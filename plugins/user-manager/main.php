<?php
/**
 * 用户管理插件（v1.1.0）
 *
 * 后台页面：Plugin::adminPage() 注册「用户管理」子页（插件管理分类下）。
 *   - 「用户查询」：按用户 ID 精确查询，改角色 / 称号 / 积分 / 等级，禁用启用，安全画像。
 *   - 「IP 封禁」：封禁 / 解封 IP、看高风险 IP、配归属地解析。
 *
 * v1.1.0 新增「登录网络」能力（参考 ip_history 的异步归属地思路）：
 *   ① 记录：login.after_verify / login.failed / logout.before_destroy / session.destroyed
 *      四个核心钩子把「谁 · 哪个 IP · 成功还是失败 · 什么设备」写进 plugin_user_manager_ip
 *      （按 uid+ip 聚合，只留首次/最近/次数/末次 UA，不按事件无限增长）。
 *      密码错时 $info['user'] 为 null（核心防账号枚举），这类失败记在 uid=0 上，
 *      供「高风险 IP」按失败次数排序用 —— 既不断言账号存在，也能抓到撞库源。
 *   ② 封禁：request.start 钩子（DB 就绪、$actor 已解析、尚未输出任何字节）命中即 403。
 *      **管理员账号直接放行**，否则管理员一不小心封了自己正在用的 IP 就再也进不去后台。
 *   ③ 归属地：不内置库、不装依赖。后台可配「含 {ip} 的接口地址」，结果落
 *      plugin_user_manager_geo 缓存；打开画像时按需补解析（前端异步二次请求），
 *      另有 Plugin::cron 慢慢消化待解析队列。接口不可用就显示「未解析」，不影响任何主流程。
 *   ④ 顺带实现核心的 ip.location 钩子 —— 聊天页「查 IP 归属地」原本没人应答。
 *
 * 安全约束：所有路由第一步做管理员鉴权（插件路由不在 Admin::handle 内）；
 *           封禁/解封走 OwApi.secure（敏感操作 + 一次性票据）。
 */
if (!defined('OWLSGO_VERSION')) exit;   // 禁止直接 HTTP 访问本文件

/** 管理员鉴权：插件路由的公共守卫 */
$umGuard = function (array $ctx): void {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
};

/* ===================== 数据表 ===================== */

/**
 * 跨驱动建表。三张表各管一件事：
 *   ip     用户 × IP 的聚合（谁用过哪个地址、成功几次、失败几次、末次设备）
 *   geo    IP → 归属地文本的缓存（跨用户复用，同 IP 只查一次）
 *   ipban  封禁规则（pattern 支持 1.2.3.* 通配，expires_at=0 永久）
 */
function owumEnsureTables(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $drv = DB::driver();
    $int = $drv === 'mysql' ? 'INT' : 'INTEGER';
    $ipc = $drv === 'sqlite' ? 'TEXT' : 'VARCHAR(64)';
    $str = $drv === 'sqlite' ? 'TEXT' : 'VARCHAR(191)';
    $ua  = $drv === 'sqlite' ? 'TEXT' : 'VARCHAR(255)';
    $ts  = $drv === 'mysql' ? 'BIGINT' : 'INTEGER';

    DB::run("CREATE TABLE IF NOT EXISTS plugin_user_manager_ip (
        uid $int NOT NULL DEFAULT 0,
        ip $ipc NOT NULL DEFAULT '',
        first_at $ts NOT NULL DEFAULT 0,
        last_at $ts NOT NULL DEFAULT 0,
        ok_n $int NOT NULL DEFAULT 0,
        fail_n $int NOT NULL DEFAULT 0,
        ua $ua NOT NULL DEFAULT '',
        kind $ipc NOT NULL DEFAULT '',
        PRIMARY KEY (uid, ip)
    )");
    DB::run("CREATE TABLE IF NOT EXISTS plugin_user_manager_geo (
        ip $ipc NOT NULL PRIMARY KEY,
        label $str NOT NULL DEFAULT '',
        at $ts NOT NULL DEFAULT 0
    )");
    DB::run("CREATE TABLE IF NOT EXISTS plugin_user_manager_ipban (
        pattern $ipc NOT NULL PRIMARY KEY,
        note $str NOT NULL DEFAULT '',
        by_uid $int NOT NULL DEFAULT 0,
        by_name $str NOT NULL DEFAULT '',
        created_at $ts NOT NULL DEFAULT 0,
        expires_at $ts NOT NULL DEFAULT 0
    )");
    // 反查用：给定 IP 找用过它的账号（「同 IP 的其他账号」、高风险 IP 聚合）
    DB::createIndex('idx_owum_ip', 'plugin_user_manager_ip', 'ip');
    DB::createIndex('idx_owum_last', 'plugin_user_manager_ip', 'last_at');
}
owumEnsureTables();

/* ===================== IP 与封禁判定 ===================== */

/** 归一化：去空白、转小写，非法地址返回空串 */
function owumNormIp(string $ip): string
{
    $ip = strtolower(trim($ip));
    if ($ip === '' || $ip === '0.0.0.0' || $ip === '::' || $ip === '::1') return $ip === '::1' ? '::1' : '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

/** 内网 / 保留地址：不查归属地，直接给「内网地址」 */
function owumIsLan(string $ip): bool
{
    if ($ip === '::1' || $ip === '127.0.0.1' || $ip === '0.0.0.0') return true;
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

/** 规则匹配：完全相同，或含 * 的通配（1.2.3.*） */
function owumIpMatch(string $pattern, string $ip): bool
{
    if ($pattern === $ip) return true;
    if (strpos($pattern, '*') === false) return false;
    $re = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
    return (bool)preg_match($re, $ip);
}

/**
 * 当前生效的封禁规则（本请求内只查一次）。
 * 表不存在 / 查询失败一律当成「没有规则」—— 拦截器绝不能因为自身异常把整站打挂。
 */
function owumBans(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $cache = DB::all('SELECT pattern,note,expires_at FROM plugin_user_manager_ipban');
    } catch (Throwable $e) {
        $cache = [];
    }
    return $cache;
}

/** 命中的封禁规则；未命中返回 null */
function owumBanHit(string $ip): ?array
{
    if ($ip === '') return null;
    $now = time();
    foreach (owumBans() as $b) {
        $exp = (int)($b['expires_at'] ?? 0);
        if ($exp > 0 && $exp <= $now) continue;      // 已过期：不再拦
        if (owumIpMatch((string)$b['pattern'], $ip)) return $b;
    }
    return null;
}

/**
 * 记录 / 更新一条「用户 × IP」聚合行。
 * 计数用 SQL 自增（ok_n=ok_n+excluded.ok_n），不走「先读后写」——
 * 并发登录时先读后写会丢计数。三种驱动语法不同，按 driver 分支。
 */
function owumTouchIp(int $uid, string $ip, string $kind, string $ua = ''): void
{
    $ip = owumNormIp($ip);
    if ($ip === '') return;
    $now = time();
    $ok = $kind === 'login' ? 1 : 0;
    $fail = $kind === 'fail' ? 1 : 0;
    $ua = mb_substr(trim($ua), 0, 255);
    $cols = 'uid,ip,first_at,last_at,ok_n,fail_n,ua,kind';
    $ph = '?,?,?,?,?,?,?,?';
    $args = [$uid, $ip, $now, $now, $ok, $fail, $ua, mb_substr($kind, 0, 64)];
    $drv = DB::driver();
    if ($drv === 'mysql') {
        $sql = "INSERT INTO plugin_user_manager_ip ($cols) VALUES ($ph)
            ON DUPLICATE KEY UPDATE last_at=VALUES(last_at), ok_n=ok_n+VALUES(ok_n),
            fail_n=fail_n+VALUES(fail_n), ua=VALUES(ua), kind=VALUES(kind)";
    } elseif ($drv === 'pgsql') {
        $sql = "INSERT INTO plugin_user_manager_ip ($cols) VALUES ($ph)
            ON CONFLICT (uid,ip) DO UPDATE SET last_at=excluded.last_at,
            ok_n=plugin_user_manager_ip.ok_n+excluded.ok_n,
            fail_n=plugin_user_manager_ip.fail_n+excluded.fail_n, ua=excluded.ua, kind=excluded.kind";
    } else {
        $sql = "INSERT INTO plugin_user_manager_ip ($cols) VALUES ($ph)
            ON CONFLICT (uid,ip) DO UPDATE SET last_at=excluded.last_at,
            ok_n=ok_n+excluded.ok_n, fail_n=fail_n+excluded.fail_n, ua=excluded.ua, kind=excluded.kind";
    }
    try {
        DB::run($sql, $args);
    } catch (Throwable $e) {
        // 记录失败绝不能影响登录 / 登出主流程
        Sec::log('owum_ip_log_err', '', ['err' => $e->getMessage()]);
    }
}

/* ===================== 归属地 ===================== */

const OWUM_GEO_DEFAULT_API = 'https://api.ip.sb/geoip/{ip}';
const OWUM_GEO_MAX_BYTES   = 262144;

function owumGeoCfg(): array
{
    $api = trim((string)DB::setting('owum_geo_api', OWUM_GEO_DEFAULT_API));
    if ($api === '' || strpos($api, '{ip}') === false) $api = OWUM_GEO_DEFAULT_API;
    return [
        'on'  => (string)DB::setting('owum_geo_on', '1') === '1',
        'api' => $api,
    ];
}

/**
 * 接口地址白名单校验：必须是 http/https、含 {ip}、不指向本机/内网。
 * 配置项只有管理员能改，但这层校验仍然要做 —— 否则一个打错的地址
 * 就能让服务器去请求自己内网的任意服务（SSRF）。
 */
function owumGeoApiOk(string $api): bool
{
    if ($api === '' || strlen($api) > 300 || strpos($api, '{ip}') === false) return false;
    $p = parse_url(str_replace('{ip}', '1.1.1.1', $api));
    if (!is_array($p)) return false;
    $scheme = strtolower((string)($p['scheme'] ?? ''));
    $host = strtolower((string)($p['host'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], true) || $host === '') return false;
    if ($host === 'localhost' || substr($host, -6) === '.local') return false;
    if (filter_var($host, FILTER_VALIDATE_IP) && owumIsLan($host)) return false;
    return true;
}

/** 批量读取归属地缓存（一次 IN），返回 ip => label */
function owumGeoMap(array $ips): array
{
    $ips = array_values(array_filter(array_unique($ips), 'strlen'));
    if (!$ips) return [];
    $out = [];
    foreach (array_chunk($ips, 400) as $chunk) {
        [$in, $args] = DB::in('ip', $chunk);
        foreach (DB::all("SELECT ip,label FROM plugin_user_manager_geo WHERE $in", $args) as $r) {
            $out[(string)$r['ip']] = (string)$r['label'];
        }
    }
    return $out;
}

/** 单个 IP 的归属地文本（只读缓存，不发外部请求） */
function owumGeoOf(string $ip): string
{
    $ip = owumNormIp($ip);
    if ($ip === '') return '';
    if (owumIsLan($ip)) return '内网地址';
    $m = owumGeoMap([$ip]);
    return $m[$ip] ?? '';
}

/** 从任意形状的 JSON 里挑出省 / 市 / 运营商，拼成一行给人看的文本 */
function owumGeoPick(array $d, array $paths): string
{
    foreach ($paths as $path) {
        $v = $d;
        $ok = true;
        foreach (explode('.', $path) as $seg) {
            if (!is_array($v) || !array_key_exists($seg, $v)) { $ok = false; break; }
            $v = $v[$seg];
        }
        if ($ok && is_scalar($v)) {
            $s = trim((string)$v);
            if ($s !== '' && $s !== '-' && strtoupper($s) !== 'N/A') return $s;
        }
    }
    return '';
}

function owumGeoLabelFrom(array $d): string
{
    // 兼容 ip.sb / ip-api / ipinfo / ipapi.co / 国内各类中文接口的常见字段名
    $country = owumGeoPick($d, ['country', 'country_name', 'data.country', 'data.country_name']);
    $region  = owumGeoPick($d, ['region', 'region_name', 'province', 'data.region', 'data.province']);
    $city    = owumGeoPick($d, ['city', 'data.city']);
    $isp     = owumGeoPick($d, ['isp', 'org', 'asn_organization', 'connection.isp', 'data.isp', 'data.isp_name']);

    $parts = [];
    if ($country !== '' && !preg_match('/中国|china/i', $country)) $parts[] = $country;
    if ($region !== '' && $region !== $country) $parts[] = $region;
    if ($city !== '' && $city !== $region) $parts[] = $city;
    $label = implode(' ', $parts);
    if ($isp !== '') $label .= ($label !== '' ? ' · ' : '') . $isp;
    return mb_substr(trim($label), 0, 190);
}

/** 发一个 GET，带 3 秒级超时与响应体大小上限。返回 [ok, body] */
function owumHttpGet(string $url, int $timeout): array
{
    $timeout = max(1, min(10, $timeout));
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if (!$ch) return [false, ''];
        $body = '';
        $tooBig = false;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(2, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_USERAGENT      => 'Owlsgo-Chat/' . OWLSGO_VERSION,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_WRITEFUNCTION  => function ($h, $chunk) use (&$body, &$tooBig) {
                if (strlen($body) + strlen($chunk) > OWUM_GEO_MAX_BYTES) { $tooBig = true; return 0; }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($tooBig || $ok === false || $code < 200 || $code >= 300) return [false, ''];
        return [true, $body];
    }
    // 没装 cURL：退到流封装（同样设超时，不无限等）
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'header' => "Accept: application/json\r\n"]]);
    $body = @file_get_contents($url, false, $ctx, 0, OWUM_GEO_MAX_BYTES);
    return is_string($body) && $body !== '' ? [true, $body] : [false, ''];
}

/**
 * 解析一个 IP 的归属地并写缓存。
 * 失败也写一行（label 为空、at=当前时间）：避免同一个死地址被每次请求反复重试。
 * 空 label 的行 7 天后允许重试（见 owumGeoPending）。
 */
function owumGeoResolveOne(string $ip, int $timeout = 3): string
{
    $ip = owumNormIp($ip);
    if ($ip === '' || owumIsLan($ip)) return '';
    $cfg = owumGeoCfg();
    if (!owumGeoApiOk($cfg['api'])) return '';
    $url = str_replace('{ip}', rawurlencode($ip), $cfg['api']);
    [$ok, $body] = owumHttpGet($url, $timeout);
    $label = '';
    if ($ok) {
        // 国内接口（如 pconline）返回 GBK；非 UTF-8 就转一次，否则中文会落库成乱码
        if (!mb_check_encoding($body, 'UTF-8')) {
            $conv = @mb_convert_encoding($body, 'UTF-8', 'GB18030');
            if (is_string($conv) && $conv !== '') $body = $conv;
        }
        $data = json_decode($body, true);
        if (is_array($data)) $label = owumGeoLabelFrom($data);
    }
    try {
        DB::upsert('plugin_user_manager_geo', ['ip' => $ip, 'label' => $label, 'at' => time()], ['ip']);
    } catch (Throwable $e) {
        // 缓存写不进去就当没解析，不影响调用方
    }
    return $label;
}

/**
 * 待解析的 IP 队列：缓存里没有，或空结果已过 7 天。
 * $scope 非空时只在这些 IP 里挑（画像面板用），否则全局挑最近活跃的。
 */
function owumGeoPending(array $scope, int $limit): array
{
    $limit = max(1, min(50, $limit));
    $cut = time() - 7 * 86400;
    if ($scope) {
        $ips = array_values(array_filter(array_unique(array_map('owumNormIp', $scope)), 'strlen'));
        if (!$ips) return [];
        $have = owumGeoMap($ips);              // 已有缓存（含空结果）
        $todo = [];
        foreach ($ips as $ip) {
            if (!isset($have[$ip])) { $todo[] = $ip; continue; }
            if ($have[$ip] === '') $todo[] = $ip;   // 空结果：下面按时间判断要不要重试
        }
        if (!$todo) return [];
        [$in, $args] = DB::in('ip', $todo);
        $retry = [];
        foreach (DB::all("SELECT ip,at FROM plugin_user_manager_geo WHERE $in", $args) as $r) {
            if ((int)$r['at'] < $cut) $retry[(string)$r['ip']] = true;
        }
        $out = [];
        foreach ($todo as $ip) {
            if (!isset($have[$ip]) || isset($retry[$ip])) $out[] = $ip;
            if (count($out) >= $limit) break;
        }
        return $out;
    }
    $rows = DB::all("SELECT ip,MAX(last_at) t FROM plugin_user_manager_ip
        WHERE uid>0 AND ip<>'' GROUP BY ip ORDER BY t DESC LIMIT 300");
    $ips = array_column($rows, 'ip');
    $have = owumGeoMap($ips);
    $out = [];
    foreach ($ips as $ip) {
        if (owumIsLan((string)$ip)) continue;
        if (!isset($have[$ip])) { $out[] = (string)$ip; }
        if (count($out) >= $limit) break;
    }
    return $out;
}

/** 批量解析（串行，逐个超时；limit 已在调用处收紧，避免把请求拖长） */
function owumGeoResolveAll(array $ips, int $limit = 5, int $timeout = 3): array
{
    $out = [];
    $n = 0;
    foreach ($ips as $ip) {
        if ($n >= $limit) break;
        $n++;
        $label = owumGeoResolveOne((string)$ip, $timeout);
        if ($label !== '') $out[(string)$ip] = $label;
    }
    return $out;
}

/* ===================== 后台页面 ===================== */

Plugin::adminPage('user-manager', '用户管理', function () {
    $pn = Sec::e(ow_points_name());
    $geo = owumGeoCfg();

    echo '<h2>用户管理</h2>'
        . '<p class="ow-admin-desc">按用户 ID 精确查询，管理身份、头衔与' . $pn . '；「安全画像」可查看登录网络并封禁 IP。</p>'

        // 分栏：用户查询 / IP 封禁（一插件一页，两块用页签分开）
        . '<div class="ow-tabs owum-tabs" id="owumTabs">'
        . '<button type="button" class="ow-tab is-active" data-owum-tab="user">用户查询</button>'
        . '<button type="button" class="ow-tab" data-owum-tab="ip">IP 封禁</button>'
        . '</div>'

        /* ---------- 面板一：用户查询 ---------- */
        . '<div class="owum-pane" id="owumPaneUser">'
        . '<div class="ow-card">'
        . '<div class="owum-search">'
        . '<input class="ow-input" id="owAQ" placeholder="输入用户 ID（纯数字）" inputmode="numeric" autocomplete="off">'
        . '<button class="ow-btn ow-btn-primary" onclick="OwUM.search()">搜索用户</button>'
        . '</div>'
        . '<p class="ow-form-hint">仅支持按用户 ID 精确查询；结果行的「安全画像」里可看登录网络并封禁 IP。</p>'
        . '</div>'
        . '<div id="owAResult"></div>'
        . '</div>'

        /* ---------- 面板二：IP 封禁 ---------- */
        . '<div class="owum-pane owum-hide" id="owumPaneIp">'

        // 封禁表单 + 现有规则
        . '<div class="ow-card">'
        . '<div class="ow-list-head"><span class="ow-list-head-t">IP 访问封禁</span>'
        . '<span class="owum-head-sub" id="owumBanStat">加载中…</span></div>'
        . '<div class="owum-banform">'
        . '<input class="ow-input owum-mono" id="owumBanIp" placeholder="1.2.3.4 或 1.2.3.*" maxlength="64" autocomplete="off">'
        . '<input class="ow-input" id="owumBanNote" placeholder="原因（选填，会显示在拦截页）" maxlength="60">'
        . '<select class="ow-input" id="owumBanDays">'
        . '<option value="0">永久</option><option value="1">1 天</option>'
        . '<option value="7">7 天</option><option value="30">30 天</option>'
        . '</select>'
        . '<button class="ow-btn ow-btn-danger" id="owumBanBtn" onclick="OwUM.banAdd()">封禁</button>'
        . '</div>'
        . '<p class="owum-warn" id="owumBanSelf" style="display:none">这是你当前登录使用的地址（<span class="owum-mono" id="owumBanSelfIp"></span>），不能封禁。</p>'
        . '<p class="ow-form-hint">被封禁的 IP 打开任何页面都会被拦下；支持 <b>1.2.3.*</b> 网段写法。管理员账号不受影响，避免把自己锁在门外。</p>'
        . '<div id="owumBanList" class="owum-banlist"><div class="owum-empty">加载中…</div></div>'
        . '</div>'

        // 高风险 IP
        . '<div class="ow-card">'
        . '<div class="ow-list-head"><span class="ow-list-head-t">高风险 IP</span>'
        . '<span class="owum-head-sub">按登录失败次数排序，点击行内「封禁」直接处置</span></div>'
        . '<div id="owumTopIp"><div class="owum-empty">加载中…</div></div>'
        . '</div>'

        // 归属地解析
        . '<div class="ow-card">'
        . '<div class="ow-list-head"><span class="ow-list-head-t">归属地解析</span>'
        . '<span class="owum-head-sub" id="owumGeoPend"></span></div>'
        . '<div class="ow-switch-row">'
        . '<span class="ow-switch-label">解析登录 IP 归属地</span>'
        . '<input type="checkbox" class="ow-switch-input" id="owumGeoOn"' . ($geo['on'] ? ' checked' : '') . '>'
        . '<label class="ow-switch' . ($geo['on'] ? ' is-on' : '') . '" for="owumGeoOn"></label>'
        . '<p class="ow-switch-hint">关闭后不再查询，已缓存的归属地照常显示；不影响登录记录与封禁。</p>'
        . '</div>'
        . '<div class="owum-geoform">'
        . '<input class="ow-input owum-mono" id="owumGeoApi" value="' . Sec::e($geo['api']) . '" maxlength="300" placeholder="https://api.ip.sb/geoip/{ip}">'
        . '<button class="ow-btn ow-btn-ghost" onclick="OwUM.geoSave()">保存</button>'
        . '<button class="ow-btn ow-btn-ghost" onclick="OwUM.geoRun()">立即解析</button>'
        . '</div>'
        . '<p class="ow-form-hint">接口地址用 <b>{ip}</b> 表示待查地址，返回 JSON 即可（自动识别 country / region / city / isp 等常见字段）。留空用默认接口。</p>'
        . '</div>'

        . '</div>';
});

/* ===================== 路由：用户查询 / 编辑（原有能力） ===================== */

/* ---------- 搜索：仅数字用户 ID 精确查询（原 admin_users） ---------- */
Plugin::route('plugin_user_manager_search', function (array $ctx) use ($umGuard) {
    $umGuard($ctx);
    $q = trim((string)($ctx['post']['q'] ?? ''));
    if ($q === '') Api::json(['ok' => true, 'data' => [], 'hint' => '请输入用户 ID']);
    if (!preg_match('/^\d{1,19}$/', $q)) Api::json(['ok' => false, 'msg' => '用户搜索仅支持数字用户 ID']);
    $rows = DB::all("SELECT id,nickname,email,role,title,points,status,created_at,last_login,last_login_ip FROM users
        WHERE id=? LIMIT 1", [(int)$q]);
    // v1.2.43：带出等级（若安装了「等级信任」插件）。
    // 用钩子而不是直接查 plugin_level_trust 表 —— 本插件不该知道别的插件的表结构，
    // 也不该在对方未安装时报「表不存在」。哨兵值 -1 = 无等级体系，前端据此隐藏该列。
    foreach ($rows as &$u) {
        $lv = -1;
        Plugin::fire('user.level.get', [&$lv, (int)$u['id']]);
        $u['level'] = $lv;
    }
    unset($u);
    Api::json(['ok' => true, 'data' => $rows]);
});

/* ---------- 用户概览（v1.2.56）：详情弹窗数据 ----------
   与 search 的区别：带 avatar / status / reg_ip / last_login_ip 等概览字段。
   只读接口，不做任何修改 → 不进 sensitive，不消耗一次性票据。

   v1.1.1：头像改由**核心**算地址。原来只取 users.avatar，而生成式头像
   （avatar_type='generated'，占绝大多数用户）在 users.avatar 里是空串 ——
   插件就退化成「首字 + 色块」，站长看到的是个紫色的 O。
   正确做法是 `Auth::avatarUrlFor($row, uid)`：上传图 / dicebear / 内置 identicon
   三种来源统一分流，还顺带触发 avatar.url 钩子（云存储插件靠它映射到对象存储）。 */
Plugin::route('plugin_user_manager_detail', function (array $ctx) use ($umGuard) {
    $umGuard($ctx);
    $id = (int)($ctx['post']['id'] ?? 0);
    if ($id <= 0) Api::json(['ok' => false, 'msg' => '非法用户']);
    $u = DB::one('SELECT id,nickname,email,role,title,status,points,
        avatar,avatar_type,avatar_style,avatar_seed,created_at,reg_ip,last_login,last_login_ip
        FROM users WHERE id=?', [$id]);
    if (!$u) Api::json(['ok' => false, 'msg' => '用户不存在'], 404);
    $u['avatar_url'] = Auth::avatarUrlFor($u, (string)$id);
    // 等级：与 search 同一口径 —— 走钩子问「等级信任」插件，-1 = 无等级体系
    $lv = -1;
    Plugin::fire('user.level.get', [&$lv, $id]);
    $u['level'] = $lv;
    Api::json(['ok' => true, 'data' => $u]);
});

/* ---------- 保存：角色 / 称号 / 积分（原 admin_user_set） ---------- */
Plugin::route('plugin_user_manager_save', function (array $ctx) use ($umGuard) {
    $umGuard($ctx);
    $post = $ctx['post'];
    $actor = $ctx['actor'];
    $id = (int)($post['id'] ?? 0);
    if ($id <= 0) Api::json(['ok' => false, 'msg' => '非法用户']);
    // 锁死超级管理员（uid=1）：用户组不可改动，否则系统将失去唯一超管（v1.0.88）
    if ($id === 1 && ($post['role'] ?? '') !== 'admin') {
        Sec::log('admin_user_lock', $actor['nickname'], ['id' => $id]);
        Api::json(['ok' => false, 'msg' => '超级管理员（1 号账号）用户组已被锁定，不可修改']);
    }
    $role = (string)($post['role'] ?? '');
    if (!in_array($role, ['member', 'vip', 'admin'], true)) Api::json(['ok' => false, 'msg' => '非法角色']);
    $title = (string)($post['title'] ?? '');
    $hasPts = isset($post['points']) && $post['points'] !== '';
    $pts = $hasPts ? (int)$post['points'] : 0;
    $old = 0;
    if ($hasPts) $old = (int)DB::val('SELECT points FROM users WHERE id=?', [$id]);

    // v1.5.22：用户组/称号 + 历史消息快照 + 积分三处写入用事务包住，避免改了一半。
    DB::tx(function () use ($role, $title, $id, $hasPts, $pts, $actor) {
        DB::run('UPDATE users SET role=?, title=? WHERE id=?', [$role, $title, $id]);
        // 历史消息里的角色/称号为发送时快照，一并刷新（v1.0.39 语义随功能迁移）
        DB::run('UPDATE messages SET role=?, title=? WHERE user_id=?', [$role, $title, $id]);
        if ($hasPts) {
            DB::run('UPDATE users SET points=? WHERE id=?', [$pts, $id]);
            Sec::log('admin_user_points', $actor['nickname'], ['id' => $id, 'points' => $pts]);
        }
    });
    // 账上多出来的部分要通知本人（v1.0.2）：后台改积分是管理员单方面动作，
    // 用户端在此之前没有任何感知。只在**增加**时发 —— 扣减不是「获得」，同值更不该刷一条。
    // 通知放在事务提交之后：通知失败不该回滚已保存的用户资料。
    if ($hasPts && $pts > $old) {
        Notice::push($id, 'um_points_grant', [
            'points' => $pts - $old, 'balance' => $pts, 'unit' => ow_points_name(),
        ]);
    }
    // v1.2.43：等级编辑（依赖「等级信任」插件；未安装时钩子无人响应，$ok 保持 null）。
    // ⚠️ 必须区分「未安装」与「设置失败」：前者提示去装插件，后者才是真失败。
    //    一律返回「已更新」会让管理员以为等级改成功了，实际没动。
    if (isset($post['level']) && $post['level'] !== '') {
        $lv = (int)$post['level'];
        if ($lv < 1) Api::json(['ok' => false, 'msg' => '等级需为 ≥1 的整数']);
        $lok = null; $lmsg = '';
        Plugin::fire('user.level.set', [$id, $lv, &$lok, &$lmsg]);
        if ($lok === null) {
            Api::json(['ok' => false, 'msg' => '未安装或未启用「等级信任」插件，无法编辑等级']);
        }
        if ($lok !== true) Api::json(['ok' => false, 'msg' => $lmsg !== '' ? $lmsg : '等级设置失败']);
        Sec::log('admin_user_level', $actor['nickname'], ['id' => $id, 'level' => $lv]);
    }
    Sec::log('admin_user_set', $actor['nickname'], ['id' => $id, 'role' => $role]);
    Api::json(['ok' => true, 'msg' => '已更新']);
});
Plugin::sensitive('plugin_user_manager_save');   // 改用户组/积分：敏感（v1.0.91）

/* ---------- 禁用 / 启用（原 admin_user_status） ---------- */
Plugin::route('plugin_user_manager_status', function (array $ctx) use ($umGuard) {
    $umGuard($ctx);
    $id = (int)($ctx['post']['id'] ?? 0);
    $status = (int)($ctx['post']['status'] ?? 1) === 1 ? 1 : 0;
    // 超管不可禁用：禁用后系统失去唯一超管，后台将无人可用（v1.0.88）
    if ($id === 1 && $status !== 1) {
        Api::json(['ok' => false, 'msg' => '超级管理员（1 号账号）不可禁用']);
    }
    DB::run('UPDATE users SET status=? WHERE id=?', [$status, $id]);
    // v1.3.52：封禁/解封要让用户本人知道。被禁用的人登不进来，看不到这条 ——
    // 但解封后他会进站，那条 banned 记录连同 unbanned 一起出现在通知里，
    // 正好构成一段完整的账号处置留痕。
    Notice::push($id, $status === 1 ? 'unbanned' : 'banned');
    Api::json(['ok' => true, 'msg' => '已更新']);
});
Plugin::sensitive('plugin_user_manager_status');   // 禁用账号：敏感（v1.0.91）

/* ===================== 路由：登录网络（v1.1.0） ===================== */

/**
 * 安全画像：一个用户的登录网络全貌。
 *
 * 一次请求拿齐：聚合行 + 归属地（批量 IN）+ 同 IP 的其他账号（批量 IN）+ 封禁态。
 * 归属地**不在这个请求里同步解析**（外部 HTTP 会把画像拖慢到几秒），
 * 缺的都标 pending，前端随后单独打 plugin_user_manager_geo 补 —— 面板先出来再说。
 */
Plugin::route('plugin_user_manager_net', function (array $ctx) use ($umGuard) {
    $umGuard($ctx);
    $id = (int)($ctx['post']['id'] ?? 0);
    if ($id <= 0) Api::json(['ok' => false, 'msg' => '非法用户']);
    $u = DB::one('SELECT id,nickname,avatar,avatar_type,avatar_style,avatar_seed,
        role,status,reg_ip,last_login,last_login_ip,created_at
        FROM users WHERE id=?', [$id]);
    if (!$u) Api::json(['ok' => false, 'msg' => '用户不存在'], 404);

    $rows = DB::all('SELECT ip,first_at,last_at,ok_n,fail_n,ua,kind FROM plugin_user_manager_ip
        WHERE uid=? ORDER BY last_at DESC LIMIT 60', [$id]);

    // 注册 IP / 最后登录 IP 可能没进聚合表（插件启用前的历史、或换了 IP 才登录过一次），
    // 这里补成只读行，保证「至少看得到这两个地址」—— 没有次数就是 0，不编造。
    $seen = [];
    foreach ($rows as $r) $seen[(string)$r['ip']] = true;
    $extra = [];
    foreach ([['ip' => (string)($u['last_login_ip'] ?? ''), 'kind' => 'last'], ['ip' => (string)($u['reg_ip'] ?? ''), 'kind' => 'reg']] as $e) {
        $ip = owumNormIp($e['ip']);
        if ($ip === '' || isset($seen[$ip])) continue;
        $seen[$ip] = true;
        $extra[] = [
            'ip' => $ip, 'first_at' => 0, 'last_at' => $e['kind'] === 'last' ? (int)$u['last_login'] : (int)$u['created_at'],
            'ok_n' => 0, 'fail_n' => 0, 'ua' => '', 'kind' => $e['kind'],
        ];
    }
    $rows = array_merge($rows, $extra);

    $ips = array_values(array_unique(array_column($rows, 'ip')));
    $geo = owumGeoMap($ips);
    $bans = owumBans();
    $now = time();
    $hasApi = owumGeoCfg()['on'];

    $list = [];
    $sumOk = $sumFail = 0;
    $uas = [];
    $firstAt = 0;
    $lastAt = 0;
    $pending = [];
    foreach ($rows as $r) {
        $ip = (string)$r['ip'];
        $label = '';
        if (owumIsLan($ip)) $label = '内网地址';
        elseif (isset($geo[$ip]) && $geo[$ip] !== '') $label = $geo[$ip];
        elseif ($hasApi) $pending[] = $ip;

        $hit = null;
        foreach ($bans as $b) {
            $exp = (int)$b['expires_at'];
            if ($exp > 0 && $exp <= $now) continue;
            if (owumIpMatch((string)$b['pattern'], $ip)) { $hit = $b; break; }
        }

        $ok = (int)$r['ok_n'];
        $fail = (int)$r['fail_n'];
        $sumOk += $ok;
        $sumFail += $fail;
        if ($r['ua'] !== '') $uas[mb_substr((string)$r['ua'], 0, 90)] = true;
        $fa = (int)$r['first_at'];
        if ($fa > 0 && ($firstAt === 0 || $fa < $firstAt)) $firstAt = $fa;
        $la = (int)$r['last_at'];
        if ($la > $lastAt) $lastAt = $la;

        $list[] = [
            'ip' => $ip, 'geo' => $label,
            'first_at' => $fa, 'last_at' => $la,
            'ok_n' => $ok, 'fail_n' => $fail, 'ua' => (string)$r['ua'], 'kind' => (string)$r['kind'],
            'banned' => $hit !== null,
            'ban_note' => $hit ? (string)$hit['note'] : '',
            'ban_exp' => $hit ? (int)$hit['expires_at'] : 0,
        ];
    }

    // 同 IP 的其他账号：反查聚合表（一条 GROUP BY，不在循环里查）
    $shared = [];
    if ($ips) {
        $chunks = array_chunk($ips, 200);
        foreach ($chunks as $chunk) {
            [$in, $args] = DB::in('ip', $chunk);
            $srows = DB::all("SELECT uid,ip,SUM(ok_n) o,SUM(fail_n) f,MAX(last_at) t
                FROM plugin_user_manager_ip WHERE uid>0 AND uid<>? AND $in
                GROUP BY uid,ip ORDER BY t DESC LIMIT 40", array_merge([$id], $args));
            $uids = array_map('intval', array_column($srows, 'uid'));
            $names = [];
            if ($uids) {
                [$in2, $a2] = DB::in('id', array_values(array_unique($uids)));
                foreach (DB::all("SELECT id,nickname,avatar,avatar_type,avatar_style,avatar_seed
                    FROM users WHERE $in2", $a2) as $ur) {
                    $names[(int)$ur['id']] = [
                        'nickname' => (string)$ur['nickname'],
                        // 头像同样走核心分流（上传图 / dicebear / identicon）
                        'avatar' => Auth::avatarUrlFor($ur, (string)$ur['id']),
                    ];
                }
            }
            foreach ($srows as $sr) {
                $info = $names[(int)$sr['uid']] ?? ['nickname' => '#' . (int)$sr['uid'], 'avatar' => ''];
                $shared[] = [
                    'uid' => (int)$sr['uid'],
                    'nickname' => $info['nickname'],
                    'avatar' => $info['avatar'],
                    'ip' => (string)$sr['ip'],
                    'ok_n' => (int)$sr['o'], 'fail_n' => (int)$sr['f'],
                    'last_at' => (int)$sr['t'],
                ];
            }
        }
    }

    Api::json(['ok' => true, 'data' => [
        'user' => [
            'id' => (int)$u['id'], 'nickname' => (string)$u['nickname'],
            'avatar_url' => Auth::avatarUrlFor($u, (string)$id),
            'role' => (string)$u['role'],
            'status' => (int)$u['status'],
        ],
        'summary' => [
            'ok_n' => $sumOk, 'fail_n' => $sumFail,
            'ip_n' => count(array_filter($ips, fn($v) => $v !== '')),
            'ua_n' => count($uas),
            'first_at' => $firstAt, 'last_at' => $lastAt,
            'reg_ip' => (string)($u['reg_ip'] ?? ''),
            'last_login' => (int)$u['last_login'],
            'last_login_ip' => (string)($u['last_login_ip'] ?? ''),
        ],
        'ips' => $list,
        'shared' => array_slice($shared, 0, 20),
        'pending' => array_values(array_unique($pending)),
        'geo_on' => $hasApi,
        // 当前请求的出口 IP：前端据此把「封自己」的入口直接关掉（服务端也会拒）
        'my_ip' => Sec::ip(),
    ]]);
});

/**
 * 补解析归属地：只处理传进来的 IP（或该用户的 IP），最多 6 个、每个 3 秒。
 * 管理员点开画像后前端自动打一次，不做成同步 —— 面板先渲染，色块随后填。
 */
Plugin::route('plugin_user_manager_geo', function (array $ctx) use ($umGuard) {
    $umGuard($ctx);
    $cfg = owumGeoCfg();
    if (!$cfg['on']) Api::json(['ok' => false, 'msg' => '归属地解析已关闭']);
    $id = (int)($ctx['post']['id'] ?? 0);
    $scope = [];
    if ($id > 0) {
        $scope = array_column(DB::all('SELECT ip FROM plugin_user_manager_ip WHERE uid=? ORDER BY last_at DESC LIMIT 30', [$id]), 'ip');
        $u = DB::one('SELECT reg_ip,last_login_ip FROM users WHERE id=?', [$id]);
        if ($u) { $scope[] = (string)$u['reg_ip']; $scope[] = (string)$u['last_login_ip']; }
    }
    $todo = owumGeoPending($scope, 6);
    if (!$todo) Api::json(['ok' => true, 'geo' => [], 'left' => 0, 'msg' => '没有待解析的地址']);
    $got = owumGeoResolveAll($todo, 6, 3);
    $left = count(owumGeoPending($scope, 50));
    Api::json(['ok' => true, 'geo' => $got, 'left' => $left,
        'msg' => $got ? ('已解析 ' . count($got) . ' 个地址') : '接口没有返回结果，请检查接口地址']);
});

/* ===================== 路由：IP 封禁（v1.1.0） ===================== */

/** 封禁列表 + 高风险 IP + 统计，一次拉齐（IP 封禁面板用） */
Plugin::route('plugin_user_manager_ban_list', function (array $ctx) use ($umGuard) {
    $umGuard($ctx);
    $now = time();
    $bans = DB::all('SELECT pattern,note,by_name,created_at,expires_at FROM plugin_user_manager_ipban
        ORDER BY created_at DESC LIMIT 200');
    $active = 0;
    foreach ($bans as &$b) {
        $exp = (int)$b['expires_at'];
        $b['expires_at'] = $exp;
        $b['alive'] = $exp === 0 || $exp > $now;
        if ($b['alive']) $active++;
    }
    unset($b);

    // 高风险 IP：跨账号汇总失败次数（一条 GROUP BY；HAVING 三驱动都支持）
    $top = DB::all("SELECT ip,SUM(fail_n) f,SUM(ok_n) o,MAX(last_at) t
        FROM plugin_user_manager_ip WHERE ip<>'' GROUP BY ip HAVING SUM(fail_n)>0
        ORDER BY f DESC LIMIT 10");
    $geo = owumGeoMap(array_column($top, 'ip'));
    foreach ($top as &$t) {
        $t['geo'] = owumIsLan((string)$t['ip']) ? '内网地址' : ($geo[(string)$t['ip']] ?? '');
        $t['banned'] = false;
        foreach ($bans as $b) {
            if (!$b['alive']) continue;
            if (owumIpMatch((string)$b['pattern'], (string)$t['ip'])) { $t['banned'] = true; break; }
        }
    }
    unset($t);

    // 待解析数：从没查过的 + 查过但结果为空且已过 7 天的（与 owumGeoPending 同一口径）
    $cfg = owumGeoCfg();
    $pend = 0;
    try {
        $pend = (int)DB::val("SELECT COUNT(DISTINCT i.ip) FROM plugin_user_manager_ip i
            LEFT JOIN plugin_user_manager_geo g ON g.ip=i.ip
            WHERE i.ip<>'' AND (g.ip IS NULL OR (g.label='' AND g.at<?))", [time() - 7 * 86400]);
    } catch (Throwable $e) {
        $pend = 0;
    }

    Api::json(['ok' => true, 'data' => [
        'bans' => $bans, 'top' => $top, 'total' => count($bans), 'active' => $active,
        'geo' => ['on' => $cfg['on'], 'api' => $cfg['api'], 'pending' => $pend],
        'my_ip' => Sec::ip(),
    ]]);
});

/** 封禁：pattern 支持通配；拒绝封禁自己当前 IP（防盗锁） */
Plugin::route('plugin_user_manager_ban_add', function (array $ctx) use ($umGuard) {
    $umGuard($ctx);
    $post = $ctx['post'];
    $actor = $ctx['actor'];
    $pattern = strtolower(trim((string)($post['pattern'] ?? '')));
    $note = trim((string)($post['note'] ?? ''));
    $days = (int)($post['days'] ?? 0);
    if ($pattern === '' || strlen($pattern) > 64) Api::json(['ok' => false, 'msg' => '请输入 IP 地址']);
    if (!preg_match('/^[0-9a-f:.*]+$/', $pattern)) Api::json(['ok' => false, 'msg' => 'IP 只能是数字、点、冒号和 *']);
    if (strpos($pattern, '*') === false && !filter_var($pattern, FILTER_VALIDATE_IP)) {
        Api::json(['ok' => false, 'msg' => 'IP 地址格式不正确']);
    }
    $myIp = Sec::ip();
    if (owumIpMatch($pattern, $myIp)) {
        Api::json(['ok' => false, 'msg' => '不能封禁你当前使用的 IP（' . $myIp . '），换一条更精确的地址']);
    }
    if ($days < 0) $days = 0;
    if ($days > 3650) $days = 3650;
    $now = time();
    // v1.5.22：封禁行 + 审计日志同一事务
    DB::tx(function () use ($pattern, $note, $actor, $now, $days) {
        DB::upsert('plugin_user_manager_ipban', [
            'pattern' => $pattern,
            'note' => mb_substr($note, 0, 180),
            'by_uid' => (int)($actor['id'] ?? 0),
            'by_name' => mb_substr((string)($actor['nickname'] ?? ''), 0, 60),
            'created_at' => $now,
            'expires_at' => $days > 0 ? $now + $days * 86400 : 0,
        ], ['pattern']);
        Sec::log('owum_ipban_add', $actor['nickname'], ['pattern' => $pattern, 'days' => $days]);
    });
    Api::json(['ok' => true, 'msg' => '已封禁 ' . $pattern . ($days > 0 ? '（' . $days . ' 天）' : '（永久）')]);
});
Plugin::sensitive('plugin_user_manager_ban_add');

/** 解封 */
Plugin::route('plugin_user_manager_ban_del', function (array $ctx) use ($umGuard) {
    $umGuard($ctx);
    $pattern = strtolower(trim((string)($ctx['post']['pattern'] ?? '')));
    if ($pattern === '') Api::json(['ok' => false, 'msg' => '参数错误']);
    // v1.5.22：解封 + 审计日志同一事务
    DB::tx(function () use ($pattern, $ctx) {
        DB::run('DELETE FROM plugin_user_manager_ipban WHERE pattern=?', [$pattern]);
        Sec::log('owum_ipban_del', $ctx['actor']['nickname'], ['pattern' => $pattern]);
    });
    Api::json(['ok' => true, 'msg' => '已解封 ' . $pattern]);
});
Plugin::sensitive('plugin_user_manager_ban_del');

/** 保存归属地配置 */
Plugin::route('plugin_user_manager_geo_save', function (array $ctx) use ($umGuard) {
    $umGuard($ctx);
    $on = (string)($ctx['post']['on'] ?? '0') === '1' ? '1' : '0';
    $api = trim((string)($ctx['post']['api'] ?? ''));
    if ($api === '') $api = OWUM_GEO_DEFAULT_API;
    if (!owumGeoApiOk($api)) {
        Api::json(['ok' => false, 'msg' => '接口地址必须含 {ip}，且是有效的 http/https 地址，不能指向本机或内网']);
    }
    DB::setSettings(['owum_geo_on' => $on, 'owum_geo_api' => $api]);
    Sec::log('owum_geo_save', $ctx['actor']['nickname'], ['on' => $on]);
    Api::json(['ok' => true, 'msg' => '已保存']);
});

/** 手动解析全局待处理的 IP（一次最多 8 个，前端有按钮触发） */
Plugin::route('plugin_user_manager_geo_run', function (array $ctx) use ($umGuard) {
    $umGuard($ctx);
    $cfg = owumGeoCfg();
    if (!$cfg['on']) Api::json(['ok' => false, 'msg' => '归属地解析已关闭']);
    $todo = owumGeoPending([], 8);
    if (!$todo) Api::json(['ok' => true, 'msg' => '没有待解析的地址', 'left' => 0]);
    $got = owumGeoResolveAll($todo, 8, 3);
    Api::json(['ok' => true, 'msg' => '已解析 ' . count($got) . ' 个地址', 'left' => count(owumGeoPending([], 50))]);
});

/* ===================== 钩子：记录 + 拦截 ===================== */

/**
 * 登录成功：用户 + IP + 设备。
 * $user 是完整 users 行；$ctx['ip'] 已由核心按 Sec::ip() 取出（只认 REMOTE_ADDR）。
 */
Plugin::on('login.after_verify', function ($user, $method, $ctx) {
    if (!is_array($user) || empty($user['id'])) return;
    owumTouchIp((int)$user['id'], (string)($ctx['ip'] ?? ''), 'login',
        (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
});

/**
 * 登录失败：**密码错时 $info['user'] 为 null**（核心防账号枚举），
 * 这类只记在 uid=0 上 —— 不知道是谁，但知道哪个 IP 在撞库。
 */
Plugin::on('login.failed', function ($info) {
    if (!is_array($info)) return;
    $u = $info['user'] ?? null;
    $uid = is_array($u) ? (int)($u['id'] ?? 0) : 0;
    $kind = (string)($info['reason'] ?? 'fail') === 'locked' ? 'locked' : 'fail';
    owumTouchIp($uid, (string)($info['ip'] ?? ''), $kind,
        (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
});

/** 主动登出：只补一个「最近一次活动时间」，不额外记一行（聚合行已存在） */
Plugin::on('logout.before_destroy', function ($info) {
    if (!is_array($info) || empty($info['had_uid'])) return;   // 游客登出不记
    $uid = (int)($info['uid'] ?? 0);
    $ip = owumNormIp((string)($info['ip'] ?? ''));
    if ($uid <= 0 || $ip === '') return;
    try {
        DB::run('UPDATE plugin_user_manager_ip SET last_at=? WHERE uid=? AND ip=?', [time(), $uid, $ip]);
    } catch (Throwable $e) {
        // 留痕失败不影响登出
    }
});

/**
 * IP 封禁拦截。
 *
 * 为什么挂在 request.start：它是核心唯一「每个请求都跑、DB 已就绪、$actor 已解析、
 * 尚未输出任何字节」的入口。别的钩子都太晚（页面已经开始吐 HTML）或太窄（只管发消息）。
 *
 * 放行管理员：封禁表里的一条记录可能正好覆盖管理员自己的出口 IP，
 * 那时若连管理员一起拦，后台就再也进不去了 —— 留着这条后门比锁死现场好。
 */
Plugin::on('request.start', function ($actor) {
    if (PHP_SAPI === 'cli') return;
    if (($actor['role'] ?? '') === 'admin') return;
    $ip = Sec::ip();
    $hit = owumBanHit($ip);
    if (!$hit) return;

    $msg = '当前网络地址已被本站限制访问';
    $isApi = isset($_GET['action']) || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== '';
    if ($isApi) {
        Api::json(['ok' => false, 'msg' => $msg], 403);
    }
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo owumBlockHtml($ip, $hit);
    exit;
});

/**
 * 拦截页。
 *
 * 这里**已经离开了核心的渲染流程**（request.start 时就 exit 了），所以自己输出整份 HTML：
 * 引核心样式表拿 --ow-* 令牌 + 内联一小段主题预置脚本，深浅色就都能跟着走，
 * 不需要在插件里写第二套颜色。
 */
function owumBlockHtml(string $ip, array $hit): string
{
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($base === '.' || $base === '\\') $base = '';
    $note = trim((string)($hit['note'] ?? ''));
    $exp = (int)($hit['expires_at'] ?? 0);
    $expTxt = $exp > 0 ? date('Y-m-d H:i', $exp) : '永久';

    return '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>访问已被限制</title>'
        . '<script>(function(){try{var m=localStorage.getItem("owl_theme");'
        . 'if(m!=="light"&&m!=="dark"&&m!=="auto")m="auto";'
        . 'var d=(m==="auto")?(window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light"):m;'
        . 'document.documentElement.setAttribute("data-theme",d);'
        . 'document.documentElement.style.colorScheme=d;}catch(e){}})();</script>'
        . '<link rel="stylesheet" href="' . Sec::e($base) . '/index.css?v=' . Sec::e(OWLSGO_ASSET_VERSION) . '">'
        . '<style>'
        . 'html,body{height:100%}'
        . 'body{display:flex;align-items:center;justify-content:center;padding:24px;'
        . 'background:var(--ow-bg-chat);color:var(--ow-text);font-size:14px;line-height:1.6}'
        . '.owum-blk{width:100%;max-width:420px;background:var(--ow-bg);border:1px solid var(--ow-border);'
        . 'border-radius:14px;padding:28px 24px;text-align:center}'
        . '.owum-blk-ico{display:inline-flex;align-items:center;justify-content:center;width:52px;height:52px;'
        . 'border-radius:50%;background:var(--ow-warn-bg);color:var(--ow-red);margin-bottom:14px}'
        . '.owum-blk h1{font-size:18px;font-weight:600;color:var(--ow-text-title);margin:0 0 6px}'
        . '.owum-blk p{margin:0 0 4px;color:var(--ow-text-sub);font-size:13px}'
        . '.owum-blk dl{display:grid;grid-template-columns:auto 1fr;gap:8px 12px;margin:18px 0 0;'
        . 'padding:14px 16px;background:var(--ow-bg-sub);border-radius:10px;text-align:left;font-size:13px}'
        . '.owum-blk dt{color:var(--ow-text-sub)}'
        . '.owum-blk dd{margin:0;color:var(--ow-text-title);word-break:break-all}'
        . '.owum-mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}'
        . '</style></head><body><div class="owum-blk">'
        . '<div class="owum-blk-ico">' . ow_icon('ban', 24) . '</div>'
        . '<h1>访问已被限制</h1>'
        . '<p>本站已停止向这个网络地址提供服务。</p>'
        . '<dl>'
        . '<dt>网络地址</dt><dd class="owum-mono">' . Sec::e($ip) . '</dd>'
        . ($note !== '' ? '<dt>原因</dt><dd>' . Sec::e($note) . '</dd>' : '')
        . '<dt>解除时间</dt><dd>' . Sec::e($expTxt) . '</dd>'
        . '</dl>'
        . '<p style="margin-top:16px">如果你认为这是误判，请联系站点管理员。</p>'
        . '</div></body></html>';
}

/**
 * 核心的「查 IP 归属地」（?action=ip_loc）：v1.0.55 起核心不再内置实现，
 * 一直没人应答 → 管理员点消息里的 IP 只能看到「未安装归属地查询插件」。
 * 这里顺手把它接上：先读缓存，没有再实时查一次（3 秒超时）。
 */
Plugin::on('ip.location', function (&$loc, $ip) {
    if (!is_string($ip) || $ip === '' || $loc !== '') return;
    $cfg = owumGeoCfg();
    $label = owumGeoOf($ip);
    if ($label === '' && $cfg['on']) $label = owumGeoResolveOne($ip, 3);
    if ($label !== '') $loc = $label;
});

/**
 * 计划任务：慢速消化待解析队列。
 * 每轮只解 3 个（每个 3 秒超时）—— 它跑在长轮询请求里，一次解太多会把那次轮询拖到超时。
 */
Plugin::cron('ip_geo', 900, function (): string {
    $cfg = owumGeoCfg();
    if (!$cfg['on']) return '归属地解析已关闭，跳过';
    $todo = owumGeoPending([], 3);
    if (!$todo) return '没有待解析的地址';
    $got = owumGeoResolveAll($todo, 3, 3);
    return '本次解析 ' . count($got) . ' / ' . count($todo) . ' 个地址';
}, '用户管理：解析登录 IP 归属地（每 15 分钟最多 3 个）');

// 注册后台交互脚本（由 ?action=assets&type=js 合并输出，仅后台页面引入）
Plugin::asset('js', 'user-manager/admin.js', 'admin');

// v1.5.31：样式改由核心 pageHead 统一引整包 —— 整包 URL 带 `t=<合并包内容指纹>`，
// 改本插件 CSS 会让指纹变化、URL 立刻变，比原来每个插件各发一条切片（首屏 16 个请求）
// 省得多，缓存效果一样（带 t= → 一年 immutable）。
Plugin::asset('css', 'user-manager/style.css');

/* ---------- 积分发放的通知模板（v1.0.2） ---------- */

/**
 * 规则：**只要用户账上的 {unit} 变多，就必须留一条系统通知**（PLUGIN.md「积分变动必须通知」）。
 * 后台改积分是管理员单方面动作，用户端没有别的渠道知道 —— 充值、活动奖励、补偿都走这条。
 * {unit} 由 push 时传 ow_points_name()，站点把「积分」改名成金币/星光时文案自动跟随。
 */
Plugin::on('notice.kinds', function (array &$kinds) {
    $kinds['um_points_grant'] = '管理员为你发放了 {points} {unit}，当前余额 {balance} {unit}。';
});
