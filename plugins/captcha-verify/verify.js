/**
 * captcha-verify · 弹窗式人机验证（v1.1.0）
 *
 * 解决什么：组件原来直接嵌在登录/注册/找回表单里，占位置、抢焦点，像表单的一部分。
 * 现在改成「点动作按钮 → 弹窗验证 → 通过后自动继续原操作」：
 *   登录页  点「登 录」     → 弹窗 → 通过 → 自动提交登录
 *   注册页  点「注 册」     → 弹窗 → 通过 → 自动提交注册
 *   注册/找回页 点「发验证码」→ 弹窗 → 通过 → 自动发出验证码
 *   好友弹窗（friend_add）保持内嵌 —— 它本身已经是弹窗，不再叠加（见 main.php 文件头注释）。
 *
 * ⚠️ 只改交互时序，**判定链路一字未动**：token 仍由各家 SDK 注入所在表单的隐藏字段，
 *    服务端 captcha.enforce 仍是唯一裁判。这里做的最多算「把组件从表单里挪到遮挡层上」。
 *
 * 三个必须守住的细节：
 *   1. **组件盒子留在表单的 DOM 里**（overlay 是表单内部的子节点，只是视觉上 fixed 全屏）。
 *      把盒子搬出 form 会让 token 隐藏字段跟出表单 —— 原生提交和 OwCv.fields() 都读不到。
 *   2. **防重入**：原动作（form submit / 发码 onclick）在验证通过后要用
 *      「先拦住→放行」的方式重放，必须有守卫标记，否则死循环。
 *   3. **失败要放行按钮**：弹窗关掉时把按钮还原，用户能再点一次。
 *
 * 依赖：widget.js 必须先执行（暴露 OwCv.getScene / OwCv.onToken）；
 *       核心样式 .ow-modal-mask / .ow-modal 直接复用（登录页已加载 owlsgo.css）。
 * 兼容老浏览器：只用 var / function，不用箭头函数、let/const、fetch、dataset。
 */
(function (w, d) {
    'use strict';

    if (!w.OwCv || !w.OwCv.getScene) return;      // widget.js 没跑到（理论上不该发生）
    if (w.__owCvModalLoaded) return;
    w.__owCvModalLoaded = true;

    /** token 字段名（与 widget.js / 服务端 owCVVerify 三处一致） */
    var FIELDS = ['cf-turnstile-response', 'cap-token', 'vaptcha_token', 'vaptcha_server',
                  'lot_number', 'captcha_output', 'pass_token', 'gen_time'];

    /** 后台开着「弹窗模式」时服务端会在组件壳上打这个标记（见 main.php 的 captcha.form） */
    var ATTR_MODAL = 'data-owcv-modal';

    /**
     * 每个场景的运行态。同页只会有一个动作在等验证（登录页只有一个表单），
     * 但注册/找回页的「发验证码」与表单提交共用同一个盒子，所以状态挂在场景上。
     */
    var cur = null;      // { form, box, scene, overlay, cont, onToken, tokenTimer }

    /* ---------- 小工具 ---------- */

    function $(id) { return d.getElementById(id); }

    function text(el, s) { if (el) el.textContent = s || ''; }

    /** 盒子所在表单的隐藏 token 字段有没有值（含 SDK 后插进来的字段） */
    function hasToken(form) {
        if (!form) return false;
        for (var i = 0; i < FIELDS.length; i++) {
            var els = form.querySelectorAll('[name="' + FIELDS[i] + '"]'), j;
            for (j = 0; j < els.length; j++) if (els[j].value) return true;
        }
        return false;
    }

    /* ---------- 弹窗结构 ---------- */

    /**
     * 找（或建）场景的弹窗容器。
     * 容器默认挂在 form 内（见文件头第 1 条），但**以 id 为唯一准绳**：
     * 先查文档里已有的同 id 节点（含历史遗留的重复节点，一并清掉），
     * 找不到才新建 —— 连续「打开→取消→再打开」的路径上踩过重复节点的坑：
     * 第二次拿到的是新节点、读状态的却是旧节点，表现成「弹窗没出来」。
     */
    function overlayOf(ctx) {
        var id = 'owCvOv-' + ctx.scene;
        var exists = d.getElementById(id);
        if (exists) {
            d.querySelectorAll ? purgeDup(id, exists) : null;
            if (exists.parentNode) return exists;
            // 节点还在、但已脱离文档（历史遗留）：重新挂回表单
            ctx.form.appendChild(exists);
            return exists;
        }
        var ov = d.createElement('div');
        ov.className = 'ow-modal-mask';
        ov.id = id;
        ov.style.display = 'none';
        ov.innerHTML = '<div class="ow-modal ow-cv-modal" role="dialog" aria-modal="true">'
            + '<h3>安全验证</h3>'
            + '<div class="ow-cv-slot"></div>'
            + '<p class="ow-cv-mtip">通过验证后自动继续，无需再点按钮。</p>'
            + '<div class="ow-modal-actions"><button type="button" class="ow-btn ow-btn-ghost" data-owcv-cancel>取消</button></div>'
            + '</div>';
        ctx.form.appendChild(ov);
        ctx.overlay = ov;

        // 点遮罩空白处关闭（点弹窗内部不关）；取消按钮同理
        ov.onclick = function (e) {
            if (e.target === ov) close();
        };
        var cancel = ov.querySelector('[data-owcv-cancel]');
        if (cancel) cancel.onclick = function () { close(); };
        return ov;
    }

    /** 清理历史遗留的同 id 重复节点（正常情况下没有；有则说明上一轮创建时被绕过过） */
    function purgeDup(id, keep) {
        var all = d.querySelectorAll('[id="' + id + '"]'), i;
        for (i = all.length - 1; i >= 0; i--) {
            if (all[i] !== keep && all[i].parentNode) all[i].parentNode.removeChild(all[i]);
        }
    }

    function open(ctx) {
        var ov = overlayOf(ctx);
        var slot = ov.querySelector('.ow-cv-slot');
        // 盒子搬进弹窗（首次之后它就一直住这里；overlay 隐藏在 form 里，不影响布局）
        if (slot.firstChild !== ctx.box) slot.appendChild(ctx.box);
        ctx.box.style.display = '';                   // 清掉服务端首屏的 display:none（弹窗模式）
        ov.style.display = '';
        // ⚠️ 必须等弹窗可见后再让组件补渲染：Turnstile 会跳过不可见容器，
        //    首屏藏着的组件在有些页面（注册/找回）会被它整块跳过 —— 容器里永远没有
        //    iframe、也没有 token，弹窗就一直等着（实测踩到、登录页侥幸不受影响）。
        //    ensureTurnstile 幂等：已渲染过 / 非 turnstile / 容器仍不可见都会安静返回。
        if (w.OwCv.ensureTurnstile) w.OwCv.ensureTurnstile(ctx.box);
        resetIfSpent(ctx);                            // 上一枚已消费且组件没续写 → 按需重解
        ctx.box.classList.add('ow-cv-wait');
        text(ov.querySelector('.ow-cv-mtip'), '通过验证后自动继续，无需再点按钮。');
    }

    /**
     * 同一页面上第二次用同一枚组件时（注册页：先提交注册、再点发码），
     * token 已被上一轮核销、组件却没有自动续写 —— 观察器干等。
     * 处理：若这个表单消费过 token、且此刻字段是空的，就 reset 一次让组件重新解题。
     * 只是把 SDK 自己的重解按钮按一下，不碰判定链路。
     */
    function resetIfSpent(ctx) {
        var form = ctx.form;
        if (!form.__owCvSpent) return;                // 本页从未消费过：组件状态本来就干净
        if (hasToken(form)) return;                   // 已有新 token（SDK 自己续上了）
        if (ctx.box.getAttribute('data-owcv-provider') !== 'turnstile') return;
        if (!w.turnstile || typeof w.turnstile.reset !== 'function') return;
        var cf = ctx.box.querySelector('.cf-turnstile');
        if (cf) { try { w.turnstile.reset(cf); } catch (e) { /* 实例还没就绪：忽略 */ } }
        form.__owCvSpent = 0;
    }

    /** 关闭弹窗并把等待中的动作放行（按钮还原成可点） */
    function close() {
        if (!cur) return;
        var c = cur;
        cur = null;
        stopWatch();
        // 按 id 关：不信任缓存的节点引用（连续开关路径上出现过引用与文档实际节点不同步）
        var ov = c.overlay || d.getElementById('owCvOv-' + c.scene);
        if (ov) ov.style.display = 'none';
        c.box.classList.remove('ow-cv-wait');
        if (c.cont && c.cont.btn) c.cont.btn.disabled = c.cont.btnWasDisabled ? true : false;
    }

    /* ---------- token 观察 ---------- */

    /**
     * 两条通道都留着：
     *   ① widget.js 的 OwCv.onToken —— Turnstile/Cap/VAPTCHA/Geetest 完成时的主动回调；
     *   ② 轮询表单里的 token 字段 —— 回调拿不到时的兜底（也是「SDK 直接在隐藏字段写值」型
     *      provider 唯一的完成信号）。
     * 交付「继续」只可能发生一次（once 守卫）。
     *
     * ⚠️ 定时器句柄必须用**模块级单例**，不能挂在场景上下文上：
     *    连续「打开→取消→再打开」时（注册页的发码按钮就是这条路径），
     *    取消时的 stopWatch 里 cur 可能已被下一轮 gate 换了人，
     *    于是**清错了人**：新周期的定时器被清掉、旧周期的还在空转，
     *    表现成「弹窗开着、token 也写了，但永远不继续」。实测踩到过。
     */
    var watchTimer = null;

    function startWatch() {
        if (!cur) return;
        var c = cur;
        w.OwCv.onToken = function (box) {
            if (cur && cur !== c) return;
            if (!box || box === c.box) fire();
        };
        stopTimer();
        var t0 = (new Date()).getTime();
        watchTimer = setInterval(function () {
            if (cur !== c) { stopTimer(); return; }
            if ((new Date()).getTime() - t0 > 120000) stopTimer();   // 两分钟没解出来就停轮询
        }, 500);
    }

    function stopTimer() {
        if (watchTimer) { clearInterval(watchTimer); watchTimer = null; }
    }

    function stopWatch() {
        stopTimer();
        w.OwCv.onToken = function () {};              // 收回通知位（下次 startWatch 再装）
    }

    /** 验证已通过：把等待中的动作放行 */
    function fire() {
        if (!cur || cur._fired) return;
        var c = cur;
        c._fired = 1;
        stopWatch();
        text(c.overlay && c.overlay.querySelector('.ow-cv-mtip'), '验证通过，正在继续…');
        // 留一拍：给 SDK 写完字段的时间（Turnstile 的 callback 早于字段可见的时序有过先例）
        setTimeout(function () {
            if (cur === c) {                          // 用户没在此期间取消
                var cont = c.cont;
                close();
                if (cont) cont.run();
            }
        }, 60);
    }

    /* ---------- 表单 / 按钮接管 ---------- */

    /**
     * 计算某场景此刻是否要弹窗。返回 null 表示不管（未接管 / 内嵌模式 / 盒子不存在）。
     * 场景 → 需要弹窗的动作：
     *   login    表单提交
     *   register 表单提交 + 「发验证码」
     *   reset    仅「发验证码」（提交由邮箱验证码把关，服务端那道闸门就挂在发码上；
     *            这里再弹一次反而会因 token 一次性而把自己锁死，见 index.php 对应注释）
     *   friend_add 内嵌，不接管
     */
    function detect(scene) {
        var form = d.querySelector('.ow-auth-form' + (scene === 'login' ? '[data-mode="login"]'
            : scene === 'register' ? '[data-mode="register"]'
            : scene === 'reset' ? '[data-mode="reset"]' : ''));
        if (!form) return null;
        var box = w.OwCv.getScene(scene);
        if (!box) return null;                        // 组件没渲染（未接管 / 免验证 / 配置不全）
        if ((box.getAttribute(ATTR_MODAL) || '0') !== '1') return null;   // 后台选了内嵌
        return { form: form, box: box, scene: scene };
    }

    /** 拦住 → 弹窗 → 通过后 run()。同场景已在等（弹窗开着）时直接返回，防重入。 */
    function gate(ctx, cont) {
        if (cur) return true;                         // 已经有动作在等验证：吞掉这次点击
        cur = ctx;
        cur.cont = cont;
        open(ctx);
        startWatch();
        return true;
    }

    function bindSubmit(scene) {
        var ctx = detect(scene);
        if (!ctx) return;
        var form = ctx.form, btn = form.querySelector('button[type="submit"]');
        if (!btn) return;
        /**
         * 运行时包装 form.onsubmit（OwAuth / twofa 注册在它上面）。
         * ⚠️ 为什么不用 addEventListener + preventDefault：
         *    preventDefault 只取消「浏览器的默认提交」，**不阻止事件继续传播** ——
         *    OwAuth 的 onsubmit 照样跑，照样把 login 请求发出去（实测：弹窗开了、
         *    请求也发了，等于验证根本没拦住登录）。必须把自己的逻辑放**前面**并短路。
         * 通过验证后的放行也走这条链：__owCvPass=1 时直接调原 onsubmit，
         * 与用户手动点按钮完全同路径。
         */
        var orig = form.onsubmit;
        form.onsubmit = function (e) {
            if (form.__owCvPass) return orig ? orig.call(form, e) : true;
            // 拦住：取消默认提交 + 不调原处理器（原处理器的活留到验证通过后）
            if (!e) e = w.event || { preventDefault: function () {}, returnValue: true };
            if (e.preventDefault) e.preventDefault(); else e.returnValue = false;
            if (!cur) {
                cur = ctx;
                cur.cont = {
                    btn: btn, btnWasDisabled: btn.disabled,
                    run: function () { form.__owCvPass = 1; fireOrig(ctx, orig); }
                };
                open(ctx);
                startWatch();
            }
            return false;
        };
    }

    /**
     * 放行后立刻把 token 字段清空。
     *
     * ⚠️ 为什么可以（也必须）在这里清：原处理器（OwAuth 的 AJAX 提交）是**同步**遍历
     *    form.elements 收值的，它返回时 token 已经被抄进请求数据 —— 此刻字段的使命结束。
     *    留着它有两种坏结果：① 登录失败后重试，观察器一进弹窗就看到旧值、立刻误判
     *    「已通过」→ 带着已被核销的废 token 提交（必然再失败）；② 用户第二次打开
     *    弹窗时组件还没重解，弹窗就已经自动关掉。
     *    在字段清空后新写进来的 token（SDK 正常续写）不会被误清 —— 清的是「这一刻」的值。
     */
    function consumeFields(form) {
        var i, j;
        for (i = 0; i < FIELDS.length; i++) {
            var els = form.querySelectorAll('[name="' + FIELDS[i] + '"]');
            for (j = 0; j < els.length; j++) els[j].value = '';
        }
    }

    /**
     * 验证通过后重放原提交：调回被我们包住的那个原处理器（不触发浏览器的默认提交）。
     *
     * ⚠️ 顺序：**先放行、后清字段**。反过来在放行之前清，登录请求就会带着空 token 发出去。
     * ⚠️ 放行是**一次性**的：用完就把 __owCvPass 归零，下一次提交必须重新过弹窗。
     */
    function fireOrig(ctx, orig) {
        var form = ctx.form;
        var e = { preventDefault: function () {}, returnValue: true, target: form, type: 'submit' };
        try {
            if (orig) orig.call(form, e);      // ① 放行（此刻 token 还在字段里）
        } catch (err) {
            /* 原处理器抛错时不再二次尝试：它自己会写错误提示 */
        }
        consumeFields(form);                   // ② 值已被收走 → 清掉
        form.__owCvSpent = 1;                  // ③ 这页这副表单消费过 token（再开窗要按需重解）
        form.__owCvPass = 0;                   // ④ 放行一次性
    }

    /**
     * 接管「发验证码」按钮（注册 / 找回页）。
     *
     * ⚠️ 用 **addEventListener 监听 click**，不包装 onclick。原因：
     *    OwAuth 的按钮逻辑挂在 onclick 上，而「换掉 onclick、通过后 btn.click()
     *    再触发它」这条链路太脆（实测出现过「点了、原 handler 也调了，但请求没发」，
     *    排查成本极高）。改成自监听：点击先把默认行为按住，验证通过后
     *    直接调原 onclick —— 逻辑一条直线，失败时按钮状态也由我们负责还原。
     *    监听器比 onclick 先跑（先注册的监听器先于标签属性），所以不必担心被抢。
     */
    function bindSendCode(scene) {
        var ctx = detect(scene);
        if (!ctx) return;
        var btn = ctx.form.querySelector('[data-sendcode]');
        if (!btn) return;
        var orig = btn.onclick;
        if (!btn.addEventListener) return;    // 老内核：维持内嵌形态（见 detect 注释）
        btn.removeAttribute && btn.removeAttribute('onclick');
        btn.onclick = null;
        btn.addEventListener('click', function (e) {
            if (e && e.preventDefault) e.preventDefault();
            gate(ctx, {
                btn: btn,
                btnWasDisabled: btn.disabled,
                run: function () {
                    if (orig) {
                        try { orig.call(btn, e); }
                        catch (err) { /* 原处理器自会写错误提示 */ }
                    }
                }
            });
        }, false);
    }

    /* ---------- 启动 ---------- */

    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
    function boot() {
        bindSubmit('login');
        bindSubmit('register');
        bindSendCode('register');
        bindSendCode('reset');
        // 找回密码页的表单提交**不**弹（见 detect 注释）；reset 页若没有发码按钮（未开邮箱验证），
        // 上面两个 bind 都会静默跳过，页面维持原样。
    }
})(window, document);
