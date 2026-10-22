<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Tests;

use OCA\HyperViewer\Service\ConfigService;
use OCA\HyperViewer\Service\FingerprintService;
use PHPUnit\Framework\TestCase;

class FingerprintServiceTest extends TestCase {
	public function testFingerprintStableForSameBytes(): void {
		$tmp = tempnam(sys_get_temp_dir(), 'hv_fp_');
		$data = str_repeat('x', 5000);
		file_put_contents($tmp, $data);
		$config = $this->createMock(ConfigService::class);
		$config->method('getInt')->willReturn(1);
		$svc = new FingerprintService($config);
		$a = $svc->computeFromPath($tmp, strlen($data));
		$b = $svc->computeFromPath($tmp, strlen($data));
		$this->assertSame($a, $b);
		$this->assertStringStartsWith('v1:', $a);
		unlink($tmp);
	}
}
