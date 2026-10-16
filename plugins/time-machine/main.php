<?php
/**
 * 时光机插件（time-machine v1.0.0）
 *
 * ── 做什么 ────────────────────────────────────────────────────
 * 类似朋友圈的动态广场。入口是 rail 上的一枚「时光机」按钮（sidebar.rail 钩子，
 * 站方指定的纸飞机字形，path 只在本文件出现一次），点开后：
 *   · 侧栏面板：简介 + 「发波文」按钮；
 *   · 主区（接管 #owMessages，与系统通知同一套接管手法）：波文时间流。
 * 一条动态叫一条「波文」：文字（≤2000 字）+ 最多 9 张图。
 *
 * ── 能力 ──────────────────────────────────────────────────────
 *   · 分栏：最新（时间序）/ 热门（点赞序）/ 我的 / 收藏；每页 10 条，「加载更多」翻页；
 *   · 互动：点赞（每人每条一次，可取消）、收藏（可取消）、评论（≤500 字，平铺无楼中楼）、
 *     浏览计数（feed 拉到哪条就给哪条 +1）、正文一键复制；
 *   · 删除：作者本人或管理员；走敏感操作（POST + 一次性票据），连带清评论/点赞/收藏；
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

/** 懒建表：波文 / 评论 / 点赞 / 收藏（只在真正读写时执行） */
function owTmBoot(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $id = DB::autoId();
    $txt = DB::textCol('content');
    DB::run("CREATE TABLE IF NOT EXISTS plugin_time_machine_post (
        id         {$id},
        uid        INTEGER      NOT NULL DEFAULT 0,
        nickname   VARCHAR(64)  NOT NULL DEFAULT '',   -- 发送时快照（与 messages 同语义）
        avatar     " . DB::textCol('avatar') . ",
        role       VARCHAR(20)  NOT NULL DEFAULT '',
        content    {$txt},
        images     " . DB::textCol('images') . ",      -- JSON 数组：本站 uploads/ 的图片 URL（≤9 张）
        views      INTEGER      NOT NULL DEFAULT 0,
        likes      INTEGER      NOT NULL DEFAULT 0,
        comments   INTEGER      NOT NULL DEFAULT 0,
        created_at INTEGER      NOT NULL DEFAULT 0
    )");
    DB::createIndex('idx_otm_post_time', 'plugin_time_machine_post', 'created_at', false);
    DB::createIndex('idx_otm_post_uid', 'plugin_time_machine_post', 'uid', false);

    DB::run("CREATE TABLE IF NOT EXISTS plugin_time_machine_comment (
        id         {$id},
        post_id    INTEGER      NOT NULL DEFAULT 0,
        uid        INTEGER      NOT NULL DEFAULT 0,
        nickname   VARCHAR(64)  NOT NULL DEFAULT '',
        avatar     " . DB::textCol('avatar') . ",
        role       VARCHAR(20)  NOT NULL DEFAULT '',
        content    VARCHAR(500) NOT NULL DEFAULT '',
        created_at INTEGER      NOT NULL DEFAULT 0
    )");
    DB::createIndex('idx_otm_cmt_post', 'plugin_time_machine_comment', 'post_id', false);

    DB::run("CREATE TABLE IF NOT EXISTS plugin_time_machine_like (
        post_id    INTEGER      NOT NULL DEFAULT 0,
        uid        INTEGER      NOT NULL DEFAULT 0,
        created_at INTEGER      NOT NULL DEFAULT 0
    )");
    DB::createIndex('idx_otm_like_key', 'plugin_time_machine_like', 'post_id,uid', true);

    DB::run("CREATE TABLE IF NOT EXISTS plugin_time_machine_fav (
        post_id    INTEGER      NOT NULL DEFAULT 0,
        uid        INTEGER      NOT NULL DEFAULT 0,
        created_at INTEGER      NOT NULL DEFAULT 0
    )");
    DB::createIndex('idx_otm_fav_key', 'plugin_time_machine_fav', 'post_id,uid', true);
}

/* ============================ 行组装 ============================ */

/**
 * post 行 → 下发结构。图片字段存的是 JSON 数组，这里解码 + 逐张复核
 * （只认本站 uploads/ 且文件确实存在；旧数据里的死链静默丢弃，不占名额）。
 */
function owTmOut(array $r): array
{
    $imgs = [];
    $raw = json_decode((string)($r['images'] ?? ''), true);
    if (is_array($raw)) {
        foreach ($raw as $u) {
            $u = (string)$u;
            if ($u !== '' && Upload::mediaRel($u) !== null) $imgs[] = $u;
            if (count($imgs) >= 9) break;
        }
    }
    return [
        'id' => (int)$r['id'],
        'uid' => (int)$r['uid'],
        'nickname' => (string)$r['nickname'],
        'avatar' => (string)$r['avatar'],
        'role' => (string)$r['role'],
        'content' => (string)$r['content'],
        'images' => $imgs,
        'views' => (int)$r['views'],
        'likes' => (int)$r['likes'],
        'comments' => (int)$r['comments'],
        'created_at' => (int)$r['created_at'],
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
 */
Plugin::on('page.head', function (): void {
    echo '<link rel="stylesheet" href="?action=assets&type=css&plugin=time-machine&file=style.css&v='
        . OWLSGO_VERSION . '">' . "\n";
});

/* ============================ API：波文流 ============================ */

/**
 * 分栏拉取：tab=new/hot/mine/fav，page 从 1 起。每页 10 条，多取 1 条算 has_more。
 * 浏览计数：本次下发的波文各 +1（mine 分栏不计，自己刷自己不算浏览）。
 * 顺带下发 can_post / is_admin / me，前端不用再猜权限；mine 分栏带 stats（条数 + 收到的点赞）。
 */
Plugin::route('plugin_time_machine_feed', function (array $ctx): void {
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
    switch ($tab) {
        case 'hot':
            $sql = 'SELECT * FROM plugin_time_machine_post ORDER BY likes DESC, comments DESC, id DESC LIMIT ' . ($per + 1) . " OFFSET {$off}";
            break;
        case 'mine':
            $sql = 'SELECT * FROM plugin_time_machine_post WHERE uid=? ORDER BY id DESC LIMIT ' . ($per + 1) . " OFFSET {$off}";
            $params[] = $uid;
            break;
        case 'fav':
            $sql = 'SELECT p.* FROM plugin_time_machine_post p'
                 . ' INNER JOIN plugin_time_machine_fav f ON f.post_id = p.id AND f.uid=?'
                 . ' ORDER BY f.created_at DESC LIMIT ' . ($per + 1) . " OFFSET {$off}";
            $params[] = $uid;
            break;
        default:
            $sql = 'SELECT * FROM plugin_time_machine_post ORDER BY id DESC LIMIT ' . ($per + 1) . " OFFSET {$off}";
    }
    $rows = $isUser || $tab === 'new' || $tab === 'hot' ? DB::all($sql, $params) : [];
    if (!$isUser && ($tab === 'mine' || $tab === 'fav')) $rows = [];   // 游客没有「我的/收藏」

    $hasMore = count($rows) > $per;
    $rows = array_slice($rows, 0, $per);
    $ids = [];
    foreach ($rows as $r) $ids[] = (int)$r['id'];
    [$liked, $faved] = owTmMineSets($ids, $uid);

    // 浏览计数：feed 拉到即算浏览（mine 除外）；批量 +1，一次 SQL
    if ($ids && $tab !== 'mine') {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        DB::run("UPDATE plugin_time_machine_post SET views=views+1 WHERE id IN ({$ph})", $ids);
    }

    $posts = [];
    foreach ($rows as $r) {
        $o = owTmOut($r);
        // 浏览数返回**自增后**的值：行是先取后更新的，旧值会让首次浏览显示 0
        if ($tab !== 'mine') $o['views'] = (int)$o['views'] + 1;
        $o['liked'] = isset($liked[$o['id']]);
        $o['faved'] = isset($faved[$o['id']]);
        $posts[] = $o;
    }

    $stats = null;
    if ($isUser && $tab === 'mine') {
        $stats = [
            'posts' => (int)DB::val('SELECT COUNT(*) FROM plugin_time_machine_post WHERE uid=?', [$uid]),
            'likes' => (int)DB::val('SELECT COALESCE(SUM(likes),0) FROM plugin_time_machine_post WHERE uid=?', [$uid]),
        ];
    }

    Api::json([
        'ok' => true,
        'posts' => $posts,
        'page' => $page,
        'has_more' => $hasMore,
        'can_post' => $isUser,
        'is_admin' => $isAdmin,
        'me' => $uid,
        'stats' => $stats,
    ]);
});

/* ============================ API：发波文 ============================ */

Plugin::route('plugin_time_machine_post', function (array $ctx): void {
    $actor = $ctx['actor'] ?? [];
    if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '请先登录后再发波文'], 403);
    owTmBoot();
    if (!Sec::rateLimit('otm_post', (string)$actor['id'], 600, 5)) {
        Api::json(['ok' => false, 'msg' => '发得太快啦，休息一下再发'], 429);
    }
    $p = $ctx['post'] ?? [];

    $content = trim((string)($p['content'] ?? ''));
    if (mb_strlen($content) > 2000) Api::json(['ok' => false, 'msg' => '波文最多 2000 字'], 400);

    // 图片：前端给 JSON 数组；逐张过 Upload::mediaRel（只认本站 uploads/ 且文件真实存在）
    $images = [];
    $raw = json_decode((string)($p['images'] ?? ''), true);
    if (is_array($raw)) {
        foreach ($raw as $u) {
            $u = trim((string)$u);
            if ($u === '') continue;
            if (Upload::mediaRel($u) === null) continue;   // 伪造/外链地址：静默丢弃
            if (!in_array($u, $images, true)) $images[] = $u;
            if (count($images) >= 9) break;
        }
    }
    if ($content === '' && !$images) Api::json(['ok' => false, 'msg' => '写点什么，或者配张图吧'], 400);

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
        'images' => (string)json_encode($images, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'created_at' => $now,
    ]);
    Sec::log('time_machine_post', (string)$actor['id'], ['post' => $pid, 'imgs' => count($images)]);
    Api::json(['ok' => true, 'id' => $pid, 'msg' => '波文已发布']);
});

/* ============================ API：图片上传 ============================ */

/** 波文配图：走核心 Upload::handle（kind=image，附件插件的 WebP 压缩钩子照常生效） */
Plugin::route('plugin_time_machine_upload', function (array $ctx): void {
    $actor = $ctx['actor'] ?? [];
    if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '请先登录后再上传'], 403);
    if (empty($_FILES['file'])) Api::json(['ok' => false, 'msg' => '未接收到文件']);
    if (!Sec::rateLimit('otm_upload', (string)$actor['id'], 60, 12)) {
        Api::json(['ok' => false, 'msg' => '上传过于频繁，请稍后再试'], 429);
    }
    [$ok, $urlOrMsg] = Upload::handle($_FILES['file'], 'image');
    Api::json($ok ? ['ok' => true, 'url' => $urlOrMsg] : ['ok' => false, 'msg' => $urlOrMsg]);
});

/* ============================ API：点赞 / 收藏 ============================ */

/** 点赞开关（同一批 SQL 形状，用 $type 区分 like / fav 两张表） */
foreach (['like' => 'plugin_time_machine_like', 'fav' => 'plugin_time_machine_fav'] as $owType => $owTable) {
    Plugin::route('plugin_time_machine_' . $owType, function (array $ctx) use ($owType, $owTable): void {
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
Plugin::route('plugin_time_machine_comments', function (array $ctx): void {
    owTmBoot();
    $actor = $ctx['actor'] ?? [];
    if (!Sec::rateLimit('otm_cmts', ($actor['kind'] ?? 'n') . (int)($actor['id'] ?? 0), 60, 90)) {
        Api::json(['ok' => false, 'msg' => '读取过于频繁，请稍后再试'], 429);
    }
    $pid = (int)($ctx['post']['post_id'] ?? 0);
    $post = DB::one('SELECT id FROM plugin_time_machine_post WHERE id=?', [$pid]);
    if (!$post) Api::json(['ok' => false, 'msg' => '这条波文已不存在'], 404);
    $rows = DB::all(
        'SELECT id, uid, nickname, avatar, role, content, created_at FROM plugin_time_machine_comment'
        . ' WHERE post_id=? ORDER BY id ASC LIMIT 200',
        [$pid]
    );
    Api::json(['ok' => true, 'comments' => $rows]);
});

Plugin::route('plugin_time_machine_comment_add', function (array $ctx): void {
    $actor = $ctx['actor'] ?? [];
    if (($actor['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '请先登录后再评论'], 403);
    owTmBoot();
    if (!Sec::rateLimit('otm_cmt_add', (string)$actor['id'], 60, 10)) {
        Api::json(['ok' => false, 'msg' => '评论太快啦，休息一下'], 429);
    }
    $pid = (int)($ctx['post']['post_id'] ?? 0);
    $post = DB::one('SELECT id, comments FROM plugin_time_machine_post WHERE id=?', [$pid]);
    if (!$post) Api::json(['ok' => false, 'msg' => '这条波文已不存在'], 404);

    $content = trim((string)($ctx['post']['content'] ?? ''));
    if ($content === '') Api::json(['ok' => false, 'msg' => '说点什么吧'], 400);
    if (mb_strlen($content) > 500) Api::json(['ok' => false, 'msg' => '评论最多 500 字'], 400);
    Chat::filterText($content, 'message', $actor);
    if (($why = Chat::checkText($content, 'message', $actor)) !== null) {
        Api::json(['ok' => false, 'msg' => $why], 400);
    }

    $u = DB::one('SELECT nickname, avatar, avatar_type, avatar_style, avatar_seed, role FROM users WHERE id=?', [(int)$actor['id']]);
    if (!$u) Api::json(['ok' => false, 'msg' => '账号状态异常，请重新登录'], 403);

    $cid = (int)DB::insert('plugin_time_machine_comment', [
        'post_id' => $pid,
        'uid' => (int)$actor['id'],
        'nickname' => mb_substr((string)$u['nickname'], 0, 64),
        'avatar' => Auth::avatarUrlFor($u),
        'role' => (string)$u['role'],
        'content' => $content,
        'created_at' => time(),
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
            'created_at' => time(),
        ],
        'comments' => (int)$post['comments'] + 1,
    ]);
});

/* ============================ API：删除（敏感） ============================ */

/** 删除波文：作者本人或管理员；连带清评论 / 点赞 / 收藏。敏感操作（票据 + 二次确认）。 */
Plugin::route('plugin_time_machine_delete', function (array $ctx): void {
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
    Sec::log('time_machine_delete', (string)$actor['id'], ['post' => $pid, 'owner' => (int)$r['uid']]);
    Api::json(['ok' => true, 'msg' => '已删除']);
});
Plugin::sensitive('plugin_time_machine_delete');
