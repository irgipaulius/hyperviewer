<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Tests;

use OCA\HyperViewer\Db\JobMapper;
use OCA\HyperViewer\Db\LogMapper;
use PHPUnit\Framework\TestCase;

class PrivacyTest extends TestCase {
	public function testLogRedactionStripsPaths(): void {
		$msg = LogMapper::redactMessage('encode failed for /home/user/files/video.MOV fileid=1331342');
		self::assertStringNotContainsString('/home/user', $msg);
		self::assertStringNotContainsString('1331342', $msg);
	}

	public function testAdminStatusFixtureHasNoPiiKeys(): void {
		$payload = [
			'worker' => ['status' => 'stopped', 'pid' => null],
			'queue' => [
				'queued' => 3,
				'by_type' => ['clip' => 2, 'fingerprint' => 1],
			],
			'cache' => ['total_indexed' => 1774, 'ready' => 1774],
		];
		$json = json_encode($payload, JSON_THROW_ON_ERROR);
		self::assertStringNotContainsString('filename', $json);
		self::assertStringNotContainsString('user_id', $json);
		self::assertStringNotContainsString('fileid', $json);
	}

	public function testErrorCategoriesAreAnonymous(): void {
		self::assertSame('timeout', JobMapper::categorizeError('encode timed out after 3600s'));
		self::assertSame('disk', JobMapper::categorizeError('No space left on device'));
	}
}
