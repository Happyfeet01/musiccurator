<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Controller;

use OCA\MusicCurator\Service\ScanIndexService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\Config\IUserConfig;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @psalm-suppress UnusedClass Loaded through Nextcloud routes.
 * @psalm-suppress MissingDependency The OCP SDK omits IRootFolder's legacy OC\Hooks\Emitter parent.
 */
final class ProgressController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private IRootFolder $rootFolder,
		private IUserConfig $config,
		private ScanIndexService $scanIndex,
		private LoggerInterface $logger,
		private IL10N $l10n,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function history(string $path): DataResponse {
		return $this->progress($path);
	}

	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function setStatus(string $path, string $status): DataResponse {
		if (!in_array($status, ['pending', 'done'], true)) {
			return new DataResponse(['message' => $this->l10n->t('Invalid review status.')], 400);
		}
		return $this->progress($path, $status);
	}

	private function progress(string $path, ?string $status = null): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['message' => $this->l10n->t('No authenticated Nextcloud user.')], 401);
		}
		$userId = $user->getUID();
		try {
			$storedPath = $this->config->getValueString($userId, 'musiccurator', 'library_path', '');
			$library = rtrim($storedPath, '/');
			$segments = explode('/', str_replace('\\', '/', $path));
			if ($path === '' || !str_starts_with($path, '/') || in_array('..', $segments, true) || in_array('.', $segments, true)
				|| $storedPath === ''
				|| ($path !== $library && !str_starts_with($path, $library . '/'))) {
				return new DataResponse(['message' => $this->l10n->t('The selected file is outside the configured music library.')], 403);
			}
			// Resolve through the signed-in user's filesystem, never a caller-supplied user or file id.
			$folder = $this->rootFolder->getUserFolder($userId);
			$node = $folder->get(ltrim($path, '/'));
			if (!$node instanceof File || !$node->isReadable()) {
				return new DataResponse(['message' => $this->l10n->t('Selected path is not a readable file.')], 404);
			}
			$fileId = $node->getId();
			if ($status !== null) {
				$this->scanIndex->recordAction($userId, $fileId, $status === 'done' ? 'marked_done' : 'reopened', $status);
			}
			return new DataResponse($this->scanIndex->reviewProgress($userId, $fileId));
		} catch (\OutOfBoundsException|\OCP\Files\NotFoundException $e) {
			return new DataResponse(['message' => $this->l10n->t('This file is not available in your index. Refresh the library index first.')], 404);
		} catch (Throwable $e) {
			$this->logger->error('MusicCurator review progress request failed', [
				'app' => 'musiccurator', 'user' => $userId, 'operation' => $status === null ? 'load_history' : 'set_status', 'exception' => $e,
			]);
			return new DataResponse(['message' => $this->l10n->t('Could not load or save review progress. Check the Nextcloud log and try again.')], 500);
		}
	}
}
