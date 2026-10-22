<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IUserSession;
use OCP\Settings\ISettings;

class PersonalSettings implements ISettings {
	public function __construct(
		private IUserSession $userSession,
		private string $appName,
	) {
	}

	public function getForm(): TemplateResponse {
		return new TemplateResponse($this->appName, 'personal-settings', [], '');
	}

	public function getSection(): string {
		return 'hyperviewer';
	}

	public function getPriority(): int {
		return 50;
	}
}
