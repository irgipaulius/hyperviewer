<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Controller;

use OCA\HyperViewer\Db\LocationMapper;
use OCA\HyperViewer\Db\MediaMapper;
use OCA\HyperViewer\Service\CacheStoreService;
use OCA\HyperViewer\Service\MediaIndexService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;

class HlsController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IUserSession $userSession,
		private MediaMapper $mediaMapper,
		private LocationMapper $locationMapper,
		private MediaIndexService $mediaIndex,
		private CacheStoreService $cacheStore,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function serve(string $fingerprint, string $filename): Response {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new DataResponse(['error' => 'Unauthorized'], 401);
		}
		$media = $this->mediaMapper->findByFingerprint($fingerprint);
		if ($media === null) {
			return new DataResponse(['error' => 'Not found'], 404);
		}
		try {
			$this->mediaIndex->assertUserCanReadMedia($user->getUID(), $media->getId());
		} catch (\Throwable) {
			return new DataResponse(['error' => 'Forbidden'], 403);
		}
		$path = $this->cacheStore->resolveFilePath($fingerprint, $filename);
		if ($path === null) {
			return new DataResponse(['error' => 'Not found'], 404);
		}
		$this->mediaMapper->touchAccess($media->getId());
		$response = new \OCP\AppFramework\Http\StreamResponse($path);
		$response->addHeader('Cache-Control', 'private, max-age=3600');
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
