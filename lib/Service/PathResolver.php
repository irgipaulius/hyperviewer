<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

class PathResolver {
	
	/**
	 * Resolve cache path (handle tilde as user home directory)
	 */
	public static function resolveCachePath(string $path): string {
		// Handle tilde (~) as reference to user home directory
		if ($path === '~' || str_starts_with($path, '~/')) {
			// Remove ~ and leading slash - we're already in user folder context
			$path = substr($path, 1);
		}
		
		// Remove leading slash
		return ltrim($path, '/');
	}
}
