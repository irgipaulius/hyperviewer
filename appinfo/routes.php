<?php

return [
	'routes' => [
		['name' => 'cache#generateCache', 'url' => '/cache/generate', 'verb' => 'POST'],
		['name' => 'cache#checkCache', 'url' => '/cache/check', 'verb' => 'POST'],
		['name' => 'cache#batchCheckCache', 'url' => '/cache/batch-check', 'verb' => 'POST'],
		['name' => 'cache#getProgress', 'url' => '/cache/progress/{cachePath}', 'verb' => 'GET', 'requirements' => ['cachePath' => '.+']],
		['name' => 'cache#discoverVideos', 'url' => '/cache/discover-videos', 'verb' => 'POST'],
		['name' => 'cache#registerAutoGeneration', 'url' => '/cache/register-auto-generation', 'verb' => 'POST'],
		['name' => 'cache#serveHlsFile', 'url' => '/hls/{cachePath}/{filename}', 'verb' => 'GET', 'requirements' => ['cachePath' => '.+', 'filename' => '.+']],
		['name' => 'cache#extractFrame', 'url' => '/api/extract-frame', 'verb' => 'POST'],
		
		// Video Clipping
		['name' => 'clip#exportClip', 'url' => '/api/export-clip', 'verb' => 'POST'],
		
		// Management Dashboard API
		['name' => 'cache#getActiveJobs', 'url' => '/api/jobs/active', 'verb' => 'GET'],
		['name' => 'cache#getJobById', 'url' => '/api/jobs/active/{id}', 'verb' => 'GET'],
		['name' => 'cache#deleteJob', 'url' => '/api/jobs/active/{id}', 'verb' => 'DELETE'],
		['name' => 'cache#getAutoGenerationSettings', 'url' => '/api/auto-generation', 'verb' => 'GET'],
		['name' => 'cache#updateAutoGeneration', 'url' => '/api/auto-generation/{configKey}', 'verb' => 'PUT'],
		['name' => 'cache#removeAutoGeneration', 'url' => '/api/auto-generation/{configKey}', 'verb' => 'DELETE'],
		['name' => 'cache#getJobStatistics', 'url' => '/api/jobs/statistics', 'verb' => 'GET'],
	]
];
