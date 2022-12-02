<?php
/**
 * @package     Joomla.Plugins
 * @subpackage  User.SPiD
 *
 * @version     __DEPLOY_VERSION__
 * @since       3.7
 *
 * @author      Helios Ciancio <info (at) eshiol (dot) it>
 * @link        https://www.eshiol.it
 * @copyright   Copyright (C) 2017 - 2022 Helios Ciancio. All rights reserved
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU/GPL v3
 * SPiD  for  Joomla!  is  free software. This version may have been modified
 * pursuant to the GNU General Public License, and as distributed it includes
 * or is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 */

defined('_JEXEC') or die;

use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Date\Date;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Log\LogEntry;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\String\PunycodeHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserHelper;
use Joomla\Registry\Registry;

/**
 * Registration model class for Users.
 */
class SpidModelRegistration extends UsersModelRegistration
{
	/**
	 * Method to save the form data.
	 *
	 * @param   array  $temp  The form data.
	 *
	 * @return  mixed  The user id on success, false on failure.
	 */
	public function register($temp)
	{
		Log::add(new LogEntry(__METHOD__, Log::DEBUG, 'plg_authentication_spid'));

		$params = ComponentHelper::getParams('com_users');
		$lang = Factory::getLanguage()->load('com_users', JPATH_SITE);

		// Initialise the table with JUser.
		$user = new User;
		$data = (array) $this->getData();

		// Merge in the registration data.
		foreach ($temp as $k => $v)
		{
			$data[$k] = $v;
		}

		// Prepare the data for the user object.
		$data['email'] = PunycodeHelper::emailToPunycode($data['email1']);
		$data['password'] = $data['password1'];
		$useractivation = $params->get('useractivation');

		// Check if the user needs to activate their account.
		if (($useractivation == 1) || ($useractivation == 2))
		{
			$data['activation'] = ApplicationHelper::getHash(UserHelper::genRandomPassword());
			$data['block'] = 1;
			// confirm email
			$data['params']['activate'] = 1;
		}

		Log::add(new LogEntry(print_r($data, true), Log::DEBUG, 'plg_authentication_spid'));

		// Bind the data.
		if (!$user->bind($data))
		{
			Log::add(new LogEntry(Text::sprintf('COM_USERS_REGISTRATION_BIND_FAILED', $user->getError()), Log::DEBUG, 'plg_authentication_spid'));
			$this->setError(Text::sprintf('COM_USERS_REGISTRATION_BIND_FAILED', $user->getError()));

			return false;
		}

		// Load the users plugin group.
		PluginHelper::importPlugin('user');

		// Store the data.
		if (!$user->save())
		{
			Log::add(new LogEntry(Text::sprintf('COM_USERS_REGISTRATION_SAVE_FAILED', $user->getError()), Log::DEBUG, 'plg_authentication_spid'));
			$this->setError(Text::sprintf('COM_USERS_REGISTRATION_SAVE_FAILED', $user->getError()));

			return false;
		}

		$config = Factory::getConfig();
		$db = $this->getDbo();
		$query = $db->getQuery(true);

		// Compile the notification mail values.
		$data = $user->getProperties();
		$data['fromname'] = $config->get('fromname');
		$data['mailfrom'] = $config->get('mailfrom');
		$data['sitename'] = $config->get('sitename');
		$data['siteurl'] = Uri::root();

		// Handle account activation/confirmation emails.
		if ($useractivation == 2)
		{
			// Set the link to confirm the user email.
			$uri = Uri::getInstance();
			$base = $uri->toString(array('scheme', 'user', 'pass', 'host', 'port'));

			$emailSubject = Text::sprintf(
				'COM_USERS_EMAIL_ACCOUNT_DETAILS',
				$data['name'],
				$data['sitename']
			);

			$emailBody = Text::sprintf(
				'PLG_AUTHENTICATION_SPID_EMAIL_REGISTERED_WITH_ADMIN_ACTIVATION_BODY_NOPW',
				$data['name'],
				$data['sitename'],
				$data['siteurl']
			);
		}
		elseif ($useractivation == 1)
		{
			// Set the link to activate the user account.
			$uri = Uri::getInstance();
			$base = $uri->toString(array('scheme', 'user', 'pass', 'host', 'port'));
			$data['activate'] = $base . Route::_('index.php?option=com_users&task=registration.activate&token=' . $data['activation'], false);

			$emailSubject = Text::sprintf(
				'COM_USERS_EMAIL_ACCOUNT_DETAILS',
				$data['name'],
				$data['sitename']
				);

			$emailBody = Text::sprintf(
				'COM_USERS_EMAIL_REGISTERED_WITH_ACTIVATION_BODY_NOPW',
				$data['name'],
				$data['sitename'],
				$data['activate'],
				$data['siteurl'],
				$data['username']
				);
		}
		else
		{
			$emailSubject = Text::sprintf(
				'COM_USERS_EMAIL_ACCOUNT_DETAILS',
				$data['name'],
				$data['sitename']
				);

			$emailBody = Text::sprintf(
				'COM_USERS_EMAIL_REGISTERED_BODY_NOPW',
				$data['name'],
				$data['sitename'],
				$data['siteurl']
				);
		}

		// Send the registration email.
		$return = Factory::getMailer()->sendMail($data['mailfrom'], $data['fromname'], $data['email'], $emailSubject, $emailBody);

		// Admin activation is on
		if ($useractivation == 2)
		{
			Log::add(new LogEntry('Admin activation is on', Log::DEBUG, 'plg_authentication_spid'));
			$uri = Uri::getInstance();

			// Compile the admin notification mail values.
			$data = $user->getProperties();
//			$data['activation'] = ApplicationHelper::getHash(UserHelper::genRandomPassword());
//			$user->set('activation', $data['activation']);
			$data['siteurl'] = JUri::base();
			$base = $uri->toString(array('scheme', 'user', 'pass', 'host', 'port'));
			$data['activate'] = $base . Route::_('index.php?option=com_users&task=registration.activate&token=' . $data['activation'], false);

			// Remove administrator/ from activate URL in case this method is called from admin
			if (Factory::getApplication()->isAdmin())
			{
				$adminPos         = strrpos($data['activate'], 'administrator/');
				$data['activate'] = substr_replace($data['activate'], '', $adminPos, 14);
			}

			$data['fromname'] = $config->get('fromname');
			$data['mailfrom'] = $config->get('mailfrom');
			$data['sitename'] = $config->get('sitename');
			$user->setParam('activate', 1);
			$emailSubject = Text::sprintf(
				'COM_USERS_EMAIL_ACTIVATE_WITH_ADMIN_ACTIVATION_SUBJECT',
				$data['name'],
				$data['sitename']
				);

			$emailBody = Text::sprintf(
				'PLG_AUTHENTICATION_SPID_EMAIL_ACTIVATE_WITH_ADMIN_ACTIVATION_BODY',
				$data['sitename'],
				$data['name'],
				$data['email'],
				$data['username'],
				$data['activate']
				);

			// Get all admin users
			$query->clear()
				->select($db->quoteName(array('name', 'email', 'sendEmail', 'id')))
				->from($db->quoteName('#__users'))
				->where($db->quoteName('sendEmail') . ' = 1')
				->where($db->quoteName('block') . ' = 0');

			$db->setQuery($query);

			try
			{
				$rows = $db->loadObjectList();
			}
			catch (RuntimeException $e)
			{
				Log::add(new LogEntry(Text::sprintf('COM_USERS_DATABASE_ERROR', $e->getMessage()), Log::DEBUG, 'plg_authentication_spid'));
				$this->setError(Text::sprintf('COM_USERS_DATABASE_ERROR', $e->getMessage()), 500);

				return false;
			}

			// Send mail to all users with users creating permissions and receiving system emails
			foreach ($rows as $row)
			{
				$usercreator = Factory::getUser($row->id);

				if ($usercreator->authorise('core.create', 'com_users'))
				{
					$return = Factory::getMailer()->sendMail($data['mailfrom'], $data['fromname'], $row->email, $emailSubject, $emailBody);

					// Check for an error.
					if ($return !== true)
					{
						Log::add(new LogEntry(Text::_('COM_USERS_REGISTRATION_ACTIVATION_NOTIFY_SEND_MAIL_FAILED'), Log::DEBUG, 'plg_authentication_spid'));
						$this->setError(JText::_('COM_USERS_REGISTRATION_ACTIVATION_NOTIFY_SEND_MAIL_FAILED'));

						return false;
					}
				}
			}
		}
		// Send Notification mail to administrators
		elseif (($params->get('useractivation') < 2) && ($params->get('mail_to_admin') == 1))
		{
			$emailSubject = Text::sprintf(
				'COM_USERS_EMAIL_ACCOUNT_DETAILS',
				$data['name'],
				$data['sitename']
				);

			$emailBodyAdmin = Text::sprintf(
				'COM_USERS_EMAIL_REGISTERED_NOTIFICATION_TO_ADMIN_BODY',
				$data['name'],
				$data['username'],
				$data['siteurl']
				);

			// Get all admin users
			$query->clear()
				->select($db->quoteName(array('name', 'email', 'sendEmail')))
				->from($db->quoteName('#__users'))
				->where($db->quoteName('sendEmail') . ' = 1')
				->where($db->quoteName('block') . ' = 0');

			$db->setQuery($query);

			try
			{
				$rows = $db->loadObjectList();
			}
			catch (RuntimeException $e)
			{
				Log::add(new LogEntry(Text::sprintf('COM_USERS_DATABASE_ERROR', $e->getMessage()), Log::DEBUG, 'plg_authentication_spid'));
				$this->setError(Text::sprintf('COM_USERS_DATABASE_ERROR', $e->getMessage()), 500);

				return false;
			}

			// Send mail to all superadministrators id
			foreach ($rows as $row)
			{
				$return = Factory::getMailer()->sendMail($data['mailfrom'], $data['fromname'], $row->email, $emailSubject, $emailBodyAdmin);

				// Check for an error.
				if ($return !== true)
				{
					$this->setError(JText::_('COM_USERS_REGISTRATION_ACTIVATION_NOTIFY_SEND_MAIL_FAILED'));

					return false;
				}
			}
		}

		// Check for an error.
		if ($return !== true)
		{
			$this->setError(Text::_('COM_USERS_REGISTRATION_SEND_MAIL_FAILED'));

			// Send a system message to administrators receiving system mails
			$db = $this->getDbo();
			$query->clear()
				->select($db->quoteName('id'))
				->from($db->quoteName('#__users'))
				->where($db->quoteName('block') . ' = ' . (int) 0)
				->where($db->quoteName('sendEmail') . ' = ' . (int) 1);
			$db->setQuery($query);

			try
			{
				$userids = $db->loadColumn();
			}
			catch (RuntimeException $e)
			{
				Log::add(new LogEntry(Text::sprintf('COM_USERS_DATABASE_ERROR', $e->getMessage()), Log::DEBUG, 'plg_authentication_spid'));
				$this->setError(Text::sprintf('COM_USERS_DATABASE_ERROR', $e->getMessage()), 500);

				return false;
			}

			if (count($userids) > 0)
			{
				$jdate = new Date;

				// Build the query to add the messages
				foreach ($userids as $userid)
				{
					$values = array(
						$db->quote($userid),
						$db->quote($userid),
						$db->quote($jdate->toSql()),
						$db->quote(Text::_('COM_USERS_MAIL_SEND_FAILURE_SUBJECT')),
						$db->quote(Text::sprintf('COM_USERS_MAIL_SEND_FAILURE_BODY', $return, $data['username']))
					);
					$query->clear()
						->insert($db->quoteName('#__messages'))
						->columns($db->quoteName(array('user_id_from', 'user_id_to', 'date_time', 'subject', 'message')))
						->values(implode(',', $values));
					$db->setQuery($query);

					try
					{
						$db->execute();
					}
					catch (RuntimeException $e)
					{
						Log::add(new LogEntry(Text::sprintf('COM_USERS_DATABASE_ERROR', $e->getMessage()), Log::DEBUG, 'plg_authentication_spid'));
						$this->setError(Text::sprintf('COM_USERS_DATABASE_ERROR', $e->getMessage()), 500);

						return false;
					}
				}
			}

			return false;
		}

		if ($useractivation == 1)
		{
			return 'useractivate';
		}
		elseif ($useractivation == 2)
		{
			return 'adminactivate';
		}
		else
		{
			return $user->id;
		}
	}
}
