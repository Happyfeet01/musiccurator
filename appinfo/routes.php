<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'library#settings', 'url' => '/api/settings', 'verb' => 'GET'],
		['name' => 'library#saveSettings', 'url' => '/api/settings', 'verb' => 'POST'],
		['name' => 'folder#folders', 'url' => '/api/folders', 'verb' => 'GET'],
		['name' => 'library#scan', 'url' => '/api/library/scan', 'verb' => 'POST'],
		['name' => 'library#musicBrainz', 'url' => '/api/musicbrainz', 'verb' => 'GET'],
		['name' => 'library#previewMove', 'url' => '/api/library/preview-move', 'verb' => 'POST'],
		['name' => 'library#move', 'url' => '/api/library/move', 'verb' => 'POST'],
		['name' => 'library#changes', 'url' => '/api/changes', 'verb' => 'GET'],
	],
];
