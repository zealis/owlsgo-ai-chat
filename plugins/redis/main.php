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
 *   ⑤ 读路径接手：核心 v1.3.155 为「让 Redis 接管读消息」开了 6 处 fire 点，本插件接其中 5 个
 *      （history.rows 校准水位、poll.hint 探活短路、online.track/.list 在场镜像、rate.limit 计数），
 *      **login.attempt 刻意不接**——核心只开了读没开写，自己计数就得复制三条安全规则，理由写在
 *      「读路径接手」小节顶部。能不能接取决于核心有没有同时开写入侧，后台页逐条列了状态与原因。
 *
 * ── 读路径接手（v1.3.159 起：核心侧的火点已全部就位）────────────────
 *   v1.3.155 起核心在读路径上开了历史/轮询/在线/限流/登录计数 5 处 fire 点，
 *   v1.3.159 补上最后一块拼图 Chat::rooms 的 `rooms.rows`（公开房间列表 —— 全站读得最频繁的一处）。
 *   于是本插件现在**真的能替核心省查询**：poll.hint 长轮询短路、online.track/list 在场名单、
 *   rate.limit 计数、rooms.rows 房间列表（缓存热时不查库）、history.rows 校准水位。
 *   唯一**刻意不接**的是 login.attempt：核心只开了读、没开写（失败自增与成功清零仍在库函数里），
 *   硬接就得把「阈值 / 锁定分钟 / 到期重算」三条安全规则复制一份 —— 代价不对等。
 *   ⇒ 逐处的状态、开关与理由见后台页「读路径接手」卡片。
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
        // ---- 读路径接手（核心 v1.3.155 起的 6 处 fire 点）----
        'readpath'    => '1',          // history.rows / poll.hint / online.track+list
        'ratelimit'   => '1',          // rate.limit（INCR + EXPIRE；mail* 桶一律不接，见注释）
        // ---- 公开房间列表缓存（读得最频繁的一处）----
        'rooms'       => '1',          // rooms.rows：缓存 rooms 表的原始行
        'rooms_ttl'   => '180',        // 缓存寿命（秒）；另有一个 60 秒的计划任务保活
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
            $v = (string)$r['v'];
            // ⚠️ 空串行一律忽略，不能让它盖掉默认值：老版本保存后台表单时会把留空的输入框
            //    写成 ''（timeout 就留下过这样一行），于是输入框显示空白、看着像「没配上」，
            //    而代码里其实走的是默认值 —— 存储与显示两套口径。新写入端已改成空值即删行，
            //    这里再兜一道，保证「空 = 用默认」是唯一口径。
            if ($v !== '' && array_key_exists($k, $cfg)) $cfg[$k] = $v;
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
    // ⚠️ 空值一律删行、不留空串：DB 里留一行 v='' 会被 owRdsAllCfg 覆盖掉默认值，
    //    于是后台输入框显示空白、看着像「没配上」（timeout 就这么空过一回），
    //    而代码里其实走的是默认值 —— 存储与显示两套口径，最容易误读。
    //    删行之后「空 = 用默认」就成了唯一口径：输入框显示默认、前端提交空也算用默认。
    DB::run('DELETE FROM plugin_redis_cfg WHERE k=?', [$k]);
    if ($v !== '') DB::run('INSERT INTO plugin_redis_cfg (k, v) VALUES (?,?)', [$k, $v]);
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
    if ($roomId > 0) {
        owRdsCmd(['SADD', owRdsRoomsKey(), (string)$roomId]);
        // 索引 Set 也要有过期时间：不给它 TTL 就会一直攒房间号（只增不减，虽然一天也就几条）。
        // 索引掉了不影响正确性 —— 它只是后台群榜的入口，下一条消息进来会重新 SADD。
        if ($days > 0) owRdsEnsureTtl(owRdsRoomsKey(), $days * 86400);
    }
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
        'auth' => $c['pass'] !== '', 'clients' => 0, 'maxclients' => 0,
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
                elseif (strpos($ln, 'connected_clients:') === 0) $r['clients'] = (int)substr($ln, 18);
                elseif (strpos($ln, 'maxclients:') === 0)     $r['maxclients'] = (int)substr($ln, 11);
            }
        }
        // dbN:keys=12,expires=3,avg_ttl=0 —— 只认目标库那一行
        if (is_string($info) && preg_match('/^db' . $c['db'] . ':keys=(\d+),expires=(\d+)/m', $info, $m)) {
            $r['keys'] = (int)$m[1];
            $r['expires'] = (int)$m[2];
        }
        // ⚠️ Redis 3.0 的 INFO 里没有 maxclients（3.0 只在 CONFIG 里），所以单独问一次；
        //    取不到就保持 0，后台显示「未知」，不假装知道。
        if ($r['maxclients'] === 0) {
            $mc = owRdsRawCmd($fp, ['CONFIG', 'GET', 'maxclients']);
            if (is_array($mc) && count($mc) >= 2 && is_numeric($mc[1])) $r['maxclients'] = (int)$mc[1];
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

/* ============================ 读路径接手 ============================ */
/*
 * 核心 v1.3.155 为「让 Redis 接管读路径」开了 6 处 fire 点。这里的能力分三档，
 * 不是「开了就都接」——**能不能接，取决于核心有没有同时开写入侧**：
 *
 *   ✅ 已接手（下面实现）
 *      · history.rows          → 只用来校准「本群最新消息 id」水位，**不回填**（理由见函数注释）
 *      · poll.hint             → 长轮询探活短路（一轮最多 40 次 SELECT，省得最多的一处）
 *      · online.track / .list  → 在场状态镜像进 Redis，名单整段接手（回填空数组则核心照查库）
 *      · rate.limit            → INCR + EXPIRE 计数；**mail* 桶刻意不接**（理由见函数注释）
 *
 *   ❌ 刻意不接：login.attempt
 *      核心只开了**读**（loginFails / loginLocked / lockSeconds 三处），没开**写**：
 *      失败自增、以及 v1.3.131 那条「上一轮锁定已到期就重新起算」的规则仍在 Sec::loginFail() 里，
 *      成功清零在 Sec::loginOk() 里。于是插件只有两条路：
 *        ① 返回 $fails = -1（未接手）→ 等于没接；
 *        ② 自己按 login.failed / login.after_verify 计数 → 就得把「阈值 login_fail_lock、
 *           锁定分钟 login_lock_minutes、到期重算」这三条**安全规则**复制一份。
 *      而这份副本一旦与核心不一致，错的方向是「锁定失效 = 暴力破解可绕过」，代价不对等。
 *      要真接，请核心先给一个写入侧钩子（如 login.fail_add / login.fail_clear），插件只当计数器。
 */

/** 在场窗口：与核心 online 表的 `last_seen > now-45` 一致（core/chat.php:2059） */
const OW_RDS_ONLINE_SEC = 45;

function owRdsLastKey(int $roomId): string { return owRdsPrefix() . 'last:r' . $roomId; }
function owRdsOnKey(int $roomId): string { return owRdsPrefix() . 'on:r' . $roomId; }
function owRdsOnzKey(int $roomId): string { return owRdsPrefix() . 'onz:r' . $roomId; }

/** 本群最新消息 id 的「水位」（天）
 *  读路径的键与镜像同寿命：镜像过期了，水位跟着过期才不会指到一个已经不在缓存里的位置 */
function owRdsReadTtl(): int
{
    $d = owRdsInt('mirror_days');
    if ($d <= 0) $d = 7;
    if ($d > 365) $d = 365;
    return $d * 86400;
}

/**
 * 校准水位：把「本群最新消息 id」原子地抬到 $id（只增不减）。
 *
 * ⚠️ 必须原子取 max，不能直接 SET：同一群里两个用户几乎同时发言时，两条 after_send 的
 *    SET 可能乱序执行，后写的反而是小 id → 水位被压低到比库里的真实最大 id 还小。
 *    而 poll.hint 的跳过条件正是「水位 == 客户端 sinceId」，水位偏低就可能命中一次
 *    本该跳过的判断，把一条已入库但没下发过的消息永远丢掉。用 Lua 把 GET+比较+SET 做成一步。
 *    脚本不可用时退回两步走：那只是把水位抬得不够高（poll.hint 于是不再短路），方向安全。
 */
function owRdsMarkLast(int $roomId, int $id, int $ttl = 0): void
{
    if ($roomId <= 0 || $id <= 0) return;
    if (owRdsCfg('readpath') !== '1') return;   // 读路径总开关关掉时，水位也就没有维护的必要
    $k = owRdsLastKey($roomId);
    $ex = $ttl > 0 ? $ttl : owRdsReadTtl();
    // ⚠️ TTL 必须与 SET 写在**同一条命令**里：Redis 的 SET 会清掉键原有的 TTL，
    //    而「同一个请求里连发多条消息」时 EXPIRE 被 owRdsEnsureTtl 的「每请求一次」守卫挡住，
    //    于是第 2 条之后的 SET 把过期时间抹掉且没人补 → 水位键永不过期（本机实测 TTL=-1）。
    //    Lua 里 GET + 比较 + SET EX 一次完成，既取到 max 也带上过期时间，没有中间态。
    $r = owRdsCmd(['EVAL',
        "local c = redis.call('GET', KEYS[1]) if (not c) or tonumber(ARGV[1]) > tonumber(c) then redis.call('SET', KEYS[1], ARGV[1], 'EX', ARGV[2]) end return 1",
        '1', $k, (string)$id, (string)$ex]);
    if ($r === null) {
        // 脚本不可用（服务端禁了 EVAL）：退回两步。并发下可能把水位压得不够高 ——
        // 那只会让 poll.hint 不再短路，方向安全；顺序仍是「先 SET（带 EX）再比较」做不到，
        // 所以这里宁可多写一次也不留无 TTL 的键。
        $cur = (int)owRdsCmd(['GET', $k]);
        if ($id > $cur) owRdsCmd(['SET', $k, (string)$id, 'EX', (string)$ex]);
    }
}

/**
 * history.rows —— 核心查完库把**原始行**交过来。
 *
 * 本插件只做一件事：用这批行校准水位。**刻意不回填、也不缓存行**：
 *   · 回填的危险在于这张钩子的触发条件是「核心这次查库没查到」——而「库里没有、缓存里有」
 *     恰恰是**消息已被撤回或被服务器保留期清理**的形态，回填等于把已经消失的内容复活
 *     （撤回是全局生效的，purgeExpired 是每小时真删）。省一次查询不值得换这个。
 *   · 行不落地：面板预览读的是摘要镜像，本来就够用；再存一份原始行只是多一份要同步的副本。
 * 水位校准这一步本身很有用：站点刚启用插件时并没有历史水位，靠「进房间时的那次 history」
 * 就能立刻把水位补上，poll.hint 不必等到下一条新消息才开始生效。
 */
function owRdsCalibrate(array $rows, int $roomId): void
{
    if (!$rows || $roomId <= 0) return;
    $maxId = 0;
    foreach ($rows as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id > $maxId) $maxId = $id;
    }
    if ($maxId > 0) owRdsMarkLast($roomId, $maxId);
}

/**
 * poll.hint 的判定：返回 true = 可以跳过这次探活查询。
 *
 * 只在**水位恰好等于客户端 sinceId** 时才跳过：
 *   · 水位 < sinceId：说明客户端已经看到过水位之后的（没被镜像的）消息 → 不敢跳过；
 *   · 水位 > sinceId：Redis 里有客户端还没拿到的消息 → 必须查库；
 *   · 水位 == sinceId：本群最新一条就是客户端最后看到的那条 → 这段时间确实没有新消息。
 * 键不存在（Redis 重启过 / 从没写过）一律返回 false —— 宁可照查库，也不猜。
 * 这个判据是**自愈**的：镜像漏写过几条时，客户端 sinceId 会由查库推进到真实最大 id，
 * 于是不再相等 → 不再短路；等下一条消息把水位抬到同一个值时，短路自动恢复。
 */
function owRdsPollSkip(int $roomId, int $sinceId): bool
{
    if ($roomId <= 0 || $sinceId <= 0) return false;
    if (owRdsCfg('readpath') !== '1') return false;
    if (owRdsConn() === null) return false;
    $last = owRdsCmd(['GET', owRdsLastKey($roomId)]);
    if ($last === null) return false;                  // 键不存在或读失败：照查库
    return (int)$last === $sinceId;
}

/** online.track —— 把在场状态镜像进 Redis（核心照旧写库，这里只是镜像） */
function owRdsPresenceTrack(array $actor, int $roomId): void
{
    if (owRdsCfg('readpath') !== '1') return;
    if (owRdsConn() === null) return;
    $kind = (string)($actor['kind'] ?? 'none');
    if ($kind !== 'user' && $kind !== 'guest') return;    // 与核心 heartbeat 的 early-return 同口径
    $id = (int)($actor['id'] ?? 0);
    if ($id <= 0) return;
    $token = ($kind === 'user' ? 'u' : 'g') . $id;
    $now = time();
    $hz = owRdsOnKey($roomId);      // Hash：token → 在场行 JSON
    $zz = owRdsOnzKey($roomId);     // ZSET：token → last_seen（排序与过期都用它）
    if (owRdsCmd(['ZADD', $zz, (string)$now, $token]) === null) return;
    owRdsEnsureTtl($zz, OW_RDS_ONLINE_SEC * 2);
    // 字段名与核心 online 表的列名一致，读取时原样交回；游客的 user_id 存空串，
    // 核心那侧写的是 `$r['user_id'] ? (int)$r['user_id'] : null`，空串同样落成 null —— 语义一致。
    $payload = (string)json_encode([
        'user_id' => $kind === 'user' ? $id : 0,
        'guest_id' => $kind === 'guest' ? $id : 0,
        'nickname' => (string)($actor['nickname'] ?? ''),
        'role' => (string)($actor['role'] ?? 'guest'),
        'title' => (string)($actor['title'] ?? ''),
        'avatar' => (string)($actor['avatar'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);
    owRdsCmd(['HSET', $hz, $token, $payload]);
    owRdsEnsureTtl($hz, OW_RDS_ONLINE_SEC * 2);
}

/**
 * online.list —— 整段接手在场名单。
 * 契约（core/chat.php:2052）：回填了行（哪怕一条）核心就不再查库；仍是空数组则照查库。
 * 所以「Redis 里确实一个人都没有」这种情况核心会退回去查库 —— 不会给错答案，只是那次查询省不掉。
 */
function owRdsPresenceList(int $roomId): array
{
    if (owRdsCfg('readpath') !== '1') return [];
    if (owRdsConn() === null) return [];
    $hz = owRdsOnKey($roomId);
    $zz = owRdsOnzKey($roomId);
    // 1) 先清过期成员（ZSET 与 Hash 一起删），否则 Hash 会只增不减
    $cut = time() - OW_RDS_ONLINE_SEC;
    $stale = owRdsCmd(['ZRANGEBYSCORE', $zz, '-inf', '(' . $cut]);
    if (is_array($stale) && $stale) {
        owRdsCmd(array_merge(['ZREM', $zz], array_map('strval', $stale)));
        owRdsCmd(array_merge(['HDEL', $hz], array_map('strval', $stale)));
    }
    // 2) 在场 token 与时间戳（时间戳就是排序依据，别从 Hash 里猜）
    $cut = time() - OW_RDS_ONLINE_SEC;
    $raw = owRdsCmd(['ZRANGEBYSCORE', $zz, '(' . $cut, '+inf', 'WITHSCORES']);
    if (!is_array($raw) || count($raw) < 2) return [];
    $ts = [];
    for ($i = 0; $i + 1 < count($raw); $i += 2) $ts[(string)$raw[$i]] = (int)$raw[$i + 1];
    if (!$ts) return [];
    // 3) 一把取回全部资料（HGETALL 一次拿完，成员再多也不增加往返）
    $all = owRdsCmd(['HGETALL', $hz]);
    if (!is_array($all) || !$all) return [];
    $rows = [];
    for ($i = 0; $i + 1 < count($all); $i += 2) {
        $token = (string)$all[$i];
        if (!isset($ts[$token])) continue;              // 资料还在、索引已过期：跳过
        $d = json_decode((string)$all[$i + 1], true);
        if (!is_array($d)) continue;
        $rows[] = [
            'user_id' => (int)($d['user_id'] ?? 0), 'guest_id' => (int)($d['guest_id'] ?? 0),
            'nickname' => (string)($d['nickname'] ?? ''), 'role' => (string)($d['role'] ?? 'guest'),
            'title' => (string)($d['title'] ?? ''), 'avatar' => (string)($d['avatar'] ?? ''),
            '__ts' => $ts[$token],
        ];
    }
    if (!$rows) return [];
    // 4) 排序口径与核心的 `ORDER BY role='admin' DESC, last_seen DESC` 一致
    usort($rows, function (array $a, array $b) {
        $ax = ($a['role'] === 'admin') ? 0 : 1;
        $bx = ($b['role'] === 'admin') ? 0 : 1;
        if ($ax !== $bx) return $ax <=> $bx;
        return $b['__ts'] <=> $a['__ts'];
    });
    foreach ($rows as $i => $r) unset($rows[$i]['__ts']);
    return array_slice($rows, 0, 300);   // 与核心的 LIMIT 300 对齐
}

/**
 * rate.limit —— 计数搬进 Redis：开窗用 SET NX，窗口内用 INCR。
 *
 * 语义逐条对齐核心（core/security.php:138-145）：
 *   · 新窗口：核心 `DB::upsert(count=1)` 后**直接放行**；这里 SET NX 成功即放行（max=0 时也放行第一条）。
 *   · 窗口内：核心先判 `count >= max` 再自增；这里先 INCR 再判 `n <= max` —— 从 1 起算等价。
 *   · 「速率窗口只读不续期」：这里**绝不**给窗口键续期（INCR 不碰窗口键），
 *     否则被拒之后窗口被顺延，用户永远等不到放行。
 *
 * ⚠️ mail* 桶一律不接：核心的 rateRemaining() 只读库、且只被邮件流程调用
 *    （core/mail.php:176-191 的 mail / mail_mail / mail_ip / mail_browser）。
 *    接手这些桶会让「闸门拒绝你、剩余时间却显示 0 秒」—— 正是核心注释里警告的那种分裂。
 */
function owRdsRateLimit(string &$handled, string $bucket, string $ident, int $window, int $max): void
{
    if (owRdsCfg('ratelimit') !== '1') return;
    if ($bucket === '' || $ident === '' || $window <= 0) return;
    if (strpos($bucket, 'mail') === 0) return;
    if (owRdsConn() === null) return;
    $key = owRdsPrefix() . 'rl:' . $bucket . ':' . md5($ident);
    /*
     * ⚠️ 为什么用 Lua 而不是「SET NX 开窗 + INCR 计数」：
     *   ① SET ... NX 在**键已存在**时返回 nil，而 nil 与「命令失败」在客户端是同一个值 ——
     *      两者混在一起，插件会把「窗口已存在」误判成「Redis 出问题了」，于是每次都退回库路径，
     *      限流等于没接（本机实测：计数键一直停在 1、库里照样有 rate_limits 行）。
     *   ② INCR 与 EXPIRE 分开写就有中间态：进程恰在两步之间挂掉会留下**没有 TTL 的键** ——
     *      计数永不过期 = 这个 ident 被永久限死。放进 Lua 一次做完就没有这个窗口。
     *   语义与核心逐条对齐（core/security.php:138-145）：只有本条把计数从 0 抬到 1 时才设过期，
     *   即「窗口只读不续期」——被拒之后的调用绝不顺延窗口。
     */
    $n = owRdsCmd(['EVAL',
        "local n = redis.call('INCR', KEYS[1]) if n == 1 then redis.call('EXPIRE', KEYS[1], ARGV[1]) end return n",
        '1', $key, (string)$window]);
    if ($n === null) {
        // 脚本不可用：退回两步，并把「设不上过期时间」的键删掉，绝不留下永不重置的计数键
        $n = owRdsCmd(['INCR', $key]);
        if ($n === null) return;
        if ((int)$n === 1) {
            $e = owRdsCmd(['EXPIRE', $key, (string)$window]);
            if ($e === null || (int)$e === 0) owRdsCmd(['DEL', $key]);
        }
    }
    // 与核心一致：窗口内第一条（计数刚被抬到 1）无条件放行，即使 max=0
    $handled = ((int)$n === 1 || (int)$n <= $max) ? '1' : '0';
}

/* ---------------------------- 公开房间列表缓存 ---------------------------- */
/*
 * 为什么值得缓存：Chat::rooms() 的第一句是 `SELECT * FROM rooms WHERE status=1 ORDER BY id`，
 * 而前端进聊天页、切群、建房之后都会打一次 ?action=rooms —— 全站最频繁的查询之一。
 * 关键是**这批行对所有人相同**：按身份的过滤（is_public / min_role / 成员关系 / 能否编辑）
 * 全在后面用 PHP 做。所以分工与 online.list 一致：**插件只提供 rooms 表的原始行，
 * 可见性过滤一律留在核心**，插件不复制任何一条可见性规则。
 *
 * ⚠️ 与 online.list 的关键差别，决定了这里必须有计划任务：
 *    那一处核心是「先 fire、插件回填了就不查库」，插件要真省掉查询就得自己能拿出行；
 *    而本处若改成「核心先查库再把行交给你」的回填点，查询**已经发生**了、省不掉。
 *    所以本插件改用「计划任务保活（60 秒）+ 房间变更时立刻刷新」：正常路径上缓存总是热的，
 *    只有从未热过时才回落给核心查库（那一次不写缓存，交给 60 秒后的计划任务补齐）。
 *    代价是这里有一份与 core/chat.php:290 逐字一致的查询 —— 同一句话抄在 owRdsRoomsQuery()，
 *    并有 TTL 兜底（默认 180 秒）。**改核心那句 SQL 时必须同步改这里**。
 *
 * ⚠️ 缓存前**剥掉 password 列**：本文档的 Redis 就在本机、默认还没有密码，
 *    房间密码的哈希没必要在另一个存储里多存一份。剥掉是安全的：
 *      · Chat::rooms() 的输出数组本来就不含 password；
 *      · 真正校验密码的 checkRoomPassword() 走 Chat::room() 的独立查询，与这份缓存无关；
 *      · canEnter() 只读 is_public / type / min_role / owner_id，不需要 password。
 */

function owRdsRoomsListKey(): string { return owRdsPrefix() . 'roomslist'; }

/** 与 core/chat.php:290 逐字一致的查询 —— **改核心那句时必须同步改这里** */
function owRdsRoomsQuery(): array
{
    try {
        return DB::all('SELECT * FROM rooms WHERE status=1 ORDER BY id');
    } catch (Throwable $e) { return []; }
}

function owRdsRoomsTtl(): int
{
    $t = owRdsInt('rooms_ttl');
    if ($t < 30) $t = 30;
    if ($t > 3600) $t = 3600;
    return $t;
}

/** 写入缓存（剥 password）；TTL 兜底，最坏情况只是这一段时间内看到旧列表 */
function owRdsRoomsStore(array $rows): bool
{
    if (!$rows || owRdsCfg('rooms') !== '1' || owRdsConn() === null) return false;
    $clean = [];
    foreach ($rows as $r) {
        if (is_array($r) && isset($r['id'])) { unset($r['password']); $clean[] = $r; }
    }
    if (!$clean) return false;
    $json = (string)json_encode($clean, JSON_UNESCAPED_UNICODE);
    return owRdsCmd(['SET', owRdsRoomsListKey(), $json, 'EX', (string)owRdsRoomsTtl()]) !== null;
}

/** 取缓存行；null = 没热过（交回核心查库，不由插件代查） */
function owRdsRoomsServe(): ?array
{
    if (owRdsCfg('rooms') !== '1' || owRdsConn() === null) return null;
    $raw = owRdsCmd(['GET', owRdsRoomsListKey()]);
    if (!is_string($raw) || $raw === '') return null;
    $rows = json_decode($raw, true);
    return (is_array($rows) && $rows) ? $rows : null;
}

/** 房间表变了：立刻失效并重刷。这条路径不在热路上，多一次查询无所谓 */
function owRdsRoomsRefresh(): void
{
    if (owRdsCfg('rooms') !== '1' || owRdsConn() === null) return;
    owRdsCmd(['DEL', owRdsRoomsListKey()]);
    owRdsRoomsStore(owRdsRoomsQuery());
}

/** 后台展示用：缓存热不热、几行、还剩多久 */
function owRdsRoomsState(): array
{
    $on = owRdsCfg('rooms') === '1';
    if (owRdsConn() === null) return ['on' => $on, 'warm' => false, 'rows' => 0, 'ttl' => -1];
    $ttl = owRdsCmd(['TTL', owRdsRoomsListKey()]);
    $n = 0;
    $raw = owRdsCmd(['GET', owRdsRoomsListKey()]);
    if (is_string($raw)) { $d = json_decode($raw, true); if (is_array($d)) $n = count($d); }
    return ['on' => $on, 'warm' => $n > 0, 'rows' => $n, 'ttl' => is_int($ttl) ? $ttl : -1];
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
        // ⚠️ 顺序有意义：**先宣告水位，再写镜像**。
        //    poll.hint 只在「水位 == 客户端 sinceId」时跳过探活查询，所以水位必须「宁可早说、不可晚说」：
        //    先宣告 → 即使后面镜像写失败，水位也已经是新 id，客户端 sinceId 追不上它 → 不会误跳过；
        //    反过来先镜像后宣告，中间失败就留下「镜像里有、水位没有」的窗口，
        //    此时水位仍等于客户端的 sinceId，一次本该发生的查询会被跳过 —— 那条消息就卡住不显示了。
        owRdsMarkLast($roomId, (int)$id);
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

/* ---------- 读路径钩子（核心 v1.3.155 的 6 处 fire 点，本插件接 5 个、刻意不接 login.attempt） ---------- */

/**
 * history.rows —— 核心查完库把原始行交过来。
 * 本插件只用它校准水位；**不回填 $rows**（为什么，见 owRdsCalibrate 的注释）。
 */
Plugin::on('history.rows', function (&$rows, $roomId, $beforeId, $limit, $fromId, $actor) {
    try {
        owRdsCalibrate(is_array($rows) ? $rows : [], (int)$roomId);
    } catch (Throwable $e) { /* 旁路失败绝不冒泡 */ }
});

/**
 * poll.hint —— 确认「这段时间没有新消息」就把 $hit 置 '1'，核心于是跳过这次探活查询。
 * ⚠️ 语义是「跳过查询」，不是「有新消息」——为空串时核心行为与从前完全一致。
 */
Plugin::on('poll.hint', function (&$hit, $roomId, $sinceId) {
    try {
        if (owRdsPollSkip((int)$roomId, (int)$sinceId)) $hit = '1';
    } catch (Throwable $e) { /* 同上 */ }
});

/** online.track —— 在场状态镜像到 Redis（通知型：核心照旧写 online 表） */
Plugin::on('online.track', function ($actor, $roomId) {
    try {
        owRdsPresenceTrack(is_array($actor) ? $actor : [], (int)$roomId);
    } catch (Throwable $e) { /* 同上 */ }
});

/** online.list —— 回填在场名单；回填空数组时核心照旧查库（不会给错答案） */
Plugin::on('online.list', function (&$rows, $roomId) {
    try {
        $got = owRdsPresenceList((int)$roomId);
        if ($got) $rows = $got;
    } catch (Throwable $e) { /* 同上 */ }
});

/** rate.limit —— 计数搬进 Redis（INCR + EXPIRE）；mail* 桶在里面被排除 */
Plugin::on('rate.limit', function (&$handled, $bucket, $ident, $window, $max) {
    try {
        owRdsRateLimit($handled, (string)$bucket, (string)$ident, (int)$window, (int)$max);
    } catch (Throwable $e) { /* 同上 */ }
});

/* ---------- 公开房间列表（缓存 rooms 表的原始行，可见性过滤留给核心） ---------- */

/**
 * rooms.rows —— 公开房间列表。核心 **v1.3.159 起**在 Chat::rooms 里 fire（那句 SELECT 之前）：
 *
 *     $list = [];
 *     Plugin::fire('rooms.rows', [&$list, $actor]);
 *     if (!$list) $list = DB::all('SELECT * FROM rooms WHERE status=1 ORDER BY id');
 *
 * 契约与 online.list 完全一致：回填了行（哪怕只有一行）核心就不再查库；仍是空数组则照查库。
 * 缓存未热过时本处理器什么都不做 → 核心照旧查库（不会给错答案，只是那一次查询省不掉）。
 * ⚠️ 处理器刻意写得防御：参数形状不对就什么都不做（宁可回落查库，也不能把房间列表弄坏）。
 */
Plugin::on('rooms.rows', function (&$list = null, $actor = null) {
    try {
        if (!is_array($list)) return;                 // 形状不符合预期：不碰
        if ($list) return;                            // 已经有行了：不覆盖
        $rows = owRdsRoomsServe();
        if ($rows) $list = $rows;
    } catch (Throwable $e) { /* 旁路失败绝不冒泡 */ }
});

/** 房间表被改（建房 / 改名改简介改头像 / 回收站撤销）→ 立刻失效并重刷 */
function owRdsOnRoomChanged(): void
{
    try { owRdsRoomsRefresh(); } catch (Throwable $e) { /* 同上 */ }
}
Plugin::on('room.created', 'owRdsOnRoomChanged');
Plugin::on('room.after_update', 'owRdsOnRoomChanged');
Plugin::on('room.restored', 'owRdsOnRoomChanged');

/* v1.5.22：后台「刷新数据缓存」/ Cache::clear('all') 时，一并清本插件前缀下的键。
   只响应 all —— 核心设置保存走 settings 区域，不该清空在线/限流这些临时数据。 */
Plugin::on('cache.clear', function (string $area = 'all'): void {
    if ($area !== 'all') return;
    try { owRdsFlushPrefix(); } catch (Throwable $e) { /* Redis 不可用：文件缓存已经刷新 */ }
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

/**
 * 每分钟把公开房间列表刷进缓存 —— 这是「省掉那句 SELECT」的前提：
 * 房间表很少变（改变时另有 room.* 钩子立刻重刷），所以正常路径上缓存总是热的，
 * 只有 Redis 刚重启 / 缓存过期且此刻没有房间变更时，才会回落给核心查库一次。
 * 间隔 60 秒：这是一条 SELECT，一分钟一次可以忽略；TTL 默认 180 秒，留两次容错。
 */
Plugin::cron('rds_rooms', 60, function () {
    try {
        if (owRdsCfg('enable') !== '1') return;
        if (owRdsCfg('rooms') !== '1') return '房间列表缓存已关闭（rooms=0）';
        $rows = owRdsRoomsQuery();
        if (!$rows) return '房间表为空，未写缓存';
        return owRdsRoomsStore($rows) ? ('已刷新 ' . count($rows) . ' 行房间列表缓存') : '写入失败（Redis 不可用？）';
    } catch (Throwable $e) { return '刷新异常：' . $e->getMessage(); }
}, '刷新公开房间列表缓存（每分钟）');

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
        'rooms_cache' => owRdsRoomsState(),
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
        . '<p class="ow-admin-desc">消息与活跃数据旁路镜像进 Redis，另有连接体检、每日计数落库，'
        . '并从核心 v1.3.155 起接手部分读路径（长轮询探活短路、在场名单、限流计数 —— 逐条状态见页面底部）。'
        . '⚠️ 读路径只接「核心同时开了写入侧」的那几处：<strong>消息历史本身仍走数据库</strong>'
        . '（history.rows 只用来校准水位、不回填），<strong>login.attempt 刻意不接</strong>。'
        . '一句话：这些改动是省查询、不是换存储 —— 数据库始终是唯一真源。</p>';

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

    // 读路径接手状态：核心 v1.3.155 的 6 处 fire 点逐条说清「接没接、为什么」
    // （占位符：名称 / 状态徽标 / 位置 / 'on'|'off' / 说明）
    $rp = [
        ['history.rows', '已接', 'core/chat.php:998', 'on',
            '只用核心这次查到的行校准「本群最新消息 id」水位；**不回填 $rows** —— 这条钩子的触发条件是「核心这次没查到行」，'
            . '而「库里没有、缓存里有」恰恰是消息被撤回、或被服务器保留期清理的形态，回填等于把已经消失的内容复活。'],
        ['poll.hint', '已接', 'core/chat.php:1081', 'on',
            '长轮询探活短路：只有「水位恰好等于客户端 sinceId」时才跳过这次查询（一轮长轮询最多 40 次 SELECT，省得最多的一处）。'
            . '水位读不到、偏大、偏小都照查库 —— 判据只会保守，不会猜。'],
        ['online.track + online.list', '已接', 'core/chat.php:2038 / 2057', 'on',
            '在场状态镜像进 Redis（窗口 45 秒，与核心 online 表一致）；online.list 回填成功则核心不再查库，'
            . '回填空数组则核心照旧查库 —— 所以「Redis 里确实没人」不会给错答案，只是那次查询省不掉。'],
        ['rate.limit', '已接（mail* 桶除外）', 'core/security.php:136', 'on',
            'INCR + EXPIRE 计数，不再写 rate_limits 表，并保住核心「窗口只读不续期」的语义（被拒之后不顺延窗口）。'
            . '⚠️ **mail* 桶刻意不接**：核心的 rateRemaining() 只读库、且只被邮件流程调用（core/mail.php:176-191），'
            . '接手这些桶会变成「闸门拒绝你、剩余时间却显示 0 秒」。'],
        ['login.attempt', '刻意不接', 'core/security.php:173 / 182 / 227', 'off',
            '核心只开了**读**（loginFails / loginLocked / lockSeconds），没开**写**：失败自增与「上一轮锁定到期就重新起算」'
            . '仍在 Sec::loginFail() 里、成功清零在 Sec::loginOk() 里。插件若自己计数，就得把阈值、锁定分钟数、到期重算'
            . '这三条**安全规则**复制一份，而错的方向是「锁定失效 = 暴力破解可绕过」。要接，请核心先给一个写入侧钩子'
            . '（如 login.fail_add / login.fail_clear），插件只当计数器。'],
        ['rooms.rows', '已接', 'core/chat.php:290 那句 SELECT 之前', 'on',
            '公开房间列表是**全站读得最频繁的一处**（进聊天页 / 切群 / 建房后都要打一次），而那批行对所有人都一样'
            . '（按身份的过滤在后面用 PHP 做）→ 插件只缓存**原始行**、可见性过滤仍留给核心。'
            . '**核心已在 v1.3.159 补上那两行**（`$list = []; Plugin::fire(\'rooms.rows\', [&$list, $actor]);'
            . ' if (!$list) $list = DB::all(...)`），缓存热时这次查询就省掉了。'
            . '同一版还把「删群 / 封禁」两处的缓存失效补齐了（admin.php 现在会 fire room.after_update）——'
            . '在那之前只能等 TTL 过期，最坏 60 秒内还列着已删/已封的群。'],
    ];
    $h .= '<div class="ow-card"><h3 style="margin:0 0 10px">读路径接手（核心 v1.3.155 / v1.3.159 的 6 处 fire 点，已全部就位）</h3>'
        . '<p style="font-size:12px">下面每一处都标注了接手状态与理由。<strong>能不能接取决于核心有没有同时开写入侧</strong>：'
        . '只开读不开写的地方，插件要么返回「未接手」（等于没接），要么就得把核心的规则复制一份 —— 后者一旦与核心不一致，'
        . '代价往往是权限或安全出问题，所以宁可不接（目前只有 login.attempt 属于这种）。</p>'
        . '<div class="ow-form-row">'
        . $sel('readpath', '读路径总开关', ['1' => '开启', '0' => '关闭'],
            '关闭后 history.rows / poll.hint / online.* 一律不接手，完整恢复核心原行为')
        . $sel('ratelimit', '限流计数', ['1' => '放进 Redis', '0' => '留给核心（写 rate_limits 表）'],
            '只作用于非 mail 桶；出问题可一键关回去')
        . $sel('rooms', '房间列表缓存', ['1' => '开启', '0' => '关闭'],
            '缓存 rooms 表的原始行（剥掉 password 列）；核心 v1.3.159 起有 rooms.rows 火点，缓存热时直接省下这次查询')
        . $f('rooms_ttl', '房间列表缓存寿命（秒）', '30~3600；另有每分钟的计划任务保活')
        . '</div>';
    // 说明里用 **…** 标重点：先 Sec::e() 转义再换成 <strong>（转义不会碰星号，顺序反了就是 XSS）
    $em = function (string $s): string {
        return (string)preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', Sec::e($s));
    };
    foreach ($rp as $t) {
        $stCls = ['on' => 'is-on', 'wait' => 'is-wait', 'off' => 'is-off'][$t[3]] ?? 'is-off';
        $h .= '<div class="ow-rds-todo"><div class="ow-rds-todo-h"><b>' . Sec::e($t[0]) . '</b>'
            . '<em class="ow-rds-st ' . $stCls . '">' . Sec::e($t[1]) . '</em>'
            . '<span>' . Sec::e($t[2]) . '</span></div>'
            . '<div class="ow-rds-todo-d">' . $em($t[4]) . '</div></div>';
    }
    $h .= '</div>';

    return $h;
});

/* ============================ 资源 ============================ */

Plugin::asset('css', 'redis/style.css');
Plugin::asset('js', 'redis/admin.js', 'admin');
