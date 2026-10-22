<?php
/**
 * 垃圾信息控制插件（v1.0.104 由核心剥离为「敏感词过滤」，v1.3.104 扩成多件套）
 *
 * 五道控制，各自生效的位置不同 —— 搞错位置等于白做：
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
 *   5. 违禁词库     （v1.4.0）两路叠加，都做打码：本地 txt 词库（data/lexicon）编译成
 *                   一份映射后 strtr 单次扫描；接口盒子「敏感词/违禁词检测」线路二
 *                   （只对消息正文调用，超时/报错/额度用尽一律放行）。词库可后台在线下载，
 *                   也能自己放进目录；更新检测走计划任务，发现更新给超级管理员发系统通知
 *                   （core/notice.php 内置的 spam_lexicon_update 类型）。
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

/* ============================ 5. 违禁词库（v1.4.0） ============================ */

/**
 * 「违禁词库」= 本地词库（txt 文件）+ 接口盒子线路二，两者都只做**打码替换**，
 * 与上面「1. 敏感词」的人工词库同一语义：命中即换掉，不拦发送。
 *
 * 为什么另起一套而不并入 sensitive_words 表：
 *   · 量级差一个数量级 —— 本地词库实测 5.1 万条，人工词库几十条。逐条插表再逐条
 *     mb_ereg_replace，每次发言要跑 5 万次正则，等于把发言拖成秒级；而且那张表
 *     （core/db.php 的 sensitive_words）没有 word 索引、没有 updated_at，回表更慢。
 *   · 词库是**别人维护的外部数据**，会被整体替换，放进业务表既难回滚也难复核。
 * 所以：txt 是只读数据源，运行时编译成一份 strtr 映射，缓存到 data/cache/。
 * 实测（2026-10-16，本机 PHP 8.2）：unserialize 5.1 万条映射 7.7ms、strtr 1800 字 3.8ms，
 * 每次发言多花不到 5ms —— 这就是「编译缓存 + 单次扫描」换来的，逐词替换做不到这个量级。
 */

/** 仓库与分支（仓主不换仓库就固定；分支从远端 default_branch 取，写在这里只是回落值） */
const OWSP_LEX_REPO = 'konsheng/Sensitive-lexicon';
const OWSP_LEX_BRANCH_FALLBACK = 'main';

/** 词库目录（相对站点根）。**刻意不给后台配**：路径可配等于把目录穿越做成功能 */
const OWSP_LEX_REL = 'data/lexicon';

/** 单个词库文件大小上限：管理员误放一个巨型文件不该把内存打爆（超限即跳过并上报） */
const OWSP_LEX_FILE_MAX = 8388608;
/** 编译后的词条总数上限：超出即截断（截断要如实上报，不能假装收录完整） */
const OWSP_LEX_WORDS_MAX = 600000;
/** 单个词条长度上限（字节）：词库里混着整句（实测最长 120 字节），太长的不值得匹配 */
const OWSP_LEX_WORD_MAX = 300;

/**
 * 词库**匹配语义**的版本号（v1.4.3）。
 *
 * ⚠️ 改了匹配算法就必须 +1：编译缓存（serialize 的映射，落在 data/cache）签名原来只算
 *    「替换串 + 各词库文件大小/mtime」——算法变了而签名没变，就会继续吃旧缓存里的旧映射，
 *    表现为「代码明明改了，打码行为一点没变」。签名与缓存校验都带上这个常量即可。
 *
 * 历史：1 = 全量 strtr 子串匹配；2 = ASCII 词条改为「按词匹配」（见 owSPlexApply）。
 */
const OWSP_LEX_LOGIC = 2;

/**
 * 按词匹配时**直接忽略**的 ASCII 词条（协议 / 主机前缀）。
 *
 * 它们不是违禁词，被当成违禁词只会产生一个后果：用户粘贴的正常链接被打码成
 * `***s://...`，链接从可点变成不可点（本站的链接预览卡片也因此取不到锚点）。
 * 域名该不该拦由「域名名单」管，不该在这里做。
 */
const OWSP_LEX_ASCII_IGNORE = ['http', 'https', 'www'];

/**
 * 「按词匹配」的取词正则：一整段 ASCII 词（可含 . _ - ' 这些词内分隔符）。
 * 左边界要求前面不是同类字符 —— 这一步是修「词条拆词」的关键：
 *   词条 `p`   不会再去命中 php / app 里的那个 p
 *   词条 `ma`  不会再去命中 normal 里的 ma
 *   词条 `http` 不会再去命中 https 里的 http（后面紧跟 s，属于同类字符，不算词尾）
 */
const OWSP_LEX_TOKEN_RE = '/(?<![A-Za-z0-9._\-\'])[A-Za-z0-9][A-Za-z0-9._\-\']*/u';

/**
 * 词库目录绝对路径。
 * $GLOBALS['sw_lex_dir'] 可覆盖 —— 与下面 $GLOBALS['sw_words'] 同一套路：
 * 离线自检要往目录里放夹具文件，不能让它去动站点真实的 data/lexicon。
 */
function owSPlexDir(): string
{
    $d = (string)($GLOBALS['sw_lex_dir'] ?? '');
    return $d !== '' ? rtrim($d, '/\\') : dirname(__DIR__, 2) . '/' . OWSP_LEX_REL;
}
/** 编译缓存文件（放核心的 cache 目录：后台「清缓存」把它删掉即可，下次请求自动重建） */
function owSPlexCacheFile(): string
{
    $p = (string)($GLOBALS['sw_lex_cache'] ?? '');
    return $p !== '' ? $p : dirname(__DIR__, 2) . '/data/cache/lexicon.bin';
}

/**
 * 词库文件清单：只认一级目录下的 *.txt（不递归 —— 递归等于允许管理员把整个磁盘拖进来）。
 * 返回 [文件名 => [size, mtime]]，按文件名排序，后台显示顺序才稳定。
 */
function owSPlexFiles(): array
{
    $out = [];
    $dir = owSPlexDir();
    if (!is_dir($dir)) return $out;
    foreach ((array)glob($dir . '/*.txt') as $p) {
        if (!is_file($p)) continue;
        $n = basename($p);
        if ($n === '' || strncmp($n, '.', 1) === 0) continue;
        $out[$n] = ['size' => (int)filesize($p), 'mtime' => (int)filemtime($p)];
    }
    ksort($out);
    return $out;
}

/** 被管理员停用的文件名（默认空 = 目录里所有 txt 都生效，下载完即用） */
function owSPlexDisabled(): array
{
    $out = [];
    foreach (preg_split('/[\r\n]+/', owSPSet('lex_disabled')) ?: [] as $l) {
        $l = trim($l);
        if ($l !== '' && !in_array($l, $out, true)) $out[] = $l;
    }
    return $out;
}

/** 打码串（与人工词库同一默认值；改它要连带重建缓存，见 sig） */
function owSPlexRep(): string
{
    $r = trim(owSPSet('lex_replacement', ''));
    return $r === '' ? '***' : mb_substr($r, 0, 8);
}

/**
 * 缓存签名 = 启用文件的「名字 + 大小 + mtime」+ 打码串。
 *
 * 为什么用 mtime/size 而不是内容哈希：签名要回答的正是「要不要重新读一遍」，
 * 拿内容算哈希等于把要省的成本先花掉。打码串必须进签名 —— 它是烘进映射里的值，
 * 不进签名的话管理员改了打码串、缓存的还是旧串。
 */
function owSPlexSig(): string
{
    // 第一位就是匹配语义版本：算法一变，全部旧缓存立即作废（见 OWSP_LEX_LOGIC 注释）
    $parts = ['L' . OWSP_LEX_LOGIC, owSPlexRep()];
    $dis = owSPlexDisabled();
    foreach (owSPlexFiles() as $n => $f) {
        if (in_array($n, $dis, true)) continue;
        $parts[] = $n . ':' . $f['size'] . ':' . $f['mtime'];
    }
    return md5(implode('|', $parts));
}

/**
 * 编译一份映射（word => 打码串）。词库是**外部内容**，逐行校验：
 * 非 UTF-8、超长、含控制字符（\t\n 等）的行一律跳过 —— 坏一行不该让整份词库失效，
 * 但也不能把坏行当真词收进去（含 \t 的「词」永远匹配不到，白占内存）。
 */
function owSPlexBuild(string $sig): array
{
    $rep = owSPlexRep();
    $dis = owSPlexDisabled();
    // 两张表，按「这个词条该怎么匹配」分：
    //   sub   子串匹配（strtr）：中文没有词边界，只能子串；ASCII 短语（含空格）同理
    //   latin 按词匹配（正则 + 哈希查表）：纯 ASCII 单词，必须左右有词边界
    // 分表的理由见 OWSP_LEX_LOGIC 与 owSPlexApply 的注释：词库里混着 `p` / `ma` / `http`
    // 这类退化条目，子串匹配会把 php / normal / https 一起打碎。
    $sub = []; $latin = []; $counts = [];
    $words = 0; $files = 0; $bytes = 0; $skipped = 0; $ignored = 0; $truncated = false;
    foreach (owSPlexFiles() as $n => $f) {
        // 停用的文件也读一遍并统计词数：后台要让管理员看见「这份词库有多少词」再决定要不要启用，
        // 只统计启用中的文件等于把最关键的那个数字藏起来。编译成本只在签名变化时付一次。
        $enabled = !in_array($n, $dis, true);
        if ($f['size'] > OWSP_LEX_FILE_MAX) { $truncated = true; $counts[$n] = -1; continue; }
        $body = @file_get_contents(owSPlexDir() . '/' . $n);
        if ($body === false) { $counts[$n] = -1; continue; }
        $files++; $bytes += strlen($body);
        $n_words = 0;
        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $w = trim($line);
            if ($w === '') continue;
            if (strlen($w) > OWSP_LEX_WORD_MAX || !mb_check_encoding($w, 'UTF-8')
                || preg_match('/[\x00-\x1F\x7F]/', $w)) { $skipped++; continue; }
            $n_words++;
            if (!$enabled) continue;

            // ---- 入库前先过「退化条目」闸门（v1.4.3，只对有问题的 ASCII 条目生效）----
            if (preg_match('/^[\x20-\x7E]+$/', $w)) {
                // ① 单字符（词库里真的有 p / b / & / 1）：任何「词」都不该是一个字符，
                //    子串匹配下它会把每个含该字符的词都打碎（php → ***h***）。
                if (mb_strlen($w) < 2) { $ignored++; continue; }
                // ② 协议 / 主机前缀：不是违禁词，打码只会废掉正常链接
                if (in_array(strtolower($w), OWSP_LEX_ASCII_IGNORE, true)) { $ignored++; continue; }
                // ③ 不像 URL 片段的标点条目：留子串匹配（如 `xi+jin+ping` 这种写法只会命中字面量）。
                //    像 URL 片段的（含 / 或 @，或以 . 开头，如 `.com` / `http://a/b`）一律丢弃 ——
                //    它们正是「example.com 变成 example***」的源头；域名拦截请用「域名名单」。
                if (preg_match('/[\/@]/', $w) || $w[0] === '.') { $ignored++; continue; }
                // ④ 其余纯 ASCII：能整段当一个「词」的走按词匹配，含空格的短语留子串匹配
                if (strpos($w, ' ') === false && preg_match('/^[A-Za-z0-9][A-Za-z0-9._\-\']*$/', $w)) {
                    $k = strtolower($w);          // 统一小写查表 → 大小写不敏感（词库里 Fuck/fuck/FUCK 三条现在只需一条）
                    if (isset($latin[$k])) continue;
                    if ($words >= OWSP_LEX_WORDS_MAX) { $truncated = true; break 2; }
                    $latin[$k] = $rep; $words++;
                    continue;
                }
            }
            if (isset($sub[$w])) continue;
            if ($words >= OWSP_LEX_WORDS_MAX) { $truncated = true; break 2; }
            $sub[$w] = $rep;
            $words++;
        }
        $counts[$n] = $n_words;
    }
    $c = ['sig' => $sig, 'logic' => OWSP_LEX_LOGIC, 'sub' => $sub, 'latin' => $latin,
        'latin_n' => count($latin), 'sub_n' => count($sub),
        'words' => $words, 'files' => $files, 'counts' => $counts, 'bytes' => $bytes,
        'skipped' => $skipped, 'ignored' => $ignored, 'truncated' => $truncated, 'built_at' => time()];
    // 原子替换：并发请求可能同时重建，写一半的缓存被读到就是 unserialize 失败 → 退回逐行重读
    $tmp = owSPlexCacheFile() . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, serialize($c)) !== false) @rename($tmp, owSPlexCacheFile());
    return $c;
}

/** 编译结果（进程内静态 + 文件缓存；签名不符才重建） */
function owSPlexCompiled(bool $force = false): array
{
    static $c = null;
    $sig = owSPlexSig();
    if (!$force && $c !== null && ($c['sig'] ?? '') === $sig) return $c;
    if (!$force) {
        $fp = owSPlexCacheFile();
        if (is_file($fp)) {
            $raw = @file_get_contents($fp);
            // allowed_classes=false：缓存文件万一被替换成恶意内容，也不许 unserialize 造对象
            $d = $raw === false ? false : @unserialize($raw, ['allowed_classes' => false]);
            if (is_array($d) && ($d['sig'] ?? '') === $sig && ($d['logic'] ?? 0) === OWSP_LEX_LOGIC
                && isset($d['sub']) && is_array($d['sub']) && isset($d['latin']) && is_array($d['latin'])) {
                $c = $d;
                return $c;
            }
        }
    }
    $c = owSPlexBuild($sig);
    return $c;
}

/**
 * 打码。返回替换后的文本，$hits 出参给命中数（替换串出现次数）。
 *
 * 两种匹配方式，因为两类「词」的边界规则本就不同（v1.4.3 起，见 OWSP_LEX_LOGIC）：
 *   ① 子串匹配（strtr）：**中文条目**。中文没有词边界，「法网恢恢」必须能被「法网」命中；
 *      strtr（数组形态）在同一位置优先最长匹配，正是违禁词想要的。
 *   ② 按词匹配（正则取词 + 哈希查表）：**纯 ASCII 条目**。不加边界就会把词拆碎 ——
 *      词条 `p` 命中 php、`ma` 命中 normal、`http` 命中 https（用户粘贴的链接就是这么被废掉的，
 *      而链接一废，本站的链接预览卡片也拿不到锚点）。取词正则见 OWSP_LEX_TOKEN_RE。
 *      命中判定另做两次兜底：
 *        · 词尾带数字（falun123 / falun666）→ 剥掉数字再查一次，堵「拼数字绕过」；
 *        · 词内含分隔符（sex.com / porn-site）→ 逐段查，只替换命中的段，分隔符与其余段原样保留。
 */
function owSPlexApply(string $text, ?int &$hits = null): string
{
    $hits = 0;
    $c = owSPlexCompiled();
    $sub = $c['sub'] ?? [];
    $latin = $c['latin'] ?? [];
    if ((!$sub && !$latin) || $text === '') return $text;

    $orig = $text;
    $rep = owSPlexRep();

    // ① 子串匹配：中文词条 + ASCII 短语
    if ($sub) $text = strtr($text, $sub);

    // ② 按词匹配：纯 ASCII 单词
    if ($latin) {
        $text = preg_replace_callback(OWSP_LEX_TOKEN_RE, function ($m) use ($latin, $rep) {
            $tok = $m[0];
            $low = strtolower($tok);
            if (isset($latin[$low])) return $rep;
            // 词尾数字：falun123
            $bare = rtrim($low, '0123456789');
            if ($bare !== '' && $bare !== $low && isset($latin[$bare])) return $rep;
            // 词内含分隔符：example.com / porn-site —— 逐段查，只换命中的段
            if (preg_match('/[._\-\']/', $tok)) {
                $pieces = preg_split('/([._\-\']+)/', $tok, -1, PREG_SPLIT_DELIM_CAPTURE);
                if (is_array($pieces)) {
                    $out = ''; $hit = false;
                    foreach ($pieces as $i => $piece) {
                        // 奇数下标是分隔符本身（捕获组），偶数下标是词段
                        if ($i % 2 === 1 || $piece === '') { $out .= $piece; continue; }
                        if (isset($latin[strtolower($piece)])) { $out .= $rep; $hit = true; }
                        else $out .= $piece;
                    }
                    if ($hit) return $out;
                }
            }
            return $tok;
        }, $text);
        // ⚠️ 正则出错（PCRE 回溯上限等）时返回 null —— 绝不能把整段内容变成空串，
        //    宁可退回「没打码」也要保住用户的文字。
        if ($text === null) return $orig;
    }

    if ($text === $orig) return $orig;
    $hits = max(1, (int)substr_count($text, $rep));
    return $text;
}

/** 词库概况（后台显示；走编译缓存，签名不符时才真读文件） */
function owSPlexStat(): array
{
    $c = owSPlexCompiled();
    return ['words' => (int)$c['words'], 'files' => (int)$c['files'], 'bytes' => (int)$c['bytes'],
        'skipped' => (int)($c['skipped'] ?? 0), 'truncated' => !empty($c['truncated']),
        'built_at' => (int)$c['built_at'], 'dir' => OWSP_LEX_REL, 'path' => owSPlexDir(),
        // v1.4.3：按词匹配 / 子串匹配 / 被忽略的退化条目数，后台要看得见
        'latin' => (int)($c['latin_n'] ?? 0), 'sub' => (int)($c['sub_n'] ?? 0),
        'ignored' => (int)($c['ignored'] ?? 0), 'logic' => (int)($c['logic'] ?? 0),
        'total' => count(owSPlexFiles()), 'rep' => owSPlexRep()];
}

/* ---------- 接口盒子（线路类型二：普通集群负载 - 域名接口） ---------- */

/** 线路类型二：HTTPS 域名接口，自动分发到普通集群负载均衡；同一份文档里的线路一是 http IP 接口 */
const OWSP_LEXAPI_URL = 'https://cn.apihz.cn/api/zici/mgc.php';
const OWSP_LEXAPI_TIMEOUT_MIN = 1;
const OWSP_LEXAPI_TIMEOUT_MAX = 10;
const OWSP_LEXAPI_TIMEOUT_DEF = 3;
/** 免费「注册用户」额度是 10 次/分钟，所以分钟级闸门默认就按它对齐（可调） */
const OWSP_LEXAPI_PERMIN_DEF = 10;
const OWSP_LEXAPI_DAILY_DEF = 2000;

function owSPlexApiTimeout(): int
{
    return max(OWSP_LEXAPI_TIMEOUT_MIN, min(OWSP_LEXAPI_TIMEOUT_MAX, owSPInt('lexapi_timeout', OWSP_LEXAPI_TIMEOUT_DEF)));
}

/**
 * 额度闸门：分钟级（防突发，对齐对方免费额度）+ 日级（防失控循环）两道。
 * 与 AI 防线同一套口径 —— 超额不是错误，是「这次不判了，照常放行」。
 */
function owSPlexApiQuotaOk(): bool
{
    $per = max(0, min(600, owSPInt('lexapi_permin', OWSP_LEXAPI_PERMIN_DEF)));
    if ($per > 0 && !Sec::rateLimit('spam_lex_min', 'all', 60, $per)) return false;
    $day = owSPInt('lexapi_daily', OWSP_LEXAPI_DAILY_DEF);
    if ($day > 0 && !Sec::rateLimit('spam_lex_day', 'all', 86400, $day)) return false;
    return true;
}

/** 把接口返回的命中词归一成词列表 */
function owSPlexApiWords($v): array
{
    // 文档没写死 mgcwords 的分隔格式（逗号 / 顿号 / 空格 / 数组都可能），全部认下来；
    // 真实格式由后台「测试」按钮实测确认，不要照猜写死一种分隔符
    $items = is_array($v) ? $v : (preg_split('/[,，、|;；\s]+/u', (string)$v) ?: []);
    $out = [];
    foreach ($items as $w) {
        $w = trim((string)$w);
        if ($w === '' || strlen($w) > OWSP_LEX_WORD_MAX || !mb_check_encoding($w, 'UTF-8')) continue;
        if (preg_match('/[\x00-\x1F\x7F]/', $w)) continue;
        $out[$w] = true;
        if (count($out) >= 200) break;
    }
    return array_keys($out);
}

/**
 * 问一次接口。返回 ['ok','hit','words','raw','ms','code']。
 * 任何一步不对都 ok=false —— 调用方一律放行，绝不因为审核接口挂着就拦住用户发言
 * （与 AI 防线同一条底线：宁可漏一条，不能因为第三方抖一下全站发不出消息）。
 *
 * ⚠️ 只取 mgcwords（命中词），**不取 replacewords**（替换后的整段文本）：
 *    那个字段是第三方返回的全文，直接当用户消息内容入库等于让外部服务决定我们库里存什么；
 *    拿命中词在本地替换，既与本地词库共用同一个打码串，也不必信任对方的转义。
 */
function owSPlexApiAsk(string $text, int $budget = 0): array
{
    $fail = function (string $raw, int $ms = 0, int $code = 0): array {
        return ['ok' => false, 'hit' => false, 'words' => [], 'raw' => $raw, 'ms' => $ms, 'code' => $code];
    };
    $id  = trim(owSPSet('lexapi_id'));
    $key = trim(owSPSet('lexapi_key'));
    if ($id === '' || $key === '') return $fail('未配置接口 ID / KEY');
    if ($text === '') return $fail('待检文本为空');

    // RFC3986 编码：接口文档明确说「参数不能带 + 号，会被自动替换为空格」。
    // 默认的 RFC1738 会把空格编成 '+'，于是正文里的 '+' 到对面就成了空格；
    // RFC3986 把空格编成 %20、把 '+' 编成 %2B，两边都不会被误解。
    $body = http_build_query([
        'id' => $id, 'key' => $key,
        'words' => mb_substr($text, 0, 10000),   // 接口上限 10000 字
        'mgctype' => '1',                        // 要它回命中词（replacetype 用不上，见上）
    ], '', '&', PHP_QUERY_RFC3986);

    $timeout = $budget > 0 ? max(1, min(OWSP_LEXAPI_TIMEOUT_MAX, $budget)) : owSPlexApiTimeout();
    $hdr = ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'];
    $resp = null; $code = 0; $ms = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init(OWSP_LEXAPI_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $hdr,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
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
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($resp === false) {
            return $fail(owSPlexApiErrText($errno, $cerr, $ms, $timeout), $ms);
        }
    } else {
        if (!ini_get('allow_url_fopen')) return $fail('无 curl 且 allow_url_fopen 关闭');
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $hdr) . "\r\n",
            'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true,
        ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $t0 = microtime(true);
        $resp = @file_get_contents(OWSP_LEXAPI_URL, false, $ctx);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        if ($resp === false) return $fail('请求失败（无 curl 扩展的回落通道也不通，已等 '
            . number_format($ms / 1000, 1) . ' 秒）', $ms);
    }

    $j = json_decode((string)$resp, true);
    if (!is_array($j)) return $fail('响应不是 JSON：' . mb_substr((string)$resp, 0, 200), $ms, $code);
    if ((int)($j['code'] ?? 0) !== 200) {
        return $fail('接口报错：' . mb_substr((string)($j['msg'] ?? '未知原因'), 0, 200), $ms, $code);
    }
    // jcstatus：0 = 未检出，1 = 含敏感词；缺失时按命中词判定，避免对面改字段就整条失效
    $words = owSPlexApiWords($j['mgcwords'] ?? '');
    $hit = (isset($j['jcstatus']) ? (int)$j['jcstatus'] === 1 : false) || $words !== [];
    return ['ok' => true, 'hit' => $hit, 'words' => $words, 'raw' => mb_substr((string)$resp, 0, 300),
        'ms' => $ms, 'code' => $code];
}

/** curl 失败翻译成人话（超时按「没连上 / TLS 卡住 / 对面不回话」分，处置完全不同） */
function owSPlexApiErrText(int $errno, string $cerr, int $ms, int $budget): string
{
    $sec = number_format($ms / 1000, 1);
    if ($errno === CURLE_OPERATION_TIMEDOUT) {
        return '接口超时：等了 ' . $sec . ' 秒（上限 ' . $budget . ' 秒）还没返回。'
            . '把「超时(秒)」调大再试；若一直超时，先确认这台服务器能访问外网。';
    }
    if ($errno === CURLE_COULDNT_RESOLVE_HOST) return '域名解析失败：' . mb_substr($cerr, 0, 60);
    if ($errno === CURLE_COULDNT_CONNECT) return '连不上接口：' . mb_substr($cerr, 0, 80);
    return '请求失败：' . ($cerr ?: '网络不通') . '（errno ' . $errno . '，已等 ' . $sec . ' 秒）';
}

/* ---------- 词库更新（检测 / 下载） ---------- */

/** 通用 HTTPS GET（curl 优先、回落 allow_url_fopen），失败返回 null 并写 $err */
function owSPlexHttpGet(string $url, ?string &$err, array $hdr = [], int $timeout = 20): ?string
{
    $err = null;
    $hdr = array_merge(['User-Agent: Owlsgo-Spam-Lexicon', 'Accept: application/vnd.github+json'], $hdr);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,     // api.github.com/tarball → codeload（跨主机跳转，正好把 Authorization 摘掉）
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $hdr,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if (class_exists('Upgrade')) {
            $ca = Upgrade::caBundle();
            if ($ca !== null) curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($body === false) $err = '网络请求失败：' . curl_error($ch);
        elseif ($code !== 200) $err = 'HTTP ' . $code . '（仓库地址 / 限流都可能，未认证的 GitHub API 限额是 60 次/小时）';
        curl_close($ch);
        return ($body === false || $err !== null) ? null : (string)$body;
    }
    if (!ini_get('allow_url_fopen')) { $err = '无 curl 扩展且 allow_url_fopen 关闭'; return null; }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'header' => implode("\r\n", $hdr) . "\r\n"],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) { $err = '网络请求失败：' . (error_get_last()['message'] ?? '未知'); return null; }
    return $body;
}

/**
 * 远端仓库信息。**用仓库元信息（pushed_at）当版本标记**，一次调用就够：
 * 拿 tree/blob 去比对要 2 次以上调用，而未认证限额只有 60 次/小时。
 * 代价是「只改了 README」也会算作有更新 —— 对「该去点一下下载」这个提示来说，
 * 宁可是多报一次，也不要漏报真有词库变化。
 */
function owSPlexRemote(?string &$err = null): ?array
{
    // $GLOBALS['sw_lex_remote'] 可替换（与 $GLOBALS['sw_words'] 同一套路）：
    // 这条路径要真访问 GitHub，而「检测到更新 → 写状态 → 推通知 → 不重复推」这段逻辑
    // 必须能离线逐条打 —— 自检机不该依赖外网，也不该消耗站方的 GitHub 限额。
    $stub = $GLOBALS['sw_lex_remote'] ?? null;
    if (is_array($stub)) return $stub;
    $j = owSPlexHttpGet('https://api.github.com/repos/' . OWSP_LEX_REPO, $err);
    if ($j === null) return null;
    $d = json_decode($j, true);
    if (!is_array($d)) { $err = 'GitHub 响应不是 JSON（可能被网关拦截）'; return null; }
    $at = (string)($d['pushed_at'] ?? '');
    if ($at === '') { $err = '响应里没有 pushed_at（仓库不存在或已改名）'; return null; }
    return [
        'at' => $at,
        'branch' => (string)($d['default_branch'] ?? OWSP_LEX_BRANCH_FALLBACK),
        'html' => (string)($d['html_url'] ?? ''),
    ];
}

/**
 * 是否有「已发现但还没处理」的词库更新。返回 [有更新, 远端版本标记]。
 *
 * spam_lex_update_to = 已就哪个远端版本提醒过；download 成功后清掉。
 * 它同时承担两件事：后台状态行据此显示「远端有更新」，检测任务据此保证**同一版本只推一次**。
 */
function owSPlexUpdatePending(): array
{
    $to = (string)DB::setting('spam_lex_update_to', '');
    if ($to === '') return [false, ''];
    return [$to !== (string)DB::setting('spam_lex_remote_at', ''), $to];
}

/**
 * 给所有超级管理员推一条「词库有更新」系统通知，返回推送条数。
 *
 * 单独抽出来是为了能**离线测**：这条路径要在「类型已注册」与「类型未注册」两种核心下
 * 都能跑（未注册时核心静默丢弃，这里必须如实返回 0 而不是假装发了）。
 * 只发给超管 —— 词库只有管理员能更新，普通用户收到只会当成系统出了什么事。
 */
function owSPlexNotifyAdmins(string $ver): int
{
    if (!owSPOn('lex_notify_on')) return 0;
    // 先问一句类型认不认：核心对未注册的 kind 是**静默丢弃**，不问的话这里会照样数出
    // 「已发 N 位管理员」，日志就成了一句谎话（旧核心上这条通知其实一条都没出去）。
    if (!in_array('spam_lexicon_update', Notice::kinds(), true)) return 0;
    $n = 0;
    foreach (DB::all("SELECT id FROM users WHERE role='admin'") as $adm) {
        Notice::push((int)$adm['id'], 'spam_lexicon_update', ['v' => $ver]);
        $n++;
    }
    return $n;
}

/**
 * 检查更新。只查仓库信息（1 次 API 调用），不下载。
 *
 * 发现更新 → 记下「待更新版本」并给所有超级管理员 push 一条系统通知。
 * 通知用 `spam_lexicon_update` 类型，**核心 v1.3.171 起内置**（同名以核心为准）；
 * 插件要发自己另外的类型时才需要经 `notice.kinds` 钩子注册模板（PLUGIN.md「通知类型注册」）。
 *
 * 「只推一次」由 spam_lex_update_to 兜住：它就是「已经就这个远端版本提醒过」的标记，
 * 下载成功后清掉。每小时的检测任务因此不会把同一条更新反复推给管理员。
 */
function owSPlexCheck(): array
{
    $err = null;
    $r = owSPlexRemote($err);
    DB::setSetting('spam_lex_last_check', (string)time());
    if ($r === null) {
        DB::setSetting('spam_lex_last_msg', '检测失败：' . (string)$err);
        return ['ok' => false, 'changed' => false, 'msg' => '检测失败：' . (string)$err];
    }
    $known = (string)DB::setting('spam_lex_remote_at', '');
    $changed = ($known !== '' && $known !== $r['at']);
    $sent = 0;
    if ($changed && (string)DB::setting('spam_lex_update_to', '') !== $r['at']) {
        DB::setSetting('spam_lex_update_to', $r['at']);
        DB::setSetting('spam_lex_update_at', (string)time());
        $v = substr($r['at'], 0, 10);   // 2026-08-17：一眼能读，比 sha 有用
        $sent = owSPlexNotifyAdmins($v);
        Sec::log('spam_lexicon_update', '系统', ['v' => $v, 'notified' => $sent]);
    }
    $v = substr($r['at'], 0, 10);
    $msg = $known === '' ? '尚未下载过词库，远端最新 ' . $v
        : ($changed
            ? '远端有更新（' . $v . '），' . ($sent > 0 ? '已发系统通知给 ' . $sent . ' 位管理员'
                : (owSPOn('lex_notify_on') ? '通知未发出：核心未注册该通知类型' : '未开启更新通知'))
            : '词库已是最新（' . substr($known, 0, 10) . '）');
    DB::setSetting('spam_lex_last_msg', $msg);
    return ['ok' => true, 'changed' => $changed, 'msg' => $msg, 'remote_at' => $r['at'], 'notified' => $sent];
}

/**
 * 手写 ustar 解析：只取普通文件。零依赖（不引 Phar / Composer），
 * 输入是 gzdecode 之后的裸 tar 字节。返回 [路径 => 内容]。
 */
function owSPlexUntar(string $tar): array
{
    $out = []; $pos = 0; $len = strlen($tar);
    while ($pos + 512 <= $len) {
        $hdr = substr($tar, $pos, 512);
        if (trim($hdr, "\0") === '') break;                       // 结束块（全零 512 字节）
        $name = rtrim(substr($hdr, 0, 100), "\0");
        $size = (int)octdec(trim(substr($hdr, 124, 12), " \0"));
        $type = substr($hdr, 156, 1);
        $prefix = rtrim(substr($hdr, 345, 155), "\0");            // ustar 长名前缀
        if ($prefix !== '') $name = $prefix . '/' . $name;
        $pos += 512;
        if ($type === '0' || $type === '' || $type === "\0") {
            if ($size > 0) $out[$name] = substr($tar, $pos, $size);
        }
        // 目录 / 符号链接 / PAX 头一律跳过：词库只需要普通文件，且不跟随链接（防穿越）
        $pos += (int)(ceil($size / 512) * 512);
    }
    return $out;
}

/**
 * 压缩包里的文件名是否可安全落盘。
 *
 * 名字来自外部压缩包（第三方仓库，理论上可被投毒），所以单独成函数而不是内联：
 * 这是**路径穿越**的唯一入口，要能被自检逐条打（`../`、子目录、绝对路径、控制字符）。
 * 只认「一段普通文件名」—— 不含路径分隔符、不含 `..`、不含控制字符、长度有限。
 */
function owSPlexSafeName(string $name): bool
{
    if ($name === '' || strlen($name) > 120) return false;
    if (strpos($name, '..') !== false) return false;
    if (preg_match('#[/\\\\]#', $name)) return false;
    if (preg_match('/[\x00-\x1F\x7F]/', $name)) return false;
    return (bool)preg_match('/^[^\/\\\\]{1,80}$/u', $name);
}

/**
 * 在线下载词库。一次 tarball 请求拿全量（实测 544KB / 2.6 秒 / 17 个词库文件），
 * 比逐文件走 contents API 省 16 次调用 —— 未认证限额 60 次/小时，逐文件下载
 * 两次就把额度用掉一半，还会因为中途限流留下一半新一半旧。
 *
 * 只覆盖同名文件，**不删除**目录里多出来的东西：管理员自己放进去的词库是本地资产，
 * 远端删了某个文件不代表本地那份该消失。
 */
function owSPlexDownload(?string &$err = null): array
{
    $err = null;
    $repo = owSPlexRemote($e1);
    if ($repo === null) { $err = $e1; return []; }
    $tgz = owSPlexHttpGet('https://api.github.com/repos/' . OWSP_LEX_REPO . '/tarball/' . $repo['branch'], $e2, [], 120);
    if ($tgz === null) { $err = $e2; return []; }
    if (substr($tgz, 0, 2) !== "\x1f\x8b") { $err = '下载到的不是 gzip 包（多半被网关改写成了错误页）'; return []; }
    $tar = @gzdecode($tgz);
    if ($tar === false) { $err = 'gzip 解压失败（包不完整）'; return []; }
    $entries = owSPlexUntar($tar);
    if (!$entries) { $err = '压缩包里没有文件'; return []; }

    if (!is_dir(owSPlexDir()) && !@mkdir(owSPlexDir(), 0755, true)) { $err = '无法创建词库目录 ' . OWSP_LEX_REL; return []; }
    $saved = []; $bytes = 0;
    foreach ($entries as $path => $body) {
        if (!preg_match('#/Vocabulary/([^/]+\.txt)$#u', $path, $m)) continue;
        $name = $m[1];
        if (!owSPlexSafeName($name)) continue;
        if (strlen($body) > OWSP_LEX_FILE_MAX || $body === '') continue;
        $dst = owSPlexDir() . '/' . $name;
        $tmp = $dst . '.tmp';
        if (@file_put_contents($tmp, $body) === false) continue;
        if (!@rename($tmp, $dst)) { @unlink($tmp); continue; }   // 原子替换：请求不会读到半份词库
        $saved[] = $name; $bytes += strlen($body);
    }
    if (!$saved) { $err = '压缩包里没找到 Vocabulary/*.txt'; return []; }

    // 缓存签名看 mtime/size，文件刚换过必然失效；显式删掉只是为了不让旧文件留在盘上
    if (class_exists('Cache')) Cache::clear('lexicon'); else @unlink(owSPlexCacheFile());
    DB::setSetting('spam_lex_remote_at', $repo['at']);
    DB::setSetting('spam_lex_installed_at', (string)time());
    // 「有待更新」这个状态到此消解：后台状态行不再显示「远端有更新」，同一版本也不会被再次提醒
    DB::setSetting('spam_lex_update_to', '');
    DB::setSetting('spam_lex_update_at', '');
    DB::setSetting('spam_lex_last_msg', '已下载 ' . count($saved) . ' 个词库文件');
    Sec::log('spam_lexicon_download', '系统', ['files' => count($saved), 'bytes' => $bytes, 'remote_at' => $repo['at']]);
    return ['files' => $saved, 'bytes' => $bytes, 'remote_at' => $repo['at'], 'branch' => $repo['branch']];
}

/** 状态行文案（后台与计划任务共用，口径只有一份） */
function owSPlexStatusText(): string
{
    $s = owSPlexStat();
    $rec = (int)DB::setting('spam_lex_installed_at', '0');
    $t = '本地词库：' . $s['files'] . ' 个文件、' . number_format($s['words']) . ' 条词'
        . '（目录共 ' . $s['total'] . ' 个文件）';
    // 按词匹配 / 子串匹配的分工，以及被忽略的退化条目 —— 管理员要能看见「为什么某条不生效」
    $t .= '　其中 ' . number_format($s['latin']) . ' 条按词匹配、' . number_format($s['sub']) . ' 条子串匹配';
    if ($s['ignored'] > 0) {
        $t .= '；已忽略 ' . number_format($s['ignored']) . ' 条会误伤链接的条目（单字符 / http、www 等前缀 / 网址片段）';
    }
    if ($rec > 0) $t .= '　最后下载 ' . date('Y-m-d H:i', $rec);
    $chk = (int)DB::setting('spam_lex_last_check', '0');
    if ($chk > 0) $t .= '　最后检测 ' . date('Y-m-d H:i', $chk);
    [$pending, $ver] = owSPlexUpdatePending();
    if ($pending) $t .= '　远端有更新（' . substr($ver, 0, 10) . '），点「在线下载词库」更新';
    $last = (string)DB::setting('spam_lex_last_msg', '');
    if ($last !== '') $t .= '　' . $last;
    return $t;
}

/**
 * 计划任务：词库更新检测。
 *
 * 注册间隔固定 1 小时，**间隔是否到期在处理器里按后台配置判断** ——
 * 计划任务的 interval 是注册时写进 cron_tasks 表的，后台改配置不会回头改表，
 * 做成「注册间隔 = 配置值」等于改了不生效（这个坑 content-report 的 ai_review 踩过）。
 */
function owSPlexCronCheck(): string
{
    if (!owSPOn('lex_check_on')) return '未启用自动检测';
    $hours = max(1, min(720, owSPInt('lex_check_hours', 24)));
    $last = (int)DB::setting('spam_lex_last_check', '0');
    if ($last > 0 && time() - $last < $hours * 3600) {
        return '未到期（每 ' . $hours . ' 小时检测一次，上次 ' . date('m-d H:i', $last) . '）';
    }
    $r = owSPlexCheck();
    return $r['msg'];
}
Plugin::cron('lexicon_check', 3600, 'owSPlexCronCheck', '检测敏感词库是否有更新，有更新则通知超级管理员');

/* ============================ 钩子 ============================ */

/** 词库（static 缓存，进程内只查一次表） */
$GLOBALS['sw_words'] = function (): array {
    static $words = null;
    if ($words === null) $words = DB::all('SELECT word, replacement FROM sensitive_words WHERE enabled=1');
    return $words;
};

/**
 * 1) 敏感词 / 违禁词：核心 text.filter 钩子，对所有输入文字生效。
 *
 * 三段按「代价从低到高」排：人工词库（内存里几十条）→ 本地词库（编译映射，~5ms）
 * → 接口盒子（一次 HTTP）。后两段各有开关，关掉即整段跳过。
 */
Plugin::on('text.filter', function (&$text, $scene, $actor) {
    if (!is_string($text) || $text === '') return;

    // ① 人工词库（sensitive_words 表）：词条少、每条有自己的替换串，保持逐条替换
    foreach (($GLOBALS['sw_words'])() as $w) {
        if ($w['word'] !== '') {
            $text = mb_ereg_replace(preg_quote($w['word'], '/'), $w['replacement'], $text);
        }
    }

    if (!owSPOn('lex_on')) return;

    // ② 本地词库：交给接口的那份用**原始**文本（本地已打码的部分对接口没意义，
    //    而且 '*' 会干扰它自己的判定），所以先把原文留一份
    $orig = $text;
    $hits = 0;
    $text = owSPlexApply($text, $hits);
    if ($hits > 0) owSPlexLog('spam_lex_local', $actor, ['n' => $hits, 'scene' => $scene]);

    // ③ 接口盒子：只对**消息正文**调用。一次 HTTP 是真延迟（实测 0.5 秒起），
    //    改昵称 / 建群 / 群公告这些低频入口不值得为它等；而免费额度只有 10 次/分钟，
    //    必须花在高频入口上。要做全场景就得先换到付费额度，那是另一个决定。
    if (!owSPOn('lexapi_on') || $scene !== 'message') return;
    if (owSPOn('lexapi_skip_local') && $hits > 0) return;   // 本地已命中：省一次调用
    if (!owSPlexApiQuotaOk()) return;                        // 额度用尽：放行，不拦正常用户
    $r = owSPlexApiAsk($orig);
    if (!$r['ok']) {
        Sec::log('spam_lex_fail', (string)($actor['nickname'] ?? '?'), ['err' => $r['raw']]);
        return;
    }
    if (!$r['hit']) return;
    if (owSPSet('lexapi_action', 'mask') === 'mask' && $r['words']) {
        $pair = [];
        foreach ($r['words'] as $w) $pair[$w] = owSPlexRep();
        $text = strtr($text, $pair);
    }
    owSPlexLog('spam_lex_api', $actor, [
        'action' => owSPSet('lexapi_action', 'mask'),
        'words' => mb_substr(implode('、', $r['words']), 0, 200), 'ms' => $r['ms'],
    ]);
});

/**
 * 违禁词命中的安全日志。
 *
 * 为什么要按人限速：本地词库 5 万条里混着不少日常词（实测「法网」这类就命中），
 * 全量记日志会让 security_logs 被常态打码事件淹掉，真正的安全事件反而看不见。
 * 限速只影响留痕密度，不影响打码本身。
 */
function owSPlexLog(string $action, array $actor, array $data): void
{
    $ident = (string)($actor['kind'] ?? 'none') . (int)($actor['id'] ?? 0);
    if (!Sec::rateLimit('spam_lex_log', $ident, 3600, 20)) return;
    Sec::log($action, (string)($actor['nickname'] ?? '?'), $data + ['ip' => Sec::ip()]);
}

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

/** 3) 域名名单下发：只在聊天页（page.footer 只在 renderChat 触发），且只带名单不带密钥 */Plugin::on('page.footer', function (): void {
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
        ['lex', '违禁词库', '接口 + 本地词库'],
        ['flood', '刷屏', '窗口条数与最小间隔'],
        ['domain', '链接域名', '点击弹窗不跳转'],
        ['appeal', '访问申诉', '用户申请恢复访问'],
        ['ai', 'AI 防线', '模型判定，默认仅记录'],
    ];
    $h = '<h2>垃圾信息控制</h2><p class="ow-admin-desc">敏感词、违禁词库、刷屏、链接域名、访问申诉、AI 审核集中在一页管理。'
        . '每个标签独立保存，改动即时生效。</p>';
    // 分组标签用核心通用轮子 owTabs（v1.3.113）：结构与配色都在 index.css / index.js，
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

    /* ---------- ⑥ 违禁词库（v1.4.0） ---------- */
    $lex = owSPlexStat();
    $h .= '<div class="ow-pagetab-panel ow-spam-pane" data-pane="lex" style="display:none">'
        . owSPswitch('lex_on', '启用本地词库打码',
            '对消息、昵称、群名称、群简介、群公告生效；命中即替换为打码串，不拦发送（与上面的人工敏感词同一语义）。')
        . owSPswitch('lexapi_on', '启用接口检测',
            '接口盒子「敏感词 / 违禁词检测」线路二（域名接口）。只对消息正文调用：一次请求约 0.5 秒，'
            . '改昵称 / 建群 / 群公告这些低频入口不值得为它等。接口挂掉、超时或额度用尽一律放行。')
        . owSPswitch('lexapi_skip_local', '本地已命中时跳过接口',
            '省调用额度。免费「注册用户」额度是 10 次/分钟，本地词库能认出的文本不必再花一次调用。')
        . '<div class="ow-form-row">'
        . '<div class="ow-form-item" style="max-width:160px"><label>打码串</label>'
        . '<input class="ow-input" id="owSpam_lex_replacement" maxlength="8" value="'
        . htmlspecialchars(owSPlexRep(), ENT_QUOTES) . '"></div>'
        . '<div class="ow-form-item"><label>命中后</label><select class="ow-input" id="owSpam_lexapi_action">'
        . '<option value="mask"' . (owSPSet('lexapi_action', 'mask') === 'mask' ? ' selected' : '') . '>打码替换</option>'
        . '<option value="record"' . (owSPSet('lexapi_action', 'mask') === 'record' ? ' selected' : '') . '>仅记录（不动消息，只写安全日志）</option>'
        . '</select><p style="font-size:12px">默认打码替换，与本地词库共用同一个打码串。'
        . '接口返回的是「命中的词」，替换在本地做 —— 不采用它返回的整段文本，免得外部服务决定我们库里存什么。</p></div>'
        . '</div>'
        . '<div class="ow-form-row">'
        . '<div class="ow-form-item"><label>接口 ID</label><input class="ow-input" id="owSpam_lexapi_id" maxlength="40" value="'
        . htmlspecialchars(owSPSet('lexapi_id'), ENT_QUOTES) . '"></div>'
        . '<div class="ow-form-item"><label>接口 KEY</label><input class="ow-input" type="password" id="owSpam_lexapi_key" '
        . 'maxlength="80" autocomplete="off" placeholder="留空表示不修改"></div>'
        . '</div>'
        . '<div class="ow-form-row">'
        . owSPnum('lexapi_timeout', '超时(秒)', 'spam_lexapi_timeout', (string)OWSP_LEXAPI_TIMEOUT_DEF,
            '1～10 秒。这期间发送请求要等接口回话，别设太长。', OWSP_LEXAPI_TIMEOUT_MIN, OWSP_LEXAPI_TIMEOUT_MAX)
        . owSPnum('lexapi_permin', '每分钟上限', 'spam_lexapi_permin', (string)OWSP_LEXAPI_PERMIN_DEF,
            '免费注册额度是 10 次/分钟，填 0 表示不限制（额度不够请到接口盒子升级会员）。')
        . owSPnum('lexapi_daily', '每日上限', 'spam_lexapi_daily', (string)OWSP_LEXAPI_DAILY_DEF, '填 0 表示不限量')
        . '</div>'
        . '<div class="ow-form-row"><div class="ow-form-item" style="flex:1"><label>接口测试文本</label>'
        . '<input class="ow-input" id="owSpamLexApiTest" placeholder="粘一段文本，看接口认不认、认出了哪些词"></div>'
        . '<button class="ow-btn ow-btn-ghost" onclick="OwSW.lexApiTest()">测试接口</button></div>'
        . '<div id="owSpamLexApiStat" style="font-size:12px;color:var(--ow-text-sub);margin:4px 0"></div>'
        . '<hr class="ow-spam-hr">'
        . '<div class="ow-form-row" style="align-items:center">'
        . '<div class="ow-form-item" style="flex:1"><label>词库目录</label>'
        . '<input class="ow-input" value="' . htmlspecialchars(OWSP_LEX_REL, ENT_QUOTES) . '" readonly>'
        . '<p style="font-size:12px">把词库 txt 放进这个目录即可生效（一行一个词），目录随站点根走，不随插件升级丢失。'
        . '也可以直接点「在线下载」从词库仓库拉取整套（下载的是压缩包，一次请求拿全量）。</p></div>'
        . '</div>'
        . '<div id="owSpamLexStat" style="font-size:12px;color:var(--ow-text-sub);margin:6px 0">'
        . htmlspecialchars(owSPlexStatusText(), ENT_QUOTES) . '</div>'
        . '<div class="ow-form-row" style="margin-bottom:8px">'
        . '<button class="ow-btn ow-btn-ghost" onclick="OwSW.lexRescan()">重新扫描</button>'
        . '<button class="ow-btn ow-btn-ghost" onclick="OwSW.lexCheck()">立即检测更新</button>'
        . '<button class="ow-btn ow-btn-primary" onclick="OwSW.lexDownload()">在线下载词库</button>'
        . '</div>'
        . owSPswitch('lex_check_on', '自动检测词库更新',
            '按下面的间隔检查远端仓库是否有更新；发现更新会给所有超级管理员发一条系统通知。')
        . owSPnum('lex_check_hours', '检测间隔(小时)', 'spam_lex_check_hours', '24', '1～720 小时。检测只查仓库信息，不下载词库。')
        . owSPswitch('lex_notify_on', '有更新时通知管理员',
            '发现更新后给每个超级管理员发一条系统通知（在「系统通知」里可读可清），同一版本只提醒一次。')
        . ($lex['truncated']
            ? '<p style="font-size:12px;color:var(--ow-red)">部分词库超出单文件或总词数上限，已被截断，未全部生效。</p>' : '')
        . '<div class="ow-form-row" style="margin:12px 0 6px">'
        // 「即时生效」必须写在标题里：这张表的启用/停用走自己的路由，不归下面那个保存按钮管 ——
        // 不写清的话，管理员会去点保存、或者反过来以为点了就存好了
        . '<b style="margin-right:auto">词库文件（启用/停用即时生效，无需保存）</b>'
        . '<button class="ow-btn ow-btn-ghost" onclick="OwSW.lexAll(true)">全部启用</button>'
        . '<button class="ow-btn ow-btn-ghost" onclick="OwSW.lexAll(false)">全部停用</button>'
        . '</div>'
        . '<div class="ow-table-wrap"><table class="ow-table" id="owSpamLexTable"></table></div>'
        // 保存按钮压在**本页所有需要保存的设置**下面：它提交的是上面那些开关与输入框
        // （词库列表的启停走即时路由，不受它影响，已在标题里写明）
        . '<div class="ow-form-row" style="margin-top:16px">'
        . '<button class="ow-btn ow-btn-primary" onclick="OwSW.save(\'lex\')">保存违禁词设置</button>'
        . '</div>'
        . '</div>';

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
    } elseif ($g === 'lex') {
        DB::setSetting('spam_lex_on', $p('lex_on') === '1' ? '1' : '0');
        DB::setSetting('spam_lexapi_on', $p('lexapi_on') === '1' ? '1' : '0');
        DB::setSetting('spam_lexapi_skip_local', $p('lexapi_skip_local') === '1' ? '1' : '0');
        DB::setSetting('spam_lex_check_on', $p('lex_check_on') === '1' ? '1' : '0');
        DB::setSetting('spam_lex_notify_on', $p('lex_notify_on') === '1' ? '1' : '0');
        // 打码串留空 = 回到默认 ***：空串会让 strtr 把命中词**删掉**（替换成空），
        // 那是另一件事（等于把词抹了），不是打码，所以不接受空值
        $rep = trim($p('lex_replacement'));
        DB::setSetting('spam_lex_replacement', $rep === '' ? '***' : mb_substr($rep, 0, 8));
        DB::setSetting('spam_lexapi_action', in_array($p('lexapi_action'), ['mask', 'record'], true) ? $p('lexapi_action') : 'mask');
        DB::setSetting('spam_lexapi_timeout', (string)max(OWSP_LEXAPI_TIMEOUT_MIN,
            min(OWSP_LEXAPI_TIMEOUT_MAX, (int)$p('lexapi_timeout', (string)OWSP_LEXAPI_TIMEOUT_DEF))));
        DB::setSetting('spam_lexapi_permin', (string)max(0, min(600, (int)$p('lexapi_permin', (string)OWSP_LEXAPI_PERMIN_DEF))));
        DB::setSetting('spam_lexapi_daily', (string)max(0, min(1000000, (int)$p('lexapi_daily', (string)OWSP_LEXAPI_DAILY_DEF))));
        DB::setSetting('spam_lex_check_hours', (string)max(1, min(720, (int)$p('lex_check_hours', '24'))));
        DB::setSetting('spam_lexapi_id', mb_substr(preg_replace('/\s+/', '', $p('lexapi_id')), 0, 40));
        // KEY 与 AI 的密钥同一口径：只在非空时覆盖（页面里它是 password 输入框）
        $lk = $p('lexapi_key');
        if ($lk !== '') DB::setSetting('spam_lexapi_key', mb_substr($lk, 0, 80));
        // ⚠️ 词库文件的启用/停用**不在这里**：它走 plugin_spam_lex_toggle 即时生效，
        // 与「敏感词」那张表的启用/停用同一交互。若在这里再收一份 lex_disabled，
        // 没带这个字段的保存请求就会把它清空（等于一键把停用过的词库全放出来）。
        owSPlexCompiled(true);   // 立刻按新设置重建一次：出错在这一步就暴露，而不是等下一个用户发言
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
    // v1.5.20：分块 IN 批量删除
    $ok = 0;
    foreach (array_chunk($ids, 200) as $chunk) {
        [$inSql, $inArgs] = DB::in('id', $chunk);
        $ok += DB::run("DELETE FROM plugin_spam_appeal WHERE $inSql", $inArgs)->rowCount();
    }
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
    // v1.5.20：分块 IN 批量删除
    $ok = 0;
    foreach (array_chunk($ids, 200) as $chunk) {
        [$inSql, $inArgs] = DB::in('id', $chunk);
        $ok += DB::run("DELETE FROM sensitive_words WHERE $inSql", $inArgs)->rowCount();
    }
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

/* ============================ 违禁词库路由（v1.4.0） ============================ */

/** 词库文件清单 + 概况（下载 / 启停之后前端重新拉一次就能刷新，不必整页刷新） */
Plugin::route('plugin_spam_lex_list', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $c = owSPlexCompiled();
    $dis = owSPlexDisabled();
    $rows = [];
    foreach (owSPlexFiles() as $n => $f) {
        $rows[] = [
            'name' => $n, 'size' => $f['size'], 'mtime' => $f['mtime'],
            // -1 = 文件超限被跳过，前端显示成「—」而不是 0（0 会被读成「这份词库是空的」）
            'words' => (int)($c['counts'][$n] ?? 0),
            'enabled' => in_array($n, $dis, true) ? 0 : 1,
        ];
    }
    Api::json(['ok' => true, 'data' => [
        'files' => $rows,
        'stat' => owSPlexStat(),
        'status' => owSPlexStatusText(),
        'installed_at' => (int)DB::setting('spam_lex_installed_at', '0'),
        'checked_at' => (int)DB::setting('spam_lex_last_check', '0'),
        'update_pending' => owSPlexUpdatePending()[0],
    ]]);
});

/** 启用 / 停用单个词库文件 */
Plugin::route('plugin_spam_lex_toggle', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $name = (string)($ctx['post']['name'] ?? '');
    if (!in_array($name, array_keys(owSPlexFiles()), true)) Api::json(['ok' => false, 'msg' => '词库文件不存在']);
    $en = (int)($ctx['post']['enabled'] ?? 0) === 1;
    $dis = owSPlexDisabled();
    if ($en) $dis = array_values(array_diff($dis, [$name]));
    elseif (!in_array($name, $dis, true)) $dis[] = $name;
    DB::setSetting('spam_lex_disabled', implode("\n", $dis));
    // v1.5.22：停用名单变了，编译缓存立即作废
    DB::afterCommit(function () {
        if (class_exists('Cache')) Cache::clear('lexicon');
    });
    Api::json(['ok' => true, 'msg' => $en ? '已启用' : '已停用']);
});

/** 全部启用 / 全部停用：一次改完，省得逐个点 */
Plugin::route('plugin_spam_lex_all', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $all = (int)($ctx['post']['enabled'] ?? 1) === 1;
    DB::setSetting('spam_lex_disabled', $all ? '' : implode("\n", array_keys(owSPlexFiles())));
    // v1.5.22：停用名单变了，编译缓存立即作废，下次请求按新名单重建
    DB::afterCommit(function () {
        if (class_exists('Cache')) Cache::clear('lexicon');
    });
    Api::json(['ok' => true, 'msg' => $all ? '已全部启用' : '已全部停用']);
});

/** 重新扫描：丢掉编译缓存，按当前目录重建（管理员自己往目录里放了 txt 之后点这个） */
Plugin::route('plugin_spam_lex_rescan', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    if (class_exists('Cache')) Cache::clear('lexicon'); else @unlink(owSPlexCacheFile());
    $c = owSPlexCompiled(true);
    Sec::log('spam_lexicon_rescan', (string)($ctx['actor']['nickname'] ?? ''),
        ['files' => count(owSPlexFiles()), 'words' => (int)$c['words']]);
    Api::json(['ok' => true, 'msg' => '已重新扫描：' . count(owSPlexFiles()) . ' 个文件，'
        . number_format((int)$c['words']) . ' 条词生效', 'data' => ['stat' => owSPlexStat(), 'status' => owSPlexStatusText()]]);
});

/**
 * 在线下载（敏感操作：会覆盖 data/lexicon 下的词库文件）。
 * 标记 sensitive 是因为它是**覆盖本地数据**的写操作，走一次性票据 + 前端二次确认，
 * 与后台其它覆盖类动作同一道防线。
 */
Plugin::route('plugin_spam_lex_download', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $err = null;
    $r = owSPlexDownload($err);
    if (!$r) Api::json(['ok' => false, 'msg' => '下载失败：' . (string)$err]);
    $c = owSPlexCompiled(true);
    Api::json(['ok' => true, 'msg' => '已下载 ' . count($r['files']) . ' 个词库文件（'
        . number_format($r['bytes'] / 1024, 0) . ' KB），共 ' . number_format((int)$c['words']) . ' 条词生效',
        'data' => ['files' => count($r['files']), 'stat' => owSPlexStat(), 'status' => owSPlexStatusText()]]);
}, ['sensitive' => true]);

/** 立即检测更新（只查仓库信息，不下载） */
Plugin::route('plugin_spam_lex_check', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $r = owSPlexCheck();
    Api::json(['ok' => $r['ok'], 'msg' => $r['msg'], 'data' => [
        'changed' => !empty($r['changed']),
        'update_pending' => owSPlexUpdatePending()[0],
        'status' => owSPlexStatusText(),
    ]]);
});

/**
 * 测试接口配置。回显判定结果、接口认出的词、以及**用本插件打码串替换后的效果** ——
 * 只报「检出 3 个词」管理员没法判断打码长什么样，而这正是用户会看到的东西。
 */
Plugin::route('plugin_spam_lexapi_test', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $text = mb_substr(trim((string)($ctx['post']['text'] ?? '')), 0, 500);
    if ($text === '') Api::json(['ok' => false, 'msg' => '请先输入一段测试文本']);
    if (!owSPlexApiQuotaOk()) Api::json(['ok' => false, 'msg' => '本分钟或今日的调用额度已用完，稍后再试']);
    $r = owSPlexApiAsk($text);
    $masked = $text;
    if ($r['words']) {
        $pair = [];
        foreach ($r['words'] as $w) $pair[$w] = owSPlexRep();
        $masked = strtr($text, $pair);
    }
    Api::json(['ok' => true, 'api_ok' => $r['ok'], 'hit' => $r['hit'],
        'words' => array_slice($r['words'], 0, 50), 'word_count' => count($r['words']),
        'masked' => $masked, 'raw' => $r['raw'], 'ms' => $r['ms'], 'budget' => owSPlexApiTimeout()]);
});

/* ============================ 资源 ============================ */

Plugin::asset('js', 'sensitive-words/admin.js', 'admin');
Plugin::asset('js', 'sensitive-words/chat.js', 'front');
Plugin::asset('css', 'sensitive-words/style.css');
