<?php
/**
 * 时光机插件（time-machine v1.4.0）
 *
 * ── 做什么 ────────────────────────────────────────────────────
 * 类似朋友圈的动态广场。入口是 rail 上的一枚「时光机」按钮（sidebar.rail 钩子，
 * 站方指定的纸飞机字形，path 只在本文件出现一次），点开后：
 *   · 侧栏面板：简介 + 「发波文」按钮；
 *   · 主区（接管 #owMessages，与系统通知同一套接管手法）：波文时间流。
 * 一条动态叫一条「波文」：文字（≤2000 字）+ 最多 9 个附件（图片 / 文件）。
 *
 * ── 附件（v1.2.0 定案） ────────────────────────────────────────
 * 本插件**没有任何上传路由**，图片与文件全部由「附件上传」插件处理：
 *   · 图片 → plugin_attachment_manager_upload（插件内部走 Upload::handle，
 *     装了压缩开关就转 WebP，与聊天里的图片同一待遇）；
 *   · 文件 → plugin_attachment_manager_upload_file（真实 MIME 白名单 / 体积 / 配额）。
 * 没装或没启用该插件：服务端下发 attach.on=false 与安装提示，前端不给上传入口
 * （点了提示「请先安装并启用附件上传插件」）。下载走本插件自己的
 * plugin_time_machine_download：签名 + **再判一次可见性**（签名只证明请求来自站内，
 * 不证明这个人能看这条波文）。
 *
 * ── 可见性（v1.2.0） ──────────────────────────────────────────
 * public 所有人 / friends 作者的好友 / private 仅作者（管理员例外，便于管理）。
 * 过滤在 SQL 侧完成（分页不会数错），评论与附件下载各自再判一次。
 *
 * ── 能力 ──────────────────────────────────────────────────────
 *   · 分栏：最新（时间序）/ 热门（点赞序）/ 我的 / 收藏；每页 10 条，「加载更多」翻页；
 *   · 互动：点赞（每人每条一次，可取消）、收藏（可取消）、评论（≤500 字，平铺无楼中楼）、
 *     **浏览计数（v1.1.0 按访问者去重：同一人反复刷新只记一次，作者看自己的不计）**、
 *     正文一键复制；
 *   · 删除：作者本人或管理员；走敏感操作（POST + 一次性票据），连带清评论/点赞/收藏/浏览记录；
 *   · 游客：可浏览（rail 对游客也渲染，复用核心行为），所有写操作要求登录；
 *   · 内容安全：文字过 Chat::filterText / Chat::checkText（scene=message，
 *     sensitive-words 等插件照常打码/拦截）；图片只认本站 uploads/ 产物
 *     （Upload::mediaRel 复核，外链/伪造地址一律丢弃）；
 *   · 频控：Sec::rateLimit 分桶（发波文 10 分钟 5 条、评论每分钟 10 条、上传每分钟 12 张）。
 *
 * ── 不做什么 ──────────────────────────────────────────────────
 * 不做后台页（无可配置项，删除走前台卡片按钮）；不发系统通知；不接 cron
 * （数据随删除即时清理，无过期物）。
 */
if (!defined('OWLSGO_VERSION')) exit;   // 禁止直接 HTTP 访问本文件

/* ============================ 建表 ============================ */

/**
 * 四张表的 CREATE TABLE 语句（按当前驱动生成）。
 *
 * 抽成函数是为了能被 `.tools/time-machine-ddl-test.php` 在**三种驱动下各生成一遍**并断言：
 * 这类「SQLite 容忍、MySQL 1064」的方言坑本地 SQLite 永远测不出来，只能靠生成物本身对账。
 *
 * ⚠️ DB::textCol() 返回的是**完整列定义（含列名）**，前面不能再写一遍列名。
 *   本插件 v1.1.0 犯过一次：`content  {$txt}`（$txt = DB::textCol('content')）
 *   生成 `content  content TEXT NULL` —— SQLite 当成「列名 + 多词类型名」容忍了，
 *   MySQL 直接 1064。后果：owTmBoot() 建表即抛异常 → **全部路由 500**，
 *   前端表现为「网络错误（500）」且 can_post 取不到 → 点发波文提示「请先登录」
 *   （与 link-card v1.2.1 修的是同一个坑）。长文本列一律照下表的写法。
 *
 * @return array<string,string> 表名 => CREATE TABLE 语句
 */
function owTmDdl(): array
{
    $id = DB::autoId();
    return [
        'plugin_time_machine_post' => "CREATE TABLE IF NOT EXISTS plugin_time_machine_post (
        id         {$id},
        uid        INTEGER      NOT NULL DEFAULT 0,
        nickname   VARCHAR(64)  NOT NULL DEFAULT '',   -- 发送时快照（与 messages 同语义）
        " . DB::textCol('avatar') . ",
        role       VARCHAR(20)  NOT NULL DEFAULT '',
        " . DB::textCol('content') . ",
        " . DB::textCol('images') . ",                 -- JSON 数组：本站 uploads/ 的图片 URL（≤9 张）
        views      INTEGER      NOT NULL DEFAULT 0,
        likes      INTEGER      NOT NULL DEFAULT 0,
        comments   INTEGER      NOT NULL DEFAULT 0,
        created_at INTEGER      NOT NULL DEFAULT 0
    )",
        'plugin_time_machine_comment' => "CREATE TABLE IF NOT EXISTS plugin_time_machine_comment (
        id         {$id},
        post_id    INTEGER      NOT NULL DEFAULT 0,
        uid        INTEGER      NOT NULL DEFAULT 0,
        nickname   VARCHAR(64)  NOT NULL DEFAULT '',
        " . DB::textCol('avatar') . ",
        role       VARCHAR(20)  NOT NULL DEFAULT '',
        content    VARCHAR(500) NOT NULL DEFAULT '',
        " . DB::textCol('media') . ",                 -- v1.4.0 评论附件：JSON 数组（图/文件，与波文同格式）
        created_at INTEGER      NOT NULL DEFAULT 0
    )",
        'plugin_time_machine_like' => "CREATE TABLE IF NOT EXISTS plugin_time_machine_like (
        post_id    INTEGER      NOT NULL DEFAULT 0,
        uid        INTEGER      NOT NULL DEFAULT 0,
        created_at INTEGER      NOT NULL DEFAULT 0
    )",
        'plugin_time_machine_fav' => "CREATE TABLE IF NOT EXISTS plugin_time_machine_fav (
        post_id    INTEGER      NOT NULL DEFAULT 0,
        uid        INTEGER      NOT NULL DEFAULT 0,
        created_at INTEGER      NOT NULL DEFAULT 0
    )",
        'plugin_time_machine_view' => "CREATE TABLE IF NOT EXISTS plugin_time_machine_view (
        post_id    INTEGER      NOT NULL DEFAULT 0,
        viewer     VARCHAR(48)  NOT NULL DEFAULT '',   -- u<uid> / g<24位匿名摘要>
        seen_at    INTEGER      NOT NULL DEFAULT 0
    )",
    ];
}

/** 列是否存在（三驱动通用）：核心的 DB::hasColumn 是 private，插件只能自己探 */
function owTmHasColumn(string $table, string $col): bool
{
    try {
        DB::val("SELECT {$col} FROM {$table} LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** 建表 + 建索引（只在真正读写时执行） */
function owTmBoot(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $ddl = owTmDdl();
    DB::run($ddl['plugin_time_machine_post']);
    // v1.2.0 可见性列（public / friends / private）：老库升级时补一列，默认公开。
    // 核心的 DB::hasColumn / addColumn 是 private，插件够不着 → 自己探：
    // 「SELECT 该列 LIMIT 1」报错就是没有，这条写法三种驱动通用；
    // `ALTER TABLE … ADD COLUMN … NOT NULL DEFAULT 'public'` 在 SQLite / MySQL / PG 上同形。
    if (!owTmHasColumn('plugin_time_machine_post', 'visibility')) {
        DB::run("ALTER TABLE plugin_time_machine_post ADD COLUMN visibility VARCHAR(10) NOT NULL DEFAULT 'public'");
    }
    DB::createIndex('idx_otm_post_time', 'plugin_time_machine_post', 'created_at', false);
    DB::createIndex('idx_otm_post_uid', 'plugin_time_machine_post', 'uid', false);

    DB::run($ddl['plugin_time_machine_comment']);
    // v1.4.0：评论附件列（老库升级补一列）。列定义走 DB::textCol —— 它对 MySQL 退化成
    // 「TEXT NULL」（MySQL 的 TEXT 不许带 DEFAULT，会 1101），其余驱动给默认空串。
    if (!owTmHasColumn('plugin_time_machine_comment', 'media')) {
        DB::run('ALTER TABLE plugin_time_machine_comment ADD COLUMN ' . DB::textCol('media'));
    }
    DB::createIndex('idx_otm_cmt_post', 'plugin_time_machine_comment', 'post_id', false);

    DB::run($ddl['plugin_time_machine_like']);
    DB::createIndex('idx_otm_like_key', 'plugin_time_machine_like', 'post_id,uid', true);

    DB::run($ddl['plugin_time_machine_fav']);
    DB::createIndex('idx_otm_fav_key', 'plugin_time_machine_fav', 'post_id,uid', true);

    /* 浏览去重（v1.1.0）：views 只增不刷 —— 同一访问者对同一条波文只计一次。
       v1.0.0 的写法是「feed 拉到就 +1」，一次刷新加一次，轻轻松松刷成几百，
       站方指出计数不准确。现在用这张表记住「谁看过哪条」：
         · 登录用户逐账号去重（viewer = u<uid>）；
         · 游客按匿名会话键去重（Sec::anonKey() 派生，同一浏览器只算一个人）；
         · 作者看自己的波文不计（自己刷自己的浏览没有意义）。
       表不存在（从 v1.0.0 升级上来）时顺手把旧计数清零：那些数字全是刷出来的，
       保留它等于把错的数留在新逻辑里。表存在性用一次 SELECT 试探 —— 三种驱动都适用。 */
    $fresh = false;
    try { DB::val('SELECT COUNT(*) FROM plugin_time_machine_view'); }
    catch (Throwable $e) { $fresh = true; }
    DB::run($ddl['plugin_time_machine_view']);
    DB::createIndex('idx_otm_view_key', 'plugin_time_machine_view', 'post_id,viewer', true);
    if ($fresh) DB::run('UPDATE plugin_time_machine_post SET views=0');
}

/** 浏览去重用的访问者键：登录用户逐账号，游客按匿名会话（同一浏览器算一个人） */
function owTmViewerKey(array $actor): string
{
    if (($actor['kind'] ?? '') === 'user') return 'u' . (int)($actor['id'] ?? 0);
    return 'g' . substr(md5(Sec::anonKey()), 0, 24);
}

/* ============================ 行组装 ============================ */

/* ---------- 附件：清洗（入）与输出（出），波文与评论共用 ---------- */

/**
 * 清洗前端传来的附件 JSON（波文与评论同一套口径）：
 *   · image：url 必须过 Upload::mediaRel（本站 uploads/ 且文件真实存在）
 *   · file ：path 必须过 Upload::fileAbs（本站 uploads/file 下且真实存在）
 * 伪造 / 外链 / 穿越地址一律静默丢弃；上限 9 个。
 */
function owTmCleanMedia(string $json): array
{
    $media = [];
    $raw = json_decode($json, true);
    if (!is_array($raw)) return $media;
    foreach ($raw as $it) {
        if (!is_array($it)) continue;
        if ((string)($it['k'] ?? '') === 'file') {
            $path = trim((string)($it['path'] ?? ''));
            if ($path === '' || Upload::fileAbs($path) === null) continue;
            $media[] = [
                'k' => 'file',
                'path' => $path,
                'name' => mb_substr(trim((string)($it['name'] ?? 'file')), 0, 120),
                'size' => max(0, (int)($it['size'] ?? 0)),
                'ext' => strtolower(preg_replace('/[^a-z0-9]/i', '', (string)($it['ext'] ?? '')) ?: ''),
            ];
        } else {
            $url = trim((string)($it['url'] ?? ''));
            if ($url === '' || Upload::mediaRel($url) === null) continue;
            $media[] = ['k' => 'image', 'url' => $url];
        }
        if (count($media) >= 9) break;
    }
    return $media;
}

/** 库里取出的附件 JSON → 下发结构（同样逐项复核，死链静默丢弃） */
function owTmMediaOut(string $json): array
{
    $media = [];
    $raw = json_decode($json, true);
    if (!is_array($raw)) return $media;
    foreach ($raw as $it) {
        if (!is_array($it)) continue;
        if ((string)($it['k'] ?? '') === 'file') {
            $p = (string)($it['path'] ?? '');
            if (Upload::fileAbs($p) === null) continue;
            $media[] = [
                'k' => 'file',
                'path' => $p,
                'name' => mb_substr((string)($it['name'] ?? 'file'), 0, 120),
                'size' => (int)($it['size'] ?? 0),
                'ext' => strtolower((string)($it['ext'] ?? '')),
            ];
        } elseif ((string)($it['k'] ?? '') === 'image') {
            $u = (string)($it['url'] ?? '');
            if ($u === '' || Upload::mediaRel($u) === null) continue;
            $media[] = ['k' => 'image', 'url' => $u];
        } else {
            // 旧形态（v1.1.x）：元素是纯图片 URL 字符串
            $u = is_string($it) ? $it : '';
            if ($u !== '' && Upload::mediaRel($u) !== null) $media[] = ['k' => 'image', 'url' => $u];
        }
        if (count($media) >= 9) break;
    }
    return $media;
}

/**
 * post 行 → 下发结构。图片字段存的是 JSON 数组，这里解码 + 逐张复核
 * （只认本站 uploads/ 且文件确实存在；旧数据里的死链静默丢弃，不占名额）。
 */
function owTmOut(array $r): array
{
    $media = owTmMediaOut((string)($r['images'] ?? ''));
    $vis = (string)($r['visibility'] ?? '');
    if (!in_array($vis, ['public', 'friends', 'private'], true)) $vis = 'public';
    return [
        'id' => (int)$r['id'],
        'uid' => (int)$r['uid'],
        'nickname' => (string)$r['nickname'],
        'avatar' => (string)$r['avatar'],
        'role' => (string)$r['role'],
        'content' => (string)$r['content'],
        'media' => $media,
        'visibility' => $vis,
        'views' => (int)$r['views'],
        'likes' => (int)$r['likes'],
        // 计数走 comment_cnt；comments 留给 feed 填的评论数组（默认展开，随流下发）
        'comment_cnt' => (int)$r['comments'],
        'created_at' => (int)$r['created_at'],
    ];
}

/* ---------- 可见性（v1.2.0） ----------
   一条波文谁能看：public 所有人 / friends 作者的好友 / private 仅作者（管理员例外，用于管理）。
   判定只在服务端做：前端拿不到「能不能看」以外的任何信息，私密波文不会下发。             */

/** 访问者能否看这条波文（行已取到时用） */
function owTmVisOk(array $r, int $uid, bool $isAdmin): bool
{
    $vis = (string)($r['visibility'] ?? 'public');
    if ($vis === '' || $vis === 'public') return true;
    if ((int)$r['uid'] === $uid || $isAdmin) return true;
    if ($uid <= 0 || $vis === 'private') return false;
    return (int)DB::val(
        'SELECT COUNT(*) FROM friends WHERE (user_id=? AND friend_id=?) OR (user_id=? AND friend_id=?)',
        [$uid, (int)$r['uid'], (int)$r['uid'], $uid]
    ) > 0;
}

/**
 * 列表查询用的可见性条件（不带前缀的列名用 p. 引用）。
 * 参数按出现顺序追加进 $params。管理员不加限制（要能管），游客只看公开。
 */
function owTmVisCond(int $uid, bool $isAdmin, array &$params): string
{
    if ($isAdmin) return '1=1';
    $sql = "(p.visibility IS NULL OR p.visibility='' OR p.visibility='public'";
    if ($uid > 0) {
        $params[] = $uid;                                   // p.uid=?
        $params[] = $uid;                                   // f.friend_id=?
        $params[] = $uid;                                   // f.user_id=?
        $sql .= " OR p.uid=? OR (p.visibility='friends' AND EXISTS ("
              . 'SELECT 1 FROM friends f WHERE (f.user_id=p.uid AND f.friend_id=?)'
              . ' OR (f.user_id=? AND f.friend_id=p.uid)))';
    }
    return $sql . ')';
}

/* ---------- 附件能力（v1.2.0） ----------
   波文的图与文件**完全依赖附件上传插件**（attachment-manager）：
     · 图片走 plugin_attachment_manager_upload   → 插件在 Upload::handle 内压缩（WebP）
     · 文件走 plugin_attachment_manager_upload_file → 插件做 MIME 白名单 / 体积 / 配额校验
   本插件自己不再有任何上传路由：没装/没启用就明确告诉用户，并不给上传入口。 */

/**
 * 附件上传插件的开关与上限。
 *
 * ⚠️ 坑（实测踩到）：插件是**按需加载**的 —— 本次请求没碰过它，owATEnabled() 这类
 *    函数就根本不存在，`function_exists()` 判出来是「没装」，可路由其实能用
 *    （真实站点上就出现过「上传成功但界面提示未安装」）。
 * 所以分两档：函数存在就用它（口径与插件一致）；不存在就查启用清单 + 它自己的
 * 配置表 plugin_attachment_config（只读，不为问一句配置去加载整个插件）。
 */
function owTmAttachCfg(): array
{
    if (function_exists('owATConfig')) return owATConfig();
    $out = ['enabled' => '1', 'max_mb' => '10', 'exts' => 'jpg,jpeg,png,gif,webp,bmp,zip,rar,7z,pdf,txt,md,doc,docx,xls,xlsx,ppt,pptx,mp3,mp4,webm'];
    try {
        foreach (DB::all('SELECT k, v FROM plugin_attachment_config') as $r) {
            if (array_key_exists($r['k'], $out)) $out[$r['k']] = (string)$r['v'];
        }
    } catch (Throwable $e) { /* 表不存在（插件从未初始化）→ 用默认值 */ }
    return $out;
}

/** 附件上传插件是否可用（已安装 + 后台启用） */
function owTmAttachOn(): bool
{
    if (function_exists('owATEnabled')) return owATEnabled();
    $on = false;
    try {
        $on = (int)DB::val("SELECT COUNT(*) FROM plugins WHERE name='attachment-manager' AND enabled=1") > 0;
    } catch (Throwable $e) { $on = false; }
    return $on && owTmAttachCfg()['enabled'] === '1';
}

/** 给前端的附件能力描述（能不能传、上限、允许的扩展名、提示文案） */
function owTmAttachInfo(): array
{
    if (!owTmAttachOn()) {
        return [
            'on' => false,
            'hint' => '未安装或未启用「附件上传」插件，暂时不能上传图片与文件。',
            'accept' => '',
            'max_mb' => 0,
        ];
    }
    $cfg = owTmAttachCfg();
    $imgExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    // 图片由插件的图片路由收（会按后台开关压缩），其余走文件路由（白名单由后台配置）
    $fileExts = [];
    foreach (preg_split('/[\s,，;；]+/u', (string)$cfg['exts']) ?: [] as $e) {
        $e = strtolower(ltrim(trim((string)$e), '.'));
        if ($e !== '' && !in_array($e, $imgExts, true)) $fileExts[] = $e;
    }
    $all = array_values(array_unique(array_merge($imgExts, $fileExts)));
    $maxMb = max(1, (int)$cfg['max_mb']);
    return [
        'on' => true,
        'hint' => '图片与文件均由「附件上传」插件处理，单个不超过 ' . $maxMb . ' MB，最多 9 个。',
        'accept' => implode(',', array_map(fn($e) => '.' . $e, $all)),
        'max_mb' => $maxMb,
        'image_exts' => $imgExts,
    ];
}

/** 取当前访问者的点赞 / 收藏集合（一批 post_id 里我点过 / 收藏过的那些） */
function owTmMineSets(array $ids, int $uid): array
{
    $liked = [];
    $faved = [];
    if ($uid <= 0 || !$ids) return [$liked, $faved];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$uid], $ids);
    foreach (DB::all("SELECT post_id FROM plugin_time_machine_like WHERE uid=? AND post_id IN ({$ph})", $params) as $row) {
        $liked[(int)$row['post_id']] = true;
    }
    foreach (DB::all("SELECT post_id FROM plugin_time_machine_fav WHERE uid=? AND post_id IN ({$ph})", $params) as $row) {
        $faved[(int)$row['post_id']] = true;
    }
    return [$liked, $faved];
}

/**
 * 一批波文的评论（默认全展开，不需要点开才加载）。
 * 每条波文最多 50 条、按 id 正序（旧→新）；一条帖 10 次查询比一次大 IN 更好控
 * （IN 版式在多帖共用一个 LIMIT 时会把靠后的帖挤掉）。
 */
function owTmCommentsOf(array $ids): array
{
    $out = [];
    foreach ($ids as $pid) {
        $rows = DB::all(
            'SELECT id, uid, nickname, avatar, role, content, media, created_at FROM plugin_time_machine_comment'
            . ' WHERE post_id=? ORDER BY id ASC LIMIT 50',
            [(int)$pid]
        );
        foreach ($rows as &$rc) $rc['media'] = owTmMediaOut((string)($rc['media'] ?? ''));
        unset($rc);
        $out[(int)$pid] = $rows;
    }
    return $out;
}

/**
 * 一批波文的点赞用户（内容下方直接展示）。
 * 点赞表只存 uid，昵称/头像取 users 表实时值 —— 点赞是动态行为，展示最新资料更合理；
 * 每帖最多 10 人，其余用「等 N 人」概括（总数在 likes 上）。
 */
function owTmLikersOf(array $ids): array
{
    $out = [];
    foreach ($ids as $pid) {
        $rows = DB::all(
            'SELECT l.uid, u.nickname, u.avatar, u.avatar_type, u.avatar_style, u.avatar_seed, u.role'
            . ' FROM plugin_time_machine_like l INNER JOIN users u ON u.id = l.uid'
            . ' WHERE l.post_id=? ORDER BY l.created_at ASC LIMIT 10',
            [(int)$pid]
        );
        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'uid' => (int)$r['uid'],
                'nickname' => (string)$r['nickname'],
                'avatar' => Auth::avatarUrlFor($r, (string)$r['uid']),
            ];
        }
        $out[(int)$pid] = $list;
    }
    return $out;
}

/* ============================ rail 入口 ============================ */

/**
 * 站方指定的纸飞机字形（iconfont 填充式，非核心 ow_icon 线性风）。
 * fill=currentColor 跟随主题与按钮态；path 按「插件自有字形只在一处」的约定放在本文件。
 */
Plugin::on('sidebar.rail', function () {
    $svg = '<svg class="ow-tm-ricon" width="20" height="20" viewBox="0 0 1024 1024" fill="currentColor"'
         . ' xmlns="http://www.w3.org/2000/svg" aria-hidden="true">'
         . '<path d="M935.297772 881.100406 753.936191 699.738825c-10.120498-10.120498-26.51591-10.120498-36.636409 0-10.120498 10.120498-10.120498 26.51591 0 36.636409L898.661364 917.736814c5.060249 5.060249 11.689227 7.590885 18.318204 7.590885s13.257955-2.530636 18.318204-7.590885C945.41827 907.616316 945.41827 891.220904 935.297772 881.100406z"/>'
         . '<path d="M894.991788 530.458397c3.921309-24.871457-6.072299-49.780777-26.110681-65.012689L703.308117 339.39485l-4.65502-208.092385c-0.582261-25.162076-14.876826-47.895847-37.294394-59.319014-22.391986-11.43647-49.186236-9.614985-69.908186 4.718465l-171.064051 118.550023-199.300139-59.888995c-24.213471-7.235798-50.198286-0.670266-67.959811 17.141402-17.812691 17.799388-24.340361 43.835368-17.10354 67.921949l59.863413 199.325721L77.323063 590.802764c-14.32117 20.696368-16.142655 47.490617-4.706185 69.920466 11.43647 22.429849 34.18252 36.712133 59.357899 37.268812L340.028277 702.635805l126.051881 165.624155c13.05534 17.129122 33.196053 26.921139 54.296627 26.921139 3.567245 0 7.135514-0.278339 10.728342-0.847298 24.845874-3.934612 45.466518-21.12718 53.816696-44.909839L653.640927 652.968615l196.415438-68.706825C873.840048 575.951521 891.044896 555.329854 894.991788 530.458397zM623.709221 581.099774c-19.811208 6.907317-35.093263 22.227234-41.950438 41.924855L517.316076 807.245328 399.107837 651.918703c-12.651135-16.610306-31.905664-26.414603-52.804646-26.883277l-195.10049-4.352121 111.149473-160.386874c11.917424-17.192567 15.307637-38.546921 9.28548-58.573023l-56.143695-186.877201 186.902784 56.156998c20.013823 5.970992 41.342594 2.606361 58.547441-9.28548l160.386874-111.149473 4.352121 194.98588c0.429789 20.936845 10.221806 40.229237 26.921139 52.918233l155.275459 118.195959L623.709221 581.099774z"/>'
         . '</svg>';
    return '<button class="ow-rail-btn" data-tab="timemachine" type="button" title="时光机" aria-label="时光机">'
         . $svg
         . '<span class="ow-rail-lb">时光机</span></button>';
});

/* ============================ 静态资源 ============================ */

Plugin::asset('js', 'time-machine/chat.js');
Plugin::asset('css', 'time-machine/style.css');

/**
 * 核心「消息/联系人」标签是 display 切换，插件面板交给 onShow 后主区归插件管，
 * 样式必须无条件注入（弹窗里的发波文编辑器也用它），做法与 batch-ops 同一口径。
 * ⚠️ 版本参数用**插件自己的版本**（pv）：核心版本不变时只改插件 CSS，
 *    若只带 OWLSGO_VERSION，浏览器会命中旧缓存，站方会以为样式没生效。
 */
Plugin::on('page.head', function (): void {
    echo '<link rel="stylesheet" href="?action=assets&type=css&plugin=time-machine&file=style.css&v='
        . OWLSGO_VERSION . '&pv=' . rawurlencode(owTmVersion()) . '">' . "\n";
});

/** 插件自身版本（取自 plugin.json；读不到就回落到 0，只影响缓存键） */
function owTmVersion(): string
{
    static $v = null;
    if ($v !== null) return $v;
    $v = '0';
    $f = __DIR__ . '/plugin.json';
    if (is_file($f)) {
        $j = json_decode((string)@file_get_contents($f), true);
        if (is_array($j) && !empty($j['version'])) $v = (string)$j['version'];
    }
    return $v;
}

/* ============================ API：波文流 ============================ */

/**
 * 注册一条插件路由，并给它的处理器套一层兜底。
 *
 * 为什么要这一层：v1.1.0 在 MySQL 上建表语法错（1064）→ owTmBoot() 抛异常 →
 * **每一条路由都返回裸 500**，前端只能显示「网络错误（500）」，什么线索都没有，
 * 排障只能靠猜。现在异常统一转成带原因的 JSON（OwApi 照样解析得出来），
 * 用户看到的是「时光机出错：……」而不是一句没有信息量的状态码。
 * 正常分支不受影响：处理器自己已经用 Api::json 结束，这里只接它没接住的。
 */
function owTmRoute(string $action, callable $fn): void
{
    Plugin::route($action, function (array $ctx) use ($fn, $action): void {
        try {
            $fn($ctx);
        } catch (Throwable $e) {
            // 核心 Api::json 以 exit 收尾（永不返回），自检桩用异常模拟它 ——
            // 那不是错误，必须原样放行，否则测试拿到的会是这条兜底报文而不是真实响应。
            if (defined('OW_TM_TEST_HARNESS')) throw $e;
            Api::json([
                'ok' => false,
                'msg' => '时光机出错：' . $e->getMessage(),
                'at' => $action,
            ], 500);
        }
    });
}

/**
 * 分栏拉取：tab=new/hot/mine/fav，page 从 1 起。每页 10 条，多取 1 条算 has_more。
 * 浏览计数：按访问者去重（同一人反复刷新只记一次，作者看自己的不计）。
 * 顺带下发 can_post / is_admin / me，前端不用再猜权限；mine 分栏带 stats（条数 + 收到的点赞）。
 */
owTmRoute('plugin_time_machine_feed', function (array $ctx): void {
    owTmBoot();
    $actor = $ctx['actor'] ?? [];
    $p = $ctx['post'] ?? [];
    if (!Sec::rateLimit('otm_feed', ($actor['kind'] ?? 'n') . (int)($actor['id'] ?? 0), 60, 90)) {
        Api::json(['ok' => false, 'msg' => '读取过于频繁，请稍后再试'], 429);
    }
    $tab = (string)($p['tab'] ?? 'new');
    if (!in_array($tab, ['new', 'hot', 'mine', 'fav'], true)) $tab = 'new';
    $page = max(1, (int)($p['page'] ?? 1));
    $per = 10;
    $off = ($page - 1) * $per;
    $isUser = ($actor['kind'] ?? '') === 'user';
    $uid = $isUser ? (int)$actor['id'] : 0;
    $isAdmin = ($actor['role'] ?? '') === 'admin';

    $params = [];
    $vis = owTmVisCond($uid, $isAdmin, $params);   // 可见性条件（占位符参数已按顺序入 $params）
    switch ($tab) {
        case 'hot':
            $sql = 'SELECT * FROM plugin_time_machine_post p WHERE ' . $vis
                 . ' ORDER BY likes DESC, comments DESC, p.id DESC LIMIT ' . ($per + 1) . " OFFSET {$off}";
            break;
        case 'mine':
            $sql = 'SELECT * FROM plugin_time_machine_post p WHERE uid=? AND ' . $vis
                 . ' ORDER BY p.id DESC LIMIT ' . ($per + 1) . " OFFSET {$off}";
            array_unshift($params, $uid);   // uid=? 排在最前
            break;
        case 'fav':
            $sql = 'SELECT p.* FROM plugin_time_machine_post p'
                 . ' INNER JOIN plugin_time_machine_fav f ON f.post_id = p.id AND f.uid=?'
                 . ' WHERE ' . $vis
                 . ' ORDER BY f.created_at DESC LIMIT ' . ($per + 1) . " OFFSET {$off}";
            array_unshift($params, $uid);
            break;
        default:
            $sql = 'SELECT * FROM plugin_time_machine_post p WHERE ' . $vis
                 . ' ORDER BY p.id DESC LIMIT ' . ($per + 1) . " OFFSET {$off}";
    }
    $rows = $isUser || $tab === 'new' || $tab === 'hot' ? DB::all($sql, $params) : [];
    if (!$isUser && ($tab === 'mine' || $tab === 'fav')) $rows = [];   // 游客没有「我的/收藏」

    $hasMore = count($rows) > $per;
    $rows = array_slice($rows, 0, $per);
    $ids = [];
    foreach ($rows as $r) $ids[] = (int)$r['id'];
    [$liked, $faved] = owTmMineSets($ids, $uid);
    $comments = owTmCommentsOf($ids);   // 评论默认展开：随流下发
    $likers = owTmLikersOf($ids);       // 点赞用户：内容下方直接展示

    // 浏览计数（v1.1.0）：按访问者去重，同一人对同一条只记一次；作者看自己的不计。
    // $bumped 记住「本次真的计上了」的那些，回给前端时把这一次显示出来（行是查在前、更在后）。
    $bumped = [];
    if ($ids) {
        $viewer = owTmViewerKey($actor);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $seen = [];
        foreach (DB::all("SELECT post_id FROM plugin_time_machine_view WHERE viewer=? AND post_id IN ({$ph})",
            array_merge([$viewer], $ids)) as $v) {
            $seen[(int)$v['post_id']] = true;
        }
        foreach ($rows as $r) {
            $pid = (int)$r['id'];
            if (isset($seen[$pid])) continue;
            if ($uid > 0 && (int)$r['uid'] === $uid) continue;   // 自己刷自己的浏览没有意义
            // insertIgnore：并发下两条请求同时到，也只会写入一行
            DB::insertIgnore('plugin_time_machine_view', [
                'post_id' => $pid, 'viewer' => $viewer, 'seen_at' => time(),
            ], ['post_id', 'viewer']);
            $bumped[$pid] = true;
        }
        if ($bumped) {
            $bph = implode(',', array_fill(0, count($bumped), '?'));
            DB::run("UPDATE plugin_time_machine_post SET views=views+1 WHERE id IN ({$bph})", array_keys($bumped));
        }
    }

    $posts = [];
    foreach ($rows as $r) {
        $o = owTmOut($r);
        if (isset($bumped[$o['id']])) $o['views'] = (int)$o['views'] + 1;
        $o['liked'] = isset($liked[$o['id']]);
        $o['faved'] = isset($faved[$o['id']]);
        $o['comments'] = $comments[$o['id']] ?? [];          // 默认展开，随流下发
        $o['likers'] = $likers[$o['id']] ?? [];              // 点赞用户（内容下方展示）
        $posts[] = $o;
    }

    $stats = null;
    if ($isUser && $tab === 'mine') {
        $stats = [
            'posts' => (int)DB::val('SELECT COUNT(*) FROM plugin_time_machine_post WHERE uid=?', [$uid]),
            'likes' => (int)DB::val('SELECT COALESCE(SUM(likes),0) FROM plugin_time_machine_post WHERE uid=?', [$uid]),
        ];
    }

    // 「我」的资料：前端点赞后要把自己加进点赞用户行，不能靠前端猜头像
    $meProfile = null;
    if ($isUser) {
        $mu = DB::one('SELECT nickname, avatar, avatar_type, avatar_style, avatar_seed FROM users WHERE id=?', [$uid]);
        if ($mu) $meProfile = ['uid' => $uid, 'nickname' => (string)$mu['nickname'], 'avatar' => Auth::avatarUrlFor($mu, (string)$uid)];
    }

    Api::json([
        'ok' => true,
        'posts' => $posts,
        'page' => $page,
        'has_more' => $hasMore,
        'can_post' => $isUser,
        'is_admin' => $isAdmin,
        'me' => $uid,
        'me_profile' => $meProfile,
        'stats' => $stats,
        'attach' => owTmAttachInfo(),   // 附件能力（能不能传 / 上限 / 扩展名 / 提示）
    ]);
});

/* ============================ API：发波文 ============================ */

owTmRoute('plugin_time_machine_post', function (array $ctx): void {
    $actor = $ctx['actor'] ?? [];
    if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '请先登录后再发波文'], 403);
    owTmBoot();
    if (!Sec::rateLimit('otm_post', (string)$actor['id'], 600, 5)) {
        Api::json(['ok' => false, 'msg' => '发得太快啦，休息一下再发'], 429);
    }
    $p = $ctx['post'] ?? [];

    $content = trim((string)($p['content'] ?? ''));
    if (mb_strlen($content) > 2000) Api::json(['ok' => false, 'msg' => '波文最多 2000 字'], 400);

    // 可见性：公开 / 好友 / 私密（v1.2.0）
    $vis = strtolower(trim((string)($p['visibility'] ?? 'public')));
    if (!in_array($vis, ['public', 'friends', 'private'], true)) $vis = 'public';

    /* 附件（v1.2.0）：由附件上传插件上传后把产物回传，这里只做**复核**
       （owTmCleanMedia：图片过 mediaRel、文件过 fileAbs，伪造/外链一律丢弃）。 */
    $media = owTmCleanMedia((string)($p['media'] ?? ''));
    if ($content === '' && !$media) Api::json(['ok' => false, 'msg' => '写点什么，或者配张图吧'], 400);

    // 内容安全：与消息正文同一道闸门（敏感词打码 + 否决），scene 用 message
    Chat::filterText($content, 'message', $actor);
    if (($why = Chat::checkText($content, 'message', $actor)) !== null) {
        Api::json(['ok' => false, 'msg' => $why], 400);
    }

    // 作者快照（与 messages 表同语义：改资料不回溯旧波文）
    $u = DB::one('SELECT nickname, avatar, avatar_type, avatar_style, avatar_seed, role FROM users WHERE id=?', [(int)$actor['id']]);
    if (!$u) Api::json(['ok' => false, 'msg' => '账号状态异常，请重新登录'], 403);

    $now = time();
    $pid = (int)DB::insert('plugin_time_machine_post', [
        'uid' => (int)$actor['id'],
        'nickname' => mb_substr((string)$u['nickname'], 0, 64),
        'avatar' => Auth::avatarUrlFor($u),
        'role' => (string)$u['role'],
        'content' => $content,
        'images' => (string)json_encode($media, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'visibility' => $vis,
        'created_at' => $now,
    ]);
    $files = 0;
    foreach ($media as $m) if ($m['k'] === 'file') $files++;
    Sec::log('time_machine_post', (string)$actor['id'], ['post' => $pid, 'media' => count($media), 'files' => $files, 'vis' => $vis]);
    Api::json(['ok' => true, 'id' => $pid, 'msg' => '波文已发布']);
});

/* ============================ API：附件下载 ============================ */

/**
 * 下载波文 / 评论里的文件附件（GET + 签名，与核心 file_download 同一手法）。
 * 入参：id=<波文 id>（波文附件）或 cid=<评论 id>（评论附件），i=附件下标。
 * 可见性在这里**再判一次**：私密 / 好友可见的波文不能因为拿到链接就被下载
 * —— 签名只证明「这个请求来自站内」，不证明「这个人能看这条波文」。
 */
owTmRoute('plugin_time_machine_download', function (array $ctx): void {
    $actor = $ctx['actor'] ?? [];
    $pid = (int)($_GET['id'] ?? 0);
    $cid = (int)($_GET['cid'] ?? 0);
    $idx = (int)($_GET['i'] ?? 0);
    owTmBoot();
    if ($cid > 0) {
        // 评论附件：先取评论，再取它所属的波文做可见性判定
        $c = DB::one('SELECT * FROM plugin_time_machine_comment WHERE id=?', [$cid]);
        if (!$c) Api::json(['ok' => false, 'msg' => '文件不存在'], 404);
        $r = DB::one('SELECT * FROM plugin_time_machine_post WHERE id=?', [(int)$c['post_id']]);
        if (!$r) Api::json(['ok' => false, 'msg' => '文件不存在'], 404);
        $json = (string)($c['media'] ?? '');
    } else {
        $r = DB::one('SELECT * FROM plugin_time_machine_post WHERE id=?', [$pid]);
        if (!$r) Api::json(['ok' => false, 'msg' => '文件不存在'], 404);
        $json = (string)($r['images'] ?? '');
    }
    $uid = ($actor['kind'] ?? '') === 'user' ? (int)$actor['id'] : 0;
    if (!owTmVisOk($r, $uid, ($actor['role'] ?? '') === 'admin')) {
        Api::json(['ok' => false, 'msg' => '无权访问'], 403);
    }
    $raw = json_decode($json, true);
    $list = is_array($raw) ? array_values($raw) : [];
    $it = $list[$idx] ?? null;
    if (!is_array($it) || (string)($it['k'] ?? '') !== 'file') Api::json(['ok' => false, 'msg' => '文件不存在'], 404);
    $abs = Upload::fileAbs((string)($it['path'] ?? ''));
    if (!$abs) Api::json(['ok' => false, 'msg' => '文件不存在'], 404);
    $name = preg_replace('/[\r\n"]/', '', (string)($it['name'] ?? 'file')) ?: 'file';
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    readfile($abs);
    exit;
});

/* ============================ API：点赞 / 收藏 ============================ */

/** 点赞开关（同一批 SQL 形状，用 $type 区分 like / fav 两张表） */
foreach (['like' => 'plugin_time_machine_like', 'fav' => 'plugin_time_machine_fav'] as $owType => $owTable) {
    owTmRoute('plugin_time_machine_' . $owType, function (array $ctx) use ($owType, $owTable): void {
        $actor = $ctx['actor'] ?? [];
        if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '请先登录'], 403);
        owTmBoot();
        if (!Sec::rateLimit('otm_' . $owType, (string)$actor['id'], 60, 40)) {
            Api::json(['ok' => false, 'msg' => '操作过于频繁，请稍后再试'], 429);
        }
        $pid = (int)($ctx['post']['id'] ?? 0);
        $r = DB::one('SELECT id, likes FROM plugin_time_machine_post WHERE id=?', [$pid]);
        if (!$r) Api::json(['ok' => false, 'msg' => '这条波文已不存在'], 404);
        $uid = (int)$actor['id'];
        $has = (int)DB::val("SELECT COUNT(*) FROM {$owTable} WHERE post_id=? AND uid=?", [$pid, $uid]);
        if ($has) {
            DB::run("DELETE FROM {$owTable} WHERE post_id=? AND uid=?", [$pid, $uid]);
            if ($owType === 'like') {
                DB::run('UPDATE plugin_time_machine_post SET likes = CASE WHEN likes>0 THEN likes-1 ELSE 0 END WHERE id=?', [$pid]);
            }
            $on = false;
        } else {
            DB::insert($owTable, ['post_id' => $pid, 'uid' => $uid, 'created_at' => time()]);
            if ($owType === 'like') {
                DB::run('UPDATE plugin_time_machine_post SET likes=likes+1 WHERE id=?', [$pid]);
            }
            $on = true;
        }
        $likes = (int)DB::val('SELECT likes FROM plugin_time_machine_post WHERE id=?', [$pid]);
        Api::json(['ok' => true, 'on' => $on, 'likes' => $likes]);
    });
}

/* ============================ API：评论 ============================ */

/** 评论列表：整楼平铺（≤200 条，旧→新），前端展开时一次拉全 */
owTmRoute('plugin_time_machine_comments', function (array $ctx): void {
    owTmBoot();
    $actor = $ctx['actor'] ?? [];
    if (!Sec::rateLimit('otm_cmts', ($actor['kind'] ?? 'n') . (int)($actor['id'] ?? 0), 60, 90)) {
        Api::json(['ok' => false, 'msg' => '读取过于频繁，请稍后再试'], 429);
    }
    $pid = (int)($ctx['post']['post_id'] ?? 0);
    $post = DB::one('SELECT * FROM plugin_time_machine_post WHERE id=?', [$pid]);
    if (!$post) Api::json(['ok' => false, 'msg' => '这条波文已不存在'], 404);
    // 可见性：看不到的波文，评论也不该读得到（列表接口不会给入口，这里是直接调用的兜底）
    $uidC = ($ctx['actor']['kind'] ?? '') === 'user' ? (int)$ctx['actor']['id'] : 0;
    if (!owTmVisOk($post, $uidC, ($ctx['actor']['role'] ?? '') === 'admin')) {
        Api::json(['ok' => false, 'msg' => '这条波文已不存在'], 404);
    }
    $rows = DB::all(
        'SELECT id, uid, nickname, avatar, role, content, media, created_at FROM plugin_time_machine_comment'
        . ' WHERE post_id=? ORDER BY id ASC LIMIT 200',
        [$pid]
    );
    foreach ($rows as &$rc) $rc['media'] = owTmMediaOut((string)($rc['media'] ?? ''));
    unset($rc);
    Api::json(['ok' => true, 'comments' => $rows]);
});

owTmRoute('plugin_time_machine_comment_add', function (array $ctx): void {
    $actor = $ctx['actor'] ?? [];
    if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '请先登录后再评论'], 403);
    owTmBoot();
    if (!Sec::rateLimit('otm_cmt_add', (string)$actor['id'], 60, 10)) {
        Api::json(['ok' => false, 'msg' => '评论太快啦，休息一下'], 429);
    }
    $pid = (int)($ctx['post']['post_id'] ?? 0);
    $post = DB::one('SELECT * FROM plugin_time_machine_post WHERE id=?', [$pid]);
    if (!$post) Api::json(['ok' => false, 'msg' => '这条波文已不存在'], 404);
    // 同上：看不到的波文也不能评论（否则凭 post_id 就能往私密波文里灌评论）
    if (!owTmVisOk($post, (int)$actor['id'], ($actor['role'] ?? '') === 'admin')) {
        Api::json(['ok' => false, 'msg' => '这条波文已不存在'], 404);
    }

    $content = trim((string)($ctx['post']['content'] ?? ''));
    if (mb_strlen($content) > 500) Api::json(['ok' => false, 'msg' => '评论最多 500 字'], 400);
    // v1.4.0：评论也能带附件（图/文件，与波文同一套清洗）
    $media = owTmCleanMedia((string)($ctx['post']['media'] ?? ''));
    if ($content === '' && !$media) Api::json(['ok' => false, 'msg' => '说点什么吧'], 400);
    Chat::filterText($content, 'message', $actor);
    if (($why = Chat::checkText($content, 'message', $actor)) !== null) {
        Api::json(['ok' => false, 'msg' => $why], 400);
    }

    $u = DB::one('SELECT nickname, avatar, avatar_type, avatar_style, avatar_seed, role FROM users WHERE id=?', [(int)$actor['id']]);
    if (!$u) Api::json(['ok' => false, 'msg' => '账号状态异常，请重新登录'], 403);

    $now = time();
    $cid = (int)DB::insert('plugin_time_machine_comment', [
        'post_id' => $pid,
        'uid' => (int)$actor['id'],
        'nickname' => mb_substr((string)$u['nickname'], 0, 64),
        'avatar' => Auth::avatarUrlFor($u),
        'role' => (string)$u['role'],
        'content' => $content,
        'media' => (string)json_encode($media, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'created_at' => $now,
    ]);
    DB::run('UPDATE plugin_time_machine_post SET comments=comments+1 WHERE id=?', [$pid]);
    Api::json([
        'ok' => true,
        'comment' => [
            'id' => $cid,
            'uid' => (int)$actor['id'],
            'nickname' => mb_substr((string)$u['nickname'], 0, 64),
            'avatar' => Auth::avatarUrlFor($u),
            'role' => (string)$u['role'],
            'content' => $content,
            'media' => $media,
            'created_at' => $now,
        ],
        'comments' => (int)$post['comments'] + 1,
    ]);
});

/* ============================ API：修改可见性（v1.4.0） ============================ */

/**
 * 改「谁可以看」：只允许作者本人或管理员。
 * 为什么不复用发波文路由：那是「新建」，这里只动一列，还要允许把一条老波文
 * 从公开收窄成私密（收窄是安全方向，不需要二次确认；放宽回公开倒要谨慎，
 * 所以前端在「放回公开」时自己加一句提示）。
 */
owTmRoute('plugin_time_machine_set_vis', function (array $ctx): void {
    $actor = $ctx['actor'] ?? [];
    if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '请先登录'], 403);
    owTmBoot();
    if (!Sec::rateLimit('otm_vis', (string)$actor['id'], 60, 30)) {
        Api::json(['ok' => false, 'msg' => '操作过于频繁，请稍后再试'], 429);
    }
    $pid = (int)($ctx['post']['id'] ?? 0);
    $vis = strtolower(trim((string)($ctx['post']['visibility'] ?? '')));
    if (!in_array($vis, ['public', 'friends', 'private'], true)) {
        Api::json(['ok' => false, 'msg' => '可见范围不正确'], 400);
    }
    $r = DB::one('SELECT id, uid FROM plugin_time_machine_post WHERE id=?', [$pid]);
    if (!$r) Api::json(['ok' => false, 'msg' => '这条波文已不存在'], 404);
    if ((int)$r['uid'] !== (int)$actor['id'] && ($actor['role'] ?? '') !== 'admin') {
        Api::json(['ok' => false, 'msg' => '只能修改自己的波文'], 403);
    }
    DB::run('UPDATE plugin_time_machine_post SET visibility=? WHERE id=?', [$vis, $pid]);
    Sec::log('time_machine_set_vis', (string)$actor['id'], ['post' => $pid, 'vis' => $vis]);
    Api::json(['ok' => true, 'visibility' => $vis, 'msg' => '已更新可见范围']);
});

/* ============================ API：删除（敏感） ============================ */

/** 删除波文：作者本人或管理员；连带清评论 / 点赞 / 收藏。敏感操作（票据 + 二次确认）。 */
owTmRoute('plugin_time_machine_delete', function (array $ctx): void {
    $actor = $ctx['actor'] ?? [];
    if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '请先登录'], 403);
    owTmBoot();
    $pid = (int)($ctx['post']['id'] ?? 0);
    $r = DB::one('SELECT id, uid FROM plugin_time_machine_post WHERE id=?', [$pid]);
    if (!$r) Api::json(['ok' => false, 'msg' => '这条波文已不存在'], 404);
    if ((int)$r['uid'] !== (int)$actor['id'] && ($actor['role'] ?? '') !== 'admin') {
        Api::json(['ok' => false, 'msg' => '只能删除自己的波文'], 403);
    }
    DB::run('DELETE FROM plugin_time_machine_post WHERE id=?', [$pid]);
    DB::run('DELETE FROM plugin_time_machine_comment WHERE post_id=?', [$pid]);
    DB::run('DELETE FROM plugin_time_machine_like WHERE post_id=?', [$pid]);
    DB::run('DELETE FROM plugin_time_machine_fav WHERE post_id=?', [$pid]);
    DB::run('DELETE FROM plugin_time_machine_view WHERE post_id=?', [$pid]);
    Sec::log('time_machine_delete', (string)$actor['id'], ['post' => $pid, 'owner' => (int)$r['uid']]);
    Api::json(['ok' => true, 'msg' => '已删除']);
});
Plugin::sensitive('plugin_time_machine_delete');
