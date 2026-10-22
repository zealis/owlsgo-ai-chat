<?php
/**
 * 内容举报插件（content-report）v1.1.0
 *
 * 功能：
 *   - 前台：聊天头像右键菜单 / 成员搜索结果里的「举报」入口
 *     弹窗 = 举报理由 + 财产损失勾选 + 截图上传 + 补充说明
 *   - 后台：举报规则、AI 审核配置、举报记录（通用列表轮子：搜索 / 筛选 / 批量 / 分页）
 *   - AI 审核（v1.1.0，异步）：
 *       举报落库后**只按 msg_id 去重**入队 —— 同一条消息被多少人、用什么理由举报，
 *       都只问一次 AI；判定时不管举报人选了哪条理由，只要命中**任意一条**举报理由即判违规。
 *       计划任务把「被举报消息 + 上下文」交给 OpenAI 兼容接口判定（默认上下共 21 条，可配）。
 *   - 通知（v1.1.0）：AI 判违规、以及前台勾选了「遭受财产损失」的举报，通过核心
 *       **通知联系人**渠道（`notice.contacts`，v1.3.160）提醒超级管理员 ——
 *       侧栏通知区里多一行，点开是待处理清单，可直接「已处理 / 忽略」。
 *
 * 不修改主程序：依赖核心 OwChat.onMsgCtx / onUserAction / onNoticeContact、
 * Plugin::route / adminPage / asset / on / cron、Notice::contacts 通知联系人扩展点。
 *
 * v1.0.1（v1.2.24）：**移除用户资料卡的「举报」按钮**，举报入口只剩消息右键菜单。
 */

if (!defined('OWLSGO_VERSION')) exit;

/* ============================ 常量 ============================ */

/** AI 超时预算（秒）。与垃圾信息控制的「AI 防线」同一口径：3 秒连 TLS 握手都跑不完。 */
const OWCR_AI_TIMEOUT_MIN = 3;
const OWCR_AI_TIMEOUT_MAX = 30;
const OWCR_AI_TIMEOUT_DEF = 10;

/** 送审上下文条数（含被举报那条） */
const OWCR_AI_CTX_MIN = 3;
const OWCR_AI_CTX_MAX = 101;
const OWCR_AI_CTX_DEF = 21;

/** 每次计划任务最多审几条（一条 = 一次模型调用） */
const OWCR_AI_BATCH_MIN = 1;
const OWCR_AI_BATCH_MAX = 20;
const OWCR_AI_BATCH_DEF = 3;

/** 单条举报最多带几张截图 */
const OWCR_IMG_MAX = 6;
const OWCR_IMG_DEF = 3;

/** 审核失败后的重试上限（超过就停在「审核失败」，交人工看，不再重试） */
const OWCR_AI_TRY_MAX = 3;

/** 通知联系人 key（服务端登记与前端 OwChat.onNoticeContact 靠它对接） */
const OWCR_NOTICE_KEY = 'content-report';

/* ============================ 数据表（幂等建表） ============================ */

DB::run("CREATE TABLE IF NOT EXISTS plugin_content_report (
    id " . DB::autoId() . ",
    reporter_id INTEGER NOT NULL DEFAULT 0,
    reporter_nick VARCHAR(64) NOT NULL DEFAULT '',
    target_uid INTEGER NOT NULL DEFAULT 0,
    target_nick VARCHAR(64) NOT NULL DEFAULT '',
    reason VARCHAR(64) NOT NULL DEFAULT '',
    " . DB::textCol('description') . ",
    room_id INTEGER NOT NULL DEFAULT 0,
    msg_id INTEGER NOT NULL DEFAULT 0,
    msg_time INTEGER NOT NULL DEFAULT 0,
    status INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0
)");

// 增量迁移：旧版数据表补齐字段（用公开的 ensureColumn，$default 必须是带引号的字面量）
DB::ensureColumn('plugin_content_report', 'msg_time', 'int', '0');
DB::ensureColumn('plugin_content_report', 'images', 'text', "''");    // 举报截图（JSON 数组，存 uploads/ 相对地址）
DB::ensureColumn('plugin_content_report', 'loss', 'int', '0');        // 前台勾选「遭受财产损失」
DB::ensureColumn('plugin_content_report', 'alerted', 'int', '0');     // 已进入超管待办（违规 / 财产损失）
DB::ensureColumn('plugin_content_report', 'alert_at', 'int', '0');    // 进入待办的时间
// v1.2.0：给**举报人**的审核结论。空串 = 还没有结果（不通知本人）。
// violate=违规 / clear=未违规 / skipped=内容非文字转人工 / manual=按人举报或财产损失，直接人工
DB::ensureColumn('plugin_content_report', 'result', 'varchar(16)', "''");
DB::ensureColumn('plugin_content_report', 'result_at', 'int', '0');
DB::ensureColumn('plugin_content_report', 'result_hit', 'varchar(64)', "''");

/* AI 审核队列：**唯一键是 msg_id** —— 「相同消息只问 AI 一次」靠它落库，
   不去比字符串理由（同一消息被两个人用不同理由举报，仍然只审一次）。 */
DB::run("CREATE TABLE IF NOT EXISTS plugin_content_report_ai (
    id " . DB::autoId() . ",
    msg_id INTEGER NOT NULL DEFAULT 0,
    room_id INTEGER NOT NULL DEFAULT 0,
    target_uid INTEGER NOT NULL DEFAULT 0,
    target_nick VARCHAR(64) NOT NULL DEFAULT '',
    reporter_id INTEGER NOT NULL DEFAULT 0,
    reporter_nick VARCHAR(64) NOT NULL DEFAULT '',
    reason VARCHAR(64) NOT NULL DEFAULT '',
    report_id INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    verdict VARCHAR(16) NOT NULL DEFAULT '',
    hit VARCHAR(64) NOT NULL DEFAULT '',
    " . DB::textCol('note') . ",
    attempts INTEGER NOT NULL DEFAULT 0,
    item_count INTEGER NOT NULL DEFAULT 0,
    elapsed INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0,
    reviewed_at INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0
)");
DB::createIndex('idx_content_report_ai_msg', 'plugin_content_report_ai', 'msg_id', true);
DB::createIndex('idx_content_report_ai_st', 'plugin_content_report_ai', 'status, id', false);

/* 每个查看者的「已读到哪条」水位（v1.2.0）。
   为什么用高水位而不是逐条已读表：通知是按人一份的摘要，已读语义天然是
   「我看到第 N 条为止」；一行一个用户，upsert 就够，不用维护一张可能无限增长的关系表。
   ⚠️ 这是「不要一刷新又是未读」的根子 —— 未读只按服务端水位算。 */
DB::run("CREATE TABLE IF NOT EXISTS plugin_content_report_seen (
    user_id INTEGER PRIMARY KEY,
    last_id INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL DEFAULT 0
)");

DB::run("CREATE TABLE IF NOT EXISTS plugin_content_report_config (
    k VARCHAR(32) PRIMARY KEY,
    " . DB::textCol('v') . "
)");

/* ============================ 配置读取 ============================ */

/** 默认配置 */
function owCRDefaultConfig(): array
{
    return [
        'reasons'      => "垃圾广告\n辱骂攻击\n色情低俗\n违法违规\n刷屏灌水\n存在欺诈骗钱行为\n其他",
        'interval'     => 30,
        'desc_limit'   => 200,
        'require_desc' => 0,   // 必须填写补充说明
        'allow_self'   => 0,   // 允许举报自己的内容
        'show_guest'   => 0,   // 未登录显示举报入口（点击跳转登录）
        'show_loss'    => 1,   // 前台显示「遭受财产损失」勾选框
        'allow_image'  => 1,   // 前台允许上传截图
        'image_max'    => OWCR_IMG_DEF,
        // ---- AI 审核（独立一套配置，不与垃圾信息控制共用） ----
        'ai_on'        => 0,
        'ai_base'      => '',
        'ai_key'       => '',
        'ai_model'     => 'gpt-4o-mini',
        'ai_timeout'   => OWCR_AI_TIMEOUT_DEF,
        'ai_context'   => OWCR_AI_CTX_DEF,
        'ai_batch'     => OWCR_AI_BATCH_DEF,
        'ai_prompt'    => '',   // 留空 = 按当前举报理由动态生成
        // ---- 通知联系人（侧栏通知区那一行） ----
        'notify_name'   => '',  // 留空 = 用插件名
        'notify_avatar' => '',  // 留空 = open-peeps 生成头像（核心按插件 id 派生，固定不变）
    ];
}

/** 读取全部配置（缺失项补默认值） */
function owCRGetConfig(): array
{
    $cfg = owCRDefaultConfig();
    foreach (DB::all('SELECT k, v FROM plugin_content_report_config') as $r) {
        $k = (string)$r['k'];
        if (!array_key_exists($k, $cfg)) continue;
        $v = (string)$r['v'];
        // 空串一律视为「没配」：所有「留空 = 用默认」的项默认值本身就是空串，
        // 所以直接跳过不会把「清空提示词」变成「改不回去」。
        if ($v === '') continue;
        $cfg[$k] = $v;
    }
    $cfg['interval']   = max(0, (int)$cfg['interval']);
    $cfg['desc_limit'] = max(10, min(2000, (int)$cfg['desc_limit']));
    $cfg['image_max']  = max(1, min(OWCR_IMG_MAX, (int)$cfg['image_max']));
    $cfg['ai_timeout'] = max(OWCR_AI_TIMEOUT_MIN, min(OWCR_AI_TIMEOUT_MAX, (int)$cfg['ai_timeout']));
    $cfg['ai_context'] = max(OWCR_AI_CTX_MIN, min(OWCR_AI_CTX_MAX, (int)$cfg['ai_context']));
    $cfg['ai_batch']   = max(OWCR_AI_BATCH_MIN, min(OWCR_AI_BATCH_MAX, (int)$cfg['ai_batch']));
    foreach (['require_desc', 'allow_self', 'show_guest', 'show_loss', 'allow_image', 'ai_on'] as $b) {
        $cfg[$b] = (int)$cfg[$b] ? 1 : 0;
    }
    return $cfg;
}

/** 保存单个配置项（不存在则插入） */
function owCRSetConfig(string $k, string $v): void
{
    DB::upsert('plugin_content_report_config', ['k' => $k, 'v' => $v], ['k']);
}

/** 把理由文本按行拆分为数组（去空行、去重、保留顺序） */
function owCRParseReasons(string $raw): array
{
    $lines = preg_split('/\r\n|\r|\n/', $raw);
    $seen = [];
    $out = [];
    foreach ($lines as $l) {
        $l = trim($l);
        if ($l === '' || isset($seen[$l])) continue;
        $seen[$l] = true;
        $out[] = $l;
    }
    return $out;
}

/**
 * v1.1.0 一次性给存量站点补上「存在欺诈骗钱行为」。
 *
 * 只动「举报理由仍是 v1.0 那 6 条原文」的站点 —— 管理员自己增删过就一个字都不碰
 * （否则等于把人家编辑过的清单覆盖掉）。幂等：替换后不再等于旧原文。
 */
function owCRReasonsMigrate(): bool
{
    $old = "垃圾广告\n辱骂攻击\n色情低俗\n违法违规\n刷屏灌水\n其他";
    $row = DB::one('SELECT v FROM plugin_content_report_config WHERE k=?', ['reasons']);
    if (!$row) return false;                                   // 从没存过 → 直接用新默认
    if (trim((string)$row['v']) !== $old) return false;         // 管理员改过 → 不动
    DB::upsert('plugin_content_report_config',
        ['k' => 'reasons', 'v' => owCRDefaultConfig()['reasons']], ['k']);
    return true;
}
owCRReasonsMigrate();

/**
 * v1.2.2 一次性存量收尾：AI 已经判「正常」的举报不该再挂在超管待处理里
 * （截图反馈：结论=正常、状态还停在「待处理」）。自动标成已处理。
 * 结论回写 v1.2.0 起就做了，这里只关单；幂等靠 config 标记，只跑一次。
 */
function owCRClearMigrate(): void
{
    $done = DB::one('SELECT v FROM plugin_content_report_config WHERE k=?', ['ai_clear_migrated']);
    if ($done) return;
    DB::run("UPDATE plugin_content_report SET status=1 WHERE status=0 AND alerted=0 AND msg_id>0"
        . " AND msg_id IN (SELECT msg_id FROM plugin_content_report_ai"
        . " WHERE status='done' AND verdict='clear')");
    owCRSetConfig('ai_clear_migrated', '1');
}
owCRClearMigrate();

/* ============================ 通知头像 ============================ */

/**
 * 通知头像取值是否合法。三种都收：
 *   - 空串：用核心的默认生成式头像（插件名 + 按插件 id 派生的 open-peeps）
 *   - 本站上传产物：`uploads/avatar/…`（`Upload::mediaRel()` 会验真实存在 + 防穿越）
 *   - https 图片地址
 * 与前台的「换头像」同一口径：上传走 `Upload::handle(kind=avatar)`，裁成正方形 + 内容哈希去重。
 */
function owCRNotifyAvatarOk(string $v): bool
{
    if ($v === '') return true;
    if (stripos($v, 'https://') === 0) return true;
    return Upload::mediaRel($v) !== null;
}

/**
 * 当前**实际生效**的通知头像（后台预览用）。
 * 走核心的 `Notice::contacts()` —— 它会替「没填」补上插件名与按插件 id 派生的生成头像，
 * 所以后台预览到的就是用户在侧栏通知区里真正看到的那一张；
 * 插件自己再算一遍种子，早晚会和核心的派生规则漂开（预览与实物不一致比没有预览更糟）。
 */
function owCRNotifyAvatarPreview(): string
{
    if (!class_exists('Notice')) return '';
    foreach (Notice::contacts() as $r) {
        if ((string)($r['key'] ?? '') === OWCR_NOTICE_KEY) return (string)($r['avatar'] ?? '');
    }
    return '';
}

/* ============================ AI 提示词 ============================ */

/** 按「当前的举报理由」生成默认提示词（后台留空时每次都用这一份） */
function owCRAiDefaultPrompt(array $reasons): string
{
    $list = '';
    foreach ($reasons as $i => $r) $list .= ($i + 1) . '. ' . $r . "\n";
    if ($list === '') $list = "（管理员尚未配置举报理由）\n";
    return "你是聊天站的内容审核员。下面给你一段聊天记录，其中标着【被举报】的那条是用户举报的消息，"
        . "其余是它前后的上下文，用来判断语境。\n\n"
        . "只要这段聊天命中下列任意一条，就判定为违规：\n" . $list . "\n"
        . "判定要求：\n"
        . "1. 不要求举报人选对理由 —— 命中上面任意一条即算违规，一条都不命中才算正常；\n"
        . "2. 必须结合上下文：玩笑、引用、转述、反讽、正常的技术讨论与观点争执都不算违规；\n"
        . "3. 正常的链接分享、正常的联系方式交换不算违规；\n"
        . "4. 证据不足或含义模糊时判为不违规（宁可放过，也不要误判）。\n\n"
        . "只输出一个 JSON 对象，不要任何解释、不要代码块标记：\n"
        . '{"violate": true 或 false, "reason": "命中的违规理由（未违规留空）", "note": "不超过 20 字的判断依据"}'
        . "\n\n【聊天记录】\n{records}";
}

/** 当前生效的提示词模板：管理员写了就用他的，没写就按当前理由生成 */
function owCRAiPromptOf(array $cfg): string
{
    $tpl = trim((string)$cfg['ai_prompt']);
    if ($tpl !== '') return $tpl;
    return owCRAiDefaultPrompt(owCRParseReasons((string)$cfg['reasons']));
}

/**
 * 把聊天记录填进提示词。
 * 自定义提示词里漏了 {records} 时**追加**而不是丢弃 —— 否则模型看不到任何内容，
 * 会把每条举报都判成正常，而管理员只会觉得「AI 好像没用」。
 */
function owCRAiBuild(string $tpl, string $records): string
{
    if (strpos($tpl, '{records}') === false) $tpl .= "\n\n【聊天记录】\n{records}";
    return str_replace('{records}', $records, $tpl);
}

/* ============================ AI 上下文 ============================ */

/** 是不是「文字消息」。只有 type=text 算 —— image / file / rich / system 都不给模型看。 */
function owCRMsgIsText(array $m): bool
{
    return (string)($m['type'] ?? 'text') === 'text';
}

/**
 * 消息的展示文本。**只用于给管理员看的场合**；送 AI 的记录块不走这里 ——
 * 图片 / 文件 / 图文一律「跳过」（见 owCRAiRecords），模型看不到内容，
 * 与其塞一个 [图片] 占位让它以为是文字，不如明说这些条目被跳过了。
 */
function owCRMsgText(array $m): string
{
    $type = (string)($m['type'] ?? 'text');
    if ($type === 'text' || $type === '') return (string)($m['content'] ?? '');
    if ($type === 'image') return '[图片]';
    if ($type === 'file') {
        $info = json_decode((string)($m['content'] ?? ''), true);
        return '[文件] ' . (is_array($info) ? (string)($info['name'] ?? '') : '');
    }
    if ($type === 'rich') {
        $j = json_decode((string)($m['content'] ?? ''), true);
        $t = is_array($j) ? trim((string)($j['text'] ?? '')) : '';
        return $t !== '' ? '[图文] ' . $t : '[图文]';
    }
    return '[' . $type . ']';
}

/**
 * 「这条消息所在的那段会话」的 SQL 条件。
 * 群聊按 room_id；私聊（room_id=0）按收发双方的**对称**条件 ——
 * 私聊仅双方可见，上下文不能把第三个人扯进来。
 */
function owCRMsgScope(array $msg): array
{
    $roomId = (int)($msg['room_id'] ?? 0);
    if ($roomId > 0) return ['room_id=?', [$roomId]];

    $sIsUser = (int)($msg['user_id'] ?? 0) > 0;
    $rIsUser = (int)($msg['to_user_id'] ?? 0) > 0;
    $sc = $sIsUser ? 'user_id' : 'guest_id';
    $sd = $sIsUser ? (int)$msg['user_id'] : (int)($msg['guest_id'] ?? 0);
    $rc = $rIsUser ? 'to_user_id' : 'to_guest_id';
    $rd = $rIsUser ? (int)$msg['to_user_id'] : (int)($msg['to_guest_id'] ?? 0);
    if ($sd <= 0 || $rd <= 0) return ['', []];
    return ["room_id=0 AND (($sc=? AND $rc=?) OR ($sc=? AND $rc=?))", [$sd, $rd, $rd, $sd]];
}

/**
 * 取被举报消息的上下文（含它自己，共 $n 条；默认 21 = 前后各 10）。
 * 撤回/已删除的消息不进上下文（它们对任何人都已不可见，拿它当语境是错的）。
 *
 * @return array<int,array{self:bool,time:int,nick:string,text:string}>
 */
function owCRAiContext(array $msg, int $n): array
{
    $n = max(OWCR_AI_CTX_MIN, min(OWCR_AI_CTX_MAX, $n));
    $id = (int)$msg['id'];
    $beforeN = intdiv($n - 1, 2);
    $afterN  = $n - 1 - $beforeN;

    [$scope, $scopeArgs] = owCRMsgScope($msg);
    $cols = 'id, nickname, type, content, created_at';
    $before = [];
    $after = [];
    if ($scope !== '') {
        if ($beforeN > 0) {
            $before = DB::all("SELECT $cols FROM messages WHERE $scope AND id<? AND recalled=0 AND deleted=0"
                . " ORDER BY id DESC LIMIT $beforeN", array_merge($scopeArgs, [$id]));
            $before = array_reverse($before);
        }
        if ($afterN > 0) {
            $after = DB::all("SELECT $cols FROM messages WHERE $scope AND id>? AND recalled=0 AND deleted=0"
                . " ORDER BY id ASC LIMIT $afterN", array_merge($scopeArgs, [$id]));
        }
    }

    $rows = array_merge($before, [$msg], $after);
    $out = [];
    foreach ($rows as $m) {
        $text = owCRMsgText($m);
        // 单条截断：一条长文（书签、日志粘贴）能把整段上下文顶掉
        if (mb_strlen($text) > 400) $text = mb_substr($text, 0, 400) . '…';
        $out[] = [
            'self' => ((int)$m['id'] === $id),
            'time' => (int)($m['created_at'] ?? 0),
            'nick' => (string)($m['nickname'] ?? ''),
            'text' => $text,
            'skip' => !owCRMsgIsText($m),   // 图片 / 文件 / 图文：跳过内容，但**仍占这一条的位置**
        ];
    }
    return $out;
}

/**
 * 上下文 → 提示词里的记录块。
 *
 * 非文字条目（图片 / GIF / 文件 / 图文）**跳过**：模型看不到它们的内容，
 * 塞一行 [图片] 只会让它把「这里什么都没有」当成正常证据，反而误导判定。
 * 但取数时它们**照样占位**（上下文窗口仍是配置的 N 条，不往前补位）——
 * 跳过的是「送给模型的内容」，不是「取了几条」。
 */
function owCRAiRecords(array $ctx): string
{
    $out = '';
    $n = 0;
    $skipped = 0;
    foreach ($ctx as $c) {
        if (!empty($c['skip'])) { $skipped++; continue; }
        $n++;
        $mark = !empty($c['self']) ? '【被举报】' : '';
        $out .= '[' . $n . '] ' . date('m-d H:i', (int)$c['time']) . ' '
            . ($c['nick'] !== '' ? $c['nick'] : '（无昵称）') . '：' . $mark . $c['text'] . "\n";
    }
    if ($out === '') $out = "（这一段里没有任何文字消息）\n";
    if ($skipped > 0) {
        $out = '（这段聊天里另有 ' . $skipped . ' 条图片 / 文件消息，你无法看到它们的内容，'
            . "下面的记录已跳过；请只根据能看到的文字判断）\n" . $out;
    }
    return $out;
}

/* ============================ AI 调用 ============================ */

/** 把 curl 的失败翻成能照着做的一句话（与垃圾信息控制同一套判据） */
function owCRAiErrText(int $errno, string $cerr, int $ms, int $budget, float $connT, float $appT): string
{
    $sec = number_format($ms / 1000, 1);
    if ($errno === CURLE_OPERATION_TIMEDOUT) {
        // curl_getinfo 用 0 表示「这一阶段没走到」，不是 -1
        if ($connT <= 0) return '连不上接口：等了 ' . $sec . ' 秒还没建立连接（上限 ' . $budget . ' 秒）';
        if ($appT <= 0) return 'TLS 握手超时：连接通了但 ' . $sec . ' 秒没完成证书协商（上限 ' . $budget . ' 秒）';
        return '接口太慢：' . $sec . ' 秒没返回内容（上限 ' . $budget . ' 秒）';
    }
    if ($errno === CURLE_COULDNT_RESOLVE_HOST) return '域名解析失败：' . mb_substr($cerr, 0, 60);
    if ($errno === CURLE_COULDNT_CONNECT) return '连不上接口：' . mb_substr($cerr, 0, 80);
    if (in_array($errno, [CURLE_SSL_CONNECT_ERROR, CURLE_SSL_CERTPROBLEM, CURLE_SSL_CIPHER,
        CURLE_SSL_CACERT, CURLE_SSL_CACERT_BADFILE, CURLE_SSL_PEER_CERTIFICATE], true)) {
        return 'HTTPS 证书校验失败：' . mb_substr($cerr, 0, 100);
    }
    return '请求失败：' . ($cerr !== '' ? $cerr : '网络不通') . '（errno ' . $errno . '，已等 ' . $sec . ' 秒）';
}

/** 返回体解析：有些兼容网关把一次性应答也按流式发（首行 data: {...}） */
function owCRAiDecode(string $resp): array
{
    $j = json_decode($resp, true);
    if (is_array($j)) return $j;
    // 不能按 \R 分行：返回体按字节处理，PCRE 非 UTF 模式下 0x85 也算换行，中文会被拦腰切开
    foreach (preg_split('/\r\n|\r|\n/', $resp) ?: [] as $line) {
        $line = trim((string)preg_replace('/^data:\s*/', '', trim($line)));
        if ($line === '' || $line === '[DONE]' || strpos($line, '{') !== 0) continue;
        $j = json_decode($line, true);
        if (is_array($j)) return $j;
    }
    return [];
}

/** 网关把本次请求判成「敏感」而拒审（随机发生，只做一次带剩余预算的重试） */
function owCRAiIsRefusal(array $err): bool
{
    $code = strtolower(trim((string)($err['code'] ?? '') . ' ' . (string)($err['type'] ?? '')));
    if (strpos($code, 'content_check') !== false) return true;
    return (bool)preg_match('/sensitive content|content policy/i', (string)($err['message'] ?? ''));
}

/** 解析模型的 JSON：模型常带代码块或前后一句话，抠第一个 { 到最后一个 } */
function owCRAiParse(string $content): array
{
    $s = strpos($content, '{');
    $e = strrpos($content, '}');
    if ($s === false || $e === false || $e <= $s) {
        return ['ok' => false, 'violate' => null, 'reason' => '', 'note' => '',
                'raw' => '模型没按 JSON 返回：' . mb_substr($content, 0, 200)];
    }
    $v = json_decode(substr($content, $s, $e - $s + 1), true);
    if (!is_array($v) || !array_key_exists('violate', $v)) {
        return ['ok' => false, 'violate' => null, 'reason' => '', 'note' => '',
                'raw' => 'JSON 里没有 violate 字段：' . mb_substr($content, 0, 200)];
    }
    $violate = filter_var($v['violate'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($violate === null) {
        return ['ok' => false, 'violate' => null, 'reason' => '', 'note' => '',
                'raw' => 'violate 不是真假值：' . mb_substr($content, 0, 200)];
    }
    return ['ok' => true, 'violate' => $violate,
            'reason' => mb_substr((string)($v['reason'] ?? ''), 0, 64),
            'note'   => mb_substr((string)($v['note'] ?? ''), 0, 200),
            'raw'    => mb_substr($content, 0, 200)];
}

/** AI 能不能用（没配置就不自动审核） */
function owCRAiReady(array $cfg): bool
{
    return !empty($cfg['ai_on'])
        && trim((string)$cfg['ai_base']) !== ''
        && trim((string)$cfg['ai_model']) !== ''
        && stripos(trim((string)$cfg['ai_base']), 'https://') === 0;
}

/**
 * 问一次模型。返回 ['ok','violate','reason','note','raw','ms']
 * 任何一步不对都是 ok=false + 人话原因 —— 调用方据此**不做判定**（既不判违规也不放行）。
 */
function owCRAiAsk(string $prompt, int $budget = 0, int $attempt = 1): array
{
    $fail = function (string $raw, int $ms = 0): array {
        return ['ok' => false, 'violate' => null, 'reason' => '', 'note' => '', 'raw' => $raw, 'ms' => $ms];
    };

    $cfg = owCRGetConfig();
    $base = trim((string)$cfg['ai_base']);
    $key = trim((string)$cfg['ai_key']);
    $model = trim((string)$cfg['ai_model']);
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
    $timeout = $budget > 0 ? max(1, min(OWCR_AI_TIMEOUT_MAX, $budget)) : (int)$cfg['ai_timeout'];
    $connTo = min(5, $timeout);   // 「地址根本不通」不该也等满预算

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
        if ($resp === false) return $fail(owCRAiErrText($errno, $cerr, $ms, $timeout, $connT, $appT), $ms);
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

    $j = owCRAiDecode((string)$resp);
    $content = (string)($j['choices'][0]['message']['content'] ?? '');
    if ($content === '' && isset($j['error']) && is_array($j['error'])) {
        $em = mb_substr((string)($j['error']['message'] ?? ''), 0, 200);
        if (owCRAiIsRefusal($j['error'])) {
            $left = $timeout - (int)ceil($ms / 1000);
            if ($attempt === 1 && $left >= 3) {
                $r2 = owCRAiAsk($prompt, $left, 2);
                $r2['ms'] = (int)($r2['ms'] ?? 0) + $ms;
                return $r2;
            }
            return $fail('网关拒绝审核这段内容（它自己的输入风控，与站点配置无关）：' . $em, $ms);
        }
        return $fail('接口报错：' . ($em !== '' ? $em : mb_substr((string)$resp, 0, 200)), $ms);
    }
    if ($content === '') return $fail('响应里没有 content：' . mb_substr((string)$resp, 0, 200), $ms);
    $r = owCRAiParse($content);
    $r['ms'] = $ms;
    return $r;
}

/* ============================ 审核队列 ============================ */

/** 某条消息的审核行（评审判定结果用） */
function owCRAiRowOf(int $msgId): ?array
{
    if ($msgId <= 0) return null;
    $r = DB::one('SELECT * FROM plugin_content_report_ai WHERE msg_id=?', [$msgId]);
    return $r ?: null;
}

/**
 * 入队（只按 msg_id 去重，靠唯一索引 + INSERT IGNORE 落库）。
 * 返回该消息当前的审核行 —— 已审过就直接复用结论，不再问一次 AI。
 */
function owCRAiEnqueue(array $rep, array $cfg): ?array
{
    $msgId = (int)$rep['msg_id'];
    if ($msgId <= 0) return null;                    // 没有具体消息（按人举报）无法审核
    if (!owCRAiReady($cfg)) return null;             // 没配 AI 就不自动审核

    $now = time();
    DB::insertIgnore('plugin_content_report_ai', [
        'msg_id'        => $msgId,
        'room_id'       => (int)$rep['room_id'],
        'target_uid'    => (int)$rep['target_uid'],
        'target_nick'   => (string)$rep['target_nick'],
        'reporter_id'   => (int)$rep['reporter_id'],
        'reporter_nick' => (string)$rep['reporter_nick'],
        'reason'        => (string)$rep['reason'],
        'report_id'     => (int)$rep['report_id'],
        'status'        => 'pending',
        'verdict'       => '',
        'hit'           => '',
        'note'          => '',
        'attempts'      => 0,
        'item_count'    => 0,
        'elapsed'       => 0,
        'created_at'    => $now,
        'reviewed_at'   => 0,
        'updated_at'    => $now,
    ], ['msg_id']);
    return owCRAiRowOf($msgId);
}

/** 把某条消息下所有「还没处理」的举报标成待超管处理 */
/** 内容归一化（比对「重复刷屏」用）：去掉全部空白后精确比对；图片/文件是 JSON，天然互不相等 */
function owCRContentNorm(string $content): string
{
    return preg_replace('/\s+/u', '', $content);
}

/**
 * v1.2.4：以超管身份**自动撤回**违规消息。走核心 Chat::recall —— 物理删行 + 删附件 +
 * msg.after_recall 钩子，与人工撤回完全同一条路（前端长轮询会立刻看到消息消失）。
 *
 * 站方口径：刷屏/灌水「不能只是删除举报的一条」。所以撤回按两档扩大：
 *   - **重复内容**（所有违规理由都做）：同房间、同发送者、被举报消息前后 10 分钟窗口内，
 *     内容归一化后与被举报消息**完全相同**的一并撤（上限 20 条）—— 判据是确定性的，不怕误伤；
 *   - **命中「刷屏灌水」**（理由名含「刷屏」）：窗口内该发送者的消息**全部**撤（上限 50 条）
 *     —— 变着花样的灌水没法靠内容比对，是否构成刷屏由 AI 判（它看得见上下文），
 *     插件只负责按窗口收尾。窗口全撤伤害大，所以只绑在这一个理由上。
 *
 * 返回撤回条数；0 = 没撤成（私聊 / 环境缺类 / 核心拒了）→ 交超管人工。
 * 权限按核心模型：群聊超管可撤任何人的违规内容；**私聊核心明确不许超管撤他人消息**
 * （隐私口径），不在插件里绕开核心的权限检查。
 */
function owCRAutoRecall(int $msgId, string $hit = ''): int
{
    if ($msgId <= 0 || !class_exists('Chat') || !method_exists('Chat', 'recall')) return 0;
    $msg = DB::one('SELECT * FROM messages WHERE id=?', [$msgId]);
    if (!$msg || (int)$msg['room_id'] <= 0) return 0;       // 私聊 / 消息已不在：不自动撤

    $recallOne = function (int $id) use ($msgId): bool {
        if ($id === $msgId) return true;                     // 被举报那条已在下面撤过
        try {
            list($ok) = Chat::recall(['kind' => 'user', 'id' => 0, 'role' => 'admin'], $id);
            return (bool)$ok;
        } catch (Throwable $e) {
            return false;
        }
    };

    try {
        list($ok) = Chat::recall(['kind' => 'user', 'id' => 0, 'role' => 'admin'], $msgId);
        if (!$ok) return 0;
    } catch (Throwable $e) {
        return 0;                                            // 撤回失败 → 交人工，绝不静默丢内容
    }

    $n = 1;
    $win = 600;                                              // 前后各 10 分钟
    $burst = $hit !== '' && mb_stripos($hit, '刷屏') !== false;
    $cap = $burst ? 50 : 20;
    $rows = DB::all(
        'SELECT * FROM messages WHERE room_id=? AND user_id=? AND id<>? AND deleted=0'
        . ' AND created_at BETWEEN ? AND ? ORDER BY id ASC LIMIT 200',
        [(int)$msg['room_id'], (int)($msg['user_id'] ?? 0), $msgId,
         (int)$msg['created_at'] - $win, (int)$msg['created_at'] + $win]
    );
    if ($rows && (int)($msg['user_id'] ?? 0) > 0) {
        $norm = owCRContentNorm((string)$msg['content']);
        foreach ($rows as $m) {
            if ($n >= $cap) break;
            // 刷屏灌水：窗口内全撤；其余理由只连带「内容相同」的重复
            if (!$burst && owCRContentNorm((string)$m['content']) !== $norm) continue;
            if ($recallOne((int)$m['id'])) $n++;
        }
    }
    return $n;
}

/** 把某条消息下所有「还没处理」的举报标成待超管处理 */
function owCRAlertMsg(int $msgId): int
{
    if ($msgId <= 0) return 0;
    return DB::run('UPDATE plugin_content_report SET alerted=1, alert_at=? WHERE msg_id=? AND status=0',
        [time(), $msgId]);
}

/** 待超管处理的条数（通知行的未读徽标） */
function owCRAlertCount(): int
{
    return (int)DB::val('SELECT COUNT(*) FROM plugin_content_report WHERE alerted=1 AND status=0');
}

/** 最新一条待处理举报（通知流里只放这一条的摘要，见下方钩子注释） */
function owCRLatestAlert(): ?array
{
    $r = DB::one('SELECT * FROM plugin_content_report WHERE alerted=1 AND status=0 ORDER BY id DESC LIMIT 1');
    return $r ?: null;
}

/** 一条举报的截图地址列表（库里存的是 uploads/ 相对地址的 JSON） */
function owCRRepImages(array $rep): array
{
    $j = json_decode((string)($rep['images'] ?? ''), true);
    return is_array($j) ? array_values(array_filter(array_map('strval', $j))) : [];
}

/** 一条待处理举报的摘要（通知流正文，≤60 字，服务端还会再截一次） */
function owCRAlertText(array $rep, int $total, int $imgs): string
{
    $what = (int)($rep['loss'] ?? 0) === 1 ? '财产损失举报' : 'AI 判定违规';
    $text = $what . '：' . (string)$rep['reporter_nick'] . ' 举报 ' . (string)$rep['target_nick']
        . '（' . (string)$rep['reason'] . '）';
    if ($imgs > 0) $text .= '·附 ' . $imgs . ' 张截图';
    if ($total > 1) $text .= '，另有 ' . ($total - 1) . ' 条待处理';
    return $text;
}

/* ============================ 已读水位（v1.2.0） ============================ */

/** 该用户已读到第几条（没记录过就是 0） */
function owCRSeenId(int $uid): int
{
    if ($uid <= 0) return 0;
    return (int)DB::val('SELECT last_id FROM plugin_content_report_seen WHERE user_id=?', [$uid]);
}

/** 上报已读水位（只前进不后退） */
function owCRMarkSeen(int $uid, int $id): void
{
    if ($uid <= 0 || $id <= 0) return;
    if ($id <= owCRSeenId($uid)) return;
    DB::upsert('plugin_content_report_seen',
        ['user_id' => $uid, 'last_id' => $id, 'updated_at' => time()], ['user_id']);
}

/** 举报表当前最大 id（上报已读时用） */
function owCRMaxReportId(): int
{
    return (int)DB::val('SELECT MAX(id) FROM plugin_content_report');
}

/**
 * 该用户视角下的未读条数（按身份分流）：
 *   超管     = 待超管处理的举报（alerted=1 且 status=0），id 大于已读水位
 *   举报人   = 自己的举报里已经有审核结论的，id 大于已读水位
 * 其他人恒 0（他们看不到这一行，见 notice.contacts 的登记条件）。
 */
function owCRUnreadFor(array $user): int
{
    $uid = (int)($user['id'] ?? 0);
    if ($uid <= 0) return 0;
    $seen = owCRSeenId($uid);
    if ((string)($user['role'] ?? '') === 'admin') {
        return (int)DB::val('SELECT COUNT(*) FROM plugin_content_report WHERE alerted=1 AND status=0 AND id>?', [$seen]);
    }
    return (int)DB::val(
        "SELECT COUNT(*) FROM plugin_content_report WHERE reporter_id=? AND result<>'' AND id>?", [$uid, $seen]);
}

/* ============================ 审核结果（v1.2.0，给举报人） ============================ */

/**
 * 给某条消息下「还没有结果」的举报补上审核结论。
 * 同一消息被多人举报时，一次判定、人人有份（与「只问 AI 一次」同一口径）。
 */
function owCRSetResult(int $msgId, string $result, string $hit = '', int $at = 0): int
{
    if ($msgId <= 0 || $result === '') return 0;
    $at = $at > 0 ? $at : time();
    return DB::run("UPDATE plugin_content_report SET result=?, result_at=?, result_hit=? WHERE msg_id=? AND result=''",
        [$result, $at, mb_substr($hit, 0, 64), $msgId]);
}

/** 给举报人看的一句话结论 */
function owCRResultText(array $rep): string
{
    $r = (string)($rep['result'] ?? '');
    if ($r === '') return '';
    if ($r === 'violate') {
        $hit = (string)($rep['result_hit'] ?? '');
        return '你的举报已审核：判定违规' . ($hit !== '' ? '（命中：' . $hit . '）' : '') . '，已交由管理员处理。';
    }
    if ($r === 'clear') return '你的举报已审核：未发现违反举报理由的内容，感谢你的反馈。';
    if ($r === 'skipped') return '你的举报已审核：该内容无法自动核实，已转交管理员人工处理。';
    if ($r === 'manual') return '你的举报已受理，已交由超级管理员处理。';
    return '你的举报已审核完成。';
}

/**
 * 把一次 AI 判定写回队列（单独成函数是为了能**离线逐条测**：
 * 真跑 owCRAiAsk 要联网，而自检机上没有密钥，那条路径就永远没被验证过）。
 *
 * @param array $q 队列行
 * @param array $r owCRAiAsk 的返回（ok=true）
 * @param int   $items 本次送审的消息条数
 * @return bool 是否判违规
 */
/**
 * 一次判定写回队列并按结论处置。返回 ['violate'=>bool, 'auto'=>bool]（auto=自动撤回是否成功）。
 *
 * v1.2.2 站方口径（两点都反过来了）：
 *   - 判「正常」→ **要**通知前台：结论回写给举报人（消息箱里能看到「未发现违规」），
 *     并把该消息下的举报自动标「已处理」—— AI 已给出结论的举报不该再挂在超管待办里
 *     等人手动点「已处理」。
 *   - 判「违规」→ **不**通知前台：直接自动撤回被举报消息（群聊），处置即完成、举报自动关单；
 *     撤回失败（私聊 / 消息已不在）才回落「交超管人工」。
 */
function owCRAiApply(array $q, array $r, int $items): array
{
    $now = time();
    $qid = (int)$q['id'];
    $msgId = (int)$q['msg_id'];
    $violate = !empty($r['violate']);
    DB::run('UPDATE plugin_content_report_ai SET status=?, verdict=?, hit=?, note=?, attempts=attempts+1,'
        . ' item_count=?, elapsed=?, reviewed_at=?, updated_at=? WHERE id=?',
        ['done', $violate ? 'violate' : 'clear', $violate ? (string)$r['reason'] : '',
         mb_substr((string)($r['note'] ?? ''), 0, 300), $items, (int)($r['ms'] ?? 0), $now, $now, $qid]);

    if ($violate) {
        $recalled = owCRAutoRecall($msgId, (string)$r['reason']);
        if ($recalled > 0) {
            // 自动撤回成功：处置即完成 —— 举报直接落「已处理」，不进待办、不通知举报人
            DB::run('UPDATE plugin_content_report SET status=1 WHERE msg_id=? AND status=0', [$msgId]);
            Sec::log('content_report_auto_recall', (string)$q['target_nick'],
                ['msg' => $msgId, 'reason' => (string)$r['reason'], 'count' => $recalled]);
            return ['violate' => true, 'auto' => $recalled];
        }
        // 撤不了（私聊 / 环境缺类）：交超管人工 —— 也照旧不写 result（违规不推前台）
        $n = owCRAlertMsg($msgId);
        Sec::log('content_report_ai_hit', (string)$q['target_nick'],
            ['msg' => $msgId, 'reason' => (string)$r['reason'], 'alerts' => $n]);
        return ['violate' => true, 'auto' => 0];
    }

    // 判「正常」：结论回写给举报人（同消息下所有还没结果的举报一次补齐）+ 自动关单
    owCRSetResult($msgId, 'clear', '', $now);
    DB::run('UPDATE plugin_content_report SET status=1 WHERE msg_id=? AND status=0 AND alerted=0', [$msgId]);
    return ['violate' => false, 'auto' => false];
}

/**
 * 计划任务：把队列里的待审条目交给 AI（每分钟至多一次，每次最多 ai_batch 条）。
 * 返回的字符串会写进「计划任务」的日志（后台看得见这批审了几条、几条违规）。
 */
function owCRAiTick(): string
{
    $cfg = owCRGetConfig();
    if (!owCRAiReady($cfg)) return '';               // 未配置 → 不审（静默，不刷日志）

    $limit = (int)$cfg['ai_batch'];
    $rows = DB::all("SELECT * FROM plugin_content_report_ai WHERE status='pending' ORDER BY id ASC LIMIT $limit");
    if (!$rows) return '';

    // v1.5.20：一次取回本批待审消息，别在 foreach 里逐条查（AI 调用才是大头，但查询也没必要 N+1）
    $msgIds = array_values(array_unique(array_map(fn($q) => (int)$q['msg_id'], $rows)));
    [$msgIn, $msgInArgs] = DB::in('id', $msgIds);
    $msgMap = [];
    foreach (DB::all("SELECT * FROM messages WHERE $msgIn", $msgInArgs) as $mr) {
        $msgMap[(int)$mr['id']] = $mr;
    }

    $okN = 0; $hitN = 0; $failN = 0; $skipN = 0; $autoN = 0;
    // N+1 例外：每个待审条目都要调一次外部 AI 接口，DB 这一侧已把消息一次预取；
    // 单条更新/上下文查询无法合并（每条的外部调用结果各自独立）。
    foreach ($rows as $q) {
        $now = time();
        $qid = (int)$q['id'];
        $msgId = (int)$q['msg_id'];
        $msg = $msgMap[$msgId] ?? null;
        // 消息已被撤回（物理删行）或软删除（内容已清空）：没有可审的内容，停在这里交人工
        if (!$msg || (int)($msg['deleted'] ?? 0) === 1 || (int)($msg['recalled'] ?? 0) === 1) {
            DB::run("UPDATE plugin_content_report_ai SET status='failed', attempts=attempts+1,"
                . " note=?, updated_at=? WHERE id=?",
                ['被举报的消息已被删除或撤回，无法审核', $now, $qid]);
            $failN++;
            continue;
        }

        $ctx = owCRAiContext($msg, (int)$cfg['ai_context']);

        // 被举报的那条**不是文字消息**（图片 / GIF / 文件 / 图文）：模型看不到内容，
        // 让它判就等于逼它猜 —— 直接转人工（标待超管处理），不消耗一次 AI 调用。
        // 与「举报的不是具体消息」同一处置口径：AI 处理不了的都交超级管理员。
        if (!owCRMsgIsText($msg)) {
            DB::run("UPDATE plugin_content_report_ai SET status='skipped', attempts=attempts+1,"
                . " note=?, item_count=?, reviewed_at=?, updated_at=? WHERE id=?",
                ['被举报的是图片/文件消息，AI 看不到内容，已转交超级管理员',
                 count($ctx), $now, $now, $qid]);
            $n = owCRAlertMsg($msgId);
            owCRSetResult($msgId, 'skipped', '', $now);
            Sec::log('content_report_ai_skip', (string)$q['target_nick'],
                ['msg' => $msgId, 'type' => (string)$msg['type'], 'alerts' => $n]);
            $skipN++;
            continue;
        }

        $prompt = owCRAiBuild(owCRAiPromptOf($cfg), owCRAiRecords($ctx));
        $r = owCRAiAsk($prompt);

        if (!$r['ok']) {
            // 失败就不判定：前几次留在队列里重试，超过上限才停在 failed（避免一直烧接口）
            $attempts = (int)$q['attempts'] + 1;
            $status = $attempts >= OWCR_AI_TRY_MAX ? 'failed' : 'pending';
            DB::run('UPDATE plugin_content_report_ai SET status=?, attempts=?, note=?, elapsed=?, updated_at=? WHERE id=?',
                [$status, $attempts, mb_substr((string)$r['raw'], 0, 300), (int)($r['ms'] ?? 0), $now, $qid]);
            if ($status === 'failed') {
                // v1.2.4：重试耗尽、审核确实失败了 —— 与「内容非文字」同一待遇：
                // 结论回写给举报人（推前台：「已转交管理员人工核实」），举报进超管待办。
                // 不能让举报人傻等一个永远不会来的结论。
                owCRSetResult($msgId, 'skipped', '', $now);
                owCRAlertMsg($msgId);
            }
            Sec::log('content_report_ai_fail', (string)$q['target_nick'],
                ['msg' => $msgId, 'err' => mb_substr((string)$r['raw'], 0, 120)]);
            $failN++;
            continue;
        }

        $applied = owCRAiApply($q, $r, count($ctx));
        if ($applied['violate']) { $hitN++; $autoN += (int)$applied['auto']; }
        $okN++;
    }
    return '审核 ' . $okN . ' 条（违规 ' . $hitN . '，自动撤回 ' . $autoN
        . ' 条；正常 ' . ($okN - $hitN) . ' 条已通知举报人并自动结单），'
        . '转人工 ' . $skipN . ' 条，失败 ' . $failN . ' 条（失败已转人工并通知举报人）';
}

/* ============================ 通知联系人 ============================ */

/**
 * 侧栏通知区里的一行（v1.3.160 notice.contacts 扩展点）。
 *
 * v1.2.0 起**两类人都登记**（钩子参数只有登记函数、没有 actor，所以自己取 Auth::user()）：
 *   超管     —— 未读 = 待超管处理的举报；副标题 = 最新一条待办的摘要
 *   举报人   —— 未读 = 自己的举报里有审核结论的；副标题 = 最新一条结论
 * 没举报过的普通用户不登记（这一行与他无关）；昵称/头像不填由核心兜底
 * （插件名 + 按插件 id 派生的 open-peeps 生成头像）。
 *
 * 点这一行 = 打开**插件自己的消息箱**（见 chat.js 的 CR.openBox），
 * 不占用系统通知那一页（站方 v1.2.0 口径：独立一个消息框）。
 */
Plugin::on('notice.contacts', function (callable $add) {
    if (!class_exists('Auth')) return;
    $u = Auth::user();
    if (!$u) return;                                        // 游客不触发（核心本就不给游客通知区）
    $uid = (int)($u['id'] ?? 0);
    $isAdmin = (string)($u['role'] ?? '') === 'admin';

    if (!$isAdmin) {
        // 没举报过的人不出现这一行
        $mine = (int)DB::val('SELECT COUNT(*) FROM plugin_content_report WHERE reporter_id=?', [$uid]);
        if ($mine <= 0) return;
    }

    $cfg = owCRGetConfig();
    $unread = owCRUnreadFor($u);
    if ($isAdmin) {
        $last = owCRLatestAlert();
        $text = $last
            ? owCRAlertText($last, owCRAlertCount(), count(owCRRepImages($last)))
            : '暂无待处理举报';
        $at = $last ? (int)($last['alert_at'] ?: $last['created_at']) : 0;
    } else {
        $last = DB::one("SELECT * FROM plugin_content_report WHERE reporter_id=? AND result<>'' ORDER BY id DESC LIMIT 1", [$uid]);
        $text = $last ? owCRResultText($last) : '暂无新消息';
        $at = $last ? (int)($last['result_at'] ?: $last['created_at']) : 0;
    }
    $add([
        'key'       => OWCR_NOTICE_KEY,
        'name'      => (string)$cfg['notify_name'],
        'avatar'    => (string)$cfg['notify_avatar'],
        'unread'    => $unread,
        'last_text' => $text,
        'at'        => $at,
    ]);
});

/* ============================ 计划任务 ============================ */

/**
 * AI 审核（异步）。固定 60 秒一次 —— 注册时的间隔只在「插件文件变化后重建清单」时才写库，
 * 做成后台可配的间隔要等下一次清单重建才生效（看着改了其实没改），所以这里固定 60，
 * 吞吐量交给「每次审几条」控制。
 */
Plugin::cron('ai_review', 60, 'owCRAiTick', '内容举报 AI 审核（把待审举报连同上下文交给 AI 判定）');

/* ============================ 管理员鉴权 ============================ */

$crGuard = function (array $ctx): void {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
};

/* ============================ 后台页面 ============================ */

Plugin::adminPage('content-report', '内容举报', function () {
    $cfg = owCRGetConfig();
    // 开关 HTML（与核心 switchHtml 结构一致，由 admin.js 调 bindSwitches 绑定视觉同步）
    // v1.2.52：行容器改成 div、for 只挂轨道 —— 与核心同款修复（点文字/空白不再误切换）
    $sw = function (string $id, string $label, bool $on, string $hint = ''): string {
        return '<div class="ow-switch-row">'
            . '<span class="ow-switch-label">' . Sec::e($label) . '</span>'
            . '<input type="checkbox" class="ow-switch-input" id="' . Sec::e($id) . '"' . ($on ? ' checked' : '') . '>'
            . '<label class="ow-switch' . ($on ? ' is-on' : '') . '" for="' . Sec::e($id) . '"></label>'
            . ($hint ? '<p class="ow-switch-hint">' . Sec::e($hint) . '</p>' : '')
            . '</div>';
    };
    $aiOn = !empty($cfg['ai_on']);
    $avPrev = owCRNotifyAvatarPreview();   // 后台预览 = 核心实际会用的那一张（见函数注释）
    // 整页套一个 ow-cr-page：插件样式表只作用于自己的页面，不改核心的通用类（v1.0.x 的
    // admin.css 直接覆盖 .ow-table-wrap / .ow-form-row，等于把全站后台页一起改了）
    $h = '<div class="ow-cr-page"><h2>内容举报</h2><p class="ow-admin-desc">前台用户可通过消息右键菜单或成员搜索举报他人，'
        . '后台可在此配置举报规则与 AI 审核，并处理举报记录。</p>'

        // ---- 规则配置 ----
        . '<div class="ow-card"><h3 style="margin-bottom:10px">举报规则配置</h3>'
        . '<div class="ow-form-item"><label>举报理由（每行一条，将作为前台下拉选项）</label>'
        . '<textarea class="ow-input" id="owCRReasons" rows="7" style="resize:vertical">' . Sec::e($cfg['reasons']) . '</textarea>'
        . '<p style="font-size:12px">这段清单同时是 AI 的判定标准：命中其中任意一条即算违规，'
        . '不要求举报人自己选对理由。</p></div>'
        . '<div class="ow-form-row">'
        . '<div class="ow-form-item"><label>同一用户举报间隔（秒，0 关闭）</label>'
        . '<input class="ow-input" id="owCRInterval" type="number" min="0" value="' . (int)$cfg['interval'] . '"></div>'
        . '<div class="ow-form-item"><label>补充说明字数上限</label>'
        . '<input class="ow-input" id="owCRDescLimit" type="number" min="10" max="2000" value="' . (int)$cfg['desc_limit'] . '"></div>'
        . '<div class="ow-form-item"><label>举报截图上限（张）</label>'
        . '<input class="ow-input" id="owCRImageMax" type="number" min="1" max="' . (int)OWCR_IMG_MAX . '" value="' . (int)$cfg['image_max'] . '"></div>'
        . '</div>'
        . '<div id="owCRSwitches">'
        . $sw('owCRRequireDesc', '必须填写补充说明', (bool)$cfg['require_desc'], '开启后用户举报时必须填写补充说明')
        . $sw('owCRAllowSelf', '允许举报自己的内容', (bool)$cfg['allow_self'], '关闭后用户不能举报自己发送的内容')
        . $sw('owCRShowGuest', '未登录显示举报入口', (bool)$cfg['show_guest'], '开启后游客也能看到举报入口，点击跳转登录页')
        . $sw('owCRShowLoss', '显示「遭受财产损失」勾选项', (bool)$cfg['show_loss'], '勾选后该举报不送 AI，直接提醒超级管理员处理')
        . $sw('owCRAllowImage', '允许上传举报截图', (bool)$cfg['allow_image'], '用户可在举报弹窗里上传截图作为证据')
        . '</div>'
        . '<button class="ow-btn ow-btn-primary" onclick="OwCRAdmin.saveConfig()">保存配置</button></div>'

        // ---- AI 审核 ----
        . '<div class="ow-card"><h3 style="margin-bottom:10px">AI 审核</h3>'
        . '<div id="owCRAISwitches">'
        . $sw('owCRAIOn', '启用 AI 审核', $aiOn, '举报落库后由计划任务异步送审；同一条消息只审一次')
        . '</div>'
        . '<div class="ow-form-row">'
        . '<div class="ow-form-item" style="min-width:220px;flex:1"><label>接口地址</label>'
        . '<input class="ow-input" id="owCRAIBase" maxlength="200" placeholder="https://api.openai.com/v1" value="'
        . Sec::e($cfg['ai_base']) . '">'
        . '<p style="font-size:12px">OpenAI 兼容的 /chat/completions 服务，必须 https（否则密钥明文出网）。留空 = 不自动审核。</p></div>'
        . '<div class="ow-form-item" style="min-width:160px"><label>模型</label>'
        . '<input class="ow-input" id="owCRAIModel" maxlength="80" value="' . Sec::e($cfg['ai_model']) . '"></div>'
        . '</div>'
        . '<div class="ow-form-item"><label>API Key</label>'
        . '<input class="ow-input" type="password" id="owCRAIKey" maxlength="200" autocomplete="off" placeholder="留空表示不修改">'
        . '<p style="font-size:12px">密钥只写不读：保存后后台再也看不到明文，留空即保持不变。</p></div>'
        . '<div class="ow-form-row">'
        . '<div class="ow-form-item"><label>超时（秒）</label>'
        . '<input class="ow-input" id="owCRAITimeout" type="number" min="' . (int)OWCR_AI_TIMEOUT_MIN . '" max="' . (int)OWCR_AI_TIMEOUT_MAX . '" value="' . (int)$cfg['ai_timeout'] . '">'
        . '<p style="font-size:12px">一次调用 = 连接 + 握手 + 推理，实测通常 7 秒以上，低于 7 秒基本等于没审。</p></div>'
        . '<div class="ow-form-item"><label>送审上下文（条）</label>'
        . '<input class="ow-input" id="owCRAIContext" type="number" min="' . (int)OWCR_AI_CTX_MIN . '" max="' . (int)OWCR_AI_CTX_MAX . '" value="' . (int)$cfg['ai_context'] . '">'
        . '<p style="font-size:12px">以被举报那条为中心，向前后取满这么多条（含它自己）；默认 21 = 前后各 10 条。</p></div>'
        . '<div class="ow-form-item"><label>每次审核条数</label>'
        . '<input class="ow-input" id="owCRAIBatch" type="number" min="' . (int)OWCR_AI_BATCH_MIN . '" max="' . (int)OWCR_AI_BATCH_MAX . '" value="' . (int)$cfg['ai_batch'] . '">'
        . '<p style="font-size:12px">计划任务每分钟跑一次，每次最多审这么多条（一条 = 一次模型调用）。</p></div>'
        . '</div>'
        . '<div class="ow-form-item"><label>审核提示词</label>'
        . '<textarea class="ow-input" id="owCRAIPrompt" rows="12" style="resize:vertical;font-family:Consolas,monospace" placeholder="'
        . Sec::e(mb_substr(owCRAiDefaultPrompt(owCRParseReasons((string)$cfg['reasons'])), 0, 400)) . '">'
        . Sec::e((string)$cfg['ai_prompt']) . '</textarea>'
        . '<p style="font-size:12px">留空 = 按上面的举报理由自动生成（推荐）；写了就固定用你这份。'
        . '聊天记录会填进 {records} 位置，没有这个占位符时会自动追加到末尾。</p></div>'
        . '<div class="ow-cr-btns">'
        . '<button class="ow-btn ow-btn-primary" onclick="OwCRAdmin.saveConfig()">保存配置</button>'
        . '<button class="ow-btn ow-btn-ghost" onclick="OwCRAdmin.fillPrompt()">填入默认提示词</button>'
        . '<button class="ow-btn ow-btn-ghost" onclick="OwCRAdmin.testAI()">测试接口</button>'
        . '</div>'
        . '<div id="owCRAITest" class="ow-cr-test"></div></div>'

        // ---- 通知渠道 ----
        . '<div class="ow-card"><h3 style="margin-bottom:10px">通知（超级管理员）</h3>'
        . '<p class="ow-admin-desc" style="margin-bottom:10px">AI 判违规、或用户勾选「遭受财产损失」的举报，'
        . '会在侧栏「通知」区里以一行通知联系人的形式提醒超级管理员，点开即可处理。</p>'
        . '<div class="ow-form-row">'
        . '<div class="ow-form-item" style="min-width:180px"><label>通知昵称</label>'
        . '<input class="ow-input" id="owCRNotifyName" maxlength="20" placeholder="留空 = 用插件名「内容举报」" value="'
        . Sec::e($cfg['notify_name']) . '"></div>'
        . '</div>'
        // 通知头像：与前台的「换头像」同一套轮子（上传裁方 / 浏览生成式插图），挑一张即刻生效
        . '<div class="ow-form-item"><label>通知头像</label>'
        . '<div class="ow-cr-av">'
        . '<span class="ow-cr-av-prev" id="owCRNotifyAvatarPrev">'
        . ($avPrev !== '' ? '<img src="' . Sec::e($avPrev) . '" alt="通知头像" loading="lazy" decoding="async">' : '')
        . '</span>'
        . '<div class="ow-cr-av-main">'
        . '<div class="ow-cr-av-ops">'
        . '<button type="button" class="ow-btn ow-btn-ghost ow-av-btn" id="owCRNotifyAvatarUp">' . ow_icon('upload', 14) . '上传头像</button>'
        . '<button type="button" class="ow-btn ow-btn-ghost ow-av-btn" id="owCRNotifyAvatarGal">' . ow_icon('image', 14) . '浏览插图</button>'
        . '<button type="button" class="ow-btn ow-btn-ghost ow-av-btn" id="owCRNotifyAvatarDef">恢复默认</button>'
        . '</div>'
        . '<p style="font-size:12px">与前台「换头像」同一套：上传会自动裁成正方形，或从生成式插图里挑一张；挑完即刻生效。留空 = 用默认的插件头像。</p>'
        . '</div>'
        . '<input type="file" id="owCRNotifyAvatarFile" accept="image/*" style="display:none">'
        . '</div>'
        . '<input class="ow-input" id="owCRNotifyAvatar" maxlength="300" placeholder="留空 = 用默认生成头像" value="'
        . Sec::e($cfg['notify_avatar']) . '">'
        . '<div class="ow-av-styles" id="owCRNotifyAvatarBox" style="display:none"></div></div>'
        . '<button class="ow-btn ow-btn-primary" onclick="OwCRAdmin.saveConfig()">保存配置</button></div>'

        // ---- 举报记录（通用列表轮子） ----
        . '<div class="ow-card"><h3 style="margin-bottom:10px">举报记录</h3>'
        // 筛选行：关键词 + 状态（与登录日志、嵌入令牌等后台页同一套结构）
        . '<div class="ow-form-row">'
        . '<div class="ow-form-item" style="min-width:220px;flex:1"><label>搜索</label>'
        . '<input class="ow-input" id="owCRQ" placeholder="举报人 / 被举报人 / 理由 / 补充说明 / 消息ID"'
        . ' onkeydown="if(event.key===\'Enter\')OwCRAdmin.load(1)"></div>'
        . '<div class="ow-form-item" style="min-width:110px"><label>状态</label>'
        . '<select class="ow-input" id="owCRState">'
        . '<option value="-1">全部</option><option value="0">待处理</option>'
        . '<option value="3">待超管处理</option><option value="1">已处理</option><option value="2">已忽略</option>'
        . '</select></div>'
        . '<button class="ow-btn ow-btn-primary" onclick="OwCRAdmin.load(1)">搜索</button>'
        . '<button class="ow-btn ow-btn-ghost" onclick="OwCRAdmin.reset()">重置</button>'
        . '</div>'
        . '<div id="owCRBatch"></div>'
        . '<div class="ow-table-wrap"><table class="ow-table" id="owCRTable"></table></div>'
        . '<div id="owCRPaging"></div></div></div>';
    return $h;
});

/* ============================ API 路由 ============================ */

/** 后台：获取配置 */
Plugin::route('plugin_content_report_config_get', function (array $ctx) use ($crGuard) {
    $crGuard($ctx);
    $cfg = owCRGetConfig();
    $cfg['key_set'] = !empty($cfg['ai_key']) ? 1 : 0;   // 只回「配没配」，明文一个字都不出服务端
    unset($cfg['ai_key']);
    $cfg['default_prompt'] = owCRAiDefaultPrompt(owCRParseReasons((string)$cfg['reasons']));
    $cfg['ai_ready'] = owCRAiReady(owCRGetConfig()) ? 1 : 0;
    $cfg['pending_ai'] = (int)DB::val("SELECT COUNT(*) FROM plugin_content_report_ai WHERE status='pending'");
    $cfg['alert_count'] = owCRAlertCount();
    $cfg['notify_avatar_preview'] = owCRNotifyAvatarPreview();   // 生效中的头像（含核心兜底后的那一张）
    Api::json(['ok' => true, 'data' => $cfg]);
});

/** 后台：保存配置（敏感操作） */
Plugin::route('plugin_content_report_config_save', function (array $ctx) use ($crGuard) {
    $crGuard($ctx);
    $post = $ctx['post'];

    $reasons = trim((string)($post['reasons'] ?? ''));
    if ($reasons === '') Api::json(['ok' => false, 'msg' => '举报理由不能为空']);
    $parsed = owCRParseReasons($reasons);
    if (!$parsed) Api::json(['ok' => false, 'msg' => '举报理由不能为空']);
    owCRSetConfig('reasons', implode("\n", $parsed));

    owCRSetConfig('interval', (string)max(0, (int)($post['interval'] ?? 0)));
    owCRSetConfig('desc_limit', (string)max(10, min(2000, (int)($post['desc_limit'] ?? 200))));
    owCRSetConfig('image_max', (string)max(1, min(OWCR_IMG_MAX, (int)($post['image_max'] ?? OWCR_IMG_DEF))));

    foreach (['require_desc', 'allow_self', 'show_guest', 'show_loss', 'allow_image', 'ai_on'] as $k) {
        owCRSetConfig($k, !empty($post[$k]) ? '1' : '0');
    }

    // AI：地址非空时必须是 https（否则密钥明文出网），保存时先夹一刀
    $base = trim((string)($post['ai_base'] ?? ''));
    if ($base !== '' && stripos($base, 'https://') !== 0) {
        Api::json(['ok' => false, 'msg' => '接口地址必须以 https:// 开头']);
    }
    owCRSetConfig('ai_base', mb_substr(rtrim($base, '/'), 0, 200));
    owCRSetConfig('ai_model', mb_substr(trim((string)($post['ai_model'] ?? 'gpt-4o-mini')), 0, 80));
    owCRSetConfig('ai_timeout', (string)max(OWCR_AI_TIMEOUT_MIN,
        min(OWCR_AI_TIMEOUT_MAX, (int)($post['ai_timeout'] ?? OWCR_AI_TIMEOUT_DEF))));
    owCRSetConfig('ai_context', (string)max(OWCR_AI_CTX_MIN,
        min(OWCR_AI_CTX_MAX, (int)($post['ai_context'] ?? OWCR_AI_CTX_DEF))));
    owCRSetConfig('ai_batch', (string)max(OWCR_AI_BATCH_MIN,
        min(OWCR_AI_BATCH_MAX, (int)($post['ai_batch'] ?? OWCR_AI_BATCH_DEF))));
    // 提示词：空串 = 回到「按举报理由自动生成」（owCRGetConfig 把空串视为未设置）
    owCRSetConfig('ai_prompt', mb_substr(trim((string)($post['ai_prompt'] ?? '')), 0, 4000));
    // 密钥只写不读：留空 = 不修改（保存空串会把它清掉，那不是管理员的本意）
    $key = trim((string)($post['ai_key'] ?? ''));
    if ($key !== '') owCRSetConfig('ai_key', mb_substr($key, 0, 200));

    owCRSetConfig('notify_name', mb_substr(trim((string)($post['notify_name'] ?? '')), 0, 20));
    $nav = trim((string)($post['notify_avatar'] ?? ''));
    if (!owCRNotifyAvatarOk($nav)) {
        Api::json(['ok' => false, 'msg' => '通知头像只能是本站上传的图片或 https 地址']);
    }
    owCRSetConfig('notify_avatar', mb_substr($nav, 0, 300));

    Sec::log('content_report_config', $ctx['actor']['nickname'], [
        'interval' => (int)($post['interval'] ?? 0), 'ai_on' => !empty($post['ai_on']) ? 1 : 0,
    ]);
    Api::json(['ok' => true, 'msg' => '配置已保存']);
}, ['sensitive' => true]);

/** 后台：AI 接口连通性测试（不写队列，只回一段结果） */
Plugin::route('plugin_content_report_ai_test', function (array $ctx) use ($crGuard) {
    $crGuard($ctx);
    $cfg = owCRGetConfig();
    if (!owCRAiReady($cfg)) {
        Api::json(['ok' => false, 'msg' => '请先填写接口地址与模型，并打开「启用 AI 审核」']);
    }
    $sample = trim((string)($ctx['post']['text'] ?? ''));
    if ($sample === '') {
        // 默认样例：一段「广告 + 前后文」，用来一眼看出提示词与接口是否真的在工作
        $sample = "[1] 10-15 20:30 小明：有没有人一起打游戏\n"
            . "[2] 10-15 20:31 游客a1b2：【被举报】加我微信 xyz888 代练，包上王者，先付款再打，"
            . "一条 50，量大有优惠，需要的私聊我，今天最后一天！\n"
            . "[3] 10-15 20:32 小红：踢了吧这人";
    }
    $tpl = owCRAiPromptOf($cfg);
    $r = owCRAiAsk(owCRAiBuild($tpl, $sample));
    Sec::log('content_report_ai_test', $ctx['actor']['nickname'], ['ok' => $r['ok'] ? 1 : 0]);
    Api::json([
        'ok'      => (bool)$r['ok'],
        'msg'     => $r['ok'] ? '接口正常' : (string)$r['raw'],
        'violate' => $r['ok'] ? (!empty($r['violate']) ? 1 : 0) : -1,
        'reason'  => (string)$r['reason'],
        'note'    => (string)$r['note'],
        'raw'     => (string)$r['raw'],
        'ms'      => (int)($r['ms'] ?? 0),
        'prompt_len' => mb_strlen($tpl),
    ]);
});

/** 后台：通知头像上传（走核心 avatar 通道：裁成正方形 + 按内容哈希去重） */
Plugin::route('plugin_content_report_upload_avatar', function (array $ctx) use ($crGuard) {
    $crGuard($ctx);
    if (empty($_FILES['file'])) Api::json(['ok' => false, 'msg' => '未接收到文件']);
    [$ok, $urlOrMsg] = Upload::handle($_FILES['file'], 'avatar');
    Api::json($ok ? ['ok' => true, 'url' => $urlOrMsg] : ['ok' => false, 'msg' => $urlOrMsg]);
});

/**
 * 后台：单独保存通知头像（敏感操作）。
 * 为什么不让它走整页「保存配置」：换头像在前台是**点一下即刻生效**的动作，
 * 这里也不该要求用户先点一遍「保存配置」；而且整页保存会把当前表单里所有字段一起写库，
 * 只想换张图的人不该顺带把半改的提示词也提交上去。
 */
Plugin::route('plugin_content_report_notify_avatar', function (array $ctx) use ($crGuard) {
    $crGuard($ctx);
    $v = trim((string)($ctx['post']['avatar'] ?? ''));
    if (!owCRNotifyAvatarOk($v)) {
        Api::json(['ok' => false, 'msg' => '头像地址不合法：请用「上传头像」或「浏览插图」，或填 https 图片地址']);
    }
    owCRSetConfig('notify_avatar', mb_substr($v, 0, 300));
    Sec::log('content_report_notify_avatar', $ctx['actor']['nickname'], ['custom' => $v === '' ? 0 : 1]);
    Api::json([
        'ok' => true,
        'msg' => $v === '' ? '已恢复默认头像' : '通知头像已更新',
        'avatar' => $v,
        'preview' => owCRNotifyAvatarPreview(),
    ]);
}, ['sensitive' => true]);

/** 后台：举报列表（分页 + 搜索 + 状态筛选；带出 AI 审核结论） */
Plugin::route('plugin_content_report_list', function (array $ctx) use ($crGuard) {
    $crGuard($ctx);
    $post = $ctx['post'];
    $page = max(1, (int)($post['page'] ?? 1));
    $size = min(100, max(1, (int)($post['size'] ?? 20)));
    $status = isset($post['status']) ? (int)$post['status'] : -1;
    $q = trim((string)($post['q'] ?? ''));

    $where = '1=1';
    $args = [];
    if ($status === 3) {                       // 3 = 只看「待超管处理」（提醒过、还没处理）
        $where .= ' AND r.alerted=1 AND r.status=0';
    } elseif ($status >= 0) {
        $where .= ' AND r.status=?';
        $args[] = $status;
    }
    if ($q !== '') {
        $like = '%' . $q . '%';
        $where .= ' AND (r.reporter_nick LIKE ? OR r.target_nick LIKE ? OR r.reason LIKE ? OR r.description LIKE ?';
        array_push($args, $like, $like, $like, $like);
        // 消息 ID 只按整数比 —— 用 CAST 在 PostgreSQL 上会被当成 char(1) 截断
        if (ctype_digit($q)) { $where .= ' OR r.msg_id = ?'; $args[] = (int)$q; }
        $where .= ')';
    }

    $total = (int)DB::val('SELECT COUNT(*) FROM plugin_content_report r WHERE ' . $where, $args);
    $rows = DB::all(
        'SELECT r.*, a.status AS ai_status, a.verdict AS ai_verdict, a.hit AS ai_hit,'
        . ' a.note AS ai_note, a.reviewed_at AS ai_at'
        . ' FROM plugin_content_report r'
        . ' LEFT JOIN plugin_content_report_ai a ON a.msg_id = r.msg_id AND r.msg_id > 0'
        . ' WHERE ' . $where . ' ORDER BY r.id DESC LIMIT ' . $size . ' OFFSET ' . (($page - 1) * $size),
        $args
    );
    Api::json(['ok' => true, 'data' => [
        'list' => $rows, 'total' => $total, 'page' => $page, 'size' => $size,
        'pending_ai' => (int)DB::val("SELECT COUNT(*) FROM plugin_content_report_ai WHERE status='pending'"),
        'alert_count' => owCRAlertCount(),
    ]]);
});

/** 后台：处理举报（标记为已处理/忽略） */
Plugin::route('plugin_content_report_handle', function (array $ctx) use ($crGuard) {
    $crGuard($ctx);
    $id = (int)($ctx['post']['id'] ?? 0);
    $status = (int)($ctx['post']['status'] ?? 1);
    if (!in_array($status, [1, 2], true)) Api::json(['ok' => false, 'msg' => '非法状态']);
    DB::run('UPDATE plugin_content_report SET status=? WHERE id=?', [$status, $id]);
    Sec::log('content_report_handle', $ctx['actor']['nickname'], ['id' => $id, 'status' => $status]);
    Api::json(['ok' => true, 'msg' => '已更新', 'alert_count' => owCRAlertCount()]);
}, ['sensitive' => true]);

/** 后台：批量处理（已处理 / 忽略 / 删除） */
Plugin::route('plugin_content_report_batch', function (array $ctx) use ($crGuard) {
    $crGuard($ctx);
    $post = $ctx['post'];
    $op = (string)($post['op'] ?? '');
    // 前端 OwApi.post 是按 k=v 手拼的，数组会被折成 "1,2,3" —— 两种形态都收
    $ids = $post['ids'] ?? [];
    if (is_string($ids)) $ids = explode(',', $ids);
    if (!is_array($ids)) $ids = [];
    $ids = array_values(array_filter(array_map('intval', $ids), function ($v) { return $v > 0; }));
    if (!$ids) Api::json(['ok' => false, 'msg' => '请先勾选要操作的举报']);
    if (count($ids) > 100) $ids = array_slice($ids, 0, 100);
    $ph = implode(',', array_fill(0, count($ids), '?'));

    if ($op === 'handled' || $op === 'ignored') {
        DB::run('UPDATE plugin_content_report SET status=? WHERE id IN (' . $ph . ')',
            array_merge([$op === 'handled' ? 1 : 2], $ids));
        $msg = '已处理 ' . count($ids) . ' 条';
    } elseif ($op === 'delete') {
        DB::run('DELETE FROM plugin_content_report WHERE id IN (' . $ph . ')', $ids);
        $msg = '已删除 ' . count($ids) . ' 条';
    } else {
        Api::json(['ok' => false, 'msg' => '未知操作']);
    }
    Sec::log('content_report_batch', $ctx['actor']['nickname'], ['op' => $op, 'n' => count($ids)]);
    Api::json(['ok' => true, 'msg' => $msg, 'alert_count' => owCRAlertCount()]);
}, ['sensitive' => true]);

/** 后台：删除举报（敏感操作） */
Plugin::route('plugin_content_report_delete', function (array $ctx) use ($crGuard) {
    $crGuard($ctx);
    $id = (int)($ctx['post']['id'] ?? 0);
    DB::run('DELETE FROM plugin_content_report WHERE id=?', [$id]);
    Sec::log('content_report_delete', $ctx['actor']['nickname'], ['id' => $id]);
    Api::json(['ok' => true, 'msg' => '已删除', 'alert_count' => owCRAlertCount()]);
}, ['sensitive' => true]);

/** 前台：获取举报理由与规则配置
 *  游客也可访问，仅返回 show_guest（决定是否显示举报入口）；
 *  已登录用户额外返回理由列表与 require_desc / allow_self / show_loss / 截图上限。
 */
Plugin::route('plugin_content_report_get_reasons', function (array $ctx) {
    $cfg = owCRGetConfig();
    $isUser = ($ctx['actor']['kind'] ?? '') === 'user';
    $data = ['show_guest' => (int)$cfg['show_guest']];
    if ($isUser) {
        $data['reasons']      = owCRParseReasons($cfg['reasons']);
        $data['desc_limit']   = (int)$cfg['desc_limit'];
        $data['require_desc'] = (int)$cfg['require_desc'];
        $data['allow_self']   = (int)$cfg['allow_self'];
        $data['show_loss']    = (int)$cfg['show_loss'];
        $data['allow_image']  = (int)$cfg['allow_image'];
        $data['image_max']    = (int)$cfg['image_max'];
    }
    Api::json(['ok' => true, 'data' => $data]);
});

/** 前台：举报截图上传（走核心 Upload::handle，附件插件的 WebP 压缩钩子照常生效） */
Plugin::route('plugin_content_report_upload', function (array $ctx) {
    $actor = $ctx['actor'];
    if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '需要登录后操作'], 403);
    $cfg = owCRGetConfig();
    if (empty($cfg['allow_image'])) Api::json(['ok' => false, 'msg' => '本站未开启举报截图上传']);
    if (empty($_FILES['file'])) Api::json(['ok' => false, 'msg' => '未接收到文件']);
    // 频控：单用户每分钟最多 10 张（一次举报最多 image_max 张，正常用不到这么多）
    if (!Sec::rateLimit('report_upload', (string)$actor['id'], 60, 10)) {
        Api::json(['ok' => false, 'msg' => '上传过于频繁，请稍后再试']);
    }
    [$ok, $urlOrMsg] = Upload::handle($_FILES['file'], 'image');
    Api::json($ok ? ['ok' => true, 'url' => $urlOrMsg] : ['ok' => false, 'msg' => $urlOrMsg]);
});

/** 前台：提交举报（已登录用户） */
Plugin::route('plugin_content_report_submit', function (array $ctx) {
    $actor = $ctx['actor'];
    if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '需要登录后操作'], 403);

    $post = $ctx['post'];
    $targetUid = (int)($post['target_uid'] ?? 0);
    $targetNick = trim((string)($post['target_nick'] ?? ''));
    $reason = trim((string)($post['reason'] ?? ''));
    $description = trim((string)($post['description'] ?? ''));
    $roomId = (int)($post['room_id'] ?? 0);
    $msgId = (int)($post['msg_id'] ?? 0);
    $msgTime = (int)($post['msg_time'] ?? 0);
    $loss = !empty($post['loss']) ? 1 : 0;

    if ($targetUid <= 0) Api::json(['ok' => false, 'msg' => '举报对象无效']);

    $cfg = owCRGetConfig();

    // 允许举报自己：关闭时拦截
    if ($targetUid === (int)$actor['id'] && empty($cfg['allow_self'])) {
        Api::json(['ok' => false, 'msg' => '不能举报自己']);
    }

    // v1.2.3：不能举报超级管理员 —— 与 user-block「不能屏蔽超管」同一口径。
    // 超管是平台的管理方，举报通道面向用户；有意见走别的渠道。
    // ⚠️ 服务端必须自己判一次：前端只是把菜单项藏起来，直接调接口照样能提交。
    $tRole = (string)(DB::val('SELECT role FROM users WHERE id=?', [$targetUid]) ?: '');
    if ($tRole === 'admin') Api::json(['ok' => false, 'msg' => '不能举报超级管理员']);

    $reasons = owCRParseReasons($cfg['reasons']);
    if (!$reasons || !in_array($reason, $reasons, true)) Api::json(['ok' => false, 'msg' => '请选择举报理由']);

    $descLimit = (int)$cfg['desc_limit'];
    if (mb_strlen($description) > $descLimit) {
        Api::json(['ok' => false, 'msg' => '补充说明不能超过 ' . $descLimit . ' 字']);
    }
    if (!empty($cfg['require_desc']) && $description === '') {
        Api::json(['ok' => false, 'msg' => '请填写补充说明']);
    }

    // 截图：只接受「本站上传目录里真实存在」的图片地址（Upload::mediaRel 统一校验，防穿越/外站）
    $images = [];
    if (!empty($cfg['allow_image'])) {
        $rawImgs = (string)($post['images'] ?? '');
        $arr = $rawImgs !== '' ? json_decode($rawImgs, true) : [];
        if (is_array($arr)) {
            foreach ($arr as $u) {
                if (count($images) >= (int)$cfg['image_max']) break;
                $rel = Upload::mediaRel((string)$u);
                if ($rel !== null) $images[] = 'uploads/' . $rel;
            }
        }
    }

    // 同一用户举报间隔校验：同一举报人对同一目标在间隔期内不可重复举报
    $interval = (int)$cfg['interval'];
    if ($interval > 0) {
        $last = (int)DB::val(
            'SELECT created_at FROM plugin_content_report WHERE reporter_id=? AND target_uid=? ORDER BY id DESC LIMIT 1',
            [(int)$actor['id'], $targetUid]
        );
        if ($last > 0 && (time() - $last) < $interval) {
            $remain = $interval - (time() - $last);
            Api::json(['ok' => false, 'msg' => '举报过于频繁，请 ' . $remain . ' 秒后再试']);
        }
    }

    // 补充说明做敏感词过滤（走核心 text.filter 钩子）
    if ($description !== '') {
        Plugin::fire('text.filter', [&$description, 'report', $actor]);
    }

    $now = time();
    $isLoss = ($loss === 1 && !empty($cfg['show_loss'])) ? 1 : 0;
    // 「交给超级管理员」的两个入口在写库时就定下来：
    //   ① 勾了财产损失（不走 AI，人工处置）；
    //   ② 举报的**不是具体某条消息**（从「所有成员」搜索里按人举报，msg_id=0）——
    //      没有可审内容，同样直接进超管待办（v1.1.3 站方口径）。
    $alertNow = ($isLoss === 1 || $msgId <= 0) ? 1 : 0;
    // 截图 / 财产损失 / 待办标记直接写进这一行（不另开 UPDATE：少一次写库，也不会出现
    // 「先插入再补字段」中间态被别的请求读到）
    $repId = DB::insert('plugin_content_report', [
        'reporter_id'   => (int)$actor['id'],
        'reporter_nick' => (string)($actor['nickname'] ?? ''),
        'target_uid'    => $targetUid,
        'target_nick'   => $targetNick,
        'reason'        => mb_substr($reason, 0, 64),
        'description'   => mb_substr($description, 0, $descLimit),
        'images'        => $images
            ? json_encode($images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        'loss'          => $isLoss,
        'alerted'       => $alertNow,
        'alert_at'      => $alertNow ? $now : 0,
        'room_id'       => $roomId,
        'msg_id'        => $msgId,
        'msg_time'      => $msgTime,
        'status'        => 0,
        'created_at'    => $now,
    ]);

    // ---- 分流：财产损失 / 按人举报 = 立刻提醒超管（不问 AI）；其余入 AI 队列（同一消息只审一次） ----
    // v1.2.0：凡是**不走 AI** 的，当场就给举报人一个「已受理」结论（manual），
    // 否则这些人永远等不到任何反馈（AI 只审有消息的那些）。
    $route = 'queue';
    if ($isLoss === 1) {
        DB::run('UPDATE plugin_content_report SET result=?, result_at=?, result_hit=? WHERE id=?',
            ['manual', $now, '', $repId]);
        $route = 'loss';
        Sec::log('content_report_loss', (string)($actor['nickname'] ?? ''),
            ['target' => $targetUid, 'msg' => $msgId, 'report' => $repId]);
    } elseif ($msgId <= 0) {
        DB::run('UPDATE plugin_content_report SET result=?, result_at=?, result_hit=? WHERE id=?',
            ['manual', $now, '', $repId]);
        $route = 'admin';
        Sec::log('content_report_manual', (string)($actor['nickname'] ?? ''),
            ['target' => $targetUid, 'report' => $repId, 'why' => 'not_a_message']);
    } else {
        $queueRow = owCRAiEnqueue([
            'msg_id' => $msgId, 'room_id' => $roomId, 'target_uid' => $targetUid,
            'target_nick' => $targetNick, 'reporter_id' => (int)$actor['id'],
            'reporter_nick' => (string)($actor['nickname'] ?? ''), 'reason' => $reason,
            'report_id' => $repId,
        ], $cfg);
        // 这条消息此前已被判违规 → 本次举报一并标成待超管处理，并把已有结论直接回给举报人，
        // 不必再等一次调用（v1.2.0：结果与待办在同一刻落齐）
        if ($queueRow && (string)($queueRow['verdict'] ?? '') === 'violate') {
            // v1.2.2 新口径：此前已判违规 → 不通知举报人，直接自动处置。
            // 消息多半已被自动撤回（物理删行）：撤得掉或已不在都直接结单；
            // 还在但撤不掉（私聊）才交超管人工。
            if (owCRAutoRecall($msgId, (string)($queueRow['hit'] ?? '')) > 0
                || !DB::one('SELECT id FROM messages WHERE id=?', [$msgId])) {
                DB::run('UPDATE plugin_content_report SET status=1 WHERE id=?', [$repId]);
            } else {
                DB::run('UPDATE plugin_content_report SET alerted=1, alert_at=? WHERE id=?', [$now, $repId]);
            }
            $route = 'violate';
        } elseif ($queueRow && (string)($queueRow['verdict'] ?? '') === 'clear') {
            // v1.2.2 新口径：判「正常」也要通知举报人（结论回写）+ 自动结单
            DB::run('UPDATE plugin_content_report SET status=1, result=?, result_at=? WHERE id=?',
                ['clear', $now, $repId]);
            $route = 'clear';
        }
    }

    Sec::log('content_report_submit', (string)($actor['nickname'] ?? ''),
        ['target' => $targetUid, 'reason' => $reason, 'imgs' => count($images), 'route' => $route]);
    Api::json(['ok' => true, 'msg' => '举报已提交，我们会尽快处理']);
});

/** 后台 / 通知面板：待超管处理的举报（含 AI 结论与截图） */
Plugin::route('plugin_content_report_alerts', function (array $ctx) use ($crGuard) {
    $crGuard($ctx);
    $limit = max(1, min(50, (int)($ctx['post']['limit'] ?? 20)));
    $rows = DB::all(
        'SELECT r.*, a.verdict AS ai_verdict, a.hit AS ai_hit, a.note AS ai_note, a.status AS ai_status'
        . ' FROM plugin_content_report r'
        . ' LEFT JOIN plugin_content_report_ai a ON a.msg_id = r.msg_id AND r.msg_id > 0'
        . ' WHERE r.alerted=1 AND r.status=0 ORDER BY r.id DESC LIMIT ' . $limit
    );
    Api::json(['ok' => true, 'data' => ['list' => $rows, 'total' => owCRAlertCount()]]);
});

/**
 * 前台：**消息箱**（v1.2.0 站方口径「独立一个消息框，不要和系统通知一个页面」）。
 *
 * 侧栏「内容举报」那一行点开的就是它：里面是一条条消息（头像 + 昵称 + 时间 + 正文 +
 * 截图缩略图 + 「查看」按钮），而不是系统通知那一页。按身份给不同内容：
 *   超管   = 待超管处理的举报（查看 → 待处理面板）
 *   举报人 = 自己举报的审核结论（查看 → 结论详情）
 */
Plugin::route('plugin_content_report_box', function (array $ctx) {
    $actor = $ctx['actor'];
    if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '需要登录后操作'], 403);
    $uid = (int)$actor['id'];
    $isAdmin = ($actor['role'] ?? '') === 'admin';
    $seen = owCRSeenId($uid);
    $out = [
        'admin'  => $isAdmin ? 1 : 0,
        'total'  => owCRUnreadFor(['id' => $uid, 'role' => $actor['role'] ?? '']),
        'max_id' => owCRMaxReportId(),
        'list'   => [],
    ];

    if ($isAdmin) {
        $rows = DB::all('SELECT * FROM plugin_content_report WHERE alerted=1 AND status=0 ORDER BY id DESC LIMIT 20');
        foreach ($rows as $r) {
            $out['list'][] = [
                'id'            => (int)$r['id'],
                'kind'          => (int)$r['loss'] === 1 ? 'loss' : 'violate',
                'at'            => (int)($r['alert_at'] ?: $r['created_at']),
                'text'          => owCRAlertText($r, owCRAlertCount(), count(owCRRepImages($r))),
                'images'        => array_slice(owCRRepImages($r), 0, 3),
                'unread'        => (int)$r['id'] > $seen ? 1 : 0,
                'reporter_id'   => (int)$r['reporter_id'],
                'reporter_nick' => (string)$r['reporter_nick'],
                'target_uid'    => (int)$r['target_uid'],
                'target_nick'   => (string)$r['target_nick'],
                'reason'        => (string)$r['reason'],
                'desc'          => (string)$r['description'],
            ];
        }
    } else {
        $rows = DB::all(
            "SELECT * FROM plugin_content_report WHERE reporter_id=? AND result<>'' ORDER BY id DESC LIMIT 20",
            [$uid]
        );
        foreach ($rows as $r) {
            $out['list'][] = [
                'id'            => (int)$r['id'],
                'kind'          => 'result',
                'at'            => (int)($r['result_at'] ?: $r['created_at']),
                'text'          => owCRResultText($r),
                'images'        => [],
                'unread'        => (int)$r['id'] > $seen ? 1 : 0,
                'reporter_id'   => 0,
                'reporter_nick' => '',
                'target_uid'    => (int)$r['target_uid'],
                'target_nick'   => (string)$r['target_nick'],
                'reason'        => (string)$r['reason'],
                'desc'          => (string)$r['description'],
                'result'        => (string)$r['result'],
                'hit'           => (string)$r['result_hit'],
            ];
        }
    }
    Api::json(['ok' => true, 'data' => $out]);
});

/**
 * 前台：上报已读水位（敏感操作）。「不要一刷新又是未读」就靠它 ——
 * 未读只按服务端的 `plugin_content_report_seen` 算，客户端刷新拿不回来。
 */
Plugin::route('plugin_content_report_seen', function (array $ctx) {
    $actor = $ctx['actor'];
    if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '需要登录后操作'], 403);
    $id = (int)($ctx['post']['id'] ?? 0);
    if ($id <= 0) $id = owCRMaxReportId();
    owCRMarkSeen((int)$actor['id'], $id);
    Api::json(['ok' => true, 'unread' => owCRUnreadFor(
        ['id' => (int)$actor['id'], 'role' => $actor['role'] ?? '']
    )]);
}, ['sensitive' => true]);

/** 前台：我的某条举报的审核结论详情（只认自己的，防 IDOR） */
Plugin::route('plugin_content_report_result', function (array $ctx) {
    $actor = $ctx['actor'];
    if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '需要登录后操作'], 403);
    $id = (int)($ctx['post']['id'] ?? 0);
    $r = DB::one('SELECT * FROM plugin_content_report WHERE id=?', [$id]);
    if (!$r || (int)$r['reporter_id'] !== (int)$actor['id']) {
        Api::json(['ok' => false, 'msg' => '只能查看自己的举报'], 403);
    }
    // 被举报的那条消息（还在的话）取一段原文，供举报人核对自己举报的是哪一句
    $msgText = '';
    if ((int)$r['msg_id'] > 0) {
        $m = DB::one('SELECT * FROM messages WHERE id=?', [(int)$r['msg_id']]);
        if ($m) $msgText = mb_substr(owCRMsgText($m), 0, 120);
    }
    Api::json(['ok' => true, 'data' => [
        'id'         => (int)$r['id'],
        'reason'     => (string)$r['reason'],
        'desc'       => (string)$r['description'],
        'result'     => (string)$r['result'],
        'hit'        => (string)$r['result_hit'],
        'result_at'  => (int)$r['result_at'],
        'target_uid' => (int)$r['target_uid'],
        'target_nick' => (string)$r['target_nick'],
        'msg_text'   => $msgText,
        'text'       => owCRResultText($r),
    ]]);
});

/* ============================ 资源注册 ============================ */

Plugin::asset('js', 'content-report/chat.js');
Plugin::asset('js', 'content-report/admin.js');
Plugin::asset('css', 'content-report/style.css');

// ⚠️ v1.5.31 起下面这段已作废：核心 pageHead 统一引整包 CSS，插件不再自己发切片（保留作历史）
/**
 * 主程序只在页面里引入 `?action=assets&type=js`，**没有**引入 css ——
 * 插件样式必须自己挂 page.head 输出 <link>，否则注册了也不会加载
 * （v1.0.x 的 admin.css 就是这么一直没生效的）。
 * 用 plugin= / file= 只取本插件这一份（不带这两个参数会把全部启用插件的 CSS 一起打包下发）。
 */
// v1.5.31：CSS 不再由插件自己发切片 —— 核心 pageHead 统一引整包
// （整包本就包含本插件的样式；此前每个插件各发一条，首屏变成 16 个串行 CSS 请求）
