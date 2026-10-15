<?php
/**
 * 每日签到插件（daily-checkin v1.2.0）
 *
 * ── 功能 ──────────────────────────────────────────────────
 *   ① 每日签到：左侧一级导航条（rail）新增「签到」入口，圆形按钮一键打卡。
 *   ② 连续奖励：连续第 N 天可领 = 基础分 + 递增分（封顶）；
 *      连续天数每满一个周期（默认 7 天）额外发一笔周期大奖。
 *   ③ 月历：当月每天的打卡状态（已签 / 未签 / 今天）。
 *   ④ 排行榜：本月累计签到天数 TOP N（含头像与昵称）。
 *   ⑤ 补签卡：断签时消耗积分补回紧邻的那一整天，连续天数得以延续（后台可关）。
 *   ⑥ 今日任务卡（v1.1.0）：展示「等级信任」插件的当日保底任务进度。
 *      数据走该插件的 plugin_level_trust_mine 接口（pull 模式，不反向依赖）——
 *      游客、插件未启用、接口不可用三种情形整卡不显示，签到功能不受影响。
 *   ⑦ 等级荣誉榜（v1.2.0）：「本月签到榜」正下方展示等级信任插件的荣誉等级榜单
 *      （达到荣誉线，默认 Lv.60，按等级降序 TOP N）。同样走 pull 模式
 *      （plugin_level_trust_rank），等级插件未启用 / 空榜整卡不显示；
 *      上榜人数复用本插件「排行榜人数」配置，两个榜人数始终一致。
 *      纯展示，数据由等级插件下发，本插件不落任何新表/新配置。
 *
 * ── 只写自己的表，不动核心 ────────────────────────────────
 *   plugin_checkin_user  每人一行：累计 / 当前连续 / 最长连续 / 累计积分
 *   plugin_checkin_log   每次打卡一行（user_id + day 唯一，重复签到靠它兜底）
 *   plugin_checkin_cfg   后台可调参数
 *   积分直接加在核心 users.points 上（与「用户管理」里手工调整的积分同源）。
 *
 * 约束：
 *   - 兼容 SQLite / MySQL / PostgreSQL（类型与自增走 DB 助手，不手写方言）。
 *   - 全部 SQL 参数化；main.php 顶层只做 Plugin::* 注册。
 *   - 仅注册用户可签到：游客身份随浏览器会话消亡，签到记录无意义。
 */
if (!defined('OWLSGO_VERSION')) exit;   // 禁止直接 HTTP 访问本文件

/* ============================ 建表 ============================ */

/** 懒建表：只在真正读写数据时执行（main.php 顶层不做副作用） */
function owCIBoot(): void
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

/* ============================ 配置 ============================ */

/**
 * 全部可调参数与默认值。
 * ⚠️ 每加一个新键，必须同时：① 这里给默认值；② 后台表单给输入框；③ cfg_save 白名单放行。
 *    漏了 ③ 的表现是「后台保存成功但值没变」。
 */
function owCIDefaults(): array
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
        'rank_size'   => '8',    // 排行榜展示人数
    ];
}

/**
 * 读配置（字符串）。
 * 缓存放 $GLOBALS 而非函数内 static：static 无法被 owCISetCfg 主动清掉，
 * 会出现「后台刚改完、本请求后续逻辑仍按旧值算」的不一致。
 */
function owCICfg(string $k): string
{
    owCIBoot();
    if (!isset($GLOBALS['_owCICfg']) || !is_array($GLOBALS['_owCICfg'])) $GLOBALS['_owCICfg'] = [];
    if (array_key_exists($k, $GLOBALS['_owCICfg'])) return $GLOBALS['_owCICfg'][$k];
    $v = DB::val('SELECT v FROM plugin_checkin_cfg WHERE k=?', [$k]);
    $def = owCIDefaults();
    $GLOBALS['_owCICfg'][$k] = ($v === null || $v === false || $v === '') ? ($def[$k] ?? '') : (string)$v;
    return $GLOBALS['_owCICfg'][$k];
}

/** 读整数配置（后台填了非法值时回落到默认，避免算出负数奖励） */
function owCIInt(string $k): int
{
    $v = owCICfg($k);
    if (!preg_match('/^-?\d+$/', $v)) return (int)(owCIDefaults()[$k] ?? 0);
    return (int)$v;
}

function owCISetCfg(string $k, string $v): void
{
    owCIBoot();
    DB::run('DELETE FROM plugin_checkin_cfg WHERE k=?', [$k]);
    DB::run('INSERT INTO plugin_checkin_cfg (k, v) VALUES (?,?)', [$k, $v]);
    unset($GLOBALS['_owCICfg'][$k]);      // 立刻失效，同请求内后续读取拿到新值
}

/* ============================ 数据读写 ============================ */

/** 取用户行；不存在则按「从未签到」创建 */
function owCIUser(int $uid): array
{
    owCIBoot();
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

function owCISigned(int $uid, string $day): bool
{
    owCIBoot();
    return (bool)DB::val('SELECT COUNT(*) FROM plugin_checkin_log WHERE user_id=? AND day=?', [$uid, $day]);
}

/**
 * 连续第 $streak 天应发的积分明细。
 * 公式 = 基础 + min(递增 × (天数 - 1), 递增封顶) +（天数是周期整数倍时）周期大奖
 */
function owCIRewardParts(int $streak): array
{
    $base  = max(0, owCIInt('base'));
    $step  = max(0, owCIInt('step'));
    $cap   = max(0, owCIInt('step_cap'));
    $cyc   = max(0, owCIInt('cycle_days'));
    $bonus = max(0, owCIInt('cycle_bonus'));
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
function owCISign(int $uid, string $day, string $kind = 'normal', bool $reward = true): array
{
    owCIBoot();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) return ['ok' => false, 'msg' => '日期格式不正确'];
    if (owCISigned($uid, $day)) return ['ok' => false, 'msg' => '这一天已经签到过了'];

    $u = owCIUser($uid);
    if (!$u) return ['ok' => false, 'msg' => '用户不存在'];

    // 连续判定：只认「紧接在最近一次签到之后的那一天」，其余一律从 1 重新起算。
    // （补签正是靠这条规则生效 —— 把断掉的那天补上，后续天数自然接得回来。）
    $prev  = (string)$u['last_day'];
    $yday  = date('Y-m-d', strtotime($day) - 86400);
    $strk  = ($prev !== '' && $prev === $yday) ? (int)$u['streak'] + 1 : 1;

    $parts = $reward ? owCIRewardParts($strk) : ['base' => 0, 'step' => 0, 'bonus' => 0, 'total' => 0];
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
    if ($gain > 0) DB::run('UPDATE users SET points=points+? WHERE id=?', [$gain, $uid]);

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
function owCIPatchTarget(int $uid, array $u): array
{
    // ⚠️ 别用 `$none + ['reason' => …]` 拼返回值：数组 + 运算**保留左侧键**，
    //    左侧已经有 reason => '' 时右侧根本写不进去，于是前端永远拿到空原因。
    $no = function (string $why): array { return ['can' => false, 'day' => '', 'reason' => $why]; };
    if (owCIInt('patch_cost') <= 0) return $no('站点未开启补签');
    $prev = (string)($u['last_day'] ?? '');
    if ($prev === '') return $no('还没有签到记录');
    $target = date('Y-m-d', strtotime($prev) + 86400);
    $today  = date('Y-m-d');
    if ($target >= $today) return $no('当前没有断签');           // 昨天已签或今天已签
    if (owCISigned($uid, $target)) return $no('那一天已经签到过了');

    // 每月次数上限（跨月自动重置）
    $mon = date('Y-m');
    $used = ((string)($u['patch_mon'] ?? '') === $mon) ? (int)($u['patch_used'] ?? 0) : 0;
    if ($used >= max(0, owCIInt('patch_limit'))) return $no('本月补签次数已用完');
    return ['can' => true, 'day' => $target, 'reason' => ''];
}

/** 本月（或指定月）某人的签到日集合 */
function owCIMonthDays(int $uid, string $month): array
{
    owCIBoot();
    $out = [];
    foreach (DB::all('SELECT day FROM plugin_checkin_log WHERE user_id=? AND day>=? AND day<=?',
        [$uid, $month . '-01', $month . '-31']) as $r) {
        $out[(string)$r['day']] = 1;
    }
    return $out;
}

/** 我的积分余额（核心 users.points） */
function owCIPoints(int $uid): int
{
    $v = DB::val('SELECT points FROM users WHERE id=?', [$uid]);
    return $v === null || $v === false ? 0 : (int)$v;
}

/**
 * 本月签到排行榜（天数相同的按 user_id 升序，保证排序稳定、翻页不跳）。
 * 头像由服务端算好下发 —— 前端自己拼 DiceBear 参数迟早与资料卡的口径漂移。
 */
function owCIRank(string $month, int $limit): array
{
    owCIBoot();
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

/* ============================ 前台路由 ============================ */

/** 守卫：必须是注册用户（游客没有 users 行，签到无从记起） */
function owCINeedUser(array $ctx): int
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
Plugin::route('plugin_daily_checkin_info', function (array $ctx) {
    $on = owCICfg('enable') === '1';
    $today = date('Y-m-d');
    $month = (string)($ctx['post']['month'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
    $base = [
        'ok' => true, 'enabled' => $on ? 1 : 0,
        'today' => $today, 'month' => $month,
        'cfg' => [
            'base' => owCIInt('base'), 'step' => owCIInt('step'), 'step_cap' => owCIInt('step_cap'),
            'cycle_days' => owCIInt('cycle_days'), 'cycle_bonus' => owCIInt('cycle_bonus'),
            'patch_cost' => owCIInt('patch_cost'), 'patch_limit' => owCIInt('patch_limit'),
        ],
    ];
    if (($ctx['actor']['kind'] ?? '') !== 'user') {
        Api::json($base + ['guest' => 1, 'rank' => owCIRank($month, owCIInt('rank_size'))]);
    }

    $uid = (int)$ctx['actor']['id'];
    $u = owCIUser($uid);
    $signed = owCISigned($uid, $today);
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
    $next  = owCIRewardParts($after);
    $cyc   = max(0, owCIInt('cycle_days'));
    $mod   = $cyc > 0 ? ($after % $cyc) : 0;
    $cycleLeft = ($cyc <= 0) ? 0 : ($mod === 0 ? 0 : $cyc - $mod);
    // 今天实际拿到多少（已签到时展示本次实发，未签时展示「可领」）
    $gotToday = $signed ? (int)DB::val('SELECT COALESCE(points,0) FROM plugin_checkin_log WHERE user_id=? AND day=?', [$uid, $today]) : 0;

    $t = owCIPatchTarget($uid, $u);
    $mon = date('Y-m');
    $patchLeft = ((string)($u['patch_mon'] ?? '') === $mon)
        ? max(0, owCIInt('patch_limit') - (int)$u['patch_used']) : owCIInt('patch_limit');

    Api::json($base + [
        'guest' => 0,
        'signed' => $signed ? 1 : 0,
        'streak' => $live,                       // 现算的有效连续（断签后自然归零）
        'streak_saved' => (int)$u['streak'],     // 表里存的快照，后台排查用
        'max_streak' => (int)$u['max_streak'],
        'total_days' => (int)$u['total_days'],
        'earned' => (int)$u['points'],
        'my_points' => owCIPoints($uid),
        'next' => $next,                         // 下一次签到可领（含明细）
        'got' => $gotToday,                      // 今天已实发（未签为 0）
        'cycle_left' => $cycleLeft,
        'patch' => ['can' => $t['can'] ? 1 : 0, 'day' => $t['day'], 'reason' => $t['reason'],
                    'cost' => owCIInt('patch_cost'), 'left' => $patchLeft],
        'days' => owCIMonthDays($uid, $month),
        'rank' => owCIRank($month, owCIInt('rank_size')),
    ]);
});

/** 执行签到 */
Plugin::route('plugin_daily_checkin_do', function (array $ctx) {
    $uid = owCINeedUser($ctx);
    if (owCICfg('enable') !== '1') Api::json(['ok' => false, 'msg' => '站点未开放签到']);
    $r = owCISign($uid, date('Y-m-d'), 'normal', true);
    if (!$r['ok']) Api::json(['ok' => false, 'msg' => $r['msg']]);

    $cyc = owCIInt('cycle_days');
    $msg = '签到成功，获得 ' . (int)$r['points'] . ' 积分';
    if ((int)$r['streak'] > 1) $msg .= '，已连续 ' . (int)$r['streak'] . ' 天';
    if ((int)$r['parts']['bonus'] > 0) $msg .= '（含连续 ' . $cyc . ' 天大奖 ' . (int)$r['parts']['bonus'] . ' 积分）';
    Api::json(['ok' => true, 'msg' => $msg, 'points' => (int)$r['points'],
               'streak' => (int)$r['streak'], 'total' => (int)$r['total'],
               // parts 供「签到成功」弹窗拆分展示（基础 / 递增 / 周期大奖各一行）
               'parts' => $r['parts']]);
});

/**
 * 补签：扣积分 + 补记录（不发奖励积分），把连续天数接回来。
 * 标为敏感操作：它扣的是用户自己的积分，走一次性票据可防请求重放。
 */
Plugin::route('plugin_daily_checkin_patch', function (array $ctx) {
    $uid = owCINeedUser($ctx);
    if (owCICfg('enable') !== '1') Api::json(['ok' => false, 'msg' => '站点未开放签到']);
    $u = owCIUser($uid);
    $t = owCIPatchTarget($uid, $u);
    if (!$t['can']) Api::json(['ok' => false, 'msg' => $t['reason'] !== '' ? $t['reason'] : '当前没有可补签的日期']);

    $cost = owCIInt('patch_cost');
    if (owCIPoints($uid) < $cost) Api::json(['ok' => false, 'msg' => '积分不足，补签需要 ' . $cost . ' 积分']);

    $r = owCISign($uid, $t['day'], 'patch', false);
    if (!$r['ok']) Api::json(['ok' => false, 'msg' => $r['msg']]);

    DB::run('UPDATE users SET points=points-? WHERE id=?', [$cost, $uid]);
    $mon = date('Y-m');
    $used = ((string)$u['patch_mon'] === $mon) ? (int)$u['patch_used'] + 1 : 1;
    DB::run('UPDATE plugin_checkin_user SET patch_used=?, patch_mon=? WHERE user_id=?', [$used, $mon, $uid]);
    Sec::log('checkin_patch', (string)($ctx['actor']['nickname'] ?? ''), ['uid' => $uid, 'day' => $t['day'], 'cost' => $cost]);

    Api::json(['ok' => true, 'msg' => '已补签 ' . $t['day'] . '，连续天数恢复到 ' . (int)$r['streak'] . ' 天',
               'cost' => $cost, 'streak' => (int)$r['streak']]);
});
Plugin::sensitive('plugin_daily_checkin_patch');

/* ============================ 后台路由 ============================ */

/** 管理员鉴权（use 捕获的是变量值，必须先定义） */
$ciGuard = function (array $ctx): void {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
};

/** 后台概览：今日 / 本月 / 累计 + 近 14 天趋势 + 累计榜 */
Plugin::route('plugin_daily_checkin_stats', function (array $ctx) use ($ciGuard) {
    $ciGuard($ctx);
    owCIBoot();
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

    // 累计签到天数榜（全量，不是当月）
    $top = [];
    foreach (DB::all('SELECT u.id, u.nickname, u.role, s.total_days, s.streak, s.max_streak, s.points
                      FROM plugin_checkin_user s JOIN users u ON u.id=s.user_id
                      ORDER BY s.total_days DESC, s.user_id ASC LIMIT 10') as $r) {
        $top[] = ['uid' => (int)$r['id'], 'nickname' => (string)$r['nickname'], 'role' => (string)$r['role'],
                  'days' => (int)$r['total_days'], 'streak' => (int)$r['streak'],
                  'max_streak' => (int)$r['max_streak'], 'points' => (int)$r['points']];
    }

    Api::json(['ok' => true, 'today' => $todayN, 'month' => $monthN, 'all' => $allN,
               'points' => $points, 'users' => $users, 'avg_streak' => round($avgStreak, 1),
               'trend' => $trend, 'top' => $top]);
});

/** 读取全部参数（后台表单回填） */
Plugin::route('plugin_daily_checkin_cfg_get', function (array $ctx) use ($ciGuard) {
    $ciGuard($ctx);
    $cfg = [];
    foreach (owCIDefaults() as $k => $def) $cfg[$k] = owCICfg($k);
    Api::json(['ok' => true, 'cfg' => $cfg]);
});

/** 保存参数（白名单 = owCIDefaults 的键集合，前端塞别的键也落不了库） */
Plugin::route('plugin_daily_checkin_cfg_save', function (array $ctx) use ($ciGuard) {
    $ciGuard($ctx);
    $post = $ctx['post'];
    $saved = 0;
    foreach (array_keys(owCIDefaults()) as $k) {
        if (!isset($post[$k])) continue;
        $v = trim((string)$post[$k]);
        // 数值统一收敛到 [0, 100000]：后台填负数会让「签到反而扣分」，填天量会打爆积分
        if (preg_match('/^-?\d+$/', $v)) {
            $n = (int)$v;
            if ($n < 0) $n = 0;
            if ($n > 100000) $n = 100000;
            $v = (string)$n;
        }
        owCISetCfg($k, $v);
        $saved++;
    }
    Sec::log('checkin_cfg_save', (string)($ctx['actor']['nickname'] ?? ''), ['items' => $saved]);
    Api::json(['ok' => true, 'msg' => '参数已保存（' . $saved . ' 项）']);
});
Plugin::sensitive('plugin_daily_checkin_cfg_save');

/** 查单个用户的签到档案（后台） */
Plugin::route('plugin_daily_checkin_user', function (array $ctx) use ($ciGuard) {
    $ciGuard($ctx);
    owCIBoot();
    $kw = trim((string)($ctx['post']['kw'] ?? ''));
    if ($kw === '') Api::json(['ok' => false, 'msg' => '请填写用户 ID 或昵称关键词']);
    $row = ctype_digit($kw)
        ? DB::one('SELECT id, nickname, role, points FROM users WHERE id=?', [(int)$kw])
        : DB::one('SELECT id, nickname, role, points FROM users WHERE nickname LIKE ? ORDER BY id ASC LIMIT 1', ['%' . $kw . '%']);
    if (!$row) Api::json(['ok' => false, 'msg' => '没有找到匹配的用户']);

    $uid = (int)$row['id'];
    $u = owCIUser($uid);
    $month = (string)($ctx['post']['month'] ?? date('Y-m'));
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
    $recent = DB::all('SELECT day, points, streak, kind FROM plugin_checkin_log WHERE user_id=? ORDER BY day DESC LIMIT 30', [$uid]);

    Api::json(['ok' => true,
        'uid' => $uid, 'nickname' => (string)$row['nickname'], 'role' => (string)$row['role'],
        'points' => (int)$row['points'],
        'total_days' => (int)$u['total_days'], 'streak' => (int)$u['streak'],
        'max_streak' => (int)$u['max_streak'], 'earned' => (int)$u['points'],
        'last_day' => (string)$u['last_day'], 'signed_today' => owCISigned($uid, date('Y-m-d')) ? 1 : 0,
        'month' => $month, 'days' => owCIMonthDays($uid, $month),
        'recent' => array_map(fn($r) => ['day' => (string)$r['day'], 'points' => (int)$r['points'],
                                         'streak' => (int)$r['streak'], 'kind' => (string)$r['kind']], $recent)]);
});

/** 管理员操作：代签 / 清零连续 */
Plugin::route('plugin_daily_checkin_user_op', function (array $ctx) use ($ciGuard) {
    $ciGuard($ctx);
    $uid = (int)($ctx['post']['uid'] ?? 0);
    $op  = (string)($ctx['post']['op'] ?? '');
    if ($uid <= 0) Api::json(['ok' => false, 'msg' => '请填写用户 ID']);
    if (!DB::one('SELECT id FROM users WHERE id=?', [$uid])) Api::json(['ok' => false, 'msg' => '用户不存在']);

    switch ($op) {
        case 'sign':        // 代签今天（按正常规则发积分）
            if (owCISigned($uid, date('Y-m-d'))) Api::json(['ok' => false, 'msg' => '该用户今天已经签到过了']);
            $r = owCISign($uid, date('Y-m-d'), 'admin', true);
            if (!$r['ok']) Api::json(['ok' => false, 'msg' => $r['msg']]);
            Sec::log('checkin_admin_sign', (string)($ctx['actor']['nickname'] ?? ''), ['uid' => $uid]);
            Api::json(['ok' => true, 'msg' => '已代签，发放 ' . (int)$r['points'] . ' 积分']);

        case 'reset':       // 连续天数清零（不删历史记录，只把 streak 归零）
            DB::run('UPDATE plugin_checkin_user SET streak=0 WHERE user_id=?', [$uid]);
            Sec::log('checkin_admin_reset', (string)($ctx['actor']['nickname'] ?? ''), ['uid' => $uid]);
            Api::json(['ok' => true, 'msg' => '已清零该用户的连续天数']);

        default:
            Api::json(['ok' => false, 'msg' => '未知操作']);
    }
});
Plugin::sensitive('plugin_daily_checkin_user_op');

/* ============================ 前台入口（rail） ============================ */

/**
 * 左侧一级导航条「签到」入口。
 * ow_icon('check-circle') 是核心自带的线性 SVG —— 不用 Emoji，也不用位图。
 * <span class="ow-rail-lb"> 桌面隐藏、窄屏 rail 变标签栏时显示，与核心入口保持一致。
 */
Plugin::on('sidebar.rail', function () {
    return '<button class="ow-rail-btn" data-tab="checkin" type="button" title="每日签到" aria-label="每日签到">'
         . ow_icon('check-circle', 20) . '<span class="ow-rail-lb">签到</span></button>';
});

/* ============================ 后台页 ============================ */

Plugin::adminPage('daily-checkin', '每日签到', function () {
    $f = function (string $k, string $label, string $hint = '') {
        return '<div class="ow-form-item"><label>' . Sec::e($label) . '</label>'
            . '<input class="ow-input" id="owCiCfg_' . $k . '" value="' . Sec::e(owCICfg($k)) . '">'
            . ($hint !== '' ? '<p style="font-size:12px">' . Sec::e($hint) . '</p>' : '')
            . '</div>';
    };
    $cyc = owCIInt('cycle_days');

    $h = '<h2>每日签到</h2>'
        . '<p class="ow-admin-desc">用户每日签到领取积分：连续第 N 天可领 = 基础分 + 递增分（封顶）'
        . ($cyc > 0 ? '，每满 ' . $cyc . ' 天额外发一笔大奖' : '')
        . '。断签后连续天数从 1 重新起算，可用补签卡接回。</p>';

    $h .= '<div class="ow-card" id="owCiStats"></div>'
        . '<div class="ow-card"><h3 style="margin:0 0 4px">近 14 天签到人数</h3><div id="owCiTrend"></div></div>'
        . '<div class="ow-card"><h3 style="margin:0 0 10px">累计签到榜</h3><div id="owCiTop"></div></div>';

    $h .= '<div class="ow-card"><h3 style="margin:0 0 10px">参数设置</h3><div class="ow-form-row">'
        . '<div class="ow-form-item" style="flex:1"><label>签到功能</label>'
        . '<select class="ow-input" id="owCiCfg_enable">'
        . '<option value="1"' . (owCICfg('enable') === '1' ? ' selected' : '') . '>开启</option>'
        . '<option value="0"' . (owCICfg('enable') === '0' ? ' selected' : '') . '>关闭（面板显示「未开放」）</option>'
        . '</select></div>'
        . $f('base', '基础积分（分）', '每次签到都发的保底数')
        . $f('step', '连续递增（分/天）', '每多连一天额外增加')
        . $f('step_cap', '递增封顶（分）', '额外部分最多累加到这个数')
        . $f('cycle_days', '大奖周期（天）', '连续满 N 天发一笔大奖；填 0 = 不发')
        . $f('cycle_bonus', '周期大奖（分）')
        . $f('patch_cost', '补签卡单价（积分）', '填 0 = 不提供补签')
        . $f('patch_limit', '每月补签上限（次）')
        . $f('rank_size', '排行榜人数')
        . '</div>'
        // ⚠️ 全局对象名必须与 admin.js 里 w.OwCIAdmin 完全一致（后台页 OwCIAdmin、
        //    前台 chat.js 是 OwCI，两个页面互斥加载，名字分开避免误调）。
        . '<div class="ow-modal-actions"><button class="ow-btn ow-btn-primary" onclick="OwCIAdmin.saveCfg()">保存全部参数</button></div>'
        . '</div>';

    $h .= '<div class="ow-card"><h3 style="margin:0 0 10px">用户查询</h3><div class="ow-form-row">'
        . '<div class="ow-form-item" style="min-width:160px"><label>用户 ID 或昵称</label>'
        . '<input class="ow-input" id="owCiKw" placeholder="如 1 或 小明"'
        . ' onkeydown="if(event.key===\'Enter\')OwCIAdmin.query()"></div>'
        . '<button class="ow-btn ow-btn-primary" onclick="OwCIAdmin.query()">查询</button>'
        . '</div><div id="owCiUser"></div></div>';

    return $h;
});

/* ============================ 资源 ============================ */

Plugin::asset('css', 'daily-checkin/style.css');
Plugin::asset('js', 'daily-checkin/chat.js');
Plugin::asset('js', 'daily-checkin/admin.js');
