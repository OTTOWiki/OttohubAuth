<?php
/**
 * 调试辅助：打印扩展注册表里与本扩展相关的 HookHandlers / 命名空间 / 类存在性。
 *
 * 用法：php maintenance/run.php OttohubAuth:askreg
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Registration\ExtensionRegistry;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

class AskReg extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Inspect registered HookHandlers / autoload namespaces for OttohubAuth' );
	}

	public function execute() {
		$reg = ExtensionRegistry::getInstance();

		$this->output( "== HookHandlers（注册表里看到的，OttohubAuth 相关）==\n" );
		$handlers = $reg->getAttribute( 'HookHandlers' ) ?: [];
		foreach ( $handlers as $name => $spec ) {
			$class = is_array( $spec ) ? ( $spec['class'] ?? '?' ) : (string)$spec;
			if ( str_contains( (string)$class, 'OttohubAuth' ) ) {
				$this->output( "  $name => " . json_encode( $spec, JSON_UNESCAPED_SLASHES ) . "\n" );
			}
		}

		$this->output( "\n== 注册表里的 AutoloadNamespaces（OttohubAuth）==\n" );
		$ns = $reg->getAttribute( 'AutoloadNamespaces' ) ?: [];
		foreach ( $ns as $k => $v ) {
			if ( str_contains( (string)$k, 'OttohubAuth' ) ) {
				$this->output( "  $k => $v\n" );
			}
		}

		$this->output( "\n== class_exists ==\n" );
		foreach ( [ 'ProfileHooks', 'Hooks', 'OttohubClient', 'SchemaHooks' ] as $c ) {
			$fq = "MediaWiki\\Extension\\OttohubAuth\\$c";
			$this->output( "  $fq : " . ( class_exists( $fq ) ? 'YES' : 'NO' ) . "\n" );
		}

		$this->output( "\n== 文件是否可读 ==\n" );
		$dir = dirname( __DIR__ ) . '/src';
		foreach ( [ 'ProfileHooks.php', 'Hooks.php' ] as $f ) {
			$abs = "$dir/$f";
			$this->output( '  ' . $f . ' : ' . ( is_readable( $abs ) ? 'readable' : 'NOT READABLE' )
				. ' (' . ( file_exists( $abs ) ? filesize( $abs ) . 'B' : 'missing' ) . ")\n" );
		}
	}
}

// @codeCoverageIgnoreStart
$maintClass = AskReg::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
