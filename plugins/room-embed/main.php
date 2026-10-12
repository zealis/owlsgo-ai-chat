<?php
/**
 * 群聊嵌入插件（room-embed）v1.1.0
 *
 * 功能：
 *   - 群主/管理员可在「群聊信息」面板获取该群的 iframe 嵌入代码
 *   - 外部网站（博客等）将代码贴入页面，右下角即出现一个内置聊天室
 *   - 嵌入页复用主程序聊天页（?page=chat&room=ID&embed=1），不修改主程序
 *   - 嵌入模式下通过 page.head 注入精简 CSS，仅保留「群名顶栏 + 消息 + 输入」
 *
 * 不修改主程序的依据：
 *   - 页面入口用现有 ?page=chat&room=ID 直达群聊（核心已支持，renderChat 内 directHit）
 *   - UI 精简走 page.head 钩子注入 CSS（核心已提供）
 *   - 「嵌入代码」入口走 OwChat.onRoomEdit 前端扩展点（核心已提供，v1.1.10 起契约稳定）
 *   - 消息收发/轮询全部复用核心 API（rooms/history/poll/send）
 *
 * v1.1.0 修复记录（2026-10-12，全部在插件内完成，主程序零改动）：
 *   1. .ow-msg-bubble 在核心不存在（真类是 .ow-msg-content）——原「气泡紧凑」规则是死代码，
 *      嵌入页气泡一直保持默认 14px/8×12 内边距。已改为 .ow-msg-content。
 *   2. .ow-layout{display:block} 把 flex 列容器改成块容器，.ow-body 失去 flex 定高，
 *      消息区会撑到内容高度；叠加核心 body.ow-chat-body{overflow:hidden}，
 *      历史消息一长输入栏直接被裁出视口（小窗里再也够不着发送框）。已删除该规则。
 *   3. 漏隐藏 .ow-brand / .ow-rail / .ow-mask：380×560 小窗被品牌条与图标条白吃 100+px，
      且 ≤720px 窄iframe 里 rail 仍是左侧 52px 图标条。已一并隐藏，嵌入态只留主区。
 *   4. 顶栏在嵌入态压高（57→44px）；生成给外部站点的 iframe 代码改响应式
 *      （max-width/max-height + dvh 渐进增强），手机上不再溢出屏幕。
 */
if (!defined('OWLSGO_VERSION')) exit;

/* ===================== 嵌入模式 CSS ===================== */
// 仅当 URL 带 ?embed=1 时注入，把聊天页精简成适合 iframe 的小窗形态。
// 目标形态：顶栏（群名，44px）+ 消息区 + 输入栏，其余框架全部隐藏。
// 颜色一律引用核心 --ow-* 变量（带回落值仅作核心样式表未加载时的兜底，不参与正常渲染）。
Plugin::on('page.head', function (): void {
    if (!isset($_GET['embed']) || $_GET['embed'] !== '1') return;
    echo <<<'CSS'
<style>
/* ===== 嵌入模式：把聊天页压成右下角小窗 ===== */
/* 品牌条 / 图标 rail / 会话列表 / 右侧群信息栏 / 浮层遮罩：小窗里全部是占地方的框架 */
.ow-brand,.ow-rail,.ow-rail-foot,.ow-sidebar,.ow-online,.ow-mask{display:none!important}
/* 顶栏紧凑：核心高度钉在 var(--ow-topbar-h)（57px，与右侧栏首行对齐用），
   嵌入态没有右侧栏，压到 44px 把高度让给消息区 */
.ow-topbar{height:44px!important;padding:0 12px!important}
.ow-topbar .ow-only-mobile{display:none!important}   /* 窄屏「菜单」抽屉按钮：没有侧栏可开 */
.ow-topbar .ow-icon-btn{display:none!important}      /* 「群聊信息」竖三点：右栏已隐藏 */
.ow-room-name{font-size:14px!important;margin:0!important}
.ow-latency{display:none!important}
.ow-tag{display:none!important}
.ow-main{border:none!important;min-width:0!important}
.ow-messages{padding:8px 10px!important}
.ow-msg{margin-bottom:6px!important;gap:8px!important}
/* 气泡紧凑：核心 .ow-msg-content 为 14px 字号 / 8×12 内边距 / 8px 圆角。
   宽度不在此处再收——外层 .ow-msg-body 已限 max-width:min(560px,78%)，二次收窄会提前折行 */
.ow-msg-content{font-size:13px!important;padding:6px 10px!important}
.ow-msg-meta{font-size:11px!important;margin-bottom:2px!important}
.ow-inputbar{padding:6px 8px!important;border-top:1px solid var(--ow-border,#eee)}
.ow-toolbar{gap:2px!important}
.ow-input-row{gap:6px!important}
.ow-input{font-size:13px!important;min-height:32px!important;padding:6px 8px!important}
.ow-send{width:34px!important;height:34px!important;min-width:34px!important}
/* 表情面板：绝对定位于 .ow-main，底缘避开紧凑输入栏（工具条 ~30 + 输入行 ~34 + 上下padding 12 ≈ 76px） */
.ow-emoji-panel{bottom:80px!important}
/* 嵌入态弹窗（如「是否加入群聊」确认、头像设置）：核心弹窗在 ≤720px 已自适应，这里只兜个上限 */
.ow-modal{width:92%!important;max-width:360px!important}
.ow-ctx-menu{font-size:12px!important}
/* 嵌入页背景与外层站点融合 */
body.ow-chat-body{background:var(--ow-bg,#fff)!important}
</style>
CSS;
});

/* ===================== 前端交互脚本 ===================== */
Plugin::asset('js', 'room-embed/chat.js');

/* ===================== 后台说明页 ===================== */
Plugin::adminPage('room-embed', '群聊嵌入', function () {
    return '<h2>群聊嵌入</h2>'
        . '<p class="ow-admin-desc">允许用户将自己创建的群聊以 iframe 方式嵌入到博客等网站，作为右下角内置聊天室。本插件不修改主程序，完全复用核心聊天能力。</p>'
        . '<div class="ow-card">'
        . '<h3 style="margin-top:0">使用方法</h3>'
        . '<ol style="line-height:2;color:var(--ow-text,#333)">'
        . '<li>群主或超级管理员进入任意群聊，点击右上角「⋮」打开群聊信息面板</li>'
        . '<li>在「群聊信息」下方点击「嵌入代码」</li>'
        . '<li>复制生成的 iframe 代码，粘贴到目标网站的 HTML 中即可</li>'
        . '</ol>'
        . '<h3>嵌入效果</h3>'
        . '<p style="color:var(--ow-text,#333)">嵌入后，网站右下角会出现一个聊天小窗，访客可直接在其中发言（受站点「游客发言」开关限制）。所有消息、禁言、敏感词等规则与主站完全一致。小窗只保留群名、消息与输入框，站点品牌条与侧栏不进入嵌入页。</p>'
        . '<h3>注意事项</h3>'
        . '<ul style="line-height:2;color:var(--ow-text,#333)">'
        . '<li>仅<strong>公开群聊</strong>可被嵌入；密码群/角色限定群/不公开群嵌入后访客无法进入</li>'
        . '<li>嵌入页面依赖主站会话，若访客未登录将以游客身份参与（需开启游客浏览/发言）</li>'
        . '<li>嵌入代码为响应式尺寸（桌面 380×560，窄屏自动收窄撑满宽度且不超出屏幕高度），无需按设备分别配置</li>'
        . '<li>个别浏览器（如 Safari）默认拦截第三方 Cookie，嵌入页中的登录态可能不生效，访客将以游客身份参与</li>'
        . '</ul>'
        . '</div>';
});
