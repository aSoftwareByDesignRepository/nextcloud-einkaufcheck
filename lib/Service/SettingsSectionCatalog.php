<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Service;

use OCP\IL10N;

/**
 * Single source of truth for /settings/{section} slugs, labels, and nav.
 *
 * Routes, PageController, and templates must derive from this class — never a
 * second hardcoded list of section names.
 */
final class SettingsSectionCatalog {
	public const DEFAULT_SECTION = 'general';

	/** @var list<string> */
	public const SECTIONS = [
		'general',
		'workspace',
		'members',
		'stores',
		'access',
	];

	public static function routeRequirement(): string {
		return implode('|', self::SECTIONS);
	}

	public function isSection(string $section): bool {
		return in_array($section, self::SECTIONS, true);
	}

	public function isAdminOnly(string $section): bool {
		return $section === 'access';
	}

	public function isManagerOnly(string $section): bool {
		return in_array($section, ['workspace', 'members'], true);
	}

	/**
	 * @return list<string>
	 */
	public function visibleSections(bool $isAppAdmin, bool $canManageWorkspace = true): array {
		$out = [];
		foreach (self::SECTIONS as $slug) {
			if ($this->isAdminOnly($slug) && !$isAppAdmin) {
				continue;
			}
			if ($this->isManagerOnly($slug) && !$canManageWorkspace) {
				continue;
			}
			$out[] = $slug;
		}
		return $out;
	}

	public function label(IL10N $l, string $section): string {
		return match ($section) {
			'general' => $l->t('Postal code'),
			'workspace' => $l->t('Shopping space'),
			'members' => $l->t('People'),
			'stores' => $l->t('Stores'),
			'access' => $l->t('Access'),
			default => $l->t('Settings'),
		};
	}

	public function navLabel(IL10N $l, string $section): string {
		return match ($section) {
			'general' => $l->t('Postal code'),
			'workspace' => $l->t('Shopping space'),
			'members' => $l->t('People'),
			'stores' => $l->t('Stores'),
			'access' => $l->t('Access'),
			default => $l->t('Settings'),
		};
	}

	public function help(IL10N $l, string $section): string {
		return match ($section) {
			'general' => $l->t('Your postcode chooses the nearest Lidl Plus store. ALDI Nord offers are nationwide. These settings belong to the shopping space you are in.'),
			'workspace' => $l->t('Rename this shopping space and choose who can find it. Private means only the people you invite. Standard lets EinkaufCheck admins help when needed.'),
			'members' => $l->t('Invite people to this shopping space. Managers can change settings; contributors can edit the list; viewers can only look.'),
			'stores' => $l->t('ALDI Nord and Lidl send us their weekly lists. Other chains still need a login we do not have.'),
			'access' => $l->t('Who may open EinkaufCheck. Open means every signed-in person; Restricted means only the people and groups you pick.'),
			default => '',
		};
	}

	public function headerIcon(string $section): string {
		return match ($section) {
			'workspace' => 'shopping-cart',
			'members' => 'users',
			'stores' => 'store',
			'access' => 'users',
			default => 'settings',
		};
	}

	/**
	 * Literal template filename (no request concatenation).
	 */
	public function templatePart(string $section): string {
		if (!$this->isSection($section)) {
			$section = self::DEFAULT_SECTION;
		}
		return 'parts/settings/' . $section;
	}
}
