<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'media#status', 'url' => '/api/media/status', 'verb' => 'GET'],
		['name' => 'media#generate', 'url' => '/cache/generate', 'verb' => 'POST'],
		['name' => 'media#check', 'url' => '/cache/check', 'verb' => 'POST'],
		['name' => 'cache#batchCheckCache', 'url' => '/cache/batch-check', 'verb' => 'POST'],
		['name' => 'cache#discoverVideos', 'url' => '/cache/discover-videos', 'verb' => 'POST'],

		['name' => 'hls#serve', 'url' => '/hls/{fingerprint}/{filename}', 'verb' => 'GET', 'requirements' => ['fingerprint' => '.+', 'filename' => '.+']],

		['name' => 'frame#extractFrame', 'url' => '/api/frame', 'verb' => 'POST'],

		['name' => 'clip#exportClip', 'url' => '/api/export-clip', 'verb' => 'POST'],

		['name' => 'job#get', 'url' => '/api/jobs/{id}', 'verb' => 'GET', 'requirements' => ['id' => '\d+']],
		['name' => 'job#listJobs', 'url' => '/api/jobs', 'verb' => 'GET'],
		['name' => 'job#active', 'url' => '/api/jobs/active', 'verb' => 'GET'],
		['name' => 'job#statistics', 'url' => '/api/jobs/statistics', 'verb' => 'GET'],
		['name' => 'job#retry', 'url' => '/api/jobs/{id}/retry', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'job#cancel', 'url' => '/api/jobs/{id}/cancel', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'job#bump', 'url' => '/api/jobs/{id}/bump', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],
		['name' => 'job#batchStatus', 'url' => '/api/jobs/batch-status', 'verb' => 'POST'],
		['name' => 'job#batchDelete', 'url' => '/api/jobs/batch-delete', 'verb' => 'POST'],

		['name' => 'watch#list', 'url' => '/api/auto-generation', 'verb' => 'GET'],
		['name' => 'watch#register', 'url' => '/cache/register-auto-generation', 'verb' => 'POST'],
		['name' => 'watch#remove', 'url' => '/api/auto-generation/{id}', 'verb' => 'DELETE', 'requirements' => ['id' => '\d+']],
		['name' => 'watch#scanNow', 'url' => '/api/auto-generation/{id}/scan', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		['name' => 'admin#overview', 'url' => '/api/admin/overview', 'verb' => 'GET'],
		['name' => 'admin#status', 'url' => '/api/admin/status', 'verb' => 'GET'],
		['name' => 'admin#getSettings', 'url' => '/api/admin/settings', 'verb' => 'GET'],
		['name' => 'admin#setSetting', 'url' => '/api/admin/settings', 'verb' => 'POST'],
		['name' => 'admin#resetSetting', 'url' => '/api/admin/settings/reset', 'verb' => 'POST'],
		['name' => 'admin#logs', 'url' => '/api/admin/logs', 'verb' => 'GET'],

	],
];
