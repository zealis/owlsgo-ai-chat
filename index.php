<?php
/**
 * Owlsgo-Chat — 根目录统一入口
 * 纯原生 PHP 8.1+，无框架 / 无 Composer 依赖，支持 SQLite / MySQL / PostgreSQL。
 * 页面渲染与 AJAX API 统一由本文件分发（?page= / ?action=）。
 */
declare(strict_types=1);

// 版本号以根目录 VERSION 文件为准（历次发版只改 VERSION，此处不再硬编码，
// 避免 CSS/JS 缓存参数 ?v= 永远停在旧版本）；文件缺失时兜底 1.0.33
define('OWLSGO_VERSION', trim((string)@file_get_contents(__DIR__ . '/VERSION')) ?: '1.0.33');

// ⚠️ DevTools 探测请求短路（v1.0.115）：Chrome 打开开发者工具时会自动请求
// /.well-known/appspecific/com.chrome.devtools.json，该请求经 try_files 落入本入口，
// 曾在指纹守卫中被销毁会话 → 登录态丢失（「开 DevTools 就退出登录」）。
// 它是工具自身的探测请求，与本应用无关，直接 404 且不初始化任何会话。
if (strpos($_SERVER['REQUEST_URI'] ?? '', '/.well-known/') === 0) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":"not found"}';
    exit;
}

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '0');

$CFG = require __DIR__ . '/core/config.php';
date_default_timezone_set($CFG['timezone'] ?? 'Asia/Shanghai');

require __DIR__ . '/core/db.php';
require __DIR__ . '/core/security.php';
require __DIR__ . '/core/mail.php';
require __DIR__ . '/core/auth.php';
require __DIR__ . '/core/plugin.php';
require __DIR__ . '/core/chat.php';
require __DIR__ . '/core/notice.php';
require __DIR__ . '/core/upload.php';
require __DIR__ . '/core/upgrade.php';
require __DIR__ . '/core/admin.php';
// v1.2.37：第三方应用授权已剥离为插件 oauth-core（按需加载），核心不再常驻它的表与路由。

// v1.3.46 强制 HTTPS：必须排在**任何输出与会话初始化之前**。
//   明文 GET → 301 到 https；明文 POST（发言/登录/上传等交互）→ 403，不做重定向降级；
//   https 响应统一带 HSTS。放在这里而不是只写 vhost，是因为 phpStudy 面板会整文件
//   重写 conf/vhosts/*.conf，写在 vhost 里的跳转可能被抹掉。
//   trust_proxy：站点在「非本机反代」后面时才需要 true，否则客户端能伪造
//   X-Forwarded-Proto 绕过跳转（默认 false；本机 nginx→php-cgi 不需要开）。
Sec::enforceHttps(($CFG['trust_proxy'] ?? false) === true);

class Api
{
    public static function json(array $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

Sec::sessionStart($CFG);
Sec::init($CFG);

// 匿名会话密钥（登录前 API 签名用）：同时写入 cookie 备份，
// 避免 php-cgi 多进程下 PHP session 偶发丢失/重建导致签名对不上
if (empty($_COOKIE['owl_akey']) || !preg_match('/^[a-f0-9]{32}$/', (string)$_COOKIE['owl_akey'])) {
    $akey = Sec::clientKey();
    setcookie('owl_akey', $akey, [
        'expires' => time() + 86400 * 7, 'path' => '/', 'httponly' => true,
        'secure' => Sec::isHttps(), 'samesite' => 'Lax',
    ]);
    $_COOKIE['owl_akey'] = $akey;
}
// v1.3.5：统一走 Sec::anonKey() —— 会话被守卫销毁/GC 回收后它会按
// 「cookie 备份 → 新生成」兜底并回写，避免后续直接读 $_SESSION['anon_key'] 拿到 null
Sec::anonKey();

$LOCK = $CFG['data_dir'] . '/install.lock';
$installed = is_file($LOCK);

// ---------- 数据库初始化 ----------
$dbOk = true;
try {
    DB::init($CFG);
    if ($installed) {
        DB::migrate();
        DB::defaults();
    }
} catch (Throwable $e) {
    $dbOk = false;
    $dbErr = $e->getMessage();
}
// v1.1.13：回填会话指纹开关。⚠️ 必须放在 DB::init() 之后 ——
// Sec::fingerprint() 由更早的 sessionStart() 调用，那会儿 PDO 还是 null，
// 指纹函数内部不能查库（详见 core/security.php fingerprint() 的注释）。
Sec::loadFpOptions();

// 会话指纹守卫（v1.0.94）：登录 Cookie 被窃取后在其它浏览器 / 网络重放时销毁会话。
// 必须在 DB::init 之后（守卫拒绝时会写安全日志），且在认证（Auth::user）之前执行。
if ($installed && $dbOk) Sec::fingerprintGuard();

Upload::init($CFG);

// ---------- 当前访问者 ----------
$user = $installed && $dbOk ? Auth::user() : null;
$guest = null;
if ($installed && $dbOk && !$user) {
    $guest = Auth::guest();
}
$actor = Auth::actor($user, $guest);
if ($installed && $dbOk) Plugin::init($CFG['plugin_dir'], $CFG['data_dir'] . '/cache');
// v1.2.60：后台需要定位 data/logs（系统日志页），与 Upload::init / Plugin::init 同一模式注入配置
Admin::init($CFG);

// v1.2.42：用户活跃钩子 —— 等级信任插件据此把「今日已登录」计入每日任务。
// 为什么放在这里而不是只挂 login.after_verify：登录态是**会话**，用户开着页面
// 第二天继续用不会再走一次「登录」，只挂登录钩子会漏掉绝大多数活跃日。
// 每请求触发一次，插件内部按自然日去重；游客没有等级，不触发。
if ($installed && $dbOk && ($actor['kind'] ?? '') === 'user') {
    Plugin::fire('user.active', [$actor]);
}

$action = $_GET['action'] ?? '';
$page = $_GET['page'] ?? 'chat';

// ---------- 调试模式（v1.2.51） ----------
// 后台「系统设置 → 调试模式」开启后生效，仅用于排错：
//   ① 所有 PHP 报错（含 notice/warning）落 data/logs/debug.log；
//   ② 页面请求回显详细报错 + 致命错误兜底渲染（AJAX 请求只落日志不回显，
//      否则一段 PHP 报错混进 JSON 会把前端接口全部打挂，比不看报错更糟）；
//   ③ 出错细节可能暴露路径 / SQL / 配置，用完请及时在后台关闭。
if ($installed && $dbOk && DB::setting('debug_mode', '0') === '1') {
    define('OWLSGO_DEBUG', true);
    error_reporting(E_ALL);
    ini_set('log_errors', '1');
    @mkdir($CFG['data_dir'] . '/logs', 0775, true);
    ini_set('error_log', $CFG['data_dir'] . '/logs/debug.log');
    if ($action === '') ini_set('display_errors', '1');
    // 兜底：display_errors 接不住的致命错误（内存耗尽 / 解析错误等）在此补一刀，
    // 保证「出错页显示详细报错」在页面请求下一定成立。
    if ($action === '') {
        register_shutdown_function(function () {
            $e = error_get_last();
            if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
            if (!headers_sent()) http_response_code(500);
            echo '<div style="margin:20px;padding:14px 16px;border:2px solid #C41D1F;border-radius:6px;'
               . 'font:13px/1.7 Consolas,monospace;background:#FFF5F5;color:#8A1F1F;white-space:pre-wrap;word-break:break-all">'
               . '【调试模式 · 致命错误】' . htmlspecialchars($e['message'], ENT_QUOTES, 'UTF-8') . "\n"
               . '位置：' . htmlspecialchars($e['file'], ENT_QUOTES, 'UTF-8') . ' 第 ' . (int)$e['line'] . ' 行</div>';
        });
    }
}

// ================= 安装向导 =================
if (!$installed) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'install') {
        $cfgWritten = false;      // 是否已经把新配置落盘（决定失败时要不要退回）
        try {
            $driver = $_POST['driver'] ?? 'sqlite';
            if (!in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) throw new RuntimeException('非法数据库类型');
            // 改写 config.php
            $cfgFile = __DIR__ . '/core/config.php';
            $cfgOld = file_get_contents($cfgFile);
            if ($cfgOld === false) throw new RuntimeException('无法读取 core/config.php（检查文件权限）');
            $secret = bin2hex(random_bytes(32));
            $src = preg_replace("/'secret'\s*=>\s*'[^']*'/", "'secret'     => '$secret'", $cfgOld);
            $src = preg_replace("/'driver'\s*=>\s*'[a-z]*'/", "'driver'   => '$driver'", $src, 1);
            if ($driver !== 'sqlite') {
                $map = ['host' => $_POST['db_host'] ?? '127.0.0.1', 'port' => (int)($_POST['db_port'] ?? 3306),
                        'name' => $_POST['db_name'] ?? 'owlsgo', 'user' => $_POST['db_user'] ?? 'root',
                        'pass' => $_POST['db_pass'] ?? ''];
                foreach ($map as $k => $v) {
                    $src = preg_replace("/'$k'\s*=>\s*'[^']*'/", "'$k'     => '" . addslashes((string)$v) . "'", $src, 1);
                }
            }
            // 邮件发送不再属于核心（v1.0.81 移除 SMTP 配置）：安装后由邮件插件提供
            if (file_put_contents($cfgFile, $src) === false) throw new RuntimeException('无法写入 core/config.php（检查目录写权限）');
            $cfgWritten = true;
            // 写进去就必须让本进程读到它：OPcache 会把 config.php 的编译结果留在内存里
            // （validate_timestamps=0 时必然），不主动失效就会出现「新配置指向 MySQL、
            // 进程还在读旧的 SQLite」——安装把管理员写进了 A 库，站点查的是 B 库，
            // 表现就是「装完了但邮箱和用户 ID 都登不进去」。
            if (function_exists('opcache_invalidate')) @opcache_invalidate($cfgFile, true);

            $CFG = require $cfgFile;
            DB::init($CFG);
            DB::migrate();

            // 旧数据预检：重装时若 php-cgi 等常驻进程持有旧库句柄，「删除」数据库文件
            // 可能实际未生效（Windows 延迟删除 / 双库并行），随后管理员创建会撞
            // users.id=1 唯一约束。这里给出明确指引而不是裸报错。
            $existUsers = (int)DB::val('SELECT COUNT(*) FROM users');
            $existRooms = (int)DB::val('SELECT COUNT(*) FROM rooms');
            if ($existUsers > 0 || $existRooms > 0) {
                throw new RuntimeException('检测到数据库中已有安装数据（用户 ' . $existUsers . ' / 群聊 ' . $existRooms
                    . '）。请先在面板重启 PHP 释放数据库句柄，再删除 data 目录后重试安装。');
            }

            // 事务：建表种子 + 管理员账号一次性提交，杜绝「装一半」（如种子已插入但账号创建失败）的中间态
            $pdo = DB::pdo();
            $pdo->beginTransaction();
            try {
                DB::defaults();

                $n = trim($_POST['nickname'] ?? '');
            $e = Auth::normEmail((string)($_POST['email'] ?? ''));   // 管理员邮箱同样归一化
            $pw = (string)($_POST['password'] ?? '');
            // 取消用户名后，账号显示名就是昵称；规则与注册/改资料共用 Auth::checkNickname
            [$nickOk, $nickRes] = Auth::checkNickname($n, ['scene' => 'install']);   // 通过时返回归一化昵称，失败时返回错误文案
            if (!$nickOk) throw new RuntimeException($nickRes);
            if (!filter_var($e, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('管理员邮箱格式不正确');
            if (strlen($pw) < 6) throw new RuntimeException('管理员密码至少 6 位');
            DB::insert('users', [
                // 管理员固定占用 001（v1.0.37 起新用户 ID 为随机 3 位起步）
                'id' => 1,
                'nickname' => $nickRes, 'email' => $e,
                'password' => password_hash($pw, PASSWORD_DEFAULT),
                // v1.3.14：与普通注册一致，默认「小可爱」+ 随机一档
                'avatar' => '', 'avatar_type' => 'generated',
                'avatar_style' => Auth::AVATAR_DEFAULT_STYLE,
                'avatar_seed' => (string)random_int(1, Auth::AVATAR_SEED_COUNT),
                'role' => 'admin',
                'client_key' => Sec::clientKey(), 'status' => 1,
                'email_verified' => 1, 'created_at' => time(),
                'reg_ip' => Sec::ip(),   // v1.2.56：与 Auth::register 口径一致，记录安装者 IP
            ]);
                @mkdir($CFG['data_dir'], 0775, true);
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            file_put_contents($LOCK, date('c') . ' v' . OWLSGO_VERSION);
            Api::json(['ok' => true, 'msg' => '安装完成']);
        } catch (Throwable $e) {
            // 安装没成功就把配置退回原样 —— 绝不留「配置指向一个没装好的库」的中间态
            // （以前会：MySQL 建表中途 1064/1101 报错，config 已经改成了 MySQL，
            //   站点从此一直停在错误页，或像现场那样读着另一个库）。
            // 备份只放在内存里，不落 .bak 文件：config.php 含密钥，多一份副本就多一处泄露面。
            $rolled = null;
            if ($cfgWritten) {
                $rolled = @file_put_contents($cfgFile, $cfgOld) !== false;
                if ($rolled && function_exists('opcache_invalidate')) @opcache_invalidate($cfgFile, true);
            }
            Api::json(['ok' => false, 'msg' => '安装失败：' . $e->getMessage()
                . ($cfgWritten ? ($rolled ? '（配置已退回原样）' : '（配置退回失败，请手工恢复 core/config.php）') : '')]);
        }
    }
    renderInstall($dbOk ? '' : ($dbErr ?? ''));
    exit;
}

if (!$dbOk) {
    http_response_code(500);
    echo '<!doctype html><meta charset="utf-8"><title>数据库连接失败</title><body style="font-family:sans-serif;padding:40px">'
       . '<h2>数据库连接失败</h2><p>' . Sec::e($dbErr ?? '') . '</p><p>请检查 core/config.php 配置。</p></body>';
    exit;
}

// ================= API =================
if ($action !== '') {
    // 验证码图片（无需签名）
    if ($action === 'captcha') {
        $code = Sec::captcha();
        header('Content-Type: image/svg+xml');
        header('Cache-Control: no-store');
        echo Sec::captchaSvg($code);
        exit;
    }
    // 系统 cron 入口（供系统计划任务调用）
    // ⚠️ v1.1.13 起强制鉴权。原实现**完全无鉴权**，任何匿名访客都能触发插件代码，
    // 也能被当作压测入口。现在只接受：后台生成的令牌，或已登录的管理员。
    if ($action === 'cron') {
        $tok = (string)DB::setting('cron_token', '');
        $given = (string)($_GET['token'] ?? '');
        $ok = ($tok !== '' && $given !== '' && hash_equals($tok, $given))
           || (($actor['role'] ?? '') === 'admin');
        if (!$ok) Api::json(['ok' => false, 'msg' => '令牌无效或已过期'], 403);
        Api::json(['ok' => true, 'results' => Plugin::cronTick(true)]);
    }

    // 插件打包下载（GET 免签名：只读操作；鉴权在下方校验管理员会话）
    if ($action === 'admin_plugin_download') {
        if (($actor['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
        $name = (string)($_GET['name'] ?? '');
        $tmp = Plugin::packageZip($name);
        if (!$tmp) Api::json(['ok' => false, 'msg' => '打包失败（插件不存在或缺少 ZipArchive）'], 500);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-zA-Z0-9_-]/', '', $name) . '.zip"');
        header('Content-Length: ' . (string)filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    // 安全日志导出 CSV（GET 免签名：只读操作；鉴权在下方校验管理员会话）
    // 与插件打包下载同理——导出必须走浏览器直下，POST 拿不到响应头里的 Content-Disposition。
    if ($action === 'admin_logs_export') {
        if (($actor['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
        // 先把正文攒完整，再发头——header() 之前一旦有任何输出就会报「headers already sent」。
        // 单次上限 20000 行：够日常审计，又不至于把内存一次性打爆。
        $rowsE = DB::all('SELECT * FROM security_logs ORDER BY id DESC LIMIT 20000');
        $buf = fopen('php://temp', 'r+');
        fputcsv($buf, ['ID', '动作', '操作者', 'IP', '数据', '时间']);
        foreach ($rowsE as $rE) {
            $cells = [
                (string)$rE['id'],
                (string)$rE['action'],
                (string)($rE['actor'] ?? ''),
                (string)($rE['ip'] ?? ''),
                (string)($rE['data'] ?? ''),
                date('Y-m-d H:i:s', (int)$rE['created_at']),
            ];
            // CSV 注入防护：= + - @ 开头的单元格会被 Excel 当成公式执行（可拖外链、发请求），
            // 前置单引号强制按文本处理。fputcsv 只管引号转义，不做这层。
            foreach ($cells as $k => $c) {
                if ($k >= 1 && $c !== '' && in_array($c[0], ['=', '+', '-', '@', "\t", "\r"], true)) $cells[$k] = "'" . $c;
            }
            fputcsv($buf, $cells);
        }
        rewind($buf);
        $csv = stream_get_contents($buf);
        fclose($buf);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="security-logs-' . date('Ymd-His') . '.csv"');
        header('Cache-Control: no-store');
        echo "\xEF\xBB\xBF" . $csv;   // 前置 UTF-8 BOM，否则 Excel 打开中文全是乱码
        exit;
    }

    // 插件静态资源合并输出（GET 引用，无敏感数据、无写操作）：与 captcha 同样免签名放行，
    // 否则 <script src="?action=assets&type=js"> 无法在页面加载
    if ($action === 'assets') {
        $type = ($_GET['type'] ?? '') === 'js' ? 'js' : 'css';
        // plugin= / file=：切出某个插件的某个资源，供需要在 <head> 同步执行的引擎用。
        // 这里只做字符白名单；「是否启用」由 Plugin::renderAssets() 按启用清单判定。
        $ok = static fn(string $k): string => preg_match('/^[a-zA-Z0-9._-]+$/', (string)($_GET[$k] ?? '')) ? (string)$_GET[$k] : '';
        $only = $ok('plugin');
        $oneFile = $ok('file');
        header($type === 'js' ? 'Content-Type: text/javascript; charset=utf-8' : 'Content-Type: text/css; charset=utf-8');
        header('Cache-Control: no-store');
        echo Plugin::renderAssets($type, $only, $oneFile);
        exit;
    }

    // 签名密钥：登录用户/游客用其 client_key，匿名用会话 key（并兼容 cookie 备份 key）
    $signKeys = array_values(array_unique(array_filter([
        (string)($actor['key'] ?? ''),
        (string)($_SESSION['anon_key'] ?? ''),
        (string)($_COOKIE['owl_akey'] ?? ''),
    ])));
    if (!Sec::verifySignAny($signKeys, $action)) {
        Api::json(['ok' => false, 'msg' => '签名验证失败，请刷新页面'], 403);
    }

    // ---------- 敏感操作安全校验（v1.0.91） ----------
    // 覆盖：退出登录、删除内容（消息/贴纸/公告/敏感词）、群聊删除/恢复/批量处置、
    // 插件卸载，以及插件声明了 sensitive 的路由（用户禁用、附件删除、禁言、关闭两步验证等）。
    // 校验：仅接受 POST + 一次性操作票据（先调 ?action=ticket 签发，用后即焚，防重放/防劫持）。
    $SENSITIVE = [
        'logout', 'msg_delete', 'recall', 'sticker_del',
        'admin_room_del', 'admin_room_trash_undo', 'admin_room_batch',
        'admin_ann_del', 'admin_word_del', 'admin_plugin_uninstall',
        // v1.1.11 群成员变更：邀请/移出/重置邀请码都会改变谁能进群，
        // 与 ban-manager 的禁言同属「谁能看到什么」的边界，一律走一次性票据。
        'room_invite', 'room_remove_member', 'room_invite_code_reset',
        // v1.1.13 计划任务：启停 / 立即执行 / 重置令牌 / 清理日志都改动服务端状态，
        // 与管理员对话类操作同等敏感，一律走一次性票据。
        'admin_cron_toggle', 'admin_cron_run', 'admin_cron_token', 'admin_cron_logs_clear',
        // v1.1.24 联系人：删除会改变「谁能被我找到」，与群成员变更同属关系边界，走票据。
        // ⚠️ 添加（friend_add）**不走**票据：它只影响自己，且是纯新增无破坏性，
        //   走票据会让「加好友」多一次往返，徒增摩擦。
        'friend_remove',
        // v1.2.60 日志管理：批量删安全日志会抹掉审计流水、清空文件日志会抹掉排错线索，
        // 都属不可恢复操作，与 cron 日志清理同档，一律走一次性票据。
        'admin_logs_batch', 'admin_syslog_clear',
        // v1.3.39 在线升级：覆盖整站代码文件 = 全站最高危操作，必须 POST + 一次性票据。
        'admin_upgrade_apply', 'admin_upgrade_rollback',
        // v1.3.55 修改密码：拿到当前密码就能接管账号（后续可关掉两步验证），
        // 与登出同档，必须 POST + 一次性票据，防签名窗口期内的重放。
        'change_password',
    ];
    $isSensitive = in_array($action, $SENSITIVE, true) || Plugin::isSensitive($action);
    if ($isSensitive) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            Api::json(['ok' => false, 'msg' => '敏感操作仅接受 POST 提交'], 405);
        }
        if (!Sec::ticketVerify((string)($_POST['ticket'] ?? ''))) {
            Sec::log('sensitive_reject', $action, ['ip' => Sec::ip()]);
            Api::json(['ok' => false, 'msg' => '安全校验失败，请刷新页面后重试'], 403);
        }
    }

    // 关键：长轮询等只读动作提前释放会话锁，避免阻塞同会话的发消息等请求（PHP-FPM 生产环境必需）
    // room_join 需要写入密码房通行缓存；ticket 与敏感操作需要读写会话（票据签发/作废），必须保留会话
    // （change_password 等敏感动作由下面的 $isSensitive 一并覆盖，不再重复列）
    $keepSession = in_array($action, ['login', 'logout', 'register', 'reset', 'send_code', 'room_join', 'ticket'], true) || $isSensitive;
    if (!$keepSession) {
        session_write_close();
    }

    $p = fn($k, $d = '') => trim((string)($_POST[$k] ?? $d));

    switch ($action) {
        // ---------- 认证 ----------
        case 'send_code':
            // v1.3.55：三类验证码 —— 注册、找回密码、修改密码（个人设置）。
            // 换邮箱 / 注销账号还没有功能入口，将来做时直接用这里的同一套策略
            // （Mailer::sendCode 自带长度、后缀白名单、五路限流）。
            $type = in_array($p('type'), ['reset', 'chpwd'], true) ? $p('type') : 'register';
            // v1.3.63：发码是最该挡的入口 —— 它既烧发信通道（服务商封的是发件方），
            // 又会被拿来试探邮箱存在性。闸门排在存在性检查**之前**，
            // 否则等于让人机验证白过一遍，试探照旧。
            // chpwd 不拦：那张表单在个人设置弹窗里，没有组件位，硬拦会把改密码锁死
            //          （它本来就要验当前密码，是另一道门）。
            if ($type !== 'chpwd') ow_captcha_gate($type);
            $name = '';
            if ($type === 'chpwd') {
                // 修改密码：收件地址**只取会话用户自己那行**，绝不接受前端传来的邮箱。
                // 收客户端邮箱就等于开了「借别人的改码按钮给任意地址发信」的口子。
                if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '请先登录']);
                $me = DB::one('SELECT email, nickname FROM users WHERE id=?', [(int)$actor['id']]);
                $email = (string)($me['email'] ?? '');
                $name = (string)($me['nickname'] ?? '');
            } else {
                // 一律先归一化（小写）再查库：SQLite 是 BINARY 排序，
                // 不归一就会出现「注册填 Xxx@qq.com、登录输 xxx@qq.com → 查不到人」。
                $email = Auth::normEmail($p('email'));
                if ($type === 'register' && DB::one('SELECT id FROM users WHERE email=?', [$email])) {
                    Api::json(['ok' => false, 'msg' => '该邮箱已注册']);
                }
                if ($type === 'reset' && !DB::one('SELECT id FROM users WHERE email=?', [$email])) {
                    Api::json(['ok' => false, 'msg' => '该邮箱未注册']);
                }
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Api::json(['ok' => false, 'msg' => '邮箱格式不正确']);
            [$ok, $msg, $extra] = Mailer::sendCode($email, $type, [], ['name' => $name]);
            Api::json(['ok' => $ok, 'msg' => $msg] + (array)$extra);

        case 'register':
            // v1.3.63：只有「注册不要求邮箱验证码」时，人机验证才是唯一的机器人门，
            // 这时才在提交这一侧核销 token。要验证码的情况下闸门已经在上游
            // （send_code）过一次，这里再核销会撞上「一次性 token 用两遍」。
            if (!Mailer::needRegisterCode()) ow_captcha_gate('register');
            // 组装出生日期（年/月/日 → Y-m-d），未开启年龄限制时为空
            $by = (int)$p('birth_y');
            $bm = (int)$p('birth_m');
            $bd = (int)$p('birth_d');
            $birthdate = ($by && $bm && $bd) ? sprintf('%04d-%02d-%02d', $by, $bm, $bd) : '';
            [$ok, $msg] = Auth::register($p('nickname'), $p('email'), (string)($_POST['password'] ?? ''), $p('code'), $birthdate);
            Api::json(['ok' => $ok, 'msg' => $msg]);

        case 'login':
            $identity = $p('identity');
            $key = strtolower($identity) . '|' . Sec::ip();
            // 登录保护：先查锁定，再查是否需要图形验证码
            $lockSec = Sec::lockSeconds($key);
            if ($lockSec > 0) {
                Api::json(['ok' => false, 'msg' => '失败次数过多，已临时锁定 ' . (int)ceil($lockSec / 60) . ' 分钟', 'locked' => true]);
            }
            // v1.3.54：人机验证插件接管时，先做服务端二次校验。
            // 顺序很关键：必须排在图形码判定**之前** —— 接管状态下那一行根本没渲染，
            // 再要求用户填一个界面上不存在的框就把人彻底锁死在登录页了。
            $cvTakenOver = Plugin::collect('captcha.takeover') === '1';
            if ($cvTakenOver) {
                $cvOk = true; $cvMsg = '';
                Plugin::fire('captcha.enforce', [&$cvOk, &$cvMsg, ['scene' => 'login', 'post' => $_POST]]);
                if (!$cvOk) {
                    // 与「图形码填错」同口径计入失败，保证锁定依然可达（否则可以无限试密码）
                    Sec::loginFail($key);
                    Api::json(['ok' => false, 'msg' => $cvMsg !== '' ? $cvMsg : '请先完成人机验证']);
                }
            }
            if (!$cvTakenOver && Sec::needCaptcha($key)) {
                $code = $p('captcha');
                if ($code === '') {
                    Sec::loginFail($key);   // 验证码为空同样计入失败，保证锁定可达
                    Api::json(['ok' => false, 'msg' => '请填写图形验证码', 'captcha' => true]);
                }
                if (!Sec::checkCaptcha($code)) {
                    Sec::loginFail($key);   // 验证码错误也计入失败
                    Api::json(['ok' => false, 'msg' => '图形验证码错误', 'captcha' => true]);
                }
            }
            [$ok, $msg] = Auth::login($identity, (string)($_POST['password'] ?? ''));
            Api::json([
                'ok' => $ok, 'msg' => $msg,
                'captcha' => !$cvTakenOver && !$ok && Sec::needCaptcha($key),
                'locked' => !$ok && Sec::lockSeconds($key) > 0,
            ]);

        case 'reset':
            // 这里**不**过人机验证闸门：token 一次性，发码时已经核销过一遍，
            // 再核销必然失败；而这一侧还必须带邮箱验证码（码只能从已过关的发码口拿到）。
            [$ok, $msg] = Auth::resetPassword($p('email'), $p('code'), (string)($_POST['password'] ?? ''));
            Api::json(['ok' => $ok, 'msg' => $msg]);

        case 'change_password':
            // v1.3.55 个人设置改密码：只对**当前会话的用户**操作，
            // 用户 id 一律取 $actor，不接受前端传来的 uid（否则就是任意账号改密码）。
            if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '请先登录'], 403);
            [$ok, $msg] = Auth::changePassword(
                (int)$actor['id'],
                (string)($_POST['current'] ?? ''),
                (string)($_POST['password'] ?? ''),
                $p('code')
            );
            Api::json(['ok' => $ok, 'msg' => $msg]);

        case 'logout':
            Auth::logout();
            Api::json(['ok' => true]);

        case 'ticket':
            // 敏感操作一次性票据签发：需签名（走到这里说明已验签），写入会话
            Api::json(['ok' => true, 'ticket' => Sec::ticketIssue()]);

        // ---------- 聊天 ----------
        case 'rooms':
            Api::json(['ok' => true, 'data' => Chat::rooms($actor)]);

        // ---------- 联系人（v1.1.24） ----------
        // 好友列表。前端一次拿全，再按需向 signature 插件批量取签名。
        case 'friends':
            Api::json(['ok' => true, 'data' => Chat::friends($actor)]);

        case 'friend_add': {   // 加联系人：幂等（重复加返回明确错误，不插重行）
            $r = Chat::addFriend($actor, (int)$p('friend_id'));
            Api::json(['ok' => $r[0], 'msg' => $r[1]]);
        }

        case 'friend_remove':  // 删联系人：已在 $SENSITIVE，需一次性票据
            $r = Chat::removeFriend($actor, (int)$p('friend_id'));
            Api::json(['ok' => $r[0], 'msg' => $r[1]]);

        // ---------- 私聊会话（v1.1.0） ----------
        // v1.3.21：未读定位 —— 「上次已读位置之后的第一条未读」消息 id。
        // 前端进会话时据此定位滚动位置，并在右下角显示「N 条未读 ↓」按钮。
        case 'conv_unread_at':
            $pk = (string)$p('peer_key');
            Api::json([
                'ok' => true,
                'first_unread_id' => Chat::firstUnreadId($actor, $pk),
                'count' => Chat::unreadCountOf($actor, $pk),
            ]);

        // v1.3.19：标记会话已读（前端进入会话 / 窗口聚焦时调用）
        case 'conv_read':
            [$ok, $msg] = Chat::markRead($actor, (string)$p('peer_key'), (int)$p('last_id'));
            Api::json(['ok' => $ok, 'msg' => $msg]);

        case 'conversations':   // 会话列表：群聊 + 私聊聚合，置顶优先 + 按最后活跃时间倒序
            $list = Chat::conversations($actor);
            // total = 会话总数（群聊数 + 私聊会话数），侧栏「聊天」徽标用
            Api::json(['ok' => true, 'data' => $list, 'total' => count($list)]);

        case 'notices':         // v1.3.52 系统通知：取最近通知并整批标为已读（红点随之清零）
            // 游客没有账号，通知一律按 user_id 存 —— 返回空列表而不是 403，
            // 免得前端为一个「本来就没有」的状态走错误分支。
            // v1.3.56：连同模板表一起下发（texts）—— 通知正文改存 kind+参数后，
            // 前端要拿模板走语言包翻译，再填参数；只回 body 会让英文界面冒出一句中文。
            if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => true, 'data' => [], 'texts' => Notice::texts()]);
            Api::json(['ok' => true, 'data' => Notice::forUser((int)$actor['id']), 'texts' => Notice::texts()]);

        /* ---------- 私聊会话操作（v1.2.28，右侧栏四个入口的后端） ---------- */
        case 'dm_pin':          // 设为置顶 / 取消置顶（按当前状态取反）
            [$ok, $msg, $pinned] = Chat::togglePin($actor, (string)$p('peer_key'));
            Api::json(['ok' => $ok, 'msg' => $msg, 'pinned' => $pinned]);

        case 'dm_clear':        // 清空本机聊天记录（只写 message_hides，对方仍可见）
            $peer = Chat::dmPeerKey($actor, $p('peer'));
            if (!$peer) Api::json(['ok' => false, 'msg' => '私聊对象不合法']);
            [$ok, $msg, $n] = Chat::clearDmForMe($actor, $peer);
            Api::json(['ok' => $ok, 'msg' => $msg, 'cleared' => $n]);

        // ---------- 搜索（v1.2.31）：品牌区搜索图标弹窗 ----------
        // scope: current 当前会话 / people 找人·群 / messages 全站消息 / friends 联系人
        // v1.2.32：① 游客不可搜；② 服务端 10 秒 3 次限流（前端也有节流，这里是防绕过的第二道）
        case 'search':
            if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '游客不支持搜索'], 403);
            // scope 白名单：动态参数只认这四个值，其余一律回落 current
            $scope = (string)$p('scope', 'current');
            if (!in_array($scope, ['current', 'people', 'messages', 'friends'], true)) $scope = 'current';
            // 限流桶按「用户 + 范围」分桶：换范围点不会互相挤占
            if (!Sec::rateLimit('search', 'u' . (int)$actor['id'] . '|' . $scope, 10, 3)) {
                Api::json(['ok' => false, 'msg' => '搜索太频繁，请 10 秒后再试']);
            }
            // v1.2.34：把该范围的条数上限一并下发，前端据此提示「仅显示前 N 条」，
            // 免得用户以为搜全了（真被截断时不会有任何提示，是最容易引起误会的地方）
            $limits = ['current' => 50, 'people' => 30, 'messages' => 80, 'friends' => 30];
            $limit = $limits[$scope];
            $data = Chat::search($actor, $scope, (string)$p('q', ''), [
                'room_id' => (int)$p('room_id', '0'),
                'peer' => (string)$p('peer', ''),
            ]);
            Api::json([
                'ok' => true,
                'scope' => $scope,
                'limit' => $limit,
                'truncated' => count($data) >= $limit,
                'data' => $data,
            ]);

        case 'dm_history':      // 私聊历史（仅双方可见）
            $peer = Chat::dmPeerKey($actor, $p('peer'));
            if (!$peer) Api::json(['ok' => false, 'msg' => '私聊对象不合法']);
            // 一并回传对方资料：会话列表里可能还没有该项（如首次私聊），
            // 前端需要它来显示私聊页标题，否则只能显示占位文案
            $info = Chat::dmPeerInfo($peer[0], $peer[1]);
            if (!$info) Api::json(['ok' => false, 'msg' => '私聊对象不存在']);
            Api::json([
                'ok' => true,
                'peer' => ['kind' => $info['kind'], 'id' => $info['id'],
                           'name' => $info['name'], 'avatar' => $info['avatar']],
                // v1.3.21：from_id 表示「>= 该 id 往后取」（未读定位用），与 before_id 二选一
                'data' => Chat::dmHistory($actor, $peer, (int)$p('before_id', '0'), 30, (int)$p('from_id', '0')),
            ]);

        case 'dm_poll':         // 私聊增量轮询（长挂起）
            $peer = Chat::dmPeerKey($actor, $p('peer'));
            if (!$peer) Api::json(['ok' => false, 'msg' => '私聊对象不合法']);
            Api::json(['ok' => true] + Chat::dmPoll($actor, $peer, (int)$p('since_id', '0')));

        case 'room_join':
            $room = Chat::room((int)$p('room_id'));
            if (!$room) Api::json(['ok' => false, 'msg' => '群聊不存在']);
            if (!Chat::canEnter($room, $actor)) Api::json(['ok' => false, 'msg' => '无权进入该群聊']);
            if ($actor['kind'] === 'none') Api::json(['ok' => false, 'msg' => '请先登录', 'need_login' => true]);
            // 密码房：已持有有效通行授权则免密；管理员免密码。
            // 是否真的需要密码一律由服务端判定，前端只需先空密码尝试一次，返回 need_password 再弹窗。
            if ($room['type'] === 'password' && !Chat::roomPassCached((int)$room['id'])) {
                if ($actor['role'] !== 'admin' && !Chat::checkRoomPassword($room, $p('password'))) {
                    $empty = $p('password') === '';
                    if (!$empty) Sec::log('room_pass_fail', $actor['nickname'] ?? '', ['room' => (int)$room['id']]);
                    Api::json(['ok' => false, 'msg' => $empty ? '该房间需要密码' : '房间密码错误', 'need_password' => true]);
                }
            Chat::grantRoomPass((int)$room['id']);   // 管理员同样授予，避免每次点击都往返一次
        }
        // v1.2.27：join=1 = 用户在前台确认了「加入」→ 真正写入 room_members（幂等）。
        // 以前公开群点了就进、不产生成员关系，「所有成员」因此只能拿在线心跳凑数。
        // 游客（kind=guest）请求加入时服务端静默跳过 —— 游客不能成为成员（见 joinRoom）。
        $joined = false;
        if (($p('join') === '1' || $p('join') === 1) && $actor['kind'] === 'user') {
            [$okJoin] = Chat::joinRoom($room, $actor);
            $joined = $okJoin;
        }
        Api::json([
            'ok' => true,
            'room' => ['id' => (int)$room['id'], 'name' => $room['name']],
            'ttl' => Chat::passTtl(),
            // 回传最新成员口径：加入后前端可立即刷新「所有成员」，不用等下一次 poll
            'joined' => $joined,
            'is_member' => $actor['kind'] === 'user' && Chat::isMember($room, $actor),
        ] + Chat::allMembers($room, $actor));

        /* ---------- 群成员管理（v1.1.11「不公开群聊」） ----------
           身份口径：邀请对象一律用**数字用户 ID**，不用昵称（可重名）也不用邮箱。
           权限口径：邀请 = 本群成员（群主/成员/超管）；移出 = 仅群主或超管。
           与插件 $oaCanManage（群主+超管）刻意不同：成员邀请是「群内协作」，
           普通成员也能邀请他人，否则「成员相互邀请」无从谈起。 */
        case 'room_members':
            $room = Chat::room((int)$p('room_id'));
            if (!$room) Api::json(['ok' => false, 'msg' => '群聊不存在']);
            if (!Chat::canEnter($room, $actor)) Api::json(['ok' => false, 'msg' => '无权访问该群聊']);
            Api::json([
                'ok' => true,
                'is_public' => (int)($room['is_public'] ?? 1) === 1,
                'can_invite' => Chat::canInvite($room, $actor),
                'owner_id' => (int)($room['owner_id'] ?? 0),
                'invite_code' => $actor['kind'] === 'user' && Chat::isMember($room, $actor)
                    ? (string)($room['invite_code'] ?? '') : '',
                'data' => Chat::memberList($room),          // 成员管理弹窗用（可移出）
                // v1.2.27：切群时前端用它立即渲染「所有成员」区块，不等下一次 poll
                // （poll 最长 20 秒才返回，否则切完群成员区会空白二十秒）
                'online_status' => Chat::canSeeOnlineStatus($actor, (int)$room['id']),
            ] + Chat::allMembers($room, $actor)
              + ['is_member' => $actor['kind'] === 'user' && Chat::isMember($room, $actor)]);

        case 'room_invite':
            $room = Chat::room((int)$p('room_id'));
            if (!$room) Api::json(['ok' => false, 'msg' => '群聊不存在']);
            if (!Chat::canEnter($room, $actor)) Api::json(['ok' => false, 'msg' => '无权访问该群聊']);
            if (!Chat::canInvite($room, $actor)) Api::json(['ok' => false, 'msg' => '只有本群成员可以邀请'], 403);
            // 游客不能被邀请：身份随会话消亡，无法审计，也不能作为成员身份标识
            if ($actor['kind'] !== 'user') Api::json(['ok' => false, 'msg' => '请先登录后再邀请成员'], 403);
            $target = (int)$p('user_id');
            if ($target <= 0) Api::json(['ok' => false, 'msg' => '请填写有效的用户 ID']);
            [$ok, $msg] = Chat::inviteMember($room, $actor, $target);
            if ($ok) Sec::log('room_invite', $actor['nickname'], ['room' => (int)$room['id'], 'to' => $target]);
            Api::json(['ok' => $ok, 'msg' => $msg]);

        case 'room_remove_member':
            $room = Chat::room((int)$p('room_id'));
            if (!$room) Api::json(['ok' => false, 'msg' => '群聊不存在']);
            if (!Chat::canEnter($room, $actor)) Api::json(['ok' => false, 'msg' => '无权访问该群聊']);
            $target = (int)$p('user_id');
            [$ok, $msg] = Chat::removeMember($room, $actor, $target);
            if ($ok) Sec::log('room_member_del', $actor['nickname'], ['room' => (int)$room['id'], 'uid' => $target]);
            Api::json(['ok' => $ok, 'msg' => $msg]);

        case 'room_invite_code_reset':
            $room = Chat::room((int)$p('room_id'));
            if (!$room) Api::json(['ok' => false, 'msg' => '群聊不存在']);
            $uid = (int)($actor['id'] ?? 0);
            if ($actor['kind'] !== 'user'
                || !((int)($room['owner_id'] ?? 0) === $uid || $actor['role'] === 'admin')) {
                Api::json(['ok' => false, 'msg' => '仅群主或超级管理员可重置邀请码'], 403);
            }
            $code = Chat::resetInviteCode($room, $actor);
            Sec::log('room_invite_code', $actor['nickname'], ['room' => (int)$room['id']]);
            Api::json(['ok' => true, 'msg' => '邀请码已重置，旧链接立即失效', 'code' => $code]);

        case 'poll':
            $roomId = (int)$p('room_id');
            $room = Chat::room($roomId);
            if (!Chat::roomAccessOk($room, $actor)) {
                Api::json([
                    'ok' => false,
                    'msg' => '无权访问该群聊',
                    'need_password' => $room['type'] === 'password',
                ]);
            }
            if ($actor['kind'] === 'none') Api::json(['ok' => false, 'msg' => '请先登录', 'need_login' => true]);
            Api::json(['ok' => true] + Chat::poll($actor, $roomId, (int)$p('since', '0')));

        case 'history':
            $roomId = (int)$p('room_id');
            $room = Chat::room($roomId);
            // 密码房必须持有有效通行授权，否则任何人都能绕过密码读取历史
            if (!Chat::roomAccessOk($room, $actor)) {
                Api::json([
                    'ok' => false,
                    'msg' => '无权访问该群聊',
                    'need_password' => $room['type'] === 'password',
                ]);
            }
            Api::json(['ok' => true, 'data' => Chat::history($actor, $roomId, (int)$p('before', '0'), 30, (int)$p('from_id', '0'))]);

        case 'send':
            [$ok, $msg, $id, $msgRow] = Chat::send($actor, (int)$p('room_id'), $p('type', 'text'), (string)($_POST['content'] ?? ''), [
                'to_user_id' => $p('to_user_id'), 'to_guest_id' => $p('to_guest_id'), 'to_nickname' => $p('to_nickname'),
                // 引用快照（前端「引用」功能）：{nick,text} 的 JSON 字符串，服务端会再校验截断
                'quote' => (string)($_POST['quote'] ?? ''),
            ]);
            // v1.3.23：msg_row = 服务端 pack 后的完整消息行，供前端**立即本地回显**
            // （原先只回 id，前端只能等长轮询把自己的消息带回来，最长要等 20 秒）
            Api::json(['ok' => $ok, 'msg' => $msg, 'id' => $id ?? null,
                'msg_row' => $msgRow ?? null]);

        // ---------- 前台编辑群聊信息（列表 ⋮ 菜单，管理员/房主） ----------
        case 'room_update':
            if ($actor['kind'] !== 'user') Api::json(['ok' => false, 'msg' => '请先登录'], 403);
            // v1.1.11：is_public 缺省不传时保持原值（null = 不改），
            // 避免旧客户端保存群资料时把公开性意外改回默认值。
            $pubRaw = $_POST['is_public'] ?? null;
            $pub = ($pubRaw === null || $pubRaw === '') ? null : (($pubRaw === '0') ? 0 : 1);
            [$ok, $msg] = Chat::updateRoom($actor, (int)$p('id'), (string)($_POST['name'] ?? ''), (string)($_POST['description'] ?? ''), (string)($_POST['avatar'] ?? ''), $pub, [
                // v1.3.11：群头像支持生成式（generated）与「回到默认」（default → 回落创建者头像）
                'avatar_type'  => (string)($_POST['avatar_type'] ?? ''),
                'avatar_style' => (string)($_POST['avatar_style'] ?? ''),
                'avatar_seed'  => (string)($_POST['avatar_seed'] ?? ''),
            ]);
            // v1.3.11：回传算好的头像 URL，前端就地更新 cfg.rooms，不必重拉列表
            Api::json(['ok' => $ok, 'msg' => $msg,
                'url' => $ok ? Chat::roomAvatarUrl(Chat::room((int)$p('id'))) : '']);

        // ---------- 删除消息（内容右键「删除」） ----------
        // v1.2.4：删除 = **一律只在本机隐藏**（写 message_hides），任何身份都是、
        // 含超级管理员；别人照常看得到、换设备不生效。真正让内容对所有人消失的
        // 是「撤回」（recall，物理删行 + 删附件）。
        // 返回第三项 scope 恒为 'hide'，仅为兼容旧前端保留。
        case 'msg_delete':
            [$ok, $msg, $scope] = Chat::deleteMessage($actor, (int)$p('id'));
            Api::json(['ok' => $ok, 'msg' => $msg, 'scope' => $scope]);

        // ---------- 撤回 = 真正的删除（全局生效、不可恢复） ----------
        case 'recall':
            [$ok, $msg] = Chat::recall($actor, (int)$p('id'));
            Api::json(['ok' => $ok, 'msg' => $msg]);

        // ---------- 上传（v1.2.41 收窄） ----------
        // v1.3.11：这里**只剩表情贴纸**。头像上传已移入附件上传插件
        //   （路由 plugin_attachment_manager_upload_avatar）——
        //   头像是最典型的「可换可不换」功能，插件停用后仍能选生成式头像，
        //   不会被用户当成「网站坏了」。
        case 'upload':
            $kind = $p('kind', 'sticker');
            if (!in_array($kind, ['sticker'], true)) {
                Api::json(['ok' => false, 'msg' => '头像与附件请使用附件上传功能'], 400);
            }
            if ($actor['kind'] !== 'user') Api::json(['ok' => false, 'msg' => '请先登录']);
            if (empty($_FILES['file'])) Api::json(['ok' => false, 'msg' => '未接收到文件']);
            // v1.2.42：上传闸门（等级信任插件按等级限制头像/贴纸 —— 见设计文档「五次功能解锁」）
            $allowUpload = true; $uploadReason = '';
            Plugin::fire('upload.guard', [&$allowUpload, &$uploadReason, $kind, $actor]);
            if (!$allowUpload) Api::json(['ok' => false, 'msg' => $uploadReason !== '' ? $uploadReason : '当前等级无法使用该上传功能']);
            [$ok, $urlOrMsg] = Upload::handle($_FILES['file'], $kind);
            Api::json($ok ? ['ok' => true, 'url' => $urlOrMsg] : ['ok' => false, 'msg' => $urlOrMsg]);

        // v1.2.44：建群前置查询。前端打开建群弹窗时先问一次，把「名额 / 是否需付费 /
        //   为什么不能建」显示出来，而不是让用户填完一堆表单再被一句报错打回。
        case 'room_create_gate':
            if ($actor['kind'] !== 'user') Api::json(['ok' => false, 'msg' => '请先登录']);
            if ($actor['role'] !== 'admin' && DB::setting('room_create_allow', '1') !== '1') {
                Api::json(['ok' => false, 'msg' => '站点未开放用户创建群聊']);
            }
            $gate = ['allowed' => true, 'reason' => '', 'cost' => 0, 'free' => true,
                     'quota' => 0, 'used' => 0, 'level' => 0, 'min_room_level' => 0];
            Plugin::fire('room.create.gate', [&$gate, $actor]);
            $gate['points'] = (int)DB::val('SELECT points FROM users WHERE id=?', [(int)$actor['id']]);
            Api::json(['ok' => true, 'gate' => $gate]);

        // 用户创建群聊（管理员始终可创建；普通用户受后台开关与名额/积分限制）
        // ⚠️ v1.2.43 定案：建群**不再消耗积分**，也**不受任何插件规则约束**
        //   （等级插件的「10 级才能建群 / 建群数量上限」已取消），
        //   唯一约束就是后台「允许用户创建群聊」开关 room_create_allow。
        //   原 v1.2.42 的 room.create.guard 钩子随之移除 —— 留着它等于
        //   给「插件再次限制建群」留了口子，与定案冲突。
        case 'room_create':
            if ($actor['kind'] !== 'user') Api::json(['ok' => false, 'msg' => '请登录后再创建群聊']);
            if ($actor['role'] !== 'admin' && DB::setting('room_create_allow', '1') !== '1') {
                Api::json(['ok' => false, 'msg' => '站点未开放用户创建群聊']);
            }
            $name = trim($p('name'));
            /* v1.2.19：群名称改为**可选**。留空时自动命名为「<昵称>的群聊」。
               原先强制 2-30 字符，等于逼用户先想好名字才能建群 ——
               而多数人建群时只想拉人开聊，名字是次要的。

               ⚠️ 默认名**不再走敏感词过滤**：昵称是已受控来源（注册/改昵称时
               已过滤过），重复过滤会把含敏感词的昵称打成「***的群聊」，
               用户看到只觉得莫名其妙（昵称本身在消息里也照样显示）。
               用户**自己填**的名字仍照常过滤。
               ⚠️ 也要截断到 30（与下方校验上限对齐）：但**截昵称、不截后缀** ——
               先给「的群聊」留足 3 个字，再截昵称。若反过来写成
               mb_substr(nick.'的群聊', 0, 30)，昵称一长（>27 字）后缀就被整个切掉，
               剩下 30 个「长」而不是「xxx的群聊」，与需求形态不符。 */
            if ($name === '') {
                $name = mb_substr($actor['nickname'], 0, 27) . '的群聊';
            } else {
                if (mb_strlen($name) < 2 || mb_strlen($name) > 30) {
                    Api::json(['ok' => false, 'msg' => '群名称需 2-30 个字符（留空则自动命名为「'
                        . $actor['nickname'] . '的群聊」）']);
                }
                Chat::filterText($name, 'room_name', $actor);   // 敏感词过滤
            }
            $desc = trim((string)($_POST['description'] ?? ''));
            Chat::filterText($desc, 'room_desc', $actor);
            $type = $p('type');
            if (!in_array($type, ['public', 'password', 'role'], true)) Api::json(['ok' => false, 'msg' => '非法的群类型']);
            $minRole = in_array($p('min_role'), ['guest', 'member', 'vip', 'admin'], true) ? $p('min_role') : 'guest';
            if ($type === 'password' && $p('password') === '') Api::json(['ok' => false, 'msg' => '密码群必须设置密码']);
            if ($type === 'role' && $minRole !== 'guest' && Auth::roleLevel($actor['role']) < Auth::roleLevel($minRole)) {
                Api::json(['ok' => false, 'msg' => '最低角色不能高于你自己']);
            }
            // v1.1.11 公开性开关：与 type 正交。缺省为 1（公开），
            // 兼容旧前端/旧客户端；非法值一律归一为 1，不接受「不明确的真」。
            $isPublic = ($p('is_public') === '0') ? 0 : 1;
            // v1.1.14 全局总闸：是否允许普通用户创建**不公开**群聊（管理员始终可）。
            if ($isPublic === 0 && $actor['role'] !== 'admin'
                && DB::setting('room_private_create_allow', '1') !== '1') {
                Api::json(['ok' => false, 'msg' => '站点已关闭「创建仅邀请群聊」，请创建公开群聊']);
            }
            // v1.2.44：建群闸门 + 名额/积分判定。
            //   $dec 形状：['allowed'=>bool,'reason'=>'','cost'=>int,'free'=>bool,'quota'=>int,'used'=>int]
            //   - 名额没用完：allowed=true, cost=0（免费）
            //   - 名额用完 / 等级不够**且允许花积分**：allowed=true, cost>0
            //   - 不允许且不能付费：allowed=false + reason（前端原样展示这个原因）
            //   ⚠️ cost 只由插件给出，核心不自己定价；管理员在插件侧一律 cost=0。
            $dec = ['allowed' => true, 'reason' => '', 'cost' => 0, 'free' => true,
                    'quota' => 0, 'used' => 0, 'level' => 0, 'min_room_level' => 0];
            Plugin::fire('room.create.gate', [&$dec, $actor]);
            if (!$dec['allowed']) {
                Api::json(['ok' => false, 'msg' => (string)($dec['reason'] ?: '当前无法创建群聊')]);
            }
            $cost    = max(0, (int)($dec['cost'] ?? 0));
            $uid     = (int)$actor['id'];
            $charged = 0;
            if ($cost > 0 && $actor['role'] !== 'admin') {
                // 条件更新：余额不足时影响 0 行，同时杜绝并发下扣成负数
                $st = DB::run('UPDATE users SET points=points-? WHERE id=? AND points>=?', [$cost, $uid, $cost]);
                if ($st->rowCount() === 0) {
                    $pts = (int)DB::val('SELECT points FROM users WHERE id=?', [$uid]);
                    Api::json(['ok' => false, 'msg' => '积分不足，本次创建需要 ' . $cost . ' 积分（当前 ' . $pts . '）']);
                }
                $charged = $cost;
            }
            while (true) {
                try {
                    $id = DB::insert('rooms', [
                        'id' => Auth::nextId('rooms'),
                        'name' => $name,
                        'slug' => 'g' . time() . bin2hex(random_bytes(3)),
                        'type' => $type,
                        'password' => $type === 'password' ? $p('password') : null,
                        'min_role' => $minRole,
                        'owner_id' => $uid,
                        // v1.1.11 公开性开关：与 type 正交。缺省为 1（公开），
                        // 兼容旧前端/旧客户端；非法值一律归一为 1，不接受「不明确的真」。
                        'is_public' => $isPublic,
                        // 不公开群建好即带邀请码，成员管理弹窗才能给出可复制的邀请链接
                        'invite_code' => Chat::newInviteCode($isPublic),
                        'description' => mb_substr($desc, 0, 200),   // 已过滤（text.filter 钩子）
                        'status' => 1,
                        'created_at' => time(),
                    ]);
                    break;
                } catch (Throwable $e) {
                    if (++$roomAttempts >= 5) {
                        // 付费建群（超名额）失败必须退还，否则用户白扣分
                        if ($charged > 0) DB::run('UPDATE users SET points=points+? WHERE id=?', [$charged, $uid]);
                        throw $e;
                    }
                }
            }
            // v1.2.44：建群成功事件（等级插件据此统计「建群次数」到活跃趋势）
            Plugin::fire('room.created', [$id, $actor, $charged]);
            Sec::log('room_create', $actor['nickname'], ['id' => $id, 'name' => $name, 'cost' => $charged]);
            Api::json(['ok' => true, 'msg' => $charged > 0
                ? '群聊已创建（消耗 ' . $charged . ' 积分）'
                : '群聊已创建', 'id' => $id, 'name' => $name, 'cost' => $charged]);
        // 且强制 attachment，避免 html/svg 之类被浏览器内联解析导致 XSS
        case 'file_download':
            // 下载走 GET 链接（带签名），这里直接读 $_GET
            $msg = DB::one('SELECT * FROM messages WHERE id=?', [(int)($_GET['id'] ?? 0)]);
            if (!$msg || $msg['type'] !== 'file') Api::json(['ok' => false, 'msg' => '文件不存在']);
            if (Chat::isDmRow($msg)) {
                // 私聊附件（room_id=0）：Chat::room(0) 不存在，必须走私聊双方可见性判定，
                // 否则私聊文件一律 403（v1.2.1 前私聊发不出文件，此处一并补齐下载通道）
                if (!Chat::dmVisible($msg, $actor)) Api::json(['ok' => false, 'msg' => '无权访问']);
            } else {
                $room = Chat::room((int)$msg['room_id']);
                if (!$room || !Chat::roomAccessOk($room, $actor)) Api::json(['ok' => false, 'msg' => '无权访问']);
            }
            $info = json_decode((string)$msg['content'], true);
            $abs  = Upload::fileAbs((string)($info['path'] ?? ''));
            if (!$abs) Api::json(['ok' => false, 'msg' => '文件不存在']);
            // v1.2.42：下载完成钩子（等级信任插件据此记「今日下载文件」任务）。
            // 放在权限与文件存在性校验**之后** —— 失败的下载不该算完成任务。
            // 上传者下载自己的文件由插件自行排除（设计文档：禁止刷任务）。
            if (($actor['kind'] ?? '') === 'user') {
                Plugin::fire('file.downloaded', [(int)$actor['id'], (int)$msg['id'], (int)($msg['user_id'] ?? 0)]);
            }
            $name = preg_replace('/[\r\n"]/', '', (string)($info['name'] ?? 'file'));
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . filesize($abs));
            header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
            header('X-Content-Type-Options: nosniff');
            readfile($abs);
            exit;

        // ---------- 贴纸 ----------
        case 'stickers':
            Api::json(['ok' => true, 'data' => Upload::stickers($actor)]);

        case 'sticker_add':
            [$ok, $msg] = Upload::addSticker($actor, $p('url'));
            Api::json(['ok' => $ok, 'msg' => $msg]);

        case 'sticker_del':
            [$ok, $msg] = Upload::delSticker($actor, (int)$p('id'));
            Api::json(['ok' => $ok, 'msg' => $msg]);

        // ---------- 资料 ----------
        case 'profile_save':
            if (!$user) Api::json(['ok' => false, 'msg' => '请先登录']);
            [$ok, $msg] = Auth::updateProfile($user, $p('nickname'), $p('avatar'));
            Api::json(['ok' => $ok, 'msg' => $msg]);

        // ---------- 头像（v1.3.11：生成式 + 上传落库） ----------
        // 上传本身走附件插件，这里只负责把「最终选择」写进 users 表。
        case 'avatar_save':
            if (!$user) Api::json(['ok' => false, 'msg' => '请先登录']);
            $t = $p('avatar_type', 'generated');
            [$ok, $msg] = Auth::setAvatar($user, $t, $p('avatar'), $p('avatar_style'), $p('avatar_seed'));
            if (!$ok) Api::json(['ok' => false, 'msg' => $msg], 400);
            // 回传算好的 URL，前端就地替换，不刷新页面
            $row = DB::one('SELECT id, avatar, avatar_type, avatar_style, avatar_seed FROM users WHERE id=?', [(int)$user['id']]);
            Api::json(['ok' => true, 'msg' => $msg, 'url' => Auth::avatarUrlFor($row, (string)$user['id'])]);

        // 风格清单（头像设置弹窗的「浏览插图」网格）
        case 'avatar_styles':
            Api::json(['ok' => true, 'data' => Auth::avatarStyleOptions((int)$p('seed'))]);

        // v1.3.14：选中风格后的二级界面 —— 该风格固定的 45 个变体
        case 'avatar_variants':
            Api::json(['ok' => true, 'style' => Auth::avatarStyle($p('avatar_style')),
                'data' => Auth::avatarVariants($p('avatar_style'))]);

        // v1.3.24：右侧「所有成员」区的成员搜索（游客一律拒绝 —— 与 ?action=search 同口径）
        case 'members_search':
            if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '游客不支持搜索成员'], 403);
            $sRoomId = (int)$p('room_id');
            $sRoom = Chat::room($sRoomId);
            if (!$sRoom) Api::json(['ok' => false, 'msg' => '群聊不存在']);
            // 与 ?action=search 同样的限流桶思路：连打会拖库（这是全站昵称 LIKE）
            if (!Sec::rateLimit('members_search', 'u' . (int)$actor['id'] . '|' . $sRoomId, 10, 3)) {
                Api::json(['ok' => false, 'msg' => '搜索太频繁，请稍后再试'], 429);
            }
            Api::json(['ok' => true, 'data' => Chat::searchMembers($actor, $sRoomId, (string)$p('q'))]);

        case 'user_card':
            // v1.3.16：带上生成式字段并把 avatar 换成**最终 URL** ——
            // 原样下发 avatar 列的话，资料卡拿到的是空串或 uploads 路径，
            // 于是显示的还是旧的首字色块 / identicon。
            $u = DB::one('SELECT id,nickname,role,title,avatar,avatar_type,avatar_style,avatar_seed,points,created_at,last_login FROM users WHERE id=?', [(int)$p('id')]);
            if (!$u) Api::json(['ok' => false, 'msg' => '用户不存在']);
            $u['avatar'] = Auth::avatarUrlFor($u, (string)$u['id']);
            // v1.1.24：带出「我是否已把 TA 加为联系人」，前端据此在「加为联系人 / 删除联系人」
            // 之间二选一（不给两个都能点、其中必报错的按钮）。
            // 游客无联系人概念，直接 false。
            $u['is_friend'] = false;
            if (($actor['kind'] ?? '') === 'user') {
                $fid = (int)$p('id');
                if ($fid !== (int)$actor['id']) {
                    $has = DB::val('SELECT id FROM friends WHERE user_id=? AND friend_id=?', [(int)$actor['id'], $fid]);
                    $u['is_friend'] = (int)$has > 0;
                }
            }
            // v1.2.42：插件可向资料卡补充字段（等级信任插件在这里挂上等级 Lv.N）。
            // 用 fire + 引用参数：$u 是**按引用**传的，插件改写它即可随 user_card 一起下发，
            // 前端无需再打一次接口。游客的 user_card 请求不会走到这里（本 case 要求用户存在）。
            Plugin::fire('user.card', [&$u, $actor]);
            Api::json(['ok' => true, 'data' => $u]);

        // ---------- IP 归属地：核心不再内置实现（原依赖第三方 ip-api.com），
        // 改由插件通过 ip.location 钩子提供；无插件响应时给出明确提示 ----------
        case 'ip_loc':
            if ($actor['role'] !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
            $ip  = trim($p('ip'));
            $loc = '';
            Plugin::fire('ip.location', [&$loc, $ip, $actor]);
            if ($loc === '') Api::json(['ok' => false, 'msg' => '未安装归属地查询插件']);
            Api::json(['ok' => true, 'loc' => $loc]);

        // ---------- 插件静态资源（已在签名门禁前放行，此处保留占位） ----------
    }

    // 管理后台动作
    if (strpos($action, 'admin_') === 0) Admin::handle($action, $actor);

    // 插件路由
    // ⚠️ files 必须传：v1.2.41 起「聊天附件上传」移入附件上传插件，
    //   插件要处理 multipart 上传就必须能拿到 $_FILES（此前 ctx 只有 actor/post）。
    $r = Plugin::dispatch($action, ['actor' => $actor, 'post' => $_POST, 'files' => $_FILES]);
    if ($r !== null) Api::json(is_array($r) ? $r : ['ok' => true, 'data' => $r]);

    Api::json(['ok' => false, 'msg' => '未知操作'], 404);
}

// ================= 页面 =================
// v1.2.44：插件前台页（Plugin::page() 注册）优先命中，之后才是核心页面。
// ⚠️ 必须先查插件再走 switch —— 放在 default 分支里会被 renderChat 吞掉
//    （任何未知 page 都会落到聊天页，?page=level 就会变成「进了聊天但啥也没有」）。
$frontPages = Plugin::frontPages();
if (isset($frontPages[$page])) {
    renderPluginPage($page, $frontPages[$page], $actor, $user);
} else {
    switch ($page) {
        case 'login':    renderAuth('login'); break;
        case 'register': renderAuth('register'); break;
        case 'forgot':   renderAuth('forgot'); break;
        case 'admin':
            // 用 ?? 兜底：未安装 / 数据库未连通时 Auth::actor() 返回的数组没有 role 键，
            // 直接取会抛 Undefined array key warning（并污染 debug.log）
            if (($actor['role'] ?? '') !== 'admin') { header('Location: ?page=login'); exit; }
            renderAdmin($actor);
            break;
        default:
            if (!$user && DB::setting('guest_browse', '1') !== '1') { header('Location: ?page=login'); exit; }
            if (!$user && !$guest) $guest = Auth::ensureGuest();
            renderChat(Auth::actor($user, $guest), $user, $guest);
    }
}

/**
 * 渲染插件注册的前台页（v1.2.44）。
 *
 * 布局刻意做**极简**：一条顶栏（站点名 + 返回聊天）+ 插件自己的内容。
 * 不复用聊天页那套三栏结构 —— 插件页的用途是「看一份说明」，
 * 套上侧栏与输入栏反而让人以为还能发消息。
 *
 * ⚠️ 同样要引入合并资源包并打 OWLSGO_ASSETS_JS_EMITTED 标记：
 *    插件页也可能需要前端脚本（等级页的任务进度刷新就用到）。
 */
function renderPluginPage(string $slug, array $pg, array $actor, ?array $user): void
{
    pageHead((string)$pg['title']);
    $site = Sec::e(DB::setting('site_name', 'Owlsgo-Chat'));
    echo '<body class="ow-page-body">'
       . '<div class="ow-page-top">'
       . '<span class="ow-page-brand">' . $site . '</span>'
       . '<a class="ow-page-back" href="?page=chat">返回聊天</a>'
       . '</div>'
       . '<main class="ow-page-main">';
    try {
        echo (string)call_user_func($pg['fn'], $actor, $user);
    } catch (Throwable $e) {
        Sec::log('plugin_page_error', $slug, ['error' => $e->getMessage()]);
        echo '<div class="ow-card">页面加载失败，请联系管理员。</div>';
    }
    echo '</main>'
       . '<div class="ow-toast" id="owToast" style="display:none"></div>'
       . '<script src="assets/js/chat.js?v=' . OWLSGO_VERSION . '"></script>';
    define('OWLSGO_ASSETS_JS_EMITTED', true);
    echo '<script src="?action=assets&type=js"></script>'
       . '</body></html>';
}

// ================= 站点地址 =================
/**
 * 站点根地址（结尾无斜杠）。
 *
 * 取值优先级：
 *   ① 后台「系统设置 → 固定网站地址」手填值（多虚拟主机 / 容器反代 / 多域名时显式指定）；
 *   ② 留空则按当前请求自动识别：协议（兼容 X-Forwarded-Proto）+ 主机（兼容
 *      X-Forwarded-Host）+ 非标准端口 + 子目录部署路径。
 *
 * @param bool $manualOnly true 时只返回「手动配置」的值（自动识别结果不生效）。
 *                         用于会写入数据库的场景（如上传 URL），避免自动识别
 *                         误判（拿到内网地址 / http）把不可访问的绝对地址存进消息。
 * @return string 形如 https://chat.example.com（子目录部署为 https://example.com/chat）；取不到返回 ''
 */
function ow_site_url(bool $manualOnly = false): string
{
    $manual = trim((string)DB::setting('site_url', ''));
    if ($manual !== '') return rtrim($manual, '/');
    if ($manualOnly) return '';

    // 协议：反代场景优先信任 X-Forwarded-Proto，其次 HTTPS 标记
    $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($proto === '') $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $proto = explode(',', $proto)[0];                     // 多层反代可能用逗号分隔
    $proto = in_array($proto, ['http', 'https'], true) ? $proto : 'http';


    // 主机：优先信任反代头；X-Forwarded-Host 与 Host 本身已含端口（容器反代场景下
    // 服务器内部端口与对外端口往往不一致，绝不能用 SERVER_PORT 去补，否则会拼出
    // 形如 https://chat.example.com:80 的错误地址）。仅当两者都缺失时退回 SERVER_NAME。
    $host = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
    if ($host === '') $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    $fromServerName = false;
    if ($host === '') { $host = trim((string)($_SERVER['SERVER_NAME'] ?? '')); $fromServerName = true; }
    if ($host === '') return '';
    $host = trim(explode(',', $host)[0]);
    if (!preg_match('/^[a-zA-Z0-9._\[\]:-]+$/', $host)) return '';   // 主机头防注入

    // 仅 SERVER_NAME 兜底时才补非标准端口
    if ($fromServerName && strpos($host, ':') === false) {
        $port = (int)($_SERVER['SERVER_PORT'] ?? 0);
        if ($port > 0 && !(($proto === 'http' && $port === 80) || ($proto === 'https' && $port === 443))) {
            $host .= ':' . $port;
        }
    }

    // 子目录部署：去掉脚本名，保留目录部分（如 /chat/index.php → /chat）
    $base = '/';
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script !== '') $base = rtrim(str_replace('\\', '/', dirname($script)), '/.');
    return $proto . '://' . $host . $base;
}

/**
 * 生成站内绝对地址。
 * @param string $path 站内相对路径（如 uploads/image/a.jpg 或 ?page=chat）
 * @param bool   $manualOnly 同 ow_site_url()：仅允许手动配置的地址参与拼接
 * @return string 有站点地址时返回绝对 URL；否则原样返回 $path（保持相对路径行为不变）
 */
function ow_abs_url(string $path, bool $manualOnly = true): string
{
    $base = ow_site_url($manualOnly);
    if ($base === '') return $path;
    if (preg_match('#^https?://#i', $path)) return $path;              // 已是绝对地址
    return $base . '/' . ltrim($path, '/');
}

// ================= 页面渲染函数 =================
/** 自研线性图标（零依赖 inline SVG，stroke 风格） */
function ow_icon(string $name, int $size = 18): string
{
    $paths = [
        'menu'   => '<line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="17" x2="20" y2="17"/>',
        'users'  => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 19c1-3.5 3.5-5 6.5-5s5.5 1.5 6.5 5"/><circle cx="17" cy="9" r="2.5"/><path d="M16 14.5c2.5.3 4.5 1.8 5.5 4.5"/>',
        'smile'  => '<circle cx="12" cy="12" r="9"/><path d="M8.5 14.5c1 1.5 2.2 2.2 3.5 2.2s2.5-.7 3.5-2.2"/><line x1="9" y1="9.5" x2="9" y2="10.5"/><line x1="15" y1="9.5" x2="15" y2="10.5"/>',
        'image'  => '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><circle cx="9" cy="10" r="1.8"/><path d="M4 17l4.5-4.5 3.5 3.5 3-3 5 5"/>',
        'bell'   => '<path d="M6 10a6 6 0 0 1 12 0c0 4 1.5 5.5 2 6H4c.5-.5 2-2 2-6z"/><path d="M10 19a2.2 2.2 0 0 0 4 0"/>',
        'bell-off' => '<path d="M8 6.5A6 6 0 0 1 18 10c0 4 1.5 5.5 2 6h-3"/><path d="M6.2 8.5C6.1 9 6 9.5 6 10c0 4-1.5 5.5-2 6h11"/><path d="M10 19a2.2 2.2 0 0 0 4 0"/><line x1="4" y1="4" x2="20" y2="20"/>',
        'user'   => '<circle cx="12" cy="8" r="4"/><path d="M4.5 20c1.2-4 4-6 7.5-6s6.3 2 7.5 6"/>',
        'chat'   => '<path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v8a2.5 2.5 0 0 1-2.5 2.5H9l-5 4z"/>',
        'mute'   => '<path d="M8 6.5A6 6 0 0 1 18 10c0 4 1.5 5.5 2 6h-3M6.2 8.5C6.1 9 6 9.5 6 10c0 4-1.5 5.5-2 6h11"/><line x1="4" y1="4" x2="20" y2="20"/>',
        'ban'    => '<circle cx="12" cy="12" r="9"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'mega'   => '<path d="M3 11v3l4 .5V10.5z"/><path d="M7 10.5L18 5v13l-11-4.5"/><path d="M9 15.5V18a2 2 0 0 0 4 .5"/>',
        'send'   => '<path d="M3.5 12L21 4l-7.5 17-2.5-7z"/>',
        // 回形针：整体内收一点（scale 0.82），否则 16px 下显得比旁边图标壮
        'paperclip' => '<g transform="scale(0.82) translate(2.6 2.6)"><path d="M16.5 7.5l-7 7a3.5 3.5 0 0 0 5 5l7-7a5.5 5.5 0 0 0-8-8L6 12a7.5 7.5 0 0 0 11 11"/></g>',
        'file'   => '<path d="M13 3.5H7a1.5 1.5 0 0 0-1.5 1.5v14A1.5 1.5 0 0 0 7 20.5h10a1.5 1.5 0 0 0 1.5-1.5V9z"/><path d="M13 3.5V9h5.5"/>',
        'download' => '<path d="M12 4v11"/><path d="M7.5 11L12 15.5 16.5 11"/><path d="M4.5 19.5h15"/>',
        // 拖拽手柄：两条斜线
        'resize'  => '<path d="M5 13l7-7"/><path d="M10 15l7-7"/>',
        'plus'    => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'puzzle' => '<path d="M9 4h6v3.5a2 2 0 1 0 4 .5V4h1v6h-3.5a2 2 0 1 0 .5 4H20v6h-6v-3.5a2 2 0 1 0-4 .5V20H4v-6h3.5a2 2 0 1 0-.5-4H4V4h5z" transform="scale(0.9) translate(1 1)"/>',
        'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        'gear'   => '<circle cx="12" cy="12" r="3"/><path d="M12 2.5v3M12 18.5v3M4.6 4.6l2.1 2.1M17.3 17.3l2.1 2.1M2.5 12h3M18.5 12h3M4.6 19.4l2.1-2.1M17.3 6.7l2.1-2.1"/>',
        'lock'   => '<rect x="5" y="10.5" width="14" height="9.5" rx="2"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/>',
        'close'  => '<line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/>',
        'logout' => '<path d="M14 4H6.5A1.5 1.5 0 0 0 5 5.5v13A1.5 1.5 0 0 0 6.5 20H14"/><path d="M10 12h10M17 8.5l3.5 3.5-3.5 3.5"/>',
        'sound'  => '<path d="M4 9.5v5h3.5L13 19V5L7.5 9.5z"/><path d="M16 9a4.5 4.5 0 0 1 0 6"/>',
        // 展开箭头：菜单分类的折叠指示
        'chevron' => '<polyline points="6.5 9.5 12 15 17.5 9.5"/>',
        // 竖排三点（v1.1.1）：群聊信息入口，替代原「在线成员」人形图标
        'more-v' => '<circle cx="12" cy="5" r="1.7" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.7" fill="currentColor" stroke="none"/><circle cx="12" cy="19" r="1.7" fill="currentColor" stroke="none"/>',
        // v1.2.31：品牌区搜索图标（替代原竖三点菜单）
        'search' => '<circle cx="11" cy="11" r="6.6"/><path d="M20.2 20.2l-4.5-4.5"/>',
    ];
    $d = $paths[$name] ?? $paths['chat'];
    return '<svg class="ow-ico" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
}

/**
 * 年龄限制开启时，渲染出生日期选择（年/月/日三个下拉，兼容不支持 date 类型的老浏览器）
 * 未开启（0）时返回空字符串
 */
function ageFieldHtml(): string
{
    $min = (int)DB::setting('min_register_age', 0);
    if ($min <= 0) return '';
    $yNow = (int)date('Y');
    $ys = '<option value="">年</option>';
    for ($y = $yNow; $y >= $yNow - 100; $y--) $ys .= '<option value="' . $y . '">' . $y . '</option>';
    $ms = '<option value="">月</option>';
    for ($m = 1; $m <= 12; $m++) $ms .= '<option value="' . $m . '">' . $m . '</option>';
    $ds = '<option value="">日</option>';
    for ($d = 1; $d <= 31; $d++) $ds .= '<option value="' . $d . '">' . $d . '</option>';
    return '<div class="ow-form-item"><label>出生日期</label>'
        . '<div class="ow-birth-row"><select class="ow-input" name="birth_y" required>' . $ys . '</select>'
        . '<select class="ow-input" name="birth_m" required>' . $ms . '</select>'
        . '<select class="ow-input" name="birth_d" required>' . $ds . '</select></div>'
        . '<p style="font-size:12px;color:var(--ow-text-sub);margin-top:4px">注册需年满 ' . $min . ' 周岁（按出生日期精确计算）。</p></div>';
}

/**
 * 人机验证闸门（v1.3.63）：注册 / 找回密码 / 发验证码 与登录走同一套钩子。
 *
 * 只在 captcha-verify 插件**接管**时生效；没接管直接返回，核心行为一字不变
 * （注册页本来就没有图形码，接管失败时不该凭空多出一道门）。
 *
 * ⚠️ 调用位置必须在任何业务校验之前：放在「该邮箱已注册 / 未注册」之后，
 *    就等于让人机验证白过一遍——攻击者拿无限次请求去试探邮箱存在性，
 *    闸门要挡的是这种试探，不是替它排队。
 *
 * @param string $scene 与表单里渲染的组件场景一致（register / reset / login）
 */
function ow_captcha_gate(string $scene): void
{
    if (Plugin::collect('captcha.takeover') !== '1') return;
    $ok = true; $msg = '';
    Plugin::fire('captcha.enforce', [&$ok, &$msg, ['scene' => $scene, 'post' => $_POST]]);
    if (!$ok) Api::json(['ok' => false, 'msg' => $msg !== '' ? $msg : '请先完成人机验证']);
}

/** 邮箱后缀白名单 + 长度提示（注册 / 找回密码页用，未限制时返回空串） */
function ow_mail_suffix_tip(): string
{
    $p = Mailer::policy();
    $list = Mailer::allowedDomains();
    if (!$list) return '';
    return ' 仅接受以下邮箱后缀：' . implode('、', $list) . '。';
}

/** 页面语言（v1.3.44）：cookie ow_lang（登录页切换器写）优先，回落后台默认语言。
 *  只输出合法 code；zh 或非法值 = 不翻译。实际翻译由 assets/js/i18n.js 引擎 + 语言包插件完成。
 *  ⚠️ 「没带 cookie」和「cookie 里写的是 zh」是两回事：后者是访客在切换器里**主动选了中文**，
 *     若一并回落到后台默认语言，站点默认设成英文后就没有任何访客能切回中文。 */
function ow_lang(): string
{
    $c = isset($_COOKIE['ow_lang']) ? trim((string)$_COOKIE['ow_lang']) : '';
    if ($c === '') $c = trim((string)DB::setting('ow_lang_default', 'zh'));
    return preg_match('/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/', $c) ? $c : 'zh';
}

function pageHead(string $title): void
{
    // 动态页禁止缓存：页面内含会话密钥，缓存旧页会导致提交时签名对不上
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    // v1.2.32：全站 noindex —— 聊天内容/昵称/私聊页面一律不进搜索引擎索引。
    // meta 与响应头同时给：meta 覆盖主流爬虫，X-Robots-Tag 对会忽略 meta 的爬虫也有效。
    header('X-Robots-Tag: noindex, nofollow, noarchive', true);
    $site = Sec::e(DB::setting('site_name', 'Owlsgo-Chat'));
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<meta name="robots" content="noindex, nofollow, noarchive, nosnippet">'
       . '<title>' . Sec::e($title) . ' - ' . $site . '</title>'
       . '<link rel="icon" href="assets/img/logo.svg" type="image/svg+xml">'
       . owThemeBootstrap()   // v1.3.25：先定主题再加载样式，避免首屏闪一下浅色
       . '<link rel="stylesheet" href="assets/css/owlsgo.css?v=' . OWLSGO_VERSION . '">';
    // v1.3.51：色系引擎不再是核心文件 —— 改由 color-schemes 插件在 page.head 注入。
    // 该钩子在样式表之后，但注入的是**阻塞脚本**，仍在首帧绘制前执行完，不会闪色。
    Plugin::fire('page.head');
    echo '</head>';
}

/**
 * 主题预置脚本（v1.3.25）。
 *
 * ⚠️ 必须**内联在 <head> 且在样式表之前**：主题的 data-theme 要在首次绘制前就写好。
 * 若放到 chat.js 里（body 末尾执行），深色用户每次刷新都会先闪一帧浅色 ——
 * 就是常说的 FOUC / 主题闪烁。这是深浅色切换最容易忽略的一步。
 *
 * 逻辑与前端 applyTheme() 保持一致（auto 由 matchMedia 解析），
 * 但这里是 ES5 且不能依赖任何已加载的模块，所以单独写一份精简版。
 */
function owThemeBootstrap(): string
{
    return '<script>(function(){try{'
        . "var m=localStorage.getItem('owl_theme');"
        . "if(m!=='light'&&m!=='dark'&&m!=='auto')m='auto';"
        . "var d=(m==='auto')?(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'):m;"
        . "document.documentElement.setAttribute('data-theme',d);"
        . "document.documentElement.style.colorScheme=d;"
        . '}catch(e){}})();</script>';
}

function renderInstall(string $err): void
{
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>安装 Owlsgo-Chat</title>'
       . owThemeBootstrap()
       . '<link rel="stylesheet" href="assets/css/owlsgo.css?v=' . OWLSGO_VERSION . '"></head>'
       . '<body class="ow-auth-body"><div class="ow-auth-card" style="max-width:520px">'
       . '<div class="ow-auth-logo"><img src="assets/img/logo.svg" alt="Owlsgo-Chat"><h1>安装 Owlsgo-Chat</h1><p>纯原生 PHP · 零依赖 · v' . OWLSGO_VERSION . '</p></div>'
       . ($err ? '<div class="ow-alert ow-alert-error">数据库连接失败：' . Sec::e($err) . '（SQLite 模式无需配置，可直接继续）</div>' : '')
       . '<form id="owInstallForm">'
       . '<div class="ow-form-item"><label>数据库类型</label><select name="driver" class="ow-input" onchange="document.getElementById(\'owDbMore\').style.display=this.value===\'sqlite\'?\'none\':\'block\'">'
       . '<option value="sqlite">SQLite（零配置，推荐）</option><option value="mysql">MySQL</option><option value="pgsql">PostgreSQL</option></select></div>'
       . '<div id="owDbMore" style="display:none">'
       . '<div class="ow-form-item"><label>数据库主机</label><input class="ow-input" name="db_host" value="127.0.0.1"></div>'
       . '<div class="ow-form-item"><label>端口</label><input class="ow-input" name="db_port" value="3306"></div>'
       . '<div class="ow-form-item"><label>数据库名</label><input class="ow-input" name="db_name" value="owlsgo"></div>'
       . '<div class="ow-form-item"><label>数据库用户</label><input class="ow-input" name="db_user" value="root"></div>'
       . '<div class="ow-form-item"><label>数据库密码</label><input class="ow-input" type="password" name="db_pass"></div></div>'
       . '<div class="ow-form-item"><label>管理员昵称</label><input class="ow-input" name="nickname" required placeholder="2-20 个字符"></div>'
       . '<div class="ow-form-item"><label>管理员邮箱</label><input class="ow-input" type="email" name="email" required></div>'
       . '<div class="ow-form-item"><label>管理员密码</label><input class="ow-input" type="password" name="password" required></div>'
       . '<button type="submit" class="ow-btn ow-btn-primary ow-btn-block">开始安装</button>'
       . '<div id="owInstallMsg" class="ow-form-msg"></div></form></div>'
       . '<script>document.getElementById("owInstallForm").onsubmit=function(e){e.preventDefault();'
       . 'var f=new FormData(this);var x=new XMLHttpRequest();'
       . 'x.open("POST","?action=install",true);x.onreadystatechange=function(){if(x.readyState===4){'
       . 'try{var r=JSON.parse(x.responseText);if(r.ok){document.getElementById("owInstallMsg").innerHTML="<span style=\"color:var(--ow-green)\">安装成功，正在跳转...</span>";setTimeout(function(){location.href="?page=login"},800);}'
       . 'else{document.getElementById("owInstallMsg").innerHTML="<span style=\"color:var(--ow-red)\">"+r.msg+"</span>";}}catch(_){}}};x.send(f);};</script>'
       . '</body></html>';
}

function renderAuth(string $mode): void
{
    pageHead(['login' => '登录', 'register' => '注册', 'forgot' => '找回密码'][$mode]);
    $titles = ['login' => '欢迎回来', 'register' => '创建账号', 'forgot' => '找回密码'];
    echo '<body class="ow-auth-body" data-lang="' . ow_lang() . '"><div class="ow-auth-card">'
       . '<div class="ow-auth-logo"><img src="assets/img/logo.svg" alt="Owlsgo-Chat"><h1>' . $titles[$mode] . '</h1>'
       . '<p>' . Sec::e(DB::setting('site_name', 'Owlsgo-Chat')) . '</p></div>';
    if ($mode === 'login') {
        // 从注册页跳转而来：提示注册成功、需手动登录（注册不自动登录）
        $regTip = isset($_GET['registered'])
            ? '<p style="color:var(--ow-green);font-size:13px;margin:0 0 10px">注册成功，请使用注册邮箱或用户 ID 登录。</p>'
            : '';
        // v1.3.54：人机验证插件（captcha-verify）接管时，SVG 图形码那一行**整块不输出**
        // —— 不是 display:none。留着它会让前端仍有机会提交 captcha 字段，
        // 而服务端已经改由插件判定，两边口径不一致。
        // 组件非空即代表插件已启用且配置齐全（判定在插件的 owCVActive() 里）。
        $cvHtml = Plugin::collect('captcha.form', [['scene' => 'login']]);
        echo '<form class="ow-auth-form" data-mode="login">'
           . $regTip
           . Sec::signField(Sec::anonKey(), 'login')
           . '<div class="ow-form-item"><label>邮箱或用户 ID</label><input class="ow-input" name="identity" required autocomplete="username" placeholder="注册邮箱或用户 ID"></div>'
           . '<div class="ow-form-item"><label>密码</label><input class="ow-input" type="password" name="password" required autocomplete="current-password"></div>'
           . ($cvHtml !== '' ? $cvHtml
               : '<div class="ow-form-item" id="owCaptchaRow" style="display:none"><label>图形验证码</label>'
                 . '<div class="ow-captcha-row"><input class="ow-input" name="captcha"><img src="?action=captcha" id="owCaptchaImg" alt="验证码" title="点击刷新"></div></div>')
           . '<button class="ow-btn ow-btn-primary ow-btn-block" type="submit">登 录</button><div class="ow-form-msg"></div></form>'
           . '<div class="ow-auth-links"><a href="?page=register">注册账号</a><a href="?page=forgot">忘记密码</a><a href="?page=chat">返回聊天</a></div>';
    } elseif ($mode === 'register') {
        // 是否要求邮箱验证由后台设置决定：关闭时不再显示验证码输入框与发码按钮
        $needMail = Mailer::needRegisterCode();
        // v1.3.63：注册页也要有人机验证组件（插件接管时才非空）。
        // 场景名要与后端 ow_captcha_gate('register') 对得上 —— 组件的隐藏字段
        // 由各家 SDK 自己注入到**所在表单**，服务端只认原生字段名，不认场景；
        // 场景只用于前端区分同一页上的多个组件。
        $cvHtml = Plugin::collect('captcha.form', [['scene' => 'register']]);
        echo '<form class="ow-auth-form" data-mode="register">'
           . Sec::signField(Sec::anonKey(), 'register')
           . '<div class="ow-form-item"><label>昵称</label><input class="ow-input" name="nickname" required placeholder="2-20 个字符，支持中英文"></div>'
           . '<div class="ow-form-item"><label>邮箱</label>'
           . ($needMail
               ? '<div class="ow-captcha-row"><input class="ow-input" type="email" name="email" required>'
                 . '<button type="button" class="ow-btn ow-btn-ghost" data-sendcode="register">发验证码</button></div>'
               : '<input class="ow-input" type="email" name="email" required>')
           . '<p style="font-size:12px;color:var(--ow-text-sub);margin-top:4px">'
           . ($needMail ? '注册需要邮箱验证码。' : '当前未开启邮箱验证，邮箱仅用于找回密码。')
           . ow_mail_suffix_tip()
           . '</p></div>'
           . ($needMail ? '<div class="ow-form-item"><label>邮箱验证码</label><input class="ow-input" name="code" required></div>' : '')
           // 年龄限制：开启时要求选择出生日期（年/月/日，兼容不支持 date 类型的老浏览器）
           . ageFieldHtml()
           . '<div class="ow-form-item"><label>密码</label><input class="ow-input" type="password" name="password" required placeholder="至少 6 位"></div>'
           . $cvHtml
           . '<button class="ow-btn ow-btn-primary ow-btn-block" type="submit">注 册</button><div class="ow-form-msg"></div></form>'
           . '<div class="ow-auth-links"><a href="?page=login">已有账号，去登录</a><a href="?page=chat">返回聊天</a></div>';
    } else {
        // 找回密码的唯一凭证就是邮箱验证码：开关关掉、或根本没装/没配邮件插件时，
        // 与其留一张「点发验证码只会报错」的死表单，不如直接说明走管理员。
        // ⚠️ 这一侧**不做降级**（与改密码相反）：改密码还有「当前密码」这道真凭证，
        //    而这里验证码就是全部 —— 一降级变成「知道邮箱就能改别人密码」的接管漏洞。
        // 只换表单内容、不改页面收尾 —— 收尾那段（i18n/chat/插件包/OwAuth.init）
        // 复制两份迟早跟登录页不同步。
        if (!Mailer::deliverable()) {
            $form = '<p style="color:var(--ow-text-sub);font-size:13px;line-height:1.7;margin:0 0 14px">'
                . '站点当前不可用邮箱验证码（未开启，或未配置能发信的邮件插件），无法自助找回密码。请联系管理员在后台处理。</p>';
        } else {
            // v1.3.63：找回密码页也放组件 —— 但闸门只挂在「发验证码」上，不挂在这里。
            // 因为 token 是**一次性**的：一次 solve 只能被服务端核销一次，
            // 若发码用掉它、重置时再核销一遍就必然失败（表现为「验证过了却说我没验证」）。
            // 而提交这一侧本来就必须带邮箱验证码，那张码又只能从已过关的发码口拿到，
            // 所以这里再要一遍人机验证既多余、又会把自己锁死。
            $cvHtml = Plugin::collect('captcha.form', [['scene' => 'reset']]);
            $form = '<form class="ow-auth-form" data-mode="reset">'
               . Sec::signField(Sec::anonKey(), 'reset')
               . '<div class="ow-form-item"><label>注册邮箱</label><div class="ow-captcha-row"><input class="ow-input" type="email" name="email" required>'
               . '<button type="button" class="ow-btn ow-btn-ghost" data-sendcode="reset">发验证码</button></div>'
               . '<p style="font-size:12px;color:var(--ow-text-sub);margin-top:4px">' . ow_mail_suffix_tip() . '</p></div>'
               . '<div class="ow-form-item"><label>邮箱验证码</label><input class="ow-input" name="code" required></div>'
               . '<div class="ow-form-item"><label>新密码</label><input class="ow-input" type="password" name="password" required></div>'
               . $cvHtml
               . '<button class="ow-btn ow-btn-primary ow-btn-block" type="submit">重置密码</button><div class="ow-form-msg"></div></form>';
        }
        echo $form;
        echo '<div class="ow-auth-links"><a href="?page=login">返回登录</a></div>';
    }
    echo '</div><script src="assets/js/i18n.js?v=' . OWLSGO_VERSION . '"></script>'
       . '<script src="assets/js/chat.js?v=' . OWLSGO_VERSION . '"></script>';
    // v1.3.54：登录页也要加载插件 JS 包 —— 人机验证组件（captcha-verify 的 widget.js）
    // 就跑在这包里。此前只有聊天页与后台加载它，登录页拿不到，
    // 之前是靠 lang-en 插件的 page.footer 顺带注入的（那个插件一停用就断）。
    // 打上标记，插件自己的 page.footer 注入会被它自己的 define 判断跳过，不会重复加载。
    if (!defined('OWLSGO_ASSETS_JS_EMITTED')) {
        define('OWLSGO_ASSETS_JS_EMITTED', true);
        echo '<script src="?action=assets&type=js"></script>';
    }
    echo '<script>OwAuth.init(' . json_encode(['key' => Sec::anonKey(), 'ts' => time()]) . ');</script>';
    Plugin::fire('page.footer');
    echo '</body></html>';
}

function renderChat(array $actor, ?array $user, ?array $guest): void
{
    $rooms = Chat::rooms($actor);
    if (!$rooms) { header('Location: ?page=login'); exit; }
    // 地址路由：?page=chat&room=ID 直达指定群聊；id 不存在或未传则回退第一个
    $first = $rooms[0];
    $reqRoom = isset($_GET['room']) ? (int)$_GET['room'] : 0;
    $directHit = false;
    if ($reqRoom > 0) {
        foreach ($rooms as $r) {
            if ((int)$r['id'] === $reqRoom) { $first = $r; $directHit = true; break; }
        }
    }
    // v1.3.1：游客不再被自动塞进第一个群聊。
    // 以前 boot.room 恒为列表首个房间，游客一个群都没点就已经「在里面」、能直接发言。
    // 游客改为默认不进入任何群（$entered=false）：必须自己点选一个群，或走 ?room=ID 直达。
    // 注册用户不受影响——他们有成员关系，由「先加入才能发言」那条规则约束。
    $entered = ($actor['kind'] !== 'guest') || $directHit;
    $settings = [
        'guest_chat' => DB::setting('guest_chat', '1'),
        'sound' => DB::setting('sound_default', '1'),
        // v1.1.14：普通用户能否创建不公开群聊。**按当前身份算好后下发**，
        // 前端据此把「公开群聊」开关置灰——不这样做就会留下「点得动、必报错」的死开关。
        'room_private_create' => (
            $actor['role'] === 'admin'
            || DB::setting('room_private_create_allow', '1') === '1'
        ) ? '1' : '0',
    ];
    pageHead('群聊');
    echo '<body class="ow-chat-body" data-lang="' . ow_lang() . '">';
    echo '<div class="ow-layout">';

    // ---------- 顶部品牌条（v1.3.6） ----------
    // 从侧栏内部移到整个布局的顶部：logo + 站点名横跨全宽，
    // rail 与会话列表从品牌条下方开始 —— rail 的「消息」图标与列表第一行天然对齐，
    // 也符合「品牌在所有栏目之上」的常规聊天客户端版式。
    // v1.3.48：搜索入口不再放这里（挪进 .ow-sidebar 顶部做成搜索框），
    //          条高改由 .ow-brand 的 min-height 钉住，见 CSS 同条注释。
    echo '<header class="ow-brand">'
       . '<img src="assets/img/logo.svg" alt="logo"><span>' . Sec::e(DB::setting('site_name', 'Owlsgo-Chat')) . '</span>'
       . '</header>';

    // 三栏主体：rail + 侧栏 + 主区 + 右侧栏（品牌条不在其中，见上）
    echo '<div class="ow-body">';

    // ---------- 左侧一级导航条 rail（v1.3.4） ----------
    // 取代原先侧栏顶部的横向「消息 / 联系人」标签条（ow-tabs），
    // 变成「图标条 + 列表 + 内容」三段式：rail 只切面板，不承载数据。
    // 插件注册入口：服务端 Plugin::collect('sidebar.rail')（产出按钮 HTML），
    //              或前端 OwChat.onRail({ id, label, onShow })。
    // ⚠️ 按钮的 data-tab 与 switchTab 的面板标识同名；核心只认 chat / friends。
    // ⚠️ 按钮内带 <span class="ow-rail-lb"> 文字标签：桌面隐藏（纯图标条），
    //    窄屏（≤720px）rail 沉底变标签栏时显示 —— 插件注册的入口建议同样带 span。
    // v1.3.52：通知入口只对**注册用户**开放 —— 通知按 user_id 存，游客没有账号，
    // 给一个永远点不出内容的标签比不给更容易让人困惑。
    // 红点数字服务端就渲染出来（首屏即正确，不等 JS 再拉一次），点开通知流后由前端清零。
    $noticeUnread = ($actor['kind'] ?? '') === 'user' ? Notice::unread((int)($actor['id'] ?? 0)) : 0;
    echo '<nav class="ow-rail" id="owRail">'
       . '<button class="ow-rail-btn is-active" data-tab="chat" type="button" title="消息" aria-label="消息">' . ow_icon('chat', 20) . '<span class="ow-rail-lb">消息</span></button>'
       . '<button class="ow-rail-btn" data-tab="friends" type="button" title="联系人" aria-label="联系人">' . ow_icon('users', 20) . '<span class="ow-rail-lb">联系人</span></button>'
       . (($actor['kind'] ?? '') === 'user'
           ? '<button class="ow-rail-btn" data-tab="notice" type="button" title="系统通知"'
             . ' aria-label="系统通知' . ($noticeUnread > 0 ? '（' . $noticeUnread . ' 条未读）' : '') . '">'
             . ow_icon('bell', 20) . '<span class="ow-rail-lb">通知</span>'
             // v1.3.53：只要「有没有」，不要数量 —— 复用通用小红点 .ow-dot（与后台系统升级同一个轮子）。
             // 点本身对读屏是装饰（aria-hidden），未读数改放进气泡按钮的 aria-label。
             . '<span class="ow-dot" id="owNoticeDot" aria-hidden="true"' . ($noticeUnread > 0 ? '' : ' style="display:none"') . '></span></button>'
           : '')
       . Plugin::collect('sidebar.rail')
       . '</nav>';

    // 左侧栏
    echo '<aside class="ow-sidebar" id="owSidebar">'
       // v1.3.6：品牌条已上移到整个布局顶部（见 .ow-topbrand 注释），侧栏从列表直接开始。
       // v1.1.0：列表已是「群聊 + 私聊」聚合，标题改为「聊天」；
       // 徽标数字含义同步改为「会话总数」，由 conversations 接口返回的 total 在前端回填
       // v1.3.4：原侧栏顶部的横向「消息 / 联系人」标签条（#owSideTabs）已移除，
       //   切换控件改为最左侧的 .ow-rail 图标条（见上方 rail 区块）。
       //   ⚠️ 插件注册入口随之迁移：Plugin::collect('sidebar.tabs') → 'sidebar.rail'，
       //      OwChat.onSideTabs → OwChat.onRail（旧名保留为兼容别名）。
       // v1.3.48：搜索入口从顶部品牌条挪到这里，做成侧栏最上方的搜索框。
       //   它是 <button> 不是 <input>：本身不接受输入，点一下开搜索弹窗（OwChat.openSearch），
       //   输入与结果都在弹窗里 —— 避免「光标在闪却打不进字」的死输入框观感。
       . '<button class="ow-side-search" id="owSideSearch" type="button" aria-label="搜索" title="搜索">'
       . ow_icon('search', 15) . '<span>搜索</span></button>'
       // 聊天面板（核心两个面板之一是「消息」，另一个是「联系人」）
       . '<ul class="ow-room-list ow-tab-panel is-active" id="owRoomList" data-panel="chat"></ul>'
       // v1.3.52：系统通知面板。与 #owRoomList 共用 .ow-room-list / .ow-tab-panel 样式，
       // 里面只有一行「系统通知」会话（内容由前端 loadNotices 渲染），所以初始隐藏、
       // 显隐统一交给 _paintTabs 管，避免两处各写一份 display 逻辑。
       . '<ul class="ow-room-list ow-tab-panel" id="owNoticeList" data-panel="notice" style="display:none"></ul>'
       // 插件面板容器：由 OwChat 在切换时创建/复用，插件标签对应的内容挂这里
       . '<div class="ow-tab-panels" id="owSidePanels" style="display:none"></div>'
       . '<div class="ow-me" id="owMe"></div>'
       // 登录用户的操作入口收进个人资料区菜单（点击 owMe 弹出）；游客仍直接给登录按钮
       . ($user ? '' : '<div class="ow-side-actions"><a class="ow-btn ow-btn-ghost" href="?page=register">注册</a><a class="ow-btn ow-btn-primary" href="?page=login">登录</a></div>')
       . '</aside>';

    // 主聊天区
    echo '<main class="ow-main">'
       . '<header class="ow-topbar">'
       . '<button class="ow-icon-btn ow-only-mobile" id="owToggleSide" aria-label="菜单">' . ow_icon('menu') . '</button>'
       // v1.3.1：游客未进入任何群时标题给占位，避免顶着一个其实没进去的群名
       . '<h2 class="ow-room-name" id="owRoomName">' . ($entered ? Sec::e($first['name']) : '请选择一个群聊') . '</h2>'
       . '<span class="ow-tag ow-tag-green" id="owSpeakTag">可发言</span>'
       . '<span class="ow-latency" id="owLatency"></span>'
       // 右侧「竖三点」：打开群聊信息侧栏（v1.1.1 替代原在线成员人形图标）
       . '<button class="ow-icon-btn" id="owTogglePanel" aria-label="群聊信息" title="群聊信息">' . ow_icon('more-v') . '</button>'
       . '</header>'
       // v1.2.6：消息区初始为空，更早的消息靠向上滚动懒加载（不再有「加载更早消息…」入口）
       . '<div class="ow-messages" id="owMessages"></div>'
       // v1.3.22：「N 条未读 ↓」跳转按钮改为**放在输入栏内部**（.ow-inputbar 的第一个子元素）。
       // v1.3.21 原本是 .ow-main 的绝对定位兄弟节点，用户反馈「应该显示在 ow-inputbar 上方」——
       // 而 .ow-inputbar 是**动态高度**（用户可上拉，输入框还能自动增高），
       // 靠 CSS 给个固定 bottom 偏移必然对不齐：输入栏一变高，按钮就陷进消息区或压住工具条。
       // 放进输入栏内部后，让 .ow-inputbar 成为定位上下文（position:relative），
       // 按钮用 bottom:100% + margin-bottom 表达「贴在输入栏上沿之外」——
       // 这样输入栏无论多高，按钮都自动跟着走，不需要任何 JS 计算。
       . '<div class="ow-inputbar">'
       . '<button class="ow-unread-jump" id="owUnreadJump" type="button" style="display:none">'
       . '<svg class="ow-ico" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
       . ' stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
       . '<polyline points="6 9 12 15 18 9"/></svg>'
       . '<span id="owUnreadJumpText">条未读</span></button>'
       // v1.2.27：未加入群聊时的闸门提示（默认隐藏，由 OwChat.applyJoinGate 控制）。
       // 正常流程下点击公开群聊会先弹「是否加入」，取消则不进入；这里是 URL 直达 /
       // 已被移出成员 / 服务端拒绝发言等异常态的兜底入口。
       . '<div class="ow-join-gate" id="owJoinGate" style="display:none"></div>'
       . '<div class="ow-toolbar">'
       // 工具栏图标统一 16px（比消息区图标小一号，避免抢视觉重心）
       . '<button class="ow-icon-btn" id="owBtnEmoji" title="表情">' . ow_icon('smile', 16) . '</button>'
       // v1.2.41：图片/文件两个按钮**移入附件上传插件**，这里只留一个空锚点。
       //   插件启用时由其 chat.js 往这里注入按钮（含 file input）；插件停用则按钮不出现 ——
       //   核心因此不需要任何「插件是否启用」的判断。
       . '<span id="owAttachTools"></span>'
       . '<button class="ow-icon-btn" id="owBtnSound" title="提示音" data-on="' . Sec::e(ow_icon('bell', 16)) . '" data-off="' . Sec::e(ow_icon('bell-off', 16)) . '">' . ow_icon('bell', 16) . '</button>'
       . '</div>'
       . '<div class="ow-input-row">'
       // 引用条（v1.0.69）：出现在输入框上方，点 ✕ 取消；默认隐藏，由 OwChat.renderQuote 填充
       . '<div class="ow-quote-bar" id="owQuoteBar" style="display:none"></div>'
       . '<textarea class="ow-input" id="owInput" rows="1" placeholder="输入消息，按 Enter 发送，Ctrl+V 粘贴图片"></textarea>'
       // 拖拽手柄：手动拉高输入框（自动增高之外的人工控制方式）
       . '<span class="ow-input-resize" id="owInputResize" title="拖动调整输入框高度">' . ow_icon('resize', 14) . '</span>'
       . '<button class="ow-btn ow-btn-primary ow-send ow-send-round" id="owBtnSend" aria-label="发送" title="发送">' . ow_icon('send', 18) . '</button>'
       . '</div></div>'
       . '<div class="ow-emoji-panel" id="owEmojiPanel" style="display:none"></div>'
       . '</main>';

    // 右侧栏（v1.1.10）：上方「群聊信息」入口区、下方所有成员
    //
    // 结构说明（v1.1.10 调整，务必与 renderRoomPanel / onRoomEdit 钩子契约对齐）：
    //   · 本区块**不再常驻展开群资料表单**——群聊设置恢复为点击弹出的模态框
    //     （v1.1.1~v1.1.9 曾把它内联常驻在侧栏，本次按需求回退到弹窗形态）。
    //   · 本区块只放**两行入口**：第一行「群聊设置」、第二行「群公告」。
    //     两行同款样式（.ow-panel-entry），群公告行由 announcements 插件经
    //     onRoomEdit 钩子填进 #owREExtras，位于「群聊设置」下方、
    //     「所有成员」区块上方 —— 插件入口因此不再与群资料表单耦合。
    //   · 私聊（room_id=0）时两者都不适用，renderRoomPanel 置空并给出提示。
    echo '<aside class="ow-online" id="owOnline">'
       . '<div class="ow-panel-sec ow-panel-room">'
       // v1.1.16：删掉「群聊信息」标题文字。区块本身已有群头像/名称/入口行
       // 自带语义，标题纯属冗余；只留一个供 JS 定位的空标题容器（收起按钮仍要挂这里）。
       . '<div class="ow-side-title" id="owPanelTitle" aria-hidden="true">'
       . '<button class="ow-online-close" id="owOnlineClose" aria-label="收起侧栏" title="收起">×</button></div>'
       . '<div class="ow-panel-room-body" id="owRoomPanel"></div>'
       . '</div>'
       . '<div class="ow-panel-sec ow-panel-members">'
       // v1.3.24：「所有成员」标题右侧的搜索按钮（点开按昵称 / ID 搜群成员）。
       // 按钮**静态输出**但由 JS 控制显隐（游客不显示 —— 搜索接口对游客一律拒绝，
       // 给一个必然报错的入口是反模式）。空标题容器不复用，这里另起一行保持语义清晰。
       . '<div class="ow-side-title ow-side-title-members">所有成员'
       . '<span class="ow-badge-num" id="owOnlineCount">0</span>'
       . '<button class="ow-icon-btn ow-members-search-btn" id="owMembersSearch"'
       . ' aria-label="搜索成员" title="搜索成员" style="display:none">'
       . ow_icon('search', 15) . '</button>'
       . '</div>'
       . '<ul class="ow-online-list" id="owOnlineList"></ul>'
       . '</div>'
       . '</aside>';

    // v1.3.6：三栏主体（.ow-body）闭合；品牌条在 .ow-layout 顶层、其上方
    echo '</div>';
    echo '</div>';

    // 浮层：资料卡 / 图片预览 / 设置 / 密码房间
    echo '<div class="ow-modal-mask" id="owModalMask" style="display:none"><div class="ow-modal" id="owModal"></div></div>';
    echo '<div class="ow-img-viewer" id="owImgViewer" style="display:none"><img id="owImgViewerImg" alt="预览"></div>';
    echo '<div class="ow-ctx-menu" id="owCtxMenu" style="display:none"></div>';
    echo '<div class="ow-mask" id="owMask"></div>';
    echo '<div class="ow-toast" id="owToast" style="display:none"></div>';

    $boot = [
        'key' => $actor['key'],
        'actor' => [
            'kind' => $actor['kind'], 'id' => $actor['id'] ?? 0,
            'nickname' => $actor['nickname'] ?? '', 'role' => $actor['role'] ?? 'guest',
            // v1.3.14：下发最终 URL —— 游客头像此前完全没进 boot，前端只能退回 identicon，
            // 于是「游客还是之前的头像」。
            'avatar' => (string)($actor['avatar'] ?? ''),
        ],
        'rooms' => $rooms,
        // v1.3.52：未读系统通知数。rail 红点与侧栏那一行的徽标首屏就要正确，
        // 让前端再拉一次接口只会多一个来回（而且点开通知时服务端已顺手全部标读）。
        'notice_unread' => (int)($noticeUnread ?? 0),
        // v1.3.52：系统通知的固定头像（小可爱第 36 号）。服务端算一次，
        // 侧栏那一行与通知流共用 —— 两处各自拼 URL 迟早不一致。
        'notice_avatar' => ($actor['kind'] ?? '') === 'user' ? Notice::avatarUrl() : '',
        // v1.3.1：游客未点选任何群时下发 0，前端据此禁用输入区（进入后才解锁）
        'room' => $entered ? (int)$first['id'] : 0,
        'site_url' => ow_site_url(),
        'settings' => $settings,
        // v1.3.64：改密码是否要邮箱验证码，前端按它决定要不要渲染那一栏。
        // 判定必须与 Auth::changePassword 完全同源（开关 + 真有可投递的通道 + 本人邮箱可用）：
        // 两边不一致就会出现「界面要求、服务端忽略」，或者反过来把用户锁死在改不了密码上。
        'mail_chpwd' => (Mailer::deliverable()
            && filter_var((string)($user['email'] ?? ''), FILTER_VALIDATE_EMAIL)) ? '1' : '0',
        'me' => $user ? [
            'nickname' => $user['nickname'], 'id' => (int)$user['id'],
            'role' => $user['role'], 'title' => $user['title'] ?? '',
            'avatar' => Auth::avatarUrlFor($user, (string)$user['id']), 'points' => (int)($user['points'] ?? 0),
            // v1.3.55：改密码要往本人邮箱发验证码，弹窗里得显示发到哪。
            // 这是用户自己的邮箱、下发给他自己的浏览器，不是泄露。
            'email' => (string)($user['email'] ?? ''),
            'email_verified' => (int)($user['email_verified'] ?? 0),
        ] : null,
        'ts' => time(),
        'version' => OWLSGO_VERSION,
        // v1.3.14：风格 slug + 中文名，供头像二级界面显示标题（前端不认 DiceBear 内部结构）
        'avatar_styles' => (function () {
            $out = [];
            foreach (Auth::avatarStyles() as $slug => $meta) $out[] = ['slug' => $slug, 'label' => $meta[0]];
            return $out;
        })(),
    ];
    // 插件资源必须在 OwChat.init 之后引入：插件脚本依赖 OwChat.cfg 判断场景
    // ⚠️ 打标记：插件（如 twofa）会用 page.footer 再补一份合并资源给「登录页」用，
    //    不标记的话聊天页会**加载两遍**合并包 —— 插件 JS 跑两次，
    //    凡「往数组里注册」的扩展点（onCardMetaTop 等）都会重复注册一行。
    define('OWLSGO_ASSETS_JS_EMITTED', true);
    echo '<script src="assets/js/i18n.js?v=' . OWLSGO_VERSION . '"></script>'
       . '<script src="assets/js/chat.js?v=' . OWLSGO_VERSION . '"></script>'
       . '<script>OwChat.init(' . json_encode($boot, JSON_UNESCAPED_UNICODE) . ');</script>'
       . '<script src="?action=assets&type=js"></script>';
    Plugin::fire('page.footer');
    echo '</body></html>';
}

function renderAdmin(array $actor): void
{
    pageHead('管理后台');
    // 插件子菜单：仅显示在 main.php 里调用过 Plugin::adminPage() 声明后台页面的插件
    // （未安装写库、未启用或未声明页面的插件都不会出现在这里）。一个声明 = 一个子页面。
    $pluginPages = Plugin::adminPages();
    // 已安装但未启用的插件：main.php 不加载（无设置页），仍显示为灰色子项，
    // 点进去提供一键启用（v1.0.45）
    $offPlugins = [];
    foreach (Plugin::listAll() as $pl) {
        if (!$pl['enabled'] && !isset($pluginPages[$pl['id']])) $offPlugins[] = $pl['id'];
    }
    $pluginMenu = '<li data-apage="plugins"' . (($pluginPages || $offPlugins) ? ' class="ow-admin-group"' : '') . '>'
        . '<span class="ow-admin-ico">' . ow_icon('puzzle', 16) . '</span>'
        . '<span class="ow-admin-label">插件管理</span>'
        . ($pluginPages ? '<span class="ow-admin-tog">' . ow_icon('chevron', 14) . '</span>' : '')
        . '</li>';
    foreach ($pluginPages as $slug => $pg) {
        // v1.2.57：子菜单不再重复放 puzzle 图标 —— 每个插件都是同一个图标，
        // 一排下来像一串无意义的方块，去掉后名称更清爽（缩进 padding-left:34px 不变，
        // 仍与父级「插件管理」的文字起始位置对齐）。
        $pluginMenu .= '<li class="ow-admin-sub" data-apage="plugin:' . Sec::e($slug) . '">'
            . '<span class="ow-admin-label">' . Sec::e($pg['title']) . '</span></li>';
    }
    foreach ($offPlugins as $offName) {
        $pluginMenu .= '<li class="ow-admin-sub ow-admin-off" data-apage="plugin:' . Sec::e($offName) . '">'
            . '<span class="ow-admin-label">' . Sec::e($offName) . '（未启用）</span></li>';
    }
    // ⚠️ 同 renderChat()：标记「合并资源已输出」，阻止插件在 page.footer 里再补一份
    //    （否则后台页也会把插件 JS 跑两遍）。用 if 包裹避免同请求内重复 define 报警告。
    if (!defined('OWLSGO_ASSETS_JS_EMITTED')) define('OWLSGO_ASSETS_JS_EMITTED', true);
    echo '<body class="ow-admin-body" data-lang="' . ow_lang() . '">'
       // 移动端顶栏：汉堡开关 + 标题 + 返回前台（桌面端隐藏，侧栏常驻）
       . '<div class="ow-admin-bar">'
       . '<button class="ow-icon-btn" id="owAdminToggle" aria-label="菜单">' . ow_icon('menu') . '</button>'
       . '<span class="ow-admin-bar-title">管理后台</span>'
       . '</div>'
       . '<div class="ow-admin-layout">'
       . '<aside class="ow-admin-side" id="owAdminSide">'
       . '<div class="ow-admin-brand">ADMIN CONSOLE<br><strong>管理后台</strong></div>'
       . '<ul class="ow-admin-menu" id="owAdminMenu">'
       . '<li data-apage="rooms" class="active"><span class="ow-admin-ico">' . ow_icon('chat', 16) . '</span>群聊审核</li>'
       // 敏感词过滤（v1.0.104）、群聊公告（v1.0.102）已剥离为插件，菜单由插件 adminPage 自动挂载
       . '<li data-apage="logs"><span class="ow-admin-ico">' . ow_icon('shield', 16) . '</span>安全日志</li>'
       // 注：系统日志（PHP 报错文件日志，v1.2.60）**不进侧栏**——只在「安全日志」页
       // 右上角提供入口。它是排错时才用的抽屉内容，常驻菜单会占位又几乎点不到。
       // 计划任务（v1.1.13）：插件通过 Plugin::cron() 注册的任务在此集中查看 / 启停 / 手动触发
       . '<li data-apage="cron"><span class="ow-admin-ico">' . ow_icon('gear', 16) . '</span>维护任务</li>'
       . '<li data-apage="settings"><span class="ow-admin-ico">' . ow_icon('gear', 16) . '</span>系统设置</li>'
       // 插件管理置于系统设置之下，作为分类，其下挂载各插件自己的设置页面
       . $pluginMenu
       . '</ul>'
       // 返回前台沉在侧栏底部（桌面常驻、移动端展开抽屉可见）
       . '<a class="ow-btn ow-btn-ghost ow-btn-block ow-admin-exit" href="?page=chat">返回前台</a>'
       . '</aside>'
       . '<main class="ow-admin-main" id="owAdminMain"></main></div>'
       // 移动端抽屉遮罩：点空白收起侧栏（桌面端不显示）
       . '<div class="ow-admin-mask" id="owAdminMask" style="display:none"></div>'
       . '<div class="ow-toast" id="owToast" style="display:none"></div>'
       . '<script src="assets/js/i18n.js?v=' . OWLSGO_VERSION . '"></script>'
       . '<script src="assets/js/chat.js?v=' . OWLSGO_VERSION . '"></script>'
       // 插件注册的 JS 资源合并输出（用户管理等插件的后台交互脚本）
       . '<script src="?action=assets&type=js"></script>'
       . '<script>OwAdmin.init(' . json_encode(['key' => $actor['key'], 'ts' => time(), 'version' => OWLSGO_VERSION]) . ');</script>'
       . '</body></html>';
}
