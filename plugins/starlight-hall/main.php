<?php
/**
 * 星光殿堂（starlight-hall v1.0.0）—— 每日签到 × 等级信任 合并版
 *
 * 本插件由 plugins/daily-checkin（每日签到）与 plugins/level-trust（等级信任）
 * 合并而来，取代两者：一个入口、一套后台、一份数据。合并的动机是这两件事
 * 本来就是同一条成长线 —— 签到攒积分与连续天数，任务攒等级天数，等级再反过来
 * 解锁能力；分成两个插件时前台要靠「pull 模式」打三个接口互相拼数据，
 * 后台也要在两个页面之间来回跳。
 *
 * ── 两条子系统（各自保留原前缀，避免机械改名改出隐性回归）──────
 *   owSHL*  = 原 level-trust 的等级体系（level subsystem）
 *   owSHC*  = 原 daily-checkin 的签到体系（checkin subsystem）
 *   前缀统一以 owSH（Starlight Hall）开头，符合 PLUGIN.md「插件自有函数以插件 ID
 *   相关名开头」；两条子系统各自的后缀保持不变，便于与改造前的代码逐行对账。
 *
 * ── 数据表沿用旧表名，**不做迁移**────────────────────────────
 *   plugin_level_trust / plugin_level_config / plugin_level_daily     （等级）
 *   plugin_checkin_user / plugin_checkin_log / plugin_checkin_cfg     （签到）
 *   PLUGIN.md 对表名只是「建议带 plugin_<id>_ 前缀」。这里刻意不迁移：
 *   存量站点的签到记录与等级进度是用户资产，为了一次改名去动六张表（含索引与
 *   跨库差异）风险远大于收益。查表时按子系统名认，见上面的分组注释。
 *
 * ── 对外契约（合并后必须保持不变，否则会打断别的插件）──────────
 *   ① `user.level.get` / `user.level.set` 两个钩子 —— plugins/user-manager 与
 *      plugins/chat-background 在 fire，取值语义（哨兵 -1 / null）原样保留。
 *   ② `.ow-lt-badge` / `.ow-lt-honor` / `.ow-lt-badge-sm` / `.ow-lv-hero` 这批
 *      CSS 类名 —— plugins/level-cultivation 会全站扫描 `.ow-lt-badge` 并把文字
 *      换成修仙图标，类名一改那个插件就直接失效。样式文件里这批类名保留原样。
 *   ③ 路由一律改为 `plugin_starlight_hall_*`（PLUGIN.md 硬性要求）。原有
 *      `plugin_level_trust_*` / `plugin_daily_checkin_*` 的调用方只有本插件自己
 *      （签到面板与后台页），已一并改过来；其它插件从未调用过这两个前缀。
 *
 * ── 等级公式（沿袭 level-trust v3.1）──────────────────────────
 *   L = 1 + ⌊ 在线天 + 任务天 + 设定天 − 消退天 ⌋，1 天 = 1 级。
 *   详见下方 owSHLDefaults() / owSHLDecayRun() 处的注释。
 *
 * 约束：兼容 SQLite / MySQL / PostgreSQL；SQL 全参数化；main.php 顶层只做
 *      Plugin::* 注册，不做输出与其它副作用。
 */
if (!defined('OWLSGO_VERSION')) exit;   // 禁止直接 HTTP 访问本文件


/* ============================ 建表（等级子系统） ============================ */

/** 懒建表：只在真正用到时执行（插件 main.php 顶层不做副作用） */
function owSHLBoot(): void
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
        online_sec   INTEGER NOT NULL DEFAULT 0,     -- v3.0：累计在线秒（只增，等级来源之一）
        task_days    REAL    NOT NULL DEFAULT 0,     -- v3.0：每日任务奖励累计「天」（小数，等级来源之二）
        bonus_days   REAL    NOT NULL DEFAULT 0,     -- v3.0：管理员直设等级时补的差额「天」（等级来源之三）
        hb_at        INTEGER NOT NULL DEFAULT 0,     -- v3.0：上次累计在线的时间戳（算两次请求的差值用）
        decay_days   REAL    NOT NULL DEFAULT 0,     -- v3.1：长期不登录已消退的「天」（公式里减掉，只增）
        decay_base   INTEGER NOT NULL DEFAULT 0,     -- v3.1：已计入消退的不登录天数基线（防重复扣、支持清零重算）
        updated_at   {$ts} DEFAULT 0)");
    // ⚠️ k 必须是 VARCHAR 不能是 TEXT：MySQL 下 TEXT/BLOB 列做键要指定长度，否则建表 1170 报错
    //    （"BLOB/TEXT column 'k' used in key specification without a key length"），
    //    建表失败会把该插件的每一次页面加载都变成 500；SQLite 不挑，本机看不出问题。
    DB::run("CREATE TABLE IF NOT EXISTS plugin_level_config (
        k VARCHAR(64) PRIMARY KEY, " . DB::textCol('v') . ")");
    // v1.2.44：按自然日归档的活跃数据（后台「活跃趋势」用）。
    //   不逐条记流水 —— 那样表会无上限膨胀，而趋势图只需要每天的汇总数。
    DB::run("CREATE TABLE IF NOT EXISTS plugin_level_daily (
        day      VARCHAR(10) PRIMARY KEY,     -- Y-m-d
                                              -- ⚠️ 同上：TEXT 当主键在 MySQL 下直接 1170，这里改成 VARCHAR(10)
        actives  INTEGER NOT NULL DEFAULT 0,  -- 当日活跃过的用户数（去重）
        logins   INTEGER NOT NULL DEFAULT 0,  -- 当日新增登录人天
        upgrades INTEGER NOT NULL DEFAULT 0,  -- 当日升级次数
        msgs     INTEGER NOT NULL DEFAULT 0,  -- 计入任务的有效发言条数
        files    INTEGER NOT NULL DEFAULT 0,  -- 文件操作次数
        rooms    INTEGER NOT NULL DEFAULT 0,  -- 建群次数
        updated_at {$ts} DEFAULT 0)");

    // ---------- 表结构迁移（幂等，只跑一次）----------
    // ⚠️ 用 plugin_level_config 里的 schema 标记短路，而不是每请求 6 次 PRAGMA 探测：
    //    user.active 每请求都触发（含 20 秒长轮询），这段代码跑在热路径上。
    // ⚠️ 'schema' 刻意**不写进 owSHLDefaults()** —— 若写进默认值，升级上来的老站点
    //    会从默认值读到最新版号而**跳过迁移**（老站点的配置表里本来就没有这一行）。
    // ⚠️ **加列必须同步把这里的版本号 +1**：v3.0 只写到 '3'，站点（含运行副本）跑过一次
    //    之后永远进不来，新列就永远建不出来 —— v3.1 加消退两列时升到 '4'。
    if (owSHLCfg('schema') !== '4') {
        $fromV2 = owSHLCfg('schema') === '';     // 空 = 从没迁过（v2 表结构 / 全新安装）
        DB::ensureColumn('plugin_level_trust', 'online_sec', 'int', '0');
        DB::ensureColumn('plugin_level_trust', 'task_days', 'real', '0');
        DB::ensureColumn('plugin_level_trust', 'bonus_days', 'real', '0');
        DB::ensureColumn('plugin_level_trust', 'hb_at', 'int', '0');
        DB::ensureColumn('plugin_level_trust', 'decay_days', 'real', '0');
        DB::ensureColumn('plugin_level_trust', 'decay_base', 'int', '0');
        // ⚠️ 折算只能跑一次：已迁到 '3' 的站点再跑一遍会把 task_days 覆盖成
        //    「旧 days × lv_step」，用户新攒的任务天全部作废。
        if ($fromV2) owSHLSeedV3();
        owSHLSetCfg('schema', '4');
    }
}

/**
 * v3.0 存量折算：把 v2.0 的整数 days（累计完成天数）折进 task_days，
 * 再用 bonus_days 把差额补齐到现有等级。
 * 目的有两个：① 让存量行也不再「等级和天数自相矛盾」；② 谁都不掉级。
 *
 * 折算口径：旧体系里「1 个完成日」= lv_step 级进度，所以乘 lv_step 换算成新单位的天。
 * 只在迁移那一刻跑一次（由 schema 标记保证），之后不参与任何计算。
 */
function owSHLSeedV3(): void
{
    $step = (float)owSHLCfg('lv_step');
    if ($step < 1) $step = 1.3;                 // v2.0 的默认倍率；配置被清空时兜底
    foreach (DB::all('SELECT * FROM plugin_level_trust') as $r) {
        $uid = (int)($r['user_id'] ?? 0);
        if ($uid <= 0) continue;
        $row = $r;
        $row['task_days']  = round((int)($r['days'] ?? 0) * $step, 4);
        $row['bonus_days'] = 0.0;
        owSHLAlignLevel($row, (int)($r['level'] ?? 1));   // 差额进 bonus_days，只增不减
        DB::run('UPDATE plugin_level_trust SET task_days=?, bonus_days=? WHERE user_id=?',
            [(float)$row['task_days'], (float)$row['bonus_days'], $uid]);
    }
}

/** 确保当天的汇总行存在（各驱动没有统一的 INSERT OR IGNORE，用「查不到就插」最稳） */
function owSHLEnsureDay(string $day): void
{
    owSHLBoot();
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
 *    那种去重计数走 owSHLMarkActive()。
 */
function owSHLBump(string $field, int $n = 1): void
{
    static $known = ['logins', 'upgrades', 'msgs', 'files', 'rooms'];
    if (!in_array($field, $known, true)) return;   // 字段名走白名单，杜绝拼进 SQL 的列被污染
    $day = date('Y-m-d');
    owSHLEnsureDay($day);
    DB::run('UPDATE plugin_level_daily SET ' . $field . '=' . $field . '+?, updated_at=? WHERE day=?',
        [$n, time(), $day]);
}

/** 记录「当日活跃人数」（同一用户当天只计一次） */
function owSHLMarkActive(int $uid): void
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
    owSHLEnsureDay($day);
    DB::run('UPDATE plugin_level_daily SET actives=actives+1, updated_at=? WHERE day=?', [time(), $day]);
}

/**
 * 清理过期的「当日活跃」去重键（只保留最近 N 天）。
 * ⚠️ 不清理的话 plugin_level_config 会按「天数 × 人数」无限膨胀，
 *    而这些键只在当天有用 —— 留着就是纯垃圾，还会拖慢配置读取。
 */
function owSHLPurgeActiveKeys(int $keepDays = 40): void
{
    owSHLBoot();
    $cut = 'act_' . date('Y-m-d', time() - $keepDays * 86400);
    // k 形如 act_2026-10-06_20；下划线在 LIKE 里是通配符，必须 ESCAPE 后才能按字面匹配
    DB::run("DELETE FROM plugin_level_config WHERE k LIKE 'act@_%' ESCAPE '@' AND k < ?", [$cut]);
}

/**
 * 全部可调参数与默认值（v3.0：在线折算小时数 / 逐任务奖励天数 / 解锁等级 / 建群名额 /
 * 有效发言 / 阶段阈值全部可在后台「等级信任 → 参数设置」里改）。
 *
 * ⚠️ 每加一个新键，必须同时：
 *   ① 在这里给默认值；② 在后台表单里给输入框；③ 在 cfg_save 的白名单里放行
 *      （白名单就是这个数组的键集合，所以 ① 做了 ③ 就自动成立）。
 * 漏了输入框的表现是「值存在但后台看不到、改不了」，很难查。
 */
function owSHLDefaults(): array
{
    return [
        // ---- 总开关 ----
        'gating'        => '1',     // 是否按等级限制功能（0=只看等级不做限制）
        // ---- 等级速度（v3.0：L = 1 + ⌊ 在线天 + 任务天 + 设定天 − 消退天 ⌋）----
        'online_hours'  => '24',    // 在线满 N 小时算 1 天（= 1 级）；<1 会被兜底成 24
        'rw_login'      => '0.2',   // 「登录」任务完成一次奖励多少天
        'rw_gm'         => '0.5',   // 「群发言」任务完成一次奖励多少天
        // v1.1.0：每日签到给等级进度发多少「天」（合并签到与等级后的新奖励项）。
        // 签到只能每天一次（(user_id, day) 唯一），所以天然不需要额外的去重。
        // 填 0 = 签到不发等级天数，只发积分（合并前的行为）。
        'rw_checkin'    => '0.2',   // 每日签到奖励（天）
        // ---- 长期不登录消退（v3.1：站方要求「60 级以后长期不登录自动消退，最多退到 46」）----
        'decay_idle_days' => '30',  // 每满 N 天不登录退 1 级；0 = 关闭消退
        'decay_from'      => '60',  // 从该等级起才消退（低于此级不动）
        'decay_floor'     => '46',  // 最低退到该等级（默认 46 = 荣誉阶段起点）
        // ⚠️ v2.0 遗留：每日任务换算的等级进度倍率（L = 1 + ⌊D × lv_step⌋）。
        //    v3.0 起公式不再用它，但**不能删键**：① 迁移时还要读一次（把旧 days
        //    折算成新单位的天数）；② cfg_save 的白名单就是这个数组的键集合，
        //    删掉它会让老站点配置表里的值再也写不回去。
        'lv_step'       => '1.3',
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
 * ⚠️ 缓存放在 $GLOBALS 而不是函数内 static：函数级 static 无法被 owSHLSetCfg 清掉，
 *    同请求内「后台刚关掉限制 → 上传仍被拦」这种不一致查起来非常费劲。
 */
function owSHLCfg(string $k): string
{
    owSHLBoot();
    if (!isset($GLOBALS['_shlCfg']) || !is_array($GLOBALS['_shlCfg'])) $GLOBALS['_shlCfg'] = [];
    if (array_key_exists($k, $GLOBALS['_shlCfg'])) return $GLOBALS['_shlCfg'][$k];
    $v = DB::val('SELECT v FROM plugin_level_config WHERE k=?', [$k]);
    $def = owSHLDefaults();
    $GLOBALS['_shlCfg'][$k] = ($v === null || $v === false || $v === '') ? ($def[$k] ?? '') : (string)$v;
    return $GLOBALS['_shlCfg'][$k];
}

/** 读整数配置 */
function owSHLInt(string $k): int
{
    return (int)owSHLCfg($k);
}

/** 读浮点配置（小数写进表里是字符串，必须显式转） */
function owSHLFloat(string $k): float
{
    return (float)owSHLCfg($k);
}

function owSHLSetCfg(string $k, string $v): void
{
    owSHLBoot();
    DB::run('DELETE FROM plugin_level_config WHERE k=?', [$k]);
    DB::run('INSERT INTO plugin_level_config (k, v) VALUES (?,?)', [$k, $v]);
    unset($GLOBALS['_shlCfg'][$k]);      // 立刻失效，同请求内后续读取拿到新值
}

/**
 * 建群名额档位：解析 room_tiers（每行「等级:名额」）。
 * 返回 [[lv=>int, n=>int], ...] 按等级升序；解析不出任何档位时回落到默认表。
 */
function owSHLRoomTiers(): array
{
    static $cached = null;
    if ($cached !== null) return $cached;
    $out = [];
    foreach (preg_split('/[\r\n,;]+/', owSHLCfg('room_tiers')) ?: [] as $line) {
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
function owSHLRoomQuota(int $level): int
{
    if ($level < owSHLInt('room_min_level')) return 0;
    $n = 0;
    foreach (owSHLRoomTiers() as $t) {
        if ($level >= $t['lv']) $n = $t['n'];
    }
    return $n;
}

/**
 * 某等级的贴纸收藏上限（v1.3.30，核心 addSticker 经 sticker.quota 钩子取这里）。
 * 档位格式同 room_tiers（每行「等级:张数」，取最高命中档）；
 * 未达首档 = 0，配合 lv_sticker 的上传闸门，语义是「还没解锁」。
 */
function owSHLStickerQuota(int $level): int
{
    static $cached = null;
    if ($cached === null) {
        $cached = [];
        foreach (preg_split('/[\r\n,;]+/', owSHLCfg('sticker_tiers')) ?: [] as $line) {
            $line = trim((string)$line);
            if ($line === '') continue;
            if (!preg_match('/^(\d{1,4})\s*[:：]\s*(\d{1,4})$/u', $line, $m)) continue;
            $cached[] = ['lv' => (int)$m[1], 'n' => (int)$m[2]];
        }
        usort($cached, fn($a, $b) => $a['lv'] <=> $b['lv']);
    }
    if (!$cached) return $level >= owSHLInt('lv_sticker') ? 100 : 0;   // 档位表被清空 → 回落旧行为
    $n = 0;
    foreach ($cached as $t) {
        if ($level >= $t['lv']) $n = $t['n'];
    }
    return $n;
}

/* ============================ 等级计算 ============================ */

/**
 * 阶段阈值（可配）：返回 [日常, 活跃, 核心, 荣誉] 四个起始等级。
 * ⚠️ 必须保证单调递增，否则 owSHLStageNo 会出现「高等级落在低阶段」的错乱。
 */
function owSHLStageBounds(): array
{
    $b = [
        max(2, owSHLInt('stage_2')),
        max(3, owSHLInt('stage_3')),
        max(4, owSHLInt('stage_4')),
        max(5, owSHLInt('stage_5')),
    ];
    for ($i = 1; $i < 4; $i++) if ($b[$i] <= $b[$i - 1]) $b[$i] = $b[$i - 1] + 1;
    return $b;
}

/** 五个阶段的群发言条数（可配，逗号分隔；缺项用默认值补齐） */
function owSHLStageGm(): array
{
    $def = [1, 3, 5, 5, 10];
    $parts = preg_split('/[\s,，]+/u', owSHLCfg('stage_gm')) ?: [];
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
function owSHLStage(int $level): array
{
    $b = owSHLStageBounds();          // [日常, 活跃, 核心, 荣誉]
    $gm = owSHLStageGm();             // 五阶段各自的群发言条数
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
function owSHLStageNo(int $level): int
{
    $b = owSHLStageBounds();
    if ($level < $b[0]) return 1;
    if ($level < $b[1]) return 2;
    if ($level < $b[2]) return 3;
    if ($level < $b[3]) return 4;
    return 5;
}

/** 阶段名（供后台统计与前台页面复用，避免各处再写一份中文） */
function owSHLStageName(int $no): string
{
    $m = [1 => '新手阶段', 2 => '日常阶段', 3 => '活跃阶段', 4 => '核心阶段', 5 => '荣誉阶段'];
    return $m[$no] ?? '新手阶段';
}

/** 某项进度。fact（群文件操作）是上传+下载的合计，不单独存 */
function owSHLProg(array $t, string $k): int
{
    if ($k === 'fact') return (int)($t['fup'] ?? 0) + (int)($t['fdl'] ?? 0);
    return (int)($t[$k] ?? 0);
}

/** 单个任务是否完成（v2.0 起无替代组，达标即完成） */
function owSHLTaskOk(array $task, array $t): bool
{
    return owSHLProg($t, $task['k']) >= (int)$task['n'];
}

function owSHLBaseOk(array $stage, array $t): bool
{
    foreach ($stage['base'] as $task) if (!owSHLTaskOk($task, $t)) return false;
    return true;
}

/** 全服等级中位数（后台概览的 KPI 用；v2.0 起不再进等级公式） */
function owSHLMedian(): float
{
    static $m = null;
    if ($m !== null) return $m;
    owSHLBoot();
    $n = (int)DB::val('SELECT COUNT(*) FROM plugin_level_trust');
    if ($n <= 0) return ($m = 1.0);
    $off = (int)floor($n / 2);                       // 字面量整数，无注入风险
    $row = DB::one('SELECT level FROM plugin_level_trust ORDER BY level LIMIT 1 OFFSET ' . $off);
    return ($m = $row ? (float)$row['level'] : 1.0);
}

/**
 * 「在线多少小时 = 1 天」（后台可配，默认 24）。
 * ⚠️ 兜底 ≥1：被调成 0 会让 owSHLTotalDays 除零、全体瞬间满级。
 */
function owSHLOnlineHours(): float
{
    $h = owSHLFloat('online_hours');
    return $h >= 1 ? $h : 24.0;
}

/**
 * 三项合计的「天」数 —— 等级公式里 floor 之前的那个值。
 * 在线秒 / 任务奖励 / 管理员设定 相加，再**减去**长期不登录的消退扣减，单位统一是「天」，1 天 = 1 级。
 */
function owSHLTotalDays(array $row): float
{
    return (float)($row['online_sec'] ?? 0) / (owSHLOnlineHours() * 3600)
        + (float)($row['task_days'] ?? 0)
        + (float)($row['bonus_days'] ?? 0)
        - (float)($row['decay_days'] ?? 0);
}

/**
 * 消退相关参数（全部后台可调）。
 * 返回 [每多少天不登录退 1 级, 起始等级, 最低等级]；间隔 ≤0 表示关闭消退。
 * ⚠️ 最低等级被夹到「起始等级 - 1 以下」，否则配置写反（floor ≥ from）会让消退
 *    一开始就把人压到 floor 以下，看起来像「一登录就掉级」。
 */
function owSHLDecayCfg(): array
{
    $idle  = owSHLFloat('decay_idle_days');
    $from  = max(2, owSHLInt('decay_from'));
    $floor = max(1, owSHLInt('decay_floor'));
    if ($floor >= $from) $floor = $from - 1;
    return [$idle, $from, $floor];
}

/** 等级公式（v3.0）：L = 1 + ⌊ 总天数 ⌋ */
function owSHLLevelFromTotal(float $total): int
{
    return max(1, 1 + (int)floor($total));
}

/**
 * 按公式重算等级并写回 $row。
 * ⚠️ 取 max(旧等级, 公式值)：未完成任务不降级；后台直设过的等级不会被公式压回去。
 *    所有改等级的地方（任务发奖、补签、后台操作）都必须走这里，别各处自己算一遍。
 */
function owSHLRecalc(array &$row): void
{
    $row['level'] = max((int)($row['level'] ?? 1), owSHLLevelFromTotal(owSHLTotalDays($row)));
}

/**
 * 把等级设为 $level，并把「公式还没算到」的差额记进 bonus_days（管理员设定）。
 *
 * 存在的意义：等级可以被后台直接写（用户管理的等级输入框只写 level、不动天数），
 * 而公式是按「天」算的 —— 两者一旦脱钩，等级页就会自相矛盾
 * （同时显示「Lv.30」和「累计 4 天」，而页面自己写着 L = 1 + ⌊天数⌋）。
 * 把差额显式记成「管理员设定」，页面就能如实拆开展示。
 *
 * ⚠️ 只增不减：公式值已经高于目标等级时**不动** bonus_days。
 *    这等于承认「后台降级是无效的」—— 下一次重算 max() 会把等级顶回去，
 *    这是 v2.0 起定下的口径（只升不降），本函数不改变它。
 */
function owSHLAlignLevel(array &$row, int $level): void
{
    $level = max(1, $level);
    $row['level'] = $level;
    $total = owSHLTotalDays($row);
    $want  = $level - 1;                  // total ≥ want 才能保证 floor(total)+1 ≥ level
    if ($total < $want) {
        // +1e-6：浮点误差不能把 total 落在 want 下沿之外（差 1e-9 就掉一整级）
        $row['bonus_days'] = (float)($row['bonus_days'] ?? 0) + ($want - $total) + 1e-6;
    }
}

/**
 * 等级进度快照 —— 等级页 / mine 接口 / 后台用户查询共用的一份口径。
 * 三处各拼一遍数字必然漂移（v2.0 就是这么出现「进度条 0% 却写着还需 20 天」的）。
 *
 * pct 为什么这么算：公式是 L = 1 + ⌊total⌋，所以 total 的小数部分**天生**就是
 * 「当前这一级已完成的比例」，不需要任何基准点。v2.0 用的是
 * `days×step − (lv−1)`，那个式子只有在「等级必然等于公式值」时才成立 ——
 * 后台一直设等级就恒为负数被夹到 0。
 *
 * @param array $row 等级行（需含 level / online_sec / task_days / bonus_days）
 */
function owSHLSnapshot(array $row): array
{
    $total = owSHLTotalDays($row);
    $lv    = max(1, (int)($row['level'] ?? 1));
    $oh    = owSHLOnlineHours();
    $floor = floor($total);
    return [
        'level'        => $lv,
        'online_sec'   => (int)($row['online_sec'] ?? 0),
        'online_days'  => (float)($row['online_sec'] ?? 0) / ($oh * 3600),
        'task_days'    => (float)($row['task_days'] ?? 0),
        'bonus_days'   => (float)($row['bonus_days'] ?? 0),
        'decay_days'   => (float)($row['decay_days'] ?? 0),
        'total_days'   => round($total, 4),
        'online_hours' => $oh,
        'pct'          => (int)max(0, min(100, round(($total - $floor) * 100))),
        // 下一级的起点是 floor(total)+1（不是 lv+1 —— 倍率/小数会让等级跳档，
        // v2.0 的「距 Lv.N+1 还需 X 天」就写错过：写着 Lv.5 实际会落到 Lv.6）
        'gap_days'     => round(max(0.0, $floor + 1 - $total), 4),
        'next_level'   => (int)$floor + 2,
    ];
}

/* ============================ 行读写 ============================ */

function owSHLDecode(string $json): array
{
    $a = json_decode($json ?: '{}', true);
    return is_array($a) ? $a : [];
}

/** 取等级行；不存在则按「注册默认 Lv.1」创建 */
function owSHLRow(int $uid): array
{
    owSHLBoot();
    $row = DB::one('SELECT * FROM plugin_level_trust WHERE user_id=?', [$uid]);
    if ($row) return $row;
    DB::run('INSERT INTO plugin_level_trust (user_id, level, days, weight, updated_at) VALUES (?,?,?,?,?)',
        [$uid, 1, 0, 0.0, time()]);
    return DB::one('SELECT * FROM plugin_level_trust WHERE user_id=?', [$uid]) ?: [];
}

/**
 * 写回等级行。
 * ⚠️ days / weight / file_w_days 是 v1.x~v2.0 的遗留列，v3.0 起**不从 $row 回写**
 *    （只在建行时留默认值），免得把历史值搬来搬去反而看不清楚它们已经不用了。
 */
function owSHLSave(int $uid, array $row, array $t): void
{
    DB::run('UPDATE plugin_level_trust SET level=?, login_streak=?, login_days=?,'
        . ' day=?, today=?, settled=?, frozen_day=?, protect_used=?, protect_mon=?,'
        . ' patch_used=?, patch_week=?, online_sec=?, task_days=?, bonus_days=?, hb_at=?,'
        . ' decay_days=?, decay_base=?, updated_at=? WHERE user_id=?', [
        (int)$row['level'],
        (int)$row['login_streak'], (int)$row['login_days'],
        (string)$row['day'], json_encode($t, JSON_UNESCAPED_UNICODE), (int)$row['settled'],
        (int)$row['frozen_day'], (int)$row['protect_used'], (string)$row['protect_mon'],
        (int)$row['patch_used'], (string)$row['patch_week'],
        (int)($row['online_sec'] ?? 0), (float)($row['task_days'] ?? 0),
        (float)($row['bonus_days'] ?? 0), (int)($row['hb_at'] ?? 0),
        (float)($row['decay_days'] ?? 0), (int)($row['decay_base'] ?? 0),
        time(), $uid,
    ]);
}

/**
 * 累计在线时长（v3.0）。
 *
 * 口径（站方定案）：**页面开着就算在线**。服务端不区分「有没有真在看」，
 * 只认「这个请求距上一个请求过了多久」。于是：
 *   · 聊天页长轮询 ≤20 秒一次 → 每次累加 ≈20 秒，等于页面开着的时间；
 *   · 浏览器标签被挂到后台时定时器被节流到约 1 分钟 → 累加照旧，只是粒度变粗；
 *   · 关掉页面几小时后再回来，那几小时**不会**补记（单次封顶兜住）。
 *
 * ⚠️ 单次封顶 120 秒：不加这一手，任何人刷新一次页面就能把「上次访问到现在」的
 *    全部时间记成在线（挂三天再刷新 = +3 天 = +3 级），等级体系直接报废。
 *    ⚠️ 但要清楚它**挡不住**「脚本掐着点定时上报」—— 每 2 分钟请求一次就能拿满
 *    120 秒（等于 100% 计入）。那与「页面真开着挂机」在服务端完全等价，无法区分，
 *    这是本方案已知且站方接受的代价（口径定的是「页面开着就算在线」）。
 * ⚠️ 差值 <5 秒不落库也不记账：同屏快速连点多个接口时把这几秒攒到下一次一起算，
 *    避免「一次点击写一次库」。代价是那几秒在请求结束时没有立即持久化（下次请求补上）。
 *
 * @return bool 是否需要写库（false = 差值太小，本次不记）
 */
function owSHLAccumOnline(int $uid, array &$row): bool
{
    $now  = time();
    $last = (int)($row['hb_at'] ?? 0);
    if ($last <= 0 || $last > $now) {          // 首次 / 时间倒流（改过服务器时钟）
        $row['hb_at'] = $now;
        return true;
    }
    $delta = $now - $last;
    if ($delta < 5) return false;              // 攒到下一次一起算
    if ($delta > 120) $delta = 120;            // 单次封顶（见上方注释）
    $row['online_sec'] = (int)($row['online_sec'] ?? 0) + $delta;
    $row['hb_at'] = $now;
    return true;
}

/**
 * 某个任务的奖励「天」数 —— 后台逐任务可配，键名 `rw_<任务key>`（如 rw_login / rw_gm）。
 * 缺项 / 非法值按 0 处理（配错不至于让任务发钱发到满级）。
 */
function owSHLReward(string $taskKey): float
{
    $map = owSHLDefaults();
    $key = 'rw_' . $taskKey;
    if (!isset($map[$key])) return 0.0;        // 没有这个任务就没有奖励（新增任务要先加配置键）
    $v = (float)owSHLCfg($key);
    return $v > 0 ? $v : 0.0;
}

/** 当前任务表里所有任务的奖励天数（键 => 天），供后台与等级页展示 */
function owSHLRewardTable(): array
{
    $out = [];
    foreach (owSHLStage(1)['base'] as $task) $out[$task['k']] = owSHLReward($task['k']);
    return $out;
}

/**
 * 每日签到能给等级进度加多少「天」（v1.1.0）。
 * 键名走 rw_ 前缀，与逐任务奖励同一套口径（见 owSHLReward）；缺项 / 非法值按 0 处理。
 */
function owSHLCheckinReward(): float
{
    $map = owSHLDefaults();
    if (!array_key_exists('rw_checkin', $map)) return 0.0;
    $v = (float)owSHLCfg('rw_checkin');
    return $v > 0 ? $v : 0.0;
}

/**
 * 奖励的中文清单（「登录 0.2 天 + 群发言 0.5 天 + 每日签到 0.2 天」）—— 等级页与后台共用，避免两处各写一份
 */
function owSHLRewardText(): string
{
    $parts = [];
    foreach (owSHLRewardTable() as $k => $d) {
        if ($d <= 0) continue;
        $parts[] = owSHLTaskLabel((string)$k) . ' ' . owSHLNum($d) . ' 天';
    }
    // v1.1.0：签到不是「每日任务」里的一项，但同样给天数 —— 一起列出来，
    // 免得「登录 + 群发言」的清单让人以为签到白签（等级一点没涨）。
    $ck = owSHLCheckinReward();
    if ($ck > 0) $parts[] = '每日签到 ' . owSHLNum($ck) . ' 天';
    return $parts ? implode(' + ', $parts) : '未配置奖励';
}

/**
 * 发当日任务奖励（v3.0）。
 *
 * 与 v2.0 最大的不同：**逐任务各自发奖**，不再要求「当天保底任务全部完成才 +1 天」。
 * 依据是站方原话「完成每日任务，每一个任务加多少天管理员可以在后台设置」——
 * 每个任务有自己的奖励值，也就必须各自结算。
 *
 * 「每天最多一次」由 today JSON 里的 paid 标记保证：today 跨天会整体重置，
 * 所以 paid 天然是按自然日隔离的，不需要额外的日期字段。
 *
 * @return float 本次实际发出的天数（0 = 没有新完成的任务）
 */
function owSHLPayTasks(array &$row, array &$t): float
{
    $paid = is_array($t['paid'] ?? null) ? $t['paid'] : [];
    $add  = 0.0;
    foreach (owSHLStage((int)$row['level'])['base'] as $task) {
        $k = (string)$task['k'];
        if (!empty($paid[$k])) continue;                  // 今天这个任务已经发过了
        if (!owSHLTaskOk($task, $t)) continue;             // 还没达标
        $paid[$k] = 1;
        $add += owSHLReward($k);
    }
    $t['paid'] = $paid;
    if ($add > 0) {
        $row['task_days'] = (float)($row['task_days'] ?? 0) + $add;
    }
    return $add;
}

/**
 * 跨天迁移 + 记一次登录。
 * ⚠️ 同日内**不做迁移也不重复计登录**（只读一次行、解出今日进度）。
 *    注意这不等于「同日内不写库」—— v3.0 起写库由调用方按需做
 *    （user.active 里累加在线时长那一步，见该钩子注释）。
 */
function owSHLTouch(int $uid): array
{
    $row = owSHLRow($uid);
    if (!$row) return [null, []];
    $today = date('Y-m-d');
    if ((string)$row['day'] === $today) {
        // 今日已处理过：只把进度解出来，不做任何迁移
        return [$row, owSHLDecode((string)$row['today'])];
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
    owSHLSave($uid, $row, $t);
    return [$row, $t];
}

/* ============================ 有效行为判定 ============================ */

/**
 * 有效发言内容判定（设计文档第六节）：
 * 长度 ≥ 2、非纯表情、非纯链接。
 * 冷却（15 秒）与去重在 owSHLAddMsg 里做 —— 它们依赖「今日进度」里的状态。
 */
function owSHLValidText(string $s): bool
{
    $s = trim($s);
    if (mb_strlen($s) < max(1, owSHLInt('msg_min_len'))) return false;
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
function owSHLAddMsg(int $uid, string $content, string $type, bool $isGroup): bool
{
    if ($uid <= 0) return false;
    if (!in_array($type, ['text', 'mention'], true)) return false;   // 图片/文件/系统不算「发言」
    if (!owSHLValidText($content)) return false;

    [$row, $t] = owSHLTouch($uid);
    if (!$row) return false;
    if ((int)$row['frozen_day'] === (int)date('Ymd')) return false;  // 当天被冻结

    $now = time();
    // ① 冷却：距上一条**计入任务**的发言至少 N 秒
    $cd = max(0, owSHLInt('msg_cooldown'));
    if (!empty($t['last_at']) && $now - (int)$t['last_at'] < $cd) return false;
    // ② 本分钟最多计入 N 条
    $perMin = max(1, owSHLInt('msg_per_minute'));
    $minute = (int)floor($now / 60);
    if (empty($t['minute']) || (int)$t['minute'] !== $minute) { $t['minute'] = $minute; $t['minute_n'] = 0; }
    if ((int)($t['minute_n'] ?? 0) >= $perMin) return false;
    // ③ 与最近 N 条内容不重复
    $h = md5(mb_strtolower(trim($content)));
    $recent = is_array($t['recent'] ?? null) ? $t['recent'] : [];
    if (in_array($h, array_slice($recent, 0, max(1, owSHLInt('msg_dedupe'))), true)) return false;

    $t['minute_n'] = (int)$t['minute_n'] + 1;
    $t['last_at'] = $now;
    array_unshift($recent, $h);
    $t['recent'] = array_slice($recent, 0, max(1, owSHLInt('msg_dedupe')));

    if ($isGroup) {
        $t['gm'] = owSHLProg($t, 'gm') + 1;   // 群发言是唯一人人可做的任务行为
    }
    owSHLAfterProgress($uid, $row, $t);
    return true;                                            // 真正计入了任务
}

/**
 * 文件操作（upload / download）——v2.0 起只统计活跃趋势，不再记任务进度
 * （旧公式里上传/下载是加权来源，还带「可用群发言替代」的挑战任务，已一并移除）。
 * 下载自己的文件不计（防刷）的规则保留。
 */
function owSHLAddFile(int $uid, string $kind, bool $own = false): void
{
    if ($uid <= 0) return;
    if ($kind === 'download' && $own) return;
    owSHLMarkActive($uid);
    owSHLBump('files');
}

/* ============================ 结算 ============================ */

/**
 * 今日任务清单（带进度、奖励与是否已发奖）—— mine 接口 / 后台查询 / 等级页共用。
 * 三处各写一份的话，加一个字段就会漏掉其中一处（v2.0 的 tasks 与等级页就分了两份）。
 */
function owSHLTasksToday(array $row, array $t): array
{
    $paid = is_array($t['paid'] ?? null) ? $t['paid'] : [];
    $out  = [];
    foreach (owSHLStage((int)$row['level'])['base'] as $task) {
        $k = (string)$task['k'];
        $out[] = [
            'k' => $k, 'label' => owSHLTaskLabel($k),
            'n' => (int)$task['n'], 'cur' => owSHLProg($t, $k),
            'rw' => owSHLReward($k),                       // 完成一次奖励多少天
            'paid' => !empty($paid[$k]) ? 1 : 0,          // 今天是否已发过奖
            'ok' => owSHLTaskOk($task, $t) ? 1 : 0,
        ];
    }
    return $out;
}

/**
 * 进度变化后：逐任务发奖（每个任务每天一次）→ 按公式重算等级。
 * v3.0 起没有「整天」概念了 —— 完成哪个任务就发哪个任务的「天」，
 * 与站方口径（每一个任务各配一个奖励天数）一致。
 *
 * ⚠️ $row / $t 必须**按引用**传：调用方紧接着还要拿这份数据写库
 *    （user.active 里跨天之后就是累加在线那一步）。按值传会让调用方手里
 *    还是发奖前的旧快照，它的保存会**把刚发的奖励覆盖回 0**
 *    —— v3.0 自检第 11 组抓到的就是这个：新用户首次活跃，0.5 天不见了。
 */
function owSHLAfterProgress(int $uid, array &$row, array &$t): void
{
    $before = (int)($row['level'] ?? 1);
    owSHLPayTasks($row, $t);
    // settled 保留原义：今日保底任务是否已全部完成（只作展示，见等级页「今日已结算」）
    $row['settled'] = owSHLBaseOk(owSHLStage((int)$row['level']), $t) ? 1 : 0;
    owSHLRecalc($row);          // 不降级由 owSHLRecalc 保证
    owSHLSave($uid, $row, $t);
    if ((int)$row['level'] > $before) owSHLBump('upgrades');
}

/* ============================ 功能解锁 ============================ */

/**
 * 当前等级已解锁的能力。
 * ratio = 单文件上限相对「全局上限」的比例（0 = 禁止上传）
 */
function owSHLUnlocks(int $level): array
{
    // v1.2.44：所有门槛改为**后台可配**（lv_* 系列）。
    // quota 是本等级的免费建群名额，pay_cost 是超额后每个群的积分价（0=不允许付费）。
    return [
        'avatar'   => $level >= owSHLInt('lv_avatar'),
        'sticker'  => $level >= owSHLInt('lv_sticker'),
        'sticker_quota' => owSHLStickerQuota($level),   // 贴纸收藏上限（0=未解锁）
        'friend'   => $level >= owSHLInt('lv_friend'),
        'ratio'    => $level >= owSHLInt('lv_file_full') ? 1.0
                    : ($level >= owSHLInt('lv_file_half') ? 0.5
                    : ($level >= owSHLInt('lv_file') ? 0.25 : 0.0)),
        'quota'    => owSHLRoomQuota($level),
        'pay_cost' => max(0, owSHLInt('room_point_cost')),
        'min_room_level' => owSHLInt('room_min_level'),
        'mod_cand' => $level >= owSHLInt('lv_mod_cand'),   // 管理权限候选资格（仅标记）
        'honor'    => $level >= owSHLInt('lv_honor'),      // 荣誉标识（不解锁新功能）
    ];
}

/** 取用户当前等级（无记录 = 注册默认 Lv.1） */
function owSHLLevelOf(int $uid): int
{
    if ($uid <= 0) return 1;
    owSHLBoot();
    $v = DB::val('SELECT level FROM plugin_level_trust WHERE user_id=?', [$uid]);
    return $v === null || $v === false ? 1 : max(1, (int)$v);
}

/* ============================ 钩子 ============================ */

/** 建群成功（活跃趋势的「建群次数」） */
Plugin::on('room.created', function ($roomId, $actor, $charged = 0) {
    if (($actor['kind'] ?? '') !== 'user') return;
    owSHLBump('rooms');
});

/**
 * 每日登录（会话仍在就不走登录钩子，所以挂 user.active）
 *
 * v3.0：这个钩子还兼着「累计在线时长」的活 —— 它是全站唯一每请求都会跑的入口，
 * 在线时长只能在这里按「两次请求的时间差」累加。因此**同日路径不再是零写入**
 * （v2.0 的注释写着「长轮询下不能每次都写库」，v3.0 起必须写：在线时长就是靠
 * 每次请求记一个差值攒出来的）。写入频率 = 该用户的轮询频率（≤20 秒一次），
 * 且差值 <5 秒的请求不落库，所以同屏连点不会放大写量。
 */
Plugin::on('user.active', function (array $actor) {
    if (($actor['kind'] ?? '') !== 'user') return;
    $uid = (int)$actor['id'];
    if ($uid <= 0) return;

    $row = owSHLRow($uid);
    if (!$row) return;

    if ((string)$row['day'] !== date('Y-m-d')) {
        // 跨天：进度清零 + 迁移（owSHLTouch 内部会写库）
        [$row, $t] = owSHLTouch($uid);
        if (!$row) return;
        // v1.2.44 活跃趋势：当日活跃人数（同一人一天只计一次）
        owSHLMarkActive($uid);
        owSHLBump('logins');                   // 跨天才算一次「登录人天」
        // 登录本身就可能直接达成低阶段任务（新手阶段 = 登录 + 群发言 1）
        owSHLAfterProgress($uid, $row, $t);
    } else {
        $t = owSHLDecode((string)$row['today']);
    }

    // v3.0：每次请求都累加在线时长（页面开着就算在线）
    if (owSHLAccumOnline($uid, $row)) {
        // ⚠️ 必须在这里重算等级：在线时长是**这一条路径**攒出来的，而等级只在
        //    跨天结算时才重算的话，「在线满一天就升一级」要等到第二天才生效。
        //    自检第 10 组（在线攒满 86400 秒）就是拿这个当断言。
        $before = (int)$row['level'];
        owSHLRecalc($row);
        owSHLSave($uid, $row, $t);
        if ((int)$row['level'] > $before) owSHLBump('upgrades');
    }
});

/** 发言 */
Plugin::on('message.after_send', function ($id, $actor, $roomId, $content = '', $type = 'text', $toUserId = 0) {
    if (($actor['kind'] ?? '') !== 'user') return;                 // 游客没有等级
    $uid = (int)$actor['id'];
    owSHLMarkActive($uid);
    if (owSHLAddMsg($uid, (string)$content, (string)$type, (int)$roomId > 0)) owSHLBump('msgs');
});

/** 文件上传成功（附件上传插件）—— 仅活跃趋势统计 */
Plugin::on('file.uploaded', function (int $uid, string $kind, int $size = 0) {
    owSHLAddFile($uid, 'upload');
});

/** 文件下载完成（核心 file_download）—— 仅活跃趋势统计 */
Plugin::on('file.downloaded', function (int $uid, int $msgId, int $ownerId = 0) {
    owSHLAddFile($uid, 'download', $ownerId > 0 && $ownerId === $uid);
});

/** 资料卡：把等级随 user_card 一起下发（前端无需再打一次接口） */
Plugin::on('user.card', function (array &$u, array $actor) {
    $lv = owSHLLevelOf((int)($u['id'] ?? 0));
    $u['level'] = $lv;
    $u['level_stage'] = owSHLStage($lv)['name'];
    $u['level_stage_no'] = owSHLStageNo($lv);
    $u['level_honor'] = $lv >= 60 ? 1 : 0;
});

/** 上传闸门：头像 3 级起、贴纸 20 级起 */
Plugin::on('upload.guard', function (bool &$allow, string &$reason, string $kind, array $actor) {
    if (owSHLCfg('gating') !== '1') return;
    if (($actor['role'] ?? '') === 'admin') return;            // 超管不受限
    $lv = owSHLLevelOf((int)$actor['id']);
    $u = owSHLUnlocks($lv);
    if ($kind === 'avatar' && !$u['avatar']) {
        $allow = false; $reason = '自定义头像需 ' . owSHLInt('lv_avatar') . ' 级解锁（当前 Lv.' . $lv . '）';
    } elseif ($kind === 'sticker' && !$u['sticker']) {
        $allow = false; $reason = '上传贴纸需 ' . owSHLInt('lv_sticker') . ' 级解锁（当前 Lv.' . $lv . '）';
    }
});

/** 贴纸收藏上限档位（v1.3.30）：核心 Upload::addSticker 经此钩子取数。
 *  gating 关闭 / 超管 → 不动 $limit，保持核心默认 100。 */
Plugin::on('sticker.quota', function (int &$limit, array $actor) {
    if (owSHLCfg('gating') !== '1') return;
    if (($actor['role'] ?? '') === 'admin') return;
    $limit = owSHLStickerQuota(owSHLLevelOf((int)($actor['id'] ?? 0)));
});

/** 加好友闸门：10 级起 */
Plugin::on('friend.guard', function (bool &$allow, string &$reason, array $actor, int $friendId) {
    if (owSHLCfg('gating') !== '1') return;
    if (($actor['role'] ?? '') === 'admin') return;
    $lv = owSHLLevelOf((int)$actor['id']);
    if (!owSHLUnlocks($lv)['friend']) {
        $allow = false; $reason = '添加好友需 ' . owSHLInt('lv_friend') . ' 级解锁（当前 Lv.' . $lv . '）';
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
    if (owSHLCfg('gating') !== '1') return;                 // 后台关闭了等级限制
    if (($actor['role'] ?? '') === 'admin') return;         // 超管不受限，也不收费
    if (($actor['kind'] ?? '') !== 'user') return;          // 游客走不到这里（核心已拦）

    $uid  = (int)$actor['id'];
    $lv   = owSHLLevelOf($uid);
    $u    = owSHLUnlocks($lv);
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
        $dec['reason']  = $why . '，且站点未开启「消耗' . ow_points_name() . '创建群聊」。';
        return;
    }
    $dec['free']   = false;
    $dec['cost']   = $cost;
    // ⚠️ reason 必须是**纯文本**：前端会整体 esc() 再插入，
    //    这里塞 <b> 会被原样显示成「<b>50</b>」（v1.2.44 实测踩过）。
    //    需要强调的数字由前端用结构化字段（cost/quota/used/level/points）自己拼。
    $dec['reason'] = $why . '，可消耗 ' . $cost . ' ' . ow_points_name() . '创建（当前 ' . $pts . ' ' . ow_points_name() . '）。';
});

/**
 * v1.2.43：**建群不再受等级限制**（用户定案）。

/** 单文件大小上限：按等级取全局上限的比例 */
Plugin::on('upload.maxsize', function (int &$maxBytes, array $actor) {
    if (owSHLCfg('gating') !== '1') return;
    if (($actor['role'] ?? '') === 'admin') return;            // 超管用全局上限
    $ratio = owSHLUnlocks(owSHLLevelOf((int)$actor['id']))['ratio'];
    if ($ratio <= 0) { $maxBytes = 1; return; }                // 未解锁：只允许极小文件（实际会超限被拒）
    $maxBytes = (int)floor($maxBytes * $ratio);
});

/** 聊天附件闸门：3 级起才解锁文件上传（设计文档第 1 次解锁） */
Plugin::on('attachment.guard', function (bool &$allow, string &$reason, array $actor) {
    if (owSHLCfg('gating') !== '1') return;
    if (($actor['role'] ?? '') === 'admin') return;
    $lv = owSHLLevelOf((int)$actor['id']);
    if (owSHLUnlocks($lv)['ratio'] <= 0) {
        $allow = false; $reason = '上传文件需 ' . owSHLInt('lv_file') . ' 级解锁（当前 Lv.' . $lv . '）';
    }
});

/* ============================ 计划任务 ============================ */
// 每天清理一次「当日活跃」去重键。不清理的话这张表会按「天数 × 人数」无限膨胀。
Plugin::cron('purge_active_keys', 86400, function () {
    owSHLPurgeActiveKeys(40);
}, '清理 40 天前的「当日活跃」去重键（活跃趋势统计用）');

// 每天一次：荣誉等级长期不登录消退（v3.1）。参数在后台「长期不登录消退」一栏。
Plugin::cron('decay_idle', 86400, function () {
    return owSHLDecayRun();
}, '长期不登录的荣誉等级消退（起始等级/最低等级/不登录天数均可在后台配置）');

/**
 * 长期不登录消退（v3.1，每日跑一次）。
 *
 * 规则（站方定案）：每满 decay_idle_days（默认 30）天不登录退 1 级；
 * 只对 ≥ decay_from（默认 60）的用户生效；最低退到 decay_floor（默认 46）为止；
 * **不退不补** —— 扣掉的「天」永久留着，用户回来靠自己攒在线/任务天涨回去。
 *
 * ⚠️ 为什么能真降下来：等级重算走 `max(旧等级, 公式值)`（只升不降），而消退天是
 *    公式里的**负项**，公式值本身就降了。所以这里显式把 level 写成公式值
 *    （**不能**走 owSHLRecalc —— 那里有 max 保护会把它顶回去），且只往下压不往上抬。
 *
 * decay_base = 「已经计入消退的不登录天数」，有两个作用：
 *   ① 让任务可重复执行：后台「立即执行」会 force 跑全部任务，多跑几次不该多退；
 *   ② 抵消「每满 N 天」的取整，将来把 N 从 30 改成 15 也不会把历史天数一次性折成级别。
 * 用户重新活跃后（不登录天数 < 基线）基线归零，下一次长期不来从头开始算。
 *
 * @return string 写进计划任务日志的一行摘要
 */
function owSHLDecayRun(): string
{
    owSHLBoot();
    [$idleDays, $from, $floor] = owSHLDecayCfg();
    if ($idleDays < 1) return '消退已关闭（「长期不登录天数」为 0）';

    $today = date('Y-m-d');
    $rows  = DB::all('SELECT * FROM plugin_level_trust WHERE level>=?', [$from]);
    $checked = count($rows);
    $hit = 0; $dropped = 0;

    foreach ($rows as $row) {
        $uid  = (int)($row['user_id'] ?? 0);
        $last = (string)($row['day'] ?? '');
        if ($uid <= 0 || $last === '') continue;      // 从没活跃过 → 没有「多久没来」可言
        $ts = strtotime($last);
        if ($ts === false) continue;

        $idle = (int)floor((strtotime($today) - $ts) / 86400);
        if ($idle < 0) $idle = 0;

        // $stored 是库里的旧基线：必须留一份再可能被归零，否则「要不要落库」判断不出来
        // （下面的 $steps<=0 分支就是靠它对比的，写完再比就永远相等 → 归零丢在内存里）。
        $stored = (int)($row['decay_base'] ?? 0);
        $base = $stored;
        if ($idle < $base) $base = 0;                 // 重新活跃过 → 新一轮从头算

        $steps = (int)floor(($idle - $base) / $idleDays);
        if ($steps <= 0) {
            // 只有「基线被归零」这一步需要落库，不然用户回来后基线永远留在高位，
            // 下一轮他要多等一整个周期才会被消退。
            if ($base !== $stored) {
                $row['decay_base'] = $base;
                owSHLSave($uid, $row, owSHLDecode((string)$row['today']));
            }
            continue;
        }
        // 每步退 1 级，所以最多只退到 floor —— 多退的步数不算数，
        // 否则「消退天」会虚涨：用户回来要攒 5 天才能回到只差 1 级的水平。
        $maxSteps = (int)$row['level'] - $floor;
        if ($maxSteps <= 0) continue;                 // 已经在最低等级上
        if ($steps > $maxSteps) $steps = $maxSteps;

        $before = (int)$row['level'];
        $row['decay_days'] = (float)($row['decay_days'] ?? 0) + $steps;
        $row['decay_base'] = $base + $steps * (int)$idleDays;
        $calc = owSHLLevelFromTotal(owSHLTotalDays($row));        // 公式值（已含 −消退天）
        $row['level'] = min($before, max($floor, $calc));       // 只降不升，且不低于 floor
        owSHLSave($uid, $row, owSHLDecode((string)$row['today']));

        $hit++;
        if ((int)$row['level'] < $before) $dropped++;
    }
    return '检查 ' . $checked . ' 人（≥ Lv.' . $from . '），消退 ' . $hit . ' 人，'
        . '其中 ' . $dropped . ' 人等级下降；规则：每满 ' . owSHLNum($idleDays) . ' 天不登录退 1 级，最低 Lv.' . $floor;
}

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
    $level = owSHLLevelOf($uid);
});

/**
 * `user.level.set` —— 设置某用户等级（后台「用户管理」用）。
 * $ok 传入 **null**：没有插件响应时它保持 null，调用方据此提示「未安装等级插件」，
 * 而不是误报成功。
 *
 * v3.0 起等级是按「天」算的（在线 + 任务 + 设定），所以直设等级必须补差额：
 * 走 owSHLAlignLevel() 把差额记进 bonus_days，等级页才能如实拆开展示。
 * 不补的话就会出现「Lv.30 配累计 4 天」—— 页面自己写着 L = 1 + ⌊天数⌋，整页自相矛盾。
 *
 * ```php
 * $ok = null; $msg = '';
 * Plugin::fire('user.level.set', [$uid, $lv, &$ok, &$msg]);
 * ```
 */
Plugin::on('user.level.set', function (int $uid, int $level, ?bool &$ok, string &$msg) {
    if ($uid <= 0) { $ok = false; $msg = '非法用户'; return; }
    if ($level < 1) { $ok = false; $msg = '等级需为 ≥1 的整数'; return; }
    $row = owSHLRow($uid);
    if (!$row) { $ok = false; $msg = '用户不存在'; return; }
    $t = owSHLDecode((string)$row['today']);
    owSHLAlignLevel($row, $level);
    owSHLSave($uid, $row, $t);
    $ok = true;
    $msg = '等级已设为 Lv.' . (int)$row['level'];
});

/* ============================ 前台等级页（?page=level） ============================ */

/** 任务 key → 中文（v2.0 起任务只剩登录与群发言；旧键保留映射以防存量 today JSON 回显） */
function owSHLTaskLabel(string $k): string
{
    $m = [
        'login' => '登录', 'gm' => '群发言',
        'gfirst' => '当天首条群发言', 'pfirst' => '当天首条私聊',
        'fup' => '上传文件', 'fdl' => '下载文件', 'fact' => '群文件操作',
    ];
    return $m[$k] ?? $k;
}

/** 舞台配色（与资料卡徽章一致） */
function owSHLStageColor(int $no): string
{
    $c = [1 => '#9AA5B1', 2 => '#0099FF', 3 => '#13A8A8', 4 => '#7A5AF8', 5 => '#D4A017'];
    return $c[$no] ?? '#9AA5B1';
}

/** 小数去尾：0.50 → 0.5、3.00 → 3、0 → 0（等级页与后台共用的数字显示口径） */
function owSHLNum(float $v): string
{
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
}

/** 秒 → 中文时长（累计在线时长显示用：8 分 / 2 小时 15 分 / 3 天 4 小时） */
function owSHLFmtDur(int $sec): string
{
    if ($sec < 60) return max(0, $sec) . ' 秒';
    $m = intdiv($sec, 60);
    if ($m < 60) return $m . ' 分';
    $hh = intdiv($m, 60);
    $mm = $m % 60;
    if ($hh < 24) return $hh . ' 小时' . ($mm > 0 ? ' ' . $mm . ' 分' : '');
    $d = intdiv($hh, 24);
    $rh = $hh % 24;
    return $d . ' 天' . ($rh > 0 ? ' ' . $rh . ' 小时' : '');
}

/** 能力解锁对比表：返回每一档的能力清单（档位取自全部配置过的等级门槛） */
function owSHLUnlockMatrix(): array
{
    // 每一行 = 一个有意义的档位（所有能力门槛 ∪ 阶段起点），去重后升序
    // ⚠️ 必须**按配置现读**：后台改了解锁等级，这张表要跟着变，不能写死。
    $marks = array_values(array_unique(array_merge(
        [owSHLInt('lv_file'), owSHLInt('lv_avatar'), owSHLInt('lv_friend'), owSHLInt('lv_file_half'),
         owSHLInt('lv_sticker'), owSHLInt('lv_mod_cand'), owSHLInt('lv_file_full'), owSHLInt('lv_honor')],
        owSHLStageBounds()
    )));
    sort($marks);
    foreach ($marks as $lv) {
        $u = owSHLUnlocks($lv);
        $ratio = (float)$u['ratio'];
        $rows[] = [
            'lv' => $lv,
            'stage' => owSHLStageName(owSHLStageNo($lv)),
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
Plugin::page('level', '星光殿堂', function (array $actor, $user = null) {
    $isUser = ($actor['kind'] ?? '') === 'user';
    $uid = (int)($actor['id'] ?? 0);
    $lv = $isUser ? owSHLLevelOf($uid) : 1;
    $stageNo = owSHLStageNo($lv);
    $stage = owSHLStage($lv);
    $u = owSHLUnlocks($lv);
    $h = '';

    /* ---------- 我的等级 ---------- */
    $h .= '<div class="ow-card ow-lv-hero ow-lv-s' . $stageNo . '">'
        . '<div class="ow-lv-hero-lv"><span class="ow-lt-badge ow-lt-s' . $stageNo
        . ($lv >= owSHLInt('lv_honor') ? ' ow-lt-honor' : '') . '">Lv.' . $lv . '</span>'
        . '<span class="ow-lv-hero-stage">' . Sec::e($stage['name']) . '</span></div>';

    if ($isUser) {
        [$row, $t] = owSHLTouch($uid);
        $snap = owSHLSnapshot($row);
        // v3.0：等级有三个来源，页面如实拆开展示（后台直设过的等级会多一行「管理员设定」）。
        // 上一版把三项混成一个「累计完成 N 天」，后台一改等级就自相矛盾。
        $h .= '<div class="ow-lv-hero-meta">'
            . '<span>累计在线 <b>' . owSHLFmtDur((int)$snap['online_sec']) . '</b></span>'
            . '<span>任务奖励 <b>' . owSHLNum((float)$snap['task_days']) . '</b> 天</span>'
            . ($snap['bonus_days'] > 0.0001
                ? '<span>管理员设定 <b>' . owSHLNum((float)$snap['bonus_days']) . '</b> 天</span>'
                : '')
            . ($snap['decay_days'] > 0.0001
                ? '<span class="ow-lv-decay">长期未登录消退 <b>−' . owSHLNum((float)$snap['decay_days']) . '</b> 天</span>'
                : '')
            . '<span>连续登录 <b>' . (int)$row['login_streak'] . '</b> 天</span>'
            . '<span>距 Lv.' . (int)$snap['next_level'] . ' 还需 <b>' . owSHLNum((float)$snap['gap_days']) . '</b> 天</span>'
            . '</div>'
            . '<div class="ow-lv-bar"><i style="width:' . (int)$snap['pct'] . '%"></i></div>';
    } else {
        $h .= '<div class="ow-lv-hero-meta"><span>登录后即可看到你的等级进度与今日任务。</span></div>';
    }
    $h .= '</div>';

    /* ---------- 阶段总览（v1.2.59）---------- */
    $b = owSHLStageBounds();
    $gm = owSHLStageGm();
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
        $h .= '<div class="' . $cls . '" style="--stage-color:' . owSHLStageColor($sno) . '">'
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
        $got  = 0.0;                       // 今日已到手的天数（= 今日实际进度）
        $all  = 0.0;
        foreach (owSHLTasksToday($row, $t) as $task) {
            $has = (int)$task['cur'];
            $n   = (int)$task['n'];
            $ok  = $task['ok'] === 1;
            if ($ok) $done++;
            $all += (float)$task['rw'];
            if ($task['paid'] === 1) $got += (float)$task['rw'];
            $h .= '<div class="ow-lv-task">'
                . '<span class="ow-lv-task-k">' . Sec::e((string)$task['label'])
                . ($task['rw'] > 0 ? ' <b class="ow-lv-task-rw">+' . owSHLNum((float)$task['rw']) . ' 天</b>' : '')
                . '</span>'
                . '<span class="ow-lv-task-bar"><i style="width:' . (int)min(100, $n > 0 ? $has / $n * 100 : 0) . '%"></i></span>'
                . '<span class="ow-lv-task-v' . ($ok ? ' done' : '') . '">' . $has . ' / ' . $n . '</span>'
                . '</div>';
        }
        $h .= '<div class="ow-lv-task-sum">'
            . '今日任务完成 <b>' . $done . ' / ' . count($stage['base']) . '</b>，'
            . '已获得 <b>' . owSHLNum($got) . '</b> / ' . owSHLNum($all) . ' 天'
            . ($got > 0 && $got >= $all ? '（<b style="color:var(--ow-green)">今日已全部到手</b>）' : '')
            . '。每个任务每天各计一次，次日清零，完不成也不降级。</div>'
            . '</div>';
    }

    /* ---------- 等级公式 ---------- */
    [$decayIdle, $decayFrom, $decayFloor] = owSHLDecayCfg();
    $h .= '<div class="ow-card"><h3 class="ow-lv-h3">等级怎么算</h3>'
        . '<div class="ow-lv-formula">L = 1 + ⌊ 在线天 + 任务天 + 设定天 − 消退天 ⌋</div>'
        . '<ul class="ow-lv-ul">'
        . '<li><b>在线天</b>：累计在线每满 <b>' . owSHLNum(owSHLOnlineHours()) . ' 小时</b> = 1 天 = 1 级。'
        . '页面开着就算在线；离开后再回来不会补记（单次最多记 2 分钟）</li>'
        . '<li><b>任务天</b>：完成每日任务直接加「天」，每个任务加多少天由后台设置'
        . '（当前：' . owSHLRewardText() . '），每个任务每天只计一次</li>'
        . '<li><b>签到天</b>：在「星光殿堂」里每天签到一次也加天（默认 '
        . owSHLNum(owSHLCheckinReward()) . ' 天，后台可调；填 0 即不涨等级，只发' . Sec::e(ow_points_name()) . '）。'
        . '签到本身每天只能一次，因此这一项不需要额外的去重</li>'
        . '<li><b>设定天</b>：仅在管理员直接设置等级时出现，用来补齐「等级」与「天」的差额，'
        . '普通用户不会有这一项</li>'
        . ($decayIdle >= 1
            ? '<li><b>消退天</b>：达到 Lv.' . $decayFrom . ' 以后，连续 <b>' . owSHLNum($decayIdle)
              . ' 天</b>不登录退 1 级，最多退到 <b>Lv.' . $decayFloor . '</b> 为止。'
              . '消退掉的进度不会自动还回来，回来继续攒在线与任务天数即可涨回去</li>'
            : '<li>长期不登录消退当前<strong>未启用</strong>（后台「长期不登录天数」为 0）</li>')
        . '<li>三项相加减去消退天，向下取整再加 1 就是等级；因为取整，实际是「攒够一天跳一级」的节奏</li>'
        . '<li>未完成任务不降级，已攒下的在线时长与任务奖励都不会被收回</li>'
        . '</ul>'
        . '<div class="ow-lv-tip">有效发言需同时满足：内容 ≥ ' . owSHLInt('msg_min_len') . ' 字、非纯表情/纯图片/纯链接、'
        . '距上一条计入任务 ≥ ' . owSHLInt('msg_cooldown') . ' 秒、每分钟 ≤ ' . owSHLInt('msg_per_minute')
        . ' 条、与最近 ' . owSHLInt('msg_dedupe') . ' 条不重复。</div>'
        . '</div>';

    /* ---------- 各等级条件一览 ---------- */
    $h .= '<div class="ow-card"><h3 class="ow-lv-h3">各阶段任务一览</h3><table class="ow-lv-table">'
        . '<tr><th>阶段</th><th>等级</th><th>保底任务</th><th>完成奖励</th></tr>';
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
        // 奖励与阶段无关（登录/群发言各一个键），所以五行的奖励列是同一组数
        $rw = '+' . owSHLNum(owSHLReward('login') + owSHLReward('gm')) . ' 天';
        $h .= '<tr class="ow-lv-tr' . ($stageNo === $d[0] ? ' is-cur' : '') . '">'
            . '<td><span class="ow-lv-dot" style="background:' . owSHLStageColor($d[0]) . '"></span>'
            . Sec::e($d[1]) . '</td>'
            . '<td>' . Sec::e($range) . '</td><td>' . Sec::e($base) . '</td>'
            . '<td>' . Sec::e($rw) . '</td></tr>';
    }
    $h .= '</table></div>';

    /* ---------- 能力解锁对比 ---------- */
    // 9 列，窄屏上压进卡片会把每列挤成一竖溜换行（表头是中文双/三字词，一换行就错位）。
    // 外层 .ow-lv-card-scroll 在 ≤640px 时开横向滚动，配合 style.css 里的 width:auto，
    // 让表格按内容自然展开、超出部分横向滑。
    // ⚠️ 高亮行 = 「≤ 当前等级的最大档位」，不是「等于当前等级」：
    //    档位取自各项解锁门槛，等级几乎不会恰好落在档位上（后台直设成 Lv.30 时
    //    档位表里就没有 30），按等于匹配会导致整张表一行都不高亮。
    $marks = [];
    foreach (owSHLUnlockMatrix() as $mr) $marks[] = (int)$mr['lv'];
    $curMark = 0;
    foreach ($marks as $mk) if ($mk <= $lv && $mk > $curMark) $curMark = $mk;
    $h .= '<div class="ow-card"><h3 class="ow-lv-h3">能力解锁对比</h3><div class="ow-lv-card-scroll"><table class="ow-lv-table">'
        . '<tr><th>等级</th><th>阶段</th><th>建群名额</th><th>头像</th><th>贴纸上限</th>'
        . '<th>加好友</th><th>单文件上限</th><th>管理候选</th><th>荣誉</th></tr>';
    foreach (owSHLUnlockMatrix() as $r) {
        $y = '<span class="ow-lv-yes">✓</span>';
        $n = '<span class="ow-lv-no">—</span>';
        $h .= '<tr class="' . ((int)$r['lv'] === $curMark ? 'is-cur' : '') . '">'
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
        . owSHLInt('room_min_level') . ' 级时，可消耗 <b>' . owSHLInt('room_point_cost')
        . '</b> ' . Sec::e(ow_points_name()) . '创建群聊（每个群一次）。把该单价设为 0 即为「不允许用'
        . Sec::e(ow_points_name()) . '绕过」。</div>'
        . '</div>';

    return $h;
});

/* ============================ 后台路由（等级子系统） ============================ */

/** 管理员鉴权：必须定义在 use($ltGuard) 之前（use 捕获的是变量值） */
$ltGuard = function (array $ctx): void {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
};

/** 后台概览：中位数 + 各阶段分布 + 近 N 天活跃趋势 */
Plugin::route('plugin_starlight_hall_level_stats', function (array $ctx) use ($ltGuard) {
    $ltGuard($ctx);
    owSHLBoot();
    $rows = DB::all('SELECT level FROM plugin_level_trust');
    $dist = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
    $sum = 0;
    foreach ($rows as $r) { $dist[owSHLStageNo((int)$r['level'])]++; $sum += (int)$r['level']; }

    // 阶段名 + 等级区间随配置现算：后台改了阶段阈值，概览里的分布标签要跟着变，
    // 不能像旧版那样把「新手 1-5」写死在前端（v1.1.5）。
    $b = owSHLStageBounds();
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
        $stageMeta[] = ['no' => $no, 'name' => owSHLStageName($no), 'range' => $ranges[$no - 1]];
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
        'median' => owSHLMedian(),
        'avg' => $rows ? round($sum / count($rows), 1) : 0,
        'dist' => $dist,
        'stage_meta' => $stageMeta,
        'gating' => owSHLCfg('gating'),
        'trend' => $trend,
        // 口径说明随数据一起下发，前端直接展示 —— 写死在 JS 里日后容易与实际脱节
        'trend_note' => '近 ' . $days . ' 天 · 活跃用户仅统计登录用户（游客不计入），'
            . '历史长度受「当日活跃」去重键保留 40 天限制；升级次数为当日实际升幅之和（可能一次升 2 级）。',
    ]);
});

/** 读取全部可调参数（后台配置表单回填） */
Plugin::route('plugin_starlight_hall_level_cfg_get', function (array $ctx) use ($ltGuard) {
    $ltGuard($ctx);
    owSHLBoot();
    $cfg = [];
    foreach (owSHLDefaults() as $k => $def) $cfg[$k] = owSHLCfg($k);
    Api::json(['ok' => true, 'cfg' => $cfg, 'tiers' => owSHLRoomTiers()]);
});

/**
 * 保存可调参数。
 * ⚠️ 白名单 = owSHLDefaults() 的键集合：只有在这里出现过的键才允许写入。
 *    这样即使前端偷偷塞了别的键（比如往配置表里写垃圾），也落不了库。
 */
Plugin::route('plugin_starlight_hall_level_cfg_save', function (array $ctx) use ($ltGuard) {
    $ltGuard($ctx);
    $post = $ctx['post'];
    $allow = array_keys(owSHLDefaults());
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
        owSHLSetCfg($k, $v);
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
        owSHLSetCfg('room_tiers', (string)$post['room_tiers']);
        $saved++;
    }
    Sec::log('level_cfg_save', (string)($ctx['actor']['nickname'] ?? ''), ['items' => $saved]);
    Api::json(['ok' => true, 'msg' => '参数已保存（' . $saved . ' 项）']);
});
Plugin::sensitive('plugin_starlight_hall_level_cfg_save');   // 改全站等级规则：敏感


/** 查单个用户 */
Plugin::route('plugin_starlight_hall_level_user', function (array $ctx) use ($ltGuard) {
    $ltGuard($ctx);
    $uid = (int)($ctx['post']['uid'] ?? 0);
    if ($uid <= 0) Api::json(['ok' => false, 'msg' => '请填写用户 ID']);
    $u = DB::one('SELECT id, nickname, role FROM users WHERE id=?', [$uid]);
    if (!$u) Api::json(['ok' => false, 'msg' => '用户不存在']);
    [$row, $t] = owSHLTouch($uid);
    $stage = owSHLStage((int)$row['level']);
    $tasks = owSHLTasksToday($row, $t);
    Api::json([
        'ok' => true,
        'uid' => $uid, 'nickname' => (string)$u['nickname'], 'role' => (string)$u['role'],
        'level' => (int)$row['level'],
        'stage' => $stage['name'], 'stage_no' => owSHLStageNo((int)$row['level']),
        'honor' => (int)$row['level'] >= owSHLInt('lv_honor') ? 1 : 0,
        'login_streak' => (int)$row['login_streak'], 'login_days' => (int)$row['login_days'],
        'settled' => (int)$row['settled'], 'frozen' => (int)$row['frozen_day'] === (int)date('Ymd') ? 1 : 0,
        'patch_used' => (int)$row['patch_used'], 'protect_used' => (int)$row['protect_used'],
        'tasks' => $tasks, 'today' => $t,
        'prog' => owSHLSnapshot($row),      // v3.0：在线/任务/设定三项拆开给后台展示
    ]);
});

/** 管理员操作：补签 / 冻结 / 解冻 / 直接设置 */
Plugin::route('plugin_starlight_hall_level_op', function (array $ctx) use ($ltGuard) {
    $ltGuard($ctx);
    $uid = (int)($ctx['post']['uid'] ?? 0);
    $op = (string)($ctx['post']['op'] ?? '');
    if ($uid <= 0) Api::json(['ok' => false, 'msg' => '请填写用户 ID']);
    $row = owSHLRow($uid);
    if (!$row) Api::json(['ok' => false, 'msg' => '用户不存在']);
    $t = owSHLDecode((string)$row['today']);

    switch ($op) {
        case 'patch':       // 补签：每周最多 1 次，视为完成当天全部保底任务 → 发放当日全部任务奖励
            $week = date('o-W');
            if ((string)$row['patch_week'] !== $week) { $row['patch_week'] = $week; $row['patch_used'] = 0; }
            if ((int)$row['patch_used'] >= 1) Api::json(['ok' => false, 'msg' => '本周补签次数已用完']);
            $row['patch_used'] = (int)$row['patch_used'] + 1;
            // 把当天每个任务的进度顶到要求值 —— 而不是绕过任务直接改等级，
            // 这样「补签得到的东西」和「真做完任务得到的东西」严格一致（v3.0 逐任务发奖）。
            foreach (owSHLStage((int)$row['level'])['base'] as $tk) {
                $k = (string)$tk['k'];
                if (owSHLProg($t, $k) < (int)$tk['n']) $t[$k] = (int)$tk['n'];
            }
            $before = (int)$row['level'];
            owSHLPayTasks($row, $t);
            $row['settled'] = 1;
            owSHLRecalc($row);
            owSHLSave($uid, $row, $t);
            if ((int)$row['level'] > $before) owSHLBump('upgrades');
            Api::json(['ok' => true, 'msg' => (int)$row['level'] > $before
                ? '已补签，等级提升至 Lv.' . (int)$row['level']
                : '已补签，当日任务奖励已发放（等级不变，仍 Lv.' . (int)$row['level'] . '）']);

        case 'freeze':      // 冻结当天任务进度（结算不再进行）
            $row['frozen_day'] = (int)date('Ymd');
            owSHLSave($uid, $row, $t);
            Api::json(['ok' => true, 'msg' => '已冻结今日任务']);

        case 'unfreeze':
            $row['frozen_day'] = 0;
            owSHLSave($uid, $row, $t);
            Api::json(['ok' => true, 'msg' => '已解除冻结']);

        case 'set':         // 直接设置等级（可选同时改「任务累计天数」）；差额自动记进「管理员设定」
            $lv = max(1, (int)($ctx['post']['level'] ?? 1));
            // 兼容旧前端字段名 days（v3.0 起改叫 task_days，语义也从整数活跃天数变成小数）
            $raw = $ctx['post']['task_days'] ?? ($ctx['post']['days'] ?? null);
            if ($raw !== null && $raw !== '') {
                $row['task_days'] = max(0.0, min(1000000.0, (float)$raw));
            }
            // ⚠️ 关键一步：把「公式还没算到」的差额记进 bonus_days，
            //    否则等级页会同时显示 Lv.N 与一个远低于 N 的天数（v1.3.150 前的表现）。
            owSHLAlignLevel($row, $lv);
            owSHLSave($uid, $row, $t);
            Api::json(['ok' => true, 'msg' => '已设为 Lv.' . (int)$row['level']
                . '（任务累计 ' . round((float)$row['task_days'], 2) . ' 天'
                . ((float)$row['bonus_days'] > 0
                    ? '，管理员设定 ' . round((float)$row['bonus_days'], 2) . ' 天'
                    : '')
                . '）']);

        case 'clear_decay':  // v3.1：清除长期不登录消退（误消退时的运维手段）
            // 退回已扣的「天」，并把基线贴到当前不登录天数 —— 等于「从现在起重新计时」：
            // 不这样做的话下一次定时任务会立刻按老的不登录天数把消退重新扣一遍。
            $row['decay_days'] = 0.0;
            $last = (string)($row['day'] ?? '');
            $idle = $last === '' ? 0 : (int)floor((strtotime(date('Y-m-d')) - (int)strtotime($last)) / 86400);
            $row['decay_base'] = max(0, $idle);
            $before = (int)$row['level'];
            owSHLRecalc($row);                 // 这里可以走 recalc：清除是「往上涨」
            owSHLSave($uid, $row, $t);
            Api::json(['ok' => true, 'msg' => $before === (int)$row['level']
                ? '已清除消退（等级不变，仍 Lv.' . (int)$row['level'] . '）'
                : '已清除消退，等级回到 Lv.' . (int)$row['level']]);

        case 'gate':        // 限制总开关
            $v = (string)($ctx['post']['v'] ?? '1') === '1' ? '1' : '0';
            owSHLSetCfg('gating', $v);
            Api::json(['ok' => true, 'msg' => $v === '1' ? '已开启等级限制' : '已关闭等级限制']);

        default:
            Api::json(['ok' => false, 'msg' => '未知操作']);
    }
});
Plugin::sensitive('plugin_starlight_hall_level_op');   // 涉及改等级/补签，走一次性票据

/* ==========================================================================
   签到子系统（原 plugins/daily-checkin）
   以下全部为 owSHC* 函数 / plugin_starlight_hall_checkin_* 路由 /
   plugin_checkin_user|log|cfg 三张表。两条子系统共用一个面板与一套后台页，
   但内部实现刻意保持互不引用，回滚或再拆分时不必先解开耦合。
   ========================================================================== */

/* ============================ 建表（签到子系统） ============================ */

/** 懒建表：只在真正读写数据时执行（main.php 顶层不做副作用） */
function owSHCBoot(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    // 主键：MySQL 用无符号 BIGINT 与 users.id 对齐，SQLite/PG 用普通整型即可
    $pk = DB::driver() === 'mysql' ? 'BIGINT UNSIGNED PRIMARY KEY' : 'INTEGER PRIMARY KEY';
    $id = DB::autoId();                       // 自增主键（三驱动方言由核心给）
    $ts = DB::driver() === 'mysql' ? 'BIGINT NOT NULL' : 'INTEGER NOT NULL';

    DB::run("CREATE TABLE IF NOT EXISTS plugin_checkin_user (
        user_id      {$pk},
        total_days   INTEGER NOT NULL DEFAULT 0,   -- 累计签到天数
        streak       INTEGER NOT NULL DEFAULT 0,   -- 当前连续天数
        max_streak   INTEGER NOT NULL DEFAULT 0,   -- 历史最长连续
        points       INTEGER NOT NULL DEFAULT 0,   -- 本插件累计发放的积分
        patch_used   INTEGER NOT NULL DEFAULT 0,   -- 本月已用补签次数
        " . DB::textCol('patch_mon') . ",           -- 补签计数所属月份 Y-m
        " . DB::textCol('last_day') . ",            -- 最近一次签到的自然日 Y-m-d
        created_at   {$ts} DEFAULT 0,
        updated_at   {$ts} DEFAULT 0)");

    DB::run("CREATE TABLE IF NOT EXISTS plugin_checkin_log (
        id           {$id},
        user_id      INTEGER NOT NULL,
        day          VARCHAR(10) NOT NULL DEFAULT '',  -- Y-m-d
        -- ⚠️ day 不能用 DB::textCol()（MySQL 下是 TEXT）：它在下面被两条索引用到
        --    （user_id,day 唯一 + day 单列），而 MySQL 的 TEXT/BLOB 做键要指定长度 → 1170。
        points       INTEGER NOT NULL DEFAULT 0,   -- 本次实发积分（补签为 0）
        streak       INTEGER NOT NULL DEFAULT 0,   -- 本次完成后的连续天数
        " . DB::textCol('kind', "'normal'") . ",    -- normal 普通 / patch 补签 / admin 管理员代签
        created_at   {$ts} DEFAULT 0)");

    // ⚠️ k 必须是 VARCHAR 不能是 TEXT：MySQL 下 TEXT/BLOB 列做键要指定长度，否则建表 1170 报错
    //    （"BLOB/TEXT column 'k' used in key specification without a key length"），
    //    建表失败会把该插件的每一次页面加载都变成 500；SQLite 不挑，本机看不出问题。
    DB::run("CREATE TABLE IF NOT EXISTS plugin_checkin_cfg (
        k VARCHAR(64) PRIMARY KEY, " . DB::textCol('v') . ")");

    // (user_id, day) 唯一：并发下重复提交由数据库兜底，比「先查后插」可靠。
    // day 单独一条索引：月历与排行榜都按日期区间筛，没有它就得全表扫。
    DB::createIndex('idx_ci_log_uday', 'plugin_checkin_log', 'user_id, day', true);
    DB::createIndex('idx_ci_log_day',  'plugin_checkin_log', 'day', false);
}

/* ============================ 配置（签到子系统） ============================ */

/**
 * 全部可调参数与默认值。
 * ⚠️ 每加一个新键，必须同时：① 这里给默认值；② 后台表单给输入框；③ cfg_save 白名单放行。
 *    漏了 ③ 的表现是「后台保存成功但值没变」。
 */
function owSHCDefaults(): array
{
    return [
        'enable'      => '1',    // 总开关（0 = 关闭签到，入口显示「未开放」）
        'base'        => '5',    // 基础积分
        'step'        => '1',    // 连续每多 1 天额外增加
        'step_cap'    => '10',   // 递增封顶（额外部分最多加这么多）
        'cycle_days'  => '7',    // 每连续满 N 天发一笔周期大奖（0 = 不发）
        'cycle_bonus' => '20',   // 周期大奖积分
        'patch_cost'  => '30',   // 补签卡单价（积分）；0 = 不提供补签
        'patch_limit' => '2',    // 每人每月补签次数上限
        'rank_size'   => '8',    // 排行榜展示人数（前台「本月签到榜」）
        // 后台「累计签到榜」显示人数。原来写死 10，站方反馈「只显示前 10」后改成可配（默认 50）——
        // 累计榜是给站方看全貌的，截断到什么程度应该由站方自己定。
        // ⚠️ 与 rank_size 是两项：那个管前台面板的月榜，这个管后台的累计榜。
        'top_size'    => '50',
    ];
}

/**
 * 读配置（字符串）。
 * 缓存放 $GLOBALS 而非函数内 static：static 无法被 owSHCSetCfg 主动清掉，
 * 会出现「后台刚改完、本请求后续逻辑仍按旧值算」的不一致。
 */
function owSHCCfg(string $k): string
{
    owSHCBoot();
    if (!isset($GLOBALS['_owSHCCfg']) || !is_array($GLOBALS['_owSHCCfg'])) $GLOBALS['_owSHCCfg'] = [];
    if (array_key_exists($k, $GLOBALS['_owSHCCfg'])) return $GLOBALS['_owSHCCfg'][$k];
    $v = DB::val('SELECT v FROM plugin_checkin_cfg WHERE k=?', [$k]);
    $def = owSHCDefaults();
    $GLOBALS['_owSHCCfg'][$k] = ($v === null || $v === false || $v === '') ? ($def[$k] ?? '') : (string)$v;
    return $GLOBALS['_owSHCCfg'][$k];
}

/** 读整数配置（后台填了非法值时回落到默认，避免算出负数奖励） */
function owSHCInt(string $k): int
{
    $v = owSHCCfg($k);
    if (!preg_match('/^-?\d+$/', $v)) return (int)(owSHCDefaults()[$k] ?? 0);
    return (int)$v;
}

function owSHCSetCfg(string $k, string $v): void
{
    owSHCBoot();
    DB::run('DELETE FROM plugin_checkin_cfg WHERE k=?', [$k]);
    DB::run('INSERT INTO plugin_checkin_cfg (k, v) VALUES (?,?)', [$k, $v]);
    unset($GLOBALS['_owSHCCfg'][$k]);      // 立刻失效，同请求内后续读取拿到新值
}

/* ============================ 系统通知（签到到账） ============================ */

/**
 * 签到发积分的通知模板（v1.1.5）。
 *
 * 规则：**只要用户账上的 {unit} 变多，就必须留一条系统通知**（PLUGIN.md「积分变动必须通知」）。
 * 签到的积分虽然由用户自己点出来（面板上也有结果提示），但「管理员代签」这条路径是
 * 完全异步的 —— 被代签的人不刷新面板就不知道钱到账了；统一走通知，三条入口口径一致。
 * {unit} 由 push 时传 ow_points_name()，站点把「积分」改名成金币/星光时文案自动跟随。
 */
Plugin::on('notice.kinds', function (array &$kinds) {
    $kinds['shc_checkin']       = '签到成功，获得 {points} {unit}，已连续签到 {streak} 天。';
    // 管理员代签是**别人替他点的**：同一句「签到成功」会让用户以为是自己签的，语气对不上
    $kinds['shc_checkin_admin'] = '管理员为你代签了 {day} 的签到，获得 {points} {unit}。';
});

/* ============================ 数据读写（签到子系统） ============================ */

/** 取用户行；不存在则按「从未签到」创建 */
function owSHCUser(int $uid): array
{
    owSHCBoot();
    $row = DB::one('SELECT * FROM plugin_checkin_user WHERE user_id=?', [$uid]);
    if ($row) return $row;
    try {
        DB::run('INSERT INTO plugin_checkin_user (user_id, created_at, updated_at) VALUES (?,?,?)',
            [$uid, time(), time()]);
    } catch (Throwable $e) {
        // 并发下已被别人插入 → 直接读回来即可
    }
    return DB::one('SELECT * FROM plugin_checkin_user WHERE user_id=?', [$uid]) ?: [];
}

function owSHCSigned(int $uid, string $day): bool
{
    owSHCBoot();
    return (bool)DB::val('SELECT COUNT(*) FROM plugin_checkin_log WHERE user_id=? AND day=?', [$uid, $day]);
}

/**
 * 连续第 $streak 天应发的积分明细。
 * 公式 = 基础 + min(递增 × (天数 - 1), 递增封顶) +（天数是周期整数倍时）周期大奖
 */
function owSHCRewardParts(int $streak): array
{
    $base  = max(0, owSHCInt('base'));
    $step  = max(0, owSHCInt('step'));
    $cap   = max(0, owSHCInt('step_cap'));
    $cyc   = max(0, owSHCInt('cycle_days'));
    $bonus = max(0, owSHCInt('cycle_bonus'));
    $add   = min($step * max(0, $streak - 1), $cap);
    $hit   = ($cyc > 0 && $streak > 0 && $streak % $cyc === 0) ? $bonus : 0;
    return ['base' => $base, 'step' => $add, 'bonus' => $hit, 'total' => $base + $add + $hit];
}

/**
 * 签到的唯一入口 —— 普通签到 / 补签 / 管理员代签都走这里，避免三处各写一套连续天数算法。
 *
 * @param string $day    目标自然日 Y-m-d（补签时是过去某天）
 * @param bool   $reward 是否发放积分（补签只补记录、不发奖）
 * @return array{ok:bool,msg:string,points:int,streak:int,total:int,max_streak:int}
 */
function owSHCSign(int $uid, string $day, string $kind = 'normal', bool $reward = true): array
{
    owSHCBoot();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) return ['ok' => false, 'msg' => '日期格式不正确'];
    if (owSHCSigned($uid, $day)) return ['ok' => false, 'msg' => '这一天已经签到过了'];

    $u = owSHCUser($uid);
    if (!$u) return ['ok' => false, 'msg' => '用户不存在'];

    // 连续判定：只认「紧接在最近一次签到之后的那一天」，其余一律从 1 重新起算。
    // （补签正是靠这条规则生效 —— 把断掉的那天补上，后续天数自然接得回来。）
    $prev  = (string)$u['last_day'];
    $yday  = date('Y-m-d', strtotime($day) - 86400);
    $strk  = ($prev !== '' && $prev === $yday) ? (int)$u['streak'] + 1 : 1;

    $parts = $reward ? owSHCRewardParts($strk) : ['base' => 0, 'step' => 0, 'bonus' => 0, 'total' => 0];
    $gain  = (int)$parts['total'];
    $now   = time();

    try {
        DB::run('INSERT INTO plugin_checkin_log (user_id, day, points, streak, kind, created_at) VALUES (?,?,?,?,?,?)',
            [$uid, $day, $gain, $strk, $kind, $now]);
    } catch (Throwable $e) {
        // 唯一键冲突 = 并发下刚被签过；不视为成功，提示用户刷新
        return ['ok' => false, 'msg' => '这一天已经签到过了'];
    }

    $max = max((int)$u['max_streak'], $strk);
    DB::run('UPDATE plugin_checkin_user SET total_days=total_days+1, streak=?, max_streak=?,'
        . ' points=points+?, last_day=?, updated_at=? WHERE user_id=?',
        [$strk, $max, $gain, $day, $now, $uid]);
    if ($gain > 0) {
        DB::run('UPDATE users SET points=points+? WHERE id=?', [$gain, $uid]);
        // 到账通知（v1.1.5）：普通签到 / 补签 / 管理员代签三条入口都走这里，口径只写一份。
        // 补签是 reward=false（$gain=0）—— 没发钱就不该发通知。
        // 代签另用一条模板：钱不是他自己点出来的。
        $adminSign = ($kind === 'admin');
        Notice::push($uid, $adminSign ? 'shc_checkin_admin' : 'shc_checkin', $adminSign
            ? ['points' => $gain, 'day' => $day, 'unit' => ow_points_name()]
            : ['points' => $gain, 'streak' => $strk, 'unit' => ow_points_name()]);
    }

    return [
        'ok' => true, 'msg' => 'ok', 'points' => $gain, 'streak' => $strk,
        'total' => (int)$u['total_days'] + 1, 'max_streak' => $max, 'parts' => $parts,
    ];
}

/**
 * 补签目标日：紧邻「最近一次签到」之后的那一天。
 *
 * 为什么只允许补这一天：补签的语义是「把断掉的那天接回来」，只有紧邻的缺口补上
 * 才能让连续天数续上；补更早的日子对连续性没有帮助，只会把规则讲不清。
 * 若最近一次签到就是昨天或今天（没断），则没有可补的缺口。
 *
 * @return array{can:bool,day:string,reason:string}
 */
function owSHCPatchTarget(int $uid, array $u): array
{
    // ⚠️ 别用 `$none + ['reason' => …]` 拼返回值：数组 + 运算**保留左侧键**，
    //    左侧已经有 reason => '' 时右侧根本写不进去，于是前端永远拿到空原因。
    $no = function (string $why): array { return ['can' => false, 'day' => '', 'reason' => $why]; };
    if (owSHCInt('patch_cost') <= 0) return $no('站点未开启补签');
    $prev = (string)($u['last_day'] ?? '');
    if ($prev === '') return $no('还没有签到记录');
    $target = date('Y-m-d', strtotime($prev) + 86400);
    $today  = date('Y-m-d');
    if ($target >= $today) return $no('当前没有断签');           // 昨天已签或今天已签
    if (owSHCSigned($uid, $target)) return $no('那一天已经签到过了');

    // 每月次数上限（跨月自动重置）
    $mon = date('Y-m');
    $used = ((string)($u['patch_mon'] ?? '') === $mon) ? (int)($u['patch_used'] ?? 0) : 0;
    if ($used >= max(0, owSHCInt('patch_limit'))) return $no('本月补签次数已用完');
    return ['can' => true, 'day' => $target, 'reason' => ''];
}

/** 本月（或指定月）某人的签到日集合 */
function owSHCMonthDays(int $uid, string $month): array
{
    owSHCBoot();
    $out = [];
    foreach (DB::all('SELECT day FROM plugin_checkin_log WHERE user_id=? AND day>=? AND day<=?',
        [$uid, $month . '-01', $month . '-31']) as $r) {
        $out[(string)$r['day']] = 1;
    }
    return $out;
}

/** 我的积分余额（核心 users.points） */
function owSHCPoints(int $uid): int
{
    $v = DB::val('SELECT points FROM users WHERE id=?', [$uid]);
    return $v === null || $v === false ? 0 : (int)$v;
}

/**
 * 本月签到排行榜（天数相同的按 user_id 升序，保证排序稳定、翻页不跳）。
 * 头像由服务端算好下发 —— 前端自己拼 DiceBear 参数迟早与资料卡的口径漂移。
 */
function owSHCRank(string $month, int $limit): array
{
    owSHCBoot();
    $limit = max(1, min(50, $limit));
    $rows = DB::all(
        'SELECT user_id, COUNT(*) AS n FROM plugin_checkin_log
         WHERE day>=? AND day<=? GROUP BY user_id ORDER BY n DESC, user_id ASC LIMIT ' . $limit,
        [$month . '-01', $month . '-31']
    );
    if (!$rows) return [];
    $ids = array_map(fn($r) => (int)$r['user_id'], $rows);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $users = [];
    foreach (DB::all("SELECT id, nickname, avatar, avatar_type, avatar_style, avatar_seed, client_key, role
                      FROM users WHERE id IN ($ph)", $ids) as $u) {
        $users[(int)$u['id']] = $u;
    }
    $out = [];
    foreach ($rows as $r) {
        $uid = (int)$r['user_id'];
        $u = $users[$uid] ?? null;
        if (!$u) continue;                                  // 用户已删除：排行榜不再展示
        $out[] = [
            'uid' => $uid,
            'nickname' => (string)$u['nickname'],
            'role' => (string)$u['role'],
            // class_exists 防御：core/auth.php 早于 plugin.php 加载，但 CLI 诊断脚本
            // 可能只 require 了部分核心文件，直接调会在那里炸。
            'avatar' => class_exists('Auth') ? Auth::avatarUrlFor($u, (string)$uid) : '',
            'days' => (int)$r['n'],
        ];
    }
    return $out;
}

/* ============================ 前台路由（签到子系统） ============================ */

/** 守卫：必须是注册用户（游客没有 users 行，签到无从记起） */
function owSHCNeedUser(array $ctx): int
{
    if (($ctx['actor']['kind'] ?? '') !== 'user') {
        Api::json(['ok' => false, 'msg' => '签到仅对注册用户开放'], 403);
    }
    return (int)$ctx['actor']['id'];
}

/**
 * 签到面板数据（一次给全，避免前端连打几个接口）。
 * 游客也返回 ok —— 面板要显示「登录后才能签到」而不是一片空白。
 */

/** 执行签到 */
Plugin::route('plugin_starlight_hall_checkin_do', function (array $ctx) {
    $uid = owSHCNeedUser($ctx);
    if (owSHCCfg('enable') !== '1') Api::json(['ok' => false, 'msg' => '站点未开放签到']);
    $r = owSHCSign($uid, date('Y-m-d'), 'normal', true);
    if (!$r['ok']) Api::json(['ok' => false, 'msg' => $r['msg']]);

    $cyc = owSHCInt('cycle_days');
    $pn  = ow_points_name();
    $msg = '签到成功，获得 ' . (int)$r['points'] . ' ' . $pn;
    if ((int)$r['streak'] > 1) $msg .= '，已连续 ' . (int)$r['streak'] . ' 天';
    if ((int)$r['parts']['bonus'] > 0) $msg .= '（含连续 ' . $cyc . ' 天大奖 ' . (int)$r['parts']['bonus'] . ' ' . $pn . '）';

    /**
     * 每日签到同时给等级进度发「天」（v1.1.0：签到也是成长的一部分）。
     * 合并前签到与等级分属两个插件，签到只能发积分；合并后是一条成长线，
     * 签到自然也要往等级上走 —— 否则「每天打卡」和「每天登录做任务」毫无关系。
     * 只在 owSHCSign 成功之后走（(user_id, day) 唯一，天然每天一次），
     * 因此不需要额外的「今天是否已发」判断。
     * 配成 0 = 签到不发等级天数（退回合并前的行为）。
     */
    $lvDays = owSHLCheckinReward();
    if ($lvDays > 0) {
        $row = owSHLRow($uid);
        $t   = owSHLDecode((string)$row['today']);
        $row['task_days'] = (float)($row['task_days'] ?? 0) + $lvDays;
        $before = (int)$row['level'];
        owSHLRecalc($row);
        owSHLSave($uid, $row, $t);
        $msg .= '，等级 +' . owSHLNum($lvDays) . ' 天';
    }

    Api::json(['ok' => true, 'msg' => $msg, 'points' => (int)$r['points'],
               'streak' => (int)$r['streak'], 'total' => (int)$r['total'],
               // parts 供「签到成功」弹窗拆分展示（基础 / 递增 / 周期大奖 / 等级天数）
               'parts' => $r['parts'], 'lv_days' => $lvDays]);
});

/**
 * 补签：扣积分 + 补记录（不发奖励积分），把连续天数接回来。
 * 标为敏感操作：它扣的是用户自己的积分，走一次性票据可防请求重放。
 */
Plugin::route('plugin_starlight_hall_checkin_patch', function (array $ctx) {
    $uid = owSHCNeedUser($ctx);
    if (owSHCCfg('enable') !== '1') Api::json(['ok' => false, 'msg' => '站点未开放签到']);
    $u = owSHCUser($uid);
    $t = owSHCPatchTarget($uid, $u);
    if (!$t['can']) Api::json(['ok' => false, 'msg' => $t['reason'] !== '' ? $t['reason'] : '当前没有可补签的日期']);

    $cost = owSHCInt('patch_cost');
    if (owSHCPoints($uid) < $cost) Api::json(['ok' => false, 'msg' => ow_points_name() . '不足，补签需要 ' . $cost . ' ' . ow_points_name()]);

    $r = owSHCSign($uid, $t['day'], 'patch', false);
    if (!$r['ok']) Api::json(['ok' => false, 'msg' => $r['msg']]);

    DB::run('UPDATE users SET points=points-? WHERE id=?', [$cost, $uid]);
    $mon = date('Y-m');
    $used = ((string)$u['patch_mon'] === $mon) ? (int)$u['patch_used'] + 1 : 1;
    DB::run('UPDATE plugin_checkin_user SET patch_used=?, patch_mon=? WHERE user_id=?', [$used, $mon, $uid]);
    Sec::log('checkin_patch', (string)($ctx['actor']['nickname'] ?? ''), ['uid' => $uid, 'day' => $t['day'], 'cost' => $cost]);

    Api::json(['ok' => true, 'msg' => '已补签 ' . $t['day'] . '，连续天数恢复到 ' . (int)$r['streak'] . ' 天',
               'cost' => $cost, 'streak' => (int)$r['streak']]);
});
Plugin::sensitive('plugin_starlight_hall_checkin_patch');

/* ============================ 后台路由（签到子系统） ============================ */

/** 管理员鉴权（use 捕获的是变量值，必须先定义） */
$ciGuard = function (array $ctx): void {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
};

/** 后台概览：今日 / 本月 / 累计 + 近 14 天趋势 + 累计榜 */
Plugin::route('plugin_starlight_hall_checkin_stats', function (array $ctx) use ($ciGuard) {
    $ciGuard($ctx);
    owSHCBoot();
    $today = date('Y-m-d');
    $month = date('Y-m');

    $todayN   = (int)DB::val('SELECT COUNT(*) FROM plugin_checkin_log WHERE day=?', [$today]);
    $monthN   = (int)DB::val('SELECT COUNT(*) FROM plugin_checkin_log WHERE day>=? AND day<=?', [$month . '-01', $month . '-31']);
    $allN     = (int)DB::val('SELECT COUNT(*) FROM plugin_checkin_log');
    $points   = (int)DB::val('SELECT COALESCE(SUM(points),0) FROM plugin_checkin_log');
    $users    = (int)DB::val('SELECT COUNT(*) FROM plugin_checkin_user WHERE total_days>0');
    $avgStreak = (float)DB::val('SELECT COALESCE(AVG(streak),0) FROM plugin_checkin_user WHERE total_days>0');

    // 近 14 天逐日人数（补齐空白天，否则柱状图会在没数据的日子断开）
    $from = date('Y-m-d', time() - 13 * 86400);
    $got = [];
    foreach (DB::all('SELECT day, COUNT(*) AS n, COALESCE(SUM(points),0) AS p FROM plugin_checkin_log WHERE day>=? GROUP BY day', [$from]) as $r) {
        $got[(string)$r['day']] = ['n' => (int)$r['n'], 'p' => (int)$r['p']];
    }
    $trend = [];
    for ($i = 13; $i >= 0; $i--) {
        $d = date('Y-m-d', time() - $i * 86400);
        $trend[] = ['day' => $d, 'n' => (int)($got[$d]['n'] ?? 0), 'p' => (int)($got[$d]['p'] ?? 0)];
    }

    // 累计签到天数榜（全量，不是当月）。人数后台可配（top_size，默认 50；原来写死 10）——
    // 收敛到 1~500：填 0 会让这张卡永远空着，填天量会把后台首屏拖垮，都不该由输入框随口决定。
    $topSize = max(1, min(500, owSHCInt('top_size')));
    $top = [];
    foreach (DB::all('SELECT u.id, u.nickname, u.role, s.total_days, s.streak, s.max_streak, s.points
                      FROM plugin_checkin_user s JOIN users u ON u.id=s.user_id
                      ORDER BY s.total_days DESC, s.user_id ASC LIMIT ' . $topSize) as $r) {
        $top[] = ['uid' => (int)$r['id'], 'nickname' => (string)$r['nickname'], 'role' => (string)$r['role'],
                  'days' => (int)$r['total_days'], 'streak' => (int)$r['streak'],
                  'max_streak' => (int)$r['max_streak'], 'points' => (int)$r['points']];
    }

    Api::json(['ok' => true, 'today' => $todayN, 'month' => $monthN, 'all' => $allN,
               'points' => $points, 'users' => $users, 'avg_streak' => round($avgStreak, 1),
               'trend' => $trend, 'top' => $top]);
});

/** 读取全部参数（后台表单回填） */
Plugin::route('plugin_starlight_hall_checkin_cfg_get', function (array $ctx) use ($ciGuard) {
    $ciGuard($ctx);
    $cfg = [];
    foreach (owSHCDefaults() as $k => $def) $cfg[$k] = owSHCCfg($k);
    Api::json(['ok' => true, 'cfg' => $cfg]);
});

/** 保存参数（白名单 = owSHCDefaults 的键集合，前端塞别的键也落不了库） */
Plugin::route('plugin_starlight_hall_checkin_cfg_save', function (array $ctx) use ($ciGuard) {
    $ciGuard($ctx);
    $post = $ctx['post'];
    $saved = 0;
    foreach (array_keys(owSHCDefaults()) as $k) {
        if (!isset($post[$k])) continue;
        $v = trim((string)$post[$k]);
        // 数值统一收敛到 [0, 100000]：后台填负数会让「签到反而扣分」，填天量会打爆积分
        if (preg_match('/^-?\d+$/', $v)) {
            $n = (int)$v;
            if ($n < 0) $n = 0;
            if ($n > 100000) $n = 100000;
            $v = (string)$n;
        }
        owSHCSetCfg($k, $v);
        $saved++;
    }
    Sec::log('checkin_cfg_save', (string)($ctx['actor']['nickname'] ?? ''), ['items' => $saved]);
    Api::json(['ok' => true, 'msg' => '参数已保存（' . $saved . ' 项）']);
});
Plugin::sensitive('plugin_starlight_hall_checkin_cfg_save');

/** 查单个用户的签到档案（后台） */
Plugin::route('plugin_starlight_hall_checkin_user', function (array $ctx) use ($ciGuard) {
    $ciGuard($ctx);
    owSHCBoot();
    $kw = trim((string)($ctx['post']['kw'] ?? ''));
    if ($kw === '') Api::json(['ok' => false, 'msg' => '请填写用户 ID 或昵称关键词']);
    $row = ctype_digit($kw)
        ? DB::one('SELECT id, nickname, role, points FROM users WHERE id=?', [(int)$kw])
        : DB::one('SELECT id, nickname, role, points FROM users WHERE nickname LIKE ? ORDER BY id ASC LIMIT 1', ['%' . $kw . '%']);
    if (!$row) Api::json(['ok' => false, 'msg' => '没有找到匹配的用户']);

    $uid = (int)$row['id'];
    $u = owSHCUser($uid);
    $month = (string)($ctx['post']['month'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
    $recent = DB::all('SELECT day, points, streak, kind FROM plugin_checkin_log WHERE user_id=? ORDER BY day DESC LIMIT 30', [$uid]);

    Api::json(['ok' => true,
        'uid' => $uid, 'nickname' => (string)$row['nickname'], 'role' => (string)$row['role'],
        'points' => (int)$row['points'],
        'total_days' => (int)$u['total_days'], 'streak' => (int)$u['streak'],
        'max_streak' => (int)$u['max_streak'], 'earned' => (int)$u['points'],
        'last_day' => (string)$u['last_day'], 'signed_today' => owSHCSigned($uid, date('Y-m-d')) ? 1 : 0,
        'month' => $month, 'days' => owSHCMonthDays($uid, $month),
        'recent' => array_map(fn($r) => ['day' => (string)$r['day'], 'points' => (int)$r['points'],
                                         'streak' => (int)$r['streak'], 'kind' => (string)$r['kind']], $recent)]);
});

/** 管理员操作：代签 / 清零连续 */
Plugin::route('plugin_starlight_hall_checkin_user_op', function (array $ctx) use ($ciGuard) {
    $ciGuard($ctx);
    $uid = (int)($ctx['post']['uid'] ?? 0);
    $op  = (string)($ctx['post']['op'] ?? '');
    if ($uid <= 0) Api::json(['ok' => false, 'msg' => '请填写用户 ID']);
    if (!DB::one('SELECT id FROM users WHERE id=?', [$uid])) Api::json(['ok' => false, 'msg' => '用户不存在']);

    switch ($op) {
        case 'sign':        // 代签今天（按正常规则发积分）
            if (owSHCSigned($uid, date('Y-m-d'))) Api::json(['ok' => false, 'msg' => '该用户今天已经签到过了']);
            $r = owSHCSign($uid, date('Y-m-d'), 'admin', true);
            if (!$r['ok']) Api::json(['ok' => false, 'msg' => $r['msg']]);
            Sec::log('checkin_admin_sign', (string)($ctx['actor']['nickname'] ?? ''), ['uid' => $uid]);
            Api::json(['ok' => true, 'msg' => '已代签，发放 ' . (int)$r['points'] . ' ' . ow_points_name()]);

        case 'reset':       // 连续天数清零（不删历史记录，只把 streak 归零）
            DB::run('UPDATE plugin_checkin_user SET streak=0 WHERE user_id=?', [$uid]);
            Sec::log('checkin_admin_reset', (string)($ctx['actor']['nickname'] ?? ''), ['uid' => $uid]);
            Api::json(['ok' => true, 'msg' => '已清零该用户的连续天数']);

        default:
            Api::json(['ok' => false, 'msg' => '未知操作']);
    }
});
Plugin::sensitive('plugin_starlight_hall_checkin_user_op');

/* ============================ 面板聚合接口 ============================ */

/**
 * 等级荣誉榜数据（≥「荣誉标识」等级的用户，按等级降序取 TOP N）。
 * 消费方是签到面板在「本月签到榜」正下方的「等级荣誉榜」。
 * 对游客同样下发：荣誉名单本身是公开信息，游客看到也能理解这套体系是活的。
 *
 * 上榜人数**不另设配置**，由调用方传入签到自己的「前台榜单人数」（rank_size）——
 * 两个榜人数因此始终一致，后台也只有一个地方要改。
 * 排序取 level DESC, user_id ASC（等级相同时按 uid 升序，保证刷新不跳动）；
 * LIMIT 用 (int) 收敛后的字面量 —— 参数化 LIMIT 在部分驱动上不可用，
 * 与 owSHCRank 的处理一致。
 */
function owSHLHonorRank(int $n): array
{
    $minLv = max(2, owSHLInt('lv_honor'));
    $n = max(1, min(50, $n));
    owSHLBoot();
    $rows = DB::all('SELECT user_id, level FROM plugin_level_trust WHERE level>=? '
        . 'ORDER BY level DESC, user_id ASC LIMIT ' . $n, [$minLv]);
    $list = [];
    if ($rows) {
        $ids = array_map(fn($r) => (int)$r['user_id'], $rows);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $users = [];
        foreach (DB::all("SELECT id, nickname, avatar, avatar_type, avatar_style, avatar_seed, client_key, role
                          FROM users WHERE id IN ($ph)", $ids) as $u) {
            $users[(int)$u['id']] = $u;
        }
        foreach ($rows as $r) {
            $uid = (int)$r['user_id'];
            $u = $users[$uid] ?? null;
            if (!$u) continue;                     // 用户已删除：榜单不再展示
            $lv = (int)$r['level'];
            $list[] = [
                'uid' => $uid,
                'nickname' => (string)$u['nickname'],
                'role' => (string)$u['role'],
                // class_exists 防御：core/auth.php 早于 plugin.php 加载，CLI 诊断脚本
                // 可能只 require 了部分核心文件（同 owSHCRank）
                'avatar' => class_exists('Auth') ? Auth::avatarUrlFor($u, (string)$uid) : '',
                'level' => $lv,
                'stage_no' => owSHLStageNo($lv),
                'honor' => 1,                      // 榜内必然 ≥ lv_honor（门槛被调低时语义随之放宽）
            ];
        }
    }
    return ['min_level' => $minLv, 'count' => count($list), 'list' => $list];
}

/**
 * 星光殿堂面板数据（一次给全）。
 *
 * 合并前这里是三个接口：签到的 info、等级的 mine、以及荣誉榜 rank ——
 * 前台打开面板要连打三次，还各自带一份重复的月历/榜单。合成为一个插件之后
 * 没有理由继续拆：数据在同一份代码里，一次查询一次下发，前端渲染也不用再
 * 处理「三个请求哪个先回来」。
 *
 * 游客也返回 ok（不是 403）：面板要显示「登录后才能签到 / 看到你的等级」，
 * 同时把两个榜单照常展示出去 —— 让大家看到这个功能是活的。
 */
Plugin::route('plugin_starlight_hall_panel', function (array $ctx) {
    $on = owSHCCfg('enable') === '1';
    $today = date('Y-m-d');
    $month = (string)($ctx['post']['month'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');

    $rankSize = owSHCInt('rank_size');
    $base = [
        'ok' => true, 'enabled' => $on ? 1 : 0,
        'today' => $today, 'month' => $month,
        'cfg' => [
            'base' => owSHCInt('base'), 'step' => owSHCInt('step'), 'step_cap' => owSHCInt('step_cap'),
            'cycle_days' => owSHCInt('cycle_days'), 'cycle_bonus' => owSHCInt('cycle_bonus'),
            'patch_cost' => owSHCInt('patch_cost'), 'patch_limit' => owSHCInt('patch_limit'),
            // v1.1.0：签到的等级天数奖励也随面板下发，前端提示文案直接用，
            // 不必再为「签到到底加不加等级天数」另立一套取值。
            'rw_checkin' => owSHLCheckinReward(),
        ],
        'rank' => owSHCRank($month, $rankSize),
        'honor' => owSHLHonorRank($rankSize),
    ];

    if (($ctx['actor']['kind'] ?? '') !== 'user') {
        Api::json($base + ['guest' => 1]);
    }

    $uid = (int)$ctx['actor']['id'];

    /* ---------- 等级档案（原 plugin_level_trust_mine） ---------- */
    [$row, $t] = owSHLTouch($uid);
    $stage = owSHLStage((int)$row['level']);
    $level = [
        'level' => (int)$row['level'],
        'stage' => $stage['name'], 'stage_no' => owSHLStageNo((int)$row['level']),
        'honor' => (int)$row['level'] >= owSHLInt('lv_honor') ? 1 : 0,
        'login_streak' => (int)$row['login_streak'], 'login_days' => (int)$row['login_days'],
        'settled' => (int)$row['settled'],
        'frozen' => (int)$row['frozen_day'] === (int)date('Ymd') ? 1 : 0,
        'tasks' => owSHLTasksToday($row, $t),
        'unlocks' => owSHLUnlocks((int)$row['level']),
        // 等级来源拆开下发（在线 / 任务 / 管理员设定 − 消退），前端不必自己换算
        'prog' => owSHLSnapshot($row),
    ];

    /* ---------- 签到档案（原 plugin_daily_checkin_info） ---------- */
    $u = owSHCUser($uid);
    $signed = owSHCSigned($uid, $today);
    $yday   = date('Y-m-d', strtotime($today) - 86400);
    $prev   = (string)$u['last_day'];

    /**
     * 「当前连续天数」必须**现算**，不能直接用表里存的 streak。
     * 那条值是「上次签到那一刻」的连续数，用户昨天没来、今天也没签时它仍是旧值 ——
     * 直接展示就成了「断了三天还显示连续 12 天」。
     * 只有「最近一次签到是今天或昨天」才算数（昨天签了、今天还没签，连续仍有效，
     * 今天签到即可续上；再拖一天才真正断掉）。
     */
    $live = ($prev === $today || $prev === $yday) ? (int)$u['streak'] : 0;

    // 「下一次签到后」的天数：已签则是明天签完的天数，未签则是今天签完的天数。
    // 周期倒计时按它算，用户看到的是「再签 N 天拿大奖」而不是已经过去的天数。
    $after = $live + 1;
    $next  = owSHCRewardParts($after);
    $cyc   = max(0, owSHCInt('cycle_days'));
    $mod   = $cyc > 0 ? ($after % $cyc) : 0;
    $cycleLeft = ($cyc <= 0) ? 0 : ($mod === 0 ? 0 : $cyc - $mod);
    // 今天实际拿到多少（已签到时展示本次实发，未签时展示「可领」）
    $gotToday = $signed ? (int)DB::val('SELECT COALESCE(points,0) FROM plugin_checkin_log WHERE user_id=? AND day=?', [$uid, $today]) : 0;

    $pt = owSHCPatchTarget($uid, $u);
    $mon = date('Y-m');
    $patchLeft = ((string)($u['patch_mon'] ?? '') === $mon)
        ? max(0, owSHCInt('patch_limit') - (int)$u['patch_used']) : owSHCInt('patch_limit');

    Api::json($base + [
        'guest' => 0,
        'signed' => $signed ? 1 : 0,
        'streak' => $live,                       // 现算的有效连续（断签后自然归零）
        'streak_saved' => (int)$u['streak'],     // 表里存的快照，后台排查用
        'max_streak' => (int)$u['max_streak'],
        'total_days' => (int)$u['total_days'],
        'earned' => (int)$u['points'],
        'my_points' => owSHCPoints($uid),
        'next' => $next,                         // 下一次签到可领（含明细）
        'got' => $gotToday,                      // 今天已实发（未签为 0）
        'cycle_left' => $cycleLeft,
        'patch' => ['can' => $pt['can'] ? 1 : 0, 'day' => $pt['day'], 'reason' => $pt['reason'],
                    'cost' => owSHCInt('patch_cost'), 'left' => $patchLeft],
        'days' => owSHCMonthDays($uid, $month),
        'level' => $level,
    ]);
});

/* ============================ 前台入口（rail） ============================ */

/**
 * rail 入口图标（插件自有字形）—— 日历 + 对勾，即「每日打卡」。
 *
 * 为什么不用核心 ow_icon()：它是固定的线性图标表，日历勾不在其中；PLUGIN.md 明确
 * 「插件自有字形可以内联 <svg>，但那份 path 只能存在于 main.php 一处」——
 * 所以这里只此一份，chat.js 的弹窗要用同一枚图形时直接克隆 rail 里渲染好的 SVG，
 * 不在 JS 里再抄一遍 path。
 *
 * 图形是站方指定版（原稿 viewBox 1024、双路径、纯填充）。适配到本项目做了两件事：
 *   ① fill 从写死的 #333333 改成 currentColor —— rail 选中态的前景是白色，
 *      自动跟随，不需要为深/浅两套主题各配一份；
 *   ② 保留 1024 的 viewBox 不动几何（缩放到 20px 由 width/height 决定）。
 */
function owSHRailIcon(int $size = 20): string
{
    // 圆角外框（原稿把这一圈描边拆成了闭合填充路径，保持原样）
    $frame = 'M 879.646 245.609 v 599.999 h -728.57 V 245.609 h 728.57 m 0 -85.714 h -728.57'
        . ' c -47.338 0 -85.716 38.377 -85.716 85.715 v 599.999 c 0 47.338 38.378 85.715 85.716 85.715'
        . ' h 728.57 c 47.338 0 85.715 -38.377 85.715 -85.715 v -600'
        . ' c -0.001 -47.338 -38.378 -85.714 -85.715 -85.714 Z';
    // 中间那道勾 + 上方两枚挂钉
    $check = 'M 482.506 755.331 h -0.021 c -16.541 0 -32.059 -6.495 -43.695 -18.276 L 306.62 603.377'
        . ' c -16.637 -16.835 -16.479 -43.967 0.348 -60.613 c 16.822 -16.625 43.954 -16.479 60.609 0.343'
        . ' l 114.93 116.248 l 220.668 -223.211 c 16.646 -16.813 43.778 -16.972 60.614 -0.348'
        . ' c 16.826 16.646 16.98 43.778 0.347 60.614 L 526.2 737.076'
        . ' c -11.644 11.778 -27.161 18.255 -43.694 18.255 Z'
        . ' M 707.145 318.818 c -23.669 0 -42.86 -19.187 -42.86 -42.855 V 104.532'
        . ' c 0 -23.665 19.191 -42.855 42.86 -42.855 c 23.664 0 42.855 19.19 42.855 42.855'
        . ' v 171.431 c 0 23.669 -19.191 42.855 -42.855 42.855 Z'
        . ' M 318.211 318.818 c -23.67 0 -42.855 -19.187 -42.855 -42.855 V 104.532'
        . ' c 0 -23.665 19.186 -42.855 42.855 -42.855 c 23.668 0 42.855 19.19 42.855 42.855'
        . ' v 171.431 c 0 23.669 -19.187 42.855 -42.855 42.855 Z';
    return '<svg class="ow-ico" width="' . $size . '" height="' . $size . '" viewBox="0 0 1024 1024"'
        . ' fill="currentColor" stroke="none" aria-hidden="true">'
        . '<path d="' . $frame . '"/><path d="' . $check . '"/></svg>';
}

/**
 * 左侧一级导航条的「星光殿堂」入口。
 * data-tab 必须与前端 OwChat.onRail({id}) 一致（这里都是 hall）。
 * <span class="ow-rail-lb"> 桌面隐藏、窄屏 rail 变底部标签栏时显示 —— 与核心入口一致。
 */
Plugin::on('sidebar.rail', function () {
    return '<button class="ow-rail-btn" data-tab="hall" type="button" title="星光殿堂" aria-label="星光殿堂">'
        . owSHRailIcon(20) . '<span class="ow-rail-lb">殿堂</span></button>';
});

/* ============================ 后台页 ============================ */

Plugin::adminPage('starlight-hall', '星光殿堂', function () {
    /**
     * 一个参数输入项。说明文字写成无 class、12px 的 <p>，主程序 OwTip.scan() 会
     * 自动把它收进 label 右侧的 ⓘ 气泡（PLUGIN.md「表单的提示与说明一律用 ow-tip」），
     * 所以页面上可见文字只留标签本身，细节全进气泡。
     */
    $f = function (string $prefix, string $k, string $label, string $hint = '', $val = null) {
        if ($val === null) $val = $prefix === 'ci' ? owSHCCfg($k) : owSHLCfg($k);
        return '<div class="ow-form-item"><label>' . Sec::e($label) . '</label>'
            . '<input class="ow-input" id="owShCfg_' . $prefix . '_' . $k . '" value="' . Sec::e((string)$val) . '">'
            . ($hint !== '' ? '<p style="font-size:12px">' . Sec::e($hint) . '</p>' : '')
            . '</div>';
    };

    // v1.5.4：本页文案里的「积分」都换成站点自定义名称（核心 系统设置 → 基本信息）
    $pn = ow_points_name();

    $h = '<h2>星光殿堂</h2>'
        . '<p class="ow-admin-desc">签到与等级合体的成长中心：每天签到攒' . Sec::e($pn) . '与连续天数，'
        . '完成任务攒等级天数 —— 等级 <b>L = 1 + ⌊ 在线天 + 任务天 + 设定天 − 消退天 ⌋</b>，'
        . '1 天 = 1 级，等级再反过来解锁头像 / 贴纸 / 加好友 / 建群 / 文件等能力。'
        . '下面是四张页签：概览看整体活跃，签到与等级各自配参数，用户把两边的档案合在一张卡里查。</p>';

    /* ---------- 页签（v1.1.3：外包白色衬底） ---------- */
    /* 为什么衬白底：管理后台的页面底色是 --ow-bg-sub（#F5F5F5，body 全局底色），
       与未选中页签的灰底同色 —— 不衬一块白底，那几颗灰页签「消失」在页面里
       （用户原话「增加白色块，把灰色块展示出来」）。与 chat-scripts 后台页签
       同一处理（.ow-cs-tabs-bar）；前台面板没有页签，不受影响。 */
    $h .= '<div class="ow-sh-tabs-bar"><div class="ow-sh-tabs" id="owShTabs">'
        . '<button type="button" class="ow-sh-tab is-active" data-p="overview" onclick="OwSHAdmin.tab(\'overview\')">概览</button>'
        . '<button type="button" class="ow-sh-tab" data-p="checkin" onclick="OwSHAdmin.tab(\'checkin\')">签到</button>'
        . '<button type="button" class="ow-sh-tab" data-p="level" onclick="OwSHAdmin.tab(\'level\')">等级</button>'
        . '<button type="button" class="ow-sh-tab" data-p="user" onclick="OwSHAdmin.tab(\'user\')">用户</button>'
        . '</div></div>';

    /* ---------- ① 概览 ---------- */
    $h .= '<div class="ow-sh-pane is-active" data-p="overview">'
        . '<div class="ow-card" id="owShLvStats"></div>'
        . '<div class="ow-card"><h3 class="ow-sh-h3">活跃趋势</h3><div id="owShLvTrend"></div></div>'
        . '</div>';

    /* ---------- ② 签到 ---------- */
    $h .= '<div class="ow-sh-pane" data-p="checkin">'
        . '<div class="ow-card" id="owShCiStats"></div>'
        . '<div class="ow-card"><h3 class="ow-sh-h3">近 14 天签到人数</h3><div id="owShCiTrend"></div></div>'
        . '<div class="ow-card"><h3 class="ow-sh-h3">累计签到榜</h3><div id="owShCiTop"></div></div>'
        . '<div class="ow-card"><h3 class="ow-sh-h3">签到规则</h3><div class="ow-form-row">'
        . '<div class="ow-form-item" style="flex:1"><label>签到功能</label>'
        . '<select class="ow-input" id="owShCfg_ci_enable">'
        . '<option value="1"' . (owSHCCfg('enable') === '1' ? ' selected' : '') . '>开启</option>'
        . '<option value="0"' . (owSHCCfg('enable') === '0' ? ' selected' : '') . '>关闭（前台显示「未开放」）</option>'
        . '</select></div>'
        . $f('ci', 'base', '基础' . $pn, '每次签到都发的保底数')
        . $f('ci', 'step', '连续递增（' . $pn . '/天）', '每多连一天额外增加')
        . $f('ci', 'step_cap', '递增封顶（' . $pn . '）', '额外部分最多累加到该数值')
        . $f('ci', 'cycle_days', '大奖周期（天）', '连续满 N 天发一笔大奖；填 0 = 不发')
        . $f('ci', 'cycle_bonus', '周期大奖（' . $pn . '）')
        . $f('ci', 'patch_cost', '补签卡单价（' . $pn . '）', '填 0 = 不提供补签')
        . $f('ci', 'patch_limit', '每月补签上限（次）')
        . $f('ci', 'rank_size', '前台榜单人数', '「本月签到榜」与「等级荣誉榜」共用这个人数，两个榜始终一致')
        . $f('ci', 'top_size', '后台累计榜人数', '后台「累计签到榜」显示多少人（1~500）；前台月榜用的是上一项')
        . '</div>'
        . '<div class="ow-sh-actions"><button class="ow-btn ow-btn-primary" onclick="OwSHAdmin.saveCheckin()">保存签到参数</button></div>'
        . '</div></div>';

    /* ---------- ③ 等级 ---------- */
    $h .= '<div class="ow-sh-pane" data-p="level">'
        . '<div class="ow-card"><h3 class="ow-sh-h3">总开关</h3><div class="ow-form-row">'
        . '<div class="ow-form-item" style="flex:1"><label>等级限制</label>'
        . '<select class="ow-input" id="owShCfg_lv_gating">'
        . '<option value="1"' . (owSHLCfg('gating') === '1' ? ' selected' : '') . '>开启（按下面各项限制功能）</option>'
        . '<option value="0"' . (owSHLCfg('gating') === '0' ? ' selected' : '') . '>关闭（等级只展示，不做任何限制）</option>'
        . '</select></div></div></div>'

        . '<div class="ow-card"><h3 class="ow-sh-h3">等级怎么涨</h3><div class="ow-form-row">'
        . $f('lv', 'online_hours', '在线多少小时算 1 天', '累计在线每满这么多小时 = +1 天 = +1 级；默认 24。页面开着就算在线，单次最多记 2 分钟')
        . $f('lv', 'rw_login', '「登录」任务奖励（天）', '完成一次奖励多少天，默认 0.2；每个任务每天只发一次')
        . $f('lv', 'rw_gm', '「群发言」任务奖励（天）', '完成一次奖励多少天，默认 0.5。两个都填 1 就是「每天做完任务升 2 级」；填 0 = 该任务只记进度不发奖励')
        . $f('lv', 'rw_checkin', '「每日签到」奖励（天）', '每天签到一次加多少等级天数，默认 0.2。填 0 = 签到只发' . $pn . '、不涨等级（合并前的行为）')
        . '</div>'
        . '<h4 class="ow-sh-cap">长期不登录消退（荣誉等级）</h4><div class="ow-form-row">'
        . $f('lv', 'decay_idle_days', '长期不登录天数', '每满这么多天不登录退 1 级；填 0 = 关闭消退')
        . $f('lv', 'decay_from', '从该等级起消退（Lv）', '低于此等级的用户不受影响；默认 60')
        . $f('lv', 'decay_floor', '最低退到（Lv）', '最多退到该等级为止；默认 46。会被自动夹到低于「从该等级起消退」，配置写反也不会一次压到底')
        . '</div>'
        . '</div>'

        . '<div class="ow-card"><h3 class="ow-sh-h3">能力解锁等级</h3><div class="ow-form-row">'
        . $f('lv', 'lv_file', '可上传文件（Lv）', '低于此级禁止上传图片/文件')
        . $f('lv', 'lv_avatar', '自定义头像（Lv）')
        . $f('lv', 'lv_friend', '添加好友（Lv）')
        . $f('lv', 'lv_file_half', '单文件 1/2 上限（Lv）')
        . $f('lv', 'lv_file_full', '单文件满额上限（Lv）')
        . $f('lv', 'lv_sticker', '上传贴纸（Lv）')
        . $f('lv', 'lv_mod_cand', '管理候选资格（Lv）', '仅作标记，不实际授予权限')
        . $f('lv', 'lv_honor', '荣誉标识（Lv）', '达到后显示荣誉徽章，同时也是「等级荣誉榜」的上榜门槛')
        . '</div></div>'

        . '<div class="ow-card"><h3 class="ow-sh-h3">建群名额与' . Sec::e($pn) . '</h3><div class="ow-form-row">'
        . $f('lv', 'room_min_level', '免费建群最低等级（Lv）', '低于此级无免费名额，但可用' . $pn . '创建')
        . $f('lv', 'room_point_cost', '超额创建单价（' . $pn . '）', '每个群扣一次；填 0 = 不允许用' . $pn . '绕过')
        . '<div class="ow-form-item" style="flex:1;min-width:220px"><label>免费名额档位</label>'
        . '<textarea class="ow-input ow-sh-mono" id="owShCfg_lv_room_tiers" rows="3">' . Sec::e(owSHLCfg('room_tiers')) . '</textarea>'
        . '<p style="font-size:12px">每行一条「等级:名额」，取最高命中档。默认 10:3 / 20:5 / 50:8</p>'
        . '</div>'
        . '<div class="ow-form-item" style="flex:1;min-width:220px"><label>贴纸收藏上限档位</label>'
        . '<textarea class="ow-input ow-sh-mono" id="owShCfg_lv_sticker_tiers" rows="3">' . Sec::e(owSHLCfg('sticker_tiers')) . '</textarea>'
        . '<p style="font-size:12px">每行一条「等级:张数」，取最高命中档；未达首档 = 未解锁。'
        . '默认 3:3 / 6:10 / 10:30 / 20:100 / 30:200 / 40:400 / 50:700 / 60:999</p>'
        . '</div></div></div>'

        . '<div class="ow-card"><h3 class="ow-sh-h3">阶段阈值与任务量</h3><div class="ow-form-row">'
        . $f('lv', 'stage_2', '日常阶段起始（Lv）')
        . $f('lv', 'stage_3', '活跃阶段起始（Lv）')
        . $f('lv', 'stage_4', '核心阶段起始（Lv）')
        . $f('lv', 'stage_5', '荣誉阶段起始（Lv）')
        . $f('lv', 'stage_gm', '各阶段群发言条数', '逗号分隔五档：新手,日常,活跃,核心,荣誉。当前任务奖励：' . owSHLRewardText())
        . '</div></div>'

        . '<div class="ow-card"><h3 class="ow-sh-h3">有效发言判定</h3><div class="ow-form-row">'
        . $f('lv', 'msg_min_len', '内容最少字数')
        . $f('lv', 'msg_cooldown', '计入冷却（秒）')
        . $f('lv', 'msg_per_minute', '每分钟最多计入（条）')
        . $f('lv', 'msg_dedupe', '与最近 N 条去重')
        . '</div>'
        . '<div class="ow-sh-actions"><button class="ow-btn ow-btn-primary" onclick="OwSHAdmin.saveLevel()">保存等级参数</button></div>'
        . '</div></div>';

    /* ---------- ④ 用户（两边档案合卡） ---------- */
    $h .= '<div class="ow-sh-pane" data-p="user">'
        . '<div class="ow-card"><h3 class="ow-sh-h3">用户档案</h3><div class="ow-form-row">'
        . '<div class="ow-form-item" style="min-width:180px"><label>用户 ID 或昵称</label>'
        . '<input class="ow-input" id="owShKw" placeholder="如 1 或 小明"'
        . ' onkeydown="if(event.key===\'Enter\')OwSHAdmin.query()"></div>'
        . '<button class="ow-btn ow-btn-primary" onclick="OwSHAdmin.query()">查询</button>'
        . '</div>'
        . '<p class="ow-admin-desc" style="margin:10px 0 0">一次查询同时给出「等级档案」与「签到档案」——'
        . '这是把两个插件并成一个之后最直接的收益：不用再在两个后台页之间对着同一个用户来回看。</p>'
        . '<div id="owShUser"></div></div>'
        . '</div>';

    return $h;
});

/* ============================ 资源 ============================ */

Plugin::asset('css', 'starlight-hall/style.css');
Plugin::asset('js', 'starlight-hall/chat.js');
Plugin::asset('js', 'starlight-hall/admin.js');
