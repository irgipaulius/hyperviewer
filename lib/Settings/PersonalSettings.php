<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

class PersonalSettings implements ISettings {
	public function getForm(): TemplateResponse {
		Util::addScript('hyperviewer', 'personal');
		Util::addStyle('hyperviewer', 'personal');
		return new TemplateResponse('hyperviewer', 'personal-settings', [], '');
	}

	public function getSection(): string {
		return 'hyperviewer';
	}

	public function getPriority(): int {
		return 50;
	}
}
