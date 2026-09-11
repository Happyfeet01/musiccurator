<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Controller;

use OCA\MusicCurator\Service\PlaylistService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

class PlaylistController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private PlaylistService $playlists,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function createFromFolder(string $folderPath, string $name = ''): DataResponse {
		try {
			$playlist = $this->playlists->createForFolder($this->userId(), $folderPath, $name);

			return new DataResponse($playlist);
		} catch (Throwable $e) {
			return new DataResponse(['message' => 'Playlist creation failed: ' . $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	private function userId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new \RuntimeException('No authenticated Nextcloud user.');
		}

		return $user->getUID();
	}
}
