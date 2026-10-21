<?php
/**
 * WS 实时层 · 协议库与服务核心（P1）
 *
 * 定位（与架构文件 c-ws-realtime-plan 对应）：
 *   - 零依赖手写：HTTP 握手 / RFC6455 帧编解码 / stream_select 事件循环，全部 PHP 标准库；
 *   - 复用口径，不复制规则：心跳写在线状态直接调 Chat::heartbeat()（online 表、45 秒窗口、
 *     online.track 插件钩子全部照旧）；身份装配直接调 Auth::actor()；
 *   - P1 只承载：连接保活心跳 + 在线状态（上线/下线事件以 wake 帧「通知前端去刷既有接口」）。
 *     不推消息内容（P2）、不碰发送路径（P3）。
 *   - wake-only 设计的原因：成员名单与可见性布尔仍由 room_members 服务端现算（单一来源），
 *     推送只做「触发器」——前端收到 wake 后调既有的 OwChat.loadMembers()。这样可见性规则
 *     零复制、WS 与长轮询两态行为天然一致，回退时不会有两套口径。
 *
 * 已知限制（刻意取舍，勿当缺陷修）：
 *   - 本进程只监听 127.0.0.1，由 nginx /ws 反代。因此它看不到真实客户端 IP：
 *     online 行的 ip 列会写成 '0.0.0.0'（Sec::ip() 在 CLI 的取值，来自 REMOTE_ADDR 的诚实哨兵），
 *     **不信任任何可伪造头**（访客 IP 规范）。IP 审计以 HTTP 路径的 security_logs 为准。
 *   - 发送路径不通、消息推送不通——这是 P1 的范围裁剪，不是遗漏。
 *   - 单帧上限 64KB、不支持分片：客户端只发微小的 JSON 控制帧（hb/sub）。
 *
 * P2 增补（消息推送 + 控制口，2026-10-16）：
 *   - 订阅即补齐：sub / dmsub 帧带 since → 入订阅时先用 roomFetchSince / dmFetchSince
 *     把缺口补推给该座位（重连不丢消息就靠它）；此后由 watcher / nudge 推增量。
 *   - watcher：每 tick 对「有订阅者的房间」先做 1 条索引探询，命中才逐座位拉包；
 *     DM 座位数量少，直接逐座位单查。推送内容一律走 Chat::roomFetchSince / dmFetchSince
 *     ——与长轮询逐字同口径，这里不新写任何可见性规则（每条消息都按最新数据鉴权）。
 *   - nudge 控制口：同端口非 Upgrade 的 POST /ctl/nudge（X-Ow-Ctl 密钥、只绑本机），
 *     只是加速器；丢了由 watcher 兜底，最多慢 1 秒。
 *   - 撤回推送：{recall,id} 直接扇出给该房间/私聊对的订阅者（行已删，无需查库）。
 *   - 票据优先走 Sec-WebSocket-Protocol: owt.<ticket>（URL 不再带票）；兼容 URL ?ticket=。
 *   - cronTick：每 tick 驱动 Plugin::cronTick()（长轮询流量下降后的计划任务承接）。
 *
 * P3 增补（发送迁移：send 帧回环，2026-10-16）：
 *   - send 帧 → 内部 HTTPS 回环到站点（index.php?action=ws_send → Chat::send()，
 *     与 HTTP 发送同一函数同一表）。**隔离的本质**：插件同步长任务（AI 应答等）跑在
 *     php-cgi 池里，本进程不执行任何发送逻辑——事件循环只「转发 + 等响应」，
 *     长任务不会阻塞任何连接（这是发送不敢直接放进事件循环的原因）。
 *   - 保序：同一 actor 在途 1 条 + 队列 ≤8——落库顺序=用户输入顺序（HTTP 时代无此保证）。
 *   - 出站请求由事件循环异步收（连接 3s / 读超时 ws_send_timeout、默认 8s）；
 *     结果以 {t:'sent', cid, ok, msg_row?} 回帧，前端据此回显/提示重试。
 *   - 真实客户端 IP：nginx /ws 段 proxy_set_header X-Ow-Peer 覆盖注入 → 握手时存
 *     seat.peerIp → 内部请求带 peer_ip → 站点侧仅双校验通过后覆盖 REMOTE_ADDR。
 *   - 密钥：send_key（daemon→web 方向，随 ws-ctl.key 下发；与 web→daemon 的 ctl key 互不推导）。
 */
declare(strict_types=1);

class RealtimeWs
{
    /** 单帧上限：客户端只会发 hb/sub 这类小 JSON，64KB 足够且能挡内存攻击 */
    public const MAX_FRAME = 65536;
    /** 单连接累积缓冲上限（握手 + 帧），超了直接按协议错误断开 */
    public const MAX_BUFFER = 131072;
    /** 握手阶段超时（秒）：连上不发完整 HTTP 请求就断开 */
    public const HANDSHAKE_TIMEOUT = 10;
    /** 空闲超时（秒）：这么久没收到任何帧判死。客户端心跳 25s 一发，足够容两次丢失 */
    public const IDLE_TIMEOUT = 60;
    /** 总席位上限与单 actor 上限（多标签页允许，但别开成连接农场） */
    public const MAX_SEATS = 2000;
    public const MAX_SEATS_PER_ACTOR = 8;
    /** 同一房间的 wake 合帧窗口（秒）：连上/断开高峰时避免一人一帧扇出 */
    public const WAKE_DELAY = 0.25;
    /** 过期票据文件清扫间隔（秒） */
    public const TICKET_SWEEP = 120;
    /** 每连接令牌桶：容量（突发帧数）与回填速率（帧/秒）。正常客户端 25s 一次 hb、
        切群一次 sub，远低于此；狂发帧者在这里先被 close 1008（不做的话，快速交替
        sub 会造成心跳写库放大——安全清单里的「按连接而非按请求限流」）。 */
    public const TB_CAPACITY = 10;
    public const TB_REFILL = 5.0;
    /** actor 快照重装间隔（秒）：轻量「重认证」——权限过滤在每条消息扇出时实时做，
        快照只影响昵称/角色显示与 roomAccessOk 的 role 判断，100 秒内收敛即可 */
    public const ACTOR_REFRESH = 100;

    // ---------- P3（发送迁移）：内部通道 ----------
    /** 内部通道连接超时（秒）：固定值——本机回环，3 秒连不上即异常 */
    public const SEND_CONNECT_TIMEOUT = 3;
    /** 读超时缺省值（秒）：DB setting ws_send_timeout 覆盖（连接超时固定 3s 不变） */
    public const SEND_DEFAULT_TIMEOUT = 8;
    /** 全站在途上限缺省值：DB setting ws_send_max_inflight 覆盖。与 php-cgi 池进程数匹配（×1.5） */
    public const SEND_DEFAULT_MAX = 16;
    /** 每身份（actor）发送排队上限：在途 1 + 队列 8 —— 保序 + 防连点堆积 */
    public const SEND_QUEUE_PER_ACTOR = 8;

    /** @var array<int,array> 席位表：fd 整数 => seat */
    private static array $seats = [];
    /** @var array<int,float> 房间 => flush 截止时间（microtime） */
    private static array $pendingWake = [];
    /** @var float 过期票据上次清扫时间 */
    private static float $lastSweep = 0.0;
    /** @var bool 收到 SIGHUP/TERM 后的退出标志（无 pcntl 的平台永远为 false） */
    private static bool $stop = false;
    /** @var string 票据目录（data/ws-tickets） */
    private static string $ticketsDir = '';
    /** @var string 控制口共享密钥（启动时随机生成；随端口一起写入 data/ws-ctl.key 供 web 侧读） */
    private static string $ctlKey = '';
    /** @var string 反向共享密钥（P3：daemon→web 内部发送通道的凭证；与 ctlKey 同文件下发） */
    private static string $sendKey = '';
    /** @var array<int,int> 房间 => 已扫描分发水位（max id）。只用于「要不要探询」；正确性由座位级 since 保证 */
    private static array $wmRoom = [];
    /** @var array<int,array> P3 出站请求表：fd => ['fp','seatFp','cid','bornAt','timeout','buf'] */
    private static array $outgoing = [];
    /** @var array<string,int> P3 计数（区间统计：周期打印后归零） */
    private static array $sendStats = ['ok' => 0, 'fail' => 0, 'timeout' => 0, 'reject' => 0];
    /** @var int P3 上次打印发送统计的时间戳 */
    private static int $lastStatsAt = 0;

    // ================================================================ 主循环

    /**
     * 启动服务。只绑 127.0.0.1（外网由 nginx /ws 反代进来），阻塞直到退出信号。
     */
    public static function run(int $port, string $dataDir): void
    {
        self::$ticketsDir = rtrim($dataDir, '/\\') . '/ws-tickets';
        @mkdir(self::$ticketsDir, 0775, true);

        // 控制口密钥：随机 16 字节，随端口一起写 data/ws-ctl.key（web 侧 RealtimePush 读它）。
        // 重启会换新密钥——web 每次现读文件，天然对齐；daemon 不在时文件也不致命（连接被拒即可）。
        // P3：send_key 是**反方向**（daemon→web 内部发送通道）的共享密钥——web 侧 ws_send
        // 分支校验它。两个方向两把钥匙（互不推导），同文件下发；重启轮换、web 现读现用。
        self::$ctlKey = bin2hex(random_bytes(16));
        self::$sendKey = bin2hex(random_bytes(16));
        @file_put_contents(rtrim($dataDir, '/\\') . '/ws-ctl.key',
            (string)json_encode(['port' => $port, 'key' => self::$ctlKey, 'send_key' => self::$sendKey]));

        $srv = @stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
        if (!$srv) {
            throw new RuntimeException("端口 {$port} 监听失败（{$errno} {$errstr}）：确认未被占用、端口在 1~65535");
        }
        stream_set_blocking($srv, false);

        // Linux 生产用 PM2 守护；PM2 停进程时先发 SIGTERM —— 有 pcntl 就优雅退出
        // （给所有席位发 close 再关监听，客户端能立刻进入退避重连而不是等心跳超时）。
        // Windows 的 PHP CLI 没有 pcntl：直接跳过，PM2 的 SIGKILL 也能被客户端重连兜住。
        if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $stop = static function (): void { self::$stop = true; };
            pcntl_signal(SIGTERM, $stop);
            pcntl_signal(SIGINT, $stop);
        }

        self::log("WS 服务已启动：ws://127.0.0.1:{$port}（票据目录 " . self::$ticketsDir . "）");

        while (!self::$stop) {
            $read = [$srv];
            foreach (self::$seats as $seat) $read[] = $seat['fp'];
            foreach (self::$outgoing as $o) $read[] = $o['fp'];   // P3：出站请求也等可读
            $write = null;
            $except = null;

            // select 超时：有合帧任务就掐着 flush 时点醒来，否则 1 秒一 tick
            $timeout = 1.0;
            $now = microtime(true);
            foreach (self::$pendingWake as $at) $timeout = min($timeout, max(0.01, $at - $now));
            $sec = (int)$timeout;
            $usec = (int)(($timeout - $sec) * 1e6);

            if (@stream_select($read, $write, $except, $sec, $usec) === false) {
                usleep(250000);   // 被信号打断等：别空转
                continue;
            }
            if (self::$stop) break;

            // 新连接
            if (in_array($srv, $read, true)) {
                while (($fp = @stream_socket_accept($srv, 0)) !== false) {
                    stream_set_blocking($fp, false);
                    self::$seats[(int)$fp] = [
                        'fp'     => $fp,
                        'peer'   => (string)@stream_socket_get_name($fp, true),
                        'phase'  => 'handshake',
                        'buf'    => '',
                        'bornAt' => time(),   // 连接建立时刻（握手/控制口超时用）
                        'lastAt' => time(),
                        'token'  => null,
                        'actor'  => null,
                        'actorAt' => 0,       // actor 快照装载时间（P2：100s 重装一次）
                        'room'   => 0,
                        'since'  => 0,        // 本座位在房间里的已推水位（P2）
                        'dms'    => [],       // peer => since 映射（P2 私聊订阅，多会话累积）
                        'saidBye' => false,
                        'ctlLen' => 0,        // 控制口：待收 body 长度
                        'ctlAuth' => false,   // 控制口：密钥是否通过
                        'peerIp' => '',       // P3：nginx 覆盖注入的真实客户端 IP（X-Ow-Peer，可能为空）
                        'siteHost' => '',     // P3：握手 Host（site_url 未配置时兜底推导站点地址）
                        'sendBusy' => false,  // P3：发送在途（每 actor 串行，保序）
                        'sendQueue' => [],    // P3：发送排队帧（上限 SEND_QUEUE_PER_ACTOR）
                        // 令牌桶初值：满桶开连，tbAt=0 表示「首次用到时按零间隔回填」
                        'tb'     => self::TB_CAPACITY,
                        'tbAt'   => 0.0,
                    ];
                }
            }

            // 已有连接可读（座位 or P3 出站请求）
            foreach ($read as $fp) {
                if ($fp === $srv) continue;
                if (isset(self::$seats[(int)$fp])) self::readAvailable(self::$seats[(int)$fp]);
                elseif (isset(self::$outgoing[(int)$fp])) self::readOutgoing((int)$fp);
            }

            self::tick();
        }

        // 退出：礼貌告知全部客户端（1001 = 服务端主动离开），然后关闭监听
        foreach (self::$seats as $seat) self::closeSeat($seat, 1001, 'server shutdown');
        @fclose($srv);
        self::log('WS 服务已退出');
    }

    // ================================================================ 读与握手

    private static function readAvailable(array $seat): void
    {
        $chunk = @fread($seat['fp'], 8192);
        if ($chunk === false || ($chunk === '' && feof($seat['fp']))) {
            self::closeSeat($seat, 1006, 'peer closed');
            return;
        }
        if ($chunk === '') return;   // 非阻塞下暂时无数据

        $seat['buf'] .= $chunk;
        if (strlen($seat['buf']) > self::MAX_BUFFER) {
            self::closeSeat($seat, 1009, 'buffer overflow');
            return;
        }
        // 席位数组是按值存的快照，回写缓冲区可避免每次读写互踩
        self::$seats[(int)$seat['fp']] = $seat;

        if ($seat['phase'] === 'handshake') self::tryHandshake($seat);
        elseif ($seat['phase'] === 'ctlbody') self::tryCtlBody($seat);
        else self::parseFrames($seat);
    }

    /** 解析并完成 HTTP Upgrade 握手；失败则回 4xx 后断开 */
    private static function tryHandshake(array $seat): void
    {
        $pos = strpos($seat['buf'], "\r\n\r\n");
        if ($pos === false) {
            if (time() - (int)$seat['bornAt'] > self::HANDSHAKE_TIMEOUT) self::closeSeat($seat, 1008, 'handshake timeout');
            return;
        }
        $head = substr($seat['buf'], 0, $pos);
        $lines = explode("\r\n", $head);
        $requestLine = (string)array_shift($lines);

        // GET → WebSocket 升级；POST /ctl/* → 控制口（PHP→daemon nudge）
        if (!preg_match('#^(GET|POST)\s+(\S+)\s+HTTP/1\.[01]$#', $requestLine, $m)) {
            self::httpReject($seat, 400);
            return;
        }
        $method = $m[1];
        $target = $m[2];
        $headers = [];
        foreach ($lines as $line) {
            $p = strpos($line, ':');
            if ($p !== false) $headers[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
        }
        // 头之后的剩余字节：对 WS 是可能的首帧，对控制口是 body 的开头——统一存进缓冲
        $rest = substr($seat['buf'], $pos + 4);

        // ---------- 控制口（只绑本机 + X-Ow-Ctl 密钥；body 可能分多次到达）----------
        if ($method === 'POST' && strpos($target, '/ctl/') === 0) {
            $live = self::$seats[(int)$seat['fp']] ?? null;
            if ($live === null) return;
            $live['phase']   = 'ctlbody';
            $live['buf']     = $rest;
            $live['ctlLen']  = min(max((int)($headers['content-length'] ?? 0), 0), 4096);
            $live['ctlAuth'] = (self::$ctlKey !== ''
                && strpos($target, '/ctl/nudge') === 0
                && hash_equals(self::$ctlKey, (string)($headers['x-ow-ctl'] ?? '')));
            self::$seats[(int)$seat['fp']] = $live;
            self::tryCtlBody($live);
            return;
        }
        if ($method !== 'GET') {
            self::httpReject($seat, 400);
            return;
        }
        $key = (string)($headers['sec-websocket-key'] ?? '');
        $upgradeOk = stripos((string)($headers['upgrade'] ?? ''), 'websocket') !== false
                  && stripos((string)($headers['connection'] ?? ''), 'upgrade') !== false;
        if ($key === '' || !$upgradeOk || ($headers['sec-websocket-version'] ?? '') !== '13') {
            self::httpReject($seat, 400);
            return;
        }
        // P3：真实客户端 IP —— nginx /ws 段 proxy_set_header 覆盖注入（客户端伪造的同名头会被
        // 覆盖语义顶掉）。只作座位属性保存，仅在内部发送通道回传给站点；公开 HTTP 路径不读它。
        // 非法的值直接丢弃（不进入后续任何路径）。
        $peerIp = (string)($headers['x-ow-peer'] ?? '');
        if ($peerIp !== '' && !filter_var($peerIp, FILTER_VALIDATE_IP)) $peerIp = '';
        // P3：握手 Host（nginx 原样透传用户访问的域名/端口）——site_url 未配置时作发送回环的
        // 站点地址兜底。格式校验与 ow_site_url 的主机头防注入同款；非法值丢弃。
        $siteHost = (string)($headers['host'] ?? '');
        if ($siteHost !== '' && !preg_match('/^[a-zA-Z0-9._\[\]:-]+$/', $siteHost)) $siteHost = '';

        // CSWSH 防御纵深（安全清单）：浏览器发起 WS 必带 Origin —— 有 Origin 就必须与 Host
        // 一致（nginx 反代下 Host = $host 原样转发），不一致直接 403。
        // 非浏览器客户端通常不发 Origin：CSWSH 是浏览器攻击，缺席 Origin 不构成该攻击面，
        // 放行（别收紧成「必须有 Origin」，否则 CLI/自测客户端全被误杀——注释防误改）。
        $origin = (string)($headers['origin'] ?? '');
        if ($origin !== '') {
            $oHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
            $hHost = strtolower(explode(':', (string)($headers['host'] ?? ''))[0]);
            if ($oHost === '' || $hHost === '' || $oHost !== $hHost) {
                self::httpReject($seat, 403, 'origin mismatch');
                return;
            }
        }

        // 票据（P2）：优先 Sec-WebSocket-Protocol 里的 'owt.<ticket>'（URL 不再带票）；
        // 兼容 URL ?ticket=（自查脚本 / 极旧页面）。⚠️ 客户端提供子协议时，服务端**必须回选
        // 其中一个**，否则浏览器按规范断开——所以前端要同时给 'owt' 与 'owt.<ticket>' 两项，
        // 这里回选 'owt'（票据本身不回显，别让它出现在响应头里）。
        $pickProto = '';
        $token = '';
        foreach (explode(',', (string)($headers['sec-websocket-protocol'] ?? '')) as $proto) {
            $proto = trim($proto);
            if ($proto === 'owt') $pickProto = 'owt';
            elseif (strpos($proto, 'owt.') === 0 && strlen($proto) > 4) $token = substr($proto, 4);
        }
        if ($token === '') {
            $query = (string)parse_url($target, PHP_URL_QUERY);
            parse_str($query, $q);
            $token = (string)($q['ticket'] ?? '');
        }
        // 票据一次性、60 秒时效，由 index.php 的 ws_ticket 动作签发
        $ids = self::consumeTicket($token);
        if ($ids === null) {
            self::httpReject($seat, 403);
            return;
        }
        $actor = self::buildActor($ids[0], $ids[1]);
        if ($actor === null) {
            self::httpReject($seat, 403);
            return;
        }
        $tokenKey = ($actor['kind'] === 'user' ? 'u' : 'g') . $actor['id'];

        if (count(self::$seats) > self::MAX_SEATS) {
            self::httpReject($seat, 503, 'busy');
            return;
        }
        $same = 0;
        foreach (self::$seats as $s) if (($s['token'] ?? null) === $tokenKey) $same++;
        if ($same >= self::MAX_SEATS_PER_ACTOR) {
            self::httpReject($seat, 403, 'too many connections');
            return;
        }

        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $protoHdr = $pickProto !== '' ? "Sec-WebSocket-Protocol: {$pickProto}\r\n" : '';
        @fwrite($seat['fp'], "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n{$protoHdr}Sec-WebSocket-Accept: $accept\r\n\r\n");

        $seat = self::$seats[(int)$seat['fp']];
        $seat['phase'] = 'open';
        $seat['token'] = $tokenKey;
        $seat['actor'] = $actor;
        $seat['actorAt'] = time();
        $seat['peerIp'] = $peerIp;
        $seat['siteHost'] = $siteHost;
        $seat['buf'] = $rest;   // 握手的余量可能是首帧，别丢
        $seat['lastAt'] = time();
        self::$seats[(int)$seat['fp']] = $seat;

        self::sendJson($seat, ['t' => 'ready', 'window' => self::IDLE_TIMEOUT]);
        // P3：X-Ow-Peer 有值时随日志展示——运维可据此确认 nginx 的覆盖注入头已生效
        //（本机测试源 IP 恒为 127.0.0.1，「头生效」与「头缺席」只在这行日志里区分得开）
        self::log("连接 #{$tokenKey} @{$seat['peer']}（" . count(self::$seats) . ' 席位'
            . ($peerIp !== '' ? "，X-Ow-Peer={$peerIp}" : '') . '）');

        // 握手包里如果已经带了完整帧（一般不），继续按帧解析
        if ($seat['buf'] !== '') self::parseFrames($seat);
    }

    // ================================================================ 帧

    /** 从缓冲区里循环取出完整帧并分发；不完整的留在缓冲区等下次读 */
    private static function parseFrames(array $seat): void
    {
        $buf = $seat['buf'];
        while (true) {
            $n = strlen($buf);
            if ($n < 2) break;

            $b1 = ord($buf[0]);
            $b2 = ord($buf[1]);
            $fin   = ($b1 & 0x80) !== 0;
            $op    = $b1 & 0x0F;
            $masked = ($b2 & 0x80) !== 0;
            $len   = $b2 & 0x7F;
            $off   = 2;

            if ($len === 126) {
                if ($n < 4) break;
                $len = unpack('n', substr($buf, 2, 2))[1];
                $off = 4;
            } elseif ($len === 127) {
                if ($n < 10) break;
                $len = (int)unpack('J', substr($buf, 2, 8))[1];
                $off = 10;
            }
            if ($len > self::MAX_FRAME) { self::closeSeat($seat, 1009, 'frame too large'); return; }
            if (!$fin || $op === 0)     { self::closeSeat($seat, 1003, 'fragmentation unsupported'); return; }
            if ($op >= 0x8 && (!$fin || $len > 125)) { self::closeSeat($seat, 1002, 'bad control frame'); return; }
            if (!$masked)               { self::closeSeat($seat, 1002, 'client frames must be masked'); return; }

            if ($n < $off + 4 + $len) break;   // 掩码键 + 载荷还没到齐
            $mask = substr($buf, $off, 4);
            $payload = substr($buf, $off + 4, $len);
            $buf = substr($buf, $off + 4 + $len);
            // 解掩码（RFC6455 规定客户端必须加掩码；按 4 字节循环异或）
            if ($len > 0) {
                $un = '';
                for ($i = 0; $i < $len; $i++) $un .= $payload[$i] ^ $mask[$i & 3];
                $payload = $un;
            }

            // 每连接令牌桶（先于一切分发）：容量 10 突发、每秒回 5 —— 正常心跳远够用，
            // 狂发帧者在这里被断掉，不让 CPU 与后续写库被拖进放大
            $nowT = microtime(true);
            $dt = $seat['tbAt'] > 0 ? ($nowT - $seat['tbAt']) : 0.0;
            $seat['tb'] = min(self::TB_CAPACITY, (float)$seat['tb'] + $dt * self::TB_REFILL);
            $seat['tbAt'] = $nowT;
            if ($seat['tb'] < 1.0) { self::closeSeat($seat, 1008, 'rate limit'); return; }
            $seat['tb'] -= 1.0;

            $seat['lastAt'] = time();
            switch ($op) {
                case 0x1: self::handleText($seat, $payload); break;
                case 0x8: $seat['saidBye'] = true; self::closeSeat($seat, 1000, 'client close'); return;
                case 0x9: self::sendFrame($seat, 0xA, $payload); break;     // ping → pong
                case 0xA: break;                                            // pong：lastAt 已更新
                default:  self::closeSeat($seat, 1003, "unsupported opcode $op"); return;
            }
            if (!isset(self::$seats[(int)$seat['fp']])) return;   // 分发里可能已断开
        }
        // 写回剩余缓冲与时间戳（⚠️ lastAt 必须一起回写：帧处理里更新的是本地副本，
        // 漏回写会让活跃连接被 IDLE_TIMEOUT 误杀——写完回读时才发现的那种）
        if (isset(self::$seats[(int)$seat['fp']])) {
            $live = self::$seats[(int)$seat['fp']];
            $live['buf'] = $buf;
            $live['lastAt'] = $seat['lastAt'];
            $live['tb'] = $seat['tb'];
            $live['tbAt'] = $seat['tbAt'];
            self::$seats[(int)$seat['fp']] = $live;
        }
    }

    /** 客户端文本帧：{"t":"hb"} 心跳 / {"t":"sub","room":N} 订阅房间（presence 范围） */
    private static function handleText(array $seat, string $payload): void
    {
        $m = json_decode($payload, true);
        if (!is_array($m) || !isset($m['t'])) return;   // 不认识就静默忽略（协议只演进不报错）
        switch ((string)$m['t']) {
            case 'hb':
                self::refreshPresence($seat);
                self::sendJson($seat, ['t' => 'pong']);
                break;
            case 'sub':
                $room = (int)($m['room'] ?? 0);
                if ($room < 0 || $room > 1000000000) $room = 0;
                // sub 也是 actor 快照的高频触发点（轻量「重认证」）：先重装再鉴权，用最新角色
                // 判 roomAccessOk；读库失败保留旧快照（不因一次抖动把人踢下线）。
                $seat['actor'] = self::buildActor(
                    (string)($seat['actor']['kind'] ?? ''), (int)($seat['actor']['id'] ?? 0)
                ) ?? $seat['actor'];
                $seat['actorAt'] = time();
                // 订阅鉴权（安全清单：「握手认证、每条消息也要授权」的同口径落地）：
                // 用与 HTTP poll 完全同一把尺 roomAccessOk —— 未授权进入的房间静默降级为
                // 「不订阅」（连「该房间有人上下线」的存在性信号也不给）。
                if ($room > 0) {
                    try {
                        $r = Chat::room($room);
                        // ⚠️ roomAccessOk 是强类型 array：必须先判 null（直接传 null 会 TypeError）。
                        // 密码房的通行授权记在会话里（$_SESSION['room_pass']），守护进程看不到 →
                        // 密码房订阅一律静默降级：仅失去「秒级在线刷新」，成员仍走原 20s 长轮询，语义不差。
                        if (!$r || !Chat::roomAccessOk($r, $seat['actor'])) $room = 0;
                    } catch (Throwable $e) { $room = 0; }
                }
                $since = max(0, (int)($m['since'] ?? 0));
                $live = self::$seats[(int)$seat['fp']] ?? null;
                if ($live === null) break;
                $live['actor'] = $seat['actor'];
                $live['actorAt'] = $seat['actorAt'];
                if ($room !== (int)$live['room']) {
                    $old = (int)$live['room'];
                    $live['room'] = $room;
                    $live['since'] = $since;
                    self::$seats[(int)$seat['fp']] = $live;
                    self::refreshPresence($live);   // 立即把在线行挪到新房间
                    self::wake($old);
                    self::wake($room);
                } else {
                    // 同房重复 sub（重连后重订）：刷新水位，后续补齐按它拉
                    $live['since'] = $since;
                    self::$seats[(int)$seat['fp']] = $live;
                }
                // 订阅即补齐（P2）：since>0 时把它缺的这段直接推过去——「重连不丢消息」就靠这里。
                // since<=0 时**只对齐水位、不补内容**（前端首进会话自会走 HTTP history；
                // 不设这道保险会把整段历史回推给客户端，造成大面积重复渲染）。
                if ($room > 0) {
                    if ($since > 0) {
                        self::roomPushSeat($live, $room);
                    } else {
                        try {
                            $live['since'] = (int)DB::val('SELECT MAX(id) FROM messages WHERE room_id=?', [$room]);
                            self::$seats[(int)$seat['fp']] = $live;
                        } catch (Throwable $e) { /* 对齐失败保持 0：watcher 会从 0 拉——DB 已故障时的降级，接受 */ }
                    }
                    // 扫描水位同步抬到当前最大 id，避免下个 tick 探询把旧段当新料重推
                    try {
                        $mx = (int)DB::val('SELECT MAX(id) FROM messages WHERE room_id=?', [$room]);
                        if ($mx > (self::$wmRoom[$room] ?? 0)) self::$wmRoom[$room] = $mx;
                    } catch (Throwable $e) { /* 水位只影响探询频率，出错忽略 */ }
                }
                break;
            case 'dmsub':
                // 私聊会话订阅（P2）：多会话累积（进过的私聊连接期内一直订阅，
                // 在群聊页也能收到私聊推送→列表前置/未读）。peer 全套校验复用
                // Chat::dmPeerKey（正则/自我/跨身份——口径零复制）；订阅即补齐同 sub。
                $peer = (string)($m['peer'] ?? '');
                $pk = ($seat['actor'] ? Chat::dmPeerKey($seat['actor'], $peer) : null);
                if (!$pk) break;
                $live = self::$seats[(int)$seat['fp']] ?? null;
                if ($live === null) break;
                if (!is_array($live['dms'] ?? null)) $live['dms'] = [];
                if (!isset($live['dms'][$peer])) $live['dms'][$peer] = 0;
                $dSince = max(0, (int)($m['since'] ?? 0));
                if ($dSince > 0) {
                    $live['dms'][$peer] = $dSince;   // 重订可抬高水位（只增不减，防重放旧段）
                    self::$seats[(int)$seat['fp']] = $live;
                    self::dmPushSeat($live, $peer);
                } else {
                    // since<=0：只对齐水位不补内容（同 sub 的保险）。dmFetchSince 的白拿版：
                    // 拉一批只为拿推进后的 since，items 丢弃——一次性开销，dmsub 频率极低。
                    try {
                        [$mx, ] = Chat::dmFetchSince($seat['actor'], $pk, 0);
                        $live['dms'][$peer] = $mx;
                        self::$seats[(int)$seat['fp']] = $live;
                    } catch (Throwable $e) { /* 对齐失败保持 0：watcher 从 0 拉，DB 已故障时的降级 */ }
                }
                break;
            case 'send':
                // P3 发送迁移：消息发送回环到站点执行（见下方 handleSend 注释）。
                self::handleSend($seat, $m);
                break;
        }
    }

    // ================================================================ 控制口（PHP→daemon nudge，P2）

    /** 控制口 body 收满后处理：验密钥 → 分发 nudge → 回响应 → 关连接（不升级 WS） */
    private static function tryCtlBody(array $seat): void
    {
        if (strlen($seat['buf']) < (int)$seat['ctlLen']) return;   // body 还没收满，等下次读
        $fp = $seat['fp'];
        if (!isset(self::$seats[(int)$fp])) return;
        if (empty($seat['ctlAuth'])) {
            @fwrite($fp, "HTTP/1.1 401 Unauthorized\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
            self::log('控制口拒绝：密钥不符 @' . $seat['peer']);
        } else {
            $d = json_decode(substr($seat['buf'], 0, (int)$seat['ctlLen']), true);
            if (is_array($d)) self::handleNudge($d);
            @fwrite($fp, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
        }
        unset(self::$seats[(int)$fp]);
        @fclose($fp);
    }

    /**
     * nudge 分发：{room} / {dm} → 立即推一次该会话增量；{recall,id,room|dm} → 直接扇出撤回帧。
     * 全部是「加速」动作——推不到不影响正确性（watcher 每 tick 兜底；撤回丢了退化到「刷新才变」）。
     */
    private static function handleNudge(array $payload): void
    {
        if (isset($payload['recall'])) {
            $id = (int)$payload['recall'];
            if ($id <= 0) return;
            $room = (int)($payload['room'] ?? 0);
            $dm = (string)($payload['dm'] ?? '');
            foreach (self::$seats as $s) {
                if ($s['phase'] !== 'open') continue;
                if ($room > 0 && (int)$s['room'] === $room) self::sendJson($s, ['t' => 'recall', 'id' => $id, 'room' => $room]);
                elseif ($dm !== '' && isset($s['dms'][$dm])) self::sendJson($s, ['t' => 'recall', 'id' => $id, 'peer' => $dm]);
            }
            return;
        }
        $room = (int)($payload['room'] ?? 0);
        if ($room > 0) { self::pushRoomNow($room); return; }
        $dm = (string)($payload['dm'] ?? '');
        if ($dm !== '') {
            foreach (self::$seats as $s) {
                if ($s['phase'] === 'open' && isset($s['dms'][$dm])) self::dmPushSeat($s, $dm);
            }
        }
    }

    // ================================================================ 消息扇出（P2）

    /** 对某房间全体订阅座位推一次增量（tick 探询命中与 nudge 共用），并推进扫描水位 */
    private static function pushRoomNow(int $room): void
    {
        if ($room <= 0) return;
        foreach (self::$seats as $s) {
            if ($s['phase'] !== 'open' || (int)$s['room'] !== $room) continue;
            self::roomPushSeat($s, $room);
        }
        try {
            $mx = (int)DB::val('SELECT MAX(id) FROM messages WHERE room_id=?', [$room]);
            if ($mx > (self::$wmRoom[$room] ?? 0)) self::$wmRoom[$room] = $mx;
        } catch (Throwable $e) { /* 水位只影响探询频率，出错忽略 */ }
    }

    /** 单座位房间增量：读（复用核心口径）→ 推 → 推进座位 since。先推后推水位，失败不丢段 */
    private static function roomPushSeat(array $seat, int $room): void
    {
        if (empty($seat['actor'])) return;
        try {
            [$since2, $items] = Chat::roomFetchSince($seat['actor'], $room, (int)$seat['since']);
        } catch (Throwable $e) {
            self::log('房间增量读取失败：' . $e->getMessage());
            return;   // 座位 since 不推进：下个 tick / 下条消息会从原水位自愈重拉
        }
        if ($items) self::sendJson($seat, ['t' => 'msg', 'room' => $room, 'items' => $items]);
        if ($items || $since2 !== (int)$seat['since']) {
            $live = self::$seats[(int)$seat['fp']] ?? null;
            if ($live !== null) { $live['since'] = $since2; self::$seats[(int)$seat['fp']] = $live; }
        }
    }

    /** 单座位私聊增量（与房间同构；水位存座位 dms[peer]） */
    private static function dmPushSeat(array $seat, string $peer): void
    {
        if (empty($seat['actor']) || !isset($seat['dms'][$peer])) return;
        try {
            $pk = Chat::dmPeerKey($seat['actor'], $peer);
            if (!$pk) return;   // 快照变了 / peer 不合法：静默忽略该订阅
            [$since2, $items] = Chat::dmFetchSince($seat['actor'], $pk, (int)$seat['dms'][$peer]);
        } catch (Throwable $e) {
            self::log('私聊增量读取失败：' . $e->getMessage());
            return;
        }
        if ($items) self::sendJson($seat, ['t' => 'msgdm', 'peer' => $peer, 'items' => $items]);
        if ($items || $since2 !== (int)$seat['dms'][$peer]) {
            $live = self::$seats[(int)$seat['fp']] ?? null;
            if ($live !== null && isset($live['dms'][$peer])) {
                $live['dms'][$peer] = $since2;
                self::$seats[(int)$seat['fp']] = $live;
            }
        }
    }

    /** 帧编码（服务端 → 客户端：不加掩码） */
    private static function sendFrame(array $seat, int $opcode, string $payload): void
    {
        $len = strlen($payload);
        $head = chr(0x80 | $opcode);
        if ($len < 126)        $head .= chr($len);
        elseif ($len < 65536)  $head .= chr(126) . pack('n', $len);
        else                   $head .= chr(127) . pack('J', $len);
        @fwrite($seat['fp'], $head . $payload);   // 载荷都是小控制帧；非阻塞下的短写概率可忽略
    }

    private static function sendJson(array $seat, array $data): void
    {
        self::sendFrame($seat, 0x1, (string)json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    // ================================================================ 在线状态 / wake

    /**
     * 刷新在线状态：直接复用 Chat::heartbeat()。
     * 为什么零复制：online 表结构、45 秒窗口、upsert 键 token、以及 online.track 插件钩子
     * （redis 插件的在场镜像）全在那一个函数里——WS 路径另写一份迟早漂移。
     * ⚠️ CLI 下 Sec::ip() 返回 '0.0.0.0'（见文件头限制说明）。
     * ⚠️ 插件钩子里若有阻塞操作（如 redis 连接超时）会短暂占用事件循环——P1 接受，
     *    插件侧已有「单请求熔断」会在首次失败后跳过。
     */
    private static function refreshPresence(array $seat): void
    {
        if (empty($seat['actor'])) return;
        try {
            Chat::heartbeat($seat['actor'], (int)$seat['room']);
        } catch (Throwable $e) {
            self::log('心跳写库失败：' . $e->getMessage());
        }
    }

    /** 房间变更合并进合帧窗口：同房间在 250ms 内多次变化只扇出一帧 */
    private static function wake(int $room): void
    {
        if ($room <= 0) return;
        self::$pendingWake[$room] = microtime(true) + self::WAKE_DELAY;
    }

    private static function flushWake(int $room): void
    {
        unset(self::$pendingWake[$room]);
        $frame = ['t' => 'wake', 'kind' => 'presence', 'room' => $room];
        foreach (self::$seats as $s) {
            if ($s['phase'] !== 'open' || (int)$s['room'] !== $room) continue;
            self::sendJson($s, $frame);
        }
    }

    // ================================================================ P3 发送迁移（内部通道）

    /**
     * send 帧：消息发送回环到站点执行（index.php?action=ws_send → Chat::send()）。
     * 本进程**不执行任何发送逻辑**——插件同步长任务（AI 应答等）跑在 php-cgi 池里，
     * 事件循环只做「转发 + 等响应」，因此长任务不会阻塞任何连接。
     * 保序：同一 actor 在途 1 条 + 队列 ≤ SEND_QUEUE_PER_ACTOR —— 落库顺序=用户输入顺序。
     */
    private static function handleSend(array $seat, array $m): void
    {
        $fp = (int)$seat['fp'];
        $live = self::$seats[$fp] ?? null;
        if ($live === null || empty($live['actor'])) return;
        $cid = substr((string)($m['cid'] ?? ''), 0, 64);

        // 开关防御（前端已判定；兜底防旧页面/直连客户端）——setting 现读，开关即时生效
        $enabled = false;
        try { $enabled = (string)DB::setting('ws_send_enable', '0') === '1'; } catch (Throwable $e) {}
        if (!$enabled) {
            self::$sendStats['reject']++;
            self::sendJson($live, ['t' => 'sent', 'cid' => $cid, 'ok' => false, 'msg' => '实时发送未启用，请刷新页面']);
            return;
        }

        if (!empty($live['sendBusy'])) {
            if (count($live['sendQueue'] ?? []) >= self::SEND_QUEUE_PER_ACTOR) {
                self::$sendStats['reject']++;
                self::sendJson($live, ['t' => 'sent', 'cid' => $cid, 'ok' => false, 'msg' => '发送过于频繁，请稍候']);
                return;
            }
            $live['sendQueue'][] = $m;
            self::$seats[$fp] = $live;
            return;
        }
        $live['sendBusy'] = true;
        self::$seats[$fp] = $live;
        self::dispatchSend($live, $m);
    }

    /** 构造并发出内部请求（同步只做「连接 + 写出」；响应由事件循环异步收）。所有失败路径统一走泵队列释放。 */
    private static function dispatchSend(array $live, array $m): void
    {
        $cid = substr((string)($m['cid'] ?? ''), 0, 64);
        $fail = static function (string $msg) use (&$live, $cid): void {
            self::$sendStats['fail']++;
            $seatNow = self::$seats[(int)$live['fp']] ?? null;
            if ($seatNow !== null) {
                self::pumpSendQueue($seatNow, ['t' => 'sent', 'cid' => $cid, 'ok' => false, 'msg' => $msg]);
            }
        };
        try {
            $site = trim((string)DB::setting('site_url', ''));
            if ($site === '') {
                // 兜底：握手 Host（nginx proxy_set_header Host $host 原样透传用户访问的域名）。
                // 协议假定 https —— 本项目 /ws 反代只配在 443（TLS）vhost，WS 连接必为 wss，
                // 且非浏览器客户端不走这里（票据仍经 web 下发，能连上说明站点本身工作）。
                $site = 'https://' . (string)($live['siteHost'] ?? '');
            }
            $u = $site !== '' ? parse_url($site) : null;
            $host = is_array($u) ? (string)($u['host'] ?? '') : '';
            if ($host === '') { $fail('站点地址未配置，请刷新页面'); return; }
            $scheme = (string)($u['scheme'] ?? 'https');
            $port = (int)($u['port'] ?? ($scheme === 'https' ? 443 : 80));
            $timeout = (int)DB::setting('ws_send_timeout', (string)self::SEND_DEFAULT_TIMEOUT);
            if ($timeout < 3) $timeout = 3;
            if ($timeout > 120) $timeout = 120;
            $max = (int)DB::setting('ws_send_max_inflight', (string)self::SEND_DEFAULT_MAX);
            if ($max < 1) $max = self::SEND_DEFAULT_MAX;
            if (count(self::$outgoing) >= $max) { $fail('服务器繁忙，请稍后重试'); return; }

            $body = http_build_query([
                'actor_kind'  => (string)($live['actor']['kind'] ?? ''),
                'actor_id'    => (int)($live['actor']['id'] ?? 0),
                'room_id'     => (int)($m['room'] ?? 0),
                'type'        => (string)($m['type'] ?? 'text'),
                'content'     => (string)($m['content'] ?? ''),
                'to_user_id'  => (string)($m['to_user_id'] ?? ''),
                'to_guest_id' => (string)($m['to_guest_id'] ?? ''),
                'to_nickname' => (string)($m['to_nickname'] ?? ''),
                'quote'       => (string)($m['quote'] ?? ''),
                'client_id'   => $cid,
                'peer_ip'     => (string)($live['peerIp'] ?? ''),
            ], '', '&');
            $hostHdr = $host . ((($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) ? ':' . $port : '');
            $req = "POST /?action=ws_send HTTP/1.1\r\nHost: {$hostHdr}\r\nX-Ow-Ctl: " . self::$sendKey
                 . "\r\nContent-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body)
                 . "\r\nConnection: close\r\n\r\n" . $body;

            $transport = $scheme === 'https' ? 'ssl://' : 'tcp://';
            // 仅回环连接：不对证书做校验；peer_name 必须保留原域名（nginx 的 SNI/vhost 分流靠它）
            $ctx = stream_context_create($scheme === 'https' ? ['ssl' => [
                'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
                'peer_name' => $host, 'SNI_enabled' => true,
            ]] : []);
            $out = @stream_socket_client($transport . '127.0.0.1:' . $port, $eno, $estr, self::SEND_CONNECT_TIMEOUT, STREAM_CLIENT_CONNECT, $ctx);
            if (!$out) { $fail('发送通道不可用'); return; }
            stream_set_blocking($out, false);
            $n = @fwrite($out, $req);
            if ($n !== strlen($req)) {          // 回环小负载一次写完是常态；写不全直接放弃（半截请求不可恢复）
                @fclose($out);
                $fail('发送通道不可用');
                return;
            }
            self::$outgoing[(int)$out] = [
                'fp' => $out, 'seatFp' => (int)$live['fp'], 'cid' => $cid,
                'bornAt' => microtime(true), 'timeout' => $timeout, 'buf' => '',
            ];
        } catch (Throwable $e) {
            $fail('发送失败，请重试');
        }
    }

    /** 发送队列泵：发待回帧（可选）→ 释放本单 → 队列还有任务就立刻开跑下一条 */
    private static function pumpSendQueue(array $seat, ?array $frame = null): void
    {
        $fp = (int)$seat['fp'];
        $live = self::$seats[$fp] ?? null;
        if ($live === null) return;             // 座位已断：不回帧（消息本身的发送已在站点侧完成）
        if ($frame !== null && $live['phase'] === 'open') self::sendJson($live, $frame);
        if (empty($live['sendQueue'])) {
            $live['sendBusy'] = false;
            self::$seats[$fp] = $live;
            return;
        }
        $next = array_shift($live['sendQueue']);
        $live['sendBusy'] = true;
        self::$seats[$fp] = $live;
        self::dispatchSend($live, $next);
    }

    /** 出站请求可读：累积解析 HTTP 响应，收满即完成（Content-Length 到齐 / 对端关闭收尾） */
    private static function readOutgoing(int $fd): void
    {
        $o = self::$outgoing[$fd] ?? null;
        if ($o === null) return;
        $chunk = @fread($o['fp'], 65536);
        if ($chunk === false || ($chunk === '' && feof($o['fp']))) {
            self::finishOutgoing($fd, $o['buf']);   // 连接关闭：用已收内容尽量解析
            return;
        }
        if ($chunk === '') return;                  // 非阻塞下暂时无数据
        $o['buf'] .= $chunk;
        $pos = strpos($o['buf'], "\r\n\r\n");
        if ($pos !== false && preg_match('/^Content-Length:\s*(\d+)/mi', substr($o['buf'], 0, $pos), $mm)) {
            if (strlen($o['buf']) - ($pos + 4) >= (int)$mm[1]) {
                self::finishOutgoing($fd, substr($o['buf'], $pos + 4));
                return;
            }
        }
        self::$outgoing[$fd] = $o;                  // 未收全：回存缓冲等下次可读
    }

    /** 内部请求收尾：解析 JSON → 回 sent 帧 → 计数 → 泵队列 */
    private static function finishOutgoing(int $fd, string $raw): void
    {
        $o = self::$outgoing[$fd] ?? null;
        if ($o === null) return;
        unset(self::$outgoing[$fd]);
        @fclose($o['fp']);
        $seat = self::$seats[$o['seatFp']] ?? null;
        if ($seat === null) return;                 // 座位已断：无人收结果
        $pos = strpos($raw, "\r\n\r\n");
        $body = $pos !== false ? substr($raw, $pos + 4) : $raw;   // 带完整头时取 body（EOF 收尾路径）
        // nginx 对「无 Content-Length」的响应可能用 chunked 编码（体前有十六进制长度行）——
        // 直接按「首个 { 到末个 }」提取 JSON（ws_send 响应固定是单个 JSON 对象，此提取无歧义）。
        if ($body === '' || $body[0] !== '{') {
            $b1 = strpos($raw, '{');
            $b2 = strrpos($raw, '}');
            if ($b1 !== false && $b2 !== false && $b2 >= $b1) $body = substr($raw, $b1, $b2 - $b1 + 1);
        }
        $j = null;
        try { $j = json_decode($body, true); } catch (Throwable $e) {}
        if (!is_array($j)) {
            self::$sendStats['fail']++;
            self::log('出站响应解析失败（' . strlen($raw) . 'B）：' . substr(str_replace(["\r", "\n"], ' ', $raw), 0, 160));
            self::pumpSendQueue($seat, ['t' => 'sent', 'cid' => $o['cid'], 'ok' => false, 'msg' => '发送失败，请重试']);
            return;
        }
        if (!empty($j['ok'])) {
            self::$sendStats['ok']++;
            $frame = ['t' => 'sent', 'cid' => $o['cid'], 'ok' => true];
            if (isset($j['msg_row']) && is_array($j['msg_row'])) $frame['msg_row'] = $j['msg_row'];
            if (isset($j['id'])) $frame['id'] = $j['id'];
            self::pumpSendQueue($seat, $frame);
            return;
        }
        self::$sendStats['fail']++;
        $msg = (string)($j['msg'] ?? '');
        if ($msg === '') $msg = '发送失败，请重试';
        self::pumpSendQueue($seat, ['t' => 'sent', 'cid' => $o['cid'], 'ok' => false, 'msg' => $msg]);
    }

    // ================================================================ 票据

    /**
     * 消费一次性票据：读 → 删 → 验。文件路径 = sha256(原 token)，避免拿文件名做注入。
     * 并发竞争窗口（同一票据几乎同时被两个连接使用）在 60 秒时效 + TLS + 单票签名串的
     * 前提下可忽略；真要做到严格一次性再上 flock，P1 不为此加复杂度。
     */
    private static function consumeTicket(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $file = self::$ticketsDir . '/' . $token . '.json';
        $raw = @file_get_contents($file);
        if ($raw === false) return null;
        @unlink($file);   // 用后即焚：读到就删，过期检查不过也删
        $d = json_decode($raw, true);
        if (!is_array($d)) return null;
        if ((int)($d['exp'] ?? 0) < time()) return null;
        $kind = (string)($d['kind'] ?? '');
        $id = (int)($d['id'] ?? 0);
        if (!in_array($kind, ['user', 'guest'], true) || $id <= 0) return null;
        return [$kind, $id];
    }

    /** 按 Auth::actor() 同口径装配身份（users / guests 查一行后交给核心函数） */
    private static function buildActor(string $kind, int $id): ?array
    {
        try {
            if ($kind === 'user') {
                $u = DB::one('SELECT * FROM users WHERE id=?', [$id]);
                return $u ? Auth::actor($u, null) : null;
            }
            $g = DB::one('SELECT * FROM guests WHERE id=?', [$id]);
            return $g ? Auth::actor(null, $g) : null;
        } catch (Throwable $e) {
            self::log('身份装配失败：' . $e->getMessage());
            return null;
        }
    }

    // ================================================================ 周期任务与收尾

    private static function tick(): void
    {
        $now = time();

        // 合帧到点即扇出
        $nowM = microtime(true);
        foreach (self::$pendingWake as $room => $at) {
            if ($nowM >= $at) self::flushWake((int)$room);
        }

        foreach (self::$seats as $seat) {
            if (($seat['phase'] === 'handshake' || $seat['phase'] === 'ctlbody')
                && $now - (int)$seat['bornAt'] > self::HANDSHAKE_TIMEOUT) {
                self::closeSeat($seat, 1008, 'handshake timeout');
            } elseif ($seat['phase'] === 'open' && $now - $seat['lastAt'] > self::IDLE_TIMEOUT) {
                self::closeSeat($seat, 1001, 'idle timeout');
            }
        }

        // actor 快照重装（轻量「重认证」，P2）：sub 时已重装，这里兜住挂着不回头的座位。
        // 权限过滤不在快照上做（每条消息 visible 实时算），快照只影响显示与 role 判断，100 秒收敛足够。
        foreach (self::$seats as $seat) {
            if ($seat['phase'] !== 'open' || empty($seat['actor'])) continue;
            if ($now - (int)($seat['actorAt'] ?? 0) < self::ACTOR_REFRESH) continue;
            $fresh = self::buildActor((string)$seat['actor']['kind'], (int)$seat['actor']['id']);
            if ($fresh === null) continue;   // 查不到就不动快照，下个周期再试
            $live = self::$seats[(int)$seat['fp']] ?? null;
            if ($live !== null) { $live['actor'] = $fresh; $live['actorAt'] = $now; self::$seats[(int)$seat['fp']] = $live; }
        }

        // 房间 watcher（P2）：v1.5.20 改成一条 GROUP BY 取回所有活跃房间的最新 id。
        // 原来每房一条探询 SQL（房间一多就是 N+1）；命中房间才逐座位拉包。
        $activeRooms = [];
        foreach (self::$seats as $s) {
            if ($s['phase'] === 'open' && (int)$s['room'] > 0) $activeRooms[(int)$s['room']] = true;
        }
        if ($activeRooms) {
            try {
                [$inSql, $inArgs] = DB::in('room_id', array_keys($activeRooms));
                $latest = [];
                foreach (DB::all("SELECT room_id, MAX(id) AS mid FROM messages WHERE $inSql GROUP BY room_id", $inArgs) as $r) {
                    $latest[(int)$r['room_id']] = (int)$r['mid'];
                }
                foreach (array_keys($activeRooms) as $room) {
                    $wm = self::$wmRoom[$room] ?? 0;
                    if (($latest[(int)$room] ?? 0) > $wm) self::pushRoomNow((int)$room);
                }
            } catch (Throwable $e) { /* 探询失败：下个 tick 重试 */ }
        }

        // DM watcher（P2）：N+1 例外 —— 私聊座位数量少，逐座位单查（查询数 = DM 座位数/秒）；
        // 房间 watcher 已改为一条 GROUP BY，私聊若要再优化需按 peer 合并 watcher 水位。
        foreach (self::$seats as $s) {
            if ($s['phase'] !== 'open' || empty($s['dms'])) continue;
            foreach (array_keys($s['dms']) as $peer) self::dmPushSeat($s, (string)$peer);
        }

        // P2：计划任务承接——长轮询流量下降后由守护进程驱动（任务自身按周期节流）。
        // 回调异常吞掉，绝不让一条坏任务掀翻事件循环（重任务挪子进程是 P3 的事）。
        try { Plugin::cronTick(); } catch (Throwable $e) { self::log('cronTick 异常：' . $e->getMessage()); }

        // P3：出站请求超时（读超时到点 → 回超时帧 + 关连接）。站点侧的 php-cgi 可能仍在执行、
        // 消息稍后落库——前端靠推送/重连补齐转正，文案如实提示「可能稍后送达」。
        foreach (self::$outgoing as $ofd => $o) {
            if ($nowM - $o['bornAt'] > (float)$o['timeout']) {
                self::$sendStats['timeout']++;
                unset(self::$outgoing[$ofd]);
                @fclose($o['fp']);
                $seat = self::$seats[$o['seatFp']] ?? null;
                if ($seat !== null) {
                    self::pumpSendQueue($seat, ['t' => 'sent', 'cid' => $o['cid'], 'ok' => false, 'msg' => '发送超时，请稍后重试（消息可能稍后送达）']);
                }
            }
        }

        // P3：发送统计周期日志（每分钟判一次、仅有活动时打印；打印后归零 = 区间统计）
        if ($now - self::$lastStatsAt >= 60) {
            self::$lastStatsAt = $now;
            if (array_sum(self::$sendStats) > 0) {
                self::log('发送统计：ok=' . self::$sendStats['ok'] . ' fail=' . self::$sendStats['fail']
                    . ' timeout=' . self::$sendStats['timeout'] . ' reject=' . self::$sendStats['reject']);
                self::$sendStats = ['ok' => 0, 'fail' => 0, 'timeout' => 0, 'reject' => 0];
            }
        }

        // 过期票据清扫（正常路径靠消费即删；这里兜住「签了没连」的残留文件）
        if ($nowM - self::$lastSweep > self::TICKET_SWEEP) {
            self::$lastSweep = $nowM;
            foreach ((array)@glob(self::$ticketsDir . '/*.json') as $f) {
                if (@filemtime($f) < $now - 120) @unlink($f);
            }
        }
    }

    /** 关闭席位：下线（删在线行 + wake 同房间）→ 回 close 帧 → 关连接 */
    private static function closeSeat(array $seat, int $code, string $reason): void
    {
        $fp = $seat['fp'];
        if (!isset(self::$seats[(int)$fp])) return;
        unset(self::$seats[(int)$fp]);

        if ($seat['phase'] === 'open' && !empty($seat['token'])) {
            try {
                // 立即下线：45 秒窗口是给「进程崩了没人删行」的兜底，不是正常路径的延迟
                DB::run('DELETE FROM online WHERE token=?', [$seat['token']]);
            } catch (Throwable $e) { /* 出错也不影响断开 */ }
            self::wake((int)$seat['room']);
            if (!$seat['saidBye']) {
                @fwrite($fp, chr(0x88) . chr(2) . pack('n', $reason === 'server shutdown' ? 1001 : $code));
            }
            self::log("断开 #{$seat['token']}（{$code} {$reason}）");
        }
        @fclose($fp);
    }

    /** 握手失败时的 HTTP 拒绝（不进入 WS 协议；浏览器 Network 里能看到状态码好排查） */
    private static function httpReject(array $seat, int $code, string $note = ''): void
    {
        $text = ['400' => 'Bad Request', '403' => 'Forbidden', '503' => 'Service Unavailable'][(string)$code] ?? 'Error';
        @fwrite($seat['fp'], "HTTP/1.1 $code $text\r\nConnection: close\r\nContent-Length: 0\r\n\r\n");
        unset(self::$seats[(int)$seat['fp']]);
        @fclose($seat['fp']);
        self::log("握手拒绝 @{$seat['peer']}：{$code}" . ($note !== '' ? "（{$note}）" : ''));
    }

    private static function log(string $msg): void
    {
        echo '[' . date('H:i:s') . '] ' . $msg . "\n";
    }
}
