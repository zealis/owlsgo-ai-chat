<?php
/**
 * 私聊端到端加密（v1.0.0）
 *
 * 只做私聊，不做群聊：私聊已经是「仅注册用户 + 必须是好友」（core/chat.php:795-805），
 * 游客与陌生人两条路本来就被堵死，所以不需要处理游客的临时身份密钥问题。
 *
 * 本插件在服务端只做三件事：
 *   1. 当公钥目录：存用户上传的身份公钥 / 签名预密钥 / 一次性预密钥，供对方取。
 *      **私钥永不上传** —— 服务端拿不到任何能解密消息的材料。
 *   2. 校验信封：拒收畸形密文、在会话已开启加密时拒收明文私信。
 *   3. 记账：哪条消息是密文（后台统计 + 合规查阅时明确告知「此条为密文」）。
 *
 * 它做不到、也不该假装能做到的事（写在这里免得后来人误解）：
 *   - 服务端看不到明文 → 敏感词、AI 审核、管理员查阅私聊对这些会话一律失效。
 *     后台「私聊合规查阅」页对加密会话只能显示「该会话已加密，无法提供内容」。
 *   - 客户端 JS 由本站下发，所以「防服务端主动作恶」在纯 Web 形态下并不成立。
 *     这套加密真实防住的是：**数据库泄露 / 备份泄露 / 运维误看**。
 */
if (!defined('OWLSGO_VERSION')) exit('Access denied');

const OWED_MODE_KEY   = 'plugin_e2ee_dm_mode';      // off | optin
const OWED_SCHEMA_KEY = 'plugin_e2ee_dm_schema';
const OWED_PREFIX     = 'owee1:';
const OWED_MAXLEN     = 20000;                       // 加密信封的字符上限（明文仍是 2000）
const OWED_OPK_MAX    = 100;                         // 单设备保存的一次性预密钥上限

/* ===================== 小工具 ===================== */

function owEDMode(): string
{
    $m = (string)DB::setting(OWED_MODE_KEY, 'off');
    return $m === 'optin' ? 'optin' : 'off';
}

function owEDIsUser($actor): bool
{
    return is_array($actor) && ($actor['kind'] ?? '') === 'user';
}

function owEDIsEnvelope(string $s): bool
{
    return strncmp($s, OWED_PREFIX, strlen(OWED_PREFIX)) === 0 && strlen($s) > 32;
}

/** 会话键：两个 uid 排序后拼起来，与发起方向无关 */
function owEDConvKey(int $a, int $b): string
{
    return $a < $b ? "u$a:u$b" : "u$b:u$a";
}

function owEDConvEnabled(int $a, int $b): bool
{
    $k = owEDConvKey($a, $b);
    return (int)DB::val('SELECT enabled FROM plugin_e2ee_conv WHERE conv=?', [$k]) === 1;
}

/** 某用户当前生效的设备行（单设备模型：一个账号只有一台设备持有私钥） */
function owEDDevice(int $uid): ?array
{
    return DB::one('SELECT * FROM plugin_e2ee_devices WHERE user_id=? ORDER BY id DESC LIMIT 1', [$uid]);
}

/** 校验并解析信封；返回 null 表示不合格 */
function owEDParseEnvelope(string $s): ?array
{
    if (!owEDIsEnvelope($s)) return null;
    $j = json_decode(substr($s, strlen(OWED_PREFIX)), true);
    if (!is_array($j) || (int)($j['v'] ?? 0) !== 1) return null;
    if (!isset($j['m']) || !is_array($j['m']) || !$j['m']) return null;
    foreach ($j['m'] as $dev => $item) {
        if (!is_string($dev) || !preg_match('/^[A-Za-z0-9_-]{8,32}$/', $dev)) return null;
        if (!is_array($item) || !isset($item['h'], $item['c'])) return null;
        if (!is_array($item['h']) || !is_string($item['h']['dh'] ?? null)) return null;
        if (!is_string($item['c']) || strlen($item['c']) < 16) return null;
    }
    return $j;
}

/* ===================== 建表（版本闸门） ===================== */

function owEDSchema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    if (DB::setting(OWED_SCHEMA_KEY, '') === '1') {
        try {
            DB::val('SELECT dev_id FROM plugin_e2ee_devices LIMIT 1');
            return;
        } catch (Throwable $e) { /* 表没了：往下重建 */ }
    }

    $id = DB::autoId();

    DB::run("CREATE TABLE IF NOT EXISTS plugin_e2ee_devices (
        id {$id},
        user_id INT NOT NULL DEFAULT 0,
        dev_id VARCHAR(32) NOT NULL DEFAULT '',
        ik_dh TEXT,
        ik_sig TEXT,
        spk TEXT,
        spk_id INT NOT NULL DEFAULT 0,
        spk_sig TEXT,
        dev_name VARCHAR(60) NOT NULL DEFAULT '',
        ua VARCHAR(120) NOT NULL DEFAULT '',
        created_at INT NOT NULL DEFAULT 0,
        last_seen INT NOT NULL DEFAULT 0
    )");
    DB::createIndex('owed_dev_user', 'plugin_e2ee_devices', 'user_id');
    DB::createIndex('owed_dev_dev', 'plugin_e2ee_devices', 'dev_id', true);

    DB::run("CREATE TABLE IF NOT EXISTS plugin_e2ee_opk (
        id {$id},
        dev_id VARCHAR(32) NOT NULL DEFAULT '',
        user_id INT NOT NULL DEFAULT 0,
        key_id INT NOT NULL DEFAULT 0,
        pub " . DB::textCol('pub_val') . ",
        used INT NOT NULL DEFAULT 0,
        created_at INT NOT NULL DEFAULT 0
    )");
    DB::createIndex('owed_opk_dev', 'plugin_e2ee_opk', 'dev_id,used');
    DB::createIndex('owed_opk_uniq', 'plugin_e2ee_opk', 'dev_id,key_id', true);

    DB::run("CREATE TABLE IF NOT EXISTS plugin_e2ee_conv (
        id {$id},
        conv VARCHAR(40) NOT NULL DEFAULT '',
        a_uid INT NOT NULL DEFAULT 0,
        b_uid INT NOT NULL DEFAULT 0,
        enabled INT NOT NULL DEFAULT 0,
        enabled_by INT NOT NULL DEFAULT 0,
        enabled_at INT NOT NULL DEFAULT 0
    )");
    DB::createIndex('owed_conv_uniq', 'plugin_e2ee_conv', 'conv', true);

    DB::run("CREATE TABLE IF NOT EXISTS plugin_e2ee_msg (
        id {$id},
        msg_id INT NOT NULL DEFAULT 0,
        conv VARCHAR(40) NOT NULL DEFAULT '',
        from_uid INT NOT NULL DEFAULT 0,
        to_uid INT NOT NULL DEFAULT 0,
        dev_from VARCHAR(32) NOT NULL DEFAULT '',
        created_at INT NOT NULL DEFAULT 0
    )");
    DB::createIndex('owed_msg_uniq', 'plugin_e2ee_msg', 'msg_id', true);
    DB::createIndex('owed_msg_conv', 'plugin_e2ee_msg', 'conv,created_at');

    DB::setSetting(OWED_SCHEMA_KEY, '1');
}

/* ===================== API 路由 ===================== */

/**
 * 我的加密状态。
 * 返回 {ok, mode, device:{dev_id,ik_dh,created_at,dev_name}|null, convEnabled:bool}
 * 入参：peer_id（可选，给了就一并返回该会话是否已开启加密）
 */
Plugin::route('plugin_e2ee_dm_state', function (array $ctx) {
    $actor = $ctx['actor'];
    if (!owEDIsUser($actor)) Api::json(['ok' => false, 'msg' => '请先登录'], 403);
    owEDSchema();
    $me = (int)$actor['id'];
    $dev = owEDDevice($me);
    $peer = (int)($ctx['post']['peer_id'] ?? 0);
    Api::json([
        'ok' => true,
        'mode' => owEDMode(),
        'device' => $dev ? [
            'dev_id' => (string)$dev['dev_id'],
            'ik_dh' => (string)$dev['ik_dh'],
            'created_at' => (int)$dev['created_at'],
            'name' => (string)$dev['dev_name'],
        ] : null,
        'conv_enabled' => $peer > 0 ? owEDConvEnabled($me, $peer) : false,
    ]);
});

/**
 * 登记本设备的公钥材料（私钥不上传）。
 * POST: ik_dh, ik_sig, spk, spk_id, spk_sig, opks(JSON 数组 [{id,pub}]), dev_name
 *
 * 单设备模型：登记新设备会作废旧设备 —— 旧设备之后收不到新消息的密钥，
 * 也无法解密新消息（这是「没有多设备扇出」的代价，前端必须二次确认）。
 */
Plugin::route('plugin_e2ee_dm_register', function (array $ctx) {
    $actor = $ctx['actor'];
    if (!owEDIsUser($actor)) Api::json(['ok' => false, 'msg' => '请先登录'], 403);
    if (owEDMode() === 'off') Api::json(['ok' => false, 'msg' => '站点未开启加密私聊']);
    owEDSchema();

    $p = $ctx['post'];
    $ikDh = trim((string)($p['ik_dh'] ?? ''));
    $ikSig = trim((string)($p['ik_sig'] ?? ''));
    $spk = trim((string)($p['spk'] ?? ''));
    $spkId = (int)($p['spk_id'] ?? 0);
    $spkSig = trim((string)($p['spk_sig'] ?? ''));
    $name = mb_substr(trim((string)($p['dev_name'] ?? '')), 0, 60);

    // P-256 公钥 raw 是 65 字节 → base64url 87 字符；签名 64 字节 → 86 字符。
    // 只做形状校验（长度/字符集），不做密码学校验 —— 服务端验了也没有意义，
    // 真正的信任来自对端客户端验签名（owce.js 的 x3dhInit）。
    foreach ([['ik_dh', $ikDh, 80, 96], ['ik_sig', $ikSig, 80, 96],
              ['spk', $spk, 80, 96], ['spk_sig', $spkSig, 80, 96]] as $f) {
        if (!preg_match('/^[A-Za-z0-9_-]{' . $f[2] . ',' . $f[3] . '}$/', $f[1])) {
            Api::json(['ok' => false, 'msg' => '密钥格式不正确（' . $f[0] . '）']);
        }
    }
    if ($spkId <= 0) Api::json(['ok' => false, 'msg' => '预密钥编号无效']);

    $opks = json_decode((string)($p['opks'] ?? '[]'), true);
    if (!is_array($opks)) $opks = [];
    if (count($opks) > OWED_OPK_MAX) $opks = array_slice($opks, 0, OWED_OPK_MAX);

    $me = (int)$actor['id'];
    $now = time();
    // 设备号由**身份公钥的原始字节**派生，必须与前端 owce.js 的 deviceId() 逐字节一致：
    // 前端是 sha256(rawKey) 取前 12 字节再 base64url。这里哈希的也必须解码后的原始公钥，
    // 不能直接哈希 base64 字符串（差一个字符就是两台设备互相对不上）。
    $rawIk = base64_decode(strtr($ikDh, '-_', '+/') . str_repeat('=', (4 - strlen($ikDh) % 4) % 4));
    $devId = rtrim(strtr(base64_encode(substr(hash('sha256', $rawIk, true), 0, 12)), '+/', '-_'), '=');

    try {
        DB::pdo()->beginTransaction();
        // 作废旧设备与该设备的预密钥
        DB::run('DELETE FROM plugin_e2ee_opk WHERE user_id=?', [$me]);
        DB::run('DELETE FROM plugin_e2ee_devices WHERE user_id=?', [$me]);
        DB::insert('plugin_e2ee_devices', [
            'user_id' => $me, 'dev_id' => $devId,
            'ik_dh' => $ikDh, 'ik_sig' => $ikSig,
            'spk' => $spk, 'spk_id' => $spkId, 'spk_sig' => $spkSig,
            'dev_name' => $name, 'ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120),
            'created_at' => $now, 'last_seen' => $now,
        ]);
        foreach ($opks as $o) {
            if (!is_array($o)) continue;
            $kid = (int)($o['id'] ?? 0);
            $pub = (string)($o['pub'] ?? '');
            if ($kid <= 0 || !preg_match('/^[A-Za-z0-9_-]{80,96}$/', $pub)) continue;
            DB::insert('plugin_e2ee_opk', [
                'dev_id' => $devId, 'user_id' => $me, 'key_id' => $kid,
                'pub' => $pub, 'used' => 0, 'created_at' => $now,
            ]);
        }
        DB::pdo()->commit();
    } catch (Throwable $e) {
        if (DB::pdo()->inTransaction()) DB::pdo()->rollBack();
        Api::json(['ok' => false, 'msg' => '保存密钥失败，请重试']);
    }

    Sec::log('e2ee_device_register', (string)($actor['nickname'] ?? ''), ['dev' => $devId, 'opk' => count($opks)]);
    Api::json(['ok' => true, 'dev_id' => $devId, 'msg' => '本设备已启用加密私聊']);
});

/**
 * 取对方的密钥包（同时消耗一枚一次性预密钥）。
 * POST: peer_id
 * 返回 {ok, bundle:{devId,ikDh,ikSig,spk,spkId,spkSig,opk:{id,pub}|null}}
 *
 * 只允许取好友的密钥包：否则任何人都能刷光别人的一次性预密钥。
 */
Plugin::route('plugin_e2ee_dm_bundle', function (array $ctx) {
    $actor = $ctx['actor'];
    if (!owEDIsUser($actor)) Api::json(['ok' => false, 'msg' => '请先登录'], 403);
    if (owEDMode() === 'off') Api::json(['ok' => false, 'msg' => '站点未开启加密私聊']);
    owEDSchema();

    $me = (int)$actor['id'];
    $peer = (int)($ctx['post']['peer_id'] ?? 0);
    if ($peer <= 0 || $peer === $me) Api::json(['ok' => false, 'msg' => '参数错误']);
    if (!Chat::areFriends($me, $peer)) Api::json(['ok' => false, 'msg' => '只能与好友建立加密会话'], 403);
    if (!Sec::rateLimit('e2ee_bundle', 'u' . $me, 60, 30)) {
        Api::json(['ok' => false, 'msg' => '操作过于频繁，请稍后再试'], 429);
    }

    $dev = owEDDevice($peer);
    if (!$dev) Api::json(['ok' => false, 'msg' => '对方未在本设备启用加密私聊', 'no_device' => true]);

    // 一次性预密钥：取最老的一枚并原子标记为已用（并发安全靠 WHERE used=0）
    $opk = null;
    $row = DB::one('SELECT id, key_id, pub FROM plugin_e2ee_opk WHERE dev_id=? AND used=0 ORDER BY id ASC LIMIT 1',
        [(string)$dev['dev_id']]);
    if ($row) {
        $st = DB::run('UPDATE plugin_e2ee_opk SET used=1 WHERE id=? AND used=0', [(int)$row['id']]);
        if ($st->rowCount() === 1) $opk = ['id' => (int)$row['key_id'], 'pub' => (string)$row['pub']];
    }

    Api::json(['ok' => true, 'bundle' => [
        'devId' => (string)$dev['dev_id'],
        'ikDh' => (string)$dev['ik_dh'],
        'ikSig' => (string)$dev['ik_sig'],
        'spk' => (string)$dev['spk'],
        'spkId' => (int)$dev['spk_id'],
        'spkSig' => (string)$dev['spk_sig'],
        'opk' => $opk,
        'opkLeft' => (int)DB::val('SELECT COUNT(*) FROM plugin_e2ee_opk WHERE dev_id=? AND used=0', [(string)$dev['dev_id']]),
    ]]);
});

/**
 * 开启 / 关闭某个私聊会话的加密。
 * POST: peer_id, on(1|0)
 *
 * 语义：只要有一方开启，这个会话就进入「加密模式」，此后私聊必须发密文。
 * 对方若没有登记设备，开启会被拒绝 —— 否则会出现「界面说已加密、实际发不出去」。
 */
Plugin::route('plugin_e2ee_dm_conv', function (array $ctx) {
    $actor = $ctx['actor'];
    if (!owEDIsUser($actor)) Api::json(['ok' => false, 'msg' => '请先登录'], 403);
    if (owEDMode() === 'off') Api::json(['ok' => false, 'msg' => '站点未开启加密私聊']);
    owEDSchema();

    $me = (int)$actor['id'];
    $peer = (int)($ctx['post']['peer_id'] ?? 0);
    $on = (int)($ctx['post']['on'] ?? 0) === 1;
    if ($peer <= 0 || $peer === $me) Api::json(['ok' => false, 'msg' => '参数错误']);
    if (!Chat::areFriends($me, $peer)) Api::json(['ok' => false, 'msg' => '只能与好友使用加密私聊'], 403);

    if ($on) {
        if (!owEDDevice($me)) Api::json(['ok' => false, 'msg' => '请先在本设备启用加密私聊']);
        if (!owEDDevice($peer)) Api::json(['ok' => false, 'msg' => '对方尚未启用加密私聊，无法开启']);
    }

    $conv = owEDConvKey($me, $peer);
    $now = time();
    $exists = DB::one('SELECT id FROM plugin_e2ee_conv WHERE conv=?', [$conv]);
    if ($exists) {
        DB::run('UPDATE plugin_e2ee_conv SET enabled=?, enabled_by=?, enabled_at=? WHERE conv=?',
            [$on ? 1 : 0, $me, $now, $conv]);
    } else {
        DB::insert('plugin_e2ee_conv', [
            'conv' => $conv, 'a_uid' => min($me, $peer), 'b_uid' => max($me, $peer),
            'enabled' => $on ? 1 : 0, 'enabled_by' => $me, 'enabled_at' => $now,
        ]);
    }
    Sec::log($on ? 'e2ee_conv_on' : 'e2ee_conv_off', (string)($actor['nickname'] ?? ''), ['peer' => $peer]);
    Api::json(['ok' => true, 'enabled' => $on, 'msg' => $on ? '已开启加密，此后该会话只能发送密文' : '已关闭加密']);
});

/**
 * 注销本设备的密钥（换设备 / 怀疑私钥泄露时用）。
 * 代价：已加密的历史消息在本设备永久无法解密。
 */
Plugin::route('plugin_e2ee_dm_reset', function (array $ctx) {
    $actor = $ctx['actor'];
    if (!owEDIsUser($actor)) Api::json(['ok' => false, 'msg' => '请先登录'], 403);
    owEDSchema();
    $me = (int)$actor['id'];
    DB::run('DELETE FROM plugin_e2ee_opk WHERE user_id=?', [$me]);
    DB::run('DELETE FROM plugin_e2ee_devices WHERE user_id=?', [$me]);
    Sec::log('e2ee_device_reset', (string)($actor['nickname'] ?? ''), []);
    Api::json(['ok' => true, 'msg' => '本设备的加密密钥已注销']);
});
Plugin::sensitive('plugin_e2ee_dm_reset');

/* ===================== 钩子 ===================== */

/**
 * 保护密文：整段跳过文字过滤与否决。
 * 回调签名：[&$skip, &$text, $scene, $actor]
 *
 * 为什么必须有这一步：信封是 base64url + JSON，短敏感词（ass / pwd / 台独…）会以
 * 很小的概率随机命中并被替换成一个字符 —— AEAD 只要一个字节不对就认证失败，
 * 这条消息从此**永久解不开**，而且现象是「偶发几条读不出来」，几乎无法定位。
 * 反过来，把密文送进 AI 审核也只是浪费调用并对乱码做误判。
 *
 * 判定用「前缀 + "m":」，比 owEDIsEnvelope 更严一点：用户随手打一句
 * 「owee1:你好」不该被当成密文特殊对待。
 */
Plugin::on('text.opaque', function (&$skip, &$text, $scene, $actor) {
    if ($scene !== 'message') return;
    $s = (string)$text;
    if (owEDIsEnvelope($s) && strpos($s, '"m":') !== false) $skip = true;
});

/**
 * 记账 + 会话级明文拦截。
 * 回调签名：[&$content, $actor, $roomId, $toUserId]
 *
 * 为什么明文拦截在这里而不是 text.check：只有这个钩子拿得到 roomId 与收件人 id，
 * text.check 两者都拿不到。代价是此处已无法「拒绝」，只能把内容换成一句固定提示 ——
 * 消息行仍会入库，但**不携带任何明文**，用户看到的是明显的拦截提示。
 * 同时写安全日志，出现即说明有页面在绕过前端加密路径（旧缓存页 / 构造请求）。
 */
Plugin::on('message.before_send', function (&$content, $actor, $roomId, $toUserId = 0) {
    if ((int)$roomId !== 0) return;                 // 只做私聊
    if (!owEDIsUser($actor)) return;
    if (owEDMode() === 'off') return;
    owEDSchema();
    $content = (string)$content;
    if (owEDIsEnvelope($content)) return;           // 密文：形状已在 text.opaque 里确认过

    $to = (int)$toUserId;
    if ($to <= 0) return;
    if (!owEDConvEnabled((int)$actor['id'], $to)) return;   // 该会话没开加密，明文照常

    $content = '（此消息未加密，已被拦截）';
    Sec::log('e2ee_plaintext_blocked', (string)($actor['nickname'] ?? ''),
        ['to' => $to, 'conv' => owEDConvKey((int)$actor['id'], $to)]);
});

/**
 * 记账：哪些消息是密文。后台统计与合规查阅靠它区分「密文」与「明文」。
 * 回调签名：[$msgId, $actor, $roomId, $content, $type, $toUserId]
 */
Plugin::on('message.after_send', function ($msgId, $actor, $roomId, $content, $type, $toUserId) {
    if ((int)$roomId !== 0 || !owEDIsUser($actor)) return;
    if (owEDMode() === 'off' || !owEDIsEnvelope((string)$content)) return;
    owEDSchema();
    $env = owEDParseEnvelope((string)$content);
    if (!$env) return;
    $me = (int)$actor['id'];
    $to = (int)$toUserId;
    try {
        DB::insertIgnore('plugin_e2ee_msg', [
            'msg_id' => (int)$msgId,
            'conv' => $to > 0 ? owEDConvKey($me, $to) : '',
            'from_uid' => $me, 'to_uid' => $to,
            'dev_from' => (string)($env['s'] ?? ''),
            'created_at' => time(),
        ], ['msg_id']);
    } catch (Throwable $e) { /* 记账失败不影响发消息 */ }
});

/** 加密信封比明文长 ~70%，主程序默认 2000 字上限会把正常长度的私信挡掉。
 *  只放宽「确实是信封」的那种，明文私信仍是 2000 字。 */
Plugin::on('message.maxlen', function (&$max, $type, $roomId, $actor, $content = '') {
    if ((int)$roomId === 0 && owEDMode() !== 'off' && owEDIsEnvelope((string)$content)) $max = OWED_MAXLEN;
});

/** 会话列表那一行摘要：密文不能把 base64 抖给用户看 */
Plugin::on('message.conv_text', function (&$text, $m) {
    if ((int)($m['room_id'] ?? -1) !== 0) return;
    if (owEDIsEnvelope((string)($m['content'] ?? ''))) $text = '[加密消息]';
});

/* ===================== 后台页面 ===================== */

Plugin::adminPage('e2ee-dm', '加密私聊', function () {
    owEDSchema();
    $mode = owEDMode();
    $devCnt = (int)DB::val('SELECT COUNT(*) FROM plugin_e2ee_devices');
    $opkLeft = (int)DB::val('SELECT COUNT(*) FROM plugin_e2ee_opk WHERE used=0');
    $convCnt = (int)DB::val('SELECT COUNT(*) FROM plugin_e2ee_conv WHERE enabled=1');
    $msgCnt = (int)DB::val('SELECT COUNT(*) FROM plugin_e2ee_msg');

    $devs = DB::all('SELECT d.user_id, d.dev_id, d.dev_name, d.created_at, u.nickname
                     FROM plugin_e2ee_devices d LEFT JOIN users u ON u.id=d.user_id
                     ORDER BY d.id DESC LIMIT 30');

    echo '<h2>加密私聊</h2>';
    echo '<div class="ow-card"><p style="margin:0;font-size:12px">'
        . '私聊消息在用户浏览器里加密，服务端只保存密文与公钥。开启后敏感词过滤、AI 审核、'
        . '管理员查阅对这些会话一律失效 —— 这是端到端加密的定义，不是缺陷。</p></div>';

    echo '<div class="ow-card"><div class="ow-form-row">';
    echo '<div class="ow-form-item"><label>功能状态</label>'
        . '<label style="display:block;margin:4px 0"><input type="radio" name="owedMode" value="off"'
        . ($mode === 'off' ? ' checked' : '') . '> 关闭（后台不显示任何入口）</label>'
        . '<label style="display:block"><input type="radio" name="owedMode" value="optin"'
        . ($mode === 'optin' ? ' checked' : '') . '> 允许用户按会话自行开启</label>'
        . '<p style="font-size:12px">开启后用户可在私聊里主动开启加密；只有双方都登记了设备才允许开启。'
        . '关闭后已有密文仍留在库里，但无法再解密（密钥只在用户设备上）。</p></div>';
    echo '<div class="ow-form-item"><label>&nbsp;</label>'
        . '<button class="ow-btn ow-btn-primary" id="owedSaveMode">保存</button></div>';
    echo '</div></div>';

    echo '<div class="ow-card"><h3 style="margin-top:0">现状</h3>'
        . '<p style="margin:0;line-height:1.9;font-size:13px">'
        . '已登记设备：<b>' . $devCnt . '</b> 台　|　'
        . '未消耗一次性预密钥：<b>' . $opkLeft . '</b> 枚　|　'
        . '已开启加密的会话：<b>' . $convCnt . '</b> 个　|　'
        . '已加密消息：<b>' . $msgCnt . '</b> 条</p></div>';

    echo '<div class="ow-card"><h3 style="margin-top:0">最近登记的设备</h3>';
    if (!$devs) {
        echo '<p style="font-size:13px;color:var(--ow-text-sub)">暂无</p>';
    } else {
        echo '<table class="ow-table"><thead><tr><th>用户</th><th>设备名</th><th>设备号</th><th>登记时间</th></tr></thead><tbody>';
        foreach ($devs as $d) {
            $nick = (string)($d['nickname'] ?? '') ?: ('用户' . (int)$d['user_id']);
            echo '<tr><td>' . htmlspecialchars($nick, ENT_QUOTES, 'UTF-8') . '（' . (int)$d['user_id'] . '）</td>'
                . '<td>' . htmlspecialchars((string)$d['dev_name'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td><code>' . htmlspecialchars(substr((string)$d['dev_id'], 0, 10), ENT_QUOTES, 'UTF-8') . '…</code></td>'
                . '<td>' . date('Y-m-d H:i', (int)$d['created_at']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
});

Plugin::route('plugin_e2ee_dm_setmode', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $m = (string)($ctx['post']['mode'] ?? 'off');
    DB::setSetting(OWED_MODE_KEY, $m === 'optin' ? 'optin' : 'off');
    Sec::log('e2ee_mode_set', (string)($ctx['actor']['nickname'] ?? ''), ['mode' => $m]);
    Api::json(['ok' => true, 'msg' => '已保存']);
});
Plugin::sensitive('plugin_e2ee_dm_setmode');

/* ===================== 资源 ===================== */

/**
 * 工具栏的「加密」开关。只在私聊里可见（chat.js 按当前会话切换 .on 与标题）。
 * 用盾牌图标：OW_ICONS 里没有 lock，硬塞一个未注册的图标名会渲染成空白按钮。
 */
Plugin::on('chat.toolbar', function (): string {
    if (owEDMode() === 'off') return '';
    return '<button class="ow-icon-btn owed-btn" id="owEDBtn" type="button"'
        . ' title="按会话开启加密" aria-label="按会话开启加密">'
        . ow_icon('shield', 16) . '</button>';
});

Plugin::asset('js', 'e2ee-dm/js/owce.js');
Plugin::asset('js', 'e2ee-dm/chat.js');
Plugin::asset('js', 'e2ee-dm/admin.js');
Plugin::asset('css', 'e2ee-dm/style.css');

Plugin::on('page.head', function () {
    $v = rawurlencode(OWLSGO_VERSION);
    echo '<link rel="stylesheet" href="?action=assets&type=css&plugin=e2ee-dm&file=style.css&v=' . $v . '">' . "\n";
});
