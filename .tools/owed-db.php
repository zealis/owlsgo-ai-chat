<?php
/**
 * E2E 用的只读查询助手：把几条固定查询的结果打成 JSON，供 Node 侧断言
 *
 *   php .tools/owed-db.php "<站点根目录>"
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = $argv[1] ?? dirname(__DIR__);
$root = rtrim($root, '/\\');
$CFG = require $root . '/core/config.php';
require $root . '/core/db.php';
DB::init($CFG);

$out = [
    'devices' => DB::all('SELECT user_id, dev_id, dev_name, created_at FROM plugin_e2ee_devices ORDER BY id'),
    'opk_left' => (int)DB::val('SELECT COUNT(*) FROM plugin_e2ee_opk WHERE used=0'),
    'opk_used' => (int)DB::val('SELECT COUNT(*) FROM plugin_e2ee_opk WHERE used=1'),
    'conv' => DB::all('SELECT conv, enabled, enabled_by FROM plugin_e2ee_conv'),
    'enc_msgs' => DB::all('SELECT msg_id, conv, from_uid, to_uid, dev_from FROM plugin_e2ee_msg ORDER BY id'),
    'mode' => (string)DB::setting('plugin_e2ee_dm_mode', 'off'),
];
$rows = DB::all("SELECT id, user_id, to_user_id, type, content, created_at FROM messages
                 WHERE room_id=0 ORDER BY id DESC LIMIT 10");
foreach ($rows as &$r) {
    $c = (string)$r['content'];
    $r['enc'] = strncmp($c, 'owee1:', 6) === 0 ? 1 : 0;
    $r['len'] = strlen($c);
    $r['head'] = $r['enc'] ? substr($c, 0, 60) : mb_substr($c, 0, 40);
}
unset($r);
$out['dms'] = array_reverse($rows);
echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
