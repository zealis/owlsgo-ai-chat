/**
 * gif-stickers · 聊天页表情面板（v1.3.75）
 *
 * 职责：往核心锚点 #owToolFirst 注入图标，点开一个「上方搜索、下方结果」的面板，
 * 选中结果 → 发一条图片消息；点★ → 把同一条外链收进自己的贴纸表。
 *
 * 两个源的调用方式不同，这是**内容源条款决定的**，不是实现偏好：
 *   - klipy：由浏览器直接请求 api.klipy.com。他们的《Integration Requirements》
 *     明文要求请求由终端用户客户端发起、媒体直接从返回 URL 加载、不得经服务器代理或缓存。
 *   - apihz：走本站路由 plugin_gif_stickers_search，密钥只留在服务端。
 * 收藏也是**只存地址、不下载到本站**（v1.3.81 站方定）：把 KLIPY 的图取回来落盘正是他们
 * 条款禁止的「存储 / 镜像 / 转存」。代价直接说给用户听 —— 图源撤图、换地址或链接过期，
 * 这张贴纸就打不开了（★ 的 title 与收藏成功的提示里都写着）。
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

    /**
     * 工具栏图标（用户指定的 GIF 字形，path 原样保留）。
     * 只改了两处呈现：尺寸跟核心工具栏统一成 16px；描边从写死的 #000000 换成
     * currentColor —— 深色模式下纯黑描边会跟黑底融在一起，图标直接消失。
     */
    function icon() {
        return '<svg class="ow-ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            + ' stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            + '<path d="M12.75 8.25v7.5m6-7.5h-3V12m0 0v3.75m0-3.75H18M9.75 9.348c-1.03-1.464-2.698-1.464-3.728 0c-1.03 1.465-1.03 3.84 0 5.304s2.699 1.464 3.728 0V12h-1.5M4.5 19.5h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5"/>'
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
            + '<button class="ow-btn ow-btn-primary" id="owGifGo" type="button">搜索</button>'
            + '<button class="ow-gif-close" id="owGifClose" type="button" aria-label="关闭" title="关闭">✕</button></div>'
            + '<div class="ow-gif-grid" id="owGifGrid"></div>'
            + '<div class="ow-gif-foot"><button class="ow-btn ow-btn-ghost" id="owGifMore" type="button" style="display:none">加载更多</button>'
            + '<span id="owGifPowered" style="display:none"></span></div>';
        // 挂在表情面板的同一个父节点上，才能复用那套绝对定位（.ow-emoji-panel 的坐标系）
        var anchor = $('owEmojiPanel');
        (anchor && anchor.parentNode ? anchor.parentNode : d.body).appendChild(p);
        $('owGifGo').onclick = function () { search(); };
        $('owGifClose').onclick = function () { close(); };
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
            if (!parsed.items.length && page === 1) {
                if (x.status === 200) {
                    // 「200 但 0 条」有两种完全不同的原因：源真的没给内容（密钥/授权问题），
                    // 或响应结构与我们理解的不一样。界面必须说清是哪一种，
                    // 否则管理员只能盲猜「是不是密钥错了」。
                    var s = inspectKlipy(j);
                    if (w.console && w.console.warn) w.console.warn('[gif-stickers] KLIPY 返回 200 但解析不出结果，原始响应：', j);
                    grid('<p class="ow-gif-msg">' + (s.found < 0
                        ? 'KLIPY 返回的内容里找不到结果数组（结构已记在浏览器控制台）'
                        : ('KLIPY 正常返回，但这条请求给了 ' + s.found + ' 条'
                            + (s.dropped ? '（' + s.dropped + ' 条取不到图片地址）' : '')
                            + ' —— 请核对密钥对应的产品与已授权域名')) + '</p>');
                } else {
                    grid('<p class="ow-gif-msg">没有结果，换个关键词试试</p>');
                }
            }
            foot();
        };
        x.onerror = function () { loading = false; grid('<p class="ow-gif-msg">连不上 KLIPY：可能被网络策略或广告拦截挡住</p>'); };
        x.ontimeout = function () { loading = false; grid('<p class="ow-gif-msg">KLIPY 请求超时</p>'); };
        x.send();
    }

    /**
     * 在响应里找第一个「像结果集」的数组。
     * 为什么要找：文档写的是 { result, data: { data: [...] } }，但他们另有一节
     * 明确说接口与 Tenor 兼容，而 Tenor 是 { results: [...] } / { data: [...] }。
     * 实测过一次 HTTP 200 却解析出 0 条 —— 就是被这一层包裹差异吃掉的。
     */
    function findList(o, depth) {
        if (!o || typeof o !== 'object' || depth > 3) return null;
        if (Object.prototype.toString.call(o) === '[object Array]') return o.length ? o : null;
        var keys = ['data', 'results', 'gifs', 'stickers', 'items'], i, r;
        for (i = 0; i < keys.length; i++) {
            if (!o[keys[i]]) continue;
            r = findList(o[keys[i]], depth + 1);
            if (r) return r;
        }
        return null;
    }

    /**
     * 从一条结果里挑一个能直接当 <img src> 用的 http(s) 地址。
     * ⚠️ 深度必须放到 4：文档结构是 item → file → gif → url，
     *   三层容器再读字符串。原先限到 2，走到 file.gif 时已经用尽层数，
     *   下一次递归直接返回空 —— 表现就是「返回 8 条，但都取不到图片地址」。
     * 键序也刻意把 file/media/preview 放在裸 url 之前：先钻进媒体容器再兜底，
     * 避免把 item.url（那是网页地址，不是图片）当成缩略图发出去。
     */
    function pickUrl(o, depth) {
        if (!o || depth > 4) return '';
        if (typeof o === 'string') return /^https?:\/\//i.test(o) ? o : '';
        if (typeof o !== 'object') return '';
        var keys = ['file', 'media', 'preview', 'gif', 'webp', 'tinygif', 'mp4', 'hd', 'url'], i, u;
        // 数组要先钻进去：Tenor 口径是 media: [ { gif: { url } } ]，
        // 只按对象键名找的话，数组里的东西一条都取不出来。
        if (Object.prototype.toString.call(o) === '[object Array]') {
            for (i = 0; i < o.length; i++) { u = pickUrl(o[i], depth + 1); if (u) return u; }
            return '';
        }
        for (i = 0; i < keys.length; i++) {
            if (!o[keys[i]]) continue;
            u = pickUrl(o[keys[i]], depth + 1);
            if (u) return u;
        }
        return '';
    }

    /** 还有没有下一页：同样按几种包裹层试，取不到就当没有 */
    function hasNext(o, depth) {
        if (!o || typeof o !== 'object' || depth > 2) return false;
        if (o.has_next === true) return true;
        if (o.next_page && o.next_page !== '' && o.next_page !== '0') return true;
        var keys = ['data', 'result', 'results', 'pagination'], i;
        for (i = 0; i < keys.length; i++) { if (hasNext(o[keys[i]], depth + 1)) return true; }
        return false;
    }

    /**
     * KLIPY 响应 → 面板条目。广告按文档说明是「某条结果里带一个 HTML 字符串的 content」，
     * 所以**按原顺序**遍历：遇到带 content 的项就当成一个广告位，不挪位、不删项
     * （他们的条款不允许我们改动结果顺序或组成）。
     */
    function parseKlipy(j) {
        var arr = findList(j, 0) || [], items = [], i, it, url;
        for (i = 0; i < arr.length; i++) {
            it = arr[i] || {};
            if (typeof it === 'string') {
                if (/^https?:\/\//i.test(it)) items.push({ kind: 'gif', url: it, thumb: it, w: 0, h: 0, title: '' });
                continue;
            }
            if (typeof it.content === 'string' && it.content.charAt(0) === '<') { items.push({ kind: 'ad', html: it.content }); continue; }
            url = pickUrl(it, 0);
            if (!url) continue;
            items.push({
                kind: 'gif', url: url,
                thumb: pickUrl(it.preview || {}, 0) || url,
                w: 0, h: 0, title: it.title || ''
            });
        }
        return { items: items, next: hasNext(j, 0) };
    }

    /** 给界面用的结构摘要：能分清「源没给内容」和「我们没解析对」这两种 0 条 */
    function inspectKlipy(j) {
        var top = [], k;
        for (k in j) if (Object.prototype.hasOwnProperty.call(j, k)) top.push(k + (Object.prototype.toString.call(j[k]) === '[object Array]' ? '[]' : (j[k] && typeof j[k] === 'object' ? '{}' : '')));
        var list = findList(j, 0);
        var dropped = 0, i;
        if (list) { for (i = 0; i < list.length; i++) { if (!pickUrl(list[i] || {}, 0)) dropped++; } }
        return {
            top: top.join(','),
            found: list ? list.length : -1,          // -1 = 压根没找到像结果集的数组
            dropped: dropped,                          // 找到了但一条地址都取不出
            items: parseKlipy(j).items
        };
    }

    /** 给后台「测试当前源」用的计数与结构摘要：面板与自测共用一份解析口径，避免两处理解漂移 */
    w.OwGif = {
        count: function (j) { return parseKlipy(j).items.length; },
        inspect: function (j) { var s = inspectKlipy(j); return { found: s.found, dropped: s.dropped, top: s.top, n: s.items.length }; }
    };

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
                + '<button class="ow-gif-fav" type="button" title="收藏为贴纸（存图源链接，可能随时失效）">★</button></span>';
        }
        if (append) g.insertAdjacentHTML ? g.insertAdjacentHTML('beforeend', h) : appendSlow(g, h);
        else g.innerHTML = h;
        g.setAttribute('data-loaded', '1');
        bindGrid();
    }

    function appendSlow(g, h) { var t = g.innerHTML; g.innerHTML = t + h; }

    /**
     * 收藏为贴纸：走核心的 sticker_add，外链由本插件的 sticker.allow 钩子放行（v1.3.81）。
     * 只存地址、不下载到本站 —— 这是照 KLIPY 的条款用他们的图（他们禁止存储 / 镜像 / 转存）。
     * 代价必须让用户在点的那一下就看到，所以提示由插件自己发，不用核心那句「已收藏」。
     * 请求期间禁用这颗星：连点会写出重复收藏，也白吃一遍收藏配额。
     */
    function collect(url, btn) {
        if (btn.disabled) return;
        btn.disabled = true;
        OwApi.post('sticker_add', { url: url }, function (r) {
            btn.disabled = false;
            if (r && r.ok) {
                toast('已收藏（存的是图源链接，图源撤图后就打不开了）');
                btn.setAttribute('data-faved', '1');   // 用属性不用 class：命中判定靠 className 等号，别把它改坏
                return;
            }
            toast((r && r.msg) || '收藏失败');
        });
    }

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
                if (t && t.className === 'ow-gif-fav') { collect(url, t); return; }   // 只收藏，不发送
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
        if ($('owBtnGif')) return;              // 幂等：重复执行不重复注入
        var btn = d.createElement('button');
        btn.className = 'ow-icon-btn';
        btn.id = 'owBtnGif';
        btn.type = 'button';
        btn.title = '表情包';
        btn.setAttribute('aria-label', '表情包');
        btn.innerHTML = icon();
        // ⚠️ 必须是 appendChild 追加，不能 box.innerHTML 整体覆盖：
        //    #owToolFirst 是**共享锚点**，群聊脚本等插件也往这里注入按钮，
        //    覆盖写法下「谁后加载谁赢」——本插件曾被后加载的插件整个冲掉过。
        box.appendChild(btn);
        btn.onclick = function (e) {
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
