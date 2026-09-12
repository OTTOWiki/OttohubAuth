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

	/**
	 * @var string 口令（只在本次请求内存里存在；不落盘、不落日志）
	 *
	 * ⚠️ 字段名**故意**与核心 `PasswordAuthenticationRequest::$password` 相同（都叫 `password`）：
	 * `AuthManagerSpecialPage::fieldInfoToFormDescriptor()` 会把所有请求的字段合并成一张表单，
	 * 同名字段只会渲染出**一个**输入框（HTML 里是 `wpPassword`），而
	 * `AuthenticationRequest::loadRequestsFromSubmission()` 会把同一个值分别填进两个请求。
	 * 于是「站内账号」与「OTTOhub 账号」两个标签页**共用同一个密码框** —— 站长 2026-09-12 的要求。
	 *
	 * 两条路径互不干扰靠的是 `loadFromSubmission()`：本地登录时 `ottohubAccount` 为空 → 本请求被丢弃、
	 * provider ABSTAIN；OTTOhub 登录时 `username` 为空 → 核心请求被丢弃。
	 */
	public $password = '';

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
			// 与核心共用：标签用中性的「密码」，前端再按当前标签页改成「OTTOhub 口令」
			'password' => [
				'type' => 'password',
				'label' => wfMessage( 'ottohubauth-field-shared-password' ),
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
