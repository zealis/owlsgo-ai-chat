/**
 * 群聊嵌入插件 - 后台交互（v2.0.0）
 * 全局对象 OwRE / OwRS；依赖主 chat.js 暴露的 OwApi / toast / esc / OwAdmin.confirm / OwAdmin.uiPager。
 * 只在后台运行（OwAdmin 存在才初始化）。
 * OwRE = 嵌入令牌管理（通用列表轮子：搜索 + 状态筛选 + 批量吊销 + 分页）；
 * OwRS = 登录会话管理（v2.0.0 弹窗授权换来的 30 天会话，列表 + 单个/批量吊销）。
 * 后台页 HTML 由 admin_plugin_page 异步注入，仿 attachment-manager 的套路：
 * MutationObserver 盯 #owAdminMain，见到目标表格且未初始化过才加载一次。
 */
(function (w, d) {
    'use strict';
    if (!w.OwAdmin) return;
    if (w.__owRoomEmbedAdminLoaded) return;
    w.__owRoomEmbedAdminLoaded = true;

    function $(id) { return d.getElementById(id); }
    function fmtTs(ts) { return ts > 0 ? new Date(ts * 1000).toLocaleString() : '—'; }

    /* 当前查询状态 */
    var state = { q: '', st: '', page: 1, psize: 15, total: 0, pages: 1 };

    w.OwRE = {
        /* 加载列表 */
        load: function (page) {
            var qEl = $('owREQ'), stEl = $('owREState');
            if (!qEl || !stEl) return;
            state.q = qEl.value;
            state.st = stEl.value;
            state.page = page || 1;
            OwApi.post('plugin_room_embed_token_list', {
                q: state.q, state: state.st,
                page: state.page, psize: state.psize
            }, function (r) {
                if (!r || !r.ok) { toast((r && r.msg) || '加载失败'); return; }
                state.total = r.total || 0;
                state.pages = r.pages || 1;
                w.OwRE.render(r.data || []);
                w.OwRE.renderPager();
            });
        },

        /* 重置筛选条件 */
        reset: function () {
            $('owREQ').value = '';
            $('owREState').value = '';
            w.OwRE.load(1);
        },

        /* 渲染列表（表头写死，一次性替换整表，避免 live collection 问题） */
        render: function (rows) {
            var tbl = $('owRETable');
            if (!tbl) return;
            var header = '<tr><th style="width:32px"><input type="checkbox" id="owRECheckAll" onchange="OwRE.toggleAll(this)"></th>'
                + '<th>ID</th><th>房间</th><th>令牌</th><th>域名白名单</th><th>签发人</th>'
                + '<th>签发时间</th><th>最近使用</th><th>状态</th><th>操作</th></tr>';
            var h = '';
            for (var i = 0; i < rows.length; i++) {
                var t = rows[i];
                var st = t.revoked ? '已吊销' : (t.expired ? '已过期' : '有效');
                var stColor = t.revoked || t.expired ? 'var(--ow-text-sub)' : 'var(--ow-green)';
                var doms = t.domains && t.domains.length
                    ? '<span title="' + esc(t.domains.join('\n')) + '">' + esc(t.domains[0]) + (t.domains.length > 1 ? ' 等 ' + t.domains.length + ' 个' : '') + '</span>'
                    : '<span style="color:var(--ow-text-sub)">不限制</span>';
                h += '<tr>'
                    + '<td><input type="checkbox" class="owREChk" value="' + t.id + '" onchange="OwRE.updateBatch()"></td>'
                    + '<td>' + t.id + '</td>'
                    + '<td>' + esc(t.room_name) + '（#' + t.room_id + '）</td>'
                    + '<td><code>' + esc(t.token_prefix) + '…</code></td>'
                    + '<td>' + doms + '</td>'
                    + '<td>' + esc(t.created_by_name) + '</td>'
                    + '<td>' + fmtTs(t.created_at) + '</td>'
                    + '<td>' + fmtTs(t.last_used_at) + '</td>'
                    + '<td style="color:' + stColor + '">' + st + '</td>'
                    + '<td>' + (t.revoked ? '—' : '<a href="javascript:;" onclick="OwRE.revoke(' + t.id + ')">吊销</a>') + '</td>'
                    + '</tr>';
            }
            if (!rows.length) {
                h = '<tr><td colspan="10" style="color:var(--ow-text-sub);text-align:center;padding:24px">暂无令牌记录</td></tr>';
            }
            tbl.innerHTML = header + h;
            w.OwRE.updateBatch();
            $('owREStat').innerHTML = '第 ' + state.page + ' / ' + state.pages + ' 页';
        },

        /* 全选 / 取消全选 */
        toggleAll: function (cb) {
            var boxes = d.getElementsByClassName('owREChk');
            for (var i = 0; i < boxes.length; i++) boxes[i].checked = cb.checked;
            w.OwRE.updateBatch();
        },

        /* 更新批量吊销按钮状态 */
        updateBatch: function () {
            var boxes = d.getElementsByClassName('owREChk');
            var n = 0;
            for (var i = 0; i < boxes.length; i++) if (boxes[i].checked) n++;
            var btn = $('owREBatchRevoke');
            if (btn) {
                btn.disabled = n === 0;
                btn.innerHTML = n > 0 ? '批量吊销（' + n + '）' : '批量吊销';
            }
        },

        /* 单个吊销 */
        revoke: function (id) {
            w.OwAdmin.confirm('吊销后，使用该令牌的嵌入代码立即失效，对应网站的聊天窗将无法连接。确定吊销？', function () {
                OwApi.secure('plugin_room_embed_token_revoke', { ids: String(id) }, function (r) {
                    toast(r.msg);
                    if (r.ok) w.OwRE.load(state.page);
                });
            });
        },

        /* 批量吊销 */
        batchRevoke: function () {
            var boxes = d.getElementsByClassName('owREChk');
            var ids = [];
            for (var i = 0; i < boxes.length; i++) if (boxes[i].checked) ids.push(boxes[i].value);
            if (!ids.length) { toast('请先选择要吊销的令牌'); return; }
            w.OwAdmin.confirm('确定吊销选中的 ' + ids.length + ' 个令牌？吊销后对应嵌入代码立即失效。', function () {
                OwApi.secure('plugin_room_embed_token_revoke', { ids: ids.join(',') }, function (r) {
                    toast(r.msg);
                    if (r.ok) w.OwRE.load(1);
                });
            });
        },

        /* 分页：复用主程序 OwAdmin.uiPager */
        renderPager: function () {
            w.OwAdmin.uiPager('owREPager', state.page, state.total, state.psize, function (pg) {
                w.OwRE.load(pg);
            });
        }
    };

    /* ===================== 登录会话（v2.0.0，OwRS） =====================
       弹窗授权换来的 30 天会话。列表 + 单个/批量吊销；不分页（上限 200 条，
       超了说明该清理，轮子里的分页对「会话」这种低频管理对象是过度设计）。 */
    w.OwRS = {
        load: function () {
            OwApi.post('plugin_room_embed_sess_list', {}, function (r) {
                if (!r || !r.ok) { toast((r && r.msg) || '加载失败'); return; }
                w.OwRS.render(r.data || []);
            });
        },

        render: function (rows) {
            var tbl = $('owRSTable');
            if (!tbl) return;
            var header = '<tr><th style="width:32px"><input type="checkbox" onchange="OwRS.toggleAll(this)"></th>'
                + '<th>ID</th><th>用户</th><th>房间</th><th>会话</th>'
                + '<th>签发时间</th><th>最近使用</th><th>有效期至</th><th>状态</th><th>操作</th></tr>';
            var h = '';
            for (var i = 0; i < rows.length; i++) {
                var s = rows[i];
                var st = s.revoked ? '已吊销' : (s.expired ? '已过期' : '有效');
                var stColor = s.revoked || s.expired ? 'var(--ow-text-sub)' : 'var(--ow-green)';
                h += '<tr>'
                    + '<td><input type="checkbox" class="owRSChk" value="' + s.id + '" onchange="OwRS.updateBatch()"></td>'
                    + '<td>' + s.id + '</td>'
                    + '<td>' + esc(s.nickname) + '（#' + s.user_id + '）</td>'
                    + '<td>' + esc(s.room_name) + '（#' + s.room_id + '）</td>'
                    + '<td><code>' + esc(s.sess_prefix) + '…</code></td>'
                    + '<td>' + fmtTs(s.created_at) + '</td>'
                    + '<td>' + fmtTs(s.last_used_at) + '</td>'
                    + '<td>' + fmtTs(s.expires_at) + '</td>'
                    + '<td style="color:' + stColor + '">' + st + '</td>'
                    + '<td>' + (s.revoked ? '—' : '<a href="javascript:;" onclick="OwRS.revoke(' + s.id + ')">吊销</a>') + '</td>'
                    + '</tr>';
            }
            if (!rows.length) {
                h = '<tr><td colspan="10" style="color:var(--ow-text-sub);text-align:center;padding:24px">暂无登录会话</td></tr>';
            }
            tbl.innerHTML = header + h;
            w.OwRS.updateBatch();
            var stat = $('owRSStat');
            if (stat) stat.innerHTML = '共 ' + rows.length + ' 条';
        },

        toggleAll: function (cb) {
            var boxes = d.getElementsByClassName('owRSChk');
            for (var i = 0; i < boxes.length; i++) boxes[i].checked = cb.checked;
            w.OwRS.updateBatch();
        },

        updateBatch: function () {
            var boxes = d.getElementsByClassName('owRSChk');
            var n = 0;
            for (var i = 0; i < boxes.length; i++) if (boxes[i].checked) n++;
            var btn = $('owRSBatchRevoke');
            if (btn) {
                btn.disabled = n === 0;
                btn.innerHTML = n > 0 ? '批量吊销（' + n + '）' : '批量吊销';
            }
        },

        revoke: function (id) {
            w.OwAdmin.confirm('吊销后，对应嵌入窗口在下次请求时退回访客身份（用户重新登录即可恢复）。确定吊销？', function () {
                OwApi.secure('plugin_room_embed_sess_revoke', { ids: String(id) }, function (r) {
                    toast(r.msg);
                    if (r.ok) w.OwRS.load();
                });
            });
        },

        batchRevoke: function () {
            var boxes = d.getElementsByClassName('owRSChk');
            var ids = [];
            for (var i = 0; i < boxes.length; i++) if (boxes[i].checked) ids.push(boxes[i].value);
            if (!ids.length) { toast('请先选择要吊销的会话'); return; }
            w.OwAdmin.confirm('确定吊销选中的 ' + ids.length + ' 个登录会话？对应嵌入窗口将退回访客身份。', function () {
                OwApi.secure('plugin_room_embed_sess_revoke', { ids: ids.join(',') }, function (r) {
                    toast(r.msg);
                    if (r.ok) w.OwRS.load();
                });
            });
        }
    };

    /* 后台页异步注入：见到 #owRETable / #owRSTable 且未初始化过才加载一次 */
    function watchInit() {
        var main = $('owAdminMain');
        if (!main) return;
        var tryInit = function () {
            var el = $('owRETable');
            if (el && el.getAttribute('data-re-inited') !== '1') {
                el.setAttribute('data-re-inited', '1');
                w.OwRE.load(1);
            }
            var el2 = $('owRSTable');
            if (el2 && el2.getAttribute('data-rs-inited') !== '1') {
                el2.setAttribute('data-rs-inited', '1');
                w.OwRS.load();
            }
        };
        if (typeof MutationObserver !== 'undefined') {
            var ob = new MutationObserver(tryInit);
            ob.observe(main, { childList: true, subtree: true });
        } else {
            setInterval(tryInit, 300);
        }
        tryInit();
    }
    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', watchInit);
    } else {
        watchInit();
    }
})(window, document);
