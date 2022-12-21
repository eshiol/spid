<?php
/**
 * @package     Joomla.Site
 * @subpackage  Templates.ItaliaPA
 *
 * @version     __DEPLOY_VERSION__
 *
 * @author      Helios Ciancio <info (at) eshiol (dot) it>
 * @link        https://www.eshiol.it
 * @copyright   Copyright (C) 2017 - 2022 Helios Ciancio. All rights reserved
 * @license     http://www.gnu.org/licenses/gpl-3.0.html GNU/GPL v3
 * Template  ItaliaPA  is free software.  This version may have been modified
 * pursuant to the GNU General Public License, and as distributed it includes
 * or is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 */
defined('_JEXEC') or die;

use eshiol\SPiD\SPiD;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\Registry\Registry;

HTMLHelper::_('bootstrap.tooltip');

$plugin = PluginHelper::getPlugin('authentication', 'spid');
$params = new Registry($plugin->params);
?>

<form action="<?php echo Route::_('index.php', true, $params->get('usesecure')); ?>" method="post" id="spid-login-form"
		class="form-validate form-horizontal well Form Form--spaced u-padding-all-xl u-background-grey-10 u-text-r-xs u-layout-prose">
	<?php
		HTMLHelper::_('stylesheet', 'plg_authentication_spid/spid.css', array('version' => 'auto', 'relative' => true));

		$environment = (int) $params->get('environment', 1);
		$production  = ($environment == 3);
		$service     = (empty($_REQUEST['service']) ? 'service' : $_REQUEST['service']);
		$spidsdk     = new SPiD($production, $service);
	?>
	<div class="u-margin-bottom-m">
		<p class="u-text-p"><?php echo Text::_('PLG_AUTHENTICATION_SPID_LOGIN_TEXT'); ?></p>
	</div>

	<?php
	$defaultSpidLevel = 1;
	$options = array();
	for ($i = 3; $i > 0; $i--) :
		if ($params->get('spidlevel' . $i, 1)) :
			$defaultSpidLevel = $i;
			array_unshift($options, HTMLHelper::_('select.option', $i, Text::_('PLG_AUTHENTICATION_SPID_SPIDLEVEL' . $i)));
		endif;
	endfor;
	?>

	<?php if (count($options) > 1) : ?>
		<div class="Form-field Grid-cell u-sizeFull">
			<div class="control-label">
				<label for="spidlevel"><?php echo Text::_('PLG_AUTHENTICATION_SPID_SPIDLEVEL_SELECT'); ?></label>
			</div>
			<div class="controls">
				<?php if (!$production) : ?>
					<?php echo HTMLHelper::_('select.genericlist', $options, 'spidlevel',
						['class' => 'inputbox',
						'onchange' => 'jQuery(\'#spid-idp-button-medium-post-container\').find(\'.icon.spid-spidl1,.icon.spid-spidl2,.icon.spid-spidl3\').removeClass(\'spid-spidl1\').removeClass(\'spid-spidl2\').removeClass(\'spid-spidl3\').addClass(\'spid-spidl\' + this.value)'
						], 'value', 'text'); ?>
				<?php else : ?>
					<?php echo HTMLHelper::_('select.genericlist', $options, 'spidlevel', ['class' => 'inputbox'], 'value', 'text'); ?>
				<?php endif; ?>
			</div>
		</div>
	<?php else : ?>
		<input type="hidden" name="spidlevel" value="<?php echo $defaultSpidLevel; ?>" />
	<?php endif; ?>

	<div class="u-flex u-margin-bottom-m">
		<div class="u-layoutCenter">
		<?php
		$spidsdk->insertSPIDButtonCSS();

		ob_start();
		$spidsdk->insertSPIDButton($params->get('size', 'm'),"POST");
		$html = ob_get_clean();

		// remove form tag
		$re = '/<form.*?>(.*?)<\/form>/si';
		preg_match_all($re, $html, $matches);
		$html = $matches[1][0];

		if ($environment != 1)
		{
			// remove SPID Demo link and SPID Demo (Validator mode) link
			$re = '/<li class="spid-idp-support-link">\s*<button class="idp-button-idp-logo" name="idp" value="(LOCAL|TEST|DEMO|DEMOVALIDATOR)" type="submit"><span class="spid-sr-only">.*<\/button>\s*<\/li>/m';
			$subst = '';
			$html = preg_replace($re, $subst, $html);
		}

		if ($environment != 2)
		{
			// remove SPID Validator link
			$re = '/<li class="spid-idp-support-link">\s*<button class="idp-button-idp-logo" name="idp" value="VALIDATOR" type="submit"><span class="spid-sr-only">.*<\/button>\s*<\/li>/m';
			$subst = '';
			$html = preg_replace($re, $subst, $html);
		}

		if ($environment == 1)
		{
			// add SPiD Level icon
			$re = '/<span class="italia-it-button-icon">.*<\/span>/m';
			$subst = '<span class="italia-it-button-icon">' .
				'<i class="icon spid-spidl' . $defaultSpidLevel . '"></i>' .
				'</span>';
			$html = preg_replace($re, $subst, $html);
		}

		echo '<div id="spid-idp-button-medium-post-container">' . $html . '</div>';

		$spidsdk->insertSPIDButtonJS();
		?>
		</div>
	</div>

	<div class="posttext">
		<p><img class="spid-agid u-layoutCenter" src="/media/plg_authentication_spid/images/spid-agid-logo-lb.png" alt=""></p>
	</div>
	<input type="hidden" name="option" value="com_users" />
	<input type="hidden" name="task" value="user.login" />
	<?php if ($active = $this->app->getMenu()->getActive()) : ?>
		<?php $mparams = $active->getParams(); ?>
		<?php $return = $mparams->get('login_redirect_url', $mparams->get('login_redirect_menuitem', '')); ?>
		<input type="hidden" name="return" value="<?php echo base64_encode($return); ?>" />
	<?php else: ?>
		<input type="hidden" name="return" value="" />
	<?php endif; ?>
	<?php echo HTMLHelper::_('form.token'); ?>
</form>
