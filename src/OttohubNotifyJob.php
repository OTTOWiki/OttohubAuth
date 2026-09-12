<?php
/**
 * 阶段 3：把一条站内信真正发出去（异步作业）。
 *
 * 为什么走作业：发信要出网，最坏会等满 `$wgOttohubAuth_Timeout`；放在触发动作（编辑/评论）
 * 的请求里会拖慢用户的保存操作。
 *
 * 重试策略（自己管，不依赖核心的无限重试）：
 *  - 可重试失败（网络/5xx/限流）→ 重新入队一条带 `jobReleaseTimestamp` 的同名作业，退避 30s → 60s → 120s；
 *  - 第 MAX_ATTEMPTS 次仍失败 → 记日志后**丢弃**，绝不无限重试；
 *  - 永久性失败（收件人不存在/超长/被拉黑）→ 直接丢弃。
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth;

use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\JobQueue\Job;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

class OttohubNotifyJob extends Job implements GenericParameterJob {

	/** 含首次在内最多尝试几次 */
	private const MAX_ATTEMPTS = 3;

	/** 首次退避秒数（之后每次翻倍） */
	private const BASE_DELAY = 30;

	/** 退避上限 */
	private const MAX_DELAY = 900;

	public function __construct( array $params ) {
		parent::__construct( OttohubNotifier::JOB_TYPE, $params );
	}

	/** 重试由本类自己安排（见类注释），所以不让核心把失败的作业无限重投 */
	public function allowRetries() {
		return false;
	}

	/** @inheritDoc */
	public function run() {
		$hubUid = (int)( $this->params['hubUid'] ?? 0 );
		$text = (string)( $this->params['text'] ?? '' );
		$eventType = (string)( $this->params['eventType'] ?? '' );
		$attempt = max( 1, (int)( $this->params['attempt'] ?? 1 ) );
		$logger = LoggerFactory::getInstance( 'OttohubAuth' );

		if ( $hubUid <= 0 || $text === '' ) {
			$this->setLastError( 'missing hubUid/text' );
			return true;
		}

		/** @var OttohubSender $sender */
		$sender = MediaWikiServices::getInstance()->getService( 'OttohubAuth.Sender' );
		$outcome = $sender->sendIm( $hubUid, $text );

		if ( $outcome === OttohubSender::OK ) {
			return true;
		}

		if ( $outcome === OttohubSender::DROP ) {
			$this->setLastError( 'dropped' );
			$logger->warning( 'OttohubAuth: 站内信永久失败，放弃投递', [
				'hubUid' => $hubUid,
				'eventType' => $eventType,
			] );
			return true;
		}

		if ( $attempt >= self::MAX_ATTEMPTS ) {
			$this->setLastError( 'max attempts reached' );
			$logger->warning( 'OttohubAuth: 站内信重试 {attempt} 次仍失败，放弃投递', [
				'hubUid' => $hubUid,
				'eventType' => $eventType,
				'attempt' => $attempt,
			] );
			return true;
		}

		$delay = min( self::BASE_DELAY * ( 2 ** ( $attempt - 1 ) ), self::MAX_DELAY );
		MediaWikiServices::getInstance()->getJobQueueGroup()->push( new JobSpecification(
			OttohubNotifier::JOB_TYPE,
			[
				'hubUid' => $hubUid,
				'text' => $text,
				'eventType' => $eventType,
				'attempt' => $attempt + 1,
			],
			[ 'jobReleaseTimestamp' => time() + $delay ]
		) );

		return true;
	}
}
