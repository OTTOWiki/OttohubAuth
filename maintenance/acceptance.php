<?php
/**
 * 阶段 1 验收探针（尽量只读；写操作一律放在事务里回滚）。
 *
 * 用法：php maintenance/run.php OttohubAuth:acceptance
 *
 * 覆盖 docs/ottohub-sso.md §6 中**不需要真实 OTTOhub 口令**的用例；
 * 需要真实 OTTOhub 账号的用例（1/2/3/4/15/15b/16 的完整登录链路）另行执行，
 * 并在验收记录里如实标注“未实测”。
 *
 * 输出不含任何口令/token；写操作（映射表约束、口令列）全部包在事务里回滚。
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth\Maintenance;

use MediaWiki\Extension\OttohubAuth\OttohubAccountStore;
use MediaWiki\Extension\OttohubAuth\OttohubPrimaryAuthenticationProvider;
use MediaWiki\Maintenance\Maintenance;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

class Acceptance extends Maintenance {

	private int $pass = 0;
	private int $fail = 0;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'OttohubAuth stage-1 acceptance checks (mostly read-only)' );
		$this->requireExtension( 'OttohubAuth' );
	}

	public function execute() {
		$services = $this->getServiceContainer();
		$store = $services->getService( 'OttohubAuth.AccountStore' );
		$provider = $services->getService( 'OttohubAuth.Provider' )->get();
		if ( $provider === null ) {
			$this->fatalError( 'provider not available' );
		}
		$dbw = $services->getConnectionProvider()->getPrimaryDatabase();
		$userFactory = $services->getUserFactory();

		// ---- 用例 8：映射表约束（写操作，事务回滚） ----
		//
		// ⚠️ 这里**必须用合成 ID**（999000901/999000902），不要用 1/2 这种真实用户 id：
		// 2026-09-12 站长把自己的主账号（user_id=1）绑上 OTTOhub 之后，本用例整段开始失败——
		// 因为它假设"user 1 没有绑定"。测试数据要跟生产数据脱钩，否则真实使用会把测试打红。
		$this->section( 'case 8: mapping table constraints' );
		$testHubA = 999000001;
		$testHubB = 999000002;
		$testUserA = 999000901;
		$testUserB = 999000902;
		$dbw->startAtomic( __METHOD__ );
		try {
			$ok = $store->link( $testHubA, $testUserA, 'case8a' );
			$this->check( 'first link succeeds', $ok === true );

			$dupHub = $store->link( $testHubA, $testUserB, 'case8b' );
			$this->check( 'duplicate oa_hub_uid rejected', $dupHub === false );

			$dupUser = $store->link( $testHubB, $testUserA, 'case8c' );
			$this->check( 'duplicate oa_user_id rejected', $dupUser === false );

			$found = $store->getByHubUid( $testHubA );
			$this->check( 'getByHubUid returns the linked local user',
				$found !== null && (int)$found['oa_user_id'] === $testUserA );
			$found2 = $store->getByLocalUserId( $testUserA );
			$this->check( 'getByLocalUserId returns hub uid',
				$found2 !== null && (int)$found2['oa_hub_uid'] === $testHubA );
			$this->check( 'getHubUidByLocalUser maps local -> hub uid',
				$store->getHubUidByLocalUser( $testUserA ) === $testHubA );
			$this->check( 'isLocalUserLinked(linked) true', $store->isLocalUserLinked( $testUserA ) );
			$this->check( 'isHubUidBoundToOther(hub,other) true',
				$store->isHubUidBoundToOther( $testHubA, $testUserB ) );
			$this->check( 'isHubUidBoundToOther(hub,same) false',
				!$store->isHubUidBoundToOther( $testHubA, $testUserA ) );

			// 幂等：同一对 (hub, user) 再 link 一次应成功
			$this->check( 're-link same pair is idempotent', $store->link( $testHubA, $testUserA, 'case8d' ) === true );

			// updateHubUsername
			$store->updateHubUsername( $testHubA, 'renamed' );
			$found3 = $store->getByHubUid( $testHubA );
			$this->check( 'updateHubUsername works', (string)$found3['oa_username'] === 'renamed' );

			// unlink
			$store->unlink( $testHubA );
			$this->check( 'unlink removes the row', $store->getByHubUid( $testHubA ) === null );
		} finally {
			$dbw->endAtomic( __METHOD__ );
			// 保险：事务若因 DDL/隐式提交失效，再显式清一次测试数据
			$store->unlink( $testHubA );
			$store->unlink( $testHubB );
		}
		$leftover = $dbw->newSelectQueryBuilder()
			->select( 'COUNT(*)' )->from( OttohubAccountStore::TABLE )
			->where( [ 'oa_hub_uid > 999000000' ] )->caller( __METHOD__ )->fetchField();
		$this->check( 'no test rows left behind', (int)$leftover === 0 );

		// ---- 用例 9：两套 ID 不混用（代码复查，自动化版） ----
		$this->section( 'case 9: the two ID spaces are kept apart' );
		$src = dirname( __DIR__ ) . '/src';
		$files = glob( "$src/*.php" ) ?: [];
		$bad = [];
		$badTables = [];
		foreach ( $files as $f ) {
			// 只看代码，去掉注释与字符串字面量，避免把说明文字当成违规
			$code = $this->stripCommentsAndStrings( file_get_contents( $f ) );
			// 禁止裸 $uid（允许 $hubUid / $localUserId / $loginUidRaw 等带语义的名字）
			if ( preg_match( '/\$uid\b/', $code ) ) {
				$bad[] = basename( $f );
			}
			// 只有 AccountStore 可以直连映射表
			if ( !str_ends_with( $f, 'OttohubAccountStore.php' )
				&& ( str_contains( $code, 'ottohub_accounts' ) || str_contains( $code, 'self::TABLE' ) ) ) {
				$badTables[] = basename( $f );
			}
		}
		$this->check( 'no bare $uid in code'
			. ( $bad ? ' [offenders: ' . implode( ', ', $bad ) . ']' : '' ), $bad === [] );
		$this->check( 'mapping table accessed only via OttohubAccountStore'
			. ( $badTables ? ' [offenders: ' . implode( ', ', $badTables ) . ']' : '' ), $badTables === [] );

		$storeCode = file_get_contents( "$src/OttohubAccountStore.php" );
		$this->check( 'store uses oa_hub_uid / oa_user_id naming',
			str_contains( $storeCode, 'oa_hub_uid' ) && str_contains( $storeCode, 'oa_user_id' ) );
		$clientCode = $this->stripCommentsAndStrings( file_get_contents( "$src/OttohubClient.php" ) );
		$this->check( 'client code has no bare $uid', !preg_match( '/\$uid\b/', $clientCode ) );
		$this->check( 'client uid-taking entry points are named $hubUid',
			str_contains( file_get_contents( "$src/OttohubClient.php" ), 'function getUser( int $hubUid )' ) );
		$adminCode = file_get_contents( "$src/SpecialOttohubAccounts.php" );
		$this->check( 'admin page shows both hub uid and local user_id',
			str_contains( $adminCode, 'hubUid' ) && str_contains( $adminCode, 'localUserId' ) );
		$provText = file_get_contents( "$src/OttohubPrimaryAuthenticationProvider.php" );
		$this->check( 'claim flow verifies the local password (UsernameUtils is a hint only)',
			str_contains( $provText, 'verifyLocalPassword' ) );

		// ---- 用例 10：通知收件人只能来自 store 查询（阶段 3 尚未实现） ----
		$this->section( 'case 10: notification receiver (stage 3 not implemented)' );
		$this->check( 'OttohubAccountStore::getHubUidByLocalUser is the only mapping source',
			method_exists( OttohubAccountStore::class, 'getHubUidByLocalUser' ) );
		$this->check( 'stage-3 notifier exists only as an inert placeholder',
			file_exists( "$src/OttohubNotifier.php" )
			&& !str_contains( file_get_contents( "$src/OttohubNotifier.php" ), 'echo' . 'Notifier' ) );

		// ---- 用例 14 的静态部分：建号路径显式置空口令 ----
		$this->section( 'case 14 (static part): new SSO accounts get an unusable password' );
		$provCode = file_get_contents( "$src/OttohubPrimaryAuthenticationProvider.php" );
		$this->check( 'createLocalAccount calls disableLocalPassword',
			str_contains( $provCode, 'disableLocalPassword( $user )' ) );
		$this->check( 'password writes bypass AuthManager::changeAuthenticationData',
			!str_contains( $this->stripCommentsAndStrings( $provCode ), 'changeAuthenticationData' ) );
		$this->check( 'password write goes straight to user_password',
			str_contains( $provCode, "'user_password' => \$value" ) );

		// ---- 现有账号的本地口令状态盘点（只读，用于判断“有无口令”封闭逻辑） ----
		$this->section( 'read-only: current local password state' );
		$empty = (int)$dbw->newSelectQueryBuilder()
			->select( 'COUNT(*)' )->from( 'user' )->where( [ 'user_password' => '' ] )
			->caller( __METHOD__ )->fetchField();
		$total = (int)$dbw->newSelectQueryBuilder()
			->select( 'COUNT(*)' )->from( 'user' )->caller( __METHOD__ )->fetchField();
		$this->out( "accounts with empty local password: $empty / $total" );

		// 验证 hasLocalPassword 判定（不改动任何数据）。
		// ⚠️ 不要写死某个账号名：真实站点上任何账号都可能被停用本地口令，
		// 写死名字的断言会变成假失败。改成按 user_password 是否存在做不变量检查。
		$withPw = $dbw->newSelectQueryBuilder()
			->select( 'user_id' )->from( 'user' )
			->where( $dbw->expr( 'user_password', '!=', '' ) )
			->andWhere( $dbw->expr( 'user_id', '>', 0 ) )
			->limit( 1 )->caller( __METHOD__ )->fetchField();
		if ( $withPw ) {
			$this->check( 'hasLocalPassword(有口令的账号) is true',
				$provider->hasLocalPassword( (int)$withPw ) );
		}
		$noPw = $dbw->newSelectQueryBuilder()
			->select( 'user_id' )->from( 'user' )
			->where( [ 'user_password' => '' ] )
			->andWhere( $dbw->expr( 'user_id', '>', 0 ) )
			->limit( 1 )->caller( __METHOD__ )->fetchField();
		if ( $noPw ) {
			$this->check( 'hasLocalPassword(空口令的账号) is false',
				!$provider->hasLocalPassword( (int)$noPw ) );
		} else {
			$this->out( 'no passwordless account on this wiki, second check skipped' );
		}

		// ---- 用例 18 的前置：确认存在未绑定 OTTOhub 的 sysop（break-glass） ----
		$this->section( 'case 18 prerequisite: break-glass administrator' );
		$sysopIds = $dbw->newSelectQueryBuilder()
			->select( [ 'ug_user' ] )
			->from( 'user_groups' )
			->where( [ 'ug_group' => 'sysop' ] )
			->andWhere( $dbw->buildComparison( '>', [ 'ug_expiry' => $dbw->timestamp() ] )
				. ' OR ug_expiry IS NULL' )
			->caller( __METHOD__ )->fetchFieldValues();
		$unlinked = 0;
		$names = [];
		foreach ( $sysopIds as $sid ) {
			$u = $userFactory->newFromId( (int)$sid );
			if ( !$u || !$u->isRegistered() ) {
				continue;
			}
			if ( !$store->isLocalUserLinked( $u->getId() ) && $provider->hasLocalPassword( $u->getId() ) ) {
				$unlinked++;
				if ( count( $names ) < 3 ) {
					$names[] = $u->getName();
				}
			}
		}
		$this->out( "sysops with local password and no OTTOhub link: $unlinked" );
		$this->out( 'examples: ' . implode( ', ', $names ) );
		$this->check( 'at least one break-glass sysop exists', $unlinked >= 1 );

		// ---- 汇总 ----
		$this->section( 'summary' );
		$this->out( "PASS={$this->pass} FAIL={$this->fail}" );
		if ( $this->fail > 0 ) {
			$this->fatalError( 'some checks failed' );
		}
	}

	private function section( string $title ): void {
		$this->output( "\n== $title ==\n" );
	}

	private function check( string $label, bool $ok ): void {
		if ( $ok ) {
			$this->pass++;
			$this->output( "  [PASS] $label\n" );
		} else {
			$this->fail++;
			$this->output( "  [FAIL] $label\n" );
		}
	}

	private function out( string $msg ): void {
		$this->output( "  $msg\n" );
	}

	/**
	 * 粗略去掉 PHP 注释与字符串字面量，只留下真正的代码，供命名约定检查使用。
	 */
	private function stripCommentsAndStrings( string $code ): string {
		$tokens = token_get_all( $code );
		$out = '';
		foreach ( $tokens as $t ) {
			if ( is_array( $t ) ) {
				if ( in_array( $t[0], [ T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING ], true ) ) {
					$out .= ' ';
					continue;
				}
				$out .= $t[1];
			} else {
				$out .= $t;
			}
		}
		return $out;
	}
}

// @codeCoverageIgnoreStart
$maintClass = Acceptance::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
