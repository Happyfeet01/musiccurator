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
	 * @return list<array{id: string, title: string, artist: string, album: string, albumArtist: string, track: string, year: string, releaseId: string, releaseGroupId: string, score: int}>
	 */
	public function search(string $title, string $artist = '', string $album = ''): array {
		$title = trim($title);
		$artist = trim($artist);
		$album = trim($album);
		if ($title === '') {
			return [];
		}

		// Album tags from downloads are often inconsistent (playlist names,
		// deluxe labels, YouTube titles, etc.). Do not make the release name a
		// hard MusicBrainz search constraint. Prefer title + artist and use the
		// existing album only to choose the most plausible release afterwards.
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

			$release = $this->bestRelease(
				is_array($recording['releases'] ?? null) ? $recording['releases'] : [],
				$album,
			);
			$releaseGroup = is_array($release['release-group'] ?? null) ? $release['release-group'] : [];
			$artistCredit = is_array($recording['artist-credit'] ?? null) ? $recording['artist-credit'] : [];
			$releaseArtistCredit = is_array($release['artist-credit'] ?? null) ? $release['artist-credit'] : [];
			$date = (string)($release['date'] ?? ($recording['first-release-date'] ?? ''));
			$year = preg_match('/\d{4}/', $date, $match) ? $match[0] : '';

			$results[] = [
				'id' => (string)($recording['id'] ?? ''),
				'title' => (string)($recording['title'] ?? ''),
				'artist' => $this->artistCreditToString($artistCredit),
				'album' => (string)($release['title'] ?? ''),
				'albumArtist' => $this->artistCreditToString($releaseArtistCredit),
				'track' => '',
				'year' => $year,
				'releaseId' => (string)($release['id'] ?? ''),
				'releaseGroupId' => (string)($releaseGroup['id'] ?? ''),
				'score' => max(0, min(100, (int)($recording['score'] ?? 0))),
			];

			if (count($results) >= 5) {
				break;
			}
		}

		return $results;
	}

	/** @return array<string, mixed> */
	private function requestJson(string $url): array {
		$client = $this->clientService->newClient();
		$lastStatus = 0;
		$lastBody = '';

		for ($attempt = 0; $attempt < 2; ++$attempt) {
			$response = $client->get($url, [
				'headers' => [
					'Accept' => 'application/json',
					'User-Agent' => 'MusicCurator/0.2.0 (https://github.com/Happyfeet01/musiccurator)',
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

			if ($attempt === 0 && in_array($lastStatus, self::RETRYABLE_STATUS_CODES, true)) {
				$retryAfter = (int)$response->getHeader('Retry-After');
				$waitMicroseconds = max(1_100_000, min(3_000_000, $retryAfter * 1_000_000));
				usleep($waitMicroseconds);
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
	 * @param array<int, mixed> $releases
	 * @return array<string, mixed>
	 */
	private function bestRelease(array $releases, string $preferredAlbum): array {
		$first = [];
		foreach ($releases as $release) {
			if (!is_array($release)) {
				continue;
			}
			if ($first === []) {
				$first = $release;
			}
			if ($preferredAlbum !== '' && strcasecmp(trim((string)($release['title'] ?? '')), $preferredAlbum) === 0) {
				return $release;
			}
		}

		return $first;
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
