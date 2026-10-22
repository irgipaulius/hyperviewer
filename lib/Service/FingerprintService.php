<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCP\Files\File;

class FingerprintService {
	public function __construct(
		private ConfigService $config,
	) {
	}

	public function computeFromFile(File $file): string {
		$local = $file->getStorage()->getLocalFile($file->getInternalPath());
		if ($local === false || !is_readable($local)) {
			throw new \RuntimeException('Cannot read file for fingerprinting');
		}
		return $this->computeFromPath($local, $file->getSize());
	}

	public function computeFromPath(string $path, int $size): string {
		$sampleBytes = $this->config->getInt(ConfigService::KEY_FINGERPRINT_MB) * 1024 * 1024;
		$head = $this->readChunk($path, 0, min($sampleBytes, $size));
		$tailStart = max(0, $size - $sampleBytes);
		$tail = $this->readChunk($path, $tailStart, min($sampleBytes, $size - $tailStart));
		$payload = $head . $tail . pack('J', $size);
		return 'v1:' . $size . ':' . hash('sha256', $payload);
	}

	private function readChunk(string $path, int $offset, int $length): string {
		if ($length <= 0) {
			return '';
		}
		$fh = fopen($path, 'rb');
		if ($fh === false) {
			throw new \RuntimeException('Failed to open file for fingerprint');
		}
		fseek($fh, $offset);
		$data = fread($fh, $length);
		fclose($fh);
		return $data === false ? '' : $data;
	}
}
