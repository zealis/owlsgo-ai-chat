<?php
/** 语音消息插件 E2E 的账号 / 房间 / 启用准备（对 phpStudy 运行副本的库）
 *   php .tools/voice-msg-e2e-setup.php "<站点根目录>"
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$root = $argv[1] ?? dirname(__DIR__);
$root = rtrim($root, '/\\');
if (!is_file($root . '/core/config.php')) { fwrite(STDERR, "找不到 $root/core/config.php\n"); exit(1); }

$CFG = require $root . '/core/config.php';
require $root . '/core/db.php';
DB::init($CFG);

// ① 启用插件（幂等）
DB::upsert('plugins', ['name' => 'voice-msg', 'enabled' => 1, 'config' => ''], ['name']);
echo "plugin enabled: voice-msg\n";

// ② 测试账号（已存在则复用）
$email = 'voice-test@test.local';
$uid = (int)DB::val('SELECT id FROM users WHERE email=?', [$email]);
if (!$uid) {
    $uid = (int)DB::insert('users', [
        'nickname' => '语音测试', 'email' => $email,
        'password' => password_hash('VoiceTest123456', PASSWORD_DEFAULT),
        'role' => 'member', 'status' => 1, 'email_verified' => 1,
        'created_at' => time(), 'last_login' => time(),
    ]);
    echo "user created: $uid\n";
} else {
    echo "user exists: $uid\n";
}

// ③ 测试群（公开房，站主已是成员；确保测试号在成员表里）
$roomId = (int)DB::val('SELECT id FROM rooms WHERE name=?', ['语音测试群']);
if (!$roomId) {
    $roomId = (int)DB::insert('rooms', [
        'name' => '语音测试群', 'slug' => 'voice-test-room', 'type' => 'public', 'owner_id' => $uid,
        'description' => '语音消息插件 E2E', 'created_at' => time(),
    ]);
    echo "room created: $roomId\n";
} else {
    echo "room exists: $roomId\n";
}
$inRoom = DB::val('SELECT COUNT(*) FROM room_members WHERE room_id=? AND user_id=?', [$roomId, $uid]);
if (!$inRoom) {
    DB::insert('room_members', ['room_id' => $roomId, 'user_id' => $uid, 'created_at' => time()]);
    echo "membership added\n";
}
echo json_encode(['uid' => $uid, 'room' => $roomId], JSON_UNESCAPED_UNICODE), "\n";
