<?php
/**
 * 阶段 3：Echo → OTTOhub 站内信通道。
 *
 * 挂载方式（extension.json，**不写进 LocalSettings**）：
 *   $wgEchoNotifiers['ottohub']                     = [ OttohubNotifier::class, 'notify' ]
 *   $wgEchoDefaultNotifyTypeAvailability['ottohub'] = false          ← 默认对所有类别关闭
 *   $wgEchoNotifyTypeAvailabilityByCategory[<类别>]['ottohub'] = true ← 只对白名单类别开放
 *
 * 因此**只有白名单类别**才会在 Special:Preferences 里出现「OTTOhub 站内信」这一列，
 * 且每个用户的该列**默认全部不勾选** —— 不主动勾选就永远不会收到推送。
 *
 * 本类只做"判断 + 入队"，真正的 HTTP 请求在 OttohubNotifyJob 里（异步 + 退避）。
 *
 * ⚠️ receiver 必须是 **OTTOhub 侧 uid**，且**只能**来自
 * OttohubAccountStore::getHubUidByLocalUser() —— 传错等于把通知发给陌生人。
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth;

use MediaWiki\Config\Config;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\User\User;
use Throwable;

class OttohubNotifier {

	/** Echo 的 notifier 名（同时也是用户偏好键的中缀：echo-subscriptions-ottohub-<类别>） */
	public const NAME = 'ottohub';

	/** 作业类型 */
	public const JOB_TYPE = 'OttohubNotify';

	/**
	 * Echo 的 notifier 入口。签名由上游固定为 ( $user, $event )。
	 *
	 * 任何异常都必须被吞掉：通知发不出去**绝不能**影响用户的编辑/评论等触发动作。
	 *
	 * @param User $user 收件人（站内用户）
	 * @param Event $event
	 */
	public static function notify( $user, $event ): void {
		try {
			self::dispatch( $user, $event );
		} catch ( Throwable $e ) {
			// 只记异常类型与位置，不记正文（正文含页面标题，没必要进日志）
			LoggerFactory::getInstance( 'OttohubAuth' )->warning(
				'OttohubAuth: 站内信通知入队失败: {class} {msg}',
				[ 'class' => get_class( $e ), 'msg' => $e->getMessage() ]
			);
		}
	}

	private static function dispatch( $user, $event ): void {
		if ( !$user instanceof User || !$user->isRegistered() || !$event instanceof Event ) {
			return;
		}

		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();

		if ( !$config->get( 'OttohubAuth_NotifyEnable' ) ) {
			return;
		}

		$eventType = $event->getType();

		// 事件级白名单（留空 = 不额外过滤，完全交给 Echo 的类别可用性）
		$events = (array)$config->get( 'OttohubAuth_NotifyEvents' );
		if ( $events !== [] && !in_array( $eventType, $events, true ) ) {
			return;
		}

		// 与内置 web 通道同款判断：用户在 Echo 偏好里真的勾了这个类别
		// （此判断同时保证该类别对 ottohub 是"可用"的）
		$attributeManager = $services->getService( 'EchoAttributeManager' );
		if ( !in_array( $eventType, $attributeManager->getUserEnabledEvents( $user, self::NAME ), true ) ) {
			return;
		}

		// 自己触发的事件不推给自己（Echo 侧一般已过滤，这里再兜一层）
		$agent = $event->getAgent();
		if ( $agent !== null && $agent->getId() === $user->getId() ) {
			return;
		}

		// ★ 收件人 hub uid 的唯一来源
		$hubUid = $services->getService( 'OttohubAuth.AccountStore' )
			->getHubUidByLocalUser( $user->getId() );
		if ( $hubUid === null ) {
			return;
		}

		if ( !self::rateLimitAllows( $hubUid, $config ) ) {
			LoggerFactory::getInstance( 'OttohubAuth' )->info(
				'OttohubAuth: 收件人 {hubUid} 已达站内信推送上限，本条跳过',
				[ 'hubUid' => $hubUid ]
			);
			return;
		}

		$agentName = $agent !== null ? $agent->getName() : '';
		$title = $event->getTitle();
		$text = OttohubNotifyMessage::build( $eventType, $agentName, $title, $config );

		$services->getJobQueueGroup()->push( new JobSpecification(
			self::JOB_TYPE,
			[
				'hubUid' => $hubUid,
				'text' => $text,
				'eventType' => $eventType,
				'attempt' => 1,
			]
		) );
	}

	/**
	 * 每个收件人每窗口最多推几条（防止一次批量操作把别人的 OTTOhub 私信刷爆）。
	 * count <= 0 表示不限。
	 */
	private static function rateLimitAllows( int $hubUid, Config $config ): bool {
		$settings = (array)$config->get( 'OttohubAuth_NotifyRateLimit' );
		$limit = (int)( $settings['count'] ?? 0 );
		if ( $limit <= 0 ) {
			return true;
		}
		$window = max( 60, (int)( $settings['window'] ?? 3600 ) );

		$cache = MediaWikiServices::getInstance()->getObjectCacheFactory()->getLocalClusterInstance();
		$key = $cache->makeKey( 'OttohubAuth', 'notify-rate', (string)$hubUid );
		$count = $cache->incrWithInit( $key, $window );

		return $count <= $limit;
	}
}
