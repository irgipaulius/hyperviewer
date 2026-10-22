<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Listener;

use OCA\HyperViewer\AppInfo\Application;
use OCA\HyperViewer\Service\PermissionsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\IUserSession;
use OCP\Util;

/** @implements IEventListener<Event> */
class LoadFilesScriptsListener implements IEventListener {
	public function __construct(
		private IUserSession $userSession,
		private PermissionsService $permissions,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalScriptsEvent) {
			return;
		}
		if (!$this->permissions->canUseApp($this->userSession->getUser())) {
			return;
		}
		Util::addScript(Application::APP_ID, 'files-integration');
		Util::addStyle(Application::APP_ID, 'files-integration');
	}
}
