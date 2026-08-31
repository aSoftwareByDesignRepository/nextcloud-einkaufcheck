<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$canManage = !empty($_['canManageWorkspace']);
$canManagePrivacy = array_key_exists('canManagePrivacy', $_)
	? (bool)$_['canManagePrivacy']
	: $canManage;
$workspace = is_array($_['workspace'] ?? null) ? $_['workspace'] : null;
if ($workspace === null) {
	return;
}
$privacy = (string)($workspace['privacyMode'] ?? 'private');
?>
<?php if (!$canManage): ?>
	<section class="ekc-card ekc-empty" role="status">
		<h2><?php p($l->t('Managers only')); ?></h2>
		<p><?php p($l->t('Only managers can rename this shopping space or change who may find it.')); ?></p>
	</section>
<?php else: ?>
<section class="ekc-card ekc-section" aria-labelledby="ekc-ws-title">
	<header class="ekc-section__header">
		<div>
			<h2 id="ekc-ws-title"><?php p($l->t('Shopping space')); ?></h2>
			<p class="ekc-section__sub"><?php p($l->t('This space holds one shopping list, staple watches, and a postcode. Only people you invite can open a private space.')); ?></p>
		</div>
	</header>

	<form class="ekc-form-grid" data-ekc-workspace-form>
		<label class="ekc-field">
			<span class="ekc-field__label"><?php p($l->t('Name')); ?></span>
			<input type="text" name="name" class="ekc-input" maxlength="128" required
				value="<?php p((string)($workspace['name'] ?? '')); ?>"
				aria-describedby="ekc-ws-name-hint">
			<span id="ekc-ws-name-hint" class="ekc-field__hint"><?php p($l->t('A short name you and your household recognise.')); ?></span>
		</label>

		<fieldset class="ekc-fieldset ekc-fieldset--privacy ekc-field--full-width" <?php if (!$canManagePrivacy) { ?>disabled<?php } ?>>
			<legend class="ekc-fieldset__legend"><?php p($l->t('Who can see this space?')); ?></legend>
			<p id="ekc-ws-privacy-hint" class="ekc-field__hint ekc-field__hint--block">
				<?php p($l->t('Private is the default: only invited people. Standard also lets EinkaufCheck admins open it to help. This is not encryption — your Nextcloud host can still read database rows.')); ?>
			</p>
			<label class="ekc-radio-card">
				<input type="radio" name="privacyMode" value="private"
					<?php if ($privacy === 'private') { ?>checked<?php } ?>
					aria-describedby="ekc-ws-privacy-hint">
				<span class="ekc-radio-card__body">
					<span class="ekc-radio-card__title"><?php p($l->t('Private — only people I invite')); ?></span>
					<span class="ekc-radio-card__text"><?php p($l->t('Best for household shopping. Groups are turned off.')); ?></span>
				</span>
			</label>
			<label class="ekc-radio-card">
				<input type="radio" name="privacyMode" value="standard"
					<?php if ($privacy === 'standard') { ?>checked<?php } ?>
					aria-describedby="ekc-ws-privacy-hint">
				<span class="ekc-radio-card__body">
					<span class="ekc-radio-card__title"><?php p($l->t('Standard — invite people or groups')); ?></span>
					<span class="ekc-radio-card__text"><?php p($l->t('App admins can also open this space if someone needs help.')); ?></span>
				</span>
			</label>
		</fieldset>

		<div class="ekc-form-actions ekc-field--full-width">
			<button type="submit" class="button primary"><?php p($l->t('Save shopping space')); ?></button>
		</div>
	</form>
</section>

<?php
$canDelete = !empty($_['canDeleteWorkspace']);
if ($canDelete):
?>
<section class="ekc-card ekc-section ekc-section--danger" aria-labelledby="ekc-ws-delete-title">
	<header class="ekc-section__header">
		<div>
			<h2 id="ekc-ws-delete-title"><?php p($l->t('Delete this shopping space')); ?></h2>
			<p class="ekc-section__sub"><?php p($l->t('Removes the list, staple watches, and members for this space. You cannot undo this. If it was your only space, a new private one is created automatically.')); ?></p>
		</div>
	</header>
	<button type="button" class="button ekc-danger-button" data-ekc-action="workspace-delete">
		<?php p($l->t('Delete shopping space')); ?>
	</button>
</section>
<?php endif; ?>
<?php endif; ?>
