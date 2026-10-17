/**
 * chat-scripts · 群聊自动化 · 后台交互（v1.1.2）
 * 全局对象 OwCSAdmin。三个页签：公共话术 / 机器人账号 / 发送记录。
 *
 * ⚠️ v1.1.1 起后台**不再管规则与任务**：关键词自动回复、定时群发都跟着群走，
 *    入口在「群聊设置 → 自动化」，群主自管本群（每群最多 8 条）。原来那两个页签
 *    连同 admin_rule_* / admin_cast_* 四条路由一并删除 —— 界面上没了、接口还留着
 *    就是暗门。这里只负责「全站共享的那三样」：公共话术、机器人账号、发送记录。
 *
 * 两个列表都走全站通用的后台列表样式（与登录日志同一套轮子）：
 *   服务端分页 OwAdmin.uiPager / 批量选择 OwAdmin.uiBatchBar + batchSync /
 *   表格首列复选框（表头 32px 全选 + 行内 .xxChk）。
 *
 * ⚠️ 本文件随合并资源包在所有页面加载：前台聊天页里 OwCSAdmin 的方法一个都不会被调用
 *    （页面 HTML 只存在于后台），但 boot() 必须判容器存在，否则白等。
 *    插件页是点菜单后由 Ajax 塞进 #owAdminMain 的，用 MutationObserver 等容器出现
 *    （与 daily-checkin / level-trust 同一套路）。
 *
 * 依赖：OwApi（post / secure）、OwAdmin（uiPager / uiBatchBar / batchSync / batchIds /
 *      confirm）、toast、esc。新增/编辑话术的弹窗自建（.ow-modal-mask / .ow-modal，
 *      后台页没有 OwChat，与 user-manager 一致）。
 * 兼容老浏览器：纯 ES5，不用箭头函数 / let / fetch / 模板串。
 */
(function (w, d) {
    'use strict';

    var $ = function (id) { return d.getElementById(id); };
    var A = 'plugin_chat_scripts_admin_';
    var F = 'plugin_chat_scripts_';
    var logLoaded = false;      // 发送记录懒加载：没点过那个页签就不打接口

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
    function brief(s, n) {
        s = String(s || '').replace(/\s+/g, ' ');
        return s.length > n ? s.slice(0, n) + '…' : s;
    }

    /* ---------- 自建弹窗（后台无 OwChat，与 user-manager 同款） ---------- */

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

    /* ---------- 状态 ---------- */

    var CS = {
        data: null,                                            // init 回来的 cfg / bot / rooms
        tab: 'snip',
        snipRows: [],                                          // 当前页话术行（编辑弹窗用）
        snip: { q: '', page: 1, psize: 20, total: 0, pages: 1 },
        log:  { q: '', kind: '', ok: '', d1: '', d2: '', page: 1, psize: 20, total: 0, pages: 1 }
    };
    w.OwCSAdmin = CS;

    /* ---------- 启动 ---------- */

    function guardBoot() {
        /**
         * ⚠️ 「已初始化」标记挂在被注入的容器上，**不能**存成模块变量（原来是
         *    `var booted`）：后台是 SPA，点侧边栏同一个插件项会重新拉一次页面
         *    HTML —— 空表格覆盖掉已渲染内容，而模块变量跨注入存活、永远不会
         *    归位 → 二次进入时初始化被挡住，列表全空。标记随元素一起被替换，
         *    新 HTML 没有标记 → 重跑。与 attachment-manager 的 data-am-inited
         *    同一写法。
         */
        var pane = $('owCsPane-snip');
        if (!pane || pane.getAttribute('data-cs-inited') === '1') return;   // 不是本插件的后台页 / 已初始化
        pane.setAttribute('data-cs-inited', '1');

        /* 页内状态归位到「服务端刚渲染出来的样子」：默认公共话术页、发送记录未加载。
           不归位的话：二次进入时 CS.tab 还停在上次的「发送记录」，而 logLoaded 仍是
           true → 再点「发送记录」被 `!logLoaded` 挡住，表格永远空白。 */
        CS.tab = 'snip';
        logLoaded = false;

        var tabs = d.querySelectorAll('.ow-cs-tab'), i;
        for (i = 0; i < tabs.length; i++) {
            tabs[i].onclick = function () {
                var t = this.getAttribute('data-tab');
                CS.tab = t;
                var all = d.querySelectorAll('.ow-cs-tab'), j, pane;
                for (j = 0; j < all.length; j++) {
                    all[j].className = all[j].getAttribute('data-tab') === t ? 'ow-cs-tab is-active' : 'ow-cs-tab';
                }
                var ids = ['snip', 'bot', 'log'];
                for (j = 0; j < ids.length; j++) {
                    pane = $('owCsPane-' + ids[j]);
                    if (pane) pane.style.display = ids[j] === t ? '' : 'none';
                }
                // 发送记录懒加载：没进过这个页签就不打接口，省一次查询
                if (t === 'log' && !logLoaded) { logLoaded = true; CS.logLoad(1); }
            };
        }
        CS.snipBar();
        CS.logBar();
        CS.load();
        CS.snipLoad(1);
    }

    /** 后台基础数据（机器人设置 + 机器人可在哪些群发言）；两个列表各自分页拉取 */
    CS.load = function () {
        OwApi.post(A + 'init', {}, function (r) {
            if (!r || !r.ok) { toast((r && r.msg) || '加载失败'); return; }
            CS.data = r;
            CS.renderBot();
            CS.renderAi();
        });
    };

    /**
     * AI 应答配置（v1.1.2）。
     * ⚠️ 服务端**不下发密钥明文**（只给 key_set 与掩码），所以密钥输入框永远留空；
     *    「留空保存 = 不修改」这条规矩必须在这里也讲一遍，否则管理员会以为保存把密钥清掉了。
     */
    CS.renderAi = function () {
        if (!$('owCsAiOn')) return;                    // 还没渲染出 AI 那张卡
        var ai = (CS.data && CS.data.ai) || {};
        var on = $('owCsAiOn');
        on.checked = !!ai.on;
        /* v1.2.1：AI 开关换成核心的通用开关轮子后，轨道（.ow-switch 的 is-on 类）要跟着
           checkbox 走 —— 服务端渲染时还不知道状态（配置是 AJAX 拿的），所以在这一刻同步一次。
           轮子由核心导出（chat.js 挂在 window 上），与 attachment-manager / sensitive-words 同款。 */
        if (w.bindSwitches) w.bindSwitches(on);
        $('owCsAiTimeout').value = ai.timeout || 10;
        $('owCsAiDaily').value = ai.daily || 0;
        $('owCsAiCtx').value = ai.ctx || 0;
        $('owCsAiBase').value = ai.base || '';
        $('owCsAiModel').value = ai.model || '';
        $('owCsAiPrompt').value = ai.prompt || '';     // 空 = 用系统默认（保存时清掉即恢复）
        $('owCsAiKey').value = '';
        var k = $('owCsAiKeyInfo');
        if (k) {
            k.innerHTML = ai.key_set
                ? '已设置密钥：<code>' + esc(ai.key_mask || '') + '</code>　重新输入即覆盖，留空表示不修改。'
                : '<span style="color:var(--ow-text-sub)">尚未设置密钥 —— 少数本地接口不需要密钥，云端的一般都要。</span>';
        }
    };

    /** 试一次 AI 配置：不落库、不发到群里，只把模型回的话显示出来 */
    CS.aiTest = function () {
        var st = $('owCsAiStat'), qEl = $('owCsAiTestQ');
        if (!st) return;
        st.innerHTML = '正在调用…（最多等 ' + int($('owCsAiTimeout').value, 10) + ' 秒）';
        OwApi.post(A + 'ai_test', { q: qEl ? qEl.value : '' }, function (r) {
            if (!r || !r.ok) {
                st.innerHTML = '<span style="color:var(--ow-red)">' + esc((r && r.msg) || '调用失败') + '</span>';
                return;
            }
            st.innerHTML = '<span style="color:var(--ow-green)">' + esc(r.msg || '通了') + '</span><br>'
                + '<span style="color:var(--ow-text-sub)">' + esc(r.text || '') + '</span>';
        });
    };

    /* ---------- 机器人账号 ---------- */

    CS.renderBot = function () {
        var box = $('owCsBotInfo');
        if (!box || !CS.data) return;
        var bot = CS.data.bot, cfg = CS.data.cfg || {};
        $('owCsBotUid').value = cfg.bot_uid || '';
        $('owCsReplyLimit').value = cfg.reply_limit || '';
        $('owCsCool').value = cfg.reply_cooldown || '';
        if (!bot) {
            box.innerHTML = '<span style="color:var(--ow-text-sub)">尚未设置机器人账号 —— 自动回复与定时群发都不会工作。'
                + '填写一个已注册用户的 ID（建议专用账号），保存后点「加入所有公开群」。</span>';
            return;
        }
        var n = 0, i, rooms = CS.data.rooms || [];
        for (i = 0; i < rooms.length; i++) if (rooms[i].bot_ok) n++;
        box.innerHTML = '当前机器人：<b>#' + esc(bot.id) + ' ' + esc(bot.nickname) + '</b>'
            + '（角色 ' + esc(bot.role) + '） · 可在 <b>' + n + '</b> 个群聊发言'
            + '<span style="color:var(--ow-text-sub)">（仅统计公开群聊；密码房需机器人本人是超级管理员，角色群按等级判定）</span>';
    };

    CS.cfgSave = function () {
        OwApi.secure(F + 'admin_cfg_save', {
            bot_uid: ($('owCsBotUid').value || '').replace(/^\s+|\s+$/g, ''),
            reply_limit: $('owCsReplyLimit').value,
            reply_cooldown: $('owCsCool').value,
            // AI 应答（v1.1.2）：ai_key 留空 = 不修改，ai_prompt 清空 = 恢复默认
            ai_on: $('owCsAiOn').checked ? 1 : 0,
            ai_base: $('owCsAiBase').value,
            ai_key: $('owCsAiKey').value,
            ai_model: $('owCsAiModel').value,
            ai_prompt: $('owCsAiPrompt').value,
            ai_timeout: $('owCsAiTimeout').value,
            ai_daily: $('owCsAiDaily').value,
            ai_ctx: $('owCsAiCtx').value
        }, function (r) {
            toast(r.msg || (r.ok ? '已保存' : '保存失败'));
            if (r.ok) CS.load();
        });
    };

    CS.botJoin = function () {
        OwAdmin.confirm('将机器人账号加入所有公开群聊（已在群里的不会重复加入）。不公开群聊需要群主邀请，不会自动加入。', function () {
            OwApi.secure(F + 'admin_bot_join', {}, function (r) {
                toast(r.msg || (r.ok ? '已处理' : '操作失败'));
                if (r.ok) CS.load();
            });
        });
    };

    /* ============================ 公共话术 ============================ */

    CS.snipBar = function () {
        if (!w.OwAdmin || !OwAdmin.uiBatchBar) return;
        OwAdmin.uiBatchBar({
            id: 'owCsSnipBatch',
            chkCls: 'owCsSnipChk',
            statBase: '共 0 条',
            actions: [{ key: 'del', label: '批量删除所选话术', danger: true }],
            onExec: function (act, ids) { CS.snipDel(ids); }
        });
    };

    /** 全选 / 取消全选：只作用于当前页（跨页全选要服务端记选择态，成本远大于收益） */
    CS.snipToggleAll = function (cb) {
        var boxes = d.getElementsByClassName('owCsSnipChk');
        for (var i = 0; i < boxes.length; i++) boxes[i].checked = cb.checked;
        CS.snipSync();
    };

    CS.snipSync = function () {
        if (w.OwAdmin && OwAdmin.batchSync) {
            OwAdmin.batchSync('owCsSnipChk', 'owCsSnipBatch', '共 ' + CS.snip.total + ' 条');
        }
    };

    CS.snipLoad = function (page) {
        var qEl = $('owCsSnipQ');
        if (!qEl) return;
        CS.snip.q = qEl.value || '';
        CS.snip.page = page || 1;
        OwApi.post(A + 'snip_list', {
            q: CS.snip.q, page: CS.snip.page, psize: CS.snip.psize
        }, function (r) {
            if (!r || !r.ok) { toast((r && r.msg) || '加载失败'); return; }
            CS.snip.total = r.total;
            CS.snip.pages = r.pages;
            CS.snip.page = r.page || CS.snip.page;   // 删光本页后服务端把越界 page 收敛回来
            CS.renderSnips(r.data || []);
            OwAdmin.uiPager('owCsSnipPager', CS.snip.page, CS.snip.total, CS.snip.psize, function (pg) {
                CS.snipLoad(pg);
            });
        });
    };

    CS.snipReset = function () {
        $('owCsSnipQ').value = '';
        CS.snipLoad(1);
    };

    CS.renderSnips = function (rows) {
        var tbl = $('owCsSnipTable');
        if (!tbl) return;
        CS.snipRows = rows;        // 编辑弹窗从这里取现成的一行，省一次「按 id 查一条」的接口
        // 首列复选框与表头全选：与全站后台表格同一规格（th 宽 32px）
        var header = '<tr><th style="width:32px"><input type="checkbox" id="owCsSnipCheckAll" onchange="OwCSAdmin.snipToggleAll(this)"></th>'
            + '<th>ID</th><th>标题</th><th>分组</th><th>内容</th><th>被使用</th><th>操作</th></tr>';
        var h = '', i;
        for (i = 0; i < rows.length; i++) {
            var s = rows[i];
            h += '<tr>'
                + '<td><input type="checkbox" class="owCsSnipChk" value="' + esc(s.id) + '" onchange="OwCSAdmin.snipSync()"></td>'
                + '<td>' + esc(s.id) + '</td>'
                + '<td>' + esc(s.title) + '</td>'
                + '<td>' + esc(s.group_name || '未分组') + '</td>'
                + '<td title="' + esc(s.content) + '">' + esc(brief(s.content, 50)) + '</td>'
                + '<td>' + esc(s.use_count || 0) + '</td>'
                + '<td><a href="javascript:;" onclick="OwCSAdmin.snipEdit(' + esc(s.id) + ')">编辑</a>'
                + ' <a href="javascript:;" onclick="OwCSAdmin.snipDelOne(' + esc(s.id) + ')">删除</a></td>'
                + '</tr>';
        }
        if (!rows.length) {
            h = '<tr><td colspan="7" style="color:var(--ow-text-sub);text-align:center;padding:24px">'
                + (CS.snip.q ? '没有匹配的公共话术' : '暂无公共话术，点「新增话术」添加') + '</td></tr>';
        }
        tbl.innerHTML = header + h;
        var all = $('owCsSnipCheckAll');
        if (all) all.checked = false;      // 换页/刷新后全选态复位，避免误以为仍全选
        var st = $('owCsSnipStat');
        if (st) st.textContent = '第 ' + CS.snip.page + ' / ' + CS.snip.pages + ' 页 · 共 ' + CS.snip.total + ' 条';
        CS.snipSync();
    };

    /** 新增 / 编辑话术弹窗 */
    CS.snipEdit = function (id) {
        id = int(id, 0);
        var s = { title: '', group_name: '', content: '' };
        // 从当前页已渲染的数据里取现成的一行，省一次「按 id 查一条」的接口
        // ⚠️ 取的是 renderSnips 存下来的 snipRows，不是 CS.data.snips ——
        //    v1.1.1 起 init 不再一次返回全部话术（列表改服务端分页了）
        if (id > 0 && CS.snipRows) {
            var list = CS.snipRows, i;
            for (i = 0; i < list.length; i++) if (int(list[i].id) === id) { s = list[i]; break; }
        }
        var m = modal('<h3>' + (id > 0 ? '编辑公共话术' : '新增公共话术') + '</h3>'
            + '<div class="ow-form-item"><label>标题</label><input class="ow-input" id="owCsSeTitle" maxlength="60" value="' + esc(s.title) + '" placeholder="不超过 60 字"></div>'
            + '<div class="ow-form-item"><label>分组</label><input class="ow-input" id="owCsSeGroup" maxlength="30" value="' + esc(s.group_name === '未分组' ? '' : s.group_name) + '" placeholder="留空为「未分组」"></div>'
            + '<div class="ow-form-item"><label>内容</label><textarea class="ow-input" id="owCsSeContent" rows="4" maxlength="2000" placeholder="不超过 2000 字（与单条消息上限一致）">' + esc(s.content) + '</textarea></div>'
            + '<div class="ow-cs-mf-btns"><button class="ow-btn ow-btn-primary" type="button" id="owCsSeOk">保存</button></div>', 520);
        $('owCsSeOk').onclick = function () {
            var title = ($('owCsSeTitle').value || '').replace(/^\s+|\s+$/g, '');
            var group = ($('owCsSeGroup').value || '').replace(/^\s+|\s+$/g, '');
            var content = ($('owCsSeContent').value || '').replace(/^\s+|\s+$/g, '');
            if (!title || !content) { toast('标题和内容不能为空'); return; }
            OwApi.secure(F + 'admin_snip_save', { id: id, title: title, group: group, content: content }, function (r) {
                toast(r.msg || (r.ok ? '已保存' : '保存失败'));
                if (r.ok) { m.close(); CS.snipLoad(CS.snip.page); }
            });
        };
    };

    /** 批量删除所选（通用批量轮子回调） */
    CS.snipDel = function (ids) {
        OwAdmin.confirm('删除后全站用户都将看不到这 ' + ids.length + ' 条公共话术，确定删除吗？', function () {
            OwApi.secure(F + 'admin_snip_del', { ids: ids.join(',') }, function (r) {
                toast(r.msg || (r.ok ? '已删除' : '删除失败'));
                if (r.ok) CS.snipLoad(CS.snip.page);
            });
        });
    };

    /** 行内单条删除 */
    CS.snipDelOne = function (id) {
        OwAdmin.confirm('删除后全站用户都将看不到这条公共话术，确定删除吗？', function () {
            OwApi.secure(F + 'admin_snip_del', { id: id }, function (r) {
                toast(r.msg || (r.ok ? '已删除' : '删除失败'));
                if (r.ok) CS.snipLoad(CS.snip.page);
            });
        });
    };

    /* ============================ 发送记录 ============================ */

    CS.logBar = function () {
        if (!w.OwAdmin || !OwAdmin.uiBatchBar) return;
        OwAdmin.uiBatchBar({
            id: 'owCsLogBatch',
            chkCls: 'owCsLogChk',
            statBase: '共 0 条',
            actions: [{ key: 'del', label: '批量删除所选记录', danger: true }],
            onExec: function (act, ids) { CS.logDel(ids); }
        });
    };

    CS.logToggleAll = function (cb) {
        var boxes = d.getElementsByClassName('owCsLogChk');
        for (var i = 0; i < boxes.length; i++) boxes[i].checked = cb.checked;
        CS.logSync();
    };

    CS.logSync = function () {
        if (w.OwAdmin && OwAdmin.batchSync) {
            OwAdmin.batchSync('owCsLogChk', 'owCsLogBatch', '共 ' + CS.log.total + ' 条');
        }
    };

    CS.logLoad = function (page) {
        var qEl = $('owCsLogQ');
        if (!qEl) return;
        var kEl = $('owCsLogKind'), okEl = $('owCsLogOk');
        CS.log.q    = qEl.value || '';
        CS.log.kind = kEl ? kEl.value : '';
        CS.log.ok   = okEl ? okEl.value : '';
        CS.log.d1   = $('owCsLogD1') ? $('owCsLogD1').value : '';
        CS.log.d2   = $('owCsLogD2') ? $('owCsLogD2').value : '';
        CS.log.page = page || 1;
        OwApi.post(A + 'log_list', {
            q: CS.log.q, kind: CS.log.kind, ok: CS.log.ok, d1: CS.log.d1, d2: CS.log.d2,
            page: CS.log.page, psize: CS.log.psize
        }, function (r) {
            if (!r || !r.ok) { toast((r && r.msg) || '加载失败'); return; }
            CS.log.total = r.total;
            CS.log.pages = r.pages;
            CS.log.page = r.page || CS.log.page;
            CS.renderLogs(r.data || []);
            OwAdmin.uiPager('owCsLogPager', CS.log.page, CS.log.total, CS.log.psize, function (pg) {
                CS.logLoad(pg);
            });
        });
    };

    CS.logReset = function () {
        $('owCsLogQ').value = '';
        $('owCsLogKind').value = '';
        $('owCsLogOk').value = '';
        $('owCsLogD1').value = '';
        $('owCsLogD2').value = '';
        CS.logLoad(1);
    };

    CS.renderLogs = function (rows) {
        var tbl = $('owCsLogTable');
        if (!tbl) return;
        var header = '<tr><th style="width:32px"><input type="checkbox" id="owCsLogCheckAll" onchange="OwCSAdmin.logToggleAll(this)"></th>'
            + '<th>时间</th><th>类型</th><th>群聊</th><th>内容</th><th>结果</th></tr>';
        var h = '', i;
        for (i = 0; i < rows.length; i++) {
            var l = rows[i];
            h += '<tr>'
                + '<td><input type="checkbox" class="owCsLogChk" value="' + esc(l.id) + '" onchange="OwCSAdmin.logSync()"></td>'
                + '<td>' + fmtTs(l.created_at) + '</td>'
                + '<td>' + (l.kind === 'cast' ? '定时群发' : (l.kind === 'ai' ? 'AI 应答' : '自动回复')) + '</td>'
                + '<td>' + esc(l.room_name || ('#' + l.room_id)) + '</td>'
                + '<td title="' + esc(l.content) + '">' + esc(brief(l.content, 40)) + '</td>'
                + '<td>' + (int(l.ok)
                    ? '<span style="color:var(--ow-green)">成功</span>'
                    : '<span style="color:var(--ow-red)">失败</span> <span style="color:var(--ow-text-sub)">' + esc(brief(l.msg, 40)) + '</span>')
                + '</td></tr>';
        }
        if (!rows.length) {
            h = '<tr><td colspan="6" style="color:var(--ow-text-sub);text-align:center;padding:24px">暂无发送记录</td></tr>';
        }
        tbl.innerHTML = header + h;
        var all = $('owCsLogCheckAll');
        if (all) all.checked = false;
        var st = $('owCsLogStat');
        if (st) st.textContent = '第 ' + CS.log.page + ' / ' + CS.log.pages + ' 页 · 共 ' + CS.log.total + ' 条';
        CS.logSync();
    };

    CS.logDel = function (ids) {
        OwAdmin.confirm('确定删除选中的 ' + ids.length + ' 条发送记录？该操作不可恢复。', function () {
            OwApi.secure(F + 'admin_log_del', { ids: ids.join(',') }, function (r) {
                toast(r.msg || (r.ok ? '已删除' : '删除失败'));
                if (r.ok) CS.logLoad(CS.log.page);
            });
        });
    };

    CS.logClearAll = function () {
        OwAdmin.confirm('确定清空全部发送记录吗？该操作不可恢复，之后也没有任何自动发送的留痕。', function () {
            OwApi.secure(F + 'admin_log_clear', {}, function (r) {
                toast(r.msg || (r.ok ? '已清空' : '操作失败'));
                if (r.ok) CS.logLoad(1);
            });
        });
    };

    /* ---------- 等后台页容器出现 ---------- */

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
