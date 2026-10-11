<?php
/**
 * 邮箱验证插件（email-verify）
 *
 * 定位：管**发到哪、长什么样、发不发得出去**。
 * 「谁能拿码、多久能拿一次、输错几次作废」这套策略在核心（`Mailer` + settings 的 `mail_*` 键），
 * 本插件停用后策略照样生效，只是没人投递 —— 接口会明确回「站点未启用邮件发送」，
 * 验证码入库但等同于不存在（fail closed：拿不到码就没人能过校验）。
 *
 * 三块内容：
 *  1. 发送通道：SMTP（ssl / tls / none，socket 直连）或 PHP mail()。
 *  2. 邮件模板：三级来源 —— `data/mail-templates/{kind}.html`（管理员自己放的）
 *     → 后台保存的（本插件表）→ 内置默认。
 *  3. 发送统计：待发送 / 今日已发 / 发送失败 / 当前状态 + 最近记录。
 *
 * ⚠️ 凭据存放：SMTP 账号密码存本插件自有表，也就是 `data/` 里的库文件。
 * 这个位置由三条既有保障兜住：`.gitignore` 已排除 `/data/`（永不进仓库）、
 * `Upgrade::PROTECT` 含 `data/`（在线升级永不覆盖）、后台表单**只写不读**
 * （密码框回显空值，留空保存 = 保持原值）。
 *
 * ⚠️ 与参考实现（参考文献/email_notify_1.3.0）的一条关键差异：那边用裸
 * `stream_socket_client('ssl://...')`，**没有开证书校验**。AUTH 阶段送出去的是
 * 账号密码，中间人只要拿下这条连接就等于拿到一个邮箱账号，还能冒充「发送成功」。
 * 这里强制 verify_peer + 复用 Upgrade::caBundle() 的 CA 发现结果，
 * 找不到 CA 包时宁可失败也不关校验（错误文案直接告诉管理员缺什么）。
 *
 * ⚠️ 本文件**不写 declare(strict_types=1)**：插件由 Plugin::loadPlugin() 用 require_once
 * 引入，文件开头已有 OWLSGO_VERSION 守卫，此时 strict_types 不再是首条语句会直接致命错误。
 */
if (!defined('OWLSGO_VERSION')) exit;

$drv = DB::driver();
$id  = $drv === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY'
     : ($drv === 'pgsql' ? 'SERIAL PRIMARY KEY'
     : 'INTEGER PRIMARY KEY AUTOINCREMENT');
$int = $drv === 'mysql' ? 'INT' : 'INTEGER';
$str = $drv === 'sqlite' ? 'TEXT' : 'VARCHAR(191)';

DB::run("CREATE TABLE IF NOT EXISTS plugin_email_verify_config (k $str PRIMARY KEY, v TEXT)");
DB::run("CREATE TABLE IF NOT EXISTS plugin_email_verify_templates (
    kind $str PRIMARY KEY, subject TEXT, body TEXT, updated_at $int NOT NULL DEFAULT 0
)");
DB::run("CREATE TABLE IF NOT EXISTS plugin_email_verify_log (
    id $id, kind $str NOT NULL DEFAULT '', recipient $str NOT NULL,
    subject TEXT, status $str NOT NULL, error TEXT, created_at $int NOT NULL
)");
DB::createIndex('idx_pev_log_created', 'plugin_email_verify_log', 'created_at');
DB::createIndex('idx_pev_log_status', 'plugin_email_verify_log', 'status, created_at');

/** 场景清单：kind => 中文名。模板与统计都按它分组。 */
function owEVKinds(): array
{
    return [
        'register' => '注册账号',
        'reset'    => '找回密码',
        'chpwd'    => '修改密码',
    ];
}

/** 发送通道配置（一次取回，请求内复用） */
function owEVConf(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [
        'enabled' => '0', 'transport' => 'smtp',
        'smtp_host' => '', 'smtp_port' => '465', 'smtp_encryption' => 'ssl',
        'smtp_user' => '', 'smtp_pass' => '',
        'from_email' => '', 'from_name' => '',
    ];
    try {
        foreach (DB::all('SELECT k, v FROM plugin_email_verify_config') as $r) {
            $k = (string)$r['k'];
            if (array_key_exists($k, $cache)) $cache[$k] = (string)$r['v'];
        }
    } catch (Throwable $e) { /* 表还没建好时用默认值 */ }
    return $cache;
}

/** 配置是否足以发信（不含 enabled 判定） */
function owEVReady(array $c): bool
{
    if (!filter_var(trim($c['from_email']), FILTER_VALIDATE_EMAIL)) return false;
    if ($c['transport'] === 'mail') return function_exists('mail');
    $host = trim($c['smtp_host']);
    $port = (int)$c['smtp_port'];
    if ($host === '' || $port < 1 || $port > 65535) return false;
    if (!in_array($c['smtp_encryption'], ['ssl', 'tls', 'none'], true)) return false;
    // 明文 SMTP（none）允许无账号；加密通道要求账号密码成对
    $u = trim($c['smtp_user']); $p = (string)$c['smtp_pass'];
    if ($u === '' && $p !== '') return false;
    return true;
}

/** 本插件是否真正在发信：开关开着且配置齐全 */
function owEVActive(): bool
{
    $c = owEVConf();
    return $c['enabled'] === '1' && owEVReady($c);
}

/** 未就绪时缺了什么（给管理员看的可执行文案，不是布尔值） */
function owEVMissing(array $c): string
{
    if ($c['enabled'] !== '1') return '未开启';
    if (!filter_var(trim($c['from_email']), FILTER_VALIDATE_EMAIL)) return '发件地址未填或未通过格式校验';
    if ($c['transport'] === 'mail') return function_exists('mail') ? '' : '服务器未启用 PHP mail() 函数';
    if (trim($c['smtp_host']) === '') return 'SMTP 服务器地址未填';
    if ((int)$c['smtp_port'] < 1 || (int)$c['smtp_port'] > 65535) return 'SMTP 端口不合法';
    return '';
}

/* ===================== 模板 ===================== */

/** 模板目录（放在 data/ 里：升级保护名单含 data/，管理员自己放的文件不会被覆盖） */
function owEVTemplatesDir(): string
{
    return dirname(dirname(__DIR__)) . '/data/mail-templates';
}

/** 内置默认模板：主题 + 正文（HTML）。找不到任何可用模板时用它兜底。 */
function owEVDefaultTemplate(string $kind): array
{
    $label = owEVKinds()[$kind] ?? '邮箱验证';
    return [
        'subject' => '[{{site_name}}] 验证码 {{verification_code}}',
        'body' => '<div style="font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.8;color:#1f2329">'
            . '<p>您好，{{recipient_name}}：</p>'
            . '<p>您正在执行「{{action_label}}」，验证码：</p>'
            . '<p style="font-size:28px;font-weight:700;letter-spacing:6px;margin:12px 0;color:#2f6fb5">{{verification_code}}</p>'
            . '<p>验证码 <b>{{expires_in_minutes}}</b> 分钟内有效，每个验证码只能使用一次。</p>'
            . '<p>如果这不是您本人的操作，请忽略本邮件，并检查账号安全设置。</p>'
            . '<p style="color:#8a9099;font-size:12px;margin-top:16px">本邮件由 {{site_name}}（{{recipient_email}}）自动发送，请勿直接回复。</p>'
            . '</div>',
        'label' => $label,
    ];
}

/**
 * 取某场景的模板与来源。
 * 优先级说明：目录文件排在后台编辑之前 —— 用户既然手工放了文件，就是要它说话；
 * 反过来（后台优先）会让「替换文件没效果」变成排错噩梦。后台页面会显示当前来源。
 * @return array ['subject'=>..,'body'=>..,'source'=>'file'|'db'|'default']
 */
function owEVTemplate(string $kind): array
{
    $d = owEVDefaultTemplate($kind);
    $safe = preg_replace('/[^a-z_-]/', '', $kind);
    $file = owEVTemplatesDir() . '/' . $safe . '.html';
    if ($safe !== '' && is_file($file)) {
        $html = (string)@file_get_contents($file);
        if (trim($html) !== '') {
            // 文件里可以用 <!-- subject: 主题 --> 首行注释声明主题，否则用默认主题
            $subject = $d['subject'];
            if (preg_match('/<!--\s*subject\s*:?(.*?)-->/is', $html, $m) && trim($m[1]) !== '') {
                $subject = trim($m[1]);
            }
            return ['subject' => $subject, 'body' => $html, 'source' => 'file'];
        }
    }
    try {
        $row = DB::one('SELECT subject, body FROM plugin_email_verify_templates WHERE kind=?', [$safe]);
        if ($row && trim((string)$row['body']) !== '') {
            return [
                'subject' => trim((string)($row['subject'] ?? '')) !== '' ? (string)$row['subject'] : $d['subject'],
                'body' => (string)$row['body'], 'source' => 'db',
            ];
        }
    } catch (Throwable $e) { /* 读不到就当没有，走默认 */ }
    return ['subject' => $d['subject'], 'body' => $d['body'], 'source' => 'default'];
}

/** 占位符表（后台的「可插入字段」与替换逻辑共用一份，不会两边漂移） */
function owEVPlaceholders(): array
{
    return [
        '{{verification_code}}'   => '验证码',
        '{{expires_in_minutes}}'  => '有效期（分钟）',
        '{{site_name}}'           => '站点名',
        '{{recipient_name}}'      => '收件人昵称',
        '{{recipient_email}}'     => '收件邮箱',
        '{{action_label}}'        => '用途名称',
    ];
}

/** 变量表：键名去掉花括号，供 str_replace 使用 */
function owEVVars(array $ctx): array
{
    $kind = (string)($ctx['kind'] ?? '');
    $ttl = (int)($ctx['ttl'] ?? 10);
    return [
        '{{verification_code}}' => (string)($ctx['code'] ?? ''),
        '{{expires_in_minutes}}' => (string)$ttl,
        '{{site_name}}' => (string)($ctx['site_name'] ?? DB::setting('site_name', 'Owlsgo-Chat')),
        '{{recipient_name}}' => (string)($ctx['name'] ?? ''),
        '{{recipient_email}}' => (string)($ctx['email'] ?? ''),
        '{{action_label}}' => owEVKinds()[$kind] ?? '邮箱验证',
    ];
}

function owEVFill(string $tpl, array $vars): string
{
    return strtr($tpl, $vars);
}

/** HTML → 纯文本（邮件同时带 text 与 html 两个 part，纯文本是降级可读版） */
function owEVToText(string $html): string
{
    $t = preg_replace('#<(?:script|style)[^>]*>.*?</(?:script|style)>#is', '', $html);
    $t = preg_replace('#<br\s*/?>#i', "\n", $t);
    $t = preg_replace('#</(?:p|div|li|h[1-6]|tr)>#i', "\n", (string)$t);
    $t = html_entity_decode(strip_tags((string)$t), ENT_QUOTES, 'UTF-8');
    $t = preg_replace("/[ \t]+/", ' ', $t);
    $t = preg_replace("/\n{3,}/", "\n\n", $t);
    return trim($t);
}

/* ===================== 组装与投递 ===================== */

/** 头部编码（RFC 2047）：中文主题必须走 base64，否则 8 位数据直接进协议头 */
function owEVHeader(string $v): string
{
    $v = str_replace(["\r", "\n"], '', trim($v));
    if ($v === '') return '';
    // 纯 ASCII 不必编码：省得一封封的 "=?UTF-8?B?..." 在部分客户端里显示成原文
    if (preg_match('/^[\x20-\x7E]*$/', $v) && strpos($v, '"') === false) return $v;
    return '=?UTF-8?B?' . base64_encode($v) . '?=';
}

/** 收件人只接受单个合法邮箱，且绝不含 CR/LF —— 防邮件头注入（邮件版 log4j） */
function owEVSaneRecipient(string $to): string
{
    $to = trim(str_replace(["\r", "\n", ' '], '', $to));
    return filter_var($to, FILTER_VALIDATE_EMAIL) ? $to : '';
}

function owEVBuildMessage(string $to, string $subject, string $text, string $html, array $c): string
{
    $from = $c['from_email'];
    $fromName = owEVHeader($c['from_name'] !== '' ? $c['from_name'] : (string)DB::setting('site_name', 'Owlsgo-Chat'));
    $domain = (($at = strrpos($from, '@')) === false) ? 'localhost' : substr($from, $at + 1);
    $domain = preg_replace('/[^A-Za-z0-9.\-]/', '', $domain) ?: 'localhost';
    $boundary = 'bnd_' . bin2hex(random_bytes(12));
    $headers = 'Date: ' . gmdate('D, d M Y H:i:s') . " +0000\r\n"
        . 'From: ' . ($fromName !== '' ? $fromName . ' <' . $from . '>' : $from) . "\r\n"
        . 'To: <' . $to . ">\r\n"
        . 'Subject: ' . owEVHeader($subject) . "\r\n"
        . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . ">\r\n"
        . "MIME-Version: 1.0\r\n"
        . 'Content-Type: multipart/alternative; boundary="' . $boundary . "\"\r\n";
    $body = '--' . $boundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text))
        . '--' . $boundary . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html))
        . '--' . $boundary . "--\r\n";
    return $headers . "\r\n" . $body;
}

/** 读一行 SMTP 响应（多行响应读到 3 位码后跟空格的那行为止） */
function owEVExpect($fp, array $codes): string
{
    $resp = '';
    while (($line = fgets($fp, 515)) !== false) {
        $resp .= $line;
        if (isset($line[3]) && $line[3] === ' ') break;
    }
    $code = (int)substr($resp, 0, 3);
    if (!in_array($code, $codes, true)) {
        $t = trim($resp);
        throw new RuntimeException($t !== '' ? mb_substr($t, 0, 160) : 'SMTP 响应异常');
    }
    return $resp;
}

function owEVCmd($fp, string $cmd, array $codes): string
{
    fwrite($fp, $cmd . "\r\n");
    return owEVExpect($fp, $codes);
}

/**
 * TLS 校验上下文：verify_peer + verify_peer_name 一定要开，CA 复用升级模块的发现结果。
 * 找不到 CA 包时**不关校验**：PHP 会自行报 "unable to get local issuer certificate"，
 * 我们把这句话翻译成可执行的中文提示（见 owEVSmtpSend 的 catch）。
 */
function owEVsslContext(): array
{
    $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
    if (class_exists('Upgrade')) {
        $ca = Upgrade::caBundle();
        if (is_string($ca) && $ca !== '') $ssl['cafile'] = $ca;
    }
    return $ssl;
}

/** SMTP 直连投递。返回 ['ok'=>bool,'error'=>string] */
function owEVSmtpSend(string $to, string $subject, string $text, string $html, array $c): array
{
    $host = trim($c['smtp_host']);
    $port = (int)$c['smtp_port'];
    $enc = $c['smtp_encryption'];
    $remote = ($enc === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $ctx = stream_context_create(['ssl' => owEVsslContext()]);
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client($remote, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return ['ok' => false, 'error' => mb_substr(($errstr !== '' ? $errstr : '无法连接 SMTP 服务器') . ' #' . $errno, 0, 160)];
    }
    stream_set_timeout($fp, 15);
    try {
        owEVExpect($fp, [220]);
        $ehlo = 'localhost';
        if (function_exists('ow_site_url')) {
            $h = parse_url(ow_site_url(), PHP_URL_HOST);
            if (is_string($h) && $h !== '') $ehlo = preg_replace('/[^A-Za-z0-9.\-]/', '', $h) ?: 'localhost';
        }
        try {
            owEVCmd($fp, 'EHLO ' . $ehlo, [250]);
        } catch (Throwable $e) {
            owEVCmd($fp, 'HELO ' . $ehlo, [250]);
        }
        if ($enc === 'tls') {
            owEVCmd($fp, 'STARTTLS', [220]);
            // STARTTLS 之后重挂校验选项再握手，否则新通道可能落到不校验的默认上下文上
            stream_context_set_option($fp, ['ssl' => owEVsslContext()]);
            $ok = @stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($ok !== true) throw new RuntimeException('STARTTLS 握手失败（证书校验开启时可能被服务器自签证书拒绝）');
            owEVCmd($fp, 'EHLO ' . $ehlo, [250]);
        }
        $user = trim($c['smtp_user']);
        if ($user !== '' || (string)$c['smtp_pass'] !== '') {
            owEVCmd($fp, 'AUTH LOGIN', [334]);
            owEVCmd($fp, base64_encode($user), [334]);
            owEVCmd($fp, base64_encode((string)$c['smtp_pass']), [235]);
        }
        owEVCmd($fp, 'MAIL FROM:<' . $c['from_email'] . '>', [250]);
        owEVCmd($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
        owEVCmd($fp, 'DATA', [354]);
        // 点 Stuffing：正文里以单个「.」开头的行会被 SMTP 当成 DATA 结束，必须 doubling
        $msg = preg_replace('/^\./m', '..', owEVBuildMessage($to, $subject, $text, $html, $c));
        fwrite($fp, $msg . "\r\n.\r\n");
        owEVExpect($fp, [250]);
        try { owEVCmd($fp, 'QUIT', [221]); } catch (Throwable $e) { /* 有的服务商发完就断，投递已成功 */ }
        fclose($fp);
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        fclose($fp);
        $m = $e->getMessage();
        if (stripos($m, 'local issuer') !== false || stripos($m, 'certificate') !== false) {
            $m .= '（服务器缺少 CA 证书包：请在 php.ini 配 openssl.cafile / curl.cainfo，或装 Git for Windows 自带的 ca-bundle.crt）';
        }
        return ['ok' => false, 'error' => mb_substr($m, 0, 160)];
    }
}

/** PHP mail() 投递（Linux 主机常见，走本机 sendmail，不需要账号密码） */
function owEVMailSend(string $to, string $subject, string $text, string $html, array $c): array
{
    if (!function_exists('mail')) return ['ok' => false, 'error' => '服务器未启用 PHP mail() 函数'];
    $boundary = 'bnd_' . bin2hex(random_bytes(12));
    $fromName = owEVHeader($c['from_name'] !== '' ? $c['from_name'] : (string)DB::setting('site_name', 'Owlsgo-Chat'));
    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        'From: ' . ($fromName !== '' ? $fromName . ' <' . $c['from_email'] . '>' : $c['from_email']),
    ]);
    $body = '--' . $boundary . "\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text))
        . '--' . $boundary . "\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html))
        . '--' . $boundary . "--\r\n";
    // 第五个参数必须传空串：PHP 的 taint 检查（5.3+）要求第五参与消息体里的
    // \r\n--boundary 不匹配，传真参数会整封被拒。这里干脆不带 -f，由本机 MTA 决定。
    $ok = @mail($to, owEVHeader($subject), $body, $headers, '');
    return ['ok' => (bool)$ok, 'error' => $ok ? '' : 'mail() 返回失败（检查本机 sendmail / MTA 配置）'];
}

/** 统计入库（状态：sent / failed / pending） */
function owEVLog(string $status, string $to, string $subject, string $kind, string $error = ''): void
{
    try {
        DB::insert('plugin_email_verify_log', [
            'kind' => mb_substr($kind, 0, 32), 'recipient' => mb_substr($to, 0, 190),
            'subject' => mb_substr($subject, 0, 200), 'status' => $status,
            'error' => mb_substr($error, 0, 200), 'created_at' => time(),
        ]);
    } catch (Throwable $e) { /* 统计是旁路，写失败不影响发信 */ }
}

/* ===================== 核心钩子 ===================== */

/** 套模板：核心只管生成码，文案由这里出（html 格式一并改写） */
Plugin::on('mail.render', function (&$subject, &$body, &$format, array $ctx = []): void {
    // 只处理验证码：没有 code 的邮件（将来别的插件群发通知）一律不碰，
    // 否则本插件会把别人的正文换成验证码模板。
    if (trim((string)($ctx['code'] ?? '')) === '') return;
    if (!owEVActive()) return;         // 通道关着就不改文案，与 mail.send 同一判定
    $tpl = owEVTemplate((string)($ctx['kind'] ?? ''));
    $vars = owEVVars($ctx);
    $subject = mb_substr(trim(owEVFill($tpl['subject'], $vars)), 0, 180);
    $body = owEVFill($tpl['body'], $vars);
    $format = 'html';
});

/** 投递 + 记统计 */
Plugin::on('mail.send', function (&$sent, $to, $subject, $body, &$error, array $ctx = []): void {
    if (trim((string)($ctx['code'] ?? '')) === '') return;
    if (!owEVActive()) return;                 // 未开启：让核心去提示「站点未启用邮件发送」
    $recipient = owEVSaneRecipient((string)$to);
    if ($recipient === '') { $error = '收件邮箱无效'; return; }
    $c = owEVConf();
    $html = (string)$body;
    $text = ($ctx['format'] ?? 'html') === 'html' ? owEVToText($html) : $html;
    $r = $c['transport'] === 'mail'
        ? owEVMailSend($recipient, (string)$subject, $text, $html, $c)
        : owEVSmtpSend($recipient, (string)$subject, $text, $html, $c);
    $sent = $r['ok'];
    $error = $r['ok'] ? '' : $r['error'];
    owEVLog($r['ok'] ? 'sent' : 'failed', $recipient, (string)$subject, (string)($ctx['kind'] ?? ''), $r['error']);
});

/**
 * 告诉核心「这台站点现在真能发信吗」。
 * 核心用它决定要不要**要求**邮箱验证码（Mailer::deliverable）：
 * 只看后台开关会出现「要求一个根本发不出来的凭证」——用户被永久卡在改不了密码上。
 * 停用本插件、或开关/参数没配齐，这里就返回空串 → 需要邮箱码的流程自动降级。
 */
Plugin::on('mail.available', function (): string {
    return owEVActive() ? '1' : '';
});

/** 没有任何通道接手：记一条「待发送」，后台统计卡才解释得清为什么码发了却没到 */
Plugin::on('mail.unsent', function (array $info = []): void {
    $kind = (string)($info['kind'] ?? '');
    if ($kind === '' || !isset(owEVKinds()[$kind])) return;
    owEVLog('pending', (string)($info['to'] ?? ''), (string)($info['subject'] ?? ''), $kind, '无可用发送通道');
});

/* ===================== 后台页 ===================== */

/** 统计卡片 + 最近记录 */
function owEVStats(): array
{
    $day = strtotime('today');
    $out = ['pending' => 0, 'sent' => 0, 'failed' => 0, 'recent' => [], 'last_error' => '', 'total' => 0];
    try {
        foreach (DB::all('SELECT status, COUNT(*) n FROM plugin_email_verify_log WHERE created_at>=? GROUP BY status', [$day]) as $r) {
            $k = (string)$r['status'];
            if ($k === 'sent') $out['sent'] = (int)$r['n'];
            elseif ($k === 'failed') $out['failed'] = (int)$r['n'];
            elseif ($k === 'pending') $out['pending'] = (int)$r['n'];
        }
        $out['total'] = (int)(DB::val('SELECT COUNT(*) FROM plugin_email_verify_log') ?: 0);
        $out['recent'] = DB::all(
            'SELECT kind, recipient, subject, status, error, created_at FROM plugin_email_verify_log
             ORDER BY id DESC LIMIT 20'
        );
        $e = DB::one("SELECT error FROM plugin_email_verify_log WHERE status='failed' ORDER BY id DESC LIMIT 1");
        $out['last_error'] = (string)($e['error'] ?? '');
    } catch (Throwable $e) { /* 表还没写好时统计全 0 */ }
    return $out;
}

Plugin::adminPage('email-verify', '邮箱验证', function () {
    $c = owEVConf();
    $p = Mailer::policy();
    $st = owEVStats();
    $kinds = owEVKinds();
    $cur = (string)($c['transport']);
    $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    $h = '<h2>邮箱验证</h2>'
        . '<p class="ow-admin-desc">SMTP / mail() 发送通道、邮件模板与发送统计。验证码的有效期与限流策略也在这里改；'
        . '停用本插件后验证码仍会生成与校验，但发不出去。</p>';

    // ---------- 统计卡 ----------
    // 状态卡只放两档字（就绪 / 未就绪），原因写在下面那行小字里：
    // 把「未就绪：发件地址未填或未通过格式校验」整句塞进大号数字位，四张卡会被撑成一条横幅。
    $ready = owEVActive();
    $why = $ready ? '通道已开启，验证码可直接投递' : (owEVMissing($c) !== '' ? owEVMissing($c) : '配置不完整');
    $h .= '<div class="ow-ev-cards">'
        . '<div class="ow-ev-card"><b>' . $st['pending'] . '</b><span>今日待发送</span></div>'
        . '<div class="ow-ev-card"><b>' . $st['sent'] . '</b><span>今日已发</span></div>'
        . '<div class="ow-ev-card"><b>' . $st['failed'] . '</b><span>今日失败</span></div>'
        . '<div class="ow-ev-card' . ($ready ? ' is-ok' : ' is-off') . '"><b>' . ($ready ? '就绪' : '未就绪') . '</b>'
        . '<span>' . $esc($why) . '</span></div>'
        . '</div>';
    if ($st['last_error'] !== '') {
        $h .= '<p class="ow-ev-last">最近一次失败原因：' . $esc($st['last_error']) . '</p>';
    }

    // ---------- 通道 ----------
    $h .= '<div class="ow-card"><h3>发送通道</h3><div class="ow-form-row">'
        . '<div class="ow-form-item" style="min-width:170px"><label>启用邮件发送</label>'
        . '<select class="ow-input" id="owEvEnabled">'
        . '<option value="0"' . ($c['enabled'] !== '1' ? ' selected' : '') . '>关闭（验证码发不出去）</option>'
        . '<option value="1"' . ($c['enabled'] === '1' ? ' selected' : '') . '>开启</option></select>'
        . '<p class="ow-form-hint">关掉后注册 / 找回密码会提示「站点未启用邮件发送」，这是刻意的：发不出的验证码等于没有。</p></div>'
        . '<div class="ow-form-item" style="min-width:170px"><label>通道类型</label>'
        . '<select class="ow-input" id="owEvTransport" onchange="OwEv.showTrans()">'
        . '<option value="smtp"' . ($cur !== 'mail' ? ' selected' : '') . '>SMTP（推荐）</option>'
        . '<option value="mail"' . ($cur === 'mail' ? ' selected' : '') . '>PHP mail()（依赖本机 MTA）</option></select></div>'
        . '</div><div class="ow-form-row ow-ev-trans" data-owev-trans="smtp">';
    $h .= '<div class="ow-form-item" style="min-width:190px"><label>SMTP 服务器</label>'
        . '<input class="ow-input" id="owEvHost" data-owev="smtp_host" type="text" autocomplete="off" value="' . $esc($c['smtp_host']) . '" placeholder="smtp.qq.com"></div>'
        . '<div class="ow-form-item" style="min-width:96px;max-width:110px"><label>端口</label>'
        . '<input class="ow-input" id="owEvPort" data-owev="smtp_port" type="number" min="1" max="65535" value="' . $esc($c['smtp_port']) . '"></div>'
        . '<div class="ow-form-item" style="min-width:150px"><label>加密方式</label><select class="ow-input" id="owEvEnc" data-owev="smtp_encryption">'
        . '<option value="ssl"' . ($c['smtp_encryption'] === 'ssl' ? ' selected' : '') . '>SSL（465）</option>'
        . '<option value="tls"' . ($c['smtp_encryption'] === 'tls' ? ' selected' : '') . '>STARTTLS（587）</option>'
        . '<option value="none"' . ($c['smtp_encryption'] === 'none' ? ' selected' : '') . '>明文（仅内网）</option>'
        . '</select><p class="ow-form-hint">SSL 与 STARTTLS 一律校验服务器证书，没有「跳过校验」开关 —— 明文跳过等于把邮箱密码交给中间人。内网自签请选明文或把根证书加进系统 CA。</p></div>'
        . '<div class="ow-form-item" style="min-width:190px"><label>账号</label>'
        . '<input class="ow-input" id="owEvUser" data-owev="smtp_user" type="text" autocomplete="off" value="' . $esc($c['smtp_user']) . '" placeholder="完整的发件邮箱"></div>'
        . '<div class="ow-form-item" style="min-width:190px"><label>密码 / 授权码</label>'
        . '<input class="ow-input" id="owEvPass" data-owev="smtp_pass" type="password" autocomplete="new-password" value="" placeholder="'
        . ($c['smtp_pass'] !== '' ? '已保存，留空即不修改' : 'QQ / 163 需用授权码，不是登录密码') . '"></div>'
        . '</div><div class="ow-form-row ow-ev-trans" data-owev-trans="mail"' . ($cur === 'mail' ? '' : ' style="display:none"') . '>'
        . '<div class="ow-form-item" style="flex:1 1 100%"><label>PHP mail()</label>'
        . '<p class="ow-form-hint">由服务器上的 sendmail / postfix 直接投递，不需要账号密码，但要本机 MTA 能对外发信，'
        . '且发件地址通常必须与本机域名一致。Windows 上没有内置 MTA，请改用 SMTP。</p></div></div>';
    $h .= '<div class="ow-form-row">'
        . '<div class="ow-form-item" style="min-width:210px"><label>发件地址</label>'
        . '<input class="ow-input" id="owEvFrom" data-owev="from_email" type="text" autocomplete="off" value="' . $esc($c['from_email']) . '" placeholder="noreply@example.com"></div>'
        . '<div class="ow-form-item" style="min-width:210px"><label>发件人名称</label>'
        . '<input class="ow-input" id="owEvFromName" data-owev="from_name" type="text" value="' . $esc($c['from_name']) . '" placeholder="留空则用站点名"></div>'
        . '</div>'
        . '<button class="ow-btn ow-btn-primary" onclick="OwEv.save()">保存设置</button>'
        . '<button class="ow-btn ow-btn-ghost" onclick="OwEv.test()" style="margin-left:8px">发测试邮件给我</button>'
        . '<p class="ow-ev-note">测试邮件只会发到当前登录管理员自己账号的邮箱，不会给任意地址发信。</p>'
        . '</div>';

    // ---------- 策略 ----------
    $num = static function (string $id, string $key, string $label, int $val, string $hint = ''): string {
        return '<div class="ow-form-item" style="min-width:150px"><label>' . $label . '</label>'
            . '<input class="ow-input" id="' . $id . '" data-owev-set="' . $key . '" type="number" value="' . (int)$val . '">'
            . ($hint !== '' ? '<p class="ow-form-hint">' . $hint . '</p>' : '') . '</div>';
    };
    $h .= '<div class="ow-card"><h3>验证码与限流</h3><div class="ow-form-row">'
        . $num('owEvTtl', 'mail_code_ttl', '有效期（分钟）', $p['ttl'])
        . $num('owEvResend', 'mail_rate_limit', '重发间隔（秒）', $p['resend'])
        . $num('owEvMailHour', 'mail_mail_hourly', '单邮箱每小时上限', $p['mail_hourly'], '0 = 不限制')
        . $num('owEvIpHour', 'mail_ip_hourly', '单 IP 每小时上限', $p['ip_hourly'], '0 = 不限制')
        . $num('owEvBrHour', 'mail_browser_hourly', '单浏览器每小时上限', $p['browser_hourly'], '0 = 不限制')
        . $num('owEvTry', 'mail_max_attempts', '最多输错次数', $p['max_attempts'], '0 = 不限制')
        . $num('owEvMin', 'mail_email_min_len', '邮箱最小长度', $p['email_min_len'])
        . $num('owEvMax', 'mail_email_max_len', '邮箱最大长度', $p['email_max_len'])
        . '</div><div class="ow-form-row">'
        . '<div class="ow-form-item" style="flex:1 1 100%"><label>允许邮箱后缀</label>'
        . '<input class="ow-input" id="owEvDomains" data-owev-set="mail_allowed_domains" type="text" value="' . $esc($p['allowed_domains']) . '" placeholder="qq.com,163.com,gmail.com">'
        . '<p class="ow-form-hint">留空表示不限制后缀，多个后缀用逗号分隔。填 qq.com 同时接受 mail.qq.com（按后缀匹配）。</p></div>'
        . '</div><div class="ow-form-row">'
        . '<div class="ow-form-item" style="min-width:170px"><label>邮箱唯一检查</label><select class="ow-input" id="owEvUnique" data-owev-set="mail_unique_check">'
        . '<option value="1"' . ($p['unique_check'] ? ' selected' : '') . '>开启</option>'
        . '<option value="0"' . (!$p['unique_check'] ? ' selected' : '') . '>关闭</option></select>'
        . '<p class="ow-form-hint">关闭后同一邮箱可注册多个账号，而用邮箱登录只会命中最早注册的那个 —— 除非确实要放开源注册，否则保持开启。</p></div>'
        . '<div class="ow-form-item" style="min-width:170px"><label>允许用户修改邮箱</label><select class="ow-input" id="owEvChange" data-owev-set="mail_allow_change">'
        . '<option value="1"' . ($p['allow_change_email'] ? ' selected' : '') . '>开启</option>'
        . '<option value="0"' . (!$p['allow_change_email'] ? ' selected' : '') . '>关闭</option></select>'
        . '<p class="ow-form-hint">目前站点还没有「修改邮箱」入口，这一项先留着：将来做该功能的插件会直接读它。</p></div>'
        . '<div class="ow-form-item" style="min-width:170px"><label>开启邮箱验证码</label><select class="ow-input" id="owEvVerify" data-owev-set="mail_code_verify">'
        . '<option value="1"' . ($p['code_verify'] ? ' selected' : '') . '>开启</option>'
        . '<option value="0"' . (!$p['code_verify'] ? ' selected' : '') . '>关闭</option></select>'
        . '<p class="ow-form-hint">关掉后不再发码、不再要求填码，找回密码页会直接提示联系管理员；注册仍受「注册需邮箱验证」那一项约束。</p></div>'
        . '</div><button class="ow-btn ow-btn-primary" onclick="OwEv.savePolicy()">保存策略</button></div>';

    // ---------- 模板 ----------
    $h .= '<div class="ow-card"><h3>邮件模板</h3>'
        . '<div class="ow-form-row"><div class="ow-form-item" style="min-width:170px"><label>场景</label>'
        . '<select class="ow-input" id="owEvKind" onchange="OwEv.loadTpl()">';
    foreach ($kinds as $k => $label) {
        $h .= '<option value="' . $k . '">' . $esc($label) . '（' . $k . '）</option>';
    }
    $h .= '</select><p class="ow-form-hint">以后新加的邮件场景（如换邮箱、注销账号）会按同样的 kind 取模板，无需改这里。</p></div>'
        . '<div class="ow-form-item" style="min-width:170px"><label>当前来源</label><p class="ow-ev-src" id="owEvTplSrc">—</p></div>'
        . '</div>'
        . '<div class="ow-ev-chips" id="owEvChips">';
    foreach (owEVPlaceholders() as $ph => $label) {
        $h .= '<button type="button" class="ow-btn ow-btn-ghost ow-ev-chip" onclick="OwEv.paste(\'' . $ph . '\')">' . $esc($ph) . '<i>' . $esc($label) . '</i></button>';
    }
    $h .= '</div>'
        . '<div class="ow-form-item"><label>主题</label><input class="ow-input" id="owEvTplSubject" type="text" value=""></div>'
        . '<div class="ow-form-item"><label>正文（HTML）</label><textarea class="ow-input" id="owEvTplBody" rows="10" spellcheck="false"></textarea></div>'
        . '<button class="ow-btn ow-btn-primary" onclick="OwEv.saveTpl()">保存模板</button>'
        . '<button class="ow-btn ow-btn-ghost" onclick="OwEv.preview()" style="margin-left:8px">预览</button>'
        . '<button class="ow-btn ow-btn-ghost" onclick="OwEv.restoreTpl()" style="margin-left:8px">恢复内置默认</button>'
        . '<p class="ow-ev-note">想整份替换而自己管理文件：把 HTML 存进 <code>data/mail-templates/场景名.html</code>'
        . '（例如 data/mail-templates/register.html），它优先生效、且在线升级不会覆盖。'
        . '文件第一行可用 <code>&lt;!-- subject: 主题 --&gt;</code> 声明主题。</p>'
        . '<iframe class="ow-ev-prev" id="owEvPrev" title="模板预览" sandbox=""></iframe>'
        . '</div>';

    // ---------- 统计明细 ----------
    $h .= '<div class="ow-card"><h3>发送记录</h3>'
        . '<p class="ow-ev-note">待发送 = 验证码已生成但没有任何通道接手（多为插件未开启或通道未配好）。记录保留 30 天。</p>';
    if (!$st['recent']) {
        $h .= '<p class="ow-ev-note">还没有发送记录。</p>';
    } else {
        $h .= '<div class="ow-table-wrap"><table class="ow-table"><thead><tr><th>时间</th><th>场景</th><th>收件邮箱</th><th>状态</th><th>说明</th></tr></thead><tbody>';
        foreach ($st['recent'] as $r) {
            $kind = (string)$r['kind'];
            $h .= '<tr><td>' . date('m-d H:i', (int)$r['created_at']) . '</td>'
                . '<td>' . $esc($kinds[$kind] ?? $kind) . '</td>'
                . '<td>' . $esc((string)$r['recipient']) . '</td>'
                . '<td class="ow-ev-' . $esc((string)$r['status']) . '">' . $esc(['sent' => '已发', 'failed' => '失败', 'pending' => '待发送'][$r['status']] ?? (string)$r['status']) . '</td>'
                . '<td>' . $esc(mb_substr((string)$r['error'], 0, 80)) . '</td></tr>';
        }
        $h .= '</tbody></table></div>'
            // 条数不写进按钮文案：语言包按整句精确匹配，拼了数字就永远匹配不上
            . '<button class="ow-btn ow-btn-ghost" onclick="OwEv.clearLog()">清空记录</button>';
    }
    $h .= '</div>';
    return $h;
});

/* ---------- 路由 ---------- */

/** 通道配置的白名单（决定哪些键允许写） */
function owEVFieldKeys(): array
{
    return ['enabled', 'transport', 'smtp_host', 'smtp_port', 'smtp_encryption',
            'smtp_user', 'smtp_pass', 'from_email', 'from_name'];
}

Plugin::route('plugin_email_verify_save', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $post = $ctx['post'];
    $kv = [];
    foreach (owEVFieldKeys() as $k) {
        if (!array_key_exists($k, $post)) continue;
        $kv[$k] = mb_substr(trim((string)$post[$k]), 0, 300);
    }
    $kv['enabled'] = ($kv['enabled'] ?? '0') === '1' ? '1' : '0';
    $kv['transport'] = ($kv['transport'] ?? 'smtp') === 'mail' ? 'mail' : 'smtp';
    $kv['smtp_encryption'] = in_array($kv['smtp_encryption'] ?? 'ssl', ['ssl', 'tls', 'none'], true) ? $kv['smtp_encryption'] : 'ssl';
    $kv['smtp_port'] = (string)max(1, min(65535, (int)($kv['smtp_port'] ?? 465)));
    // 密码留空 = 保持原值（表单里本来就不回显它）
    if (($kv['smtp_pass'] ?? '') === '') unset($kv['smtp_pass']);
    // 开着关但配置不全 → 拒绝，否则后台只显示「未就绪」而没人知道哪坏了
    $merged = owEVConf();
    foreach ($kv as $k => $v) $merged[$k] = $v;
    if ($kv['enabled'] === '1' && !owEVReady($merged)) {
        Api::json(['ok' => false, 'msg' => '配置不完整：' . (owEVMissing($merged) !== '' ? owEVMissing($merged) : '请检查发件地址与 SMTP 参数')]);
    }
    foreach ($kv as $k => $v) DB::upsert('plugin_email_verify_config', ['k' => $k, 'v' => $v], ['k']);
    Sec::log('plugin_email_verify_save', '', ['enabled' => $kv['enabled'], 'transport' => $kv['transport']]);
    Api::json(['ok' => true, 'msg' => '已保存']);
});
Plugin::sensitive('plugin_email_verify_save');

/** 策略保存：写的是核心 settings 的 mail_* 键（键名必须逐个白名单） */
Plugin::route('plugin_email_verify_policy', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $allow = ['mail_code_ttl', 'mail_rate_limit', 'mail_mail_hourly', 'mail_ip_hourly', 'mail_browser_hourly',
              'mail_max_attempts', 'mail_email_min_len', 'mail_email_max_len', 'mail_allowed_domains',
              'mail_unique_check', 'mail_allow_change', 'mail_code_verify'];
    $got = 0;
    foreach ($allow as $k) {
        if (!array_key_exists($k, $ctx['post'])) continue;
        DB::setSetting($k, mb_substr(trim((string)$ctx['post'][$k]), 0, 2000));
        $got++;
    }
    if ($got === 0) Api::json(['ok' => false, 'msg' => '没有收到任何可保存的设置项']);
    // 长度上下限写反了会让所有邮箱都过不了，直接拒
    $p = Mailer::policy();
    if ($p['email_min_len'] > $p['email_max_len']) {
        Api::json(['ok' => false, 'msg' => '邮箱最小长度不能大于最大长度']);
    }
    Api::json(['ok' => true, 'msg' => '策略已保存']);
});
Plugin::sensitive('plugin_email_verify_policy');

/** 读取某场景模板（后台编辑器与预览用）。default=1 时返回内置默认，供「恢复默认」填回编辑器。 */
Plugin::route('plugin_email_verify_template', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $kind = preg_replace('/[^a-z_-]/', '', (string)($ctx['post']['kind'] ?? ''));
    if ((string)($ctx['post']['default'] ?? '') === '1') {
        $d = owEVDefaultTemplate($kind);
        Api::json(['ok' => true, 'data' => ['subject' => $d['subject'], 'body' => $d['body'], 'source' => 'default']]);
    }
    Api::json(['ok' => true, 'data' => owEVTemplate($kind)]);
});

Plugin::route('plugin_email_verify_template_save', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $kind = preg_replace('/[^a-z_-]/', '', (string)($ctx['post']['kind'] ?? ''));
    if (!isset(owEVKinds()[$kind])) Api::json(['ok' => false, 'msg' => '未知场景']);
    $subject = mb_substr(trim((string)($ctx['post']['subject'] ?? '')), 0, 180);
    $body = (string)($ctx['post']['body'] ?? '');
    if (strlen($body) > 60000) Api::json(['ok' => false, 'msg' => '模板正文过长（上限 60000 字节）']);
    if (trim($body) === '') {
        // 空正文 = 删掉自定义那一行，回到内置默认。
        // 存一条空模板会让每封邮件都变成空信 —— 那是「保存成功、邮件全空」的静默故障。
        DB::run('DELETE FROM plugin_email_verify_templates WHERE kind=?', [$kind]);
        Api::json(['ok' => true, 'msg' => '已恢复内置默认模板']);
    }
    DB::upsert('plugin_email_verify_templates',
        ['kind' => $kind, 'subject' => $subject, 'body' => $body, 'updated_at' => time()],
        ['kind']);
    Api::json(['ok' => true, 'msg' => '模板已保存']);
});
Plugin::sensitive('plugin_email_verify_template_save');

/** 预览：用假数据把模板渲染成 HTML，交给后台里的沙箱 iframe 显示 */
Plugin::route('plugin_email_verify_preview', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $kind = preg_replace('/[^a-z_-]/', '', (string)($ctx['post']['kind'] ?? ''));
    $tpl = owEVTemplate($kind);
    $subject = (string)($ctx['post']['subject'] ?? $tpl['subject']);
    $body = (string)($ctx['post']['body'] ?? $tpl['body']);
    $vars = owEVVars(['kind' => $kind, 'code' => '123456', 'ttl' => Mailer::policy()['ttl'],
        'email' => 'someone@example.com', 'name' => '示例昵称']);
    Api::json([
        'ok' => true,
        'data' => ['subject' => owEVFill(mb_substr($subject, 0, 180), $vars),
                   'html' => owEVFill(mb_substr($body, 0, 60000), $vars)],
    ]);
});

/** 发测试邮件：只发给**当前管理员自己的邮箱**，不接受任意收件地址 */
Plugin::route('plugin_email_verify_test', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    if (!owEVActive()) {
        $m = owEVMissing(owEVConf());
        Api::json(['ok' => false, 'msg' => '邮件发送未开启或配置不完整' . ($m !== '' ? '（' . $m . '）' : '')]);
    }
    $email = trim((string)DB::val('SELECT email FROM users WHERE id=?', [(int)$ctx['actor']['id']]));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Api::json(['ok' => false, 'msg' => '你的账号没有可用邮箱，无法接收测试邮件']);
    $kind = in_array((string)($ctx['post']['kind'] ?? ''), array_keys(owEVKinds()), true) ? (string)$ctx['post']['kind'] : 'register';
    $vars = owEVVars(['kind' => $kind, 'code' => '123456', 'ttl' => Mailer::policy()['ttl'],
        'email' => $email, 'name' => (string)($ctx['actor']['nickname'] ?? '')]);
    $tpl = owEVTemplate($kind);
    $subject = owEVFill($tpl['subject'], $vars);
    $html = owEVFill($tpl['body'], $vars);
    $c = owEVConf();
    $r = $c['transport'] === 'mail'
        ? owEVMailSend($email, $subject, owEVToText($html), $html, $c)
        : owEVSmtpSend($email, $subject, owEVToText($html), $html, $c);
    owEVLog($r['ok'] ? 'sent' : 'failed', $email, $subject, 'test', $r['error']);
    Api::json(['ok' => $r['ok'], 'msg' => $r['ok'] ? ('测试邮件已发到 ' . $email) : ('发送失败：' . $r['error'])]);
});
Plugin::sensitive('plugin_email_verify_test');

Plugin::route('plugin_email_verify_log_clear', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    DB::run('DELETE FROM plugin_email_verify_log');
    Api::json(['ok' => true, 'msg' => '发送记录已清空']);
});
Plugin::sensitive('plugin_email_verify_log_clear');

/** 日志保留 30 天（统计只看今天，留久了没人看，只是把库撑大） */
Plugin::cron('email_verify_purge_log', 86400, function () {
    $n = (int)DB::val('SELECT COUNT(*) FROM plugin_email_verify_log WHERE created_at<?', [time() - 30 * 86400]);
    DB::run('DELETE FROM plugin_email_verify_log WHERE created_at<?', [time() - 30 * 86400]);
    return '清理发送记录 ' . $n . ' 条';
}, '清理 30 天前的邮件发送记录（每天一次）');

/** 后台样式：核心不引合并 CSS 包，插件自己注入 <link>（只取本插件这一个文件）。 */
Plugin::on('page.head', function (): void {
    echo '<link rel="stylesheet" href="?action=assets&type=css&plugin=email-verify&file=style.css&v=' . OWLSGO_VERSION . '">';
});

Plugin::asset('js', 'email-verify/admin.js');
Plugin::asset('css', 'email-verify/style.css');
