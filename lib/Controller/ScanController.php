<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Controller;

use OCA\MusicCurator\Service\AudioTagReader;
use OCA\MusicCurator\Service\ScanIndexService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
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

			$untagged = 0;
			$albumKeys = [];
			foreach ($tracks as $track) {
				if (!$track['tagged'] || $track['title'] === '' || $track['artist'] === '') {
					++$untagged;
				}
				if ($track['album'] !== '') {
					$albumKeys[strtolower($track['albumArtist'] . "\0" . $track['artist'] . "\0" . $track['album'])] = true;
				}
			}

			$durationMs = (int)round((microtime(true) - $startedAt) * 1000);
			$this->logger->info('MusicCurator library scan completed', [
				'app' => 'musiccurator',
				'user' => $userId,
				'path' => $libraryPath,
				'tracks' => count($tracks),
				'albums' => count($albumKeys),
				'playlists' => count($playlists),
				'cache_hits' => $cacheHits,
				'fresh_tag_reads' => $freshReads,
				'duration_ms' => $durationMs,
			]);

			return new DataResponse([
				'libraryPath' => $libraryPath,
				'tracks' => $tracks,
				'playlists' => $playlists,
				'stats' => [
					'tracks' => count($tracks),
					'needsReview' => $untagged,
					'albums' => count($albumKeys),
					'playlists' => count($playlists),
				],
				'cache' => [
					'hits' => $cacheHits,
					'freshReads' => $freshReads,
					'knownFiles' => count($index),
				],
				'truncated' => count($tracks) >= self::MAX_TRACKS,
				'durationMs' => $durationMs,
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
	 * @param list<array{path: string, name: string}> $playlists
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
				$playlists[] = ['path' => $path, 'name' => $node->getName()];
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
