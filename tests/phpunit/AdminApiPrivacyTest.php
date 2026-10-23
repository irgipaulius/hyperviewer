<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Tests;

use PHPUnit\Framework\TestCase;

class AdminApiPrivacyTest extends TestCase {
	/** @return list<string> */
	private function forbiddenKeys(): array {
		return ['filename', 'fileid', 'user_id', 'path', 'directory', 'files_dir', 'fingerprint', 'cache_directory', '/var/'];
	}

	public function testAdminStatusFixtureContainsNoForbiddenKeys(): void {
		$payload = [
			'worker' => ['status' => 'stopped'],
			'queue' => [
				'queued' => 2,
				'queued_depth_histogram' => [['depth' => 1, 'users' => 2]],
			],
			'cache' => ['total_indexed' => 10, 'ready' => 8],
		];
		$json = json_encode($payload, JSON_THROW_ON_ERROR);
		foreach ($this->forbiddenKeys() as $key) {
			self::assertStringNotContainsString('"' . $key . '"', $json);
		}
	}

	public function testHistogramIsAnonymous(): void {
		$row = ['depth' => 3, 'users' => 2];
		self::assertArrayNotHasKey('user_id', $row);
		self::assertArrayNotHasKey('fileid', $row);
	}
}
