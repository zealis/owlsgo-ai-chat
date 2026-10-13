/**
 * 垃圾信息控制插件 - 后台交互（v1.3.104）
 * 全局对象 OwSW；依赖 OwApi / esc / toast / OwAdmin.uiPager / OwAdmin.uiBatchBar / OwApi.secure
 *
 * 页面是五个标签共用一张卡片：标签只切换 .ow-spam-pane 的显隐，
 * 词库那张表的分页/批量轮子与原来完全一致（路由与表名都没改）。
 */
(function (w, d) {
    'use strict';
    var $ = function (id) { return d.getElementById(id); };

    var state = { page: 1, size: 30, total: 0 };

    /* 每个标签要提交哪些字段。字段 id 统一是 owSpam_<key>，
       白名单在插件后端（plugin_spam_save）也写死了一份 —— 这里只决定「带不带」，
       能写哪些键最终由后端说了算。 */
    var GROUPS = {
        flood:  ['rate_win', 'rate_max', 'min_gap'],
        domain: ['domain_on', 'domain_list', 'domain_warn'],
        ip:     ['ip_on', 'ip_mode', 'ip_list', 'ip_keep'],
        ai:     ['ai_on', 'ai_action', 'ai_timeout', 'ai_daily', 'ai_base', 'ai_key', 'ai_model', 'ai_prompt']
    };

    w.OwSW = {
        init: function () {
            w.OwAdmin.uiBatchBar({
                id: 'owSWBatch',
                chkCls: 'owSWChk',
                statBase: '共 0 条敏感词',
                actions: [{ key: 'del', label: '批量删除所选敏感词', danger: true }],
                onExec: function (act, ids) { w.OwSW.batchDelete(ids); }
            });
            this.bindTabs();
            this.list(1);
        },

        /* ---------- 标签切换 ---------- */
        bindTabs: function () {
            var bar = $('owSpamTabs');
            if (!bar || bar._bound) return;
            bar._bound = true;
            // 事件委托：标签是后台页 innerHTML 注入的，逐个绑 onclick 会在重绘后失效
            bar.onclick = function (e) {
                var t = e.target;
                while (t && t !== bar && !(t.getAttribute && t.getAttribute('data-tab'))) t = t.parentNode;
                if (t && t !== bar) w.OwSW.tab(t.getAttribute('data-tab'));
            };
        },

        tab: function (name) {
            var i, els = d.querySelectorAll('.ow-spam-tab');
            for (i = 0; i < els.length; i++) els[i].className = 'ow-spam-tab' + (els[i].getAttribute('data-tab') === name ? ' active' : '');
            var panes = d.querySelectorAll('.ow-spam-pane');
            for (i = 0; i < panes.length; i++) {
                panes[i].style.display = panes[i].getAttribute('data-pane') === name ? '' : 'none';
            }
        },

        /* ---------- 分组保存 ---------- */
        save: function (group) {
            var keys = GROUPS[group];
            if (!keys) { toast('未知的设置分组'); return; }
            var data = { group: group };
            for (var i = 0; i < keys.length; i++) {
                var k = keys[i], el = $('owSpam_' + k);
                if (!el) continue;
                if (el.type === 'checkbox') data[k] = el.checked ? '1' : '0';
                else data[k] = el.value;
            }
            if (group === 'ai' && data.ai_key === '') delete data.ai_key;   // 空 = 不改动已存的密钥
            OwApi.secure('plugin_spam_save', data, function (r) {
                toast(r && r.msg ? r.msg : '保存失败');
                // IP 规则被「防自锁」拦下时不刷新页面：管理员往往要就地改名单再存一次
                if (r && r.ok && group === 'ip') w.OwSW.reloadSoon();
            });
        },

        reloadSoon: function () {
            setTimeout(function () { location.reload(); }, 700);
        },

        /* ---------- AI 测试 ---------- */
        aiTest: function () {
            var box = $('owSpamAiStat'), t = $('owSpamTest');
            if (!box || !t) return;
            if (!t.value.trim()) { toast('请先输入一段测试文本'); return; }
            box.textContent = '判定中…（要等接口回话，最多几秒）';
            OwApi.post('plugin_spam_ai_test', { text: t.value }, function (r) {
                if (!r || !r.ok) { box.textContent = '测试失败：' + ((r && r.msg) || '接口无响应'); return; }
                if (r.verdict === 'spam') box.textContent = '判定：垃圾信息' + (r.why ? '（' + r.why + '）' : '') + '　原始返回：' + r.raw;
                else if (r.verdict === 'clean') box.textContent = '判定：正常' + (r.why ? '（' + r.why + '）' : '');
                else box.textContent = '判定失败（消息照常放行）：' + r.raw + (r.quota_left === 'exhausted' ? '　※ 今日调用上限已用完' : '');
            });
        },

        /* ---------- 以下词库部分与 v1.0.110 相同：路由、表、轮子都没动 ---------- */
        list: function (page) {
            OwApi.post('plugin_sensitive_words_admin', { page: page || 1, size: state.size }, function (r) {
                var table = $('owSWTable'), pager = $('owSWPager');
                if (!table || !r.ok) { if (table) table.innerHTML = '<tr><td style="color:var(--ow-text-sub)">加载失败</td></tr>'; return; }
                state.total = r.data.total;
                state.page = r.data.page;
                OwSW.render(r.data.list);
                w.OwAdmin.uiPager(pager, state.page, state.total, state.size, function (pg) { w.OwSW.list(pg); });
                w.OwAdmin.batchSync('owSWChk', 'owSWBatch', '共 ' + state.total + ' 条敏感词');
            });
        },

        render: function (rows) {
            var table = $('owSWTable');
            var h = '<tr><th style="width:32px"><input type="checkbox" id="owSWCheckAll" onchange="OwSW.toggleAll(this)"></th>'
                + '<th>ID</th><th>敏感词</th><th>替换为</th><th>状态</th><th>操作</th></tr>';
            for (var i = 0; i < rows.length; i++) {
                var wd = rows[i];
                h += '<tr><td><input type="checkbox" class="owSWChk" value="' + wd.id + '" onchange="OwSW.syncBatch()"></td>'
                    + '<td>' + wd.id + '</td><td>' + esc(wd.word) + '</td><td>' + esc(wd.replacement) + '</td>'
                    + '<td>' + (wd.enabled == 1 ? '<span class="ow-tag ow-tag-green">启用</span>' : '<span class="ow-tag ow-tag-guest">停用</span>') + '</td>'
                    + '<td><a href="javascript:;" onclick="OwSW.wordToggle(' + wd.id + ',' + (wd.enabled == 1 ? 0 : 1) + ')">' + (wd.enabled == 1 ? '停用' : '启用') + '</a> '
                    + '<a href="javascript:;" onclick="OwSW.wordDel(' + wd.id + ')">删除</a></td></tr>';
            }
            if (!rows.length) h += '<tr><td colspan="6" style="color:var(--ow-text-sub)">词库为空</td></tr>';
            table.innerHTML = h;
            var all = $('owSWCheckAll');
            if (all) all.checked = false;
            OwSW.syncBatch();
        },

        toggleAll: function (cb) {
            var boxes = d.getElementsByClassName('owSWChk');
            for (var i = 0; i < boxes.length; i++) boxes[i].checked = cb.checked;
            OwSW.syncBatch();
        },

        syncBatch: function () {
            w.OwAdmin.batchSync('owSWChk', 'owSWBatch', '共 ' + state.total + ' 条敏感词');
        },

        batchDelete: function (ids) {
            if (!ids || !ids.length) { toast('请先选择要删除的敏感词'); return; }
            w.OwAdmin.confirm('确认删除选中的 ' + ids.length + ' 条敏感词？', function () {
                OwApi.secure('plugin_sensitive_words_batch', { ids: ids.join(',') }, function (r) {
                    toast(r.msg);
                    if (r.ok) w.OwSW.list(state.page);
                });
            });
        },

        wordAdd: function () {
            OwApi.post('plugin_sensitive_words_add', { word: $('owWWord').value, replacement: $('owWRep').value }, function (r) {
                toast(r.msg);
                if (r.ok) w.OwSW.list(1);
            });
        },
        wordToggle: function (id, en) {
            OwApi.post('plugin_sensitive_words_toggle', { id: id, enabled: en }, function (r) { toast(r.msg); if (r.ok) w.OwSW.list(state.page); });
        },
        wordDel: function (id) {
            w.OwAdmin.confirm('确定删除该敏感词？', function () {
                OwApi.secure('plugin_sensitive_words_del', { id: id }, function (r) {
                    toast(r.msg);
                    if (r.ok) w.OwSW.list(state.page);
                });
            });
        }
    };

    /* 后台页 HTML 由 innerHTML 注入（script 不执行）：MutationObserver 检测表格出现后自动加载 */
    var mo = new MutationObserver(function () {
        var t = $('owSWTable');
        // ⚠️ 必须用标记防重入，不能只判「表格是空的」：
        // init() 里的 OwAdmin.uiBatchBar 会同步写 #owSWBatch 的 innerHTML，
        // 这次改动立刻再次触发本回调；而此时列表 XHR 还没回来、表格仍是 0 行，
        // 于是 init 被无限递归调用（每轮还发一个请求），表现就是一进页面浏览器直接卡死。
        // 标记挂在表格元素上：切走再切回时表格是新元素，仍能重新初始化。
        if (t && !t._inited) { t._inited = true; w.OwSW.init(); }
    });
    mo.observe(d.documentElement, { childList: true, subtree: true });
})(window, document);
