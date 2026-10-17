/**
 * 群公告插件 - 前端交互
 * 依赖主程序：OwApi / esc / toast / OwChat.onRoomSwitch / OwChat.onRoomEdit /
 *             OwChat.onViewChange / OwChat.openModal / OwChat.closeModal /
 *             OwApi.secure（敏感操作票据）
 *
 * v1.3.0：聊天室上方公告条重设计（模仿主流现代聊天室的主色实底通栏）：
 *   - 形态：喇叭图标 + [置顶徽章] + 公告文字 + 右侧圆形关闭按钮；实底白字通栏。
 *   - 多条公告 5 秒轮播、0.3s 淡入淡出；单条静态显示。
 *   - 桌面端文字单行溢出省略；移动端（≤960px）仅在实际溢出时按距离折算速度匀速横滚。
 *   - 关闭按钮只在**本次页面会话**内隐藏（不写 localStorage：新公告应能再次提醒）。
 *   - 点击条体 / 键盘回车仍进入群公告页面（Owlsgo 增强）。
 */
(function (w, d) {
    'use strict';

    var current = { roomId: 0, roomName: '', list: [], isAdmin: false, isOwner: false };

    /* 公告条底色（四档预设，空串 = 跟随主题的默认主色）。
       ⚠️ 前端也过一道白名单：这个值是从库里读出来的，会被拼进 class 名。
          服务端存的时候已经筛过一次，这里再筛是因为万一库里有历史脏数据
          （手工改库、或将来某次迁移写坏），也不能让它变成一个任意 class。 */
    var BAR_COLORS = { blue: 1, green: 1, orange: 1, red: 1 };
    function colorCls(c) { return BAR_COLORS[c] ? ' oa-bar-' + c : ''; }

    /* 条内两枚图标（currentColor 描边，随条的配色走）。
       形状与核心 ow_icon() 注册表同源：mega = 喇叭（「群聊信息」入口行同一形状）、
       close = 叉。公告条由前端动态创建，拿不到 PHP 端的 ow_icon()，故内联同一份 path。 */
    var ICON_MEGA = '<svg class="ow-ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 11v3l4 .5V10.5z"/><path d="M7 10.5L18 5v13l-11-4.5"/><path d="M9 15.5V18a2 2 0 0 0 4 .5"/></svg>';
    var ICON_CLOSE = '<svg class="ow-ico" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>';

    /* rAF 兜底：极老的浏览器没有它时退化成 16ms 定时器（测量只差一帧，无功能影响） */
    var raf = w.requestAnimationFrame || function (f) { return setTimeout(f, 16); };
    var caf = w.cancelAnimationFrame || function (h) { clearTimeout(h); };

    /* 公告条骨架引用与轮播状态（v1.3.0：条体只建一次，之后仅更新内容与配色类） */
    var bar = null, barPin = null, barText = null, barTc = null;
    var bars = [];               /* 当前群的 bar 类型公告（轮播队列，已按置顶优先排序） */
    var barClosed = false;       /* 本次页面会话内用户点了关闭 */
    var carTimer = null, fadeTimer = null, measureRaf = 0;

    function fmtTime(ts) {
        var dt = new Date(ts * 1000);
        function p(n) { return (n < 10 ? '0' : '') + n; }
        return dt.getFullYear() + '/' + p(dt.getMonth() + 1) + '/' + p(dt.getDate()) + ' ' + p(dt.getHours()) + ':' + p(dt.getMinutes());
    }

    function stopCarousel() {
        if (carTimer) { clearTimeout(carTimer); carTimer = null; }
        if (fadeTimer) { clearTimeout(fadeTimer); fadeTimer = null; }
        if (measureRaf) { caf(measureRaf); measureRaf = 0; }
    }

    /** 撤掉公告条（切群 / 切视图 / 无公告 / 用户关闭） */
    function hideBar() {
        stopCarousel();
        bars = [];
        if (bar && bar.parentNode) bar.parentNode.removeChild(bar);
        bar = barPin = barText = barTc = null;
    }

    /** 建公告条骨架（只建一次；事件也只在此时绑一次，避免轮播重画时重复绑定） */
    function ensureBar() {
        var main = d.querySelector('.ow-main');
        if (!main || !d.getElementById('owMessages')) return false;
        bar = d.createElement('div');
        bar.id = 'oaBar';
        bar.setAttribute('role', 'button');
        bar.setAttribute('tabindex', '0');
        bar.title = '查看群公告';
        bar.innerHTML = '<span class="oa-bar-ico">' + ICON_MEGA + '</span>'
            + '<span class="oa-bar-pin" id="oaBarPin" style="display:none">置顶</span>'
            + '<div class="oa-bar-text" id="oaBarText"><span class="oa-bar-tc" id="oaBarTc"></span></div>'
            + '<button class="oa-bar-close" id="oaBarClose" type="button" title="关闭公告" aria-label="关闭公告">' + ICON_CLOSE + '</button>';
        // ⚠️ 用 querySelector 而不是 getElementById：bar 此刻还没 insertBefore 进文档，
        //    getElementById 拿不到（返回 null，设置 onclick 直接抛异常、整条公告都建不出来）。
        barPin = bar.querySelector('.oa-bar-pin');
        barText = bar.querySelector('.oa-bar-text');
        barTc = bar.querySelector('.oa-bar-tc');
        // 关闭：仅本次会话隐藏，不写 localStorage —— 群里发新公告时应能再次提醒
        bar.querySelector('.oa-bar-close').onclick = function (e) {
            e.stopPropagation();
            barClosed = true;
            hideBar();
        };
        // 条体点击 / 键盘（Enter、Space）→ 群公告页面
        bar.onclick = function () { showPage(); };
        bar.onkeydown = function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); showPage(); }
        };
        main.insertBefore(bar, d.getElementById('owMessages'));
        return true;
    }

    /**
     * 溢出测量（v1.3.0）：文字实际宽度超出容器时才挂横滚动画。
     * 速度按溢出距离折算（约 50px/秒，夹在 4~14 秒）—— 长公告滚得慢、
     * 短公告滚得快，不会一律固定时长晕车；CSS 两端各留 8% 停顿。
     * ⚠️ 必须用 scrollWidth 而不是 offsetWidth：.oa-bar-tc 是 display:block，
     *    offsetWidth 恒等于容器宽（block 占满一行），永远测不出溢出；
     *    scrollWidth 才是「nowrap 内容的实际宽」，超出部分也在里面。
     */
    function measureScroll() {
        if (measureRaf) { caf(measureRaf); measureRaf = 0; }
        if (!barText || !barTc) return;
        measureRaf = raf(function () {
            measureRaf = 0;
            if (!barText || !barTc) return;
            var overflow = barTc.scrollWidth - barText.clientWidth;
            if (overflow > 4) {
                var dur = Math.max(4, Math.min(14, overflow / 50));
                barText.style.setProperty('--oa-scroll-distance', '-' + overflow + 'px');
                barText.style.setProperty('--oa-scroll-duration', dur + 's');
                barText.classList.add('is-overflow');
            } else {
                barText.classList.remove('is-overflow');
                barText.style.removeProperty('--oa-scroll-distance');
                barText.style.removeProperty('--oa-scroll-duration');
            }
        });
    }

    /** 轮播第 i 条：0.3s 淡出 → 换字（含配色类）→ 淡入 →（多条时）5s 后下一条 */
    function showMessage(i) {
        if (barClosed || !barText || i >= bars.length) return;
        var a = bars[i];
        barText.className = 'oa-bar-text is-out';
        fadeTimer = setTimeout(function () {
            fadeTimer = null;
            if (barClosed || !bar || i >= bars.length) return;
            // 配色跟着当前这条走：不同公告的 bar_color 可以不同，
            // 只换文字会把上一条的底色留在条上（v1.3.0 修）。
            bar.className = 'oa-bar' + colorCls(a.bar_color);
            barPin.style.display = (a.pinned === 1 || a.pinned === '1') ? '' : 'none';
            barTc.textContent = a.content;   // textContent：内容已过服务端敏感词过滤
            barText.className = 'oa-bar-text';
            measureScroll();
            if (bars.length > 1) carTimer = setTimeout(function () { showMessage(i + 1); }, 5000);
        }, 300);
    }

    /** 渲染公告条：取置顶优先的最新 bar 公告；多条进轮播，单条静态 */
    function renderBar() {
        bars = [];
        for (var i = 0; i < current.list.length; i++) {
            if (current.list[i].type !== 'bar') continue;   // 弹窗通知不占公告条
            bars.push(current.list[i]);
        }
        if (!bars.length || barClosed) { hideBar(); return; }
        // 接口按 pinned DESC, id DESC 排序：bars[0] 即「置顶优先、其次最新」
        if (!bar && !ensureBar()) return;
        // 每次重画都重写 className：条体元素复用，切到另一条公告（底色不同）时只改
        // innerHTML 会把上一条的配色类留在条上。
        bar.className = 'oa-bar' + colorCls(bars[0].bar_color);
        stopCarousel();
        if (bars.length === 1) {
            barPin.style.display = (bars[0].pinned === 1 || bars[0].pinned === '1') ? '' : 'none';
            barTc.textContent = bars[0].content;
            barText.className = 'oa-bar-text';
            measureScroll();
        } else {
            showMessage(0);
        }
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
        barClosed = false;      // 换群重置「已关闭」：新群的公告要能提醒（参考站同款语义）
        renderBar();            // 同步撤掉上一个群的公告条，不等接口
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

    /** 发布 / 管理弹窗（群主）：内容、类型、底色、置顶 */
    function showPublish() {
        w.OwChat.openModal(
            '<h3>发布群公告</h3>'
            + '<div class="ow-form-item"><label>公告内容</label>'
            + '<textarea class="ow-input" id="oaContent" rows="4" maxlength="600" placeholder="最多 600 字"></textarea></div>'
            + '<div class="ow-form-item"><label>展示类型</label>'
            + '<select class="ow-input" id="oaType"><option value="bar">聊天室上方公告条</option><option value="popup">进群弹窗通知</option></select></div>'
            + '<div class="ow-form-item" id="oaColorRow"><label>公告条底色</label>'
            + '<div class="oa-picks" id="oaPicks">'
            +   '<button type="button" class="oa-pick oa-pick-blue is-on" data-c="blue">蓝</button>'
            +   '<button type="button" class="oa-pick oa-pick-green" data-c="green">绿</button>'
            +   '<button type="button" class="oa-pick oa-pick-orange" data-c="orange">橙</button>'
            +   '<button type="button" class="oa-pick oa-pick-red" data-c="red">红</button>'
            + '</div>'
            // ⓘ 约定（PLUGIN.md / v1.3.30）：说明写成 .ow-form-item 内的无 class、≤12.5px 的 <p>，
            // 核心的 OwTip.scan 会把它收进 label 右侧的气泡里。可见文字只留控件本身。
            + '<p style="font-size:12px">只对「聊天室上方公告条」生效，进群弹窗通知不画条、与底色无关。'
            + '四档都按站内语义色配好了文字色，深浅两种主题下都清楚。</p></div>'
            + '<div class="ow-form-item"><label class="oa-check"><input type="checkbox" id="oaPinned"> 置顶该公告（在公告条与列表优先展示）</label></div>'
            + '<div class="ow-modal-actions">'
            + '<button class="ow-btn ow-btn-ghost" onclick="OwChat.closeModal()">取消</button>'
            + '<button class="ow-btn ow-btn-primary" id="oaPubBtn">发布</button></div>'
        );
        // 底色只在「公告条」这一档下有意义 —— 切到弹窗通知时整行收起，
        // 免得让人以为弹窗也能染成红的。
        var typeSel = d.getElementById('oaType'), colorRow = d.getElementById('oaColorRow');
        typeSel.onchange = function () { colorRow.style.display = typeSel.value === 'bar' ? '' : 'none'; };
        var picks = d.getElementById('oaPicks').getElementsByTagName('button');
        for (var pi = 0; pi < picks.length; pi++) {
            picks[pi].onclick = function () {
                for (var pj = 0; pj < picks.length; pj++) picks[pj].className = picks[pj].className.replace(' is-on', '');
                this.className += ' is-on';
            };
        }
        d.getElementById('oaPubBtn').onclick = function () {
            var content = d.getElementById('oaContent').value.replace(/^\s+|\s+$/g, '');
            if (!content) { toast('公告内容不能为空'); return; }
            var type = typeSel.value;
            var on = d.getElementById('oaPicks').querySelector('.is-on');
            OwApi.secure('plugin_announcements_add', {
                room_id: current.roomId, content: content, type: type,
                pinned: d.getElementById('oaPinned').checked ? 1 : 0,
                // 弹窗通知不传底色；服务端还会再筛一次白名单
                bar_color: type === 'bar' && on ? on.getAttribute('data-c') : ''
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
    // v1.3.0：这里**不重置 barClosed** —— 用户关过公告条后，切走再切回本群不应又弹出来；
    // 只有切换到另一个群（onRoomSwitch → load）才重置。
    w.OwChat.onViewChange(function (ctx) {
        if (ctx.view === 'room') return;    // 群聊视图：onRoomSwitch 已经负责刷新
        loadGen++;                          // 作废在途请求：切走之后不许再把公告画回来
        current.roomId = 0;
        current.list = [];
        renderBar();                        // list 为空时 renderBar 会自行移除 #oaBar
    });
})(window, document);
