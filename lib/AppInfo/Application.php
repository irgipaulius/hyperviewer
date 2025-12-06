<?php

declare(strict_types=1);

namespace OCA\HyperViewer\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Util;
use OCA\HyperViewer\BackgroundJob\AutoHlsGenerationJob;

class Application extends App implements IBootstrap {
	public const APP_ID = 'hyperviewer';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerService('AutoHlsGenerationJob', function() {
			return \OC::$server->get(AutoHlsGenerationJob::class);
		});
		$context->registerService('ProcessQueueJob', function() {
			return \OC::$server->get(\OCA\HyperViewer\BackgroundJob\ProcessQueueJob::class);
		});
		$context->registerCommand(\OCA\HyperViewer\Command\ProcessQueue::class);
	}

	public function boot(IBootContext $context): void {
		// Always inject our Files integration JS
		Util::addScript(self::APP_ID, 'files-integration');
		
		// Register auto-generation cron job
		$jobList = $context->getServerContainer()->get(\OCP\BackgroundJob\IJobList::class);
		if (!$jobList->has(AutoHlsGenerationJob::class, null)) {
			$jobList->add(AutoHlsGenerationJob::class);
		}
		
		// Register process queue job
		if (!$jobList->has(\OCA\HyperViewer\BackgroundJob\ProcessQueueJob::class, null)) {
			$jobList->add(\OCA\HyperViewer\BackgroundJob\ProcessQueueJob::class);
		}
	}
}
