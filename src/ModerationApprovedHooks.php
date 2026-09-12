<?php
/**
 * 阶段 3 附加项：「审核结果」通知作者。
 *
 * 背景：Moderation 扩展自己**没有**任何 Echo 事件（它只有"有新待审编辑就邮件通知管理员"）。
 * 所以这里由我们自己造一个 Echo 事件 —— 但**不需要给 Moderation 打本地补丁**：
 *
 *  - Moderation 在批准一条待审编辑时，会先 `installApproveHook()` 把任务登记到
 *    `Moderation.ApproveHook` 服务（`ModerationApproveHook::isApprovingNow()` 因此为真），
 *    然后**以原作者的身份** `PageUpdater::saveRevision()`（见 ApproveEditConsequence::run）。
 *  - 于是核心的 `PageSaveComplete` 必然被触发，且此时 `isApprovingNow()` 为真 →
 *    这就是"这次保存 = 一次审核通过"的可靠信号（该 API 也是 Moderation 给 CanSkip 用的公开方法）。
 *
 * 通知对象是**原作者**（`$revisionRecord->getUser()`）；agent 取当前请求的审核者（网页端），
 * CLI 批准时取不到就留空 —— 反正正文不写审核者名字，不影响投递。
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\Notifications\Model\Event;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Throwable;

class ModerationApprovedHooks {

	/** Echo 事件类型（在 extension.json 里注册） */
	public const EVENT_TYPE = 'ottohubauth-moderation-approved';

	/** 审核者服务的名字（Moderation 提供；未装 Moderation 时取不到） */
	private const APPROVE_HOOK_SERVICE = 'Moderation.ApproveHook';

	/**
	 * @param mixed $wikiPage
	 * @param mixed $user
	 * @param mixed $summary
	 * @param mixed $flags
	 * @param mixed $revisionRecord
	 * @param mixed $editResult
	 */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ): void {
		try {
			$this->maybeCreateApprovedEvent( $wikiPage, $revisionRecord );
		} catch ( Throwable $e ) {
			// 通知造不出来绝不能让这次编辑失败
			LoggerFactory::getInstance( 'OttohubAuth' )->warning(
				'OttohubAuth: 审核结果通知创建失败: {class} {msg}',
				[ 'class' => get_class( $e ), 'msg' => $e->getMessage() ]
			);
		}
	}

	private function maybeCreateApprovedEvent( $wikiPage, $revisionRecord ): void {
		// Echo 不在就什么都不做（事件没人消费）
		if ( !class_exists( Event::class ) ) {
			return;
		}
		if ( !is_object( $revisionRecord ) || !method_exists( $revisionRecord, 'getUser' ) ) {
			return;
		}
		if ( !is_object( $wikiPage ) || !method_exists( $wikiPage, 'getTitle' ) ) {
			return;
		}

		$approveHook = self::approveHook();
		if ( $approveHook === null || !$approveHook->isApprovingNow() ) {
			return;
		}

		$title = $wikiPage->getTitle();
		if ( $title === null ) {
			return;
		}

		$author = $revisionRecord->getUser();
		if ( $author === null || !$author->isRegistered() ) {
			return;
		}

		$info = [
			'type' => self::EVENT_TYPE,
			'title' => $title,
			'extra' => [
				// Event::RECIPIENTS_IDX：Echo 内置的"直接指定收件人"定位器
				'recipients' => [ $author->getId() ],
				'revid' => (int)$revisionRecord->getId(),
			],
		];

		$moderator = self::currentModerator( $author->getId() );
		if ( $moderator !== null ) {
			$info['agent'] = $moderator;
		}

		Event::create( $info );
	}

	/**
	 * 当前请求的审核者（网页端就是点"批准"的那个人）；CLI 或同一个人时返回 null。
	 */
	private static function currentModerator( int $authorId ): ?object {
		$user = RequestContext::getMain()->getUser();
		if ( $user->isRegistered() && $user->getId() !== $authorId ) {
			return $user;
		}
		return null;
	}

	/**
	 * 取 Moderation 的 ApproveHook 服务；Moderation 未安装时返回 null。
	 */
	private static function approveHook(): ?object {
		try {
			$hook = MediaWikiServices::getInstance()->getService( self::APPROVE_HOOK_SERVICE );
		} catch ( Throwable $e ) {
			return null;
		}
		if ( !is_object( $hook ) || !method_exists( $hook, 'isApprovingNow' ) ) {
			return null;
		}
		return $hook;
	}
}
