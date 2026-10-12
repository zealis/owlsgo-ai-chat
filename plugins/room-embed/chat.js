/**
 * 群聊嵌入插件（room-embed）前端脚本 v1.1.1
 *
 * 职责：
 *   1. 嵌入模式（?embed=1）下做 JS 层的 UI 微调（隐藏音效按钮、输入框拖拽手柄）
 *   2. 普通模式下，通过 OwChat.onRoomEdit 在群聊信息面板追加「嵌入代码」入口
 *      —— 仅群主/超级管理员可见，点击弹出 iframe 代码
 *
 * 兼容约束：仅用 var / function，无箭头函数、const、let、fetch。
 *
 * v1.1.0（2026-10-12）：
 *   - 生成的 iframe 代码改响应式：桌面 380×560，窄屏 max-width/max-height 自动收窄，
 *     dvh 渐进增强（老内核忽略第二条声明回退 vh），手机上不再溢出屏幕；
 *   - 幂等标记 __haRoomEmbedLoaded → __owRoomEmbedLoaded（与 Owlsgo 品牌命名对齐）；
 *   - iframe 投影色属「外部站点装饰」（粘贴到没有 --ow-* 变量的第三方页面），就地注释例外。
 * v1.1.1（2026-10-12）：
 *   - 新增跨站嵌入的 Cookie 不到场检测与恢复：浏览器拦下 iframe 的 Set-Cookie 时
 *     （SameSite=Lax 不允许在第三方上下文落地），所有 action 会 403「签名验证失败」。
 *     探测失败后在嵌入页顶部给出「恢复连接」按钮：点击申请 Storage Access
 *     （Safari 弹窗征求同意，授权后重载即恢复）；失败或不支持时给出「在主站打开」入口。
 *   - 生成的 iframe 代码加 allow="storage-access"（Storage Access 权限策略，嵌入页需显式允许）。
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
        embedGuard();   // 跨站嵌入的 Cookie 不到场检测与恢复引导
        return;
    }

    /* ---------- 嵌入模式：跨站 iframe 的 Cookie 不到场检测与恢复 ----------
       根因（2026-10-12 实测）：跨站嵌入时，浏览器会拒绝存储在第三方 iframe 里下发的
       Set-Cookie（本站会话 cookie 是 SameSite=Lax，不允许在第三方上下文落地），
       iframe 内每个请求都是新会话 → 页面里烘的签名 key 永远对不上 → 全站 action 403。
       主程序侧的正解是把 cookie 改成 SameSite=None;Secure（属核心安全设计，插件不动），
       插件能做的只有两件事：
         ① 探测到该场景时给一句人话 + 「恢复连接」按钮 —— 点击申请 Storage Access，
            Safari 会弹窗征求同意，授权后重载，cookie 即可正常落地；
         ② 授权失败/浏览器不支持时，给「在主站打开」的出口，不再无声地 403。
       探测用 OwApi.sign() 现算签名后走裸 XHR（不借用 OwApi.post，避开它的自动刷新逻辑）。 */
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
            x.send('ts=' + s.ts + '&sign=' + s.sign);
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
        /** 生成并展示嵌入代码 */
        showCode: function (roomId, roomName) {
            var base = (w.OwChat.cfg && w.OwChat.cfg.site_url) ? w.OwChat.cfg.site_url : '';
            var src = base + '/?page=chat&room=' + roomId + '&embed=1';
            // ⚠️ 例外（PLUGIN.md「图像像素」类）：本串是粘贴到**外部站点**运行的代码，
            // 外部页面没有 --ow-* 变量体系，投影/圆角按外部站点装饰写死，不随本站主题变化。
            var code = '<iframe src="' + src + '" width="380" height="560" frameborder="0" title="聊天室" '
                + 'allow="storage-access" '
                + 'style="position:fixed;bottom:20px;right:20px;z-index:99999;overflow:hidden;'
                + 'width:380px;max-width:calc(100vw - 24px);'
                + 'height:560px;max-height:calc(100vh - 40px);max-height:calc(100dvh - 40px);'
                + 'border:none;border-radius:10px;box-shadow:0 4px 24px rgba(0,0,0,.18)"></iframe>';

            var html = '<h3>嵌入代码</h3>'
                + '<p style="color:var(--ow-text-sub,#999);font-size:13px;margin:0 0 10px">将以下代码粘贴到网站 HTML 的合适位置，右下角即出现聊天室。</p>'
                + '<div class="ow-form-item">'
                + '<label>群聊：' + escHtml(roomName || ('#' + roomId)) + '</label>'
                + '<textarea id="owEmbedCode" class="ow-input" rows="6" readonly style="width:100%;resize:vertical;font-family:var(--ow-mono,Consolas,monospace);font-size:12px">' + escHtml(code) + '</textarea>'
                + '</div>'
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
        row.onclick = function () { w.OwRoomEmbed.showCode(ctx.roomId, name); };
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
