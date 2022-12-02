<?php
/**
 * @package     Joomla.Plugins
 * @subpackage  Authentication.CiE
 *
 * @version     __DEPLOY_VERSION__
 * @since       3.10
 *
 * @author      Helios Ciancio <info (at) eshiol (dot) it>
 * @link        https://www.eshiol.it
 * @copyright   Copyright (C) 2022 Helios Ciancio. All rights reserved
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU/GPL v3
 * SPiD  for  Joomla!  is  free software. This version may have been modified
 * pursuant to the GNU General Public License, and as distributed it includes
 * or is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 */

defined('_JEXEC') or die;

use eshiol\SPiD\CiE;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

HTMLHelper::_('bootstrap.tooltip');
?>

<form action="<?php echo Route::_('index.php', true, $this->params->get('usesecure')); ?>" method="post" id="cie-login-form"
		class="form-validate form-horizontal well">
	<?php
		HTMLHelper::_('stylesheet', 'plg_authentication_cie/cie.css', array('version' => 'auto', 'relative' => true));

		$environment = (int) $this->params->get('environment', 1);
		$production  = ($environment == 3);
		$service     = (empty($_REQUEST['service']) ? 'cieid' : $_REQUEST['service']);
		$spidsdk     = new CiE($production, $service);
	?>
 	<div class="pretext">
		<p><?php echo Text::_('PLG_AUTHENTICATION_CIE_LOGIN_TEXT'); ?></p>
	</div>

	<div class="text-center">
		<button type="submit"><img style="width:220px" class="pull-center" src="/media/plg_authentication_cie/images/entra-con-cie.png" alt=""></button>
	</div>

	<input type="hidden" name="option" value="com_users" />
	<input type="hidden" name="task" value="user.login" />
	<input type="hidden" name="idp" value="CIE<?php echo ($environment != 3 ? ' TEST' : ''); ?>" />
	<input type="hidden" name="return" value="<?php echo $return; ?>" />
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
