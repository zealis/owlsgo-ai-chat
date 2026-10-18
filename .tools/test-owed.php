<?php
/**
 * e2ee-dm 服务端纯函数自测（不连库、不起服务）
 *
 *   php .tools/test-owed.php
 *
 * 重点验的是「跨语言格式约定」：前端 owce.js 生成的信封，服务端解析器必须认；
 * 畸形信封必须一律拒绝（否则会把垃圾写进 messages.content，前端永远解不开）。
 */
define('OWLSGO_VERSION', 'test');

class Plugin
{
    public static function route($a, $f, $o = []) {}
    public static function on($h, $f) {}
    public static function adminPage($s, $t, $f) {}
    public static function asset($t, $p) {}
    public static function sensitive($a) {}
}
class DB
{
    public static function setting($k, $d = null) { return $d; }
    public static function setSetting($k, $v) {}
    public static function autoId() { return 'INTEGER PRIMARY KEY AUTOINCREMENT'; }
    public static function textCol($c, $d = "''") { return "$c TEXT NOT NULL DEFAULT $d"; }
    public static function createIndex($n, $t, $c, $u = false) {}
    public static function run($s, $a = []) { return new class { public function rowCount() { return 0; } }; }
    public static function val($s, $a = []) { return 0; }
    public static function one($s, $a = []) { return null; }
    public static function all($s, $a = []) { return []; }
    public static function insert($t, $d) { return 0; }
    public static function pdo() { return new class { public function beginTransaction() {} public function commit() {} public function rollBack() {} public function inTransaction() { return false; } }; }
}
class Api { public static function json($d, $c = 200) { echo json_encode($d, JSON_UNESCAPED_UNICODE); } }
class Sec { public static function log($a, $b = '', $c = []) {} public static function rateLimit($a, $b, $c, $d) { return true; } }
class Chat { public static function areFriends($a, $b) { return true; } }
function ow_icon($n, $s = 16) { return ''; }

require __DIR__ . '/../plugins/e2ee-dm/main.php';

$pass = 0;
$fail = 0;
function t($name, $cond, $extra = null)
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok    $name\n"; }
    else { $fail++; echo "  FAIL  $name" . ($extra !== null ? '  ' . json_encode($extra, JSON_UNESCAPED_UNICODE) : '') . "\n"; }
}

// 由 .tools/test-owee.js 的同构脚本产出的真实信封
$fixture = json_decode((string)file_get_contents(__DIR__ . '/owed-env.json'), true);
if (!is_array($fixture) || empty($fixture['env'])) {
    echo "缺少测试向量：先跑 node .tools/gen-owed-env.js 生成 .tools/owed-env.json\n";
    exit(1);
}
$env = $fixture['env'];

t('真实信封被识别', owEDIsEnvelope($env));
$p = owEDParseEnvelope($env);
t('真实信封解析成功', is_array($p));
t('版本号为 1', is_array($p) && $p['v'] === 1);
t('收件设备号与前端一致', is_array($p) && isset($p['m'][$fixture['devB']]), is_array($p) ? array_keys($p['m']) : null);
t('消息头带 dh 与序号', is_array($p) && is_string($p['m'][$fixture['devB']]['h']['dh']));

t('普通文字不算信封', !owEDIsEnvelope('你好，在吗'));
t('纯前缀不算信封', !owEDIsEnvelope('owee1:'));
t('坏 JSON 被拒', owEDParseEnvelope('owee1:{not json}') === null);
t('空 m 被拒', owEDParseEnvelope('owee1:' . json_encode(['v' => 1, 'm' => []])) === null);
t('版本不对被拒', owEDParseEnvelope('owee1:' . json_encode(['v' => 2, 'm' => ['abcdefgh' => ['h' => ['dh' => 'x'], 'c' => str_repeat('a', 20)]]])) === null);
t('设备号含非法字符被拒', owEDParseEnvelope('owee1:' . json_encode(['v' => 1, 'm' => ['../etc' => ['h' => ['dh' => 'x'], 'c' => str_repeat('a', 20)]]])) === null);
t('密文过短被拒', owEDParseEnvelope('owee1:' . json_encode(['v' => 1, 'm' => ['abcdefgh' => ['h' => ['dh' => 'x'], 'c' => 'short']]])) === null);
t('缺 h.dh 被拒', owEDParseEnvelope('owee1:' . json_encode(['v' => 1, 'm' => ['abcdefgh' => ['h' => [], 'c' => str_repeat('a', 20)]]])) === null);

t('会话键与方向无关', owEDConvKey(3, 9) === owEDConvKey(9, 3));
t('会话键形状', owEDConvKey(3, 9) === 'u3:u9');
t('默认模式为 off', owEDMode() === 'off');

echo "\n通过 $pass 项" . ($fail ? "，失败 $fail 项" : '，全部通过') . "\n";
exit($fail ? 1 : 0);
