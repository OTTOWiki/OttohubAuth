<?php
/**
 * 官方发件账号：用 `$wgOttohubAuth_SenderAccount` 指向的 600 凭据文件换 token（带缓存），
 * 再以该账号的身份给指定 OTTOhub 用户发站内信。
 *
 * 契约（2026-09-12 用 flan 的发件账号 23406 实测，见 docs/ottohub-sso-phase3-plan.md）：
 *   POST /api/auth/login     {"uid_email","pw"}          → 平坦 {status:"success", uid, token, …}
 *   POST /api/im/messages    {token, receiver, message}  → {status:"success"}
 *   长度上限 **222 个字符**（223 → too_long_message）；receiver 必须是 OTTOhub 侧 uid。
 *   错误码：error_receiver / too_long_message / too_short_message / blocked / missing_argument /
 *           error_token（401）/ system_error（可重试）。
 *
 * 安全约定：
 *  - 凭据文件路径从配置读；**本类绝不记录凭据或 token**，日志只出现 hubUid 与错误码。
 *  - token 缓存在对象缓存（本站 = APCu）里，键是哈希，不落库、不进 URL、不进访问日志。
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth;

use MediaWiki\Config\Config;
use Psr\Log\LoggerInterface;
use Wikimedia\ObjectCache\BagOStuff;

class OttohubSender {

	/** 发信成功 */
	public const OK = 'ok';
	/** 暂时性失败（网络/上游 5xx/限流）→ 值得退避重试 */
	public const RETRY = 'retry';
	/** 永久性失败（收件人不存在、超长、被拉黑…）→ 重试无意义 */
	public const DROP = 'drop';

	/** token 缓存时长（秒）：上游未公布有效期，短一点更安全 */
	private const TOKEN_TTL = 1800;

	/** 上游单条站内信的长度上限（实测：222 通过，223 报 too_long_message） */
	public const MAX_LENGTH = 222;

	/** 重试也没有意义的错误码 */
	private const FATAL_CODES = [
		'error_receiver',
		'too_long_message',
		'too_short_message',
		'blocked',
		'missing_argument',
		'error_password',
	];

	private bool $warnedNoCredential = false;

	public function __construct(
		private readonly OttohubClient $client,
		private readonly BagOStuff $cache,
		private readonly Config $config,
		private readonly LoggerInterface $logger
	) {
	}

	/**
	 * 是否已配置发件账号（只判"文件在不在、字段全不全"，不登录）。
	 */
	public function isConfigured(): bool {
		return $this->credentials() !== null;
	}

	/**
	 * 发一条站内信。
	 *
	 * @param int $hubUid 收件人的 **OTTOhub 侧 uid**
	 * @param string $text 正文（本方法会自动按 222 字符截断）
	 * @return string self::OK / self::RETRY / self::DROP
	 */
	public function sendIm( int $hubUid, string $text ): string {
		if ( $hubUid <= 0 ) {
			return self::DROP;
		}
		$text = self::truncate( $text );
		if ( $text === '' ) {
			return self::DROP;
		}

		$token = $this->token();
		if ( $token === null ) {
			// 没凭据/登录失败：不当作永久失败（可能是上游临时故障），交给作业退避
			return self::RETRY;
		}

		$res = $this->client->sendIm( $token, $hubUid, $text );
		$code = $res->getErrorCode();

		if ( !$res->isOk() && $code === 'error_token' ) {
			// token 过期：丢弃缓存重登一次，仍失败则交给退避逻辑
			$token = $this->token( true );
			if ( $token !== null ) {
				$res = $this->client->sendIm( $token, $hubUid, $text );
				$code = $res->getErrorCode();
			}
		}

		if ( $res->isOk() ) {
			return self::OK;
		}

		$this->logger->warning( 'OttohubAuth: 站内信发送失败', [
			'hubUid' => $hubUid,
			'code' => $code,
			'http' => $res->getHttpStatus(),
		] );

		return in_array( $code, self::FATAL_CODES, true ) ? self::DROP : self::RETRY;
	}

	/**
	 * 只验证凭据是否可用（登录并取一次 token 缓存起来），**不发任何消息**。
	 *
	 * @return bool 凭据可用
	 */
	public function verifyCredentials(): bool {
		return $this->token( true ) !== null;
	}

	/**
	 * 取（必要时换）发件账号 token。
	 */
	private function token( bool $fresh = false ): ?string {
		$key = $this->cache->makeKey( 'OttohubAuth', 'sender-token' );
		if ( !$fresh ) {
			$cached = $this->cache->get( $key );
			if ( is_string( $cached ) && $cached !== '' ) {
				return $cached;
			}
		} else {
			$this->cache->delete( $key );
		}

		$cred = $this->credentials();
		if ( $cred === null ) {
			return null;
		}

		$res = $this->client->login( $cred['uid_email'], $cred['pw'] );
		if ( !$res->isOk() ) {
			$this->logger->warning( 'OttohubAuth: 发件账号登录失败', [
				'code' => $res->getErrorCode(),
				'http' => $res->getHttpStatus(),
			] );
			return null;
		}

		$token = $res->getString( 'token' );
		if ( $token === null || $token === '' ) {
			$this->logger->warning( 'OttohubAuth: 发件账号登录响应缺少 token' );
			return null;
		}

		$this->cache->set( $key, $token, self::TOKEN_TTL );
		return $token;
	}

	/**
	 * 读取凭据文件。格式（JSON）：{"uid_email": "<用户名或邮箱>", "pw": "<口令>"}
	 *
	 * @return array{uid_email:string,pw:string}|null
	 */
	private function credentials(): ?array {
		$path = (string)$this->config->get( 'OttohubAuth_SenderAccount' );
		if ( $path === '' ) {
			$this->warnOnce( '未配置发件账号（$wgOttohubAuth_SenderAccount 为空），阶段 3 的通知不会推送' );
			return null;
		}
		if ( !is_file( $path ) || !is_readable( $path ) ) {
			$this->warnOnce( '发件账号凭据文件不存在或不可读' );
			return null;
		}

		$raw = @file_get_contents( $path );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
		if ( !is_array( $data ) ) {
			$this->warnOnce( '发件账号凭据文件不是合法 JSON' );
			return null;
		}

		$account = $data['uid_email'] ?? null;
		$password = $data['pw'] ?? null;
		if ( !is_string( $account ) || $account === '' || !is_string( $password ) || $password === '' ) {
			$this->warnOnce( '发件账号凭据文件缺少 uid_email 或 pw' );
			return null;
		}

		return [ 'uid_email' => $account, 'pw' => $password ];
	}

	/**
	 * 同一请求内同一原因只告警一次，避免作业重试刷日志。
	 */
	private function warnOnce( string $reason ): void {
		if ( $this->warnedNoCredential ) {
			return;
		}
		$this->warnedNoCredential = true;
		$this->logger->warning( 'OttohubAuth: ' . $reason );
	}

	/**
	 * 按**字符数**（不是字节）截断到上游上限。
	 *
	 * 实测：222 个汉字 / 112 个 emoji 都通过，223 个汉字被拒 → 上限是字符数。
	 */
	public static function truncate( string $text ): string {
		$text = trim( $text );
		if ( mb_strlen( $text ) <= self::MAX_LENGTH ) {
			return $text;
		}
		return mb_substr( $text, 0, self::MAX_LENGTH - 1 ) . '…';
	}
}
