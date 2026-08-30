<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
?>
<section class="ekc-card" aria-labelledby="ekc-access-title">
	<header class="ekc-card__header">
		<h2 id="ekc-access-title"><?php p($l->t('Who can use EinkaufCheck')); ?></h2>
	</header>
	<div class="ekc-card__body">
		<form id="ekc-access-form">
			<fieldset class="form-fieldset">
				<legend class="form-legend"><?php p($l->t('Access mode')); ?></legend>
				<label class="form-radio">
					<input type="radio" name="access_mode" value="open" checked />
					<?php p($l->t('Open — every signed-in person')); ?>
				</label>
				<label class="form-radio">
					<input type="radio" name="access_mode" value="restricted" />
					<?php p($l->t('Restricted — only people and groups you pick')); ?>
				</label>
			</fieldset>

			<div class="form-group">
				<label class="form-label" for="ekc-group-search"><?php p($l->t('Allowed groups')); ?></label>
				<p class="form-help"><?php p($l->t('Search, then pick. Never type a group id.')); ?></p>
				<input class="form-input" id="ekc-group-search" type="search" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="ekc-group-listbox" placeholder="<?php p($l->t('Search groups')); ?>" autocomplete="off" />
				<ul id="ekc-group-listbox" class="ekc-picker-list" role="listbox" hidden></ul>
				<ul id="ekc-group-chips" class="ekc-chip-list" aria-label="<?php p($l->t('Allowed groups')); ?>"></ul>
			</div>

			<div class="form-group">
				<label class="form-label" for="ekc-user-search"><?php p($l->t('Allowed people')); ?></label>
				<p class="form-help"><?php p($l->t('Search by name, then pick. Never type a user id.')); ?></p>
				<input class="form-input" id="ekc-user-search" type="search" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="ekc-user-listbox" placeholder="<?php p($l->t('Search people')); ?>" autocomplete="off" />
				<ul id="ekc-user-listbox" class="ekc-picker-list" role="listbox" hidden></ul>
				<ul id="ekc-user-chips" class="ekc-chip-list" aria-label="<?php p($l->t('Allowed people')); ?>"></ul>
			</div>

			<div class="form-group">
				<label class="form-label" for="ekc-admin-search"><?php p($l->t('App admins')); ?></label>
				<p class="form-help"><?php p($l->t('Nextcloud admins always have access. Extra app admins can change this page.')); ?></p>
				<input class="form-input" id="ekc-admin-search" type="search" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="ekc-admin-listbox" placeholder="<?php p($l->t('Search people')); ?>" autocomplete="off" />
				<ul id="ekc-admin-listbox" class="ekc-picker-list" role="listbox" hidden></ul>
				<ul id="ekc-admin-chips" class="ekc-chip-list" aria-label="<?php p($l->t('App admins')); ?>"></ul>
			</div>

			<button type="submit" class="ekc-btn ekc-btn--primary" id="ekc-access-save"><?php p($l->t('Save access')); ?></button>
		</form>
	</div>
</section>
