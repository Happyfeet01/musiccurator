<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'read#settings', 'url' => '/api/settings', 'verb' => 'GET'],
		['name' => 'settings#saveSettings', 'url' => '/api/settings', 'verb' => 'POST'],
		['name' => 'folder#folders', 'url' => '/api/folders', 'verb' => 'GET'],
		['name' => 'scan#scan', 'url' => '/api/library/scan', 'verb' => 'POST'],
		['name' => 'scan#scanSelected', 'url' => '/api/library/scan-selected', 'verb' => 'POST'],
		['name' => 'read#metadata', 'url' => '/api/metadata', 'verb' => 'GET'],
		['name' => 'read#musicBrainz', 'url' => '/api/musicbrainz', 'verb' => 'GET'],
		['name' => 'ai#classifyFolder', 'url' => '/api/ai/classify-folder', 'verb' => 'POST'],
		['name' => 'library#previewMove', 'url' => '/api/library/preview-move', 'verb' => 'POST'],
		['name' => 'library#move', 'url' => '/api/library/move', 'verb' => 'POST'],
		['name' => 'read#changes', 'url' => '/api/changes', 'verb' => 'GET'],
		['name' => 'clientLog#report', 'url' => '/api/client-error', 'verb' => 'POST'],
	],
];
