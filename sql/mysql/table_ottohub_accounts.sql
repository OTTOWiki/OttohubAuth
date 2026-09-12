-- OTTOhub ↔ OTTOWiki 账号映射表（阶段 1，docs/ottohub-sso.md §4.2）
--
-- ⚠️ 两个 ID 是**独立的值域**，且都从 1 开始、完全重叠：
--    oa_hub_uid = OTTOhub 侧 uid
--    oa_user_id = 站内 user.user_id
-- 列名强制带前缀，禁止裸写 oa_uid。
--
-- 本表不存 token、不存口令。

CREATE TABLE /*_*/ottohub_accounts (
  oa_hub_uid    INT UNSIGNED   NOT NULL,   -- OTTOhub 侧 uid（不是站内 user_id！）
  oa_user_id    INT UNSIGNED   NOT NULL,   -- 站内 user.user_id
  oa_username   VARBINARY(255) NOT NULL,   -- 登录时 /api/profile 返回的 username 原样记录；用于展示/排障与发现改名
  oa_linked_by  INT UNSIGNED   NOT NULL DEFAULT 0,  -- 0 = 登录时自动建号或用户自助绑定；>0 = 操作的管理员 user_id（D11）
  oa_linked_at  BINARY(14)     NOT NULL,
  PRIMARY KEY (oa_hub_uid),
  UNIQUE KEY oa_user_id (oa_user_id)
) /*$wgDBTableOptions*/;
