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
	 * @return array{results: list<array<string, mixed>>, providers: list<array<string, mixed>>, query: array{title: string, artist: string, album: string, source: string, inputConflict: bool}}
	 */
	public function search(string $userId, File $file, array $tags, string $title): array {
		$tagArtist = trim((string)($tags['artist'] ?? ''));
		$tagAlbum = trim((string)($tags['album'] ?? ''));
		$tagTitle = trim($title);

		// Downloads often contain a useful "Artist - Title" string but no
		// separate artist tag. Split common separators before querying providers
		// so Last.fm, MusicBrainz and Discogs can still match these files.
		[$tagTitle, $tagArtist] = $this->inferArtistAndTitle($tagTitle, $tagArtist);

		$filenameStem = $this->cleanFilenameStem(pathinfo($file->getName(), PATHINFO_FILENAME));
		[$filenameTitle, $filenameArtist] = $this->inferArtistAndTitle($filenameStem, '');

		$title = $tagTitle;
		$artist = $tagArtist;
		$album = $tagAlbum;
		$querySource = 'tags';
		$inputConflict = false;

		// Existing tags are not automatically trustworthy: MusicCurator is often
		// used precisely because downloaded files contain somebody else's tags.
		// A clearly structured "Artist - Title" filename is valuable independent
		// evidence. If it strongly disagrees with the embedded tags, search from
		// the filename and force manual review instead of treating a provider's
		// exact match to the bad tags as safe.
		if ($filenameArtist !== '' && $filenameTitle !== '') {
			if ($artist === '' || $title === '') {
				$title = $filenameTitle;
				$artist = $filenameArtist;
				$querySource = 'filename';
			} elseif ($this->metadataConflict($tagTitle, $tagArtist, $filenameTitle, $filenameArtist)) {
				$title = $filenameTitle;
				$artist = $filenameArtist;
				$album = '';
				$querySource = 'filename';
				$inputConflict = true;
			}
		}

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

		// Genre evidence has its own priority and is deliberately independent of
		// which provider wins the recording match. Prefer MusicBrainz release-group
		// genres, then Last.fm track/artist tags. Discogs remains an optional extra
		// signal when the user configured a token. Fuzzy title/artist matching lets
		// equivalent rows share genre evidence across providers without blindly
		// applying genres to covers or unrelated recordings.
		$results = $this->enrichGenres($results);

		// When a strong Chromaprint/AcoustID result exists, it is evidence from the
		// actual audio rather than filenames or embedded tags. Conflicting textual
		// matches are deliberately demoted so a 100% MusicBrainz search score can no
		// longer beat a strong fingerprint for a completely different recording.
		$results = $this->applyFingerprintEvidence($results);

		foreach ($results as &$row) {
			$row['querySource'] = $querySource;
			$row['inputConflict'] = $inputConflict;
			$fingerprintConfirmed = (bool)($row['fingerprintConfirmed'] ?? false);
			$fingerprintConflict = (bool)($row['fingerprintConflict'] ?? false);
			$row['autoAccept'] = !$fingerprintConflict && (!$inputConflict || $fingerprintConfirmed);
		}
		unset($row);

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
				'source' => $querySource,
				'inputConflict' => $inputConflict,
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
		$genreRows = array_values(array_filter(
			$rows,
			static fn (array $row): bool => trim((string)($row['genre'] ?? '')) !== '',
		));

		foreach ($rows as &$row) {
			if (trim((string)($row['genre'] ?? '')) !== '') {
				continue;
			}

			$bestGenre = '';
			$bestScore = 0.0;
			$bestPriority = PHP_INT_MAX;
			foreach ($genreRows as $genreRow) {
				$matchScore = $this->genreMatchScore($row, $genreRow);
				if ($matchScore <= 0.0) {
					continue;
				}

				$priority = $this->genreSourcePriority((string)($genreRow['source'] ?? ''));
				if ($priority < $bestPriority || ($priority === $bestPriority && $matchScore > $bestScore)) {
					$bestPriority = $priority;
					$bestScore = $matchScore;
					$bestGenre = trim((string)($genreRow['genre'] ?? ''));
				}
			}

			if ($bestGenre !== '') {
				$row['genre'] = $bestGenre;
			}
		}
		unset($row);

		return $rows;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 * @return list<array<string, mixed>>
	 */
	private function applyFingerprintEvidence(array $rows): array {
		$fingerprint = null;
		foreach ($rows as $row) {
			if ((string)($row['source'] ?? '') !== 'AcoustID' || (int)($row['score'] ?? 0) < 90) {
				continue;
			}
			if (trim((string)($row['title'] ?? '')) === '' || trim((string)($row['artist'] ?? '')) === '') {
				continue;
			}
			$fingerprint = $row;
			break;
		}

		if ($fingerprint === null) {
			return $rows;
		}

		foreach ($rows as &$row) {
			if ((string)($row['source'] ?? '') === 'AcoustID') {
				$row['fingerprintConfirmed'] = $this->recordingsMatch($row, $fingerprint);
				$row['fingerprintConflict'] = false;
				continue;
			}

			if ($this->recordingsMatch($row, $fingerprint)) {
				$row['fingerprintConfirmed'] = true;
				$row['fingerprintConflict'] = false;
				continue;
			}

			$row['fingerprintConfirmed'] = false;
			$row['fingerprintConflict'] = true;
			$row['score'] = min(70, (int)($row['score'] ?? 0));
		}
		unset($row);

		return $rows;
	}

	/**
	 * Return a positive score only when two provider rows are sufficiently
	 * similar to safely share broad genre metadata.
	 *
	 * @param array<string, mixed> $target
	 * @param array<string, mixed> $source
	 */
	private function genreMatchScore(array $target, array $source): float {
		$targetTitle = $this->normalizeTitleForGenre((string)($target['title'] ?? ''));
		$sourceTitle = $this->normalizeTitleForGenre((string)($source['title'] ?? ''));
		$targetArtist = $this->normalizeArtistForGenre((string)($target['artist'] ?? ($target['albumArtist'] ?? '')));
		$sourceArtist = $this->normalizeArtistForGenre((string)($source['artist'] ?? ($source['albumArtist'] ?? '')));

		if ($targetTitle === '' || $sourceTitle === '' || $targetArtist === '' || $sourceArtist === '') {
			return 0.0;
		}

		$titleScore = 0.0;
		$artistScore = 0.0;
		similar_text($targetTitle, $sourceTitle, $titleScore);
		similar_text($targetArtist, $sourceArtist, $artistScore);

		// Require both dimensions to be credible. A nearly exact title can allow
		// slightly more artist variation (stylized names), but never a completely
		// different performer, which prevents genre bleed between cover versions.
		if (($titleScore < 82.0 || $artistScore < 75.0)
			&& ($titleScore < 96.0 || $artistScore < 65.0)) {
			return 0.0;
		}

		return ($titleScore * 0.65) + ($artistScore * 0.35);
	}

	/** @param array<string, mixed> $a @param array<string, mixed> $b */
	private function recordingsMatch(array $a, array $b): bool {
		$titleA = $this->normalizeTitleForGenre((string)($a['title'] ?? ''));
		$titleB = $this->normalizeTitleForGenre((string)($b['title'] ?? ''));
		$artistA = $this->normalizeArtistForGenre((string)($a['artist'] ?? ($a['albumArtist'] ?? '')));
		$artistB = $this->normalizeArtistForGenre((string)($b['artist'] ?? ($b['albumArtist'] ?? '')));
		if ($titleA === '' || $titleB === '' || $artistA === '' || $artistB === '') {
			return false;
		}

		$titleScore = 0.0;
		$artistScore = 0.0;
		similar_text($titleA, $titleB, $titleScore);
		similar_text($artistA, $artistB, $artistScore);

		return ($titleScore >= 82.0 && $artistScore >= 75.0)
			|| ($titleScore >= 96.0 && $artistScore >= 65.0);
	}

	private function metadataConflict(string $tagTitle, string $tagArtist, string $filenameTitle, string $filenameArtist): bool {
		$tagTitle = $this->normalizeTitleForGenre($tagTitle);
		$filenameTitle = $this->normalizeTitleForGenre($filenameTitle);
		$tagArtist = $this->normalizeArtistForGenre($tagArtist);
		$filenameArtist = $this->normalizeArtistForGenre($filenameArtist);
		if ($tagTitle === '' || $filenameTitle === '' || $tagArtist === '' || $filenameArtist === '') {
			return false;
		}

		$titleScore = 0.0;
		$artistScore = 0.0;
		similar_text($tagTitle, $filenameTitle, $titleScore);
		similar_text($tagArtist, $filenameArtist, $artistScore);

		return $titleScore < 45.0 || $artistScore < 45.0;
	}

	private function cleanFilenameStem(string $value): string {
		$value = trim($value);
		$value = preg_replace('/^\s*(?:\d{1,3}\s*[._-]\s*)+/u', '', $value) ?? $value;
		$value = preg_replace('/\s+/u', ' ', $value) ?? $value;
		return trim($value);
	}

	private function normalizeTitleForGenre(string $value): string {
		$value = mb_strtolower(trim($value));
		$value = preg_replace(
			'/\s*[\(\[][^\)\]]*(?:single\s+version|radio\s+edit|album\s+version|remaster(?:ed)?(?:\s+\d{4})?|official(?:\s+(?:video|audio))?|lyric(?:s)?(?:\s+video)?)[^\)\]]*[\)\]]\s*$/u',
			'',
			$value,
		) ?? $value;
		return $this->normalizeForKey($value);
	}

	private function normalizeArtistForGenre(string $value): string {
		$value = mb_strtolower(trim($value));
		// Common artist-name stylisation: Ke$ha -> Kesha.
		$value = str_replace('$', 's', $value);
		return $this->normalizeForKey($value);
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
			'MusicBrainz' => 0,
			'Last.fm' => 1,
			'Discogs' => 2,
			'AcoustID' => 3,
			default => 9,
		};
	}
}
