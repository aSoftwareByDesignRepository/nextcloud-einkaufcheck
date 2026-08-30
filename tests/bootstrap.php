<?php

declare(strict_types=1);

if (!defined('PHPUNIT_RUN')) {
	define('PHPUNIT_RUN', 1);
}

$candidates = [
	__DIR__ . '/../../../lib/base.php',
	'/var/www/html/lib/base.php',
];
$base = null;
foreach ($candidates as $candidate) {
	if (is_file($candidate)) {
		$base = $candidate;
		break;
	}
}
if ($base !== null) {
	require_once $base;
}

spl_autoload_register(static function (string $class): void {
	$map = [
		'OCA\\EinkaufCheck\\Tests\\' => __DIR__ . '/',
		'OCA\\EinkaufCheck\\' => __DIR__ . '/../lib/',
	];
	foreach ($map as $prefix => $dir) {
		if (!str_starts_with($class, $prefix)) {
			continue;
		}
		$file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) {
			require_once $file;
		}
	}
});
