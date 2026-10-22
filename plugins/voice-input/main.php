<?php
/**
 * 语音输入插件（voice-input v1.0.0）
 *
 * ── 做什么 ────────────────────────────────────────────────────
 *   输入框工具栏新增「语音输入」按钮（chat.toolbar 钩子产出，停用插件即消失）。
 *   点一下开始听写：输入栏上沿浮出一张小卡片，实时显示识别到的文字
 *   （已定稿的正文 + 未定稿的浅色尾句 + 光标），右上角是「本机识别 / 在线识别」标签，
 *   点「✓」把文字插到输入框光标处，点「✕」或 Esc 丢弃。
 *
 * ── 只走浏览器原生能力，零依赖 ────────────────────────────────
 *   Web Speech API 的 SpeechRecognition（Chrome / Edge 原生，Safari 需 webkit 前缀）。
 *   不需要 API Key、不需要第三方库、不需要本站后端 —— 本插件**没有任何服务端代码**，
 *   没有路由、没有建表、没有配置项：识别全在浏览器里跑，产出的只是一段文字。
 *
 * ── 隐私：默认强制本机识别 ────────────────────────────────────
 *   Chrome 传统行为是把音频送到 Google 服务器（"不完全是私密的"，也没法离线）。
 *   Web Speech API 的 processLocally 属性可以改掉这件事：置 true 后识别必须在本机执行，
 *   音频与转录都不会发给第三方（前提是浏览器已装好对应语言的离线语音包）。
 *   本插件默认就是这条路。关键实现细节在 chat.js 顶部：
 *   ⚠️ 「设上去了」≠「生效了」—— 浏览器没实现这条 IDL 属性时，赋值只会挂一个无人理会的
 *      expando，音频照样上云，界面却写着「本机识别」。所以必须先 `'processLocally' in rec`
 *      问一句「有没有」，再谈「设不设」。
 *   没有这条能力、或本机离线包缺失时，本插件**不会静默降级**到云端，而是在浮层里
 *   说明情况、请用户显式点「改用在线识别」，此后标签一直显示「在线识别」。
 *
 * ── 与核心的接缝 ──────────────────────────────────────────────
 *   · 按钮 HTML 由 chat.toolbar 钩子服务端产出，核心 Plugin::collect 拼进 #owToolFirst。
 *     默认 style="display:none"：只有 chat.js 探测到 SpeechRecognition 才显形，
 *     Firefox 等不支持的环境按钮始终不出现（不报错、不破坏界面）。
 *   · 样式：由核心 pageHead 统一引整包（v1.5.31 起不再自己发切片）。原来这里写的是「必须自己发 <link>」：
 *     Plugin::asset('css', …) 只是登记（合并包成员 + ?action=assets 白名单），
 *     核心没有「合并 CSS 再引入」的地方，只登记会拿到 0 字节。
 *   · 写入输入框复用核心 `OwChat.autoGrow()`，并在插入后派发一次 input 事件，
 *     让核心绑在 #owInput 上的 oninput（自动增高）与任何其它监听者都能收到。
 *   · 文案在 chat.js 里带一份**插件自有的**中英对照表，不调用 OwI18n.register()：
 *     那个接口对同一 code 是**整体替换**（`packs[i].dict = dictionary`），
 *     插件注册 'en' 会把 lang-en 的整本词典冲掉。
 */
if (!defined('OWLSGO_VERSION')) exit;   // 禁止直接 HTTP 访问本文件

// ⚠️ v1.5.31 起下面这段已作废：核心 pageHead 统一引整包 CSS，插件不再自己发切片（保留作历史）
/**
 * 样式：自己发一份 <link>，不搭别人的顺风车。
 * 只登记不发 link 的话，?action=assets&type=css 虽然能取到字节，但没有任何页面会引用它。
 */
// v1.5.31：CSS 不再由插件自己发切片 —— 核心 pageHead 统一引整包
// （整包本就包含本插件的样式；此前每个插件各发一条，首屏变成 16 个串行 CSS 请求）

/**
 * 语音输入按钮：HTML 由服务端产出，点击行为绑在 chat.js。
 *
 * 图标是插件自有字形（PLUGIN.md 允许内联 SVG，且 path 只存在于本文件这一处）：
 * 一张稿纸 + 两行文字 + 右上角的话筒 —— 「说的话直接落成文稿」，与 voice-msg 的
 * 纯麦克风（录一段音频发出去）一眼可分，两个插件同时启用也不会撞脸。
 *
 * ⚠️ 只用原图里的 path，但两处必须改：
 *   · `fill="#000000"` → `currentColor`：写死黑色在深色主题下就是一坨黑，
 *     而工具栏按钮的 color 由 .ow-icon-btn 给（--ow-text-sub / hover 变主色），
 *     currentColor 才能跟着主题与悬停态一起变。
 *   · 去掉 stroke 系列属性：这是**实心**字形（Material Symbols 风格），
 *     fill="none" + stroke 会把实心块画成一圈轮廓，完全走形。
 */
Plugin::on('chat.toolbar', function (): string {
    $svg = '<svg class="ow-ico" width="16" height="16" viewBox="0 0 24 24"'
         . ' fill="currentColor" aria-hidden="true">'
         . '<path d="M17 10q-.825 0-1.412-.575T15 8V4q0-.85.588-1.425T17 2q.85 0 1.425.575T19 4v4q0 .85-.575 1.425T17 10M5 22q-.825 0-1.412-.587T3 20V4q0-.825.588-1.412T5 2h8v2H5v16h11v-2h2v2q0 .825-.587 1.413T16 22zm2-4v-2h7v2zm0-3v-2h5v2zm11 1h-2v-2.6q-1.925-.35-3.213-1.862T11.5 8h2q0 1.45 1.025 2.475T17 11.5q1.475 0 2.488-1.025T20.5 8h2q0 2.025-1.275 3.538T18 13.4z"/></svg>';

    return '<button class="ow-icon-btn ow-vi-entry" id="owBtnSpeech" type="button"'
         . ' title="语音输入" aria-label="语音输入" aria-pressed="false" style="display:none">'
         . $svg . '</button>';
});

/* ============================ 静态资源 ============================ */
/* 登记 = 合并包成员 + ?action=assets 的可服务白名单；不登记则取不到。 */
Plugin::asset('js', 'voice-input/chat.js', 'front');
Plugin::asset('css', 'voice-input/style.css');
