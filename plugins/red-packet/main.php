<?php
/**
 * 红包插件（red-packet v1.0.0）
 *
 * ── 做什么 ────────────────────────────────────────────────────
 *   输入框工具栏新增「红包」按钮（chat.toolbar 钩子产出，停用插件即消失）。
 *   弹窗里四选一：
 *     · 普通红包  每人一样多（总额必须能被个数整除）
 *     · 随机红包  每人随机（最少 1，经典「先抢后手气」分配）
 *     · 口令红包  与普通红包同额，但领取要先输入发送者设的口令
 *     · 福利红包  不扣积分：发送者按行填「支付宝口令 / 密钥 / CDKey」，
 *                 每位领取者随机抽走**一条未被抽走的内容**（抽完即止）
 *   积分红包的总额在建包时从发送者账户一次性扣除；24 小时（后台可调）没领完的，
 *   剩余部分由计划任务自动退回发送者账户。
 *   发送者本人也能领一次（与其他人完全同一套规则；口令红包同样要输对口令）。
 *
 * ── 实现要点（与核心的接缝）───────────────────────────────────
 *   · 消息呈现：核心没有「消息内容渲染」钩子，红包卡沿用项目既有做法
 *     （见 plugins/link-card/chat.js、level-cultivation）——插件 chat.js 用
 *     MutationObserver 扫 .ow-msg，拿消息 ID 问服务端「这条是不是红包」，是就把
 *     气泡内容换成信封卡。服务端只认 plugin_red_packet.msg_id，聊天记录里伪造
 *     一句「红包」文字不会变出卡来。
 *   · 发消息走核心 Chat::send（与手动发消息完全同一套校验：成员、禁言、敏感词、
 *     频率限制），失败即整单回滚（退积分 + 删包），不会出现「扣了钱没消息」。
 *   · 领取并发：先抢唯一键（packet_id,user_id）位置，再用「比对旧值」的
 *     条件 UPDATE 扣减余量（SQLite / MySQL / PostgreSQL 语义一致），
 *     抢不到就回滚并提示手慢了。
 *   · 文案不写死「积分」：一律走 ow_points_name()（后台可改名）。
 *   · 样式只引用 --ow-* 变量，深浅两套主题自动跟随；移动端弹窗 92% 宽。
 */
if (!defined('OWLSGO_VERSION')) exit;   // 禁止直接 HTTP 访问本文件

/* ============================ 建表 ============================ */

function owRPBoot(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    // 表结构版本闸门：每次聊天页加载都跑 4 条 CREATE + 5 次索引探测太浪费
    //（MySQL 的 createIndex 还要查 information_schema）。版本一致时只留一条 SELECT；
    // 顺手探一下主表在不在 —— 只带 settings 的库还原会绕过建表，不能让插件带着空表跑。
    if (DB::setting('owrp_schema', '') === '1') {
        try {
            DB::val('SELECT id FROM plugin_red_packet LIMIT 1');
            return;
        } catch (Throwable $e) { /* 表没了：往下重新建 */ }
    }

    $id = DB::autoId();

    DB::run("CREATE TABLE IF NOT EXISTS plugin_red_packet (
        id {$id},
        room_id INT NOT NULL DEFAULT 0,
        sender_id INT NOT NULL DEFAULT 0,
        sender_nick VARCHAR(60) NOT NULL DEFAULT '',
        kind VARCHAR(12) NOT NULL DEFAULT 'normal',
        total INT NOT NULL DEFAULT 0,
        cnt INT NOT NULL DEFAULT 0,
        remain_cnt INT NOT NULL DEFAULT 0,
        remain_total INT NOT NULL DEFAULT 0,
        pass_hash VARCHAR(255) NOT NULL DEFAULT '',
        blessing VARCHAR(160) NOT NULL DEFAULT '',
        msg_id INT NOT NULL DEFAULT 0,
        status VARCHAR(12) NOT NULL DEFAULT 'active',
        created_at INT NOT NULL DEFAULT 0,
        expire_at INT NOT NULL DEFAULT 0
    )");

    // 福利内容池：一行一条，claim_user=0 表示还没被抽走
    DB::run("CREATE TABLE IF NOT EXISTS plugin_red_packet_item (
        id {$id},
        packet_id INT NOT NULL DEFAULT 0,
        " . DB::textCol('content') . ",
        claim_user INT NOT NULL DEFAULT 0,
        claim_at INT NOT NULL DEFAULT 0
    )");

    // 领取记录：唯一键 (packet_id,user_id) 是「一人只能领一次」的最终防线
    DB::run("CREATE TABLE IF NOT EXISTS plugin_red_packet_claim (
        id {$id},
        packet_id INT NOT NULL DEFAULT 0,
        user_id INT NOT NULL DEFAULT 0,
        amount INT NOT NULL DEFAULT 0,
        item_id INT NOT NULL DEFAULT 0,
        created_at INT NOT NULL DEFAULT 0
    )");
    DB::createIndex('owrp_claim_uniq', 'plugin_red_packet_claim', 'packet_id,user_id', true);
    DB::createIndex('owrp_claim_user', 'plugin_red_packet_claim', 'user_id,created_at');
    DB::createIndex('owrp_packet_room', 'plugin_red_packet', 'room_id,created_at');
    DB::createIndex('owrp_packet_sender', 'plugin_red_packet', 'sender_id,created_at');
    DB::createIndex('owrp_item_pid', 'plugin_red_packet_item', 'packet_id,claim_user');

    // 插件配置（k-v）：单笔上限 / 有效期 / 个数上限
    DB::run("CREATE TABLE IF NOT EXISTS plugin_red_packet_cfg (
        k VARCHAR(64) PRIMARY KEY,
        v VARCHAR(255) NOT NULL DEFAULT ''
    )");

    DB::setSetting('owrp_schema', '1');
}
owRPBoot();   // 顶层建表是 PLUGIN.md 允许的副作用（与 chat-background / login-logs 同做法）

/* ============================ 配置与工具 ============================ */

function owRPCfg(string $k, string $def = ''): string
{
    $v = DB::val('SELECT v FROM plugin_red_packet_cfg WHERE k=?', [$k]);
    return ($v === false || $v === null || $v === '') ? $def : (string)$v;
}

function owRPCfgInt(string $k, int $def): int
{
    $v = (int)owRPCfg($k, (string)$def);
    return $v > 0 ? $v : $def;
}

/** 四种红包的中文名（前端提示、后台列表、卡片副标题共用一份） */
function owRPKinds(): array
{
    return [
        'normal'   => '普通红包',
        'random'   => '随机红包',
        'password' => '口令红包',
        'welfare'  => '福利红包',
    ];
}

function owRPStatusText(string $st): string
{
    return [
        'active'  => '进行中',
        'done'    => '已领完',
        'expired' => '已过期',
        'void'    => '已撤销',
    ][$st] ?? $st;
}

function owRPBalance(int $uid): int
{
    return (int)DB::val('SELECT points FROM users WHERE id=?', [$uid]);
}

/**
 * 退还剩余积分并把红包收尾（只对仍在进行中的包生效，天然幂等）。
 * $status：expired（到期）/ void（发送失败撤销）
 */
function owRPRefundPoints(int $pid, string $status = 'expired'): void
{
    $p = DB::one('SELECT * FROM plugin_red_packet WHERE id=?', [$pid]);
    if (!$p || (string)$p['status'] !== 'active') return;
    $st = DB::run("UPDATE plugin_red_packet SET status=? WHERE id=? AND status='active'", [$status, $pid]);
    if ($st->rowCount() < 1) return;   // 别的进程已经收尾
    $back = (int)$p['remain_total'];
    if ($back > 0 && (string)$p['kind'] !== 'welfare') {
        DB::run('UPDATE users SET points=points+? WHERE id=?', [$back, (int)$p['sender_id']]);
    }
}

/** 卡片 / 详情用的数据结构（不含任何口令与福利原文） */
function owRPPayload(array $p, int $uid, bool $claimed = false, int $myAmount = 0): array
{
    $kinds = owRPKinds();
    $st = (string)$p['status'];
    if ($st === 'active' && (int)$p['expire_at'] <= time()) $st = 'expired';
    return [
        'id'         => (int)$p['id'],
        'msg_id'     => (int)$p['msg_id'],
        'kind'       => (string)$p['kind'],
        'kind_name'  => $kinds[$p['kind']] ?? '红包',
        'blessing'   => (string)$p['blessing'],
        'total'      => (int)$p['total'],
        'cnt'        => (int)$p['cnt'],
        'left'       => max(0, (int)$p['remain_cnt']),
        'status'     => $st,
        'status_text'=> owRPStatusText($st),
        'sender'     => (string)$p['sender_nick'],
        'sender_id'  => (int)$p['sender_id'],
        'mine'       => $uid > 0 && $uid === (int)$p['sender_id'],
        'claimed'    => $claimed,
        'my_amount'  => $myAmount,
        'expire_at'  => (int)$p['expire_at'],
        'points_name'=> ow_points_name(),
    ];
}

/** 我在这批红包里各自领了多少（一次查询，避免 N+1） */
function owRPClaimedMap(int $uid, array $ids): array
{
    if ($uid <= 0 || !$ids) return [];
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rows = DB::all("SELECT packet_id, amount FROM plugin_red_packet_claim
                     WHERE user_id=? AND packet_id IN ($ph)", array_merge([$uid], $ids));
    $out = [];
    foreach ($rows as $r) $out[(int)$r['packet_id']] = (int)$r['amount'];
    return $out;
}

/** 福利红包里某条内容（只回给抽到它的人） */
function owRPItemContent(int $itemId): string
{
    if ($itemId <= 0) return '';
    return (string)DB::val('SELECT content FROM plugin_red_packet_item WHERE id=?', [$itemId]);
}

/**
 * 领取一个红包。返回 [ok, msg, extra]
 * @param array  $actor 当前访问者
 * @param int    $pid   红包 ID
 * @param string $pass  口令（仅口令红包需要）
 */
function owRPDoClaim(array $actor, int $pid, string $pass): array
{
    $uid = (int)($actor['id'] ?? 0);
    $p = DB::one('SELECT * FROM plugin_red_packet WHERE id=?', [$pid]);
    if (!$p) return [false, '红包不存在或已失效', []];

    // 幂等：已领过就直接把结果还给他（重复点「开」不该报错）
    $own = DB::one('SELECT * FROM plugin_red_packet_claim WHERE packet_id=? AND user_id=?', [$pid, $uid]);
    if ($own) {
        return [true, 'ok', [
            'already' => true,
            'amount'  => (int)$own['amount'],
            'item'    => owRPItemContent((int)$own['item_id']),
            'card'    => owRPPayload($p, $uid, true, (int)$own['amount']),
            'balance' => owRPBalance($uid),
        ]];
    }

    if ((string)$p['status'] !== 'active') {
        return [false, (string)$p['status'] === 'done' ? '手慢了，红包已领完' : '红包已过期', []];
    }
    if ((int)$p['expire_at'] <= time()) return [false, '红包已过期', []];
    if ((int)$p['remain_cnt'] <= 0) return [false, '手慢了，红包已领完', []];

    // 领取者必须进得去这个群（能看见消息却进不去，说明房间权限变了）
    $room = Chat::room((int)$p['room_id']);
    if (!$room || !Chat::roomAccessOk($room, $actor)) return [false, '你已不在该群聊中，无法领取', []];

    if ((string)$p['kind'] === 'password') {
        if ($pass === '') return [false, '请输入口令', []];
        // 口令通常是短文本，必须限次（10 次 / 分钟 / 账号），否则等于给了个穷举入口
        if (!Sec::rateLimit('owrp_pass', 'u' . $uid, 60, 10)) {
            return [false, '口令尝试过于频繁，请稍后再试', []];
        }
        if (!password_verify($pass, (string)$p['pass_hash'])) return [false, '口令不正确', []];
    }

    $now = time();

    // ① 先抢唯一键位置：并发下同一人只可能插进去一条
    try {
        $claimId = DB::insert('plugin_red_packet_claim', [
            'packet_id' => $pid, 'user_id' => $uid, 'amount' => 0, 'item_id' => 0, 'created_at' => $now,
        ]);
    } catch (Throwable $e) {
        // 唯一键冲突 = 已领过（检查一次再决定是否把异常抛回去）
        $dup = DB::one('SELECT * FROM plugin_red_packet_claim WHERE packet_id=? AND user_id=?', [$pid, $uid]);
        if (!$dup) throw $e;
        return [true, 'ok', [
            'already' => true,
            'amount'  => (int)$dup['amount'],
            'item'    => owRPItemContent((int)$dup['item_id']),
            'card'    => owRPPayload($p, $uid, true, (int)$dup['amount']),
            'balance' => owRPBalance($uid),
        ]];
    }

    $kind = (string)$p['kind'];
    $fixed = ($kind === 'normal' || $kind === 'password')
        ? intdiv((int)$p['total'], max(1, (int)$p['cnt']))
        : 0;

    $amount = 0;
    $won = false;
    for ($i = 0; $i < 6; $i++) {
        $row = DB::one('SELECT * FROM plugin_red_packet WHERE id=?', [$pid]);
        if (!$row || (string)$row['status'] !== 'active') break;
        $leftCnt = (int)$row['remain_cnt'];
        $leftTotal = (int)$row['remain_total'];
        if ($leftCnt <= 0) break;

        if ($kind === 'random') {
            // 「保底 1」的均匀分配：上界 = 剩余总额 - (剩余份数-1)，保证后面每份至少还有 1。
            // ⚠️ 最后一份必须**把余额全部拿走**：否则随机出来的尾巴（如 3 份总额 10 只领走 9）
            // 会永远留在 remain_total 里，既发不出去也退不回（only-for-active 的退款扫描不到它）。
            if ($leftCnt <= 1) {
                $amount = $leftTotal;
            } else {
                $max = $leftTotal - ($leftCnt - 1);
                if ($max < 1) $max = 1;
                $amount = random_int(1, $max);
            }
        } elseif ($kind === 'welfare') {
            $amount = 0;
        } else {
            $amount = $fixed;
        }

        $newCnt = $leftCnt - 1;
        $newTotal = max(0, $leftTotal - $amount);
        $newStatus = $newCnt <= 0 ? 'done' : 'active';
        // 比对旧值的 CAS：三驱动的 UPDATE 求值语义不同，只有「把旧值写进 WHERE」才稳
        $st = DB::run("UPDATE plugin_red_packet
                          SET remain_cnt=?, remain_total=?, status=?
                        WHERE id=? AND status='active' AND remain_cnt=? AND remain_total=?",
            [$newCnt, $newTotal, $newStatus, $pid, $leftCnt, $leftTotal]);
        if ($st->rowCount() === 1) { $won = true; break; }
    }

    if (!$won) {
        DB::run('DELETE FROM plugin_red_packet_claim WHERE id=?', [$claimId]);
        return [false, '手慢了，红包已领完', []];
    }

    // ② 福利红包：从还没被抽走的内容里拿一条（抢不到就换一条，最多试几次）
    $itemId = 0;
    $content = '';
    if ($kind === 'welfare') {
        for ($i = 0; $i < 8; $i++) {
            $it = DB::one('SELECT id, content FROM plugin_red_packet_item
                            WHERE packet_id=? AND claim_user=0 ORDER BY id ASC LIMIT 1', [$pid]);
            if (!$it) break;
            $st = DB::run('UPDATE plugin_red_packet_item SET claim_user=?, claim_at=? WHERE id=? AND claim_user=0',
                [$uid, $now, (int)$it['id']]);
            if ($st->rowCount() === 1) {
                $itemId = (int)$it['id'];
                $content = (string)$it['content'];
                break;
            }
        }
        if ($itemId === 0) {
            // 内容已被同时抢空：把刚扣掉的余量还回去；若确实一条都不剩，直接把包收尾
            //（不依赖 remain_cnt 归零 —— 数据异常时它可能还大于 0，包会一直「进行中」却永远领不到）
            DB::run('UPDATE plugin_red_packet SET remain_cnt=remain_cnt+1 WHERE id=?', [$pid]);
            DB::run('DELETE FROM plugin_red_packet_claim WHERE id=?', [$claimId]);
            $leftItems = (int)DB::val('SELECT COUNT(*) FROM plugin_red_packet_item WHERE packet_id=? AND claim_user=0', [$pid]);
            if ($leftItems <= 0) {
                DB::run("UPDATE plugin_red_packet SET remain_cnt=0, status='done' WHERE id=?", [$pid]);
            }
            return [false, '福利已被抢空', []];
        }
    }

    // ③ 记账：先给用户加积分，再回填领取记录
    if ($amount > 0) DB::run('UPDATE users SET points=points+? WHERE id=?', [$amount, $uid]);
    DB::run('UPDATE plugin_red_packet_claim SET amount=?, item_id=? WHERE id=?', [$amount, $itemId, $claimId]);

    $fresh = DB::one('SELECT * FROM plugin_red_packet WHERE id=?', [$pid]);
    return [true, 'ok', [
        'already' => false,
        'amount'  => $amount,
        'item'    => $content,
        'card'    => owRPPayload($fresh ?: $p, $uid, true, $amount),
        'balance' => owRPBalance($uid),
    ]];
}

/* ============================ 工具栏入口 / 样式 ============================ */

/**
 * 「红包」按钮：HTML 由服务端产出，核心 Plugin::collect('chat.toolbar') 拼进 #owToolFirst。
 * 点击与面板仍由 chat.js 绑定（插件停用 → 钩子不注册 → 按钮不存在，前端安静退出）。
 */
Plugin::on('chat.toolbar', function (): string {
    // 图标是插件自有字形（红包：信封 + 封口弧 + 铜钱），path 只存在于本文件这一处。
    // 封口弧与铜钱必须留出间距：16px 下两者一挨上就糊成一团黑块（实测 r=1.8/cy=12.6 时会粘连）。
    $svg = '<svg class="ow-ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
         . ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . '<rect x="4" y="3.5" width="16" height="17" rx="2.6"/>'
         . '<path d="M4 8.2c2.8 3 5.5 4.4 8 4.4s5.2-1.4 8-4.4"/>'
         . '<circle cx="12" cy="16" r="1.7"/></svg>';
    return '<button class="ow-icon-btn" id="owBtnRp" type="button" title="红包" aria-label="红包"'
         . ' data-points-name="' . Sec::e(ow_points_name()) . '">' . $svg . '</button>';
});

/**
 * 样式表必须自己发 <link>：核心只引合并 **JS**，?action=assets&type=css 没有任何
 * 页面引用它（见 gif-stickers / attachment-manager 的同段注释）。
 * 这里只取本插件那一份，避免依赖别的插件是否恰好整包输出。
 */
Plugin::on('page.head', function (): void {
    echo '<link rel="stylesheet" href="?action=assets&type=css&plugin=red-packet&file=style.css&v=' . OWLSGO_VERSION . '">';
});

Plugin::asset('css', 'red-packet/style.css');   // 供 ?action=assets&type=css&plugin=red-packet&file=style.css 取用
Plugin::asset('js', 'red-packet/chat.js');    // 前台：按钮 + 面板 + 消息卡（后台页自动退出）
Plugin::asset('js', 'red-packet/admin.js');   // 后台：规则表单 + 最近发放列表

/* ============================ API 路由 ============================ */

/** 发包：校验 → 扣积分 → 建包 → 发消息 → 回填 msg_id（任何一步失败都整单回滚） */
Plugin::route('plugin_red_packet_create', function (array $ctx) {
    $actor = $ctx['actor'];
    $post = $ctx['post'];
    if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '请先登录后再发红包']);
    $uid = (int)$actor['id'];
    $kinds = owRPKinds();
    $kind = (string)($post['kind'] ?? '');
    if (!isset($kinds[$kind])) Api::json(['ok' => false, 'msg' => '红包类型不正确']);

    // 只能在群聊里发（私聊没有「多人抢」的语义，也避免与好友关系耦合）
    $roomId = (int)($post['room_id'] ?? 0);
    if ($roomId <= 0) Api::json(['ok' => false, 'msg' => '私聊中不支持发红包，请在群聊里发']);
    $room = Chat::room($roomId);
    if (!$room) Api::json(['ok' => false, 'msg' => '群聊不存在或已解散']);
    if (!Chat::roomAccessOk($room, $actor)) Api::json(['ok' => false, 'msg' => '你已不在该群聊中']);
    if (!Chat::isMember($room, $actor)) Api::json(['ok' => false, 'msg' => '请先加入该群聊后再发红包']);

    $maxTotal = owRPCfgInt('max_total', 100000);
    $maxCnt   = owRPCfgInt('max_count', 100);
    $expireH  = owRPCfgInt('expire_hours', 24);
    $pn       = ow_points_name();

    $blessing = preg_replace('/\s+/u', ' ', strip_tags((string)($post['blessing'] ?? '')));
    $blessing = mb_substr(trim((string)$blessing), 0, 40);
    if ($blessing === '') $blessing = '恭喜发财，大吉大利';
    // 祝福语会出现在聊天消息与红包卡上，必须过站点的敏感词替换（与手动发消息同一口径）；
    // 不过滤就等于开了一条绕过内容审核的侧门（卡片上的字是插件自己渲染的）。
    Chat::filterText($blessing, 'message', $actor);
    if (trim($blessing) === '') $blessing = '恭喜发财，大吉大利';

    $cnt   = (int)($post['count'] ?? 0);
    $total = (int)($post['total'] ?? 0);
    $items = [];
    if ($kind === 'welfare') {
        $raw = str_replace(["\r\n", "\r"], "\n", (string)($post['items'] ?? ''));
        foreach (explode("\n", $raw) as $ln) {
            $ln = trim($ln);
            if ($ln === '') continue;
            $items[] = mb_substr($ln, 0, 300);
            if (count($items) >= 50) break;
        }
        if (!$items) Api::json(['ok' => false, 'msg' => '请至少填写一条福利内容（每行一条）']);
        $cnt = count($items);
        $total = 0;
    } else {
        if ($cnt < 1 || $cnt > $maxCnt) Api::json(['ok' => false, 'msg' => '红包个数需在 1 ~ ' . $maxCnt . ' 之间']);
        if ($total < 1) Api::json(['ok' => false, 'msg' => '请填写红包总金额']);
        if ($total > $maxTotal) Api::json(['ok' => false, 'msg' => '单个红包总额不能超过 ' . $maxTotal . ' ' . $pn]);
        if ($total < $cnt) Api::json(['ok' => false, 'msg' => '总金额不能少于个数（每份至少 1 ' . $pn . '）']);
        if ($kind !== 'random' && $total % $cnt !== 0) {
            Api::json(['ok' => false, 'msg' => '普通红包与口令红包的总额必须能被个数整除']);
        }
    }

    $pass = '';
    if ($kind === 'password') {
        $pass = trim((string)($post['password'] ?? ''));
        if ($pass === '') Api::json(['ok' => false, 'msg' => '请设置口令']);
        if (mb_strlen($pass) > 20) Api::json(['ok' => false, 'msg' => '口令最多 20 个字']);
    }

    // 扣积分：条件 UPDATE 一步完成「查余额 + 扣款」，并发下不会扣成负数
    $cost = $total;
    if ($cost > 0) {
        $st = DB::run('UPDATE users SET points=points-? WHERE id=? AND points>=?', [$cost, $uid, $cost]);
        if ($st->rowCount() < 1) {
            Api::json(['ok' => false, 'msg' => $pn . '不足，当前 ' . owRPBalance($uid) . ' ' . $pn]);
        }
    }

    $now = time();
    $pid = DB::insert('plugin_red_packet', [
        'room_id'      => $roomId,
        'sender_id'    => $uid,
        'sender_nick'  => mb_substr((string)($actor['nickname'] ?? ''), 0, 40),
        'kind'         => $kind,
        'total'        => $total,
        'cnt'          => $cnt,
        'remain_cnt'   => $cnt,
        'remain_total' => $total,
        'pass_hash'    => $kind === 'password' ? password_hash($pass, PASSWORD_DEFAULT) : '',
        'blessing'     => $blessing,
        'msg_id'       => 0,
        'status'       => 'active',
        'created_at'   => $now,
        'expire_at'    => $now + $expireH * 3600,
    ]);
    foreach ($items as $line) {
        DB::insert('plugin_red_packet_item', [
            'packet_id' => $pid, 'content' => $line, 'claim_user' => 0, 'claim_at' => 0,
        ]);
    }

    // 发消息：走核心 Chat::send，成员/禁言/敏感词/频率一套校验全部复用。
    // 用 try 兜一层：Chat::send 内部若抛异常（不是返回 false），红包已经扣过款，
    // 不接住就会留下「扣了钱、没消息、也没人知道该退」的脏账。
    $content = ($kind === 'welfare' ? '🎁 福利红包：' : '🧧 ') . $blessing;
    try {
        $res = Chat::send($actor, $roomId, 'text', $content, [
            'client_id' => 'rp' . $pid . 'x' . bin2hex(random_bytes(4)),
        ]);
    } catch (Throwable $e) {
        $res = [false, '发送失败，请稍后再试'];
    }
    if (empty($res[0])) {
        owRPRefundPoints($pid, 'void');
        DB::run('DELETE FROM plugin_red_packet_item WHERE packet_id=?', [$pid]);
        DB::run('DELETE FROM plugin_red_packet WHERE id=?', [$pid]);
        Api::json(['ok' => false, 'msg' => '红包发送失败：' . (string)($res[1] ?? '请稍后再试')]);
    }
    $msgId = (int)($res[2] ?? 0);
    if ($msgId > 0) DB::run('UPDATE plugin_red_packet SET msg_id=? WHERE id=?', [$msgId, $pid]);

    $row = DB::one('SELECT * FROM plugin_red_packet WHERE id=?', [$pid]);
    Api::json([
        'ok'      => true,
        'msg'     => '红包已发出',
        'card'    => owRPPayload($row ?: ['id' => $pid, 'msg_id' => $msgId, 'kind' => $kind, 'blessing' => $blessing,
            'total' => $total, 'cnt' => $cnt, 'remain_cnt' => $cnt, 'status' => 'active', 'sender_nick' => '',
            'sender_id' => $uid, 'expire_at' => $now], $uid),
        'msg_row' => $res[3] ?? null,
        'balance' => owRPBalance($uid),
    ]);
});

/** 批量问「这些消息哪些是红包」（前端扫到新消息后调用；最多 60 条一批） */
Plugin::route('plugin_red_packet_cards', function (array $ctx) {
    $actor = $ctx['actor'];
    $uid = ($actor['kind'] ?? '') === 'user' ? (int)$actor['id'] : 0;
    $raw = (string)($ctx['post']['ids'] ?? '');
    $ids = [];
    foreach (explode(',', $raw) as $s) {
        $n = (int)trim($s);
        if ($n > 0) $ids[] = $n;
        if (count($ids) >= 60) break;
    }
    if (!$ids) Api::json(['ok' => true, 'items' => []]);

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rows = DB::all("SELECT * FROM plugin_red_packet WHERE msg_id IN ($ph)", $ids);
    if (!$rows) Api::json(['ok' => true, 'items' => []]);

    $claimed = $uid > 0 ? owRPClaimedMap($uid, array_column($rows, 'id')) : [];
    $items = [];
    $roomCache = [];
    foreach ($rows as $p) {
        // 消息 ID 是连号的，不校验房间就等于给了「猜别人的红包金额/祝福语」的接口。
        // 判定与「能不能看见这个群」同一口径（canEnter），群对象在本次请求内缓存。
        $rid = (int)$p['room_id'];
        if (!array_key_exists($rid, $roomCache)) $roomCache[$rid] = Chat::room($rid);
        $room = $roomCache[$rid];
        if (!$room || !Chat::roomAccessOk($room, $actor)) continue;
        $pid = (int)$p['id'];
        $items[(string)(int)$p['msg_id']] = owRPPayload($p, $uid, isset($claimed[$pid]), (int)($claimed[$pid] ?? 0));
    }
    Api::json(['ok' => true, 'items' => $items]);
});

/** 红包详情：基本信息 + 领取名单（福利红包只报「已领」，不泄露别人抽到的内容） */
Plugin::route('plugin_red_packet_info', function (array $ctx) {
    $actor = $ctx['actor'];
    if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '请先登录']);
    $uid = (int)$actor['id'];
    $pid = (int)($ctx['post']['id'] ?? 0);
    $p = DB::one('SELECT * FROM plugin_red_packet WHERE id=?', [$pid]);
    if (!$p) Api::json(['ok' => false, 'msg' => '红包不存在']);
    $room = Chat::room((int)$p['room_id']);
    if (!$room || !Chat::roomAccessOk($room, $actor)) Api::json(['ok' => false, 'msg' => '你已不在该群聊中']);

    $own = DB::one('SELECT * FROM plugin_red_packet_claim WHERE packet_id=? AND user_id=?', [$pid, $uid]);
    $claims = DB::all('SELECT c.amount, c.created_at, c.item_id, u.nickname
                         FROM plugin_red_packet_claim c
                    LEFT JOIN users u ON u.id=c.user_id
                      WHERE c.packet_id=?
                   ORDER BY c.created_at ASC, c.id ASC LIMIT 200', [$pid]);
    $list = [];
    foreach ($claims as $c) {
        $list[] = [
            'nickname' => (string)($c['nickname'] ?? '已注销用户'),
            'amount'   => (int)$c['amount'],
            'time'     => date('m-d H:i', (int)$c['created_at']),
        ];
    }
    Api::json([
        'ok'      => true,
        'card'    => owRPPayload($p, $uid, (bool)$own, (int)($own['amount'] ?? 0)),
        // ⚠️ 这里的 claimed 是「**我**领过没有」；「是不是我发的」看 card.mine。
        // 两者混用会让发包人看到自己红包上的「开红包」按钮（点下去必然被服务端拒）。
        'claimed' => (bool)$own,
        'my_item' => owRPItemContent((int)($own['item_id'] ?? 0)),
        'claims'  => $list,
        'balance' => owRPBalance($uid),
    ]);
});

/** 领取红包 */
Plugin::route('plugin_red_packet_claim', function (array $ctx) {
    $actor = $ctx['actor'];
    if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '请先登录后再领红包']);
    $pid = (int)($ctx['post']['id'] ?? 0);
    if ($pid <= 0) Api::json(['ok' => false, 'msg' => '红包不存在']);
    $pass = trim((string)($ctx['post']['pass'] ?? ''));
    [$ok, $msg, $extra] = owRPDoClaim($actor, $pid, $pass);
    if (!$ok) Api::json(['ok' => false, 'msg' => $msg] + $extra);
    Api::json(['ok' => true, 'msg' => $msg] + $extra);
});

/** 我的红包记录（只回 30 天内） */
Plugin::route('plugin_red_packet_records', function (array $ctx) {
    $actor = $ctx['actor'];
    if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '请先登录']);
    $uid = (int)$actor['id'];
    $since = time() - 30 * 86400;
    $kinds = owRPKinds();

    $recv = DB::all('SELECT c.amount, c.item_id, c.created_at, p.kind, p.blessing, p.sender_nick, p.id
                       FROM plugin_red_packet_claim c
                       JOIN plugin_red_packet p ON p.id=c.packet_id
                      WHERE c.user_id=? AND c.created_at>=?
                   ORDER BY c.created_at DESC LIMIT 100', [$uid, $since]);
    $sent = DB::all('SELECT id, kind, blessing, total, cnt, remain_cnt, status, created_at
                       FROM plugin_red_packet
                      WHERE sender_id=? AND created_at>=?
                   ORDER BY created_at DESC LIMIT 100', [$uid, $since]);

    $recvOut = [];
    foreach ($recv as $r) {
        $recvOut[] = [
            'id'        => (int)$r['id'],
            'kind_name' => $kinds[$r['kind']] ?? '红包',
            'blessing'  => (string)$r['blessing'],
            'sender'    => (string)$r['sender_nick'],
            'amount'    => (int)$r['amount'],
            'is_item'   => (int)$r['item_id'] > 0,
            'time'      => date('m-d H:i', (int)$r['created_at']),
        ];
    }
    $sentOut = [];
    foreach ($sent as $r) {
        $sentOut[] = [
            'id'        => (int)$r['id'],
            'kind_name' => $kinds[$r['kind']] ?? '红包',
            'blessing'  => (string)$r['blessing'],
            'total'     => (int)$r['total'],
            'cnt'       => (int)$r['cnt'],
            'left'      => max(0, (int)$r['remain_cnt']),
            'status'    => (string)$r['status'],
            'status_text' => owRPStatusText((string)$r['status']),
            'time'      => date('m-d H:i', (int)$r['created_at']),
        ];
    }
    Api::json(['ok' => true, 'recv' => $recvOut, 'sent' => $sentOut, 'points_name' => ow_points_name()]);
});

/* ---------- 后台 ---------- */

Plugin::route('plugin_red_packet_admin_save', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $post = $ctx['post'];
    $maxTotal = max(1, min(100000000, (int)($post['max_total'] ?? 100000)));
    $maxCnt   = max(1, min(500, (int)($post['max_count'] ?? 100)));
    $expireH  = max(1, min(720, (int)($post['expire_hours'] ?? 24)));
    foreach (['max_total' => $maxTotal, 'max_count' => $maxCnt, 'expire_hours' => $expireH] as $k => $v) {
        DB::upsert('plugin_red_packet_cfg', ['k' => $k, 'v' => (string)$v], ['k']);
    }
    Api::json(['ok' => true, 'msg' => '已保存']);
});

Plugin::route('plugin_red_packet_admin_list', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $since = time() - 30 * 86400;
    $kinds = owRPKinds();
    $rows = DB::all('SELECT * FROM plugin_red_packet ORDER BY id DESC LIMIT 50');
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'        => (int)$r['id'],
            'sender'    => (string)$r['sender_nick'],
            'sender_id' => (int)$r['sender_id'],
            'kind_name' => $kinds[$r['kind']] ?? '红包',
            'total'     => (int)$r['total'],
            'cnt'       => (int)$r['cnt'],
            'left'      => max(0, (int)$r['remain_cnt']),
            'status'    => owRPStatusText((string)$r['status']),
            'time'      => date('Y-m-d H:i', (int)$r['created_at']),
        ];
    }
    $sum30 = (int)DB::val('SELECT COALESCE(SUM(total),0) FROM plugin_red_packet WHERE created_at>=?', [$since]);
    $cnt30 = (int)DB::val('SELECT COUNT(*) FROM plugin_red_packet WHERE created_at>=?', [$since]);
    $get30 = (int)DB::val('SELECT COUNT(*) FROM plugin_red_packet_claim WHERE created_at>=?', [$since]);
    $amt30 = (int)DB::val('SELECT COALESCE(SUM(amount),0) FROM plugin_red_packet_claim WHERE created_at>=?', [$since]);
    Api::json([
        'ok' => true, 'rows' => $out,
        'kpi' => ['cnt30' => $cnt30, 'sum30' => $sum30, 'get30' => $get30, 'amt30' => $amt30],
        'points_name' => ow_points_name(),
    ]);
});

Plugin::adminPage('red-packet', '红包', function () {
    $pn = Sec::e(ow_points_name());
    $maxTotal = owRPCfgInt('max_total', 100000);
    $maxCnt   = owRPCfgInt('max_count', 100);
    $expireH  = owRPCfgInt('expire_hours', 24);

    $h = '<h2>红包</h2>'
       . '<p class="ow-admin-desc">聊天工具栏里的红包入口：普通 / 随机 / 口令 / 福利四种。'
       . '积分红包在建包时从发送者账户一次性扣除；到期没领完的剩余部分由维护任务自动退回。'
       . '福利红包不扣积分，领取者随机抽走一条尚未被抽走的内容（口令 / 密钥 / CDKey）。</p>';

    // 30 天概览
    $h .= '<div class="ow-card" id="owRpKpi"><div class="ow-rp-kpi">'
        . '<div class="ow-rp-kpi-i"><b>—</b><span>30 天发包</span></div>'
        . '<div class="ow-rp-kpi-i"><b>—</b><span>30 天发出</span></div>'
        . '<div class="ow-rp-kpi-i"><b>—</b><span>领取次数</span></div>'
        . '<div class="ow-rp-kpi-i"><b>—</b><span>领取总额</span></div>'
        . '</div></div>';

    // 规则
    $h .= '<div class="ow-card"><div class="ow-list-head"><span class="ow-list-head-t">发放规则</span></div>'
        . '<div class="ow-form-row">'
        . '<div class="ow-form-item" style="flex:1;min-width:150px"><label>单笔最大' . $pn . '</label>'
        . '<input class="ow-input" id="owRpMaxTotal" type="number" min="1" value="' . $maxTotal . '">'
        . '<p style="font-size:12px">超过这个数额的红包直接拒绝，防止手滑多按两个零。只影响新的红包，已发出的不受影响。</p></div>'
        . '<div class="ow-form-item" style="flex:1;min-width:130px"><label>个数上限</label>'
        . '<input class="ow-input" id="owRpMaxCnt" type="number" min="1" max="500" value="' . $maxCnt . '">'
        . '<p style="font-size:12px">一个红包最多能被分成多少份（含随机与口令红包）。</p></div>'
        . '<div class="ow-form-item" style="flex:1;min-width:130px"><label>有效期（小时）</label>'
        . '<input class="ow-input" id="owRpExpire" type="number" min="1" max="720" value="' . $expireH . '">'
        . '<p style="font-size:12px">超过这个时间没领完，剩余' . $pn . '自动退回发送者账户，红包转为「已过期」。</p></div>'
        . '<div class="ow-form-item" style="min-width:110px"><label>&nbsp;</label>'
        . '<button class="ow-btn ow-btn-primary" type="button" onclick="OwRPAdmin.save()">保存</button></div>'
        . '</div></div>';

    // 最近发放
    $h .= '<div class="ow-card"><div class="ow-list-head"><span class="ow-list-head-t">最近发放</span>'
        . '<span class="ow-rp-head-note">最新 50 条</span></div>'
        . '<div id="owRpAdminList" class="ow-table-wrap"><div class="ow-rp-loading">加载中…</div></div></div>';

    return $h;
});

/* ============================ 计划任务 ============================ */

/**
 * 每小时：① 过期红包退回剩余积分；② 清理 90 天前的红包与领取记录。
 * 30 天的「收红包记录」由查询侧过滤，这里只做长期归档清理，别把记录删到 30 天以内。
 */
Plugin::cron('expire_and_purge', 3600, function () {
    $now = time();
    $refunded = 0;
    $expired = 0;

    $rows = DB::all("SELECT * FROM plugin_red_packet
                      WHERE status='active' AND expire_at<=? AND remain_cnt>0
                   ORDER BY id ASC LIMIT 300", [$now]);
    foreach ($rows as $r) {
        $st = DB::run("UPDATE plugin_red_packet SET status='expired' WHERE id=? AND status='active'", [(int)$r['id']]);
        if ($st->rowCount() < 1) continue;
        $expired++;
        $back = (int)$r['remain_total'];
        if ($back > 0 && (string)$r['kind'] !== 'welfare') {
            DB::run('UPDATE users SET points=points+? WHERE id=?', [$back, (int)$r['sender_id']]);
            $refunded += $back;
        }
    }

    // 兜底：已领完但仍有余额的包（历史数据 / 极端并发下老版本留下的尾巴）把余额退回。
    // 领取侧的「最后一份拿走全部余额」保证新包不会产生这种行，这条只对存量数据生效。
    $leftover = DB::all("SELECT * FROM plugin_red_packet WHERE status='done' AND remain_total>0 LIMIT 300");
    foreach ($leftover as $r) {
        $back = (int)$r['remain_total'];
        $st = DB::run('UPDATE plugin_red_packet SET remain_total=0 WHERE id=? AND remain_total=?',
            [(int)$r['id'], $back]);
        if ($st->rowCount() < 1) continue;
        if ($back > 0 && (string)$r['kind'] !== 'welfare') {
            DB::run('UPDATE users SET points=points+? WHERE id=?', [$back, (int)$r['sender_id']]);
            $refunded += $back;
        }
    }

    // 90 天前的红包整包清掉（含内容池与领取记录）
    $cut = $now - 90 * 86400;
    $old = DB::all('SELECT id FROM plugin_red_packet WHERE created_at<? LIMIT 500', [$cut]);
    $purged = 0;
    foreach ($old as $o) {
        $pid = (int)$o['id'];
        DB::run('DELETE FROM plugin_red_packet_claim WHERE packet_id=?', [$pid]);
        DB::run('DELETE FROM plugin_red_packet_item WHERE packet_id=?', [$pid]);
        DB::run('DELETE FROM plugin_red_packet WHERE id=?', [$pid]);
        $purged++;
    }

    $pn = ow_points_name();
    return '过期 ' . $expired . ' 个，退回 ' . $refunded . ' ' . $pn . '；清理 ' . $purged . ' 个 90 天前的红包';
}, '红包到期退回剩余积分，并清理 90 天前的红包与领取记录（每小时一次）');
