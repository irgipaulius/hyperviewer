<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Listener;

use OCP\AppFramework\Http\EmptyContentSecurityPolicy;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;

/** @implements IEventListener<Event> */
class CspListener implements IEventListener {
	public function handle(Event $event): void {
		if (!$event instanceof AddContentSecurityPolicyEvent) {
			return;
		}

		$policy = new EmptyContentSecurityPolicy();
		$policy->addAllowedMediaDomain("'self'");
		$policy->addAllowedMediaDomain('blob:');
		$policy->addAllowedScriptDomain("'self'");
		$policy->addAllowedScriptDomain('blob:');
		$policy->addAllowedWorkerSrcDomain("'self'");
		$policy->addAllowedWorkerSrcDomain('blob:');
		if (method_exists($policy, 'addAllowedChildSrcDomain')) {
			$policy->addAllowedChildSrcDomain("'self'");
			$policy->addAllowedChildSrcDomain('blob:');
		}
		$event->addPolicy($policy);
	}
}
