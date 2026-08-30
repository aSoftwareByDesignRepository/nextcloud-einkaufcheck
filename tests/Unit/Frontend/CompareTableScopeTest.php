<?php

declare(strict_types=1);

namespace OCA\EinkaufCheck\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class CompareTableScopeTest extends TestCase {
	public function testCompareTheadCellsHaveScopeCol(): void {
		$js = (string)file_get_contents(dirname(__DIR__, 3) . '/js/app.js');
		self::assertNotSame('', $js);
		self::assertMatchesRegularExpression(
			'/<th scope="col">\$\{esc\(t\(APP, \'Store\'\)\)\}<\/th>/',
			$js,
		);
		self::assertStringContainsString('data-cell="${esc(t(APP, \'Store\'))}"', $js);
		self::assertDoesNotMatchRegularExpression(
			'/ekc-compare-card.*<thead><tr><th>\$\{esc/',
			$js,
		);
	}

	public function testShellWrapsNavAndContentSoFlexCannotCollapse(): void {
		$nav = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/common/navigation.php');
		$end = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/common/page-end.php');
		$css = (string)file_get_contents(dirname(__DIR__, 3) . '/css/common/app-layout.css');
		self::assertStringContainsString('id="einkaufcheck-app"', $nav);
		self::assertStringContainsString('class="ekc-brand__title"', $nav);
		self::assertStringNotContainsString('<h2 class="ekc-brand__title">', $nav);
		self::assertStringContainsString('/#einkaufcheck-app', $end);
		self::assertStringContainsString('#content.app-einkaufcheck #einkaufcheck-app', $css);
		self::assertStringContainsString('height: calc(100dvh - var(--header-height, 50px))', $css);
	}

	public function testOffersPageHasVisibleHeadingAndSkipLink(): void {
		$main = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/main.php');
		$start = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/common/page-start.php');
		self::assertStringContainsString('ekc-skip-link', $start);
		self::assertStringContainsString('id="ekc-offers-title"', $main);
		self::assertStringNotContainsString('ekc-offers-title" class="ekc-sr-only"', $main);
		self::assertStringContainsString('minlength="3"', $main);
		self::assertStringContainsString('id="ekc-cat-food"', $main);
		self::assertStringContainsString('for="ekc-only-kg"', $main);
		self::assertStringContainsString('for="ekc-show-images"', $main);
		self::assertStringContainsString('Show pictures', $main);
		self::assertStringContainsString('ekc-item-list--qty', $main);
		self::assertStringContainsString('ekc-item-list--watch', $main);
		self::assertStringContainsString('id="ekc-print"', $main);
		self::assertStringContainsString('id="ekc-list-store-aldi"', $main);
		self::assertStringContainsString('Which shop?', $main);
		self::assertStringContainsString('id="ekc-list-jump"', $main);
		$js = (string)file_get_contents(dirname(__DIR__, 3) . '/js/app.js');
		self::assertStringContainsString('listExportUrl', $js);
		self::assertStringContainsString('listClearUrl', $js);
		self::assertStringContainsString('visibleListItems', $js);
		self::assertStringContainsString('offerMatchesQuery', $js);
		self::assertStringContainsString('Search looks in every category.', $js);
		self::assertStringContainsString('foldSearch', $js);
		self::assertStringContainsString('ekc-quick-filter-dock', $main);
		self::assertStringContainsString('categoryInFilter', $js);
		self::assertStringContainsString("filter === 'food'", $js);
		self::assertStringContainsString("offerCat === 'produce'", $js);
		self::assertStringContainsString('Food, including fruit and vegetables', $main);
		self::assertStringContainsString("cat === 'food'", $js);
	}

	public function testFilterHelpIsNotASubgridChildOnOffersGrid(): void {
		$css = (string)file_get_contents(dirname(__DIR__, 3) . '/css/app.css');
		self::assertStringContainsString(
			'.ekc-filter-grid.ekc-filter-grid--offers > .ekc-filter-field',
			$css,
		);
		self::assertStringContainsString('grid-row: auto', $css);
		self::assertStringContainsString('grid-template-rows: none', $css);
		self::assertStringContainsString('.ekc-filter-field .form-help', $css);
		self::assertStringContainsString('position: static', $css);
		self::assertStringContainsString('position: sticky', $css);
		self::assertStringContainsString('ekc-quick-filter-dock', $css);

		foreach (['/templates/main.php', '/templates/trends.php'] as $rel) {
			$tpl = (string)file_get_contents(dirname(__DIR__, 3) . $rel);
			self::assertMatchesRegularExpression(
				'/<div class="ekc-filter-field__control">\s*<input class="form-input" id="ekc-plz"[\s\S]*?<p class="form-help" id="ekc-plz-help">/',
				$tpl,
				$rel . ' must keep postcode help inside the control, not as a subgrid sibling',
			);
			self::assertDoesNotMatchRegularExpression(
				'/<\/div>\s*<p class="form-help" id="ekc-plz-help">/',
				$tpl,
			);
		}
		$trends = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/trends.php');
		self::assertMatchesRegularExpression(
			'/<div class="ekc-filter-field__control">\s*<input class="form-input" id="ekc-q"[\s\S]*?<p class="form-help" id="ekc-trends-q-help">/',
			$trends,
		);
	}

	public function testTrendsPageIsGrannyReadableAndHasSkipLink(): void {
		$tpl = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/trends.php');
		$nav = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/common/navigation.php');
		$js = (string)file_get_contents(dirname(__DIR__, 3) . '/js/app.js');
		self::assertStringContainsString('id="ekc-trends-filter-title"', $tpl);
		self::assertStringNotContainsString('ekc-trends-filter-title" class="ekc-sr-only"', $tpl);
		self::assertStringContainsString('id="ekc-trends-staples-title"', $tpl);
		self::assertStringContainsString('id="ekc-trends-cheap-title"', $tpl);
		self::assertStringContainsString('minlength="3"', $tpl);
		self::assertStringContainsString('for="ekc-plz"', $tpl);
		self::assertStringContainsString('for="ekc-show-images"', $tpl);
		self::assertStringContainsString('Show pictures', $tpl);
		self::assertStringContainsString('Your staples', $tpl);
		self::assertStringContainsString("page === 'trends'", $js);
		self::assertStringContainsString('bindTrendsPage', $js);
		self::assertStringContainsString("setAttribute('role', 'img')", $js);
		self::assertStringContainsString('ekc-sr-only', $js);
		self::assertStringContainsString('Cheaper than usual', $js);
		self::assertStringContainsString("t(APP, 'Put on list')", $js);
		self::assertStringContainsString('trending-down', $nav);
		self::assertStringContainsString("pageId === 'trends'", $nav);
		self::assertStringContainsString('urls.trends', $js);
	}

	public function testQtyStepperAndWatchPauseArePresent(): void {
		$js = (string)file_get_contents(dirname(__DIR__, 3) . '/js/app.js');
		self::assertStringContainsString('ekc-qty__btn', $js);
		self::assertStringContainsString("t(APP, '%s offers shown.'", $js);
		self::assertStringContainsString('Stop watching this staple?', $js);
		self::assertStringContainsString("listBusy.has(item.id)", $js);
		self::assertStringContainsString('whatsapp_url', $js);
	}

	public function testProductPicturesAreOptInDomThumbsNotInnerHtml(): void {
		$root = dirname(__DIR__, 3);
		$js = (string)file_get_contents($root . '/js/app.js');
		$main = (string)file_get_contents($root . '/templates/main.php');
		$trends = (string)file_get_contents($root . '/templates/trends.php');
		$general = (string)file_get_contents($root . '/templates/parts/settings/general.php');
		self::assertStringContainsString("document.createElement('img')", $js);
		self::assertStringContainsString('referrerPolicy', $js);
		self::assertStringContainsString('ekc-product__body', $js);
		self::assertStringContainsString('picturesEnabled', $js);
		self::assertStringNotContainsString('<img', $js);
		self::assertStringNotContainsString('<img', $main);
		self::assertStringNotContainsString('<img', $trends);
		self::assertStringContainsString('for="ekc-settings-show-images"', $general);
		self::assertStringContainsString('Product pictures', $general);
		self::assertDoesNotMatchRegularExpression('/innerHTML\s*=\s*[`\'"][^`\'"]*<img/', $js);
	}

	public function testOffersTableWrapIsAHorizontalScrollport(): void {
		$shell = (string)file_get_contents(dirname(__DIR__, 3) . '/css/common/shell-chrome.css');
		$app = (string)file_get_contents(dirname(__DIR__, 3) . '/css/app.css');
		$main = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/main.php');
		self::assertDoesNotMatchRegularExpression(
			'/#content\.app-einkaufcheck #app-content \.ekc-table-wrap\s*\{[^}]*overflow:\s*hidden/',
			$shell,
		);
		self::assertStringContainsString('#content.app-einkaufcheck #app-content .ekc-card--table-solo > .ekc-table-wrap', $app);
		self::assertStringContainsString('overflow-x: auto', $app);
		self::assertMatchesRegularExpression(
			'/<div class="ekc-table-wrap" id="ekc-table-wrap" tabindex="0"/',
			$main,
		);
		self::assertStringContainsString('id="ekc-table-scroll-hint"', $main);
	}
}
