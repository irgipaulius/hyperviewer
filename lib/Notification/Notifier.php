<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Notification;

use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

class Notifier implements INotifier {
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return 'hyperviewer';
	}

	public function getId(): string {
		return $this->getID();
	}

	public function getName(): string {
		return $this->l10nFactory->get('hyperviewer')->t('Hyper Viewer');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'hyperviewer') {
			throw new \InvalidArgumentException('Unexpected app');
		}
		$l = $this->l10nFactory->get('hyperviewer', $languageCode);
		$subject = $notification->getSubject();
		switch ($subject) {
			case 'encode_ready':
				$notification->setParsedSubject($l->t('HLS cache ready'));
				break;
			case 'encode_failed':
				$notification->setParsedSubject($l->t('HLS encoding failed'));
				break;
			case 'clip_ready':
				$notification->setParsedSubject($l->t('Clip export finished'));
				break;
			case 'clip_failed':
				$notification->setParsedSubject($l->t('Clip export failed'));
				break;
			default:
				$notification->setParsedSubject($l->t('Hyper Viewer job update'));
		}

		$notification->setParsedMessage((string)$notification->getMessage());
		$notification->setLink($this->urlGenerator->linkToRouteAbsolute('settings.PersonalSettings.index', ['section' => 'hyperviewer']));
		$notification->setIcon($this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath('hyperviewer', 'app.svg')));

		return $notification;
	}
}
