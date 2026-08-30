<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$decoded = json_decode((string)($_['urlsJson'] ?? '{}'), true);
$pages = is_array($decoded) ? ($decoded['pages'] ?? []) : [];
$offersPageUrl = (string)($pages['offers'] ?? '#');
require __DIR__ . '/common/page-start.php';
?>

<section class="ekc-card ekc-filter-panel" aria-labelledby="ekc-trends-filter-title">
	<header class="ekc-filter-panel__head">
		<h2 id="ekc-trends-filter-title"><?php p($l->t('Find cheaper prices')); ?></h2>
		<p class="ekc-filter-panel__intro"><?php p($l->t('Type your postcode. Leave search empty to see your staples, or type a product name.')); ?></p>
	</header>
	<div class="ekc-filter-panel__body">
		<form class="ekc-filter-panel__form" id="ekc-trends-form" role="search" aria-label="<?php p($l->t('Trend filters')); ?>" novalidate>
			<div class="ekc-filter-grid ekc-filter-grid--offers" role="group" aria-label="<?php p($l->t('Filter options')); ?>">
				<div class="ekc-filter-field">
					<label class="ekc-filter-field__label" for="ekc-plz"><?php p($l->t('Postcode')); ?></label>
					<div class="ekc-filter-field__control">
						<input class="form-input" id="ekc-plz" name="plz" type="text" inputmode="numeric" maxlength="5" pattern="[0-9]{5}" autocomplete="postal-code" value="24149" required aria-required="true" aria-describedby="ekc-plz-help" />
						<p class="form-help" id="ekc-plz-help"><?php p($l->t('5 digits, e.g. 24149 for Kiel.')); ?></p>
					</div>
				</div>
				<div class="ekc-filter-field">
					<label class="ekc-filter-field__label" for="ekc-week"><?php p($l->t('Week')); ?></label>
					<div class="ekc-filter-field__control">
						<select class="form-select" id="ekc-week" name="week">
							<option value="current"><?php p($l->t('This week')); ?></option>
							<option value="next"><?php p($l->t('Next week')); ?></option>
						</select>
					</div>
				</div>
				<div class="ekc-filter-field">
					<label class="ekc-filter-field__label" for="ekc-q"><?php p($l->t('Search')); ?></label>
					<div class="ekc-filter-field__control">
						<input class="form-input" id="ekc-q" name="q" type="search" placeholder="<?php p($l->t('Milk, bananas, cream…')); ?>" autocomplete="off" minlength="3" maxlength="200" aria-describedby="ekc-trends-q-help" />
						<p class="form-help" id="ekc-trends-q-help"><?php p($l->t('At least 3 letters, for example milk or bananas. Empty shows your staples.')); ?></p>
					</div>
				</div>
				<div class="ekc-filter-field">
					<label class="ekc-filter-field__label" for="ekc-store"><?php p($l->t('Store')); ?></label>
					<div class="ekc-filter-field__control">
						<select class="form-select" id="ekc-store" name="store">
							<option value="all"><?php p($l->t('Both stores')); ?></option>
							<option value="ALDI Nord"><?php p($l->t('ALDI Nord')); ?></option>
							<option value="Lidl"><?php p($l->t('Lidl')); ?></option>
						</select>
					</div>
				</div>
				<div class="ekc-filter-field ekc-filter-field--actions">
					<div class="ekc-filter-field__control ekc-filter-field__control--actions">
						<button type="submit" class="ekc-btn ekc-btn--primary" id="ekc-trends-show"><?php p($l->t('Show trends')); ?></button>
						<button type="button" class="ekc-btn ekc-btn--secondary" id="ekc-trends-clear"><?php p($l->t('Clear search')); ?></button>
					</div>
				</div>
			</div>
		</form>
	</div>
</section>

<div class="ekc-quick-filters">
	<span class="ekc-quick-filters__label" id="ekc-extra-label"><?php p($l->t('Also')); ?></span>
	<div class="ekc-filter-bar" role="group" aria-labelledby="ekc-extra-label">
		<label class="ekc-filter" for="ekc-show-images"><input id="ekc-show-images" type="checkbox" aria-describedby="ekc-show-images-help" /> <?php p($l->t('Show pictures')); ?></label>
	</div>
</div>
<p class="ekc-sr-only" id="ekc-show-images-help"><?php p($l->t('Pictures come from ALDI and Lidl. We only load them when this is on.')); ?></p>
<p class="ekc-hint" id="ekc-images-hint" hidden><?php p($l->t('Tap New prices once so pictures can show.')); ?></p>

<div class="ekc-callout ekc-callout--info" id="ekc-trends-stale" hidden>
	<p class="ekc-callout__title"><?php p($l->t('This week’s prices are not loaded yet')); ?></p>
	<p><?php p($l->t('Open Offers and tap New prices to load this week’s list. We can still show older prices you search for.')); ?></p>
	<p><a class="ekc-btn ekc-btn--primary" id="ekc-trends-open-offers" href="<?php p($offersPageUrl); ?>"><?php p($l->t('Open Offers')); ?></a></p>
</div>

<p class="ekc-hint" id="ekc-trends-weeks" hidden></p>

<div id="ekc-trends-load-error" hidden>
	<div class="ekc-callout ekc-callout--danger" role="alert">
		<p class="ekc-callout__title"><?php p($l->t('Price trends could not be loaded')); ?></p>
		<p id="ekc-trends-load-error-text"></p>
		<button type="button" class="ekc-btn ekc-btn--primary" id="ekc-trends-retry"><?php p($l->t('Try again')); ?></button>
	</div>
</div>

<div class="ekc-loading" id="ekc-trends-loading" aria-busy="true"><?php p($l->t('Loading price trends…')); ?></div>

<section class="ekc-card" id="ekc-trends-staples-wrap" aria-labelledby="ekc-trends-staples-title" hidden>
	<header class="ekc-card__header">
		<h2 id="ekc-trends-staples-title"><?php p($l->t('Your staples')); ?></h2>
		<p class="ekc-card__meta"><?php p($l->t('Products you asked us to watch. We say in words if they are cheaper than in earlier weeks.')); ?></p>
	</header>
	<div class="ekc-card__body">
		<ul class="ekc-trend-list" id="ekc-trends-staples"></ul>
		<div class="ekc-empty-state" id="ekc-trends-staples-empty" hidden>
			<p class="ekc-empty-state__title"><?php p($l->t('No staples on this week’s list')); ?></p>
			<p class="ekc-empty-state__text"><?php p($l->t('Watch staples on Offers to see them here.')); ?></p>
		</div>
	</div>
</section>

<section class="ekc-card" id="ekc-trends-cheap-wrap" aria-labelledby="ekc-trends-cheap-title" hidden>
	<header class="ekc-card__header">
		<h2 id="ekc-trends-cheap-title"><?php p($l->t('Cheap this week')); ?></h2>
		<p class="ekc-card__meta"><?php p($l->t('At least 8 percent cheaper than in the weeks we already have, and at least 5 cents.')); ?></p>
	</header>
	<div class="ekc-card__body">
		<ul class="ekc-trend-list" id="ekc-trends-cheap"></ul>
		<div class="ekc-empty-state" id="ekc-trends-cheap-empty" hidden>
			<p class="ekc-empty-state__title"><?php p($l->t('Nothing is clearly cheaper this week')); ?></p>
			<p class="ekc-empty-state__text"><?php p($l->t('We need a few weeks. Come back next week.')); ?></p>
		</div>
	</div>
</section>

<section class="ekc-card" id="ekc-trends-search-wrap" aria-labelledby="ekc-trends-search-title" hidden>
	<header class="ekc-card__header">
		<h2 id="ekc-trends-search-title"><?php p($l->t('Search results')); ?></h2>
		<p class="ekc-card__meta" id="ekc-trends-search-count" aria-live="polite"></p>
	</header>
	<div class="ekc-card__body">
		<ul class="ekc-trend-list" id="ekc-trends-search"></ul>
		<div class="ekc-empty-state" id="ekc-trends-search-empty" hidden>
			<p class="ekc-empty-state__title"><?php p($l->t('No matching products')); ?></p>
			<p class="ekc-empty-state__text"><?php p($l->t('Try another word, at least 3 letters.')); ?></p>
		</div>
	</div>
</section>

<?php
require __DIR__ . '/common/page-end.php';
