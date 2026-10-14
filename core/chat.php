<?php
/**
 * 聊天核心：房间、消息、长轮询、撤回、历史分页、敏感词、禁言、公告、在线列表
 */
class Chat
{
    /**
     * 撤回窗口（秒）：仅对**普通用户自己发的消息**生效。
     * 群主 / 超级管理员撤回任何时间的消息都放行（用于处理违规内容），不受此限制。
     * v1.2.3 由 180 秒（3 分钟）放宽为 300 秒（5 分钟）。
     */
    private const RECALL_WINDOW = 300;

    // ---------- 联系人（v1.1.24） ----------
    /**
     * 好友列表（只返回**我主动加的**那些，即 friends 表里 user_id = 我的行）。
     *
     * 语义说明（重要，别当成双向）：
     *   本表存的是单向关系 —— 「A 把 B 加为联系人」与「B 把 A 加为联系人」是两条独立行。
     *   因此这里返回的是**我的联系人名单**，不代表对方也把我加了。
     *   前端列表里点谁都能开私聊（私聊本身不需要好友关系，见 dmPeerKey）。
     *
     * 字段一律 JOIN users 取实时值，不存快照 —— 好友改昵称/换头像后立即同步。
     * 排序：昵称升序（联系人列表无「活跃度」概念，按名字找更顺手）。
     */
    public static function friends(array $actor): array
    {
        // 游客没有联系人：身份随会话消亡，写进表也无法回查
        if (($actor['kind'] ?? '') !== 'user') return [];
        $me = (int)$actor['id'];
        // v1.3.10：改为**双向可见**。
        // friends 表每行表示「user_id 单方面把 friend_id 加进了联系人」，
        // 原先只取 f.user_id = 我（= 我添加的人），于是别人添加我只会写进
        // (对方 → 我) 那一行，我这边完全查不到 —— 表现就是
        // 「别人加我为好友了，我的联系人列表不显示」。
        // 现在按「与我有任一方向关系」取人，并按对方去重（双方都加过会存在两行）。
        // MIN(created_at) 取最早一次建立时间，列表仍按昵称排序，结果稳定。
        // ⚠️ 三个占位符必须分开写：PDO 原生预处理不允许同一命名占位重复绑定。
        $rows = DB::all(
            'SELECT u.id, u.nickname, u.avatar, u.avatar_type, u.avatar_style, u.avatar_seed,
                    u.role, u.title, MIN(f.created_at) AS created_at
             FROM friends f
             JOIN users u ON u.id = CASE WHEN f.user_id = ? THEN f.friend_id ELSE f.user_id END
             WHERE f.user_id = ? OR f.friend_id = ?
             GROUP BY u.id, u.nickname, u.avatar, u.avatar_type, u.avatar_style, u.avatar_seed, u.role, u.title
             ORDER BY ' . DB::ci('u.nickname') . ' ASC',
            [$me, $me, $me]);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'user_id' => (int)$r['id'],
                'nickname' => (string)$r['nickname'],
                'avatar'   => Auth::avatarUrlFor($r, (string)$r['id']),
                'role'     => (string)($r['role'] ?? 'member'),
                'title'    => (string)($r['title'] ?? ''),
                'added_at' => (int)$r['created_at'],
            ];
        }
        return $out;
    }

    /**
     * 删除联系人（幂等）。返回 [ok, msg]。
     *
     * v1.3.73：默认连带清空**本机这一侧**与 TA 的聊天记录（会话列表里也不再显示）。
     * 走 v1.2.28 就有的 clearDmForMe —— 只写 message_hides，服务器上一行消息都不删，
     * 对方照常看得到他自己的记录。为什么用隐藏而不是 DELETE：站内只有一份库，
     * 一条私信两人共用一行，删行等于替对方做了决定。
     * 会话列表 / 私聊历史 / 轮询 / 搜索都已按 hiddenIds 过滤（见 conversations、
     * dmHistory、poll、search），所以「消息列表不展示」不需要另写一遍判定。
     */
    public static function removeFriend(array $actor, int $friendId): array
    {
        if (($actor['kind'] ?? '') !== 'user') return [false, '请先登录'];
        $me = (int)$actor['id'];
        if ($friendId <= 0) return [false, '参数错误'];
        // v1.3.10：联系人关系已改成双向可见，删除时两个方向一起清 ——
        // 只删我发起的这一行的话，对方那条 (对方 → 我) 还在，
        // 我这边的列表里仍会留着他（等于删不掉）。
        DB::run('DELETE FROM friends WHERE (user_id=? AND friend_id=?) OR (user_id=? AND friend_id=?)',
            [$me, $friendId, $friendId, $me]);
        $peer = self::dmPeerKey($actor, 'user:' . $friendId);   // 与 dm_clear 同一入口校验，非法对像直接跳过
        $n = 0;
        if ($peer) {
            [, , $n] = self::clearDmForMe($actor, $peer);
        }
        return [true, $n > 0 ? ('已删除联系人，并清空 ' . $n . ' 条聊天记录') : '已删除联系人'];
    }

    /* ---------- 好友请求（v1.3.71）：先申请，对方通过后才成为联系人 ---------- */

    /**
     * 「我发出的请求」只保留最近这么多条，更早的直接删。
     * 申请是有时效的动作：翻 20 条之前的记录没有意义，留着只会让页面越来越长。
     * 只按 from_id（我发出的）裁剪，**不动别人发给我的** —— 收件箱该不该留由收件人决定。
     */
    private const REQ_KEEP = 20;

    /** 裁掉我发出的第 REQ_KEEP 条之外的旧请求 */
    private static function pruneFriendReq(int $me): void
    {
        // 不用 DELETE ... ORDER BY id LIMIT n：那是 MySQL 专有写法，SQLite / PG 不认。
        // 先探出「第 21 条」的 id，再删比它老的 —— 两个方言都是普通 SQL。
        $cut = DB::val('SELECT id FROM friend_req WHERE from_id=? ORDER BY id DESC LIMIT 1 OFFSET ' . self::REQ_KEEP, [$me]);
        if ($cut) DB::run('DELETE FROM friend_req WHERE from_id=? AND id<?', [$me, (int)$cut]);
    }

    /** 两人当前是否已是联系人（任一行即算 —— 与 friends() 的双向可见同一口径） */
    public static function areFriends(int $a, int $b): bool
    {
        if ($a <= 0 || $b <= 0 || $a === $b) return false;
        return (int)DB::val(
            'SELECT id FROM friends WHERE (user_id=? AND friend_id=?) OR (user_id=? AND friend_id=?) LIMIT 1',
            [$a, $b, $b, $a]) > 0;
    }

    /** 「新朋友」入口的固定头像 = 生成式头像「小可爱（open-peeps）第 6 号」。
     *  与系统通知头像（第 36 号）同一套写法：风格与种子写死，不随机 ——
     *  它是站内一个固定入口，头像每次都得是同一张，否则用户回看时认不出。
     *  种子取值范围见 Auth::AVATAR_SEED_COUNT（档位号→真实 seed 的映射在 Auth 里）。 */
    private const FRIEND_AVATAR_SEED = '6';

    public static function friendAvatarUrl(): string
    {
        return Auth::avatarGeneratedUrl(Auth::AVATAR_DEFAULT_STYLE, self::FRIEND_AVATAR_SEED);
    }

    /** 待我处理的好友请求数 —— 联系人栏小红点的计数。语义是「未处理」，通过/拒绝才消 */
    public static function friendReqPending(int $uid): int
    {
        return $uid > 0 ? (int)DB::val('SELECT COUNT(*) FROM friend_req WHERE to_id=? AND status=0', [$uid]) : 0;
    }

    /** 我今天发出的请求数 —— captcha-verify「每天前 N 个好友请求免人机验证」按这个判 */
    public static function friendReqToday(int $uid): int
    {
        if ($uid <= 0) return 0;
        return (int)DB::val('SELECT COUNT(*) FROM friend_req WHERE from_id=? AND created_at>=?',
            [$uid, (int)strtotime(date('Y-m-d 00:00:00'))]);
    }

    /**
     * 确立联系人关系：只写「$by → $for」一行。
     * 联系人查询双向可见（见 friends()），一行就让两个人互相看到对方；
     * 不写两行是为了让「删除联系人」保持单一动作（removeFriend 两个方向一起清）。
     */
    private static function linkFriends(int $by, int $for): void
    {
        DB::insertIgnore('friends',
            ['user_id' => $by, 'friend_id' => $for, 'created_at' => time()],
            ['user_id', 'friend_id']);
    }

    /**
     * 发起好友请求。返回 [ok, msg]。
     * 验证消息选填（截到 200 字）：它是「申请那一刻写的话」，属于历史事实，
     * 所以破例存文本快照而不是引用（不像联系人列表那样一律 JOIN users）。
     */
    public static function friendRequest(array $actor, int $toId, string $note = ''): array
    {
        if (($actor['kind'] ?? '') !== 'user') return [false, '请先登录'];
        $me = (int)$actor['id'];
        if ($toId <= 0) return [false, '参数错误'];
        if ($toId === $me) return [false, '不能加自己为好友'];
        // 身份一律用数字 user_id 查（与全站口径一致：禁止用昵称/邮箱反查）
        $u = DB::one('SELECT id, nickname, status FROM users WHERE id=?', [$toId]);
        if (!$u) return [false, '该用户不存在'];
        if ((int)$u['status'] !== 1) return [false, '该用户已被封禁，无法添加'];
        if (self::areFriends($me, $toId)) return [false, '你们已经是联系人了'];
        // 等级闸门沿用（v1.2.42，等级信任插件按等级限制加好友）：请求也是加好友的入口
        $allow = true; $reason = '';
        Plugin::fire('friend.guard', [&$allow, &$reason, $actor, $toId]);
        if (!$allow) return [false, $reason !== '' ? $reason : '当前等级暂不能添加好友'];

        $note = mb_substr(trim($note), 0, 200);

        // 对向已有待处理请求（他先加我、我又加他）→ 直接互相通过。
        // 不这么做的话两人会各自挂着一条永远等不到回应的请求。
        $rev = (int)DB::val('SELECT id FROM friend_req WHERE from_id=? AND to_id=? AND status=0', [$toId, $me]);
        if ($rev > 0) {
            self::handleFriendRequest($actor, $rev, true);
            return [true, '对方也向你发过请求，你们已成为联系人'];
        }

        $pend = (int)DB::val('SELECT id FROM friend_req WHERE from_id=? AND to_id=? AND status=0', [$me, $toId]);
        if ($pend > 0) {
            // 重复点「加好友」不插新行、不重复打扰，只把验证消息更新成最新一条
            DB::run('UPDATE friend_req SET note=?, created_at=? WHERE id=?', [$note, time(), $pend]);
            return [true, '请求已在待处理中，等待对方回应'];
        }
        DB::insert('friend_req', [
            'from_id' => $me, 'to_id' => $toId, 'note' => $note,
            'status' => 0, 'created_at' => time(), 'handled_at' => 0,
        ]);
        self::pruneFriendReq($me);   // 发完再裁：新的一条一定落在保留窗口内
        return [true, '好友请求已发送给「' . (string)$u['nickname'] . '」'];
    }

    /**
     * 处理收到的请求：只有收件人能处理，且只能处理一次（status 必须还是 0）。
     * 通过 → 写联系人 + 通知申请人；拒绝 → 只置状态、不发通知
     * （通知一条「他被你拒了」没有任何可执行内容，只会变成骚扰）。
     */
    public static function handleFriendRequest(array $actor, int $reqId, bool $accept): array
    {
        if (($actor['kind'] ?? '') !== 'user') return [false, '请先登录'];
        $me = (int)$actor['id'];
        $r = DB::one('SELECT q.id, q.from_id, q.status, u.nickname AS from_nick
                      FROM friend_req q JOIN users u ON u.id = q.from_id
                      WHERE q.id=? AND q.to_id=?', [$reqId, $me]);
        if (!$r) return [false, '请求不存在'];
        if ((int)$r['status'] !== 0) return [false, '该请求已经处理过了'];
        $fromId = (int)$r['from_id'];
        DB::run('UPDATE friend_req SET status=?, handled_at=? WHERE id=?',
            [$accept ? 1 : 2, time(), $reqId]);
        if (!$accept) return [true, '已拒绝'];
        self::linkFriends($me, $fromId);
        Notice::push($fromId, 'friend_accepted', ['nick' => (string)($actor['nickname'] ?? '')]);
        return [true, '已添加「' . (string)$r['from_nick'] . '」为联系人'];
    }

    /**
     * 好友通知页数据：incoming = 待我处理的，outgoing = 我发出的近期记录（含状态）。
     * 昵称/头像一律 JOIN users 取实时值（与 friends() 同一口径），note 是快照。
     */
    public static function friendRequests(array $actor): array
    {
        $out = ['incoming' => [], 'outgoing' => []];
        if (($actor['kind'] ?? '') !== 'user') return $out;
        $me = (int)$actor['id'];
        $sel = 'SELECT q.id, q.status, q.note, q.created_at, q.handled_at,
                       u.id AS uid, u.nickname, u.avatar, u.avatar_type, u.avatar_style, u.avatar_seed, u.role, u.title';
        // 两个方向各写整句字面量：列名不拼变量，杜绝任何「标识符来自入参」的可能
        $out['incoming'] = self::friendReqRows(
            $sel . ' FROM friend_req q JOIN users u ON u.id = q.from_id
             WHERE q.to_id=? AND q.status=0 ORDER BY q.id DESC LIMIT 50', [$me]);
        $out['outgoing'] = self::friendReqRows(
            $sel . ' FROM friend_req q JOIN users u ON u.id = q.to_id
             WHERE q.from_id=? ORDER BY q.id DESC LIMIT ' . self::REQ_KEEP, [$me]);
        return $out;
    }

    private static function friendReqRows(string $sql, array $args): array
    {
        $out = [];
        foreach (DB::all($sql, $args) as $r) {
            $uid = (int)$r['uid'];
            $out[] = [
                'id' => (int)$r['id'],
                'user_id' => $uid,
                'nickname' => (string)$r['nickname'],
                'avatar' => Auth::avatarUrlFor($r, (string)$uid),
                'role' => (string)($r['role'] ?? 'member'),
                'title' => (string)($r['title'] ?? ''),
                'note' => (string)($r['note'] ?? ''),
                'status' => (int)$r['status'],
                'created_at' => (int)$r['created_at'],
                'handled_at' => (int)$r['handled_at'],
            ];
        }
        return $out;
    }

    // ---------- 房间 ----------
    /**
     * 群头像：自定义优先，否则**回落创建者头像**（v1.3.11 用户要求）。
     * 顺序：上传图 → 群自己的生成式风格 → 创建者头像 → 用群 id 派生一个（不空）。
     */
    public static function roomAvatarUrl(array $room): string
    {
        $type = (string)($room['avatar_type'] ?? '');
        $path = (string)($room['avatar'] ?? '');
        if ($path !== '' && ($type === '' || $type === 'upload')) return Auth::avatarFileUrl($path);
        $rid  = (int)($room['id'] ?? 0);
        if ($type === 'generated') {
            $seed = (string)($room['avatar_seed'] ?? '');
            return Auth::avatarGeneratedUrl((string)($room['avatar_style'] ?? ''), $seed !== '' ? $seed : 'room-' . $rid);
        }
        $ownerId = (int)($room['owner_id'] ?? 0);
        if ($ownerId > 0) {
            $u = DB::one('SELECT id, avatar, avatar_type, avatar_style, avatar_seed FROM users WHERE id=?', [$ownerId]);
            if ($u) return Auth::avatarUrlFor($u, (string)$ownerId);
        }
        // 创建者已被删除 → 用群 id 派生一个，保证列表里不留空白
        return Auth::avatarGeneratedUrl('', 'room-' . $rid);
    }

    public static function rooms(array $actor): array
    {
        $list = DB::all('SELECT * FROM rooms WHERE status=1 ORDER BY id');
        // v1.1.11：一次性取出「我加入的所有群」，避免在循环里逐群查成员表（N+1）。
        $mineSet = self::memberRoomIds($actor);
        $out = [];
        foreach ($list as $r) {
            if (!self::canEnter($r, $actor, true)) continue;
            $out[] = [
                'id' => (int)$r['id'], 'name' => $r['name'], 'slug' => $r['slug'],
                'type' => $r['type'], 'need_password' => $r['type'] === 'password',
                // v1.1.11：公开性随房间下发，前端据此显示「公开/不公开」与邀请入口
                'is_public' => (int)($r['is_public'] ?? 1) === 1,
                // 我是否在这个群的成员表里（群主恒为 true，不依赖 room_members 行）
                'is_member' => (int)($r['owner_id'] ?? 0) === (int)($actor['id'] ?? 0)
                    || (isset($mineSet[(int)$r['id']]) && $actor['kind'] === 'user'),
                'description' => $r['description'] ?? '',
                'owner_id' => (int)($r['owner_id'] ?? 0),
                // v1.3.11：群头像 = 自定义 → 创建者头像 → 群 id 派生（不再用固定剪影图）
                'avatar' => self::roomAvatarUrl($r),
                'mine' => (int)($r['owner_id'] ?? 0) === (int)($actor['id'] ?? 0) && $actor['kind'] === 'user',
            ];
            // 前台可编辑（群聊设置弹窗 / 右侧栏入口）：群主 + 超级管理员。
            // v1.1.0 修正：原先只判群主，导致「非群主的超管」进不去群聊设置，
            // 连带群公告等插件入口（onRoomEdit 按 isOwner||isAdmin 渲染）也拿不到，
            // 表现为「服务端允许删除但前台没有删除按钮」的契约不一致。
            // 判定口径与插件服务端 $oaCanManage 对齐（admin 直接放行）。
            $out[count($out) - 1]['can_edit'] = $actor['kind'] === 'user'
                && ($actor['role'] === 'admin'
                    || ((int)($r['owner_id'] ?? 0) === (int)$actor['id']));
            // v1.1.11：能否邀请成员 = 本人是群成员（含群主）且对方有邀请入口。
            // 群主与超管必然是成员；普通成员在公开群里进过群也算「加入了该群」。
            $out[count($out) - 1]['can_invite'] = $actor['kind'] === 'user'
                && ((int)($r['owner_id'] ?? 0) === (int)$actor['id']
                    || $actor['role'] === 'admin'
                    || (isset($mineSet[(int)$r['id']])));
        }
        return $out;
    }

    // ---------- 群成员（v1.1.11） ----------

    /**
     * 当前身份已加入的房间 ID 集合（room_members.room_id => true）。
     * 游客与未登录一律返回空数组：游客身份随浏览器会话消亡，
     * 同名游客背后可能是任意多个人，不能也不该成为成员。
     */
    public static function memberRoomIds(array $actor): array
    {
        if (($actor['kind'] ?? '') !== 'user') return [];
        $uid = (int)($actor['id'] ?? 0);
        if ($uid <= 0) return [];
        $set = [];
        foreach (DB::all('SELECT room_id FROM room_members WHERE user_id=?', [$uid]) as $r) {
            $set[(int)$r['room_id']] = true;
        }
        return $set;
    }

    /** 是否为该群成员（群主恒真；游客恒假） */
    public static function isMember(array $room, array $actor): bool
    {
        if (($actor['kind'] ?? '') !== 'user') return false;
        $uid = (int)($actor['id'] ?? 0);
        if ($uid <= 0) return false;
        if ((int)($room['owner_id'] ?? 0) === $uid) return true;      // 群主恒为成员
        if ($actor['role'] === 'admin') return true;                // 超管可见全部群（既有口径）
        // ⚠️ 必须转 int 再比大小，**不能**写 !== null：
        // DB::val() 底层是 PDOStatement::fetchColumn()，无匹配行时返回 **false** 而非 null，
        // `false !== null` 恒为 true —— 会把所有人都判成成员，不公开群直接形同虚设。
        // 全库其余 DB::val 调用点都是 (int) 或 ?: 转型，只有这里踩过这个坑。
        return (int)DB::val('SELECT 1 FROM room_members WHERE room_id=? AND user_id=?',
            [(int)$room['id'], $uid]) > 0;
    }

    /** 该房间是否允许邀请他人（群主 + 成员 + 超管） */
    public static function canInvite(array $room, array $actor): bool
    {
        return self::isMember($room, $actor);
    }

    /**
     * 邀请一名注册用户进群（幂等：已在群内直接返回已存在）。
     * 只接受**数字用户 ID**——与全站身份口径一致（昵称可重名、邮箱属个人信息，
     * 二者都不能做身份标识或反查，见开发文档「开发约束」）。
     */
    public static function inviteMember(array $room, array $actor, int $userId): array
    {
        $user = DB::one('SELECT id, nickname FROM users WHERE id=? AND status=1', [$userId]);
        if (!$user) return [false, '用户不存在或已停用'];
        if ((int)$room['owner_id'] === $userId) return [false, '对方就是群主，无需邀请'];
        $exists = DB::val('SELECT 1 FROM room_members WHERE room_id=? AND user_id=?',
            [(int)$room['id'], $userId]);
        if ($exists) return [false, ($user['nickname'] ?? '') . ' 已在群内'];
        DB::insert('room_members', [
            'room_id' => (int)$room['id'],
            'user_id' => $userId,
            'invited_by' => (int)($actor['id'] ?? 0),
            'created_at' => time(),
        ]);
        return [true, '已邀请 ' . ($user['nickname'] ?? '') . '（用户 ID ' . $userId . '）'];
    }

    /** 移出成员（仅群主/超管；群主不能移除自己，避免把群变成无人可管） */
    public static function removeMember(array $room, array $actor, int $userId): array
    {
        $uid = (int)($actor['id'] ?? 0);
        $isOwner = (int)($room['owner_id'] ?? 0) === $uid || $actor['role'] === 'admin';
        if (!$isOwner) return [false, '仅群主或超级管理员可移出成员'];
        if ($userId === (int)$room['owner_id']) return [false, '不能移出群主'];
        $st = DB::run('DELETE FROM room_members WHERE room_id=? AND user_id=?', [(int)$room['id'], $userId]);
        $n = is_object($st) && method_exists($st, 'rowCount') ? (int)$st->rowCount() : 0;
        if ($n <= 0) return [false, '该用户不是本群成员'];
        return [true, '已移出成员'];
    }

    /** 成员列表（含昵称/角色），按加入时间倒序 */
    public static function memberList(array $room): array
    {
        $rows = DB::all(
            'SELECT rm.id, rm.user_id, rm.invited_by, rm.created_at,
                    u.nickname, u.role, u.avatar, u.avatar_type, u.avatar_style, u.avatar_seed
             FROM room_members rm LEFT JOIN users u ON u.id = rm.user_id
             WHERE rm.room_id=? ORDER BY rm.created_at DESC', [(int)$room['id']]);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'user_id' => (int)$r['user_id'],
                'nickname' => (string)($r['nickname'] ?? ''),
                'role' => (string)($r['role'] ?? 'member'),
                // v1.3.11：下发最终 URL（上传图或生成式）
                'avatar' => Auth::avatarUrlFor($r, (string)($r['user_id'] ?? '')),
                'invited_by' => (int)($r['invited_by'] ?? 0),
                'created_at' => (int)$r['created_at'],
            ];
        }
        return $out;
    }

    /**
     * 加入群聊（幂等）。v1.2.27：公开群聊从「点进去就能说话」改为**必须显式加入**。
     *
     * 背景：以前 room_join 只做密码房通行校验，公开群点了就直接进、不产生任何成员关系，
     * 于是「所有成员」区块只能拿 online 心跳（45 秒窗口）凑数，离线成员根本看不到。
     *
     * ⚠️ 只有**注册用户**能成为成员。游客身份随浏览器会话消亡（没有 user_id），
     * 写进 room_members 会积累永久垃圾行（见 memberRoomIds 注释），
     * 游客一律走「在场即显示、离开即消失」的 online 心跳口径。
     */
    public static function joinRoom(array $room, array $actor): array
    {
        if (($actor['kind'] ?? '') !== 'user') return [false, '游客无需加入，直接进入即可'];
        $uid = (int)($actor['id'] ?? 0);
        if ($uid <= 0) return [false, '请先登录'];
        if ((int)($room['owner_id'] ?? 0) === $uid) return [true, '你是群主，无需加入'];
        // 幂等预检必须**直查 room_members 行**，不能用 isMember()：
        // isMember() 对超管恒真（既有「超管进任意群」口径），走它会永远命中
        // 「已在本群」提前返回、从不插行；而群列表的 is_member（rooms()）
        // 只认表行，于是超管每次点别人创建的群都再弹「是否加入」。
        if ((int)DB::val('SELECT 1 FROM room_members WHERE room_id=? AND user_id=?',
            [(int)$room['id'], $uid]) > 0) return [true, '已在本群'];
        DB::insert('room_members', [
            'room_id' => (int)$room['id'],
            'user_id' => $uid,
            'invited_by' => 0,          // 0 = 主动加入（非邀请）
            'created_at' => time(),
        ]);
        return [true, '已加入群聊'];
    }

    /**
     * 「所有成员」区块的统一口径（v1.2.27）。
     *
     *   注册用户成员 = room_members **全量**（含离线） + 群主补位
     *   在场游客     = online 表里本群的 guest_id 行（心跳 45 秒内）
     *
     * 为什么成员不再直接用 online 表：online 只有 45 秒窗口，问的是「谁在线」；
     * 「所有成员」要的是「谁加入了这个群」，离线的也必须在列。
     * 为什么游客仍走 online：游客没有持久身份，只能按在场判定（见 joinRoom 注释）。
     *
     * @return array{members:array,guests:array}
     */
    public static function allMembers(array $room, array $actor): array
    {
        $rid = (int)($room['id'] ?? 0);
        $ownerId = (int)($room['owner_id'] ?? 0);
        $since = time() - 45;

        // 在线标记：online 表窗口内的 user_id 集合（仅用于打点，不是名单来源）
        $onlineSet = [];
        if ($rid > 0) {
            foreach (DB::all('SELECT user_id FROM online WHERE room_id=? AND last_seen>? AND user_id>0',
                [$rid, $since]) as $o) {
                $onlineSet[(int)$o['user_id']] = true;
            }
        }

        $out = [];
        $seen = [];
        foreach (self::memberList($room) as $m) {
            $uid = (int)$m['user_id'];
            if ($uid <= 0 || isset($seen[$uid])) continue;
            $seen[$uid] = true;
            $out[] = [
                'uid' => $uid, 'gid' => null, 'kind' => 'user',
                'nickname' => (string)$m['nickname'],
                'role' => (string)($m['role'] ?: 'member'),
                'avatar' => (string)$m['avatar'],
                'online' => isset($onlineSet[$uid]),
                'is_owner' => $uid === $ownerId,
            ];
        }
        // 群主补位：建群时不一定给自己插 room_members 行，但群主恒为成员
        if ($ownerId > 0 && !isset($seen[$ownerId])) {
            $u = DB::one('SELECT nickname, role, avatar, avatar_type, avatar_style, avatar_seed FROM users WHERE id=?', [$ownerId]);
            if ($u) {
                $out[] = [
                    'uid' => $ownerId, 'gid' => null, 'kind' => 'user',
                    'nickname' => (string)$u['nickname'],
                    'role' => (string)($u['role'] ?: 'member'),
                    'avatar' => Auth::avatarUrlFor($u, (string)$ownerId),
                    'online' => isset($onlineSet[$ownerId]),
                    'is_owner' => true,
                ];
            }
        }
        // 排序：群主 → 在线 → 用户 ID（稳定，避免每次刷新顺序跳动）
        usort($out, function (array $a, array $b): int {
            if ($a['is_owner'] !== $b['is_owner']) return $a['is_owner'] ? -1 : 1;
            if ($a['online'] !== $b['online']) return $a['online'] ? -1 : 1;
            return (int)$a['uid'] <=> (int)$b['uid'];
        });

        // 在场游客：只有公开群可能有游客（不公开群游客进不来，查询结果自然为空）
        $guests = [];
        if ($rid > 0 && (int)($room['is_public'] ?? 1) === 1) {
            // online 一行一个会话 token，同一游客多端登录就有好几行，这里按 guest_id 收拢。
            // ⚠️ nickname / avatar 必须显式聚合：MySQL 5.7 默认 sql_mode 带
            //   ONLY_FULL_GROUP_BY，SELECT 里出现未分组、未聚合的列直接报 1055
            //   （SQLite 容忍，所以这条在迁移前从没暴露 —— 它挂在 poll 与 room_join
            //   两条路径上，表现就是「点任何群聊都 500」）。
            //   取 MAX 而不是子查询挑代表行：同一 guest_id 本就是同一个人，
            //   名字与头像来自他的不同会话无害，换来的是两个方言都能跑的直白 SQL。
            foreach (DB::all('SELECT guest_id, MAX(nickname) AS nickname, MAX(avatar) AS avatar FROM online
                              WHERE room_id=? AND last_seen>? AND guest_id>0
                              GROUP BY guest_id ORDER BY MIN(last_seen)', [$rid, $since]) as $g) {
                $guests[] = [
                    'uid' => null, 'gid' => (int)$g['guest_id'], 'kind' => 'guest',
                    'nickname' => (string)$g['nickname'],
                    'role' => 'guest',
                    'avatar' => (string)($g['avatar'] ?? ''),
                    'online' => true, 'is_owner' => false,
                ];
            }
        }
        return ['members' => $out, 'guests' => $guests];
    }

    /** 生成/重置邀请码（仅群主/超管）。返回新码 */
    public static function resetInviteCode(array $room, array $actor): string
    {
        $code = strtolower(bin2hex(random_bytes(5)));   // 10 位 hex，无歧义字符
        DB::run('UPDATE rooms SET invite_code=? WHERE id=?', [$code, (int)$room['id']]);
        return $code;
    }

    /**
     * 新建群时按公开性决定是否预生成邀请码。
     * 不公开群必须一建好就有码，否则「成员管理」里既展示不出邀请链接、
     * 也没有「重置邀请码」按钮可点（前端按 invite_code 非空来渲染这两处）。
     * @param int $isPublic 1=公开 0=不公开
     */
    public static function newInviteCode(int $isPublic): string
    {
        return $isPublic === 1 ? '' : strtolower(bin2hex(random_bytes(5)));
    }

    public static function room(int $id): ?array
    {
        return DB::one('SELECT * FROM rooms WHERE id=? AND status=1', [$id]);
    }

    /** 进入权限检查（$silent 仅判断可见性） */
    public static function canEnter(array $room, array $actor, bool $silent = false): bool
    {
        // v1.1.11：不公开群的**第一道闸**，先于 type 判断——
        // 未被邀请的人一律看不到、进不去，无论 type 是 public/password/role。
        // 群主与超管恒放行（isMember 内已含）；游客在非公开群没有任何入口，
        // 也无法被邀请（游客身份随会话消亡，不能作为成员，见 memberRoomIds）。
        if ((int)($room['is_public'] ?? 1) !== 1 && !self::isMember($room, $actor)) {
            return false;
        }
        if ($room['type'] === 'role') {
            $need = Auth::roleLevel($room['min_role']);
            return Auth::roleLevel($actor['role'] ?? 'guest') >= $need;
        }
        if ($actor['kind'] === 'none' && DB::setting('guest_browse', '1') !== '1') return false;
        return true;
    }

    public static function checkRoomPassword(array $room, string $password): bool
    {
        return $room['type'] !== 'password' || hash_equals((string)$room['password'], $password);
    }

    // ---------- 密码房通行缓存（避免每次进入都重新输密码） ----------
    /** 缓存时长（秒），0 表示每次都要输入 */
    public static function passTtl(): int
    {
        return (int)DB::setting('room_pass_ttl', 1800);
    }

    /** 该房间是否已持有未过期的通行授权 */
    public static function roomPassCached(int $roomId): bool
    {
        $ttl = self::passTtl();
        if ($ttl <= 0) return false;
        return !empty($_SESSION['room_pass'][$roomId]) && (int)$_SESSION['room_pass'][$roomId] > time();
    }

    /** 授予通行授权（默认保留 30 分钟，可在后台配置） */
    public static function grantRoomPass(int $roomId): void
    {
        $ttl = self::passTtl();
        if ($ttl <= 0) return;
        if (!isset($_SESSION['room_pass']) || !is_array($_SESSION['room_pass'])) $_SESSION['room_pass'] = [];
        $_SESSION['room_pass'][$roomId] = time() + $ttl;
    }

    /**
     * 完整进入校验：角色房查角色，密码房必须有有效通行授权（管理员免密）
     */
    public static function roomAccessOk(array $room, array $actor): bool
    {
        if (!self::canEnter($room, $actor)) return false;
        if ($room['type'] === 'password') {
            if ($actor['role'] === 'admin') return true;      // 管理员免密码
            return self::roomPassCached((int)$room['id']);
        }
        return true;
    }

    // ---------- 禁言检查 ----------
    public static function isBanned(array $actor, int $roomId): ?string
    {
        $now = time();
        $checks = [];
        if ($actor['kind'] === 'user') $checks[] = ['user', (string)$actor['id']];
        if ($actor['kind'] === 'guest') $checks[] = ['guest', $actor['nickname']];
        $checks[] = ['ip', Sec::ip()];
        foreach ($checks as [$type, $target]) {
            $b = DB::one(
                'SELECT * FROM bans WHERE type=? AND target=? AND (room_id=0 OR room_id=?) AND (expires_at IS NULL OR expires_at=0 OR expires_at>?) ORDER BY id DESC LIMIT 1',
                [$type, $target, $roomId, $now]
            );
            if ($b) {
                $exp = $b['expires_at'] ? '，解封时间 ' . date('Y-m-d H:i', (int)$b['expires_at']) : '，永久';
                return '您已被禁言' . $exp . ($b['reason'] ? '，原因：' . $b['reason'] : '');
            }
        }
        // 插件扩展判定（v1.0.52）：核心表无禁言时，插件可通过 $reason 追加自定义
        // 禁言逻辑（返回原因字符串即拦截）。每条消息触发一次，回调内避免重查询。
        $reason = null;
        Plugin::fire('ban.check', [&$reason, $actor, $roomId]);
        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    // ---------- 敏感词 ----------
    // （v1.0.104）过滤逻辑已剥离为 sensitive-words 插件；核心只提供 text.filter 钩子——
    // 所有输入的文字内容在入库前触发：Plugin::fire('text.filter', [&$text, $scene, $actor])，
    // 场景 scene：message / nickname / room_name / room_desc / announcement。
    public static function filterText(string &$text, string $scene, array $actor = []): void
    {
        Plugin::fire('text.filter', [&$text, $scene, $actor]);
    }

    /**
     * 内容否决钩子（v1.3.104，与 text.filter 配对）。
     *
     * text.filter 只能**改写**文字（敏感词打码），拦不住「这条根本不该发」——
     * 刷屏与 AI 判定要拒发时必须有个出口，否则插件只能把正文替换成一句提示，
     * 消息照样落库、照样在别人屏幕上闪一下。
     * 约定：任一插件往 $reason 里写非空字符串即拒发，理由原样回给用户；不认领就留空。
     * $text 同样按引用传出：判定方（AI 防线）可以就地改写正文，这样一个钩子就能同时
     * 表达「拦」与「换」，不必让插件把同一段文字送去判两次。
     * 只对**消息正文**开放（昵称/群名等仍走 filterText），场景与 text.filter 同一套。
     */
    public static function checkText(string &$text, string $scene, array $actor = []): ?string
    {
        $reason = '';
        Plugin::fire('text.check', [&$reason, &$text, $scene, $actor]);
        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
     * 图文混排消息（type=rich）的形态校验与拆分（v1.3.86）。
     *
     * 前端送来的是 `{"text":"…","media":[{"url":"uploads/image/…","kind":"image"},…]}`。
     * 为什么要这个类型：用户要的是「一段话配几张图」**在同一条消息里** ——
     * 拆成多条发出去，引用、删除、撤回、看记录的人都要逐条操作，图文关系也断了。
     *
     * 四条硬约束（请求可以绕过前端直接打 send，所以服务端必须自己判）：
     *  · url 必须是本站 uploads/ 下**真实存在**的文件（`Upload::mediaRel`），外站一律否；
     *  · 媒体最多 9 个 —— 与输入框待发区的上限同值，两边各拦一次；
     *  · kind 只允许 image / video，其它一律按文件走（不进混排）；
     *  · 文字与媒体至少要有一个非空，否则是空消息。
     *
     * @return array{0:bool,1:string,2:string,3:array} [是否通过, 失败原因, 文字, 媒体数组]
     */
    public static function normalizeRich(string $content): array
    {
        $j = json_decode($content, true);
        if (!is_array($j)) return [false, '混排消息格式不正确', '', []];
        $text = trim((string)($j['text'] ?? ''));
        if (mb_strlen($text) > 2000) return [false, '消息过长（最多 2000 字）', '', []];
        $media = [];
        $raw = isset($j['media']) && is_array($j['media']) ? $j['media'] : [];
        if (count($raw) > 9) return [false, '一次最多发送 9 个图片/视频', '', []];
        foreach ($raw as $m) {
            if (!is_array($m)) return [false, '混排消息里的媒体项不正确', '', []];
            $kind = (string)($m['kind'] ?? '');
            if (!in_array($kind, ['image', 'video'], true)) return [false, '混排消息只支持图片和视频', '', []];
            $rel = Upload::mediaRel((string)($m['url'] ?? ''));
            if ($rel === null) return [false, '混排消息里的文件无效或已被删除', '', []];
            // 文件名只是展示用的标题（视频气泡下面那行字），不参与寻址：
            // 换行与引号一律去掉，长度截到 120，别让它变成注入 JSON 的入口。
            $name = mb_substr(str_replace(["\r", "\n", '"'], '', trim((string)($m['name'] ?? ''))), 0, 120);
            // 字节数：前端报的是本地 File.size（上传前就知道），只当展示用，
            // 一律夹成非负整数 —— 负数与字符串都会原样进 JSON，渲染时很难看。
            $size = max(0, (int)($m['size'] ?? 0));
            // 存相对路径（uploads/ 下）：换域名 / 换协议时，历史消息不会跟着一起失效
            $media[] = ['url' => 'uploads/' . $rel, 'kind' => $kind, 'name' => $name, 'size' => $size];
        }
        if ($text === '' && !$media) return [false, '消息不能为空', '', []];
        return [true, '', $text, $media];
    }

    // ---------- 发送消息 ----------
    public static function send(array $actor, int $roomId, string $type, string $content, array $opt = []): array
    {
        // ---------- 消息形态归一（必须先于私聊判定） ----------
        // v1.1.0 起私聊用 type='private' 一列兼表两个正交维度：「这是私聊」与「消息形态是文本」。
        // 后果：私聊里发图片/文件时 type 变成 'image'/'file'，服务端立刻认不出私聊
        // （room_id=0 查不到群 → 报「群聊不存在」），历史/轮询 SQL 也永远漏掉这类消息。
        // v1.2.1 起私聊身份改由「room_id=0 且指定接收方」判定，type 回归纯消息形态；
        // 旧客户端/旧缓存页面传来的 'private' 一律降级为普通文本，不丢消息。
        if ($type === 'private') $type = 'text';

        // v1.2.1：私聊 = 虚拟私聊空间（room_id=0）+ 指定接收方，与消息形态无关。
        $isDm = ($roomId === 0);
        $toUserId = null; $toGuestId = null; $toNickname = null;
        if ($isDm) {
            $toUserId = (int)($opt['to_user_id'] ?? 0) ?: null;
            $toGuestId = (int)($opt['to_guest_id'] ?? 0) ?: null;
            $toNickname = mb_substr(trim((string)($opt['to_nickname'] ?? '')), 0, 40);
            if (!$toUserId && !$toGuestId) return [false, '私信缺少接收对象'];
            // v1.1.2：跨身份私聊已下线，服务端同步收口。
            // 判定不能只靠 dmPeerKey —— 构造请求可以绕过前端入口直接打 send。
            if ($toGuestId) return [false, '暂不支持与游客私聊'];
            if ($actor['kind'] !== 'user') return [false, '请先登录后再发起私聊'];
            if ($toUserId === (int)$actor['id']) return [false, '不能给自己发私信'];
            // v1.3.71：私聊必须先成为好友（对方通过你的好友请求）。
            // 口径取「当前是否好友」而不是「历史上聊过没有」—— 一律拦，删了好友
            // 连老会话也发不出去，必须重新加。只读历史不拦（见 dmList/dmPoll），
            // 否则删好友等于抹掉对方看得见的记录。
            // ⚠️ 判定必须放在服务端：构造请求可以绕过前端所有入口直接打 send。
            if (!self::areFriends((int)$actor['id'], (int)$toUserId)) {
                return [false, '你们还不是好友，先发好友请求，对方通过后才能私聊'];
            }
            $room = ['id' => 0, 'type' => 'dm'];   // 不隶属任何群聊，跳过群校验与群禁言
        } else {
            $room = self::room($roomId);
            if (!$room) return [false, '群聊不存在'];
            if (!self::roomAccessOk($room, $actor)) return [false, '无权进入该群聊'];
            // v1.2.27：注册用户必须先**加入**群聊才能发言（含公开群）。
            // 以前公开群进得来就能说，成员表因此永远只有被邀请/建群的人，
            // 「所有成员」只能拿在线心跳凑。现在进入要显式确认加入（前端弹窗）。
            // ⚠️ 游客不受此限：游客无法成为成员（身份随会话消亡），
            //    进得来就说，其发言频率由 guest_msg_interval 单独约束。
            // ⚠️ 群主/超管由 isMember 恒真放行，不受影响。
            // 返回保持 send 的三元签名 [ok, msg, id]，不加第四个元素 ——
            // 调用点统一 `[$ok,$msg,$id] = Chat::send()`，多给会踩「Undefined array key」
            if ($actor['kind'] === 'user' && !self::isMember($room, $actor)) {
                return [false, '请先加入该群聊后再发言', null];
            }
        }

        // 游客发言权限
        if ($actor['kind'] === 'guest') {
            if (DB::setting('guest_chat', '1') !== '1') return [false, '站点已禁止游客发言'];
            // v1.1.0：改为「发言间隔」而非「每日限额」。
            // 复用 rate_limits 表做通用计数（bucket=guest_gap），不新增表结构。
            // 间隔 0 = 不限制；返回剩余秒数让前端能提示「还需等 N 秒」。
            $gap = (int)DB::setting('guest_msg_interval', 30);
            if ($gap > 0 && !Sec::rateLimit('guest_gap', 'g' . $actor['id'], $gap, 1)) {
                return [false, "游客发言间隔为 $gap 秒，请稍后再试（登录账号不受此限制）"];
            }
            // v1.3.1：游客必须先「进入」群聊才能发言。
            // 旧行为是服务端把游客直接塞进列表第一个群（boot.room = 第一个），
            // 于是游客一个群都没点也能发言，前端没有可依据的「已选择」事实。
            // 判定依据取 online 心跳：只有前端真的 switchRoom（拉起轮询）才会写，
            // 构造请求直接打 send 不会有心跳记录，因此拦得住。
            // 心跳窗口 45 秒、轮询 ≤20 秒一次，这里放宽到 120 秒避免边界抖动。
            $seen = (int)DB::val('SELECT last_seen FROM online WHERE room_id=? AND guest_id=?', [$roomId, (int)$actor['id']]);
            if ($seen <= 0 || $seen < time() - 120) {
                return [false, '请先进入该群聊后再发言', null];
            }
        }
        if ($actor['kind'] === 'none') return [false, '请先登录或刷新页面'];

        // 禁言
        if ($ban = self::isBanned($actor, $roomId)) return [false, $ban];

        // 发言频率限制（数据库计数，替代 Redis）
        $win = (int)DB::setting('msg_rate_window', 10);
        $max = (int)DB::setting('msg_rate_max', 8);
        if (!Sec::rateLimit('msg', $actor['kind'] . $actor['id'], $win, $max)) {
            return [false, '发言过于频繁，请稍后再试'];
        }

        // 消息形态归一：私聊与群聊共用同一套形态（text/mention/image/file），
        // 「发到谁」由上面 $isDm 分支的 room_id/to_* 决定，两者互不干扰。
        if ($type === 'text' && preg_match('/(^|\s)@[^\s@]+/u', $content)) {
            $type = 'mention';
        } elseif (!in_array($type, ['text', 'image', 'file', 'system', 'rich'], true)) {
            $type = 'text';
        }
        if ($type === 'rich') {
            // 图文混排（v1.3.86）：一条消息里文字 + 最多 9 个本站图片/视频。
            // ⚠️ 敏感词只过滤**文字那一段**：整串 JSON 一起过会把替换成的 *** 写进 url，
            // 消息存进去就是一条打不开的破图 —— 先拆、过滤、再拼回去。
            [$okR, $msgR, $rText, $rMedia] = self::normalizeRich($content);
            if (!$okR) return [false, $msgR];
            self::filterText($rText, 'message', $actor);
            // 打码之后才否决：插件（垃圾信息控制）看到的是**最终**文字，
            // 判定依据与用户实际会看到的內容一致。
            if (($why = self::checkText($rText, 'message', $actor)) !== null) return [false, $why];
            $content = json_encode(['text' => $rText, 'media' => $rMedia], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif ($type === 'file') {
            // 文件消息 content 是 JSON：只允许 name/size/ext/path 四个字段，
            // 且 path 必须来自上传接口返回（Upload::fileAbs 会再校验一次）
            $info = json_decode($content, true);
            if (!is_array($info) || !Upload::fileAbs((string)($info['path'] ?? ''))) {
                return [false, '文件信息无效'];
            }
            $content = json_encode([
                'name' => mb_substr(preg_replace('/[\\\\\/\x00-\x1F\x7F]/u', '', (string)($info['name'] ?? 'file')), 0, 120),
                'size' => (int)($info['size'] ?? 0),
                'ext'  => strtolower(preg_replace('/[^a-z0-9]/i', '', (string)($info['ext'] ?? ''))),
                'path' => (string)$info['path'],
            ], JSON_UNESCAPED_UNICODE);
        } elseif ($type !== 'image') {
            $content = trim($content);
            if ($content === '') return [false, '消息不能为空'];
            if (mb_strlen($content) > 2000) return [false, '消息过长（最多 2000 字）'];
            self::filterText($content, 'message', $actor);
            if (($why = self::checkText($content, 'message', $actor)) !== null) return [false, $why];
        }

        // 引用快照：前端传 {nick,text}，服务端只保留两个字段并截断，避免塞入任意结构
        $quote = '';
        if ($type !== 'system' && !empty($opt['quote'])) {
            $q = is_array($opt['quote']) ? $opt['quote'] : json_decode((string)$opt['quote'], true);
            if (is_array($q)) {
                $qn = trim((string)($q['nick'] ?? ''));
                $qt = trim((string)($q['text'] ?? ''));
                if ($qn !== '' || $qt !== '') {
                    // id：被引用消息的 ID，供前端「点击引用跳转到原消息」
                    $quote = json_encode([
                        'nick' => mb_substr($qn, 0, 40),
                        'text' => mb_substr($qt, 0, 120),
                        'id' => (int)($q['id'] ?? 0),
                    ], JSON_UNESCAPED_UNICODE);
                }
            }
        }
        // 钩子：可改写引用内容（如脱敏、追加上下文），或直接清空以禁用该条引用
        if ($quote !== '') Plugin::fire('message.quote', [&$quote, $content, $actor, $roomId]);

        Plugin::fire('message.before_send', [&$content, $actor, $roomId]);

        $id = DB::insert('messages', [
            'room_id' => $roomId,
            'user_id' => $actor['kind'] === 'user' ? $actor['id'] : null,
            'guest_id' => $actor['kind'] === 'guest' ? $actor['id'] : null,
            'nickname' => $actor['nickname'], 'role' => $actor['role'],
            'title' => $actor['title'] ?? '', 'avatar' => $actor['avatar'] ?? '',
            'type' => $type, 'content' => $content,
            'to_user_id' => $toUserId, 'to_guest_id' => $toGuestId, 'to_nickname' => $toNickname,
            'quote' => $quote,
            // v1.1.0 新增：deleted 默认 0（新增列的 DEFAULT 已兜底，这里显式写清语义）
            'recalled' => 0, 'deleted' => 0, 'deleted_at' => 0, 'deleted_by' => '',
            'ip' => Sec::ip(), 'created_at' => time(),
        ]);
        // v1.1.0：游客每日计数（daily_count）已随「每日限额」下线而废弃，
        // 改为在 Sec::rateLimit('guest_gap', …) 里按间隔限流，无需再更新 guests 表。
        // v1.2.42：追加 $content / $type / $toUserId —— 等级信任插件要做「有效发言判定」
        // （长度、纯表情、纯链接、内容去重），只有 id 就得回查一次 messages，
        // 每条消息多一发 SQL 不划算。参数是**追加**，老插件的多余形参不受影响。
        Plugin::fire('message.after_send', [$id, $actor, $roomId, $content, $type, $toUserId]);
        // v1.3.23：返回**完整消息行**（pack 后的形态），让前端能立即本地回显。
        // 为什么必须由服务端给：消息行里有十几处派生字段 —— 时间戳、role、
        // 头像最终 URL、引用快照、被敏感词插件替换过的正文（text.filter 钩子
        // 可能改内容）、ip 归属的 guest_id / user_id 判定……
        // 前端凭空拼一份必然与其他人收到的版本不一致（典型症状就是「自己看到的
        // 和自己收到的不一样」），而且要重新实现一遍 pack 的口径、必然漂移。
        // 这里多一次按主键回查（只一条），换来「所见即服务端所见」。
        $row = DB::one('SELECT * FROM messages WHERE id=?', [$id]);
        if (!$row) return [true, 'ok', $id];
        $avMap = self::avatarMap([$row]);
        return [true, 'ok', $id, self::pack($row, $actor, $avMap)];
    }

    // ---------- 消息序列化（含可见性过滤） ----------
    /**
     * 是否为私聊消息（v1.2.1：判据从 type='private' 改为 room_id=0 且有接收方）。
     * 所有私聊相关的 SQL 条件与权限判定都必须走这里，避免各处散落一份判断而漏掉某种形态。
     */
    public static function isDmRow(array $m): bool
    {
        return (int)($m['room_id'] ?? 0) === 0
            && ((int)($m['to_user_id'] ?? 0) > 0 || (int)($m['to_guest_id'] ?? 0) > 0);
    }

    private static function visible(array $m, array $actor): bool
    {
        if (!self::isDmRow($m)) return true;
        // v1.1.0：私聊仅双方可见（超级管理员亦不例外，后台同样不可越权查看）
        if ($actor['kind'] === 'user' && ((int)$m['user_id'] === $actor['id'] || (int)$m['to_user_id'] === $actor['id'])) return true;
        if ($actor['kind'] === 'guest' && ((int)$m['guest_id'] === $actor['id'] || (int)$m['to_guest_id'] === $actor['id'])) return true;
        return false;
    }

    /** 私聊消息对该用户是否可见（供附件下载等旁路复用，避免各处重写一遍判定） */
    public static function dmVisible(array $m, array $actor): bool
    {
        return self::visible($m, $actor);
    }

    public static function pack(array $m, array $actor, array $avatarMap = []): array
    {
        $admin = $actor['role'] === 'admin';
        $deleted = (int)($m['deleted'] ?? 0) === 1;
        // v1.3.20：**始终**按发送者**当前**头像下发，不再只补空快照。
        //
        // 为什么改成「有映射就用映射」：v1.3.12 只在快照为空时回填，于是存量那些
        // 非空但已过期的快照（比如用户换过头像、或早期版本把相对路径存进去的）
        // 会一直显示老头像 —— 表现就是「改了头像，历史消息不跟着变」。
        // 快照的保留意义只剩一种场景：**用户已注销**，avatarMap 查不到人时才回落到它，
        // 这正是下面 isset 判断的意义（有映射用映射，没映射用快照）。
        // avatarMap 是批量查询（见 avatarMap），不产生 N+1。
        $av = (string)($m['avatar'] ?? '');
        if (!empty($m['user_id']) && isset($avatarMap[(int)$m['user_id']])) {
            $av = $avatarMap[(int)$m['user_id']];
        }
        return [
            'id' => (int)$m['id'], 'room' => (int)$m['room_id'],
            'uid' => $m['user_id'] ? (int)$m['user_id'] : null,
            'gid' => $m['guest_id'] ? (int)$m['guest_id'] : null,
            'nickname' => $m['nickname'], 'role' => $m['role'],
            'title' => $m['title'] ?? '', 'avatar' => $av,
            'type' => $m['type'],
            // 软删除与撤回都清空正文：deleted 额外带 deleted 标记，
            // 前台据此显示「该消息已删除」而非「已撤回」，语义不同。
            'content' => ($m['recalled'] || $deleted) ? '' : $m['content'],
            'recalled' => (int)$m['recalled'],
            'deleted' => $deleted,
            'quote' => ($m['recalled'] || $deleted) ? null : (json_decode((string)($m['quote'] ?? ''), true) ?: null),
            'to_uid' => $m['to_user_id'] ? (int)$m['to_user_id'] : null,
            'to_gid' => $m['to_guest_id'] ? (int)$m['to_guest_id'] : null,
            'to_nickname' => $m['to_nickname'] ?? '',
            // v1.2.1：私聊标识与消息形态解耦后，前端不能再靠 m.type==='private' 判断
            // （私聊里的图片/文件消息 type 是 image/file），故单独下发 dm 布尔位。
            'dm' => self::isDmRow($m) ? 1 : 0,
            'time' => date('H:i', (int)$m['created_at']),
            'date' => date('m-d', (int)$m['created_at']),
            'ts' => (int)$m['created_at'],
            'ip' => $admin ? $m['ip'] : null,
            'mine' => ($actor['kind'] === 'user' && (int)$m['user_id'] === $actor['id'])
                  || ($actor['kind'] === 'guest' && (int)$m['guest_id'] === $actor['id']),
        ];
    }

    // ---------- 历史消息（向上翻页） ----------
    /**
     * 拉一段历史。v1.3.21 起支持第三种起点 $fromId（>= 该 id 起往后取）：
     * 进会话时若定位到「上次已读位置」，就用它把那一段消息取出来渲染，
     * 免得定位点落在空白处（只取了更早或更晚的消息）。
     *
     * @param int $beforeId >0 取更早的（向上翻页）；<0 取**更晚**的（定位点之后）
     * @param int $fromId  >0 时改为「>= 该 id 往后取」，忽略 beforeId
     */
    public static function history(array $actor, int $roomId, int $beforeId, int $limit = 30, int $fromId = 0): array
    {
        self::purgeExpired();   // v1.2.2：顺带清理过服务器保留期的消息 + 其附件
        self::purgeHides();     // v1.1.14：顺带清理指向已消失消息的隐藏行
        $sql = 'SELECT * FROM messages WHERE room_id=?';
        $args = [$roomId];
        if ($fromId > 0) {
            $sql .= ' AND id>=?'; $args[] = $fromId;
            // 往后取要**包含** fromId 那条，故 ORDER BY ASC（下方统一 DESC 后再翻转）
        } elseif ($beforeId > 0) { $sql .= ' AND id<?'; $args[] = $beforeId; }
        $asc = $fromId > 0;      // 往后取是「从早到晚」，与前面的 DESC 相反
        $sql .= ' ORDER BY id ' . ($asc ? 'ASC' : 'DESC') . ' LIMIT ' . max(1, min(100, $limit));
        $rows = DB::all($sql, $args);
        // ⚠️ 只有 DESC 那条路需要翻转（原本是为「取最新 N 条」倒着取再翻正）。
        //   ASC 已经是升序，再翻一次就变回倒序 —— 前端 appendMessage 会把
        //   消息按这个顺序**追加到末尾**，结果时间线是反的。
        if (!$asc) $rows = array_reverse($rows);
        $hidden = self::hiddenIds($actor);
        $avMap = self::avatarMap($rows);   // v1.3.20：统一按当前头像下发（见 pack）
        $out = [];
        foreach ($rows as $m) {
            if (isset($hidden[(int)$m['id']])) continue;   // v1.1.14：仅自己隐藏的，不下发
            if (self::visible($m, $actor)) $out[] = self::pack($m, $actor, $avMap);
        }
        return $out;
    }

    // ---------- 「仅自己隐藏」的消息（v1.1.14） ----------

    /**
     * 当前身份已隐藏的消息 ID 集合（message_hides.user_id = 我的那批）。
     *
     * 游客不查表：游客身份随浏览器会话消亡、同一昵称背后可能是任意多个人，
     * 往表里写「谁不想看哪条」既无法审计也会留下无主垃圾行。游客直接返回空集，
     * 即「隐藏」对游客退化为无效操作（前端也不给该入口）。
     *
     * @return array<int,bool>  message_id => true
     */
    public static function hiddenIds(array $actor): array
    {
        if (($actor['kind'] ?? '') !== 'user') return [];
        $uid = (int)($actor['id'] ?? 0);
        if ($uid <= 0) return [];
        $out = [];
        foreach (DB::all('SELECT message_id FROM message_hides WHERE user_id=?', [$uid]) as $r) {
            $out[(int)$r['message_id']] = true;
        }
        return $out;
    }

    /**
     * 把一条消息加入「仅本机不再看到」（幂等）。这就是「删除」的全部实现。
     * 只允许注册用户调用；不校验房间权限 —— 这是**纯个人视图**行为，
     * 看不见的消息自然也不会出现在自己的列表里，拦不拦没有实际区别。
     *
     * v1.2.4：删除**一律**走这里，超管与群主也不例外（谁都不能用删除让别人看不到）。
     * 真要全局生效只有撤回（recall()，物理删行）。
     *
     * 表名与函数名仍沿用 hide / message_hides —— 它们准确描述内部机制，
     * 不为了对齐用户文案去改表名（那是无谓的迁移风险）。
     * 返回文案刻意带上「仅本机」：否则用户会以为所有人都看不到了，那是欺骗。
     */
    public static function hideMessage(array $actor, int $msgId): array
    {
        if (($actor['kind'] ?? '') !== 'user') return [false, '请先登录后再使用该功能'];
        $m = DB::one('SELECT id, room_id FROM messages WHERE id=?', [$msgId]);
        if (!$m) return [false, '消息不存在'];
        $tip = '已删除（仅本机不再看到这条消息，其他人不受影响）';
        if ((int)DB::val('SELECT 1 FROM message_hides WHERE user_id=? AND message_id=?',
                [(int)$actor['id'], $msgId]) > 0) {
            return [true, $tip];
        }
        DB::insert('message_hides', [
            'user_id' => (int)$actor['id'], 'message_id' => $msgId, 'created_at' => time(),
        ]);
        Sec::log('msg_hide', (string)$msgId, [
            'room' => (int)$m['room_id'],
            'by'   => (string)($actor['kind'] ?? ''),
        ]);
        return [true, $tip];
    }

    // ---------- 长轮询（主通道，零依赖替代 WebSocket） ----------
    public static function poll(array $actor, int $roomId, int $sinceId, int $timeout = 20): array
    {
        self::heartbeat($actor, $roomId);
        $deadline = time() + max(5, min(30, $timeout));
        $new = [];
        $hidden = self::hiddenIds($actor);
        while (time() < $deadline) {
            $rows = DB::all('SELECT * FROM messages WHERE room_id=? AND id>? ORDER BY id LIMIT 200', [$roomId, $sinceId]);
            if ($rows) {
                $avMap = self::avatarMap($rows);   // v1.3.20：与 history 同一口径（见 pack）
                foreach ($rows as $m) {
                    // v1.1.14：隐藏的消息不下发，但仍要推进 sinceId，
                    // 否则这条会被下一次轮询重新捞出来反复判断。
                    if (!isset($hidden[(int)$m['id']]) && self::visible($m, $actor)) $new[] = self::pack($m, $actor, $avMap);
                    $sinceId = max($sinceId, (int)$m['id']);
                }
                break;
            }
            Plugin::cronTick();
            usleep(500000); // 0.5s
            if (connection_aborted()) exit;
        }
        // v1.2.27：「所有成员」改为「加入的成员全量 + 在场游客」，随 poll 一起下发，
        // 前端不必再单独维护一份名单。roomId=0（私聊）不查，私聊没有成员概念。
        $mem = ['members' => [], 'guests' => []];
        if ($roomId > 0) {
            $r = self::room($roomId);
            if ($r) $mem = self::allMembers($r, $actor);
        }
        return [
            'since' => $sinceId,
            'messages' => $new,
            'online' => self::onlineList($roomId),      // 保留：插件/旧逻辑可能用
            'members' => $mem['members'],
            'guests' => $mem['guests'],
            // v1.1.1：成员在线状态仅超级管理员与群主可见（前端据此决定是否画在线点）。
            // 名单本身对所有人可见，裁剪只发生在「在线/离线」这一层。
            'online_status' => self::canSeeOnlineStatus($actor, $roomId),
            'server_time' => time(),
        ];
    }

    /**
     * 成员在线状态的可见权限（v1.1.1）。
     *
     * 口径：超级管理员 + 群主可见；普通会员与游客一律不可见。
     * 与公告插件的 $oaCanManage 同源（admin 直接放行 + owner_id 命中），
     * 避免出现「前端藏起点、后端仍能查到」的口径分裂。
     */
    public static function canSeeOnlineStatus(array $actor, int $roomId): bool
    {
        if (($actor['role'] ?? '') === 'admin') return true;
        if (($actor['kind'] ?? '') !== 'user') return false;
        $owner = (int)(DB::val('SELECT owner_id FROM rooms WHERE id=?', [$roomId]) ?: 0);
        return $owner !== 0 && $owner === (int)$actor['id'];
    }

    // ---------- 私聊会话（v1.1.0） ----------
    // 存储约定：私聊消息 room_id=0（虚拟私聊空间），to_user_id / to_guest_id 标识接收方；
    // 可见性由 visible() 收口为「仅双方」，超管亦不可越权。会话列表 = 群聊 + 私聊按最后活跃时间倒序。

    /**
     * 解析对方标识：'user:12' / 'guest:34' → [kind, id]
     *
     * v1.1.2：**只允许注册用户之间私聊**，跨身份（user↔guest）一律拒绝。
     * 游客会话生命周期极短（浏览器一关即失效），且发言可匿名顶替，
     * 允许注册用户去私聊一个「随时可能是另一个人」的游客既不可靠也不可审计，
     * 故从协议层彻底关闭。前端入口同步收敛（chat.js showUserMenu / openDmWith / pm）。
     */
    public static function dmPeerKey(array $actor, string $peer): ?array
    {
        if (!preg_match('/^(user|guest):(\d{1,10})$/', trim($peer), $m)) return null;
        $kind = $m[1];
        $id = (int)$m[2];
        if ($id <= 0) return null;
        // 不能与自己私聊
        if ($kind === $actor['kind'] && $id === (int)$actor['id']) return null;
        // v1.1.2：跨身份私聊已下线 —— 双方都必须是注册用户
        if ($kind !== 'user' || $actor['kind'] !== 'user') return null;
        return [$kind, $id];
    }

    /** 对方资料（用于会话头与私聊页标题） */
    public static function dmPeerInfo(string $kind, int $id): array
    {
        if ($kind === 'user') {
            $u = DB::one('SELECT id, nickname, avatar, avatar_type, avatar_style, avatar_seed FROM users WHERE id=?', [$id]);
            return $u ? ['kind' => 'user', 'id' => (int)$u['id'], 'name' => (string)$u['nickname'],
                'avatar' => Auth::avatarUrlFor($u, (string)$id)] : [];
        }
        // v1.3.14：与 actor() 同口径 —— 游客头像取 guests 表的风格与档位
        $g = DB::one('SELECT id, nickname, client_key, avatar_type, avatar_style, avatar_seed FROM guests WHERE id=?', [$id]);
        return $g ? ['kind' => 'guest', 'id' => (int)$g['id'], 'name' => '游客' . substr((string)$g['nickname'], 2),
            'avatar' => Auth::avatarUrlFor($g, (string)($g['client_key'] ?? 'guest'))] : [];
    }

    // ---------- 会话置顶（v1.2.28，**按用户**生效） ----------

    /** 会话唯一键：私聊 'dm:user:20'；群聊 'room:5'（预留，前端暂只开放私聊） */
    public static function convKey(string $conv, string $peer, int $id): string
    {
        return $conv === 'dm' ? ('dm:' . $peer) : ('room:' . $id);
    }

    /** 我置顶过的会话键集合（peer_key => true）。游客没有个人设置，不置顶。 */
    public static function pinnedKeys(array $actor): array
    {
        if (($actor['kind'] ?? '') !== 'user') return [];
        $uid = (int)($actor['id'] ?? 0);
        if ($uid <= 0) return [];
        $set = [];
        foreach (DB::all('SELECT peer_key FROM conversation_pins WHERE user_id=?', [$uid]) as $r) {
            $set[(string)$r['peer_key']] = true;
        }
        return $set;
    }

    // ---------- 已读位点 / 未读数（v1.3.19） ----------

    /**
     * 取「各会话最后已读到哪条消息 id」。返回 peer_key => last_id。
     * 游客没有用户行，无法跨设备保存阅读进度 —— 直接返回空，列表不显示未读徽标
     *（否则游客一刷新就满屏红点，反而干扰）。游客的「已读」由前端按当前会话即时判断。
     */
    /**
     * @param array|null $marks 已查好的 map，传进来可避免在循环里反复查同一张表
     *                           （unreadCounts 按会话循环时用得上）
     */
    public static function readMarks(array $actor, ?array $marks = null): array
    {
        if (is_array($marks)) return $marks;
        if (($actor['kind'] ?? '') !== 'user') return [];
        $uid = (int)($actor['id'] ?? 0);
        if ($uid <= 0) return [];
        $out = [];
        foreach (DB::all('SELECT peer_key, last_id FROM conversation_reads WHERE user_id=?', [$uid]) as $r) {
            $out[(string)$r['peer_key']] = (int)$r['last_id'];
        }
        return $out;
    }

    /**
     * 标记已读（幂等）。$lastId 是「读到的最后一条消息 id」。
     *
     * ⚠️ **只增不减**：用 max(现值, 传入值) 而不是无条件 UPDATE。
     * 前端可能在「历史拉取完成」与「轮询收到新消息」两处先后调用，顺序不保证；
     * 若允许回写，会出现「较新的位点被较早的请求覆盖回去」→ 未读数回退、红点反复闪。
     */
    public static function markRead(array $actor, string $peerKey, int $lastId): array
    {
        if (($actor['kind'] ?? '') !== 'user') return [true, ''];   // 游客静默成功，别弹错
        $uid = (int)($actor['id'] ?? 0);
        if ($uid <= 0) return [true, ''];
        $peerKey = mb_substr(trim($peerKey), 0, 40);
        if ($peerKey === '') return [false, '参数错误'];
        $lastId = max(0, $lastId);
        $cur = (int)DB::val('SELECT last_id FROM conversation_reads WHERE user_id=? AND peer_key=?', [$uid, $peerKey]);
        if ($lastId <= $cur) return [true, ''];                       // 已是更靠后的位点，忽略
        if ($cur > 0) {
            DB::run('UPDATE conversation_reads SET last_id=?, updated_at=? WHERE user_id=? AND peer_key=?',
                [$lastId, time(), $uid, $peerKey]);
        } else {
            DB::run('INSERT INTO conversation_reads (user_id, peer_key, last_id, updated_at) VALUES (?,?,?,?)',
                [$uid, $peerKey, $lastId, time()]);
        }
        return [true, ''];
    }

    /**
     * 单个会话的未读条数（v1.3.21：给右下角「N 条未读 ↓」按钮用）。
     * 口径与 unreadCounts 完全一致，抽出来是为了让两处共用一套判定，避免各自漂移。
     */
    public static function unreadCountOf(array $actor, string $peerKey, ?array $marks = null): int
    {
        if (($actor['kind'] ?? '') !== 'user') return 0;
        $uid = (int)($actor['id'] ?? 0);
        if ($uid <= 0) return 0;
        $mark = (int)(self::readMarks($actor, $marks)[$peerKey] ?? 0);
        if ($mark <= 0) return 0;
        if (strpos($peerKey, 'room:') === 0) {
            $rid = (int)substr($peerKey, 5);
            if ($rid <= 0) return 0;
            return (int)DB::val(
                'SELECT COUNT(*) FROM messages
                 WHERE room_id=? AND id>? AND deleted=0 AND recalled=0
                   AND (user_id IS NULL OR user_id<>?) AND (guest_id IS NULL OR guest_id=0)',
                [$rid, $mark, $uid]
            );
        }
        if (strpos($peerKey, 'dm:') === 0) {
            $pid = (int)substr($peerKey, strrpos($peerKey, ':') + 1);
            if ($pid <= 0) return 0;
            return (int)DB::val(
                'SELECT COUNT(*) FROM messages
                 WHERE room_id=0 AND to_user_id=? AND user_id=? AND id>? AND deleted=0 AND recalled=0',
                [$uid, $pid, $mark]
            );
        }
        return 0;
    }

    /**
     * 计算每个会话的未读数（peer_key => 数量）。返回的 key 只含「有未读」的会话。
     *
     * 口径（与微信 / QQ 一致）：
     *  - **不数自己的消息** —— 自己发的没有「未读」可言；
     *  - 排除已删除 / 已撤回的；
     *  - 只数「id 大于该会话已读位点」的消息，即位点之后新到的；
     *  - **没有位点记录的会话返回 0**（历史不算未读）。理由：不然升级后第一天
     *    每个会话都是几千条红点，那是噪声而不是提醒。
     *
     * 实现：一个会话一条 COUNT 查询（带位点条件，走主键范围扫描）。
     * 会话列表本身只有几十条，这个量级完全可接受；换来的是口径准确、
     * 不需要「把所有 id > min(位点) 的行捞回 PHP 再分桶」那种易错的写法。
     */
    private static function unreadCounts(array $actor, array $readMarks, array $lastMsgId): array
    {
        if (($actor['kind'] ?? '') !== 'user') return [];
        $uid = (int)($actor['id'] ?? 0);
        if ($uid <= 0) return [];

        $out = [];
        foreach ($readMarks as $key => $mark) {
            if ((int)$mark <= 0) continue;                 // 没建立过有效位点 → 不算未读
            $lastId = (int)($lastMsgId[$key] ?? 0);
            if ($lastId <= (int)$mark) continue;            // 之后没有新消息，短路掉这次 COUNT
            // v1.3.21：计数口径统一走 unreadCountOf，避免同一套判定散在两处日后漂移。
            // 第二个参数把已查好的 $readMarks 传下去 —— 否则每个会话都会重查一次同一张表。
            $n = self::unreadCountOf($actor, (string)$key, $readMarks);
            if ($n > 0) $out[$key] = $n;
        }
        return $out;
    }

    /**
     * 「第一条未读消息」的 id —— 用于进会话时**定位到上次已读位置**（v1.3.21）。
     *
     * 口径与 unreadCounts 完全一致（不数自己发的、排除 deleted/recalled、
     * 只看位点之后），所以返回的这条一定真的没读过，定位不会落空。
     *
     * 返回 0 表示「没有未读」或「没有位点」—— 两种情况前端都该直接滚到底部。
     */
    public static function firstUnreadId(array $actor, string $peerKey): int
    {
        if (($actor['kind'] ?? '') !== 'user') return 0;
        $uid = (int)($actor['id'] ?? 0);
        if ($uid <= 0) return 0;
        $mark = (int)(self::readMarks($actor)[$peerKey] ?? 0);
        if ($mark <= 0) return 0;

        if (strpos($peerKey, 'room:') === 0) {
            $rid = (int)substr($peerKey, 5);
            if ($rid <= 0) return 0;
            return (int)DB::val(
                'SELECT MIN(id) FROM messages
                 WHERE room_id=? AND id>? AND deleted=0 AND recalled=0
                   AND (user_id IS NULL OR user_id<>?) AND (guest_id IS NULL OR guest_id=0)',
                [$rid, $mark, $uid]
            );
        }
        if (strpos($peerKey, 'dm:') === 0) {
            $pid = (int)substr($peerKey, strrpos($peerKey, ':') + 1);
            if ($pid <= 0) return 0;
            return (int)DB::val(
                'SELECT MIN(id) FROM messages
                 WHERE room_id=0 AND to_user_id=? AND user_id=? AND id>? AND deleted=0 AND recalled=0',
                [$uid, $pid, $mark]
            );
        }
        return 0;
    }

    /**
     * 切换置顶（幂等语义：按当前状态取反）。返回 [ok, msg, pinned(bool)]。
     * 只影响调用者自己的会话列表顺序，不影响对方。
     */
    public static function togglePin(array $actor, string $peerKey): array
    {
        if (($actor['kind'] ?? '') !== 'user') return [false, '游客无法置顶会话', false];
        $uid = (int)($actor['id'] ?? 0);
        if ($uid <= 0) return [false, '请先登录', false];
        $peerKey = mb_substr(trim($peerKey), 0, 40);
        if ($peerKey === '') return [false, '参数错误', false];
        $has = (int)DB::val('SELECT 1 FROM conversation_pins WHERE user_id=? AND peer_key=?', [$uid, $peerKey]) > 0;
        if ($has) {
            DB::run('DELETE FROM conversation_pins WHERE user_id=? AND peer_key=?', [$uid, $peerKey]);
            return [true, '已取消置顶', false];
        }
        DB::upsert('conversation_pins',
            ['user_id' => $uid, 'peer_key' => $peerKey, 'created_at' => time()],
            ['user_id', 'peer_key']);   // 唯一索引 idx_conv_pins_key
        return [true, '已置顶', true];
    }

    /**
     * 清空私聊的**本机视图**（v1.2.28）。
     *
     * 语义与 v1.2.4 的「删除」完全一致：只写 message_hides，**对方照常能看到**，
     * 服务器上的消息一行不删。可逆（清掉 message_hides 即可恢复），不误伤对方。
     *
     * @return array [ok, msg, 隐藏条数]
     */
    public static function clearDmForMe(array $actor, array $peer): array
    {
        if (($actor['kind'] ?? '') !== 'user') return [false, '游客没有聊天记录可清', 0];
        [$pk, $pid] = $peer;
        // 与 dmHistory 同一对 SQL 口径，保证「我发的 + 他发的」都覆盖到
        [$cond, $args] = self::dmPairSql($actor, $pk, $pid, '', []);
        $rows = DB::all("SELECT id FROM messages WHERE room_id=0 AND to_user_id > 0 AND $cond", $args);
        if (!$rows) return [true, '没有可清空的聊天记录', 0];
        $uid = (int)$actor['id'];
        $now = time();
        foreach ($rows as $r) {
            DB::upsert('message_hides',
                ['user_id' => $uid, 'message_id' => (int)$r['id'], 'created_at' => $now],
                ['user_id', 'message_id']);   // 唯一索引 idx_msg_hides_key
        }
        return [true, '已清空 ' . count($rows) . ' 条聊天记录', count($rows)];
    }

    /**
     * 会话列表：群聊 + 私聊聚合，统一按最后活跃时间倒序（无消息的群聊排最后）。
     * @return array 每个项：{conv:'room'|'dm', id, peer, name, avatar, last_at, last_text, need_password, can_edit, mine}
     */
    public static function conversations(array $actor): array
    {
        $out = [];
        $hidden = self::hiddenIds($actor);   // v1.1.14：摘要也不能漏，漏了就等于没隐藏
        $pins = self::pinnedKeys($actor);    // v1.2.28：置顶是个人偏好，随会话一起下发
        $marks = self::readMarks($actor);    // v1.3.19：已读位点（未读数的基线）

        // 群聊：各房间最后一条消息（一条 GROUP BY 取回，避免逐房间查询）
        $last = [];
        foreach (DB::all('SELECT room_id, MAX(id) AS mid, MAX(created_at) AS at FROM messages WHERE room_id>0 GROUP BY room_id') as $row) {
            $last[(int)$row['room_id']] = ['at' => (int)$row['at'], 'id' => (int)$row['mid']];
        }
        $lastText = [];
        if ($last) {
            $ids = array_values(array_map(fn($x) => $x['id'], $last));
            $q = implode(',', array_fill(0, count($ids), '?'));
            foreach (DB::all("SELECT id, type, content FROM messages WHERE id IN ($q)", $ids) as $m) {
                $lastText[(int)$m['id']] = self::convText($m);
            }
        }
        foreach (self::rooms($actor) as $r) {
            $meta = $last[$r['id']] ?? ['at' => 0, 'id' => 0];
            $key = self::convKey('room', '', (int)$r['id']);
            $out[] = [
                'conv' => 'room', 'id' => $r['id'], 'peer' => '', 'peer_key' => $key,
                'name' => $r['name'], 'avatar' => $r['avatar'],
                'last_at' => $meta['at'],
                // 末条被我隐藏过 → 摘要置空（但时间戳照旧，列表排序不受影响）
                'last_text' => isset($hidden[$meta['id']]) ? '' : ($lastText[$meta['id']] ?? ''),
                'need_password' => $r['need_password'], 'can_edit' => $r['can_edit'], 'mine' => $r['mine'],
                'pinned' => isset($pins[$key]),
                'last_id' => (int)$meta['id'],   // v1.3.19：前端标记已读时回传
            ];
        }

        // 私聊：与我有关的私聊消息按对方分组，各取最新一条。
        // v1.1.2：跨身份私聊已下线 → 游客身份没有任何私聊会话，游客分支整体跳过。
        if ($actor['kind'] !== 'user') {
            // v1.3.19：游客不显示未读徽标（无用户行存不了阅读进度，一刷新就满屏红点）。
            // 字段仍补上，保持结构一致，前端不必分支判断。
            foreach ($out as &$c) { $c['unread'] = 0; $c['last_id'] = 0; }
            unset($c);
            return self::sortConversations($out);
        }
        $mineUser = (int)$actor['id'];
        $conds = ['(user_id > 0 AND user_id = ?)', '(to_user_id > 0 AND to_user_id = ?)'];
        $args = [$mineUser, $mineUser];
        $rows = DB::all(
            'SELECT * FROM messages WHERE room_id=0 AND to_user_id > 0
             AND (' . implode(' OR ', $conds) . ')
             ORDER BY id DESC LIMIT 300',
            $args
        );
        $seen = [];
        foreach ($rows as $m) {
            if (isset($hidden[(int)$m['id']])) continue;   // v1.1.14：别拿我已隐藏的消息当会话摘要
            // 对方 = 另一方。v1.1.2 起只可能是「用户↔用户」，
            // 但库内可能残留 v1.1.0 时期写入的跨身份消息，故仍按「谁发的」两分支取对方，
            // 再用 dmPeerKey 做一次协议层校验，非法的直接跳过（不展示、也不可进入）。
            $sentByMe = (int)($m['user_id'] ?? 0) === $mineUser;
            if ($sentByMe) {
                $pKind = ((int)($m['to_user_id'] ?? 0) > 0) ? 'user' : 'guest';
                $pId = (int)(($m['to_user_id'] ?? 0) ?: ($m['to_guest_id'] ?? 0));
            } else {
                $pKind = ((int)($m['user_id'] ?? 0) > 0) ? 'user' : 'guest';
                $pId = (int)(($m['user_id'] ?? 0) ?: ($m['guest_id'] ?? 0));
            }
            if ($pId <= 0) continue;           // 接收方缺失的异常数据，直接跳过
            $key = $pKind . ':' . $pId;
            if (!self::dmPeerKey($actor, $key)) continue;   // v1.1.2：跨身份会话不展示
            if (isset($seen[$key])) continue;   // 已取到最新一条
            $seen[$key] = true;
            $info = self::dmPeerInfo($pKind, $pId);
            if (!$info) continue;              // 对方已注销
            $out[] = [
                'conv' => 'dm', 'id' => $pId, 'peer' => $key,
                'peer_key' => self::convKey('dm', $key, $pId),
                'name' => $info['name'], 'avatar' => $info['avatar'],
                'last_at' => (int)$m['created_at'], 'last_text' => self::convText($m),
                'last_id' => (int)$m['id'],       // v1.3.19：前端标记已读时回传
                'need_password' => false, 'can_edit' => false, 'mine' => false,
                'pinned' => isset($pins[self::convKey('dm', $key, $pId)]),
                // v1.2.28：右侧栏「删除好友」只在对方确实是好友时才渲染，
                // 不然点了必然被服务端拒（先查一次 friends，比让用户试错好）
                'is_friend' => (int)DB::val('SELECT 1 FROM friends WHERE user_id=? AND friend_id=?',
                    [$mineUser, $pId]) > 0,
            ];
        }
        // v1.3.19：未读数统一在这里算完再下发。lastMsgId 用各会话的末条 id
        // 做「之后有没有新消息」的快速短路，避免给每个会话都跑一次 COUNT。
        $lastMsgId = [];
        foreach ($out as $c) $lastMsgId[(string)$c['peer_key']] = (int)$c['last_id'];
        $unread = self::unreadCounts($actor, $marks, $lastMsgId);
        foreach ($out as &$c) $c['unread'] = (int)($unread[(string)$c['peer_key']] ?? 0);
        unset($c);

        return self::sortConversations($out);
    }

    /**
     * 在**本群成员**中按关键词搜索（v1.3.24：右侧「所有成员」区的搜索弹窗）。
     *
     * 为什么不用通用 Chat::search：那个是全站搜索（可搜消息、可搜跨群的人），
     * 这里的语义是「看看这个群里有哪些人」—— 限定注册用户，
     * 结果集天然小、也不必担心搜到无关的人。
     *
     * v1.3.27：只返回**注册用户**，游客不可被搜。游客没有持久身份、
     * 不在成员表（见 joinRoom），此前「本群在场游客」一段让游客混进搜索结果，
     * 与「所有成员」列表的新口径（不含游客）也对不上。
     */
    public static function searchMembers(array $actor, int $roomId, string $q, int $limit = 30): array
    {
        $q = trim($q);
        if ($q === '') return [];
        // 与 Chat::search 的清洗口径一致：压空白、去控制符、去 LIKE 通配符
        $q = preg_replace('/[\x00-\x1f\x7f]/u', '', $q) ?? '';
        $q = preg_replace('/\s+/u', ' ', $q) ?? '';
        $q = mb_substr($q, 0, 50);
        if ($q === '') return [];
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';

        $out = [];
        // 注册用户：全站按昵称 / 数字 ID 搜（不限是否在群内 —— 群里的人可能还没加入成员表）
        $idNum = ctype_digit($q) ? (int)$q : 0;
        $sql = 'SELECT id, nickname, avatar, avatar_type, avatar_style, avatar_seed, role
                FROM users WHERE status=1 AND (nickname LIKE ?' . ($idNum > 0 ? ' OR id=?' : '') . ')
                ORDER BY (nickname=?) DESC, id ASC LIMIT ?';
        $args = $idNum > 0 ? [$like, $idNum, $q, $limit * 2] : [$like, $q, $limit * 2];
        foreach (DB::all($sql, $args) as $u) {
            $uid = (int)$u['id'];
            $out[] = [
                'kind' => 'user', 'uid' => $uid, 'gid' => 0,
                'nickname' => (string)$u['nickname'], 'role' => (string)$u['role'],
                'avatar' => Auth::avatarUrlFor($u, (string)$uid),
                // 是否已在本群（用于结果行上打「已在群」标记）
                'in_room' => (int)DB::val('SELECT 1 FROM room_members WHERE room_id=? AND user_id=?', [$roomId, $uid]) > 0,
            ];
            if (count($out) >= $limit) return $out;
        }
        return $out;
    }

    // ---------- 搜索（v1.2.31） ----------

    /**
     * 清洗搜索关键词（v1.2.32）。
     *  ① 去掉控制字符与 SQL/HTML 危险符号（参数已用占位符绑定，这里是第二道防线，
     *     防止 LIKE 通配符被用户滥用成全表扫）；
     *  ② 连续空白压成一个空格；
     *  ③ 长度上限 50（与 UI 输入框 maxlength 一致）。
     * @return array [清洗后的词, 是否为纯数字ID]
     */
    public static function cleanSearchKey(string $q): array
    {
        $q = (string)$q;
        // 去掉控制字符、HTML/SQL 符号、反斜杠；保留中文、字母、数字、空格与 @ . - _
        $q = preg_replace('/[\x00-\x1F\x7F<>"\x27`\\\\\/%&|;=$()\[\]{}*?!#~^,]/u', '', $q);
        $q = trim(preg_replace('/\s+/u', ' ', (string)$q));
        if (mb_strlen($q) > 50) $q = mb_substr($q, 0, 50);
        return [$q, (bool)preg_match('/^\d{1,10}$/', $q)];
    }

    /**
     * 可被搜索的消息形态（v1.3.93）。
     *
     * 为什么 file / rich 也进来：这两类的**文件名**就存在 content 的 JSON 里，
     * 而用户搜的正是「上周那份 xxx 报告」—— 只搜正文等于搜不到。
     * rich 顺带补上了混排消息的文字段（v1.3.86 起它一直是搜索盲区）。
     * image 不进：它的 content 是哈希命名的 URL，按关键词永远搜不出来。
     *
     * ⚠️ file/rich 是拿整段 content 做 LIKE，所以路径里的哈希也可能命中。
     *    这是有意的取舍：宁可多算，也不为了精确取 `$.name` 去按驱动分支 ——
     *    MySQL 的 JSON_EXTRACT 支持 `[*]` 通配、SQLite 的 json_extract 不支持、
     *    PostgreSQL 又是一套 `->>`，为一个字段换三份 SQL 不值。
     */
    const SEARCH_TYPES = "type IN ('text','mention','file','rich')";

    /**
     * 统一搜索入口，四个范围：
     *   current  当前会话内的消息（群聊传 room_id，私聊传 peer）
     *   people   按昵称/ID 找人 + 按名称/ID 找群
     *   messages 全站消息（**只搜自己有权看的**：能进的群 + 涉及自己的私聊）
     *   friends  联系人（按昵称/ID）
     *
     * 返回项统一形状：{type:'user'|'room'|'msg', ...}，前端按 type 分发点击行为。
     * msg 额外带 room_id（0 = 私聊）、peer（私聊对象键）、from（发送者昵称）。
     *
     * @param array $opt ['room_id'=>int, 'peer'=>string]
     */
    public static function search(array $actor, string $scope, string $q, array $opt = []): array
    {
        [$q, $isId] = self::cleanSearchKey($q);
        if ($q === '') return [];
        $like = '%' . $q . '%';
        $idNum = $isId ? (int)$q : 0;

        switch ($scope) {
            case 'people':
                $out = [];
                // 找人：昵称模糊 **或** ID 精确（v1.2.32 支持直接输 ID 找人）；排除自己
                $sql = 'SELECT id, nickname, avatar, avatar_type, avatar_style, avatar_seed, role FROM users
                        WHERE status=1 AND (nickname LIKE ?' . ($idNum > 0 ? ' OR id=?' : '') . ')
                          AND id<>? ORDER BY nickname LIMIT 20';
                $args = $idNum > 0 ? [$like, $idNum, (int)($actor['id'] ?? 0)] : [$like, (int)($actor['id'] ?? 0)];
                foreach (DB::all($sql, $args) as $u) {
                    $out[] = ['type' => 'user', 'user_id' => (int)$u['id'], 'nickname' => (string)$u['nickname'],
                        'avatar' => Auth::avatarUrlFor($u, (string)$u['id']), 'role' => (string)$u['role']];
                }
                // 找群：名称模糊 **或** ID 精确；仍走 rooms() 过 canEnter（游客看不到的不给）
                foreach (self::rooms($actor) as $r) {
                    $hit = $idNum > 0 && (int)$r['id'] === $idNum;
                    if (!$hit && mb_strpos((string)$r['name'], $q) === false) continue;
                    $out[] = ['type' => 'room', 'room_id' => (int)$r['id'], 'name' => (string)$r['name'],
                        'avatar' => (string)($r['avatar'] ?? ''),
                        'need_password' => !empty($r['need_password']), 'is_public' => !empty($r['is_public'])];
                    if (count($out) >= 30) break;
                }
                return $out;

            case 'friends':
                if (($actor['kind'] ?? '') !== 'user') return [];
                $out = [];
                // 好友：昵称模糊 **或** ID 精确（v1.2.32）
                $sql = 'SELECT u.id, u.nickname, u.avatar, u.avatar_type, u.avatar_style, u.avatar_seed, u.role FROM friends f
                        JOIN users u ON u.id = CASE WHEN f.user_id = ? THEN f.friend_id ELSE f.user_id END
                        WHERE (f.user_id=? OR f.friend_id=?) AND u.status=1 AND (u.nickname LIKE ?' . ($idNum > 0 ? ' OR u.id=?' : '') . ')
                        GROUP BY u.id, u.nickname, u.avatar, u.avatar_type, u.avatar_style, u.avatar_seed, u.role
                        ORDER BY u.nickname LIMIT 30';
                $args = $idNum > 0
                    ? [(int)$actor['id'], (int)$actor['id'], (int)$actor['id'], $like, $idNum]
                    : [(int)$actor['id'], (int)$actor['id'], (int)$actor['id'], $like];
                foreach (DB::all($sql, $args) as $u) {
                    $out[] = ['type' => 'user', 'user_id' => (int)$u['id'], 'nickname' => (string)$u['nickname'],
                        'avatar' => Auth::avatarUrlFor($u, (string)$u['id']), 'role' => (string)$u['role'], 'is_friend' => true];
                }
                // 好友补签名（插件）：signature 未启用时不阻断，与联系人列表同一口径
                if ($out) {
                    $ids = array_map(fn($x) => (int)$x['user_id'], $out);
                    $q = implode(',', array_fill(0, count($ids), '?'));
                    try {
                        foreach (DB::all("SELECT user_id, signature FROM plugin_signature WHERE user_id IN ($q)", $ids) as $sg) {
                            $sid = (int)$sg['user_id'];
                            for ($i2 = 0; $i2 < count($out); $i2++) {
                                if ((int)$out[$i2]['user_id'] === $sid) $out[$i2]['signature'] = (string)$sg['signature'];
                            }
                        }
                    } catch (Throwable $e) { /* 表不存在（插件未启用）：忽略 */ }
                }
                return $out;

            case 'messages':
                $out = [];
                // 群消息：只搜「我能进的群」——用 rooms() 拿到的 id 集合，避免搜到进不去的群
                $roomIds = array_map(fn($r) => (int)$r['id'], self::rooms($actor));
                if ($roomIds) {
                    $in = implode(',', array_fill(0, count($roomIds), '?'));
                    $args = array_merge($roomIds, [$like]);
                    $rows = DB::all(
                        "SELECT id, room_id, user_id, guest_id, nickname, type, content, created_at
                         FROM messages WHERE room_id IN ($in) AND " . self::SEARCH_TYPES . "
                         AND content LIKE ? ORDER BY id DESC LIMIT 40", $args);
                    $hidden = self::hiddenIds($actor);
                    $avs = self::avatarMap($rows);
                    foreach ($rows as $m) {
                        if (isset($hidden[(int)$m['id']])) continue;   // 我隐藏过的，别再搜出来
                        $out[] = self::srMsg($m, 0, '', $avs);
                    }
                }
                // 私聊消息：只搜涉及我的
                if (($actor['kind'] ?? '') === 'user') {
                    $me = (int)$actor['id'];
                    $rows = DB::all(
                        "SELECT id, room_id, user_id, guest_id, to_user_id, nickname, type, content, created_at
                         FROM messages WHERE room_id=0 AND to_user_id>0 AND " . self::SEARCH_TYPES . "
                         AND (user_id=? OR to_user_id=?) AND content LIKE ?
                         ORDER BY id DESC LIMIT 40", [$me, $me, $like]);
                    $hidden = self::hiddenIds($actor);
                    $avs2 = self::avatarMap($rows);
                    foreach ($rows as $m) {
                        if (isset($hidden[(int)$m['id']])) continue;
                        $sentByMe = (int)($m['user_id'] ?? 0) === $me;
                        $pId = $sentByMe ? (int)($m['to_user_id'] ?? 0) : (int)($m['user_id'] ?? 0);
                        if ($pId <= 0) continue;
                        $out[] = self::srMsg($m, 0, 'user:' . $pId, $avs2);
                    }
                }
                return $out;

            case 'current':
            default:
                $roomId = (int)($opt['room_id'] ?? 0);
                $peer = (string)($opt['peer'] ?? '');
                $rows = [];
                if ($roomId > 0) {
                    $room = self::room($roomId);
                    // 必须过 canEnter：否则能通过 URL/构造参数搜到进不去的群
                    if (!$room || !self::canEnter($room, $actor)) return [];
                    $rows = DB::all(
                        "SELECT id, room_id, user_id, guest_id, nickname, type, content, created_at
                         FROM messages WHERE room_id=? AND " . self::SEARCH_TYPES . "
                         AND content LIKE ? ORDER BY id DESC LIMIT 50", [$roomId, $like]);
                } elseif ($peer !== '') {
                    $pk = self::dmPeerKey($actor, $peer);
                    if (!$pk) return [];
                    [$cond, $args] = self::dmPairSql($actor, $pk[0], $pk[1], '', []);
                    $rows = DB::all(
                        "SELECT id, room_id, user_id, guest_id, nickname, type, content, created_at
                         FROM messages WHERE room_id=0 AND to_user_id>0 AND $cond
                         AND " . self::SEARCH_TYPES . " AND content LIKE ?
                         ORDER BY id DESC LIMIT 50", array_merge($args, [$like]));
                } else {
                    return [];   // 没有任何会话上下文（切到联系人标签后中间是空白的）
                }
                $hidden = self::hiddenIds($actor);
                $out = [];
                foreach ($rows as $m) {
                    if (isset($hidden[(int)$m['id']])) continue;
                    $out[] = self::srMsg($m, $roomId, $peer, self::avatarMap($rows));
                }
                return $out;
        }
    }

    /** 搜索结果里的消息项（统一形状） */
    private static function srMsg(array $m, int $roomId, string $peer, array $avatars = []): array
    {
        $text = (string)($m['content'] ?? '');
        $ty = (string)($m['type'] ?? '');
        // v1.3.93：file / rich 进了搜索，结果行就不能再抖 JSON 给用户看。
        // 摘要口径与「引用条 / 会话列表」保持一致：文件是「[文件] 名字」，
        // 混排是「文字 + [图片]」（没有文字就只剩 [图片]）。
        if ($ty === 'file' || $ty === 'rich') {
            $j = json_decode($text, true);
            if ($ty === 'file') {
                $text = '[文件] ' . (is_array($j) ? trim((string)($j['name'] ?? '')) : '');
            } else {
                $t = is_array($j) ? trim((string)($j['text'] ?? '')) : '';
                $md = is_array($j) && is_array($j['media'] ?? null) ? $j['media'] : [];
                $n = count($md);
                // 媒体文件名也要上这一行：搜「oceans.mp4」命中三条（一条当文件发、两条当混排发），
                // 而混排行只显示「哈哈哈哈哈 [图片]」时，用户看不出它凭什么被搜出来。
                $names = [];
                foreach ($md as $one) {
                    $nm = is_array($one) ? trim((string)($one['name'] ?? '')) : '';
                    if ($nm !== '') $names[] = $nm;
                }
                $text = trim(($t !== '' ? $t : '') . ($n > 0 ? ' [图片]' : ''));
                if ($names) {
                    $head = array_slice($names, 0, 3);
                    $text .= ' ' . implode('、', $head) . (count($names) > 3 ? ' 等' : '');
                }
                $text = trim($text);
            }
        }
        $snippet = mb_strlen($text) > 80 ? mb_substr($text, 0, 80) . '…' : $text;
        $uid = (int)($m['user_id'] ?? 0);
        return [
            'type' => 'msg',
            'msg_id' => (int)$m['id'],
            'room_id' => (int)($m['room_id'] ?? 0),
            'peer' => $peer,
            'from' => (string)($m['nickname'] ?? ''),
            // v1.2.37：带发送者头像（一次性批量查表传入，避免每行一次查询）。
            // 游客消息没有用户身份，$avatars 里查不到 → 前端回退字母头像。
            'avatar' => $uid > 0 ? (string)($avatars[$uid] ?? '') : Auth::avatarGeneratedUrl('', 'guest'),
            'user_id' => $uid,
            'text' => $snippet,
            'created_at' => (int)($m['created_at'] ?? 0),
        ];
    }

    /**
     * 批量取一组用户 id 的头像（user_id => **最终 URL**）。
     * 供搜索结果的消息行显示真实头像；游客没有用户 id，前端按游客头像口径回退。
     */
    private static function avatarMap(array $rows): array
    {
        $uids = [];
        foreach ($rows as $m) {
            $u = (int)($m['user_id'] ?? 0);
            if ($u > 0) $uids[$u] = true;
        }
        if (!$uids) return [];
        $ids = array_keys($uids);
        $q = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        try {
            // v1.3.11：带上生成式字段，批量换成最终 URL（避免每行一次查询）
            $rs = DB::all("SELECT id, avatar, avatar_type, avatar_style, avatar_seed FROM users WHERE id IN ($q)", $ids);
            foreach ($rs as $u) $out[(int)$u['id']] = Auth::avatarUrlFor($u, (string)$u['id']);
        } catch (Throwable $e) { /* 查询失败就退回内置几何头像 */ }
        return $out;
    }

    /** 会话排序：**置顶优先**，组内按最后活跃时间倒序；无消息（at=0）的沉底，其内按 id 升序 */
    private static function sortConversations(array $list): array
    {
        usort($list, function ($a, $b) {
            // v1.2.28：置顶的会话整体排在最前，其余相对顺序完全不变
            $pa = !empty($a['pinned']) ? 1 : 0;
            $pb = !empty($b['pinned']) ? 1 : 0;
            if ($pa !== $pb) return $pb <=> $pa;
            if ($a['last_at'] !== $b['last_at']) return $b['last_at'] <=> $a['last_at'];
            return $a['id'] <=> $b['id'];
        });
        return $list;
    }

    /** 会话列表用的一句话摘要（按类型给出可读文本） */
    private static function convText(array $m): string
    {
        $t = (string)$m['type'];
        if ($t === 'image') return '[图片]';
        if ($t === 'rich') {
            // 混排消息的摘要：有文字就用文字打头，纯媒体就退回「[图片]」。
            // 不这么处理，会话列表那一行会直接把 JSON 抖给用户看。
            $j = json_decode((string)$m['content'], true);
            $txt = trim((string)(is_array($j) ? ($j['text'] ?? '') : ''));
            $n = is_array($j) && isset($j['media']) && is_array($j['media']) ? count($j['media']) : 0;
            // 不写「×N」：整行摘要本来就是动态文字，语言包按整句精确匹配，
            // 掺进计数只会让英文界面冒出一句带中文标签的串
            return $txt !== '' ? mb_substr($txt, 0, 60) : ($n > 0 ? '[图片]' : '');
        }
        if ($t === 'file') {
            $info = json_decode((string)$m['content'], true);
            return '[文件] ' . (is_array($info) ? (string)($info['name'] ?? '') : '');
        }
        return mb_substr((string)$m['content'], 0, 60);
    }

    /** 私聊历史：仅双方可见；按 id 升序返回（向上翻页用 before_id） */
    /**
     * 私聊历史。v1.3.21 起 $fromId>0 表示「>= 该 id 往后取」（定位点之后），
     * 语义与 Chat::history 的同名参数一致。
     */
    public static function dmHistory(array $actor, array $peer, int $beforeId = 0, int $limit = 30, int $fromId = 0): array
    {
        [$pk, $pid] = $peer;
        if ($fromId > 0) {
            [$cond, $args] = self::dmPairSql($actor, $pk, $pid, 'id>=?', [$fromId]);
            $sql = "SELECT * FROM messages WHERE room_id=0 AND to_user_id > 0 AND $cond"
                 . ' ORDER BY id ASC LIMIT ' . max(1, min(50, $limit));
            $rows = DB::all($sql, $args);
        } else {
            [$cond, $args] = self::dmPairSql($actor, $pk, $pid, $beforeId > 0 ? 'id<?' : '', $beforeId > 0 ? [$beforeId] : []);
            $sql = "SELECT * FROM messages WHERE room_id=0 AND to_user_id > 0 AND $cond"
                 . ' ORDER BY id DESC LIMIT ' . max(1, min(50, $limit));
            $rows = DB::all($sql, $args);
            $rows = array_reverse($rows);           // 升序返回给前端直接追加
        }
        $hidden = self::hiddenIds($actor);      // v1.1.14：扣掉「仅自己隐藏」的
        $avMap  = self::avatarMap($rows);       // v1.3.20：与 history 同一口径（私聊此前漏了）
        $out = [];
        foreach ($rows as $m) {
            if (isset($hidden[(int)$m['id']])) continue;
            $out[] = self::pack($m, $actor, $avMap);
        }
        return $out;
    }

    /**
     * 私聊合规查阅（v1.1.0）：**唯一**允许非当事人读取私聊内容的入口。
     *
     * 背景：v1.1.0 定下「私聊仅双方可见，管理员亦不例外」这条硬约束，
     * 但部分司法辖区（如 GDPR 的合法利益 / 司法协助例外）要求数据控制者
     * 在有合法权限时必须能提供记录。两者需要共存，故：
     *
     *   1. **默认关闭**：本方法只在插件注册了 `dm.read` 钩子且回调明确放行时才可被调用；
     *      插件不存在（未安装 / 未启用）时，路由根本不存在，物理上无法查阅。
     *   2. **仅超级管理员**：`$actor['role']` 必须是 admin，member 与游客一律拒绝。
     *   3. **强制留痕**：无论是否查到内容，都写安全日志（含查询条件与命中条数）。
     *      合规场景下「没查到」本身也是需要举证的答复。
     *   4. **只读**：本方法不提供任何写路径，插件也拿不到 DB 句柄以外的东西。
     *
     * @param int    $targetId  被查阅的用户 ID（查该用户参与的所有私聊）
     * @param string $from      起始日期 Y-m-d（空 = 不限）
     * @param string $to        结束日期 Y-m-d（空 = 不限）
     * @param int    $limit     最多返回条数
     * @return array{ok:bool, msg:string, data:array, total:int}
     */
    public static function dmComplianceRead(array $actor, int $targetId, string $from = '', string $to = '', int $limit = 200): array
    {
        // 1) 仅超级管理员
        if (($actor['role'] ?? '') !== 'admin') {
            return ['ok' => false, 'msg' => '仅超级管理员可查阅', 'data' => [], 'total' => 0];
        }
        if ($targetId <= 0) return ['ok' => false, 'msg' => '请填写有效的用户 ID', 'data' => [], 'total' => 0];

        // 2) 钩子放行：未注册 dm.read 的插件（如本插件被停用）一律拒绝。
        //    class_exists 防御：某些精简部署（如仅 CLI 批处理）可能不加载 Plugin 类，
        //    此时合规能力应「不可用」而不是 fatal error。
        $allow = false; $reason = '未启用合规查阅能力';
        if (class_exists('Plugin')) {
            Plugin::fire('dm.read', [&$allow, &$reason, $actor, $targetId, $from, $to]);
        }
        if (!$allow) return ['ok' => false, 'msg' => $reason, 'data' => [], 'total' => 0];

        // 3) 组装查询：目标用户参与的所有私聊（作为发送方或接收方都要覆盖）
        $cond = "type='private' AND room_id=0 AND ((user_id > 0 AND user_id = ?) OR (to_user_id > 0 AND to_user_id = ?))";
        $args = [$targetId, $targetId];
        if ($from !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
                return ['ok' => false, 'msg' => '起始日期格式应为 YYYY-MM-DD', 'data' => [], 'total' => 0];
            }
            $cond .= ' AND created_at >= ?'; $args[] = strtotime($from . ' 00:00:00');
        }
        if ($to !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
                return ['ok' => false, 'msg' => '结束日期格式应为 YYYY-MM-DD', 'data' => [], 'total' => 0];
            }
            $cond .= ' AND created_at <= ?'; $args[] = strtotime($to . ' 23:59:59');
        }
        $limit = max(1, min(1000, $limit));
        $rows = DB::all("SELECT * FROM messages WHERE $cond ORDER BY id DESC LIMIT $limit", $args);

        // 4) 打包：按「对方」分组，标注每条的发送方与接收方，方便还原对话
        $target = DB::one('SELECT id,nickname FROM users WHERE id=?', [$targetId]);
        $data = [];
        foreach (array_reverse($rows) as $m) {
            $peerUid = (int)($m['user_id'] ?? 0) === $targetId
                ? (int)($m['to_user_id'] ?? 0) : (int)($m['user_id'] ?? 0);
            $peerNick = (string)($m['to_nickname'] ?? '');
            if ($peerUid > 0 && $peerNick === '') {
                $peerNick = (string)(DB::val('SELECT nickname FROM users WHERE id=?', [$peerUid]) ?: ('用户' . $peerUid));
            }
            $data[] = [
                'id' => (int)$m['id'],
                'from_user' => (int)($m['user_id'] ?? 0),      // 0 = 游客
                'from_nick' => (string)$m['nickname'],
                'to_user' => (int)($m['to_user_id'] ?? 0),
                'peer_uid' => $peerUid,
                'peer_nick' => $peerNick,
                'type' => (string)$m['type'],
                'deleted' => (int)($m['deleted'] ?? 0) === 1,
                'recalled' => (int)$m['recalled'] === 1,
                'content' => ((int)$m['recalled'] || (int)($m['deleted'] ?? 0) === 1) ? '' : (string)$m['content'],
                'ip' => (string)($m['ip'] ?? ''),
                'time' => date('Y-m-d H:i:s', (int)$m['created_at']),
                'ts' => (int)$m['created_at'],
            ];
        }

        // 5) 强制留痕：无论有无命中都要记（合规答复需要举证「查过但没有」）
        Sec::log('dm_compliance_read', (string)$actor['nickname'], [
            'target_id' => $targetId,
            'target_nick' => (string)($target['nickname'] ?? ''),
            'from' => $from, 'to' => $to, 'hit' => count($data),
        ]);
        return ['ok' => true, 'msg' => 'ok', 'data' => $data, 'total' => count($data)];
    }

    /** 私聊增量轮询：与 poll 同构（长挂起），返回新消息 */
    public static function dmPoll(array $actor, array $peer, int $sinceId, int $timeout = 20): array
    {
        [$pk, $pid] = $peer;
        [$cond, $args] = self::dmPairSql($actor, $pk, $pid, 'id>?', [$sinceId]);
        $sql = "SELECT * FROM messages WHERE room_id=0 AND to_user_id > 0 AND $cond ORDER BY id LIMIT 200";
        $deadline = time() + max(5, min(30, $timeout));
        $new = [];
        $hidden = self::hiddenIds($actor);
        while (time() < $deadline) {
            $rows = DB::all($sql, $args);
            if ($rows) {
                // ⚠️ 头像映射必须在这里建：这行原先直接引用 $avMap，而本函数从没定义它 ——
                //   PHP 8 下「未定义变量」是 warning（值 null），传给 pack() 声明为
                //   array 的第三参就是 TypeError → 整个 dm_poll 500。
                //   只在「本轮真有新私信」时触发，所以平时看不出来，一来私信就炸。
                //   与 poll()（见上）和 dmList() 同一口径：批量查，不产生 N+1。
                $avMap = self::avatarMap($rows);
                foreach ($rows as $m) {
                    if (!isset($hidden[(int)$m['id']]) && self::visible($m, $actor)) $new[] = self::pack($m, $actor, $avMap);
                    $sinceId = max($sinceId, (int)$m['id']);
                }
                break;
            }
            Plugin::cronTick();
            usleep(500000);
            if (connection_aborted()) exit;
        }
        return ['since' => $sinceId, 'messages' => $new, 'server_time' => time()];
    }

    /**
     * 私聊双方条件 + 对应参数（合一对返回，避免 SQL 与参数错位）。
     *
     * 私聊有三种组合：用户↔用户、游客↔游客、用户↔游客。消息的发送方落在
     * user_id / guest_id 之一，接收方落在 to_user_id / to_guest_id 之一，
     * 因此每条分支都要按该方向的【真实身份】选列。
     *
     * ⚠️ 两条硬约束（都是实测踩出来的，不是风格偏好）：
     * 1. 每个分支内部必须写成「发送方列 = ? AND 接收方列 = ?」，发送方列在前。
     *    若写成 `to_user_id=? AND user_id=?`（接收方在前），SQLite 在
     *    EMULATE_PREPARES=false 下会复用错位的绑定值 → 只查得到自己发出的那条。
     * 2. 「对方 → 我」这一支的发送方列必须换成对方的列（跨身份时是 guest_id ↔ user_id 互换），
     *    否则该支永远不成立。
     * 已验证：用户↔用户双向完整历史。v1.1.2 起跨身份私聊已下线，
     * dmPeerKey() 会在 dm_history / dm_poll 之前就挡掉，这里保留双列结构只是防御性兜底。
     *
     * @return array [ [cond1, args1], [cond2, args2] ]
     */
    private static function dmPairConds(array $actor, string $pk, int $pid): array
    {
        $my = (int)$actor['id'];
        $myCol = $actor['kind'] === 'user' ? 'user_id' : 'guest_id';       // 我的发送方列
        $myRecv = $actor['kind'] === 'user' ? 'to_user_id' : 'to_guest_id'; // 写给我的接收方列
        $pSend = $pk === 'user' ? 'user_id' : 'guest_id';                  // 对方的发送方列
        $pRecv = $pk === 'user' ? 'to_user_id' : 'to_guest_id';           // 我发给对方的接收方列
        return [
            [$myCol . '=? AND ' . $pRecv . '=?', [$my, $pid]],   // 我 → 对方
            [$pSend . '=? AND ' . $myRecv . '=?', [$pid, $my]],  // 对方 → 我
        ];
    }

    /**
     * 拼成完整条件与扁平参数数组。
     * tail 只接受不含占位符的附加条件；带占位符时由 tailArgs 按出现顺序追加。
     */
    private static function dmPairSql(array $actor, string $pk, int $pid, string $tail = '', array $tailArgs = []): array
    {
        $conds = self::dmPairConds($actor, $pk, $pid);
        $sql = '((' . $conds[0][0] . ') OR (' . $conds[1][0] . '))';
        $args = array_merge($conds[0][1], $conds[1][1]);
        if ($tail !== '') {
            $sql .= ' AND ' . $tail;
            $args = array_merge($args, $tailArgs);
        }
        return [$sql, $args];
    }

    // ---------- 在线状态 ----------
    public static function heartbeat(array $actor, int $roomId): void
    {
        if ($actor['kind'] === 'none') return;
        $token = $actor['kind'] === 'user' ? 'u' . $actor['id'] : 'g' . $actor['id'];
        DB::upsert('online', [
            'token' => $token,
            'user_id' => $actor['kind'] === 'user' ? $actor['id'] : null,
            'guest_id' => $actor['kind'] === 'guest' ? $actor['id'] : null,
            'nickname' => $actor['nickname'], 'role' => $actor['role'],
            'title' => $actor['title'] ?? '', 'avatar' => $actor['avatar'] ?? '',
            'room_id' => $roomId, 'ip' => Sec::ip(), 'last_seen' => time(),
        ], ['token']);
        DB::run('DELETE FROM online WHERE last_seen<?', [time() - 60]);
    }

    public static function onlineList(int $roomId): array
    {
        $rows = DB::all('SELECT * FROM online WHERE room_id=? AND last_seen>? ORDER BY role=\'admin\' DESC, last_seen DESC LIMIT 300', [$roomId, time() - 45]);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'uid' => $r['user_id'] ? (int)$r['user_id'] : null,
                'gid' => $r['guest_id'] ? (int)$r['guest_id'] : null,
                'nickname' => $r['nickname'], 'role' => $r['role'],
                'title' => $r['title'] ?? '', 'avatar' => $r['avatar'] ?? '',
            ];
        }
        return $out;
    }

    // ---------- 撤回 ----------
    /**
     * 删除消息 = **一律只在本机隐藏**，不做任何权限区分。
     *
     * v1.2.4 彻底改了语义（与 v1.1.x 相反，勿回退）：
     *   删除：仅写 message_hides，**只对当前账号/设备生效**。消息在库里、
     *         别人照常看得到、换设备登录也不生效。
     *         **包括超级管理员与群主** —— 谁都不能用「删除」让内容对别人消失。
     *   撤回：才是真正的删除，**全局生效**（见 recall()）。
     *
     * 为什么这样切分：聊天是公共空间。「删除」是**个人视图**行为 —— 任何人都有权
     * 屏蔽自己不想看的发言；但把「我不看」升格成「别人也看不到」就越权了。
     * 真要清除内容只有一条路：撤回，且撤回受时间窗口约束（见 recall）。
     *
     * @return array [bool, string, string] [是否成功, 提示文案, 'delete'|'hide']
     */
    public static function deleteMessage(array $actor, int $msgId): array
    {
        $m = DB::one('SELECT * FROM messages WHERE id=?', [$msgId]);
        if (!$m) return [false, '消息不存在', 'hide'];

        // 钩子：可拦截（如插件阻止处理某类内容）。语义是「阻止本机隐藏」，不是权限判定。
        $allow = true; $reason = '';
        Plugin::fire('msg.before_delete', [&$allow, &$reason, $m, $actor]);
        if (!$allow) return [false, $reason !== '' ? $reason : '无法删除该消息', 'hide'];

        [$ok, $msg] = self::hideMessage($actor, $msgId);
        return [$ok, $msg, 'hide'];
    }

    /**
     * 清理超过**服务器保留期**的消息（物理 DELETE，行与附件文件彻底消失）。
     *
     * v1.2.3 起**统一由 msg_retain_days 一个设置项控制**（正常消息与已删除消息同一期限）。
     * v1.2.4：撤回改为**立即物理删除**，`keep_forever` 豁免标记随之失去意义、
     * 从 SQL 中移除（撤回的消息根本不进库，不存在「被清理」的问题）。
     *
     * 保留期由系统设置 msg_retain_days 控制，0 = 永久保留。
     * 站点不提供聊天记录导出，故到期即物理清除——**无法恢复**，这是有意为之：
     * 保留期是站点对「留多久」的明确承诺，留着超期数据等于承诺落空。
     *
     * 附件处理：消息行删除前先取出 file 消息的 path 与 rich 消息里的**视频**（v1.3.86 起
     * 视频也存在 uploads/file/ 下，与文件同一份存储、同一套到期口径），删行后同步 unlink。
     * 图片/头像/贴纸不动 —— 那些可能被收藏贴纸引用，生命周期与消息不同。
     * 删除只对 uploads/file/ 下的路径生效，且经 fileAbs() 二次校验（阻断路径穿越）。
     * ⚠️ 文件按内容哈希命名，同一份物理文件可被多条消息共用；因此 unlink 前要确认
     * 没有别的消息还在引用它，否则就是删掉一条消息、抽走另一条消息的地基。
     *
     * 安全：逐条 DELETE 而非大范围 WHERE，避免一次锁表；
     * 单条失败只跳过该条（记入日志），绝不影响读消息主流程。
     */
    public static function purgeExpired(): void
    {
        static $lastRun = 0;
        $days = (int)DB::setting('msg_retain_days', 90);
        if ($days <= 0) return;                       // 0 = 永久保留
        if (time() - $lastRun < 3600) return;         // 每小时最多跑一次
        $lastRun = time();
        $before = time() - $days * 86400;
        try {
            // 时间基准统一用 created_at（消息本身的年龄），不用 deleted_at ——
            // 否则「刚删掉一条很旧的消息」会因 deleted_at 很新而多留一轮，语义不统一。
            $rows = DB::all(
                'SELECT id, type, content FROM messages
                 WHERE created_at < ? ORDER BY id LIMIT 500',
                [$before]
            );
            if (!$rows) return;

            $msgs = 0; $files = 0; $paths = [];
            foreach ($rows as $m) {
                $ty = (string)$m['type'];
                // 先收集附件路径：删行之后 content 就没了，必须在删除前提取。
                if ($ty === 'file') {
                    $info = json_decode((string)$m['content'], true);
                    if (is_array($info) && !empty($info['path'])) $paths[] = (string)$info['path'];
                } elseif ($ty === 'rich') {
                    // v1.3.86：混排消息里的**视频**也存在 uploads/file/ 下，所以它跟着这条消息一起到期。
                    // 图片不在这里收 —— uploads/image/ 本来就只随记录留、不进磁盘清理
                    // （image 消息今天也是这样），别在这一处顺手改图片的保留口径。
                    $j = json_decode((string)$m['content'], true);
                    $md = is_array($j) && is_array($j['media'] ?? null) ? $j['media'] : [];
                    foreach ($md as $one) {
                        $u = (string)(is_array($one) ? ($one['url'] ?? '') : '');
                        if ((string)(is_array($one) ? ($one['kind'] ?? '') : '') === 'video' && strpos($u, 'uploads/file/') === 0) {
                            $paths[] = substr($u, strlen('uploads/'));
                        }
                    }
                }
                if (DB::run('DELETE FROM messages WHERE id=?', [(int)$m['id']])) $msgs++;
            }

            // 附件文件同步清理：只删 uploads/file/ 下、经 fileAbs 二次校验的路径。
            // 校验失败就跳过（宁可留垃圾文件，也不能让一条脏数据指向 uploads 外的文件）。
            //
            // ⚠️ 删之前必须问一句「还有别的消息在用这个文件吗」：文件按内容哈希命名，
            // 同一段视频可能被 A 当**文件**发、又被 B 塞进**混排消息**（还可能是重发的同图）。
            // 刚删掉的这条已经不在计数里，所以查得到就说明另有活消息 —— 那时只删记录、留文件。
            // 少了这一问，90 天到期就会把别人消息里的视频抽走（且不可恢复）。
            // 一次查一批而不是每个文件扫一遍全表：一轮最多 500 个待删路径，
            // 逐条 LIKE 就是 500 次全表扫描，这个函数是在页面请求里顺带跑的。
            $alive = [];
            $stems = [];
            $stemOf = [];
            foreach (array_unique($paths) as $rel) {
                $s = (string)preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', basename($rel));
                if ($s !== '' && preg_match('/^[A-Za-z0-9_\-]{6,80}$/', $s)) {
                    $stemOf[$rel] = $s;
                    $stems[$s] = 1;
                }
            }
            if (!empty($stems)) {
                foreach (array_chunk(array_keys($stems), 50) as $chunk) {
                    $ors = []; $binds = [];
                    foreach ($chunk as $s) { $ors[] = 'content LIKE ?'; $binds[] = '%/' . $s . '.%'; }
                    // 斜杠在 JSON 里可能写成 \/，所以匹配串带着前导 / 也照样命中（\/xxx.mp4 含 /xxx.mp4）
                    foreach (DB::all("SELECT content FROM messages WHERE type IN ('file','rich') AND ("
                        . implode(' OR ', $ors) . ')', $binds) as $r) {
                        $c = (string)$r['content'];
                        foreach ($chunk as $s) { if (strpos($c, '/' . $s . '.') !== false) $alive[$s] = 1; }
                    }
                }
            }
            foreach (array_unique($paths) as $rel) {
                if (isset($alive[$stemOf[$rel] ?? ''])) continue;
                $abs = Upload::fileAbs($rel);
                if ($abs && @unlink($abs)) $files++;
            }

            if ($msgs > 0 || $files > 0) {
                Sec::log('msg_expire', 'system', ['msgs' => $msgs, 'files' => $files, 'retain_days' => $days]);
            }
        } catch (Throwable $e) {
            // 清理失败绝不能影响正常读消息，静默即可
        }
    }

    /**
     * 前台编辑群聊信息（列表 ⋮ 菜单）：群名称 / 简介 / 头像。
     * 权限：管理员或房主。slug、类型、密码等管理性字段不在前台开放。
     *
     * @return array [bool, string]
     */
    public static function updateRoom(array $actor, int $roomId, string $name, string $description, string $avatar = '', ?int $isPublic = null, array $opt = []): array
    {
        $room = self::room($roomId);
        if (!$room) return [false, '群聊不存在'];
        // v1.0.78 起前台仅房主可编辑（超级管理员在后台只做审核，不再代改群聊信息）
        $isOwner = $actor['kind'] === 'user' && (int)$room['owner_id'] === (int)$actor['id'];
        if (!$isOwner) return [false, '仅群主可编辑群聊信息'];

        $name = trim($name);
        if (mb_strlen($name) < 1 || mb_strlen($name) > 30) return [false, '群名称需 1-30 个字符'];
        $description = trim($description);
        if (mb_strlen($description) > 200) return [false, '群简介不能超过 200 字'];
        // 敏感词过滤（与发言同一套词库：命中替换）
        self::filterText($name, 'room_name', $actor);
        self::filterText($description, 'room_desc', $actor);
        // 头像：仅接受本站头像目录下的相对路径（由上传接口产出），空串表示不修改。
        // 前端提交的一律要是这个形态 —— 渲染用的完整 URL 不许回传（那是 rooms() 的产物，
        // 把它写回去等于把「跟随群主头像」悄悄钉死成一张静态图，见 chat.js openRoomEdit）。
        // ⚠️ 光比前缀挡不住上跳：'uploads/avatar/../../config.php' 也是以它开头的，
        //    存进去之后 <img src> 会由浏览器解析到站点根的任意文件 —— 所以再钉一条：
        //    带 .. 的一律拒。（.tools/room-avatar-test.php ④ 就是为这条加的）
        $avatar = trim($avatar);
        if ($avatar !== '' && (strpos($avatar, 'uploads/avatar/') !== 0 || strpos($avatar, '..') !== false)) {
            return [false, '头像路径不合法'];
        }

        $sets = ['name' => $name, 'description' => $description];
        if ($avatar !== '') {
            $sets['avatar'] = $avatar;
            /* v1.3.135：上传自定义头像必须同时把 avatar_type 落成 upload。
               roomAvatarUrl() 只在 type 为 '' 或 'upload' 时才认这个路径 ——
               原先只写 avatar、不动 type，于是「先用过生成式插图、之后上传自定义头像」的群
               永远还在显示那张插图：上传成功、路径也入库了，界面上却看不出任何变化。 */
            $sets['avatar_type'] = 'upload';
        }
        // v1.3.11：生成式头像。传 avatarType=generated 时清空 avatar 路径并写风格/种子；
        //   传 restore=1 表示「回到默认」= 清空自定义 → 渲染时回落创建者头像。
        $avatarType = (string)($opt['avatar_type'] ?? '');
        if ($avatarType === 'generated') {
            $sets['avatar'] = '';
            $sets['avatar_type'] = 'generated';
            $sets['avatar_style'] = Auth::avatarStyle((string)($opt['avatar_style'] ?? ''));
            $seed = trim((string)($opt['avatar_seed'] ?? ''));
            $sets['avatar_seed'] = mb_substr($seed !== '' ? $seed : 'room-' . $roomId, 0, 40);
        } elseif ($avatarType === 'default') {
            $sets['avatar'] = '';
            $sets['avatar_type'] = '';
            $sets['avatar_style'] = '';
            $sets['avatar_seed'] = '';
        }
        // v1.1.11 公开性开关；null = 不改（兼容旧调用方，如后台）。
        // 切到「不公开」时若无邀请码，顺手生成一个，省得群主再点一次。
        if ($isPublic !== null) {
            // v1.1.14 全局总闸同样约束**改**：只拦「公开 → 不公开」这一次转换，
            // 已经是不公开的群（管理员或开关开启时建的）照常能改名、改简介，
            // 否则总闸一关，这些群主会被永久锁死在「保存不进去」的状态。
            $wasPublic = (int)($room['is_public'] ?? 1) === 1;
            if (!$isPublic && $wasPublic && $actor['role'] !== 'admin'
                && DB::setting('room_private_create_allow', '1') !== '1') {
                return [false, '站点已关闭「创建仅邀请群聊」，无法将群聊设为仅邀请'];
            }
            $sets['is_public'] = $isPublic ? 1 : 0;
            if (!$isPublic && empty($room['invite_code'])) {
                $sets['invite_code'] = self::newInviteCode(0);
            }
        }
        $up = implode(',', array_map(fn($c) => "$c=?", array_keys($sets)));
        DB::run("UPDATE rooms SET $up WHERE id=?", [...array_values($sets), $roomId]);
        Sec::log('room_update', $name, ['id' => $roomId, 'by' => $actor['role']]);
        Plugin::fire('room.after_update', [$roomId, $sets, $actor]);
        return [true, '已保存'];
    }

    /**
     * 撤回消息 = **真正的删除**，**全局生效**（所有人都不再看到），行与附件立即物理消失。
     *
     * v1.2.4 彻底改了语义（与 v1.1.x 相反，勿回退）：
     *   删除：一律只在本机隐藏（见 deleteMessage）
     *   撤回：唯一能让内容对所有人消失的操作，**立即物理删除，不可恢复**
     *
     * 撤回窗口（谁能在多长时间内撤回）：
     *   - 消息作者本人：5 分钟内（Chat::RECALL_WINDOW）
     *   - 该群群主 / 超级管理员：任何时间（处理违规内容用）
     *   - 私聊：超管不得撤回他人私聊，仅双方 5 分钟内可撤回自己发的
     *
     * 为什么撤回能改别人、删除不能：撤回是**有节制**的 —— 有时间窗、有角色限制、
     * 双方内容对称（你也撤不了我的）；删除是**无节制**的，任何人都能点。
     * 若让「删除」也全局生效，等于把「清空整个群聊」的决定权交给每个用户。
     *
     * ⚠️ 立即物理 DELETE 意味着**没有审计留痕**（谁撤的、何时都查不到）。
     * 这是刻意的取舍：撤回语义就是「这段话不该存在过」，留行反而是负担。
     * 关键操作（删除/撤回）在 security_logs 里留一条记录，供事后追溯。
     */
    public static function recall(array $actor, int $msgId): array
    {
        $m = DB::one('SELECT * FROM messages WHERE id=?', [$msgId]);
        if (!$m) return [false, '消息不存在或已撤回'];
        $mine = ($actor['kind'] === 'user' && (int)$m['user_id'] === $actor['id'])
             || ($actor['kind'] === 'guest' && (int)$m['guest_id'] === $actor['id']);

        // 群主判定（仅群聊有房主；私聊无房主概念）
        $isRoomOwner = false;
        if ($actor['kind'] === 'user' && (int)$m['room_id'] > 0) {
            $ownerId = (int)(DB::val('SELECT owner_id FROM rooms WHERE id=?', [(int)$m['room_id']]) ?: 0);
            $isRoomOwner = $ownerId !== 0 && $ownerId === (int)$actor['id'];
        }
        $isAdmin = ($actor['role'] === 'admin');

        // 私聊仅双方可见，超管不得撤回他人私聊；群聊里超管/群主可撤回任何人的违规内容
        $isModerator = $isAdmin || $isRoomOwner;
        if (self::isDmRow($m) && $isModerator) $isModerator = $mine;

        $can = $isModerator || ($mine && time() - (int)$m['created_at'] <= self::RECALL_WINDOW);
        if (!$can) return [false, '只能撤回 ' . (int)(self::RECALL_WINDOW / 60) . ' 分钟内自己发送的消息'];

        // 附件在删行**之前**先取出路径（删行后 content 就没了）
        $fileRel = '';
        if ((string)$m['type'] === 'file') {
            $info = json_decode((string)$m['content'], true);
            if (is_array($info) && !empty($info['path'])) $fileRel = (string)$info['path'];
        }

        // 真正删除：行彻底消失（不是软删除）。
        // message_hides 里的隐藏行会变成孤儿，由 purgeHides() 顺带清理。
        $st = DB::run('DELETE FROM messages WHERE id=?', [$msgId]);
        if (is_object($st) && method_exists($st, 'rowCount') && $st->rowCount() === 0) {
            return [false, '消息不存在或已撤回'];
        }

        // 附件立即物理删除：撤回 = 内容不应继续留存。
        // 只删 uploads/file/ 下、且经 fileAbs 二次校验的路径（阻断路径穿越）。
        if ($fileRel !== '') {
            $abs = Upload::fileAbs($fileRel);
            if ($abs) @unlink($abs);
        }

        Sec::log('msg_recall', (string)$msgId, [
            'room' => (int)$m['room_id'],
            'type' => (string)$m['type'],
            'by'   => (string)($actor['kind'] ?? ''),
            'mod'  => $isModerator,   // 是否为管理动作（撤他人违规内容）
        ]);
        Plugin::fire('msg.after_recall', [$msgId, $m, $actor]);
        return [true, '已撤回'];
    }

    /**
     * 清理 message_hides 里的孤儿行（v1.1.14）。
     * 消息被 purgeExpired 物理清除后，隐藏行会变成指向不存在消息的死记录。
     * 这里只清「消息已不在库」的，语义与 purgeExpired 严格区分。
     */
    public static function purgeHides(): void
    {
        static $lastRun = 0;
        if (time() - $lastRun < 3600) return;   // 每小时最多跑一次
        $lastRun = time();
        try {
            DB::run('DELETE FROM message_hides WHERE message_id NOT IN (SELECT id FROM messages)');
        } catch (Throwable $e) {
            // 清理失败绝不能影响正常读消息，静默即可
        }
    }

    // ---------- 公告 ----------
    // （v1.0.102）系统公告已剥离为 announcements 插件（群公告体系，见 plugins/announcements/），
    // 核心不再下发 announcements 字段；插件经 onRoomSwitch 钩子按群自行拉取。
}
