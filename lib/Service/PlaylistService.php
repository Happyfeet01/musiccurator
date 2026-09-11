<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use RuntimeException;
use Throwable;

class PlaylistService {
	private const AUDIO_EXTENSIONS = ['mp3', 'flac', 'm4a', 'm4b', 'aac', 'ogg', 'opus', 'wav'];
	private const MANAGED_MARKER = '# MusicCurator managed playlist';

	public function __construct(
		private IRootFolder $rootFolder,
		private IConfig $config,
		private AudioTagReader $tagReader,
	) {
	}

	/**
	 * @return array{path: string, name: string, entries: int, managed: bool}
	 */
	public function createForFolder(string $userId, string $folderPath, string $requestedName = ''): array {
		$folderPath = $this->normalizePath($folderPath);
		$this->assertInsideLibrary($userId, $folderPath);
		$node = $this->nodeForUserPath($userId, $folderPath);
		if (!$node instanceof Folder) {
			throw new RuntimeException('The selected path is not a folder.');
		}

		$audioEntries = $this->audioEntries($node);
		if ($audioEntries === []) {
			throw new RuntimeException('This folder contains no supported audio files.');
		}

		$playlistName = $this->playlistName($node, $requestedName);
		$playlistPath = $this->joinUserPath($folderPath, $playlistName);
		$content = $this->render($audioEntries);

		if ($node->nodeExists($playlistName)) {
			$playlist = $node->get($playlistName);
			if (!$playlist instanceof File) {
				throw new RuntimeException('A folder already uses the requested playlist name.');
			}
			$existing = $this->safeContent($playlist);
			if (!str_contains($existing, self::MANAGED_MARKER)) {
				throw new RuntimeException('A playlist with this name already exists and is not managed by MusicCurator. It was left untouched.');
			}
			$playlist->putContent($content);
		} else {
			$node->newFile($playlistName, $content);
		}

		$this->rememberPlaylist($userId, $playlistPath, $playlistName);

		return [
			'path' => $playlistPath,
			'name' => $playlistName,
			'entries' => count($audioEntries),
			'managed' => true,
		];
	}

	/** @param list<string> $folderPaths */
	public function refreshManagedForFolders(string $userId, array $folderPaths): void {
		foreach (array_values(array_unique($folderPaths)) as $folderPath) {
			try {
				$folderPath = $this->normalizePath($folderPath);
				$this->assertInsideLibrary($userId, $folderPath);
				$node = $this->nodeForUserPath($userId, $folderPath);
				if (!$node instanceof Folder) {
					continue;
				}
				$audioEntries = $this->audioEntries($node);
				foreach ($node->getDirectoryListing() as $child) {
					if (!$child instanceof File || strtolower(pathinfo($child->getName(), PATHINFO_EXTENSION)) !== 'm3u8') {
						continue;
					}
					if (!str_contains($this->safeContent($child), self::MANAGED_MARKER)) {
						continue;
					}
					$child->putContent($this->render($audioEntries));
				}
			} catch (Throwable) {
				// Playlist maintenance is best-effort and must never make a file move fail.
			}
		}
	}

	/**
	 * Album-like folders are sorted by their embedded track number when enough
	 * files agree on one album. Mixed folders keep natural filename order, which
	 * preserves playlist indices such as "01 - ...", "02 - ..." from downloads.
	 *
	 * @return list<array{file: File, tags: array<string, mixed>, trackNumber: int|null}>
	 */
	private function audioEntries(Folder $folder): array {
		$entries = [];
		foreach ($folder->getDirectoryListing() as $child) {
			if (!$child instanceof File) {
				continue;
			}
			$extension = strtolower(pathinfo($child->getName(), PATHINFO_EXTENSION));
			if (!in_array($extension, self::AUDIO_EXTENSIONS, true)) {
				continue;
			}

			$tags = $this->tagReader->read($child);
			$entries[] = [
				'file' => $child,
				'tags' => $tags,
				'trackNumber' => $this->numericTrack((string)($tags['track'] ?? '')),
			];
		}

		$albumKeys = [];
		$albumTagged = 0;
		$trackTagged = 0;
		foreach ($entries as $entry) {
			$tags = $entry['tags'];
			$album = trim((string)($tags['album'] ?? ''));
			if ($album !== '') {
				++$albumTagged;
				$artist = trim((string)($tags['albumArtist'] ?? ''));
				if ($artist === '') {
					$artist = trim((string)($tags['artist'] ?? ''));
				}
				$albumKeys[mb_strtolower($artist . "\0" . $album)] = true;
			}
			if ($entry['trackNumber'] !== null) {
				++$trackTagged;
			}
		}

		$minimumAgreement = max(2, (int)ceil(count($entries) * 0.75));
		$useAlbumTrackOrder = count($entries) > 1
			&& count($albumKeys) === 1
			&& $albumTagged >= $minimumAgreement
			&& $trackTagged >= $minimumAgreement;

		usort($entries, static function (array $a, array $b) use ($useAlbumTrackOrder): int {
			if ($useAlbumTrackOrder) {
				$aTrack = $a['trackNumber'] ?? PHP_INT_MAX;
				$bTrack = $b['trackNumber'] ?? PHP_INT_MAX;
				if ($aTrack !== $bTrack) {
					return $aTrack <=> $bTrack;
				}
			}

			return strnatcasecmp($a['file']->getName(), $b['file']->getName());
		});

		return $entries;
	}

	/**
	 * @param list<array{file: File, tags: array<string, mixed>, trackNumber: int|null}> $audioEntries
	 */
	private function render(array $audioEntries): string {
		$lines = ['#EXTM3U', self::MANAGED_MARKER];
		foreach ($audioEntries as $index => $entry) {
			$file = $entry['file'];
			$tags = $entry['tags'];
			$name = str_replace(["\r", "\n"], ' ', $file->getName());
			$title = trim((string)($tags['title'] ?? ''));
			if ($title === '') {
				$title = pathinfo($name, PATHINFO_FILENAME);
			}
			$artist = trim((string)($tags['artist'] ?? ''));
			$sequence = str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT);
			$label = $sequence . '. ' . ($artist !== '' ? $artist . ' - ' : '') . $title;
			$label = str_replace(["\r", "\n"], ' ', $label);

			// Extended M3U keeps the playlist position visible for players that use
			// EXTINF while the following relative filename stays maximally portable.
			$lines[] = '#EXTINF:-1,' . $label;
			$lines[] = $name;
		}

		return implode("\n", $lines) . "\n";
	}

	private function numericTrack(string $value): ?int {
		if (!preg_match('/^\s*(\d{1,4})(?:\s*\/\s*\d+)?/u', trim($value), $match)) {
			return null;
		}

		$track = (int)$match[1];
		return $track > 0 ? $track : null;
	}

	private function playlistName(Folder $folder, string $requestedName): string {
		$name = trim($requestedName);
		if ($name === '') {
			$name = $folder->getName() !== '' ? $folder->getName() : 'playlist';
		}
		$name = preg_replace('/[\x00-\x1f\x7f\/\\<>:"|?*]+/u', ' ', $name) ?? '';
		$name = trim(preg_replace('/\s+/u', ' ', $name) ?? '', " .\t\r\n");
		if ($name === '') {
			$name = 'playlist';
		}
		if (!str_ends_with(mb_strtolower($name), '.m3u8')) {
			$name .= '.m3u8';
		}

		return $name;
	}

	private function safeContent(File $file): string {
		try {
			return $file->getContent();
		} catch (Throwable) {
			return '';
		}
	}

	private function rememberPlaylist(string $userId, string $path, string $name): void {
		$raw = $this->config->getUserValue($userId, 'musiccurator', 'last_scan_playlists', '[]');
		$playlists = json_decode($raw, true);
		if (!is_array($playlists)) {
			$playlists = [];
		}

		$found = false;
		foreach ($playlists as &$playlist) {
			if (!is_array($playlist) || (string)($playlist['path'] ?? '') !== $path) {
				continue;
			}
			$playlist['name'] = $name;
			$playlist['managed'] = true;
			$found = true;
			break;
		}
		unset($playlist);

		if (!$found) {
			$playlists[] = ['path' => $path, 'name' => $name, 'managed' => true, 'artworkReleaseIds' => []];
		}

		$this->config->setUserValue(
			$userId,
			'musiccurator',
			'last_scan_playlists',
			json_encode(array_values($playlists), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
		);
	}

	private function assertInsideLibrary(string $userId, string $path): void {
		$library = trim($this->config->getUserValue($userId, 'musiccurator', 'library_path', ''));
		if ($library === '') {
			throw new RuntimeException('No music folder is configured.');
		}
		$library = rtrim($this->normalizePath($library), '/');
		if ($library === '') {
			$library = '/';
		}
		if ($library !== '/' && $path !== $library && !str_starts_with($path, $library . '/')) {
			throw new RuntimeException('The selected folder is outside the configured music library.');
		}
	}

	private function nodeForUserPath(string $userId, string $path): Node {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$relative = ltrim($path, '/');

		return $relative === '' ? $userFolder : $userFolder->get($relative);
	}

	private function normalizePath(string $path): string {
		$segments = [];
		foreach (explode('/', str_replace('\\', '/', trim($path))) as $segment) {
			$segment = trim($segment);
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..') {
				throw new RuntimeException('Parent path segments are not allowed.');
			}
			$segments[] = $segment;
		}

		return $segments === [] ? '/' : '/' . implode('/', $segments);
	}

	private function joinUserPath(string $base, string $name): string {
		return $this->normalizePath(rtrim($base, '/') . '/' . $name);
	}
}
