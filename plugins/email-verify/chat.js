/**
 * 邮箱验证插件 - 前台「修改登录邮箱」弹窗（v1.1.0）
 *
 * 入口在核心的「个人设置 → 账号」：那边按 window.OwEvEmail 是否存在渲染按钮，
 * 所以本插件停用时入口自然消失，核心不需要任何开关判断。
 *
 * 两步走（顺序不能反，服务端两道闸都在）：
 *   1. send   ：带**当前密码** + 新邮箱 → 验证码发到新邮箱（限流在 Mailer::sendCode 里）
 *   2. commit ：带当前密码 + 新邮箱 + 验证码 → 才真正改 users.email
 * 两个路由都是敏感路由，走 OwApi.secure 的一次性票据。
 *
 * 兼容老浏览器：只用 var / function，无箭头函数、无 const/let。
 */
(function (w, d) {
    'use strict';
    if (!w.OwChat || !w.OwApi) return;

    var esc = w.esc || function (s) { return String(s == null ? '' : s); };
    var tm = null;

    function val(id) {
        var el = d.getElementById(id);
        return el ? String(el.value || '') : '';
    }
    function say(s, ok) {
        var m = d.getElementById('owEvEmailMsg');
        if (!m) return;
        m.innerHTML = s === '' ? '' : '<span style="color:var(--ow-' + (ok ? 'green' : 'red') + ')">' + esc(s) + '</span>';
    }
    /** 重发倒计时：秒数取服务端返回值（后台可改 mail_rate_limit，写死 60 会「转完了还不能发」） */
    function countdown(btn, sec) {
        if (tm) { clearInterval(tm); tm = null; }
        var n = parseInt(sec, 10);
        if (!(n > 0)) n = 60;
        btn.disabled = true;
        btn.textContent = n + 's 后重发';
        tm = setInterval(function () {
            if (--n < 0) { clearInterval(tm); tm = null; btn.disabled = false; btn.textContent = '发送验证码'; return; }
            btn.textContent = n + 's 后重发';
        }, 1000);
    }

    var OwEvEmail = {
        open: function () {
            var me = (w.OwChat.cfg && w.OwChat.cfg.me) || {};
            OwChat.openModal('<h3>修改登录邮箱</h3>'
                + '<p class="ow-modal-desc">当前登录邮箱：<b>' + esc(me.email || '未设置') + '</b>'
                + '　改完下次登录起用新邮箱或用户 ID。</p>'
                + '<div class="ow-form-item"><label>新邮箱</label>'
                + '<input class="ow-input" id="owEvEmailNew" type="email" autocomplete="off" placeholder="you@example.com"></div>'
                + '<div class="ow-form-item"><label>当前密码</label>'
                + '<input class="ow-input" id="owEvEmailPw" type="password" autocomplete="current-password" placeholder="确认是本人操作"></div>'
                + '<div class="ow-form-item"><label>新邮箱验证码</label>'
                + '<div class="ow-form-row" style="gap:8px">'
                + '<input class="ow-input" id="owEvEmailCode" style="flex:1 1 auto;min-width:0" inputmode="numeric" '
                + 'autocomplete="one-time-code" maxlength="10" placeholder="6 位验证码">'
                + '<button type="button" class="ow-btn ow-btn-ghost" style="flex:none" id="owEvEmailSend" '
                + 'onclick="OwEvEmail.send()">发送验证码</button></div></div>'
                + '<div class="ow-form-msg" id="owEvEmailMsg"></div>'
                + '<div class="ow-modal-actions">'
                + '<button type="button" class="ow-btn ow-btn-ghost" onclick="OwChat.closeModal()">取消</button>'
                + '<button type="button" class="ow-btn ow-btn-primary" id="owEvEmailOk" onclick="OwEvEmail.commit()">确认修改</button>'
                + '</div>');
            var f = d.getElementById('owEvEmailNew');
            if (f && f.focus) f.focus();
        },

        send: function () {
            var btn = d.getElementById('owEvEmailSend');
            if (!btn || btn.disabled) return;                 // 防重入：倒计时期间点不动
            var email = val('owEvEmailNew').trim(), pw = val('owEvEmailPw');
            if (email === '') { say('请先填新邮箱'); return; }
            if (pw === '') { say('请先填当前密码'); return; }
            say('正在发送验证码…', true);
            btn.disabled = true;
            OwApi.secure('plugin_email_verify_email_send', { email: email, current: pw }, function (r) {
                if (!r.ok) { say(r.msg || '发送失败'); countdown(btn, r.wait || 0); return; }
                say(r.msg || '验证码已发送', true);
                countdown(btn, r.remain > 0 ? r.remain : (r.wait || 60));
            });
        },

        commit: function () {
            var btn = d.getElementById('owEvEmailOk');
            if (btn && btn.disabled) return;
            var email = val('owEvEmailNew').trim(), pw = val('owEvEmailPw'), code = val('owEvEmailCode').trim();
            if (email === '' || pw === '' || code === '') { say('新邮箱、当前密码、验证码都要填'); return; }
            if (btn) btn.disabled = true;
            say('提交中…', true);
            OwApi.secure('plugin_email_verify_email_commit', { email: email, current: pw, code: code }, function (r) {
                if (btn) btn.disabled = false;
                if (!r.ok) { say(r.msg || '修改失败'); return; }
                toast(r.msg || '已更新');
                // 顶栏资料区、侧栏「我」那一行都读的是 cfg.me，整页刷新最省事也最不会漏
                setTimeout(function () { location.reload(); }, 600);
            });
        }
    };

    w.OwEvEmail = OwEvEmail;
})(window, document);
