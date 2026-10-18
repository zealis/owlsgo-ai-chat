/**
 * 红包插件 · 后台（red-packet v1.0.0）
 *
 * 后台页 HTML 由 main.php 的 Plugin::adminPage 产出，本脚本负责：
 *   · 规则表单保存（单笔上限 / 个数上限 / 有效期）；
 *   · 30 天概览数字；
 *   · 最近 50 条发放记录。
 *
 * 后台页是经 innerHTML 注入的，内联 <script> 不会执行 —— 所有交互都在这里，
 * 初始化靠监听 #owAdminMain 的变化 + 在 DOM 上打标记（不能只用模块变量：
 * 页面二次进入会整体替换容器，模块变量还活着 → 列表永远空白）。
 * 兼容老浏览器：仅 var / function。
 */
(function (w, d) {
    'use strict';
    if (w.__owRpAdminLoaded) return;
    w.__owRpAdminLoaded = 1;

    var $ = function (id) { return d.getElementById(id); };

    w.OwRPAdmin = {
        /** 保存规则 */
        save: function () {
            var mt = $('owRpMaxTotal'), mc = $('owRpMaxCnt'), eh = $('owRpExpire');
            if (!mt || !mc || !eh) return;
            OwApi.post('plugin_red_packet_admin_save', {
                max_total: parseInt(mt.value, 10) || 100000,
                max_count: parseInt(mc.value, 10) || 100,
                expire_hours: parseInt(eh.value, 10) || 24
            }, function (r) {
                toast((r && r.msg) || (r && r.ok ? '已保存' : '保存失败'));
            });
        },

        /** 拉取概览 + 最近发放 */
        load: function () {
            OwApi.post('plugin_red_packet_admin_list', {}, function (r) {
                if (!r || !r.ok) {
                    var box = $('owRpAdminList');
                    if (box) box.innerHTML = '<div class="ow-rp-loading">' + esc((r && r.msg) || '加载失败') + '</div>';
                    return;
                }
                OwRPAdmin.renderKpi(r.kpi || {}, r.points_name || '积分');
                OwRPAdmin.renderList(r.rows || [], r.points_name || '积分');
            });
        },

        renderKpi: function (kpi, pn) {
            var box = $('owRpKpi');
            if (!box) return;
            var items = box.getElementsByTagName('b');
            if (items.length < 4) return;
            items[0].textContent = kpi.cnt30 || 0;
            items[1].textContent = (kpi.sum30 || 0) + ' ' + pn;
            items[2].textContent = kpi.get30 || 0;
            items[3].textContent = (kpi.amt30 || 0) + ' ' + pn;
        },

        renderList: function (rows, pn) {
            var box = $('owRpAdminList');
            if (!box) return;
            if (!rows.length) {
                box.innerHTML = '<div class="ow-rp-loading">还没有红包记录</div>';
                return;
            }
            var h = '<table class="ow-table"><thead><tr>'
                  + '<th style="width:52px">ID</th><th>发送者</th><th>类型</th>'
                  + '<th>总额</th><th>进度</th><th>状态</th><th>时间</th>'
                  + '</tr></thead><tbody>';
            for (var i = 0; i < rows.length; i++) {
                var r = rows[i];
                h += '<tr>'
                   + '<td>' + r.id + '</td>'
                   + '<td>' + esc(r.sender) + ' <span style="color:var(--ow-text-sub)">#' + r.sender_id + '</span></td>'
                   + '<td>' + esc(r.kind_name) + '</td>'
                   + '<td>' + (r.total > 0 ? r.total + ' ' + esc(pn) : '—') + '</td>'
                   + '<td>' + (r.cnt - r.left) + ' / ' + r.cnt + '</td>'
                   + '<td>' + esc(r.status) + '</td>'
                   + '<td>' + esc(r.time) + '</td>'
                   + '</tr>';
            }
            box.innerHTML = h + '</tbody></table>';
        }
    };

    /* 后台页异步注入：监听 #owAdminMain，看到本插件的表单就初始化一次 */
    function watch() {
        var main = $('owAdminMain');
        if (!main) return;
        var tryInit = function () {
            var el = $('owRpMaxTotal');
            if (el && el.getAttribute('data-owrp-inited') !== '1') {
                el.setAttribute('data-owrp-inited', '1');
                w.OwRPAdmin.load();
            }
        };
        if (typeof MutationObserver !== 'undefined') {
            new MutationObserver(tryInit).observe(main, { childList: true, subtree: true });
        } else {
            setInterval(tryInit, 300);
        }
        tryInit();
    }

    if (d.readyState === 'loading') {
        d.addEventListener ? d.addEventListener('DOMContentLoaded', watch, false) : (d.onreadystatechange = watch);
    } else {
        watch();
    }
})(window, document);
