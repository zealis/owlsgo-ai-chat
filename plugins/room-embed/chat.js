/**
 * 群聊嵌入插件（room-embed）前端脚本 v2.3.0
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
 * v2.2.0（2026-10-16）：
 *   - 右下角按钮支持多套样式：参数面板新增「按钮样式」下拉（默认取后台设置），
 *     选中即用真按钮 1:1 预览（CSS 来自服务端下发的 window.OW_RE_LAUNCHER，
 *     与嵌入代码同一份来源）；生成的代码按所选样式拼装，不再写死手机图标那一款。
 *   - autoTheme 多返回一组「界面底色」（minimal 款的白/深底描边按钮要用）。
 * v2.3.0（2026-10-16）：
 *   - 群聊信息面板新增「嵌入令牌」入口（与「嵌入代码」同权限）：列本群令牌
 *     （有效 1 个 + 历史，最多 50 条）、单个/批量/全部吊销。样式在真打开弹窗时
 *     注入一次（injectTokenCss），不占聊天页体积；确认走 OwChat 自研确认框。
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
        /** 第一步：参数面板（域名白名单 + 启动方式 + 按钮样式） */
        showSetup: function (roomId, roomName) {
            w.OwChat._owEmbedRoomName = roomName;
            var L = launcherData();
            var opts = '';
            var cur = '';
            if (L) {
                cur = L.cfg.style || 'classic';
                for (var i = 0; i < L.order.length; i++) {
                    var id = L.order[i];
                    opts += '<option value="' + id + '"' + (id === cur ? ' selected' : '') + '>'
                        + escHtml(L.presets[id].name) + '</option>';
                }
            }
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
                + (opts
                    ? '<div class="ow-form-item"><label>按钮样式</label>'
                      + '<select class="ow-input" id="owEmbedStyle" onchange="OwRoomEmbed.preview()">' + opts + '</select>'
                      + '<div id="owEmbedPv" class="ow-embed-pv" style="height:96px;'
                      + 'display:-webkit-flex;display:flex;-webkit-align-items:center;align-items:center;'
                      + '-webkit-justify-content:center;justify-content:center;background:var(--ow-bg-sub);'
                      + 'border:1px solid var(--ow-border);border-radius:8px;margin-top:8px;'
                      + 'overflow:hidden;position:relative">'
                      + '<button type="button" id="owEmbedPvBtn" class="owlsgo-launcher ow-lc-' + cur + '"'
                      + ' title="在线聊天" aria-label="在线聊天">' + (L.presets[cur] ? L.presets[cur].html : '') + '</button>'
                      + '</div>'
                      + '<p style="font-size:12px;color:var(--ow-text-sub);margin-top:6px">上方是真按钮的 1:1 预览（颜色与尺寸跟随后台设置）。'
                      + '主色、尺寸在后台「群聊嵌入 → 浮动按钮样式」统一调整；样式改动只对之后生成的代码生效。</p></div>'
                    : '')
                + '<div class="ow-form-row" style="gap:8px">'
                + '<button class="ow-btn ow-btn-primary" onclick="OwRoomEmbed.generate(' + roomId + ')">生成代码</button>'
                + '<button class="ow-btn ow-btn-ghost" onclick="OwChat.closeModal()">取消</button>'
                + '</div>';
            w.OwChat.openModal(html, 460);
            w.OwRoomEmbed.preview();
        },

        /** 预览：只把「当前这一款」的 CSS 注进弹窗作用域，切样式就换一份，不刷页面 */
        preview: function () {
            var L = launcherData();
            var sel = document.getElementById('owEmbedStyle');
            var btn = document.getElementById('owEmbedPvBtn');
            if (!L || !sel || !btn) return;
            var id = sel.value;
            if (!L.presets[id]) id = L.cfg.style;
            btn.className = L.presets[id].cls;
            btn.innerHTML = L.presets[id].html;
            // 通用盒 + 本款样式 + 预览中和规则（真按钮是右下角 fixed 浮层，摆进弹窗要复位）
            var css = L.base + L.presets[id].css
                + '#owEmbedPv .owlsgo-launcher{position:relative!important;left:auto!important;top:auto!important;'
                + 'right:auto!important;bottom:auto!important;cursor:default!important}';
            var el = document.getElementById('owEmbedPvCss');
            if (!el) {
                el = document.createElement('style');
                el.id = 'owEmbedPvCss';
                document.head.appendChild(el);
            }
            el.textContent = css;
        },

        /** 第二步：签发令牌（敏感操作，一次性票据）并出代码 */
        generate: function (roomId) {
            var dEl = document.getElementById('owEmbedDomains');
            var mEl = document.getElementById('owEmbedMode');
            var sEl = document.getElementById('owEmbedStyle');
            var domains = dEl ? dEl.value : '';
            var mode = mEl ? mEl.value : 'launcher';
            var style = sEl ? sEl.value : '';
            OwApi.secure('plugin_room_embed_token', { room_id: roomId, domains: domains }, function (r) {
                if (!r.ok) { toast(r.msg || '签发嵌入令牌失败'); return; }
                w.OwRoomEmbed.showCode(roomId, w.OwChat._owEmbedRoomName || '', r.token, r.expires_at, mode, style);
            });
        },

        /** 生成并展示嵌入代码（v1.3.0：默认浮动手机按钮启动器；v2.2.0：按钮样式由服务端下发的 CSS 拼装） */
        showCode: function (roomId, roomName, token, expiresAt, mode, style) {
            var base = (w.OwChat.cfg && w.OwChat.cfg.site_url) ? w.OwChat.cfg.site_url : '';
            if (!base) base = w.location.origin;   // 未配「固定网站地址」时退回当前源
            var direct = mode === 'direct';
            var src = base + '/?page=chat&room=' + roomId + '&embed=1'
                + (token ? '&embed_token=' + token + '&embed_visitor=' : '');
            // ⚠️ 例外（PLUGIN.md「图像像素」类）：本串是粘贴到**外部站点**运行的代码，
            // 外部页面没有 --ow-* 变量体系，按钮底色/投影按外部站点装饰处理。
            // v2.2.0：按钮的整段 CSS 由服务端生成（main.php 的 owReLauncher* 系列）后在此拼装，
            // 与后台预览共用同一份来源，不在这里另写一套样式。
            // 站点主人仍可用 --owlsgo-launcher-bg / -fg / -accent / -size 覆盖。
            // 访客标识在**父页面**（第一方）localStorage 铸造后随 iframe URL 带上——
            // 第三方 iframe 自己的存储可能被浏览器拦掉，父页面不会；每个访客因此有稳定身份。
            var L = launcherData();
            var st = null;
            if (L) st = L.presets[style] || L.presets[L.cfg.style] || null;
            if (!st) {   // 兜底：页面没注入数据（老浏览器缓存等）时退回经典圆钮的等价样式
                st = { cls: 'owlsgo-launcher ow-lc-classic', html: '', css: '' };
            }
            var launcherCss = direct ? '' : (L ? (L.base + st.css) : '');
            var frameStyle = 'position:fixed;bottom:84px;right:20px;z-index:99998;overflow:hidden;'
                + 'width:380px;max-width:calc(100vw - 24px);'
                + 'height:560px;max-height:calc(100vh - 128px);max-height:calc(100dvh - 128px);'
                + 'border:none;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.18)';
            var code = (launcherCss ? '<style>\n' + launcherCss + '\n</style>\n' : '')
                + '<div id="owlsgo-chat"></div>\n'
                + '<script>\n'
                + '(function(){var k="owlsgo_embed_visitor",v="";\n'
                + 'try{v=window.localStorage.getItem(k)||"";}catch(e){}\n'
                + 'if(!/^[a-z0-9]{4,16}$/.test(v)){v=Math.random().toString(16).slice(2,10);'
                + 'try{window.localStorage.setItem(k,v);}catch(e){}}\n'
                // 自动配色：沿祖先链找第一个不透明背景色算亮度——浅色页面出深底白图形，
                // 深色页面出白底深图形；取不到按浅色处理（最常见），并参考 prefers-color-scheme。
                // v2.2.0：多返回一组「界面底色」（minimal 款的白/深底描边按钮要用）。
                + 'function autoTheme(){\n'
                + 'var el=document.body,bg="",n=0,c;\n'
                + 'while(el&&n<8){c=getComputedStyle(el).backgroundColor;\n'
                + 'if(c&&c!=="transparent"&&c!=="rgba(0, 0, 0, 0)"){bg=c;break;}\n'
                + 'el=el.parentElement;n++;}\n'
                + 'var light=true,m=/rgba?\\((\\d+)\\s*,\\s*(\\d+)\\s*,\\s*(\\d+)/.exec(bg||"");\n'
                + 'if(m){var L=(0.299*(+m[1])+0.587*(+m[2])+0.114*(+m[3]))/255;light=L>=0.55;}\n'
                + 'else if(window.matchMedia&&matchMedia("(prefers-color-scheme: dark)").matches)light=false;\n'
                + 'return light?["#2b2f36","#ffffff","#ffffff"]:["#ffffff","#2b2f36","#2b2f36"];}\n'
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
                + 'launcher.id="owlsgo-launcher";launcher.className=' + JSON.stringify(st.cls) + ';\n'
                + 'launcher.type="button";launcher.title="在线聊天";'
                + 'launcher.setAttribute("aria-label","在线聊天");\n'
                + 'launcher.innerHTML=' + JSON.stringify(st.html) + ';\n'
                + 'var _t=autoTheme();\n'
                + 'launcher.style.setProperty("--owlsgo-launcher-bg-auto",_t[0]);\n'
                + 'launcher.style.setProperty("--owlsgo-launcher-fg-auto",_t[1]);\n'
                + 'launcher.style.setProperty("--owlsgo-launcher-surface-auto",_t[2]);\n'
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
                    : '将以下代码粘贴到网站 HTML 的合适位置，右下角即出现'
                      + (st && st.name ? '「' + escHtml(st.name) + '」' : '') + '按钮，'
                      + '访客点击展开聊天、点外部自动收回。')
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
                + '按钮底色会自动适应你网站的深浅色（也可用 CSS 变量 <code>--owlsgo-launcher-bg</code>、<code>--owlsgo-launcher-accent</code> 覆盖）。'
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
        },

        /* ===================== v2.3.0 前台令牌管理 =====================
           嵌入代码是群主自己在群聊信息面板里生成的，令牌却原先只能在后台吊销 ——
           群主想撤掉自己发出去的代码得去找管理员。这里把「本群」这一档放回前台，
           权限口径与签发一致（服务端同一个守卫：超管 或 该群群主，见 main.php）。 */

        /** 打开令牌管理弹窗（roomId/roomName 由群聊信息面板传入） */
        tokens: function (roomId, roomName) {
            w.OwChat._owEmbedRoomName = roomName;
            injectTokenCss();
            var html = '<h3>嵌入令牌</h3>'
                + '<p style="color:var(--ow-text-sub,#999);font-size:13px;margin:0 0 10px">'
                + escHtml(roomName || ('#' + roomId)) + ' 的嵌入代码令牌。浏览器拦截第三方 Cookie 的跨站场景下，'
                + '访客靠它维持连接并获得独立身份（可单独禁言）。</p>'
                + '<div id="owRTokenBox"><p style="color:var(--ow-text-sub,#999);font-size:13px;margin:0">正在读取…</p></div>'
                + '<p style="color:var(--ow-text-sub,#999);font-size:12.5px;margin:0 0 12px">'
                + '同一时刻最多只有 1 个有效令牌——重新生成嵌入代码会立刻作废旧令牌。'
                + '怀疑代码泄露时在这里吊销，对应网站上的聊天窗立即无法连接。</p>'
                + '<div class="ow-form-row" style="gap:8px">'
                + '<button class="ow-btn ow-btn-danger" id="owRTokenBatch" disabled'
                + ' onclick="OwRoomEmbed.tokenRevoke(' + roomId + ')">吊销选中的令牌</button>'
                + '<button class="ow-btn ow-btn-ghost" onclick="OwChat.closeModal()">关闭</button>'
                + '</div>';
            w.OwChat.openModal(html, 520);
            w.OwRoomEmbed.tokensLoad(roomId);
        },

        /** 拉本群令牌（只读路由，不敏感） */
        tokensLoad: function (roomId) {
            OwApi.post('plugin_room_embed_token_mine', { room_id: roomId }, function (r) {
                var box = document.getElementById('owRTokenBox');
                if (!box) return;
                if (!r || !r.ok) {
                    box.innerHTML = '<p style="color:var(--ow-red,#C41D1F);font-size:13px;margin:0">'
                        + escHtml((r && r.msg) || '读取失败') + '</p>';
                    return;
                }
                w.OwRoomEmbed.tokensRender(r.data || []);
            });
        },

        /** 渲染列表：有效令牌置顶且默认勾选，历史只读（已吊销/已过期，吊销它们没有意义） */
        tokensRender: function (rows) {
            var box = document.getElementById('owRTokenBox');
            if (!box) return;
            if (!rows.length) {
                box.innerHTML = '<p style="color:var(--ow-text-sub,#999);font-size:13px;margin:0">'
                    + '这个群还没有生成过嵌入代码。</p>';
                w.OwRoomEmbed.tokenBatch();
                return;
            }
            var h = '';
            for (var i = 0; i < rows.length; i++) {
                var t = rows[i];
                var live = !t.revoked && !t.expired;
                var st = t.revoked ? '已吊销' : (t.expired ? '已过期' : '有效');
                var dom = t.domains && t.domains.length
                    ? (t.domains[0] === '*' ? '任意域名' : t.domains.join('、'))
                    : '不限制域名';
                var inner = (live
                        ? '<input type="checkbox" class="owRTokenChk" value="' + t.id + '" checked'
                          + ' onchange="OwRoomEmbed.tokenBatch()">'
                        : '')
                    + '<span class="ow-rt-main">'
                    + '<span class="ow-rt-code">' + escHtml(t.token_prefix) + '…</span>'
                    + '<span class="ow-rt-meta">' + escHtml(dom)
                    + ' · 签发 ' + fmtTs(t.created_at) + ' · 最近使用 ' + fmtTs(t.last_used_at)
                    + ' · 由 ' + escHtml(t.created_by_name) + ' 生成</span>'
                    + '</span>'
                    + '<span class="ow-rt-st ' + (live ? 'on' : 'offp') + '">' + st + '</span>';
                // 可勾选的用 label（点整行即勾选），只读的用 div（label 里没控件是语义错误）
                h += live
                    ? '<label class="ow-rt-item">' + inner + '</label>'
                    : '<div class="ow-rt-item off">' + inner + '</div>';
            }
            box.innerHTML = h;
            w.OwRoomEmbed.tokenBatch();
        },

        /** 勾选数同步按钮文案与可用态 */
        tokenBatch: function () {
            var boxes = document.getElementsByClassName('owRTokenChk');
            var n = 0;
            for (var i = 0; i < boxes.length; i++) if (boxes[i].checked) n++;
            var btn = document.getElementById('owRTokenBatch');
            if (!btn) return;
            btn.disabled = n === 0;
            btn.innerHTML = n > 0 ? '吊销选中的令牌（' + n + '）' : '吊销选中的令牌';
        },

        /** 吊销选中令牌：走 OwChat.confirm 自研确认框（全站禁浏览器原生弹窗） */
        tokenRevoke: function (roomId) {
            var boxes = document.getElementsByClassName('owRTokenChk');
            var ids = [];
            for (var i = 0; i < boxes.length; i++) if (boxes[i].checked) ids.push(boxes[i].value);
            if (!ids.length) { toast('请先选择要吊销的令牌'); return; }
            var go = function () {
                // 敏感操作：OwApi.secure 自动先取一次性票据
                OwApi.secure('plugin_room_embed_token_revoke_mine', {
                    room_id: roomId, ids: ids.join(',')
                }, function (r) {
                    toast((r && r.msg) || ((r && r.ok) ? '已吊销' : '吊销失败'));
                    if (r && r.ok) w.OwRoomEmbed.tokensLoad(roomId);
                });
            };
            var text = '确定吊销选中的 ' + ids.length + ' 个令牌？\n'
                + '使用它们的嵌入代码会立即失效，对应网站上的聊天窗将无法连接。';
            // OwChat.confirm 缺失时直接执行——绝不落到浏览器原生 confirm
            if (typeof w.OwChat.confirm === 'function') w.OwChat.confirm(text, go);
            else go();
        }
    };

    /** 令牌列表的样式：只在真打开弹窗时注入一次（平时不占聊天页体积），
     *  颜色一律走 --ow-* 令牌，尺寸随容器自适应，窄屏换行不横向溢出。 */
    function injectTokenCss() {
        if (document.getElementById('owReTokenCss')) return;
        var css = '#owRTokenBox{max-height:340px;overflow-y:auto;-webkit-overflow-scrolling:touch}'
            + '.ow-rt-item{display:-webkit-flex;display:flex;align-items:flex-start;'
            + '-webkit-align-items:flex-start;gap:9px;padding:9px 10px;margin:0 0 8px;'
            + 'border:1px solid var(--ow-border,#eee);border-radius:8px;background:var(--ow-bg-sub,#f7f7f7);'
            + 'cursor:pointer}'
            + '.ow-rt-item.off{opacity:.62}'
            + '.ow-rt-item input{margin:3px 0 0;flex:none;-webkit-flex:none}'
            + '.ow-rt-main{-webkit-flex:1;flex:1;min-width:0}'
            + '.ow-rt-code{display:block;font-family:var(--ow-mono,Consolas,monospace);font-size:12.5px;'
            + 'color:var(--ow-text,#333);word-break:break-all}'
            + '.ow-rt-meta{display:block;font-size:12px;line-height:1.6;color:var(--ow-text-sub,#999);'
            + 'margin-top:3px;word-break:break-word}'
            + '.ow-rt-st{-webkit-flex:none;flex:none;font-size:12px;padding:2px 7px;border-radius:4px;'
            + 'white-space:nowrap}'
            + '.ow-rt-st.on{color:var(--ow-green,#237804);background:var(--ow-bg,#fff);'
            + 'border:1px solid var(--ow-border,#eee)}'
            + '.ow-rt-st.offp{color:var(--ow-text-sub,#999);background:var(--ow-bg,#fff);'
            + 'border:1px solid var(--ow-border,#eee)}'
            + '@media (max-width:520px){.ow-rt-item{-webkit-flex-wrap:wrap;flex-wrap:wrap}'
            + '.ow-rt-meta{font-size:11.5px}}';
        var el = document.createElement('style');
        el.id = 'owReTokenCss';
        el.textContent = css;
        document.head.appendChild(el);
    }

    /** 时间戳 → YYYY-MM-DD HH:MM（0 显示「—」） */
    function fmtTs(ts) {
        if (!ts) return '—';
        var d = new Date(ts * 1000);
        function p(n) { return (n < 10 ? '0' : '') + n; }
        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate())
            + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }

    /** 服务端下发的浮动按钮样式数据（main.php 的 page.head 注入）。
     *  结构：{ base:通用盒 CSS, presets:{id:{name,desc,css,html,cls}}, order:[id…],
     *          palette:[…], sizes:[…], cfg:{style,color,size} }。
     *  缺失（脚本被缓存没刷新、或停在旧版本）时返回 null，调用方各自退回经典圆钮。 */
    function launcherData() {
        var L = w.OW_RE_LAUNCHER;
        if (!L || !L.presets || !L.cfg || !L.order || !L.base) return null;
        return L;
    }

    function fmtDate(ts) {
        if (!ts) return '—';
        var d = new Date(ts * 1000);
        return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2);
    }

    /** 往群聊信息面板追加一行入口（图标 + 文案 + 右箭头，核心 .ow-panel-entry 风格）。
     *  回车/空格同样可触发——与核心面板里其它可点行保持一致。 */
    function addPanelEntry(extras, iconPath, text, onclick) {
        var row = document.createElement('div');
        row.className = 'ow-panel-entry';
        row.setAttribute('role', 'button');
        row.setAttribute('tabindex', '0');
        row.innerHTML = '<svg class="ow-ico ow-panel-entry-ico" width="16" height="16" viewBox="0 0 24 24" fill="none"'
            + ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            + iconPath + '</svg><span class="ow-panel-entry-t">' + escHtml(text) + '</span>'
            + '<span class="ow-panel-entry-arrow">›</span>';
        row.onclick = onclick;
        row.onkeydown = function (e) {
            e = e || w.event;
            if (e.keyCode === 13 || e.keyCode === 32) { e.preventDefault ? e.preventDefault() : (e.returnValue = false); row.onclick(); }
        };
        extras.appendChild(row);
    }

    w.OwChat.onRoomEdit(function (ctx) {
        // 仅群主 / 超级管理员可见：签发与管理嵌入令牌的口径一致（服务端也是同一个守卫）
        if (!ctx.isOwner && !ctx.isAdmin) return;
        var extras = document.getElementById('owREExtras');
        if (!extras) return;

        var name = '';
        var list = (w.OwChat.cfg && w.OwChat.cfg.rooms) || [];
        for (var i = 0; i < list.length; i++) {
            if (list[i].id === ctx.roomId) { name = list[i].name || ''; break; }
        }

        // 签发（生成代码）
        addPanelEntry(extras,
            '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>'
            + '<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
            '嵌入代码',
            function () { w.OwRoomEmbed.showSetup(ctx.roomId, name); });

        // v2.3.0 令牌管理：看本群令牌、单个/批量/全部吊销（原先只在后台，群主撤不掉自己发的代码）
        addPanelEntry(extras,
            '<circle cx="8" cy="12" r="3.2"/><path d="M11.2 12H20"/><path d="M17.4 12v3.2"/>'
            + '<path d="M14.6 12v2.2"/>',
            '嵌入令牌',
            function () { w.OwRoomEmbed.tokens(ctx.roomId, name); });
    });

    // ⚠️ 时序修正：核心在 OwChat.init() 内已调用过 renderRoomPanel()，
    // 但本脚本在 init 之后才加载，钩子注册时初始渲染已完成。
    // 这里主动重渲染一次，确保「嵌入代码」入口在首屏即出现（无需用户切换群聊/重开面板）。
    try { w.OwChat.renderRoomPanel(); } catch (e) {}
})();
