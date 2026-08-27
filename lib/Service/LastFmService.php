<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Service;

use OCP\Http\Client\IClientService;
use RuntimeException;

class LastFmService {
	public function __construct(
		private IClientService $clientService,
	) {
	}

	/** @return list<array<string, mixed>> */
	public function search(string $title, string $artist, string $apiKey): array {
		$title = trim($title);
		$artist = trim($artist);
		$apiKey = trim($apiKey);
		if ($title === '' || $apiKey === '') {
			return [];
		}

		$params = [
			'method' => 'track.search',
			'track' => $title,
			'api_key' => $apiKey,
			'format' => 'json',
			'limit' => '5',
		];
		if ($artist !== '') {
			$params['artist'] = $artist;
		}

		$data = $this->requestJson('https://ws.audioscrobbler.com/2.0/?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
		$matches = $data['results']['trackmatches']['track'] ?? [];
		if (is_array($matches) && isset($matches['name'])) {
			$matches = [$matches];
		}
		if (!is_array($matches)) {
			return [];
		}

		$results = [];
		foreach (array_slice($matches, 0, 5) as $index => $match) {
			if (!is_array($match)) {
				continue;
			}
			$candidateTitle = trim((string)($match['name'] ?? ''));
			$candidateArtist = trim((string)($match['artist'] ?? ''));
			$url = trim((string)($match['url'] ?? ''));
			$album = '';
			$year = '';
			$genre = '';

			if ($index < 2 && $candidateTitle !== '') {
				try {
					$info = $this->trackInfo($candidateTitle, $candidateArtist, $apiKey);
					$track = is_array($info['track'] ?? null) ? $info['track'] : [];
					$albumInfo = is_array($track['album'] ?? null) ? $track['album'] : [];
					$album = trim((string)($albumInfo['title'] ?? ''));
					$genre = GenreNormalizer::normalize($this->tagNames($track['toptags']['tag'] ?? []));
					if ($url === '') {
						$url = trim((string)($track['url'] ?? ''));
					}
				} catch (RuntimeException) {
					// Search metadata alone is still useful as a fallback.
				}
			}

			$score = $this->score($title, $artist, $candidateTitle, $candidateArtist, $index);
			$results[] = [
				'id' => (string)($match['mbid'] ?? '') ?: 'lastfm:' . sha1($candidateArtist . "\0" . $candidateTitle),
				'title' => $candidateTitle,
				'artist' => $candidateArtist,
				'album' => $album,
				'albumArtist' => $candidateArtist,
				'track' => '',
				'year' => $year,
				'genre' => $genre,
				'releaseId' => '',
				'releaseGroupId' => '',
				'score' => $score,
				'source' => 'Last.fm',
				'sourceUrl' => $url,
			];
		}

		return $results;
	}

	/** @return array<string, mixed> */
	private function trackInfo(string $title, string $artist, string $apiKey): array {
		$params = [
			'method' => 'track.getInfo',
			'track' => $title,
			'artist' => $artist,
			'api_key' => $apiKey,
			'format' => 'json',
			'autocorrect' => '1',
		];

		return $this->requestJson('https://ws.audioscrobbler.com/2.0/?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
	}

	/**
	 * @param mixed $tags
	 * @return list<string>
	 */
	private function tagNames(mixed $tags): array {
		if (!is_array($tags)) {
			return [];
		}
		if (isset($tags['name'])) {
			$tags = [$tags];
		}

		$names = [];
		foreach ($tags as $tag) {
			if (is_array($tag) && isset($tag['name'])) {
				$name = trim((string)$tag['name']);
				if ($name !== '') {
					$names[] = $name;
				}
			}
		}
		return $names;
	}

	/** @return array<string, mixed> */
	private function requestJson(string $url): array {
		$client = $this->clientService->newClient();
		$response = $client->get($url, [
			'headers' => [
				'Accept' => 'application/json',
				'User-Agent' => 'MusicCurator/0.2.8 (https://github.com/Happyfeet01/musiccurator)',
			],
			'connect_timeout' => 8,
			'timeout' => 15,
			'http_errors' => false,
		]);

		$status = $response->getStatusCode();
		$body = (string)$response->getBody();
		if ($status < 200 || $status >= 300) {
			throw new RuntimeException(sprintf('Last.fm returned HTTP %d', $status));
		}
		$data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		if (is_array($data) && isset($data['error'])) {
			throw new RuntimeException('Last.fm error: ' . (string)($data['message'] ?? $data['error']));
		}
		return is_array($data) ? $data : [];
	}

	private function score(string $wantedTitle, string $wantedArtist, string $title, string $artist, int $index): int {
		$titleScore = 0.0;
		$artistScore = 0.0;
		similar_text($this->normalize($wantedTitle), $this->normalize($title), $titleScore);
		if ($wantedArtist !== '' && $artist !== '') {
			similar_text($this->normalize($wantedArtist), $this->normalize($artist), $artistScore);
		} else {
			$artistScore = 70.0;
		}
		$score = (int)round(($titleScore * 0.7) + ($artistScore * 0.3)) - ($index * 2);
		return max(45, min(99, $score));
	}

	private function normalize(string $value): string {
		$value = mb_strtolower(trim($value));
		$value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? $value;
		return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
	}
}
