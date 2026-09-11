<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Controller;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;

/**
 * @psalm-suppress UnusedClass
 */
class ApiController extends OCSController {
	/**
	 * Lightweight readiness endpoint used during development
	 *
	 * @return DataResponse<Http::STATUS_OK, array{app: string, status: string}, array{}>
	 *
	 * 200: MusicCurator readiness status returned
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api')]
	public function index(): DataResponse {
		return new DataResponse([
			'app' => 'musiccurator',
			'status' => 'ok',
		]);
	}
}
