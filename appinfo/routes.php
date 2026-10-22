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
		['name' => 'publicStream#serve', 'url' => '/public/hls/{token}/{filename}', 'verb' => 'GET', 'requirements' => ['token' => '.+', 'filename' => '.+']],

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
		['name' => 'watch#setEnabled', 'url' => '/api/auto-generation/{id}/enabled', 'verb' => 'POST', 'requirements' => ['id' => '\d+']],

		['name' => 'user#capabilities', 'url' => '/api/user/capabilities', 'verb' => 'GET'],
		['name' => 'user#overview', 'url' => '/api/user/overview', 'verb' => 'GET'],
		['name' => 'user#library', 'url' => '/api/user/library', 'verb' => 'GET'],
		['name' => 'user#history', 'url' => '/api/user/history', 'verb' => 'GET'],
		['name' => 'user#logs', 'url' => '/api/user/logs', 'verb' => 'GET'],
		['name' => 'user#cancelMyQueued', 'url' => '/api/user/queue/cancel-queued', 'verb' => 'POST'],
		['name' => 'user#preferences', 'url' => '/api/user/preferences', 'verb' => 'GET'],
		['name' => 'user#savePreferences', 'url' => '/api/user/preferences', 'verb' => 'POST'],
		['name' => 'user#pinLibraryItem', 'url' => '/api/user/library/pin', 'verb' => 'POST'],
		['name' => 'user#regenerateLibraryItem', 'url' => '/api/user/library/regenerate', 'verb' => 'POST'],
		['name' => 'user#deleteLibraryCache', 'url' => '/api/user/library/delete-cache', 'verb' => 'POST'],
		['name' => 'user#streamUrl', 'url' => '/api/user/library/stream-url', 'verb' => 'GET'],
		['name' => 'user#listPublicLinks', 'url' => '/api/user/links', 'verb' => 'GET'],
		['name' => 'user#createPublicLink', 'url' => '/api/user/links', 'verb' => 'POST'],
		['name' => 'user#revokePublicLink', 'url' => '/api/user/links/revoke', 'verb' => 'POST'],
		['name' => 'user#exportLogs', 'url' => '/api/user/logs/export', 'verb' => 'GET'],

		['name' => 'admin#overview', 'url' => '/api/admin/overview', 'verb' => 'GET'],
		['name' => 'admin#status', 'url' => '/api/admin/status', 'verb' => 'GET'],
		['name' => 'admin#pauseQueue', 'url' => '/api/admin/queue/pause', 'verb' => 'POST'],
		['name' => 'admin#cancelFailed', 'url' => '/api/admin/queue/cancel-failed', 'verb' => 'POST'],
		['name' => 'admin#purgeLogs', 'url' => '/api/admin/logs/purge', 'verb' => 'POST'],
		['name' => 'admin#drainQueue', 'url' => '/api/admin/queue/drain', 'verb' => 'POST'],
		['name' => 'admin#verifyCache', 'url' => '/api/admin/cache/verify', 'verb' => 'POST'],
		['name' => 'admin#rebuildIndex', 'url' => '/api/admin/index/rebuild', 'verb' => 'POST'],
		['name' => 'admin#purgeHistory', 'url' => '/api/admin/history/purge', 'verb' => 'POST'],
		['name' => 'admin#diagnosticReport', 'url' => '/api/admin/diagnostic-report', 'verb' => 'GET'],
		['name' => 'admin#getSettings', 'url' => '/api/admin/settings', 'verb' => 'GET'],
		['name' => 'admin#setSetting', 'url' => '/api/admin/settings', 'verb' => 'POST'],
		['name' => 'admin#resetSetting', 'url' => '/api/admin/settings/reset', 'verb' => 'POST'],
		['name' => 'admin#logs', 'url' => '/api/admin/logs', 'verb' => 'GET'],

	],
];
