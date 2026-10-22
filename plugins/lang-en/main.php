<?php
/**
 * English 语言包（lang-en v1.0.0）
 *
 * 纯数据插件：词条在 lang.js（OwI18n.register 注入），引擎在核心 assets/js/i18n.js。
 * 只翻译系统界面文字；消息流/会话列表/成员列表在引擎里整体跳过，用户内容不受影响。
 *
 * 为什么词条放 JS 而不是 PHP：owlsgo 的界面绝大多数由 chat.js 渲染，
 * 前端词典一次覆盖 聊天页/后台页/登录页 + toast（chat.js 的 toast 走 OwI18n.t）。
 */
if (!defined('OWLSGO_VERSION')) exit;   // 禁止直接 HTTP 访问本文件

Plugin::asset('js', 'lang-en/lang.js');

// 登录页默认不加载合并资源包（PLUGIN.md「前台资源」），但语言切换器必须在登录页可用
// —— 用 page.footer 补一份 bundle，并打标记防止聊天页/后台页被二次注入。
Plugin::on('page.footer', function () {
    if (defined('OWLSGO_ASSETS_JS_EMITTED')) return;
    define('OWLSGO_ASSETS_JS_EMITTED', true);
    echo '<script src="?action=assets&type=js&v=' . OWLSGO_ASSET_VERSION . '"></script>';
});
