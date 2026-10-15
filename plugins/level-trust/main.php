<?php
/**
 * 等级信任插件（v2.0.0 公式重制）
 *
 * 规则出处：设计文档/等级设计.md（该文档描述的是 v1.x 旧公式，v2.0 起以本文件注释为准）
 *
 * ── 等级公式 ────────────────────────────────────────────────
 *   L = 1 + ⌊D × lv_step⌋        （lv_step 默认 1.3，后台可配）
 *   D = 累计完成每日保底任务的天数
 *   即「平均每完成一天任务升 1.3 级」——与旧公式典型用户的实际速度一致
 *   （旧公式 D + W，W 日均约 0.3 天：连签每 3 天 +0.3、每日发言 +0.1~0.3）。
 *   不设满级；每天最多升 ⌊lv_step⌋+1 = 2 级。
 *   未完成任务不降级：等级只取 max(旧等级, 公式值)，后台直设过的等级不会被公式压回去。
 *
 *   v1.x 旧公式（已废弃）：L = 1 + ⌊D + min(W, 0.5D) × 追赶系数⌋，W 由连签/发言/文件
 *   随机累加、封顶、再加全服中位数追赶系数——12 个参数、随机数、跨用户耦合，故重制。
 *   weight / file_w_days 两列保留不删（存量数据），但新公式不再读写。
 *
 * ── 分阶段每日任务（v2.0 起无「替代」、无「挑战」）────────────
 *   全部阶段统一为：登录 + 群发言 N 条（N 见后台 stage_gm，默认 1/3/5/5/10）
 *   取消原因：替代任务组（上传↔私聊首条、下载↔群发言）判定分支多、
 *   后台要懂每组的等价关系才能调参；且无文件/无好友的用户绕一圈仍等价于群发言。
 *   挑战任务（上传/下载）从不阻塞升级，只是加权来源，加权已随公式一并移除。
 *
 * ── 五次功能解锁（设计文档第五节）────────────────────────────
 *   3 级  自定义头像、贴纸收藏（3 张起，按 sticker_tiers 分档递增）、单文件 ≤ 1/4 全局上限
 *   10 级 加好友、创建 3 个群聊、单文件 ≤ 1/2 全局上限
 *   20 级 创建 5 个群聊（贴纸上限见后台档位表：20:100 … 60:999）
 *   35 级 管理权限候选资格（本插件不做，仅标记）
 *   50 级 创建最多 8 个群、高级文件权限（= 全局上限）
 *   ⚠️ 全部限制可由后台「等级信任 → 限制开关」一键关闭（默认开启）。
 *
 * 安全与性能约束：
 *   - 所有 SQL 参数化；等级行按 user_id 主键读写。
 *   - `user.active` 每请求都会触发（含长轮询），因此**同日内只做一次主键查询就返回**，
 *     不写库、不算等级；跨天才做迁移与结算。
 *   - 单插件抛异常不影响主流程（Plugin::fire 已兜底）。
 */
if (!defined('OWLSGO_VERSION')) exit;   // 禁止直接 HTTP 访问本文件

/* ============================ 建表 ============================ */

/** 懒建表：只在真正用到时执行（插件 main.php 顶层不做副作用） */
function owLTBoot(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $pk = 'INTEGER PRIMARY KEY';        // SQLite
    $ts = 'INTEGER NOT NULL';
    if (DB::driver() === 'mysql') {
        $pk = 'BIGINT UNSIGNED PRIMARY KEY';
        $ts = 'BIGINT UNSIGNED NOT NULL';
    } elseif (DB::driver() === 'pgsql') {
        $pk = 'BIGSERIAL PRIMARY KEY';
    }
    DB::run("CREATE TABLE IF NOT EXISTS plugin_level_trust (
        user_id      {$pk},
        level        INTEGER NOT NULL DEFAULT 1,      -- 当前等级
        days         INTEGER NOT NULL DEFAULT 0,      -- D：累计完成每日任务的天数
        weight       REAL    NOT NULL DEFAULT 0,      -- v1.x 遗留：旧公式的累计加权，v2.0 起不再读写
        login_streak INTEGER NOT NULL DEFAULT 0,      -- C：连续登录天数（展示/断签保护用，不进公式）
        login_days   INTEGER NOT NULL DEFAULT 0,      -- 累计登录天数（展示用，不进公式）
        file_w_days  INTEGER NOT NULL DEFAULT 0,      -- v1.x 遗留：文件加权累积次数，v2.0 起不再使用
        " . DB::textCol('day') . ",                    -- 上次活跃日 Y-m-d
        " . DB::textCol('today', "'{}'") . ",          -- 今日进度 JSON
        settled      INTEGER NOT NULL DEFAULT 0,      -- 今日是否已结算（加权每天只结算一次）
        frozen_day   INTEGER NOT NULL DEFAULT 0,      -- 被冻结的自然日 Ymd（0=未冻结）
        protect_used INTEGER NOT NULL DEFAULT 0,      -- 本月已用「连续登录保护」次数
        " . DB::textCol('protect_mon') . ",            -- 保护计数所属月份 Y-m
        patch_used   INTEGER NOT NULL DEFAULT 0,      -- 本周已补签次数
        " . DB::textCol('patch_week') . ",             -- 补签计数所属周（Y-W）
        updated_at   {$ts} DEFAULT 0)");
    DB::run("CREATE TABLE IF NOT EXISTS plugin_level_config (
        k TEXT PRIMARY KEY, " . DB::textCol('v') . ")");
    // v1.2.44：按自然日归档的活跃数据（后台「活跃趋势」用）。
    //   不逐条记流水 —— 那样表会无上限膨胀，而趋势图只需要每天的汇总数。
    DB::run("CREATE TABLE IF NOT EXISTS plugin_level_daily (
        day      TEXT PRIMARY KEY,            -- Y-m-d
        actives  INTEGER NOT NULL DEFAULT 0,  -- 当日活跃过的用户数（去重）
        logins   INTEGER NOT NULL DEFAULT 0,  -- 当日新增登录人天
        upgrades INTEGER NOT NULL DEFAULT 0,  -- 当日升级次数
        msgs     INTEGER NOT NULL DEFAULT 0,  -- 计入任务的有效发言条数
        files    INTEGER NOT NULL DEFAULT 0,  -- 文件操作次数
        rooms    INTEGER NOT NULL DEFAULT 0,  -- 建群次数
        updated_at {$ts} DEFAULT 0)");
}

/** 确保当天的汇总行存在（各驱动没有统一的 INSERT OR IGNORE，用「查不到就插」最稳） */
function owLTEnsureDay(string $day): void
{
    owLTBoot();
    $has = DB::val('SELECT day FROM plugin_level_daily WHERE day=?', [$day]);
    if ($has === null || $has === false) {
        try {
            DB::run('INSERT INTO plugin_level_daily (day, updated_at) VALUES (?,?)', [$day, time()]);
        } catch (Throwable $e) {
            // 并发下另一个进程可能刚插入同一天 → 主键冲突，忽略即可
        }
    }
}

/**
 * 按天累加一项活跃指标（次数类）。
 * ⚠️ actives（人数）**不能**走这里 —— 同一用户一天活跃多次只应算 1 人，
 *    那种去重计数走 owLTMarkActive()。
 */
function owLTBump(string $field, int $n = 1): void
{
    static $known = ['logins', 'upgrades', 'msgs', 'files', 'rooms'];
    if (!in_array($field, $known, true)) return;   // 字段名走白名单，杜绝拼进 SQL 的列被污染
    $day = date('Y-m-d');
    owLTEnsureDay($day);
    DB::run('UPDATE plugin_level_daily SET ' . $field . '=' . $field . '+?, updated_at=? WHERE day=?',
        [$n, time(), $day]);
}

/** 记录「当日活跃人数」（同一用户当天只计一次） */
function owLTMarkActive(int $uid): void
{
    if ($uid <= 0) return;
    $day = date('Y-m-d');
    $key = 'act_' . $day . '_' . $uid;
    // 进程内先挡一次：长轮询下同一请求会多次触发 user.active，没必要每次都查库
    if (!empty($GLOBALS[$key])) return;
    $GLOBALS[$key] = 1;

    $has = DB::val('SELECT v FROM plugin_level_config WHERE k=?', [$key]);
    if ($has !== null && $has !== false) return;
    try {
        DB::run('INSERT INTO plugin_level_config (k, v) VALUES (?,?)', [$key, '1']);
    } catch (Throwable $e) {
        return;                                    // 并发下已被别人插入 → 说明今天已计过
    }
    owLTEnsureDay($day);
    DB::run('UPDATE plugin_level_daily SET actives=actives+1, updated_at=? WHERE day=?', [time(), $day]);
}

/**
 * 清理过期的「当日活跃」去重键（只保留最近 N 天）。
 * ⚠️ 不清理的话 plugin_level_config 会按「天数 × 人数」无限膨胀，
 *    而这些键只在当天有用 —— 留着就是纯垃圾，还会拖慢配置读取。
 */
function owLTPurgeActiveKeys(int $keepDays = 40): void
{
    owLTBoot();
    $cut = 'act_' . date('Y-m-d', time() - $keepDays * 86400);
    // k 形如 act_2026-10-06_20；下划线在 LIKE 里是通配符，必须 ESCAPE 后才能按字面匹配
    DB::run("DELETE FROM plugin_level_config WHERE k LIKE 'act@_%' ESCAPE '@' AND k < ?", [$cut]);
}

/**
 * 全部可调参数与默认值（v2.0：解锁等级 / 建群名额 / 有效发言 / 阶段阈值全部可在
 * 后台「等级信任 → 参数设置」里改；加权参数已随旧公式一并移除，只留一个 lv_step）。
 *
 * ⚠️ 每加一个新键，必须同时：
 *   ① 在这里给默认值；② 在后台表单里给输入框；③ 在 cfg_save 的白名单里放行。
 * 漏了 ③ 的表现是「后台保存成功但值没变」，很难查。
 */
function owLTDefaults(): array
{
    return [
        // ---- 总开关 ----
        'gating'        => '1',     // 是否按等级限制功能（0=只看等级不做限制）
        // ---- 等级速度 ----
        'lv_step'       => '1.3',   // 每日任务换算的等级进度：L = 1 + ⌊D × lv_step⌋
        // ---- 能力解锁等级 ----
        'lv_avatar'     => '3',     // 自定义头像
        'lv_sticker'    => '3',     // 上传/设置贴纸（v1.3.30：20 → 3，数量按 sticker_tiers 分档）
        'lv_friend'     => '10',    // 添加好友
        'lv_file'       => '3',     // 能上传文件（否则禁止）
        'lv_file_half'  => '10',    // 单文件上限放宽到全局 1/2
        'lv_file_full'  => '50',    // 单文件上限 = 全局上限
        'lv_mod_cand'   => '35',    // 管理权限候选资格（仅标记）
        'lv_honor'      => '60',    // 荣誉标识（不解锁新功能）
        // ---- 建群名额与积分 ----
        'room_min_level' => '10',   // 达到该等级起才有免费名额
        'room_tiers'     => "10:3\n20:5\n50:8",   // 等级:免费名额，逐行
        // v1.3.30：贴纸收藏上限分档（等级:张数，逐行，取最高命中档）。
        // 20 级=100 与核心旧全局上限对齐（存量用户无感），60 级=999 封顶。
        'sticker_tiers'  => "3:3\n6:10\n10:30\n20:100\n30:200\n40:400\n50:700\n60:999",
        'room_point_cost' => '50',  // 每超出 1 个群（或等级不够时）消耗的积分；0=不允许付费建群
        // ---- 有效发言判定 ----
        'msg_cooldown'   => '15',   // 距上一条计入任务的发言至少 N 秒
        'msg_per_minute' => '5',    // 每分钟最多计入 N 条
        'msg_dedupe'     => '10',   // 与最近 N 条内容去重
        'msg_min_len'    => '2',    // 内容至少 N 个字符
        // ---- 阶段阈值与任务量 ----
        'stage_2'        => '6',    // 日常阶段起始等级
        'stage_3'        => '16',   // 活跃阶段
        'stage_4'        => '31',   // 核心阶段
        'stage_5'        => '46',   // 荣誉阶段
        'stage_gm'       => '1,3,5,5,10',   // 五个阶段各自的群发言条数
    ];
}

/**
 * 读配置（字符串）。
 * ⚠️ 缓存放在 $GLOBALS 而不是函数内 static：函数级 static 无法被 owLTSetCfg 清掉，
 *    同请求内「后台刚关掉限制 → 上传仍被拦」这种不一致查起来非常费劲。
 */
function owLTCfg(string $k): string
{
    owLTBoot();
    if (!isset($GLOBALS['_haLTCfg']) || !is_array($GLOBALS['_haLTCfg'])) $GLOBALS['_haLTCfg'] = [];
    if (array_key_exists($k, $GLOBALS['_haLTCfg'])) return $GLOBALS['_haLTCfg'][$k];
    $v = DB::val('SELECT v FROM plugin_level_config WHERE k=?', [$k]);
    $def = owLTDefaults();
    $GLOBALS['_haLTCfg'][$k] = ($v === null || $v === false || $v === '') ? ($def[$k] ?? '') : (string)$v;
    return $GLOBALS['_haLTCfg'][$k];
}

/** 读整数配置 */
function owLTInt(string $k): int
{
    return (int)owLTCfg($k);
}

/** 读浮点配置（小数写进表里是字符串，必须显式转） */
function owLTFloat(string $k): float
{
    return (float)owLTCfg($k);
}

function owLTSetCfg(string $k, string $v): void
{
    owLTBoot();
    DB::run('DELETE FROM plugin_level_config WHERE k=?', [$k]);
    DB::run('INSERT INTO plugin_level_config (k, v) VALUES (?,?)', [$k, $v]);
    unset($GLOBALS['_haLTCfg'][$k]);      // 立刻失效，同请求内后续读取拿到新值
}

/**
 * 建群名额档位：解析 room_tiers（每行「等级:名额」）。
 * 返回 [[lv=>int, n=>int], ...] 按等级升序；解析不出任何档位时回落到默认表。
 */
function owLTRoomTiers(): array
{
    static $cached = null;
    if ($cached !== null) return $cached;
    $out = [];
    foreach (preg_split('/[\r\n,;]+/', owLTCfg('room_tiers')) ?: [] as $line) {
        $line = trim((string)$line);
        if ($line === '') continue;
        if (!preg_match('/^(\d{1,4})\s*[:：]\s*(\d{1,4})$/u', $line, $m)) continue;
        $out[] = ['lv' => (int)$m[1], 'n' => (int)$m[2]];
    }
    if (!$out) {
        $out = [['lv' => 10, 'n' => 3], ['lv' => 20, 'n' => 5], ['lv' => 50, 'n' => 8]];
    }
    usort($out, fn($a, $b) => $a['lv'] <=> $b['lv']);
    return ($cached = $out);
}

/** 某等级的免费建群名额（未达最低等级 = 0） */
function owLTRoomQuota(int $level): int
{
    if ($level < owLTInt('room_min_level')) return 0;
    $n = 0;
    foreach (owLTRoomTiers() as $t) {
        if ($level >= $t['lv']) $n = $t['n'];
    }
    return $n;
}

/**
 * 某等级的贴纸收藏上限（v1.3.30，核心 addSticker 经 sticker.quota 钩子取这里）。
 * 档位格式同 room_tiers（每行「等级:张数」，取最高命中档）；
 * 未达首档 = 0，配合 lv_sticker 的上传闸门，语义是「还没解锁」。
 */
function owLTStickerQuota(int $level): int
{
    static $cached = null;
    if ($cached === null) {
        $cached = [];
        foreach (preg_split('/[\r\n,;]+/', owLTCfg('sticker_tiers')) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line === '') continue;
            if (!preg_match('/^(\d{1,4})\s*[:：]\s*(\d{1,4})$/u', $line, $m)) continue;
            $cached[] = ['lv' => (int)$m[1], 'n' => (int)$m[2]];
        }
        usort($cached, fn($a, $b) => $a['lv'] <=> $b['lv']);
    }
    if (!$cached) return $level >= owLTInt('lv_sticker') ? 100 : 0;   // 档位表被清空 → 回落旧行为
    $n = 0;
    foreach ($cached as $t) {
        if ($level >= $t['lv']) $n = $t['n'];
    }
    return $n;
}

/* ============================ 等级计算 ============================ */

/**
 * 阶段阈值（可配）：返回 [日常, 活跃, 核心, 荣誉] 四个起始等级。
 * ⚠️ 必须保证单调递增，否则 owLTStageNo 会出现「高等级落在低阶段」的错乱。
 */
function owLTStageBounds(): array
{
    $b = [
        max(2, owLTInt('stage_2')),
        max(3, owLTInt('stage_3')),
        max(4, owLTInt('stage_4')),
        max(5, owLTInt('stage_5')),
    ];
    for ($i = 1; $i < 4; $i++) if ($b[$i] <= $b[$i - 1]) $b[$i] = $b[$i - 1] + 1;
    return $b;
}

/** 五个阶段的群发言条数（可配，逗号分隔；缺项用默认值补齐） */
function owLTStageGm(): array
{
    $def = [1, 3, 5, 5, 10];
    $parts = preg_split('/[\s,，]+/u', owLTCfg('stage_gm')) ?: [];
    $out = [];
    for ($i = 0; $i < 5; $i++) {
        $v = isset($parts[$i]) ? (int)trim((string)$parts[$i]) : 0;
        $out[$i] = $v > 0 ? $v : $def[$i];
    }
    return $out;
}

/**
 * 阶段任务表（v2.0 起只有保底任务，无替代、无挑战）。
 * base = 保底任务，当天全部完成才记 1 个活跃天数（D+1）。
 * 各阶段任务统一为「登录 + 群发言 N 条」，N 取后台 stage_gm——
 * 群发言是唯一人人可做的行为（全员默认群），不需要替代组兜底。
 */
function owLTStage(int $level): array
{
    $b = owLTStageBounds();          // [日常, 活跃, 核心, 荣誉]
    $gm = owLTStageGm();             // 五阶段各自的群发言条数
    $login = [['k' => 'login', 'n' => 1]];

    if ($level < $b[0]) {
        return ['name' => '新手阶段', 'from' => 1, 'to' => $b[0] - 1,
            'base' => array_merge($login, [['k' => 'gm', 'n' => $gm[0]]])];
    }
    if ($level < $b[1]) {
        return ['name' => '日常阶段', 'from' => $b[0], 'to' => $b[1] - 1,
            'base' => array_merge($login, [['k' => 'gm', 'n' => $gm[1]]])];
    }
    if ($level < $b[2]) {
        return ['name' => '活跃阶段', 'from' => $b[1], 'to' => $b[2] - 1,
            'base' => array_merge($login, [['k' => 'gm', 'n' => $gm[2]]])];
    }
    if ($level < $b[3]) {
        return ['name' => '核心阶段', 'from' => $b[2], 'to' => $b[3] - 1,
            'base' => array_merge($login, [['k' => 'gm', 'n' => $gm[3]]])];
    }
    return ['name' => '荣誉阶段', 'from' => $b[3], 'to' => 0,
        'base' => array_merge($login, [['k' => 'gm', 'n' => $gm[4]]])];
}

/** 阶段号（1~5），用于前端配色与后台分布统计 */
function owLTStageNo(int $level): int
{
    $b = owLTStageBounds();
    if ($level < $b[0]) return 1;
    if ($level < $b[1]) return 2;
    if ($level < $b[2]) return 3;
    if ($level < $b[3]) return 4;
    return 5;
}

/** 阶段名（供后台统计与前台页面复用，避免各处再写一份中文） */
function owLTStageName(int $no): string
{
    $m = [1 => '新手阶段', 2 => '日常阶段', 3 => '活跃阶段', 4 => '核心阶段', 5 => '荣誉阶段'];
    return $m[$no] ?? '新手阶段';
}

/** 某项进度。fact（群文件操作）是上传+下载的合计，不单独存 */
function owLTProg(array $t, string $k): int
{
    if ($k === 'fact') return (int)($t['fup'] ?? 0) + (int)($t['fdl'] ?? 0);
    return (int)($t[$k] ?? 0);
}

/** 单个任务是否完成（v2.0 起无替代组，达标即完成） */
function owLTTaskOk(array $task, array $t): bool
{
    return owLTProg($t, $task['k']) >= (int)$task['n'];
}

function owLTBaseOk(array $stage, array $t): bool
{
    foreach ($stage['base'] as $task) if (!owLTTaskOk($task, $t)) return false;
    return true;
}

/** 全服等级中位数（后台概览的 KPI 用；v2.0 起不再进等级公式） */
function owLTMedian(): float
{
    static $m = null;
    if ($m !== null) return $m;
    owLTBoot();
    $n = (int)DB::val('SELECT COUNT(*) FROM plugin_level_trust');
    if ($n <= 0) return ($m = 1.0);
    $off = (int)floor($n / 2);                       // 字面量整数，无注入风险
    $row = DB::one('SELECT level FROM plugin_level_trust ORDER BY level LIMIT 1 OFFSET ' . $off);
    return ($m = $row ? (float)$row['level'] : 1.0);
}

/**
 * 等级公式（v2.0）：L = 1 + ⌊D × lv_step⌋
 * D = 累计完成每日保底任务的天数；lv_step 默认 1.3（后台可配）。
 * 速度对标旧公式的典型用户（旧 W 日均约 0.3 天：连签每 3 天 +0.3、每日发言 +0.1~0.3）：
 *   D=7/10/20/30 天 → 旧典型 Lv.10/14/27/40，新公式 10/14/27/40，逐段一致。
 */
function owLTCalc(int $days): int
{
    $step = owLTFloat('lv_step');
    if ($step < 1) $step = 1.0;          // 后台把倍率调成 0 会让所有人永远不升级，兜底
    return max(1, 1 + (int)floor($days * $step));
}

/**
 * 按公式重算等级并写回 $row。
 * ⚠️ 取 max(旧等级, 公式值)：未完成当天任务不降级；后台直设过等级（或旧公式下
 *    加权攒得多）时，纯公式会算出比当前更低的等级——不兜这一手就是「什么都没做却掉级」。
 *    所有改等级的地方（每日结算、补签后台操作）都必须走这里，别各处自己算一遍。
 */
function owLTRecalc(array &$row): void
{
    $row['level'] = max((int)$row['level'], owLTCalc((int)$row['days']));
}

/* ============================ 行读写 ============================ */

function owLTDecode(string $json): array
{
    $a = json_decode($json ?: '{}', true);
    return is_array($a) ? $a : [];
}

/** 取等级行；不存在则按「注册默认 Lv.1」创建 */
function owLTRow(int $uid): array
{
    owLTBoot();
    $row = DB::one('SELECT * FROM plugin_level_trust WHERE user_id=?', [$uid]);
    if ($row) return $row;
    DB::run('INSERT INTO plugin_level_trust (user_id, level, days, weight, updated_at) VALUES (?,?,?,?,?)',
        [$uid, 1, 0, 0.0, time()]);
    return DB::one('SELECT * FROM plugin_level_trust WHERE user_id=?', [$uid]) ?: [];
}

function owLTSave(int $uid, array $row, array $t): void
{
    DB::run('UPDATE plugin_level_trust SET level=?, days=?, weight=?, login_streak=?, login_days=?,'
        . ' file_w_days=?, day=?, today=?, settled=?, frozen_day=?, protect_used=?, protect_mon=?,'
        . ' patch_used=?, patch_week=?, updated_at=? WHERE user_id=?', [
        (int)$row['level'], (int)$row['days'], (float)$row['weight'],
        (int)$row['login_streak'], (int)$row['login_days'], (int)$row['file_w_days'],
        (string)$row['day'], json_encode($t, JSON_UNESCAPED_UNICODE), (int)$row['settled'],
        (int)$row['frozen_day'], (int)$row['protect_used'], (string)$row['protect_mon'],
        (int)$row['patch_used'], (string)$row['patch_week'], time(), $uid,
    ]);
}

/**
 * 跨天迁移 + 记一次登录。
 * ⚠️ 同日内直接返回（零写入）—— user.active 每请求都触发，长轮询下不能每次都写库。
 */
function owLTTouch(int $uid): array
{
    $row = owLTRow($uid);
    if (!$row) return [null, []];
    $today = date('Y-m-d');
    if ((string)$row['day'] === $today) {
        // 今日已处理过：只把进度解出来，不做任何迁移
        return [$row, owLTDecode((string)$row['today'])];
    }

    $t = [];                                          // 新的一天：进度清零
    $prev = (string)$row['day'];
    $streak = (int)$row['login_streak'];
    if ($prev !== '') {
        $gap = (int)round((strtotime($today) - strtotime($prev)) / 86400);
        if ($gap === 1) {
            $streak++;                                // 连续
        } else {
            // 断签：每月 2 次保护机会，用完则连续天数减半（不清零，已得加权不受影响）
            $mon = date('Y-m');
            if ((string)$row['protect_mon'] !== $mon) { $row['protect_mon'] = $mon; $row['protect_used'] = 0; }
            if ((int)$row['protect_used'] < 2) {
                $row['protect_used'] = (int)$row['protect_used'] + 1;   // 消耗保护，连续天数保留
            } else {
                $streak = (int)floor($streak / 2);
            }
        }
    } else {
        $streak = 1;                                  // 首次活跃
    }
    $row['login_streak'] = $streak;
    $row['login_days'] = (int)$row['login_days'] + 1;
    $row['day'] = $today;
    $row['settled'] = 0;
    $t['login'] = 1;                                  // 登录任务：当天首次活跃即完成
    owLTSave($uid, $row, $t);
    return [$row, $t];
}

/* ============================ 有效行为判定 ============================ */

/**
 * 有效发言内容判定（设计文档第六节）：
 * 长度 ≥ 2、非纯表情、非纯链接。
 * 冷却（15 秒）与去重在 owLTAddMsg 里做 —— 它们依赖「今日进度」里的状态。
 */
function owLTValidText(string $s): bool
{
    $s = trim($s);
    if (mb_strlen($s) < max(1, owLTInt('msg_min_len'))) return false;
    if (preg_match('#^https?://\S+$#i', $s)) return false;              // 纯链接
    $stripped = preg_replace(
        '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2190}-\x{21FF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}\x{20E3}\s]/u',
        '', $s);
    if ($stripped === null || $stripped === '') return false;           // 纯表情
    return true;
}

/**
 * 记一次发言。
 * @param bool $isGroup 群聊（room_id>0）还是私聊
 */
function owLTAddMsg(int $uid, string $content, string $type, bool $isGroup): bool
{
    if ($uid <= 0) return false;
    if (!in_array($type, ['text', 'mention'], true)) return false;   // 图片/文件/系统不算「发言」
    if (!owLTValidText($content)) return false;

    [$row, $t] = owLTTouch($uid);
    if (!$row) return false;
    if ((int)$row['frozen_day'] === (int)date('Ymd')) return false;  // 当天被冻结

    $now = time();
    // ① 冷却：距上一条**计入任务**的发言至少 N 秒
    $cd = max(0, owLTInt('msg_cooldown'));
    if (!empty($t['last_at']) && $now - (int)$t['last_at'] < $cd) return false;
    // ② 本分钟最多计入 N 条
    $perMin = max(1, owLTInt('msg_per_minute'));
    $minute = (int)floor($now / 60);
    if (empty($t['minute']) || (int)$t['minute'] !== $minute) { $t['minute'] = $minute; $t['minute_n'] = 0; }
    if ((int)($t['minute_n'] ?? 0) >= $perMin) return false;
    // ③ 与最近 N 条内容不重复
    $h = md5(mb_strtolower(trim($content)));
    $recent = is_array($t['recent'] ?? null) ? $t['recent'] : [];
    if (in_array($h, array_slice($recent, 0, max(1, owLTInt('msg_dedupe'))), true)) return false;

    $t['minute_n'] = (int)$t['minute_n'] + 1;
    $t['last_at'] = $now;
    array_unshift($recent, $h);
    $t['recent'] = array_slice($recent, 0, max(1, owLTInt('msg_dedupe')));

    if ($isGroup) {
        $t['gm'] = owLTProg($t, 'gm') + 1;   // 群发言是唯一人人可做的任务行为
    }
    owLTAfterProgress($uid, $row, $t);
    return true;                                            // 真正计入了任务
}

/**
 * 文件操作（upload / download）——v2.0 起只统计活跃趋势，不再记任务进度
 * （旧公式里上传/下载是加权来源，还带「可用群发言替代」的挑战任务，已一并移除）。
 * 下载自己的文件不计（防刷）的规则保留。
 */
function owLTAddFile(int $uid, string $kind, bool $own = false): void
{
    if ($uid <= 0) return;
    if ($kind === 'download' && $own) return;
    owLTMarkActive($uid);
    owLTBump('files');
}

/* ============================ 结算 ============================ */

/**
 * 进度变化后：当天保底任务全部完成 → 记 1 个活跃天数（D+1）→ 按公式重算等级。
 * v2.0 起没有加权、没有随机、没有封顶——完成一天就是一天，公式自己算等级。
 */
function owLTAfterProgress(int $uid, array $row, array $t): void
{
    if ((int)$row['settled'] === 1) { owLTSave($uid, $row, $t); return; }   // 今天已结算过
    $stage = owLTStage((int)$row['level']);
    if (!owLTBaseOk($stage, $t)) { owLTSave($uid, $row, $t); return; }

    $row['days'] = (int)$row['days'] + 1;
    $row['settled'] = 1;
    $before = (int)$row['level'];
    owLTRecalc($row);          // 不降级由 owLTRecalc 保证
    owLTSave($uid, $row, $t);
    if ((int)$row['level'] > $before) owLTBump('upgrades');
}

/* ============================ 功能解锁 ============================ */

/**
 * 当前等级已解锁的能力。
 * ratio = 单文件上限相对「全局上限」的比例（0 = 禁止上传）
 */
function owLTUnlocks(int $level): array
{
    // v1.2.44：所有门槛改为**后台可配**（lv_* 系列）。
    // quota 是本等级的免费建群名额，pay_cost 是超额后每个群的积分价（0=不允许付费）。
    return [
        'avatar'   => $level >= owLTInt('lv_avatar'),
        'sticker'  => $level >= owLTInt('lv_sticker'),
        'sticker_quota' => owLTStickerQuota($level),   // 贴纸收藏上限（0=未解锁）
        'friend'   => $level >= owLTInt('lv_friend'),
        'ratio'    => $level >= owLTInt('lv_file_full') ? 1.0
                    : ($level >= owLTInt('lv_file_half') ? 0.5
                    : ($level >= owLTInt('lv_file') ? 0.25 : 0.0)),
        'quota'    => owLTRoomQuota($level),
        'pay_cost' => max(0, owLTInt('room_point_cost')),
        'min_room_level' => owLTInt('room_min_level'),
        'mod_cand' => $level >= owLTInt('lv_mod_cand'),   // 管理权限候选资格（仅标记）
        'honor'    => $level >= owLTInt('lv_honor'),      // 荣誉标识（不解锁新功能）
    ];
}

/** 取用户当前等级（无记录 = 注册默认 Lv.1） */
function owLTLevelOf(int $uid): int
{
    if ($uid <= 0) return 1;
    owLTBoot();
    $v = DB::val('SELECT level FROM plugin_level_trust WHERE user_id=?', [$uid]);
    return $v === null || $v === false ? 1 : max(1, (int)$v);
}

/* ============================ 钩子 ============================ */

/** 建群成功（活跃趋势的「建群次数」） */
Plugin::on('room.created', function ($roomId, $actor, $charged = 0) {
    if (($actor['kind'] ?? '') !== 'user') return;
    owLTBump('rooms');
});

/** 每日登录（会话仍在就不走登录钩子，所以挂 user.active） */
Plugin::on('user.active', function (array $actor) {
    if (($actor['kind'] ?? '') !== 'user') return;
    $uid = (int)$actor['id'];
    if ($uid <= 0) return;
    $isNewDay = (string)(owLTRow($uid)['day'] ?? '') !== date('Y-m-d');
    [$row, $t] = owLTTouch($uid);
    if (!$row) return;
    // v1.2.44 活跃趋势：当日活跃人数（同一人一天只计一次）
    owLTMarkActive($uid);
    if ($isNewDay) owLTBump('logins');      // 跨天才算一次「登录人天」
    // 登录本身就可能直接达成低阶段保底任务（新手阶段 = 登录 + 群发言 1，还差发言）
    owLTAfterProgress($uid, $row, $t);
});

/** 发言 */
Plugin::on('message.after_send', function ($id, $actor, $roomId, $content = '', $type = 'text', $toUserId = 0) {
    if (($actor['kind'] ?? '') !== 'user') return;                 // 游客没有等级
    $uid = (int)$actor['id'];
    owLTMarkActive($uid);
    if (owLTAddMsg($uid, (string)$content, (string)$type, (int)$roomId > 0)) owLTBump('msgs');
});

/** 文件上传成功（附件上传插件）—— 仅活跃趋势统计 */
Plugin::on('file.uploaded', function (int $uid, string $kind, int $size = 0) {
    owLTAddFile($uid, 'upload');
});

/** 文件下载完成（核心 file_download）—— 仅活跃趋势统计 */
Plugin::on('file.downloaded', function (int $uid, int $msgId, int $ownerId = 0) {
    owLTAddFile($uid, 'download', $ownerId > 0 && $ownerId === $uid);
});

/** 资料卡：把等级随 user_card 一起下发（前端无需再打一次接口） */
Plugin::on('user.card', function (array &$u, array $actor) {
    $lv = owLTLevelOf((int)($u['id'] ?? 0));
    $u['level'] = $lv;
    $u['level_stage'] = owLTStage($lv)['name'];
    $u['level_stage_no'] = owLTStageNo($lv);
    $u['level_honor'] = $lv >= 60 ? 1 : 0;
});

/** 上传闸门：头像 3 级起、贴纸 20 级起 */
Plugin::on('upload.guard', function (bool &$allow, string &$reason, string $kind, array $actor) {
    if (owLTCfg('gating') !== '1') return;
    if (($actor['role'] ?? '') === 'admin') return;            // 超管不受限
    $lv = owLTLevelOf((int)$actor['id']);
    $u = owLTUnlocks($lv);
    if ($kind === 'avatar' && !$u['avatar']) {
        $allow = false; $reason = '自定义头像需 ' . owLTInt('lv_avatar') . ' 级解锁（当前 Lv.' . $lv . '）';
    } elseif ($kind === 'sticker' && !$u['sticker']) {
        $allow = false; $reason = '上传贴纸需 ' . owLTInt('lv_sticker') . ' 级解锁（当前 Lv.' . $lv . '）';
    }
});

/** 贴纸收藏上限档位（v1.3.30）：核心 Upload::addSticker 经此钩子取数。
 *  gating 关闭 / 超管 → 不动 $limit，保持核心默认 100。 */
Plugin::on('sticker.quota', function (int &$limit, array $actor) {
    if (owLTCfg('gating') !== '1') return;
    if (($actor['role'] ?? '') === 'admin') return;
    $limit = owLTStickerQuota(owLTLevelOf((int)($actor['id'] ?? 0)));
});

/** 加好友闸门：10 级起 */
Plugin::on('friend.guard', function (bool &$allow, string &$reason, array $actor, int $friendId) {
    if (owLTCfg('gating') !== '1') return;
    if (($actor['role'] ?? '') === 'admin') return;
    $lv = owLTLevelOf((int)$actor['id']);
    if (!owLTUnlocks($lv)['friend']) {
        $allow = false; $reason = '添加好友需 ' . owLTInt('lv_friend') . ' 级解锁（当前 Lv.' . $lv . '）';
    }
});

/**
 * 建群闸门（v1.2.44）。
 *
 * 三种结果，核心按 $dec 决定放行 / 收费 / 拒绝：
 *   ① 免费名额没用完      → free=true,  cost=0
 *   ② 名额用完 或 等级不够 → free=false, cost=单价（单价为 0 时拒绝）
 *   ③ 站点不允许付费建群  → allowed=false + 原因
 *
 * ⚠️ reason 必须**说清为什么**并给出可执行的信息（还差几级 / 需要多少积分 / 当前多少），
 *    用户原话就是「提升为什么不能创建群聊的原因」—— 一句「等级不足」等于没说。
 */
Plugin::on('room.create.gate', function (array &$dec, array $actor) {
    if (owLTCfg('gating') !== '1') return;                 // 后台关闭了等级限制
    if (($actor['role'] ?? '') === 'admin') return;         // 超管不受限，也不收费
    if (($actor['kind'] ?? '') !== 'user') return;          // 游客走不到这里（核心已拦）

    $uid  = (int)$actor['id'];
    $lv   = owLTLevelOf($uid);
    $u    = owLTUnlocks($lv);
    $used = (int)DB::val('SELECT COUNT(*) FROM rooms WHERE owner_id=?', [$uid]);
    $cost = (int)$u['pay_cost'];
    $pts  = (int)DB::val('SELECT points FROM users WHERE id=?', [$uid]);

    $dec['quota'] = (int)$u['quota'];
    $dec['used']  = $used;
    $dec['level'] = $lv;
    $dec['min_room_level'] = (int)$u['min_room_level'];   // 前端拼「还差几级」用

    // ① 还有免费名额
    if ($used < (int)$u['quota']) { $dec['free'] = true; return; }

    // ②/③ 需要付费：等级不够 或 名额用完
    $why = $lv < (int)$u['min_room_level']
        ? '建群需 ' . (int)$u['min_room_level'] . ' 级（当前 Lv.' . $lv . '）'
        : '免费名额已用完（' . $used . '/' . (int)$u['quota'] . '）';

    if ($cost <= 0) {
        // 后台把单价设为 0 = 不允许用积分绕过
        $dec['allowed'] = false;
        $dec['reason']  = $why . '，且站点未开启「消耗积分创建群聊」。';
        return;
    }
    $dec['free']   = false;
    $dec['cost']   = $cost;
    // ⚠️ reason 必须是**纯文本**：前端会整体 esc() 再插入，
    //    这里塞 <b> 会被原样显示成「<b>50</b>」（v1.2.44 实测踩过）。
    //    需要强调的数字由前端用结构化字段（cost/quota/used/level/points）自己拼。
    $dec['reason'] = $why . '，可消耗 ' . $cost . ' 积分创建（当前 ' . $pts . ' 积分）。';
});

/**
 * v1.2.43：**建群不再受等级限制**（用户定案）。

/** 单文件大小上限：按等级取全局上限的比例 */
Plugin::on('upload.maxsize', function (int &$maxBytes, array $actor) {
    if (owLTCfg('gating') !== '1') return;
    if (($actor['role'] ?? '') === 'admin') return;            // 超管用全局上限
    $ratio = owLTUnlocks(owLTLevelOf((int)$actor['id']))['ratio'];
    if ($ratio <= 0) { $maxBytes = 1; return; }                // 未解锁：只允许极小文件（实际会超限被拒）
    $maxBytes = (int)floor($maxBytes * $ratio);
});

/** 聊天附件闸门：3 级起才解锁文件上传（设计文档第 1 次解锁） */
Plugin::on('attachment.guard', function (bool &$allow, string &$reason, array $actor) {
    if (owLTCfg('gating') !== '1') return;
    if (($actor['role'] ?? '') === 'admin') return;
    $lv = owLTLevelOf((int)$actor['id']);
    if (owLTUnlocks($lv)['ratio'] <= 0) {
        $allow = false; $reason = '上传文件需 ' . owLTInt('lv_file') . ' 级解锁（当前 Lv.' . $lv . '）';
    }
});

/* ============================ 计划任务 ============================ */
// 每天清理一次「当日活跃」去重键。不清理的话这张表会按「天数 × 人数」无限膨胀。
Plugin::cron('purge_active_keys', 86400, function () {
    owLTPurgeActiveKeys(40);
}, '清理 40 天前的「当日活跃」去重键（活跃趋势统计用）');

/* ============================ 对外钩子（供其它插件读写等级） ============================ */

/**
 * `user.level.get` —— 取某用户等级。
 * 调用方把 $level 初始化为**哨兵值 -1**：没插件响应时它仍是 -1，
 * 调用方据此判断「等级插件未安装 / 未启用」并隐藏相关 UI。
 *
 * ```php
 * $lv = -1;
 * Plugin::fire('user.level.get', [&$lv, $uid]);
 * if ($lv < 0) { /* 无等级体系 *\/ }
 * ```
 */
Plugin::on('user.level.get', function (int &$level, int $uid) {
    if ($uid <= 0) return;
    $level = owLTLevelOf($uid);
});

/**
 * `user.level.set` —— 设置某用户等级（后台「用户管理」用）。
 * $ok 传入 **null**：没有插件响应时它保持 null，调用方据此提示「未安装等级插件」，
 * 而不是误报成功。等级只改数值，不重算加权（与插件后台的「直接设置」同口径）。
 *
 * ```php
 * $ok = null; $msg = '';
 * Plugin::fire('user.level.set', [$uid, $lv, &$ok, &$msg]);
 * ```
 */
Plugin::on('user.level.set', function (int $uid, int $level, ?bool &$ok, string &$msg) {
    if ($uid <= 0) { $ok = false; $msg = '非法用户'; return; }
    if ($level < 1) { $ok = false; $msg = '等级需为 ≥1 的整数'; return; }
    $row = owLTRow($uid);
    if (!$row) { $ok = false; $msg = '用户不存在'; return; }
    $t = owLTDecode((string)$row['today']);
    $row['level'] = max(1, $level);
    owLTSave($uid, $row, $t);
    $ok = true;
    $msg = '等级已设为 Lv.' . (int)$row['level'];
});

/* ============================ 前台路由 ============================ */

/** 我的等级（含今日任务进度，供资料卡/后续展示用） */
Plugin::route('plugin_level_trust_mine', function (array $ctx) {
    if (($ctx['actor']['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '游客没有等级']);
    $uid = (int)$ctx['actor']['id'];
    [$row, $t] = owLTTouch($uid);
    $stage = owLTStage((int)$row['level']);
    $tasks = [];
    foreach ($stage['base'] as $task) {
        $tasks[] = ['k' => $task['k'], 'n' => (int)$task['n'], 'cur' => owLTProg($t, $task['k'])];
    }
    Api::json([
        'ok' => true,
        'level' => (int)$row['level'], 'days' => (int)$row['days'],
        'stage' => $stage['name'], 'stage_no' => owLTStageNo((int)$row['level']),
        'honor' => (int)$row['level'] >= 60 ? 1 : 0,
        'login_streak' => (int)$row['login_streak'], 'login_days' => (int)$row['login_days'],
        'settled' => (int)$row['settled'],
        'frozen' => (int)$row['frozen_day'] === (int)date('Ymd') ? 1 : 0,
        'tasks' => $tasks,
        'unlocks' => owLTUnlocks((int)$row['level']),
    ]);
});


/* ============================ 前台等级页（?page=level） ============================ */

/** 任务 key → 中文（v2.0 起任务只剩登录与群发言；旧键保留映射以防存量 today JSON 回显） */
function owLTTaskLabel(string $k): string
{
    $m = [
        'login' => '登录', 'gm' => '群发言',
        'gfirst' => '当天首条群发言', 'pfirst' => '当天首条私聊',
        'fup' => '上传文件', 'fdl' => '下载文件', 'fact' => '群文件操作',
    ];
    return $m[$k] ?? $k;
}

/** 舞台配色（与资料卡徽章一致） */
function owLTStageColor(int $no): string
{
    $c = [1 => '#9AA5B1', 2 => '#0099FF', 3 => '#13A8A8', 4 => '#7A5AF8', 5 => '#D4A017'];
    return $c[$no] ?? '#9AA5B1';
}

/** 能力解锁对比表：返回每一档的能力清单（档位取自全部配置过的等级门槛） */
function owLTUnlockMatrix(): array
{
    // 每一行 = 一个有意义的档位（所有能力门槛 ∪ 阶段起点），去重后升序
    // ⚠️ 必须**按配置现读**：后台改了解锁等级，这张表要跟着变，不能写死。
    $marks = array_values(array_unique(array_merge(
        [owLTInt('lv_file'), owLTInt('lv_avatar'), owLTInt('lv_friend'), owLTInt('lv_file_half'),
         owLTInt('lv_sticker'), owLTInt('lv_mod_cand'), owLTInt('lv_file_full'), owLTInt('lv_honor')],
        owLTStageBounds()
    )));
    sort($marks);
    foreach ($marks as $lv) {
        $u = owLTUnlocks($lv);
        $ratio = (float)$u['ratio'];
        $rows[] = [
            'lv' => $lv,
            'stage' => owLTStageName(owLTStageNo($lv)),
            'quota' => (int)$u['quota'],
            'avatar' => $u['avatar'],
            'sticker' => $u['sticker_quota'],   // 展示为张数上限（0=未解锁）
            'friend' => $u['friend'],
            'file' => $ratio <= 0 ? '不可上传' : ($ratio >= 1 ? '全局上限' : ('1/' . (int)round(1 / $ratio) . ' 上限')),
            'mod' => $u['mod_cand'],
            'honor' => $u['honor'],
        ];
    }
    return $rows;
}

/**
 * 注册前台页 ?page=level。
 * 游客也能看（等级规则是公开信息），但「我的进度」那块会换成提示。
 */
Plugin::page('level', '等级', function (array $actor, $user = null) {
    $isUser = ($actor['kind'] ?? '') === 'user';
    $uid = (int)($actor['id'] ?? 0);
    $lv = $isUser ? owLTLevelOf($uid) : 1;
    $stageNo = owLTStageNo($lv);
    $stage = owLTStage($lv);
    $u = owLTUnlocks($lv);
    $h = '';

    /* ---------- 我的等级 ---------- */
    $h .= '<div class="ow-card ow-lv-hero ow-lv-s' . $stageNo . '">'
        . '<div class="ow-lv-hero-lv"><span class="ow-lt-badge ow-lt-s' . $stageNo
        . ($lv >= owLTInt('lv_honor') ? ' ow-lt-honor' : '') . '">Lv.' . $lv . '</span>'
        . '<span class="ow-lv-hero-stage">' . Sec::e($stage['name']) . '</span></div>';

    if ($isUser) {
        [$row, $t] = owLTTouch($uid);
        $days = (int)$row['days'];
        $step = owLTFloat('lv_step');
        if ($step < 1) $step = 1.0;
        // 下一级所需天数：⌊D×step⌋ ≥ lv 即升到 lv+1 → D ≥ lv/step。
        // 用 $lv（真实等级，可能被后台直设过）而不是公式值，否则直设过的用户
        // 会看到「进度条 90% 但还差 21 天」这种自相矛盾的提示。
        $needDays = (int)ceil($lv / $step);
        $pct = (int)max(0, min(100, round(($days * $step - ($lv - 1)) * 100)));

        $h .= '<div class="ow-lv-hero-meta">'
            . '<span>累计完成 <b>' . $days . '</b> 天</span>'
            . '<span>每天完成任务 = <b>' . $step . '</b> 级进度</span>'
            . '<span>连续登录 <b>' . (int)$row['login_streak'] . '</b> 天</span>'
            . '<span>距 Lv.' . ($lv + 1) . ' 还需 <b>' . max(0, $needDays - $days) . '</b> 天任务</span>'
            . '</div>'
            . '<div class="ow-lv-bar"><i style="width:' . $pct . '%"></i></div>';
    } else {
        $h .= '<div class="ow-lv-hero-meta"><span>登录后即可看到你的等级进度与今日任务。</span></div>';
    }
    $h .= '</div>';

    /* ---------- 阶段总览（v1.2.59）---------- */
    $b = owLTStageBounds();
    $gm = owLTStageGm();
    $stageDefs = [
        [1, '新手阶段', 1, $b[0] - 1],
        [2, '日常阶段', $b[0], $b[1] - 1],
        [3, '活跃阶段', $b[1], $b[2] - 1],
        [4, '核心阶段', $b[2], $b[3] - 1],
        [5, '荣誉阶段', $b[3], 0],
    ];
    $h .= '<div class="ow-card"><h3 class="ow-lv-h3">阶段总览</h3>'
        . '<div class="ow-lv-stages"><div class="ow-lv-stage-connector"></div>';
    foreach ($stageDefs as $sd) {
        $sno = $sd[0];
        $isCur = $stageNo === $sno;
        $isPast = $stageNo > $sno;
        $cls = 'ow-lv-stage-item' . ($isCur ? ' is-cur' : ($isPast ? ' is-past' : ' is-future'));
        $range = $sd[3] > 0 ? ($sd[2] === $sd[3] ? (string)$sd[2] : ($sd[2] . '~' . $sd[3])) : ($sd[2] . ' 及以上');
        $h .= '<div class="' . $cls . '" style="--stage-color:' . owLTStageColor($sno) . '">'
            . '<div class="ow-lv-stage-dot-wrap"><div class="ow-lv-stage-dot"></div></div>'
            . '<div class="ow-lv-stage-name">' . Sec::e($sd[1]) . '</div>'
            . '<div class="ow-lv-stage-range">Lv.' . Sec::e($range) . '</div>'
            . '</div>';
    }
    $h .= '</div></div>';

    /* ---------- 今日任务 ---------- */
    if ($isUser) {
        $h .= '<div class="ow-card"><h3 class="ow-lv-h3">今日任务</h3>';
        $done = 0;
        foreach ($stage['base'] as $task) {
            $has = owLTProg($t, $task['k']);
            $n = (int)$task['n'];
            $ok = $has >= $n;
            if ($ok) $done++;
            $h .= '<div class="ow-lv-task">'
                . '<span class="ow-lv-task-k">' . Sec::e(owLTTaskLabel($task['k'])) . '</span>'
                . '<span class="ow-lv-task-bar"><i style="width:' . (int)min(100, $n > 0 ? $has / $n * 100 : 0) . '%"></i></span>'
                . '<span class="ow-lv-task-v' . ($ok ? ' done' : '') . '">' . $has . ' / ' . $n . '</span>'
                . '</div>';
        }
        $h .= '<div class="ow-lv-task-sum">'
            . '保底任务完成 <b>' . $done . ' / ' . count($stage['base']) . '</b>，'
            . '全部完成今天记 1 个活跃天数（升 <b>' . owLTFloat('lv_step') . '</b> 级进度）'
            . ((int)$row['settled'] === 1 ? '（<b style="color:var(--ow-green)">今日已结算</b>）' : '')
            . '。任务次日清零，断签不降级。</div>'
            . '</div>';
    }

    /* ---------- 等级公式 ---------- */
    $h .= '<div class="ow-card"><h3 class="ow-lv-h3">等级怎么算</h3>'
        . '<div class="ow-lv-formula">L = 1 + ⌊ D × ' . owLTFloat('lv_step') . ' ⌋</div>'
        . '<ul class="ow-lv-ul">'
        . '<li><b>D</b>：累计完成每日保底任务的天数，每天最多 +1</li>'
        . '<li><b>' . owLTFloat('lv_step') . '</b>：每个活跃天数换算的等级进度（后台可调，默认 1.3）</li>'
        . '<li>平均每完成一天任务升 <b>' . owLTFloat('lv_step') . '</b> 级；'
        . '因为取整，实际是「攒几天跳一级」的节奏，不是每天准时跳</li>'
        . '<li>各阶段保底任务都是「登录 + 群发言 N 条」，N 随阶段递增（见下方一览）</li>'
        . '<li>未完成当天任务不降级，已攒下的天数不会被收回</li>'
        . '</ul>'
        . '<div class="ow-lv-tip">有效发言需同时满足：内容 ≥ ' . owLTInt('msg_min_len') . ' 字、非纯表情/纯图片/纯链接、'
        . '距上一条计入任务 ≥ ' . owLTInt('msg_cooldown') . ' 秒、每分钟 ≤ ' . owLTInt('msg_per_minute')
        . ' 条、与最近 ' . owLTInt('msg_dedupe') . ' 条不重复。</div>'
        . '</div>';

    /* ---------- 各等级条件一览 ---------- */
    $h .= '<div class="ow-card"><h3 class="ow-lv-h3">各阶段任务一览</h3><table class="ow-lv-table">'
        . '<tr><th>阶段</th><th>等级</th><th>保底任务</th></tr>';
    $defs = [
        [1, '新手阶段', 1, $b[0] - 1, $gm[0]],
        [2, '日常阶段', $b[0], $b[1] - 1, $gm[1]],
        [3, '活跃阶段', $b[1], $b[2] - 1, $gm[2]],
        [4, '核心阶段', $b[2], $b[3] - 1, $gm[3]],
        [5, '荣誉阶段', $b[3], 0, $gm[4]],
    ];
    foreach ($defs as $d) {
        $range = $d[3] > 0 ? ($d[2] === $d[3] ? (string)$d[2] : ($d[2] . '~' . $d[3])) : ($d[2] . ' 及以上');
        $base = '登录 + 群发言 ' . $d[4] . ' 条';
        $h .= '<tr class="ow-lv-tr' . ($stageNo === $d[0] ? ' is-cur' : '') . '">'
            . '<td><span class="ow-lv-dot" style="background:' . owLTStageColor($d[0]) . '"></span>'
            . Sec::e($d[1]) . '</td>'
            . '<td>' . Sec::e($range) . '</td><td>' . Sec::e($base) . '</td></tr>';
    }
    $h .= '</table></div>';

    /* ---------- 能力解锁对比 ---------- */
    // 9 列，窄屏上压进卡片会把每列挤成一竖溜换行（表头是中文双/三字词，一换行就错位）。
    // 外层 .ow-lv-card-scroll 在 ≤640px 时开横向滚动，配合 style.css 里的 width:auto，
    // 让表格按内容自然展开、超出部分横向滑。
    $h .= '<div class="ow-card"><h3 class="ow-lv-h3">能力解锁对比</h3><div class="ow-lv-card-scroll"><table class="ow-lv-table">'
        . '<tr><th>等级</th><th>阶段</th><th>建群名额</th><th>头像</th><th>贴纸上限</th>'
        . '<th>加好友</th><th>单文件上限</th><th>管理候选</th><th>荣誉</th></tr>';
    foreach (owLTUnlockMatrix() as $r) {
        $y = '<span class="ow-lv-yes">✓</span>';
        $n = '<span class="ow-lv-no">—</span>';
        $h .= '<tr class="' . ($r['lv'] === $lv ? 'is-cur' : '') . '">'
            . '<td><b>Lv.' . (int)$r['lv'] . '</b></td>'
            . '<td>' . Sec::e($r['stage']) . '</td>'
            . '<td>' . ((int)$r['quota'] > 0 ? (int)$r['quota'] . ' 个' : $n) . '</td>'
            . '<td>' . ($r['avatar'] ? $y : $n) . '</td>'
            . '<td>' . ((int)$r['sticker'] > 0 ? (int)$r['sticker'] . ' 张' : $n) . '</td>'
            . '<td>' . ($r['friend'] ? $y : $n) . '</td>'
            . '<td>' . Sec::e($r['file']) . '</td>'
            . '<td>' . ($r['mod'] ? $y : $n) . '</td>'
            . '<td>' . ($r['honor'] ? $y : $n) . '</td></tr>';
    }
    $h .= '</table></div>'
        . '<div class="ow-lv-tip">建群名额用完、或等级未到 '
        . owLTInt('room_min_level') . ' 级时，可消耗 <b>' . owLTInt('room_point_cost')
        . '</b> 积分创建群聊（每个群一次）。把该单价设为 0 即为「不允许用积分绕过」。</div>'
        . '</div>';

    return $h;
});

/* ============================ 后台 ============================ */

/** 管理员鉴权：必须定义在 use($ltGuard) 之前（use 捕获的是变量值） */
$ltGuard = function (array $ctx): void {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
};

/** 后台概览：中位数 + 各阶段分布 + 近 N 天活跃趋势 */
Plugin::route('plugin_level_trust_stats', function (array $ctx) use ($ltGuard) {
    $ltGuard($ctx);
    owLTBoot();
    $rows = DB::all('SELECT level FROM plugin_level_trust');
    $dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
    $sum = 0;
    foreach ($rows as $r) { $dist[owLTStageNo((int)$r['level'])]++; $sum += (int)$r['level']; }

    // 阶段名 + 等级区间随配置现算：后台改了阶段阈值，概览里的分布标签要跟着变，
    // 不能像旧版那样把「新手 1-5」写死在前端（v1.1.5）。
    $b = owLTStageBounds();
    $ranges = [];
    $prev = 1;
    foreach ($b as $start) {
        $end = $start - 1;
        $ranges[] = $prev === $end ? (string)$prev : $prev . '-' . $end;
        $prev = $start;
    }
    $ranges[] = $b[3] . '+';
    $stageMeta = [];
    foreach ([1, 2, 3, 4, 5] as $no) {
        $stageMeta[] = ['no' => $no, 'name' => owLTStageName($no), 'range' => $ranges[$no - 1]];
    }

    // 近 30 天趋势（含今天）。补齐空白天，否则折线会在没数据的日子断开。
    $days = (int)($ctx['post']['days'] ?? 30);
    $days = max(7, min(90, $days));
    $from = date('Y-m-d', time() - ($days - 1) * 86400);
    $got = [];
    foreach (DB::all('SELECT * FROM plugin_level_daily WHERE day>=? ORDER BY day ASC', [$from]) as $r) {
        $got[(string)$r['day']] = $r;
    }
    $trend = [];
    for ($i = 0; $i < $days; $i++) {
        $d = date('Y-m-d', time() - ($days - 1 - $i) * 86400);
        $r = $got[$d] ?? null;
        $trend[] = [
            'day' => $d,
            'actives' => (int)($r['actives'] ?? 0),
            'logins'  => (int)($r['logins'] ?? 0),
            'upgrades' => (int)($r['upgrades'] ?? 0),
            'msgs'    => (int)($r['msgs'] ?? 0),
            'files'   => (int)($r['files'] ?? 0),
            'rooms'   => (int)($r['rooms'] ?? 0),
        ];
    }

    Api::json([
        'ok' => true,
        'users' => count($rows),
        'median' => owLTMedian(),
        'avg' => $rows ? round($sum / count($rows), 1) : 0,
        'dist' => $dist,
        'stage_meta' => $stageMeta,
        'gating' => owLTCfg('gating'),
        'trend' => $trend,
        // 口径说明随数据一起下发，前端直接展示 —— 写死在 JS 里日后容易与实际脱节
        'trend_note' => '近 ' . $days . ' 天 · 活跃用户仅统计登录用户（游客不计入），'
            . '历史长度受「当日活跃」去重键保留 40 天限制；升级次数为当日实际升幅之和（可能一次升 2 级）。',
    ]);
});

/** 读取全部可调参数（后台配置表单回填） */
Plugin::route('plugin_level_trust_cfg_get', function (array $ctx) use ($ltGuard) {
    $ltGuard($ctx);
    owLTBoot();
    $cfg = [];
    foreach (owLTDefaults() as $k => $def) $cfg[$k] = owLTCfg($k);
    Api::json(['ok' => true, 'cfg' => $cfg, 'tiers' => owLTRoomTiers()]);
});

/**
 * 保存可调参数。
 * ⚠️ 白名单 = owLTDefaults() 的键集合：只有在这里出现过的键才允许写入。
 *    这样即使前端偷偷塞了别的键（比如往配置表里写垃圾），也落不了库。
 */
Plugin::route('plugin_level_trust_cfg_save', function (array $ctx) use ($ltGuard) {
    $ltGuard($ctx);
    $post = $ctx['post'];
    $allow = array_keys(owLTDefaults());
    $saved = 0;
    foreach ($allow as $k) {
        if (!isset($post[$k])) continue;
        $v = trim((string)$post[$k]);
        // 数值键统一做范围收敛：后台填了 "-5" 或 "999999" 不该让算法彻底失灵
        if (preg_match('/^-?\d+(\.\d+)?$/', $v)) {
            $f = (float)$v;
            if ($f < 0) $f = 0;
            if ($f > 1000000) $f = 1000000;
            $v = (string)(floor($f) == $f ? (int)$f : $f);
        }
        if (mb_strlen($v) > 200) $v = mb_substr($v, 0, 200);
        owLTSetCfg($k, $v);
        $saved++;
    }
    // 档位表：解析不出任何行就拒绝，避免把「全部名额清零」这种破坏性配置写进去
    if (isset($post['room_tiers'])) {
        $tmp = [];
        foreach (preg_split('/[\r\n,;]+/', (string)$post['room_tiers']) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line === '') continue;
            if (preg_match('/^(\d{1,4})\s*[:：]\s*(\d{1,4})$/u', $line, $m)) $tmp[] = ['lv' => (int)$m[1], 'n' => (int)$m[2]];
        }
        if (!$tmp) Api::json(['ok' => false, 'msg' => '名额档位格式不对，每行需形如「等级:名额」，例如 10:3']);
        owLTSetCfg('room_tiers', (string)$post['room_tiers']);
        $saved++;
    }
    Sec::log('level_cfg_save', (string)($ctx['actor']['nickname'] ?? ''), ['items' => $saved]);
    Api::json(['ok' => true, 'msg' => '参数已保存（' . $saved . ' 项）']);
});
Plugin::sensitive('plugin_level_trust_cfg_save');   // 改全站等级规则：敏感


/** 查单个用户 */
Plugin::route('plugin_level_trust_user', function (array $ctx) use ($ltGuard) {
    $ltGuard($ctx);
    $uid = (int)($ctx['post']['uid'] ?? 0);
    if ($uid <= 0) Api::json(['ok' => false, 'msg' => '请填写用户 ID']);
    $u = DB::one('SELECT id, nickname, role FROM users WHERE id=?', [$uid]);
    if (!$u) Api::json(['ok' => false, 'msg' => '用户不存在']);
    [$row, $t] = owLTTouch($uid);
    $stage = owLTStage((int)$row['level']);
    $tasks = [];
    foreach ($stage['base'] as $task) {
        $tasks[] = ['k' => $task['k'], 'n' => (int)$task['n'], 'cur' => owLTProg($t, $task['k'])];
    }
    Api::json([
        'ok' => true,
        'uid' => $uid, 'nickname' => (string)$u['nickname'], 'role' => (string)$u['role'],
        'level' => (int)$row['level'], 'days' => (int)$row['days'],
        'stage' => $stage['name'], 'login_streak' => (int)$row['login_streak'], 'login_days' => (int)$row['login_days'],
        'settled' => (int)$row['settled'], 'frozen' => (int)$row['frozen_day'] === (int)date('Ymd') ? 1 : 0,
        'patch_used' => (int)$row['patch_used'], 'protect_used' => (int)$row['protect_used'],
        'tasks' => $tasks, 'today' => $t,
    ]);
});

/** 管理员操作：补签 / 冻结 / 解冻 / 直接设置 */
Plugin::route('plugin_level_trust_op', function (array $ctx) use ($ltGuard) {
    $ltGuard($ctx);
    $uid = (int)($ctx['post']['uid'] ?? 0);
    $op = (string)($ctx['post']['op'] ?? '');
    if ($uid <= 0) Api::json(['ok' => false, 'msg' => '请填写用户 ID']);
    $row = owLTRow($uid);
    if (!$row) Api::json(['ok' => false, 'msg' => '用户不存在']);
    $t = owLTDecode((string)$row['today']);

    switch ($op) {
        case 'patch':       // 补签：每周最多 1 次，视为完成当天全部保底任务，活跃天数 +1
            $week = date('o-W');
            if ((string)$row['patch_week'] !== $week) { $row['patch_week'] = $week; $row['patch_used'] = 0; }
            if ((int)$row['patch_used'] >= 1) Api::json(['ok' => false, 'msg' => '本周补签次数已用完']);
            $row['patch_used'] = (int)$row['patch_used'] + 1;
            $row['days'] = (int)$row['days'] + 1;
            $row['settled'] = 1;
            owLTRecalc($row);
            owLTSave($uid, $row, $t);
            Api::json(['ok' => true, 'msg' => '已补签，等级提升至 Lv.' . (int)$row['level']]);

        case 'freeze':      // 冻结当天任务进度（结算不再进行）
            $row['frozen_day'] = (int)date('Ymd');
            owLTSave($uid, $row, $t);
            Api::json(['ok' => true, 'msg' => '已冻结今日任务']);

        case 'unfreeze':
            $row['frozen_day'] = 0;
            owLTSave($uid, $row, $t);
            Api::json(['ok' => true, 'msg' => '已解除冻结']);

        case 'set':         // 直接设置等级与天数（迁移或纠错用）
            $lv = max(1, (int)($ctx['post']['level'] ?? 1));
            $days = max(0, (int)($ctx['post']['days'] ?? (int)$row['days']));
            $row['level'] = $lv;
            $row['days'] = $days;
            owLTSave($uid, $row, $t);
            Api::json(['ok' => true, 'msg' => '已设置为 Lv.' . $lv . '（累计 ' . $days . ' 天）']);

        case 'gate':        // 限制总开关
            $v = (string)($ctx['post']['v'] ?? '1') === '1' ? '1' : '0';
            owLTSetCfg('gating', $v);
            Api::json(['ok' => true, 'msg' => $v === '1' ? '已开启等级限制' : '已关闭等级限制']);

        default:
            Api::json(['ok' => false, 'msg' => '未知操作']);
    }
});
Plugin::sensitive('plugin_level_trust_op');   // 涉及改等级/补签，走一次性票据

Plugin::adminPage('level-trust', '等级信任', function () {
    $f = function (string $k, string $label, string $hint = '') {
        return '<div class="ow-form-item"><label>' . Sec::e($label) . '</label>'
            . '<input class="ow-input" id="owCfg_' . $k . '" value="' . Sec::e(owLTCfg($k)) . '">'
            . ($hint !== '' ? '<p style="font-size:12px;color:var(--ow-text-sub);margin-top:4px">' . Sec::e($hint) . '</p>' : '')
            . '</div>';
    };
    $head = '<h2>等级信任</h2>'
        . '<p class="ow-admin-desc">等级 L = 1 + ⌊D × lv_step⌋（v2.0 起）：D 为累计完成每日保底任务的天数，'
        . '默认每个活跃天换算 1.3 级进度，平均一天升 1.3 级。各阶段保底任务统一为「登录 + 群发言 N 条」，无替代、无挑战。'
        . '以下参数全部可调，改完点最下方「保存全部参数」。</p>';

    $head .= '<div class="ow-card" id="owLTStats"></div>'
        . '<div class="ow-card"><h3 style="margin:0 0 4px">活跃趋势</h3><div id="owLTTrend"></div></div>';

    $head .= '<div class="ow-card"><h3 style="margin:0 0 10px">参数设置</h3>';

    $head .= '<h4 class="ow-lt-cap">总开关</h4><div class="ow-form-row">'
        . '<div class="ow-form-item" style="flex:1"><label>等级限制</label>'
        . '<select class="ow-input" id="owCfg_gating">'
        . '<option value="1"' . (owLTCfg('gating') === '1' ? ' selected' : '') . '>开启（按下面各项限制功能）</option>'
        . '<option value="0"' . (owLTCfg('gating') === '0' ? ' selected' : '') . '>关闭（等级只展示，不做任何限制）</option>'
        . '</select></div></div>';

    $head .= '<h4 class="ow-lt-cap">能力解锁等级</h4><div class="ow-form-row">'
        . $f('lv_file', '可上传文件（Lv）', '低于此级禁止上传图片/文件')
        . $f('lv_avatar', '自定义头像（Lv）')
        . $f('lv_friend', '添加好友（Lv）')
        . $f('lv_file_half', '单文件 1/2 上限（Lv）')
        . $f('lv_file_full', '单文件满额上限（Lv）')
        . $f('lv_sticker', '上传贴纸（Lv）')
        . $f('lv_mod_cand', '管理候选资格（Lv）', '仅作标记，不实际授予权限')
        . $f('lv_honor', '荣誉标识（Lv）', '达到后显示荣誉徽章')
        . '</div>';

    $head .= '<h4 class="ow-lt-cap">建群名额与积分</h4><div class="ow-form-row">'
        . $f('room_min_level', '免费建群最低等级（Lv）', '低于此级无免费名额，但可用积分创建')
        . $f('room_point_cost', '超额创建单价（积分）', '每个群扣一次；填 0 = 不允许用积分绕过')
        . '<div class="ow-form-item" style="flex:1;min-width:220px"><label>免费名额档位</label>'
        . '<textarea class="ow-input" id="owCfg_room_tiers" rows="3" style="font-family:Menlo,Consolas,monospace">'
        . Sec::e(owLTCfg('room_tiers')) . '</textarea>'
        . '<p style="font-size:12px;color:var(--ow-text-sub);margin-top:4px">每行一条「等级:名额」，取最高命中档。默认 10:3 / 20:5 / 50:8</p>'
        . '</div>'
        . '<div class="ow-form-item" style="flex:1;min-width:220px"><label>贴纸收藏上限档位</label>'
        . '<textarea class="ow-input" id="owCfg_sticker_tiers" rows="3" style="font-family:Menlo,Consolas,monospace">'
        . Sec::e(owLTCfg('sticker_tiers')) . '</textarea>'
        . '<p style="font-size:12px;color:var(--ow-text-sub);margin-top:4px">每行一条「等级:张数」，取最高命中档；未达首档 = 未解锁。'
        . '默认 3:3 / 6:10 / 10:30 / 20:100 / 30:200 / 40:400 / 50:700 / 60:999</p>'
        . '</div></div>';

    $head .= '<h4 class="ow-lt-cap">阶段阈值与任务量</h4><div class="ow-form-row">'
        . $f('stage_2', '日常阶段起始（Lv）')
        . $f('stage_3', '活跃阶段起始（Lv）')
        . $f('stage_4', '核心阶段起始（Lv）')
        . $f('stage_5', '荣誉阶段起始（Lv）')
        . $f('stage_gm', '各阶段群发言条数', '逗号分隔：新手,日常,活跃,核心,荣誉')
        . '</div>';

    $head .= '<h4 class="ow-lt-cap">等级速度</h4><div class="ow-form-row">'
        . $f('lv_step', '每日进度倍率', '每个完成日的活跃天数换算多少级进度；1 = 每天升 1 级，1.3 = 平均一天 1.3 级')
        . '</div>';

    $head .= '<h4 class="ow-lt-cap">有效发言判定</h4><div class="ow-form-row">'
        . $f('msg_min_len', '内容最少字数')
        . $f('msg_cooldown', '计入冷却（秒）')
        . $f('msg_per_minute', '每分钟最多计入（条）')
        . $f('msg_dedupe', '与最近 N 条去重')
        . '</div>';

    $head .= '<div class="ow-modal-actions"><button class="ow-btn ow-btn-primary" onclick="OwLT.saveCfg()">保存全部参数</button></div></div>';

    $head .= '<div class="ow-card"><h3 style="margin:0 0 10px">用户查询</h3><div class="ow-form-row">'
        . '<div class="ow-form-item" style="min-width:120px"><label>用户 ID</label>'
        . '<input class="ow-input" id="owLTUid" type="number" min="1" placeholder="如 1"'
        . ' onkeydown="if(event.key===\'Enter\')OwLT.query()"></div>'
        . '<button class="ow-btn ow-btn-primary" onclick="OwLT.query()">查询</button>'
        . '</div><div id="owLTUser"></div></div>';

    return $head;
});

/* ============================ 资源 ============================ */

Plugin::asset('css', 'level-trust/style.css');
Plugin::asset('js', 'level-trust/chat.js');
Plugin::asset('js', 'level-trust/admin.js');
