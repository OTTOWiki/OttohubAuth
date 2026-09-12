<?php
/**
 * OTTOhub 登录主认证提供者 —— 阶段 1 核心。
 *
 * 身份模型（docs/ottohub-sso.md §4.3，D2）三段式：
 *   ① 发现候选：看 /api/profile 返回的 username 与站内用户名**规范化后**是否相等（只是线索）
 *   ② 确认身份：重名时必须用**该本地账号的口令**证明归属（用户名不是证明，可被抢注）
 *   ③ 绑定之后：一律按 hub_uid 匹配（username 会变，D9 要求改名跟随）
 *
 * 硬性要求：
 *  - 上游异常必须 FAIL/UI，**绝不 ABSTAIN**（否则会静默落到本地口令校验）§4.6.5
 *  - 口令零落日志：本类只在内存里持有口令，错误信息一律走 i18n 文案 §4.6.1
 *  - 清空/设置本地口令必须直接写 user.user_password，不得走 changeAuthenticationData()
 *    （会连带作废该账号所有 bot password）D14 / §4.9 坑 3
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth;

use MediaWiki\Auth\AbstractPrimaryAuthenticationProvider;
use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Auth\AuthenticationResponse;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Config\Config;
use MediaWiki\Message\Message;
use MediaWiki\Password\PasswordFactory;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserNameUtils;
use StatusValue;
use Wikimedia\Rdbms\IConnectionProvider;

class OttohubPrimaryAuthenticationProvider extends AbstractPrimaryAuthenticationProvider {

	private const SESSION_PENDING = 'OttohubAuth:pendingClaim';
	private const SESSION_CLAIM_PASSWORD = 'OttohubAuth:claimPassword';
	private const SESSION_PRIMARY_REQ = 'OttohubAuth:primaryRequest';

	private OttohubClient $client;
	private OttohubAccountStore $store;
	private OttohubThrottle $throttle;
	private UserFactory $userFactory;
	private IConnectionProvider $dbProvider;
	private PasswordFactory $passwordFactory;

	public function __construct(
		OttohubClient $client,
		OttohubAccountStore $store,
		OttohubThrottle $throttle,
		UserFactory $userFactory,
		UserNameUtils $userNameUtils,
		IConnectionProvider $dbProvider,
		PasswordFactory $passwordFactory,
		Config $config
	) {
		$this->client = $client;
		$this->store = $store;
		$this->throttle = $throttle;
		$this->userFactory = $userFactory;
		$this->userNameUtils = $userNameUtils; // 父类 protected 属性
		$this->dbProvider = $dbProvider;
		$this->passwordFactory = $passwordFactory;
		$this->config = $config;
		// 注意：$logger / $manager / $config 等由 AbstractAuthenticationProvider 声明，
		// 并在 AuthManager 调用 init() 时注入；子类不得重复声明（会 fatal）。
	}

	// ------------------------------------------------------------------
	// AuthenticationProvider 基础
	// ------------------------------------------------------------------

	/** @inheritDoc */
	public function getAuthenticationRequests( $action, array $options ) {
		if ( $action === AuthManager::ACTION_LOGIN ) {
			return [ new OttohubLoginRequest() ];
		}
		// ACTION_CREATE：本地注册保持关闭（D8），本 provider 不参与原生建号。
		return [];
	}

	/** @inheritDoc */
	public function accountCreationType() {
		return self::TYPE_NONE;
	}

	/** @inheritDoc */
	public function testUserExists( $username, $flags = 0 ) {
		// 我们是外部身份来源，不需要在这里断言本地账号存在性
		return false;
	}

	/** @inheritDoc */
	public function testUserCanAuthenticate( $username ) {
		$user = $this->userFactory->newFromName( $username );
		if ( !$user || !$user->isRegistered() ) {
			return false;
		}
		return $this->store->isLocalUserLinked( $user->getId() );
	}

	/** @inheritDoc */
	public function providerAllowsAuthenticationDataChange( AuthenticationRequest $req, $checkData = true ) {
		// 改绑/解绑只允许管理员走 Special:OttohubAccounts（D11），不提供用户自助
		return StatusValue::newGood( 'ignored' );
	}

	/** @inheritDoc */
	public function providerChangeAuthenticationData( AuthenticationRequest $req ) {
		// 同上：此处不做任何事
	}

	/**
	 * 原生建号流程：本 provider 不参与（本地注册保持关闭，D8）。
	 *
	 * 新账号一律走 beginPrimaryAuthentication 里的 AuthManager::autoCreateUser()。
	 */
	public function beginPrimaryAccountCreation( $user, $creator, array $reqs ) {
		return AuthenticationResponse::newAbstain();
	}

	/** @inheritDoc */
	public function testUserForCreation( $user, $autocreate, array $options = [] ) {
		// 我们通过 AuthManager::autoCreateUser() 自行建号，不走原生建号流程
		return StatusValue::newGood();
	}

	/** @inheritDoc */
	public function autoCreatedAccount( $user, $source ) {
		// 建号后的收尾统一在 createLocalAccount() 里做，避免两条路径分叉
	}

	// ------------------------------------------------------------------
	// ② beginPrimaryAuthentication
	// ------------------------------------------------------------------

	/** @inheritDoc */
	public function beginPrimaryAuthentication( array $reqs ) {
		$req = $this->firstRequest( $reqs, OttohubLoginRequest::class );
		if ( $req === null ) {
			return AuthenticationResponse::newAbstain();
		}

		$clientIp = $this->manager->getRequest()->getIP() ?? '';
		$account = trim( (string)$req->ottohubAccount );
		$password = (string)$req->ottohubPassword;

		if ( $account === '' || $password === '' ) {
			return $this->fail( 'ottohubauth-error-empty' );
		}

		if ( $this->throttle->isBlocked( $clientIp, $account ) ) {
			return $this->fail( 'ottohubauth-error-throttled' );
		}

		$login = $this->client->login( $account, $password );
		if ( !$login->isOk() ) {
			$this->throttle->bump( $clientIp, $account );
			return $this->fail( $this->messageForUpstream( $login, true ) );
		}

		$token = $login->getString( 'token' );
		if ( $token === null || $token === '' ) {
			$this->throttle->bump( $clientIp, $account );
			return $this->fail( 'ottohubauth-error-upstream-detail', OttohubResponse::ERR_MISSING_FIELD );
		}

		$profile = $this->client->profile( $token );
		if ( !$profile->isOk() ) {
			$this->throttle->bump( $clientIp, $account );
			return $this->fail( $this->messageForUpstream( $profile, false ) );
		}

		$hubUid = $this->parseHubUid( $profile->getString( 'uid' ) );
		if ( $hubUid === null ) {
			$this->throttle->bump( $clientIp, $account );
			return $this->fail( 'ottohubauth-error-upstream-detail', OttohubResponse::ERR_MISSING_FIELD );
		}

		// 绑定用的 hub uid 一律取 profile 的值（token 才是凭证）；并校验它与 login 响应一致。
		$loginUidRaw = $login->getString( 'uid' );
		if ( $loginUidRaw !== null && $this->parseHubUid( $loginUidRaw ) !== $hubUid ) {
			$this->logger->warning( 'OttohubAuth: uid mismatch between login and profile', [
				'hubUidProfile' => $hubUid,
			] );
			$this->throttle->bump( $clientIp, $account );
			return $this->fail( 'ottohubauth-error-identity' );
		}

		$hubUsername = $profile->getString( 'username' ) ?? '';
		$email = $profile->getString( 'email' );

		// 把初始请求留在会话里：命中重名进认领流程时复用，避免用户重填口令
		$this->manager->setAuthenticationSessionData( self::SESSION_PRIMARY_REQ, [
			'account' => $account,
			'password' => $password,
			'hubUid' => $hubUid,
			'hubUsername' => $hubUsername,
			'email' => $email,
		] );

		$mapping = $this->store->getByHubUid( $hubUid );
		if ( $mapping !== null ) {
			$localUser = $this->userFactory->newFromId( (int)$mapping['oa_user_id'] );
			if ( !$localUser || !$localUser->isRegistered() ) {
				// 映射悬空：站内账号已不存在。删掉悬空映射后按“新用户”重建，
				// 否则这个 hub 身份将永远登不进来。
				$this->store->unlink( $hubUid );
			} else {
				// 改名跟随：刷新记录里的 hub 用户名
				if ( $hubUsername !== '' && $hubUsername !== (string)$mapping['oa_username'] ) {
					$this->store->updateHubUsername( $hubUid, $hubUsername );
				}
				$this->maybeSetEmail( $localUser, $email );
				$this->throttle->clear( $clientIp, $account );
				return AuthenticationResponse::newPass( $localUser->getName() );
			}
		}

		// 未命中映射 → 按 D3 处理重名
		$candidate = $this->sanitizeUsername( $hubUsername, $hubUid );
		$existing = $this->userFactory->newFromName( $candidate, UserFactory::RIGOR_CREATABLE );
		if ( $existing !== null && $existing->isRegistered() ) {
			// 发现候选（只是线索，不是证明）→ 进入认领交互
			$this->manager->setAuthenticationSessionData( self::SESSION_PENDING, [
				'hubUid' => $hubUid,
				'hubUsername' => $hubUsername,
				'localName' => $existing->getName(),
			] );
			return AuthenticationResponse::newUI(
				[ new OttohubClaimRequest() ],
				wfMessage( 'ottohubauth-claim-intro', $existing->getName(), $hubUsername ),
				'warning'
			);
		}

		// 站内无重名 → 自动建号
		return $this->createLocalAccount( $hubUid, $hubUsername, $email, $candidate, $clientIp, $account );
	}

	// ------------------------------------------------------------------
	// ③ continuePrimaryAuthentication（重名认领）
	// ------------------------------------------------------------------

	/** @inheritDoc */
	public function continuePrimaryAuthentication( array $reqs ) {
		$pending = $this->manager->getAuthenticationSessionData( self::SESSION_PENDING );
		$primary = $this->manager->getAuthenticationSessionData( self::SESSION_PRIMARY_REQ );
		if ( !is_array( $pending ) || !is_array( $primary ) ) {
			$this->clearAuthSession();
			return $this->fail( 'ottohubauth-error-session' );
		}

		$req = $this->firstRequest( $reqs, OttohubClaimRequest::class );
		if ( $req === null ) {
			return AuthenticationResponse::newUI( [ new OttohubClaimRequest() ], null, 'error' );
		}

		$hubUid = (int)$pending['hubUid'];
		$hubUsername = (string)$pending['hubUsername'];
		$localName = (string)$pending['localName'];
		$localUser = $this->userFactory->newFromName( $localName );
		$clientIp = $this->manager->getRequest()->getIP() ?? '';
		$account = (string)$primary['account'];

		if ( $this->throttle->isBlocked( $clientIp, $account ) ) {
			$this->clearAuthSession();
			return $this->fail( 'ottohubauth-error-throttled' );
		}

		try {
			if ( $req->claimIt ) {
				// 选“这是我的账号”：必须用**该本地账号的口令**证明归属
				if ( !$localUser || !$localUser->isRegistered() ) {
					$this->clearAuthSession();
					return $this->fail( 'ottohubauth-error-account-vanished' );
				}

				$proof = (string)$req->localPassword;
				if ( $proof === '' ) {
					$proof = (string)$this->manager->getAuthenticationSessionData( self::SESSION_CLAIM_PASSWORD, '' );
				}
				if ( !$this->verifyLocalPassword( $localUser, $proof ) ) {
					$this->throttle->bump( $clientIp, $account );
					// 保留口令以便重试；不回显到界面、不进日志
					$this->manager->setAuthenticationSessionData( self::SESSION_CLAIM_PASSWORD, $proof );
					return AuthenticationResponse::newUI(
						[ new OttohubClaimRequest() ],
						wfMessage( 'ottohubauth-claim-wrong-password' ),
						'error'
					);
				}

				if ( !$this->store->link(
					$hubUid,
					$localUser->getId(),
					$hubUsername,
					OttohubAccountStore::LINKED_BY_LOGIN
				) ) {
					$this->clearAuthSession();
					return $this->fail( 'ottohubauth-error-link-conflict' );
				}

				// D14：绑定时当场问一次；默认不勾＝这次不动本地口令
				if ( $req->disableLocalPassword ) {
					$this->disableLocalPassword( $localUser );
				}

				// 邮箱按 D13：只在本地为空时写入并确认
				$email = $primary['email'] ?? null;
				if ( is_string( $email ) && $email !== '' ) {
					$this->maybeSetEmail( $localUser, $email );
				}

				$this->throttle->clear( $clientIp, $account );
				$this->clearAuthSession();
				return AuthenticationResponse::newPass( $localUser->getName() );
			}

			// 选“不是我的” → 自动另分名建号（§9.3-6）
			$email = $primary['email'] ?? null;
			return $this->createLocalAccount(
				$hubUid,
				$hubUsername,
				is_string( $email ) ? $email : null,
				$this->uniqueUsername( $hubUsername, $hubUid ),
				$clientIp,
				$account
			);
		} finally {
			$this->manager->removeAuthenticationSessionData( self::SESSION_CLAIM_PASSWORD );
		}
	}

	/** @inheritDoc */
	public function postAuthentication( $user, AuthenticationResponse $response ) {
		$primary = $this->manager->getAuthenticationSessionData( self::SESSION_PRIMARY_REQ );
		if ( is_array( $primary ) && isset( $primary['hubUid'] ) ) {
			// 只记 id 与 hub uid，绝不含口令/token
			$this->logger->info( 'OttohubAuth: SSO login succeeded', [
				'localUserId' => $user->getId(),
				'hubUid' => (int)$primary['hubUid'],
			] );
		}
		$this->clearAuthSession();
	}

	// ------------------------------------------------------------------
	// 内部实现
	// ------------------------------------------------------------------

	/**
	 * @param AuthenticationRequest[] $reqs
	 * @param string $class
	 * @return AuthenticationRequest|null
	 */
	private function firstRequest( array $reqs, string $class ) {
		foreach ( $reqs as $req ) {
			if ( $req instanceof $class ) {
				return $req;
			}
		}
		return null;
	}

	private function fail( string $msgKey, ...$args ): AuthenticationResponse {
		return AuthenticationResponse::newFail( wfMessage( $msgKey, ...$args ) );
	}

	/**
	 * 上游错误 → 面向用户的提示（§4.5）。
	 */
	private function messageForUpstream( OttohubResponse $response, bool $isLogin ): Message {
		$code = $response->getErrorCode();
		switch ( $code ) {
			case OttohubResponse::ERR_TRANSPORT:
				return wfMessage( 'ottohubauth-error-unavailable' );
			case 'error_password':
				return wfMessage( 'ottohubauth-error-bad-credentials' );
			case 'error_token':
				return $isLogin
					? wfMessage( 'ottohubauth-error-identity' )
					: wfMessage( 'ottohubauth-error-session' );
			case 'too_many_requests':
				return wfMessage( 'ottohubauth-error-upstream-throttled' );
			default:
				return wfMessage( 'ottohubauth-error-upstream-detail', $code );
		}
	}

	/**
	 * 解析上游 uid。上游返回的是**字符串** uid；只接受纯数字且 > 0。
	 */
	private function parseHubUid( ?string $raw ): ?int {
		if ( $raw === null || !preg_match( '/^[0-9]+$/', $raw ) ) {
			return null;
		}
		$value = (int)$raw;
		return $value > 0 ? $value : null;
	}

	/**
	 * hub 用户名净化（§9.3-8）：非法/为空时回退为 Ottohub<hub_uid>。
	 */
	private function sanitizeUsername( string $hubUsername, int $hubUid ): string {
		$fallback = 'Ottohub' . $hubUid;
		$name = trim( $hubUsername );
		if ( $name === '' ) {
			return $fallback;
		}
		// 本站 $wgInvalidUsernameCharacters 以及标题层面非法字符
		$name = str_replace( [ '@', ':', '>', '=', '#', '<', '[', ']', '|', '{', '}', '/', '\\' ], ' ', $name );
		$name = trim( preg_replace( '/\s+/u', ' ', $name ) ?? '' );
		if ( $name === '' ) {
			return $fallback;
		}
		$canonical = $this->userNameUtils->getCanonical( $name, UserNameUtils::RIGOR_CREATABLE );
		if ( $canonical === false || $canonical === '' ) {
			return $fallback;
		}
		if ( !$this->userNameUtils->isUsable( $canonical ) || !$this->userNameUtils->isCreatable( $canonical ) ) {
			return $fallback;
		}
		return $canonical;
	}

	/**
	 * 生成不冲突的站内用户名：原名 (2)、(3)…；连试仍冲突则用 原名-<hub_uid>（§9.3-6）。
	 */
	private function uniqueUsername( string $hubUsername, int $hubUid ): string {
		$base = $this->sanitizeUsername( $hubUsername, $hubUid );
		if ( !$this->usernameTaken( $base ) ) {
			return $base;
		}
		for ( $i = 2; $i <= 6; $i++ ) {
			$candidate = $base . ' (' . $i . ')';
			if ( $this->userNameUtils->isCreatable( $candidate ) && !$this->usernameTaken( $candidate ) ) {
				return $candidate;
			}
		}
		return $base . '-' . $hubUid;
	}

	private function usernameTaken( string $name ): bool {
		$user = $this->userFactory->newFromName( $name );
		return $user !== null && $user->isRegistered();
	}

	/**
	 * 自动建号 + 写映射 + 处理邮箱。
	 */
	private function createLocalAccount(
		int $hubUid,
		string $hubUsername,
		?string $email,
		string $candidateName,
		string $clientIp,
		string $account
	): AuthenticationResponse {
		$user = $this->userFactory->newFromName( $candidateName, UserFactory::RIGOR_CREATABLE );
		if ( $user === null ) {
			$this->throttle->bump( $clientIp, $account );
			return $this->fail( 'ottohubauth-error-username-invalid' );
		}

		$status = $this->manager->autoCreateUser( $user, self::class, true );
		if ( !$status->isGood() ) {
			$this->throttle->bump( $clientIp, $account );
			$this->logger->warning( 'OttohubAuth: autoCreateUser failed', [
				'hubUid' => $hubUid,
				'status' => $status->getStatusValue()->getValue(),
			] );
			return AuthenticationResponse::newFail(
				wfMessage( 'ottohubauth-error-autocreate', $status->getMessage()->plain() )
			);
		}

		if ( !$this->store->link( $hubUid, $user->getId(), $hubUsername, OttohubAccountStore::LINKED_BY_LOGIN ) ) {
			$this->throttle->bump( $clientIp, $account );
			return $this->fail( 'ottohubauth-error-link-conflict' );
		}

		// D14：新号显式写入不可用口令，不依赖 addToDatabase() 的默认行为
		$this->disableLocalPassword( $user );

		$this->maybeSetEmail( $user, $email );
		$this->throttle->clear( $clientIp, $account );
		$this->clearAuthSession();

		return AuthenticationResponse::newPass( $user->getName() );
	}

	/**
	 * 校验本地账号口令（与核心 LocalPasswordPrimaryAuthenticationProvider 同法）：
	 * PasswordFactory::newFromCiphertext( user.user_password )->verify( $plain )。
	 */
	private function verifyLocalPassword( User $user, string $plain ): bool {
		if ( $plain === '' ) {
			return false;
		}
		$hash = $this->getLocalPasswordHash( $user->getId() );
		if ( $hash === null ) {
			return false;
		}
		return $this->passwordFactory->newFromCiphertext( $hash )->verify( $plain );
	}

	/**
	 * 该本地账号当前**有无**本地口令（D14 的密码重置封闭判定用）。
	 */
	public function hasLocalPassword( int $localUserId ): bool {
		return $this->getLocalPasswordHash( $localUserId ) !== null;
	}

	private function getLocalPasswordHash( int $localUserId ): ?string {
		if ( $localUserId <= 0 ) {
			return null;
		}
		$hash = $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( [ 'user_password' ] )
			->from( 'user' )
			->where( [ 'user_id' => $localUserId ] )
			->caller( __METHOD__ )
			->fetchField();
		return ( is_string( $hash ) && $hash !== '' ) ? $hash : null;
	}

	/**
	 * 停用本地口令：**直接置空 user.user_password**。
	 *
	 * ⚠️ 绝不可走 AuthManager::changeAuthenticationData() —— 那条路径会
	 *    botPasswordStore->invalidateUserPasswords()，连带作废该账号所有 bot password。
	 */
	public function disableLocalPassword( User $user ): void {
		$this->writeLocalPassword( $user->getId(), '' );
	}

	/**
	 * 设置本地口令（管理员救援 / 用户在偏好里重新启用）。
	 * 同样直接写列，不走 changeAuthenticationData()。
	 */
	public function setLocalPassword( User $user, string $plain ): void {
		$hash = $this->passwordFactory->newFromPlaintext( $plain )->toString();
		$this->writeLocalPassword( $user->getId(), $hash );
	}

	private function writeLocalPassword( int $localUserId, string $value ): void {
		if ( $localUserId <= 0 ) {
			return;
		}
		$this->dbProvider->getPrimaryDatabase()->newUpdateQueryBuilder()
			->update( 'user' )
			->set( [
				'user_password' => $value,
				'user_password_expires' => null,
			] )
			->where( [ 'user_id' => $localUserId ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * 邮箱策略（D4 + D13）：**只在本地邮箱为空时**写入 OTTOhub 邮箱并直接确认。
	 */
	private function maybeSetEmail( User $user, ?string $email ): void {
		if ( $email === null || $email === '' || $user->getEmail() !== '' ) {
			return;
		}
		$user->setEmail( $email );
		$user->confirmEmail();
		$user->saveSettings();
	}

	private function clearAuthSession(): void {
		$this->manager->removeAuthenticationSessionData( self::SESSION_PENDING );
		$this->manager->removeAuthenticationSessionData( self::SESSION_PRIMARY_REQ );
		$this->manager->removeAuthenticationSessionData( self::SESSION_CLAIM_PASSWORD );
	}
}
