<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Controller;

use OCA\MusicCurator\Service\AudioTagReader;
use OCA\MusicCurator\Service\ScanIndexService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

class ScanController extends Controller {
	private const AUDIO_EXTENSIONS = ['mp3', 'flac', 'm4a', 'm4b', 'aac', 'ogg', 'opus', 'wav'];
	private const PLAYLIST_EXTENSIONS = ['m3u', 'm3u8'];
	private const MAX_TRACKS = 5000;

	public function __construct(
		string $appName,
		IRequest $request,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private IConfig $config,
		private AudioTagReader $tagReader,
		private ScanIndexService $scanIndex,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function cached(): DataResponse {
		$userId = $this->userId();
		$storedPath = trim($this->config->getUserValue($userId, 'musiccurator', 'library_path', ''));
		if ($storedPath === '') {
			return new DataResponse([
				'libraryPath' => '',
				'tracks' => [],
				'playlists' => [],
				'stats' => ['tracks' => 0, 'needsReview' => 0, 'albums' => 0, 'playlists' => 0],
				'truncated' => false,
				'durationMs' => 0,
				'lastScanAt' => 0,
				'fromCache' => true,
			]);
		}

		$libraryPath = $this->normalizePath($storedPath);
		$tracks = [];
		foreach ($this->scanIndex->loadForUser($userId) as $row) {
			$path = (string)($row['path'] ?? '');
			if (!$this->isInsideLibraryPath($path, $libraryPath)) {
				continue;
			}
			$track = $this->scanIndex->snapshotTrack($row);
			if ($track !== null) {
				$tracks[] = $track;
			}
		}
		usort($tracks, static fn (array $a, array $b): int => strcasecmp((string)($a['path'] ?? ''), (string)($b['path'] ?? '')));

		$lastScanPath = trim($this->config->getUserValue($userId, 'musiccurator', 'last_scan_path', ''));
		$playlists = [];
		if ($lastScanPath !== '' && $this->normalizePath($lastScanPath) === $libraryPath) {
			$decoded = json_decode($this->config->getUserValue($userId, 'musiccurator', 'last_scan_playlists', '[]'), true);
			if (is_array($decoded)) {
				$playlists = array_values(array_filter($decoded, static fn (mixed $playlist): bool => is_array($playlist)));
			}
		}

		return new DataResponse([
			'libraryPath' => $libraryPath,
			'tracks' => $tracks,
			'playlists' => $playlists,
			'stats' => $this->libraryStats($tracks, $playlists),
			'truncated' => $this->config->getUserValue($userId, 'musiccurator', 'last_scan_truncated', '0') === '1',
			'durationMs' => 0,
			'lastScanAt' => (int)$this->config->getUserValue($userId, 'musiccurator', 'last_scan_at', '0'),
			'fromCache' => true,
		]);
	}

	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function scan(string $libraryPath = ''): DataResponse {
		$userId = $this->userId();
		$startedAt = microtime(true);
		$requestedPath = trim($libraryPath);
		$storedPath = trim($this->config->getUserValue($userId, 'musiccurator', 'library_path', ''));

		// If the user has typed or selected a path in the current UI, use that
		// value and persist it after validation. Otherwise use the saved value.
		$libraryPath = $requestedPath !== '' ? $requestedPath : $storedPath;

		if ($libraryPath === '') {
			$this->logger->warning('MusicCurator scan rejected because no music folder is configured', [
				'app' => 'musiccurator',
				'user' => $userId,
			]);

			return new DataResponse([
				'message' => 'No music folder is configured. Choose a music folder first.',
			], Http::STATUS_BAD_REQUEST);
		}

		try {
			$libraryPath = $this->normalizePath($libraryPath);
			$node = $this->nodeForUserPath($userId, $libraryPath);
			if (!$node instanceof Folder) {
				$this->logger->warning('MusicCurator scan path is not a folder', [
					'app' => 'musiccurator',
					'user' => $userId,
					'path' => $libraryPath,
				]);

				return new DataResponse(['message' => 'The selected library path is not a folder.'], Http::STATUS_BAD_REQUEST);
			}

			$this->config->setUserValue($userId, 'musiccurator', 'library_path', $libraryPath);
			$this->logger->info('MusicCurator library scan started', [
				'app' => 'musiccurator',
				'user' => $userId,
				'path' => $libraryPath,
				'previous_saved_path' => $storedPath,
			]);

			$tracks = [];
			$playlists = [];
			$cacheHits = 0;
			$freshReads = 0;
			$index = $this->scanIndex->loadForUser($userId);
			$this->scanFolder($node, $libraryPath, $userId, $index, $tracks, $playlists, $cacheHits, $freshReads);
			$playlists = $this->decoratePlaylists($playlists, $tracks);
			$stats = $this->libraryStats($tracks, $playlists);

			$durationMs = (int)round((microtime(true) - $startedAt) * 1000);
			$truncated = count($tracks) >= self::MAX_TRACKS;
			$scanTime = time();
			$this->config->setUserValue($userId, 'musiccurator', 'last_scan_path', $libraryPath);
			$this->config->setUserValue($userId, 'musiccurator', 'last_scan_at', (string)$scanTime);
			$this->config->setUserValue($userId, 'musiccurator', 'last_scan_truncated', $truncated ? '1' : '0');
			$this->config->setUserValue(
				$userId,
				'musiccurator',
				'last_scan_playlists',
				json_encode($playlists, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
			);

			$this->logger->info('MusicCurator library scan completed', [
				'app' => 'musiccurator',
				'user' => $userId,
				'path' => $libraryPath,
				'tracks' => $stats['tracks'],
				'albums' => $stats['albums'],
				'playlists' => $stats['playlists'],
				'cache_hits' => $cacheHits,
				'fresh_tag_reads' => $freshReads,
				'duration_ms' => $durationMs,
			]);

			return new DataResponse([
				'libraryPath' => $libraryPath,
				'tracks' => $tracks,
				'playlists' => $playlists,
				'stats' => $stats,
				'cache' => [
					'hits' => $cacheHits,
					'freshReads' => $freshReads,
					'knownFiles' => count($index),
				],
				'truncated' => $truncated,
				'durationMs' => $durationMs,
				'lastScanAt' => $scanTime,
				'fromCache' => false,
			]);
		} catch (Throwable $e) {
			$this->logger->warning('MusicCurator library scan failed', [
				'app' => 'musiccurator',
				'user' => $userId,
				'path' => $libraryPath,
				'requested_path' => $requestedPath,
				'saved_path' => $storedPath,
				'exception' => $e,
			]);

			return new DataResponse([
				'message' => sprintf('The configured music folder "%s" does not exist or cannot be accessed. Choose the folder again.', $libraryPath),
			], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function scanSelected(string $libraryPath = ''): DataResponse {
		return $this->scan($libraryPath);
	}

	/**
	 * @param array<int, array<string, mixed>> $index
	 * @param list<array<string, mixed>> $tracks
	 * @param list<array<string, mixed>> $playlists
	 */
	private function scanFolder(
		Folder $folder,
		string $relativePath,
		string $userId,
		array &$index,
		array &$tracks,
		array &$playlists,
		int &$cacheHits,
		int &$freshReads,
	): void {
		if (count($tracks) >= self::MAX_TRACKS) {
			return;
		}

		foreach ($folder->getDirectoryListing() as $node) {
			if (count($tracks) >= self::MAX_TRACKS) {
				return;
			}

			$path = $this->joinUserPath($relativePath, $node->getName());
			if ($node instanceof Folder) {
				$this->scanFolder($node, $path, $userId, $index, $tracks, $playlists, $cacheHits, $freshReads);
				continue;
			}
			if (!$node instanceof File) {
				continue;
			}

			$extension = strtolower(pathinfo($node->getName(), PATHINFO_EXTENSION));
			if (in_array($extension, self::PLAYLIST_EXTENSIONS, true)) {
				$playlists[] = [
					'path' => $path,
					'name' => $node->getName(),
					'entries' => $this->playlistEntries($node, $path),
				];
				continue;
			}
			if (!in_array($extension, self::AUDIO_EXTENSIONS, true)) {
				continue;
			}

			$fileId = $node->getId();
			$etag = $node->getEtag();
			$size = (int)$node->getSize();
			$mtime = $node->getMTime();
			$existingRow = $index[$fileId] ?? null;
			$cached = $existingRow !== null
				? $this->scanIndex->cachedTrack($existingRow, $etag, $size, $mtime)
				: null;

			if ($cached !== null) {
				$cached['path'] = $path;
				$cached['filename'] = $node->getName();
				$cached['extension'] = $extension;
				$cached['mime'] = $node->getMimeType();
				$cached['size'] = $size;
				$cached['mtime'] = $mtime;
				$tracks[] = $cached;
				++$cacheHits;
				$this->scanIndex->updateSeenPath($existingRow, $path);
				continue;
			}

			$tags = $this->tagReader->read($node);
			$track = [
				'path' => $path,
				'filename' => $node->getName(),
				'extension' => $extension,
				'mime' => $node->getMimeType(),
				'size' => $size,
				'mtime' => $mtime,
				'fileId' => $fileId,
				...$tags,
				'status' => $tags['title'] !== '' && $tags['artist'] !== '' ? 'Metadata' : 'Needs tags',
				'scanState' => 'fresh',
				'scannedAt' => time(),
				'musicBrainzRecordingId' => (string)($existingRow['mb_recording_id'] ?? ''),
				'musicBrainzReleaseId' => (string)($existingRow['mb_release_id'] ?? ''),
				'musicBrainzScore' => (int)($existingRow['mb_score'] ?? 0),
				'musicBrainzMatchedAt' => (int)($existingRow['matched_at'] ?? 0),
			];

			$this->scanIndex->storeTrack($userId, $fileId, $path, $etag, $size, $mtime, $track, $existingRow);
			$tracks[] = $track;
			++$freshReads;
		}
	}

	/**
	 * @param list<array<string, mixed>> $playlists
	 * @param list<array<string, mixed>> $tracks
	 * @return list<array<string, mixed>>
	 */
	private function decoratePlaylists(array $playlists, array $tracks): array {
		$releaseByPath = [];
		foreach ($tracks as $track) {
			$releaseId = trim((string)($track['musicBrainzReleaseId'] ?? ''));
			$path = (string)($track['path'] ?? '');
			if ($releaseId !== '' && $path !== '') {
				$releaseByPath[$path] = $releaseId;
			}
		}

		foreach ($playlists as &$playlist) {
			$releaseIds = [];
			$entries = is_array($playlist['entries'] ?? null) ? $playlist['entries'] : [];
			foreach ($entries as $entry) {
				$releaseId = $releaseByPath[(string)$entry] ?? '';
				if ($releaseId === '' || in_array($releaseId, $releaseIds, true)) {
					continue;
				}
				$releaseIds[] = $releaseId;
				if (count($releaseIds) >= 4) {
					break;
				}
			}
			$playlist['artworkReleaseIds'] = $releaseIds;
			unset($playlist['entries']);
		}
		unset($playlist);

		return $playlists;
	}

	/** @return list<string> */
	private function playlistEntries(File $file, string $playlistPath): array {
		try {
			$content = $file->getContent();
		} catch (Throwable) {
			return [];
		}
		if ($content === '') {
			return [];
		}

		$base = str_replace('\\', '/', dirname($playlistPath));
		$base = $base === '.' ? '/' : $base;
		$entries = [];
		foreach (preg_split('/\R/u', $content) ?: [] as $line) {
			$line = trim((string)$line, "\xEF\xBB\xBF \t\r\n");
			if ($line === '' || str_starts_with($line, '#') || preg_match('~^[a-z][a-z0-9+.-]*://~i', $line)) {
				continue;
			}
			$line = rawurldecode(str_replace('\\', '/', $line));
			try {
				$entries[] = str_starts_with($line, '/')
					? $this->normalizePath($line)
					: $this->joinUserPath($base, $line);
			} catch (Throwable) {
				continue;
			}
			if (count($entries) >= 1000) {
				break;
			}
		}

		return array_values(array_unique($entries));
	}

	/**
	 * @param list<array<string, mixed>> $tracks
	 * @param list<array<string, mixed>> $playlists
	 * @return array{tracks: int, needsReview: int, albums: int, playlists: int}
	 */
	private function libraryStats(array $tracks, array $playlists): array {
		$untagged = 0;
		$albumKeys = [];
		foreach ($tracks as $track) {
			if (!($track['tagged'] ?? false) || (string)($track['title'] ?? '') === '' || (string)($track['artist'] ?? '') === '') {
				++$untagged;
			}
			if ((string)($track['album'] ?? '') !== '') {
				$albumKeys[strtolower((string)($track['albumArtist'] ?? '') . "\0" . (string)($track['artist'] ?? '') . "\0" . (string)$track['album'])] = true;
			}
		}

		return [
			'tracks' => count($tracks),
			'needsReview' => $untagged,
			'albums' => count($albumKeys),
			'playlists' => count($playlists),
		];
	}

	private function isInsideLibraryPath(string $path, string $libraryPath): bool {
		if ($libraryPath === '/') {
			return str_starts_with($path, '/');
		}
		$libraryPath = rtrim($libraryPath, '/');

		return $path === $libraryPath || str_starts_with($path, $libraryPath . '/');
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

	private function joinUserPath(string $base, string $name): string {
		return $this->normalizePath(rtrim($base, '/') . '/' . $name);
	}
}
