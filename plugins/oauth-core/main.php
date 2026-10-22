<?php
/**
 * 第三方应用授权插件（v1.2.37 自核心剥离为插件，按需加载）
 *
 * ## 这个插件负责什么 / 不负责什么
 *
 * **负责**：记录「哪个用户把哪些权限授予了哪个应用」，并提供用户自助查看 / 授权 / 撤销。
 * **不负责**：授权码跳转、access_token 签发与存储、回调校验 —— 这些**全部由插件自己实现**。
 *
 * 为什么不把它留在核心：
 *  1. 不是每个站点都需要第三方授权，核心常驻会白建一张表、白挂三个路由；
 *  2. 令牌是**极敏感凭据**，核心一旦持有，所有插件的令牌都归核心管，安全边界被放大；
 *  3. 各插件需要的授权流差异很大（有的跳第三方授权页，只要一串 token），
 *     强行统一反而别扭 —— 核心只存「授权关系」这件最轻的事，想换实现时不用动核心。
 *
 * 停用本插件后：表保留（数据不丢），前台不再出现「第三方授权」入口，路由消失。
 *
 * ## 别的插件怎么接
 *
 * ① 注册应用（声明需要的权限范围）：
 * ```php
 * Plugin::on('oauth.apps', function () {
 *     return json_encode([
 *         'plugin' => 'my-plugin',       // 必填：授权记录归属哪个插件
 *         'id'      => 'myapp',           // 插件内唯一标识
 *         'name'    => '我的应用',
 *         'desc'    => '把聊天记录同步到我的笔记工具',
 *         'icon'    => 'plugins/my/icon.svg',
 *         'scopes'  => [
 *             ['key' => 'profile.read', 'name' => '读取你的资料', 'desc' => '昵称、头像、用户 ID'],
 *             ['key' => 'message.read', 'name' => '读取聊天记录', 'desc' => '你所在会话的历史消息'],
 *         ],
 *     ], JSON_UNESCAPED_UNICODE) . "\n";   // ← 行尾 "\n" 不能省
 * });
 * ```
 * ⚠️ **为什么是「返回 JSON 文本」而不是「往数组里塞」**：
 *   Plugin::fire / collect 内部都是 `$fn(...$args)` 调用，而 **PHP 的 spread 展开
 *   会丢失引用** —— 插件写 `function (&$apps)` 收不到引用，改不到调用方的数组。
 *   collect 本来就是「把各插件的字符串拼起来」，所以每个插件返回**一行 JSON**，
 *   核心（本插件）按行 json_decode 合并，是与现有机制最契合的做法。
 *
 * ② 用户授权/撤销时本插件会 fire 两个事件，插件据此签发/销毁自己的令牌：
 *   `oauth.granted` → fn(int $userId, string $plugin, string $appId, array $scopes)
 *   `oauth.revoked` → fn(int $userId, string $plugin, string $appId)
 *
 * ③ 验证用户身份时调用 `OAuth::grantedScopes($userId, $plugin, $appId)`
 *   拿到**当前有效**的权限集合；空数组 = 未授权或已撤销 —— **不要自己查表**，撤销逻辑在这里。
 *
 * ⚠️ 依赖本插件才能用：你的 plugin.json 里不必写 oauth.apps 钩子也行
 *    （collect 会自动加载本插件并把已注册应用收集出来）。
 */
if (!defined('OWLSGO_VERSION')) exit;   // 禁止直接 HTTP 访问本文件

/* ============================ 建表 ============================ */
Plugin::on('install', function () {
    static $done = false;
    if ($done) return;
    $done = true;
    $id = 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $ts = 'INTEGER NOT NULL';
    if (DB::driver() === 'mysql') {
        $id = 'BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT';
        $ts = 'BIGINT UNSIGNED NOT NULL';
    }
    // 只记录「谁把什么权限授予了哪个应用」，不存任何令牌。
    // 一人 + 一应用 = 一行（唯一索引兜底），撤销用 revoked_at 标记而非删行：留审计。
    DB::run("CREATE TABLE IF NOT EXISTS oauth_grants (
        id $id,
        user_id BIGINT UNSIGNED NOT NULL,
        plugin_name VARCHAR(64) NOT NULL,
        app_id VARCHAR(64) NOT NULL,
        app_name VARCHAR(100) NOT NULL,
        app_desc TEXT,
        icon TEXT,
        " . DB::textCol('scopes') . ",
        created_at $ts, updated_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
        revoked_at BIGINT UNSIGNED)");
    // 唯一索引是「一人+一应用只一行」的正确性保证，不能用 try/catch 吞：
    // MySQL 不支持 CREATE INDEX IF NOT EXISTS，吞掉的后果是索引**静默缺失**。
    DB::createIndex('idx_oauth_grants_key', 'oauth_grants', 'user_id, plugin_name, app_id', true);
});

/* ============================ 授权逻辑 ============================ */
if (!class_exists('OAuth', false)) {
    /**
     * 第三方应用授权（表 oauth_grants）。
     * 方法说明见本文件头部；插件侧只需用 grantedScopes() 验证身份。
     */
    class OAuth
    {
        /**
         * 取所有已注册的应用（由各插件通过 oauth.apps 钩子产出，每插件一行 JSON）。
         * @return array 每个项：{plugin, id, name, desc, icon, scopes:[{key,name,desc}]}
         */
        public static function apps(): array
        {
            $raw = Plugin::collect('oauth.apps');
            $out = [];
            foreach (preg_split('/\r\n|\r|\n/', (string)$raw) as $line) {
                $line = trim((string)$line);
                if ($line === '') continue;
                $a = json_decode($line, true);
                if (!is_array($a) || empty($a['id']) || empty($a['name'])) continue;
                $scopes = [];
                foreach ((array)($a['scopes'] ?? []) as $s) {
                    if (!is_array($s) || empty($s['key'])) continue;
                    $scopes[] = [
                        'key' => (string)$s['key'],
                        'name' => (string)($s['name'] ?? $s['key']),
                        'desc' => (string)($s['desc'] ?? ''),
                    ];
                }
                $out[] = [
                    'plugin' => (string)($a['plugin'] ?? ''),
                    'id' => (string)$a['id'],
                    'name' => (string)$a['name'],
                    'desc' => (string)($a['desc'] ?? ''),
                    'icon' => (string)($a['icon'] ?? ''),
                    'scopes' => $scopes,
                ];
            }
            return $out;
        }

        /**
         * 应用列表 + 当前用户的授权状态（供「第三方授权」页渲染）。
         * @return array 每项 = apps() 的一项 + granted(bool) + granted_scopes(string)
         */
        public static function appsForUser(int $userId): array
        {
            $apps = self::apps();
            if ($userId <= 0 || !$apps) return $apps;
            $mine = self::grantsOf($userId);
            foreach ($apps as &$a) {
                $g = $mine[$a['plugin'] . '|' . $a['id']] ?? null;
                $a['granted'] = !empty($g);
                $a['granted_scopes'] = $g ? (string)$g['scopes'] : '';
            }
            unset($a);
            return $apps;
        }

        /**
         * 某用户全部**有效**授权（revoked_at 为空），键为 "plugin|appId"。
         * @return array<string, array>
         */
        public static function grantsOf(int $userId): array
        {
            if ($userId <= 0) return [];
            $set = [];
            foreach (DB::all('SELECT * FROM oauth_grants WHERE user_id=? AND revoked_at IS NULL', [$userId]) as $r) {
                $set[(string)$r['plugin_name'] . '|' . (string)$r['app_id']] = $r;
            }
            return $set;
        }

        /**
         * 取某用户在某应用上的**当前有效权限键**数组（插件验证身份时用这个，别自己查表）。
         * @return string[] 未授权/已撤销 → 空数组
         */
        public static function grantedScopes(int $userId, string $plugin, string $appId): array
        {
            $row = DB::one(
                'SELECT scopes FROM oauth_grants WHERE user_id=? AND plugin_name=? AND app_id=? AND revoked_at IS NULL',
                [$userId, $plugin, $appId]);
            if (!$row) return [];
            return array_values(array_filter(array_map('trim', explode(',', (string)$row['scopes']))));
        }

        /**
         * 授予权限（幂等：已授权则更新 scopes，不插重行）。
         * ⚠️ 只接受**应用自己声明过**的 scope，未声明的一律丢弃 ——
         *    否则构造请求能授出超出应用声明范围、并被记录进库的权限。
         *
         * @param string[] $scopes 用户勾选的权限键
         * @return array [ok, msg, granted_scopes]
         */
        public static function grant(int $userId, string $plugin, string $appId, array $scopes): array
        {
            if ($userId <= 0) return [false, '请先登录', []];
            if ($plugin === '' || $appId === '') return [false, '参数错误', []];
            $app = null;
            foreach (self::apps() as $a) { if ($a['id'] === $appId) { $app = $a; break; } }
            if (!$app) return [false, '该应用不存在或已停用', []];
            $app['plugin'] = $app['plugin'] !== '' ? $app['plugin'] : $plugin;

            $allowed = [];
            foreach ($app['scopes'] as $s) $allowed[$s['key']] = true;
            $clean = [];
            foreach ($scopes as $k) {
                $k = trim((string)$k);
                if ($k !== '' && isset($allowed[$k]) && !in_array($k, $clean, true)) $clean[] = $k;
            }
            if (!$clean) return [false, '请至少勾选一项权限', []];

            $now = time();
            $exists = (int)DB::val(
                'SELECT 1 FROM oauth_grants WHERE user_id=? AND plugin_name=? AND app_id=?',
                [$userId, $app['plugin'], $appId]) > 0;
            if ($exists) {
                // 再次授权 = 更新权限 + 复活（清空 revoked_at）
                DB::run('UPDATE oauth_grants SET scopes=?, app_name=?, app_desc=?, icon=?, updated_at=?, revoked_at=NULL
                         WHERE user_id=? AND plugin_name=? AND app_id=?',
                    [implode(',', $clean), (string)$app['name'], (string)$app['desc'], (string)$app['icon'],
                        $now, $userId, $app['plugin'], $appId]);
            } else {
                DB::insert('oauth_grants', [
                    'user_id' => $userId, 'plugin_name' => $app['plugin'], 'app_id' => $appId,
                    'app_name' => (string)$app['name'], 'app_desc' => (string)$app['desc'], 'icon' => (string)$app['icon'],
                    'scopes' => implode(',', $clean),
                    'created_at' => $now, 'updated_at' => $now, 'revoked_at' => null,
                ]);
            }
            Plugin::fire('oauth.granted', [$userId, $app['plugin'], $appId, $clean]);
            return [true, '已授权「' . $app['name'] . '」', $clean];
        }

        /**
         * 撤销授权（幂等）。**保留行**并盖 revoked_at：留审计，再授权即复活。
         * @return array [ok, msg]
         */
        public static function revoke(int $userId, string $plugin, string $appId): array
        {
            if ($userId <= 0) return [false, '请先登录'];
            if ($plugin === '' || $appId === '') return [false, '参数错误'];
            DB::run('UPDATE oauth_grants SET revoked_at=?, updated_at=? WHERE user_id=? AND plugin_name=? AND app_id=?',
                [time(), time(), $userId, $plugin, $appId]);
            Plugin::fire('oauth.revoked', [$userId, $plugin, $appId]);
            return [true, '已取消授权'];
        }
    }
}

/* ============================ 路由 ============================ */
/** 登录用户才能操作（游客直接拒，不给「游客授权」这种语义） */
$oaGuard = function (array $ctx): bool {
    return ($ctx['actor']['kind'] ?? '') === 'user';
};

Plugin::route('plugin_oauth_core_apps', function (array $ctx) use ($oaGuard) {
    if (!$oaGuard($ctx)) Api::json(['ok' => false, 'msg' => '请先登录'], 403);
    Api::json(['ok' => true, 'data' => OAuth::appsForUser((int)$ctx['actor']['id'])]);
});

Plugin::route('plugin_oauth_core_grant', function (array $ctx) use ($oaGuard) {
    if (!$oaGuard($ctx)) Api::json(['ok' => false, 'msg' => '请先登录'], 403);
    // ⚠️ 前端 OwApi.post 用 encodeURIComponent 逐字段编码，**数组会被拍成逗号串**
    // （["a","b"] → "a,b"），这里必须兼容字符串形态。
    $raw = $ctx['post']['scopes'] ?? [];
    if (!is_array($raw)) $raw = explode(',', (string)$raw);
    [$ok, $msg, $scopes] = OAuth::grant(
        (int)$ctx['actor']['id'],
        (string)($ctx['post']['plugin'] ?? ''),
        (string)($ctx['post']['app_id'] ?? ''),
        $raw
    );
    Api::json(['ok' => $ok, 'msg' => $msg, 'scopes' => $scopes]);
});

Plugin::route('plugin_oauth_core_revoke', function (array $ctx) use ($oaGuard) {
    if (!$oaGuard($ctx)) Api::json(['ok' => false, 'msg' => '请先登录'], 403);
    [$ok, $msg] = OAuth::revoke(
        (int)$ctx['actor']['id'],
        (string)($ctx['post']['plugin'] ?? ''),
        (string)($ctx['post']['app_id'] ?? '')
    );
    Api::json(['ok' => $ok, 'msg' => $msg]);
});

Plugin::asset('js', 'oauth-core/chat.js', 'auth,front');
Plugin::asset('css', 'oauth-core/style.css');
