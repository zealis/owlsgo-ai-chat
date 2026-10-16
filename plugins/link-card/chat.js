/**
 * 链接卡片插件 · 聊天页消息卡片（link-card v1.1.0）
 *
 * 做什么：消息里出现链接时，在消息下方渲染一张预览卡（标题/描述/站点/封面图）。
 *   数据不在这里抓 —— 本脚本只负责「找链接、批量问服务端、把卡片插进 DOM」，
 *   真正的读取 Open Graph、黑名单判定、缓存与限流都在 main.php 的
 *   plugin_link_card_preview 里做（前端不可信，判定必须在服务端）。
 *
 * 为什么用 MutationObserver 扫 DOM 而不是挂渲染钩子：
 *   消息是**增量渲染**的（历史、长轮询、WebSocket 推送、引用跳转、撤回重绘、批量补拉
 *   各走各的路径，且核心并未给每条路径都留钩子），事后扫 DOM 是唯一能一次覆盖全部来源
 *   的做法 —— 与 level-cultivation 给消息加等级徽标同一思路。
 *
 * 约定（都踩过坑，别改）：
 *   · page.footer 在合并资源包**之后**才输出 OW_LC_* —— 所以在**调用时**读全局，
 *     不能在脚本加载时抓一份快照（那样永远是 undefined，功能静默失效）。
 *   · 站点已拦截的链接（核心给加了 .ow-link-blocked，由域名过滤插件决定）**不出卡**：
 *     既已不让点，就不该再引用户去看它的预览。
 *   · 远端数据一律 textContent 写入、封面图只接受 https 且带 referrerpolicy=no-referrer。
 *   · 撤回 / 删除消息时核心会替换内容元素并给 .ow-msg 加 recalled/deleted 类，
 *     卡片必须跟着移除，否则会留下「已撤回却还在的预览」。
 */
(function (w, d) {
    'use strict';
    if (w.__owLcChat) return;      // 幂等：合并资源被重复引入时不重复注册
    w.__owLcChat = 1;

    var PER_CALL = 2;              // 一次请求最多抓几个（与服务端 OWLC_MAX_PER_CALL 对齐）
    var cache = {};                // url → {ok:true,data} / {ok:false,reason}（仅本页会话）
    var waiting = {};              // url → [消息节点, …] 等这一批回包
    var queue = [];                // 待抓 URL
    var timer = null;
    var inflight = false;

    /**
     * 取 URL 主机名（小写、去端口、去认证信息）。
     * ⚠️ 与后端 owLChost / sensitive-words 的 hostOf 同一口径，三类伪装都要处理：
     *   https://spam.com@good.com/   认证信息里塞一个白名单域名，真实主机是 good.com
     *   https://good.com\@spam.com/  浏览器把 \ 当 / 解析，主机在第一个 \ 之前结束
     *   https://spam.com:8080/       带端口的同一主机不能漏
     */
    function hostOf(url) {
        var s = String(url || '');
        var m = /^https?:\/\/([^\/?#]*)/i.exec(s);
        var auth = m ? m[1] : s.split(/[\/?#]/)[0];
        var bs = auth.indexOf('\\');
        if (bs >= 0) auth = auth.slice(0, bs);
        var at = auth.lastIndexOf('@');
        if (at >= 0) auth = auth.slice(at + 1);
        auth = auth.replace(/:\d+$/, '');
        return auth.toLowerCase().replace(/\.$/, '');
    }

    /** 黑名单命中（精确或子域；与后端 owLCBlockHit 同口径） */
    function blocked(host) {
        var list = w.OW_LC_BLOCKED || [];
        if (!host || !list.length) return false;
        for (var i = 0; i < list.length; i++) {
            var dd = String(list[i] || '').toLowerCase();
            if (!dd) continue;
            if (host === dd) return true;
            if (host.length > dd.length + 1 && host.slice(-(dd.length + 1)) === '.' + dd) return true;
        }
        return false;
    }

    /** 从消息节点里挑出要出卡的链接（最多 OW_LC_MAX 个，默认 1） */
    function pickUrls(msg) {
        var body = msg.querySelector('.ow-msg-body');
        if (!body) return [];
        if (body.querySelector('.lc-msgcard')) return [];        // 已出过卡
        var max = parseInt(w.OW_LC_MAX, 10) || 1;
        var as = body.querySelectorAll('a.ow-msg-link[href]');
        var out = [];
        for (var i = 0; i < as.length && out.length < max; i++) {
            var a = as[i];
            if ((a.className || '').indexOf('ow-link-blocked') >= 0) continue;   // 站点已拦截的不出卡
            var href = a.getAttribute('href') || '';
            if (!/^https?:\/\//i.test(href)) continue;
            var h = hostOf(href);
            if (!h || blocked(h)) continue;
            var dup = false;
            for (var k = 0; k < out.length; k++) if (out[k].url === href) { dup = true; break; }
            // 连锚点一起带出来：出卡后要把这个链接藏掉（卡片已经表达了同一件事）
            if (!dup) out.push({ url: href, el: a });
        }
        return out;
    }

    /** 入队并安排发车。items = [{url, el}] */
    function enqueue(msg, items) {
        var added = false;
        for (var i = 0; i < items.length; i++) {
            var u = items[i].url, at = { msg: msg, el: items[i].el };
            if (cache[u]) {                                   // 已有结果：直接出卡，不再请求
                if (cache[u].ok) mount(at, cache[u].data);
                continue;
            }
            if (!waiting[u]) { waiting[u] = []; }
            var seen = false;
            for (var j = 0; j < waiting[u].length; j++) {
                if (waiting[u][j].msg === msg && waiting[u][j].el === items[i].el) { seen = true; break; }
            }
            if (!seen) waiting[u].push(at);
            if (queue.indexOf(u) < 0) { queue.push(u); added = true; }
        }
        if (added) schedule();
    }

    function schedule() {
        if (timer) return;
        timer = setTimeout(flush, 350);      // 合并同一批渲染出的多条消息，少发几次请求
    }

    function flush() {
        timer = null;
        if (inflight) { schedule(); return; }
        if (!w.OwApi || !w.OwApi.key) { schedule(); return; }   // 密钥未就绪（首屏初始化顺序）：等下一轮
        if (!queue.length) return;
        var batch = [];
        while (queue.length && batch.length < PER_CALL) {
            var u = queue.shift();
            if (!cache[u] && batch.indexOf(u) < 0) batch.push(u);
        }
        if (!batch.length) return;

        inflight = true;
        w.OwApi.post('plugin_link_card_preview', { urls: JSON.stringify(batch) }, function (r) {
            inflight = false;
            var data = (r && r.ok && r.data) ? r.data : null;
            for (var i = 0; i < batch.length; i++) {
                var u = batch[i];
                cache[u] = (data && data[u]) ? data[u] : { ok: false, reason: (r && r.msg) || '加载失败' };
            }
            for (var j = 0; j < batch.length; j++) resolve(batch[j]);
            if (queue.length) schedule();
        });
    }

    /** 把某个 URL 的结果分发给等它的消息 */
    function resolve(url) {
        var list = waiting[url] || [];
        delete waiting[url];
        var r = cache[url];
        if (!r || !r.ok) return;     // 失败 / 被拦：静默不出卡，不打扰用户
        for (var i = 0; i < list.length; i++) mount(list[i], r.data);
    }

    /**
     * 出卡后把原始链接藏掉（卡片表达的已是同一件事，一条消息里链接 + 卡片是重复）。
     * 只藏**被出卡的那个锚点**；消息里还有别的内容时气泡照留，
     * 若气泡里只剩空白（正文就只有这个链接）连气泡一起收起来，免得留一个空气泡。
     * 标记 data-lc-hidden 记下「是我们藏的」：撤回时好原样还原。
     */
    function hideLink(msg, el) {
        if (!el || !el.parentNode) return;
        el.style.display = 'none';
        el.setAttribute('data-lc-hidden', '1');
        var holder = el.parentNode;   // .ow-msg-content / .ow-msg-plain
        if (!holder || !holder.className || holder.className.indexOf('ow-msg-') < 0) return;
        // 正文里除了已藏的链接还剩不剩文字？
        // ⚠️ 不能直接用 textContent 判空：display:none 的锚点文本仍算在 textContent 里，
        //    会得出「还有内容」的结论，于是纯链接的消息留一个空气泡（实测踩过）。
        var rest = holder.textContent || '';
        var hidden = holder.querySelectorAll('a.ow-msg-link[data-lc-hidden]');
        for (var i = 0; i < hidden.length; i++) {
            var t = hidden[i].textContent || '';
            if (t !== '') rest = rest.split(t).join('');
        }
        if (rest.replace(/\s+/g, '') === '') {
            holder.style.display = 'none';
            holder.setAttribute('data-lc-hidden', '1');
        }
    }

    /** 还原被藏起来的链接与气泡（卡片被摘掉时调用） */
    function restoreLink(msg) {
        var hs = msg.querySelectorAll('[data-lc-hidden]');
        for (var i = 0; i < hs.length; i++) {
            hs[i].style.display = '';
            hs[i].removeAttribute('data-lc-hidden');
        }
    }

    /** 出卡：全部用 createElement + textContent，远端数据不进 innerHTML */
    function mount(at, p) {
        if (!p || !p.title) return;
        var msg = at.msg;
        if (!msg) return;
        var body = msg.querySelector('.ow-msg-body');
        if (!body || body.querySelector('.lc-msgcard')) return;
        if (msg.className && (msg.className.indexOf('recalled') >= 0 || msg.className.indexOf('deleted') >= 0)) return;

        var card = d.createElement('a');
        card.className = 'lc-msgcard';
        card.setAttribute('href', p.url || '#');
        card.setAttribute('target', '_blank');
        card.setAttribute('rel', 'noopener noreferrer nofollow');

        // 图区**恒占同样高度**：没有封面图时放一个站名首字母占位块 ——
        // 卡片是固定尺寸的（多张卡在消息里排下来要一样大，不能有图一张没图一张）
        if (p.image && /^https:\/\//i.test(p.image)) {
            // 只接受 https（本站强制 https，http 图会被混合内容策略拦掉）；
            // referrerpolicy=no-referrer 不把聊天页地址透露给图片站；加载失败换成占位块
            var im = d.createElement('img');
            im.className = 'lc-msgcard-img';
            im.alt = '';
            im.setAttribute('loading', 'lazy');
            im.setAttribute('referrerpolicy', 'no-referrer');
            im.onerror = function () {
                if (im.parentNode) im.parentNode.replaceChild(placeholder(p), im);
            };
            im.src = p.image;
            card.appendChild(im);
        } else {
            card.appendChild(placeholder(p));
        }

        var bx = d.createElement('div');
        bx.className = 'lc-msgcard-body';
        var site = d.createElement('span');
        site.className = 'lc-msgcard-site';
        site.textContent = p.site || hostOf(p.url);
        bx.appendChild(site);
        var title = d.createElement('div');
        title.className = 'lc-msgcard-title';
        title.textContent = p.title;
        bx.appendChild(title);
        // 描述即使为空也占位（固定高度靠它撑住：标题/描述都恒占两行的位置）
        var desc = d.createElement('div');
        desc.className = 'lc-msgcard-desc';
        desc.textContent = p.description || '';
        bx.appendChild(desc);
        card.appendChild(bx);
        body.appendChild(card);

        hideLink(msg, at.el);
    }

    /** 无封面图（或图挂了）时的占位块：与封面图同高，取站名首字母 */
    function placeholder(p) {
        var ph = d.createElement('div');
        ph.className = 'lc-msgcard-img lc-msgcard-ph';
        var host = p.site || hostOf(p.url) || '?';
        var ch = (host.replace(/^www\./i, '').charAt(0) || '?').toUpperCase();
        ph.textContent = ch;
        return ph;
    }

    /** 撤回 / 删除后摘掉陈旧卡片，并还原被藏起来的链接 */
    function sweep(msg) {
        var card = msg.querySelector('.lc-msgcard');
        if (!card) return;
        var cls = msg.className || '';
        if (cls.indexOf('recalled') >= 0 || cls.indexOf('deleted') >= 0) {
            card.parentNode.removeChild(card);
            restoreLink(msg);
        }
    }

    /** 最近的 .ow-msg 祖先（不用 Element.closest：老浏览器没有这个方法） */
    function closestMsg(node) {
        var n = node;
        while (n && n !== box && n.nodeType === 1) {
            if (n.classList && n.classList.contains('ow-msg')) return n;
            n = n.parentNode;
        }
        return null;
    }

    function handle(msg) {
        if (w.OW_LC_MSG_ON != 1) return;                 // 调用时读（见文件头说明）
        // 系统提示与站点通知不参与：它们不是用户分享的链接，出卡既无意义又多一次出网
        var cls = msg.className || '';
        if (cls.indexOf('ow-msg-system') >= 0 || cls.indexOf('system') >= 0
            || cls.indexOf('ow-msg-notice') >= 0) return;
        sweep(msg);
        var urls = pickUrls(msg);
        if (urls.length) enqueue(msg, urls);
    }

    function scanFrom(node) {
        if (!node || node.nodeType !== 1) return;
        if (node.classList && node.classList.contains('ow-msg')) { handle(node); return; }
        if (!node.querySelectorAll) return;
        var ms = node.querySelectorAll('.ow-msg');
        for (var i = 0; i < ms.length; i++) handle(ms[i]);
    }

    /* ---------------- 挂载 ---------------- */
    var box = d.getElementById('owMessages');
    if (!box) return;      // 不在聊天页（合并资源包在后台页也会加载）：安静退出

    if (w.MutationObserver) {
        new w.MutationObserver(function (recs) {
            for (var i = 0; i < recs.length; i++) {
                var rec = recs[i];
                for (var j = 0; j < rec.addedNodes.length; j++) scanFrom(rec.addedNodes[j]);
                // 撤回/删除走的是「替换内容元素」，没有新消息节点 —— 从变动目标的所属消息上收尾
                var own = closestMsg(rec.target);
                if (own) sweep(own);
            }
        }).observe(box, { childList: true, subtree: true });
    }
    // 首屏兜底：脚本可能在历史消息已渲染之后才执行
    var init = box.querySelectorAll('.ow-msg');
    for (var n = 0; n < init.length; n++) handle(init[n]);

    /* 对外只暴露一个调试入口（排查「为什么没出卡」时用） */
    w.OwLcChat = {
        cache: cache,
        /** 手动重扫全量消息（例如刚改完黑名单） */
        rescan: function () {
            var ms = box.querySelectorAll('.ow-msg');
            for (var i = 0; i < ms.length; i++) handle(ms[i]);
        }
    };
})(window, document);
