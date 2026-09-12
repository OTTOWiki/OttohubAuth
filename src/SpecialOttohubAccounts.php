<?php
/**
 * Special:OttohubAccounts —— 管理员查看 / 解绑 / 重绑 / 恢复本地口令（D11 + §4.9）。
 *
 * ⚠️ 两套 ID 最容易搞混的地方（§4.2 三个“真会抓错人”的第 3 处）：
 *    本页**同时**显示 OTTOhub UID、OTTOhub 用户名、站内用户名与站内 user_id，
 *    且所有动作字段名都带 ottohub- 前缀，避免人手操作时传错。
 *
 * 权限：ottohubauth-manage（默认授给 sysop + bureaucrat，与 usermerge 授权范围一致）。
 * 审计：解绑/重绑/设置口令都写进 MediaWiki 日志系统（Special:Log）。
 * 口令：管理员动作生成的临时口令**只显示一次**，不写日志、不入文档。
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth;

use MediaWiki\Session\CsrfTokenSet;

use MediaWiki\Html\Html;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Logging\ManualLogEntry;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use Psr\Log\LoggerInterface;

class SpecialOttohubAccounts extends SpecialPage {

	/** 生成的临时口令长度 */
	private const TEMP_PASSWORD_LENGTH = 16;

	/** CSRF token 的 salt（表单与校验必须一致） */
	private const TOKEN_SALT = 'ottohubauth-accounts';

	private OttohubAccountStore $store;
	private OttohubClient $client;
	private OttohubProviderFactory $providerFactory;
	private UserFactory $userFactory;
	private LoggerInterface $logger;

	public function __construct(
		OttohubAccountStore $store,
		OttohubClient $client,
		OttohubProviderFactory $providerFactory,
		UserFactory $userFactory
	) {
		parent::__construct( 'OttohubAccounts', 'ottohubauth-manage' );
		$this->store = $store;
		$this->client = $client;
		$this->providerFactory = $providerFactory;
		$this->userFactory = $userFactory;
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
		$this->checkPermissions();
		$out = $this->getOutput();
		$out->setPageTitleMsg( wfMessage( 'ottohubauth-accounts-title' ) );
		$out->addWikiMsg( 'ottohubauth-accounts-intro' );
		$out->addWikiMsg( 'ottohubauth-accounts-id-warning' );

		$request = $this->getRequest();
		if ( $request->wasPosted() ) {
			if ( $this->handleAction() ) {
				return;
			}
		}

		$out->addHTML( $this->buildLookupForm() );
	}

	private function buildLookupForm(): string {
		// 1.46 起用 CsrfTokenSet（Session::getToken/matchToken 已移除）
		$token = ( new CsrfTokenSet( $this->getRequest() ) )->getToken()->toString();
		$html = Html::hidden( 'ottohubauth-token', $token )
			. Html::hidden( 'ottohubauth-action', 'lookup' )
			. Html::element( 'label', [ 'for' => 'ottohubauth-lookup' ],
				wfMessage( 'ottohubauth-accounts-lookup-label' )->text() )
			. Html::input( 'ottohubauth-lookup', '', 'text', [
				'id' => 'ottohubauth-lookup',
				'placeholder' => wfMessage( 'ottohubauth-accounts-lookup-placeholder' )->text(),
			] )
			. Html::submitButton( wfMessage( 'ottohubauth-accounts-lookup-button' )->text() );

		return Html::rawElement( 'form', [
			'method' => 'post',
			'action' => $this->getRequest()->getRequestURL(),
			'class' => 'ottohubauth-lookup-form',
		], $html );
	}

	/**
	 * @return bool 是否已处理完毕（true = 不再渲染后续表单）
	 */
	private function handleAction(): bool {
		$request = $this->getRequest();
		$out = $this->getOutput();

		$token = $request->getVal( 'ottohubauth-token' );
		if ( !is_string( $token ) || !( new CsrfTokenSet( $request ) )->matchToken( $token ) ) {
			$out->addWikiMsg( 'ottohubauth-error-bad-token' );
			return false;
		}

		$action = (string)$request->getVal( 'ottohubauth-action', 'lookup' );
		$performerId = $this->getUser()->getId();

		switch ( $action ) {
			case 'lookup':
				$this->renderLookup();
				return true;

			case 'unlink':
				$hubUid = $this->getIntParam( 'ottohubauth-hub-uid' );
				if ( $hubUid <= 0 ) {
					$out->addWikiMsg( 'ottohubauth-accounts-missing-params' );
					return false;
				}
				$mapping = $this->store->getByHubUid( $hubUid );
				if ( $mapping === null ) {
					$out->addWikiMsg( 'ottohubauth-accounts-not-linked' );
					return false;
				}
				$localUser = $this->userFactory->newFromId( (int)$mapping['oa_user_id'] );
				$this->store->unlink( $hubUid );
				$this->logAction( 'unlink', $performerId, [
					'hubUid' => $hubUid,
					'localUserName' => $localUser ? $localUser->getName() : (string)$mapping['oa_user_id'],
				] );
				$out->addWikiMsg( 'ottohubauth-accounts-unlinked',
					(string)$hubUid,
					$localUser ? $localUser->getName() : (string)$mapping['oa_user_id']
				);
				$this->renderLookup( $localUser ? $localUser->getName() : null );
				return true;

			case 'relink':
				return $this->handleRelink( $performerId );

			case 'setpass':
				return $this->handleSetPassword( $performerId );

			default:
				$out->addWikiMsg( 'ottohubauth-accounts-missing-params' );
				return false;
		}
	}

	private function handleRelink( int $performerId ): bool {
		$request = $this->getRequest();
		$out = $this->getOutput();

		$hubUid = $this->getIntParam( 'ottohubauth-hub-uid' );
		$localName = trim( (string)$request->getVal( 'ottohubauth-local-user', '' ) );
		if ( $hubUid <= 0 || $localName === '' ) {
			$out->addWikiMsg( 'ottohubauth-accounts-missing-params' );
			return false;
		}

		$localUser = $this->userFactory->newFromName( $localName );
		if ( $localUser === null || !$localUser->isRegistered() ) {
			$out->addWikiMsg( 'ottohubauth-accounts-no-such-user', $localName );
			return false;
		}

		// 必须先向 OTTOhub 核实该 hub uid 真实存在，并把 hub 用户名一并展示出来（防传错 id）
		$probe = $this->client->getUser( $hubUid );
		if ( !$probe->isOk() ) {
			$out->addWikiMsg( $this->messageKeyForUpstream( $probe ), $probe->getErrorCode() );
			return false;
		}
		$hubUsername = $probe->getString( 'username' ) ?? '';
		$probeUid = $probe->getString( 'uid' );
		if ( $probeUid !== null && (int)$probeUid !== $hubUid ) {
			$out->addWikiMsg( 'ottohubauth-error-identity' );
			return false;
		}

		if ( $this->store->isHubUidBoundToOther( $hubUid, $localUser->getId() ) ) {
			$out->addWikiMsg( 'ottohubauth-link-taken' );
			return false;
		}

		if ( !$this->store->relink( $hubUid, $localUser->getId(), $hubUsername, $performerId ) ) {
			$out->addWikiMsg( 'ottohubauth-error-link-conflict' );
			return false;
		}

		$this->logAction( 'relink', $performerId, [
			'hubUid' => $hubUid,
			'hubUsername' => $hubUsername,
			'localUserName' => $localUser->getName(),
			'localUserId' => $localUser->getId(),
		] );
		$out->addWikiMsg( 'ottohubauth-accounts-relinked',
			$localUser->getName(),
			(string)$localUser->getId(),
			(string)$hubUid,
			$hubUsername
		);
		$this->renderLookup( $localUser->getName() );
		return true;
	}

	private function handleSetPassword( int $performerId ): bool {
		$request = $this->getRequest();
		$out = $this->getOutput();

		$localName = trim( (string)$request->getVal( 'ottohubauth-local-user', '' ) );
		if ( $localName === '' ) {
			$out->addWikiMsg( 'ottohubauth-accounts-missing-params' );
			return false;
		}
		$localUser = $this->userFactory->newFromName( $localName );
		if ( $localUser === null || !$localUser->isRegistered() ) {
			$out->addWikiMsg( 'ottohubauth-accounts-no-such-user', $localName );
			return false;
		}

		// 随机临时口令：只在本次输出里显示一次；不写日志、不入文档
		$provider = $this->providerFactory->get();
		if ( $provider === null ) {
			$out->addWikiMsg( 'ottohubauth-error-session' );
			return false;
		}
		$temp = \MediaWiki\Password\PasswordFactory::generateRandomPasswordString( self::TEMP_PASSWORD_LENGTH );
		$provider->setLocalPassword( $localUser, $temp );

		$this->logAction( 'setpass', $performerId, [
			'localUserName' => $localUser->getName(),
			'localUserId' => $localUser->getId(),
		] );

		$out->addWikiMsg( 'ottohubauth-accounts-temppass', $localUser->getName() );
		$out->addHTML( Html::element( 'code', [ 'class' => 'ottohubauth-temppass' ], $temp ) );
		$out->addWikiMsg( 'ottohubauth-accounts-temppass-advice' );
		return true;
	}

	/**
	 * 渲染查询结果：同时显示两套 id，避免人手混淆。
	 */
	private function renderLookup( ?string $fallbackName = null ): void {
		$name = trim( (string)$this->getRequest()->getVal( 'ottohubauth-lookup', (string)$fallbackName ) );
		if ( $name === '' ) {
			$name = (string)$fallbackName;
		}
		if ( $name === '' ) {
			return;
		}

		$user = $this->userFactory->newFromName( $name );
		if ( $user === null || !$user->isRegistered() ) {
			$this->getOutput()->addWikiMsg( 'ottohubauth-accounts-no-such-user', $name );
			return;
		}

		$localUserId = $user->getId();
		$mapping = $this->store->getByLocalUserId( $localUserId );
		$provider = $this->providerFactory->get();
		$hasLocalPassword = $provider !== null && $provider->hasLocalPassword( $localUserId );

		$this->getOutput()->addWikiMsg(
			'ottohubauth-accounts-local',
			$user->getName(),
			(string)$localUserId,
			wfMessage( $hasLocalPassword
				? 'ottohubauth-accounts-password-yes'
				: 'ottohubauth-accounts-password-no' )->text()
		);

		if ( $mapping === null ) {
			$this->getOutput()->addWikiMsg( 'ottohubauth-accounts-not-linked' );
		} else {
			$this->getOutput()->addWikiMsg(
				'ottohubauth-accounts-bound',
				(string)$mapping['oa_hub_uid'],
				(string)$mapping['oa_username'],
				(string)$mapping['oa_linked_by'],
				(string)$mapping['oa_linked_at']
			);
		}

		$this->getOutput()->addHTML( $this->buildActionForms( $user->getName(), $mapping ) );
	}

	private function buildActionForms( string $localName, ?array $mapping ): string {
		// 1.46 起用 CsrfTokenSet（Session::getToken/matchToken 已移除）
		$token = ( new CsrfTokenSet( $this->getRequest() ) )->getToken()->toString();
		$action = $this->getRequest()->getRequestURL();
		$hiddenToken = Html::hidden( 'ottohubauth-token', $token );
		$hiddenUser = Html::hidden( 'ottohubauth-local-user', $localName );

		// 解绑（仅当已绑定）
		$unlink = '';
		if ( $mapping !== null ) {
			$unlink = Html::rawElement( 'fieldset', [],
				Html::element( 'legend', [], wfMessage( 'ottohubauth-accounts-unlink-legend' )->text() )
				. Html::rawElement( 'form', [ 'method' => 'post', 'action' => $action ],
					$hiddenToken . $hiddenUser
					. Html::hidden( 'ottohubauth-action', 'unlink' )
					. Html::hidden( 'ottohubauth-hub-uid', (string)$mapping['oa_hub_uid'] )
					. Html::submitButton( wfMessage( 'ottohubauth-accounts-unlink-button' )->text() )
				)
			);
		}

		// 重绑：管理员显式填 OTTOhub UID
		$relink = Html::rawElement( 'fieldset', [],
			Html::element( 'legend', [], wfMessage( 'ottohubauth-accounts-relink-legend' )->text() )
			. Html::rawElement( 'form', [ 'method' => 'post', 'action' => $action ],
				$hiddenToken . $hiddenUser
				. Html::hidden( 'ottohubauth-action', 'relink' )
				. Html::element( 'label', [ 'for' => 'ottohubauth-hub-uid' ],
					wfMessage( 'ottohubauth-accounts-hubuid-label' )->text() )
				. Html::input( 'ottohubauth-hub-uid', '', 'text', [
					'id' => 'ottohubauth-hub-uid',
					'class' => 'ottohubauth-hub-uid-input',
				] )
				. Html::rawElement( 'div', [ 'class' => 'htmlform-tip' ],
					wfMessage( 'ottohubauth-accounts-hubuid-help' )->parse() )
				. Html::submitButton( wfMessage( 'ottohubauth-accounts-relink-button' )->text() )
			)
		);

		// 恢复/设置本地口令（救援）
		$setpass = Html::rawElement( 'fieldset', [],
			Html::element( 'legend', [], wfMessage( 'ottohubauth-accounts-setpass-legend' )->text() )
			. Html::rawElement( 'form', [ 'method' => 'post', 'action' => $action ],
				$hiddenToken . $hiddenUser
				. Html::hidden( 'ottohubauth-action', 'setpass' )
				. Html::rawElement( 'div', [ 'class' => 'htmlform-tip' ],
					wfMessage( 'ottohubauth-accounts-setpass-help' )->parse() )
				. Html::submitButton( wfMessage( 'ottohubauth-accounts-setpass-button' )->text() )
			)
		);

		return $unlink . $relink . $setpass;
	}

	private function getIntParam( string $name ): int {
		$raw = $this->getRequest()->getVal( $name, '' );
		if ( !is_string( $raw ) || !preg_match( '/^[0-9]+$/', $raw ) ) {
			return 0;
		}
		return (int)$raw;
	}

	private function messageKeyForUpstream( OttohubResponse $response ): string {
		switch ( $response->getErrorCode() ) {
			case OttohubResponse::ERR_TRANSPORT:
				return 'ottohubauth-error-unavailable';
			case 'too_many_requests':
				return 'ottohubauth-error-upstream-throttled';
			default:
				return 'ottohubauth-error-upstream-detail';
		}
	}

	/**
	 * 审计日志（Special:Log）。只含两套 id 与动作，绝不含任何凭证。
	 */
	private function logAction( string $subtype, int $performerId, array $params ): void {
		$entry = new ManualLogEntry( 'ottohubauth', $subtype );
		$entry->setPerformer( $this->userFactory->newFromId( $performerId ) );
		$entry->setTarget( $this->getPageTitle() );
		$entry->setParameters( $params );
		$logId = $entry->insert();
		$entry->publish( $logId );
	}
}
