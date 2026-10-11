<?php
/**
 * 跨引擎数据层：PDO 适配 SQLite / MySQL / PostgreSQL
 * 统一占位符(?)、分页与方言差异封装；schema 自动初始化与迁移。
 */
class DB
{
    private static ?PDO $pdo = null;
    private static string $driver = 'sqlite';
    private static array $cfg = [];

    public static function init(array $cfg): void
    {
        self::$cfg = $cfg;
        $db = $cfg['db'];
        self::$driver = $db['driver'];
        switch ($db['driver']) {
            case 'mysql':
                $dsn = "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}";
                self::$pdo = new PDO($dsn, $db['user'], $db['pass'], self::opts());
                // 存储引擎必须在**连接层**钉死。phpStudy 的 my.ini 常把 default-storage-engine
                // 设成 MyISAM，而本项目所有建表语句都不带 ENGINE 子句 → 表会整片落到 MyISAM 上。
                // 后果有两层，第二层才致命：
                //  ① MyISAM 索引上限 1000 字节，rate_limits 的主键 (bucket, ident) = 191×4×2 = 1528
                //     → 建表直接 1071（InnoDB 是 767 / DYNAMIC 下 3072）；
                //  ② **MyISAM 不支持事务**：安装的 beginTransaction()、消息与附件的多表写入会被
                //     静默忽略，「装一半」的保护等于没有，而且不报错、看不出来。
                // 会话层设一次就覆盖核心与所有插件的 DDL，不必给每条建表拼 ENGINE=InnoDB。
                self::$pdo->exec('SET SESSION default_storage_engine=InnoDB');
                break;
            case 'pgsql':
                $dsn = "pgsql:host={$db['host']};port={$db['port']};dbname={$db['name']}";
                self::$pdo = new PDO($dsn, $db['user'], $db['pass'], self::opts());
                break;
            default:
                self::$driver = 'sqlite';
                @mkdir(dirname($db['sqlite']), 0775, true);
                self::$pdo = new PDO('sqlite:' . $db['sqlite'], null, null, self::opts());
                self::$pdo->exec('PRAGMA journal_mode=WAL');
                self::$pdo->exec('PRAGMA foreign_keys=ON');
        }
    }

    private static function opts(): array
    {
        return [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
    }

    public static function pdo(): PDO { return self::$pdo; }
    public static function driver(): string { return self::$driver; }

    /**
     * 建表片段：长文本列。
     *
     * MySQL 的 TEXT/BLOB **不允许有默认值**（错误 1101），而只写 `TEXT NOT NULL`
     * 又会在「INSERT 省略这一列」时报 1364（Field doesn't have a default value，
     * 严格模式下必炸）。所以 MySQL 上退化成可空无默认，其余驱动保持原样。
     * 读取侧一律 `(string)$row['x']` / `?? ''`，可空不会改变行为。
     *
     * @param string $col     列名
     * @param string $default 非 MySQL 驱动的默认值字面量（带引号），如 "''" / "'[]'"
     */
    public static function textCol(string $col, string $default = "''"): string
    {
        if (self::$driver === 'mysql') return "$col TEXT NULL";
        return "$col " . self::t('text') . " NOT NULL DEFAULT $default";
    }

    /**
     * 「按字面大小写不敏感排序」的方言写法。
     *
     * SQLite 默认按字节序排（'Z' < 'a'），要大小写无关必须显式 COLLATE NOCASE；
     * MySQL 的 utf8mb4_*_ci 与 PostgreSQL 的默认 collation 本来就是大小写无关，
     * 再拼一句 COLLATE NOCASE 反而炸 —— MySQL 直接 1273 Unknown collation。
     * @param string $expr 排序表达式（如 'u.nickname'）
     */
    public static function ci(string $expr): string
    {
        return self::$driver === 'sqlite' ? "$expr COLLATE NOCASE" : $expr;
    }

    /**
     * 幂等建索引。
     *
     * ⚠️ MySQL 全系（含 8.0）都**不支持** `CREATE INDEX IF NOT EXISTS` —— 不是报错就是
     * 被 try/catch 吞掉，而吞掉的后果比报错更坏：**索引静默缺失**，表一大就全表扫，
     * 且后台看不出任何异常。所以 MySQL 走 information_schema 先查后建。
     * SQLite / PostgreSQL 的 IF NOT EXISTS 是真支持的，直接用。
     */
    public static function createIndex(string $name, string $table, string $cols, bool $unique = false): void
    {
        $u = $unique ? 'UNIQUE ' : '';
        if (self::$driver === 'mysql') {
            $has = (bool)self::val(
                'SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema=DATABASE() AND table_name=? AND index_name=?',
                [$table, $name]
            );
            if ($has) return;
            self::$pdo->exec("CREATE {$u}INDEX $name ON $table ($cols)");
            return;
        }
        self::$pdo->exec("CREATE {$u}INDEX IF NOT EXISTS $name ON $table ($cols)");
    }

    /**
     * 唯一键冲突就整行忽略的插入（三驱动语法各不相同）。
     * @param array $keys 冲突判定列（仅 PG 需要：ON CONFLICT (keys) DO NOTHING）
     */
    public static function insertIgnore(string $table, array $data, array $keys): int
    {
        $cols = array_keys($data);
        $ph = implode(',', array_fill(0, count($cols), '?'));
        $colList = implode(',', $cols);
        switch (self::$driver) {
            case 'mysql':
                $sql = "INSERT IGNORE INTO $table ($colList) VALUES ($ph)";
                break;
            case 'pgsql':
                $sql = "INSERT INTO $table ($colList) VALUES ($ph) ON CONFLICT (" . implode(',', $keys) . ') DO NOTHING';
                break;
            default:
                $sql = "INSERT OR IGNORE INTO $table ($colList) VALUES ($ph)";
        }
        self::run($sql, array_values($data));
        return (int)self::$pdo->lastInsertId();
    }

    public static function run(string $sql, array $args = []): PDOStatement
    {
        $st = self::$pdo->prepare($sql);
        $st->execute($args);
        return $st;
    }

    public static function one(string $sql, array $args = []): ?array
    {
        $r = self::run($sql, $args)->fetch();
        return $r === false ? null : $r;
    }

    public static function all(string $sql, array $args = []): array
    {
        return self::run($sql, $args)->fetchAll();
    }

    public static function val(string $sql, array $args = [])
    {
        return self::run($sql, $args)->fetchColumn();
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = "INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES (" .
               implode(',', array_fill(0, count($cols), '?')) . ")";
        self::run($sql, array_values($data));
        return (int)self::$pdo->lastInsertId();
    }

    /** 跨引擎 UPSERT：$keys 为唯一键列 */
    public static function upsert(string $table, array $data, array $keys): void
    {
        $cols = array_keys($data);
        $sets = [];
        foreach ($cols as $c) {
            if (!in_array($c, $keys, true)) $sets[] = "$c=EXCLUDED_$c";
        }
        if (self::$driver === 'mysql') {
            $setSql = implode(',', array_map(fn($c) => "$c=VALUES($c)", array_diff($cols, $keys)));
            $sql = "INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES (" .
                   implode(',', array_fill(0, count($cols), '?')) . ")" .
                   ($setSql ? " ON DUPLICATE KEY UPDATE $setSql" : " ON DUPLICATE KEY UPDATE " . $keys[0] . "=" . $keys[0]);
        } else {
            $setSql = $sets
                ? implode(',', array_map(fn($c) => "$c=excluded.$c", array_diff($cols, $keys)))
                : 'NOTHING';
            $sql = "INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES (" .
                   implode(',', array_fill(0, count($cols), '?')) . ")" .
                   ($sets
                       ? " ON CONFLICT (" . implode(',', $keys) . ") DO UPDATE SET $setSql"
                       : " ON CONFLICT (" . implode(',', $keys) . ") DO NOTHING");
        }
        self::run($sql, array_values($data));
    }

    /** 自增主键方言 */
    /** 自增主键片段（三驱动语法不同）。插件建表要用它，别自己写 AUTOINCREMENT。 */
    public static function autoId(): string
    {
        return match (self::$driver) {
            'mysql' => 'INT AUTO_INCREMENT PRIMARY KEY',
            'pgsql' => 'SERIAL PRIMARY KEY',
            default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        };
    }

    private static function t(string $type): string
    {
        // 统一类型映射
        $map = [
            'mysql' => ['text' => 'TEXT', 'str' => 'VARCHAR(191)', 'int' => 'INT', 'bigint' => 'BIGINT', 'ts' => 'BIGINT'],
            'pgsql' => ['text' => 'TEXT', 'str' => 'VARCHAR(191)', 'int' => 'INTEGER', 'bigint' => 'BIGINT', 'ts' => 'BIGINT'],
            'sqlite'=> ['text' => 'TEXT', 'str' => 'TEXT', 'int' => 'INTEGER', 'bigint' => 'INTEGER', 'ts' => 'INTEGER'],
        ];
        // 支持 varchar(N) 形式，其余走统一映射；未知类型原样返回，绝不返回 null
        if (preg_match('/^varchar\((\d+)\)$/i', $type, $m)) {
            return self::$driver === 'sqlite' ? 'TEXT' : 'VARCHAR(' . $m[1] . ')';
        }
        return $map[self::$driver][$type] ?? $type;
    }

    /** 初始化全部表结构（幂等） */
    public static function migrate(): void
    {
        $id = self::autoId();
        $text = self::t('text'); $str = self::t('str');
        $int = self::t('int');   $ts  = self::t('ts');

        $tables = [
            "CREATE TABLE IF NOT EXISTS settings (k $str PRIMARY KEY, v $text)",
            "CREATE TABLE IF NOT EXISTS users (
                id $id, nickname $str NOT NULL, email $str NOT NULL,
                password $str NOT NULL, avatar $text,
                role $str NOT NULL DEFAULT 'member', title $str,
                client_key $str, status $int NOT NULL DEFAULT 1,
                email_verified $int NOT NULL DEFAULT 0,
                created_at $ts NOT NULL, last_login $ts)",
            "CREATE TABLE IF NOT EXISTS guests (
                id $id, token $str NOT NULL UNIQUE, nickname $str NOT NULL,
                client_key $str, ip $str, daily_count $int NOT NULL DEFAULT 0,
                daily_date $str, created_at $ts NOT NULL)",
            "CREATE TABLE IF NOT EXISTS email_codes (
                id $id, email $str NOT NULL, code $str NOT NULL, type $str NOT NULL,
                ip $str, used $int NOT NULL DEFAULT 0,
                expires_at $ts NOT NULL, created_at $ts NOT NULL)",
            "CREATE TABLE IF NOT EXISTS rooms (
                id $id, name $str NOT NULL, slug $str NOT NULL UNIQUE,
                type $str NOT NULL DEFAULT 'public', password $str,
                min_role $str NOT NULL DEFAULT 'guest', owner_id $int,
                description $text, status $int NOT NULL DEFAULT 1, created_at $ts NOT NULL)",
            // 群成员（v1.1.11「不公开群聊」）：房间与用户的归属关系。
            // 设计要点：
            //  - 只有**注册用户**是成员，游客不占行（游客身份随浏览器会话消亡，
            //    同名游客背后可能是任意多个人，无法审计，也不能作为邀请对象）。
            //  - (room_id, user_id) 唯一，重复邀请直接 INSERT OR REPLACE 幂等。
            //  - invited_by 记下「谁邀请的」，与 rooms.owner_id（群主）区分：
            //    群主也可能不是直接邀请者。
            //  - created_at 单列存加入时间；角色（member/admin）暂不入表，
            //    群内权限一律以 rooms.owner_id + users.role 为准，避免两套口径打架。
            "CREATE TABLE IF NOT EXISTS room_members (
                id $id,
                room_id $int NOT NULL, user_id $int NOT NULL,
                invited_by $int NOT NULL DEFAULT 0,
                created_at $ts NOT NULL)",
            "CREATE TABLE IF NOT EXISTS messages (
                id $id, room_id $int NOT NULL, user_id $int, guest_id $int,
                nickname $str NOT NULL, role $str NOT NULL DEFAULT 'guest',
                title $str, avatar $text,
                type $str NOT NULL DEFAULT 'text', content $text,
                to_user_id $int, to_guest_id $int, to_nickname $str,
                recalled $int NOT NULL DEFAULT 0,
                deleted $int NOT NULL DEFAULT 0, deleted_at $int NOT NULL DEFAULT 0,
                deleted_by $str,
                ip $str, created_at $ts NOT NULL)",
            "CREATE TABLE IF NOT EXISTS bans (
                id $id, type $str NOT NULL, target $str NOT NULL,
                room_id $int NOT NULL DEFAULT 0, reason $text,
                expires_at $ts, created_by $str, created_at $ts NOT NULL)",
            "CREATE TABLE IF NOT EXISTS sensitive_words (
                id $id, word $str NOT NULL, replacement $str NOT NULL DEFAULT '***',
                enabled $int NOT NULL DEFAULT 1)",
            // announcements（旧系统公告）表已随 v1.0.102 剥离为 announcements 插件，
            // 由插件表 plugin_announcements 接管；存量库中的旧表保留不删（含迁移源数据）
            "CREATE TABLE IF NOT EXISTS security_logs (
                id $id, action $str NOT NULL, actor $str, ip $str,
                data $text, created_at $ts NOT NULL)",
            "CREATE TABLE IF NOT EXISTS login_attempts (
                identity $str PRIMARY KEY, fails $int NOT NULL DEFAULT 0,
                locked_until $ts, updated_at $ts NOT NULL)",
            "CREATE TABLE IF NOT EXISTS rate_limits (
                bucket $str NOT NULL, ident $str NOT NULL,
                window_start $ts NOT NULL, count $int NOT NULL DEFAULT 0,
                PRIMARY KEY (bucket, ident))",
            "CREATE TABLE IF NOT EXISTS stickers (
                id $id, owner_key $str NOT NULL, url $text NOT NULL, created_at $ts NOT NULL)",
            "CREATE TABLE IF NOT EXISTS online (
                token $str PRIMARY KEY, user_id $int, guest_id $int,
                nickname $str NOT NULL, role $str NOT NULL DEFAULT 'guest',
                title $str, avatar $text, room_id $int NOT NULL DEFAULT 0,
                ip $str, last_seen $ts NOT NULL)",
            "CREATE TABLE IF NOT EXISTS plugins (
                name $str PRIMARY KEY, enabled $int NOT NULL DEFAULT 0, config $text)",
            // 群聊审核回收站：before 存操作前快照（delete 为整行 JSON），撤销=按快照还原
            "CREATE TABLE IF NOT EXISTS room_review_trash (
                id $id, room_id $int NOT NULL, room_name $str NOT NULL,
                action $str NOT NULL, before_data $text NOT NULL,
                undone $int NOT NULL DEFAULT 0, created_at $ts NOT NULL)",
            // 插件计划任务（v1.1.13）：由 Plugin::cron() 声明后同步入库，
            // 本表只存**调度状态**，执行逻辑在 Plugin::runCron()。
            // plugin+name 唯一 —— 插件重复加载（清单重建时也会真加载一次）不会插出重复行。
            // ⚠️ 列名是 interval_sec 而不是 interval：`INTERVAL` 是 MySQL 保留字，
            //   叫 interval 会在建表时直接 1064（SQLite 不拦，所以这个坑藏到第一次连 MySQL 才炸）。
            "CREATE TABLE IF NOT EXISTS cron_tasks (
                id $id, plugin $str NOT NULL DEFAULT '', name $str NOT NULL,
                interval_sec $int NOT NULL DEFAULT 3600,
                last_run_at $int NOT NULL DEFAULT 0, next_run_at $int NOT NULL DEFAULT 0,
                last_status $str NOT NULL DEFAULT '', run_count $int NOT NULL DEFAULT 0,
                enabled $int NOT NULL DEFAULT 1, created_at $ts NOT NULL, updated_at $ts NOT NULL)",
            // 计划任务执行日志：每次执行落一条（含被跳过 / 失败），后台可查最近成败。
            "CREATE TABLE IF NOT EXISTS cron_logs (
                id $id, name $str NOT NULL DEFAULT '', status $str NOT NULL DEFAULT 'ok',
                message $str NOT NULL DEFAULT '', duration $int NOT NULL DEFAULT 0,
                created_at $ts NOT NULL)",
            // v1.1.14「仅自己隐藏」的消息黑名单。
            // 语义边界（务必分清，勿与 messages.deleted 混用）：
            //   messages.deleted=1  = 真删除，**所有人**都不再看到（清空正文，留行审计）；
            //   本表一行            = 只是「我不想看这条」，**别人照常能看到**，
            //                           用于普通用户删除他人消息时的降级形态。
            // 因此读消息的每条通道（history / poll / dm_history / dm_poll / conversations）
            // 都必须扣掉本表命中的行，漏一处就会出现「删了却又刷出来」。
            "CREATE TABLE IF NOT EXISTS message_hides (
                id $id, user_id $int NOT NULL, message_id $int NOT NULL,
                created_at $ts NOT NULL)",
            // 联系人（v1.1.24）：**双向**好友关系，一人一行。
            // 设计要点：
            //  - 不用 `UNIQUE(friend_id)`：A 加 B 与 B 加 A 是**两条独立行**，
            //    「我加了他」不等于「他加了我」。查询时恒定 WHERE user_id = 我。
            //  - 只存注册用户（与 room_members 同口径）：游客身份随会话消亡，
            //    写进表也无法审计，且回查不到 users 行。
            //  - 无 nickname/avatar 等快照列，一律 JOIN users 取实时值 ——
            //    好友改昵称/换头像后联系人列表应立即同步，存快照会长期不一致。
            "CREATE TABLE IF NOT EXISTS friends (
                id $id, user_id $int NOT NULL, friend_id $int NOT NULL,
                created_at $ts NOT NULL)",
            // 好友请求（v1.3.71）：加好友不再即时生效 —— 先申请（可附验证消息），
            // 对方「通过」之后才成为联系人、才能互发私信。
            //  - status：0 待处理 / 1 已通过 / 2 已拒绝。收件人处理时才写 handled_at。
            //  - note 是申请那一刻写的话，属于历史事实，破例存文本快照（不随签名变化）。
            //  - 为什么不往 friends 上加 status 列：friends 一行代表「关系已成立」，
            //    混进待处理/被拒的行后，每个联系人查询都要多一句 WHERE status=1，
            //    而请求列表要的恰恰是 status=0/2 —— 两类查询互相拖累。
            //  - 通过时只写**一行**（处理人 → 申请人）：联系人查询自 v1.3.10 起
            //    双向可见（见 Chat::friends），一行就够让两边都看到对方。
            "CREATE TABLE IF NOT EXISTS friend_req (
                id $id, from_id $int NOT NULL, to_id $int NOT NULL,
                note $text, status $int NOT NULL DEFAULT 0,
                created_at $ts NOT NULL, handled_at $int NOT NULL DEFAULT 0)",
            // 会话置顶（v1.2.28）：**按用户**生效，不是全局状态。
            // 私聊置顶只影响自己的列表顺序（peer_key 形如 'dm:user:20'）；
            // key 统一带前缀，为以后群聊置顶（'room:5'）留位，不用改表。
            // 为什么不用 rooms/messages 上的字段：置顶是**个人偏好**，
            // 放业务表上会让「A 置顶」影响 B 的列表。
            "CREATE TABLE IF NOT EXISTS conversation_pins (
                id $id, user_id $int NOT NULL, peer_key $str NOT NULL, created_at $ts NOT NULL)",
            // 会话已读位点（v1.3.19）：**按用户**生效，存「该会话最后读到哪条消息 id」。
            // 为什么不放 message_hides 那样的一行一消息 —— 那是隐藏（累积），
            // 已读是单调递增的一个水位，一条记录即可，历史体积恒定。
            // peer_key 与 conversation_pins 同口径：'room:5' / 'dm:user:20'。
            "CREATE TABLE IF NOT EXISTS conversation_reads (
                id $id, user_id $int NOT NULL, peer_key $str NOT NULL, last_id $int NOT NULL DEFAULT 0, updated_at $ts NOT NULL)",
            // 系统通知（v1.3.52）：**按用户**存，绝不复用 rooms/messages ——
            // 密码修改、封禁解封这类内容属于账号隐私，放进全局房间会让 A 看到 B 的通知。
            // kind 是事件类型（pwd / email / twofa_on / twofa_off / locked / banned / unbanned），
            // 前端按它挑图标；body 存成品文案，避免把中文文案散到 JS 里。
            // read_at=0 表示未读，用于 rail 上的红点计数。
            "CREATE TABLE IF NOT EXISTS notices (
                id $id, user_id $int NOT NULL, kind $str NOT NULL DEFAULT 'system',
                body $text, params $text, created_at $ts NOT NULL, read_at $int NOT NULL DEFAULT 0)",
        ];
        foreach ($tables as $sql) self::$pdo->exec($sql);

        // v1.3.61：把历史上落成 MyISAM 的表就地转成 InnoDB（自愈，不需要重建库）。
        // 上面那条 SET 只影响**之后**的建表；这次迁移之前已经建好的表还是 MyISAM，
        // 而 CREATE TABLE IF NOT EXISTS 不会动它们 —— 不转就会留下「一半支持事务、一半不支持」
        // 的库，比全是 MyISAM 更难查。information_schema 这条查询很便宜，且正常情况下返回 0 行。
        if (self::$driver === 'mysql') {
            $myisam = self::all(
                "SELECT table_name AS t FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND engine='MyISAM'"
            );
            foreach ($myisam as $row) {
                $t = (string)($row['t'] ?? '');
                if ($t === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $t)) continue;   // 只认本项目风格的表名
                self::$pdo->exec('ALTER TABLE `' . $t . '` ENGINE=InnoDB');
            }
        }

        // v1.3.56：存量库补 notices.params —— 通知改存「kind + 参数」，body 只作渲染快照。
        // 为什么两样都留：params 让语言包能翻译模板（动态文案拼成整句就永远匹配不上字典），
        // body 让管理员直接读库时也看得懂、且旧前端不改也能继续显示。
        self::ensureColumn('notices', 'params', 'text', "''");

        // v1.3.57：cron_tasks 旧列名 `interval` 撞 MySQL 保留字，改名 interval_sec。
        // 改名语法三驱动各不相同，必须分支：MySQL 5.7 **没有** RENAME COLUMN（8.0.3 才有），
        // 只能 CHANGE COLUMN 重写一遍列定义；SQLite 3.25+ / PG 都支持 RENAME COLUMN。
        // 双判（有旧列 **且** 没有新列）才动手，保证幂等：中途失败重来不会撞「列已存在」。
        if (self::hasColumn('cron_tasks', 'interval') && !self::hasColumn('cron_tasks', 'interval_sec')) {
            $int = self::t('int');
            if (self::$driver === 'mysql') {
                self::$pdo->exec("ALTER TABLE cron_tasks CHANGE COLUMN `interval` interval_sec $int NOT NULL DEFAULT 3600");
            } else {
                self::$pdo->exec('ALTER TABLE cron_tasks RENAME COLUMN interval TO interval_sec');
            }
        }

        // v1.3.59：users.email / email_codes.email 一次性归一为小写。
        // 不修不行：SQLite 排序是 BINARY，`Admin@qq.com` 与 `admin@qq.com` 是两个账号 ——
        // 安装/注册时浏览器自动首字母大写、登录时手输全小写，就直接「邮箱未注册」。
        // 用 settings 标记做一次性（migrate 每请求都跑，不能每次都全表扫）。
        if ((string)self::setting('email_lower_migrated', '') !== '1') {
            $byLower = [];
            foreach (self::all('SELECT id, email FROM users') as $r) {
                $byLower[mb_strtolower(trim((string)$r['email']))][] = $r;
            }
            $changed = 0; $collide = [];
            foreach ($byLower as $low => $group) {
                // 归一后撞车的（两个账号只差大小写）一律不动：合并账号等于删数据，
                // 必须人来判断，这里只把它记进安全日志。
                if (count($group) > 1) { $collide[] = $low; continue; }
                if ((string)$group[0]['email'] === $low) continue;
                self::run('UPDATE users SET email=? WHERE id=?', [$low, (int)$group[0]['id']]);
                $changed++;
            }
            // 待发中的验证码也一并归一，否则「用大写地址刚领了码」的人在部署后那一次校验会失败
            foreach (self::all('SELECT id, email FROM email_codes') as $c) {
                $low = mb_strtolower(trim((string)$c['email']));
                if ((string)$c['email'] !== $low) self::run('UPDATE email_codes SET email=? WHERE id=?', [$low, (int)$c['id']]);
            }
            self::setSetting('email_lower_migrated', '1');
            if ($collide && class_exists('Sec')) {
                Sec::log('email_case_collision', '', ['count' => count($collide), 'emails' => implode(',', array_slice($collide, 0, 20))]);
            }
        }

        // 计划任务表与几张关系表的唯一/查询索引。
        // ⚠️ 这里以前是「exec + try/catch 吞掉」，看着兼容，实际 MySQL 上**一条索引都没建成**
        //   （它不支持 CREATE INDEX IF NOT EXISTS，异常被吞掉=静默缺失），表一大就全表扫。
        //   改走 DB::createIndex()：MySQL 先查 information_schema 再建，另两驱动用 IF NOT EXISTS。
        foreach ([
            ['idx_cron_tasks_key',   'cron_tasks',      'plugin, name',        true],
            ['idx_cron_tasks_due',   'cron_tasks',      'enabled, next_run_at', false],
            ['idx_cron_logs_created','cron_logs',       'created_at',           false],
            // 同一用户重复隐藏同一条消息必须幂等，否则反复点会插出一堆重复行
            ['idx_msg_hides_key',    'message_hides',   'user_id, message_id', true],
            // 同一人重复加同一好友必须幂等（否则联系人列表出现重名行）
            ['idx_friends_pair',     'friends',         'user_id, friend_id',  true],
            // 同一用户对同一会话只能置顶一次，重复点由代码先查后写，这里兜底防重行
            ['idx_conv_pins_key',    'conversation_pins',  'user_id, peer_key', true],
            ['idx_conv_reads_key',   'conversation_reads', 'user_id, peer_key', true],
            // 通知永远是「按用户取最近 N 条」和「按用户数未读」，没有跨用户查询
            ['idx_notices_user',     'notices',         'user_id, id',          false],
            // 好友请求两个方向都要查：收件箱（待我处理）与发件箱（我发出的状态）。
            // 重复待处理请求由代码先查后插（Chat::friendRequest），不建唯一索引 ——
            // 同一对人可以「拒了再加」，历史行要留。
            ['idx_friend_req_to',    'friend_req',      'to_id, status, id',    false],
            ['idx_friend_req_from',  'friend_req',      'from_id, status, id',  false],
        ] as $ix) {
            self::createIndex($ix[0], $ix[1], $ix[2], $ix[3]);
        }

        // ---------- 增量迁移（幂等） ----------
        self::addColumn('messages', 'quote', 'text', "''");   // 引用快照 JSON：{nick,text}（v1.0.69 引用功能）
        self::addColumn('rooms', 'avatar', 'text', "''");     // 群聊头像（v1.0.76，uploads/avatar/ 下的相对路径）
        self::addColumn('users', 'points', 'int', '0');   // 用户积分
        self::addColumn('users', 'birthdate', 'varchar(10)', "''");   // 出生日期（年龄限制注册用）
        // v1.2.56 用户概览：注册 IP 与最后登录 IP（last_login 时间戳建表时就有）。
        // 存量用户两列为空 → 前端显示「—」；登录成功时由 Auth::login 回写 last_login_ip。
        self::addColumn('users', 'reg_ip', 'varchar(64)', "''");
        self::addColumn('users', 'last_login_ip', 'varchar(64)', "''");
        // v1.1.0 软删除：deleted=1 表示「已删除」。行保留（昵称/时间/IP 可审计），
        // content 同步清空（原内容不可恢复），到期由 Chat::purgeExpired() 物理删除。
        // 与 recalled（撤回）区分：撤回是用户自己的动作且不涉及合规留痕。
        self::addColumn('messages', 'deleted', 'int', '0');
        self::addColumn('messages', 'deleted_at', 'int', '0');

        // ---------- 生成式头像（v1.3.11） ----------
        // 字段模型参考 参考文献/bbs1org-main：`风格 + 种子` 而不是图片路径。
        //   avatar_type  'upload' = 用 avatar 里的上传路径；'generated'/'空' = 用风格生成
        //   avatar_style 风格 slug（见 Auth::avatarStyles()，对应 DiceBear 10.x 风格名）
        //   avatar_seed  生成种子；为空时由服务端按 client_key / uid 稳定派生，
        //                同一账号每次进站拿到同一个头像
        // ⚠️ 存量数据不迁移：老记录 avatar_type 默认**空**，渲染时按
        //    「avatar 有上传路径就用上传」兼容（见 Auth::avatarUrlFor）。
        //    正是为了这个，avatar_type 的默认值必须是空串而不是 'generated' ——
        //    ALTER TABLE ADD COLUMN 的默认值会回填到所有既有行。
        self::addColumn('users', 'avatar_type', 'str', "''");
        self::addColumn('users', 'avatar_style', 'str', "''");
        self::addColumn('users', 'avatar_seed', 'str', "''");
        // 群聊同一套字段；**默认为空 = 回落创建者头像**（用户明确要求：
        // 群没有自定义头像就用创建者的头像）。原来的固定剪影图一并去掉。
        self::addColumn('rooms', 'avatar_type', 'str', "''");
        self::addColumn('rooms', 'avatar_style', 'str', "''");
        self::addColumn('rooms', 'avatar_seed', 'str', "''");
        // v1.3.14：游客也有自己的头像（默认「豆苗」sprouts + 随机档位），存到表里才能
        // 「同一个游客每次进来头像一致、不同游客长得不一样」。此前只能由 client_key 派生，
        // 等于每个游客都长一个样，且换不了。
        self::addColumn('guests', 'avatar_type', 'str', "'generated'");
        self::addColumn('guests', 'avatar_style', 'str', "'sprouts'");
        self::addColumn('guests', 'avatar_seed', 'str', "''");
        self::addColumn('messages', 'deleted_by', 'varchar(64)', "''");
        // ⚠️ v1.2.4：keep_forever（免清理标记）已随「撤回=立即物理删除」下线。
        // 撤回的消息根本不进库，不存在「被保留期清理」的问题，该标记失去意义。
        // 存量列保留不删（与 rooms.min_age 同源策略）：删列有数据迁移风险，
        // 而留着它无害 —— 应用层已不再读写。**勿在业务代码中重新启用。**
        // 已废弃字段：rooms.min_age（进入该房间的最低年龄）随 1.0.31 下线，应用层已不再读写。
        // 保留此行仅为兼容历史数据库（列仍存在且幂等），勿在业务代码中重新启用。
        self::addColumn('rooms', 'min_age', 'int', '0');
        // v1.1.11「公开 / 不公开」开关：与 type（public/password/role）**正交**。
        // type 管的是「进入方式」（要不要密码 / 要什么角色），is_public 管的是
        //「谁能发现这个群」——公开群进公开列表、游客可进可发言；
        // 不公开群不进公开列表，只有群主与 room_members 里的成员能进。
        // 默认 1（公开）：存量群全部保持原有可见性，不做隐式收紧。
        self::addColumn('rooms', 'is_public', 'int', '1');
        // 不公开群的邀请码：不公开群不出现在列表里，只能靠邀请链接进入。
        // 为空表示从未生成过（前端显示「生成邀请链接」按钮）；
        // 群主可在群聊设置里重置（重置后旧链接立即失效）。
        self::addColumn('rooms', 'invite_code', 'varchar(16)', "''");

        // v1.0.33 起取消「用户名」：账号不再有独立登录名，显示名统一为 nickname（昵称）。
        // 迁移策略（一次性、幂等）：先把昵称回填为原用户名（原 nickname 里用户自定义的值按需求丢弃），
        // 再物理删除 username 列——必须真删，否则新插入语句会因该列 NOT NULL UNIQUE 且无默认值而失败。
        if (self::hasColumn('users', 'username')) {
            self::$pdo->exec('UPDATE users SET nickname=username');
            self::dropColumn('users', 'username');
        }

        // ---------- v1.1.0：私聊搬进虚拟空间（room_id=0） ----------
        // 1.0.x 的私信是「在某群里发给某人」，room_id 指向那个群；v1.1.0 起私聊是独立会话，
        // 统一落在 room_id=0。若不迁移，存量私信既不会出现在私聊会话列表里，
        // 又会继续混在群聊历史中（对双方可见、对其他人不可见），语义割裂。
        // 幂等：仅处理 room_id>0 的存量行，迁移后 WHERE 不再命中。
        self::$pdo->exec("UPDATE messages SET room_id=0 WHERE type='private' AND room_id>0");

        // ---------- v1.2.1：私聊身份与消息形态解耦 ----------
        // 此前 type='private' 一列兼表「这是私聊」与「消息形态是文本」，
        // 私聊里发图片/文件时 type 被迫变成 image/file → 服务端认不出私聊（直接报「群聊不存在」），
        // 历史/轮询 SQL 也永远查不出这类消息。现私聊身份改由 room_id=0 + to_user_id 承载，
        // 故把存量 'private' 行改写为 'text'（语义不丢，只是归位到消息形态列）。
        // 幂等：改写后 WHERE 不再命中。必须排在上面 room_id 迁移之后。
        self::$pdo->exec("UPDATE messages SET type='text' WHERE type='private'");

        // 热路径索引。原来 MySQL 分支是「去掉 IF NOT EXISTS 再逐个 try/catch」——
        // 结果是**每次请求都真的去建一遍已存在的索引**再吞掉异常，白跑一趟还留着
        // 「哪天异常没被吞就整站 500」的隐患。统一走 createIndex()：先查存在性再建。
        foreach ([
            ['idx_msg_room',      'messages',      'room_id, id'],
            ['idx_msg_private',   'messages',      'to_user_id, to_guest_id'],
            // v1.1.0 私聊：历史/增量轮询按「room_id=0 + 发送方 + id」过滤，补一条发送方索引
            ['idx_msg_dm_sender', 'messages',      'user_id, guest_id, id'],
            ['idx_online_seen',   'online',        'last_seen'],
            ['idx_email',         'email_codes',   'email, type, created_at'],
            ['idx_logs',          'security_logs', 'created_at'],
        ] as $ix) {
            self::createIndex($ix[0], $ix[1], $ix[2]);
        }
    }

    /** 列是否已存在（三引擎方言） */
    private static function hasColumn(string $table, string $col): bool
    {
        try {
            if (self::$driver === 'sqlite') {
                foreach (self::all("PRAGMA table_info($table)") as $r) {
                    if (($r['name'] ?? '') === $col) return true;
                }
                return false;
            }
            if (self::$driver === 'pgsql') {
                return (bool)self::val(
                    'SELECT COUNT(*) FROM information_schema.columns WHERE table_name=? AND column_name=?',
                    [$table, $col]
                );
            }
            return (bool)self::val(
                'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',
                [$table, $col]
            );
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * 幂等加列的**公开入口**，供插件建表 / 升级时使用（v1.1.12）。
     *
     * 为什么不直接把 addColumn 改成 public：核心内部（含 migrate()）一律走
     * private addColumn，这里另开一个语义明确的薄封装，插件侧读代码时
     * 一眼就知道「这是给插件用的公开 API」，而不是误以为可以随便传表名。
     *
     * ⚠️ 插件踩过的坑（v1.1.12 亲历）：直接 DB::addColumn() 会抛
     * 「Call to private method DB::addColumn() from global scope」，
     * 而 Plugin::loadPlugin() 用 try/catch 吞掉插件异常只记 plugin_error，
     * **表现为插件「静默半个身位」—— 后续所有 Plugin::on 都没注册，
     * 但缓存清单仍写着旧的 hooks 列表**，排查时极易误判成「钩子没触发」。
     * 插件顶层做 schema 迁移前，务必确认调用的方法是 public。
     *
     * @param string $type    类型，如 'int' / 'varchar(16)'
     * @param string $default 带引号的默认值字面量，如 "'1'" / "''"（不是裸值）
     */
    public static function ensureColumn(string $table, string $col, string $type, string $default): void
    {
        self::addColumn($table, $col, $type, $default);
    }

    /** 幂等加列：已存在则跳过 */
    private static function addColumn(string $table, string $col, string $type, string $default): void
    {
        if (self::hasColumn($table, $col)) return;
        $t = self::t($type);
        // MySQL 的 TEXT/BLOB/JSON **不能有默认值**（1101），而且给已有行的表加
        // NOT NULL 列还会报 1364/1138 —— 所以大字段一律退化成可空无默认。
        // 与 DB::textCol() 同一口径：读取侧本来就都是 (string) 强转。
        if (self::$driver === 'mysql' && preg_match('/^(TEXT|BLOB|MEDIUMTEXT|LONGTEXT|TINYTEXT|JSON)$/i', $t)) {
            self::$pdo->exec("ALTER TABLE $table ADD COLUMN $col $t NULL");
            return;
        }
        self::$pdo->exec("ALTER TABLE $table ADD COLUMN $col $t NOT NULL DEFAULT $default");
    }

    /**
     * 幂等删列：已不存在则跳过。
     *
     * SQLite 拒绝删除带 UNIQUE 约束的列（"cannot drop UNIQUE column"），
     * MySQL / PostgreSQL 无此限制。因此 SQLite 失败时退化为「建新表 → 拷数据 → 换名」重建，
     * 并在重建后恢复原表的显式索引（隐式 unique 索引随列一起消失，无需处理）。
     */
    private static function dropColumn(string $table, string $col): void
    {
        if (!self::hasColumn($table, $col)) return;
        try {
            self::$pdo->exec("ALTER TABLE $table DROP COLUMN $col");
            return;
        } catch (Throwable $e) {
            if (self::$driver !== 'sqlite') throw $e;
            self::rebuildTableWithout($table, $col);
        }
    }

    /** SQLite 专用：重建表以移除列（保留其余列定义、主键自增与显式索引） */
    private static function rebuildTableWithout(string $table, string $col): void
    {
        $pdo = self::$pdo;
        // 先取结构与索引定义：DROP TABLE 之后 sqlite_master 里就没了
        $keep = [];
        foreach (self::all("PRAGMA table_info($table)") as $c) {
            if (($c['name'] ?? '') !== $col) $keep[] = $c;
        }
        $idx = self::all(
            "SELECT name, sql FROM sqlite_master WHERE type='index' AND tbl_name=? AND sql IS NOT NULL",
            [$table]
        );

        $defs = [];
        foreach ($keep as $c) {
            $t = $c['type'] ?: 'TEXT';
            $d = '"' . $c['name'] . '" ' . $t;
            if ((int)$c['pk'] === 1) {
                // INTEGER 主键需显式声明 AUTOINCREMENT，否则重建后 rowid 复用旧值语义变化
                $d .= ' PRIMARY KEY' . (strtolower($t) === 'integer' ? ' AUTOINCREMENT' : '');
            } elseif ((int)$c['notnull'] === 1) {
                $d .= ' NOT NULL';
            }
            if ($c['dflt_value'] !== null) $d .= ' DEFAULT ' . $c['dflt_value'];
            $defs[] = $d;
        }
        $names = implode(',', array_map(fn($c) => '"' . $c['name'] . '"', $keep));
        $tmp = $table . '__new';

        $oldFk = (int)$pdo->query('PRAGMA foreign_keys')->fetchColumn();
        $pdo->exec('PRAGMA foreign_keys=OFF');
        $pdo->beginTransaction();
        try {
            $pdo->exec('DROP TABLE IF EXISTS "' . $tmp . '"');
            $pdo->exec('CREATE TABLE "' . $tmp . '" (' . implode(',', $defs) . ')');
            $pdo->exec('INSERT INTO "' . $tmp . '" (' . $names . ') SELECT ' . $names . ' FROM "' . $table . '"');
            $pdo->exec('DROP TABLE "' . $table . '"');
            $pdo->exec('ALTER TABLE "' . $tmp . '" RENAME TO "' . $table . '"');
            foreach ($idx as $ix) $pdo->exec($ix['sql']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $pdo->exec('PRAGMA foreign_keys=' . ($oldFk ? 'ON' : 'OFF'));
            throw $e;
        }
        $pdo->exec('PRAGMA foreign_keys=' . ($oldFk ? 'ON' : 'OFF'));
    }

    // ---------- 站点设置 ----------
    public static function setting(string $k, $default = null)
    {
        $v = self::val('SELECT v FROM settings WHERE k=?', [$k]);
        return $v === false || $v === null ? $default : $v;
    }

    public static function setSetting(string $k, $v): void
    {
        self::upsert('settings', ['k' => $k, 'v' => (string)$v], ['k']);
    }

    public static function defaults(): void
    {
        $defs = [
            'site_name'        => 'Owlsgo-Chat',
            'allow_register'   => '1',
            // v1.3.59：新装站点**默认不要求**注册邮箱验证码。
            // 原因很实际：核心不内置 SMTP（v1.0.81 起），发信要装并配好「邮箱验证」插件；
            // 默认开着就等于「装完站谁都无法注册」，而且报错文案指向邮件通道，
            // 管理员第一反应会是「注册功能坏了」。要开请在后台「系统设置」勾上。
            'reg_email_verify' => '0',
            'guest_browse'     => '1',
            'guest_chat'       => '1',
            // v1.1.0 起游客发言限制改为「两条消息之间的最小间隔（秒）」，
            // 取代原先的「每日发言条数上限」——限额在天级粒度过粗，
            // 30 秒间隔既能防洪，又不会让正常聊天被卡死。0 = 不限制。
            'guest_msg_interval' => '30',
            'msg_rate_limit'   => '5',   // 每条消息最小间隔(秒)内的最大条数窗口
            'msg_rate_window'  => '10',  // 频率窗口(秒)
            'msg_rate_max'     => '8',   // 窗口内最大消息数
            // v1.2.2 消息服务器保留期（天）：消息超过本期限后由 Chat::purgeExpired() 物理清除，
            // 附件文件同步删除（无法恢复）。v1.2.3 起这是**唯一**的消息保留期设置项 ——
            // 原「已删除消息保留期」(msg_deleted_retain_days) 已下线，删除消息同样适用本期限。
            // 0 = 永久保留。默认 90 天（约三个月）。
            'msg_retain_days' => '90',
            // ---------- 邮件验证码策略（v1.3.55）----------
            // 这些键刻意放在核心 settings 而不是插件自有表：读它们的是 Mailer::sendCode/verifyCode，
            // 邮件插件停用时核心必须带着默认值继续跑（开关关掉即可，不能因为少个插件就炸）。
            // 后台编辑界面由 email-verify 插件提供；SMTP 账号密码在插件自有表，不在这。
            'mail_rate_limit'    => '60',   // 同一邮箱重发间隔(秒)
            'mail_code_ttl'      => '10',   // 验证码有效期(分钟)
            'mail_mail_hourly'   => '5',    // 单邮箱每小时发送上限，0=不限制
            'mail_ip_hourly'     => '15',   // 单 IP 每小时发送上限，0=不限制
            'mail_browser_hourly'=> '15',   // 单浏览器每小时发送上限，0=不限制
            'mail_max_attempts'  => '5',    // 同一验证码最多输错次数，0=不限制
            'mail_email_min_len' => '7',    // 邮箱最小长度
            'mail_email_max_len' => '150',  // 邮箱最大长度
            'mail_allowed_domains' => '',   // 允许邮箱后缀，逗号分隔，留空=不限制
            'mail_unique_check'  => '1',    // 邮箱唯一检查
            'mail_allow_change'  => '1',    // 允许用户修改邮箱
            'mail_code_verify'   => '1',    // 邮箱验证码总开关（关闭后找回密码一并停用）
            'sound_default'      => '1',
            'room_pass_ttl'    => '1800', // 密码房通行缓存(秒)，0=每次进入都要输入密码
            // 登录保护
            'login_fail_captcha' => '3',  // 连续失败达此次数后要求图形验证码（0=不启用）
            'login_fail_lock'    => '10', // 连续失败达此次数后临时锁定（0=不锁定）
            'login_lock_minutes' => '15', // 锁定时长（分钟）
            'min_register_age' => '0',   // 注册最低年龄（周岁），0=不限制
            // v1.2.41：file_upload / file_exts / file_max_size 三项**已移入附件上传插件**
            //   （表 plugin_attachment_config）。这里不再声明默认值 ——
            //   留着的后果是：插件迁移逻辑会读到核心默认值，把用户已改过的配置覆盖回去。
            // 用户创建群聊
            'room_create_allow' => '1',  // 是否允许普通用户创建群聊（管理员始终可创建）
            // v1.2.43：原 room_create_cost（创建群聊扣除积分）**已下线** ——
            //   建群不再消耗积分。不给默认值：留着会让「后台看上去还能配」的错觉，
            //   而它其实已经不参与任何校验了。
            // v1.1.14：是否允许普通用户创建**不公开**群聊（管理员始终可创建）。
            // 与 room_create_allow 正交：那个管「能不能建群」，这个管「建出来的群能不能藏起来」。
            // 不公开群只靠邀请链接传播，容易变成灰色宣传阵地，故单独留一道总闸。
            'room_private_create_allow' => '1',
        ];
        foreach ($defs as $k => $v) {
            if (self::setting($k) === null) self::setSetting($k, $v);
        }
        if (!self::val('SELECT COUNT(*) FROM rooms')) {
            // 默认房间固定占用 001（v1.0.51 起新房间 ID 为随机 3 位起步），
            // 群主固定指向超级管理员（管理员固定占用用户 001，安装流程在其后创建）
            DB::insert('rooms', [
                'id' => 1,
                'name' => '综合闲聊', 'slug' => 'public', 'type' => 'public',
                'min_role' => 'guest', 'description' => '默认公共群聊',
                'owner_id' => 1,
                'status' => 1, 'created_at' => time(),
            ]);
        }
        // 存量库修正（v1.0.80）：老版本安装的默认群聊没有群主，补指向超级管理员
        DB::run('UPDATE rooms SET owner_id=1 WHERE id=1 AND (owner_id IS NULL OR owner_id=0) AND EXISTS(SELECT 1 FROM users WHERE id=1)');
    }
}
