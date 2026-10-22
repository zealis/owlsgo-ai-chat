<?php
/**
 * 插件机制：Hook、路由、后台页面、资源合并、在线安装（zip）、统一计划任务
 * 插件结构：plugins/<name>/plugin.json + main.php
 *   plugin.json: {"name":"显示名","version":"1.0.0","description":"...","author":"..."}
 *   main.php 中通过 Plugin::on('钩子名', callable) 注册钩子
 * 内置钩子：message.before_send / message.after_send / page.head / page.footer /
 *           admin.menu / api.route / cron.minute
 *
 * v1.0.90 性能改造（元数据缓存 + 按需加载）：
 *   - init() 不再逐个 require 插件 main.php，只做一次轻量目录扫描 + 读取缓存文件；
 *   - 元数据（plugin.json）与「注册清单」（插件登记了哪些钩子/路由/后台页/资源）
 *     缓存到 data/cache/plugins.json；仅在首次部署、新增启用、插件文件变更后重建一次；
 *   - 钩子触发 / 路由分发 / 后台页渲染时按清单精确加载涉及的插件，
 *     与当前请求无关的插件代码不再进入请求周期；
 *   - ?action=assets 的合并输出做文件级缓存（参与文件集合与「mtime+字节数」未变则直接复用）。
 * v1.5.24 收尾（热路径零加载 + 清单权威化）：
 *   - 后台页 / 前台页的「slug=>标题」进清单：后台壳、插件管理列表、?page= 路由
 *     只读清单即可渲染菜单与路由表，不再为拿几个标题把全部页面型插件 require 进来；
 *     真正渲染某一页时才按清单只加载拥有它的那一个插件（adminPageFor/frontPageFor）。
 *   - loadPlugin / inspect 改「权威覆盖」：main.php 完整执行过，本次注册集即真相，
 *     整体替换旧清单条目。旧实现 array_merge 只增不减 —— 插件改版删掉某个钩子后，
 *     旧条目永留清单，每次 fire 都为它白加载一次插件（正是要消灭的 include 开销），
 *     敏感标记同理残留。执行抛异常的半截注册不覆盖，保留旧清单合并增量。
 *   - 缓存格式升 v=2（pages/fpages 由 slug 列表改为 slug=>标题映射），
 *     readCache 拒读旧版，格式升级后的第一个请求自动重建。
 * 契约（与既有约定一致）：main.php 顶层只做 Plugin::* 注册，不做输出 / 其它副作用。
 */
class Plugin
{
    private static array $hooks = [];
    /* v1.3.160：与 $hooks **平行**的「回调归属」表 —— 记下每个处理器是哪个插件注册的，
       回调执行期间 Plugin::current() 返回它。用途：给插件注册出来的行补默认值
       （如「通知联系人」没给昵称就用插件名、没给头像就用该插件派生的生成头像）。
       ⚠️ 它必须与 $hooks 同生共死：init() 重置、on() 追加、snapshot/inspect 回滚三处都要一起动，
          否则两个数组索引错位 —— 归属会张冠李戴。 */
    private static array $hookOwner = [];
    private static string $current = '';   // 正在执行的回调属于哪个插件（空串 = 核心或未知）
    private static array $routes = [];
    private static array $adminPages = [];
    private static array $frontPages = [];   // v1.2.44：插件注册的前台页 slug => ['title'=>..,'fn'=>..]
    private static array $assets = ['css' => [], 'js' => []];
    private static array $crons = [];   // 插件注册的计划任务（v1.1.13）
    private static array $stats = [];   // 兼容保留：每个插件本次加载的注册计数
    private static string $dir = '';
    private static string $cacheDir = '';
    private static int $lastCron = 0;

    private static array $order = [];      // 启用插件名（DB 顺序）
    private static array $loaded = [];     // name => 本请求是否已加载 main.php
    private static array $meta = [];       // name => plugin.json 解析内容
    private static array $manifest = [];   // name => ['sig','hooks','routes','pages','assets','stats']
    private static ?array $pending = null; // 正在加载插件的注册增量
    private static string $loading = '';   // 正在加载的插件名
    private static bool $dirty = false;    // 缓存是否需要落盘

    public static function init(string $dir, string $cacheDir = ''): void
    {
        self::$dir = $dir;
        self::$cacheDir = $cacheDir !== '' ? $cacheDir : dirname(rtrim($dir, '/\\')) . '/data/cache';
        @mkdir($dir, 0775, true);

        // 同进程内重复 init（CLI 脚本、测试）必须重置这些状态，否则会累积出脏数据：
        //  · $crons 累积 → 插件被停用后旧处理器还留在注册表里，
        //    runCron 照样判定「处理器已注册」并执行，skip 分支永远走不到；
        //    syncCronTasks 也会把已停用插件的任务重新写回。
        // 线上每请求一个新进程，碰不到这里，但同进程内 init 两次就出问题了。
        self::$crons = [];
        self::$loaded = [];
        self::$stats = [];
        self::$hooks = [];
        self::$hookOwner = [];
        self::$routes = [];
        self::$adminPages = [];
        self::$frontPages = [];
        self::$assets = ['css' => [], 'js' => []];
        self::$meta = [];
        self::$manifest = [];
        self::$pending = null;
        self::$dirty = false;
        self::$lastCron = 0;

        // 核心自身的计划任务（v1.1.13）：plugin 名为空串，与插件任务在表里天然区分。
        // 注册在 init 最前面 —— 它不依赖任何插件，且 syncCronTasks() 在 init 末尾才跑。
        self::$loading = '';
        // v1.2.2：清除超过服务器保留期的消息及其附件文件。
        // v1.2.3 起本任务是**唯一**的消息清理入口 —— 原 purge_deleted_messages
        // 随「已删除消息保留期」一并下线，删除消息同样由本任务按 msg_retain_days 清除。
        self::cron('purge_expired_messages', 3600, function () {
            if (class_exists('Chat')) Chat::purgeExpired();
        }, '物理清除超过服务器保留期的消息与附件（每小时一次）');
        // v1.3.55：过期 / 用过的邮箱验证码清理。放核心而不是邮件插件里 ——
        // 表 email_codes 由核心写入（插件停用也照样写），清理就必须同样在核心，
        // 否则「停用插件」会让这张表只增不减。
        self::cron('purge_email_codes', 86400, function () {
            if (!class_exists('Mailer')) return 'Mailer 类未加载，跳过';
            return '清理过期验证码 ' . Mailer::purge(3600) . ' 条';
        }, '清理已过期的邮箱验证码（每天一次，保留最近 1 小时的记录便于排查）');
        // v1.3.167：执行日志保留期。cron_logs 每次任务执行都写一行（维护任务由长轮询
        // 每分钟驱动，几秒级任务一天就是上千行），不清会无限膨胀。保留天数走系统设置
        // 「执行日志保留期」（cron_log_retain_days，默认 90 天，0 = 永久保留）。
        self::cron('purge_cron_logs', 86400, function () {
            $days = (int)DB::setting('cron_log_retain_days', 90);
            if ($days <= 0) return '保留期设为 0（永久保留），跳过';
            $cut = time() - $days * 86400;
            $n = (int)DB::val('SELECT COUNT(*) FROM cron_logs WHERE created_at<?', [$cut]);
            if ($n <= 0) return '没有超过 ' . $days . ' 天的执行日志';
            DB::run('DELETE FROM cron_logs WHERE created_at<?', [$cut]);
            return '清理超过 ' . $days . ' 天的执行日志 ' . $n . ' 条';
        }, '清理超过保留期的执行日志（每天一次；保留天数在系统设置里改，默认 90 天，0=永久保留）');
        // v1.3.42 系统升级红点：每 12 小时比对一次 GitHub 仓库，结果写 settings.update_info，
        // 后台「系统与维护 → 系统升级」按它显隐红点。失败只记 error，不影响其它任务。
        // v1.3.99 顺带负责通知：发现新版本就给超级管理员发一条前台系统通知。
        // 复用这条而不是新加一条任务 —— 同一次 GitHub 比对不该打两遍 API，
        // 而且多一条同名任务，后台只会看到两个都在「检查更新」却行为不同。
        self::cron('check_update', 43200, function () {
            if (!class_exists('Upgrade')) return 'Upgrade 类未加载，跳过';
            $r = Upgrade::check($err);
            Upgrade::saveInfo($r, $err);
            if ($r === null) return '检查失败：' . $err;
            // 三态分开写：以前「有差异」和「有新版」共用一个 has_update，
            // 本地还没推的提交也会被写成「（有更新）」，看日志的人以为仓库动了。
            $stLabel = ['update' => '有更新', 'local_changes' => '本地与仓库有差异（多为未推送的提交）', 'latest' => '已最新'];
            return 'v' . $r['local_version'] . ' → v' . $r['remote_version']
                . '（' . ($stLabel[$r['status']] ?? $r['status']) . '）；' . Upgrade::notifyAdmins($r);
        }, '检查 GitHub 仓库更新（每 12 小时一次，后台「系统升级」处显示红点；有新版则通知超级管理员）');
        self::$loading = '';

        // 1) 轻量目录扫描（目录名 + mtime），识别插件名单变化
        $dirs = [];
        foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            $n = basename($d);
            if (preg_match('/^[a-zA-Z0-9_-]+$/', $n)) $dirs[$n] = (int)@filemtime($d);
        }

        // 2) 读缓存并按签名（目录 mtime + main.php / plugin.json 的 mtime+字节数）校验
        $cache = self::readCache();
        $stale = [];
        foreach ($dirs as $n => $dm) {
            $c = $cache['plugins'][$n] ?? null;
            $sig = self::sigOf($n, $dm);
            if ($c && ($c['sig'] ?? null) === $sig) {
                // 旧版本清单没有 crons 键（v1.1.13 之前）→ 视为过期并重建。
                // 否则计划任务永远进不了按需加载：清单看着「完整」，实际漏了注册信息。
                $legacy = !array_key_exists('crons', $c);
                self::$meta[$n] = $c['meta'] ?? self::readMeta($n);
                if ($legacy) {
                    self::$manifest[$n] = $c + self::blankManifestParts();
                    self::$dirty = true;
                    $stale[$n] = true;
                } else {
                    self::$manifest[$n] = $c;
                }
            } else {
                // 新插件或文件有变更：重读元数据，旧清单仅作兜底，标记待重建
                self::$meta[$n] = self::readMeta($n);
                if ($c) self::$manifest[$n] = $c;
                self::$dirty = true;
                $stale[$n] = true;
            }
        }
        // 名单中已消失的插件：剔除本地状态
        foreach (array_keys($cache['plugins']) as $n) {
            if (!isset($dirs[$n])) { unset(self::$manifest[$n], self::$meta[$n]); self::$dirty = true; }
        }

        // 3) 启用集（与旧版同一查询、同一顺序）
        self::$order = array_map('strval', array_column(DB::all('SELECT name FROM plugins WHERE enabled=1'), 'name'));

        // 4) 已启用但清单缺失或文件变更的插件：本请求先真实加载一次重建清单
        //    （仅首次部署 / 新启用 / 文件变更后的第一个请求），之后恢复按需加载
        foreach (self::$order as $n) {
            if (isset($stale[$n]) || empty(self::$manifest[$n]['stats'])) self::loadPlugin($n);
        }
        // 计划任务入库（v1.1.13）：插件清单此时已就绪，syncCronTasks 幂等，
        // 只在「新任务」或「间隔变化」时才动 next_run_at。
        self::syncCronTasks();
        self::saveCache();
    }

    // ---------- 元数据 / 清单缓存 ----------

    private static function sigOf(string $name, ?int $dirMtime = null): array
    {
        $d = self::$dir . '/' . $name;
        return [
            'd' => $dirMtime ?? (int)@filemtime($d),
            'm' => self::stamp($d . '/main.php'),
            'j' => self::stamp($d . '/plugin.json'),
        ];
    }

    /**
     * 单文件新鲜度标记 = mtime + 字节数（一次 stat 顺带取到，不读文件内容）。
     * ⚠️ 只看 mtime 会漏部署：rsync -a / unzip / cp -p 都保留**源文件**的 mtime，
     *    内容换了而 mtime 正好撞车时，清单与元数据会永远判定「未变」——
     *    症状是后台版本号已经是新的（plugin.json 被单独改动过）、页面却还是旧代码渲染的。
     *    缺文件统一返回 '0:0'，与任何真实文件都不相等。
     */
    private static function stamp(string $f): string
    {
        $s = @stat($f);
        return $s === false ? '0:0' : ((int)$s['mtime'] . ':' . (int)$s['size']);
    }

    private static function readMeta(string $name): array
    {
        $f = self::$dir . '/' . $name . '/plugin.json';
        return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    }

    private static function cacheFile(): string { return self::$cacheDir . '/plugins.json'; }

    /**
     * 缓存用 JSON + file_get_contents，不用 include：
     * php-cgi 的 OPcache 会按路径缓存 include 文件的编译结果，本机重校验有数十秒延迟，
     * 重写后的清单可能读到旧版本导致钩子不触发；json 直读完全绕开该问题。
     */
    private static function readCache(): array
    {
        $f = self::cacheFile();
        if (!is_file($f)) return ['plugins' => []];
        $c = json_decode((string)file_get_contents($f), true);
        if (!is_array($c) || !isset($c['plugins']) || !is_array($c['plugins'])) return ['plugins' => []];
        // v1.5.24：清单格式升到 v=2（pages/fpages 由 slug 列表改为 slug=>标题映射）。
        // 旧版缓存按新格式解读会把字符串当标题数组用 → 直接作废，
        // 让 init 走「新插件」分支重建（格式升级后的第一个请求全量加载一次）。
        if ((int)($c['v'] ?? 1) < 2) return ['plugins' => []];
        return $c;
    }

    private static function blankManifestParts(): array
    {
        // v1.5.24：pages/fpages 是「slug=>标题」映射（旧版是 slug 列表，
        // 标题只在运行时 $adminPages/$frontPages 里，拿它就得加载插件代码）。
        return ['hooks' => [], 'routes' => [], 'pages' => [], 'fpages' => [], 'sensitive' => [], 'crons' => [],
                'assets' => ['css' => [], 'js' => []],
                'stats' => ['hooks' => 0, 'routes' => 0, 'pages' => 0, 'crons' => 0]];
    }

    /** 原子落盘缓存（tmp + rename，多进程并发下后写覆盖，内容等价无害） */
    private static function saveCache(): void
    {
        if (!self::$dirty || self::$cacheDir === '') return;
        if (!is_dir(self::$cacheDir)) @mkdir(self::$cacheDir, 0775, true);
        $data = ['v' => 2, 'plugins' => []];
        // ⚠️ 白名单漏一个键，那个键就**永远不会被写进缓存**：
        // 落盘丢失 → 下次读缓存缺失 → 判定为「清单不完整」→ 每次请求都重新加载插件，
        // 而按需加载（fire/dispatch 靠清单里的 hooks/crons 决定要不要加载）随之失效。
        // 加新的注册类型时，这里必须同步加，否则症状是「性能下降 + 行为诡异」而非报错。
        $keep = array_flip(['hooks', 'routes', 'pages', 'fpages', 'sensitive', 'crons', 'assets', 'stats', 'sig']);
        // v1.5.24：遍历 **$meta ∪ $manifest**，不是只遍历 $manifest。
        // 旧实现只持久化「加载过的插件」—— 未启用（或从没被 fire 到）的插件永远进不了
        // 缓存 → 每个请求都要对它们重新 readMeta（一次文件读 + json 解码）并置脏重写
        // 缓存文件。35+ 插件的站点上这就是「每次请求的插件扫描开销」本身。
        // 现在元数据全量落缓存：稳态下 init 对任何插件都不读文件、不写缓存。
        $names = array_unique(array_merge(array_keys(self::$meta), array_keys(self::$manifest)));
        foreach ($names as $n) {
            $data['plugins'][$n] = array_intersect_key(self::$manifest[$n] ?? [], $keep)
                + ['sig' => self::sigOf($n), 'meta' => self::$meta[$n] ?? []]
                + self::blankManifestParts();
        }
        $tmp = self::cacheFile() . '.' . (string)getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
            @rename($tmp, self::cacheFile());
        }
        self::$dirty = false;
    }

    // ---------- 按需加载 ----------

    /** 加载单个插件的 main.php：记录注册增量 → 写入清单 → 落盘缓存 */
    private static function loadPlugin(string $name): void
    {
        if (!empty(self::$loaded[$name])) return;
        self::$loaded[$name] = true;
        $main = self::$dir . '/' . $name . '/main.php';
        if (!is_file($main)) return;

        self::$pending = ['hooks' => [], 'routes' => [], 'pages' => [], 'fpages' => [], 'sensitive' => [], 'crons' => [], 'assets' => ['css' => [], 'js' => []]];
        self::$loading = $name;
        // ⚠️ 必须 require_once，不能用 require。
        // 同一请求内一个插件可能被加载两次：init() 的「重建清单」与 inspect() 的「探测计数」，
        // 以及后台页里 adminPages() 先加载、随后 fire() 又按清单加载。
        // 用 require 时第二次会重新执行整个文件 → 插件顶层定义的函数重复声明
        // → Fatal error: Cannot redeclare xxx() → 整个请求 500（空响应，无 JSON）。
        // 踩过：login-logs 的 owLLEnsureTable() 就是这么把 ?action=cron 打成 500 的。
        // require_once 第二次直接返回 true 且不执行，语义正是我们要的。
        $ok = true;
        try { require_once $main; } catch (Throwable $e) { $ok = false; Sec::log('plugin_error', $name, ['error' => $e->getMessage()]); }
        self::$loading = '';
        $p = self::$pending;
        self::$pending = null;

        $stats = ['hooks' => count($p['hooks']), 'routes' => count($p['routes']),
                  'pages' => count($p['pages']) + count($p['fpages']), 'crons' => count($p['crons'])];
        self::$stats[$name] = $stats;
        $old = self::$manifest[$name] ?? null;
        // v1.5.24：main.php 完整执行过 → 本次注册集就是权威真相，**整体覆盖**旧清单。
        // 旧实现是 array_merge 只增不减：插件改版删掉某个钩子 / 路由 / 敏感标记 /
        // 后台页后，旧条目永远留在清单里 —— fire/dispatch 每次都为这个已删除的钩子
        // 白加载一次插件（正是要消灭的 include 开销），敏感标记还会残留。
        // 执行失败（抛异常）时半截注册不是真相，但不能把清单洗空：保留旧清单 + 合并增量。
        if ($ok || $old === null) {
            // ⚠️ 'meta' 必须带上且排在最后：saveCache 落盘的键序就是
            // hooks…sig,meta（array_intersect_key + '+' 的结果），缺它或换序都会让
            // $old !== $m 恒成立 → 每个加载了插件的请求都重写一遍缓存文件。
            $m = [
                'hooks' => $p['hooks'], 'routes' => $p['routes'],
                'pages' => $p['pages'], 'fpages' => $p['fpages'],
                'sensitive' => $p['sensitive'], 'crons' => $p['crons'],
                'assets' => $p['assets'], 'stats' => $stats,
                'sig' => self::sigOf($name),
                'meta' => self::$meta[$name] ?? [],
            ];
        } else {
            $m = ($old ?? []) + self::blankManifestParts() + ['sig' => []];
            foreach (['hooks', 'routes', 'sensitive', 'crons'] as $k) {
                $m[$k] = array_values(array_unique(array_merge($m[$k], $p[$k])));
            }
            // pages/fpages 是 slug=>标题映射：array_unique 按**值**去重，
            // 两个页标题相同就会互相顶掉，必须用 array_replace 按键覆盖。
            foreach (['pages', 'fpages'] as $k) {
                $m[$k] = array_replace($m[$k], $p[$k]);
            }
            foreach (['css', 'js'] as $t) {
                $m['assets'][$t] = array_values(array_unique(array_merge($m['assets'][$t] ?? [], $p['assets'][$t])));
            }
            $m['stats'] = $stats;
            $m['sig'] = self::sigOf($name);
        }
        self::$manifest[$name] = $m;
        // 内容与签名都未变化时不落盘（同一请求内重复加载 / 探测不重复写缓存）
        if ($old === null || $old !== $m) {
            self::$dirty = true;
            self::saveCache();
        }
    }

    // ---------- 注册接口（加载期被 main.php 调用） ----------

    public static function on(string $hook, callable $fn): void
    {
        self::$hooks[$hook][] = $fn;
        // 归属：加载期 self::$loading 就是正在加载的插件名（核心自己注册回调时为空串）
        self::$hookOwner[$hook][] = self::$loading;
        if (self::$pending !== null) self::$pending['hooks'][] = $hook;
    }

    /**
     * 当前正在执行的回调属于哪个插件（v1.3.160）。
     *
     * 只在 fire / collect 调起**插件注册的回调**期间有值；核心代码里调用一律为空串。
     * 典型用法是给插件注册的行补默认展示信息，比如通知联系人：
     * 插件没给昵称就取它自己的插件名、没给头像就按插件 id 派生一个生成头像。
     */
    public static function current(): string { return self::$current; }

    /**
     * 注册 API 路由。$opts['sensitive'] = true 标记为敏感操作：
     * 服务端会要求一次性操作票据（见 PLUGIN.md「敏感操作安全校验」），
     * 前端须用 OwApi.secure() 调用（自动先取票再提交）。
     */
    public static function route(string $action, callable $fn, array $opts = []): void
    {
        self::$routes[$action] = $fn;
        if (self::$pending === null) return;
        self::$pending['routes'][] = $action;
        if (!empty($opts['sensitive'])) self::$pending['sensitive'][] = $action;
    }

    /** 单独标记某路由为敏感操作（多行闭包注册后追加声明用；也可用 route 的 $opts['sensitive']） */
    public static function sensitive(string $action): void
    {
        if (self::$pending === null) return;
        if (!in_array($action, self::$pending['sensitive'], true)) self::$pending['sensitive'][] = $action;
    }

    public static function adminPage(string $slug, string $title, callable $fn): void
    {
        self::$adminPages[$slug] = ['title' => $title, 'fn' => $fn];
        // v1.5.24：清单里存 slug=>标题映射 —— 后台壳 / 插件管理列表只读清单
        // 就能渲染子菜单，不必为拿标题把插件 require 进来（见 adminPageTitles）。
        if (self::$pending !== null) self::$pending['pages'][$slug] = $title;
    }

    /**
     * 注册一个**前台页面**（v1.2.44）：访问 ?page=<slug> 时渲染。
     *
     * 与 adminPage 的区别：那个挂在后台「插件管理」子菜单里、需要管理员身份；
     * 这个是**面向访客**的独立页面（如等级说明页），自带页面布局与返回入口。
     * slug 只允许字母数字短横线，避免与核心页面（chat/login/register/admin…）撞名。
     *
     * ```php
     * Plugin::page('level', '等级', function (array $actor): string {
     *     return '<div class="ow-lv-page">…</div>';
     * });
     * ```
     */
    public static function page(string $slug, string $title, callable $fn): void
    {
        if (!preg_match('/^[a-z][a-z0-9-]{1,30}$/', $slug)) return;
        // 核心保留页：插件不得覆盖
        if (in_array($slug, ['chat', 'login', 'register', 'forgot', 'admin', 'install'], true)) return;
        self::$frontPages[$slug] = ['title' => $title, 'fn' => $fn];
        if (self::$pending !== null) self::$pending['fpages'][$slug] = $title;
    }

    /**
     * 已注册的前台页（会加载所有声明了 fpages 的启用插件）。
     *
     * ⚠️ 重：v1.5.24 起热路径（?page= 路由）请用 frontPageTitles() + frontPageFor()——
     * 只读清单 + 命中后按需加载拥有该页的那一个插件。本方法保留给需要一次性
     * 拿到全部 fn 的调用方（第三方插件兼容）。
     */
    public static function frontPages(): array
    {
        foreach (self::$order as $name) {
            if (empty(self::$loaded[$name]) && !empty(self::$manifest[$name]['fpages'])) self::loadPlugin($name);
        }
        return self::$frontPages;
    }

    public static function asset(string $type, string $path): void
    {
        if (!in_array($type, ['css', 'js'], true)) return;
        self::$assets[$type][] = $path;
        if (self::$pending !== null && !in_array($path, self::$pending['assets'][$type], true)) {
            self::$pending['assets'][$type][] = $path;
        }
    }

    // ---------- 计划任务（v1.1.13） ----------

    /**
     * 注册一个计划任务（插件在 main.php 顶层调用）。
     *
     * 与 `cron.minute` 钩子的区别：那个是「每分钟醒一次，要不要干活自己判断」，
     * 无状态、无列表、无法在后台查看；本方法是**声明式注册**——
     * 任务名、间隔、中文说明都入库，后台「计划任务」页能列出来、能启停、能手动触发，
     * 每次执行还留一条日志。
     *
     * ```php
     * Plugin::cron('purge_expired', 86400, function () {
     *     Chat::purgeExpired();
     * }, '清理到期消息');
     * ```
     *
     * @param string          $name        任务名（同插件内唯一，只允许字母数字下划线）
     * @param int             $interval    运行间隔（秒），小于 60 按 60 处理
     * @param callable|string $handler     处理器：闭包，或 "Class@method" 字符串
     * @param string          $description 面向管理员的中文说明（后台列表展示；不写则只显示任务名）
     */
    public static function cron(string $name, int $interval, $handler, string $description = ''): void
    {
        if (!is_callable($handler) && !is_string($handler)) return;
        $safe = preg_replace('/[^A-Za-z0-9_]/', '_', $name);
        self::$crons[] = [
            'plugin'      => self::$loading,
            'name'        => ($safe !== '' && $safe !== null) ? $safe : 'task',
            'interval'    => max(60, $interval),
            'handler'     => $handler,
            'description' => trim($description),
        ];
        if (self::$pending !== null) self::$pending['crons'][] = $safe;
    }

    /**
     * 已注册的计划任务清单（来自本请求已加载的插件）。
     * 后台用它补齐 cron_tasks 表里不存的 description。
     * @return list<array{plugin:string,name:string,interval:int,handler:mixed,description:string}>
     */
    public static function crons(): array
    {
        return self::$crons;
    }

    /**
     * 当前已启用（且已安装）的插件名集合，供后台判断「插件是否启用」等场景。
     * 与 runCron 的判定同源（plugins 表 enabled=1），不依赖本请求是否恰好加载过该插件 main.php——
     * 按需加载下，独立的 admin_cron_list 请求通常不会去 loadPlugin 插件，
     * 若用 self::$crons 判定会误把已启用插件认成「未启用」。
     */
    public static function enabledPlugins(): array
    {
        return self::$order;
    }

    /**
     * 把已加载插件注册的计划任务同步进 cron_tasks 表。
     *
     * 只在「新增或间隔变化」时改 next_run_at —— 否则每次请求都会把到期时间往后推，
     * 任务永远等不到执行（论坛踩过这个坑，这里明确区分首次写入与更新）。
     * 已在库里的 enabled / last_run_at / run_count 一律不动，那是管理员与历史的状态。
     */
    public static function syncCronTasks(): void
    {
        $now = time();
        // v1.5.20：先一次取回现有任务，别在 foreach 里逐个 SELECT（插件一多就是 N+1）。
        $existing = [];
        foreach (DB::all('SELECT id, plugin, name, interval_sec FROM cron_tasks') as $row) {
            $existing[(string)$row['plugin'] . '::' . (string)$row['name']] = $row;
        }
        $newRows = [];
        foreach (self::$crons as $t) {
            $key = $t['plugin'] . '::' . $t['name'];
            $row = $existing[$key] ?? null;
            if (!$row) {
                $newRows[$key] = [
                    'plugin'      => $t['plugin'],
                    'name'        => $t['name'],
                    // 列名是 interval_sec：`INTERVAL` 是 MySQL 保留字（见 DB::migrate 的说明）。
                    // 注册数组的键仍叫 interval —— 那是 PHP 侧的形状，只有落库时才换名。
                    'interval_sec' => (int)$t['interval'],
                    'last_run_at' => 0,
                    'next_run_at' => $now + (int)$t['interval'],
                    'last_status' => '',
                    'run_count'   => 0,
                    'enabled'     => 1,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ];
                continue;
            }
            // 间隔被插件改过 → 按新间隔重算到期时间；否则原样保留。
            // 只有「真变了」的少数任务会写一次，数量不随插件数放大。
            if ((int)$row['interval_sec'] !== (int)$t['interval']) {
                DB::run('UPDATE cron_tasks SET interval_sec=?, next_run_at=?, updated_at=? WHERE id=?',
                    [(int)$t['interval'], $now + (int)$t['interval'], $now, (int)$row['id']]);
            }
        }
        if ($newRows) DB::insertMany('cron_tasks', array_values($newRows));
    }

    /**
     * 执行到期的计划任务。
     *
     * @param int  $limit 单次最多执行多少个（防止一次性堆积的任务把请求拖死）
     * @param bool $force true = 忽略 next_run_at，把启用的任务全跑一遍（后台「立即执行」用）。
     *                     false = 只跑到期任务（长轮询驱动时用，保持调度节奏）
     * @return list<array{name:string,status:string,message:string,duration:int}>
     */
    public static function runCron(int $limit = 10, bool $force = false): array
    {
        $now = time();
        $limit = max(1, min(50, $limit));
        $sql = 'SELECT * FROM cron_tasks WHERE enabled=1';
        if (!$force) $sql .= ' AND next_run_at<=?';
        $sql .= ' ORDER BY next_run_at ASC LIMIT ' . $limit;

        $tasks = DB::all($sql, $force ? [] : [$now]);
        if (!$tasks) return [];

        // 「插件::任务名」→ 处理器。未加载的插件这里取不到（按需加载，见下）
        $handlers = [];
        foreach (self::cronsOfEnabledPlugins() as $t) {
            $handlers[$t['plugin'] . '::' . $t['name']] = $t['handler'];
        }

        $results = [];
        // N+1 例外：每个到期任务必须独立执行并写自己的状态/日志；$tasks 有 LIMIT 上限。
        foreach ($tasks as $task) {
            $key = $task['plugin'] . '::' . $task['name'];
            $started = microtime(true);
            $status = 'ok';
            $message = '';

            if (!isset($handlers[$key])) {
                // 插件停用后任务仍留在表里，但每次都会走到这里被跳过。
                // 后台据此显示「插件未启用」并隐藏启停开关。
                $status = 'skip';
                $message = '处理器未注册（插件可能已停用）';
            } else {
                try {
                    // 处理器返回的字符串就是本次的日志消息：不记的话「检查了，没更新」
                    // 和「检查了，通知了 2 位管理员」在后台长得一模一样，只能看到一片 ok。
                    // 返回 null/空串的任务（多数清理类）行为不变。
                    $msg = self::callCronHandler($handlers[$key]);
                    if (is_string($msg) && $msg !== '') $message = mb_substr($msg, 0, 400);
                } catch (Throwable $e) {
                    $status = 'error';
                    $message = $e->getMessage();
                    Sec::log('cron_error', $key, ['err' => $e->getMessage()]);
                }
            }

            $duration = (int)round((microtime(true) - $started) * 1000);
            // $task 是 cron_tasks 的行（列名 interval_sec，见 syncCronTasks 的说明）
            $interval = max(60, (int)$task['interval_sec']);
            // 状态与日志必须成对写：只更新状态没日志，后台看不出这次跑了什么；
            // 只写日志没更新状态，任务会被反复重跑。
            // v1.5.22：两张表用事务包住，避免「状态改了、日志没落」的残缺。
            DB::tx(function () use ($now, $interval, $status, $task, $key, $message, $duration) {
                DB::run('UPDATE cron_tasks SET last_run_at=?, next_run_at=?, last_status=?, run_count=run_count+1, updated_at=? WHERE id=?',
                    [$now, $now + $interval, $status, $now, (int)$task['id']]);
                DB::insert('cron_logs', [
                    'name'       => $key,
                    'status'     => $status,
                    'message'    => mb_substr($message, 0, 400),
                    'duration'   => $duration,
                    'created_at' => $now,
                ]);
            });
            $results[] = ['name' => $key, 'status' => $status, 'message' => $message, 'duration' => $duration];
        }
        return $results;
    }

    /** 收集所有启用插件注册的计划任务（按需加载各插件 main.php） */
    private static function cronsOfEnabledPlugins(): array
    {
        foreach (self::$order as $name) {
            if (empty(self::$loaded[$name]) && !empty(self::$manifest[$name]['crons'])) {
                self::loadPlugin($name);
            }
        }
        return self::$crons;
    }

    /** 调用处理器：支持 callable 与 "Class@method" 字符串 */
    private static function callCronHandler($handler)
    {
        if (is_string($handler) && strpos($handler, '@') !== false) {
            [$cls, $method] = explode('@', $handler, 2);
            return $cls::$method();
        }
        return call_user_func($handler);
    }


    // ---------- 运行时触发（按清单懒加载涉及的插件） ----------

    public static function fire(string $hook, array $args = []): void
    {
        foreach (self::$order as $name) {
            if (empty(self::$loaded[$name])
                && in_array($hook, self::$manifest[$name]['hooks'] ?? [], true)) {
                self::loadPlugin($name);
            }
        }
        $owners = self::$hookOwner[$hook] ?? [];
        foreach (self::$hooks[$hook] ?? [] as $i => $fn) {
            $prev = self::$current;
            self::$current = (string)($owners[$i] ?? '');
            // finally：回调抛异常也要把「当前插件」还回去，否则后面所有回调都会被当成它
            try { $fn(...$args); } catch (Throwable $e) { /* 插件异常不影响主流程 */ } finally { self::$current = $prev; }
        }
    }

    /**
     * 与 fire() 相同地触发钩子，但**收集各处理器的字符串返回值并拼接**（v1.2.20）。
     *
     * 为什么需要它：fire() 是 void —— 插件只能自己 echo / 直接改传入的引用参数，
     * 无法「产出片段让主程序决定插到哪里」。而「插件往标签条里加一个标签」
     * 这类需求本质是**产出 HTML 片段**，必须能把返回值收回来。
     *
     * 约定：处理器返回 string（或可转 string 的值）即被采纳，返回 null / false / 空串忽略。
     * ⚠️ 单个插件抛异常只丢弃它这一段，不影响其它插件与主流程（与 fire 同口径）。
     *
     * @param string $hook 钩子名
     * @param array  $args 传给各处理器的参数（与 fire 相同）
     * @return string 拼接后的 HTML（无插件产出时为空串）
     */
    public static function collect(string $hook, array $args = []): string
    {
        foreach (self::$order as $name) {
            if (empty(self::$loaded[$name])
                && in_array($hook, self::$manifest[$name]['hooks'] ?? [], true)) {
                self::loadPlugin($name);
            }
        }
        $out = '';
        $owners = self::$hookOwner[$hook] ?? [];
        foreach (self::$hooks[$hook] ?? [] as $i => $fn) {
            $prev = self::$current;
            self::$current = (string)($owners[$i] ?? '');
            try {
                $r = $fn(...$args);
                if (is_string($r) && $r !== '') $out .= $r;
            } catch (Throwable $e) { /* 插件异常不影响主流程 */ } finally { self::$current = $prev; }
        }
        return $out;
    }

    public static function dispatch(string $action, array $ctx)
    {
        foreach (self::$order as $name) {
            if (empty(self::$loaded[$name])
                && in_array($action, self::$manifest[$name]['routes'] ?? [], true)) {
                self::loadPlugin($name);
            }
        }
        if (isset(self::$routes[$action])) return call_user_func(self::$routes[$action], $ctx);
        return null;
    }

    /** 该 action 是否被插件声明为敏感操作（清单查询，不触发加载） */
    public static function isSensitive(string $action): bool
    {
        foreach (self::$manifest as $m) {
            if (in_array($action, $m['sensitive'] ?? [], true)) return true;
        }
        return false;
    }

    /**
     * 后台页集合：先按清单把声明了后台页的启用插件加载进来，再返回。
     *
     * ⚠️ 重：v1.5.24 起核心热路径（后台壳 / 插件管理列表）改用
     * adminPageTitles() + adminPageFor()。本方法保留给需要一次性拿到全部 fn
     * 的调用方（第三方插件兼容）。
     */
    public static function adminPages(): array
    {
        foreach (self::$order as $name) {
            if (empty(self::$loaded[$name]) && !empty(self::$manifest[$name]['pages'])) self::loadPlugin($name);
        }
        return self::$adminPages;
    }

    /**
     * 已启用插件注册的后台页标题表（slug => 标题）—— **只读清单，不加载任何插件代码**。
     *
     * v1.5.24：后台「插件管理」子菜单、插件管理列表的「直达 slug 清单」都只需要标题，
     * 旧实现为此 require 了全部页面型插件。现在标题随清单缓存，真正渲染某一页时
     * 才按需加载拥有它的那一个插件（adminPageFor）。
     * 同名 slug 以 $order 里最后一个声明者为准，与 adminPages() 全量加载的覆盖顺序一致。
     */
    public static function adminPageTitles(): array
    {
        $out = [];
        foreach (self::$order as $name) {
            foreach (self::$manifest[$name]['pages'] ?? [] as $slug => $title) {
                if (!is_string($slug) || $slug === '') continue;
                $out[$slug] = (string)$title;
            }
        }
        return $out;
    }

    /**
     * 取某个后台页的 ['title'=>..,'fn'=>..]：按清单**只加载拥有该 slug 的插件**。
     * slug 未注册、或插件加载后并未注册它（清单刚好过期）时返回 null。
     */
    public static function adminPageFor(string $slug): ?array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $slug)) return null;
        $owner = '';
        // 反序找最后一个声明者 —— 与 adminPages() 全量加载时的覆盖顺序一致
        foreach (array_reverse(self::$order) as $name) {
            if (isset(self::$manifest[$name]['pages'][$slug])) { $owner = $name; break; }
        }
        if ($owner === '') return null;
        self::loadPlugin($owner);
        // 加载后清单已被权威注册集覆盖：仍声明这个 slug 才认，
        // 防止清单刚过期时把无关插件的同名页当成本插件的返回。
        if (!isset(self::$manifest[$owner]['pages'][$slug])) return null;
        return self::$adminPages[$slug] ?? null;
    }

    /**
     * 已启用插件注册的前台页标题表（slug => 标题）—— 只读清单，不加载插件代码。
     * ?page= 路由先查它，命中后再 frontPageFor() 按需加载。
     */
    public static function frontPageTitles(): array
    {
        $out = [];
        foreach (self::$order as $name) {
            foreach (self::$manifest[$name]['fpages'] ?? [] as $slug => $title) {
                if (!is_string($slug) || $slug === '') continue;
                $out[$slug] = (string)$title;
            }
        }
        return $out;
    }

    /**
     * 取某个前台页的 ['title'=>..,'fn'=>..]：按清单**只加载拥有该 slug 的插件**。
     * slug 未注册、或插件加载后并未注册它时返回 null。
     */
    public static function frontPageFor(string $slug): ?array
    {
        if (!preg_match('/^[a-z][a-z0-9-]{1,30}$/', $slug)) return null;
        $owner = '';
        foreach (array_reverse(self::$order) as $name) {
            if (isset(self::$manifest[$name]['fpages'][$slug])) { $owner = $name; break; }
        }
        if ($owner === '') return null;
        self::loadPlugin($owner);
        if (!isset(self::$manifest[$owner]['fpages'][$slug])) return null;
        return self::$frontPages[$slug] ?? null;
    }

    // ---------- 资源合并（清单驱动，无需加载 main.php） ----------

    /**
     * @param string $only 非空时只取**该插件**注册的资源。
     * @param string $file 非空时再按文件名（如 boot.js）挑出其中一个。
     *                     两个参数是为了切出「同一插件、不同时机」的脚本：引擎必须在 <head>
     *                     同步执行（放 body 末尾的合并总包里会先闪一帧默认色），UI 则必须等
     *                     chat.js 之后。一份清单即可切出两种子集，不必新增注册类型。
     * 插件名只与「已启用清单」比对、文件名只用于**过滤已注册的路径**，两者都不参与拼路径，
     * 所以不存在任意文件读取；未启用的插件返回空串 = 停用即回到核心默认。
     */
    public static function renderAssets(string $type, string $only = '', string $file = ''): string
    {
        if (!in_array($type, ['css', 'js'], true)) return '';
        if ($only !== '' && !in_array($only, self::$order, true)) return '';
        if ($file !== '' && !preg_match('/^[a-zA-Z0-9._-]+$/', $file)) return '';
        // 启用插件清单中的资源（未加载的插件也算上）+ 本请求运行时注册的资源
        $files = [];
        foreach (self::$order as $name) {
            if ($only !== '' && $name !== $only) continue;
            foreach (self::$manifest[$name]['assets'][$type] ?? [] as $p) $files[] = $p;
        }
        if ($only === '' && $file === '') {
            foreach (self::$assets[$type] ?? [] as $p) $files[] = $p;
        }
        if ($file !== '') {
            $files = array_values(array_filter($files, fn($p) => basename(str_replace('\\', '/', $p)) === $file));
        }
        $files = array_values(array_unique($files));

        // 合并缓存：参与文件集合与「mtime+字节数」未变则直接复用（stamp 的理由见 sigOf）
        $sig = [];
        foreach ($files as $p) {
            $f = self::$dir . '/' . ltrim($p, '/');
            $sig[$p] = self::stamp($f);
        }
        $key = md5(json_encode([$type, $only, $file, $sig]));
        $cfile = self::$cacheDir !== '' ? self::$cacheDir . '/assets-' . $key . '.json' : '';
        if ($cfile !== '' && is_file($cfile)) {
            $c = json_decode((string)file_get_contents($cfile), true);
            if (is_array($c) && ($c['sig'] ?? null) === $sig) return (string)$c['out'];
        }
        $out = '';
        foreach ($files as $p) {
            $f = self::$dir . '/' . ltrim($p, '/');
            if (is_file($f)) $out .= file_get_contents($f) . "\n";
        }
        if ($cfile !== '') {
            if (!is_dir(self::$cacheDir)) @mkdir(self::$cacheDir, 0775, true);
            $tmp = $cfile . '.' . (string)getmypid() . '.tmp';
            if (@file_put_contents($tmp, json_encode(['sig' => $sig, 'out' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false) {
                @rename($tmp, $cfile);
                // 过期缓存清理：保留**最近 6 份**而不是只留刚写的这一份 —— 单插件资源（$only）
                // 与总包是不同 key，若「只留一份」就会互相踢掉，每次请求都重新合并。
                $keep = glob(self::$cacheDir . '/assets-*.json') ?: [];
                usort($keep, fn($a, $b) => (int)@filemtime($b) <=> (int)@filemtime($a));
                foreach (array_slice($keep, 6) as $old) @unlink($old);
            }
        }
        return $out;
    }

    /**
     * 统一计划任务驱动（v1.1.13 升级）
     *
     * 两层：
     *  1. `cron.minute` 钩子 —— 旧机制，每分钟醒一次，回调自行判断到期没到。保留不删，
     *     现有插件仍挂在这里。
     *  2. 声明式任务（`Plugin::cron()` 注册）—— 到期就由 runCron() 执行。
     *
     * 频率取抹由长轮询驱动（每分钟最多一次），也可挂系统 cron 调 `?action=cron`。
     * 淘过到期任务：多进程下先刺断已有进程在跑，不阻塞每个请求。
     *
     * @param bool $force true = 忽略 next_run_at，强制跑全部已启用任务（后台「立即执行」）
     * @return list<array{name:string,status:string,message:string,duration:int}>
     */
    public static function cronTick(bool $force = false): array
    {
        $minute = (int)(time() / 60);
        if (!$force && $minute === self::$lastCron) return [];
        self::$lastCron = $minute;
        // 锁存在 data/cron.lock，内容为进程 pid：保持 110 秒自动过期，
        // 避免多个 php-cgi 进程在同一分钟重复执行（轮询是并发的）。
        $lockDir = self::$cacheDir !== '' ? self::$cacheDir : dirname(self::$dir) . '/data/cache';
        $lock = @fopen($lockDir . '/cron.lock', 'c');
        if ($lock === false) return [];
        if (@flock($lock, LOCK_EX | LOCK_NB) === false) { @fclose($lock); return []; }
        try {
            @ftruncate($lock, 0);
            @fwrite($lock, (string)getmypid());
            // 先火钩子、后声明式任务：保证旧机制的依赖不受影响
            self::fire('cron.minute');
            return self::runCron(20, $force);
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
    }

    // ---------- 后台管理 ----------

    public static function listAll(): array
    {
        $out = [];
        $enabled = array_column(DB::all('SELECT name, enabled FROM plugins'), 'enabled', 'name');
        foreach (glob(self::$dir . '/*/plugin.json') ?: [] as $file) {
            $name = basename(dirname($file));
            $meta = self::$meta[$name] ?? (json_decode((string)file_get_contents($file), true) ?: []);
            $on = (int)($enabled[$name] ?? 0);
            // 注册计数：清单新鲜直接用（免加载）；清单缺失/文件变更时临时加载探测
            $m = self::$manifest[$name] ?? null;
            // v1.5.24：stats 全 0 的条目是「从没真实加载过」的元数据占位
            // （未启用插件现在也有缓存条目了，见 saveCache），不能当新鲜 ——
            // 否则后台列表会对明明注册了钩子的插件显示 0。探测一次补上真实计数。
            $fresh = $m && ($m['sig'] ?? null) === self::sigOf($name)
                && !empty($m['stats']) && array_sum($m['stats']) > 0;
            $stats = $fresh ? $m['stats'] : self::inspect($name);
            $out[] = [
                'id' => $name,
                'name' => $meta['name'] ?? $name,
                'version' => $meta['version'] ?? '?',
                'description' => $meta['description'] ?? '',
                'author' => $meta['author'] ?? '',
                'source' => $meta['source'] ?? '本地',
                'enabled' => $on,
                'hooks' => (int)$stats['hooks'],
                'routes' => (int)$stats['routes'],
                'pages' => (int)$stats['pages'],
                // 计划任务数（v1.1.13）：后台插件管理页在「钩子/路由/后台页」之前展示
                'crons' => (int)($stats['crons'] ?? 0),
            ];
        }
        // 启用的排上面、未启用的沉底（站方 2026-10-14 要求）。**只加这一条排序键**：
        // PHP 8 的 usort 是稳定排序，同组内保持原来的目录序，不顺手把列表打乱重排。
        usort($out, fn(array $a, array $b): int => $b['enabled'] <=> $a['enabled']);
        return $out;
    }

    /** 注册计数快照：加载插件前后对比，得到该插件注册的钩子/路由/后台页数量 */
    private static function snapshot(): array
    {
        return ['hooks' => self::$hooks, 'hookOwner' => self::$hookOwner, 'routes' => self::$routes, 'pages' => self::$adminPages, 'fpages' => self::$frontPages];
    }

    private static function countReg(array $before): array
    {
        $h = 0;
        foreach (self::$hooks as $list) $h += count($list);
        $h0 = 0;
        foreach ($before['hooks'] as $list) $h0 += count($list);
        return ['hooks' => $h - $h0, 'routes' => count(self::$routes) - count($before['routes']),
                'pages' => count(self::$adminPages) - count($before['pages'])
                         + count(self::$frontPages) - count($before['fpages'])];
    }

    /** 已启用插件的注册计数（加载时记录） */
    public static function stats(string $name): array
    {
        return self::$stats[$name] ?? ['hooks' => 0, 'routes' => 0, 'pages' => 0, 'crons' => 0];
    }

    /**
     * 探测插件注册计数：临时加载 main.php 统计后完整回滚（含资源），并把清单刷新进缓存。
     * 仅用于后台列表展示；插件应保证 main.php 只做 Plugin::* 注册（见 PLUGIN.md）。
     */
    public static function inspect(string $name): array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) return ['hooks' => 0, 'routes' => 0, 'pages' => 0];
        $main = self::$dir . '/' . $name . '/main.php';
        if (!is_file($main)) return ['hooks' => 0, 'routes' => 0, 'pages' => 0, 'crons' => 0];
        // v1.5.24：本请求已真实加载过该插件时（后台壳 adminPages() 先加载、列表后探测），
        // require_once 第二次不会执行文件，$pending 会是空的 —— 拿它「刷新」清单等于
        // 把清单洗空（旧实现靠 array_merge 侥幸没洗掉，但回报的计数会是 0，前台页数错）。
        // 直接回报加载时记录的注册计数：loadPlugin 已把清单写成权威值。
        if (!empty(self::$loaded[$name])) {
            $s = self::$manifest[$name]['stats'] ?? null;
            return is_array($s) ? $s : ['hooks' => 0, 'routes' => 0, 'pages' => 0, 'crons' => 0];
        }
        $before = self::snapshot();
        $beforeAssets = self::$assets;
        $beforeCrons = self::$crons;
        self::$pending = ['hooks' => [], 'routes' => [], 'pages' => [], 'fpages' => [], 'sensitive' => [], 'crons' => [], 'assets' => ['css' => [], 'js' => []]];
        self::$loading = $name;
        $ok = true;
        // require_once：inspect() 可能与 loadPlugin() 在同一请求内先后加载同一插件，
        // 用 require 会因函数重复声明直接 fatal（见 loadPlugin 内的说明）。
        try { require_once $main; $stats = ['hooks' => count(self::$pending['hooks']), 'routes' => count(self::$pending['routes']), 'pages' => count(self::$pending['pages']), 'crons' => count(self::$pending['crons'])]; }
        catch (Throwable $e) { $ok = false; $stats = ['hooks' => 0, 'routes' => 0, 'pages' => 0, 'crons' => 0]; }
        // 探测执行过文件 = 已加载：后续 loadPlugin 直接走 $loaded 闸返回，
        // 不会拿空 $pending 覆盖清单（与上面早返回守卫同一目的的两道保险）。
        self::$loaded[$name] = true;
        $p = self::$pending;
        self::$pending = null;
        self::$loading = '';
        // 回滚全部注册（探测不生效，包括资源与计划任务）
        self::$hooks = $before['hooks'];
        self::$hookOwner = $before['hookOwner'] ?? [];
        self::$routes = $before['routes'];
        self::$adminPages = $before['pages'];
        self::$frontPages = $before['fpages'];
        self::$assets = $beforeAssets;
        // ⚠️ 计划任务必须一并回滚：Plugin::cron() 直接 push 到 self::$crons，
        // 漏掉会让「探测」变成真注册，同一任务在表里出现两次。
        self::$crons = $beforeCrons;
        // 刷新清单与签名（后续后台列表免探测）—— 与 loadPlugin 同口径：
        // 完整执行过就权威覆盖；半截异常保留旧清单合并增量。
        $oldM = self::$manifest[$name] ?? null;
        if ($ok) {
            // 键序与 meta 同 loadPlugin：缺 meta 会让「清单没变」误判成「变了」而重写缓存
            $m = [
                'hooks' => $p['hooks'], 'routes' => $p['routes'],
                'pages' => $p['pages'], 'fpages' => $p['fpages'],
                'sensitive' => $p['sensitive'], 'crons' => $p['crons'],
                'assets' => $p['assets'], 'stats' => $stats,
                'sig' => self::sigOf($name),
                'meta' => self::$meta[$name] ?? [],
            ];
        } else {
            $m = (self::$manifest[$name] ?? []) + self::blankManifestParts() + ['sig' => []];
            foreach (['hooks', 'routes', 'sensitive', 'crons'] as $k) {
                $m[$k] = array_values(array_unique(array_merge($m[$k], $p[$k])));
            }
            // pages/fpages 是 slug=>标题映射：array_unique 按值去重会顶掉同名标题的页，改用按键覆盖
            foreach (['pages', 'fpages'] as $k) {
                $m[$k] = array_replace($m[$k], $p[$k]);
            }
            foreach (['css', 'js'] as $t) {
                $m['assets'][$t] = array_values(array_unique(array_merge($m['assets'][$t] ?? [], $p['assets'][$t])));
            }
            $m['stats'] = $stats;
            $m['sig'] = self::sigOf($name);
        }
        self::$manifest[$name] = $m;
        // v1.5.24：变了才落盘。后台列表一次会探测几十个「从没加载过」的插件，
        // 旧实现每探测一次就全量重写缓存文件（N 次原子写）；注册集没变就不写。
        if ($oldM === null || $oldM !== $m) {
            self::$dirty = true;
            self::saveCache();
        }
        return $stats;
    }

    /** 卸载：删除注册记录并递归删除插件目录（仅限已安装插件，名称白名单） */
    public static function uninstall(string $name): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) return false;
        $dir = self::$dir . '/' . $name;
        if (!is_file($dir . '/plugin.json')) return false;
        // v1.5.22：先在插件仍完整时清清单/资源缓存（钩子里的代码还在），再删库与目录
        if (class_exists('Cache')) Cache::clear('plugin');
        // 先删注册（不是停用：卸载后列表不应留任何痕迹），再删目录
        DB::run('DELETE FROM plugins WHERE name=?', [$name]);
        self::rrmdir($dir);
        unset(self::$manifest[$name], self::$meta[$name], self::$loaded[$name]);
        self::$dirty = true;
        self::saveCache();
        Sec::log('plugin_uninstall', '', ['plugin' => $name]);
        return true;
    }

    private static function rrmdir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $f) {
            is_dir($f) ? self::rrmdir($f) : @unlink($f);
        }
        @rmdir($dir);
    }

    /** 打包插件目录为 zip（返回临时文件路径；调用方负责输出与删除） */
    public static function packageZip(string $name): ?string
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) return null;
        $dir = self::$dir . '/' . $name;
        if (!is_file($dir . '/plugin.json') || !class_exists('ZipArchive')) return null;
        $tmp = tempnam(sys_get_temp_dir(), 'owplug_');
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) return null;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen(str_replace('\\', '/', $dir)) + 1);
            $zip->addFile($f->getPathname(), $name . '/' . $rel);
        }
        $zip->close();
        return $tmp;
    }

    public static function toggle(string $name, bool $enable): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) return;
        if (!is_file(self::$dir . "/$name/plugin.json")) return;
        DB::upsert('plugins', ['name' => $name, 'enabled' => $enable ? 1 : 0, 'config' => ''], ['name']);
        // v1.5.22：启停后清插件清单/合并资源缓存，下次请求按新的启用清单重建
        DB::afterCommit(function () {
            if (class_exists('Cache')) Cache::clear('plugin');
        });
        // 同步本进程的内存状态：否则同一请求里紧接着调 runCron()，看到的仍是启停**前**的
        // order / crons —— 表现为「刚启用却提示插件未启用」或「刚停用却照常执行」。
        // 跨请求自然一致，但请求内必须自己跟上。
        $key = array_search($name, self::$order, true);
        if ($enable && $key === false) {
            self::$order[] = $name;
        } elseif (!$enable && $key !== false) {
            unset(self::$order[$key]);
            self::$order = array_values(self::$order);
        }
        // 停用时从注册表剔除该插件的任务。⚠️ require_once 的副作用：同进程内该插件的
        // main.php 已执行过，再 loadPlugin 不会重跑、闭包拿不回来；
        // 所以「启用」只能等下个请求（刷新页面）生效，这里不做无效尝试。
        if (!$enable) {
            self::$crons = array_values(array_filter(
                self::$crons,
                fn($c) => ($c['plugin'] ?? '') !== $name
            ));
        }
    }

    /** 在线安装：上传 zip 解压到 plugins/（ZipArchive 为 PHP 内置扩展） */
    public static function installZip(array $f): array
    {
        if ($f['error'] !== UPLOAD_ERR_OK) return [false, '上传失败'];
        if (!class_exists('ZipArchive')) return [false, '服务器未启用 ZipArchive 扩展'];
        $zip = new ZipArchive();
        if ($zip->open($f['tmp_name']) !== true) return [false, '无法解压插件包'];
        $root = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (preg_match('#^([^/]+)/plugin\.json$#', $n, $m)) { $root = $m[1]; break; }
        }
        if (!$root || !preg_match('/^[a-zA-Z0-9_-]+$/', $root)) { $zip->close(); return [false, '插件包缺少 plugin.json']; }
        $dest = self::$dir . '/' . $root;
        @mkdir($dest, 0775, true);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (strpos($n, $root . '/') !== 0) continue;
            $rel = substr($n, strlen($root) + 1);
            if ($rel === '' || strpos($rel, '..') !== false) continue; // 防目录穿越
            $target = $dest . '/' . $rel;
            if (substr($n, -1) === '/') { @mkdir($target, 0775, true); continue; }
            @mkdir(dirname($target), 0775, true);
            copy('zip://' . $f['tmp_name'] . '#' . $n, $target);
        }
        $zip->close();
        // v1.5.22：新插件文件已落盘，清清单/资源缓存，下次请求重新扫描
        if (class_exists('Cache')) Cache::clear('plugin');
        Sec::log('plugin_install', '', ['plugin' => $root]);
        return [true, '插件已安装：' . $root];
    }
}
