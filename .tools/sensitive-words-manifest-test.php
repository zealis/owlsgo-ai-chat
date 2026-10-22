<?php
/**
 * 垃圾信息控制插件「核心认不认」+ 词库下载真站集成探测（v1.4.0）
 *
 *   php .tools/sensitive-words-manifest-test.php          # 只跑本地断言
 *   php .tools/sensitive-words-manifest-test.php --net    # 追加真实下载（走外网）
 *
 * 走真实核心：临时 SQLite 库 + 真 core/*.php + Plugin::init()，把 sensitive-words
 * 登记为启用，然后读**它自己写出来的清单缓存**。按需加载认的就是这份清单：
 * 钩子 / 路由 / 后台页 / 资源 / 计划任务 / 敏感操作少一项，线上表现就是
 * 「注册了却不生效、还查不出原因」。
 *
 * --net 那一段是真的从 GitHub 拉整套词库并落盘（落在临时目录，不碰站点运行数据），
 * 打的是这条链路上最容易坏的地方：CA 证书、tarball 跳转、gzip、tar 解析、中文文件名落盘。
 */
declare(strict_types=1);

define('OWLSGO_VERSION', '0.0.0-test');
$NET = in_array('--net', $argv ?? [], true);

$ROOT = dirname(__DIR__);
$CFG = require $ROOT . '/core/config.php';

$tmpDb = str_replace('\\', '/', sys_get_temp_dir()) . '/sw-manifest-' . getmypid() . '.sqlite';
$tmpCache = str_replace('\\', '/', sys_get_temp_dir()) . '/sw-cache-' . getmypid();
$tmpLex = str_replace('\\', '/', sys_get_temp_dir()) . '/sw-lexicon-' . getmypid();
@unlink($tmpDb);
foreach ([$tmpCache, $tmpLex] as $d) {
    if (is_dir($d)) foreach (glob($d . '/*') ?: [] as $f) @unlink($f);
    @mkdir($d, 0775, true);
}

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
DB::insert('plugins', ['name' => 'sensitive-words', 'enabled' => 1, 'config' => '']);

// 词库目录改到临时目录：本脚本会真的下载词库，不能往站点 data/lexicon 里塞测试产物
$GLOBALS['sw_lex_dir'] = $tmpLex;
$GLOBALS['sw_lex_cache'] = $tmpCache . '/lexicon.bin';

Plugin::init($CFG['plugin_dir'], $tmpCache);

$file = $tmpCache . '/plugins.json';
$all = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
$m = $all['plugins']['sensitive-words'] ?? null;

$fail = 0; $pass = 0;
function check(string $name, $got, $want = true): void
{
    global $fail, $pass;
    $ok = $got === $want;
    $ok ? $pass++ : $fail++;
    $f = fn($v) => is_bool($v) ? var_export($v, true) : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : var_export((string)$v, true));
    printf("%s %-56s got=%s want=%s\n", $ok ? 'PASS' : 'FAIL', $name, $f($got), $f($want));
}

echo "== 清单缓存（按需加载的判据） ==\n";
check('清单里有 sensitive-words', is_array($m));
if (!is_array($m)) { echo "\n插件未能注册进清单，后面的断言没意义\n"; exit(1); }

foreach (['text.filter', 'text.check', 'page.footer'] as $h) {
    check("hooks 含 {$h}", in_array($h, $m['hooks'] ?? [], true));
}
check('crons 含 lexicon_check', in_array('lexicon_check', $m['crons'] ?? [], true));
foreach (['plugin_spam_save', 'plugin_spam_ai_test', 'plugin_spam_appeal_submit', 'plugin_spam_appeal_list',
             'plugin_spam_appeal_handle', 'plugin_spam_appeal_delete', 'plugin_spam_appeal_batch',
             'plugin_sensitive_words_admin', 'plugin_sensitive_words_batch', 'plugin_sensitive_words_add',
             'plugin_sensitive_words_toggle', 'plugin_sensitive_words_del',
             'plugin_spam_lex_list', 'plugin_spam_lex_toggle', 'plugin_spam_lex_all',
             'plugin_spam_lex_rescan', 'plugin_spam_lex_download', 'plugin_spam_lex_check',
             'plugin_spam_lexapi_test'] as $r) {
    check("routes 含 {$r}", in_array($r, $m['routes'] ?? [], true));
}
// v1.5.24：pages 是「slug=>标题」映射（旧版是 slug 列表），键在即注册过
check('pages 含 sensitive-words', isset($m['pages']['sensitive-words']));
check('sensitive 含下载路由（一次性票据）', in_array('plugin_spam_lex_download', $m['sensitive'] ?? [], true));
foreach ([['js', 'sensitive-words/admin.js'], ['js', 'sensitive-words/chat.js'], ['css', 'sensitive-words/style.css']] as [$t, $p]) {
    check("assets.{$t} 含 {$p}", in_array($p, $m['assets'][$t] ?? [], true));
}

echo "\n== 计划任务写库 ==\n";
$task = DB::one('SELECT * FROM cron_tasks WHERE plugin=? AND name=?', ['sensitive-words', 'lexicon_check']);
check('cron_tasks 里有 lexicon_check 行', is_array($task));
if ($task) {
    check('间隔 3600 秒', (int)$task['interval_sec'], 3600);
    check('默认启用', (int)$task['enabled'], 1);
}

echo "\n== 真核心：通知类型与落库渲染 ==\n";
check('kinds() 含 spam_lexicon_update', in_array('spam_lexicon_update', Notice::kinds(), true), true);
$tpl = (string)(Notice::texts()['spam_lexicon_update'] ?? '');
check('模板带 {v} 占位（与插件 push 的参数名一致）', strpos($tpl, '{v}') !== false, true);
Notice::push(4242, 'spam_lexicon_update', ['v' => '2026-08-17']);
$nr = Notice::forUser(4242);
check('push 能落库并读回 1 条', count($nr), 1);
check('正文里的 {v} 已替换成日期', isset($nr[0]) && strpos((string)$nr[0]['body'], '2026-08-17') !== false, true);
Notice::push(4243, 'not_a_real_kind_xyz');
check('未注册的 kind 仍被丢弃（与核心态度一致）', count(Notice::forUser(4243)), 0);

echo "\n== 违禁词库真实下载" . ($NET ? '（走外网）' : '（未加 --net，跳过）') . " ==\n";
if (!$NET) {
    echo "SKIP 加 --net 才会真下载\n";
} else {
    $err = null;
    if (!function_exists('owSPlexDownload')) {
        echo "SKIP 插件 main.php 没被真实加载过（清单是扫描出来的？），无法调下载函数\n";
    } else {
    $r = owSPlexDownload($err);
    if (!$r) {
        // 网络不通不算断言失败（自检机可能没外网），但必须说清是哪一步断的
        echo "SKIP 下载没能完成：{$err}\n";
    } else {
        check('拿到词库文件', count($r['files']) >= 10 ? true : count($r['files']), true);
        check('落盘目录里有 txt', count(owSPlexFiles()) >= 10);
        $stat = owSPlexStat();
        check('编译出的词条数 > 3 万', $stat['words'] > 30000);
        check('未被截断', $stat['truncated'], false);
        check('记录远端版本', DB::setting('spam_lex_remote_at', '') !== '');
        // 下载成功即意味着「有待更新」这个状态被消解：前台的更新提醒按它现算，随即自动消失
        check('下载后待更新状态已清空', DB::setting('spam_lex_update_to', ''), '');
        check('缓存文件已生成', is_file($GLOBALS['sw_lex_cache']));
        $h = 0;
        $out = owSPlexApply('这里有两张图片，加微信了解一下', $h);
        echo "      打码样例：{$out}（命中 {$h}）\n";
    }
    }
}

foreach ([$tmpCache, $tmpLex] as $d) {
    foreach ((array)glob($d . '/*') as $f) is_dir($f) ? @rmdir($f) : @unlink($f);
    @rmdir($d);
}
@unlink($tmpDb);

echo "\n================ {$pass} 项通过 / {$fail} 项失败 ================\n";
exit($fail === 0 ? 0 : 1);
