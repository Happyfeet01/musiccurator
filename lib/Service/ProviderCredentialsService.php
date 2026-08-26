<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Service;

use OCP\IConfig;
use OCP\Security\ICredentialsManager;
use Throwable;

class ProviderCredentialsService {
	private const PREFIX = 'musiccurator:';

	public function __construct(
		private ICredentialsManager $credentialsManager,
		private IConfig $config,
	) {
	}

	public function get(string $userId, string $key): string {
		try {
			$value = $this->credentialsManager->retrieve($userId, self::PREFIX . $key);
			if (is_string($value) && $value !== '') {
				return $value;
			}
		} catch (Throwable) {
			// Fall back to the legacy user-config value below.
		}

		$legacy = trim($this->config->getUserValue($userId, 'musiccurator', $key, ''));
		if ($legacy === '') {
			return '';
		}

		// Transparently migrate credentials saved by early development builds.
		try {
			$this->credentialsManager->store($userId, self::PREFIX . $key, $legacy);
			$this->config->deleteUserValue($userId, 'musiccurator', $key);
		} catch (Throwable) {
			// Keep the legacy value if secure migration is not available.
		}

		return $legacy;
	}

	public function configured(string $userId, string $key): bool {
		return $this->get($userId, $key) !== '';
	}

	public function storeIfProvided(string $userId, string $key, string $value): void {
		$value = trim($value);
		if ($value === '') {
			return;
		}

		$this->credentialsManager->store($userId, self::PREFIX . $key, $value);
		$this->config->deleteUserValue($userId, 'musiccurator', $key);
	}
}
