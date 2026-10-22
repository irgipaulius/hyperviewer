<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getMediaId()
 * @method void setMediaId(int $mediaId)
 * @method int|null getFileid()
 * @method void setFileid(?int $fileid)
 * @method int getStorageId()
 * @method void setStorageId(int $storageId)
 * @method string getPathHash()
 * @method void setPathHash(string $pathHash)
 * @method string getPath()
 * @method void setPath(string $path)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method int getMtime()
 * @method void setMtime(int $mtime)
 * @method int getSeenAt()
 * @method void setSeenAt(int $seenAt)
 */
class Location extends Entity {
	protected $mediaId = null;
	protected ?int $fileid = null;
	protected $storageId = null;
	protected $pathHash = null;
	protected $path = null;
	protected $userId = null;
	protected $size = null;
	protected $mtime = null;
	protected $seenAt = null;

	public function __construct() {
		$this->addType('mediaId', 'integer');
		$this->addType('fileid', 'integer');
		$this->addType('storageId', 'integer');
		$this->addType('size', 'integer');
		$this->addType('mtime', 'integer');
		$this->addType('seenAt', 'integer');
	}
}
