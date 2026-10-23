<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

/**
 * Paths stored and returned to clients are always relative to the user's Files root (/Videos/foo).
 */
class UserPathService {
	public function normalizeWatchPath(string $path, ?string $userId = null): string {
		$path = str_replace('\\', '/', trim($path));
		if ($path === '') {
			return '/';
		}
		// Strip absolute server / data-directory prefixes if they were persisted historically.
		if (preg_match('#^/var/[^/]+/data/[^/]+/files(?=/|$)(.*)$#', $path, $m) === 1) {
			$path = $m[1] !== '' ? $m[1] : '/';
		} elseif (preg_match('#/data/([^/]+)/files(?=/|$)(.*)$#', $path, $m) === 1) {
			if ($userId === null || $m[1] === $userId) {
				$path = $m[2] !== '' ? $m[2] : '/';
			}
		} elseif (preg_match('#^/home/[^/]+/[^/]+/files(?=/|$)(.*)$#', $path, $m) === 1) {
			$path = $m[1] !== '' ? $m[1] : '/';
		}
		if ($path !== '/' && !str_starts_with($path, '/')) {
			$path = '/' . $path;
		}
		$path = preg_replace('#/+#', '/', $path) ?? $path;
		if ($path !== '/' && str_ends_with($path, '/')) {
			$path = rtrim($path, '/') ?: '/';
		}
		return $path;
	}
}
