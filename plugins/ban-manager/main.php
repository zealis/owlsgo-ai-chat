<?php
/**
 * 禁言管理插件（v1.0.52 自后台剥离）
 *
 * 后台页面：Plugin::adminPage() 注册「禁言管理」子页（插件管理分类下）。
 * API 路由：Plugin::route() 注册列表 / 添加 / 解除三条管理员操作，
 *           action 前缀 plugin_ban_manager_；每条路由自查管理员权限。
 * 运行时：禁言的生效拦截（Chat::isBanned）与 bans 表保留在核心——
 *         停用本插件只下线管理入口，已生效的禁言继续拦截；
 *         其他插件可通过核心钩子 ban.check 扩展禁言判定。
 */
if (!defined('OWLSGO_VERSION')) exit;

/** 管理员鉴权：插件路由的公共守卫 */
$bmGuard = function (array $ctx): void {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
};

/* ---------- 后台页面 ---------- */
Plugin::adminPage('ban-manager', '禁言管理', function () {
    return '<h2>禁言管理</h2><p class="ow-admin-desc">按用户 / 游客昵称 / IP 禁言，可按房间隔离，支持过期时间。</p>'
        . '<div class="ow-card"><div class="ow-form-row">'
        . '<div class="ow-form-item"><label>类型</label><select class="ow-input" id="owBType"><option value="user">用户ID</option><option value="guest">游客昵称</option><option value="ip">IP 地址</option></select></div>'
        . '<div class="ow-form-item"><label>目标</label><input class="ow-input" id="owBTarget"></div>'
        . '<div class="ow-form-item"><label>房间ID（0=全局）</label><input class="ow-input" id="owBRoom" value="0"></div>'
        . '<div class="ow-form-item"><label>时长（小时，0=永久）</label><input class="ow-input" id="owBHours" value="24"></div>'
        . '<div class="ow-form-item"><label>原因</label><input class="ow-input" id="owBReason"></div>'
        . '<button class="ow-btn ow-btn-danger" onclick="OwBM.add()">添加禁言</button></div></div>'
        . '<div id="owBList"></div>';
});

/* ---------- 列表（原 admin_bans） ---------- */
Plugin::route('plugin_ban_manager_list', function (array $ctx) use ($bmGuard) {
    $bmGuard($ctx);
    Api::json(['ok' => true, 'data' => DB::all('SELECT * FROM bans ORDER BY id DESC LIMIT 100')]);
});

/* ---------- 添加（原 admin_ban_add，空目标校验保留） ---------- */
Plugin::route('plugin_ban_manager_add', function (array $ctx) use ($bmGuard) {
    $bmGuard($ctx);
    $post = $ctx['post'];
    $actor = $ctx['actor'];
    $type = (string)($post['type'] ?? '');
    if (!in_array($type, ['user', 'guest', 'ip'], true)) Api::json(['ok' => false, 'msg' => '非法类型']);
    $target = trim((string)($post['target'] ?? ''));
    if ($target === '') Api::json(['ok' => false, 'msg' => '请填写禁言目标']);
    $id = DB::insert('bans', [
        'type' => $type, 'target' => $target,
        'room_id' => (int)($post['room_id'] ?? 0),
        'reason' => (string)($post['reason'] ?? ''),
        'expires_at' => (int)($post['hours'] ?? 0) > 0 ? time() + (int)($post['hours'] ?? 0) * 3600 : null,
        'created_by' => $actor['nickname'], 'created_at' => time(),
    ]);
    Sec::log('admin_ban', $actor['nickname'], ['type' => $type, 'target' => $target]);
    Plugin::fire('ban.after_add', [$id, $type, $target, $actor]);
    // v1.3.53：通知本人「在哪个群、被禁多久」。只有 type=user 有账号可投递 ——
    // 游客身份随会话消亡、IP 段背后是多人，发过去要么没人收要么发错人。
    if ($type === 'user') Notice::muted((int)$target, (int)($post['room_id'] ?? 0), (int)($post['hours'] ?? 0));
    Api::json(['ok' => true, 'msg' => '已禁言']);
});
Plugin::sensitive('plugin_ban_manager_add');   // 禁言：敏感（v1.0.91）

/* ---------- 解除（原 admin_ban_del） ---------- */
Plugin::route('plugin_ban_manager_del', function (array $ctx) use ($bmGuard) {
    $bmGuard($ctx);
    $id = (int)($ctx['post']['id'] ?? 0);
    DB::run('DELETE FROM bans WHERE id=?', [$id]);
    Plugin::fire('ban.after_del', [$id, $ctx['actor']]);
    Api::json(['ok' => true, 'msg' => '已解除']);
});
Plugin::sensitive('plugin_ban_manager_del');   // 解除禁言：敏感（v1.0.91）

/* ---------- 群聊右键「禁言」快速操作（v1.0.54） ----------
 * 面向场景：房主 / 管理员在群聊里右键某人消息直接禁言。
 * 与后台添加的区别：
 *   - 权限放宽到「管理员 或 本房间房主」（后台仅管理员）；
 *   - 范围固定为当前群聊（房主无权做全站禁言，需全站请用后台页面）；
 *   - 房主不能禁言管理员，也不能禁言自己。
 * 目标取自消息作者：用户用 ID，游客用昵称（与核心 Chat::isBanned 的匹配方式一致）。
 */
Plugin::route('plugin_ban_manager_quick', function (array $ctx) {
    $actor = $ctx['actor'];
    $post  = $ctx['post'];
    if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '需要登录后操作'], 403);

    $roomId = (int)($post['room_id'] ?? 0);
    $room = DB::one('SELECT id,owner_id FROM rooms WHERE id=?', [$roomId]);
    if (!$room) Api::json(['ok' => false, 'msg' => '房间不存在']);

    $isAdmin = ($actor['role'] ?? '') === 'admin';
    $isOwner = (int)($room['owner_id'] ?? 0) > 0 && (int)$room['owner_id'] === (int)$actor['id'];
    if (!$isAdmin && !$isOwner) Api::json(['ok' => false, 'msg' => '仅管理员或本房间房主可禁言'], 403);

    // 目标：消息作者（用户 ID 优先，其次游客昵称）
    $uid  = (int)($post['uid'] ?? 0);
    $gid  = (int)($post['gid'] ?? 0);
    $nick = trim((string)($post['nickname'] ?? ''));
    $tgtRole = '';
    if ($uid > 0) {
        if ($uid === (int)$actor['id']) Api::json(['ok' => false, 'msg' => '不能禁言自己']);
        $type = 'user'; $target = (string)$uid;
        $tgtRole = (string)(DB::val('SELECT role FROM users WHERE id=?', [$uid]) ?: '');
    } elseif ($gid > 0 && $nick !== '') {
        $type = 'guest'; $target = $nick; $tgtRole = 'guest';
    } else {
        Api::json(['ok' => false, 'msg' => '无法确定禁言对象']);
    }
    if (!$isAdmin && $tgtRole === 'admin') Api::json(['ok' => false, 'msg' => '不能禁言管理员'], 403);

    $hours = (int)($post['hours'] ?? 0);
    if ($hours < 0) $hours = 0;
    if ($hours > 8760) $hours = 8760;                 // 上限一年，防止超大数值溢出
    $id = DB::insert('bans', [
        'type' => $type, 'target' => $target,
        'room_id' => $roomId,                          // 仅本房间生效
        'reason' => mb_substr((string)($post['reason'] ?? ''), 0, 100),
        'expires_at' => $hours > 0 ? time() + $hours * 3600 : null,
        'created_by' => $actor['nickname'], 'created_at' => time(),
    ]);
    Sec::log('ban_quick', $actor['nickname'], ['type' => $type, 'target' => $target, 'room' => $roomId, 'hours' => $hours]);
    Plugin::fire('ban.after_add', [$id, $type, $target, $actor]);
    if ($type === 'user') Notice::muted($uid, $roomId, $hours);   // v1.3.53 通知本人（游客无账号可投递）
    Api::json(['ok' => true, 'msg' => '已禁言' . ($hours > 0 ? "（本房间 {$hours} 小时）" : '（本房间永久）')]);
});
Plugin::sensitive('plugin_ban_manager_quick');   // 右键快速禁言：敏感（v1.0.91）

// 注册脚本：admin.js（后台页面交互）+ chat.js（群聊右键菜单）
// 聊天页自 v1.0.55 起由主程序统一引入合并资源（在 OwChat.init 之后），插件无需自行注入
Plugin::asset('js', 'ban-manager/admin.js', 'admin');
Plugin::asset('js', 'ban-manager/chat.js', 'front');
