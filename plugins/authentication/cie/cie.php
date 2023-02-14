<?php
/**
 * @package     Joomla.Plugins
 * @subpackage  Authentication.CIE
 *
 * @version     __DEPLOY_VERSION__
 * @since       3.10
 *
 * @author      Helios Ciancio <info (at) eshiol (dot) it>
 * @link        https://www.eshiol.it
 * @copyright   Copyright (C) 2022 - 2023 Helios Ciancio. All rights reserved
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU/GPL v3
 * SPiD  for  Joomla!  is  free software. This version may have been modified
 * pursuant to the GNU General Public License, and as distributed it includes
 * or is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 */

defined('_JEXEC') or die;

use eshiol\SPiD\CiE;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Log\LogEntry;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\String\PunycodeHelper;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;

if (!defined('JPATH_SPIDPHP'))
{
	$plugin = PluginHelper::getPlugin('authentication', 'cie');
	$params = new Registry($plugin->params);
	define('JPATH_SPIDPHP', $params->get('spid-php_path', JPATH_LIBRARIES . '/eshiol/spid-php'));
}
require_once(JPATH_SPIDPHP . '/spid-php.php');
jimport('eshiol.SPiD.CiE');

class plgAuthenticationCie extends CMSPlugin
{
	/**
	 * Application object.
	 *
	 * @var    Joomla\CMS\Application\CMSApplication
	 * @since  3.8.5
	 */
	protected $app;

	/**
	 * Load the language file on instantiation.
	 *
	 * @var	boolean
	 */
	protected $autoloadLanguage = true;

	/**
	 * SPiD attributes
	 *
	 * @var array
	 */
	static protected $fields = array('name', 'familyName', 'dateOfBirth', 'fiscalNumber');

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
			Log::addLogger(array('text_file' => $this->params->get('log', 'eshiol.log.php'), 'extension' => 'plg_authentication_cie_file'), Log::ALL, array('plg_authentication_cie'));
		}
		Log::addLogger(array('logger' => (null !== $this->params->get('logger')) ?$this->params->get('logger') : 'messagequeue', 'extension' => 'plg_authentication_cie'), LOG::ALL & ~LOG::DEBUG, array('plg_authentication_cie'));

		Log::add(new LogEntry(__METHOD__, Log::DEBUG, 'plg_authentication_cie'));
	}

	/**
	 * This method should handle any authentication and report back to the subject
	 *
	 * @param   array   $credentials  Array holding the user credentials
	 * @param   array   $options	  Array of extra options
	 * @param   object  &$response	  Authentication response object
	 *
	 * @return  boolean
	 */
	public function onUserAuthenticate($credentials, $options, &$response)
	{
		Log::add(new LogEntry(__METHOD__, Log::DEBUG, 'plg_authentication_cie'));

		$environment = (int) $this->params->get('environment', 1);
		$production  = ($environment == 3);
		$service     = (empty($_REQUEST['service']) ? 'cieid' : $_REQUEST['service']);
		$spidsdk     = new CiE($production, $service);

		if ($spidsdk->isAuthenticated() && isset($_REQUEST['idp']) && $spidsdk->isIdP($_REQUEST['idp']))
		{
			Log::add(new LogEntry('User is authenticated', Log::DEBUG, 'plg_authentication_cie'));

		    $authDataArray = $spidsdk->getAuthDataArray();
		    Log::add(new LogEntry(print_r($authDataArray, true), Log::DEBUG, 'plg_authentication_cie'));

		    if (isset($authDataArray['saml:sp:AuthnContext']))
		    {
		        $loa = (int)substr($authDataArray['saml:sp:AuthnContext'], -1);
		    }

		    foreach($_REQUEST as $attribute=>$value)
		    {
		        Log::add(new LogEntry($attribute . ": " . $value, Log::DEBUG, 'plg_authentication_cie'));
		    }

		    Log::add(new LogEntry($spidsdk->getIdP(), Log::DEBUG, 'plg_authentication_cie'));
		    Log::add(new LogEntry("Response ID: " . $spidsdk->getResponseID(), Log::DEBUG, 'plg_authentication_cie'));

		    $attributes = $spidsdk->getAttributes();
		    foreach($attributes as $attribute=>$value)
		    {
		    	Log::add(new LogEntry($attribute . ": " . $value[0], Log::DEBUG, 'plg_authentication_cie'));
		    }

		    $response->type = 'CIE';

			if (!isset($attributes['fiscalNumber']))
			{
				$response->status        = Authentication::STATUS_FAILURE;
				$response->error_message = Text::sprintf('PLG_AUTHENTICATION_CIE_ATTRIBUTE_ERROR', 'fiscalNumber');

				return;
			}

			if (!isset($attributes['name']))
			{
				$response->status        = Authentication::STATUS_FAILURE;
				$response->error_message = Text::sprintf('PLG_AUTHENTICATION_CIE_ATTRIBUTE_ERROR', 'name');

				return;
			}

			if (!isset($attributes['familyName']))
			{
				$response->status        = Authentication::STATUS_FAILURE;
				$response->error_message = Text::sprintf('PLG_AUTHENTICATION_CIE_ATTRIBUTE_ERROR', 'familyName');

				return;
			}

			if ($this->params->get('removeTINPrefix', true) && (($i = strpos($attributes['fiscalNumber'][0], '-')) !== false))
			{
				$username = substr($attributes['fiscalNumber'][0], $i + 1);
			}
			else
			{
				$username = $attributes['fiscalNumber'][0];
			}

			$cie_response = [];
			$cie_response['authsource'] = $authsource;
			foreach($attributes as $i => $v)
			{
				$cie_response[$i] = $v[0];
			}
			Log::add(new LogEntry(print_r($cie_response, true), Log::DEBUG, 'plg_authentication_cie'));

			$user = User::getInstance($username);
			if ($user->id)
			{
				unset($response->error_message);
				$response->cie      = $cie_response;
				$response->status   = Authentication::STATUS_SUCCESS;
				$response->username = $username;
				$response->email    = PunycodeHelper::emailToPunycode($user->email);
				$response->fullname = $attributes['name'][0] . ' ' . $attributes['familyName'][0];
			}
			else
			{
				// TODO: allowUserRegistration

				// Invalid user
				$response->status        = Authentication::STATUS_FAILURE;
				$response->error_message = Text::_('JGLOBAL_AUTH_NO_USER');
				Log::add(new LogEntry(Text::_('JGLOBAL_AUTH_NO_USER'), Log::DEBUG, 'plg_authentication_cie'));
				Log::add(new LogEntry(print_r($response, true), Log::DEBUG, 'plg_authentication_cie'));
				$spidsdk->logout(null, false);
				return false;
			}

			Log::add(new LogEntry(print_r($response, true), Log::DEBUG, 'plg_authentication_cie'));
		}
		else
		{
			Log::add(new LogEntry('Authenticating...', Log::DEBUG, 'plg_authentication_cie'));
			Log::add(new LogEntry(print_r($_REQUEST, true), Log::DEBUG, 'plg_authentication_cie'));
			Log::add(new LogEntry("idp " . $_REQUEST['idp'] . " is available: " . $spidsdk->isIdPAvailable($_REQUEST['idp']), Log::DEBUG, 'plg_authentication_cie'));
			if(isset($_REQUEST['idp']) && $spidsdk->isIdPAvailable($_REQUEST['idp']) && $spidsdk->isCIEKey($_REQUEST['idp']))
			{
				Log::add(new LogEntry('Authenticating'.$_REQUEST['idp'].'...', Log::DEBUG, 'plg_authentication_cie'));
				$spidsdk->login($_REQUEST['idp']);
			}
		}

		return true;
	}

}