/**
 * 垃圾信息控制插件 - 聊天页交互（v1.3.104）
 *
 * 只做一件事：给核心挂一个「链接守卫」window.OW_LINK_GUARD。
 * 核心 linkifyHtml 在把 URL 变成 <a> 之前问一句，返回一句提示 = 这个链接不许跳，
 * 点击只弹窗（用户仍然看得见完整 URL，知道它本来要去哪）。
 *
 * 名单由 main.php 的 page.footer 注入为 window.OW_SPAM_DOMAINS，
 * 而 page.footer 在合并资源**之后**才输出 —— 所以这里必须**调用时**再读全局，
 * 不能在本脚本加载时抓一份快照（那样永远拿到 undefined，功能静默失效）。
 */
(function (w) {
    'use strict';
    if (w.__owSpamChat) return;      // 幂等：合并资源被重复引入时不重复注册
    w.__owSpamChat = 1;

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
})(window);
