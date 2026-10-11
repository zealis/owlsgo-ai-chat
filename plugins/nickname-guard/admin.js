/**
 * 昵称保留插件 - 后台交互（v1.3.1）
 * 全局对象 OwNG：页面 HTML 由插件后台页注入，函数在点击时执行，
 * 依赖的 OwApi / toast / esc 由主 chat.js 提供。
 */
(function (w, d) {
    'use strict';
    var $ = function (id) { return d.getElementById(id); };

    w.OwNG = {
        /* 保存保留词列表 */
        save: function () {
            OwApi.post('plugin_nickname_guard_save', {
                list: $('owNGList').value
            }, function (r) {
                var m = $('owNGMsg');
                m.innerHTML = '<span style="color:' + (r.ok ? 'var(--ow-green,#52C41A)' : 'var(--ow-red,#F5222D)') + '">' + esc(r.msg) + '</span>';
                toast(r.msg);
            });
        }
    };
})(window, document);
