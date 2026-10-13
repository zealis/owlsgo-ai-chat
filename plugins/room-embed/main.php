<?php
/**
 * 群聊嵌入插件（room-embed）v2.1.0
 *
 * 功能：
 *   - 群主/管理员可在「群聊信息」面板获取该群的嵌入代码（默认浮动手机按钮，
 *     点击展开聊天室、点外部自动收回；也可选直接展开）
 *   - 外部网站（博客等）将代码贴入页面，即获得一个内置聊天室
 *   - 嵌入页复用主程序聊天页（?page=chat&room=ID&embed=1），不修改主程序
 *   - 嵌入模式下通过 page.head 注入精简 CSS，仅保留「群名顶栏 + 消息 + 输入」
 *   - **域名白名单**：只有名单内的域名才能加载（服务端 + 前端双重校验）
 *   - 嵌入代码内含**嵌入令牌**：跨站 iframe 被浏览器拦下会话 Cookie 时，
 *     令牌经核心 sign.fallback 钩子顶替签名门禁，聊天全功能可用（不依赖 Cookie）
 *   - **令牌导航保活**（v1.4.0）：iframe 内整页导航（等级页链接、「返回聊天」等）
 *     不再丢令牌——window.name 载体 + 加载恢复 + 点击/改地址时补全参数
 *
 * 不修改主程序的依据（v1.2.0 起有一处最小核心增量，其余仍为零修改）：
 *   - 页面入口用现有 ?page=chat&room=ID 直达群聊（核心已支持，renderChat 内 directHit）
 *   - UI 精简走 page.head 钩子注入 CSS（核心已提供）
 *   - 「嵌入代码」入口走 OwChat.onRoomEdit 前端扩展点（核心已提供，v1.1.10 起契约稳定）
 *   - 消息收发/轮询全部复用核心 API（rooms/history/poll/send）
 *   - v1.3.97 核心签名门禁新增 sign.fallback 钩子咨询（~6 行，纯增量、fail-closed），
 *     嵌入令牌的签发/校验/吊销全部在本插件内，核心不持有任何嵌入逻辑
 *
 * v1.1.0 修复记录（2026-10-12）：
 *   1. .ow-msg-bubble 在核心不存在（真类是 .ow-msg-content）——原「气泡紧凑」规则是死代码。
 *   2. .ow-layout{display:block} 切断 flex 高度链 → 历史消息一长输入栏被 overflow:hidden 裁出视口。
 *   3. 漏隐藏 .ow-brand / .ow-rail / .ow-mask，380×560 小窗被框架白吃 100+px。顶栏压至 44px。
 *   4. 生成的嵌入代码改响应式（max-width/max-height + dvh 渐进增强）。
 * v1.1.1：跨站嵌入的 Cookie 不到场检测与「恢复连接」引导（Storage Access，Safari 路径）。
 * v1.2.0（2026-10-12）：
 *   5. 嵌入令牌（跨站完全修复）：每个公开群一条有效令牌，随嵌入码下发；
 *      库内只存 sha256 哈希，明文仅在生成时展示一次；默认一年过期，可吊销、
 *      可重新生成（旧码立即作废）；房间改为非公开/密码房时自动失效；
 *      带 room_id 的请求必须与令牌房间一致；动作白名单（聊天面 + plugin_ 前缀）。
 *      权力边界 = 该公开群的**访客**：游客浏览/发言开关、禁言、限流、IP 封禁全部照生效，
 *      令牌不映射任何用户身份，无提权路径。
 * v1.3.0（2026-10-12）：
 *   6. 域名白名单：签发时每行一个域名（example.com 覆盖子域，* = 任意，空 = 不限制）；
 *      服务端在 sign.fallback 校验来源主机（Origin → Referer，配了白名单却取不到来源即拒），
 *      前端由 page.head 下发名单，document.referrer 不符时自显示了断；
 *   7. 启动方式默认改「浮动手机按钮」：点击展开、点聊天室外部/Esc 自动收回；
 *      保留「直接展开」选项；后台令牌管理改通用列表轮子（搜索/状态筛选/批量吊销/分页）。
 * v1.3.1（2026-10-12）：
 *   8. 修后台说明页文档列表：核心全局 reset 清零了 ol 左内边距（序号挂到卡片左界外，
 *      「数字超出标题」）且 ul 被 list-style:none 吃掉的符号——在本页作用域
 *      （.ow-re-doc）内补回 padding-left 与 disc 符号，不影响其它页面。
 * v1.4.0（2026-10-12）：
 *   9. 修「从 level 页返回聊天，全站 403 签名验证失败」：iframe 内整页导航
 *      （level-trust 用户卡片链接 href="?page=level"、插件页顶栏「返回聊天」
 *      href="?page=chat"）把 embed_token/visitor/origin 全部冲掉，iframe 回到
 *      无 cookie + 无令牌状态，每请求新会话 → 签名 key 永远对不上，刷新无用。
 *      保活三件套（page.head 每页注入，主程序零改动）：window.name 载体
 *      （令牌+房间随 iframe 导航存活，跨站父页面读不到）；加载即恢复
 *      （replaceState 写回 URL）；导航即携带（capture 拦同站 <a> 补参数，
 *      聊天目标同时补回上次房间——否则访客卡在「请选择一个群聊」选不了群；
 *      pushState/replaceState 兜底 + 跨站链接先抹令牌防外泄）。
 * v1.4.1（2026-10-12）：
 *   10. 修 v1.4.0 的漏网路径：**点击**等级链接时钩子会给等级页 URL 补上令牌，
 *      等级页走「URL 带令牌」分支，nameSet 把房间覆盖成空 → 返回聊天仍补不回
 *      room（用户实测复现）。改为合并语义：当前页 URL 没带房间时保留上次记住的。
 * v1.5.0（2026-10-12）：
 *   11. 嵌入态支持「登录/注册」：第三方 iframe 里浏览器拒绝落地会话 cookie，
 *      嵌入窗口内做不了登录——顶栏新增入口，点击新标签页打开主站登录页
 *      （第一方上下文，cookie 正常）；已登录身份（同站嵌入）不显示该入口。
 *   12. 启动器图标底色改自动适配页面深浅：autoTheme 沿祖先链找第一个不透明
 *      背景色算亮度，浅色页面出深底白图形、深色页面出白底深图形；站点主人可用
 *      --owlsgo-launcher-bg / --owlsgo-launcher-fg 覆盖（transparent = 透明图标）。
 *      替换掉原来写死的黄褐兜底色（用户报「不要这个黄色」）。
 * v2.0.0（2026-10-13）：
 *   13. 弹窗授权登录（OAuth 式，主程序仅 4 处小增量）：嵌入窗内点「登录/注册」→
 *      弹出主站登录小窗（第一方上下文，cookie 正常）→ 登录/注册成功 → 小窗内
 *      签发一次性授权码（120 秒）经 postMessage 回传 iframe → iframe 带码重载，
 *      服务端在 page.head 消费授权码并换发 30 天长期会话令牌 → iframe 内身份
 *      直接升级为该用户本人（发消息/头像/等级全是本人），会话令牌由父页面
 *      localStorage 持久化，刷新嵌入页不掉线。顶栏显示昵称 + 「登出」。
 *      注册顺带自动登录：仅嵌入授权弹窗（?embed_auth=1）生效，主站注册流程不变。
 *      授权码兑换时校验嵌入令牌的域名白名单；会话令牌后台可查看/吊销。
 * v2.1.0（2026-10-13）：
 *   14. 修「我的贴纸恒为空」：令牌放行白名单漏了 stickers，嵌在窗口里的登录用户
 *      点开贴纸页签恒 403（面板退回「暂无贴纸」）。放行 stickers（只读本人贴纸）。
 *   15. 修「昵称与登出之间间隔太多」：昵称与登出按钮都带 margin-left:auto，
 *      两个 auto 边距平分剩余空间。改为昵称承担唯一 auto，登出紧跟其后 8px。
 */
if (!defined('OWLSGO_VERSION')) exit;

/* ===================== 数据库表 ===================== */
// 嵌入令牌：一群一条有效记录（revoked=0），重新生成 = 吊销旧的 + 插新的。
// token_hash 存 sha256（库泄漏也拿不到明文）；created_by 留签发人便于审计。
// domains：域名白名单（每行一个，example.com 覆盖其子域名；* = 任意；空 = 不限制）。
function owReEnsureTable(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $drv = DB::driver();
    $int = $drv === 'mysql' ? 'INT' : 'INTEGER';
    $str = $drv === 'sqlite' ? 'TEXT' : 'VARCHAR(191)';
    $h64 = $drv === 'sqlite' ? 'TEXT' : 'VARCHAR(64)';
    DB::run("CREATE TABLE IF NOT EXISTS plugin_room_embed_tokens (
        id " . ($drv === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : ($drv === 'pgsql' ? 'SERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT')) . ",
        room_id $int NOT NULL,
        token_hash $h64 NOT NULL UNIQUE,
        created_by $int NOT NULL DEFAULT 0,
        created_by_name $str NOT NULL DEFAULT '',
        created_at $int NOT NULL DEFAULT 0,
        expires_at $int NOT NULL DEFAULT 0,
        last_used_at $int NOT NULL DEFAULT 0,
        revoked $int NOT NULL DEFAULT 0
    )");
    DB::run("CREATE INDEX IF NOT EXISTS idx_ow_re_tok_room ON plugin_room_embed_tokens (room_id)");
    // v1.3.0：域名白名单列（存量行空值 = 不限制，向后兼容）
    DB::ensureColumn('plugin_room_embed_tokens', 'domains', 'text', "''");
    // v2.0.0：登录授权表（弹窗 OAuth 式登录）。一张表两种 kind：
    //   code = 一次性授权码（小窗签发 → iframe 带码重载，120 秒过期，用后即焚）
    //   sess = 长期会话令牌（兑换码时换发，默认 30 天，父页面 localStorage 持久化）
    // secret_hash 存 sha256（库泄漏也拿不到明文）；token_id 绑定签发地那枚嵌入令牌——
    // 令牌被吊销，会话一并失效（embed.sess_user 钩子校验）。
    DB::run("CREATE TABLE IF NOT EXISTS plugin_room_embed_auth (
        id " . ($drv === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : ($drv === 'pgsql' ? 'SERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT')) . ",
        kind $str NOT NULL DEFAULT 'code',
        token_id $int NOT NULL DEFAULT 0,
        user_id $int NOT NULL DEFAULT 0,
        secret_hash $h64 NOT NULL UNIQUE,
        created_at $int NOT NULL DEFAULT 0,
        expires_at $int NOT NULL DEFAULT 0,
        last_used_at $int NOT NULL DEFAULT 0,
        used_at $int NOT NULL DEFAULT 0,
        revoked $int NOT NULL DEFAULT 0
    )");
    DB::run("CREATE INDEX IF NOT EXISTS idx_ow_re_auth_kind ON plugin_room_embed_auth (kind, revoked)");
}
owReEnsureTable();

/* ===================== 域名白名单 ===================== */
/** 解析白名单原文为规范数组（小写、去重、限量） */
function owReDomainList(string $raw): array
{
    $out = [];
    foreach (preg_split('/[\r\n,，;；\s]+/', strtolower($raw)) ?: [] as $d) {
        $d = trim($d, ". \t");
        if ($d === '') continue;
        if ($d !== '*' && !preg_match('/^(\*\.)?[a-z0-9-]+(\.[a-z0-9-]+)+$/', $d)) continue;
        if (!in_array($d, $out, true)) $out[] = $d;
        if (count($out) >= 20) break;
    }
    return $out;
}

/** 请求来源主机：优先客户端随请求携带的 embed_origin（父页面域名，见下），
 *  再回落 Origin（跨站 XHR 必带）/ Referer（iframe 导航带）。
 *  ⚠️ 为什么需要 embed_origin：iframe 里聊天页向**自己源**发的 XHR 是同源请求——
 *  不带 Origin、Referer 是聊天页自己，服务端从协议层看不到父域名。父域名由父页面
 *  写进 iframe URL（location.hostname，浏览器控制、第三方伪造不了），请求随身携带。 */
function owReReqHost(): string
{
    $o = (string)($_POST['embed_origin'] ?? '');
    if ($o === '') $o = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($o === '') $o = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($o === '') return '';
    if (!preg_match('#^https?://#i', $o)) {
        // embed_origin 是裸主机名（父页面 location.hostname），补齐 scheme 再取
        if (preg_match('/^[a-z0-9.-]+$/i', $o)) $o = 'https://' . $o;
    }
    $host = parse_url($o, PHP_URL_HOST);
    return strtolower((string)$host);
}

/** 白名单校验：未配置 = 放行；配置了却取不到来源 = fail-closed */
function owReDomainOk(array $row): bool
{
    $list = owReDomainList((string)($row['domains'] ?? ''));
    if (!$list) return true;
    $host = owReReqHost();
    if ($host === '') return false;
    foreach ($list as $d) {
        if ($d === '*') return true;
        // 精确匹配或子域名匹配：host === $d，或 host 以 ".$d" 结尾
        $suffix = '.' . $d;
        if ($host === $d || substr($host, -strlen($suffix)) === $suffix) return true;
    }
    return false;
}

/* ===================== 令牌校验（sign.fallback 处理器） ===================== */
// 核心验签失败后咨询本钩子，返回 '1' 放行。边界（与 PLUGIN.md 的 sign.fallback 约定一致）：
//   · 动作白名单：嵌入聊天页实际用到的核心动作 + plugin_ 前缀（插件路由按规范必须自查权限）；
//   · 令牌须存在、未吊销、未过期；房间须仍为公开群（改为密码/角色/不公开即失效）；
//   · 带 room_id 的请求必须与令牌绑定的房间一致；
//   · 通过后记 last_used_at（后台「最近使用」列）。
function owReTokenOk(string $tok, string $action): bool
{
    if (!preg_match('/^[a-f0-9]{32}$/', $tok)) return false;
    $ok = ['rooms', 'conversations', 'history', 'poll', 'send', 'room_join',
           'room_members', 'members_search', 'user_card',
           // v2.1.0：表情面板的「我的贴纸」。访客本来没有贴纸（Upload::stickers 按
           // owner_key=kind+id 取），但登录态下嵌在窗口里的正是本人 —— 不放行的话
           // 点开贴纸页签恒 403（实测 access.log 三条 403），面板显示「暂无贴纸」。
           // 与已放行的 user_card 同性质：只读本人数据，不能越权操作他人。
           'stickers'];
    if (!in_array($action, $ok, true) && strncmp($action, 'plugin_', 7) !== 0) return false;

    $row = DB::one('SELECT * FROM plugin_room_embed_tokens WHERE token_hash=? AND revoked=0',
        [hash('sha256', $tok)]);
    if (!$row) return false;
    if ((int)$row['expires_at'] < time()) return false;
    if (!owReDomainOk($row)) return false;   // v1.3.0：域名白名单（配了且来源不符 → 拒）

    $room = DB::one('SELECT id, type, is_public, status FROM rooms WHERE id=?', [(int)$row['room_id']]);
    if (!$room || (int)$room['status'] !== 1 || $room['type'] !== 'public' || (int)($room['is_public'] ?? 0) !== 1) {
        return false;
    }
    $rid = (int)($_POST['room_id'] ?? 0);
    if ($rid > 0 && $rid !== (int)$row['room_id']) return false;

    DB::run('UPDATE plugin_room_embed_tokens SET last_used_at=? WHERE id=?', [time(), (int)$row['id']]);
    return true;
}

Plugin::on('sign.fallback', function (array $ctx): string {
    $tok = (string)($ctx['token'] ?? '');
    $action = (string)($ctx['action'] ?? '');
    return owReTokenOk($tok, $action) ? '1' : '';
});

/* ===================== 登录授权（v2.0.0） ===================== */
// 弹窗 OAuth 式登录：小窗（第一方，cookie 正常）签授权码 → iframe 带码重载 →
// page.head 消费码换发 sess → 之后每个请求带 sess，核心经 embed.sess_user 钩子
// 把身份解析成用户本人。详见文件头第 13 条。

/** 按会话令牌解析用户（核心身份解析块咨询本钩子，&$user 回填 users 行）。
 *  校验链：格式 → 存在/未吊销/未过期 → 绑定的嵌入令牌仍有效 → 用户仍可用。
 *  任何一环不过都留空 $user，核心随之回落到访客身份（嵌入照常可用）。 */
Plugin::on('embed.sess_user', function (&$user, $sess): void {
    if (!empty($user)) return;
    $sess = (string)$sess;
    if (preg_match('/^[a-f0-9]{32}$/', $sess) !== 1) return;
    $row = DB::one('SELECT * FROM plugin_room_embed_auth WHERE kind=? AND secret_hash=? AND revoked=0',
        ['sess', hash('sha256', $sess)]);
    if (!$row || (int)$row['expires_at'] < time()) return;
    $tok = DB::one('SELECT revoked, expires_at FROM plugin_room_embed_tokens WHERE id=?', [(int)$row['token_id']]);
    if (!$tok || (int)$tok['revoked'] !== 0 || (int)$tok['expires_at'] < time()) return;
    $u = DB::one('SELECT * FROM users WHERE id=? AND status=1', [(int)$row['user_id']]);
    if (!$u) return;
    DB::run('UPDATE plugin_room_embed_auth SET last_used_at=? WHERE id=?', [time(), (int)$row['id']]);
    $user = $u;
});

/** 嵌入授权小窗里签发一次性授权码（敏感操作：小窗第一方上下文走 OwApi.secure）。
 *  调用方 = 主站聊天页（弹窗），actor 已由 cookie 会话解析为登录用户。 */
Plugin::route('plugin_room_embed_auth_code', function (array $ctx) {
    $actor = $ctx['actor'];
    if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '请先登录'], 403);
    $tok = (string)($ctx['post']['embed_token'] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/', $tok) !== 1) Api::json(['ok' => false, 'msg' => '参数不完整'], 400);
    $row = DB::one('SELECT * FROM plugin_room_embed_tokens WHERE token_hash=? AND revoked=0', [hash('sha256', $tok)]);
    if (!$row || (int)$row['expires_at'] < time()) Api::json(['ok' => false, 'msg' => '嵌入令牌无效或已过期']);
    // 房间须仍为公开群（与 sign.fallback 同口径）：房没了码也就没有意义
    $room = DB::one('SELECT id, type, is_public, status FROM rooms WHERE id=?', [(int)$row['room_id']]);
    if (!$room || (int)$room['status'] !== 1 || $room['type'] !== 'public' || (int)($room['is_public'] ?? 0) !== 1) {
        Api::json(['ok' => false, 'msg' => '该群已不可嵌入']);
    }
    $code = bin2hex(random_bytes(16));
    DB::insert('plugin_room_embed_auth', [
        'kind' => 'code',
        'token_id' => (int)$row['id'],
        'user_id' => (int)$actor['id'],
        'secret_hash' => hash('sha256', $code),
        'created_at' => time(),
        'expires_at' => time() + 120,   // 120 秒：弹窗回传 + iframe 重载够用，又不给重放留窗口
        'used_at' => 0,
        'revoked' => 0,
    ]);
    Sec::log('plugin_room_embed_auth_code', (string)($actor['nickname'] ?? ''), ['room' => (int)$row['room_id']]);
    Api::json(['ok' => true, 'code' => $code, 'expires_in' => 120]);
});
Plugin::sensitive('plugin_room_embed_auth_code');

/** 登出（吊销当前会话令牌）。请求从 iframe 来（带 embed_sess + embed_token 过门禁），
 *  走一次性票据防重放；父页面收到广播后清 localStorage 并以访客身份重载 iframe。 */
Plugin::route('plugin_room_embed_auth_logout', function (array $ctx) {
    $sess = (string)($ctx['post']['embed_sess'] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/', $sess) === 1) {
        DB::run('UPDATE plugin_room_embed_auth SET revoked=1 WHERE kind=? AND secret_hash=?',
            ['sess', hash('sha256', $sess)]);
    }
    Api::json(['ok' => true, 'msg' => '已登出']);
});
Plugin::sensitive('plugin_room_embed_auth_logout');

/** 后台：登录会话列表（管理员）。只给展示所需字段，哈希只露前 8 位。 */
Plugin::route('plugin_room_embed_sess_list', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $rows = DB::all('SELECT a.*, u.nickname, t.room_id, r.name AS room_name
        FROM plugin_room_embed_auth a
        LEFT JOIN users u ON u.id = a.user_id
        LEFT JOIN plugin_room_embed_tokens t ON t.id = a.token_id
        LEFT JOIN rooms r ON r.id = t.room_id
        WHERE a.kind = ?
        ORDER BY a.id DESC LIMIT 200', ['sess']);
    $now = time();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int)$r['id'],
            'user_id' => (int)$r['user_id'],
            'nickname' => (string)($r['nickname'] ?? ('#' . $r['user_id'])),
            'room_id' => (int)($r['room_id'] ?? 0),
            'room_name' => (string)($r['room_name'] ?? ('#' . (int)($r['room_id'] ?? 0))),
            'sess_prefix' => substr((string)$r['secret_hash'], 0, 8),
            'created_at' => (int)$r['created_at'],
            'expires_at' => (int)$r['expires_at'],
            'last_used_at' => (int)$r['last_used_at'],
            'revoked' => (int)$r['revoked'],
            'expired' => (int)$r['expires_at'] < $now,
        ];
    }
    Api::json(['ok' => true, 'data' => $out]);
});

/** 后台：吊销登录会话（管理员，支持批量 ids）。 */
Plugin::route('plugin_room_embed_sess_revoke', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $ids = [];
    foreach (explode(',', (string)($ctx['post']['ids'] ?? '')) as $v) {
        $v = (int)trim($v);
        if ($v > 0) $ids[] = $v;
    }
    if (!$ids) Api::json(['ok' => false, 'msg' => '参数不完整']);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $n = DB::run('UPDATE plugin_room_embed_auth SET revoked=1 WHERE kind=? AND id IN (' . $ph . ')',
        array_merge(['sess'], $ids));
    Sec::log('plugin_room_embed_sess_revoke', (string)($ctx['actor']['nickname'] ?? ''), ['ids' => $ids]);
    Api::json(['ok' => true, 'msg' => '已吊销 ' . (int)$n . ' 个会话，对应嵌入窗口将退回访客身份']);
});
Plugin::sensitive('plugin_room_embed_sess_revoke');

/* ===================== 登录授权辅助（v2.0.0） ===================== */
// 会话令牌有效期（天）。用户 2026-10-13 定稿「默认 30 天」——先按常量实现，
// 后续要做成后台可配只需把这里换成配置读取。
define('OW_RE_SESS_DAYS', 30);

/** 嵌入令牌 + 用户双重有效？返回 users 行，否则 null。tokenId 对应那枚嵌入令牌。 */
function owReAuthUser(int $tokenId, int $userId): ?array
{
    $tok = DB::one('SELECT id, room_id, revoked, expires_at FROM plugin_room_embed_tokens WHERE id=?', [$tokenId]);
    if (!$tok || (int)$tok['revoked'] !== 0 || (int)$tok['expires_at'] < time()) return null;
    $room = DB::one('SELECT id, type, is_public, status FROM rooms WHERE id=?', [(int)$tok['room_id']]);
    if (!$room || (int)$room['status'] !== 1 || $room['type'] !== 'public' || (int)($room['is_public'] ?? 0) !== 1) return null;
    $u = DB::one('SELECT * FROM users WHERE id=? AND status=1', [$userId]);
    return $u ?: null;
}

/** 换发长期会话令牌（同 (用户,令牌) 最多留 5 条有效会话，超出吊销最老的，防堆行）。 */
function owReMintSess(int $tokenId, int $userId): string
{
    $sess = bin2hex(random_bytes(16));
    $now = time();
    DB::insert('plugin_room_embed_auth', [
        'kind' => 'sess', 'token_id' => $tokenId, 'user_id' => $userId,
        'secret_hash' => hash('sha256', $sess), 'created_at' => $now,
        'expires_at' => $now + OW_RE_SESS_DAYS * 86400, 'used_at' => 0, 'revoked' => 0,
    ]);
    $keep = DB::all('SELECT id FROM plugin_room_embed_auth WHERE kind=? AND token_id=? AND user_id=? AND revoked=0 ORDER BY id DESC LIMIT 5',
        ['sess', $tokenId, $userId]);
    $keepIds = [];
    foreach ($keep as $k) $keepIds[] = (int)$k['id'];
    if (count($keepIds) < 5) return $sess;   // 没超额度，不用清
    $ph = implode(',', array_fill(0, count($keepIds), '?'));
    DB::run('UPDATE plugin_room_embed_auth SET revoked=1 WHERE kind=? AND token_id=? AND user_id=? AND revoked=0 AND id NOT IN (' . $ph . ')',
        array_merge(['sess', $tokenId, $userId], $keepIds));
    return $sess;
}

/** 消费一次性授权码 → 换发会话令牌。成功回传用户行，&$sessOut 带出新令牌明文。 */
function owReExchangeCode(string $code, string &$sessOut): ?array
{
    $row = DB::one('SELECT * FROM plugin_room_embed_auth WHERE kind=? AND secret_hash=? AND used_at=0 AND revoked=0',
        ['code', hash('sha256', $code)]);
    if (!$row || (int)$row['expires_at'] < time()) return null;
    $u = owReAuthUser((int)$row['token_id'], (int)$row['user_id']);
    if (!$u) return null;
    DB::run('UPDATE plugin_room_embed_auth SET used_at=? WHERE id=?', [time(), (int)$row['id']]);
    $sessOut = owReMintSess((int)$row['token_id'], (int)$u['id']);
    Sec::log('plugin_room_embed_auth_exchange', (string)$u['nickname'], ['room' => (int)$row['token_id']]);
    return $u;
}

/** 校验长期会话令牌 → 用户行。 */
function owReSessUser(string $sess): ?array
{
    if (preg_match('/^[a-f0-9]{32}$/', $sess) !== 1) return null;
    $row = DB::one('SELECT * FROM plugin_room_embed_auth WHERE kind=? AND secret_hash=? AND revoked=0',
        ['sess', hash('sha256', $sess)]);
    if (!$row || (int)$row['expires_at'] < time()) return null;
    return owReAuthUser((int)$row['token_id'], (int)$row['user_id']);
}

/* ===================== 路由 ===================== */

/** 群主/管理员守卫：管理员 或 该房间房主 */
$owReRoomGuard = function (array $ctx, int $roomId): array {
    $actor = $ctx['actor'];
    if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '需要登录后操作'], 403);
    $room = DB::one('SELECT id, owner_id, type, is_public, status, name FROM rooms WHERE id=?', [$roomId]);
    if (!$room || (int)$room['status'] !== 1) Api::json(['ok' => false, 'msg' => '房间不存在']);
    if ($room['type'] !== 'public' || (int)($room['is_public'] ?? 0) !== 1) {
        Api::json(['ok' => false, 'msg' => '仅公开群聊可嵌入']);
    }
    $isAdmin = ($actor['role'] ?? '') === 'admin';
    $isOwner = (int)($room['owner_id'] ?? 0) > 0 && (int)$room['owner_id'] === (int)$actor['id'];
    if (!$isAdmin && !$isOwner) Api::json(['ok' => false, 'msg' => '仅群主或超级管理员可生成嵌入代码'], 403);
    return $room;
};

/**
 * 签发（= 重新生成）嵌入令牌。敏感操作：每次调用都作废该群旧令牌并换发新的，
 * 明文只在本响应里返回一次（库里只有 sha256 哈希）。
 * v1.3.0： domains 域名白名单（每行一个；example.com 覆盖子域；* = 任意；空 = 不限制）。
 */
Plugin::route('plugin_room_embed_token', function (array $ctx) use ($owReRoomGuard) {
    $actor = $ctx['actor'];
    $roomId = (int)($ctx['post']['room_id'] ?? 0);
    $room = $owReRoomGuard($ctx, $roomId);

    $domains = owReDomainList((string)($ctx['post']['domains'] ?? ''));
    $tok = bin2hex(random_bytes(16));
    $now = time();
    DB::run('UPDATE plugin_room_embed_tokens SET revoked=1 WHERE room_id=? AND revoked=0', [$roomId]);
    DB::insert('plugin_room_embed_tokens', [
        'room_id' => $roomId,
        'token_hash' => hash('sha256', $tok),
        'created_by' => (int)($actor['id'] ?? 0),
        'created_by_name' => (string)($actor['nickname'] ?? ''),
        'created_at' => $now,
        'expires_at' => $now + 365 * 86400,
        'last_used_at' => 0,
        'revoked' => 0,
        'domains' => implode("\n", $domains),
    ]);
    Sec::log('plugin_room_embed_token', (string)($actor['nickname'] ?? ''),
        ['room' => $roomId, 'room_name' => $room['name'], 'domains' => $domains ?: '不限制']);
    Api::json(['ok' => true, 'token' => $tok, 'expires_at' => $now + 365 * 86400, 'domains' => $domains]);
});
Plugin::sensitive('plugin_room_embed_token');

/** 吊销令牌（后台用，管理员）。支持单个 id 或批量 ids（逗号分隔）。吊销后嵌入代码立即失效。 */
Plugin::route('plugin_room_embed_token_revoke', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $ids = [];
    foreach (explode(',', (string)($ctx['post']['ids'] ?? '')) as $v) {
        $v = (int)trim($v);
        if ($v > 0) $ids[] = $v;
    }
    if (!$ids && ($ctx['post']['id'] ?? '') !== '') {   // 兼容单条调用
        $ids = [(int)$ctx['post']['id']];
    }
    if (!$ids) Api::json(['ok' => false, 'msg' => '请先选择要吊销的令牌']);
    $ids = array_slice(array_unique($ids), 0, 100);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rooms = array_column(DB::all("SELECT id, room_id FROM plugin_room_embed_tokens WHERE id IN ($ph)", $ids), 'room_id', 'id');
    $n = DB::run("UPDATE plugin_room_embed_tokens SET revoked=1 WHERE id IN ($ph) AND revoked=0", $ids)->rowCount();
    Sec::log('plugin_room_embed_token_revoke', (string)($ctx['actor']['nickname'] ?? ''),
        ['ids' => implode(',', $ids), 'rooms' => implode(',', array_unique($rooms))]);
    Api::json(['ok' => true, 'msg' => '已吊销 ' . (int)$n . ' 个令牌，对应嵌入代码立即失效']);
});
Plugin::sensitive('plugin_room_embed_token_revoke');

/** 令牌列表（后台用，管理员）。通用列表轮子：搜索 + 状态筛选 + 分页；不回传哈希全值。 */
Plugin::route('plugin_room_embed_token_list', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $q = trim((string)($ctx['post']['q'] ?? ''));
    $state = (string)($ctx['post']['state'] ?? '');          // ''=全部 / '1'=有效 / '0'=已吊销
    $page = max(1, (int)($ctx['post']['page'] ?? 1));
    $psize = min(50, max(5, (int)($ctx['post']['psize'] ?? 15)));

    $where = ['1=1'];
    $args = [];
    if ($q !== '') {
        $where[] = '(r.name LIKE ? OR t.token_hash LIKE ? OR t.created_by_name LIKE ? OR t.room_id = ?)';
        $args[] = '%' . $q . '%';
        $args[] = '%' . $q . '%';
        $args[] = '%' . $q . '%';
        $args[] = (int)preg_replace('/\D/', '', $q) ?: 0;   // 纯数字按房间 ID 精确匹配
    }
    if ($state === '1' || $state === '0') {
        $now = time();
        if ($state === '1') {
            $where[] = 't.revoked=0 AND t.expires_at>?';
            $args[] = $now;
        } else {
            $where[] = '(t.revoked=1 OR t.expires_at<=?)';
            $args[] = $now;
        }
    }
    $wsql = implode(' AND ', $where);

    $total = (int)DB::val("SELECT COUNT(*) FROM plugin_room_embed_tokens t LEFT JOIN rooms r ON r.id=t.room_id WHERE $wsql", $args);
    $pages = max(1, (int)ceil($total / $psize));
    if ($page > $pages) $page = $pages;
    $rows = DB::all("SELECT t.*, r.name AS room_name FROM plugin_room_embed_tokens t "
        . "LEFT JOIN rooms r ON r.id = t.room_id WHERE $wsql ORDER BY t.revoked ASC, t.id DESC LIMIT $psize OFFSET " . (($page - 1) * $psize), $args);
    $out = [];
    $now = time();
    foreach ($rows as $r) {
        $domains = owReDomainList((string)($r['domains'] ?? ''));
        $out[] = [
            'id' => (int)$r['id'],
            'room_id' => (int)$r['room_id'],
            'room_name' => (string)($r['room_name'] ?? ('#' . $r['room_id'])),
            'token_prefix' => substr((string)$r['token_hash'], 0, 8),
            'created_by_name' => (string)$r['created_by_name'],
            'created_at' => (int)$r['created_at'],
            'expires_at' => (int)$r['expires_at'],
            'last_used_at' => (int)$r['last_used_at'],
            'revoked' => (int)$r['revoked'],
            'expired' => (int)$r['expires_at'] < $now,
            'domains' => $domains,
        ];
    }
    Api::json(['ok' => true, 'data' => $out, 'total' => $total, 'pages' => $pages, 'page' => $page, 'psize' => $psize]);
});

/* ===================== 嵌入模式 CSS ===================== */
// 仅当 URL 带 ?embed=1 时注入，把聊天页精简成适合 iframe 的小窗形态。
// 目标形态：顶栏（群名，44px）+ 消息区 + 输入栏，其余框架全部隐藏。
// 颜色一律引用核心 --ow-* 变量（带回落值仅作核心样式表未加载时的兜底，不参与正常渲染）。
Plugin::on('page.head', function (): void {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $hasTok = preg_match('/[?&]embed_token=[a-f0-9]{32}/', $uri) === 1;
    if (isset($_GET['embed']) && $_GET['embed'] === '1') {
        echo <<<'CSS'
<style>
/* ===== 嵌入模式：把聊天页压成右下角小窗 ===== */
/* 品牌条 / 图标 rail / 会话列表 / 右侧群信息栏 / 浮层遮罩：小窗里全部是占地方的框架 */
.ow-brand,.ow-rail,.ow-rail-foot,.ow-sidebar,.ow-online,.ow-mask{display:none!important}
/* 顶栏紧凑：核心高度钉在 var(--ow-topbar-h)（57px，与右侧栏首行对齐用），
   嵌入态没有右侧栏，压到 44px 把高度让给消息区 */
.ow-topbar{height:44px!important;padding:0 12px!important}
.ow-topbar .ow-only-mobile{display:none!important}   /* 窄屏「菜单」抽屉按钮：没有侧栏可开 */
.ow-topbar .ow-icon-btn{display:none!important}      /* 「群聊信息」竖三点：右栏已隐藏 */
.ow-room-name{font-size:14px!important;margin:0!important;
  overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;min-width:0!important}
/* 「登录/注册」入口（v1.5.0）：第三方 iframe 里浏览器拒绝落地会话 cookie，
   嵌入窗口内做不了登录——唯一可行路径是新标签页打开主站登录页（第一方上下文）。
   同站嵌入且已登录（actor.kind=user）时前端不注入本按钮。 */
.ow-embed-login{margin-left:auto!important;flex-shrink:0!important;-webkit-flex-shrink:0!important;
  font-size:12px;line-height:1;padding:6px 10px;border-radius:6px;white-space:nowrap;
  color:var(--ow-primary,#00A0E9);border:1px solid var(--ow-primary,#00A0E9);
  text-decoration:none;background:transparent}
.ow-embed-login:hover,.ow-embed-login:active{background:var(--ow-primary,#00A0E9);color:#fff}
/* v2.0.0：登录态顶栏（昵称 + 登出）。
   ⚠️ v2.1.0 修「昵称与登出之间间隔太多」：原来 .ow-embed-me 和 .ow-embed-login
   **都**带 margin-left:auto——两个 auto 边距会平分顶栏剩余空间，把昵称推到中间、
   登出推到最右。改为：昵称承担唯一的 auto（与未登录态的登录按钮同位置），
   登出按钮跟随其后（margin-left:8px）。 */
.ow-embed-me{margin-left:auto!important;flex-shrink:0!important;-webkit-flex-shrink:0!important;
  font-size:12px;line-height:1;padding:6px 0 6px 2px;white-space:nowrap;
  max-width:38%;overflow:hidden;text-overflow:ellipsis;
  color:var(--ow-text,#333);font-weight:500}
#owEmbedLogout{margin-left:8px!important}
.ow-latency{display:none!important}
.ow-tag{display:none!important}
.ow-main{border:none!important;min-width:0!important}
.ow-messages{padding:8px 10px!important}
.ow-msg{margin-bottom:6px!important;gap:8px!important}
/* 气泡紧凑：核心 .ow-msg-content 为 14px 字号 / 8×12 内边距 / 8px 圆角。
   宽度不在此处再收——外层 .ow-msg-body 已限 max-width:min(560px,78%)，二次收窄会提前折行 */
.ow-msg-content{font-size:13px!important;padding:6px 10px!important}
.ow-msg-meta{font-size:11px!important;margin-bottom:2px!important}
.ow-inputbar{padding:6px 8px!important;border-top:1px solid var(--ow-border,#eee)}
.ow-toolbar{gap:2px!important}
.ow-input-row{gap:6px!important}
.ow-input{font-size:13px!important;min-height:32px!important;padding:6px 8px!important}
.ow-send{width:34px!important;height:34px!important;min-width:34px!important}
/* 表情面板：绝对定位于 .ow-main，底缘避开紧凑输入栏（工具条 ~30 + 输入行 ~34 + 上下padding 12 ≈ 76px） */
.ow-emoji-panel{bottom:80px!important}
/* 嵌入态弹窗（如「是否加入群聊」确认、头像设置）：核心弹窗在 ≤720px 已自适应，这里只兜个上限 */
.ow-modal{width:92%!important;max-width:360px!important}
.ow-ctx-menu{font-size:12px!important}
/* Cookie 不到场时的「恢复连接」提示条（room-embed v1.1.1）：
   复用核心 .ow-notice ow-notice-warn 警示条（配色走 --ow-warn-* / --ow-yellow 令牌），
   只补 flex 布局与按钮不拉伸；注入位置在 .ow-inputbar 之前，不挡消息区 */
.ow-embed-guard{display:flex;display:-webkit-flex;align-items:center;-webkit-align-items:center;
  gap:8px;flex-wrap:wrap;-webkit-flex-wrap:wrap;margin:0 8px 6px;font-size:12px;line-height:1.6}
.ow-embed-guard-t{flex:1;-webkit-flex:1;min-width:0}
.ow-embed-guard-b{flex-shrink:0;-webkit-flex-shrink:0;font-size:12px;padding:5px 12px;text-decoration:none}
/* 嵌入页背景与外层站点融合 */
body.ow-chat-body{background:var(--ow-bg,#fff)!important}
/* 域名未授权提示（v1.3.0）：整页替换为一句人话，样式走核心令牌 */
.ow-embed-deny{position:fixed;left:0;top:0;right:0;bottom:0;display:flex;display:-webkit-flex;
  flex-direction:column;-webkit-flex-direction:column;align-items:center;-webkit-align-items:center;
  justify-content:center;-webkit-justify-content:center;gap:10px;padding:24px;text-align:center;
  background:var(--ow-bg,#fff);color:var(--ow-text,#333);font-size:14px;line-height:1.7}
.ow-embed-deny b{font-size:15px;color:var(--ow-text-title,#333)}
.ow-embed-deny span{color:var(--ow-text-sub,#999);font-size:12.5px;max-width:280px}
</style>
CSS;
    }
    /* ---------- 域名白名单下发（v1.3.0） ----------
       仅当 URL 带令牌时注入：chat.js 的 domainGuard 用它做客户端预判。
       注意：经 v1.4.0「导航保活」从 window.name 恢复令牌的页面，服务端渲染时
       URL 还没有令牌参数，名单未注入——此时客户端守卫跳过（返回 true），
       由服务端 sign.fallback 权威校验（配了白名单且来源不符 → 拒）。 */
    if ($hasTok) {
        $embDomains = [];
        if (preg_match('/[?&]embed_token=([a-f0-9]{32})/', $uri, $mm)) {
            $embRow = DB::one('SELECT domains FROM plugin_room_embed_tokens WHERE token_hash=? AND revoked=0', [hash('sha256', $mm[1])]);
            $embDomains = $embRow ? owReDomainList((string)$embRow['domains']) : [];
        }
        echo '<script>window.OW_EMBED_DOMAINS=' . json_encode($embDomains, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>';
    }
    /* ---------- 登录授权注入（v2.0.0） ----------
       URL 带 embed_login（一次性授权码，弹窗回传的）→ 在此消费并换发 30 天会话令牌；
       URL 带 embed_sess（会话令牌，父页面持久化的）→ 校验。通过则把「用户身份 +
       会话令牌明文」注入页面：
         · chat.js 据此覆盖 cfg.actor/cfg.me，顶栏显示昵称 + 登出；
         · 下方 head 预打包脚本把 sess 随每个 XHR 带上，核心经 embed.sess_user 钩子
           把请求身份解析成用户本人（发消息/头像/等级全是本人，不再是访客）。
       在 GET 渲染时一次完成兑换，前端零额外往返；码/令牌任何一环无效都静默跳过
       （页面按访客渲染，顶栏仍是登录入口）。 */
    $embLogin = preg_match('/[?&]embed_login=([a-f0-9]{32})/', $uri, $ml) ? $ml[1] : '';
    $embSessIn = preg_match('/[?&]embed_sess=([a-f0-9]{32})/', $uri, $ms) ? $ms[1] : '';
    $embUser = null;
    $embSessOut = '';
    if ($embLogin !== '') {
        $embUser = owReExchangeCode($embLogin, $embSessOut);
    } elseif ($embSessIn !== '') {
        $embUser = owReSessUser($embSessIn);
        if ($embUser) $embSessOut = $embSessIn;
    }
    if ($embUser) {
        $embUserJs = [
            'id' => (int)$embUser['id'],
            'nickname' => (string)$embUser['nickname'],
            'role' => (string)($embUser['role'] ?? 'member'),
            'avatar' => \Auth::avatarUrlFor($embUser, (string)$embUser['id']),
        ];
        echo '<script>window.OW_EMBED_USER=' . json_encode($embUserJs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>';
        echo '<script>window.OW_EMBED_SESS=' . json_encode($embSessOut, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>';
    }
    /* ---------- 嵌入令牌保活（v1.4.0，每页注入） ----------
       解决的真问题：iframe 内**整页导航**会把 embed_token 冲掉——
       level-trust 的用户卡片链接（href="?page=level"）、插件页顶栏的
       「返回聊天」（href="?page=chat"）都是裸相对链接。令牌一丢，
       iframe 回到「无 cookie（第三方上下文被拦）+ 无令牌」状态，
       每个请求都是新会话 → 签名 key 永远对不上 → 全站 action 403
       「签名验证失败，请刷新页面」，且刷新无用（实测复现）。
       保活三件套（全部在插件内，主程序零改动）：
         ① window.name 载体：令牌随 iframe 导航存活（不受第三方
            Cookie/localStorage 拦截影响；跨站父页面读不到 iframe 的 window.name）；
         ② 加载即恢复：iframe 内且 URL 无令牌时，从 window.name 取回并
            replaceState 写回 URL（不产生历史记录），再装 XHR 预打包；
 *         ③ 导航即携带：capture 阶段拦同站 <a> 点击补全参数（含上次房间——
 *            「返回聊天」是 href="?page=chat" 裸链接，不补房间访客会卡在
 *            「请选择一个群聊」——嵌入态侧栏被隐藏，永远选不了群）；
 *            pushState/replaceState 也补；点跨站链接先抹 window.name（不外泄令牌）。
       XHR 预打包为什么必须有：核心 OwChat.init() 的首批请求发生在插件
       chat.js 加载之前，不预打包首波全灭（实测 10/11 个 403）。 */
    echo <<<'JS'
<script>
(function () {
    var KEY = 'owEmbedCtx';
    var RE_TOK = /[?&]embed_token=([a-f0-9]{32})/;
    var RE_VIS = /[?&]embed_visitor=([a-z0-9]{4,16})/;
    var RE_ORG = /[?&]embed_origin=([a-z0-9.-]+)/;
    var RE_ROOM = /[?&]room=(\d{1,9})/;
    var RE_SESS = /[?&]embed_sess=([a-f0-9]{32})/;
    function inFrame() { try { return window.top !== window.self; } catch (e) { return true; } }
    function ctxUrl() {
        var q = location.search || '';
        var t = RE_TOK.exec(q), v = RE_VIS.exec(q), o = RE_ORG.exec(q), r = RE_ROOM.exec(q), s = RE_SESS.exec(q);
        // v2.0.0：sess 优先取服务端注入值（授权码刚兑换出来的、或刚校验过的），
        // 注入值是最新鲜的；URL 里的只作兜底（父页面持久化那枚）。
        var sess = s ? s[1] : '';
        try { if (window.OW_EMBED_SESS && /^[a-f0-9]{32}$/.test(window.OW_EMBED_SESS)) sess = window.OW_EMBED_SESS; } catch (e) {}
        return { tok: t ? t[1] : '', vis: v ? v[1] : '', org: o ? o[1] : '', room: r ? r[1] : '', sess: sess };
    }
    function nameGet() {
        try {
            var n = window.name || '';
            if (n.indexOf(KEY + '=') !== 0) return null;
            var p = n.slice(KEY.length + 1).split('|');
            if ((p[0] || '').length !== 32 || !RE_TOK.test('?embed_token=' + (p[0] || ''))) return null;
            return {
                tok: p[0], vis: p[1] || '', org: p[2] || '',
                room: /^\d{1,9}$/.test(p[3] || '') ? p[3] : '',
                sess: /^[a-f0-9]{32}$/.test(p[4] || '') ? p[4] : ''
            };
        } catch (e) { return null; }
    }
    function nameSet(c) {
        try {
            window.name = KEY + '=' + c.tok + '|' + c.vis + '|' + c.org + '|' + (c.room || '')
                + '|' + (c.sess || '');
        } catch (e) {}
    }
    function nameClear() {
        try { if ((window.name || '').indexOf(KEY + '=') === 0) window.name = ''; } catch (e) {}
    }
    function sameOrigin(u) { return u.indexOf(location.protocol + '//' + location.host) === 0; }
    function qs(c) {
        return 'embed=1&embed_token=' + c.tok
            + (c.vis ? '&embed_visitor=' + c.vis : '')
            + (c.org ? '&embed_origin=' + c.org : '')
            + (c.sess ? '&embed_sess=' + c.sess : '');   // v2.0.0：会话令牌随导航续命
    }
    function restoreUrl(c) {
        try {
            var kept = [];
            (location.search || '').replace(/^\?/, '').split('&').forEach(function (kv) {
                if (kv && kv.indexOf('embed_') !== 0) kept.push(kv);
            });
            history.replaceState(null, '', location.pathname + '?' + (kept.length ? kept.join('&') + '&' + qs(c) : qs(c)));
        } catch (e) {}
    }
    function packXhr(c) {
        var extra = 'embed_token=' + c.tok + (c.vis ? '&embed_visitor=' + c.vis : '') + (c.org ? '&embed_origin=' + c.org : '');
        if (c.sess) extra += '&embed_sess=' + c.sess;   // v2.0.0：登录会话令牌（有则身份=用户本人）
        var oo = XMLHttpRequest.prototype.open, os = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.open = function (meth, url) {
            this._owUrl = String(url || '');
            return oo.apply(this, arguments);
        };
        XMLHttpRequest.prototype.send = function (body) {
            try {
                if (this._owUrl && this._owUrl.indexOf('action=') >= 0) {
                    if (typeof body === 'string' && body.indexOf('embed_token=') < 0) {
                        body += (body ? '&' : '') + extra;
                    } else if (typeof FormData !== 'undefined' && body instanceof FormData
                               && (!body.has || !body.has('embed_token'))) {
                        body.append('embed_token', c.tok);
                        if (c.vis) body.append('embed_visitor', c.vis);
                        if (c.org) body.append('embed_origin', c.org);
                        if (c.sess) body.append('embed_sess', c.sess);
                    }
                }
            } catch (e) {}
            return os.call(this, body);
        };
    }
    function hookNav(c) {
        // ① 同站链接点击补全参数（capture 阶段：核心与插件渲染的链接都拦得到）。
        //    只拦无 target 的链接——target=_blank/_top 会离开 iframe 上下文，
        //    新标签页是第一方，靠 cookie 即可，带令牌反而会套上嵌入态 UI。
        document.addEventListener('click', function (e) {
            e = e || window.event;
            var t = e.target || e.srcElement, a = null;
            while (t && t.getAttribute) {
                if (t.getAttribute('href')) { a = t; break; }
                t = t.parentNode;
            }
            if (!a || a.getAttribute('target')) return;
            var href = a.getAttribute('href') || '';
            if (!href || href.charAt(0) === '#' || /^(javascript|mailto|tel):/i.test(href)) return;
            var url = a.href;
            if (!sameOrigin(url)) { nameClear(); return; }
            if (url.indexOf('embed_token=') >= 0) return;
            var qi = url.indexOf('?');
            var q = qi >= 0 ? url.slice(qi + 1) : '';
            var add = qs(c);
            // 聊天目标（page=chat 或无 page 参数的默认页）且没带房间时，补回上次的房间——
            // 插件页顶栏「返回聊天」是 href="?page=chat" 的裸链接，不补房间的话
            // 嵌入态侧栏被隐藏，访客卡在「请选择一个群聊」永远选不了群。
            var isChat = /(^|&)page=chat(&|$)/.test(q) || !/(^|&)page=/.test(q);
            if (isChat && c.room && !/(^|&)room=/.test(q)) add += '&room=' + c.room;
            a.setAttribute('href', url + (qi >= 0 ? '&' : '?') + add);
        }, true);
        // ② SPA 改地址后参数被冲掉时补回（核心 setRoomUrl 本就保留其他参数，这里兜插件/未来改动）
        ['pushState', 'replaceState'].forEach(function (fn) {
            var orig = history[fn];
            if (!orig) return;
            history[fn] = function () {
                try {
                    var u = arguments[2];
                    if (typeof u === 'string' && u.indexOf('embed_token=') < 0 && u.indexOf('action=') < 0
                        && sameOrigin(location.protocol + '//' + location.host + u)) {
                        arguments[2] = u + (u.indexOf('?') >= 0 ? '&' : '?') + qs(c);
                    }
                } catch (e) {}
                return orig.apply(history, arguments);
            };
        });
    }

    var framed = inFrame();
    var c = ctxUrl();
    if (c.tok) {
        // ⚠️ 房间/会话要合并而不是覆盖：等级页等非聊天页的 URL 带令牌但不带房间，
        // 直接 nameSet 会把上次记住的房间抹掉→「返回聊天」补不回 room，
        // 访客卡在「请选择一个群聊」（嵌入态侧栏隐藏，永远选不了群）。
        // v2.0.0：sess 同理——等级页 URL 没有 sess，不合并就会「变回访客」。
        if (!c.room || !c.sess) {
            var prev = nameGet();
            if (prev) {
                if (!c.room && prev.room) c.room = prev.room;
                if (!c.sess && prev.sess) c.sess = prev.sess;
            }
        }
        nameSet(c);          // URL 带令牌：入册（后续 iframe 内导航靠它续命）
        packXhr(c);
        if (framed) hookNav(c);
    } else if (framed) {
        var n = nameGet();   // URL 没令牌但还在嵌入 iframe 里：从 window.name 续上
        if (n) {
            restoreUrl(n);
            packXhr(n);
            hookNav(n);
        }
    }
})();
</script>
JS;
});

/* ===================== 前端交互脚本 ===================== */
Plugin::asset('js', 'room-embed/chat.js');
Plugin::asset('js', 'room-embed/admin.js');

/* ===================== 后台说明页的文档列表样式 ===================== */
// 核心全局 reset（* { margin:0; padding:0 }）把 ol 默认左内边距清零，而序号 marker 是
// outside 定位——数字会挂到卡片内容区左界外（用户报「数字超出标题」）；ul 又被核心的
// list-style:none 吃掉项目符号。这里只在本插件后台页作用域内补回，不污染别处。
Plugin::on('page.head', function (): void {
    if (($_GET['page'] ?? '') !== 'admin') return;
    echo '<style>'
        . '.ow-re-doc ol{padding-left:24px}'
        . '.ow-re-doc ul{padding-left:24px;list-style:disc}'
        . '</style>';
});

/* ===================== 后台说明页 ===================== */
Plugin::adminPage('room-embed', '群聊嵌入', function () {
    return '<h2>群聊嵌入</h2>'
        . '<p class="ow-admin-desc">允许用户将自己创建的群聊以 iframe 方式嵌入到博客等网站，作为右下角内置聊天室。本插件不修改主程序（仅 v1.3.97 起核心签名门禁多咨询一次 sign.fallback 钩子），完全复用核心聊天能力。</p>'
        . '<div class="ow-card ow-re-doc">'
        . '<h3 style="margin-top:0">使用方法</h3>'
        . '<ol style="line-height:2;color:var(--ow-text,#333)">'
        . '<li>群主或超级管理员进入任意公开群聊，点击右上角「⋮」打开群聊信息面板</li>'
        . '<li>在「群聊信息」下方点击「嵌入代码」——填写域名白名单、选择启动方式，每次点击会重新签发嵌入令牌</li>'
        . '<li>复制生成的代码，粘贴到目标网站的 HTML 中即可</li>'
        . '</ol>'
        . '<h3>嵌入效果（v1.3.0）</h3>'
        . '<p style="color:var(--ow-text,#333)">默认在网站右下角显示一个圆形手机图标，访客点击才展开聊天室，点聊天室外部自动收回；也可在生成时选择「直接展开」。小窗只保留群名、消息与输入框，站点品牌条与侧栏不进入嵌入页。图标底色会自动适应 embedding 网站的深浅色，也可用 CSS 变量 <code>--owlsgo-launcher-bg</code> 覆盖。</p>'
        . '<h3>登录与注册（v1.5.0 / v2.0.0）</h3>'
        . '<p style="color:var(--ow-text,#333)">浏览器会拦截第三方 iframe 里的登录 Cookie，嵌入窗口内无法直接登录。v2.0.0 起聊天窗顶栏提供「登录/注册」按钮：访客点击后<strong>弹出主站小窗</strong>完成登录或注册（小窗是主站第一方页面，验证码/两步验证全支持），成功后小窗自动关闭、聊天窗内<strong>直接以本人身份聊天</strong>——发消息、头像、等级全是本人。会话保持 30 天，刷新嵌入页不掉线；顶栏显示昵称和「登出」，登出后退回访客身份。</p>'
        . '<h3>域名白名单（v1.3.0）</h3>'
        . '<p style="color:var(--ow-text,#333)">生成嵌入代码时可填写允许加载的域名（每行一个）：只有名单内的域名才能加载聊天，其他网站即使拿到代码也只会看到「未授权」提示。填 <code>example.com</code> 同时覆盖其子域名；填 <code>*</code> 表示不限制；留空同样不限制（兼容旧代码）。校验在服务端与前端双重生效。</p>'
        . '<h3>嵌入令牌（v1.2.0）</h3>'
        . '<p style="color:var(--ow-text,#333)">嵌入代码内含一枚绑定该群的令牌，用于在浏览器拦截第三方 Cookie 的跨站场景下顶替会话——这是嵌入聊天在 Safari / 严格模式 Chrome 里可用的关键。令牌默认一年过期，权力等同于该群的<strong>访客</strong>（不携带任何用户身份）。重新点击「嵌入代码」会换发新令牌，旧代码立即失效；在本页底部可搜索与批量吊销全部令牌。</p>'
        . '<h3>注意事项</h3>'
        . '<ul style="line-height:2;color:var(--ow-text,#333)">'
        . '<li>仅<strong>公开群聊</strong>可被嵌入；密码群/角色限定群/不公开群嵌入后访客无法进入，改为非公开后令牌自动失效</li>'
        . '<li>嵌入代码含令牌，注意不要在公开场合粘贴完整代码；怀疑泄露请到本页底部吊销</li>'
        . '<li>嵌入代码为响应式尺寸（桌面 380×560，窄屏自动收窄撑满宽度且不超出屏幕高度），无需按设备分别配置</li>'
        . '</ul>'
        . '</div>'
        . '<div class="ow-card"><h3 style="margin-top:0">令牌管理</h3>'
        . '<p class="ow-admin-desc">吊销后，使用该令牌的嵌入代码立即失效（对应网站的聊天窗将提示无法连接）。重新生成请在群聊信息面板再次点击「嵌入代码」。</p>'
        // 通用列表轮子：搜索栏 + 状态筛选 + 批量操作 + 表格 + 分页（与附件管理等后台页同款）
        . '<div class="ow-form-row">'
        . '<div class="ow-form-item" style="min-width:220px;flex:1"><label>搜索</label>'
        . '<input class="ow-input" id="owREQ" placeholder="房间名 / 令牌前缀 / 签发人 / 房间ID" onkeydown="if(event.key===\'Enter\')OwRE.load(1)"></div>'
        . '<div class="ow-form-item" style="min-width:110px"><label>状态</label>'
        . '<select class="ow-input" id="owREState"><option value="">全部</option><option value="1">有效</option><option value="0">已吊销/已过期</option></select></div>'
        . '<button class="ow-btn ow-btn-primary" onclick="OwRE.load(1)">搜索</button>'
        . '<button class="ow-btn ow-btn-ghost" onclick="OwRE.reset()">重置</button>'
        . '</div></div>'
        . '<div class="ow-card">'
        . '<div style="margin-bottom:10px">'
        . '<button class="ow-btn ow-btn-danger" id="owREBatchRevoke" onclick="OwRE.batchRevoke()" disabled>批量吊销</button>'
        . '<span id="owREStat" style="margin-left:12px;color:var(--ow-text-sub,#999);font-size:12px"></span>'
        . '</div>'
        . '<div style="overflow-x:auto;-webkit-overflow-scrolling:touch">'
        . '<table class="ow-table" id="owRETable"></table></div>'
        . '<div id="owREPager" style="margin-top:12px"></div>'
        . '</div>'
        // v2.0.0：登录会话管理（弹窗授权换来的 30 天会话）。吊销后对应嵌入窗口
        // 在下次请求时退回访客身份（前端也会在登出广播时立刻重载）。
        . '<div class="ow-card"><h3 style="margin-top:0">登录会话</h3>'
        . '<p class="ow-admin-desc">访客通过弹窗授权登录后，嵌入窗口即以其本人身份聊天，会话有效期 30 天。在此可查看所有会话并吊销（吊销后对应窗口退回访客身份，用户重新登录即可恢复）。</p>'
        . '<div style="margin-bottom:10px">'
        . '<button class="ow-btn ow-btn-danger" id="owRSBatchRevoke" onclick="OwRS.batchRevoke()" disabled>批量吊销</button>'
        . '<span id="owRSStat" style="margin-left:12px;color:var(--ow-text-sub,#999);font-size:12px"></span>'
        . '</div>'
        . '<div style="overflow-x:auto;-webkit-overflow-scrolling:touch">'
        . '<table class="ow-table" id="owRSTable"></table></div>'
        . '</div>';
});
