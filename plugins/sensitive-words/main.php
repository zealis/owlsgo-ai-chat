<?php
/**
 * 垃圾信息控制插件（v1.0.104 由核心剥离为「敏感词过滤」，v1.3.104 扩成五件套）
 *
 * 五道控制，各自生效的位置不同 —— 搞错位置等于白做：
 *   1. 敏感词过滤   核心 text.filter 钩子：命中词库即**替换**（打码），对所有输入文字生效
 *                   （发言、昵称、群名称、群简介、群公告）。词库沿用 sensitive_words 表。
 *   2. 刷屏控制     「窗口内条数」直接接管核心键 msg_rate_window / msg_rate_max
 *                   （核心 Chat::send 已在读，只是编辑入口搬到这里，键不变、不打架）；
 *                   另加「两条消息最小间隔」= spam_min_gap，走新的 text.check 钩子拒发。
 *   3. IP 封锁      request.start 钩子（index.php 里最早、每个请求都跑的那个）——
 *                   黑名单命中即 403，白名单模式下不在名单内即 403。
 *   4. 域名过滤     **只在前端点击时拦**：名单随聊天页下发，核心 linkifyHtml 经
 *                   window.OW_LINK_GUARD 把命中的链接改成「点了弹窗、不跳转」。
 *                   不在服务端拦发送 —— 按站方定版：垃圾已经进了库，但不让人走进去。
 *   5. AI 防线      text.check 钩子：把正文交给可配的 OpenAI 兼容接口判定，
 *                   动作三选一（仅记录 / 打码替换 / 拒发），默认仅记录。
 *
 * ⚠️ 三条不可省的前提：
 *   · IP 一律取 Sec::ip()（REMOTE_ADDR）。任何 X-Forwarded-For / X-Real-IP 都不读 ——
 *     那些头客户端想写什么写什么，拿它封 IP 等于把「绕过封锁」的开关交给攻击者。
 *   · AI 的 key 只存后端、只在服务端用，绝不下发前端；base URL 强制 https，
 *     否则等于拿明文往外送密钥。
 *   · AI 判定失败/超时/额度用尽一律**放行**：宁可漏一条垃圾，不能因为第三方接口抖一下
 *     全站发不出消息。
 */
if (!defined('OWLSGO_VERSION')) exit;   // 禁止直接 HTTP 访问本文件

/* ============================ 配置读写 ============================ */

/** 插件配置统一存核心 settings 表，键前缀 spam_ —— 停用插件也不脏读，键名自带归属 */
function owSPSet(string $k, string $def = ''): string
{
    $v = DB::setting('spam_' . $k, $def);
    return is_string($v) ? $v : $def;
}
function owSPInt(string $k, int $def = 0): int
{
    $v = trim((string)DB::setting('spam_' . $k, (string)$def));
    return is_numeric($v) ? (int)$v : $def;
}
function owSPOn(string $k): bool { return (string)DB::setting('spam_' . $k, '0') === '1'; }

/** 多行文本 → 去重去空的条目列表（域名与 IP 名单共用；容忍逗号/分号/空格分隔） */
function owSPlist(string $txt): array
{
    $out = [];
    foreach (preg_split('/[\r\n,，;；\s]+/u', mb_strtolower($txt, 'UTF-8')) ?: [] as $s) {
        $s = trim($s);
        if ($s !== '' && !in_array($s, $out, true)) $out[] = $s;
    }
    return $out;
}

/* ============================ 2. 域名匹配 ============================ */

/**
 * 名单条目归一：管理员常直接粘一整条 URL（https://a.com/x），这里只取主机名。
 * ⚠️ 没写协议时**不能**丢给 parse_url：它会把 "spam.com:8080" 里的 "spam.com" 当成协议名，
 *    于是解析出 host=null，这条规则永远不生效、后台还看着像配好了。
 */
function owSPnormDomain(string $s): string
{
    $s = trim($s);
    if ($s === '') return '';
    if (strpos($s, '://') !== false) {
        return rtrim(strtolower((string)(parse_url($s, PHP_URL_HOST) ?: '')), '.');
    }
    $s = strtolower(explode('/', explode('?', $s)[0])[0]);   // 去掉路径与查询
    return rtrim((string)preg_replace('/:\d+$/', '', $s), '.');  // 去掉端口
}

/** 从待检 URL 取主机名 */
function owSPhost(string $url): string
{
    $h = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
    return rtrim($h, '.');
}

/**
 * 命中判定：精确相等，或是名单项的**子域**。
 * ⚠️ 不能写成 strpos($host, $d) !== false —— 那样「evil.com」会命中名单里的「vil.com」，
 *    反过来「notexample.com」也不会命中「example.com」但看着像，两边都要按点边界比。
 */
function owSPdomainHit(string $host, array $list): bool
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

/** 生效的域名名单（未启用返回空数组：前端与后台都按它判定） */
function owSPdomains(): array
{
    if (!owSPOn('domain_on')) return [];
    $out = [];
    foreach (owSPlist(owSPSet('domain_list')) as $d) {
        $n = owSPnormDomain($d);
        if ($n !== '' && !in_array($n, $out, true)) $out[] = $n;
    }
    return $out;
}

/* ============================ 3. IP 匹配 ============================ */

/**
 * CIDR 命中（v4/v6 通吃）：都转成二进制按前缀比长度。
 * 不用 ip2long —— IPv6 地址装不进 32 位整型，那样写等于悄悄不支持 v6。
 */
function owSPcidrHit(string $ip, string $cidr): bool
{
    [$net, $bits] = explode('/', $cidr, 2);
    $binIp = @inet_pton($ip);
    $binNet = @inet_pton($net);
    if ($binIp === false || $binNet === false || strlen($binIp) !== strlen($binNet)) return false;
    $bits = (int)$bits;
    $max = strlen($binNet) * 8;
    if ($bits < 0 || $bits > $max) return false;
    $full = intdiv($bits, 8);
    if ($full > 0 && substr($binIp, 0, $full) !== substr($binNet, 0, $full)) return false;
    $rem = $bits % 8;
    if ($rem === 0) return true;
    $mask = (0xFF << (8 - $rem)) & 0xFF;
    return (ord($binIp[$full]) & $mask) === (ord($binNet[$full]) & $mask);
}

/**
 * 名单命中判定，支持三种写法混用：
 *   精确        203.0.113.7 / 2001:db8::1
 *   CIDR        203.0.113.0/24 / 2001:db8::/32
 *   通配        203.0.113.* / 2001:db8:*   （星号必须在末尾，前缀自带 . 或 : 作边界）
 * 单独的 '*' 一律忽略 —— 它在黑名单里等于封全站、在白名单里等于放开全站，两种都不是管理员的意思。
 */
function owSPipHit(string $ip, array $list): bool
{
    foreach ($list as $rule) {
        if ($rule === '') continue;
        if ($rule === $ip) return true;
        if (strpos($rule, '/') !== false) { if (owSPcidrHit($ip, $rule)) return true; continue; }
        if (substr($rule, -1) === '*') {
            $pre = substr($rule, 0, -1);
            if ($pre === '' || $pre === $rule) continue;
            if (strncmp($ip, $pre, strlen($pre)) === 0) return true;
        }
    }
    return false;
}

/** 清洗后的 IP 名单（逐条校验，写不进 inet_pton 又不是通配的条目直接丢掉，避免留下永远不生效的规则） */
function owSPipList(string $txt): array
{
    $out = [];
    foreach (owSPlist($txt) as $r) {
        if ($r === '*') continue;
        if (strpos($r, '/') !== false) {
            [$n] = explode('/', $r, 2);
            if (@inet_pton($n) === false) continue;
        } elseif (@inet_pton($r) === false && substr($r, -1) !== '*') {
            continue;
        }
        if (!in_array($r, $out, true)) $out[] = $r;
    }
    return $out;
}

/**
 * 这个 IP 会不会被「mode + 名单」拦住？
 * 白名单模式下**名单为空视为不启用** —— 否则管理员勾上白名单、还没填 IP 就先把自己关在外面。
 */
function owSPipBlocked(string $ip, string $mode, array $list): bool
{
    if (!$list) return false;
    return $mode === 'white' ? !owSPipHit($ip, $list) : owSPipHit($ip, $list);
}

/* ============================ 5. AI 防线 ============================ */

/** 默认提示词：站方可在后台改。要求只回 JSON 是因为解析一句「是的，这条像广告」太脆 */
const OWSP_AI_PROMPT = <<<TXT
你是聊天站的内容审核员。判断下面这条用户消息是否属于垃圾信息：
广告推广、引流（诱导添加联系方式/访问外部站点）、诈骗、色情、赌博、批量模板化文案、刷屏无意义内容。

正常聊天、技术讨论、正常的链接分享都不算垃圾信息，宁可放过也不要误判。

只输出一个 JSON 对象，不要任何解释、不要代码块标记：
{"spam": true 或 false, "reason": "不超过 20 字的中文类别"}

【场景】{scene}
【消息】{text}
TXT;

/** 填模板：只认 {scene} / {text} 两个占位，其它花括号原样留给模型 */
function owSPaiPrompt(string $text, string $scene): string
{
    $tpl = owSPSet('ai_prompt', '');
    if (trim($tpl) === '') $tpl = OWSP_AI_PROMPT;
    return str_replace(['{scene}', '{text}'], [mb_substr($scene, 0, 32), mb_substr($text, 0, 2000)], $tpl);
}

/**
 * 解析模型的返回。单独成函数是为了能**离线逐条测**：
 * 真跑 owSPaiAsk 要联网，而 CI/自检机上没有密钥，那条路径就永远没被验证过。
 * 模型常把 JSON 包在 ```json 代码块里或前后带一句话，所以抠第一个 { 到最后一个 }。
 */
function owSPaiParse(string $content): array
{
    $s = strpos($content, '{'); $e = strrpos($content, '}');
    if ($s === false || $e === false || $e <= $s) {
        return ['ok' => false, 'spam' => null, 'why' => '', 'raw' => '模型没按 JSON 返回：' . mb_substr($content, 0, 200)];
    }
    $v = json_decode(substr($content, $s, $e - $s + 1), true);
    if (!is_array($v) || !array_key_exists('spam', $v)) {
        return ['ok' => false, 'spam' => null, 'why' => '', 'raw' => 'JSON 里没有 spam 字段：' . mb_substr($content, 0, 200)];
    }
    $spam = filter_var($v['spam'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($spam === null) return ['ok' => false, 'spam' => null, 'why' => '', 'raw' => 'spam 不是真假值：' . mb_substr($content, 0, 200)];
    return ['ok' => true, 'spam' => $spam, 'why' => mb_substr((string)($v['reason'] ?? ''), 0, 40), 'raw' => mb_substr($content, 0, 200)];
}

/**
 * 问一次模型。返回 ['ok'=>bool,'spam'=>?bool,'why'=>string,'raw'=>string]
 * 任何一步不对都 ok=false + 人话原因 —— 调用方一律放行，绝不因为审核接口挂了而拦人。
 */
function owSPaiAsk(string $prompt): array
{
    $base = trim(owSPSet('ai_base'));
    $key  = trim(owSPSet('ai_key'));
    $model = trim(owSPSet('ai_model', 'gpt-4o-mini'));
    if ($base === '' || $model === '') return ['ok' => false, 'spam' => null, 'why' => '', 'raw' => '未配置接口地址或模型'];
    if (stripos($base, 'https://') !== 0) return ['ok' => false, 'spam' => null, 'why' => '', 'raw' => '接口地址必须是 https（否则密钥明文出网）'];
    $url = rtrim($base, '/') . '/chat/completions';
    $body = json_encode([
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0,
    ], JSON_UNESCAPED_UNICODE);
    if ($body === false) return ['ok' => false, 'spam' => null, 'why' => '', 'raw' => '请求体编码失败'];

    $hdr = ['Content-Type: application/json', 'Accept: application/json'];
    if ($key !== '') $hdr[] = 'Authorization: Bearer ' . $key;
    $timeout = max(1, min(15, owSPInt('ai_timeout', 3)));

    $ch = function_exists('curl_init') ? curl_init($url) : null;
    if ($ch) {
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $hdr,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if (class_exists('Upgrade')) {
            $ca = Upgrade::caBundle();
            if ($ca !== null) curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $cerr = $resp === false ? curl_error($ch) : '';
        curl_close($ch);
        if ($resp === false) return ['ok' => false, 'spam' => null, 'why' => '', 'raw' => '请求失败：' . ($cerr ?: '网络不通')];
        if ($code !== 200) return ['ok' => false, 'spam' => null, 'why' => '', 'raw' => 'HTTP ' . $code . '：' . mb_substr((string)$resp, 0, 200)];
    } else {
        if (!ini_get('allow_url_fopen')) return ['ok' => false, 'spam' => null, 'why' => '', 'raw' => '无 curl 且 allow_url_fopen 关闭'];
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $hdr) . "\r\n",
            'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true,
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $resp = @file_get_contents($url, false, $ctx);
        if ($resp === false) return ['ok' => false, 'spam' => null, 'why' => '', 'raw' => '请求失败（无 curl 扩展的回落通道也不通）'];
    }

    $j = json_decode((string)$resp, true);
    $content = '';
    if (is_array($j)) {
        $content = (string)($j['choices'][0]['message']['content'] ?? '');
        if ($content === '' && isset($j['error']['message'])) {
            return ['ok' => false, 'spam' => null, 'why' => '', 'raw' => '接口报错：' . mb_substr((string)$j['error']['message'], 0, 200)];
        }
    }
    if ($content === '') return ['ok' => false, 'spam' => null, 'why' => '', 'raw' => '响应里没有 content：' . mb_substr((string)$resp, 0, 200)];
    return owSPaiParse($content);
}

/** 额度闸门：默认每天 2000 次，防止一个失控的循环把账单打爆 */
function owSPaiQuotaOk(): bool
{
    $daily = owSPInt('ai_daily', 2000);
    if ($daily <= 0) return true;              // 0 = 不限量（站方自己承担）
    return Sec::rateLimit('spam_ai', 'all', 86400, $daily);
}

/* ============================ 钩子 ============================ */

/** 词库（static 缓存，进程内只查一次表） */
$GLOBALS['sw_words'] = function (): array {
    static $words = null;
    if ($words === null) $words = DB::all('SELECT word, replacement FROM sensitive_words WHERE enabled=1');
    return $words;
};

/** 1) 敏感词：核心 text.filter 钩子，对所有输入文字生效 */
Plugin::on('text.filter', function (&$text, $scene, $actor) {
    if (!is_string($text) || $text === '') return;
    foreach (($GLOBALS['sw_words'])() as $w) {
        if ($w['word'] !== '') {
            $text = mb_ereg_replace(preg_quote($w['word'], '/'), $w['replacement'], $text);
        }
    }
});

/**
 * AI 判定入口（可被自检替换）：真跑要密钥和外网，而「命中后做什么」这三条分支
 * 必须照样能测 —— 与上面 $GLOBALS['sw_words'] 同一个套路，进程内可换。
 */
$GLOBALS['sw_ai_ask'] = $GLOBALS['sw_ai_ask'] ?? 'owSPaiAsk';

/**
 * 2) + 5) 拒发与 AI：核心 text.check 钩子（只对消息正文触发）。
 * 顺序是先便宜的（最小间隔）后贵的（AI），且 AI 只在开关打开时才发请求。
 */
Plugin::on('text.check', function (&$reason, &$text, $scene, $actor) {
    if ($scene !== 'message' || !is_string($text) || trim($text) === '') return;
    $ident = (string)($actor['kind'] ?? 'none') . (string)($actor['id'] ?? 0);

    // 刷屏：两条消息之间的最小间隔（窗口/条数那一半由核心用 msg_rate_* 判，键不重复）
    $gap = owSPInt('min_gap', 0);
    if ($gap > 0 && !Sec::rateLimit('spam_gap', $ident, $gap, 1)) {
        $reason = '发言太快了，两条消息之间至少间隔 ' . $gap . ' 秒';
        Sec::log('spam_flood', (string)($actor['nickname'] ?? '?'), ['gap' => $gap, 'ip' => Sec::ip()]);
        return;
    }

    if (!owSPOn('ai_on')) return;
    if (!owSPaiQuotaOk()) return;              // 额度用尽：放行，不拦正常用户
    $r = ($GLOBALS['sw_ai_ask'])(owSPaiPrompt($text, $scene));
    if (!$r['ok']) {
        // 失败一律放行，但要把原因留下来 —— 否则「AI 没生效」永远查不出为什么
        Sec::log('spam_ai_fail', (string)($actor['nickname'] ?? '?'), ['err' => $r['raw']]);
        return;
    }
    $action = owSPSet('ai_action', 'record');
    if (!$r['spam']) return;
    if ($action === 'reject') {
        $reason = '消息未通过自动审核' . ($r['why'] !== '' ? '（' . $r['why'] . '）' : '') . '，请修改后再发';
    } elseif ($action === 'mask') {
        $text = '***';
    }
    Sec::log('spam_ai_hit', (string)($actor['nickname'] ?? '?'),
        ['action' => $action, 'why' => $r['why'], 'ip' => Sec::ip()]);
});

/**
 * 3) IP 封锁：request.start 是核心里最早、且**每个请求都会跑**的钩子
 *    （插件是按需加载的，别的钩子都可能不被触发）。
 */
Plugin::on('request.start', function ($actor) {
    if (!owSPOn('ip_on')) return;
    $list = owSPipList(owSPSet('ip_list'));
    $mode = owSPSet('ip_mode', 'black') === 'white' ? 'white' : 'black';
    $ip = Sec::ip();
    if (!owSPipBlocked($ip, $mode, $list)) return;
    // 被拦的机器往往在循环重试，日志按 IP 限流，否则安全日志会被它一个人写满
    if (Sec::rateLimit('spam_ip_log', $ip, 3600, 10)) {
        Sec::log('spam_ip_block', (string)(is_array($actor) ? ($actor['nickname'] ?? '?') : '?'),
            ['ip' => $ip, 'mode' => $mode, 'uri' => mb_substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 120)]);
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    // 不回显「你被 IP 规则拦住了」这类细节：那等于告诉对方换个 IP 就能绕过哪条规则
    echo '403 Forbidden';
    exit;
});

/** 4) 域名名单下发：只在聊天页（page.footer 只在 renderChat 触发），且只带名单不带密钥 */
Plugin::on('page.footer', function (): void {
    $list = owSPdomains();
    if (!$list) return;
    $warn = owSPSet('domain_warn', '网页包含不安全内容，被多人投诉，已停止自动跳转。');
    echo '<script>window.OW_SPAM_DOMAINS='
        . json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';'
        . 'window.OW_SPAM_DOMAIN_WARN='
        . json_encode(mb_substr($warn, 0, 200), JSON_UNESCAPED_UNICODE) . ';</script>';
});

/* ============================ 后台页 ============================ */

Plugin::adminPage('sensitive-words', '垃圾信息控制', function () {
    $cur = Sec::ip();
    $tabs = [
        ['words', '敏感词', '词库打码'],
        ['flood', '刷屏', '窗口条数与最小间隔'],
        ['domain', '链接域名', '点击弹窗不跳转'],
        ['ip', 'IP 封锁', '黑白名单整站拦截'],
        ['ai', 'AI 防线', '模型判定，默认仅记录'],
    ];
    $h = '<h2>垃圾信息控制</h2><p class="ow-admin-desc">敏感词、刷屏、链接域名、IP 封锁、AI 审核集中在一页管理。'
        . '每个标签独立保存，改动即时生效。</p>';
    $h .= '<div class="ow-card"><div class="ow-spam-tabs" id="owSpamTabs">';
    foreach ($tabs as $i => $t) {
        $h .= '<button type="button" class="ow-spam-tab' . ($i === 0 ? ' active' : '') . '" data-tab="' . $t[0] . '">'
            . '<b>' . $t[1] . '</b><span>' . $t[2] . '</span></button>';
    }
    $h .= '</div>';

    /* ---------- ① 敏感词 ---------- */
    $h .= '<div class="ow-spam-pane" data-pane="words">'
        . '<div class="ow-form-row">'
        . '<div class="ow-form-item"><label>敏感词</label><input class="ow-input" id="owWWord"></div>'
        . '<div class="ow-form-item"><label>替换为</label><input class="ow-input" id="owWRep" value="***"></div>'
        . '<button class="ow-btn ow-btn-primary" onclick="OwSW.wordAdd()">添加</button></div>'
        . '<p style="font-size:12px;color:var(--ow-text-sub);margin-top:4px">对所有输入文字生效：发言、昵称、群名称、群简介、群公告。'
        . '命中即替换，不拦发送。</p>'
        . '<div id="owSWBatch"></div>'
        . '<div class="ow-table-wrap"><table class="ow-table" id="owSWTable"></table></div>'
        . '<div id="owSWPager"></div></div>';

    /* ---------- ② 刷屏 ---------- */
    $h .= '<div class="ow-spam-pane" data-pane="flood" style="display:none">'
        . owSPnum('rate_win', '频率窗口(秒)', 'msg_rate_window', '10', '在这个时长内统计发送条数')
        . owSPnum('rate_max', '窗口内最多条数', 'msg_rate_max', '8', '超过就拒发，登录用户与游客同一套')
        . owSPnum('min_gap', '两条消息最小间隔(秒)', 'spam_min_gap', '0', '填 0 表示不限制；比窗口更细的一层')
        . '<p style="font-size:12px;color:var(--ow-text-sub);margin-top:4px">前两项就是核心发言频率限制（键名未变，只是编辑入口搬到这里），'
        . '第三项由本插件在发送前额外判定。游客另有「系统设置 → 游客、存储 → 游客发言间隔」。</p>'
        . '<button class="ow-btn ow-btn-primary" onclick="OwSW.save(\'flood\')">保存刷屏设置</button></div>';

    /* ---------- ③ 域名 ---------- */
    $h .= '<div class="ow-spam-pane" data-pane="domain" style="display:none">'
        . owSPswitch('domain_on', '启用域名过滤')
        . '<div class="ow-form-item"><label>域名名单</label>'
        . '<textarea class="ow-input" id="owSpam_domain_list" rows="8" style="font-family:Consolas,monospace" placeholder="每行一个，例如&#10;spam-site.com&#10;ads.example.net">'
        . htmlspecialchars(owSPSet('domain_list'), ENT_QUOTES) . '</textarea>'
        . '<p style="font-size:12px">名单里的域名（含其子域）出现在消息链接里时，用户点击不会跳转，只弹一句提示。</p></div>'
        . '<div class="ow-form-item"><label>提示文案</label><input class="ow-input" id="owSpam_domain_warn" maxlength="200" value="'
        . htmlspecialchars(owSPSet('domain_warn', '网页包含不安全内容，被多人投诉，已停止自动跳转。'), ENT_QUOTES) . '"></div>'
        . '<button class="ow-btn ow-btn-primary" onclick="OwSW.save(\'domain\')">保存域名设置</button></div>';

    /* ---------- ④ IP ---------- */
    $h .= '<div class="ow-spam-pane" data-pane="ip" style="display:none">'
        . '<p style="font-size:12px;color:var(--ow-yellow)">你当前的 IP：<b>' . htmlspecialchars($cur, ENT_QUOTES) . '</b>'
        . ' 改动名单前先确认这个地址在名单里，否则保存后你自己也进不来。</p>'
        . owSPswitch('ip_on', '启用 IP 封锁')
        . '<div class="ow-form-item"><label>模式</label><select class="ow-input" id="owSpam_ip_mode">'
        . '<option value="black"' . (owSPSet('ip_mode', 'black') === 'black' ? ' selected' : '') . '>黑名单：名单内的 IP 一律拒绝访问</option>'
        . '<option value="white"' . (owSPSet('ip_mode') === 'white' ? ' selected' : '') . '>白名单：只允许名单内的 IP 访问</option></select>'
        . '<p style="font-size:12px">白名单模式下名单为空视为不启用（防止勾上就把自己关在外面）。</p></div>'
        . '<div class="ow-form-item"><label>IP 名单</label>'
        . '<textarea class="ow-input" id="owSpam_ip_list" rows="8" style="font-family:Consolas,monospace" placeholder="每行一个，支持三种写法&#10;203.0.113.7&#10;203.0.113.0/24&#10;203.0.113.*">'
        . htmlspecialchars(owSPSet('ip_list'), ENT_QUOTES) . '</textarea>'
        . '<p style="font-size:12px">支持精确地址、CIDR 网段、末尾通配三种写法，IPv4 与 IPv6 都可以。'
        . '识别的是服务器连接对端地址，不读任何代理头（那类头客户端可以随便伪造）。</p></div>'
        . '<div class="ow-form-item"><label style="font-weight:400"><input type="checkbox" id="owSpam_ip_keep"> '
        . '我确认要封锁当前 IP（明知会被关在外面）</label></div>'
        . '<button class="ow-btn ow-btn-primary" onclick="OwSW.save(\'ip\')">保存 IP 规则</button></div>';

    /* ---------- ⑤ AI ---------- */
    $h .= '<div class="ow-spam-pane" data-pane="ai" style="display:none">'
        . owSPswitch('ai_on', '启用 AI 防线')
        . '<div class="ow-form-item"><label>命中后</label><select class="ow-input" id="owSpam_ai_action">'
        . owSPopt('ai_action', 'record', '仅记录（不动消息，只写安全日志）')
        . owSPopt('ai_action', 'mask', '打码替换（整条正文替换为 ***）')
        . owSPopt('ai_action', 'reject', '拒发（提示用户修改后再发）') . '</select>'
        . '<p style="font-size:12px">默认「仅记录」：先看一段时间判得准不准，再决定要不要真拦。</p></div>'
        . '<div class="ow-form-row">'
        . owSPnum('ai_timeout', '超时(秒)', 'spam_ai_timeout', '3', '超过这个时间直接放行')
        . owSPnum('ai_daily', '每日调用上限', 'spam_ai_daily', '2000', '填 0 表示不限量')
        . '</div>'
        . '<div class="ow-form-item"><label>接口地址</label><input class="ow-input" id="owSpam_ai_base" maxlength="200" placeholder="https://api.openai.com/v1" value="'
        . htmlspecialchars(owSPSet('ai_base'), ENT_QUOTES) . '">'
        . '<p style="font-size:12px">OpenAI 兼容地址，必须 https（密钥不能明文出网），系统会自动接 /chat/completions。</p></div>'
        . '<div class="ow-form-row">'
        . '<div class="ow-form-item"><label>API Key</label><input class="ow-input" type="password" id="owSpam_ai_key" maxlength="200" autocomplete="off" placeholder="留空表示不修改"></div>'
        . '<div class="ow-form-item"><label>模型</label><input class="ow-input" id="owSpam_ai_model" maxlength="80" value="'
        . htmlspecialchars(owSPSet('ai_model', 'gpt-4o-mini'), ENT_QUOTES) . '"></div></div>'
        . '<div class="ow-form-item"><label>提示词</label>'
        . '<textarea class="ow-input" id="owSpam_ai_prompt" rows="12" style="font-family:Consolas,monospace">'
        . htmlspecialchars(owSPSet('ai_prompt', OWSP_AI_PROMPT), ENT_QUOTES) . '</textarea>'
        . '<p style="font-size:12px">可用占位符：{scene} 场景、{text} 消息正文。'
        . '要求模型只回 JSON（spam / reason 两个字段），回不出 JSON 就按放行处理。'
        . '<b>清空本框并保存 = 恢复系统默认提示词。</b></p></div>'
        . '<div class="ow-form-row"><div class="ow-form-item" style="flex:1"><label>测试文本</label>'
        . '<input class="ow-input" id="owSpamTest" placeholder="粘贴一条消息，看模型怎么判"></div>'
        . '<button class="ow-btn ow-btn-ghost" onclick="OwSW.aiTest()">测试 AI 配置</button></div>'
        . '<div id="owSpamAiStat" style="font-size:12px;color:var(--ow-text-sub);margin:4px 0"></div>'
        . '<button class="ow-btn ow-btn-primary" onclick="OwSW.save(\'ai\')">保存 AI 设置</button></div>';

    $h .= '</div>';
    return $h;
});

/** 数字输入项（三个标签页共用，省得每处手写 label+input+提示） */
function owSPnum(string $key, string $label, string $storeKey, string $def, string $hint = ''): string
{
    $v = (string)DB::setting($storeKey, $def);
    return '<div class="ow-form-item"><label>' . $label . '</label>'
        . '<input class="ow-input" type="number" min="0" id="owSpam_' . $key . '" value="' . htmlspecialchars($v, ENT_QUOTES) . '">'
        . ($hint !== '' ? '<p style="font-size:12px">' . $hint . '</p>' : '') . '</div>';
}
/** 开关（与核心设置页同款视觉：复选框 + 文字） */
function owSPswitch(string $key, string $label): string
{
    return '<div class="ow-form-item"><label style="font-weight:400"><input type="checkbox" id="owSpam_' . $key . '"'
        . (owSPOn($key) ? ' checked' : '') . '> ' . $label . '</label></div>';
}
/** 下拉选项：按当前值决定 selected */
function owSPopt(string $key, string $val, string $label): string
{
    return '<option value="' . $val . '"' . (owSPSet($key, 'record') === $val ? ' selected' : '') . '>' . $label . '</option>';
}

/* ============================ 保存与测试 ============================ */

/**
 * 分组保存。字段名白名单写死在这里 —— 后台表单能提交什么键由这段决定，
 * 不接受任何未列出的键（否则就成了任意 settings 写入）。
 */
Plugin::route('plugin_spam_save', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $g = (string)($ctx['post']['group'] ?? '');
    $p = fn(string $k, string $d = '') => isset($ctx['post'][$k]) ? trim((string)$ctx['post'][$k]) : $d;

    if ($g === 'flood') {
        // 前两项写**核心键**：核心 Chat::send 读的就是它们，编辑入口搬家而存储不换
        DB::setSetting('msg_rate_window', (string)max(0, min(3600, (int)$p('rate_win', '10'))));
        DB::setSetting('msg_rate_max', (string)max(1, min(1000, (int)$p('rate_max', '8'))));
        DB::setSetting('spam_min_gap', (string)max(0, min(3600, (int)$p('min_gap', '0'))));
    } elseif ($g === 'domain') {
        DB::setSetting('spam_domain_on', $p('domain_on') === '1' ? '1' : '0');
        DB::setSetting('spam_domain_list', mb_substr($p('domain_list'), 0, 8000));
        $w = $p('domain_warn', '网页包含不安全内容，被多人投诉，已停止自动跳转。');
        DB::setSetting('spam_domain_warn', mb_substr(preg_replace('/[\r\n]+/u', ' ', $w), 0, 200));
    } elseif ($g === 'ip') {
        $on = $p('ip_on') === '1' ? '1' : '0';
        $mode = $p('ip_mode') === 'white' ? 'white' : 'black';
        $list = owSPipList($p('ip_list'));
        $cur = Sec::ip();
        // 防自锁：新规则会拦住正在保存的管理员时，除非他显式勾选，否则拒绝保存。
        // 只靠页面上那句提醒是不够的 —— 名单一填错，下一次请求就连后台都进不去了。
        if ($on === '1' && owSPipBlocked($cur, $mode, $list) && $p('ip_keep') !== '1') {
            Api::json(['ok' => false, 'msg' => '这套规则会把你当前的 IP（' . $cur . '）拦在门外，已拒绝保存。'
                . '把该地址放进名单（或关掉开关）再试；确实要封锁自己，请勾选「我确认要封锁当前 IP」。']);
        }
        DB::setSetting('spam_ip_on', $on);
        DB::setSetting('spam_ip_mode', $mode);
        DB::setSetting('spam_ip_list', implode("\n", $list));
        Sec::log('spam_ip_save', (string)($ctx['actor']['nickname'] ?? ''),
            ['on' => $on, 'mode' => $mode, 'count' => count($list), 'self_blocked' => owSPipBlocked($cur, $mode, $list) ? 1 : 0]);
    } elseif ($g === 'ai') {
        DB::setSetting('spam_ai_on', $p('ai_on') === '1' ? '1' : '0');
        DB::setSetting('spam_ai_action', in_array($p('ai_action'), ['record', 'mask', 'reject'], true) ? $p('ai_action') : 'record');
        DB::setSetting('spam_ai_timeout', (string)max(1, min(15, (int)$p('ai_timeout', '3'))));
        DB::setSetting('spam_ai_daily', (string)max(0, min(1000000, (int)$p('ai_daily', '2000'))));
        $base = $p('ai_base');
        if ($base !== '' && stripos($base, 'https://') !== 0) {
            Api::json(['ok' => false, 'msg' => '接口地址必须以 https:// 开头（API Key 不能明文出网）']);
        }
        DB::setSetting('spam_ai_base', mb_substr(rtrim($base, '/'), 0, 200));
        DB::setSetting('spam_ai_model', mb_substr($p('ai_model', 'gpt-4o-mini'), 0, 80));
        // key 只在**非空**时覆盖：页面里它是 password 输入框，保存其它字段时不该被清空
        $key = $p('ai_key');
        if ($key !== '') DB::setSetting('spam_ai_key', mb_substr($key, 0, 200));
        DB::setSetting('spam_ai_prompt', mb_substr($p('ai_prompt'), 0, 4000));
    } else {
        Api::json(['ok' => false, 'msg' => '未知的设置分组']);
    }
    Api::json(['ok' => true, 'msg' => '已保存']);
}, ['sensitive' => true]);

/** 测试 AI 配置：管理员填完地址先打一发看通不通，不用等真实消息 */
Plugin::route('plugin_spam_ai_test', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $text = mb_substr(trim((string)($ctx['post']['text'] ?? '')), 0, 500);
    if ($text === '') Api::json(['ok' => false, 'msg' => '请先输入一段测试文本']);
    $r = owSPaiAsk(owSPaiPrompt($text, 'message'));
    Api::json([
        'ok' => true,
        'verdict' => $r['ok'] ? ($r['spam'] ? 'spam' : 'clean') : 'error',
        'why' => $r['why'],
        'raw' => $r['raw'],
        'quota_left' => owSPaiQuotaOk() ? 'ok' : 'exhausted',
    ]);
});

/* ---------- 词库：沿用 v1.0.104 剥离时的表与路由，不改名以免老链接失效 ---------- */

/** 词库分页数据（接入通用列表轮子） */
Plugin::route('plugin_sensitive_words_admin', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $page = max(1, (int)($ctx['post']['page'] ?? 1));
    $size = min(100, max(1, (int)($ctx['post']['size'] ?? 30)));
    $total = (int)DB::val('SELECT COUNT(*) FROM sensitive_words');
    $rows = DB::all('SELECT * FROM sensitive_words ORDER BY id DESC LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size));
    Api::json(['ok' => true, 'data' => ['list' => $rows, 'total' => $total, 'page' => $page, 'size' => $size]]);
});

/** 批量删除（敏感操作） */
Plugin::route('plugin_sensitive_words_batch', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $ids = array_filter(array_map('intval', explode(',', (string)($ctx['post']['ids'] ?? ''))));
    if (!$ids) Api::json(['ok' => false, 'msg' => '未选择敏感词']);
    $ok = 0;
    foreach ($ids as $id) { DB::run('DELETE FROM sensitive_words WHERE id=?', [$id]); $ok++; }
    Sec::log('sensitive_word_batch_del', $ctx['actor']['nickname'], ['count' => $ok]);
    Api::json(['ok' => $ok > 0, 'msg' => '批量删除：成功 ' . $ok . ' 条']);
}, ['sensitive' => true]);

Plugin::route('plugin_sensitive_words_add', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $word = trim((string)($ctx['post']['word'] ?? ''));
    $rep = trim((string)($ctx['post']['replacement'] ?? ''));
    if ($word === '') Api::json(['ok' => false, 'msg' => '敏感词不能为空']);
    if ($rep === '') $rep = '***';
    if (DB::one('SELECT id FROM sensitive_words WHERE word=?', [$word])) Api::json(['ok' => false, 'msg' => '该敏感词已存在']);
    DB::insert('sensitive_words', ['word' => $word, 'replacement' => $rep, 'enabled' => 1]);
    Sec::log('sensitive_word_add', $ctx['actor']['nickname'], ['word' => $word]);
    Api::json(['ok' => true, 'msg' => '已添加']);
});

Plugin::route('plugin_sensitive_words_toggle', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    DB::run('UPDATE sensitive_words SET enabled=? WHERE id=?', [(int)($ctx['post']['enabled'] ?? 0), (int)($ctx['post']['id'] ?? 0)]);
    Api::json(['ok' => true, 'msg' => '已更新']);
});

Plugin::route('plugin_sensitive_words_del', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    DB::run('DELETE FROM sensitive_words WHERE id=?', [(int)($ctx['post']['id'] ?? 0)]);
    Sec::log('sensitive_word_del', $ctx['actor']['nickname'], ['id' => (int)($ctx['post']['id'] ?? 0)]);
    Api::json(['ok' => true, 'msg' => '已删除']);
}, ['sensitive' => true]);

/* ============================ 资源 ============================ */

Plugin::asset('js', 'sensitive-words/admin.js');
Plugin::asset('js', 'sensitive-words/chat.js');
Plugin::asset('css', 'sensitive-words/style.css');
