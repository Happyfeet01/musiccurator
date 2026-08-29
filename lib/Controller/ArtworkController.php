<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use Throwable;

class ArtworkController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IClientService $clientService,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function artwork(string $releaseId = '', string $url = ''): DataDisplayResponse|DataResponse {
		$releaseId = trim($releaseId);
		$url = trim($url);

		if ($url === '' && $releaseId !== '') {
			if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $releaseId)) {
				return new DataResponse(['message' => 'Invalid MusicBrainz release id.'], Http::STATUS_BAD_REQUEST);
			}
			$url = 'https://coverartarchive.org/release/' . rawurlencode($releaseId) . '/front-250';
		}

		if (!$this->isAllowedArtworkUrl($url)) {
			return new DataResponse(['message' => 'Artwork URL is not allowed.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$client = $this->clientService->newClient();
			$response = $client->get($url, [
				'headers' => [
					'Accept' => 'image/avif,image/webp,image/jpeg,image/png,image/*;q=0.8,*/*;q=0.5',
					'User-Agent' => 'MusicCurator/0.2.12 (https://github.com/Happyfeet01/musiccurator)',
				],
				'connect_timeout' => 8,
				'timeout' => 18,
				'http_errors' => false,
				'allow_redirects' => true,
			]);

			$status = $response->getStatusCode();
			if ($status < 200 || $status >= 300) {
				return new DataResponse(['message' => 'Artwork not found.'], Http::STATUS_NOT_FOUND);
			}

			$contentType = trim((string)$response->getHeader('Content-Type'));
			if (!str_starts_with(strtolower($contentType), 'image/')) {
				return new DataResponse(['message' => 'Artwork provider returned an unexpected content type.'], Http::STATUS_BAD_GATEWAY);
			}

			return new DataDisplayResponse((string)$response->getBody(), Http::STATUS_OK, [
				'Content-Type' => $contentType,
				'Cache-Control' => 'private, max-age=86400',
			]);
		} catch (Throwable) {
			return new DataResponse(['message' => 'Artwork provider is currently unavailable.'], Http::STATUS_BAD_GATEWAY);
		}
	}

	private function isAllowedArtworkUrl(string $url): bool {
		if ($url === '') {
			return false;
		}
		$parts = parse_url($url);
		if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
			return false;
		}

		$host = strtolower((string)($parts['host'] ?? ''));
		if ($host === '') {
			return false;
		}

		return $host === 'coverartarchive.org'
			|| $host === 'archive.org'
			|| str_ends_with($host, '.archive.org')
			|| $host === 'i.discogs.com'
			|| $host === 'lastfm.freetls.fastly.net'
			|| $host === 'lastfm-img2.akamaized.net';
	}
}
