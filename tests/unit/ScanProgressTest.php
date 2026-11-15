<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Tests\Unit;

use OCA\MusicCurator\Controller\ProgressController;
use OCA\MusicCurator\Controller\ScanController;
use OCA\MusicCurator\Controller\TagController;
use OCA\MusicCurator\Service\AudioTagReader;
use OCA\MusicCurator\Service\AudioTagWriter;
use OCA\MusicCurator\Service\ScanIndexService;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\Config\IUserConfig;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/** Real SQLite persistence with a small adapter for Nextcloud's query-builder API. */
final class ScanProgressTest extends TestCase {
	private PDO $pdo;
	private IDBConnection $db;
	private ScanIndexService $index;
	private LoggerInterface $logger;
	private array $logs;

	protected function setUp(): void {
		$this->pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$this->pdo->exec('CREATE TABLE musiccurator_tracks (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id TEXT NOT NULL, file_id INTEGER NOT NULL,
			path TEXT NOT NULL, etag TEXT, size INTEGER, mtime INTEGER, scanned_at INTEGER, metadata TEXT, mb_recording_id TEXT, mb_release_id TEXT,
			mb_score INTEGER, matched_at INTEGER, review_status TEXT NOT NULL DEFAULT \'pending\', review_updated INTEGER NOT NULL DEFAULT 0, UNIQUE(user_id, file_id))');
		$this->pdo->exec('CREATE TABLE musiccurator_review (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id TEXT NOT NULL, file_id INTEGER NOT NULL, action TEXT NOT NULL, created_at INTEGER NOT NULL)');
		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('getQueryBuilder')->willReturnCallback(fn () => $this->queryBuilder());
		$this->db->method('beginTransaction')->willReturnCallback(function (): void {
			$this->pdo->beginTransaction();
		});
		$this->db->method('commit')->willReturnCallback(function (): void {
			$this->pdo->commit();
		});
		$this->db->method('rollBack')->willReturnCallback(function (): void {
			$this->pdo->rollBack();
		});
		$this->logs = [];
		$this->logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning', 'error'] as $level) {
			$this->logger->method($level)->willReturnCallback(function ($message, $context) use ($level): void {
				$this->logs[] = [$level, $message, $context];
			});
		}
		$this->index = new ScanIndexService($this->db, $this->logger);
	}

	public function testStatusAndHistorySurviveNewRequestsAndRescansAndAreIsolatedByUser(): void {
		$this->store('alice', 1);
		$this->store('bob', 1);
		$this->index->recordAction('alice', 1, 'marked_done', 'done');
		$fresh = new ScanIndexService($this->db, $this->logger);
		self::assertSame('done', $fresh->reviewProgress('alice', 1)['reviewStatus']);
		self::assertSame('pending', $fresh->reviewProgress('bob', 1)['reviewStatus']);
		self::assertSame([], $fresh->reviewProgress('bob', 1)['history']);
		$this->store('alice', 1, '/Music/renamed.mp3');
		$rows = $fresh->loadForUser('alice');
		self::assertCount(1, $rows);
		self::assertSame('done', $fresh->snapshotTrack($rows[1])['reviewStatus']);
		self::assertSame('/Music/renamed.mp3', $fresh->snapshotTrack($rows[1])['path']);
		$fresh->recordAction('alice', 1, 'metadata_saved', 'pending');
		$progress = $fresh->reviewProgress('alice', 1);
		self::assertSame('pending', $progress['reviewStatus']);
		self::assertSame(['metadata_saved', 'marked_done'], array_column($progress['history'], 'action'));
	}

	public function testHistoryInsertFailureRollsBackStatusAndProducesAnErrorLog(): void {
		$this->store('alice', 1);
		$this->pdo->exec("CREATE TRIGGER fail_review BEFORE INSERT ON musiccurator_review BEGIN SELECT RAISE(FAIL, 'history unavailable'); END");
		try {
			$this->index->recordAction('alice', 1, 'marked_done', 'done');
			self::fail('Expected failed history insert');
		} catch (\PDOException) {
			self::assertSame('pending', $this->index->reviewProgress('alice', 1)['reviewStatus']);
			self::assertSame([], $this->index->reviewProgress('alice', 1)['history']);
			self::assertSame('error', $this->logs[0][0]);
			self::assertSame(1, $this->logs[0][2]['file_id']);
		}
	}

	public function testVerifiedTagWritesAndUndoRecordHistoryAndReopenTheTrack(): void {
		$this->store('alice', 1);
		$this->index->recordAction('alice', 1, 'marked_done', 'done');
		[$config, $session, $root, $l10n] = $this->dependencies();
		$file = $this->createMock(File::class);
		foreach (['getId' => 1, 'getName' => '1.mp3', 'getMimeType' => 'audio/mpeg', 'getSize' => 123, 'getMtime' => 100, 'getEtag' => 'etag'] as $method => $value) {
			$file->method($method)->willReturn($value);
		}
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willReturn($file);
		$root->method('getUserFolder')->willReturn($folder);
		$tags = ['title' => 'Before', 'artist' => 'Artist', 'album' => 'Album', 'albumArtist' => '', 'track' => '', 'year' => '', 'genre' => '', 'tagged' => true];
		$reader = $this->createMock(AudioTagReader::class);
		$reader->method('read')->willReturnCallback(static function () use (&$tags) {
			return $tags;
		});
		$writer = $this->createMock(AudioTagWriter::class);
		$writer->method('writeMp3')->willReturnCallback(static function ($file, $updates) use (&$tags) {
			$tags = array_merge($tags, $updates);
			return ['fields' => array_keys($updates), 'bytes' => 123];
		});
		$controller = new TagController('musiccurator', $this->createMock(IRequest::class), $root, $session, $config, $reader, $writer, $this->logger, $this->index, $l10n);
		$response = $controller->write('/Music/1.mp3', title: 'After', useTitle: '1');
		self::assertTrue($response->getData()['written']);
		self::assertTrue($response->getData()['historyRecorded']);
		self::assertSame('metadata_saved', $this->index->reviewProgress('alice', 1)['history'][0]['action']);
		self::assertSame('pending', $this->index->reviewProgress('alice', 1)['reviewStatus']);
		self::assertSame('After', $tags['title']);
		$this->index->recordAction('alice', 1, 'marked_done', 'done');
		self::assertTrue($controller->undoLast()->getData()['undone']);
		self::assertSame('Before', $tags['title']);
		self::assertSame('metadata_undone', $this->index->reviewProgress('alice', 1)['history'][0]['action']);
		self::assertSame('pending', $this->index->reviewProgress('alice', 1)['reviewStatus']);
	}

	public function testHistoryShowsTheNewestFiftyActionsInStableOrder(): void {
		$this->store('alice', 1);
		for ($i = 0; $i < 55; $i++) {
			$this->index->recordAction('alice', 1, 'reopened', 'pending');
		}
		$history = $this->index->reviewProgress('alice', 1)['history'];
		self::assertCount(50, $history);
		self::assertSame(55, $history[0]['id']);
		self::assertSame(6, $history[49]['id']);
	}

	public function testUnknownUserCannotUpdateAnotherUsersTrack(): void {
		$this->store('bob', 1);
		$this->expectException(\OutOfBoundsException::class);
		$this->index->recordAction('alice', 1, 'marked_done', 'done');
	}

	public function testCacheRestoresMoreThanFiveThousandTracksWithReviewStatus(): void {
		$this->pdo->beginTransaction();
		$insert = $this->pdo->prepare('INSERT INTO musiccurator_tracks (user_id, file_id, path, metadata) VALUES (?, ?, ?, ?)');
		for ($id = 1; $id <= 10001; $id++) {
			$insert->execute(['alice', $id, '/Music/' . $id . '.mp3', '{"title":"Track","artist":"Artist","album":"Album","tagged":true}']);
		}
		$this->pdo->commit();
		$this->index->recordAction('alice', 10001, 'marked_done', 'done');
		[$config, $session, $root, $l10n] = $this->dependencies();
		$controller = new ScanController('musiccurator', $this->createMock(IRequest::class), $root, $session, $config,
			$this->createMock(AudioTagReader::class), new ScanIndexService($this->db, $this->logger), $this->logger, $l10n);
		$response = $controller->cached();
		self::assertSame(200, $response->getStatus());
		self::assertCount(10001, $response->getData()['tracks']);
		$done = array_values(array_filter($response->getData()['tracks'], static fn ($track) => $track['reviewStatus'] === 'done'));
		self::assertSame(10001, $done[0]['fileId']);
		self::assertSame(10001, $this->logs[1][2]['restored_tracks']);
	}

	public static function previousIndexSizes(): array {
		return ['empty index' => [0], 'partial index like the reported refresh' => [43]];
	}

	#[DataProvider('previousIndexSizes')]
	public function testFullScanAndANewRequestReturnTheSameTracks(int $previouslyIndexed): void {
		for ($id = 1; $id <= $previouslyIndexed; $id++) {
			$this->store('alice', $id, '/Music/' . $id . '.mp3');
		}
		[$config, $session, $root, $l10n] = $this->dependencies();
		$files = [];
		for ($id = 1; $id <= 60; $id++) {
			$file = $this->createMock(File::class);
			foreach (['getId' => $id, 'getName' => $id . '.mp3', 'getMimeType' => 'audio/mpeg', 'getSize' => 123, 'getMtime' => 100, 'getEtag' => 'etag'] as $method => $value) {
				$file->method($method)->willReturn($value);
			}
			$files[] = $file;
		}
		$library = $this->createMock(Folder::class);
		$library->method('getDirectoryListing')->willReturn($files);
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->with('Music')->willReturn($library);
		$root->method('getUserFolder')->with('alice')->willReturn($userFolder);
		$reader = $this->createMock(AudioTagReader::class);
		$reader->method('read')->willReturn(['title' => 'Track', 'artist' => 'Artist', 'album' => 'Album',
			'albumArtist' => '', 'track' => '', 'year' => '', 'genre' => '', 'tagged' => true]);

		// RouteRegistrationTest separately checks the actual attributes and PHP
		// routes together, so this cannot silently test an unreachable controller.
		$routes = (require __DIR__ . '/../../appinfo/routes.php')['routes'];
		$route = array_values(array_filter($routes, static fn ($route) => $route['verb'] === 'POST' && $route['url'] === '/api/library/scan'))[0];
		self::assertSame('scan#scan', $route['name']);
		$controller = new ScanController('musiccurator', $this->createMock(IRequest::class), $root, $session, $config,
			$reader, $this->index, $this->logger, $l10n);
		$response = $controller->scan('/Music');
		self::assertSame(200, $response->getStatus());
		$scanned = $response->getData();
		self::assertCount(60, $scanned['tracks']);
		self::assertSame($previouslyIndexed, $scanned['cache']['hits']);
		self::assertGreaterThan(0, $scanned['lastScanAt']);

		// A fresh controller/index instance stands for F5: no scan arrays survive.
		$fresh = new ScanController('musiccurator', $this->createMock(IRequest::class), $root, $session, $config,
			$reader, new ScanIndexService($this->db, $this->logger), $this->logger, $l10n);
		$cachedResponse = $fresh->cached();
		self::assertSame(200, $cachedResponse->getStatus());
		$cached = $cachedResponse->getData();
		self::assertCount(60, $cached['tracks']);
		self::assertSame('/Music', $cached['libraryPath']);
		self::assertSame($scanned['lastScanAt'], $cached['lastScanAt']);
		$ids = array_column($cached['tracks'], 'fileId');
		sort($ids);
		self::assertSame(range(1, 60), $ids);
		self::assertContains('MusicCurator library scan completed', array_column($this->logs, 1));
	}

	public function testCacheFailureReturnsUsefulErrorAndLogsTheActualException(): void {
		$this->pdo->exec('DROP TABLE musiccurator_tracks');
		[$config, $session, $root, $l10n] = $this->dependencies();
		$controller = new ScanController('musiccurator', $this->createMock(IRequest::class), $root, $session, $config,
			$this->createMock(AudioTagReader::class), $this->index, $this->logger, $l10n);
		self::assertSame(500, $controller->cached()->getStatus());
		self::assertSame('error', $this->logs[0][0]);
		self::assertInstanceOf(\PDOException::class, $this->logs[0][2]['exception']);
	}

	public function testProgressEndpointValidatesLibraryAndAuthenticationAndProtectsWritesFromCsrf(): void {
		$this->store('alice', 1);
		[$config, $session, $root, $l10n] = $this->dependencies();
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(1);
		$file->method('isReadable')->willReturn(true);
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->with('Music/1.mp3')->willReturn($file);
		$root->method('getUserFolder')->with('alice')->willReturn($folder);
		$userConfig = $this->createMock(IUserConfig::class);
		$userConfig->method('getValueString')->willReturn('/Music');
		$controller = new ProgressController('musiccurator', $this->createMock(IRequest::class), $session, $root, $userConfig, $this->index, $this->logger, $l10n);
		self::assertSame(403, $controller->history('/Music-other/1.mp3')->getStatus());
		self::assertSame(403, $controller->history('/Music/../Other/1.mp3')->getStatus());
		self::assertSame(400, $controller->setStatus('/Music/1.mp3', 'invalid')->getStatus());
		self::assertSame('done', $controller->setStatus('/Music/1.mp3', 'done')->getData()['reviewStatus']);
		self::assertCount(1, $controller->history('/Music/1.mp3')->getData()['history']);
		self::assertSame([], (new ReflectionMethod(ProgressController::class, 'setStatus'))->getAttributes(NoCSRFRequired::class));
		$anonymous = $this->createMock(IUserSession::class);
		$controller = new ProgressController('musiccurator', $this->createMock(IRequest::class), $anonymous, $root, $userConfig, $this->index, $this->logger, $l10n);
		self::assertSame(401, $controller->history('/Music/1.mp3')->getStatus());
	}

	private function store(string $user, int $id, string $path = '/Music/1.mp3'): void {
		$this->index->storeTrack($user, $id, $path, 'etag', 123, 100, ['title' => 'Track', 'artist' => 'Artist', 'album' => 'Album', 'tagged' => true]);
	}

	private function dependencies(): array {
		$config = $this->createMock(IConfig::class);
		$values = ['library_path' => '/Music'];
		$config->method('getUserValue')->willReturnCallback(static function ($user, $app, $key, $default = '') use (&$values) {
			return $values[$key] ?? $default;
		});
		$config->method('setUserValue')->willReturnCallback(static function ($user, $app, $key, $value) use (&$values): void {
			$values[$key] = $value;
		});
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		return [$config, $session, $this->createMock(IRootFolder::class), $l10n];
	}

	private function queryBuilder(): IQueryBuilder {
		$state = ['type' => 'select', 'columns' => '*', 'table' => '', 'where' => '1=1', 'limit' => '', 'order' => '', 'values' => []];
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn ($x, $y) => $x . ' = ' . $y);
		$expr->method('andX')->willReturnCallback(function (...$parts) {
			$composite = $this->createMockForIntersectionOfInterfaces([ICompositeExpression::class, \Stringable::class]);
			$composite->method('__toString')->willReturn('(' . implode(' AND ', $parts) . ')');
			return $composite;
		});
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(fn ($value) => is_int($value) ? (string)$value : $this->pdo->quote((string)$value));
		foreach (['from', 'insert', 'update'] as $method) {
			$qb->method($method)->willReturnCallback(function ($table) use (&$state, $qb, $method) {
				$state['table'] = $table;
				if ($method !== 'from') {
					$state['type'] = $method;
				}
				return $qb;
			});
		}
		$qb->method('select')->willReturnCallback(function (...$columns) use (&$state, $qb) {
			$state['columns'] = implode(', ', $columns);
			return $qb;
		});
		$qb->method('where')->willReturnCallback(function ($where) use (&$state, $qb) {
			$state['where'] = (string)$where;
			return $qb;
		});
		$qb->method('setMaxResults')->willReturnCallback(function ($limit) use (&$state, $qb) {
			$state['limit'] = ' LIMIT ' . (int)$limit;
			return $qb;
		});
		$qb->method('orderBy')->willReturnCallback(function ($column, $order) use (&$state, $qb) {
			$state['order'] = ' ORDER BY ' . $column . ' ' . $order;
			return $qb;
		});
		$qb->method('values')->willReturnCallback(function ($values) use (&$state, $qb) {
			$state['values'] = $values;
			return $qb;
		});
		$qb->method('set')->willReturnCallback(function ($column, $value) use (&$state, $qb) {
			$state['values'][$column] = $value;
			return $qb;
		});
		$qb->method('executeQuery')->willReturnCallback(function () use (&$state) {
			$statement = $this->pdo->query('SELECT ' . $state['columns'] . ' FROM ' . $state['table'] . ' WHERE ' . $state['where'] . $state['order'] . $state['limit']);
			$result = $this->createMock(IResult::class);
			$result->method('fetchAssociative')->willReturnCallback(static fn () => $statement->fetch(PDO::FETCH_ASSOC));
			$result->method('closeCursor')->willReturnCallback(static fn () => $statement->closeCursor());
			return $result;
		});
		$qb->method('executeStatement')->willReturnCallback(function () use (&$state) {
			if ($state['type'] === 'insert') {
				$sql = 'INSERT INTO ' . $state['table'] . ' (' . implode(', ', array_keys($state['values'])) . ') VALUES (' . implode(', ', $state['values']) . ')';
			} else {
				$sets = [];
				foreach ($state['values'] as $column => $value) {
					$sets[] = $column . ' = ' . $value;
				}
				$sql = 'UPDATE ' . $state['table'] . ' SET ' . implode(', ', $sets) . ' WHERE ' . $state['where'];
			}
			return $this->pdo->exec($sql);
		});
		return $qb;
	}
}
