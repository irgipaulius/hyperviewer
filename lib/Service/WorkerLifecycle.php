<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

/** Shared between WorkerCommand and JobProcessorService for graceful SIGTERM. */
final class WorkerLifecycle {
	private static bool $terminating = false;

	public static function requestTermination(): void {
		self::$terminating = true;
	}

	public static function isTerminating(): bool {
		return self::$terminating;
	}

	public static function reset(): void {
		self::$terminating = false;
	}
}
