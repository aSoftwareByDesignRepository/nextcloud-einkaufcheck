<?php

declare(strict_types=1);

/**
 * In-place mutation gate for EinkaufCheck watch matching and bool coercion.
 * Mutants MUST make the targeted PHPUnit filter fail. Source is always restored.
 */

$root = dirname(__DIR__, 2);
$phpunitCandidates = [
	'/var/www/html/custom_apps/budgetcheck/vendor/bin/phpunit',
	'/var/www/html/custom_apps/snackcheck/vendor/bin/phpunit',
	$root . '/../budgetcheck/vendor/bin/phpunit',
	$root . '/../snackcheck/vendor/bin/phpunit',
];
$phpunit = null;
foreach ($phpunitCandidates as $candidate) {
	if (is_file($candidate)) {
		$phpunit = $candidate;
		break;
	}
}
if ($phpunit === null) {
	fwrite(STDERR, "phpunit not found\n");
	exit(1);
}

$fail = 0;

function say(string $msg): void {
	echo $msg . "\n";
}

function fail(string $msg): void {
	global $fail;
	say('FAIL: ' . $msg);
	$fail++;
}

function ok(string $msg): void {
	say('OK: ' . $msg);
}

/**
 * @return list<array{name: string, file: string, search: string, replace: string, filter: string}>
 */
function mutants(string $root): array {
	$match = $root . '/lib/Service/WatchMatchService.php';
	$bool = $root . '/lib/Service/InputCoercion.php';
	$list = $root . '/lib/Service/ShoppingListService.php';
	$img = $root . '/lib/Service/OfferImagePolicy.php';
	$unit = $root . '/lib/Service/OfferUnitPrice.php';
	$accessEx = $root . '/lib/Exception/AppAccessDeniedException.php';
	$fetch = $root . '/lib/Service/OfferFetchService.php';
	return [
		[
			'name' => 'infix-str-contains-restored',
			'file' => $match,
			'search' => 'if (mb_strlen($qn) >= 4 && $this->isWordBounded($hay, $qn)) {
			return true;
		}',
			'replace' => 'if ($qn !== \'\' && str_contains($hay, $qn)) {
			return true;
		}',
			'filter' => 'WatchMatchServiceTest::testEisMustNotMatchReis',
		],
		[
			'name' => 'jaccard-half-restored',
			'file' => $match,
			'search' => '		return false;
	}

	/**
	 * @param list<array<string, mixed>> $watches',
			'replace' => '		$qSet = array_fill_keys($qt, true);
		$oSet = array_fill_keys($ot, true);
		$inter = 0;
		foreach ($qSet as $t => $_) {
			if (isset($oSet[$t])) {
				$inter++;
			}
		}
		$union = count($qSet) + count($oSet) - $inter;
		return $union > 0 && ($inter / $union) >= 0.5;
	}

	/**
	 * @param list<array<string, mixed>> $watches',
			'filter' => 'WatchMatchServiceTest::testTwoTokenQueryDoesNotMatchOneTokenOffer',
		],
		[
			'name' => 'hits-cap-removed',
			'file' => $match,
			'search' => 'if ($perWatch >= self::MAX_HITS_PER_WATCH) {
					break;
				}',
			'replace' => 'if (false && $perWatch >= self::MAX_HITS_PER_WATCH) {
					break;
				}',
			'filter' => 'WatchMatchServiceTest::testHitsCapPerWatch',
		],
		[
			'name' => 'false-string-treated-as-true',
			'file' => $bool,
			'search' => "if (in_array(\$normalized, ['0', 'false', 'no', 'off', ''], true)) {
				return false;
			}",
			'replace' => "if (in_array(\$normalized, ['0', 'no', 'off', ''], true)) {
				return false;
			}",
			'filter' => 'InputCoercionTest::testFalseStringIsFalse',
		],
		[
			'name' => 'list-empty-checked-trap',
			'file' => $list,
			'search' => "(InputCoercion::asBool(\$payload['checked'], 'checked') ? 1 : 0)",
			'replace' => "(!empty(\$payload['checked']) ? 1 : 0)",
			'filter' => 'OwnershipAndValidationTest::testCheckedFalseStringUnchecksItem',
		],
		[
			'name' => 'image-host-suffix-without-dot',
			'file' => $img,
			'search' => "if (\$host === \$suffix || str_ends_with(\$host, '.' . \$suffix)) {
				return true;
			}",
			'replace' => "if (\$host === \$suffix || str_ends_with(\$host, \$suffix)) {
				return true;
			}",
			'filter' => 'OfferImagePolicyTest::testLookalikeHostIsRejected',
		],
		[
			'name' => 'negative-per-kg-accepted',
			'file' => $unit,
			'search' => 'if ($perKg !== null && self::isUsableAmount($perKg)) {',
			'replace' => 'if ($perKg !== null) {',
			'filter' => 'OfferUnitPriceTest::testRejectsNegativeStorePerKg',
		],
		[
			'name' => 'admin-required-code-collapsed',
			'file' => $accessEx,
			'search' => "return \$this->reason === 'admin_required' ? 'admin_required' : 'app_access_denied';",
			'replace' => "return 'app_access_denied';",
			'filter' => 'AppAccessMiddlewareStatusTest::testAdminRequiredIsDistinctFromAppDoorDenial',
		],
		[
			'name' => 'noise-solo-query-accepted',
			'file' => $match,
			'search' => '		// Whole-query noise ("bio", "plus", "frisch") must never match alone —
		// those tokens appear on nearly every Bio/Aktion shelf label.
		if (isset(self::NOISE[$qn])) {
			return false;
		}

		// Whole-query, word-bounded. Infix is forbidden: "eis" must not hit "Reis".',
			'replace' => '		// Whole-query, word-bounded. Infix is forbidden: "eis" must not hit "Reis".',
			'filter' => 'WatchMatchServiceTest::testSoloBioNoiseNeverMatches',
		],
		[
			'name' => 'force-coalesce-disabled',
			'file' => $fetch,
			'search' => 'if (!$force || ($age !== null && $age < self::FORCE_COALESCE_SECONDS)) {',
			'replace' => 'if (!$force) {',
			'filter' => 'OfferFetchPeekCacheTest::testForceRefreshCoalescesOntoFreshCache',
		],
		[
			'name' => 'empty-cache-poison-allowed',
			'file' => $fetch,
			'search' => 'return is_array($offers) && $offers !== [];',
			'replace' => 'return is_array($offers);',
			'filter' => 'OfferFetchPeekCacheTest::testIsPersistableRejectsEmptyOffers',
		],
		[
			'name' => 'personal-lock-key-overflow',
			'file' => $root . '/lib/Service/WorkspaceService.php',
			'search' => "private const LOCK_PERSONAL_PREFIX = 'ekc-pw-';",
			'replace' => "private const LOCK_PERSONAL_PREFIX = 'einkaufcheck/personal/overflow-';",
			'filter' => 'WorkspaceLockKeyLengthTest::testPersonalAndCreateLockKeysFitFileLocksColumn',
		],
		[
			'name' => 'list-service-acl-stripped',
			'file' => $list,
			'search' => "public function list(int \$workspaceId, string \$actorUserId): array {\n\t\t\$this->access->ensureMinimumRole(\$workspaceId, \$actorUserId, AccessControlService::ROLE_VIEWER);\n\t\treturn \$this->listRows(\$workspaceId);\n\t}",
			'replace' => "public function list(int \$workspaceId, string \$actorUserId): array {\n\t\treturn \$this->listRows(\$workspaceId);\n\t}",
			'filter' => 'PrivateWorkspaceAclAttackTest::testAppAdminCannotListItemsInForeignPrivateSpaceViaServiceIdGuess',
		],
		[
			'name' => 'list-update-unlocked',
			'file' => $list,
			'search' => "public function update(int \$workspaceId, string \$actorUserId, int \$id, array \$payload): array {\n\t\t\$this->access->ensureMinimumRole(\$workspaceId, \$actorUserId, AccessControlService::ROLE_CONTRIBUTOR);\n\t\treturn \$this->withWorkspaceLock(\$workspaceId, function () use (\$workspaceId, \$id, \$payload): array {",
			'replace' => "public function update(int \$workspaceId, string \$actorUserId, int \$id, array \$payload): array {\n\t\t\$this->access->ensureMinimumRole(\$workspaceId, \$actorUserId, AccessControlService::ROLE_CONTRIBUTOR);\n\t\treturn (function () use (\$workspaceId, \$id, \$payload): array {",
			'filter' => 'ListWatchWriteLockContractTest::testShoppingListMutationsUseWorkspaceLock',
		],
		[
			'name' => 'contributor-refresh-plz-unbound',
			'file' => $root . '/lib/Controller/ApiController.php',
			'search' => "\t\t\$plz = \$prefs['plz'];\n\t\t\$week = \$prefs['week'];\n\t\ttry {\n\t\t\t\$this->accessControl->ensureMinimumRole(\$wsId, \$uid, AccessControlService::ROLE_MANAGER);\n\t\t\t\$plz = \$requestedPlz;\n\t\t\t\$week = \$requestedWeek;\n\t\t\t\$this->workspaces->savePrefs(\$wsId, \$uid, \$plz, \$week);\n\t\t} catch (AccessDeniedException) {\n\t\t\t// contributor: bound to saved prefs\n\t\t}",
			'replace' => "\t\t\$plz = \$requestedPlz;\n\t\t\$week = \$requestedWeek;\n\t\ttry {\n\t\t\t\$this->accessControl->ensureMinimumRole(\$wsId, \$uid, AccessControlService::ROLE_MANAGER);\n\t\t\t\$this->workspaces->savePrefs(\$wsId, \$uid, \$plz, \$week);\n\t\t} catch (AccessDeniedException) {\n\t\t\t// contributor still fetches requested plz (INSECURE mutant)\n\t\t}",
			'filter' => 'ApiOffersGetMustNotMutatePrefsTest::testContributorRefreshIgnoresRequestedPlzAndDoesNotSavePrefs',
		],
	];
}

/**
 * @param array{name: string, file: string, search: string, replace: string, filter: string} $m
 */
function runMutant(array $m, string $phpunit, string $root): bool {
	$original = file_get_contents($m['file']);
	if ($original === false) {
		fail('cannot read ' . $m['file']);
		return false;
	}
	if (!str_contains($original, $m['search'])) {
		fail('mutation search string not found for ' . $m['name']);
		return false;
	}
	$mutated = str_replace($m['search'], $m['replace'], $original, $count);
	if ($count !== 1) {
		fail('expected 1 replacement for ' . $m['name'] . ', got ' . $count);
		return false;
	}

	if (!is_writable($m['file'])) {
		fail('cannot write mutant for ' . $m['name'] . ' (file not writable; run via scripts/run-mutations.sh as root in Docker)');
		return false;
	}

	$backup = sys_get_temp_dir() . '/ekc-mut-' . md5($m['file']) . '.bak';
	if (file_put_contents($backup, $original) === false) {
		fail('cannot write backup for ' . $m['name']);
		return false;
	}
	if (file_put_contents($m['file'], $mutated) === false) {
		@unlink($backup);
		fail('cannot write mutant for ' . $m['name']);
		return false;
	}

	$cmd = escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=0 ' . escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($root . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($m['filter'])
		. ' 2>&1';
	exec($cmd, $out, $code);

	$restored = file_put_contents($m['file'], $original);
	@unlink($backup);
	if ($restored === false) {
		fail('CRITICAL: failed to restore ' . $m['file'] . ' after ' . $m['name']);
		return false;
	}

	if ($code === 0) {
		fail('SURVIVED: ' . $m['name'] . ' (phpunit exit 0)');
		say(implode("\n", array_slice($out, -20)));
		return false;
	}
	ok('killed: ' . $m['name'] . ' (phpunit exit ' . $code . ')');
	return true;
}

$libBlob = '';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/lib'));
foreach ($it as $f) {
	if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
		$libBlob .= (string)file_get_contents($f->getPathname());
	}
}
foreach ([
	'shell_exec(' => 'no shell_exec',
	'passthru(' => 'no passthru',
	'eval(' => 'no eval',
] as $needle => $label) {
	if (str_contains($libBlob, $needle)) {
		fail($label);
	} else {
		ok($label);
	}
}

if (str_contains($libBlob, '!empty($payload[\'checked\'])')) {
	fail('ShoppingList still uses empty() on checked');
} else {
	ok('checked uses InputCoercion');
}
if (str_contains($libBlob, '!empty($payload[\'enabled\'])')) {
	fail('Watch still uses empty() on enabled');
} else {
	ok('enabled uses InputCoercion');
}
if (str_contains($libBlob, '!empty($body[\'show_images\'])') || str_contains($libBlob, '!empty($payload[\'show_images\'])')) {
	fail('show_images still uses empty()');
} else {
	ok('show_images uses InputCoercion');
}

foreach (mutants($root) as $m) {
	runMutant($m, $phpunit, $root);
}

exit($fail > 0 ? 1 : 0);
