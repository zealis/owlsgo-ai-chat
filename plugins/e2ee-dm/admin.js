/**
 * 加密私聊 - 后台交互
 *
 * 用 document 级事件委托而不是「注入后绑定」：后台页面是 SPA，
 * 每次进入都会重新 innerHTML 注入容器，绑在按钮上的 onclick 会随节点一起丢。
 * 委托挂在 document 上，重进多少次都还在。
 */
(function (w, d) {
    'use strict';

    d.addEventListener('click', function (e) {
        var t = e.target;
        if (!t || t.id !== 'owedSaveMode') return;
        var sel = d.querySelector('input[name="owedMode"]:checked');
        var mode = sel ? sel.value : 'off';
        w.OwApi.secure('plugin_e2ee_dm_setmode', { mode: mode }, function (r) {
            w.toast(r.msg || (r.ok ? '已保存' : '保存失败'));
        });
    });
})(window, document);
