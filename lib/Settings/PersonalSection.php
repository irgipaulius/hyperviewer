<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class PersonalSection implements IIconSection {
	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $url,
	) {
	}

	public function getID(): string {
		return 'hyperviewer';
	}

	public function getName(): string {
		return $this->l10n->t('Hyper Viewer');
	}

	public function getPriority(): int {
		return 80;
	}

	public function getIcon(): string {
		return $this->url->imagePath('hyperviewer', 'app.svg');
	}
}
