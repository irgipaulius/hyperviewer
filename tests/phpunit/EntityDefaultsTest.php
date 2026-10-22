<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Tests;

use OCA\HyperViewer\Db\Job;
use OCA\HyperViewer\Db\Location;
use OCA\HyperViewer\Db\Media;
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
}
