/**
 * color-schemes · 色系引擎（v1.3.51 起是本插件的文件，此前在核心 assets/js/）
 * —— 色系系统从姊妹实现（参考文献/forum）的 theme-boot.js 移植。
 *
 * 加载位置：<head> 内、由插件的 page.head 钩子以阻塞脚本注入
 * （`?action=assets&type=js&plugin=color-schemes`）。放不进 body 末尾的合并总包 ——
 * 那样每次刷新都会先按经典蓝画一帧再变色。
 * **插件停用时本文件根本不加载**：没有行内变量，界面就是样式表默认值（经典蓝）。
 *
 * 与日夜模式的分工：日夜由 index.php 的内联预置脚本落成 <html data-theme>（那份必须内联，
 * 它只有 5 行且不能 404）；本文件只负责**色系**，读已定好的 data-theme，不再自己判深浅 ——
 * 两处各算一遍 auto 迟早漂移。
 *
 * 一个色系 = 主色 / 悬浮 / 浅底 三档，映射到核心既有令牌（不新增语义，改的全是现成的）：
 *   brand → --ow-primary（描边、图标底、焦点态）+ --ow-primary-strong（实心按钮、链接）
 *   hover → --ow-primary-dark（悬停、选中态）
 *   soft  → --ow-blue-light（浅蓝底：自己的消息气泡等）
 * 深色档下描边族走 darkify() 提亮，但 --ow-primary-strong 保持原值（它背后是白字）。
 * ⚠️ 刻意**不碰** --ow-hover-bg / --ow-active-bg：那两档在 v1.1.23 被明确要求做成中性灰，
 *    与色系无关，跟着变色等于把当初的决定推翻。
 *
 * 存储：localStorage（owl_scheme / owl_schemes_custom），与 owl_theme 同一档「本机外观偏好」，
 * 不进服务端 —— 换浏览器看到的还是自己的配色，也不需要为它加一张表。
 *
 * 经典蓝 classic = 样式表默认值，**不写任何行内变量**。这既让默认外观逐像素不变，
 * 也避免行内变量把下面深色适配那套逻辑无谓地套上去。
 */
(function (w, d) {
    'use strict';
    // 本文件同时在两处出现：<head> 里由插件的 page.head 注入（?…&file=boot.js，为了首帧前定色），
    // 以及 body 末尾的插件合并总包（清单里注册了它）。第二次执行必须直接退出，
    // 否则会把已生效的行内变量重算一遍、并覆盖 window.OwTheme 上的实例。
    if (w.OwTheme) return;

    var SCHEME_KEY = 'owl_scheme';
    var CUSTOM_KEY = 'owl_schemes_custom';
    var MAX_CUSTOM = 20;                       // 自建上限，防 localStorage 被撑爆
    var DARK_BASE = '#1c1c1e';                 // 深色下的 --ow-bg，浅底掺色时的基色

    var BUILTIN = {
        classic: { name: '经典蓝', brand: '#00a0e9', hover: '#0086c9', deep: '#005f9e', soft: '#e6f7ff' },
        emerald: { name: '翡翠绿', brand: '#047857', hover: '#065f46', deep: '#065f46', soft: '#ecfdf5' },
        red:     { name: '品牌红', brand: '#fc5531', hover: '#e8380d', deep: '#e8380d', soft: '#fef0ed' },
        violet:  { name: '紫罗兰', brand: '#7c3aed', hover: '#6d28d9', deep: '#6d28d9', soft: '#f5f3ff' },
        pink:    { name: '猛男粉', brand: '#fb7299', hover: '#e45c85', deep: '#e45c85', soft: '#fff0f5' },
        ink:     { name: '雅酷黑', brand: '#24292f', hover: '#111418', deep: '#111418', soft: '#f0f2f5' }
    };

    var VARS = ['--ow-primary', '--ow-primary-strong', '--ow-primary-dark', '--ow-blue-light',
        '--ow-brand', '--ow-action', '--ow-action-hover', '--ow-link', '--ow-focus-ring',
        '--ow-primary-soft', '--ow-primary-soft-fg'];

    function isColor(v) { return typeof v === 'string' && /^#[0-9a-f]{6}$/i.test(v); }
    function toRgb(hex) {
        return [parseInt(hex.slice(1, 3), 16), parseInt(hex.slice(3, 5), 16), parseInt(hex.slice(5, 7), 16)];
    }
    function toHex(a) {
        var s = '#';
        for (var i = 0; i < 3; i++) {
            var c = Math.round(Math.min(1, Math.max(0, a[i] / 255)) * 255).toString(16);
            s += c.length === 1 ? '0' + c : c;
        }
        return s;
    }

    /** 两色按 pct 掺合（pct=0 全 a，100 全 b）。用它在 JS 侧算深色浅底，
     *  而不是 CSS color-mix() —— 后者要 Chrome 111 / Safari 16.2，不支持时整条变量失效。 */
    function mix(a, b, pct) {
        var x = toRgb(a), y = toRgb(b), out = [], t = pct / 100;
        for (var i = 0; i < 3; i++) out[i] = x[i] + (y[i] - x[i]) * t;
        return toHex(out);
    }

    /**
     * 深底适配：把日间品牌色调到深色下可读的亮度档（过暗提亮、过亮略压，色相基本不变）。
     * 深色模式若直接套日间原色，#047857 这种深绿在 #1A1A1C 上几乎看不见；
     * 反过来日间套夜间值又会刺眼 —— 所以两套各算一次，而不是一个值走天下。
     */
    function darkify(hex) {
        var rgb = toRgb(hex), r = rgb[0] / 255, g = rgb[1] / 255, b = rgb[2] / 255;
        var max = Math.max(r, g, b), min = Math.min(r, g, b), h = 0, s, l = (max + min) / 2;
        if (max !== min) {
            var dd = max - min;
            s = l > 0.5 ? dd / (2 - max - min) : dd / (max + min);
            if (max === r) h = (g - b) / dd + (g < b ? 6 : 0);
            else if (max === g) h = (b - r) / dd + 2;
            else h = (r - g) / dd + 4;
            h /= 6;
        } else {
            s = 0;
        }
        if (l < 0.5) l = l + (0.58 - l) * 0.8;
        else if (l > 0.7) l = l - (l - 0.62) * 0.5;
        s = Math.min(s, 0.8);
        var q = l < 0.5 ? l * (1 + s) : l + s - l * s, p = 2 * l - q;
        function hue2rgb(t) {
            if (t < 0) t += 1;
            if (t > 1) t -= 1;
            if (t < 1 / 6) return p + (q - p) * 6 * t;
            if (t < 1 / 2) return q;
            if (t < 2 / 3) return p + (q - p) * (2 / 3 - t) * 6;
            return p;
        }
        return toHex([hue2rgb(h + 1 / 3) * 255, hue2rgb(h) * 255, hue2rgb(h - 1 / 3) * 255]);
    }

    function customList() {
        try {
            var raw = JSON.parse(w.localStorage.getItem(CUSTOM_KEY) || '[]');
            return Array.isArray(raw) ? raw : [];
        } catch (e) { return []; }
    }

    function customSchemes() {
        var out = {};
        customList().forEach(function (s) {
            if (!s || typeof s.id !== 'string' || !/^u_[0-9]+$/.test(s.id)) return;
            var name = String(s.name || '').slice(0, 12);
            if (name === '' || !isColor(s.brand) || !isColor(s.hover) || !isColor(s.soft)) return;
            out[s.id] = { name: name, brand: s.brand, hover: s.hover, deep: s.hover, soft: s.soft, custom: true };
        });
        return out;
    }

    function all() {
        var m = {}, k;
        for (k in BUILTIN) { if (Object.prototype.hasOwnProperty.call(BUILTIN, k)) m[k] = BUILTIN[k]; }
        var c = customSchemes();
        for (k in c) { if (Object.prototype.hasOwnProperty.call(c, k)) m[k] = c[k]; }
        return m;
    }

    /** 当前色系 id；存了个不存在的（自建被删、换浏览器）就回经典蓝 */
    function id() {
        try {
            var v = w.localStorage.getItem(SCHEME_KEY);
            if (v && all()[v]) return v;
        } catch (e) {}
        return 'classic';
    }

    function save(v) { try { w.localStorage.setItem(SCHEME_KEY, v); } catch (e) {} }

    function saveList(list) {
        try { w.localStorage.setItem(CUSTOM_KEY, JSON.stringify(list)); return true; } catch (e) { return false; }
    }

    /** 新建自建色系（立即启用由调用方做）。校验：名称非空 ≤12 字、三个值都是 #rrggbb、总数 <20 */
    function add(input) {
        var name = String((input && input.name) || '').trim().slice(0, 12);
        if (name === '' || !isColor(input.brand) || !isColor(input.hover) || !isColor(input.soft)) return null;
        var list = customList();
        if (list.length >= MAX_CUSTOM) return null;
        var s = {
            id: 'u_' + Date.now(), name: name,
            brand: input.brand.toLowerCase(), hover: input.hover.toLowerCase(), soft: input.soft.toLowerCase()
        };
        list.push(s);
        return saveList(list) ? s : null;
    }

    /** 删除自建色系（内置不可删）。删掉正在用的那个时，调用方要把 id 落回 classic */
    function remove(sid) {
        if (!/^u_[0-9]+$/.test(String(sid))) return false;
        var list = customList(), next = [];
        for (var i = 0; i < list.length; i++) { if (!list[i] || list[i].id !== sid) next.push(list[i]); }
        if (next.length === list.length) return false;
        return saveList(next);
    }

    /** 当前生效的深浅档（读内联预置脚本写好的 data-theme，缺省按浅色） */
    function currentTheme() {
        return d.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    }

    /**
     * 把色系写进 <html> 行内变量。
     *
     * ⚠️ 为什么必须在切到深色时重算一遍：行内样式的优先级高于 `[data-theme="dark"]` 块，
     *    一旦写了行内变量，样式表里的深色值就再也盖不回来 —— 所以深色下要主动喂
     *    darkify() 过的值，而不是留着日间的浅色变量（表现为「切了深色，按钮还是亮绿」）。
     */
    function applyScheme(theme) {
        var style = d.documentElement.style, cur = id(), s = all()[cur];
        function reset() { for (var i = 0; i < VARS.length; i++) style.removeProperty(VARS[i]); }
        if (cur === 'classic' || !s) { reset(); return; }
        if (theme === 'dark') {
            var brand = darkify(s.brand);
            var soft = mix(DARK_BASE, brand, 22);
            style.setProperty('--ow-primary', brand);
            // ⚠️ --ow-primary-strong **不**提亮：它是「白字坐在上面」的实心按钮底/开关轨道，
            //    与 --ow-primary（画在深底上的描边、图标、焦点环）方向正好相反。
            //    实测翡翠绿 darkify 后是 #1fe6ae，白字对比只剩 1.62:1（看不见）；
            //    用原值 #047857 则是 6.7:1。需要提亮的是描边那一族，实心那一族要压得住白字。
            style.setProperty('--ow-primary-strong', s.brand);
            style.setProperty('--ow-primary-dark', darkify(s.hover));
            style.setProperty('--ow-blue-light', soft);
            style.setProperty('--ow-brand', brand);
            style.setProperty('--ow-action', s.brand);
            style.setProperty('--ow-action-hover', s.hover);
            style.setProperty('--ow-link', brand);
            style.setProperty('--ow-focus-ring', brand);
            style.setProperty('--ow-primary-soft', soft);
            style.setProperty('--ow-primary-soft-fg', brand);
            return;
        }
        style.setProperty('--ow-primary', s.brand);
        style.setProperty('--ow-primary-strong', s.brand);
        style.setProperty('--ow-primary-dark', s.hover);
        style.setProperty('--ow-blue-light', s.soft);
        style.setProperty('--ow-brand', s.brand);
        style.setProperty('--ow-action', s.brand);
        style.setProperty('--ow-action-hover', s.hover);
        style.setProperty('--ow-link', s.brand);
        style.setProperty('--ow-focus-ring', s.brand);
        style.setProperty('--ow-primary-soft', s.soft);
        style.setProperty('--ow-primary-soft-fg', s.deep || s.brand);
    }

    function apply() {
        var t = currentTheme();
        applyScheme(t);
        return t;
    }

    apply();   // <head> 内同步执行 → 首帧绘制前定色，刷新不闪一下经典蓝

    w.OwTheme = {
        BUILTIN: BUILTIN, MAX_CUSTOM: MAX_CUSTOM,
        all: all, customSchemes: customSchemes, id: id, save: save,
        add: add, remove: remove, apply: apply, applyScheme: applyScheme,
        currentTheme: currentTheme, darkify: darkify, mix: mix
    };
})(window, document);
