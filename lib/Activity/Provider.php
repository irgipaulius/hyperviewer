<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Activity;

use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\L10N\IFactory;

class Provider implements IProvider {
	public function __construct(
		private IFactory $l10nFactory,
	) {
	}

	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
		if ($event->getApp() !== 'hyperviewer') {
			return $event;
		}
		$l = $this->l10nFactory->get('hyperviewer', $language);
		switch ($event->getSubject()) {
			case 'clip_export':
				$event->setParsedSubject($l->t('Exported a video clip'));
				break;
			case 'hls_ready':
				$event->setParsedSubject($l->t('HLS cache finished encoding'));
				break;
		}
		$event->setIcon('');
		return $event;
	}
}
