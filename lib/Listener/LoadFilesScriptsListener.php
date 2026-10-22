<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Listener;

use OCA\HyperViewer\AppInfo\Application;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\Util;

/** @implements IEventListener<Event> */
class LoadFilesScriptsListener implements IEventListener {
	public function handle(Event $event): void {
		if (!$event instanceof LoadAdditionalScriptsEvent) {
			return;
		}
		Util::addScript(Application::APP_ID, 'files-integration');
		Util::addStyle(Application::APP_ID, 'files-integration');
	}
}
