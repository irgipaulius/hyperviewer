<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getPath()
 * @method void setPath(string $path)
 * @method ?int getStorageId()
 * @method void setStorageId(?int $storageId)
 * @method ?string getResolutions()
 * @method void setResolutions(?string $resolutions)
 * @method bool getEnabled()
 * @method void setEnabled(bool $enabled)
 * @method int getPriority()
 * @method void setPriority(int $priority)
 * @method ?int getLastScanAt()
 * @method void setLastScanAt(?int $lastScanAt)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class WatchDir extends Entity {
	protected $userId = '';
	protected $path = '';
	protected $storageId = null;
	protected $resolutions = null;
	protected $enabled = true;
	protected $priority = 100;
	protected $lastScanAt = null;
	protected $createdAt = 0;

	public function __construct() {
		$this->addType('storageId', 'integer');
		$this->addType('enabled', 'boolean');
		$this->addType('priority', 'integer');
		$this->addType('lastScanAt', 'integer');
		$this->addType('createdAt', 'integer');
	}
}
