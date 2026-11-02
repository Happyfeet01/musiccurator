<?php

declare(strict_types=1);

$loader = require __DIR__ . '/../vendor/autoload.php';
$loader->addPsr4('OCA\\MusicCurator\\', __DIR__ . '/../lib', true);
$loader->addPsr4('OCP\\', __DIR__ . '/../vendor/nextcloud/ocp/OCP');
