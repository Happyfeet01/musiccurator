<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Tests\Unit;

use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Schema;
use OCA\MusicCurator\Migration\Version0201Date20260826164500;
use OCA\MusicCurator\Migration\Version0202Date20261109120000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PDO;
use PHPUnit\Framework\TestCase;

final class ReviewMigrationTest extends TestCase {
	public function testUpgradeKeepsIndexedTracksAndAddsPendingStatusAndHistoryTable(): void {
		$schema = new Schema();
		$wrapper = $this->createMock(ISchemaWrapper::class);
		$wrapper->method('hasTable')->willReturnCallback(static fn ($name) => $schema->hasTable($name));
		$wrapper->method('getTable')->willReturnCallback(static fn ($name) => $schema->getTable($name));
		$wrapper->method('createTable')->willReturnCallback(static fn ($name) => $schema->createTable($name));
		$output = $this->createMock(IOutput::class);
		$closure = static fn () => $wrapper;
		(new Version0201Date20260826164500())->changeSchema($output, $closure, []);
		$platform = new SQLitePlatform();
		$db = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		foreach ($schema->toSql($platform) as $sql) {
			$db->exec($sql);
		}
		$db->exec("INSERT INTO musiccurator_tracks (user_id, file_id, path, metadata) VALUES ('alice', 1, '/Music/a.mp3', '{}')");
		$before = clone $schema;
		$migration = new Version0202Date20261109120000();
		$migration->changeSchema($output, $closure, []);
		$diff = (new Comparator($platform))->compareSchemas($before, $schema);
		foreach ($platform->getAlterSchemaSQL($diff) as $sql) {
			$db->exec($sql);
		}
		$row = $db->query('SELECT * FROM musiccurator_tracks')->fetch(PDO::FETCH_ASSOC);
		self::assertSame('/Music/a.mp3', $row['path']);
		self::assertSame('pending', $row['review_status']);
		self::assertSame(0, $row['review_updated']);
		self::assertSame(0, (int)$db->query('SELECT COUNT(*) FROM musiccurator_review')->fetchColumn());
		$beforeSecondRun = clone $schema;
		$migration->changeSchema($output, $closure, []);
		self::assertSame([], $platform->getAlterSchemaSQL((new Comparator($platform))->compareSchemas($beforeSecondRun, $schema)));
	}
}
