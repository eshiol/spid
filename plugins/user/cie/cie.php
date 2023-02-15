<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  User.CiE
 *
 * @version     __DEPLOY_VERSION__
 * @since       3.10
 *
 * @author      Helios Ciancio <info (at) eshiol (dot) it>
 * @link        https://www.eshiol.it
 * @copyright   Copyright (C) 2022 - 2023 Helios Ciancio. All rights reserved
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU/GPL v3
 * Joomla.Plugin.User.CiE  is  free  software.  This  version  may  have been
 * modified pursuant to the GNU General Public License, and as distributed it
 * includes or is derivative of works licensed under the GNU  General  Public 
 * License or other free or open source software licenses.
 */

defined('_JEXEC') or die;

use eshiol\SPiD\CiE;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Filesystem\Path;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Helper\LibraryHelper;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Log\LogEntry;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;

if (!defined('JPATH_SPIDPHP'))
{
	$plugin = PluginHelper::getPlugin('authentication', 'cie');
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
jimport('eshiol.SPiD.CiE');

class PlgUserCie extends CMSPlugin
{
	/**
	 * Application object.
	 *
	 * @var    JApplicationCms
	 * @since  3.8.5
	 */
	protected $app;

	/**
	 * Load the language file on instantiation.
	 *
	 * @var    boolean
	 */
	protected $autoloadLanguage = true;

	/**
	 * Database object
	 *
	 * @var    JDatabaseDriver
	 */
	protected $db;

	/**
	 * The authentication source
	 *
	 * @var string
	 */
	protected $authsource;

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
			Log::addLogger(array('text_file' => $this->params->get('log', 'eshiol.log.php'), 'extension' => 'plg_user_cie_file'), Log::ALL, array('plg_user_cie'));
		}
		Log::addLogger(array('logger' => (null !== $this->params->get('logger')) ?$this->params->get('logger') : 'messagequeue', 'extension' => 'plg_user_cie'), JLOG::ALL & ~JLOG::DEBUG, array('plg_user_cie'));

		Log::add(new LogEntry(__METHOD__, Log::DEBUG, 'plg_user_cie'));

		if (!$this->checkSPiD())
		{
			// Disable all SPiD plugins
			$query = $this->db->getQuery(true);
			$query->update($this->db->quoteName('#__extensions'))
				->set($this->db->quoteName('enabled') . ' = 0')
				->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
				->where($this->db->quoteName('element') . ' = ' . $this->db->quote('cie'));
			Log::add(new LogEntry($query, Log::DEBUG, 'plg_authentication _cie'));
			$this->db->setQuery($query)->execute();

			return;
		}
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
			$spidsdk->logout(null, false);
		}
		return true;
	}

	protected function checkSPiD()
	{
		if (!file_exists(JPATH_SPIDPHP . '/spid-php.php'))
		{
			if ($this->params->get('debug', 0))
			{
				Log::add(new LogEntry('PLG_USER_CIE_SPIDPHPNOTFOUND', Log::ERROR, 'plg_user_cie'));
			}
			return false;
		}
		elseif (!LibraryHelper::isEnabled('eshiol/SPiD'))
		{
			if ($this->params->get('debug', 0))
			{
				Log::add(new LogEntry('PLG_USER_CIE_SPIDLIBRARYDISABLED', Log::ERROR, 'plg_user_cie'));
			}
			return false;
		}
		elseif (!file_exists(JPATH_SPIDPHP_SIMPLESAMLPHP . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php'))
		{
			if ($this->params->get('debug', 0))
			{
				Log::add(new LogEntry('PLG_USER_CIE_CONFIGFILENOTFOUND', Log::ERROR, 'plg_user_cie'));
			}
			return false;
		}
		elseif (!file_exists(JPATH_SPIDPHP_SIMPLESAMLPHP . DIRECTORY_SEPARATOR . 'metadata' . DIRECTORY_SEPARATOR . 'saml20-idp-remote.php'))
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
					Log::add(new LogEntry('PLG_USER_CIE_CERTNOTFOUND', Log::ERROR, 'plg_user_cie'));
				}
				return false;
			}
		}
		return true;
	}

	/**
	 * @param   string     $context  The context for the data
	 * @param   integer    $data     The user id
	 *
	 * @return  boolean
	 *
	 * @since   3.10.1
	 */
	public function onContentPrepareData($context, $data)
	{
		Log::add(new LogEntry(__METHOD__, Log::DEBUG, 'plg_user_cie'));

		// Check we are manipulating a valid form.
		if (!in_array($context, array('com_users.profile', 'com_users.user', 'com_users.registration', 'com_admin.profile')))
		{
			return true;
		}

		if (is_object($data))
		{
			$userId = isset($data->id) ? $data->id : 0;

			if ($userId == 0)
			{
				// Read the default user group option from com_users
				$uParams = ComponentHelper::getParams('com_users');
				$defaultUserGroup = $this->params->get('new_usertype', $uParams->get('new_usertype', $uParams->get('guest_usergroup', 1)));
				Log::add(new LogEntry('defaultUserGroup: ' . $defaultUserGroup, Log::DEBUG, 'plg_user_cie'));
				$data->groups = array($defaultUserGroup);
			}
			if (!isset($data->profile) and $userId > 0)
			{
				// Load the profile data from the database.
				$this->db->setQuery(
					'SELECT profile_key, profile_value FROM #__user_profiles' .
					' WHERE user_id = ' . (int) $userId . " AND profile_key LIKE 'profile.%'" .
					' ORDER BY ordering');

				try
				{
					$results = $this->db->loadRowList();
				}
				catch (RuntimeException $e)
				{
					$this->_subject->setError($e->getMessage());
					return false;
				}

				// Merge the profile data.
				$data->profile = array();

				foreach ($results as $v)
				{
					$k = str_replace('profile.', '', $v[0]);
					$data->profile[$k] = json_decode($v[1], true);
					if ($data->profile[$k] === null)
					{
						$data->profile[$k] = $v[1];
					}
				}
			}
		}

		return true;
	}

	/**
	 * @param   Jomla\CMS\Form\Form     $form    The form to be altered.
	 * @param   array                   $data    The associated data for the form.
	 *
	 * @return  boolean
	 *
	 * @since   3.8.5
	 */
	public function onContentPrepareForm($form, $data)
	{
		Log::add(new LogEntry(__METHOD__, Log::DEBUG, 'plg_user_cie'));
		Log::add(new LogEntry(print_r($data, true), Log::DEBUG, 'plg_user_cie'));

		if (!($form instanceof Form))
		{
			$this->_subject->setError('JERROR_NOT_A_FORM');
			return false;
		}

		// Check we are manipulating a valid form.
		$name = $form->getName();
		Log::add(new LogEntry('form name: ' . $name, Log::DEBUG, 'plg_user_cie'));

		if (!in_array($name, array('com_admin.profile', 'com_users.user', 'com_users.profile', 'com_users.registration')))
		{
			return true;
		}

		if ($this->app->isClient('administrator'))
		{
			// Add the registration fields to the form.
			$file = Path::find($form->addFormPath(__DIR__ . '/profiles'), 'profile.xml');
			$data = file_get_contents($file);
			if ($this->app->isClient('administrator'))
			{
				$re    = '/<fieldset name="(.*)" label="(.*)">/m';
				$subst = "<fieldset name=\"profile\"><field name=\"$1\" type=\"spacer\" label=\"$2\" class=\"text\"/>";
				$data  = preg_replace($re, $subst, $data);
			}
			$xml  = simplexml_load_string($data);
			$form->load($xml);
		}
		else
		{
			$profile = array();
			if (is_array($data) && key_exists('profile', $data))
			{
				$profile = $data['profile'];
			}
			if (is_object($data) && isset($data->id))
			{
				$profile = $data->profile;
			}
			Log::add(new LogEntry('cie.cie: ' . $this->app->getUserState('cie.cie'), Log::DEBUG, 'plg_user_cie'));
			if ($this->app->getUserState('cie.cie'))
			{
				if (in_array($name, array('com_users.profile', 'com_users.registration')))
				{
					$form->setFieldAttribute('name', 'readonly', 'true');
					$form->setFieldAttribute('username', 'readonly', 'true');
					$form->setFieldAttribute('name', 'readonly', 'true', 'profile');
					$form->setFieldAttribute('familyName', 'readonly', 'true', 'profile');
					$form->setFieldAttribute('gender', 'readonly', 'true', 'profile');
					$form->setFieldAttribute('dateOfBirth', 'readonly', 'true', 'profile');
					$form->setFieldAttribute('placeOfBirth', 'readonly', 'true', 'profile');
					$form->removeField('password1');
					$form->removeField('password2');
				}
				foreach (self::$fields as $field)
				{
					if (!empty($profile[$field]))
					{
						$form->setFieldAttribute($field, 'readonly', 'readonly', 'profile');
					}
				}
			}
		}



		return true;
	}

	/**
	 * Utility method to act on a user after it has been saved.
	 *
	 * @param   array    $user     Holds the new data.
	 * @param   boolean  $isnew    True if a new is stored.
	 * @param   boolean  $success  True if data was successfully stored in the database.
	 * @param   string   $msg      Message.
	 *
	 * @return  void
	 *
	 * @since   3.8.5
	 */
	public function onUserAfterSave($data, $isnew, $success, $msg)
	{
		Log::add(new LogEntry(__METHOD__, Log::DEBUG, 'plg_user_cie'));

		$userId = JArrayHelper::getValue($data, 'id', 0, 'int');

		if ($userId && $success && isset($data['profile']) && (count($data['profile'])))
		{
			try
			{
				$keys = array_keys($data['profile']);

				foreach ($keys as &$key)
				{
					$key = 'profile.' . $key;
					$key = $this->db->quote($key);
				}

				$query = $this->db->getQuery(true)
					->delete($this->db->quoteName('#__user_profiles'))
					->where($this->db->quoteName('user_id') . ' = ' . (int) $userId)
					->where($this->db->quoteName('profile_key') . ' IN (' . implode(',', $keys) . ')');
				$this->db->setQuery($query);
				$this->db->execute();

				$tuples = array();
				$order = 1;

				foreach ($data['profile'] as $k => $v)
				{
					$tuples[] = '(' . $userId . ', ' . $this->db->quote('profile.' . $k) . ', ' . $this->db->quote(json_encode($v)) . ', ' . $order++ . ')';
				}

				$this->db->setQuery('INSERT INTO #__user_profiles VALUES ' . implode(', ', $tuples));
				$this->db->execute();
			}
			catch (RuntimeException $e)
			{
				$this->_subject->setError($e->getMessage());
				return false;
			}

			$uParams = JComponentHelper::getParams('com_users');
			$userActivation = $this->params->get('userActivation', $uParams->get('useractivation'));
			if ($userActivation == 0)
			{
				$this->app->login(
					['username' => '', 'password' => ''],
					['remember' => false, 'silent' => true]
				);

				$return = base64_decode($this->app->input->getInputForRequestMethod()->get('return', '', 'BASE64'));

				$this->app->redirect(JRoute::_($return, false));
			}
		}

		return true;
	}

	/**
	 * Remove all user profile information for the given user ID
	 *
	 * Method is called after user data is deleted from the database
	 *
	 * @param   array    $user     Holds the user data
	 * @param   boolean  $success  True if user was succesfully stored in the database
	 * @param   string   $msg      Message
	 *
	 * @return  boolean
	 *
	 * @since   3.8.5
	 */
	public function onUserAfterDelete($user, $success, $msg)
	{
		Log::add(new LogEntry(__METHOD__, Log::DEBUG, 'plg_user_cie'));

		if (!$success)
		{
			return false;
		}

		$userId = JArrayHelper::getValue($user, 'id', 0, 'int');

		if ($userId)
		{
			try
			{
				$this->db->setQuery(
					'DELETE FROM #__user_profiles WHERE user_id = ' . $userId .
					" AND profile_key LIKE 'profile.%'");

				$this->db->execute();
			}
			catch (Exception $e)
			{
				$this->_subject->setError($e->getMessage());
				return false;
			}
		}

		return true;
	}

	/**
	 * Method to handle
	 *
	 * @param   array  $user     Holds the user data.
	 * @param   array  $options  Array holding options (remember, autoregister, group).
	 *
	 * @return  boolean  True on success.
	 *
	 * @since	3.8.5
	 */
	public function onUserLogin($user, $options = array())
	{
		Log::add(new LogEntry(__METHOD__, Log::DEBUG, 'plg_user_cie'));
		$tmp = $user;
		if (isset($tmp['password'])) {
			$tmp['password'] = '*******';
		}
		Log::add(new LogEntry(print_r($tmp, true), Log::DEBUG, 'plg_user_cie'));

		if (($user['status'] == 1) && ($user['type'] == 'SPiD'))
		{
			unset($user['error_message']);
			$this->app->setUserState('cie.cie', true);

			try
			{
				$userId = (int) User::getInstance($user['username'])->id;

				$profiles = array();
				include JPATH_SPIDPHP_SIMPLESAMLPHP . '/config/authsources.php';
				$keys = $config['service']['attributes'];
				array_push($keys, "cie");
				foreach ($keys as $k)
				{
					$profiles[] = $this->db->quote('profile.' . $k);
				}

				$order = 0;
				$tuples = array();
				foreach ($keys as $k)
				{
					if (isset($user['cie'][$k]))
					{
						$tuple = $userId . ', ' . $this->db->quote('profile.' . $k) . ', ' . $this->db->quote(json_encode($user['cie'][$k])) . ', ' . $order++;
						Log::add(new LogEntry($tuple, Log::DEBUG, 'plg_user_cie'));
						$tuples[] = $tuple;
					}
				}

				$query = 'REPLACE INTO #__user_profiles VALUES (' . implode('), (', $tuples) . ')';
				Log::add(new LogEntry($query, Log::DEBUG, 'plg_user_cie'));
				$this->db->setQuery($query);
				$this->db->execute();
			}
			catch (RuntimeException $e)
			{
				Log::add(new LogEntry($e->getMessage(), Log::DEBUG, 'plg_user_cie'));
				return false;
			}
		}

		return true;
	}
}
