<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\OpenAPI;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Receives sanitized frontend request failures so development errors that
 * happen before an application controller is reached are still visible in
 * nextcloud.log.
 */
class ClientLogController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[OpenAPI(OpenAPI::SCOPE_IGNORE)]
	public function report(
		string $operation = 'unknown',
		int $status = 0,
		string $message = '',
		string $path = '',
	): DataResponse {
		$userId = $this->userSession->getUser()?->getUID() ?? 'unknown';

		$this->logger->warning('MusicCurator frontend request failed', [
			'app' => 'musiccurator',
			'user' => $userId,
			'operation' => $this->sanitize($operation, 80),
			'status' => max(0, min(599, $status)),
			'path' => $this->sanitize($path, 240),
			'message' => $this->sanitize($message, 500),
		]);

		return new DataResponse(['logged' => true]);
	}

	private function sanitize(string $value, int $maxLength): string {
		$value = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $value) ?? '';
		$value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

		return strlen($value) > $maxLength ? substr($value, 0, $maxLength) : $value;
	}
}
