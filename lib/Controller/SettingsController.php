<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

class SettingsController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function saveSettings(
		string $libraryPath = '',
		string $musicBrainzEnabled = '1',
		string $acoustIdKey = '',
		string $acoustIdUserKey = '',
		string $discogsToken = '',
		string $lastFmKey = '',
	): DataResponse {
		$userId = $this->userId();
		$libraryPath = trim($libraryPath);

		if ($libraryPath === '') {
			$this->logger->warning('MusicCurator settings save rejected because no music folder was selected', [
				'app' => 'musiccurator',
				'user' => $userId,
			]);

			return new DataResponse(['message' => 'Choose a music folder before saving.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$libraryPath = $this->normalizePath($libraryPath);
			$node = $this->nodeForUserPath($userId, $libraryPath);
			if (!$node instanceof Folder) {
				return new DataResponse(['message' => 'The selected library path is not a folder.'], Http::STATUS_BAD_REQUEST);
			}
		} catch (Throwable $e) {
			$this->logger->warning('MusicCurator could not save the selected music folder', [
				'app' => 'musiccurator',
				'user' => $userId,
				'path' => $libraryPath,
				'exception' => $e,
			]);

			return new DataResponse([
				'message' => sprintf('The selected music folder "%s" does not exist in your Nextcloud files.', $libraryPath),
			], Http::STATUS_BAD_REQUEST);
		}

		$this->config->setUserValue($userId, 'musiccurator', 'library_path', $libraryPath);
		$this->config->setUserValue($userId, 'musiccurator', 'musicbrainz_enabled', $musicBrainzEnabled === '1' ? '1' : '0');
		$this->storeSecretIfProvided($userId, 'acoustid_key', $acoustIdKey);
		$this->storeSecretIfProvided($userId, 'acoustid_user_key', $acoustIdUserKey);
		$this->storeSecretIfProvided($userId, 'discogs_token', $discogsToken);
		$this->storeSecretIfProvided($userId, 'lastfm_key', $lastFmKey);

		$this->logger->info('MusicCurator personal settings saved', [
			'app' => 'musiccurator',
			'user' => $userId,
			'library_path' => $libraryPath,
		]);

		return new DataResponse([
			'libraryPath' => $libraryPath,
			'libraryConfigured' => true,
			'musicBrainzEnabled' => $musicBrainzEnabled === '1',
			'acoustIdConfigured' => $this->config->getUserValue($userId, 'musiccurator', 'acoustid_key', '') !== '',
			'acoustIdUserConfigured' => $this->config->getUserValue($userId, 'musiccurator', 'acoustid_user_key', '') !== '',
			'discogsConfigured' => $this->config->getUserValue($userId, 'musiccurator', 'discogs_token', '') !== '',
			'lastFmConfigured' => $this->config->getUserValue($userId, 'musiccurator', 'lastfm_key', '') !== '',
		]);
	}

	private function storeSecretIfProvided(string $userId, string $key, string $value): void {
		$value = trim($value);
		if ($value !== '') {
			$this->config->setUserValue($userId, 'musiccurator', $key, $value);
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
