<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Controller;

use OCA\MusicCurator\Service\AiAdvisorService;
use OCA\MusicCurator\Service\AudioTagReader;
use OCA\MusicCurator\Service\ProviderCredentialsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

class AiController extends Controller {
	private const AUDIO_EXTENSIONS = ['mp3', 'flac', 'm4a', 'm4b', 'aac', 'ogg', 'opus', 'wav'];
	private const MAX_TRACKS = 100;
	private const DEFAULT_OPENAI_MODEL = 'gpt-5.6-luna';
	private const DEFAULT_MISTRAL_MODEL = 'mistral-small-latest';
	private const DEFAULT_OLLAMA_URL = 'http://127.0.0.1:11434/api';

	public function __construct(
		string $appName,
		IRequest $request,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
		private IConfig $config,
		private AudioTagReader $tagReader,
		private AiAdvisorService $advisor,
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

		return new DataResponse($this->settingsPayload($userId));
	}

	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function saveSettings(
		string $aiProvider = 'off',
		string $openAiKey = '',
		string $mistralKey = '',
		string $openAiModel = self::DEFAULT_OPENAI_MODEL,
		string $mistralModel = self::DEFAULT_MISTRAL_MODEL,
		string $ollamaModel = '',
		string $ollamaUrl = self::DEFAULT_OLLAMA_URL,
	): DataResponse {
		$userId = $this->userId();
		$aiProvider = strtolower(trim($aiProvider));
		if (!in_array($aiProvider, ['off', 'openai', 'mistral', 'ollama'], true)) {
			return new DataResponse(['message' => 'Unknown AI provider.'], Http::STATUS_BAD_REQUEST);
		}

		$openAiModel = trim($openAiModel) !== '' ? trim($openAiModel) : self::DEFAULT_OPENAI_MODEL;
		$mistralModel = trim($mistralModel) !== '' ? trim($mistralModel) : self::DEFAULT_MISTRAL_MODEL;
		$ollamaModel = trim($ollamaModel);
		$ollamaUrl = rtrim(trim($ollamaUrl), '/');
		if ($ollamaUrl === '') {
			$ollamaUrl = self::DEFAULT_OLLAMA_URL;
		}

		$this->config->setUserValue($userId, 'musiccurator', 'ai_provider', $aiProvider);
		$this->config->setUserValue($userId, 'musiccurator', 'openai_model', $openAiModel);
		$this->config->setUserValue($userId, 'musiccurator', 'mistral_model', $mistralModel);
		$this->config->setUserValue($userId, 'musiccurator', 'ollama_model', $ollamaModel);
		$this->config->setUserValue($userId, 'musiccurator', 'ollama_url', $ollamaUrl);
		$this->credentials->storeIfProvided($userId, 'openai_key', $openAiKey);
		$this->credentials->storeIfProvided($userId, 'mistral_key', $mistralKey);

		$this->logger->info('MusicCurator AI advisor settings saved', [
			'app' => 'musiccurator',
			'user' => $userId,
			'ai_provider' => $aiProvider,
			'openai_configured' => $this->credentials->configured($userId, 'openai_key'),
			'mistral_configured' => $this->credentials->configured($userId, 'mistral_key'),
		]);

		return new DataResponse($this->settingsPayload($userId));
	}

	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function classifyFolder(string $path): DataResponse {
		$userId = $this->userId();

		try {
			$path = $this->normalizePath($path);
			$this->assertInsideLibrary($userId, $path);
			$node = $this->nodeForUserPath($userId, $path);
			if (!$node instanceof Folder) {
				return new DataResponse(['message' => 'AI classification requires a folder path.'], Http::STATUS_BAD_REQUEST);
			}

			$tracks = [];
			foreach ($node->getDirectoryListing() as $child) {
				if (count($tracks) >= self::MAX_TRACKS) {
					break;
				}
				if (!$child instanceof File) {
					continue;
				}
				$extension = strtolower(pathinfo($child->getName(), PATHINFO_EXTENSION));
				if (!in_array($extension, self::AUDIO_EXTENSIONS, true)) {
					continue;
				}
				$tags = $this->tagReader->read($child);
				$tracks[] = [
					'filename' => $child->getName(),
					...$tags,
				];
			}

			if ($tracks === []) {
				return new DataResponse(['message' => 'No audio files were found directly in this folder.'], Http::STATUS_BAD_REQUEST);
			}

			$this->logger->info('MusicCurator AI folder classification started', [
				'app' => 'musiccurator',
				'user' => $userId,
				'path' => $path,
				'tracks' => count($tracks),
			]);

			$result = $this->advisor->classifyFolder($userId, $path, $tracks);

			$this->logger->info('MusicCurator AI folder classification completed', [
				'app' => 'musiccurator',
				'user' => $userId,
				'path' => $path,
				'provider' => $result['provider'] ?? '',
				'group_type' => $result['groupType'] ?? '',
				'confidence' => $result['confidence'] ?? 0,
			]);

			return new DataResponse($result);
		} catch (Throwable $e) {
			$this->logger->warning('MusicCurator AI folder classification failed', [
				'app' => 'musiccurator',
				'user' => $userId,
				'path' => $path,
				'exception' => $e,
			]);

			return new DataResponse(['message' => 'AI classification failed: ' . $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}

	/** @return array<string, mixed> */
	private function settingsPayload(string $userId): array {
		return [
			'aiProvider' => $this->config->getUserValue($userId, 'musiccurator', 'ai_provider', 'off'),
			'openAiConfigured' => $this->credentials->configured($userId, 'openai_key'),
			'mistralConfigured' => $this->credentials->configured($userId, 'mistral_key'),
			'openAiModel' => $this->config->getUserValue($userId, 'musiccurator', 'openai_model', self::DEFAULT_OPENAI_MODEL),
			'mistralModel' => $this->config->getUserValue($userId, 'musiccurator', 'mistral_model', self::DEFAULT_MISTRAL_MODEL),
			'ollamaModel' => $this->config->getUserValue($userId, 'musiccurator', 'ollama_model', ''),
			'ollamaUrl' => $this->config->getUserValue($userId, 'musiccurator', 'ollama_url', self::DEFAULT_OLLAMA_URL),
		];
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
			throw new \RuntimeException('The requested folder is outside the configured music library.');
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
