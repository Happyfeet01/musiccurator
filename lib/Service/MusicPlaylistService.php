<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Service;

use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IDBConnection;
use RuntimeException;
use Throwable;

/** Optional bridge to the Music app's playlist library. */
class MusicPlaylistService {
	public function __construct(
		private IAppManager $appManager,
		private IRootFolder $rootFolder,
		private IConfig $config,
		private IDBConnection $db,
	) {
	}

	/** @return array<string, mixed> */
	public function publish(string $userId, string $path): array {
		if (!$this->appManager->isEnabledForUser('music')) {
			throw new RuntimeException('Enable Nextcloud Music and scan your music collection there first.');
		}
		$segments = explode('/', str_replace('\\', '/', $path));
		if (in_array('..', $segments, true) || in_array('.', $segments, true)) {
			throw new RuntimeException('Invalid playlist path.');
		}
		$path = '/' . implode('/', array_filter($segments, static fn (string $part): bool => $part !== ''));
		$storedLibrary = $this->config->getUserValue($userId, 'musiccurator', 'library_path', '');
		$library = rtrim($storedLibrary, '/');
		if ($storedLibrary === '' || !str_starts_with($path, $library . '/')) {
			throw new RuntimeException('Playlist is outside the configured music library.');
		}
		$folder = $this->rootFolder->getUserFolder($userId);
		$file = $folder->get(ltrim($path, '/'));
		if (!$file instanceof File || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'm3u8'
			|| !str_contains((string)$file->getContent(), '# MusicCurator managed playlist')) {
			throw new RuntimeException('Only MusicCurator-managed M3U8 playlists can be published.');
		}

		// Resolve only on explicit publication, so Music remains an optional app.
		\OC_App::loadApp('music');
		$parser = \OCP\Server::get(\OCA\Music\Service\PlaylistFileService::class);
		$tracks = \OCP\Server::get(\OCA\Music\BusinessLayer\TrackBusinessLayer::class);
		$playlists = \OCP\Server::get(\OCA\Music\BusinessLayer\PlaylistBusinessLayer::class);
		$parsed = $parser->parseFile($file->getId(), $folder);
		$missing = $parsed['invalid_paths'];
		$ids = [];
		foreach ($parsed['files'] as $entry) {
			$node = $entry['file'] ?? null;
			if (!$node instanceof File) {
				$missing[] = (string)($entry['url'] ?? 'Unsupported playlist entry');
				continue;
			}
			$track = $tracks->findByFileId($node->getId(), $userId);
			if ($track === null) {
				$missing[] = $folder->getRelativePath($node->getPath());
			} else {
				$ids[] = $track->getId();
			}
		}
		// Preserve the previous server playlist until every entry is available.
		if ($missing !== [] || $ids === []) {
			return ['published' => false, 'missing' => array_values($missing), 'matched' => count($ids),
				'message' => 'Scan the listed files in Nextcloud Music, then publish again. The server playlist was left unchanged.'];
		}

		$key = 'music_playlist:' . hash('sha256', $path);
		$marker = 'MusicCurator:' . hash('sha256', $path);
		$id = (int)$this->config->getUserValue($userId, 'musiccurator', $key, '0');
		$this->db->beginTransaction();
		try {
			if ($id > 0) {
				$playlist = $playlists->find($id, $userId);
				if ($playlist->getComment() !== $marker) {
					throw new RuntimeException('The linked Music playlist is no longer managed by MusicCurator.');
				}
			} else {
				$playlist = $playlists->create(pathinfo($file->getName(), PATHINFO_FILENAME) . ' [MusicCurator]', $userId);
				$id = $playlist->getId();
				$playlists->setComment($marker, $id, $userId);
			}
			$playlists->setTracks($ids, $id, $userId);
			$this->config->setUserValue($userId, 'musiccurator', $key, (string)$id);
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		return ['published' => true, 'playlistId' => $id, 'tracks' => count($ids), 'missing' => [],
			'message' => 'Playlist published in Nextcloud Music. Refresh playlists in Amperfy or play:Sub.'];
	}
}
