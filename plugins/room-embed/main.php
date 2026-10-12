<?php
/**
 * 群聊嵌入插件（room-embed）v1.3.0
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

/** 请求来源主机：优先 Origin（跨站 XHR 必带），回落 Referer（iframe 导航带） */
function owReReqHost(): string
{
    $o = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($o === '') $o = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($o === '') return '';
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
           'room_members', 'members_search', 'user_card'];
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
    if (!isset($_GET['embed']) || $_GET['embed'] !== '1') return;
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
.ow-room-name{font-size:14px!important;margin:0!important}
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
        /* ---------- 嵌入令牌的 XHR 预打包（v1.2.0） ----------
           核心 OwChat.init() 的首批请求发生在**本插件的 chat.js 加载之前**，那时 OwApi
           还没被包上令牌——首波请求会全灭，且 poll 循环一死不再重试（实测 10/11 个 403）。
           这里在 <head> 里先做 XHR 原型级注入，保证首波请求也带令牌与访客标识。
           只在嵌入态且 URL 带合法令牌时生效；老嵌入码（无令牌）行为不变，走恢复引导兜底。
           令牌/访客标识**不从地址栏抹除**：第三方 iframe 里 localStorage 可能被浏览器一并拦掉，
           URL 是重载后唯一可靠的来源（跨站 referrer 默认只传 origin，查询串不外泄）。 */
        if (preg_match('/[?&]embed_token=[a-f0-9]{32}/', (string)($_SERVER['REQUEST_URI'] ?? ''))) {
            // v1.3.0：把该令牌的域名白名单下发给前端（chat.js 的 domainGuard 用），
            // 未授权域名进来自我了断给一句人话，而不是留一个半死的聊天窗。
            $embTokHash = null;
            if (preg_match('/[?&]embed_token=([a-f0-9]{32})/', (string)($_SERVER['REQUEST_URI'] ?? ''), $mm)) {
                $embRow = DB::one('SELECT domains FROM plugin_room_embed_tokens WHERE token_hash=? AND revoked=0', [hash('sha256', $mm[1])]);
                $embTokHash = $embRow ? owReDomainList((string)$embRow['domains']) : [];
            }
            echo '<script>window.OW_EMBED_DOMAINS=' . json_encode($embTokHash ?: [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>';
            echo <<<'JS'
<script>
(function () {
    var m = /[?&]embed_token=([a-f0-9]{32})/.exec(location.search);
    if (!m) return;
    var tok = m[1];
    var v = /[?&]embed_visitor=([a-z0-9]{4,16})/.exec(location.search);
    var vis = v ? v[1] : '';
    try { localStorage.setItem('ow_embed_token', tok); } catch (e) {}
    var extra = 'embed_token=' + tok + (vis ? '&embed_visitor=' + vis : '');
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
                    body.append('embed_token', tok);
                    if (vis) body.append('embed_visitor', vis);
                }
            }
        } catch (e) {}
        return os.call(this, body);
    };
})();
</script>
JS;
        }
    });

/* ===================== 前端交互脚本 ===================== */
Plugin::asset('js', 'room-embed/chat.js');
Plugin::asset('js', 'room-embed/admin.js');

/* ===================== 后台说明页 ===================== */
Plugin::adminPage('room-embed', '群聊嵌入', function () {
    return '<h2>群聊嵌入</h2>'
        . '<p class="ow-admin-desc">允许用户将自己创建的群聊以 iframe 方式嵌入到博客等网站，作为右下角内置聊天室。本插件不修改主程序（仅 v1.3.97 起核心签名门禁多咨询一次 sign.fallback 钩子），完全复用核心聊天能力。</p>'
        . '<div class="ow-card">'
        . '<h3 style="margin-top:0">使用方法</h3>'
        . '<ol style="line-height:2;color:var(--ow-text,#333)">'
        . '<li>群主或超级管理员进入任意公开群聊，点击右上角「⋮」打开群聊信息面板</li>'
        . '<li>在「群聊信息」下方点击「嵌入代码」——填写域名白名单、选择启动方式，每次点击会重新签发嵌入令牌</li>'
        . '<li>复制生成的代码，粘贴到目标网站的 HTML 中即可</li>'
        . '</ol>'
        . '<h3>嵌入效果（v1.3.0）</h3>'
        . '<p style="color:var(--ow-text,#333)">默认在网站右下角显示一个圆形手机图标，访客点击才展开聊天室，点聊天室外部自动收回；也可在生成时选择「直接展开」。小窗只保留群名、消息与输入框，站点品牌条与侧栏不进入嵌入页。</p>'
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
        . '</div>';
});
