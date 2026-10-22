#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Standalone importer for legacy .cached_hls trees (not registered as an occ command).
 *
 * Usage:
 *   NEXTCLOUD_ROOT=/path/to/nextcloud php scripts/convert_cached_hls.php --user=alice [--path=...] [--dry-run] [--limit=100] [--force]
 */

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "CLI only\n");
	exit(1);
}

$longopts = ['nextcloud-root:', 'user:', 'path:', 'dry-run', 'limit:', 'force'];
$opts = getopt('', $longopts);
$ncRoot = $opts['nextcloud-root'] ?? getenv('NEXTCLOUD_ROOT') ?: '';
if ($ncRoot === '' || !is_dir($ncRoot)) {
	fwrite(STDERR, "Set NEXTCLOUD_ROOT or pass --nextcloud-root= to your Nextcloud install.\n");
	exit(1);
}

require_once rtrim($ncRoot, '/') . '/lib/base.php';
\OC::$CLI = true;

if (!\OCP\Server::get(\OCP\App\IAppManager::class)->isEnabledForAnyone('hyperviewer')) {
	fwrite(STDERR, "Enable the hyperviewer app before running conversion.\n");
	exit(1);
}
\OC_App::loadApp('hyperviewer');

try {
	/** @var \OCA\HyperViewer\Service\CacheAdoptService $adopt */
	$adopt = \OCP\Server::get(\OCA\HyperViewer\Service\CacheAdoptService::class);
	$report = $adopt->run([
		'user' => $opts['user'] ?? null,
		'path' => $opts['path'] ?? null,
		'dry_run' => array_key_exists('dry-run', $opts),
		'force' => array_key_exists('force', $opts),
		'copy' => true,
		'move' => false,
		'limit' => isset($opts['limit']) ? (int)$opts['limit'] : null,
		'cleanup_adopted' => false,
	]);
	echo json_encode($report, JSON_PRETTY_PRINT) . "\n";
	exit(isset($report['error']) ? 1 : 0);
} catch (\Throwable $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}
