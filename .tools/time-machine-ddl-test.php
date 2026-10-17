<?php
/**
 * 时光机建表语句「三驱动」断言（time-machine v1.1.1）
 *
 *   php .tools/time-machine-ddl-test.php
 *
 * 为什么要有这个脚本：v1.1.0 的 `content  {$txt}`（$txt = DB::textCol('content')）
 * 生成了 `content content TEXT NULL`。SQLite 把它当成「列名 + 多词类型名」容忍了
 * （本地一路绿灯），MySQL 直接 1064 —— 线上 owTmBoot() 建表即抛异常，
 * 全部路由 500，前端只剩「网络错误（500）」+「请先登录」。这类方言坑本地 SQLite
 * 测不出来，只能对**生成物本身**做断言。
 *
 * 做法：用反射把 DB::$driver 分别切成 sqlite / mysql / pgsql，各生成一遍 DDL，
 * 逐条查：①列名没有被重复写两遍（1064 的直接形态）；②列数/列名与预期一致；
 * ③MySQL 下 TEXT 列不带 DEFAULT（核心 textCol 已保证，这里守住回归）。
 */
declare(strict_types=1);
define('OWLSGO_VERSION', '0.0.0-ddl');

$ROOT = dirname(__DIR__);
require $ROOT . '/core/db.php';
require $ROOT . '/core/security.php';
require $ROOT . '/core/auth.php';
require $ROOT . '/core/plugin.php';
require $ROOT . '/core/chat.php';
require $ROOT . '/core/notice.php';
require $ROOT . '/core/upload.php';
require $ROOT . '/core/mail.php';

// 临时 SQLite 库：只为让 Plugin::route/asset 这类注册调用有库可用（本脚本不做任何读写）
$tmpDb = sys_get_temp_dir() . '/tm-ddl-' . getmypid() . '.sqlite';
@unlink($tmpDb);
$CFG = require $ROOT . '/core/config.php';
$CFG['db']['sqlite'] = $tmpDb;
DB::init($CFG);
DB::migrate();   // plugins 表等核心结构（本脚本不写业务数据）
Plugin::init($ROOT . '/plugins', sys_get_temp_dir() . '/tm-ddl-cache-' . getmypid());
require $ROOT . '/plugins/time-machine/main.php';

$fail = 0;
function check(string $name, $got, $want = true): void
{
    global $fail;
    $ok = $got === $want;
    if (!$ok) $fail++;
    $f = fn($v) => is_bool($v) ? var_export($v, true) : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : var_export((string)$v, true));
    printf("%s %-58s got=%s want=%s\n", $ok ? 'PASS' : 'FAIL', $name, $f($got), $f($want));
}

$expectCols = [
    'plugin_time_machine_post'    => ['id', 'uid', 'nickname', 'avatar', 'role', 'content', 'images', 'views', 'likes', 'comments', 'created_at'],
    'plugin_time_machine_comment' => ['id', 'post_id', 'uid', 'nickname', 'avatar', 'role', 'content', 'media', 'created_at'],
    'plugin_time_machine_like'    => ['post_id', 'uid', 'created_at'],
    'plugin_time_machine_fav'     => ['post_id', 'uid', 'created_at'],
    'plugin_time_machine_view'    => ['post_id', 'viewer', 'seen_at'],
];

$ref = new ReflectionClass('DB');
$prop = $ref->getProperty('driver');
$prop->setAccessible(true);

foreach (['sqlite', 'mysql', 'pgsql'] as $drv) {
    $prop->setValue(null, $drv);
    echo "\n===== driver = {$drv} =====\n";
    $ddl = owTmDdl();
    check("  表数量 5", count($ddl) === 5);
    foreach ($expectCols as $table => $cols) {
        $sql = $ddl[$table] ?? '';
        // ① 1064 的直接形态：同一标识符被连写两遍（`content content TEXT`）
        $doubled = preg_match('/\b([a-z_][a-z0-9_]*)\s+\1\b/i', $sql, $m);
        check("  {$table}：无重复列名（1064 形态）", $doubled === 0);
        if ($doubled) echo "      → 重复片段：{$m[0]}\n";
        // ② 列齐全
        $missing = [];
        foreach ($cols as $c) {
            if (!preg_match('/(^|[\s,(])' . preg_quote($c, '/') . '\s/i', $sql)) $missing[] = $c;
        }
        check("  {$table}：列齐全", $missing, []);
        // ③ MySQL：TEXT 列不允许 DEFAULT（错误 1101）
        if ($drv === 'mysql') {
            $bad = preg_match('/TEXT\s+[^,\n]*DEFAULT/i', $sql);
            check("  {$table}：TEXT 列不带 DEFAULT", $bad === 0);
        }
    }
    if ($drv === 'mysql') {
        echo "  样例（post 表前几行）：\n";
        foreach (array_slice(explode("\n", $ddl['plugin_time_machine_post']), 0, 7) as $l) echo "    " . trim($l) . "\n";
    }
}

/* 顺带扫一遍全站插件：还有没有同款「列名写两遍」的写法 */
echo "\n===== 全站插件同名写法扫描 =====\n";
$bad2 = [];
foreach (glob($ROOT . '/plugins/*/main.php') ?: [] as $f) {
    foreach (file($f) ?: [] as $n => $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === '*' || strpos($t, '//') === 0 || $t[0] === '#') continue;   // 跳过注释行
        if (preg_match_all('/([a-z_][a-z0-9_]*)\s+"\s*\.\s*DB::textCol\(\s*\'\1\'\s*\)/i', $line, $mm)) {
            $bad2[] = basename(dirname($f)) . ':' . ($n + 1) . ' → ' . trim($mm[0][0]);
        }
    }
}
check('其它插件没有「列名 + textCol 同列名」的写法', $bad2, []);
if ($bad2) foreach ($bad2 as $b) echo "      {$b}\n";

echo "\n" . ($fail === 0 ? "全部通过\n" : "有 {$fail} 项失败\n");
exit($fail === 0 ? 0 : 1);
