<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Repair;

use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\Server;

/**
 * Safety net when migrations were marked complete without creating every table.
 */
class EnsureEinkaufCheckSchema implements IRepairStep {
	/** @var list<string> */
	public const TABLES = ['einkaufcheck_items', 'einkaufcheck_watch', 'einkaufcheck_price_hist'];

	public function __construct(
		private readonly IDBConnection $connection,
	) {
	}

	public function getName(): string {
		return 'Ensure EinkaufCheck database tables exist';
	}

	public function run(IOutput $output): void {
		$inner = Server::get(Connection::class);
		$schema = new SchemaWrapper($inner);
		$changed = false;

		if (!$schema->hasTable('einkaufcheck_items')) {
			$this->createItemsTable($schema);
			$changed = true;
			$output->info('EinkaufCheck: created table einkaufcheck_items.');
		}
		if (!$schema->hasTable('einkaufcheck_watch')) {
			$this->createWatchTable($schema);
			$changed = true;
			$output->info('EinkaufCheck: created table einkaufcheck_watch.');
		}
		if (!$schema->hasTable('einkaufcheck_price_hist')) {
			$this->createHistoryTable($schema);
			$changed = true;
			$output->info('EinkaufCheck: created table einkaufcheck_price_hist.');
		}

		if ($changed) {
			$this->connection->migrateToSchema($schema->getWrappedSchema());
			$output->info('EinkaufCheck: schema repair applied.');
			return;
		}

		$output->info('EinkaufCheck: schema is complete.');
	}

	private function createItemsTable(SchemaWrapper $schema): void {
		$table = $schema->createTable('einkaufcheck_items');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('store', Types::STRING, [
			'notnull' => true,
			'length' => 64,
			'default' => '',
		]);
		$table->addColumn('brand', Types::STRING, [
			'notnull' => true,
			'length' => 128,
			'default' => '',
		]);
		$table->addColumn('name', Types::STRING, [
			'notnull' => true,
			'length' => 512,
		]);
		$table->addColumn('pack', Types::STRING, [
			'notnull' => true,
			'length' => 255,
			'default' => '',
		]);
		$table->addColumn('price', Types::DECIMAL, [
			'notnull' => false,
			'precision' => 10,
			'scale' => 2,
		]);
		$table->addColumn('per_kg', Types::DECIMAL, [
			'notnull' => false,
			'precision' => 10,
			'scale' => 2,
		]);
		$table->addColumn('qty', Types::INTEGER, [
			'notnull' => true,
			'default' => 1,
		]);
		$table->addColumn('note', Types::STRING, [
			'notnull' => true,
			'length' => 255,
			'default' => '',
		]);
		$table->addColumn('checked', Types::SMALLINT, [
			'notnull' => true,
			'default' => 0,
		]);
		$table->addColumn('created_at', Types::BIGINT, [
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['user_id'], 'einkaufcheck_uid');
	}

	private function createWatchTable(SchemaWrapper $schema): void {
		$table = $schema->createTable('einkaufcheck_watch');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('query', Types::STRING, [
			'notnull' => true,
			'length' => 512,
		]);
		$table->addColumn('brand', Types::STRING, [
			'notnull' => true,
			'length' => 128,
			'default' => '',
		]);
		$table->addColumn('store', Types::STRING, [
			'notnull' => true,
			'length' => 64,
			'default' => '',
		]);
		$table->addColumn('max_price', Types::DECIMAL, [
			'notnull' => false,
			'precision' => 10,
			'scale' => 2,
		]);
		$table->addColumn('max_per_kg', Types::DECIMAL, [
			'notnull' => false,
			'precision' => 10,
			'scale' => 2,
		]);
		$table->addColumn('enabled', Types::SMALLINT, [
			'notnull' => true,
			'default' => 1,
		]);
		$table->addColumn('last_hit_key', Types::STRING, [
			'notnull' => true,
			'length' => 64,
			'default' => '',
		]);
		$table->addColumn('created_at', Types::BIGINT, [
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->setPrimaryKey(['id']);
		$table->addIndex(['user_id'], 'einkaufcheck_watch_uid');
	}

	private function createHistoryTable(SchemaWrapper $schema): void {
		$table = $schema->createTable('einkaufcheck_price_hist');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('sku_key', Types::STRING, [
			'notnull' => true,
			'length' => 40,
		]);
		$table->addColumn('store', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('brand', Types::STRING, [
			'notnull' => true,
			'length' => 128,
			'default' => '',
		]);
		$table->addColumn('name', Types::STRING, [
			'notnull' => true,
			'length' => 512,
		]);
		$table->addColumn('pack', Types::STRING, [
			'notnull' => true,
			'length' => 255,
			'default' => '',
		]);
		$table->addColumn('plz', Types::STRING, [
			'notnull' => true,
			'length' => 5,
		]);
		$table->addColumn('week_start', Types::STRING, [
			'notnull' => true,
			'length' => 10,
		]);
		$table->addColumn('price', Types::DECIMAL, [
			'notnull' => false,
			'precision' => 10,
			'scale' => 2,
		]);
		$table->addColumn('per_kg', Types::DECIMAL, [
			'notnull' => false,
			'precision' => 10,
			'scale' => 2,
		]);
		$table->addColumn('recorded_at', Types::BIGINT, [
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['sku_key', 'week_start'], 'ekc_hist_sku_week');
		$table->addIndex(['plz', 'store', 'week_start'], 'ekc_hist_plz_store_week');
	}
}
