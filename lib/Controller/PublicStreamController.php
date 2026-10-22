<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCA\HyperViewer\Db\MediaMapper;
use OCA\HyperViewer\Db\PublicLinkMapper;
use OCA\HyperViewer\Service\CacheStoreService;
use OCA\HyperViewer\Service\PublicLinkService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;

class PublicStreamController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private PublicLinkService $publicLinks,
		private PublicLinkMapper $linkMapper,
		private MediaMapper $mediaMapper,
		private CacheStoreService $cacheStore,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function serve(string $token, string $filename): Response {
		$link = $this->publicLinks->resolveActiveByToken($token);
		if ($link === null) {
			return new DataResponse(['error' => 'Not found'], 404);
		}
		$media = $this->mediaMapper->findById($link->getMediaId());
		$fingerprint = $media->getFingerprint();
		$path = $this->cacheStore->resolveFilePath($fingerprint, $filename);
		if ($path === null && $filename === 'master.m3u8') {
			$path = $this->cacheStore->resolveFilePath($fingerprint, 'playlist.m3u8');
		}
		if ($path === null) {
			return new DataResponse(['error' => 'Not found'], 404);
		}
		$this->linkMapper->incrementViews($link->getId());
		$response = new \OCP\AppFramework\Http\StreamResponse($path);
		$response->addHeader('Cache-Control', 'public, max-age=60');
		$response->addHeader('Content-Type', $this->mimeFor($filename));
		return $response;
	}

	private function mimeFor(string $filename): string {
		return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
			'm3u8' => 'application/vnd.apple.mpegurl',
			'ts' => 'video/mp2t',
			'm4s', 'mp4' => 'video/mp4',
			default => 'application/octet-stream',
		};
	}
}
