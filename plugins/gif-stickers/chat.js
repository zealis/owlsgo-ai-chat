/**
 * gif-stickers · 聊天页表情面板（v1.3.75）
 *
 * 职责：往核心锚点 #owToolFirst 注入图标，点开一个「上方搜索、下方结果」的面板，
 * 选中结果 → 发一条图片消息；每条结果可收藏成贴纸（复用核心 OwChat.collect）。
 *
 * 两个源的调用方式不同，这是**内容源条款决定的**，不是实现偏好：
 *   - klipy：由浏览器直接请求 api.klipy.com。他们的《Integration Requirements》
 *     明文要求请求由终端用户客户端发起、媒体直接从返回 URL 加载、不得经服务器代理或缓存。
 *   - apihz：走本站路由 plugin_gif_stickers_search，密钥只留在服务端。
 *
 * 兼容老浏览器：只用 var / function，不用箭头函数、let/const、fetch、模板串。
 * 依赖核心暴露的 OwApi / esc / toast / OwChat。
 */
(function (w, d) {
    'use strict';

    var $ = function (id) { return d.getElementById(id); };
    var esc = w.esc, toast = w.toast;

    /** KLIPY 唯一标 REQUIRED 的署名项：搜索框占位文字。刻意写成常量、不进语言包。 */
    var KLIPY_PLACEHOLDER = 'Search KLIPY';
    var KLIPY_BASE = 'https://api.klipy.com/api/v1/';

    var cfg = null, cfgDone = false;
    var q = '', page = 1, hasMore = false, loading = false, opened = false;

    /** 工具栏图标：与核心 ow_icon() 同一份 path data 风格（24 viewBox、16px、描边） */
    function icon() {
        return '<svg class="ow-ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            + ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            + '<rect x="3" y="5" width="18" height="14" rx="2.5"/>'
            + '<path d="M7.5 5v14M16.5 5v14"/><path d="M3 9.5h4.5M3 14.5h4.5M16.5 9.5H21M16.5 14.5H21"/>'
            + '</svg>';
    }

    /* ---------- 面板骨架 ---------- */

    function panel() {
        var p = $('owGifPanel');
        if (p) return p;
        p = d.createElement('div');
        p.className = 'ow-gif-panel';
        p.id = 'owGifPanel';
        p.style.display = 'none';
        p.innerHTML =
            '<div class="ow-gif-search">'
            + '<input class="ow-input" type="text" id="owGifQ" autocomplete="off" maxlength="60">'
            + '<button class="ow-btn ow-btn-primary" id="owGifGo" type="button">搜索</button></div>'
            + '<div class="ow-gif-grid" id="owGifGrid"></div>'
            + '<div class="ow-gif-foot"><button class="ow-btn ow-btn-ghost" id="owGifMore" type="button" style="display:none">加载更多</button>'
            + '<span id="owGifPowered" style="display:none"></span></div>';
        // 挂在表情面板的同一个父节点上，才能复用那套绝对定位（.ow-emoji-panel 的坐标系）
        var anchor = $('owEmojiPanel');
        (anchor && anchor.parentNode ? anchor.parentNode : d.body).appendChild(p);
        $('owGifGo').onclick = function () { search(); };
        $('owGifQ').onkeydown = function (e) {
            e = e || w.event;
            if (e.keyCode === 13) { e.preventDefault ? e.preventDefault() : (e.returnValue = false); search(); }
        };
        $('owGifMore').onclick = function () { load(page + 1); };
        return p;
    }

    function close() {
        var p = $('owGifPanel');
        if (p) p.style.display = 'none';
        opened = false;
    }

    function toggle() {
        if (opened) return close();
        open();
    }

    function open() {
        var p = panel();
        // 表情面板与本面板互斥：两个都开着会叠在一起，点外面也分不清该关哪个
        var em = $('owEmojiPanel');
        if (em) em.style.display = 'none';
        p.style.display = '';
        opened = true;
        var inp = $('owGifQ');
        if (inp) inp.placeholder = (cfg && cfg.provider === 'klipy') ? KLIPY_PLACEHOLDER : '搜索表情包';
        if (!cfgDone) return fetchCfg();
        if (!$('owGifGrid').getAttribute('data-loaded')) load(1);
        if (inp) inp.focus();
    }

    function search() {
        var inp = $('owGifQ');
        q = inp ? (inp.value || '').replace(/^\s+|\s+$/g, '') : '';
        load(1);
    }

    /** 配置只取一次；失败也记「取过」，避免每次点开都打一发请求 */
    function fetchCfg() {
        OwApi.post('plugin_gif_stickers_conf', {}, function (r) {
            cfgDone = true;
            if (!r.ok) { grid('<p class="ow-gif-msg">' + esc(r.msg || '表情包源未配置') + '</p>'); return; }
            cfg = r.cfg;
            var i = $('owGifQ');
            if (i) i.placeholder = cfg.provider === 'klipy' ? KLIPY_PLACEHOLDER : '搜索表情包';
            var pw = $('owGifPowered');
            if (pw && cfg.provider === 'klipy' && cfg.klipy && cfg.klipy.powered) {
                pw.style.display = '';
                pw.innerHTML = 'Powered by <a href="https://klipy.com" target="_blank" rel="noopener nofollow">KLIPY</a>';
            }
            load(1);
            var i2 = $('owGifQ');
            if (i2) i2.focus();
        });
    }

    /* ---------- 取数 ---------- */

    function load(toPage) {
        if (!cfg) { if (!cfgDone) fetchCfg(); return; }
        if (loading) return;
        page = toPage > 1 ? toPage : 1;
        loading = true;
        grid(page > 1 ? null : '<p class="ow-gif-msg">加载中…</p>');
        if (cfg.provider === 'apihz') return loadApihz();
        loadKlipy();
    }

    /** KLIPY：浏览器直连。空关键词走 trending，有关键词走 search —— 都是他们的官方端点 */
    function loadKlipy() {
        var k = cfg.klipy || {};
        var qs = ['per_page=' + (k.per_page || 24), 'page=' + page];
        if (k.content_filter) qs.push('content_filter=' + encodeURIComponent(k.content_filter));
        if (k.locale) qs.push('locale=' + encodeURIComponent(k.locale));
        if (cfg.customer_id) qs.push('customer_id=' + encodeURIComponent(cfg.customer_id));
        // 广告参数只在开启时带。他们的条款不允许我们改动结果顺序，所以广告位
        // 只能落在响应把它放在哪儿的那个位置（见 parseKlipy 保序遍历）
        if (k.ads) {
            qs.push('ad-min-width=320', 'ad-max-width=640', 'ad-min-height=50', 'ad-max-height=250');
        }
        var path;
        if (q === '') {
            path = 'gifs/trending';
        } else {
            path = 'gifs/search';
            qs.push('q=' + encodeURIComponent(q));
        }
        var url = KLIPY_BASE + encodeURIComponent(k.key || '') + '/' + path + '?' + qs.join('&');
        getKlipy(url);
    }

    function getKlipy(url) {
        var x = new XMLHttpRequest();
        x.open('GET', url, true);
        x.timeout = 12000;
        x.onload = function () {
            loading = false;
            var j = null;
            try { j = JSON.parse(x.responseText || '{}'); } catch (e) { j = null; }
            if (!j) { grid('<p class="ow-gif-msg">KLIPY 返回异常（HTTP ' + x.status + '）</p>'); return; }
            var parsed = parseKlipy(j);
            hasMore = !!parsed.next;
            render(parsed.items, page > 1);
            if (!parsed.items.length && page === 1) grid('<p class="ow-gif-msg">没有结果，换个关键词试试</p>');
            foot();
        };
        x.onerror = function () { loading = false; grid('<p class="ow-gif-msg">连不上 KLIPY：可能被网络策略或广告拦截挡住</p>'); };
        x.ontimeout = function () { loading = false; grid('<p class="ow-gif-msg">KLIPY 请求超时</p>'); };
        x.send();
    }

    /**
     * KLIPY 响应：{ result, data: { data: [ { file: { gif: { url, width, height } } } ] } }
     * 广告按文档说明是「某条结果里带一个 HTML 字符串的 content 字段」，
     * 所以这里**按原顺序**遍历：遇到带 content 的项就当成一个广告位，不挪位、不删项。
     */
    function parseKlipy(j) {
        var box = (j && j.data) ? j.data : j;
        var arr = box && box.data && box.data.length !== undefined ? box.data : (box && box.results ? box.results : []);
        var items = [], i, it;
        if (Object.prototype.toString.call(arr) !== '[object Array]') arr = [];
        for (i = 0; i < arr.length; i++) {
            it = arr[i] || {};
            if (typeof it.content === 'string' && it.content.indexOf('<') === 0) { items.push({ kind: 'ad', html: it.content }); continue; }
            var m = (it.file && (it.file.gif || it.file.webp || it.file.hd)) || it.media || null;
            var url = m ? (m.url || m.gif || '') : '';
            if (!/^https?:\/\//i.test(String(url))) continue;
            items.push({
                kind: 'gif', url: url,
                thumb: (it.preview && it.preview.url) || url,
                w: m && m.width ? (m.width | 0) : 0, h: m && m.height ? (m.height | 0) : 0,
                title: it.title || ''
            });
        }
        return { items: items, next: !!(box && (box.has_next || (box.next_page && box.next_page !== ''))) };
    }

    /** 给后台「测试当前源」用的计数：面板与自测共用一份解析口径，免得两处对形状的理解漂移 */
    w.OwGif = { count: function (j) { return parseKlipy(j).items.length; } };

    /** apihz：本站代理，返回里只有图片地址 */
    function loadApihz() {
        OwApi.post('plugin_gif_stickers_search', { words: q, page: page }, function (r) {
            loading = false;
            if (!r.ok) { grid(page > 1 ? null : '<p class="ow-gif-msg">' + esc(r.msg || '搜索失败') + '</p>'); foot(); return; }
            var urls = r.data || [], items = [], i;
            hasMore = page < (r.maxpage || 1);
            for (i = 0; i < urls.length; i++) {
                if (/^https?:\/\//i.test(String(urls[i]))) items.push({ kind: 'gif', url: urls[i], thumb: urls[i], w: 0, h: 0, title: '' });
            }
            render(items, page > 1);
            if (!items.length && page === 1) grid('<p class="ow-gif-msg">没有结果，换个关键词试试</p>');
            foot();
        });
    }

    /* ---------- 渲染 ---------- */

    function grid(html) {
        var g = $('owGifGrid');
        if (!g) return;
        if (html === null) return;          // 追加模式：不动已有内容
        g.innerHTML = html;
        g.removeAttribute('data-loaded');
    }

    function render(items, append) {
        var g = $('owGifGrid');
        if (!g) return;
        var h = '', i;
        for (i = 0; i < items.length; i++) {
            if (items[i].kind === 'ad') { h += adHtml(items[i].html); continue; }
            h += '<span class="ow-gif-item" data-url="' + esc(items[i].url) + '" title="' + esc(items[i].title || '点击发送') + '">'
                + '<img loading="lazy" src="' + esc(items[i].thumb) + '" alt="">'
                + '<button class="ow-gif-fav" type="button" title="收藏为贴纸">★</button></span>';
        }
        if (append) g.insertAdjacentHTML ? g.insertAdjacentHTML('beforeend', h) : appendSlow(g, h);
        else g.innerHTML = h;
        g.setAttribute('data-loaded', '1');
        bindGrid();
    }

    function appendSlow(g, h) { var t = g.innerHTML; g.innerHTML = t + h; }

    /**
     * 广告位：KLIPY 要求把返回的 HTML 原样渲染。
     * 直接 innerHTML 等于把第三方 HTML 塞进本站 DOM —— 一旦它内含脚本就是 XSS。
     * 所以放进 sandbox iframe：allow-scripts 让广告能正常渲染与计数，
     * 但**不给 allow-same-origin**，它拿不到本站页面与 cookie。
     */
    function adHtml(html) {
        return '<div class="ow-gif-ad"><iframe class="ow-gif-ad-frame" sandbox="allow-scripts allow-popups'
            + ' allow-popups-to-escape-sandbox" title="advertisement" srcdoc="' + esc(html) + '"></iframe></div>';
    }

    function bindGrid() {
        var g = $('owGifGrid');
        if (!g) return;
        var items = g.querySelectorAll('.ow-gif-item'), i;
        for (i = 0; i < items.length; i++) {
            items[i].onclick = function (e) {
                e = e || w.event;
                var t = e.target || e.srcElement;
                var url = this.getAttribute('data-url');
                if (t && t.className === 'ow-gif-fav') { w.OwChat.collect(url); return; }   // 只收藏，不发送
                if (!url) return;
                w.OwChat.send({ type: 'image', content: url });
                close();
            };
        }
    }

    function foot() {
        var m = $('owGifMore');
        if (m) m.style.display = hasMore ? '' : 'none';
    }

    /* ---------- 挂载与点外关闭 ---------- */

    function mount() {
        var box = $('owToolFirst');
        if (!box) return;                       // 核心没有这个锚点（旧版本），安静退出
        box.innerHTML = '<button class="ow-icon-btn" id="owBtnGif" type="button" title="表情包" aria-label="表情包">' + icon() + '</button>';
        $('owBtnGif').onclick = function (e) {
            if (e && e.stopPropagation) e.stopPropagation(); else w.event.cancelBubble = true;
            toggle();
        };
        d.addEventListener ? d.addEventListener('click', onDocClick, false) : (d.onclick = onDocClick);
    }

    /** 点面板与图标以外的地方收起（与表情面板同一交互预期） */
    function onDocClick(e) {
        if (!opened) return;
        var t = (e || w.event).target || (e || w.event).srcElement, p = $('owGifPanel');
        if (!p) return;
        var n = t;
        while (n) {
            if (n === p || n.id === 'owBtnGif') return;
            n = n.parentNode;
        }
        close();
    }

    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', mount, false);
    else mount();
})(window, document);
