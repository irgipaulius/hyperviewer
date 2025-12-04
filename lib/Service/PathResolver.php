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
	/**
	 * Calculate cache path based on location type
	 * 
	 * @param string $locationType Either 'relative' or 'home'
	 * @param string $directory The directory containing the video file
	 * @return string The calculated cache path
	 */
	public static function calculateCachePath(string $locationType, string $directory): string {
		if ($locationType === 'home') {
			// Home: cache at user root
			return '.cached_hls';
		}
		
		// Relative: cache in parent directory of the video
		// If directory is empty or root, use root level
		if (empty($directory) || $directory === '/') {
			return '.cached_hls';
		}
		
		// Get parent directory
		$parentDir = dirname($directory);
		if ($parentDir === '.' || $parentDir === '/') {
			return '.cached_hls';
		}
		
		return $parentDir . '/.cached_hls';
	}
}
