<?php
/**
 * 聊天背景插件（chat-background）
 *
 * ## 职责边界
 *
 * - 用户在「个人设置」中可切换聊天区背景：
 *   ① SVG 图案（Telegram 风的平铺背景）
 *   ② 图片（webp/jpg/png 全屏背景）
 *   ③ 自定义（远程链接 或 上传；上传需要附件上传插件启用，否则只显示远程链接入口）
 * - 每种背景之上可叠加一层 CSS 渐变遮罩（多档预设色），不影响文字可读性。
 * - 后台「聊天背景」页面：管理员可管理前台可选背景库（远程链接 / 上传）、
 *   设置「自定义背景」所需的用户等级阈值。
 *
 * ## 依赖
 *
 * - 上传自定义背景依赖 attachment-manager 插件启用（核心 Upload 类提供落盘能力，
 *   但闸门与体积上限由 attachment-manager 配置）。停用 attachment-manager 时，
 *   前端隐藏「上传」入口，远程链接入口仍可用。
 * - 「等级阈值」需要 level-trust 插件提供 user.level.get 钩子；未安装时阈值不生效。
 *
 * ## 存储
 *
 * - 物理文件：uploads/background/（SVG 也在其中；nginx 默认禁止 /uploads 下 .svg 直访，
 *   故统一通过本插件路由 ?action=plugin_chat_background_file&id=N 输出，并设 nosniff）。
 * - 数据表：
 *   · plugin_chat_bg_items  管理员维护的背景库（preset / admin / remote；
 *                           source 里的历史值 unsplash 仍会照常展示，但不再新产生）
 *   · plugin_chat_bg_user   用户个人选择（user_id 主键，一行一用户）
 *   · plugin_chat_bg_config 插件配置（k-v）
 */
if (!defined('OWLSGO_VERSION')) exit;

/* ============================ 常量与目录 ============================ */

/** uploads 根目录（绝对路径） */
function owCBUploadDir(): string
{
    $cfg = require dirname(__DIR__, 2) . '/core/config.php';
    return (string)$cfg['upload']['dir'];
}

/** uploads 根 URL（相对站点根，如 'uploads'） */
function owCBUploadUrl(): string
{
    $cfg = require dirname(__DIR__, 2) . '/core/config.php';
    return (string)$cfg['upload']['url'];
}

/** 背景文件子目录（uploads/background/） */
function owCBBgDir(): string
{
    return owCBUploadDir() . '/background';
}

/** 确保必要目录存在 */
function owCBEnsureDirs(): void
{
    @mkdir(owCBBgDir(), 0775, true);
    // 防目录列举
    $idx = owCBBgDir() . '/index.html';
    if (!is_file($idx)) @file_put_contents($idx, '');
}

owCBEnsureDirs();

/* ============================ 数据表 ============================ */

DB::run("CREATE TABLE IF NOT EXISTS plugin_chat_bg_items (
    id " . DB::autoId() . ",
    name VARCHAR(120) NOT NULL DEFAULT '',
    kind VARCHAR(16) NOT NULL DEFAULT 'image',   -- svg | image | url
    source VARCHAR(16) NOT NULL DEFAULT 'admin', -- preset | admin | remote（unsplash 为历史值，不再新产生）
    file VARCHAR(255) NOT NULL DEFAULT '',        -- 相对 uploads/background/ 的文件名
    url VARCHAR(500) NOT NULL DEFAULT '',         -- kind=url 时的远程 URL
    thumb VARCHAR(255) NOT NULL DEFAULT '',       -- 缩略图（同目录或远程 URL）
    enabled INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0
)");
DB::run("CREATE TABLE IF NOT EXISTS plugin_chat_bg_user (
    user_id INTEGER PRIMARY KEY,
    mode VARCHAR(16) NOT NULL DEFAULT 'none',     -- none | item | custom
    item_id INTEGER NOT NULL DEFAULT 0,
    custom_url VARCHAR(500) NOT NULL DEFAULT '',  -- 自定义：远程链接
    custom_file VARCHAR(255) NOT NULL DEFAULT '', -- 自定义：上传文件名（uploads/background/）
    overlay VARCHAR(24) NOT NULL DEFAULT 'none', -- 叠加色键（见 owCBOverlays()）
    opacity INTEGER NOT NULL DEFAULT 50,          -- 叠加层不透明度 0-100
    updated_at INTEGER NOT NULL DEFAULT 0
)");
DB::run("CREATE TABLE IF NOT EXISTS plugin_chat_bg_config (
    k VARCHAR(32) PRIMARY KEY,
    " . DB::textCol('v') . "
)");

/* ============================ 配置 ============================ */

function owCBDefaultConfig(): array
{
    return [
        'min_level_custom'  => '0',    // 自定义背景所需等级；0 = 不限制
        'default_overlay'   => 'dark', // 默认叠加色
    ];
}

function owCBConfig(): array
{
    $cfg = owCBDefaultConfig();
    foreach (DB::all('SELECT k, v FROM plugin_chat_bg_config') as $r) {
        if (array_key_exists($r['k'], $cfg)) $cfg[$r['k']] = (string)$r['v'];
    }
    return $cfg;
}

function owCBSaveConfig(array $in): array
{
    $def = owCBDefaultConfig();
    foreach ($in as $k => $v) {
        if (!array_key_exists($k, $def)) continue;
        $v = trim((string)$v);
        if ($k === 'min_level_custom') {
            $v = (string)max(0, (int)$v);
        }
        // upsert 而不是 INSERT OR REPLACE：后者是 SQLite 方言，MySQL 直接 1064
        DB::upsert('plugin_chat_bg_config', ['k' => (string)$k, 'v' => $v], ['k']);
    }
    return owCBConfig();
}

/* ============================ 叠加色预设 ============================ */
/**
 * 叠加色：在背景图之上盖一层渐变 / 半透明色，用于压暗或染色，保证消息气泡可读。
 * 返回 [键 => CSS background 值]。
 *
 * 实现方式：在 .ow-main 上叠加 ::before（图）与 ::after（叠加色）两个伪元素，
 * 叠加层的颜色在这里定义；前端会按用户选择取对应键的 CSS 值注入。
 */
function owCBOverlays(): array
{
    // 键的顺序 = 前台的展示顺序。分两组：
    //   · 前 10 个：单色调「压暗 / 染色」——同色相不同 alpha 的竖向渐变，用来压暗背景图、保文字可读；
    //   · 后 8 个（g- 前缀）：**多色渐变遮罩**——在「遮罩」档单独使用时色彩更明确。
    // 一律用 rgba 自带 alpha（再由用户的「透明度」滑块整体缩放），
    // 所以既能叠在背景图上，也能单独盖在默认底色上。
    // ⚠️ 值里不许出现引号 —— 前台是把它拼进 style="background:…" 属性里的。
    return [
        'none'    => 'none',
        'light'   => 'linear-gradient(rgba(255,255,255,0.35), rgba(255,255,255,0.55))',
        'dark'    => 'linear-gradient(rgba(0,0,0,0.35), rgba(0,0,0,0.55))',
        'dim'     => 'linear-gradient(rgba(0,0,0,0.55), rgba(0,0,0,0.75))',
        'blue'    => 'linear-gradient(rgba(0,90,170,0.30), rgba(0,40,80,0.50))',
        'purple'  => 'linear-gradient(rgba(90,30,160,0.30), rgba(40,0,80,0.50))',
        'green'   => 'linear-gradient(rgba(20,120,60,0.30), rgba(0,60,30,0.50))',
        'orange'  => 'linear-gradient(rgba(180,90,0,0.30), rgba(90,40,0,0.50))',
        'pink'    => 'linear-gradient(rgba(200,60,120,0.30), rgba(110,20,60,0.50))',
        'sepia'   => 'linear-gradient(rgba(120,80,30,0.30), rgba(60,40,10,0.50))',
        // —— 多色渐变遮罩（135° 斜向；alpha 0.58~0.82，让颜色真的看得出来）——
        'g-sunset' => 'linear-gradient(135deg, rgba(255,120,60,0.62), rgba(216,60,140,0.66))',
        'g-ocean'  => 'linear-gradient(135deg, rgba(0,170,205,0.60), rgba(20,60,165,0.70))',
        'g-aurora' => 'linear-gradient(135deg, rgba(30,190,140,0.58), rgba(60,90,225,0.62), rgba(155,55,205,0.62))',
        'g-grape'  => 'linear-gradient(135deg, rgba(150,65,225,0.62), rgba(45,35,125,0.72))',
        'g-ember'  => 'linear-gradient(135deg, rgba(245,85,45,0.62), rgba(250,185,45,0.58))',
        'g-candy'  => 'linear-gradient(135deg, rgba(250,110,190,0.58), rgba(150,110,240,0.62))',
        'g-forest' => 'linear-gradient(135deg, rgba(25,145,95,0.62), rgba(10,55,50,0.72))',
        'g-night'  => 'linear-gradient(135deg, rgba(35,45,100,0.70), rgba(10,10,25,0.82))',
    ];
}

/* ============================ 工具函数 ============================ */

/** 后台管理员鉴权 */
$owCBAdminGuard = function (array $ctx): void {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
};

/** 已登录用户鉴权 */
$owCBUserGuard = function (array $ctx): void {
    if (($ctx['actor']['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '请先登录'], 403);
};

/**
 * 取一条背景库记录的最终展示 URL（前端 CSS 用的 url(...)）。
 * - kind=url：直接用远程 URL
 * - 图片（webp/jpg/png/gif）：直链 uploads/background/filename（nginx 允许非 SVG 直访）
 * - SVG：nginx 拦截 /uploads/*.svg 直访，?action= 路由又需签名（CSS url() 的 GET 不带签名），
 *   故 SVG 走 data URI 内联（base64），唯一不需要改主程序或 nginx 的方案
 */
function owCBItemUrl(array $item): string
{
    if ((string)$item['kind'] === 'url' && (string)$item['url'] !== '') {
        return (string)$item['url'];
    }
    $file = (string)$item['file'];
    if ($file === '') return '';
    $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
    // SVG：nginx 拦截 + ?action= 需签名 → 内联 data URI
    if ($ext === 'svg' || $ext === 'svgz') {
        $abs = owCBBgDir() . '/' . $file;
        if (!is_file($abs)) return '';
        $raw = @file_get_contents($abs);
        if ($raw === false || $raw === '') return '';
        return 'data:image/svg+xml;base64,' . base64_encode($raw);
    }
    // 图片：直链（nginx 仅拦截 uploads 下的 svg/php/html，webp/jpg/png/gif 可直访）
    return owCBUploadUrl() . '/background/' . rawurlencode($file);
}

/**
 * 取用户自定义上传背景文件的直链 URL。
 * 用户上传只允许图片（jpg/png/gif/webp），不允许 SVG，故直链可行。
 */
function owCBUserFileUrl(string $relFile): string
{
    if ($relFile === '') return '';
    return owCBUploadUrl() . '/background/' . implode('/', array_map('rawurlencode', explode('/', $relFile)));
}

/** 取用户当前背景设置（无则返回默认空设置） */
function owCBUserSetting(int $uid): array
{
    $row = DB::one('SELECT * FROM plugin_chat_bg_user WHERE user_id=?', [$uid]);
    if (!$row) {
        return [
            'user_id' => $uid, 'mode' => 'none', 'item_id' => 0,
            'custom_url' => '', 'custom_file' => '', 'overlay' => 'none',
            'opacity' => 50, 'updated_at' => 0,
        ];
    }
    return $row;
}

/**
 * 当前用户是否被允许使用「自定义背景」。
 * - 管理员不受限
 * - 等级插件未安装时按 0 级处理（即任意已登录用户都允许；阈值 0 也表示不限制）
 * - 阈值 > 0 时需 level-trust 提供 user.level.get 钩子
 */
function owCBCanUseCustom(array $actor, array $cfg): array
{
    if (($actor['role'] ?? '') === 'admin') return [true, ''];
    $need = (int)($cfg['min_level_custom'] ?? '0');
    if ($need <= 0) return [true, ''];
    $lv = -1;
    Plugin::fire('user.level.get', [&$lv, (int)$actor['id']]);
    if ($lv < 0) return [true, ''];   // 未安装等级插件 → 不限制
    if ($lv < $need) return [false, '自定义背景需 ' . $need . ' 级解锁（当前 Lv.' . $lv . '）'];
    return [true, ''];
}

/** 当前是否启用了 attachment-manager（决定「上传」入口是否给前端） */
function owCBAttachEnabled(): bool
{
    foreach (Plugin::enabledPlugins() as $n) {
        if ($n === 'attachment-manager') return true;
    }
    return false;
}

/**
 * 校验扩展名 + 真实 MIME，并存到 uploads/background/。
 * 复用 attachment-manager 的安全表，但本插件负责落盘（kind=background，独立子目录）。
 */
function owCBStoreUpload(array $f): array
{
    if (!owCBAttachEnabled()) return [false, '未启用附件上传插件，无法上传背景'];
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) return [false, '上传失败（错误码 ' . $err . '）'];
    $tmp  = (string)($f['tmp_name'] ?? '');
    $orig = (string)($f['name'] ?? 'bg');
    if ($tmp === '' || !is_file($tmp)) return [false, '临时文件不可读'];

    // 复用 attachment-manager 的安全表（若该插件停用则用本插件的兜底白名单）
    $safe = function_exists('owATExtMime') ? owATExtMime() : [
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
        'gif' => ['image/gif'], 'webp' => ['image/webp'],
    ];
    // 仅图片类（svg 不允许上传——脚本注入风险，preset 由管理员导入）
    $allow = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (class_exists('finfo')) {
        $fi = new finfo(FILEINFO_MIME_TYPE);
        $mime = strtolower((string)$fi->file($tmp));
    } else {
        $mime = 'application/octet-stream';
    }
    $extIn = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
    if ($extIn === '' || !in_array($extIn, $allow, true) || !in_array($mime, $safe[$extIn] ?? [], true)) {
        // 真实内容兜底
        $real = null;
        foreach ($allow as $e) {
            if (in_array($mime, $safe[$e], true)) { $real = $e; break; }
        }
        if ($real === null) return [false, '不支持的背景类型（' . $mime . '），仅允许：' . implode(' / ', $allow)];
        $extIn = $real;
    }

    $max = 10 * 1048576;   // 10MB
    if (function_exists('owATMaxBytes')) $max = owATMaxBytes();
    $size = (int)@filesize($tmp);
    if ($size > $max) return [false, '文件超过 ' . round($max / 1048576, 1) . ' MB 限制'];

    // 内容哈希命名 + 去重
    $sha = @hash_file('sha256', $tmp);
    if ($sha === false || $sha === '') return [false, '保存失败'];
    $hash = substr($sha, 0, 32);
    $sub  = substr($hash, 0, 2);
    $dir  = owCBBgDir() . '/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return [false, '保存失败'];
    $name = $hash . '.' . $extIn;
    $abs  = $dir . '/' . $name;
    if (!is_file($abs)) {
        if (!@move_uploaded_file($tmp, $abs)) return [false, '保存失败'];
        @chmod($abs, 0644);
    } else {
        @unlink($tmp);
    }
    return [true, $sub . '/' . $name];
}

/**
 * 从 设计文档/background/ 导入预设背景。
 * 仅在管理员手动触发（admin_import_presets）时调用；幂等：按文件名去重。
 */
function owCBImportPresets(): int
{
    $root = dirname(__DIR__, 2) . '/设计文档/background';
    $imported = 0;
    foreach (['svg' => 'svg', 'webp' => 'image'] as $sub => $kind) {
        $srcDir = $root . '/' . $sub;
        if (!is_dir($srcDir)) continue;
        foreach (glob($srcDir . '/*') ?: [] as $f) {
            if (!is_file($f)) continue;
            $name = basename($f);
            // 跳过已有同名预设
            $ex = DB::val('SELECT id FROM plugin_chat_bg_items WHERE source=? AND file=?', ['preset', $name]);
            if ((int)$ex > 0) continue;
            $dest = owCBBgDir() . '/' . $name;
            if (!is_file($dest)) {
                if (!@copy($f, $dest)) continue;
                @chmod($dest, 0644);
            }
            DB::insert('plugin_chat_bg_items', [
                'name' => pathinfo($name, PATHINFO_FILENAME),
                'kind' => $kind,
                'source' => 'preset',
                'file' => $name,
                'url' => '',
                'thumb' => '',
                'enabled' => 1,
                'sort_order' => 0,
                'created_at' => time(),
            ]);
            $imported++;
        }
    }
    return $imported;
}

/** 取一条 item 的绝对物理路径（删除时用） */
function owCBItemAbsPath(array $item): ?string
{
    $f = (string)$item['file'];
    if ($f === '') return null;
    $abs = owCBBgDir() . '/' . $f;
    return is_file($abs) ? $abs : null;
}

/* ============================ 后台页面 ============================ */

Plugin::adminPage('chat-background', '聊天背景', function () {
    return '<h2>聊天背景</h2>'
        . '<p class="ow-admin-desc">管理前台可选背景库（SVG 图案 / 图片 / 远程链接），'
        . '设置「自定义背景」所需等级。停用本插件后聊天区恢复默认背景。</p>'

        // 配置区
        . '<div class="ow-card">'
        . '<h3 style="margin-bottom:10px">插件配置</h3>'
        . '<div class="ow-form-row">'
        . '<div class="ow-form-item" style="min-width:160px"><label>自定义背景所需等级</label>'
        . '<input class="ow-input" id="owCBMinLevel" type="number" min="0" value="0">'
        . '<p style="font-size:12px;color:var(--ow-text-sub);margin-top:4px">0 = 不限制；需启用「等级信任」插件。</p></div>'
        . '</div>'
        . '<button class="ow-btn ow-btn-primary" onclick="OwCB.saveCfg()">保存配置</button>'
        . '</div>'

        // 背景库
        . '<div class="ow-card">'
        . '<div style="display:flex;-webkit-display:flex;align-items:center;-webkit-align-items:center;justify-content:space-between;-webkit-justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;-webkit-flex-wrap:wrap;gap:8px">'
        . '<h3 style="margin:0">背景库</h3>'
        . '<div style="display:flex;-webkit-display:flex;gap:6px;flex-wrap:wrap;-webkit-flex-wrap:wrap">'
        . '<button class="ow-btn ow-btn-ghost" onclick="OwCB.importPresets()">从设计文档导入预设</button>'
        . '<button class="ow-btn ow-btn-primary" onclick="OwCB.openAdd()">添加背景</button>'
        . '</div></div>'
        . '<div id="owCBList"></div>'
        . '</div>';
});

/* ============================ 后台路由 ============================ */

Plugin::route('plugin_chat_background_admin_list', function (array $ctx) use ($owCBAdminGuard) {
    $owCBAdminGuard($ctx);
    $rows = DB::all('SELECT * FROM plugin_chat_bg_items ORDER BY sort_order ASC, id DESC');
    $out = [];
    foreach ($rows as $r) {
        $r['preview']  = owCBItemUrl($r);
        $r['kind_cn']  = ((string)$r['kind'] === 'svg') ? 'SVG 图案' : ((string)$r['kind'] === 'url' ? '远程链接' : '图片');
        // source_cn 里的 unsplash 是历史值映射：v1.1.2 删了 Unsplash 搜索后不再新产生，
        // 但存量行的 source 仍是 'unsplash'，去掉映射会让后台列表露出原始 slug
        $r['source_cn'] = ['preset' => '预设', 'admin' => '管理员上传', 'unsplash' => 'Unsplash', 'remote' => '远程链接'][(string)$r['source']] ?? (string)$r['source'];
        $r['updated_at'] = (int)$r['created_at'];
        $out[] = $r;
    }
    Api::json(['ok' => true, 'data' => $out, 'attach_enabled' => owCBAttachEnabled()]);
});

Plugin::route('plugin_chat_background_admin_save', function (array $ctx) use ($owCBAdminGuard) {
    $owCBAdminGuard($ctx);
    $p = $ctx['post'];
    $id = (int)($p['id'] ?? 0);
    $kind = (string)($p['kind'] ?? 'image');
    if (!in_array($kind, ['svg', 'image', 'url'], true)) Api::json(['ok' => false, 'msg' => '非法类型'], 400);
    $name = mb_substr(trim((string)($p['name'] ?? '')), 0, 120);
    if ($name === '') $name = '未命名背景';
    $url = trim((string)($p['url'] ?? ''));
    $file = trim((string)($p['file'] ?? ''));
    if ($kind === 'url') {
        // v1.3.46：只收 https://。本站已强制 HTTPS，存 http:// 外链会在页面里变成
        // 混合内容被浏览器拦掉（背景图直接不显示），报错比静默失效更难排查，所以在入口就拒绝。
        if ($url === '' || !preg_match('#^https://#i', $url)) Api::json(['ok' => false, 'msg' => '远程链接需以 https:// 开头'], 400);
        $file = '';
    } else {
        if ($file === '') Api::json(['ok' => false, 'msg' => '缺少背景文件'], 400);
    }
    $row = [
        'name' => $name, 'kind' => $kind, 'source' => (string)($p['source'] ?? 'admin'),
        'file' => $file, 'url' => $url, 'thumb' => trim((string)($p['thumb'] ?? '')),
        'enabled' => isset($p['enabled']) ? (int)($p['enabled'] ? 1 : 0) : 1,
        'sort_order' => (int)($p['sort_order'] ?? 0),
    ];
    if ($id > 0) {
        DB::run('UPDATE plugin_chat_bg_items SET name=?,kind=?,source=?,file=?,url=?,thumb=?,enabled=?,sort_order=? WHERE id=?',
            [$row['name'], $row['kind'], $row['source'], $row['file'], $row['url'], $row['thumb'], $row['enabled'], $row['sort_order'], $id]);
    } else {
        $row['created_at'] = time();
        DB::insert('plugin_chat_bg_items', $row);
        $id = (int)DB::val('SELECT last_insert_rowid()');
    }
    Api::json(['ok' => true, 'msg' => '已保存', 'id' => $id]);
});

Plugin::route('plugin_chat_background_admin_upload', function (array $ctx) use ($owCBAdminGuard) {
    $owCBAdminGuard($ctx);
    if (empty($ctx['files']['file'])) Api::json(['ok' => false, 'msg' => '未接收到文件'], 400);
    [$ok, $relOrMsg] = owCBStoreUpload($ctx['files']['file']);
    if (!$ok) Api::json(['ok' => false, 'msg' => $relOrMsg], 400);
    Api::json(['ok' => true, 'file' => $relOrMsg]);
});

Plugin::route('plugin_chat_background_admin_delete', function (array $ctx) use ($owCBAdminGuard) {
    $owCBAdminGuard($ctx);
    $id = (int)($ctx['post']['id'] ?? 0);
    if ($id <= 0) Api::json(['ok' => false, 'msg' => '非法 ID'], 400);
    $item = DB::one('SELECT * FROM plugin_chat_bg_items WHERE id=?', [$id]);
    if (!$item) Api::json(['ok' => false, 'msg' => '背景不存在'], 404);
    // preset 不允许直接删（避免反复导入）；其它类型可删物理文件
    if ((string)$item['source'] !== 'preset') {
        $abs = owCBItemAbsPath($item);
        if ($abs) @unlink($abs);
    }
    DB::run('DELETE FROM plugin_chat_bg_items WHERE id=?', [$id]);
    // 已选此背景的用户回退到 none
    DB::run('UPDATE plugin_chat_bg_user SET mode=?, item_id=0 WHERE item_id=?', ['none', $id]);
    Api::json(['ok' => true, 'msg' => '已删除']);
});
Plugin::sensitive('plugin_chat_background_admin_delete');

Plugin::route('plugin_chat_background_admin_toggle', function (array $ctx) use ($owCBAdminGuard) {
    $owCBAdminGuard($ctx);
    $id = (int)($ctx['post']['id'] ?? 0);
    $en = (int)($ctx['post']['enabled'] ?? 0) ? 1 : 0;
    DB::run('UPDATE plugin_chat_bg_items SET enabled=? WHERE id=?', [$en, $id]);
    Api::json(['ok' => true, 'msg' => $en ? '已启用' : '已停用']);
});

Plugin::route('plugin_chat_background_admin_import_presets', function (array $ctx) use ($owCBAdminGuard) {
    $owCBAdminGuard($ctx);
    $n = owCBImportPresets();
    Api::json(['ok' => true, 'msg' => '导入完成，新增 ' . $n . ' 项（同名已跳过）']);
});

Plugin::route('plugin_chat_background_admin_config_get', function (array $ctx) use ($owCBAdminGuard) {
    $owCBAdminGuard($ctx);
    Api::json(['ok' => true, 'config' => owCBConfig(), 'attach_enabled' => owCBAttachEnabled(), 'overlays' => owCBOverlays()]);
});

Plugin::route('plugin_chat_background_admin_config_save', function (array $ctx) use ($owCBAdminGuard) {
    $owCBAdminGuard($ctx);
    $cfg = owCBSaveConfig($ctx['post']);
    Api::json(['ok' => true, 'msg' => '配置已保存', 'config' => $cfg]);
});

/* ============================ 用户路由 ============================ */

/**
 * 用户取设置 + 可选背景清单 + 配置（等级阈值、附件是否启用、叠加色清单）。
 * 一个接口返回所有 UI 所需信息，减少 AJAX 往返。
 */
Plugin::route('plugin_chat_background_user_load', function (array $ctx) {
    if (($ctx['actor']['kind'] ?? '') !== 'user') Api::json(['ok' => true, 'setting' => null, 'items' => [], 'cfg' => null, 'attach_enabled' => false, 'overlays' => owCBOverlays()]);
    $uid = (int)$ctx['actor']['id'];
    $cfg = owCBConfig();
    $items = DB::all('SELECT * FROM plugin_chat_bg_items WHERE enabled=1 ORDER BY sort_order ASC, id DESC');
    $list = [];
    foreach ($items as $r) {
        $list[] = [
            'id' => (int)$r['id'], 'name' => (string)$r['name'],
            'kind' => (string)$r['kind'], 'preview' => owCBItemUrl($r),
        ];
    }
    [$canCustom, $customReason] = owCBCanUseCustom($ctx['actor'], $cfg);
    $st = owCBUserSetting($uid);
    // 自定义 URL 不可见前先打码（避免泄露给其他用户；当前是本人查询，原样返回）
    Api::json([
        'ok' => true,
        'setting' => [
            'mode' => (string)$st['mode'], 'item_id' => (int)$st['item_id'],
            'custom_url' => (string)$st['custom_url'], 'custom_file' => (string)$st['custom_file'],
            'custom_preview' => owCBUserFileUrl((string)$st['custom_file']),
            'overlay' => (string)$st['overlay'], 'opacity' => (int)$st['opacity'],
        ],
        'items' => $list,
        'cfg' => [
            'min_level_custom' => (int)$cfg['min_level_custom'],
            'can_custom' => $canCustom, 'custom_reason' => $customReason,
        ],
        'attach_enabled' => owCBAttachEnabled(),
        'overlays' => owCBOverlays(),
    ]);
});

/** 用户保存设置 */
Plugin::route('plugin_chat_background_user_save', function (array $ctx) {
    if (($ctx['actor']['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '请先登录'], 403);
    $uid = (int)$ctx['actor']['id'];
    $p = $ctx['post'];
    $mode = (string)($p['mode'] ?? 'none');
    if (!in_array($mode, ['none', 'item', 'custom'], true)) $mode = 'none';

    $cfg = owCBConfig();
    if ($mode === 'custom') {
        [$ok, $reason] = owCBCanUseCustom($ctx['actor'], $cfg);
        if (!$ok) Api::json(['ok' => false, 'msg' => $reason], 403);
    }
    $itemId = 0;
    if ($mode === 'item') {
        $itemId = (int)($p['item_id'] ?? 0);
        if ($itemId > 0) {
            $ex = DB::val('SELECT id FROM plugin_chat_bg_items WHERE id=? AND enabled=1', [$itemId]);
            if ((int)$ex === 0) Api::json(['ok' => false, 'msg' => '背景不存在或已停用'], 400);
        } else {
            $mode = 'none';
        }
    }
    $customUrl = '';
    $customFile = '';
    if ($mode === 'custom') {
        $customUrl = trim((string)($p['custom_url'] ?? ''));
        $customFile = trim((string)($p['custom_file'] ?? ''));
        // 至少要有 URL 或文件
        if ($customUrl === '' && $customFile === '') {
            Api::json(['ok' => false, 'msg' => '请提供远程链接或上传文件'], 400);
        }
        if ($customUrl !== '' && !preg_match('#^https?://#i', $customUrl)) {
            Api::json(['ok' => false, 'msg' => '远程链接需以 http:// 或 https:// 开头'], 400);
        }
        if ($customFile !== '' && !owCBValidateRelPath($customFile)) {
            $customFile = '';
        }
    }
    $overlay = (string)($p['overlay'] ?? 'none');
    $overlays = owCBOverlays();
    if (!isset($overlays[$overlay])) $overlay = 'none';
    $opacity = max(0, min(100, (int)($p['opacity'] ?? 50)));

    // user_id 是主键 → upsert 三驱动通用（INSERT OR REPLACE 只有 SQLite 认）
    DB::upsert('plugin_chat_bg_user', [
        'user_id'     => $uid,
        'mode'        => $mode,
        'item_id'     => $itemId,
        'custom_url'  => $customUrl,
        'custom_file' => $customFile,
        'overlay'     => $overlay,
        'opacity'     => $opacity,
        'updated_at'  => time(),
    ], ['user_id']);
    Api::json(['ok' => true, 'msg' => '背景已更新']);
});

/** 校验 custom_file 必须是 uploads/background/ 下的相对路径 */
function owCBValidateRelPath(string $p): bool
{
    if ($p === '') return false;
    if (strpos($p, '..') !== false) return false;
    if (strpos($p, "/") !== false || strpos($p, "\\") !== false) {
        // 允许 子目录/文件名 形式
        if (!preg_match('#^[a-zA-Z0-9_\-]+/[a-zA-Z0-9_\-]+\.(jpg|jpeg|png|gif|webp)$#i', $p)) return false;
    } else {
        if (!preg_match('#^[a-zA-Z0-9_\-]+\.(jpg|jpeg|png|gif|webp)$#i', $p)) return false;
    }
    $abs = owCBBgDir() . '/' . str_replace('\\', '/', $p);
    return is_file($abs);
}

/** 用户上传自定义背景 */
Plugin::route('plugin_chat_background_user_upload', function (array $ctx) {
    if (($ctx['actor']['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '请先登录'], 403);
    $cfg = owCBConfig();
    [$ok, $reason] = owCBCanUseCustom($ctx['actor'], $cfg);
    if (!$ok) Api::json(['ok' => false, 'msg' => $reason], 403);
    if (!owCBAttachEnabled()) Api::json(['ok' => false, 'msg' => '未启用附件上传插件，无法上传'], 400);
    if (empty($ctx['files']['file'])) Api::json(['ok' => false, 'msg' => '未接收到文件'], 400);
    [$ok2, $relOrMsg] = owCBStoreUpload($ctx['files']['file']);
    if (!$ok2) Api::json(['ok' => false, 'msg' => $relOrMsg], 400);
    Api::json(['ok' => true, 'file' => $relOrMsg, 'preview' => owCBUserFileUrl($relOrMsg)]);
});

/* ============================ 文件输出路由 ============================ */

/**
 * 按 id 输出某个背景库项的物理文件。
 * 与 ?action=assets 不同：这里是非合并的单一二进制输出，
 * 服务端读 uploads/background/<file> 后 echo，并对 svg 强制 image/svg+xml。
 */
Plugin::route('plugin_chat_background_file', function (array $ctx) {
    // 此路由不通过 Plugin::dispatch 的 ctx.files 流程，而是直接 GET 命中（见 index.php 的 action 分发）
    // 实际入口在 index.php：未在核心 switch 命中的 action 会进 Plugin::dispatch。
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { http_response_code(404); echo 'Not Found'; exit; }
    $item = DB::one('SELECT * FROM plugin_chat_bg_items WHERE id=?', [$id]);
    if (!$item) { http_response_code(404); echo 'Not Found'; exit; }
    if ((int)$item['enabled'] !== 1) { http_response_code(404); echo 'Not Found'; exit; }
    $file = (string)$item['file'];
    if ($file === '' || strpos($file, '..') !== false) { http_response_code(404); echo 'Not Found'; exit; }
    $abs = owCBBgDir() . '/' . $file;
    if (!is_file($abs)) { http_response_code(404); echo 'Not Found'; exit; }
    $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
    $mime = [
        'svg'  => 'image/svg+xml',
        'jpg'  => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png'  => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'bmp' => 'image/bmp',
    ][$ext] ?? 'application/octet-stream';
    // 缓存 7 天：item_id + updated_at 不变即同内容
    $etag = '"' . (int)$item['id'] . '-' . (int)(@filemtime($abs)) . '"';
    $ims = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    if ($ims === $etag) { http_response_code(304); exit; }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)@filesize($abs));
    header('Cache-Control: public, max-age=604800');
    header('ETag: ' . $etag);
    header('X-Content-Type-Options: nosniff');
    readfile($abs);
    exit;
});

/**
 * 按 file 相对路径输出（仅用户上传的自定义背景用；file 来自 owCBStoreUpload 返回值）。
 * 不查 DB，因为用户上传后不一定入库（用户设置存的是 file 路径，不入 items 表）。
 */
Plugin::route('plugin_chat_background_path', function (array $ctx) {
    $file = (string)($_GET['file'] ?? '');
    if ($file === '' || !owCBValidateRelPath($file)) { http_response_code(404); echo 'Not Found'; exit; }
    $abs = owCBBgDir() . '/' . str_replace('\\', '/', $file);
    if (!is_file($abs)) { http_response_code(404); echo 'Not Found'; exit; }
    $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
    $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
             'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp'][$ext] ?? 'application/octet-stream';
    $etag = '"' . md5($file) . '-' . (int)(@filemtime($abs)) . '"';
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string)@filesize($abs));
    header('Cache-Control: private, max-age=604800');
    header('ETag: ' . $etag);
    header('X-Content-Type-Options: nosniff');
    readfile($abs);
    exit;
});

/* ============================ page.head：注入用户背景 CSS ============================ */
/**
 * 仅在聊天页（?page=chat 或缺省）+ 已登录用户时应用。
 * 既没有背景图、也没有叠加色时不注入任何东西（保持核心默认）。
 */
Plugin::on('page.head', function () {
    $page = (string)($_GET['page'] ?? '');
    if ($page !== '' && $page !== 'chat') return;   // 仅聊天页应用
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $u = Auth::user();
    if (!$u) return;
    $uid = (int)$u['id'];
    $st = owCBUserSetting($uid);

    // 解析最终 URL（「默认」模式下为空）
    $url = '';
    $tile = false;   // 是否平铺（SVG 图案）
    if ((string)$st['mode'] === 'item' && (int)$st['item_id'] > 0) {
        $item = DB::one('SELECT * FROM plugin_chat_bg_items WHERE id=? AND enabled=1', [(int)$st['item_id']]);
        if ($item) {
            $url = owCBItemUrl($item);
            $tile = (string)$item['kind'] === 'svg';
        }
    } elseif ((string)$st['mode'] === 'custom') {
        if ((string)$st['custom_url'] !== '') {
            $url = (string)$st['custom_url'];
        } elseif ((string)$st['custom_file'] !== '') {
            $url = owCBUserFileUrl((string)$st['custom_file']);
        }
    }

    $overlays = owCBOverlays();
    $ovr = (string)$st['overlay'];
    if (!isset($overlays[$ovr])) $ovr = 'none';
    $ovrCss = $overlays[$ovr];
    $hasImg = ($url !== '');
    $hasOvr = ($ovrCss !== 'none');
    // 既无图又无叠加色 → 不注入，保持核心默认。
    // 「默认」模式（无图）也允许只叠一层色：叠加色本就是独立于背景图的选择，
    // 若只在有图时才有意义，用户在默认模式里点颜色就等于白点。
    // 前端 buildCss() 是同一套判断，两边必须一致，否则「保存后」与「刷新后」长相不同。
    if (!$hasImg && !$hasOvr) return;

    $opacity = max(0, min(100, (int)$st['opacity'])) / 100;

    // 生成 CSS：注入到 .ow-main 的伪元素
    $bgCss = $tile
        ? "background-image: url('" . $url . "'); background-repeat: repeat; background-size: auto;"
        : "background-image: url('" . $url . "'); background-repeat: no-repeat; background-size: cover; background-position: center;";

    // 叠加层：::after 半透明（opacity 控制 .ow-main::after 自身的透明度，
    // 用 background + opacity 影响子元素，故改用「叠加色 + 半透明 alpha」合成）。
    // 这里用简化方案：直接把叠加色按 opacity 重新生成（渐变里的 alpha 已固定，外层再叠 opacity）。
    // ⚠️ 无图、只有叠加色时**不动** .ow-main 自身底色 —— 否则聊天区会从 --ow-bg-chat 变成
    //    body 的 --ow-bg-sub（深色下两者不同色），白白改了聊天区外观。
    // 背景层用**负 z-index**，并让 .ow-main 自成层叠上下文（position:relative + z-index:0）：
    // 在 .ow-main 的层叠上下文里，z-index:-1 的伪元素排在「自身底色之后、一切常规流内容之前」，
    // 于是背景图/遮罩自动落在**所有**子级底下 —— 顶栏、输入栏、消息区，以及任何插件往
    // .ow-main 里插的直接子级（announcements 插件就是 main.insertBefore(公告条, #owMessages) 插进去的）。
    //
    // ⚠️ 为什么不逐个枚举子级抬 z-index（写 > * 或点名清单）—— 那种写法已经连出两次事故：
    //   ① 写成 > * 会连表情面板 #owEmojiPanel、GIF 面板 .ow-gif-panel 一起命中，
    //      把它们的 position:absolute 改写成 relative，面板掉进文档流并错位；
    //   ② 收窄成只抬 .ow-topbar/.ow-messages/.ow-inputbar 后，不在名单里的公告条 #oaBar
    //      又被背景图盖住（z-index:0 的定位伪元素按绘制顺序排在普通流内容之上）——
    //      用户看到的是「群公告条变透明」，其实是它整条被背景图压住了。
    //   负 z-index 方案不依赖任何名单，子级增删都不必再维护。前端 buildCss() 同此。
    // 昵称配色变量：12 个色相（每 30° 一个）共享一个亮度旋钮。
    // 变量挂在 body.ow-chat-body 上而不是 :root —— 只作用聊天页；插件停用或不设背景时
    // 随整块 <style> 一起消失。[data-theme="dark"] 挂在 <html> 上，能正确后置覆盖。
    $nickVars = 'body.ow-chat-body{--ow-cb-nick-l:42%;';
    for ($i = 0; $i < 12; $i++) {
        $nickVars .= '--ow-cb-nick-' . $i . ':hsl(' . ($i * 30) . ',68%,var(--ow-cb-nick-l));';
    }
    $nickVars .= '}[data-theme="dark"] body.ow-chat-body{--ow-cb-nick-l:68%;}';

    echo '<style id="ow-cb-style">'
       . 'body.ow-chat-body .ow-main{position:relative;z-index:0;' . ($hasImg ? 'background-color:transparent;' : '') . '}'
       . ($hasImg ? 'body.ow-chat-body .ow-main::before{content:"";position:absolute;inset:0;z-index:-1;pointer-events:none;' . $bgCss . '}' : '')
       . ($hasOvr
           ? 'body.ow-chat-body .ow-main::after{content:"";position:absolute;inset:0;z-index:-1;pointer-events:none;background:' . $ovrCss . ';opacity:' . number_format($opacity, 2, '.', '') . ';}'
           : '')
       . 'body.ow-chat-body .ow-messages{background-color:transparent;background-image:none;}'
       // 顶栏与输入栏**不做任何覆盖**：按用户要求完全恢复核心的不透明底色（--ow-bg-chat），
       // 连一点点透明也不要。故这里既没有 color-mix 半透明规则，也没有配套的
       // .ow-input:not(:focus) 规则（那条是为了保住聚焦态底色，随半透明一起去掉）。
       // ⚠️ 与前端 chat.js 的 buildCss() 必须一致。
       // 左侧图标条与侧栏同样半透明。⚠️ 它们**不在** .ow-main 内，是它的兄弟节点，
       // 故选择器不能带 .ow-main；透出来的是 .ow-layout 的 --ow-bg（浅色为白），
       // 即「没有背景图在下面」—— 效果是朝页面底色略提亮，而不是透出背景图。
       . 'body.ow-chat-body .ow-rail,'
       . 'body.ow-chat-body .ow-sidebar{background-color:color-mix(in srgb, var(--ow-bg-chat) 78%, transparent);}'
       // 昵称彩色化：颜色值以 CSS 变量下发，具体取哪个由前端按昵称哈希决定
       // （见 chat.js 的 paintNick）。为什么不在 CSS 里直接写死：CSS 无法按元素文本做哈希，
       // 做不出「每人一色」。为什么这套调色板一定「非白非黑非灰」：12 个色相绕色环每 30° 一个、
       // 饱和度固定 68%，只把亮度收成单变量 --ow-cb-nick-l（浅色 42% / 深色 68%）——
       // 主题切换只改它一处，前端不必重新上色。
       . $nickVars
       . '</style>';
});

/* ============================ 资源 ============================ */

Plugin::on('page.head', function () {
    // 注入合并 CSS（attachment-manager 也是这样做的）
    echo '<link rel="stylesheet" href="?action=assets&type=css&cb=' . OWLSGO_VERSION . '">';
});

Plugin::asset('css', 'chat-background/chat.css');
Plugin::asset('js', 'chat-background/chat.js');
Plugin::asset('js', 'chat-background/admin.js');
