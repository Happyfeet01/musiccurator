<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Service;

use OCP\Files\File;
use OCP\Http\Client\IClientService;
use OCP\ITempManager;
use RuntimeException;

class AcoustIdService {
	public function __construct(
		private IClientService $clientService,
		private ITempManager $tempManager,
	) {
	}

	/** @return list<array<string, mixed>> */
	public function identify(File $file, string $clientKey): array {
		$clientKey = trim($clientKey);
		if ($clientKey === '') {
			return [];
		}

		$fpcalc = $this->fpcalcPath();
		if ($fpcalc === '') {
			throw new RuntimeException('AcoustID is configured, but fpcalc (Chromaprint) is not installed on the server.');
		}

		$temp = $this->tempManager->getTemporaryFile('.' . $file->getExtension());
		if ($temp === false) {
			throw new RuntimeException('Could not create a temporary file for audio fingerprinting.');
		}

		$input = $file->fopen('r');
		$output = fopen($temp, 'wb');
		if (!is_resource($input) || !is_resource($output)) {
			throw new RuntimeException('Could not open the audio file for fingerprinting.');
		}
		try {
			stream_copy_to_stream($input, $output);
		} finally {
			fclose($input);
			fclose($output);
		}

		$fingerprint = $this->fingerprint($fpcalc, $temp);
		if ($fingerprint['fingerprint'] === '' || $fingerprint['duration'] <= 0) {
			throw new RuntimeException('fpcalc did not return a usable Chromaprint fingerprint.');
		}

		$params = [
			'client' => $clientKey,
			'format' => 'json',
			'duration' => (string)$fingerprint['duration'],
			'fingerprint' => $fingerprint['fingerprint'],
			'meta' => 'recordings+releases+releasegroups',
		];
		$url = 'https://api.acoustid.org/v2/lookup?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
		$data = $this->requestJson($url);
		$rows = is_array($data['results'] ?? null) ? $data['results'] : [];
		$results = [];

		foreach (array_slice($rows, 0, 5) as $row) {
			if (!is_array($row)) {
				continue;
			}
			$score = max(0, min(100, (int)round(((float)($row['score'] ?? 0)) * 100)));
			$acoustId = trim((string)($row['id'] ?? ''));
			$recordings = is_array($row['recordings'] ?? null) ? $row['recordings'] : [];
			if ($recordings === []) {
				$results[] = [
					'id' => 'acoustid:' . $acoustId,
					'title' => '',
					'artist' => '',
					'album' => '',
					'albumArtist' => '',
					'track' => '',
					'year' => '',
					'releaseId' => '',
					'releaseGroupId' => '',
					'score' => $score,
					'source' => 'AcoustID',
					'sourceUrl' => $acoustId !== '' ? 'https://acoustid.org/track/' . rawurlencode($acoustId) : '',
				];
				continue;
			}

			foreach (array_slice($recordings, 0, 3) as $recording) {
				if (!is_array($recording)) {
					continue;
				}
				$releases = is_array($recording['releases'] ?? null) ? $recording['releases'] : [];
				$release = is_array($releases[0] ?? null) ? $releases[0] : [];
				$releaseGroups = is_array($recording['releasegroups'] ?? null) ? $recording['releasegroups'] : [];
				$releaseGroup = is_array($releaseGroups[0] ?? null) ? $releaseGroups[0] : [];
				$artists = is_array($recording['artists'] ?? null) ? $recording['artists'] : [];
				$releaseArtists = is_array($release['artists'] ?? null) ? $release['artists'] : [];
				$date = trim((string)($release['date'] ?? ''));
				$year = preg_match('/\d{4}/', $date, $match) ? $match[0] : '';

				$results[] = [
					'id' => (string)($recording['id'] ?? ('acoustid:' . $acoustId)),
					'title' => trim((string)($recording['title'] ?? '')),
					'artist' => $this->artistsToString($artists),
					'album' => trim((string)($release['title'] ?? '')),
					'albumArtist' => $this->artistsToString($releaseArtists),
					'track' => '',
					'year' => $year,
					'releaseId' => trim((string)($release['id'] ?? '')),
					'releaseGroupId' => trim((string)($releaseGroup['id'] ?? '')),
					'score' => $score,
					'source' => 'AcoustID',
					'sourceUrl' => $acoustId !== '' ? 'https://acoustid.org/track/' . rawurlencode($acoustId) : '',
				];
			}
		}

		return $results;
	}

	/** @return array{duration: int, fingerprint: string} */
	private function fingerprint(string $fpcalc, string $file): array {
		if (!function_exists('proc_open')) {
			throw new RuntimeException('The PHP proc_open function is disabled; AcoustID fingerprinting cannot run.');
		}

		$descriptors = [
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$process = proc_open([$fpcalc, '-json', $file], $descriptors, $pipes);
		if (!is_resource($process)) {
			throw new RuntimeException('Could not start fpcalc.');
		}
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$exitCode = proc_close($process);
		if ($exitCode !== 0) {
			throw new RuntimeException('fpcalc failed: ' . trim((string)$stderr));
		}

		$data = json_decode((string)$stdout, true);
		if (!is_array($data)) {
			throw new RuntimeException('fpcalc returned invalid JSON.');
		}

		return [
			'duration' => (int)round((float)($data['duration'] ?? 0)),
			'fingerprint' => trim((string)($data['fingerprint'] ?? '')),
		];
	}

	/** @return array<string, mixed> */
	private function requestJson(string $url): array {
		$client = $this->clientService->newClient();
		$response = $client->get($url, [
			'headers' => [
				'Accept' => 'application/json',
				'User-Agent' => 'MusicCurator/0.2.0 (https://github.com/Happyfeet01/musiccurator)',
			],
			'connect_timeout' => 8,
			'timeout' => 20,
			'http_errors' => false,
		]);
		$status = $response->getStatusCode();
		$body = (string)$response->getBody();
		if ($status < 200 || $status >= 300) {
			throw new RuntimeException(sprintf('AcoustID returned HTTP %d', $status));
		}
		$data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($data) || ($data['status'] ?? '') !== 'ok') {
			throw new RuntimeException('AcoustID returned an error response.');
		}
		return $data;
	}

	private function fpcalcPath(): string {
		foreach (['/usr/bin/fpcalc', '/usr/local/bin/fpcalc'] as $candidate) {
			if (is_file($candidate) && is_executable($candidate)) {
				return $candidate;
			}
		}
		return '';
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
}
