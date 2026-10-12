/**
 * 群聊嵌入插件（room-embed）前端脚本 v1.2.0
 *
 * 职责：
 *   1. 嵌入模式（?embed=1）：UI 微调（隐藏音效按钮/拖拽手柄）+ 嵌入令牌引导
 *      + Cookie 不到场的「恢复连接」兜底
 *   2. 普通模式：通过 OwChat.onRoomEdit 在群聊信息面板追加「嵌入代码」入口
 *      —— 仅群主/超级管理员可见，点击签发令牌并弹出 iframe 代码
 *
 * 兼容约束：仅用 var / function，无箭头函数、const、let、fetch。
 *
 * v1.1.0（2026-10-12）：iframe 代码响应式（max-width/max-height + dvh 渐进增强）；
 *   幂等标记统一为 __owRoomEmbedLoaded；iframe 投影色属外部站点装饰（就地注释例外）。
 * v1.1.1：跨站嵌入的 Cookie 不到场检测与 Storage Access 恢复引导。
 * v1.2.0（2026-10-12）：嵌入令牌——跨站 iframe 无 cookie 时的完全修复：
 *   - 嵌入码带 embed_token；核心 init 首波请求由 main.php 在 <head> 注入的 XHR 预打包
 *     脚本带令牌（本脚本加载太晚，赶不上首波），本脚本再包一层 OwApi 双保险；
 *   - 令牌不从地址栏抹除（第三方 iframe 的 localStorage 可能被拦，URL 是重载后唯一可靠来源）；
 *   - 「嵌入代码」点击改为先签发令牌（OwApi.secure，敏感操作）再展示代码；
 *   - 探测请求同样带令牌：令牌有效则无提示条；失效/缺失才走 v1.1.1 的恢复引导。
 */
(function () {
    var w = window;
    if (!w.OwChat || !w.OwChat.cfg) return;
    if (w.__owRoomEmbedLoaded) return;
    w.__owRoomEmbedLoaded = true;

    var isEmbed = /[?&]embed=1(&|$)/.test(w.location.search);

    /* ---------- 嵌入模式：隐藏音效按钮、隐藏输入框拖拽手柄 ---------- */
    if (isEmbed) {
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
        embedTokenBoot();   // v1.2.0：令牌引导（跨站无 cookie 时顶替签名门禁）
        embedGuard();       // 兜底：无令牌/令牌失效时的恢复引导
        return;
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
    function embedTokenBoot() {
        var m = /[?&]embed_token=([a-f0-9]{32})/.exec(w.location.search);
        var v = /[?&]embed_visitor=([a-z0-9]{4,16})/.exec(w.location.search);
        if (m) {
            EMBED_TOKEN = m[1];
            try { w.localStorage.setItem(OW_RE_TOK_KEY, EMBED_TOKEN); } catch (e) {}
        } else {
            try { EMBED_TOKEN = w.localStorage.getItem(OW_RE_TOK_KEY) || ''; } catch (e) {}
        }
        EMBED_VISITOR = v ? v[1] : '';
        if (EMBED_TOKEN) wrapOwApiToken(EMBED_TOKEN, EMBED_VISITOR);
    }
    function wrapOwApiToken(tok, vis) {
        function inject(data) {
            data = data || {};
            if (!data.embed_token) data.embed_token = tok;
            if (vis && !data.embed_visitor) data.embed_visitor = vis;
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
        /** 先签发嵌入令牌（敏感操作，一次性票据），成功再展示代码 */
        ensureToken: function (roomId, roomName) {
            OwApi.secure('plugin_room_embed_token', { room_id: roomId }, function (r) {
                if (!r.ok) { toast(r.msg || '签发嵌入令牌失败'); return; }
                w.OwRoomEmbed.showCode(roomId, roomName, r.token, r.expires_at);
            });
        },

        /** 生成并展示嵌入代码 */
        showCode: function (roomId, roomName, token, expiresAt) {
            var base = (w.OwChat.cfg && w.OwChat.cfg.site_url) ? w.OwChat.cfg.site_url : '';
            var src = base + '/?page=chat&room=' + roomId + '&embed=1'
                + (token ? '&embed_token=' + token + '&embed_visitor=' : '');
            // ⚠️ 例外（PLUGIN.md「图像像素」类）：本串是粘贴到**外部站点**运行的代码，
            // 外部页面没有 --ow-* 变量体系，投影/圆角按外部站点装饰写死，不随本站主题变化。
            // 访客标识在**父页面**（第一方）localStorage 铸造后随 iframe URL 带上——
            // 第三方 iframe 自己的存储可能被浏览器拦掉，父页面不会；每个访客因此有稳定身份。
            var style = 'position:fixed;bottom:20px;right:20px;z-index:99999;overflow:hidden;'
                + 'width:380px;max-width:calc(100vw - 24px);'
                + 'height:560px;max-height:calc(100vh - 40px);max-height:calc(100dvh - 40px);'
                + 'border:none;border-radius:10px;box-shadow:0 4px 24px rgba(0,0,0,.18)';
            var code = '<div id="owlsgo-chat"></div>\n'
                + '<script>\n'
                + '(function(){var k="owlsgo_embed_visitor",v="";\n'
                + 'try{v=window.localStorage.getItem(k)||"";}catch(e){}\n'
                + 'if(!/^[a-z0-9]{4,16}$/.test(v)){v=Math.random().toString(16).slice(2,10);'
                + 'try{window.localStorage.setItem(k,v);}catch(e){}}\n'
                + 'var f=document.createElement("iframe");\n'
                + 'f.src="' + src + '"+v;\n'
                + 'f.width="380";f.height="560";f.frameBorder="0";f.title="聊天室";f.allow="storage-access";\n'
                + 'f.style.cssText="' + style + '";\n'
                + 'document.getElementById("owlsgo-chat").appendChild(f);})();\n'
                + '<\/script>';

            var html = '<h3>嵌入代码</h3>'
                + '<p style="color:var(--ow-text-sub,#999);font-size:13px;margin:0 0 10px">将以下代码粘贴到网站 HTML 的合适位置，右下角即出现聊天室。</p>'
                + '<div class="ow-form-item">'
                + '<label>群聊：' + escHtml(roomName || ('#' + roomId)) + '</label>'
                + '<textarea id="owEmbedCode" class="ow-input" rows="8" readonly style="width:100%;resize:vertical;font-family:var(--ow-mono,Consolas,monospace);font-size:12px">' + escHtml(code) + '</textarea>'
                + '</div>'
                + (token
                    ? '<p style="color:var(--ow-text-sub,#999);font-size:12px;margin:0 0 10px">代码内含嵌入令牌（有效期至 '
                      + fmtDate(expiresAt) + '）：浏览器拦截第三方 Cookie 的跨站场景下靠它维持连接，每位访客会自动获得独立身份（可单独禁言），权力等同该群访客。请勿公开粘贴完整代码，怀疑泄露可到后台「群聊嵌入」页吊销；再次点击「嵌入代码」会换发新令牌，旧代码立即失效。</p>'
                    : '')
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
        row.onclick = function () { w.OwRoomEmbed.ensureToken(ctx.roomId, name); };
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
