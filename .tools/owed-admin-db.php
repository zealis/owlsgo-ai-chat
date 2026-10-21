<?php
/**
 * 后台设备列表 E2E 的数据库助手
 *
 *   php .tools/owed-admin-db.php seed   "<站点根目录>"   # 建临时管理员 + 3 台设备，打印用户 id
 *   php .tools/owed-admin-db.php count  "<站点根目录>"   # 打印测试设备数
 *   php .tools/owed-admin-db.php clean  "<站点根目录>"   # 删临时管理员与其设备/预密钥
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$mode = $argv[1] ?? 'count';
$root = rtrim($argv[2] ?? dirname(__DIR__), '/\\');
$CFG = require $root . '/core/config.php';
require $root . '/core/db.php';
DB::init($CFG);

const EMAIL = 'owed-adm@test.local';
const PW = 'OwedAdm123456';

if ($mode === 'seed') {
    $id = (int)DB::val('SELECT id FROM users WHERE email=?', [EMAIL]);
    if (!$id) {
        $id = (int)DB::insert('users', [
            'nickname' => '设备列表自检', 'email' => EMAIL,
            'password' => password_hash(PW, PASSWORD_DEFAULT),
            'role' => 'admin', 'status' => 1, 'email_verified' => 1,
            'created_at' => time(), 'last_login' => time(),
        ]);
    }
    foreach (['device-alpha-01', 'device-beta-02', 'device-gamma-03'] as $n) {
        DB::run('DELETE FROM plugin_e2ee_devices WHERE dev_id=?', [$n]);
        DB::insert('plugin_e2ee_devices', [
            'user_id' => $id, 'dev_id' => $n, 'ik_dh' => 'x', 'ik_sig' => 'x',
            'spk' => 'x', 'spk_id' => 1, 'spk_sig' => 'x', 'dev_name' => $n,
            'ua' => 'test', 'created_at' => time(),
        ]);
    }
    echo $id, "\n";
    exit(0);
}
if ($mode === 'clean') {
    $id = (int)DB::val('SELECT id FROM users WHERE email=?', [EMAIL]);
    if ($id) {
        DB::run('DELETE FROM plugin_e2ee_devices WHERE user_id=?', [$id]);
        DB::run('DELETE FROM plugin_e2ee_opk WHERE user_id=?', [$id]);
        DB::run('DELETE FROM users WHERE id=?', [$id]);
    }
    echo "cleaned\n";
    exit(0);
}
echo (int)DB::val("SELECT COUNT(*) FROM plugin_e2ee_devices WHERE dev_id LIKE 'device-%'"), "\n";
