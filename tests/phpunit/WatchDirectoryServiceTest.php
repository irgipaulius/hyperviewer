<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Tests;

use OCA\HyperViewer\Db\WatchDir;
use OCA\HyperViewer\Db\WatchDirMapper;
use OCA\HyperViewer\Service\QueueService;
use OCA\HyperViewer\Service\UserPathService;
use OCA\HyperViewer\Service\WatchDirectoryService;
use PHPUnit\Framework\TestCase;

class WatchDirectoryServiceTest extends TestCase {
	public function testNewWatchDirQueuesOneScan(): void {
		$watch = new WatchDir();
		$watch->setId(1);
		$watch->setPath('/Paulius & Mate/Pictures');

		$mapper = $this->createMock(WatchDirMapper::class);
		$mapper->expects(self::once())
			->method('upsert')
			->willReturn(['watch' => $watch, 'created' => true]);

		$queue = $this->createMock(QueueService::class);
		$queue->expects(self::once())
			->method('enqueueScan')
			->with('paulius', '/Paulius & Mate/Pictures', 1);

		$paths = $this->createMock(UserPathService::class);
		$paths->method('normalizeWatchPath')->willReturnArgument(0);

		$svc = new WatchDirectoryService($mapper, $queue, $paths);
		$result = $svc->register('paulius', '/Paulius & Mate/Pictures', ['720p']);
		self::assertTrue($result['scanQueued']);
	}

	public function testExistingWatchDirDoesNotQueueScan(): void {
		$watch = new WatchDir();
		$watch->setId(1);

		$mapper = $this->createMock(WatchDirMapper::class);
		$mapper->method('upsert')->willReturn(['watch' => $watch, 'created' => false]);

		$queue = $this->createMock(QueueService::class);
		$queue->expects(self::never())->method('enqueueScan');

		$paths = $this->createMock(UserPathService::class);
		$paths->method('normalizeWatchPath')->willReturnArgument(0);

		$svc = new WatchDirectoryService($mapper, $queue, $paths);
		$result = $svc->register('paulius', '/Paulius & Mate/Pictures', ['720p']);
		self::assertFalse($result['scanQueued']);
	}
}
