<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Tests;

use OCA\HyperViewer\Db\Job;
use OCA\HyperViewer\Db\Location;
use OCA\HyperViewer\Db\LogEntry;
use OCA\HyperViewer\Db\Media;
use OCA\HyperViewer\Db\PublicLink;
use OCA\HyperViewer\Db\WatchDir;
use PHPUnit\Framework\TestCase;

class EntityDefaultsTest extends TestCase {
	public function testJobAcceptsExplicitStringsAfterNullDefaults(): void {
		$job = new Job();
		$job->setType('clip');
		$job->setUserId('alice');
		$job->setState('queued');
		self::assertSame('clip', $job->getType());
		self::assertSame('alice', $job->getUserId());
		self::assertSame('queued', $job->getState());
	}

	public function testMediaAndLocationNullDefaults(): void {
		$media = new Media();
		$media->setFingerprint('abc');
		$media->setCacheState('ready');
		self::assertSame('abc', $media->getFingerprint());
		self::assertSame('ready', $media->getCacheState());

		$loc = new Location();
		$loc->setPath('/Videos/a.mov');
		$loc->setUserId('bob');
		self::assertSame('/Videos/a.mov', $loc->getPath());
		self::assertSame('bob', $loc->getUserId());
	}

	public function testWatchDirPublicLinkLogDefaults(): void {
		$wd = new WatchDir();
		$wd->setUserId('u1');
		$wd->setPath('/inbox');
		self::assertSame('u1', $wd->getUserId());

		$link = new PublicLink();
		$link->setToken('abc');
		$link->setUserId('u1');
		$link->setFileid(1);
		$link->setMediaId(2);
		self::assertSame('abc', $link->getToken());

		$log = new LogEntry();
		$log->setLevel('info');
		$log->setChannel('worker');
		$log->setMessage('ok');
		$log->setCreatedAt(time());
		self::assertSame('info', $log->getLevel());
	}
}
