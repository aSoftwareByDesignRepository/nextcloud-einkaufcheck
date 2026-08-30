<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

use OCA\EinkaufCheck\Service\SettingsSectionCatalog;

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'page#trends', 'url' => '/trends', 'verb' => 'GET'],
		['name' => 'page#settingsIndex', 'url' => '/settings', 'verb' => 'GET'],
		[
			'name' => 'page#settings',
			'url' => '/settings/{section}',
			'verb' => 'GET',
			'requirements' => ['section' => SettingsSectionCatalog::routeRequirement()],
		],

		['name' => 'api#offers', 'url' => '/api/offers', 'verb' => 'GET'],
		['name' => 'api#offersRefresh', 'url' => '/api/offers/refresh', 'verb' => 'POST'],
		['name' => 'api#storesStatus', 'url' => '/api/stores-status', 'verb' => 'GET'],
		['name' => 'api#settingsGet', 'url' => '/api/settings', 'verb' => 'GET'],
		['name' => 'api#settingsSave', 'url' => '/api/settings', 'verb' => 'PUT'],

		['name' => 'api#listGet', 'url' => '/api/list', 'verb' => 'GET'],
		['name' => 'api#listAdd', 'url' => '/api/list', 'verb' => 'POST'],
		['name' => 'api#listUpdate', 'url' => '/api/list/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\\d+']],
		['name' => 'api#listDelete', 'url' => '/api/list/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\\d+']],
		['name' => 'api#listClear', 'url' => '/api/list', 'verb' => 'DELETE'],
		['name' => 'api#listExport', 'url' => '/api/list/export', 'verb' => 'GET'],

		['name' => 'api#watchGet', 'url' => '/api/watch', 'verb' => 'GET'],
		['name' => 'api#watchHits', 'url' => '/api/watch/hits', 'verb' => 'GET'],
		['name' => 'api#trends', 'url' => '/api/trends', 'verb' => 'GET'],
		['name' => 'api#accessGet', 'url' => '/api/access', 'verb' => 'GET'],
		['name' => 'api#accessSave', 'url' => '/api/access', 'verb' => 'PUT'],
		['name' => 'api#directoryUsers', 'url' => '/api/directory/users', 'verb' => 'GET'],
		['name' => 'api#directoryGroups', 'url' => '/api/directory/groups', 'verb' => 'GET'],
		['name' => 'api#watchAdd', 'url' => '/api/watch', 'verb' => 'POST'],
		['name' => 'api#watchUpdate', 'url' => '/api/watch/{id}', 'verb' => 'PUT', 'requirements' => ['id' => '\\d+']],
		['name' => 'api#watchDelete', 'url' => '/api/watch/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\\d+']],
	],
];
