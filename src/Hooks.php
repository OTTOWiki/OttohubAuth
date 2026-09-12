<?php
/**
 * 钩子集合：
 *  - 注册页改造（§4.8 做法 A）：拦截 Special:CreateAccount，渲染“注册说明页 + FAQ + 登录入口”
 *  - 登录页 UX：给「站内账号」与「OTTOhub 账号」两组字段打标记（供 ext.ottohubLogin 做成标签页），
 *    并在表单下方补上登录常见问题
 *  - 密码重置按“当前有无本地口令”封闭（D14 / §4.9）
 *  - Special:Preferences 里提供“停用 / 重新启用本地口令”的入口（D14 双向可逆）
 *
 * 硬性要求：这里**不得**输出任何口令/token；表单动作一律要求 CSRF token，且只接受 POST。
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth;

use MediaWiki\Session\CsrfTokenSet;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Html\Html;
use MediaWiki\Linker\Linker;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\SpecialPage\SpecialPageFactory;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use Psr\Log\LoggerInterface;

class Hooks {

	/** 偏好页只保留"停用"（站长 2026-09-12 决定去掉"重新设置本地密码"） */
	public const ACTION_DISABLE = 'disable';

	/** 偏好页上那个表单的字段名 */
	public const FIELD_ACTION = 'ottohubauth-action';
	public const FIELD_TOKEN = 'ottohubauth-token';
	/** 偏好页动作的 token salt */
	public const TOKEN_SALT = 'ottohubauth-prefs';

	/** 登录页上两组字段的标记类（ext.ottohubLogin 靠它做标签页） */
	public const CLASS_LOCAL_FIELD = 'ottohubauth-local-field';
	public const CLASS_HUB_FIELD = 'ottohubauth-hub-field';

	/**
	 * 登录页要挂标签页的「本地」字段。
	 * ⚠️ 这里用的是 **AuthManager 字段描述符的键**（= AuthenticationRequest 的属性名），
	 * 不是 HTML 里的 input name（HTMLForm 会加 `wp` 前缀：username → wpName）。
	 * 2026-09-12 实测的键：username, password, rememberMe, loginattempt, linkcontainer,
	 * passwordReset, ottohubAccount, ottohubPassword。
	 */
	private const LOCAL_LOGIN_FIELDS = [ 'username', 'password' ];
	/** 「OTTOhub」字段（来自 OttohubLoginRequest 的属性名） */
	private const HUB_LOGIN_FIELDS = [ 'ottohubAccount', 'ottohubPassword' ];
	/** 「记住登录状态」两组共用，不放进任何一页 */
	private const SHARED_LOGIN_FIELDS = [ 'rememberMe' ];

	/** Special:UserLogin 的规范名（注意：注册名是 `Userlogin`，小写 l —— 踩过） */
	private const LOGIN_PAGE_NAME = 'UserLogin';

	private AuthManager $authManager;
	private UserFactory $userFactory;
	private OttohubAccountStore $store;
	private SpecialPageFactory $specialPageFactory;
	private OttohubProviderFactory $providerFactory;
	private LoggerInterface $logger;

	public function __construct(
		AuthManager $authManager,
		UserFactory $userFactory,
		OttohubAccountStore $store,
		SpecialPageFactory $specialPageFactory,
		OttohubProviderFactory $providerFactory
	) {
		$this->authManager = $authManager;
		$this->userFactory = $userFactory;
		$this->store = $store;
		$this->specialPageFactory = $specialPageFactory;
		$this->providerFactory = $providerFactory;
		$this->logger = LoggerFactory::getInstance( 'OttohubAuth' );
	}

	private function getProvider(): ?OttohubPrimaryAuthenticationProvider {
		return $this->providerFactory->get();
	}

	// ------------------------------------------------------------------
	// 注册页 / 密码重置 / 偏好（SpecialPageBeforeExecute）
	// ------------------------------------------------------------------

	/**
	 * @param SpecialPage $special
	 * @param string|null $subPage
	 * @return bool false = 阻止原页面执行
	 */
	public function onSpecialPageBeforeExecute( $special, $subPage ) {
		$name = $special->getName();

		if ( $name === 'CreateAccount' ) {
			$this->renderRegistrationInfo( $special );
			return false;
		}

		if ( $name === 'PasswordReset' ) {
			return !$this->interceptPasswordReset( $special );
		}

		if ( $name === 'Preferences' ) {
			// 处理“停用/启用本地口令”表单（POST + CSRF token）
			$this->handlePreferencesAction( $special );
			return true;
		}

		return true;
	}

	// ------------------------------------------------------------------
	// 登录页：字段分组标记 + 常见问题（站长 2026-09-12 要求）
	// ------------------------------------------------------------------

	/**
	 * 给登录表单的两组字段打上 CSS 类，供 ext.ottohubLogin 在前端做成标签页。
	 *
	 * 为什么用钩子而不是前端靠 input name 猜：字段名虽然稳定，但包装层（.mw-htmlform-field-*）
	 * 会随皮肤/主题变，标记在字段描述符上最可靠；标记缺失时前端会**优雅降级**（不生成标签页，
	 * 两组字段像以前一样并排显示）。
	 *
	 * @param array $requests
	 * @param array $fieldInfo
	 * @param array &$formDescriptor
	 * @param string $action
	 */
	public function onAuthChangeFormFields( $requests, $fieldInfo, &$formDescriptor, $action ) {
		if ( $action !== AuthManager::ACTION_LOGIN ) {
			return;
		}
		foreach ( self::LOCAL_LOGIN_FIELDS as $field ) {
			self::appendCssClass( $formDescriptor, $field, self::CLASS_LOCAL_FIELD );
		}
		foreach ( self::HUB_LOGIN_FIELDS as $field ) {
			self::appendCssClass( $formDescriptor, $field, self::CLASS_HUB_FIELD );
		}
		// 「记住我的登录状态」是核心的 RememberMeAuthenticationRequest，两组登录都适用，
		// 因此不放进任何一页，而是留在两页之外共用。
		foreach ( self::SHARED_LOGIN_FIELDS as $field ) {
			self::appendCssClass( $formDescriptor, $field, 'ottohubauth-shared-field' );
		}
	}

	private static function appendCssClass( array &$formDescriptor, string $field, string $class ): void {
		if ( !isset( $formDescriptor[$field] ) || !is_array( $formDescriptor[$field] ) ) {
			return;
		}
		$existing = trim( (string)( $formDescriptor[$field]['cssclass'] ?? '' ) );
		if ( $existing !== '' && str_contains( $existing, $class ) ) {
			return;
		}
		$formDescriptor[$field]['cssclass'] = trim( $existing . ' ' . $class );
	}

	/**
	 * 登录页后端补两件事：加载前端模块（标签页 + 样式），并在表单下面补上登录常见问题。
	 *
	 * 用 AfterExecute 是为了让 FAQ 落在表单**下面**（BeforeExecute 的 addHTML 会跑到表单上面）。
	 *
	 * @param SpecialPage $special
	 * @param string|null $subPage
	 * @return bool
	 */
	public function onSpecialPageAfterExecute( $special, $subPage ) {
		// ⚠️ 站点上这个特殊页的**规范名是 `Userlogin`（小写 l）**，写 'UserLogin' 永远匹配不上，
		// 钩子会静默什么都不做（2026-09-12 踩过）。这里大小写不敏感地比较。
		if ( !$special instanceof SpecialPage
			|| strcasecmp( $special->getName(), self::LOGIN_PAGE_NAME ) !== 0
		) {
			return true;
		}

		$out = $special->getOutput();
		$out->addModules( 'ext.ottohubLogin' );

		// 前端默认打开哪一页：?local=1 / ?ottohub=1 可显式指定（给站外/邮件里的深链用）；
		// 都不给时前端自己按「上次选择 → 默认 OTTOhub」决定。
		$request = $special->getRequest();
		$forcedTab = '';
		if ( $request->getInt( 'local' ) === 1 ) {
			$forcedTab = 'local';
		} elseif ( $request->getInt( 'ottohub' ) === 1 ) {
			$forcedTab = 'hub';
		}
		$out->addJsConfigVars( 'ottohubauthDefaultTab', $forcedTab );

		$out->addHTML( '<div class="ottohubauth-login-faq">' );
		$out->addWikiMsg( 'ottohubauth-login-faq' );
		$out->addHTML( '</div>' );

		return true;
	}

	private function renderRegistrationInfo( SpecialPage $special ): void {
		$out = $special->getOutput();
		$out->setPageTitleMsg( wfMessage( 'ottohubauth-register-title' ) );
		$out->addWikiMsg( 'ottohubauth-register-intro' );
		$out->addWikiMsg( 'ottohubauth-register-steps' );
		$out->addWikiMsg( 'ottohubauth-register-faq' );
		$out->addHTML( Html::rawElement(
			'p',
			[ 'class' => 'ottohubauth-register-cta' ],
			Linker::linkKnown(
				Title::newFromText( 'Special:UserLogin' ),
				wfMessage( 'ottohubauth-register-login-link' )->text()
			)
		) );
	}

	// ------------------------------------------------------------------
	// 密码重置封闭（D14）：按“当前有无本地口令”判定
	// ------------------------------------------------------------------

	private function interceptPasswordReset( SpecialPage $special ): bool {
		$request = $special->getRequest();
		if ( !$request->wasPosted() ) {
			return false;
		}
		$raw = $request->getVal( 'wpUsername', '' );
		if ( !is_string( $raw ) || trim( $raw ) === '' ) {
			return false;
		}
		$user = $this->userFactory->newFromName( trim( $raw ) );
		if ( $user === null || !$user->isRegistered() ) {
			// 与上游一致：不泄露账号是否存在，交给原流程处理
			return false;
		}
		$provider = $this->getProvider();
		if ( $provider === null || $provider->hasLocalPassword( $user->getId() ) ) {
			return false;
		}
		$special->getOutput()->setPageTitleMsg( wfMessage( 'ottohubauth-reset-blocked-title' ) );
		$special->getOutput()->addWikiMsg( 'ottohubauth-reset-blocked' );
		// 只记动作与站内 id（用户名在 wiki 上本就公开，但仍不必要地写进聚合日志）
		$this->logger->info( 'OttohubAuth: password reset blocked (account has no local password)', [
			'localUserId' => $user->getId(),
		] );
		return true;
	}

	// ------------------------------------------------------------------
	// Special:Preferences 入口（D14 双向可逆）
	// ------------------------------------------------------------------

	/**
	 * @param User $user
	 * @param array &$preferences
	 */
	public function onGetPreferences( $user, &$preferences ) {
		if ( !$user->isRegistered() ) {
			return;
		}
		$provider = $this->getProvider();
		if ( $provider === null ) {
			return;
		}

		$hasLocalPassword = $provider->hasLocalPassword( $user->getId() );
		$linked = $this->store->isLocalUserLinked( $user->getId() );

		if ( !$linked ) {
			// 未绑定：给首次自助绑定入口（§9.3-7）
			$preferences['ottohubauth-link-account'] = [
				'type' => 'info',
				'raw' => true,
				'default' => Linker::linkKnown(
					Title::newFromText( 'Special:LinkOttohub' ),
					wfMessage( 'ottohubauth-pref-link-cta' )->text()
				),
				'label-message' => 'ottohubauth-pref-link-label',
				'help-message' => 'ottohubauth-pref-link-help',
				'section' => 'personal/info',
			];
		}

		// 「停用本地密码」只在当前**有**本地口令时提供。
		// 站长 2026-09-12 决定：**去掉"重新设置本地密码"**（用户侧不再能自己设/恢复）。
		// 因此无本地口令时这里什么都不渲染；要恢复口令由管理员走 Special:OttohubAccounts。
		if ( $hasLocalPassword ) {
			$preferences['ottohubauth-local-password'] = [
				'type' => 'info',
				'raw' => true,
				'default' => $this->buildLocalPasswordForm( $user ),
				'label-message' => 'ottohubauth-pref-password-label',
				'section' => 'personal/info',
			];
		}

		// 阶段 3（通知推送）：只有已绑定 OTTOhub 的用户才提示怎么开推送；
		// 真正的开关是 Echo 自动生成的「OTTOhub 站内信」那一列（默认全不勾选）。
		if ( $linked ) {
			$preferences['ottohubauth-notify-hint'] = [
				'type' => 'info',
				'raw' => true,
				'default' => wfMessage( 'ottohubauth-pref-notifyhint' )->parse(),
				'label-message' => 'echo-pref-ottohub',
				'section' => 'echo/echosubscriptions',
			];
		}
	}

	private function buildLocalPasswordForm( User $user ): string {
		$request = $user->getRequest();
		// 1.46 起用 CsrfTokenSet（Session::getToken/matchToken 已移除）
		$token = ( new CsrfTokenSet( $request ) )->getToken()->toString();

		$fields = Html::hidden( self::FIELD_TOKEN, $token )
			. Html::hidden( self::FIELD_ACTION, self::ACTION_DISABLE )
			. Html::submitButton( wfMessage( 'ottohubauth-pref-password-disable-button' )->text() );

		return Html::rawElement(
			'div',
			[ 'class' => 'ottohubauth-pref-password' ],
			wfMessage( 'ottohubauth-pref-password-status-on' )->parse()
		)
			. Html::rawElement(
				'div',
				[ 'class' => 'htmlform-tip' ],
				wfMessage( 'ottohubauth-pref-password-disable-help' )->parse()
			)
			. Html::rawElement(
				'form',
				[
					'method' => 'post',
					'action' => $request->getRequestURL(),
					'class' => 'ottohubauth-pref-form',
				],
				$fields
			);
	}

	/**
	 * 处理偏好页里提交的「停用本地口令」。
	 *
	 * 只接受 POST + 合法 CSRF token；只处理 disable（"重新设置"已按站长决定移除）。
	 */
	private function handlePreferencesAction( SpecialPage $special ): void {
		$request = $special->getRequest();
		if ( !$request->wasPosted() ) {
			return;
		}
		$action = $request->getVal( self::FIELD_ACTION );
		if ( $action !== self::ACTION_DISABLE ) {
			return;
		}
		$user = $special->getUser();
		if ( !$user->isRegistered() ) {
			return;
		}
		$token = $request->getVal( self::FIELD_TOKEN );
		if ( !is_string( $token ) || !( new CsrfTokenSet( $request ) )->matchToken( $token ) ) {
			$special->getOutput()->addWikiMsg( 'ottohubauth-pref-password-bad-token' );
			return;
		}
		$provider = $this->getProvider();
		if ( $provider === null ) {
			return;
		}

		$provider->disableLocalPassword( $user );

		// 只记动作与站内 id，不含口令
		$this->logger->info( 'OttohubAuth: local password disable', [
			'localUserId' => $user->getId(),
		] );

		$special->getOutput()->addWikiMsg( 'ottohubauth-pref-password-disabled' );
	}
}
