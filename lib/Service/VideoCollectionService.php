<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;

/**
 * Single source for discovering video files under a user folder (discover API, scans, file events).
 */
class VideoCollectionService {
	/** @var list<string> */
	public const VIDEO_MIMES = [
		'video/mp4',
		'video/quicktime',
		'video/x-matroska',
		'video/webm',
		'video/x-msvideo',
		'video/x-flv',
		'video/ogg',
		'video/3gpp',
		'video/3gpp2',
		'video/mpeg',
	];

	public function isVideoFile(File $file): bool {
		if ($file->getSize() <= 0) {
			return false;
		}
		return in_array($file->getMimeType(), self::VIDEO_MIMES, true);
	}

	/**
	 * @return list<array{fileid: int, filename: string, path: string}>
	 *         path = file path relative to the user's files root (leading slash)
	 */
	public function collectInFolder(Folder $folder, Folder $userRoot): array {
		$out = [];
		$this->walk($folder, $userRoot, $out);
		return $out;
	}

	/**
	 * @param list<array{fileid: int, filename: string, path: string}> $out
	 */
	private function walk(Folder $folder, Folder $userRoot, array &$out): void {
		foreach ($folder->getDirectoryListing() as $node) {
			if ($node instanceof Folder) {
				$this->walk($node, $userRoot, $out);
				continue;
			}
			if (!$node instanceof File || !$this->isVideoFile($node)) {
				continue;
			}
			$out[] = [
				'fileid' => $node->getId(),
				'filename' => $node->getName(),
				'path' => $this->relativePath($userRoot, $node),
			];
		}
	}

	public function relativePath(Folder $userRoot, File $file): string {
		try {
			$rel = $userRoot->getRelativePath($file->getPath());
		} catch (NotFoundException) {
			$rel = $file->getPath();
		}
		if ($rel === null || $rel === '') {
			$rel = $file->getName();
		}
		$rel = str_replace('\\', '/', $rel);
		if (!str_starts_with($rel, '/')) {
			$rel = '/' . $rel;
		}
		return $rel;
	}

	public function parentDirectoryFromFilePath(string $filePath): string {
		$filePath = str_replace('\\', '/', $filePath);
		$pos = strrpos($filePath, '/');
		if ($pos === false || $pos === 0) {
			return '/';
		}
		return substr($filePath, 0, $pos) ?: '/';
	}
}
