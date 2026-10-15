<?php
/**
 * Redis 缓存插件（redis v1.0.0）
 *
 * ── 这一版实际做了什么（先读这里再开）────────────────────────────
 *   ① 连接体检：后台一键测通，含「写—读—删」探针、连接延迟、版本、内存占用、
 *      当前库键数、命中率；连不上时明确给出原因（拒绝连接 / 认证失败 / 超时）。
 *   ② 消息旁路镜像：发消息时把一条摘要写进 Redis（每群一个 List，保最近 N 条、
 *      按天自动过期），撤回时同步从列表里摘掉。
 *   ③ 实时计数：消息数、登录数、活跃用户（按自然日累加，活跃用户用 Set 去重）。
 *   ④ 落库留痕：计划任务每 5 分钟把当日计数写进 plugin_redis_daily ——
 *      于是后台的 14 天趋势图不会因为 Redis 键到期而丢历史。
 *   ⑤ 核心钩子待办：后台页列出「要让 Redis 真正接管消息读路径」还需要核心补的
 *      5 个钩子（文件:行 + 建议签名），照抄即可，插件侧无需再改。
 *
 * ── 为什么本插件不加速聊天（关键结论，勿误解）────────────────────
 *   Redis 要生效必须插在「读消息」的路上，而核心只在「写消息」的路上留了钩子：
 *   前端取数一律走核心 action history / poll（assets/js/chat.js），
 *   `Plugin::dispatch()` 只对**未命中**的 action 兜底（core/plugin.php），
 *   所以插件根本拿不到这些请求；Chat::history / Chat::poll / onlineList /
 *   Sec::rateLimit 等读路径函数体内没有任何 Plugin::fire() 调用点。
 *   ⇒ 本插件是「旁路镜像 + 统计」，聊天照旧走数据库。
 *     详见后台页「核心钩子待办」卡片。
 *
 * ── 零依赖 ────────────────────────────────────────────────────
 *   优先用 phpredis 扩展；**没装扩展时自动切到内置的极简 RESP 客户端**
 *   （fsockopen + 手写协议解析，只实现本插件用到的命令），
 *   于是「主机没编译 redis 扩展」也能用，不需要 Composer、不需要编译。
 *
 * ── 安全与降级（硬性纪律）─────────────────────────────────────
 *   - Redis 挂了 / 超时 / 认证失败：**一律静默跳过**，绝不影响发消息与登录；
 *     同一请求内连接失败后不再重试（熔断），避免把故障放大成热路径卡顿。
 *   - 密码只写不读：接口只回 pass_set 布尔值，后台输入框永远留空，
 *     **留空保存 = 不修改**（否则每次保存别的设置都会把密码悄悄清掉）。
 *   - 所有键都带可配置前缀；「清空」只删本前缀下的键（SCAN + DEL），不碰别人的数据。
 *   - 私聊内容默认不进 Redis（room_id=0 跳过），后台可显式打开。
 *
 * 表：
 *   plugin_redis_cfg    后台可调参数（k/v）
 *   plugin_redis_daily  每日计数落库（day 唯一）
 *
 * 键（<P> = 可配置前缀，默认 owlsgo:）：
 *   <P>msg:r<房间ID>    List，元素是一条消息的 JSON 摘要，新消息在左（LPUSH）
 *   <P>msg:dm           同上，私聊（默认不写）
 *   <P>rooms            Set，有镜像数据的房间 ID（后台群榜用的索引）
 *   <P>stat:msg:<日期>  当日消息条数
 *   <P>stat:mir:<日期>  当日镜像写入条数
 *   <P>stat:login:<日期> 当日登录次数
 *   <P>stat:act:<日期>  Set，当日活跃注册用户 ID
 */
if (!defined('OWLSGO_VERSION')) exit;   // 禁止直接 HTTP 访问本文件

/* ============================ 建表 ============================ */

/** 懒建表：只在真正读写数据时执行（main.php 顶层不做副作用） */
function owRdsBoot(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $ts = DB::driver() === 'mysql' ? 'BIGINT NOT NULL' : 'INTEGER NOT NULL';

    // ⚠️ k 必须是 VARCHAR 不能是 TEXT：MySQL 下 TEXT/BLOB 列做键要指定长度，
    //    否则建表报 1170（"used in key specification without a key length"），
    //    建表失败会把该插件的每次页面加载都变成 500；SQLite 不挑，本机看不出问题。
    DB::run("CREATE TABLE IF NOT EXISTS plugin_redis_cfg (
        k VARCHAR(64) PRIMARY KEY, " . DB::textCol('v') . ")");

    DB::run("CREATE TABLE IF NOT EXISTS plugin_redis_daily (
        day        VARCHAR(10) NOT NULL DEFAULT '',   -- Y-m-d（仅用于查图，不做键，故可宽）
        msgs       INTEGER NOT NULL DEFAULT 0,        -- 当日消息条数
        acts       INTEGER NOT NULL DEFAULT 0,        -- 当日活跃注册用户数
        logins     INTEGER NOT NULL DEFAULT 0,        -- 当日登录次数
        mirrors    INTEGER NOT NULL DEFAULT 0,        -- 当日镜像写入条数
        updated_at {$ts} DEFAULT 0)");

    DB::createIndex('idx_rds_daily_day', 'plugin_redis_daily', 'day', true);
}

/* ============================ 配置 ============================ */

/**
 * 全部可调参数与默认值。
 * ⚠️ 每加一个新键必须同时：① 这里给默认值；② 后台表单给输入框；③ cfg_save 白名单放行。
 *    漏了 ③ 的表现是「后台保存成功但值没变」。
 */
function owRdsDefaults(): array
{
    return [
        'enable'      => '1',          // 总开关（0 = 完全不连 Redis）
        'host'        => '127.0.0.1',
        'port'        => '6379',
        'pass'        => '',           // 只写不读：接口只回 pass_set
        'db'          => '0',
        'prefix'      => 'owlsgo:',
        'timeout'     => '2',          // 连接与读写超时（秒），收敛到 1~5
        'mirror'      => '1',          // 消息旁路镜像
        'mirror_keep' => '200',        // 每群保留条数
        'mirror_days' => '7',          // 镜像过期天数（0 = 不过期）
        'mirror_dm'   => '0',          // 是否镜像私聊（默认否：私聊内容不进 Redis）
        'stat'        => '1',          // 实时计数（消息 / 镜像 / 登录 / 活跃）
        'stat_days'   => '40',         // 计数键保留天数（须 ≥ 落库周期，留足 cron 窗口）
    ];
}

/**
 * 一次性取回全部配置。
 *
 * 为什么不照抄 daily-checkin 的「一个键一次查询」：本插件注册了 `user.active`
 * ——它在每个（注册用户的）请求上都会触发，于是插件会被加载，
 * 而配置是热路径上第一件要读的东西。按需取键会变成「每请求 3~4 条 SELECT」，
 * 一次取回则固定 1 条。
 */
function owRdsAllCfg(): array
{
    if (isset($GLOBALS['_owRdsAll']) && is_array($GLOBALS['_owRdsAll'])) return $GLOBALS['_owRdsAll'];
    owRdsBoot();
    $cfg = owRdsDefaults();
    try {
        foreach (DB::all('SELECT k, v FROM plugin_redis_cfg') as $r) {
            $k = (string)$r['k'];
            if (array_key_exists($k, $cfg)) $cfg[$k] = (string)$r['v'];
        }
    } catch (Throwable $e) { /* 表还没建好等情况：按默认值走 */ }
    return $GLOBALS['_owRdsAll'] = $cfg;
}

function owRdsCfg(string $k): string
{
    $all = owRdsAllCfg();
    return (string)($all[$k] ?? '');
}

/** 读整数配置：后台填了非法值就回落默认（避免把 keep=abc 变成 0 条） */
function owRdsInt(string $k): int
{
    $v = owRdsCfg($k);
    if (!preg_match('/^-?\d+$/', $v)) return (int)(owRdsDefaults()[$k] ?? 0);
    return (int)$v;
}

function owRdsSetCfg(string $k, string $v): void
{
    owRdsBoot();
    DB::run('DELETE FROM plugin_redis_cfg WHERE k=?', [$k]);
    DB::run('INSERT INTO plugin_redis_cfg (k, v) VALUES (?,?)', [$k, $v]);
    unset($GLOBALS['_owRdsAll'], $GLOBALS['_owRdsConn'], $GLOBALS['_owRdsTtl']);  // 立刻失效
}

/** 归一化后的连接参数（非法值一律收敛到安全默认，绝不带着垃圾去连） */
function owRdsConnCfg(): array
{
    $port = owRdsInt('port');
    $port = ($port >= 1 && $port <= 65535) ? $port : 6379;
    $to   = owRdsInt('timeout');
    $to   = ($to >= 1 && $to <= 5) ? $to : 2;
    $db   = owRdsInt('db');
    $db   = ($db >= 0 && $db <= 15) ? $db : 0;
    $host = trim(owRdsCfg('host'));
    if ($host === '') $host = '127.0.0.1';
    // 前缀只允许安全字符：它会被拼进键名，也是「清空」时 SCAN 的匹配串
    $pfx = trim(owRdsCfg('prefix'));
    if ($pfx === '' || !preg_match('/^[A-Za-z0-9_.:\-]{1,32}$/', $pfx)) $pfx = 'owlsgo:';
    return [
        'enable' => owRdsCfg('enable'),
        'host' => $host, 'port' => $port, 'pass' => owRdsCfg('pass'),
        'db' => $db, 'prefix' => $pfx, 'timeout' => $to,
    ];
}

function owRdsPrefix(): string
{
    if (!isset($GLOBALS['_owRdsPfx'])) $GLOBALS['_owRdsPfx'] = owRdsConnCfg()['prefix'];
    return $GLOBALS['_owRdsPfx'];
}

/* ============================ Redis 客户端 ============================ */

/**
 * 取连接。
 *   返回 null —— 不可用（总开关关闭 / 连不上 / 认证失败）；
 *   返回 ['t' => 'ext'|'raw', 'h' => 句柄] —— 可用。
 *
 * ⚠️ 失败结果同样被缓存（$GLOBALS['_owRdsConn']）：这就是**熔断**——
 *    Redis 挂掉时，一个请求里只尝试一次连接，后续所有调用直接短路，
 *    否则一条消息触发 3 次 Redis 操作就是 3 次 2 秒超时，热路径会被拖死。
 */
function owRdsConn()
{
    if (array_key_exists('_owRdsConn', $GLOBALS)) return $GLOBALS['_owRdsConn'];
    $GLOBALS['_owRdsConn'] = null;
    $c = owRdsConnCfg();
    if ($c['enable'] !== '1') return null;

    if (class_exists('Redis')) {
        try {
            $r = new Redis();
            if (@$r->connect($c['host'], $c['port'], $c['timeout'])) {
                if ($c['pass'] !== '' && !@$r->auth($c['pass'])) return null;
                if ($c['db'] > 0) @$r->select($c['db']);
                return $GLOBALS['_owRdsConn'] = ['t' => 'ext', 'h' => $r];
            }
        } catch (Throwable $e) { /* 扩展存在但连不上：落到内置客户端再试一次 */ }
    }

    $fp = @fsockopen($c['host'], $c['port'], $no, $st, $c['timeout']);
    if (!$fp) return null;
    @stream_set_timeout($fp, $c['timeout']);
    try {
        if ($c['pass'] !== '') {
            $r = owRdsRawCmd($fp, ['AUTH', $c['pass']]);
            if ($r !== 'OK') { @fclose($fp); return null; }
        }
        if ($c['db'] > 0) owRdsRawCmd($fp, ['SELECT', (string)$c['db']]);
    } catch (Throwable $e) { @fclose($fp); return null; }
    return $GLOBALS['_owRdsConn'] = ['t' => 'raw', 'h' => $fp];
}

/**
 * 统一的命令出口：$argv = ['LPUSH', 'key', 'value']。
 * 返回 int / string / array；**返回 null 表示不可用或失败**（调用方据此短路）。
 * 扩展与内置客户端在这里合流，业务代码不关心底层用的是哪一个。
 */
function owRdsCmd(array $argv)
{
    $c = owRdsConn();
    if (!$c) return null;
    try {
        if ($c['t'] === 'ext') {
            $r = $c['h'];
            if (!method_exists($r, 'rawCommand')) return null;   // 老扩展兜底：不硬撑
            $name = (string)array_shift($argv);
            $res = @$r->rawCommand($name, ...$argv);
            return $res === false ? null : $res;
        }
        return owRdsRawCmd($c['h'], $argv);
    } catch (Throwable $e) { return null; }
}

/** 内置 RESP 客户端：发一条命令并读一条回复 */
function owRdsRawCmd($fp, array $argv)
{
    $out = '*' . count($argv) . "\r\n";
    foreach ($argv as $a) {
        $a = (string)$a;
        $out .= '$' . strlen($a) . "\r\n" . $a . "\r\n";
    }
    if (@fwrite($fp, $out) === false) throw new RuntimeException('redis write failed');
    return owRdsRawRead($fp);
}

/** 读一行（以 CRLF 结尾）。连接断开时返回空串，由调用方判为失败。 */
function owRdsRawLine($fp): string
{
    $line = '';
    while (($chunk = fgets($fp, 8192)) !== false && $chunk !== '') {
        $line .= $chunk;
        if (substr($line, -2) === "\r\n") break;
    }
    return rtrim($line, "\r\n");
}

/**
 * 解析一条 RESP 回复（递归处理数组）。
 * 支持五种类型：+ 状态 / - 错误 / : 整数 / $ 批量字符串 / * 数组。
 */
function owRdsRawRead($fp)
{
    $line = owRdsRawLine($fp);
    if ($line === '') throw new RuntimeException('redis connection closed');
    $type = $line[0];
    $rest = substr($line, 1);
    switch ($type) {
        case '+': return $rest;                            // 状态回复：OK / PONG
        case '-': throw new RuntimeException($rest);       // 错误回复：交给上层吞掉
        case ':': return (int)$rest;                       // 整数
        case '$':                                          // 批量字符串
            $n = (int)$rest;
            if ($n < 0) return null;                       // nil
            $buf = '';
            // ⚠️ 要读满 n+2 字节：**尾部 CRLF 必须一起消费掉**，否则它会留在缓冲区里，
            //    被下一条命令的解析当成一个空行（本机实测：DBSIZE 的回复因此读成空串）。
            while (strlen($buf) < $n + 2) {
                $chunk = fread($fp, $n + 2 - strlen($buf));
                if ($chunk === false || $chunk === '') break;
                $buf .= $chunk;
            }
            if (strlen($buf) < $n) throw new RuntimeException('short bulk reply');
            return substr($buf, 0, $n);
        case '*':                                          // 数组
            $n = (int)$rest;
            if ($n < 0) return null;
            $arr = [];
            for ($i = 0; $i < $n; $i++) $arr[] = owRdsRawRead($fp);
            return $arr;
        default: return $line;                             // 协议异常：原样返回，不猜
    }
}

/** 续 TTL，但同一请求内同一个键只续一次（省掉每条消息一次的 EXPIRE 往返） */
function owRdsEnsureTtl(string $key, int $sec): void
{
    if ($sec <= 0) return;
    if (!isset($GLOBALS['_owRdsTtl']) || !is_array($GLOBALS['_owRdsTtl'])) $GLOBALS['_owRdsTtl'] = [];
    if (!empty($GLOBALS['_owRdsTtl'][$key])) return;
    $GLOBALS['_owRdsTtl'][$key] = 1;
    owRdsCmd(['EXPIRE', $key, (string)$sec]);
}

/* ============================ 键与业务 ============================ */

function owRdsMirrorKey(int $roomId): string
{
    $p = owRdsPrefix();
    return $roomId > 0 ? $p . 'msg:r' . $roomId : $p . 'msg:dm';
}
function owRdsRoomsKey(): string { return owRdsPrefix() . 'rooms'; }
function owRdsStatKey(string $name, string $day): string { return owRdsPrefix() . 'stat:' . $name . ':' . $day; }

/** 镜像元素的字段顺序固定 —— 写与读都走这一个函数，字段集不随调用方漂移 */
function owRdsPayload(array $f): string
{
    return (string)json_encode([
        'id'     => (int)$f['id'],
        'rid'    => (int)$f['rid'],
        'uid'    => (int)$f['uid'],
        'gid'    => (int)$f['gid'],
        'nick'   => (string)$f['nick'],
        'role'   => (string)$f['role'],
        'title'  => (string)$f['title'],
        'avatar' => (string)$f['avatar'],
        'type'   => (string)$f['type'],
        'text'   => (string)$f['text'],
        'uid_to' => (int)$f['uid_to'],
        'ts'     => (int)$f['ts'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** 写镜像：LPUSH + LTRIM + EXPIRE，异常全部吞掉（旁路不允许影响发消息） */
function owRdsMirrorPush(int $roomId, array $f): bool
{
    if (owRdsCfg('mirror') !== '1') return false;
    if ($roomId <= 0 && owRdsCfg('mirror_dm') !== '1') return false;   // 私聊默认不进 Redis
    if (owRdsConn() === null) return false;

    $keep = owRdsInt('mirror_keep');
    if ($keep < 10) $keep = 10;
    if ($keep > 5000) $keep = 5000;
    $key = owRdsMirrorKey($roomId);

    if (owRdsCmd(['LPUSH', $key, owRdsPayload($f)]) === null) return false;
    owRdsCmd(['LTRIM', $key, '0', (string)($keep - 1)]);
    $days = owRdsInt('mirror_days');
    if ($days > 0) owRdsEnsureTtl($key, $days * 86400);
    if ($roomId > 0) owRdsCmd(['SADD', owRdsRoomsKey(), (string)$roomId]);
    return true;
}

/**
 * 删镜像（撤回时调用）。
 * ⚠️ 拿「从 Redis 里读回来的那串原文」做 LREM 的匹配值，**不试图在 PHP 侧重构 JSON**
 *    —— 字段顺序、转义、数字形态任何一点不同都匹配不上，重构必然随核心改动而漂移。
 */
function owRdsMirrorDrop(int $roomId, int $msgId): void
{
    if ($msgId <= 0) return;
    if ($roomId <= 0 && owRdsCfg('mirror_dm') !== '1') return;
    if (owRdsConn() === null) return;
    $key = owRdsMirrorKey($roomId);
    $items = owRdsCmd(['LRANGE', $key, '0', '-1']);
    if (!is_array($items)) return;
    foreach ($items as $raw) {
        $d = json_decode((string)$raw, true);
        if (is_array($d) && (int)($d['id'] ?? 0) === $msgId) {
            owRdsCmd(['LREM', $key, '1', (string)$raw]);
            return;
        }
    }
}

/** 计数自增（按自然日的键） */
function owRdsBump(string $name, int $by = 1): void
{
    if (owRdsCfg('stat') !== '1') return;
    if (owRdsConn() === null) return;
    $key = owRdsStatKey($name, date('Y-m-d'));
    if (owRdsCmd(['INCRBY', $key, (string)max(0, $by)]) === null) return;
    $d = owRdsInt('stat_days');
    if ($d < 2) $d = 2;
    if ($d > 365) $d = 365;
    owRdsEnsureTtl($key, $d * 86400);
}

/** 记一次活跃（Set 去重，同一天同一人只算一个） */
function owRdsTouchActive(int $uid): void
{
    if ($uid <= 0 || owRdsCfg('stat') !== '1') return;
    if (owRdsConn() === null) return;
    $key = owRdsStatKey('act', date('Y-m-d'));
    if (owRdsCmd(['SADD', $key, (string)$uid]) === null) return;
    $d = owRdsInt('stat_days');
    if ($d < 2) $d = 2;
    if ($d > 365) $d = 365;
    owRdsEnsureTtl($key, $d * 86400);
}

/** Redis 侧当日实时值；null 表示 Redis 不可用（与「值就是 0」严格区分） */
function owRdsTodayLive(): ?array
{
    if (owRdsConn() === null) return null;
    $day = date('Y-m-d');
    $msgs = owRdsCmd(['GET', owRdsStatKey('msg', $day)]);
    $mir  = owRdsCmd(['GET', owRdsStatKey('mir', $day)]);
    $lgn  = owRdsCmd(['GET', owRdsStatKey('login', $day)]);
    $acts = owRdsCmd(['SCARD', owRdsStatKey('act', $day)]);
    return ['day' => $day, 'msgs' => (int)$msgs, 'mirrors' => (int)$mir,
            'logins' => (int)$lgn, 'acts' => (int)$acts];
}

/** DB 侧近 N 天趋势（补齐空白天，柱子不会在没数据的日子断开） */
function owRdsTrend(int $days = 14): array
{
    owRdsBoot();
    $from = date('Y-m-d', time() - ($days - 1) * 86400);
    $got = [];
    try {
        foreach (DB::all('SELECT day, msgs, acts, logins, mirrors FROM plugin_redis_daily WHERE day>=? ORDER BY day ASC', [$from]) as $r) {
            $got[(string)$r['day']] = $r;
        }
    } catch (Throwable $e) { /* 读不到就当全空 */ }
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', time() - $i * 86400);
        $r = $got[$d] ?? null;
        $out[] = [
            'day' => $d,
            'msgs' => (int)($r['msgs'] ?? 0), 'acts' => (int)($r['acts'] ?? 0),
            'logins' => (int)($r['logins'] ?? 0), 'mirrors' => (int)($r['mirrors'] ?? 0),
        ];
    }
    return $out;
}

/** 有镜像数据的房间（含条数与房间名）；顺手剔除已过期/写失败的空房间索引 */
function owRdsMirrorRooms(int $limit = 10): array
{
    if (owRdsConn() === null) return [];
    $ids = owRdsCmd(['SMEMBERS', owRdsRoomsKey()]);
    if (!is_array($ids)) return [];
    $list = [];
    foreach ($ids as $id) {
        $id = (int)$id;
        if ($id <= 0) continue;
        $n = owRdsCmd(['LLEN', owRdsMirrorKey($id)]);
        if ($n === null || (int)$n <= 0) { owRdsCmd(['SREM', owRdsRoomsKey(), (string)$id]); continue; }
        $list[] = ['room_id' => $id, 'n' => (int)$n, 'name' => ''];
    }
    usort($list, fn($a, $b) => $b['n'] <=> $a['n']);
    $list = array_slice($list, 0, max(1, min(50, $limit)));
    if ($list) {
        // 房间名单独一次查询：IN 的占位符按条数生成（禁止把 ID 拼进 SQL）
        $ph = implode(',', array_fill(0, count($list), '?'));
        $args = array_map(fn($x) => $x['room_id'], $list);
        try {
            $names = [];
            foreach (DB::all("SELECT id, name FROM rooms WHERE id IN ($ph)", $args) as $r) {
                $names[(int)$r['id']] = (string)$r['name'];
            }
            foreach ($list as $i => $x) $list[$i]['name'] = $names[$x['room_id']] ?? '';
        } catch (Throwable $e) { /* 名字拿不到就留空，不影响条数展示 */ }
    }
    return $list;
}

/** 某群最近镜像消息（新的在前） */
function owRdsRoomPreview(int $roomId, int $limit = 20): array
{
    if (owRdsConn() === null) return [];
    $limit = max(1, min(50, $limit));
    $items = owRdsCmd(['LRANGE', owRdsMirrorKey($roomId), '0', (string)($limit - 1)]);
    if (!is_array($items)) return [];
    $out = [];
    foreach ($items as $raw) {
        $d = json_decode((string)$raw, true);
        if (!is_array($d)) continue;
        $out[] = [
            'id' => (int)($d['id'] ?? 0),
            'nick' => (string)($d['nick'] ?? ''), 'role' => (string)($d['role'] ?? ''),
            'uid' => (int)($d['uid'] ?? 0), 'gid' => (int)($d['gid'] ?? 0),
            'type' => (string)($d['type'] ?? ''), 'text' => (string)($d['text'] ?? ''),
            'ts' => (int)($d['ts'] ?? 0),
        ];
    }
    return $out;
}

/**
 * 连接体检。
 * 刻意不走 owRdsConn()（那个会熔断缓存失败），这里每次都真连一次，
 * 目的是把「为什么连不上」说清楚 —— 后台点一下就该知道是端口不对、要密码，还是超时。
 */
function owRdsProbe(): array
{
    $c = owRdsConnCfg();
    $r = [
        'enable' => $c['enable'] === '1', 'host' => $c['host'] . ':' . $c['port'], 'db' => $c['db'],
        'prefix' => $c['prefix'], 'ext' => class_exists('Redis'),
        'client' => class_exists('Redis') ? 'phpredis 扩展' : '内置 RESP（无扩展依赖）',
        'ok' => false, 'msg' => '', 'latency' => 0, 'version' => '', 'memory' => '',
        'keys' => 0, 'hits' => 0, 'misses' => 0, 'expires' => 0, 'rw' => false,
        'auth' => $c['pass'] !== '',
    ];
    if (!$r['enable']) { $r['msg'] = '插件总开关处于关闭状态'; return $r; }

    $t0 = microtime(true);
    $fp = @fsockopen($c['host'], $c['port'], $no, $st, $c['timeout']);
    if (!$fp) {
        $r['msg'] = '连接失败：' . ($st !== '' ? $st : ('错误码 ' . $no))
                  . '（检查地址端口、Redis 是否已启动、服务器防火墙）';
        return $r;
    }
    @stream_set_timeout($fp, $c['timeout']);
    try {
        if ($c['pass'] !== '') {
            $a = owRdsRawCmd($fp, ['AUTH', $c['pass']]);
            if ($a !== 'OK') { $r['msg'] = '认证失败：AUTH 被拒绝（密码不对，或 Redis 未设密码却填了密码）'; @fclose($fp); return $r; }
        }
        if ($c['db'] > 0) owRdsRawCmd($fp, ['SELECT', (string)$c['db']]);

        if (owRdsRawCmd($fp, ['PING']) !== 'PONG') {
            $r['msg'] = 'PING 未返回 PONG（对端可能不是 Redis）'; @fclose($fp); return $r;
        }
        $r['latency'] = round((microtime(true) - $t0) * 1000, 1);

        $info = owRdsRawCmd($fp, ['INFO']);
        if (is_string($info)) {
            foreach (explode("\n", $info) as $ln) {
                $ln = trim($ln);
                if (strpos($ln, 'redis_version:') === 0)      $r['version'] = substr($ln, 14);
                elseif (strpos($ln, 'used_memory_human:') === 0) $r['memory'] = substr($ln, 18);
                elseif (strpos($ln, 'keyspace_hits:') === 0)  $r['hits'] = (int)substr($ln, 14);
                elseif (strpos($ln, 'keyspace_misses:') === 0) $r['misses'] = (int)substr($ln, 16);
            }
        }
        // dbN:keys=12,expires=3,avg_ttl=0 —— 只认目标库那一行
        if (is_string($info) && preg_match('/^db' . $c['db'] . ':keys=(\d+),expires=(\d+)/m', $info, $m)) {
            $r['keys'] = (int)$m[1];
            $r['expires'] = (int)$m[2];
        }
        $db = owRdsRawCmd($fp, ['DBSIZE']);
        if (is_int($db) && $r['keys'] === 0) $r['keys'] = $db;

        // 写—读—删探针：验证真的有写权限（只读实例 / 内存满 maxmemory 只读，都会在这里露出来）
        $pk = $c['prefix'] . 'probe:' . substr(md5(uniqid('', true)), 0, 12);
        owRdsRawCmd($fp, ['SETEX', $pk, '30', '1']);
        $got = owRdsRawCmd($fp, ['GET', $pk]);
        owRdsRawCmd($fp, ['DEL', $pk]);
        $r['rw'] = ($got === '1');
        if (!$r['rw']) { $r['msg'] = '可以连接，但「写—读—删」探针未通过（可能磁盘写满 / maxmemory 已满 / 只读从库）'; return $r; }

        $r['ok'] = true;
        $r['msg'] = '连接正常';
    } catch (Throwable $e) {
        $r['msg'] = '通信异常：' . $e->getMessage();
    }
    @fclose($fp);
    return $r;
}

/** 清空本插件前缀下的所有键（SCAN 增量删，不阻塞 Redis，也不碰别人的数据） */
function owRdsFlushPrefix(): int
{
    $c = owRdsConn();
    if (!$c) return -1;
    $pattern = owRdsConnCfg()['prefix'] . '*';
    $cursor = '0';
    $deleted = 0;
    $guard = 0;
    do {
        $res = owRdsCmd(['SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '200']);
        if (!is_array($res) || count($res) < 2) break;
        $cursor = (string)$res[0];
        $keys = is_array($res[1]) ? $res[1] : [];
        if ($keys) {
            $arg = array_merge(['DEL'], array_map('strval', $keys));
            $n = owRdsCmd($arg);
            if (is_int($n)) $deleted += $n;
        }
        if (++$guard > 500) break;   // 兜底：SCAN 游标异常时不要死循环
    } while ($cursor !== '0');
    // 镜像索引与本次的熔断缓存一并清掉
    owRdsCmd(['DEL', owRdsRoomsKey()]);
    unset($GLOBALS['_owRdsTtl']);
    return $deleted;
}

/* ============================ 钩子（写入侧，只有一个方向） ============================ */

/**
 * 消息入库后：写镜像 + 计一条消息。
 * 钩子签名来自 core/chat.php:887 —— 参数是**追加**的，老插件多余形参不受影响，
 * 所以这里把后 3 个都带上（type / content / toUserId 都用于生成镜像摘要）。
 */
Plugin::on('message.after_send', function ($id, $actor, $roomId, $content = '', $type = 'text', $toUserId = 0) {
    try {
        $roomId = (int)$roomId;
        if (owRdsConn() === null) return;
        $ok = owRdsMirrorPush($roomId, [
            'id' => (int)$id, 'rid' => $roomId,
            'uid' => ($actor['kind'] ?? '') === 'user' ? (int)$actor['id'] : 0,
            'gid' => ($actor['kind'] ?? '') === 'guest' ? (int)$actor['id'] : 0,
            'nick' => (string)($actor['nickname'] ?? ''),
            'role' => (string)($actor['role'] ?? 'guest'),
            'title' => (string)($actor['title'] ?? ''),
            'avatar' => (string)($actor['avatar'] ?? ''),
            'type' => (string)$type, 'text' => (string)$content,
            'uid_to' => (int)$toUserId, 'ts' => time(),
        ]);
        owRdsBump('msg');
        if ($ok) owRdsBump('mir');
    } catch (Throwable $e) { /* 旁路失败，绝不冒泡 */ }
});

/** 撤回：镜像里同步摘掉（删除是「仅本机隐藏」，不影响镜像，故不处理） */
Plugin::on('msg.after_recall', function ($msgId, $m, $actor) {
    try {
        if (!is_array($m)) return;
        owRdsMirrorDrop((int)($m['room_id'] ?? 0), (int)$msgId);
    } catch (Throwable $e) { /* 同上 */ }
});

/**
 * 每请求的活跃（含 20 秒长轮询）：注册用户按自然日在 Redis 里记一个 Set 成员。
 * ⚠️ 这是热路径：本插件被加载时固定 1 条 SELECT（取配置）+ 1 条 SADD。
 *    后台把「实时计数」关掉即可整段短路。
 */
Plugin::on('user.active', function (array $actor) {
    try {
        if (($actor['kind'] ?? '') !== 'user') return;
        owRdsTouchActive((int)($actor['id'] ?? 0));
    } catch (Throwable $e) { /* 同上 */ }
});

/** 登录成功：计一次登录（失败不计数 —— 登录失败已有核心的 login_attempts 兜底） */
Plugin::on('login.after_verify', function ($user, $method = 'password', $ctx = []) {
    try { owRdsBump('login'); } catch (Throwable $e) { /* 同上 */ }
});

/* ============================ 计划任务 ============================ */

/**
 * 每 5 分钟把当日计数落库（Redis 键会过期，趋势图不能依赖它）。
 * ⚠️ 用 max(旧值, 新值) 而不是直接覆盖：Redis 重启/清库后当日值会归零，
 *    直接写会把已经落库的当日高点改小，图上出现「今天倒着长」。
 */
Plugin::cron('rds_sync', 300, function () {
    try {
        if (owRdsCfg('enable') !== '1') return;
        owRdsBoot();
        $day = date('Y-m-d');
        $live = owRdsTodayLive();
        if ($live === null) return;   // Redis 不可用：什么都不做，保留库里的旧值

        $cur = DB::one('SELECT * FROM plugin_redis_daily WHERE day=?', [$day]) ?: [];
        $row = [
            'day' => $day,
            'msgs' => max((int)($cur['msgs'] ?? 0), (int)$live['msgs']),
            'acts' => max((int)($cur['acts'] ?? 0), (int)$live['acts']),
            'logins' => max((int)($cur['logins'] ?? 0), (int)$live['logins']),
            'mirrors' => max((int)($cur['mirrors'] ?? 0), (int)$live['mirrors']),
            'updated_at' => time(),
        ];
        DB::upsert('plugin_redis_daily', $row, ['day']);
    } catch (Throwable $e) { /* 定时任务出错不影响其它任务（核心也会兜） */ }
}, 'Redis 每日计数落库');

/* ============================ 后台路由 ============================ */

/** 管理员鉴权（use 捕获的是变量值，必须先定义） */
$rdsGuard = function (array $ctx): void {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
};
/** 配置回填：密码只回「是否已设置」，永不回原文 */
$rdsMaskedCfg = function (): array {
    $cfg = owRdsAllCfg();
    $cfg['pass'] = '';
    $cfg['pass_set'] = owRdsCfg('pass') !== '' ? '1' : '0';
    return $cfg;
};

/** 概览：今日实时（Redis）+ 近 14 天（DB）+ 群镜像榜 + 配置 */
Plugin::route('plugin_redis_stats', function (array $ctx) use ($rdsGuard, $rdsMaskedCfg) {
    $rdsGuard($ctx);
    Api::json([
        'ok' => true,
        'today' => owRdsTodayLive(),
        'trend' => owRdsTrend(14),
        'rooms' => owRdsMirrorRooms(10),
        'cfg' => $rdsMaskedCfg(),
        'connected' => owRdsConn() !== null,
    ]);
});

/** 连接体检（每次都真连一次，失败要给出原因） */
Plugin::route('plugin_redis_probe', function (array $ctx) use ($rdsGuard) {
    $rdsGuard($ctx);
    Api::json(['ok' => true, 'probe' => owRdsProbe()]);
});

/** 查看某群最近镜像消息 */
Plugin::route('plugin_redis_preview', function (array $ctx) use ($rdsGuard) {
    $rdsGuard($ctx);
    $roomId = (int)($ctx['post']['room_id'] ?? 0);
    Api::json(['ok' => true, 'room_id' => $roomId, 'rows' => owRdsRoomPreview($roomId, 20)]);
});

/** 保存配置（白名单 = owRdsDefaults 的键集合，前端塞别的键也落不了库） */
Plugin::route('plugin_redis_cfg_save', function (array $ctx) use ($rdsGuard) {
    $rdsGuard($ctx);
    $post = $ctx['post'];
    $saved = 0;
    foreach (array_keys(owRdsDefaults()) as $k) {
        if (!isset($post[$k])) continue;
        $v = trim((string)$post[$k]);
        if ($k === 'pass') {
            // 只写不读：显式勾了「清除密码」才写空；留空一律视为「不修改」
            if (!empty($post['pass_clear'])) { owRdsSetCfg('pass', ''); $saved++; }
            else if ($v !== '') { owRdsSetCfg('pass', $v); $saved++; }
            continue;
        }
        if (in_array($k, ['port', 'db', 'timeout', 'mirror_keep', 'mirror_days', 'stat_days'], true)
            && preg_match('/^-?\d+$/', $v)) {
            $n = (int)$v;
            if ($n < 0) $n = 0;
            if ($n > 100000) $n = 100000;
            $v = (string)$n;
        }
        if ($k === 'prefix' && !preg_match('/^[A-Za-z0-9_.:\-]{1,32}$/', $v)) {
            Api::json(['ok' => false, 'msg' => '键前缀只能包含字母、数字、下划线、点、冒号、短横线（1~32 字符）']);
        }
        owRdsSetCfg($k, $v);
        $saved++;
    }
    Sec::log('redis_cfg_save', (string)($ctx['actor']['nickname'] ?? ''), ['items' => $saved]);
    Api::json(['ok' => true, 'msg' => '已保存 ' . $saved . ' 项，配置立即生效']);
});
Plugin::sensitive('plugin_redis_cfg_save');

/** 清空本插件前缀下的键（危险操作：给票据 + 二次确认） */
Plugin::route('plugin_redis_flush', function (array $ctx) use ($rdsGuard) {
    $rdsGuard($ctx);
    $prefix = owRdsConnCfg()['prefix'];
    if (owRdsConn() === null) Api::json(['ok' => false, 'msg' => 'Redis 当前不可用，无法清空']);
    $n = owRdsFlushPrefix();
    if ($n < 0) Api::json(['ok' => false, 'msg' => '清空失败：Redis 通信异常']);
    Sec::log('redis_flush', (string)($ctx['actor']['nickname'] ?? ''), ['prefix' => $prefix, 'keys' => $n]);
    Api::json(['ok' => true, 'msg' => '已删除前缀 ' . $prefix . '* 下的 ' . $n . ' 个键（数据库里的每日计数不受影响）']);
});
Plugin::sensitive('plugin_redis_flush');

/* ============================ 后台页 ============================ */

Plugin::adminPage('redis', 'Redis 缓存', function () {
    $f = function (string $k, string $label, string $hint = '', string $ph = '') {
        return '<div class="ow-form-item"><label>' . Sec::e($label) . '</label>'
            . '<input class="ow-input" id="owRdsCfg_' . $k . '" value="' . Sec::e(owRdsCfg($k)) . '"'
            . ($ph !== '' ? ' placeholder="' . Sec::e($ph) . '"' : '') . '>'
            . ($hint !== '' ? '<p style="font-size:12px">' . Sec::e($hint) . '</p>' : '')
            . '</div>';
    };
    $sel = function (string $k, string $label, array $opts, string $hint = '') {
        $h = '<div class="ow-form-item"><label>' . Sec::e($label) . '</label>'
           . '<select class="ow-input" id="owRdsCfg_' . $k . '">';
        $cur = owRdsCfg($k);
        foreach ($opts as $v => $t) {
            $h .= '<option value="' . Sec::e($v) . '"' . ($cur === (string)$v ? ' selected' : '') . '>'
                . Sec::e($t) . '</option>';
        }
        $h .= '</select>' . ($hint !== '' ? '<p style="font-size:12px">' . Sec::e($hint) . '</p>' : '') . '</div>';
        return $h;
    };

    $h = '<h2>Redis 缓存</h2>'
        . '<p class="ow-admin-desc">把消息与活跃数据旁路镜像进 Redis，并做连接体检与每日计数落库。'
        . '⚠️ <strong>本插件不接管聊天的读路径</strong>——历史与轮询仍走数据库，'
        . '让 Redis 真正加速聊天需要核心补钩子（见页面底部「核心钩子待办」）。</p>';

    // 状态条：一句话说清「现在 Redis 到底在不在干活」
    $h .= '<div class="ow-card"><div id="owRdsState" class="ow-rds-state">正在检测…</div>'
        . '<div class="ow-modal-actions">'
        . '<button class="ow-btn ow-btn-primary" onclick="OwRdsAdmin.probe()">重新体检</button>'
        . '<button class="ow-btn ow-btn-ghost" onclick="OwRdsAdmin.load()">刷新数据</button>'
        . '</div><div id="owRdsProbe"></div></div>';

    $h .= '<div class="ow-card" id="owRdsKpi"></div>'
        . '<div class="ow-card"><h3 style="margin:0 0 4px">近 14 天消息量</h3>'
        . '<div id="owRdsTrend"></div></div>'
        . '<div class="ow-card"><h3 style="margin:0 0 10px">已镜像的群</h3>'
        . '<div id="owRdsRooms"></div><div id="owRdsPreview"></div></div>';

    // 配置：连接 / 镜像 / 计数 三块
    $h .= '<div class="ow-card"><h3 style="margin:0 0 10px">连接设置</h3>'
        . '<p style="font-size:12px">填的是 Redis 服务进程的地址，不是网站的地址；改完点本卡片下方「保存」'
        . '即时生效，无需重启 PHP。</p><div class="ow-form-row">'
        . $sel('enable', '总开关', ['1' => '开启', '0' => '关闭（完全不连 Redis）'])
        . $f('host', '主机地址', 'Redis 与本站同机时填 127.0.0.1', '127.0.0.1')
        . $f('port', '端口', '默认 6379', '6379')
        . $f('db', '库序号', '0~15；只用其中一个库，别与别的程序撞号', '0')
        . $f('prefix', '键前缀', '本插件的键都以此开头；「清空」也只删这个前缀', 'owlsgo:')
        . $f('timeout', '超时（秒）', '连接与读写超时，1~5；Redis 慢时调小，避免拖慢发消息', '2')
        . '<div class="ow-form-item" style="flex:1"><label>密码</label>'
        . '<input class="ow-input" id="owRdsCfg_pass" type="password" value="" placeholder="留空 = 不修改">'
        . '<p style="font-size:12px">当前状态：' . (owRdsCfg('pass') !== '' ? '已设置密码' : '未设置密码')
        . '。为安全起见后台不回显已保存的密码，留空保存不会清掉它。</p></div>'
        . '</div>'
        // 勾选框单独占一行、紧跟在密码之后（与 chat-scripts 的「启用 AI 应答」同款结构）。
        // 为什么不能塞进上面那个 .ow-form-row：核心 .ow-form-row 是 align-items: flex-end，
        // 而密码格下方还有一行 ⓘ 提示，勾选框会被顶到底对齐位置，看起来比输入框低一截。
        . '<div class="ow-form-item"><label class="ow-rds-check"><input type="checkbox" id="owRdsCfg_pass_clear" value="1">'
        . '<span>显式清空已保存的密码</span></label></div></div>';

    $h .= '<div class="ow-card"><h3 style="margin:0 0 10px">镜像与计数</h3><div class="ow-form-row">'
        . $sel('mirror', '消息镜像', ['1' => '开启', '0' => '关闭'], '发消息时写一条摘要进 Redis（每群一个列表）')
        . $f('mirror_keep', '每群保留条数', '超出的从最旧的开始丢，10~5000')
        . $f('mirror_days', '镜像过期天数', '0 = 不过期；过期后键自动消失，数据库不受影响')
        . $sel('mirror_dm', '镜像私聊', ['0' => '不镜像（推荐）', '1' => '镜像'], '私聊内容进 Redis 属于敏感数据，默认不写')
        . $sel('stat', '实时计数', ['1' => '开启', '0' => '关闭'], '消息数 / 登录数 / 活跃用户；每个请求会多一次 Redis 写')
        . $f('stat_days', '计数键保留天数', '要大于落库周期（5 分钟），默认 40 天足够')
        . '</div>'
        . '<div class="ow-modal-actions">'
        . '<button class="ow-btn ow-btn-primary" onclick="OwRdsAdmin.saveCfg()">保存设置</button>'
        . '<button class="ow-btn ow-btn-ghost" onclick="OwRdsAdmin.flush()">清空 Redis 中的本插件数据</button>'
        . '</div></div>';

    // 核心钩子待办：把「要什么」写清楚，站方照抄即可
    $todo = [
        ['history.rows', 'core/chat.php:992（Chat::history 里的 DB::all 之后）',
            "Plugin::fire('history.rows', [&\$rows, \$roomId, \$beforeId, \$limit, \$fromId, \$actor]);",
            '插件在 $rows 为空时回填缓存行。pack() / 可见性 / 隐藏过滤仍由核心做 —— 插件只提供原始行，不复制核心规则。'],
        ['poll.hint', 'core/chat.php:1070（Chat::poll 的 while 循环内）',
            "\$hit = ''; Plugin::fire('poll.hint', [&\$hit, \$roomId, \$sinceId]); if (\$hit !== '1') { /* 原有 SELECT */ }",
            'Redis 侧用发布订阅/列表长度替代「每 0.5 秒查一次库」的探活。'],
        ['online.track / online.list', 'core/chat.php:2021（heartbeat 的 upsert）与 2034（onlineList 的 SELECT）',
            "Plugin::fire('online.track', [\$actor, \$roomId]);   Plugin::fire('online.list', [&\$rows, \$roomId]);",
            '在线状态是本项目最像 Redis 的场景：现在是每请求一次 upsert + 一次全表 DELETE。'],
        ['rate.limit', 'core/security.php:126（Sec::rateLimit 计数处）',
            "Plugin::fire('rate.limit', [&\$handled, \$bucket, \$ident, \$window, \$max]);",
            'INCR + EXPIRE 天然原子。注意：核心里「速率窗口只读不续期」的语义必须保留。'],
        ['login.attempt', 'core/security.php:154（loginFails / loginLocked 读取处）',
            "Plugin::fire('login.attempt', [&\$fails, \$identity]);",
            '登录失败计数与锁定时间进 Redis，跨进程一致。'],
    ];
    $h .= '<div class="ow-card"><h3 style="margin:0 0 10px">核心钩子待办（需要手工改核心）</h3>'
        . '<p style="font-size:12px">本插件已经把写入侧与统计侧做完了；要让 Redis 接管「读消息」这条路，'
        . '只在核心补下面 5 个 fire 点即可，插件侧不用再改一行。'
        . '原则：<strong>插件只提供原始行，可见性过滤一律留在核心</strong>——'
        . '否则隐藏消息、密码房、游客这些规则会被复制一份，核心一改口径插件就错。</p>';
    foreach ($todo as $i => $t) {
        $h .= '<div class="ow-rds-todo"><div class="ow-rds-todo-h"><b>' . ($i + 1) . '. '
            . Sec::e($t[0]) . '</b><span>' . Sec::e($t[1]) . '</span></div>'
            . '<div class="ow-rds-scroll"><code>' . Sec::e($t[2]) . '</code></div>'
            . '<div class="ow-rds-todo-d">' . Sec::e($t[3]) . '</div></div>';
    }
    $h .= '</div>';

    return $h;
});

/* ============================ 资源 ============================ */

Plugin::asset('css', 'redis/style.css');
Plugin::asset('js', 'redis/admin.js');
