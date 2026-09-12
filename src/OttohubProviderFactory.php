<?php
/**
 * 认证 provider 的取用入口。
 *
 * 为什么需要它：AuthManagerAutoConfig 里注册的 provider **不是**服务容器里的服务，
 * 直接写 "services": [ "OttohubPrimaryAuthenticationProvider" ] 会抛
 * NoSuchServiceException（2026-09-12 实测：两个特殊页 500 就是这个原因）。
 * 正确做法是经 AuthManager::getAuthenticationProvider() 取同一个实例。
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth;

use MediaWiki\Auth\AuthManager;

class OttohubProviderFactory {

	private AuthManager $authManager;

	public function __construct( AuthManager $authManager ) {
		$this->authManager = $authManager;
	}

	public function get(): ?OttohubPrimaryAuthenticationProvider {
		$provider = $this->authManager->getAuthenticationProvider(
			OttohubPrimaryAuthenticationProvider::class
		);
		return $provider instanceof OttohubPrimaryAuthenticationProvider ? $provider : null;
	}
}
