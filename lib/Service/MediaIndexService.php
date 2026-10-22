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
use OCP\Files\Storage\IStorage;

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

	public function getMediaForFileid(int $fileid): ?Media {
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

	public function indexOrRebind(File $file, string $userId, bool $forceFingerprint = false): Media {
		$fileid = $file->getId();
		$existing = $this->locationMapper->findByFileid($fileid);
		if ($existing !== null && !$forceFingerprint) {
			if ($existing->getSize() === $file->getSize() && $existing->getMtime() === $file->getMTime()) {
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

	public function updateLocationPath(int $fileid, string $newPath, int $mtime, int $size): void {
		$loc = $this->locationMapper->findByFileid($fileid);
		if ($loc === null) {
			return;
		}
		$loc->setPath($newPath);
		$loc->setPathHash(hash('sha256', $newPath));
		$loc->setMtime($mtime);
		$loc->setSize($size);
		$loc->setSeenAt(time());
		$this->locationMapper->update($loc);
	}

	public function bindNewFileidToMedia(int $mediaId, File $file, string $userId): Location {
		$existing = $this->locationMapper->findByFileid($file->getId());
		if ($existing !== null) {
			return $existing;
		}
		$media = $this->mediaMapper->findById($mediaId);
		return $this->upsertLocation($media, $file, $userId, null);
	}

	public function removeLocationByFileid(int $fileid): void {
		$this->locationMapper->deleteByFileid($fileid);
	}

	private function upsertLocation(Media $media, File $file, string $userId, ?Location $existing): Location {
		$path = $file->getPath();
		$internal = ltrim(str_replace('/' . $userId . '/files', '', $path), '/');
		if ($existing !== null) {
			$existing->setMediaId($media->getId());
			$existing->setPath($internal);
			$existing->setPathHash(hash('sha256', $internal));
			$existing->setSize($file->getSize());
			$existing->setMtime($file->getMTime());
			$existing->setStorageId((int)$file->getStorageId());
			$existing->setSeenAt(time());
			return $this->locationMapper->update($existing);
		}
		$loc = new Location();
		$loc->setMediaId($media->getId());
		$loc->setFileid($file->getId());
		$loc->setStorageId((int)$file->getStorageId());
		$loc->setPath($internal);
		$loc->setPathHash(hash('sha256', $internal));
		$loc->setUserId($userId);
		$loc->setSize($file->getSize());
		$loc->setMtime($file->getMTime());
		$loc->setSeenAt(time());
		return $this->locationMapper->insert($loc);
	}

	public function assertUserCanReadMedia(string $userId, int $mediaId): void {
		if (!$this->locationMapper->userCanAccessMedia($userId, $mediaId)) {
			throw new \RuntimeException('Access denied');
		}
	}
}
