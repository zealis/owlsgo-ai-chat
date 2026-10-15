<?php
/**
 * 等级信任插件（v2.0.0 公式重制）
 *
 * 规则出处：设计文档/等级设计.md（该文档描述的是 v1.x 旧公式，v2.0 起以本文件注释为准）
 *
 * ── 等级公式（v3.1：在线时间 + 每日任务 − 长期不登录消退）──────
 *   L = 1 + ⌊ 在线天 + 任务天 + 设定天 − 消退天 ⌋
 *     在线天数 = 累计在线秒 ÷ (online_hours × 3600)，默认 online_hours=24
 *                —— 「总共在线满一天就加一级」
 *     任务天数 = Σ 已完成任务的奖励（rw_<任务key>，后台逐任务可配，默认各 0.5 天）
 *                —— 「完成每日任务，每一个任务加多少天」；每个任务每天最多发一次
 *     设定天数 = 管理员直设等级时自动补的差额（见 owLTAlignLevel）
 *     消退天数 = 荣誉等级（默认 ≥ 60 级）长期不登录的扣减（见 owLTCronDecay）
 *   「天」是唯一单位：1 天 = 1 级。前四项相加后取整。
 *   不设满级。未完成任务不降级：等级只取 max(旧等级, 公式值)——
 *   **消退是这条规则唯一的例外**，它显式把等级按公式值往下压（见 owLTCronDecay）。
 *
 * ⚠️ 消退为什么必须是公式里的一个「负项」，而不是直接改小 level：
 *    等级每次重算都走 max(旧等级, 公式值)（「只升不降」）。只把 level 改小的话，
 *    用户下一次活跃一重算就被 max() 顶回原样，等于没退。把扣减记进公式，
 *    max() 拿到的公式值本身就是降过的，两者一致，才真退得下去。
 *    副作用正好是站方要的「不退不补」：消退天是固定扣着的，用户回来继续活跃时
 *    靠新攒的在线/任务天自然把等级顶回来，不需要任何「恢复」逻辑。
 *
 *   v2.0 旧公式（已废弃）：L = 1 + ⌊D × lv_step⌋，D = 累计完成每日任务的天数。
 *     v1.x 更早的公式（已废弃）：L = 1 + ⌊D + min(W, 0.5D) × 追赶系数⌋。
 *     days / weight / file_w_days 三列保留不删（存量数据），v3.0 起不再读写。
 *
 *   ⚠️ 为什么要单列「设定天数」而不是把差额塞进任务天数（v1.3.150 的教训）：
 *     等级和「天数」是两个可以各自独立写入的量（后台用户管理那个等级输入框只写
 *     level、不动天数），一旦脱钩，页面就会同时显示「Lv.30」和「累计 4 天」，
 *     而页面自己写着 L = 1 + ⌊天数⌋ —— 整页自相矛盾。把差额显式记成「管理员设定」，
 *     等级页就能如实拆开展示，而不是拿假的任务天数糊过去。
 *
 * ── 在线时长怎么来的（v3.0）────────────────────────────────
 *   钩子用 index.php 的 `user.active`（每个请求都触发，含 20 秒长轮询）。
 *   每次请求把「now − 上次请求时间」累加进 online_sec，**单次封顶 120 秒**：
 *   关掉页面几小时后再回来，那几小时不会一次补上（页面没开着就不算在线）。
 *   「页面开着就算在线」是站方定案 —— 所以挂机也算，这是本方案已知的代价。
 *   ⚠️ 封顶只能挡「离开很久再回来一次性补齐」，挡不住「脚本掐着点定时上报」
 *      （每 2 分钟请求一次就能拿满 120 秒）—— 后者与挂机等价，无法在服务端区分。
 *
 * ── 每日任务（v3.0：逐任务各自发奖）──────────────────────────
 *   全部阶段统一为：登录 + 群发言 N 条（N 见后台 stage_gm，默认 1/3/5/5/10）
 *   v3.0 起**每个任务各配一个奖励天数**（rw_login / rw_gm），完成即发、每天各一次，
 *   不再要求「当天全部完成才 +1 天」—— 站方口径是「每一个任务加多少天」。
 *   v2.0 的「替代任务组」（上传↔私聊首条）与「挑战任务」更早已取消。
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
 *   - `user.active` 每请求都会触发（含长轮询）：**同日内仍是「一次主键查询」起步**，
 *     但 v3.0 起每次请求都要写一次库（在线时长就是靠每次请求的差值攒的），
 *     写入频率 = 该用户的轮询频率（≤20 秒一次），差值 <5 秒的请求跳过不写。
 *     跨天才做迁移、发登录奖励与结算。
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
    // ⚠️ 'schema' 刻意**不写进 owLTDefaults()** —— 若写进默认值，升级上来的老站点
    //    会从默认值读到最新版号而**跳过迁移**（老站点的配置表里本来就没有这一行）。
    // ⚠️ **加列必须同步把这里的版本号 +1**：v3.0 只写到 '3'，站点（含运行副本）跑过一次
    //    之后永远进不来，新列就永远建不出来 —— v3.1 加消退两列时升到 '4'。
    if (owLTCfg('schema') !== '4') {
        $fromV2 = owLTCfg('schema') === '';     // 空 = 从没迁过（v2 表结构 / 全新安装）
        DB::ensureColumn('plugin_level_trust', 'online_sec', 'int', '0');
        DB::ensureColumn('plugin_level_trust', 'task_days', 'real', '0');
        DB::ensureColumn('plugin_level_trust', 'bonus_days', 'real', '0');
        DB::ensureColumn('plugin_level_trust', 'hb_at', 'int', '0');
        DB::ensureColumn('plugin_level_trust', 'decay_days', 'real', '0');
        DB::ensureColumn('plugin_level_trust', 'decay_base', 'int', '0');
        // ⚠️ 折算只能跑一次：已迁到 '3' 的站点再跑一遍会把 task_days 覆盖成
        //    「旧 days × lv_step」，用户新攒的任务天全部作废。
        if ($fromV2) owLTSeedV3();
        owLTSetCfg('schema', '4');
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
function owLTSeedV3(): void
{
    $step = (float)owLTCfg('lv_step');
    if ($step < 1) $step = 1.3;                 // v2.0 的默认倍率；配置被清空时兜底
    foreach (DB::all('SELECT * FROM plugin_level_trust') as $r) {
        $uid = (int)($r['user_id'] ?? 0);
        if ($uid <= 0) continue;
        $row = $r;
        $row['task_days']  = round((int)($r['days'] ?? 0) * $step, 4);
        $row['bonus_days'] = 0.0;
        owLTAlignLevel($row, (int)($r['level'] ?? 1));   // 差额进 bonus_days，只增不减
        DB::run('UPDATE plugin_level_trust SET task_days=?, bonus_days=? WHERE user_id=?',
            [(float)$row['task_days'], (float)$row['bonus_days'], $uid]);
    }
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
 * 全部可调参数与默认值（v3.0：在线折算小时数 / 逐任务奖励天数 / 解锁等级 / 建群名额 /
 * 有效发言 / 阶段阈值全部可在后台「等级信任 → 参数设置」里改）。
 *
 * ⚠️ 每加一个新键，必须同时：
 *   ① 在这里给默认值；② 在后台表单里给输入框；③ 在 cfg_save 的白名单里放行
 *      （白名单就是这个数组的键集合，所以 ① 做了 ③ 就自动成立）。
 * 漏了输入框的表现是「值存在但后台看不到、改不了」，很难查。
 */
function owLTDefaults(): array
{
    return [
        // ---- 总开关 ----
        'gating'        => '1',     // 是否按等级限制功能（0=只看等级不做限制）
        // ---- 等级速度（v3.0：L = 1 + ⌊ 在线天 + 任务天 + 设定天 − 消退天 ⌋）----
        'online_hours'  => '24',    // 在线满 N 小时算 1 天（= 1 级）；<1 会被兜底成 24
        'rw_login'      => '0.5',   // 「登录」任务完成一次奖励多少天
        'rw_gm'         => '0.5',   // 「群发言」任务完成一次奖励多少天
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
 * 「在线多少小时 = 1 天」（后台可配，默认 24）。
 * ⚠️ 兜底 ≥1：被调成 0 会让 owLTTotalDays 除零、全体瞬间满级。
 */
function owLTOnlineHours(): float
{
    $h = owLTFloat('online_hours');
    return $h >= 1 ? $h : 24.0;
}

/**
 * 三项合计的「天」数 —— 等级公式里 floor 之前的那个值。
 * 在线秒 / 任务奖励 / 管理员设定 相加，再**减去**长期不登录的消退扣减，单位统一是「天」，1 天 = 1 级。
 */
function owLTTotalDays(array $row): float
{
    return (float)($row['online_sec'] ?? 0) / (owLTOnlineHours() * 3600)
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
function owLTDecayCfg(): array
{
    $idle  = owLTFloat('decay_idle_days');
    $from  = max(2, owLTInt('decay_from'));
    $floor = max(1, owLTInt('decay_floor'));
    if ($floor >= $from) $floor = $from - 1;
    return [$idle, $from, $floor];
}

/** 等级公式（v3.0）：L = 1 + ⌊ 总天数 ⌋ */
function owLTLevelFromTotal(float $total): int
{
    return max(1, 1 + (int)floor($total));
}

/**
 * 按公式重算等级并写回 $row。
 * ⚠️ 取 max(旧等级, 公式值)：未完成任务不降级；后台直设过的等级不会被公式压回去。
 *    所有改等级的地方（任务发奖、补签、后台操作）都必须走这里，别各处自己算一遍。
 */
function owLTRecalc(array &$row): void
{
    $row['level'] = max((int)($row['level'] ?? 1), owLTLevelFromTotal(owLTTotalDays($row)));
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
function owLTAlignLevel(array &$row, int $level): void
{
    $level = max(1, $level);
    $row['level'] = $level;
    $total = owLTTotalDays($row);
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
function owLTSnapshot(array $row): array
{
    $total = owLTTotalDays($row);
    $lv    = max(1, (int)($row['level'] ?? 1));
    $oh    = owLTOnlineHours();
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

/**
 * 写回等级行。
 * ⚠️ days / weight / file_w_days 是 v1.x~v2.0 的遗留列，v3.0 起**不从 $row 回写**
 *    （只在建行时留默认值），免得把历史值搬来搬去反而看不清楚它们已经不用了。
 */
function owLTSave(int $uid, array $row, array $t): void
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
function owLTAccumOnline(int $uid, array &$row): bool
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
function owLTReward(string $taskKey): float
{
    $map = owLTDefaults();
    $key = 'rw_' . $taskKey;
    if (!isset($map[$key])) return 0.0;        // 没有这个任务就没有奖励（新增任务要先加配置键）
    $v = (float)owLTCfg($key);
    return $v > 0 ? $v : 0.0;
}

/** 当前任务表里所有任务的奖励天数（键 => 天），供后台与等级页展示 */
function owLTRewardTable(): array
{
    $out = [];
    foreach (owLTStage(1)['base'] as $task) $out[$task['k']] = owLTReward($task['k']);
    return $out;
}

/** 奖励的中文清单（「登录 0.5 天 + 群发言 0.5 天」）—— 等级页与后台共用，避免两处各写一份 */
function owLTRewardText(): string
{
    $parts = [];
    foreach (owLTRewardTable() as $k => $d) {
        if ($d <= 0) continue;
        $parts[] = owLTTaskLabel((string)$k) . ' ' . owLTNum($d) . ' 天';
    }
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
function owLTPayTasks(array &$row, array &$t): float
{
    $paid = is_array($t['paid'] ?? null) ? $t['paid'] : [];
    $add  = 0.0;
    foreach (owLTStage((int)$row['level'])['base'] as $task) {
        $k = (string)$task['k'];
        if (!empty($paid[$k])) continue;                  // 今天这个任务已经发过了
        if (!owLTTaskOk($task, $t)) continue;             // 还没达标
        $paid[$k] = 1;
        $add += owLTReward($k);
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
 * 今日任务清单（带进度、奖励与是否已发奖）—— mine 接口 / 后台查询 / 等级页共用。
 * 三处各写一份的话，加一个字段就会漏掉其中一处（v2.0 的 tasks 与等级页就分了两份）。
 */
function owLTTasksToday(array $row, array $t): array
{
    $paid = is_array($t['paid'] ?? null) ? $t['paid'] : [];
    $out  = [];
    foreach (owLTStage((int)$row['level'])['base'] as $task) {
        $k = (string)$task['k'];
        $out[] = [
            'k' => $k, 'label' => owLTTaskLabel($k),
            'n' => (int)$task['n'], 'cur' => owLTProg($t, $k),
            'rw' => owLTReward($k),                       // 完成一次奖励多少天
            'paid' => !empty($paid[$k]) ? 1 : 0,          // 今天是否已发过奖
            'ok' => owLTTaskOk($task, $t) ? 1 : 0,
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
function owLTAfterProgress(int $uid, array &$row, array &$t): void
{
    $before = (int)($row['level'] ?? 1);
    owLTPayTasks($row, $t);
    // settled 保留原义：今日保底任务是否已全部完成（只作展示，见等级页「今日已结算」）
    $row['settled'] = owLTBaseOk(owLTStage((int)$row['level']), $t) ? 1 : 0;
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

    $row = owLTRow($uid);
    if (!$row) return;

    if ((string)$row['day'] !== date('Y-m-d')) {
        // 跨天：进度清零 + 迁移（owLTTouch 内部会写库）
        [$row, $t] = owLTTouch($uid);
        if (!$row) return;
        // v1.2.44 活跃趋势：当日活跃人数（同一人一天只计一次）
        owLTMarkActive($uid);
        owLTBump('logins');                   // 跨天才算一次「登录人天」
        // 登录本身就可能直接达成低阶段任务（新手阶段 = 登录 + 群发言 1）
        owLTAfterProgress($uid, $row, $t);
    } else {
        $t = owLTDecode((string)$row['today']);
    }

    // v3.0：每次请求都累加在线时长（页面开着就算在线）
    if (owLTAccumOnline($uid, $row)) {
        // ⚠️ 必须在这里重算等级：在线时长是**这一条路径**攒出来的，而等级只在
        //    跨天结算时才重算的话，「在线满一天就升一级」要等到第二天才生效。
        //    自检第 10 组（在线攒满 86400 秒）就是拿这个当断言。
        $before = (int)$row['level'];
        owLTRecalc($row);
        owLTSave($uid, $row, $t);
        if ((int)$row['level'] > $before) owLTBump('upgrades');
    }
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

// 每天一次：荣誉等级长期不登录消退（v3.1）。参数在后台「长期不登录消退」一栏。
Plugin::cron('decay_idle', 86400, function () {
    return owLTDecayRun();
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
 *    （**不能**走 owLTRecalc —— 那里有 max 保护会把它顶回去），且只往下压不往上抬。
 *
 * decay_base = 「已经计入消退的不登录天数」，有两个作用：
 *   ① 让任务可重复执行：后台「立即执行」会 force 跑全部任务，多跑几次不该多退；
 *   ② 抵消「每满 N 天」的取整，将来把 N 从 30 改成 15 也不会把历史天数一次性折成级别。
 * 用户重新活跃后（不登录天数 < 基线）基线归零，下一次长期不来从头开始算。
 *
 * @return string 写进计划任务日志的一行摘要
 */
function owLTDecayRun(): string
{
    owLTBoot();
    [$idleDays, $from, $floor] = owLTDecayCfg();
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
                owLTSave($uid, $row, owLTDecode((string)$row['today']));
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
        $calc = owLTLevelFromTotal(owLTTotalDays($row));        // 公式值（已含 −消退天）
        $row['level'] = min($before, max($floor, $calc));       // 只降不升，且不低于 floor
        owLTSave($uid, $row, owLTDecode((string)$row['today']));

        $hit++;
        if ((int)$row['level'] < $before) $dropped++;
    }
    return '检查 ' . $checked . ' 人（≥ Lv.' . $from . '），消退 ' . $hit . ' 人，'
        . '其中 ' . $dropped . ' 人等级下降；规则：每满 ' . owLTNum($idleDays) . ' 天不登录退 1 级，最低 Lv.' . $floor;
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
    $level = owLTLevelOf($uid);
});

/**
 * `user.level.set` —— 设置某用户等级（后台「用户管理」用）。
 * $ok 传入 **null**：没有插件响应时它保持 null，调用方据此提示「未安装等级插件」，
 * 而不是误报成功。
 *
 * v3.0 起等级是按「天」算的（在线 + 任务 + 设定），所以直设等级必须补差额：
 * 走 owLTAlignLevel() 把差额记进 bonus_days，等级页才能如实拆开展示。
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
    $row = owLTRow($uid);
    if (!$row) { $ok = false; $msg = '用户不存在'; return; }
    $t = owLTDecode((string)$row['today']);
    owLTAlignLevel($row, $level);
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
    $tasks = owLTTasksToday($row, $t);
    Api::json([
        'ok' => true,
        'level' => (int)$row['level'],
        'stage' => $stage['name'], 'stage_no' => owLTStageNo((int)$row['level']),
        'honor' => (int)$row['level'] >= owLTInt('lv_honor') ? 1 : 0,
        'login_streak' => (int)$row['login_streak'], 'login_days' => (int)$row['login_days'],
        'settled' => (int)$row['settled'],
        'frozen' => (int)$row['frozen_day'] === (int)date('Ymd') ? 1 : 0,
        'tasks' => $tasks,
        'unlocks' => owLTUnlocks((int)$row['level']),
        // v3.0：等级来源拆开下发（在线 / 任务 / 管理员设定），前端不必自己换算
        'prog' => owLTSnapshot($row),
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

/** 小数去尾：0.50 → 0.5、3.00 → 3、0 → 0（等级页与后台共用的数字显示口径） */
function owLTNum(float $v): string
{
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
}

/** 秒 → 中文时长（累计在线时长显示用：8 分 / 2 小时 15 分 / 3 天 4 小时） */
function owLTFmtDur(int $sec): string
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
        $snap = owLTSnapshot($row);
        // v3.0：等级有三个来源，页面如实拆开展示（后台直设过的等级会多一行「管理员设定」）。
        // 上一版把三项混成一个「累计完成 N 天」，后台一改等级就自相矛盾。
        $h .= '<div class="ow-lv-hero-meta">'
            . '<span>累计在线 <b>' . owLTFmtDur((int)$snap['online_sec']) . '</b></span>'
            . '<span>任务奖励 <b>' . owLTNum((float)$snap['task_days']) . '</b> 天</span>'
            . ($snap['bonus_days'] > 0.0001
                ? '<span>管理员设定 <b>' . owLTNum((float)$snap['bonus_days']) . '</b> 天</span>'
                : '')
            . ($snap['decay_days'] > 0.0001
                ? '<span class="ow-lv-decay">长期未登录消退 <b>−' . owLTNum((float)$snap['decay_days']) . '</b> 天</span>'
                : '')
            . '<span>连续登录 <b>' . (int)$row['login_streak'] . '</b> 天</span>'
            . '<span>距 Lv.' . (int)$snap['next_level'] . ' 还需 <b>' . owLTNum((float)$snap['gap_days']) . '</b> 天</span>'
            . '</div>'
            . '<div class="ow-lv-bar"><i style="width:' . (int)$snap['pct'] . '%"></i></div>';
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
        $got  = 0.0;                       // 今日已到手的天数（= 今日实际进度）
        $all  = 0.0;
        foreach (owLTTasksToday($row, $t) as $task) {
            $has = (int)$task['cur'];
            $n   = (int)$task['n'];
            $ok  = $task['ok'] === 1;
            if ($ok) $done++;
            $all += (float)$task['rw'];
            if ($task['paid'] === 1) $got += (float)$task['rw'];
            $h .= '<div class="ow-lv-task">'
                . '<span class="ow-lv-task-k">' . Sec::e((string)$task['label'])
                . ($task['rw'] > 0 ? ' <b class="ow-lv-task-rw">+' . owLTNum((float)$task['rw']) . ' 天</b>' : '')
                . '</span>'
                . '<span class="ow-lv-task-bar"><i style="width:' . (int)min(100, $n > 0 ? $has / $n * 100 : 0) . '%"></i></span>'
                . '<span class="ow-lv-task-v' . ($ok ? ' done' : '') . '">' . $has . ' / ' . $n . '</span>'
                . '</div>';
        }
        $h .= '<div class="ow-lv-task-sum">'
            . '今日任务完成 <b>' . $done . ' / ' . count($stage['base']) . '</b>，'
            . '已获得 <b>' . owLTNum($got) . '</b> / ' . owLTNum($all) . ' 天'
            . ($got > 0 && $got >= $all ? '（<b style="color:var(--ow-green)">今日已全部到手</b>）' : '')
            . '。每个任务每天各计一次，次日清零，完不成也不降级。</div>'
            . '</div>';
    }

    /* ---------- 等级公式 ---------- */
    [$decayIdle, $decayFrom, $decayFloor] = owLTDecayCfg();
    $h .= '<div class="ow-card"><h3 class="ow-lv-h3">等级怎么算</h3>'
        . '<div class="ow-lv-formula">L = 1 + ⌊ 在线天 + 任务天 + 设定天 − 消退天 ⌋</div>'
        . '<ul class="ow-lv-ul">'
        . '<li><b>在线天</b>：累计在线每满 <b>' . owLTNum(owLTOnlineHours()) . ' 小时</b> = 1 天 = 1 级。'
        . '页面开着就算在线；离开后再回来不会补记（单次最多记 2 分钟）</li>'
        . '<li><b>任务天</b>：完成每日任务直接加「天」，每个任务加多少天由后台设置'
        . '（当前：' . owLTRewardText() . '），每个任务每天只计一次</li>'
        . '<li><b>设定天</b>：仅在管理员直接设置等级时出现，用来补齐「等级」与「天」的差额，'
        . '普通用户不会有这一项</li>'
        . ($decayIdle >= 1
            ? '<li><b>消退天</b>：达到 Lv.' . $decayFrom . ' 以后，连续 <b>' . owLTNum($decayIdle)
              . ' 天</b>不登录退 1 级，最多退到 <b>Lv.' . $decayFloor . '</b> 为止。'
              . '消退掉的进度不会自动还回来，回来继续攒在线与任务天数即可涨回去</li>'
            : '<li>长期不登录消退当前<strong>未启用</strong>（后台「长期不登录天数」为 0）</li>')
        . '<li>三项相加减去消退天，向下取整再加 1 就是等级；因为取整，实际是「攒够一天跳一级」的节奏</li>'
        . '<li>未完成任务不降级，已攒下的在线时长与任务奖励都不会被收回</li>'
        . '</ul>'
        . '<div class="ow-lv-tip">有效发言需同时满足：内容 ≥ ' . owLTInt('msg_min_len') . ' 字、非纯表情/纯图片/纯链接、'
        . '距上一条计入任务 ≥ ' . owLTInt('msg_cooldown') . ' 秒、每分钟 ≤ ' . owLTInt('msg_per_minute')
        . ' 条、与最近 ' . owLTInt('msg_dedupe') . ' 条不重复。</div>'
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
        $rw = '+' . owLTNum(owLTReward('login') + owLTReward('gm')) . ' 天';
        $h .= '<tr class="ow-lv-tr' . ($stageNo === $d[0] ? ' is-cur' : '') . '">'
            . '<td><span class="ow-lv-dot" style="background:' . owLTStageColor($d[0]) . '"></span>'
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
    foreach (owLTUnlockMatrix() as $mr) $marks[] = (int)$mr['lv'];
    $curMark = 0;
    foreach ($marks as $mk) if ($mk <= $lv && $mk > $curMark) $curMark = $mk;
    $h .= '<div class="ow-card"><h3 class="ow-lv-h3">能力解锁对比</h3><div class="ow-lv-card-scroll"><table class="ow-lv-table">'
        . '<tr><th>等级</th><th>阶段</th><th>建群名额</th><th>头像</th><th>贴纸上限</th>'
        . '<th>加好友</th><th>单文件上限</th><th>管理候选</th><th>荣誉</th></tr>';
    foreach (owLTUnlockMatrix() as $r) {
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
    $tasks = owLTTasksToday($row, $t);
    Api::json([
        'ok' => true,
        'uid' => $uid, 'nickname' => (string)$u['nickname'], 'role' => (string)$u['role'],
        'level' => (int)$row['level'],
        'stage' => $stage['name'], 'login_streak' => (int)$row['login_streak'], 'login_days' => (int)$row['login_days'],
        'settled' => (int)$row['settled'], 'frozen' => (int)$row['frozen_day'] === (int)date('Ymd') ? 1 : 0,
        'patch_used' => (int)$row['patch_used'], 'protect_used' => (int)$row['protect_used'],
        'tasks' => $tasks, 'today' => $t,
        'prog' => owLTSnapshot($row),      // v3.0：在线/任务/设定三项拆开给后台展示
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
        case 'patch':       // 补签：每周最多 1 次，视为完成当天全部保底任务 → 发放当日全部任务奖励
            $week = date('o-W');
            if ((string)$row['patch_week'] !== $week) { $row['patch_week'] = $week; $row['patch_used'] = 0; }
            if ((int)$row['patch_used'] >= 1) Api::json(['ok' => false, 'msg' => '本周补签次数已用完']);
            $row['patch_used'] = (int)$row['patch_used'] + 1;
            // 把当天每个任务的进度顶到要求值 —— 而不是绕过任务直接改等级，
            // 这样「补签得到的东西」和「真做完任务得到的东西」严格一致（v3.0 逐任务发奖）。
            foreach (owLTStage((int)$row['level'])['base'] as $tk) {
                $k = (string)$tk['k'];
                if (owLTProg($t, $k) < (int)$tk['n']) $t[$k] = (int)$tk['n'];
            }
            $before = (int)$row['level'];
            owLTPayTasks($row, $t);
            $row['settled'] = 1;
            owLTRecalc($row);
            owLTSave($uid, $row, $t);
            if ((int)$row['level'] > $before) owLTBump('upgrades');
            Api::json(['ok' => true, 'msg' => (int)$row['level'] > $before
                ? '已补签，等级提升至 Lv.' . (int)$row['level']
                : '已补签，当日任务奖励已发放（等级不变，仍 Lv.' . (int)$row['level'] . '）']);

        case 'freeze':      // 冻结当天任务进度（结算不再进行）
            $row['frozen_day'] = (int)date('Ymd');
            owLTSave($uid, $row, $t);
            Api::json(['ok' => true, 'msg' => '已冻结今日任务']);

        case 'unfreeze':
            $row['frozen_day'] = 0;
            owLTSave($uid, $row, $t);
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
            owLTAlignLevel($row, $lv);
            owLTSave($uid, $row, $t);
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
            owLTRecalc($row);                 // 这里可以走 recalc：清除是「往上涨」
            owLTSave($uid, $row, $t);
            Api::json(['ok' => true, 'msg' => $before === (int)$row['level']
                ? '已清除消退（等级不变，仍 Lv.' . (int)$row['level'] . '）'
                : '已清除消退，等级回到 Lv.' . (int)$row['level']]);

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
        . '<p class="ow-admin-desc">等级 L = 1 + ⌊ 在线天 + 任务天 + 设定天 − 消退天 ⌋（v3.1）：'
        . '累计在线每满 <b>' . owLTNum(owLTOnlineHours()) . '</b> 小时算 1 天，完成每日任务按后台设定的奖励加「天」，'
        . '1 天 = 1 级。当前任务奖励：<b>' . Sec::e(owLTRewardText()) . '</b>。'
        . '各阶段保底任务统一为「登录 + 群发言 N 条」，每个任务各自发奖、每天各一次。'
        . '荣誉等级（Lv.' . owLTInt('decay_from') . ' 起）长期不登录会自动消退，最低退到 Lv.' . owLTDecayCfg()[2] . '。'
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

    $head .= '<h4 class="ow-lt-cap">等级速度（v3.0：L = 1 + ⌊ 在线天 + 任务天 + 设定天 ⌋）</h4><div class="ow-form-row">'
        . $f('online_hours', '在线多少小时算 1 天', '累计在线每满这么多小时 = +1 天 = +1 级；默认 24')
        . $f('rw_login', '「登录」任务奖励（天）', '完成一次奖励多少天，默认 0.5；每个任务每天只发一次')
        . $f('rw_gm', '「群发言」任务奖励（天）', '完成一次奖励多少天，默认 0.5')
        . '</div>'
        . '<p style="font-size:12px;color:var(--ow-text-sub);margin:0 0 14px">'
        . '任务奖励填 0 = 该任务不发奖励（只统计进度）；两个都填 1 就是「每天做完任务升 2 级」。'
        . '在线时长按「页面开着」累计，单次最多计 2 分钟（离开后再回来不会补记）。'
        . 'v2.0 的「每日进度倍率」已停用：只在存量数据迁移时读一次，不再出现在这里。</p>';

    $head .= '<h4 class="ow-lt-cap">长期不登录消退（荣誉等级）</h4><div class="ow-form-row">'
        . $f('decay_idle_days', '长期不登录天数', '每满这么多天不登录退 1 级；填 0 = 关闭消退')
        . $f('decay_from', '从该等级起消退（Lv）', '低于此等级的用户不受影响；默认 60')
        . $f('decay_floor', '最低退到（Lv）', '最多退到该等级为止；默认 46（荣誉阶段起点）')
        . '</div>'
        . '<p style="font-size:12px;color:var(--ow-text-sub);margin:0 0 14px">'
        . '由每日计划任务「长期不登录的荣誉等级消退」执行（后台「计划任务」页可点「立即执行」手动跑一次）。'
        . '消退掉的进度**不会自动还回来**：用户回来继续攒在线与任务天数即可涨回去。'
        . '「最低退到」会被夹到低于「从该等级起消退」，配置写反时不会把用户一次压到更低的级别。'
        . '单个用户被误消退时，可在下方「用户查询」里点「清除消退」还回去。</p>';

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
