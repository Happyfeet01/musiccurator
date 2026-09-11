<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Controller;

use OCA\MusicCurator\Service\AudioTagReader;
use OCA\MusicCurator\Service\AudioTagWriter;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

class TagController extends Controller {
	private const TAG_FIELDS = ['title', 'artist', 'album', 'albumArtist', 'track', 'year', 'genre'];

	public function __construct(
		string $appName,
		IRequest $request,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private IConfig $config,
		private AudioTagReader $tagReader,
		private AudioTagWriter $tagWriter,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function write(
		string $path,
		string $title = '',
		string $artist = '',
		string $album = '',
		string $albumArtist = '',
		string $track = '',
		string $year = '',
		string $genre = '',
		string $useTitle = '0',
		string $useArtist = '0',
		string $useAlbum = '0',
		string $useAlbumArtist = '0',
		string $useTrack = '0',
		string $useYear = '0',
		string $useGenre = '0',
	): DataResponse {
		$userId = $this->userId();

		try {
			$path = $this->normalizePath($path);
			$this->assertInsideLibrary($userId, $path);
			$node = $this->nodeForUserPath($userId, $path);
			if (!$node instanceof File) {
				return new DataResponse(['message' => 'Selected path is not a file.'], Http::STATUS_BAD_REQUEST);
			}
			if (strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION)) !== 'mp3') {
				return new DataResponse(['message' => 'Experimental tag writing currently supports MP3 files only.'], Http::STATUS_BAD_REQUEST);
			}

			$values = [
				'title' => $title,
				'artist' => $artist,
				'album' => $album,
				'albumArtist' => $albumArtist,
				'track' => $track,
				'year' => $year,
				'genre' => $genre,
			];
			$flags = [
				'title' => $useTitle === '1',
				'artist' => $useArtist === '1',
				'album' => $useAlbum === '1',
				'albumArtist' => $useAlbumArtist === '1',
				'track' => $useTrack === '1',
				'year' => $useYear === '1',
				'genre' => $useGenre === '1',
			];

			$updates = [];
			foreach (self::TAG_FIELDS as $field) {
				if ($flags[$field]) {
					$updates[$field] = trim($values[$field]);
				}
			}
			if ($updates === []) {
				return new DataResponse(['message' => 'Choose at least one metadata field to write.'], Http::STATUS_BAD_REQUEST);
			}

			$before = $this->tagReader->read($node);
			$backup = [];
			foreach (array_keys($updates) as $field) {
				$backup[$field] = (string)($before[$field] ?? '');
			}

			$this->logger->info('MusicCurator MP3 metadata write started', [
				'app' => 'musiccurator',
				'user' => $userId,
				'path' => $path,
				'fields' => array_keys($updates),
			]);

			$result = $this->tagWriter->writeMp3($node, $updates);
			$after = $this->tagReader->read($node);
			foreach ($updates as $field => $expected) {
				$actual = trim((string)($after[$field] ?? ''));
				if ($field === 'year') {
					$expected = $this->normalizedYear($expected);
					$actual = $this->normalizedYear($actual);
				}
				if ($actual !== trim($expected)) {
					$this->tagWriter->writeMp3($node, $backup);
					throw new \RuntimeException(sprintf('Verification failed for field %s; the previous metadata was restored.', $field));
				}
			}

			$backupId = bin2hex(random_bytes(8));
			$this->storeBackup($userId, $backupId, $path, $backup, $updates);
			$this->appendChange($userId, $path, array_keys($updates), $backupId);

			$this->logger->info('MusicCurator MP3 metadata write completed', [
				'app' => 'musiccurator',
				'user' => $userId,
				'path' => $path,
				'fields' => array_keys($updates),
				'backup_id' => $backupId,
			]);

			return new DataResponse([
				'written' => true,
				'path' => $path,
				'fields' => $result['fields'],
				'bytes' => $result['bytes'],
				'tags' => $after,
				'backupId' => $backupId,
			]);
		} catch (Throwable $e) {
			$this->logger->warning('MusicCurator MP3 metadata write failed', [
				'app' => 'musiccurator',
				'user' => $userId,
				'path' => $path,
				'exception' => $e,
			]);

			return new DataResponse(['message' => 'Metadata write failed: ' . $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function undoLast(): DataResponse {
		$userId = $this->userId();
		$backups = $this->backups($userId);
		$backup = $backups[0] ?? null;
		if (!is_array($backup)) {
			return new DataResponse(['message' => 'There is no metadata write to undo.'], Http::STATUS_NOT_FOUND);
		}

		try {
			$path = $this->normalizePath((string)($backup['path'] ?? ''));
			$this->assertInsideLibrary($userId, $path);
			$node = $this->nodeForUserPath($userId, $path);
			if (!$node instanceof File) {
				throw new \RuntimeException('The file from the last metadata write no longer exists.');
			}
			$before = is_array($backup['before'] ?? null) ? $backup['before'] : [];
			$restore = [];
			foreach (self::TAG_FIELDS as $field) {
				if (array_key_exists($field, $before)) {
					$restore[$field] = (string)$before[$field];
				}
			}
			if ($restore === []) {
				throw new \RuntimeException('The stored rollback record contains no tag fields.');
			}

			$this->tagWriter->writeMp3($node, $restore);
			array_shift($backups);
			$this->config->setUserValue($userId, 'musiccurator', 'tag_backups', (string)json_encode($backups, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
			$this->appendChange($userId, $path, array_keys($restore), '', 'metadata_undo');

			return new DataResponse([
				'undone' => true,
				'path' => $path,
				'tags' => $this->tagReader->read($node),
			]);
		} catch (Throwable $e) {
			return new DataResponse(['message' => 'Undo failed: ' . $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	/** @param array<string, string> $before @param array<string, string> $after */
	private function storeBackup(string $userId, string $id, string $path, array $before, array $after): void {
		$backups = $this->backups($userId);
		array_unshift($backups, [
			'id' => $id,
			'path' => $path,
			'before' => $before,
			'after' => $after,
			'timestamp' => time(),
		]);
		$backups = array_slice($backups, 0, 100);
		$this->config->setUserValue($userId, 'musiccurator', 'tag_backups', (string)json_encode($backups, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}

	/** @return list<array<string, mixed>> */
	private function backups(string $userId): array {
		$raw = $this->config->getUserValue($userId, 'musiccurator', 'tag_backups', '[]');
		$backups = json_decode($raw, true);
		return is_array($backups) ? array_values(array_filter($backups, 'is_array')) : [];
	}

	/** @param list<string> $fields */
	private function appendChange(string $userId, string $path, array $fields, string $backupId = '', string $type = 'metadata'): void {
		$raw = $this->config->getUserValue($userId, 'musiccurator', 'changes', '[]');
		$changes = json_decode($raw, true);
		if (!is_array($changes)) {
			$changes = [];
		}
		array_unshift($changes, [
			'type' => $type,
			'source' => $path,
			'target' => $path,
			'fields' => $fields,
			'backupId' => $backupId,
			'timestamp' => time(),
		]);
		$changes = array_slice($changes, 0, 100);
		$this->config->setUserValue($userId, 'musiccurator', 'changes', (string)json_encode($changes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}

	private function normalizedYear(string $value): string {
		return preg_match('/\d{4}/', $value, $match) ? $match[0] : trim($value);
	}

	private function userId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('No authenticated Nextcloud user.');
		}
		return $user->getUID();
	}

	private function nodeForUserPath(string $userId, string $path): Node {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$relative = ltrim($path, '/');
		return $relative === '' ? $userFolder : $userFolder->get($relative);
	}

	private function libraryPath(string $userId): string {
		$stored = trim($this->config->getUserValue($userId, 'musiccurator', 'library_path', ''));
		return $stored === '' ? '' : $this->normalizePath($stored);
	}

	private function assertInsideLibrary(string $userId, string $path): void {
		$library = rtrim($this->libraryPath($userId), '/');
		if ($library === '') {
			throw new \RuntimeException('No music folder is configured.');
		}
		if ($path !== $library && !str_starts_with($path, $library . '/')) {
			throw new \RuntimeException('The requested path is outside the configured music library.');
		}
	}

	private function normalizePath(string $path): string {
		$segments = $this->pathSegments($path);
		return $segments === [] ? '/' : '/' . implode('/', $segments);
	}

	/** @return list<string> */
	private function pathSegments(string $path): array {
		$path = str_replace('\\', '/', trim($path));
		$segments = [];
		foreach (explode('/', $path) as $segment) {
			$segment = trim($segment);
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..') {
				throw new \InvalidArgumentException('Parent path segments are not allowed.');
			}
			$segments[] = $segment;
		}
		return $segments;
	}
}
