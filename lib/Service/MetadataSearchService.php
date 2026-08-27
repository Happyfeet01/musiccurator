<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Service;

use OCP\Files\File;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

class MetadataSearchService {
	public function __construct(
		private IConfig $config,
		private ProviderCredentialsService $credentials,
		private MusicBrainzService $musicBrainz,
		private DiscogsService $discogs,
		private LastFmService $lastFm,
		private AcoustIdService $acoustId,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<string, mixed> $tags
	 * @return array{results: list<array<string, mixed>>, providers: list<array<string, mixed>>, query: array{title: string, artist: string, album: string}}
	 */
	public function search(string $userId, File $file, array $tags, string $title): array {
		$artist = trim((string)($tags['artist'] ?? ''));
		$album = trim((string)($tags['album'] ?? ''));
		$title = trim($title);

		// Downloads often contain a useful "Artist - Title" string but no
		// separate artist tag. Split common separators before querying providers
		// so Last.fm, MusicBrainz and Discogs can still match these files.
		[$title, $artist] = $this->inferArtistAndTitle($title, $artist);

		$results = [];
		$providers = [];

		$musicBrainzEnabled = $this->config->getUserValue($userId, 'musiccurator', 'musicbrainz_enabled', '1') === '1';
		if ($musicBrainzEnabled) {
			$this->runProvider('MusicBrainz', true, function () use ($title, $artist, $album): array {
				$rows = $this->musicBrainz->search($title, $artist, $album);
				return array_map(static fn (array $row): array => $row + [
					'genre' => '',
					'source' => 'MusicBrainz',
					'sourceUrl' => isset($row['id']) && $row['id'] !== '' ? 'https://musicbrainz.org/recording/' . rawurlencode((string)$row['id']) : '',
				], $rows);
			}, $results, $providers);
		} else {
			$providers[] = $this->status('MusicBrainz', false, false, false, 'Disabled in personal settings.');
		}

		$discogsToken = $this->credentials->get($userId, 'discogs_token');
		if ($discogsToken !== '') {
			$this->runProvider('Discogs', true, fn (): array => $this->discogs->search($title, $artist, $album, $discogsToken), $results, $providers);
		} else {
			$providers[] = $this->status('Discogs', false, false, false, 'No personal token configured.');
		}

		$lastFmKey = $this->credentials->get($userId, 'lastfm_key');
		if ($lastFmKey !== '') {
			$this->runProvider('Last.fm', true, fn (): array => $this->lastFm->search($title, $artist, $lastFmKey), $results, $providers);
		} else {
			$providers[] = $this->status('Last.fm', false, false, false, 'No API key configured.');
		}

		$acoustIdKey = $this->credentials->get($userId, 'acoustid_key');
		if ($acoustIdKey !== '') {
			$this->runProvider('AcoustID', true, fn (): array => $this->acoustId->identify($file, $acoustIdKey), $results, $providers);
		} else {
			$providers[] = $this->status('AcoustID', false, false, false, 'No client/API key configured.');
		}

		// Discogs and Last.fm often know the genre while MusicBrainz/AcoustID may
		// win the actual recording match. Share a normalized genre only between
		// candidates whose normalized title + artist agree, so a high-confidence
		// recording can still carry the genre evidence from another provider.
		$results = $this->enrichGenres($results);

		usort($results, function (array $a, array $b): int {
			$score = ((int)($b['score'] ?? 0)) <=> ((int)($a['score'] ?? 0));
			if ($score !== 0) {
				return $score;
			}
			return $this->sourcePriority((string)($a['source'] ?? '')) <=> $this->sourcePriority((string)($b['source'] ?? ''));
		});

		return [
			'results' => array_slice($this->deduplicate($results), 0, 20),
			'providers' => $providers,
			'query' => [
				'title' => $title,
				'artist' => $artist,
				'album' => $album,
			],
		];
	}

	/**
	 * @param callable(): array $callback
	 * @param list<array<string, mixed>> $results
	 * @param list<array<string, mixed>> $providers
	 */
	private function runProvider(string $name, bool $configured, callable $callback, array &$results, array &$providers): void {
		$started = microtime(true);
		try {
			$rows = $callback();
			foreach ($rows as $row) {
				if (is_array($row)) {
					$results[] = $row;
				}
			}
			$providers[] = $this->status($name, $configured, true, true, '', count($rows), $started);
		} catch (Throwable $e) {
			$this->logger->warning('MusicCurator metadata provider failed', [
				'app' => 'musiccurator',
				'provider' => $name,
				'exception' => $e,
			]);
			$providers[] = $this->status($name, $configured, true, false, $e->getMessage(), 0, $started);
		}
	}

	/** @return array<string, mixed> */
	private function status(
		string $name,
		bool $configured,
		bool $attempted,
		bool $ok,
		string $message,
		int $results = 0,
		?float $started = null,
	): array {
		return [
			'name' => $name,
			'configured' => $configured,
			'attempted' => $attempted,
			'ok' => $ok,
			'results' => $results,
			'message' => $message,
			'durationMs' => $started !== null ? (int)round((microtime(true) - $started) * 1000) : 0,
		];
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	private function enrichGenres(array $rows): array {
		$genres = [];

		// Discogs is deliberately considered before Last.fm because its release
		// genres/styles are curated structured fields, while Last.fm genres are
		// derived from community tags.
		$genreRows = $rows;
		usort($genreRows, fn (array $a, array $b): int => $this->genreSourcePriority((string)($a['source'] ?? '')) <=> $this->genreSourcePriority((string)($b['source'] ?? '')));
		foreach ($genreRows as $row) {
			$genre = trim((string)($row['genre'] ?? ''));
			$key = $this->recordingKey($row);
			if ($genre !== '' && $key !== '' && !isset($genres[$key])) {
				$genres[$key] = $genre;
			}
		}

		foreach ($rows as &$row) {
			if (trim((string)($row['genre'] ?? '')) !== '') {
				continue;
			}
			$key = $this->recordingKey($row);
			if ($key !== '' && isset($genres[$key])) {
				$row['genre'] = $genres[$key];
			}
		}
		unset($row);

		return $rows;
	}

	/** @param array<string, mixed> $row */
	private function recordingKey(array $row): string {
		$title = $this->normalizeForKey((string)($row['title'] ?? ''));
		$artist = $this->normalizeForKey((string)($row['artist'] ?? ($row['albumArtist'] ?? '')));
		if ($title === '' || $artist === '') {
			return '';
		}
		return $title . "\0" . $artist;
	}

	private function normalizeForKey(string $value): string {
		$value = mb_strtolower(trim($value));
		$value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? $value;
		return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	private function deduplicate(array $rows): array {
		$seen = [];
		$out = [];
		foreach ($rows as $row) {
			$key = mb_strtolower(trim((string)($row['source'] ?? '')) . "\0"
				. trim((string)($row['title'] ?? '')) . "\0"
				. trim((string)($row['artist'] ?? '')) . "\0"
				. trim((string)($row['album'] ?? '')));
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$out[] = $row;
		}
		return $out;
	}

	/** @return array{0: string, 1: string} */
	private function inferArtistAndTitle(string $title, string $artist): array {
		if ($artist !== '' || $title === '') {
			return [$title, $artist];
		}

		foreach ([' - ', ' – ', ' — '] as $separator) {
			$position = mb_strpos($title, $separator);
			if ($position === false) {
				continue;
			}

			$possibleArtist = trim(mb_substr($title, 0, $position));
			$possibleTitle = trim(mb_substr($title, $position + mb_strlen($separator)));
			if ($possibleArtist !== '' && $possibleTitle !== '') {
				return [$possibleTitle, $possibleArtist];
			}
		}

		return [$title, $artist];
	}

	private function sourcePriority(string $source): int {
		return match ($source) {
			'AcoustID' => 0,
			'MusicBrainz' => 1,
			'Discogs' => 2,
			'Last.fm' => 3,
			default => 9,
		};
	}

	private function genreSourcePriority(string $source): int {
		return match ($source) {
			'Discogs' => 0,
			'Last.fm' => 1,
			'MusicBrainz' => 2,
			'AcoustID' => 3,
			default => 9,
		};
	}
}
