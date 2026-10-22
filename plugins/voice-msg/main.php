<?php
/**
 * 语音消息插件（voice-msg v1.0.0）
 *
 * ── 做什么 ────────────────────────────────────────────────────
 *   输入框工具栏新增「语音消息」按钮（chat.toolbar 钩子产出，停用插件即消失）。
 *   点击开始录音：实时波形 + 计时，可取消或发送；停止后自动上传，
 *   以语音气泡（播放按钮 + 波形 + 时长）进入聊天记录，点气泡即可播放。
 *
 * ── 录音实现：MediaRecorder 为主，WebAudio/WAV 兜底 ───────────
 *   · 路径 A（主）：navigator.mediaDevices.getUserMedia 拿麦克风流，
 *     交给 MediaRecorder。按 isTypeSupported 依次试探
 *     audio/webm;codecs=opus（Chrome 系）→ audio/mp4（Safari 14.1+）
 *     → audio/ogg;codecs=opus（Firefox）。三种容器现代浏览器都能直接播放，
 *     接收端浏览器负责解码，本插件不做任何转码。
 *   · 路径 B（兜底）：老浏览器没有 MediaRecorder（或没有任何受支持的
 *     MIME）时，用 AudioContext + ScriptProcessorNode 采原始 PCM，
 *     手动拼 44 字节 WAV 头封装成 audio/wav。零依赖、不挑容器，
 *     代价是体积大（16bit 单声道每分钟约 5 MB，故后台默认时长上限 60 秒）。
 *   · 两条路共用同一套实时波形（AnalyserNode）与同一套上传 / 发送链路。
 *
 * ── 实现要点（与核心的接缝）───────────────────────────────────
 *   · 消息形态：语音借道核心的 file 消息（Chat::send 的类型白名单只有
 *     text/image/file/system/rich，自定义类型会被降级成 text）——
 *     上传落盘、房间权限、禁言、频率限制、敏感词、过期清理全部复用核心
 *     那一套；语音的时长与波形存在插件自己的表里（plugin_voice_msg，
 *     按 msg_id 关联），聊天记录里伪造一句文件消息不会变出语音气泡
 *     （服务端只认自己的表，且按消息可见性过滤）。
 *   · 消息呈现：核心没有「消息内容渲染」钩子，语音气泡沿用项目既有做法
 *     （见 red-packet 的红包卡）——插件 chat.js 用 MutationObserver 扫
 *     .ow-msg 里的文件卡，批量问服务端「这些消息哪些是语音」，是就把
 *     文件卡换成气泡。播放地址取 uploads/ 下的静态文件（与图片消息同口径，
 *     哈希命名不可猜测）。
 *   · 停用插件：按钮与气泡消失，历史语音仍作为普通文件消息可下载
 *     （下载走核心的 file_download，与本插件无关）。
 *   · 样式只引用 --ow-* 变量，深浅两套主题自动跟随；移动端已适配。
 */
if (!defined('OWLSGO_VERSION')) exit;   // 禁止直接 HTTP 访问本文件

/* ============================ 建表 ============================ */

function owVMBoot(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    // 表结构版本闸门：每次聊天页加载都跑 2 条 CREATE + 索引探测太浪费。
    // 版本一致时只留一条 SELECT；顺手探一下主表在不在 —— 只带 settings
    // 的库还原会绕过建表，不能让插件带着空表跑。
    if (DB::setting('owvm_schema', '') === '1') {
        try {
            DB::val('SELECT id FROM plugin_voice_msg LIMIT 1');
            return;
        } catch (Throwable $e) { /* 表没了：往下重新建 */ }
    }

    $id = DB::autoId();

    // 语音记录：msg_id=0 表示「录完还没发出去」的 pending 行；
    // 波形 peaks 是最多 60 个 0~100 的整数（JSON），没有就退化成等宽波形。
    DB::run("CREATE TABLE IF NOT EXISTS plugin_voice_msg (
        id {$id},
        msg_id INT NOT NULL DEFAULT 0,
        owner_id INT NOT NULL DEFAULT 0,
        path VARCHAR(255) NOT NULL DEFAULT '',
        ext VARCHAR(8) NOT NULL DEFAULT '',
        size INT NOT NULL DEFAULT 0,
        dur INT NOT NULL DEFAULT 0,
        peaks " . DB::textCol('peaks') . ",
        created_at INT NOT NULL DEFAULT 0
    )");
    DB::createIndex('owvm_msg', 'plugin_voice_msg', 'msg_id');
    DB::createIndex('owvm_owner', 'plugin_voice_msg', 'owner_id,created_at');

    // 插件配置（k-v）：最长时长 / 大小上限
    DB::run("CREATE TABLE IF NOT EXISTS plugin_voice_msg_cfg (
        k VARCHAR(32) PRIMARY KEY,
        " . DB::textCol('v') . "
    )");

    DB::setSetting('owvm_schema', '1');
}
owVMBoot();   // 顶层建表是 PLUGIN.md 允许的副作用（与 red-packet 同做法）

/* ============================ 配置 ============================ */

function owVMCfg(string $k, string $def = ''): string
{
    $v = DB::val('SELECT v FROM plugin_voice_msg_cfg WHERE k=?', [$k]);
    return ($v === false || $v === null || $v === '') ? $def : (string)$v;
}

/** 最长录音时长（秒），10~600，默认 60 */
function owVMMaxDur(): int
{
    $v = (int)owVMCfg('max_dur', '60');
    return max(10, min(600, $v > 0 ? $v : 60));
}

/** 单条语音大小上限（字节），1~20MB，默认 10 */
function owVMMaxBytes(): int
{
    $v = (int)owVMCfg('max_mb', '10');
    return max(1, min(20, $v > 0 ? $v : 10)) * 1048576;
}

/* ============================ 音频格式白名单 ============================
 * 浏览器能录出来的容器就这四种：Chrome 系 webm、Firefox ogg、Safari mp4、
 * 老浏览器兜底 wav。真实 MIME 用 finfo 读，**不信客户端声明的 type** ——
 * 把 .wav 改名成 .webm 之类的伪装会被挡下。 */
function owVMExtMime(): array
{
    return [
        'webm' => ['audio/webm', 'video/webm'],
        'ogg'  => ['audio/ogg', 'application/ogg'],
        'mp4'  => ['audio/mp4', 'video/mp4'],
        'wav'  => ['audio/wav', 'audio/x-wav', 'audio/vnd.wave'],
    ];
}

function owVMRealMime(string $path): string
{
    if (class_exists('finfo')) {
        $f = new finfo(FILEINFO_MIME_TYPE);
        $m = $f->file($path);
        if (is_string($m) && $m !== '') return strtolower(trim($m));
    }
    return 'application/octet-stream';
}

/** 真实 MIME → 扩展名（不在白名单里返回空串） */
function owVMExtOfMime(string $mime): string
{
    foreach (owVMExtMime() as $ext => $list) {
        if (in_array($mime, $list, true)) return $ext;
    }
    return '';
}

/** 波形数组归一化：JSON 串 → 最多 60 个 0~100 的整数；不合法返回空串 */
function owVMNormPeaks(string $raw): string
{
    $arr = json_decode($raw, true);
    // 只认 JSON 数组（list）：对象 / 标量 / 垃圾串一律当没有波形，
    // 前端届时退化成等宽波形，不影响功能
    if (!is_array($arr) || !$arr || !array_is_list($arr)) return '';
    $out = [];
    foreach ($arr as $p) {
        if (!is_numeric($p)) continue;
        $out[] = max(0, min(100, (int)$p));
        if (count($out) >= 60) break;
    }
    return $out ? json_encode($out, JSON_UNESCAPED_UNICODE) : '';
}

/**
 * 还有没有消息在引用这个音频文件（删除前的必要一问）。
 * 文件按内容哈希命名、可能被多条消息共用（同一段录音发给两个人），
 * 不查就删会把别人消息里的语音抽走。匹配「去掉扩展名的文件名主体」，
 * 避开 JSON 里 `\/` 转义与完整路径前缀问题（与 attachment-manager
 * 删除前的引用计数同一口径，实测过的写法）。
 */
function owVMRefCount(string $path): int
{
    $base = basename(str_replace('\\', '/', $path));
    $stem = preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', $base) ?: $base;
    if (!preg_match('/^[A-Za-z0-9_\-]{6,80}$/', $stem)) return 1;   // 形状不对 → 保守当作有人用
    return (int)DB::val("SELECT COUNT(*) FROM messages WHERE type='file' AND content LIKE ?", ['%/' . $stem . '.%']);
}

/* ============================ 工具栏入口 / 样式 ============================ */

/**
 * 「语音消息」按钮：HTML 由服务端产出，核心 Plugin::collect('chat.toolbar')
 * 拼进 #owToolFirst。点击与录音面板仍由 chat.js 绑定（插件停用 → 钩子
 * 不注册 → 按钮不存在，前端安静退出）。
 * 图标是插件自有字形（麦克风），path 只存在于本文件这一处。
 */
Plugin::on('chat.toolbar', function (): string {
    $svg = '<svg class="ow-ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
         . ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . '<rect x="9" y="2.5" width="6" height="11.5" rx="3"/>'
         . '<path d="M5.5 10.5a6.5 6.5 0 0 0 13 0"/>'
         . '<line x1="12" y1="17" x2="12" y2="20.5"/>'
         . '<line x1="8" y1="20.5" x2="16" y2="20.5"/></svg>';
    return '<button class="ow-icon-btn" id="owBtnVoice" type="button" title="语音消息" aria-label="语音消息">'
         . $svg . '</button>';
});

// ⚠️ v1.5.31 起下面这段已作废：核心 pageHead 统一引整包 CSS，插件不再自己发切片（保留作历史）
/**
 * 样式表必须自己发 <link>：核心只引合并 JS，?action=assets&type=css
 * 没有任何页面引用它（与 red-packet / attachment-manager 同款注释）。
 * 只取本插件那一份，避免依赖别的插件是否恰好整包输出。
 */
// v1.5.31：CSS 不再由插件自己发切片 —— 核心 pageHead 统一引整包
// （整包本就包含本插件的样式；此前每个插件各发一条，首屏变成 16 个串行 CSS 请求）

Plugin::asset('css', 'voice-msg/style.css');   // 录音面板 + 语音气泡
Plugin::asset('js', 'voice-msg/chat.js');      // 前台：录音 + 扫消息 + 播放（后台页自动退出）
Plugin::asset('js', 'voice-msg/admin.js');     // 后台：规则表单

/* ============================ API 路由 ============================ */

/** 公开配置：最长时长 / 大小上限（前台自动停止与提示用；非敏感值，不加权限） */
Plugin::route('plugin_voice_msg_conf', function (array $ctx) {
    Api::json([
        'ok' => true,
        'max_dur' => owVMMaxDur(),
        'max_mb' => (int)round(owVMMaxBytes() / 1048576),
    ]);
});

/**
 * 录音上传：落盘 + 建 pending 行（msg_id=0，发送时才绑定）。
 * 文件走核心 Upload::storeFile（内容哈希命名 + 天然去重，
 * 同一段录音发给两个人也只存一份）。
 */
Plugin::route('plugin_voice_msg_upload', function (array $ctx) {
    $actor = $ctx['actor'];
    if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '请先登录后再录音'], 403);
    if (empty($ctx['files']['file'])) Api::json(['ok' => false, 'msg' => '未接收到音频'], 400);
    if (!Sec::rateLimit('voice_upload', (int)$actor['id'], 60, 12)) {
        Api::json(['ok' => false, 'msg' => '录音上传太频繁，请稍后再试'], 429);
    }
    $f = $ctx['files']['file'];
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) Api::json(['ok' => false, 'msg' => '上传失败（错误码 ' . $err . '）'], 400);
    $tmp = (string)($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) Api::json(['ok' => false, 'msg' => '临时文件不可读'], 400);

    $max  = owVMMaxBytes();
    $size = (int)@filesize($tmp);
    if ($size <= 0) Api::json(['ok' => false, 'msg' => '录音内容为空'], 400);
    if ($size > $max) Api::json(['ok' => false, 'msg' => '录音超过 ' . round($max / 1048576) . ' MB 限制'], 400);

    $mime = owVMRealMime($tmp);
    $ext  = owVMExtOfMime($mime);
    if ($ext === '') Api::json(['ok' => false, 'msg' => '不支持的音频格式（' . $mime . '）'], 400);

    [$ok, $rel] = Upload::storeFile($tmp, $ext);
    if (!$ok) Api::json(['ok' => false, 'msg' => '保存失败'], 400);

    // 时长与波形是展示用元数据：时长夹在后台上限内，波形限长后归一化
    $dur   = max(0, min(owVMMaxDur(), (int)($ctx['post']['dur'] ?? 0)));
    $peaks = owVMNormPeaks(mb_substr((string)($ctx['post']['peaks'] ?? ''), 0, 4000));

    DB::insert('plugin_voice_msg', [
        'msg_id' => 0,
        'owner_id' => (int)$actor['id'],
        'path' => $rel,
        'ext' => $ext,
        'size' => $size,
        'dur' => $dur,
        'peaks' => $peaks,
        'created_at' => time(),
    ]);
    Sec::log('voice_upload', (string)($actor['nickname'] ?? ''), ['size' => $size, 'ext' => $ext, 'dur' => $dur]);
    Api::json(['ok' => true, 'path' => $rel, 'ext' => $ext, 'size' => $size]);
});

/**
 * 发送语音：把刚上传的 pending 录音发成 file 消息，并绑定 msg_id。
 * 走核心 Chat::send —— 成员 / 禁言 / 敏感词 / 频率限制一套校验全部复用，
 * 失败即整单失败（pending 行留给维护任务清理，不会出现「有文件没消息」的
 * 展示错位——没有 msg_id 的记录永远不会被升级成气泡）。
 */
Plugin::route('plugin_voice_msg_send', function (array $ctx) {
    $actor = $ctx['actor'];
    if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '请先登录'], 403);
    $post = $ctx['post'];

    $path = (string)($post['path'] ?? '');
    if (!preg_match('#^file/[A-Za-z0-9_\-./]+$#', $path)) {
        Api::json(['ok' => false, 'msg' => '录音不存在，请重新录制'], 400);
    }
    // 只能发「自己上传的、还没绑定消息的」录音，且 1 小时内有效
    $v = DB::one('SELECT * FROM plugin_voice_msg WHERE path=? AND owner_id=? AND msg_id=0', [$path, (int)$actor['id']]);
    if (!$v || time() - (int)$v['created_at'] > 3600) {
        Api::json(['ok' => false, 'msg' => '录音已过期，请重新录制'], 400);
    }

    $roomId   = (int)($post['room_id'] ?? 0);
    $toUserId = (int)($post['to_user_id'] ?? 0);
    $toNick   = '';
    if ($roomId === 0) {
        if ($toUserId <= 0) Api::json(['ok' => false, 'msg' => '缺少接收对象'], 400);
        $peer = DB::one('SELECT id, nickname FROM users WHERE id=? AND status=1', [$toUserId]);
        if (!$peer) Api::json(['ok' => false, 'msg' => '对方不存在或已被禁用'], 400);
        $toNick = (string)$peer['nickname'];
    }

    $content = json_encode([
        'name' => '语音消息',
        'size' => (int)$v['size'],
        'ext'  => (string)$v['ext'],
        'path' => $path,
    ], JSON_UNESCAPED_UNICODE);

    try {
        $res = Chat::send($actor, $roomId, 'file', $content, [
            'to_user_id'  => $roomId === 0 ? $toUserId : '',
            'to_nickname' => $toNick,
            'client_id'   => 'vm' . (int)$v['id'] . 'x' . bin2hex(random_bytes(4)),
        ]);
    } catch (Throwable $e) {
        $res = [false, '发送失败，请稍后再试'];
    }
    if (empty($res[0])) {
        Api::json(['ok' => false, 'msg' => '语音发送失败：' . (string)($res[1] ?? '请稍后再试')], 400);
    }
    $msgId = (int)($res[2] ?? 0);
    if ($msgId > 0) {
        DB::run('UPDATE plugin_voice_msg SET msg_id=? WHERE id=? AND msg_id=0', [$msgId, (int)$v['id']]);
    }
    // 回传 pack 后的完整消息行：前端立即本地回显（与 red-packet 同口径）
    Api::json(['ok' => true, 'msg' => '语音已发送', 'msg_row' => $res[3] ?? null]);
});

/**
 * 一条语音记录 → 前端气泡要用的数据；不可见返回 null。
 * 群聊走 roomAccessOk，私聊走「是不是本人 / 对方」—— 消息 ID 连号，
 * 不校验可见性就等于给了「猜别人语音」的接口。
 */
function owVMCard(array $v, array $actor, int $uid): ?array
{
    $m = DB::one('SELECT id, room_id, user_id, to_user_id FROM messages WHERE id=?', [(int)$v['msg_id']]);
    if (!$m) return null;   // 消息没了（过期清理 / 删除）→ 不当成语音
    if ((int)$m['room_id'] === 0) {
        $visible = $uid > 0 && ($uid === (int)$m['user_id'] || $uid === (int)$m['to_user_id']);
    } else {
        $room = Chat::room((int)$m['room_id']);
        $visible = $room && Chat::roomAccessOk($room, $actor);
    }
    if (!$visible) return null;
    $peaks = [];
    $pj = json_decode((string)$v['peaks'], true);
    if (is_array($pj)) {
        foreach ($pj as $p) {
            $peaks[] = max(0, min(100, (int)$p));
            if (count($peaks) >= 60) break;
        }
    }
    return [
        // 与图片消息同口径：手动配置了「固定网站地址」才给绝对地址
        'url'   => ow_abs_url('uploads/' . (string)$v['path'], true),
        'ext'   => (string)$v['ext'],
        'dur'   => max(0, (int)$v['dur']),
        'peaks' => $peaks,
        'mine'  => $uid > 0 && $uid === (int)$m['user_id'],
    ];
}

/**
 * 批量问「这些消息哪些是语音」（前端扫到新消息后调用；最多 60 条一批）。
 */
Plugin::route('plugin_voice_msg_cards', function (array $ctx) {
    $actor = $ctx['actor'];
    $uid = ($actor['kind'] ?? '') === 'user' ? (int)($actor['id'] ?? 0) : 0;
    $ids = [];
    foreach (explode(',', (string)($ctx['post']['ids'] ?? '')) as $s) {
        $n = (int)trim($s);
        if ($n > 0) $ids[] = $n;
        if (count($ids) >= 60) break;
    }
    if (!$ids) Api::json(['ok' => true, 'items' => []]);

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rows = DB::all("SELECT * FROM plugin_voice_msg WHERE msg_id IN ($ph)", $ids);
    $items = [];
    foreach ($rows as $v) {
        $card = owVMCard($v, $actor, $uid);
        if ($card) $items[(string)(int)$v['msg_id']] = $card;
    }
    Api::json(['ok' => true, 'items' => $items]);
});

/**
 * 首屏预取：按会话取最近一页的语音清单（v1.0.1）。
 *
 * 为什么需要它：核心没有「消息内容渲染」钩子，插件只能在消息**已经渲染成文件卡
 * 之后**才认得出它是不是语音，再回头问 cards 接口 —— 实测刷新页面后这中间隔着
 * 约 1.4 秒，30 条语音会整屏先显示成附件文件卡再逐个变成语音气泡（首屏闪烁）。
 *
 * 这里的做法是把「认语音」提前到首屏渲染**之前**：前端在进入群聊/私聊时先拿一份
 * 清单进内存，消息渲染出来时直接查内存决定渲染成什么 → 气泡与消息同帧出现。
 * cards 接口仍然保留：预取只覆盖首屏这一页，之后的新消息仍走增量（并顺手把
 * 结果并进内存，清单会随使用变全）。
 *
 * 可见性口径与 cards 完全一致（都走 owVMCard）。
 */
Plugin::route('plugin_voice_msg_prefetch', function (array $ctx) {
    $actor = $ctx['actor'];
    $uid = ($actor['kind'] ?? '') === 'user' ? (int)($actor['id'] ?? 0) : 0;
    $post = $ctx['post'];
    $limit = max(1, min(60, (int)($post['limit'] ?? 30)));

    // 私聊：to_user_id 是**对方**。自己发给自己不是真实场景，退化成空而不是误取。
    $peer = (int)($post['to_user_id'] ?? 0);
    if ($peer > 0) {
        if ($uid <= 0) Api::json(['ok' => false, 'msg' => '请先登录'], 403);
        if ($uid === $peer) Api::json(['ok' => true, 'items' => []]);
        // 私聊要能发出去的前提就是好友（核心 Chat::send 同口径），不是好友不给清单
        if (!Chat::areFriends($uid, $peer)) {
            Api::json(['ok' => true, 'items' => []]);
        }
        // 私聊：只看「我与对方之间」的消息（要么我发的、要么对方发给我的）
        $rows = DB::all(
            "SELECT v.* FROM plugin_voice_msg v
               JOIN messages m ON m.id = v.msg_id
              WHERE v.msg_id > 0 AND m.room_id = 0
                AND ((m.user_id = ? AND m.to_user_id = ?)
                  OR (m.user_id = ? AND m.to_user_id = ?))
           ORDER BY v.msg_id DESC LIMIT $limit",
            [$uid, $peer, $peer, $uid]
        );
        $rows = array_reverse($rows);
    } else {
        $roomId = (int)($post['room_id'] ?? 0);
        if ($roomId <= 0) Api::json(['ok' => true, 'items' => []]);
        $room = Chat::room($roomId);
        if (!$room || !Chat::roomAccessOk($room, $actor)) {
            Api::json(['ok' => true, 'items' => []]);   // 进不去：不给（与 cards 同口径）
        }
        $rows = DB::all(
            "SELECT v.* FROM plugin_voice_msg v
               JOIN messages m ON m.id = v.msg_id
              WHERE v.msg_id > 0 AND m.room_id = ?
           ORDER BY v.msg_id DESC LIMIT $limit",
            [$roomId]
        );
        $rows = array_reverse($rows);
    }

    $items = [];
    foreach ($rows as $v) {
        $card = owVMCard($v, $actor, $uid);
        if ($card) $items[(string)(int)$v['msg_id']] = $card;
    }
    Api::json(['ok' => true, 'items' => $items]);
});

/* ============================ 后台 ============================ */

/** 管理员鉴权：插件路由的公共守卫（须定义在所有 use 它的闭包之前） */
$vmGuard = function (array $ctx): void {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
};

Plugin::route('plugin_voice_msg_admin_save', function (array $ctx) use ($vmGuard) {
    $vmGuard($ctx);
    $dur = max(10, min(600, (int)($ctx['post']['max_dur'] ?? 60)));
    $mb  = max(1, min(20, (int)($ctx['post']['max_mb'] ?? 10)));
    DB::upsert('plugin_voice_msg_cfg', ['k' => 'max_dur', 'v' => (string)$dur], ['k']);
    DB::upsert('plugin_voice_msg_cfg', ['k' => 'max_mb', 'v' => (string)$mb], ['k']);
    Api::json(['ok' => true, 'msg' => '已保存', 'max_dur' => $dur, 'max_mb' => $mb]);
});

Plugin::adminPage('voice-msg', '语音消息', function () {
    return '<h2>语音消息</h2>'
       . '<p class="ow-admin-desc">聊天工具栏里的麦克风入口：点击开始录音，实时波形与计时，可取消或发送；'
       . '语音以文件消息的形式进入聊天记录，任何现代浏览器都能直接播放。插件停用后入口消失，'
       . '历史语音仍可作为普通文件下载。</p>'
       . '<div class="ow-card"><div class="ow-list-head"><span class="ow-list-head-t">录音规则</span></div>'
       . '<div class="ow-form-row">'
       . '<div class="ow-form-item" style="min-width:150px"><label>最长时长（秒）</label>'
       . '<input class="ow-input" id="owvmMaxDur" type="number" min="10" max="600" value="' . owVMMaxDur() . '">'
       . '<p style="font-size:12px">超过这个时长会自动停止并发送。老浏览器走 WAV 兜底，体积随时长线性增长，不宜设得过大。</p></div>'
       . '<div class="ow-form-item" style="min-width:150px"><label>大小上限（MB）</label>'
       . '<input class="ow-input" id="owvmMaxMb" type="number" min="1" max="20" value="' . (int)round(owVMMaxBytes() / 1048576) . '">'
       . '<p style="font-size:12px">单条语音的体积上限。Opus 录音 1 分钟约 0.2 MB，WAV 兜底约 5 MB，默认 10 MB 两者都够。</p></div>'
       . '<div class="ow-form-item" style="min-width:110px"><label>&nbsp;</label>'
       . '<button class="ow-btn ow-btn-primary" type="button" onclick="OwVMAdmin.save()">保存</button></div>'
       . '</div></div>';
});

/* ============================ 计划任务 ============================ */

/**
 * 每小时：① 清「上传超过 1 小时还没发出去」的 pending 行（录完取消 /
 *    发送失败留下的），文件确认没有消息引用就连文件一起删；
 *    ② 清消息已被删除的孤儿行（消息没了，气泡也无从升级）。
 */
Plugin::cron('gc', 3600, function (): string {
    $now = time();
    $rows = 0; $files = 0;

    // ① 孤儿行：消息已不存在
    $orphans = DB::all(
        "SELECT v.id, v.path FROM plugin_voice_msg v
          LEFT JOIN messages m ON m.id = v.msg_id
         WHERE v.msg_id > 0 AND m.id IS NULL
         LIMIT 300"
    );
    // ② pending 超时：上传 1 小时仍未绑定到任何消息
    $stale = DB::all(
        'SELECT id, path FROM plugin_voice_msg WHERE msg_id = 0 AND created_at < ? LIMIT 300',
        [$now - 3600]
    );
    $all = array_merge($orphans, $stale);
    if (!$all) return '';
    // v1.5.20：批量删行；文件引用改为一次扫回再判定，不再逐行 COUNT + DELETE
    $delIds = [];
    $paths = [];
    foreach ($all as $v) {
        $delIds[] = (int)$v['id'];
        $p = (string)($v['path'] ?? '');
        if ($p !== '') $paths[$p] = true;
    }
    foreach (array_chunk($delIds, 200) as $chunk) {
        [$inSql, $inArgs] = DB::in('id', $chunk);
        DB::run("DELETE FROM plugin_voice_msg WHERE $inSql", $inArgs);
    }
    $rows = count($delIds);

    // 文件可能被别的消息共用（同一段录音发给两个人）：没别人用了才真删
    $stems = []; $stemOf = []; $keep = [];
    foreach (array_keys($paths) as $rel) {
        $base = basename(str_replace('\\', '/', $rel));
        $s = (string)preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', $base);
        if ($s !== '' && preg_match('/^[A-Za-z0-9_\-]{6,80}$/', $s)) {
            $stemOf[$rel] = $s; $stems[$s] = 1;
        } else {
            $keep[$rel] = true;   // 形状不对 → 保守当作有人用（与旧逻辑一致）
        }
    }
    $alive = [];
    if ($stems) {
        foreach (array_chunk(array_keys($stems), 50) as $chunk) {
            $ors = []; $binds = [];
            foreach ($chunk as $s) { $ors[] = 'content LIKE ?'; $binds[] = '%/' . $s . '.%'; }
            foreach (DB::all("SELECT content FROM messages WHERE type='file' AND (" . implode(' OR ', $ors) . ')', $binds) as $r) {
                $c = (string)$r['content'];
                foreach ($chunk as $s) { if (strpos($c, '/' . $s . '.') !== false) $alive[$s] = 1; }
            }
        }
    }
    foreach (array_keys($paths) as $rel) {
        if (isset($keep[$rel]) || isset($alive[$stemOf[$rel] ?? ''])) continue;
        $abs = Upload::fileAbs($rel);
        if ($abs && @unlink($abs)) $files++;
    }
    return '清理语音记录 ' . $rows . ' 条' . ($files > 0 ? '，删除文件 ' . $files . ' 个' : '');
}, '清理超时未发送的语音、消息已删除的孤儿记录及无人引用的音频文件（每小时一次）');
