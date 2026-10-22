<?php
/**
 * 两步验证插件（twofa）v1.0.0
 *
 * 功能：
 *   - TOTP 动态验证码（RFC 6238，30 秒窗口，6 位）
 *   - 一次性恢复码（仅保存 SHA-256 哈希，明文仅生成时展示一次）
 *   - 个人资料页可启用 / 关闭 / 重置恢复码
 *   - 登录时已启用用户需二次验证（通过 login.after_verify 钩子拦截会话）
 *   - 二次验证错误计入现有登录失败频率限制（Sec::loginFail）
 *   - 后台插件页查看已启用两步验证的用户统计
 *   - 2FA 相关表单使用普通提交（非 AJAX），避免被全局 Ajax 拦截误报"操作失败"
 *
 * 不修改主程序：仅依赖核心已有的 login.after_verify 钩子与 Plugin::route / adminPage / asset。
 */
if (!defined('OWLSGO_VERSION')) exit;

/* ===================== TOTP 算法（零依赖） ===================== */

/** Base32 解码（RFC 4648），忽略非字母数字字符与填充符 */
function owTwoFABase32Decode(string $b32): string
{
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    $buf = 0;
    $bits = 0;
    $len = strlen($b32);
    for ($i = 0; $i < $len; $i++) {
        $pos = strpos($map, $b32[$i]);
        if ($pos === false) continue;
        $buf = ($buf << 5) | $pos;
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $out .= chr(($buf >> $bits) & 0xFF);
        }
    }
    return $out;
}

/** HOTP（RFC 4226），默认 6 位数字 */
function owTwoFAHotp(string $secretBin, int $counter, int $digits = 6): string
{
    $binCounter = pack('N*', 0) . pack('N*', $counter);
    $hash = hash_hmac('sha1', $binCounter, $secretBin, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $code = (
        ((ord($hash[$offset]) & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8) |
        (ord($hash[$offset + 3]) & 0xFF)
    ) % (10 ** $digits);
    return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
}

/** 校验 TOTP 码，允许前后各 1 个时间窗口（±30 秒） */
function owTwoFAVerifyTotp(string $secret, string $code, int $window = 1): bool
{
    $secretBin = owTwoFABase32Decode($secret);
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6}$/', $code)) return false;
    $now = (int)(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(owTwoFAHotp($secretBin, $now + $i), $code)) return true;
    }
    return false;
}

/** 生成 32 字符 Base32 密钥（20 字节 / 160 位，符合 RFC 6238 推荐长度） */
function owTwoFAGenerateSecret(): string
{
    $b32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    for ($i = 0; $i < 32; $i++) $out .= $b32[random_int(0, 31)];
    return $out;
}

/** 生成 8 个恢复码，返回 [明文列表, 哈希列表]；哈希仅存储 */
function owTwoFAGenerateRecoveryCodes(): array
{
    $codes = [];
    $hashes = [];
    for ($i = 0; $i < 8; $i++) {
        $code = strtoupper(bin2hex(random_bytes(4)));
        $codes[] = $code;
        $hashes[] = hash('sha256', $code);
    }
    return [$codes, $hashes];
}

/* ===================== 数据库表 ===================== */

function owTwoFAEnsureTable(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $drv = DB::driver();
    $int = $drv === 'mysql' ? 'INT' : ($drv === 'pgsql' ? 'INTEGER' : 'INTEGER');
    $str = $drv === 'sqlite' ? 'TEXT' : 'VARCHAR(191)';
    // recovery_codes 走 DB::textCol()：MySQL 的 TEXT 不能带默认值（1101）
    $sql = "CREATE TABLE IF NOT EXISTS plugin_twofa (
        user_id $int PRIMARY KEY,
        secret $str NOT NULL DEFAULT '',
        " . DB::textCol('recovery_codes', "'[]'") . ",
        enabled $int NOT NULL DEFAULT 0,
        created_at $int NOT NULL DEFAULT 0
    )";
    DB::run($sql);
}
owTwoFAEnsureTable();

/* ===================== 登录钩子 ===================== */

/**
 * login.after_verify：密码校验通过且会话已建立后触发。
 * 若用户已启用 2FA，则撤销会话并置为"待二次验证"状态，
 * 由前端引导用户完成 TOTP / 恢复码校验。
 */
Plugin::on('login.after_verify', function (array $user, string $method, array $ctx): void {
    if ($method !== 'password') return;   // 仅拦截密码登录，放行 2FA 通过后的二次触发
    $row = DB::one('SELECT enabled FROM plugin_twofa WHERE user_id=?', [(int)$user['id']]);
    if ($row && (int)$row['enabled'] === 1) {
        unset($_SESSION['uid']);
        $_SESSION['twofa_pending_uid'] = (int)$user['id'];
        $_SESSION['twofa_login_key'] = strtolower($user['email']) . '|' . ($ctx['ip'] ?? Sec::ip());
    }
});

/**
 * 登录成功后查询是否待二次验证（AJAX）。
 * 前端在核心 login 返回成功后调用本接口：need=true 时展示两步验证表单，
 * 全程走 OwApi（自动签名），避免普通表单提交被核心签名门禁拒绝。
 */
Plugin::route('plugin_twofa_check', function (array $ctx): void {
    $uid = (int)($_SESSION['twofa_pending_uid'] ?? 0);
    Api::json(['ok' => true, 'need' => $uid > 0]);
});

/* ===================== 路由：二次验证（AJAX 提交） ===================== */

Plugin::route('plugin_twofa_verify', function (array $ctx): void {
    // 同上：验证通过后要写 $_SESSION['uid']，需重新打开会话
    if (session_status() === PHP_SESSION_NONE) session_start();
    $uid = (int)($_SESSION['twofa_pending_uid'] ?? 0);
    if ($uid <= 0) {
        Api::json(['ok' => false, 'msg' => '验证已超时，请重新登录', 'expired' => true]);
    }

    $code = strtoupper(preg_replace('/\s+/', '', (string)($ctx['post']['code'] ?? '')));
    $loginKey = (string)($_SESSION['twofa_login_key'] ?? '');

    $row = DB::one('SELECT * FROM plugin_twofa WHERE user_id=? AND enabled=1', [$uid]);
    if (!$row) {
        // 2FA 已被关闭，直接完成登录
        $_SESSION['uid'] = $uid;
        unset($_SESSION['twofa_pending_uid'], $_SESSION['twofa_login_key']);
        Api::json(['ok' => true, 'msg' => '登录成功']);
    }

    $verified = false;
    $usedRecovery = false;

    // 优先 TOTP
    if (owTwoFAVerifyTotp($row['secret'], $code)) {
        $verified = true;
    } else {
        // 恢复码（一次性，使用后从哈希列表中移除）
        $codes = json_decode((string)$row['recovery_codes'], true) ?: [];
        $hash = hash('sha256', $code);
        foreach ($codes as $i => $c) {
            if (hash_equals((string)$c, $hash)) {
                unset($codes[$i]);
                DB::run('UPDATE plugin_twofa SET recovery_codes=? WHERE user_id=?',
                    [json_encode(array_values($codes)), $uid]);
                $verified = true;
                $usedRecovery = true;
                break;
            }
        }
    }

    if (!$verified) {
        if ($loginKey !== '') Sec::loginFail($loginKey);
        Sec::log('twofa_verify_fail', '', ['uid' => $uid]);
        Api::json(['ok' => false, 'msg' => '动态验证码或恢复码不正确']);
    }

    // 验证通过：完成登录
    if ($loginKey !== '') Sec::loginOk($loginKey);
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
    unset($_SESSION['twofa_pending_uid'], $_SESSION['twofa_login_key']);
    DB::run('UPDATE users SET last_login=? WHERE id=?', [time(), $uid]);
    $nick = (string)DB::val('SELECT nickname FROM users WHERE id=?', [$uid]);
    Sec::log('twofa_login_ok', $nick, ['method' => $usedRecovery ? 'recovery' : 'totp']);
    Api::json(['ok' => true, 'msg' => '验证通过']);
});

Plugin::route('plugin_twofa_cancel', function (array $ctx): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    unset($_SESSION['twofa_pending_uid'], $_SESSION['twofa_login_key']);
    Api::json(['ok' => true]);
});

/* ===================== 个人资料：2FA 管理路由（AJAX） ===================== */

$twofaUserGuard = function (array $ctx): array {
    if (($ctx['actor']['kind'] ?? '') !== 'user') {
        Api::json(['ok' => false, 'msg' => '请先登录'], 403);
    }
    return $ctx['actor'];
};

/** 查询当前用户的 2FA 状态 */
Plugin::route('plugin_twofa_status', function (array $ctx) use ($twofaUserGuard) {
    $actor = $twofaUserGuard($ctx);
    $uid = (int)$actor['id'];
    $row = DB::one('SELECT enabled, recovery_codes FROM plugin_twofa WHERE user_id=?', [$uid]);
    $enabled = $row ? (int)$row['enabled'] === 1 : false;
    $remain = 0;
    if ($enabled) {
        $codes = json_decode((string)($row['recovery_codes'] ?? '[]'), true) ?: [];
        $remain = count($codes);
    }
    Api::json(['ok' => true, 'enabled' => $enabled, 'recovery_remain' => $remain]);
});

/** 生成 TOTP 密钥与 otpauth URI（尚未启用，仅供扫码预览） */
Plugin::route('plugin_twofa_setup', function (array $ctx) use ($twofaUserGuard) {
    // 密钥暂存会话（twofa_setup_secret），必须重新打开会话才能落盘
    if (session_status() === PHP_SESSION_NONE) session_start();
    $actor = $twofaUserGuard($ctx);
    $uid = (int)$actor['id'];
    $row = DB::one('SELECT enabled FROM plugin_twofa WHERE user_id=?', [$uid]);
    if ($row && (int)$row['enabled'] === 1) {
        Api::json(['ok' => false, 'msg' => '两步验证已启用，请先关闭']);
    }
    $secret = owTwoFAGenerateSecret();
    // label 用固定 issuer + 数字用户 ID：不随站点名 / 邮箱长度变化，
    // 保证 otpauth 串恒定在 ~86 字节内（V6 容量 108 字节），内嵌 QR 生成器可稳定编码
    $account = 'u' . $uid;
    $issuer = 'Owlsgo-Chat';
    $otpauth = 'otpauth://totp/' . $issuer . ':' . $account
        . '?secret=' . $secret . '&issuer=' . $issuer;
    $_SESSION['twofa_setup_secret'] = $secret;
    Api::json(['ok' => true, 'secret' => $secret, 'otpauth' => $otpauth]);
});

/** 确认启用 2FA：校验 TOTP 码并保存，返回恢复码明文（仅一次） */
Plugin::route('plugin_twofa_enable', function (array $ctx) use ($twofaUserGuard) {
    $actor = $twofaUserGuard($ctx);
    $uid = (int)$actor['id'];
    $code = preg_replace('/\s+/', '', (string)($ctx['post']['code'] ?? ''));
    $secret = (string)($_SESSION['twofa_setup_secret'] ?? '');
    if ($secret === '' || !owTwoFAVerifyTotp($secret, $code)) {
        Api::json(['ok' => false, 'msg' => '验证码错误，请输入验证器 App 中的 6 位数字']);
    }
    [$plain, $hashes] = owTwoFAGenerateRecoveryCodes();
    DB::upsert('plugin_twofa', [
        'user_id' => $uid,
        'secret' => $secret,
        'recovery_codes' => json_encode($hashes),
        'enabled' => 1,
        'created_at' => time(),
    ], ['user_id']);
    unset($_SESSION['twofa_setup_secret']);
    Sec::log('twofa_enable', $actor['nickname'], ['uid' => $uid]);
    Notice::push($uid, 'twofa_on');   // v1.3.52 账号安全事件站内留痕
    Api::json(['ok' => true, 'recovery_codes' => $plain]);
});

/** 关闭 2FA */
Plugin::route('plugin_twofa_disable', function (array $ctx) use ($twofaUserGuard) {
    $actor = $twofaUserGuard($ctx);
    $uid = (int)$actor['id'];
    // 要求输入当前密码二次确认
    $password = (string)($ctx['post']['password'] ?? '');
    $user = DB::one('SELECT password FROM users WHERE id=?', [$uid]);
    if (!$user || !password_verify($password, $user['password'])) {
        Api::json(['ok' => false, 'msg' => '密码错误，无法关闭两步验证']);
    }
    DB::run('UPDATE plugin_twofa SET enabled=0, secret=?, recovery_codes=? WHERE user_id=?', ['', '[]', $uid]);
    Sec::log('twofa_disable', $actor['nickname'], ['uid' => $uid]);
    Notice::push($uid, 'twofa_off');   // v1.3.52：关闭 2FA 是高风险动作，必须让本人看得到
    Api::json(['ok' => true, 'msg' => '两步验证已关闭']);
});
Plugin::sensitive('plugin_twofa_disable');   // 关闭两步验证：敏感（v1.0.91）

/** 重置恢复码（要求已启用 2FA，且需密码确认） */
Plugin::route('plugin_twofa_reset_codes', function (array $ctx) use ($twofaUserGuard) {
    $actor = $twofaUserGuard($ctx);
    $uid = (int)$actor['id'];
    $row = DB::one('SELECT enabled FROM plugin_twofa WHERE user_id=?', [$uid]);
    if (!$row || (int)$row['enabled'] !== 1) {
        Api::json(['ok' => false, 'msg' => '请先启用两步验证']);
    }
    $password = (string)($ctx['post']['password'] ?? '');
    $user = DB::one('SELECT password FROM users WHERE id=?', [$uid]);
    if (!$user || !password_verify($password, $user['password'])) {
        Api::json(['ok' => false, 'msg' => '密码错误，无法重置恢复码']);
    }
    [$plain, $hashes] = owTwoFAGenerateRecoveryCodes();
    DB::run('UPDATE plugin_twofa SET recovery_codes=? WHERE user_id=?', [json_encode($hashes), $uid]);
    Sec::log('twofa_reset_codes', $actor['nickname'], ['uid' => $uid]);
    Api::json(['ok' => true, 'recovery_codes' => $plain]);
});
Plugin::sensitive('plugin_twofa_reset_codes');   // 重置恢复码：敏感（v1.0.91）

/* ===================== 后台统计页面 ===================== */

Plugin::adminPage('twofa', '两步验证', function () {
    $total = (int)DB::val('SELECT COUNT(*) FROM users');
    $enabled = (int)DB::val('SELECT COUNT(*) FROM plugin_twofa WHERE enabled=1');
    $pct = $total > 0 ? round($enabled / $total * 100, 1) : 0;
    $remain0 = (int)DB::val("SELECT COUNT(*) FROM plugin_twofa WHERE enabled=1 AND recovery_codes='[]'");
    return '<h2>两步验证</h2>'
        . '<p class="ow-admin-desc">查看全站两步验证（TOTP）启用情况。恢复码仅保存哈希，无法查看明文；若用户丢失全部恢复码且无法登录，可在用户管理中禁用其账号后联系用户处理。</p>'
        . '<div class="ow-card ow-twofa-stats">'
        . '<div class="ow-twofa-stat"><span class="ow-twofa-stat-num">' . $enabled . '</span><span class="ow-twofa-stat-label">已启用</span></div>'
        . '<div class="ow-twofa-stat"><span class="ow-twofa-stat-num">' . $total . '</span><span class="ow-twofa-stat-label">用户总数</span></div>'
        . '<div class="ow-twofa-stat"><span class="ow-twofa-stat-num">' . $pct . '%</span><span class="ow-twofa-stat-label">启用率</span></div>'
        . '<div class="ow-twofa-stat ow-twofa-stat-warn"><span class="ow-twofa-stat-num">' . $remain0 . '</span><span class="ow-twofa-stat-label">恢复码已用完</span></div>'
        . '</div>';
});

/* ===================== 注册资源 ===================== */

// 登录页（renderAuth）不经过主程序的合并资源引入，用 page.footer 钩子补上。
// ⚠️ 必须判 OWLSGO_ASSETS_JS_EMITTED：聊天页 / 后台页核心已经输出过一份，
//    这里再补就变成**加载两遍合并包** —— 插件 JS 整体执行两次，
//    凡「往数组里注册」的扩展点都会重复注册（曾导致资料卡出现两行等级）。
//    （原先靠 chat.js 内的 __haTwoFALoaded 兜底，那只救得了本插件，救不了别人。）
Plugin::on('page.footer', function () {
    if (defined('OWLSGO_ASSETS_JS_EMITTED')) return;
    echo '<script src="?action=assets&type=js&v=' . OWLSGO_ASSET_VERSION . '"></script>';
});

Plugin::asset('js', 'twofa/chat.js');
Plugin::asset('css', 'twofa/admin.css');
