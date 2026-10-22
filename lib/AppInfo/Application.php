<?php

declare(strict_types=1);

namespace OCA\HyperViewer\AppInfo;

use OCA\HyperViewer\Listener\CspListener;
use OCA\HyperViewer\Listener\FileEventListener;
use OCA\HyperViewer\Listener\LoadFilesScriptsListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
class Application extends App implements IBootstrap {
	public const APP_ID = 'hyperviewer';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(AddContentSecurityPolicyEvent::class, CspListener::class);
		$context->registerEventListener(LoadAdditionalScriptsEvent::class, LoadFilesScriptsListener::class);
		$context->registerEventListener(NodeCreatedEvent::class, FileEventListener::class);
		$context->registerEventListener(NodeWrittenEvent::class, FileEventListener::class);
		$context->registerEventListener(NodeRenamedEvent::class, FileEventListener::class);
		$context->registerEventListener(NodeCopiedEvent::class, FileEventListener::class);
		$context->registerEventListener(NodeDeletedEvent::class, FileEventListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
