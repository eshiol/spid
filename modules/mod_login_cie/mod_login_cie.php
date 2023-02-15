<?php
/**
 * @package     Joomla.Site
 * @subpackage  Module.LoginCiE
 *
 * @version     __DEPLOY_VERSION__
 * @since       3.10
 *
 * @author      Helios Ciancio <info (at) eshiol (dot) it>
 * @link        https://www.eshiol.it
 * @copyright   Copyright (C) 2023 Helios Ciancio. All rights reserved
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU/GPL v3
 * Joomla.Site.Module.LoginCiE is free software.  This version  may have been 
 * modified pursuant to the GNU General Public License, and as distributed it
 * includes  or  is derivative of works licensed under the GNU General Public 
 * License or other free or open source software licenses.
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ModuleHelper;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

$auth     = $params->get('auth', 'SAML');
$continue = false;

if ($auth == 'SAML')
{
	if (PluginHelper::isEnabled('authentication', 'cie'))
	{
		$plugin = PluginHelper::getPlugin('authentication', 'cie');
		$sParams = new Registry($plugin->params);
		if (!defined('JPATH_SPIDPHP'))
		{
			define('JPATH_SPIDPHP', $sParams->get('spid-php_path', JPATH_LIBRARIES . '/eshiol/spid-php'));
		}
		defined('JPATH_SPIDPHP_SIMPLESAMLPHP') or define('JPATH_SPIDPHP_SIMPLESAMLPHP', JPATH_SPIDPHP . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'simplesamlphp' . DIRECTORY_SEPARATOR . 'simplesamlphp');
		if (file_exists(JPATH_SPIDPHP . '/spid-php.php'))
		{
			require_once(JPATH_SPIDPHP . '/spid-php.php');

			jimport('eshiol.SPiD.CiE');
			$continue = true;
		}
	}
}
else // if ($auth == 'TLS')
{
	$continue = PluginHelper::isEnabled('authentication', 'cns');
}

if ($continue)
{
	$return = Factory::getApplication()->input->get('return', null, 'base64');

	if (empty($return))
	{
		// Stay on the same page
		$url = Uri::getInstance()->toString();

		// If currect menu has login_redirect_menuitem go to
		if ($item = Factory::getApplication()->getMenu()->getActive())
		{
			if ($redirectId = $item->getParams()->get('login_redirect_menuitem'))
			{
				$lang = '';

				if ($item->language !== '*' && Multilanguage::isEnabled())
				{
					$lang = '&lang=' . $item->language;
				}

				$url = 'index.php?Itemid=' . $redirectId . $lang;
			}
		}

		$url    = $params->get('login', $url);
		if (is_numeric($url))
		{
			$url = 'index.php?Itemid=' . $url;
		}
		$return = base64_encode($url);
	}

	$user   = Factory::getUser();
	$layout = $params->get('layout', 'italiapa');
	$auth   = $params->get('auth', 'SAML');

	// Logged users must load the logout sublayout
	if (!$user->guest)
	{
		$layout .= '_logout';
	}

	require ModuleHelper::getLayoutPath('mod_login_cie', $layout);
}
