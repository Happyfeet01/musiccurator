<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Tests\Unit;

use OCA\MusicCurator\Controller\CredentialController;
use OCA\MusicCurator\Service\ProviderCredentialsService;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\Config\IUserConfig;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ICredentialsManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class CredentialsTest extends TestCase {
	public static function credentials(): array {
		return array_map(static fn (string $key): array => [$key], [
			'acoustid_key', 'acoustid_user_key', 'discogs_token', 'lastfm_key', 'openai_key', 'mistral_key',
		]);
	}

	#[DataProvider('credentials')]
	public function testDeletesSecureAndLegacyCopiesForCurrentUser(string $key): void {
		$config = $this->createMock(IConfig::class);
		$userConfig = $this->createMock(IUserConfig::class);
		$manager = $this->createMock(ICredentialsManager::class);
		$order = [];
		$userConfig->expects(self::once())->method('deleteUserConfig')->with('alice', 'musiccurator', $key)
			->willReturnCallback(static function () use (&$order): void {
				$order[] = 'legacy';
			});
		$manager->expects(self::once())->method('delete')->with('alice', 'musiccurator:' . $key)
			->willReturnCallback(static function () use (&$order): int {
				$order[] = 'secure';
				return 1;
			});
		$config->expects(self::never())->method('setUserValue');
		$service = new ProviderCredentialsService($manager, $config, $userConfig);
		$response = $this->controller($service)->delete($key);
		self::assertSame(200, $response->getStatus());
		self::assertSame(['deleted' => true], $response->getData());
		self::assertSame(['legacy', 'secure'], $order);
	}

	public function testDeletedLegacyTokenCannotBeMigratedBackAndDeletionIsIdempotent(): void {
		$secure = ['alice:musiccurator:lastfm_key' => 'old-secure-key', 'bob:musiccurator:lastfm_key' => 'other-user-key', 'alice:musiccurator:discogs_token' => 'other-provider-key'];
		$legacy = ['alice:lastfm_key' => 'old-legacy-key', 'bob:lastfm_key' => 'other-user-legacy'];
		$config = $this->createMock(IConfig::class);
		$userConfig = $this->createMock(IUserConfig::class);
		$manager = $this->createMock(ICredentialsManager::class);
		$userConfig->method('deleteUserConfig')->willReturnCallback(static function ($user, $app, $key) use (&$legacy): void {
			unset($legacy[$user . ':' . $key]);
		});
		$config->method('getUserValue')->willReturnCallback(static function ($user, $app, $key) use (&$legacy): string {
			return $legacy[$user . ':' . $key] ?? '';
		});
		$manager->method('delete')->willReturnCallback(static function ($user, $key) use (&$secure): int {
			unset($secure[$user . ':' . $key]);
			return 1;
		});
		$manager->method('retrieve')->willReturnCallback(static function ($user, $key) use (&$secure) {
			return $secure[$user . ':' . $key] ?? null;
		});
		$manager->expects(self::never())->method('store');
		$service = new ProviderCredentialsService($manager, $config, $userConfig);
		$service->delete('alice', 'lastfm_key');
		$service->delete('alice', 'lastfm_key');
		self::assertFalse($service->configured('alice', 'lastfm_key'));
		self::assertSame('other-user-key', $service->get('bob', 'lastfm_key'));
		self::assertSame('other-provider-key', $service->get('alice', 'discogs_token'));
		self::assertSame(['bob:lastfm_key' => 'other-user-legacy'], $legacy);
	}

	public function testBlankSaveStillPreservesExistingCredentials(): void {
		$config = $this->createMock(IConfig::class);
		$userConfig = $this->createMock(IUserConfig::class);
		$manager = $this->createMock(ICredentialsManager::class);
		$manager->expects(self::never())->method('store');
		$manager->expects(self::never())->method('delete');
		$config->expects(self::never())->method('deleteUserValue');
		(new ProviderCredentialsService($manager, $config, $userConfig))->storeIfProvided('alice', 'lastfm_key', '  ');
	}

	public function testRejectsUnrelatedConfigKey(): void {
		$service = $this->createMock(ProviderCredentialsService::class);
		$service->expects(self::never())->method('delete');
		self::assertSame(400, $this->controller($service)->delete('library_path')->getStatus());
	}

	public function testRequiresAuthenticatedUser(): void {
		$service = $this->createMock(ProviderCredentialsService::class);
		$service->expects(self::never())->method('delete');
		$this->expectException(RuntimeException::class);
		$this->controller($service, false)->delete('lastfm_key');
	}

	public function testStorageFailureDoesNotReportSuccessOrExposeSecret(): void {
		$service = $this->createMock(ProviderCredentialsService::class);
		$service->method('delete')->willThrowException(new RuntimeException('secret-key'));
		$response = $this->controller($service)->delete('lastfm_key');
		self::assertSame(500, $response->getStatus());
		self::assertSame(['message' => 'Could not delete the saved credential. Please try again.'], $response->getData());
	}

	public function testDeleteRouteRetainsCsrfProtection(): void {
		$method = new ReflectionMethod(CredentialController::class, 'delete');
		self::assertSame([], $method->getAttributes(NoCSRFRequired::class));
		$routes = require __DIR__ . '/../../appinfo/routes.php';
		$routes = array_values(array_filter($routes['routes'], static fn (array $route): bool => $route['name'] === 'credential#delete'));
		self::assertSame('DELETE', $routes[0]['verb']);
	}

	private function controller(ProviderCredentialsService $credentials, bool $authenticated = true): CredentialController {
		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session->method('getUser')->willReturn($authenticated ? $user : null);

		return new CredentialController('musiccurator', $this->createMock(IRequest::class), $session, $credentials);
	}
}
