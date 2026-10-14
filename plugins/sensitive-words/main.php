<?php
/**
 * 垃圾信息控制插件（v1.0.104 由核心剥离为「敏感词过滤」，v1.3.104 扩成多件套）
 *
 * 四道控制，各自生效的位置不同 —— 搞错位置等于白做：
 *   1. 敏感词过滤   核心 text.filter 钩子：命中词库即**替换**（打码），对所有输入文字生效
 *                   （发言、昵称、群名称、群简介、群公告）。词库沿用 sensitive_words 表。
 *   2. 刷屏控制     「窗口内条数」直接接管核心键 msg_rate_window / msg_rate_max
 *                   （核心 Chat::send 已在读，只是编辑入口搬到这里，键不变、不打架）；
 *                   另加「两条消息最小间隔」= spam_min_gap，走新的 text.check 钩子拒发。
 *   3. 域名过滤     **只在前端点击时拦**：名单随聊天页下发，核心 linkifyHtml 经
 *                   window.OW_LINK_GUARD 把命中的链接改成「点了弹窗、不跳转」。
 *                   不在服务端拦发送 —— 按站方定版：垃圾已经进了库，但不让人走进去。
 *                   弹窗里给两个出口（v1.3.106）：「规则说明」（文本后台可改）与
 *                   「申请恢复访问」（游客也可提交 → 落 plugin_spam_appeal 表 + 前台通知所有超管；
 *                   管理员点「通过」就把该域名从名单里删掉，真的恢复访问）。
 *   4. AI 防线      text.check 钩子：把正文交给可配的 OpenAI 兼容接口判定，
 *                   动作三选一（仅记录 / 打码替换 / 拒发），默认仅记录。
 *
 * v1.3.110 移除「IP 封锁」标签页（站点级 IP 黑白名单）：站方判断这条不值得留 ——
 *   真要拦人用的是后台的封禁用户/禁言，而 IP 段一填错就是把自己和一片人关在门外，
 *   风险与收益不成比例。要恢复的话看 git 历史里 v1.3.109 的 main.php 与自检 ②③⑩ 段。
 *
 * ⚠️ 三条不可省的前提：
 *   · 需要记 IP 的地方（申诉、AI 命中日志）一律取 Sec::ip()（REMOTE_ADDR），
 *     不读 X-Forwarded-For / X-Real-IP —— 那些头客户端想写什么写什么。
 *   · AI 的 key 只存后端、只在服务端用，绝不下发前端；base URL 强制 https，
 *     否则等于拿明文往外送密钥。
 *   · AI 判定失败/超时/额度用尽一律**放行**：宁可漏一条垃圾，不能因为第三方接口抖一下
 *     全站发不出消息。超时是**整次请求**的预算（连接 + TLS 握手 + 模型推理），默认 10 秒 ——
 *     v1.3.104 的 3 秒连握手都跑不完，结果是「防线看着开着、其实一条都没判」，
 *     加载时会把仍停在旧默认值 3 秒的配置一次性上调（管理员之后自己选 3 秒不再被覆盖）。
 *   · 网关有时会以自己的输入风控拒收某条消息，且**随机**（实测同一条博彩广告问 6 次：
 *     拦 2 拒 4；正常消息 6/6 都能判）。这种拒收只重问一次，且只用**剩下的预算**，
 *     不撑破管理员设的上限；再失败就放行并说明原因。不做任何绕过对方风控的改写。
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

/** 多行文本 → 去重去空的条目列表（域名名单用；容忍逗号/分号/空格分隔） */
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

/* ==================== 域名申诉（上面「2. 域名过滤」的下游） ==================== */

/**
 * 申诉表：用户点了被拦的链接 → 填一句理由提交 → 落表并通知超级管理员；
 * 管理员点「通过」= 把该域名从黑名单里删掉（真的恢复访问）。
 *
 * 建表写在这里而不是安装脚本里：插件随时可停用/删除，表跟着插件走
 * （与 content-report 同一做法），CREATE IF NOT EXISTS 幂等。
 * status：0 待处理 / 1 已通过 / 2 已拒绝。
 */
DB::run('CREATE TABLE IF NOT EXISTS plugin_spam_appeal (
    id ' . DB::autoId() . ',
    domain VARCHAR(190) NOT NULL DEFAULT \'\',
    kind VARCHAR(8) NOT NULL DEFAULT \'\',
    uid INTEGER NOT NULL DEFAULT 0,
    nick VARCHAR(64) NOT NULL DEFAULT \'\',
    ip VARCHAR(45) NOT NULL DEFAULT \'\',
    ' . DB::textCol('note') . ',
    status INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0,
    handled_at INTEGER NOT NULL DEFAULT 0
)');
DB::createIndex('plugin_spam_appeal_domain', 'plugin_spam_appeal', 'domain');

/**
 * 默认规则说明。内容与「内容举报」的理由同一批口径，但**独立存一份**：
 * 读举报插件的配置就等于让两个插件互相依赖，内容举报一停用，这里的弹窗就空了。
 */
const OWSP_DOMAIN_RULES = "垃圾广告\n辱骂攻击\n色情低俗\n违法违规\n刷屏灌水\n其他";

/** 规则说明按行拆开（弹窗与后台共用；空行与重复行去掉） */
function owSPdomainRules(): array
{
    $raw = owSPSet('domain_rules', '');
    if (trim($raw) === '') $raw = OWSP_DOMAIN_RULES;
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $l) {
        $l = trim($l);
        if ($l !== '' && !in_array($l, $out, true)) $out[] = $l;
    }
    return $out;
}

/**
 * 把域名从黑名单里摘掉（「通过申诉」的动作）。
 * 名单条目可能写成 https://x.com/y 这种整条 URL，所以按**归一后的主机**比对，
 * 而不是拿字符串硬等 —— 否则管理员粘过 URL，通过一次申诉名单纹丝不动。
 */
function owSPremoveDomain(string $domain): int
{
    $lines = preg_split('/\r\n|\r|\n/', owSPSet('domain_list')) ?: [];
    $kept = []; $removed = 0;
    foreach ($lines as $l) {
        if (trim($l) === '') { $kept[] = $l; continue; }
        if (owSPnormDomain($l) === $domain) { $removed++; continue; }
        $kept[] = $l;
    }
    if ($removed > 0) DB::setSetting('spam_domain_list', implode("\n", $kept));
    return $removed;
}

/* ============================ 3. AI 防线 ============================ */

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

/**
 * AI 超时预算（秒）。
 *
 * 为什么默认值从 3 秒提到 10 秒：一次判定 = TCP 连接 + TLS 握手 + 模型推理，实测最省事的
 * 兼容网关也要 7 秒以上（握手 2 秒 + 推理 5 秒）。3 秒连握手都完不成，于是「AI 防线」
 * 在每个人身上静默失败、静默放行 —— 开关看着是开的，其实一条都没判。
 * 上限留到 30 秒兜住慢模型，但发送请求会等这么久，所以提示词里让管理员自己权衡。
 */
const OWSP_AI_TIMEOUT_MIN = 3;
const OWSP_AI_TIMEOUT_MAX = 30;
const OWSP_AI_TIMEOUT_DEF = 10;

/** 当前生效的超时预算。旧数据可能留着 1 秒或 999 秒这类越界值，读写两头都夹回区间 */
function owSPaiTimeout(): int
{
    return max(OWSP_AI_TIMEOUT_MIN, min(OWSP_AI_TIMEOUT_MAX, owSPInt('ai_timeout', OWSP_AI_TIMEOUT_DEF)));
}

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
 * 把 curl 的失败翻译成人能照着做的一句话。
 *
 * libcurl 原文只会说 "SSL connection timeout" —— 看不出卡在哪个阶段，也不知道该把哪个数调大，
 * 管理员只能反复点测试按钮。这里按阶段分（没连上 / TLS 卡住 / 接口不回话），并给出实测耗时，
 * 因为这三种情况的处置完全不同：换地址、查证书、调超时。
 */
function owSPaiErrText(int $errno, string $cerr, int $ms, int $budget, float $connT, float $appT): string
{
    $sec = number_format($ms / 1000, 1);
    if ($errno === CURLE_OPERATION_TIMEDOUT) {
        // ⚠️ PHP 的 curl_getinfo 用 **0** 表示「这一阶段没走到」，不是 -1（实测：连不上时
        //    connect_time / appconnect_time 都是 0.0）。判 < 0 等于这条分支永不生效，
        //    任何「地址不通」都会被报成「接口太慢」，把管理员引去调一个不该调的数。
        if ($connT <= 0) {
            return '连不上接口：等了 ' . $sec . ' 秒还没建立连接（上限 ' . $budget
                . ' 秒）。先确认这台服务器能访问外网、地址里的端口没被挡。';
        }
        if ($appT <= 0) {
            return 'TLS 握手超时：连接通了，但 ' . $sec . ' 秒还没完成证书协商（上限 ' . $budget
                . ' 秒）。把「超时(秒)」调到 10 秒以上；若一直卡在这一步，多半是证书链不全或被网络设备拦截。';
        }
        return '接口太慢：' . $sec . ' 秒没返回内容（上限 ' . $budget
            . ' 秒）。模型判定本身就要几秒，把「超时(秒)」调到 ' . owSPaiSuggest($budget) . ' 秒再试。';
    }
    if ($errno === CURLE_COULDNT_RESOLVE_HOST) return '域名解析失败：地址里的主机名查不到（' . mb_substr($cerr, 0, 60) . '）';
    if ($errno === CURLE_COULDNT_CONNECT) return '连不上接口：' . mb_substr($cerr, 0, 80);
    // 证书类错误码一堆，合并成一句「证书有问题」就够管理员动手了。
    // ⚠️ 只用 PHP 的 curl 扩展真正定义的常量：CURLE_PEER_FAILED_VERIFICATION / _ISSUER_ERROR
    //    这些 libcurl 名字在 PHP 里不存在，写上去就是 Undefined constant 直接炸。
    if (in_array($errno, [CURLE_SSL_CONNECT_ERROR, CURLE_SSL_CERTPROBLEM, CURLE_SSL_CIPHER,
        CURLE_SSL_CACERT, CURLE_SSL_CACERT_BADFILE, CURLE_SSL_PEER_CERTIFICATE], true)) {
        return 'HTTPS 证书校验失败：' . mb_substr($cerr, 0, 100) . '（接口地址要用证书有效的 https）';
    }
    return '请求失败：' . ($cerr ?: '网络不通') . '（errno ' . $errno . '，已等 ' . $sec . ' 秒）';
}

/** 超时报错里的建议值：够把没跑完的请求跑完，又不把人吊死在发送上 */
function owSPaiSuggest(int $budget): int
{
    return (int)max(OWSP_AI_TIMEOUT_DEF, min(OWSP_AI_TIMEOUT_MAX, $budget * 2));
}

/**
 * 解析返回体。有些 OpenAI 兼容网关把一次性应答也按流式格式发（首行是 "data: {...}"），
 * 直接 json_decode 得到 null，于是我们把接口明明说出口的错误原因报成「响应里没有 content」。
 * 先按整体解，解不动再逐行剥 SSE 帧头试一次。
 */
function owSPaiDecode(string $resp): array
{
    $j = json_decode($resp, true);
    if (is_array($j)) return $j;
    // ⚠️ 不能用 \R 分行：返回体是**按字节**处理的（没加 /u），而 PCRE 在非 UTF 模式下把 0x85
    //    也算换行 —— 中文 UTF-8 序列里到处是 0x85（例如「内」= E5 86 85），会把一个字符拦腰切开。
    foreach (preg_split('/\r\n|\r|\n/', $resp) ?: [] as $line) {
        $line = trim((string)preg_replace('/^data:\s*/', '', trim($line)));
        if ($line === '' || $line === '[DONE]' || strpos($line, '{') !== 0) continue;
        $j = json_decode($line, true);
        if (is_array($j)) return $j;
    }
    return [];
}

/**
 * 网关把自己的输入风控判成「违规」而拒绝处理这次请求。
 * 与站点配置无关，而且实测**随机**：同一条博彩广告连问 6 次是「拦 拒 拒 拦 拒 拒」，
 * 正常消息则 6/6 都能判 —— 拒的恰好是最需要判的那类文本。
 * 只做一次带剩余预算的重试；不做任何绕过（改写/编码正文去躲对方风控不是这里该干的事）。
 */
function owSPaiIsRefusal(array $err): bool
{
    $code = strtolower(trim((string)($err['code'] ?? '') . ' ' . (string)($err['type'] ?? '')));
    if (strpos($code, 'content_check') !== false) return true;
    return (bool)preg_match('/sensitive content|content policy/i', (string)($err['message'] ?? ''));
}

const OWSP_AI_REFUSAL = '网关拒绝审核这段文本（它自己的输入风控，与站点配置无关；已按放行处理）。'
    . '被拒的往往正是广告与违规内容 —— 要覆盖这一类，得换一家接口或让服务商为审核流量放行。';

/**
 * 问一次模型。返回 ['ok'=>bool,'spam'=>?bool,'why'=>string,'raw'=>string,'ms'=>int]
 * 任何一步不对都 ok=false + 人话原因 —— 调用方一律放行，绝不因为审核接口挂了而拦人。
 *
 * $budget 只给内部重试用（剩余秒数），外部一律留空读配置：
 * 重试不能把「超时(秒)」这个对管理员承诺的上限撑破。
 */
function owSPaiAsk(string $prompt, int $budget = 0, int $attempt = 1): array
{
    // 统一的失败出口：调用方只看 ok 与 raw，但测试页要知道这一次到底等了多久
    $fail = fn(string $raw, int $ms = 0): array => ['ok' => false, 'spam' => null, 'why' => '', 'raw' => $raw, 'ms' => $ms];

    $base = trim(owSPSet('ai_base'));
    $key  = trim(owSPSet('ai_key'));
    $model = trim(owSPSet('ai_model', 'gpt-4o-mini'));
    if ($base === '' || $model === '') return $fail('未配置接口地址或模型');
    if (stripos($base, 'https://') !== 0) return $fail('接口地址必须是 https（否则密钥明文出网）');
    $url = rtrim($base, '/') . '/chat/completions';
    $body = json_encode([
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0,
    ], JSON_UNESCAPED_UNICODE);
    if ($body === false) return $fail('请求体编码失败');

    $hdr = ['Content-Type: application/json', 'Accept: application/json'];
    if ($key !== '') $hdr[] = 'Authorization: Bearer ' . $key;
    $timeout = $budget > 0 ? max(1, min(OWSP_AI_TIMEOUT_MAX, $budget)) : owSPaiTimeout();
    // 连接阶段单独设上限：预算可能被调到 30 秒等模型，但「地址根本不通」不该也等 30 秒 ——
    // 那样每条消息都要卡满半分钟才放行。
    $connTo = min(5, $timeout);

    $ch = function_exists('curl_init') ? curl_init($url) : null;
    if ($ch) {
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $hdr,
            CURLOPT_CONNECTTIMEOUT => $connTo,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if (class_exists('Upgrade')) {
            $ca = Upgrade::caBundle();
            if ($ca !== null) curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
        $t0 = microtime(true);
        $resp = curl_exec($ch);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $errno = curl_errno($ch);
        $cerr = $resp === false ? curl_error($ch) : '';
        $connT = (float)curl_getinfo($ch, CURLINFO_CONNECT_TIME);
        $appT = (float)curl_getinfo($ch, CURLINFO_APPCONNECT_TIME);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($resp === false) {
            return $fail(owSPaiErrText($errno, $cerr, $ms, $timeout, $connT, $appT), $ms);
        }
        if ($code !== 200) {
            return $fail('HTTP ' . $code . '（耗时 ' . number_format($ms / 1000, 1) . ' 秒）：'
                . mb_substr((string)$resp, 0, 200), $ms);
        }
    } else {
        if (!ini_get('allow_url_fopen')) return $fail('无 curl 且 allow_url_fopen 关闭');
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $hdr) . "\r\n",
            'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true,
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $t0 = microtime(true);
        $resp = @file_get_contents($url, false, $ctx);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        if ($resp === false) return $fail('请求失败（无 curl 扩展的回落通道也不通，已等 '
            . number_format($ms / 1000, 1) . ' 秒）', $ms);
    }

    $j = owSPaiDecode((string)$resp);
    $content = (string)($j['choices'][0]['message']['content'] ?? '');
    if ($content === '' && isset($j['error']) && is_array($j['error'])) {
        $em = mb_substr((string)($j['error']['message'] ?? ''), 0, 200);
        if (owSPaiIsRefusal($j['error'])) {
            // 拒审是随机的，趁热再问一次；但只用**剩下的预算**，不撑破管理员设的上限
            $left = $timeout - (int)ceil($ms / 1000);
            if ($attempt === 1 && $left >= 3) {
                $r2 = owSPaiAsk($prompt, $left, 2);
                $r2['ms'] = (int)($r2['ms'] ?? 0) + $ms;
                return $r2;
            }
            return $fail(OWSP_AI_REFUSAL . ' 网关原话：' . ($em !== '' ? $em : mb_substr((string)$resp, 0, 120)), $ms);
        }
        return $fail('接口报错：' . ($em !== '' ? $em : mb_substr((string)$resp, 0, 200)), $ms);
    }
    if ($content === '') return $fail('响应里没有 content：' . mb_substr((string)$resp, 0, 200), $ms);
    $r = owSPaiParse($content);
    $r['ms'] = $ms;
    return $r;
}

/** 额度闸门：默认每天 2000 次，防止一个失控的循环把账单打爆 */
function owSPaiQuotaOk(): bool
{
    $daily = owSPInt('ai_daily', 2000);
    if ($daily <= 0) return true;              // 0 = 不限量（站方自己承担）
    return Sec::rateLimit('spam_ai', 'all', 86400, $daily);
}

/**
 * v1.3.107 一次性上调旧默认超时（加载时调用，可单独测）。
 *
 * 3 秒是 v1.3.104 的默认值，也是绝大多数站点从没碰过的值，而它连 TLS 握手都跑不完 ——
 * 结果是「AI 防线」看着开着、实际对每条消息都静默放行。只动「仍是那个旧默认值」且没标记过
 * 的情形：管理员之后主动改回 3 秒，就不再被覆盖。
 */
function owSPaiTimeoutMigrate(): bool
{
    if (DB::setting('spam_ai_timeout') !== '3' || (string)DB::setting('spam_ai_timeout_v107', '') !== '') return false;
    DB::setSetting('spam_ai_timeout', (string)OWSP_AI_TIMEOUT_DEF);
    DB::setSetting('spam_ai_timeout_v107', '1');
    return true;
}
owSPaiTimeoutMigrate();

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
 * 2) + 4) 拒发与 AI：核心 text.check 钩子（只对消息正文触发）。
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

/** 3) 域名名单下发：只在聊天页（page.footer 只在 renderChat 触发），且只带名单不带密钥 */
Plugin::on('page.footer', function (): void {
    $list = owSPdomains();
    if (!$list) return;
    $warn = owSPSet('domain_warn', '网页包含不安全内容，被多人投诉，已停止自动跳转。');
    // 规则文本与「能不能申诉」一并下发：弹窗是点击瞬间拼的，为它发一次 XHR 会让按钮先空一下
    echo '<script>window.OW_SPAM_DOMAINS='
        . json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';'
        . 'window.OW_SPAM_DOMAIN_WARN='
        . json_encode(mb_substr($warn, 0, 200), JSON_UNESCAPED_UNICODE) . ';'
        . 'window.OW_SPAM_DOMAIN_RULES='
        . json_encode(owSPdomainRules(), JSON_UNESCAPED_UNICODE) . ';'
        . 'window.OW_SPAM_APPEAL_ON=' . (owSPOn('appeal_on') ? '1' : '0') . ';'
        . 'window.OW_SPAM_APPEAL_LIMIT=' . max(20, min(1000, owSPInt('appeal_note_limit', 300))) . ';</script>';
});

/* ============================ 后台页 ============================ */

Plugin::adminPage('sensitive-words', '垃圾信息控制', function () {
    $tabs = [
        ['words', '敏感词', '词库打码'],
        ['flood', '刷屏', '窗口条数与最小间隔'],
        ['domain', '链接域名', '点击弹窗不跳转'],
        ['appeal', '访问申诉', '用户申请恢复访问'],
        ['ai', 'AI 防线', '模型判定，默认仅记录'],
    ];
    $h = '<h2>垃圾信息控制</h2><p class="ow-admin-desc">敏感词、刷屏、链接域名、访问申诉、AI 审核集中在一页管理。'
        . '每个标签独立保存，改动即时生效。</p>';
    // 分组标签用核心通用轮子 owTabs（v1.3.113）：结构与配色都在 owlsgo.css / chat.js，
    // 这里只出 HTML；点击走标签条上的 onclick 委托，插件侧不再写一份显隐逻辑。
    // 首个标签的 is-active 与其它面板的 display:none 都由这里一次性写定，是这一页的唯一真相。
    $h .= '<div class="ow-card"><div class="ow-pagetabs" id="owSpamTabs" onclick="owTabs.click(this, event)">';
    foreach ($tabs as $i => $t) {
        $h .= '<button type="button" class="ow-pagetab' . ($i === 0 ? ' is-active' : '') . '" data-tab="' . $t[0] . '">'
            . '<b>' . $t[1] . '</b><span>' . $t[2] . '</span></button>';
    }
    $h .= '</div>';

    /* ---------- ① 敏感词 ---------- */
    $h .= '<div class="ow-pagetab-panel ow-spam-pane" data-pane="words">'
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
    $h .= '<div class="ow-pagetab-panel ow-spam-pane" data-pane="flood" style="display:none">'
        . owSPnum('rate_win', '频率窗口(秒)', 'msg_rate_window', '10', '在这个时长内统计发送条数')
        . owSPnum('rate_max', '窗口内最多条数', 'msg_rate_max', '8', '超过就拒发，登录用户与游客同一套')
        . owSPnum('min_gap', '两条消息最小间隔(秒)', 'spam_min_gap', '0', '填 0 表示不限制；比窗口更细的一层')
        . '<p style="font-size:12px;color:var(--ow-text-sub);margin-top:4px">前两项就是核心发言频率限制（键名未变，只是编辑入口搬到这里），'
        . '第三项由本插件在发送前额外判定。游客另有「系统设置 → 游客、存储 → 游客发言间隔」。</p>'
        . '<button class="ow-btn ow-btn-primary" onclick="OwSW.save(\'flood\')">保存刷屏设置</button></div>';

    /* ---------- ③ 域名 ---------- */
    $h .= '<div class="ow-pagetab-panel ow-spam-pane" data-pane="domain" style="display:none">'
        . owSPswitch('domain_on', '启用域名过滤')
        . '<div class="ow-form-item"><label>域名名单</label>'
        . '<textarea class="ow-input" id="owSpam_domain_list" rows="8" style="font-family:Consolas,monospace" placeholder="每行一个，例如&#10;spam-site.com&#10;ads.example.net">'
        . htmlspecialchars(owSPSet('domain_list'), ENT_QUOTES) . '</textarea>'
        . '<p style="font-size:12px">名单里的域名（含其子域）出现在消息链接里时，用户点击不会跳转，只弹一句提示。</p></div>'
        . '<div class="ow-form-item"><label>提示文案</label><input class="ow-input" id="owSpam_domain_warn" maxlength="200" value="'
        . htmlspecialchars(owSPSet('domain_warn', '网页包含不安全内容，被多人投诉，已停止自动跳转。'), ENT_QUOTES) . '"></div>'
        . '<div class="ow-form-item"><label>规则说明</label>'
        . '<textarea class="ow-input" id="owSpam_domain_rules" rows="7" style="resize:vertical" placeholder="每行一条">'
        . htmlspecialchars(owSPSet('domain_rules', OWSP_DOMAIN_RULES), ENT_QUOTES) . '</textarea>'
        . '<p style="font-size:12px">用户点被拦链接时，弹窗里「规则说明」按钮展示的就是这几行 —— 与举报理由同一套口径，独立一份，停用内容举报插件也不影响。</p></div>'
        . '<button class="ow-btn ow-btn-primary" onclick="OwSW.save(\'domain\')">保存域名设置</button></div>';

    /* ---------- ④ 访问申诉 ---------- */
    $h .= '<div class="ow-pagetab-panel ow-spam-pane" data-pane="appeal" style="display:none">'
        . owSPswitch('appeal_on', '允许申请恢复访问', '关闭后弹窗只剩「规则说明」，用户不能再提交申诉')
        . '<div class="ow-form-row">'
        . owSPnum('appeal_interval', '同一人申诉间隔(秒)', 'spam_appeal_interval', '300', '填 0 表示不限制')
        . owSPnum('appeal_note_limit', '申请说明字数上限', 'spam_appeal_note_limit', '300', '')
        . '</div>'
        . '<p style="font-size:12px;color:var(--ow-text-sub);margin-bottom:10px">游客与登录用户都能提交；'
        . '新申诉会以站内通知发给所有超级管理员。同一人对同一域名只留一条待处理记录。'
        . '下面这张列表只读，处理与删除都即时生效，不需要保存。</p>'
        . '<button class="ow-btn ow-btn-primary" onclick="OwSW.save(\'appeal\')">保存申诉设置</button>'
        . '<div class="ow-form-row" style="align-items:center;margin-top:14px">'
        . '<div class="ow-form-item" style="min-width:180px"><label>按状态筛选</label>'
        . '<select class="ow-input" id="owSpamAppealFilter" onchange="OwSW.appealList(1)">'
        . '<option value="">全部</option><option value="0">待处理</option>'
        . '<option value="1">已通过</option><option value="2">已拒绝</option></select></div>'
        . '<span id="owSpamAppealStat" style="margin-left:auto;color:var(--ow-text-sub);font-size:12px"></span>'
        . '</div>'
        . '<div id="owSpamAppealBatch"></div>'
        . '<div class="ow-table-wrap"><table class="ow-table" id="owSpamAppealTable"></table></div>'
        . '<div id="owSpamAppealPager" style="margin-top:10px"></div></div>';

    /* ---------- ⑤ AI ---------- */
    $h .= '<div class="ow-pagetab-panel ow-spam-pane" data-pane="ai" style="display:none">'
        . owSPswitch('ai_on', '启用 AI 防线')
        . '<div class="ow-form-item"><label>命中后</label><select class="ow-input" id="owSpam_ai_action">'
        . owSPopt('ai_action', 'record', '仅记录（不动消息，只写安全日志）')
        . owSPopt('ai_action', 'mask', '打码替换（整条正文替换为 ***）')
        . owSPopt('ai_action', 'reject', '拒发（提示用户修改后再发）') . '</select>'
        . '<p style="font-size:12px">默认「仅记录」：先看一段时间判得准不准，再决定要不要真拦。</p></div>'
        . '<div class="ow-form-row">'
        . owSPnum('ai_timeout', '超时(秒)', 'spam_ai_timeout', (string)OWSP_AI_TIMEOUT_DEF,
            '3～30 秒。一次判定含连接、TLS 握手与模型推理，实测要 5～10 秒；设小了就是「看着开着、其实一条都没判」。'
            . '这期间发送请求要等接口回话，别设太长。', 3, 30)
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
        . '网关偶尔会自己拒收某条消息（它那一侧的输入风控，与这里无关），插件会趁热再问一次，仍失败就放行。'
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
function owSPnum(string $key, string $label, string $storeKey, string $def, string $hint = '', int $min = 0, int $max = 0): string
{
    $v = (string)DB::setting($storeKey, $def);
    // min/max 只给浏览器即时反馈，真正的夹紧在服务端保存分支（前端参数不能信）
    return '<div class="ow-form-item"><label>' . $label . '</label>'
        . '<input class="ow-input" type="number" min="' . $min . '"' . ($max > 0 ? ' max="' . $max . '"' : '')
        . ' id="owSpam_' . $key . '" value="' . htmlspecialchars($v, ENT_QUOTES) . '">'
        . ($hint !== '' ? '<p style="font-size:12px">' . $hint . '</p>' : '') . '</div>';
}
/**
 * 开关（与核心设置页同款 .ow-switch-row）。
 *
 * 后台的布尔设置项一律用开关，不用裸打勾框：开关一眼能看出「现在是开着还是关着」，
 * 打勾框只表达「勾了没有」，一排设置下来要逐个读文字才知道站点当前行为。
 * 结构照核心 switchHtml()：隐藏的 checkbox + 一个 for 到它的轨道 label，
 * is-on 类由 admin.js 调核心的 bindSwitches 同步（switchHtml / bindSwitches 都由 chat.js 挂到 window）。
 */
function owSPswitch(string $key, string $label, string $hint = ''): string
{
    $on = owSPOn($key);
    $id = htmlspecialchars('owSpam_' . $key, ENT_QUOTES);
    $escLabel = htmlspecialchars($label, ENT_QUOTES);
    return '<div class="ow-switch-row">'
        . '<span class="ow-switch-label">' . $escLabel . '</span>'
        . '<input type="checkbox" class="ow-switch-input" id="' . $id . '"' . ($on ? ' checked' : '') . '>'
        . '<label class="ow-switch' . ($on ? ' is-on' : '') . '" for="' . $id . '"></label>'
        . ($hint !== '' ? '<p class="ow-switch-hint">' . htmlspecialchars($hint, ENT_QUOTES) . '</p>' : '')
        . '</div>';
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
        // 规则说明留换行（一行一条，弹窗按行渲染）；清空 = 回到默认那六条
        DB::setSetting('spam_domain_rules', mb_substr(trim($p('domain_rules')), 0, 2000));
    } elseif ($g === 'appeal') {
        DB::setSetting('spam_appeal_on', $p('appeal_on') === '1' ? '1' : '0');
        DB::setSetting('spam_appeal_interval', (string)max(0, min(86400, (int)$p('appeal_interval', '300'))));
        DB::setSetting('spam_appeal_note_limit', (string)max(20, min(1000, (int)$p('appeal_note_limit', '300'))));
    } elseif ($g === 'ai') {
        DB::setSetting('spam_ai_on', $p('ai_on') === '1' ? '1' : '0');
        DB::setSetting('spam_ai_action', in_array($p('ai_action'), ['record', 'mask', 'reject'], true) ? $p('ai_action') : 'record');
        DB::setSetting('spam_ai_timeout', (string)max(OWSP_AI_TIMEOUT_MIN,
            min(OWSP_AI_TIMEOUT_MAX, (int)$p('ai_timeout', (string)OWSP_AI_TIMEOUT_DEF))));
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
        // 耗时与上限一起回：管理员要据此判断「该把超时调大」还是「这地址根本不通」
        'ms' => (int)($r['ms'] ?? 0),
        'budget' => owSPaiTimeout(),
        'quota_left' => owSPaiQuotaOk() ? 'ok' : 'exhausted',
    ]);
});

/* ============================ 访问申诉路由 ============================ */

/**
 * 前台：提交申诉。游客与登录用户都能提（被拦的链接游客也看得见，只让登录用户申诉
 * 等于给游客留一个点了没反应的按钮）。防灌水靠三道：
 * 只能申诉**当前真在黑名单里**的域名（这张表不会变成任意留言箱）、
 * 同一人同一域名有待处理记录就不再收、按人限流。
 */
Plugin::route('plugin_spam_appeal_submit', function (array $ctx) {
    if (!owSPOn('appeal_on')) Api::json(['ok' => false, 'msg' => '站点未开放访问申诉'], 403);
    $actor = $ctx['actor'];
    $kind = (string)($actor['kind'] ?? 'none');
    if ($kind !== 'user' && $kind !== 'guest') Api::json(['ok' => false, 'msg' => '请先登录或刷新页面'], 403);
    $uid = (int)($actor['id'] ?? 0);
    $nick = mb_substr((string)($actor['nickname'] ?? ''), 0, 64);

    $domain = owSPnormDomain((string)($ctx['post']['domain'] ?? ''));
    if ($domain === '' || strlen($domain) > 190) Api::json(['ok' => false, 'msg' => '域名无效']);
    if (!owSPdomainHit($domain, owSPdomains())) Api::json(['ok' => false, 'msg' => '该域名当前未被拦截，无需申诉']);

    $limit = max(20, min(1000, owSPInt('appeal_note_limit', 300)));
    $note = trim((string)($ctx['post']['note'] ?? ''));
    if ($note === '') Api::json(['ok' => false, 'msg' => '请填写申请说明']);
    if (mb_strlen($note) > $limit) Api::json(['ok' => false, 'msg' => '申请说明不能超过 ' . $limit . ' 字']);

    if (DB::one('SELECT id FROM plugin_spam_appeal WHERE domain=? AND kind=? AND uid=? AND status=0',
        [$domain, $kind, $uid])) Api::json(['ok' => false, 'msg' => '你已经申诉过这个域名，正在等待处理']);
    $gap = owSPInt('appeal_interval', 300);
    if ($gap > 0 && !Sec::rateLimit('spam_appeal', $kind . $uid, $gap, 1)) {
        Api::json(['ok' => false, 'msg' => '申诉过于频繁，请 ' . $gap . ' 秒后再试']);
    }

    // 说明文字也过一遍敏感词（与举报的补充说明同口径）
    Plugin::fire('text.filter', [&$note, 'appeal', $actor]);

    DB::insert('plugin_spam_appeal', [
        'domain' => $domain, 'kind' => $kind, 'uid' => $uid, 'nick' => $nick,
        'ip' => Sec::ip(), 'note' => mb_substr($note, 0, $limit),
        'status' => 0, 'created_at' => time(), 'handled_at' => 0,
    ]);

    // 通知所有超级管理员（与「仓库有新版」同一批人、同一条前台通知通道）。
    // 库里只存 kind + 参数，整句模板交给语言包翻译。
    foreach (DB::all("SELECT id FROM users WHERE role='admin'") as $adm) {
        Notice::push((int)$adm['id'], 'spam_appeal', ['nick' => $nick, 'domain' => $domain]);
    }
    Sec::log('spam_appeal_submit', $nick, ['domain' => $domain, 'kind' => $kind, 'ip' => Sec::ip()]);
    Api::json(['ok' => true, 'msg' => '申诉已提交，等待管理员处理']);
});

/** 后台：申诉列表（分页 + 按状态筛） */
Plugin::route('plugin_spam_appeal_list', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $page = max(1, (int)($ctx['post']['page'] ?? 1));
    $size = min(100, max(1, (int)($ctx['post']['size'] ?? 20)));
    $status = isset($ctx['post']['status']) && $ctx['post']['status'] !== '' ? (int)$ctx['post']['status'] : -1;
    $where = '1=1'; $args = [];
    if ($status >= 0) { $where .= ' AND status=?'; $args[] = $status; }
    $total = (int)DB::val('SELECT COUNT(*) FROM plugin_spam_appeal WHERE ' . $where, $args);
    $rows = DB::all('SELECT * FROM plugin_spam_appeal WHERE ' . $where . ' ORDER BY id DESC LIMIT '
        . $size . ' OFFSET ' . (($page - 1) * $size), $args);
    Api::json(['ok' => true, 'data' => ['list' => $rows, 'total' => $total, 'page' => $page, 'size' => $size,
        'pending' => (int)DB::val('SELECT COUNT(*) FROM plugin_spam_appeal WHERE status=0')]]);
});

/**
 * 后台：处理申诉。
 * 「通过」= 把该域名从黑名单里删掉（用户申请的是恢复访问，那就真的恢复）；
 * 只标记不动名单等于让他再点一次还是拦着。「拒绝」仅标记。
 */
Plugin::route('plugin_spam_appeal_handle', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $id = (int)($ctx['post']['id'] ?? 0);
    $status = (int)($ctx['post']['status'] ?? 0);
    if (!in_array($status, [1, 2], true)) Api::json(['ok' => false, 'msg' => '非法状态']);
    $row = DB::one('SELECT * FROM plugin_spam_appeal WHERE id=?', [$id]);
    if (!$row) Api::json(['ok' => false, 'msg' => '申诉不存在或已被删除']);
    if ((int)$row['status'] !== 0) Api::json(['ok' => false, 'msg' => '这条申诉已经处理过了']);
    $domain = (string)$row['domain'];
    $removed = $status === 1 ? owSPremoveDomain($domain) : 0;
    DB::run('UPDATE plugin_spam_appeal SET status=?, handled_at=? WHERE id=?', [$status, time(), $id]);
    Sec::log('spam_appeal_handle', (string)($ctx['actor']['nickname'] ?? ''),
        ['id' => $id, 'status' => $status, 'domain' => $domain, 'removed' => $removed]);
    Api::json(['ok' => true, 'msg' => $status === 2 ? '已拒绝'
        : ($removed > 0 ? '已通过：' . $domain . ' 已从名单移除（用户刷新页面后链接恢复可点）'
            : '已标记通过，但该域名已不在名单里（可能先前被手动移除）')]);
}, ['sensitive' => true]);

/** 后台：删除申诉记录（敏感操作） */
Plugin::route('plugin_spam_appeal_delete', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    DB::run('DELETE FROM plugin_spam_appeal WHERE id=?', [(int)($ctx['post']['id'] ?? 0)]);
    Sec::log('spam_appeal_delete', (string)($ctx['actor']['nickname'] ?? ''), ['id' => (int)($ctx['post']['id'] ?? 0)]);
    Api::json(['ok' => true, 'msg' => '已删除']);
}, ['sensitive' => true]);

/**
 * 后台：批量删除申诉记录（敏感操作）。
 * 只删记录、不碰黑名单 —— 与单条删除同一语义。批量入口绝不能顺手把域名也放出去：
 * 勾一批历史申诉点删除，等于一次性放行一堆没人复审的站点。
 */
Plugin::route('plugin_spam_appeal_batch', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $ids = array_values(array_unique(array_filter(
        array_map('intval', explode(',', (string)($ctx['post']['ids'] ?? ''))),
        fn(int $v): bool => $v > 0)));
    if (!$ids) Api::json(['ok' => false, 'msg' => '未选择申诉']);
    if (count($ids) > 200) Api::json(['ok' => false, 'msg' => '一次最多删除 200 条']);
    $ok = 0;
    foreach ($ids as $id) { DB::run('DELETE FROM plugin_spam_appeal WHERE id=?', [$id]); $ok++; }
    Sec::log('spam_appeal_batch_del', (string)($ctx['actor']['nickname'] ?? ''), ['count' => $ok]);
    Api::json(['ok' => $ok > 0, 'msg' => '批量删除：成功 ' . $ok . ' 条']);
}, ['sensitive' => true]);

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
