<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

// nextcloud/ocp ships no autoloader; map OCP\ to its stubs for mocks.
spl_autoload_register(static function (string $class): void {
	if (str_starts_with($class, 'OCP\\')) {
		$file = __DIR__ . '/../../vendor/nextcloud/ocp/' . str_replace('\\', '/', $class) . '.php';
		if (is_file($file)) {
			require_once $file;
		}
	}
});
