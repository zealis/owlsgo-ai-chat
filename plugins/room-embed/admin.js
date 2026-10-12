/**
 * 群聊嵌入插件 - 后台交互（v1.2.0）
 * 全局对象 OwRE；依赖主 chat.js 暴露的 OwApi / toast / esc / OwAdmin.confirm。
 * 只在后台运行（OwAdmin 存在才初始化）。
 * 后台页 HTML 由 admin_plugin_page 异步注入，仿 attachment-manager 的套路：
 * MutationObserver 盯 #owAdminMain，见到 #owREList 且未初始化过才加载一次。
 */
(function (w, d) {
    'use strict';
    if (!w.OwAdmin) return;
    if (w.__owRoomEmbedAdminLoaded) return;
    w.__owRoomEmbedAdminLoaded = true;

    function $(id) { return d.getElementById(id); }
    function fmtTs(ts) { return ts > 0 ? new Date(ts * 1000).toLocaleString() : '—'; }

    w.OwRE = {
        /* 令牌列表 */
        list: function () {
            OwApi.post('plugin_room_embed_token_list', {}, function (r) {
                if (!r.ok) { toast(r.msg); return; }
                var now = Math.floor(new Date().getTime() / 1000);
                var h = '<div class="ow-card"><table class="ow-table"><tr><th>ID</th><th>房间</th><th>令牌</th><th>签发人</th>'
                    + '<th>签发时间</th><th>过期时间</th><th>最近使用</th><th>状态</th><th>操作</th></tr>';
                for (var i = 0; i < r.data.length; i++) {
                    var t = r.data[i];
                    var st = t.revoked ? '已吊销' : (t.expires_at < now ? '已过期' : '有效');
                    h += '<tr><td>' + t.id + '</td>'
                        + '<td>' + esc(t.room_name) + '（#' + t.room_id + '）</td>'
                        + '<td><code>' + esc(t.token_prefix) + '…</code></td>'
                        + '<td>' + esc(t.created_by_name) + '</td>'
                        + '<td>' + fmtTs(t.created_at) + '</td>'
                        + '<td>' + fmtTs(t.expires_at) + '</td>'
                        + '<td>' + fmtTs(t.last_used_at) + '</td>'
                        + '<td>' + st + '</td>'
                        + '<td>' + (t.revoked ? '—' : '<a href="javascript:;" onclick="OwRE.revoke(' + t.id + ')">吊销</a>') + '</td></tr>';
                }
                if (!r.data.length) h += '<tr><td colspan="9" style="color:var(--ow-text-sub)">暂无嵌入令牌</td></tr>';
                var box = $('owREList');
                if (box) box.innerHTML = h + '</table></div>';
            });
        },

        /* 吊销 */
        revoke: function (id) {
            w.OwAdmin.confirm('吊销后，使用该令牌的嵌入代码立即失效，对应网站的聊天窗将无法连接。确定吊销？', function () {
                OwApi.secure('plugin_room_embed_token_revoke', { id: id }, function (r) {
                    toast(r.msg);
                    if (r.ok) w.OwRE.list();
                });
            });
        }
    };

    /* 后台页异步注入：见到 #owREList 且未初始化过才加载一次 */
    function watchInit() {
        var main = $('owAdminMain');
        if (!main) return;
        var tryInit = function () {
            var el = $('owREList');
            if (el && el.getAttribute('data-re-inited') !== '1') {
                el.setAttribute('data-re-inited', '1');
                w.OwRE.list();
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
