/**
 * captcha-verify · 前端组件（v1.3.54）
 *
 * 只负责「把组件渲染出来并让它把 token 注进表单」。四家的 SDK 都会自己往表单里
 * 放隐藏字段（Turnstile: cf-turnstile-response、Cap: cap-token、VAPTCHA: vaptcha_token
 * + vaptcha_server、Geetest: 本文件补的四个），所以这里不搬运 token ——
 * 真正的判定在服务端 `captcha.enforce`，前端做任何事都不能替代它。
 *
 * 登录页必须引入插件 JS 包（?action=assets&type=js），否则组件不会出现。
 */
(function (w, d) {
    'use strict';

    /** 各家 SDK 注入表单的原生字段名（服务端 owCVVerify 读的就是这些） */
    var CV_FIELDS = ['cf-turnstile-response', 'cap-token', 'vaptcha_token', 'vaptcha_server',
                     'lot_number', 'captcha_output', 'pass_token', 'gen_time'];

    /**
     * v1.3.63：发验证码是 AJAX，不像表单提交那样自动带上隐藏字段，
     * 所以要显式取出来拼进请求；而 token 是**一次性**的，用过一次必须清掉，
     * 否则下一次点「发验证码」会拿已核销的 token 再赌一次，必然被拒。
     * 定义在「有没有组件」的提前返回之前：没有组件的页面也要能安全调用。
     */
    w.OwCv = {
        fields: function (form) {
            var out = {}, i, els = form ? form.querySelectorAll('input[name]') : [];
            for (i = 0; i < els.length; i++) {
                if (CV_FIELDS.indexOf(els[i].name) >= 0 && els[i].value) out[els[i].name] = els[i].value;
            }
            return out;
        },
        reset: function (form) {
            if (!form) return;
            var i, els = form.querySelectorAll('input[name]');
            for (i = 0; i < els.length; i++) {
                if (CV_FIELDS.indexOf(els[i].name) >= 0) els[i].value = '';
            }
            // Turnstile 有官方 reset()；另三家只能靠清字段逼用户重新解一次
            if (w.turnstile && typeof w.turnstile.reset === 'function') {
                try { w.turnstile.reset(); } catch (e) { /* 组件还没渲染出来时忽略 */ }
            }
        }
    };

    /**
     * v1.1.0：弹窗模式（verify.js）要拿到「某个场景的组件盒子」才能把它搬进弹窗。
     * 盒子在初始化时按 data-owcv-scene 登记；同场景只应有一个（页面上的场景互斥）。
     * get 返回 null 表示该场景此刻没有组件（未接管 / friend_free 免验证额度内）。
     */
    var owCvRefs = {};
    w.OwCv.getScene = function (scene) {
        return owCvRefs[scene] || null;
    };
    /** v1.1.0：各家「用户完成验证」的通知位，由 verify.js 覆盖（同一时刻只会有一个弹窗在等）。
     *  拿不到回调也没关系 —— 弹窗侧的 token 观察器（verify.js watchToken）会兜底；
     *  这里的回调只是让「自动继续」更快出现。 */
    w.OwCv.onToken = function () {};

    /**
     * v1.1.0：确保 Turnstile 组件在**可见容器**里完成渲染。
     *
     * 为什么必须显式调用：Turnstile 的 implicit 渲染（api.js 自动扫 .cf-turnstile）会
     * **跳过不可见容器** —— 弹窗模式首屏把组件藏在 display:none 里，注册/找回页的
     * 组件就这样被跳过：容器里只有隐藏字段、没有 iframe，也不会有任何 token 写入。
     * 表现是「弹窗开着、永远等不到验证通过」。登录页侥幸能用的原因是它上面有别的
     * 组件（发码/找回共用页签）先被渲染过，一旦页面结构不同就翻车。
     * 解法：弹窗打开、容器可见之后，对还没渲染过的 .cf-turnstile 调一次显式 render。
     * 幂等：容器里已有 iframe 就跳过（重复 render 会被 Turnstile 拒绝）。
     */
    function ensureTurnstile(box) {
        if (!box || box.getAttribute('data-owcv-provider') !== 'turnstile') return;
        var holder = box.querySelector('.ow-cv-box');
        if (!holder) return;
        // ⚠️ 容器不可见时不渲染（渲染了也会被 Turnstile 当成 skipped，白忙还留错标记）——
        //    弹窗打开后容器可见，这里才真正动手。offsetParent 为 null = 自身或祖先被隐藏。
        if (holder.offsetParent === null) return;
        var els = holder.querySelectorAll('.cf-turnstile'), pending = [];
        for (var i = 0; i < els.length; i++) {
            if (!els[i].querySelector('iframe') && !els[i].getAttribute('data-owcv-rendered')) pending.push(els[i]);
        }
        if (!pending.length) return;
        var doRender = function () {
            for (var k = 0; k < pending.length; k++) {
                var opts = {
                    callback: function () { w.OwCv.onToken(box); },
                    'error-callback': function () { tip(box, '验证组件出错，请刷新页面重试'); }
                };
                var sk = pending[k].getAttribute('data-sitekey');
                if (sk) opts.sitekey = sk;
                try {
                    w.turnstile.render(pending[k], opts);
                    pending[k].setAttribute('data-owcv-rendered', '1');
                } catch (e) { /* 已渲染过：忽略 */ }
            }
        };
        if (w.turnstile && typeof w.turnstile.render === 'function') { doRender(); return; }
        // 库还没加载好：轮询等它出现（最多 10 秒），出现即渲染
        var n = 0;
        var t = setInterval(function () {
            if (w.turnstile && typeof w.turnstile.render === 'function') { clearInterval(t); doRender(); return; }
            if (++n > 20) clearInterval(t);
        }, 500);
    }
    w.OwCv.ensureTurnstile = ensureTurnstile;

    /** 组件盒子初始化序号（给 VAPTCHA 的临时 id 用，跨 mount 也要唯一） */
    var owCvSeq = 0;

    /** 同一个 URL 只加载一次（页面上有多个表单时不会重复注入 script） */
    var loaded = {};
    function loadScript(url, cb) {
        if (!url) { if (cb) cb(false); return; }
        if (loaded[url]) { if (cb) loaded[url].push(cb); return; }
        loaded[url] = cb ? [cb] : [];
        var s = d.createElement('script');
        s.src = url;
        s.async = true;
        s.onerror = function () {
            var list = loaded[url] || [];
            loaded[url] = false;                 // 标记失败：不再重复尝试，也不回调成功
            for (var i = 0; i < list.length; i++) list[i](false);
        };
        s.onload = function () {
            var list = loaded[url] || [];
            for (var i = 0; i < list.length; i++) list[i](true);
        };
        (d.head || d.documentElement).appendChild(s);
    }

    function tip(box, text) {
        var p = box.querySelector('.ow-cv-tip');
        if (p) p.textContent = text || '';
    }

    /**
     * token 字段该写进哪个容器：优先最近的 <form>；
     * 弹窗这类没有 form 的场合退回标了 data-owcv-host 的容器（加好友弹窗就是 div）。
     * 只认 form 的话，弹窗里的 VAPTCHA / 极验会把 token 写到页面别处或干脆丢掉。
     */
    function hostOf(box) {
        if (!box || !box.closest) return null;
        return box.closest('form') || box.closest('[data-owcv-host]');
    }

    /** 给宿主容器一个 id，供 VAPTCHA 的 renderTokenInput(selector) 使用 */
    function formSelector(box, n) {
        var f = hostOf(box);
        if (!f) return null;
        if (!f.id) f.id = 'owCvForm-' + n;
        return '#' + f.id;
    }

    /** 初始化一个组件盒子。幂等：同一个盒子只渲染一次（mount 可以被反复调用） */
    function initBox(box) {
        if (!box || box._owcvDone) return;
        box._owcvDone = 1;
        var n = ++owCvSeq;
        var provider = box.getAttribute('data-owcv-provider') || '';
        // v1.1.0：登记场景 → 盒子，供弹窗模式取用（同场景重复出现时后一个覆盖前一个）
        var scene = box.getAttribute('data-owcv-scene') || '';
        if (scene) owCvRefs[scene] = box;
        var holder = box.querySelector('.ow-cv-box');
        if (!holder) return;

        if (provider === 'turnstile') {
            // 弹窗模式下这一步基本是空转（容器还藏着，ensureTurnstile 会直接返回）；
            // 真正的渲染发生在弹窗打开、容器可见之后（verify.js 调 OwCv.ensureTurnstile）。
            // 非弹窗（内嵌）场景则在这里就地渲染。
            loadScript('https://challenges.cloudflare.com/turnstile/v0/api.js', function (ok) {
                if (!ok) tip(box, '人机验证组件加载失败，请刷新页面');
                else ensureTurnstile(box);
            });
            return;
        }

        if (provider === 'cap') {
            loadScript(box.getAttribute('data-owcv-cap-script'), function (ok) {
                if (!ok) tip(box, '人机验证组件加载失败，请检查后台「组件脚本地址」');
            });
            // v1.1.0：Cap 在元素上派发 solve 事件（token 同时会写进表单字段）；
            // 挂不上也只是慢半拍 —— 弹窗侧的 token 观察器兜底
            var cw = box.querySelector('cap-widget');
            if (cw && cw.addEventListener) {
                cw.addEventListener('solve', function () { w.OwCv.onToken(box); });
            }
            return;
        }

        if (provider === 'vaptcha') {
            var vid = box.getAttribute('data-owcv-vid') || '';
            if (!vid) { tip(box, '未配置 VAPTCHA 验证单元 VID'); return; }
            var sel = formSelector(box, n);
            loadScript('https://v-cn.vaptcha.com/v3.js', function (ok) {
                if (!ok || typeof w.vaptcha !== 'function') { tip(box, 'VAPTCHA 脚本加载失败'); return; }
                w.vaptcha({
                    vid: vid, mode: 'click', scene: 1,
                    container: '#' + holder.id, area: 'auto'
                }).then(function (obj) {
                    obj.render();
                    // 注入 vaptcha_token / vaptcha_server 两个隐藏字段到本表单
                    if (sel) obj.renderTokenInput(sel);
                    obj.listen('pass', function () {
                        tip(box, '');
                        w.OwCv.onToken(box);          // v1.1.0：弹窗模式据此自动继续
                    });
                    obj.listen('error', function () { tip(box, '验证失败，请重试'); });
                }, function () { tip(box, 'VAPTCHA 初始化失败，请检查 VID 与网络'); });
            });
            return;
        }

        if (provider === 'geetest') {
            var gid = box.getAttribute('data-owcv-gid') || '';
            if (!gid) { tip(box, '未配置极验 captcha_id'); return; }
            var form = hostOf(box);
            loadScript('https://static.geetest.com/v4/gt4.js', function (ok) {
                if (!ok || typeof w.initGeetest4 !== 'function') { tip(box, '极验脚本加载失败'); return; }
                w.initGeetest4({ captchaId: gid, product: 'float' }, function (captcha) {
                    captcha.appendTo('#' + holder.id);
                    captcha.onReady(function () { tip(box, ''); });
                    captcha.onSuccess(function () {
                        var r = captcha.getValidate() || {};
                        // Geetest 不自己写表单，四个字段得手动带上（服务端要全部四样 + 签名）
                        ['lot_number', 'captcha_output', 'pass_token', 'gen_time'].forEach(function (k) {
                            var input = form && form.querySelector('[name="' + k + '"]');
                            if (!input) {
                                input = d.createElement('input');
                                input.type = 'hidden';
                                input.name = k;
                                if (form) form.appendChild(input);
                            }
                            input.value = String(r[k] || '');
                        });
                        tip(box, '');
                        w.OwCv.onToken(box);          // v1.1.0：弹窗模式据此自动继续
                    });
                    captcha.onError(function () { tip(box, '验证失败，请重试'); });
                });
            });
        }
    }

    /** 收集 root 内真正要渲染的盒子：模板容器（data-owcv-tpl）里的那些不算 */
    function scan(root) {
        var all = (root || d).querySelectorAll('.ow-cv'), out = [], i;
        for (i = 0; i < all.length; i++) {
            if (all[i].closest && all[i].closest('[data-owcv-tpl]')) continue;
            out.push(all[i]);
        }
        return out;
    }

    /**
     * 挂载弹窗里后来注入的组件（首屏扫描只看得见当时就在 DOM 里的盒子）。
     * 幂等，可以每次打开弹窗都调一遍。
     */
    w.OwCv.mount = function (root) {
        var bs = scan(root || d), i;
        for (i = 0; i < bs.length; i++) initBox(bs[i]);
        return bs.length;
    };

    scan(d).forEach(initBox);
})(window, document);
