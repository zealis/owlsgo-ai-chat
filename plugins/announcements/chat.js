/**
 * 群公告插件 - 前端交互
 * 依赖主程序：OwApi / esc / toast / OwChat.onRoomSwitch / OwChat.onRoomEdit /
 *             OwChat.openModal / OwChat.closeModal / OwApi.secure（敏感操作票据）
 */
(function (w, d) {
    'use strict';

    var current = { roomId: 0, roomName: '', list: [], isAdmin: false, isOwner: false };

    function fmtTime(ts) {
        var dt = new Date(ts * 1000);
        function p(n) { return (n < 10 ? '0' : '') + n; }
        return dt.getFullYear() + '/' + p(dt.getMonth() + 1) + '/' + p(dt.getDate()) + ' ' + p(dt.getHours()) + ':' + p(dt.getMinutes());
    }

    /** 公告条：只显示一条 bar 类型（置顶优先，其次最新），点击进入群公告页面 */
    function renderBar() {
        var main = d.querySelector('.ow-main');
        if (!main) return;
        var bar = d.getElementById('oaBar');
        var top = null;
        for (var i = 0; i < current.list.length; i++) {
            if (current.list[i].type !== 'bar') continue;   // 弹窗通知不占公告条
            if (!top || (current.list[i].pinned === 1 && top.pinned !== 1)) top = current.list[i];
        }
        if (!top) { if (bar) bar.parentNode.removeChild(bar); return; }
        if (!bar) {
            bar = d.createElement('div');
            bar.id = 'oaBar';
            bar.className = 'oa-bar';
            main.insertBefore(bar, d.getElementById('owMessages'));
        }
        bar.innerHTML = '<span class="oa-bar-pin' + (top.pinned === 1 ? ' is-pin' : '') + '">' + (top.pinned === 1 ? '置顶' : '公告') + '</span>'
            + '<span class="oa-bar-text">' + esc(top.content) + '</span>'
            + '<span class="oa-bar-more" title="群公告">›</span>';
        bar.onclick = function () { showPage(); };
    }

    /** 拉取当前群公告并渲染公告条 */
    /* v1.1.11：加世代号。原来切群时公告条**不会立刻撤**，要等新群的接口回来才重画 ——
       中间那一段（本机几十毫秒，走网络的站点几百毫秒到一秒以上）屏幕上挂着的就是
       上一个群的公告，看起来像「公告串到了别的群」。更糟的是两个请求在途时，
       先发的可能后回来，把 A 群的公告直接画在 B 群页面上、还不会自己恢复。
       现在：进 load() 先清空重画（切群瞬间就没有残留），回来时只认最后一次请求。 */
    var loadGen = 0;
    function load() {
        var my = ++loadGen;
        var rid = current.roomId;
        current.list = [];
        renderBar();                        // 同步撤掉上一个群的公告条，不等接口
        if (!rid) return;
        OwApi.post('plugin_announcements_list', { room_id: rid }, function (r) {
            if (my !== loadGen || current.roomId !== rid) return;   // 已切走：迟到响应丢弃
            current.list = r.ok ? (r.data || []) : [];
            renderBar();
            showPopupOnce();
        });
    }

    /** popup 类型：进群时弹窗通知一次（按 公告id 记录已读） */
    function showPopupOnce() {
        for (var i = current.list.length - 1; i >= 0; i--) {
            var a = current.list[i];
            if (a.type !== 'popup') continue;
            var key = 'oa_read_' + current.roomId + '_' + a.id;
            try { if (w.localStorage.getItem(key)) continue; } catch (e) { return; }
            w.localStorage.setItem(key, '1');
            w.OwChat.openModal(
                '<h3>群公告</h3>'
                + '<div class="oa-popup-meta"><b>' + esc(a.nickname) + '</b> ' + fmtTime(a.created_at)
                + (a.pinned === 1 ? ' <span class="oa-pin">置顶</span>' : '') + '</div>'
                + '<div class="oa-popup-body">' + esc(a.content) + '</div>'
                + '<div class="ow-modal-actions"><button class="ow-btn ow-btn-primary" onclick="OwChat.closeModal()">我知道了</button></div>'
            );
            return;
        }
    }

    /** 群公告页面（大弹窗）：群名称 + 全部公告卡片 */
    function showPage() {
        var cards = '', i, a;
        if (!current.list.length) cards = '<div class="oa-empty">本群还没有公告</div>';
        for (i = 0; i < current.list.length; i++) {
            a = current.list[i];
            cards += '<div class="oa-card">'
                + '<div class="oa-card-meta"><b>' + esc(a.nickname) + '</b><span class="oa-card-time">' + fmtTime(a.created_at) + '</span>'
                + (a.pinned === 1 ? '<span class="oa-pin">置顶</span>' : '')
                + (current.isOwner || current.isAdmin ? '<a class="oa-card-del" href="javascript:;" data-id="' + a.id + '">删除</a>' : '')
                + '</div>'
                + '<div class="oa-card-body"><div class="oa-card-text">' + esc(a.content) + '</div>'
                + '<a class="oa-card-toggle" href="javascript:;">展开 ∨</a></div>'
                + '</div>';
        }
        var manage = (current.isOwner || current.isAdmin)
            ? '<button class="ow-btn ow-btn-primary ow-btn-block" id="oaAddBtn">发布公告</button>' : '';
        w.OwChat.openModal(
            '<div class="oa-page"><div class="oa-page-title">' + esc(current.roomName) + '</div>'
            + '<div class="oa-page-sub">群公告</div>'
            + '<div class="oa-list">' + cards + '</div>' + manage + '</div>',
            560
        );
        // 卡片展开 / 收起（内容过长时折叠）
        var cards2 = d.querySelectorAll('#owModal .oa-card');
        for (var j = 0; j < cards2.length; j++) {
            (function (card) {
                var body = card.querySelector('.oa-card-text');
                var tog = card.querySelector('.oa-card-toggle');
                if (!body || !tog) return;
                if (body.scrollHeight <= 96) { tog.style.display = 'none'; return; }
                tog.onclick = function () {
                    var open = body.className.indexOf('open') >= 0;
                    body.className = open ? 'oa-card-text' : 'oa-card-text open';
                    tog.textContent = open ? '展开 ∨' : '收起 ∧';
                };
            })(cards2[j]);
        }
        // 删除（敏感操作，自研确认弹窗）
        // v1.1.2：只传 id —— room_id 由服务端按「该公告自身的归属群」判定并判权限，
        // 前端不再传当前群 id（原先传错导致 SQL 匹配 0 行却仍返回 ok，即「假成功」）。
        var dels = d.querySelectorAll('#owModal .oa-card-del');
        for (var k = 0; k < dels.length; k++) {
            (function (el) {
                el.onclick = function () {
                    w.OwChat.confirm('确定删除该公告？删除后成员端立即不再展示。', function () {
                        OwApi.secure('plugin_announcements_del', { id: el.getAttribute('data-id') }, function (r) {
                            toast(r.msg);
                            if (r.ok) { w.OwChat.closeModal(); load(); }
                        });
                    });
                };
            })(dels[k]);
        }
        // 发布入口
        var addBtn = d.getElementById('oaAddBtn');
        if (addBtn) addBtn.onclick = function () { showPublish(); };
    }

    /** 发布 / 管理弹窗（群主）：内容、类型、置顶 */
    function showPublish() {
        w.OwChat.openModal(
            '<h3>发布群公告</h3>'
            + '<div class="ow-form-item"><label>公告内容</label>'
            + '<textarea class="ow-input" id="oaContent" rows="4" maxlength="600" placeholder="最多 600 字"></textarea></div>'
            + '<div class="ow-form-item"><label>展示类型</label>'
            + '<select class="ow-input" id="oaType"><option value="bar">聊天室上方公告条</option><option value="popup">进群弹窗通知</option></select></div>'
            + '<div class="ow-form-item"><label class="oa-check"><input type="checkbox" id="oaPinned"> 置顶该公告（在公告条与列表优先展示）</label></div>'
            + '<div class="ow-modal-actions">'
            + '<button class="ow-btn ow-btn-ghost" onclick="OwChat.closeModal()">取消</button>'
            + '<button class="ow-btn ow-btn-primary" id="oaPubBtn">发布</button></div>'
        );
        d.getElementById('oaPubBtn').onclick = function () {
            var content = d.getElementById('oaContent').value.replace(/^\s+|\s+$/g, '');
            if (!content) { toast('公告内容不能为空'); return; }
            OwApi.secure('plugin_announcements_add', {
                room_id: current.roomId, content: content,
                type: d.getElementById('oaType').value,
                pinned: d.getElementById('oaPinned').checked ? 1 : 0
            }, function (r) {
                toast(r.msg);
                if (r.ok) { w.OwChat.closeModal(); load(); }
            });
        };
    }

    /**
     * 右侧栏「群聊信息」区里的「群公告」入口行（群主 / 超级管理员可见）。
     *
     * v1.1.10：核心把群资料表单搬回了弹窗，#owREExtras 从「表单里的一行」变成
     * 「入口行容器」。本插件因此改为渲染与「群聊设置」**完全同款**的 .ow-panel-entry
     * （图标 + 文案 + 右箭头，整行可点），位置在「群聊设置」下方、「所有成员」上方。
     * 样式不复制一份：直接用核心的类，两行外观由 CSS 保证一致。
     *
     * ⚠️ 钩子契约：核心保证 #owREExtras 必定存在（渲染入口行后紧接着就是它），
     *    但插件仍须按 ctx.isOwner || ctx.isAdmin 自行决定是否填充 —— 群公告的
     *    发布/删除权限与「群资料可改」是同一口径（群主 + 超管）。
     */
    w.OwChat.onRoomEdit(function (ctx) {
        current.roomId = ctx.roomId;
        current.isAdmin = !!ctx.isAdmin;
        current.isOwner = !!ctx.isOwner;
        var box = d.getElementById('owREExtras');
        if (!box || !(ctx.isOwner || ctx.isAdmin)) return;
        box.innerHTML = '<div class="ow-panel-entry" role="button" tabindex="0" id="oaManageBtn"'
            + ' title="查看与发布本群公告">'
            + '<svg class="ow-ico ow-panel-entry-ico" width="16" height="16" viewBox="0 0 24 24" fill="none"'
            + ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            + '<path d="M3 11v3l4 .5V10.5z"/><path d="M7 10.5L18 5v13l-11-4.5"/><path d="M9 15.5V18a2 2 0 0 0 4 .5"/></svg>'
            + '<span class="ow-panel-entry-t">群公告</span>'
            + '<span class="ow-panel-entry-arrow">›</span></div>';
        // 事件用 JS 绑定而非 inline onclick：闭包直接拿到 ctx，避免把 roomId
        // 拼进 HTML 属性（也省掉一个不存在的 OwChat.oaOpen 全局函数）。
        var btn = d.getElementById('oaManageBtn');
        btn.onclick = function () {
            OwApi.post('plugin_announcements_list', { room_id: ctx.roomId }, function (r) {
                current.list = r.ok ? (r.data || []) : [];
                var rooms = (w.OwChat.cfg.rooms || []);
                for (var i = 0; i < rooms.length; i++) if (rooms[i].id === ctx.roomId) current.roomName = rooms[i].name;
                setTimeout(showPage, 60);
            });
        };
        // 键盘可达：入口行是 div 不是 button，必须自己补 Enter/Space
        btn.onkeydown = function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); btn.onclick(); }
        };
    });

    // 切换群聊：更新上下文并刷新公告条
    w.OwChat.onRoomSwitch(function (ctx) {
        current.roomId = ctx.roomId;
        current.isAdmin = !!ctx.isAdmin;
        current.isOwner = ctx.ownerId === ((w.OwChat.cfg.me || {}).id || 0);
        var rooms = (w.OwChat.cfg.rooms || []);
        for (var i = 0; i < rooms.length; i++) if (rooms[i].id === ctx.roomId) current.roomName = rooms[i].name;
        load();
    });

    // 视图切换（v1.1.0）：公告只对具体群聊成立，room_id=0 的一切视图都不该有公告条 ——
    // 私聊、系统通知流、联系人名单、还没点选会话，四种都是「没有房间」。
    // v1.1.11：原来这里判的是 `!== 'dm'`，等于默认「非私聊即群聊」；核心补上真实视图名后
    // 改成反向判定：只要不是 'room' 就清场，以后核心再加新视图（插件面板之类）也不会漏。
    w.OwChat.onViewChange(function (ctx) {
        if (ctx.view === 'room') return;    // 群聊视图：onRoomSwitch 已经负责刷新
        loadGen++;                          // 作废在途请求：切走之后不许再把公告画回来
        current.roomId = 0;
        current.list = [];
        renderBar();                        // list 为空时 renderBar 会自行移除 #oaBar
    });
})(window, document);
