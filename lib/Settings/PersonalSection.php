<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class PersonalSection implements IIconSection {
	private IL10N $l;
	private IURLGenerator $urlGenerator;

	public function __construct(IL10N $l, IURLGenerator $urlGenerator) {
		$this->l = $l;
		$this->urlGenerator = $urlGenerator;
	}

	public function getID(): string {
		return 'hyper_viewer';
	}

	public function getName(): string {
		return $this->l->t('Hyper Viewer');
	}

	public function getPriority(): int {
		return 75;
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath('hyper_viewer', 'app.svg');
	}
}
