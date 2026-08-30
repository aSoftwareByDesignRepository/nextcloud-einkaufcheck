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

class Version1100Date20260830140000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('einkaufcheck_watch')) {
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

		return $schema;
	}
}
