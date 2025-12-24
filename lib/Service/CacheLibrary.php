<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCA\HyperViewer\Service\Repositories\FileCacheRepository;
use OCP\IDBConnection;

class CacheLibrary {
	private FileCacheRepository $repo;

	public function __construct(?FileCacheRepository $repo = null, ?IDBConnection $conn = null) {
		if ($repo !== null) {
			$this->repo = $repo;
			return;
		}

		if ($conn === null) {
			$conn = \OC::$server->getDatabaseConnection();
		}

		$this->repo = new FileCacheRepository($conn);
	}

	public function registerCache(int $fileId, string $filePath, string $cachePath): void {
		[$cacheSize, $originalContentLength] = $this->calculateSizes($filePath, $cachePath);
		$this->repo->upsert($fileId, $filePath, $cachePath, $cacheSize, $originalContentLength);
	}

	public function calculateSizes(string $filePath, string $cachePath): array {
		$originalContentLength = $this->getSize($filePath);
		$cacheSize = $this->getSize($cachePath);
		return [
			$cacheSize,
			$originalContentLength,
		];
	}

	private function getSize(string $path): ?int {
		if ($path === '') {
			return null;
		}

		if (!is_file($path) && !is_dir($path)) {
			return null;
		}

		$escapedPath = escapeshellarg($path);
		$duOutput = shell_exec('du -sk ' . $escapedPath);
		if (!$duOutput) {
			return null;
		}

		$parts = preg_split('/\s+/', trim($duOutput));
		if (!isset($parts[0]) || !is_numeric($parts[0])) {
			return null;
		}

		return (int)$parts[0] * 1024;
	}
}
