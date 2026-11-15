<?php

declare(strict_types=1);

namespace OCA\MusicCurator\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Instantiated by Nextcloud during app bootstrap.
 *
 * @psalm-suppress UnusedClass
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'musiccurator';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
	}

	public function boot(IBootContext $context): void {
	}
}
