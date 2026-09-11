<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Service;

use OCP\Http\Client\IClientService;
use RuntimeException;

class DiscogsService {
	private const RETRYABLE_STATUS_CODES = [429, 500, 502, 503, 504];

	public function __construct(
		private IClientService $clientService,
	) {
	}

	/** @return list<array<string, mixed>> */
	public function search(string $title, string $artist, string $album, string $token): array {
		$title = trim($title);
		$artist = trim($artist);
		$album = trim($album);
		$token = trim($token);
		if ($title === '' || $token === '') {
			return [];
		}

		// Do not use the local album tag as a hard search condition. Playlist
		// downloads commonly carry playlist/compilation names that do not match
		// the original Discogs release. Album similarity is only a score bonus.
		$params = [
			'type' => 'release',
			'track' => $title,
			'per_page' => '5',
		];
		if ($artist !== '') {
			$params['artist'] = $artist;
		}

		$url = 'https://api.discogs.com/database/search?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
		$data = $this->requestJson($url, $token);
		$rows = is_array($data['results'] ?? null) ? $data['results'] : [];
		$results = [];

		foreach (array_slice($rows, 0, 5) as $index => $row) {
			if (!is_array($row)) {
				continue;
			}

			$releaseId = (string)($row['id'] ?? '');
			$releaseTitle = (string)($row['title'] ?? '');
			[$releaseArtist, $albumTitle] = $this->splitReleaseTitle($releaseTitle);
			$trackTitle = $title;
			$trackNumber = '';
			$year = (string)($row['year'] ?? '');
			$sourceUrl = $this->discogsUrl((string)($row['uri'] ?? ''));
			$artworkUrl = $this->httpsImage((string)($row['cover_image'] ?? ($row['thumb'] ?? '')));
			$genreValues = [
				...(is_array($row['genre'] ?? null) ? $row['genre'] : []),
				...(is_array($row['style'] ?? null) ? $row['style'] : []),
			];

			if ($releaseId !== '' && $index < 3) {
				try {
					$details = $this->requestJson('https://api.discogs.com/releases/' . rawurlencode($releaseId), $token);
					$albumTitle = trim((string)($details['title'] ?? $albumTitle));
					$year = trim((string)($details['year'] ?? $year));
					$releaseArtist = $this->artistsToString(is_array($details['artists'] ?? null) ? $details['artists'] : []) ?: $releaseArtist;
					$sourceUrl = trim((string)($details['uri'] ?? $sourceUrl));
					$detailArtwork = $this->detailArtwork(is_array($details['images'] ?? null) ? $details['images'] : []);
					if ($detailArtwork !== '') {
						$artworkUrl = $detailArtwork;
					}
					$genreValues = [
						...(is_array($details['genres'] ?? null) ? $details['genres'] : []),
						...(is_array($details['styles'] ?? null) ? $details['styles'] : []),
						...$genreValues,
					];
					[$trackTitle, $trackNumber] = $this->bestTrack(
						is_array($details['tracklist'] ?? null) ? $details['tracklist'] : [],
						$title,
					);
				} catch (RuntimeException) {
					// Search metadata alone is still useful if release details fail.
				}
			}

			$score = max(55, 92 - ($index * 5));
			$score += $this->similarityBonus($artist, $releaseArtist, 5);
			$score += $this->similarityBonus($album, $albumTitle, 2);
			$score = min(99, $score);

			$results[] = [
				'id' => 'discogs:' . $releaseId . ':' . $trackNumber,
				'title' => $trackTitle !== '' ? $trackTitle : $title,
				'artist' => $releaseArtist !== '' ? $releaseArtist : $artist,
				'album' => $albumTitle,
				'albumArtist' => $releaseArtist,
				'track' => $trackNumber,
				'year' => preg_match('/^\d{4}$/', $year) ? $year : '',
				'genre' => GenreNormalizer::normalize($genreValues),
				'artworkUrl' => $artworkUrl,
				'releaseId' => $releaseId,
				'releaseGroupId' => (string)($row['master_id'] ?? ''),
				'score' => $score,
				'source' => 'Discogs',
				'sourceUrl' => $sourceUrl,
			];
		}

		return $results;
	}

	/** @return array<string, mixed> */
	private function requestJson(string $url, string $token): array {
		$client = $this->clientService->newClient();
		$lastStatus = 0;
		for ($attempt = 0; $attempt < 2; ++$attempt) {
			$response = $client->get($url, [
				'headers' => [
					'Accept' => 'application/json',
					'Authorization' => 'Discogs token=' . $token,
					'User-Agent' => 'MusicCurator/0.2.12 +https://github.com/Happyfeet01/musiccurator',
				],
				'connect_timeout' => 8,
				'timeout' => 15,
				'http_errors' => false,
			]);

			$lastStatus = $response->getStatusCode();
			$body = (string)$response->getBody();
			if ($lastStatus >= 200 && $lastStatus < 300) {
				$data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
				return is_array($data) ? $data : [];
			}
			if ($attempt === 0 && in_array($lastStatus, self::RETRYABLE_STATUS_CODES, true)) {
				$retryAfter = (int)$response->getHeader('Retry-After');
				usleep(max(1_100_000, min(3_000_000, $retryAfter * 1_000_000)));
				continue;
			}
			break;
		}

		throw new RuntimeException(sprintf('Discogs returned HTTP %d', $lastStatus));
	}

	/** @return array{0: string, 1: string} */
	private function splitReleaseTitle(string $value): array {
		$parts = preg_split('/\s+-\s+/', $value, 2);
		if (!is_array($parts) || count($parts) < 2) {
			return ['', trim($value)];
		}
		return [trim((string)$parts[0]), trim((string)$parts[1])];
	}

	/** @param array<int, mixed> $artists */
	private function artistsToString(array $artists): string {
		$names = [];
		foreach ($artists as $artist) {
			if (is_array($artist) && isset($artist['name'])) {
				$names[] = trim((string)$artist['name']);
			}
		}
		return implode(', ', array_filter($names));
	}

	/** @param array<int, mixed> $images */
	private function detailArtwork(array $images): string {
		foreach ($images as $image) {
			if (!is_array($image)) {
				continue;
			}
			$url = $this->httpsImage((string)($image['uri150'] ?? ($image['resource_url'] ?? ($image['uri'] ?? ''))));
			if ($url !== '') {
				return $url;
			}
		}

		return '';
	}

	private function httpsImage(string $url): string {
		$url = trim($url);

		return str_starts_with($url, 'https://') ? $url : '';
	}

	/** @param array<int, mixed> $tracklist
	 * @return array{0: string, 1: string}
	 */
	private function bestTrack(array $tracklist, string $wantedTitle): array {
		$wanted = $this->normalize($wantedTitle);
		$bestTitle = $wantedTitle;
		$bestPosition = '';
		$bestScore = -1.0;
		foreach ($tracklist as $track) {
			if (!is_array($track)) {
				continue;
			}
			$candidate = trim((string)($track['title'] ?? ''));
			if ($candidate === '') {
				continue;
			}
			$score = 0.0;
			similar_text($wanted, $this->normalize($candidate), $score);
			if ($score > $bestScore) {
				$bestScore = $score;
				$bestTitle = $candidate;
				$bestPosition = trim((string)($track['position'] ?? ''));
			}
		}
		return [$bestTitle, $bestScore >= 65.0 ? $bestPosition : ''];
	}

	private function similarityBonus(string $wanted, string $candidate, int $maximum): int {
		if ($wanted === '' || $candidate === '') {
			return 0;
		}
		$score = 0.0;
		similar_text($this->normalize($wanted), $this->normalize($candidate), $score);
		return (int)round(($score / 100) * $maximum);
	}

	private function normalize(string $value): string {
		$value = mb_strtolower(trim($value));
		$value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? $value;
		return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
	}

	private function discogsUrl(string $uri): string {
		$uri = trim($uri);
		if ($uri === '') {
			return '';
		}
		if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
			return $uri;
		}
		return 'https://www.discogs.com' . (str_starts_with($uri, '/') ? '' : '/') . $uri;
	}
}
