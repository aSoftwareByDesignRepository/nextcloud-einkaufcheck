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
<section class="ekc-card" aria-labelledby="ekc-pref-title">
	<header class="ekc-card__header">
		<h2 id="ekc-pref-title"><?php p($l->t('Postcode for this shopping space')); ?></h2>
	</header>
	<div class="ekc-card__body">
		<form id="ekc-pref-form">
			<div class="form-group">
				<label class="form-label form-label--required" for="ekc-settings-plz"><?php p($l->t('Postcode')); ?></label>
				<input class="form-input" id="ekc-settings-plz" name="plz" type="text" inputmode="numeric" maxlength="5" pattern="[0-9]{5}" required aria-required="true" autocomplete="postal-code" aria-describedby="ekc-settings-plz-help" />
				<p class="form-help" id="ekc-settings-plz-help"><?php p($l->t('Used to pick the nearest Lidl Plus store for everyone in this shopping space. ALDI Nord offers are the same nationwide.')); ?></p>
			</div>
			<div class="form-group">
				<label class="form-label" for="ekc-settings-week"><?php p($l->t('Default week')); ?></label>
				<select class="form-select" id="ekc-settings-week" name="week">
					<option value="current"><?php p($l->t('This week')); ?></option>
					<option value="next"><?php p($l->t('Next week')); ?></option>
				</select>
			</div>
			<fieldset class="ekc-fieldset">
				<legend class="ekc-fieldset__legend"><?php p($l->t('Product pictures')); ?></legend>
				<label class="ekc-filter" for="ekc-settings-show-images">
					<input id="ekc-settings-show-images" type="checkbox" aria-describedby="ekc-settings-show-images-help" />
					<?php p($l->t('Show pictures')); ?>
				</label>
				<p class="form-help" id="ekc-settings-show-images-help"><?php p($l->t('Pictures come from ALDI and Lidl. We only load them when this is on.')); ?></p>
			</fieldset>
			<button type="submit" class="ekc-btn ekc-btn--primary"><?php p($l->t('Save')); ?></button>
		</form>
	</div>
</section>
