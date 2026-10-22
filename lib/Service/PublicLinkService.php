<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCA\HyperViewer\Db\PublicLink;
use OCA\HyperViewer\Db\PublicLinkMapper;
use OCP\IURLGenerator;
use OCP\Security\ISecureRandom;

class PublicLinkService {
	public function __construct(
		private PublicLinkMapper $linkMapper,
		private MediaIndexService $mediaIndex,
		private ISecureRandom $random,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function create(string $userId, int $fileid, ?int $expiresAt): PublicLink {
		$file = $this->mediaIndex->resolveFileForUser($userId, $fileid);
		$media = $this->mediaIndex->getMediaForFile($file, $userId);
		if ($media === null || $media->getCacheState() === 'none') {
			throw new \RuntimeException('No HLS cache for this file');
		}
		$link = new PublicLink();
		$link->setUserId($userId);
		$link->setFileid($fileid);
		$link->setMediaId($media->getId());
		$link->setToken($this->random->generate(48, ISecureRandom::CHAR_ALPHANUMERIC));
		$link->setExpiresAt($expiresAt);
		$link->setViewCount(0);
		$link->setCreatedAt(time());
		return $this->linkMapper->insert($link);
	}

	public function revoke(string $userId, int $linkId): void {
		$link = $this->linkMapper->findById($linkId);
		if ($link->getUserId() !== $userId) {
			throw new \RuntimeException('Forbidden');
		}
		$link->setRevokedAt(time());
		$this->linkMapper->update($link);
	}

	public function resolveActiveByToken(string $token): ?PublicLink {
		$link = $this->linkMapper->findByToken($token);
		if ($link === null || $link->getRevokedAt() !== null) {
			return null;
		}
		$exp = $link->getExpiresAt();
		if ($exp !== null && $exp > 0 && $exp < time()) {
			return null;
		}
		return $link;
	}

	public function publicUrl(PublicLink $link, string $filename = 'master.m3u8'): string {
		return $this->urlGenerator->linkToRouteAbsolute(
			'hyperviewer.publicStream#serve',
			['token' => $link->getToken(), 'filename' => $filename],
		);
	}
}
