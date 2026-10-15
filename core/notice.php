<?php
/**
 * 系统通知（v1.3.52）—— 账号安全事件的站内留痕。
 *
 * 为什么单独一张表而不复用 rooms/messages：通知是**按用户**的隐私内容（谁改了密码、
 * 谁被封过号），塞进一个全局房间会让所有人互相看得到。
 * 也不做进邮件：邮箱可能没绑、可能收不到，站内通知是账号自己的记录，与可达性无关。
 *
 * 文案集中在这里而不是散到各调用点：一是同一事件多处触发时口径必须一致，
 * 二是 lang-en 语言包按「中文原文」精确匹配，文案只有一个出处才好维护。
 */
class Notice
{
    /** 每人最多留多少条：超出即丢最旧的，避免长期累积（不需要为此再加一个计划任务） */
    private const KEEP = 200;

    /**
     * 事件类型 → **文案模板**（v1.3.56：不再是整句死文案）。
     *
     * 为什么改成模板：禁言这类通知的正文带范围、群号、时长，拼成整句存进库之后，
     * 语言包（按中文原文精确匹配）**永远匹配不上** —— 英文界面只会看到一句中文。
     * 现在库里存 kind + 参数，模板本身是固定字符串：翻译模板，再填参数。
     *
     * ⚠️ 变体拆成独立 kind，不要用「一种句式 + 条件占位符」（如 {perm} 填「永久」或「24 小时」）：
     * 英文里语序和词形都会跟着变，条件式占位符翻译不了。所以禁言有四个完整模板。
     */
    private const TEXTS = [
        'pwd'       => '你的登录密码已修改。如果不是你本人操作，请立即再次修改密码并检查账号安全。',
        'email'     => '你的账号邮箱已修改。如果不是你本人操作，请立即联系管理员。',
        'twofa_on'  => '两步验证已开启，之后登录需要输入动态验证码。',
        'twofa_off' => '两步验证已关闭，登录不再需要动态验证码。',
        'banned'    => '你的账号已被管理员封禁，暂时无法登录与发言。',
        'unbanned'  => '你的账号已被解封，可以正常登录与发言。',
        'muted_room'      => '你在群聊 #{room_id} 被禁言 {hours} 小时。',
        'muted_room_perm' => '你在群聊 #{room_id} 被永久禁言。',
        'muted_site'      => '你的账号被全站禁言 {hours} 小时。',
        'muted_site_perm' => '你的账号被全站永久禁言。',
        // 好友请求被通过（v1.3.71）：只通知**发起请求的人**。
        // 请求到达时不通知 —— 联系人栏的小红点就是它的通知，
        // 再来一条系统通知等于同一件事提醒两遍。
        'friend_accepted' => '{nick} 已通过你的好友请求，你们现在是联系人了，可以互发私信。',
        // 在线升级的定时检查（v1.3.99）：只发给超级管理员。
        // 版本号与文件数走参数，模板本身是固定句 —— 语言包按整句翻译后再填 {v}/{n}。
        'update_available' => '仓库有新版本 {v}（{n} 个文件有变更），可到「后台 → 系统与维护 → 在线升级」查看并应用。',
        // 被拦域名的访问申诉（v1.3.106）：只发给超级管理员，与 update_available 同一类「要人来处理」的事件。
        // 域名与昵称走参数，模板保持整句，英文界面才翻得动。
        'spam_appeal' => '{nick} 申诉被拦截的域名 {domain}，申请恢复访问。请到「后台 → 插件管理 → 垃圾信息控制 → 访问申诉」查看并处理。',
    ];

    /**
     * 禁言通知：必须让用户知道**在哪个群、多久**。
     * 文案放核心而不是拼在插件里 —— 禁言有两个入口（后台添加、群聊右键），
     * 两处各写一句迟早不一致；将来解封通知也要用同样的范围措辞。
     *
     * @param int $roomId 0 = 全站禁言（只有后台能设），其余为具体群聊
     * @param int $hours  0 = 永久
     */
    public static function muted(int $userId, int $roomId, int $hours): void
    {
        if ($roomId > 0) {
            self::push($userId, $hours > 0 ? 'muted_room' : 'muted_room_perm',
                ['room_id' => $roomId, 'hours' => $hours]);
        } else {
            self::push($userId, $hours > 0 ? 'muted_site' : 'muted_site_perm', ['hours' => $hours]);
        }
    }

    /**
     * 系统通知的固定头像 = 生成式头像「小可爱（open-peeps）第 36 号」。
     * 写死风格与种子（而不是随机）：通知是长期留痕，头像必须每次都一样，
     * 否则用户回看时认不出是谁发的。种子取值范围见 Auth::AVATAR_SEED_COUNT。
     */
    private const AVATAR_STYLE = 'open-peeps';
    private const AVATAR_SEED  = '36';

    /** 通知头像 URL（侧栏那一行与消息流共用；avatar_source 设为内置时自动回落 identicon） */
    public static function avatarUrl(): string
    {
        return Auth::avatarGeneratedUrl(self::AVATAR_STYLE, self::AVATAR_SEED);
    }

    /**
     * 插件注册的「通知联系人」（v1.3.160）。实现见下面共用的 pluginRows()。
     *
     * @return array<int,array<string,mixed>> 已补好默认值、且形状合法的行（键：key/name/avatar/unread/last_text）
     */
    public static function contacts(): array
    {
        return self::pluginRows('notice.contacts');
    }

    /**
     * 插件注册的「通知消息」（v1.3.162）—— 进通知流的条目，与侧栏那一行同源。
     *
     * 通知流（?action=notices）此前只读 notices 表，插件消息没有进来的口子；
     * 侧栏行的 last_text 只够行内展示 —— 这一份收集器把插件登记的数据以「消息」的形状
     * 交给端点并进流里（正文 = last_text，另带 at 供排序）。
     *
     * 插件写法与 notice.contacts 完全一致（同一个登记函数、同一套校验与默认值），
     * 只是钩子名不同；正文写 last_text（侧栏叫「副标题」，进消息流就是正文），
     * at 写这条消息的时间（不写按 0 处理）：
     *
     *     Plugin::on('notice.items', function (callable $add) {
     *         $add(['key' => 'mykey', 'last_text' => '你的举报已处理', 'at' => time()]);
     *     });
     */
    public static function pluginItems(): array
    {
        $out = [];
        foreach (self::pluginRows('notice.items') as $r) {
            $r['body'] = $r['last_text'];   // 侧栏叫「副标题」，进消息流就是正文
            $out[] = $r;
        }
        return $out;
    }

    /**
     * 「侧栏插件行」的通用收集器（v1.3.161）—— 通知联系人（notice.contacts）与联系人行
     * （contact.rows，Chat::contactRows）共用。两个区的行形状完全一样
     * （key / name / avatar / unread / last_text），默认值规则也一样，**校验与兜底只留这一份**：
     * 抄成两处的话，改了一边忘了另一边，两个区的行为就开始漂。
     *
     * 插件写法（main.php 顶层，唯一参数是**登记函数** $add）：
     *
     *     Plugin::on('notice.contacts', function (callable $add) {
     *         $add(['key' => 'mykey', 'name' => '我的通知', 'unread' => 3, 'last_text' => '有新内容']);
     *         // 只给 key 也能跑：昵称默认取插件名、头像默认取按插件 id 派生的 open-peeps 生成头像
     *     });
     *
     * 为什么参数是「登记函数」而不是按引用传数组：登记动作发生在**插件自己的回调里**，
     * 此刻 Plugin::current() 正是它 —— 归属、默认昵称、默认头像都从这一处拿到，
     * 插件不用自报家门（写 ['plugin' => '自己'] 那种重复信息迟早会有人写错）。
     *
     * @param string $hook 钩子名（notice.contacts / contact.rows / notice.items）
     */
    public static function pluginRows(string $hook): array
    {
        $rows = [];
        $add = function (array $c) use (&$rows): void {
            if (class_exists('Plugin')) $c['__owner'] = Plugin::current();
            $rows[] = $c;
        };
        if (class_exists('Plugin')) Plugin::fire($hook, [$add]);
        if (!$rows) return [];

        // 插件名表：只给「没填昵称」的那些兜底用（名字以 plugin.json 的 name 为准）
        $names = [];
        if (class_exists('Plugin')) {
            foreach (Plugin::listAll() as $pl) $names[(string)$pl['id']] = (string)$pl['name'];
        }
        $out = [];
        $seen = [];
        foreach ($rows as $c) {
            $key = (string)($c['key'] ?? '');
            // key 会被前端拿去注册点击处理器、也会进 data-* 属性：只收一段干净短串，其余丢掉
            if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $key)) continue;
            if (isset($seen[$key])) continue;                    // 同一个 key 只认第一行
            $seen[$key] = true;
            $owner = (string)($c['__owner'] ?? '');
            $name  = trim((string)($c['name'] ?? ''));
            if ($name === '') $name = $names[$owner] ?? $owner;  // 兜底①：插件名（拿不到就退插件 id）
            $avatar = trim((string)($c['avatar'] ?? ''));
            if ($avatar === '' && $owner !== '') {
                // 兜底②：open-peeps 生成头像。种子由**插件 id** 派生（crc32 稳定），
                // 所以每个插件的默认头像固定不变 —— 同 Notice::avatarUrl 的理由：
                // 通知是长期留痕，头像每次都变的话用户认不出是谁发的。
                $seed = (string)(1 + (crc32($owner) % Auth::AVATAR_SEED_COUNT));
                $avatar = Auth::avatarGeneratedUrl('open-peeps', $seed);
            }
            $out[] = [
                'key' => $key,
                'name' => mb_substr($name, 0, 20),
                'avatar' => $avatar,
                'unread' => max(0, (int)($c['unread'] ?? 0)),
                'last_text' => mb_substr(trim((string)($c['last_text'] ?? '')), 0, 60),
                // v1.3.162：条目时间。通知流（notice.items）按它排序；侧栏行不传就是 0
                // （行内时间列渲染空串，行为与旧版一致），传了行内会显示最新一条的时间。
                'at' => max(0, (int)($c['at'] ?? 0)),
            ];
        }
        return $out;
    }

    /** 已知事件类型（后台/插件判实用；也是模板表的键集合） */
    public static function kinds(): array
    {
        return array_keys(self::TEXTS);
    }

    /** 模板表：随通知列表一起下发给前端，语言包按整条模板翻译后再填参数 */
    public static function texts(): array
    {
        return self::TEXTS;
    }

    /** 按 kind + 参数渲染中文正文（库里 body 就是这份快照） */
    public static function render(string $kind, array $params = []): string
    {
        $tpl = self::TEXTS[$kind] ?? '';
        if ($tpl === '') return '';
        $out = $tpl;
        foreach ($params as $k => $v) {
            $out = str_replace('{' . $k . '}', (string)$v, $out);
        }
        // 没给到的占位符一律抹掉：宁可少一个数字，也不要界面上露出 "{hours}"
        return preg_replace('/\{[a-z_]+\}/i', '', $out);
    }

    /**
     * 记一条通知。$kind 必须是已知类型（未知类型直接丢弃 —— 宁可不发，
     * 也不要在界面上出现一个没有图标、没有语义的孤立条目）。
     * $params 只放**渲染模板要用的取值**（room_id / hours），存 JSON 不存整句。
     * 静默失败：通知是旁路留痕，绝不能因为它写失败就让「改密码」这种主流程报错回滚。
     */
    public static function push(int $userId, string $kind, array $params = []): void
    {
        if ($userId <= 0 || !isset(self::TEXTS[$kind])) return;
        $text = self::render($kind, $params);
        try {
            DB::insert('notices', [
                'user_id' => $userId, 'kind' => $kind, 'body' => $text,
                'params'  => $params ? json_encode($params, JSON_UNESCAPED_UNICODE) : '',
                'created_at' => time(), 'read_at' => 0,
            ]);
            // 只保留最近 KEEP 条：一条 DELETE 解决，不给它配清理任务
            DB::run(
                'DELETE FROM notices WHERE user_id=? AND id NOT IN (SELECT id FROM notices WHERE user_id=? ORDER BY id DESC LIMIT ?)',
                [$userId, $userId, self::KEEP]
            );
        } catch (Throwable $e) {
            // 表还没建好（升级中途）等场景：吞掉，主流程照常
        }
    }

    /** 未读条数（rail 红点用）。游客恒为 0。 */
    public static function unread(int $userId): int
    {
        if ($userId <= 0) return 0;
        try {
            return (int)DB::val('SELECT COUNT(*) FROM notices WHERE user_id=? AND read_at=0', [$userId]);
        } catch (Throwable $e) { return 0; }
    }

    /**
     * 取最近的通知（新→旧），并把这次取到的都标为已读。
     * 「打开就全部已读」是站内通知的常规语义（没有逐条勾选的必要），
     * 也让红点在用户看过之后必然消失，不会留下清不掉的计数。
     *
     * 每行带 `params`（对象，无参数时是 `{}`）与 `body`（中文快照）：
     * 前端优先用「模板 + 参数」渲染（可翻译），拿不到模板时才回落到 body。
     */
    public static function forUser(int $userId, int $limit = 100): array
    {
        if ($userId <= 0) return [];
        $limit = max(1, min(200, $limit));
        try {
            $rows = DB::all(
                'SELECT id, kind, body, params, created_at FROM notices WHERE user_id=? ORDER BY id DESC LIMIT ?',
                [$userId, $limit]
            );
            if ($rows) DB::run('UPDATE notices SET read_at=? WHERE user_id=? AND read_at=0', [time(), $userId]);
        } catch (Throwable $e) {
            return [];
        }
        foreach ($rows as &$r) {
            $p = json_decode((string)($r['params'] ?? ''), true);
            // 必须是 JSON 对象：前端按 r.params.room_id 取值，给成数组会静默取不到
            $r['params'] = is_array($p) ? $p : new stdClass();
        }
        unset($r);
        return $rows;
    }
}
