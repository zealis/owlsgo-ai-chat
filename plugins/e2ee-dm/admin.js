/**
 * 端到端加密私聊 - 后台交互
 *
 *   - 功能状态保存（原样保留）
 *   - 登记的设备：通用列表（关键词搜索 + 多选批量 + 分页）
 *
 * 两条都是 document 级事件委托：后台页是 SPA，每次进入都会整体替换容器，
 * 绑在按钮上的 onclick 会随节点一起丢（本项目踩过不止一次）。
 * 列表的首次加载用 tryInit 轮询 + DOM 上的幂等标记（data-x-inited）——
 * 模块级变量在 SPA 重注入后仍然活着，会把「已经初始化过」误判为真。
 */
(function (w, d) {
    'use strict';
    var $ = function (id) { return d.getElementById(id); };
    var esc = w.esc || function (s) { return String(s == null ? '' : s); };
    var toast = w.toast || function () {};

    var st = { page: 1, size: 20, total: 0, q: '' };

    var OwEDAdmin = w.OwEDAdmin = {

        /* ---------- 功能状态 ---------- */
        saveMode: function () {
            var sel = d.querySelector('input[name="owedMode"]:checked');
            var mode = sel ? sel.value : 'off';
            w.OwApi.secure('plugin_e2ee_dm_setmode', { mode: mode }, function (r) {
                toast(r.msg || (r.ok ? '已保存' : '保存失败'));
            });
        },

        /* ---------- 设备列表 ---------- */
        load: function (page) {
            st.page = page || 1;
            st.q = (($('owEDQ') || {}).value || '').replace(/^\s+|\s+$/g, '');
            w.OwApi.post('plugin_e2ee_dm_dev_list', {
                page: st.page, size: st.size, q: st.q
            }, function (r) {
                var tb = $('owEDTable');
                if (!tb) return;
                if (!r.ok) { tb.innerHTML = '<tr><td class="owed-empty">加载失败：' + esc(r.msg || '') + '</td></tr>'; return; }
                var data = r.data || {};
                st.total = data.total | 0;
                OwEDAdmin.render(data.list || []);
                var pager = $('owEDPager');
                if (pager && w.OwAdmin && w.OwAdmin.uiPager) {
                    w.OwAdmin.uiPager(pager, st.page, st.total, st.size, function (pg) { OwEDAdmin.load(pg); });
                }
            });
        },

        render: function (rows) {
            var tb = $('owEDTable');
            if (!tb) return;
            var h = '';
            for (var i = 0; i < rows.length; i++) {
                var r = rows[i];
                var nick = esc(r.nickname || ('用户' + r.user_id));
                var opk = parseInt(r.opk_left, 10) || 0;
                var opkCell = opk > 0
                    ? '<span class="ow-tag ow-tag-green">' + opk + ' 枚</span>'
                    : '<span class="ow-tag ow-tag-red">已耗尽</span>';
                h += '<tr>'
                    + '<td><input type="checkbox" class="owEDChk" value="' + parseInt(r.id || 0, 10) + '"'
                    + ' onchange="OwEDAdmin.sync()"></td>'
                    + '<td>' + esc(r.id) + '</td>'
                    + '<td>' + nick + '<br><span class="owed-sub">#' + esc(r.user_id) + '</span></td>'
                    + '<td>' + esc(r.dev_name || '—') + '</td>'
                    + '<td><code title="' + esc(r.dev_id) + '">' + esc(String(r.dev_id).slice(0, 10)) + '…</code></td>'
                    + '<td>' + opkCell + '</td>'
                    + '<td>' + (r.created_at ? esc(new Date((r.created_at | 0) * 1000).toLocaleString()) : '—') + '</td>'
                    + '</tr>';
            }
            if (!rows.length) {
                h = '<tr><td colspan="7" class="owed-empty">'
                    + (st.q ? '没有匹配「' + esc(st.q) + '」的设备' : '还没有设备登记') + '</td></tr>';
            }
            tb.innerHTML = h;

            if (w.OwAdmin && w.OwAdmin.uiBatchBar) {
                w.OwAdmin.uiBatchBar({
                    id: 'owEDBatch',
                    chkCls: 'owEDChk',
                    actions: [
                        { key: 'delete', label: '注销所选设备', danger: true }
                    ],
                    statBase: '共 ' + st.total + ' 台',
                    onExec: function (op, ids) { OwEDAdmin.batch(op, ids); }
                });
            }
        },

        toggleAll: function (box) {
            var list = d.getElementsByClassName('owEDChk'), i;
            for (i = 0; i < list.length; i++) list[i].checked = !!box.checked;
            this.sync();
        },

        /** 批量计数同步：执行按钮的可用态与「已选 N 条」由核心统管 */
        sync: function () {
            if (w.OwAdmin && w.OwAdmin.batchSync) w.OwAdmin.batchSync('owEDChk', 'owEDBatch', '共 ' + st.total + ' 台');
        },

        reset: function () {
            if ($('owEDQ')) $('owEDQ').value = '';
            this.load(1);
        },

        batch: function (op, ids) {
            if (!ids.length) { toast('请先勾选设备'); return; }
            if (op !== 'delete') return;
            w.OwAdmin.confirm('注销 ' + ids.length + ' 台设备？\n'
                + '这些设备上的用户需要重新点一次「端到端加密」才能再用；'
                + '该设备此前加密的消息将永久无法读取（密钥只存在原设备上）。', function () {
                w.OwApi.secure('plugin_e2ee_dm_dev_batch', { op: op, ids: ids }, function (r) {
                    toast(r.msg || (r.ok ? '已注销' : '操作失败'));
                    if (r.ok) OwEDAdmin.load(st.page);
                });
            });
        }
    };

    /* ---------- 事件委托：重进页面多少次都有效 ---------- */
    d.addEventListener('click', function (e) {
        var t = e.target;
        if (!t) return;
        if (t.id === 'owedSaveMode') { OwEDAdmin.saveMode(); return; }
        if (t.id === 'owEDReset') { OwEDAdmin.reset(); return; }
    });

    /* ---------- 列表首载：SPA 重注入要能再跑一次 ---------- */
    var tryInit = function () {
        var box = $('owEDTable');
        // 容器还没出现（此时多半还在登录页 / 别的后台页）：必须**继续轮询**。
        // 早先这里是裸 return，等于整个插件包加载时只试了一次，之后再没跑过 ——
        // 表现就是「搜索能用、首屏永远空表」（只有用户手动触发 load 才会有数据）。
        if (!box) { setTimeout(tryInit, 300); return; }
        if (box.getAttribute('data-x-inited') === '1') return;
        box.setAttribute('data-x-inited', '1');
        OwEDAdmin.load(1);
        // 容器被整体替换（下一次进入本页）时标记会跟着新节点一起消失，
        // 这里重新观察一次，保证再进页面还能加载
        var mo = new w.MutationObserver(function () {
            var b2 = $('owEDTable');
            if (!b2) { mo.disconnect(); setTimeout(tryInit, 300); return; }
            if (b2.getAttribute('data-x-inited') !== '1') { b2.setAttribute('data-x-inited', '1'); OwEDAdmin.load(1); }
        });
        if (box.parentNode) mo.observe(box.parentNode, { childList: true });
    };
    tryInit();
})(window, document);
