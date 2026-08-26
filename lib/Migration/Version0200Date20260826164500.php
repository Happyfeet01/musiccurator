<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\Attributes\CreateTable;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

#[CreateTable(
	table: 'musiccurator_tracks',
	description: 'Persistent per-user scan index for MusicCurator audio files and accepted metadata matches',
)]
class Version0200Date20260826164500 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('musiccurator_tracks')) {
			return null;
		}

		$table = $schema->createTable('musiccurator_tracks');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
		]);
		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('file_id', Types::BIGINT, [
			'notnull' => true,
		]);
		$table->addColumn('path', Types::STRING, [
			'notnull' => true,
			'length' => 1024,
		]);
		$table->addColumn('etag', Types::STRING, [
			'notnull' => true,
			'length' => 64,
			'default' => '',
		]);
		$table->addColumn('size', Types::BIGINT, [
			'notnull' => true,
			'default' => 0,
		]);
		$table->addColumn('mtime', Types::BIGINT, [
			'notnull' => true,
			'default' => 0,
		]);
		$table->addColumn('scanned_at', Types::BIGINT, [
			'notnull' => true,
			'default' => 0,
		]);
		$table->addColumn('metadata', Types::TEXT, [
			'notnull' => true,
		]);
		$table->addColumn('mb_recording_id', Types::STRING, [
			'notnull' => true,
			'length' => 36,
			'default' => '',
		]);
		$table->addColumn('mb_release_id', Types::STRING, [
			'notnull' => true,
			'length' => 36,
			'default' => '',
		]);
		$table->addColumn('mb_score', Types::INTEGER, [
			'notnull' => true,
			'default' => 0,
		]);
		$table->addColumn('matched_at', Types::BIGINT, [
			'notnull' => true,
			'default' => 0,
		]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['user_id', 'file_id'], 'mcurator_user_file');
		$table->addIndex(['user_id', 'scanned_at'], 'mcurator_user_scan');

		return $schema;
	}
}
