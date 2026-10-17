<?php
/**
 * 时光机插件全链路流程测试（v1.1.1）
 *
 *   php .tools/time-machine-flow-test.php
 *
 * 在临时 SQLite 上走真实路由闭包（Plugin::dispatch）：feed / post / upload 校验 /
 * like / fav / comments / comment_add / delete（含权限判定与敏感清单），
 * 图片地址复核用真实 Upload（临时上传目录 + 真实文件）。
 * Api::json 在 CLI 里以异常桩替代（核心 Api 在 index.php，本 harness 不加载它）；
 * 定义 OW_TM_TEST_HARNESS 让插件路由的异常兜底知道「这个异常不是错误」。
 */
declare(strict_types=1);

define('OWLSGO_VERSION', '0.0.0-test');
define('OW_TM_TEST_HARNESS', true);

/* ow_abs_url / ow_site_url 定义在 index.php（harness 不加载它），给最小桩 */
if (!function_exists('ow_site_url')) {
    function ow_site_url(bool $manualOnly = false): string { return ''; }
    function ow_abs_url(string $path, bool $manualOnly = true): string { return $path; }
}

/* ---------- Api 桩：json() 抛异常代替 exit ---------- */
class ApiExit extends Exception
{
    public array $data;
    public function __construct(array $data, int $code)
    {
        $this->data = $data;
        parent::__construct('api-exit', $code);   // 复用 Exception 自带的 code 属性
    }
}
class Api
{
    public static function json(array $data, int $code = 200): void
    {
        throw new ApiExit($data, $code);
    }
}

$ROOT = dirname(__DIR__);
$CFG = require $ROOT . '/core/config.php';

$tmpDb = sys_get_temp_dir() . '/tm-flow-' . getmypid() . '.sqlite';
$tmpCache = sys_get_temp_dir() . '/tm-flow-cache-' . getmypid();
$tmpUp = sys_get_temp_dir() . '/tm-flow-up-' . getmypid();
@unlink($tmpDb);
foreach ([$tmpCache, $tmpUp] as $rm) {          // 只有目录需要建；$tmpDb 是文件，绝不能 mkdir
    if (is_dir($rm)) foreach (glob($rm . '/*') ?: [] as $f) @unlink($f);
    @mkdir($rm, 0775, true);
}

$CFG['db']['sqlite'] = $tmpDb;
$CFG['plugin_dir'] = $ROOT . '/plugins';
$CFG['upload'] = ['dir' => $tmpUp, 'url' => 'uploads', 'max_size' => 5 * 1024 * 1024];

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
Upload::init($CFG);
// CLI 下没有会话：预置匿名 cookie，避免 Sec::anonKey() 在已输出之后 setcookie 触发 header 警告
$_COOKIE['owl_akey'] = str_repeat('a', 32);
DB::insert('plugins', ['name' => 'time-machine', 'enabled' => 1, 'config' => '']);
Plugin::init($CFG['plugin_dir'], $tmpCache);

/* ---------- 测试用户 ---------- */
function mkUser(string $nick, string $role = 'user'): int
{
    return (int)DB::insert('users', [
        'nickname' => $nick, 'password' => 'x', 'email' => $nick . '@t.local',
        'role' => $role, 'status' => 1, 'created_at' => time(),
    ]);
}
$uidA = mkUser('小明');
$uidB = mkUser('小红');
$uidAdmin = mkUser('管理员', 'admin');
$uA = DB::one('SELECT * FROM users WHERE id=?', [$uidA]);
$avA = Auth::avatarUrlFor($uA);
$actA = ['kind' => 'user', 'id' => $uidA, 'role' => 'user', 'nickname' => '小明'];
$actB = ['kind' => 'user', 'id' => $uidB, 'role' => 'user', 'nickname' => '小红'];
$actAdmin = ['kind' => 'user', 'id' => $uidAdmin, 'role' => 'admin', 'nickname' => '管理员'];
$actGuest = ['kind' => 'guest', 'id' => 0, 'role' => 'guest'];

$fail = 0;
function check(string $name, $got, $want = true): void
{
    global $fail;
    $ok = $got === $want;
    if (!$ok) $fail++;
    $f = fn($v) => is_bool($v) ? var_export($v, true) : (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : var_export((string)$v, true));
    printf("%s %-56s got=%s want=%s\n", $ok ? 'PASS' : 'FAIL', $name, $f($got), $f($want));
}

/** 调一次插件路由，返回 [data, code] */
function call(string $action, array $ctx): array
{
    try {
        Plugin::dispatch($action, $ctx);
    } catch (ApiExit $e) {
        return [$e->data, $e->getCode()];
    }
    return [null, 0];   // 路由没拦（不该发生：所有分支都应以 Api::json 结束）
}

/* 真实图片产物：走 Upload::local 的哈希落盘（手工构造一份 1x1 png） */
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$tmpPng = $tmpUp . '/.src-' . getmypid() . '.png';
file_put_contents($tmpPng, $png);
$fakeUpload = ['error' => UPLOAD_ERR_OK, 'size' => strlen($png), 'tmp_name' => $tmpPng, 'name' => 'a.png'];
[$upOk, $imgUrl] = Upload::local($fakeUpload, 'image');
check('Upload::local 产出本站图片地址（mediaRel 能认回）', $upOk && Upload::mediaRel($imgUrl) !== null);

echo "\n== 游客与空流 ==\n";
[$d] = call('plugin_time_machine_feed', ['actor' => $actGuest, 'post' => ['tab' => 'new', 'page' => 1]]);
check('游客可看流', $d['ok'] === true && $d['posts'] === []);
check('游客不可发', $d['can_post'] === false);
[$d] = call('plugin_time_machine_feed', ['actor' => $actA, 'post' => ['tab' => 'new', 'page' => 1]]);
check('登录用户 can_post=true', $d['can_post'] === true);

echo "\n== 发波文 ==\n";
[$d, $c] = call('plugin_time_machine_post', ['actor' => $actA, 'post' => ['content' => '', 'images' => '[]']]);
check('纯空内容被拒', $c === 400);
[$d, $c] = call('plugin_time_machine_post', ['actor' => $actGuest, 'post' => ['content' => 'hi']]);
check('游客发帖被拒（403）', $c === 403);
[$d, $c] = call('plugin_time_machine_post', ['actor' => $actA, 'post' => [
    'content' => "第一条波文\n配图测试",
    'images' => json_encode([$imgUrl, 'https://evil.example/x.png', 'uploads/image/no/no-such.jpg']),
]]);
check('发布成功', $c === 200 && $d['ok'] === true);
$pid = (int)$d['id'];
$row = DB::one('SELECT * FROM plugin_time_machine_post WHERE id=?', [$pid]);
check('正文与作者快照落库', (string)$row['nickname'] === '小明' && strpos((string)$row['content'], '配图测试') !== false);
$imgs = json_decode((string)$row['images'], true);
check('配图：本站产物保留、外链与伪造地址丢弃', $imgs, [$imgUrl]);

echo "\n== 点赞 / 收藏 ==\n";
[$d] = call('plugin_time_machine_like', ['actor' => $actB, 'post' => ['id' => $pid]]);
check('B 点赞 on=true likes=1', $d['on'] === true && $d['likes'] === 1);
[$d] = call('plugin_time_machine_like', ['actor' => $actB, 'post' => ['id' => $pid]]);
check('B 再点取消 on=false likes=0', $d['on'] === false && $d['likes'] === 0);
[$d] = call('plugin_time_machine_fav', ['actor' => $actB, 'post' => ['id' => $pid]]);
check('B 收藏 on=true', $d['on'] === true);

echo "\n== 评论 ==\n";
[$d] = call('plugin_time_machine_comment_add', ['actor' => $actB, 'post' => ['post_id' => $pid, 'content' => '沙发！']]);
check('评论成功且回填行', $d['ok'] === true && $d['comment']['content'] === '沙发！');
check('计数回传 comments=1', $d['comments'] === 1);
[$d] = call('plugin_time_machine_comments', ['actor' => $actA, 'post' => ['post_id' => $pid]]);
check('评论列表 1 条', count($d['comments']) === 1 && (int)$d['comments'][0]['uid'] === $uidB);
[$d, $c] = call('plugin_time_machine_comment_add', ['actor' => $actB, 'post' => ['post_id' => $pid, 'content' => str_repeat('长', 501)]]);
check('超长评论被拒（400）', $c === 400);

echo "\n== 浏览去重（v1.1.0） ==\n";
[$d] = call('plugin_time_machine_feed', ['actor' => $actGuest, 'post' => ['tab' => 'new', 'page' => 1]]);
$p0 = $d['posts'][0] ?? [];
check('游客首次浏览计 1', count($d['posts']) === 1 && $p0['views'] === 1);
[$d] = call('plugin_time_machine_feed', ['actor' => $actGuest, 'post' => ['tab' => 'new', 'page' => 1]]);
check('同一游客刷新不重复计数（仍 1）', $d['posts'][0]['views'] === 1);
[$d] = call('plugin_time_machine_feed', ['actor' => $actB, 'post' => ['tab' => 'new', 'page' => 1]]);
check('另一个账号 B 浏览 → 2', $d['posts'][0]['views'] === 2);
[$d] = call('plugin_time_machine_feed', ['actor' => $actB, 'post' => ['tab' => 'new', 'page' => 1]]);
check('B 反复刷新仍 2', $d['posts'][0]['views'] === 2);
[$d] = call('plugin_time_machine_feed', ['actor' => $actA, 'post' => ['tab' => 'new', 'page' => 1]]);
check('作者看自己的波文不计（仍 2）', $d['posts'][0]['views'] === 2);
check('浏览去重表正好 2 行（游客 + B，作者未入表）',
    (int)DB::val('SELECT COUNT(*) FROM plugin_time_machine_view') === 2);
check('未点赞/未收藏标记正确', $p0['liked'] === false && $p0['faved'] === false);

echo "\n== 分栏 ==\n";
[$d] = call('plugin_time_machine_feed', ['actor' => $actB, 'post' => ['tab' => 'fav', 'page' => 1]]);
check('B 的收藏栏有这条', count($d['posts']) === 1 && $d['posts'][0]['faved'] === true);
check('收藏栏浏览仍不涨（B 已计过）', $d['posts'][0]['views'] === 2);
[$d] = call('plugin_time_machine_feed', ['actor' => $actA, 'post' => ['tab' => 'mine', 'page' => 1]]);
check('我的栏 + stats', count($d['posts']) === 1 && $d['stats']['posts'] === 1);
check('mine 分栏不计浏览（仍 2）', $d['posts'][0]['views'] === 2);
[$d] = call('plugin_time_machine_feed', ['actor' => $actB, 'post' => ['tab' => 'new', 'page' => 1]]);
check('B 视角 liked/faved 标记正确', $d['posts'][0]['liked'] === false && $d['posts'][0]['faved'] === true);
[$d] = call('plugin_time_machine_like', ['actor' => $actB, 'post' => ['id' => $pid]]);
check('B 再点赞后 likes=1', $d['on'] === true && $d['likes'] === 1);

echo "\n== 删除权限 ==\n";
check('删除在敏感清单里', Plugin::isSensitive('plugin_time_machine_delete') === true);
[$d, $c] = call('plugin_time_machine_delete', ['actor' => $actB, 'post' => ['id' => $pid]]);
check('非作者非管理员删除被拒（403）', $c === 403);
[$d, $c] = call('plugin_time_machine_delete', ['actor' => $actAdmin, 'post' => ['id' => $pid]]);
check('管理员可删', $c === 200 && $d['ok'] === true);
foreach (['plugin_time_machine_post', 'plugin_time_machine_comment', 'plugin_time_machine_like', 'plugin_time_machine_fav', 'plugin_time_machine_view'] as $t) {
    check('  连带清空：' . $t, (int)DB::val("SELECT COUNT(*) FROM {$t}") === 0);
}
[$d, $c] = call('plugin_time_machine_delete', ['actor' => $actAdmin, 'post' => ['id' => $pid]]);
check('重复删除返回 404', $c === 404);

echo "\n== 热门分栏排序 ==\n";
call('plugin_time_machine_post', ['actor' => $actA, 'post' => ['content' => '甲']]);
call('plugin_time_machine_post', ['actor' => $actB, 'post' => ['content' => '乙']]);
$ids = array_map(fn($r) => (int)$r['id'], DB::all('SELECT id FROM plugin_time_machine_post ORDER BY id ASC'));
[$d] = call('plugin_time_machine_like', ['actor' => $actA, 'post' => ['id' => $ids[1]]]);
[$d] = call('plugin_time_machine_feed', ['actor' => $actGuest, 'post' => ['tab' => 'hot', 'page' => 1]]);
check('热门栏被赞的排前', array_map(fn($p) => $p['id'], $d['posts']) === [$ids[1], $ids[0]]);

/* 收尾 */
$ref = new ReflectionClass('DB');
$pdoProp = $ref->getProperty('pdo');
$pdoProp->setAccessible(true);
$pdoProp->setValue(null, null);
foreach ([$tmpDb, $tmpCache, $tmpUp] as $rm) {
    foreach (glob($rm . '/*') ?: [] as $f) @unlink($f);
    @rmdir($rm);
    @unlink($rm);
}

echo "\n" . ($fail === 0 ? "全部通过\n" : "有 {$fail} 项失败\n");
exit($fail === 0 ? 0 : 1);
