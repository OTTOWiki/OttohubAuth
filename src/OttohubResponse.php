<?php
/**
 * 一次 OTTOhub API 调用的结果。
 *
 * 上游两种响应形状都要支持（2026-09-12 实测）：
 *   A) {"status":"success","data":{...}}                ← /api/user/{hubUid}
 *   B) {"status":"success","uid":"1","token":"…",...}   ← /api/auth/login（**平坦结构**）
 *
 * 纯数据对象，便于单元测试（解析逻辑不依赖 MediaWiki）。
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth;

class OttohubResponse {

	/** 网络/超时/JSON 解析失败 */
	public const ERR_TRANSPORT = 'transport_error';

	/** 上游返回了非 JSON 或无法识别的结构 */
	public const ERR_BAD_RESPONSE = 'bad_response';

	/** 上游返回缺少必要字段 */
	public const ERR_MISSING_FIELD = 'missing_field';

	/** profile 与 login 返回的 uid 不一致（按异常拒绝） */
	public const ERR_UID_MISMATCH = 'uid_mismatch';

	/** @var bool 是否成功取得业务数据 */
	private bool $ok;

	/** @var string 上游 status 为 error 时的 message（如 error_password / error_token / too_many_requests） */
	private string $code;

	/** @var array 成功时的业务字段（已抹平 data 包装） */
	private array $data;

	/** @var int HTTP 状态码，0 表示网络层失败 */
	private int $httpStatus;

	/** @var string 传输层错误摘要（不含任何凭证） */
	private string $transportError;

	private function __construct( bool $ok, string $code, array $data, int $httpStatus, string $transportError ) {
		$this->ok = $ok;
		$this->code = $code;
		$this->data = $data;
		$this->httpStatus = $httpStatus;
		$this->transportError = $transportError;
	}

	public static function success( array $data, int $httpStatus ): self {
		return new self( true, '', $data, $httpStatus, '' );
	}

	public static function error( string $code, int $httpStatus = 0 ): self {
		return new self( false, $code, [], $httpStatus, '' );
	}

	public static function transport( string $summary ): self {
		return new self( false, self::ERR_TRANSPORT, [], 0, $summary );
	}

	public function isOk(): bool {
		return $this->ok;
	}

	public function getErrorCode(): string {
		return $this->code;
	}

	public function getData(): array {
		return $this->data;
	}

	public function getHttpStatus(): int {
		return $this->httpStatus;
	}

	public function getTransportError(): string {
		return $this->transportError;
	}

	/**
	 * 取业务字段的字符串值；不存在返回 null。
	 * 数字（含上游以字符串形式返回的数字，如 uid）统一转成字符串。
	 */
	public function getString( string $key ): ?string {
		$value = $this->data[$key] ?? null;
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string)$value;
		}
		return null;
	}
}
