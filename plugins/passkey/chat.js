/* 通行密钥插件前端（passkey）v1.0.0
 *
 * 两个落点：
 *   1. 登录页：在账号密码表单下方注入「使用通行密钥登录」——
 *      设备指纹 / 面容 / PIN 直接登录，走 plugin_passkey_login_* 路由。
 *   2. 聊天页：包一层 OwChat.openSettings，把「通行密钥」区块追加到
 *      个人设置 →「账号」页（用 owTabs.pane(modal,'acct') 定位，别插到弹窗根节点）。
 *
 * 注意：
 *   · 本文件在登录页也会执行 —— 此刻 OwChat.cfg 还不存在，只能用 DOM 判断场景。
 *   · OwApi.key 由 OwAuth.init 在**本文件之后**下发（登录页），所以登录流程必须等
 *     DOMContentLoaded 再发请求；聊天页则可以直接用。
 *   · WebAuthn 的 create() / get() 必须在**用户手势内**调用（Safari 尤其严格），
 *     所以注册挑战（add_options）在面板打开时就预取，且「验密码」与「点开始验证」拆成两步：
 *     create() 落在第二次点击的手势里，不经过任何 XHR 回调。
 */
(function (w, d) {
    'use strict';
    if (w.__owPkLoaded) return;
    w.__owPkLoaded = true;
    if (!w.OwApi || !w.OwChat || !w.esc || !w.toast) return;   // 核心脚本没加载（不该发生）

    function $(id) { return d.getElementById(id); }
    function esc(s) { return w.esc(s); }
    function toast(s) { w.toast(s); }

    /* ---------- base64url <-> ArrayBuffer ---------- */
    function bufToB64u(buf) {
        var bytes = new Uint8Array(buf), s = '', i;
        for (i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
        return w.btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }
    function b64uToBuf(s) {
        s = String(s || '').replace(/-/g, '+').replace(/_/g, '/');
        while (s.length % 4) s += '=';
        var bin = w.atob(s), out = new Uint8Array(bin.length), i;
        for (i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
        return out.buffer;
    }

    /* ---------- 图标（自绘线性 SVG，24 网格，与核心 ow_icon 同一语言） ---------- */
    function svg(paths, size) {
        return '<svg width="' + (size || 20) + '" height="' + (size || 20) + '" viewBox="0 0 24 24" fill="none" '
            + 'stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" '
            + 'aria-hidden="true">' + paths + '</svg>';
    }
    // 指纹：与核心 ow_icon 同一套 24 网格线性语言（多弧线版本，22px 下依然认得出是指纹）
    var FINGER = '<path d="M12 10a2 2 0 0 0-2 2c0 1.02-.1 2.51-.26 4"/>'
        + '<path d="M14 13.12c0 2.38 0 6.38-1 8.88"/>'
        + '<path d="M17.29 21.02c.12-.6.43-2.3.5-3.02"/>'
        + '<path d="M2 12a10 10 0 0 1 18-6"/>'
        + '<path d="M2 16h.01"/>'
        + '<path d="M21.8 16c.2-2 .131-5.354 0-6"/>'
        + '<path d="M5 19.5C5.5 18 6 15 6 12a6 6 0 0 1 .34-2"/>'
        + '<path d="M8.65 22c.21-.66.45-1.32.57-2"/>'
        + '<path d="M9 6.8a6 6 0 0 1 9 5.2v2"/>';
    var ICO = {
        finger: svg(FINGER, 22),        // 登录按钮 / 列表
        fingerBig: svg(FINGER, 34),      // 设备确认弹窗里的主视觉
        // 本机设备
        device: svg('<rect x="3.2" y="5" width="17.6" height="11.6" rx="2"/><path d="M9.5 20h5"/><path d="M12 16.6V20"/>', 18),
        // 外接安全密钥
        key: svg('<rect x="7.4" y="3.2" width="9.2" height="12.2" rx="2"/><path d="M10.6 3.4V6h2.8V3.4"/><path d="M10.5 19v1.8"/><path d="M13.5 19v1.8"/>', 18)
    };

    /* ---------- 时间 ---------- */
    function fmtDate(ts) {
        if (!ts) return '';
        var dt = new Date(ts * 1000), n = dt.getFullYear() + '-' + (dt.getMonth() + 1) + '-' + dt.getDate();
        return n;
    }
    function fmtRecent(ts) {
        if (!ts) return '尚未使用';
        var diff = Math.floor(new Date().getTime() / 1000) - ts;
        if (diff < 60) return '刚刚用过';
        if (diff < 3600) return Math.floor(diff / 60) + ' 分钟前用过';
        if (diff < 86400) return Math.floor(diff / 3600) + ' 小时前用过';
        if (diff < 86400 * 7) return Math.floor(diff / 86400) + ' 天前用过';
        return fmtDate(ts) + ' 用过';
    }

    /* ---------- WebAuthn 可用性 ---------- */
    function webauthnOk() {
        return !!(w.PublicKeyCredential && w.navigator && w.navigator.credentials
            && w.navigator.credentials.create && w.navigator.credentials.get);
    }
    function errText(err) {
        var n = (err && err.name) ? err.name : '';
        if (n === 'NotAllowedError') return '已取消，或没有可用的通行密钥';
        if (n === 'InvalidStateError') return '这台设备已经添加过通行密钥了';
        if (n === 'SecurityError') return '当前域名不允许使用通行密钥（需要 HTTPS）';
        if (n === 'NotSupportedError') return '此浏览器不支持通行密钥';
        if (n === 'AbortError') return '操作已中止';
        return '操作未完成' + (n ? '（' + n + '）' : '');
    }

    /* ==========================================================================
       一、登录页
       ========================================================================== */
    function initLogin() {
        var form = d.querySelector('.ow-auth-form[data-mode="login"]');
        if (!form || $('owpkLogin')) return;
        w.OwApi.post('plugin_passkey_config', {}, function (r) {
            if (!r || !r.ok || !r.enabled) return;
            if (!r.secure || !webauthnOk()) return;   // 非 HTTPS / 老浏览器：不摆一个点了就报错的按钮
            mountLogin(form);
        });
    }

    function mountLogin(form) {
        var box = d.createElement('div');
        box.className = 'owpk-login';
        box.id = 'owpkLogin';
        box.innerHTML = '<div class="owpk-or"><span>或</span></div>'
            + '<button type="button" class="owpk-pk-btn" id="owpkPkBtn">'
            + '<span class="owpk-pk-ico">' + ICO.finger + '</span>'
            + '<span class="owpk-pk-t">使用通行密钥登录</span>'
            + '<span class="owpk-spin" aria-hidden="true"></span>'
            + '</button>'
            + '<div class="owpk-tip" id="owpkPkTip"></div>'
            + '<div class="owpk-idrow" id="owpkIdRow">'
            + '<input class="ow-input" id="owpkIdentity" type="text" placeholder="邮箱或用户 ID" autocomplete="username">'
            + '<button type="button" class="ow-btn ow-btn-ghost" id="owpkIdGo">继续</button>'
            + '</div>';
        // 插在登录表单之后、「注册账号 / 忘记密码」那一行之前
        form.parentNode.insertBefore(box, form.nextSibling);

        var btn = $('owpkPkBtn');
        btn.onclick = function () { startLogin(''); };
        $('owpkIdGo').onclick = function () {
            var v = ($('owpkIdentity').value || '').replace(/^\s+|\s+$/g, '');
            if (!v) { setTip('请先填写邮箱或用户 ID'); return; }
            startLogin(v);
        };
        $('owpkIdentity').onkeydown = function (e) {
            e = e || w.event;
            if (e.key === 'Enter' || e.keyCode === 13) { e.preventDefault(); $('owpkIdGo').onclick(); }
        };
    }

    function setTip(msg, tone) {
        var t = $('owpkPkTip');
        if (!t) return;
        t.innerHTML = msg ? esc(msg) : '';
        t.className = 'owpk-tip' + (msg ? ' is-on' : '') + (tone === 'err' ? ' is-err' : '');
    }

    function setBusy(on) {
        var btn = $('owpkPkBtn');
        if (!btn) return;
        btn.disabled = !!on;
        btn.className = 'owpk-pk-btn' + (on ? ' is-busy' : '');
    }

    function startLogin(identity) {
        if (!webauthnOk()) { setTip('此浏览器不支持通行密钥', 'err'); return; }
        setTip('');
        setBusy(true);
        var data = identity ? { identity: identity } : {};
        w.OwApi.post('plugin_passkey_login_options', data, function (r) {
            if (!r || !r.ok) {
                setBusy(false);
                setTip((r && r.msg) || '无法开始验证', 'err');
                return;
            }
            var pk = r.publicKey;
            pk.challenge = b64uToBuf(pk.challenge);
            var list = pk.allowCredentials || [], i;
            for (i = 0; i < list.length; i++) list[i].id = b64uToBuf(list[i].id);

            w.navigator.credentials.get({ publicKey: pk }).then(function (cred) {
                var res = cred.response;
                w.OwApi.post('plugin_passkey_login_finish', {
                    id: cred.id,
                    rawId: bufToB64u(cred.rawId),
                    clientDataJSON: bufToB64u(res.clientDataJSON),
                    authenticatorData: bufToB64u(res.authenticatorData),
                    signature: bufToB64u(res.signature),
                    userHandle: res.userHandle ? bufToB64u(res.userHandle) : ''
                }, function (rr) {
                    if (rr && rr.ok) {
                        setTip('验证通过，正在进入…');
                        w.location.href = '?page=chat';
                        return;
                    }
                    setBusy(false);
                    setTip((rr && rr.msg) || '验证未通过', 'err');
                });
            }).catch(function (err) {
                setBusy(false);
                setTip(errText(err), 'err');
                // 免用户名取凭据失败（多半是设备上没有常驻凭据）→ 给一条「先填账号」的退路
                if (!identity && err && (err.name === 'NotAllowedError' || err.name === 'InvalidStateError')) {
                    var row = $('owpkIdRow');
                    if (row) row.className = 'owpk-idrow is-on';
                    setTip('没有找到这台设备上的通行密钥。可以填写账号后再试，或改用密码登录。');
                }
            });
        });
    }

    /* ==========================================================================
       二、聊天页：个人设置里的管理区
       ========================================================================== */
    var pendingOpts = null;   // 预取的注册挑战（保证 create() 在用户手势内调用）

    function initChat() {
        var orig = w.OwChat.openSettings;
        w.OwChat.openSettings = function () {
            var r = orig.apply(this, arguments);
            loadPanel();
            return r;
        };
    }

    function loadPanel() {
        w.OwApi.post('plugin_passkey_status', {}, function (r) {
            if (!r || !r.ok) return;
            renderPanel(r);
            if (r.enabled && r.secure && webauthnOk()) prefetchOptions();
        });
    }

    function prefetchOptions() {
        // 已经有新鲜的挑战就不要再要一个 —— 服务端同一时刻只保留一条挑战，
        // 面板被重复渲染（其它插件也会重开设置）时再取一次，会把正在用的那条顶掉，
        // 表现为「点了添加却提示验证数据不合法」。
        var now = (new Date()).getTime();
        if (pendingOpts && pendingOpts.__at && now - pendingOpts.__at < 120000) return;
        pendingOpts = null;
        w.OwApi.post('plugin_passkey_add_options', {}, function (r) {
            if (r && r.ok && r.publicKey) {
                r.publicKey.__at = (new Date()).getTime();
                pendingOpts = r.publicKey;
            }
        });
    }

    function renderPanel(data) {
        var modal = $('owModal');
        if (!modal || modal.querySelector('#owpkSet')) return;
        var host = (w.owTabs && w.owTabs.pane) ? w.owTabs.pane(modal, 'acct') : null;
        if (!host) return;   // 老内核没有分组结构：不硬塞，避免在每一页都露出来

        var items = data.items || [], html = '', i;
        if (!items.length) {
            html = '<div class="owpk-empty">还没有添加通行密钥。'
                + (data.enabled ? '添加后就能用指纹 / 面容直接登录。' : '站点暂未开启通行密钥登录。')
                + '</div>';
        } else {
            for (i = 0; i < items.length; i++) {
                var it = items[i];
                html += '<div class="owpk-item">'
                    + '<span class="owpk-item-ico">' + (it.cross ? ICO.key : ICO.device) + '</span>'
                    + '<span class="owpk-item-main">'
                    + '<span class="owpk-item-name">' + esc(it.name) + '</span>'
                    + '<span class="owpk-item-meta">添加于 ' + esc(fmtDate(it.created_at)) + ' · ' + esc(fmtRecent(it.last_used)) + '</span>'
                    + '</span>'
                    + '<button type="button" class="owpk-item-act" data-pk-rename="' + esc(it.id) + '" data-pk-name="' + esc(it.name) + '">改名</button>'
                    + '<button type="button" class="owpk-item-act is-del" data-pk-del="' + esc(it.id) + '" data-pk-name="' + esc(it.name) + '">删除</button>'
                    + '</div>';
            }
        }

        var canAdd = data.enabled && data.secure && webauthnOk();
        var sec = d.createElement('div');
        sec.id = 'owpkSet';
        sec.className = 'owpk-set';
        sec.innerHTML = '<div class="owpk-set-head">'
            + '<span class="owpk-set-main">'
            + '<span class="owpk-set-t">通行密钥</span>'
            + '<span class="owpk-set-s">用这台设备的指纹、面容或 PIN 直接登录，不必再输密码。</span>'
            + '</span>'
            + (canAdd ? '<button type="button" class="ow-btn ow-btn-ghost ow-btn-mini owpk-add" id="owpkAdd">添加</button>' : '')
            + '</div>'
            + '<div class="owpk-list">' + html + '</div>'
            + (canAdd ? '' : '<div class="owpk-tip is-on">'
                + (data.enabled ? '当前页面不是 HTTPS 访问，浏览器不允许使用通行密钥。' : '站点暂未开启通行密钥登录，请联系管理员。')
                + '</div>');
        host.appendChild(sec);
        // 位置：紧跟「账号安全」那块（改密码按钮所在表单项），排在两步验证/屏蔽列表之前。
        // 直接 append 会掉到「账号」页最底部 —— 用户得先滚过 2FA 才能看到，
        // 而通行密钥恰恰是这一页最该被看见的入口。认不到锚点就老实放末尾。
        try {
            var items = host.querySelectorAll('.ow-form-item'), it = null, k;
            for (k = 0; k < items.length; k++) {
                if ((items[k].innerHTML || '').indexOf('openPwdDialog') >= 0) { it = items[k]; break; }
            }
            if (it && it.parentNode) it.parentNode.insertBefore(sec, it.nextSibling);
        } catch (e) {}

        var add = $('owpkAdd');
        if (add) add.onclick = askPassword;

        var acts = sec.querySelectorAll('[data-pk-del]'), j;
        for (j = 0; j < acts.length; j++) {
            acts[j].onclick = function () {
                confirmDelete(this.getAttribute('data-pk-del'), this.getAttribute('data-pk-name'));
            };
        }
        acts = sec.querySelectorAll('[data-pk-rename]');
        for (j = 0; j < acts.length; j++) {
            acts[j].onclick = function () {
                askRename(this.getAttribute('data-pk-rename'), this.getAttribute('data-pk-name'));
            };
        }
    }

    /* ---------- 添加：① 密码确认 → ② 设备验证 ----------
       分两步是刻意的：
         · 密码错了在第一步就能看到，不会白弹一次指纹；
         · create() 必须落在真实点击手势里（Safari 严格要求），所以第二步单独一个按钮。 */
    function askPassword() {
        w.OwChat.openModal(
            '<h3>添加通行密钥</h3>'
            + '<p class="ow-modal-desc">通行密钥保存在这台设备（或你的密码管理器）里，之后可用它免密码登录。'
            + '为了确认是本人在操作，请先输入账号密码。</p>'
            + '<div class="owpk-pw"><input type="password" class="ow-input" id="owpkPw" '
            + 'placeholder="当前账号密码" autocomplete="current-password"></div>'
            + '<div class="ow-form-msg" id="owpkPwMsg"></div>'
            + '<div class="ow-modal-actions">'
            + '<button type="button" class="ow-btn ow-btn-ghost" id="owpkPwNo">取消</button>'
            + '<button type="button" class="ow-btn ow-btn-primary" id="owpkPwOk">继续</button>'
            + '</div>'
        );
        var input = $('owpkPw');
        if (input) { try { input.focus(); } catch (e) {} }

        function go() {
            var pw = input ? input.value : '';
            var msg = $('owpkPwMsg');
            if (!pw) { msg.innerHTML = '<span style="color:var(--ow-red)">请输入账号密码</span>'; return; }
            var btn = $('owpkPwOk');
            if (btn) btn.disabled = true;
            msg.innerHTML = '验证中…';
            w.OwApi.post('plugin_passkey_pw_check', { password: pw }, function (r) {
                if (btn) btn.disabled = false;
                if (!r || !r.ok) {
                    msg.innerHTML = '<span style="color:var(--ow-red)">' + esc((r && r.msg) || '验证失败') + '</span>';
                    return;
                }
                askDevice(pw);
            });
        }
        $('owpkPwOk').onclick = go;
        $('owpkPwNo').onclick = function () { closeToSettings(); };
        if (input) {
            input.onkeydown = function (e) {
                e = e || w.event;
                if (e.key === 'Enter' || e.keyCode === 13) { e.preventDefault(); go(); }
            };
        }
    }

    function askDevice(pw) {
        w.OwChat.openModal(
            '<h3>确认这台设备</h3>'
            + '<p class="ow-modal-desc">点下面的按钮后，系统会请你用这台设备的指纹、面容或设备 PIN 确认。</p>'
            + '<div class="owpk-hero">' + ICO.fingerBig + '</div>'
            + '<div class="ow-form-msg" id="owpkGoMsg"></div>'
            + '<button type="button" class="ow-btn ow-btn-primary ow-btn-block" id="owpkGo">开始设备验证</button>'
            + '<div class="owpk-hero-tip">没有弹出验证窗口？确认设备已设置指纹 / 面容 / PIN，'
            + '或换用支持通行密钥的浏览器（Chrome / Edge / Safari 16+）。</div>'
        );
        var btn = $('owpkGo');
        if (btn) btn.onclick = function () {
            if (!pendingOpts) {
                $('owpkGoMsg').innerHTML = '<span style="color:var(--ow-red)">准备超时，请关掉重开个人设置再试</span>';
                return;
            }
            btn.disabled = true;
            $('owpkGoMsg').innerHTML = '等待设备确认…';
            // 失败时 createCredential 会 toast + 回到设置页；这里只在成功前保持禁用
            createCredential(pw);
        };
    }

    function createCredential(pw) {
        var pk = pendingOpts, i;
        var publicKey = {
            challenge: b64uToBuf(pk.challenge),
            rp: pk.rp,
            user: { id: b64uToBuf(pk.user.id), name: pk.user.name, displayName: pk.user.displayName },
            pubKeyCredParams: pk.pubKeyCredParams,
            timeout: pk.timeout,
            attestation: pk.attestation,
            authenticatorSelection: pk.authenticatorSelection,
            excludeCredentials: []
        };
        var ex = pk.excludeCredentials || [];
        for (i = 0; i < ex.length; i++) {
            publicKey.excludeCredentials.push({ type: 'public-key', id: b64uToBuf(ex[i].id), transports: ex[i].transports || undefined });
        }

        w.navigator.credentials.create({ publicKey: publicKey }).then(function (cred) {
            var res = cred.response;
            var transports = '';
            try { transports = (res.getTransports ? res.getTransports().join(',') : ''); } catch (e) {}
            w.OwApi.post('plugin_passkey_add_finish', {
                password: pw,
                name: deviceLabel(cred.authenticatorAttachment),
                attachment: cred.authenticatorAttachment || '',
                transports: transports,
                rawId: bufToB64u(cred.rawId),
                clientDataJSON: bufToB64u(res.clientDataJSON),
                attestationObject: bufToB64u(res.attestationObject)
            }, function (r) {
                pendingOpts = null;
                if (!r || !r.ok) {
                    toast((r && r.msg) || '添加失败');
                    closeToSettings();
                    return;
                }
                toast('已添加：' + (r.name || '通行密钥'));
                closeToSettings();
            });
        }).catch(function (err) {
            pendingOpts = null;
            toast(errText(err));
            closeToSettings();
        });
    }

    /** 设备默认名称（服务端会优先用 AAGUID 识别出的厂商名覆盖它） */
    function deviceLabel(attachment) {
        var ua = w.navigator.userAgent || '';
        if (/iPhone|iPad|iPod/.test(ua)) return '这台 iPhone / iPad';
        if (/Android/.test(ua)) return '这台 Android 设备';
        if (/Macintosh/.test(ua)) return '这台 Mac';
        if (/Windows/.test(ua)) return '这台 Windows 设备';
        return attachment === 'cross-platform' ? '安全密钥' : '这台设备';
    }

    /** 关掉当前弹窗并回到个人设置（内容会重新拉一次，列表即时刷新） */
    function closeToSettings() {
        w.OwChat.closeModal();
        if (!(w.OwChat.cfg && w.OwChat.cfg.me)) return;
        w.OwChat.openSettings();
        // 回到「账号」页：个人设置默认停在「资料」，添加完再让用户自己切一次很别扭
        var bar = $('owSetTabs');
        if (bar && w.owTabs && w.owTabs.show) w.owTabs.show(bar, 'acct');
    }

    /* ---------- 删除 ---------- */
    function confirmDelete(id, name) {
        w.OwChat.openModal(
            '<h3>删除通行密钥</h3>'
            + '<p class="ow-modal-desc">「' + esc(name) + '」删除后，这台设备就不能再用它登录了。'
            + '你仍可以用密码登录，也可以随时重新添加。</p>'
            + '<div class="ow-modal-actions">'
            + '<button type="button" class="ow-btn ow-btn-ghost" id="owpkDelNo">取消</button>'
            + '<button type="button" class="ow-btn ow-btn-danger" id="owpkDelOk">删除</button>'
            + '</div>'
        );
        $('owpkDelNo').onclick = function () { closeToSettings(); };
        $('owpkDelOk').onclick = function () {
            w.OwApi.secure('plugin_passkey_delete', { id: id }, function (r) {
                toast((r && r.msg) || (r && r.ok ? '已删除' : '删除失败'));
                closeToSettings();
            });
        };
    }

    /* ---------- 改名 ---------- */
    function askRename(id, name) {
        w.OwChat.openModal(
            '<h3>重命名通行密钥</h3>'
            + '<p class="ow-modal-desc">起一个方便辨认的名字，比如「我的笔记本」或「公司安全密钥」。</p>'
            + '<div class="owpk-pw"><input type="text" class="ow-input" id="owpkName" maxlength="30" '
            + 'value="' + esc(name) + '" placeholder="最多 30 个字符"></div>'
            + '<div class="ow-form-msg" id="owpkNameMsg"></div>'
            + '<div class="ow-modal-actions">'
            + '<button type="button" class="ow-btn ow-btn-ghost" id="owpkNameNo">取消</button>'
            + '<button type="button" class="ow-btn ow-btn-primary" id="owpkNameOk">保存</button>'
            + '</div>'
        );
        var input = $('owpkName');
        if (input) { try { input.focus(); input.select(); } catch (e) {} }
        function go() {
            var v = (input.value || '').replace(/^\s+|\s+$/g, '');
            if (!v) { $('owpkNameMsg').innerHTML = '<span style="color:var(--ow-red)">请填写名称</span>'; return; }
            w.OwApi.post('plugin_passkey_rename', { id: id, name: v }, function (r) {
                toast((r && r.msg) || '已保存');
                if (r && r.ok) closeToSettings();
            });
        }
        $('owpkNameOk').onclick = go;
        $('owpkNameNo').onclick = function () { closeToSettings(); };
        if (input) {
            input.onkeydown = function (e) {
                e = e || w.event;
                if (e.key === 'Enter' || e.keyCode === 13) { e.preventDefault(); go(); }
            };
        }
    }

    /* ==========================================================================
       三、后台：总开关（页面是异步注入的，用事件委托）
       ========================================================================== */
    d.addEventListener('change', function (e) {
        var t = e.target;
        if (!t || t.id !== 'owpkAdmSwitch') return;
        // 插件后台页是 innerHTML 注入的，核心不会替它调 bindSwitches —— 轨道配色自己跟上
        var track = t.parentNode ? t.parentNode.querySelector('.ow-switch') : null;
        if (track) track.className = 'ow-switch' + (t.checked ? ' is-on' : '');
        var on = t.checked ? '1' : '0';
        w.OwApi.post('plugin_passkey_admin_save', { enabled: on }, function (r) {
            toast((r && r.msg) || (r && r.ok ? '已保存' : '保存失败'));
            if (r && !r.ok) {
                t.checked = !t.checked;
                if (track) track.className = 'ow-switch' + (t.checked ? ' is-on' : '');
            }
        });
    }, false);

    /* ==========================================================================
       四、启动
       ========================================================================== */
    function boot() {
        if (d.querySelector('.ow-auth-form[data-mode="login"]')) { initLogin(); return; }
        if (w.OwChat && w.OwChat.cfg && w.OwChat.cfg.me) initChat();
    }
    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', boot, false);
    } else {
        boot();
    }

    w.OwPK = { errText: errText, deviceLabel: deviceLabel };
})(window, document);
