<?php
/**
 * 内容举报插件「核心认不认」探测（v1.1.0）
 *
 *   php .tools/content-report-manifest-test.php
 *
 * 走真实核心：临时 SQLite 库 + 真 core/*.php + Plugin::init()，把 content-report
 * 登记为启用，然后读**它自己写出来的清单缓存**（data/cache/plugins.json 的那一份）。
 * 按需加载认的就是这份清单：钩子/路由/后台页/资源/计划任务/敏感操作少一项，
 * 线上表现就是「注册了却不生效、还查不出原因」。
 *
 * 不碰站点运行数据：库与缓存目录都在系统临时目录，收尾整体删除。
 */
declare(strict_types=1);

define('OWLSGO_VERSION', '0.0.0-test');

$ROOT = dirname(__DIR__);
$CFG = require $ROOT . '/core/config.php';

$tmpDb = sys_get_temp_dir() . '/cr-manifest-' . getmypid() . '.sqlite';
$tmpCache = sys_get_temp_dir() . '/cr-cache-' . getmypid();
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
// 插件默认不在 plugins 表注册 —— 手动登记为启用（与后台「启用」同一行）
DB::insert('plugins', ['name' => 'content-report', 'enabled' => 1, 'config' => '']);

Plugin::init($CFG['plugin_dir'], $tmpCache);

$file = $tmpCache . '/plugins.json';
// 缓存结构：{"v":2,"plugins":{"<插件ID>":{hooks/routes/pages(=>slug=>标题映射)/fpages/sensitive/crons/assets/...}}}
$all = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
$m = $all['plugins']['content-report'] ?? null;

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
check('清单里有 content-report', is_array($m));
if (!is_array($m)) { echo "清单没生成，后面的断言无意义\n"; exit(1); }

$hooks = $m['hooks'] ?? [];
$routes = $m['routes'] ?? [];
$pages = $m['pages'] ?? [];
$sens = $m['sensitive'] ?? [];
$assets = $m['assets'] ?? ['css' => [], 'js' => []];

echo "\n== 钩子 ==\n";
check('notice.contacts（通知联系人）', has($hooks, 'notice.contacts'));
check('page.head（插件 CSS 必须自己挂 link）', has($hooks, 'page.head'));
check('没有迷信「page.footer 注入脚本」的写法', has($hooks, 'page.footer'), false);

echo "\n== 路由（全部带前缀） ==\n";
foreach ([
    'plugin_content_report_config_get', 'plugin_content_report_config_save',
    'plugin_content_report_ai_test', 'plugin_content_report_list',
    'plugin_content_report_handle', 'plugin_content_report_batch',
    'plugin_content_report_delete', 'plugin_content_report_get_reasons',
    'plugin_content_report_upload', 'plugin_content_report_submit',
    'plugin_content_report_alerts', 'plugin_content_report_upload_avatar',
    'plugin_content_report_notify_avatar', 'plugin_content_report_box',
    'plugin_content_report_seen', 'plugin_content_report_result',
] as $act) check('  ' . $act, has($routes, $act));
check('路由条数正好 16（没有漏注册/多注册）', count($routes), 16);

echo "\n== 敏感操作 ==\n";
foreach (['plugin_content_report_config_save', 'plugin_content_report_handle',
          'plugin_content_report_batch', 'plugin_content_report_delete',
          'plugin_content_report_notify_avatar', 'plugin_content_report_seen'] as $act) {
    check('  需一次性票据：' . $act, has($sens, $act));
}
check('上传/提交/列表不是敏感操作（前台正常点用）',
    has($sens, 'plugin_content_report_submit'), false);
check('消息箱/结论详情不是敏感操作（只看不发）',
    has($sens, 'plugin_content_report_box'), false);
check('头像上传也不是敏感操作（与核心头像上传同一口径）',
    has($sens, 'plugin_content_report_upload_avatar'), false);

echo "\n== 后台页与资源 ==\n";
// v1.5.24：pages 是「slug=>标题」映射（旧版是 slug 列表），键在即注册过
check('后台页 slug', isset($pages['content-report']));
check('CSS 只注册 style.css', $assets['css'], ['content-report/style.css']);
check('两个 JS 都注册', $assets['js'], ['content-report/chat.js', 'content-report/admin.js']);

echo "\n== 计划任务 ==\n";
check('crons 里有 ai_review', has($m['crons'] ?? [], 'ai_review'));
$task = DB::one('SELECT interval_sec, enabled, next_run_at FROM cron_tasks WHERE plugin=? AND name=?',
    ['content-report', 'ai_review']);
check('已写进 cron_tasks 表', is_array($task));
check('间隔 60 秒', (int)($task['interval_sec'] ?? 0), 60);
check('默认启用', (int)($task['enabled'] ?? 0), 1);
// description 不存在表里：后台「计划任务」页的说明取自插件注册时的第三个参数
$desc = '';
foreach (Plugin::crons() as $c) if (($c['plugin'] ?? '') === 'content-report' && $c['name'] === 'ai_review') $desc = $c['description'];
check('注册时带了中文说明（后台列表要显示）', strpos($desc, 'AI 审核') !== false, true);
check('处理器是可调用的（不是空注册）', is_callable('owCRAiTick'), true);

echo "\n== 插件加载没有静默失败 ==\n";
$errs = DB::all("SELECT action, data FROM security_logs WHERE action LIKE 'plugin%' ORDER BY id DESC LIMIT 5");
check('没有 plugin_error 之类的加载错误', $errs, []);

/* page.head 的注入内容：走 Plugin::fire()（钩子是按清单按需加载的，fire 到就 require 并调用），
   而不是去读 Plugin::$hooks —— 那是核心的私有实现细节，测试不该依赖它的可见性。 */
ob_start();
Plugin::fire('page.head');
$head = (string)ob_get_clean();
check('page.head 里输出 css <link>（主程序不会自动引插件 css）',
    strpos($head, 'action=assets&type=css') !== false, true);
check('只取本插件那一份（plugin= + file=，不打包全站插件 CSS）',
    strpos($head, 'plugin=content-report&file=style.css') !== false, true);

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
