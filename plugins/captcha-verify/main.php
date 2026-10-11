<?php
/**
 * 人机验证插件（captcha-verify）
 *
 * 集成四家服务商：Cloudflare Turnstile、Cap、VAPTCHA、极验 Geetest v4。
 * 开启「服务端二次验证」后**取代**核心自带的 SVG 数字图形验证码（`Sec::captcha()` 那条链路）。
 *
 * ⚠️ 三条不可退让的原则：
 *  1. **服务端二次校验是唯一的判定依据**。前端组件只是取得 token 的UI，
 *     攻击者绕过页面直接 POST 时，只有 siteverify 能拦住他。
 *  2. **fail closed**：校验请求本身出错（网络不通、对方 5xx）一律判为不通过。
 *     反过来做等于「把服务商打挂就能免验证登录」。
 *  3. **不信客户端给的校验地址**。VAPTCHA 的 SDK 会连 `server` 字段一起回传
 *     （参考实现 bearsimple 就直接 curl 那个 URL）—— 那等于让攻击者自己决定
 *     「问谁要结果」，他起一个恒返回成功的端点就完事了。这里只接受官方域名，
 *     不在白名单内的一律改用配置里的默认端点。
 *
 * 对核心的三个钩子（下一步把注册 / 找回密码 / 邮箱发送也接上时，同样只用这三个）：
 *   - `captcha.takeover`  返回 '1' 表示本插件接管人机验证，核心不再出 SVG 图形码
 *   - `captcha.form`      产出要嵌进表单的组件 HTML（参数 ['scene' => 'login']）
 *   - `captcha.enforce`   服务端校验，引用回传 [$ok, $msg, $ctx]
 */
declare(strict_types=1);

DB::run("CREATE TABLE IF NOT EXISTS plugin_captcha_verify_config (k VARCHAR(64) PRIMARY KEY, v TEXT)");

/** 服务商清单与各自必填字段。fields 的键就是配置表的键，也是保存接口的字段名。 */
function owCVProviders(): array
{
    return [
        'turnstile' => [
            'name'   => 'Cloudflare Turnstile',
            'fields' => ['turnstile_site_key' => 'Site Key', 'turnstile_secret_key' => 'Secret Key'],
            'note'   => '免费、不限次、无图片。在 https://dash.cloudflare.com 的 Turnstile 里创建站点获取两把钥匙。',
        ],
        'cap' => [
            'name'   => 'Cap',
            'fields' => ['cap_instance_url' => '实例地址', 'cap_site_key' => 'Site Key', 'cap_secret_key' => 'Secret Key', 'cap_script_url' => '组件脚本地址'],
            'note'   => '开源、可自托管（https://trycap.dev）。实例地址填你自己的 Cap 服务根地址，不要带结尾斜杠。',
        ],
        'vaptcha' => [
            'name'   => 'VAPTCHA',
            'fields' => ['vaptcha_vid' => '验证单元 VID', 'vaptcha_key' => '验证单元 KEY'],
            'note'   => '国内可用、点击式动画验证。VID 与 KEY 在 vaptcha.com 控制台创建验证单元后获得。',
        ],
        'geetest' => [
            'name'   => '极验 Geetest v4',
            'fields' => ['geetest_captcha_id' => 'captcha_id', 'geetest_captcha_key' => 'captcha_key'],
            'note'   => '只支持 GeeTest v4（gcaptcha4）。captcha_key 是服务端签名密钥，不要下发到前端。',
        ],
    ];
}

/** 全部配置（一次取回，整个请求内复用） */
function owCVConf(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [
        'enabled' => '0', 'provider' => 'turnstile',
        'turnstile_site_key' => '', 'turnstile_secret_key' => '',
        'cap_instance_url' => '', 'cap_site_key' => '', 'cap_secret_key' => '',
        'cap_script_url' => 'https://cdn.jsdelivr.net/npm/cap-widget',
        'vaptcha_vid' => '', 'vaptcha_key' => '',
        'geetest_captcha_id' => '', 'geetest_captcha_key' => '',
    ];
    try {
        foreach (DB::all('SELECT k, v FROM plugin_captcha_verify_config') as $r) {
            $k = (string)$r['k'];
            if (array_key_exists($k, $cache)) $cache[$k] = (string)$r['v'];
        }
    } catch (Throwable $e) { /* 表还没建好时用默认值 */ }
    return $cache;
}

/** 某服务商的必填字段是否齐全（cap_script_url 有默认值，不算必填） */
function owCVReady(string $provider, array $c): bool
{
    $defs = owCVProviders();
    if (!isset($defs[$provider])) return false;
    foreach (array_keys($defs[$provider]['fields']) as $k) {
        if ($k === 'cap_script_url') continue;
        if (trim((string)($c[$k] ?? '')) === '') return false;
    }
    // 实例地址与脚本地址必须是合法 http(s)，否则会被拿去拼 curl 目标
    if ($provider === 'cap') {
        foreach (['cap_instance_url', 'cap_script_url'] as $k) {
            $v = trim((string)($c[$k] ?? ''));
            if ($v !== '' && (!filter_var($v, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $v))) return false;
        }
    }
    return true;
}

/** 本插件是否真正生效：开关开着 **且** 所选服务商配置齐全 */
function owCVActive(): bool
{
    $c = owCVConf();
    return (string)($c['enabled'] ?? '0') === '1' && owCVReady((string)$c['provider'], $c);
}

/**
 * 带 CA 校验的表单/JSON POST。
 * 优先 curl；没有 curl 扩展时回落 file_get_contents + stream context（同样校验证书）。
 * 返回 ['ok','status','body','error']，ok 只看 HTTP 2xx —— 业务是否通过由调用方读 body 判定。
 */
function owCVPost(string $url, array $payload, bool $asJson = false): array
{
    $timeout = 8;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if (!$ch) return ['ok' => false, 'status' => 0, 'body' => '', 'error' => '无法初始化请求'];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [$asJson ? 'Content-Type: application/json' : 'Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS     => $asJson
                ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : http_build_query($payload, '', '&'),
            // 证书必须验：这是把 token 交给「谁」的问题，中间人可以谎报「验证通过」
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'owlsgo-captcha/1.0',
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $opts[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        // 复用升级模块已经趟出来的 CA 发现结果（Windows 下 PHP 默认没有系统 CA 库）
        if (class_exists('Upgrade')) {
            $ca = Upgrade::caBundle();
            if (is_string($ca) && $ca !== '') {
                $opts[CURLOPT_CAINFO] = $ca;
                $opts[CURLOPT_CAPATH] = dirname($ca);
            }
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $err !== '' ? $err : '请求失败'];
        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string)$body, 'error' => ''];
    }

    // 无 curl 的回落路径
    $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
    if (class_exists('Upgrade')) {
        $ca = Upgrade::caBundle();
        if (is_string($ca) && $ca !== '') $ssl['cafile'] = $ca;
    }
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => ($asJson ? 'Content-Type: application/json' : 'Content-Type: application/x-www-form-urlencoded') . "\r\n",
        'content'       => $asJson
            ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : http_build_query($payload, '', '&'),
        'timeout'       => $timeout,
        'follow_location' => 1,
        'max_redirects' => 3,
        'ignore_errors' => true,
        'user_agent'    => 'owlsgo-captcha/1.0',
    ], 'ssl' => $ssl]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return ['ok' => false, 'status' => 0, 'body' => '', 'error' => '请求失败'];
    $status = 0;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int)$m[1];
    }
    return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => $body, 'error' => ''];
}

/** 把服务商返回的 error-codes 收敛成可展示、不含杂字符的短码（绝不回显原文，防注入与泄露） */
function owCVCodes(array $data, array $resp): string
{
    $codes = [];
    foreach (['error-codes', 'errors'] as $key) {
        if (!empty($data[$key]) && is_array($data[$key])) {
            foreach ($data[$key] as $c) {
                $c = is_array($c) ? (string)($c['code'] ?? '') : (string)$c;
                $c = preg_replace('/[^a-zA-Z0-9._-]/', '', $c);
                if ($c !== '') $codes[] = $c;
            }
        }
    }
    if (!$codes && isset($data['message'])) {
        $m = preg_replace('/[^a-zA-Z0-9._-]/', '', (string)$data['message']);
        if ($m !== '') $codes[] = $m;
    }
    if (!$codes) {
        $st = (int)($resp['status'] ?? 0);
        $codes[] = !($resp['ok'] ?? false) ? ($st > 0 ? 'http-' . $st : 'request-failed') : 'invalid-response';
    }
    return implode(',', array_slice(array_unique($codes), 0, 4));
}

/**
 * 服务端二次校验总入口。$post 是表单原始字段 ——
 * 四家的 SDK 都会**自己往表单里注入隐藏字段**（Turnstile 的 cf-turnstile-response、
 * Cap 的 cap-token、VAPTCHA 的 vaptcha_token/vaptcha_server、Geetest 由本插件补的四个），
 * 所以这里直接读原生字段，不再套一层自定义 JSON：
 * Cap 的 token 有可能落在 shadow DOM 里，JS 抄不动，抄不到就等于永远验证不过。
 * 返回 [bool ok, string msg]。
 */
function owCVVerify(array $post): array
{
    $c = owCVConf();
    $provider = (string)($c['provider'] ?? 'turnstile');
    if (!owCVReady($provider, $c)) return [false, '人机验证未正确配置，请联系管理员'];
    $ip = class_exists('Sec') ? Sec::ip() : '';
    $str = static fn(string $k): string => trim((string)($post[$k] ?? ''));

    if ($provider === 'turnstile') {
        $token = $str('cf-turnstile-response');
        if ($token === '') return [false, '请先完成人机验证'];
        $resp = owCVPost('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => (string)$c['turnstile_secret_key'],
            'response' => $token,
            'remoteip' => $ip,
        ]);
        $data = json_decode((string)$resp['body'], true) ?: [];
        if (!empty($data['success'])) return [true, ''];
        return [false, '人机验证未通过（' . owCVCodes($data, $resp) . '），请重试'];
    }

    if ($provider === 'cap') {
        $token = $str('cap-token');
        if ($token === '') return [false, '请先完成人机验证'];
        // Cap Standalone：siteverify 只收 JSON，端点是 <实例>/<site_key>/siteverify
        $base = rtrim(trim((string)$c['cap_instance_url']), '/') . '/' . trim((string)$c['cap_site_key']) . '/';
        $resp = owCVPost($base . 'siteverify', [
            'secret' => (string)$c['cap_secret_key'],
            'response' => $token,
        ], true);
        $data = json_decode((string)$resp['body'], true) ?: [];
        if (!empty($data['success'])) return [true, ''];
        return [false, '人机验证未通过（' . owCVCodes($data, $resp) . '），请重试'];
    }

    if ($provider === 'vaptcha') {
        $token = $str('vaptcha_token');
        if ($token === '') return [false, '请先完成人机验证'];
        $server = $str('vaptcha_server');
        // 白名单：只允许官方域名的 https 端点。客户端传什么域名都不影响最终打到哪 ——
        // 不在名单内就退回默认网关（参考实现 bearsimple 直接 curl 客户端给的 URL，那是漏洞：
        // 攻击者起一个恒返回 success 的端点就等于自己签发通过结果）。
        if ($server === '' || !preg_match('#^https://#i', $server)
            || !preg_match('#^(?:[a-z0-9-]+\.)*vaptcha\.com$#i', (string)parse_url($server, PHP_URL_HOST))) {
            $server = 'https://gate.vaptcha.com/v2/validate';
        }
        $resp = owCVPost($server, [
            'id' => (string)$c['vaptcha_vid'],
            'scene' => '1',
            'secretkey' => (string)$c['vaptcha_key'],
            'token' => $token,
            'ip' => $ip,
            'useragent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ], true);
        $data = json_decode((string)$resp['body'], true) ?: [];
        if (($resp['ok'] ?? false) && (int)($data['success'] ?? 0) === 1) return [true, ''];
        return [false, '人机验证未通过（' . owCVCodes($data, $resp) . '），请重试'];
    }

    // geetest v4：四个字段一起回传，sign_token = HMAC-SHA256(lot_number, captcha_key)
    $lot = $str('lot_number');
    if ($lot === '' || $str('captcha_output') === '' || $str('pass_token') === '' || $str('gen_time') === '') {
        return [false, '请先完成人机验证'];
    }
    $resp = owCVPost('https://gcaptcha4.geetest.com/validate?captcha_id=' . rawurlencode((string)$c['geetest_captcha_id']), [
        'lot_number' => $lot,
        'captcha_output' => $str('captcha_output'),
        'pass_token' => $str('pass_token'),
        'gen_time' => $str('gen_time'),
        'sign_token' => hash_hmac('sha256', $lot, (string)$c['geetest_captcha_key']),
    ]);
    $data = json_decode((string)$resp['body'], true) ?: [];
    // 注意 result 与 status 是两个维度：status=success 只代表接口调通，
    // 真正的判定在 result（'success' / 'fail' + reason）。
    if (($data['status'] ?? '') === 'success' && ($data['result'] ?? '') === 'success') return [true, ''];
    $reason = preg_replace('/[^a-zA-Z0-9._-]/', '', (string)($data['reason'] ?? ''));
    return [false, '人机验证未通过' . ($reason !== '' ? '（' . $reason . '）' : '，请重试')];
}

/** 前端组件 HTML。核心在登录/注册等表单里 collect('captcha.form') 拿这一段。 */
Plugin::on('captcha.form', function (array $ctx = []): string {
    if (!owCVActive()) return '';
    $c = owCVConf();
    $provider = (string)$c['provider'];
    $scene = preg_replace('/[^a-z_-]/', '', (string)($ctx['scene'] ?? 'login'));
    $dom = 'owCvBox-' . $scene;
    if ($provider === 'turnstile') {
        $inner = '<div class="cf-turnstile" data-sitekey="' . htmlspecialchars((string)$c['turnstile_site_key'], ENT_QUOTES) . '" data-theme="auto" data-size="flexible"></div>';
    } elseif ($provider === 'cap') {
        $endpoint = rtrim(trim((string)$c['cap_instance_url']), '/') . '/' . trim((string)$c['cap_site_key']) . '/';
        $inner = '<cap-widget data-cap-api-endpoint="' . htmlspecialchars($endpoint, ENT_QUOTES) . '"'
            . ' data-cap-i18n-initial-state="进行人机验证" data-cap-i18n-verifying-label="正在验证…"'
            . ' data-cap-i18n-solved-label="验证成功" data-cap-i18n-error-label="验证出错"></cap-widget>';
    } else {
        // vaptcha / geetest 由 widget.js 自己造容器
        $inner = '';
    }
    return '<div class="ow-cv" data-owcv-provider="' . htmlspecialchars($provider, ENT_QUOTES) . '"'
        . ' data-owcv-scene="' . htmlspecialchars($scene, ENT_QUOTES) . '"'
        . ' data-owcv-vid="' . htmlspecialchars((string)$c['vaptcha_vid'], ENT_QUOTES) . '"'
        . ' data-owcv-gid="' . htmlspecialchars((string)$c['geetest_captcha_id'], ENT_QUOTES) . '"'
        . ' data-owcv-cap-script="' . htmlspecialchars((string)$c['cap_script_url'], ENT_QUOTES) . '">'
        . '<div class="ow-cv-box" id="' . $dom . '">' . $inner . '</div>'
        . '<p class="ow-cv-tip" role="status" aria-live="polite"></p></div>';
});

Plugin::on('captcha.takeover', function (): string { return owCVActive() ? '1' : ''; });

Plugin::on('captcha.enforce', function (&$ok, &$msg, array $ctx = []): void {
    if (!owCVActive()) return;                       // 未启用：完全不参与判定，核心走自己的 SVG 码
    [$ok, $msg] = owCVVerify(is_array($ctx['post'] ?? null) ? $ctx['post'] : []);
});

/* ---------- 后台配置页 ---------- */
Plugin::adminPage('captcha-verify', '人机验证', function () {
    $c = owCVConf();
    $defs = owCVProviders();
    $h = '<h2>人机验证</h2>'
        . '<p class="ow-admin-desc">接入 Cloudflare Turnstile / Cap / VAPTCHA / 极验 Geetest v4。'
        . '开启后取代系统自带的数字图形验证码，并在服务端做二次校验。</p>'
        . '<div class="ow-card">'
        . '<div class="ow-form-row">'
        . '<div class="ow-form-item" style="min-width:170px"><label>服务端二次验证</label>'
        . '<select class="ow-input" id="owCvEnabled">'
        . '<option value="0"' . ((string)($c['enabled'] ?? '0') !== '1' ? ' selected' : '') . '>关闭（用系统图形码）</option>'
        . '<option value="1"' . ((string)($c['enabled'] ?? '0') === '1' ? ' selected' : '') . '>开启（取代图形码）</option></select>'
        . '<p style="font-size:12px;color:var(--ow-text-sub)">开启后每次登录都必须先通过人机验证；'
        . '关掉则回到「失败若干次才要图形码」的原逻辑。</p></div>'
        . '<div class="ow-form-item" style="min-width:170px"><label>验证服务商</label><select class="ow-input" id="owCvProvider">';
    foreach ($defs as $k => $p) {
        $h .= '<option value="' . $k . '"' . ((string)($c['provider'] ?? 'turnstile') === $k ? ' selected' : '') . '>' . $p['name'] . '</option>';
    }
    $h .= '</select><p style="font-size:12px;color:var(--ow-text-sub)">只填所选服务商的那一组；'
        . '其余组的值留在本地不会下发到页面。</p></div></div>';

    $cur = (string)($c['provider'] ?? 'turnstile');
    foreach ($defs as $key => $p) {
        // 只回显**当前所选**服务商的密钥值。四组全渲染等于把所有服务商的 secret key
        // 一起塞进页面源码 —— 任何一个拿到后台会话的人（或浏览器插件、XSS）都能一次读走。
        $reveal = ($key === $cur);
        $h .= '<div class="ow-form-row ow-cv-prov" data-owcv-prov="' . $key . '"><div class="ow-form-item" style="flex:1 1 100%">'
            . '<label>' . $p['name'] . '</label>'
            . '<p style="font-size:12px;color:var(--ow-text-sub)">' . $p['note'] . '</p></div>';
        foreach ($p['fields'] as $fk => $fl) {
            $isSecret = strpos($fk, 'secret') !== false || strpos($fk, '_key') !== false;
            $val = $reveal ? (string)($c[$fk] ?? '') : '';
            $h .= '<div class="ow-form-item" style="min-width:210px"><label>' . $fl . '</label>'
                . '<input class="ow-input" id="owCv_' . $fk . '" data-owcv-field="' . $fk . '"'
                . ($isSecret ? ' type="password" autocomplete="off"' : ' type="text"')
                . ' value="' . htmlspecialchars($val, ENT_QUOTES) . '"'
                . ($reveal ? '' : ' placeholder="切换到此服务商需重新填写"') . '></div>';
        }
        $h .= '</div>';
    }
    $h .= '<button class="ow-btn ow-btn-primary" onclick="OwCV.save()">保存设置</button>'
        . '<button class="ow-btn ow-btn-ghost" onclick="OwCV.test()" style="margin-left:8px">测试校验连通性</button>'
        . '<p style="font-size:12px;color:var(--ow-text-sub);margin-top:8px">「测试」只验证配置完整性与能否连上服务商接口，'
        . '不会生成有效 token，因此预期返回「未通过」类错误码 —— 能拿到错误码就说明链路是通的。</p>'
        . '</div>';
    return $h;
});

Plugin::route('plugin_captcha_verify_save', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $post = $ctx['post'];
    $provider = (string)($post['provider'] ?? 'turnstile');
    if (!isset(owCVProviders()[$provider])) Api::json(['ok' => false, 'msg' => '未知的验证服务商']);
    $kv = ['enabled' => ($post['enabled'] ?? '0') === '1' ? '1' : '0', 'provider' => $provider];
    foreach (owCVProviders()[$provider]['fields'] as $fk => $fl) {
        $kv[$fk] = mb_substr(trim((string)($post[$fk] ?? '')), 0, 300);
    }
    // 开关开着但配置不全 → 拒绝保存。否则线上会变成「谁都无法登录」的静默故障
    if ($kv['enabled'] === '1') {
        $merged = owCVConf();
        foreach ($kv as $k => $v) $merged[$k] = $v;
        if (!owCVReady($provider, $merged)) Api::json(['ok' => false, 'msg' => '请填写该服务商的全部必填项']);
    }
    // upsert 而非 INSERT OR REPLACE：后者是 SQLite 方言，MySQL / PG 都不认
    foreach ($kv as $k => $v) DB::upsert('plugin_captcha_verify_config', ['k' => $k, 'v' => $v], ['k']);
    Api::json(['ok' => true, 'msg' => '已保存']);
});
Plugin::sensitive('plugin_captcha_verify_save');

Plugin::route('plugin_captcha_verify_test', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    if (!owCVActive()) Api::json(['ok' => false, 'msg' => '未开启或配置不完整，无法测试']);
    // 故意用一个假 token 打真实接口：拿到结构化拒绝 = 网络与密钥通路正常
    $provider = (string)owCVConf()['provider'];
    $fake = [
        'turnstile' => ['cf-turnstile-response' => 'owlsgo-connectivity-probe'],
        'cap'       => ['cap-token' => 'owlsgo-connectivity-probe'],
        'vaptcha'   => ['vaptcha_token' => 'owlsgo-connectivity-probe'],
        'geetest'   => ['lot_number' => 'owlsgo-probe', 'captcha_output' => 'x', 'pass_token' => 'x', 'gen_time' => '0'],
    ][$provider] ?? [];
    [$ok, $msg] = owCVVerify($fake);
    Api::json(['ok' => $ok, 'msg' => $ok ? '接口返回通过（异常，请检查服务商设置）' : ('链路正常，接口回应：' . $msg)]);
});

/**
 * 本插件的 CSS 必须自己注入 <link>。
 * ⚠️ v1.3.54~v1.3.62 期间只调用了 Plugin::asset('css', …) 登记，却没人发 link ——
 * 核心不合并插件样式表，于是 style.css 里的规则（容器最小高度、Cap 组件宽度）
 * **一条都没生效**，表现成"改了 CSS 页面毫无变化"。与 email-verify 同一做法。
 */
Plugin::on('page.head', function (): void {
    echo '<link rel="stylesheet" href="?action=assets&type=css&plugin=captcha-verify&file=style.css&v=' . OWLSGO_VERSION . '">';
});

Plugin::asset('js', 'captcha-verify/admin.js');
Plugin::asset('js', 'captcha-verify/widget.js');
Plugin::asset('css', 'captcha-verify/style.css');
