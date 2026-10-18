/**
 * voice-msg · 后台配置页交互（v1.0.0）
 * 全局对象 OwVMAdmin：页面 HTML 由插件后台页注入，函数在点击时执行。
 * 依赖主程序提供的 OwApi / toast。
 * 兼容老浏览器：只用 var / function，不用箭头函数、let/const、fetch。
 */
(function (w, d) {
    'use strict';
    var $ = function (id) { return d.getElementById(id); };

    w.OwVMAdmin = {
        save: function () {
            var durEl = $('owvmMaxDur'), mbEl = $('owvmMaxMb');
            if (!durEl || !mbEl) return;
            OwApi.post('plugin_voice_msg_admin_save', {
                max_dur: durEl.value,
                max_mb: mbEl.value
            }, function (r) {
                if (r && r.ok) {
                    if (w.toast) w.toast(r.msg || '已保存');
                    if (r.max_dur) durEl.value = r.max_dur;
                    if (r.max_mb) mbEl.value = r.max_mb;
                } else if (w.toast) {
                    w.toast((r && r.msg) || '保存失败');
                }
            });
        }
    };
})(window, document);
