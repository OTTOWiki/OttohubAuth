<?php
/**
 * 验收辅助：创建/删除一个**临时本地测试账号**（用于验证"无本地口令 → 密码重置被封闭"等）。
 *
 * 用法（服务器上，站点根目录）：
 *   php maintenance/run.php OttohubAuth:testuser --name=OttohubAuthResetTest --create
 *   php maintenance/run.php OttohubAuth:testuser --name=OttohubAuthResetTest --delete
 *
 * --create 时会读取 /tmp/_mktest_pw 作为本地口令（调用方写入、用完即删；本脚本不回显口令）。
 * --delete 带安全护栏：账号有编辑或 OTTOhub 映射时**拒绝删除**。
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth\Maintenance;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\User\UserFactory;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

class TestUser extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Create or delete a temporary local test account (acceptance only)' );
		$this->addOption( 'name', 'Local username', true, true );
		$this->addOption( 'create', 'Create the account (reads /tmp/_mktest_pw)' );
		$this->addOption( 'delete', 'Delete the account (refuses if it has edits or a mapping)' );
		$this->requireExtension( 'OttohubAuth' );
	}

	public function execute() {
		$services = $this->getServiceContainer();
		$name = (string)$this->getOption( 'name' );
		$user = $services->getUserFactory()->newFromName( $name, UserFactory::RIGOR_CREATABLE );
		if ( $user === null ) {
			$this->fatalError( 'invalid username' );
		}

		if ( $this->hasOption( 'delete' ) ) {
			$this->deleteUser( $services, $user, $name );
			return;
		}

		if ( !$this->hasOption( 'create' ) ) {
			$this->fatalError( 'pass --create or --delete' );
		}

		if ( $user->isRegistered() ) {
			$this->output( "  already exists: user_id=" . $user->getId() . "\n" );
			return;
		}

		$plain = @file_get_contents( '/tmp/_mktest_pw' );
		$plain = is_string( $plain ) ? trim( $plain ) : '';
		if ( $plain === '' ) {
			$this->fatalError( 'missing /tmp/_mktest_pw' );
		}

		// 本地注册已关闭，这里走 autocreateaccount 路径（等价于 sysop 手工建号）
		$status = $services->getAuthManager()->autoCreateUser(
			$user, AuthManager::AUTOCREATE_SOURCE_MAINT, false
		);
		if ( !$status->isGood() ) {
			$this->fatalError( 'autoCreateUser failed: ' . $status->getMessage()->plain() );
		}

		$services->getConnectionProvider()->getPrimaryDatabase()->newUpdateQueryBuilder()
			->update( 'user' )
			->set( [ 'user_password' => $services->getPasswordFactory()
				->newFromPlaintext( $plain )->toString() ] )
			->where( [ 'user_id' => $user->getId() ] )
			->caller( __METHOD__ )->execute();

		$this->output( "  created $name user_id=" . $user->getId() . " (local password set, not echoed)\n" );
	}

	private function deleteUser( $services, $user, string $name ): void {
		if ( !$user->isRegistered() ) {
			$this->output( "  not found, nothing to delete\n" );
			return;
		}
		$uid = $user->getId();
		$dbw = $services->getConnectionProvider()->getPrimaryDatabase();
		$edits = (int)$dbw->newSelectQueryBuilder()
			->select( 'COUNT(*)' )->from( 'revision' )
			->join( 'actor', null, 'rev_actor=actor_id' )
			->where( [ 'actor_user' => $uid ] )->caller( __METHOD__ )->fetchField();
		$maps = (int)$dbw->newSelectQueryBuilder()
			->select( 'COUNT(*)' )->from( 'ottohub_accounts' )
			->where( [ 'oa_user_id' => $uid ] )->caller( __METHOD__ )->fetchField();
		if ( $edits > 0 || $maps > 0 ) {
			$this->fatalError( "  refusing to delete: revisions=$edits mappings=$maps" );
		}
		$dbw->newDeleteQueryBuilder()->deleteFrom( 'user' )
			->where( [ 'user_id' => $uid ] )->caller( __METHOD__ )->execute();
		$dbw->newDeleteQueryBuilder()->deleteFrom( 'actor' )
			->where( [ 'actor_user' => $uid ] )->caller( __METHOD__ )->execute();
		$this->output( "  deleted $name user_id=$uid\n" );
	}
}

// @codeCoverageIgnoreStart
$maintClass = TestUser::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
