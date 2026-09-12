<?php
/**
 * 调试辅助：以真实用户身份渲染一个页面标题，抓异常原文（生产模式下页面只显示 500）。
 *
 * 用法：
 *   php maintenance/run.php OttohubAuth:renderpage --title=User:ExampleUser
 *   php maintenance/run.php OttohubAuth:renderpage --title=User:ExampleUser --user=ExampleUser
 *
 * 依据 docs/pitfalls.md L 节：渲染"要求登录"的页面必须在 CLI 里 setUser 成真实用户。
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth\Maintenance;

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Request\FauxRequest;
use MediaWiki\Title\Title;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

class RenderPage extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Render a page title as a real user and print any exception details' );
		$this->addOption( 'title', 'Page title', true, true );
		$this->addOption( 'user', 'Viewer username (default: anonymous)', false, true );
	}

	public function execute() {
		$services = $this->getServiceContainer();
		$titleText = (string)$this->getOption( 'title' );
		$title = Title::newFromText( $titleText );
		if ( $title === null ) {
			$this->fatalError( "bad title: $titleText" );
		}
		$this->output( "  title = {$title->getPrefixedText()} (ns={$title->getNamespace()})\n" );

		$userName = $this->getOption( 'user' );
		$user = $userName
			? $services->getUserFactory()->newFromName( (string)$userName )
			: $services->getUserFactory()->newAnonymous( '127.0.0.1' );
		if ( !$user ) {
			$this->fatalError( 'bad user' );
		}
		$this->output( '  viewer = ' . $user->getName() . ' (id=' . $user->getId() . ")\n" );

		$context = \MediaWiki\Context\RequestContext::getMain();
		$request = new FauxRequest( [] );
		$context->setRequest( $request );
		$context->setUser( $user );
		$context->setTitle( $title );
		$this->getServiceContainer()->getSkinFactory(); // 预热

		try {
			$wikiPage = $services->getWikiPageFactory()->newFromTitle( $title );
			$content = $wikiPage->getContent();
			$this->output( '  content model = ' . ( $content ? $content->getModel() : '(none)' ) . "\n" );

			// 走 Article::view()，与真实请求同路
			$articleObj = $services->getArticleFactory()->newFromTitle( $title );
			ob_start();
			$articleObj->view();
			$html = (string)ob_get_clean();
			$this->output( '  rendered OK, ' . strlen( $html ) . " bytes\n" );
			$this->output( '  contains ottohubauth block: '
				. ( str_contains( $html, 'ottohubauth-profile-block' ) ? 'YES' : 'no' ) . "\n" );
			$this->output( '  contains data-ottohub-uid: '
				. ( str_contains( $html, 'data-ottohub-uid' ) ? 'YES' : 'no' ) . "\n" );
			$this->output( '  contains user-page-right: '
				. ( str_contains( $html, 'user-page-right' ) ? 'YES' : 'no' ) . "\n" );
		} catch ( \Throwable $e ) {
			if ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			$this->output( '  EXCEPTION ' . get_class( $e ) . ': ' . $e->getMessage() . "\n" );
			$this->output( '  at ' . $e->getFile() . ':' . $e->getLine() . "\n" );
			foreach ( array_slice( explode( "\n", $e->getTraceAsString() ), 0, 12 ) as $line ) {
				$this->output( "    $line\n" );
			}
		}
	}
}

// @codeCoverageIgnoreStart
$maintClass = RenderPage::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
