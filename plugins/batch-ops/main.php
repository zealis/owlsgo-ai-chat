<?php
/**
 * 批量操作插件（batch-ops v1.1.0）
 *
 * ── 做什么 ────────────────────────────────────────────────────
 * 一个「选择目标」通用轮子 + 消息多选能力，当前接了两种业务：
 *   A. 消息多选：消息右键「多选」（或「转发」单条直达）进入选择模式，
 *      消息区底部操作条：逐条转发 / 合并转发 / 保存至电脑 / 收藏 / 删除 / 复制。
 *      转发打开选择器（最近聊天 / 好友 / 群聊三栏 + 跨好友群聊搜索），
 *      合并转发把 N 条合成一条（文字摘要 + 图片 rich 附件，≤9 张）。
 *   B. 邀请好友进群：群聊信息面板「邀请好友」打开同一个选择器（verb=邀请），
 *      逐行调用核心 room_invite；底部附群聊邀请链接一键复制。
 *
 * ── 为什么服务端是空的 ────────────────────────────────────────
 * 转发 = 对目标**逐条**调核心 send（图片/文件/混排按原 type 与 content 原样重发，
 *   敏感词、禁言、好友关系、群成员校验、发言频率限制全部由核心保证，一条不少）；
 * 邀请 = 核心 room_invite（已在 $SENSITIVE，前端走 OwApi.secure 一次性票据）；
 * 列表数据 = 核心 conversations / friends / rooms / room_members 四个既有 action。
 * 所以本插件不需要注册任何路由与表 —— main.php 只登记前端资源。
 *
 * ── 已知边界 ──────────────────────────────────────────────────
 * 逐条转发的节奏受核心「发言频率限制」约束（默认 10 秒 8 条），
 * 数量多时前端队列会自动等待窗口重置并显示进度（见 chat.js 的转发队列）。
 * 合并转发是「N 条合成一条」：文字按「昵称：内容」摘要、图片作 rich 附件
 * （≤9 张，只收本站 uploads/ 下的），文件类不随合并消息走（保真不了）。
 */
if (!defined('OWLSGO_VERSION')) exit;   // 禁止直接 HTTP 访问本文件

Plugin::asset('js', 'batch-ops/chat.js');
Plugin::asset('css', 'batch-ops/style.css');

/**
 * 核心不会自动在页面里引入插件合并 CSS（只自动引合并 JS），
 * 需在 page.head 自行输出 —— 与 link-card / captcha-verify 等现行插件同一做法。
 * 本插件的样式只服务聊天页交互，但注入条件保持无条件：多几 KB 静态文件，
 * 换取「任何页面动态弹出的选择器都不会裸奔」。
 */
Plugin::on('page.head', function (): void {
    echo '<link rel="stylesheet" href="?action=assets&type=css&plugin=batch-ops&file=style.css&v='
        . OWLSGO_VERSION . '">' . "\n";
});
