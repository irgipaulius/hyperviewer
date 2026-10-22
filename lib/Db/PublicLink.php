<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getFileid()
 * @method void setFileid(int $fileid)
 * @method int getMediaId()
 * @method void setMediaId(int $mediaId)
 * @method string getToken()
 * @method void setToken(string $token)
 * @method ?int getExpiresAt()
 * @method void setExpiresAt(?int $expiresAt)
 * @method int getViewCount()
 * @method void setViewCount(int $viewCount)
 * @method ?int getRevokedAt()
 * @method void setRevokedAt(?int $revokedAt)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class PublicLink extends Entity {
	protected $userId = null;
	protected $fileid = null;
	protected $mediaId = null;
	protected $token = null;
	protected $expiresAt = null;
	protected $viewCount = null;
	protected $revokedAt = null;
	protected $createdAt = null;

	public function __construct() {
		$this->addType('fileid', 'integer');
		$this->addType('mediaId', 'integer');
		$this->addType('expiresAt', 'integer');
		$this->addType('viewCount', 'integer');
		$this->addType('revokedAt', 'integer');
		$this->addType('createdAt', 'integer');
	}
}
