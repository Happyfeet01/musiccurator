<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Controller;

use OCA\MusicCurator\Service\ProviderCredentialsService;
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
		private ProviderCredentialsService $credentials,
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
		string $aiProvider = 'off',
		string $openAiKey = '',
		string $mistralKey = '',
		string $openAiModel = 'gpt-5.6-luna',
		string $mistralModel = 'mistral-small-latest',
		string $ollamaModel = '',
		string $ollamaUrl = 'http://127.0.0.1:11434/api',
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

		$aiProvider = strtolower(trim($aiProvider));
		if (!in_array($aiProvider, ['off', 'openai', 'mistral', 'ollama'], true)) {
			$aiProvider = 'off';
		}
		$openAiModel = trim($openAiModel) !== '' ? trim($openAiModel) : 'gpt-5.6-luna';
		$mistralModel = trim($mistralModel) !== '' ? trim($mistralModel) : 'mistral-small-latest';
		$ollamaModel = trim($ollamaModel);
		$ollamaUrl = rtrim(trim($ollamaUrl), '/');
		if ($ollamaUrl === '') {
			$ollamaUrl = 'http://127.0.0.1:11434/api';
		}

		$this->config->setUserValue($userId, 'musiccurator', 'library_path', $libraryPath);
		$this->config->setUserValue($userId, 'musiccurator', 'musicbrainz_enabled', $musicBrainzEnabled === '1' ? '1' : '0');
		$this->config->setUserValue($userId, 'musiccurator', 'ai_provider', $aiProvider);
		$this->config->setUserValue($userId, 'musiccurator', 'openai_model', $openAiModel);
		$this->config->setUserValue($userId, 'musiccurator', 'mistral_model', $mistralModel);
		$this->config->setUserValue($userId, 'musiccurator', 'ollama_model', $ollamaModel);
		$this->config->setUserValue($userId, 'musiccurator', 'ollama_url', $ollamaUrl);
		$this->credentials->storeIfProvided($userId, 'acoustid_key', $acoustIdKey);
		$this->credentials->storeIfProvided($userId, 'acoustid_user_key', $acoustIdUserKey);
		$this->credentials->storeIfProvided($userId, 'discogs_token', $discogsToken);
		$this->credentials->storeIfProvided($userId, 'lastfm_key', $lastFmKey);
		$this->credentials->storeIfProvided($userId, 'openai_key', $openAiKey);
		$this->credentials->storeIfProvided($userId, 'mistral_key', $mistralKey);

		$this->logger->info('MusicCurator personal settings saved', [
			'app' => 'musiccurator',
			'user' => $userId,
			'library_path' => $libraryPath,
			'musicbrainz_enabled' => $musicBrainzEnabled === '1',
			'acoustid_configured' => $this->credentials->configured($userId, 'acoustid_key'),
			'discogs_configured' => $this->credentials->configured($userId, 'discogs_token'),
			'lastfm_configured' => $this->credentials->configured($userId, 'lastfm_key'),
			'ai_provider' => $aiProvider,
			'openai_configured' => $this->credentials->configured($userId, 'openai_key'),
			'mistral_configured' => $this->credentials->configured($userId, 'mistral_key'),
		]);

		return new DataResponse([
			'libraryPath' => $libraryPath,
			'libraryConfigured' => true,
			'musicBrainzEnabled' => $musicBrainzEnabled === '1',
			'acoustIdConfigured' => $this->credentials->configured($userId, 'acoustid_key'),
			'acoustIdUserConfigured' => $this->credentials->configured($userId, 'acoustid_user_key'),
			'discogsConfigured' => $this->credentials->configured($userId, 'discogs_token'),
			'lastFmConfigured' => $this->credentials->configured($userId, 'lastfm_key'),
			'aiProvider' => $aiProvider,
			'openAiConfigured' => $this->credentials->configured($userId, 'openai_key'),
			'mistralConfigured' => $this->credentials->configured($userId, 'mistral_key'),
			'openAiModel' => $openAiModel,
			'mistralModel' => $mistralModel,
			'ollamaModel' => $ollamaModel,
			'ollamaUrl' => $ollamaUrl,
		]);
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
