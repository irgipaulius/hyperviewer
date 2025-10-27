<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Tests\Unit\Controller;

use OCA\HyperViewer\AppInfo\Application;
use OCA\HyperViewer\Controller\SettingsController;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Test SettingsController cache location validation
 */
final class SettingsControllerTest extends TestCase {
	
	public function testSetCacheLocationsValidatesPaths(): void {
		// Mock dependencies
		$request = $this->createMock(IRequest::class);
		$config = $this->createMock(IConfig::class);
		
		// Create controller
		$controller = new SettingsController(Application::APP_ID, $request, $config);
		
		// Test data with empty/whitespace paths that should be filtered
		$locations = [
			'./.cached_hls/',
			'',
			'~/.cached_hls/',
			'   ',
			'/mnt/cache/.cached_hls/'
		];
		
		// Expect config to be called with only valid (non-empty) paths
		$config->expects($this->once())
			->method('setUserValue')
			->with(
				$this->anything(),
				Application::APP_ID,
				'cache_locations',
				$this->callback(function($value) {
					$decoded = json_decode($value, true);
					// Should have 3 valid paths (empty/whitespace filtered out)
					return count($decoded) === 3 
						&& in_array('./.cached_hls/', $decoded)
						&& in_array('~/.cached_hls/', $decoded)
						&& in_array('/mnt/cache/.cached_hls/', $decoded);
				})
			);
		
		// Mock the user
		\OC_User::setUserId('testuser');
		
		// Execute
		$response = $controller->setCacheLocations($locations);
		
		// Assert success
		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}
}
