<?php
/**
 * 建表 / 删表（阶段 1 映射表 ottohub_accounts）。
 *
 * 用法（服务器上，站点根目录）：
 *   php maintenance/run.php OttohubAuth:schema                # 建表（幂等）
 *   php maintenance/run.php OttohubAuth:schema --drop --yes   # 删表（回滚用）
 *
 * ⚠️ 这里**故意不用** DatabaseUpdater::doUpdates(['extensions'])：
 *   该方法没有“只跑某个扩展”的过滤开关，实测会顺带应用**所有**扩展的待定 schema 变更
 *   （2026-09-12 就因此把 SocialProfile 的 user_profile 4 个列 DROP 掉了，已还原）。
 *   因此本脚本直接执行本扩展的建表 DDL（幂等），只影响 ottohub_accounts。
 *
 * extension.json 里仍然保留 LoadExtensionSchemaUpdates → SchemaHooks，
 * 这样将来官方 update.php / 标准流程仍能识别本扩展的表。
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

class Schema extends Maintenance {

	/**
	 * 本扩展的唯一一张表。DDL 与 sql/mysql/table_ottohub_accounts.sql 保持一致
	 * （列名带 oa_ 前缀、两个唯一键；/*_* / 前缀与 /*$wgDBTableOptions* / 在此处展开）。
	 */
	private const CREATE_SQL = 'CREATE TABLE IF NOT EXISTS /*_*/ottohub_accounts (
  oa_hub_uid    INT UNSIGNED   NOT NULL,
  oa_user_id    INT UNSIGNED   NOT NULL,
  oa_username   VARBINARY(255) NOT NULL,
  oa_linked_by  INT UNSIGNED   NOT NULL DEFAULT 0,
  oa_linked_at  BINARY(14)     NOT NULL,
  PRIMARY KEY (oa_hub_uid),
  UNIQUE KEY oa_user_id (oa_user_id)
) /*$wgDBTableOptions*/';

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Create (or drop) the OttohubAuth mapping table (idempotent)' );
		$this->addOption( 'drop', 'Drop the table instead of creating it (rollback path)' );
		$this->addOption( 'yes', 'Confirm the destructive --drop action' );
		$this->requireExtension( 'OttohubAuth' );
	}

	public function execute() {
		$services = $this->getServiceContainer();
		$dbw = $services->getConnectionProvider()->getPrimaryDatabase();
		$table = OttohubAccountStore::TABLE;

		$existsBefore = $dbw->tableExists( $table );
		$this->output( "  table $table exists before: " . ( $existsBefore ? 'YES' : 'no' ) . "\n" );

		if ( $this->hasOption( 'drop' ) ) {
			$this->dropTable( $dbw, $table, $existsBefore );
			return;
		}

		if ( $existsBefore ) {
			$this->output( "  already present; nothing to create (idempotent)\n" );
		} else {
			$this->output( "  creating $table ...\n" );
			$dbw->query( $this->expandSql( self::CREATE_SQL ), __METHOD__ );
			if ( !$dbw->tableExists( $table ) ) {
				$this->fatalError( '  FAILED: table still missing after CREATE' );
			}
			$this->output( "  created\n" );
		}

		$this->printSchema( $dbw, $table );
	}

	private function dropTable( $dbw, string $table, bool $existsBefore ): void {
		if ( !$this->hasOption( 'yes' ) ) {
			$this->fatalError( '--drop is destructive; re-run with --drop --yes to confirm' );
		}
		if ( !$existsBefore ) {
			$this->output( "  nothing to drop\n" );
			return;
		}
		$rows = (int)$dbw->newSelectQueryBuilder()
			->select( 'COUNT(*)' )->from( $table )->caller( __METHOD__ )->fetchField();
		if ( $rows > 0 ) {
			$this->fatalError( "  refusing: table has $rows row(s); decide about the data first" );
		}
		$dbw->dropTable( $table, __METHOD__ );
		$this->output( "  dropped $table\n" );
	}

	/**
	 * 展开建表 SQL 里的占位符。站点 $wgDBTableOptions 就是
	 * "ENGINE=InnoDB, DEFAULT CHARSET=binary"（见 LocalSettings.php）。
	 */
	private function expandSql( string $sql ): string {
		global $wgDBTableOptions;
		$options = is_string( $wgDBTableOptions ) && $wgDBTableOptions !== ''
			? $wgDBTableOptions
			: 'ENGINE=InnoDB, DEFAULT CHARSET=binary';
		$sql = preg_replace( '!/\*_\*/', '', $sql );
		return str_replace( '/*$wgDBTableOptions*/', $options, $sql );
	}

	private function printSchema( $dbw, string $table ): void {
		$this->output( "  --- SHOW CREATE TABLE ---\n" );
		$res = $dbw->query( 'SHOW CREATE TABLE ' . $table, __METHOD__ );
		$row = $res->fetchRow();
		foreach ( explode( "\n", (string)$row[1] ) as $line ) {
			$this->output( '  ' . $line . "\n" );
		}
		$this->output( "  --- indexes ---\n" );
		$res = $dbw->query( 'SHOW INDEX FROM ' . $table, __METHOD__ );
		foreach ( $res as $idx ) {
			$this->output( sprintf(
				"  %s unique=%s column=%s\n",
				$idx->Key_name,
				( (int)$idx->Non_unique === 0 ) ? 'YES' : 'no',
				$idx->Column_name
			) );
		}
	}
}

// @codeCoverageIgnoreStart
$maintClass = Schema::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
