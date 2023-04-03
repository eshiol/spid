<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Authentication.CiE
 *
 * @version     __DEPLOY_VERSION__
 * @since       3.10
 *
 * @author      Helios Ciancio <info (at) eshiol (dot) it>
 * @link        https://www.eshiol.it
 * @copyright   Copyright (C) 2022 - 2023 Helios Ciancio.  All rights reserved.
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU/GPL v3
 * Joomla.Plugin.Authentication.CiE  is  free  software.  This version may have
 * been modified pursuant to the GNU General Public License, and as distributed
 * it includes or is derivative of works licensed under the GNU  General Public
 * License or other free or open source software licenses.
 */

defined('_JEXEC') or die;

use eshiol\ItaliaPA\FiscalNumber\FiscalNumber;
use eshiol\SPiD\CiE;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Helper\LibraryHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Log\LogEntry;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\String\PunycodeHelper;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;

defined('JPATH_SPIDPHP')                     || define('JPATH_SPIDPHP', (new Registry(PluginHelper::getPlugin('authentication', 'cie') ? PluginHelper::getPlugin('authentication', 'cie')->params : ''))->get('spid-php_path', JPATH_LIBRARIES . '/eshiol/spid-php'));

defined('JPATH_SPIDPHP_SIMPLESAMLPHP')       || define('JPATH_SPIDPHP_SIMPLESAMLPHP', JPATH_SPIDPHP . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'simplesamlphp' . DIRECTORY_SEPARATOR . 'simplesamlphp');

file_exists(JPATH_SPIDPHP . '/spid-php.php') && require_once(JPATH_SPIDPHP . '/spid-php.php');

jimport('eshiol.SPiD.CiE');

class PlgAuthenticationCie extends CMSPlugin
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
	 * Database object
	 *
	 * @var    JDatabaseDriver
	 */
	protected $db;

	/**
	 * CIE attributes
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

		if (!$this->checkSPiD())
		{
			// Disable all CIE plugins
			$query = $this->db->getQuery(true);
			$query->update($this->db->quoteName('#__extensions'))
				->set($this->db->quoteName('enabled') . ' = 0')
				->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
				->where($this->db->quoteName('element') . ' = ' . $this->db->quote('cie'));
			Log::add(new LogEntry($query, Log::DEBUG, 'plg_authentication_cie'));
			$this->db->setQuery($query)->execute();

			return;
		}
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

		if ($this->app->isClient('administrator'))
		{
			return;
		}

		if (!$this->checkSPiD())
		{
			return;
		}

		$environment = (int) $this->params->get('environment', 1);
		$production  = ($environment == 3);
		$service     = (empty($_REQUEST['service']) ? 'cieid' : $_REQUEST['service']);
		$spidsdk     = new CiE($production, $service);
		$idp         = isset($options['idp']) ? $options['idp'] : (isset($_REQUEST['idp']) ? $_REQUEST['idp'] : '');

		Log::add(new LogEntry('isAuthenticated: ' . $spidsdk->isAuthenticated(), Log::DEBUG, 'plg_authentication_cie'));
		Log::add(new LogEntry('idp: ' . $idp, Log::DEBUG, 'plg_authentication_cie'));

		if ($spidsdk->isAuthenticated() && $idp && $spidsdk->isIdP($idp))
		{
			Log::add(new LogEntry('User is authenticated', Log::DEBUG, 'plg_authentication_cie'));
			$this->app->setUserState('cie.cie', true);

			$authDataArray = $spidsdk->getAuthDataArray();
			Log::add(new LogEntry(print_r($authDataArray, true), Log::DEBUG, 'plg_authentication_cie'));

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

			$response->type = 'CiE';

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
				// $attributes['fiscalNumber'][0] = $username;
			}
			else
			{
				$username = $attributes['fiscalNumber'][0];
			}

			$cie_response = [];
			foreach($attributes as $i => $v)
			{
				$cie_response[$i] = $v[0];
			}

			jimport('eshiol.ItaliaPA.FiscalNumber.FiscalNumber');
			$cf = $attributes['fiscalNumber'][0];
			if (($i = strpos($cf, '-')) !== false)
			{
				// remove TINPrefix
				$cf = substr($cf, $i + 1);
			}
			$fiscalNumber = new FiscalNumber($cf);

			$cie_response['gender'] = $fiscalNumber->getGender();
			$cie_response['placeOfBirth'] = $fiscalNumber->getBirthPlace();

			Log::add(new LogEntry(print_r($cie_response, true), Log::DEBUG, 'plg_authentication_cie'));

			$user = User::getInstance($username);
			if ($user->id)
			{
				unset($response->error_message);
				$cie_response['authsource'] = $authsource;
				$response->cie              = $cie_response;
				$response->status           = Authentication::STATUS_SUCCESS;
				$response->username         = $username;
				$response->email            = PunycodeHelper::emailToPunycode($user->email);
				$response->fullname         = $attributes['name'][0] . ' ' . $attributes['familyName'][0];
			}
			else
			{
				$uParams = ComponentHelper::getParams('com_users');
				Log::add(new LogEntry('allowUserRegistration: ' . $this->params->get('allowUserRegistration', $uParams->get('allowUserRegistration')), Log::DEBUG, 'plg_authentication_cns'));
				if ($this->params->get('allowUserRegistration', $uParams->get('allowUserRegistration')))
				{
					// user data
					$data = [
						'idp'      => 'CiE',
						'name'     => ucwords(strtolower($attributes['name'][0])) . ' ' . strtoupper($attributes['familyName'][0]),
						'username' => $username
					];

					$data['profile'] = $cie_response;
		
					// Save the data in the session.
					$this->app->setUserState('com_users.registration.data', $data);

					$return = $this->app->input->getInputForRequestMethod()->get('return', '', 'BASE64');

					// Redirect back to the registration screen.
					$this->app->redirect(JRoute::_('index.php?option=com_users&view=registration&idp=cie&return=' . $return, false));

					return true;
				}
				else
				{
					// Invalid user
					$response->status        = Authentication::STATUS_FAILURE;
					$response->error_message = Text::_('JGLOBAL_AUTH_NO_USER');
					Log::add(new LogEntry(Text::_('JGLOBAL_AUTH_NO_USER'), Log::DEBUG, 'plg_authentication_cie'));
					Log::add(new LogEntry(print_r($response, true), Log::DEBUG, 'plg_authentication_cie'));
					$spidsdk->logout(null, false);
					return false;
				}
			}

			Log::add(new LogEntry(print_r($response, true), Log::DEBUG, 'plg_authentication_cie'));
		}
		elseif (isset($idp))
		{
			Log::add(new LogEntry('Authenticating...', Log::DEBUG, 'plg_authentication_cie'));
			Log::add(new LogEntry(print_r($_REQUEST, true), Log::DEBUG, 'plg_authentication_cie'));
			Log::add(new LogEntry("idp " . $idp . " is available: " . $spidsdk->isIdPAvailable($idp), Log::DEBUG, 'plg_authentication_cie'));
			if(isset($idp) && $spidsdk->isIdPAvailable($idp) && $spidsdk->isCIEKey($idp))
			{
				Log::add(new LogEntry('Authenticating'.$idp.'...', Log::DEBUG, 'plg_authentication_cie'));
				$authenticationLevel = (int) $this->params->get('authentication_level', 1);
				$spidsdk->login($idp, $authenticationLevel);
			}
		}

		return true;
	}

	/**
	 * @param   integer		$userid		The user id
	 * @param	string		$key		The profile key
	 * @param	string		$default	The default value
	 *
	 * @return  string
	 *
	 * @since   3.10.2
	 */
	private function getProfile($userid, $key, $default = '')
	{
		Log::add(new LogEntry(__METHOD__, Log::DEBUG, 'plg_authentication_cie'));

		$query = $this->db->getQuery(true)
			->select($this->db->quoteName('profile_value'))
			->from($this->db->quoteName('#__user_profiles'))
			->where($this->db->quoteName('user_id') . ' = ' . (int)$userid)
			->where($this->db->quoteName('profile_key') . ' = ' . $this->db->quote('profile.' . $key));
		$this->db->setQuery($query);
		$value = json_decode($this->db->LoadResult());

		return $value ?: $default;
	}

	/**
	 * Chack SPID-PHP installation
	 *
	 * @return  boolean
	 *
	 * @since   3.10.2
	 */
	protected function checkSPiD()
	{
		Log::add(new LogEntry(__METHOD__, Log::DEBUG, 'plg_authentication_cie'));

		if (!file_exists(JPATH_SPIDPHP . '/spid-php.php'))
		{
			if ($this->params->get('debug', 0))
			{
				Log::add(new LogEntry('PLG_AUTHENTICATION_CIE_SPIDPHPNOTFOUND', Log::ERROR, 'plg_authentication_cie'));
			}
			return false;
		}
		elseif (!LibraryHelper::isEnabled('eshiol/SPiD'))
		{
			if ($this->params->get('debug', 0))
			{
				Log::add(new LogEntry('PLG_AUTHENTICATION_CIE_SPIDLIBRARYDISABLED', Log::ERROR, 'plg_authentication_cie'));
			}
			return false;
		}
		elseif (!file_exists(JPATH_SPIDPHP_SIMPLESAMLPHP . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php'))
		{
			if ($this->params->get('debug', 0))
			{
				Log::add(new LogEntry('PLG_AUTHENTICATION_CIE_CONFIGFILENOTFOUND', Log::ERROR, 'plg_authentication_cie'));
			}
			return false;
		}
		elseif (!file_exists(JPATH_SPIDPHP_SIMPLESAMLPHP . DIRECTORY_SEPARATOR . 'metadata' . DIRECTORY_SEPARATOR . 'saml20-idp-remote.php'))
		{
			if ($this->params->get('debug', 0))
			{
				Log::add(new LogEntry('PLG_AUTHENTICATION_CIE_METADATANOTFOUND', Log::ERROR, 'plg_authentication_cie'));
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
					Log::add(new LogEntry('PLG_AUTHENTICATION_CIE_CERTNOTFOUND', Log::ERROR, 'plg_authentication_cie'));
				}
				return false;
			}
		}
		return true;
	}

}
