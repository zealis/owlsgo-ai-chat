<?php
/**
 * 页头页脚插件（header-footer）v1.0.0
 *
 * 功能：
 *   - 管理员在后台分别填写页头（<head> 内）与页脚（</body> 前）的自定义内容
 *   - 常用于注入统计代码（百度统计、Google Analytics 等）或自定义 CSS/JS
 *   - 不修改主程序：依赖 page.head / page.footer 两个钩子
 *
 * 钩子：
 *   page.head   —— 各页面 <head> 输出时（pageHead 内），无参，直接 echo
 *   page.footer —— 聊天页 / 登录页 body 输出末尾，无参，直接 echo
 *                  （注：管理后台页不触发 page.footer，故页脚内容不在后台页输出）
 *
 * 存储：settings 表，键 plugin_header_footer_head / plugin_header_footer_foot，
 *       v 列为 TEXT，可容纳较长的统计代码。
 */
if (!defined('OWLSGO_VERSION')) exit;

/* ---------- 设置键 ---------- */
function owHFKeyHead(): string { return 'plugin_header_footer_head'; }
function owHFKeyFoot(): string { return 'plugin_header_footer_foot'; }

/* ---------- 钩子：原样输出页头 / 页脚内容 ---------- */
// 内容由管理员填入，用于统计代码等场景，必须原样输出（不转义）。
Plugin::on('page.head', function (): void {
    $head = (string)DB::setting(owHFKeyHead(), '');
    if ($head !== '') echo $head . "\n";
});

Plugin::on('page.footer', function (): void {
    $foot = (string)DB::setting(owHFKeyFoot(), '');
    if ($foot !== '') echo $foot . "\n";
});

/* ---------- 后台页面：页头 / 页脚两个输入框 ---------- */
Plugin::adminPage('header-footer', '页头页脚', function () {
    $head = (string)DB::setting(owHFKeyHead(), '');
    $foot = (string)DB::setting(owHFKeyFoot(), '');

    // textarea 内的用户内容必须转义，防止后台 UI 被注入
    return '<h2>页头页脚</h2>'
        . '<p class="ow-admin-desc">在网站页头或页脚加入统计代码及其他自定义内容。仅管理员可配置，内容将<strong>原样输出</strong>，请勿填入不可信代码。</p>'
        . '<div class="ow-card">'

        . '<div class="ow-form-item"><label>页头内容</label>'
        . '<textarea class="ow-input" id="owHFHead" rows="8" style="width:100%;resize:vertical;font-family:var(--ow-mono,Consolas,monospace)" placeholder="<script> ... </script>  或  <style> ... </style>">' . Sec::e($head) . '</textarea>'
        . '<p style="font-size:12px;color:var(--ow-text-sub,#999);margin-top:4px">输出在每个页面的 <code>&lt;/head&gt;</code> 之前（含登录页、聊天页、管理后台），适合放置统计代码、自定义 CSS 等。</p></div>'

        . '<div class="ow-form-item" style="margin-top:14px"><label>页脚内容</label>'
        . '<textarea class="ow-input" id="owHFFoot" rows="8" style="width:100%;resize:vertical;font-family:var(--ow-mono,Consolas,monospace)" placeholder="<script> ... </script>  或备案信息等 HTML">' . Sec::e($foot) . '</textarea>'
        . '<p style="font-size:12px;color:var(--ow-text-sub,#999);margin-top:4px">输出在聊天页与登录页的 <code>&lt;/body&gt;</code> 之前，适合放置统计代码、底部备案信息等。<strong>管理后台页不输出页脚内容。</strong></p></div>'

        . '<div class="ow-form-row" style="margin-top:10px">'
        . '<button class="ow-btn ow-btn-primary" onclick="OwHF.save()">保存设置</button>'
        . '</div>'
        . '<div id="owHFMsg" style="margin-top:10px"></div>'
        . '</div>';
});

/* ---------- 保存接口（仅管理员） ---------- */
Plugin::route('plugin_header_footer_save', function (array $ctx): void {
    if (($ctx['actor']['role'] ?? '') !== 'admin') {
        Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    }
    $head = (string)($ctx['post']['head'] ?? '');
    $foot = (string)($ctx['post']['foot'] ?? '');
    DB::setSetting(owHFKeyHead(), $head);
    DB::setSetting(owHFKeyFoot(), $foot);
    Sec::log('header_footer_save', $ctx['actor']['nickname']);
    Api::json(['ok' => true, 'msg' => '已保存']);
});

/* ---------- 后台交互脚本 ---------- */
Plugin::asset('js', 'header-footer/admin.js', 'admin');
