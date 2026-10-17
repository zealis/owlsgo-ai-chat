<?php
/**
 * 时光机插件「核心认不认」探测（v1.0.0）
 *
 *   php .tools/time-machine-manifest-test.php
 *
 * 走真实核心：临时 SQLite 库 + 真 core/*.php + Plugin::init()，把 time-machine
 * 登记为启用，然后读它自己写出来的清单缓存。附带验证 rail 入口输出、page.head
 * 的 CSS 注入、懒建表与 plugin_error 静默失败。
 * 不碰站点运行数据：库与缓存目录都在系统临时目录，收尾整体删除。
 */
declare(strict_types=1);

define('OWLSGO_VERSION', '0.0.0-test');

$ROOT = dirname(__DIR__);
$CFG = require $ROOT . '/core/config.php';

$tmpDb = sys_get_temp_dir() . '/tm-manifest-' . getmypid() . '.sqlite';
$tmpCache = sys_get_temp_dir() . '/tm-cache-' . getmypid();
@unlink($tmpDb);
if (is_dir($tmpCache)) foreach (glob($tmpCache . '/*') ?: [] as $f) @unlink($f);
@mkdir($tmpCache, 0775, true);

$CFG['db']['sqlite'] = $tmpDb;
$CFG['plugin_dir'] = $ROOT . '/plugins';

require $ROOT . '/core/db.php';
require $ROOT . '/core/security.php';
require $ROOT . '/core/mail.php';
require $ROOT . '/core/auth.php';
require $ROOT . '/core/plugin.php';
require $ROOT . '/core/chat.php';
require $ROOT . '/core/notice.php';
require $ROOT . '/core/upload.php';
require $ROOT . '/core/upgrade.php';

DB::init($CFG);
DB::migrate();
DB::insert('plugins', ['name' => 'time-machine', 'enabled' => 1, 'config' => '']);

Plugin::init($CFG['plugin_dir'], $tmpCache);

$file = $tmpCache . '/plugins.json';
$all = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
$m = $all['plugins']['time-machine'] ?? null;

$fail = 0;
function check(string $name, $got, $want = true): void
{
    global $fail;
    $ok = $got === $want;
    if (!$ok) $fail++;
    $f = fn($v) => is_bool($v) ? var_export($v, true) : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : var_export((string)$v, true));
    printf("%s %-52s got=%s want=%s\n", $ok ? 'PASS' : 'FAIL', $name, $f($got), $f($want));
}
function has(array $list, string $v): bool { return in_array($v, $list, true); }

echo "== 清单缓存（按需加载认的就是它） ==\n";
check('清单里有 time-machine', is_array($m));
if (!is_array($m)) { echo "清单没生成，后面的断言无意义\n"; exit(1); }

$hooks = $m['hooks'] ?? [];
$routes = $m['routes'] ?? [];
$sens = $m['sensitive'] ?? [];
$assets = $m['assets'] ?? ['css' => [], 'js' => []];

echo "\n== 钩子 ==\n";
check('sidebar.rail（rail 入口按钮）', has($hooks, 'sidebar.rail'));
check('page.head（插件 CSS 必须自己挂 link）', has($hooks, 'page.head'));

echo "\n== 路由（全部带前缀） ==\n";
foreach ([
    'plugin_time_machine_feed', 'plugin_time_machine_post',
    'plugin_time_machine_download', 'plugin_time_machine_like',
    'plugin_time_machine_fav', 'plugin_time_machine_comments',
    'plugin_time_machine_comment_add', 'plugin_time_machine_delete',
] as $act) check('  ' . $act, has($routes, $act));
check('路由条数正好 8（循环注册的两个开关路由也算数）', count($routes), 8);
check('没有自己的上传路由（附件完全依赖附件上传插件）', has($routes, 'plugin_time_machine_upload'), false);

echo "\n== 敏感操作 ==\n";
check('删除波文需一次性票据', has($sens, 'plugin_time_machine_delete'));
check('发布不是敏感操作（前台正常点用）', has($sens, 'plugin_time_machine_post'), false);
check('点赞/收藏不是敏感操作', has($sens, 'plugin_time_machine_like'), false);

echo "\n== 资源 ==\n";
check('CSS 只注册 style.css', $assets['css'], ['time-machine/style.css']);
check('JS 只注册 chat.js', $assets['js'], ['time-machine/chat.js']);

echo "\n== rail 入口与 page.head 注入 ==\n";
/* rail 是 collect 型钩子（HTML 靠返回值拼接，fire 会把返回值丢掉），必须走 collect。
   collect 内部会按清单按需加载插件再调用回调 —— 与 index.php 渲染 rail 同一条路。 */
$rail = Plugin::collect('sidebar.rail');
check('rail 按钮带 data-tab=timemachine（与前端 onRail 同名）',
    strpos($rail, 'data-tab="timemachine"') !== false, true);
check('rail 按钮带 ow-rail-lb 文字标签（窄屏沉底要显示）',
    strpos($rail, 'ow-rail-lb') !== false, true);
check('图标 fill=currentColor（跟随主题，不写死颜色）',
    strpos($rail, 'fill="currentColor"') !== false, true);

ob_start();
Plugin::fire('page.head');
$head = (string)ob_get_clean();
check('page.head 里输出 css <link>（主程序不会自动引插件 css）',
    strpos($head, 'action=assets&type=css') !== false, true);
check('只取本插件那一份（plugin= + file=）',
    strpos($head, 'plugin=time-machine&file=style.css') !== false, true);

echo "\n== 懒建表 ==\n";
check('owTmBoot 可调用（main.php 已被清单加载）', is_callable('owTmBoot'), true);
owTmBoot();
foreach (['plugin_time_machine_post', 'plugin_time_machine_comment',
          'plugin_time_machine_like', 'plugin_time_machine_fav',
          'plugin_time_machine_view'] as $t) {
    $n = (int)DB::val("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?", [$t]);
    check('  表存在：' . $t, $n === 1);
}

echo "\n== 插件加载没有静默失败 ==\n";
$errs = DB::all("SELECT action, data FROM security_logs WHERE action LIKE 'plugin%' ORDER BY id DESC LIMIT 5");
check('没有 plugin_error 之类的加载错误', $errs, []);

/* 收尾：临时库与缓存目录整体删掉。
   ⚠️ Windows 下 PDO 还开着时 unlink() 会静默失败 → 先用反射把静态句柄置空。 */
$ref = new ReflectionClass('DB');
$pdoProp = $ref->getProperty('pdo');
$pdoProp->setAccessible(true);
$pdoProp->setValue(null, null);
$left = [];
foreach (glob($tmpCache . '/*') ?: [] as $f) if (!@unlink($f)) $left[] = $f;
if (!@rmdir($tmpCache)) $left[] = $tmpCache;
if (is_file($tmpDb) && !@unlink($tmpDb)) $left[] = $tmpDb;
if ($left) printf("WARN 临时文件没删干净：%s\n", implode(', ', $left));

echo "\n" . ($fail === 0 ? "全部通过\n" : "有 {$fail} 项失败\n");
exit($fail === 0 ? 0 : 1);
