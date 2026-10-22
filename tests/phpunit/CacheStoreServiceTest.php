<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Tests;

use OCA\HyperViewer\Service\CacheStoreService;
use PHPUnit\Framework\TestCase;

class CacheStoreServiceTest extends TestCase {
	public function testHlsFilenameWhitelist(): void {
		$store = new CacheStoreService(
			$this->createMock(\OCP\IAppDataFactory::class),
			$this->createMock(\OCA\HyperViewer\Service\ConfigService::class),
		);
		$this->assertTrue($store->isAllowedHlsFilename('master.m3u8'));
		$this->assertTrue($store->isAllowedHlsFilename('seg001.ts'));
		$this->assertFalse($store->isAllowedHlsFilename('../etc/passwd'));
		$this->assertFalse($store->isAllowedHlsFilename('evil.php'));
	}
}
