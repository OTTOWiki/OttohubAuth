<?php
/**
 * 登录/认领流程共用的节流计数（docs/ottohub-sso.md §4.6.2、§9.3-10）。
 *
 * 目的有二：防止本站被当成打 OTTOhub 的肉鸡，以及防止本地口令被离线爆破。
 * 计数存在对象缓存里（键含 IP 与账号的哈希），**不含任何凭证明文**。
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth;

use Wikimedia\ObjectCache\BagOStuff;

class OttohubThrottle {

	private BagOStuff $cache;
	private int $limit;
	private int $window;

	public function __construct( BagOStuff $cache, int $limit = 5, int $window = 600 ) {
		$this->cache = $cache;
		$this->limit = max( 1, $limit );
		$this->window = max( 1, $window );
	}

	private function key( string $clientIp, string $account ): string {
		// 账号先小写归一，避免大小写差异绕过计数；再做哈希，键里不留原文
		return $this->cache->makeKey(
			'OttohubAuth-throttle',
			md5( $clientIp . "\n" . strtolower( $account ) )
		);
	}

	/**
	 * 当前是否已被节流。
	 */
	public function isBlocked( string $clientIp, string $account ): bool {
		return (int)$this->cache->get( $this->key( $clientIp, $account ) ) >= $this->limit;
	}

	/**
	 * 记一次失败（或一次会打到上游的尝试）。
	 */
	public function bump( string $clientIp, string $account ): void {
		$key = $this->key( $clientIp, $account );
		$this->cache->incrWithInit( $key, $this->window, 1 );
	}

	/**
	 * 登录成功后清除该 (IP, 账号) 的计数。
	 */
	public function clear( string $clientIp, string $account ): void {
		$this->cache->delete( $this->key( $clientIp, $account ) );
	}

	public function getLimit(): int {
		return $this->limit;
	}
}
