<?php
/**
 * 只读探针：确认扩展在 MediaWiki 里真的被注册、服务能装配、认证 provider 能实例化。
 *
 * 用法（服务器上，站点根目录）：
 *   php maintenance/run.php OttohubAuth:probe
 *
 * 本脚本**不做任何写操作**，不创建表、不改配置。
 * 输出一律用 ASCII 标签，避免终端对全角标点的截断误导。
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth\Maintenance;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Auth\AuthenticationRequest;
use MediaWiki\Extension\OttohubAuth\OttohubAccountStore;
use MediaWiki\Extension\OttohubAuth\OttohubClient;
use MediaWiki\Extension\OttohubAuth\OttohubLoginRequest;
use MediaWiki\Extension\OttohubAuth\OttohubPrimaryAuthenticationProvider;
use MediaWiki\Extension\OttohubAuth\OttohubResponse;
use MediaWiki\Maintenance\Maintenance;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

class Probe extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'OttohubAuth read-only probe' );
		$this->requireExtension( 'OttohubAuth' );
	}

	public function execute() {
		$services = $this->getServiceContainer();

		$this->ok( 'extension class autoload: ' . ( class_exists( OttohubClient::class ) ? 'yes' : 'NO' ) );

		// 1) 服务装配
		$client = $services->getService( 'OttohubAuth.Client' );
		$store = $services->getService( 'OttohubAuth.AccountStore' );
		$throttle = $services->getService( 'OttohubAuth.Throttle' );
		$this->ok( 'service OttohubAuth.Client: ' . get_class( $client ) );
		$this->ok( 'service OttohubAuth.AccountStore: ' . get_class( $store ) );
		$this->ok( 'service OttohubAuth.Throttle: ' . get_class( $throttle )
			. ' limit=' . $throttle->getLimit() );

		// 2) 配置项
		$config = $services->getMainConfig();
		$this->ok( 'config ApiBase: ' . $config->get( 'OttohubAuth_ApiBase' ) );
		$this->ok( 'config Timeout: ' . (int)$config->get( 'OttohubAuth_Timeout' ) );

		// 3) 认证 provider（走 AuthManager 实例化，验证 services 注入正确）
		$authManager = $services->getAuthManager();
		$provider = $authManager->getAuthenticationProvider( OttohubPrimaryAuthenticationProvider::class );
		if ( $provider instanceof OttohubPrimaryAuthenticationProvider ) {
			$this->ok( 'auth provider instantiated: ' . get_class( $provider ) );
			$this->ok( 'accountCreationType: ' . $provider->accountCreationType() );
		} else {
			$this->err( 'auth provider NOT registered (check AuthManagerAutoConfig)' );
		}

		// 4) 匿名用户的 autocreateaccount 权限（阶段 1 建号的前提）
		$anon = $services->getUserFactory()->newAnonymous( '127.0.0.1' );
		$permissions = $services->getPermissionManager();
		$this->ok( 'anon right autocreateaccount: '
			. ( $permissions->userHasRight( $anon, 'autocreateaccount' ) ? 'TRUE' : 'false' ) );
		$this->ok( 'anon right createaccount: '
			. ( $permissions->userHasRight( $anon, 'createaccount' ) ? 'TRUE' : 'false' )
			. ' (expect false: local registration stays closed)' );

		// 5) OttohubClient 的解析是纯函数：离线样例验证两种成功形状与错误码映射（不出网）
		$cases = [
			// profile / getUser：data 包装
			[ '{"status":"success","data":{"uid":"577","username":"alice"}}', 200, true, '' ],
			// login：平坦结构（2026-09-12 实测的真实形状）
			[ '{"status":"success","uid":"577","token":"zz9","email":"a@b.c"}', 200, true, '' ],
			// 发站内信：**只有 status**（2026-09-12 实测的真实形状）
			[ '{"status":"success"}', 200, true, '' ],
			[ '{"status":"error","message":"error_password"}', 401, false, 'error_password' ],
			[ '{"status":"error","message":"error_token"}', 401, false, 'error_token' ],
			[ '{"status":"error","message":"too_many_requests"}', 429, false, 'too_many_requests' ],
			[ '{"status":"error","message":"missing_argument"}', 400, false, 'missing_argument' ],
			[ 'not json at all', 200, false, OttohubResponse::ERR_BAD_RESPONSE ],
		];
		$fails = 0;
		foreach ( $cases as $i => $case ) {
			[ $body, $status, $wantOk, $wantCode ] = $case;
			$res = $client->parse( $body, $status );
			if ( $res->isOk() !== $wantOk || $res->getErrorCode() !== $wantCode ) {
				$fails++;
				$this->err( "parse case $i FAILED: ok=" . var_export( $res->isOk(), true )
					. ' code=' . $res->getErrorCode() );
			}
		}
		// 平坦结构里的字段要真的能取出来
		$flat = $client->parse( '{"status":"success","uid":"577","token":"zz9"}', 200 );
		if ( $flat->getString( 'uid' ) !== '577' || $flat->getString( 'token' ) !== 'zz9' ) {
			$fails++;
			$this->err( 'flat success shape: fields not readable' );
		}
		$wrapped = $client->parse( '{"status":"success","data":{"uid":"577"}}', 200 );
		if ( $wrapped->getString( 'uid' ) !== '577' ) {
			$fails++;
			$this->err( 'wrapped success shape: fields not readable' );
		}
		$this->ok( 'client parse cases: ' . ( count( $cases ) - $fails ) . '/' . count( $cases )
			. ' passed (+2 field-readability checks)' );

		// 6) 映射表是否存在 + 当前规模（只读）
		$dbr = $services->getConnectionProvider()->getReplicaDatabase();
		if ( $dbr->tableExists( OttohubAccountStore::TABLE ) ) {
			$count = $dbr->newSelectQueryBuilder()
				->select( 'COUNT(*)' )
				->from( OttohubAccountStore::TABLE )
				->caller( __METHOD__ )
				->fetchField();
			$this->ok( 'table ' . OttohubAccountStore::TABLE . ' exists, rows=' . (int)$count );
		} else {
			$this->err( 'table ' . OttohubAccountStore::TABLE . ' does NOT exist' );
		}

		// 6b) 登录页共用口令框（离线不变量检查）
		//
		// 站长 2026-09-12 要求：OTTOhub 口令与站内口令**共用同一个输入框**。
		// 实现方式是让 OttohubLoginRequest 的口令字段也叫 `password`（与核心
		// PasswordAuthenticationRequest 同名）：字段合并后表单只渲染一个输入框，同一个值被分别
		// 填进两个请求；两条路径靠 loadFromSubmission() 的"字段缺失就丢弃该请求"互不干扰。
		//
		// ⚠️ 这里刻意用 **AuthManager 真正产出的那组请求**（而不是自己 new），否则会漏掉
		//    provider 对请求对象做的初始化（例如核心请求的 action 必须是 ACTION_LOGIN，
		//    否则 getFieldInfo() 会多出一个 retype 字段，导致 loadFromSubmission() 直接返回 false）。
		$loginRequests = $services->getAuthManager()
			->getAuthenticationRequests( AuthManager::ACTION_LOGIN, null );
		$coreReq = AuthenticationRequest::getRequestByClass(
			$loginRequests, \MediaWiki\Auth\PasswordAuthenticationRequest::class );
		$hubReq = AuthenticationRequest::getRequestByClass( $loginRequests, OttohubLoginRequest::class );
		$this->ok( 'login form carries both a core password request and the hub request: '
			. ( $coreReq && $hubReq ? 'yes' : 'NO' ) );

		$hubInfo = ( new OttohubLoginRequest() )->getFieldInfo();
		$this->ok( 'hub login request uses the shared `password` field: '
			. ( isset( $hubInfo['password'] ) && !isset( $hubInfo['ottohubPassword'] )
				? 'yes' : 'NO (fields: ' . implode( ',', array_keys( $hubInfo ) ) . ')' ) );

		$hubOnly = AuthenticationRequest::loadRequestsFromSubmission(
			[ clone $coreReq, clone $hubReq ],
			[ 'ottohubAccount' => 'someone', 'password' => 'shared-secret' ]
		);
		$this->ok( 'ottohub-only submission loads ONLY the hub request, with the shared password: '
			. ( count( $hubOnly ) === 1 && $hubOnly[0] instanceof OttohubLoginRequest
				&& $hubOnly[0]->password === 'shared-secret' ? 'yes' : 'NO' ) );

		$coreOnly = AuthenticationRequest::loadRequestsFromSubmission(
			[ clone $coreReq, clone $hubReq ],
			[ 'username' => 'SomeUser', 'password' => 'shared-secret' ]
		);
		$this->ok( 'local-only submission loads ONLY the core request (local login unaffected): '
			. ( count( $coreOnly ) === 1
				&& $coreOnly[0] instanceof \MediaWiki\Auth\PasswordAuthenticationRequest
				&& $coreOnly[0]->password === 'shared-secret' ? 'yes' : 'NO' ) );

		// 7) 日志自检：确认没有把调试日志开到文件里
		$this->ok( 'wgDebugLogFile set: '
			. ( $config->get( 'DebugLogFile' ) !== '' ? 'YES (check!)' : 'no' ) );
		$this->ok( 'wgDebugLogGroups count: ' . count( $config->get( 'DebugLogGroups' ) ) );

		// 8) 两个特殊页能否实例化（走 ObjectFactory 的 DI 装配；有问题在这里就暴露）
		$specialFactory = $services->getSpecialPageFactory();
		foreach ( [ 'LinkOttohub', 'OttohubAccounts' ] as $name ) {
			if ( !$specialFactory->exists( $name ) ) {
				$this->err( "special page $name NOT registered" );
				continue;
			}
			try {
				$page = $specialFactory->getPage( $name );
				$this->ok( "special page $name instantiated: " . get_class( $page ) );
			} catch ( \Throwable $e ) {
				$this->err( "special page $name FAILED: " . get_class( $e ) . ': ' . $e->getMessage() );
			}
		}

		// 9) 权限项是否注册
		$allRights = $services->getPermissionManager()->getAllPermissions();
		$this->ok( 'right ottohubauth-manage registered: '
			. ( in_array( 'ottohubauth-manage', $allRights, true ) ? 'YES' : 'NO' ) );

		// 10) 节流计数器（写的是独立测试键，测完立刻 clear，不影响真实账号）
		$t = $throttle;
		$probeIp = '127.0.0.1';
		$probeAcct = 'probe_acct_direct';
		$t->clear( $probeIp, $probeAcct );
		$firstBlocked = null;
		for ( $i = 1; $i <= 7; $i++ ) {
			$t->bump( $probeIp, $probeAcct );
			if ( $t->isBlocked( $probeIp, $probeAcct ) && $firstBlocked === null ) {
				$firstBlocked = $i;
			}
		}
		$t->clear( $probeIp, $probeAcct );
		$cleared = !$t->isBlocked( $probeIp, $probeAcct );
		$this->ok( "throttle: limit=" . $t->getLimit() . ", first blocked at attempt "
			. ( $firstBlocked ?? '?' ) . ", clear works=" . ( $cleared ? 'yes' : 'NO' ) );

		$this->ok( 'probe finished (read-only, nothing modified)' );
	}

	private function ok( string $msg ): void {
		$this->output( "  [OK] $msg\n" );
	}

	private function err( string $msg ): void {
		$this->output( "  [!!] $msg\n" );
	}
}

// @codeCoverageIgnoreStart
$maintClass = Probe::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
