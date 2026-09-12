<?php
/**
 * ottohub_accounts 映射表的**唯一**访问入口。
 *
 * ⚠️ 两套 ID 体系（docs/ottohub-sso.md §4.2）：
 *   oa_hub_uid = OTTOhub 侧 uid      → PHP 变量/参数写 $hubUid
 *   oa_user_id = 站内 user.user_id   → PHP 变量/参数写 $localUserId
 * 两者都从 1 开始且值域完全重叠，传错**不报错、只抓错人**。
 * 因此本表的读写一律只经本类，禁止在其他文件里散落 SQL。
 *
 * 表内不存 token、不存口令。
 *
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\OttohubAuth;

use MediaWiki\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Wikimedia\Rdbms\DBQueryError;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;

class OttohubAccountStore {

	public const TABLE = 'ottohub_accounts';

	/** oa_linked_by 取值：0 = 登录时自动建号或用户自助认领绑定 */
	public const LINKED_BY_LOGIN = 0;

	private IConnectionProvider $dbProvider;
	private LoggerInterface $logger;

	public function __construct( IConnectionProvider $dbProvider ) {
		$this->dbProvider = $dbProvider;
		$this->logger = LoggerFactory::getInstance( 'OttohubAuth' );
	}

	private function db(): IDatabase {
		return $this->dbProvider->getPrimaryDatabase();
	}

	/**
	 * 按 OTTOhub 侧 uid 查映射。命中返回 ['oa_hub_uid'=>int,'oa_user_id'=>int,'oa_username'=>string,...]，否则 null。
	 */
	public function getByHubUid( int $hubUid ): ?array {
		if ( $hubUid <= 0 ) {
			return null;
		}
		$row = $this->db()->newSelectQueryBuilder()
			->select( [ 'oa_hub_uid', 'oa_user_id', 'oa_username', 'oa_linked_by', 'oa_linked_at' ] )
			->from( self::TABLE )
			->where( [ 'oa_hub_uid' => $hubUid ] )
			->caller( __METHOD__ )
			->fetchRow();
		return $row ? (array)$row : null;
	}

	/**
	 * 按站内 user_id 查映射。
	 */
	public function getByLocalUserId( int $localUserId ): ?array {
		if ( $localUserId <= 0 ) {
			return null;
		}
		$row = $this->db()->newSelectQueryBuilder()
			->select( [ 'oa_hub_uid', 'oa_user_id', 'oa_username', 'oa_linked_by', 'oa_linked_at' ] )
			->from( self::TABLE )
			->where( [ 'oa_user_id' => $localUserId ] )
			->caller( __METHOD__ )
			->fetchRow();
		return $row ? (array)$row : null;
	}

	/**
	 * 取站内账号绑定的 OTTOhub uid；未绑定返回 null。
	 *
	 * ⚠️ 唯一允许把站内 user_id 换成 hub_uid 的地方（通知推送等必须经此）。
	 */
	public function getHubUidByLocalUser( int $localUserId ): ?int {
		$row = $this->getByLocalUserId( $localUserId );
		return $row ? (int)$row['oa_hub_uid'] : null;
	}

	/**
	 * 站内账号是否已绑定。
	 */
	public function isLocalUserLinked( int $localUserId ): bool {
		return $this->getByLocalUserId( $localUserId ) !== null;
	}

	/**
	 * 该 OTTOhub uid 是否已被**其他**站内账号绑定（$localUserId 为当前账号时可用来检测“被抢绑”）。
	 */
	public function isHubUidBoundToOther( int $hubUid, int $localUserId ): bool {
		$row = $this->getByHubUid( $hubUid );
		return $row !== null && (int)$row['oa_user_id'] !== $localUserId;
	}

	/**
	 * 建立绑定。
	 *
	 * 唯一键冲突（并发）不抛给上层：重查一次，
	 *  - 若已绑到同一个站内账号 → 视为成功（幂等）
	 *  - 否则返回失败（对应 docs/ottohub-sso.md §9.3-9）
	 *
	 * @param int $hubUid OTTOhub 侧 uid
	 * @param int $localUserId 站内 user_id
	 * @param string $hubUsername 登录时 /api/profile 返回的 username 原样记录
	 * @param int $linkedBy 0 = 登录/自助绑定；>0 = 操作的管理员站内 user_id
	 * @return bool 是否（最终）建立成功
	 */
	public function link( int $hubUid, int $localUserId, string $hubUsername, int $linkedBy = self::LINKED_BY_LOGIN ): bool {
		if ( $hubUid <= 0 || $localUserId <= 0 ) {
			return false;
		}

		try {
			$this->db()->newInsertQueryBuilder()
				->insertInto( self::TABLE )
				->row( [
					'oa_hub_uid' => $hubUid,
					'oa_user_id' => $localUserId,
					'oa_username' => $hubUsername,
					'oa_linked_by' => $linkedBy,
					'oa_linked_at' => $this->db()->timestamp(),
				] )
				->caller( __METHOD__ )
				->execute();
			return true;
		} catch ( DBQueryError $e ) {
			$existing = $this->getByHubUid( $hubUid );
			if ( $existing !== null && (int)$existing['oa_user_id'] === $localUserId ) {
				// 并发下已被同一账号绑上 → 幂等成功
				return true;
			}
			// 不记录任何凭证；只记两个 id 与冲突事实
			$this->logger->debug( 'OttohubAuth: link conflict', [
				'hubUid' => $hubUid,
				'localUserId' => $localUserId,
			] );
			return false;
		}
	}

	/**
	 * 更新记录里的 hub 用户名（改名跟随用；失败忽略）。
	 */
	public function updateHubUsername( int $hubUid, string $hubUsername ): void {
		if ( $hubUid <= 0 ) {
			return;
		}
		$this->db()->newUpdateQueryBuilder()
			->update( self::TABLE )
			->set( [ 'oa_username' => $hubUsername ] )
			->where( [ 'oa_hub_uid' => $hubUid ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * 解绑。管理员动作，必须已在上层做过权限检查并写日志。
	 */
	public function unlink( int $hubUid ): bool {
		if ( $hubUid <= 0 ) {
			return false;
		}
		$this->db()->newDeleteQueryBuilder()
			->deleteFrom( self::TABLE )
			->where( [ 'oa_hub_uid' => $hubUid ] )
			->caller( __METHOD__ )
			->execute();
		return true;
	}

	/**
	 * 重绑：把某个 OTTOhub uid 绑到站内账号，先清掉该站内账号原有绑定。
	 *
	 * @return bool 是否成功
	 */
	public function relink( int $hubUid, int $localUserId, string $hubUsername, int $linkedBy ): bool {
		$current = $this->getByLocalUserId( $localUserId );
		if ( $current !== null ) {
			$this->unlink( (int)$current['oa_hub_uid'] );
		}
		return $this->link( $hubUid, $localUserId, $hubUsername, $linkedBy );
	}
}
