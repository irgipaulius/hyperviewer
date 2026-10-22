<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Listener;

use OCA\HyperViewer\Db\WatchDirMapper;
use OCA\HyperViewer\Service\ConfigService;
use OCA\HyperViewer\Service\MediaIndexService;
use OCA\HyperViewer\Service\QueueService;
use OCA\HyperViewer\Service\UserPreferencesService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;

/** @implements IEventListener<Event> */
class FileEventListener implements IEventListener {
	public function __construct(
		private WatchDirMapper $watchDirMapper,
		private QueueService $queueService,
		private MediaIndexService $mediaIndex,
		private ConfigService $config,
		private UserPreferencesService $userPreferences,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof NodeDeletedEvent) {
			$node = $event->getNode();
			if ($node instanceof File) {
				$userId = $node->getOwner()?->getUID() ?? '';
				if ($userId !== '') {
					$this->mediaIndex->removeLocationForFile($node, $userId);
				}
			}
			return;
		}
		if ($event instanceof NodeRenamedEvent) {
			$target = $event->getTarget();
			if ($target instanceof File) {
				$userId = $target->getOwner()?->getUID() ?? '';
				if ($userId !== '') {
					$this->mediaIndex->updateLocationPath($target, $userId);
				}
			}
			return;
		}
		if ($event instanceof NodeCopiedEvent) {
			$target = $event->getTarget();
			$source = $event->getSource();
			if ($target instanceof File && $source instanceof File) {
				$userId = $target->getOwner()?->getUID() ?? '';
				$sourceUser = $source->getOwner()?->getUID() ?? $userId;
				$media = $sourceUser !== '' ? $this->mediaIndex->getMediaForFile($source, $sourceUser) : null;
				if ($media !== null) {
					if ($userId !== '') {
						$this->mediaIndex->bindNewFileidToMedia($media->getId(), $target, $userId);
					}
				}
			}
			return;
		}
		if ($event instanceof NodeCreatedEvent || $event instanceof NodeWrittenEvent) {
			$node = $event instanceof NodeCreatedEvent ? $event->getNode() : $event->getNode();
			if (!$node instanceof File) {
				return;
			}
			if (!in_array($node->getMimeType(), ['video/mp4', 'video/quicktime'], true) || $node->getSize() <= 0) {
				return;
			}
			$userId = $node->getOwner()?->getUID();
			if ($userId === null) {
				return;
			}
			$userAuto = $this->userPreferences->getBool(
				$userId,
				UserPreferencesService::KEY_AUTO_GENERATE,
				false,
			);
			if (
				!$this->config->getBool(ConfigService::KEY_AUTO_ENCODE_ALL)
				&& !$this->isWatched($userId, $node->getPath())
				&& !$userAuto
			) {
				return;
			}
			$this->queueService->enqueueFingerprint($userId, $node->getId());
		}
	}

	private function isWatched(string $userId, string $path): bool {
		foreach ($this->watchDirMapper->findEnabledForUser($userId) as $watch) {
			$watchPath = rtrim($watch->getPath(), '/');
			if (str_starts_with($path, $watchPath)) {
				return true;
			}
		}
		return false;
	}
}
