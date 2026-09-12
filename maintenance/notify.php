<?php
/**
 * 阶段 3（通知推送）的运维/验收工具。
 *
 * 用法（服务器上，站点根目录）：
 *   php maintenance/run.php OttohubAuth:notify                 # 默认 = --show
 *   php maintenance/run.php OttohubAuth:notify --show          # 配置与现状总览
 *   php maintenance/run.php OttohubAuth:notify --verify        # 只验证发件账号凭据（不发消息）
 *   php maintenance/run.php OttohubAuth:notify --user=ExampleUser   # 看某人的绑定与已勾选类别
 *   php maintenance/run.php OttohubAuth:notify --send=<hubUid> --text='...'  # 立即发一条（绕过队列）
 *   php maintenance/run.php OttohubAuth:notify --queue --max=10 # 立刻把待发作业跑掉
 *
 * 安全：本脚本不回显凭据与 token；`--send` 会真的给那个 OTTOhub 用户发私信，只在受控测试时用。
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth\Maintenance;

use MediaWiki\Extension\OttohubAuth\OttohubAccountStore;
use MediaWiki\Extension\OttohubAuth\OttohubNotifier;
use MediaWiki\Extension\OttohubAuth\OttohubSender;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\User\UserFactory;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

class Notify extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Inspect / test the Echo -> OTTOhub direct-message channel (stage 3)' );
		$this->addOption( 'show', 'Print effective configuration and current state (default)' );
		$this->addOption( 'verify', 'Log in as the sender account to verify credentials (sends nothing)' );
		$this->addOption( 'user', 'Show link + enabled ottohub categories for a local username', false, true );
		$this->addOption( 'send', 'Send one message right now to this OTTOhub uid', false, true );
		$this->addOption( 'text', 'Message body for --send', false, true );
		$this->addOption( 'queue', 'Run pending OttohubNotify jobs now' );
		$this->addOption( 'max', 'Max jobs to run with --queue (default 10)', false, true );
		$this->requireExtension( 'OttohubAuth' );
	}

	public function execute() {
		$services = $this->getServiceContainer();
		$config = $services->getMainConfig();
		$store = $services->getService( 'OttohubAuth.AccountStore' );
		/** @var OttohubSender $sender */
		$sender = $services->getService( 'OttohubAuth.Sender' );

		if ( $this->hasOption( 'verify' ) ) {
			$this->verify( $sender );
			return;
		}
		if ( $this->hasOption( 'send' ) ) {
			$this->send( $sender );
			return;
		}
		if ( $this->hasOption( 'queue' ) ) {
			$this->runQueue();
			return;
		}
		if ( $this->hasOption( 'user' ) ) {
			$this->showUser( $store );
			return;
		}
		$this->show( $config, $store, $sender );
	}

	private function show( $config, OttohubAccountStore $store, OttohubSender $sender ): void {
		$enabled = (bool)$config->get( 'OttohubAuth_NotifyEnable' );
		$this->output( "阶段 3 通知推送\n" );
		$this->output( "  总开关 (OttohubAuth_NotifyEnable) : " . ( $enabled ? '开' : '关' ) . "\n" );
		$this->output( '  发件账号凭据文件 : '
			. ( (string)$config->get( 'OttohubAuth_SenderAccount' ) !== '' ? '已配置' : '**未配置**' ) . "\n" );
		$this->output( '  发件账号可用     : ' . ( $sender->isConfigured() ? '是（未登录验证，用 --verify）' : '否' ) . "\n" );

		$events = (array)$config->get( 'OttohubAuth_NotifyEvents' );
		$this->output( '  事件白名单       : ' . ( $events === [] ? '（空 = 由类别可用性决定）' : implode( ', ', $events ) ) . "\n" );

		$limit = (array)$config->get( 'OttohubAuth_NotifyRateLimit' );
		$this->output( '  每人限流         : ' . (int)( $limit['count'] ?? 0 ) . ' 条 / '
			. (int)( $limit['window'] ?? 0 ) . " 秒\n" );

		$notifiers = (array)$config->get( 'EchoNotifiers' );
		$this->output( '  Echo 通道已挂入  : ' . ( isset( $notifiers['ottohub'] ) ? '是' : '**否**' ) . "\n" );

		$byCategory = (array)$config->get( 'NotifyTypeAvailabilityByCategory' );
		$cats = [];
		foreach ( $byCategory as $category => $map ) {
			if ( !empty( $map['ottohub'] ) ) {
				$cats[] = $category;
			}
		}
		$this->output( "  可推送的 Echo 类别: " . ( $cats === [] ? '（无）' : implode( ', ', $cats ) ) . "\n" );

		$dbr = $this->getServiceContainer()->getConnectionProvider()->getReplicaDatabase();
		if ( $dbr->tableExists( OttohubAccountStore::TABLE ) ) {
			$rows = $dbr->newSelectQueryBuilder()
				->select( [ 'oa_hub_uid', 'oa_user_id' ] )
				->from( OttohubAccountStore::TABLE )
				->caller( __METHOD__ )
				->fetchResultSet();
			$bound = 0;
			$optedIn = 0;
			$lookup = $this->getServiceContainer()->getUserOptionsLookup();
			$userFactory = $this->getServiceContainer()->getUserFactory();
			foreach ( $rows as $row ) {
				$bound++;
				$user = $userFactory->newFromId( (int)$row->oa_user_id );
				if ( !$user->isRegistered() ) {
					continue;
				}
				foreach ( $cats as $category ) {
					if ( $lookup->getOption( $user, 'echo-subscriptions-ottohub-' . $category ) ) {
						$optedIn++;
						break;
					}
				}
			}
			$this->output( "  已绑定 OTTOhub 的用户: {$bound}（其中已勾选任一 OTTOhub 类别的：{$optedIn}）\n" );
		}

		$pending = $this->getServiceContainer()->getJobQueueGroup()
			->get( OttohubNotifier::JOB_TYPE )->getSize();
		$this->output( "  待发作业数       : $pending\n" );
	}

	private function verify( OttohubSender $sender ): void {
		if ( !$sender->isConfigured() ) {
			$this->error( '发件账号未配置（\$wgOttohubAuth_SenderAccount）', 1 );
		}
		$ok = $sender->verifyCredentials();
		$this->output( $ok ? "发件账号凭据可用（已换到 token 并缓存）\n" : "发件账号凭据**不可用**（见日志）\n" );
		if ( !$ok ) {
			exit( 1 );
		}
	}

	private function send( OttohubSender $sender ): void {
		$hubUid = (int)$this->getOption( 'send' );
		$text = (string)$this->getOption( 'text' );
		if ( $hubUid <= 0 || $text === '' ) {
			$this->error( '--send=<hubUid> 与 --text=<正文> 都必须给', 1 );
		}
		$outcome = $sender->sendIm( $hubUid, $text );
		$this->output( "发送结果: {$outcome}（ok/retry/drop）\n" );
		if ( $outcome !== OttohubSender::OK ) {
			exit( 1 );
		}
	}

	private function runQueue(): void {
		$max = max( 1, (int)( $this->getOption( 'max' ) ?? 10 ) );
		$group = $this->getServiceContainer()->getJobQueueGroup();
		$queue = $group->get( OttohubNotifier::JOB_TYPE );
		$size = $queue->getSize();
		$this->output( "队列中 OttohubNotify 作业: {$size}（本次最多跑 {$max}）\n" );
		$runner = $this->getServiceContainer()->getJobRunner();
		$result = $runner->run( [
			'type' => OttohubNotifier::JOB_TYPE,
			'maxJobs' => $max,
			'maxTime' => 60,
		] );
		$this->output( '执行结果: ' . json_encode( $result, JSON_UNESCAPED_UNICODE ) . "\n" );
	}

	private function showUser( OttohubAccountStore $store ): void {
		$name = (string)$this->getOption( 'user' );
		/** @var UserFactory $userFactory */
		$userFactory = $this->getServiceContainer()->getUserFactory();
		$user = $userFactory->newFromName( $name );
		if ( $user === null || !$user->isRegistered() ) {
			$this->error( "找不到用户: $name", 1 );
		}

		$hubUid = $store->getHubUidByLocalUser( $user->getId() );
		$this->output( "$name (user_id " . $user->getId() . '): '
			. ( $hubUid === null ? '未绑定 OTTOhub' : "已绑定 hub uid $hubUid" ) . "\n" );

		$lookup = $this->getServiceContainer()->getUserOptionsLookup();
		$categories = (array)$this->getServiceContainer()->getMainConfig()
			->get( 'NotifyTypeAvailabilityByCategory' );
		foreach ( $categories as $category => $map ) {
			if ( empty( $map['ottohub'] ) ) {
				continue;
			}
			$on = (bool)$lookup->getOption( $user, 'echo-subscriptions-ottohub-' . $category );
			$this->output( "  [$category] " . ( $on ? '已勾选（会推送）' : '未勾选（不推送）' ) . "\n" );
		}
	}
}

$maintClass = Notify::class;
require_once RUN_MAINTENANCE_IF_MAIN;
