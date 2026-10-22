<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCA\HyperViewer\Db\Location;
use OCA\HyperViewer\Db\LocationMapper;
use OCA\HyperViewer\Db\Media;
use OCA\HyperViewer\Db\MediaMapper;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

class MediaIndexService {
	public function __construct(
		private IRootFolder $rootFolder,
		private MediaMapper $mediaMapper,
		private LocationMapper $locationMapper,
		private FingerprintService $fingerprintService,
	) {
	}

	public function resolveFileForUser(string $userId, int $fileid): File {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$nodes = $userFolder->getById($fileid);
		if ($nodes === []) {
			throw new NotFoundException('File not found');
		}
		$node = $nodes[0];
		if (!$node instanceof File) {
			throw new NotFoundException('Not a file');
		}
		if (!$node->isReadable()) {
			throw new \RuntimeException('Permission denied');
		}
		return $node;
	}

	public function getMediaForFileid(int $fileid, ?string $userId = null): ?Media {
		if ($userId !== null && $userId !== '') {
			try {
				$file = $this->resolveFileForUser($userId, $fileid);
				return $this->getMediaForFile($file, $userId);
			} catch (\Throwable) {
				// fall through to fileid hint
			}
		}
		$loc = $this->locationMapper->findByFileid($fileid);
		if ($loc === null) {
			return null;
		}
		try {
			return $this->mediaMapper->findById($loc->getMediaId());
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	public function getMediaForFile(File $file, string $userId): ?Media {
		$loc = $this->findLocationByFile($file, $userId);
		if ($loc === null) {
			return null;
		}
		try {
			return $this->mediaMapper->findById($loc->getMediaId());
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	public function findLocationByFile(File $file, string $userId): ?Location {
		$internal = $this->toInternalPath($file, $userId);
		$pathHash = hash('sha256', $internal);
		$storageId = $file->getStorageId();
		$loc = $this->locationMapper->findByStorageAndPathHash($storageId, $pathHash);
		if ($loc !== null) {
			return $loc;
		}
		if ($file->getId() > 0) {
			return $this->locationMapper->findByFileid($file->getId());
		}
		return null;
	}

	public function indexOrRebind(File $file, string $userId, bool $forceFingerprint = false): Media {
		$existing = $this->findLocationByFile($file, $userId);
		if ($existing !== null && !$forceFingerprint) {
			if ($existing->getSize() === $file->getSize() && $existing->getMtime() === $file->getMTime()) {
				if ($file->getId() > 0 && $existing->getFileid() !== $file->getId()) {
					$existing->setFileid($file->getId());
					$this->locationMapper->update($existing);
				}
				return $this->mediaMapper->findById($existing->getMediaId());
			}
		}
		$fingerprint = $this->fingerprintService->computeFromFile($file);
		$media = $this->mediaMapper->findByFingerprint($fingerprint);
		if ($media === null) {
			$media = new Media();
			$media->setFingerprint($fingerprint);
			$media->setSize($file->getSize());
			$media->setCacheState('none');
			$media->setCreatedAt(time());
			$media->setLastAccessAt(time());
			$media = $this->mediaMapper->insert($media);
		}
		$this->upsertLocation($media, $file, $userId, $existing);
		return $media;
	}

	public function updateLocationPath(File $file, string $userId): void {
		$internal = $this->toInternalPath($file, $userId);
		$loc = $this->findLocationByFile($file, $userId);
		if ($loc === null && $file->getId() > 0) {
			$loc = $this->locationMapper->findByFileid($file->getId());
		}
		if ($loc === null) {
			return;
		}
		$loc->setPath($internal);
		$loc->setPathHash(hash('sha256', $internal));
		$loc->setMtime($file->getMTime());
		$loc->setSize($file->getSize());
		$loc->setStorageId($file->getStorageId());
		$loc->setFileid($file->getId() > 0 ? $file->getId() : null);
		$loc->setSeenAt(time());
		$this->locationMapper->update($loc);
	}

	public function bindNewFileidToMedia(int $mediaId, File $file, string $userId): Location {
		$existing = $this->findLocationByFile($file, $userId);
		if ($existing !== null) {
			return $existing;
		}
		$media = $this->mediaMapper->findById($mediaId);
		return $this->upsertLocation($media, $file, $userId, null);
	}

	public function removeLocationForFile(File $file, string $userId): void {
		$loc = $this->findLocationByFile($file, $userId);
		if ($loc !== null) {
			$this->locationMapper->delete($loc);
			return;
		}
		if ($file->getId() > 0) {
			$this->locationMapper->deleteByFileid($file->getId());
		}
	}

	public function removeLocationByFileid(int $fileid): void {
		$this->locationMapper->deleteByFileid($fileid);
	}

	private function upsertLocation(Media $media, File $file, string $userId, ?Location $existing): Location {
		$internal = $this->toInternalPath($file, $userId);
		$pathHash = hash('sha256', $internal);
		$storageId = $file->getStorageId();
		if ($existing === null) {
			$existing = $this->locationMapper->findByStorageAndPathHash($storageId, $pathHash);
		}
		if ($existing !== null) {
			$existing->setMediaId($media->getId());
			$existing->setPath($internal);
			$existing->setPathHash($pathHash);
			$existing->setSize($file->getSize());
			$existing->setMtime($file->getMTime());
			$existing->setStorageId($storageId);
			$existing->setFileid($file->getId() > 0 ? $file->getId() : null);
			$existing->setSeenAt(time());
			return $this->locationMapper->update($existing);
		}
		$loc = new Location();
		$loc->setMediaId($media->getId());
		$loc->setFileid($file->getId() > 0 ? $file->getId() : null);
		$loc->setStorageId($storageId);
		$loc->setPath($internal);
		$loc->setPathHash($pathHash);
		$loc->setUserId($userId);
		$loc->setSize($file->getSize());
		$loc->setMtime($file->getMTime());
		$loc->setSeenAt(time());
		return $this->locationMapper->insert($loc);
	}

	private function toInternalPath(File $file, string $userId): string {
		$internal = $file->getInternalPath();
		if ($internal !== '') {
			return $internal;
		}
		return ltrim(str_replace('/' . $userId . '/files', '', $file->getPath()), '/');
	}

	public function assertUserCanReadMedia(string $userId, int $mediaId): void {
		if (!$this->locationMapper->userCanAccessMedia($userId, $mediaId)) {
			throw new \RuntimeException('Access denied');
		}
	}
}
