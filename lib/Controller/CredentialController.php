<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Controller;

use OCA\MusicCurator\Service\ProviderCredentialsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;
use Throwable;

/** @psalm-api Instantiated by Nextcloud through appinfo/routes.php. */
final class CredentialController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private ProviderCredentialsService $credentials,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function delete(string $credential): DataResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new RuntimeException('No authenticated Nextcloud user.');
		}
		$userId = $user->getUID();
		if (!in_array($credential, ['acoustid_key', 'acoustid_user_key', 'discogs_token', 'lastfm_key', 'openai_key', 'mistral_key'], true)) {
			return new DataResponse(['message' => 'Unknown provider credential.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$this->credentials->delete($userId, $credential);
		} catch (Throwable) {
			return new DataResponse(['message' => 'Could not delete the saved credential. Please try again.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new DataResponse(['deleted' => true]);
	}

}
