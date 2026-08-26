<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Controller;

use OCA\MusicCurator\Service\AudioTagReader;
use OCA\MusicCurator\Service\MusicBrainzService;
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
use Throwable;

/**
 * Read-only AJAX endpoints used by the MusicCurator frontend.
 *
 * These methods never mutate user data. Keeping them separate makes the
 * temporary CSRF exemption explicit while all state-changing POST routes
 * remain CSRF protected.
 */
class ReadController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private IConfig $config,
		private AudioTagReader $tagReader,
		private MusicBrainzService $musicBrainz,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function settings(): DataResponse {
		$userId = $this->userId();

		return new DataResponse([
			'libraryPath' => $this->libraryPath($userId),
			'musicBrainzEnabled' => $this->config->getUserValue($userId, 'musiccurator', 'musicbrainz_enabled', '1') === '1',
			'acoustIdConfigured' => $this->config->getUserValue($userId, 'musiccurator', 'acoustid_key', '') !== '',
			'acoustIdUserConfigured' => $this->config->getUserValue($userId, 'musiccurator', 'acoustid_user_key', '') !== '',
			'discogsConfigured' => $this->config->getUserValue($userId, 'musiccurator', 'discogs_token', '') !== '',
			'lastFmConfigured' => $this->config->getUserValue($userId, 'musiccurator', 'lastfm_key', '') !== '',
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
	public function musicBrainz(string $path): DataResponse {
		$userId = $this->userId();
		if ($this->config->getUserValue($userId, 'musiccurator', 'musicbrainz_enabled', '1') !== '1') {
			return new DataResponse(['message' => 'MusicBrainz is disabled in your personal settings.'], 400);
		}

		try {
			$path = $this->normalizePath($path);
			$this->assertInsideLibrary($userId, $path);
			$node = $this->nodeForUserPath($userId, $path);
			if (!$node instanceof File) {
				return new DataResponse(['message' => 'Selected path is not a file.'], 400);
			}

			$tags = $this->tagReader->read($node);
			$title = $tags['title'] !== '' ? $tags['title'] : pathinfo($node->getName(), PATHINFO_FILENAME);
			$results = $this->musicBrainz->search($title, $tags['artist'], $tags['album']);

			return new DataResponse(['results' => $results]);
		} catch (Throwable $e) {
			return new DataResponse(['message' => 'MusicBrainz lookup failed: ' . $e->getMessage()], 502);
		}
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
		return $this->normalizePath($this->config->getUserValue($userId, 'musiccurator', 'library_path', '/Music'));
	}

	private function assertInsideLibrary(string $userId, string $path): void {
		$library = rtrim($this->libraryPath($userId), '/');
		if ($library === '') {
			return;
		}
		if ($path !== $library && !str_starts_with($path, $library . '/')) {
			throw new \RuntimeException('The requested path is outside the configured music library.');
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
