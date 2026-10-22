<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getFingerprint()
 * @method void setFingerprint(string $fingerprint)
 * @method int getSize()
 * @method void setSize(int $size)
 * @method ?float getDuration()
 * @method void setDuration(?float $duration)
 * @method ?float getFps()
 * @method void setFps(?float $fps)
 * @method ?int getWidth()
 * @method void setWidth(?int $width)
 * @method ?int getHeight()
 * @method void setHeight(?int $height)
 * @method bool getHasAudio()
 * @method void setHasAudio(bool $hasAudio)
 * @method string getCacheState()
 * @method void setCacheState(string $cacheState)
 * @method ?string getRenditions()
 * @method void setRenditions(?string $renditions)
 * @method int getCacheBytes()
 * @method void setCacheBytes(int $cacheBytes)
 * @method bool getPinned()
 * @method void setPinned(bool $pinned)
 * @method bool getAutoDisabled()
 * @method void setAutoDisabled(bool $autoDisabled)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getLastAccessAt()
 * @method void setLastAccessAt(int $lastAccessAt)
 */
class Media extends Entity {
	protected $fingerprint = '';
	protected $size = 0;
	protected $duration = null;
	protected $fps = null;
	protected $width = null;
	protected $height = null;
	protected $hasAudio = false;
	protected $cacheState = 'none';
	protected $renditions = null;
	protected $cacheBytes = 0;
	protected $pinned = false;
	protected $autoDisabled = false;
	protected $createdAt = 0;
	protected $lastAccessAt = 0;

	public function __construct() {
		$this->addType('size', 'integer');
		$this->addType('duration', 'float');
		$this->addType('fps', 'float');
		$this->addType('width', 'integer');
		$this->addType('height', 'integer');
		$this->addType('hasAudio', 'boolean');
		$this->addType('cacheBytes', 'integer');
		$this->addType('pinned', 'boolean');
		$this->addType('autoDisabled', 'boolean');
		$this->addType('createdAt', 'integer');
		$this->addType('lastAccessAt', 'integer');
	}
}
