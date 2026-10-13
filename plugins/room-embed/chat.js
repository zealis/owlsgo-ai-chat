/**
 * 群聊嵌入插件（room-embed）前端脚本 v2.0.0
 *
 * 职责：
 *   1. 嵌入模式（?embed=1）：域名白名单守卫 + UI 微调（隐藏音效按钮/拖拽手柄）
 *      + 嵌入令牌引导 + Cookie 不到场的「恢复连接」兜底
 *   2. 普通模式：通过 OwChat.onRoomEdit 在群聊信息面板追加「嵌入代码」入口
 *      —— 仅群主/超级管理员可见，点击填参数（域名白名单/启动方式）→ 签发令牌 → 出代码
 *
 * 兼容约束：仅用 var / function，无箭头函数、const、let、fetch。
 *
 * v1.1.0：嵌入代码响应式（max-width/max-height + dvh 渐进增强）；幂等标记统一。
 * v1.1.1：跨站嵌入的 Cookie 不到场检测与 Storage Access 恢复引导。
 * v1.2.0：嵌入令牌——跨站 iframe 无 cookie 时的完全修复（<head> XHR 预打包 +
 *   OwApi 双保险；签发走 OwApi.secure；探测带令牌）。
 * v1.3.0（2026-10-12）：
 *   - 域名白名单：page.head 下发 OW_EMBED_DOMAINS，嵌入页按 document.referrer 自检，
 *     不在名单内直接替换页面为「未授权」提示（服务端 sign.fallback 同步校验，双保险）；
 *   - 启动方式默认改浮动手机按钮：点击展开聊天、点聊天室外部/Esc 自动收回；
 *     保留「直接展开」；生成前先出参数面板（白名单 + 启动方式）。
 * v1.4.0（2026-10-12）：
 *   - 令牌导航保活主体在 main.php 的 page.head 每页脚本（window.name 载体 + 加载恢复
 *     + 点击/改地址补参数），本文件的令牌引导因此拿到「恢复后」的 URL，行为不变；
 *   - domainGuard 优先读 URL 里的 embed_origin（恢复后仍在），referrer 仅作老代码兜底。
 * v1.4.1（2026-10-12）：
 *   - 修「返回聊天仍显示请选择一个群聊」：等级页 URL 被点击钩子补上令牌后走
 *     「URL 带令牌」分支，window.name 的房间被覆盖成空。main.php 已改为合并语义
 *     （没带房间就保留上次的），本文件无逻辑改动。
 * v1.5.0（2026-10-12）：
 *   - 嵌入态顶栏新增「登录/注册」入口：第三方 iframe 存不了登录 cookie，
 *     点它新标签页打开主站登录页（第一方上下文）；已登录身份（同站嵌入）不注入；
 *   - 启动器图标底色改自动适配页面深浅（autoTheme 沿祖先链算背景亮度，
 *     浅页深底白图形/深页白底深图形），可用 --owlsgo-launcher-bg/fg 覆盖，
 *     transparent 即透明图标；替换掉原来写死的黄褐兜底色。
 * v2.0.0（2026-10-13）：
 *   - 弹窗授权登录（OAuth 式）：嵌入窗内点「登录/注册」→ window.open 弹出主站
 *     登录小窗（第一方，cookie 正常）→ 小窗内登录/注册成功 → 小窗里的聊天页
 *     检测 window.name 授权标记 → OwApi.secure 签发一次性授权码 → postMessage
 *     回传 iframe → iframe 带 embed_login 重载，服务端换发 30 天会话令牌并注入
 *     OW_EMBED_USER/SESS → 本文件据此覆盖 cfg 身份、顶栏显示昵称 + 登出；
 *   - 会话令牌经 postMessage 交父页面持久化（父页面刷新/重开 iframe 时带上），
 *     iframe 内导航靠 main.php 的 window.name 上下文续命；
 *   - 登出：OwApi.secure 吊销会话 + 广播父页面清令牌重载（退回访客）。
 */
(function () {
    var w = window;
    if (!w.OwChat || !w.OwChat.cfg) return;
    if (w.__owRoomEmbedLoaded) return;
    w.__owRoomEmbedLoaded = true;

    var isEmbed = /[?&]embed=1(&|$)/.test(w.location.search);

    /* ---------- v2.0.0：授权小窗分支（本页是弹出的登录小窗） ----------
       判定：有 opener（是被 window.open 弹出来的）+ window.name 带授权标记
       （opener 写的，跨导航存活——登录成功后会从 ?page=login 跳到 ?page=chat，
       标记跟着走到聊天页）。小窗整个流程都停留在主站第一方上下文，cookie 正常，
       所以登录/注册（含自动登录）在这里能真正建立会话。 */
    if (isAuthPopup()) { doAuthPopup(); return; }
    function isAuthPopup() {
        try {
            return !!w.opener && w.opener !== w && (w.name || '').indexOf('owlsgo_embed_auth|') === 0;
        } catch (e) { return false; }
    }
    function doAuthPopup() {
        // 标记格式：owlsgo_embed_auth|state|token（state 防跨站伪造消息，token 定位嵌入房间）
        var parts = (w.name || '').split('|');
        var state = parts[1] || '', tok = parts[2] || '';
        if (!/^[a-f0-9]{32}$/.test(tok)) { try { w.close(); } catch (e) {} return; }
        function finish(r) {
            if (r && r.ok && r.code) {
                // 回传 opener（= 嵌入 iframe，同源）：targetOrigin 用本窗源，
                // 消息只投递给 opener 窗口，其他页面读不到。
                try { w.opener.postMessage({ type: 'owlsgo_embed_auth', state: state, code: r.code }, w.location.origin); } catch (e) {}
            }
            setTimeout(function () { try { w.close(); } catch (e) {} }, 250);
        }
        function go(tryNo) {
            OwApi.secure('plugin_room_embed_auth_code', { embed_token: tok }, function (r) {
                // ⚠️ 为什么要延迟 + 多次重试：聊天页首屏会并发打一串请求（history/poll/各插件），
                // 核心的会话处理器**不加锁**（长轮询必须尽早释放会话，是有意设计），
                // 首屏请求会把刚签发的一次性票据从会话里冲掉 → 敏感校验 403。
                // 洪峰长度不定（插件多、网络慢就长），所以退避重试到 ~9s 覆盖整个窗口，
                // 而不是赌一个固定等待。实测单次/两次重试都会偶发失败。
                if (r && r.ok) { finish(r); return; }
                if (tryNo < 8) { setTimeout(function () { go(tryNo + 1); }, 700 + tryNo * 250); return; }
                finish(r);
            });
        }
        setTimeout(function () { go(0); }, 1200);
    }

    /* ---------- 嵌入模式：隐藏音效按钮、隐藏输入框拖拽手柄 ---------- */
    if (isEmbed) {
        // v1.3.0：域名白名单守卫。未授权域名进来只留一句人话（服务端同步拒令牌，
        // 双保险），不留半死的聊天窗。名单为空 = 不限制；referrer 取不到不拦（服务端兜底）。
        if (!domainGuard()) {
            document.body.innerHTML = '<div class="ow-embed-deny">'
                + '<b>该网站未获授权使用此聊天窗</b>'
                + '<span>请联系网站管理员，在生成嵌入代码时把本域名加入白名单。</span>'
                + '</div>';
            return;
        }
        function trimEmbedUI() {
            var sound = document.getElementById('owBtnSound');
            if (sound) sound.style.display = 'none';
            var resize = document.getElementById('owInputResize');
            if (resize) resize.style.display = 'none';
        }
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            trimEmbedUI();
        } else {
            document.addEventListener('DOMContentLoaded', trimEmbedUI);
        }
        injectLoginEntry();   // v1.5.0/v2.0.0：登录/注册入口（弹窗授权）
        embedAuthBoot();      // v2.0.0：登录态 UI + 授权码回传监听
        embedTokenBoot();     // v1.2.0：令牌引导（跨站无 cookie 时顶替签名门禁）
        embedGuard();         // 兜底：无令牌/令牌失效时的恢复引导
        return;
    }

    /* ---------- 嵌入模式：登录/注册入口（v1.5.0 新标签页 → v2.0.0 弹窗授权） ----------
       为什么不在嵌入窗口内直接登录：第三方 iframe 里浏览器拒绝落地会话 cookie
       （SameSite=Lax 不允许在第三方上下文存储），登录态根本无法保存在 iframe 内。
       v2.0.0 起改走「弹窗授权」：点入口弹出主站登录小窗（第一方上下文，cookie 正常），
       小窗里登录/注册成功后会签发一次性授权码回传本窗，本窗带码重载后服务端把身份
       升级成用户本人——和「微信/Google 第三方登录」同一个模式。弹窗被拦截时退回
       v1.5.0 的新标签页行为。同站嵌入且已登录时（actor.kind=user）不注入。 */
    var AUTH_STATE = '';
    function injectLoginEntry() {
        var actor = w.OwChat.cfg && w.OwChat.cfg.actor;
        if (actor && actor.kind === 'user') return;          // 已登录（同站嵌入场景）不显示
        if (document.getElementById('owEmbedLogin')) return;
        function place() {
            if (document.getElementById('owEmbedLogin')) return;
            var bar = document.querySelector('.ow-topbar');
            if (!bar) return;
            var base = (w.OwChat.cfg && w.OwChat.cfg.site_url) ? w.OwChat.cfg.site_url : w.location.origin;
            var a = document.createElement('a');
            a.id = 'owEmbedLogin';
            a.className = 'ow-embed-login';
            a.href = base + '/?page=login';
            a.target = '_blank';
            a.rel = 'noopener';
            a.textContent = '登录/注册';
            a.title = '弹出小窗登录主站账号，成功后即可用本人身份聊天';
            a.onclick = function (e) {
                // 弹窗授权：onclick 里 window.open 才算用户手势，不会被拦截。
                // 被拦时**不** preventDefault——让 <a target="_blank"> 的默认行为兜底
                // （新标签页打开主站，v1.5.0 的老路），登录这条路永远走得通。
                var pop = openAuthPopup(base, 'login');
                if (pop) { e.preventDefault(); return false; }
                return true;
            };
            bar.appendChild(a);
        }
        if (document.readyState === 'complete' || document.readyState === 'interactive') place();
        else document.addEventListener('DOMContentLoaded', place);
    }

    /** 弹出授权小窗并写好 window.name 标记（跨导航存活，登录成功跳到聊天页后仍读得到）。
     *  返回小窗窗口；被拦截返回 null（调用方走 <a> 默认行为兜底）。 */
    function openAuthPopup(base, kind) {
        var tok = embedTokenOf();
        if (!tok) return null;   // 没令牌（老代码）→ 让调用方走新标签页
        AUTH_STATE = Math.random().toString(36).slice(2, 12);
        var pop = null;
        try {
            pop = w.open(base + '/?page=' + kind + '&embed_auth=1', 'owlsgo_embed_auth', 'width=460,height=620');
        } catch (e) {}
        if (!pop) return null;
        try { pop.name = 'owlsgo_embed_auth|' + AUTH_STATE + '|' + tok; } catch (e) {}
        return pop;
    }

    /** 当前嵌入令牌（URL → localStorage → window.name 上下文，兼容保活恢复） */
    function embedTokenOf() {
        var m = /[?&]embed_token=([a-f0-9]{32})/.exec(w.location.search);
        if (m) return m[1];
        try { var ls = w.localStorage.getItem('ow_embed_token'); if (ls) return ls; } catch (e) {}
        try {
            var n = w.name || '';
            if (n.indexOf('owEmbedCtx=') === 0) {
                var p = n.slice(11).split('|');
                if (/^[a-f0-9]{32}$/.test(p[0] || '')) return p[0];
            }
        } catch (e) {}
        return '';
    }

    /* ---------- v2.0.0：登录态 UI（昵称 + 登出）与授权码回传监听 ---------- */
    function embedAuthBoot() {
        // ① 页面渲染时服务端已把授权码换成会话令牌并注入 OW_EMBED_USER/SESS
        var user = w.OW_EMBED_USER, sess = w.OW_EMBED_SESS;
        if (user && sess) {
            // 交给父页面持久化：父页面刷新/重开 iframe 时把 sess 拼回 src
            postToParent({ type: 'owlsgo_embed_sess', sess: sess });
            patchLoggedIn(user, sess);
        }
        // ② 监听授权小窗回传的一次性授权码（同源消息：小窗的 opener 就是本 iframe）
        w.addEventListener('message', function (e) {
            if (e.origin !== w.location.origin) return;
            var d = e.data;
            if (!d || d.type !== 'owlsgo_embed_auth' || !d.code) return;
            if (d.state !== AUTH_STATE) return;   // 非本窗发起的授权（防跨站伪造）
            AUTH_STATE = '';
            // 带授权码整页重载：服务端在 page.head 消费码并换发会话令牌
            var kept = [];
            (w.location.search || '').replace(/^\?/, '').split('&').forEach(function (kv) {
                if (kv && kv.indexOf('embed_login=') !== 0 && kv.indexOf('embed_sess=') !== 0) kept.push(kv);
            });
            kept.push('embed_login=' + d.code);
            w.location.search = '?' + kept.join('&');
        });
    }

    /** 覆盖 cfg 身份 + 顶栏换成「昵称 + 登出」 */
    function patchLoggedIn(user, sess) {
        try {
            w.OwChat.cfg.actor = {
                kind: 'user', id: user.id, nickname: user.nickname,
                role: user.role || 'member', avatar: user.avatar || ''
            };
            w.OwChat.cfg.me = {
                id: user.id, nickname: user.nickname, role: user.role || 'member',
                avatar: user.avatar || '', title: ''
            };
        } catch (e) {}
        function paint() {
            var old = document.getElementById('owEmbedLogin');
            var bar = document.querySelector('.ow-topbar');
            if (!bar) return;
            var me = document.getElementById('owEmbedMe');
            if (!me) {
                me = document.createElement('span');
                me.id = 'owEmbedMe';
                me.className = 'ow-embed-me';
                me.textContent = user.nickname;
                bar.appendChild(me);
            }
            var out = document.getElementById('owEmbedLogout');
            if (!out) {
                out = document.createElement('a');
                out.id = 'owEmbedLogout';
                out.className = 'ow-embed-login';
                out.href = 'javascript:void(0)';
                out.textContent = '登出';
                out.title = '退出登录，退回访客身份';
                out.onclick = function () { embedLogout(sess); };
                bar.appendChild(out);
            }
            if (old && old.parentNode) old.parentNode.removeChild(old);
        }
        if (document.readyState === 'complete' || document.readyState === 'interactive') paint();
        else document.addEventListener('DOMContentLoaded', paint);
    }

    /** 登出：服务端吊销会话 + 广播父页面清令牌并重载 iframe（退回访客） */
    function embedLogout(sess) {
        try {
            OwApi.secure('plugin_room_embed_auth_logout', { embed_sess: sess }, function () {
                postToParent({ type: 'owlsgo_embed_logout' });
                // 父页面不在线（直接展开模式被剥离等）时自己回退
                setTimeout(function () {
                    var kept = [];
                    (w.location.search || '').replace(/^\?/, '').split('&').forEach(function (kv) {
                        if (kv && kv.indexOf('embed_sess=') !== 0 && kv.indexOf('embed_login=') !== 0) kept.push(kv);
                    });
                    w.location.search = kept.length ? '?' + kept.join('&') : '';
                }, 400);
            });
        } catch (e) {}
    }

    /** 跨站 postMessage 给父页面（目标源从 URL 的 embed_parent 取，父页面脚本写入） */
    function postToParent(msg) {
        var target = '*';
        var m = /[?&]embed_parent=([^&]+)/.exec(w.location.search);
        if (m) {
            try { target = decodeURIComponent(m[1]); } catch (e) {}
        }
        try { w.parent.postMessage(msg, target); } catch (e) {}
    }

    /* ---------- 嵌入模式：域名白名单守卫（v1.3.0） ----------
       page.head 已把该令牌的白名单注入 window.OW_EMBED_DOMAINS。
       父域名取 URL 里的 embed_origin（父页面写入的 location.hostname，浏览器控制、
       第三方伪造不了，且重载后仍在 URL 里——比 document.referrer 可靠，referrer 在
       iframe 自动刷新后会变成聊天页自己）；老代码没有该参数时回落 referrer。
       服务端 sign.fallback 用请求里的 embed_origin 做同样校验（权威），前端只为友好提示。 */
    function domainGuard() {
        var allowed = w.OW_EMBED_DOMAINS;
        if (!allowed || !allowed.length) return true;
        var host = '';
        var mo = /[?&]embed_origin=([a-z0-9.-]+)/.exec(w.location.search);
        if (mo) {
            host = mo[1].toLowerCase();
        } else {
            var ref = document.referrer || '';
            if (!ref) return true;
            var m = /^https?:\/\/([^\/:]+)/i.exec(ref);
            host = m ? m[1].toLowerCase() : '';
            if (!host) return true;
        }
        for (var i = 0; i < allowed.length; i++) {
            var d = allowed[i];
            if (d === '*' || host === d) return true;
            var suffix = '.' + d;
            if (host.length > suffix.length && host.slice(-suffix.length) === suffix) return true;
        }
        return false;
    }

    /* ---------- 嵌入模式：嵌入令牌（v1.2.0） ----------
       跨站 iframe 被浏览器拦下会话 Cookie 时，页面烘的签名 key 永远对不上（全站 403）。
       嵌入码内的令牌经 OwApi 随每个请求附带，服务端经 sign.fallback 钩子校验后放行，
       并按「令牌 + 访客标识」解析出稳定游客身份（无 cookie 依赖，每访客一身份）。
       令牌/访客标识读取顺序：URL → localStorage 兜底。**不抹地址栏参数**——第三方 iframe 里
       localStorage 可能被浏览器一并拦掉（setItem 直接抛 SecurityError），URL 是重载后
       唯一可靠来源；跨站 referrer 默认策略只传 origin，查询串不会外泄给父站点。
       注意：核心 init 的首批请求早于本脚本加载，那波由 main.php 在 <head> 里注入的
       XHR 预打包脚本负责带令牌；这里的 OwApi 包装覆盖其后所有调用（双保险，幂等防重）。 */
    var OW_RE_TOK_KEY = 'ow_embed_token';
    var EMBED_TOKEN = '';
    var EMBED_VISITOR = '';
    var EMBED_ORIGIN = '';
    var EMBED_SESS = '';
    function embedTokenBoot() {
        var m = /[?&]embed_token=([a-f0-9]{32})/.exec(w.location.search);
        var v = /[?&]embed_visitor=([a-z0-9]{4,16})/.exec(w.location.search);
        var o = /[?&]embed_origin=([a-z0-9.-]+)/.exec(w.location.search);
        var s = /[?&]embed_sess=([a-f0-9]{32})/.exec(w.location.search);
        if (m) {
            EMBED_TOKEN = m[1];
            try { w.localStorage.setItem(OW_RE_TOK_KEY, EMBED_TOKEN); } catch (e) {}
        } else {
            try { EMBED_TOKEN = w.localStorage.getItem(OW_RE_TOK_KEY) || ''; } catch (e) {}
        }
        EMBED_VISITOR = v ? v[1] : '';
        EMBED_ORIGIN = o ? o[1] : '';
        // v2.0.0：会话令牌——服务端注入值最新鲜（刚兑换/刚校验），URL 的作兜底
        EMBED_SESS = '';
        try {
            if (w.OW_EMBED_SESS && /^[a-f0-9]{32}$/.test(w.OW_EMBED_SESS)) EMBED_SESS = w.OW_EMBED_SESS;
        } catch (e) {}
        if (!EMBED_SESS && s) EMBED_SESS = s[1];
        if (EMBED_TOKEN) wrapOwApiToken(EMBED_TOKEN, EMBED_VISITOR, EMBED_ORIGIN, EMBED_SESS);
    }
    function wrapOwApiToken(tok, vis, org, sess) {
        function inject(data) {
            data = data || {};
            if (!data.embed_token) data.embed_token = tok;
            if (vis && !data.embed_visitor) data.embed_visitor = vis;
            if (org && !data.embed_origin) data.embed_origin = org;
            if (sess && !data.embed_sess) data.embed_sess = sess;   // v2.0.0：带上会话令牌（身份=用户本人）
            return data;
        }
        // ⚠️ 必须 .call(this, …)：原方法内部用 this.sign()/this.post()，裸调会把 this 丢成
        // undefined（严格模式下 this.sign 直接 TypeError，history/poll 全部发不出）。
        var origPost = w.OwApi.post, origUpload = w.OwApi.upload, origSecure = w.OwApi.secure;
        w.OwApi.post = function (action, data, cb) { return origPost.call(this, action, inject(data), cb); };
        w.OwApi.upload = function (action, file, extra, cb) {
            if (typeof extra === 'function') { cb = extra; extra = null; }
            return origUpload.call(this, action, file, inject(extra), cb);
        };
        w.OwApi.secure = function (action, data, cb) { return origSecure.call(this, action, inject(data), cb); };
    }

    /* ---------- 嵌入模式：Cookie 不到场检测与恢复（v1.1.1，令牌无效时才亮） ----------
       根因：跨站嵌入时浏览器拒绝存储第三方 iframe 里下发的 Set-Cookie（会话 cookie 是
       SameSite=Lax，不允许在第三方上下文落地），iframe 内每个请求都是新会话 →
       签名对不上 → 全站 action 403。有有效令牌时探测直接通过，本提示条不出现。
       插件能做的：探测失败后给出「恢复连接」按钮（Storage Access，Safari 路径），
       失败/不支持时给「在主站打开」的出口，不再无声地 403。
       探测用 OwApi.sign() 现算签名后走裸 XHR（避开 OwApi.post 的自动刷新逻辑），
       并随身附带嵌入令牌——否则令牌有效也会被误判。 */
    function embedGuard() {
        var FLAG = 'ow_embed_sa_tried';
        function tried() {
            try { return window.sessionStorage.getItem(FLAG) === '1'; } catch (e) { return false; }
        }
        function markTried() {
            try { window.sessionStorage.setItem(FLAG, '1'); } catch (e) {}
        }
        function probe() {
            if (document.getElementById('owEmbedGuard')) return;
            var s = null;
            try { s = w.OwApi.sign('rooms'); } catch (e) { return; }
            var x = new XMLHttpRequest();
            x.open('POST', '?action=rooms', true);
            x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            x.onreadystatechange = function () {
                if (x.readyState !== 4 || x.status !== 403) return;
                var r = null;
                try { r = JSON.parse(x.responseText); } catch (e) {}
                if (r && r.msg && r.msg.indexOf('签名验证失败') >= 0) showBar();
            };
            var body = 'ts=' + s.ts + '&sign=' + s.sign;
            if (EMBED_TOKEN) {
                body += '&embed_token=' + EMBED_TOKEN;
                if (EMBED_VISITOR) body += '&embed_visitor=' + EMBED_VISITOR;
                if (EMBED_ORIGIN) body += '&embed_origin=' + EMBED_ORIGIN;
                if (EMBED_SESS) body += '&embed_sess=' + EMBED_SESS;   // v2.0.0：有会话令牌一并带
            }
            x.send(body);
        }
        function openInSite() {
            var base = (w.OwChat.cfg && w.OwChat.cfg.site_url) ? w.OwChat.cfg.site_url : '';
            var m = /[?&]room=(\d+)/.exec(w.location.search);
            return base + '/?page=chat' + (m ? '&room=' + m[1] : '');
        }
        function showBar() {
            if (document.getElementById('owEmbedGuard')) return;
            var host = document.querySelector('.ow-main') || document.body;
            var bar = document.createElement('div');
            bar.className = 'ow-notice ow-notice-warn ow-embed-guard';
            bar.id = 'owEmbedGuard';
            if (tried()) {
                bar.innerHTML = '<span class="ow-embed-guard-t">此浏览器无法恢复嵌入连接，'
                    + '聊天需在主站进行。</span>';
                var a = document.createElement('a');
                a.className = 'ow-btn ow-btn-primary ow-embed-guard-b';
                a.href = openInSite();
                a.target = '_blank';
                a.rel = 'noopener';
                a.textContent = '在主站打开';
                bar.appendChild(a);
            } else {
                bar.innerHTML = '<span class="ow-embed-guard-t">浏览器阻止了嵌入页保存连接状态，'
                    + '消息暂时无法发送。</span>';
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'ow-btn ow-btn-primary ow-embed-guard-b';
                btn.textContent = '恢复连接';
                btn.onclick = function () {
                    markTried();
                    if (typeof document.requestStorageAccess !== 'function') { showBar(); return; }
                    btn.disabled = true;
                    btn.textContent = '正在恢复…';
                    document.requestStorageAccess().then(function () {
                        btn.textContent = '已恢复，重新载入…';
                        setTimeout(function () { w.location.reload(); }, 400);
                    }).catch(function () { showBar(); });
                };
                bar.appendChild(btn);
            }
            var inputbar = document.querySelector('.ow-inputbar');
            if (inputbar && inputbar.parentNode) inputbar.parentNode.insertBefore(bar, inputbar);
            else host.appendChild(bar);
        }
        // 页面自身请求会先打一轮（含一次自动刷新），延迟探测避开风头
        setTimeout(probe, 2000);
    }

    /* ---------- 普通模式：群聊信息面板追加「嵌入代码」入口 ---------- */
    w.OwRoomEmbed = {
        /** 第一步：参数面板（域名白名单 + 启动方式） */
        showSetup: function (roomId, roomName) {
            w.OwChat._owEmbedRoomName = roomName;
            var html = '<h3>嵌入代码</h3>'
                + '<p style="color:var(--ow-text-sub,#999);font-size:13px;margin:0 0 10px">生成该群的嵌入代码。每次生成都会重新签发嵌入令牌，旧代码立即失效。</p>'
                + '<div class="ow-form-item"><label>允许加载的域名（每行一个，留空 = 不限制）</label>'
                + '<textarea id="owEmbedDomains" class="ow-input" rows="3" placeholder="example.com&#10;www.example.com" '
                + 'style="width:100%;resize:vertical;font-size:13px"></textarea>'
                + '<p style="font-size:12px;color:var(--ow-text-sub);margin-top:4px">填 example.com 同时覆盖其子域名；填 * 表示任意域名。只有名单内的域名才能加载聊天（服务端与前端双重校验）。</p></div>'
                + '<div class="ow-form-item"><label>启动方式</label>'
                + '<select class="ow-input" id="owEmbedMode">'
                + '<option value="launcher">浮动手机按钮（点击展开，点外部收回）</option>'
                + '<option value="direct">直接展开聊天</option></select></div>'
                + '<div class="ow-form-row" style="gap:8px">'
                + '<button class="ow-btn ow-btn-primary" onclick="OwRoomEmbed.generate(' + roomId + ')">生成代码</button>'
                + '<button class="ow-btn ow-btn-ghost" onclick="OwChat.closeModal()">取消</button>'
                + '</div>';
            w.OwChat.openModal(html, 460);
        },

        /** 第二步：签发令牌（敏感操作，一次性票据）并出代码 */
        generate: function (roomId) {
            var dEl = document.getElementById('owEmbedDomains');
            var mEl = document.getElementById('owEmbedMode');
            var domains = dEl ? dEl.value : '';
            var mode = mEl ? mEl.value : 'launcher';
            OwApi.secure('plugin_room_embed_token', { room_id: roomId, domains: domains }, function (r) {
                if (!r.ok) { toast(r.msg || '签发嵌入令牌失败'); return; }
                w.OwRoomEmbed.showCode(roomId, w.OwChat._owEmbedRoomName || '', r.token, r.expires_at, mode);
            });
        },

        /** 生成并展示嵌入代码（v1.3.0：默认浮动手机按钮启动器） */
        showCode: function (roomId, roomName, token, expiresAt, mode) {
            var base = (w.OwChat.cfg && w.OwChat.cfg.site_url) ? w.OwChat.cfg.site_url : '';
            if (!base) base = w.location.origin;   // 未配「固定网站地址」时退回当前源
            var direct = mode === 'direct';
            var src = base + '/?page=chat&room=' + roomId + '&embed=1'
                + (token ? '&embed_token=' + token + '&embed_visitor=' : '');
            // ⚠️ 例外（PLUGIN.md「图像像素」类）：本串是粘贴到**外部站点**运行的代码，
            // 外部页面没有 --ow-* 变量体系，按钮底色/投影按外部站点装饰处理。
            // v1.5.0：底色默认**自动适配页面深浅**（见下方 autoTheme），不再写死；
            // 站点主人仍可用 --owlsgo-launcher-bg / --owlsgo-launcher-fg 覆盖
            // （设 transparent 即得透明图标，图形色随页面底色反差）。
            // 访客标识在**父页面**（第一方）localStorage 铸造后随 iframe URL 带上——
            // 第三方 iframe 自己的存储可能被浏览器拦掉，父页面不会；每个访客因此有稳定身份。
            var frameStyle = 'position:fixed;bottom:84px;right:20px;z-index:99998;overflow:hidden;'
                + 'width:380px;max-width:calc(100vw - 24px);'
                + 'height:560px;max-height:calc(100vh - 128px);max-height:calc(100dvh - 128px);'
                + 'border:none;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.18)';
            var phoneSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" '
                + 'stroke-linecap="round" stroke-linejoin="round">'
                + '<rect x="7" y="3" width="10" height="18" rx="2"/><line x1="11" y1="18" x2="13" y2="18"/></svg>';
            var code = '<style>\n'
                + '#owlsgo-launcher{position:fixed;bottom:20px;right:20px;z-index:99999;width:52px;height:52px;\n'
                + 'border-radius:50%;cursor:pointer;border:none;padding:0;font-size:0;line-height:0;\n'
                + 'display:-webkit-flex;display:flex;align-items:center;justify-content:center;\n'
                + 'background:var(--owlsgo-launcher-bg,var(--owlsgo-launcher-bg-auto,#2b2f36));\n'
                + 'color:var(--owlsgo-launcher-fg,var(--owlsgo-launcher-fg-auto,#ffffff));\n'
                + 'box-shadow:0 4px 16px rgba(0,0,0,.22);\n'
                + '-webkit-transition:transform .15s;transition:transform .15s;}\n'
                + '#owlsgo-launcher:hover{transform:scale(1.06);}\n'
                + '#owlsgo-launcher svg{width:24px;height:24px;}\n'
                + '</style>\n'
                + '<div id="owlsgo-chat"></div>\n'
                + '<script>\n'
                + '(function(){var k="owlsgo_embed_visitor",v="";\n'
                + 'try{v=window.localStorage.getItem(k)||"";}catch(e){}\n'
                + 'if(!/^[a-z0-9]{4,16}$/.test(v)){v=Math.random().toString(16).slice(2,10);'
                + 'try{window.localStorage.setItem(k,v);}catch(e){}}\n'
                // 自动配色：沿祖先链找第一个不透明背景色算亮度——浅色页面出深底白图形，
                // 深色页面出白底深图形；取不到按浅色处理（最常见），并参考 prefers-color-scheme。
                + 'function autoTheme(){\n'
                + 'var el=document.body,bg="",n=0,c;\n'
                + 'while(el&&n<8){c=getComputedStyle(el).backgroundColor;\n'
                + 'if(c&&c!=="transparent"&&c!=="rgba(0, 0, 0, 0)"){bg=c;break;}\n'
                + 'el=el.parentElement;n++;}\n'
                + 'var light=true,m=/rgba?\\((\\d+)\\s*,\\s*(\\d+)\\s*,\\s*(\\d+)/.exec(bg||"");\n'
                + 'if(m){var L=(0.299*(+m[1])+0.587*(+m[2])+0.114*(+m[3]))/255;light=L>=0.55;}\n'
                + 'else if(window.matchMedia&&matchMedia("(prefers-color-scheme: dark)").matches)light=false;\n'
                + 'return light?["#2b2f36","#ffffff"]:["#ffffff","#2b2f36"];}\n'
                + 'var SITE=' + JSON.stringify(base) + ',ROOM=' + roomId + ',TOKEN=' + JSON.stringify(token || '')
                + ',DIRECT=' + (direct ? 'true' : 'false') + ';\n'
                // v2.0.0：登录会话令牌持久化（父页面 localStorage——第三方 iframe 自己的
                // 存储会被浏览器拦掉，父页面不会）。iframe 换发成功后经 postMessage 交回来，
                // 之后每次创建 iframe 都带上，刷新嵌入页不掉线。
                + 'var SESS_KEY="owlsgo_embed_sess",PARENT_ORIGIN=location.origin||'
                + '(location.protocol+"//"+location.host);\n'
                + 'function getSess(){try{return localStorage.getItem(SESS_KEY)||"";}catch(e){return"";}}\n'
                + 'function setSess(s){try{s?localStorage.setItem(SESS_KEY,s):localStorage.removeItem(SESS_KEY);}catch(e){}}\n'
                // iframe 地址统一入口：visitor/origin/sess 都在这拼，登出重载也走它
                + 'function frameSrc(){\n'
                + 'var q="/?page=chat&room="+ROOM+"&embed=1&embed_token="+TOKEN'
                + '+ "&embed_visitor="+v+"&embed_origin="+location.hostname'
                + '+ "&embed_parent="+encodeURIComponent(PARENT_ORIGIN);\n'
                + 'var s=getSess();if(/^[a-f0-9]{32}$/.test(s))q+="&embed_sess="+s;\n'
                + 'return SITE+q;}\n'
                + 'var launcher=document.createElement("button");\n'
                + 'launcher.id="owlsgo-launcher";launcher.type="button";launcher.title="在线聊天";\n'
                + 'launcher.innerHTML=' + JSON.stringify(phoneSvg) + ';\n'
                + 'var _t=autoTheme();\n'
                + 'launcher.style.setProperty("--owlsgo-launcher-bg-auto",_t[0]);\n'
                + 'launcher.style.setProperty("--owlsgo-launcher-fg-auto",_t[1]);\n'
                + 'var frame=null;\n'
                + 'function ensureFrame(){\n'
                + 'if(frame)return frame;\n'
                + 'frame=document.createElement("iframe");\n'
                + 'frame.src=frameSrc();\n'
                + 'frame.allow="storage-access";frame.title="聊天室";\n'
                + 'frame.style.cssText=' + JSON.stringify(frameStyle) + '+"display:none;";\n'
                + 'document.body.appendChild(frame);return frame;}\n'
                // 只听主站源的消息：iframe 回传「换发的会话令牌」与「登出广播」
                + 'window.addEventListener("message",function(e){\n'
                + 'if(e.origin!==SITE)return;var d=e.data;if(!d)return;\n'
                + 'if(d.type==="owlsgo_embed_sess"&&d.sess){setSess(d.sess);return;}\n'
                + 'if(d.type==="owlsgo_embed_logout"){\n'
                + 'setSess("");\n'
                + 'if(frame){frame.src=frameSrc();}\n'   // 不带 sess 重载 → 退回访客身份
                + 'return;}});\n'
                + 'function openChat(){ensureFrame().style.display="block";launcher.style.display="none";}\n'
                + 'function closeChat(){if(frame)frame.style.display="none";launcher.style.display="flex";}\n'
                + 'if(DIRECT){openChat();launcher.style.display="none";}\n'
                + 'else{\n'
                + 'launcher.onclick=function(e){e.stopPropagation();openChat();};\n'
                + 'document.addEventListener("click",function(e){\n'
                + 'if(!frame||frame.style.display==="none")return;\n'
                + 'if(frame.contains(e.target)||launcher.contains(e.target))return;\n'
                + 'closeChat();});\n'
                + 'document.addEventListener("keydown",function(e){if(e.keyCode===27)closeChat();});\n'
                + 'document.body.appendChild(launcher);}\n'
                + '})();\n'
                + '<\/script>';

            var html = '<h3>嵌入代码</h3>'
                + '<p style="color:var(--ow-text-sub,#999);font-size:13px;margin:0 0 10px">'
                + (direct ? '将以下代码粘贴到网站 HTML 的合适位置，聊天室将直接展开。'
                    : '将以下代码粘贴到网站 HTML 的合适位置，右下角即出现手机图标，访客点击展开聊天、点外部自动收回。')
                + '</p>'
                + '<div class="ow-form-item">'
                + '<label>群聊：' + escHtml(roomName || ('#' + roomId)) + '</label>'
                + '<textarea id="owEmbedCode" class="ow-input" rows="10" readonly style="width:100%;resize:vertical;font-family:var(--ow-mono,Consolas,monospace);font-size:12px">' + escHtml(code) + '</textarea>'
                + '</div>'
                + (token
                    ? '<p style="color:var(--ow-text-sub,#999);font-size:12px;margin:0 0 10px">代码内含嵌入令牌（有效期至 '
                      + fmtDate(expiresAt) + '）：浏览器拦截第三方 Cookie 的跨站场景下靠它维持连接，每位访客会自动获得独立身份（可单独禁言），权力等同该群访客。请勿公开粘贴完整代码，怀疑泄露可到后台「群聊嵌入」页吊销；再次生成会换发新令牌，旧代码立即失效。</p>'
                    : '')
                + '<p style="color:var(--ow-text-sub,#999);font-size:12px;margin:0 0 10px">'
                + '图标底色会自动适应你网站的深浅色（也可用 CSS 变量 <code>--owlsgo-launcher-bg</code> 覆盖，设 <code>transparent</code> 即为透明图标）。'
                + '访客点聊天窗顶栏的「登录/注册」会弹出主站小窗完成登录/注册（浏览器拦截第三方 Cookie，嵌入窗口内无法直接登录），'
                + '成功后即用本人身份聊天，会话保持 30 天，可随时登出。</p>'
                + '<div class="ow-form-row" style="gap:8px">'
                + '<button class="ow-btn ow-btn-primary" onclick="OwRoomEmbed.copy()">复制代码</button>'
                + '<button class="ow-btn ow-btn-ghost" onclick="OwChat.closeModal()">关闭</button>'
                + '</div>'
                + '<div id="owEmbedTip" style="margin-top:8px;font-size:12px;color:var(--ow-green,#52c41a)"></div>';
            w.OwChat.openModal(html, 460);
        },

        /** 复制嵌入代码到剪贴板 */
        copy: function () {
            var ta = document.getElementById('owEmbedCode');
            if (!ta) return;
            var tip = document.getElementById('owEmbedTip');
            ta.select();
            try {
                var ok = document.execCommand('copy');
                if (tip) tip.textContent = ok ? '已复制到剪贴板' : '复制失败，请手动选择文本复制';
            } catch (e) {
                if (tip) tip.textContent = '复制失败，请手动选择文本复制';
            }
        }
    };

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }

    function fmtDate(ts) {
        if (!ts) return '—';
        var d = new Date(ts * 1000);
        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    }

    w.OwChat.onRoomEdit(function (ctx) {
        // 仅群主 / 超级管理员可见嵌入入口
        if (!ctx.isOwner && !ctx.isAdmin) return;
        var extras = document.getElementById('owREExtras');
        if (!extras) return;

        var name = '';
        var list = (w.OwChat.cfg && w.OwChat.cfg.rooms) || [];
        for (var i = 0; i < list.length; i++) {
            if (list[i].id === ctx.roomId) { name = list[i].name || ''; break; }
        }

        var row = document.createElement('div');
        row.className = 'ow-panel-entry';
        row.setAttribute('role', 'button');
        row.setAttribute('tabindex', '0');
        row.innerHTML = '<svg class="ow-ico ow-panel-entry-ico" width="16" height="16" viewBox="0 0 24 24" fill="none"'
            + ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            + '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>'
            + '<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>'
            + '</svg><span class="ow-panel-entry-t">嵌入代码</span>'
            + '<span class="ow-panel-entry-arrow">›</span>';
        row.onclick = function () { w.OwRoomEmbed.showSetup(ctx.roomId, name); };
        row.onkeydown = function (e) {
            e = e || w.event;
            if (e.keyCode === 13 || e.keyCode === 32) { e.preventDefault ? e.preventDefault() : (e.returnValue = false); row.onclick(); }
        };
        extras.appendChild(row);
    });

    // ⚠️ 时序修正：核心在 OwChat.init() 内已调用过 renderRoomPanel()，
    // 但本脚本在 init 之后才加载，钩子注册时初始渲染已完成。
    // 这里主动重渲染一次，确保「嵌入代码」入口在首屏即出现（无需用户切换群聊/重开面板）。
    try { w.OwChat.renderRoomPanel(); } catch (e) {}
})();
