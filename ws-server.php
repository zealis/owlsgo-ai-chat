<?php
/**
 * WS 实时层 · CLI 入口（P1）
 *
 * 用法（须在站点根目录执行，读的就是该目录的 config.php 与 data/）：
 *   php ws-server.php                                  # 前台启动（本机调试）
 *   pm2 start "php ws-server.php" --name owlsgo-ws     # Linux 生产（PM2 守护）
 *
 * 守卫：只允许命令行启动（网页访问直接 403）；单例锁防重复启动；
 * 引导链与 index.php 同源，不做任何自己的配置读取——端口/开关都走 DB settings。
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

define('OWLSGO_VERSION', trim((string)@file_get_contents(__DIR__ . '/VERSION')) ?: '0');
define('OWLSGO_ASSET_VERSION', trim((string)@file_get_contents(__DIR__ . '/ASSET_VERSION')) ?: OWLSGO_VERSION);

$CFG = require __DIR__ . '/core/config.php';
date_default_timezone_set($CFG['timezone'] ?? 'Asia/Shanghai');

require __DIR__ . '/core/db.php';
require __DIR__ . '/core/cache.php';
require __DIR__ . '/core/security.php';
require __DIR__ . '/core/auth.php';
require __DIR__ . '/core/plugin.php';
require __DIR__ . '/core/chat.php';
require __DIR__ . '/core/realtime_ws.php';

DB::init($CFG);
// 插件按需加载清单：online.track（redis 在场镜像）等钩子要经它分发，与 index.php 同一套参数
Plugin::init($CFG['plugin_dir'], $CFG['data_dir'] . '/cache');

$dataDir = (string)($CFG['data_dir'] ?? __DIR__ . '/data');
@mkdir($dataDir, 0775, true);

// 单例锁：PM2 保证单实例，这里再兜一道——误敲两次命令时第二个进程能立刻发现而不是端口报错
$lock = @fopen($dataDir . '/ws-server.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "另一个 ws-server 实例已在运行（锁 {$dataDir}/ws-server.lock）\n");
    exit(1);
}

$port = (int)DB::setting('ws_port', '8790');
if ($port < 1 || $port > 65535) $port = 8790;

// 端口占用自检（实测踩过）：Windows 的 SO_REUSEADDR 语义允许**第二个进程也绑定成功**，
// 于是两个实例互抢连接、双方日志都干净却谁都收不到握手 —— 只靠 flock 不够硬。
// 绑定前先探测一次：端口已有监听者就明确退出，不做「假启动」。
// ⚠️ 探测用 127.0.0.1：本机开发时守护进程应只有一个；生产（Linux）bind 本身就会报占用，这层只是更快更明确。
$probe = @fsockopen('127.0.0.1', $port, $probeNo, $probeStr, 0.5);
if ($probe) {
    fclose($probe);
    fwrite(STDERR, "端口 {$port} 已被占用（已有 ws-server 在跑？先停掉旧实例，或在后台改 ws_port）\n");
    exit(1);
}

try {
    RealtimeWs::run($port, $dataDir);
} catch (Throwable $e) {
    fwrite(STDERR, '启动失败：' . $e->getMessage() . "\n");
    exit(1);
}
