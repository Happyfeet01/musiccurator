<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Service;

use OCP\Http\Client\IClientService;
use RuntimeException;

class MusicBrainzService {
	private const RETRYABLE_STATUS_CODES = [429, 502, 503, 504];

	public function __construct(
		private IClientService $clientService,
	) {
	}

	/**
	 * @return list<array{id: string, title: string, artist: string, album: string, albumArtist: string, track: string, year: string, genre: string, artworkUrl: string, releaseId: string, releaseGroupId: string, score: int}>
	 */
	public function search(string $title, string $artist = '', string $album = ''): array {
		$title = trim($title);
		$artist = trim($artist);
		$album = trim($album);
		if ($title === '') {
			return [];
		}

		$query = ['recording:"' . $this->escapeQuery($title) . '"'];
		if ($artist !== '') {
			$query[] = 'artist:"' . $this->escapeQuery($artist) . '"';
		}

		$url = 'https://musicbrainz.org/ws/2/recording/?fmt=json&limit=10&query=' . rawurlencode(implode(' AND ', $query));
		$data = $this->requestJson($url);
		$recordings = is_array($data['recordings'] ?? null) ? $data['recordings'] : [];
		$results = [];
		foreach ($recordings as $recording) {
			if (!is_array($recording)) {
				continue;
			}

			$recordingTitle = trim((string)($recording['title'] ?? $title));
			$release = $this->bestRelease(
				is_array($recording['releases'] ?? null) ? $recording['releases'] : [],
				$album,
				$recordingTitle,
			);
			$releaseGroup = is_array($release['release-group'] ?? null) ? $release['release-group'] : [];
			$artistCredit = is_array($recording['artist-credit'] ?? null) ? $recording['artist-credit'] : [];
			$releaseArtistCredit = is_array($release['artist-credit'] ?? null) ? $release['artist-credit'] : [];
			$date = (string)($release['date'] ?? ($recording['first-release-date'] ?? ''));
			$year = preg_match('/\d{4}/', $date, $match) ? $match[0] : '';
			$releaseId = (string)($release['id'] ?? '');

			$results[] = [
				'id' => (string)($recording['id'] ?? ''),
				'title' => $recordingTitle,
				'artist' => $this->artistCreditToString($artistCredit),
				'album' => (string)($release['title'] ?? ''),
				'albumArtist' => $this->artistCreditToString($releaseArtistCredit),
				'track' => '',
				'year' => $year,
				'genre' => '',
				'artworkUrl' => $releaseId !== '' ? 'https://coverartarchive.org/release/' . rawurlencode($releaseId) . '/front-250' : '',
				'releaseId' => $releaseId,
				'releaseGroupId' => (string)($releaseGroup['id'] ?? ''),
				'score' => max(0, min(100, (int)($recording['score'] ?? 0))),
			];

			if (count($results) >= 5) {
				break;
			}
		}

		// Only enrich the best MusicBrainz recording. This keeps batch lookups
		// reasonably light while fixing the common case where a release has no
		// genres but its release group does.
		if (isset($results[0])) {
			$releaseGroupId = trim((string)($results[0]['releaseGroupId'] ?? ''));
			if ($releaseGroupId !== '') {
				try {
					$results[0]['genre'] = $this->releaseGroupGenre($releaseGroupId);
				} catch (RuntimeException) {
					// Genre enrichment is optional; the recording match remains useful.
				}
			}
		}

		return $results;
	}

	/**
	 * Releases themselves often have no genre tags even when the logical
	 * release group does. Query the release group explicitly and normalize its
	 * MusicBrainz genres for use as a fallback.
	 */
	public function releaseGroupGenre(string $releaseGroupId): string {
		$releaseGroupId = trim($releaseGroupId);
		if ($releaseGroupId === '') {
			return '';
		}

		// Respect MusicBrainz API etiquette when this lookup follows a recording
		// search in the same metadata request.
		usleep(1_100_000);

		$url = 'https://musicbrainz.org/ws/2/release-group/' . rawurlencode($releaseGroupId) . '?inc=genres&fmt=json';
		$data = $this->requestJson($url);
		$genres = is_array($data['genres'] ?? null) ? $data['genres'] : [];
		$names = [];
		foreach ($genres as $genre) {
			if (!is_array($genre)) {
				continue;
			}
			$name = trim((string)($genre['name'] ?? ''));
			if ($name !== '') {
				$names[] = $name;
			}
		}

		return GenreNormalizer::normalize($names);
	}

	/** @return array<string, mixed> */
	private function requestJson(string $url): array {
		$client = $this->clientService->newClient();
		$lastStatus = 0;
		$lastBody = '';
		$delays = [1_500_000, 3_000_000];

		for ($attempt = 0; $attempt < 3; ++$attempt) {
			$response = $client->get($url, [
				'headers' => [
					'Accept' => 'application/json',
					'User-Agent' => 'MusicCurator/0.2.14 (https://github.com/Happyfeet01/musiccurator)',
				],
				'connect_timeout' => 8,
				'timeout' => 15,
				'http_errors' => false,
			]);

			$lastStatus = $response->getStatusCode();
			$lastBody = (string)$response->getBody();
			if ($lastStatus >= 200 && $lastStatus < 300) {
				$data = json_decode($lastBody, true, 512, JSON_THROW_ON_ERROR);
				return is_array($data) ? $data : [];
			}

			if ($attempt < 2 && in_array($lastStatus, self::RETRYABLE_STATUS_CODES, true)) {
				$retryAfter = (int)$response->getHeader('Retry-After');
				$serverDelay = $retryAfter > 0 ? min(6_000_000, $retryAfter * 1_000_000) : 0;
				usleep(max($delays[$attempt], $serverDelay));
				continue;
			}

			break;
		}

		$details = trim(strip_tags($lastBody));
		if (strlen($details) > 180) {
			$details = substr($details, 0, 180) . '…';
		}

		throw new RuntimeException(sprintf(
			'MusicBrainz returned HTTP %d%s',
			$lastStatus,
			$details !== '' ? ': ' . $details : '',
		));
	}

	/**
	 * Prefer a release that represents the song itself instead of an arbitrary
	 * later compilation. If an album tag already exists, that explicit album is
	 * still the strongest signal.
	 *
	 * @param array<int, mixed> $releases
	 * @return array<string, mixed>
	 */
	private function bestRelease(array $releases, string $preferredAlbum, string $recordingTitle): array {
		$best = [];
		$bestScore = PHP_INT_MIN;
		$bestDate = '9999-99-99';
		$recordingKey = $this->canonicalTitle($recordingTitle);

		foreach ($releases as $release) {
			if (!is_array($release)) {
				continue;
			}

			$releaseTitle = trim((string)($release['title'] ?? ''));
			$releaseGroup = is_array($release['release-group'] ?? null) ? $release['release-group'] : [];
			$releaseGroupTitle = trim((string)($releaseGroup['title'] ?? ''));
			$primaryType = mb_strtolower(trim((string)($releaseGroup['primary-type'] ?? '')));
			$secondaryTypes = is_array($releaseGroup['secondary-types'] ?? null) ? $releaseGroup['secondary-types'] : [];
			$status = mb_strtolower(trim((string)($release['status'] ?? '')));
			$date = trim((string)($release['date'] ?? '9999-99-99')) ?: '9999-99-99';

			$score = 0;
			if ($preferredAlbum !== '' && strcasecmp($releaseTitle, $preferredAlbum) === 0) {
				$score += 200;
			}
			if ($recordingKey !== '' && $this->canonicalTitle($releaseGroupTitle) === $recordingKey) {
				$score += 80;
			}
			if ($recordingKey !== '' && $this->canonicalTitle($releaseTitle) === $recordingKey) {
				$score += 60;
			}
			if ($primaryType === 'single') {
				$score += 20;
			}
			if ($status === 'official') {
				$score += 8;
			}
			foreach ($secondaryTypes as $secondaryType) {
				if (mb_strtolower(trim((string)$secondaryType)) === 'compilation') {
					$score -= 35;
					break;
				}
			}

			if ($score > $bestScore || ($score === $bestScore && strcmp($date, $bestDate) < 0)) {
				$best = $release;
				$bestScore = $score;
				$bestDate = $date;
			}
		}

		return $best;
	}

	private function canonicalTitle(string $value): string {
		$value = mb_strtolower(trim($value));
		$value = preg_replace('/\s*[\(\[]\s*(?:single version|single edit|radio edit|radio version|album version|original version|remaster(?:ed)?(?: \d{4})?|official video|official audio|lyric video)\s*[\)\]]\s*$/ui', '', $value) ?? $value;
		$value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? $value;
		return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
	}

	/**
	 * @param array<int, mixed> $credit
	 */
	private function artistCreditToString(array $credit): string {
		$value = '';
		foreach ($credit as $part) {
			if (is_string($part)) {
				$value .= $part;
				continue;
			}
			if (!is_array($part)) {
				continue;
			}
			$value .= (string)($part['name'] ?? ($part['artist']['name'] ?? ''));
			$value .= (string)($part['joinphrase'] ?? '');
		}
		return trim($value);
	}

	private function escapeQuery(string $value): string {
		return str_replace(['\\', '"'], ['\\\\', '\\"'], trim($value));
	}
}
