<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Authentication.SPiD
 *
 * @version     __DEPLOY_VERSION__
 * @since       3.7
 *
 * @author      Helios Ciancio <info (at) eshiol (dot) it>
 * @link        https://www.eshiol.it
 * @copyright   Copyright (C) 2017 - 2023 Helios Ciancio.  All rights reserved.
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU/GPL v3
 * Joomla.Plugin.Authentication.SPiD  is  free software.  This version may have
 * been modified pursuant to the GNU General Public License, and as distributed
 * it  includes or is derivative of works licensed under the GNU General Public 
 * License or other free or open source software licenses.
 */

defined('_JEXEC') or die;

use eshiol\SPiD\SPiD;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\LibraryHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Log\LogEntry;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\String\PunycodeHelper;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserHelper;
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

class PlgAuthenticationSpid extends CMSPlugin
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
	static protected $fields = array('spidCode',
		'name',
		'familyName',
		'placeOfBirth',
		'countyOfBirth',
		'dateOfBirth',
		'gender',
		'companyName',
		'registeredOffice',
		'fiscalNumber',
		'companyFiscalNumber',
		'ivaCode',
		'idCard',
		'mobilePhone',
		'email',
		'expirationDate',
		'digitalAddress',
		'domicileStreetAddress',
		'domicilePostalCode',
		'domicileMunicipality',
		'domicileProvince',
		'domicileNation');

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
			Log::addLogger(array('text_file' => $this->params->get('log', 'eshiol.log.php'), 'extension' => 'plg_authentication_spid_file'), Log::ALL, array('plg_authentication_spid'));
		}
		Log::addLogger(array('logger' => (null !== $this->params->get('logger')) ?$this->params->get('logger') : 'messagequeue', 'extension' => 'plg_authentication_spid'), LOG::ALL & ~LOG::DEBUG, array('plg_authentication_spid'));

		Log::add(new LogEntry(__METHOD__, Log::DEBUG, 'plg_authentication_spid'));

		if (!$this->checkSPiD())
		{
			// Disable all SPiD plugins
			$db = Factory::getDbo();
			$query = $db->getQuery(true);
			$query->update($db->quoteName('#__extensions'))
				->set($db->quoteName('enabled') . ' = 0')
				->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
				->where($db->quoteName('element') . ' = ' . $db->quote('spid'));
			JLog::add(new JLogEntry($query, JLog::DEBUG, 'plg_authentication_spid'));
			$db->setQuery($query)->execute();

			// Disable all SPiD Login modules
			$query->clear()
				->update($db->quoteName('#__extensions'))
				->set($db->quoteName('enabled') . ' = 0')
				->where($db->quoteName('type') . ' = ' . $db->quote('module'))
				->where($db->quoteName('element') . ' = ' . $db->quote('mod_login_spid'));
			JLog::add(new JLogEntry($query, JLog::DEBUG, 'plg_authentication_spid'));
			$db->setQuery($query)->execute();

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
		Log::add(new LogEntry(__METHOD__, Log::DEBUG, 'plg_authentication_spid'));

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
		$service     = (empty($_REQUEST['service']) ? 'service' : $_REQUEST['service']);
		$spidsdk     = new SPiD($production, $service);
		$loa         = (int)((isset($_REQUEST['spidlevel'])) ? $_REQUEST['spidlevel'] : 1);

		if ($spidsdk->isAuthenticated() && isset($_REQUEST['idp']) && $spidsdk->isIdP($_REQUEST['idp']))
		{
			Log::add(new LogEntry('User is authenticated', Log::DEBUG, 'plg_authentication_spid'));

		    $authDataArray = $spidsdk->getAuthDataArray();
		    Log::add(new LogEntry(print_r($authDataArray, true), Log::DEBUG, 'plg_authentication_spid'));

		    if (isset($authDataArray['saml:sp:AuthnContext']))
		    {
		        $loa = (int)substr($authDataArray['saml:sp:AuthnContext'], -1);
		    }

		    foreach($_REQUEST as $attribute=>$value)
		    {
		        Log::add(new LogEntry($attribute . ": " . $value, Log::DEBUG, 'plg_authentication_spid'));
		    }

		    Log::add(new LogEntry($spidsdk->getIdP(), Log::DEBUG, 'plg_authentication_spid'));
		    Log::add(new LogEntry("Response ID: " . $spidsdk->getResponseID(), Log::DEBUG, 'plg_authentication_spid'));

		    $attributes = $spidsdk->getAttributes();
		    foreach($attributes as $attribute=>$value)
		    {
		    	Log::add(new LogEntry($attribute . ": " . $value[0], Log::DEBUG, 'plg_authentication_spid'));
		    }

		    $response->type = 'SPiD';

			if (!isset($attributes['fiscalNumber']))
			{
				$response->status        = Authentication::STATUS_FAILURE;
				$response->error_message = Text::sprintf('PLG_AUTHENTICATION_SPID_ATTRIBUTE_ERROR', 'fiscalNumber');

				return;
			}

			if (!isset($attributes['name']))
			{
				$response->status        = Authentication::STATUS_FAILURE;
				$response->error_message = Text::sprintf('PLG_AUTHENTICATION_SPID_ATTRIBUTE_ERROR', 'name');

				return;
			}

			if (!isset($attributes['familyName']))
			{
				$response->status        = Authentication::STATUS_FAILURE;
				$response->error_message = Text::sprintf('PLG_AUTHENTICATION_SPID_ATTRIBUTE_ERROR', 'familyName');

				return;
			}

			if ($this->params->get('removeTINPrefix', true) && (($i = strpos($attributes['fiscalNumber'][0], '-')) !== false))
			{
				$username = substr($attributes['fiscalNumber'][0], $i + 1);
				$attributes['fiscalNumber'][0] = $username;
			}
			else
			{
				$username = $attributes['fiscalNumber'][0];
			}

			$spid_response = [];
			$spid_response['loa'] = $loa;
			$spid_response['authsource'] = $authsource;
			foreach($attributes as $i => $v)
			{
				$spid_response[$i] = $v[0];
			}
			Log::add(new LogEntry(print_r($spid_response, true), Log::DEBUG, 'plg_authentication_spid'));

			if (User::getInstance($username)->id)
			{
				unset($response->error_message);
				$response->spid = $spid_response;

				if ($this->params->get('spidlevel1', '1'))
				{
					$defaultLoA = 1;
				}
				elseif ($this->params->get('spidlevel2', '1'))
				{
					$defaultLoA = 2;
				}
				elseif ($this->params->get('spidlevel3', '1'))
				{
					$defaultLoA = 3;
				}
				else
				{
					$defaultLoA = 1;
				}
				$minimumLoA = $this->getProfile(User::getInstance($username)->id, 'loa', $defaultLoA);

				if ($loa < $minimumLoA)
				{
					$spidsdk->logout(null, false);
					$response->status        = Authentication::STATUS_FAILURE;
					$response->error_message = Text::sprintf('PLG_AUTHENTICATION_SPID_LOA_ERROR', $loa, $minimumLoA);
					Log::add(new LogEntry(print_r($response, true), Log::DEBUG, 'plg_authentication_spid'));

					Log::add(new LogEntry('Authenticating...', Log::DEBUG, 'plg_authentication_spid'));
					$spidsdk->login($_REQUEST['idp'], $minimumLoA);
					return false;
				}

				$response->status        = Authentication::STATUS_SUCCESS;
				$response->username      = $username;
				$response->email         = PunycodeHelper::emailToPunycode($attributes['email'][0]);
				$response->fullname      = $attributes['name'][0] . ' ' . $attributes['familyName'][0];
			}
			else
			{
				if ($allowEmailAuthentication = $this->params->get('allowEmailAuthentication', false))
				{
					// Get the database object and a new query object.
					$db = Factory::getDbo();
					$query = $db->getQuery(true);

					// Build the query.
					$query->select('username')
						->from('#__users')
						->where('email = ' . $db->quote(PunycodeHelper::emailToPunycode($spid_response['email'])));

					// Set and query the database.
					$db->setQuery($query);
					$usernameByEmail = $db->loadResult();

					if ($usernameByEmail)
					{
						$response->spid = $spid_response;

						if ($allowEmailAuthentication == 2)
						{
							$query = $db->getQuery(true);

							// Build the query.
							$query->update('#__users')
								->set('username = ' . $db->quote($username))
								->where('email = ' . $db->quote(PunycodeHelper::emailToPunycode($spid_response['email'])));

							// Set and query the database.
							$db->setQuery($query);

							try
							{
								$db->execute();
								$usernameByEmail = $username;
								$this->app->enqueueMessage(Text::sprintf('PLG_AUTHENTICATION_SPID_PROFILE_UPDATE_SUCCESS', Text::_('JGLOBAL_USERNAME'),	$username), 'notice');
							}
							catch (Exception $e)
							{
							}
						}

						unset($response->error_message);
						$response->spid = $spid_response;

						if ($this->params->get('spidlevel1', '1'))
						{
							$defaultLoA = 1;
						}
						elseif ($this->params->get('spidlevel2', '1'))
						{
							$defaultLoA = 2;
						}
						elseif ($this->params->get('spidlevel3', '1'))
						{
							$defaultLoA = 3;
						}
						else
						{
							$defaultLoA = 1;
						}
						$minimumLoA = $this->getProfile(User::getInstance($usernameByEmail)->id, 'loa', $defaultLoA);

						if ($loa < $minimumLoA)
						{
							$spidsdk->logout(null, false);
							$response->status        = Authentication::STATUS_FAILURE;
							$response->error_message = Text::sprintf('PLG_AUTHENTICATION_SPID_LOA_ERROR', $loa, $minimumLoA);
							Log::add(new LogEntry(print_r($response, true), Log::DEBUG, 'plg_authentication_spid'));
														Log::add(new LogEntry('Authenticating...', Log::DEBUG, 'plg_authentication_spid'));
							$spidsdk->login($_REQUEST['idp'], $minimumLoA);
							return false;
						}

						$response->status   = Authentication::STATUS_SUCCESS;
						$response->username = $usernameByEmail;
						$response->email    = PunycodeHelper::emailToPunycode($attributes['email'][0]);
						$response->fullname = $attributes['name'][0] . ' ' . $attributes['familyName'][0];

						return true;
					}
				}

				$uParams = ComponentHelper::getParams('com_users');
				if ($this->params->get('allowUserRegistration', $uParams->get('allowUserRegistration')))
				{
					// user data
					$data['name']     = $spid_response['name'] . ' ' . $spid_response['familyName'];
					$data['username'] = $username;
					$data['email']    = $data['email1']    = $data['email2']    = PunycodeHelper::emailToPunycode($spid_response['email']);
					$data['password'] = $data['password1'] = $data['password2'] = UserHelper::genRandomPassword();

					$data['profile']['spid'] = true;
					include JPATH_SPIDPHP_SIMPLESAMLPHP . '/config/authsources.php';
					foreach (self::$fields as $field)
					{
						$data['profile'][$field] = $spid_response[$field];
					}

					// Get the model and validate the data.
					jimport('joomla.application.component.model');
					require_once JPATH_BASE . '/components/com_users/models/registration.php';
					BaseDatabaseModel::addIncludePath(__DIR__);
					$model = BaseDatabaseModel::getInstance('Registration', 'SpidModel');

					$return = $model->register($data);

					if ($return === false)
					{
						$errors = $model->getErrors();
						$spidsdk->logout(null, false);
						$response->status = Authentication::STATUS_FAILURE;

						// Get the database object and a new query object.
						$db = Factory::getDbo();
						$query = $db->getQuery(true);

						// Build the query.
						$query->select('COUNT(*)')
							->from('#__users')
							->where('email = ' . $db->quote($data['email']));

						// Set and query the database.
						$db->setQuery($query);
						$duplicate = (bool) $db->loadResult();

						$response->message = ($duplicate ? Text::_('PLG_AUTHENTICATION_SPID_REGISTER_EMAIL1_MESSAGE') : 'USER NOT EXISTS AND FAILED THE CREATION PROCESS');

						$login_url = JUri::getInstance();
						$this->app->redirect($login_url, $response->message, 'error');
					}

					$user = User::getInstance($username);
					if (($user->block == 0) && (!$user->activation))
					{
						$response->spid = $spid_response;

						if ($this->params->get('spidlevel1', '1'))
						{
							$defaultLoA = 1;
						}
						elseif ($this->params->get('spidlevel2', '1'))
						{
							$defaultLoA = 2;
						}
						elseif ($this->params->get('spidlevel3', '1'))
						{
							$defaultLoA = 3;
						}
						else
						{
							$defaultLoA = 1;
						}
						$minimumLoA = $this->getProfile(User::getInstance($usernameByEmail)->id, 'loa', $defaultLoA);

						if ($loa < $minimumLoA)
						{
							$spidsdk->logout(null, false);
							$response->status        = Authentication::STATUS_FAILURE;
							$response->error_message = Text::sprintf('PLG_AUTHENTICATION_SPID_LOA_ERROR', $loa, $minimumLoA);
							Log::add(new LogEntry(print_r($response, true), Log::DEBUG, 'plg_authentication_spid'));
							Log::add(new LogEntry('Authenticating...', Log::DEBUG, 'plg_authentication_spid'));
							$spidsdk->login($_REQUEST['idp'], $minimumLoA);
							return false;
						}

						$session = Factory::getSession();
						$session->set('user', $user);

						$response->status   = Authentication::STATUS_SUCCESS;
						$response->username = $usernameByEmail;
						$response->email    = PunycodeHelper::emailToPunycode($attributes['email'][0]);
						$response->fullname = $attributes['name'][0] . ' ' . $attributes['familyName'][0];
					}

					// Flush the data from the session.
					$this->app->setUserState('com_users.registration.data', null);

					// Redirect to the profile screen.
					Factory::getLanguage()->load('com_users', JPATH_SITE);
					if ($return === 'adminactivate')
					{
						$this->app->enqueueMessage(Text::_('PLG_AUTHENTICATION_SPID_REGISTRATION_COMPLETE_VERIFY'));
						$this->app->redirect(JRoute::_('index.php?option=com_users&view=registration&layout=complete', false));
					}
					elseif ($return === 'useractivate')
					{
						$this->app->enqueueMessage(Text::_('COM_USERS_REGISTRATION_COMPLETE_ACTIVATE'));
						$this->app->redirect(JRoute::_('index.php?option=com_users&view=registration&layout=complete', false));
					}
					else
					{
						if ($this->params->get('thankYouMessage', 1))
						{
							$this->app->enqueueMessage(Text::_('PLG_AUTHENTICATION_SPID_REGISTRATION_SAVE_SUCCESS'));
						}
						$redirect_url = base64_decode($this->app->input->get('return', null, 'BASE64'));
						$this->app->redirect(JRoute::_($redirect_url ? 'index.php?Itemid=' . $redirect_url : 'index.php?option=com_users&view=login', false));
					}
				}

				// Invalid user
				$response->status        = Authentication::STATUS_FAILURE;
				$response->error_message = Text::_('JGLOBAL_AUTH_NO_USER');
				Log::add(new LogEntry(Text::_('JGLOBAL_AUTH_NO_USER'), Log::DEBUG, 'plg_authentication_spid'));
				Log::add(new LogEntry(print_r($response, true), Log::DEBUG, 'plg_authentication_spid'));
				$spidsdk->logout(null, false);
				return false;
			}

			Log::add(new LogEntry(print_r($response, true), Log::DEBUG, 'plg_authentication_spid'));
		}
		elseif (isset($_REQUEST['idp']))
		{
			Log::add(new LogEntry('Authenticating...', Log::DEBUG, 'plg_authentication_spid'));
			Log::add(new LogEntry(print_r($_REQUEST, true), Log::DEBUG, 'plg_authentication_spid'));
			Log::add(new LogEntry("idp " . $_REQUEST['idp'] . " is available: " . $spidsdk->isIdPAvailable($_REQUEST['idp']), Log::DEBUG, 'plg_authentication_spid'));
			if(isset($_REQUEST['idp']) && $spidsdk->isIdPAvailable($_REQUEST['idp']) && !$spidsdk->isCIEKey($_REQUEST['idp']))
			{
				Log::add(new LogEntry('Authenticating '.$_REQUEST['idp'].'...', Log::DEBUG, 'plg_authentication_spid'));
				$spidsdk->login($_REQUEST['idp'], $loa);
				/*$return = base64_decode(Factory::getApplication()->input->get('return', null, 'base64'));
				Log::add(new LogEntry("return " . $return, Log::DEBUG, 'plg_authentication_spid'));
				$spidsdk->login($_REQUEST['idp'], $loa, $return);*/
			}
		}

		return true;
	}

	/**
	 * Textfield or Form of the Plugin.
	 *
	 * @return  array  Returns an array with the tab information
	 *
	 * @since   3.10.0
	 */
	public function onAuthenticationAddLoginTab()
	{
		Log::add(new LogEntry(__METHOD__, Log::DEBUG, 'plg_authentication_spid'));

		if (!$this->checkSPiD())
		{
			return;
		}

		$tab            = array();
		$tab['name']    = 'spid';
		$tab['label']   = Text::_('PLG_AUTHENTICATION_SPID_LOGIN');

		// Render the input
		ob_start();
		include PluginHelper::getLayoutPath('authentication', 'spid');
		$tab['content'] = ob_get_clean();

		return $tab;
	}

	/**
	 * @param   integer		$userid		The user id
	 * @param	string		$key		The profile key
	 * @param	string		$default	The default value
	 *
	 * @return  string
	 *
	 * @since   3.8.5
	 */
	private function getProfile($userid, $key, $default = '')
	{
		Log::add(new LogEntry(__METHOD__, Log::DEBUG, 'plg_authentication_spid'));

		$db = JFactory::getDbo();
		$query = $db->getQuery(true)
			->select($db->quoteName('profile_value'))
			->from($db->quoteName('#__user_profiles'))
			->where($db->quoteName('user_id') . ' = ' . (int)$userid)
			->where($db->quoteName('profile_key') . ' = ' . $db->quote('profile.' . $key));
		$db->setQuery($query);
		$value = json_decode($db->LoadResult());

		return $value ?: $default;
	}

	protected function checkSPiD()
	{
		Log::add(new LogEntry(__METHOD__, Log::DEBUG, 'plg_authentication_spid'));

		if (!file_exists(JPATH_SPIDPHP . '/spid-php.php'))
		{
			if ($this->params->get('debug', 0))
			{
				Log::add(new LogEntry('PLG_AUTHENTICATION_SPID_SPIDPHPNOTFOUND', Log::ERROR, 'PLG_AUTHENTICATION_SPID'));
			}
			return false;
		}
		elseif (!LibraryHelper::isEnabled('eshiol/SPiD'))
		{
			if ($this->params->get('debug', 0))
			{
				Log::add(new LogEntry('PLG_AUTHENTICATION_SPID_SPIDLIBRARYDISABLED', Log::ERROR, 'PLG_AUTHENTICATION_SPID'));
			}
			return false;
		}
		elseif (!file_exists(JPATH_SPIDPHP_SIMPLESAMLPHP . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php'))
		{
			if ($this->params->get('debug', 0))
			{
				Log::add(new LogEntry('PLG_AUTHENTICATION_SPID_CONFIGFILENOTFOUND', Log::ERROR, 'PLG_AUTHENTICATION_SPID'));
			}
			return false;
		}
		elseif (!file_exists(JPATH_SPIDPHP_SIMPLESAMLPHP . DIRECTORY_SEPARATOR . 'metadata' . DIRECTORY_SEPARATOR . 'saml20-idp-remote.php'))
		{
			if ($this->params->get('debug', 0))
			{
				Log::add(new LogEntry('PLG_AUTHENTICATION_SPID_METADATANOTFOUND', Log::ERROR, 'PLG_AUTHENTICATION_SPID'));
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
					Log::add(new LogEntry('PLG_AUTHENTICATION_SPID_CERTNOTFOUND', Log::ERROR, 'PLG_AUTHENTICATION_SPID'));
				}
				return false;
			}
		}
		return true;
	}

}