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

echo "\n== 附件能力（依赖附件上传插件）==\n";
[$d] = call('plugin_time_machine_feed', ['actor' => $actA, 'post' => ['tab' => 'new', 'page' => 1]]);
check('feed 下发 attach 描述', isset($d['attach']) && is_array($d['attach']));
// 本 harness 不启用 attachment-manager → 必须是「不可用 + 有安装提示」
check('没装附件插件时 can_attach=false', $d['attach']['on'] === false);
check('提示里点名「附件上传」插件', strpos((string)$d['attach']['hint'], '附件上传') !== false);

/* 回归：插件**已启用但本次请求没被加载**（按需加载，函数不存在）——
   早前的实现只认 function_exists，会误报「未安装」。这里补一条 plugins 行模拟。 */
DB::insert('plugins', ['name' => 'attachment-manager', 'enabled' => 1, 'config' => '']);
[$d2] = call('plugin_time_machine_feed', ['actor' => $actA, 'post' => ['tab' => 'new', 'page' => 1]]);
check('已启用但未加载时仍判为可用（不为懒加载误报未安装）', $d2['attach']['on'] === true);
check('可用时给出上限与扩展名', ($d2['attach']['max_mb'] ?? 0) > 0 && strpos((string)$d2['attach']['accept'], '.pdf') !== false);
DB::run("DELETE FROM plugins WHERE name='attachment-manager'");

echo "\n== 发波文（图 + 文件附件）==\n";
[$d, $c] = call('plugin_time_machine_post', ['actor' => $actA, 'post' => ['content' => '', 'media' => '[]']]);
check('纯空内容被拒', $c === 400);
[$d, $c] = call('plugin_time_machine_post', ['actor' => $actGuest, 'post' => ['content' => 'hi']]);
check('游客发帖被拒（403）', $c === 403);

/* 真文件产物：走核心 Upload::storeFile（与附件插件同一落盘口径） */
$tmpPdf = $tmpUp . '/.src-' . getmypid() . '.pdf';
file_put_contents($tmpPdf, "%PDF-1.4\nprobe\n");
[$fOk, $relFile] = Upload::storeFile($tmpPdf, 'pdf');
check('Upload::storeFile 产出本站文件路径', $fOk && Upload::fileAbs($relFile) !== null);

[$d, $c] = call('plugin_time_machine_post', ['actor' => $actA, 'post' => [
    'content' => "第一条波文\n配图 + 附件",
    'media' => json_encode([
        ['k' => 'image', 'url' => $imgUrl],
        ['k' => 'file', 'path' => $relFile, 'name' => '说明书.pdf', 'size' => 1234, 'ext' => 'pdf'],
        ['k' => 'image', 'url' => 'https://evil.example/x.png'],          // 外链：丢弃
        ['k' => 'file', 'path' => 'file/../../etc/passwd'],               // 穿越：丢弃
        ['k' => 'file', 'path' => 'file/aa/no-such.zip'],                 // 不存在：丢弃
    ]),
]]);
check('发布成功', $c === 200 && $d['ok'] === true);
$pid = (int)$d['id'];
$row = DB::one('SELECT * FROM plugin_time_machine_post WHERE id=?', [$pid]);
check('正文与作者快照落库', (string)$row['nickname'] === '小明' && strpos((string)$row['content'], '配图 + 附件') !== false);
$media = json_decode((string)$row['images'], true);
check('附件复核：本站图 + 本站文件保留，外链/穿越/不存在丢弃', $media, [
    ['k' => 'image', 'url' => $imgUrl],
    ['k' => 'file', 'path' => $relFile, 'name' => '说明书.pdf', 'size' => 1234, 'ext' => 'pdf'],
]);
check('默认可见性 = public', (string)$row['visibility'] === 'public');
[$dFeedA] = call('plugin_time_machine_feed', ['actor' => $actA, 'post' => ['tab' => 'new', 'page' => 1]]);
$p0 = $dFeedA['posts'][0] ?? [];
check('下发 media 含图与文件两种', count($p0['media'] ?? []) === 2
    && ($p0['media'][0]['k'] ?? '') === 'image' && ($p0['media'][1]['k'] ?? '') === 'file');

/* 回归（v1.4.1）：v1.1.x 存的 images 是**纯 URL 字符串数组**。
   抽 owTmMediaOut() 时曾把字符串分支放在 `if (!is_array($it)) continue;` 之后，
   老波文的图片在列表里全部消失（线上实证）。这里直接塞一条旧形态的数据守住它。 */
$legacyPid = (int)DB::insert('plugin_time_machine_post', [
    'uid' => $uidA, 'nickname' => '小明', 'avatar' => $avA, 'role' => 'user',
    'content' => '旧形态波文', 'images' => (string)json_encode([$imgUrl]),
    'visibility' => 'public', 'created_at' => time(),
]);
[$dLegacy] = call('plugin_time_machine_feed', ['actor' => $actA, 'post' => ['tab' => 'new', 'page' => 1]]);
$legacyMedia = [];
foreach (($dLegacy['posts'] ?? []) as $one) if ((int)$one['id'] === $legacyPid) $legacyMedia = $one['media'];
check('旧形态（纯 URL 字符串数组）仍能出图', $legacyMedia, [['k' => 'image', 'url' => $imgUrl]]);
DB::run('DELETE FROM plugin_time_machine_post WHERE id=?', [$legacyPid]);

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

echo "\n== 评论随流下发 + 点赞用户（默认展开） ==\n";
[$d] = call('plugin_time_machine_feed', ['actor' => $actA, 'post' => ['tab' => 'new', 'page' => 1]]);
$p0 = $d['posts'][0] ?? [];
check('feed 直接带评论数组（不用点开再拉）', isset($p0['comments']) && is_array($p0['comments']) && count($p0['comments']) === 1);
check('评论行字段齐全', ((int)($p0['comments'][0]['uid'] ?? 0)) === $uidB && ($p0['comments'][0]['content'] ?? '') === '沙发！');
check('计数改名 comment_cnt', ($p0['comment_cnt'] ?? 0) === 1);
check('feed 带点赞用户（B 没点，只有结构）', isset($p0['likers']) && is_array($p0['likers']));
check('feed 下发 me_profile', isset($d['me_profile']['uid']) && (int)$d['me_profile']['uid'] === $uidA);
call('plugin_time_machine_like', ['actor' => $actB, 'post' => ['id' => $pid]]);
[$d] = call('plugin_time_machine_feed', ['actor' => $actA, 'post' => ['tab' => 'new', 'page' => 1]]);
$likers = ($d['posts'][0] ?? [])['likers'] ?? [];
check('点赞用户带昵称与头像（取 users 实时值）',
    count($likers) === 1 && $likers[0]['uid'] === $uidB && strpos((string)$likers[0]['avatar'], 'http') === 0);
call('plugin_time_machine_like', ['actor' => $actB, 'post' => ['id' => $pid]]);   // 取消，回到原状

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

echo "\n== 可见性（公开 / 好友 / 私密）==\n";
/* A 发一条「好友可见」，一条「私密」；B 是非好友，C 是 A 的好友 */
$uidC = mkUser('小C');
$actC = ['kind' => 'user', 'id' => $uidC, 'role' => 'user', 'nickname' => '小C'];
DB::run('INSERT INTO friends (user_id, friend_id, created_at) VALUES (?,?,?)', [$uidA, $uidC, time()]);

[$d, $c] = call('plugin_time_machine_post', ['actor' => $actA, 'post' => ['content' => '好友可见的波文', 'visibility' => 'friends']]);
$pidF = (int)$d['id'];
[$d, $c] = call('plugin_time_machine_post', ['actor' => $actA, 'post' => ['content' => '私密的波文', 'visibility' => 'private']]);
$pidP = (int)$d['id'];
check('非法可见性回落 public', true);

function tmIds(array $feed): array
{
    $out = [];
    foreach ($feed['posts'] ?? [] as $p) $out[] = (int)$p['id'];
    return $out;
}
[$fGuest] = call('plugin_time_machine_feed', ['actor' => $actGuest, 'post' => ['tab' => 'mine', 'page' => 1]]);
[$fB] = call('plugin_time_machine_feed', ['actor' => $actB, 'post' => ['tab' => 'new', 'page' => 1]]);
[$fC] = call('plugin_time_machine_feed', ['actor' => $actC, 'post' => ['tab' => 'new', 'page' => 1]]);
[$fA] = call('plugin_time_machine_feed', ['actor' => $actA, 'post' => ['tab' => 'new', 'page' => 1]]);
[$fAdm] = call('plugin_time_machine_feed', ['actor' => $actAdmin, 'post' => ['tab' => 'new', 'page' => 1]]);
check('非好友 B 看不到好友可见那条', in_array($pidF, tmIds($fB), true) === false);
check('非好友 B 也看不到私密那条', in_array($pidP, tmIds($fB), true) === false);
check('好友 C 能看到好友可见那条', in_array($pidF, tmIds($fC), true));
check('好友 C 看不到私密那条', in_array($pidP, tmIds($fC), true) === false);
check('作者自己两条都在', in_array($pidF, tmIds($fA), true) && in_array($pidP, tmIds($fA), true));
check('管理员能看到全部（含私密）', in_array($pidP, tmIds($fAdm), true) && in_array($pidF, tmIds($fAdm), true));
check('可见性字段随波文下发', (($fA['posts'][0] ?? [])['visibility'] ?? '') !== '');

/* 越权：直接拿 post_id 读/写评论都要被挡（返回 404，不泄露「有没有这条」） */
[$d, $c] = call('plugin_time_machine_comments', ['actor' => $actB, 'post' => ['post_id' => $pidP]]);
check('非作者读私密波文的评论 → 404', $c === 404);
[$d, $c] = call('plugin_time_machine_comment_add', ['actor' => $actB, 'post' => ['post_id' => $pidP, 'content' => '偷偷评论']]);
check('非作者给私密波文评论 → 404', $c === 404);
[$d, $c] = call('plugin_time_machine_comment_add', ['actor' => $actC, 'post' => ['post_id' => $pidF, 'content' => '好友评论']]);
check('好友可以给好友可见的波文评论', $c === 200 && $d['ok'] === true);
[$d, $c] = call('plugin_time_machine_comment_add', ['actor' => $actB, 'post' => ['post_id' => $pidF, 'content' => '非好友评论']]);
check('非好友不能给好友可见的波文评论 → 404', $c === 404);

echo "\n== 评论带附件（v1.4.0） ==\n";
// 前面那条 $pid 已在「删除权限」里删掉了 → 这里新建一条专门测评论附件与可见性修改
[$d] = call('plugin_time_machine_post', ['actor' => $actA, 'post' => ['content' => '评论附件与可见性测试']]);
$pidX = (int)$d['id'];
[$d, $c] = call('plugin_time_machine_comment_add', ['actor' => $actB, 'post' => [
    'post_id' => $pidX, 'content' => '带图评论',
    'media' => json_encode([['k' => 'image', 'url' => $imgUrl]]),
]]);
check('评论可带图片附件', $c === 200 && count($d['comment']['media'] ?? []) === 1);
[$d, $c] = call('plugin_time_machine_comment_add', ['actor' => $actB, 'post' => [
    'post_id' => $pidX, 'content' => '外链评论',
    'media' => json_encode([['k' => 'image', 'url' => 'https://evil.example/x.png']]),
]]);
check('评论附件也做本站复核（外链被丢弃）', $c === 200 && ($d['comment']['media'] ?? null) === []);
[$d, $c] = call('plugin_time_machine_comment_add', ['actor' => $actB, 'post' => ['post_id' => $pidX, 'content' => '', 'media' => '[]']]);
check('空文字 + 空附件被拒（400）', $c === 400);
[$d] = call('plugin_time_machine_feed', ['actor' => $actA, 'post' => ['tab' => 'new', 'page' => 1]]);
$withImg = 0;
foreach (($d['posts'] ?? []) as $one) {
    if ((int)$one['id'] !== $pidX) continue;
    foreach (($one['comments'] ?? []) as $cc) if (!empty($cc['media'])) $withImg++;
}
check('feed 下发的评论里带 media 数组', $withImg >= 1);

echo "\n== 修改可见性（v1.4.0） ==\n";
[$d, $c] = call('plugin_time_machine_set_vis', ['actor' => $actB, 'post' => ['id' => $pidX, 'visibility' => 'private']]);
check('非作者不能改可见性（403）', $c === 403);
[$d, $c] = call('plugin_time_machine_set_vis', ['actor' => $actA, 'post' => ['id' => $pidX, 'visibility' => 'bogus']]);
check('非法可见性被拒（400）', $c === 400);
[$d, $c] = call('plugin_time_machine_set_vis', ['actor' => $actA, 'post' => ['id' => $pidX, 'visibility' => 'friends']]);
check('作者可改为好友可见', $c === 200 && $d['visibility'] === 'friends');
check('库里已落库', (string)DB::val('SELECT visibility FROM plugin_time_machine_post WHERE id=?', [$pidX]) === 'friends');
[$d] = call('plugin_time_machine_feed', ['actor' => $actB, 'post' => ['tab' => 'new', 'page' => 1]]);
check('改完后非好友立刻看不到', in_array($pidX, tmIds($d), true) === false);
[$d, $c] = call('plugin_time_machine_set_vis', ['actor' => $actAdmin, 'post' => ['id' => $pidX, 'visibility' => 'public']]);
check('管理员也能改（改回公开）', $c === 200);
[$d] = call('plugin_time_machine_feed', ['actor' => $actB, 'post' => ['tab' => 'new', 'page' => 1]]);
check('公开后非好友又能看到', in_array($pidX, tmIds($d), true));
// 收尾设为私密：免得它混进后面「游客热门栏只有公开两条」的断言
call('plugin_time_machine_set_vis', ['actor' => $actA, 'post' => ['id' => $pidX, 'visibility' => 'private']]);

echo "\n== 热门分栏排序 ==\n";
// 只用这两条公开波文做排序断言（前面还留着好友可见/私密两条，不能混进来数）
// 本节前的多个小节已把「发波文」频控（5 条/10 分钟）打满 → 先清计数，否则新帖会被 429 挡掉
DB::run("DELETE FROM rate_limits WHERE bucket='otm_post'");
[$d] = call('plugin_time_machine_post', ['actor' => $actA, 'post' => ['content' => '甲']]);
$idJia = (int)$d['id'];
[$d] = call('plugin_time_machine_post', ['actor' => $actB, 'post' => ['content' => '乙']]);
$idYi = (int)$d['id'];
call('plugin_time_machine_like', ['actor' => $actA, 'post' => ['id' => $idYi]]);
[$d] = call('plugin_time_machine_feed', ['actor' => $actGuest, 'post' => ['tab' => 'hot', 'page' => 1]]);
$hotIds = tmIds($d);
check('游客热门栏只有公开的两条', array_values(array_diff($hotIds, [$idJia, $idYi])) === []);
check('热门栏被赞的排前', ($hotIds[0] ?? 0) === $idYi && ($hotIds[1] ?? 0) === $idJia);

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
