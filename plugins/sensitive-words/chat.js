/**
 * 垃圾信息控制插件 - 聊天页交互（v1.3.104 起，v1.3.106 加申诉与规则说明）
 *
 * 两件事：
 *   1. 给核心挂「链接守卫」window.OW_LINK_GUARD —— 核心 linkifyHtml 在把 URL 变成 <a> 之前问一句，
 *      返回一句提示 = 这个链接不许跳，点击只弹窗（用户仍看得见完整 URL，知道它本来要去哪）。
 *   2. 给那个弹窗追加两个按钮（window.OW_LINK_BLOCKED_EXTRA）：「规则说明」与「申请恢复访问」。
 *      弹窗本体仍归核心，插件只追加内容，不猜核心的结构。
 *
 * 名单与规则由 main.php 的 page.footer 注入为 window.OW_SPAM_*，
 * 而 page.footer 在合并资源**之后**才输出 —— 所以这里必须**调用时**再读全局，
 * 不能在本脚本加载时抓一份快照（那样永远拿到 undefined，功能静默失效）。
 */
(function (w) {
    'use strict';
    if (w.__owSpamChat) return;      // 幂等：合并资源被重复引入时不重复注册
    w.__owSpamChat = 1;

    var esc = w.esc || function (s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    };
    // 打开申诉弹窗时记下的域名，提交时用它 —— 不去 DOM 里猜（弹窗内容一改就得跟着改选择器）
    var appealDomain = '';

    /**
     * 取 URL 的主机名（小写、去端口、去认证信息）。
     * 核心 v1.3.104 起传进来的地址一律带协议（裸域名会补 https://），
     * 但这是对外公开的接缝，别假设调用方一定守约 —— 没协议时按「第一段就是主机」处理。
     * ⚠️ 必须处理这三类伪装，否则名单形同虚设：
     *   https://spam.com@good.com/    认证信息里塞一个白名单域名，真实主机是 good.com
     *   https://good.com\@spam.com/   浏览器把 \ 当 / 解析，主机在第一个 \ 之前结束
     *   https://spam.com:8080/        带端口的同一主机不能漏
     */
    function hostOf(url) {
        var s = String(url || '');
        var m = /^https?:\/\/([^\/?#]*)/i.exec(s);
        var auth = m ? m[1] : s.split(/[\/?#]/)[0];
        var bs = auth.indexOf('\\');
        if (bs >= 0) auth = auth.slice(0, bs);
        var at = auth.lastIndexOf('@');
        if (at >= 0) auth = auth.slice(at + 1);
        auth = auth.replace(/:\d+$/, '');
        return auth.toLowerCase().replace(/\.$/, '');
    }

    /** 精确相等或子域命中（与后端 owSPdomainHit 同一口径） */
    function hit(host, list) {
        if (!host) return false;
        for (var i = 0; i < list.length; i++) {
            var d = String(list[i] || '').toLowerCase();
            if (!d) continue;
            if (host === d) return true;
            if (host.length > d.length + 1 && host.slice(-(d.length + 1)) === '.' + d) return true;
        }
        return false;
    }

    w.OW_LINK_GUARD = function (url) {
        var list = w.OW_SPAM_DOMAINS;
        if (!list || !list.length) return '';
        if (!hit(hostOf(url), list)) return '';
        return String(w.OW_SPAM_DOMAIN_WARN || '该链接已被站点拦截，不再自动跳转。');
    };

    /* ==================== 被拦弹窗上的两个出口（v1.3.106） ====================
       核心在自己的弹窗里给插件留了一次追加 HTML 的机会（window.OW_LINK_BLOCKED_EXTRA），
       这里就只追加按钮，弹窗本体仍由核心渲染 —— 插件不猜核心的结构。 */

    /** 追加到被拦弹窗底部：规则说明 + 申请恢复访问 */
    w.OW_LINK_BLOCKED_EXTRA = function (a) {
        var url = (a && a.getAttribute('data-url')) || '';
        var domain = hostOf(url);
        if (!domain) return '';
        var rules = w.OW_SPAM_DOMAIN_RULES;
        var h = '<div class="ow-modal-actions">';
        if (rules && rules.length) {
            h += '<button type="button" class="ow-btn ow-btn-ghost" onclick="OwSpam.showRules()">规则说明</button>';
        }
        // 域名走 data 属性传给处理函数，不拼进 onclick 字符串：
        // 域名是从用户自己打的链接里抠出来的，拼进 JS 源码等于把注入面敞开。
        if (w.OW_SPAM_APPEAL_ON == 1) {
            h += '<button type="button" class="ow-btn ow-btn-primary" data-domain="' + esc(domain) + '"'
                + ' onclick="OwSpam.appeal(this)">申请恢复访问</button>';
        }
        return h + '</div>';
    };

    w.OwSpam = {
        /** 规则说明：后台每行一条，这里原样列出来 */
        showRules: function () {
            var rules = w.OW_SPAM_DOMAIN_RULES || [];
            var h = '<h3>规则说明</h3><p class="ow-modal-desc">站点按下列规则拦截外部链接：</p><ul style="margin:0 0 10px 20px">';
            for (var i = 0; i < rules.length; i++) h += '<li>' + esc(rules[i]) + '</li>';
            h += '</ul><div class="ow-modal-actions">'
                + '<button type="button" class="ow-btn ow-btn-ghost" onclick="OwChat.closeModal()">关闭</button></div>';
            w.OwChat.openModal(h, 420);
        },

        /** 申诉表单：域名预填（只读），用户写一句「为什么需要访问它」 */
        appeal: function (btn) {
            var domain = btn.getAttribute('data-domain') || '';
            if (!domain) return;
            appealDomain = domain;
            var limit = parseInt(w.OW_SPAM_APPEAL_LIMIT, 10) || 300;
            var h = '<h3>申请恢复访问</h3>'
                + '<p class="ow-modal-desc">被拦截的域名：<b>' + esc(domain) + '</b><br>'
                + '请说明你为什么需要访问它（例如：正常工作需要的文档、要查证的来源）。管理员审核通过后该域名会解除拦截。</p>'
                + '<div class="ow-form-item"><label>申请说明</label>'
                + '<textarea class="ow-input" id="owSpamAppealNote" rows="4" maxlength="' + limit + '"'
                + ' placeholder="至少写一句，方便管理员判断"></textarea></div>'
                + '<div class="ow-modal-actions">'
                + '<button type="button" class="ow-btn ow-btn-ghost" onclick="OwChat.closeModal()">取消</button>'
                + '<button type="button" class="ow-btn ow-btn-primary" id="owSpamAppealGo" onclick="OwSpam.submit()">提交申请</button>'
                + '</div>';
            w.OwChat.openModal(h, 460);
            var t = document.getElementById('owSpamAppealNote');
            if (t && t.focus) t.focus();
        },

        submit: function () {
            var box = document.getElementById('owSpamAppealNote');
            var go = document.getElementById('owSpamAppealGo');
            if (!box) return;
            var note = String(box.value || '').trim();
            if (!note) { w.toast('请填写申请说明'); return; }
            if (!appealDomain) { w.toast('域名识别失败，请重新点击链接'); return; }
            if (go) go.disabled = true;                 // 提交中锁死，防连点送出两条
            OwApi.post('plugin_spam_appeal_submit', { domain: appealDomain, note: note }, function (r) {
                if (go) go.disabled = false;
                w.toast((r && r.msg) || '提交失败');
                if (r && r.ok) { appealDomain = ''; w.OwChat.closeModal(); }
            });
        }
    };
})(window);
