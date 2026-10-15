/**
 * captcha-verify · 后台配置页交互（v1.3.54）
 * 全局对象 OwCV；依赖主 chat.js 暴露的 OwApi / toast / esc。
 * 兼容老浏览器：只用 var / function，不用箭头函数、let/const、fetch。
 */
(function (w, d) {
    'use strict';
    var $ = function (id) { return d.getElementById(id); };

    w.OwCV = {
        /** 只显示当前所选服务商那一组字段（其余组是空壳，填了也不会被保存） */
        show: function () {
            var sel = $('owCvProvider');
            if (!sel) return;
            var cur = sel.value;
            var rows = d.querySelectorAll('.ow-cv-prov'), i;
            for (i = 0; i < rows.length; i++) {
                rows[i].style.display = rows[i].getAttribute('data-owcv-prov') === cur ? '' : 'none';
            }
        },

        save: function () {
            var en = $('owCvEnabled'), sel = $('owCvProvider'), fm = $('owCvFormMode');
            if (!en || !sel) return;
            var data = { enabled: en.value, provider: sel.value };
            // v1.1.0 呈现方式：随基础项一起提交（后端有白名单收敛）
            if (fm) data.form_mode = fm.value;
            var fields = d.querySelectorAll('[data-owcv-field]'), i;
            for (i = 0; i < fields.length; i++) {
                data[fields[i].getAttribute('data-owcv-field')] = fields[i].value;
            }
            // 保存是敏感操作（能改登录防护本身），走一次性票据
            OwApi.secure('plugin_captcha_verify_save', data, function (r) {
                toast(r.msg);
                if (r.ok) OwCV.show();
            });
        },

        /** 连通性自测：拿假 token 打真实接口，能拿到结构化拒绝就说明链路通 */
        test: function () {
            OwApi.post('plugin_captcha_verify_test', {}, function (r) {
                toast(r.msg);
            });
        }
    };

    /* 后台页面 HTML 由 admin_plugin_page 异步注入，监听 #owAdminMain 变化后自动初始化 */
    function watchInit() {
        var main = $('owAdminMain');
        var sel = function () { return $('owCvProvider'); };
        if (!main) return;
        var tryInit = function () {
            var el = sel();
            if (el && el.getAttribute('data-owcv-inited') !== '1') {
                el.setAttribute('data-owcv-inited', '1');
                OwCV.show();
                el.onchange = OwCV.show;
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
