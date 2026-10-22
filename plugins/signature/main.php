<?php
/**
 * 个性签名插件（signature）v1.0.0
 *
 * 功能：
 *   - 用户在「个人设置」中填写个性签名（默认上限 200 字）
 *   - 在「个人资料卡」中展示个性签名
 *   - 不修改主程序：仅依赖 Plugin::route / asset，不新增钩子
 */
if (!defined('OWLSGO_VERSION')) exit;

/* ===================== 数据库表 ===================== */

function owSigEnsureTable(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $drv = DB::driver();
    $int  = $drv === 'mysql' ? 'INT' : 'INTEGER';
    // signature 走 DB::textCol()：MySQL 的 TEXT 不能带默认值（1101）
    $sql  = "CREATE TABLE IF NOT EXISTS plugin_signature (
        user_id $int PRIMARY KEY,
        " . DB::textCol('signature') . ",
        updated_at $int NOT NULL DEFAULT 0
    )";
    DB::run($sql);
}
owSigEnsureTable();

/* ===================== 路由 ===================== */

/** 保存当前用户的个性签名（限 200 字） */
Plugin::route('plugin_signature_save', function (array $ctx): void {
    if (($ctx['actor']['kind'] ?? '') !== 'user') {
        Api::json(['ok' => false, 'msg' => '请先登录'], 403);
    }
    $uid = (int)$ctx['actor']['id'];
    $signature = trim((string)($ctx['post']['signature'] ?? ''));
    if (mb_strlen($signature) > 200) {
        Api::json(['ok' => false, 'msg' => '个性签名不能超过 200 个字']);
    }
    DB::upsert('plugin_signature', [
        'user_id'    => $uid,
        'signature'  => $signature,
        'updated_at' => time(),
    ], ['user_id']);
    Api::json(['ok' => true, 'msg' => '个性签名已保存']);
});

/** 查询指定用户的个性签名（供资料卡展示） */
Plugin::route('plugin_signature_get', function (array $ctx): void {
    $uid = (int)($ctx['post']['id'] ?? 0);
    if ($uid <= 0) Api::json(['ok' => false, 'msg' => '参数错误']);
    $row = DB::one('SELECT signature FROM plugin_signature WHERE user_id=?', [$uid]);
    Api::json(['ok' => true, 'signature' => $row ? (string)$row['signature'] : '']);
});

/**
 * 批量查询个性签名（v1.1.24 联系人列表用）。
 *
 * 为什么要批量：联系人列表要显示每个人的签名，逐个调 plugin_signature_get
 * 就是 N 次请求（20 个好友 = 20 次往返）。这里一次传入全部 user_id 换回 Map。
 *
 * 入参：ids = [1,2,3]（数组，由 ?ids[]=1&ids[]=2 或 JSON 字符串两种形式传）
 * 出参：signatures = { "1": "签名文本", "2": "" }（无签名的**也列出**，值为空串）
 *   —— 前端只需判断值是否为空，不必再查「有没有这个 key」。
 *
 * ⚠️ 上限 500 个：防止有人传超大数组打爆内存（联系人列表本身不会有这么多）。
 */
Plugin::route('plugin_signature_bulk', function (array $ctx): void {
    $ids = $ctx['post']['ids'] ?? [];
    // 兼容两种传法：表单的 ids[]=1&ids[]=2（已是数组），或 JSON 字符串 "1,2,3"
    if (is_string($ids)) {
        $decoded = json_decode($ids, true);
        $ids = is_array($decoded) ? $decoded : explode(',', $ids);
    }
    if (!is_array($ids)) Api::json(['ok' => false, 'msg' => '参数错误']);
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($x) => $x > 0)));
    if (!$ids) Api::json(['ok' => true, 'signatures' => new stdClass()]);
    if (count($ids) > 500) $ids = array_slice($ids, 0, 500);

    $q = implode(',', array_fill(0, count($ids), '?'));
    $rows = DB::all("SELECT user_id, signature FROM plugin_signature WHERE user_id IN ($q)", $ids);
    $out = [];
    foreach ($ids as $id) $out[(string)$id] = '';   // 先全部置空，保证 key 齐全
    foreach ($rows as $r) $out[(string)(int)$r['user_id']] = (string)$r['signature'];
    Api::json(['ok' => true, 'signatures' => $out]);
});

/* ===================== 资源 ===================== */

Plugin::asset('js', 'signature/chat.js', 'front');
