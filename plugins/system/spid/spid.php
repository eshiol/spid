<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  System.SPiD
 *
 * @version     __DEPLOY_VERSION__
 * @since       3.7
 *
 * @author      Helios Ciancio <info (at) eshiol (dot) it>
 * @link        https://www.eshiol.it
 * @copyright   Copyright (C) 2017 - 2023 Helios Ciancio. All rights reserved.
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU/GPL v3
 * Joomla.Plugin.System.SPiD  is  free  software.  This  version may have been
 * modified pursuant to the GNU General Public License,  and as distributed it
 * includes  or  is  derivative of works licensed under the GNU General Public 
 * License or other free or open source software licenses.
 */

defined('_JEXEC') or die();

use Joomla\CMS\Factory;
use Joomla\CMS\Helper\LibraryHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Log\LogEntry;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Registry\Registry;

if (!defined('JPATH_SPIDPHP'))
{
	$plugin = PluginHelper::getPlugin('authentication', 'spid');
	$params = new Registry($plugin->params);
	define('JPATH_SPIDPHP', $params->get('spid-php_path', JPATH_LIBRARIES . '/eshiol/spid-php'));
}
defined('JPATH_SPIDPHP_SIMPLESAMLPHP') or define('JPATH_SPIDPHP_SIMPLESAMLPHP', JPATH_SPIDPHP . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'simplesamlphp' . DIRECTORY_SEPARATOR . 'simplesamlphp');
if (file_exists(JPATH_SPIDPHP . '/spid-php.php'))
{
	require_once(JPATH_SPIDPHP . '/spid-php.php');
}

/*if (LibraryHelper::isEnabled('eshiol/SPiD'))
{
	require_once(JPATH_LIBRARIES . '/eshiol/SPiD/SPiD.php');
}*/
jimport('eshiol.SPiD.SPiD');

/**
 * System SPiD Plugin.
 */
class PlgSystemSpid extends CMSPlugin
{

	/**
	 * Application object.
	 *
	 * @var JApplicationCms
	 * @since 3.8.6
	 */
	protected $app;

	/**
	 * The base path of the library
	 *
	 * @var string
	 * @since 3.8.6
	 */
	protected $basePath;

	/**
	 * Load the language file on instantiation.
	 *
	 * @var boolean
	 */
	protected $autoloadLanguage = true;

	/**
	 * Database object
	 *
	 * @var    JDatabaseDriver
	 */
	protected $db;

	/**
	 * Constructor
	 *
	 * @param  object  $subject  The object to observe
	 * @param  array   $config   An array that holds the plugin configuration
	 */
	function __construct(&$subject, $config)
	{
		parent::__construct($subject, $config);
		
		if ($this->params->get('debug') || defined('JDEBUG') && JDEBUG)
		{
			Log::addLogger(array('text_file' => $this->params->get('log', 'eshiol.log.php'), 'extension' => 'plg_system_spid_file'), Log::ALL, array('plg_system_spid'));
		}
		Log::addLogger(array('logger' => (null !== $this->params->get('logger')) ?$this->params->get('logger') : 'messagequeue', 'extension' => 'plg_system_spid'), LOG::ALL & ~LOG::DEBUG, array('plg_system_spid'));
		
		Log::add(new LogEntry(__METHOD__, Log::DEBUG, 'plg_system_spid'));

		if (!$this->checkSPiD())
		{
			// Disable all SPiD plugins
			$query = $this->db->getQuery(true);
			$query->update($this->db->quoteName('#__extensions'))
				->set($this->db->quoteName('enabled') . ' = 0')
				->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
				->where($this->db->quoteName('element') . ' = ' . $this->db->quote('spid'));
			Log::add(new LogEntry($query, Log::DEBUG, 'plg_authentication_spid'));
			$this->db->setQuery($query)->execute();

			// Disable all SPiD Login modules
			$query->clear()
				->update($this->db->quoteName('#__extensions'))
				->set($this->db->quoteName('enabled') . ' = 0')
				->where($this->db->quoteName('type') . ' = ' . $this->db->quote('module'))
				->where($this->db->quoteName('element') . ' = ' . $this->db->quote('mod_login_spid'));
			Log::add(new LogEntry($query, Log::DEBUG, 'plg_authentication_spid'));
			$this->db->setQuery($query)->execute();

			return;
		}
	}

	/**
	 *
	 * @return void
	 *
	 * @since 3.8.5
	 */
	public function onAfterInitialise ()
	{
		Log::add(new LogEntry(__METHOD__, Log::DEBUG, 'plg_system_spid'));
		Log::add(new LogEntry(print_r($_REQUEST, true), Log::DEBUG, 'plg_system_spid'));

		if (!$this->checkSPiD())
		{
			return;
		}

		if (array_key_exists('statusCode', $_REQUEST))
		{
			$lang = Factory::getLanguage();
			$statusCode = $_REQUEST['statusCode'];
			$esfx = 'ERRORCODE_NR00';
			
			if ($statusCode == 'urn:oasis:names:tc:SAML:2.0:status:Responder')
			{
				$m = str_replace(' ', '_', strtoupper($_REQUEST['statusMessage']));
				$esfx = (strpos($m, '_ERRORCODE_NR') !== false) ? substr($m, (strpos($m, '_ERRORCODE_NR') + 1)) : $m;
			}
			elseif ($statusCode == 'urn:oasis:names:tc:SAML:2.0:status:VersionMismatch ErrorCode nr09')
			{
				$esfx = 'ERRORCODE_NR09';
			}
			elseif ($statusCode == 'urn:oasis:names:tc:SAML:2.0:status:Requester ErrorCode nr11')
			{
				$esfx = 'ERRORCODE_NR11';
			}

			$this->app->setHeader('status', 403, true);
			$key = 'PLG_SYSTEM_SPID_' . $esfx;
			Log::add(new LogEntry($lang->hasKey($key)
					? Text::_($key)
					: Text::sprintf('PLG_SYSTEM_SPID_ERRORCODE_UNKNOWN', $_REQUEST['errorMessage']), LOG::ERROR, 'plg_system_spid'));
			if ($this->params->get('debug', 0))
			{
				Log::add(new LogEntry(str_replace([" ", "\t", "\n"], ['&nbsp;', '&nbsp;&nbsp;&nbsp;&nbsp;', '<br />'],  htmlspecialchars(print_r($_REQUEST, true))), LOG::ERROR, 'plg_system_spid'));
			}

			Log::add(new LogEntry($lang->hasKey($key)
					? Text::_($key)
					: Text::sprintf('PLG_SYSTEM_SPID_ERRORCODE_UNKNOWN', $_REQUEST['errorMessage']), LOG::DEBUG, 'plg_system_spid'));
			Log::add(new LogEntry(print_r($_REQUEST, true), LOG::DEBUG, 'plg_system_spid'));
		}
	}

	protected function checkSPiD()
	{
		Log::add(new LogEntry(__METHOD__, Log::DEBUG, 'plg_system_spid'));

		if (!file_exists(JPATH_SPIDPHP . '/spid-php.php'))
		{
			if ($this->params->get('debug', 0))
			{
				Log::add(new LogEntry('PLG_SYSTEM_SPID_SPIDPHPNOTFOUND', Log::ERROR, 'plg_system_spid'));
			}
			return false;
		}
		elseif (!LibraryHelper::isEnabled('eshiol/SPiD'))
		{
			if ($this->params->get('debug', 0))
			{
				Log::add(new LogEntry('PLG_SYSTEM_SPID_SPIDLIBRARYDISABLED', Log::ERROR, 'plg_system_spid'));
			}
			return false;
		}
		elseif (!file_exists(JPATH_SPIDPHP_SIMPLESAMLPHP . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php'))
		{
			if ($this->params->get('debug', 0))
			{
				Log::add(new LogEntry('PLG_SYSTEM_SPID_CONFIGFILENOTFOUND', Log::ERROR, 'plg_system_spid'));
			}
			return false;
		}
		elseif (!file_exists(JPATH_SPIDPHP_SIMPLESAMLPHP . DIRECTORY_SEPARATOR . 'metadata' . DIRECTORY_SEPARATOR . 'saml20-idp-remote.php'))
		{
			if ($this->params->get('debug', 0))
			{
				Log::add(new LogEntry('PLG_SYSTEM_SPID_METADATANOTFOUND', Log::ERROR, 'plg_system_spid'));
			}
			return false;
		}
		else
		{
			include JPATH_SPIDPHP_SIMPLESAMLPHP . '/config/authsources.php';

			if (!file_exists(JPATH_SPIDPHP_SIMPLESAMLPHP . DIRECTORY_SEPARATOR . 'cert' . DIRECTORY_SEPARATOR . $config['service']['privatekey']))
			{
				if ($this->params->get('debug', 0))
				{
					Log::add(new LogEntry('plg_system_spid_CERTNOTFOUND', Log::ERROR, 'plg_system_spid'));
				}
				return false;
			}
		}
		return true;
	}
}
