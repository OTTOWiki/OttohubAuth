<?php
/**
 * Special:LinkOttohub —— 首次自助绑定 OTTOhub 身份（§4.8 FAQ 第二条、§9.3-7）。
 *
 * 只做**首次绑定**：改绑/解绑一律归管理员（D11 / Special:OttohubAccounts）。
 * 入口：注册说明页 / 登录页 FAQ 文案，以及 Special:Preferences 的未绑定提示。
 *
 * D14：本页同样提供“绑定后停用 wiki 本地密码”复选框（**默认不勾**）与收益/风险说明。
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth;

use MediaWiki\Session\CsrfTokenSet;

use MediaWiki\Html\Html;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use Psr\Log\LoggerInterface;

class SpecialLinkOttohub extends SpecialPage {

	/** CSRF token 的 salt（表单与校验必须一致） */
	private const TOKEN_SALT = 'ottohubauth-link';

	private OttohubAccountStore $store;
	private OttohubClient $client;
	private OttohubProviderFactory $providerFactory;
	private LoggerInterface $logger;

	public function __construct(
		OttohubAccountStore $store,
		OttohubClient $client,
		OttohubProviderFactory $providerFactory
	) {
		parent::__construct( 'LinkOttohub' );
		$this->store = $store;
		$this->client = $client;
		$this->providerFactory = $providerFactory;
		$this->logger = LoggerFactory::getInstance( 'OttohubAuth' );
	}

	/** @inheritDoc */
	public function doesWrites() {
		return true;
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'users';
	}

	/** @inheritDoc */
	public function execute( $subPage ) {
		$this->requireLogin( 'ottohubauth-link-require-login' );
		$out = $this->getOutput();
		$out->setPageTitleMsg( wfMessage( 'ottohubauth-link-title' ) );

		$user = $this->getUser();
		$request = $this->getRequest();

		if ( $this->store->isLocalUserLinked( $user->getId() ) ) {
			$out->addWikiMsg( 'ottohubauth-link-already-linked', $user->getName() );
			$out->addWikiMsg( 'ottohubauth-link-admin-note' );
			return;
		}

		if ( $request->wasPosted() ) {
			if ( $this->handleSubmit( $user->getId(), $user->getName() ) ) {
				return;
			}
		}

		$out->addWikiMsg( 'ottohubauth-link-intro', $user->getName() );
		$out->addHTML( $this->buildForm() );
	}

	private function buildForm(): string {
		// 1.46 起用 CsrfTokenSet（Session::getToken/matchToken 已移除）
		$formToken = ( new CsrfTokenSet( $this->getRequest() ) )->getToken()->toString();
		$html = Html::hidden( 'ottohubauth-token', $formToken );
		$html .= Html::rawElement( 'div', [ 'class' => 'htmlform-tip' ],
			wfMessage( 'ottohubauth-field-account-help' )->parse() );
		$html .= Html::element( 'label', [ 'for' => 'ottohubauth-account' ],
			wfMessage( 'ottohubauth-field-account' )->text() );
		$html .= Html::input( 'ottohubauth-account', '', 'text', [
			'id' => 'ottohubauth-account',
			'required' => true,
			'autocomplete' => 'username',
		] );

		$html .= Html::element( 'label', [ 'for' => 'ottohubauth-password' ],
			wfMessage( 'ottohubauth-field-password' )->text() );
		$html .= Html::input( 'ottohubauth-password', '', 'password', [
			'id' => 'ottohubauth-password',
			'required' => true,
			'autocomplete' => 'current-password',
		] );

		$html .= Html::rawElement( 'div', [ 'class' => 'htmlform-tip' ],
			wfMessage( 'ottohubauth-field-password-help' )->parse() );

		$html .= Html::rawElement( 'div', [ 'class' => 'ottohubauth-disable-block' ],
			Html::check( 'ottohubauth-disable-password', false, [ 'id' => 'ottohubauth-disable-password' ] )
			. Html::element( 'label', [ 'for' => 'ottohubauth-disable-password' ],
				wfMessage( 'ottohubauth-disable-local-password-label' )->text() )
			. Html::rawElement( 'div', [ 'class' => 'htmlform-tip' ],
				wfMessage( 'ottohubauth-disable-local-password-help' )->parse() )
		);

		$html .= Html::submitButton( wfMessage( 'ottohubauth-link-submit' )->text() );

		return Html::rawElement( 'form', [
			'method' => 'post',
			'action' => $this->getRequest()->getRequestURL(),
			'class' => 'ottohubauth-link-form',
		], $html );
	}

	/**
	 * @return bool 是否已处理完毕（true = 不再渲染表单）
	 */
	private function handleSubmit( int $localUserId, string $localUserName ): bool {
		$request = $this->getRequest();
		$out = $this->getOutput();

		$token = $request->getVal( 'ottohubauth-token' );
		if ( !is_string( $token ) || !( new CsrfTokenSet( $request ) )->matchToken( $token ) ) {
			$out->addWikiMsg( 'ottohubauth-error-bad-token' );
			return false;
		}

		$account = trim( (string)$request->getVal( 'ottohubauth-account', '' ) );
		$password = (string)$request->getVal( 'ottohubauth-password', '' );
		$disableLocalPassword = (bool)$request->getCheck( 'ottohubauth-disable-password' );

		if ( $account === '' || $password === '' ) {
			$out->addWikiMsg( 'ottohubauth-error-empty' );
			return false;
		}

		$login = $this->client->login( $account, $password );
		if ( !$login->isOk() ) {
			$out->addWikiMsg( $this->messageKeyForUpstream( $login ) );
			return false;
		}
		$token = $login->getString( 'token' );
		if ( $token === null || $token === '' ) {
			$out->addWikiMsg( 'ottohubauth-error-upstream-detail', OttohubResponse::ERR_MISSING_FIELD );
			return false;
		}
		$profile = $this->client->profile( $token );
		if ( !$profile->isOk() ) {
			$out->addWikiMsg( $this->messageKeyForUpstream( $profile ) );
			return false;
		}
		$rawUid = $profile->getString( 'uid' );
		if ( $rawUid === null || !preg_match( '/^[0-9]+$/', $rawUid ) || (int)$rawUid <= 0 ) {
			$out->addWikiMsg( 'ottohubauth-error-upstream-detail', OttohubResponse::ERR_MISSING_FIELD );
			return false;
		}
		$hubUid = (int)$rawUid;
		$hubUsername = $profile->getString( 'username' ) ?? '';

		// 目标：该 hub 身份必须尚未被他人绑定
		if ( $this->store->isHubUidBoundToOther( $hubUid, $localUserId ) ) {
			$out->addWikiMsg( 'ottohubauth-link-taken' );
			return true;
		}

		if ( !$this->store->link( $hubUid, $localUserId, $hubUsername, OttohubAccountStore::LINKED_BY_LOGIN ) ) {
			$out->addWikiMsg( 'ottohubauth-error-link-conflict' );
			return true;
		}

		if ( $disableLocalPassword ) {
			$provider = $this->providerFactory->get();
			if ( $provider !== null ) {
				$provider->disableLocalPassword( $this->getUser() );
			}
		}

		$this->logger->info( 'OttohubAuth: self-service link', [
			'localUserId' => $localUserId,
			'hubUid' => $hubUid,
			'disabledLocalPassword' => $disableLocalPassword,
		] );

		$out->addWikiMsg( 'ottohubauth-link-success', $localUserName, $hubUsername, (string)$hubUid );
		if ( $disableLocalPassword ) {
			$out->addWikiMsg( 'ottohubauth-pref-password-disabled' );
		}
		return true;
	}

	private function messageKeyForUpstream( OttohubResponse $response ): string {
		switch ( $response->getErrorCode() ) {
			case OttohubResponse::ERR_TRANSPORT:
				return 'ottohubauth-error-unavailable';
			case 'error_password':
				return 'ottohubauth-error-bad-credentials';
			case 'too_many_requests':
				return 'ottohubauth-error-upstream-throttled';
			default:
				return 'ottohubauth-error-upstream-detail';
		}
	}
}
