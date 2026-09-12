<?php
/**
 * 重名认领交互（§4.3 ③、§9.3-6、D14）。
 *
 * 同一个界面里做三件事：
 *  1. “这是我的账号” —— 勾选后**必须**输入该站内账号的口令（用户名只是线索，口令才是证明）
 *  2. 不勾 → 按站内规则另分用户名建新号
 *  3. D14 的复选框：绑定后是否停用本地口令（**默认不勾**），并附收益/风险说明
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth;

use MediaWiki\Auth\AuthenticationRequest;

class OttohubClaimRequest extends AuthenticationRequest {

	/** @var bool 是否认领（勾选＝这是我的账号） */
	public $claimIt = false;

	/** @var string 该站内账号的本地口令（只在本次认证请求内存在；不落盘、不落日志） */
	public $localPassword = '';

	/** @var bool D14：绑定后是否停用 wiki 本地密码（默认 false＝这次不动） */
	public $disableLocalPassword = false;

	/** @inheritDoc */
	public function getFieldInfo() {
		return [
			'claimIt' => [
				'type' => 'checkbox',
				'label' => wfMessage( 'ottohubauth-claim-checkbox' ),
				'help' => wfMessage( 'ottohubauth-claim-checkbox-help' ),
				'optional' => true,
			],
			'localPassword' => [
				'type' => 'password',
				'label' => wfMessage( 'ottohubauth-claim-password' ),
				'help' => wfMessage( 'ottohubauth-claim-password-help' ),
				'optional' => true,
			],
			'disableLocalPassword' => [
				'type' => 'checkbox',
				'label' => wfMessage( 'ottohubauth-disable-local-password-label' ),
				// D14 硬性要求：不能只给一个光秃秃的复选框，必须说明收益与风险
				'help' => wfMessage( 'ottohubauth-disable-local-password-help' ),
				'optional' => true,
			],
			'ottohubClaimNotMine' => [
				'type' => 'null',
				'label' => wfMessage( 'ottohubauth-claim-not-mine-note' ),
			],
		];
	}

	/** @inheritDoc */
	public function describeCredentials() {
		return [
			'provider' => wfMessage( 'ottohubauth-provider-name' ),
			'account' => wfMessage( 'ottohubauth-credential-account', '' ),
		];
	}
}
