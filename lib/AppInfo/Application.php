<?php

declare(strict_types=1);

namespace OCA\HyperViewer\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
use OCP\Util;
use OCA\HyperViewer\BackgroundJob\AutoHlsGenerationJob;
use OCA\HyperViewer\BackgroundJob\ProcessQueueJob;

class Application extends App implements IBootstrap {
	public const APP_ID = 'hyperviewer';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// Optional: if you need explicit service IDs, otherwise NC can autowire by class
		
		$context->registerService('AutoHlsGenerationJob', function() {
			return \OC::$server->get(AutoHlsGenerationJob::class);
		});
		$context->registerService('ProcessQueueJob', function() {
			return \OC::$server->get(ProcessQueueJob::class);
		});
	}

	public function boot(IBootContext $context): void {
		// Inject your JS
		Util::addScript(self::APP_ID, 'files-integration');

		/** @var IJobList $jobList */
		$jobList = $context->getServerContainer()->get(IJobList::class);

		// Register auto-generation cron job (once)
		if (!$jobList->has(AutoHlsGenerationJob::class)) {
			$jobList->add(AutoHlsGenerationJob::class);
		}

		// Register process queue job (once)
		if (!$jobList->has(ProcessQueueJob::class)) {
			$jobList->add(ProcessQueueJob::class);
		}
	}
}
