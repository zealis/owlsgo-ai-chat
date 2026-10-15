<?php
/**
 * 群聊自动化插件（chat-scripts v1.1.3）
 *
 * 插件显示名 v1.1.0 起为「群聊自动化」（目录名与路由/表名前缀 chat-scripts 保持
 * 不动 —— 那是标识不是文案，改了会丢启用状态、丢数据、断掉已发出去的资源地址）。
 *
 * ── 四个模块 ──────────────────────────────────────────────────
 *   A 快捷话术：工具栏「话术」按钮由本插件的 chat.toolbar 钩子产出（main.php），面板里
 *     「公共话术」（后台维护，全站只读）+「群话术」（群主前台自管）+「我的话术」。
 *     点击条目 = 插入输入框光标处；点「发」= 经 OwChat.send 直接发出。
 *   B 关键词自动回复：message.after_send 钩子按规则匹配，以机器人账号身份
 *     调用核心 Chat::send 回复。防循环 + 双限流，见下方注释。
 *   C 定时群发：Plugin::cron 每分钟扫描到期任务，机器人账号发送；
 *     支持「周期循环」与「一次性定时」两种模式。
 *   D 房间级自动化管理（v1.1.0 新增）：B/C 的管理入口从「只有后台」扩到
 *     「群聊设置 → 自动化」，**群主在自己群里就能配**。auto.js 走
 *     OwChat.onRoomEdit 把入口挂在群公告那一行下面（见 auto.js 文件头）。
 *     v1.1.1：后台那两个页签（自动回复 / 定时群发）连同 admin_rule_* /
 *     admin_cast_* 四条路由一并下线 —— 规则与任务只跟着群走，不再有「全站规则」
 *     这条只有管理员能用的第二通道（界面上没了、接口还留着就是暗门）。
 *     每群上限收到 8 条（规则与任务同为 8），后台只留公共话术 / 机器人账号 / 发送记录。
 *   E AI 应答（v1.1.2 新增）：**只有群里有人 @ 机器人时才生效**，用配置的 OpenAI 兼容
 *     接口回答那个人的问题，以机器人账号身份发出。没人 @ 它就不动用 AI —— 否则每句话
 *     都烧一次调用、还会抢话。网络通道与超时口径照抄 sensitive-words 的「AI 防线」；
 *     密钥只写不读（接口只回掩码），地址强制 https，每日/每人/每房三道闸。
 *     ⚠️ 一次应答是**同步**发生在 message.after_send 里的，也就是说提问者的那次发送会
 *     等到模型回话（默认上限 10 秒）—— 与「AI 防线」在发送路径上等接口是同一回事。
 *
 * ── B/C 的两套作用域（v1.1.0 起）──────────────────────────────
 *   · room_id = 0   全局规则，**只有管理员能建**（后台页）。对所有群生效。
 *   · room_id > 0   某群的规则。管理员在后台能选任意群；群主只能管自己那个群，
 *                   且只能通过 plugin_chat_scripts_room_* 这一组路由 ——
 *                   那组路由把 room_id 钉死在「鉴权通过的那个群」上，
 *                   POST 里没有第二个房间参数可以塞。见 csRoomScope()。
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
 *   8. 房间级路由（D）的三条硬约束见 csRoomScope()：room_id 必须 > 0、
 *      鉴权只看「这个群是不是你的」、写操作强制写进鉴权通过的那个群。
 *      删除/修改一律带 `AND room_id=?` 条件 —— 否则群主改个 id 就能动别人的群（IDOR）。
 *
 * 约束：零依赖；样式只引 --ow-* 变量（见 style.css 头注）；ES5 兼容（chat.js/auto.js/admin.js）；
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

    // A：话术（scope=public 公共 / room 群共享 / user 个人；room 时 owner_id=创建者、room_id=所属群）
    DB::run("CREATE TABLE IF NOT EXISTS plugin_chat_scripts_snip (
        id           {$id},
        scope        {$str} NOT NULL DEFAULT 'user',
        owner_id     {$ts} NOT NULL DEFAULT 0,
        room_id      {$ts} NOT NULL DEFAULT 0,
        group_name   {$str} NOT NULL DEFAULT '未分组',
        title        {$str} NOT NULL DEFAULT '',
        content      TEXT NOT NULL,
        use_count    INTEGER NOT NULL DEFAULT 0,
        sort         INTEGER NOT NULL DEFAULT 0,
        created_at   {$ts} DEFAULT 0,
        updated_at   {$ts} DEFAULT 0)");
    // v1.0.1：群共享话术（scope='room'）后加的列。老库升级走 ensureColumn，
    //         不在 CREATE TABLE 里补 —— IF NOT EXISTS 对已存在的表不生效。
    DB::ensureColumn('plugin_chat_scripts_snip', 'room_id', 'int', "'0'");
    DB::createIndex('idx_cs_snip_scope', 'plugin_chat_scripts_snip', 'scope, owner_id', false);
    DB::createIndex('idx_cs_snip_room', 'plugin_chat_scripts_snip', 'scope, room_id', false);

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
        // E：AI 应答（v1.1.2）。ai_key 只写不读给前端（见 csAiPublic）。
        'ai_on'      => '0',
        'ai_base'    => '',          // OpenAI 兼容地址，必须 https（否则密钥明文出网）
        'ai_model'   => 'gpt-4o-mini',
        'ai_key'     => '',
        'ai_prompt'  => '',          // 空 = 用 CS_AI_PROMPT 默认（清空即恢复默认）
        'ai_timeout' => '10',
        'ai_daily'   => '200',       // 每日调用上限（0 = 不限量）
        'ai_ctx'     => '0',         // 带几条最近的群聊消息进提示词（0 = 只带这一条提问）
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

/**
 * 群话术管理权（v1.0.1）：群主本人，或超级管理员（与群主同权——
 * 群是站长的站点的一部分，超管在任何一个群里都等同于「能管这个群」）。
 * 游客、普通成员、非本人一律 false。
 */
function csRoomAdmin(int $roomId, array $actor): bool
{
    if ($roomId <= 0) return false;
    if (($actor['kind'] ?? '') !== 'user') return false;
    if (($actor['role'] ?? '') === 'admin') return true;
    $room = DB::one('SELECT owner_id FROM rooms WHERE id=? AND status=1', [$roomId]);
    return $room && (int)$room['owner_id'] === (int)$actor['id'];
}

/* ============================ A：快捷话术（前台路由） ============================ */

/** 话术列表：公共（全站只读）+ 群共享（进得去该群才可见）+ 我的（登录用户）；游客只看公共与群共享 */
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
    // v1.0.1 群共享话术：只有「进得去这个群」的人才拉得到（与能否在里面发言同源，
    // 密码房要求会话里有通行授权——群主/成员进得去就看得见，外人连房间都进不去）。
    // room_admin 告诉前端「这个人可以在前台管理群话术」（群主/超管）。
    $room = []; $roomAdmin = 0;
    $roomId = (int)($ctx['post']['room_id'] ?? 0);
    if ($roomId > 0) {
        $row = DB::one('SELECT * FROM rooms WHERE id=? AND status=1', [$roomId]);
        if ($row && Chat::roomAccessOk($row, $actor)) {
            $room = DB::all("SELECT id, scope, group_name, title, content, use_count
                FROM plugin_chat_scripts_snip WHERE scope='room' AND room_id=? ORDER BY sort ASC, id ASC",
                [$roomId]);
        }
        if (csRoomAdmin($roomId, $actor)) $roomAdmin = 1;
    }
    Api::json(['ok' => true, 'public' => $pub, 'room' => $room, 'room_admin' => $roomAdmin,
        'mine' => $mine, 'guest' => $uid > 0 ? 0 : 1]);
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

/* ---------- 群共享话术：群主 / 超管在前台直接增删改（v1.0.1） ---------- */

/** 群话术保存（仅群主与超管；room_id 必须真有这个群） */
Plugin::route('plugin_chat_scripts_room_snip_save', function (array $ctx) {
    csBoot();
    $actor = $ctx['actor'];
    $post = $ctx['post'];
    $roomId = (int)($post['room_id'] ?? 0);
    if (!csRoomAdmin($roomId, $actor)) Api::json(['ok' => false, 'msg' => '仅群主可以管理群话术'], 403);
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
        $row = DB::one("SELECT id FROM plugin_chat_scripts_snip WHERE id=? AND scope='room' AND room_id=?", [$id, $roomId]);
        if (!$row) Api::json(['ok' => false, 'msg' => '群话术不存在或不属于该群'], 403);
        DB::run('UPDATE plugin_chat_scripts_snip SET group_name=?, title=?, content=?, updated_at=? WHERE id=?',
            [$group, $title, $content, $now, $id]);
        Api::json(['ok' => true, 'msg' => '已保存']);
    }
    // 每群上限 100 条，防无限灌数据
    $cnt = (int)DB::val("SELECT COUNT(*) FROM plugin_chat_scripts_snip WHERE scope='room' AND room_id=?", [$roomId]);
    if ($cnt >= 100) Api::json(['ok' => false, 'msg' => '本群话术最多 100 条，请先清理']);
    DB::insert('plugin_chat_scripts_snip', [
        'scope' => 'room', 'owner_id' => (int)$actor['id'], 'room_id' => $roomId,
        'group_name' => $group, 'title' => $title, 'content' => $content,
        'sort' => 0, 'use_count' => 0, 'created_at' => $now, 'updated_at' => $now,
    ]);
    Api::json(['ok' => true, 'msg' => '已添加']);
});

/** 删除群话术（敏感操作；仅群主与超管） */
Plugin::route('plugin_chat_scripts_room_snip_del', function (array $ctx) {
    csBoot();
    $actor = $ctx['actor'];
    $id = (int)($ctx['post']['id'] ?? 0);
    $row = DB::one("SELECT id, room_id FROM plugin_chat_scripts_snip WHERE id=? AND scope='room'", [$id]);
    if (!$row) Api::json(['ok' => false, 'msg' => '群话术不存在'], 404);
    if (!csRoomAdmin((int)$row['room_id'], $actor)) Api::json(['ok' => false, 'msg' => '仅群主可以管理群话术'], 403);
    DB::run('DELETE FROM plugin_chat_scripts_snip WHERE id=?', [$id]);
    Api::json(['ok' => true, 'msg' => '已删除']);
});
Plugin::sensitive('plugin_chat_scripts_room_snip_del');

/** 使用计数 +1（插入/发送时调用；限流防刷） */
Plugin::route('plugin_chat_scripts_use', function (array $ctx) {
    csBoot();
    $actor = $ctx['actor'];
    if (!Sec::rateLimit('cs_use', ($actor['kind'] ?? 'g') . ($actor['id'] ?? 0), 60, 60)) {
        Api::json(['ok' => true]);   // 限流就静默成功：计数只是统计，不该打扰用户
    }
    $id = (int)($ctx['post']['id'] ?? 0);
    $row = DB::one('SELECT id, scope, owner_id, room_id FROM plugin_chat_scripts_snip WHERE id=?', [$id]);
    if (!$row) Api::json(['ok' => false, 'msg' => '话术不存在'], 404);
    $scope = (string)($row['scope'] ?? 'user');
    if ($scope === 'user') {
        // 个人话术只能由本人计数
        if (($actor['kind'] ?? '') !== 'user' || (int)$actor['id'] !== (int)$row['owner_id']) {
            Api::json(['ok' => false, 'msg' => '无权使用'], 403);
        }
    } elseif ($scope === 'room') {
        // 群共享话术：只有进得去该群的人才能用（与列表接口同一口径）
        $rm = DB::one('SELECT * FROM rooms WHERE id=? AND status=1', [(int)$row['room_id']]);
        if (!$rm || !Chat::roomAccessOk($rm, $actor)) {
            Api::json(['ok' => false, 'msg' => '无权使用'], 403);
        }
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

    // v1.1.2：被 @ 到时优先走 AI。理由很实在 —— 群里 @ 机器人就是冲着它来的，
    // 这时候再回一句关键词规则配好的固定话术，等于把人打发了。
    // 反过来，没人 @ 它时不该动用 AI（那会变成每句话都烧一次调用、还抢话），
    // 所以关键词规则保持原样兜底。AI 自己失败时也不再退到关键词：
    // 一条消息两次应答比没有应答更吵。
    if (csAiOn() && csAiMentioned((string)$content, (string)$bot['nickname'])) {
        csAiAnswer((int)$roomId, $actor, (string)$content, $bot);
        return;
    }

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
}, '群聊自动化：按计划用机器人账号在群聊里发送预设消息（每分钟检查一次到期任务；发送走核心 Chat::send，与普通消息同样受敏感词/禁言/频率限制）');

/* ============================ D：房间级自动化（群主在前台自管本群，v1.1.0） ============================ */

/**
 * 单个群的自动化条数上限（防群主灌数据把插件表撑大）。
 * v1.1.1：自动回复 20→8、定时群发 10→8 —— 8 条够一个群日常用，
 * 且两个数字相同，前端文案与接口都只需一个概念。值同时下发给前端做文案。
 */
function csRoomRuleCap(): int { return 8; }
function csRoomCastCap(): int { return 8; }

/**
 * 机器人能否真的在本群发出消息。三项都要过：
 *   ① 站点配了机器人账号且它是启用状态；
 *   ② 机器人进得去这个群（公开性 / 房型 / 角色等级，与保存时复核同一口径）；
 *   ③ 机器人是**本群成员** —— Chat::send 对注册用户强制要求「已加入该群聊后再发言」，
 *      而群主与超管才天生算成员，机器人账号两者都不是。
 * 任何一项不过就带人话直接结束请求：群主配完规则等半天没反应，比当场被拒还难受。
 */
function csBotReadyForRoom(array $room): array
{
    $bot = csBot();
    if (!$bot) Api::json(['ok' => false, 'msg' => '站长还没有配置机器人账号，自动化暂时用不了']);
    if (!csRoomOkForBot($room, $bot)) {
        Api::json(['ok' => false, 'msg' => '机器人进不了本群（只支持公开群聊；密码房需要机器人本人是超级管理员，角色房按角色等级判定）']);
    }
    $botActor = ['kind' => 'user', 'id' => (int)$bot['id'], 'role' => (string)$bot['role']];
    if (!Chat::isMember($room, $botActor)) {
        Api::json(['ok' => false, 'msg' => '机器人还不是本群成员，请先点「让机器人加入本群」']);
    }
    return $bot;
}

/**
 * 房间级自动化的公共守卫。返回**鉴权通过的那个群行**，调用方后续一律以它的 id 为目标。
 *
 * ⚠️ 这是本插件 v1.1.0 最关键的一段，三条硬约束缺一不可：
 *   1. room_id 必须 > 0。0 是「全部群聊」的全局作用域，**只属于管理员**；
 *      群主传 0 一律拒 —— 否则他能给全站每一个群都挂上规则。
 *   2. 权限判定只信服务端：csRoomAdmin() 查的是 rooms.owner_id 是不是这个 actor
 *      （或 actor 是超管），与请求体里写什么都不相干。
 *   3. 返回的 $room 就是唯一允许操作的目标群；改动/删除 SQL 必须带 `AND room_id=?`
 *      条件，否则群主改个 id 就能动别的群的规则（IDOR）。
 *
 * $write=true 时叠加写入限流：挡住群主脚本化刷接口灌规则（30 次/分钟）。
 */
function csRoomScope(array $ctx, bool $write = false): array
{
    $actor  = $ctx['actor'];
    $roomId = (int)($ctx['post']['room_id'] ?? 0);
    if ($roomId <= 0) Api::json(['ok' => false, 'msg' => '缺少群聊 ID'], 400);
    $room = DB::one('SELECT * FROM rooms WHERE id=? AND status=1', [$roomId]);
    if (!$room) Api::json(['ok' => false, 'msg' => '群聊不存在或已关闭'], 404);
    if (!csRoomAdmin($roomId, $actor)) Api::json(['ok' => false, 'msg' => '仅群主可以管理本群的自动化'], 403);
    if ($write && !Sec::rateLimit('cs_room_write', ($actor['kind'] ?? 'g') . (int)($actor['id'] ?? 0), 60, 30)) {
        Api::json(['ok' => false, 'msg' => '操作太频繁了，稍等一下再试'], 429);
    }
    return $room;
}

/** 本群自动化的当前状态：机器人可用性 + 本群规则/任务 + 站点总开关 */
Plugin::route('plugin_chat_scripts_room_auto', function (array $ctx) {
    csBoot();
    $room = csRoomScope($ctx);
    $roomId = (int)$room['id'];
    $bot = csBot();
    $botOk = ($bot && csRoomOkForBot($room, $bot)) ? 1 : 0;
    $botMember = 0;
    if ($botOk) {
        $botMember = Chat::isMember($room, [
            'kind' => 'user', 'id' => (int)$bot['id'], 'role' => (string)$bot['role'],
        ]) ? 1 : 0;
    }
    Api::json([
        'ok'   => true,
        'room' => ['id' => $roomId, 'name' => (string)$room['name']],
        'bot'  => $bot ? ['id' => (int)$bot['id'], 'nickname' => (string)$bot['nickname'], 'role' => (string)$bot['role']] : null,
        'bot_ok'     => $botOk,
        'bot_member' => $botMember,
        // 站点把「每房每分钟自动回复上限」设成 0 = 全站关闭自动回复。那是站长的总闸，
        // 群主不能反着开；如实告诉群主，别让他对着自己的规则反复找问题。
        'site_off' => csCfgInt('reply_limit') <= 0 ? 1 : 0,
        // 后台还有全局规则（room_id=0）在本群同样生效，条数给前端做只读提示
        'global_rules' => (int)DB::val('SELECT COUNT(*) FROM plugin_chat_scripts_rule WHERE room_id=0 AND enabled=1'),
        'limits' => ['rule' => csRoomRuleCap(), 'cast' => csRoomCastCap()],
        // ⚠️ 只取 room_id=? 的行：全局规则不在群主的管理范围里，一条都不能给他
        'rules' => DB::all('SELECT * FROM plugin_chat_scripts_rule WHERE room_id=? ORDER BY sort ASC, id ASC', [$roomId]),
        'casts' => DB::all('SELECT * FROM plugin_chat_scripts_cast WHERE room_id=? ORDER BY id DESC', [$roomId]),
    ]);
});

/**
 * 让机器人加入本群（仅本群群主/超管）。
 * 为什么群主需要这个按钮：Chat::send 要求注册用户「已加入该群聊」才能发言，
 * 机器人账号不是群主也不是超管，不加进来它一条都发不出（公开群也一样）。
 * 口径与 csRoomOkForBot 一致：只处理公开群，其余情况让它去后台办。
 */
Plugin::route('plugin_chat_scripts_room_bot_join', function (array $ctx) {
    csBoot();
    $room = csRoomScope($ctx, true);
    $bot = csBot();
    if (!$bot) Api::json(['ok' => false, 'msg' => '站长还没有配置机器人账号']);
    if (!csRoomOkForBot($room, $bot)) Api::json(['ok' => false, 'msg' => '机器人进不了本群（只支持公开群聊）']);
    $roomId = (int)$room['id'];
    // 先查后插：room_members 的唯一约束随核心版本演变，不能依赖 INSERT IGNORE 去重
    $has = (int)DB::val('SELECT 1 FROM room_members WHERE room_id=? AND user_id=?', [$roomId, (int)$bot['id']]);
    if ($has) Api::json(['ok' => true, 'msg' => '机器人已在本群中']);
    DB::insert('room_members', [
        'room_id' => $roomId, 'user_id' => (int)$bot['id'],
        'invited_by' => 0, 'created_at' => time(),
    ]);
    Sec::log('cs_room_bot_join', $ctx['actor']['nickname'], ['room' => $roomId, 'bot' => (int)$bot['id']]);
    Api::json(['ok' => true, 'msg' => '机器人已加入本群']);
});
Plugin::sensitive('plugin_chat_scripts_room_bot_join');

/** 自动回复规则：保存（新增 / 修改本群的规则）。校验口径与后台那条完全一致 —— 同一套规则不该有两套标准 */
Plugin::route('plugin_chat_scripts_room_rule_save', function (array $ctx) {
    csBoot();
    $room = csRoomScope($ctx, true);
    $roomId = (int)$room['id'];
    $post = $ctx['post'];
    $id   = (int)($post['id'] ?? 0);

    $mode = ($post['match_mode'] ?? '') === 'exact' ? 'exact' : 'include';
    $kws  = json_decode((string)($post['keywords'] ?? ''), true);
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

    csBotReadyForRoom($room);      // 机器人发不出话就先说清，别让群主配完才发现不生效

    $enabled = (int)($post['enabled'] ?? 1) ? 1 : 0;
    $now = time();
    $kwJson = json_encode($clean, JSON_UNESCAPED_UNICODE);
    if ($id > 0) {
        // ⚠️ `AND room_id=?` 不能省：少了它，群主改个 id 就能改别人群的规则（IDOR）
        $row = DB::one('SELECT id FROM plugin_chat_scripts_rule WHERE id=? AND room_id=?', [$id, $roomId]);
        if (!$row) Api::json(['ok' => false, 'msg' => '规则不存在或不属于本群'], 403);
        // room_id 不参与 UPDATE：规则不能被改挂到别的群去
        DB::run('UPDATE plugin_chat_scripts_rule SET keywords=?, match_mode=?, reply=?, enabled=?, updated_at=? WHERE id=?',
            [$kwJson, $mode, $reply, $enabled, $now, $id]);
        Api::json(['ok' => true, 'msg' => '已保存']);
    }
    $cnt = (int)DB::val('SELECT COUNT(*) FROM plugin_chat_scripts_rule WHERE room_id=?', [$roomId]);
    if ($cnt >= csRoomRuleCap()) Api::json(['ok' => false, 'msg' => '本群自动回复最多 ' . csRoomRuleCap() . ' 条，请先清理']);
    DB::insert('plugin_chat_scripts_rule', [
        'room_id' => $roomId, 'keywords' => $kwJson, 'match_mode' => $mode,
        'reply' => $reply, 'hit_count' => 0, 'enabled' => $enabled, 'sort' => 0,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    Sec::log('cs_room_rule_add', $ctx['actor']['nickname'], ['room' => $roomId]);
    Api::json(['ok' => true, 'msg' => '已添加']);
});
Plugin::sensitive('plugin_chat_scripts_room_rule_save');

/** 自动回复规则：删除（敏感操作；只能删本群的） */
Plugin::route('plugin_chat_scripts_room_rule_del', function (array $ctx) {
    csBoot();
    $room = csRoomScope($ctx, true);
    $id = (int)($ctx['post']['id'] ?? 0);
    $row = DB::one('SELECT id FROM plugin_chat_scripts_rule WHERE id=? AND room_id=?', [$id, (int)$room['id']]);
    if (!$row) Api::json(['ok' => false, 'msg' => '规则不存在或不属于本群'], 403);
    DB::run('DELETE FROM plugin_chat_scripts_rule WHERE id=?', [$id]);
    Api::json(['ok' => true, 'msg' => '已删除']);
});
Plugin::sensitive('plugin_chat_scripts_room_rule_del');

/** 定时群发任务：保存（目标群恒为本群，前端根本没有第二个房间参数可以传） */
Plugin::route('plugin_chat_scripts_room_cast_save', function (array $ctx) {
    csBoot();
    $room = csRoomScope($ctx, true);
    $roomId = (int)$room['id'];
    $post = $ctx['post'];
    $id      = (int)($post['id'] ?? 0);
    $content = trim((string)($post['content'] ?? ''));
    $mode    = ($post['mode'] ?? '') === 'once' ? 'once' : 'loop';
    if ($content === '' || mb_strlen($content) > 2000) Api::json(['ok' => false, 'msg' => '内容 1-2000 字']);

    csBotReadyForRoom($room);

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
    if ($id > 0) {
        // ⚠️ 同样必须带 `AND room_id=?`：否则群主能改别的群的任务
        $row = DB::one('SELECT id FROM plugin_chat_scripts_cast WHERE id=? AND room_id=?', [$id, $roomId]);
        if (!$row) Api::json(['ok' => false, 'msg' => '任务不存在或不属于本群'], 403);
        // room_id / room_name 不参与 UPDATE：任务不能改挂到别的群
        DB::run('UPDATE plugin_chat_scripts_cast SET content=?, mode=?, interval_sec=?, send_at=?, next_run_at=?, updated_at=? WHERE id=?',
            [$content, $mode, $interval, $sendAt, $next, $now, $id]);
        Api::json(['ok' => true, 'msg' => '已保存']);
    }
    $cnt = (int)DB::val('SELECT COUNT(*) FROM plugin_chat_scripts_cast WHERE room_id=?', [$roomId]);
    if ($cnt >= csRoomCastCap()) Api::json(['ok' => false, 'msg' => '本群定时群发最多 ' . csRoomCastCap() . ' 条，请先清理']);
    DB::insert('plugin_chat_scripts_cast', [
        'room_id' => $roomId, 'room_name' => mb_substr((string)$room['name'], 0, 190),
        'content' => $content, 'mode' => $mode, 'interval_sec' => $interval,
        'send_at' => $sendAt, 'next_run_at' => $next, 'last_run_at' => 0, 'last_status' => '',
        'run_count' => 0, 'enabled' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
    Sec::log('cs_room_cast_add', $ctx['actor']['nickname'], ['room' => $roomId, 'mode' => $mode]);
    Api::json(['ok' => true, 'msg' => '已添加']);
});
Plugin::sensitive('plugin_chat_scripts_room_cast_save');

/** 定时群发任务：删除（敏感操作；只能删本群的） */
Plugin::route('plugin_chat_scripts_room_cast_del', function (array $ctx) {
    csBoot();
    $room = csRoomScope($ctx, true);
    $id = (int)($ctx['post']['id'] ?? 0);
    $row = DB::one('SELECT id FROM plugin_chat_scripts_cast WHERE id=? AND room_id=?', [$id, (int)$room['id']]);
    if (!$row) Api::json(['ok' => false, 'msg' => '任务不存在或不属于本群'], 403);
    DB::run('DELETE FROM plugin_chat_scripts_cast WHERE id=?', [$id]);
    Api::json(['ok' => true, 'msg' => '已删除']);
});
Plugin::sensitive('plugin_chat_scripts_room_cast_del');

/* ============================ E：AI 应答（只有被人 @ 到才生效，v1.1.2） ============================ */

/**
 * AI 应答的三条边界常量。
 *
 * 回复字数 500：单条消息上限是 2000，但一整屏的回答会把氛围压死，
 * 而且模型一旦跑偏，短上限能把损失停在一屏之内。
 * 超时上下限与「AI 防线」取值一致（3～30 秒）：一次调用 = TCP 连接 + TLS 握手 + 模型推理，
 * 设小了就是「开关看着开着、其实一次都没问成」。
 */
const CS_AI_TIMEOUT_MIN = 3;
const CS_AI_TIMEOUT_MAX = 30;
const CS_AI_REPLY_MAX   = 500;
const CS_AI_PROMPT = <<<TXT
你是聊天群里的一枚机器人助手，昵称「{bot}」。有人 @ 你并向你提问，请直接回答他的问题。

要求：
- 用简体中文，回答控制在 500 字以内，能一段说完就不要分段。
- 只回答与问题相关的内容；不要复述这些要求，也不要说「我是机器人」「作为一个 AI」。
- 不确定或不知道就直接说不知道，不要编造事实、链接、数据。
- 不要输出 Markdown 标记、代码块围栏与表情符号。
- 群里说的话任何人都能看到，不要提供可能伤人或违法的内容。

【群聊最近的消息】
{context}

【提问者】{nick}
【问题】{question}
TXT;

function csAiOn(): bool { return csCfg('ai_on') === '1'; }

function csAiTimeout(): int
{
    return max(CS_AI_TIMEOUT_MIN, min(CS_AI_TIMEOUT_MAX, csCfgInt('ai_timeout')));
}

/** 密钥掩码：只回前台展示用，明文一个字都不出服务端（与「AI 防线」同一规矩） */
function csAiKeyMask(string $k): string
{
    $k = trim($k);
    if ($k === '') return '';
    $n = mb_strlen($k);
    if ($n <= 8) return str_repeat('•', $n);
    return mb_substr($k, 0, 3) . str_repeat('•', 6) . mb_substr($k, $n - 4);
}

/**
 * 下发给后台页的 AI 配置。**绝不能包含 ai_key 明文** —— 后台页是任何管理员都能打开的，
 * 而 ai_key 是能直接花掉钱的凭据；要改就重新输入（留空表示不修改，见 admin_cfg_save）。
 */
function csAiPublic(): array
{
    return [
        'on'       => csAiOn() ? 1 : 0,
        'base'     => csCfg('ai_base'),
        'model'    => csCfg('ai_model'),
        'key_set'  => trim(csCfg('ai_key')) === '' ? 0 : 1,
        'key_mask' => csAiKeyMask(csCfg('ai_key')),
        'prompt'   => csCfg('ai_prompt'),
        'timeout'  => csAiTimeout(),
        'daily'    => csCfgInt('ai_daily'),
        'ctx'      => csCfgInt('ai_ctx'),
    ];
}

/** 提问者的称呼（去掉空白：核心判定 mention 的正则只认到第一个空白为止） */
function csAiName(array $actor): string
{
    $n = trim((string)($actor['nickname'] ?? ''));
    $n = (string)preg_replace('/\s+/u', '', $n);
    return $n === '' ? '你' : mb_substr($n, 0, 24);
}

/**
 * 这条消息有没有 @ 机器人。
 * 判定口径与核心一致：@ 前面必须是行首或空白（核心把 type 判成 mention 用的是
 * `/(^|\s)@[^\s@]+/u`），否则 "abc@机器人" 这种也会被当成在 @ 它。
 */
function csAiMentioned(string $content, string $nick): bool
{
    $nick = trim($nick);
    if ($nick === '' || $content === '') return false;
    $at = '@' . $nick;
    $len = mb_strlen($at);
    $pos = 0;
    while (($p = mb_stripos($content, $at, $pos)) !== false) {
        $before = $p > 0 ? mb_substr($content, $p - 1, 1) : '';
        if ($before === '' || preg_match('/\s/u', $before) === 1) return true;
        $pos = $p + $len;
    }
    return false;
}

/** 把 @机器人 那一段从原文里摘掉，剩下真正的问题 */
function csAiQuestion(string $content, string $nick): string
{
    $at = '@' . trim($nick);
    $p = mb_stripos($content, $at);
    if ($p === false) return trim($content);
    return trim(mb_substr($content, 0, $p) . mb_substr($content, $p + mb_strlen($at)));
}

/**
 * 最近的群聊消息，作为上下文塞进提示词（ai_ctx 条，默认 0 = 只带这一条提问）。
 * ⚠️ 默认是关的：一旦开启，群里的发言会被送到第三方接口去。开不开由站长在后台决定，
 *    并且提示词里写明这是群聊内容。已撤回与已删除的消息一律不取。
 */
function csAiContext(int $roomId, int $n): string
{
    if ($roomId <= 0 || $n <= 0) return '';
    $n = min(20, $n);
    $rows = DB::all("SELECT nickname, content, type FROM messages
        WHERE room_id=? AND deleted=0 AND recalled=0 ORDER BY id DESC LIMIT $n", [$roomId]);
    if (!$rows) return '';
    $out = '';
    foreach (array_reverse($rows) as $r) {
        $t = (string)($r['type'] ?? '');
        if ($t !== 'text' && $t !== 'mention') continue;
        $out .= mb_substr((string)$r['nickname'], 0, 24) . '：' . mb_substr((string)$r['content'], 0, 200) . "\n";
    }
    return $out;
}

/** 拼最终提示词：占位符 {bot} {nick} {question} {context} */
function csAiPrompt(string $question, array $actor, array $bot, int $roomId): string
{
    $tpl = trim(csCfg('ai_prompt'));
    if ($tpl === '') $tpl = CS_AI_PROMPT;
    $ctx = csAiContext($roomId, csCfgInt('ai_ctx'));
    return str_replace(
        ['{bot}', '{nick}', '{question}', '{context}'],
        [
            mb_substr((string)($bot['nickname'] ?? ''), 0, 24),
            csAiName($actor),
            mb_substr($question, 0, 1000),
            $ctx === '' ? '（无）' : $ctx,
        ],
        $tpl
    );
}

/** 额度闸门：默认每天 200 次，防止一个失控的群把账单打爆（0 = 不限量） */
function csAiQuotaOk(): bool
{
    $daily = csCfgInt('ai_daily');
    if ($daily <= 0) return true;
    return Sec::rateLimit('cs_ai_day', 'all', 86400, $daily);
}

/**
 * 问一次模型。返回 ['ok'=>bool,'text'=>string,'err'=>string]，
 * 任何一步不对都 ok=false + 一句人话原因 —— 调用方记日志后静默放过，绝不让接口故障影响聊天。
 *
 * 网络通道与「AI 防线」同一套：优先 curl，没有就回落 allow_url_fopen；
 * ⚠️ 地址必须 https（密钥不能明文出网）；连接超时与总超时分开设 ——
 *    「地址根本不通」不该也等满 30 秒。
 */
function csAiAsk(string $prompt): array
{
    $fail = function (string $err): array { return ['ok' => false, 'text' => '', 'err' => $err]; };

    $base  = trim(csCfg('ai_base'));
    $model = trim(csCfg('ai_model'));
    $key   = trim(csCfg('ai_key'));
    if ($base === '' || $model === '') return $fail('未配置接口地址或模型');
    if (stripos($base, 'https://') !== 0) return $fail('接口地址必须是 https（否则密钥明文出网）');

    $body = json_encode([
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.6,
    ], JSON_UNESCAPED_UNICODE);
    if ($body === false) return $fail('请求体编码失败');

    $hdr = ['Content-Type: application/json', 'Accept: application/json'];
    if ($key !== '') $hdr[] = 'Authorization: Bearer ' . $key;
    $timeout = csAiTimeout();
    $connTo = min(5, $timeout);
    $url = rtrim($base, '/') . '/chat/completions';

    $raw = '';
    $ch = function_exists('curl_init') ? curl_init($url) : null;
    if ($ch) {
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $hdr,
            CURLOPT_CONNECTTIMEOUT => $connTo,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if (class_exists('Upgrade')) {
            $ca = Upgrade::caBundle();
            if ($ca !== null) curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $cerr = $resp === false ? curl_error($ch) : '';
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($resp === false) {
            return $fail('接口请求失败：' . mb_substr($errno ? ('curl ' . $errno . ' ' . $cerr) : '未知原因', 0, 160));
        }
        if ($code !== 200) return $fail('HTTP ' . $code . '：' . mb_substr((string)$resp, 0, 160));
        $raw = (string)$resp;
    } else {
        if (!ini_get('allow_url_fopen')) return $fail('无 curl 且 allow_url_fopen 已关闭');
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $hdr) . "\r\n",
            'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true,
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) return $fail('接口请求失败（无 curl 时的回落通道也不通）');
        $raw = (string)$resp;
    }

    // 有些网关会在 JSON 前面带 BOM 或空行，先清掉再解
    $j = json_decode(trim((string)preg_replace('/^\xEF\xBB\xBF/', '', trim($raw))), true);
    if (!is_array($j)) return $fail('响应不是 JSON：' . mb_substr($raw, 0, 160));
    if (isset($j['error']) && is_array($j['error'])) {
        return $fail('接口报错：' . mb_substr((string)($j['error']['message'] ?? ''), 0, 160));
    }
    $text = trim((string)($j['choices'][0]['message']['content'] ?? ''));
    if ($text === '') return $fail('响应里没有内容：' . mb_substr($raw, 0, 160));
    // 模型爱用 ``` 包一层，把围栏去掉；正文本身照原样发出去（群里没有 Markdown 渲染）
    $text = trim((string)preg_replace('/^```[a-zA-Z]*\s*|\s*```$/u', '', $text));
    if ($text === '') return $fail('响应内容为空：' . mb_substr($raw, 0, 160));
    if (mb_strlen($text) > CS_AI_REPLY_MAX) $text = mb_substr($text, 0, CS_AI_REPLY_MAX);
    return ['ok' => true, 'text' => $text, 'err' => ''];
}

/**
 * 被 @ 时的一次完整应答：过闸 → 问模型 → 以机器人身份发出。
 * 任何一步失败都只记日志、不打扰群里的人（聊天不该被一个外部接口拖累）。
 */
function csAiAnswer(int $roomId, array $actor, string $content, array $bot): void
{
    $room = DB::one('SELECT * FROM rooms WHERE id=? AND status=1', [$roomId]);
    if (!$room) return;
    $botActor = csBotActor();
    if (!$botActor) { csLog('ai', $roomId, 0, $content, false, '机器人账号不可用'); return; }
    if (!csRoomOkForBot($room, $bot)) {
        csLog('ai', $roomId, 0, $content, false, '机器人进不了本群（只支持公开群聊）'); return;
    }
    if (!Chat::isMember($room, $botActor)) {
        csLog('ai', $roomId, 0, $content, false, '机器人还不是本群成员'); return;
    }

    // 三道闸：① 每日总量（护账单）② 同一人每分钟（防一个人刷）③ 同一房每分钟（防一群刷）
    if (!csAiQuotaOk()) { csLog('ai', $roomId, 0, $content, false, '已达每日调用上限'); return; }
    $who = ($actor['kind'] ?? 'g') . (int)($actor['id'] ?? 0);
    if (!Sec::rateLimit('cs_ai_u', $who, 60, 3)) {
        csLog('ai', $roomId, 0, $content, false, '同一人一分钟内最多问 3 次'); return;
    }
    if (!Sec::rateLimit('cs_ai_r', 'r' . $roomId, 60, 10)) {
        csLog('ai', $roomId, 0, $content, false, '本群一分钟内的提问太多'); return;
    }

    // 只 @ 了一下、后面什么都没写：不值得花一次调用
    $question = csAiQuestion($content, (string)($bot['nickname'] ?? ''));
    if ($question === '') return;

    $r = csAiAsk(csAiPrompt($question, $actor, $bot, $roomId));
    if (!$r['ok']) {
        csLog('ai', $roomId, 0, $content, false, mb_substr((string)$r['err'], 0, 190));
        return;
    }
    // 回复前面带上提问者的 @：一来他知道这条是回他的，二来核心会把它判成 mention（高亮）
    $reply = '@' . csAiName($actor) . ' ' . (string)$r['text'];
    if (mb_strlen($reply) > 2000) $reply = mb_substr($reply, 0, 2000);
    $ok = false; $msg = '';
    try {
        [$ok, $msg] = Chat::send($botActor, $roomId, 'text', $reply);
    } catch (Throwable $e) {
        $ok = false; $msg = $e->getMessage();
    }
    csLog('ai', $roomId, 0, $reply, (bool)$ok, (string)$msg);
}

/* ============================ 后台 ============================ */

/** 管理员鉴权：插件路由的公共守卫（核心不会替插件校验权限） */
$csGuard = function (array $ctx): void {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
};

Plugin::adminPage('chat-scripts', '群聊自动化', function () {
    return '<h2>群聊自动化</h2>'
        . '<p class="ow-admin-desc">机器人自动发送的消息与普通用户消息走完全相同的服务端校验（敏感词、禁言、频率限制）。'
        . '<b>自动回复与定时群发不在这里 —— 它们跟着群走：</b>入口是「群聊设置 → 自动化」，群主在自己群里直接管本群规则，'
        . '且只能管本群（每个群最多 8 条自动回复、8 条定时群发）。'
        . '这个后台页管三件事：公共话术、机器人账号、发送记录。</p>'
        . '<div class="ow-cs-tabs">'
        . '<button class="ow-btn ow-cs-tab is-active" type="button" data-tab="snip">公共话术</button>'
        . '<button class="ow-btn ow-cs-tab" type="button" data-tab="bot">机器人账号</button>'
        . '<button class="ow-btn ow-cs-tab" type="button" data-tab="log">发送记录</button>'
        . '</div>'

        /* ---- 公共话术（通用列表：筛选 + 批量 + 分页，与登录日志同一套轮子） ---- */
        . '<div class="ow-cs-pane" id="owCsPane-snip">'
        . '<div class="ow-card">'
        . '<p style="font-size:12px">全站用户在话术面板里都能看到并使用，仅管理员可维护。个人话术由用户在前台自行管理，不在这里出现。</p>'
        . '<div class="ow-form-row">'
        . '<div class="ow-form-item" style="flex:1;min-width:180px"><label>关键词</label>'
        . '<input class="ow-input" id="owCsSnipQ" placeholder="标题 / 内容 / 分组" onkeydown="if(event.key===\'Enter\')OwCSAdmin.snipLoad(1)"></div>'
        . '<button class="ow-btn ow-btn-primary" type="button" onclick="OwCSAdmin.snipLoad(1)">搜索</button>'
        . '<button class="ow-btn ow-btn-ghost" type="button" onclick="OwCSAdmin.snipReset()">重置</button>'
        . '<button class="ow-btn" type="button" onclick="OwCSAdmin.snipEdit(0)">新增话术</button>'
        . '</div></div>'
        . '<div class="ow-card">'
        . '<div class="ow-cs-toolbar"><span class="ow-cs-count" id="owCsSnipStat"></span></div>'
        . '<div id="owCsSnipBatch"></div>'
        . '<div class="ow-table-wrap"><table class="ow-table" id="owCsSnipTable">'
        . '<tr><th style="width:32px"><input type="checkbox" id="owCsSnipCheckAll" onchange="OwCSAdmin.snipToggleAll(this)"></th>'
        . '<th>ID</th><th>标题</th><th>分组</th><th>内容</th><th>被使用</th></tr>'
        . '</table></div>'
        . '<div id="owCsSnipPager"></div>'
        . '</div></div>'

        /* ---- 机器人账号（v1.1.1：从原「自动回复」页里独立出来，顶替它成为一个页签） ---- */
        . '<div class="ow-cs-pane" id="owCsPane-bot" style="display:none">'
        . '<div class="ow-card"><h3 style="margin-bottom:10px">机器人账号</h3>'
        . '<p style="font-size:12px">自动回复与定时群发都以该账号身份发消息，和普通用户消息同样经过敏感词、禁言、频率限制。'
        . '建议注册一个专用账号；不要使用超级管理员账号（它发的每条消息都会带管理员标识）。'
        . '<br>机器人必须是该群成员才能发言 —— 公开群由群主在「群聊设置 → 自动化」里点「让机器人加入本群」，'
        . '也可用下面的按钮一次性加入所有公开群。</p>'
        . '<div class="ow-form-row">'
        . '<div class="ow-form-item"><label>机器人用户 ID</label><input class="ow-input" id="owCsBotUid" style="width:120px" placeholder="如 12"></div>'
        . '<div class="ow-form-item"><label>每房每分钟回复上限</label><input class="ow-input" id="owCsReplyLimit" style="width:90px"></div>'
        . '<div class="ow-form-item"><label>同规则冷却（秒）</label><input class="ow-input" id="owCsCool" style="width:90px"></div>'
        . '<button class="ow-btn ow-btn-primary" type="button" onclick="OwCSAdmin.cfgSave()">保存设置</button>'
        . '<button class="ow-btn" type="button" onclick="OwCSAdmin.botJoin()">加入所有公开群</button>'
        . '</div><div id="owCsBotInfo" style="margin-top:8px"></div></div>'

        /* ---- AI 应答（v1.1.2：配置放在「机器人账号」页签里，跟着机器人走） ---- */
        . '<div class="ow-card"><h3 style="margin-bottom:10px">AI 应答</h3>'
        . '<p style="font-size:12px">开启后，群里<b>有人 @ 机器人</b>时，机器人会用配置的模型回答那个人的问题，'
        . '并以自己的身份发出（回复前面带 @提问者）。<b>没人 @ 它就不调用接口</b>，所以平时不烧钱。'
        . '关键词自动回复仍是「没人 @ 也应答」的兜底，两者不抢同一条消息。</p>'
        . '<div class="ow-form-item"><label class="ow-cs-check"><input type="checkbox" id="owCsAiOn">'
        . '<span>启用 AI 应答</span></label></div>'
        . '<div class="ow-form-row">'
        . '<div class="ow-form-item"><label>超时（秒）</label>'
        . '<input class="ow-input" id="owCsAiTimeout" type="number" min="3" max="30" style="width:90px"></div>'
        . '<div class="ow-form-item"><label>每日调用上限</label>'
        . '<input class="ow-input" id="owCsAiDaily" type="number" min="0" style="width:110px"></div>'
        . '<div class="ow-form-item"><label>带几条上下文</label>'
        . '<input class="ow-input" id="owCsAiCtx" type="number" min="0" max="20" style="width:90px"></div>'
        . '</div>'
        . '<p style="font-size:12px">超时 3～30 秒：一次调用含连接、TLS 握手与模型推理，设小了就是'
        . '「开关看着开着、其实一次都没问成」。⚠️ <b>应答是同步发生的</b> —— 提问者的那次发送要等'
        . '模型回话，别把超时设得太长。'
        . '<br>「带几条上下文」默认 0（只把这一条提问交给模型）；调大后<b>群里的发言会一起送到第三方接口</b>，'
        . '按需要开。另有三道闸：每日总量（账单）、同一人一分钟 3 次、同一群一分钟 10 次，'
        . '回复长度固定截断在 500 字（防模型跑偏刷屏）。</p>'
        . '<div class="ow-form-item"><label>接口地址</label>'
        . '<input class="ow-input" id="owCsAiBase" maxlength="200" placeholder="https://api.openai.com/v1">'
        . '<p style="font-size:12px">OpenAI 兼容地址，必须 https（密钥不能明文出网），系统会自动接 /chat/completions。</p></div>'
        . '<div class="ow-form-row">'
        . '<div class="ow-form-item"><label>API Key</label>'
        . '<input class="ow-input" type="password" id="owCsAiKey" maxlength="200" autocomplete="off" placeholder="留空表示不修改"></div>'
        . '<div class="ow-form-item"><label>模型</label>'
        . '<input class="ow-input" id="owCsAiModel" maxlength="80" placeholder="gpt-4o-mini"></div>'
        . '</div>'
        . '<div id="owCsAiKeyInfo" style="font-size:12px;color:var(--ow-text-sub);margin:-6px 0 10px"></div>'
        . '<div class="ow-form-item"><label>提示词</label>'
        . '<textarea class="ow-input" id="owCsAiPrompt" rows="12" style="font-family:Consolas,monospace"></textarea>'
        . '<p style="font-size:12px">可用占位符：{bot} 机器人昵称、{nick} 提问者、{question} 问题、'
        . '{context} 群聊最近的消息。<b>清空本框并保存 = 恢复系统默认提示词。</b></p></div>'
        . '<div class="ow-form-row"><div class="ow-form-item" style="flex:1"><label>测试一句话</label>'
        . '<input class="ow-input" id="owCsAiTestQ" placeholder="留空则用一句默认的话试试接口通不通"></div>'
        . '<button class="ow-btn ow-btn-ghost" type="button" onclick="OwCSAdmin.aiTest()">测试 AI 配置</button></div>'
        . '<div id="owCsAiStat" style="font-size:12px;color:var(--ow-text-sub);margin:4px 0"></div>'
        . '<div class="ow-cs-mf-btns"><button class="ow-btn ow-btn-primary" type="button" onclick="OwCSAdmin.cfgSave()">保存设置</button></div>'
        . '</div></div>'

        /* ---- 发送记录（通用列表：筛选 + 批量 + 分页） ---- */
        . '<div class="ow-cs-pane" id="owCsPane-log" style="display:none">'
        . '<div class="ow-card">'
        . '<p style="font-size:12px">自动回复与定时群发的每次发送都记在这里（含失败原因）。机器人被禁言、被移出群聊、内容被敏感词拦截时，这里能看到对应记录。</p>'
        . '<div class="ow-form-row">'
        . '<div class="ow-form-item" style="flex:1;min-width:180px"><label>关键词</label>'
        . '<input class="ow-input" id="owCsLogQ" placeholder="群名 / 内容 / 失败原因" onkeydown="if(event.key===\'Enter\')OwCSAdmin.logLoad(1)"></div>'
        . '<div class="ow-form-item" style="min-width:130px"><label>类型</label>'
        . '<select class="ow-input" id="owCsLogKind" onchange="OwCSAdmin.logLoad(1)">'
        . '<option value="">全部</option><option value="reply">自动回复</option>'
        . '<option value="cast">定时群发</option><option value="ai">AI 应答</option>'
        . '</select></div>'
        . '<div class="ow-form-item" style="min-width:130px"><label>结果</label>'
        . '<select class="ow-input" id="owCsLogOk" onchange="OwCSAdmin.logLoad(1)">'
        . '<option value="">全部</option><option value="1">成功</option><option value="0">失败</option>'
        . '</select></div>'
        . '<div class="ow-form-item" style="min-width:150px"><label>开始日期</label>'
        . '<input class="ow-input" id="owCsLogD1" type="date"></div>'
        . '<div class="ow-form-item" style="min-width:150px"><label>结束日期</label>'
        . '<input class="ow-input" id="owCsLogD2" type="date"></div>'
        . '<button class="ow-btn ow-btn-primary" type="button" onclick="OwCSAdmin.logLoad(1)">搜索</button>'
        . '<button class="ow-btn ow-btn-ghost" type="button" onclick="OwCSAdmin.logReset()">重置</button>'
        . '</div></div>'
        . '<div class="ow-card">'
        . '<div class="ow-cs-toolbar">'
        . '<button class="ow-btn ow-btn-ghost" type="button" onclick="OwCSAdmin.logClearAll()">清空全部记录</button>'
        . '<span class="ow-cs-count" id="owCsLogStat"></span>'
        . '</div>'
        . '<div id="owCsLogBatch"></div>'
        . '<div class="ow-table-wrap"><table class="ow-table" id="owCsLogTable">'
        . '<tr><th style="width:32px"><input type="checkbox" id="owCsLogCheckAll" onchange="OwCSAdmin.logToggleAll(this)"></th>'
        . '<th>时间</th><th>类型</th><th>群聊</th><th>内容</th><th>结果</th></tr>'
        . '</table></div>'
        . '<div id="owCsLogPager"></div>'
        . '</div></div>';
});

/**
 * 后台基础数据：机器人账号设置 + 机器人能在哪些群发言。
 * v1.1.1：两个列表（公共话术 / 发送记录）改走服务端分页，不再从这里一次拿全 ——
 * 记录上万条时那把「一次拿 100 条」既看不全也拖慢首屏，交给各自的列表路由。
 */
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
        // AI 配置走 csAiPublic()：**不含 ai_key 明文**，只回是否已设置 + 掩码
        'ai'    => csAiPublic(),
    ]);
});

/* ---------- 公共话术列表（分页 + 搜索；与全站后台通用列表同一套分页口径） ---------- */
Plugin::route('plugin_chat_scripts_admin_snip_list', function (array $ctx) use ($csGuard) {
    $csGuard($ctx);
    csBoot();
    $post  = $ctx['post'];
    $page  = max(1, (int)($post['page'] ?? 1));
    $psize = max(1, min(100, (int)($post['psize'] ?? 20)));
    $kw    = trim((string)($post['q'] ?? ''));

    // ⚠️ scope='public' 写死在这里，不是「顺手过滤一下」：个人话术与群话术
    //    绝不能从这个接口漏出去（那是别人自己写的东西）。
    $where = " WHERE scope='public'";
    $args  = [];
    if ($kw !== '') {
        $where .= ' AND (title LIKE ? OR content LIKE ? OR group_name LIKE ?)';
        $args[] = '%' . $kw . '%';
        $args[] = '%' . $kw . '%';
        $args[] = '%' . $kw . '%';
    }
    $total = (int)DB::val("SELECT COUNT(*) FROM plugin_chat_scripts_snip$where", $args);
    // 翻页越界收敛：批量删光当前页后 $page 可能超过总页数，不收敛会返回一张空表
    $pages = max(1, (int)ceil($total / $psize));
    if ($page > $pages) $page = $pages;
    $offset = ($page - 1) * $psize;

    $rows = DB::all("SELECT id, group_name, title, content, use_count
        FROM plugin_chat_scripts_snip$where ORDER BY sort ASC, id ASC LIMIT $psize OFFSET $offset", $args);
    Api::json(['ok' => true, 'data' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'psize' => $psize]);
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

/**
 * 把前端传来的 id 清单解析成去重后的正整数数组（批量删除共用）。
 * 只认纯数字：'abc' / '1e3' / ' 3 ' 里的前两者直接被丢掉，不留任何能进 SQL 的余地
 * （最终仍走参数占位符，这一步是「连占位符都不给它」的额外一层）。
 */
function csParseIds(string $raw, int $cap = 500): array
{
    $out = [];
    foreach (explode(',', $raw) as $v) {
        $v = trim($v);
        if ($v === '' || !preg_match('/^\d+$/', $v)) continue;
        $n = (int)$v;
        if ($n > 0) $out[$n] = true;
        if (count($out) >= $cap) break;
    }
    return array_keys($out);
}

/* ---------- 公共话术删除（单个 / 批量共用；敏感操作） ---------- */
Plugin::route('plugin_chat_scripts_admin_snip_del', function (array $ctx) use ($csGuard) {
    $csGuard($ctx);
    csBoot();
    $post = $ctx['post'];
    // v1.1.1：列表换成通用批量选择后，删除走 ids（逗号分隔）；单个删除仍传 id，两条路都留着
    $ids = csParseIds((string)($post['ids'] ?? ''));
    if (!$ids && (int)($post['id'] ?? 0) > 0) $ids = [(int)$post['id']];
    if (!$ids) Api::json(['ok' => false, 'msg' => '请先勾选要删除的话术']);

    $ph = implode(',', array_fill(0, count($ids), '?'));
    // ⚠️ scope='public' 必须留在 WHERE 里：少了它，构造请求拿别的 id 就能删掉
    //    用户的个人话术 / 群话术（那两类的 id 与公共话术同表连续，几乎必然命中）
    $rows = DB::all("SELECT id, title FROM plugin_chat_scripts_snip WHERE scope='public' AND id IN ($ph)", $ids);
    if (!$rows) Api::json(['ok' => false, 'msg' => '公共话术不存在'], 404);
    DB::run("DELETE FROM plugin_chat_scripts_snip WHERE scope='public' AND id IN ($ph)", $ids);
    Sec::log('cs_snip_del', $ctx['actor']['nickname'], [
        'n' => count($rows), 'titles' => mb_substr(implode(' / ', array_column($rows, 'title')), 0, 190),
    ]);
    Api::json(['ok' => true, 'msg' => '已删除 ' . count($rows) . ' 条']);
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

    // ---- AI 应答（v1.1.2）----
    csSetCfg('ai_on', (int)($post['ai_on'] ?? 0) === 1 ? '1' : '0');
    $base = trim((string)($post['ai_base'] ?? ''));
    // 与「AI 防线」同一条规矩：非 https 直接拒 —— 密钥不能明文出网
    if ($base !== '' && stripos($base, 'https://') !== 0) {
        Api::json(['ok' => false, 'msg' => '接口地址必须是 https（否则密钥明文出网）']);
    }
    csSetCfg('ai_base', mb_substr($base, 0, 200));
    csSetCfg('ai_model', mb_substr(trim((string)($post['ai_model'] ?? '')), 0, 80));
    // ⚠️ 密钥留空 = 不修改（与「AI 防线」同一规矩）。后台页从来拿不到明文，
    //    若空值也覆盖，管理员每次保存别的设置都会把密钥悄悄清掉。
    $key = trim((string)($post['ai_key'] ?? ''));
    if ($key !== '') csSetCfg('ai_key', mb_substr($key, 0, 200));
    // 空 = 用默认（清空即恢复）。截到 8000 字：再长的提示词既没有意义也会撑爆请求体
    csSetCfg('ai_prompt', mb_substr((string)($post['ai_prompt'] ?? ''), 0, 8000));
    csSetCfg('ai_timeout', (string)max(CS_AI_TIMEOUT_MIN, min(CS_AI_TIMEOUT_MAX, (int)($post['ai_timeout'] ?? 10))));
    csSetCfg('ai_daily', (string)max(0, min(1000000, (int)($post['ai_daily'] ?? 200))));
    csSetCfg('ai_ctx', (string)max(0, min(20, (int)($post['ai_ctx'] ?? 0))));

    Sec::log('cs_cfg_save', $ctx['actor']['nickname'], ['bot_uid' => $botUid]);
    Api::json(['ok' => true, 'msg' => '已保存']);
});
Plugin::sensitive('plugin_chat_scripts_admin_cfg_save');

/**
 * 试一次 AI 配置（不落库、不发到群里，只把模型回的话显示给管理员看）。
 * 为什么需要它：不开这个口子，管理员只能等群里有人 @ 机器人才知道配置对不对，
 * 而一次失败是静默的（只进发送日志），排查起来很别扭。
 */
Plugin::route('plugin_chat_scripts_admin_ai_test', function (array $ctx) use ($csGuard) {
    $csGuard($ctx);
    csBoot();
    $q = trim((string)($ctx['post']['q'] ?? ''));
    if ($q === '') $q = '用一句话说说你能帮群里做什么';
    $bot = csBot();
    // 测试不该真的去读某个群的历史：room_id 传 0，csAiContext() 会直接返回空
    $t0 = microtime(true);
    $r = csAiAsk(csAiPrompt($q, ['nickname' => '测试'], $bot ?: ['nickname' => '机器人'], 0));
    $ms = (int)round((microtime(true) - $t0) * 1000);
    Api::json([
        'ok'   => $r['ok'],
        'msg'  => $r['ok'] ? ('通了（' . $ms . ' ms）') : ('失败：' . (string)$r['err']),
        'text' => (string)$r['text'],
        'ms'   => $ms,
    ]);
});

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

/* v1.1.1：后台的「自动回复」「定时群发」两个页签整块下线 —— 规则与任务只跟着群走，
   入口在「群聊设置 → 自动化」，群主自管本群。原来这两条 admin_rule_* 路由支持
   room_id=0（全站规则）与任意群，是只有管理员能用的第二条通道；留着就是「界面上没了、
   接口还在」的暗门（用户此前明确叫停过这种死代码），因此连同 admin_cast_* 一并删除。
   ⚠️ 连带影响：全站规则（room_id=0）从此没有任何创建入口。已有的历史行仍会被
   message.after_send 钩子命中（那里保留了 `OR room_id=0`），群主在自动化弹窗顶部
   能看到「另有 N 条全站规则在本群同样生效」，不会莫名其妙多出一条自动回复。 */

/* ---------- 发送记录：列表（分页 + 筛选）/ 批量删除 / 清空 ---------- */

Plugin::route('plugin_chat_scripts_admin_log_list', function (array $ctx) use ($csGuard) {
    $csGuard($ctx);
    csBoot();
    $post  = $ctx['post'];
    $page  = max(1, (int)($post['page'] ?? 1));
    $psize = max(1, min(100, (int)($post['psize'] ?? 20)));
    $kw    = trim((string)($post['q'] ?? ''));
    $kind  = trim((string)($post['kind'] ?? ''));     // reply / cast，空 = 全部
    $ok    = trim((string)($post['ok'] ?? ''));       // '1' / '0'，空 = 全部
    $d1    = trim((string)($post['d1'] ?? ''));
    $d2    = trim((string)($post['d2'] ?? ''));

    $where = ' WHERE 1=1';
    $args  = [];
    if (in_array($kind, ['reply', 'cast', 'ai'], true)) { $where .= ' AND kind=?'; $args[] = $kind; }
    if ($ok === '0' || $ok === '1') { $where .= ' AND ok=?'; $args[] = (int)$ok; }
    if ($kw !== '') {
        // 群名也进搜索：排查「某个群为什么没发出」时，按群名找比翻页快得多
        $where .= ' AND (content LIKE ? OR room_name LIKE ? OR msg LIKE ?)';
        $args[] = '%' . $kw . '%';
        $args[] = '%' . $kw . '%';
        $args[] = '%' . $kw . '%';
    }
    // 日期段：当日 0 点起、次日 0 点前（结束日期包含整天）——与登录日志同一口径
    if ($d1 !== '') { $t1 = strtotime($d1 . ' 00:00:00'); if ($t1 !== false) { $where .= ' AND created_at>=?'; $args[] = (int)$t1; } }
    if ($d2 !== '') { $t2 = strtotime($d2 . ' 23:59:59'); if ($t2 !== false) { $where .= ' AND created_at<=?'; $args[] = (int)$t2; } }

    $total = (int)DB::val("SELECT COUNT(*) FROM plugin_chat_scripts_log$where", $args);
    $pages = max(1, (int)ceil($total / $psize));
    if ($page > $pages) $page = $pages;      // 批量删光当前页后收敛，避免返回一张空表
    $offset = ($page - 1) * $psize;

    $rows = DB::all("SELECT id, kind, room_id, room_name, rule_id, content, ok, msg, created_at
        FROM plugin_chat_scripts_log$where ORDER BY id DESC LIMIT $psize OFFSET $offset", $args);
    Api::json(['ok' => true, 'data' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'psize' => $psize]);
});

/** 批量删除所选记录（敏感操作；按 id 精确删，不碰别的行） */
Plugin::route('plugin_chat_scripts_admin_log_del', function (array $ctx) use ($csGuard) {
    $csGuard($ctx);
    csBoot();
    $ids = csParseIds((string)($ctx['post']['ids'] ?? ''));
    if (!$ids) Api::json(['ok' => false, 'msg' => '请先勾选要删除的记录']);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $n = (int)DB::val("SELECT COUNT(*) FROM plugin_chat_scripts_log WHERE id IN ($ph)", $ids);
    DB::run("DELETE FROM plugin_chat_scripts_log WHERE id IN ($ph)", $ids);
    Sec::log('cs_log_del', $ctx['actor']['nickname'], ['n' => $n]);
    Api::json(['ok' => true, 'msg' => '已删除 ' . $n . ' 条']);
});
Plugin::sensitive('plugin_chat_scripts_admin_log_del');

/* ---------- 清空全部记录（敏感操作） ---------- */
Plugin::route('plugin_chat_scripts_admin_log_clear', function (array $ctx) use ($csGuard) {
    $csGuard($ctx);
    csBoot();
    DB::run('DELETE FROM plugin_chat_scripts_log');
    Sec::log('cs_log_clear', $ctx['actor']['nickname'], []);
    Api::json(['ok' => true, 'msg' => '已清空']);
});
Plugin::sensitive('plugin_chat_scripts_admin_log_clear');

/* ============================ 输入工具栏入口 ============================ */

/**
 * 「话术」按钮（v1.1.3 起走 chat.toolbar 钩子）：HTML 由服务端产出，
 * 核心 Plugin::collect('chat.toolbar') 拼进 #owToolFirst；点击与面板仍绑在 chat.js。
 * ow_icon('chat', 16) 与旧版 JS OwChat.icon('chat', 16) 输出逐字相同，外观不变。
 */
Plugin::on('chat.toolbar', function (): string {
    /* v1.2.0：把配置的机器人账号 uid 挂在这颗按钮上（data-bot-uid）。
       为什么挂这里：这是本插件在聊天页**唯一的服务端渲染点**，uid 随页面一起到，
       前端读一下就能登记给核心（OwChat.markBots），既不用多发一个 conf 请求，
       也不用把 bot_uid 暴露成一条公开接口。uid 本身不是秘密：任何人的消息里都带着自己的 uid。
       0 = 后台还没配机器人，前端登记 0 会被核心忽略。 */
    return '<button class="ow-icon-btn" id="owBtnCs" type="button" title="话术" aria-label="话术"'
         . ' data-bot-uid="' . csCfgInt('bot_uid') . '">'
         . ow_icon('chat', 16) . '</button>';
});

/* ============================ 静态资源 ============================ */

Plugin::asset('css', 'chat-scripts/style.css');
Plugin::asset('js', 'chat-scripts/chat.js');      // 前台：话术面板（后台页自动退出）
Plugin::asset('js', 'chat-scripts/auto.js');      // 前台：群聊设置里的「自动化」入口（后台页自动退出）
Plugin::asset('js', 'chat-scripts/admin.js');     // 后台：管理页交互（前台页自动退出）
