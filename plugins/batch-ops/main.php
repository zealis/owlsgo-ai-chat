<?php
/**
 * 批量操作插件（batch-ops v1.2.0）
 *
 * ── 做什么 ────────────────────────────────────────────────────
 * 一个「选择目标」通用轮子 + 消息多选能力，当前接了两种业务：
 *   A. 消息多选：消息右键「多选」（或「转发」单条直达）进入选择模式，
 *      消息区底部操作条：逐条转发 / 合并转发 / 保存至电脑 / 收藏 / 删除 / 复制。
 *      逐条转发 = 对目标逐条原样重发；合并转发 = 生成「聊天记录」卡片
 *      （站内快照 + 弹窗查看，仿微信记录形态，见下）。
 *   B. 邀请好友进群：群聊信息面板「邀请好友」打开同一个选择器（verb=邀请），
 *      逐行调用核心 room_invite；底部附群聊邀请链接一键复制。
 *
 * ── 聊天记录快照（v1.2.0 新增，本插件唯一落库的形态） ──────────
 * 合并转发不能把 N 条塞进 2000 字的正文（且正文过滤/长度都不合适），
 * 做法与微信一致：**发送时在服务端做一份快照**（消息行按入选顺序复制进
 * plugin_batch_ops_record.items），聊天里只发一条极短的引用消息
 * `[聊天记录]#<token>#`，前端把这条消息渲染成卡片，点击弹窗看完整记录。
 *   · 快照也是权限边界：只复制「发送者此刻有权看到」的消息（群走 roomAccessOk、
 *     私聊走参与方判定），此后原消息撤回不影响已发出的记录（与微信同语义，
 *     快照在转发那一刻定格 —— 用户能截图的东西，不能假装它不存在）；
 *   · 记录按不可猜测的 32 位 hex token 寻址，持 token 即可读（记录本身就是
 *     一条已发消息的内容，与消息可见性一致）；
 *   · 90 天自动清理（Plugin::cron），过期卡片显示「已过期」。
 *
 * ── 为什么其余部分服务端是空的 ────────────────────────────────
 * 逐条转发 = 逐条调核心 send（敏感词、禁言、好友关系、群成员校验、
 *   发言频率限制全部由核心保证）；邀请 = 核心 room_invite；
 *   列表数据 = 核心 conversations / friends / rooms / room_members。
 *
 * ── 已知边界 ──────────────────────────────────────────────────
 * 逐条转发的节奏受核心「发言频率限制」约束（默认 10 秒 8 条），
 * 数量多时前端队列会自动等待窗口重置并显示进度（见 chat.js 的转发队列）。
 * 记录快照里文件类消息只带原消息 id（下载仍走核心 file_download 权限），
 * 图片/混排按原 content 保真。
 */
if (!defined('OWLSGO_VERSION')) exit;   // 禁止直接 HTTP 访问本文件

/* ============================ 建表 ============================ */

/*
 * ⚠️ DB::textCol() 返回的是**完整列定义（含列名）**，前面不能再写一遍列名。
 *   这里犯过一次（v1.2.6 修）：`items  " . DB::textCol('items')` 生成
 *   `items  items TEXT NULL` —— SQLite 当成「列名 + 多词类型名」容忍了（本地一直绿灯），
 *   MySQL 直接 1064（link-card v1.2.1 / time-machine v1.1.1 是同一个坑）。
 *   后果：合并转发第一次落快照时建表失败 → 该路由 500。长文本列照下表的写法。
 */
/** 懒建表：聊天记录快照（只在真正读写记录时执行） */
function owbBoot(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $id = DB::autoId();
    DB::run("CREATE TABLE IF NOT EXISTS plugin_batch_ops_record (
        id         {$id},
        token      CHAR(32)     NOT NULL DEFAULT '',   -- 卡片寻址用（32 位 hex，不可猜测）
        title      VARCHAR(64)  NOT NULL DEFAULT '',   -- 卡片/弹窗标题（群聊的聊天记录 / 聊天记录）
        src        VARCHAR(64)  NOT NULL DEFAULT '',   -- 来源名（群名/对方昵称，存档说明用）
        cnt        INTEGER      NOT NULL DEFAULT 0,    -- 快照条数
        " . DB::textCol('items') . ",                  -- JSON：消息行子集（见 owbSnapshotRow）
        created_at INTEGER      NOT NULL DEFAULT 0
    )");
    DB::createIndex('idx_owb_rec_token', 'plugin_batch_ops_record', 'token', true);
}

/* ============================ 快照助手 ============================ */

/** 快照一行的可读预览文本（卡片上的前几行 / 别的记录里再引用它时的占位） */
function owbPreview(array $it): string
{
    $ty = (string)($it['type'] ?? '');
    $s = '';
    if ($ty === 'image') {
        $s = '[图片]';
    } elseif ($ty === 'file') {
        $j = json_decode((string)($it['content'] ?? ''), true);
        $s = '[文件] ' . (is_array($j) ? (string)($j['name'] ?? '') : '');
    } elseif ($ty === 'rich') {
        $j = json_decode((string)($it['content'] ?? ''), true);
        $t = is_array($j) ? trim((string)($j['text'] ?? '')) : '';
        $s = $t !== '' ? $t . ' [图片]' : '[图片]';
    } else {
        $s = (string)($it['content'] ?? '');
    }
    // 记录里嵌记录：预览只给占位，不把 token 再吐出来（弹窗里是可点的）。
    if (preg_match('/^\[聊天记录\]#[a-f0-9]{32}#$/', trim($s))) $s = '[聊天记录]';
    $s = trim((string)preg_replace('/\s+/u', ' ', $s));
    return mb_substr($s, 0, 60);
}

/* ============================ API 路由 ============================ */

/**
 * 生成聊天记录快照（合并转发第一步）。入参：ids（逗号分隔的消息 id）。
 * 逐条校验「此刻本人有权看到」：群消息过 roomAccessOk，私聊消息必须是参与方；
 * 系统消息 / 已撤回 / 已删除 / 空正文一律跳过。返回 token + 卡片预览。
 */
Plugin::route('plugin_batch_ops_record_save', function (array $ctx): void {
    $actor = $ctx['actor'] ?? [];
    $p = $ctx['post'] ?? [];
    if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '请先登录后再合并转发'], 403);
    owbBoot();
    // 写库接口，限速不能缺席（每次最多 100 条快照）
    if (!Sec::rateLimit('owb_rec_save', 'u' . (int)$actor['id'], 60, 10)) {
        Api::json(['ok' => false, 'msg' => '操作过于频繁，请稍后再试'], 429);
    }

    $ids = [];
    foreach (explode(',', (string)($p['ids'] ?? '')) as $v) {
        $v = (int)trim($v);
        if ($v > 0 && !in_array($v, $ids, true)) $ids[] = $v;
        if (count($ids) >= 100) break;
    }
    if (!$ids) Api::json(['ok' => false, 'msg' => '没有可合并的消息'], 400);

    $items = [];
    $roomCache = [];
    $srcRid = 0;
    $srcPeerUid = 0;
    // v1.5.20：一次取回所选消息，别在 foreach 里逐条 SELECT（最多 100 条 = 100 次查询）
    $msgMap = [];
    [$msgIn, $msgInArgs] = DB::in('id', $ids);
    foreach (DB::all("SELECT * FROM messages WHERE $msgIn", $msgInArgs) as $row) {
        $msgMap[(int)$row['id']] = $row;
    }
    foreach ($ids as $mid) {
        $m = $msgMap[$mid] ?? null;
        if (!$m) continue;
        if ((int)$m['recalled'] === 1 || (int)$m['deleted'] === 1) continue;
        $ty = (string)$m['type'];
        if ($ty === 'system' || $ty === 'private') continue;   // private = 旧版私聊形态
        if ((string)$m['content'] === '') continue;

        $rid = (int)$m['room_id'];
        if ($rid > 0) {
            if (!isset($roomCache[$rid])) $roomCache[$rid] = Chat::room($rid);
            $room = $roomCache[$rid];
            if (!$room || !Chat::roomAccessOk($room, $actor)) continue;
        } else {
            $uid = (int)$actor['id'];
            if ((int)$m['user_id'] !== $uid && (int)$m['to_user_id'] !== $uid) continue;
            if ($srcRid === 0) $srcPeerUid = (int)$m['user_id'] === $uid ? (int)$m['to_user_id'] : (int)$m['user_id'];
        }
        if ($srcRid === 0) $srcRid = $rid;

        // 引用：保留昵称与文本，丢原消息 id（记录里的引用不做跳转 —— 看的人多半不在原会话）
        $quote = '';
        if ((string)($m['quote'] ?? '') !== '') {
            $q = json_decode((string)$m['quote'], true);
            if (is_array($q) && ((string)($q['nick'] ?? '') !== '' || (string)($q['text'] ?? '') !== '')) {
                $quote = (string)json_encode([
                    'nick' => mb_substr((string)($q['nick'] ?? ''), 0, 40),
                    'text' => mb_substr((string)($q['text'] ?? ''), 0, 120),
                ], JSON_UNESCAPED_UNICODE);
            }
        }
        $items[] = [
            'id' => (int)$m['id'],                    // 保留原消息 id：文件类下载仍走核心权限校验
            'type' => $ty,
            'content' => (string)$m['content'],
            'nickname' => (string)$m['nickname'],
            'role' => (string)$m['role'],
            'title' => (string)($m['title'] ?? ''),
            'avatar' => (string)($m['avatar'] ?? ''),
            'uid' => (int)($m['user_id'] ?? 0),
            'quote' => $quote,
            'ts' => (int)$m['created_at'],
        ];
    }
    if (!$items) Api::json(['ok' => false, 'msg' => '所选消息已失效或无权转发'], 400);

    if ($srcRid > 0) {
        $room = $roomCache[$srcRid] ?? Chat::room($srcRid);
        $title = '群聊的聊天记录';
        $src = (string)($room['name'] ?? '');
    } else {
        $title = '聊天记录';
        $peer = $srcPeerUid > 0 ? DB::one('SELECT nickname FROM users WHERE id=?', [$srcPeerUid]) : null;
        $src = (string)($peer['nickname'] ?? '');
    }

    $token = bin2hex(random_bytes(16));   // 32 位 hex：不可猜测，持 token 即读（与消息可见性同权）
    DB::insert('plugin_batch_ops_record', [
        'token' => $token,
        'title' => $title,
        'src' => mb_substr($src, 0, 64),
        'cnt' => count($items),
        'items' => (string)json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'created_at' => time(),
    ]);

    $preview = [];
    foreach (array_slice($items, 0, 4) as $it) {
        $preview[] = ['nick' => (string)$it['nickname'], 'text' => owbPreview($it)];
    }
    Api::json(['ok' => true, 'token' => $token, 'count' => count($items), 'title' => $title, 'preview' => $preview]);
});

/**
 * 读取聊天记录：tokens（逗号分隔，≤20）。单 token（点开卡片）带完整条目，
 * 多 token（一页多张卡的预取）只回标题/条数/前 4 行预览。
 * 返回 {token: {title, count, preview, items?}}；token 不存在返回 null → 前端显示「已过期」。
 */
Plugin::route('plugin_batch_ops_record_get', function (array $ctx): void {
    $actor = $ctx['actor'] ?? [];
    $p = $ctx['post'] ?? [];
    owbBoot();
    if (!Sec::rateLimit('owb_rec_get', ($actor['kind'] ?? 'n') . (int)($actor['id'] ?? 0), 60, 60)) {
        Api::json(['ok' => false, 'msg' => '读取过于频繁，请稍后再试'], 429);
    }
    $tokens = [];
    foreach (explode(',', (string)($p['tokens'] ?? '')) as $t) {
        $t = trim($t);
        if (preg_match('/^[a-f0-9]{32}$/', $t) && !in_array($t, $tokens, true)) $tokens[] = $t;
        if (count($tokens) >= 20) break;
    }
    /* v1.2.3：**请求形状与「卡片预取」完全一致**（只带 tokens，不再区分 full）。
       单 token（= 点开某张卡）直接带完整条目，多 token 批量（= 一页多张卡的预取）
       只回预览。站方反馈「点击弹窗请求 100% 失败、预取 100% 成功」后这样收口：
       点击走的就是那条已验证可用的请求形状。旧页面传来的 full=1 仍然认（兼容），
       新客户端不再发送。 */
    $withItems = count($tokens) === 1 || (string)($p['full'] ?? '') === '1';
    // v1.5.20：预取这一页所有 token 对应的记录，别逐个 token 查
    $recMap = [];
    if ($tokens) {
        [$tokIn, $tokArgs] = DB::in('token', $tokens);
        foreach (DB::all("SELECT * FROM plugin_batch_ops_record WHERE $tokIn", $tokArgs) as $row) {
            $recMap[(string)$row['token']] = $row;
        }
    }
    $out = [];
    foreach ($tokens as $t) {
        $row = $recMap[$t] ?? null;
        if (!$row) { $out[$t] = null; continue; }
        $items = json_decode((string)$row['items'], true);
        if (!is_array($items)) $items = [];
        $preview = [];
        foreach (array_slice($items, 0, 4) as $it) {
            if (is_array($it)) $preview[] = ['nick' => (string)($it['nickname'] ?? ''), 'text' => owbPreview($it)];
        }
        $entry = [
            'title' => (string)$row['title'],
            'count' => (int)$row['cnt'],
            'preview' => $preview,
        ];
        if ($withItems) $entry['items'] = array_slice($items, 0, 200);
        $out[$t] = $entry;
    }
    Api::json(['ok' => true, 'data' => $out]);
});

/* ============================ 计划任务 ============================ */

/** 记录快照保留 90 天（每天一次；过期后卡片显示「聊天记录已过期或不存在」） */
Plugin::cron('record_purge', 86400, function () {
    owbBoot();
    $threshold = time() - 90 * 86400;
    $cnt = (int)DB::val('SELECT COUNT(*) FROM plugin_batch_ops_record WHERE created_at<?', [$threshold]);
    if ($cnt > 0) DB::run('DELETE FROM plugin_batch_ops_record WHERE created_at<?', [$threshold]);
    return $cnt;
}, '清理批量操作插件的聊天记录快照（90 天前，每天一次）');

/* ============================ 静态资源 ============================ */

Plugin::asset('js', 'batch-ops/chat.js');
Plugin::asset('css', 'batch-ops/style.css');

/**
 * 核心不会自动在页面里引入插件合并 CSS（只自动引合并 JS），
 * 需在 page.head 自行输出 —— 与 link-card / captcha-verify 等现行插件同一做法。
 * 本插件的样式只服务聊天页交互，但注入条件保持无条件：多几 KB 静态文件，
 * 换取「任何页面动态弹出的选择器都不会裸奔」。
 */
Plugin::on('page.head', function (): void {
    echo '<link rel="stylesheet" href="?action=assets&type=css&plugin=batch-ops&file=style.css&v='
        . OWLSGO_ASSET_VERSION . '">' . "\n";
});
