<?php
/**
 * OTTOhub API 的唯一出网入口。
 *
 * 端点与**实测契约**（2026-09-12 用真实账号实测；契约与设计文档 §3.1 有差异，见下）：
 *   POST /api/auth/login    JSON body {"uid_email": <用户名或邮箱>, "pw": <口令>}
 *                           → 平坦结构 {status, uid, token, email?, avatar_url…}
 *   GET  /api/profile?token=<token>
 *                           → {status, data:{uid, username, email?, …}}  ★ username 的唯一来源
 *   GET  /api/user/{hubUid}  → {status, data:{uid, username, …}}（阶段 2 前端直接调用）
 *   POST /api/im/messages    JSON body {"token": <token>, "receiver": <hubUid>, "message": <文本>}
 *                           → {status:"success"}（**只有 status，没有任何 data**）
 *
 * ⚠️ 与规格的差异（必须记住）：
 *   1. login 的参数名是 **uid_email / pw**，且必须是 **JSON**；
 *      旧文档写的 account/password + form-urlencoded 现在一律返回
 *      `missing_argument`（HTTP 400），会让登录**完全不可用**。
 *   2. login 成功时业务字段在**顶层**（没有 data 包装），profile 才有 data 包装。
 *   3. profile 的 token **只接受 query 参数** `?token=`；放在 `token:` 请求头或
 *      `Authorization: Bearer` 一律 401 `error_token`。
 *
 * 安全约定（docs/ottohub-sso.md §4.6）：
 *  - 本类**不写任何日志**；异常信息里不含口令/token/URL。
 *  - 口令只在 POST body 里，绝不进 URL。
 *  - ⚠️ **GET 的 token 只能放 query**（上游限制）→ 必须配合 nginx 访问日志对该参数打码
 *    （见 extensions-src/README.md「token 会落进 nginx 访问日志」一节）。
 *  - ✅ **POST（含发站内信）的 token 一律放 JSON body**，既合上游要求，又不落访问日志。
 *
 * ⚠️ 两套 ID：本类所有涉及 OTTOhub 侧 uid 的参数一律命名 $hubUid；禁止裸用 $uid。
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth;

class OttohubClient {

	private string $apiBase;
	private int $timeout;

	public function __construct( string $apiBase, int $timeout = 8 ) {
		$this->apiBase = rtrim( $apiBase, '/' );
		$this->timeout = max( 1, $timeout );
	}

	/**
	 * 用 OTTOhub 账号（用户名或邮箱）+ 口令换 token。
	 *
	 * 上游不区分“账号不存在”与“口令错误”，统一返回 error_password（401）。
	 */
	public function login( string $account, string $password ): OttohubResponse {
		return $this->request( 'POST', '/api/auth/login', [
			'uid_email' => $account,
			'pw' => $password,
		] );
	}

	/**
	 * 用 token 取本人的 OTTOhub 身份。
	 *
	 * ★ 绑定用的 hub uid 与用户名一律以本方法的返回值为准（token 才是凭证）。
	 */
	public function profile( string $token ): OttohubResponse {
		return $this->request( 'GET', '/api/profile', [], $token );
	}

	/**
	 * 公开取某个 OTTOhub 用户的展示信息（阶段 2 前端直接用浏览器调，服务端仅作只读探针用途）。
	 *
	 * @param int $hubUid OTTOhub 侧 uid（**不是**站内 user_id）
	 */
	public function getUser( int $hubUid ): OttohubResponse {
		return $this->request( 'GET', '/api/user/' . $hubUid );
	}

	/**
	 * 给某个 OTTOhub 用户发站内信（阶段 3 的通知通道）。
	 *
	 * @param string $token 发件账号的 token（**放 JSON body**，不进 URL）
	 * @param int $hubUid 收件人的 **OTTOhub 侧 uid**（不是站内 user_id）
	 * @param string $message 纯文本，上游上限 **222 个字符**（超出 → too_long_message）
	 */
	public function sendIm( string $token, int $hubUid, string $message ): OttohubResponse {
		return $this->request( 'POST', '/api/im/messages', [
			'receiver' => $hubUid,
			'message' => $message,
			'token' => $token,
		], null );
	}

	/**
	 * @param string $method GET|POST
	 * @param string $path 以 / 开头的 API 路径（不含任何凭证）
	 * @param array $fields POST JSON 字段
	 * @param string|null $token 需要认证时传；上游只认 query 参数
	 */
	private function request( string $method, string $path, array $fields = [], ?string $token = null ): OttohubResponse {
		if ( !function_exists( 'curl_init' ) ) {
			return OttohubResponse::transport( 'curl_missing' );
		}

		$url = $this->apiBase . $path;
		if ( $token !== null && $token !== '' ) {
			$url .= ( str_contains( $url, '?' ) ? '&' : '?' ) . 'token=' . rawurlencode( $token );
		}

		$headers = [ 'Accept: application/json' ];

		$ch = curl_init( $url );
		if ( $ch === false ) {
			return OttohubResponse::transport( 'curl_init_failed' );
		}

		$options = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => $this->timeout,
			CURLOPT_TIMEOUT => $this->timeout,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_USERAGENT => 'OTTOWiki-OttohubAuth/0.1 (+https://wiki.ottohub.cn)',
		];

		if ( $method === 'POST' ) {
			$encoded = json_encode( $fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			$options[CURLOPT_POST] = true;
			$options[CURLOPT_POSTFIELDS] = $encoded === false ? '{}' : $encoded;
			$options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
		}

		curl_setopt_array( $ch, $options );
		$body = curl_exec( $ch );
		$errno = curl_errno( $ch );
		$httpStatus = (int)curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		curl_close( $ch );

		if ( $errno !== 0 || !is_string( $body ) ) {
			// 只上报 curl 错误码，不回显任何请求内容
			return OttohubResponse::transport( 'curl_errno_' . $errno );
		}

		return $this->parse( $body, $httpStatus );
	}

	/**
	 * 解析上游响应。纯函数，便于单元测试。
	 *
	 * 兼容三种成功形状：
	 *   {"status":"success","data":{…}}          → 用 data
	 *   {"status":"success","uid":…,"token":…}   → 用顶层字段（login 就是这种）
	 *   {"status":"success"}                     → 空 data（**发站内信就是这种**）
	 *
	 * ⚠️ 第三种没有业务字段，**调用方必须自己校验必需字段**（login 已校验 token/uid）。
	 */
	public function parse( string $body, int $httpStatus ): OttohubResponse {
		$decoded = json_decode( $body, true );
		if ( !is_array( $decoded ) ) {
			return OttohubResponse::error( OttohubResponse::ERR_BAD_RESPONSE, $httpStatus );
		}

		$status = $decoded['status'] ?? null;

		if ( $status === 'success' ) {
			$data = $decoded['data'] ?? null;
			if ( is_array( $data ) ) {
				return OttohubResponse::success( $data, $httpStatus );
			}
			unset( $decoded['status'] );
			// 允许"只有 status"的成功（发站内信）；必需字段由调用方校验
			return OttohubResponse::success( $decoded, $httpStatus );
		}

		if ( $status === 'error' ) {
			$message = $decoded['message'] ?? null;
			if ( !is_string( $message ) || $message === '' ) {
				$message = OttohubResponse::ERR_BAD_RESPONSE;
			}
			return OttohubResponse::error( $message, $httpStatus );
		}

		return OttohubResponse::error( OttohubResponse::ERR_BAD_RESPONSE, $httpStatus );
	}
}
