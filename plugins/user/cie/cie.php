<?php
/**
 * @package     Joomla.Plugins
 * @subpackage  User.CiE
 *
 * @version     __DEPLOY_VERSION__
 * @since       3.10
 *
 * @author      Helios Ciancio <info (at) eshiol (dot) it>
 * @link        https://www.eshiol.it
 * @copyright   Copyright (C) 2022 Helios Ciancio. All rights reserved
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU/GPL v3
 * CiE  for  Joomla!  is  free software.  This version may have been modified
 * pursuant to the GNU General Public License, and as distributed it includes
 * or is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 */

defined('_JEXEC') or die;

defined('JPATH_SPIDPHP') or define('JPATH_SPIDPHP', JPATH_LIBRARIES . DIRECTORY_SEPARATOR . 'eshiol' . DIRECTORY_SEPARATOR . 'cie-php');
defined('JPATH_SPIDPHP_SIMPLESAMLPHP') or define('JPATH_SPIDPHP_SIMPLESAMLPHP', JPATH_SPIDPHP . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'simplesamlphp' . DIRECTORY_SEPARATOR . 'simplesamlphp');

use eshiol\SPiD\CiE;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Helper\LibraryHelper;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Log\LogEntry;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\User\User;

if (!defined('JPATH_SPIDPHP'))
{
	$plugin = PluginHelper::getPlugin('authentication', 'cie');
	$params = new Registry($plugin->params);
	define('JPATH_SPIDPHP', $params->get('spid-php_path', JPATH_LIBRARIES . '/eshiol/spid-php'));
}
require_once(JPATH_SPIDPHP . '/spid-php.php');
jimport('eshiol.SPiD.CiE');

class plgUserCie extends CMSPlugin
{
	/**
	 * Application object.
	 *
	 * @var    JApplicationCms
	 * @since  3.8.5
	 */
	protected $app;

	/**
	 * The authentication source
	 *
	 * @var string
	 */
	protected $authsource;

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
			Log::addLogger(array('text_file' => $this->params->get('log', 'eshiol.log.php'), 'extension' => 'plg_user_cie_file'), Log::ALL, array('plg_user_cie'));
		}
		Log::addLogger(array('logger' => (null !== $this->params->get('logger')) ?$this->params->get('logger') : 'messagequeue', 'extension' => 'plg_user_cie'), JLOG::ALL & ~JLOG::DEBUG, array('plg_user_cie'));

		// Load the authentication source from the session.
		$this->authsource = $this->app->getUserState('cie.authsource');
	}

	/**
	 * This is where we logout CiE
	 *
	 * @param   array  $options  Array holding options (length, timeToExpiration)
	 *
	 * @return  boolean  True on success
	 *
	 * @since   3.8.5
	 */
	public function onUserAfterLogout($options)
	{
		Log::add(new LogEntry(__METHOD__, Log::DEBUG, 'plg_user_cie'));

		if (! $this->checkSPiD())
		{
			return;
		}

		$production = false;
		$spidsdk    = new CiE($production);

		if ($spidsdk->isAuthenticated())
		{
			$spidsdk->logout();
		}
		return true;
	}

	protected function checkSPiD()
	{
		if (! LibraryHelper::isEnabled('eshiol/spid-php'))
		{
			if ($this->params->get('debug', 0))
			{
				Log::add(new LogEntry('PLG_USER_CIE_SPIDPHPLIBRARYDISABLED', Log::ERROR, 'plg_user_cie'));
			}
			return false;
		}
		elseif (! file_exists(JPATH_SPIDPHP_SIMPLESAMLPHP . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php'))
		{
			if ($this->params->get('debug', 0))
			{
				Log::add(new LogEntry('PLG_USER_CIE_CONFIGFILENOTFOUND', Log::ERROR, 'plg_user_cie'));
			}
			return false;
		}
		elseif (! file_exists(JPATH_SPIDPHP_SIMPLESAMLPHP . DIRECTORY_SEPARATOR . 'metadata' . DIRECTORY_SEPARATOR . 'saml20-idp-remote.php'))
		{
			if ($this->params->get('debug', 0))
			{
				Log::add(new LogEntry('PLG_USER_CIE_METADATANOTFOUND', Log::ERROR, 'plg_user_cie'));
			}
			return false;
		}
		else
		{
			include JPATH_SPIDPHP_SIMPLESAMLPHP . '/config/authsources.php';

			if (! file_exists(JPATH_SPIDPHP_SIMPLESAMLPHP . DIRECTORY_SEPARATOR . 'cert' . DIRECTORY_SEPARATOR . $config['service']['privatekey']))
			{
				if ($this->params->get('debug', 0))
				{
					Log::add(new LogEntry('plg_user_cie_CERTNOTFOUND', Log::ERROR, 'plg_user_cie'));
				}
				return false;
			}
		}
		return true;
	}
}
