/**
 * chat-scripts · 群聊自动化 · 前台话术面板（v1.1.2）
 *
 * 入口：「话术」按钮由 main.php 的 chat.toolbar 钩子在服务端产出（与表情包、附件上传同一
 *      规范；核心零分支，插件停用则按钮不出现）。面板挂到 #owEmojiPanel 的父节点上，
 *      复用 .ow-emoji-panel 那套绝对定位坐标系（移动端核心已有 92% 宽适配）。
 *
 * ⚠️ 按钮的 title 是「话术」而不是插件名「群聊自动化」：这颗按钮只开话术面板。
 *    插件的另半个能力（关键词自动回复 / 定时群发）不在这里，而是在**群聊设置**里
 *    的「自动化」入口（见 auto.js），群主在群里直接管。
 *
 * 三档话术（v1.0.1 起群主可在前台自管群档）：
 *   · 公共  scope=public  管理员后台维护，全站只读可用；
 *   · 群聊  scope=room    群主/超管在本前台直接增删改，本群成员都能用
 *                        （「进得去这个群」才拉得到，密码房同口径）；
 *   · 我的  scope=user    登录用户自建，仅本人可见。
 * 交互：点击条目 = 插入输入框光标处（可改完再发）；点「发送」= 直接发出。
 *       私聊视图下两种操作都自动走私聊通道（OwChat.send 内部改写，与手动发消息一致）。
 *
 * 兼容老浏览器：纯 ES5 + var/function，不用箭头函数 / let / fetch / 模板串。
 * 依赖核心暴露的 OwApi / esc / toast / OwChat（send / openModal / confirm / icon）。
 */
(function (w, d) {
    'use strict';

    // 后台页 / 登录页 / 安装页也会加载合并脚本：没有 OwChat.cfg 直接退出（幂等防重复注入）
    if (!w.OwChat || !OwChat.cfg) return;
    if (w.__owCsLoaded) return;
    w.__owCsLoaded = true;

    var $ = function (id) { return d.getElementById(id); };
    var A = 'plugin_chat_scripts_';
    var esc = w.esc, toast = w.toast;

    var CS = {
        data: null,      // { public:[], room:[], room_admin:0|1, mine:[], guest:0|1 }
        tab: 'public',   // 当前分组：public 公共 / room 群聊 / mine 我的
        q: '',
        opened: false,
        mgTab: 'mine',   // 管理弹窗内的子档：mine 我的话术 / room 本群话术
        mgId: 0          // 管理弹窗正在编辑的话术 id（0 = 新增）
    };
    w.OwCS = CS;

    /** 当前房间 ID（OwChat.room 由核心在切群/私聊时维护；拿不到按 0 处理） */
    function curRoom() { return (OwChat.room || 0) > 0 ? (OwChat.room | 0) : 0; }

    /* ---------- 工具栏按钮（HTML 由 main.php 的 chat.toolbar 钩子产出，这里只绑事件） ---------- */

    function mount() {
        var btn = $('owBtnCs');
        if (!btn) return;                       // 本插件未启用（钩子没注册），安静退出
        btn.onclick = function (e) {
            if (e && e.stopPropagation) e.stopPropagation(); else w.event.cancelBubble = true;
            CS.opened ? close() : open();
        };
        // 点面板与按钮以外的地方收起（与表情面板同一交互预期）
        d.addEventListener ? d.addEventListener('click', onDocClick, false) : (d.onclick = onDocClick);
    }

    function onDocClick(e) {
        if (!CS.opened) return;
        e = e || w.event;
        var t = e.target || e.srcElement, p = $('owCsPanel');
        if (!p) return;
        var n = t;
        while (n) {
            if (n === p || n.id === 'owBtnCs') return;
            n = n.parentNode;
        }
        close();
    }

    /* ---------- 面板 ---------- */

    function panel() {
        var p = $('owCsPanel');
        if (p) return p;
        p = d.createElement('div');
        p.className = 'ow-cs-panel';
        p.id = 'owCsPanel';
        p.style.display = 'none';
        // v1.1.0：头部右侧的 ✕ 关闭按钮撤掉（用户要求）。关闭改由「点面板与按钮
        // 以外的地方」（onDocClick）与再点一次工具栏那颗按钮（toggle）承担。
        p.innerHTML =
              '<div class="ow-cs-head">'
            + '<input class="ow-input ow-cs-q" id="owCsQ" type="text" maxlength="30" autocomplete="off" placeholder="搜索话术标题或内容">'
            + '</div>'
            + '<div class="ow-cs-tabs ow-cs-tabs-in">'
            + '<button class="ow-cs-tab is-active" type="button" data-t="public">公共</button>'
            + '<button class="ow-cs-tab" type="button" data-t="room" style="display:none">群聊</button>'
            + '<button class="ow-cs-tab" type="button" data-t="mine">我的</button>'
            + '</div>'
            + '<div class="ow-cs-body" id="owCsBody"></div>'
            + '<div class="ow-cs-foot">'
            + '<span class="ow-cs-count" id="owCsCount"></span>'
            + '<button class="ow-btn ow-btn-ghost ow-cs-manage" id="owCsManage" type="button">管理</button>'
            + '</div>';
        // 挂在表情面板的同一个父节点上，才能复用那套绝对定位
        var anchor = $('owEmojiPanel');
        (anchor && anchor.parentNode ? anchor.parentNode : d.body).appendChild(p);
        $('owCsQ').oninput = function () { CS.q = (this.value || '').replace(/^\s+|\s+$/g, ''); render(); };
        $('owCsManage').onclick = function () { close(); mgOpen(); };
        var tabs = p.querySelectorAll('.ow-cs-tab'), i;
        for (i = 0; i < tabs.length; i++) {
            tabs[i].onclick = function () {
                var t = this.getAttribute('data-t');
                if (t === CS.tab) return;
                CS.tab = t;
                var all = $('owCsPanel').querySelectorAll('.ow-cs-tab'), j;
                for (j = 0; j < all.length; j++) {
                    if (all[j].getAttribute('data-t') === t) all[j].className = 'ow-cs-tab is-active';
                    else all[j].className = 'ow-cs-tab';
                }
                render();
            };
        }
        // 事件委托：条目点击 = 插入；「发送」按钮 = 直接发
        $('owCsBody').onclick = function (e) {
            e = e || w.event;
            var t = e.target || e.srcElement;
            var sendBtn = null, item = null, n = t;
            while (n && n !== this) {
                if (!sendBtn && n.className && String(n.className).indexOf('ow-cs-send') === 0) sendBtn = n;
                if (!item && n.className && String(n.className).indexOf('ow-cs-item') === 0) item = n;
                n = n.parentNode;
            }
            if (!item) return;
            var text = item.getAttribute('data-content') || '';
            if (!text) return;
            if (sendBtn) {
                if (e && e.stopPropagation) e.stopPropagation(); else w.event.cancelBubble = true;
                OwChat.send({ type: 'text', content: text });
                useCount(item.getAttribute('data-id'));
                close();
            } else {
                insertAtCursor(text);
                useCount(item.getAttribute('data-id'));
                close();
            }
        };
        return p;
    }

    function open() {
        var p = panel();
        // 与表情面板互斥：两个都开着会叠在一起
        var em = $('owEmojiPanel');
        if (em) em.style.display = 'none';
        p.style.display = '';
        CS.opened = true;
        load();
        var inp = $('owCsQ');
        if (inp) inp.focus();
    }

    function close() {
        var p = $('owCsPanel');
        if (p) p.style.display = 'none';
        CS.opened = false;
    }

    /* ---------- 数据 ---------- */

    function load() {
        OwApi.post(A + 'snips', { room_id: curRoom() }, function (r) {
            if (!r || !r.ok) { render(); return; }
            CS.data = {
                public: r.public || [], room: r.room || [], room_admin: r.room_admin ? 1 : 0,
                mine: r.mine || [], guest: r.guest ? 1 : 0
            };
            // 「群聊」tab：本群有群话术，或我是群主/超管（空群也要让群主找到添加入口）
            var roomTab = $('owCsPanel') && $('owCsPanel').querySelector('.ow-cs-tab[data-t="room"]');
            if (roomTab) roomTab.style.display = (CS.data.room.length || CS.data.room_admin) ? '' : 'none';
            // 当前 tab 变得不可见时退回公共档
            if (CS.tab === 'room' && !(CS.data.room.length || CS.data.room_admin)) CS.tab = 'public';
            render();
        });
    }

    function cur() {
        if (!CS.data) return [];
        var list = CS.tab === 'mine' ? CS.data.mine : (CS.tab === 'room' ? CS.data.room : CS.data.public);
        if (!CS.q) return list;
        var q = CS.q.toLowerCase(), out = [], i, s;
        for (i = 0; i < list.length; i++) {
            s = (list[i].title || '') + ' ' + (list[i].content || '');
            if (s.toLowerCase().indexOf(q) >= 0) out.push(list[i]);
        }
        return out;
    }

    function render() {
        var body = $('owCsBody');
        if (!body) return;
        if (!CS.data) { body.innerHTML = '<p class="ow-cs-empty">加载中…</p>'; foot(0); return; }

        var list = cur();
        if (CS.tab === 'mine' && CS.data.guest) {
            body.innerHTML = '<p class="ow-cs-empty">登录后即可创建和使用个人话术</p>';
            foot(0);
            var mg = $('owCsManage');
            if (mg) mg.style.display = 'none';
            return;
        }
        var mg2 = $('owCsManage');
        if (mg2) mg2.style.display = (CS.tab === 'mine' || (CS.tab === 'room' && CS.data.room_admin)) ? '' : 'none';

        if (!list.length) {
            var tip;
            if (CS.tab === 'mine') tip = '还没有个人话术，点「管理」添加';
            else if (CS.tab === 'room') tip = CS.data.room_admin ? '本群暂无群话术，点「管理」添加' : '本群暂无群话术';
            else tip = CS.q ? '没有匹配的话术' : '暂无公共话术';
            body.innerHTML = '<p class="ow-cs-empty">' + esc(tip) + '</p>';
            foot(0);
            return;
        }
        // 按分组聚合，分组内保持服务端排序
        var groups = [], map = {}, i, g;
        for (i = 0; i < list.length; i++) {
            g = list[i].group_name || '未分组';
            if (!map[g]) { map[g] = []; groups.push(g); }
            map[g].push(list[i]);
        }
        var h = '';
        for (i = 0; i < groups.length; i++) {
            h += '<div class="ow-cs-group"><div class="ow-cs-group-t">' + esc(groups[i]) + '</div>';
            var items = map[groups[i]];
            for (var j = 0; j < items.length; j++) {
                var it = items[j];
                var brief = (it.content || '').replace(/\s+/g, ' ');
                if (brief.length > 60) brief = brief.slice(0, 60) + '…';
                h += '<div class="ow-cs-item" data-id="' + esc(it.id) + '" data-content="' + esc(it.content) + '">'
                    + '<div class="ow-cs-item-main">'
                    + '<b class="ow-cs-item-t">' + esc(it.title) + '</b>'
                    + '<span class="ow-cs-item-c">' + esc(brief) + '</span></div>'
                    + '<button class="ow-cs-send" type="button" title="直接发送">发送</button>'
                    + '</div>';
            }
            h += '</div>';
        }
        body.innerHTML = h;
        foot(list.length);
    }

    function foot(n) {
        var c = $('owCsCount');
        if (c) c.textContent = n ? (n + ' 条') : '';
    }

    /** 插入输入框光标处，并触发核心的自动增高 */
    function insertAtCursor(text) {
        var inp = $('owInput');
        if (!inp) { toast('找不到输入框'); return; }
        var s = inp.selectionStart, e = inp.selectionEnd, v = inp.value;
        if (s == null || e == null) {                   // 老浏览器不支持 selection：退化为追加
            inp.value = v + text;
        } else {
            inp.value = v.slice(0, s) + text + v.slice(e);
            var p = s + text.length;
            inp.selectionStart = p; inp.selectionEnd = p;
        }
        inp.focus();
        if (OwChat.autoGrow) OwChat.autoGrow();         // 核心输入框自动增高（oninput 同一入口）
    }

    function useCount(id) {
        if (!id) return;
        OwApi.post(A + 'use', { id: id }, function () {});
    }

    /* ---------- 管理弹窗（我的话术 / 本群话术 CRUD） ---------- */

    function mgOpen() {
        if (CS.data && CS.data.guest) { toast('请先登录'); return; }
        CS.mgId = 0;
        // 默认子档：跟面板当前 tab 走（在群聊tab点管理就该管群话术）
        CS.mgTab = (CS.tab === 'room' && CS.data && CS.data.room_admin) ? 'room' : 'mine';
        OwChat.openModal(
              '<h3>话术管理</h3>'
            + '<div class="ow-cs-mg-tabs" id="owCsMgTabs">'
            + '<button class="ow-cs-mg-tab is-active" type="button" data-m="mine">我的话术</button>'
            + '<button class="ow-cs-mg-tab" type="button" data-m="room" style="display:none">本群话术（群主）</button>'
            + '</div>'
            + '<p class="ow-cs-mg-tip">点面板里的条目 = 插入输入框；点「发送」= 直接发出。个人话术最多 200 条、群话术最多 100 条。</p>'
            + '<div class="ow-cs-mg-form">'
            + '<input class="ow-input" id="owCsMgTitle" maxlength="60" placeholder="标题（不超过 60 字）">'
            + '<input class="ow-input" id="owCsMgGroup" maxlength="30" placeholder="分组（留空为「未分组」）">'
            + '<textarea class="ow-input" id="owCsMgContent" rows="3" maxlength="2000" placeholder="内容（不超过 2000 字）"></textarea>'
            + '<div class="ow-cs-mg-btns">'
            + '<button class="ow-btn ow-btn-primary" type="button" onclick="OwCS.mgSave()">保存</button>'
            + '<button class="ow-btn" type="button" onclick="OwCS.mgReset()">清空</button>'
            + '</div></div>'
            + '<div class="ow-cs-mg-list" id="owCsMgList"></div>',
            520);
        var roomTab = $('owCsMgTabs').querySelector('[data-m="room"]');
        if (roomTab && CS.data && CS.data.room_admin) roomTab.style.display = '';
        var tabs = $('owCsMgTabs').querySelectorAll('.ow-cs-mg-tab'), i;
        for (i = 0; i < tabs.length; i++) {
            tabs[i].onclick = function () {
                var t = this.getAttribute('data-m');
                CS.mgTab = t;
                CS.mgId = 0;
                var all = $('owCsMgTabs').querySelectorAll('.ow-cs-mg-tab'), j;
                for (j = 0; j < all.length; j++) {
                    if (all[j].getAttribute('data-m') === t) all[j].className = 'ow-cs-mg-tab is-active';
                    else all[j].className = 'ow-cs-mg-tab';
                }
                mgReset();
                mgRender();
            };
        }
        mgRender();
    }

    function mgList() {
        if (!CS.data) return [];
        return CS.mgTab === 'room' ? (CS.data.room || []) : (CS.data.mine || []);
    }

    function mgRender() {
        var box = $('owCsMgList');
        if (!box) return;
        var list = mgList();
        if (!list.length) {
            box.innerHTML = '<p class="ow-cs-empty">'
                + (CS.mgTab === 'room' ? '本群暂无群话术' : '还没有个人话术') + '</p>';
            return;
        }
        var h = '<table class="ow-table"><tr><th>标题</th><th>分组</th><th>内容</th><th>已喂次数</th><th>操作</th></tr>';
        for (var i = 0; i < list.length; i++) {
            var it = list[i];
            var brief = (it.content || '').replace(/\s+/g, ' ');
            if (brief.length > 40) brief = brief.slice(0, 40) + '…';
            h += '<tr><td>' + esc(it.title) + '</td><td>' + esc(it.group_name || '未分组') + '</td>'
                + '<td title="' + esc(it.content) + '">' + esc(brief) + '</td>'
                + '<td>' + esc(it.use_count || 0) + '</td>'
                + '<td><a href="javascript:;" onclick="OwCS.mgEdit(' + esc(it.id) + ')">编辑</a>'
                + ' <a href="javascript:;" onclick="OwCS.mgDel(' + esc(it.id) + ')">删除</a></td></tr>';
        }
        box.innerHTML = h + '</table>';
    }

    CS.mgEdit = function (id) {
        var list = mgList(), i;
        for (i = 0; i < list.length; i++) {
            if (String(list[i].id) === String(id)) {
                CS.mgId = parseInt(list[i].id, 10);
                $('owCsMgTitle').value = list[i].title || '';
                $('owCsMgGroup').value = list[i].group_name === '未分组' ? '' : (list[i].group_name || '');
                $('owCsMgContent').value = list[i].content || '';
                $('owCsMgTitle').focus();
                return;
            }
        }
    };

    CS.mgReset = function () {
        CS.mgId = 0;
        $('owCsMgTitle').value = '';
        $('owCsMgGroup').value = '';
        $('owCsMgContent').value = '';
    };

    CS.mgSave = function () {
        var title = ($('owCsMgTitle').value || '').replace(/^\s+|\s+$/g, '');
        var group = ($('owCsMgGroup').value || '').replace(/^\s+|\s+$/g, '');
        var content = ($('owCsMgContent').value || '').replace(/^\s+|\s+$/g, '');
        if (!title) { toast('请填写标题'); return; }
        if (!content) { toast('请填写内容'); return; }
        var isRoom = CS.mgTab === 'room';
        var payload = { id: CS.mgId, title: title, group: group, content: content };
        var action = A + 'snip_save';
        if (isRoom) {
            payload.room_id = curRoom();
            action = A + 'room_snip_save';
        }
        OwApi.post(action, payload, function (r) {
            toast((r && r.msg) || (r && r.ok ? '已保存' : '保存失败'));
            if (r && r.ok) {
                CS.mgReset();
                load();            // 刷新面板数据
                mgRender();        // 刷新弹窗列表
            }
        });
    };

    CS.mgDel = function (id) {
        var isRoom = CS.mgTab === 'room';
        OwChat.confirm('删除后无法恢复，确定删除这条话术吗？', function () {
            var action = isRoom ? A + 'room_snip_del' : A + 'snip_del';
            OwApi.secure(action, { id: id }, function (r) {
                toast((r && r.msg) || (r && r.ok ? '已删除' : '删除失败'));
                if (r && r.ok) {
                    if (CS.mgId === parseInt(id, 10)) CS.mgReset();
                    load();
                    mgRender();
                }
            });
        });
    };

    /* ---------- 启动 ---------- */

    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', mount, false);
    else mount();
})(window, document);
