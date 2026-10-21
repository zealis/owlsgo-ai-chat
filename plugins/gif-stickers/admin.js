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
            // v1.5.18：KLIPY 分支是**裸 XHR**（不走 OwApi，自动锁覆盖不到），
            // 这里手动挂到触发按钮上，避免连点把测试请求打出去好几次。
            var release = w.OwBusy ? OwBusy.autoLock('plugin_gif_stickers_test') : null;
            var say = function (ok, t) {
                if (m) m.innerHTML = '<span style="color:' + (ok ? 'var(--ow-green,#52C41A)' : 'var(--ow-red,#F5222D)') + '">' + esc(t) + '</span>';
                toast(t);
                if (release) { release(); release = null; }
            };
            if (data.provider === 'apihz') {
                // nocache：自测必须打真接口 —— 命中缓存时「代理可用」是假的（上游挂了也照样秒回）。
                // 这个开关只对管理员生效，普通访客传了也没用（见 main.php 里的 $noCache）。
                OwApi.post('plugin_gif_stickers_search', { words: '', page: 1, nocache: 1 }, function (r) {
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
                if (x.status !== 200) { say(false, 'KLIPY 返回 HTTP ' + x.status + '，请核对 App Key'); return; }
                var s = w.OwGif ? w.OwGif.inspect(j) : null;
                if (!s) { say(false, '解析脚本还没就绪，请刷新后台页面再试'); return; }
                if (s.n > 0) { say(true, 'KLIPY 可用，取到 ' + s.n + ' 张'); return; }
                // 三种 0 条要说不同的话：找不出数组 = 结构变了；找到 N 条但取不到地址 =
                // 媒体字段变了；找到空数组 = 密钥/授权没给内容（这三种修法完全不同）
                say(false, s.found < 0
                    ? ('KLIPY 返回 200，但内容里找不到结果数组（顶层：' + s.top + '）')
                    : (s.dropped
                        ? ('KLIPY 返回 ' + s.found + ' 条，但都取不到图片地址（顶层：' + s.top + '）')
                        : ('KLIPY 返回 200 但给了空列表 —— 请核对密钥对应的产品（GIF/Sticker/Clip）与已授权域名')));
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
