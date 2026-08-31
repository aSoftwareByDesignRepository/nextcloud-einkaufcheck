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
$workspace = is_array($_['workspace'] ?? null) ? $_['workspace'] : null;
$privacy = is_array($workspace) ? (string)($workspace['privacyMode'] ?? 'private') : 'private';
$isPrivate = $privacy === 'private';
?>
<?php if (!$canManage): ?>
	<section class="ekc-card ekc-empty" role="status">
		<h2><?php p($l->t('Managers only')); ?></h2>
		<p><?php p($l->t('Only managers can invite people or change roles.')); ?></p>
	</section>
<?php else: ?>
<section class="ekc-card ekc-section" aria-labelledby="ekc-members-title">
	<header class="ekc-section__header">
		<div>
			<h2 id="ekc-members-title"><?php p($l->t('People')); ?></h2>
			<p class="ekc-section__sub"><?php p($l->t('Share this shopping list with household members. Managers change settings, contributors edit the list, viewers only look.')); ?></p>
		</div>
	</header>

	<div class="ekc-member-invite" data-ekc-member-invite aria-labelledby="ekc-member-invite-title">
		<h3 id="ekc-member-invite-title" class="ekc-member-invite__title"><?php p($l->t('Add a person')); ?></h3>
		<p id="ekc-member-invite-hint" class="ekc-field__hint ekc-field__hint--block"><?php p($l->t('Search for people in your Nextcloud groups, or type their exact login name (at least two characters). Pick one, choose a role, then add them.')); ?></p>
		<div class="ekc-member-invite__grid">
			<div class="ekc-entity-picker ekc-member-invite__search">
				<label for="ekc-member-invite-q" class="ekc-sr-only"><?php p($l->t('Search for a person to add')); ?></label>
				<input id="ekc-member-invite-q" type="search" class="ekc-input ekc-entity-picker__q" autocomplete="off" maxlength="120"
					aria-describedby="ekc-member-invite-hint" placeholder="<?php p($l->t('Search for a person')); ?>">
				<div id="ekc-member-invite-suggest" class="ekc-entity-picker__suggest" hidden aria-live="polite"></div>
			</div>
			<label class="ekc-field ekc-member-invite__role">
				<span class="ekc-field__label"><?php p($l->t('Role')); ?></span>
				<select id="ekc-member-invite-role" class="ekc-input" data-ekc-member-invite-role>
					<option value="viewer"><?php p($l->t('Viewer — look only')); ?></option>
					<option value="contributor" selected><?php p($l->t('Contributor — edit list')); ?></option>
					<option value="manager"><?php p($l->t('Manager — full control')); ?></option>
				</select>
			</label>
			<button type="button" class="button primary ekc-member-invite__submit" data-ekc-action="member-invite-submit">
				<?php p($l->t('Add person')); ?>
			</button>
		</div>
		<div class="ekc-member-picked" data-ekc-member-selected-wrap hidden role="status" aria-live="polite">
			<p class="ekc-member-picked__label"><?php p($l->t('Selected')); ?></p>
			<div class="ekc-member-picked__row">
				<p class="ekc-member-picked__value" data-ekc-member-selected></p>
				<button type="button" class="button" data-ekc-action="member-invite-clear"><?php p($l->t('Clear')); ?></button>
			</div>
		</div>
	</div>

	<div class="ekc-member-invite" data-ekc-group-invite aria-labelledby="ekc-group-invite-title"
		<?php if ($isPrivate) { ?>data-ekc-private-locked="1"<?php } ?>>
		<h3 id="ekc-group-invite-title" class="ekc-member-invite__title"><?php p($l->t('Add a group')); ?></h3>
		<p id="ekc-group-invite-hint" class="ekc-field__hint ekc-field__hint--block"><?php p($l->t('Everyone in the group gets the same role. Groups can be viewers or contributors — managers stay individual people.')); ?></p>
		<p class="ekc-callout ekc-callout--info" data-ekc-private-groups-blocked <?php if (!$isPrivate) { ?>hidden<?php } ?> role="status">
			<?php p($l->t('This shopping space is private. Only individual people can be members — switch to Standard in Shopping space settings to allow groups.')); ?>
		</p>
		<div class="ekc-member-invite__grid" data-ekc-group-invite-fields <?php if ($isPrivate) { ?>hidden<?php } ?>>
			<div class="ekc-entity-picker ekc-member-invite__search">
				<label for="ekc-group-invite-q" class="ekc-sr-only"><?php p($l->t('Search for a group to add')); ?></label>
				<input id="ekc-group-invite-q" type="search" class="ekc-input ekc-entity-picker__q" autocomplete="off" maxlength="120"
					aria-describedby="ekc-group-invite-hint" placeholder="<?php p($l->t('Search for a group')); ?>"
					<?php if ($isPrivate) { ?>disabled<?php } ?>>
				<div id="ekc-group-invite-suggest" class="ekc-entity-picker__suggest" hidden aria-live="polite"></div>
			</div>
			<label class="ekc-field ekc-member-invite__role">
				<span class="ekc-field__label"><?php p($l->t('Role')); ?></span>
				<select id="ekc-group-invite-role" class="ekc-input" data-ekc-group-invite-role <?php if ($isPrivate) { ?>disabled<?php } ?>>
					<option value="viewer"><?php p($l->t('Viewer — look only')); ?></option>
					<option value="contributor" selected><?php p($l->t('Contributor — edit list')); ?></option>
				</select>
			</label>
			<button type="button" class="button primary ekc-member-invite__submit" data-ekc-action="group-invite-submit" <?php if ($isPrivate) { ?>disabled<?php } ?>>
				<?php p($l->t('Add group')); ?>
			</button>
		</div>
		<div class="ekc-member-picked" data-ekc-group-selected-wrap hidden role="status" aria-live="polite">
			<p class="ekc-member-picked__label"><?php p($l->t('Selected')); ?></p>
			<div class="ekc-member-picked__row">
				<p class="ekc-member-picked__value" data-ekc-group-selected></p>
				<button type="button" class="button" data-ekc-action="group-invite-clear"><?php p($l->t('Clear')); ?></button>
			</div>
		</div>
	</div>

	<div class="ekc-table-scroll" role="region" aria-label="<?php p($l->t('Members')); ?>" tabindex="0">
		<table class="ekc-table ekc-members-table">
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Member')); ?></th>
					<th scope="col"><?php p($l->t('Type')); ?></th>
					<th scope="col"><?php p($l->t('Role')); ?></th>
					<th scope="col" class="ekc-sr-only"><?php p($l->t('Actions')); ?></th>
				</tr>
			</thead>
			<tbody data-ekc-member-rows>
				<tr>
					<td colspan="4" class="ekc-loading"><?php p($l->t('Loading…')); ?></td>
				</tr>
			</tbody>
		</table>
	</div>
</section>
<?php endif; ?>
