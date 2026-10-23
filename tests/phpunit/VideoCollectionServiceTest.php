<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Tests;

use OCA\HyperViewer\Service\VideoCollectionService;
use OCP\Files\File;
use OCP\Files\Folder;
use PHPUnit\Framework\TestCase;

class VideoCollectionServiceTest extends TestCase {
	public function testCollectsNestedVideosWithRelativePaths(): void {
		$deep = $this->mockFile(99, 'nested clip.mp4', '/Paulius & Mate/Pictures/a/b/nested clip.mp4', 'video/mp4', 1000);
		$sub = $this->mockFolder('/Paulius & Mate/Pictures/a/b', [$deep]);
		$mid = $this->mockFolder('/Paulius & Mate/Pictures/a', [$sub]);
		$root = $this->mockFolder('/Paulius & Mate/Pictures', [$mid]);
		$userRoot = $this->createMock(Folder::class);
		$userRoot->method('getRelativePath')->willReturnCallback(
			static fn (string $path) => $path,
		);

		$svc = new VideoCollectionService();
		$files = $svc->collectInFolder($root, $userRoot);

		self::assertCount(1, $files);
		self::assertSame(99, $files[0]['fileid']);
		self::assertSame('nested clip.mp4', $files[0]['filename']);
		self::assertSame('/Paulius & Mate/Pictures/a/b/nested clip.mp4', $files[0]['path']);
	}

	public function testParentDirectoryFromFilePath(): void {
		$svc = new VideoCollectionService();
		self::assertSame('/Paulius & Mate/Pictures/a/b', $svc->parentDirectoryFromFilePath('/Paulius & Mate/Pictures/a/b/x.mp4'));
	}

	/** @param list<File|Folder> $children */
	private function mockFolder(string $path, array $children): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getDirectoryListing')->willReturn($children);
		$folder->method('getPath')->willReturn($path);
		return $folder;
	}

	private function mockFile(int $id, string $name, string $path, string $mime, int $size): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($id);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn($path);
		$file->method('getMimeType')->willReturn($mime);
		$file->method('getSize')->willReturn($size);
		return $file;
	}
}
