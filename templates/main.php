<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

require __DIR__ . '/common/page-start.php';
?>

<section class="ekc-card ekc-filter-panel" aria-labelledby="ekc-filter-title">
	<header class="ekc-filter-panel__head">
		<h2 id="ekc-filter-title"><?php p($l->t('Find offers')); ?></h2>
		<p class="ekc-filter-panel__intro"><?php p($l->t('Type your 5-digit postcode, pick this week or next, then tap Show offers.')); ?></p>
	</header>
	<div class="ekc-filter-panel__body">
		<form class="ekc-filter-panel__form" id="ekc-filter-form" role="search" aria-label="<?php p($l->t('Offer filters')); ?>" novalidate>
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
						<input class="form-input" id="ekc-q" name="q" type="search" placeholder="<?php p($l->t('Milk, bananas, cream…')); ?>" autocomplete="off" />
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
						<button type="submit" class="ekc-btn ekc-btn--primary" id="ekc-reload"><?php p($l->t('Show offers')); ?></button>
						<button type="button" class="ekc-btn ekc-btn--secondary" id="ekc-refresh"><?php p($l->t('New prices')); ?></button>
						<button type="button" class="ekc-btn ekc-btn--secondary" id="ekc-clear-filters"><?php p($l->t('Clear search')); ?></button>
					</div>
				</div>
			</div>
		</form>
	</div>
</section>

<div class="ekc-quick-filter-dock">
<div class="ekc-quick-filters">
	<span class="ekc-quick-filters__label" id="ekc-cat-label"><?php p($l->t('Show')); ?></span>
	<div class="ekc-filter-bar" role="radiogroup" aria-labelledby="ekc-cat-label">
		<label class="ekc-filter" for="ekc-cat-food"><input id="ekc-cat-food" type="radio" name="ekc-cat" value="food" checked aria-label="<?php p($l->t('Food, including fruit and vegetables')); ?>" /> <?php p($l->t('Food')); ?></label>
		<label class="ekc-filter" for="ekc-cat-produce"><input id="ekc-cat-produce" type="radio" name="ekc-cat" value="produce" /> <?php p($l->t('Fruit & vegetables')); ?></label>
		<label class="ekc-filter" for="ekc-cat-nonfood"><input id="ekc-cat-nonfood" type="radio" name="ekc-cat" value="nonfood" /> <?php p($l->t('Non-food')); ?></label>
		<label class="ekc-filter" for="ekc-cat-all"><input id="ekc-cat-all" type="radio" name="ekc-cat" value="all" /> <?php p($l->t('Everything')); ?></label>
	</div>
</div>
<div class="ekc-quick-filters">
	<span class="ekc-quick-filters__label" id="ekc-extra-label"><?php p($l->t('Also')); ?></span>
	<div class="ekc-filter-bar" role="group" aria-labelledby="ekc-extra-label">
		<label class="ekc-filter" for="ekc-only-kg"><input id="ekc-only-kg" type="checkbox" /> <?php p($l->t('Only with unit price')); ?></label>
		<label class="ekc-filter" for="ekc-only-wait"><input id="ekc-only-wait" type="checkbox" aria-describedby="ekc-only-wait-help" /> <?php p($l->t('Cheaper next week')); ?></label>
		<label class="ekc-filter" for="ekc-only-match"><input id="ekc-only-match" type="checkbox" /> <?php p($l->t('Only in both stores')); ?></label>
		<label class="ekc-filter" for="ekc-show-images"><input id="ekc-show-images" type="checkbox" aria-describedby="ekc-show-images-help" /> <?php p($l->t('Show pictures')); ?></label>
	</div>
</div>
</div>
<p class="ekc-sr-only" id="ekc-only-wait-help"><?php p($l->t('Shows items that cost less next week, so you can wait before buying. Needs both weeks loaded. Lidl Plus is always this week’s list.')); ?></p>
<p class="ekc-sr-only" id="ekc-show-images-help"><?php p($l->t('Pictures come from ALDI and Lidl. We only load them when this is on.')); ?></p>
<p class="ekc-hint" id="ekc-images-hint" hidden><?php p($l->t('Tap New prices once so pictures can show.')); ?></p>
<p class="ekc-hint" id="ekc-week-compare-hint" hidden></p>

<section class="ekc-alerts ekc-callout ekc-callout--success" id="ekc-alerts" hidden></section>

<p class="ekc-hint" id="ekc-fetch-status" hidden></p>
<p class="ekc-hint" id="ekc-lidl-store" hidden></p>

<section class="ekc-stat-strip" id="ekc-stats" aria-label="<?php p($l->t('Overview')); ?>"></section>

<div id="ekc-load-error" hidden>
	<div class="ekc-callout ekc-callout--danger" role="alert">
		<p class="ekc-callout__title"><?php p($l->t('Offers could not be loaded')); ?></p>
		<p id="ekc-load-error-text"></p>
		<button type="button" class="ekc-btn ekc-btn--primary" id="ekc-retry"><?php p($l->t('Try again')); ?></button>
	</div>
</div>

<details class="ekc-advanced ekc-compares-wrap" id="ekc-compares-wrap" hidden>
	<summary><?php p($l->t('Compare same items across stores')); ?></summary>
	<section class="ekc-compares" id="ekc-compares"></section>
</details>

<div class="ekc-page-grid">
	<section class="ekc-card ekc-card--table-solo" aria-labelledby="ekc-offers-title">
		<header class="ekc-card__header">
			<h2 id="ekc-offers-title"><?php p($l->t('This week’s offers')); ?></h2>
			<p class="ekc-card__meta" id="ekc-offers-count" aria-live="polite"></p>
		</header>
		<p class="ekc-sr-only" id="ekc-table-scroll-hint"><?php p($l->t('This table scrolls. Swipe or scroll sideways if you cannot see the unit price.')); ?></p>
		<div class="ekc-table-wrap" id="ekc-table-wrap" tabindex="0" role="region" aria-labelledby="ekc-offers-title" aria-describedby="ekc-table-scroll-hint" aria-busy="false">
			<table class="table table--hover" id="ekc-offers-table">
				<caption class="ekc-sr-only"><?php p($l->t('Own-brand offers for the selected week')); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php p($l->t('Actions')); ?></th>
						<th scope="col"><?php p($l->t('Store')); ?></th>
						<th scope="col"><?php p($l->t('Brand')); ?></th>
						<th scope="col"><?php p($l->t('Product')); ?></th>
						<th scope="col"><?php p($l->t('Pack')); ?></th>
						<th scope="col" class="num"><?php p($l->t('Price')); ?></th>
						<th scope="col" class="num" id="ekc-unit-col"><?php p($l->t('Unit price')); ?></th>
					</tr>
				</thead>
				<tbody id="ekc-rows">
					<tr>
						<td colspan="7">
							<div class="ekc-loading" aria-busy="true"><?php p($l->t('Loading prices from the stores. This can take up to a minute.')); ?></div>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<div class="ekc-empty-state" id="ekc-empty" hidden>
			<p class="ekc-empty-state__title"><?php p($l->t('No matching offers')); ?></p>
			<p class="ekc-empty-state__text"><?php p($l->t('Try another word, or tap Clear to see everything.')); ?></p>
			<button type="button" class="ekc-btn ekc-btn--secondary" id="ekc-empty-clear"><?php p($l->t('Clear filters')); ?></button>
		</div>
	</section>

	<aside class="ekc-side">
		<section class="ekc-card ekc-list-card" id="ekc-list-card" aria-labelledby="ekc-list-title" tabindex="-1">
			<header class="ekc-card__header">
				<h2 class="ekc-card__title" id="ekc-list-title"><?php p($l->t('Shopping list')); ?> <span id="ekc-list-count"></span></h2>
				<p class="ekc-list-print-store" id="ekc-list-print-store" aria-hidden="true"></p>
			</header>
			<div class="ekc-card__body">
				<fieldset class="ekc-list-store-filter">
					<legend class="ekc-list-store-filter__legend"><?php p($l->t('Which shop?')); ?></legend>
					<p class="ekc-hint" id="ekc-list-store-help"><?php p($l->t('You take one shop, someone else takes the other. Print and send only that shop.')); ?></p>
					<div class="ekc-filter-bar">
						<label class="ekc-filter" for="ekc-list-store-all"><input id="ekc-list-store-all" type="radio" name="ekc-list-store" value="all" checked /> <?php p($l->t('Both stores')); ?><span class="ekc-list-store-count" data-ekc-store="all"></span></label>
						<label class="ekc-filter" for="ekc-list-store-aldi"><input id="ekc-list-store-aldi" type="radio" name="ekc-list-store" value="ALDI Nord" /> <?php p($l->t('ALDI Nord')); ?><span class="ekc-list-store-count" data-ekc-store="ALDI Nord"></span></label>
						<label class="ekc-filter" for="ekc-list-store-lidl"><input id="ekc-list-store-lidl" type="radio" name="ekc-list-store" value="Lidl" /> <?php p($l->t('Lidl')); ?><span class="ekc-list-store-count" data-ekc-store="Lidl"></span></label>
					</div>
				</fieldset>
				<div class="ekc-list-actions">
					<div class="ekc-list-actions__share" role="group" aria-label="<?php p($l->t('Send or print this shop')); ?>" aria-describedby="ekc-list-share-help">
						<button type="button" class="ekc-btn ekc-btn--primary" id="ekc-wa"><?php p($l->t('WhatsApp')); ?></button>
						<button type="button" class="ekc-btn ekc-btn--secondary" id="ekc-copy"><?php p($l->t('Copy')); ?></button>
						<button type="button" class="ekc-btn ekc-btn--secondary" id="ekc-csv"><?php p($l->t('CSV')); ?></button>
						<button type="button" class="ekc-btn ekc-btn--secondary" id="ekc-print"><?php p($l->t('Print')); ?></button>
					</div>
					<button type="button" class="ekc-btn ekc-btn--ghost" id="ekc-clear"><?php p($l->t('Empty list')); ?></button>
				</div>
				<p class="ekc-sr-only" id="ekc-list-share-help"><?php p($l->t('WhatsApp, Copy, CSV and Print use the store you picked.')); ?></p>
				<p class="ekc-hint"><?php p($l->t('Tick the box when you have picked it up. Use + and − to change how many.')); ?></p>
				<ul id="ekc-list-items" class="ekc-item-list ekc-item-list--qty"></ul>
				<p class="ekc-empty-inline" id="ekc-list-empty"><?php p($l->t('Your list is empty. Tap + on an offer.')); ?></p>
			</div>
		</section>

		<section class="ekc-card ekc-watch-card" aria-labelledby="ekc-watch-title">
			<header class="ekc-card__header">
				<h2 id="ekc-watch-title"><?php p($l->t('Staples to watch')); ?></h2>
			</header>
			<div class="ekc-card__body">
				<p class="ekc-hint"><?php p($l->t('We ping you when this is on offer — optionally only if the price stays under your cap.')); ?></p>
				<p class="ekc-hint"><?php p($l->t('Tick the box to get a ping. Untick to pause. × removes it.')); ?></p>
				<form id="ekc-watch-form" class="ekc-watch-form">
					<div class="form-group">
						<label class="form-label form-label--required" for="ekc-watch-q"><?php p($l->t('What do you always buy?')); ?></label>
						<input class="form-input" id="ekc-watch-q" name="query" type="text" minlength="3" maxlength="200" required aria-required="true" aria-describedby="ekc-watch-q-help" placeholder="<?php p($l->t('e.g. whipping cream')); ?>" autocomplete="off" />
						<p class="form-help" id="ekc-watch-q-help"><?php p($l->t('At least 3 letters, for example milk or cream.')); ?></p>
					</div>
					<details class="ekc-advanced">
						<summary><?php p($l->t('More options')); ?></summary>
						<div class="ekc-watch-form__extras">
							<div class="form-group">
								<label class="form-label" for="ekc-watch-max"><?php p($l->t('Max price (€)')); ?></label>
								<input class="form-input" id="ekc-watch-max" name="max_price" type="number" step="0.01" min="0" max="9999.99" inputmode="decimal" />
							</div>
							<div class="form-group">
								<label class="form-label" for="ekc-watch-kg"><?php p($l->t('Max €/kg')); ?></label>
								<input class="form-input" id="ekc-watch-kg" name="max_per_kg" type="number" step="0.01" min="0" max="9999.99" inputmode="decimal" />
							</div>
							<div class="form-group">
								<label class="form-label" for="ekc-watch-store"><?php p($l->t('Store')); ?></label>
								<select class="form-select" id="ekc-watch-store" name="store">
									<option value=""><?php p($l->t('Both stores')); ?></option>
									<option value="ALDI Nord"><?php p($l->t('ALDI Nord only')); ?></option>
									<option value="Lidl"><?php p($l->t('Lidl only')); ?></option>
								</select>
							</div>
						</div>
					</details>
					<button type="submit" class="ekc-btn ekc-btn--primary"><?php p($l->t('Watch this')); ?></button>
				</form>
				<ul id="ekc-watch-items" class="ekc-item-list ekc-item-list--watch"></ul>
				<p class="ekc-empty-inline" id="ekc-watch-empty"><?php p($l->t('No staples watched yet.')); ?></p>
			</div>
		</section>
	</aside>
</div>
<a class="ekc-list-jump" id="ekc-list-jump" href="#ekc-list-card"><?php p($l->t('Shopping list')); ?></a>

<?php
require __DIR__ . '/common/page-end.php';
