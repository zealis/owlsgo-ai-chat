<?php
/**
 * 屏蔽用户插件（user-block）v1.0.0
 *
 * 功能：
 *   - 群聊消息右键「头像」菜单增加「屏蔽此人 / 取消屏蔽」
 *   - 私聊右侧栏增加「屏蔽此人」iOS 样式开关
 *   - 个人设置增加「已屏蔽用户」列表，可逐个取消屏蔽
 *   - 被屏蔽者在群聊 / 私聊中发来的消息对屏蔽者不可见，对方无感知
 *
 * 实现思路（不修改主程序）：
 *   - 后端：注册 message.after_send 钩子，消息入库后把它写进所有「屏蔽了发送者」
 *     的用户的 message_hides（核心既有的「仅本机隐藏」机制），history/poll 自动过滤；
 *     同时在屏蔽时回填对方最近一批消息的隐藏行，立即生效。
 *   - 前端：包装 OwChat.addMessage 做二次过滤（兜底轮询竞态），
 *     包装 OwChat.dmPanelHtml / openSettings 注入入口，
 *     用 OwChat.onMsgCtx 追加右键菜单项。
 *
 * 路由前缀：plugin_user_block_
 * 前端全局对象：OwUB
 */
if (!defined('OWLSGO_VERSION')) exit;

/* ===================== 数据表（幂等建表） ===================== */

DB::run("CREATE TABLE IF NOT EXISTS plugin_user_blocks (
    id " . DB::autoId() . ",
    blocker_id INTEGER NOT NULL DEFAULT 0,
    blocked_id INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0
)");
// 唯一索引是「同一人不会被重复屏蔽」的正确性保证，不能靠 try/catch：
// MySQL 不支持 CREATE INDEX IF NOT EXISTS，吞掉=索引静默缺失。
DB::createIndex('idx_user_blocks_pair', 'plugin_user_blocks', 'blocker_id, blocked_id', true);

/* ===================== 工具函数 ===================== */

/** 当前是否为已登录注册用户 */
function owUBIsUser(array $actor): bool
{
    return ($actor['kind'] ?? '') === 'user' && (int)($actor['id'] ?? 0) > 0;
}

/**
 * 跨驱动把「屏蔽了发送者」的用户批量写入 message_hides。
 * message_hides 有 (user_id, message_id) 唯一索引，用各驱动的「冲突忽略」语法。
 */
function owUBHideFromBlockers(int $msgId, int $senderId, int $now): void
{
    if ($msgId <= 0 || $senderId <= 0) return;
    $driver = DB::driver();
    if ($driver === 'mysql') {
        $sql = 'INSERT IGNORE INTO message_hides (user_id, message_id, created_at)
                SELECT blocker_id, ?, ? FROM plugin_user_blocks WHERE blocked_id = ?';
    } elseif ($driver === 'pgsql') {
        $sql = 'INSERT INTO message_hides (user_id, message_id, created_at)
                SELECT blocker_id, ?, ? FROM plugin_user_blocks WHERE blocked_id = ?
                ON CONFLICT (user_id, message_id) DO NOTHING';
    } else {
        $sql = 'INSERT OR IGNORE INTO message_hides (user_id, message_id, created_at)
                SELECT blocker_id, ?, ? FROM plugin_user_blocks WHERE blocked_id = ?';
    }
    DB::run($sql, [$msgId, $now, $senderId]);
}

/**
 * 屏蔽时回填：把对方最近一批消息写进我的 message_hides，立即可见地消失。
 * 限制条数避免历史消息过多时拖慢请求。
 */
function owUBBackfillHides(int $blockerId, int $blockedId, int $now, int $limit = 500): void
{
    if ($blockerId <= 0 || $blockedId <= 0) return;
    $driver = DB::driver();
    if ($driver === 'mysql') {
        $sql = 'INSERT IGNORE INTO message_hides (user_id, message_id, created_at)
                SELECT ?, id, ? FROM messages WHERE user_id = ? ORDER BY id DESC LIMIT ' . $limit;
    } elseif ($driver === 'pgsql') {
        $sql = 'INSERT INTO message_hides (user_id, message_id, created_at)
                SELECT ?, id, ? FROM messages WHERE user_id = ? ORDER BY id DESC LIMIT ' . $limit
                . ' ON CONFLICT (user_id, message_id) DO NOTHING';
    } else {
        $sql = 'INSERT OR IGNORE INTO message_hides (user_id, message_id, created_at)
                SELECT ?, id, ? FROM messages WHERE user_id = ? ORDER BY id DESC LIMIT ' . $limit;
    }
    DB::run($sql, [$blockerId, $now, $blockedId]);
}

/* ===================== API 路由 ===================== */

/**
 * 切换屏蔽状态（幂等）。
 * POST: target_id
 * 返回: {ok, blocked(bool), msg}
 */
Plugin::route('plugin_user_block_toggle', function (array $ctx) {
    $actor = $ctx['actor'];
    if (!owUBIsUser($actor)) Api::json(['ok' => false, 'msg' => '请先登录后再操作'], 403);

    $me = (int)$actor['id'];
    $targetId = (int)($ctx['post']['target_id'] ?? 0);
    if ($targetId <= 0) Api::json(['ok' => false, 'msg' => '参数错误']);
    if ($targetId === $me) Api::json(['ok' => false, 'msg' => '不能屏蔽自己']);

    // 目标必须是存在的注册用户（游客无持久身份，无法屏蔽）
    $target = DB::one('SELECT id, nickname, role FROM users WHERE id=? AND status=1', [$targetId]);
    if (!$target) Api::json(['ok' => false, 'msg' => '该用户不存在或已被封禁']);

    // 超级管理员不可被屏蔽（写入点硬保护，覆盖所有入口）
    if (($target['role'] ?? '') === 'admin') {
        Api::json(['ok' => false, 'msg' => '不能屏蔽超级管理员']);
    }

    $now = time();
    $exists = (int)DB::val('SELECT id FROM plugin_user_blocks WHERE blocker_id=? AND blocked_id=?', [$me, $targetId]) > 0;

    if ($exists) {
        // 取消屏蔽：只删关系行，已写入 message_hides 的历史消息保留（语义同「删除」，不复活）
        DB::run('DELETE FROM plugin_user_blocks WHERE blocker_id=? AND blocked_id=?', [$me, $targetId]);
        Sec::log('user_block_remove', $actor['nickname'] ?? '', ['target' => $targetId]);
        Api::json(['ok' => true, 'blocked' => false, 'msg' => '已取消屏蔽「' . (string)$target['nickname'] . '」']);
    }

    DB::insert('plugin_user_blocks', [
        'blocker_id' => $me,
        'blocked_id' => $targetId,
        'created_at' => $now,
    ]);
    // 回填：把对方最近一批消息在我这边隐藏
    owUBBackfillHides($me, $targetId, $now);
    Sec::log('user_block_add', $actor['nickname'] ?? '', ['target' => $targetId]);
    Api::json(['ok' => true, 'blocked' => true, 'msg' => '已屏蔽「' . (string)$target['nickname'] . '」，对方不会收到通知']);
});

/**
 * 查询我是否屏蔽了某人。
 * POST: target_id
 * 返回: {ok, blocked: bool}
 */
Plugin::route('plugin_user_block_check', function (array $ctx) {
    $actor = $ctx['actor'];
    if (!owUBIsUser($actor)) Api::json(['ok' => true, 'blocked' => false]);

    $me = (int)$actor['id'];
    $targetId = (int)($ctx['post']['target_id'] ?? 0);
    if ($targetId <= 0) Api::json(['ok' => true, 'blocked' => false]);

    $blocked = (int)DB::val('SELECT 1 FROM plugin_user_blocks WHERE blocker_id=? AND blocked_id=?', [$me, $targetId]) > 0;
    Api::json(['ok' => true, 'blocked' => $blocked]);
});

/**
 * 我屏蔽的用户列表（个人设置用）。
 * 返回: {ok, data: [{uid, nickname, avatar, created_at}]}
 */
Plugin::route('plugin_user_block_list', function (array $ctx) {
    $actor = $ctx['actor'];
    if (!owUBIsUser($actor)) Api::json(['ok' => true, 'data' => []]);

    $me = (int)$actor['id'];
    $rows = DB::all(
        'SELECT b.blocked_id AS uid, b.created_at, u.nickname, u.avatar
         FROM plugin_user_blocks b
         LEFT JOIN users u ON u.id = b.blocked_id
         WHERE b.blocker_id = ?
         ORDER BY b.id DESC',
        [$me]
    );
    $out = [];
    foreach ($rows as $r) {
        $uid = (int)$r['uid'];
        $nick = (string)($r['nickname'] ?? '');
        if ($nick === '') $nick = '用户' . $uid;   // 对方已注销时兜底
        $out[] = [
            'uid' => $uid,
            'nickname' => $nick,
            'avatar' => (string)($r['avatar'] ?? ''),
            'created_at' => (int)$r['created_at'],
        ];
    }
    Api::json(['ok' => true, 'data' => $out]);
});

/* ===================== 钩子：消息入库后对屏蔽者隐藏 ===================== */

/**
 * message.after_send 回调签名：[$msgId, $actor, $roomId, $content, $type, $toUserId]
 * 把这条消息写进所有「屏蔽了发送者」的用户的 message_hides。
 * 游客（无持久 user_id）不会被屏蔽，直接跳过。
 */
Plugin::on('message.after_send', function ($msgId, $actor, $roomId, $content, $type, $toUserId) {
    if (!owUBIsUser($actor)) return;
    $senderId = (int)$actor['id'];
    owUBHideFromBlockers((int)$msgId, $senderId, time());
});

/* ===================== 资源注册 ===================== */

Plugin::asset('js', 'user-block/chat.js', 'front');
