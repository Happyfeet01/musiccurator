<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

class FolderController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IRootFolder $rootFolder,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function folders(string $path = '/'): DataResponse {
		$userId = $this->userId();

		try {
			$path = $this->normalizePath($path);
			$node = $this->nodeForUserPath($userId, $path);
			if (!$node instanceof Folder) {
				return new DataResponse(['message' => 'Path is not a folder.'], 400);
			}
		} catch (Throwable $e) {
			return new DataResponse([
				'message' => 'Folder not found: ' . $e->getMessage(),
			], 404);
		}

		$folders = [];
		foreach ($node->getDirectoryListing() as $child) {
			if ($child->getType() !== FileInfo::TYPE_FOLDER) {
				continue;
			}

			$folders[] = [
				'name' => $child->getName(),
				'path' => $this->joinUserPath($path, $child->getName()),
			];
		}

		usort($folders, static fn (array $a, array $b): int => strcasecmp((string)$a['name'], (string)$b['name']));

		return new DataResponse([
			'path' => $path,
			'parent' => $this->parentPath($path),
			'folders' => $folders,
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

	private function joinUserPath(string $base, string $name): string {
		return $this->normalizePath(rtrim($base, '/') . '/' . $name);
	}

	private function parentPath(string $path): string {
		$segments = $this->pathSegments($path);
		array_pop($segments);

		return $segments === [] ? '/' : '/' . implode('/', $segments);
	}
}
