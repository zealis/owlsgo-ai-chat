<?php
/**
 * 链接卡片插件（link-card v1.0.0）
 *
 * ── 功能 ──────────────────────────────────────────────────
 *   后台统一维护「分享卡片」：一次填写标题 / 描述 / 封面图 / 规范链接，
 *   按需启用各平台的链接预览（页面 meta 标签）：
 *     · Open Graph（og:*）   —— Facebook / LinkedIn / Discord / Slack 等
 *     · X（Twitter）卡片     —— twitter:card 系列
 *     · 微信（wx.share.*）   —— 微信内置浏览器读取的私有 Meta 标签
 *     · QQ（qq:share:*）     —— QQ 内置浏览器读取的私有 Meta 标签
 *   可建多张卡片、每张单独启停；多张同时启用时抓取器按列表顺序取第一张。
 *
 * ── 落地方式 ──────────────────────────────────────────────
 *   page.head 钩子（pageHead() 内、</head> 之前）输出 <meta>，不改核心文件。
 *   管理后台是内部工具：不输出卡片 meta，插件样式也只在后台页加载。
 *
 * 约束：
 *   - 兼容 SQLite / MySQL / PostgreSQL（自增主键走 DB::autoId()，长文本走 DB::textCol()）。
 *   - 全部 SQL 参数化；main.php 顶层只做 Plugin::* 注册，懒建表见 owLCBoot()。
 *   - 样式只引用核心 --ow-* 变量（style.css），深浅两套主题自动跟随。
 *   - og:image 必须是 https:// 开头的可公开访问绝对地址（保存时校验）。
 */
if (!defined('OWLSGO_VERSION')) exit;   // 禁止直接 HTTP 访问本文件

/* ============================ 建表 ============================ */

/** 懒建表：只在真正读写数据时执行（main.php 顶层不做副作用） */
function owLCBoot(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $id = DB::autoId();
    DB::run("CREATE TABLE IF NOT EXISTS plugin_link_card (
        id           {$id},
        name         VARCHAR(64)  NOT NULL DEFAULT '',   -- 卡片备注名（后台识别用，不进 meta）
        url          VARCHAR(512) NOT NULL DEFAULT '',   -- og:url 规范链接（绝对地址）
        title        VARCHAR(255) NOT NULL DEFAULT '',   -- 四平台通用标题
        description  " . DB::textCol('description') . ", -- 四平台通用描述（长文本列，方言由核心给）
        image        VARCHAR(512) NOT NULL DEFAULT '',   -- og:image 封面图（https:// 绝对地址）
        og_type      VARCHAR(16)  NOT NULL DEFAULT 'website',         -- og:type：website / article
        tw_card      VARCHAR(24)  NOT NULL DEFAULT 'summary_large_image', -- twitter:card：summary / summary_large_image
        p_og         INTEGER NOT NULL DEFAULT 0,         -- Open Graph 平台开关
        p_tw         INTEGER NOT NULL DEFAULT 0,         -- X（Twitter）平台开关
        p_wx         INTEGER NOT NULL DEFAULT 0,         -- 微信平台开关
        p_qq         INTEGER NOT NULL DEFAULT 0,         -- QQ 平台开关
        enabled      INTEGER NOT NULL DEFAULT 1,         -- 卡片启停
        sort         INTEGER NOT NULL DEFAULT 0,         -- 排序（小者在前，决定哪张先生效）
        created_at   INTEGER NOT NULL DEFAULT 0,
        updated_at   INTEGER NOT NULL DEFAULT 0
    )");
}

/* ============================ 校验助手 ============================ */

/** 绝对地址校验：http/https 且带主机名（og:url 用） */
function owLCValidUrl(string $u): bool
{
    if ($u === '' || mb_strlen($u) > 512) return false;
    if (!preg_match('#^https?://#i', $u)) return false;
    $p = parse_url($u);
    return !empty($p['host']) && in_array(strtolower((string)$p['scheme']), ['http', 'https'], true);
}

/** 封面图校验：https:// 绝对地址（OG 规范要求 og:image 可公开访问） */
function owLCValidImage(string $u): bool
{
    return owLCValidUrl($u) && stripos($u, 'https://') === 0;
}

/* ============================ 分享卡片 meta ============================ */

/** 取当前生效的卡片：启用中按排序最前的一张 */
function owLCActiveCard(): ?array
{
    return DB::one("SELECT * FROM plugin_link_card WHERE enabled=1 ORDER BY sort ASC, id ASC LIMIT 1");
}

/**
 * 输出启用平台的 meta 标签（page.head 钩子调用）。
 * 标题/链接必填恒输出；描述与封面图为空时跳过对应标签，避免空 content。
 */
function owLCMeta(): void
{
    $row = owLCActiveCard();
    if (!$row) return;

    $title = (string)$row['title'];
    $desc  = (string)($row['description'] ?? '');
    $image = (string)$row['image'];
    $url   = (string)$row['url'];
    $site  = (string)DB::setting('site_name', '');
    $m = [];   // [attr, name, content]

    if ((int)$row['p_og'] === 1) {
        $m[] = ['property', 'og:title', $title];
        if ($desc !== '')  $m[] = ['property', 'og:description', $desc];
        if ($image !== '') $m[] = ['property', 'og:image', $image];
        $m[] = ['property', 'og:url', $url];
        $m[] = ['property', 'og:type', (string)$row['og_type']];
        if ($site !== '') $m[] = ['property', 'og:site_name', $site];
    }
    if ((int)$row['p_tw'] === 1) {
        $m[] = ['name', 'twitter:card', (string)$row['tw_card']];
        $m[] = ['name', 'twitter:title', $title];
        if ($desc !== '')  $m[] = ['name', 'twitter:description', $desc];
        if ($image !== '') $m[] = ['name', 'twitter:image', $image];
    }
    if ((int)$row['p_wx'] === 1) {
        $m[] = ['name', 'wx.share.title', $title];
        if ($desc !== '')  $m[] = ['name', 'wx.share.desc', $desc];
        if ($image !== '') $m[] = ['name', 'wx.share.img', $image];
    }
    if ((int)$row['p_qq'] === 1) {
        $m[] = ['name', 'qq:share:title', $title];
        if ($desc !== '')  $m[] = ['name', 'qq:share:description', $desc];
        if ($image !== '') $m[] = ['name', 'qq:share:image', $image];
    }
    if (!$m) return;

    echo "<!-- link-card v1.0.0 -->\n";
    foreach ($m as $t) {
        echo '<meta ' . $t[0] . '="' . Sec::e($t[1]) . '" content="' . Sec::e($t[2]) . '">' . "\n";
    }
}

/**
 * page.head：后台页只注入插件样式；其余页面输出分享卡片 meta。
 * 管理后台是内部工具，不参与链接分享，故不输出 meta。
 */
Plugin::on('page.head', function (): void {
    if (($_GET['page'] ?? '') === 'admin') {
        // 主程序不自动引入插件 CSS，需自行挂 <link>（只取本插件这一份）
        echo '<link rel="stylesheet" href="?action=assets&type=css&plugin=link-card&file=style.css&v='
            . OWLSGO_VERSION . '">' . "\n";
        return;
    }
    owLCBoot();
    owLCMeta();
});

/* ============================ 后台页面 ============================ */

Plugin::adminPage('link-card', '链接卡片', function () {
    return '<h2>链接卡片</h2>'
        . '<p class="ow-admin-desc">一次填写分享信息，按需启用 Open Graph / X（Twitter）/ 微信 / QQ 平台的链接卡片。'
        . '抓取器读取页面 meta 标签生成卡片，封面图须为可公开访问的 https:// 绝对地址；'
        . '多张卡片同时启用时，平台按列表顺序取第一张。</p>'
        . '<div class="ow-admin-batch">'
        . '<button class="ow-btn ow-btn-primary" onclick="OwLC.edit(0)">新建卡片</button>'
        . '<span class="lc-count" id="lcCount"></span>'
        . '</div>'
        . '<div id="lcNotice"></div>'
        . '<div id="lcList"><div class="ow-card lc-empty">加载中…</div></div>';
});

/* ============================ API 路由 ============================ */

/** 管理员鉴权：插件路由不在 Admin::handle 内，核心不会替插件校验权限 */
$lcGuard = function (array $ctx): void {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
};

/** 卡片列表（按排序，供后台渲染） */
Plugin::route('plugin_link_card_list', function (array $ctx) use ($lcGuard): void {
    $lcGuard($ctx);
    owLCBoot();
    Api::json(['ok' => true, 'cards' => DB::all("SELECT * FROM plugin_link_card ORDER BY sort ASC, id ASC")]);
});

/** 新建 / 保存卡片 */
Plugin::route('plugin_link_card_save', function (array $ctx) use ($lcGuard): void {
    $lcGuard($ctx);
    owLCBoot();
    $p = $ctx['post'];

    $id     = (int)($p['id'] ?? 0);
    $name   = trim((string)($p['name'] ?? ''));
    $url    = trim((string)($p['url'] ?? ''));
    $title  = trim((string)($p['title'] ?? ''));
    $desc   = trim((string)($p['desc'] ?? ''));
    $image  = trim((string)($p['image'] ?? ''));
    $ogType = (string)($p['og_type'] ?? 'website');
    $twCard = (string)($p['tw_card'] ?? 'summary_large_image');
    $pOg = !empty($p['p_og']) ? 1 : 0;
    $pTw = !empty($p['p_tw']) ? 1 : 0;
    $pWx = !empty($p['p_wx']) ? 1 : 0;
    $pQq = !empty($p['p_qq']) ? 1 : 0;
    $enabled = !empty($p['enabled']) ? 1 : 0;
    $sort    = max(-9999, min(9999, (int)($p['sort'] ?? 0)));

    if ($name === '' || mb_strlen($name) > 64) {
        Api::json(['ok' => false, 'msg' => '请填写卡片名称（64 字以内）'], 400);
    }
    if ($title === '' || mb_strlen($title) > 255) {
        Api::json(['ok' => false, 'msg' => '请填写卡片标题（255 字以内）'], 400);
    }
    if (mb_strlen($desc) > 300) {
        Api::json(['ok' => false, 'msg' => '描述过长（300 字以内）'], 400);
    }
    if (!owLCValidUrl($url)) {
        Api::json(['ok' => false, 'msg' => '请填写有效的规范链接（http/https 绝对地址）'], 400);
    }
    if ($image !== '' && !owLCValidImage($image)) {
        Api::json(['ok' => false, 'msg' => '封面图须为 https:// 开头的绝对地址'], 400);
    }
    if (!in_array($ogType, ['website', 'article'], true)) {
        Api::json(['ok' => false, 'msg' => '非法内容类型'], 400);
    }
    if (!in_array($twCard, ['summary', 'summary_large_image'], true)) {
        Api::json(['ok' => false, 'msg' => '非法卡片样式'], 400);
    }
    if ($pOg + $pTw + $pWx + $pQq === 0) {
        Api::json(['ok' => false, 'msg' => '请至少选择一个分享平台'], 400);
    }

    $now = time();
    if ($id > 0) {
        if (!DB::val("SELECT COUNT(*) FROM plugin_link_card WHERE id=?", [$id])) {
            Api::json(['ok' => false, 'msg' => '卡片不存在'], 404);
        }
        DB::run("UPDATE plugin_link_card SET name=?,url=?,title=?,description=?,image=?,og_type=?,tw_card=?,
                p_og=?,p_tw=?,p_wx=?,p_qq=?,enabled=?,sort=?,updated_at=? WHERE id=?",
                [$name, $url, $title, $desc, $image, $ogType, $twCard,
                 $pOg, $pTw, $pWx, $pQq, $enabled, $sort, $now, $id]);
    } else {
        DB::insert('plugin_link_card', [
            'name' => $name, 'url' => $url, 'title' => $title, 'description' => $desc,
            'image' => $image, 'og_type' => $ogType, 'tw_card' => $twCard,
            'p_og' => $pOg, 'p_tw' => $pTw, 'p_wx' => $pWx, 'p_qq' => $pQq,
            'enabled' => $enabled, 'sort' => $sort, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
    Sec::log('link_card_save', $ctx['actor']['nickname'], ['id' => $id, 'name' => $name]);
    Api::json(['ok' => true, 'msg' => '已保存', 'cards' => DB::all("SELECT * FROM plugin_link_card ORDER BY sort ASC, id ASC")]);
});

/** 启用 / 停用单张卡片 */
Plugin::route('plugin_link_card_toggle', function (array $ctx) use ($lcGuard): void {
    $lcGuard($ctx);
    owLCBoot();
    $id = (int)($ctx['post']['id'] ?? 0);
    $on = !empty($ctx['post']['enabled']) ? 1 : 0;
    if (!DB::val("SELECT COUNT(*) FROM plugin_link_card WHERE id=?", [$id])) {
        Api::json(['ok' => false, 'msg' => '卡片不存在'], 404);
    }
    DB::run("UPDATE plugin_link_card SET enabled=?, updated_at=? WHERE id=?", [$on, time(), $id]);
    Sec::log('link_card_toggle', $ctx['actor']['nickname'], ['id' => $id, 'enabled' => $on]);
    Api::json(['ok' => true, 'msg' => $on ? '已启用' : '已停用',
               'cards' => DB::all("SELECT * FROM plugin_link_card ORDER BY sort ASC, id ASC")]);
});

/** 删除卡片（删除数据 = 敏感操作，前端须走 OwApi.secure 一次性票据） */
Plugin::route('plugin_link_card_del', function (array $ctx) use ($lcGuard): void {
    $lcGuard($ctx);
    owLCBoot();
    $id = (int)($ctx['post']['id'] ?? 0);
    $row = DB::one("SELECT id,name FROM plugin_link_card WHERE id=?", [$id]);
    if (!$row) Api::json(['ok' => false, 'msg' => '卡片不存在'], 404);
    DB::run("DELETE FROM plugin_link_card WHERE id=?", [$id]);
    Sec::log('link_card_del', $ctx['actor']['nickname'], ['id' => $id, 'name' => (string)$row['name']]);
    Api::json(['ok' => true, 'msg' => '已删除', 'cards' => DB::all("SELECT * FROM plugin_link_card ORDER BY sort ASC, id ASC")]);
});
Plugin::sensitive('plugin_link_card_del');

/* ============================ 静态资源 ============================ */

Plugin::asset('js', 'link-card/admin.js');
Plugin::asset('css', 'link-card/style.css');
