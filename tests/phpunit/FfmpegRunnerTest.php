<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Tests;

use OCA\HyperViewer\Service\ConfigService;
use OCA\HyperViewer\Service\FfmpegRunner;
use PHPUnit\Framework\TestCase;

/** Regressions from the 2.1.5 worker launch on a FreeBSD jail. */
class FfmpegRunnerTest extends TestCase {
	private function script(string $body): string {
		$f = tempnam(sys_get_temp_dir(), 'hv_bin_');
		file_put_contents($f, "#!/bin/sh\n" . $body . "\n");
		chmod($f, 0755);
		return $f;
	}

	public function testIdprioThatFailsIsNotUsable(): void {
		// Jailed www: "idprio: RTP_SET: Operation not permitted", exit 1.
		$bin = $this->script('echo "idprio: RTP_SET: Operation not permitted" >&2; exit 1');
		$this->assertFalse(FfmpegRunner::idprioUsable($bin));
		unlink($bin);
	}

	public function testIdprioThatWorksIsUsable(): void {
		$bin = $this->script('shift; exec "$@"');
		$this->assertTrue(FfmpegRunner::idprioUsable($bin));
		unlink($bin);
	}

	public function testStderrIsCapturedOnFailure(): void {
		$bin = $this->script('echo "Unknown encoder libfoo" >&2; exit 3');
		$config = $this->createMock(ConfigService::class);
		$config->method('getString')->willReturnCallback(
			fn (string $k) => $k === ConfigService::KEY_FFMPEG ? $bin : 'none'
		);
		$config->method('getInt')->willReturn(60);
		$runner = new FfmpegRunner($config);
		$this->assertSame(3, $runner->run(['-i', 'x']));
		$this->assertStringContainsString('Unknown encoder libfoo', $runner->getLastStderr());
		unlink($bin);
	}
}
