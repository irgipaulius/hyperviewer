<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getLevel()
 * @method void setLevel(string $level)
 * @method string getChannel()
 * @method void setChannel(string $channel)
 * @method string getMessage()
 * @method void setMessage(string $message)
 * @method ?string getContext()
 * @method void setContext(?string $context)
 * @method ?string getUserId()
 * @method void setUserId(?string $userId)
 * @method ?int getFileid()
 * @method void setFileid(?int $fileid)
 * @method ?int getJobId()
 * @method void setJobId(?int $jobId)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class LogEntry extends Entity {
	protected $level = null;
	protected $channel = null;
	protected $message = null;
	protected $context = null;
	protected $userId = null;
	protected $fileid = null;
	protected $jobId = null;
	protected $createdAt = 0;

	public function __construct() {
		$this->addType('fileid', 'integer');
		$this->addType('jobId', 'integer');
		$this->addType('createdAt', 'integer');
	}
}
