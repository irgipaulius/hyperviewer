<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

class BinaryFinder {
	public function findFfmpeg(): string {
		return $this->which('ffmpeg');
	}

	public function findFfprobe(): string {
		return $this->which('ffprobe');
	}

	private function which(string $name): string {
		$path = getenv('PATH') ?: '/usr/bin:/usr/local/bin:/bin';
		foreach (explode(':', $path) as $dir) {
			$candidate = rtrim($dir, '/') . '/' . $name;
			if (is_executable($candidate)) {
				return $candidate;
			}
		}
		return '';
	}
}
