<?php
/**
 * 登录日志插件（v1.1.0）
 *
 * 数据来源：四个核心钩子（都在 core/auth.php 与 core/security.php 内 fire）
 *   - login.after_verify      成功登录    [$user, $method, ['ip' => ...]]
 *   - login.failed            登录失败    [$info]（v1.1.12 新增）
 *   - logout.before_destroy   登出        [$info]（v1.1.12 新增）
 *   - session.destroyed       会话被销毁  [$info]（v1.1.12 新增，指纹守卫踢出）
 *
 * 四者共用一张表，用 `result` 列区分（ok / fail / logout），
 * 因此「谁在什么时候登录失败过」「谁登出后就没再出现」都能在同一个后台页里查。
 *
 * ⚠️ 依赖核心钩子的两个前提，缺一不可：
 *   1. `logout.before_destroy` 必须在 session_destroy() **之前** fire；
 *      否则 $_SESSION 已清空，登出记录只能记成匿名。
 *   2. 载荷里的 `user` 字段在密码错误时是 **null**（防账号枚举侧信道），
 *      插件侧必须判空后再用，不能假定一定有用户行。
 *
 * 后台页面：Plugin::adminPage() 注册「登录日志」子页（插件管理分类下）。
 * API 路由：Plugin::route()，action 前缀 plugin_login_logs_。
 *   - list   分页列表，支持按用户 ID / 关键词 / 时间段 / 结果筛选
 *   - stats  顶部统计：总记录数 / 独立用户数 / 今日登录数
 *   - del    批量删除所选（v1.2.1；敏感操作，需票据 + 前台二次确认）
 *   - clear  清理旧日志（默认 90 天前；敏感操作，需票据）
 * 计划任务：Plugin::cron('purge_old', 86400, ...) 每天自动清理 90 天前日志，
 *           启用插件即默认启用，可在后台「计划任务」页启停 / 手动触发。
 * 安全约束：所有路由第一步做管理员鉴权；SQL 全部参数化；
 *           跨驱动建表（SQLite / MySQL / PostgreSQL）。
 */
if (!defined('OWLSGO_VERSION')) exit;   // 禁止直接 HTTP 访问本文件

/* ===================== 数据库表 ===================== */

/** 跨驱动建表（与核心 autoId()/t() 同策略，内联以避免依赖私有方法） */
function owLLEnsureTable(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $drv = DB::driver();
    $id  = $drv === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY'
         : ($drv === 'pgsql' ? 'SERIAL PRIMARY KEY'
         : 'INTEGER PRIMARY KEY AUTOINCREMENT');
    $int = $drv === 'mysql' ? 'INT' : 'INTEGER';
    $str = $drv === 'sqlite' ? 'TEXT' : 'VARCHAR(191)';
    $ua  = $drv === 'sqlite' ? 'TEXT' : 'VARCHAR(255)';
    DB::run("CREATE TABLE IF NOT EXISTS plugin_login_logs (
        id $id,
        user_id $int NOT NULL,
        nickname $str NOT NULL,
        email $str NOT NULL,
        ip $str,
        user_agent $ua,
        method $str NOT NULL DEFAULT 'password',
        created_at $int NOT NULL
    )");
    // 索引：MySQL 不支持 CREATE INDEX IF NOT EXISTS，统一走 DB::createIndex()
    DB::createIndex('idx_pllogin_user', 'plugin_login_logs', 'user_id');
    DB::createIndex('idx_pllogin_created', 'plugin_login_logs', 'created_at');
    // v1.1.12：新增 result / identity / reason 三列（幂等迁移，存量行默认 'ok'）
    // ⚠️ 必须用公开的 ensureColumn()，不能用 DB::addColumn()——后者是 private，
    // 插件调用会抛 Error；而 Plugin::loadPlugin() 吞掉插件异常后，
    // 本函数之后的 Plugin::on 全部不注册，插件变成「静默半个身位」。
    DB::ensureColumn('plugin_login_logs', 'result', $str, "'ok'");
    DB::ensureColumn('plugin_login_logs', 'identity', $str, "''");   // 失败时用户提交的标识
    DB::ensureColumn('plugin_login_logs', 'reason', $str, "''");     // fail / locked / disabled / manual
}
owLLEnsureTable();

/* ===================== 登录 / 登出钩子 ===================== */

/**
 * 共用写入器。三个钩子载荷形状不同但字段可归一，这里统一收口：
 * 任何一条写失败都只记 error、绝不影响登录主流程（fire 内部也会再兜一层）。
 */
$llWrite = function (array $row): void {
    try {
        DB::insert('plugin_login_logs', [
            'user_id'     => (int)($row['user_id'] ?? 0),
            'nickname'    => (string)($row['nickname'] ?? ''),
            'email'       => (string)($row['email'] ?? ''),
            'ip'          => (string)($row['ip'] ?? ''),
            'user_agent'  => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'method'      => (string)($row['method'] ?: 'password'),
            'result'      => (string)($row['result'] ?? 'ok'),
            'identity'    => (string)($row['identity'] ?? ''),
            'reason'      => (string)($row['reason'] ?? ''),
            'created_at'  => time(),
        ]);
    } catch (Throwable $e) {
        // 日志写入失败不影响登录主流程
        Sec::log('plugin_login_logs_err', '', ['err' => $e->getMessage()]);
    }
};

/**
 * login.after_verify：成功登录。
 * $user 是完整 users 行（含 id / nickname / email），$method 为验证方式，
 * $ctx['ip'] 为客户端 IP（已通过 Sec::ip() 处理反代头）。
 */
Plugin::on('login.after_verify', function ($user, $method, array $ctx) use ($llWrite): void {
    if (!is_array($user) || empty($user['id'])) return;
    $llWrite([
        'user_id'  => (int)$user['id'],
        'nickname' => (string)($user['nickname'] ?? ''),
        'email'    => (string)($user['email'] ?? ''),
        'ip'       => (string)($ctx['ip'] ?? ''),
        'method'   => (string)($method ?: 'password'),
        'result'   => 'ok',
    ]);
});

/**
 * login.failed：登录失败（v1.1.12 新增核心钩子）。
 * 载荷 `$info`：
 *   identity 用户提交的登录标识（原样，邮箱或数字用户 ID）
 *   reason   fail=密码或账号错 / locked=已锁定 / disabled=账号被禁用
 *   left     剩余尝试次数（-1 表示不适用）
 *   user     命中的 users 行；**密码错误时为 null**（防账号枚举侧信道），必须判空
 *   ip       客户端 IP
 */
Plugin::on('login.failed', function (array $info) use ($llWrite): void {
    if (empty($info['reason'])) return;   // 非登录失败（如两阶段验证失败）不入此表
    $u = is_array($info['user'] ?? null) ? $info['user'] : null;
    $llWrite([
        'user_id'  => (int)($u['id'] ?? 0),
        'nickname' => (string)($u['nickname'] ?? ''),
        'email'    => (string)($u['email'] ?? ''),
        'ip'       => (string)($info['ip'] ?? ''),
        'method'   => 'password',
        'result'   => 'fail',
        // 账号不存在时 user 为空，把用户提交的标识存下来，
        // 否则「查了不存在的账号」这类记录只剩一行空身份，事后无从排查。
        'identity' => (string)($info['identity'] ?? ''),
        'reason'   => (string)$info['reason'],
    ]);
});

/**
 * logout.before_destroy：登出（v1.1.12 新增核心钩子）。
 * 载荷 `$info`：uid / nickname / had_uid / ip / reason（manual=用户主动登出）
 * ⚠️ 核心保证在 session_destroy() 之前 fire，所以这里 uid 一定拿得到；
 *    指纹守卫踢出的会话走的是 `session.destroyed` 而非本钩子。
 */
Plugin::on('logout.before_destroy', function (array $info) use ($llWrite): void {
    if (empty($info['had_uid'])) return;   // 游客会话不记，否则刷出一堆噪声
    $llWrite([
        'user_id'  => (int)($info['uid'] ?? 0),
        'nickname' => (string)($info['nickname'] ?? ''),
        'ip'       => (string)($info['ip'] ?? ''),
        'method'   => 'password',
        'result'   => 'logout',
        'reason'   => 'manual',
    ]);
});

/**
 * session.destroyed：会话被服务端销毁（v1.1.12 新增）。
 * 目前唯一的触发点是指纹守卫（Cookie 被盗用后在别处重放）——
 * **这不是用户主动登出**，记 result=fail / reason=fingerprint，
 * 免得后台把「被踢」误看成「主动退出」，掩盖异常登录。
 * 载荷：reason / uid / had_uid / fp_saved / fp_now / ip
 */
Plugin::on('session.destroyed', function (array $info) use ($llWrite): void {
    if (empty($info['had_uid'])) return;
    $llWrite([
        'user_id'  => (int)($info['uid'] ?? 0),
        'ip'       => (string)($info['ip'] ?? ''),
        'method'   => 'password',
        'result'   => 'fail',
        'reason'   => (string)($info['reason'] ?? 'session_destroyed'),
    ]);
});

/* ===================== 后台 CSS 注入 ===================== */

// 后台页面已自动引入 ?action=assets&type=js，但 CSS 不会自动加载，
// 通过 page.head 注入 <link>（与 attachment-manager 同样做法）。
Plugin::on('page.head', function () {
    echo '<link rel="stylesheet" href="?action=assets&type=css&v=' . OWLSGO_ASSET_VERSION . '">';
});

/* ===================== 公共鉴权 ===================== */

/** 管理员鉴权：插件路由的公共守卫（核心不会替插件校验权限） */
$llGuard = function (array $ctx): void {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
};

/* ===================== 后台页面 ===================== */

Plugin::adminPage('login-logs', '登录日志', function () {
    return '<h2>登录日志</h2>'
        . '<p class="ow-admin-desc">记录登录成功、登录失败与主动登出（IP、浏览器、方式、时间）。'
        . '数据来自核心钩子 <code>login.after_verify</code> / <code>login.failed</code> / '
        . '<code>logout.before_destroy</code> / <code>session.destroyed</code>，'
        . '仅记录启用本插件之后的事件。「指纹不符」表示会话被服务端销毁（疑似 Cookie 被盗用），'
        . '与主动登出是两回事。系统每天自动清理 90 天前的旧日志（计划任务 <code>purge_old</code>，可在「计划任务」页启停）。</p>'
        // 统计卡（KPI 卡片由 admin.js 渲染，样式见 admin.css 的 .ow-ll-kpi）
        . '<div class="ow-card" id="owLLStatCard"></div>'
        // 筛选
        . '<div class="ow-card"><div class="ow-form-row">'
        . '<div class="ow-form-item" style="flex:1;min-width:120px"><label>用户 ID</label>'
        . '<input class="ow-input" id="owLLU" type="number" min="1" placeholder="精确用户 ID" onkeydown="if(event.key===\'Enter\')OwLL.load(1)"></div>'
        . '<div class="ow-form-item" style="flex:2;min-width:180px"><label>关键词</label>'
        . '<input class="ow-input" id="owLLQ" placeholder="昵称 / 邮箱 / 登录标识" onkeydown="if(event.key===\'Enter\')OwLL.load(1)"></div>'
        . '<div class="ow-form-item" style="min-width:130px"><label>结果</label>'
        . '<select class="ow-input" id="owLLR" onchange="OwLL.load(1)">'
        . '<option value="">全部</option>'
        . '<option value="ok">登录成功</option>'
        . '<option value="fail">登录失败</option>'
        . '<option value="logout">主动登出</option>'
        . '</select></div>'
        . '<div class="ow-form-item" style="min-width:150px"><label>开始日期</label>'
        . '<input class="ow-input" id="owLLD1" type="date"></div>'
        . '<div class="ow-form-item" style="min-width:150px"><label>结束日期</label>'
        . '<input class="ow-input" id="owLLD2" type="date"></div>'
        . '<button class="ow-btn ow-btn-primary" onclick="OwLL.load(1)">搜索</button>'
        . '<button class="ow-btn ow-btn-ghost" onclick="OwLL.resetFilter()">重置</button>'
        . '</div></div>'
        // 列表
        . '<div class="ow-card">'
        . '<div class="ow-ll-toolbar">'
        . '<button class="ow-btn ow-btn-danger" onclick="OwLL.clearOld()">清理 90 天前日志</button>'
        . '<span id="owLLStat" class="ow-ll-count"></span>'
        . '</div>'
        // 批量操作轮子（v1.2.1：与核心安全日志/群聊审核同款，由 admin.js 的 uiBatchBar 渲染）
        . '<div id="owLLBatch"></div>'
        // 横滚容器用核心 .ow-table-wrap（与核心后台表格同一套；OwAdmin 的自动包表
        // 观察器见到父级已是 ow-table-wrap 会跳过，不会套两层）
        . '<div class="ow-table-wrap"><table class="ow-table" id="owLLTable">'
        . '<tr><th style="width:32px"><input type="checkbox" id="owLLCheckAll" onchange="OwLL.toggleAll(this)"></th>'
        . '<th>ID</th><th>结果</th><th>用户 ID</th><th>昵称</th><th>邮箱</th>'
        . '<th>IP</th><th>方式</th><th>原因 / 登录标识</th><th>User-Agent</th><th>时间</th></tr>'
        . '</table></div>'
        . '<div id="owLLPager"></div>'
        . '</div>';
});

/* ===================== 列表查询（分页 + 筛选） ===================== */

Plugin::route('plugin_login_logs_list', function (array $ctx) use ($llGuard) {
    $llGuard($ctx);
    $post = $ctx['post'];
    $page   = max(1, (int)($post['page'] ?? 1));
    $psize  = max(1, min(100, (int)($post['psize'] ?? 20)));
    $uid    = (int)($post['uid'] ?? 0);
    $kw     = trim((string)($post['q'] ?? ''));
    $d1     = trim((string)($post['d1'] ?? ''));
    $d2     = trim((string)($post['d2'] ?? ''));
    // v1.1.12：结果筛选（ok / fail / logout），空 = 全部
    $result = trim((string)($post['result'] ?? ''));

    $where = ' WHERE 1=1';
    $args = [];
    if ($uid > 0) { $where .= ' AND user_id=?'; $args[] = $uid; }
    if ($kw !== '') {
        $where .= ' AND (nickname LIKE ? OR email LIKE ? OR identity LIKE ?)';
        $args[] = '%' . $kw . '%';
        $args[] = '%' . $kw . '%';
        $args[] = '%' . $kw . '%';   // 失败时昵称为空，identity 才是可搜的线索
    }
    if (in_array($result, ['ok', 'fail', 'logout'], true)) { $where .= ' AND result=?'; $args[] = $result; }
    // 日期段：按当日 0 点起、次日 0 点前（结束日期包含整天）
    if ($d1 !== '') {
        $t1 = strtotime($d1 . ' 00:00:00');
        if ($t1 !== false) { $where .= ' AND created_at>=?'; $args[] = (int)$t1; }
    }
    if ($d2 !== '') {
        $t2 = strtotime($d2 . ' 23:59:59');
        if ($t2 !== false) { $where .= ' AND created_at<=?'; $args[] = (int)$t2; }
    }

    $total = (int)DB::val("SELECT COUNT(*) FROM plugin_login_logs$where", $args);
    // 翻页越界收敛（v1.2.1）：批量删光当前页后再刷新，$page 可能超过总页数，
    // 不收敛会返回一张空表（用户以为记录全没了）。删哪些就停在本页看结果。
    $pages = max(1, (int)ceil($total / $psize));
    if ($page > $pages) $page = $pages;
    $offset = ($page - 1) * $psize;

    $rows = DB::all(
        "SELECT id, user_id, nickname, email, ip, user_agent, method, result, identity, reason, created_at
         FROM plugin_login_logs$where
         ORDER BY id DESC
         LIMIT $psize OFFSET $offset",
        $args
    );

    // 验证方式中文映射
    $methodCn = function (string $m): string {
        switch ($m) {
            case 'totp':     return '两步验证';
            case 'recovery': return '恢复码';
            case 'password': return '密码';
            default:         return $m ?: '未知';
        }
    };
    // v1.1.12：结果 + 原因中文映射
    $resultCn = function (string $r): string {
        switch ($r) {
            case 'ok':     return '登录成功';
            case 'fail':   return '登录失败';
            case 'logout': return '主动登出';
            default:       return $r ?: '未知';
        }
    };
    $reasonCn = function (string $r): string {
        switch ($r) {
            case 'fail':              return '账号或密码错误';
            case 'locked':            return '已临时锁定';
            case 'disabled':          return '账号被禁用';
            case 'manual':            return '用户主动';
            case 'fingerprint_mismatch': return '指纹不符（疑似会话被盗）';
            default:                  return $r ?: '';
        }
    };

    $list = [];
    foreach ($rows as $r) {
        $res = (string)($r['result'] ?? 'ok');
        $list[] = [
            'id'         => (int)$r['id'],
            'user_id'    => (int)$r['user_id'],
            'nickname'   => (string)$r['nickname'],
            'email'      => (string)$r['email'],
            'ip'         => (string)$r['ip'],
            'user_agent' => (string)$r['user_agent'],
            'method'     => (string)$r['method'],
            'method_cn'  => $methodCn((string)$r['method']),
            'result'     => $res,
            'result_cn'  => $resultCn($res),
            'identity'   => (string)($r['identity'] ?? ''),
            'reason'     => (string)($r['reason'] ?? ''),
            'reason_cn'  => $reasonCn((string)($r['reason'] ?? '')),
            'created_at' => (int)$r['created_at'],
            'time_text'  => date('Y-m-d H:i:s', (int)$r['created_at']),
        ];
    }

    Api::json([
        'ok'    => true,
        'data'  => $list,
        'total' => $total,
        'page'  => $page,
        'psize' => $psize,
        'pages' => $pages,
    ]);
});

/* ===================== 统计 ===================== */

Plugin::route('plugin_login_logs_stats', function (array $ctx) use ($llGuard) {
    $llGuard($ctx);
    $total  = (int)DB::val('SELECT COUNT(*) FROM plugin_login_logs');
    $users  = (int)DB::val('SELECT COUNT(DISTINCT user_id) FROM plugin_login_logs WHERE user_id>0');
    $today0 = strtotime(date('Y-m-d 00:00:00'));
    $today  = (int)DB::val("SELECT COUNT(*) FROM plugin_login_logs WHERE created_at>=? AND result='ok'", [$today0]);
    // v1.1.12：失败与登出也纳入统计。failToday 单独给出——「今天有多少人登录失败」
    // 是排查撞库时最先看的数字，埋在总数里等于没有。
    $fail      = (int)DB::val("SELECT COUNT(*) FROM plugin_login_logs WHERE result='fail'");
    $failToday = (int)DB::val("SELECT COUNT(*) FROM plugin_login_logs WHERE result='fail' AND created_at>=?", [$today0]);
    $logout    = (int)DB::val("SELECT COUNT(*) FROM plugin_login_logs WHERE result='logout'");
    // 指纹不符 = 会话被服务端销毁，疑似 Cookie 被盗用，单独拎出来（不该和「密码打错」混看）
    $fpMismatch = (int)DB::val("SELECT COUNT(*) FROM plugin_login_logs WHERE reason='fingerprint_mismatch'");
    Api::json([
        'ok'          => true,
        'total'       => $total,
        'users'       => $users,
        'today'       => $today,
        'today_str'   => date('Y-m-d', $today0),
        'fail'        => $fail,
        'fail_today'  => $failToday,
        'logout'      => $logout,
        'fp_mismatch' => $fpMismatch,
    ]);
});

/* ===================== 批量删除所选（敏感，v1.2.1） ===================== */

/**
 * 通用批量删除：与核心「安全日志 → admin_logs_batch」同一套模式
 * （下拉轮子勾选 → 确认框 → OwApi.secure 一次性票据 → 逐条参数化删除 → 留痕）。
 * 逐条删而不是一条 IN：一页最多 100 条，逐条足够快，且每条 rowCount() 都能
 * 准确统计「真正删掉了几条」（并发下别人可能已经删过同几条）。
 */
Plugin::route('plugin_login_logs_del', function (array $ctx) use ($llGuard) {
    $llGuard($ctx);
    $ids = array_values(array_unique(array_filter(
        array_map('intval', explode(',', (string)($ctx['post']['ids'] ?? '')))
    )));
    if (!$ids) Api::json(['ok' => false, 'msg' => '未选择日志']);
    // 防御性上限：正常一页 ≤100 条，超过即异常请求，拒绝而不是照删
    if (count($ids) > 500) Api::json(['ok' => false, 'msg' => '一次最多删除 500 条']);
    // v1.5.20：分块 IN 批量删除，别逐条 DELETE（一页最多 500 条 = 500 次 SQL）
    $ok = 0;
    foreach (array_chunk($ids, 200) as $chunk) {
        [$inSql, $inArgs] = DB::in('id', $chunk);
        $ok += DB::run("DELETE FROM plugin_login_logs WHERE $inSql", $inArgs)->rowCount();
    }
    // 删除动作本身留痕：谁在什么时候删了哪些（ids 全量记录，便于事后核对）
    Sec::log('plugin_login_logs_del', (string)($ctx['actor']['nickname'] ?? ''), ['ids' => $ids, 'ok' => $ok]);
    Api::json(['ok' => $ok > 0, 'msg' => '批量删除：成功 ' . $ok . ' 条']);
});
Plugin::sensitive('plugin_login_logs_del');   // 删除数据：敏感，强制 POST + 一次性票据

/* ===================== 清理旧日志（敏感） ===================== */

Plugin::route('plugin_login_logs_clear', function (array $ctx) use ($llGuard) {
    $llGuard($ctx);
    $days = (int)($ctx['post']['days'] ?? 90);
    if ($days < 1) $days = 90;
    $threshold = time() - $days * 86400;
    // 先统计待删条数，便于提示
    $cnt = (int)DB::val('SELECT COUNT(*) FROM plugin_login_logs WHERE created_at<?', [$threshold]);
    if ($cnt > 0) {
        DB::run('DELETE FROM plugin_login_logs WHERE created_at<?', [$threshold]);
    }
    Sec::log('admin_login_logs_clear', $ctx['actor']['nickname'], ['days' => $days, 'deleted' => $cnt]);
    Api::json(['ok' => true, 'msg' => "已清理 $cnt 条 $days 天前的日志", 'deleted' => $cnt]);
});
Plugin::sensitive('plugin_login_logs_clear');   // 删除日志：敏感（v1.0.91）

/* ===================== 资源注册 ===================== */

Plugin::asset('css', 'login-logs/admin.css');
Plugin::asset('js', 'login-logs/admin.js');

/* ===================== 计划任务（v1.1.13） ===================== */

/**
 * 每天清理一次 90 天前的登录日志。
 *
 * 之前只有后台的「清理 90 天前日志」按钮，靠管理员记得点；
 * 有了计划任务后日志表不会无限增长，也不用记着点按钮。
 * 保留手动按钮：管理员想立刻清就点，不必等调度。
 */
Plugin::cron('purge_old', 86400, function () {
    $threshold = time() - 90 * 86400;
    $cnt = (int)DB::val('SELECT COUNT(*) FROM plugin_login_logs WHERE created_at<?', [$threshold]);
    if ($cnt > 0) DB::run('DELETE FROM plugin_login_logs WHERE created_at<?', [$threshold]);
    return $cnt;
}, '清理 90 天前的登录日志（每天一次）');
