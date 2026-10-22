<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getType()
 * @method void setType(string $type)
 * @method ?int getFileid()
 * @method void setFileid(?int $fileid)
 * @method ?int getMediaId()
 * @method void setMediaId(?int $mediaId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method ?string getPayload()
 * @method void setPayload(?string $payload)
 * @method string getState()
 * @method void setState(string $state)
 * @method int getPriority()
 * @method void setPriority(int $priority)
 * @method int getProgress()
 * @method void setProgress(int $progress)
 * @method int getAttempts()
 * @method void setAttempts(int $attempts)
 * @method int getMaxAttempts()
 * @method void setMaxAttempts(int $maxAttempts)
 * @method int getNextAttemptAt()
 * @method void setNextAttemptAt(int $nextAttemptAt)
 * @method ?string getError()
 * @method void setError(?string $error)
 * @method ?int getWorkerPid()
 * @method void setWorkerPid(?int $workerPid)
 * @method ?int getHeartbeatAt()
 * @method void setHeartbeatAt(?int $heartbeatAt)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method ?int getStartedAt()
 * @method void setStartedAt(?int $startedAt)
 * @method ?int getFinishedAt()
 * @method void setFinishedAt(?int $finishedAt)
 */
class Job extends Entity {
	protected $type = null;
	protected $fileid = null;
	protected $mediaId = null;
	protected $userId = null;
	protected $payload = null;
	protected $state = null;
	protected $priority = null;
	protected $progress = null;
	protected $attempts = null;
	protected $maxAttempts = null;
	protected $nextAttemptAt = null;
	protected $error = null;
	protected $workerPid = null;
	protected $heartbeatAt = null;
	protected $createdAt = null;
	protected $startedAt = null;
	protected $finishedAt = null;

	public function __construct() {
		$this->addType('fileid', 'integer');
		$this->addType('mediaId', 'integer');
		$this->addType('priority', 'integer');
		$this->addType('progress', 'integer');
		$this->addType('attempts', 'integer');
		$this->addType('maxAttempts', 'integer');
		$this->addType('nextAttemptAt', 'integer');
		$this->addType('workerPid', 'integer');
		$this->addType('heartbeatAt', 'integer');
		$this->addType('createdAt', 'integer');
		$this->addType('startedAt', 'integer');
		$this->addType('finishedAt', 'integer');
	}
}
