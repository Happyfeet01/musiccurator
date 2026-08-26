<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Controller;

use OCA\MusicCurator\Service\AudioTagReader;
use OCA\MusicCurator\Service\MusicBrainzService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
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
use Throwable;

class LibraryController extends Controller {
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
		private MusicBrainzService $musicBrainz,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'GET', url: '/api/settings')]
	public function settings(): DataResponse {
		$userId = $this->userId();
		return new DataResponse([
			'libraryPath' => $this->libraryPath($userId),
			'musicBrainzEnabled' => $this->config->getUserValue($userId, 'musiccurator', 'musicbrainz_enabled', '1') === '1',
			'acoustIdConfigured' => $this->config->getUserValue($userId, 'musiccurator', 'acoustid_key', '') !== '',
			'acoustIdUserConfigured' => $this->config->getUserValue($userId, 'musiccurator', 'acoustid_user_key', '') !== '',
			'discogsConfigured' => $this->config->getUserValue($userId, 'musiccurator', 'discogs_token', '') !== '',
			'lastFmConfigured' => $this->config->getUserValue($userId, 'musiccurator', 'lastfm_key', '') !== '',
		]);
	}

	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'POST', url: '/api/settings')]
	public function saveSettings(
		string $libraryPath = '/Music',
		string $musicBrainzEnabled = '1',
		string $acoustIdKey = '',
		string $acoustIdUserKey = '',
		string $discogsToken = '',
		string $lastFmKey = '',
	): DataResponse {
		$userId = $this->userId();
		try {
			$libraryPath = $this->normalizePath($libraryPath);
			$node = $this->nodeForUserPath($userId, $libraryPath);
			if (!$node instanceof Folder) {
				return new DataResponse(['message' => 'The selected library path is not a folder.'], Http::STATUS_BAD_REQUEST);
			}
		} catch (Throwable) {
			return new DataResponse(['message' => 'The selected music folder does not exist in your Nextcloud files.'], Http::STATUS_BAD_REQUEST);
		}

		$this->config->setUserValue($userId, 'musiccurator', 'library_path', $libraryPath);
		$this->config->setUserValue($userId, 'musiccurator', 'musicbrainz_enabled', $musicBrainzEnabled === '1' ? '1' : '0');
		$this->storeSecretIfProvided($userId, 'acoustid_key', $acoustIdKey);
		$this->storeSecretIfProvided($userId, 'acoustid_user_key', $acoustIdUserKey);
		$this->storeSecretIfProvided($userId, 'discogs_token', $discogsToken);
		$this->storeSecretIfProvided($userId, 'lastfm_key', $lastFmKey);

		return $this->settings();
	}

	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'GET', url: '/api/folders')]
	public function folders(string $path = '/'): DataResponse {
		$userId = $this->userId();
		try {
			$path = $this->normalizePath($path);
			$node = $this->nodeForUserPath($userId, $path);
			if (!$node instanceof Folder) {
				return new DataResponse(['message' => 'Path is not a folder.'], Http::STATUS_BAD_REQUEST);
			}
		} catch (Throwable) {
			return new DataResponse(['message' => 'Folder not found.'], Http::STATUS_NOT_FOUND);
		}

		$folders = [];
		foreach ($node->getDirectoryListing() as $child) {
			if ($child instanceof Folder) {
				$folders[] = [
					'name' => $child->getName(),
					'path' => $this->joinUserPath($path, $child->getName()),
				];
			}
		}
		usort($folders, static fn (array $a, array $b): int => strcasecmp((string)$a['name'], (string)$b['name']));

		return new DataResponse([
			'path' => $path,
			'parent' => $this->parentPath($path),
			'folders' => $folders,
		]);
	}

	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'POST', url: '/api/library/scan')]
	public function scan(): DataResponse {
		$userId = $this->userId();
		$libraryPath = $this->libraryPath($userId);
		try {
			$node = $this->nodeForUserPath($userId, $libraryPath);
			if (!$node instanceof Folder) {
				return new DataResponse(['message' => 'The configured library path is not a folder.'], Http::STATUS_BAD_REQUEST);
			}
		} catch (Throwable) {
			return new DataResponse(['message' => 'The configured music folder no longer exists.'], Http::STATUS_NOT_FOUND);
		}

		$tracks = [];
		$playlists = [];
		$this->scanFolder($node, $libraryPath, $tracks, $playlists);

		$untagged = 0;
		$albumKeys = [];
		foreach ($tracks as $track) {
			if (!$track['tagged'] || $track['title'] === '' || $track['artist'] === '') {
				++$untagged;
			}
			if ($track['album'] !== '') {
				$albumKeys[strtolower($track['artist'] . "\0" . $track['album'])] = true;
			}
		}

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
			'truncated' => count($tracks) >= self::MAX_TRACKS,
		]);
	}

	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'GET', url: '/api/musicbrainz')]
	public function musicBrainz(string $path): DataResponse {
		$userId = $this->userId();
		if ($this->config->getUserValue($userId, 'musiccurator', 'musicbrainz_enabled', '1') !== '1') {
			return new DataResponse(['message' => 'MusicBrainz is disabled in your personal settings.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$path = $this->normalizePath($path);
			$this->assertInsideLibrary($userId, $path);
			$node = $this->nodeForUserPath($userId, $path);
			if (!$node instanceof File) {
				return new DataResponse(['message' => 'Selected path is not a file.'], Http::STATUS_BAD_REQUEST);
			}
			$tags = $this->tagReader->read($node);
			$title = $tags['title'] !== '' ? $tags['title'] : pathinfo($node->getName(), PATHINFO_FILENAME);
			$results = $this->musicBrainz->search($title, $tags['artist'], $tags['album']);
			return new DataResponse(['results' => $results]);
		} catch (Throwable $e) {
			return new DataResponse(['message' => 'MusicBrainz lookup failed: ' . $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'POST', url: '/api/library/preview-move')]
	public function previewMove(
		string $source,
		string $title = '',
		string $artist = '',
		string $album = '',
		string $track = '',
	): DataResponse {
		$userId = $this->userId();
		try {
			$source = $this->normalizePath($source);
			$this->assertInsideLibrary($userId, $source);
			$sourceNode = $this->nodeForUserPath($userId, $source);
			if (!$sourceNode instanceof File) {
				return new DataResponse(['message' => 'Source is not a file.'], Http::STATUS_BAD_REQUEST);
			}
			$target = $this->buildTargetPath($userId, $sourceNode, $title, $artist, $album, $track);
			return new DataResponse(['source' => $source, 'target' => $target]);
		} catch (Throwable $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'POST', url: '/api/library/move')]
	public function move(string $source, string $target): DataResponse {
		$userId = $this->userId();
		try {
			$source = $this->normalizePath($source);
			$target = $this->normalizePath($target);
			$this->assertInsideLibrary($userId, $source);
			$this->assertInsideLibrary($userId, $target);

			$sourceNode = $this->nodeForUserPath($userId, $source);
			if (!$sourceNode instanceof File) {
				return new DataResponse(['message' => 'Source is not a file.'], Http::STATUS_BAD_REQUEST);
			}

			$userFolder = $this->rootFolder->getUserFolder($userId);
			if ($userFolder->nodeExists(ltrim($target, '/'))) {
				return new DataResponse(['message' => 'A file already exists at the proposed destination.'], Http::STATUS_CONFLICT);
			}

			$targetDirectoryPath = $this->parentPath($target);
			$targetFolder = $this->ensureFolder($userFolder, $targetDirectoryPath);
			$targetName = basename($target);
			$sourceNode->move(rtrim($targetFolder->getPath(), '/') . '/' . $targetName);
			$this->appendChange($userId, $source, $target);

			return new DataResponse(['source' => $source, 'target' => $target, 'moved' => true]);
		} catch (Throwable $e) {
			return new DataResponse(['message' => 'Move failed: ' . $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	#[FrontpageRoute(verb: 'GET', url: '/api/changes')]
	public function changes(): DataResponse {
		$userId = $this->userId();
		$raw = $this->config->getUserValue($userId, 'musiccurator', 'changes', '[]');
		$changes = json_decode($raw, true);
		return new DataResponse(['changes' => is_array($changes) ? $changes : []]);
	}

	/**
	 * @param list<array<string, mixed>> $tracks
	 * @param list<array{path: string, name: string}> $playlists
	 */
	private function scanFolder(Folder $folder, string $relativePath, array &$tracks, array &$playlists): void {
		if (count($tracks) >= self::MAX_TRACKS) {
			return;
		}

		foreach ($folder->getDirectoryListing() as $node) {
			if (count($tracks) >= self::MAX_TRACKS) {
				return;
			}
			$path = $this->joinUserPath($relativePath, $node->getName());
			if ($node instanceof Folder) {
				$this->scanFolder($node, $path, $tracks, $playlists);
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

			$tags = $this->tagReader->read($node);
			$tracks[] = [
				'path' => $path,
				'filename' => $node->getName(),
				'extension' => $extension,
				'mime' => $node->getMimeType(),
				'size' => $node->getSize(),
				'mtime' => $node->getMTime(),
				...$tags,
				'status' => $tags['title'] !== '' && $tags['artist'] !== '' ? 'Metadata' : 'Needs tags',
			];
		}
	}

	private function buildTargetPath(string $userId, File $source, string $title, string $artist, string $album, string $track): string {
		$current = $this->tagReader->read($source);
		$title = $this->safeSegment($title !== '' ? $title : ($current['title'] !== '' ? $current['title'] : pathinfo($source->getName(), PATHINFO_FILENAME)), 'Unknown title');
		$artist = $this->safeSegment($artist !== '' ? $artist : ($current['albumArtist'] !== '' ? $current['albumArtist'] : $current['artist']), 'Unknown Artist');
		$album = $this->safeSegment($album !== '' ? $album : $current['album'], 'Unknown Album');
		$track = trim($track !== '' ? $track : $current['track']);
		$track = preg_replace('/[^0-9].*$/', '', $track) ?? '';
		$prefix = $track !== '' ? str_pad((string)(int)$track, 2, '0', STR_PAD_LEFT) . ' - ' : '';
		$extension = strtolower(pathinfo($source->getName(), PATHINFO_EXTENSION));
		$filename = $prefix . $title . ($extension !== '' ? '.' . $extension : '');

		return $this->normalizePath($this->libraryPath($userId) . '/' . $artist . '/' . $album . '/' . $filename);
	}

	private function ensureFolder(Folder $userFolder, string $path): Folder {
		$folder = $userFolder;
		foreach ($this->pathSegments($path) as $segment) {
			if ($folder->nodeExists($segment)) {
				$node = $folder->get($segment);
				if (!$node instanceof Folder) {
					throw new \RuntimeException('A file blocks the target folder ' . $segment . '.');
				}
				$folder = $node;
			} else {
				$folder = $folder->newFolder($segment);
			}
		}
		return $folder;
	}

	private function appendChange(string $userId, string $source, string $target): void {
		$raw = $this->config->getUserValue($userId, 'musiccurator', 'changes', '[]');
		$changes = json_decode($raw, true);
		if (!is_array($changes)) {
			$changes = [];
		}
		array_unshift($changes, [
			'type' => 'move',
			'source' => $source,
			'target' => $target,
			'timestamp' => time(),
		]);
		$changes = array_slice($changes, 0, 50);
		$this->config->setUserValue($userId, 'musiccurator', 'changes', (string)json_encode($changes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}

	private function assertInsideLibrary(string $userId, string $path): void {
		$library = rtrim($this->libraryPath($userId), '/');
		if ($library === '') {
			return;
		}
		if ($path !== $library && !str_starts_with($path, $library . '/')) {
			throw new \RuntimeException('The requested path is outside the configured music library.');
		}
	}

	private function nodeForUserPath(string $userId, string $path): Node {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$relative = ltrim($path, '/');
		return $relative === '' ? $userFolder : $userFolder->get($relative);
	}

	private function libraryPath(string $userId): string {
		return $this->normalizePath($this->config->getUserValue($userId, 'musiccurator', 'library_path', '/Music'));
	}

	private function storeSecretIfProvided(string $userId, string $key, string $value): void {
		$value = trim($value);
		if ($value !== '') {
			$this->config->setUserValue($userId, 'musiccurator', $key, $value);
		}
	}

	private function userId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('No authenticated Nextcloud user.');
		}
		return $user->getUID();
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

	private function parentPath(string $path): string {
		$segments = $this->pathSegments($path);
		array_pop($segments);
		return $segments === [] ? '/' : '/' . implode('/', $segments);
	}

	private function safeSegment(string $value, string $fallback): string {
		$value = preg_replace('/[\\x00-\\x1f\\x7f\\/\\\\<>:"|?*]+/u', ' ', trim($value)) ?? '';
		$value = preg_replace('/\\s+/u', ' ', $value) ?? '';
		$value = trim($value, " .\t\r\n");
		return $value !== '' ? $value : $fallback;
	}
}
