<?php
/**
 * 用户名片插件（profile-card）v1.0.4
 *
 * 功能：
 *   - 资料卡头部改为「名片」布局：顶部横幅背景图（默认白色）+ 头像叠压
 *   - 用户在「个人设置 → 资料」里上传图片 / 粘贴链接 / 移除自己的横幅
 *   - 横幅 URL 随核心 user_card 接口一起下发（user.card 钩子写入 u.banner），
 *     前端无需再打一次接口
 *
 * 依赖的核心能力（均不改主程序）：
 *   - user.card       服务端钩子（v1.2.42 起有）：给资料卡数据补 banner 字段
 *   - onCardRendered  前端渲染完成钩子（v1.5.17 新增）：插入横幅 DOM 节点
 *   - Upload::handle  image 类型：横幅上传复用核心上传链路（按内容哈希落盘）
 *   - sidebar.rail / onRail：左侧入口与面板
 *
 * ⚠️ 核心低于 v1.5.17 时（没有 onCardRendered）插件自动降级：
 *    资料卡保持核心原样，面板仍可设置横幅（等核心升级后即刻生效）。
 */
if (!defined('OWLSGO_VERSION')) exit;

/* ===================== 数据库表 ===================== */

function owpcEnsureTable(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $drv = DB::driver();
    $int  = $drv === 'mysql' ? 'INT' : 'INTEGER';
    // ⚠️ banner 走 DB::textCol()：它返回**完整列定义（含列名）**，前面绝不重复写列名 ——
    //    写重了 MySQL 直接 1064（SQLite 容忍，本地全绿骗不了线上，见时光机 2026-10-18 事故）。
    // ⚠️ MySQL 的 TEXT 不能带 DEFAULT，这里不写默认值，读取端自行兜底空串。
    $sql = "CREATE TABLE IF NOT EXISTS plugin_profile_card (
        user_id $int PRIMARY KEY,
        " . DB::textCol('banner') . ",
        updated_at $int NOT NULL DEFAULT 0
    )";
    DB::run($sql);
}
owpcEnsureTable();

/** 取指定用户的横幅 URL（无记录 / 未设置返回空串） */
function owpcBannerOf(int $uid): string
{
    if ($uid <= 0) return '';
    $row = DB::one('SELECT banner FROM plugin_profile_card WHERE user_id=?', [$uid]);
    return $row ? trim((string)$row['banner']) : '';
}

/**
 * 校验并规范化横幅 URL：只允许 http(s) 外链或站内路径（uploads/…、/… 开头）。
 * 不合法返回空串。横幅最终进 CSS url()，引号 / 反斜杠 / 空白 / 尖括号一律拒绝。
 */
function owpcSanitizeBanner(string $url): string
{
    $url = trim($url);
    if ($url === '' || mb_strlen($url) > 500) return '';
    if (preg_match('#["\'\\\\<>\s]#u', $url)) return '';
    if (preg_match('#^https?://#i', $url)) return $url;
    if ($url[0] === '/' || strpos($url, 'uploads/') === 0) return $url;
    return '';
}

/* ===================== 路由 ===================== */

/** 查询指定用户的横幅（资料卡 / 名片面板共用；无需登录 —— 卡片本身游客可见） */
Plugin::route('plugin_profile_card_get', function (array $ctx): void {
    $uid = (int)($ctx['post']['id'] ?? 0);
    if ($uid <= 0) Api::json(['ok' => false, 'msg' => '参数错误'], 400);
    Api::json(['ok' => true, 'banner' => owpcBannerOf($uid)]);
});

/** 保存当前用户的横幅（banner 传空串 = 移除） */
Plugin::route('plugin_profile_card_save', function (array $ctx): void {
    if (($ctx['actor']['kind'] ?? '') !== 'user') {
        Api::json(['ok' => false, 'msg' => '请先登录'], 403);
    }
    $uid   = (int)$ctx['actor']['id'];
    $raw   = trim((string)($ctx['post']['banner'] ?? ''));
    $banner = owpcSanitizeBanner($raw);
    if ($raw !== '' && $banner === '') {
        Api::json(['ok' => false, 'msg' => '横幅地址不合法：只支持 http(s) 链接或站内 uploads/ 路径']);
    }
    DB::upsert('plugin_profile_card', [
        'user_id'    => $uid,
        'banner'     => $banner,
        'updated_at' => time(),
    ], ['user_id']);
    Api::json(['ok' => true, 'msg' => $banner === '' ? '横幅已移除' : '横幅已保存', 'banner' => $banner]);
});

/**
 * 上传横幅图片：复用核心 Upload::handle 的 image 类型（按内容哈希去重落盘）。
 * 只返回 URL，是否采用由前端再调 save 决定（用户可先看预览再保存）。
 */
Plugin::route('plugin_profile_card_upload', function (array $ctx): void {
    if (($ctx['actor']['kind'] ?? '') !== 'user') {
        Api::json(['ok' => false, 'msg' => '请先登录'], 403);
    }
    if (empty($_FILES['file'])) Api::json(['ok' => false, 'msg' => '未接收到文件']);
    [$ok, $urlOrMsg] = Upload::handle($_FILES['file'], 'image');
    Api::json($ok ? ['ok' => true, 'url' => $urlOrMsg] : ['ok' => false, 'msg' => $urlOrMsg]);
});

/* ===================== 钩子 ===================== */

/** 资料卡数据：把横幅 URL 随 user_card 一起下发（前端打开卡片时数据已在手） */
Plugin::on('user.card', function (array &$u, array $actor): void {
    $u['banner'] = owpcBannerOf((int)($u['id'] ?? 0));
});

/* ===================== 入口说明 ===================== */

/**
 * v1.0.1 起横幅管理**移入个人设置**（资料页），不再占左侧 rail 入口：
 * 设置弹窗是站点既定的「改自己资料」所在地（昵称 / 头像都在这），横幅作为
 * 同类资料放这里最顺；单独一个 rail 面板反而多一处入口、两套状态。
 * 注入方式与 signature 插件同一口径：前端包装 OwChat.openSettings /
 * OwChat.saveSettings（核心在保存按钮注释里明确「插件注入的字段挂在
 * saveSettings 上」），服务端零改动。
 */

/* ===================== 静态资源 ===================== */

Plugin::asset('js', 'profile-card/chat.js');
Plugin::asset('css', 'profile-card/style.css');

/**
 * 资料卡横幅样式必须无条件注入（onCardRendered 渲染即用，不依赖面板被打开过），
 * 做法与时光机 / 群聊自动化同一口径：page.head 自己发 <link>。
 * ⚠️ 只做 Plugin::asset('css') 登记不够 —— 核心没有合并引用 CSS 的地方，
 *    不发 <link> 的话 ?action=assets 只会返回 0 字节。
 * ⚠️ 版本参数带插件自己的版本（pv）：核心版本不变时只改插件 CSS 也能刷新缓存。
 */
Plugin::on('page.head', function (): void {
    echo '<link rel="stylesheet" href="?action=assets&type=css&plugin=profile-card&file=style.css&v='
        . OWLSGO_VERSION . '&pv=' . rawurlencode(owpcVersion()) . '">' . "\n";
});

/** 插件自身版本（取自 plugin.json；读不到回落 0，只影响缓存键） */
function owpcVersion(): string
{
    static $v = null;
    if ($v !== null) return $v;
    $v = '0';
    $f = __DIR__ . '/plugin.json';
    if (is_file($f)) {
        $j = json_decode((string)file_get_contents($f), true);
        if (is_array($j) && !empty($j['version'])) $v = (string)$j['version'];
    }
    return $v;
}
