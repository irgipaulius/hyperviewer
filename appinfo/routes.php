<?php

return [
	'routes' => [
		['name' => 'settings#setCacheLocations', 'url' => '/settings/cache-locations', 'verb' => 'POST'],
		['name' => 'settings#getCacheLocations', 'url' => '/settings/cache-locations', 'verb' => 'GET'],
		['name' => 'cache#generateCache', 'url' => '/cache/generate', 'verb' => 'POST'],
		['name' => 'cache#checkCache', 'url' => '/cache/check', 'verb' => 'POST'],
		['name' => 'cache#batchCheckCache', 'url' => '/cache/batch-check', 'verb' => 'POST'],
		['name' => 'cache#getProgress', 'url' => '/cache/progress/{cachePath}', 'verb' => 'GET', 'requirements' => ['cachePath' => '.+']],
		['name' => 'cache#discoverVideos', 'url' => '/cache/discover-videos', 'verb' => 'POST'],
		['name' => 'cache#registerAutoGeneration', 'url' => '/cache/register-auto-generation', 'verb' => 'POST'],
		['name' => 'cache#serveHlsFile', 'url' => '/hls/{cachePath}/{filename}', 'verb' => 'GET', 'requirements' => ['cachePath' => '.+', 'filename' => '.+']],
		
		// Video Clipping
		['name' => 'clip#exportClip', 'url' => '/api/export-clip', 'verb' => 'POST'],
		
		// Management Dashboard API
		['name' => 'cache#getActiveJobs', 'url' => '/api/jobs/active', 'verb' => 'GET'],
		['name' => 'cache#getJobProgress', 'url' => '/api/jobs/active/{filename}', 'verb' => 'GET'],
		['name' => 'cache#getAutoGenerationSettings', 'url' => '/api/auto-generation', 'verb' => 'GET'],
		['name' => 'cache#updateAutoGeneration', 'url' => '/api/auto-generation/{configKey}', 'verb' => 'PUT'],
		['name' => 'cache#removeAutoGeneration', 'url' => '/api/auto-generation/{configKey}', 'verb' => 'DELETE'],
		['name' => 'cache#getJobStatistics', 'url' => '/api/jobs/statistics', 'verb' => 'GET'],
	]
];
