# OttohubAuth

MediaWiki extension that lets people sign in to a wiki with their **OTTOhub** account,
shows their OTTOhub identity on their profile page, and can push wiki notifications to
their OTTOhub direct messages.

> 给 OTTOWiki（`wiki.ottohub.cn`）写的扩展：用 OTTOhub 账号登录、在个人资料页展示 OTTOhub 身份、
> 把站内通知推成 OTTOhub 私信。代码里不包含任何凭据；发件账号的凭据放在站点根**之外**的 600 文件里。

## 功能

| 阶段 | 内容 |
|---|---|
| 1 · 统一登录 | 用 OTTOhub 用户名/邮箱 + 口令登录。首次登录**自动建号**；若 OTTOhub 用户名与站内已有账号同名，走**认领**流程（要求输入该站内账号的原口令，防止把别人的账号错认给他人）。保留本地口令登录（老用户不受影响），可在绑定后选择停用本地口令。 |
| 2 · 个人资料联动 | 在用户页右栏显示 OTTOhub 头像/用户名/UID/简介。**服务端只存 hub uid**，展示数据由浏览器直接调 `api.ottohub.cn`（改名/换头像自动跟随，零存储）；用户可在偏好里隐藏该区块。 |
| 3 · 通知推送 | 通过 Echo 增加一个 `ottohub` 投递通道，把站内通知推成 OTTOhub 私信（`POST /api/im/messages`）。**默认对所有用户关闭**，需用户自己在「通知」里逐类别勾选。 | 
| 3+ · 审核结果 | 监听核心 `PageSaveComplete`，借 Moderation 公开的 `ApproveHook::isApprovingNow()` 判定"这次保存＝一次审批通过"，给**原作者**造一条 Echo 事件「你的编辑已通过审核」。**不需要给 Moderation 打补丁。** |

登录页会把两组凭据做成标签页（**站内账号** / **OTTOhub 账号**），并在表单下方附登录常见问题；
没有 JavaScript 时退化成两组字段并排显示，功能不受影响。

## 环境要求

- MediaWiki **>= 1.46**（用到了 `AuthManager`、`CsrfTokenSet`、`MediaWiki\JobQueue\Job` 等 1.46 的写法）
- PHP 需带 `curl` 扩展
- 阶段 3 需要 **Echo**（`wfLoadExtension( 'Echo' )`）
- 可选联动：**Moderation**（审核结果通知）、**CommentStreams** / **SocialProfile**（白名单里的通知类别）

零 composer 依赖。

## 安装

```bash
cd /path/to/wiki/extensions
git clone https://github.com/OTTOWiki/OttohubAuth.git
```

`LocalSettings.php`：

```php
wfLoadExtension( 'OttohubAuth' );

$wgOttohubAuth_ApiBase  = 'https://api.ottohub.cn';
$wgOttohubAuth_Timeout  = 8;
$wgOttohubAuth_Throttle = [ 'count' => 5, 'window' => 600 ];

// SSO 首次登录要自动建号。若站点关闭了本地注册（$wgGroupPermissions['*']['createaccount'] = false），
// 匿名用户会连 autocreateaccount 一起失去，必须补这一行，否则报 badaccess-group0。
// 它**不会**重新开放本地注册（原生建号页用的仍是 createaccount）。
$wgGroupPermissions['*']['autocreateaccount'] = true;
```

建表：

```bash
php maintenance/run.php OttohubAuth:schema
```

## 配置

| 变量 | 默认 | 说明 |
|---|---|---|
| `$wgOttohubAuth_ApiBase` | `https://api.ottohub.cn` | 上游 API 根 |
| `$wgOttohubAuth_Timeout` | `8` | 出网超时（秒） |
| `$wgOttohubAuth_Throttle` | `['count' => 5, 'window' => 600]` | 登录/认领共用的节流（IP + 账号） |
| `$wgOttohubAuth_NotifyEnable` | `true` | 阶段 3 总开关；`false` 立刻停止一切推送 |
| `$wgOttohubAuth_NotifyEvents` | `[]` | 事件级白名单；留空＝由下面的类别可用性决定 |
| `$wgOttohubAuth_NotifyRateLimit` | `['count' => 10, 'window' => 3600]` | 每个收件人每窗口最多推几条；`count <= 0` 不限 |
| `$wgOttohubAuth_SenderAccount` | `''` | 发件账号凭据文件的**路径**（见下）。留空＝不推送 |

### 阶段 3 的接线（必须写在 LocalSettings.php）

⚠️ 两个都踩过的坑：

1. **不能写进 `extension.json`** —— MediaWiki 1.46 禁止扩展清单声明别的扩展（这里指 Echo）
   已经声明过的配置键，会直接抛 `RuntimeException` 导致**全站 500**；`merge_strategy` 不豁免。
2. **Echo 的键名不都带 `Echo` 前缀**：`EchoNotifiers` / `EchoNotificationCategories` /
   `EchoNotifications` 有；`DefaultNotifyTypeAvailability` / `NotifyTypeAvailabilityByCategory`
   **没有**。写错不会报错，只会**静默失效**（通道永远不触发）。

```php
$wgEchoNotifiers['ottohub'] = 'MediaWiki\\Extension\\OttohubAuth\\OttohubNotifier::notify';
$wgDefaultNotifyTypeAvailability['ottohub'] = false;   // 默认对所有类别关闭
$wgNotifyTypeAvailabilityByCategory['mention']['ottohub'] = true;
$wgNotifyTypeAvailabilityByCategory['commentstreams-notification-category']['ottohub'] = true;
$wgNotifyTypeAvailabilityByCategory['edit-user-talk']['ottohub'] = true;
$wgNotifyTypeAvailabilityByCategory['social-msg']['ottohub'] = true;
$wgNotifyTypeAvailabilityByCategory['ottohubauth-moderation']['ottohub'] = true;
$wgEchoNotificationCategories['ottohubauth-moderation'] = [
	'priority' => 3,
	'tooltip' => 'echo-pref-tooltip-ottohubauth-moderation',
];
$wgEchoNotifications['ottohubauth-moderation-approved'] = [
	'category' => 'ottohubauth-moderation',
	'section' => 'message',
	'group' => 'positive',
	'presentation-model' => 'MediaWiki\\Extension\\OttohubAuth\\ModerationApprovedPresentationModel',
];
```

### 发件账号凭据

放在**站点根之外**、Web 服务器不可达的位置，权限 `600`、属主与 PHP-FPM 用户一致：

```json
{ "uid_email": "<OTTOhub 用户名或邮箱>", "pw": "<OTTOhub 口令>" }
```

```php
$wgOttohubAuth_SenderAccount = '/path/outside/webroot/ottohub-sender.json';
```

凭据只在服务端读取；登录换来的 token 缓存在对象缓存（TTL 30 分钟），
**凭据与 token 都不写日志、不进 URL**（POST 的 token 放 JSON body）。

## 上游契约（实测）

| 用途 | 方法 | 路径 | 参数 | 响应 |
|---|---|---|---|---|
| 登录换 token | POST | `/api/auth/login` | `{"uid_email": <用户名或邮箱>, "pw": <口令>}` | **平坦** `{"status":"success","uid","token",…}` |
| 取本人身份 | GET | `/api/profile?token=…` | token **只能放 query** | `{"status":"success","data":{"uid","username","email"}}` |
| 公开用户信息 | GET | `/api/user/{uid}` | — | `{"status":"success","data":{…}}`（前端直接用） |
| 发私信 | POST | `/api/im/messages` | `{"token","receiver","message"}`（token 进 body） | `{"status":"success"}`（**只有 status**） |
| 删除私信 | DELETE | `/api/im/messages/{msg_id}` | body `{"token"}` | `{"status":"success"}` |

- 私信正文上限 **222 个字符**（按字符，不是字节；超出报 `too_long_message`）。
- 错误码：`missing_argument` / `error_receiver` / `error_token`（401）/ `too_long_message` /
  `too_short_message` / `blocked` / `system_error`。
- `receiver` 必须是 **OTTOhub 侧 uid**。本扩展里它**只能**来自映射表
  `OttohubAccountStore::getHubUidByLocalUser()` —— 传错等于把通知发给陌生人。

## 数据库

单表 `ottohub_accounts`：`oa_hub_uid`（主键，OTTOhub 侧 uid）、`oa_user_id`（唯一键，站内 user_id）、
`oa_username`、`oa_linked_by`、`oa_linked_at`。表结构见 `sql/mysql/table_ottohub_accounts.sql`，
建表用 `OttohubAuth:schema`（幂等，只碰这一张表）。

## 维护脚本

```bash
php maintenance/run.php OttohubAuth:schema        # 建表（幂等）
php maintenance/run.php OttohubAuth:probe         # 只读探针：注册/服务/权限/provider/解析用例
php maintenance/run.php OttohubAuth:acceptance    # 验收检查（含事务回滚的映射表约束测试）
php maintenance/run.php OttohubAuth:accounts      # 列出/查询/解绑映射（CLI 兜底）
php maintenance/run.php OttohubAuth:notify        # 阶段 3 总览（--verify / --user= / --send= / --queue）
php maintenance/run.php OttohubAuth:debugrl --module=<名>        # ResourceLoader 异常原文
php maintenance/run.php OttohubAuth:renderpage --title=<页面>     # CLI 里按真实用户渲染页面抓异常
```

## 安全要点

- 收件人 hub uid **只**来自映射表；自己触发的事件不推给自己；未绑定 OTTOhub 的用户直接跳过。
- 阶段 3 **默认全员不勾选**（Echo 只给 web/email 设默认值），不主动勾选就永远收不到推送。
- 每个收件人每窗口限流（默认 10 条/小时），失败按 30/60 秒退避重试，最多 3 次；永久性错误直接丢弃。
- 通知入队/发送过程中的任何异常都被吞掉并记日志 —— **通知失败绝不影响触发它的编辑/评论**。
- 密码重置按"当前有无本地口令"封闭：没有本地口令的账号走不了 `Special:PasswordReset`，
  页面会明确提示改用 OTTOhub。

## 说明

本扩展是为 OTTOWiki 的具体环境写的（Echo、Moderation、CommentStreams、SocialProfile、Citizen 皮肤），
其中的类别白名单、`ottohubauth-moderation` 事件与通知文案都带着那个站点的取舍。
搬到别的 wiki 上用之前，请按自己的通知类别调整 `LocalSettings` 里的接线与 `i18n/` 文案。

## 许可

GPL-2.0-or-later，见 [LICENSE](LICENSE)。
