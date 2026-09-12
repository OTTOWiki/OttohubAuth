<?php
/**
 * 站内信正文的构造（纯函数，便于离线测试）。
 *
 * 形状：`【OTTOWiki】<谁> <做了什么> <可点链接>`
 *  - 整条 **≤ 222 个字符**（上游硬上限）。
 *  - 链接一律用**可读形式**（多字节字符还原，ASCII 仍保持百分号编码）→ 又短又 URL 安全。
 *  - 链接太长放不下时退化为 `Special:Notifications`，再放不下就截断正文。
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth;

use MediaWiki\Config\Config;
use MediaWiki\Title\Title;

class OttohubNotifyMessage {

	/** 上游单条消息上限（字符数），与 OttohubSender::MAX_LENGTH 一致 */
	private const MAX = OttohubSender::MAX_LENGTH;

	/** 链接放在正文后面的分隔符 */
	private const SEP = ' ';

	/**
	 * 事件类型 → i18n 键后缀。键里只允许 [a-z0-9-]，其余字符（如点）换成 `-`。
	 */
	public static function messageKeyFor( string $eventType ): string {
		return 'ottohubauth-notify-' . strtolower( preg_replace( '/[^A-Za-z0-9-]/', '-', $eventType ) );
	}

	/**
	 * 构造正文。
	 *
	 * @param string $eventType Echo 事件类型（如 mention / commentstreams-reply-on-watched-page）
	 * @param string $agentName 触发者站内用户名（可为空）
	 * @param Title|null $title 相关页面
	 */
	public static function build( string $eventType, string $agentName, ?Title $title, Config $config ): string {
		$prefix = wfMessage( 'ottohubauth-notify-prefix' )->text();
		if ( $prefix === '' || $prefix === '-' ) {
			$prefix = '[OTTOWiki] ';
		}

		$pageText = $title instanceof Title ? $title->getPrefixedText() : '';
		$key = self::messageKeyFor( $eventType );
		if ( !wfMessage( $key )->exists() ) {
			$key = 'ottohubauth-notify-generic';
		}
		$head = $prefix . wfMessage( $key, $agentName, $pageText )->text();

		$url = self::urlFor( $title, $config );
		$fallback = self::notificationsUrl( $config );

		if ( mb_strlen( $head ) + mb_strlen( self::SEP . $url ) <= self::MAX ) {
			return $head . self::SEP . $url;
		}
		if ( mb_strlen( $head ) + mb_strlen( self::SEP . $fallback ) <= self::MAX ) {
			return $head . self::SEP . $fallback;
		}

		$room = self::MAX - mb_strlen( self::SEP . $fallback ) - 1;
		return mb_substr( $head, 0, max( 1, $room ) ) . '…' . self::SEP . $fallback;
	}

	/**
	 * 相关页面的可读 URL。
	 *
	 * `Title::getPrefixedURL()` 会把汉字也百分号编码（一个汉字 9 个字符），直接塞进 222 字符的
	 * 限额里太浪费；而完全 `rawurldecode` 又会放出 `?`、`#`、`[`、`]` 这些需要在 URL 里再转义的
	 * 字符。所以这里只还原**多字节（≥0x80）**的字节，ASCII 一律保留编码 —— 既短又安全。
	 */
	public static function urlFor( ?Title $title, Config $config ): string {
		$server = rtrim( (string)$config->get( 'CanonicalServer' ), '/' );
		if ( !$title instanceof Title ) {
			return self::notificationsUrl( $config );
		}

		$path = preg_replace_callback(
			'/%[0-9A-Fa-f]{2}/',
			static function ( array $m ): string {
				$byte = rawurldecode( $m[0] );
				return strlen( $byte ) === 1 && ord( $byte ) >= 0x80 ? $byte : $m[0];
			},
			$title->getPrefixedURL()
		);

		$articlePath = (string)$config->get( 'ArticlePath' );
		if ( $articlePath === '' ) {
			$articlePath = '/index.php?title=$1';
		}
		return $server . str_replace( '$1', (string)$path, $articlePath );
	}

	private static function notificationsUrl( Config $config ): string {
		$server = rtrim( (string)$config->get( 'CanonicalServer' ), '/' );
		$scriptPath = (string)$config->get( 'ScriptPath' );
		return $server . $scriptPath . '/index.php?title=Special:Notifications';
	}
}
