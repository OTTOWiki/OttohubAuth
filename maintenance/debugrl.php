<?php
/**
 * 调试辅助：打印某个 ResourceLoader 模块的编译/取址异常详情（生产模式下属页面只显示
 * "Fatal exception of type RuntimeException"，看不到原因）。
 *
 * 用法：php maintenance/run.php OttohubAuth:debugrl --module=ext.ottohubProfile
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\ResourceLoader\Context;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

class DebugRl extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Print ResourceLoader module diagnostics (exception details included)' );
		$this->addOption( 'module', 'Module name', true, true );
	}

	public function execute() {
		$services = $this->getServiceContainer();
		$rl = $services->getResourceLoader();
		$name = (string)$this->getOption( 'module' );

		$module = $rl->getModule( $name );
		if ( $module === null ) {
			$this->fatalError( "no such module: $name" );
		}
		$this->output( '  class    = ' . get_class( $module ) . "\n" );
		if ( method_exists( $module, 'getTargets' ) ) {
			$this->output( '  targets  = ' . implode( ',', $module->getTargets() ) . "\n" );
		}
		$this->output( '  messages = ' . implode( ', ', $module->getMessages() ) . "\n" );

		$skin = (string)$services->getMainConfig()->get( 'DefaultSkin' );
		$installedSkins = array_keys( $services->getSkinFactory()->getInstalledSkins() );
		$ctx = new Context(
			$rl,
			new \MediaWiki\Request\FauxRequest( [
				'lang' => 'zh-cn',
				'modules' => $name,
				'only' => 'scripts',
				'skin' => $skin,
				'debug' => 'true',
			] ),
			$installedSkins
		);
		foreach ( [ 'getVersionHash', 'getScriptURLsForDebug', 'getStyleURLsForDebug',
			'getDefinitionSummary', 'getMessages' ] as $method ) {
			$this->output( "  --- $method ---\n" );
			if ( !method_exists( $module, $method ) ) {
				$this->output( "    (method does not exist in this version)\n" );
				continue;
			}
			try {
				$r = $module->$method( $ctx );
				if ( is_array( $r ) ) {
					foreach ( $r as $k => $v ) {
						$this->output( '    ' . $k . ' => ' . ( is_scalar( $v ) ? (string)$v : gettype( $v ) ) . "\n" );
					}
				} else {
					$this->output( '    ' . var_export( $r, true ) . "\n" );
				}
			} catch ( \Throwable $e ) {
				$this->output( '    EXCEPTION ' . get_class( $e ) . ': ' . $e->getMessage() . "\n" );
				foreach ( array_slice( explode( "\n", $e->getTraceAsString() ), 0, 5 ) as $line ) {
					$this->output( "      $line\n" );
				}
			}
		}

		// 真正走一遍 load.php 的 respond 路径（异常原文会直接暴露）
		foreach ( [ 'scripts', 'styles' ] as $only ) {
			$this->output( "  --- respond(only=$only) ---\n" );
			try {
				$ctx = new Context(
					$rl,
					new \MediaWiki\Request\FauxRequest( [
						'lang' => 'zh-cn',
						'modules' => $name,
						'only' => $only,
						'skin' => $skin,
						'debug' => 'true',
					] ),
					$installedSkins
				);
				ob_start();
				$rl->respond( $ctx );
				$out = ob_get_clean();
				$this->output( '    OK, ' . strlen( (string)$out ) . " bytes\n" );
				$this->output( '    ' . substr( preg_replace( '/\s+/', ' ', (string)$out ), 0, 700 ) . "\n" );
			} catch ( \Throwable $e ) {
				if ( ob_get_level() > 0 ) {
					ob_end_clean();
				}
				$this->output( '    EXCEPTION ' . get_class( $e ) . ': ' . $e->getMessage() . "\n" );
				foreach ( array_slice( explode( "\n", $e->getTraceAsString() ), 0, 10 ) as $line ) {
					$this->output( "      $line\n" );
				}
			}
		}

		// 顺便逐个文件检查是否存在（这是最常见的 RuntimeException 来源）
		$this->output( "  --- 文件存在性 ---\n" );
		$extDir = dirname( __DIR__ );
		foreach ( [ 'resources/ext.ottohubProfile/index.js', 'resources/ext.ottohubProfile/index.css' ] as $rel ) {
			$abs = "$extDir/$rel";
			$this->output( '    ' . ( file_exists( $abs ) ? 'OK   ' : 'MISS ' ) . $rel
				. ( file_exists( $abs ) ? ' (' . filesize( $abs ) . ' bytes)' : '' ) . "\n" );
		}
	}
}

// @codeCoverageIgnoreStart
$maintClass = DebugRl::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
