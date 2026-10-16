<?php
/**
 * 链接卡片插件（link-card v1.1.0）
 *
 * ── 两个方向 ──────────────────────────────────────────────────
 *   A. 站点分享卡片（v1.0.0）——「本站链接被别人分享时显示什么」
 *      后台维护若干张卡片，page.head 按平台开关输出 meta：
 *        Open Graph / X（Twitter）/ 微信（wx.share.*）/ QQ（qq:share:*）
 *   B. 消息链接卡片（v1.1.0）——「消息里的链接显示什么」
 *      消息中出现链接时，服务器读取目标页的 Open Graph 信息，
 *      在消息下方渲染预览卡。后台可总开关，可维护域名黑名单
 *      （名单内域名**不抓取、不出卡**）。抓取结果入库缓存。
 *
 * ── 出网安全（B 的核心风险）───────────────────────────────────
 *   目标 URL 由**普通用户**提供，属典型 SSRF 面。防护见 owLCFetchPage()：
 *   仅 http/https、仅默认端口、禁 URL 内认证信息、DNS 解析后逐个校验是否为公网 IP、
 *   用 CURLOPT_RESOLVE 把校验过的 IP 钉死在本次连接上（堵 DNS Rebinding）、
 *   不自动跟随跳转（逐跳人工重校验，最多 2 跳）、超时与响应体上限、仅接受 text/html，
 *   外加按 IP 的频率限制与域名黑名单。没有 cURL 时**拒绝抓取**（fail-closed）。
 *
 * 约束：
 *   - 兼容 SQLite / MySQL / PostgreSQL（自增主键走 DB::autoId()，长文本走 DB::textCol()）。
 *   - 全部 SQL 参数化；main.php 顶层只做 Plugin::* 注册，懒建表见 owLCBoot() / owLCPreviewBoot()。
 *   - 样式只引用核心 --ow-* 变量（style.css），深浅两套主题自动跟随。
 *   - og:image 必须是 https:// 开头的可公开访问绝对地址（保存与抓取两侧都校验）。
 */
if (!defined('OWLSGO_VERSION')) exit;   // 禁止直接 HTTP 访问本文件

/** 响应体上限：既限制抓取多少字节用于解析 meta，也不让远端用超大响应拖住我们 */
const OWLC_MAX_BYTES = 262144;          // 256 KB
/** 单条消息最多出几张卡（控制单次请求耗时与消息区长度） */
const OWLC_MAX_PER_MSG = 1;
/** 一次请求最多抓几个 URL（客户端分批请求，避免一个请求拖太久） */
const OWLC_MAX_PER_CALL = 2;
/** 缓存有效期：成功 7 天；失败 1 小时（不做无谓重试，也不会长期卡在失败态） */
const OWLC_TTL_OK   = 604800;
const OWLC_TTL_FAIL = 3600;

/* ============================ 建表 ============================ */

/** 懒建表：站点分享卡片 + 消息预览缓存（只在真正读写数据时执行） */
function owLCBoot(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $id = DB::autoId();
    DB::run("CREATE TABLE IF NOT EXISTS plugin_link_card (
        id           {$id},
        name         VARCHAR(64)  NOT NULL DEFAULT '',   -- 卡片备注名（后台识别用，不进 meta）
        url          VARCHAR(512) NOT NULL DEFAULT '',   -- og:url 规范链接（绝对地址）
        title        VARCHAR(255) NOT NULL DEFAULT '',   -- 四平台通用标题
        description  " . DB::textCol('description') . ", -- 四平台通用描述（长文本列，方言由核心给）
        image        VARCHAR(512) NOT NULL DEFAULT '',   -- og:image 封面图（https:// 绝对地址）
        og_type      VARCHAR(16)  NOT NULL DEFAULT 'website',         -- og:type：website / article
        tw_card      VARCHAR(24)  NOT NULL DEFAULT 'summary_large_image', -- twitter:card：summary / summary_large_image
        p_og         INTEGER NOT NULL DEFAULT 0,         -- Open Graph 平台开关
        p_tw         INTEGER NOT NULL DEFAULT 0,         -- X（Twitter）平台开关
        p_wx         INTEGER NOT NULL DEFAULT 0,         -- 微信平台开关
        p_qq         INTEGER NOT NULL DEFAULT 0,         -- QQ 平台开关
        enabled      INTEGER NOT NULL DEFAULT 1,         -- 卡片启停
        sort         INTEGER NOT NULL DEFAULT 0,         -- 排序（小者在前，决定哪张先生效）
        created_at   INTEGER NOT NULL DEFAULT 0,
        updated_at   INTEGER NOT NULL DEFAULT 0
    )");

    owLCPreviewBoot();
}

/** 预览缓存表（单独一个函数：后台「清空缓存」等场景也会单独用到） */
function owLCPreviewBoot(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $id = DB::autoId();
    // ⚠️ 查表键必须是定长哈希，不能拿 url 本身建索引：MySQL 下 TEXT / 长 VARCHAR 建索引有字节上限
    //    （utf8mb4 767/3072），而 URL 长度本就不定。url_hash = md5(url)。
    DB::run("CREATE TABLE IF NOT EXISTS plugin_link_card_preview (
        id          {$id},
        url_hash    CHAR(32)     NOT NULL DEFAULT '',   -- md5(规范化后的 url)，唯一键
        url         VARCHAR(512) NOT NULL DEFAULT '',
        title       VARCHAR(255) NOT NULL DEFAULT '',
        description " . DB::textCol('description') . ",
        image       VARCHAR(512) NOT NULL DEFAULT '',
        site        VARCHAR(191) NOT NULL DEFAULT '',
        status      INTEGER NOT NULL DEFAULT 0,         -- 1 = 成功，0 = 失败（失败也缓存，短 TTL）
        reason      VARCHAR(191) NOT NULL DEFAULT '',   -- 失败原因（后台可见，便于排查）
        fetched_at  INTEGER NOT NULL DEFAULT 0
    )");
    // 唯一键：同一 URL 只留一行，并发下由数据库兜底（比先查后插可靠）
    DB::createIndex('idx_lc_preview_hash', 'plugin_link_card_preview', 'url_hash', true);
}

/* ==================== 站点分享卡片：校验助手 ==================== */

/** 绝对地址校验：http/https 且带主机名（og:url 用） */
function owLCValidUrl(string $u): bool
{
    if ($u === '' || mb_strlen($u) > 512) return false;
    if (!preg_match('#^https?://#i', $u)) return false;
    $p = parse_url($u);
    return !empty($p['host']) && in_array(strtolower((string)$p['scheme']), ['http', 'https'], true);
}

/** 封面图校验：https:// 绝对地址（OG 规范要求 og:image 可公开访问） */
function owLCValidImage(string $u): bool
{
    return owLCValidUrl($u) && stripos($u, 'https://') === 0;
}

/* ==================== 站点分享卡片：meta 输出 ==================== */

/** 取当前生效的卡片：启用中按排序最前的一张 */
function owLCActiveCard(): ?array
{
    return DB::one("SELECT * FROM plugin_link_card WHERE enabled=1 ORDER BY sort ASC, id ASC LIMIT 1");
}

/**
 * 输出启用平台的 meta 标签（page.head 钩子调用）。
 * 标题/链接必填恒输出；描述与封面图为空时跳过对应标签，避免空 content。
 */
function owLCMeta(): void
{
    $row = owLCActiveCard();
    if (!$row) return;

    $title = (string)$row['title'];
    $desc  = (string)($row['description'] ?? '');
    $image = (string)$row['image'];
    $url   = (string)$row['url'];
    $site  = (string)DB::setting('site_name', '');
    $m = [];   // [attr, name, content]

    if ((int)$row['p_og'] === 1) {
        $m[] = ['property', 'og:title', $title];
        if ($desc !== '')  $m[] = ['property', 'og:description', $desc];
        if ($image !== '') $m[] = ['property', 'og:image', $image];
        $m[] = ['property', 'og:url', $url];
        $m[] = ['property', 'og:type', (string)$row['og_type']];
        if ($site !== '') $m[] = ['property', 'og:site_name', $site];
    }
    if ((int)$row['p_tw'] === 1) {
        $m[] = ['name', 'twitter:card', (string)$row['tw_card']];
        $m[] = ['name', 'twitter:title', $title];
        if ($desc !== '')  $m[] = ['name', 'twitter:description', $desc];
        if ($image !== '') $m[] = ['name', 'twitter:image', $image];
    }
    if ((int)$row['p_wx'] === 1) {
        $m[] = ['name', 'wx.share.title', $title];
        if ($desc !== '')  $m[] = ['name', 'wx.share.desc', $desc];
        if ($image !== '') $m[] = ['name', 'wx.share.img', $image];
    }
    if ((int)$row['p_qq'] === 1) {
        $m[] = ['name', 'qq:share:title', $title];
        if ($desc !== '')  $m[] = ['name', 'qq:share:description', $desc];
        if ($image !== '') $m[] = ['name', 'qq:share:image', $image];
    }
    if (!$m) return;

    echo "<!-- link-card v1.1.0 -->\n";
    foreach ($m as $t) {
        echo '<meta ' . $t[0] . '="' . Sec::e($t[1]) . '" content="' . Sec::e($t[2]) . '">' . "\n";
    }
}

/* ==================== 消息预览：配置与黑名单 ==================== */

function owLCKeyMsgOn(): string   { return 'plugin_link_card_msg_on'; }
function owLCKeyBlocked(): string { return 'plugin_link_card_blocked'; }

/** 「消息链接卡片」总开关（默认开：插件启用即可用，管理员随时可关） */
function owLCmsgOn(): bool
{
    return DB::setting(owLCKeyMsgOn(), '1') === '1';
}

/**
 * 名单条目归一：管理员常直接粘一整条 URL（https://a.com/x），这里只取主机名。
 * ⚠️ 与 sensitive-words 的 owSPnormDomain 同一口径（站点里两处域名名单，规则必须一致），
 *    只多两条**容错**（两处都是「静默失效」的坑：后台看着配好了，实际一条都不命中）：
 *      · 开头的通配写法 `*.a.com` → 归一为 `a.com`（本插件本就按「精确或子域」匹配，
 *        前缀通配是多余的，但管理员习惯这么写，不能让它变成永不命中的规则）；
 *      · 两端多余的点 `.a.com.` → `a.com`（DNS 风格的写法）。
 *    没写协议时**不能**丢给 parse_url：它会把 "spam.com:8080" 里的 "spam.com" 当协议名，
 *    于是解析出 host=null，这条规则永远不生效、后台还看着像配好了。
 */
function owLCnormDomain(string $s): string
{
    $s = trim($s);
    if ($s === '') return '';
    if (strpos($s, '://') !== false) {
        return trim(strtolower((string)(parse_url($s, PHP_URL_HOST) ?: '')), '.');
    }
    $s = strtolower(explode('/', explode('?', $s)[0])[0]);
    $s = (string)preg_replace('/:\d+$/', '', $s);     // 去端口
    $s = (string)preg_replace('/^\*\./', '', $s);     // 去通配前缀
    return trim($s, '.');
}

/** 黑名单（设置项，每行一个域名；容忍逗号/分号分隔） */
function owLCBlockedList(): array
{
    $raw = (string)DB::setting(owLCKeyBlocked(), '');
    if (trim($raw) === '') return [];
    $out = [];
    foreach (preg_split('/[\r\n,;]+/', $raw) ?: [] as $d) {
        $n = owLCnormDomain($d);
        if ($n !== '' && !in_array($n, $out, true)) $out[] = $n;
    }
    return $out;
}

/**
 * 命中判定：精确相等，或是名单项的**子域**。
 * ⚠️ 不能写成 strpos($host, $d) !== false —— 那样「evil.com」会命中名单里的「vil.com」，
 *    反过来「notexample.com」也不会命中「example.com」但看着像，两边都要按点边界比。
 */
function owLCBlockHit(string $host, array $list): bool
{
    if ($host === '') return false;
    foreach ($list as $d) {
        if ($d === '') continue;
        if ($host === $d) return true;
        $suffix = '.' . $d;
        if (substr($host, -strlen($suffix)) === $suffix) return true;
    }
    return false;
}

/** 从 URL 取主机名（小写、去端口、去认证信息） */
function owLChost(string $url): string
{
    $h = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
    return rtrim($h, '.');
}

/* ==================== 消息预览：SSRF 硬化抓取 ==================== */

/**
 * 是否公网地址。只有返回 true 的 IP 才允许连接。
 * ⚠️ 不依赖 filter_var 的 NO_PRIV/NO_RES 单打独斗：它对 CGNAT(100.64/10)、
 *    IPv4-mapped IPv6、组播等的覆盖不全，而这些恰是探测内网最常用的入口，一律显式判。
 */
function owLCPublicIp(string $ip): bool
{
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) return false;

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
        $n = ip2long($ip);
        if ($n === false) return false;
        $bad = [
            ['0.0.0.0', '0.255.255.255'],         // 本网络
            ['10.0.0.0', '10.255.255.255'],       // 私网 A
            ['100.64.0.0', '100.127.255.255'],    // CGNAT
            ['127.0.0.0', '127.255.255.255'],     // 环回
            ['169.254.0.0', '169.254.255.255'],   // 链路本地（含云元数据 169.254.169.254）
            ['172.16.0.0', '172.31.255.255'],     // 私网 B
            ['192.0.0.0', '192.0.0.255'],         // IETF 协议保留
            ['192.0.2.0', '192.0.2.255'],         // TEST-NET-1
            ['192.168.0.0', '192.168.255.255'],   // 私网 C
            ['198.18.0.0', '198.19.255.255'],     // 基准测试
            ['198.51.100.0', '198.51.100.255'],   // TEST-NET-2
            ['203.0.113.0', '203.0.113.255'],     // TEST-NET-3
            ['224.0.0.0', '255.255.255.255'],     // 组播 + 保留
        ];
        foreach ($bad as $r) {
            if ($n >= ip2long($r[0]) && $n <= ip2long($r[1])) return false;
        }
        return true;
    }

    // IPv6：环回 / 唯一本地 / 链路本地 / 组播 / IPv4-mapped 一律拒
    $low = strtolower($ip);
    if ($low === '::' || $low === '::1') return false;
    $bin = @inet_pton($ip);
    if ($bin === false) return false;
    $hex = bin2hex($bin);
    if (strlen($hex) === 32) {
        $b0 = hexdec(substr($hex, 0, 2));
        $b1 = hexdec(substr($hex, 2, 2));
        if (($b0 & 0xfe) === 0xfc) return false;                  // fc00::/7 唯一本地
        if ($b0 === 0xfe && ($b1 & 0xc0) === 0x80) return false;   // fe80::/10 链路本地
        if ($b0 === 0xff) return false;                            // ff00::/8 组播
        if (substr($hex, 0, 24) === '00000000000000000000ffff') {  // ::ffff:a.b.c.d
            return owLCPublicIp(long2ip((int)hexdec(substr($hex, 24, 8))));
        }
    }
    return true;
}

/**
 * 函数真的可以调用吗（v1.2.0）。
 * ⚠️ function_exists 不够：共享主机常把 gethostbynamel / dns_get_record 放进
 *    disable_functions —— 那种情况下函数「存在」但一调用就是 Fatal error，
 *    预览接口直接 500（线上反馈过的那类环境差异）。这里把禁用名单也算上。
 */
function owLCCallable(string $fn): bool
{
    if (!function_exists($fn)) return false;
    $dis = ini_get('disable_functions');
    if ($dis === '' || $dis === false) return true;
    return !in_array($fn, array_map('trim', explode(',', (string)$dis)), true);
}

/**
 * 解析主机名并校验：返回可用公网 IP 列表；**任一**解析结果为内网即整体拒绝
 * （多 A 记录里混一个内网地址，是绕过单点校验的常见手法）。
 * ⚠️ DNS 函数在共享主机常被 disable_functions 禁用 —— 不可用就当「解析不了」处理
 *    （fail-closed：宁可不出卡，也不能跳过 IP 校验直接连）。
 */
function owLCResolveHost(string $host): array
{
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        $ips[] = $host;
    } else {
        if (owLCCallable('gethostbynamel')) {
            $a = @gethostbynamel($host);
            if (is_array($a)) $ips = array_merge($ips, $a);
        }
        if (owLCCallable('dns_get_record')) {
            $rec = @dns_get_record($host, DNS_AAAA);
            if (is_array($rec)) {
                foreach ($rec as $r) if (!empty($r['ipv6'])) $ips[] = (string)$r['ipv6'];
            }
        }
    }
    $ips = array_values(array_unique($ips));
    if (!$ips) return [];
    foreach ($ips as $ip) if (!owLCPublicIp($ip)) return [];
    return $ips;
}

/** 抓取用 UA：带站名，方便被抓方识别来源（要封就封这个 UA，而不是整台机器） */
function owLCUserAgent(): string
{
    $site = (string)DB::setting('site_name', '');
    return 'OwlsgoLinkPreview/1.1' . ($site !== '' ? ' (' . preg_replace('/[^\x20-\x7E]/', '', $site) . ')' : '');
}

/**
 * 单次 HTTP 请求（不跟随跳转、不解析内容）。返回：
 *   ['err' => 文案] 或 ['code' => int, 'type' => string, 'loc' => string, 'body' => string]
 *
 * ⚠️ 必须走 curl：DNS 校验与真正建立连接之间有时间差（TOCTOU），只有 CURLOPT_RESOLVE
 *    把**校验过的那个 IP** 钉死在本次连接上，才堵得住「解析时给公网、连接时改指内网」的
 *    DNS Rebinding。fopen 回落通道做不到这一点 —— 所以本插件不带回落，没有 curl 就不抓
 *    （fail-closed 比「能抓但可被绕过」合适）。
 */
function owLCFetchRaw(string $url, string $host, int $port, string $ip): array
{
    if (!function_exists('curl_init')) {
        return ['err' => '服务器未启用 cURL 扩展，无法读取链接预览'];
    }
    $body = '';
    $loc  = '';
    $type = '';
    $ch = curl_init($url);
    $pin = (strpos($ip, ':') !== false) ? '[' . $ip . ']' : $ip;   // IPv6 需方括号
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => false,
        CURLOPT_FOLLOWLOCATION  => false,        // 跳转由 owLCFetchPage 逐跳重校验
        CURLOPT_CONNECTTIMEOUT  => 3,
        CURLOPT_TIMEOUT         => 5,
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_HTTPHEADER      => ['Accept: text/html,application/xhtml+xml', 'Accept-Language: *'],
        CURLOPT_USERAGENT       => owLCUserAgent(),
        CURLOPT_RESOLVE         => [$host . ':' . $port . ':' . $pin],   // ★ 钉住已校验的 IP
        CURLOPT_HEADERFUNCTION  => function ($ch, $line) use (&$loc, &$type) {
            if (stripos($line, 'location:') === 0)         $loc  = trim(substr($line, 9));
            elseif (stripos($line, 'content-type:') === 0) $type = trim(substr($line, 13));
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION   => function ($ch, $chunk) use (&$body) {
            $body .= $chunk;
            // 返回 0 = 主动中止传输（curl 报 CURLE_WRITE_ERROR）——不让远端用超大响应拖住我们
            if (strlen($body) > OWLC_MAX_BYTES) return 0;
            return strlen($chunk);
        },
    ]);
    if (class_exists('Upgrade')) {                        // 与 content-report 同一套 CA 兜底
        $ca = Upgrade::caBundle();
        if ($ca !== null) curl_setopt($ch, CURLOPT_CAINFO, $ca);
    }
    curl_exec($ch);
    $errno = curl_errno($ch);
    $cerr  = curl_error($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    // 因体积上限被我们主动掐断时 body 仍是完整的开头部分，meta 通常已在其中 —— 有 body 就继续用
    if ($errno !== 0 && $body === '') {
        if ($errno === CURLE_OPERATION_TIMEDOUT) return ['err' => '读取超时（5 秒，目标站响应过慢）'];
        return ['err' => '读取失败：' . mb_substr($cerr !== '' ? $cerr : ('CURL ' . $errno), 0, 80)];
    }
    return ['code' => $code, 'type' => $type, 'loc' => $loc, 'body' => (string)$body];
}

/** 解析跳转地址：只认绝对 / 协议相对 / 根相对三种，其余（含畸形写法）一律不跟 */
function owLCabsUrl(string $location, string $base): string
{
    $location = trim($location);
    if ($location === '') return '';
    if (preg_match('#^https?://#i', $location)) return $location;
    if (strpos($location, '//') === 0) {
        $scheme = (string)(parse_url($base, PHP_URL_SCHEME) ?: 'https');
        return $scheme . ':' . $location;
    }
    if ($location[0] === '/') {
        $p = parse_url($base);
        if (empty($p['host'])) return '';
        $scheme = (string)($p['scheme'] ?? 'https');
        $port = empty($p['port']) ? '' : ':' . $p['port'];
        return $scheme . '://' . $p['host'] . $port . $location;
    }
    return '';   // 相对路径不做猜测：多一跳就多一个校验面，宁可不跟
}

/**
 * 读取一个页面（含最多 2 跳跳转，逐跳重跑全部校验）。返回：
 *   ['ok' => true, 'body' => string, 'url' => 最终地址] 或 ['err' => 文案]
 */
function owLCFetchPage(string $url): array
{
    $blocked = owLCBlockedList();
    $cur = $url;

    for ($hop = 0; $hop <= 2; $hop++) {
        $p = parse_url($cur);
        if (!is_array($p) || empty($p['host']) || empty($p['scheme'])) return ['err' => '地址无效'];
        $scheme = strtolower((string)$p['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) return ['err' => '仅支持 http/https 链接'];
        if (isset($p['user']) || isset($p['pass'])) return ['err' => '地址含认证信息，已拒绝'];
        $host = rtrim(strtolower((string)$p['host']), '.');
        if ($host === '') return ['err' => '地址无效'];
        if (owLCBlockHit($host, $blocked)) return ['err' => 'blocked'];
        // 只走默认端口：其它端口要么是错的，要么是在拿我们扫端口
        $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        if ($port !== ($scheme === 'https' ? 443 : 80)) return ['err' => '仅支持默认端口'];

        $ips = owLCResolveHost($host);
        if (!$ips) return ['err' => '域名无法解析，或解析到内网地址'];

        $res = owLCFetchRaw($cur, $host, $port, (string)$ips[0]);
        if (isset($res['err'])) return $res;

        if ($res['code'] >= 300 && $res['code'] < 400 && $res['loc'] !== '') {
            $next = owLCabsUrl($res['loc'], $cur);
            if ($next === '') return ['err' => '跳转地址无法解析'];
            $cur = $next;
            continue;   // 回到循环顶部：新地址重新过一遍全部校验
        }
        if ($res['code'] !== 200) return ['err' => '目标页返回 HTTP ' . $res['code']];
        if ($res['type'] !== '' && stripos($res['type'], 'html') === false) {
            return ['err' => '目标不是网页（' . mb_substr($res['type'], 0, 60) . '）'];
        }
        return ['ok' => true, 'body' => $res['body'], 'url' => $cur];
    }
    return ['err' => '跳转次数过多'];
}

/* ==================== 消息预览：OG 解析 ==================== */

/** 文本清洗：统一编码 → 去实体 → 去控制字符 → 折叠空白 → 截断 */
function owLCClean(string $s, string $charset, int $max): string
{
    $s = str_replace(["\r", "\n", "\t"], ' ', $s);
    if ($charset !== '' && strtolower($charset) !== 'utf-8' && function_exists('mb_convert_encoding')) {
        $t = @mb_convert_encoding($s, 'UTF-8', $charset);
        if (is_string($t) && $t !== '') $s = $t;
    }
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $s);
    $s = trim((string)preg_replace('/\s+/u', ' ', $s));
    return $max > 0 ? mb_substr($s, 0, $max) : $s;
}

/**
 * 从 HTML 取 Open Graph（含少量兜底）信息。
 * 只扫 </head> 之前的一段：正文里偶有 "og:title" 字样，扫全文会误取。
 */
function owLCParseOg(string $html, string $finalUrl): array
{
    $out = ['title' => '', 'description' => '', 'image' => '', 'site' => ''];
    $head = $html;
    if (($pos = stripos($html, '</head>')) !== false) $head = substr($html, 0, $pos);
    if (strlen($head) > 200000) $head = substr($head, 0, 200000);

    $charset = '';
    if (preg_match('/<meta[^>]+charset\s*=\s*["\']?\s*([A-Za-z0-9_\-]+)/i', $head, $m)) $charset = $m[1];

    // 收集所有 meta：同一个 key 只认第一条（OG 规范里先出现的生效）
    $meta = [];
    if (preg_match_all('/<meta\s+([^>]+)>/i', $head, $mm)) {
        foreach ($mm[1] as $attrs) {
            if (!preg_match('/(?:property|name)\s*=\s*["\']([^"\']+)["\']/i', $attrs, $k)) continue;
            if (!preg_match('/content\s*=\s*["\']([^"\']*)["\']/i', $attrs, $v)) continue;
            $key = strtolower(trim($k[1]));
            if ($key !== '' && !isset($meta[$key])) $meta[$key] = $v[1];
        }
    }
    $get = function (array $keys) use ($meta): string {
        foreach ($keys as $k) if (!empty($meta[$k])) return (string)$meta[$k];
        return '';
    };

    $out['title'] = owLCClean($get(['og:title', 'twitter:title']), $charset, 200);
    $out['description'] = owLCClean($get(['og:description', 'twitter:description', 'description']), $charset, 300);
    $out['site'] = owLCClean($get(['og:site_name']), $charset, 100);
    $img = $get(['og:image:secure_url', 'og:image:url', 'og:image', 'twitter:image', 'twitter:image:src']);

    // 没有 og:title 时退回 <title>：没有标题的卡片没有意义
    if ($out['title'] === '' && preg_match('/<title[^>]*>(.*?)<\/title>/is', $head, $t)) {
        $out['title'] = owLCClean($t[1], $charset, 200);
    }
    if ($out['site'] === '') $out['site'] = owLChost($finalUrl);

    // og:image 允许相对地址（不少站点这么写），补成绝对；只接受 https
    if ($img !== '') {
        $img = owLCabsUrl(owLCClean($img, $charset, 512), $finalUrl);
        $p = $img !== '' ? parse_url($img) : null;
        $ihost = $p ? rtrim(strtolower((string)($p['host'] ?? '')), '.') : '';
        if ($img === '' || stripos($img, 'https://') !== 0 || $ihost === '') {
            $img = '';
        } elseif (owLCBlockHit($ihost, owLCBlockedList())) {
            $img = '';   // 黑名单域名上的图片同样不给（图片是浏览器去取的，等于替用户出网）
        } elseif (filter_var($ihost, FILTER_VALIDATE_IP) !== false && !owLCPublicIp($ihost)) {
            $img = '';   // 内网 IP 字面量：不让用户浏览器去探自己局域网
        }
    }
    $out['image'] = $img;
    return $out;
}

/* ==================== 消息预览：缓存读写 ==================== */

/** 读取缓存（含过期判定）。命中返回行数组，否则 null */
function owLCCacheGet(string $url): ?array
{
    // 懒建表挂在这里而不是只挂路由：任何调用方（含脚本 / 未来新入口）都不该因为
    // 「忘了先 boot」而 500 —— 静态标记保证只真正执行一次。
    owLCPreviewBoot();
    $row = DB::one('SELECT * FROM plugin_link_card_preview WHERE url_hash=?', [md5($url)]);
    if (!$row) return null;
    $ttl = (int)$row['status'] === 1 ? OWLC_TTL_OK : OWLC_TTL_FAIL;
    if (time() - (int)$row['fetched_at'] > $ttl) return null;
    return $row;
}

function owLCCachePut(string $url, array $d): void
{
    owLCPreviewBoot();
    DB::upsert('plugin_link_card_preview', [
        'url_hash'    => md5($url),
        'url'         => mb_substr($url, 0, 512),
        'title'       => mb_substr((string)($d['title'] ?? ''), 0, 255),
        'description' => mb_substr((string)($d['description'] ?? ''), 0, 300),
        'image'       => mb_substr((string)($d['image'] ?? ''), 0, 512),
        'site'        => mb_substr((string)($d['site'] ?? ''), 0, 191),
        'status'      => !empty($d['status']) ? 1 : 0,
        'reason'      => mb_substr((string)($d['reason'] ?? ''), 0, 191),
        'fetched_at'  => time(),
    ], ['url_hash']);
}

/**
 * 取一个 URL 的预览数据（缓存优先）。返回：
 *   ['ok' => true, 'data' => ['url','title','description','image','site']]
 *   ['ok' => false, 'reason' => 文案]
 * 失败同样入库（短 TTL），避免每次渲染都对同一个坏地址重试。
 *
 * ⚠️ 两站方规则（2026-10-17 反馈）：
 *   ① **没有可用封面图就不出卡** —— 链接卡片的价值就在那张图，没图的「卡片」只剩
 *      一个突兀的占位块（透明 / 自定义配色主题下尤其难看），不如让链接保持普通消息原样。
 *      缓存记为失败（短 TTL）：站点以后补了 og:image，卡片自然会出现。
 *   ② 本函数**永不抛异常**：预览是聊天页的增强功能，线上任何环境差异（DB 权限、
 *      disable_functions、curl 缺失、内存不足）都不能变成 500 —— 全部退化为
 *      「无卡片」，真实原因写进安全日志（action=link_card_error，后台可查）。
 */
function owLCGetPreview(string $url): array
{
    $url = (string)preg_replace('/#.*$/', '', trim($url));   // 锚点不该产生第二条缓存
    if ($url === '' || mb_strlen($url) > 512) return ['ok' => false, 'reason' => '地址无效'];
    if (!owLCValidUrl($url)) return ['ok' => false, 'reason' => '仅支持 http/https 链接'];

    // ★ 黑名单必须**先于缓存**判定：管理员刚把某域名拉黑，不能因为「以前抓过、缓存还在」
    //   就继续出卡 —— 否则「拉黑」要等缓存过期（7 天）才真正生效，等于没拉黑。
    if (owLCBlockHit(owLChost($url), owLCBlockedList())) {
        return ['ok' => false, 'reason' => '该域名在黑名单中'];
    }

    try {
        $c = owLCCacheGet($url);
        if ($c) {
            if ((int)$c['status'] !== 1) return ['ok' => false, 'reason' => (string)$c['reason']];
            // 旧版本缓存过的无图条目（v1.1.1 及以前 status=1 但 image 为空）：按新规则不出卡
            if ((string)$c['image'] === '') return ['ok' => false, 'reason' => '目标页没有可用的封面图'];
            return ['ok' => true, 'data' => [
                'url' => (string)$c['url'], 'title' => (string)$c['title'],
                'description' => (string)($c['description'] ?? ''), 'image' => (string)$c['image'],
                'site' => (string)$c['site'],
            ]];
        }

        $r = owLCFetchPage($url);
        if (isset($r['err'])) {
            $reason = $r['err'] === 'blocked' ? '该域名在黑名单中' : (string)$r['err'];
            owLCCachePut($url, ['status' => 0, 'reason' => $reason]);
            return ['ok' => false, 'reason' => $reason];
        }
        $og = owLCParseOg((string)$r['body'], (string)$r['url']);
        if ($og['title'] === '') {
            owLCCachePut($url, ['status' => 0, 'reason' => '目标页没有可用的标题信息']);
            return ['ok' => false, 'reason' => '目标页没有可用的标题信息'];
        }
        if ($og['image'] === '') {
            owLCCachePut($url, ['status' => 0, 'reason' => '目标页没有可用的封面图']);
            return ['ok' => false, 'reason' => '目标页没有可用的封面图'];
        }
        $og['status'] = 1;
        owLCCachePut($url, $og);
        return ['ok' => true, 'data' => [
            'url' => $url, 'title' => $og['title'], 'description' => $og['description'],
            'image' => $og['image'], 'site' => $og['site'],
        ]];
    } catch (Throwable $e) {
        // 预览永远不该把聊天页拖出 500：真实原因进安全日志（后台「安全日志」页可查）
        try {
            Sec::log('link_card_error', '', ['url' => mb_substr($url, 0, 120), 'err' => mb_substr($e->getMessage(), 0, 200)]);
        } catch (Throwable $e2) {}   // 连日志本身都失败（如 DB 不可用）也不再往外抛
        return ['ok' => false, 'reason' => '读取失败，详情见安全日志（link_card_error）'];
    }
}

/* ============================ 钩子 ============================ */

/**
 * page.head：插件样式（后台管理界面 + 聊天页消息卡片，两处都要，故全页注入）；
 * 站点分享卡片 meta 只在非后台页输出（管理后台是内部工具，不参与链接分享）。
 */
Plugin::on('page.head', function (): void {
    echo '<link rel="stylesheet" href="?action=assets&type=css&plugin=link-card&file=style.css&v='
        . OWLSGO_VERSION . '">' . "\n";
    if (($_GET['page'] ?? '') === 'admin') return;
    owLCBoot();
    owLCMeta();
});

/**
 * page.footer：给聊天页下发「消息卡片」的前端开关与黑名单。
 * ⚠️ page.footer 在合并资源包**之后**输出（聊天页与后台页都会触发），
 *    所以 chat.js 必须**调用时**再读 window.OW_LC_*，不能在脚本加载时抓快照
 *    （那样永远拿到 undefined，功能静默失效 —— 与 sensitive-words 同一个坑）。
 */
Plugin::on('page.footer', function (): void {
    if (!owLCmsgOn()) return;
    echo '<script>window.OW_LC_MSG_ON=1;window.OW_LC_BLOCKED='
        . json_encode(owLCBlockedList(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';'
        . 'window.OW_LC_MAX=' . OWLC_MAX_PER_MSG . ';</script>' . "\n";
});

/* ============================ 后台页面 ============================ */

Plugin::adminPage('link-card', '链接卡片', function () {
    try {
        return owLCAdminHtml();
    } catch (Throwable $e) {
        // 后台页不能 500：把真实原因给管理员看（多半是数据库账号无建表权限这类环境问题），
        // 完整堆栈进安全日志（action=link_card_error）
        try {
            Sec::log('link_card_error', '', ['where' => 'admin', 'err' => mb_substr($e->getMessage(), 0, 200)]);
        } catch (Throwable $e2) {}
        return '<h2>链接卡片</h2>'
            . '<div class="ow-card"><p style="margin:0 0 8px"><b>初始化失败：</b>' . Sec::e(mb_substr($e->getMessage(), 0, 200)) . '</p>'
            . '<p style="margin:0;font-size:12px;color:var(--ow-text-sub)">常见原因：数据库账号无建表权限、数据目录不可写。'
            . '完整信息见「安全日志」（action=link_card_error）。</p></div>';
    }
});

/** 后台页面主体（拆出来是为了让上面的 try/catch 兜得住整个渲染过程） */
function owLCAdminHtml(): string
{
    owLCBoot();
    $msgOn = owLCmsgOn();
    $cacheN = (int)DB::val('SELECT COUNT(*) FROM plugin_link_card_preview');

    return '<h2>链接卡片</h2>'
        . '<p class="ow-admin-desc">两个用途：<b>消息链接卡片</b> —— 消息里的链接由服务器读取目标页 Open Graph，'
        . '在消息下方展示预览；<b>站点分享卡片</b> —— 本站链接被分享到各平台时显示的标题、描述与封面图。</p>'

        /* ---------- ① 消息链接卡片 ---------- */
        . '<div class="ow-card lc-sec"><h3 class="lc-sec-hd">消息链接卡片</h3>'
        . '<div class="ow-switch-row">'
        . '<span class="ow-switch-label">在消息中展示链接卡片</span>'
        . '<input type="checkbox" class="ow-switch-input" id="lcMsgOn"' . ($msgOn ? ' checked' : '') . '>'
        . '<label class="ow-switch' . ($msgOn ? ' is-on' : '') . '" for="lcMsgOn"></label>'
        . '<p class="ow-switch-hint">开启后，消息里的链接会由服务器读取目标页的 Open Graph 信息并缓存，'
        . '在消息下方展示预览卡片；目标页没有可用封面图、或读取失败时不展示卡片（链接保持原样）；关闭则完全不抓取。</p></div>'

        . '<div class="ow-form-item"><label>域名黑名单</label>'
        . '<textarea class="ow-input lc-mono" id="lcBlocked" rows="6" placeholder="每行一个，例如&#10;spam-site.com&#10;ads.example.net">'
        . Sec::e((string)DB::setting(owLCKeyBlocked(), '')) . '</textarea>'
        . '<p style="font-size:12px">每行一个域名（含其子域）。名单内的域名<strong>不会被抓取</strong>，消息里也不会出现卡片。'
        . '站点分享卡片不受此名单影响。</p></div>'

        . '<div class="ow-form-row">'
        . '<button class="ow-btn ow-btn-primary" onclick="OwLC.saveMsgCfg()">保存</button>'
        . '<button class="ow-btn ow-btn-ghost" onclick="OwLC.clearCache()">清空预览缓存</button>'
        . '<span class="lc-cache-info" id="lcCacheInfo">已缓存 ' . $cacheN . ' 个链接</span>'
        . '</div></div>'

        /* ---------- ② 站点分享卡片 ---------- */
        . '<div class="ow-card lc-sec"><h3 class="lc-sec-hd">站点分享卡片</h3>'
        . '<p class="lc-sec-desc">一次填写分享信息，按需启用 Open Graph / X（Twitter）/ 微信 / QQ 平台。'
        . '封面图须为可公开访问的 https:// 绝对地址；多张同时启用时按列表顺序取第一张。</p>'
        . '<div class="ow-admin-batch">'
        . '<button class="ow-btn ow-btn-primary" onclick="OwLC.edit(0)">新建卡片</button>'
        . '<span class="lc-count" id="lcCount"></span>'
        . '</div>'
        . '<div id="lcNotice"></div>'
        . '<div id="lcList"><div class="lc-empty">加载中…</div></div>'
        . '</div>';
}

/* ============================ API 路由 ============================ */

/** 管理员鉴权：插件路由不在 Admin::handle 内，核心不会替插件校验权限 */
$lcGuard = function (array $ctx): void {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
};

/** 卡片列表（按排序，供后台渲染）。任何环境异常都返回可读错误，不给后台 500 */
Plugin::route('plugin_link_card_list', function (array $ctx) use ($lcGuard): void {
    $lcGuard($ctx);
    try {
        owLCBoot();
        Api::json(['ok' => true, 'cards' => DB::all("SELECT * FROM plugin_link_card ORDER BY sort ASC, id ASC")]);
    } catch (Throwable $e) {
        try {
            Sec::log('link_card_error', (string)($ctx['actor']['nickname'] ?? ''), ['where' => 'list', 'err' => mb_substr($e->getMessage(), 0, 200)]);
        } catch (Throwable $e2) {}
        Api::json(['ok' => false, 'msg' => '读取失败：' . mb_substr($e->getMessage(), 0, 120) . '（详见安全日志 link_card_error）']);
    }
});

/** 新建 / 保存卡片 */
Plugin::route('plugin_link_card_save', function (array $ctx) use ($lcGuard): void {
    $lcGuard($ctx);
    owLCBoot();
    $p = $ctx['post'];

    $id     = (int)($p['id'] ?? 0);
    $name   = trim((string)($p['name'] ?? ''));
    $url    = trim((string)($p['url'] ?? ''));
    $title  = trim((string)($p['title'] ?? ''));
    $desc   = trim((string)($p['desc'] ?? ''));
    $image  = trim((string)($p['image'] ?? ''));
    $ogType = (string)($p['og_type'] ?? 'website');
    $twCard = (string)($p['tw_card'] ?? 'summary_large_image');
    $pOg = !empty($p['p_og']) ? 1 : 0;
    $pTw = !empty($p['p_tw']) ? 1 : 0;
    $pWx = !empty($p['p_wx']) ? 1 : 0;
    $pQq = !empty($p['p_qq']) ? 1 : 0;
    $enabled = !empty($p['enabled']) ? 1 : 0;
    $sort    = max(-9999, min(9999, (int)($p['sort'] ?? 0)));

    if ($name === '' || mb_strlen($name) > 64) {
        Api::json(['ok' => false, 'msg' => '请填写卡片名称（64 字以内）'], 400);
    }
    if ($title === '' || mb_strlen($title) > 255) {
        Api::json(['ok' => false, 'msg' => '请填写卡片标题（255 字以内）'], 400);
    }
    if (mb_strlen($desc) > 300) {
        Api::json(['ok' => false, 'msg' => '描述过长（300 字以内）'], 400);
    }
    if (!owLCValidUrl($url)) {
        Api::json(['ok' => false, 'msg' => '请填写有效的规范链接（http/https 绝对地址）'], 400);
    }
    if ($image !== '' && !owLCValidImage($image)) {
        Api::json(['ok' => false, 'msg' => '封面图须为 https:// 开头的绝对地址'], 400);
    }
    if (!in_array($ogType, ['website', 'article'], true)) {
        Api::json(['ok' => false, 'msg' => '非法内容类型'], 400);
    }
    if (!in_array($twCard, ['summary', 'summary_large_image'], true)) {
        Api::json(['ok' => false, 'msg' => '非法卡片样式'], 400);
    }
    if ($pOg + $pTw + $pWx + $pQq === 0) {
        Api::json(['ok' => false, 'msg' => '请至少选择一个分享平台'], 400);
    }

    $now = time();
    if ($id > 0) {
        if (!DB::val("SELECT COUNT(*) FROM plugin_link_card WHERE id=?", [$id])) {
            Api::json(['ok' => false, 'msg' => '卡片不存在'], 404);
        }
        DB::run("UPDATE plugin_link_card SET name=?,url=?,title=?,description=?,image=?,og_type=?,tw_card=?,
                p_og=?,p_tw=?,p_wx=?,p_qq=?,enabled=?,sort=?,updated_at=? WHERE id=?",
                [$name, $url, $title, $desc, $image, $ogType, $twCard,
                 $pOg, $pTw, $pWx, $pQq, $enabled, $sort, $now, $id]);
    } else {
        DB::insert('plugin_link_card', [
            'name' => $name, 'url' => $url, 'title' => $title, 'description' => $desc,
            'image' => $image, 'og_type' => $ogType, 'tw_card' => $twCard,
            'p_og' => $pOg, 'p_tw' => $pTw, 'p_wx' => $pWx, 'p_qq' => $pQq,
            'enabled' => $enabled, 'sort' => $sort, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    Sec::log('link_card_save', $ctx['actor']['nickname'], ['id' => $id, 'name' => $name]);
    Api::json(['ok' => true, 'msg' => '已保存', 'cards' => DB::all("SELECT * FROM plugin_link_card ORDER BY sort ASC, id ASC")]);
});

/** 启用 / 停用单张卡片 */
Plugin::route('plugin_link_card_toggle', function (array $ctx) use ($lcGuard): void {
    $lcGuard($ctx);
    owLCBoot();
    $id = (int)($ctx['post']['id'] ?? 0);
    $on = !empty($ctx['post']['enabled']) ? 1 : 0;
    if (!DB::val("SELECT COUNT(*) FROM plugin_link_card WHERE id=?", [$id])) {
        Api::json(['ok' => false, 'msg' => '卡片不存在'], 404);
    }
    DB::run("UPDATE plugin_link_card SET enabled=?, updated_at=? WHERE id=?", [$on, time(), $id]);
    Sec::log('link_card_toggle', $ctx['actor']['nickname'], ['id' => $id, 'enabled' => $on]);
    Api::json(['ok' => true, 'msg' => $on ? '已启用' : '已停用',
               'cards' => DB::all("SELECT * FROM plugin_link_card ORDER BY sort ASC, id ASC")]);
});

/** 删除卡片（删除数据 = 敏感操作，前端须走 OwApi.secure 一次性票据） */
Plugin::route('plugin_link_card_del', function (array $ctx) use ($lcGuard): void {
    $lcGuard($ctx);
    owLCBoot();
    $id = (int)($ctx['post']['id'] ?? 0);
    $row = DB::one("SELECT id,name FROM plugin_link_card WHERE id=?", [$id]);
    if (!$row) Api::json(['ok' => false, 'msg' => '卡片不存在'], 404);
    DB::run("DELETE FROM plugin_link_card WHERE id=?", [$id]);
    Sec::log('link_card_del', $ctx['actor']['nickname'], ['id' => $id, 'name' => (string)$row['name']]);
    Api::json(['ok' => true, 'msg' => '已删除', 'cards' => DB::all("SELECT * FROM plugin_link_card ORDER BY sort ASC, id ASC")]);
});
Plugin::sensitive('plugin_link_card_del');

/** 消息卡片设置：总开关 + 域名黑名单 */
Plugin::route('plugin_link_card_cfg', function (array $ctx) use ($lcGuard): void {
    $lcGuard($ctx);
    owLCPreviewBoot();
    $on = !empty($ctx['post']['msg_on']) ? '1' : '0';
    $blocked = mb_substr((string)($ctx['post']['blocked'] ?? ''), 0, 8000);
    DB::setSetting(owLCKeyMsgOn(), $on);
    DB::setSetting(owLCKeyBlocked(), $blocked);
    Sec::log('link_card_cfg', $ctx['actor']['nickname'], ['msg_on' => $on, 'blocked' => count(owLCBlockedList())]);
    Api::json([
        'ok' => true, 'msg' => '已保存',
        'cache_count' => (int)DB::val('SELECT COUNT(*) FROM plugin_link_card_preview'),
        'blocked_count' => count(owLCBlockedList()),
    ]);
});

/** 清空预览缓存（删数据 = 敏感操作） */
Plugin::route('plugin_link_card_cache_clear', function (array $ctx) use ($lcGuard): void {
    $lcGuard($ctx);
    owLCPreviewBoot();
    $n = DB::run('DELETE FROM plugin_link_card_preview')->rowCount();
    Sec::log('link_card_cache_clear', $ctx['actor']['nickname'], ['rows' => $n]);
    Api::json(['ok' => true, 'msg' => '已清空 ' . $n . ' 条预览缓存', 'cache_count' => 0]);
});
Plugin::sensitive('plugin_link_card_cache_clear');

/**
 * 批量取链接预览（聊天页调用，所有访客可用）。
 * 安全：除签名门禁（页面会话密钥）外，还有按 IP 的频率限制 —— 这个接口会让服务器出网，
 * 不限流就成了免费代理 / 内网扫描器。缓存命中的 URL 不消耗配额。
 * ⚠️ 整体兜 try/catch：这个接口在聊天页每条带链接的消息都会调，线上任何环境异常
 *    （DB 权限、disable_functions、curl 缺失）都返回可读的 ok:false，绝不能 500 ——
 *    否则用户看到的就是一串「网络错误（500）」。
 */
Plugin::route('plugin_link_card_preview', function (array $ctx): void {
    try {
        if (!owLCmsgOn()) Api::json(['ok' => true, 'data' => [], 'off' => 1]);
        owLCPreviewBoot();

        $list = json_decode((string)($ctx['post']['urls'] ?? ''), true);
        if (!is_array($list) || !$list) Api::json(['ok' => false, 'msg' => '参数错误'], 400);
        $list = array_slice($list, 0, OWLC_MAX_PER_CALL);

        $need = 0;
        foreach ($list as $u) {
            $u = (string)preg_replace('/#.*$/', '', trim((string)$u));
            if ($u !== '' && !owLCCacheGet($u)) $need++;
        }
        if ($need > 0) {
            $ip = Sec::ip();
            if (!Sec::rateLimit('lc_preview', $ip, 60, 20)) {
                $left = Sec::rateRemaining('lc_preview', $ip, 60);
                Api::json(['ok' => false, 'msg' => '取链接预览过于频繁，请 ' . max(1, $left) . ' 秒后再试'], 429);
            }
        }

        $out = [];
        foreach ($list as $u) {
            $u = trim((string)$u);
            if ($u === '' || mb_strlen($u) > 512) continue;
            $out[$u] = owLCGetPreview($u);
        }
        Api::json(['ok' => true, 'data' => $out]);
    } catch (Throwable $e) {
        try {
            Sec::log('link_card_error', (string)($ctx['actor']['nickname'] ?? ''), ['where' => 'preview', 'err' => mb_substr($e->getMessage(), 0, 200)]);
        } catch (Throwable $e2) {}
        Api::json(['ok' => false, 'msg' => '链接预览暂时不可用（原因见安全日志 link_card_error）']);
    }
});

/* ============================ 静态资源 ============================ */

Plugin::asset('js', 'link-card/chat.js');
Plugin::asset('js', 'link-card/admin.js');
Plugin::asset('css', 'link-card/style.css');
