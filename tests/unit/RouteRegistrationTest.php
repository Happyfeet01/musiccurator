<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Tests\Unit;

use OCP\AppFramework\Http\Attribute\Route;
use PHPUnit\Framework\TestCase;
use ReflectionAttribute;
use ReflectionClass;

final class RouteRegistrationTest extends TestCase {
	public function testScanUrlHasOnlyThePersistentHandler(): void {
		$owners = $this->frontpageOwners();
		self::assertSame(['scan#scan'], $owners['POST /api/library/scan']);
		self::assertSame(['scan#cached'], $owners['GET /api/library/cache']);
		self::assertSame(['scan#scanselected'], $owners['POST /api/library/scan-selected']);
	}

	public function testFrontpageUrlsCannotBeShadowedByASecondController(): void {
		foreach ($this->frontpageOwners() as $request => $owners) {
			self::assertCount(1, $owners, $request . ' has competing handlers: ' . implode(', ', $owners));
		}
	}

	public function testSettingsAndMetadataUseTheCurrentControllers(): void {
		$owners = $this->frontpageOwners();
		foreach ([
			'GET /api/settings' => 'read#settings',
			'POST /api/settings' => 'settings#savesettings',
			'GET /api/folders' => 'folder#folders',
			'GET /api/musicbrainz' => 'read#musicbrainz',
			'GET /api/changes' => 'read#changes',
			'POST /api/library/preview-move' => 'library#previewmove',
			'POST /api/library/move' => 'library#move',
		] as $request => $handler) {
			self::assertSame([$handler], $owners[$request], $request);
		}
	}

	/**
	 * Inspect both registration mechanisms used by Nextcloud. Checking routes.php
	 * alone misses older attributes that Nextcloud registers before that file.
	 *
	 * @return array<string, list<string>>
	 */
	private function frontpageOwners(): array {
		$owners = [];
		foreach (glob(__DIR__ . '/../../lib/Controller/*Controller.php') as $file) {
			$class = new ReflectionClass('OCA\\MusicCurator\\Controller\\' . basename($file, '.php'));
			foreach ($class->getMethods() as $method) {
				foreach ($method->getAttributes(Route::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
					$route = $attribute->newInstance();
					if ($route->getType() === Route::TYPE_FRONTPAGE) {
						$data = $route->toArray();
						$owners[$data['verb'] . ' ' . $data['url']][] = strtolower(substr($class->getShortName(), 0, -10) . '#' . $method->getName());
					}
				}
			}
		}
		$config = require __DIR__ . '/../../appinfo/routes.php';
		foreach ($config['routes'] as $route) {
			$owners[$route['verb'] . ' ' . $route['url']][] = strtolower($route['name']);
		}
		return array_map(static fn ($handlers) => array_values(array_unique($handlers)), $owners);
	}
}
