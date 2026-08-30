<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\EinkaufCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1200Date20260830180000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('einkaufcheck_price_hist')) {
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

		return $schema;
	}
}
