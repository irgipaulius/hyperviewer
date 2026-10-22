<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
	public function getForm(): TemplateResponse {
		Util::addScript('hyperviewer', 'cockpit');
		Util::addStyle('hyperviewer', 'cockpit');
		return new TemplateResponse('hyperviewer', 'admin-settings', [], '');
	}

	public function getSection(): string {
		return 'hyperviewer';
	}

	public function getPriority(): int {
		return 50;
	}
}
