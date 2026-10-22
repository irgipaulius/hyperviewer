<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Files\AppData\IAppDataFactory;

class CacheStoreService {
	private IAppData $appData;

	public function __construct(
		IAppDataFactory $appDataFactory,
		private ConfigService $config,
	) {
		$this->appData = $appDataFactory->get('hyperviewer');
	}

	public function getMediaDir(string $fingerprint, bool $create = false): string {
		$custom = trim($this->config->getString(ConfigService::KEY_CACHE_DIR));
		if ($custom !== '' && is_dir($custom) && is_writable($custom)) {
			$dir = rtrim($custom, '/') . '/' . substr($fingerprint, 0, 2) . '/' . $fingerprint;
			if ($create && !is_dir($dir)) {
				mkdir($dir, 0775, true);
			}
			return $dir;
		}
		$folder = $this->ensureAppDataFolder('hls/' . substr($fingerprint, 0, 2) . '/' . $fingerprint, $create);
		return $folder->getStorage()->getLocalFile($folder->getInternalPath()) ?: '';
	}

	public function getTempEncodeDir(string $fingerprint, int $jobId): string {
		$custom = trim($this->config->getString(ConfigService::KEY_CACHE_DIR));
		$base = $custom !== '' && is_writable($custom)
			? rtrim($custom, '/') . '/.tmp'
			: sys_get_temp_dir() . '/hyperviewer';
		$dir = $base . '/' . $fingerprint . '-' . $jobId;
		if (!is_dir($dir)) {
			mkdir($dir, 0775, true);
		}
		return $dir;
	}

	public function finalizeEncode(string $fingerprint, string $tempDir): void {
		$target = $this->getMediaDir($fingerprint, true);
		if ($target === '') {
			throw new \RuntimeException('Cache store path unavailable');
		}
		if (is_dir($target)) {
			$this->removeDir($target);
		}
		if (!rename($tempDir, $target)) {
			throw new \RuntimeException('Failed to move encoded cache into store');
		}
	}

	public function removeMediaCache(string $fingerprint): void {
		$dir = $this->getMediaDir($fingerprint, false);
		if ($dir !== '' && is_dir($dir)) {
			$this->removeDir($dir);
		}
	}

	public function isAllowedHlsFilename(string $filename): bool {
		return (bool)preg_match('/^[A-Za-z0-9_.-]+\.(m3u8|ts|m4s|mp4)$/', $filename);
	}

	public function resolveFilePath(string $fingerprint, string $filename): ?string {
		if (!$this->isAllowedHlsFilename($filename)) {
			return null;
		}
		$dir = $this->getMediaDir($fingerprint, false);
		if ($dir === '') {
			return null;
		}
		$path = $dir . '/' . $filename;
		return is_file($path) ? $path : null;
	}

	public function copyDirectory(string $source, string $destination): void {
		if (!is_dir($source)) {
			return;
		}
		if (!is_dir($destination) && !mkdir($destination, 0775, true) && !is_dir($destination)) {
			throw new \RuntimeException('Failed to create encode temp directory');
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST,
		);
		foreach ($it as $item) {
			$target = $destination . DIRECTORY_SEPARATOR . $it->getSubPathName();
			if ($item->isDir()) {
				if (!is_dir($target)) {
					mkdir($target, 0775, true);
				}
			} elseif (!is_file($target)) {
				copy($item->getPathname(), $target);
			}
		}
	}

	public function computeCacheBytes(string $fingerprint): int {
		$dir = $this->getMediaDir($fingerprint, false);
		if ($dir === '' || !is_dir($dir)) {
			return 0;
		}
		$bytes = 0;
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
		foreach ($it as $file) {
			if ($file->isFile()) {
				$bytes += $file->getSize();
			}
		}
		return $bytes;
	}

	private function ensureAppDataFolder(string $relative, bool $create): ISimpleFolder {
		$parts = array_values(array_filter(explode('/', $relative)));
		try {
			$current = $this->appData->getFolder($parts[0]);
		} catch (NotFoundException) {
			if (!$create) {
				throw new \RuntimeException('Cache folder missing');
			}
			$current = $this->appData->newFolder($parts[0]);
		}
		for ($i = 1, $c = count($parts); $i < $c; $i++) {
			$part = $parts[$i];
			try {
				$current = $current->getFolder($part);
			} catch (NotFoundException) {
				if (!$create) {
					throw new \RuntimeException('Cache folder missing');
				}
				$current = $current->newFolder($part);
			}
		}
		return $current;
	}

	private function removeDir(string $dir): void {
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($it as $file) {
			if ($file->isDir()) {
				rmdir($file->getPathname());
			} else {
				unlink($file->getPathname());
			}
		}
		rmdir($dir);
	}
}
