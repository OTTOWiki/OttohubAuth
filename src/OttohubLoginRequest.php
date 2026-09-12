<?php
/**
 * 登录页上的 OTTOhub 字段（登录页会与本地账号表单并列显示；D6 保留本地登录）。
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth;

use MediaWiki\Auth\AuthenticationRequest;

class OttohubLoginRequest extends AuthenticationRequest {

	/** @var string OTTOhub 账号（用户名或邮箱） */
	public $ottohubAccount = '';

	/** @var string OTTOhub 口令（只在本次请求内存里存在；不落盘、不落日志） */
	public $ottohubPassword = '';

	/** @inheritDoc */
	public function getFieldInfo() {
		return [
			'ottohubAccount' => [
				'type' => 'string',
				'label' => wfMessage( 'ottohubauth-field-account' ),
				'placeholder' => wfMessage( 'ottohubauth-field-account-placeholder' ),
				'help' => wfMessage( 'ottohubauth-field-account-help' ),
				'optional' => false,
			],
			'ottohubPassword' => [
				'type' => 'password',
				'label' => wfMessage( 'ottohubauth-field-password' ),
				'help' => wfMessage( 'ottohubauth-field-password-help' ),
				'optional' => false,
			],
		];
	}

	/** @inheritDoc */
	public function describeCredentials() {
		return [
			'provider' => wfMessage( 'ottohubauth-provider-name' ),
			'account' => wfMessage( 'ottohubauth-credential-account', $this->ottohubAccount ),
		];
	}
}
