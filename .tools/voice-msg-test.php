<?php
/**
 * 语音消息插件（voice-msg v1.0.0）自测
 *
 *   php .tools/voice-msg-test.php
 *
 * 用内存 SQLite + 核心类桩真实加载 main.php，把四条路由与维护任务跑一遍：
 *   ① 格式白名单与波形归一化（纯函数）
 *   ② 上传路由：游客拒 / 体积 / MIME / 成功落 pending / 限流
 *   ③ 发送路由：群聊 / 私聊 / 别人 pending / 过期 pending / 发送失败回滚语义
 *   ④ 查询路由：房间可见性 / 私聊本人对方 / 消息已不存在
 *   ⑤ 维护任务：pending 超时清理（含共用文件保护）/ 孤儿行 / 无人引用文件真删
 *   ⑥ 后台保存与公开配置
 *
 * ⚠️ 每个 WAV 都要带唯一内容：Upload::storeFile 按内容哈希命名，
 *    相同字节会被去重成同一个路径（线上同一段录音发给两个人只存一份），
 *    测试里内容相同会让多条记录共享 path，断言全乱。
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('OWLSGO_VERSION', '0.0.0-test');

/* ============================ 核心类桩 ============================ */

class ApiExit extends RuntimeException
{
    public int $status;
    public function __construct(int $status) { $this->status = $status; parent::__construct('api exit'); }
}

class Api
{
    public static array $last = [];
    public static function json($data, int $status = 200)
    {
        self::$last = (array)$data;
        throw new ApiExit($status);
    }
}

class Sec
{
    public static bool $rateOk = true;
    public static array $logs = [];
    public static function rateLimit(string $bucket, string $ident, int $win, int $max): bool { return self::$rateOk; }
    public static function log(string $type, string $who, array $extra = []): void { self::$logs[] = [$type, $who, $extra]; }
    public static function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES); }
    public static function ip(): string { return '127.0.0.1'; }
}

class Chat
{
    public static array $sendResult = [true, 'ok', 100, ['id' => 100, 'type' => 'file']];
    public static bool $accessOk = true;
    public static array $lastSend = [];
    public static function room(int $id): ?array
    {
        return $id > 0 ? ['id' => $id, 'type' => 'public', 'name' => '测试群'] : null;
    }
    public static function roomAccessOk(array $room, array $actor): bool { return self::$accessOk; }
    public static function areFriends(int $a, int $b): bool
    {
        $n = DB::val('SELECT COUNT(*) FROM friends WHERE (user_id=? AND friend_id=?) OR (user_id=? AND friend_id=?)', [$a, $b, $b, $a]);
        return (int)$n > 0;
    }
    public static function send(array $actor, int $roomId, string $type, string $content, array $opt = []): array
    {
        self::$lastSend = ['actor' => $actor, 'room_id' => $roomId, 'type' => $type, 'content' => $content, 'opt' => $opt];
        return self::$sendResult;
    }
}

class Upload
{
    /** @var array<string,string> rel => abs（落盘副本） */
    public static array $files = [];
    public static function storeFile(string $srcTmp, string $ext): array
    {
        $sha = hash('sha256', (string)@file_get_contents($srcTmp));
        $rel = 'file/' . substr($sha, 0, 2) . '/' . substr($sha, 0, 32) . '.' . $ext;
        $abs = sys_get_temp_dir() . '/owvm_' . basename($rel);
        if (!@copy($srcTmp, $abs)) return [false, ''];
        self::$files[$rel] = $abs;
        return [true, $rel];
    }
    public static function fileAbs(string $rel): ?string
    {
        if (!preg_match('#^file/[A-Za-z0-9_\-./]+$#', $rel)) return null;
        return self::$files[$rel] ?? null;
    }
}

class Plugin
{
    public static array $hooks = [], $routes = [], $assets = ['css' => [], 'js' => []], $crons = [], $pages = [];
    public static function on(string $hook, callable $fn): void { self::$hooks[$hook][] = $fn; }
    public static function route(string $action, callable $fn, array $opts = []): void { self::$routes[$action] = $fn; }
    public static function sensitive(string $action): void {}
    public static function asset(string $type, string $path): void { self::$assets[$type][] = $path; }
    public static function cron(string $name, int $interval, $handler, string $description = ''): void
    {
        self::$crons[] = ['name' => $name, 'interval' => $interval, 'handler' => $handler, 'description' => $description];
    }
    public static function adminPage(string $slug, string $title, callable $fn): void { self::$pages[$slug] = $title; }
}

function ow_abs_url(string $p, bool $only = true): string { return $p; }

class DB
{
    public static ?PDO $pdo = null;

    public static function boot(): void
    {
        self::$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        self::run('CREATE TABLE settings (k VARCHAR(64) PRIMARY KEY, v TEXT)');
        self::run('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, nickname TEXT, status INT DEFAULT 1, points INT DEFAULT 0)');
        self::run('CREATE TABLE rooms (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        self::run('CREATE TABLE messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT, room_id INT DEFAULT 0,
            user_id INT DEFAULT 0, guest_id INT DEFAULT 0, to_user_id INT DEFAULT 0, to_guest_id INT DEFAULT 0,
            type TEXT, content TEXT, created_at INT DEFAULT 0)');
        self::run('CREATE TABLE rate_limits (bucket TEXT, ident TEXT, window_start INT, count INT)');
        self::run('CREATE TABLE friends (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INT, friend_id INT, created_at INT)');
        self::run('CREATE TABLE plugins (name TEXT, enabled INT DEFAULT 0, config TEXT)');
    }

    public static function driver(): string { return 'sqlite'; }
    public static function autoId(): string { return 'INTEGER PRIMARY KEY AUTOINCREMENT'; }
    public static function textCol(string $col, string $default = "''"): string { return "$col TEXT NOT NULL DEFAULT $default"; }
    public static function createIndex(string $name, string $table, string $cols, bool $unique = false): void
    {
        self::run("CREATE INDEX IF NOT EXISTS $name ON $table ($cols)");
    }
    public static function ensureColumn(string $table, string $col, string $type, string $default): void {}

    public static function setting(string $k, $default = null)
    {
        $r = self::one('SELECT v FROM settings WHERE k=?', [$k]);
        return $r ? (string)$r['v'] : $default;
    }
    public static function setSetting(string $k, $v): void
    {
        self::run('INSERT OR REPLACE INTO settings (k, v) VALUES (?, ?)', [$k, (string)$v]);
    }
    private static function q(string $sql, array $args): PDOStatement
    {
        $st = self::$pdo->prepare($sql);
        $st->execute($args);
        return $st;
    }
    public static function val(string $sql, array $args = [])
    {
        $r = self::q($sql, $args)->fetch(PDO::FETCH_ASSOC);
        return $r ? array_values($r)[0] : false;
    }
    public static function one(string $sql, array $args = []): ?array
    {
        $r = self::q($sql, $args)->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    }
    public static function all(string $sql, array $args = []): array
    {
        return self::q($sql, $args)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    public static function run(string $sql, array $args = [])
    {
        return self::q($sql, $args);
    }
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $ph = implode(',', array_fill(0, count($cols), '?'));
        self::q("INSERT INTO $table (" . implode(',', $cols) . ") VALUES ($ph)", array_values($data));
        return (int)self::$pdo->lastInsertId();
    }
    public static function upsert(string $table, array $data, array $keys): void
    {
        $cols = array_keys($data);
        $ph = implode(',', array_fill(0, count($cols), '?'));
        self::q("INSERT OR REPLACE INTO $table (" . implode(',', $cols) . ") VALUES ($ph)", array_values($data));
    }
}

/* ============================ 加载被测插件 ============================ */

DB::boot();
require dirname(__DIR__) . '/plugins/voice-msg/main.php';

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $extra = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  [OK]   $label\n"; }
    else { $fail++; echo "  [FAIL] $label" . ($extra !== '' ? "  → $extra" : '') . "\n"; }
}

/** 调一条路由，返回 [status, data] */
function call(string $action, array $post = [], array $files = [], array $actor = ['kind' => 'user', 'id' => 5, 'nickname' => '测试甲', 'role' => 'member']): array
{
    try {
        Plugin::$routes[$action](['actor' => $actor, 'post' => $post, 'files' => $files]);
        return [200, Api::$last];
    } catch (ApiExit $e) {
        return [$e->status, Api::$last];
    }
}

$wavSeq = 0;
/** 内容唯一的 WAV（$seed 进 PCM，finfo 仍认得出） */
function wavTmp(int $seconds = 1, int $rate = 8000): string
{
    global $wavSeq;
    $wavSeq++;
    $n = $seconds * $rate;
    $hdr = 'RIFF' . pack('V', 36 + $n * 2) . 'WAVEfmt ' . pack('VVvvHH', 16, 1, 1, $rate, $rate * 2, 2, 16) . 'data' . pack('V', $n * 2);
    $pcm = '';
    for ($i = 0; $i < $n; $i++) $pcm .= chr(($i * 7 + $wavSeq * 13) % 256);   // 唯一内容 → 唯一哈希
    $f = tempnam(sys_get_temp_dir(), 'owvmw');
    file_put_contents($f, $hdr . $pcm);
    return $f;
}

/* ============================ ① 纯函数 ============================ */
echo "\n1) 格式白名单 / 波形归一化 / 引用计数\n";
ok('webm 双 MIME 都认', owVMExtOfMime('audio/webm') === 'webm' && owVMExtOfMime('video/webm') === 'webm');
ok('ogg 双 MIME 都认', owVMExtOfMime('audio/ogg') === 'ogg' && owVMExtOfMime('application/ogg') === 'ogg');
ok('mp4 双 MIME 都认', owVMExtOfMime('audio/mp4') === 'mp4' && owVMExtOfMime('video/mp4') === 'mp4');
ok('wav 多写法都认', owVMExtOfMime('audio/wav') === 'wav' && owVMExtOfMime('audio/x-wav') === 'wav' && owVMExtOfMime('audio/vnd.wave') === 'wav');
ok('陌生 MIME 拒绝', owVMExtOfMime('text/plain') === '' && owVMExtOfMime('application/octet-stream') === '');
ok('配置钳制：默认 60s / 10MB', owVMMaxDur() === 60 && owVMMaxBytes() === 10 * 1048576);

ok('波形归一化：正常数组', owVMNormPeaks('[3,50,100,0]') === '[3,50,100,0]');
ok('波形归一化：越界值夹紧', owVMNormPeaks('[-5,300]') === '[0,100]');
ok('波形归一化：超 60 条截断', count(json_decode(owVMNormPeaks('[' . str_repeat('7,', 80) . '7]'), true)) === 60);
ok('波形归一化：垃圾输入返回空', owVMNormPeaks('not json') === '' && owVMNormPeaks('{"a":1}') === '' && owVMNormPeaks('') === '' && owVMNormPeaks('[1,"x",2]') === '[1,2]');

$stem = str_repeat('a', 32);
DB::run("INSERT INTO messages (id, room_id, user_id, type, content, created_at) VALUES (1, 1, 5, 'file', ?, ?)",
    ['{"name":"\u8bed\u97f3\u6d88\u606f","size":1,"ext":"webm","path":"file\/ab\/' . $stem . '.webm"}', time()]);
ok('引用计数：JSON 转义斜杠也能命中', owVMRefCount('file/ab/' . $stem . '.webm') === 1);
ok('引用计数：不存在的文件为 0', owVMRefCount('file/ab/' . str_repeat('b', 32) . '.webm') === 0);
ok('引用计数：形状不对保守当作有人用', owVMRefCount('file/ab/x.webm') === 1);

/* ============================ ② 上传路由 ============================ */
echo "\n2) 上传路由 plugin_voice_msg_upload\n";
[$st, $r] = call('plugin_voice_msg_upload', [], ['file' => ['name' => 'v.wav', 'tmp_name' => wavTmp(), 'error' => 0, 'size' => 100]], ['kind' => 'guest', 'id' => 9, 'nickname' => '游客']);
ok('游客拒之（403）', $st === 403 && $r['msg'] === '请先登录后再录音', json_encode($r));

[$st, $r] = call('plugin_voice_msg_upload', [], [], ['kind' => 'user', 'id' => 5, 'nickname' => '测试甲', 'role' => 'member']);
ok('无文件 400', $st === 400 && $r['ok'] === false);

$bad = tempnam(sys_get_temp_dir(), 'owvmb');
file_put_contents($bad, 'i am not audio at all, just text');
[$st, $r] = call('plugin_voice_msg_upload', [], ['file' => ['name' => 'v.webm', 'tmp_name' => $bad, 'error' => 0, 'size' => 32]]);
ok('伪装音频被 MIME 白名单拦下', $st === 400 && strpos((string)$r['msg'], '不支持的音频格式') === 0, $r['msg']);

Sec::$rateOk = false;
[$st, $r] = call('plugin_voice_msg_upload', [], ['file' => ['name' => 'v.wav', 'tmp_name' => wavTmp(), 'error' => 0, 'size' => 100]]);
ok('限流 429', $st === 429);
Sec::$rateOk = true;

$peaks = json_encode(array_fill(0, 40, 55));
[$st, $r] = call('plugin_voice_msg_upload', ['dur' => '12', 'peaks' => $peaks], ['file' => ['name' => 'v.wav', 'tmp_name' => wavTmp(2), 'error' => 0, 'size' => 32000]]);
ok('上传成功返回 path', $st === 200 && $r['ok'] === true && preg_match('#^file/[a-f0-9]{2}/[a-f0-9]{32}\.wav$#', (string)$r['path']) === 1, json_encode($r));
$v1 = DB::one('SELECT * FROM plugin_voice_msg WHERE path=?', [(string)$r['path']]);
ok('pending 行落库（msg_id=0 + 时长 + 波形）', $v1 && (int)$v1['msg_id'] === 0 && (int)$v1['dur'] === 12 && (int)$v1['owner_id'] === 5 && $v1['peaks'] === $peaks);
ok('上传记了安全日志', count(Sec::$logs) === 1 && Sec::$logs[0][0] === 'voice_upload');

// 体积上限：配置压到 1MB，传 2MB 的假 WAV
DB::upsert('plugin_voice_msg_cfg', ['k' => 'max_mb', 'v' => '1'], ['k']);
$big = tempnam(sys_get_temp_dir(), 'owvmw');
file_put_contents($big, 'RIFF' . str_repeat("\0", 2 * 1048576));
[$st, $r] = call('plugin_voice_msg_upload', [], ['file' => ['name' => 'v.wav', 'tmp_name' => $big, 'error' => 0, 'size' => 2 * 1048576]]);
ok('超过体积上限被拒', $st === 400 && strpos((string)$r['msg'], 'MB 限制') !== false, $r['msg']);
DB::upsert('plugin_voice_msg_cfg', ['k' => 'max_mb', 'v' => '10'], ['k']);

/* ============================ ③ 发送路由 ============================ */
echo "\n3) 发送路由 plugin_voice_msg_send\n";
$v1 = DB::one('SELECT * FROM plugin_voice_msg ORDER BY id DESC LIMIT 1');
$path1 = (string)$v1['path'];

[$st, $r] = call('plugin_voice_msg_send', ['room_id' => '1', 'path' => $path1]);
ok('群聊发送成功', $st === 200 && $r['ok'] === true && !empty($r['msg_row']), json_encode($r));
ok('走核心 Chat::send（file + JSON content）', Chat::$lastSend['type'] === 'file'
    && json_decode(Chat::$lastSend['content'], true)['path'] === $path1
    && json_decode(Chat::$lastSend['content'], true)['name'] === '语音消息'
    && (int)Chat::$lastSend['room_id'] === 1
    && (int)Chat::$lastSend['actor']['id'] === 5);
$bound = DB::one('SELECT * FROM plugin_voice_msg WHERE path=?', [$path1]);
ok('msg_id 已绑定', (int)$bound['msg_id'] === 100);
[$st, $r] = call('plugin_voice_msg_send', ['room_id' => '1', 'path' => $path1]);
ok('重发被拒（已绑定 → 录音已过期）', $st === 400 && $r['ok'] === false && $r['msg'] === '录音已过期，请重新录制', json_encode($r));

// 私聊
[$st, $r] = call('plugin_voice_msg_upload', ['dur' => '3', 'peaks' => '[10,20]'], ['file' => ['name' => 'v.wav', 'tmp_name' => wavTmp(1), 'error' => 0, 'size' => 16000]]);
$path2 = (string)$r['path'];
DB::run('INSERT INTO users (id, nickname) VALUES (6, ?)', ['测试乙']);
[$st, $r] = call('plugin_voice_msg_send', ['room_id' => '0', 'to_user_id' => '6', 'path' => $path2]);
ok('私聊发送成功且带上接收方', $st === 200 && $r['ok'] === true
    && (int)Chat::$lastSend['opt']['to_user_id'] === 6 && Chat::$lastSend['opt']['to_nickname'] === '测试乙');

// 别人的 pending
[$st, $r] = call('plugin_voice_msg_upload', ['dur' => '3'], ['file' => ['name' => 'v.wav', 'tmp_name' => wavTmp(1), 'error' => 0, 'size' => 16000]], ['kind' => 'user', 'id' => 5, 'nickname' => '测试甲', 'role' => 'member']);
$path3 = (string)$r['path'];
[$st, $r] = call('plugin_voice_msg_send', ['room_id' => '1', 'path' => $path3], [], ['kind' => 'user', 'id' => 7, 'nickname' => '测试丙', 'role' => 'member']);
ok('发不出别人的 pending', $r['ok'] === false && $r['msg'] === '录音已过期，请重新录制');

// 过期 pending
[$st, $r] = call('plugin_voice_msg_upload', ['dur' => '3'], ['file' => ['name' => 'v.wav', 'tmp_name' => wavTmp(1), 'error' => 0, 'size' => 16000]]);
$path4 = (string)$r['path'];
DB::run('UPDATE plugin_voice_msg SET created_at=? WHERE path=?', [time() - 7200, $path4]);
[$st, $r] = call('plugin_voice_msg_send', ['room_id' => '1', 'path' => $path4]);
ok('超过 1 小时的 pending 拒发', $st === 400 && $r['ok'] === false && $r['msg'] === '录音已过期，请重新录制');

// 发送失败（核心拒了）：整单失败、不绑定 msg_id
[$st, $r] = call('plugin_voice_msg_upload', ['dur' => '3'], ['file' => ['name' => 'v.wav', 'tmp_name' => wavTmp(1), 'error' => 0, 'size' => 16000]]);
$path5 = (string)$r['path'];
Chat::$sendResult = [false, '你已被禁言'];
[$st, $r] = call('plugin_voice_msg_send', ['room_id' => '1', 'path' => $path5]);
ok('核心拒发时整单失败且不绑定', $st === 400 && $r['ok'] === false
    && strpos((string)$r['msg'], '你已被禁言') !== false
    && (int)DB::val('SELECT msg_id FROM plugin_voice_msg WHERE path=?', [$path5]) === 0,
    json_encode($r) . ' msg_id=' . var_export(DB::val('SELECT msg_id FROM plugin_voice_msg WHERE path=?', [$path5]), true));
Chat::$sendResult = [true, 'ok', 100, ['id' => 100, 'type' => 'file']];

/* ============================ ④ 查询路由 ============================ */
echo "\n4) 查询路由 plugin_voice_msg_cards\n";
DB::run("INSERT INTO messages (id, room_id, user_id, type, content, created_at) VALUES (100, 1, 5, 'file', ?, ?)",
    [json_encode(['name' => '语音消息', 'size' => 1, 'ext' => 'wav', 'path' => $path1]), time()]);
DB::run('UPDATE plugin_voice_msg SET msg_id=100 WHERE path=?', [$path1]);
DB::run("INSERT INTO messages (id, room_id, user_id, to_user_id, type, content, created_at) VALUES (101, 0, 5, 6, 'file', ?, ?)",
    [json_encode(['name' => '语音消息', 'size' => 1, 'ext' => 'wav', 'path' => $path2]), time()]);
DB::run('UPDATE plugin_voice_msg SET msg_id=101 WHERE path=?', [$path2]);
DB::run('INSERT INTO plugin_voice_msg (msg_id, owner_id, path, ext, size, dur, peaks, created_at) VALUES (999, 5, ?, ?, 1, 2, ?, ?)',
    ['file/cc/' . str_repeat('c', 32) . '.wav', 'wav', '[1,2]', time()]);   // 消息不存在

Chat::$accessOk = true;
[$st, $r] = call('plugin_voice_msg_cards', ['ids' => '100,101,999'], [], ['kind' => 'user', 'id' => 5, 'nickname' => '测试甲', 'role' => 'member']);
ok('群聊语音：发送者本人可见 mine=1', isset($r['items']['100']) && $r['items']['100']['mine'] === true && $r['items']['100']['dur'] === 12, json_encode($r['items']['100'] ?? null));
ok('私聊语音：发送者本人 mine=1', isset($r['items']['101']) && $r['items']['101']['mine'] === true);
ok('消息已不存在：不出现在结果里', !isset($r['items']['999']));

[$st, $r] = call('plugin_voice_msg_cards', ['ids' => '100,101'], [], ['kind' => 'user', 'id' => 6, 'nickname' => '测试乙', 'role' => 'member']);
ok('私聊语音：接收方可见 mine=0', isset($r['items']['101']) && $r['items']['101']['mine'] === false, json_encode($r['items']['101'] ?? null));

Chat::$accessOk = false;
[$st, $r] = call('plugin_voice_msg_cards', ['ids' => '100,101'], [], ['kind' => 'user', 'id' => 7, 'nickname' => '测试丙', 'role' => 'member']);
ok('无权进群：群语音不可见', !isset($r['items']['100']));
ok('私聊对第三者不可见', !isset($r['items']['101']));
Chat::$accessOk = true;

[$st, $r] = call('plugin_voice_msg_cards', ['ids' => '100,101'], [], ['kind' => 'guest', 'id' => 0, 'nickname' => '游客']);
ok('游客也能看到群里的语音（mine=0）', isset($r['items']['100']) && $r['items']['100']['mine'] === false);

[$st, $r] = call('plugin_voice_msg_cards', ['ids' => '']);
ok('空 ids 直接返回空', $r['items'] === []);

/* ============================ ④b 首屏预取 ============================ */
echo "\n4b) 预取路由 plugin_voice_msg_prefetch\n";
// 私聊预取要求双方是好友（与核心私聊口径一致），先建关系
DB::run('INSERT INTO friends (user_id, friend_id, created_at) VALUES (5, 6, ?)', [time()]);
// 群 1 里再放两条语音（消息 102 / 103，发送者分别是 5 与 6）
DB::run("INSERT INTO messages (id, room_id, user_id, type, content, created_at) VALUES (102, 1, 6, 'file', ?, ?)",
    [json_encode(['name' => '语音消息', 'size' => 1, 'ext' => 'wav', 'path' => $path3]), time()]);
DB::run('UPDATE plugin_voice_msg SET msg_id=102 WHERE path=?', [$path3]);
DB::run("INSERT INTO messages (id, room_id, user_id, type, content, created_at) VALUES (103, 1, 5, 'file', ?, ?)",
    [json_encode(['name' => '语音消息', 'size' => 1, 'ext' => 'wav', 'path' => $path5]), time()]);
DB::run('UPDATE plugin_voice_msg SET msg_id=103 WHERE path=?', [$path5]);

Chat::$accessOk = true;
[$st, $r] = call('plugin_voice_msg_prefetch', ['room_id' => '1', 'limit' => 30]);
ok('群预取：拿到该群全部语音', isset($r['items']['100'], $r['items']['102'], $r['items']['103']), json_encode(array_keys($r['items'])));
ok('群预取：不掺进别的群 / 私聊的消息', !isset($r['items']['101']), json_encode(array_keys($r['items'])));
ok('群预取：mine 判定正确（发送者是本人）', $r['items']['100']['mine'] === true && $r['items']['102']['mine'] === false);

Chat::$accessOk = false;
[$st, $r] = call('plugin_voice_msg_prefetch', ['room_id' => '1'], [], ['kind' => 'user', 'id' => 7, 'nickname' => '测试丙', 'role' => 'member']);
ok('进不去的群：预取给空（与 cards 同口径）', $r['items'] === []);
Chat::$accessOk = true;

// 私聊：msg 101 是 5→6 的语音。to_user_id 传的是**对方**
[$st, $r] = call('plugin_voice_msg_prefetch', ['to_user_id' => '6'], [], ['kind' => 'user', 'id' => 5, 'nickname' => '测试甲', 'role' => 'member']);
ok('私聊预取：发送方视角拿得到', isset($r['items']['101']), json_encode(array_keys($r['items'])));
ok('私聊预取：mine 随身份翻转（我是发送者）', $r['items']['101']['mine'] === true, json_encode($r['items']['101']['mine']));
[$st, $r] = call('plugin_voice_msg_prefetch', ['to_user_id' => '5'], [], ['kind' => 'user', 'id' => 6, 'nickname' => '测试乙', 'role' => 'member']);
ok('私聊预取：接收方视角同样拿得到', isset($r['items']['101']), json_encode(array_keys($r['items'])));
ok('私聊预取：接收方 mine=false', $r['items']['101']['mine'] === false, json_encode($r['items']['101']['mine']));
[$st, $r] = call('plugin_voice_msg_prefetch', ['to_user_id' => '6'], [], ['kind' => 'user', 'id' => 7, 'nickname' => '测试丙', 'role' => 'member']);
ok('第三方预取不到别人的私聊语音', !isset($r['items']['101']), json_encode($r['items']));
// 自己给自己发私聊不是真实场景，但参数自指时不能把会话搞错（应退化为空）
[$st, $r] = call('plugin_voice_msg_prefetch', ['to_user_id' => '6'], [], ['kind' => 'user', 'id' => 6, 'nickname' => '测试乙', 'role' => 'member']);
ok('to_user_id 传自己：拿不到对方发来的语音', !isset($r['items']['101']), json_encode(array_keys($r['items'])));

[$st, $r] = call('plugin_voice_msg_prefetch', ['room_id' => '0'], [], ['kind' => 'user', 'id' => 5, 'nickname' => '测试甲', 'role' => 'member']);
ok('没给 room 也没给 peer：返回空而不是报错', $r['ok'] === true && $r['items'] === []);

[$st, $r] = call('plugin_voice_msg_prefetch', ['room_id' => '1', 'limit' => 1]);
ok('limit 生效（只回最近 1 条）', count($r['items']) === 1, json_encode(array_keys($r['items'])));
[$st, $r] = call('plugin_voice_msg_prefetch', ['to_user_id' => '6'], [], ['kind' => 'guest', 'id' => 0, 'nickname' => '游客']);
ok('游客预取私聊：拒绝', $r['ok'] === false || $r['items'] === [], json_encode($r));

/* ============================ ⑤ 维护任务 ============================ */
echo "\n5) 维护任务 gc\n";
$cron = null;
foreach (Plugin::$crons as $c) { if ($c['name'] === 'gc') $cron = $c; }
ok('cron 已注册（每小时）', $cron !== null && (int)$cron['interval'] === 3600);

// 第一轮：pending 超时，但文件被另一条活消息引用 → 删行留文件
[$st, $r] = call('plugin_voice_msg_upload', ['dur' => '3'], ['file' => ['name' => 'v.wav', 'tmp_name' => wavTmp(1), 'error' => 0, 'size' => 16000]]);
$path6 = (string)$r['path'];
DB::run('UPDATE plugin_voice_msg SET created_at=? WHERE path=?', [time() - 7200, $path6]);
$abs6 = (string)Upload::fileAbs($path6);
ok('清理前文件在', $abs6 !== '' && is_file($abs6));
DB::run("INSERT INTO messages (id, room_id, user_id, type, content, created_at) VALUES (200, 1, 5, 'file', ?, ?)",
    [json_encode(['name' => 'x', 'size' => 1, 'ext' => 'wav', 'path' => $path6]), time()]);
$msg = $cron['handler']();
ok('pending 超时行被清', DB::one('SELECT id FROM plugin_voice_msg WHERE path=?', [$path6]) === null);
ok('孤儿行被清', DB::one('SELECT id FROM plugin_voice_msg WHERE msg_id=999') === null);
ok('共用文件被保留（别的消息还在用）', is_file($abs6));
ok('日志消息含条数', strpos((string)$msg, '清理语音记录') === 0, (string)$msg);

// 第二轮：撤掉引用消息，再放一个无人引用的过期 pending → 连文件一起删
DB::run('DELETE FROM messages WHERE id=200');
[$st, $r] = call('plugin_voice_msg_upload', ['dur' => '3'], ['file' => ['name' => 'v.wav', 'tmp_name' => wavTmp(1), 'error' => 0, 'size' => 16000]]);
$path7 = (string)$r['path'];
$abs7 = (string)Upload::fileAbs($path7);
DB::run('UPDATE plugin_voice_msg SET created_at=? WHERE path=?', [time() - 7200, $path7]);
$msg = $cron['handler']();
ok('无人引用的过期文件连文件一起删', DB::one('SELECT id FROM plugin_voice_msg WHERE path=?', [$path7]) === null && !is_file($abs7));

/* ============================ ⑥ 后台与入口 ============================ */
echo "\n6) 后台保存 / 公开配置 / 钩子\n";
[$st, $r] = call('plugin_voice_msg_admin_save', ['max_dur' => '30', 'max_mb' => '5'], [], ['kind' => 'user', 'id' => 5, 'nickname' => '测试甲', 'role' => 'member']);
ok('非管理员 403', $st === 403);
[$st, $r] = call('plugin_voice_msg_admin_save', ['max_dur' => '9999', 'max_mb' => '0'], [], ['kind' => 'user', 'id' => 1, 'nickname' => '管理员', 'role' => 'admin']);
ok('管理员保存且越界值被钳制', $r['ok'] === true && (int)$r['max_dur'] === 600 && (int)$r['max_mb'] === 1, json_encode($r));
DB::upsert('plugin_voice_msg_cfg', ['k' => 'max_dur', 'v' => '45'], ['k']);
DB::upsert('plugin_voice_msg_cfg', ['k' => 'max_mb', 'v' => '8'], ['k']);
[$st, $r] = call('plugin_voice_msg_conf', []);
ok('公开配置反映后台值', $r['max_dur'] === 45 && $r['max_mb'] === 8);

$tb = null;
foreach (Plugin::$hooks['chat.toolbar'] ?? [] as $fn) { $tb = $fn(); }
ok('chat.toolbar 产出麦克风按钮', is_string($tb) && strpos($tb, 'id="owBtnVoice"') !== false && strpos($tb, 'title="语音消息"') !== false);
ok('page.head 产出样式 link', count(Plugin::$hooks['page.head'] ?? []) === 1);
ok('资源登记：css + 两个 js', Plugin::$assets['css'] === ['voice-msg/style.css']
    && Plugin::$assets['js'] === ['voice-msg/chat.js', 'voice-msg/admin.js']);
ok('后台页已注册', (Plugin::$pages['voice-msg'] ?? '') === '语音消息');

/* ============================ 汇总 ============================ */
echo "\n" . ($fail === 0 ? "ALL PASS（$pass 项）\n" : "$fail FAILED / $pass passed\n");
exit($fail === 0 ? 0 : 1);
