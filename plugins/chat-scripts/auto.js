/**
 * chat-scripts · 群聊自动化 · 群聊设置里的「自动化」入口（v1.1.2）
 *
 * ── 位置 ───────────────────────────────────────────────────────
 * 右侧栏「群聊信息」区块里的一行，紧跟在「群公告」**下面**。
 * 那个区块是 #owREExtras 容器：核心 renderRoomPanel() 渲染完入口行之后，按注册
 * 顺序依次调用 OwChat.onRoomEdit(ctx) 钩子，插件往容器里自己追加。
 * announcements 插件填的是「群公告」，本文件填「自动化」。
 *
 * ⚠️ 顺序为什么要额外兜一次：announcements 是 innerHTML **整体覆盖** #owREExtras 的
 *    （它当初假设只有自己一个钩子）。谁先跑谁占第一行 —— 靠插件目录名字母序
 *    （announcements < chat-scripts）今天刚好对，但那是巧合不是契约。
 *    所以这里 appendChild 完，再在下一个 tick 把 #owAutomationEntry 移到容器末尾：
 *    appendChild 对**已在文档里**的节点是「移动」语义，天然幂等 —— 位置本就在末尾
 *    时等于空操作，被别人覆盖掉时又正好补回来。这样无论别人用哪种写法，
 *    最终顺序都是「群公告 → 自动化」。
 *
 * ── 权限 ───────────────────────────────────────────────────────
 * 只有群主与超级管理员看得到这一行（与群公告同口径）。但前端这一层只是
 * 「不显示没权限的入口」，真正的闸在服务端 csRoomScope()（main.php）：它用
 * rooms.owner_id 复核身份，并把 room_id 钉死在鉴权通过的那个群上 ——
 * 前端传什么都改不了目标群，构造请求也串不到别的群。
 *
 * ── 本群 vs 全站 ───────────────────────────────────────────────
 * 这里管的是**本群**的自动回复与定时群发。「全部群聊」的全局规则属于站长、
 * 只在后台维护；这里只在提示条里告诉群主「另有 N 条全站规则同样生效」。
 *
 * 兼容老浏览器：纯 ES5 + var/function，不用箭头函数 / let / fetch / 模板串。
 * 依赖核心暴露的 OwApi（post / secure）、esc、toast、OwChat（onRoomEdit /
 *      onRoomSwitch / openModal / closeModal / confirm）。
 */
(function (w, d) {
    'use strict';

    // 后台页 / 登录页 / 安装页也会加载合并脚本：没有 OwChat.cfg 直接退出
    if (!w.OwChat || !OwChat.cfg) return;
    if (w.__owCaLoaded) return;                // 幂等：合并资源包可能被加载两次
    w.__owCaLoaded = true;

    var $ = function (id) { return d.getElementById(id); };
    var A = 'plugin_chat_scripts_';
    var esc = w.esc, toast = w.toast;

    /** 弹窗状态。roomId 只由钩子（即当前会话）给，绝不从输入框拼 */
    var CA = { roomId: 0, data: null, tab: 'rule', editId: 0 };
    w.OwCA = CA;

    function int(v, dft) { var n = parseInt(v, 10); return isNaN(n) ? (dft || 0) : n; }
    function brief(s, n) {
        s = String(s == null ? '' : s).replace(/\s+/g, ' ');
        return s.length > n ? s.slice(0, n) + '…' : s;
    }
    /** Unix 秒 → 'YYYY-MM-DD HH:mm'（本地时间）；与 admin.js 同一口径 */
    function fmtTs(ts) {
        ts = int(ts, 0);
        if (ts <= 0) return '—';
        var t = new Date(ts * 1000);
        function p(n) { return (n < 10 ? '0' : '') + n; }
        return t.getFullYear() + '-' + p(t.getMonth() + 1) + '-' + p(t.getDate())
            + ' ' + p(t.getHours()) + ':' + p(t.getMinutes());
    }
    /** 'YYYY-MM-DD HH:mm' / 'YYYY-MM-DDTHH:mm' → Unix 秒；解析失败返回 0 */
    function parseTs(s) {
        s = String(s || '').replace(/^\s+|\s+$/g, '');
        var m = s.match(/^(\d{4})-(\d{1,2})-(\d{1,2})[ T](\d{1,2}):(\d{1,2})/);
        if (!m) return 0;
        var t = new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], 0, 0);
        return isNaN(t.getTime()) ? 0 : Math.floor(t.getTime() / 1000);
    }
    /** Unix 秒 → 'YYYY-MM-DDTHH:mm'（<input type="datetime-local"> 认的格式） */
    function fmtLocal(ts) {
        ts = int(ts, 0);
        if (ts <= 0) return '';
        var t = new Date(ts * 1000);
        function p(n) { return (n < 10 ? '0' : '') + n; }
        return t.getFullYear() + '-' + p(t.getMonth() + 1) + '-' + p(t.getDate())
            + 'T' + p(t.getHours()) + ':' + p(t.getMinutes());
    }

    /**
     * 本浏览器支不支持原生日期时间选择器（v1.1.1）。
     *
     * 一次性定时的「执行时间」原来是个手输框，要求用户自己按 'YYYY-MM-DD HH:mm' 敲
     * —— 手机上没有键盘可弹，桌面端还得记格式，敲错了服务端只回一句「至少 30 秒以后」。
     * 改用 datetime-local 后，现代浏览器点一下就是系统日期时间选择器。
     *
     * 老浏览器（IE 全系、老 Android WebView）不支持：给 <input> 设这个 type 会被
     * **静默**降级成 text，不报错也不提示。所以这里探测一次（同一浏览器结果恒定，
     * 结果缓存在闭包变量里），不支持就退回手输框 + 原格式提示 —— 解析口径本来就
     * 兼容两种写法（parseTs 同时认空格与 T），因此降级路径与增强路径共用同一套存取。
     */
    var dtOk;
    function dtSupported() {
        if (dtOk !== undefined) return dtOk;
        var i = d.createElement('input');
        try { i.setAttribute('type', 'datetime-local'); } catch (e) { /* 老 IE 会抛 */ }
        dtOk = (i.type === 'datetime-local');
        return dtOk;
    }
    /** 把 keywords 字段（JSON 串）解析成数组，坏数据一律当空数组，别让渲染炸掉 */
    function kwArr(s) {
        var a = [];
        try { a = JSON.parse(s) || []; } catch (e) { a = []; }
        if (!(a instanceof Array)) a = [];
        return a;
    }

    /* ============================ 群聊设置里的入口行 ============================ */

    /**
     * 「自动化」图标：一圈不闭合的圆 + 箭头 = 自动 / 循环。
     * 插件自带 path，不去动核心的图标注册表 —— 那边加一个图标要同时改
     * index.php 的 ow_icon 与 assets/js/chat.js 的 OW_ICONS，而 assets/ 与
     * index.php 属主程序，插件不该伸手（.tools/icon-audit.php 也不会扫到内联 svg）。
     */
    function autoIcon() {
        return '<svg class="ow-ico ow-panel-entry-ico" width="16" height="16" viewBox="0 0 24 24" fill="none"'
            + ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            + '<path d="M4.6 12a7.4 7.4 0 1 0 2.2-5.2"/><path d="M4.6 4.4V9.8h5.4"/></svg>';
    }

    w.OwChat.onRoomEdit(function (ctx) {
        var ext = $('owREExtras');
        if (!ext) return;                              // 核心没给这个容器：安静退出
        if (!(ctx.isOwner || ctx.isAdmin)) return;     // 与群公告同口径：群主 + 超管
        if ($('owAutomationEntry')) return;            // 幂等：同一个容器只挂一行

        var row = d.createElement('div');
        row.className = 'ow-panel-entry';
        row.id = 'owAutomationEntry';
        row.setAttribute('role', 'button');
        row.setAttribute('tabindex', '0');
        row.title = '本群的关键词自动回复与定时群发';
        row.innerHTML = autoIcon()
            + '<span class="ow-panel-entry-t">自动化</span>'
            + '<span class="ow-panel-entry-arrow">›</span>';
        // 事件用 JS 绑定而不是 inline onclick：闭包直接拿到 ctx，
        // roomId 不必拼进 HTML 属性，也就没有属性注入面
        row.onclick = function () { open(int(ctx.roomId, 0)); };
        row.onkeydown = function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); row.onclick(); }
        };
        ext.appendChild(row);

        // 下一个 tick 把本行移到容器末尾（理由见文件头「顺序为什么要额外兜一次」）。
        // ⚠️ 这里按 id 现查而不是闭包引用 row：切群会重建整个容器，
        //    闭包里的旧节点那时已经脱离文档，移动它等于往空气里塞。
        w.setTimeout(function () {
            var box = $('owREExtras'), mine = $('owAutomationEntry');
            if (box && mine && mine.parentNode === box) box.appendChild(mine);
        }, 0);
    });

    // 弹窗内容只对「打开它时那个群」成立，切群就关掉：
    // 服务端不会串群，但留着界面会骗人（群主以为在改 A，其实焦点已经到 B 了）
    if (w.OwChat.onRoomSwitch) {
        w.OwChat.onRoomSwitch(function () { if ($('owCaTabs')) OwChat.closeModal(); });
    }

    /* ============================ 弹窗骨架 ============================ */

    function open(roomId) {
        if (roomId <= 0) return;
        CA.roomId = roomId;
        CA.tab = 'rule';
        CA.editId = 0;
        CA.data = null;
        OwChat.openModal(
              '<h3>自动化<span id="owCaRoomName"></span></h3>'
            + '<p class="ow-cs-mg-tip">用机器人账号在本群自动应答与定时发送。机器人发出的消息与普通用户消息一样过敏感词、禁言与频率限制。</p>'
            + '<div class="ow-cs-mg-tabs" id="owCaTabs">'
            + '<button class="ow-cs-mg-tab is-active" type="button" data-t="rule">自动回复</button>'
            + '<button class="ow-cs-mg-tab" type="button" data-t="cast">定时群发</button>'
            + '</div>'
            + '<div id="owCaNotice"></div>'
            + '<div id="owCaFormWrap"></div>'
            + '<div class="ow-cs-mg-list" id="owCaList"></div>',
            560);
        var tabs = $('owCaTabs').querySelectorAll('.ow-cs-mg-tab'), i;
        for (i = 0; i < tabs.length; i++) {
            tabs[i].onclick = function () {
                var t = this.getAttribute('data-t');
                if (t === CA.tab) return;
                CA.tab = t;
                CA.editId = 0;
                var all = $('owCaTabs').querySelectorAll('.ow-cs-mg-tab'), j;
                for (j = 0; j < all.length; j++) {
                    all[j].className = all[j].getAttribute('data-t') === t ? 'ow-cs-mg-tab is-active' : 'ow-cs-mg-tab';
                }
                render();
            };
        }
        render();      // 先画「加载中」，别让弹窗空一拍
        load();
    }

    function load() {
        var rid = CA.roomId;
        OwApi.post(A + 'room_auto', { room_id: rid }, function (r) {
            if (!r || !r.ok) { toast((r && r.msg) || '加载失败'); return; }
            if (CA.roomId !== rid) return;             // 已经切群：在途响应作废
            CA.data = r;
            var nm = $('owCaRoomName');
            if (nm) nm.textContent = ' · ' + ((r.room && r.room.name) || '');
            render();
        });
    }

    function render() {
        var wrap = $('owCaFormWrap'), list = $('owCaList');
        if (!wrap || !list) return;                    // 弹窗已被关掉 / 被别的弹窗顶掉
        renderNotice();
        if (!CA.data) {
            wrap.innerHTML = '';
            list.innerHTML = '<p class="ow-cs-empty">加载中…</p>';
            return;
        }
        if (CA.tab === 'cast') { renderCastForm(); renderCastList(); }
        else { renderRuleForm(); renderRuleList(); }
    }

    /**
     * 顶部提示条：把「为什么不会生效」一次说清。
     * 机器人没配 / 进不了本群 / 不是本群成员 / 站长关了全站自动回复 —— 四种情况
     * 群主自己都看不出来（规则写得再对也不发），所以必须显式讲明。
     */
    function renderNotice() {
        var box = $('owCaNotice');
        if (!box) return;
        if (!CA.data) { box.innerHTML = ''; return; }
        var D = CA.data, h = '';
        if (!D.bot) {
            h = '<p class="ow-ca-notice is-warn">站长还没有配置机器人账号，自动化暂时用不了。'
              + '自动回复与定时群发都要以「机器人账号」的身份在本群发言，请先联系站长设置。</p>';
        } else if (!int(D.bot_ok)) {
            h = '<p class="ow-ca-notice is-warn">机器人进不了本群，自动化暂时用不了。'
              + '目前只支持公开群聊；密码房需要机器人本人是超级管理员，角色房按角色等级判定。</p>';
        } else if (!int(D.bot_member)) {
            h = '<p class="ow-ca-notice is-warn">机器人（' + esc(D.bot.nickname) + '）还不是本群成员，'
              + '不加入进来它一条都发不出。'
              + '<button class="ow-btn ow-btn-primary" type="button" id="owCaBotJoin">让机器人加入本群</button></p>';
        } else {
            h = '<p class="ow-ca-notice is-info">机器人：<b>' + esc(D.bot.nickname) + '</b>'
              + (int(D.site_off) ? ' · <b>站长已关闭全站自动回复</b>，本群规则暂不会触发' : '')
              + (int(D.global_rules) ? ' · 另有 ' + int(D.global_rules) + ' 条全站规则在本群同样生效（由站长维护）' : '')
              + '</p>';
        }
        box.innerHTML = h;
        var jb = $('owCaBotJoin');
        if (jb) jb.onclick = botJoin;
    }

    function botJoin() {
        var rid = CA.roomId;
        OwApi.secure(A + 'room_bot_join', { room_id: rid }, function (r) {
            toast((r && r.msg) || (r && r.ok ? '已加入' : '操作失败'));
            if (r && r.ok && CA.roomId === rid) load();
        });
    }

    /* ============================ 自动回复 ============================ */

    function ruleCap() { return int((CA.data.limits || {}).rule, 20); }

    function renderRuleForm() {
        var list = CA.data.rules || [], i, cur = null;
        for (i = 0; i < list.length; i++) if (int(list[i].id) === CA.editId) { cur = list[i]; break; }
        var r = cur || { keywords: '[]', match_mode: 'include', reply: '', enabled: 1 };
        var kws = kwArr(r.keywords);
        $('owCaFormWrap').innerHTML =
              '<div class="ow-cs-mg-form">'
            + '<div class="ow-form-item"><label>匹配方式</label><select class="ow-input" id="owCaRMode">'
            + '<option value="include"' + (r.match_mode !== 'exact' ? ' selected' : '') + '>包含任一关键词即命中</option>'
            + '<option value="exact"' + (r.match_mode === 'exact' ? ' selected' : '') + '>整条消息全等才命中</option>'
            + '</select></div>'
            + '<div class="ow-form-item"><label>关键词（逗号分隔，最多 10 个，每个不超过 20 字）</label>'
            + '<input class="ow-input" id="owCaRKw" maxlength="200" value="' + esc(kws.join(',')) + '" placeholder="如：多少钱,价格,报价"></div>'
            + '<div class="ow-form-item"><label>回复内容</label>'
            + '<textarea class="ow-input" id="owCaRReply" rows="3" maxlength="2000" placeholder="机器人回复的内容，不超过 2000 字">' + esc(r.reply) + '</textarea></div>'
            + '<div class="ow-form-item"><label class="ow-cs-check"><input type="checkbox" id="owCaROn"' + (int(r.enabled) ? ' checked' : '') + '><span>启用本规则</span></label></div>'
            + '<div class="ow-cs-mg-btns">'
            + '<button class="ow-btn ow-btn-primary" type="button" id="owCaRSave">' + (CA.editId ? '保存修改' : '新增规则') + '</button>'
            + (CA.editId ? '<button class="ow-btn" type="button" id="owCaRCancel">取消编辑</button>' : '')
            + '</div></div>';
        $('owCaRSave').onclick = saveRule;
        if ($('owCaRCancel')) $('owCaRCancel').onclick = function () { CA.editId = 0; render(); };
    }

    function renderRuleList() {
        var list = CA.data.rules || [], i, h = '';
        h = '<table class="ow-table"><tr><th>关键词</th><th>匹配</th><th>回复</th><th>命中</th><th>状态</th><th>操作</th></tr>';
        if (!list.length) {
            h += '<tr><td colspan="6" class="ow-cs-empty">本群还没有自动回复规则，在上面新增一条</td></tr>';
        }
        for (i = 0; i < list.length; i++) {
            var it = list[i];
            h += '<tr><td>' + esc(kwArr(it.keywords).join('、')) + '</td>'
                + '<td>' + (it.match_mode === 'exact' ? '全等' : '包含') + '</td>'
                + '<td title="' + esc(it.reply) + '">' + esc(brief(it.reply, 20)) + '</td>'
                + '<td>' + esc(it.hit_count || 0) + '</td>'
                + '<td>' + (int(it.enabled) ? '启用' : '停用') + '</td>'
                + '<td><a href="javascript:;" data-act="edit" data-id="' + esc(it.id) + '">编辑</a>'
                + ' <a href="javascript:;" data-act="del" data-id="' + esc(it.id) + '">删除</a></td></tr>';
        }
        h += '</table><p class="ow-cs-mg-tip" style="margin:8px 0 0">本群最多 ' + ruleCap()
            + ' 条自动回复，当前 ' + list.length + ' 条。</p>';
        var box = $('owCaList');
        box.innerHTML = h;
        // 事件委托：条目上的编辑/删除走 data-act，不往 HTML 里塞 inline 全局函数
        box.onclick = function (e) {
            e = e || w.event;
            var t = e.target || e.srcElement;
            if (!t || !t.getAttribute) return;
            var act = t.getAttribute('data-act');
            if (!act) return;
            var id = int(t.getAttribute('data-id'), 0);
            if (act === 'edit') { CA.editId = id; render(); }
            else if (act === 'del') delRule(id);
        };
    }

    function saveRule() {
        var raw = ($('owCaRKw').value || '').split(/[,，]/), clean = [], k;
        for (k = 0; k < raw.length; k++) {
            var s = raw[k].replace(/^\s+|\s+$/g, '');
            if (s) clean.push(s);
            if (clean.length >= 10) break;
        }
        if (!clean.length) { toast('至少填写一个关键词'); return; }
        var rid = CA.roomId;
        OwApi.secure(A + 'room_rule_save', {
            room_id: rid,                       // ⚠️ 只有本群 id；服务端仍会用 rooms.owner_id 复核
            id: CA.editId,
            match_mode: $('owCaRMode').value,
            keywords: JSON.stringify(clean),
            reply: ($('owCaRReply').value || '').replace(/^\s+|\s+$/g, ''),
            enabled: $('owCaROn').checked ? 1 : 0
        }, function (r) {
            toast((r && r.msg) || (r && r.ok ? '已保存' : '保存失败'));
            if (r && r.ok) { CA.editId = 0; if (CA.roomId === rid) load(); }
        });
    }

    function delRule(id) {
        OwChat.confirm('删除后本群不再按这条自动回复，确定删除吗？', function () {
            var rid = CA.roomId;
            // ⚠️ room_id 必须带上：服务端 csRoomScope() 强制要求（没有就回「缺少群聊 ID」），
            //    这是防 IDOR 的设计——只传 id 的话服务端连「哪个群」都不知道。
            OwApi.secure(A + 'room_rule_del', { id: id, room_id: rid }, function (r) {
                toast((r && r.msg) || (r && r.ok ? '已删除' : '删除失败'));
                if (r && r.ok) {
                    if (CA.editId === id) CA.editId = 0;
                    if (CA.roomId === rid) load();
                }
            });
        });
    }

    /* ============================ 定时群发 ============================ */

    function castCap() { return int((CA.data.limits || {}).cast, 10); }

    function renderCastForm() {
        var list = CA.data.casts || [], i, cur = null;
        for (i = 0; i < list.length; i++) if (int(list[i].id) === CA.editId) { cur = list[i]; break; }
        var c = cur || { content: '', mode: 'loop', interval_sec: 3600, send_at: 0 };
        // 执行时间用原生选择器；不支持的浏览器退回手输框（见 dtSupported 注释）。
        // 两条路都读同一个 id，保存时只差一次格式解析（parseTs 两种都认）。
        // min 取「当前 +1 分钟」：服务端要求至少 30 秒以后，选择器别让人白选过去的时间。
        // ⚠️ 用 new Date().getTime() 而不是 Date.now()：后者是 ES5.1 才有的，
        //    老引擎里是 undefined（项目目标包含落后内核）。
        var nowSec = Math.floor(new Date().getTime() / 1000);
        var atInput = dtSupported()
            ? '<input class="ow-input" id="owCaCeSendAt" type="datetime-local" step="60" min="' + fmtLocal(nowSec + 60) + '"'
              + ' value="' + esc(fmtLocal(c.send_at)) + '">'
            : '<input class="ow-input" id="owCaCeSendAt" value="' + esc(int(c.send_at) ? fmtTs(c.send_at) : '') + '"'
              + ' placeholder="如 2026-10-15 09:00">';
        $('owCaFormWrap').innerHTML =
              '<div class="ow-cs-mg-form">'
            + '<div class="ow-form-item"><label>发送内容</label>'
            + '<textarea class="ow-input" id="owCaCeContent" rows="3" maxlength="2000" placeholder="到时间用机器人账号在本群发送的内容，不超过 2000 字">' + esc(c.content) + '</textarea></div>'
            + '<div class="ow-form-item"><label>模式</label><select class="ow-input" id="owCaCeMode">'
            + '<option value="loop"' + (c.mode !== 'once' ? ' selected' : '') + '>周期循环</option>'
            + '<option value="once"' + (c.mode === 'once' ? ' selected' : '') + '>一次性定时</option>'
            + '</select></div>'
            + '<div class="ow-form-item" id="owCaCeLoopItem"><label>间隔（分钟，最短 5 分钟）</label>'
            + '<input class="ow-input" id="owCaCeInterval" type="number" min="5" max="10080" value="'
            + esc(Math.max(5, Math.round(int(c.interval_sec) / 60))) + '"></div>'
            + '<div class="ow-form-item" id="owCaCeOnceItem" style="display:none"><label>执行时间'
            + (dtSupported() ? '' : '（格式 年-月-日 时:分）') + '</label>'
            + atInput + '</div>'
            + '<div class="ow-cs-mg-btns">'
            + '<button class="ow-btn ow-btn-primary" type="button" id="owCaCeSave">' + (CA.editId ? '保存修改' : '新增任务') + '</button>'
            + (CA.editId ? '<button class="ow-btn" type="button" id="owCaCeCancel">取消编辑</button>' : '')
            + '</div></div>';
        function syncMode() {
            var once = $('owCaCeMode').value === 'once';
            $('owCaCeLoopItem').style.display = once ? 'none' : '';
            $('owCaCeOnceItem').style.display = once ? '' : 'none';
        }
        $('owCaCeMode').onchange = syncMode;
        syncMode();
        $('owCaCeSave').onclick = saveCast;
        if ($('owCaCeCancel')) $('owCaCeCancel').onclick = function () { CA.editId = 0; render(); };
    }

    function renderCastList() {
        var list = CA.data.casts || [], i;
        var h = '<table class="ow-table"><tr><th>内容</th><th>模式</th><th>下次执行</th><th>上次</th><th>次数</th><th>状态</th><th>操作</th></tr>';
        if (!list.length) {
            h += '<tr><td colspan="7" class="ow-cs-empty">本群还没有定时群发任务，在上面新增一条</td></tr>';
        }
        for (i = 0; i < list.length; i++) {
            var c = list[i];
            h += '<tr><td title="' + esc(c.content) + '">' + esc(brief(c.content, 20)) + '</td>'
                + '<td>' + (c.mode === 'once' ? '一次性' : '每 ' + Math.max(5, Math.round(int(c.interval_sec) / 60)) + ' 分钟') + '</td>'
                + '<td>' + (int(c.enabled) ? fmtTs(c.next_run_at) : '—') + '</td>'
                + '<td>' + fmtTs(c.last_run_at)
                + (c.last_status ? '<br><span style="color:var(--ow-text-sub)">' + esc(brief(c.last_status, 16)) + '</span>' : '')
                + '</td>'
                + '<td>' + esc(c.run_count || 0) + '</td>'
                + '<td>' + (int(c.enabled) ? '启用' : '停用') + '</td>'
                + '<td><a href="javascript:;" data-act="edit" data-id="' + esc(c.id) + '">编辑</a>'
                + ' <a href="javascript:;" data-act="del" data-id="' + esc(c.id) + '">删除</a></td></tr>';
        }
        h += '</table><p class="ow-cs-mg-tip" style="margin:8px 0 0">本群最多 ' + castCap()
            + ' 条定时群发，当前 ' + list.length + ' 条。发送失败会在「上次」里写明原因。</p>';
        var box = $('owCaList');
        box.innerHTML = h;
        box.onclick = function (e) {
            e = e || w.event;
            var t = e.target || e.srcElement;
            if (!t || !t.getAttribute) return;
            var act = t.getAttribute('data-act');
            if (!act) return;
            var id = int(t.getAttribute('data-id'), 0);
            if (act === 'edit') { CA.editId = id; render(); }
            else if (act === 'del') delCast(id);
        };
    }

    function saveCast() {
        var mode = $('owCaCeMode').value;
        var content = ($('owCaCeContent').value || '').replace(/^\s+|\s+$/g, '');
        if (!content) { toast('请填写发送内容'); return; }
        var payload = {
            room_id: CA.roomId,                 // ⚠️ 目标群恒为本群
            id: CA.editId,
            content: content,
            mode: mode,
            interval_sec: int($('owCaCeInterval').value, 60) * 60,
            send_at: 0
        };
        if (mode === 'once') {
            payload.send_at = parseTs($('owCaCeSendAt').value);
            if (!payload.send_at) {
                toast(dtSupported() ? '请选择执行时间' : '请按格式填写执行时间，如 2026-10-15 09:00');
                return;
            }
        }
        var rid = CA.roomId;
        OwApi.secure(A + 'room_cast_save', payload, function (r) {
            toast((r && r.msg) || (r && r.ok ? '已保存' : '保存失败'));
            if (r && r.ok) { CA.editId = 0; if (CA.roomId === rid) load(); }
        });
    }

    function delCast(id) {
        OwChat.confirm('删除后这个任务不再发送，已经发出去的消息不会撤回。确定删除吗？', function () {
            var rid = CA.roomId;
            // ⚠️ room_id 必须带上：服务端 csRoomScope() 强制要求（没有就回「缺少群聊 ID」），
            //    与 delRule 同一原因（防 IDOR，服务端仍需「哪个群」做归属校验）。
            OwApi.secure(A + 'room_cast_del', { id: id, room_id: rid }, function (r) {
                toast((r && r.msg) || (r && r.ok ? '已删除' : '删除失败'));
                if (r && r.ok) {
                    if (CA.editId === id) CA.editId = 0;
                    if (CA.roomId === rid) load();
                }
            });
        });
    }
})(window, document);
