<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Service;

use OCP\Http\Client\IClientService;
use RuntimeException;

class DiscogsService {
	public function __construct(
		private IClientService $clientService,
	) {
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function search(string $title, string $artist, string $album, string $token): array {
		$title = trim($title);
		$artist = trim($artist);
		$album = trim($album);
		$token = trim($token);
		if ($title === '' || $token === '') {
			return [];
		}

		$params = [
			'type' => 'release',
			'track' => $title,
			'per_page' => '5',
		];
		if ($artist !== '') {
			$params['artist'] = $artist;
		}
		if ($album !== '') {
			$params['release_title'] = $album;
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

			if ($releaseId !== '' && $index < 3) {
				try {
					$details = $this->requestJson('https://api.discogs.com/releases/' . rawurlencode($releaseId), $token);
					$albumTitle = trim((string)($details['title'] ?? $albumTitle));
					$year = trim((string)($details['year'] ?? $year));
					$releaseArtist = $this->artistsToString(is_array($details['artists'] ?? null) ? $details['artists'] : []) ?: $releaseArtist;
					$sourceUrl = trim((string)($details['uri'] ?? $sourceUrl));
					[$trackTitle, $trackNumber] = $this->bestTrack(
						is_array($details['tracklist'] ?? null) ? $details['tracklist'] : [],
						$title,
					);
				} catch (RuntimeException) {
					// The search result is still useful if fetching release details fails.
				}
			}

			$score = max(55, 94 - ($index * 5));
			$score += $this->similarityBonus($artist, $releaseArtist, 4);
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
		$response = $client->get($url, [
			'headers' => [
				'Accept' => 'application/json',
				'Authorization' => 'Discogs token=' . $token,
				'User-Agent' => 'MusicCurator/0.2.0 +https://github.com/Happyfeet01/musiccurator',
			],
			'connect_timeout' => 8,
			'timeout' => 15,
			'http_errors' => false,
		]);

		$status = $response->getStatusCode();
		$body = (string)$response->getBody();
		if ($status < 200 || $status >= 300) {
			throw new RuntimeException(sprintf('Discogs returned HTTP %d', $status));
		}

		$data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		return is_array($data) ? $data : [];
	}

	/** @return array{0: string, 1: string} */
	private function splitReleaseTitle(string $value): array {
		$parts = preg_split('/\s+-\s+/', $value, 2);
		if (!is_array($parts) || count($parts) < 2) {
			return ['', trim($value)];
		}

		return [trim((string)$parts[0]), trim((string)$parts[1])];
	}

	/**
	 * @param array<int, mixed> $artists
	 */
	private function artistsToString(array $artists): string {
		$names = [];
		foreach ($artists as $artist) {
			if (is_array($artist) && isset($artist['name'])) {
				$names[] = trim((string)$artist['name']);
			}
		}

		return implode(', ', array_filter($names));
	}

	/**
	 * @param array<int, mixed> $tracklist
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
