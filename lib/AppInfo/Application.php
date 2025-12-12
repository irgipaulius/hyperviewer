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
use OCA\HyperViewer\Command\RunJobCommand;

class Application extends App implements IBootstrap {
	public const APP_ID = 'hyperviewer';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerService('AutoHlsGenerationJob', function () {
			return \OC::$server->get(AutoHlsGenerationJob::class);
		});

		$context->registerService('ProcessQueueJob', function () {
			return \OC::$server->get(ProcessQueueJob::class);
		});

		$context->registerService('RunJobCommand', function () {
			return \OC::$server->get(RunJobCommand::class);
		});

		// registerCommand is available on newer NC; guard for older API versions
		if (method_exists($context, 'registerCommand')) {
			$context->registerCommand(RunJobCommand::class);
		}
	}

	public function boot(IBootContext $context): void {
		// JS integration
		Util::addScript(self::APP_ID, 'files-integration');

		/** @var IJobList $jobList */
		$jobList = $context->getServerContainer()->get(IJobList::class);

		// Register auto-generation cron job once
		if (!$jobList->has(AutoHlsGenerationJob::class, null)) {
			$jobList->add(AutoHlsGenerationJob::class, null);
		}

		// Register FFmpeg queue processor once
		if (!$jobList->has(ProcessQueueJob::class, null)) {
			$jobList->add(ProcessQueueJob::class, null);
		}
	}
}
