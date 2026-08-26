<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Controller;

use OCA\MusicCurator\Service\AudioTagReader;
use OCA\MusicCurator\Service\MetadataSearchService;
use OCA\MusicCurator\Service\ProviderCredentialsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read-only AJAX endpoints used by the MusicCurator frontend.
 */
class ReadController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private IConfig $config,
		private AudioTagReader $tagReader,
		private MetadataSearchService $metadataSearch,
		private ProviderCredentialsService $credentials,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function settings(): DataResponse {
		$userId = $this->userId();
		$libraryPath = $this->libraryPath($userId);

		return new DataResponse([
			'libraryPath' => $libraryPath,
			'libraryConfigured' => $libraryPath !== '',
			'musicBrainzEnabled' => $this->config->getUserValue($userId, 'musiccurator', 'musicbrainz_enabled', '1') === '1',
			'acoustIdConfigured' => $this->credentials->configured($userId, 'acoustid_key'),
			'acoustIdUserConfigured' => $this->credentials->configured($userId, 'acoustid_user_key'),
			'discogsConfigured' => $this->credentials->configured($userId, 'discogs_token'),
			'lastFmConfigured' => $this->credentials->configured($userId, 'lastfm_key'),
		]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function changes(): DataResponse {
		$userId = $this->userId();
		$raw = $this->config->getUserValue($userId, 'musiccurator', 'changes', '[]');
		$changes = json_decode($raw, true);

		return new DataResponse(['changes' => is_array($changes) ? $changes : []]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function metadata(string $path): DataResponse {
		$userId = $this->userId();

		try {
			$path = $this->normalizePath($path);
			$this->assertInsideLibrary($userId, $path);
			$node = $this->nodeForUserPath($userId, $path);
			if (!$node instanceof File) {
				return new DataResponse(['message' => 'Selected path is not a file.'], 400);
			}

			$tags = $this->tagReader->read($node);
			$title = $tags['title'] !== '' ? $tags['title'] : pathinfo($node->getName(), PATHINFO_FILENAME);

			$this->logger->info('MusicCurator metadata lookup started', [
				'app' => 'musiccurator',
				'user' => $userId,
				'path' => $path,
				'title' => $title,
				'artist' => $tags['artist'],
			]);

			$data = $this->metadataSearch->search($userId, $node, $tags, $title);

			$this->logger->info('MusicCurator metadata lookup completed', [
				'app' => 'musiccurator',
				'user' => $userId,
				'path' => $path,
				'results' => count($data['results']),
				'providers' => $data['providers'],
			]);

			return new DataResponse($data);
		} catch (Throwable $e) {
			$this->logger->warning('MusicCurator metadata lookup failed', [
				'app' => 'musiccurator',
				'user' => $userId,
				'path' => $path,
				'library_path' => $this->libraryPath($userId),
				'exception' => $e,
			]);

			return new DataResponse(['message' => 'Metadata lookup failed: ' . $e->getMessage()], 502);
		}
	}

	/**
	 * Backwards-compatible endpoint for development builds that still call
	 * /api/musicbrainz. It now runs the complete provider search.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function musicBrainz(string $path): DataResponse {
		return $this->metadata($path);
	}

	private function userId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('No authenticated Nextcloud user.');
		}

		return $user->getUID();
	}

	private function nodeForUserPath(string $userId, string $path): Node {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$relative = ltrim($path, '/');

		return $relative === '' ? $userFolder : $userFolder->get($relative);
	}

	private function libraryPath(string $userId): string {
		$stored = trim($this->config->getUserValue($userId, 'musiccurator', 'library_path', ''));

		return $stored === '' ? '' : $this->normalizePath($stored);
	}

	private function assertInsideLibrary(string $userId, string $path): void {
		$library = rtrim($this->libraryPath($userId), '/');
		if ($library === '') {
			throw new \RuntimeException('No music folder is configured.');
		}
		if ($path !== $library && !str_starts_with($path, $library . '/')) {
			throw new \RuntimeException(sprintf('The requested path "%s" is outside the configured music library "%s".', $path, $library));
		}
	}

	private function normalizePath(string $path): string {
		$segments = $this->pathSegments($path);

		return $segments === [] ? '/' : '/' . implode('/', $segments);
	}

	/** @return list<string> */
	private function pathSegments(string $path): array {
		$path = str_replace('\\', '/', trim($path));
		$segments = [];

		foreach (explode('/', $path) as $segment) {
			$segment = trim($segment);
			if ($segment === '' || $segment === '.') {
				continue;
			}
			if ($segment === '..') {
				throw new \InvalidArgumentException('Parent path segments are not allowed.');
			}
			$segments[] = $segment;
		}

		return $segments;
	}
}
