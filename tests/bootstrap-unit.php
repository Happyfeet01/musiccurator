<?php

declare(strict_types=1);

require __DIR__ . '/../vendor-bin/phpunit/vendor/autoload.php';
$loader = require __DIR__ . '/../vendor/autoload.php';
$loader->addPsr4('OCA\\MusicCurator\\', __DIR__ . '/../lib', true);
$loader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP');

if (!interface_exists(\OC\Hooks\Emitter::class)) {
	require __DIR__ . '/stubs/Emitter.php';
}
