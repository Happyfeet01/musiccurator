<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** @psalm-suppress UnusedClass Loaded by Nextcloud's migration discovery. */
final class Version0202Date20261109120000 extends SimpleMigrationStep {
	/** @psalm-suppress UndefinedDocblockClass Doctrine schema classes are supplied by Nextcloud, absent from the OCP SDK. */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$tracks = $schema->getTable('musiccurator_tracks');
		if (!$tracks->hasColumn('review_status')) {
			$tracks->addColumn('review_status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']);
		}
		if (!$tracks->hasColumn('review_updated')) {
			$tracks->addColumn('review_updated', Types::BIGINT, ['notnull' => true, 'default' => 0]);
		}
		if (!$schema->hasTable('musiccurator_review')) {
			$table = $schema->createTable('musiccurator_review');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('action', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['user_id', 'file_id', 'id'], 'mcurator_review_file');
		}
		return $schema;
	}
}
