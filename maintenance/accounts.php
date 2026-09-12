<?php
/**
 * 映射表 CLI 兜底（D11 的“另留一个 CLI 维护脚本作为兜底”）。
 *
 * 用法（服务器上，站点根目录）：
 *   php maintenance/run.php OttohubAuth:accounts                       # 列出全部绑定
 *   php maintenance/run.php OttohubAuth:accounts --local-user=ExampleUser   # 查某个站内账号
 *   php maintenance/run.php OttohubAuth:accounts --hub-uid=577         # 查某个 OTTOhub uid
 *   php maintenance/run.php OttohubAuth:accounts --unlink=577          # 解绑 OTTOhub uid 577
 *
 * ⚠️ 两套 ID 必须分清：--hub-uid 是 **OTTOhub 侧** uid，--local-user 是站内用户名。
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth\Maintenance;

use MediaWiki\Extension\OttohubAuth\OttohubAccountStore;
use MediaWiki\Maintenance\Maintenance;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

class Accounts extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'List or manage OTTOhub account mappings (CLI fallback)' );
		$this->addOption( 'local-user', 'Look up by local username', false, true );
		$this->addOption( 'hub-uid', 'Look up by OTTOhub uid', false, true );
		$this->addOption( 'unlink', 'Unlink the given OTTOhub uid', false, true );
		$this->addOption( 'limit', 'Max rows to list (default 200)', false, true );
		$this->requireExtension( 'OttohubAuth' );
	}

	public function execute() {
		$services = $this->getServiceContainer();
		$store = $services->getService( 'OttohubAuth.AccountStore' );

		$unlink = $this->getOption( 'unlink' );
		if ( $unlink !== null ) {
			$this->unlink( $store, (string)$unlink );
			return;
		}

		$localUser = $this->getOption( 'local-user' );
		if ( $localUser !== null ) {
			$this->showOne( $services, $store, (string)$localUser );
			return;
		}

		$hubUid = $this->getOption( 'hub-uid' );
		if ( $hubUid !== null ) {
			$this->showByHubUid( $store, (string)$hubUid );
			return;
		}

		$this->listAll( $services, (int)( $this->getOption( 'limit' ) ?? 200 ) );
	}

	private function listAll( $services, int $limit ): void {
		$dbr = $services->getConnectionProvider()->getReplicaDatabase();
		$rows = $dbr->newSelectQueryBuilder()
			->select( [ 'oa_hub_uid', 'oa_user_id', 'oa_username', 'oa_linked_by', 'oa_linked_at' ] )
			->from( OttohubAccountStore::TABLE )
			->orderBy( 'oa_hub_uid' )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchResultSet();

		$count = 0;
		foreach ( $rows as $row ) {
			$user = $services->getUserFactory()->newFromId( (int)$row->oa_user_id );
			$this->output( sprintf(
				"  hub_uid=%-8d local_user_id=%-8d local_name=%-24s hub_username=%-24s linked_by=%-6d at=%s\n",
				(int)$row->oa_hub_uid,
				(int)$row->oa_user_id,
				$user ? $user->getName() : '(missing)',
				(string)$row->oa_username,
				(int)$row->oa_linked_by,
				(string)$row->oa_linked_at
			) );
			$count++;
		}
		$this->output( "  total listed: $count\n" );
	}

	private function showOne( $services, $store, string $localName ): void {
		$user = $services->getUserFactory()->newFromName( $localName );
		if ( $user === null || !$user->isRegistered() ) {
			$this->fatalError( "no such local user: $localName" );
		}
		$this->output( '  local_user_id=' . $user->getId() . ' local_name=' . $user->getName() . "\n" );
		$mapping = $store->getByLocalUserId( $user->getId() );
		$this->printMapping( $mapping );
	}

	private function showByHubUid( $store, string $raw ): void {
		if ( !preg_match( '/^[0-9]+$/', $raw ) ) {
			$this->fatalError( 'hub-uid must be numeric' );
		}
		$mapping = $store->getByHubUid( (int)$raw );
		$this->printMapping( $mapping );
	}

	private function unlink( $store, string $raw ): void {
		if ( !preg_match( '/^[0-9]+$/', $raw ) ) {
			$this->fatalError( 'unlink must be a numeric OTTOhub uid' );
		}
		$hubUid = (int)$raw;
		$mapping = $store->getByHubUid( $hubUid );
		if ( $mapping === null ) {
			$this->output( "  no mapping for hub_uid=$hubUid (nothing to do)\n" );
			return;
		}
		$store->unlink( $hubUid );
		$this->output( "  unlinked hub_uid=$hubUid from local_user_id=" . (int)$mapping['oa_user_id'] . "\n" );
		$this->output( "  NOTE: this CLI action is not written to Special:Log; prefer Special:OttohubAccounts\n" );
	}

	private function printMapping( ?array $mapping ): void {
		if ( $mapping === null ) {
			$this->output( "  (not linked)\n" );
			return;
		}
		$this->output( sprintf(
			"  hub_uid=%d local_user_id=%d hub_username=%s linked_by=%d at=%s\n",
			(int)$mapping['oa_hub_uid'],
			(int)$mapping['oa_user_id'],
			(string)$mapping['oa_username'],
			(int)$mapping['oa_linked_by'],
			(string)$mapping['oa_linked_at']
		) );
	}
}

// @codeCoverageIgnoreStart
$maintClass = Accounts::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
