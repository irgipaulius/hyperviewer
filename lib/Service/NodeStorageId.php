<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCP\Files\Node;

final class NodeStorageId {
	public static function getNumericStorageId(Node $node): int {
		try {
			$mount = $node->getMountPoint();
			if (method_exists($mount, 'getNumericStorageId')) {
				return (int)$mount->getNumericStorageId();
			}
		} catch (\Throwable) {
		}
		try {
			return (int)$node->getStorage()->getCache()->getNumericStorageId();
		} catch (\Throwable $e) {
			throw new \RuntimeException('Unable to resolve numeric storage id for node', 0, $e);
		}
	}
}
