<?php
/**
 * @package     Joomla.Site
 * @subpackage  Modules.LoginCIE
 *
 * @version     __DEPLOY_VERSION__
 * @since       3.10
 *
 * @author      Helios Ciancio <info (at) eshiol (dot) it>
 * @link        https://www.eshiol.it
 * @copyright   Copyright (C) 2023 Helios Ciancio. All rights reserved
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU/GPL v3
 * Joomla.Site.Modules.LoginCIE is free software. This version  may have been 
 * modified pursuant to the GNU General Public License, and as distributed it
 * includes  or  is derivative of works licensed under the GNU General Public 
 * License or other free or open source software licenses.
 */

defined('_JEXEC') or die;

use eshiol\SPiD\CiE;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

HTMLHelper::_('bootstrap.tooltip');
HtmlHelper::_('behavior.modal', 'a.modal');
?>

<form action="<?php echo Route::_('index.php' . ($auth == 'TLS' ? '?idp=cns' : ''), true, $params->get('usesecure')); ?>" method="post" id="cie-login-form"
		class="form-validate form-horizontal well">
	<?php
		if ($auth == 'SAML')
		{
			HTMLHelper::_('stylesheet', 'mod_login_cie/cie.css', array('version' => 'auto', 'relative' => true));
			
			$environment = (int) $params->get('environment', 1);
			$production  = ($environment == 3);
			$service     = (empty($_REQUEST['service']) ? 'cieid' : $_REQUEST['service']);
			$spidsdk     = new CiE($production, $service);
		}
	?>
 	<div class="pretext">
		<p><?php echo Text::_('MOD_LOGIN_CIE_LOGIN_TEXT'); ?></p>
	</div>

	<div>
		<div>
			<?php if ($auth == 'SAML') : ?>
				<button type="submit"><img style="width:220px" class="pull-center" src="/media/mod_login_cie/images/entra-con-cie.png" alt=""></button>
			<?php else : // if ($auth == 'TLS') : ?>
				<a href="#" class="js-fr-dialogmodal-open" aria-controls="modal-entra-con-cie">
					<img style="width:220px" class="pull-center" src="/media/mod_login_cie/images/entra-con-cie.png" alt="<?php echo TEXT::_('MOD_LOGIN_CIE_LOGIN_BUTTON'); ?>">
				</a>
				<?php echo HTMLHelper::_('bootstrap.renderModal', 'modal-entra-con-cie', 
					['title' => TEXT::_('MOD_LOGIN_CIE_POPUP_TITLE'), 'height' => 800,	'width' => '100%', 'closeButton' => false,
					'footer' => '<div><div>' . 
						'<a href="#" class="js-fr-dialogmodal-close">' . Text::_('JLIB_HTML_BEHAVIOR_CLOSE') . '</a> ' .
						'<button type="submit">' . Text::_('JLOGIN') . '</button></div></div>'],
						'<div><p>' . Text::_('MOD_LOGIN_CIE_POPUP_TEXT') . '</p></div>'); ?>		
			<?php endif; ?>
		</div>
	</div>
	<input type="hidden" name="option" value="com_users" />
	<input type="hidden" name="task" value="user.login" />
	<?php if ($auth == 'SAML') : ?>
	<input type="hidden" name="idp" value="CIE<?php echo ($environment != 3 ? ' TEST' : ''); ?>" />
	<?php endif; ?>
	<input type="hidden" name="return" value="<?php echo $return; ?>" />
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
