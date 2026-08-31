<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Migration;

use Closure;
use OCA\EinkaufCheck\AppInfo\Application;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Private shopping workspaces: ws / members / groups + workspace_id on items & watches.
 */
class Version1300Date20260830210000 extends SimpleMigrationStep {
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IConfig $config,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('einkaufcheck_ws')) {
			$t = $schema->createTable('einkaufcheck_ws');
			$t->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$t->addColumn('name', Types::STRING, [
				'notnull' => true,
				'length' => 128,
			]);
			$t->addColumn('privacy_mode', Types::STRING, [
				'notnull' => true,
				'length' => 16,
				'default' => 'private',
			]);
			$t->addColumn('created_by', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$t->addColumn('plz', Types::STRING, [
				'notnull' => true,
				'length' => 5,
				'default' => '24149',
			]);
			$t->addColumn('week', Types::STRING, [
				'notnull' => true,
				'length' => 16,
				'default' => 'current',
			]);
			$t->addColumn('show_images', Types::SMALLINT, [
				'notnull' => true,
				'default' => 0,
			]);
			$t->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$t->addColumn('updated_at', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$t->setPrimaryKey(['id'], 'ekc_ws_pk');
			$t->addIndex(['created_by'], 'ekc_ws_created_by');
			$t->addIndex(['privacy_mode'], 'ekc_ws_privacy');
		}

		if (!$schema->hasTable('einkaufcheck_ws_mem')) {
			$t = $schema->createTable('einkaufcheck_ws_mem');
			$t->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$t->addColumn('workspace_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$t->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$t->addColumn('role', Types::STRING, [
				'notnull' => true,
				'length' => 16,
				'default' => 'viewer',
			]);
			$t->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$t->setPrimaryKey(['id'], 'ekc_wsm_pk');
			$t->addUniqueIndex(['workspace_id', 'user_id'], 'ekc_ws_mem_uidx');
			$t->addIndex(['user_id'], 'ekc_ws_mem_user');
		}

		if (!$schema->hasTable('einkaufcheck_ws_grp')) {
			$t = $schema->createTable('einkaufcheck_ws_grp');
			$t->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$t->addColumn('workspace_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$t->addColumn('gid', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$t->addColumn('role', Types::STRING, [
				'notnull' => true,
				'length' => 16,
				'default' => 'viewer',
			]);
			$t->addColumn('created_at', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$t->setPrimaryKey(['id'], 'ekc_wsg_pk');
			$t->addUniqueIndex(['workspace_id', 'gid'], 'ekc_ws_grp_uidx');
			$t->addIndex(['gid'], 'ekc_ws_grp_gid');
		}

		if ($schema->hasTable('einkaufcheck_items')) {
			$items = $schema->getTable('einkaufcheck_items');
			if (!$items->hasColumn('workspace_id')) {
				$items->addColumn('workspace_id', Types::BIGINT, [
					'notnull' => false,
					'unsigned' => true,
				]);
				$items->addIndex(['workspace_id'], 'ekc_items_ws');
			}
		}

		if ($schema->hasTable('einkaufcheck_watch')) {
			$watch = $schema->getTable('einkaufcheck_watch');
			if (!$watch->hasColumn('workspace_id')) {
				$watch->addColumn('workspace_id', Types::BIGINT, [
					'notnull' => false,
					'unsigned' => true,
				]);
				$watch->addIndex(['workspace_id'], 'ekc_watch_ws');
			}
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$uids = $this->collectUserIds();
		$now = time();
		foreach ($uids as $uid) {
			$this->provisionPrivateWorkspace($uid, $now);
		}

		$orphanId = $this->ensureOrphanWorkspace($now);
		$this->assignRemainingNullRows($orphanId);

		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;
		foreach (['einkaufcheck_items', 'einkaufcheck_watch'] as $tableName) {
			if (!$schema->hasTable($tableName)) {
				continue;
			}
			$table = $schema->getTable($tableName);
			if ($table->hasColumn('workspace_id') && !$table->getColumn('workspace_id')->getNotnull()) {
				$table->getColumn('workspace_id')->setNotnull(true);
				$changed = true;
			}
		}
		if ($changed) {
			$this->db->migrateToSchema($schema->getWrappedSchema());
			$output->info('EinkaufCheck: workspace_id set NOT NULL after backfill.');
		}
		$output->info('EinkaufCheck: provisioned ' . count($uids) . ' private shopping workspace(s).');
	}

	/**
	 * @return list<string>
	 */
	private function collectUserIds(): array {
		$uids = [];
		foreach (['einkaufcheck_items', 'einkaufcheck_watch'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->selectDistinct('user_id')->from($table);
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$uid = (string)($row['user_id'] ?? '');
				if ($uid !== '') {
					$uids[$uid] = true;
				}
			}
			$result->closeCursor();
		}
		return array_keys($uids);
	}

	private function provisionPrivateWorkspace(string $uid, int $now): void {
		$mq = $this->db->getQueryBuilder();
		$mq->select('workspace_id')
			->from('einkaufcheck_ws_mem')
			->where($mq->expr()->eq('user_id', $mq->createNamedParameter($uid)))
			->setMaxResults(1);
		$mRes = $mq->executeQuery();
		$mRow = $mRes->fetch();
		$mRes->closeCursor();
		if ($mRow !== false) {
			$this->attachRows($uid, (int)$mRow['workspace_id']);
			return;
		}

		$prefs = $this->readLegacyPrefs($uid);
		$this->db->beginTransaction();
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('einkaufcheck_ws')
				->values([
					'name' => $qb->createNamedParameter('My shopping list'),
					'privacy_mode' => $qb->createNamedParameter('private'),
					'created_by' => $qb->createNamedParameter($uid),
					'plz' => $qb->createNamedParameter($prefs['plz']),
					'week' => $qb->createNamedParameter($prefs['week']),
					'show_images' => $qb->createNamedParameter($prefs['show_images'], IQueryBuilder::PARAM_INT),
					'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
					'updated_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				])
				->executeStatement();
			$wsId = (int)$this->db->lastInsertId('einkaufcheck_ws');

			$mqb = $this->db->getQueryBuilder();
			$mqb->insert('einkaufcheck_ws_mem')
				->values([
					'workspace_id' => $mqb->createNamedParameter($wsId, IQueryBuilder::PARAM_INT),
					'user_id' => $mqb->createNamedParameter($uid),
					'role' => $mqb->createNamedParameter('manager'),
					'created_at' => $mqb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				])
				->executeStatement();

			$this->attachRows($uid, $wsId);
			$this->db->commit();
		} catch (\Throwable $e) {
			if ($this->db->inTransaction()) {
				$this->db->rollBack();
			}
			throw $e;
		}
	}

	private function attachRows(string $uid, int $wsId): void {
		foreach (['einkaufcheck_items', 'einkaufcheck_watch'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($table)
				->set('workspace_id', $qb->createNamedParameter($wsId, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
				->andWhere($qb->expr()->isNull('workspace_id'))
				->executeStatement();
		}
	}

	/**
	 * @return array{plz: string, week: string, show_images: int}
	 */
	private function readLegacyPrefs(string $uid): array {
		$plz = (string)$this->config->getUserValue($uid, Application::APP_ID, 'plz', '24149');
		if (!preg_match('/^\d{5}$/', $plz)) {
			$plz = '24149';
		}
		$week = (string)$this->config->getUserValue($uid, Application::APP_ID, 'week', 'current');
		if (!in_array($week, ['current', 'next'], true)) {
			$week = 'current';
		}
		$show = $this->config->getUserValue($uid, Application::APP_ID, 'show_images', '0') === '1' ? 1 : 0;
		return ['plz' => $plz, 'week' => $week, 'show_images' => $show];
	}

	private function ensureOrphanWorkspace(int $now): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('einkaufcheck_ws')
			->where($qb->expr()->eq('created_by', $qb->createNamedParameter('__system__')))
			->andWhere($qb->expr()->eq('name', $qb->createNamedParameter('Orphan shopping data')))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		if ($row !== false) {
			return (int)$row['id'];
		}
		$ins = $this->db->getQueryBuilder();
		$ins->insert('einkaufcheck_ws')
			->values([
				'name' => $ins->createNamedParameter('Orphan shopping data'),
				'privacy_mode' => $ins->createNamedParameter('private'),
				'created_by' => $ins->createNamedParameter('__system__'),
				'plz' => $ins->createNamedParameter('24149'),
				'week' => $ins->createNamedParameter('current'),
				'show_images' => $ins->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'created_at' => $ins->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				'updated_at' => $ins->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			])
			->executeStatement();
		return (int)$this->db->lastInsertId('einkaufcheck_ws');
	}

	private function assignRemainingNullRows(int $orphanId): void {
		foreach (['einkaufcheck_items', 'einkaufcheck_watch'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($table)
				->set('workspace_id', $qb->createNamedParameter($orphanId, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->isNull('workspace_id'))
				->executeStatement();
		}
	}
}
