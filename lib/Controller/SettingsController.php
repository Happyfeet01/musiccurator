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
	private const DEFAULT_OPENAI_MODEL = 'gpt-5.6-luna';
	private const DEFAULT_MISTRAL_MODEL = 'mistral-small-latest';
	private const DEFAULT_OLLAMA_URL = 'http://127.0.0.1:11434/api';

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
		?string $aiProvider = null,
		string $openAiKey = '',
		string $mistralKey = '',
		?string $openAiModel = null,
		?string $mistralModel = null,
		?string $ollamaModel = null,
		?string $ollamaUrl = null,
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

		// AI advisor settings are a separate settings domain. Older development
		// frontends may still submit them here, so accept those values when they
		// are explicitly present, but never reset them when the normal settings
		// form omits them.
		if ($aiProvider !== null) {
			$aiProvider = strtolower(trim($aiProvider));
			if (in_array($aiProvider, ['off', 'openai', 'mistral', 'ollama'], true)) {
				$this->config->setUserValue($userId, 'musiccurator', 'ai_provider', $aiProvider);
			}
		}
		if ($openAiModel !== null) {
			$openAiModel = trim($openAiModel) !== '' ? trim($openAiModel) : self::DEFAULT_OPENAI_MODEL;
			$this->config->setUserValue($userId, 'musiccurator', 'openai_model', $openAiModel);
		}
		if ($mistralModel !== null) {
			$mistralModel = trim($mistralModel) !== '' ? trim($mistralModel) : self::DEFAULT_MISTRAL_MODEL;
			$this->config->setUserValue($userId, 'musiccurator', 'mistral_model', $mistralModel);
		}
		if ($ollamaModel !== null) {
			$this->config->setUserValue($userId, 'musiccurator', 'ollama_model', trim($ollamaModel));
		}
		if ($ollamaUrl !== null) {
			$ollamaUrl = rtrim(trim($ollamaUrl), '/');
			$this->config->setUserValue($userId, 'musiccurator', 'ollama_url', $ollamaUrl !== '' ? $ollamaUrl : self::DEFAULT_OLLAMA_URL);
		}

		$this->credentials->storeIfProvided($userId, 'acoustid_key', $acoustIdKey);
		$this->credentials->storeIfProvided($userId, 'acoustid_user_key', $acoustIdUserKey);
		$this->credentials->storeIfProvided($userId, 'discogs_token', $discogsToken);
		$this->credentials->storeIfProvided($userId, 'lastfm_key', $lastFmKey);
		$this->credentials->storeIfProvided($userId, 'openai_key', $openAiKey);
		$this->credentials->storeIfProvided($userId, 'mistral_key', $mistralKey);

		$currentAiProvider = $this->config->getUserValue($userId, 'musiccurator', 'ai_provider', 'off');

		$this->logger->info('MusicCurator personal settings saved', [
			'app' => 'musiccurator',
			'user' => $userId,
			'library_path' => $libraryPath,
			'musicbrainz_enabled' => $musicBrainzEnabled === '1',
			'acoustid_configured' => $this->credentials->configured($userId, 'acoustid_key'),
			'discogs_configured' => $this->credentials->configured($userId, 'discogs_token'),
			'lastfm_configured' => $this->credentials->configured($userId, 'lastfm_key'),
			'ai_provider' => $currentAiProvider,
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
			'aiProvider' => $currentAiProvider,
			'openAiConfigured' => $this->credentials->configured($userId, 'openai_key'),
			'mistralConfigured' => $this->credentials->configured($userId, 'mistral_key'),
			'openAiModel' => $this->config->getUserValue($userId, 'musiccurator', 'openai_model', self::DEFAULT_OPENAI_MODEL),
			'mistralModel' => $this->config->getUserValue($userId, 'musiccurator', 'mistral_model', self::DEFAULT_MISTRAL_MODEL),
			'ollamaModel' => $this->config->getUserValue($userId, 'musiccurator', 'ollama_model', ''),
			'ollamaUrl' => $this->config->getUserValue($userId, 'musiccurator', 'ollama_url', self::DEFAULT_OLLAMA_URL),
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
