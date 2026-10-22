<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

use OCP\IGroupManager;
use OCP\IUser;

class PermissionsService {
	public function __construct(
		private ConfigService $config,
		private IGroupManager $groupManager,
	) {
	}

	public function canUseApp(?IUser $user): bool {
		return $this->userInGroups($user, ConfigService::KEY_ALLOWED_GROUPS);
	}

	public function canGenerateHls(?IUser $user): bool {
		return $this->canUseApp($user)
			&& $this->userInGroups($user, ConfigService::KEY_GROUP_GENERATE_HLS);
	}

	public function canExportClip(?IUser $user): bool {
		return $this->canUseApp($user)
			&& $this->userInGroups($user, ConfigService::KEY_GROUP_EXPORT_CLIP);
	}

	public function canCreatePublicLink(?IUser $user): bool {
		return $this->canUseApp($user)
			&& $this->userInGroups($user, ConfigService::KEY_GROUP_PUBLIC_LINKS);
	}

	public function assertCanUseApp(?IUser $user): void {
		if (!$this->canUseApp($user)) {
			throw new \RuntimeException('Hyper Viewer is not enabled for your account');
		}
	}

	public function assertCanGenerateHls(?IUser $user): void {
		$this->assertCanUseApp($user);
		if (!$this->canGenerateHls($user)) {
			throw new \RuntimeException('HLS generation is not permitted for your account');
		}
	}

	public function assertCanExportClip(?IUser $user): void {
		$this->assertCanUseApp($user);
		if (!$this->canExportClip($user)) {
			throw new \RuntimeException('Clip export is not permitted for your account');
		}
	}

	public function assertCanCreatePublicLink(?IUser $user): void {
		$this->assertCanUseApp($user);
		if (!$this->canCreatePublicLink($user)) {
			throw new \RuntimeException('Public stream links are not permitted for your account');
		}
	}

	private function userInGroups(?IUser $user, string $configKey): bool {
		if ($user === null) {
			return false;
		}
		$raw = trim($this->config->getString($configKey));
		if ($raw === '') {
			return true;
		}
		$groups = array_values(array_filter(array_map('trim', explode(',', $raw))));
		if ($groups === []) {
			return true;
		}
		foreach ($groups as $gid) {
			if ($this->groupManager->isInGroup($user->getUID(), $gid)) {
				return true;
			}
		}
		return false;
	}
}
