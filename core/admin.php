<?php
/**
 * 管理后台：用户 / 禁言 / 敏感词 / 群聊 / 公告 / 安全日志 / 站点设置
 */
class Admin
{
    /** 秒 → 人类可读时长（计划任务间隔展示用） */
    public static function intervalText(int $sec): string
    {
        $sec = max(60, $sec);
        if ($sec % 86400 === 0) return ($sec / 86400) . ' 天';
        if ($sec % 3600 === 0)  return ($sec / 3600) . ' 小时';
        if ($sec % 60 === 0)    return ($sec / 60) . ' 分钟';
        return $sec . ' 秒';
    }

    /** 全局配置（index.php 启动时注入，用于定位 data/logs） */
    private static array $cfg = [];

    /** 注入配置：与 Upload::init / Plugin::init 同一模式（$CFG 由 index.php 持有） */
    public static function init(array $cfg): void
    {
        self::$cfg = $cfg;
    }

    public static function requireAdmin(array $actor): void
    {
        if ($actor['role'] !== 'admin') {
            Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
        }
    }

    /** 日志文件名白名单：只认这两种形态，其余一律拒绝（防 ../ 穿越与任意文件读取） */
    private const SYSLOG_NAME = '/^debug(\.log|-\d{4}-\d{2}-\d{2}\.log)$/';

    /** 系统日志目录（data/logs） */
    private static function syslogDir(): string
    {
        $base = (string)(self::$cfg['data_dir'] ?? (dirname(__DIR__) . '/data'));
        return rtrim($base, '/\\') . '/logs';
    }

    /**
     * 列出日志文件，新的在前。
     * @return array [['name'=>'debug.log','size'=>123,'mtime'=>1700000000,'kind'=>'debug'], ...]
     */
    private static function syslogFiles(): array
    {
        $dir = self::syslogDir();
        $out = [];
        if (!is_dir($dir)) return $out;
        foreach ((array)@scandir($dir) as $f) {
            $f = (string)$f;
            if (!preg_match(self::SYSLOG_NAME, $f)) continue;
            $fp = $dir . '/' . $f;
            if (!is_file($fp)) continue;
            $out[] = [
                'name'  => $f,
                'size'  => (int)@filesize($fp),
                'mtime' => (int)@filemtime($fp),
                'kind'  => $f === 'debug.log' ? 'debug' : 'daily',
            ];
        }
        usort($out, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $out;
    }

    /**
     * 校验文件名并返回绝对路径；非法返回 null。
     * 前端传来的 file 必须过这里，绝不直接拼路径。
     */
    private static function syslogPath(string $file): ?string
    {
        if (!preg_match(self::SYSLOG_NAME, $file)) return null;
        $dir = self::syslogDir();
        $p = $dir . '/' . $file;
        if (!is_file($p)) return null;
        // realpath 归一后再比较：Windows 下 realpath 返回反斜杠、配置里可能是正斜杠，
        // 不统一分隔符会把合法路径误判成越界
        $real = @realpath($p);
        $base = @realpath($dir);
        if ($real !== false && $base !== false) {
            $norm = static fn(string $s): string => rtrim(str_replace('\\', '/', $s), '/');
            if (strpos($norm($real), $norm($base) . '/') !== 0) return null;
        }
        return $real !== false ? $real : $p;
    }

    /**
     * 读取日志尾部（默认末尾 200KB，够看又不炸页面）。
     * 按字节截断可能落在多字节字符中间，只在切点处向前找到合法 UTF-8 起始字节再截，
     * 最多丢 3 字节——不能对全文做 [\x80-\xFF] 替换，那样会把正常中文全变成乱码。
     */
    private static function syslogTail(string $path, int $bytes = 204800): string
    {
        clearstatcache(true, $path);
        $size = (int)@filesize($path);
        $fh = @fopen($path, 'rb');
        if (!$fh) return '';
        $cut = false;
        if ($size > $bytes) {
            fseek($fh, -$bytes, SEEK_END);
            $out = "...（已省略前 " . round(($size - $bytes) / 1024) . " KB）\n";
            $cut = true;
        } else {
            $out = '';
        }
        $body = (string)stream_get_contents($fh);
        fclose($fh);
        if ($cut) {
            for ($k = 0; $k < 4 && $k < strlen($body); $k++) {
                if ((ord($body[$k]) & 0xC0) === 0x80) {   // 续字节 → 前一个字符被切了一半
                    $body = substr($body, $k);
                } else {
                    break;
                }
            }
            if ($body !== '' && (ord($body[0]) & 0xC0) === 0x80) $body = '';
        }
        return $out . $body;
    }

    public static function handle(string $action, array $actor): void
    {
        /**
         * 单条群聊审核处置（v1.0.78，v1.0.83 提取为闭包供单条/批量共用）。
         * 处置写入回收站（room_review_trash），可撤销；delete 走独立逻辑（见 admin_room_del）。
         * @return array [bool, string]
         */
        $roomReview = function (array $actor, int $id, string $act): array {
            $room = DB::one('SELECT * FROM rooms WHERE id=?', [$id]);
            if (!$room) return [false, '群聊不存在'];
            $trash = function (string $action, array $before) use ($id, $room): void {
                DB::insert('room_review_trash', [
                    'room_id' => $id, 'room_name' => $room['name'],
                    'action' => $action, 'before_data' => json_encode($before, JSON_UNESCAPED_UNICODE),
                    'undone' => 0, 'created_at' => time(),
                ]);
            };
            if ($act === 'reset_name') {
                $trash('reset_name', ['name' => $room['name']]);
                DB::run("UPDATE rooms SET name=? WHERE id=?", ['未命名群聊', $id]);
                Sec::log('room_review', (string)$id, ['act' => 'reset_name']);
                Plugin::fire('room.after_update', [$id, ['name' => '未命名群聊'], $actor]);
                return [true, '已重置为「未命名群聊」（可在回收站撤销）'];
            }
            if ($act === 'reset_avatar') {
                $old = (string)$room['avatar'];
                $trash('reset_avatar', ['avatar' => $old]);
                // v1.3.13：连带清 avatar_type/style/seed —— 只清路径的话，
                // 生成式头像仍生效，「重置头像」等于没重置。
                DB::run("UPDATE rooms SET avatar='', avatar_type='', avatar_style='', avatar_seed='' WHERE id=?", [$id]);
                // 违规头像文件移入回收目录（不删除，撤销时移回）
                if ($old !== '' && strpos($old, 'uploads/avatar/') === 0) {
                    $src = dirname(__DIR__) . '/' . $old;
                    if (is_file($src)) {
                        $trashDir = dirname(__DIR__) . '/data/avatar_trash';
                        if (!is_dir($trashDir)) @mkdir($trashDir, 0775, true);
                        @rename($src, $trashDir . '/' . basename($old));
                    }
                }
                Sec::log('room_review', (string)$id, ['act' => 'reset_avatar']);
                Plugin::fire('room.after_update', [$id, ['avatar' => ''], $actor]);
                return [true, '头像已恢复默认（可在回收站撤销）'];
            }
            if ($act === 'toggle_status') {
                $to = (int)$room['status'] === 1 ? 0 : 1;
                $trash('toggle_status', ['status' => (int)$room['status']]);
                DB::run('UPDATE rooms SET status=? WHERE id=?', [$to, $id]);
                Sec::log('room_review', (string)$id, ['act' => $to ? 'unban' : 'ban']);
                // v1.3.159：封禁 / 解封也要通知插件 —— redis 插件的「公开房间列表」缓存的正是
                // status=1 那批行，不通知的话它最坏要等 TTL 过期才不再列出已封的群。
                Plugin::fire('room.after_update', [$id, ['status' => $to], $actor]);
                return [true, ($to ? '已解封' : '已封禁') . '（可在回收站撤销）'];
            }
            return [false, '未知审核动作'];
        };

        /**
         * 撤销一条回收站记录（v1.4.5：从 case 'admin_room_trash_undo' 里原样抽出，
         * 单条与批量共用 —— 恢复逻辑抄两份，改一份忘一份是迟早的事）。
         * 顺序约束：恢复名称 / 头像 / 状态前，群聊必须仍存在
         *（若已被删除，必须先撤销对应的「删除」记录）。
         * @return array [bool, string]
         */
        $trashUndo = function (array $actor, int $tid): array {
            $t = DB::one('SELECT * FROM room_review_trash WHERE id=?', [$tid]);
            if (!$t) return [false, '回收站记录不存在'];
            if ((int)$t['undone'] === 1) return [false, '该记录已撤销过'];
            $before = json_decode((string)$t['before_data'], true) ?: [];
            $roomId = (int)$t['room_id'];
            $room = DB::one('SELECT id FROM rooms WHERE id=?', [$roomId]);
            if ($t['action'] !== 'delete' && !$room) {
                return [false, '该群聊已被删除，请先在回收站撤销对应的「删除」操作'];
            }
            if ($t['action'] === 'reset_name') {
                DB::run('UPDATE rooms SET name=? WHERE id=?', [(string)$before['name'], $roomId]);
            } elseif ($t['action'] === 'reset_avatar') {
                $old = (string)$before['avatar'];
                // 头像文件从回收目录移回
                $trashFile = dirname(__DIR__) . '/data/avatar_trash/' . basename($old);
                $dest = dirname(__DIR__) . '/' . $old;
                if ($old !== '' && is_file($trashFile)) {
                    $destDir = dirname($dest);
                    if (!is_dir($destDir)) @mkdir($destDir, 0775, true);
                    @rename($trashFile, $dest);
                }
                DB::run('UPDATE rooms SET avatar=? WHERE id=?', [$old, $roomId]);
            } elseif ($t['action'] === 'toggle_status') {
                DB::run('UPDATE rooms SET status=? WHERE id=?', [(int)$before['status'], $roomId]);
            } elseif ($t['action'] === 'delete') {
                if ($room) return [false, '该群聊已存在，无需恢复'];
                if ($roomId === 1) return [false, '默认群聊不可恢复为删除前状态'];
                $row = $before['row'] ?? null;
                if (!is_array($row)) return [false, '快照数据缺失，无法恢复'];
                $cols = array_keys($row);
                DB::run('INSERT INTO rooms (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')',
                    array_values($row));
                Plugin::fire('room.restored', [$roomId, $row, $actor]);
            }
            DB::run('UPDATE room_review_trash SET undone=1 WHERE id=?', [$tid]);
            Sec::log('room_undo', (string)$roomId, ['act' => $t['action'], 'trash' => $tid]);
            return [true, '已撤销'];
        };

        self::requireAdmin($actor);
        $p = fn($k, $d = '') => trim((string)($_POST[$k] ?? $d));

        switch ($action) {
            // ---------- 仪表盘（v1.3.158） ----------
            /* 后台首页要的计数一次算完。计数一律现算、不做缓存：这个页面是「点开才看一眼」的，
               不在每请求路径上，多一次 COUNT 无所谓。三个趋势各自只扫小表（users / rooms /
               friends），messages 的两条计数分别走 idx_msg_room 与 idx_msg_private 索引。
               ⚠️ 不给 messages 做「按天」统计：messages.created_at **没有索引**，按时间区间数会
                  全表扫；用户 / 群聊 / 好友三张表小得多，7 天趋势就用它们仨。 */
            case 'admin_stats':
                $now = time();
                $todayStart = strtotime('today');
                /* 近 7 天（含今天）分桶：**用 PHP 算出每天的起止时刻，再让 SQL 分桶** ——
                   不写 DATE()/strftime() 这类方言函数（SQLite 与 MySQL 的日期函数完全不同），
                   也不按「时间戳整除 86400」切（那是 UTC 天，与站点时区差 8 小时，
                   CST 早上 8 点前的数据会被算到前一天）。 */
                $buckets = [];
                $sums = [];
                $targs = [];
                for ($i = 6; $i >= 0; $i--) {
                    $s = $todayStart - $i * 86400;
                    $buckets[] = ['label' => date('m-d', $s), 'today' => $i === 0];
                    $sums[] = 'SUM(CASE WHEN created_at>=? AND created_at<? THEN 1 ELSE 0 END)';
                    $targs[] = $s;
                    $targs[] = $s + 86400;
                }
                $trendOf = function (string $table) use ($sums, $targs): array {
                    $row = DB::one('SELECT ' . implode(',', $sums) . ' FROM ' . $table, $targs);
                    return array_map(fn($v) => (int)$v, array_values((array)$row));
                };
                $uTrend = $trendOf('users');
                $rTrend = $trendOf('rooms');
                $fTrend = $trendOf('friends');
                $days = [];
                foreach ($buckets as $i => $b) {
                    $days[] = $b + ['users' => $uTrend[$i] ?? 0, 'rooms' => $rTrend[$i] ?? 0, 'friends' => $fTrend[$i] ?? 0];
                }
                Api::json(['ok' => true, 'data' => [
                    'users'       => (int)DB::val('SELECT COUNT(*) FROM users'),
                    'rooms'       => (int)DB::val('SELECT COUNT(*) FROM rooms'),
                    'msg_group'   => (int)DB::val('SELECT COUNT(*) FROM messages WHERE room_id>0'),
                    'msg_dm'      => (int)DB::val('SELECT COUNT(*) FROM messages WHERE room_id=0 AND to_user_id>0'),
                    'friends'     => (int)DB::val('SELECT COUNT(*) FROM friends'),
                    // 在线口径与 Chat::onlineList 同源（45 秒内有过心跳）
                    'online'      => (int)DB::val('SELECT COUNT(*) FROM online WHERE last_seen>?', [$now - 45]),
                    'users_today' => $uTrend[6] ?? 0,
                    'rooms_today' => $rTrend[6] ?? 0,
                    'days'        => $days,
                    'env'         => [
                        'version' => OWLSGO_VERSION, 'php' => PHP_VERSION,
                        'driver'  => DB::driver(), 'now' => date('Y-m-d H:i'),
                    ],
                ]]);

            // ---------- 用户管理 ----------
            // 用户管理自 v1.0.44 起剥离为插件 user-manager（plugins/user-manager/），
            // 原搜索/角色/积分/禁用接口随迁：plugin_user_manager_search/save/status

            // ---------- 禁言管理 ----------
            // 禁言管理自 v1.0.52 起剥离为插件 ban-manager（plugins/ban-manager/），
            // 原列表/添加/解除接口随迁：plugin_ban_manager_list/add/del。
            // 运行时拦截（Chat::isBanned）与 bans 表保留在核心。

            // ---------- 敏感词 ----------
            case 'admin_words':
                Api::json(['ok' => true, 'data' => DB::all('SELECT * FROM sensitive_words ORDER BY id DESC LIMIT 500')]);

            case 'admin_word_add':
                if ($p('word') === '') Api::json(['ok' => false, 'msg' => '敏感词不能为空']);
                DB::insert('sensitive_words', ['word' => $p('word'), 'replacement' => $p('replacement', '***'), 'enabled' => 1]);
                Api::json(['ok' => true, 'msg' => '已添加']);

            case 'admin_word_toggle':
                DB::run('UPDATE sensitive_words SET enabled=? WHERE id=?', [(int)$p('enabled'), (int)$p('id')]);
                Api::json(['ok' => true, 'msg' => '已更新']);

            case 'admin_word_del':
                DB::run('DELETE FROM sensitive_words WHERE id=?', [(int)$p('id')]);
                Api::json(['ok' => true, 'msg' => '已删除']);

            // ---------- 群聊管理 ----------
            case 'admin_rooms':
                // 审核列表：服务端分页 + 房主/群聊ID搜索；不返回密码字段
                $pageR = max(1, (int)$p('page', '1'));
                $sizeR = min(100, max(1, (int)$p('size', '20')));
                $where = [];
                $args = [];
                $ownerR = (int)$p('owner', '0');
                if ($ownerR > 0) { $where[] = 'owner_id=?'; $args[] = $ownerR; }
                $ridR = (int)$p('rid', '0');
                if ($ridR > 0) { $where[] = 'id=?'; $args[] = $ridR; }
                $wR = $where ? ' WHERE ' . implode(' AND ', $where) : '';
                $totalR = (int)DB::val('SELECT COUNT(*) FROM rooms' . $wR, $args);
                // v1.3.13：SELECT * —— 群头像要交给 Chat::roomAvatarUrl 算，
                // 它需要 avatar_type/avatar_style/avatar_seed 三列（自定义 → 创建者 → 群 id 派生）。
                $listR = DB::all('SELECT * FROM rooms'
                    . $wR . ' ORDER BY id LIMIT ' . $sizeR . ' OFFSET ' . (($pageR - 1) * $sizeR), $args);
                // 与前台同口径：后台审核里看到的头像 = 前台显示的头像（原先直接给路径，
                // 未自定义时是空串 → 后台列表一片空白，与前台的「创建者头像」对不上）
                foreach ($listR as &$rr) { $rr['avatar'] = Chat::roomAvatarUrl($rr); }
                unset($rr);
                Api::json(['ok' => true, 'data' => ['list' => $listR, 'total' => $totalR, 'page' => $pageR, 'size' => $sizeR]]);

            case 'admin_room_review':
                // 群聊审核（v1.0.78）：超级管理员仅能做合规处置，不代改群聊内容。
                // 处置写入回收站（room_review_trash），可撤销。
                [$rok, $rmsg] = $roomReview($actor, (int)$p('id', '0'), (string)$p('act', ''));
                Api::json(['ok' => $rok, 'msg' => $rmsg]);

            case 'admin_room_batch':
                // 批量审核（v1.0.83）：ids 逗号分隔，act 同单条（reset_name /
                // reset_avatar / toggle_status / delete）。逐条走同一逻辑（均入回收站），
                // 返回成功/失败计数；失败不影响其余条目。
                $actB = (string)$p('act', '');
                $idsB = array_filter(array_map('intval', explode(',', (string)$p('ids', ''))));
                if (!$idsB) Api::json(['ok' => false, 'msg' => '未选择群聊']);
                $okB = 0; $failB = 0; $lastMsg = '';
                foreach ($idsB as $idB) {
                    if ($actB === 'delete') {
                        // 批量删除：与 admin_room_del 同款（整行快照入回收站 + 默认房间保护）
                        $del = DB::one('SELECT * FROM rooms WHERE id=? AND slug!=?', [$idB, 'public']);
                        if (!$del) { $failB++; $lastMsg = '群聊不存在（默认房间不可删除）'; continue; }
                        DB::insert('room_review_trash', [
                            'room_id' => $idB, 'room_name' => $del['name'],
                            'action' => 'delete', 'before_data' => json_encode(['row' => $del], JSON_UNESCAPED_UNICODE),
                            'undone' => 0, 'created_at' => time(),
                        ]);
                        DB::run('DELETE FROM rooms WHERE id=? AND slug!=?', [$idB, 'public']);
                        Sec::log('room_review', (string)$idB, ['act' => 'delete']);
                        // v1.3.159：删群同样要通知插件（房间列表缓存靠它精确失效，
                        // 否则最坏等 TTL 过期才不再列出这个已删的群）
                        Plugin::fire('room.after_update', [$idB, ['deleted' => 1], $actor]);
                        $okB++;
                        continue;
                    }
                    [$rok, $rmsg] = $roomReview($actor, $idB, $actB);
                    if ($rok) { $okB++; } else { $failB++; $lastMsg = $rmsg; }
                }
                Api::json(['ok' => $okB > 0,
                    'msg' => '批量' . ($actB === 'delete' ? '删除' : '处置') . '：成功 ' . $okB . ' 条'
                        . ($failB ? '，失败 ' . $failB . ' 条（' . $lastMsg . '）' : '')]);

            case 'admin_room_trash_list':
                // 回收站：服务端分页 + 搜索（v1.4.5）。三个筛选条件都可空=不限：
                //   kw     —— 群名模糊；纯数字时同时按群聊 ID 命中（「搜 12」既像群名也像 ID）
                //   act    —— 处置类型白名单，非法值一律当「全部」（不给拼 SQL 留口子）
                //   undone —— '0' 未撤销 / '1' 已撤销 / 其它 = 全部
                // ⚠️ 条件值全走占位符；只有分页两个数字是 CAST 后拼进 LIMIT（? 占位的 LIMIT 不被 MySQL 接受）。
                $pageT = max(1, (int)$p('page', '1'));
                $sizeT = min(100, max(1, (int)$p('size', '20')));
                $wT = []; $argsT = [];
                $kwT = trim((string)$p('kw', ''));
                if ($kwT !== '') {
                    if (preg_match('/^\d{1,10}$/', $kwT)) {
                        $wT[] = '(room_id=? OR room_name LIKE ?)';
                        $argsT[] = (int)$kwT; $argsT[] = '%' . $kwT . '%';
                    } else {
                        // ⚠️ 不做 % / _ 转义：MySQL 认反斜杠、SQLite 不认（要 ESCAPE 子句），
                        // 两边写法不通用；这里是搜索框，通配符顶多让结果宽一点，不涉及越权。
                        $wT[] = 'room_name LIKE ?';
                        $argsT[] = '%' . $kwT . '%';
                    }
                }
                $actT = (string)$p('act', '');
                if (in_array($actT, ['reset_name', 'reset_avatar', 'toggle_status', 'delete'], true)) {
                    $wT[] = 'action=?'; $argsT[] = $actT;
                }
                $undT = (string)$p('undone', '');
                if ($undT === '0' || $undT === '1') { $wT[] = 'undone=?'; $argsT[] = (int)$undT; }
                $whereT = $wT ? ' WHERE ' . implode(' AND ', $wT) : '';
                $totalT = (int)DB::val('SELECT COUNT(*) FROM room_review_trash' . $whereT, $argsT);
                $rowsT = DB::all('SELECT * FROM room_review_trash' . $whereT . ' ORDER BY id DESC LIMIT '
                    . $sizeT . ' OFFSET ' . (($pageT - 1) * $sizeT), $argsT);
                foreach ($rowsT as &$r) {
                    $r['before_data'] = json_decode((string)$r['before_data'], true) ?: new stdClass();
                }
                unset($r);
                Api::json(['ok' => true, 'data' => ['list' => $rowsT, 'total' => $totalT, 'page' => $pageT, 'size' => $sizeT]]);

            case 'admin_room_trash_undo':
                // 单条撤销：逻辑在 $trashUndo（v1.4.5 抽出，与批量共用）
                [$okU, $msgU] = $trashUndo($actor, (int)$p('id', '0'));
                Api::json(['ok' => $okU, 'msg' => $msgU]);

            case 'admin_room_trash_batch':
                // 批量撤销（v1.4.5）：目前只有 undo 这一个动作，接口形状与 admin_room_batch 一致。
                // ⚠️ 处理顺序固定「删除优先、各自按 id 升序」：单条撤销就有依赖 —— 群聊被删后，
                // 必须先撤销「删除」把群恢复，同一群的名称 / 头像 / 封禁撤销才不会被「群聊不存在」挡掉。
                // 若按勾选顺序处理，先点到的那条会把后面本该成功的挡在门外（用户看到的是「一半莫名失败」）。
                $actBt = (string)$p('act', '');
                if ($actBt !== 'undo') Api::json(['ok' => false, 'msg' => '不支持的批量动作']);
                $idsBt = [];
                foreach (explode(',', (string)$p('ids', '')) as $iBt) {
                    $iBt = (int)trim($iBt);
                    if ($iBt > 0) $idsBt[] = $iBt;
                }
                $idsBt = array_values(array_unique($idsBt));
                if (!$idsBt) Api::json(['ok' => false, 'msg' => '未选择回收站记录']);
                if (count($idsBt) > 100) Api::json(['ok' => false, 'msg' => '单次最多批量撤销 100 条']);
                $phBt = implode(',', array_fill(0, count($idsBt), '?'));
                $preBt = DB::all('SELECT id, action FROM room_review_trash WHERE id IN (' . $phBt . ')', $idsBt);
                $delBt = []; $othBt = [];
                foreach ($preBt as $prBt) {
                    if ($prBt['action'] === 'delete') $delBt[] = (int)$prBt['id'];
                    else $othBt[] = (int)$prBt['id'];
                }
                sort($delBt); sort($othBt);
                $okBt = 0; $failBt = 0; $lastBt = '';
                foreach (array_merge($delBt, $othBt) as $tidBt) {
                    [$ok2, $msg2] = $trashUndo($actor, $tidBt);
                    if ($ok2) { $okBt++; } else { $failBt++; $lastBt = $msg2; }
                }
                // 勾选里已经不存在的记录（被人先撤销/清掉了）也算失败，别让人以为全成功
                if (count($idsBt) > count($preBt)) {
                    $failBt += count($idsBt) - count($preBt);
                    $lastBt = '部分记录已不在回收站';
                }
                Api::json(['ok' => $okBt > 0,
                    'msg' => '批量撤销：成功 ' . $okBt . ' 条'
                        . ($failBt ? '，失败 ' . $failBt . ' 条（' . $lastBt . '）' : '')]);

            case 'admin_room_del':
                $id = (int)$p('id');
                $del = DB::one('SELECT * FROM rooms WHERE id=? AND slug!=?', [$id, 'public']);
                if (!$del) Api::json(['ok' => false, 'msg' => '群聊不存在（默认房间不可删除）']);
                // 整行快照入回收站，可撤销恢复
                DB::insert('room_review_trash', [
                    'room_id' => $id, 'room_name' => $del['name'],
                    'action' => 'delete', 'before_data' => json_encode(['row' => $del], JSON_UNESCAPED_UNICODE),
                    'undone' => 0, 'created_at' => time(),
                ]);
                DB::run('DELETE FROM rooms WHERE id=? AND slug!=?', [$id, 'public']);
                Sec::log('room_review', (string)$id, ['act' => 'delete']);
                // v1.3.159：与批量删除同口径 —— 通知插件精确失效房间列表缓存
                Plugin::fire('room.after_update', [$id, ['deleted' => 1], $actor]);
                Api::json(['ok' => true, 'msg' => '已删除（可在回收站撤销）']);

            // ---------- 公告 ----------
            // ---------- 系统公告（v1.0.102）已剥离为 announcements 插件（群公告体系） ----------

            // ---------- 安全日志 ----------
            // v1.2.51：改服务端分页 + 动作筛选（原来一次 LIMIT 200 全量吐给前端，
            // 日志一多页面又长又卡，且没有筛选能力）。前端用通用分页轮子 uiPager 渲染。
            case 'admin_logs':
                $page  = max(1, (int)$p('page'));
                $psize = max(5, min(100, (int)($p('psize') ?: 30)));
                $act   = trim((string)$p('action'));          // 按动作筛选，空 = 全部
                $where = ''; $args = [];
                if ($act !== '') { $where = 'WHERE action=?'; $args = [$act]; }
                $total = (int)DB::val("SELECT COUNT(*) FROM security_logs $where", $args);
                Api::json([
                    'ok'      => true,
                    'data'    => DB::all("SELECT * FROM security_logs $where ORDER BY id DESC LIMIT $psize OFFSET " . (($page - 1) * $psize), $args),
                    'total'   => $total,
                    'page'    => $page,
                    'psize'   => $psize,
                    'pages'   => max(1, (int)ceil($total / $psize)),
                    // 动作清单 + 计数，供筛选下拉展示「登录成功（12）」这类带量的选项
                    'actions' => DB::all('SELECT action, COUNT(*) AS n FROM security_logs GROUP BY action ORDER BY n DESC, action ASC'),
                ]);

            // ---------- 系统日志（文件日志查看，v1.2.60） ----------
            // 调试模式开启时 PHP 把报错写进 data/logs/debug.log（见 index.php 的 OWLSGO_DEBUG 块）。
            // 这里只做「查看 / 清空」，不改写入机制：日志存不存在、写了什么，仍由调试模式开关决定。
            case 'admin_syslog':
                Api::json([
                    'ok'   => true,
                    'files' => self::syslogFiles(),
                    // 前端据此决定是否显示「调试模式已关闭」提示条
                    'debug' => DB::setting('debug_mode', '0') === '1',
                ]);

            case 'admin_syslog_read':
                $slFile = (string)$p('file', '');
                $slPath = self::syslogPath($slFile);
                // 非法文件名（穿越 / 不在白名单 / 不存在）一律按「文件不存在」处理，
                // 不把真实路径或存在性回传给前端
                if ($slPath === null) Api::json(['ok' => false, 'msg' => '日志文件不存在'], 404);
                Api::json(['ok' => true, 'file' => $slFile, 'content' => self::syslogTail($slPath)]);

            case 'admin_syslog_clear':
                $scFile = (string)$p('file', '');
                $scPath = self::syslogPath($scFile);
                if ($scPath === null) Api::json(['ok' => false, 'msg' => '日志文件不存在'], 404);
                if (@file_put_contents($scPath, '') === false) {
                    Api::json(['ok' => false, 'msg' => '清空失败：文件不可写'], 500);
                }
                Api::json(['ok' => true, 'msg' => '已清空 ' . $scFile]);

            // ---------- 安全日志：批量删除（v1.2.60） ----------
            case 'admin_logs_batch':
                $idsLB = array_values(array_unique(array_filter(
                    array_map('intval', explode(',', (string)$p('ids', '')))
                )));
                if (!$idsLB) Api::json(['ok' => false, 'msg' => '未选择日志']);
                $okLB = 0;
                foreach ($idsLB as $idLB) {
                    // DB::run 返回 PDOStatement，行数要取 rowCount()（与对象比大小无意义）
                    if (DB::run('DELETE FROM security_logs WHERE id=?', [$idLB])->rowCount() > 0) $okLB++;
                }
                // 删除动作本身留痕：谁在什么时候清了哪些日志
                Sec::log('admin_logs_batch_del', $actor['nickname'], ['ids' => $idsLB, 'ok' => $okLB]);
                Api::json(['ok' => $okLB > 0, 'msg' => '批量删除：成功 ' . $okLB . ' 条']);

            // ---------- 运行缓存（v1.2.60） ----------
            case 'admin_opcache_reset':
                // 手动刷新已编译脚本缓存：代码更新后 php-cgi 的 OPcache 可能仍在跑旧字节码
                // （validate_timestamps 关闭或 revalidate_freq 未到），清一次最直接。
                if (!function_exists('opcache_reset')) {
                    Api::json(['ok' => false, 'msg' => '当前环境未启用 OPcache（phpStudy 需在面板 PHP 设置中勾选 OPcache）']);
                }
                if (!@opcache_reset()) {
                    Api::json(['ok' => false, 'msg' => 'OPcache 清理失败（缓存可能处于只读或不可写状态）'], 500);
                }
                Api::json(['ok' => true, 'msg' => '已刷新编译脚本缓存']);

            // ---------- 在线升级（v1.3.39，实现见 core/upgrade.php） ----------
            case 'admin_upgrade_check':
                $r = Upgrade::check($errUpd);
                Upgrade::saveInfo($r, $errUpd);   // 同步红点数据（手动检查也要立刻反映到入口）
                Api::json($r === null ? ['ok' => false, 'msg' => $errUpd] : ['ok' => true] + $r);

            case 'admin_upgrade_apply':
                // 敏感路由：POST + 一次性票据（index.php $SENSITIVE）。
                // 两个授权位都只认字符串 '1'：降级 = 拿更旧的代码覆盖，
                // 覆盖本地差异 = 拿仓库版覆盖我还没推的新代码（v1.3.102 新增）。
                [$okUA, $resUA] = Upgrade::apply($p('allow_downgrade') === '1', $p('allow_overwrite_local') === '1');
                if ($okUA) {
                    Sec::log('upgrade_apply', (string)($actor['nickname'] ?? ''), is_array($resUA) ? $resUA : ['msg' => (string)$resUA]);
                    Upgrade::saveInfo(Upgrade::check($eUa));   // 升级后重比一次，红点即时熄灭
                }
                Api::json($okUA ? ['ok' => true] + (array)$resUA : ['ok' => false, 'msg' => (string)$resUA]);

            case 'admin_upgrade_rollback':
                // 敏感路由同上；默认恢复最近一次升级前的状态，可指定备份编号。
                // ⚠️ 参数不能叫 ts —— 会和 OwApi 签名字段 ts（时间戳）撞名，
                //    $p('ts') 拿到的是签名时间戳、直接被判「备份编号不合法」。
                [$okRB, $resRB] = Upgrade::rollback($p('bak_ts') !== '' ? $p('bak_ts') : null);
                if ($okRB) {
                    Sec::log('upgrade_rollback', (string)($actor['nickname'] ?? ''), is_array($resRB) ? $resRB : ['msg' => (string)$resRB]);
                    Upgrade::saveInfo(Upgrade::check($eUr));   // 回滚后本地又落后了，红点应重新亮起
                }
                Api::json($okRB ? ['ok' => true] + (array)$resRB : ['ok' => false, 'msg' => (string)$resRB]);

            case 'admin_upgrade_backups':
                Api::json(['ok' => true, 'data' => Upgrade::backups()]);

            case 'admin_upgrade_token':
                /* v1.3.138：GitHub token（可选）。配了之后 API 限额 60 → 5000 次/小时，
                   单次升级的文件上限随之放宽；没配也不会再卡死 —— 整包下载那条路只花 2 次调用。
                   敏感路由（index.php $SENSITIVE）：这是凭据，写入必须 POST + 一次性票据。
                   ⚠️ 只回显掩码，完整值不出库、不进日志。 */
                $tk = $p('token');
                if ($tk === '') {
                    DB::setSetting('github_token', '');
                    Sec::log('upgrade_token_clear', (string)($actor['nickname'] ?? ''), []);
                    Api::json(['ok' => true, 'token_masked' => '', 'msg' => '已清除 token']);
                }
                if (strlen($tk) < 20 || strlen($tk) > 255 || preg_match('/\s/', $tk)) {
                    Api::json(['ok' => false, 'msg' => 'token 格式不像 GitHub 的（20~255 个非空白字符）']);
                }
                DB::setSetting('github_token', $tk);
                Sec::log('upgrade_token_set', (string)($actor['nickname'] ?? ''), ['mask' => Upgrade::maskToken($tk)]);
                Api::json(['ok' => true, 'token_masked' => Upgrade::maskToken($tk), 'msg' => 'token 已保存']);

            // ---------- 站点设置 ----------
            case 'admin_settings_get':
                $rows = DB::all('SELECT k, v FROM settings');
                Api::json(['ok' => true, 'data' => array_column($rows, 'v', 'k')]);

            case 'admin_settings_save':
                // v1.2.43：原「创建群聊扣除积分」的校验随该设置一并下线（建群不再消耗积分）
                // 固定网站地址：允许留空（自动识别）；填写时必须是 http(s) 开头的合法地址
                if (isset($_POST['site_url']) && trim((string)$_POST['site_url']) !== '') {
                    $u = trim((string)$_POST['site_url']);
                    if (!preg_match('{^https?://[a-zA-Z0-9._~:/?#\[\]@!$&()*+,;=%-]+$}', $u)) {
                        Api::json(['ok' => false, 'msg' => '固定网站地址需以 http:// 或 https:// 开头']);
                    }
                }
                $allow = ['site_name', 'site_url', 'allow_register', 'reg_email_verify', 'guest_browse', 'guest_chat',
                          // v1.5.4 积分名称（全站替代表述：签到 / 建群扣费 / 用户管理）。
                          // 校验与清洗在下面单独一段 —— 它是**名词**，不是普通字符串，
                          // 会出现在前端拼接的 HTML 里，必须限制长度与字符集。
                          'points_name',
                          // v1.1.0：guest_daily_limit 已下线，改为发言间隔（秒）
                          'guest_msg_interval',
                          // v1.2.2 消息服务器保留期（天），0 = 永久保留。
                          // v1.2.3 起这是唯一的消息保留期项（已删除消息同样适用），
                          // 原 msg_deleted_retain_days 已下线、不再读写。
                          'msg_retain_days',
                          // v1.3.167 执行日志保留期（天），0 = 永久保留；purge_cron_logs 用它。
                          'cron_log_retain_days',
                          'msg_rate_window', 'msg_rate_max',
                          // v1.3.55：mail_rate_limit 不再由本页写入 —— 它和其余 mail_* 策略键
                          // 一起归到「邮箱验证」插件的后台页（那边按白名单直写 settings）。
                          // 留在两个页面里改同一个键，迟早出现「这边改了那边被旧表单覆盖」。
                          'sound_default', 'room_pass_ttl',
                          'login_fail_captcha', 'login_fail_lock', 'login_lock_minutes', 'min_register_age',
                          'room_create_allow',
                          // v1.1.14：普通用户能否创建不公开群聊（管理员始终可）
                          'room_private_create_allow',
                          // v1.2.51 调试模式：开 = 记录 debug 级日志 + 出错页显示详细报错（排错用，勿常开）
                          'debug_mode',
                          // v1.3.44 站点默认语言（语言包插件注册可选项；zh=不翻译）
                          'ow_lang_default',
                          // P1（WS 实时层）：实时通道开关与守护进程端口（配合 PM2 起的 ws-server.php）
                          'ws_enable', 'ws_port',
                          'ws_send_enable', 'ws_send_timeout', 'ws_send_max_inflight', 'ws_send_rooms'];
                // 数值型设置统一收敛为非负整数：负数会让间隔/保留期这类
                // 「窗口秒数」「天数」直接失效或行为诡异，前端输入框挡不住。
                $intKeys = ['guest_msg_interval', 'msg_retain_days', 'cron_log_retain_days', 'ws_port', 'ws_send_timeout', 'ws_send_max_inflight'];
                // v1.5.4 积分名称：单独清洗，不进下面那条通用分支。
                // 它是**用户可见的名词**，会出现在前端拼接的 HTML 与插件后台表单里，
                // 所以先删标签与空白/控制字符再限长（8 字），不接受换行、引号内换行之类的东西。
                // 空串 = 恢复默认「积分」：不把默认值写进库，日后改默认文案不必写迁移。
                if (isset($_POST['points_name'])) {
                    $pn = preg_replace('/[\s\x00-\x1F\x7F]+/u', '', strip_tags((string)$p('points_name')));
                    $pn = is_string($pn) ? $pn : '';
                    if (mb_strlen($pn, 'UTF-8') > 8) {
                        Api::json(['ok' => false, 'msg' => '积分名称最多 8 个字']);
                    }
                    DB::setSetting('points_name', $pn);
                }
                foreach ($allow as $k) {
                    if (!isset($_POST[$k])) continue;
                    if ($k === 'points_name') continue;   // 上面已单独处理
                    if (in_array($k, $intKeys, true)) {
                        DB::setSetting($k, (string)max(0, (int)$p($k)));
                    } else {
                        DB::setSetting($k, $p($k));
                    }
                }
                Sec::log('admin_settings', $actor['nickname']);
                Api::json(['ok' => true, 'msg' => '设置已保存']);

            // ---------- 计划任务（v1.1.13） ----------
            case 'admin_cron_list': {
                // 描述不进 cron_tasks 表（插件升级改描述时不必改库），
                // 按「插件::任务名」从注册表补齐；插件停用时注册表无条目，描述留空。
                $desc = [];
                foreach (Plugin::crons() as $t) {
                    $desc[$t['plugin'] . '::' . $t['name']] = (string)$t['description'];
                }
                // 「插件是否启用」以 plugins 表的 enabled 为准（与 runCron 同源），
                // 不能依赖本请求是否恰好加载过该插件 main.php（按需加载下 list 请求通常不加载，
                // 会让已启用插件被误判为「插件未启用」）。plugin 为空串表示核心任务，恒视为激活。
                $enabledPlugins = array_flip(Plugin::enabledPlugins());
                $rows = DB::all('SELECT * FROM cron_tasks ORDER BY enabled DESC, next_run_at ASC');
                $now = time();
                foreach ($rows as &$r) {
                    $key = $r['plugin'] . '::' . $r['name'];
                    $r['description'] = $desc[$key] ?? '';
                    // 停用插件的任务仍在表里，但每次执行都会被跳过 →
                    // 对它显示「启用」按钮是误导，前端据此隐藏启停开关。
                    $r['plugin_active'] = ($r['plugin'] === '') || isset($enabledPlugins[$r['plugin']]);
                    $r['interval_text'] = Admin::intervalText((int)$r['interval_sec']);
                    $r['next_run_text'] = (int)$r['next_run_at'] > 0
                        ? date('Y-m-d H:i:s', (int)$r['next_run_at']) : '—';
                    $r['last_run_text'] = (int)$r['last_run_at'] > 0
                        ? date('Y-m-d H:i:s', (int)$r['last_run_at']) : '从未执行';
                    $r['due'] = (int)$r['enabled'] === 1 && (int)$r['next_run_at'] > 0 && (int)$r['next_run_at'] <= $now;
                }
                unset($r);
                // 最近一次执行：同时看任务表与日志表（跳过类任务也写日志）
                $lastTask = (int)DB::val('SELECT COALESCE(MAX(last_run_at),0) FROM cron_tasks');
                $lastLog  = (int)DB::val('SELECT COALESCE(MAX(created_at),0) FROM cron_logs');
                $page = max(1, (int)$p('page'));
                $psize = max(5, min(100, (int)($p('psize') ?: 30)));
                $total = (int)DB::val('SELECT COUNT(*) FROM cron_logs');
                Api::json([
                    'ok'       => true,
                    'tasks'    => $rows,
                    'total'    => count($rows),
                    'due'      => (int)DB::val('SELECT COUNT(*) FROM cron_tasks WHERE enabled=1 AND next_run_at>0 AND next_run_at<=?', [$now]),
                    'enabled'  => (int)DB::val('SELECT COUNT(*) FROM cron_tasks WHERE enabled=1'),
                    'last_run' => max($lastTask, $lastLog),
                    'token'    => (string)DB::setting('cron_token', ''),
                    'logs'     => DB::all('SELECT * FROM cron_logs ORDER BY id DESC LIMIT ' . $psize . ' OFFSET ' . (($page - 1) * $psize)),
                    'log_total'=> $total,
                    'page'     => $page,
                    'pages'    => max(1, (int)ceil($total / $psize)),
                ]);
            }

            case 'admin_cron_toggle': {
                $id = (int)$p('id');
                $row = DB::one('SELECT * FROM cron_tasks WHERE id=?', [$id]);
                // ⚠️ 必须查 rowCount / 存在性：0 行时若仍返回 ok:true，
                // 前端会弹「已启用」但刷新后状态没变 —— 与 v1.1.2 公告删不掉是同一类假成功。
                if (!$row) Api::json(['ok' => false, 'msg' => '任务不存在'], 404);
                $on = (int)$row['enabled'] !== 1;
                DB::run('UPDATE cron_tasks SET enabled=?, next_run_at=?, updated_at=? WHERE id=?',
                    [$on ? 1 : 0, $on ? time() + max(60, (int)$row['interval_sec']) : 0, time(), $id]);
                Sec::log('cron_toggle', $row['plugin'] . '::' . $row['name']);
                Api::json(['ok' => true, 'msg' => $on ? '任务已启用' : '任务已停用', 'enabled' => $on]);
            }

            case 'admin_cron_run': {
                // force=true：忽略 next_run_at 全跑一遍。否则刚跑过的任务要等满整个间隔
                // 才会再次到期，连点「立即执行」永远是「执行 0 个」。
                $res = Plugin::runCron(50, true);
                $fail = 0;
                foreach ($res as $x) if ($x['status'] === 'error') $fail++;
                Sec::log('cron_run', '', ['count' => count($res), 'fail' => $fail]);
                $parts = ['执行 ' . count($res) . ' 个任务'];
                if ($fail > 0) $parts[] = '失败 ' . $fail . ' 个';
                Api::json(['ok' => true, 'msg' => implode('，', $parts), 'results' => $res]);
            }

            case 'admin_cron_token': {
                // 重置后旧地址立即失效
                $t = bin2hex(random_bytes(24));
                DB::setSetting('cron_token', $t);
                Sec::log('cron_token', $actor['nickname']);
                Api::json(['ok' => true, 'msg' => '新的触发令牌已生成，旧地址立即失效', 'token' => $t]);
            }

            case 'admin_cron_logs_clear': {
                // 不传 days（维护任务页的手动按钮就是这么调的）→ 按系统设置
                // 「执行日志保留期」（默认 90 天）清；显式传 days 仍按传的值清
                // （days=0 = 清空全部，老行为保留给显式调用方）。
                $raw = $p('days');
                $days = ($raw === null || $raw === '') ? null : max(0, (int)$raw);
                if ($days === null) {
                    $days = (int)DB::setting('cron_log_retain_days', 90);
                    if ($days <= 0) Api::json(['ok' => false, 'msg' => '保留期设为「永久保留」，未清理']);
                }
                $n = $days > 0
                    ? (int)DB::val('SELECT COUNT(*) FROM cron_logs WHERE created_at<?', [time() - $days * 86400])
                    : (int)DB::val('SELECT COUNT(*) FROM cron_logs');
                DB::run($days > 0 ? 'DELETE FROM cron_logs WHERE created_at<?' : 'DELETE FROM cron_logs',
                    $days > 0 ? [time() - $days * 86400] : []);
                Api::json(['ok' => true, 'msg' => "已清理 $n 条日志", 'deleted' => $n]);
            }

            // ---------- 插件管理 ----------
            case 'admin_plugins':
                // v1.2.57：额外下发**已注册后台页的 slug 清单**，供列表点标题直达。
                // 只给数量（listAll 的 pages）不够：那只是「注册了几个」，
                // 而 slug 由插件自定（惯例等于目录名，但不强制）——以注册表为准最稳。
                Api::json([
                    'ok'          => true,
                    'data'        => Plugin::listAll(),
                    'admin_pages' => array_keys(Plugin::adminPages()),
                ]);

            case 'admin_plugin_toggle':
                Plugin::toggle($p('name'), $p('enabled') === '1');
                Api::json(['ok' => true, 'msg' => '已更新']);

            case 'admin_plugin_install':
                if (empty($_FILES['file'])) Api::json(['ok' => false, 'msg' => '未接收到文件']);
                [$ok, $msg] = Plugin::installZip($_FILES['file']);
                Api::json(['ok' => $ok, 'msg' => $msg]);

            case 'admin_plugin_uninstall':
                if (!Plugin::uninstall($p('name'))) Api::json(['ok' => false, 'msg' => '卸载失败（插件不存在或名称非法）']);
                Api::json(['ok' => true, 'msg' => '已卸载']);

            case 'admin_plugin_page':
                $slug = $p('slug');
                $pages = Plugin::adminPages();
                if (!isset($pages[$slug])) {
                    // 已安装但未启用的插件：显示未启用占位页 + 一键启用按钮（v1.0.45）
                    $installed = array_column(Plugin::listAll(), null, 'id');
                    if (isset($installed[$slug])) {
                        Api::json(['ok' => true, 'html' =>
                            '<h2>' . Sec::e($installed[$slug]['name']) . '</h2>'
                            . '<div class="ow-card"><p style="margin:0 0 12px">该插件当前处于<b>停用</b>状态，设置页面不可用。启用后即可使用其功能与设置页。</p>'
                            . '<button class="ow-btn ow-btn-primary" onclick="OwAdmin.pluginToggle(\'' . Sec::e($slug) . '\',1)">启用插件</button></div>']);
                    }
                    Api::json(['ok' => false, 'msg' => '页面不存在'], 404);
                }
                // 兼容两种插件页面写法：fn 内 echo 输出，或 fn 返回 HTML 字符串（v1.0.44）
                ob_start();
                $ret = call_user_func($pages[$slug]['fn']);
                $html = ob_get_clean();
                if ($html === '' && is_string($ret) && $ret !== '') $html = $ret;
                Api::json(['ok' => true, 'html' => $html]);

            default:
                Api::json(['ok' => false, 'msg' => '未知操作'], 404);
        }
    }
}
