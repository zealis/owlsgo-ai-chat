<?php
/**
 * E2EE 私聊 E2E 的测试账号准备 / 清理
 *
 *   php .tools/owed-e2e-setup.php setup  "<站点根目录>"
 *   php .tools/owed-e2e-setup.php clean  "<站点根目录>"
 *
 * 站点根目录默认取本仓库；要动 phpStudy 运行副本就把它的绝对路径传进来。
 * 之所以不直接连库：config.php 里的路径是相对自身目录算的，换目录就必须换配置文件。
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$mode = $argv[1] ?? 'setup';
if (!in_array($mode, ['setup', 'clean', 'reset'], true)) { fwrite(STDERR, "mode 只能是 setup / clean / reset\n"); exit(1); }
$root = $argv[2] ?? dirname(__DIR__);
$root = rtrim($root, '/\\');
if (!is_file($root . '/core/config.php')) { fwrite(STDERR, "找不到 $root/core/config.php\n"); exit(1); }

$CFG = require $root . '/core/config.php';
require $root . '/core/db.php';
DB::init($CFG);

$emails = ['owed-a@test.local', 'owed-b@test.local'];

if ($mode === 'setup') {
    $ids = [];
    foreach ($emails as $i => $em) {
        $row = DB::one('SELECT id FROM users WHERE email=?', [$em]);
        if ($row) { $ids[] = (int)$row['id']; continue; }
        $ids[] = (int)DB::insert('users', [
            'nickname' => '加密测试' . ($i + 1), 'email' => $em,
            'password' => password_hash('OwedTest123456', PASSWORD_DEFAULT),
            'role' => 'member', 'status' => 1, 'email_verified' => 1,
            'created_at' => time(), 'last_login' => time(),
        ]);
    }
    $has = DB::val('SELECT id FROM friends WHERE (user_id=? AND friend_id=?) OR (user_id=? AND friend_id=?)',
        [$ids[0], $ids[1], $ids[1], $ids[0]]);
    if (!$has) DB::insert('friends', ['user_id' => $ids[0], 'friend_id' => $ids[1], 'created_at' => time()]);
    echo json_encode(['a' => $ids[0], 'b' => $ids[1]], JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}

/* ---------- 清理 ---------- */
$ids = [];
foreach ($emails as $em) {
    $r = DB::one('SELECT id FROM users WHERE email=?', [$em]);
    if ($r) $ids[] = (int)$r['id'];
}
if (!$ids) { echo "没有测试号需要清理\n"; exit(0); }
$in = implode(',', array_fill(0, count($ids), '?'));
DB::run("DELETE FROM messages WHERE user_id IN ($in)", $ids);
DB::run("DELETE FROM messages WHERE to_user_id IN ($in)", $ids);
DB::run("DELETE FROM friends WHERE user_id IN ($in) OR friend_id IN ($in)", $ids);
DB::run("DELETE FROM friend_req WHERE from_id IN ($in) OR to_id IN ($in)", $ids);
DB::run("DELETE FROM plugin_e2ee_devices WHERE user_id IN ($in)", $ids);
DB::run("DELETE FROM plugin_e2ee_opk WHERE user_id IN ($in)", $ids);
DB::run("DELETE FROM plugin_e2ee_conv WHERE a_uid IN ($in) OR b_uid IN ($in)", $ids);
DB::run("DELETE FROM plugin_e2ee_msg WHERE from_uid IN ($in) OR to_uid IN ($in)", $ids);
if ($mode === 'reset') {
    echo "已重置 " . count($ids) . " 个测试号的插件状态\n";
    exit(0);
}
DB::run("DELETE FROM users WHERE id IN ($in)", $ids);
echo '已清理 ' . count($ids) . " 个测试号\n";
