/**
 * chat-scripts · 群聊脚本 · 后台交互
 * 全局对象 OwCSAdmin：快捷话术 / 自动回复规则 / 定时群发任务 / 发送日志 四个页签。
 *
 * ⚠️ 本文件随合并资源包在所有页面加载：前台聊天页里 OwCSAdmin 的方法一个都不会被调用
 *    （页面 HTML 只存在于后台），但 boot() 必须判容器存在，否则白等。
 *    插件页是点菜单后由 Ajax 塞进 #owAdminMain 的，用 MutationObserver 等容器出现
 *    （与 daily-checkin / level-trust 同一套路）。
 *
 * 依赖：OwApi（post / secure）、toast、esc。弹窗自建（.ow-modal-mask / .ow-modal，
 *      与 user-manager 后台一致——后台页没有 OwChat）。
 * 兼容老浏览器：纯 ES5，不用箭头函数 / let / fetch / 模板串。
 */
(function (w, d) {
    'use strict';

    var $ = function (id) { return d.getElementById(id); };
    var A = 'plugin_chat_scripts_admin_';
    var F = 'plugin_chat_scripts_';
    var booted = false;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
    function int(v, dft) { var n = parseInt(v, 10); return isNaN(n) ? (dft || 0) : n; }

    /** Unix 秒 → 'YYYY-MM-DD HH:mm'（本地时间） */
    function fmtTs(ts) {
        ts = int(ts, 0);
        if (ts <= 0) return '—';
        var t = new Date(ts * 1000);
        function p(n) { return (n < 10 ? '0' : '') + n; }
        return t.getFullYear() + '-' + p(t.getMonth() + 1) + '-' + p(t.getDate())
            + ' ' + p(t.getHours()) + ':' + p(t.getMinutes());
    }
    /** 'YYYY-MM-DD HH:mm' → Unix 秒；解析失败返回 0 */
    function parseTs(s) {
        s = String(s || '').replace(/^\s+|\s+$/g, '');
        var m = s.match(/^(\d{4})-(\d{1,2})-(\d{1,2})[ T](\d{1,2}):(\d{1,2})/);
        if (!m) return 0;
        var t = new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], 0, 0);
        return isNaN(t.getTime()) ? 0 : Math.floor(t.getTime() / 1000);
    }
    function brief(s, n) {
        s = String(s || '').replace(/\s+/g, ' ');
        return s.length > n ? s.slice(0, n) + '…' : s;
    }

    /* ---------- 自建弹窗 / 确认框（后台无 OwChat，与 user-manager 同款） ---------- */

    function modal(html, width) {
        var mask = d.createElement('div');
        mask.className = 'ow-modal-mask';
        mask.style.display = 'flex';
        mask.innerHTML = '<div class="ow-modal" style="max-width:' + (width || 520) + 'px">'
            + '<button class="ow-modal-close" type="button">✕</button>' + html + '</div>';
        d.body.appendChild(mask);
        function close() { if (mask.parentNode) d.body.removeChild(mask); }
        mask.querySelector('.ow-modal-close').onclick = close;
        mask.onclick = function (e) { if (e.target === mask) close(); };
        return { box: mask, close: close };
    }

    function confirm2(text, onOk) {
        var m = modal('<h3>确认操作</h3><p style="font-size:13px;color:var(--ow-text-sub)">' + esc(text) + '</p>'
            + '<div class="ow-cs-mf-btns"><button class="ow-btn ow-btn-primary" type="button" id="owCsCfOk">确定</button>'
            + '<button class="ow-btn" type="button" id="owCsCfNo">取消</button></div>', 420);
        $('owCsCfOk').onclick = function () { m.close(); onOk(); };
        $('owCsCfNo').onclick = m.close;
    }

    /* ---------- 数据 ---------- */

    var CS = { data: null, tab: 'snip' };
    w.OwCSAdmin = CS;

    function guardBoot() {
        if (booted) return;
        if (!$('owCsPane-snip')) return;          // 不是本插件的后台页
        booted = true;
        // 页签切换
        var tabs = d.querySelectorAll('.ow-cs-tab'), i;
        for (i = 0; i < tabs.length; i++) {
            tabs[i].onclick = function () {
                var t = this.getAttribute('data-tab');
                CS.tab = t;
                var all = d.querySelectorAll('.ow-cs-tab'), j, pane;
                for (j = 0; j < all.length; j++) {
                    if (all[j].getAttribute('data-tab') === t) all[j].className = 'ow-btn ow-cs-tab is-active';
                    else all[j].className = 'ow-btn ow-cs-tab';
                }
                var ids = ['snip', 'rule', 'cast', 'log'];
                for (j = 0; j < ids.length; j++) {
                    pane = $('owCsPane-' + ids[j]);
                    if (pane) pane.style.display = ids[j] === t ? '' : 'none';
                }
            };
        }
        // 公共话术搜索框（列表页内）
        var q = $('owCsSnipQ');
        if (q) q.oninput = function () { renderSnips(); };
        load();
    }

    function load() {
        OwApi.post(A + 'init', {}, function (r) {
            if (!r || !r.ok) {
                toast((r && r.msg) || '加载失败');
                return;
            }
            CS.data = r;
            renderSnips();
            renderBotInfo();
            renderRules();
            renderCasts();
            renderLogs();
        });
    }

    /* ---------- 快捷话术（公共） ---------- */

    function renderSnips() {
        var box = $('owCsSnips');
        if (!box || !CS.data) return;
        var kw = ($('owCsSnipQ').value || '').replace(/^\s+|\s+$/g, '').toLowerCase();
        var list = CS.data.snips || [], i, h = '';
        if (kw) {
            var out = [];
            for (i = 0; i < list.length; i++) {
                if ((list[i].title + ' ' + list[i].content).toLowerCase().indexOf(kw) >= 0) out.push(list[i]);
            }
            list = out;
        }
        if (!list.length) { box.innerHTML = '<p class="ow-admin-desc" style="margin:10px 0 0">' + (kw ? '没有匹配的话术' : '暂无公共话术，点「新增话术」添加') + '</p>'; return; }
        h = '<table class="ow-table"><tr><th>ID</th><th>标题</th><th>分组</th><th>内容</th><th>被使用</th><th>操作</th></tr>';
        for (i = 0; i < list.length; i++) {
            var s = list[i];
            h += '<tr><td>' + esc(s.id) + '</td><td>' + esc(s.title) + '</td><td>' + esc(s.group_name || '未分组') + '</td>'
                + '<td title="' + esc(s.content) + '">' + esc(brief(s.content, 50)) + '</td>'
                + '<td>' + esc(s.use_count || 0) + '</td>'
                + '<td><a href="javascript:;" onclick="OwCSAdmin.snipEdit(' + esc(s.id) + ')">编辑</a>'
                + ' <a href="javascript:;" onclick="OwCSAdmin.snipDel(' + esc(s.id) + ')">删除</a></td></tr>';
        }
        box.innerHTML = h + '</table>';
    }

    CS.snipEdit = function (id) {
        id = int(id, 0);
        var s = { title: '', group_name: '', content: '' };
        if (id > 0) {
            var list = CS.data.snips || [], i;
            for (i = 0; i < list.length; i++) if (int(list[i].id) === id) { s = list[i]; break; }
        }
        var m = modal('<h3>' + (id > 0 ? '编辑公共话术' : '新增公共话术') + '</h3>'
            + '<div class="ow-form-item"><label>标题</label><input class="ow-input" id="owCsSeTitle" maxlength="60" value="' + esc(s.title) + '" placeholder="不超过 60 字"></div>'
            + '<div class="ow-form-item"><label>分组</label><input class="ow-input" id="owCsSeGroup" maxlength="30" value="' + esc(s.group_name === '未分组' ? '' : s.group_name) + '" placeholder="留空为「未分组」"></div>'
            + '<div class="ow-form-item"><label>内容</label><textarea class="ow-input" id="owCsSeContent" rows="4" maxlength="2000" placeholder="不超过 2000 字（与单条消息上限一致）">' + esc(s.content) + '</textarea></div>'
            + '<div class="ow-cs-mf-btns"><button class="ow-btn ow-btn-primary" type="button" id="owCsSeOk">保存</button></div>', 520);
        $('owCsSeOk').onclick = function () {
            var title = $('owCsSeTitle').value.replace(/^\s+|\s+$/g, '');
            var group = $('owCsSeGroup').value.replace(/^\s+|\s+$/g, '');
            var content = $('owCsSeContent').value.replace(/^\s+|\s+$/g, '');
            if (!title || !content) { toast('标题和内容不能为空'); return; }
            OwApi.secure(F + 'admin_snip_save', { id: id, title: title, group: group, content: content }, function (r) {
                toast(r.msg || (r.ok ? '已保存' : '保存失败'));
                if (r.ok) { m.close(); load(); }
            });
        };
    };

    CS.snipDel = function (id) {
        confirm2('删除后全站用户都将看不到这条公共话术，确定删除吗？', function () {
            OwApi.secure(F + 'admin_snip_del', { id: id }, function (r) {
                toast(r.msg || (r.ok ? '已删除' : '删除失败'));
                if (r.ok) load();
            });
        });
    };

    /* ---------- 机器人设置 ---------- */

    function renderBotInfo() {
        var box = $('owCsBotInfo');
        if (!box || !CS.data) return;
        var bot = CS.data.bot;
        $('owCsBotUid').value = CS.data.cfg.bot_uid || '';
        $('owCsReplyLimit').value = CS.data.cfg.reply_limit || '';
        $('owCsCool').value = CS.data.cfg.reply_cooldown || '';
        if (!bot) {
            box.innerHTML = '<span style="color:var(--ow-text-sub)">尚未设置机器人账号 —— 自动回复与群发都不会工作。填写一个已注册用户的 ID（建议专用账号），保存后点「加入所有公开群」。</span>';
            return;
        }
        var n = 0, i, rooms = CS.data.rooms || [];
        for (i = 0; i < rooms.length; i++) if (rooms[i].bot_ok) n++;
        box.innerHTML = '当前机器人：<b>#' + esc(bot.id) + ' ' + esc(bot.nickname) + '</b>'
            + '（角色 ' + esc(bot.role) + '） · 可在 <b>' + n + '</b> 个群聊发言'
            + '<span style="color:var(--ow-text-sub)">（仅统计公开群聊；密码房需机器人本人是超级管理员）</span>';
    }

    CS.cfgSave = function () {
        OwApi.secure(F + 'admin_cfg_save', {
            bot_uid: $('owCsBotUid').value.replace(/^\s+|\s+$/g, ''),
            reply_limit: $('owCsReplyLimit').value,
            reply_cooldown: $('owCsCool').value
        }, function (r) {
            toast(r.msg || (r.ok ? '已保存' : '保存失败'));
            if (r.ok) load();
        });
    };

    CS.botJoin = function () {
        confirm2('将机器人账号加入所有公开群聊（已在群里的不会重复加入）。不公开群聊需要群主邀请，不会自动加入。', function () {
            OwApi.secure(F + 'admin_bot_join', {}, function (r) {
                toast(r.msg || (r.ok ? '已处理' : '操作失败'));
                if (r.ok) load();
            });
        });
    };

    /* ---------- 自动回复规则 ---------- */

    /** 房间下拉：机器人不能发言的房间标注出来（保存时服务端仍会复核） */
    function roomOptions(sel, cur) {
        var rooms = (CS.data && CS.data.rooms) || [], h = '<option value="0">全部群聊</option>', i;
        for (i = 0; i < rooms.length; i++) {
            var rm = rooms[i];
            h += '<option value="' + esc(rm.id) + '"' + (int(rm.id) === cur ? ' selected' : '') + '>'
                + esc(rm.name) + (rm.bot_ok ? '' : '（机器人不可发言）') + '</option>';
        }
        sel.innerHTML = h;
    }

    function renderRules() {
        var box = $('owCsRules');
        if (!box || !CS.data) return;
        var list = CS.data.rules || [];
        if (!list.length) { box.innerHTML = '<p class="ow-admin-desc" style="margin:0">暂无规则</p>'; return; }
        var h = '<table class="ow-table"><tr><th>ID</th><th>群聊</th><th>匹配</th><th>关键词</th><th>回复内容</th><th>命中</th><th>状态</th><th>操作</th></tr>', i;
        for (i = 0; i < list.length; i++) {
            var r = list[i], kws = [];
            try { kws = JSON.parse(r.keywords) || []; } catch (e) {}
            var roomName = '全部群聊', rooms = CS.data.rooms || [], j;
            for (j = 0; j < rooms.length; j++) if (int(rooms[j].id) === int(r.room_id)) roomName = rooms[j].name;
            h += '<tr><td>' + esc(r.id) + '</td><td>' + esc(roomName) + '</td>'
                + '<td>' + (r.match_mode === 'exact' ? '全等' : '包含') + '</td>'
                + '<td>' + esc(kws.join('、')) + '</td>'
                + '<td title="' + esc(r.reply) + '">' + esc(brief(r.reply, 40)) + '</td>'
                + '<td>' + esc(r.hit_count || 0) + '</td>'
                + '<td>' + (int(r.enabled) ? '启用' : '停用') + '</td>'
                + '<td><a href="javascript:;" onclick="OwCSAdmin.ruleEdit(' + esc(r.id) + ')">编辑</a>'
                + ' <a href="javascript:;" onclick="OwCSAdmin.ruleDel(' + esc(r.id) + ')">删除</a></td></tr>';
        }
        box.innerHTML = h + '</table>';
    }

    CS.ruleEdit = function (id) {
        id = int(id, 0);
        var r = { room_id: 0, keywords: '[]', match_mode: 'include', reply: '', enabled: 1 };
        if (id > 0) {
            var list = CS.data.rules || [], i;
            for (i = 0; i < list.length; i++) if (int(list[i].id) === id) { r = list[i]; break; }
        }
        var kws = [];
        try { kws = JSON.parse(r.keywords) || []; } catch (e) {}
        var m = modal('<h3>' + (id > 0 ? '编辑回复规则' : '新增回复规则') + '</h3>'
            + '<div class="ow-form-item"><label>适用群聊</label><select class="ow-input" id="owCsRrRoom"></select></div>'
            + '<div class="ow-form-item"><label>匹配方式</label><select class="ow-input" id="owCsRrMode">'
            + '<option value="include"' + (r.match_mode !== 'exact' ? ' selected' : '') + '>包含任一关键词即命中</option>'
            + '<option value="exact"' + (r.match_mode === 'exact' ? ' selected' : '') + '>整条消息全等才命中</option></select></div>'
            + '<div class="ow-form-item"><label>关键词（逗号分隔，最多 10 个，每个不超过 20 字）</label>'
            + '<input class="ow-input" id="owCsRrKw" maxlength="200" value="' + esc(kws.join(',')) + '" placeholder="如：多少钱,价格,报价"></div>'
            + '<div class="ow-form-item"><label>回复内容</label>'
            + '<textarea class="ow-input" id="owCsRrReply" rows="4" maxlength="2000" placeholder="机器人回复的内容，不超过 2000 字">' + esc(r.reply) + '</textarea></div>'
            + '<div class="ow-form-item"><label><input type="checkbox" id="owCsRrOn"' + (int(r.enabled) ? ' checked' : '') + '> 启用本规则</label></div>'
            + '<div class="ow-cs-mf-btns"><button class="ow-btn ow-btn-primary" type="button" id="owCsRrOk">保存</button></div>', 540);
        roomOptions($('owCsRrRoom'), int(r.room_id));
        $('owCsRrOk').onclick = function () {
            var kwv = $('owCsRrKw').value.split(/[,，]/), clean = [], k;
            for (k = 0; k < kwv.length; k++) {
                var s = kwv[k].replace(/^\s+|\s+$/g, '');
                if (s) clean.push(s);
                if (clean.length >= 10) break;
            }
            if (!clean.length) { toast('至少填写一个关键词'); return; }
            OwApi.secure(F + 'admin_rule_save', {
                id: id,
                room_id: $('owCsRrRoom').value,
                match_mode: $('owCsRrMode').value,
                keywords: JSON.stringify(clean),
                reply: $('owCsRrReply').value.replace(/^\s+|\s+$/g, ''),
                enabled: $('owCsRrOn').checked ? 1 : 0
            }, function (r2) {
                toast(r2.msg || (r2.ok ? '已保存' : '保存失败'));
                if (r2.ok) { m.close(); load(); }
            });
        };
    };

    CS.ruleDel = function (id) {
        confirm2('确定删除这条回复规则吗？', function () {
            OwApi.secure(F + 'admin_rule_del', { id: id }, function (r) {
                toast(r.msg || (r.ok ? '已删除' : '删除失败'));
                if (r.ok) load();
            });
        });
    };

    /* ---------- 定时群发任务 ---------- */

    function renderCasts() {
        var box = $('owCsCasts');
        if (!box || !CS.data) return;
        var list = CS.data.casts || [];
        if (!list.length) { box.innerHTML = '<p class="ow-admin-desc" style="margin:0">暂无群发任务</p>'; return; }
        var h = '<table class="ow-table"><tr><th>ID</th><th>群聊</th><th>内容</th><th>模式</th><th>下次执行</th><th>上次</th><th>次数</th><th>状态</th><th>操作</th></tr>', i;
        for (i = 0; i < list.length; i++) {
            var c = list[i];
            h += '<tr><td>' + esc(c.id) + '</td><td>' + esc(c.room_name || ('#' + c.room_id)) + '</td>'
                + '<td title="' + esc(c.content) + '">' + esc(brief(c.content, 40)) + '</td>'
                + '<td>' + (c.mode === 'once' ? '一次性' : '每 ' + Math.max(5, Math.round(int(c.interval_sec) / 60)) + ' 分钟') + '</td>'
                + '<td>' + (int(c.enabled) ? fmtTs(c.next_run_at) : '—') + '</td>'
                + '<td>' + fmtTs(c.last_run_at) + (c.last_status ? '<br><span style="color:var(--ow-text-sub)">' + esc(brief(c.last_status, 30)) + '</span>' : '') + '</td>'
                + '<td>' + esc(c.run_count || 0) + '</td>'
                + '<td>' + (int(c.enabled) ? '启用' : '停用') + '</td>'
                + '<td><a href="javascript:;" onclick="OwCSAdmin.castEdit(' + esc(c.id) + ')">编辑</a>'
                + ' <a href="javascript:;" onclick="OwCSAdmin.castDel(' + esc(c.id) + ')">删除</a></td></tr>';
        }
        box.innerHTML = h + '</table>';
    }

    CS.castEdit = function (id) {
        id = int(id, 0);
        var c = { room_id: 0, content: '', mode: 'loop', interval_sec: 3600, send_at: 0 };
        if (id > 0) {
            var list = CS.data.casts || [], i;
            for (i = 0; i < list.length; i++) if (int(list[i].id) === id) { c = list[i]; break; }
        }
        var m = modal('<h3>' + (id > 0 ? '编辑群发任务' : '新增群发任务') + '</h3>'
            + '<div class="ow-form-item"><label>目标群聊</label><select class="ow-input" id="owCsCeRoom"></select></div>'
            + '<div class="ow-form-item"><label>发送内容</label>'
            + '<textarea class="ow-input" id="owCsCeContent" rows="4" maxlength="2000" placeholder="到时间用机器人账号发送的内容，不超过 2000 字">' + esc(c.content) + '</textarea></div>'
            + '<div class="ow-form-item"><label>模式</label><select class="ow-input" id="owCsCeMode">'
            + '<option value="loop"' + (c.mode !== 'once' ? ' selected' : '') + '>周期循环</option>'
            + '<option value="once"' + (c.mode === 'once' ? ' selected' : '') + '>一次性定时</option></select></div>'
            + '<div class="ow-form-item" id="owCsCeLoopItem"><label>间隔（分钟，最短 5 分钟）</label>'
            + '<input class="ow-input" id="owCsCeInterval" type="number" min="5" max="10080" value="' + esc(Math.max(5, Math.round(int(c.interval_sec) / 60))) + '"></div>'
            + '<div class="ow-form-item" id="owCsCeOnceItem" style="display:none"><label>执行时间（格式 年-月-日 时:分，至少 30 秒以后）</label>'
            + '<input class="ow-input" id="owCsCeSendAt" value="' + esc(int(c.send_at) ? fmtTs(c.send_at) : '') + '" placeholder="如 2026-10-15 09:00"></div>'
            + '<div class="ow-cs-mf-btns"><button class="ow-btn ow-btn-primary" type="button" id="owCsCeOk">保存</button></div>', 540);
        roomOptions($('owCsCeRoom'), int(c.room_id));
        function syncMode() {
            var once = $('owCsCeMode').value === 'once';
            $('owCsCeLoopItem').style.display = once ? 'none' : '';
            $('owCsCeOnceItem').style.display = once ? '' : 'none';
        }
        $('owCsCeMode').onchange = syncMode;
        syncMode();
        $('owCsCeOk').onclick = function () {
            var mode = $('owCsCeMode').value;
            var payload = {
                id: id,
                room_id: $('owCsCeRoom').value,
                content: $('owCsCeContent').value.replace(/^\s+|\s+$/g, ''),
                mode: mode,
                interval_sec: int($('owCsCeInterval').value, 60) * 60,
                send_at: 0
            };
            if (mode === 'once') {
                payload.send_at = parseTs($('owCsCeSendAt').value);
                if (!payload.send_at) { toast('请按格式填写执行时间，如 2026-10-15 09:00'); return; }
            }
            if (!payload.content) { toast('请填写发送内容'); return; }
            OwApi.secure(F + 'admin_cast_save', payload, function (r) {
                toast(r.msg || (r.ok ? '已保存' : '保存失败'));
                if (r.ok) { m.close(); load(); }
            });
        };
    };

    CS.castDel = function (id) {
        confirm2('确定删除这个群发任务吗？已发送的消息不会撤回。', function () {
            OwApi.secure(F + 'admin_cast_del', { id: id }, function (r) {
                toast(r.msg || (r.ok ? '已删除' : '删除失败'));
                if (r.ok) load();
            });
        });
    };

    /* ---------- 发送日志 ---------- */

    function renderLogs() {
        var box = $('owCsLogs');
        if (!box || !CS.data) return;
        var list = CS.data.logs || [];
        if (!list.length) { box.innerHTML = '<p class="ow-admin-desc" style="margin:0">暂无日志</p>'; return; }
        var h = '<table class="ow-table"><tr><th>时间</th><th>类型</th><th>群聊</th><th>内容</th><th>结果</th></tr>', i;
        for (i = 0; i < list.length; i++) {
            var l = list[i];
            h += '<tr><td>' + fmtTs(l.created_at) + '</td>'
                + '<td>' + (l.kind === 'cast' ? '群发' : '自动回复') + '</td>'
                + '<td>' + esc(l.room_name || ('#' + l.room_id)) + '</td>'
                + '<td title="' + esc(l.content) + '">' + esc(brief(l.content, 40)) + '</td>'
                + '<td>' + (int(l.ok)
                    ? '<span style="color:var(--ow-green)">成功</span>'
                    : '<span style="color:var(--ow-red)">失败</span> <span style="color:var(--ow-text-sub)">' + esc(brief(l.msg, 40)) + '</span>') + '</td></tr>';
        }
        box.innerHTML = h + '</table>';
    }

    CS.logClear = function () {
        confirm2('确定清空全部发送日志吗？', function () {
            OwApi.secure(F + 'admin_log_clear', {}, function (r) {
                toast(r.msg || (r.ok ? '已清空' : '操作失败'));
                if (r.ok) load();
            });
        });
    };

    /* ---------- 启动：等后台页容器出现 ---------- */

    function watch() {
        if (w.MutationObserver) {
            var mo = new w.MutationObserver(function () { guardBoot(); });
            mo.observe(d.getElementById('owAdminMain') || d.body, { childList: true, subtree: true });
        } else if (w.setInterval) {
            w.setInterval(guardBoot, 500);   // 老浏览器兜底
        }
        guardBoot();
    }

    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', watch, false);
    else watch();
})(window, document);
