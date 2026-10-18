<?php
/**
 * 通行密钥（passkey）v1.0.0
 *
 * 功能：
 *   - 个人设置 →「账号」页添加 / 重命名 / 删除通行密钥（WebAuthn 凭据）
 *   - 登录页「使用通行密钥登录」：设备指纹 / 面容 / PIN 直接登录，不再输密码
 *   - 支持免用户名（常驻凭据）与「填了账号再验证」两种取凭据方式
 *   - 后台：总开关 + 启用统计 + 最近添加的凭据列表
 *
 * 实现要点（不修改主程序）：
 *   - 登录态：插件路由内直接写 $_SESSION['uid']（与 twofa 插件同一做法）。
 *     index.php 在非敏感 action 上会 session_write_close() 释放会话锁，
 *     所以每个要读写会话的路由都得先 owPkSession() 重新打开。
 *   - 签名：登录页的 OwApi.key 由 OwAuth.init 用匿名密钥下发，插件用 OwApi.post 即可过签名门禁。
 *   - CSS：主程序只自动引入合并 JS，样式必须自己挂 page.head 输出 <link>。
 *
 * 零依赖：CBOR 解码、COSE → PEM、签名校验全部手写（openssl 为 PHP 内置扩展）。
 * 只支持 ES256 / ES384 / ES512 与 RS256 / RS384 / RS512 —— 覆盖 Windows Hello、
 * Touch ID / iCloud 钥匙串、Google 密码管理器、Android、YubiKey 等主流实现。
 * （EdDSA 因 openssl_verify 无法指定算法而暂不支持，注册时会被拒绝并给出提示。）
 */
if (!defined('OWLSGO_VERSION')) exit;

/* ==========================================================================
   一、编码 / DER / CBOR
   ========================================================================== */

/** base64url 编码（无填充） */
function owPkB64u(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/** base64url 解码，非法输入返回空串 */
function owPkB64uDecode(string $s): string
{
    $s = strtr(trim($s), '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad > 0) $s .= str_repeat('=', 4 - $pad);
    $r = base64_decode($s, true);
    return $r === false ? '' : $r;
}

/** DER 长度字段（短/长格式） */
function owPkDerLen(int $len): string
{
    if ($len < 0x80) return chr($len);
    $b = '';
    while ($len > 0) { $b = chr($len & 0xFF) . $b; $len >>= 8; }
    return chr(0x80 | strlen($b)) . $b;
}

/** DER TLV */
function owPkDerTlv(int $tag, string $body): string
{
    return chr($tag) . owPkDerLen(strlen($body)) . $body;
}

/** DER INTEGER（正数，必要时补 0x00 防被当成负数） */
function owPkDerInt(string $bytes): string
{
    $bytes = ltrim($bytes, "\x00");
    if ($bytes === '') $bytes = "\x00";
    if (ord($bytes[0]) > 0x7F) $bytes = "\x00" . $bytes;
    return owPkDerTlv(0x02, $bytes);
}

/**
 * COSE_Key → PEM 公钥。
 * EC2（kty=2）：只认 P-256（crv=1）；RSA（kty=3）：任意长度。
 * @param array $cose CBOR 解出来的 COSE_Key map
 * @return string|null 失败返回 null（算法不支持 / 字段不合法）
 */
function owPkCoseToPem(array $cose): ?string
{
    $kty = (int)($cose[1] ?? 0);
    if ($kty === 2) {
        $crv = (int)($cose[-1] ?? 0);
        $x = (string)($cose[-2] ?? '');
        $y = (string)($cose[-3] ?? '');
        if ($crv !== 1 || strlen($x) !== 32 || strlen($y) !== 32) return null;
        // 1.2.840.10045.2.1 (ecPublicKey) + 1.2.840.10045.3.1.7 (prime256v1)
        $alg = "\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01" . "\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07";
        // BIT STRING：首字节 0x00 = 无未使用位，随后是未压缩点 0x04 || X || Y
        $der = owPkDerTlv(0x30, owPkDerTlv(0x30, $alg) . owPkDerTlv(0x03, "\x00\x04" . $x . $y));
    } elseif ($kty === 3) {
        $n = (string)($cose[-1] ?? '');
        $e = (string)($cose[-2] ?? '');
        if ($n === '' || $e === '') return null;
        // 1.2.840.113549.1.1.1 (rsaEncryption) + NULL
        $alg = "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00";
        $rsa = owPkDerTlv(0x30, owPkDerInt($n) . owPkDerInt($e));
        $der = owPkDerTlv(0x30, owPkDerTlv(0x30, $alg) . owPkDerTlv(0x03, "\x00" . $rsa));
    } else {
        return null;
    }
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** 按 COSE alg 校验签名（ES* 的签名是 DER 编码的 ECDSA-Sig-Value，openssl 直接吃） */
function owPkVerifySig(int $alg, string $pem, string $data, string $sig): bool
{
    $algs = [
        -7   => OPENSSL_ALGO_SHA256,   // ES256
        -35  => OPENSSL_ALGO_SHA384,   // ES384
        -36  => OPENSSL_ALGO_SHA512,   // ES512
        -257 => OPENSSL_ALGO_SHA256,   // RS256
        -258 => OPENSSL_ALGO_SHA384,   // RS384
        -259 => OPENSSL_ALGO_SHA512,   // RS512
    ];
    if (!isset($algs[$alg])) return false;
    return @openssl_verify($data, $sig, $pem, $algs[$alg]) === 1;
}

/**
 * 极简 CBOR 解码（只覆盖 WebAuthn 用到的部分：uint / negint / bytes / text / array / map / tag / bool）。
 *
 * ⚠️ 用引用传 offset 递归：WebAuthn 的 authData 里 COSE 公钥**长度未知**，必须从当前位置连续解码。
 * ⚠️ 出错与「值为 null」都用 null 表示 —— COSE_Key / attestationObject 里不会出现 null 值，
 *    所以调用方只需判一次 is_array / === null，不必区分两种语义。
 *
 * @param string $bin 原始字节
 * @param int    $off 当前偏移（按引用推进）
 * @param int    $depth 递归深度（防御畸形数据打爆栈）
 * @return mixed
 */
function owPkCbor(string $bin, int &$off = 0, int $depth = 0)
{
    if ($depth > 8 || $off < 0 || $off >= strlen($bin)) return null;
    $b = ord($bin[$off++]);
    $major = $b >> 5;
    $info = $b & 0x1F;

    if ($info < 24) {
        $len = $info;
    } elseif ($info === 24) {
        if ($off + 1 > strlen($bin)) return null;
        $len = ord($bin[$off]);
        $off += 1;
    } elseif ($info === 25) {
        if ($off + 2 > strlen($bin)) return null;
        $len = unpack('n', substr($bin, $off, 2))[1];
        $off += 2;
    } elseif ($info === 26) {
        if ($off + 4 > strlen($bin)) return null;
        $len = unpack('N', substr($bin, $off, 4))[1];
        $off += 4;
    } elseif ($info === 27) {
        if ($off + 8 > strlen($bin)) return null;
        $hi = unpack('N', substr($bin, $off, 4))[1];
        $lo = unpack('N', substr($bin, $off + 4, 4))[1];
        $len = ($hi === 0) ? $lo : PHP_INT_MAX;
        $off += 8;
    } else {
        return null;   // 28-31：保留 / 不定长（WebAuthn 不用）
    }

    switch ($major) {
        case 0:
            return $len;
        case 1:
            return -1 - $len;
        case 2:
        case 3:
            if ($len < 0 || $off + $len > strlen($bin)) return null;
            $s = substr($bin, $off, $len);
            $off += $len;
            return $s;
        case 4:
            if ($len > 64) return null;
            $arr = [];
            for ($i = 0; $i < $len; $i++) {
                $v = owPkCbor($bin, $off, $depth + 1);
                if ($v === null) return null;
                $arr[] = $v;
            }
            return $arr;
        case 5:
            if ($len > 64) return null;
            $map = [];
            for ($i = 0; $i < $len; $i++) {
                $k = owPkCbor($bin, $off, $depth + 1);
                $v = owPkCbor($bin, $off, $depth + 1);
                if ($k === null || $v === null) return null;
                if (is_int($k) || is_string($k)) $map[$k] = $v;
            }
            return $map;
        case 6:   // tag：跳过标签本身，按它的内容继续解
            return owPkCbor($bin, $off, $depth + 1);
        case 7:
            if ($info === 20) return false;
            if ($info === 21) return true;
            return null;   // null / undefined / 浮点：WebAuthn 不用
    }
    return null;
}

/** AAGUID 字节 → 带连字符的 UUID 文本 */
function owPkUuid(string $bytes): string
{
    if (strlen($bytes) !== 16) return '';
    $h = bin2hex($bytes);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4)
         . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}

/**
 * 解析 authenticatorData。
 * 结构：rpIdHash(32) | flags(1) | signCount(4) | [AT: aaguid(16) + credIdLen(2) + credId + COSE_Key] | [ED: 扩展]
 * @return array|null 失败返回 null
 */
function owPkParseAuthData(string $ad): ?array
{
    if (strlen($ad) < 37) return null;
    $out = [
        'rp_hash' => substr($ad, 0, 32),
        'flags'   => ord($ad[32]),
        'count'   => (int)(unpack('N', substr($ad, 33, 4))[1]),
    ];
    if ($out['flags'] & 0x40) {          // AT：带证明数据（注册时才有）
        $off = 37;
        if (strlen($ad) < $off + 18) return null;
        $out['aaguid'] = substr($ad, $off, 16);
        $credLen = (int)(unpack('n', substr($ad, $off + 16, 2))[1]);
        $off += 18;
        if ($credLen <= 0 || strlen($ad) < $off + $credLen) return null;
        $out['cred_id'] = substr($ad, $off, $credLen);
        $off += $credLen;
        $cose = owPkCbor($ad, $off);
        if (!is_array($cose)) return null;
        $out['cose'] = $cose;
    }
    return $out;
}

/* ==========================================================================
   二、会话 / 环境
   ========================================================================== */

/** 重新打开会话（index.php 已为插件路由 session_write_close 过） */
function owPkSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
}

/** 当前请求的 RP ID（域名，不含端口） */
function owPkRpId(): string
{
    $host = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
    if ($host === '') $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') $host = trim((string)($_SERVER['SERVER_NAME'] ?? ''));
    $host = trim(explode(',', $host)[0]);
    if (preg_match('/^\[(.+)\](?::\d+)?$/', $host, $m)) {
        $host = $m[1];                                  // IPv6
    } elseif (substr_count($host, ':') === 1) {
        $host = explode(':', $host)[0];                 // 域名:端口
    }
    return strtolower($host);
}

/** 当前环境能否用通行密钥（WebAuthn 要求安全上下文：https，或 localhost 例外） */
function owPkSecure(): bool
{
    if (Sec::isHttps()) return true;
    return in_array(owPkRpId(), ['localhost', '127.0.0.1', '::1'], true);
}

/** 站点总开关 */
function owPkEnabled(): bool
{
    return DB::setting('plugin_passkey_login', '1') === '1';
}

/**
 * 校验 clientDataJSON。
 * 只比对 host（而不是整条 origin）：线上常见「配置了固定站点地址 + 反代改端口」，
 * 严格比 scheme+port 会在反代环境下全部失败；host 相等 + 强制 https（localhost 例外）
 * 已足以阻止跨站冒用。
 */
function owPkCheckClientData(string $json, string $expectType, string $expectChallenge): ?array
{
    $cd = json_decode($json, true);
    if (!is_array($cd)) return null;
    if (($cd['type'] ?? '') !== $expectType) return null;
    if (!hash_equals($expectChallenge, (string)($cd['challenge'] ?? ''))) return null;
    $p = parse_url((string)($cd['origin'] ?? ''));
    if (!$p || empty($p['host'])) return null;
    $scheme = strtolower((string)($p['scheme'] ?? ''));
    $host = strtolower((string)$p['host']);
    if ($host !== owPkRpId()) return null;
    if ($scheme !== 'https' && !($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '::1'], true))) return null;
    return $cd;
}

/** 下发一条挑战（写进会话），返回 base64url 文本 */
function owPkChallengeSet(string $scene, array $extra = []): string
{
    $raw = random_bytes(32);
    $_SESSION['owpk_chal'] = ['c' => owPkB64u($raw), 'scene' => $scene, 'at' => time()] + $extra;
    return owPkB64u($raw);
}

/** 取出并立即作废挑战（一次性，失败也不留） */
function owPkChallengeTake(string $scene, int $ttl = 300): ?array
{
    $s = $_SESSION['owpk_chal'] ?? null;
    unset($_SESSION['owpk_chal']);
    if (!is_array($s) || (string)($s['scene'] ?? '') !== $scene) return null;
    if (time() - (int)($s['at'] ?? 0) > $ttl) return null;
    return $s;
}

/** 已登录用户守卫 */
function owPkActorUser(array $ctx): array
{
    $a = $ctx['actor'] ?? [];
    if (($a['kind'] ?? '') !== 'user' || (int)($a['id'] ?? 0) <= 0) {
        Api::json(['ok' => false, 'msg' => '请先登录'], 403);
    }
    return $a;
}

/**
 * 「添加通行密钥」前的密码尝试计数（挂在会话上，10 分钟窗口）。
 *
 * 为什么单独计数而不走 Sec::loginFail：那是登录失败预算，扣在这里等于
 * 「在设置里试错密码会把自己锁在登录页外」，代价不对等。
 *
 * @param bool $inc true = 本次失败，计数 +1
 * @return int 当前窗口内的失败次数
 */
function owPkPwFails(bool $inc = false): int
{
    $s = $_SESSION['owpk_pwf'] ?? null;
    $now = time();
    if (!is_array($s) || $now - (int)($s['t'] ?? 0) > 600) $s = ['n' => 0, 't' => $now];
    if ($inc) $s['n'] = (int)$s['n'] + 1;
    $_SESSION['owpk_pwf'] = $s;
    return (int)$s['n'];
}

/* ==========================================================================
   三、数据表
   ========================================================================== */

function owPkEnsureTable(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $int = DB::driver() === 'mysql' ? 'INT' : 'INTEGER';
    // cred_id / pubkey 可能很长，走 DB::textCol()（MySQL 的 TEXT 不能带默认值）
    DB::run("CREATE TABLE IF NOT EXISTS plugin_passkey (
        id_hash VARCHAR(64) NOT NULL PRIMARY KEY,
        user_id $int NOT NULL DEFAULT 0,
        " . DB::textCol('cred_id') . ",
        " . DB::textCol('pubkey') . ",
        alg $int NOT NULL DEFAULT -7,
        sign_count $int NOT NULL DEFAULT 0,
        aaguid VARCHAR(36) NOT NULL DEFAULT '',
        name VARCHAR(191) NOT NULL DEFAULT '',
        transports VARCHAR(120) NOT NULL DEFAULT '',
        cross_platform $int NOT NULL DEFAULT 0,
        created_at $int NOT NULL DEFAULT 0,
        last_used_at $int NOT NULL DEFAULT 0,
        last_ip VARCHAR(64) NOT NULL DEFAULT ''
    )");
    DB::createIndex('idx_passkey_user', 'plugin_passkey', 'user_id');
}
owPkEnsureTable();

/* ==========================================================================
   四、登录 / 注册的实际动作
   ========================================================================== */

/**
 * 完成登录（passkey 校验通过后调用）。
 * 与 Auth::login 的成功分支保持一致：清失败计数 → 换会话 id → 写 uid → 回写最后登录 → 触发钩子。
 *
 * 钩子的 $method 传 passkey：twofa 只拦 password，所以启用了两步验证的账号
 * 用通行密钥登录不会再要一次 TOTP —— 这是**刻意的**：通行密钥本身已包含
 * 设备解锁（指纹 / 面容 / PIN）这一因子，与「密码 + 动态码」等价甚至更强。
 * 若站点要求叠加，应由 twofa 侧监听 method=passkey 自行扩展。
 */
function owPkFinishLogin(int $uid): array
{
    $user = DB::one('SELECT * FROM users WHERE id=?', [$uid]);
    if (!$user) return [false, '账号不存在', null];
    if ((int)$user['status'] !== 1) return [false, '账号已被禁用，请联系管理员', null];

    Sec::loginOk('passkey|' . Sec::ip());
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$user['id'];
    DB::run('UPDATE users SET last_login=?, last_login_ip=? WHERE id=?', [time(), Sec::ip(), $user['id']]);
    Sec::log('login', (string)$user['nickname'], ['method' => 'passkey']);
    Plugin::fire('login.after_verify', [$user, 'passkey', ['ip' => Sec::ip()]]);
    return [true, '登录成功', $user];
}

/* ==========================================================================
   五、路由
   ========================================================================== */

/** 登录页 / 前台：当前站点能不能用通行密钥（登录页据此决定要不要出按钮） */
Plugin::route('plugin_passkey_config', function (array $ctx): void {
    Api::json(['ok' => true, 'enabled' => owPkEnabled(), 'secure' => owPkSecure()]);
});

/* ---------- 5.1 登录：取挑战 ---------- */

Plugin::route('plugin_passkey_login_options', function (array $ctx): void {
    owPkSession();
    if (!owPkEnabled()) Api::json(['ok' => false, 'msg' => '站点未开启通行密钥登录']);
    if (!owPkSecure()) Api::json(['ok' => false, 'msg' => '当前环境不支持通行密钥（需要 HTTPS 访问）']);
    if (Sec::loginLocked('passkey|' . Sec::ip())) {
        Api::json(['ok' => false, 'msg' => '尝试次数过多，请稍后再试']);
    }

    // 可选：带了账号就只允许该账号的凭据（不支持常驻凭据的安全密钥走这条路）
    $allow = [];
    $identity = trim((string)($ctx['post']['identity'] ?? ''));
    if ($identity !== '') {
        $user = null;
        if (preg_match('/^\d{1,19}$/', $identity)) {
            $user = DB::one('SELECT id FROM users WHERE id=?', [(int)$identity]);
        }
        if (!$user) $user = DB::one('SELECT id FROM users WHERE email=?', [Auth::normEmail($identity)]);
        // 认不出账号时**不加任何提示**，直接给空列表：否则就是一个账号枚举接口
        if ($user) {
            $rows = DB::all('SELECT cred_id, transports FROM plugin_passkey WHERE user_id=?', [(int)$user['id']]);
            foreach ($rows as $r) {
                $t = array_values(array_filter(array_map('trim', explode(',', (string)$r['transports']))));
                $item = ['type' => 'public-key', 'id' => (string)$r['cred_id']];
                if ($t) $item['transports'] = $t;
                $allow[] = $item;
            }
        }
    }

    $challenge = owPkChallengeSet('login');
    Api::json(['ok' => true, 'publicKey' => [
        'challenge'        => $challenge,
        'rpId'             => owPkRpId(),
        'timeout'          => 60000,
        'userVerification' => 'preferred',
        'allowCredentials' => $allow,
    ]]);
});

/* ---------- 5.2 登录：校验断言 ---------- */

Plugin::route('plugin_passkey_login_finish', function (array $ctx): void {
    owPkSession();
    if (!owPkEnabled()) Api::json(['ok' => false, 'msg' => '站点未开启通行密钥登录']);
    $ipKey = 'passkey|' . Sec::ip();
    if (Sec::loginLocked($ipKey)) Api::json(['ok' => false, 'msg' => '尝试次数过多，请 15 分钟后再试']);

    $chal = owPkChallengeTake('login');
    if (!$chal) Api::json(['ok' => false, 'msg' => '验证已过期，请重新点击「使用通行密钥登录」']);

    $post  = $ctx['post'];
    $rawId = owPkB64uDecode((string)($post['rawId'] ?? ($post['id'] ?? '')));
    if ($rawId === '') Api::json(['ok' => false, 'msg' => '凭据格式不正确']);
    $row = DB::one('SELECT * FROM plugin_passkey WHERE id_hash=?', [hash('sha256', $rawId)]);
    if (!$row) {
        Sec::loginFail($ipKey);
        Sec::log('passkey_login_fail', '', ['reason' => 'unknown_credential', 'ip' => Sec::ip()]);
        Api::json(['ok' => false, 'msg' => '这台设备上没有该账号的通行密钥，请改用密码登录']);
    }

    $clientJson = owPkB64uDecode((string)($post['clientDataJSON'] ?? ''));
    $authData   = owPkB64uDecode((string)($post['authenticatorData'] ?? ''));
    $sig        = owPkB64uDecode((string)($post['signature'] ?? ''));
    $cd = owPkCheckClientData($clientJson, 'webauthn.get', (string)$chal['c']);
    if (!$cd) {
        Sec::loginFail($ipKey);
        Sec::log('passkey_login_fail', '', ['reason' => 'client_data', 'ip' => Sec::ip()]);
        Api::json(['ok' => false, 'msg' => '验证数据不合法，请重试']);
    }
    $ad = owPkParseAuthData($authData);
    if (!$ad || !hash_equals(hash('sha256', owPkRpId(), true), $ad['rp_hash'])) {
        Sec::loginFail($ipKey);
        Api::json(['ok' => false, 'msg' => '验证数据与当前站点不匹配']);
    }
    if (!($ad['flags'] & 0x01)) {                 // UP：用户在场
        Sec::loginFail($ipKey);
        Api::json(['ok' => false, 'msg' => '需要本人操作确认']);
    }
    $ok = owPkVerifySig((int)$row['alg'], (string)$row['pubkey'], $authData . hash('sha256', $clientJson, true), $sig);
    if (!$ok) {
        Sec::loginFail($ipKey);
        Sec::log('passkey_login_fail', '', ['reason' => 'bad_signature', 'uid' => (int)$row['user_id'], 'ip' => Sec::ip()]);
        Api::json(['ok' => false, 'msg' => '验证未通过，请重试或改用密码登录']);
    }
    // 计数器回退 = 可能是克隆的认证器（有些认证器恒为 0，那种跳过）
    $cnt = (int)$ad['count'];
    if ($cnt > 0 && (int)$row['sign_count'] > 0 && $cnt <= (int)$row['sign_count']) {
        Sec::log('passkey_login_fail', '', ['reason' => 'counter_regress', 'uid' => (int)$row['user_id']]);
        Api::json(['ok' => false, 'msg' => '该通行密钥的计数器异常，已拒绝本次登录，请联系管理员']);
    }

    DB::run('UPDATE plugin_passkey SET sign_count=?, last_used_at=?, last_ip=? WHERE id_hash=?',
        [max($cnt, (int)$row['sign_count']), time(), Sec::ip(), (string)$row['id_hash']]);

    [$ok2, $msg] = owPkFinishLogin((int)$row['user_id']);
    if (!$ok2) Api::json(['ok' => false, 'msg' => $msg]);
    Api::json(['ok' => true, 'msg' => '登录成功']);
});

/* ---------- 5.3 个人设置：列表 ---------- */

Plugin::route('plugin_passkey_status', function (array $ctx): void {
    $actor = owPkActorUser($ctx);
    $uid = (int)$actor['id'];
    $rows = DB::all('SELECT id_hash, name, aaguid, cross_platform, created_at, last_used_at
                     FROM plugin_passkey WHERE user_id=? ORDER BY created_at DESC', [$uid]);
    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id'         => (string)$r['id_hash'],
            'name'       => (string)$r['name'],
            'cross'      => (int)$r['cross_platform'] === 1,
            'created_at' => (int)$r['created_at'],
            'last_used'  => (int)$r['last_used_at'],
        ];
    }
    Api::json([
        'ok'      => true,
        'enabled' => owPkEnabled(),
        'secure'  => owPkSecure(),
        'count'   => count($items),
        'items'   => $items,
    ]);
});

/* ---------- 5.4 个人设置：注册（取挑战） ---------- */

Plugin::route('plugin_passkey_add_options', function (array $ctx): void {
    owPkSession();
    $actor = owPkActorUser($ctx);
    if (!owPkEnabled()) Api::json(['ok' => false, 'msg' => '站点未开启通行密钥登录']);
    if (!owPkSecure()) Api::json(['ok' => false, 'msg' => '当前环境不支持通行密钥（需要 HTTPS 访问）']);

    $uid = (int)$actor['id'];
    $u = DB::one('SELECT id, nickname, email FROM users WHERE id=?', [$uid]);
    if (!$u) Api::json(['ok' => false, 'msg' => '账号不存在'], 403);
    // 每人上限 10 把：多到这一步基本是误操作，列表也会失控
    if ((int)DB::val('SELECT COUNT(*) FROM plugin_passkey WHERE user_id=?', [$uid]) >= 10) {
        Api::json(['ok' => false, 'msg' => '最多添加 10 个通行密钥，请先删除不用的']);
    }

    $exclude = [];
    foreach (DB::all('SELECT cred_id, transports FROM plugin_passkey WHERE user_id=?', [$uid]) as $r) {
        $t = array_values(array_filter(array_map('trim', explode(',', (string)$r['transports']))));
        $item = ['type' => 'public-key', 'id' => (string)$r['cred_id']];
        if ($t) $item['transports'] = $t;
        $exclude[] = $item;
    }

    $challenge = owPkChallengeSet('register', ['uid' => $uid]);
    Api::json(['ok' => true, 'publicKey' => [
        'challenge'             => $challenge,
        'rp'                    => ['name' => (string)DB::setting('site_name', 'Owlsgo-Chat'), 'id' => owPkRpId()],
        // 稳定的 user handle（用户 ID）：凭据同步到新设备后，认证器里显示的仍是同一个「账号」
        'user'                  => [
            'id'          => owPkB64u(pack('N', $uid)),
            'name'        => (string)($u['email'] !== '' ? $u['email'] : ('u' . $uid)),
            'displayName' => (string)$u['nickname'],
        ],
        'pubKeyCredParams'      => [
            ['type' => 'public-key', 'alg' => -7],
            ['type' => 'public-key', 'alg' => -257],
        ],
        'timeout'               => 120000,
        'attestation'           => 'none',
        'excludeCredentials'    => $exclude,
        'authenticatorSelection' => [
            'residentKey'        => 'preferred',
            'requireResidentKey' => false,
            'userVerification'   => 'preferred',
        ],
    ]]);
});

/* ---------- 5.5 个人设置：密码确认（只做体验，真正的校验在 add_finish 再跑一遍） ---------- */

/**
 * 为什么要有这一步：WebAuthn 的 create() 必须在**用户手势内**调用（Safari 尤其严格），
 * 所以不能「先 XHR 验密码、回调里再 create()」。拆成两步后：
 *   ① 这里先验密码，密码错了直接在弹窗里提示，不会白白弹一次指纹；
 *   ② 用户点「开始设备验证」→ create() 落在真实点击手势里。
 * 真正的安全校验仍在 add_finish（本接口只是提前给反馈，拿它当门等于没有门）。
 */
Plugin::route('plugin_passkey_pw_check', function (array $ctx): void {
    owPkSession();
    $actor = owPkActorUser($ctx);
    $uid = (int)$actor['id'];
    if (owPkPwFails() >= 5) Api::json(['ok' => false, 'msg' => '密码错误次数过多，请 10 分钟后再试']);
    $u = DB::one('SELECT password FROM users WHERE id=?', [$uid]);
    if (!$u || !password_verify((string)($ctx['post']['password'] ?? ''), (string)$u['password'])) {
        owPkPwFails(true);
        Sec::log('passkey_add_fail', (string)$actor['nickname'], ['reason' => 'bad_password']);
        Api::json(['ok' => false, 'msg' => '账号密码不正确']);
    }
    unset($_SESSION['owpk_pwf']);
    Api::json(['ok' => true]);
});

/* ---------- 5.5 个人设置：注册（提交凭据） ---------- */

Plugin::route('plugin_passkey_add_finish', function (array $ctx): void {
    owPkSession();
    $actor = owPkActorUser($ctx);
    $uid = (int)$actor['id'];
    $post = $ctx['post'];

    // 1) 密码二次确认 —— 会话被劫持时也不能给别人种一把长期钥匙
    if (owPkPwFails() >= 5) {
        Api::json(['ok' => false, 'msg' => '密码错误次数过多，请 10 分钟后再试']);
    }
    $u = DB::one('SELECT password FROM users WHERE id=?', [$uid]);
    if (!$u || !password_verify((string)($post['password'] ?? ''), (string)$u['password'])) {
        owPkPwFails(true);
        Sec::log('passkey_add_fail', (string)$actor['nickname'], ['reason' => 'bad_password']);
        Api::json(['ok' => false, 'msg' => '账号密码不正确']);
    }

    // 2) 挑战（一次性）
    $chal = owPkChallengeTake('register');
    if (!$chal) Api::json(['ok' => false, 'msg' => '添加超时，请重新点击「添加通行密钥」']);
    if ((int)($chal['uid'] ?? 0) !== $uid) Api::json(['ok' => false, 'msg' => '会话不匹配，请重试']);

    $att = owPkCbor(owPkB64uDecode((string)($post['attestationObject'] ?? '')));
    if (!is_array($att) || !isset($att['authData']) || !is_string($att['authData'])) {
        Api::json(['ok' => false, 'msg' => '凭据格式不正确']);
    }
    $clientJson = owPkB64uDecode((string)($post['clientDataJSON'] ?? ''));
    if (!owPkCheckClientData($clientJson, 'webauthn.create', (string)$chal['c'])) {
        Api::json(['ok' => false, 'msg' => '验证数据不合法，请重试']);
    }
    $ad = owPkParseAuthData((string)$att['authData']);
    if (!$ad || empty($ad['cred_id']) || empty($ad['cose'])) {
        Api::json(['ok' => false, 'msg' => '未能从认证器读取公钥，请换一台设备重试']);
    }
    if (!hash_equals(hash('sha256', owPkRpId(), true), $ad['rp_hash'])) {
        Api::json(['ok' => false, 'msg' => '凭据与当前站点不匹配']);
    }
    if (!($ad['flags'] & 0x01)) {
        Api::json(['ok' => false, 'msg' => '需要本人操作确认']);
    }
    $pem = owPkCoseToPem($ad['cose']);
    if ($pem === null) {
        Api::json(['ok' => false, 'msg' => '该认证器使用了暂不支持的算法（仅支持 ES256 / RS256 系列）']);
    }

    $credIdRaw = (string)$ad['cred_id'];
    $idHash = hash('sha256', $credIdRaw);
    $exist = DB::one('SELECT user_id FROM plugin_passkey WHERE id_hash=?', [$idHash]);
    if ($exist) {
        if ((int)$exist['user_id'] !== $uid) {
            Api::json(['ok' => false, 'msg' => '这把通行密钥已经绑定在其他账号上']);
        }
        Api::json(['ok' => false, 'msg' => '这台设备已经添加过了，无需重复添加']);
    }

    // 3) 名字：优先认 AAGUID（能分辨 iCloud / Windows Hello / Google 等），否则用前端给的名字
    $aaguid = owPkUuid((string)($ad['aaguid'] ?? ''));
    $known = [
        'fbfc3007-154e-4ecc-8c0b-6e020557d7bd' => 'iCloud 钥匙串',
        '08987058-cadc-4b81-b6e1-30de50dcbe96' => 'Windows Hello',
        'ea9b8d66-4d01-1d21-3ce4-b6b48cb575d4' => 'Google 密码管理器',
        'adce0002-35bc-c60a-648b-0b0f1f38b0d6' => 'Android 设备',
        'd548826e-79b4-db40-a3d8-11116f7e8349' => 'Bitwarden',
        'bada5566-a7aa-401f-bd96-45619a55120d' => '1Password',
    ];
    $name = trim((string)($post['name'] ?? ''));
    if ($aaguid !== '' && isset($known[$aaguid])) $name = $known[$aaguid];
    if ($name === '') $name = '通行密钥';
    $name = mb_substr((string)preg_replace('/\s+/u', ' ', $name), 0, 30);

    $transports = (string)($post['transports'] ?? '');
    $transports = mb_substr(preg_replace('/[^a-z,\-]/', '', strtolower($transports)), 0, 100);
    $attachment = (string)($post['attachment'] ?? '');

    DB::insert('plugin_passkey', [
        'id_hash'        => $idHash,
        'user_id'        => $uid,
        'cred_id'        => owPkB64u($credIdRaw),
        'pubkey'         => $pem,
        'alg'            => (int)($ad['cose'][3] ?? -7),
        'sign_count'     => (int)$ad['count'],
        'aaguid'         => $aaguid,
        'name'           => $name,
        'transports'     => $transports,
        'cross_platform' => $attachment === 'cross-platform' ? 1 : 0,
        'created_at'     => time(),
        'last_used_at'   => 0,
        'last_ip'        => '',
    ]);

    Sec::log('passkey_add', (string)$actor['nickname'], ['name' => $name, 'aaguid' => $aaguid]);
    Api::json(['ok' => true, 'msg' => '通行密钥已添加', 'name' => $name]);
});

/* ---------- 5.6 个人设置：重命名 ---------- */

Plugin::route('plugin_passkey_rename', function (array $ctx): void {
    $actor = owPkActorUser($ctx);
    $uid = (int)$actor['id'];
    $id = preg_replace('/[^a-f0-9]/', '', (string)($ctx['post']['id'] ?? ''));
    $name = trim((string)($ctx['post']['name'] ?? ''));
    if (strlen($id) !== 64) Api::json(['ok' => false, 'msg' => '参数不正确']);
    if ($name === '') Api::json(['ok' => false, 'msg' => '请填写名称']);
    $name = mb_substr(preg_replace('/\s+/u', ' ', $name), 0, 30);
    $row = DB::one('SELECT user_id FROM plugin_passkey WHERE id_hash=?', [$id]);
    if (!$row || (int)$row['user_id'] !== $uid) Api::json(['ok' => false, 'msg' => '通行密钥不存在'], 404);
    DB::run('UPDATE plugin_passkey SET name=? WHERE id_hash=? AND user_id=?', [$name, $id, $uid]);
    Api::json(['ok' => true, 'msg' => '已重命名', 'name' => $name]);
});

/* ---------- 5.7 个人设置：删除（敏感操作） ---------- */

Plugin::route('plugin_passkey_delete', function (array $ctx): void {
    $actor = owPkActorUser($ctx);
    $uid = (int)$actor['id'];
    $id = preg_replace('/[^a-f0-9]/', '', (string)($ctx['post']['id'] ?? ''));
    if (strlen($id) !== 64) Api::json(['ok' => false, 'msg' => '参数不正确']);
    $row = DB::one('SELECT user_id, name FROM plugin_passkey WHERE id_hash=?', [$id]);
    if (!$row || (int)$row['user_id'] !== $uid) Api::json(['ok' => false, 'msg' => '通行密钥不存在'], 404);
    DB::run('DELETE FROM plugin_passkey WHERE id_hash=? AND user_id=?', [$id, $uid]);
    Sec::log('passkey_del', (string)$actor['nickname'], ['name' => (string)$row['name']]);
    Api::json(['ok' => true, 'msg' => '通行密钥已删除']);
});
Plugin::sensitive('plugin_passkey_delete');

/* ---------- 5.8 后台：总开关 ---------- */

Plugin::route('plugin_passkey_admin_save', function (array $ctx): void {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $on = (string)($ctx['post']['enabled'] ?? '0') === '1' ? '1' : '0';
    DB::setSetting('plugin_passkey_login', $on);
    Sec::log('passkey_config', (string)($ctx['actor']['nickname'] ?? ''), ['enabled' => $on]);
    Api::json(['ok' => true, 'msg' => $on === '1' ? '已开启通行密钥登录' : '已关闭通行密钥登录']);
});

/* ==========================================================================
   六、后台页面
   ========================================================================== */

Plugin::adminPage('passkey', '通行密钥', function (): string {
    $on = owPkEnabled();
    $total = (int)DB::val('SELECT COUNT(*) FROM plugin_passkey');
    $users = (int)DB::val('SELECT COUNT(DISTINCT user_id) FROM plugin_passkey');
    $allUsers = (int)DB::val('SELECT COUNT(*) FROM users');
    $week = (int)DB::val('SELECT COUNT(*) FROM plugin_passkey WHERE last_used_at>=?', [time() - 86400 * 7]);
    $pct = $allUsers > 0 ? round($users / $allUsers * 100, 1) : 0;

    $rows = DB::all('SELECT p.name, p.aaguid, p.cross_platform, p.created_at, p.last_used_at,
                            p.user_id AS uid, u.nickname
                     FROM plugin_passkey p LEFT JOIN users u ON u.id=p.user_id
                     ORDER BY p.created_at DESC LIMIT 20');

    $h = '<h2>通行密钥</h2>'
       . '<p class="ow-admin-desc">用户在「个人设置 → 账号」里自行添加通行密钥（指纹 / 面容 / 设备 PIN），'
       . '之后可直接在登录页免密码进入。此处只做站点级开关与使用情况查看，看不到也无法还原任何凭据私钥。</p>'
       . '<div class="owpk-kpi">'
       . '<div class="owpk-kpi-c"><div class="owpk-kpi-n">' . $users . '</div><div class="owpk-kpi-l">已启用用户</div></div>'
       . '<div class="owpk-kpi-c"><div class="owpk-kpi-n">' . $total . '</div><div class="owpk-kpi-l">通行密钥总数</div></div>'
       . '<div class="owpk-kpi-c"><div class="owpk-kpi-n">' . $week . '</div><div class="owpk-kpi-l">近 7 天被使用</div></div>'
       . '<div class="owpk-kpi-c"><div class="owpk-kpi-n">' . $pct . '%</div><div class="owpk-kpi-l">用户覆盖率</div></div>'
       . '</div>';

    $h .= '<div class="ow-card">'
        . '<div class="ow-switch-row">'
        . '<span class="ow-switch-label">允许使用通行密钥登录</span>'
        . '<input type="checkbox" class="ow-switch-input" id="owpkAdmSwitch"' . ($on ? ' checked' : '') . '>'
        . '<label class="ow-switch' . ($on ? ' is-on' : '') . '" for="owpkAdmSwitch"></label>'
        . '<p class="ow-switch-hint">关闭后登录页不再显示入口，已添加的凭据保留在库里，重新开启即恢复。改动即时生效，无需保存。</p>'
        . '</div></div>';

    $h .= '<div class="ow-card"><div class="ow-list-head"><span class="ow-list-head-t">最近添加的通行密钥</span></div>';
    if (!$rows) {
        $h .= '<div class="owpk-empty">还没有任何通行密钥。用户可在「个人设置 → 账号 → 通行密钥」里添加。</div>';
    } else {
        $h .= '<div class="ow-table-wrap"><table class="ow-table"><thead><tr>'
            . '<th>用户</th><th>名称</th><th>类型</th><th>添加时间</th><th>最近使用</th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $nick = (string)($r['nickname'] ?? '');
            $h .= '<tr>'
                . '<td>' . ($nick !== ''
                    ? Sec::e($nick) . ' <span style="color:var(--ow-text-sub)">#' . (int)$r['uid'] . '</span>'
                    : '<span style="color:var(--ow-text-sub)">已注销 #' . (int)$r['uid'] . '</span>') . '</td>'
                . '<td>' . Sec::e((string)$r['name']) . '</td>'
                . '<td>' . ((int)$r['cross_platform'] === 1
                    ? '<span class="ow-tag ow-tag-member">安全密钥</span>'
                    : '<span class="ow-tag ow-tag-green">本机设备</span>') . '</td>'
                . '<td>' . ((int)$r['created_at'] > 0 ? date('Y-m-d H:i', (int)$r['created_at']) : '—') . '</td>'
                . '<td>' . ((int)$r['last_used_at'] > 0 ? date('Y-m-d H:i', (int)$r['last_used_at']) : '未使用') . '</td>'
                . '</tr>';
        }
        $h .= '</tbody></table></div>';
    }
    $h .= '</div>';
    return $h;
});

/* ==========================================================================
   七、资源
   ========================================================================== */

// 主程序只自动引入合并 JS（聊天页 / 登录页 / 后台都会带），样式必须自己挂 page.head。
// ⚠️ 用 plugin= / file= 单文件形式：登录页也会加载，几 KB 的静态文件不影响。
Plugin::on('page.head', function (): void {
    echo '<link rel="stylesheet" href="?action=assets&type=css&plugin=passkey&file=style.css&v='
        . OWLSGO_VERSION . '">' . "\n";
});

Plugin::asset('js', 'passkey/chat.js');
Plugin::asset('css', 'passkey/style.css');
