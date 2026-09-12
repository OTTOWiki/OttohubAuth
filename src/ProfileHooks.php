<?php
/**
 * 阶段 2：在 SocialProfile 的 profile 页右栏、站内用户信息下方渲染独立的「OTTOhub」区块。
 *
 * 依据 docs/ottohub-sso.md §5（D9 / D10）：
 *  - 挂 UserProfileRightSideAfterActivity；回调**必须返回 true**（否则 SocialProfile 记 warning）
 *  - 服务端**只存/只输出 hub_uid**（属性名 data-ottohub-uid），用户名/头像/简介由浏览器直接调
 *    https://api.ottohub.cn/api/user/{hubUid} 获取 → 零存储、改名换头像自动跟随
 *  - 未绑定 OTTOhub 的账号：不输出容器、不加载模块
 *  - 可见性（站长 2026-09-12 选 (ii)）：默认公开，用户可在 Special:Preferences 里隐藏
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth;

use MediaWiki\Html\Html;
use MediaWiki\User\Options\UserOptionsManager;
use MediaWiki\User\UserFactory;

class ProfileHooks {

	/** 用户在偏好里勾选后，profile 页不再显示 OTTOhub 区块 */
	public const PREF_HIDE = 'ottohubauth-hide-profile';

	/** 区块容器的 id / 模块名 */
	public const CONTAINER_ID = 'ottohubauth-profile-block';
	public const MODULE = 'ext.ottohubProfile';

	private OttohubAccountStore $store;
	private UserFactory $userFactory;
	private UserOptionsManager $userOptionsManager;

	public function __construct(
		OttohubAccountStore $store,
		UserFactory $userFactory,
		UserOptionsManager $userOptionsManager
	) {
		$this->store = $store;
		$this->userFactory = $userFactory;
		$this->userOptionsManager = $userOptionsManager;
	}

	/**
	 * @param \MediaWiki\Extension\SocialProfile\UserProfilePage $userProfilePage
	 * @return bool 必须返回 true
	 */
	public function onUserProfileRightSideAfterActivity( $userProfilePage ) {
		$out = $userProfilePage->getContext()->getOutput();

		// 站内账号：从被展示的用户页标题取用户名（User:XXX）
		$title = $userProfilePage->getTitle();
		if ( $title === null ) {
			return true;
		}
		$localUser = $this->userFactory->newFromName( $title->getText() );
		if ( $localUser === null || !$localUser->isRegistered() ) {
			return true;
		}

		$localUserId = $localUser->getId();
		$hubUid = $this->store->getHubUidByLocalUser( $localUserId );
		if ( $hubUid === null ) {
			// 未绑定 OTTOhub：既不输出容器，也不加载模块
			return true;
		}

		// 可见性 (ii)：默认公开；本人可以在偏好里隐藏
		$viewer = $userProfilePage->getContext()->getUser();
		$isSelf = $viewer->isRegistered() && $viewer->getId() === $localUserId;
		// 1.46 已移除 User::getOption()，必须经 UserOptionsManager
		if ( !$isSelf
			&& $this->userOptionsManager->getOption( $localUser, self::PREF_HIDE ) ) {
			return true;
		}

		// ⚠️ 只输出 hub uid（OTTOhub 侧），属性名带 ottohub- 前缀防与站内 user_id 混淆
		$out->addHTML( Html::rawElement(
			'div',
			[
				'id' => self::CONTAINER_ID,
				'class' => 'ottohubauth-profile-block',
				'data-ottohub-uid' => (string)$hubUid,
			],
			''
		) );
		$out->addModules( self::MODULE );

		return true;
	}

	/**
	 * Special:Preferences：给已绑定用户一个「在个人资料页隐藏 OTTOhub 区块」的开关。
	 * 未绑定用户不显示（没有区块可隐藏）。
	 *
	 * @param \MediaWiki\User\User $user
	 * @param array &$preferences
	 */
	public function onGetPreferences( $user, &$preferences ) {
		if ( !$user->isRegistered() || !$this->store->isLocalUserLinked( $user->getId() ) ) {
			return;
		}
		$preferences[self::PREF_HIDE] = [
			'type' => 'toggle',
			'label-message' => 'ottohubauth-pref-hideprofile-label',
			'help-message' => 'ottohubauth-pref-hideprofile-help',
			'section' => 'personal/info',
		];
	}
}
