<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Service;

use OCA\EinkaufCheck\AppInfo\Application;
use OCA\EinkaufCheck\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException as FilesNotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class OfferFetchService {
	/**
	 * Offer snapshots are keyed by PLZ + week and shared across users on purpose:
	 * retailer prices for a postcode are public, not personal data. Abuse is bounded
	 * by per-user rate limits, a per-PLZ stampede lock, and CACHE_TTL — not by
	 * isolating cache per user (that would multiply third-party fetch load).
	 */
	private const CACHE_TTL = 1200;
	/** Concurrent force-refresh waiters coalesce onto a write fresher than this. */
	private const FORCE_COALESCE_SECONDS = 90;
	private const FETCH_TIMEOUT_SECONDS = 55;
	private const LOCK_PREFIX = 'ekc-fetch-';
	private const LOCK_WAIT_SECONDS = 55;
	private const STDERR_LOG_LIMIT = 500;
	private const STDOUT_MAX_BYTES = 8_388_608;
	private const CACHE_FOLDER = 'offer-cache';
	private const MAX_OFFERS = 2500;

	/** @var list<string> */
	public const WEEKS = ['current', 'next'];

	public function __construct(
		private readonly IConfig $config,
		private readonly ILockingProvider $locking,
		private readonly ITimeFactory $timeFactory,
		private readonly ?IAppData $appData = null,
		private readonly ?LoggerInterface $logger = null,
		private readonly ?PriceHistoryService $history = null,
	) {
	}

	/**
	 * @return array{plz: string, week: string, show_images: bool}
	 */
	public function getUserPrefs(string $userId): array {
		$plz = $this->config->getUserValue($userId, Application::APP_ID, 'plz', '24149');
		$week = $this->config->getUserValue($userId, Application::APP_ID, 'week', 'current');
		if (!preg_match('/^\d{5}$/', $plz)) {
			$plz = '24149';
		}
		if (!in_array($week, self::WEEKS, true)) {
			$week = 'current';
		}
		$show = $this->config->getUserValue($userId, Application::APP_ID, 'show_images', '0');
		return [
			'plz' => $plz,
			'week' => $week,
			'show_images' => $show === '1',
		];
	}

	/**
	 * Persist PLZ/week. $showImages null means leave the existing pictures toggle alone
	 * so Offers/Trends PUT {plz, week} cannot wipe it.
	 *
	 * @return array{plz: string, week: string, show_images: bool}
	 */
	public function saveUserPrefs(string $userId, string $plz, string $week, ?bool $showImages = null): array {
		$this->assertPlzAndWeek($plz, $week);
		$this->config->setUserValue($userId, Application::APP_ID, 'plz', $plz);
		$this->config->setUserValue($userId, Application::APP_ID, 'week', $week);
		if ($showImages !== null) {
			$this->config->setUserValue(
				$userId,
				Application::APP_ID,
				'show_images',
				$showImages ? '1' : '0',
			);
		}
		return $this->getUserPrefs($userId);
	}

	/**
	 * Return a cached payload or null. Never starts a live Python fetch
	 * and never waits on the fetch lock. GET handlers must use this.
	 *
	 * @return array<string, mixed>|null
	 */
	public function peekCache(string $plz, string $week): ?array {
		$this->assertPlzAndWeek($plz, $week);
		$hit = $this->readCache($plz, $week);
		if ($hit === null) {
			return null;
		}
		$hit['cache'] = 'hit';
		return $hit;
	}

	public function fetch(string $plz, string $week, bool $force = false): array {
		$this->assertPlzAndWeek($plz, $week);

		if (!$force) {
			$hit = $this->readCache($plz, $week);
			if ($hit !== null) {
				$hit['cache'] = 'hit';
				$this->rememberHistory($plz, $week, $hit, false);
				return $hit;
			}
		}

		$lockKey = self::LOCK_PREFIX . md5($plz . '|' . $week);
		$acquired = $this->acquireLock($lockKey);
		try {
			// Always re-check under the lock. Non-force: stampede coalesce.
			// Force: coalesce onto a write fresher than FORCE_COALESCE_SECONDS so
			// N concurrent "New prices" clicks do not each spawn Python.
			$hit = $this->readCache($plz, $week);
			if ($hit !== null) {
				$age = $this->cacheAgeSeconds($plz, $week);
				if (!$force || ($age !== null && $age < self::FORCE_COALESCE_SECONDS)) {
					$hit['cache'] = 'hit';
					$this->rememberHistory($plz, $week, $hit, false);
					return $hit;
				}
			}
			$data = $this->runFetch($plz, $week);
			if (self::isPersistableOfferPayload($data)) {
				$this->writeCache($plz, $week, $data);
				$data['cache'] = 'miss';
				$this->rememberHistory($plz, $week, $data, true);
			} else {
				// Empty/failed retailer payload must not blank the shared PLZ cache
				// for every household. Return the miss without persisting.
				$this->logger?->warning('EinkaufCheck skipped empty shared cache write', [
					'plz' => $plz,
					'week' => $week,
				]);
				$data['cache'] = 'miss';
				$data['persisted'] = false;
			}
			return $data;
		} finally {
			if ($acquired) {
				try {
					$this->locking->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
				} catch (\Throwable) {
					// Never fail the request because unlock failed.
				}
			}
		}
	}

	/**
	 * Shared cache must only store non-empty offer lists. An empty successful
	 * fetch (retailer outage / scraper noop) must not poison every user on that PLZ.
	 *
	 * @param array<string, mixed> $data
	 */
	public static function isPersistableOfferPayload(array $data): bool {
		$offers = $data['offers'] ?? null;
		return is_array($offers) && $offers !== [];
	}

	/**
	 * @return array<string, array{status: string, label: string}>
	 */
	public function storesStatus(): array {
		return [
			'aldi_nord' => ['status' => 'ok', 'label' => 'ALDI Nord — JSON-Wochenangebote'],
			'lidl' => ['status' => 'ok', 'label' => 'Lidl — Plus + Prospekt-Katalog'],
			'penny' => ['status' => 'blocked_auth', 'label' => 'Penny — Angebote hinter Login (Magnolia), Filialsuche ok'],
			'rewe' => ['status' => 'blocked_mtls', 'label' => 'REWE — Mobile-API braucht Client-Zertifikat aus der App'],
			'kaufland' => ['status' => 'blocked_auth', 'label' => 'Kaufland — App-Basic-Auth nötig'],
			'netto' => ['status' => 'blocked_auth', 'label' => 'Netto — API verlangt Session/Login'],
		];
	}

	/**
	 * Persist weekly prices for trends. History must never fail the offer fetch.
	 * Cache hits skip a rewrite when this ISO week already has a snapshot
	 * (upgrade path still records when the table is empty).
	 *
	 * @param array<string, mixed> $data
	 */
	private function rememberHistory(string $plz, string $week, array $data, bool $liveWrite): void {
		if ($this->history === null) {
			return;
		}
		try {
			if (!$liveWrite && $this->history->hasWeekSnapshot($plz, $week)) {
				return;
			}
			$offers = is_array($data['offers'] ?? null) ? $data['offers'] : [];
			$this->history->record($plz, $week, $offers);
		} catch (\Throwable $e) {
			$this->logger?->warning('EinkaufCheck price history record failed', ['exception' => $e]);
		}
	}

	public function assertPlzAndWeek(string $plz, string $week): void {
		if (!preg_match('/^\d{5}$/', $plz)) {
			throw new ValidationException(
				'Postal code must be exactly 5 digits.',
				['plz' => 'Must match ^\\d{5}$'],
				'invalid_plz',
			);
		}
		if (!in_array($week, self::WEEKS, true)) {
			throw new ValidationException(
				'Week must be current or next.',
				['week' => 'Must be current or next'],
				'invalid_week',
			);
		}
	}

	private function acquireLock(string $lockKey): bool {
		$deadline = microtime(true) + self::LOCK_WAIT_SECONDS;
		while (microtime(true) < $deadline) {
			try {
				$this->locking->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
				return true;
			} catch (LockedException) {
				usleep(100_000);
			}
		}
		throw new ValidationException(
			'Offer fetch is busy. Try again shortly.',
			[],
			'fetch_busy',
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function readCache(string $plz, string $week): ?array {
		$now = $this->timeFactory->getTime();
		$folder = $this->cacheFolder();
		if ($folder !== null) {
			try {
				$base = $plz . '_' . $week;
				if ($folder->fileExists($base . '.meta') && $folder->fileExists($base . '.json')) {
					$at = (int)trim($folder->getFile($base . '.meta')->getContent());
					if ($at > 0 && ($now - $at) < self::CACHE_TTL) {
						$data = json_decode($folder->getFile($base . '.json')->getContent(), true);
						if (is_array($data)) {
							return $this->sanitizeFetchPayload($data, $plz, $week);
						}
					}
				}
			} catch (\Throwable $e) {
				$this->logger?->debug('EinkaufCheck AppData cache read failed', ['exception' => $e]);
			}
		}

		$cacheKey = 'offers_' . $plz . '_' . $week;
		$cached = $this->config->getAppValue(Application::APP_ID, $cacheKey, '');
		$cachedAt = (int)$this->config->getAppValue(Application::APP_ID, $cacheKey . '_at', '0');
		if ($cached !== '' && ($now - $cachedAt) < self::CACHE_TTL) {
			$data = json_decode($cached, true);
			if (is_array($data)) {
				return $this->sanitizeFetchPayload($data, $plz, $week);
			}
		}
		return null;
	}

	/**
	 * Age of the shared cache entry in seconds, or null if missing/expired.
	 */
	private function cacheAgeSeconds(string $plz, string $week): ?int {
		$now = $this->timeFactory->getTime();
		$folder = $this->cacheFolder();
		if ($folder !== null) {
			try {
				$base = $plz . '_' . $week;
				if ($folder->fileExists($base . '.meta')) {
					$at = (int)trim($folder->getFile($base . '.meta')->getContent());
					if ($at > 0 && ($now - $at) < self::CACHE_TTL) {
						return max(0, $now - $at);
					}
				}
			} catch (\Throwable) {
				// Fall through to appconfig.
			}
		}
		$cacheKey = 'offers_' . $plz . '_' . $week . '_at';
		$cachedAt = (int)$this->config->getAppValue(Application::APP_ID, $cacheKey, '0');
		if ($cachedAt > 0 && ($now - $cachedAt) < self::CACHE_TTL) {
			return max(0, $now - $cachedAt);
		}
		return null;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function writeCache(string $plz, string $week, array $data): void {
		$payload = json_encode($data, JSON_UNESCAPED_UNICODE);
		if (!is_string($payload)) {
			return;
		}
		$now = (string)$this->timeFactory->getTime();
		$folder = $this->cacheFolder();
		if ($folder !== null) {
			try {
				$base = $plz . '_' . $week;
				$this->putCacheFile($folder, $base . '.json', $payload);
				$this->putCacheFile($folder, $base . '.meta', $now);
				return;
			} catch (\Throwable $e) {
				$this->logger?->debug('EinkaufCheck AppData cache write failed', ['exception' => $e]);
			}
		}
		$cacheKey = 'offers_' . $plz . '_' . $week;
		try {
			$this->config->setAppValue(Application::APP_ID, $cacheKey, $payload);
			$this->config->setAppValue(Application::APP_ID, $cacheKey . '_at', $now);
		} catch (\Throwable $e) {
			$this->logger?->debug('EinkaufCheck appconfig cache write failed', ['exception' => $e]);
		}
	}

	private function putCacheFile(ISimpleFolder $folder, string $name, string $content): void {
		if ($folder->fileExists($name)) {
			$folder->getFile($name)->putContent($content);
			return;
		}
		$folder->newFile($name, $content);
	}

	private function cacheFolder(): ?ISimpleFolder {
		if ($this->appData === null) {
			return null;
		}
		try {
			try {
				return $this->appData->getFolder(self::CACHE_FOLDER);
			} catch (FilesNotFoundException) {
				return $this->appData->newFolder(self::CACHE_FOLDER);
			}
		} catch (\Throwable $e) {
			$this->logger?->debug('EinkaufCheck AppData folder unavailable', ['exception' => $e]);
			return null;
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function runFetch(string $plz, string $week): array {
		$python = $this->resolvePython();
		$script = dirname(__DIR__, 2) . '/python/fetch_cli.py';
		if (!is_file($script)) {
			throw new ValidationException('Offer fetcher is not installed.', [], 'fetch_failed');
		}

		$cmd = [
			$python,
			$script,
			'--plz', $plz,
			'--week', $week,
		];
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$proc = proc_open($cmd, $descriptors, $pipes, dirname($script), [
			'LANG' => 'C.UTF-8',
			'LC_ALL' => 'C.UTF-8',
		]);
		if (!is_resource($proc)) {
			throw new ValidationException('Offer fetch could not be started.', [], 'fetch_failed');
		}

		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$stdout = '';
		$stderr = '';
		$timedOut = false;
		$code = -1;
		$deadline = microtime(true) + self::FETCH_TIMEOUT_SECONDS;

		try {
			while (true) {
				$status = proc_get_status($proc);
				$remaining = $deadline - microtime(true);
				if ($remaining <= 0) {
					$timedOut = true;
					break;
				}
				$read = [];
				if (is_resource($pipes[1])) {
					$read[] = $pipes[1];
				}
				if (is_resource($pipes[2])) {
					$read[] = $pipes[2];
				}
				$write = null;
				$except = null;
				if ($read !== []) {
					$sec = (int)$remaining;
					$usec = (int)max(0, ($remaining - $sec) * 1_000_000);
					@stream_select($read, $write, $except, $sec, $usec);
					foreach ($read as $stream) {
						$chunk = fread($stream, 8192);
						if (!is_string($chunk) || $chunk === '') {
							continue;
						}
						if ($stream === $pipes[1]) {
							$stdout .= $chunk;
							if (strlen($stdout) > self::STDOUT_MAX_BYTES) {
								$timedOut = true;
								break 2;
							}
						} else {
							$stderr .= $chunk;
							if (strlen($stderr) > 65_536) {
								$stderr = substr($stderr, 0, 65_536);
							}
						}
					}
				}
				if (!$status['running']) {
					if (is_resource($pipes[1])) {
						$tail = stream_get_contents($pipes[1]);
						if (is_string($tail)) {
							$stdout .= $tail;
						}
					}
					if (is_resource($pipes[2])) {
						$tail = stream_get_contents($pipes[2]);
						if (is_string($tail)) {
							$stderr .= $tail;
						}
					}
					break;
				}
			}
		} finally {
			if ($timedOut) {
				proc_terminate($proc);
				$killUntil = microtime(true) + 2;
				while (microtime(true) < $killUntil) {
					$st = proc_get_status($proc);
					if (!$st['running']) {
						break;
					}
					usleep(50_000);
				}
				$st = proc_get_status($proc);
				if (!empty($st['running'])) {
					proc_terminate($proc, 9);
				}
			}
			foreach ([1, 2] as $i) {
				if (isset($pipes[$i]) && is_resource($pipes[$i])) {
					fclose($pipes[$i]);
				}
			}
			$code = proc_close($proc);
		}

		if ($timedOut) {
			$this->logFetcherFailure('Offer fetch timed out', $stderr, $code ?? -1);
			throw new ValidationException('Offer fetch failed.', [], 'fetch_failed');
		}

		$data = json_decode($stdout, true);
		if (!is_array($data)) {
			$this->logFetcherFailure('Offer fetch returned invalid JSON', $stderr, $code ?? -1);
			throw new ValidationException('Offer fetch failed.', [], 'fetch_failed');
		}
		if (($code ?? 0) !== 0 && empty($data['offers'])) {
			$this->logFetcherFailure('Offer fetch exited non-zero', $stderr, $code ?? -1);
			throw new ValidationException('Offer fetch failed.', [], 'fetch_failed');
		}
		if (!empty($data['errors']) && is_array($data['errors'])) {
			$this->logger?->warning('EinkaufCheck fetcher source errors', [
				'count' => count($data['errors']),
				'sample' => mb_substr((string)($data['errors'][0] ?? ''), 0, self::STDERR_LOG_LIMIT),
			]);
		}
		return $this->sanitizeFetchPayload($data, $plz, $week);
	}

	private function logFetcherFailure(string $reason, string $stderr, int $code): void {
		$this->logger?->warning('EinkaufCheck {reason}', [
			'reason' => $reason,
			'code' => $code,
			'stderr' => mb_substr($stderr, 0, self::STDERR_LOG_LIMIT),
		]);
	}

	/**
	 * Strip raw fetcher stderr/exception strings and oversized fields before
	 * caching or returning to the browser.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function sanitizeFetchPayload(array $data, string $plz, string $week): array {
		$rawOffers = is_array($data['offers'] ?? null) ? $data['offers'] : [];
		$offers = [];
		foreach ($rawOffers as $row) {
			if (!is_array($row)) {
				continue;
			}
			$offers[] = $this->sanitizeOffer($row);
			if (count($offers) >= self::MAX_OFFERS) {
				break;
			}
		}

		$countsIn = is_array($data['counts'] ?? null) ? $data['counts'] : [];
		$counts = [
			'total' => count($offers),
			'aldi' => (int)($countsIn['aldi'] ?? 0),
			'lidl' => (int)($countsIn['lidl'] ?? 0),
			'food' => (int)($countsIn['food'] ?? 0),
			'produce' => (int)($countsIn['produce'] ?? 0),
			'with_per_kg' => (int)($countsIn['with_per_kg'] ?? 0),
			'with_per_l' => (int)($countsIn['with_per_l'] ?? 0),
			'with_unit_price' => 0,
			'compared' => (int)($countsIn['compared'] ?? 0),
			'compare_groups' => (int)($countsIn['compare_groups'] ?? 0),
		];
		// Prefer recount from sanitized offers when present.
		$counts['aldi'] = count(array_filter($offers, static fn (array $o): bool => ($o['store'] ?? '') === 'ALDI Nord'));
		$counts['lidl'] = count(array_filter($offers, static fn (array $o): bool => ($o['store'] ?? '') === 'Lidl'));
		$counts['food'] = count(array_filter($offers, static fn (array $o): bool => ($o['category'] ?? '') === 'food'));
		$counts['produce'] = count(array_filter($offers, static fn (array $o): bool => ($o['category'] ?? '') === 'produce'));
		$counts['with_per_kg'] = count(array_filter($offers, static fn (array $o): bool => ($o['per_kg'] ?? null) !== null));
		$counts['with_unit_price'] = count(array_filter($offers, static fn (array $o): bool => ($o['unit_price'] ?? null) !== null));
		$counts['compared'] = count(array_filter($offers, static fn (array $o): bool => (int)($o['match_stores'] ?? 1) > 1));
		$matchIds = [];
		foreach ($offers as $o) {
			if (!empty($o['match_id'])) {
				$matchIds[(string)$o['match_id']] = true;
			}
		}
		$counts['compare_groups'] = count($matchIds);

		$lidlIn = is_array($data['lidl_store'] ?? null) ? $data['lidl_store'] : [];
		$partial = !empty($data['partial'])
			|| (!empty($data['errors']) && is_array($data['errors']));

		return [
			'fetched_at' => is_string($data['fetched_at'] ?? null) ? mb_substr($data['fetched_at'], 0, 64) : '',
			'plz' => $plz,
			'week' => $week,
			'lidl_store' => [
				'name' => mb_substr((string)($lidlIn['name'] ?? ''), 0, 128),
				'address' => mb_substr((string)($lidlIn['address'] ?? ''), 0, 255),
				'postal_code' => mb_substr((string)($lidlIn['postal_code'] ?? ''), 0, 16),
				'city' => mb_substr((string)($lidlIn['city'] ?? ''), 0, 128),
			],
			'counts' => $counts,
			'partial' => $partial,
			'offers' => $offers,
		];
	}

	/**
	 * @param array<string, mixed> $offer
	 * @return array<string, mixed>
	 */
	private function sanitizeOffer(array $offer): array {
		$compare = [];
		if (is_array($offer['compare'] ?? null)) {
			foreach ($offer['compare'] as $row) {
				if (!is_array($row)) {
					continue;
				}
				$compare[] = [
					'store' => mb_substr((string)($row['store'] ?? ''), 0, 64),
					'brand' => mb_substr((string)($row['brand'] ?? ''), 0, 128),
					'name' => mb_substr((string)($row['name'] ?? ''), 0, 512),
					'pack' => mb_substr((string)($row['pack'] ?? ''), 0, 255),
					'price' => $this->finiteOrNull($row['price'] ?? null),
					'per_kg' => $this->finiteOrNull($row['per_kg'] ?? null),
					'per_l' => $this->finiteOrNull($row['per_l'] ?? null),
					'cheapest' => !empty($row['cheapest']),
				];
				if (count($compare) >= 12) {
					break;
				}
			}
		}

		$price = $this->finiteOrNull($offer['price'] ?? null);
		$perKg = $this->finiteOrNull($offer['per_kg'] ?? null);
		$perL = $this->finiteOrNull($offer['per_l'] ?? null);
		$pack = mb_substr((string)($offer['pack'] ?? ''), 0, 255);
		$unit = OfferUnitPrice::resolve(
			$price,
			$perKg,
			$perL,
			$pack,
			(string)($offer['unit_label'] ?? ''),
		);

		return [
			'store' => mb_substr((string)($offer['store'] ?? ''), 0, 64),
			'source' => mb_substr((string)($offer['source'] ?? ''), 0, 64),
			'brand' => mb_substr((string)($offer['brand'] ?? ''), 0, 128),
			'name' => mb_substr((string)($offer['name'] ?? ''), 0, 512),
			'pack' => $pack,
			'price' => $price,
			'old_price' => $this->finiteOrNull($offer['old_price'] ?? null),
			'per_kg' => $perKg,
			'per_l' => $perL,
			'unit_price' => $unit['unit_price'],
			'unit_kind' => $unit['unit_kind'],
			'unit_label' => $unit['unit_label'] !== ''
				? mb_substr($unit['unit_label'], 0, 64)
				: mb_substr((string)($offer['unit_label'] ?? ''), 0, 64),
			'valid_from' => mb_substr((string)($offer['valid_from'] ?? ''), 0, 32),
			'valid_until' => mb_substr((string)($offer['valid_until'] ?? ''), 0, 32),
			'category' => mb_substr((string)($offer['category'] ?? ''), 0, 32),
			'url' => $this->safeHttpsUrl($offer['url'] ?? ''),
			'image' => OfferImagePolicy::sanitize($offer['image'] ?? ''),
			'match_id' => mb_substr((string)($offer['match_id'] ?? ''), 0, 64),
			'match_stores' => max(0, (int)($offer['match_stores'] ?? 1)),
			'is_cheapest' => !empty($offer['is_cheapest']),
			'compare' => $compare,
		];
	}

	private function finiteOrNull(mixed $value): ?float {
		if ($value === null || $value === '') {
			return null;
		}
		if (!is_numeric($value)) {
			return null;
		}
		$n = (float)$value;
		if (!is_finite($n) || $n < 0 || $n > 99999.99) {
			return null;
		}
		return round($n, 2);
	}

	private function safeHttpsUrl(mixed $url): string {
		$u = trim((string)$url);
		if ($u === '' || strlen($u) > 2000) {
			return '';
		}
		if (preg_match('/[\x00-\x1f\x7f]/', $u) === 1) {
			return '';
		}
		$parts = parse_url($u);
		if (!is_array($parts)) {
			return '';
		}
		if (strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
			return '';
		}
		if (isset($parts['user']) || isset($parts['pass'])) {
			return '';
		}
		$host = strtolower((string)($parts['host'] ?? ''));
		if ($host === '' || str_contains($host, '%') || str_contains($u, '\\')) {
			return '';
		}
		if (!preg_match('~^https://[a-z0-9.-]+(?::[1-9][0-9]{0,4})?(?:[/?#].*)?$~i', $u)) {
			return '';
		}
		return $u;
	}

	private function resolvePython(): string {
		foreach (['/usr/bin/python3', '/usr/local/bin/python3'] as $bin) {
			if (is_executable($bin)) {
				return $bin;
			}
		}
		throw new ValidationException('python3 is not available on the server.', [], 'fetch_failed');
	}
}
