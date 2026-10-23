<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Tests;

use OCA\HyperViewer\Service\UserPathService;
use PHPUnit\Framework\TestCase;

class UserPathServiceTest extends TestCase {
	public function testStripsDataDirectoryPrefix(): void {
		$s = new UserPathService();
		self::assertSame(
			'/Videos/inbox',
			$s->normalizeWatchPath('/var/www/data/paulius/files/Videos/inbox', 'paulius'),
		);
	}

	public function testKeepsUserRelativePath(): void {
		$s = new UserPathService();
		self::assertSame('/Clips', $s->normalizeWatchPath('Clips', 'paulius'));
	}
}
