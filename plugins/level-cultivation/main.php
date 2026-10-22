<?php
/**
 * 等级修仙插件（level-cultivation v1.0.0）
 *
 * 功能：把等级信任插件的文字徽章「Lv.X」替换为修仙境界 SVG 图标。
 * 共 11 阶：练气→筑基→金丹→元婴→化神→合体→渡劫→大乘→真仙→玄仙→金仙。
 * 等级 55 及以上为金仙。
 *
 * 实现方式（不修改主程序）：
 *   - 前端 chat.js 通过 MutationObserver + 定时扫描，找到 .ow-lt-badge 元素，
 *     解析其中的「Lv.X」文本，按等级映射替换为对应境界的内联 SVG。
 *   - style.css 把图标尺寸锁定到与原徽章一致（资料卡 16px、侧栏 14px），
 *     并去掉原徽章的彩色背景。
 */
if (!defined('OWLSGO_VERSION')) exit;

/* 注册前台资源（聊天页与后台页统一由 ?action=assets 合并引入） */
Plugin::asset('js', 'level-cultivation/chat.js', 'front');
Plugin::asset('css', 'level-cultivation/style.css');
