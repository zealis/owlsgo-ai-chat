/**
 * gif-stickers · 后台配置页交互（v1.3.75）
 * 全局对象 OwGS；依赖主 chat.js 提供的 OwApi / toast / esc。
 * 兼容老浏览器：只用 var / function，不用箭头函数、let/const、fetch。
 */
(function (w, d) {
    'use strict';
    var $ = function (id) { return d.getElementById(id); };

    w.OwGS = {
        /** 只显示当前所选源那一组：另一组是空壳，填了也不会被保存 */
        show: function () {
            var sel = $('owGsProvider');
            if (!sel) return;
            var cur = sel.value, rows = d.querySelectorAll('.ow-gs-src'), i;
            for (i = 0; i < rows.length; i++) {
                rows[i].style.display = rows[i].getAttribute('data-owgs-src') === cur ? '' : 'none';
            }
        },

        collect: function () {
            var data = { provider: $('owGsProvider').value, klipy_per_page: $('owGsPerPage').value };
            var f = d.querySelectorAll('[data-owgs-field]'), i;
            for (i = 0; i < f.length; i++) data[f[i].getAttribute('data-owgs-field')] = f[i].value;
            return data;
        },

        /** 保存能改站内所有访客看到的内容源，走一次性票据（与 captcha-verify 同口径） */
        save: function () {
            OwApi.secure('plugin_gif_stickers_save', this.collect(), function (r) {
                var m = $('owGsMsg');
                if (m) m.innerHTML = '<span style="color:' + (r.ok ? 'var(--ow-green,#52C41A)' : 'var(--ow-red,#F5222D)') + '">' + esc(r.msg) + '</span>';
                toast(r.msg);
                if (r.ok) OwGS.show();
            });
        },

        /**
         * 连通性自测：按当前所选源真发一次「取热门」请求。
         * KLIPY 由浏览器直连（这是他们的条款要求的调用方式），
         * API 盒子走本站代理路由 —— 两条路分开测，才能分清「是配置错还是网络不通」。
         */
        test: function () {
            var self = this, data = this.collect(), m = $('owGsMsg');
            var say = function (ok, t) {
                if (m) m.innerHTML = '<span style="color:' + (ok ? 'var(--ow-green,#52C41A)' : 'var(--ow-red,#F5222D)') + '">' + esc(t) + '</span>';
                toast(t);
            };
            if (data.provider === 'apihz') {
                OwApi.post('plugin_gif_stickers_search', { words: '', page: 1 }, function (r) {
                    say(!!r.ok, r.ok ? ('代理可用，取到 ' + ((r.data || []).length) + ' 张') : (r.msg || '测试失败'));
                });
                return;
            }
            var key = String(data.klipy_app_key || '').replace(/^\s+|\s+$/g, '');
            if (!key) { say(false, '请先填写 KLIPY 的 App Key'); return; }
            var url = 'https://api.klipy.com/api/v1/' + encodeURIComponent(key) + '/gifs/trending'
                + '?per_page=8&content_filter=' + encodeURIComponent(data.klipy_content_filter || 'low')
                + '&locale=' + encodeURIComponent(String(data.klipy_locale || 'us').toLowerCase());
            var x = new XMLHttpRequest();
            x.open('GET', url, true);
            x.timeout = 10000;
            x.onload = function () {
                var j = null;
                try { j = JSON.parse(x.responseText || '{}'); } catch (e) { j = null; }
                if (!j) { say(false, 'KLIPY 返回了无法解析的内容（HTTP ' + x.status + '）'); return; }
                var n = w.OwGif ? w.OwGif.count(j) : 0;
                say(x.status === 200 && n > 0, n > 0 ? ('KLIPY 可用，取到 ' + n + ' 张') : ('KLIPY 未返回内容（HTTP ' + x.status + '），请核对 App Key'));
            };
            x.onerror = function () { say(false, '浏览器直连 KLIPY 失败：可能被网络策略或广告拦截挡住'); };
            x.ontimeout = function () { say(false, 'KLIPY 请求超时'); };
            x.send();
        }
    };

    /**
     * 后台页 HTML 是 SPA 异步注入的（admin_plugin_page 返回后才进 DOM），
     * 脚本执行时 #owGsProvider 根本不存在 —— 直接绑定的话切源不会联动，
     * 表现就是「选了 API 盒子，下面还是 KLIPY 那一堆框」。
     * 与 captcha-verify / email-verify 同一做法：监听 #owAdminMain 变化后再绑一次。
     */
    function watchInit() {
        var main = $('owAdminMain');
        if (!main) return;
        var tryInit = function () {
            var el = $('owGsProvider');
            if (el && el.getAttribute('data-owgs-inited') !== '1') {
                el.setAttribute('data-owgs-inited', '1');
                OwGS.show();
                el.onchange = OwGS.show;
            }
        };
        if (typeof MutationObserver !== 'undefined') {
            new MutationObserver(tryInit).observe(main, { childList: true, subtree: true });
        } else {
            setInterval(tryInit, 300);
        }
        tryInit();
    }

    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', watchInit);
    else watchInit();
})(window, document);
