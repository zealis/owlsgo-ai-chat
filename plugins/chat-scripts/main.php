<?php
/**
 * 群聊脚本插件（chat-scripts v1.0.0）
 *
 * ── 三个模块 ──────────────────────────────────────────────────
 *   A 快捷话术：输入工具栏 #owToolFirst 注入「话术」按钮（chat.js），面板里
 *     「公共话术」（后台维护，全站只读）+「我的话术」（前台自建，仅本人可见）。
 *     点击条目 = 插入输入框光标处；点「发」= 经 OwChat.send 直接发出。
 *   B 关键词自动回复：message.after_send 钩子按规则匹配，以机器人账号身份
 *     调用核心 Chat::send 回复。防循环 + 双限流，见下方注释。
 *   C 定时群发：Plugin::cron 每分钟扫描到期任务，机器人账号发送；
 *     支持「周期循环」与「一次性定时」两种模式。
 *
 * ── 安全设计（本插件的第一优先级）────────────────────────────
 *   1. 一切自动发送都走核心 Chat::send：敏感词过滤、禁言判定、发言频率限制、
 *      群成员校验、游客限制全部天然生效，插件不另造发送通道。
 *   2. 防循环：机器人自己发出的消息再次触发 message.after_send 时直接跳过
 *      （Chat::send 内部会再 fire 一次本钩子，没有这道闸就会无限递归）。
 *   3. 双限流：① 每房每分钟自动回复上限（Sec::rateLimit cs_reply）；
 *      ② 同一规则命中冷却（cs_rule）。叠加 Chat::send 自带的 msg 限流，
 *      机器人账号 10 秒最多 8 条，三重闸门防刷屏。
 *   4. 一条消息只回一条：多规则同时命中时按排序只发第一条，防连环复读。
 *   5. 机器人只能进公开群聊（is_public=1）；密码房仅超级管理员身份的
 *      机器人可进（免密），角色房按角色等级判定。房间选择在保存时服务端复核。
 *   6. 后台路由一律管理员鉴权；个人话术只能本人增改删；SQL 全参数化。
 *   7. 私聊（room_id=0）不触发自动回复与群发——那是两个具体的人之间的事。
 *
 * 约束：零依赖；样式只引 --ow-* 变量（见 style.css 头注）；ES5 兼容（chat.js/admin.js）；
 *       main.php 顶层只做 Plugin::* 注册与函数定义，副作用全部懒执行。
 */
if (!defined('OWLSGO_VERSION')) exit;   // 禁止直接 HTTP 访问本文件

/* ============================ 建表 ============================ */

/** 懒建表：只在真正读写数据时执行（main.php 顶层不做副作用） */
function csBoot(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pk  = DB::driver() === 'mysql' ? 'BIGINT UNSIGNED PRIMARY KEY' : 'INTEGER PRIMARY KEY';
    $id  = DB::autoId();                       // 自增主键（三驱动方言由核心给）
    $ts  = DB::driver() === 'mysql' ? 'BIGINT NOT NULL' : 'INTEGER NOT NULL';
    $str = DB::driver() === 'mysql' ? 'VARCHAR(190)' : 'TEXT';

    // A：话术（scope=public 公共 / user 个人）
    DB::run("CREATE TABLE IF NOT EXISTS plugin_chat_scripts_snip (
        id           {$id},
        scope        {$str} NOT NULL DEFAULT 'user',
        owner_id     {$ts} NOT NULL DEFAULT 0,
        group_name   {$str} NOT NULL DEFAULT '未分组',
        title        {$str} NOT NULL DEFAULT '',
        content      TEXT NOT NULL,
        use_count    INTEGER NOT NULL DEFAULT 0,
        sort         INTEGER NOT NULL DEFAULT 0,
        created_at   {$ts} DEFAULT 0,
        updated_at   {$ts} DEFAULT 0)");
    DB::createIndex('idx_cs_snip_scope', 'plugin_chat_scripts_snip', 'scope, owner_id', false);

    // B：自动回复规则（room_id=0 表示全部群聊）
    DB::run("CREATE TABLE IF NOT EXISTS plugin_chat_scripts_rule (
        id           {$id},
        room_id      {$ts} NOT NULL DEFAULT 0,
        keywords     TEXT NOT NULL,
        match_mode   {$str} NOT NULL DEFAULT 'include',
        reply        TEXT NOT NULL,
        hit_count    INTEGER NOT NULL DEFAULT 0,
        enabled      INTEGER NOT NULL DEFAULT 1,
        sort         INTEGER NOT NULL DEFAULT 0,
        created_at   {$ts} DEFAULT 0,
        updated_at   {$ts} DEFAULT 0)");
    DB::createIndex('idx_cs_rule_room', 'plugin_chat_scripts_rule', 'enabled, room_id', false);

    // C：群发任务（mode=loop 周期 / once 一次性）
    DB::run("CREATE TABLE IF NOT EXISTS plugin_chat_scripts_cast (
        id           {$id},
        room_id      {$ts} NOT NULL,
        room_name    {$str} NOT NULL DEFAULT '',
        content      TEXT NOT NULL,
        mode         {$str} NOT NULL DEFAULT 'loop',
        interval_sec INTEGER NOT NULL DEFAULT 3600,
        send_at      {$ts} NOT NULL DEFAULT 0,
        next_run_at  {$ts} NOT NULL DEFAULT 0,
        last_run_at  {$ts} NOT NULL DEFAULT 0,
        last_status  {$str} NOT NULL DEFAULT '',
        run_count    INTEGER NOT NULL DEFAULT 0,
        enabled      INTEGER NOT NULL DEFAULT 1,
        created_at   {$ts} DEFAULT 0,
        updated_at   {$ts} DEFAULT 0)");
    DB::createIndex('idx_cs_cast_due', 'plugin_chat_scripts_cast', 'enabled, next_run_at', false);

    // 配置（k/v）
    DB::run("CREATE TABLE IF NOT EXISTS plugin_chat_scripts_cfg (k TEXT PRIMARY KEY, " . DB::textCol('v') . ")");

    // 发送日志（B/C 的每次自动发送留痕，后台可查）
    DB::run("CREATE TABLE IF NOT EXISTS plugin_chat_scripts_log (
        id           {$id},
        kind         {$str} NOT NULL DEFAULT 'reply',
        room_id      {$ts} NOT NULL DEFAULT 0,
        room_name    {$str} NOT NULL DEFAULT '',
        rule_id      {$ts} NOT NULL DEFAULT 0,
        content      TEXT NOT NULL,
        ok           INTEGER NOT NULL DEFAULT 0,
        msg          {$str} NOT NULL DEFAULT '',
        created_at   {$ts} DEFAULT 0)");
    DB::createIndex('idx_cs_log_kind', 'plugin_chat_scripts_log', 'kind, created_at', false);
}

/* ============================ 配置 ============================ */

function csCfgDefaults(): array
{
    return [
        'bot_uid'         => '0',    // 机器人账号的用户 ID（0 = 未配置，B/C 不工作）
        'reply_limit'     => '3',    // B：每房每分钟自动回复上限（0 = 关闭自动回复）
        'reply_cooldown'  => '30',   // B：同一规则两次命中的最小间隔（秒，0 = 无冷却）
    ];
}

function csCfg(string $k): string
{
    csBoot();
    if (!isset($GLOBALS['_csCfg']) || !is_array($GLOBALS['_csCfg'])) $GLOBALS['_csCfg'] = [];
    if (array_key_exists($k, $GLOBALS['_csCfg'])) return $GLOBALS['_csCfg'][$k];
    $v = DB::val('SELECT v FROM plugin_chat_scripts_cfg WHERE k=?', [$k]);
    $def = csCfgDefaults();
    $GLOBALS['_csCfg'][$k] = ($v === null || $v === false || $v === '') ? ($def[$k] ?? '') : (string)$v;
    return $GLOBALS['_csCfg'][$k];
}

function csCfgInt(string $k): int
{
    $v = csCfg($k);
    if (!preg_match('/^-?\d+$/', $v)) return (int)(csCfgDefaults()[$k] ?? 0);
    return (int)$v;
}

function csSetCfg(string $k, string $v): void
{
    csBoot();
    DB::run('DELETE FROM plugin_chat_scripts_cfg WHERE k=?', [$k]);
    DB::run('INSERT INTO plugin_chat_scripts_cfg (k, v) VALUES (?,?)', [$k, $v]);
    unset($GLOBALS['_csCfg'][$k]);      // 立即失效，同请求后续读取拿新值
}

/* ============================ 机器人账号 ============================ */

/** 机器人 users 行（要求 status=1）；未配置 / 被禁用返回 null */
function csBot(): ?array
{
    csBoot();
    $uid = csCfgInt('bot_uid');
    if ($uid <= 0) return null;
    $u = DB::one('SELECT id, nickname, role, title, avatar, status FROM users WHERE id=?', [$uid]);
    return ($u && (int)$u['status'] === 1) ? $u : null;
}

/** 机器人 → Chat::send 可用的 actor 形状 */
function csBotActor(): ?array
{
    $u = csBot();
    if (!$u) return null;
    return [
        'kind'     => 'user',
        'id'       => (int)$u['id'],
        'nickname' => (string)$u['nickname'],
        'role'     => (string)$u['role'],
        'title'    => (string)($u['title'] ?? ''),
        'avatar'   => (string)($u['avatar'] ?? ''),
    ];
}

/**
 * 机器人能否在某群发言（与 Chat::send 内部的校验同源，保存前先挡一道）：
 *   · 不公开群（is_public=0）：机器人不是成员就进不去；
 *   · 密码房：机器人无浏览器 session 通行授权，仅超级管理员身份免密；
 *   · 角色房：按角色等级比较（Auth::roleLevel）。
 */
function csRoomOkForBot(array $room, array $bot): bool
{
    if ((int)($room['is_public'] ?? 1) !== 1) return false;
    if (($room['type'] ?? 'public') === 'password' && ($bot['role'] ?? '') !== 'admin') return false;
    if (($room['type'] ?? '') === 'role') {
        return Auth::roleLevel($bot['role'] ?? 'guest') >= Auth::roleLevel((string)($room['min_role'] ?? 'guest'));
    }
    return true;
}

/** 写一条发送日志（B/C 共用）；content/msg 截断入库，防脏数据撑爆列 */
function csLog(string $kind, int $roomId, int $ruleId, string $content, bool $ok, string $msg): void
{
    csBoot();
    $name = '';
    if ($roomId > 0) {
        $row = DB::one('SELECT name FROM rooms WHERE id=?', [$roomId]);
        $name = $row ? (string)$row['name'] : '';
    }
    DB::insert('plugin_chat_scripts_log', [
        'kind'       => $kind,
        'room_id'    => $roomId,
        'room_name'  => mb_substr($name, 0, 190),
        'rule_id'    => $ruleId,
        'content'    => mb_substr($content, 0, 2000),
        'ok'         => $ok ? 1 : 0,
        'msg'        => mb_substr($msg, 0, 190),
        'created_at' => time(),
    ]);
}

/** 关键词命中判定（$text 已转小写；include=包含任一，exact=完全相等） */
function csKeywordHit(array $kws, string $mode, string $low): bool
{
    foreach ($kws as $kw) {
        $kw = trim((string)$kw);
        if ($kw === '') continue;
        if ($mode === 'exact') {
            if (mb_strtolower($kw) === $low) return true;
        } elseif (mb_strpos($low, mb_strtolower($kw)) !== false) {
            return true;
        }
    }
    return false;
}

/* ============================ A：快捷话术（前台路由） ============================ */

/** 话术列表：公共（全站只读）+ 我的（登录用户）；游客只看公共 */
Plugin::route('plugin_chat_scripts_snips', function (array $ctx) {
    csBoot();
    $actor = $ctx['actor'];
    $uid = ($actor['kind'] ?? '') === 'user' ? (int)$actor['id'] : 0;
    $pub = DB::all("SELECT id, scope, group_name, title, content, use_count
        FROM plugin_chat_scripts_snip WHERE scope='public' ORDER BY sort ASC, id ASC");
    $mine = [];
    if ($uid > 0) {
        $mine = DB::all("SELECT id, scope, group_name, title, content, use_count
            FROM plugin_chat_scripts_snip WHERE scope='user' AND owner_id=? ORDER BY sort ASC, id ASC",
            [$uid]);
    }
    Api::json(['ok' => true, 'public' => $pub, 'mine' => $mine, 'guest' => $uid > 0 ? 0 : 1]);
});

/** 保存我的话术（仅登录用户；只能改自己的 user 行） */
Plugin::route('plugin_chat_scripts_snip_save', function (array $ctx) {
    csBoot();
    $actor = $ctx['actor'];
    if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '请先登录后再管理个人话术'], 401);
    $uid = (int)$actor['id'];
    $post = $ctx['post'];
    $id      = (int)($post['id'] ?? 0);
    $title   = trim((string)($post['title'] ?? ''));
    $content = trim((string)($post['content'] ?? ''));
    $group   = trim((string)($post['group'] ?? ''));
    if ($title === '' || mb_strlen($title) > 60) Api::json(['ok' => false, 'msg' => '标题不能为空且不超过 60 字']);
    if ($content === '' || mb_strlen($content) > 2000) Api::json(['ok' => false, 'msg' => '内容不能为空且不超过 2000 字（与单条消息上限一致）']);
    if (mb_strlen($group) > 30) $group = mb_substr($group, 0, 30);
    if ($group === '') $group = '未分组';
    $now = time();
    if ($id > 0) {
        $row = DB::one("SELECT id FROM plugin_chat_scripts_snip WHERE id=? AND scope='user' AND owner_id=?", [$id, $uid]);
        if (!$row) Api::json(['ok' => false, 'msg' => '话术不存在或无权修改'], 403);
        DB::run('UPDATE plugin_chat_scripts_snip SET group_name=?, title=?, content=?, updated_at=? WHERE id=?',
            [$group, $title, $content, $now, $id]);
        Api::json(['ok' => true, 'msg' => '已保存']);
    }
    // 个人话术总量上限，防无限灌数据
    $cnt = (int)DB::val("SELECT COUNT(*) FROM plugin_chat_scripts_snip WHERE scope='user' AND owner_id=?", [$uid]);
    if ($cnt >= 200) Api::json(['ok' => false, 'msg' => '个人话术最多 200 条，请先清理']);
    DB::insert('plugin_chat_scripts_snip', [
        'scope' => 'user', 'owner_id' => $uid, 'group_name' => $group,
        'title' => $title, 'content' => $content, 'sort' => 0, 'use_count' => 0,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    Api::json(['ok' => true, 'msg' => '已添加']);
});

/** 删除我的话术（敏感操作：前端须用 OwApi.secure） */
Plugin::route('plugin_chat_scripts_snip_del', function (array $ctx) {
    csBoot();
    $actor = $ctx['actor'];
    if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '请先登录'], 401);
    $id = (int)($ctx['post']['id'] ?? 0);
    $row = DB::one("SELECT id FROM plugin_chat_scripts_snip WHERE id=? AND scope='user' AND owner_id=?",
        [$id, (int)$actor['id']]);
    if (!$row) Api::json(['ok' => false, 'msg' => '话术不存在或无权删除'], 403);
    DB::run('DELETE FROM plugin_chat_scripts_snip WHERE id=?', [$id]);
    Api::json(['ok' => true, 'msg' => '已删除']);
});
Plugin::sensitive('plugin_chat_scripts_snip_del');

/** 使用计数 +1（插入/发送时调用；限流防刷） */
Plugin::route('plugin_chat_scripts_use', function (array $ctx) {
    csBoot();
    $actor = $ctx['actor'];
    if (!Sec::rateLimit('cs_use', ($actor['kind'] ?? 'g') . ($actor['id'] ?? 0), 60, 60)) {
        Api::json(['ok' => true]);   // 限流就静默成功：计数只是统计，不该打扰用户
    }
    $id = (int)($ctx['post']['id'] ?? 0);
    $row = DB::one('SELECT id, scope, owner_id FROM plugin_chat_scripts_snip WHERE id=?', [$id]);
    if (!$row) Api::json(['ok' => false, 'msg' => '话术不存在'], 404);
    // 个人话术只能由本人计数
    if (($row['scope'] ?? '') === 'user'
        && (($actor['kind'] ?? '') !== 'user' || (int)$actor['id'] !== (int)$row['owner_id'])) {
        Api::json(['ok' => false, 'msg' => '无权使用'], 403);
    }
    DB::run('UPDATE plugin_chat_scripts_snip SET use_count=use_count+1 WHERE id=?', [$id]);
    Api::json(['ok' => true]);
});

/* ============================ B：关键词自动回复 ============================ */

Plugin::on('message.after_send', function ($id, $actor, $roomId, $content, $type, $toUserId) {
    // 只接群聊文本/提及消息；私聊（room_id=0）是两个具体人之间的事，不碰
    if ($roomId <= 0) return;
    if ($type !== 'text' && $type !== 'mention') return;

    $bot = csBot();
    if (!$bot) return;                                  // 未配置机器人账号：功能待机
    // 防循环（关键）：机器人自己发的消息会再次触发本钩子，直接跳过。
    // 没有这道闸，规则里出现互相包含的关键词就会无限递归刷屏。
    if (($actor['kind'] ?? '') === 'user' && (int)($actor['id'] ?? 0) === (int)$bot['id']) return;

    $limit = csCfgInt('reply_limit');
    if ($limit <= 0) return;                            // 0 = 管理员关闭了自动回复
    // 双限流之一：每房每分钟上限（与 Chat::send 自带的 msg 限流叠加）
    if (!Sec::rateLimit('cs_reply', 'r' . (int)$roomId, 60, $limit)) return;

    $text = trim((string)$content);
    if ($text === '') return;
    $low = mb_strtolower($text);

    $rules = DB::all('SELECT * FROM plugin_chat_scripts_rule
        WHERE enabled=1 AND (room_id=0 OR room_id=?) ORDER BY sort ASC, id ASC', [(int)$roomId]);
    if (!$rules) return;

    $cool = csCfgInt('reply_cooldown');
    foreach ($rules as $r) {
        $kws = json_decode((string)$r['keywords'], true);
        if (!is_array($kws) || !$kws) continue;
        if (!csKeywordHit($kws, (string)($r['match_mode'] ?? 'include'), $low)) continue;

        // 双限流之二：同一规则冷却，防单规则被反复触发
        if ($cool > 0 && !Sec::rateLimit('cs_rule', 'r' . (int)$r['id'], $cool, 1)) return;

        $reply = (string)$r['reply'];
        // 机器人身份发送；Chat::send 内部照常过敏感词/禁言/频率/成员校验
        $ok = false; $msg = '';
        try {
            [$ok, $msg] = Chat::send(csBotActor(), (int)$roomId, 'text', $reply);
        } catch (Throwable $e) {
            $ok = false; $msg = $e->getMessage();
        }
        DB::run('UPDATE plugin_chat_scripts_rule SET hit_count=hit_count+1 WHERE id=?', [(int)$r['id']]);
        csLog('reply', (int)$roomId, (int)$r['id'], $reply, (bool)$ok, (string)$msg);
        return;   // 一条消息只回一条：多规则命中按排序只发第一条，防连环复读
    }
});

/* ============================ C：定时群发 ============================ */

Plugin::cron('chat_scripts_cast', 60, function () {
    csBoot();
    $now = time();
    // 每次最多处理 5 个到期任务，防一次性堆积把长轮询拖慢
    $tasks = DB::all('SELECT * FROM plugin_chat_scripts_cast
        WHERE enabled=1 AND next_run_at>0 AND next_run_at<=? ORDER BY next_run_at ASC LIMIT 5', [$now]);
    if (!$tasks) return null;

    $bot = csBot();
    $done = 0;
    foreach ($tasks as $t) {
        $id = (int)$t['id'];
        if (!$bot) {
            DB::run('UPDATE plugin_chat_scripts_cast SET last_status=? WHERE id=?',
                ['未配置机器人账号，任务未发送', $id]);
            continue;
        }
        $room = DB::one('SELECT * FROM rooms WHERE id=? AND status=1', [(int)$t['room_id']]);
        if (!$room) {
            // 群被删/关了：任务没有存在意义，自动停用并留痕
            DB::run('UPDATE plugin_chat_scripts_cast SET enabled=0, last_status=?, updated_at=? WHERE id=?',
                ['群聊不存在或已关闭，任务已自动停用', $now, $id]);
            csLog('cast', (int)$t['room_id'], 0, (string)$t['content'], false, '群聊不存在或已关闭');
            continue;
        }
        $ok = false; $msg = '';
        try {
            [$ok, $msg] = Chat::send(csBotActor(), (int)$t['room_id'], 'text', (string)$t['content']);
        } catch (Throwable $e) {
            $ok = false; $msg = $e->getMessage();
        }
        csLog('cast', (int)$t['room_id'], 0, (string)$t['content'], (bool)$ok, (string)$msg);
        $status = $ok ? '已发送' : ('发送失败：' . mb_substr((string)$msg, 0, 120));
        if (($t['mode'] ?? 'loop') === 'once') {
            // 一次性任务：发完即停用（失败也停，避免到点反复重试同一内容）
            DB::run('UPDATE plugin_chat_scripts_cast SET enabled=0, last_run_at=?, last_status=?, run_count=run_count+1, updated_at=? WHERE id=?',
                [$now, $status, $now, $id]);
        } else {
            $iv = max(300, (int)$t['interval_sec']);
            DB::run('UPDATE plugin_chat_scripts_cast SET last_run_at=?, next_run_at=?, last_status=?, run_count=run_count+1, updated_at=? WHERE id=?',
                [$now, $now + $iv, $status, $now, $id]);
        }
        $done++;
    }
    return $done ? ('执行群发任务 ' . $done . ' 个') : null;
}, '群聊脚本：按计划用机器人账号在群聊里发送预设消息（每分钟检查一次到期任务；发送走核心 Chat::send，与普通消息同样受敏感词/禁言/频率限制）');

/* ============================ 后台 ============================ */

/** 管理员鉴权：插件路由的公共守卫（核心不会替插件校验权限） */
$csGuard = function (array $ctx): void {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
};

Plugin::adminPage('chat-scripts', '群聊脚本', function () {
    return '<h2>群聊脚本</h2>'
        . '<p class="ow-admin-desc">快捷话术、关键词自动回复与定时群发。机器人自动发送的消息与普通用户消息走完全相同的服务端校验（敏感词、禁言、频率限制）。</p>'
        . '<div class="ow-cs-tabs">'
        . '<button class="ow-btn ow-cs-tab is-active" type="button" data-tab="snip">快捷话术</button>'
        . '<button class="ow-btn ow-cs-tab" type="button" data-tab="rule">自动回复</button>'
        . '<button class="ow-btn ow-cs-tab" type="button" data-tab="cast">定时群发</button>'
        . '<button class="ow-btn ow-cs-tab" type="button" data-tab="log">发送日志</button>'
        . '</div>'

        /* ---- 快捷话术（公共） ---- */
        . '<div class="ow-cs-pane" id="owCsPane-snip">'
        . '<div class="ow-card"><h3 style="margin-bottom:10px">公共话术</h3>'
        . '<p style="font-size:12px">全站用户在话术面板里都能看到并使用，仅管理员可维护。个人话术由用户在前台自行管理，不在这里出现。</p>'
        . '<div class="ow-form-row"><div class="ow-form-item" style="flex:1">'
        . '<input class="ow-input" id="owCsSnipQ" placeholder="搜索标题或内容（留空显示全部）">'
        . '</div><button class="ow-btn ow-btn-primary" type="button" onclick="OwCSAdmin.snipEdit(0)">新增话术</button></div>'
        . '<div id="owCsSnips"></div></div></div>'

        /* ---- 自动回复 ---- */
        . '<div class="ow-cs-pane" id="owCsPane-rule" style="display:none">'
        . '<div class="ow-card"><h3 style="margin-bottom:10px">机器人账号</h3>'
        . '<p style="font-size:12px">自动回复与定时群发都以该账号身份发消息，和普通用户消息同样经过敏感词、禁言、频率限制。建议注册一个专用账号并加入相关群聊；不要使用超级管理员账号（它发的每条消息都会带管理员标识）。</p>'
        . '<div class="ow-form-row">'
        . '<div class="ow-form-item"><label>机器人用户 ID</label><input class="ow-input" id="owCsBotUid" style="width:120px" placeholder="如 12"></div>'
        . '<div class="ow-form-item"><label>每房每分钟回复上限</label><input class="ow-input" id="owCsReplyLimit" style="width:90px"></div>'
        . '<div class="ow-form-item"><label>同规则冷却（秒）</label><input class="ow-input" id="owCsCool" style="width:90px"></div>'
        . '<button class="ow-btn ow-btn-primary" type="button" onclick="OwCSAdmin.cfgSave()">保存设置</button>'
        . '<button class="ow-btn" type="button" onclick="OwCSAdmin.botJoin()">加入所有公开群</button>'
        . '</div><div id="owCsBotInfo" style="margin-top:8px"></div></div>'
        . '<div class="ow-card"><h3 style="margin-bottom:10px">回复规则</h3>'
        . '<p style="font-size:12px">群聊里有人说的话命中关键词时，机器人自动回复一次。「包含」= 消息里出现任一关键词即命中；「全等」= 整条消息（去首尾空白）与关键词完全相同才命中。同一条消息只会触发一条规则（按排序取第一条）。</p>'
        . '<div id="owCsRules"></div>'
        . '<button class="ow-btn ow-btn-primary" type="button" style="margin-top:10px" onclick="OwCSAdmin.ruleEdit(0)">新增规则</button>'
        . '</div></div>'

        /* ---- 定时群发 ---- */
        . '<div class="ow-cs-pane" id="owCsPane-cast" style="display:none">'
        . '<div class="ow-card"><h3 style="margin-bottom:10px">群发任务</h3>'
        . '<p style="font-size:12px">到时间用机器人账号在选定群聊发送预设内容。机器人只能进公开群聊（密码房需要机器人本人是超级管理员，角色房按角色等级判定）；「加入所有公开群」按钮在「自动回复」页。周期任务最短 5 分钟一次，一次性任务到点发送后自动停用。</p>'
        . '<div id="owCsCasts"></div>'
        . '<button class="ow-btn ow-btn-primary" type="button" style="margin-top:10px" onclick="OwCSAdmin.castEdit(0)">新增任务</button>'
        . '</div></div>'

        /* ---- 发送日志 ---- */
        . '<div class="ow-cs-pane" id="owCsPane-log" style="display:none">'
        . '<div class="ow-card"><h3 style="margin-bottom:10px">发送日志</h3>'
        . '<p style="font-size:12px">自动回复与群发的每次发送都记在这里（含失败原因）。机器人被禁言、被移出群聊、内容被敏感词拦截时，这里能看到对应记录。</p>'
        . '<div class="ow-form-row" style="margin-bottom:10px"><button class="ow-btn" type="button" onclick="OwCSAdmin.logClear()">清空日志</button></div>'
        . '<div id="owCsLogs"></div></div></div>';
});

/* ---------- 后台数据总览（一次拿全，减少请求） ---------- */
Plugin::route('plugin_chat_scripts_admin_init', function (array $ctx) use ($csGuard) {
    $csGuard($ctx);
    csBoot();
    $bot = csBot();
    $rooms = DB::all('SELECT id, name, type, min_role, is_public FROM rooms WHERE status=1 ORDER BY id ASC');
    foreach ($rooms as &$rm) {
        $rm['bot_ok'] = ($bot && csRoomOkForBot($rm, $bot)) ? 1 : 0;
    }
    unset($rm);
    Api::json([
        'ok'    => true,
        'cfg'   => [
            'bot_uid'        => csCfg('bot_uid'),
            'reply_limit'    => csCfg('reply_limit'),
            'reply_cooldown' => csCfg('reply_cooldown'),
        ],
        'bot'   => $bot ? ['id' => (int)$bot['id'], 'nickname' => (string)$bot['nickname'], 'role' => (string)$bot['role']] : null,
        'rooms' => $rooms,
        'snips' => DB::all("SELECT * FROM plugin_chat_scripts_snip WHERE scope='public' ORDER BY sort ASC, id ASC"),
        'rules' => DB::all('SELECT * FROM plugin_chat_scripts_rule ORDER BY sort ASC, id ASC'),
        'casts' => DB::all('SELECT * FROM plugin_chat_scripts_cast ORDER BY id DESC'),
        'logs'  => DB::all('SELECT * FROM plugin_chat_scripts_log ORDER BY id DESC LIMIT 100'),
    ]);
});

/* ---------- 公共话术保存 / 删除（敏感操作） ---------- */
Plugin::route('plugin_chat_scripts_admin_snip_save', function (array $ctx) use ($csGuard) {
    $csGuard($ctx);
    csBoot();
    $post = $ctx['post'];
    $id      = (int)($post['id'] ?? 0);
    $title   = trim((string)($post['title'] ?? ''));
    $content = trim((string)($post['content'] ?? ''));
    $group   = trim((string)($post['group'] ?? ''));
    if ($title === '' || mb_strlen($title) > 60) Api::json(['ok' => false, 'msg' => '标题不能为空且不超过 60 字']);
    if ($content === '' || mb_strlen($content) > 2000) Api::json(['ok' => false, 'msg' => '内容不能为空且不超过 2000 字']);
    if (mb_strlen($group) > 30) $group = mb_substr($group, 0, 30);
    if ($group === '') $group = '未分组';
    $now = time();
    if ($id > 0) {
        $row = DB::one("SELECT id FROM plugin_chat_scripts_snip WHERE id=? AND scope='public'", [$id]);
        if (!$row) Api::json(['ok' => false, 'msg' => '公共话术不存在'], 404);
        DB::run('UPDATE plugin_chat_scripts_snip SET group_name=?, title=?, content=?, updated_at=? WHERE id=?',
            [$group, $title, $content, $now, $id]);
        Api::json(['ok' => true, 'msg' => '已保存']);
    }
    DB::insert('plugin_chat_scripts_snip', [
        'scope' => 'public', 'owner_id' => 0, 'group_name' => $group,
        'title' => $title, 'content' => $content, 'sort' => 0, 'use_count' => 0,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    Sec::log('cs_snip_add', $ctx['actor']['nickname'], ['title' => mb_substr($title, 0, 60)]);
    Api::json(['ok' => true, 'msg' => '已添加']);
});
Plugin::sensitive('plugin_chat_scripts_admin_snip_save');

Plugin::route('plugin_chat_scripts_admin_snip_del', function (array $ctx) use ($csGuard) {
    $csGuard($ctx);
    csBoot();
    $id = (int)($ctx['post']['id'] ?? 0);
    $row = DB::one("SELECT id, title FROM plugin_chat_scripts_snip WHERE id=? AND scope='public'", [$id]);
    if (!$row) Api::json(['ok' => false, 'msg' => '公共话术不存在'], 404);
    DB::run('DELETE FROM plugin_chat_scripts_snip WHERE id=?', [$id]);
    Sec::log('cs_snip_del', $ctx['actor']['nickname'], ['title' => mb_substr((string)$row['title'], 0, 60)]);
    Api::json(['ok' => true, 'msg' => '已删除']);
});
Plugin::sensitive('plugin_chat_scripts_admin_snip_del');

/* ---------- 机器人设置（敏感操作） ---------- */
Plugin::route('plugin_chat_scripts_admin_cfg_save', function (array $ctx) use ($csGuard) {
    $csGuard($ctx);
    csBoot();
    $post = $ctx['post'];
    $botUid = (int)($post['bot_uid'] ?? 0);
    if ($botUid > 0) {
        $u = DB::one('SELECT id, status, role FROM users WHERE id=?', [$botUid]);
        if (!$u) Api::json(['ok' => false, 'msg' => '机器人账号不存在']);
        if ((int)$u['status'] !== 1) Api::json(['ok' => false, 'msg' => '机器人账号已被禁用，换个账号']);
    }
    csSetCfg('bot_uid', (string)$botUid);
    csSetCfg('reply_limit', (string)max(0, min(60, (int)($post['reply_limit'] ?? 3))));
    csSetCfg('reply_cooldown', (string)max(0, min(3600, (int)($post['reply_cooldown'] ?? 30))));
    Sec::log('cs_cfg_save', $ctx['actor']['nickname'], ['bot_uid' => $botUid]);
    Api::json(['ok' => true, 'msg' => '已保存']);
});
Plugin::sensitive('plugin_chat_scripts_admin_cfg_save');

/* ---------- 机器人加入所有公开群（敏感操作） ---------- */
Plugin::route('plugin_chat_scripts_admin_bot_join', function (array $ctx) use ($csGuard) {
    $csGuard($ctx);
    csBoot();
    $bot = csBot();
    if (!$bot) Api::json(['ok' => false, 'msg' => '请先设置机器人账号']);
    $rooms = DB::all('SELECT id FROM rooms WHERE status=1 AND is_public=1');
    $n = 0;
    foreach ($rooms as $rm) {
        // 先查后插：room_members 的唯一约束随核心版本演变，不能依赖 INSERT IGNORE 去重
        $has = (int)DB::val('SELECT 1 FROM room_members WHERE room_id=? AND user_id=?',
            [(int)$rm['id'], (int)$bot['id']]);
        if ($has) continue;
        DB::insert('room_members', [
            'room_id' => (int)$rm['id'], 'user_id' => (int)$bot['id'],
            'invited_by' => 0, 'created_at' => time(),
        ]);
        $n++;
    }
    Sec::log('cs_bot_join', $ctx['actor']['nickname'], ['bot' => (int)$bot['id'], 'joined' => $n]);
    Api::json(['ok' => true, 'msg' => $n > 0 ? ('已加入 ' . $n . ' 个公开群聊') : '机器人已在所有公开群聊中', 'n' => $n]);
});
Plugin::sensitive('plugin_chat_scripts_admin_bot_join');

/* ---------- 回复规则保存 / 删除（敏感操作） ---------- */
Plugin::route('plugin_chat_scripts_admin_rule_save', function (array $ctx) use ($csGuard) {
    $csGuard($ctx);
    csBoot();
    $post = $ctx['post'];
    $id     = (int)($post['id'] ?? 0);
    $roomId = (int)($post['room_id'] ?? 0);
    $mode   = ($post['match_mode'] ?? '') === 'exact' ? 'exact' : 'include';

    $kws = json_decode((string)($post['keywords'] ?? ''), true);
    if (!is_array($kws) || !$kws) Api::json(['ok' => false, 'msg' => '至少填写一个关键词']);
    $clean = [];
    foreach ($kws as $kw) {
        $kw = trim((string)$kw);
        if ($kw === '') continue;
        if (mb_strlen($kw) > 20) Api::json(['ok' => false, 'msg' => '单个关键词不超过 20 字']);
        $clean[] = $kw;
        if (count($clean) >= 10) break;
    }
    if (!$clean) Api::json(['ok' => false, 'msg' => '至少填写一个关键词']);

    $reply = trim((string)($post['reply'] ?? ''));
    if ($reply === '' || mb_strlen($reply) > 2000) Api::json(['ok' => false, 'msg' => '回复内容 1-2000 字']);

    if ($roomId > 0) {
        $room = DB::one('SELECT * FROM rooms WHERE id=? AND status=1', [$roomId]);
        if (!$room) Api::json(['ok' => false, 'msg' => '群聊不存在或已关闭']);
        $bot = csBot();
        if (!$bot) Api::json(['ok' => false, 'msg' => '请先在「机器人账号」里设置机器人用户 ID']);
        if (!csRoomOkForBot($room, $bot)) {
            Api::json(['ok' => false, 'msg' => '机器人无法进入该群聊（仅支持公开群聊；密码房需机器人本人是超级管理员）']);
        }
    }

    $enabled = (int)($post['enabled'] ?? 1) ? 1 : 0;
    $now = time();
    $kwJson = json_encode($clean, JSON_UNESCAPED_UNICODE);
    if ($id > 0) {
        DB::run('UPDATE plugin_chat_scripts_rule SET room_id=?, keywords=?, match_mode=?, reply=?, enabled=?, updated_at=? WHERE id=?',
            [$roomId, $kwJson, $mode, $reply, $enabled, $now, $id]);
        Api::json(['ok' => true, 'msg' => '已保存']);
    }
    DB::insert('plugin_chat_scripts_rule', [
        'room_id' => $roomId, 'keywords' => $kwJson, 'match_mode' => $mode,
        'reply' => $reply, 'hit_count' => 0, 'enabled' => $enabled, 'sort' => 0,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    Sec::log('cs_rule_add', $ctx['actor']['nickname'], ['room' => $roomId]);
    Api::json(['ok' => true, 'msg' => '已添加']);
});
Plugin::sensitive('plugin_chat_scripts_admin_rule_save');

Plugin::route('plugin_chat_scripts_admin_rule_del', function (array $ctx) use ($csGuard) {
    $csGuard($ctx);
    csBoot();
    $id = (int)($ctx['post']['id'] ?? 0);
    $row = DB::one('SELECT id FROM plugin_chat_scripts_rule WHERE id=?', [$id]);
    if (!$row) Api::json(['ok' => false, 'msg' => '规则不存在'], 404);
    DB::run('DELETE FROM plugin_chat_scripts_rule WHERE id=?', [$id]);
    Api::json(['ok' => true, 'msg' => '已删除']);
});
Plugin::sensitive('plugin_chat_scripts_admin_rule_del');

/* ---------- 群发任务保存 / 删除（敏感操作） ---------- */
Plugin::route('plugin_chat_scripts_admin_cast_save', function (array $ctx) use ($csGuard) {
    $csGuard($ctx);
    csBoot();
    $post = $ctx['post'];
    $id      = (int)($post['id'] ?? 0);
    $roomId  = (int)($post['room_id'] ?? 0);
    $content = trim((string)($post['content'] ?? ''));
    $mode    = ($post['mode'] ?? '') === 'once' ? 'once' : 'loop';

    if ($content === '' || mb_strlen($content) > 2000) Api::json(['ok' => false, 'msg' => '内容 1-2000 字']);

    $room = DB::one('SELECT * FROM rooms WHERE id=? AND status=1', [$roomId]);
    if (!$room) Api::json(['ok' => false, 'msg' => '请选择群聊']);
    $bot = csBot();
    if (!$bot) Api::json(['ok' => false, 'msg' => '请先在「机器人账号」里设置机器人用户 ID']);
    if (!csRoomOkForBot($room, $bot)) {
        Api::json(['ok' => false, 'msg' => '机器人无法进入该群聊（仅支持公开群聊；密码房需机器人本人是超级管理员）']);
    }

    $now = time();
    if ($mode === 'once') {
        $sendAt = (int)($post['send_at'] ?? 0);
        if ($sendAt < $now + 30) Api::json(['ok' => false, 'msg' => '定时时间至少设为 30 秒以后']);
        $interval = 0;
        $next = $sendAt;
    } else {
        $interval = max(300, min(86400 * 7, (int)($post['interval_sec'] ?? 3600)));
        $sendAt = 0;
        $next = $now + $interval;
    }
    $roomName = mb_substr((string)$room['name'], 0, 190);
    if ($id > 0) {
        DB::run('UPDATE plugin_chat_scripts_cast SET room_id=?, room_name=?, content=?, mode=?, interval_sec=?, send_at=?, next_run_at=?, updated_at=? WHERE id=?',
            [$roomId, $roomName, $content, $mode, $interval, $sendAt, $next, $now, $id]);
        Api::json(['ok' => true, 'msg' => '已保存']);
    }
    DB::insert('plugin_chat_scripts_cast', [
        'room_id' => $roomId, 'room_name' => $roomName, 'content' => $content,
        'mode' => $mode, 'interval_sec' => $interval, 'send_at' => $sendAt,
        'next_run_at' => $next, 'last_run_at' => 0, 'last_status' => '',
        'run_count' => 0, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    Sec::log('cs_cast_add', $ctx['actor']['nickname'], ['room' => $roomId, 'mode' => $mode]);
    Api::json(['ok' => true, 'msg' => '已添加']);
});
Plugin::sensitive('plugin_chat_scripts_admin_cast_save');

Plugin::route('plugin_chat_scripts_admin_cast_del', function (array $ctx) use ($csGuard) {
    $csGuard($ctx);
    csBoot();
    $id = (int)($ctx['post']['id'] ?? 0);
    $row = DB::one('SELECT id FROM plugin_chat_scripts_cast WHERE id=?', [$id]);
    if (!$row) Api::json(['ok' => false, 'msg' => '任务不存在'], 404);
    DB::run('DELETE FROM plugin_chat_scripts_cast WHERE id=?', [$id]);
    Api::json(['ok' => true, 'msg' => '已删除']);
});
Plugin::sensitive('plugin_chat_scripts_admin_cast_del');

/* ---------- 日志清空（敏感操作） ---------- */
Plugin::route('plugin_chat_scripts_admin_log_clear', function (array $ctx) use ($csGuard) {
    $csGuard($ctx);
    csBoot();
    DB::run('DELETE FROM plugin_chat_scripts_log');
    Sec::log('cs_log_clear', $ctx['actor']['nickname'], []);
    Api::json(['ok' => true, 'msg' => '已清空']);
});
Plugin::sensitive('plugin_chat_scripts_admin_log_clear');

/* ============================ 静态资源 ============================ */

Plugin::asset('css', 'chat-scripts/style.css');
Plugin::asset('js', 'chat-scripts/chat.js');      // 前台：话术面板（后台页自动退出）
Plugin::asset('js', 'chat-scripts/admin.js');     // 后台：管理页交互（前台页自动退出）
