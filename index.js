/* ==========================================================================
   Owlsgo-Chat 前端（纯原生 ES5，无框架无依赖，兼容旧内核浏览器）
   包含：OwAuth（登录/注册/找回）、OwChat（聊天主程序，长轮询）、OwAdmin（管理后台）
   ========================================================================== */
/* ⚠️ IIFE 形参是 (w, d)：d 即 document。
   本文件是 'use strict'，任何未声明的标识符都会抛 ReferenceError 而不是静默变 undefined
   —— 曾经在主题切换里误用了未定义的 d，点击直接失效且控制台之外的表现只是"没反应"。
   新增代码请统一用 w / d，不要假设有别的全局别名。 */
(function (w, d) {
    'use strict';

    /* ---------- 纯 JS MD5（标准实现，用于 API 签名 sign = md5(key|ts|action)） ----------
       注意：MD5 的 64 个 T 常量必须逐一写死，不能用公式推导，否则与服务端 md5 不一致。 */
    function md5(s) {
        function safeAdd(x, y) {
            var lsw = (x & 0xffff) + (y & 0xffff);
            var msw = (x >> 16) + (y >> 16) + (lsw >> 16);
            return (msw << 16) | (lsw & 0xffff);
        }
        function rol(num, cnt) { return (num << cnt) | (num >>> (32 - cnt)); }
        function cmn(q, a, b, x, s, t) { return safeAdd(rol(safeAdd(safeAdd(a, q), safeAdd(x, t)), s), b); }
        function ff(a, b, c, d, x, s, t) { return cmn((b & c) | (~b & d), a, b, x, s, t); }
        function gg(a, b, c, d, x, s, t) { return cmn((b & d) | (c & ~d), a, b, x, s, t); }
        function hh(a, b, c, d, x, s, t) { return cmn(b ^ c ^ d, a, b, x, s, t); }
        function ii(a, b, c, d, x, s, t) { return cmn(c ^ (b | ~d), a, b, x, s, t); }
        function cycle(x, k) {
            var a = x[0], b = x[1], c = x[2], d = x[3];
            a = ff(a, b, c, d, k[0], 7, -680876936);    d = ff(d, a, b, c, k[1], 12, -389564586);
            c = ff(c, d, a, b, k[2], 17, 606105819);    b = ff(b, c, d, a, k[3], 22, -1044525330);
            a = ff(a, b, c, d, k[4], 7, -176418897);    d = ff(d, a, b, c, k[5], 12, 1200080426);
            c = ff(c, d, a, b, k[6], 17, -1473231341);  b = ff(b, c, d, a, k[7], 22, -45705983);
            a = ff(a, b, c, d, k[8], 7, 1770035416);    d = ff(d, a, b, c, k[9], 12, -1958414417);
            c = ff(c, d, a, b, k[10], 17, -42063);      b = ff(b, c, d, a, k[11], 22, -1990404162);
            a = ff(a, b, c, d, k[12], 7, 1804603682);   d = ff(d, a, b, c, k[13], 12, -40341101);
            c = ff(c, d, a, b, k[14], 17, -1502002290); b = ff(b, c, d, a, k[15], 22, 1236535329);

            a = gg(a, b, c, d, k[1], 5, -165796510);    d = gg(d, a, b, c, k[6], 9, -1069501632);
            c = gg(c, d, a, b, k[11], 14, 643717713);   b = gg(b, c, d, a, k[0], 20, -373897302);
            a = gg(a, b, c, d, k[5], 5, -701558691);    d = gg(d, a, b, c, k[10], 9, 38016083);
            c = gg(c, d, a, b, k[15], 14, -660478335);  b = gg(b, c, d, a, k[4], 20, -405537848);
            a = gg(a, b, c, d, k[9], 5, 568446438);     d = gg(d, a, b, c, k[14], 9, -1019803690);
            c = gg(c, d, a, b, k[3], 14, -187363961);   b = gg(b, c, d, a, k[8], 20, 1163531501);
            a = gg(a, b, c, d, k[13], 5, -1444681467);  d = gg(d, a, b, c, k[2], 9, -51403784);
            c = gg(c, d, a, b, k[7], 14, 1735328473);   b = gg(b, c, d, a, k[12], 20, -1926607734);

            a = hh(a, b, c, d, k[5], 4, -378558);       d = hh(d, a, b, c, k[8], 11, -2022574463);
            c = hh(c, d, a, b, k[11], 16, 1839030562);  b = hh(b, c, d, a, k[14], 23, -35309556);
            a = hh(a, b, c, d, k[1], 4, -1530992060);   d = hh(d, a, b, c, k[4], 11, 1272893353);
            c = hh(c, d, a, b, k[7], 16, -155497632);   b = hh(b, c, d, a, k[10], 23, -1094730640);
            a = hh(a, b, c, d, k[13], 4, 681279174);    d = hh(d, a, b, c, k[0], 11, -358537222);
            c = hh(c, d, a, b, k[3], 16, -722521979);   b = hh(b, c, d, a, k[6], 23, 76029189);
            a = hh(a, b, c, d, k[9], 4, -640364487);    d = hh(d, a, b, c, k[12], 11, -421815835);
            c = hh(c, d, a, b, k[15], 16, 530742520);   b = hh(b, c, d, a, k[2], 23, -995338651);

            a = ii(a, b, c, d, k[0], 6, -198630844);    d = ii(d, a, b, c, k[7], 10, 1126891415);
            c = ii(c, d, a, b, k[14], 15, -1416354905); b = ii(b, c, d, a, k[5], 21, -57434055);
            a = ii(a, b, c, d, k[12], 6, 1700485571);   d = ii(d, a, b, c, k[3], 10, -1894986606);
            c = ii(c, d, a, b, k[10], 15, -1051523);    b = ii(b, c, d, a, k[1], 21, -2054922799);
            a = ii(a, b, c, d, k[8], 6, 1873313359);    d = ii(d, a, b, c, k[15], 10, -30611744);
            c = ii(c, d, a, b, k[6], 15, -1560198380);  b = ii(b, c, d, a, k[13], 21, 1309151649);
            a = ii(a, b, c, d, k[4], 6, -145523070);    d = ii(d, a, b, c, k[11], 10, -1120210379);
            c = ii(c, d, a, b, k[2], 15, 718787259);    b = ii(b, c, d, a, k[9], 21, -343485551);

            x[0] = safeAdd(a, x[0]); x[1] = safeAdd(b, x[1]);
            x[2] = safeAdd(c, x[2]); x[3] = safeAdd(d, x[3]);
        }
        function blk(s) {
            var out = [], i;
            for (i = 0; i < 64; i += 4) {
                out[i >> 2] = s.charCodeAt(i) + (s.charCodeAt(i + 1) << 8) +
                    (s.charCodeAt(i + 2) << 16) + (s.charCodeAt(i + 3) << 24);
            }
            return out;
        }
        function hex(n) {
            var s2 = '', i, v;
            for (i = 0; i < 4; i++) {
                v = (n >> (i * 8)) & 0xff;
                s2 += ('0' + v.toString(16)).slice(-2);
            }
            return s2;
        }
        var str = String(s == null ? '' : s), n, i, tail;
        try { str = unescape(encodeURIComponent(str)); } catch (e) { /* 旧内核降级：按原串处理 */ }
        n = str.length;
        var state = [1732584193, -271733879, -1732584194, 271733878];
        for (i = 64; i <= n; i += 64) cycle(state, blk(str.substring(i - 64, i)));
        str = str.substring(i - 64);
        tail = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
        for (i = 0; i < str.length; i++) tail[i >> 2] |= str.charCodeAt(i) << ((i % 4) << 3);
        tail[i >> 2] |= 0x80 << ((i % 4) << 3);
        if (i > 55) { cycle(state, tail); for (i = 0; i < 16; i++) tail[i] = 0; }
        tail[14] = n * 8;
        tail[15] = Math.floor(n / 0x20000000);
        cycle(state, tail);
        return hex(state[0]) + hex(state[1]) + hex(state[2]) + hex(state[3]);
    }

    /* ---------- 通用工具 ---------- */
    function $(id) { return document.getElementById(id); }

    /**
     * 开关（State 按钮）HTML 生成器 —— 通用样式轮子（v1.1.5）。
     *
     * 前后台与插件共用同一套结构与样式，勿各处手写：
     *   <div class="ow-switch-row">
     *     <span class="ow-switch-label">标签文字</span>
     *     <input type="checkbox" class="ow-switch-input" id="...">   ← 真实状态载体，可被表单直接读取
     *     <label class="ow-switch is-on" for="..."></label>            ← 视觉轨道（唯一可点区域）
     *     <p class="ow-switch-hint">提示文字（可省略）</p>
     *   </div>
     *
     * 用原生 checkbox 承载状态：可被 FormData 收集、可 Tab 聚焦，
     * 视觉完全交给 .ow-switch（bindSwitches 只做 class 同步）。
     *
     * v1.2.52：⚠️ **只有 .ow-switch（轨道本身）可点**。
     *   之前整行是 `<label for=id>` —— 浏览器原生行为会让「点击行内任何位置
     *   （标签文字、右边空白、甚至 hint）」都切换开关，与「看起来只有那个
     *   44×26 的轨道能点」的预期不符：用户想在空白处点一下什么也不做，却把开关拨了。
     *   现在行容器改成 div（不再关联 for），for 只挂在轨道上：
     *     · 点轨道 → 切换（label for 生效）
     *     · 点文字 / 行空白 / hint → 无反应
     *     · 键盘：Tab 到 checkbox + 空格仍可切换（focus-visible 描边靠
     *       `.ow-switch-input:focus-visible + .ow-switch`，所以 input 必须**紧跟在轨道之前**）
     *   改结构时千万别把 input 挪进 .ow-switch 里面 —— 上面那条相邻选择器会失效，
     *   而旧内核不支持 :has()，没有等价写法。
     *
     * @param {string} id      input 的 id，绑定与读取都用它
     * @param {string} label   左侧标签文字
     * @param {boolean} on     初始状态
     * @param {string} [hint]  下方灰色提示文字，可省略
     * @param {boolean} [disabled] 是否禁用
     */
    function switchHtml(id, label, on, hint, disabled) {
        return '<div class="ow-switch-row' + (disabled ? ' is-disabled' : '') + '">'
            + '<span class="ow-switch-label">' + esc(label) + '</span>'
            + '<input type="checkbox" class="ow-switch-input" id="' + esc(id) + '"' + (on ? ' checked' : '') + (disabled ? ' disabled' : '') + '>'
            + '<label class="ow-switch' + (on ? ' is-on' : '') + '" for="' + esc(id) + '"></label>'
            + (hint ? '<p class="ow-switch-hint">' + esc(hint) + '</p>' : '')
            + '</div>';
    }

    /* ==================================================================
       表单说明气泡（v1.2.45）
       把「输入框/选择框/开关下方的灰色说明文字」收进标题右侧的圆形 ⓘ，
       悬浮才显示。目的：说明一多，每个表单项都被撑高一行，界面看着参差不齐。

       为什么做成「自动增强」而不是逐个改 HTML：
         · 前后台 + 十几个插件的表单都是手写 HTML 字符串，逐个改要动几十处、极易漏；
         · 自动增强对新写的插件同样生效 —— 只要按约定写说明段落（12px 灰字），
           不用记得调任何函数。
       因此这里是「扫 DOM + 搬文字」，不是「生成控件」。
       ================================================================== */
    var OwTip = (function () {

        /**
         * 造一个圆形 ⓘ 气泡。
         *
         * v1.2.49：**不再写 title 属性**。
         *   之前 title 是当「CSS 气泡被父级 overflow 裁掉」的兜底，
         *   但那是 v1.2.47 之前的困境 —— 兜底和正式气泡同时存在，结果**屏幕上出现两个提示**
         *   （浏览器原生黄/白框+ 我们的深灰气泡，鼠标移开还在，用户截图报的就是这个）。
         *   v1.2.48 已用 fixEdge() 实测边界彻底解决裁切，兜底使命完成。
         *
         *   保留 aria-label：无障碍语义需要，且**不产生任何视觉提示**。
         *   真的裁到了（fixEdge 也失效的极端情况），宁可少一个提示也不要两个。
         */
        function badge(text) {
            var b = document.createElement('span');
            b.className = 'ow-tip';
            b.setAttribute('aria-label', text);
            b.innerHTML = '<i>i</i><span class="ow-tip-box"></span>';
            b.getElementsByClassName('ow-tip-box')[0].textContent = text;
            // v1.2.47：真正悬浮的那一刻再判一次越界方向。
            // 必须用 addEventListener 而不是 CSS —— 气泡 display:none 时量不到宽度，
            // 而且窗口缩放/滚动/换行后位置会变，绑一次就不准了。
            if (b.addEventListener) {
                b.addEventListener('mouseenter', function () { fixEdge(b); }, false);
            }
            return b;
        }

        /* ---------- v1.2.47：气泡越界纠正 ----------
           气泡默认 left:50% + translateX(-50%)（居中在 ⓘ 上方）。
           ⓘ 一般在 label 文字右侧靠中间，气泡宽 max 260px，向左必然溢出容器。

           之前只用 CSS `.ow-form-row > .ow-form-item:last-child` 靠右兜底，
           但**独立 .ow-form-item（不在 .ow-form-row 里）走不到那条规则** ——
           实测「个人设置」的「昵称」气泡 left=463、弹窗左边界 530，**左边被裁掉 67px**，
           「第三方授权」裁掉 28px。后台多列表单同理。

           为什么必须用 JS：
             · CSS 拿不到「气泡实际宽度」（max-content 运行时才知道）；
             · **ⓘ 徽章本身不是 14px 宽** —— label 是 display:flex，
               .ow-tip  作为 flex 项被拉伸到整行剩余宽度（实测 240px），
               所以任何 `left/right: %` 或 `right: -6px` 都是相对这个假宽度算的，
               实测会得到 left=-240px → 气泡跑到 x=346，比不修还糟。

           所以：mouseenter 时量一次真实尺寸，按「容器可用区」算出绝对 left，
           写进 style.left（px），并把宽度也钳到可用宽度内。
           · 只在 :hover 时量（display:none 时量不到尺寸）；
           · 每次 mouseenter 重新判定 —— 窗口缩放/滚动/换行后位置会变；
           · 写 style.left 而非加类 —— 值本来就是算出来的，类表达不了。 */
        function fixEdge(b) {
            var box = b && b.querySelector ? b.querySelector('.ow-tip-box') : null;
            if (!box) return;
            // 找最近的裁剪祖先（overflow 非 visible），它才是真正的边界
            var edge = null, node = b.parentNode;
            while (node && node.nodeType === 1) {
                var ov = '';
                try { ov = w.getComputedStyle(node).overflowX; } catch (e) { ov = ''; }
                if (ov && ov !== 'visible') { edge = node; break; }
                node = node.parentNode;
            }
            var host = edge || document.body;
            var hb = host.getBoundingClientRect();
            var ecs = w.getComputedStyle(host);
            // 可用区 = 容器内容盒（扣掉 padding），气泡不该压到 padding 上
            var availL = hb.left + (parseFloat(ecs.paddingLeft) || 0) + 4;
            var availR = hb.right - (parseFloat(ecs.paddingRight) || 0) - 4;
            var availW = Math.max(120, availR - availL);

            // 先显示并清掉旧定位，才能量到真实宽度
            box.style.display = 'block';
            box.style.left = ''; box.style.right = ''; box.style.maxWidth = ''; box.style.transform = '';

            var bw = box.getBoundingClientRect().width;   // max-content 实宽
            // 气泡比可用区宽就先收窄（否则再对齐也会溢出）
            if (bw > availW) { box.style.maxWidth = availW + 'px'; bw = availW; }

            /* 垂直方向一起判（v1.3.81）：气泡默认开在 ⓘ 上方（CSS `bottom:20px`），
               于是**页面顶部**的字段说明必然向上出界 —— 实测「表情包」的 KLIPY 那条：
               ⓘ 在 y=192、气泡高 200，顶边算出来是 -14px，第一行直接被视口裁掉。
               只在「上面放不下、下面更宽」时翻到下方；两边都放不下就保持原样，
               不做两头都够不到的猜测（翻来翻去反而让人找不到锚点）。 */
            var bb = b.getBoundingClientRect();
            var bh = box.getBoundingClientRect().height;
            var vh = w.innerHeight || (d.documentElement ? d.documentElement.clientHeight : 0) || 0;
            var upRoom = bb.top - 8;
            var downRoom = vh - bb.bottom - 8;
            var below = false;
            if (bh > upRoom && downRoom > upRoom) {
                below = true;
                if (box.className.indexOf('ow-tip-below') < 0) box.className += ' ow-tip-below';
            } else if (box.className.indexOf('ow-tip-below') >= 0) {
                box.className = box.className.replace(' ow-tip-below', '');
            }

            /* v1.3.122：落在**内层**裁剪容器里才改固定定位（坐标按视口写）。
               个人设置这类固定弹窗的面板是 overflow:auto/hidden，气泡按 CSS 默认
               「开到 ⓘ 上方 20px」会被面板上边缘整条切掉 —— 实测「日夜模式」「第三方授权」
               两条的气泡顶边在面板上方 57px，中心点 elementFromPoint 命中的是弹窗标题 H3，
               一个字都看不见。固定定位不受祖先 overflow 裁剪（前提：祖先没有 transform，
               本站弹窗/遮罩都没用 transform，改完实测气泡矩形与算出来的一致）。
               ⚠️ 必须排除 body/html：聊天页 body 本身就是 overflow:hidden（锁滚动），
               不排除会让**全站每个 ⓘ**都改走固定定位 —— 那不是这次要修的问题。
               没有内层裁剪祖先的普通表单，行为与改动前逐字节一致。 */
            var inner = edge && edge !== document.body && edge !== d.documentElement;
            if (inner) {
                if (box.className.indexOf('ow-tip-fixed') < 0) box.className += ' ow-tip-fixed';
                box.style.top = below ? (bb.top + 20) + 'px' : 'auto';
                box.style.bottom = below ? 'auto' : (vh - bb.bottom + 20) + 'px';
            }

            var bLeft = b.getBoundingClientRect().left;  // ⓘ 左缘（气泡锚点）
            // 期望：默认左缘对齐 ⓘ，超出左边界就右移，超出右边界再回退
            var left = bLeft;
            if (left < availL) left = availL;
            if (left + bw > availR) left = availR - bw;
            if (left < availL) left = availL;
            // 固定定位时 left 就是视口坐标；绝对定位要换算回相对 ⓘ 的偏移
            box.style.left = inner ? left + 'px' : (left - bLeft) + 'px';
            box.style.right = 'auto';
            box.style.transform = 'none';

            // 箭头跟着对齐：指向 ⓘ 的中心（相对气泡左缘）
            var c = bLeft + b.offsetWidth / 2 - left;   // ⓘ 中心 - 气泡左缘
            c = Math.max(12, Math.min(c, bw - 12));     // 夹在气泡内，别跑到边上
            box.style.setProperty('--ow-tip-arrow', c + 'px');

            // 离开时清干净，避免下次内容变化/窗口缩放后沿用旧值
            if (!b.__haTipCleanup) {
                b.__haTipCleanup = function () {
                    box.style.display = ''; box.style.left = ''; box.style.right = '';
                    box.style.maxWidth = ''; box.style.transform = '';
                    box.style.top = ''; box.style.bottom = '';
                    if (box.className.indexOf('ow-tip-fixed') >= 0) {
                        box.className = box.className.replace(' ow-tip-fixed', '');
                    }
                    box.style.removeProperty('--ow-tip-arrow');
                };
                b.addEventListener('mouseleave', b.__haTipCleanup, false);
            }
        }

        /**
         * 判断一个 <p> 是不是「说明文字」。
         * 判据：无 class（或显式 ow-form-hint）+ 生效字号 ≤ 12.5px。
         * ⚠️ 必须排除带 class 的 —— 那些是有语义的块（.ow-rc-note 公开性说明、
         *    .ow-form-msg 报错、.ow-panel-empty 空态），搬走会丢信息。
         */
        function isHint(node) {
            if (!node || node.tagName !== 'P') return false;
            var cn = (node.className || '').trim();
            if (cn && cn !== 'ow-form-hint') return false;
            var fs = parseFloat(getComputedStyle(node).fontSize) || 0;
            return fs > 0 && fs <= 12.5;
        }

        /** 处理一个 .ow-form-item：把说明搬进 label 右侧的 ⓘ */
        function oneItem(item) {
            if (!item) return;
            var label = item.querySelector('label');
            if (!label || label.getElementsByClassName('ow-tip').length) return;
            var kids = item.children, i, hint = null;
            for (i = 0; i < kids.length; i++) {
                if (isHint(kids[i])) { hint = kids[i]; break; }
            }
            if (!hint) return;
            var txt = (hint.textContent || '').replace(/^\s+|\s+$/g, '');
            if (txt) {
                label.appendChild(badge(txt));
                label.className += ' ow-form-label-tip';
            }
            if (hint.parentNode) hint.parentNode.removeChild(hint);
        }

        /** 处理一个开关行（switchHtml 生成的 .ow-switch-row + p.ow-switch-hint） */
        function oneSwitch(row) {
            if (!row || (row.className || '').indexOf('ow-switch-row') < 0) return;
            if (row.getElementsByClassName('ow-tip').length) return;
            var lb = row.querySelector('.ow-switch-label');
            var hint = row.querySelector('.ow-switch-hint');
            if (!lb || !hint) return;
            var txt = (hint.textContent || '').replace(/^\s+|\s+$/g, '');
            if (txt) lb.insertAdjacentElement('afterend', badge(txt));
            if (hint.parentNode) hint.parentNode.removeChild(hint);
        }

        /** 扫描一个容器（或整篇文档） */
        function scan(root) {
            var scope = (root && root.querySelectorAll) ? root : w.document;
            if (scope.nodeType === 1) {
                if (scope.classList && scope.classList.contains('ow-form-item')) oneItem(scope);
                if (scope.classList && scope.classList.contains('ow-switch-row')) oneSwitch(scope);
            }
            if (!scope.querySelectorAll) return;
            var items = scope.querySelectorAll('.ow-form-item'), i;
            for (i = 0; i < items.length; i++) oneItem(items[i]);
            var rows = scope.querySelectorAll('.ow-switch-row');
            for (i = 0; i < rows.length; i++) oneSwitch(rows[i]);
        }

        /** 手动给某个 label 挂气泡（新代码用；已有说明段落交给 scan 即可） */
        function add(label, text) {
            if (!label || !text) return;
            label.appendChild(badge(String(text)));
            label.className += ' ow-form-label-tip';
        }

        // 自动启用：首屏 + 之后任何异步插入的表单（弹窗 / 后台 Ajax / 插件页）
        if (w.document.readyState === 'loading') {
            w.document.addEventListener('DOMContentLoaded', function () { scan(w.document); });
        } else {
            scan(w.document);
        }
        if (w.MutationObserver) {
            // ⚠️ 必须先判断「新增节点里有没有表单」再做扫描：
            //    聊天页消息区每秒都在变，无条件重扫会持续触发重排。
            new w.MutationObserver(function (recs) {
                for (var i = 0; i < recs.length; i++) {
                    var list = recs[i].addedNodes;
                    for (var j = 0; j < list.length; j++) {
                        var n = list[j];
                        if (n.nodeType !== 1) continue;
                        if ((n.classList && (n.classList.contains('ow-form-item')
                                || n.classList.contains('ow-switch-row')))
                            || n.querySelector('.ow-form-item, .ow-switch-row')) {
                            scan(n);
                            // ⚠️ 这里**不能 break**：一次 innerHTML 替换会同时加入多个
                            //    顶层节点（表单容器 + 独立表单项 + 按钮…），
                            //    扫完第一个就 break 会漏掉同一批里的其它表单项
                            //    （实测：个人设置里「昵称」挂上了气泡、「第三方授权」没挂）。
                        }
                    }
                }
            }).observe(w.document.body || w.document.documentElement, { childList: true, subtree: true });
        }

        return { scan: scan, add: add, badge: badge, fixEdge: fixEdge };
    })();

    /**
     * 绑定开关的视觉同步：监听 change，把 .ow-switch 的 is-on 跟上 checkbox。
     * 必须在元素插入 DOM 后调用（可传事件委托的容器，或单个 input）。
     * @param {Element|NodeList} scope 容器（含 checkbox）或 checkbox 本身
     */
    function bindSwitches(scope) {
        var list = [];
        if (!scope) return;
        if (scope.nodeType === 1 && scope.className && (' ' + scope.className + ' ').indexOf(' ow-switch-input ') >= 0) list.push(scope);
        else list = [].slice.call(scope.querySelectorAll ? scope.querySelectorAll('.ow-switch-input') : []);
        for (var i = 0; i < list.length; i++) {
            (function (cb) {
                var sw = cb.parentNode.querySelector('.ow-switch');
                if (!sw) return;
                var sync = function () { sw.className = 'ow-switch' + (cb.checked ? ' is-on' : ''); };
                cb.addEventListener ? cb.addEventListener('change', sync, false) : cb.attachEvent('onchange', sync);
                sync();
            })(list[i]);
        }
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    /**
     * 把任意值序列化成可安全嵌进 onclick/on* 属性里当 JS 字符串实参的字面量。
     * 用法：onclick="OwChat.userCard(1,' + jsAttr(nick) + ')" —— 注意调用点
     * **不要再自己包引号**，jsAttr 产出的是带双引号的完整字符串字面量。
     * 为什么 esc() 不够：浏览器执行 onclick 前会把属性值 HTML 解码，
     * esc() 转出的 &#39; 还原成 ' 照样闭合 JS 字符串 —— esc 在属性内嵌 JS
     * 的上下文防不住注入（此前 4 处 userCard 全靠昵称校验不含引号才没炸）。
     * JSON.stringify 先处理 JS 层（引号/反斜杠/控制符），esc 再处理 HTML 属性层。
     */
    function jsAttr(s) { return esc(JSON.stringify(String(s == null ? '' : s))); }
    function toast(msg, ms) {
        var t = $('owToast'); if (!t) return;
        // v1.3.44：提示文案过 i18n 词典（精确命中才替换）——API 返回的中文 msg
        // 也能被语言包覆盖，不用改任何接口的服务端文案。
        t.innerHTML = esc(w.OwI18n ? OwI18n.t(msg) : msg); t.style.display = 'block';
        clearTimeout(t._tm);
        t._tm = setTimeout(function () { t.style.display = 'none'; }, ms || 2200);
    }
    /* 用户 ID 展示补零：至少 3 位（001），超出 3 位按实际位数（1000 起）。
       仅影响显示，传输与存储始终是数字，后端搜索兼容 001 输入（(int) 归一）。 */
    function fmtUid(id) {
        var s = String(id == null ? 0 : id);
        var w = s.length > 3 ? s.length : 3;
        while (s.length < w) s = '0' + s;
        return s;
    }
    /** 归一化 class 字符串：去掉多余空格（增删 class 时避免累积空白） */
    function trimCls(s) { return String(s || '').replace(/\s+/g, ' ').replace(/^ | $/g, ''); }

    /**
     * 邮箱打码显示（v1.3.55，改密码弹窗里告诉用户发到哪）。
     * 留在前端的都是他自己账号的邮箱，打码不是为了防他，是为了让截一张图发给别人时
     * 不至于把自己的完整地址一起公开出去 —— 本地部分只留头两个字符，域名保留（认出是哪个邮箱要看域名）。
     */
    function owMaskEmail(s) {
        s = String(s || '');
        var at = s.lastIndexOf('@');
        if (at <= 0) return s;
        var local = s.slice(0, at), domain = s.slice(at);
        var head = local.length > 2 ? local.slice(0, 2) : local.charAt(0);
        return head + '***' + domain;
    }

    /* 枚举值中文显示（提交时仍用英文原始值，仅界面本地化） */
    // v1.1.16：'public' 的中文统一为「普通」（与后台/插件口径一致）。
    // 'public' 是 rooms.type 的**存储值**（不是 is_public），指「无密码无角色门槛」。
    var ROOM_TYPE_CN = { 'public': '普通', 'password': '密码房', 'role': '角色限定' };
    var ROLE_CN = { 'guest': '游客', 'member': '普通用户', 'vip': 'VIP', 'admin': '超级管理员' };
    function cn(map, v) { return map[v] || v; }
    function opts(map, keys, current) {
        var h = '', i;
        for (i = 0; i < keys.length; i++) {
            h += '<option value="' + esc(keys[i]) + '"' + (current === keys[i] ? ' selected' : '') + '>' + esc(cn(map, keys[i])) + '</option>';
        }
        return h;
    }

    /* ---------- 文件类型图标（v1.2.16）----------

       11 个彩色图标全部是「同一个文件外形 + 不同底色 + 不同内嵌符号」，
       外形路径完全一致，故抽成常量复用，只按类型查表取底色与符号。
       全部 viewBox 1024 实心版式，与 24 网格的 OW_SVG_PATHS 不同源，
       所以单独一套渲染函数，不塞进 OW_SVG_PATHS。 */
    var FILE_SILHOUETTE = 'M862 902c0 16.569-13.431 30-30 30H192c-16.569 0-30-13.431-30-30V122c0-16.569 13.431-30 30-30h476l194 194v616z';
    /* 右上角折角高光：11 个图标完全相同 */
    var FILE_FOLD = 'M862 286H698c-16.569 0-30-13.431-30-30V92';

    /* 类型 → { 底色, 内嵌符号 }。未命中走 unknown（灰色问号）。
       扩展名清单与后端 core/upload.php 的 EXT_MIME 保持一致。 */
    var FILE_ICON_MAP = {
        /* 压缩包：拉链 */
        archive: { c: '#7DB4FF', g: 'M289 92h70v70h-70zM359 162h70v70h-70zM289 232h70v70h-70zM359 302h70v70h-70zM289 372h70v70h-70zM289 468h140v110c0 16.569-13.431 30-30 30h-80c-16.569 0-30-13.431-30-30V468z' },
        /* 电脑端可执行文件：EXE 字样 */
        exe:    { c: '#7DB4FF', g: 'M403.76 540.84c0-44.48-13.76-75.84-73.92-75.84-54.08 0-85.44 19.52-85.44 89.6s31.36 89.6 84.16 89.6c30.08 0 56-6.08 66.24-12.16v-36.48c-11.2 6.08-36.16 12.16-57.28 12.16-30.72 0-45.12-11.2-46.72-35.84l111.36-7.04c0.96-4.8 1.6-14.08 1.6-24z m-112.96-1.28c0.96-27.2 10.56-39.68 39.04-39.68 27.2 0 31.68 15.36 31.68 34.24l-70.72 5.44zM420.4 641h54.72l36.48-59.52h1.92L549.36 641h55.04l-59.2-88.64 56-84.16h-53.76l-33.6 56.32h-1.92l-33.6-56.32h-54.4l56.32 84.48zM781.04 540.84c0-44.48-13.76-75.84-73.92-75.84-54.08 0-85.44 19.52-85.44 89.6s31.36 89.6 84.16 89.6c30.08 0 56-6.08 66.24-12.16v-36.48c-11.2 6.08-36.16 12.16-57.28 12.16-30.72 0-45.12-11.2-46.72-35.84l111.36-7.04c0.96-4.8 1.6-14.08 1.6-24z m-112.96-1.28c0.96-27.2 10.56-39.68 39.04-39.68 27.2 0 31.68 15.36 31.68 34.24l-70.72 5.44z' },
        /* 纯文本：T */
        text:   { c: '#BEBEBE', g: 'M638.17 362.94H385.82v65.17H475V703h73.99V428.11h89.18z' },
        /* 代码文件：</> */
        code:   { c: '#FFAB4E', g: 'M245.007043 548.186003m15.909903-15.909902l111.015764-111.015765q15.909903-15.909903 31.819805 0l0 0q15.909903 15.909903 0 31.819805l-111.015764 111.015765q-15.909903 15.909903-31.819805 0l0 0q-15.909903-15.909903 0-31.819805Z M292.736751 532.260544l111.015764 111.015765c8.786509 8.786509 8.787216 23.032589 0 31.819805-8.786509 8.786509-23.033296 8.786509-31.819805 0l-111.015764-111.015764C252.130437 555.293841 252.130437 541.047053 260.916946 532.260544c8.787216-8.787216 23.033296-8.786509 31.819805 0z M451.835252 687.708405m5.823428-21.733331l66.516495-248.242937q5.823429-21.733331 27.55676-15.909903l0 0q21.733331 5.823429 15.909902 27.55676l-66.516494 248.242937q-5.823429 21.733331-27.55676 15.909903l0 0q-21.733331-5.823429-15.909903-27.55676Z M779.499086 548.186057m-15.909902-15.909903l-111.015765-111.015764q-15.909903-15.909903-31.819805 0l0 0q-15.909903 15.909903 0 31.819805l111.015765 111.015764q15.909903 15.909903 31.819805 0l0 0q15.909903-15.909903 0-31.819805Z M731.769379 532.260598l-111.015765 111.015765c-8.786509 8.786509-8.787216 23.032589 0 31.819805 8.786509 8.786509 23.033296 8.786509 31.819805 0l111.015765-111.015765C772.375693 555.293894 772.375693 541.047107 763.589184 532.260598c-8.787216-8.787216-23.033296-8.786509-31.819805 0z' },
        /* 音频：音符 */
        audio:  { c: '#FF6359', g: 'M627.579498 327.776722m-7.764571 28.977775l-94.727771 353.528852q-7.764571 28.977775-36.742346 21.213204l0 0q-28.977775-7.764571-21.213203-36.742347l94.72777-353.528852q7.764571-28.977775 36.742347-21.213203l0 0q28.977775 7.764571 21.213203 36.742346Z M614.551831 328.726268c5.486288 6.671274 9.751378 12.915942 12.797461 18.733558C636.893966 365.698642 626.146831 376.906994 650.462485 404.234511c24.315654 27.327517 60.0614 26.208052 76.794976 50.59809 16.734542 24.390297 17.315177 28.648678 19.510288 52.420831 2.194145 23.771894-23.492776 74.570552-26.770242 49.91931-3.278432-24.6515-19.503267-42.787775-31.327354-73.082335-5.384355-13.797719-21.829962-19.055304-41.086699-26.765017-0.701151-0.281048-2.886169-1.380018-6.557435-3.29962a99.988 99.988 0 0 1-34.015467-29.076613L567.711128 371.924962c-10.264493-13.848523-8.838443-33.126309 3.350522-45.314888 11.479416-11.480083 30.091303-11.478933 41.570679-0.000742 0.674139 0.675497 1.315261 1.381488 1.920209 2.118161z M270.398574 649.322917a130.822 87.5 15 1 0 252.728697 67.71845 130.822 87.5 15 1 0-252.728697-67.71845Z' },
        /* 视频：胶片 */
        video:  { c: '#C076E5', g: 'M257 92h70v70h-70zM257 232h70v70h-70zM257 372h70v70h-70zM257 512h70v70h-70zM257 652h70v70h-70zM257 792h70v70h-70zM698 302h70v70h-70zM698 442h70v70h-70zM698 582h70v70h-70zM698 722h70v70h-70zM698 862h70v70h-70zM593.941 544.47l-138.743 95.568c-9.096 6.266-21.55 3.971-27.816-5.125a20 20 0 0 1-3.529-11.345V432.432c0-11.045 8.954-20 20-20a20 20 0 0 1 11.345 3.53l138.743 95.567c9.097 6.266 11.392 18.72 5.126 27.816a20 20 0 0 1-5.126 5.126z' },
        /* PPT：P */
        ppt:    { c: '#FF6359', g: 'M391.269 705h75.544V598.642h42.245c97.412 0 135.681-31.311 135.681-121.268 0-82.999-32.305-117.292-135.681-117.292H391.269V705z m75.544-166.992V419.722h32.305c50.694 0 68.586 14.91 68.586 59.143 0 45.724-17.395 59.143-68.586 59.143h-32.305z' },
        /* XLSX：X */
        xls:    { c: '#60C267', g: 'M495.93 707.9c93.59 0 130.83-35.77 130.83-102.41 0-63.21-17.64-88.69-98.98-108.29-49.98-11.76-57.82-19.6-57.82-41.65 0-28.91 16.17-36.26 54.88-36.26 28.42 0 67.62 6.86 84.28 12.25v-61.25c-18.13-6.37-48.51-12.25-87.22-12.25-78.89 0-124.46 27.44-124.46 99.47 0 60.76 21.07 81.34 93.59 98 51.94 12.74 61.74 22.05 61.74 48.02 0 30.38-14.7 40.67-59.78 40.67-34.3 0-74.97-5.88-96.04-13.72v65.17c21.07 5.88 59.29 12.25 98.98 12.25z' },
        /* Word：W */
        word:   { c: '#4895FF', g: 'M361.81 703h93.1l53.41-215.6h2.94L563.69 703h93.1l77.91-340.06h-76.93l-49.98 251.37h-2.94l-49-218.54h-86.24l-51.94 218.54h-2.94l-49-251.37h-77.91z' },
        /* PDF：PDF 字样 */
        pdf:    { c: '#FF6359', g: 'M234.64 641h48.64v-68.48h27.2c62.72 0 87.36-20.16 87.36-78.08 0-53.44-20.8-75.52-87.36-75.52h-75.84V641z m48.64-107.52v-76.16h20.8c32.64 0 44.16 9.6 44.16 38.08 0 29.44-11.2 38.08-44.16 38.08h-20.8zM437.52 418.92V641h76.16c65.28 0 108.16-25.28 108.16-111.04 0-92.48-42.88-111.04-108.16-111.04h-76.16z m48.64 180.48V459.88h24.96c40 0 60.48 12.8 60.48 70.08 0 54.08-19.52 69.44-60.48 69.44h-24.96zM801.36 461.16v-42.24H665.68V641h48.64v-86.4h80.64v-41.92h-80.64v-51.52z' },
        /* 图片（v1.3.86）：山峰 + 太阳。此前 jpg/png 作为**文件**发送时落到 unknown（灰色问号），
           而「以文件方式发送图片」这条路的意义恰恰是让人一眼看出那是张图片。 */
        image:  { c: '#FFAB4E', g: 'M440.26 397.636l152.711 269.49c4.901 8.649 1.863 19.633-6.786 24.534a18 18 0 0 1-8.874 2.34H271.889c-9.941 0-18-8.059-18-18a18 18 0 0 1 2.34-8.874l152.71-269.49c4.902-8.649 15.886-11.687 24.535-6.786a18 18 0 0 1 6.786 6.786z M633.04 497.15l96.32 169.976c4.9 8.649 1.863 19.633-6.786 24.534a18 18 0 0 1-8.875 2.34H521.061c-9.942 0-18-8.059-18-18a18 18 0 0 1 2.34-8.874L601.72 497.15c4.9-8.649 15.885-11.687 24.534-6.786a18 18 0 0 1 6.786 6.786z M695 420m-50 0a50 50 0 1 0 100 0 50 50 0 1 0-100 0Z' },
        /* 未知：问号 */
        unknown:{ c: '#BEBEBE', g: 'M474.17 591.28h59.78v-11.27c0-16.66 5.88-25.97 35.77-48.02 33.32-24.99 44.1-42.63 44.1-88.2 0-68.6-33.32-86.73-113.68-86.73-29.4 0-55.86 3.43-73.99 8.82v60.76c17.15-5.39 39.69-8.33 59.78-8.33 39.69 0 51.94 6.37 51.94 35.77 0 20.58-4.41 29.89-25.97 47.04-28.91 24.99-37.73 40.18-37.73 68.11v22.05z m30.38 114.66c34.79 0 41.65-5.88 41.65-41.16 0-34.3-6.86-40.67-41.65-40.67-35.28 0-41.65 6.37-41.65 40.67 0 35.28 6.37 41.16 41.65 41.16z' }
    };

    /* 扩展名 → 图标类型。后端 core/upload.php 的 EXT_MIME 是允许清单，
       这里覆盖得更宽（含 md/log 等），多出的类型落到 unknown 也不影响。 */
    var FILE_EXT_ICON = {
        /* 压缩包 */
        'zip': 'archive', 'rar': 'archive', '7z': 'archive', 'gz': 'archive',
        'tar': 'archive', 'bz2': 'archive', 'xz': 'archive',
        /* 电脑端可执行文件 */
        'exe': 'exe', 'msi': 'exe', 'bat': 'exe', 'cmd': 'exe', 'com': 'exe', 'scr': 'exe',
        /* 文本 / 文档 / 日志 / 数据 */
        'txt': 'text', 'log': 'text', 'md': 'text', 'rtf': 'text', 'ini': 'text', 'csv': 'text',
        /* 代码 */
        'js': 'code', 'ts': 'code', 'php': 'code', 'html': 'code', 'htm': 'code', 'css': 'code',
        'json': 'code', 'xml': 'code', 'py': 'code', 'java': 'code', 'c': 'code', 'cpp': 'code',
        'h': 'code', 'go': 'code', 'rs': 'code', 'rb': 'code', 'sh': 'code', 'sql': 'code',
        'yml': 'code', 'yaml': 'code', 'vue': 'code', 'tsv': 'code',
        /* 音频 */
        'mp3': 'audio', 'wav': 'audio', 'flac': 'audio', 'ogg': 'audio', 'm4a': 'audio',
        'aac': 'audio', 'wma': 'audio', 'amr': 'audio', 'mid': 'audio',
        /* 图片（作为文件发送时）*/
        'jpg': 'image', 'jpeg': 'image', 'png': 'image', 'gif': 'image', 'webp': 'image', 'bmp': 'image',
        /* 视频 */
        'mp4': 'video', 'webm': 'video', 'avi': 'video', 'mkv': 'video', 'mov': 'video',
        'wmv': 'video', 'flv': 'video', 'm4v': 'video', 'rmvb': 'video', 'mpg': 'video', 'mpeg': 'video',
        /* PPT */
        'ppt': 'ppt', 'pptx': 'ppt',
        /* Excel */
        'xls': 'xls', 'xlsx': 'xls',
        /* Word */
        'doc': 'word', 'docx': 'word',
        /* PDF */
        'pdf': 'pdf'
    };
    function fileIconSvg(ext) {
        var e = String(ext || '').toLowerCase().replace(/^\./, '');
        var t = FILE_ICON_MAP[FILE_EXT_ICON[e] || 'unknown'];
        return owSvgFileType(t, 34);
    }

    /* 文件消息卡片：图标 + 文件名 + 大小 + 下载（下载链接带签名，服务端再校验房间权限）
       v1.2.14：第二行去掉「ZIP · 」这类扩展名前缀（与文件图标信息重复，且挤占文件名空间）。
       v1.2.18：文件名加 data-name 存**完整名**（中间省略只改显示，不改数据源），
       并补 title —— 省略后鼠标悬停仍能看到全名。 */
    function fileCardHtml(m) {
        var info = null;
        try { info = JSON.parse(m.content); } catch (e) { info = null; }
        if (!info) return '<span class="ow-file-card">文件内容已失效</span>';
        var s = OwApi.sign('file_download');
        var dl = '?action=file_download&id=' + m.id + '&ts=' + s.ts + '&sign=' + s.sign;
        var nm = info.name || '文件';
        return '<div class="ow-file-card">'
            + '<span class="ow-file-ico">' + fileIconSvg(info.ext) + '</span>'
            + '<span class="ow-file-meta"><span class="ow-file-name" data-name="' + esc(nm)
            + '" title="' + esc(nm) + '">' + esc(nm) + '</span>'
            + '<span class="ow-file-size">' + esc(sizeText(info.size)) + '</span></span>'
            + '<a class="ow-file-dl" href="' + dl + '" title="下载">' + owSvg('download', 18) + '</a></div>';
    }

    /**
     * 图文混排消息（type=rich，v1.3.86）的解析与渲染。
     * content = {"text":"…","media":[{"url":"uploads/image/…","kind":"image|video"},…]}
     *
     * 为什么服务端校验过了这里还要逐层防：历史消息里可能有别的版本写进来的脏数据，
     * `JSON.parse` 一抛就是整条消息渲染不出来 —— 满屏空白比一张破图更难排查。
     * url 也只认本站 uploads/ 前缀：消息内容里不能出现外站地址，
     * 那等于让每个看记录的人的浏览器去加载任意 URL。
     */
    function richParts(m) {
        var out = { text: '', media: [] }, j = null, i, u, k;
        try { j = JSON.parse(m.content); } catch (e) { return out; }
        if (!j || typeof j !== 'object') return out;
        if (typeof j.text === 'string') out.text = j.text;
        if (j.media && j.media.length) {
            for (i = 0; i < j.media.length && out.media.length < 9; i++) {
                u = String((j.media[i] || {}).url || '');
                k = String((j.media[i] || {}).kind || '');
                if (!/^uploads\/[a-z0-9_.\/-]+$/i.test(u)) continue;
                if (k !== 'image' && k !== 'video') continue;
                out.media.push({
                    url: u, kind: k,
                    name: String((j.media[i] || {}).name || '').slice(0, 120),
                    size: parseInt((j.media[i] || {}).size, 10) || 0
                });
            }
        }
        return out;
    }
    function richHtml(m) {
        var p = richParts(m), h = '', i, cap;
        if (p.media.length) {
            h += '<span class="ow-rich-media">';
            for (i = 0; i < p.media.length; i++) {
                // 视频下方那行是「名字 · 大小」。两个都可能缺（v1.3.88 之前的老消息没存过），
                // 所以按有什么拼什么 —— 不能留一条光秃秃的「 · 」。
                cap = p.media[i].name
                    + (p.media[i].size > 0 ? (p.media[i].name ? ' · ' : '') + sizeText(p.media[i].size) : '');
                h += p.media[i].kind === 'video'
                    // 视频直接放进来播，不给「下载」——这是用户要的「和图片一样直接展示」。
                    // ⚠️ preload=none：**没点播放就一个字节都不下**。一屏滚过十条视频消息时，
                    //    metadata 也会把每条的头部拉下来；而本站消息区里视频多数是「先看标题再说」。
                    //    代价是黑块 + 原生 ▶，看不到首帧画面（要预览得等用户点）。
                    // ⚠️ muted：**默认静音，必须手动开声**。消息区里的视频不是「点开看的播放器」，
                    //    而是随手滚到的内容 —— 不静音就会有人被突然外放吓到，也打扰旁人。
                    ? '<span class="ow-rich-vid"><video class="ow-rich-video" src="' + esc(p.media[i].url)
                      + '" controls muted preload="none" playsinline></video>'
                      + (cap ? '<span class="ow-rich-name">' + esc(cap) + '</span>' : '') + '</span>'
                    : '<img class="ow-rich-img" src="' + esc(p.media[i].url) + '" data-url="' + esc(p.media[i].url)
                      + '" loading="lazy" decoding="async" alt="图片" onclick="OwChat.viewImg(this.src)">';
            }
            h += '</span>';
        }
        // 文字在媒体下方：与输入框里的待发区（图在上、输入在下）保持同一视觉顺序
        if (p.text !== '') h += '<span class="ow-rich-text">' + linkifyHtml(esc(p.text)) + '</span>';
        return h;
    }

    /* ---------- 文件名「中间省略」（v1.2.18） ----------
       CSS 的 text-overflow: ellipsis 只能砍**尾部**，而尾部恰好是扩展名 ——
       「…年度报表.xlsx」砍成「…年度报表.xls」甚至「…年度报表」，
       恰好把「这是什么文件」这条最关键的信息抹掉。
       这里改成保留「开头 + …… + 结尾（连扩展名）」，中间省略：
           啊啊啊啊啊啊……哈哈.txt

       ⚠️ 为什么不能用纯 CSS 兜底：
       - text-overflow: ellipsis —— 只能尾部，且**不换行**是前提；
       - direction: rtl + ellipsis —— 省略号会跑到开头，但 bidi 会把 .txt
         之类的拉丁片段甩到左侧，文件名视觉顺序直接乱掉，中文场景不可用；
       - 多层 background 渐变遮罩 —— 只能遮，不能真正改变文字内容，
         复制文件名出去还是被砍掉的那串。
       所以只能 JS 量像素后重排文本。

       ⚠️ 必须**元素已入 DOM 之后**才能做：要读 clientWidth/scrollWidth，
       在 innerHTML 字符串阶段还没布局，量不到宽度。
       ⚠️ 完整名始终从 data-name 重取，**绝不能基于已截断的 textContent 再算** ——
       否则 resize 二次调用会把「上次的截断结果」当成原文，越截越短。 */
    var FIT_ELLIPSIS = '……';
    function fitFileName(el) {
        var full = el.getAttribute('data-name') || '';
        if (!full) return;
        el.textContent = full;              // 先还原全名，再按**当前**宽度重算
        var avail = el.clientWidth;
        if (avail <= 0) return;             // 容器还没布局（页面隐藏 / display:none），跳过
        if (el.scrollWidth <= avail) return; // 放得下，原样显示

        // 拆扩展名：下标 > 0 的最后一个点才算（.gitignore 这类以点开头的没有扩展名）
        var dot = full.lastIndexOf('.');
        var ext = dot > 0 ? full.slice(dot) : '';
        var stem = dot > 0 ? full.slice(0, dot) : full;

        /* 二分「首尾一共保留多少个 stem 字符」。
           宽度对「保留字符数」单调递增，所以二分有效。
           head 用 ceil 略多于 tail：结尾往往只剩半个词，
           开头留多一点更符合读名习惯（结尾的扩展名已由 ext 单独占位）。 */
        var lo = 0, hi = stem.length, best = null;
        while (lo <= hi) {
            var k = (lo + hi) >> 1;
            var head = (k + 1) >> 1, tail = k - head;
            var s = stem.slice(0, head) + FIT_ELLIPSIS
                + stem.slice(stem.length - tail) + ext;
            el.textContent = s;
            if (el.scrollWidth <= avail) { best = s; lo = k + 1; }
            else hi = k - 1;
        }
        if (best) { el.textContent = best; return; }

        /* 走到这里 = 连 stem 一个字符都不留、只靠「……+ 扩展名」都还是超宽，
           即**扩展名本身就比卡片还长**（如「报告.a…a」几百个字符的畸形扩展名）。
           ⚠️ 这里绝不能直接 return —— 二分过程已经把 textContent 改成了
           「某半截 stem + …… + ext」的残骸，直接返回会把这份超宽残骸留在界面上，
           表现为「明明做了中间省略却仍横向溢出」。
           正确做法：先保证扩展名自己塞得下（同样按中间省略压到 avail 以内），
           再把它接回去；连压后的扩展名仍放不下，才彻底交给 CSS 的 ellipsis。 */
        el.textContent = ext;
        if (el.scrollWidth <= avail) { el.textContent = FIT_ELLIPSIS + ext; return; }
        ext = shrinkToWidth(el, ext, avail);
        el.textContent = ext;   // 极端情况：只留被压短的扩展名（保底一定不溢出）
    }

    /**
     * 把 s 压到不超过 avail 宽（二分，保留首尾）。
     * 用于扩展名本身超长的兜底 —— 正常文件名走不到这里。
     */
    function shrinkToWidth(el, s, avail) {
        var lo = 0, hi = s.length, best = null;
        while (lo <= hi) {
            var k = (lo + hi) >> 1;
            var head = (k + 1) >> 1, tail = k - head;
            var t = s.slice(0, head) + FIT_ELLIPSIS + s.slice(s.length - tail);
            el.textContent = t;
            if (el.scrollWidth <= avail) { best = t; lo = k + 1; }
            else hi = k - 1;
        }
        // 连「……」都放不下（极窄视口）：只留首字符，后面交给 CSS ellipsis
        return best || s.slice(0, 1);
    }

    /* 合并同一帧内的多次调用：懒加载一次插 30 条，若逐条立即量宽会强制 reflow 30 次。
       ⚠️ 入参是**整条消息的容器节点**，不是文件名元素本身 ——
       fitFileName 读的是元素自己的 data-name，直接把容器传进去会拿到 null 而静默返回。
       所以这里统一向下找出文件名节点。 */
    var fitQueue = [], fitRaf = 0;
    function scheduleFitFileName(box) {
        fitQueue.push(box);
        if (fitRaf) return;
        fitRaf = requestAnimationFrame(function () {
            var q = fitQueue;
            fitQueue = [];
            fitRaf = 0;
            for (var i = 0; i < q.length; i++) {
                if (!q[i] || !q[i].getElementsByClassName) continue;
                var list = q[i].getElementsByClassName('ow-file-name');
                for (var j = 0; j < list.length; j++) fitFileName(list[j]);
            }
        });
    }
    /** 重算消息区里所有文件名（窗口尺寸变化后卡片可用宽度跟着变） */
    function refitAllFileNames() {
        var box = $('owMessages');
        if (!box) return;
        var list = box.getElementsByClassName('ow-file-name');
        for (var i = 0; i < list.length; i++) fitFileName(list[i]);
    }
    function sizeText(n) {
        n = parseInt(n, 10) || 0;
        if (n < 1024) return n + ' B';
        if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
        return (n / 1048576).toFixed(1) + ' MB';
    }

    /**
     * 认得的「长后缀」白名单（≥3 位）。两位一律当 ccTLD 放行（.cn/.io/.me/.tv…），
     * 所以这里只列三位及以上的常见通用顶级域。
     *
     * 为什么用白名单而不是「看着像域名就算」：聊天里 photo.jpg、index.html、main.py、
     * v1.2 天天出现，全字母后缀一律当域名的话，普通文字会被成片点亮。
     * 白名单的代价是冷门新后缀认不出 —— 那种场景极少，而且用户补一个 https:// 就一定算。
     */
    var OW_TLD = /^(?:com|net|org|edu|gov|mil|int|info|biz|name|pro|xyz|app|dev|page|cloud|site|online|store|shop|tech|live|video|photo|pics|studio|group|space|website|link|click|imo|fun|win|buy|sell|host|zone|plus|one|two|three|now|new|life|world|email|news|bio|art|auto|blog|cash|club|data|design|fan|fit|game|games|global|house|ink|land|love|market|meme|mini|money|museum|party|pet|ping|pizza|place|press|pub|quest|read|room|run|sale|science|show|social|software|solar|solutions|style|taxi|team|technology|today|tool|top|town|trade|travel|vip|vote|watch|wiki|work|wtf|xxx|zone)$/;

    /**
     * 两位后缀里的「反例」：它们确实是国家级顶级域（.py 巴拉圭、.sh 圣赫勒拿…），
     * 但在聊天里出现 `main.py`、`index.ts`、`mod.go` 时，几乎一定是在说文件。
     * 判错的代价不对称 —— 把文件点亮是一眼可见的笑话，把 .pl 漏掉只是「用户补个协议就好」，
     * 所以这一小撮一律不当域名。
     */
    var OW_TLD_NOT = /^(?:py|js|ts|go|sh|rb|rs|kt|ex|ml|nb|nn|hl|gl)$/;

    /** 末段后缀在白名单里，或是两位且不在反例里，才算一个真域名的主机名 */
    function owHostReal(host) {
        var parts = String(host || '').toLowerCase().split('.');
        if (parts.length < 2) return false;
        var tld = parts[parts.length - 1];
        if (/^[a-z]{2}$/.test(tld)) return !OW_TLD_NOT.test(tld);
        return tld.length >= 3 && tld.length <= 24 && OW_TLD.test(tld);
    }

    /**
     * 把**已经 esc() 过的**文本里的网址变成可点（v1.3.101 起，v1.3.104 补全裸域名）。
     *
     * 三种写法都要认 —— 用户不会为了「能被点」去补协议头：
     *   https://example.com/a   带协议
     *   www.example.com/a       www 开头
     *   example.com/a           裸域名（后缀须在 OW_TLD 白名单里）
     * 裸域名与 www 生成的 href 一律补 https://：明文 http 要用户自己写出来，我们不替他降级。
     *
     * ⚠️ 顺序只能是「先 esc、再 linkify」：esc 之后文本里不可能再有真标签，
     *    生成的 href 直接沿用转义后的串（`&amp;` 写在属性里本来就是合法写法，浏览器会还原成 &）。
     *    反过来先链接化再 esc，会把我们刚生成的 <a> 一起转义成文字，等于没做。
     * ⚠️ URL 字符集里排掉中日韩汉字与全角标点（　-〿 / 一-鿿 / -￯）：
     *    中文打字习惯是「详见 example.com/a，很好用」——链接和下文之间常常没有空格，
     *    不排除就会把后面整句中文吞进 href。
     * ⚠️ 裸域名前一个字符是字母/数字/@/点/连字符/斜杠时不算链接：那说明它是更大一串的一部分
     *    （邮箱 me@example.com、文件名 a.com.txt、版本号 x.y.com 都在这里被挡掉）。
     * ⚠️ rel 必须带 noopener：target=_blank 打开的页面能靠 window.opener 反向改原页面。
     */
    function linkifyHtml(s) {
        var NOSTOP = '[^\\s<\\u3000-\\u303f\\u4e00-\\u9fff\\uff00-\\uffef]';
        var RE = new RegExp('https?:\\/\\/' + NOSTOP + '+|www\\.' + NOSTOP + '+|(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\\.)+[a-z]{2,24}(?::\\d{1,5})?(?:[\\/?#]' + NOSTOP + '*)?', 'gi');
        return String(s || '').replace(RE, function (raw, offset, str) {
            var lower = raw.toLowerCase();
            var hasScheme = lower.indexOf('http://') === 0 || lower.indexOf('https://') === 0;
            if (!hasScheme) {
                var prev = offset > 0 ? str.charAt(offset - 1) : '';
                if (/[\w.@\-\/]/.test(prev)) return raw;
                // 先剥端口再验后缀：example.com:8080/p 的主机段是 example.com:8080，
                // 拿它按点切分会得到「com:8080」这个假后缀，一条正常链接就被判成了普通文字。
                if (!owHostReal(raw.split(/[\/?#]/)[0].replace(/:\d+$/, ''))) return raw;
            }
            // 句尾标点是句子的一部分，不是 URL 的一部分，剥下来放回正文。
            // &quot;/&#039; 要显式写：源文本里的引号经 esc() 后就是这两串。
            var m = raw.match(/(?:&quot;|&#039;|[)\]},.;:!?])+$/);
            var tail = m ? m[0] : '';
            var u = tail ? raw.slice(0, raw.length - tail.length) : raw;
            // 但分号不能无脑剥：`?x=<b>` esc 后是 `?x=&lt;b&gt;`，剥掉收尾那个 ; 实体就散了。
            // 链接末尾刚好断在 `&字母` 上时，把 ; 还给链接。
            if (tail.charAt(0) === ';' && /&[a-z][a-z0-9]{1,5}$/i.test(u)) {
                u += ';'; tail = tail.slice(1);
            }
            // 去掉协议后必须还剩一个带点的 host，否则只是「https://」这种残链（点了什么也不会发生）
            var bare = u.replace(/^https?:\/\//, '');
            if (bare.indexOf('.') < 0) return raw;
            var href = hasScheme ? u : 'https://' + u;
            // 链接守卫（v1.3.104）：插件可挂 window.OW_LINK_GUARD = function (url) { return 提示文案 }，
            // 返回非空即表示「这个链接不许跳，点了只弹窗」。传的是**补全协议后**的地址，
            // 所以用户只打裸域名时同样在管控范围内。
            // 为什么做在这里而不是让插件事后扫 DOM：消息是增量渲染的（轮询、引用跳转、
            // 撤回后重绘都会新建节点），事后扫要额外挂渲染钩子，还总会漏掉一条路径。
            var warn = '';
            if (typeof w.OW_LINK_GUARD === 'function') {
                try { warn = String(w.OW_LINK_GUARD(href) || ''); } catch (e) { warn = ''; }
            }
            if (warn !== '') {
                // href="#"：被拦的链接不给真实目标（中键/Ctrl+点击同样跳不出去），
                // 但锚文本仍是用户打出来的那串 —— 看得见它本来要去哪。
                // data-url 另存一份补全协议后的地址：插件的申诉弹窗要按域名填表。
                return '<a class="ow-msg-link ow-link-blocked" href="#" data-url="' + esc(href)
                    + '" data-warn="' + esc(warn) + '"'
                    + ' onclick="return OwChat.blockedLink(this)" title="' + esc(warn) + '">' + u + '</a>' + tail;
            }
            return '<a class="ow-msg-link" href="' + href + '" target="_blank"'
                + ' rel="noopener noreferrer nofollow" title="' + href + '">' + u + '</a>' + tail;
        });
    }

    /** 生成 ow 线性 SVG 图标（与服务端 ow_icon 保持一致的描边风格） */
    var OW_SVG_PATHS = {
        'file': '<path d="M13 3.5H7a1.5 1.5 0 0 0-1.5 1.5v14A1.5 1.5 0 0 0 7 20.5h10a1.5 1.5 0 0 0 1.5-1.5V9z"/><path d="M13 3.5V9h5.5"/>',
        'download': '<path d="M12 4v11"/><path d="M7.5 11L12 15.5 16.5 11"/><path d="M4.5 19.5h15"/>'
    };
    function owSvg(name, size) {
        var p = OW_SVG_PATHS[name] || '';
        return '<svg class="ow-icon" width="' + (size || 18) + '" height="' + (size || 18) + '" viewBox="0 0 24 24" fill="none" '
            + 'stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + p + '</svg>';
    }

    /* 彩色文件类型图标：实心版式（viewBox 1024），底色 + 内嵌符号均来自
       FILE_ICON_MAP 配置，与 24 网格的 OW_SVG_PATHS 不同源，故单独一个函数。
       颜色写死在配置里，不跟随主题 currentColor（这些是文件类型的语义色，
       与界面主题无关，跟随主题反而会丢失类型辨识度）。 */
    function owSvgFileType(t, size) {
        var s = size || 34;
        return '<svg class="ow-icon ow-icon-file" width="' + s + '" height="' + s + '" viewBox="0 0 1024 1024" version="1.1" '
            + 'xmlns="http://www.w3.org/2000/svg">'
            + '<path d="' + FILE_SILHOUETTE + '" fill="' + t.c + '"/>'
            + '<path d="' + FILE_FOLD + '" fill="#FFFFFF" fill-opacity=".296"/>'
            + '<path d="' + t.g + '" fill="#FFFFFF"/>'
            + '</svg>';
    }

    /* ==========================================================================
       OwBusy：按钮防重入（v1.5.18，通用轮子）
       --------------------------------------------------------------------------
       一次点击发两个请求的代价，登录页早就吃过（失败计数翻倍，点几下就被锁在门外），
       所以 v1.3.131 在登录表单里写了 submitOnce。这个轮子把它推广到全站：

         OwBusy.run(btn, function (done) { …请求…; done(); });
         OwBusy.form(form, function (done) { …提交…; done(); });
         OwBusy.lock(el)      // 手动上锁，返回释放函数（引用计数，可嵌套）
         OwBusy.autoLock(action) // 供裸 XHR/WS 的插件把手动请求挂到「刚点的按钮」上

       还有一层自动兜底：OwApi.post / secure / upload 会自动锁住**触发它的按钮**
       （同一次点击任务里同步发起的请求，或锁内链式发起的后续请求），请求回来自动释放。
       所以绝大多数按钮不用改代码就有防重入；只有不经过 OwApi 的异步按钮需要显式 run()。

       几个刻意不自动锁的 action（见 AUTO_SKIP）：
         · poll / dm_poll / heartbeat_status / ws_ticket / conv_read / conv_unread_at
           —— 轮询和后台请求，锁住按钮只会把长轮询的几十秒算到用户头上；
         · send —— 发送按设计是非阻塞的（300ms 合并 + 服务端幂等键），禁用它反而卡住连发；
         · send_code —— 「发验证码」自己管禁用与 60s 倒计时，锁上去会在响应后把倒计时解开。

       锁的行为：原生按钮置 disabled（保留锁前就禁用的按钮，绝不替别人解禁）；
       非按钮（a / role=button / .ow-btn 等）加 .ow-busy，并在捕获阶段拦掉重复点击。
       ========================================================================== */
    var OwBusy = (function () {
        var last = null;          // { el, seq, sync } —— 最近一次点击的按钮
        var seq = 0;
        var BTN_CLASS = /(^|\s)(ow-btn|ow-icon-btn|ow-rail-btn|ow-seg-btn|ow-optcard|ow-av-btn|ow-av-cell|ow-pagetab|ow-navset-item|ow-emoji-tab|ow-syslog-tab|ow-tab|ow-quick|ow-send)(\s|$)/;
        // 这些接口不参与「点谁锁谁」的自动锁，原因见上方注释
        var AUTO_SKIP = {
            poll: 1, dm_poll: 1, heartbeat_status: 1, ws_ticket: 1,
            conv_read: 1, conv_unread_at: 1, send: 1, send_code: 1
        };

        function hasClass(el, c) {
            return (' ' + (typeof el.className === 'string' ? el.className : '') + ' ').indexOf(' ' + c + ' ') >= 0;
        }
        function addClass(el, c) {
            if (!hasClass(el, c) && typeof el.className === 'string') {
                el.className = (el.className + ' ' + c).replace(/^\s+|\s+$/g, '');
            }
        }
        function delClass(el, c) {
            if (typeof el.className !== 'string') return;
            el.className = (' ' + el.className + ' ').replace(' ' + c + ' ', ' ').replace(/^\s+|\s+$/g, '');
        }

        function buttonish(n) {
            if (!n || n.nodeType !== 1) return false;
            if (n.tagName === 'BUTTON' || n.tagName === 'A') return true;
            if (n.getAttribute && n.getAttribute('role') === 'button') return true;
            return typeof n.className === 'string' && BTN_CLASS.test(n.className);
        }

        /** 从点击目标向上找「可锁的按钮」，最多 12 层，避免把整页容器当按钮锁住 */
        function nearest(target) {
            var n = target, depth = 0;
            while (n && n.nodeType === 1 && depth++ < 12) {
                if (buttonish(n)) return n;
                n = n.parentNode;
            }
            return null;
        }

        /** 释放一次锁；reference count 归零才真正解禁 */
        function makeRelease(el, rec) {
            var used = false;
            return function () {
                if (used) return;
                used = true;
                rec.n--;
                if (rec.n > 0) return;
                if (rec.timer) { clearTimeout(rec.timer); rec.timer = 0; }
                // 已被安全超时释放、或元素上换了一把新锁：本次是迟到回调，不能碰新锁
                if (el.__owBusy !== rec) return;
                try { delete el.__owBusy; } catch (e) { el.__owBusy = null; }
                delClass(el, 'ow-busy');
                if (el.removeAttribute) el.removeAttribute('aria-busy');
                // 只恢复「我们锁之前本来就是启用」的按钮（倒计时按钮等不能被我们解开）
                if (!rec.wasDisabled && 'disabled' in el) {
                    try { el.disabled = false; } catch (e) {}
                }
                if (last && last.el === el) last = null;
            };
        }

        function lock(el, timeout) {
            if (!el || el.nodeType !== 1) return null;
            if (el.__owBusy) {
                el.__owBusy.n++;
                return makeRelease(el, el.__owBusy);
            }
            var rec = { n: 1, wasDisabled: el.disabled === true, timer: 0 };
            el.__owBusy = rec;
            if ('disabled' in el) { try { el.disabled = true; } catch (e) {} }
            addClass(el, 'ow-busy');
            if (el.setAttribute) el.setAttribute('aria-busy', 'true');
            // 安全阀：请求异常/回调丢失也不能把按钮永久焊死
            rec.timer = setTimeout(function () {
                if (el.__owBusy !== rec) return;
                rec.timer = 0;
                rec.n = 0;
                makeRelease(el, rec)();
            }, timeout || 30000);
            return makeRelease(el, rec);
        }

        /** 自动锁：供 OwApi 使用。只在「本次点击任务内」或「该按钮正在锁内链式请求」时生效 */
        function autoLock(action, timeout) {
            if (!last || AUTO_SKIP[action]) return null;
            var el = last.el;
            if (!el || !el.parentNode) return null;   // 已被弹窗关闭等移除
            if (!last.sync && !el.__owBusy) return null;
            return lock(el, timeout || 30000);
        }

        /** 点击任务的收尾：同一任务内标记 sync；任务结束后按钮仍锁着就继续允许链式请求挂靠 */
        function noteClick(el) {
            // 点在空白/列表行上不覆盖上一个按钮：否则「A 的请求还没回、用户点了一下空白」
            // 会让 A 的链式请求失去挂靠对象，中途解禁。
            if (!el) return;
            var s = ++seq;
            last = { el: el, seq: s, sync: true };
            setTimeout(function () {
                if (!last || last.seq !== s) return;
                last.sync = false;
                if (!last.el.__owBusy) last = null;
            }, 0);
        }

        // 捕获阶段做两件事：记录本次点击的按钮；拦住正在忙的元素上的重复点击
        if (d.addEventListener) {
            d.addEventListener('click', function (e) {
                var t = e.target || e.srcElement, n = t;
                while (n && n.nodeType === 1) {
                    if (n.__owBusy) {
                        if (e.preventDefault) e.preventDefault();
                        if (e.stopPropagation) e.stopPropagation();
                        if (e.stopImmediatePropagation) e.stopImmediatePropagation();
                        return;
                    }
                    n = n.parentNode;
                }
                noteClick(nearest(t));
            }, true);
        }

        return {
            /** 手动上锁；返回的释放函数可重复调用，内部按引用计数（嵌套请求安全） */
            lock: lock,
            autoLock: autoLock,

            /**
             * 执行一次带锁的异步任务。
             * @param {Element} el   要锁的按钮（可为 null，此时只执行任务）
             * @param {Function} task task(done)：异步结束后必须调 done()
             * @param {Object} [opts] { timeout: 毫秒，默认 30000 }
             * @returns {boolean} false = 按钮正在忙，本次点击被忽略
             */
            run: function (el, task, opts) {
                if (el && el.__owBusy) return false;
                var release = lock(el, opts && opts.timeout), done = false;
                var finish = function () {
                    if (done) return;
                    done = true;
                    if (release) release();
                };
                try {
                    var ret = task(finish);
                    // 也支持 Promise：任务返回 thenable 时自动收尾
                    if (ret && typeof ret.then === 'function') ret.then(finish, finish);
                } catch (e) {
                    finish();
                    throw e;
                }
                return true;
            },

            /**
             * 整张表单上锁：提交期间禁用所有按钮，同一表单只放行一次提交。
             * 与 v1.3.131 的 OwAuth.submitOnce 行为一致（只解禁「锁前是启用」的按钮）。
             * @param {HTMLFormElement} form
             * @param {Function} task task(done)
             * @param {Object} [opts] { timeout: 毫秒，默认 15000 }
             * @returns {boolean} false = 上一次提交还没回来
             */
            form: function (form, task, opts) {
                if (!form || form.__owBusy) return false;
                form.__owBusy = true;
                if (form.setAttribute) form.setAttribute('aria-busy', 'true');
                var off = [], i, all = form.getElementsByTagName('button');
                for (i = 0; i < all.length; i++) {
                    if (!all[i].disabled) { all[i].disabled = true; off.push(all[i]); }
                }
                var done = false, timer = 0;
                var release = function () {
                    if (done) return;
                    done = true;
                    if (timer) { clearTimeout(timer); timer = 0; }
                    if (!form.__owBusy) return;
                    form.__owBusy = false;
                    if (form.removeAttribute) form.removeAttribute('aria-busy');
                    for (var j = 0; j < off.length; j++) off[j].disabled = false;
                };
                timer = setTimeout(release, (opts && opts.timeout) || 15000);
                try {
                    task(release);
                } catch (e) {
                    release();   // 任务同步抛错也要放开，不能等 15s 超时
                    throw e;
                }
                return true;
            }
        };
    })();

    /* ==========================================================================
       OwLazy：图片懒加载（v1.5.19，通用轮子）
       --------------------------------------------------------------------------
       选型：不引入 lazysizes —— 本站是零依赖交付，且现代浏览器原生
       loading="lazy" 已覆盖大多数场景。这里只补它顾不到的两块：
         ① 生成式 HTML 经常漏写 loading 属性 → stamp / scan 自动补齐；
         ② 老内核没有原生 lazy → data-src / data-srcset 走 IntersectionObserver
            （原生 lazy 只认 src，不认 data-src，所以这条在现代浏览器也要跑）。

       用法：
         OwLazy.scan(el)        // 新片段插入前/后扫一遍（MutationObserver 也会兜底）
         OwLazy.stamp(img)      // 单个 <img> 补属性
         OwLazy.observe(img)    // 让 data-src 立即参与观察
         OwLazy.eager(img)      // 显式标记「不要懒加载」

       约定：
         · 需要延后的图写 <img data-src="…" data-srcset="…">，不要直接写 src；
         · 首屏 Logo / 验证码 / 灯箱加 data-ow-eager（或 loading="eager"）；
         · CSS background-image 不在本范围内（背景图一打开就该看到，延后反而闪白），
           内容图请用 <img>。
       ========================================================================== */
    var OwLazy = (function () {
        var native = false;
        try { native = 'loading' in d.createElement('img'); } catch (e) {}
        var io = null;
        var ROOT_MARGIN = '600px 0px';
        var EAGER_CLASS = /(^|\s)(ow-auth-logo|ow-brand|ow-img-viewer|ow-captcha-row)(\s|$)/;
        var queue = [], timer = 0, mo = null;

        /** 首屏/灯箱/验证码等不该延后的图：自己或任一祖先带 data-ow-eager 即放过 */
        function isEager(img) {
            if ((img.getAttribute('loading') || '').toLowerCase() === 'eager') return true;
            if (img.id === 'owCaptchaImg' || img.id === 'owImgViewerImg') return true;
            var n = img;
            while (n && n.nodeType === 1) {
                if (n.getAttribute && n.getAttribute('data-ow-eager') !== null) return true;
                if (typeof n.className === 'string' && EAGER_CLASS.test(n.className)) return true;
                n = n.parentNode;
            }
            return false;
        }

        function reveal(img) {
            var src = img.getAttribute('data-src');
            if (src) { img.setAttribute('src', src); img.removeAttribute('data-src'); }
            var ss = img.getAttribute('data-srcset');
            if (ss) { img.setAttribute('srcset', ss); img.removeAttribute('data-srcset'); }
            if (io) { try { io.unobserve(img); } catch (e) {} }
            img.__owLazyObserved = false;   // 允许之后重新设置 data-src 再次观察
        }

        function observe(img) {
            if (!img || img.tagName !== 'IMG' || img.__owLazyObserved) return;
            img.__owLazyObserved = true;
            if (!io) { reveal(img); return; }   // 老内核无 IO：立即加载，不把图卡死
            try { io.observe(img); } catch (e) { reveal(img); }
        }

        /**
         * 显式懒加载 CSS 背景图（background-image 没有原生 loading 属性）。
         * 只在调用方明确需要时使用；首屏背景、聊天主背景不要走这里。
         */
        function bg(el, url) {
            if (!el || el.nodeType !== 1) return;
            if (!url) {
                el.style.backgroundImage = '';
                el.__owLazyBg = '';
                if (io) { try { io.unobserve(el); } catch (e) {} }
                el.__owLazyObserved = false;
                return;
            }
            if (!io) { el.__owLazyBg = url; applyBg(el); return; }
            if (el.__owLazyObserved) { el.__owLazyBg = url; return; }
            el.__owLazyObserved = true;
            el.__owLazyBg = url;
            try { io.observe(el); } catch (e) { applyBg(el); }
        }

        /** 把待加载的背景图写进 style（IO 命中 / 无 IO 兜底共用） */
        function applyBg(el) {
            var url = el.__owLazyBg;
            if (!url) return;
            el.__owLazyBg = '';
            if (io) { try { io.unobserve(el); } catch (e) {} }
            el.__owLazyObserved = false;
            if (!el.parentNode) return;   // 弹窗已关，别再写样式
            // 与插件原来的转义口径一致：单引号和反斜杠不能破坏 url('…')
            el.style.backgroundImage = "url('" + String(url).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "')";
        }

        function initIO() {
            if (io || typeof w.IntersectionObserver === 'undefined') return;
            try {
                io = new w.IntersectionObserver(function (entries) {
                    for (var i = 0; i < entries.length; i++) {
                        if (!entries[i].isIntersecting) continue;
                        var t = entries[i].target;
                        // __owLazyBg 优先：同一元素既被当背景容器又被当图片的极端情况
                        if (t.__owLazyBg) applyBg(t);
                        else if (t.tagName === 'IMG') reveal(t);
                    }
                }, { rootMargin: ROOT_MARGIN });
            } catch (e) { io = null; }
        }

        /**
         * 给一张图补懒加载属性。
         * ⚠️ 只处理「有网络请求的图」：data:/blob: 是本地即时预览（附件待发区），
         *    延后加载只会让预览晚一拍，得不偿失。
         */
        function stamp(img) {
            if (!img || img.tagName !== 'IMG' || isEager(img)) return;
            var src = img.getAttribute('src') || '';
            var data = img.getAttribute('data-src') || img.getAttribute('data-srcset') || '';
            if (!data && /^(data|blob):/i.test(src)) return;
            if (!img.getAttribute('loading')) img.setAttribute('loading', 'lazy');
            if (!img.getAttribute('decoding')) img.setAttribute('decoding', 'async');
            if (data) observe(img);
        }

        /** 扫一个容器（元素 / DocumentFragment / document）；root 本身是 img 也支持 */
        function scan(root) {
            var scope = (root && root.nodeType === 1) ? root : (root || d);
            if (scope.tagName === 'IMG') { stamp(scope); return; }
            if (!scope.querySelectorAll) return;
            var imgs = scope.querySelectorAll('img');
            for (var i = 0; i < imgs.length; i++) stamp(imgs[i]);
        }

        function flush() {
            timer = 0;
            var q = queue;
            queue = [];
            for (var i = 0; i < q.length; i++) scan(q[i]);
        }

        function enqueue(node) {
            if (!node || node.nodeType !== 1) return;
            if (node.tagName === 'IMG') queue.push(node);
            else if (node.getElementsByTagName) {
                // 消息区/插件弹窗会整块 innerHTML 替换；只对含图的子树入队
                if (!node.getElementsByTagName('img').length) return;
                queue.push(node);
            } else return;
            if (!timer) timer = setTimeout(flush, 0);
        }

        function watch() {
            if (mo || !w.MutationObserver) return;
            var host = d.body || d.documentElement;
            if (!host) return;
            try {
                mo = new w.MutationObserver(function (recs) {
                    for (var i = 0; i < recs.length; i++) {
                        var add = recs[i].addedNodes;
                        for (var j = 0; j < add.length; j++) enqueue(add[j]);
                    }
                });
                mo.observe(host, { childList: true, subtree: true });
            } catch (e) { mo = null; }
        }

        function boot() {
            initIO();
            scan(d);
            watch();
        }

        if (d.readyState === 'loading') {
            d.addEventListener('DOMContentLoaded', boot, false);
        } else {
            boot();
        }

        return {
            native: native,   // 仅供诊断：浏览器是否原生支持 loading="lazy"
            stamp: stamp,
            scan: scan,
            observe: observe,
            reveal: reveal,
            bg: bg,
            eager: function (img) {
                if (img && img.setAttribute) img.setAttribute('data-ow-eager', '1');
            }
        };
    })();

    var OwApi = {
        key: '',
        tsOffset: 0,   // 客户端时钟与服务器的偏差（秒），由页面下发的服务器时间校正
        // 关键：签名用的 ts 以「服务器时间」为准，客户端系统时钟不准也不会导致签名失败
        setServerTime: function (ts) {
            if (!ts) return;
            this.tsOffset = parseInt(ts, 10) - Math.floor(new Date().getTime() / 1000);
        },
        sign: function (action) {
            var ts = Math.floor(new Date().getTime() / 1000) + this.tsOffset;
            return { ts: ts, sign: md5(this.key + '|' + ts + '|' + action) };
        },
        // 带文件的 multipart 上传：upload(action, file, cb) 或 upload(action, file, extraFields, cb)
        upload: function (action, file, extra, cb) {
            if (typeof extra === 'function') { cb = extra; extra = null; }
            // v1.5.18：上传期间锁住触发按钮（不经过点击的调用没有可锁对象，自然跳过）。
            // 大文件/慢网可能超过 30s，安全超时放宽到 120s。
            var release = OwBusy.autoLock(action, 120000);
            var done = function (r, status) {
                try { if (cb) cb(r, status); }
                finally { if (release) release(); }
            };
            var s = this.sign(action), fd, x, k;
            try { fd = new FormData(); } catch (e) { done({ ok: false, msg: '当前浏览器不支持文件上传' }); return; }
            fd.append('ts', s.ts);
            fd.append('sign', s.sign);
            if (extra) { for (k in extra) if (extra.hasOwnProperty(k)) fd.append(k, extra[k]); }
            fd.append('file', file);
            x = new XMLHttpRequest();
            x.open('POST', '?action=' + action, true);
            x.onreadystatechange = function () {
                if (x.readyState !== 4) return;
                var r = null;
                try { r = JSON.parse(x.responseText); } catch (e) {}
                r = r || { ok: false, msg: '网络错误（' + x.status + '）' };
                if (r.ok === false && r.msg && r.msg.indexOf('签名验证失败') >= 0) {
                    OwApi.onSignExpired(function () { done(r, x.status); });
                    return;
                }
                done(r, x.status);
            };
            x.send(fd);
            return x;
        },

        /* 签名失效自愈：会话重建/页面为旧缓存时密钥对不上，自动刷新一次取新密钥 */
        onSignExpired: function (cb) {
            var flag = 'owl_sig_reload_at', now = new Date().getTime(), last = 0;
            try { last = parseInt(w.sessionStorage.getItem(flag) || '0', 10); } catch (e) {}
            if (last && now - last < 15000) { if (cb) cb(); return; } // 15 秒内只自动刷新一次，避免死循环
            try { w.sessionStorage.setItem(flag, String(now)); } catch (e) {}
            if (cb) cb();
            setTimeout(function () { location.reload(); }, 800);
        },
        post: function (action, data, cb) {
            // v1.5.18：自动锁住「触发这次请求的按钮」，请求回来再释放（链式请求会继续挂靠）
            var release = OwBusy.autoLock(action);
            var done = function (r, status) {
                try { if (cb) cb(r, status); }
                finally { if (release) release(); }
            };
            var s = this.sign(action), body = 'ts=' + s.ts + '&sign=' + s.sign, k;
            for (k in (data || {})) if (data.hasOwnProperty(k)) body += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
            var x = new XMLHttpRequest();
            x.open('POST', '?action=' + encodeURIComponent(action), true);
            x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            x.onreadystatechange = function () {
                if (x.readyState !== 4) return;
                var r = null;
                try { r = JSON.parse(x.responseText); } catch (e) {}
                r = r || { ok: false, msg: '网络错误（' + x.status + '）' };
                if (r.ok === false && r.msg && r.msg.indexOf('签名验证失败') >= 0) {
                    OwApi.onSignExpired(function () { done(r, x.status); });
                    return;
                }
                done(r, x.status);
            };
            x.send(body);
            return x;
        },

        /**
         * 敏感操作（v1.0.91）：退出登录 / 删除内容 / 管理员删·恢复 / 禁用等。
         * 先取一次性操作票据（?action=ticket，签名保护），随请求提交，服务端校验后作废，
         * 防止签名窗口期内的请求重放与跨站劫持。用法与 post 相同：OwApi.secure(action, data, cb)
         */
        secure: function (action, data, cb) {
            // 票据 + 业务是两次请求：从点击起就持有锁，直到业务请求回来才释放。
            // 中途 ticket 的 post 也会自动锁（引用计数），不会提前解禁。
            var hold = OwBusy.autoLock(action), settled = false;
            var finish = function (r, status) {
                if (settled) return;
                settled = true;
                try { if (cb) cb(r, status); }
                finally { if (hold) hold(); }
            };
            this.post('ticket', {}, function (t) {
                if (!t.ok || !t.ticket) { finish({ ok: false, msg: t.msg || '安全校验组件不可用' }); return; }
                var d = {}, k;
                for (k in (data || {})) if (data.hasOwnProperty(k)) d[k] = data[k];
                d.ticket = t.ticket;
                OwApi.post(action, d, finish);
            });
        },
    };

    /* 提示音（内置短音 data URI，旧浏览器静默降级） */
    var BEEP = 'data:audio/wav;base64,UklGRl9vT1dQV0ZFZm10IBAAAAABAAEAQB8AAIA+AAACABAAZGF0YQAAAAD//w==';
    function beep() {
        try {
            var AC = w.AudioContext || w.webkitAudioContext;
            if (AC) {
                var ctx = beep._ctx || (beep._ctx = new AC());
                var o = ctx.createOscillator(), g = ctx.createGain();
                o.type = 'sine'; o.frequency.value = 880;
                g.gain.setValueAtTime(0.08, ctx.currentTime);
                g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.25);
                o.connect(g); g.connect(ctx.destination);
                o.start(); o.stop(ctx.currentTime + 0.25);
            } else {
                var a = new Audio(BEEP); a.play();
            }
        } catch (e) {}
    }

    /* 前台身份标签（v1.0.86）：只保留「群主 / 会员」两类身份展示——
       群主=当前群聊 owner（橙色），VIP=会员（保留 VIP 配色），普通用户与超级管理员=会员（灰色），
       游客与其它角色不再展示身份标签。超级管理员在别人创建的群聊里同样显示「会员」。 */
    var CUR_OWNER = 0;   // 当前群聊的 owner 用户 ID（OwChat 切换群聊时同步）

    /* 机器人账号（v1.3.152）：uid → 1。
       机器人多半就是一个**普通用户账号**（群聊自动化那套就是这么做的：后台填一个 bot_uid，
       它按普通用户的身份发言），不登记的话它在群里顶着「会员」标签，看着就是个真人。
       由插件在脚本加载时调 OwChat.markBots() 登记 —— 值随插件自己的服务端渲染带下来
       （见 chat-scripts 的 chat.toolbar 按钮），不用多发一个请求。 */
    var BOT_UIDS = {};
    /**
     * 身份标签。
     *
     * v1.2.40 起**分场景**（这是有意的口径差异，不是 bug）：
     *   · 聊天场景（消息气泡、会话列表、成员名单…）：admin 一律显示「会员」。
     *     聊天是日常场景，没必要把特权身份挂在每个人眼前。
     *   · 管理场景（**用户资料卡**、**群成员管理列表**）：admin 显示「超级管理员」。
     *     这两处是判断「这个人能不能管这个群」的依据，隐藏身份会让人做错决定。
     * @param {string} role
     * @param {string} title 头衔
     * @param {number} uid   用于判「群主」
     * @param {boolean} real  true=管理场景（显示真实身份）；缺省/false=聊天场景
     */
    function roleTag(role, title, uid, real) {
        var h = '';
        // v1.3.152：机器人优先于一切身份标签 —— 它就是台机器，标成「会员」会让人当成真人。
        // 管理场景（real=true）同样出「机器人」：知道对方是机器人比知道它挂着管理员更能避免误判。
        if (uid && BOT_UIDS[uid]) h = '<span class="ow-tag ow-tag-bot">机器人</span>';
        else if (uid && uid === CUR_OWNER) h = '<span class="ow-tag ow-tag-owner">群主</span>';
        else if (real && role === 'admin') h = '<span class="ow-tag ow-tag-admin">超级管理员</span>';
        else if (role === 'vip') h = '<span class="ow-tag ow-tag-vip">会员</span>';
        else if (role === 'member' || role === 'admin') h = '<span class="ow-tag ow-tag-member">会员</span>';
        if (title) h += (h ? ' ' : '') + '<span class="ow-tag ow-tag-title">' + esc(title) + '</span>';
        return h;
    }

    /* 头像：服务端 avatarUrlFor() 给什么就显示什么（上传图 / DiceBear / 内置 identicon）。
       v1.3.11 起不再有「首字色块」与「游客固定米金底」这类前端兜底 —— 那些都挪到服务端统一算了。 */
    function avatarHtml(url, name, sm, role) {
        /* v1.3.11：删除「首字色块」兜底（游客固定米色、用户按昵称长度取色）。
           服务端 avatarUrlFor() 保证 url 非空（上传图 / DiceBear / 内置 identicon），
           前端只负责渲染并挂 onerror 兜底 —— 远程挂了也不破相。 */
        var sizeCls = sm === 'xs' ? ' ow-avatar-xs'
            : (sm === 'lg' ? ' ow-avatar-lg'
            : (sm === 'md' ? ' ow-avatar-md' : (sm ? ' ow-avatar-sm' : '')));
        var cls = 'ow-avatar' + sizeCls;
        var src = url || OwAvatar.identicon(name || role || 'owl');
        return '<span class="' + cls + '"><img src="' + esc(src) + '" alt="' + esc(name || '') + '" loading="lazy" decoding="async" onerror="OwAvatar.fallback(this)"></span>';
    }

    /* v1.3.11：群聊默认头像（room-default.svg 双人剪影）已移除 ——
       群没有自定义头像时，改用**创建者的头像**（服务端 Chat::roomAvatarUrl 负责回落），
       群 id 派生仅作创建者已删除时的兜底。原先 assets/img/room-default.svg 一并删除。 */

    /**
     * 头像加载失败的兜底（v1.3.11）。
     *
     * 服务端已经保证 avatar 字段是非空 URL（上传图 / DiceBear / 内置 identicon），
     * 但远程 DiceBear 在国内可能加载失败 —— `<img>` 碎了就是一片占位图，
     * 比没有头像更糟。所以统一挂 onerror：失败时换成本地现算的几何头像。
     * data-owfb 标记保证只兜底一次（兜底自身失败就不再重试，避免死循环）。
     */
    var OwAvatar = {
        /** 标准 CRC32（与 PHP 侧 crc32 一致），用来把昵称映射成稳定的图案与色相 */
        crc32: function (str) {
            var c, crc = 0xFFFFFFFF;
            str = String(str || '');
            for (var n = 0; n < str.length; n++) {
                c = (crc ^ str.charCodeAt(n)) & 0xFF;
                for (var k = 0; k < 8; k++) c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
                crc = (crc >>> 8) ^ c;
            }
            return (crc ^ 0xFFFFFFFF) >>> 0;
        },
        /** 5×5 对称网格，与 core/auth.php 的 Auth::avatarIdenticon 同一套算法 */
        identicon: function (seed) {
            var h = this.crc32(seed || 'owl');
            // ⚠️ 颜色必须用十六进制：SVG 1.1 的 fill 属性不认 hsl()，用 hsl 会整张填不上色；
            // width/height 必须写死：只有 viewBox 的 SVG 放进 <img> 会被按 0 宽渲染成一条竖线。
            var hue = h % 360;
            var fg = this.hslHex(hue, 62, 48), bg = this.hslHex((hue + 200) % 360, 42, 90);
            var bits = (h >>> 5) & 0x7FF, rects = '', y, x, b;
            for (y = 0; y < 5; y++) {
                for (x = 0; x < 3; x++) {
                    b = bits & (1 << (y * 3 + x));
                    if (!b) continue;
                    rects += '<rect x="' + x + '" y="' + y + '" width="1" height="1"/>'
                           + '<rect x="' + (4 - x) + '" y="' + y + '" width="1" height="1"/>';
                }
            }
            var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="5" height="5" viewBox="0 0 5 5"'
                    + ' preserveAspectRatio="xMidYMid meet" shape-rendering="crispEdges">'
                    + '<rect width="5" height="5" fill="' + bg + '"/>'
                    + '<g fill="' + fg + '">' + rects + '</g></svg>';
            // 中文昵称不能直接进 URI，中文场景统一用 base64（btoa 对非 Latin1 会抛错，故先转义）
            try {
                return 'data:image/svg+xml;base64,' + w.btoa(unescape(encodeURIComponent(svg)));
            } catch (e) {
                return 'data:image/svg+xml,' + encodeURIComponent(svg);
            }
        },
        /** HSL → #RRGGBB（与 core/auth.php 的 hslHex 同一算法） */
        hslHex: function (h, sat, lig) {
            var s = sat / 100, l = lig / 100;
            var c = (1 - Math.abs(2 * l - 1)) * s;
            var x = c * (1 - Math.abs((h % 360) / 60 % 2 - 1));
            var m = l - c / 2, r, g, b;
            var hp = h % 360;
            if (hp < 60)       { r = c; g = x; b = 0; }
            else if (hp < 120) { r = x; g = c; b = 0; }
            else if (hp < 180) { r = 0; g = c; b = x; }
            else if (hp < 240) { r = 0; g = x; b = c; }
            else if (hp < 300) { r = x; g = 0; b = c; }
            else               { r = c; g = 0; b = x; }
            function hx(v) {
                var n = Math.max(0, Math.min(255, Math.round((v + m) * 255)));
                return (n < 16 ? '0' : '') + n.toString(16);
            }
            return '#' + hx(r) + hx(g) + hx(b);
        },
        fallback: function (el) {
            if (!el || el.getAttribute('data-owfb')) return;
            el.setAttribute('data-owfb', '1');
            el.src = this.identicon(el.getAttribute('alt') || 'owl');
        }
    };
    w.OwAvatar = OwAvatar;

    /**
     * 群头像 HTML。v1.3.11 起服务端已把「自定义 → 创建者头像 → 群 id 派生」算好，
     * 这里不再有默认剪影图分支，url 恒为非空；仍保留 onerror 兜底。
     * @param {string} url   服务端算好的头像 URL
     * @param {string} sm    尺寸档，同 avatarHtml
     * @param {string} extra 额外的 class（会话列表要用 .ow-cl-icon 而非 .ow-avatar）
     */
    function roomAvatarHtml(url, sm, extra) {
        var sizeCls = sm === 'xs' ? ' ow-avatar-xs'
            : (sm === 'lg' ? ' ow-avatar-lg'
            : (sm === 'md' ? ' ow-avatar-md' : (sm ? ' ow-avatar-sm' : '')));
        var cls = extra || ('ow-avatar' + sizeCls);
        var src = url || (OwAvatar.identicon('room'));
        return '<span class="' + cls + '"><img src="' + esc(src) + '" alt="" loading="lazy" decoding="async" onerror="OwAvatar.fallback(this)"></span>';
    }

    /* ---------- 线性图标表（v1.3.85 合并成一张） ----------
       原先有两张：OW_ENTRY_ICONS（侧栏入口行）与 OW_ICONS（owIco），
       同名图标各存一份 path —— gear 就有两份，改一处漏一处的漂移只是时间问题。
       现在只留 OW_ICONS 一张：侧栏入口行与右键菜单都从这里取。
       ⚠️ 与 PHP 侧 ow_icon **同名**的条目必须逐字同 path（.tools/icon-audit.php 会比对并报警），
       只在 JS 出现的（copy / quote / undo / star / monitor / pin / trash / flag…）不受此约束。
       禁 Emoji 作功能图标。 */
    var OW_ICONS = {
        /* 与 PHP ow_icon 同名同 path */
        image: '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><circle cx="9" cy="10" r="1.8"/><path d="M4 17l4.5-4.5 3.5 3.5 3-3 5 5"/>',
        smile: '<circle cx="12" cy="12" r="9"/><path d="M8.5 14.5c1 1.5 2.2 2.2 3.5 2.2s2.5-.7 3.5-2.2"/><line x1="9" y1="9.5" x2="9" y2="10.5"/><line x1="15" y1="9.5" x2="15" y2="10.5"/>',
        gear: '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
        mega: '<path d="M3 11v3l4 .5V10.5z"/><path d="M7 10.5L18 5v13l-11-4.5"/><path d="M9 15.5V18a2 2 0 0 0 4 .5"/>',
        user: '<circle cx="12" cy="8" r="4"/><path d="M4.5 20c1.2-4 4-6 7.5-6s6.3 2 7.5 6"/>',
        chat: '<path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v8a2.5 2.5 0 0 1-2.5 2.5H9l-5 4z"/>',
        send: '<path d="M3.5 12L21 4l-7.5 17-2.5-7z"/>',
        plus: '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        shield: '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><path d="M9 12l2 2 4-4"/>',
        logout: '<path d="M14 4H6.5A1.5 1.5 0 0 0 5 5.5v13A1.5 1.5 0 0 0 6.5 20H14"/><path d="M10 12h10M17 8.5l3.5 3.5-3.5 3.5"/>',
        mute: '<path d="M8 6.5A6 6 0 0 1 18 10c0 4 1.5 5.5 2 6h-3M6.2 8.5C6.1 9 6 9.5 6 10c0 4-1.5 5.5-2 6h11"/><line x1="4" y1="4" x2="20" y2="20"/>',
        file: '<path d="M13 3.5H7a1.5 1.5 0 0 0-1.5 1.5v14A1.5 1.5 0 0 0 7 20.5h10a1.5 1.5 0 0 0 1.5-1.5V9z"/><path d="M13 3.5V9h5.5"/>',
        paperclip: '<g transform="scale(0.82) translate(2.6 2.6)"><path d="M16.5 7.5l-7 7a3.5 3.5 0 0 0 5 5l7-7a5.5 5.5 0 0 0-8-8L6 12a7.5 7.5 0 0 0 11 11"/></g>',
        /* 仅 JS 侧使用 */
        upload: '<path d="M12 16V4"/><path d="M7.5 8.5L12 4l4.5 4.5"/><path d="M4.5 19.5h15"/>',
        // v1.3.86 待发区弹窗的「以视频方式发送」卡片
        video: '<rect x="3.5" y="5.5" width="17" height="13" rx="2.5"/><path d="M10.5 9.3l4.6 2.7-4.6 2.7z"/>',
        // v1.2.28 私聊右侧栏四入口：统一 24 网格、stroke 1.8、round 端点（禁实心填充）
        pin: '<path d="M9 3.5h6M10 3.5v5.2L7.4 13h9.2L14 8.7V3.5"/><path d="M12 13v7.5"/>',
        pinOff: '<path d="M9 3.5h6M10 3.5v5.2L7.4 13h9.2M14 8.7V3.5"/><path d="M12 13v7.5"/><path d="M4 20.5L20 3.5"/>',
        trash: '<path d="M4 6.5h16"/><path d="M9.5 6.5V4.2h5v2.3"/><path d="M6.5 6.5l1 13.3h9l1-13.3"/><path d="M10.2 10v6.2M13.8 10v6.2"/>',
        userX: '<circle cx="10" cy="8" r="3.4"/><path d="M3.8 20.2c0-3.4 2.8-5.7 6.2-5.7 1 0 1.9.2 2.7.5"/><path d="M16.2 16.6l4.6 4.6M20.8 16.6l-4.6 4.6"/>',
        flag: '<path d="M5.5 21.2V3.6"/><path d="M5.5 4.6h11.8l-2.2 3.9 2.2 3.9H5.5z"/>',
        // v1.3.85 右键菜单新增：复制 / 引用 / 撤回 / 收藏 / 后台
        copy: '<rect x="8.5" y="8.5" width="11" height="11" rx="2"/><path d="M15.5 8.5V5.5a2 2 0 0 0-2-2H5.5a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h3"/>',
        quote: '<path d="M5 5.5v13"/><path d="M9.5 9h9M9.5 12.5h9M9.5 16h6"/>',
        undo: '<path d="M4.5 9.5h9.5a5 5 0 1 1 0 10H8"/><path d="M8 5.5L4.5 9.5 8 13.5"/>',
        star: '<path d="M12 4l2.4 5 5.4.7-4 3.8 1 5.4-4.8-2.7-4.8 2.7 1-5.4-4-3.8 5.4-.7z"/>',
        // 右键头像菜单第一行「@ 昵称」：标签是动态的，按文字查表查不到，只能显式指定
        at: '<circle cx="12" cy="12" r="4"/><path d="M16 8v5.5a2.5 2.5 0 0 0 5 0V12a9 9 0 1 0-3.6 7.1"/>',
        monitor: '<rect x="3.5" y="5" width="17" height="11.5" rx="2"/><path d="M9 20h6M12 16.5V20"/>',
        // v1.3.158：仪表盘（后台首屏菜单）。与 PHP ow_icon('chart') 同一份 path，icon-audit 逐字对账。
        chart: '<line x1="4" y1="20" x2="20" y2="20"/><rect x="5.6" y="12" width="3.4" height="8" rx="1"/><rect x="10.3" y="6.5" width="3.4" height="13.5" rx="1"/><rect x="15" y="15" width="3.4" height="5" rx="1"/>',
        // v1.4.2：复选方块 —— 消息「多选」入口（batch-ops 插件）。此前右键菜单里只有它没图标，
        // 与相邻的复制/引用/撤回/删除不齐；与 PHP ow_icon('check-square') 同一份 path，icon-audit 逐字对账。
        'check-square': '<rect x="4" y="4" width="16" height="16" rx="3.5"/><path d="M8.3 12.4l2.6 2.6 4.8-5.2"/>'
    };
    function owIco(name, size, extra) {
        var d = OW_ICONS[name] || '';
        if (!d) return '';
        return '<svg class="ow-ico' + (extra ? ' ' + extra : '') + '" width="' + (size || 16) + '" height="' + (size || 16) + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + d + '</svg>';
    }

    /**
     * 生成一行侧栏入口（整行可点）。onclick 缺省时渲染为不可点的静态行。
     *
     * @param {string} label    行文字
     * @param {string} icon     OW_ICONS 里的图标名（avatarUrl 给了就不看它）
     * @param {string} onclick  点击表达式；空 = 静态行
     * @param {string} [avatarUrl] 传了就把**图标位换成头像**：这一行代表的是「这个会话」，
     *                  群聊显示群头像、私聊显示对方头像，比一枚通用齿轮更能说明「我在看谁」。
     */
    function entryRow(label, icon, onclick, avatarUrl) {
        var ico = avatarUrl
            ? roomAvatarHtml(avatarUrl, 'xs', 'ow-avatar ow-avatar-xs ow-panel-entry-ico')
            : (function () {
                var d = OW_ICONS[icon] || OW_ICONS.gear;
                return '<svg class="ow-ico ow-panel-entry-ico" width="16" height="16" viewBox="0 0 24 24" fill="none"'
                    + ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + d + '</svg>';
            })();
        var arrow = '<span class="ow-panel-entry-arrow">›</span>';
        if (!onclick) {
            return '<div class="ow-panel-entry is-static">' + ico + '<span class="ow-panel-entry-t">' + esc(label) + '</span>' + arrow + '</div>';
        }
        return '<div class="ow-panel-entry" role="button" tabindex="0" onclick="' + onclick + '"'
            + ' onkeydown="if(event.key===\'Enter\'||event.key===\' \'){event.preventDefault();' + onclick + '}">'
            + ico + '<span class="ow-panel-entry-t">' + esc(label) + '</span>' + arrow + '</div>';
    }

    /**
     * 右键 / 点击菜单的条目 HTML（v1.3.85）。四处菜单（消息右键、点头像昵称、右键头像、
     * 成员搜索结果）原先各拼一遍 `<a>`，图标只能加在其中几处 —— 现在共用一个渲染口。
     *
     * 图标取值顺序：条目自带的 `icon` → 按文字查 OW_CTX_ICONS → 无图标。
     * 第二档是为**插件注入的条目**准备的：禁言 / 举报 / 屏蔽这些文字由各自插件 push，
     * 核心不认识它们，但也不能让这几行看起来缺了一块。
     */
    var OW_CTX_ICONS = {
        '查看个人资料': 'user', '发私信': 'chat', '私信': 'chat',
        '复制': 'copy', '引用': 'quote', '回复': 'quote', '撤回': 'undo', '删除': 'trash',
        '收藏为贴纸': 'star', '禁言 TA': 'mute', '禁言': 'mute', '举报': 'flag',
        '屏蔽此人': 'userX', '取消屏蔽': 'user', '拉黑': 'userX', '取消拉黑': 'user',
        '群文件操作': 'file', '创建群聊': 'plus', '设置': 'gear', '管理后台': 'monitor',
        '退出登录': 'logout'
    };
    /**
     * 菜单条目 → HTML。四种形态：
     *   · 普通行  { t:'设置', icon:'gear', run:fn }
     *   · 首行    { head:true, html:'…', run:fn }  —— 「头像 + 昵称」胶囊那类非纯文字内容
     *   · 分隔线  { sep:true }
     *   · 禁用行  { t:'…', dis:true, tip:'…' }
     * ⚠️ data-i 记的是**数组下标**（含 sep/head），点击委托靠它反查 _ctxItems；
     *    sep 没有 <a>、不会被点到，head 的 <a> 里套了 span，所以委托那边要向上找最近的 <a>。
     * v1.5.0：head/sep 是给账号菜单首行（用户胶囊）加的，其余四处菜单的调用方式不变。
     */
    function ctxItemsHtml(items) {
        var h = '', i, ic, it;
        for (i = 0; i < items.length; i++) {
            it = items[i] || {};
            if (it.sep) { h += '<div class="ow-ctx-sep"></div>'; continue; }
            if (it.head) {
                h += '<a class="ow-ctx-item ow-ctx-head" href="javascript:;" data-i="' + i + '"'
                   + ' aria-label="' + esc(it.aria || '个人主页') + '">'
                   + '<span class="ow-ctx-me" id="owBrandMe">' + (it.html || '') + '</span></a>';
                continue;
            }
            ic = it.icon || OW_CTX_ICONS[it.t] || '';
            h += '<a class="ow-ctx-item" href="javascript:;" data-i="' + i + '">'
               + (ic ? owIco(ic, 14, 'ow-ctx-ico') : '') + esc(it.t) + '</a>';
        }
        return h;
    }

    /**
     * 通知正文渲染（v1.3.56）。
     * 库里存的是 kind + 参数，模板表随 ?action=notices 一起下发：
     * **先翻译整条模板、再填参数** —— 语言包按中文原文整句精确匹配，
     * 先把参数拼成整句就永远匹配不上，英文界面会冒出一句中文。
     * 拿不到模板（未知 kind / 老数据）才回落到 body 快照，保证不出现空白行。
     */
    function owNoticeText(row, texts) {
        var tpl = (texts && texts[row.kind]) ? String(texts[row.kind]) : '';
        if (!tpl) return String(row.body || '');
        if (w.OwI18n && w.OwI18n.t) tpl = w.OwI18n.t(tpl);
        var p = row.params || {};
        return tpl.replace(/\{([a-z_]+)\}/gi, function (m, key) {
            return (p[key] === undefined || p[key] === null) ? '' : String(p[key]);
        });
    }

    /**
     * 动态文案翻译（v1.3.103）：**先整句查语言包、再填参数**，与 owNoticeText 同一口径。
     *
     * 为什么不能直接拼：引擎按**整个文本节点**精确匹配，句子一掺进数字（版本号、文件数）
     * 就永远匹配不上，英文界面会冒出半截中文。所以带数字的句子一律写成 `{m}` 占位模板。
     * 纯静态的句子不用走这里 —— OwI18n 的 MutationObserver 会遍历新插入的元素子树，
     * 只要字典里有整句键就会翻。这里管的是「observer 看不到」的两类：
     * 拼了参数的整句、以及用 textContent 直接改写的已有节点（加的是纯文本节点，observer 不遍历）。
     * 没命中键时原样返回中文，行为与改动前一致。
     */
    function owTr(tpl, params) {
        var s = (w.OwI18n && w.OwI18n.t) ? w.OwI18n.t(tpl) : String(tpl);
        if (!params) return s;
        return String(s).replace(/\{([a-z_]+)\}/gi, function (m, key) {
            return (params[key] === undefined || params[key] === null) ? '' : String(params[key]);
        });
    }

    /* ==========================================================================
       owTabs：通用分组标签（v1.3.113）
       ========================================================================== */
    /**
     * 结构契约与配色见 index.css 的 .ow-tabs 注释。
     * ⚠️ 作用域 = 标签条的父节点，面板必须是它的直接子元素：
     * 一个页面里两组标签（比如后台某页 + 弹窗）才会互不干扰。
     * 标签条上写 onclick="owTabs.click(this, event)" 即可，不需要初始化脚本 ——
     * 后台页与弹窗都是 innerHTML 注入的，逐个绑监听会在重绘后失效。
     */
    var owTabs = {
        /** 顶部标签条里的一个标签（卡片式：标题 + 可省副标题） */
        html: function (name, label, sub) {
            return '<button type="button" class="ow-pagetab" data-tab="' + esc(name) + '">'
                + '<b>' + esc(label) + '</b>' + (sub ? '<span>' + esc(sub) + '</span>' : '') + '</button>';
        },
        /** 侧边导航里的一个条目（与上面同一套切换逻辑，只是形态不同） */
        navHtml: function (name, label, icon) {
            return '<button type="button" class="ow-navset-item" data-tab="' + esc(name) + '">'
                + (icon ? owIco(icon, 16, 'ow-navset-ico') : '') + '<span>' + esc(label) + '</span></button>';
        },
        /** 标签条 / 导航的点击委托入口（this 指向带 [data-tab] 子元素的那个容器） */
        click: function (bar, e) {
            var t = e ? e.target : null;
            while (t && t !== bar && !(t.getAttribute && t.getAttribute('data-tab'))) t = t.parentNode;
            if (t && t !== bar) owTabs.show(bar, t.getAttribute('data-tab'));
        },
        /**
         * 找某一页的容器 —— 插件往分组式弹窗（个人设置）塞区块时用这个，**别插到弹窗根节点**：
         * 根节点上的东西不属于任何一页，会在每个分组里都露一遍，还要跟固定高度抢地方，
         * 结果就是内容区被压扁、面板内部滚动（v1.3.114 锁死高度之后，2FA 与屏蔽列表正是这样翻的车）。
         * 老核心没有 [data-pane] 结构时返回 null，调用方自己回落到旧写法，两边都不报错。
         */
        pane: function (root, name) {
            if (!root || !root.querySelectorAll) return null;
            var all = root.querySelectorAll('[data-pane]');
            for (var i = 0; i < all.length; i++) {
                if (all[i].getAttribute('data-pane') === name) return all[i];
            }
            return null;
        },
        /**
         * 只加减 is-active，绝不重写 className —— 顶部标签（.ow-pagetab）与
         * 侧边导航（.ow-navset-item）共用这一个函数，重写基类会把对方的样式全丢掉。
         * 老内核没有 classList 时走字符串分支，并顺手压掉多余空格。
         */
        set: function (n, on) {
            if (n.classList) { n.classList[on ? 'add' : 'remove']('is-active'); return; }
            var parts = (' ' + n.className).split(/\s+/).filter(function (x) { return x && x !== 'is-active'; });
            if (on) parts.push('is-active');
            n.className = parts.join(' ');
        },
        /**
         * 切到某个分组：只改显隐与选中类，输入框始终在 DOM 里（切走再切回不丢未保存的编辑）。
         * 作用域：[data-tab] 取 bar 的直接子元素，[data-pane] 取 bar 的兄弟节点，
         * 所以「导航 + 面板」必须在同一个父容器里；两组标签就各自套一个容器。
         */
        show: function (bar, name) {
            if (!bar) return;
            var i, n;
            // 标签/导航项在 bar **里面**，面板在 bar **外面**（兄弟）—— 早先只走了外面那一层，
            // 结果面板切得动、标签永远不高亮，两种形态都中招。
            for (i = 0; i < bar.children.length; i++) {
                n = bar.children[i];
                if (n.getAttribute && n.getAttribute('data-tab') !== null) {
                    owTabs.set(n, n.getAttribute('data-tab') === name);
                }
            }
            var scope = bar.parentNode;
            if (!scope) return;
            for (i = 0; i < scope.children.length; i++) {
                n = scope.children[i];
                if (n === bar || !n.getAttribute) continue;
                if (n.getAttribute('data-pane') !== null) {
                    n.style.display = n.getAttribute('data-pane') === name ? '' : 'none';
                }
            }
        },
        /** 当前选中的分组名（插件偶尔要按分组决定校验哪几个字段） */
        current: function (bar) {
            if (!bar) return '';
            var i, n;
            for (i = 0; i < bar.children.length; i++) {
                n = bar.children[i];
                if (n.getAttribute && n.getAttribute('data-tab') !== null
                    && (' ' + n.className + ' ').indexOf(' is-active') >= 0) return n.getAttribute('data-tab');
            }
            return '';
        }
    };

    /* ==========================================================================
       OwAuth：登录 / 注册 / 找回密码
       ========================================================================== */
    /**
     * 表单防重入（v1.3.131）：一次点击发出两个请求，代价是**失败计数翻倍**。
     * 登录页尤其致命 —— 后台「失败 10 次锁 15 分钟」按次数算，
     * 站方 2026-10-14 的日志里就有两对相隔 1 秒的 login_fail，点五六下就被锁在门外。
     *
     * @param {HTMLFormElement} form  被提交的表单
     * @param {Function} run          run(done)：在里面发请求，请求一回来就调 done()
     * @returns {boolean} 是否真的开始提交（false = 上一个请求还没回来，这次直接忽略）
     */
    function submitOnce(form, run) {
        // 统一走 OwBusy.form（同一套引用计数与安全超时）；保留此函数是为了兼容
        // 老插件调用的 OwAuth.submitOnce（twofa 等），行为与 v1.3.131 完全一致。
        return OwBusy.form(form, run, { timeout: 15000 });
    }

    var OwAuth = {
        /** 供插件复用：接管登录表单提交时同样要防重入（见 plugins/twofa/chat.js） */
        submitOnce: submitOnce,

        init: function (opt) {
            OwApi.key = opt.key;
            OwApi.setServerTime(opt.ts);   // 用服务器时间校正本机时钟偏差
            var form = document.querySelector('.ow-auth-form');
            if (!form) return;
            var mode = form.getAttribute('data-mode');
            var msg = form.querySelector('.ow-form-msg');

            var img = $('owCaptchaImg');
            if (img) img.onclick = function () { img.src = '?action=captcha&_=' + new Date().getTime(); };

            var codeBtn = form.querySelector('[data-sendcode]');
            if (codeBtn) codeBtn.onclick = function () {
                var email = form.querySelector('[name=email]').value;
                if (!email) { msg.innerHTML = '<span style="color:var(--ow-red)">请先填写邮箱</span>'; return; }
                codeBtn.disabled = true;
                // v1.3.63：发验证码是 AJAX，表单里人机验证组件注入的隐藏字段不会自动带上，
                // 必须显式拼进请求 —— 否则插件接管后服务端永远收不到 token，发码被一路拒掉。
                var data = { email: email, type: codeBtn.getAttribute('data-sendcode') };
                if (w.OwCv) {
                    var cf = OwCv.fields(form), ck;
                    for (ck in cf) if (cf.hasOwnProperty(ck)) data[ck] = cf[ck];
                }
                OwApi.post('send_code', data, function (r) {
                    // token 一次性：闸门在业务之前就会把它核销掉，所以无论成败都要重置，
                    // 下一次点「发验证码」得重新解一次（留着旧值只会必然被拒）。
                    if (w.OwCv) OwCv.reset(form);
                    msg.innerHTML = '<span style="color:' + (r.ok ? 'var(--ow-green)' : 'var(--ow-red)') + '">' + esc(r.msg) + '</span>';
                    if (!r.ok) { codeBtn.disabled = false; return; }
                    // 秒数取服务端返回值：重发间隔在后台可调（mail_rate_limit），
                    // 写死 60 就会出现「按钮转完了、再点还是提示太频繁」。
                    var n = parseInt(r.wait, 10);
                    if (r.remain > 0) n = Math.max(1, parseInt(r.remain, 10));
                    if (!(n > 0)) n = 60;
                    var tm = setInterval(function () {
                        codeBtn.innerHTML = n + 's';
                        if (--n < 0) { clearInterval(tm); codeBtn.disabled = false; codeBtn.innerHTML = '发验证码'; }
                    }, 1000);
                });
            };

            form.onsubmit = function (e) {
                e.preventDefault();
                var data = {}, i, els = form.elements;
                // 跳过服务端预置的 ts/sign 隐藏域：由 OwApi 用实时值重新签名
                for (i = 0; i < els.length; i++) {
                    if (!els[i].name || els[i].name === 'ts' || els[i].name === 'sign') continue;
                    data[els[i].name] = els[i].value;
                }
                // 上一个请求没回来之前，这次提交直接丢掉 —— 一次点击发两个请求，
                // 失败计数就是翻倍地涨，登录页尤其致命（见 submitOnce 的注释）
                submitOnce(form, function (done) {
                    msg.innerHTML = '提交中…';
                    OwApi.post(mode === 'login' ? 'login' : mode, data, function (r) {
                        if (r.ok) {
                            // 成功就不解禁了：800ms 后整页跳走，中间再点一下只会又发一次
                            msg.innerHTML = '<span style="color:var(--ow-green)">' + esc(r.msg) + '</span>';
                            // 注册成功不进入聊天（后端注册本就不建会话）：跳登录页由用户手动登录。
                            // v1.3.104 例外：嵌入授权弹窗（?embed_auth=1）里注册已自动登录，
                            // 直接进聊天页——弹窗里的聊天页检测到授权标记后会完成「签发授权码」收尾。
                            setTimeout(function () {
                                location.href = mode === 'login' ? '?page=chat'
                                    : mode === 'reset' ? '?page=login'
                                    : (r.autologin ? '?page=chat' : '?page=login&registered=1');
                            }, 800);
                            return;
                        }
                        done();
                        msg.innerHTML = '<span style="color:var(--ow-red)">' + esc(r.msg) + '</span>';
                        if (r.captcha && $('owCaptchaRow')) {
                            $('owCaptchaRow').style.display = 'block';
                            if (img) img.src = '?action=captcha&_=' + new Date().getTime();
                        }
                    });
                });
            };
        }
    };

    /* ==========================================================================
       OwChat：聊天主程序
       ========================================================================== */
    /**
     * 通用聊天列表轮子（v1.1.0）：群聊与私聊会话共用的列表渲染器。
     * 与业务无关——只负责「头像 + 名称 + 右侧摘要/时间 + 标签 + 选中态」的 DOM 组装与点击分发，
     * 数据形状：[{ conv:'room'|'dm', id, peer, name, avatar, last_at, last_text, tag }]
     * opts: { container, activeKey, onClick(item, el), emptyText }
     */
    var ChatList = {
        time: function (ts) {
            if (!ts) return '';
            var d = new Date(ts * 1000), now = new Date();
            function p(n) { return (n < 10 ? '0' : '') + n; }
            var sameDay = d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth() && d.getDate() === now.getDate();
            if (sameDay) return p(d.getHours()) + ':' + p(d.getMinutes());
            if (d.getFullYear() === now.getFullYear()) return (d.getMonth() + 1) + '/' + d.getDate();
            return d.getFullYear() + '/' + (d.getMonth() + 1) + '/' + d.getDate();
        },
        /**
         * 未读徽标文案与尺寸档（v1.3.19）。
         *  - 超过 99 条显示 **99**（按需求：不显示 99+ / 100+，直接截到 99）；
         *  - 尺寸按位数分三档：1 位 16px、2 位 18px、3 位 20px —— 圆跟着数字变大，
         *    但封顶后最大就是 20px，不会把 118px 的文字区挤掉太多。
         * 返回 [文案, 档位类名]。
         */
        badge: function (n) {
            n = parseInt(n, 10) || 0;
            if (n <= 0) return '';
            var txt = n > 99 ? '99' : String(n);
            var lv  = txt.length >= 3 ? ' lg' : (txt.length === 2 ? ' md' : '');
            return '<span class="ow-cl-badge-n' + lv + '">' + txt + '</span>';
        },
        render: function (list, opts) {
            var box = typeof opts.container === 'string' ? $(opts.container) : opts.container;
            if (!box) return;
            /* v1.5.30：内容没变就不重排。
               每来一条新消息都会 loadConversations() → render()，无条件 innerHTML 会把
               左侧列表的滚动位置拽回顶部（会话多时尤其明显），并把所有头像 <img> 重建一遍。
               签名挂在**容器**上 —— 同一个渲染器被会话列表 / 联系人列表 / 通知列表共用，
               用模块级变量会让三者互相作废。 */
            var sig = (opts.activeKey || '') + '|' + (opts.hideTime ? 1 : 0) + '|' + (opts.emptyText || '')
                    // 时间文案依赖「今天/昨天」的判定：页面挂着跨天时要重渲一次，
                    // 否则昨天 23:59 的消息会一直显示成「23:59」。
                    + '|' + new Date().toDateString();
            var sigList = list || [], si, sc;
            for (si = 0; si < sigList.length; si++) {
                sc = sigList[si];
                sig += '|' + sc.conv + ':' + (sc.conv === 'dm' ? sc.peer : sc.id)
                     + ',' + (sc.name || '') + ',' + (sc.last_text || '') + ',' + (sc.last_at || 0)
                     + ',' + (sc.unread || 0) + ',' + (sc.pinned ? 1 : 0) + ',' + (sc.avatar || '')
                     + ',' + (sc.tag || '') + ',' + (sc.need_password ? 1 : 0) + ',' + (sc.role || '');
            }
            if (box._clSig === sig) return;
            box._clSig = sig;
            if (!list || !list.length) {
                box.innerHTML = '<li class="ow-cl-empty">' + esc(opts.emptyText || '暂无会话') + '</li>';
                return;
            }
            var html = '', i, c;
            for (i = 0; i < list.length; i++) {
                c = list[i];
                var key = c.conv + ':' + (c.conv === 'dm' ? c.peer : c.id);
                // v1.3.19：当前打开的会话不显示未读徽标（正看着呢，不算未读）
                c.active = (key === opts.activeKey);
                // v1.1.19：群聊无自定义头像 → 统一默认剪影图；私聊沿用「首字色块」。
                // ⚠️ 必须按 c.conv 区分：私聊的 avatar 为空时用首字是**用户**语义，
                // 群聊用首字会与「群名首字随机色」的历史行为混在一起，看着像乱码。
                var isRoom = c.conv === 'room';
                var icon = isRoom
                    ? roomAvatarHtml(c.avatar, true, 'ow-cl-icon')
                    : (c.avatar
                        ? '<span class="ow-cl-icon"><img src="' + esc(c.avatar) + '" alt="" loading="lazy" decoding="async"></span>'
                        : '<span class="ow-cl-icon">' + esc((c.name || '?').charAt(0)) + '</span>');
                html += '<li class="ow-cl-item' + (key === opts.activeKey ? ' active' : '') + (c.pinned ? ' is-pinned' : '') + '" data-key="' + esc(key) + '"'
                      + (c.conv === 'dm' ? ' data-dm="' + esc(c.peer) + '"' : ' data-room="' + (c.conv === 'room' ? c.id : 0) + '"')
                      + ' data-name="' + esc(c.name) + '" data-pw="' + (c.need_password ? 1 : 0) + '"'
                      + ' data-pin="' + (c.pinned ? 1 : 0) + '">'
                      + icon
                      + '<span class="ow-cl-main">'
                      // v1.2.28：置顶标记放在**标题内部最前**（与微信一致）。
                      // ⚠️ 必须放进 .ow-cl-title 里，不能放在 .ow-cl-main 下 ——
                      //   .ow-cl-main 是 column flex，多一个子节点就**独占一行**，
                      //   会把标题和摘要挤成三行。title 本身被 flex blockify，
                      //   里面的 inline span 才与文字同行。
                      + '<span class="ow-cl-title">'
                      + (c.pinned ? '<span class="ow-cl-pin" title="已置顶"></span>' : '')
                      + esc(c.name) + (c.tag ? '<span class="ow-cl-tag">' + esc(c.tag) + '</span>' : '') + '</span>'
                      + '<span class="ow-cl-sub">' + esc(c.last_text || '') + '</span>'
                      + '</span>'
                      // v1.2.28：hideTime 用于联系人列表（无消息流，时间没有参考价值）。
                      // 不渲染这个 span 而不是传空串 —— 空 span 仍占位、仍可能带 margin。
                      // v1.3.19：时间与未读徽标一起放进右侧竖排容器 .ow-cl-side
                      // （徽标在时间**下方**，按需求）。⚠️ 不能把徽标直接塞进 .ow-cl-item
                      // 根部 —— 那样它会参与横向 flex 排版，把时间挤到中间或换行。
                      + (opts.hideTime
                            ? ''
                            : '<span class="ow-cl-side">'
                              + '<span class="ow-cl-time">' + ChatList.time(c.last_at) + '</span>'
                              + (c.unread > 0
                                  ? '<span class="ow-cl-badge' + (c.active ? ' is-current' : '') + '"'
                                    + ' title="' + c.unread + ' 条未读">' + ChatList.badge(c.unread) + '</span>'
                                  : '')
                              + '</span>')
                      + '</li>';
            }
            box.innerHTML = html;
            var items = box.getElementsByTagName('li'), k;
            for (k = 0; k < items.length; k++) {
                if (items[k]._noClick) continue;
                items[k].onclick = function () { if (opts.onClick) opts.onClick(this); };
            }
        },
        /** 定位并高亮当前会话对应的行 */
        activate: function (container, key) {
            var box = typeof container === 'string' ? $(container) : container;
            if (!box) return;
            var items = box.getElementsByTagName('li'), i;
            for (i = 0; i < items.length; i++) {
                items[i].className = items[i].className.replace(' active', '');
                if (items[i].getAttribute('data-key') === key) items[i].className += ' active';
            }
        }
    };
    w.ChatList = ChatList;

    var OwChat = {
        cfg: null, room: 0, since: 0, polling: false, failCount: 0,
        historyDone: false, loadingHistory: false, sound: true, lastMsgId: 0,
        emojis: '😀 😁 😂 🤣 😊 😍 😘 😜 🤔 😎 😴 😷 🤒 😱 😭 😡 👍 👎 👏 🙏 💪 🤝 ❤️ 💔 🎉 🔥 ⭐ 🌹 🍀 🎂 ☕ 🍺 ⚽ 🏀 🚀 ✈️ 🐱 🐶 🦉 🌙 ☀️ 🌈'.split(' '),

        /** v1.5.4：站点自定义的「积分」名称（后台 系统设置 → 基本信息 → 积分名称）。
         *  全站（含插件前台）凡是要写「积分」二字的地方都走它，站点就能把它叫成
         *  「金币 / 星光 / 猫粮」。空 = 顶层默认「积分」；插件侧建议这样取：
         *      var pn = (w.OwChat && OwChat.pointsName) ? OwChat.pointsName() : '积分';
         *  ⚠️ 返回值是**纯文本**，拼 HTML 时仍要自己 esc()。 */
        pointsName: function () {
            var s = (this.cfg && this.cfg.settings) || {};
            return s.points_name || '积分';
        },

        init: function (cfg) {
            this.cfg = cfg;
            OwApi.key = cfg.key;
            OwApi.setServerTime(cfg.ts);
            this.room = cfg.room;
            this.syncRoomOwner();
            this.sound = cfg.settings.sound === '1';
            /* v1.3.164：提示音开关是**本机偏好**（与 owl_theme / owl_fontsize 同一类），
               站点默认（后台 sound_default）之上允许本机覆盖。此前点了只翻内存标志、什么也不存，
               刷新即回到站点默认 —— 站方报的「提示音状态一刷新就失效」就是这个。 */
            try {
                var sv = w.localStorage.getItem('owl_sound');
                if (sv === '0' || sv === '1') this.sound = sv === '1';
            } catch (e) {}
            var self = this;

            /* 刷新要停在原来那个 rail 标签上。这里不能直接恢复：插件脚本排在
               OwChat.init() 之后引入（见 index.php renderChat 收尾），此刻插件标签的
               onShow 还没注册，切过去只会得到一块空面板。挂 DOMContentLoaded——
               插件包是页面末尾的阻塞脚本，这个事件必然在它执行完之后才触发。 */
            var restoreRailTab = function () { self.restoreTab(); };
            if (d.readyState === 'loading' && d.addEventListener) d.addEventListener('DOMContentLoaded', restoreRailTab);
            else setTimeout(restoreRailTab, 0);

            this.loadConversations();   // 会话列表（群聊+私聊聚合）——私聊路由要靠它取昵称
            this.renderConversations();
            // v1.3.52：通知标签的侧栏行首屏就渲染出来（红点数取自 boot，不必等接口）。
            // 游客没有该标签、#owNoticeList 也不存在，函数内部自行返回。
            this.renderNoticeRow();
            this.startConvPoll();       // v1.1.0：列表低频轮询，新消息会话自动前置（不打断当前聊天）
            this.bindFocusRead();       // v1.3.19：窗口聚焦时补标当前会话已读
            this.renderMe();
            this.buildEmojiPanel();
            this.bindEvents();
            this.renderRoomPanel();      // v1.1.1：初始化右侧栏群聊信息区
            this.syncMembersSearchBtn(); // v1.3.24：成员搜索按钮显隐（游客/私聊隐藏）
            // v1.3.25：主题已在 <head> 的内联脚本里预置好（防首屏闪一下浅色），
            // 这里只补「监听系统主题变化」。再次 applyTheme 是幂等的，不会闪。
            this.initTheme();

            // P1（WS 实时层）：按服务端开关尝试连一条 WS 加速层（心跳 / 在线状态）。
            // 失败、断开、未启用一律静默 —— 消息与成员仍由长轮询照常拉取，这里纯并行加速。
            this.rt.start();

            // 地址路由：私聊 ?dm=user:12 优先（v1.1.0），否则规范化为 ?room=当前群聊（replace）
            var dmFromUrl = this.dmFromUrl();
            // 前进/后退（或手动改 URL 回车）→ 切换到对应群聊 / 私聊
            w.onpopstate = function () {
                // ★ v1.1.0：dm 与 room 在 URL 上互斥（见 chat-url-mutex 约束）。
                //   正常情况下只会命中其中一个；这里以 URL 实际内容为准，
                //   若两个都在（历史遗留 / 外部链接），优先认群聊 room，避免莫名跳进私聊。
                var rid = self.roomFromUrl();
                if (rid) {
                    if (self.dm || rid !== self.room) {
                        for (var i = 0; i < cfg.rooms.length; i++) {
                            if (cfg.rooms[i].id === rid) { self.switchRoom(rid, cfg.rooms[i].name, null, true); return; }
                        }
                    }
                    return;
                }
                var dpeer = self.dmFromUrl();
                if (!dpeer) return;
                if (self.dm && self.dm.peer === dpeer) return;
                self.openDm(dpeer, self.dmNameOf(dpeer));
            };

            if (dmFromUrl) {
                // 私聊视图：昵称取自会话列表，但列表是异步到达的。
                // openDm 允许传入临时名（'私聊'），列表到达后 loadConversations 会重渲染并补上正确昵称，
                // 所以这里直接打开即可，无需轮询等待。
                this.setDmUrl(dmFromUrl, true);
                this.openDm(dmFromUrl, this.dmNameOf(dmFromUrl));
                // ★ 不触发 _fireRoomSwitch：私聊下 this.room=0，插件按 room_id=0 取数据是错的；
                //   群级装饰由 openDm 末尾的 _fireViewChange 通知插件清理。
                return;   // 私聊入口不加载群聊历史、不启动群聊长轮询
            }
            // v1.3.1：游客未点选任何群时（服务端下发 room=0）不进任何群——
            // 不发 room_join / history、不拉轮询，输入区保持禁用，等用户自己点一个群。
            // 以前这里会拿 boot 里的第一个群直接加载，等于「还没点就已经在群里」，能直接发言。
            if (!this.room) {
                this.applyInputGate('room');
                this.renderMembers([], null);   // 右侧栏同步回到空态
                return;
            }
            this.setRoomUrl(this.room, true);

            // 初始加载历史：是否需密码由服务端判定（管理员/已授权会直接放行，不会弹窗）
            var first = null, i;
            for (i = 0; i < cfg.rooms.length; i++) if (cfg.rooms[i].id === this.room) first = cfg.rooms[i];
            var load = function (password) {
                OwApi.post('room_join', { room_id: self.room, password: password || '' }, function (j) {
                    if (!j.ok) {
                        if (j.need_password) {
                            self.passForget(self.room);
                            self.askRoomPassword(self.room, first ? first.name : '', function (pw) { load(pw); });
                            return;
                        }
                        if (j.need_login) { location.href = '?page=login'; return; }
                    } else {
                        self.passRemember(self.room, j.ttl);
                    }
                    if (self.dm) return;   // 等待期间用户已切到私聊
                    /* v1.5.29：闸门直接用 room_join 已经算好的 is_member，不再为它跑一遍
                       全量名单；名单本身只在右侧栏打开时才拉（loadMembers 内部判可见性）。
                       此处已过 room_join 的密码 / 权限闸，密码房调用安全
                       （提前调用会撞 canEnter 403）。 */
                    if (j.ok) self.applyJoinGate(j.is_member === true, !(first && first.is_public === false));
                    self.loadMembers();
                    OwApi.post('history', { room_id: self.room, before: 0 }, function (r) {
                        if (self.dm) return;
                        if (r.ok) {
                            for (var k = 0; k < r.data.length; k++) self.addMessage(r.data[k], true);
                            if (r.data.length) self.since = r.data[r.data.length - 1].id;
                            self.scrollBottom();
                            if (r.data.length < 30) self.historyDone = true;
                            // v1.2.6 懒加载：填不满屏幕就自动往前补
                            self.fillIfShort();
                        } else if (r.need_password) {
                            self.passForget(self.room);
                            self.askRoomPassword(self.room, first ? first.name : '', function (pw) { load(pw); });
                            return;
                        }
                        self._roomPollRunning = true;
                        self.startPoll();
                    });
                });
            };
            load('');
            this._fireRoomSwitch();   // 首次进入也通知插件（公告等按群拉取）
        },

        /* ============ P1：WS 实时加速层（可选） ============
           只做两件事：① 连接即在线（心跳保活，私聊页停留也不再掉线）；
           ② 在线集合变化时收 wake 帧、立刻用**既有** room_members 接口刷成员
           （可见性布尔仍由服务端算 —— 口径零复制）。
           刻意不做：消息推送（P2）、发送迁移（P3）、停长轮询 —— P1 的消息仍走长轮询，
           这里纯并行加速。因此：连不上 / 断开 / 服务未启用 → 什么都不做，聊天不受任何影响。
           生命周期形状与参考实现对齐：onopen 开心跳、onclose 退避重连（5s×5 → 60s）。 */
        rt: {
            ws: null, on: false, opening: false, attempts: 0, hbTimer: 0, reTimer: 0,
            lastPong: 0, room: -1, _wmAt: 0,
            lastRoom: 0,   // 最近订阅过的房间（私聊视图重连时只订它、不补内容）
            dmSets: {},    // peer => 已知水位（P2 私聊多会话订阅；重连时原样重发）

            /** 入口：服务端 rt_enabled=0 时永不连接（后台一键切回长轮询靠它） */
            start: function () {
                var self = this;
                if (!OwChat.cfg || !OwChat.cfg.rt_enabled) return;
                if (this.ws || this.opening) return;
                this.opening = true;
                OwApi.post('ws_ticket', {}, function (r) {
                    self.opening = false;
                    if (!r || !r.ok || !r.url || !r.ticket) return;   // 未启用 / 限速 / 站点地址缺失：静默
                    var s;
                    try {
                        // P2：票据走 Sec-WebSocket-Protocol 子协议（浏览器 WS API 不能加请求头，
                        // 放 URL 会进访问日志）；服务端取 'owt.<ticket>' 并回选 'owt'。
                        s = new WebSocket(r.url, ['owt', 'owt.' + r.ticket]);
                    } catch (e) { return; }   // 老内核无 WebSocket / 不支持子协议：静默
                    self.ws = s;
                    s.onopen = function () { self.onOpen(); };
                    s.onmessage = function (ev) { self.onMsg(ev); };
                    s.onclose = function () { self.onClose(); };
                    s.onerror = function () {};   // 出错后必随 onclose，收口只写一处
                });
            },
            onOpen: function () {
                this.on = true; this.attempts = 0;
                this.lastPong = new Date().getTime();
                // P2 主通道切换：WS 在线 → 停消息长轮询（群聊与私聊都停；世代号让在途请求自毁）
                try {
                    if (OwChat._roomPollRunning) { OwChat._roomPollRunning = false; OwChat.pollGen = (OwChat.pollGen || 0) + 1; }
                    OwChat.dmGen = (OwChat.dmGen || 0) + 1;
                } catch (e) {}
                this.hb();
                // 重订：**不走 sub() 的去重早退**。原因（实测踩过）：连接建立前的
                // switchRoom 会调 sub() —— send 静默丢弃（!on），但 this.room 已置为新值；
                // 连接建立后 onOpen 再用 sub() 重订就被「room === this.room」早退吞掉，
                // 服务端从未收到订阅 → 该端收不到任何房间推送，直到某次断线重连把
                // room 清 -1 才自愈。这里无条件重发（重连后服务端订阅本就随旧连接销毁）。
                // 私聊视图只订最后所在房间（用户没在看它，不补内容——房间新消息由推送触发列表前置）。
                if (OwChat.dm) {
                    if (this.lastRoom > 0) { this.room = this.lastRoom; this.send({ t: 'sub', room: this.lastRoom, since: 0 }); }
                } else {
                    var r0 = OwChat.room || 0;
                    this.room = r0;
                    this.send({ t: 'sub', room: r0, since: OwChat.since || 0 });
                }
                for (var p in this.dmSets) {
                    if (this.dmSets.hasOwnProperty(p)) this.send({ t: 'dmsub', peer: p, since: this.dmSets[p] });
                }
            },
            /** 应用层心跳 25s：服务端 60s 无帧判死；25s 让「单帧丢失」也落在 45s 在线窗口内 */
            hb: function () {
                var self = this;
                clearTimeout(this.hbTimer);
                this.hbTimer = setTimeout(function () {
                    if (!self.on || !self.ws) return;
                    self.send({ t: 'hb' });
                    if (new Date().getTime() - self.lastPong > 75000) {   // 链路假死（代理吞包等）
                        try { self.ws.close(); } catch (e) {}
                        return;
                    }
                    self.hb();
                }, 25000);
            },
            /** 订阅房间 = 在线状态归属哪个群 + 消息推送范围；进私聊不调用（保持最后房间，人在线不掉） */
            sub: function (room, since) {
                room = parseInt(room, 10) || 0;
                since = parseInt(since, 10) || 0;
                if (room > 0 && room !== this.lastRoom) this.lastRoom = room;
                if (room === this.room) return;
                this.room = room;
                this.send({ t: 'sub', room: room, since: since });
            },
            /** 私聊会话订阅（P2）：多会话累积；记录该会话已知水位，重连时原样重发 */
            dmsub: function (peer, since) {
                if (!peer) return;
                since = parseInt(since, 10) || 0;
                this.dmSets[peer] = Math.max(this.dmSets[peer] || 0, since);
                this.send({ t: 'dmsub', peer: peer, since: this.dmSets[peer] });
            },
            /**
             * P3 发送帧：消息发送走 WS（daemon 回环站点执行 Chat::send，结果以 sent 帧回——
             * 由 onMsg 的 sent 分支交 onSendResult 收尾）。cid = 幂等键，重试沿用同一个。
             */
            sendSend: function (cid, payload) {
                this.send({
                    t: 'send', cid: cid,
                    room: parseInt(payload.room_id, 10) || 0,
                    type: payload.type || 'text',
                    content: payload.content || '',
                    to_user_id: payload.to_user_id || '',
                    to_guest_id: payload.to_guest_id || '',
                    to_nickname: payload.to_nickname || '',
                    quote: payload.quote || '',
                });
            },
            send: function (obj) {
                if (!this.on || !this.ws) return;
                try { this.ws.send(JSON.stringify(obj)); } catch (e) {}
            },
            onMsg: function (ev) {
                var m = null;
                try { m = JSON.parse(ev.data); } catch (e) { return; }
                if (!m || !m.t) return;
                if (m.t === 'pong') { this.lastPong = new Date().getTime(); return; }
                if (m.t === 'sent') {
                    // P3：发送结果帧（daemon 内部通道的应答）——与 HTTP 回调用同一套收尾（onSendResult）
                    try {
                        OwChat.onSendResult(String(m.cid || ''), { ok: m.ok === true, msg: m.msg || '', msg_row: m.msg_row || null });
                    } catch (e) {}
                    return;
                }
                if (m.t === 'msg' || m.t === 'msgdm') { this.onPush(m); return; }
                if (m.t === 'recall') {
                    // 撤回推送（P2）：与本地撤回同款收尾——直接移除气泡 + 清缓存 + 侧栏摘要刷新
                    var rid = parseInt(m.id, 10) || 0;
                    var el = document.getElementById('owMsg' + rid);
                    if (el && el.parentNode) el.parentNode.removeChild(el);
                    try { delete OwChat.msgCache[rid]; } catch (e) {}
                    try { OwChat.loadConversations(); } catch (e) {}
                    return;
                }
                if (m.t === 'wake') {
                    // 在线集合变了 → 立刻刷成员；500ms 客户端合并挡连上/断开高峰的扇出
                    var now = new Date().getTime();
                    if (now - this._wmAt < 500) return;
                    if (parseInt(m.room, 10) === (OwChat.room || 0) && OwChat.room > 0 && !OwChat.dm) {
                        this._wmAt = now;
                        // v1.5.29：面板收起时不刷成员（看不见的数据没人要）——
                        // 用户打开面板那一下会立刻补拉，不依赖这条推送。
                        if (OwChat.membersVisible()) { try { OwChat.loadMembers(true); } catch (e) {} }
                    }
                }
            },
            /**
             * 推送增量渲染（P2）：与长轮询回调同款语义——addMessage 自带按 id 去重
             * （发送方主标签页 HTTP 回显与其他标签页推送并发到达也不会双气泡）。
             * 非当前会话的推送不渲染内容，只刷新会话列表（前置 / 未读）。
             */
            onPush: function (m) {
                var list = (m && m.items) || [], i, n = list.length;
                if (!n) return;
                var isDm = (m.t === 'msgdm');
                var viewMatch = isDm
                    ? (!!OwChat.dm && OwChat.dm.peer === m.peer)
                    : ((parseInt(m.room, 10) || 0) === (OwChat.room || 0) && !OwChat.dm && !OwChat.noticeMode);
                if (!viewMatch) {
                    try { OwChat.loadConversations(); } catch (e) {}   // 其他会话：前置 / 未读
                    return;
                }
                for (i = 0; i < n; i++) {
                    try { OwChat.addMessage(list[i]); } catch (e) {}
                    if (list[i].id > OwChat.since) OwChat.since = list[i].id;
                }
                // 滚动与提示音：与长轮询回调同一口径（用户停在未读定位点时不打断）
                if (OwChat._unreadJumpVisible()) OwChat.showUnreadJump(0);
                else OwChat.scrollBottom();
                if (OwChat.sound) { try { beep(); } catch (e) {} }
                try { OwChat.loadConversations(); } catch (e) {}
            },
            onClose: function () {
                var wasOn = this.on;   // 真连上过才需要把长轮询拉回来（失败尝试 = 轮询从未停）
                this.on = false;
                clearTimeout(this.hbTimer);
                this.ws = null;
                this.room = -1;   // 重连后 onOpen 会重新 sub 当前房间
                if (wasOn) {
                    // P2 回落：WS 断开 → 消息长轮询拉回来（startPoll/dmPollLoop 自带世代号自愈，重复调用安全）
                    try {
                        if (OwChat.dm) OwChat.dmPollLoop();
                        else if (OwChat.room > 0) { OwChat._roomPollRunning = true; OwChat.startPoll(); }
                    } catch (e) {}
                }
                if (!OwChat.cfg || !OwChat.cfg.rt_enabled) return;
                var self = this;
                this.attempts++;
                var delay = this.attempts <= 5 ? 5000 : 60000;
                clearTimeout(this.reTimer);
                this.reTimer = setTimeout(function () { self.start(); }, delay);
            }
        },

        /** 从已加载的会话列表里取私聊对方昵称（供 URL 直达 / 前进后退时补名） */
        dmNameOf: function (peer) {
            var list = this.conversations || [], i;
            for (i = 0; i < list.length; i++) if (list[i].conv === 'dm' && list[i].peer === peer) return list[i].name;
            return '私聊';
        },

        bindEvents: function () {
            var self = this;
            // v1.2.20：侧栏标签条（消息 / 联系人 / 插件标签）
            self.bindRail();
            $('owBtnSend').onclick = function () { self.send(); };
            // v1.3.24：「所有成员」区的搜索按钮
            var msBtn = $('owMembersSearch');
            if (msBtn) msBtn.onclick = function () { self.openMembersSearch(); };
            var input = $('owInput');
            // 随内容自动增高；恢复上次手动拖出的高度
            try { self.inputUserH = parseInt(w.localStorage.getItem('owl_input_h') || '0', 10) || 0; } catch (e) {}
            self.bindInputResize();
            self.autoGrow();
            input.oninput = function () { self.autoGrow(); };
            input.onkeydown = function (e) {
                e = e || w.event;
                if (e.keyCode === 13 && !e.shiftKey) { e.preventDefault ? e.preventDefault() : (e.returnValue = false); self.send(); }
            };
            input.onpaste = function (e) {
                var items = (e.clipboardData || w.clipboardData).items;
                if (!items) return;
                for (var i = 0; i < items.length; i++) {
                    if (items[i].type.indexOf('image') === 0) {
                        var f = items[i].getAsFile();
                        // v1.2.41：走插件暴露的上传器；插件未启用则不粘贴上传
                        if (f && w.OwAttach) w.OwAttach.uploadImage(f);
                    }
                }
            };
            // v1.2.41：图片/文件按钮及其 file input 移入附件上传插件（v1.3.142 起该插件改走
            // chat.toolbar 钩子，按钮由服务端产出、本插件自己绑定）。插件停用时这两个按钮不存在。
            // ⚠️ 下方「粘贴上传图片」仍留在核心，但要走插件暴露的 OwAttach；
            //    插件未启用时静默跳过（不能因为没插件就整个粘贴功能报错）。
            // 表情面板：按钮切换 + 点击外部/Esc 关闭（原来只能靠再点一次按钮）
            var setEmoji = function (open) {
                var p = $('owEmojiPanel');
                if (!p) return;
                p.style.display = open ? 'block' : 'none';
            };
            $('owBtnEmoji').onclick = function () {
                var p = $('owEmojiPanel');
                setEmoji(getComputedStyle(p).display === 'none');
            };
            var sndBtn = $('owBtnSound');
            /* 首屏先把图标对到已存偏好上：服务端那一枚恒按「开」渲染，
               而 this.sound 已叠加了本机偏好（owl_sound，见 init）。偏好是「关」时，
               图标显示成有声、实际不出声 —— 点了才纠正过来，等于让用户先信错一次。 */
            if (sndBtn && !self.sound) sndBtn.innerHTML = sndBtn.getAttribute('data-off');
            sndBtn.onclick = function () {
                self.sound = !self.sound;
                this.innerHTML = this.getAttribute(self.sound ? 'data-on' : 'data-off');
                // v1.3.164：本机偏好落 localStorage（与日夜模式/字号同一类，不随「保存」提交）
                try { w.localStorage.setItem('owl_sound', self.sound ? '1' : '0'); } catch (e) {}
                toast(self.sound ? '提示音已开启' : '提示音已关闭');
            };
            // 窄屏浮层遮罩：侧栏或成员面板打开时显示
            var syncMask = function () {
                var m = $('owMask');
                if (!m) return;
                var open = $('owSidebar').className.indexOf('open') >= 0 || $('owOnline').className.indexOf('open') >= 0;
                m.className = open ? 'ow-mask show' : 'ow-mask';
            };
            // 侧栏开关：真正的切换（原写法只加不减，打开后无法关闭）
            var setSide = function (open) {
                var s = $('owSidebar');
                var c = s.className.replace(' open', '');
                s.className = c + (open ? ' open' : '');
                syncMask();
            };
            $('owToggleSide').onclick = function () {
                setSide($('owSidebar').className.indexOf('open') < 0);
            };
            // 群聊信息面板：宽屏用 hidden 收起（常驻侧栏），窄屏用 open 浮层（默认收起）
            // v1.1.1：侧栏内容改为「上方群聊信息 + 下方所有成员」，开关时需重渲染设置区
            var isNarrow = function () { return (document.documentElement.clientWidth || w.innerWidth || 1024) <= 960; };
            var setPanel = function (open) {
                var o = $('owOnline');
                var c = o.className.replace(' open', '').replace(' hidden', '');
                var narrow = isNarrow();
                o.className = c + (open ? (narrow ? ' open' : '') : (narrow ? '' : ' hidden'));
                syncMask();
                // v1.5.29：打开才拉「所有成员」。收起期间名单一律不请求（只标过期），
                // 所以这里必须先撑开类名再拉 —— loadMembers 的可见性判定读的就是它。
                if (open) self.loadMembers(true);
            };
            var togglePanel = function () {
                var o = $('owOnline');
                var open = isNarrow() ? (o.className.indexOf('open') >= 0) : (o.className.indexOf('hidden') < 0);
                setPanel(!open);
                if (!open) self.renderRoomPanel();   // 打开时刷新设置区（切群后内容可能已过期）
            };
            $('owTogglePanel').onclick = togglePanel;
            $('owOnlineClose').onclick = function () { setPanel(false); };
            // v1.2.31：品牌条搜索图标 → v1.3.48 改为侧栏顶部的搜索框（点它开弹窗，本身不可输入）
            var sb = $('owSideSearch');
            if (sb) sb.onclick = function () { self.openSearch(); };
            // 所有成员面板默认一律不展开（v1.0.101，v1.0.119 恢复：游客入口已移到顶栏）
            setPanel(false);
            $('owMask').onclick = function () { setSide(false); setPanel(false); };
            // 创建群聊：侧栏底部按钮（仅登录用户渲染）→ 弹窗
            if ($('owBtnCreateRoom')) $('owBtnCreateRoom').onclick = function () {
                setEmoji(false);
                self.roomCreateModal();
            };
            // 窄屏浮层：点击浮层外部时收起（成员面板 / 左侧栏 / 表情面板 / 「+」菜单）
            document.addEventListener ? document.addEventListener('click', function (e) {
                var o = $('owOnline'), s = $('owSidebar'), em = $('owEmojiPanel');
                var t = e.target || e.srcElement, inside = false, n = t;
                while (n) {
                    if (n === o || n === $('owTogglePanel') || n === s || n === $('owToggleSide')
                        || n === em || n === $('owBtnEmoji')) { inside = true; break; }
                    n = n.parentNode;
                }
                if (inside) return;
                setEmoji(false);          // 点空白处顺手收起表情面板
                if (o && o.className.indexOf('open') >= 0) setPanel(false);
                if (s && s.className.indexOf('open') >= 0) setSide(false);
            }) : (document.onclick = null);
            /* v1.2.6 懒加载：向上滚到接近顶部时自动拉更早的一批，
               不再有「加载更早消息…」那个可点入口。
               阈值 120px 而非 40px：触屏上手指惯性滑动很容易冲到 0，
               贴着 0 才触发会出现「已经到底了却没反应」的错觉。
               loadingHistory 是并发闸门 —— 一次请求未回前不再发第二次。 */
            $('owMessages').onscroll = function () {
                // v1.3.21：先判「是否已滚到底」—— 滚到底意味着未读看完，
                // 要清未读并隐藏右下角跳转按钮。放在最前面是因为下面那两行
                // 在 scrollTop>120 时会 return，若放在其后永远轮不到。
                self.onMsgScroll();
                if (this.scrollTop > 120) return;
                if (self.historyDone || self.loadingHistory) return;
                self.loadHistory();
            };
            // v1.3.21：右下角「N 条未读 ↓」按钮 —— 点击直达最新消息。
            // 用 addEventListener 而非 onclick：该元素由服务端静态输出、只此一个，
            // 两种都行；addEventListener 不会被后续可能的 innerHTML 重写抹掉。
            var jumpBtn = $('owUnreadJump');
            if (jumpBtn && !jumpBtn._jumpBound) {
                jumpBtn._jumpBound = true;
                jumpBtn.addEventListener('click', function () { self.unreadJumpGo(); });
            }
            /* v1.2.18：窗口尺寸变化后重算文件名中间省略。
               卡片是固定宽度，但 .ow-file-card 仍有 max-width:100% ——
               视口窄于卡片时会被压缩，可用宽度随之变化，必须重排。
               去抖 120ms：拖窗口时 resize 会连续触发几十次，
               每次都遍历全部消息重排会明显卡顿。 */
            var fitTimer = 0;
            window.addEventListener('resize', function () {
                if (fitTimer) clearTimeout(fitTimer);
                fitTimer = setTimeout(function () { fitTimer = 0; refitAllFileNames(); }, 120);
            });
            /* 消息时间：悬停「消息气泡」满 2 秒才显示，移开立即隐藏（v1.1.0）
               为什么不用 CSS :hover —— CSS 无法表达「持续满 N 秒」，
               一 hover 就出现会与快速扫读打架，也会让昵称行一直跳。
               用父容器委托而非逐条绑定：消息频繁重渲染（innerHTML 重建），
               逐条绑定会随重建丢失。
               计时器挂在 OwChat 上，切换会话时统一清理，避免残留。 */
            $('owMessages').onmouseover = function (e) {
                e = e || w.event;
                var t = e.target || e.srcElement, node = t;
                while (node && node !== this) {
                    if (node.className && (' ' + node.className + ' ').indexOf(' ow-msg ') >= 0) break;
                    node = node.parentNode;
                }
                if (!node || node === this) { self.hideMsgTime(); return; }
                if (self._timeMsg === node) return;          // 已在同一条计时中
                self.hideMsgTime();
                self._timeMsg = node;
                self._timeTimer = setTimeout(function () {
                    self._timeTimer = null;
                    if (self._timeMsg === node) node.className += ' ow-time-show';
                }, 2000);
            };
            $('owMessages').onmouseout = function (e) {
                e = e || w.event;
                var t = e.target || e.srcElement, node = t, to = e.relatedTarget || e.toElement;
                while (node && node !== this) {
                    if (node.className && (' ' + node.className + ' ').indexOf(' ow-msg ') >= 0) break;
                    node = node.parentNode;
                }
                if (!node || node === this) return;
                // 鼠标只是从消息内部移到了它自己的子元素上（不算真正离开）
                if (to && node.contains && node.contains(to)) return;
                self.hideMsgTime();
            };
            // v1.2.6：「加载更早消息…」入口节点已移除，改为滚动懒加载
            // （见上面的 onscroll），故这里不再需要 owLoadMore 的点击委托。
            // 右键消息气泡 → 操作菜单（@/私信/收藏/撤回，插件可追加）
            $('owMessages').oncontextmenu = function (e) {
                e = e || w.event;
                var t = e.target || e.srcElement, node = t;
                while (node && node !== this) {
                    if (node.id && /^owMsg\d+$/.test(node.id)) break;
                    node = node.parentNode;
                }
                if (!node || node === this || !node.id) { self.hideCtxMenu(); return; }
                var m = self.msgCache[parseInt(node.id.replace('owMsg', ''), 10)];
                if (!m || m.type === 'system' || m.recalled || m.deleted) { self.hideCtxMenu(); return; }
                if (e.preventDefault) e.preventDefault(); else e.returnValue = false;
                // 右键落点分流（v1.0.69）：点在头像上 → 对该「人」的操作菜单；
                // 点在消息内容 / 其它区域 → 对该「消息」的操作菜单（复制 / 引用 / 删除）
                var onAvatar = false, n2 = e.target || e.srcElement;
                while (n2 && n2 !== node) {
                    if (n2.className && String(n2.className).indexOf('ow-avatar') >= 0) { onAvatar = true; break; }
                    n2 = n2.parentNode;
                }
                if (onAvatar) self.showUserMenu(e.clientX || 0, e.clientY || 0, m);
                else self.showContentMenu(e.clientX || 0, e.clientY || 0, m);
                return false;
            };
            // 点击菜单外 / Esc 关闭
            document.onclick = function (e) {
                var menu = $('owCtxMenu');
                if (!menu || menu.style.display === 'none') return;
                var t = e.target || e.srcElement;
                var inside = false, n = t;
                while (n) { if (n === menu) { inside = true; break; } n = n.parentNode; }
                if (!inside) self.hideCtxMenu();
            };
            document.onkeydown = function (e) {
                e = e || w.event;
                if (e.keyCode === 27) { self.hideCtxMenu(); setEmoji(false); }
            };
            // 菜单项点击（委托）：执行对应操作后收起菜单
            $('owCtxMenu').onclick = function (e) {
                e = e || w.event;
                var t = e.target || e.srcElement;
                // ⚠️ 必须**向上找最近的 <a>**：账号菜单首行是「头像+昵称」胶囊，
                // 点的往往是里面的 span/span，直接判 t.tagName==='A' 会点了没反应。
                var a = null;
                while (t && t !== this) {
                    if ((t.tagName || '').toUpperCase() === 'A') { a = t; break; }
                    t = t.parentNode;
                }
                if (!a) return;
                var it = self._ctxItems[parseInt(a.getAttribute('data-i'), 10)];
                // 禁用项（游客）：给出明确提示，不静默无反应，也不执行动作
                if (it && it.dis) { self.hideCtxMenu(); toast(it.tip || '请先登录后再使用该功能'); return; }
                self.hideCtxMenu();
                if (it && it.run) it.run();
            };
            $('owModalMask').onclick = function (e) { if (e.target === this) self.closeModal(); };
            $('owImgViewer').onclick = function () { this.style.display = 'none'; };
            var lo = $('owBtnLogout');
            if (lo) lo.onclick = function () {
                self.confirmModal('确定退出登录吗？', function () {
                    OwApi.secure('logout', {}, function () { location.href = '?page=login'; });
                });
            };
            var st = $('owBtnSettings');
            if (st) st.onclick = function () { self.openSettings(); };
        },

        /* ---------- 密码房：通行缓存 + 自研密码弹窗 ---------- */
        // 缓存键（按房间），仅存"已授权到几点"，不存密码本身
        passCacheKey: function (roomId) { return 'owl_room_pass_' + roomId; },
        passCached: function (roomId) {
            var v = 0;
            try { v = parseInt(w.sessionStorage.getItem(this.passCacheKey(roomId)) || '0', 10); } catch (e) {}
            return v > Math.floor(new Date().getTime() / 1000);
        },
        passRemember: function (roomId, ttl) {
            if (!ttl || ttl <= 0) { this.passForget(roomId); return; }
            // 比服务端有效期提前 60 秒失效，避免边界上反复弹窗
            var until = Math.floor(new Date().getTime() / 1000) + Math.max(60, ttl - 60);
            try { w.sessionStorage.setItem(this.passCacheKey(roomId), String(until)); } catch (e) {}
        },
        passForget: function (roomId) {
            try { w.sessionStorage.removeItem(this.passCacheKey(roomId)); } catch (e) {}
        },
        // 需要密码（且本地无有效缓存）时弹出自研弹窗，验证成功回调 onOk
        askRoomPassword: function (roomId, roomName, onOk) {
            var self = this;
            this.openModal(
                '<h3>需要密码</h3>'
                + '<p class="ow-modal-desc">进入「' + esc(roomName) + '」需要密码，验证成功后在有效期内不必重复输入。</p>'
                + '<div class="ow-form-item"><label>房间密码</label>'
                + '<input class="ow-input" type="password" id="owRoomPw" autocomplete="off" placeholder="请输入房间密码"></div>'
                + '<div class="ow-modal-actions">'
                + '<button class="ow-btn ow-btn-ghost" id="owRoomPwCancel">取消</button>'
                + '<button class="ow-btn ow-btn-primary" id="owRoomPwOk">进 入</button></div>'
                + '<div class="ow-form-msg" id="owRoomPwMsg"></div>'
            );
            var input = $('owRoomPw'), msg = $('owRoomPwMsg');
            var submit = function () {
                var pw = input.value;
                if (!pw) { msg.innerHTML = '<span style="color:var(--ow-red)">请输入密码</span>'; return; }
                msg.innerHTML = '验证中…';
                OwApi.post('room_join', { room_id: roomId, password: pw }, function (r) {
                    if (!r.ok) {
                        msg.innerHTML = '<span style="color:var(--ow-red)">' + esc(r.msg) + '</span>';
                        input.select();
                        return;
                    }
                    self.passRemember(roomId, r.ttl);
                    self.closeModal();
                    if (onOk) onOk();
                });
            };
            $('owRoomPwOk').onclick = submit;
            $('owRoomPwCancel').onclick = function () { self.closeModal(); };
            input.onkeydown = function (e) {
                e = e || w.event;
                if (e.keyCode === 13) { e.preventDefault ? e.preventDefault() : (e.returnValue = false); submit(); }
            };
            try { input.focus(); } catch (e) {}
        },

        /* ---------- 房间 ---------- */
        /**
         * 拉取会话列表（群聊 + 私聊聚合，服务端已按最后活跃时间倒序），交给通用轮子渲染。
         * v1.1.0：取代旧的 renderRooms —— 群聊与私聊共用同一列表与同一交互。
         * v1.1.24：**联系人视图下不拉会话** —— 联系人要的是好友名单，
         * 会话列表此刻是多余的请求，且会覆盖 `conversations` 字段导致切回来时列表闪空。
         */
        /** 当前会话的 peer_key（与服务端 convKey 同口径：'room:5' / 'dm:user:20'） */
        curConvKey: function () {
            if (this.dm && this.dm.peer) return 'dm:' + this.dm.peer;
            if (this.room > 0) return 'room:' + this.room;
            return '';
        },

        /**
         * 标记当前会话已读到 lastId（v1.3.19）。
         *
         * 调用时机（缺一个就是体验缺口）：
         *  ① 切进会话（历史拉完后）—— 点开会话就该清零；
         *  ② 窗口重新聚焦 —— 用户切出去时可能漏看，切回来补标；
         *  ③ 正在看的会话收到新消息 —— 正看着的当然算已读。
         *
         * 两点注意：
         *  - 服务端是**只增不减**的，所以这里重复调用无害，不用自己去重；
         *  - 徽标要本地立即清掉再发请求，否则点开会话后要等下一次拉列表才消红点。
         */
        markRead: function (lastId) {
            lastId = parseInt(lastId, 10) || 0;
            if (lastId <= 0) return;
            var key = this.curConvKey();
            if (!key) return;
            var self = this;
            // 本地先把该会话的未读清零（乐观更新，请求失败下次拉列表会纠正回来）
            if (this._convList) {
                for (var i = 0; i < this._convList.length; i++) {
                    var c = this._convList[i];
                    if (('room:' + c.id === key && c.conv === 'room')
                        || ('dm:' + c.peer === key && c.conv === 'dm')) c.unread = 0;
                }
                if (this.view === 'chat') this.renderConversations();
            }
            // 1 秒内的重复标记合并成一次请求（切换会话时 history 与 poll 常连续触发）
            var now = new Date().getTime();
            if (this._lastMark && now - this._lastMark.t < 1000 && this._lastMark.key === key) {
                this._lastMark.id = Math.max(this._lastMark.id, lastId);
                return;
            }
            this._lastMark = { key: key, id: lastId, t: now };
            OwApi.post('conv_read', { peer_key: key, last_id: lastId }, function () {});
        },

        /** 窗口重新聚焦时补标当前会话已读（v1.3.19）：
         *  用户切出去这段时间可能漏看消息，切回来时应当清掉该会话的未读。
         *  document.hidden 是关键 —— 页面在后台时事件不触发，避免无谓请求。 */
        bindFocusRead: function () {
            if (this._focusReadBound) return;
            var self = this;
            window.addEventListener('focus', function () {
                if (document.hidden || (!self.dm && !self.room)) return;
                self.loadConversations();
                // ⚠️ 不能直接用 self.since —— 那是上次轮询的位置，切走期间到达的消息
                //   id 更大，会被漏标成未读。这里按视图分别问一次「现在末条是多少」。
                if (self.dm && self.dm.peer) {
                    // v1.5.30：timeout=0 —— 只问「现在末条是多少」，立即返回。
                    // 以前不传，服务端按默认 20 秒长轮询处理，切回标签页要白等一轮。
                    OwApi.post('dm_poll', { peer: self.dm.peer, since_id: self.since, timeout: 0 }, function (r) {
                        if (r && r.ok && r.messages && r.messages.length) {
                            self.since = r.messages[r.messages.length - 1].id;
                            self.markRead(self.since);
                        } else {
                            self.markRead(self.since);
                        }
                    });
                } else if (self.room > 0) {
                    OwApi.post('poll', { room_id: self.room, since: self.since, timeout: 0 }, function (r) {
                        if (r && r.ok && r.messages && r.messages.length) {
                            self.since = r.since;
                            self.markRead(r.since);
                        } else {
                            self.markRead(self.since);
                        }
                    });
                }
            });
            this._focusReadBound = true;
        },

        /* ---------- 未读定位与跳转（v1.3.21） ---------- */

        /**
         * 查询当前会话的「第一条未读消息 id」与未读条数（v1.3.21）。
         * 服务端口径与列表徽标完全一致（不数自己发的、排除已删除/撤回）。
         * @param {function} fn fn(anchorId, count)；anchorId=0 表示无未读 / 无位点
         */
        unreadAnchor: function (fn) {
            var key = this.curConvKey();
            if (!key) { if (fn) fn(0, 0); return; }
            var self = this;
            OwApi.post('conv_unread_at', { peer_key: key }, function (r) {
                if (!r || !r.ok) { if (fn) fn(0, 0); return; }
                // 请求返回时会话可能已切走 —— 丢弃过期结果，否则会把上一个会话的
                // 定位点画到当前会话上（表现为「一进去就跳到奇怪的位置」）
                if (self.curConvKey() !== key) return;
                if (fn) fn(parseInt(r.first_unread_id, 10) || 0, parseInt(r.count, 10) || 0);
            });
        },

        /**
         * 进会话后定位到「上次已读位置」（v1.3.21）。
         *
         * 为什么不是直接滚到底：几十条未读时一进会话就跳到最新，
         * 中间那一片未读等于没看见，用户会漏消息。
         *
         * 流程：问出第一条未读 id → 若存在，用 history(from_id) 把定位点附近的消息
         * 补齐 → 渲染完把该消息滚到**视口顶部偏上**（不是顶部，正好留一点上下文）
         * → 右下角浮出「N 条未读 ↓」。没有未读则照旧滚到底部。
         */
        jumpToUnread: function (done) {
            var self = this;
            var finish = function () { if (typeof done === 'function') done(); };

            // 把定位消息滚到视口顶部偏上 8px（不顶死，留一点上下文）。
            // 抽成独立函数：定位后还要补更早消息，补完必须**重定位** ——
            // fillIfShort 会在上方插入节点，scrollHeight 变化、视口相对位置跟着漂。
            var locate = function (anchorId) {
                var box = $('owMessages');
                var el = $('owMsg' + anchorId);
                if (!box || !el) return false;
                // el.offsetTop 相对 offsetParent，box.offsetTop 同理；用差值避免依赖父级定位方式
                box.scrollTop = el.offsetTop - box.offsetTop - 8;
                return true;
            };

            this.unreadAnchor(function (anchorId, count) {
                if (!anchorId) { self.scrollBottom(); finish(); return; }
                // 定位期间屏蔽 onMsgScroll 的「滚到底」判定（理由见该方法注释）
                self._locating = true;
                self._unreadAnchor = anchorId;
                self._unreadCount = count;
                // 定位点可能很靠后（未读很多），先把「定位点及其之后」这一段拉出来
                self.loadFromAnchor(anchorId, function () {
                    // 等 DOM 布局稳定（图片/引用可能还在撑高度）
                    setTimeout(function () {
                        if (!locate(anchorId)) {
                            // 定位点没渲染出来（消息已过期/被清理）→ 退回滚到底
                            self._locating = false;
                            self.scrollBottom();
                            finish();
                            return;
                        }
                        self.showUnreadJump(count);
                        // 补更早的消息让上下文完整；补完重定位，保证视口还停在定位点。
                        // ⚠️ 必须延后一帧，且补完再 locate 一次 ——
                        //   上方插入节点会把已定位的内容往上推，不重定位就会看到"跳了一下"。
                        setTimeout(function () {
                            var before = $('owMessages').scrollHeight;
                            self.fillIfShort();
                            setTimeout(function () {
                                var box = $('owMessages');
                                // 定位流程收尾才释放闸门（无论成败都要放，否则永久失去滚到底检测）
                                self._locating = false;
                                if (!box) { finish(); return; }
                                // fillIfShort 可能是异步（内部 loadHistory 还要发请求），
                                // 这里只做「高度变了就重新对一次」的兜底：
                                // 若它已经完成，补一次即可；若还没完成，onMsgScroll
                                // 与后续的 loadHistory 会各自维持现有视口，不会失效。
                                if (box.scrollHeight !== before) locate(anchorId);
                                finish();
                            }, 80);
                        }, 0);
                    }, 60);
                }, anchorId);
            });
        },

        /** 跳转按钮当前是否可见（长轮询据此决定要不要打断用户） */
        _unreadJumpVisible: function () {
            var btn = $('owUnreadJump');
            return !!(btn && btn.style.display !== 'none' && btn.style.display !== '');
        },

        /** 显示/隐藏右下角跳转按钮。count<=0 即隐藏（已读完了） */
        showUnreadJump: function (count) {
            var btn = $('owUnreadJump'), txt = $('owUnreadJumpText');
            this._unreadCount = count || 0;
            if (!btn || !txt) return;
            if (!(count > 0)) { btn.style.display = 'none'; return; }
            txt.innerHTML = (count > 99 ? 99 : count) + ' 条未读';
            btn.style.display = '';
        },

        /** 点击按钮：直达最新消息，并清掉按钮（等于「我已看完这些」） */
        unreadJumpGo: function () {
            this.scrollBottom();
            this.showUnreadJump(0);
            this._unreadAnchor = 0;
            this.markRead(this.since);
        },

        /**
         * 消息区滚动监听（v1.3.21）：手动滚到底部同样算「看完了」。
         *
         * 为什么必须有：jumpToUnread **故意不**在进会话时 markRead
         * （否则列表徽标与按钮条数会立刻消失），所以「已读」这件事被推迟到
         * 「用户真的滚到最后」。若只靠点击按钮清，未读会一直挂着 ——
         * 用户手动往下一拉看完，徽标却还在，下次进会话又定位一次。
         *
         * 只在按钮可见时才做事：平时滚动不该产生额外请求。
         */
        onMsgScroll: function () {
            // ⚠️ 定位动作本身也会触发 scroll 事件。若此时按钮**还没显示**就跳过 ——
            //   否则「未读只有 1~2 条、定位点离底部不到 24px」的场景下，
            //   定位刚做完就被这个判定当成「用户已滚到底」，按钮一闪就没了，
            //   等于白做。（showUnreadJump 在 locate() 之后才调用，所以这个闸门有效。）
            if (this._locating) return;
            var btn = $('owUnreadJump');
            if (!btn || btn.style.display === 'none' || btn.style.display === '') return;
            var box = $('owMessages');
            if (!box) return;
            // 距底部 24px 内即视为到达（各浏览器 scrollHeight 取整有 1~2px 误差）
            if (box.scrollHeight - box.scrollTop - box.clientHeight <= 24) {
                this.showUnreadJump(0);
                this._unreadAnchor = 0;
                this.markRead(this.since);
            }
        },

        loadConversations: function () {            var self = this;
            // v1.2.20：不在「消息」标签时不拉会话 —— 拉回来也会被别的面板覆盖，
            // 白打接口。⚠️ 判断必须写成 `!== 'chat'` 而不是 `=== 'friends'`：
            //   view 现在还可能是插件标签（如 'orders'），只挡 friends 会漏。
            if (this.view !== 'chat') return;
            OwApi.post('conversations', {}, function (r) {
                if (!r.ok) return;
                self.conversations = r.data;
                // v1.2.20：原「会话总数徽标」已随标题一起移除（标签条不需要计数，
                // 数量在列表里本就一目了然）。r.total 仍在响应里，留给需要的地方用。
                // 私聊视图的标题用的是进入时的昵称；若当时列表未到（标题为占位「私聊」），
                // 这里用刚取到的真实昵称补正，避免刷新后标题一直停在占位文案
                if (self.dm && self.roomName === '私聊') {
                    var nm = self.dmNameOf(self.dm.peer);
                    if (nm && nm !== '私聊') {
                        self.roomName = nm;
                        $('owRoomName').innerHTML = esc(nm);
                    }
                }
                self.renderConversations();
            });
        },

        /* ---------- 侧栏标签页（v1.2.20，通用 Tabs 组件） ----------
           v1.1.24 时「联系人」藏在品牌区下拉菜单里，入口深、发现不了。
           v1.2.20 把它上移为侧栏顶部的常驻标签，与「消息」并列。

           面板模型：核心只有两个面板，**都不靠 DOM 创建/销毁**：
             chat    → #owRoomList（就在 HTML 里，切换只改显隐）
             friends → 复用同一个 #owRoomList 容器渲染联系人（ChatList 轮子）
           所以「切面板」= 换数据源重渲染 + 切标签高亮，不涉及容器搬运。

           插件面板：入口由服务端 Plugin::collect('sidebar.rail') 产出按钮 HTML，
           或前端 OwChat.onRail 追加；其内容由插件自己往 #owSidePanels 里写。
           点插件入口时核心只切换高亮并触发 onShow，不碰插件内容。
           （v1.3.4：入口控件由侧栏顶部横向标签条改为最左侧图标条 rail；
             onSideTabs / bindSideTabs / Plugin::collect('sidebar.tabs') 为旧名兼容保留） */
        view: 'chat',              // 当前面板：'chat' | 'friends' | 插件自定义名
        friends: [],               // 好友列表（含签名，签名可能为空串）
        _sigProbe: null,           // signature 批量接口是否可用（探测一次，缓存结果）
        _tabsExt: [],              // 插件扩展：{ id, label, onShow } 数组
        _tabsInited: false,        // 标签条事件是否已绑定（只绑一次）
        railTabKey: 'owRailTab',   // 本机记住「刷新前停在哪个 rail 标签」的 sessionStorage 键

        /**
         * 插件扩展点：往侧栏标签条追加一个标签页。
         *
         * @param {Object} opt
         * @param {string}   opt.id      标签标识（= 服务端标签的 data-tab，需唯一）
         * @param {string}   opt.label   显示文字
         * @param {Function} opt.onShow  切到该标签时调用，参数为面板容器节点，
         *                               插件把自己的内容写进去（可重复调用，需自行做幂等）
         * @example
         * OwChat.onSideTabs({
         *     id: 'orders', label: '订单',
         *     onShow: function (panel) { panel.innerHTML = '<div>我的订单</div>'; }
         * });
         */
        onSideTabs: function (opt) {
            if (!opt || !opt.id || !opt.label) return;
            // 同 id 重复注册时覆盖，避免插件重复启用后出现两个同名标签
            for (var i = 0; i < this._tabsExt.length; i++) {
                if (this._tabsExt[i].id === opt.id) { this._tabsExt[i] = opt; return; }
            }
            this._tabsExt.push(opt);
        },

        /**
         * 插件扩展点（v1.3.4，推荐用这个）：往左侧一级导航条 rail 追加一个入口。
         * 与 onSideTabs 共用同一份注册表与面板容器（#owSidePanels），
         * 只是控件从侧栏顶部的横向标签条换成了最左侧的图标条。
         *
         * 服务端配套：插件在入口 HTML 里输出
         *   <button class="ow-rail-btn" data-tab="<id>" title="<label>">…svg…</button>
         * （通常用 Plugin::collect('sidebar.rail') 注册），
         * 前端再用 onRail 注册同 id 的 onShow 填充面板。
         *
         * @param {Object} opt
         * @param {string}   opt.id      唯一标识（= 按钮的 data-tab）
         * @param {string}   [opt.label] 文字说明（服务端按钮的 title 用）
         * @param {Function} opt.onShow  切到该入口时调用，参数为面板容器节点
         * @example
         * OwChat.onRail({
         *     id: 'orders', label: '订单',
         *     onShow: function (panel) { panel.innerHTML = '<div>我的订单</div>'; }
         * });
         */
        onRail: function (opt) { this.onSideTabs(opt); },

        /* ---------- 资料卡 meta 顶部扩展点（v1.2.42） ----------
           需求：等级要**固定在 .ow-card-meta 的最上面**，不能被其它插件的行挤下去。
           做法：核心在渲染 meta 时把本扩展点的输出**前置**到既有行之前，
           而不是给插件一个空 div 让它们自己 append（那样谁先注册谁在上面，
           且插件一旦改用 prepend 就会互相覆盖）。
           @example
           OwChat.onCardMetaTop(function (u) {
               return u.level ? '<div class="ow-card-meta-row">…</div>' : '';
           }, 1);   // prio 小者在前，默认 10
        */
        _cardMetaTop: [],
        /**
         * 注册资料卡 meta 顶部行。
         * @param {Function} fn   fn(userCardData) → HTML 字符串；返回空串表示不插入
         * @param {number} [prio] 排序优先级，小者在前（默认 10）
         */
        onCardMetaTop: function (fn, prio) {
            if (typeof fn !== 'function') return;
            this._cardMetaTop.push({ fn: fn, prio: typeof prio === 'number' ? prio : 10 });
        },

        /** 按 prio 升序拼出顶部行 HTML（单个插件抛错只丢弃它那一段） */
        cardMetaTopHtml: function (u) {
            var list = this._cardMetaTop.slice().sort(function (a, b) { return a.prio - b.prio; });
            var out = '', i;
            for (i = 0; i < list.length; i++) {
                try { var h = list[i].fn(u); if (typeof h === 'string') out += h; } catch (e) {}
            }
            return out;
        },

        /* ---------- 资料卡渲染完成扩展点（v1.5.17） ----------
           需求：资料卡要支持「顶部横幅背景图 + 头像叠压」这类**头部装饰**
           （见 profile-card 插件）。onCardMetaTop 只能在 .ow-card-meta 顶部加行、
           #owCardExtras 只能在卡片末尾加内容，都动不了卡片头部的结构。
           做法：核心把资料卡渲染完（openModal 之后）再 fire 本钩子，把弹窗 DOM
           与卡片数据交给插件做**增量装饰** —— 插件不必复刻核心 HTML。
           ⚠️ 单个插件抛错只丢弃它自己那一段，不影响其他插件与核心渲染。
           @example
           OwChat.onCardRendered(function (modal, u) {
               var b = document.createElement('div');
               b.className = 'owpc-banner';
               modal.insertBefore(b, modal.querySelector('.ow-card-head'));
           });
        */
        _cardRendered: [],
        /**
         * 注册资料卡渲染完成回调。
         * @param {Function} fn  fn(modal, userCardData)；modal = #owModal 弹窗节点
         */
        onCardRendered: function (fn) {
            if (typeof fn !== 'function') return;
            this._cardRendered.push(fn);
        },

        /** 触发资料卡渲染完成回调（按注册顺序；单个插件抛错只丢弃它那一段） */
        fireCardRendered: function (modal, u) {
            var list = this._cardRendered, i;
            for (i = 0; i < list.length; i++) {
                try { list[i](modal, u); } catch (e) {}
            }
        },

        /**
         * 绑定一级导航条 rail 的点击（委托，只绑一次；插件后加的入口也自动生效）。
         * v1.3.4：控件由侧栏顶部的横向标签条（#owSideTabs）改为最左侧图标条（#owRail），
         * 面板切换内核 switchTab 不变，所以只换了「点谁」。
         */
        bindRail: function () {
            if (this._tabsInited) return;
            var bar = $('owRail');
            if (!bar) return;
            var self = this;
            bar.onclick = function (e) {
                e = e || w.event;
                var t = e.target || e.srcElement;
                // 从点击点向上找带 data-tab 的按钮（点的是里面的 span 文字）
                while (t && t !== bar && !t.getAttribute) t = t.parentNode;
                while (t && t !== bar && !t.getAttribute('data-tab')) t = t.parentNode;
                if (!t || t === bar) return;
                var tab = t.getAttribute('data-tab');
                if (!tab) return;
                // v1.3.126：点的就是当前那个入口 → 收起/展开左侧栏（再点一次放回来）。
                // switchTab 对同一个标签是直接 return 的，所以这一支必须在它前面判。
                if (tab === self.view) { self.toggleSidebar(); return; }
                self.switchTab(tab);
            };
            /* v1.3.134：rail 底部沉下去的 ☰ **不是切面板的入口**，没有 data-tab，
               上面的委托对它是空转，所以单独绑一次。
               v1.5.0：这颗按钮现在**游客也有**（用户胶囊搬进菜单后，它是游客唯一的
               登录 / 注册入口），菜单内容由 toggleAccountMenu 按身份分流。 */
            var menuBtn = $('owRailMenu');
            if (menuBtn) menuBtn.onclick = function (e) {
                e = e || w.event;
                if (e.stopPropagation) e.stopPropagation(); else e.cancelBubble = true;
                self.toggleAccountMenu();
            };
            this._tabsInited = true;
        },

        /**
         * 收起 / 展开左侧栏（宽屏）。收起后 240px 让给消息区，rail 仍留在原地。
         * 窄屏（≤720px）直接不管：那边侧栏本来就是左移离屏的抽屉，由顶栏汉堡
         * #owToggleSide 与遮罩开合，再叠一层状态只会互相打架。
         */
        setSidebarCollapsed: function (on) {
            var s = $('owSidebar');
            if (!s) return;
            if ((d.documentElement.clientWidth || w.innerWidth || 1024) <= 720) return;
            var has = (' ' + s.className + ' ').indexOf(' ow-side-collapsed ') >= 0;
            if (has === !!on) return;
            // 只加减这一个类：侧栏上还可能挂着别的类（窄屏的 open、插件自加的样式类），
            // 整体重写 className 会把它们抹掉。
            if (s.classList) { s.classList[on ? 'add' : 'remove']('ow-side-collapsed'); return; }
            s.className = trimCls(s.className.replace(/\bow-side-collapsed\b/g, '') + (on ? ' ow-side-collapsed' : ''));
        },

        /** 当前是展开就收起、是收起就展开 */
        toggleSidebar: function () {
            var s = $('owSidebar');
            if (!s) return;
            this.setSidebarCollapsed((' ' + s.className + ' ').indexOf(' ow-side-collapsed ') < 0);
        },

        /** @deprecated v1.3.4：控件已改为 rail，保留旧名仅为兼容外部/插件调用 */
        bindSideTabs: function () { this.bindRail(); },

        /** 切到指定标签（核心与插件标签统一入口） */
        switchTab: function (tab) {
            if (!tab) return;
            // v1.3.126：任何「切到某入口」的调用都先把侧栏放出来 —— 收起状态下从顶栏点通知
            // 红点、或插件直接调 switchTab，面板还藏在 display:none 里，等于点了没反应。
            this.setSidebarCollapsed(false);
            if (tab === this.view) return;
            this.view = tab;
            this._rememberTab(tab);   // 刷新要停在原处（见 _rememberTab）
            // 离开「联系人」标签时，好友通知页随之作废（顶栏那几样要还回房间）
            if (tab !== 'friends') this.exitFriendReqs();
            // 离开通知标签要先还原顶栏：标题、群聊信息按钮、延迟显示都属于房间，
            // 而它们是被 paintNoticeChrome 藏起来的，不还原就会一直缺着。
            if (tab !== 'notice') this.exitNotices();
            this._paintTabs();
            if (tab === 'chat') {
                this.renderConversations();
                // ⚠️ 兜底：若进页面后一直待在别处，conversations 可能从未拉过，
                //   此时列表是空的。补拉一次；已拉过则跳过（不白打接口）。
                if (!this.conversations) this.loadConversations();
                // v1.2.29：从「联系人」切回来时中间是空白的（那边已把 room/dm 清掉），
                // 这里补上「还没选会话」的闸门；点某个会话后由 switchRoom/openDm 解禁。
                if (!this.dm && !this.room) {
                    this.renderRoomPanel();
                    this.applyInputGate('conv');
                }
            } else if (tab === 'friends') {
                // v1.2.29：先「关掉」中间正在显示的会话，再渲染联系人名单。
                // 顺序不能反 —— 否则名单渲染完又被随后的清理逻辑影响。
                this.blankChatForFriends();
                this.loadFriends();
            } else if (tab === 'notice') {
                // v1.3.52：系统通知。渲染与拉数据都在 openNotices 里（它会先停掉房间轮询）
                this.openNotices();
            } else {
                // 插件标签：把面板容器交给插件自己填
                this._showExtPanel(tab);
            }
        },

        /** 只更新标签高亮 + 面板显隐（不碰数据） */
        _paintTabs: function () {
            var bar = $('owRail');
            if (bar) {
                var btns = bar.getElementsByClassName('ow-rail-btn'), i;
                for (i = 0; i < btns.length; i++) {
                    // v1.3.134：rail 里现在还有两个「不是标签」的 .ow-rail-btn（顶部头像、底部 ☰），
                    // 它们没有 data-tab。高亮逻辑必须跳过它们 —— 否则下面那条无 classList 的回落分支
                    // 会整体重写 className，把 ow-rail-me / ow-rail-menu 一起抹掉。
                    if (!btns[i].getAttribute('data-tab')) continue;
                    var on = btns[i].getAttribute('data-tab') === this.view;
                    // ⚠️ 用 classList 逐项增删而不是整体赋值 ——
                    //   插件可能在自己入口上加了自己的类（如角标定位类），
                    //   整体覆盖 className 会把插件的类一起抹掉。
                    if (btns[i].classList) btns[i].classList[on ? 'add' : 'remove']('is-active');
                    else btns[i].className = on ? 'ow-rail-btn is-active' : 'ow-rail-btn';
                }
            }
            // 核心面板：「消息 / 联系人」共用 #owRoomList，「通知」用自己的 #owNoticeList；
            // 两者都不是插件面板，所以 #owSidePanels 在这三个标签下都要让位。
            var isCore = (this.view === 'chat' || this.view === 'friends');
            var isNotice = this.view === 'notice';
            var list = $('owRoomList'), panels = $('owSidePanels'), nlist = $('owNoticeList');
            if (list) list.style.display = isCore ? '' : 'none';
            if (nlist) {
                nlist.style.display = isNotice ? '' : 'none';
                // 面板类名与 #owRoomList 同源（ow-tab-panel is-active），显隐仍由 style 管
                if (nlist.classList) nlist.classList[isNotice ? 'add' : 'remove']('is-active');
            }
            if (panels) panels.style.display = (isCore || isNotice) ? 'none' : '';
        },

        /**
         * 记住当前 rail 标签。与后台的 sessionStorage.owAdminPage 是同一类需求：
         * 标签此前只是内存里的 this.view，刷新必然回到写死的 'chat'，
         * 人在殿堂 / 时光机里按一次 F5 就被拽回消息页。
         * 为什么用 sessionStorage 而不是 URL：标签是「视图」不是「文档」，
         * 塞进 ?tab= 就要与 ?room= / ?dm= 做互斥清理，还会让分享链接替别人决定视图。
         * 代价：只在当前标签页里有效，新开标签或重启浏览器仍从「消息」开始。
         *
         * ⚠️ 只存标签，**不要**顺手存 this.room 当作「刷新还是导航」的判据：
         * 切到联系人 / 插件标签时 blankChatForFriends 会把 this.room 清成 0，
         * 存下去就是 0，而下次开页 cfg.room 是真实群 id → 比对必然失败 → 恢复整个失效
         * （2026-10-18 实测：殿堂标签刷新后照样掉回消息页）。
         * 同一标签页里粘 ?room=9 分享链接会被拽回上一个视图，是这个取舍的代价：
         * 极少发生、且点一次「消息」就回到该群（room 一直在 URL 里），不值得为它加状态。
         */
        _rememberTab: function (tab) {
            try {
                if (tab === 'chat') { w.sessionStorage.removeItem(this.railTabKey); return; }
                w.sessionStorage.setItem(this.railTabKey, tab);
            } catch (e) {}
        },

        /**
         * 首屏恢复标签。**必须在插件包加载完之后调用**（挂载点见 init）：
         * 插件脚本排在 OwChat.init() 之后（见 index.php renderChat 收尾），
         * 抢在它前面恢复就只有按钮、没有 onShow，面板会落成「该标签页没有内容」的空态。
         */
        restoreTab: function () {
            var self = this, tab = '';
            try { tab = w.sessionStorage.getItem(this.railTabKey) || ''; } catch (e) { return; }
            if (!tab || tab === this.view) return;
            var drop = function () { try { w.sessionStorage.removeItem(self.railTabKey); } catch (e) {} };
            // 入口必须真的在 DOM 里：游客没有联系人 / 通知标签，插件停用后它的
            // data-tab 也不再由服务端渲染 —— 这时留在「消息」比切进空面板好。
            var bar = $('owRail');
            var btns = bar ? bar.getElementsByClassName('ow-rail-btn') : [], i, hit = false;
            for (i = 0; i < btns.length; i++) {
                if (btns[i].getAttribute('data-tab') === tab) { hit = true; break; }
            }
            if (!hit) { drop(); return; }
            this.switchTab(tab);
        },

        /** 插件面板：在 #owSidePanels 里为该标签准备一个容器并回调插件填充 */
        _showExtPanel: function (tab) {
            var wrap = $('owSidePanels');
            if (!wrap) return;
            var panel = document.getElementById('owExtPanel-' + tab);
            if (!panel) {
                panel = document.createElement('div');
                panel.className = 'ow-tab-panel is-active';
                panel.id = 'owExtPanel-' + tab;
                panel.setAttribute('data-panel', tab);
                wrap.appendChild(panel);
            }
            // 隐藏同容器内其它插件面板（插件面板之间互斥）
            var kids = wrap.children, i;
            for (i = 0; i < kids.length; i++) {
                if (kids[i] !== panel) kids[i].style.display = 'none';
            }
            panel.style.display = '';
            for (i = 0; i < this._tabsExt.length; i++) {
                if (this._tabsExt[i].id === tab) {
                    try { this._tabsExt[i].onShow(panel); } catch (e) {}
                    return;
                }
            }
            // 标签是服务端渲染的、但前端没注册 onShow：给个空态，不留白板
            if (!panel.innerHTML) {
                panel.innerHTML = '<div class="ow-cl-empty">该标签页没有内容</div>';
            }
        },

        /**
         * 兼容旧入口：v1.1.24 的「联系人视图」切换。
         * noArg=true 时强制切到聊天（供「返回」类入口用）。
         * @deprecated 保留是因为插件/外部可能还在调；内部请直接用 switchTab。
         */
        toggleFriendsView: function (noArg) {
            this.switchTab(noArg ? 'chat' : (this.view === 'friends' ? 'chat' : 'friends'));
        },

        /**
         * 拉联系人名单 + 个性签名，然后渲染。
         * ⚠️ 签名来自 signature 插件，**插件未启用时该接口不存在** ——
         *   此时只渲染好友名单、签名留空（不报错、不阻断列表出现）。
         */
        loadFriends: function () {
            var self = this;
            OwApi.post('friends', {}, function (r) {
                if (!r.ok) {
                    // 游客：服务端返回空数组（friends 对游客直接返回 []），
                    // 正常不会走到这里；真报错说明未登录等异常，给明确提示
                    self.renderFriends([]);
                    return;
                }
                self.friends = r.data || [];
                self.loadFriendSignatures(self.friends);
            });
        },

        /**
         * 批量取个性签名并渲染。
         * 用 `plugin_signature_bulk`（v1.1.24 新增）一次拿完，避免 N 次请求。
         * 插件未启用 → 接口返回「未知操作」→ 直接渲染空签名，不打扰用户。
         */
        loadFriendSignatures: function (list) {
            var self = this;
            if (!list.length) { this.renderFriends(list); return; }
            var ids = [], i;
            for (i = 0; i < list.length; i++) ids.push(list[i].user_id);
            OwApi.post('plugin_signature_bulk', { ids: ids }, function (r) {
                var sigs = (r && r.ok && r.signatures) || {};
                for (var k = 0; k < self.friends.length; k++) {
                    var uid = self.friends[k].user_id;
                    // ⚠️ 插件未启用时 signatures 为空 → 全部留空串，前端显示空副行
                    self.friends[k].signature = sigs[String(uid)] || '';
                }
                self.renderFriends(self.friends);
            });
        },

        /* ---------- 联系人增删（v1.1.24） ----------
           入口在「用户资料卡」底部按钮（与「发私信」同排）。加/删互斥，
           靠 user_card 返回的 is_friend 决定显示哪个 —— 不做乐观切换，
           避免「界面显示已加、实际请求失败」的不一致。 */

        /** 加为联系人。幂等：重复加服务端返回明确错误，不插重行。
         *  ⚠️ v1.2.28：**只加，不切私聊**。
         *     加好友是「加一个联系人」的动作，不是「去跟 TA 聊天」——
         *     自动跳进私聊会让中间的消息区无声地换成另一个人，
         *     正在打字的消息就发到别处去了（用户明确要求避免这个）。 */
        /**
         * 加好友：v1.3.71 起不再即时生效 —— 弹窗填一句验证消息（选填）发出请求，
         * 对方在「好友通知」里通过之后两人才算联系人、才互发得了私信。
         *
         *  ⚠️ v1.2.28 的老规矩保留：**只发请求，不切私聊**。自动跳进私聊会让
         *     中间的消息区无声换成另一个人，正在打字的消息就发到别处去了。
         *  ⚠️ 人机验证那一栏只在 boot 说需要时才挂组件（服务端同源判定），
         *     提交失败要 reset —— token 是一次性的，留着下次必被拒。
         */
        addFriend: function (uid) {
            var self = this;
            uid = uid | 0;
            if (!uid) return;
            this.openModal(
                '<h3>添加好友</h3>'
                + '<p class="ow-modal-desc">发送好友请求，对方通过后你们才能互发私信。</p>'
                + '<div class="ow-form-item"><label>验证消息（选填）</label>'
                + '<input class="ow-input" type="text" id="owFrNote" maxlength="200" autocomplete="off"'
                + ' placeholder="让对方知道你是谁，例如：我是综合闲聊的小明"></div>'
                + '<div data-owcv-host="1" id="owFrCv"></div>'
                + '<div class="ow-modal-actions">'
                + '<button class="ow-btn ow-btn-ghost" id="owFrCancel">取消</button>'
                + '<button class="ow-btn ow-btn-primary" id="owFrOk">发 送</button></div>'
                + '<div class="ow-form-msg" id="owFrMsg"></div>'
            );
            var host = $('owFrCv'), msg = $('owFrMsg'), note = $('owFrNote');
            if (host && this.cfg && this.cfg.captcha_friend === '1') {
                var tpl = $('owCvFriendTpl');
                if (tpl && tpl.innerHTML) {
                    host.innerHTML = tpl.innerHTML;
                    if (w.OwCv && w.OwCv.mount) w.OwCv.mount(host);
                }
            }
            var submit = function () {
                var data = { friend_id: uid, note: (note.value || '').replace(/^\s+|\s+$/g, '') };
                if (w.OwCv && w.OwCv.fields) {
                    var f = w.OwCv.fields(host) || {}, k;
                    for (k in f) if (f.hasOwnProperty(k)) data[k] = f[k];
                }
                msg.innerHTML = '发送中…';
                OwApi.post('friend_add', data, function (r) {
                    if (!r.ok) {
                        msg.innerHTML = '<span style="color:var(--ow-red)">' + esc(r.msg || '发送失败') + '</span>';
                        if (w.OwCv && w.OwCv.reset) w.OwCv.reset(host);   // token 用掉了，逼组件重新解一次
                        return;
                    }
                    toast(r.msg || '请求已发送');
                    self.closeModal();
                    if (self.view === 'friends') self.loadFriends();
                });
            };
            $('owFrOk').onclick = submit;
            $('owFrCancel').onclick = function () { self.closeModal(); };
            note.onkeydown = function (e) {
                e = e || w.event;
                if (e.keyCode === 13) { e.preventDefault ? e.preventDefault() : (e.returnValue = false); submit(); }
            };
            note.focus();
        },

        /** 删除联系人。走 OwApi.secure —— friend_remove 在 $SENSITIVE 内，需一次性票据。 */
        removeFriend: function (uid) {
            var self = this;
            OwApi.secure('friend_remove', { friend_id: uid }, function (r) {
                toast(r.msg || (r.ok ? '已删除' : '删除失败'));
                if (!r.ok) return;
                self.userCard(uid);
                if (self.view === 'friends') self.loadFriends();
            });
        },

        /**
         * 渲染联系人列表 —— **复用 ChatList 轮子**（同一个 DOM 结构与样式）。
         * 数据形状对齐 ChatList.render 需要的字段：
         *   conv='dm'（决定走用户头像分支）、peer=**'user:' + id**（点击走 openDm）、
         *   name=昵称、last_text=个性签名。
         *
         * v1.2.28 两处修正：
         *  ① peer 之前传的是**纯数字**（f.user_id），而 openDm 只认 `user:20` 这种
         *     带前缀的格式（`/^(\w+):(\d+)$/` 不匹配就静默 return）→ **点联系人进不去**。
         *  ② 右上角时间取消：原显示「加入联系人的时间」，
         *     联系人列表没有消息流，这个时间对用户没有参考价值。
         */
        renderFriends: function (list) {
            var self = this, box = $('owRoomList');
            if (!box) return;
            var rows = [], i;
            // v1.3.71：顶部固定一行「新朋友」—— 好友请求的入口。
            // 用 peer='req:new' 冒充一个 dm 行（ChatList 只认 conv/peer），
            // 点击在 onClick 里先分流，不会被当成私聊。
            var pend = this.cfg ? (this.cfg.friend_pending | 0) : 0;
            if (this.cfg && this.cfg.me && this.cfg.me.id) {
                rows.push({
                    conv: 'dm', peer: 'req:new', id: 0, name: '新朋友',
                    // 固定头像：服务端下发的「小可爱第 6 号」（见 Chat::friendAvatarUrl）。
                    // 不给它一个稳定形象的话，ChatList 会退化成蓝底白字「新」字块 ——
                    // 与旁边那些真人头像不是一个视觉量级，看着像没加载出来。
                    avatar: (this.cfg && this.cfg.friend_avatar) || '', role: '',
                    // 文案不带数字：「3 个待处理请求」这种整句语言包永远匹配不上，
                    // 具体几条到页面里看（联系人栏那个红点已经负责说「有事待处理」）
                    last_text: pend > 0 ? '有人想加你为好友' : '没有人向你发送好友请求',
                    last_at: 0
                });
            }
            // v1.3.161：插件注册的「联系人行」（与通知联系人同一套；服务端 Notice::pluginRows
            // 已补好默认昵称/头像）。位置：紧跟「新朋友」之后、真人联系人之前 ——
            // 系统性质的行集中在上方，别插在人堆中间。
            var pcs = (this.cfg && this.cfg.contact_rows) || [], pi;
            for (pi = 0; pi < pcs.length; pi++) {
                rows.push({
                    conv: 'dm', peer: 'plugin:' + pcs[pi].key, id: 0, name: pcs[pi].name,
                    avatar: pcs[pi].avatar || '', role: '',
                    // v1.3.162：与服务端 pluginRows 的字段保持完整对接（at → last_at，与通知区同一口径）。
                    // 本列表 hideTime，时间不参与渲染 —— 这里的意义是两个区的行数据形状始终一致。
                    last_text: pcs[pi].last_text || '', last_at: pcs[pi].at || 0
                });
            }
            for (i = 0; i < (list || []).length; i++) {
                var f = list[i];
                rows.push({
                    conv: 'dm', peer: 'user:' + f.user_id, id: f.user_id,
                    name: f.nickname, avatar: f.avatar, role: f.role,
                    last_text: f.signature || '',
                    last_at: 0
                });
            }
            // v1.2.20：联系人数量徽标一并移除（与消息标签同理，计数非必要信息）
            ChatList.render(rows, {
                container: 'owRoomList',
                // 正在看好友通知页时，高亮要留在「新朋友」那一行 ——
                // 它不是会话，this.dm 永远是空，照原样算 activeKey 会一行都不亮。
                activeKey: this.friendMode ? 'dm:req:new'
                    : (this.dm ? ('dm:' + this.dm.peer) : ''),
                emptyText: '还没有联系人，去「成员管理」或资料卡添加吧',
                hideTime: true,          // v1.2.28：取消右上角时间
                onClick: function (el) {
                    var peer = el.getAttribute('data-dm');
                    if (peer === 'req:new') return self.openFriendReqs();
                    // v1.3.161：插件的联系人行 —— 交给它自己的处理器，别当私聊开
                    if (peer && peer.indexOf('plugin:') === 0) return self.openContactRow(peer.substr(7));
                    // 从好友通知页点回某个联系人：先把本页借走的顶栏还回去，openDm 才会写自己的标题
                    self.exitFriendReqs();
                    if (peer) self.openDm(peer, el.getAttribute('data-name'));
                }
            });
        },

        /**
         * 注册「联系人行」的点击处理器（v1.3.161，插件扩展点）—— 与通知联系人那一对完全同构。
         *
         * 服务端先用 `Plugin::on('contact.rows', function (callable $add) { $add(['key' => '…']); })`
         * 登记一行，前端再用本方法按同一个 key 接住点击 —— 两边只靠 key 约定，核心不认识插件的内容。
         *
         * @param {string}   key 与服务端登记时一致的 key
         * @param {function} fn  点击时调用，收到行对象 {key, name, avatar, unread, last_text}
         */
        onContactOpen: function (key, fn) {
            if (key && typeof fn === 'function') this._contactHooks[key] = fn;
        },
        _contactHooks: {},
        /** 点了插件的联系人行：交给插件；没注册处理器就提示一句（与通知联系人同一条路标） */
        openContactRow: function (key) {
            var fn = this._contactHooks[key];
            if (typeof fn !== 'function') { toast('插件「' + key + '」没有注册点击处理'); return; }
            var rs = this.cfg.contact_rows || [], row = null, i;
            for (i = 0; i < rs.length; i++) if (rs[i].key === key) row = rs[i];
            fn(row || { key: key });
        },

        /* =====================================================================
         * 新朋友 / 好友通知（v1.3.71）
         * ---------------------------------------------------------------------
         * 加好友不再即时生效：发起方填一句验证消息（选填），接收方在「好友通知」页
         * 通过或拒绝；通过后两人才是联系人，也才互发得了私信（闸门在服务端 send 里）。
         * 联系人栏那个小红点算的是「未处理」，不是「未读」—— 光看不表态会一直挂着。
         * =================================================================== */

        /** 打开好友通知页（中间区域换成请求列表，侧栏仍在「联系人」标签下） */
        openFriendReqs: function () {
            var self = this;
            this.exitNotices();
            this.friendMode = true;
            // 点完要把侧栏那一行点亮：openDm 是靠 ChatList.activate 做这件事的，
            // 本页不是会话、走不到那条路径，得自己补一次（否则进了页面没有选中态）。
            ChatList.activate('owRoomList', 'dm:req:new');
            // 顶栏与通知页同一处理：标题、群聊信息按钮、延迟显示都属于「某个房间」，
            // 而这一页没有房间 —— 不清就会一直挂着上一个群的名字（实测截出来的就是这问题）。
            this._freqTitleBefore = ($('owRoomName') || {}).textContent || '';
            var p = $('owTogglePanel'); if (p) p.style.display = 'none';
            var l = $('owLatency'); if (l) l.style.display = 'none';
            var t = $('owRoomName'); if (t) t.textContent = '好友通知';
            this.applyInputGate('friend');
            var box = $('owMessages');
            if (box) box.innerHTML = '<div class="ow-notice-pending">加载中…</div>';
            OwApi.post('friend_requests', {}, function (r) {
                if (!r || !r.ok) {
                    if (box) box.innerHTML = '<div class="ow-notice-pending">' + esc((r && r.msg) || '加载失败') + '</div>';
                    return;
                }
                self.friendReqs = r;
                // 列表就是权威计数：待处理几条一目了然，不用服务端再回一个数
                if (self.cfg) self.cfg.friend_pending = (r.incoming || []).length;
                self.paintFriendDot();
                self.renderFriendReqs();
            });
        },

        /** 离开好友通知页：还回顶栏那几样与房间绑定的控件 */
        exitFriendReqs: function () {
            if (!this.friendMode) return;
            this.friendMode = false;
            var p = $('owTogglePanel'); if (p) p.style.display = '';
            var l = $('owLatency'); if (l) l.style.display = '';
            var t = $('owRoomName');
            if (t && this._freqTitleBefore != null) t.textContent = this._freqTitleBefore;
        },

        /** 好友通知页正文：待我处理的（通过/拒绝）+ 我发出的（看状态） */
        renderFriendReqs: function () {
            var box = $('owMessages');
            if (!box) return;
            var d = this.friendReqs || {}, inc = d.incoming || [], out = d.outgoing || [];
            var h = '<div class="ow-freq">', i, r;
            h += '<h3 class="ow-freq-hd">新的朋友</h3>';
            if (!inc.length) h += '<p class="ow-freq-empty">暂无待处理的好友请求。</p>';
            for (i = 0; i < inc.length; i++) {
                r = inc[i];
                h += '<div class="ow-freq-row">'
                   + '<span class="ow-freq-av">' + avatarHtml(r.avatar, r.nickname) + '</span>'
                   + '<span class="ow-freq-main">'
                   + '<span class="ow-freq-name">' + esc(r.nickname) + roleTag(r.role, r.title, r.user_id) + '</span>'
                   + '<span class="ow-freq-note">' + (r.note ? esc(r.note) : '（对方未填写验证消息）') + '</span>'
                   + '<span class="ow-freq-time">' + esc(ChatList.time(r.created_at)) + '</span>'
                   + '</span>'
                   + '<span class="ow-freq-acts">'
                   + '<button class="ow-btn ow-btn-primary ow-btn-mini" onclick="OwChat.friendHandle(' + (r.id | 0) + ',1)">通过</button>'
                   + '<button class="ow-btn ow-btn-ghost ow-btn-mini" onclick="OwChat.friendHandle(' + (r.id | 0) + ',0)">拒绝</button>'
                   + '</span></div>';
            }
            if (out.length) {
                h += '<h3 class="ow-freq-hd">我发出的请求</h3>';
                for (i = 0; i < out.length; i++) {
                    r = out[i];
                    h += '<div class="ow-freq-row is-out">'
                       + '<span class="ow-freq-av">' + avatarHtml(r.avatar, r.nickname) + '</span>'
                       + '<span class="ow-freq-main">'
                       + '<span class="ow-freq-name">' + esc(r.nickname) + '</span>'
                       + '<span class="ow-freq-note">' + (r.note ? esc(r.note) : '（未填写验证消息）') + '</span>'
                       + '<span class="ow-freq-time">' + esc(ChatList.time(r.created_at)) + '</span>'
                       + '</span>'
                       + '<span class="ow-freq-state">' + (r.status === 1 ? '已通过' : (r.status === 2 ? '已拒绝' : '待处理')) + '</span>'
                       + '</div>';
                }
            }
            box.innerHTML = h + '</div>';
        },

        /** 通过 / 拒绝一条好友请求 */
        friendHandle: function (id, accept) {
            var self = this;
            OwApi.post('friend_handle', { id: id, accept: accept ? '1' : '0' }, function (r) {
                toast(r.msg || (r.ok ? '已处理' : '处理失败'));
                if (!r.ok) return;
                if (typeof r.pending === 'number' && self.cfg) self.cfg.friend_pending = r.pending;
                self.paintFriendDot();
                // 通过之后对方就是联系人了：名单与本页都要跟着换
                if (self.view === 'friends') self.loadFriends();
                if (self.friendMode) self.openFriendReqs();
            });
        },

        /** 联系人栏小红点（服务端首屏已渲染初值，这里只管之后的增删） */
        paintFriendDot: function () {
            var dot = $('owFriendDot');
            if (!dot) return;
            dot.style.display = ((this.cfg && this.cfg.friend_pending | 0) > 0) ? '' : 'none';
        },

        /* ---------- 会话列表轻量轮询（v1.1.0） ----------
           需求：任何会话来了新消息，该会话自动排到列表最前，用户不必手动刷新。
           为什么不能靠消息长轮询带出来：群聊 poll 只监听「当前所在群」，
           私聊 dm_poll 只监听「当前所在私聊」——停在群聊2 时收不到群聊1 的消息。
           所以这里单独开一路低频轮询（10s），只重渲染左侧栏，
           完全不碰消息区 / 输入栏 / 当前会话，因此不会打断正在进行的聊天。 */
        _convTimer: null,
        startConvPoll: function () {
            var self = this;
            if (this._convTimer) return;
            var loop = function () {
                if (!self.cfg) return;
                self.loadConversations();   // 内部重渲染列表，幂等
                self._convTimer = setTimeout(loop, 10000);
            };
            this._convTimer = setTimeout(loop, 10000);
        },
        stopConvPoll: function () {
            if (this._convTimer) { clearTimeout(this._convTimer); this._convTimer = null; }
        },

        /** 按 peer_key 查会话项（v1.2.28：置顶/好友态的来源） */
        convByKey: function (peerKey) {
            var l = this._convList || [], i;
            for (i = 0; i < l.length; i++) { if (l[i].peer_key === peerKey) return l[i]; }
            return null;
        },

        /** 会话列表渲染 + 行点击分发（群聊走密码房流程，私聊进私聊页） */
        renderConversations: function () {
            var self = this, list = this.conversations || [];
            // v1.2.28：缓存整份会话，供 openDm 查「是否已置顶 / 是否是好友」
            //（右侧栏的「设为置顶」「删除好友」两行要用，不必再发一次请求）
            this._convList = list;
            // v1.2.20：非「消息」标签下**不要**渲染会话列表 —— 会把它上面板的内容冲掉。
            // loadConversations 已拦了一道，这里再兜一道：任何直接调
            // renderConversations 的路径（切会话、openDm 等）都不该踩坏别的面板。
            // ⚠️ 同样用 `!== 'chat'`，把插件标签一并挡掉。
            if (this.view !== 'chat') return;
            var activeKey = this.dm ? ('dm:' + this.dm.peer) : ('room:' + this.room);
            ChatList.render(list, {
                container: 'owRoomList',
                activeKey: activeKey,
                emptyText: '暂无会话',
                onClick: function (el) {
                    var dm = el.getAttribute('data-dm');
                    if (dm) return self.openDm(dm, el.getAttribute('data-name'));
                    var id = parseInt(el.getAttribute('data-room'), 10) || 0;
                    var name = el.getAttribute('data-name');
                    if (!id) return;
                    // 当前会话的公开性与成员态（服务端已随房间下发，前端不自行推断）
                    var meta = null, rl = self.cfg.rooms || [];
                    for (var k = 0; k < rl.length; k++) { if (rl[k].id === id) { meta = rl[k]; break; } }
                    var isPub = !meta || meta.is_public !== false;

                    // 群聊：先不带密码尝试一次（是否需要密码由服务端判定）
                    // v1.2.27：join=1 表示用户已在前台确认「加入」→ 服务端写 room_members
                    var tryJoin = function (password, join) {
                        OwApi.post('room_join', { room_id: id, password: password || '', join: join ? 1 : 0 }, function (rr) {
                            if (!rr.ok) {
                                if (rr.need_password) {
                                    if (password) toast(rr.msg);
                                    self.passForget(id);
                                    self.askRoomPassword(id, name, function (pw) { tryJoin(pw, join); });
                                    return;
                                }
                                toast(rr.msg);
                                if (rr.need_login) location.href = '?page=login';
                                return;
                            }
                            self.passRemember(id, rr.ttl);
                            // 加入成功后回写缓存，避免下次点同一个群又弹一次确认
                            if (meta && (rr.joined || rr.is_member)) meta.is_member = true;
                            self.switchRoom(id, rr.room.name, el);
                            // 立即刷新「所有成员」（刚加入的自己也要出现在列表里）
                            if (rr.members) self.renderMembers(rr.members, self._canStatus);
                        });
                    };

                    // v1.2.27：公开群聊点击时先确认是否加入（未加入者一律先弹窗）。
                    // 取消 = 不进入（用户选定口径：比「可浏览禁发言」更严格）。
                    // 游客 is_member 恒为 false，同样走这里 —— 游客看到的是同一句提示，
                    // 服务端对游客不写成员表，只当「确认进入」处理。
                    if (isPub && meta && !meta.is_member) {
                        self.confirm(
                            '是否加入「' + name + '」？'
                            + (isPub ? '\n即将加入公开群聊，请注意个人隐私安全。' : ''),
                            function () { tryJoin('', 1); }
                        );
                        return;
                    }
                    tryJoin('', 0);
                }
            });
            // 密码房标签：轮子渲染后补（数据里 need_password 时显示）
            var items = $('owRoomList').getElementsByTagName('li'), i;
            for (i = 0; i < items.length; i++) {
                if (items[i].getAttribute('data-pw') === '1' && items[i].querySelector('.ow-cl-lock')) continue;
                if (items[i].getAttribute('data-pw') === '1') {
                    var t = items[i].querySelector('.ow-cl-title');
                    if (t && !t.querySelector('.ow-cl-lock')) {
                        t.innerHTML += '<span class="ow-cl-tag ow-cl-lock">密码房</span>';
                    }
                }
            }
        },

        /**
         * 打开私聊会话（v1.1.0）：复用群聊骨架——消息区、输入栏、轮询全部沿用，
         * 仅切换「对方昵称」标题、会话目标（room_id=0 + to_user_id）与列表高亮。
         * peer 形如 'user:12'（服务端据此做双方可见性校验）。
         * v1.1.2：跨身份私聊下线，peer 里的 'guest:' 形态直接拒绝。
         */
        openDm: function (peer, name) {
            var self = this;
            var m = /^(\w+):(\d+)$/.exec(peer || '');
            if (!m) return;
            if (m[1] !== 'user') { toast('游客暂不支持私聊'); return; }
            // 已在该私聊：若只是补来了真实昵称（此前为占位「私聊」），只更新标题即可，不重载历史
            if (this.dm && this.dm.peer === peer) {
                if (name && name !== this.roomName && name !== '私聊') {
                    this.roomName = name;
                    $('owRoomName').innerHTML = esc(name);
                }
                return;
            }
            // 先切状态：startPoll 的 alive() 依赖 !this.dm，赋值即让群聊长轮询自杀
            // v1.2.28：从会话列表缓存里取「是否已置顶 / 是否是好友」，
            // 右侧栏的「设为置顶」「删除好友」两行依赖这两个值。
            var conv = this.convByKey('dm:' + peer);
            this.dm = {
                peer: peer, kind: m[1], id: parseInt(m[2], 10),
                pinned: !!(conv && conv.pinned),
                is_friend: !!(conv && conv.is_friend),
                // 右侧栏第一行的头像位要用它（会话列表里本来就带着，不必再查一次接口）
                avatar: (conv && conv.avatar) || ''
            };
            this.pollGen = (this.pollGen || 0) + 1;          // 作废在途的群聊轮询回调
            this._roomPollRunning = false;                  // 群聊长轮询就此停摆
            this.room = 0;
            this.since = 0;
            this.historyDone = false;
            this.loadingHistory = false;
            this.roomName = name || '私聊';
            this.syncRoomOwner();
            this.renderMe();
            this.clearQuote();
            $('owRoomName').innerHTML = esc(this.roomName);
            this.hideMsgTime();   // v1.1.0：消息区重渲染前清掉悬停计时（引用的元素已不存在）
            $('owMessages').innerHTML = '';   // v1.2.6：改懒加载，不再放「加载更早消息…」入口节点
            ChatList.activate('owRoomList', 'dm:' + peer);
            $('owSidebar').className = $('owSidebar').className.replace(' open', '');
            this.setDmUrl(peer);
            this.scrollBottom();
            // 历史：迟到响应需校验仍停留在同一私聊，否则丢弃（避免串到别的会话）
            var myPeer = peer;
            OwApi.post('dm_history', { peer: myPeer, before_id: 0 }, function (r) {
                if (!self.dm || self.dm.peer !== myPeer) return;
                if (!r.ok) { toast(r.msg); return; }
                // 服务端一并回传对方资料：首次私聊时会话列表里还没有该项，
                // 靠这里把标题从占位「私聊」换成真实昵称
                if (r.peer && r.peer.name && r.peer.name !== self.roomName) {
                    self.roomName = r.peer.name;
                    $('owRoomName').innerHTML = esc(r.peer.name);
                }
                for (var i = 0; i < r.data.length; i++) self.addMessage(r.data[i], true);
                if (r.data.length) self.since = r.data[r.data.length - 1].id;
                // P2：私聊订阅（带刚确立的水位；服务端 watcher/nudge 据此推增量。
                // 重连时 onOpen 会用 dmSets 里的同值原样重发 → 掉线缺口补齐）
                if (self.rt) self.rt.dmsub(myPeer, self.since);
                // v1.3.21：与群聊一致，滚动交给 jumpToUnread 决定（见 switchRoom 同处注释）
                if (r.data.length < 30) self.historyDone = true;
                if (!r.data.length) self.historyDone = true;
                // v1.2.6 懒加载：消息太少填不满屏幕时自动往前补，
                // 否则去掉「加载更早消息…」入口后，这类会话上方会一直留白。
                // v1.3.21：与群聊一致 —— 有未读就定位到上次已读位置并显示跳转按钮
                self.jumpToUnread();
            });
            this.dmPollLoop();
            this.renderRoomPanel();   // v1.2.28：私聊下侧栏渲染四个会话操作入口（并隐藏「所有成员」）
            // v1.2.29：进私聊 → 解除「先选联系人 / 先选会话」的闸门
            //（私聊双方都是注册用户且不存在「未加入」，所以直接解禁）
            this.applyInputGate('');
            // v1.1.0：进入私聊视图 → 通知插件清理群级装饰（公告条等）
            this._fireViewChange();
        },

        /**
         * 私聊增量轮询（与群聊 poll 同构，20s 长挂起）。
         * 用 dmGen 世代号与「当前是否仍在私聊」双重判定，保证切回群聊后立刻停摆。
         */
        dmPollLoop: function () {
            var self = this;
            if (!this.dm) return;
            if (OwChat.rt && OwChat.rt.on) return;   // P2：WS 主通道在线 → 不启动私聊长轮询
            if (this._dmPollTimer) { clearTimeout(this._dmPollTimer); this._dmPollTimer = 0; }
            if (this._dmPollBusy) return;
            var myGen = this.dmGen = (this.dmGen || 0) + 1;
            var peer = this.dm.peer, since = this.since;
            var alive = function () { return self.dm && self.dm.peer === peer && self.dmGen === myGen; };
            this._dmPollBusy = true;
            var t0 = new Date().getTime();
            OwApi.post('dm_poll', { peer: peer, since_id: since }, function (r) {
                if (!alive()) { self._dmPollBusy = false; return; }
                self._dmPollBusy = false;
                if (r && r.ok) {
                    var hasNew = false;
                    for (var i = 0; i < r.messages.length; i++) { self.addMessage(r.messages[i]); hasNew = true; }
                    if (r.messages.length) self.since = r.messages[r.messages.length - 1].id;
                    if (hasNew) {
                        // v1.3.21：同群聊 —— 停在定位点时不打断（见 startPoll 内同处注释）
                        if (self._unreadJumpVisible()) self.showUnreadJump(0);
                        else self.scrollBottom();
                        if (self.sound) beep();
                    }
                    $('owLatency').innerHTML = '● ' + (new Date().getTime() - t0) + ' ms';
                    $('owLatency').style.color = 'var(--ow-green)';
                    // 有新消息即刷新会话列表（排序会因这条消息而变）
                    if (hasNew) self.loadConversations();
                }
                if (alive()) self._dmPollTimer = setTimeout(function () { self.dmPollLoop(); }, 100);
            });
        },

        /**
         * 私聊地址路由：?dm=user:12（v1.1.0）。
         * 与 setRoomUrl 对称：互斥清理对方参数（私聊页不保留 room=），并保留其余查询参数。
         *
         * ⚠️ 冒号不能被编码：peer 形如 'user:12'，若用 encodeURIComponent 会变成 'user%3A12'，
         * 而 dmFromUrl 的正则按字面冒号匹配 → 应用自己写出的链接自己都解析不出来。
         * ':' 是 RFC 3986 允许出现在 query 中的字符，直接拼接即可。
         */
        setDmUrl: function (peer, replace) {
            try {
                if (!w.history || !w.history.pushState) return;
                var search = (w.location.search || '').replace(/^\?/, '')
                    .replace(/(^|&)dm=[^&]*/g, '').replace(/(^|&)room=[^&]*/g, '')
                    .replace(/^&+|&+$/g, '');
                var q = search ? search + '&dm=' + peer : 'dm=' + peer;
                var url = w.location.pathname + '?' + q;
                if (replace) w.history.replaceState({ dm: peer }, '', url);
                else w.history.pushState({ dm: peer }, '', url);
            } catch (e) {}
        },

        /**
         * 地址路由：把当前群聊 id 写进 URL（?page=chat&room=ID）。
         * 刷新、分享链接、前进/后退都停留在对应群聊；保留其他查询参数。
         */
        setRoomUrl: function (rid, replace) {
            try {
                if (!w.history || !w.history.pushState) return;
                // ★ v1.1.0 修复：dm 与 room 必须互斥。
                //   原先只删 room=，从私聊切回群聊会留下 ?page=chat&dm=user:20&room=2，
                //   刷新时 dmFromUrl() 优先解析 → 错误跳回私聊（表现为「跳到第一个群聊」）。
                //   这里同时清掉 dm= 与 room=，再写入 room=。
                var search = (w.location.search || '').replace(/^\?/, '')
                    .replace(/(^|&)dm=[^&]*/g, '')
                    .replace(/(^|&)room=[^&]*/g, '').replace(/^&+|&+$/g, '');
                var q = search ? search + '&room=' + rid : 'room=' + rid;
                var url = w.location.pathname + '?' + q;
                if (replace) w.history.replaceState({ room: rid }, '', url);
                else w.history.pushState({ room: rid }, '', url);
            } catch (e) {}
        },
        /** 从当前 URL 解析 room id（无则 0） */
        roomFromUrl: function () {
            var mt = (w.location.search || '').match(/[?&]room=(\d+)/);
            return mt ? parseInt(mt[1], 10) || 0 : 0;
        },

        /** 私聊地址解析：?dm=user:12（v1.1.0，兼容 %3A 编码形式）
         *  v1.1.2：跨身份私聊下线，guest: 形态直接丢弃（否则会走进一个必然报错的空会话） */
        dmFromUrl: function () {
            var mt = (w.location.search || '').match(/[?&]dm=([^&]+)/);
            if (!mt) return '';
            var v = decodeURIComponent(mt[1]);   // 外部链接可能带 %3A，需还原
            if (!/^(\w+:\d{1,10})$/.test(v)) return '';
            return v.indexOf('user:') === 0 ? v : '';
        },

        /** 同步当前群聊的 owner 用户 ID 到模块变量 CUR_OWNER（roleTag 群主标签用） */
        syncRoomOwner: function () {
            var rooms = (this.cfg && this.cfg.rooms) || [];
            for (var i = 0; i < rooms.length; i++) {
                if (rooms[i].id === this.room) { CUR_OWNER = rooms[i].owner_id || 0; return; }
            }
            CUR_OWNER = 0;
        },

        /* ---------- 前端扩展钩子（v1.0.102，供插件注册） ---------- */
        _roomSwitchHooks: [],
        _roomEditHooks: [],
        _viewChangeHooks: [],
        /** 注册「切换群聊」回调：fn({ roomId, ownerId, isAdmin })，切群时触发；注册时立即补发当前状态（插件脚本晚于 init 加载） */
        onRoomSwitch: function (fn) {
            if (typeof fn !== 'function') return;
            this._roomSwitchHooks.push(fn);
            try { fn({ roomId: this.room, ownerId: CUR_OWNER, isAdmin: this.cfg.actor.role === 'admin' }); } catch (e) {}
        },
        /**
         * 注册「群聊信息入口区渲染」回调：fn({ roomId, ownerId, isAdmin, isOwner })。
         * v1.1.1：触发时机从「打开群聊设置弹窗」改为「右侧栏群聊信息区渲染」
         * （init / 切群 / 进私聊 / 打开侧栏 / 保存群资料后都会触发），
         * 可往 #owREExtras 追加入口。
         * v1.1.10：群资料表单已搬回弹窗，#owREExtras 现在是**入口行容器**
         * （不再是表单里的一行），插件入口不再与表单耦合；请用 .ow-panel-entry
         * 类保持与「群聊信息」行一致的外观。#owREExtras 必定存在，
         * 但插件应按 ctx.isOwner || ctx.isAdmin 自行决定是否填充。
         */
        onRoomEdit: function (fn) { if (typeof fn === 'function') this._roomEditHooks.push(fn); },

        /**
         * 登记机器人账号（插件扩展点，v1.3.152）。
         *
         * 登记过的 uid 在**所有** roleTag 出现的地方都出「机器人」标签：消息气泡、
         * 会话列表、成员面板、用户资料卡 —— 走的是同一个渲染函数，不必逐处通知。
         * @param {number|string|Array} uids 一个或多个用户 ID（0 / 空值忽略）
         */
        markBots: function (uids) {
            var a = Object.prototype.toString.call(uids) === '[object Array]' ? uids : [uids], i, n, added = 0;
            for (i = 0; i < a.length; i++) {
                n = parseInt(a[i], 10) || 0;
                if (n > 0 && !BOT_UIDS[n]) { BOT_UIDS[n] = 1; added++; }
            }
            /* 插件脚本在 init 之后才加载，而 init 会立刻去拉当前群的历史 ——
               首屏那一批消息可能**先渲染完**。登记完补一次重渲染，否则机器人的第一次亮相
               还是「会员」，要等下一次消息刷新才纠正。 */
            if (added) this.refreshBotTags();
        },
        isBot: function (uid) { return !!BOT_UIDS[parseInt(uid, 10) || 0]; },
        /** 把已渲染的消息重画一遍（只在登记机器人后调一次；没有任何消息时是空转） */
        refreshBotTags: function () {
            var box = $('owMessages');
            if (!box || !box.children.length) return;
            var nodes = box.children, i, m, frag = w.document.createDocumentFragment(), need = false, node, b, div;
            for (i = 0; i < nodes.length; i++) {
                node = nodes[i];
                m = this.msgCache[parseInt(String(node.id).replace('owMsg', ''), 10)];
                // buildMessage 返回的是 {cls, html} —— 必须自己建 div 再填，不能
                // 直接 appendChild（那是对象不是节点，抛 NotFoundError: 参数 1 不是 Node）。
                // 与 addMessage / addMessageBefore / addPending 三处同一套写法。
                if (m) {
                    b = this.buildMessage(m);
                    div = w.document.createElement('div');
                    div.className = b.cls;
                    div.id = 'owMsg' + m.id;
                    div.innerHTML = b.html;
                    frag.appendChild(div);
                    if (b.html.indexOf('ow-file-name') >= 0) scheduleFitFileName(div);
                    need = true;
                } else {
                    frag.appendChild(node.cloneNode(true));   // 日期分隔条之类的非消息节点，原样留着
                }
            }
            if (!need) return;                        // 一条消息都没有：别白清一次 DOM
            var keepTop = box.scrollTop;              // 重画会回到顶部，滚动位置要还回去
            box.innerHTML = '';
            box.appendChild(frag);
            box.scrollTop = keepTop;
            // v1.2.18：文件名省略要在**入 DOM 之后**量宽，重画同理
            if (box.getElementsByClassName) scheduleFitFileName(box);
        },
        _fireRoomSwitch: function () {
            for (var i = 0; i < this._roomSwitchHooks.length; i++) {
                try { this._roomSwitchHooks[i]({ roomId: this.room, ownerId: CUR_OWNER, isAdmin: this.cfg.actor.role === 'admin' }); } catch (e) {}
            }
        },

        /* ---------- 「视图切换」钩子（v1.1.0） ----------
           群聊与私聊共用同一套消息区/输入栏/顶部标题，但群级装饰（公告条、群设置入口等）
           只在群聊视图成立。核心不直接操作插件 DOM——由插件自己注册本钩子，
           在 view 不是 'room' 时清理自己的群级装饰，view='room' 时按 roomId 复原。
           ctx: { view: 'room'|'dm'|'notice'|'none', roomId, peer, ownerId, isAdmin }
             'room'   停在某个群聊
             'dm'     私聊（room_id=0 的虚拟空间）
             'notice' 系统通知流
             'none'   没有任何会话：联系人名单、未点选会话
           注册时立即补发当前视图（插件脚本晚于 init 加载）。 */
        onViewChange: function (fn) {
            if (typeof fn !== 'function') return;
            this._viewChangeHooks.push(fn);
            this._fireViewChangeTo(fn);
        },
        _fireViewChangeTo: function (fn) {
            try {
                fn({
                    /* v1.3.133：这里原来只看 this.dm 一个布尔，于是「没有房间」的三种入口
                       （系统通知、联系人、未选会话）统统被报成 'room' —— 插件按契约只在
                       'dm' 时清理，公告条就留在了别人的页面上。现在只有真的停在某个群时
                       才报 'room'，其余一律给出真实视图名。 */
                    view: this.noticeMode ? 'notice' : (this.dm ? 'dm' : (this.room ? 'room' : 'none')),
                    roomId: (this.noticeMode || this.dm) ? 0 : this.room,
                    peer: this.dm ? this.dm.peer : '',
                    ownerId: CUR_OWNER,
                    isAdmin: this.cfg && this.cfg.actor ? this.cfg.actor.role === 'admin' : false
                });
            } catch (e) {}
        },
        _fireViewChange: function () {
            for (var i = 0; i < this._viewChangeHooks.length; i++) this._fireViewChangeTo(this._viewChangeHooks[i]);
        },

        switchRoom: function (id, name, el, fromPop) {
            // v1.1.0：离开私聊态 —— 作废私聊轮询世代号，随后 startPoll 会接管长轮询
            if (this.dm) { this.dmGen = (this.dmGen || 0) + 1; this.dm = null; }
            // v1.1.1：群聊↔群聊切换同样要作废在途轮询。
            // 原先只在「私聊→群聊」时重启，导致 A 群切 B 群时在途的那个长轮询
            // （最长 20s）仍会醒来用 **A 群的 online 列表**刷一次侧栏，
            // 表现为「切了群但成员列表还是上一个群的」延迟二十秒。
            if (this._roomPollRunning) {
                this._roomPollRunning = false;
                this.pollGen = (this.pollGen || 0) + 1;   // 旧循环醒来即自杀
            }
            this.room = id; this.roomName = name; this.since = 0; this.historyDone = false;
            // v1.3.21：切会话必须复位未读状态，否则上一个会话的跳转按钮会跟着过来
            // （按钮文案是「N 条未读」，带着走就完全对不上当前会话）。
            // ⚠️ _locating 也要复位：定位是异步的（要发请求 + 等布局），
            // 期间切走的话那个 setTimeout 仍会跑并释放闸门 —— 但若切走发生在
            // 「置 true」与「释放」之间且流程被打断，闸门会永久卡住（滚到底不再判定已读）。
            this._unreadAnchor = 0; this._unreadCount = 0; this._locating = false;
            this.showUnreadJump(0);
            // v1.3.1：游客点选群聊本身就算「已进入」，立即解锁输入区。
            // 游客没有成员关系（applyJoinGate 对它恒放行），这里先解一次闸门，
            // 免得等 room_members 异步回来才解锁、点完还发不出去。
            if (this.cfg.actor && this.cfg.actor.kind === 'guest') this.applyInputGate('');
            // ⚠️ 必须重置 loadingHistory：切群时若上一批懒加载还在途，锁会一直卡在 true，
            // 导致新群的懒加载彻底不响应（onscroll 与 fillIfShort 都被它挡住）。
            this.loadingHistory = false;
            this.syncRoomOwner();
            this.renderMe();   // 资料区身份标签随群聊变化（群主/会员归属当前群）
            this.clearQuote();
            $('owRoomName').innerHTML = esc(name);
            this.hideMsgTime();   // v1.1.0：消息区重渲染前清掉悬停计时（引用的元素已不存在）
            $('owMessages').innerHTML = '';   // v1.2.6：改懒加载，不再放「加载更早消息…」入口节点
            var items = $('owRoomList').getElementsByTagName('li'), i;
            for (i = 0; i < items.length; i++) {
                items[i].className = items[i].className.replace(' active', '');
                // 未传 el（前进/后退、创建群聊跳转等）时按 data-room 自动定位高亮
                if (!el && String(items[i].getAttribute('data-room')) === String(id)) el = items[i];
            }
            if (el) el.className += ' active';
            $('owSidebar').className = $('owSidebar').className.replace(' open', '');
            if (!fromPop) this.setRoomUrl(id, false);
            var self = this;
            var load = function () {
                OwApi.post('history', { room_id: id, before: 0 }, function (r) {
                    // 已切走（切到私聊或别的群）则丢弃迟到响应
                    if (self.dm || self.room !== id) return;
                    if (r.ok) {
                        for (var i = 0; i < r.data.length; i++) self.addMessage(r.data[i], true);
                        if (r.data.length) self.since = r.data[r.data.length - 1].id;
                        // v1.3.21：**这里不再无条件 scrollBottom()** ——
                        // 滚动到哪由下面的 jumpToUnread 决定（有未读→定位到已读位置，
                        // 无未读→才滚到底）。原来那句无条件滚底会把随后的定位覆盖掉，
                        // 表现为「有未读却还是直接进到最下面」。
                        if (r.data.length < 30) self.historyDone = true;
                        // 从私聊切回群聊时群聊长轮询是停的，需在此重新拉起
                        if (!self._roomPollRunning) { self._roomPollRunning = true; self.startPoll(); }
                        // v1.2.6 懒加载：填不满屏幕就自动往前补
                        // v1.3.21：**有未读时定位到上次已读位置**而不是直接到底部，
                        // 右下角浮出「N 条未读 ↓」；没有未读才照旧滚到底。
                        // 注意此时**不能** markRead —— 一标记未读就清了，
                        // 按钮上的条数与列表徽标会凭空消失。
                        self.jumpToUnread();
                    } else if (r.need_password) {
                        // 通行授权已过期 → 重新验证，验证成功后自动重试
                        self.passForget(id);
                        self.askRoomPassword(id, name, load);
                    }
                });
            };
            load();
            this.renderRoomPanel();     // v1.1.1：右侧栏群聊信息区跟随切群刷新
            this._fireRoomSwitch();   // 插件钩子：切换群聊（公告等按群拉取）
            this._fireViewChange();   // v1.1.0：回到群聊视图 → 插件按 roomId 复原群级装饰
            // v1.2.27：立即渲染「所有成员」并校准发言闸门，不等首次 poll 返回（最长 20 秒）
            // v1.5.29：拆成两条路 —— 面板开着才拉完整名单（顺带校准闸门）；
            // 面板收起只要闸门，用 room_members?lite=1，不再为渲染看不见的列表付两份全量查询。
            if (this.membersVisible()) this.loadMembers(true);
            else this.syncJoinGate();
            // P1/P2（WS 实时层）：订阅房间（presence 归属 + 消息推送范围）。
            // since 传 0：此刻 history 还没回来；服务端对 since<=0 只对齐水位、不补内容，
            // 期间到达的新消息由推送带来（与 history 返回段靠 addMessage 去重，不重不漏）。
            this.rt.sub(id, 0);
        },

        /* ---------- 长轮询（主通道）+ 断线降级短轮询 ----------
           v1.1.0：引入 pollGen 世代号。群聊与私聊共用同一套消息区/输入栏，
           两条长轮询必须互斥——切换视图时自增世代号，旧循环醒来即自杀，
           避免两个 in-flight 请求同时刷新同一个 #owMessages、互相覆盖 since。 */
        startPoll: function () {
            // P2：WS 主通道在线 → 不启动消息长轮询（WS 断开时 onClose 会把它拉回来）
            if (OwChat.rt && OwChat.rt.on) return;
            var self = this;
            var myGen = this.pollGen = (this.pollGen || 0) + 1;
            var alive = function () { return self.pollGen === myGen && !self.dm; };
            function loop() {
                if (!alive()) return;
                var roomId = self.room, since = self.since;
                var t0 = new Date().getTime();
                // v1.5.29：只有右侧栏真的打开时才要成员名单 —— 收起时服务端零查询。
                // 面板开着也走这条路保证实时（有人加入/离开能跟着刷新）；关掉即停，下一轮就不再带。
                OwApi.post('poll', {
                    room_id: roomId, since: since,
                    want_members: self.membersVisible() ? 1 : 0
                }, function (r, status) {
                    if (!alive()) return;
                    if (!r || !r.ok) {
                        // 密码房授权过期：停止轮询，重新验证后继续
                        if (r && r.need_password) {
                            self.passForget(roomId);
                            self.askRoomPassword(roomId, self.roomName || '', function () { self.startPoll(); });
                            return;
                        }
                        self.failCount++;
                        // 降级：短轮询 + 指数退避（2s → 10s 封顶）
                        var wait = Math.min(10000, 2000 * self.failCount);
                        $('owLatency').innerHTML = '重连中…';
                        $('owLatency').style.color = 'var(--ow-red)';
                        setTimeout(loop, wait);
                        return;
                    }
                    self.failCount = 0;
                    var ms = new Date().getTime() - t0;
                    $('owLatency').innerHTML = '● ' + ms + ' ms';
                    $('owLatency').style.color = 'var(--ow-green)';
                    self.since = r.since;
                    var i, hasNew = false;
                    for (i = 0; i < r.messages.length; i++) {
                        self.addMessage(r.messages[i]);
                        hasNew = true;
                    }
                    if (hasNew) {
                        // v1.3.21：用户若还停在未读定位点（按钮可见），**不要**把他拽到底部。
                        // 微信的行为是留在原地，只在底部露出「N 条新消息」提示 ——
                        // 正在读的时候被强行拉走是最烦人的那种打断。
                        if (self._unreadJumpVisible()) self.showUnreadJump(0);
                        else self.scrollBottom();
                        if (self.sound) beep();
                        // v1.1.0：当前群有消息时立刻前置该会话（其余会话由 startConvPoll 兜底）
                        self.loadConversations();
                    }
                    // v1.2.27：优先用新的成员口径（成员全量 + 在场游客）；
                    // 老服务端没有 members 字段时退回原来的在线名单，不报错。
                    self._canStatus = r.online_status;
                    if (r.members) self.renderMembers(r.members, r.online_status);
                    else self.renderOnline(r.online, r.online_status);
                    setTimeout(loop, 100);
                });
            }
            loop();
        },

        /* ---------- 输入框高度：随内容自动增高 + 拖拽手柄手动拉高 ---------- */
        inputMaxH: 120,      // 自动增高上限（拖拽可上调）
        inputUserH: 0,       // 用户手动拖出的高度（0=未设置，走自动增高）
        autoGrow: function () {
            var el = $('owInput');
            if (!el) return;
            el.style.height = 'auto';
            var h = el.scrollHeight + 2;
            var min = 40;
            if (this.inputUserH > 0) {
                // 手动设定过高度：内容再多也不超过用户设定，内容少时也不缩回去
                el.style.height = Math.max(min, Math.min(Math.max(h, this.inputUserH), 320)) + 'px';
                return;
            }
            el.style.height = Math.max(min, Math.min(h, this.inputMaxH)) + 'px';
        },
        bindInputResize: function () {
            var self = this, el = $('owInput'), handle = $('owInputResize');
            if (!el || !handle) return;
            handle.onmousedown = function (e) {
                e = e || w.event;
                var startY = e.clientY, startH = el.offsetHeight;
                if (e.preventDefault) e.preventDefault(); else e.returnValue = false;
                document.onmousemove = function (ev) {
                    ev = ev || w.event;
                    var h = startH + ((ev.clientY || 0) - startY);
                    h = Math.max(40, Math.min(h, 320));
                    self.inputUserH = h;
                    el.style.height = h + 'px';
                    try { w.localStorage.setItem('owl_input_h', String(h)); } catch (err) {}
                };
                document.onmouseup = function () {
                    document.onmousemove = null;
                    document.onmouseup = null;
                };
                return false;
            };
        },
        msgCache: {},

        // 统一构建消息 DOM：头像一侧依次是「用户组标签、昵称」；
        // 时间不直接显示，悬停气泡满 2 秒才显示；操作（@/私信/收藏/撤回等）改为右键菜单
        buildMessage: function (m) {
            var cls = 'ow-msg';
            if (m.mine) cls += ' mine';
            if (m.type === 'mention') cls += ' mention';
            // v1.2.1：私聊标识来自服务端下发的 dm 位，不能再靠 m.type==='private'
            //（私聊里的图片/文件消息 type 分别是 image/file）
            if (m.dm) cls += ' private';
            if (m.type === 'system') cls += ' system';
            if (m.recalled) cls += ' recalled';
            // v1.1.0 软删除：服务端已清空 content，前台只显示占位文案
            if (m.deleted) cls += ' deleted';

            var content;
            if (m.deleted) content = '<span class="ow-msg-content">该消息已删除</span>';
            else if (m.recalled) content = '<span class="ow-msg-content">此消息已撤回</span>';
            // v1.2.13：图片与文件**不再套 .ow-msg-content 气泡**。
            // v1.2.7 把气泡底改成纯白后，这两种消息就多了一层「白底座」——
            // 图片外一圈白、文件卡片里又一层白（.ow-file-card 自带 background+border），
            // 呈现为白底里再套一个白框，视觉上像「双重边框」，很脏。
            // 两者自身都已具备完整外观：.ow-msg-img 有圆角，.ow-file-card 有白底+边框。
            // ⚠️ 不能靠「给 .ow-msg-content 加 class 再改 CSS」绕：那类节点还要参与
            // markRecalled 的 outerHTML 替换与引用跳转的 querySelector('.ow-msg-content')，
            // 去掉节点最干净。留空 span 反而会破坏 flex 基线对齐。
            else if (m.type === 'file') content = '<span class="ow-msg-plain">' + fileCardHtml(m) + '</span>';
            else if (m.type === 'image') content = '<span class="ow-msg-plain"><img class="ow-msg-img" src="' + esc(m.content) + '" loading="lazy" decoding="async" onclick="OwChat.viewImg(this.src)" alt="图片"></span>';
            // 混排消息**带文字**，所以走气泡（.ow-msg-content）而不是 .ow-msg-plain：
            // 纯图/纯文件才需要「无底座」，有文字却没气泡会显得半句话悬在空中。
            else if (m.type === 'rich') content = '<span class="ow-msg-content ow-msg-rich">' + richHtml(m) + '</span>';
            else content = '<span class="ow-msg-content">' + (m.quote && (m.quote.nick || m.quote.text)
                    ? '<span class="ow-msg-quote' + (m.quote.id ? ' ow-quote-link' : '') + '"'
                      + (m.quote.id ? ' title="点击查看原消息" onclick="OwChat.jumpToQuote(' + (m.quote.id | 0) + ')"' : '')
                      + '><b>' + esc(m.quote.nick || '') + '</b>'
                      + (m.quote.nick ? '：' : '') + esc(m.quote.text || '') + '</span>'
                    : '') + linkifyHtml(esc(m.content)) + '</span>';

            var isSys = m.type === 'system';
            // meta 行：头像一侧依次是「用户组标签、昵称」；时间不直接显示，
            // 悬停满 2 秒才显示（见 ow-time-show 类与 hideMsgTime）
            var timeHtml = '<span class="ow-msg-time">' + esc(m.date + ' ' + m.time) + '</span>';
            var tagHtml = roleTag(m.role, m.title, m.uid);
            // v1.5.15：游客昵称单独降一档（CSS .ow-msg-nick.ow-nick-guest，14 → 12px）。
            //   根因是**不对称**而非字号：roleTag() 只给 bot/群主/超管/VIP/会员/头衔出标签，
            //   游客一个标签都没有 —— 「游客0046c」孤零零杵着，旁边全是「标签 + 昵称」，
            //   同样字号下它自然成了整行最重的那一个（站方原话「游客的昵称怎么这么大」）。
            //   这里按 role 判定，不能靠 CSS 的 :not(.ow-tag) 猜 ——
            //   「这行没标签」的原因不止游客一种（无头衔的普通用户同样没有）。
            //   昵称同时出现在通知流（ow-notice-nick）与举报盒里，那两处没有标签参照，
            //   不加这个类，避免平白变小。
            var isGuest = !m.uid && m.role === 'guest';
            var mainPart = tagHtml
                + ' <span class="ow-msg-nick' + (isGuest ? ' ow-nick-guest' : '')
                + '" onclick="OwChat.userCard(' + (m.uid || 0) + ',' + jsAttr(m.nickname) + ')">' + esc(m.nickname) + '</span>';
            // v1.1.0：去掉昵称后的「→ 昵称」私信文字标签。
            // 私聊会话页双方已确定；群聊内的 @提及 足以定位发给人，额外标注纯噪音。
            //
            // v1.2.8：**私聊不显示 meta 行**（昵称与角色标签都不显示）。
            // 私聊只有两个人、页面本身就是对话，顶部已写明对方是谁，
            // 每条消息上方再重复一遍昵称是纯噪音。头像仍保留（可点开资料卡）。
            // 判定用 m.dm（服务端下发的私聊标识），不靠 m.type —— v1.2.1 起
            // 私聊里的图片/文件消息 type 分别是 image/file，用 type 会漏判。
            // ⚠️ 悬停时间也一并去掉：meta 行整体不渲染，留个空 div 只会破坏
            // flex 布局与 .ow-msg-body 的基线对齐。
            var meta = (isSys || m.dm) ? '' :
                '<div class="ow-msg-meta">' + mainPart + timeHtml + '</div>';

            return {
                cls: cls,
                // v1.1.8：消息头像与昵称一样可点 —— 左击头像即打开该用户资料卡
                //（原先只有昵称带 onclick，头像是纯展示，两处行为不一致）。
                // 游客（uid 为 0）传 0，userCard 内部会走 pmHint 提示不可查看。
                // 加 .ow-msg-av 可点类供 CSS 给 cursor:pointer 与 hover 反馈。
                html: (isSys ? '' : '<span class="ow-msg-av" onclick="OwChat.userCard(' + (m.uid || 0) + ',' + jsAttr(m.nickname) + ')">'
                    + avatarHtml(m.avatar, m.nickname, false, m.role) + '</span>')
                    + '<div class="ow-msg-body">' + meta + content + '</div>'
            };
        },

        /** 创建群聊弹窗（用户也可创建，含后台创建房间的全部选项） */
        roomCreateModal: function () {
            var self = this;
            var TYPE = { 'public': '普通', 'password': '密码房', 'role': '角色限定' };
            var ROLE = { 'guest': '游客', 'member': '普通用户', 'vip': 'VIP', 'admin': '超级管理员' };
            // v1.1.14：服务端已按当前身份算好（管理员恒为 1），前端不再自行判 role
            var canPrivate = (this.cfg.settings || {}).room_private_create !== '0';
            var opts = function (map, keys, cur) {
                var h = '';
                for (var i = 0; i < keys.length; i++) {
                    h += '<option value="' + esc(keys[i]) + '"' + (cur === keys[i] ? ' selected' : '') + '>' + esc(map[keys[i]] || keys[i]) + '</option>';
                }
                return h;
            };
            /* v1.5.10：建群弹窗**不再问群名称**（站方：不需要定义群聊名称就直接创建，
               建完可以去群聊设置里改）。服务端从 v1.2.19 起就支持留空自动命名
               「<昵称>的群聊」，所以这里只是把输入框撤掉、name 不提交 = 走默认名。
               创建成功的 toast 回显的是服务端给的群名，用户当场就知道群叫什么。 */
            this.openModal(
                '<h3>创建群聊</h3>'
                + '<div class="ow-form-item"><label>类型</label><select class="ow-input" id="owRCType">'
                + opts(TYPE, ['public', 'password', 'role'], 'public') + '</select></div>'
                + '<div class="ow-form-item" id="owRCPassRow" style="display:none"><label>房间密码</label><input class="ow-input" type="password" id="owRCPass" placeholder="密码群必须设置密码"></div>'
                + '<div class="ow-form-item" id="owRCRoleRow" style="display:none"><label>最低进入角色</label><select class="ow-input" id="owRCRole">'
                + opts(ROLE, ['guest', 'member', 'vip', 'admin'], 'guest') + '</select></div>'
                + '<div class="ow-form-item"><label>群简介（可选）</label><input class="ow-input" id="owRCDesc" maxlength="200" placeholder="一句话介绍这个群"></div>'
                // v1.1.11 公开性 State 开关。与上面的「类型」正交：
                // 类型管「进入方式」（密码/角色门槛），开关管「谁能发现这个群」。
                // v1.1.14：后台总闸关闭时对当前身份禁用（canPrivate 由服务端按身份算好后下发），
                // 避免留下「能点、提交必报错」的死开关。
                // v1.1.16：删掉「开启：显示在群聊列表，游客可进入并发言。关闭：不进公开列表…」，
                // 同样的理由（读着绕 + 「游客可发言」并非恒成立）。
                // 「谁能看到这个群」由下方 owRCPubNote 随开关实时说明，不重复写死。
                + '<div class="ow-form-item ow-form-item-switch">'
                // v1.1.18 修正：公开性开关的标签改回「公开群聊」。
                // v1.1.16 曾把 is_public 也译成「普通」，与同弹窗里 type=public 的
                // 「普通」撞词 → 两个「普通」并排，用户以为公开性开关消失了。
                // 定案：**类型**= 普通/密码群/角色限定，**公开性**= 公开/仅邀请。
                + switchHtml('owRCPublic', '公开群聊', true,
                    canPrivate ? ''
                               : '站点已关闭「创建仅邀请群聊」，新群只能公开（管理员不受此限制）。',
                    !canPrivate)
                + '</div>'
                + '<div class="ow-form-msg ow-rc-note" id="owRCPubNote"></div>'
                + '<div class="ow-room-form-tip" id="owRCTip"></div>'
                + '<div class="ow-form-msg" id="owRCMsg"></div>'
                + '<div class="ow-modal-actions">'
                + '<button class="ow-btn ow-btn-ghost" id="owRCCancel">取消</button>'
                + '<button class="ow-btn ow-btn-primary" id="owRCCreate">创 建</button></div>'
            );
            bindSwitches($('owModal'));
            var typeSel = $('owRCType'), tip = $('owRCTip'), msg = $('owRCMsg');
            var pubBox = $('owRCPublic'), pubNote = $('owRCPubNote');
            // 公开性提示随开关变化：把「谁能进这个群」讲清楚，避免建完才发现进不去。
            // v1.1.18：措辞改回「公开 / 仅邀请」。v1.1.16 误用「普通」，与类型撞词。
            var refreshPub = function () {
                if (!canPrivate) {
                    pubNote.innerHTML = '<span style="color:var(--ow-red)">站点已关闭「创建仅邀请群聊」，新群只能公开。</span>';
                    return;
                }
                pubNote.innerHTML = pubBox.checked
                    ? '<span style="color:var(--ow-text-sub)">群聊将出现在左侧列表，所有人（含游客）都能看到并进入。</span>'
                    : '<span style="color:var(--ow-red)">仅邀请：群聊不会出现在列表里。创建后只有你能进，其他人需要你或群成员在群聊信息里按用户 ID 邀请。</span>';
            };
            pubBox.onchange = refreshPub;
            refreshPub();
            var refreshTip = function () {
                var t = typeSel.value;
                $('owRCPassRow').style.display = t === 'password' ? 'block' : 'none';
                $('owRCRoleRow').style.display = t === 'role' ? 'block' : 'none';
            };
            typeSel.onchange = refreshTip;
            refreshTip();
            // v1.2.44：建群前置提示 —— 名额 / 是否需花积分 / 为什么不能建。
            //   先问服务端再渲染（异步），拿到前保持按钮可用（乐观），
            //   ⚠️ 不能反过来先禁用再放开：请求失败时用户会卡在一个永远点不动的按钮上。
            tip.innerHTML = '<span style="color:var(--ow-text-sub)">检查创建资格…</span>';
            var gateInfo = null;
            OwApi.post('room_create_gate', {}, function (rg) {
                gateInfo = (rg && rg.ok) ? rg.gate : null;
                renderGateTip();
            });
            // v1.2.45：文案全部由**前端**拼，数字用 <b> 强调。
            //   服务端只给纯文本 reason + 结构化字段（cost/quota/used/level/points/min_level），
            //   这样既不会把 <b> 当字面量显示出来，也不必在插件里拼 HTML 交给前端转义。
            function renderGateTip() {
                var g = gateInfo;
                if (!g) { tip.innerHTML = ''; return; }
                var html = '';
                if (!g.allowed) {
                    html = '<span style="color:var(--ow-red)">' + esc(g.reason || '当前无法创建群聊') + '</span>';
                    $('owRCCreate').disabled = true;
                    $('owRCCreate').style.opacity = '.5';
                    $('owRCCreate').style.cursor = 'not-allowed';
                } else if (g.cost > 0) {
                    // v1.5.4：「积分」是站点可改的名词，统一走 pointsName()
                    var pn = self.pointsName();
                    var why = (typeof g.level === 'number' && g.min_room_level && g.level < g.min_room_level)
                        ? '建群需 <b>' + g.min_room_level + '</b> 级（当前 Lv.' + g.level + '）'
                        : '免费名额已用完（<b>' + g.used + '/' + g.quota + '</b>）';
                    html = '<span style="color:var(--ow-yellow)">' + why + '，可消耗 <b>' + g.cost
                        + '</b> ' + esc(pn) + '创建（当前 <b>' + g.points + '</b>）。</span>';
                    if (g.points < g.cost) {
                        html += '<br><span style="color:var(--ow-red)">' + esc(pn) + '不足：还需 <b>'
                            + (g.cost - g.points) + '</b> ' + esc(pn) + '。</span>';
                        $('owRCCreate').disabled = true;
                        $('owRCCreate').style.opacity = '.5';
                        $('owRCCreate').style.cursor = 'not-allowed';
                    }
                } else if (g.quota > 0) {
                    html = '<span style="color:var(--ow-text-sub)">免费名额 <b>' + g.used + '</b> / <b>'
                        + g.quota + '</b>（超出后可用' + esc(self.pointsName()) + '创建）。</span>';
                } else if (g.quota === 0 && !g.level) {
                    // gate 字段全为默认值 = 没有任何插件接管（未安装 / 已停用 / 超管）
                    html = '<span style="color:var(--ow-text-sub)">创建群聊不受等级或名额限制。</span>';
                } else {
                    html = '';
                }
                tip.innerHTML = html;
            }
            var submit = function () {
                if (gateInfo && !gateInfo.allowed) {
                    msg.innerHTML = '<span style="color:var(--ow-red)">'
                        + esc(gateInfo.reason || '当前无法创建群聊') + '</span>';
                    return;
                }
                var t = typeSel.value;
                if (t === 'password' && !$('owRCPass').value) {
                    msg.innerHTML = '<span style="color:var(--ow-red)">密码群必须设置密码</span>'; return;
                }
                msg.innerHTML = '创建中…';
                // name 不提交：服务端按「留空」补默认名（见 room_create）
                OwApi.post('room_create', {
                    type: t, password: $('owRCPass') ? $('owRCPass').value : '',
                    min_role: $('owRCRole').value,
                    // v1.1.11：公开性开关。传 '0'/'1' 字符串，服务端按 === '0' 归一
                    description: $('owRCDesc').value,
                    is_public: pubBox.checked ? '1' : '0'
                }, function (r) {
                    if (!r.ok) { msg.innerHTML = '<span style="color:var(--ow-red)">' + esc(r.msg) + '</span>'; return; }
                    self.closeModal();
                    toast('群聊「' + r.name + '」已创建'
                        + (r.cost > 0 ? '，扣除 ' + r.cost + ' ' + self.pointsName() : '')
                        + (pubBox.checked ? '' : '（仅邀请，可在群聊信息里邀请成员）'));
                    self.refreshRooms(r.id, r.name);
                });
            };
            $('owRCCreate').onclick = submit;
            $('owRCCancel').onclick = function () { self.closeModal(); };
        },

        /** 重新拉取房间列表并定位到指定房间 */
        refreshRooms: function (gotoId, gotoName) {
            var self = this;
            OwApi.post('rooms', {}, function (r) {
                if (!r.ok) return;
                self.cfg.rooms = r.data;
                self.loadConversations();   // v1.1.0：列表为群聊+私聊聚合，须走 conversations
                var found = null, i, j;
                for (i = 0; i < r.data.length; i++) if (r.data[i].id === gotoId) found = r.data[i];
                if (found) {
                    self.switchRoom(gotoId, found.name, null);
                } else if (gotoName) {
                    $('owRoomName').innerHTML = esc(gotoName);
                }
            });
        },

        /* ---------- 消息流时间戳（v1.2.5） ---------- */
        /**
         * 相邻消息间隔阈值（秒）：超过才插时间戳。
         * 5 分钟是个平衡点：密集聊天时列表不被时间戳打断，
         * 而一段明显停顿（午休、下班、隔天回来）又能让人知道时间断层在哪。
         */
        TIME_DIVIDER_GAP: 300,

        /**
         * 是否需要在这条消息前插时间戳。
         * @param {number|null} prevTs 上一条消息的 ts（秒）；null = 消息流里的第一条
         * @param {number}      ts      当前消息的 ts
         */
        needTimeDivider: function (prevTs, ts) {
            // 流里第一条：上一条是「加载更早消息…」之类的非消息节点，
            // 不拿它当上一条消息比时间，否则会在列表最顶部多出一条无意义的时间戳。
            if (!prevTs || !ts) return false;
            return Math.abs(ts - prevTs) > this.TIME_DIVIDER_GAP;
        },

        /**
         * 时间戳文案分档（与气泡悬停时间 date('H:i') 的 24 小时制保持一致）：
         *   今天        → 13:00
         *   昨天        → 昨天 13:00
         *   本周内      → 周三 13:00
         *   更早        → 10月4日 13:00
         * 跨年时「更早」档补上年份，避免「去年 3 月 5 日」和今年混淆。
         */
        timeDividerText: function (ts) {
            var d = new Date(ts * 1000), now = new Date();
            var hm = (function (x) {
                return (x < 10 ? '0' : '') + x;
            });
            var timeStr = hm(d.getHours()) + ':' + hm(d.getMinutes());

            // 归零到当天 00:00 再算天数差，避开时分秒带来的小数误差
            var d0 = new Date(d.getFullYear(), d.getMonth(), d.getDate());
            var n0 = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            var dayDiff = Math.round((n0 - d0) / 86400000);

            if (dayDiff === 0) return timeStr;                       // 今天
            if (dayDiff === 1) return '昨天 ' + timeStr;              // 昨天
            if (dayDiff < 7) return '周' + '日一二三四五六'[d.getDay()] + ' ' + timeStr;
            var ymd = (d.getMonth() + 1) + '月' + d.getDate() + '日';
            if (d.getFullYear() !== now.getFullYear()) ymd = d.getFullYear() + '年' + ymd;
            return ymd + ' ' + timeStr;
        },

        /** 生成一个时间戳分隔节点（不进 msgCache，它不是消息） */
        buildTimeDivider: function (ts) {
            var el = document.createElement('div');
            el.className = 'ow-time-divider';
            var span = document.createElement('span');
            span.textContent = this.timeDividerText(ts);
            el.appendChild(span);
            return el;
        },

        /**
         * 取「插入点参考节点 ref 自身」的时间戳 ts。
         *
         * 向上翻页时新消息插在 ref **之前**，所以要比的时间是 **ref 自己**的时间，
         * 不是 ref 之前那条 —— 后者在本轮插入前根本不存在（这批更早的消息还没渲染），
         * 那是 addMessage 追加路径才需要的。
         */
        tsOfRef: function (ref) {
            if (!ref || !ref.id || ref.id.indexOf('owMsg') !== 0) return 0;
            var c = this.msgCache[parseInt(ref.id.replace('owMsg', ''), 10)];
            return c ? (parseInt(c.ts, 10) || 0) : 0;
        },

        addMessage: function (m, batch) {
            // P3（发送迁移）：真消息到达（推送/历史/回显任一来源）→ 清掉同 cid 的
            // 「发送中/失败」临时气泡——这就是「超时失败后消息最终落库自动转正」的实现点。
            if (m && m.cid) this.clearPending(m.cid);

            var box = $('owMessages');
            var exist = document.getElementById('owMsg' + m.id);
            if (exist) {
                // 轮询带回撤回状态时，同步更新已渲染的气泡（否则撤回后界面不变）
                if (m.recalled) this.markRecalled(m.id);
                return;
            }
            this.msgCache[m.id] = m;
            var b = this.buildMessage(m);
            var div = document.createElement('div');
            div.className = b.cls;
            div.id = 'owMsg' + m.id;
            div.innerHTML = b.html;

            // 时间戳：与已渲染的最后一条比时间（末尾追加路径）
            var lastMsg = null, kids = box.querySelectorAll('.ow-msg'), i;
            for (i = kids.length - 1; i >= 0; i--) { lastMsg = kids[i]; break; }
            var prevTs = 0;
            if (lastMsg) {
                var c = this.msgCache[parseInt(lastMsg.id.replace('owMsg', ''), 10)];
                prevTs = c ? (parseInt(c.ts, 10) || 0) : 0;
            }
            if (this.needTimeDivider(prevTs, m.ts)) box.appendChild(this.buildTimeDivider(m.ts));
            box.appendChild(div);
            // v1.2.18：文件名中间省略必须在**入 DOM 之后**量宽（见 fitFileName 注释），
            // 同一帧内的多次插入由 scheduleFitFileName 合并成一次重排。
            if (b.html.indexOf('ow-file-name') >= 0) scheduleFitFileName(div);

            // ⚠️ 裁剪条件必须按**消息条数**算，不能用 children.length ——
            // children 里混着时间戳节点，用它算会让上限被时间戳虚增，
            // 导致实际消息数远未到 500 就开始裁（表现为「消息莫名变少」）。
            if (!batch) {
                var msgCount = box.getElementsByClassName('ow-msg').length;
                while (msgCount > 500) {
                    // 只删「最靠上的那条消息」，并连带它**之后**紧跟的时间戳节点，
                    // 否则会留下一个失去参照的孤儿时间戳在顶部。
                    var old = box.querySelector('.ow-msg');
                    if (!old) break;
                    var oid = parseInt((old.id || '').replace('owMsg', ''), 10);
                    if (oid) delete this.msgCache[oid];
                    var after = old.nextSibling;
                    box.removeChild(old);
                    if (after && after.className
                        && after.className.indexOf('ow-time-divider') >= 0) {
                        box.removeChild(after);
                    }
                    msgCount--;
                }
            }
        },

        /** 把某条消息的气泡更新为「已撤回」状态 */
        markRecalled: function (id) {
            var el = document.getElementById('owMsg' + id);
            if (!el || el.className.indexOf('recalled') >= 0) return;
            el.className += ' recalled';
            // v1.2.13：图片 / 文件消息已不再套 .ow-msg-content（去白色底座），
            // 它们的承载节点是 .ow-msg-plain。两种都要查，否则撤回时
            // 占位文案不渲染、原图/文件仍留在界面上 —— 表现为「撤回了但内容还在」。
            // 删除/撤回占位统一回退成 .ow-msg-content，让 .ow-msg.recalled
            // 与 .ow-msg.deleted 的虚线弱化样式正常生效。
            var cs = el.querySelector('.ow-msg-content') || el.querySelector('.ow-msg-plain');
            if (cs) cs.outerHTML = '<span class="ow-msg-content">此消息已撤回</span>';
            this.msgCache[id] = this.msgCache[id] || {};
            this.msgCache[id].recalled = 1;
        },

        /** 自研确认弹窗（替代原生 confirm） */
        confirmModal: function (text, onOk) {
            var self = this;
            this.openModal(
                '<h3>确认操作</h3>'
                + '<p class="ow-modal-desc">' + esc(text) + '</p>'
                + '<div class="ow-modal-actions">'
                + '<button class="ow-btn ow-btn-ghost" id="owCfmNo">取消</button>'
                + '<button class="ow-btn ow-btn-danger" id="owCfmOk">确定</button></div>'
            );
            $('owCfmOk').onclick = function () { self.closeModal(); if (onOk) onOk(); };
            $('owCfmNo').onclick = function () { self.closeModal(); };
        },

        /** 自己发消息后收尾（v1.3.21）：若跳转按钮还挂着，说明用户停在定位点没看完，
         *  但既然自己发言了就是参与了，清掉按钮比留着更符合直觉。 */
        afterSendClearJump: function () {
            var btn = $('owUnreadJump');
            if (btn && btn.style.display !== 'none' && btn.style.display !== '') {
                this.showUnreadJump(0);
                this._unreadAnchor = 0;
            }
        },

        scrollBottom: function () {
            var box = $('owMessages');
            box.scrollTop = box.scrollHeight;
        },

        /**
         * 懒加载更早的一批消息（v1.2.6）。触发方有两个：
         *   1. 消息区向上滚到接近顶部（见 bindEvents 里的 onscroll）
         *   2. 首屏渲染完若**填不满容器**，自动续拉（见 fillIfShort）
         * 不再有「加载更早消息…」可点入口 —— 那是 v1.1.0 之前的做法。
         *
         * 视图分流沿用 v1.1.0：群聊走 history(room_id)，私聊走 dm_history(peer)，
         * 两者都是「取 before 之前的 30 条」。
         *
         * @param {function} done 加载完成回调（成功/失败/已切走都会调）
         */
        loadHistory: function (done) {
            var self = this, box = $('owMessages');
            var finish = function () { if (typeof done === 'function') done(); };
            if (this.historyDone || this.loadingHistory) { finish(); return; }
            var first = box.querySelector('.ow-msg');
            if (!first) { this.historyDone = true; finish(); return; }   // 一条都没有 = 拉完了
            var before = parseInt(first.id.replace('owMsg', ''), 10);
            var isDm = !!this.dm, peer = isDm ? this.dm.peer : '';
            var action = isDm ? 'dm_history' : 'history';
            var payload = isDm ? { peer: peer, before_id: before } : { room_id: this.room, before: before };
            this.loadingHistory = true;
            OwApi.post(action, payload, function (r) {
                self.loadingHistory = false;
                // ⚠️ 两边都必须先 !! 归一再比。
                // 群聊首次进入时 self.dm 从未被赋值，是 undefined；而 isDm 是 !!this.dm
                // 得到的 boolean false。直接用 !== 比较 undefined 与 false 恒为 true，
                // 会把每次响应都当成「已切走」丢弃 —— 表现为「懒加载永远不生效、
                // 往上滚什么都没反应」，且没有任何报错，很难定位。
                var nowIsDm = !!self.dm;
                if (nowIsDm !== isDm || (isDm && self.dm.peer !== peer)) { finish(); return; }
                if (!r.ok) { self.historyDone = true; finish(); return; }
                if (!r.data.length) { self.historyDone = true; finish(); return; }

                // ⚠️ 插入新节点会改变 scrollHeight，必须**先记旧高度、插完再按差值回推**，
                // 否则浏览器会保持 scrollTop 不变 → 视口猛地跳到新加载内容的位置（老实现就这毛病）。
                var oldH = box.scrollHeight, i;
                // 定位态下记一下定位消息当前距视口顶部的距离：上方插入节点后按它回推，
                // 而不是按 scrollHeight 差值 —— 后者会把视口连同内容一起往下拽，
                // 表现为「刚定位好就又跳了一下」。
                var keepAnchor = null, anchorEl = null;
                if (self._unreadJumpVisible() && self._unreadAnchor) {
                    anchorEl = $('owMsg' + self._unreadAnchor);
                    if (anchorEl) keepAnchor = anchorEl.offsetTop - box.offsetTop - box.scrollTop;
                }
                for (i = r.data.length - 1; i >= 0; i--) self.addMessageBefore(r.data[i], first);
                if (keepAnchor !== null && anchorEl) {
                    box.scrollTop = anchorEl.offsetTop - box.offsetTop - keepAnchor;
                } else {
                    box.scrollTop = box.scrollHeight - oldH;
                }

                // 不足一屏（scrollHeight <= clientHeight）说明还有空间，继续往前拉，
                // 避免新群/新会话只显示最后几条、上方却是一片空白。
                var short = box.scrollHeight <= box.clientHeight + 4;
                if (short && r.data.length >= 30) { self.loadHistory(finish); return; }
                if (r.data.length < 30) self.historyDone = true;   // 不足一页 = 没有更早的了
                finish();
            });
        },

        /**
         * 从「定位点」往后拉一段消息（v1.3.21：未读定位用）。
         *
         * 与 loadHistory 是**两个方向**的事，不合并：
         *  loadHistory 取 first 之前的消息、插到 first 上方、还要按 scrollHeight 差值回推；
         *  这里取 anchorId 及其之后的消息、**追加到末尾**、不动滚动位置 ——
         *  两者混在一起会把「保持视口」这个最关键的逻辑搅乱。
         *
         * 用完后把 since 设为末条 id，这样首轮长轮询从它往后接，不会重复渲染。
         */
        loadFromAnchor: function (anchorId, done) {
            var self = this, box = $('owMessages');
            var finish = function () { if (typeof done === 'function') done(); };
            var isDm = !!this.dm, peer = isDm ? this.dm.peer : '';
            var action = isDm ? 'dm_history' : 'history';
            var payload = isDm
                ? { peer: peer, from_id: anchorId }
                : { room_id: this.room, from_id: anchorId };
            this.loadingAnchor = true;
            OwApi.post(action, payload, function (r) {
                self.loadingAnchor = false;
                // 与 loadHistory 同一道防线：会话已切走就丢弃（理由见该处注释）
                var nowIsDm = !!self.dm;
                if (nowIsDm !== isDm || (isDm && self.dm.peer !== peer)) { finish(); return; }
                if (!r || !r.ok || !r.data.length) { finish(); return; }
                for (var i = 0; i < r.data.length; i++) self.addMessage(r.data[i]);
                // ⚠️ since **只能前进**。首屏已经把 since 设到最新一条（末条 id 最大），
                //   而这里取的是「定位点那段」的末条，id 必然更小 —— 直接赋值会把
                //   since 回退，长轮询随即把整段消息当作「新的」重发，
                //   于是 addMessage 又跑一遍 + scrollBottom 触发，定位彻底失效。
                var tailId = r.data[r.data.length - 1].id;
                if (tailId > self.since) self.since = tailId;
                finish();
            });
        },

        /**
         * 首屏渲染完成后调用：若消息区填不满容器，自动续拉更早的消息直到填满或拉完。
         * v1.2.6 懒加载配套 —— 少了「加载更早消息…」入口后，消息少的会话
         * 若不自动补，用户会看到上方空白且没有任何提示。
         */
        fillIfShort: function () {
            var box = $('owMessages');
            if (!box || this.historyDone || this.loadingHistory) return;
            if (box.scrollHeight > box.clientHeight + 4) return;   // 已填满，不用管
            if (!box.querySelector('.ow-msg')) return;           // 还没拿到任何消息，等首屏回调
            this.loadHistory();
        },

        addMessageBefore: function (m, ref) {
            var box = $('owMessages');
            if (document.getElementById('owMsg' + m.id)) return;
            this.msgCache[m.id] = m;
            var b = this.buildMessage(m);
            var div = document.createElement('div');
            div.className = b.cls;
            div.id = 'owMsg' + m.id;
            div.innerHTML = b.html;
            // 时间戳：与 ref（更晚的那条）比时间，插在**更早这条的上方**。
            // ⚠️ 顺序必须是「先 div 后 divider」：
            //   insertBefore 的第二个参数必须是 div 的**已在 DOM 中的后继节点**。
            //   若先插 divider，就得拿还没入 DOM 的 div 当参照 → 抛 NotFoundError。
            box.insertBefore(div, ref);
            // v1.2.18：同上，插入后才有宽度可量
            if (b.html.indexOf('ow-file-name') >= 0) scheduleFitFileName(div);
            var prevTs = this.tsOfRef(ref);
            if (this.needTimeDivider(prevTs, m.ts)) {
                box.insertBefore(this.buildTimeDivider(m.ts), div);
            }
        },

        /* ---------- 消息右键菜单（@ / 私信 / 收藏贴纸 / 撤回，插件可扩展） ---------- */
        _ctxItems: [],
        /**
         * 消息右键菜单扩展点（供插件追加菜单项，如禁言插件的「禁言」）。
         * 回调签名：function (items, msg, env) —— 直接 items.push({t:'文案', run:fn}) 即可。
         * env：{ roomId: 当前房间ID, actor: 当前身份对象 }
         */
        _ctxExt: [],
        _ctxExtContent: [],   // 右键「内容」菜单的插件扩展
        _quoteExt: [],        // 引用内容钩子（前端侧）
        quote: null,          // 当前待发送的引用 {nick,text}
        onMsgCtx: function (fn) { if (typeof fn === 'function') this._ctxExt.push(fn); },

        /* ---------- 成员搜索（v1.3.24） ---------- */
        /** 搜索按钮的显隐：只对注册用户显示（游客搜索接口一律 403） */
        syncMembersSearchBtn: function () {
            var b = $('owMembersSearch');
            if (!b) return;
            var isUser = this.cfg.actor.kind === 'user';
            // 私聊视图下「所有成员」区块整个被隐藏（renderRoomPanel 会置空），
            // 按钮跟着一起藏，免得出现「点开是空的」
            b.style.display = (isUser && !this.dm) ? '' : 'none';
        },

        /**
         * 打开「搜索成员」弹窗（v1.3.24）。
         *
         * 交互：输入关键词 → 防抖 250ms 后查 → 结果列表（仅注册用户，v1.3.27 起游客不可被搜）。
         * 点某个人 → 打开**该用户的操作菜单**（资料 / 私信 / 举报 / 禁言…）。
         *
         * ⚠️ 为什么不直接把资料卡弹出来：用户要求「点击搜索出来的用户可以查看个人资料、
         * 举报、禁言」，是**一组操作**而非单一动作。所以这里点人 → 弹操作菜单，
         * 菜单项由 onUserAction 钩子汇总（与右键消息头像的 onMsgCtx 同一套思路）。
         */
        openMembersSearch: function () {
            if (this.cfg.actor.kind !== 'user') { toast('游客不支持搜索成员'); return; }
            var self = this;
            // h3 标题必须留着：openModal 的 ✕ 是 float:right，没有标题时它会和
            // 输入框挤在同一行（v1.3.26 前的弹窗乱象根因）。
            // v1.3.25：结果区给个**最小高度**，否则空态/加载态时弹窗高度会跳
            // （从"一行提示"突然撑到"一屏结果"），看着像闪了一下。
            var h = '<h3>搜索成员</h3>'
                  + '<div class="ow-msr-search-row">'
                  + '<input class="ow-input ow-msr-input" id="owMsrInput" type="text"'
                  + ' placeholder="输入昵称或用户 ID" maxlength="50" autocomplete="off">'
                  + '</div>'
                  + '<div class="ow-msr-results" id="owMsrResults"></div>';
            this.openModal(h, 420);

            var input = $('owMsrInput'), box = $('owMsrResults');
            if (!input || !box) return;
            input.focus();
            box.innerHTML = '<div class="ow-msr-empty">输入关键词开始搜索</div>';

            // 防抖：每敲一个字就查会打满限流桶（10 次 / 3 秒）
            var timer = 0, lastQ = '';
            var run = function () {
                var q = (input.value || '').replace(/^\s+|\s+$/g, '');
                if (q === lastQ) return;
                lastQ = q;
                if (q === '') {
                    box.innerHTML = '<div class="ow-msr-empty">输入关键词开始搜索</div>';
                    return;
                }
                box.innerHTML = '<div class="ow-msr-empty">搜索中…</div>';
                OwApi.post('members_search', { room_id: self.room, q: q }, function (r) {
                    // 请求返回时会话可能已切走，丢弃过期结果
                    if (self.dm || self.room !== roomId) return;
                    if (!r || !r.ok) {
                        box.innerHTML = '<div class="ow-msr-empty">'
                            + esc((r && r.msg) ? r.msg : '搜索失败') + '</div>';
                        return;
                    }
                    self.renderMemberSearch(box, r.data || [], roomId);
                });
            };
            var roomId = self.room;
            input.oninput = function () { if (timer) clearTimeout(timer); timer = setTimeout(run, 250); };
            input.onkeydown = function (e) {
                if (e.keyCode === 13) { e.preventDefault ? e.preventDefault() : (e.returnValue = false); if (timer) clearTimeout(timer); run(); }
            };
        },

        /** 渲染搜索结果；点行 → 打开该用户的操作菜单 */
        renderMemberSearch: function (box, list, roomId) {
            var self = this;
            if (!box) return;
            if (!list.length) {
                box.innerHTML = '<div class="ow-msr-empty">没有找到匹配的用户</div>';
                return;
            }
            var html = '';
            for (var i = 0; i < list.length; i++) {
                var u = list[i];
                var uid = u.uid || 0;
                // v1.3.27：结果只会有注册用户（服务端已剔除游客）
                var meta = u.role === 'admin' ? '管理员'
                    : (u.role === 'vip' ? 'VIP'
                    : (uid ? '用户 ID ' + uid : ''));
                html += '<div class="ow-msr-item" data-uid="' + uid + '"'
                      + ' data-nick="' + esc(u.nickname) + '" data-kind="' + esc(u.kind) + '"'
                      + ' data-role="' + esc(u.role || '') + '">'
                      + '<img class="ow-msr-av" src="' + esc(u.avatar || '') + '" alt="" loading="lazy" decoding="async">'
                      + '<div class="ow-msr-main">'
                      + '<div class="ow-msr-nick">' + esc(u.nickname) + '</div>'
                      + '<div class="ow-msr-meta">' + esc(meta) + '</div>'
                      + '</div>'
                      + (u.in_room ? '<span class="ow-msr-inroom">在群</span>' : '')
                      + '</div>';
            }
            box.innerHTML = html;
            var rows = box.getElementsByClassName('ow-msr-item'), k;
            for (k = 0; k < rows.length; k++) {
                (function (row) {
                    row.onclick = function () {
                        self.closeModal();
                        self.openUserActions(row.getAttribute('data-uid'), row.getAttribute('data-nick'), {
                            kind: row.getAttribute('data-kind'),
                            role: row.getAttribute('data-role')
                        });
                    };
                })(rows[k]);
            }
        },

        /**
         * 「针对某个用户」的操作菜单（v1.3.24）。
         *
         * 为什么要有这层：现有 onMsgCtx 是**按消息**定位人的（右键某条消息的头像），
         * 而成员搜索是**按人**发起的（没有具体消息上下文）。
         * 新增 onUserAction(items, u, env) 钩子，u = {uid, gid, nickname, kind}，
         * env 与 onMsgCtx 同构。禁言 / 举报插件挂这个钩子即可复用，
         * 无需各自再写一份「怎么拿到这个人」的界面逻辑。
         */
        onUserAction: function (fn) { if (typeof fn === 'function') this._userActExt.push(fn); },
        _userActExt: [],

        /** 打开用户操作菜单。uid=0 且 gid>0 表示游客（游客只有「查看资料」会提示不可用）。 */
        openUserActions: function (uid, nickname, extra) {
            var self = this;
            uid = parseInt(uid, 10) || 0;
            extra = extra || {};
            var me = this.cfg.actor;
            // ⚠️ role 必须带上：禁言插件的 canBan 判「房主不能禁言管理员」用的就是它。
            //   漏传会让 canBan 拿到空 role → 房主误判成「可以禁言管理员」，
            //   前端菜单显示出来了，服务端再拒 —— 用户白点一次。
            var u = {
                uid: uid, gid: extra.gid || 0,
                nickname: nickname || '', kind: extra.kind || 'user',
                role: extra.role || ''
            };
            var items = [];

            if (uid > 0) {
                items.push({ t: '查看个人资料', run: function () { self.userCard(uid, nickname); } });
                // 私信只对「对方也是注册用户」成立（跨身份私聊 v1.1.2 起已下线）
                if (me.kind === 'user' && uid !== (me.id || 0)) {
                    items.push({ t: '发私信', run: function () { self.openDmWith({ uid: uid, nickname: nickname }); } });
                }
            } else {
                items.push({ t: '查看个人资料', run: function () { toast('游客没有个人资料'); } });
            }
            // 禁言 / 举报等由插件经 onUserAction 注入（权限判定在各自的服务端）
            for (var i = 0; i < this._userActExt.length; i++) {
                try { this._userActExt[i](items, u, { roomId: this.room, actor: me }); } catch (e) {}
            }
            if (!items.length) return;

            // 复用消息右键菜单（同款 DOM 与事件绑定，不另造一套）
            // ⚠️ 条目必须是 <a>：bindEvents 里是向上找最近的 <a> 再按 data-i 取项，
            //   用 <div> 会点了没反应（那是为了兼容锚点语义写的向上查找）。
            // ⚠️ 还要先把旧的 transform 清掉：右键菜单定位靠 inline left/top，
            //   若上一次留下 translateX(-50%)，这次用 left 百分比就会被平移两次。
            this._ctxItems = items;
            var menu = $('owCtxMenu');
            menu._from = 'member-search';
            menu.innerHTML = ctxItemsHtml(items);
            // 没有触发源（不是右键），按视口水平居中、固定在偏上位置
            var w2 = w.innerWidth || 900, mw = menu.offsetWidth || 150;
            menu.style.left = Math.max(8, Math.round(w2 / 2 - mw / 2)) + 'px';
            menu.style.top = '150px';
            menu.style.display = 'block';
            // 点外部关闭由 bindEvents 里的 document.onclick 统一处理（已存在）
        },
        /** 插件扩展点：构造引用内容时可改写（服务端另有 message.quote 钩子做最终校验） */
        onQuote: function (fn) { if (typeof fn === 'function') this._quoteExt.push(fn); },
        showCtxMenu: function (x, y, m) { this.showUserMenu(x, y, m); },
        hideCtxMenu: function () {
            var menu = $('owCtxMenu');
            if (menu) { menu.style.display = 'none'; menu._from = ''; }
        },
        /* ---------- 消息时间显隐（v1.1.0）----------
           悬停消息气泡满 2 秒才显示时间，移开立即隐藏。
           计时状态集中在这里，切换会话 / 消息区重渲染前统一 hideMsgTime()，
           避免「已经移开鼠标但定时器还在跑」导致时间凭空出现。 */
        _timeMsg: null,
        _timeTimer: null,
        hideMsgTime: function () {
            if (this._timeTimer) { clearTimeout(this._timeTimer); this._timeTimer = null; }
            if (this._timeMsg && this._timeMsg.className) {
                this._timeMsg.className = this._timeMsg.className.replace(' ow-time-show', '');
            }
            this._timeMsg = null;
        },
        /**
         * 右键「头像」的用户菜单：对该发言人的操作（@ / 私信 / 收藏 / 撤回 / 禁言…）。
         * 插件通过 OwChat.onMsgCtx 追加的项也进这里（都是针对「人」的能力）。
         * @deprecated showCtxMenu 保留为别名，兼容既有插件 / 调用
         */
        showUserMenu: function (x, y, m) {
            var self = this, admin = this.cfg.actor.role === 'admin', items = [];
            if (!m.recalled && !m.deleted) {
                // 行首的 @ 图标已经表示「提及」，标签里不要再写一个 @（v1.3.90：两个 @ 叠着很怪）
                items.push({ t: m.nickname, icon: 'at', run: function () { self.mention(m.nickname); } });
                // v1.1.2：**只有对方也是注册用户才给「私信」**（m.uid 为空即游客）。
                // 原先只判自己是不是注册用户，于是「注册用户 → 游客」的入口一直挂着，
                // 而服务端已彻底关闭跨身份私聊 —— 前端不收口就会变成点进去必然报错的死按钮。
                if (!m.mine && this.cfg.actor.kind === 'user' && m.uid)
                    items.push({ t: '私信', run: function () { self.openDmWith(m); } });
                // 收藏贴纸已移到内容菜单（showContentMenu）：它是对「图片」的操作，不是对「人」的操作
            }
            // 插件扩展（v1.0.54）：如禁言插件按「管理员 / 房主」身份追加菜单项；
            // IP 归属地已移出核心，插件可在此注册（服务端走 ip_loc + ip.location 钩子）
            for (var i = 0; i < this._ctxExt.length; i++) {
                try { this._ctxExt[i](items, m, { roomId: this.room, actor: this.cfg.actor }); } catch (e) {}
            }
            if (!items.length) return;

            this._ctxItems = items;
            var menu = $('owCtxMenu');
            menu._from = 'msg';
            menu.innerHTML = ctxItemsHtml(items);
            menu.style.display = 'block';
            // 视口边界：菜单放不下时往回挪
            var vw = w.innerWidth || document.documentElement.clientWidth;
            var vh = w.innerHeight || document.documentElement.clientHeight;
            var mw = menu.offsetWidth || 140, mh = menu.offsetHeight || items.length * 32;
            menu.style.left = Math.max(4, x + mw > vw ? x - mw : x) + 'px';
            menu.style.top = Math.max(4, y + mh > vh ? y - mh : y) + 'px';
        },

        /* ---------- 发送 ---------- */
        /**
         * 发送消息。v1.1.0：私聊视图下自动改写路由——
         * room_id=0（虚拟私聊空间）+ 对方标识（to_user_id）。
         *
         * v1.2.1 修复：原判断是 `if (this.dm && !opt.type)`，即「没显式指定类型才走私聊」。
         * 而图片 / 文件 / 贴纸发送时 opt.type 分别是 'image' / 'file'，
         * 于是这些消息绕过了私聊改写、带着上一个群的 room_id 走群聊通道，
         * 结果私聊里发图/文件必然失败（服务端按群校验直接拒绝）。
         * 私聊身份与消息形态本就是两个正交维度：只要处于私聊视图就一律走私聊路由，
         * type 只描述「发的是什么」，不再决定「发给谁」。
         *
         * 昵称仅作展示快照，不作身份；服务端以数字 ID 判定双方可见性。
         */
        /**
         * 扩展点（v1.3.86）：输入框文字发出去**之前**给插件一次接管机会。
         * 附件插件用它实现图文混排 —— 待发区里有图片/视频时，「发送」意味着
         * 「文字 + 这些媒体合成一条 rich 消息」，而不是先甩一条纯文本。
         * 回调返回 true = 已接管本次发送，核心**立即中止**本次发送。
         * ⚠️ 核心不碰输入框：清不清、什么时候清由接管方决定 —— 附件插件在「还有文件
         * 在上传」时也要返回 true 拦下这次发送，那种情况下用户刚打的字必须原样留着，
         * 核心若在这里统一清空，就是静默吞掉用户输入。
         *
         * ⚠️ 只拦「从输入框发出去的普通文字」这一种：插件自己调用
         * `OwChat.send({type:'file'|'image'|…})` 时不能再次被拦，否则递归。
         */
        onBeforeSend: function (fn) { if (typeof fn === 'function') this._beforeSend.push(fn); },
        _beforeSend: [],
        /** 核心线性图标（v1.3.86 对外暴露）：插件要图标就调这个，
         *  别在自己文件里再抄一份 path —— 附件插件那份 paperclip 就是前车之鉴。 */
        icon: owIco,

        send: function (opt) {
            opt = opt || {};
            var input = $('owInput');
            // v1.2.29：输入区闸门统一在这里拦一道。
            //   'join'   未加入群聊（服务端 send 也有同样校验）
            //   'friend' 停在「联系人」标签 —— 这是**防误发**的关键一环：
            //            中间是空白的，用户可能以为还在跟刚才那个人聊天
            //   'conv'   没选中任何会话
            if (this._inputGate === 'join') { toast('请先加入该群聊后再发言'); return; }
            if (this._inputGate === 'friend') { toast('请先选择一个联系人'); return; }
            if (this._inputGate === 'conv') { toast('请先选择一个会话'); return; }
            if (this._inputGate === 'room') { toast('请先从左侧选择一个群聊'); return; }
            /* 待发区（图片/视频）非空时，「发送」= 文字 + 媒体合成一条 rich 消息。
               必须放在下面「文字为空就直接 return」**之前**：只发图不打字是完全正常的操作。 */
            if (opt.content == null && !opt.type) {
                for (var bsi = 0; bsi < this._beforeSend.length; bsi++) {
                    var taken = false;
                    try { taken = this._beforeSend[bsi](input ? input.value : '') === true; } catch (e) {}
                    if (taken) return;
                }
            }
            var content = opt.content != null ? opt.content : input.value;
            if (!content || !content.replace(/^\s+|\s+$/g, '')) return;
            // P3 防连点（轻量合并）：300ms 内的重复点击/回车只发一条——配合服务端幂等键，
            // 用户手抖连点不会产生重复消息（评审定的最小防抖）。
            var nowMs = new Date().getTime();
            if (this._sendLockAt && nowMs - this._sendLockAt < 300) return;
            this._sendLockAt = nowMs;

            var self = this;
            // P3 幂等键：每条消息一个；重试沿用同一个 —— 服务端按 (身份, client_id) 去重。
            var cid = this.newCid();
            var payload = {
                room_id: this.room,
                type: opt.type || 'text',
                content: content,
                to_user_id: opt.to_user_id || '', to_guest_id: opt.to_guest_id || '',
                to_nickname: opt.to_nickname || '',
                // 引用快照：JSON 字符串，服务端会再次校验截断
                quote: this.quote ? JSON.stringify(this.quote) : '',
                client_id: cid,
            };
            // 私聊视图：文本 / 图片 / 文件等所有形态一律发往对方
            if (this.dm) {
                // v1.1.2：跨身份私聊已下线 —— 理论上不会进入 guest 分支（入口已收口），
                // 这里仍硬拦一道，避免任何残留状态发出必然被服务端拒绝的请求
                if (this.dm.kind !== 'user') { toast('游客暂不支持私聊'); return; }
                payload.room_id = 0;
                payload.to_user_id = this.dm.id;
                payload.to_guest_id = '';
                payload.to_nickname = this.roomName;
            }
            // P3：立即上屏「发送中」气泡（本机信息先渲染，服务端 msg_row 到达时转正替换）——
            // 输入区同时释放，用户可以继续打字（评审定的轻量非阻塞交互；失败留「重试」入口）。
            this.addPending(cid, payload);
            if (!opt.type || opt.type === 'text') input.value = '';
            this.clearQuote();   // 发送即清引用条（内容已装进 payload；失败重试用的也是它）
            this.autoGrow();
            this.afterSendClearJump();
            // P3 三分支：WS 可用 → send 帧（少一次客户端 HTTPS 往返，且每身份串行保序）；
            // 否则回落 HTTP（现状路径原样保留，两条路共用同一套结果状态机 onSendResult）。
            if (this.canWsSend(payload.room_id)) {
                this.rt.sendSend(cid, payload);
                this._armSendTimeout(cid);
            } else {
                OwApi.post('send', payload, function (r) { self.onSendResult(cid, r); });
            }
        },

        // ---------- P3（发送迁移）：发送状态机 ----------

        /** 客户端消息唯一号（幂等键 + 结果配对；重试沿用同一个） */
        newCid: function () {
            return 'c' + new Date().getTime().toString(36) + Math.random().toString(36).slice(2, 10);
        },

        /** WS 发送三分支判定：rt.on && 开关 && 非密码房 && 白名单内（DM 只受前两项约束） */
        canWsSend: function (roomId) {
            if (!this.rt || !this.rt.on || !this.cfg || !this.cfg.ws_send_enabled) return false;
            if (this.dm) return true;                       // 私聊无密码房/白名单概念
            roomId = parseInt(roomId, 10) || 0;
            if (!roomId) return false;
            var rooms = this.cfg.rooms || [], i;
            for (i = 0; i < rooms.length; i++) {
                // 密码房发送回落 HTTP：通行授权记在会话里，内部通道（无会话）拿不到，
                // 走了 WS 必然被「无权进入该群聊」拒——P3 方案定稿口径。
                if ((parseInt(rooms[i].id, 10) || 0) === roomId && rooms[i].type === 'password') return false;
            }
            var wl = (this.cfg.ws_send_rooms || '').replace(/\s+/g, '');
            if (wl !== '') {
                var hit = false, parts = wl.split(',');
                for (i = 0; i < parts.length; i++) if ((parseInt(parts[i], 10) || 0) === roomId) hit = true;
                if (!hit) return false;                     // 白名单灰度：未列入的房间回落 HTTP
            }
            return true;
        },

        /** 本地渲染「发送中」气泡（伪行以本机信息拼装；转正时整个替换为服务端 msg_row 渲染） */
        addPending: function (cid, payload) {
            this._pending = this._pending || {};
            if (this._pending[cid]) return;
            var me = (this.cfg && this.cfg.me) || {};
            var d = new Date();
            var pad = function (n) { return (n < 10 ? '0' : '') + n; };
            var row = {
                id: 0, room: payload.room_id || 0, uid: me.id || null, gid: null,
                nickname: me.nickname || '', role: me.role || 'guest', title: me.title || '',
                avatar: me.avatar || '', type: payload.type || 'text', content: payload.content,
                quote: null, recalled: 0, deleted: 0, dm: this.dm ? 1 : 0, mine: true,
                ts: Math.floor(d.getTime() / 1000),
                time: pad(d.getHours()) + ':' + pad(d.getMinutes()),
                date: pad(d.getMonth() + 1) + '-' + pad(d.getDate()),
            };
            if (payload.quote) { try { row.quote = JSON.parse(payload.quote); } catch (e) {} }
            var b = this.buildMessage(row);
            var div = document.createElement('div');
            div.className = b.cls + ' ow-msg-pending';
            div.id = 'owTmp' + cid;
            div.innerHTML = b.html;
            var box = $('owMessages');
            if (box) box.appendChild(div);
            this.scrollBottom();
            this._pending[cid] = { row: row, el: div, payload: payload, timer: 0 };
        },

        /** 移除某 cid 的临时气泡（转正 / 清场用） */
        clearPending: function (cid) {
            if (!cid || !this._pending || !this._pending[cid]) return false;
            var p = this._pending[cid];
            if (p.timer) clearTimeout(p.timer);
            if (p.el && p.el.parentNode) p.el.parentNode.removeChild(p.el);
            delete this._pending[cid];
            return true;
        },

        /** 标记某 cid 发送失败（气泡转失败态 + 重试入口；daemon 超时帧与前端兜底定时器共用） */
        markPendingFailed: function (cid, msg) {
            var p = this._pending && this._pending[cid];
            if (!p) return;
            if (p.timer) { clearTimeout(p.timer); p.timer = 0; }
            if (!p.el || !p.el.parentNode) { delete this._pending[cid]; return; }   // 已切视图：无 DOM 可标（消息由推送/历史补齐）
            if (p.el.className.indexOf('ow-msg-failed') < 0) p.el.className += ' ow-msg-failed';
            if (!p.el.querySelector('.ow-msg-retry')) {
                var bar = document.createElement('div');
                bar.className = 'ow-msg-retry';
                bar.innerHTML = esc(msg || '发送失败') + ' · <a href="javascript:;" onclick="OwChat.retrySend('
                    + jsAttr(cid) + ')">重试</a>';
                p.el.appendChild(bar);
            }
        },

        /** 重试（沿用同一 cid——服务端按 (身份, cid) 幂等，重试不会产生重复消息） */
        retrySend: function (cid) {
            var p = this._pending && this._pending[cid];
            if (!p || !p.el) return;
            p.el.className = p.el.className.replace(/\s*ow-msg-failed/, '');
            var bar = p.el.querySelector('.ow-msg-retry');
            if (bar && bar.parentNode) bar.parentNode.removeChild(bar);
            var payload = p.payload;
            if (this.canWsSend(payload.room_id)) {
                this.rt.sendSend(cid, payload);
                this._armSendTimeout(cid);
            } else {
                var self = this;
                OwApi.post('send', payload, function (r) { self.onSendResult(cid, r); });
            }
        },

        /** WS 发送的超时兜底（daemon 会按 ws_send_timeout 回超时帧；10s 兜的是断线等极端情况） */
        _armSendTimeout: function (cid) {
            var self = this;
            var p = this._pending && this._pending[cid];
            if (!p) return;
            if (p.timer) clearTimeout(p.timer);
            p.timer = setTimeout(function () {
                var pp = self._pending && self._pending[cid];
                if (pp && pp.timer) { pp.timer = 0; self.markPendingFailed(cid, '发送超时，请重试'); }
            }, 10000);
        },

        /**
         * 发送结果统一入口（WS sent 帧与 HTTP 回调共用 —— 收尾口径只有一份）。
         * 成功：临时气泡撤掉，以服务端 msg_row 渲染（视图未变才就地插；切走则由推送/历史补齐，
         *       addMessage 会按 cid 清理残留临时气泡）。失败：气泡转失败态 + 重试入口 + toast。
         */
        onSendResult: function (cid, r) {
            var p = this._pending && this._pending[cid];
            if (r && r.ok) {
                this.clearPending(cid);
                var row = r.msg_row;
                if (row) {
                    var viewMatch = row.dm
                        ? (!!this.dm && this.dm.peer === ('user:' + row.to_uid))
                        : ((parseInt(row.room, 10) || 0) === (this.room || 0) && !this.dm && !this.noticeMode);
                    if (viewMatch) {
                        this.addMessage(row);
                        if (row.id > this.since) this.since = row.id;
                        this.scrollBottom();
                        this.markRead(this.since);
                    }
                }
                this.loadConversations();   // 刷新会话排序（自己发的排最前）
                return;
            }
            if (p) this.markPendingFailed(cid, (r && r.msg) || '发送失败，请重试');
            if (r && r.msg) toast(r.msg);
        },

        /**
         * 上传文件附件，并作为一条 file 消息发送。
         * v1.2.1：私聊可用（路由由 send() 统一处理，此处无需分支）。
         */
        /**
         * 撤回消息 = 真正的删除，**全局生效**（所有人都不再看到，不可恢复）。
         * v1.2.4 起撤回是物理删除行，所以成功后直接把气泡从 DOM 移除，
         * 不再走 markRecalled（那是软删除时代的「留个已撤回占位」）。
         */
        recall: function (id) {
            var self = this;
            var text = '确定撤回这条消息吗？撤回后所有群成员都不再显示，且不可恢复。'
                + '（如只想自己不看，请用「删除」）';
            this.confirmModal(text, function () {
                OwApi.secure('recall', { id: id }, function (r) {
                    if (!r.ok) { toast(r.msg); return; }
                    var el = $('owMsg' + id);
                    if (el && el.parentNode) el.parentNode.removeChild(el);
                    delete self.msgCache[id];
                    toast(r.msg || '已撤回');
                    // 全局生效 → 会话摘要也会变，重拉让侧栏同步
                    self.loadConversations();
                });
            });
        },

        mention: function (nick) {
            var input = $('owInput');
            input.value += '@' + nick + ' ';
            input.focus();
            OwChat.autoGrow();
        },

        /**
         * 从一条消息进入与该作者的私聊（v1.1.0）。
         * 私聊不再用「弹窗写一条」的一次性交互，而是进入完整会话页——
         * 历史可翻、双方可继续对话，与群聊共用同一套消息区与输入栏。
         * 对象标识用 user:<id>（v1.1.2 起仅注册用户，游客不再提供私聊入口）。
         */
        openDmWith: function (m) {
            if (!m || !m.uid) { toast('游客暂不支持私聊'); return; }
            this.openDm('user:' + m.uid, m.nickname);
        },
        /** 兼容旧调用点（插件可能仍调 pm）；v1.1.0 起统一进入私聊会话页 */
        pm: function (nick, uid, gid) {
            if (uid) return this.openDm('user:' + uid, nick);
            if (gid) { toast('游客暂不支持私聊'); return; }   // v1.1.2：跨身份私聊已下线
            toast('无法确定私聊对象');
        },

        collect: function (url) {
            OwApi.post('sticker_add', { url: url }, function (r) { toast(r.msg); });
        },

        viewImg: function (src) {
            $('owImgViewerImg').src = src;
            $('owImgViewer').style.display = '-webkit-flex';
            $('owImgViewer').style.display = 'flex';
        },

        /** 被拦链接（v1.3.104）：只弹窗告知，不跳转。文案由插件经 OW_LINK_GUARD 提供。
         *  v1.3.106 起窗口下方还能挂插件自己的操作区（申请恢复访问 / 规则说明）：
         *  核心不知道装没装那个插件，所以只认一个可选全局函数，返回 HTML 原样拼进去。 */
        blockedLink: function (a) {
            var msg = a.getAttribute('data-warn') || '该链接已停止跳转。';
            var extra = '';
            if (typeof w.OW_LINK_BLOCKED_EXTRA === 'function') {
                try { extra = String(w.OW_LINK_BLOCKED_EXTRA(a) || ''); } catch (e) { extra = ''; }
            }
            OwChat.openModal('<h3>链接提示</h3><p class="ow-modal-desc">' + esc(msg) + '</p>' + extra);
            return false;
        },

        /* ---------- 用户资料卡 ---------- */
        /**
         * 打开用户资料卡。
         *
         * v1.1.9 重做布局：**头像左上 + 昵称/身份在右**（原为居中大图 + 下方文字）。
         * 与个人设置**共用同一套头像轮子**（cropForTarget → avatarCropSave
         * → avatarUpload），并统一头像尺寸：
         *  - 尺寸：资料卡与设置页都用 'lg'（64px）。v1.1.8 曾统一到 'md'(32px)，
         *    但 32px 在 380px 宽的弹窗里视觉权重太轻，看着仍偏小，故再放大一档。
         *    尺寸由 CSS 档位锁死（.ow-avatar + overflow:hidden + img 的 max-*），
         *    **与原图实际像素无关**，不会被大图撑破。
         *  - 自己的卡片：头像可点直接换头像（标题提示 + hover 反馈），
         *    与设置页的点击上传走同一条链；上传后两处预览同时回填。
         *  - v1.2.23：**不再有底部「关闭」按钮**（右上角 ✕ 已够）；
         *    自己的卡片因此没有按钮 → 不输出 actions 容器。
         * 用户名已取消：资料卡以用户 ID 作为唯一标识，昵称可重名只作展示。
         */
        userCard: function (uid, nick) {
            if (!uid) { this.pmHint(nick); return; }
            var self = this;
            OwApi.post('user_card', { id: uid }, function (r) {
                if (!r.ok) { toast(r.msg); return; }
                var u = r.data;
                var meId = (self.cfg.me && self.cfg.me.id) || 0;
                var isMe = self.cfg.actor.kind === 'user' && meId > 0 && meId === u.id;
                // v1.1.0：资料卡加「发私信」入口，与头像右键菜单走同一条私聊路径
                var canPm = self.cfg.actor.kind === 'user' && !isMe && self.cfg.actor.id !== u.id;
                // 自己的卡片：点头像走**与设置页同一个**头像设置弹窗（上传 / 浏览插图）。
                // 原先这里直接点一个隐藏的 file input → 选完就裁剪上传，跳过了那层弹窗，
                // 两条入口的交互不一致（站方实测反馈）。
                var avHtml = avatarHtml(u.avatar, u.nickname, 'lg', u.role);
                if (isMe) {
                    avHtml = '<span class="ow-set-avatar-btn" id="owCardAvatarPreview" title="点击设置头像"'
                        + ' onclick="OwChat.openAvatarDialog(\'me\')">' + avHtml + '</span>';
                }
                var regDate = u.created_at ? new Date(u.created_at * 1000).toLocaleDateString() : '-';
                var badges = roleTag(u.role, u.title, u.id, true);   // 资料卡=管理场景，显示真实身份
                // v1.2.23：底部不再放「关闭」—— openModal 自带右上角 ✕（见 openModal），
                // 两个关闭入口纯冗余。
                // ⚠️ 连带影响：自己的卡片 canPm=false，删掉「关闭」后**一个按钮都不剩**，
                // 此时整个 .ow-modal-actions 都不输出（否则卡片底部留一道空边框）。
                // 自己的卡片就只能靠 ✕ / 点遮罩 / Esc 关闭 —— 这是有意的。
                // v1.2.40 按钮改造：
                //   ① **取消「删除好友」**（用户要求）。删除入口已迁到「私聊右侧栏」，
                //      资料卡只负责「加」—— 避免在同一处既加又删、误点。
                //      已是好友时不再显示任何好友按钮（不是禁用，是不出现）。
                //   ② **自己的资料卡给「编辑资料」**（之前一个按钮都不剩，只能 ✕ 关闭，
                //      想改昵称头像还得先去侧栏底部资料区绕一圈）。
                //   ③ 「发私信」维持原样：对方才能私聊；自己的卡不显示。
                var acts = '';
                if (canPm) {
                    if (!u.is_friend) {
                        // v1.3.71：私聊必须先成为好友（服务端 send 里也拦）。
                        // 非好友时**不给「发私信」按钮**：点进去也只会在发送那一刻被打回，
                        // 白打字的体验比少一个按钮差得多 —— 直接把入口换成「加好友」。
                        acts += '<button class="ow-btn ow-btn-primary" onclick="OwChat.addFriend(' + (u.id) + ')">加好友</button>';
                    } else {
                        acts += '<button class="ow-btn ow-btn-primary" onclick="OwChat.closeModal();OwChat.openDm(' + jsAttr('user:' + (u.id)) + ',' + jsAttr(u.nickname) + ')">发私信</button>';
                    }
                } else {
                    acts += '<button class="ow-btn ow-btn-primary" onclick="OwChat.closeModal();OwChat.openSettings()">编辑资料</button>';
                }
                OwChat.openModal(
                    '<h3>用户资料</h3>'
                    + '<div class="ow-card-head">'
                    + avHtml
                    + '<div class="ow-card-id">'
                    // v1.2.23：昵称与身份标签**同一行**（标签在昵称右侧）；
                    // 昵称下方显示「ID xxxxx」—— 原「用户 ID」在下方 meta 行里，已上移，不再重复。
                    // ⚠️ badges 为空时不输出该 span：空 flex item 仍会吃掉一个 gap。
                    + '<div class="ow-card-name-row">'
                    + '<span class="ow-card-name">' + esc(u.nickname) + '</span>'
                    + (badges ? '<span class="ow-card-badges ow-card-badges-inline">' + badges + '</span>' : '')
                    + '</div>'
                    + '<div class="ow-card-sub">ID ' + esc(fmtUid(u.id)) + '</div>'
                    + '</div></div>'
                    // v1.2.42：扩展点输出**前置** —— 等级等插件行固定在 meta 最上面，
                    // 排在「注册」之前，且不随插件注册顺序漂移。
                    // v1.3.127：原「积分」那一行按站方要求整块删除（不是改成条件显示）。
                    + '<div class="ow-card-meta">'
                    + self.cardMetaTopHtml(u)
                    + '<div class="ow-card-meta-row"><span class="ow-card-meta-k">注册</span>'
                    + '<span class="ow-card-meta-v">' + esc(regDate) + '</span></div>'
                    + '</div>'
                    // v1.2.23：插件内容锚点（个性签名等）。
                    // 由来：signature 插件原先靠 `modal.querySelector('p')` 定位，
                    // v1.1.9 资料卡重做布局后 <p> 被 .ow-card-meta 取代 → 拿到 null
                    // → 静默 return，签名从此不再显示。给个稳定 id 让插件不再猜结构。
                    + '<div id="owCardExtras"></div>'
                    + (acts ? '<div class="ow-modal-actions">' + acts + '</div>' : '')
                );
                // v1.5.17：资料卡渲染完成 —— 让插件装饰卡片（如顶部横幅背景图）。
                // onCardMetaTop / #owCardExtras 都动不了头部结构，故在 openModal 之后
                // 把渲染结果与卡片数据交给插件做增量 DOM（详见 onCardRendered 注释）。
                self.fireCardRendered($('owModal'), u);
            });
        },

        pmHint: function (nick) { toast('游客用户无法查看资料卡'); },

        /* ---------- 成员列表 ---------- */
        /**
         * 渲染成员列表。
         *
         * v1.1.1：在线状态（绿点/灰点）**仅超级管理员与群主可见**，
         * 口径由服务端 poll 返回的 online_status 决定，前端不自行判身份——
         * 否则两处判定漂移，就会重演「服务端允许、前台没有按钮」的契约不一致。
         * 成员名字对所有人可见（含游客）。
         */
        renderOnline: function (list, canStatus) {
            var box = $('owOnlineList'), cnt = $('owOnlineCount');
            if (cnt) cnt.innerHTML = list.length;
            if (!box) return;
            this._memSig = null;   // v1.5.29：这条老口径绕过了 renderMembers 的签名缓存，作废它
            var html = '', i;
            for (i = 0; i < list.length; i++) {
                var o = list[i];
                html += '<li class="ow-online-item">'
                      + (canStatus ? '<span class="ow-online-dot"></span>' : '')
                      + avatarHtml(o.avatar, o.nickname, true, o.role)
                      + '<span class="ow-online-name" onclick="OwChat.userCard(' + (o.uid || 0) + ',' + jsAttr(o.nickname) + ')">' + esc(o.nickname) + '</span>'
                      + roleTag(o.role, '', o.uid) + '</li>';
            }
            box.innerHTML = html;
        },

        /* ---------- 所有成员（v1.2.27 改口径；v1.3.27 起不含游客） ----------
           以前这里渲染的是 online 表（45 秒心跳 = 「谁在线」），
           需求要的是「群里都有谁」—— 改成：
             · 注册用户：room_members **全量**（含离线，群主自动补位）
           v1.3.27：在场游客不再进「所有成员」—— 游客没有持久身份、
           本就不是成员（服务端从不写 room_members，见 joinRoom），显示出来
           反而让列表每次心跳后都变样。服务端 guests 字段仍下发（插件可用），前端忽略。
           在线点的显隐仍由服务端 canSeeOnlineStatus 决定，前端不自行判身份。 */
        renderMembers: function (members, canStatus) {
            var box = $('owOnlineList'), cnt = $('owOnlineCount');
            var ms = members || [];
            if (cnt) cnt.innerHTML = ms.length;
            if (!box) return;
            /* v1.5.29：名单没变化就不重排 DOM。
               面板打开期间每轮 poll 都会把名单带回来（want_members=1），无条件
               innerHTML 赋值会把列表滚动位置重置回顶部 —— 用户滚到一半找人，
               二十秒后被拽回去。签名只取影响渲染结果的字段。 */
            var sig = (canStatus ? '1' : '0') + '|' + ms.length, s;
            for (s = 0; s < ms.length; s++) {
                sig += '|' + (ms[s].uid || 0) + ',' + (ms[s].nickname || '') + ',' + (ms[s].role || '')
                     + ',' + (ms[s].online ? 1 : 0) + ',' + (ms[s].avatar || '');
            }
            if (sig === this._memSig) return;
            this._memSig = sig;
            var html = '', i;
            for (i = 0; i < ms.length; i++) html += this._memberRow(ms[i], canStatus);
            box.innerHTML = html || '<li class="ow-online-empty">还没有成员</li>';
        },

        /** 单行成员（注册用户）。游客已不在成员列表渲染（v1.3.27），行结构只剩注册用户一种 */
        _memberRow: function (o, canStatus) {
            var dot = canStatus
                ? '<span class="ow-online-dot' + (o.online ? '' : ' is-off') + '"></span>'
                : '';
            var nameAttr = ' onclick="OwChat.userCard(' + (o.uid || 0) + ',' + jsAttr(o.nickname) + ')"';
            return '<li class="ow-online-item">'
                + dot
                + avatarHtml(o.avatar, o.nickname, true, o.role)
                + '<span class="ow-online-name"' + nameAttr + '>' + esc(o.nickname) + '</span>'
                + roleTag(o.role, '', o.uid) + '</li>';
        },

        /* ---------- 右侧栏「所有成员」按需加载（v1.5.29） ----------
           为什么改：右侧栏默认是收起的（initChat 里 setPanel(false)），但成员名单
           此前一直在白算 —— poll 每轮收尾都跑 allMembers（memberList join +
           online 集合 + 游客 GROUP BY + usort + 逐个 avatarUrlFor），切群也立刻拉
           一次完整 room_members（两份全量名单）。多数会话里没人打开过这个面板。

           现在：
             · 面板收起 → 不请求（收到 wake 推送也不刷）；
             · 面板打开 → loadMembers(true) 立即拉；打开期间 poll 带 want_members=1
               （打开着才要实时性，收起后立刻回到零查询）；
             · 发言闸门（未加入 → 显示「加入群聊」）不再搭车完整名单，改走
               room_members?lite=1 —— 只要 is_member / is_public 这几个字段。 */

        /** 右侧栏当前是否真的可见：窄屏（≤960）看 open 类，宽屏看有没有 hidden */
        membersVisible: function () {
            var o = $('owOnline');
            if (!o) return false;
            var cls = ' ' + String(o.className || '').replace(/\s+/g, ' ') + ' ';
            var narrow = (document.documentElement.clientWidth || w.innerWidth || 1024) <= 960;
            return narrow ? cls.indexOf(' open ') >= 0 : cls.indexOf(' hidden ') < 0;
        },

        /**
         * 拉「所有成员」名单。
         * @param {boolean} force 用户刚打开面板 —— 绕过可见性判定，并且顺带校准闸门。
         *   打开面板一律走 force（不做新鲜度判断）：成员可能刚变过，打开就该看到最新的。
         * 面板收起时直接返回；切群 / 首屏 / WS wake 都走这里。
         */
        loadMembers: function (force) {
            var self = this, id = this.room;
            if (!id) return;
            if (!force && !this.membersVisible()) return;
            OwApi.post('room_members', { room_id: id }, function (r) {
                if (!r.ok || self.room !== id) return;
                self.renderMembers(r.members, r.online_status);
                self.applyJoinGate(r.is_member, r.is_public);
            });
        },

        /**
         * 只校准输入区闸门（v1.5.29）：room_members?lite=1。
         * 切群时执行 —— 被移出成员 / URL 直达未加入的群，靠它把「加入群聊」按钮显示出来。
         * 老服务端没有 lite 参数 → 返回完整名单，此时顺手渲染一次，等同旧行为。
         */
        syncJoinGate: function () {
            var self = this, id = this.room;
            if (!id || this.dm) return;
            OwApi.post('room_members', { room_id: id, lite: 1 }, function (r) {
                if (!r.ok || self.room !== id) return;
                if (r.members) self.renderMembers(r.members, r.online_status);
                self.applyJoinGate(r.is_member, r.is_public);
            });
        },

        /**
         * 切到「联系人」标签时**关掉**中间正在显示的会话（v1.2.29）。
         *
         * 要解决的问题：切标签只换了左侧栏，中间还留着刚才那个群聊/私聊的聊天记录，
         * 用户很容易「以为还在跟刚才那个人/群聊天」，直接在输入框打字发出去 ——
         * 发错对象。要让「切换标签」等价于「离开当前会话」。
         *
         * 四件事缺一不可：
         *  ① 停两路轮询（群聊长轮询 + 私聊长轮询），否则在途回调醒来又往
         *     已清空的 #owMessages 里塞消息（还会把 since 推走）；
         *  ② 清空消息区 + 取消列表高亮（视觉上彻底「关掉」）；
         *  ③ 清掉 this.room / this.dm（**不留后路**：否则输入框一解禁就能往刚才那个群发消息）；
         *  ④ 输入区落闸（禁发言 + 提示先选联系人）。
         */
        blankChatForFriends: function () {
            this.pollGen = (this.pollGen || 0) + 1;      // 作废群聊长轮询世代
            this.dmGen = (this.dmGen || 0) + 1;          // 作废私聊长轮询世代
            this._roomPollRunning = false;
            this.dm = null;
            this.room = 0;
            this.since = 0;
            this.historyDone = true;                     // 已无可加载历史，禁掉上滚懒加载
            this.loadingHistory = false;
            var box = $('owMessages');
            if (box) box.innerHTML = '';
            ChatList.activate('owRoomList', '');         // 取消任何一行的高亮
            this.renderRoomPanel();                      // 右侧栏回到「请先选择」空态
            this.applyInputGate('friend');
            this._fireViewChange();                      // v1.3.133：同上，中间已经没有房间了
        },

        /* =====================================================================
         * 系统通知（v1.3.52）
         * ---------------------------------------------------------------------
         * 与「消息 / 联系人」并列的第三个核心标签：侧栏一行「系统通知」会话
         * （复用 ChatList 与 .ow-room-list 样式，与消息列表同源），主区是**只读**消息流。
         * 通知按 user_id 存在 notices 表（core/notice.php），游客没有账号，
         * 所以 index.php 根本不给他这个 rail 按钮。
         * =================================================================== */
        noticeMode: false,
        notices: [],

        /** 侧栏那一行：交给 ChatList 渲染，保证与消息列表逐像素一致 */
        renderNoticeRow: function () {
            var box = $('owNoticeList');
            if (!box) return;
            /* v1.3.162：服务端返回的流里现在混着插件条目（notice.items），而这一行说的是
               「系统通知」自己 —— 取 [0] 不再等于「最新一条系统通知」，副标题会露出别的插件的事。
               所以只认没有 key 的行（系统通知不带 key，插件条目必带，见 Notice::pluginItems）。 */
            var last = null, li2;
            for (li2 = 0; li2 < (this.notices || []).length; li2++) {
                if (!this.notices[li2].key) { last = this.notices[li2]; break; }
            }
            var unread = this.noticeMode ? 0 : ((this.cfg.notice_unread | 0) || 0);
            var rows = [{
                conv: 'dm', peer: 'notice', name: '系统通知',
                // v1.3.52：固定头像「小可爱第36号」，由服务端算好下发（Notice::avatarUrl）
                avatar: this.cfg.notice_avatar || '',
                last_at: last ? (last.created_at | 0) : 0,
                last_text: last ? owNoticeText(last, this.noticeTexts) : '暂无系统通知',
                unread: unread
            }];
            /* v1.3.160：插件注册的「通知联系人」拼在系统通知**之后**（同一张表、同一套行渲染，
               所以看着就是通知区里多出来的联系人）。昵称与头像的默认值服务端已补好
               —— 没给就用插件名 + 按插件 id 派生的 open-peeps 头像（Notice::contacts）。 */
            var cs = this.cfg.notice_contacts || [], i;
            for (i = 0; i < cs.length; i++) {
                rows.push({
                    conv: 'dm', peer: cs[i].key, name: cs[i].name,
                    avatar: cs[i].avatar || '',
                    // v1.3.162：插件给了 at 就显示「最新一条」的时间（与系统通知行同口径）；
                    // 没给仍是 0 → ChatList.time(0) 渲染空串，行为与 v1.3.160 一致。
                    last_at: cs[i].at | 0,
                    last_text: cs[i].last_text || '',
                    unread: cs[i].unread | 0
                });
            }
            ChatList.render(rows, {
                container: 'owNoticeList',
                activeKey: this.noticeMode ? 'dm:notice' : '',
                onClick: function (li) {
                    var peer = li.getAttribute('data-dm');
                    if (peer === 'notice') { OwChat.openNotices(); return; }
                    OwChat.openNoticeContact(peer);   // 插件的联系人：交给它自己的处理器
                }
            });
        },

        /**
         * 注册「通知联系人」的点击处理器（v1.3.160，插件扩展点）。
         *
         * 服务端那边先用 `Plugin::on('notice.contacts', function ($add) { $add(['key' => '…']); })`
         * 登记一行，前端再用本方法按同一个 key 接住点击 —— 两边只靠 key 约定，核心不认识插件的内容。
         *
         * @param {string}   key 与服务端登记时一致的 key
         * @param {function} fn  点击时调用，收到行对象 {key, name, avatar, unread, last_text}
         */
        onNoticeContact: function (key, fn) {
            if (key && typeof fn === 'function') this._noticeHooks[key] = fn;
        },
        _noticeHooks: {},
        /** 点了插件的通知联系人：交给插件；没注册处理器就提示一句（免得作者对着没反应的入口查半天） */
        openNoticeContact: function (key) {
            var fn = this._noticeHooks[key];
            if (typeof fn !== 'function') { toast('插件「' + key + '」没有注册点击处理'); return; }
            var cs = this.cfg.notice_contacts || [], row = null, i;
            for (i = 0; i < cs.length; i++) if (cs[i].key === key) row = cs[i];
            fn(row || { key: key });
        },

        /** 进入通知模式：先停掉群聊/私聊长轮询，再把主区换成通知流 */
        openNotices: function () {
            var self = this;
            if (!this.noticeMode) {
                this._titleBefore = ($('owRoomName') || {}).textContent || '';
                this.noticeMode = true;
            }
            // 世代号 +1 让在飞的轮询自己作废（与 blankChatForFriends 同一手法）
            this.pollGen = (this.pollGen || 0) + 1;
            this.dmGen = (this.dmGen || 0) + 1;
            this._roomPollRunning = false;
            this.dm = null; this.room = 0; this.since = 0;
            this.historyDone = true;          // 通知流没有「上滚加载更早」
            this.loadingHistory = false;
            ChatList.activate('owRoomList', '');
            this.renderNoticeRow();
            this.paintNoticeChrome(true);
            this.applyInputGate('notice');
            // v1.3.133：通知流复用了群聊那套消息区，但它是**独立视图** —— 不通知插件，
            // 上一个群的公告条就会压在系统通知上面（站方截图里那条「公告 测试一下」）。
            this._fireViewChange();
            var box = $('owMessages');
            if (box) box.innerHTML = '<div class="ow-notice-pending">加载中…</div>';
            OwApi.post('notices', {}, function (r) {
                self.notices = (r && r.ok && r.data) ? r.data : [];
                if (r && r.ok && r.texts) self.noticeTexts = r.texts;
                self.renderNoticeStream();
                // 服务端在返回时就整批标已读了，红点必须跟着清零
                self.cfg.notice_unread = 0;
                self.clearNoticeDot();
                self.renderNoticeRow();
            });
        },

        /** 离开通知标签：还回顶栏那几样与房间绑定的控件，并清掉通知态 */
        exitNotices: function () {
            if (!this.noticeMode) return;
            this.noticeMode = false;
            this.paintNoticeChrome(false);
            var n = $('owRoomName');
            if (n && this._titleBefore != null) n.textContent = this._titleBefore;
        },

        /** 顶栏联动：通知态收起「群聊信息」按钮与延迟显示（它们都属于某个房间） */
        paintNoticeChrome: function (on) {
            var p = $('owTogglePanel'); if (p) p.style.display = on ? 'none' : '';
            var l = $('owLatency'); if (l) l.style.display = on ? 'none' : '';
            var n = $('owRoomName');
            if (n && on) n.textContent = '系统通知';
        },

        /** 主区通知流：旧→新（与消息流同向），普通气泡 + 元信息，不用 .system 那套弱化样式 */
        renderNoticeStream: function () {
            var box = $('owMessages');
            if (!box) return;
            /* v1.3.162：插件消息（notice.items，服务端 Notice::pluginItems）与系统通知同流混排。
               「查看」的点击用**一次委托**接住：#owMessages 是服务端静态输出的唯一节点，
               innerHTML 重建不会丢监听（这与好友通知页那类每次重绑的做法不同口径，
               因为这里不止一条流会重建 —— 委托一次，之后任何一次重渲染都在覆盖范围内）。 */
            if (!box._noticeActBound) {
                box._noticeActBound = true;
                box.addEventListener('click', function (e) {
                    var t = e.target || e.srcElement;
                    while (t && t !== box && !(t.className && (' ' + t.className + ' ').indexOf(' ow-notice-act ') >= 0)) t = t.parentNode;
                    if (!t || t === box) return;
                    var key = t.getAttribute('data-key') || '';
                    if (key) OwChat.openNoticeContact(key);   // 与点侧栏那一行同一条路：交给插件自己的处理器
                });
            }
            var rows = (this.notices || []).slice().reverse(), h = '', i, r;
            var av = this.cfg.notice_avatar || '';
            if (!rows.length) {
                box.innerHTML = '<div class="ow-notice-pending">暂无系统通知</div>';
                return;
            }
            for (i = 0; i < rows.length; i++) {
                r = rows[i];
                // 系统通知没有 name/avatar/at，分别回落「系统通知」/ 核心通知头像 / created_at；
                // 插件条目则各用自己那份（默认值服务端已补好），正文走 body（无 kind，由 owNoticeText 兜回）。
                // 头像用 .ow-msg-av（它负责排版），但通知没有资料卡可看，
                // 可点暗示与悬停反馈由 CSS 的 .ow-msg-notice 规则关掉。
                var nick = r.name || '系统通知', rav = r.avatar || av;
                // v1.3.165：插件条目的缩略图（≤3 张，服务端已把非法值拦掉）——点开站内灯箱看原图；
                // 与消息图片共用 .ow-msg-img / OwChat.viewImg（#owImgViewer 是现成的 200 层灯箱）
                var imgs = r.images || [], ih = '', k;
                for (k = 0; k < imgs.length; k++) {
                    ih += '<img class="ow-msg-img" src="' + esc(imgs[k]) + '" loading="lazy" decoding="async" onclick="OwChat.viewImg(this.src)" alt="图片">';
                }
                if (ih) ih = '<div class="ow-notice-imgs">' + ih + '</div>';
                h += '<div class="ow-msg ow-msg-notice">'
                   + (rav ? '<span class="ow-msg-av">' + avatarHtml(rav, nick) + '</span>' : '')
                   + '<div class="ow-msg-body">'
                   + '<div class="ow-msg-meta"><span class="ow-msg-nick ow-notice-nick">' + esc(nick) + '</span>'
                   + '<span class="ow-msg-time">' + esc(ChatList.time((r.at || r.created_at || 0) | 0)) + '</span></div>'
                   + '<span class="ow-msg-content">' + esc(owNoticeText(r, this.noticeTexts)) + '</span>'
                   // 缩略图插在正文与「查看」之间：正文 → 证据 → 入口，阅读顺序与处理动作一致
                   + ih
                   // v1.3.162：可点元素只由数据决定（key 非空才有），链接样式复用正文链接的
                   // .ow-msg-link（v1.3.101：对比度按正文 4.5:1 调过，不为它新写 CSS）。
                   + (r.key ? ' <a href="javascript:;" class="ow-msg-link ow-notice-act" data-key="' + esc(r.key) + '">查看</a>' : '')
                   + '</div></div>';
            }
            box.innerHTML = h;
            box.scrollTop = box.scrollHeight;
        },

        /** rail 小红点：进入通知流后熄灭（服务端已把整批标为已读），按钮的未读数一并从 aria-label 去掉 */
        clearNoticeDot: function () {
            var b = $('owNoticeDot');
            if (b) b.style.display = 'none';
            var btn = document.querySelector('.ow-rail-btn[data-tab="notice"]');
            if (btn) btn.setAttribute('aria-label', '系统通知');
        },

        /**
         * 输入区闸门（v1.2.29 统一入口）。此前只有 applyJoinGate 一条路，
         * 现在要覆盖三种「不能发」的原因，抽成一处，避免多路径互相覆盖。
         *   ''        正常
         *   'join'    未加入该群聊 → 显示加入按钮
         *   'friend'  停在「联系人」标签 → 先点一个联系人
         *   'conv'    停在「消息」标签但没选中任何会话
         */
        applyInputGate: function (kind) {
            var box = $('owJoinGate');
            var input = $('owInput');
            this._inputGate = kind || '';
            if (!input) return;
            // 工具栏一并禁用：选图/选文件会直接走 send 发出消息，
            // 只禁输入框的话，用户还能从工具栏把消息发到「已经关掉的会话」里。
            var tbIds = ['owBtnEmoji', 'owBtnImage', 'owBtnFile'], i, tb;
            for (i = 0; i < tbIds.length; i++) {
                tb = $(tbIds[i]);
                if (tb) tb.disabled = !!kind;
            }
            // 顶栏「可发言」标签随闸门联动：禁言时它一直写着「可发言」是自相矛盾的
            var tag = $('owSpeakTag');
            if (tag) tag.style.display = kind ? 'none' : '';
            if (!kind) {
                if (box) { box.style.display = 'none'; box.innerHTML = ''; }
                input.disabled = false;
                input.placeholder = '输入消息，按 Enter 发送，Ctrl+V 粘贴图片';
                return;
            }
            input.disabled = true;
            // v1.3.52：系统通知是**单向**的 —— 账号安全事件不需要回复入口。
            // 这里只禁用不隐藏：保留输入区高度，切回普通会话时布局不跳。
            if (kind === 'notice') {
                if (box) { box.style.display = 'none'; box.innerHTML = ''; }
                input.placeholder = '系统通知不能回复';
                return;
            }
            if (kind === 'join') {
                if (box) {
                    box.innerHTML = '<span>你还没有加入该群聊，加入后即可发言</span>'
                        + '<button class="ow-btn ow-btn-primary ow-btn-mini" onclick="OwChat.joinCurrentRoom()">加入群聊</button>';
                    box.style.display = '';
                }
                input.placeholder = '加入群聊后即可发言';
                return;
            }
            // 'friend' / 'conv' / 'room'：没有具体对话对象，只禁用并给一句话提示
            if (box) { box.style.display = 'none'; box.innerHTML = ''; }
            input.placeholder = (kind === 'friend') ? '请先选择一个联系人'
                : (kind === 'room') ? '请先从左侧选择一个群聊'
                : '请先选择一个会话';
        },

        /**
         * 未加入群聊时的发言闸门（v1.2.27）。
         * 正常流程下点击公开群聊会先弹「是否加入」，取消就不进入，走不到这里；
         * 这里是 URL 直达 / 被移出成员 / 服务端已拒发等异常态的兜底：
         * 让界面自洽（看得见为什么发不出去）而不是让用户对着一个没反应的输入框。
         *
         * v1.2.29：实现已并入 applyInputGate，这里只保留业务判定。
         */
        applyJoinGate: function (isMember, isPublic) {
            var isUser = this.cfg.actor && this.cfg.actor.kind === 'user';
            var needJoin = !(!isUser || isPublic === false || isMember);
            this._needJoin = needJoin;
            this.applyInputGate(needJoin ? 'join' : '');
        },

        /** 在闸门里点「加入群聊」：就地加入，不必回会话列表重点一次 */
        joinCurrentRoom: function () {
            var self = this, id = this.room;
            if (!id) return;
            OwApi.post('room_join', { room_id: id, join: 1 }, function (r) {
                if (!r.ok) { toast(r.msg); return; }
                toast('已加入群聊');
                // 同步侧栏缓存，否则再点一次该会话还会弹「是否加入」
                var rl = self.cfg.rooms || [];
                for (var i = 0; i < rl.length; i++) { if (rl[i].id === id) rl[i].is_member = true; }
                self.applyJoinGate(true, true);
                if (r.members) self.renderMembers(r.members, self._canStatus);
                else self.loadMembers();
            });
        },

        /* ---------- 右侧栏：群聊信息入口区 ---------- */
        /**
         * 渲染右侧栏上方的「群聊信息」入口区（v1.1.10 重做）。
         *
         * 形态变更史（别走回头路）：
         *   v1.1.0  群资料是**弹窗**，入口在会话列表行内三点菜单。
         *   v1.1.1  改为**常驻侧栏内联表单**（本区块直接渲染可编辑表单）。
         *   v1.1.10 按需求回退到**弹窗**，侧栏只留两行入口：
         *     第 1 行「群聊信息」→ openRoomEdit() 打开模态框
         *     第 2 行「群公告」  → announcements 插件经 onRoomEdit 钩子填进 #owREExtras
         *     两行同款样式（.ow-panel-entry），群公告因此位于「所有成员」区块上方，
         *     **不再与群资料表单耦合**——插件不必关心表单是弹窗还是内联。
         *
         * 钩子契约（onRoomEdit）保持不变：
         *   fn({ roomId, ownerId, isAdmin, isOwner })，#owREExtras 必定存在。
         */
        renderRoomPanel: function () {
            // v1.3.24：切群 / 进私聊都会走到这里，成员搜索按钮的显隐跟着刷新
            if (this.syncMembersSearchBtn) this.syncMembersSearchBtn();
            var box = $('owRoomPanel');
            if (!box) return;
            var me = this.cfg.me || {}, isAdmin = this.cfg.actor.role === 'admin';
            var isDm = this.room === 0;                    // 私聊是 room_id=0 的虚拟空间
            var r = null, list = this.cfg.rooms || [], i;
            for (i = 0; i < list.length; i++) { if (list[i].id === this.room) { r = list[i]; break; } }

            // v1.2.29：什么会话都没选（刚进页、或从「联系人」标签切回来）——
            // 不属于私聊也不属于群聊，给一个中性空态，别误用私聊那套入口。
            var noTarget = !this.dm && !this.room;
            if (noTarget) {
                box.innerHTML = '<div class="ow-panel-hint">请先选择一个会话</div>';
                var memSec0 = document.querySelector('.ow-panel-members');
                if (memSec0) memSec0.style.display = 'none';
                return;
            }

            // v1.2.28：私聊**不显示「所有成员」区块** —— 那是群聊概念，
            // 私聊只有两个人，列出来是噪音。用 display 切换而不是移除节点：
            // 切回群聊时无需重建，且 poll 返回的 members 仍有地方可写。
            var memSec = document.querySelector('.ow-panel-members');
            if (memSec) memSec.style.display = isDm ? 'none' : '';

            // v1.2.28：私聊不再是「没有群聊信息」的空洞提示，改渲染四个会话操作入口。
            if (isDm) {
                box.innerHTML = this.dmPanelHtml();
                return;
            }
            if (!r) {
                /* v1.3.85 自愈：刚被邀请/新加入的群只出现在会话轮询里，而 cfg.rooms 还是进页时
                   的快照 —— 于是点进群看不到「群聊信息」入口，必须刷新页面才出现（用户报的现象）。
                   这里按当前群补拉一次群列表再重渲染；**每个群只补拉一次**，
                   免得「确实不在我列表里的群」（已删除/被移出）每次都打接口。 */
                if (this.room > 0 && this._roomsHealed !== this.room) {
                    this._roomsHealed = this.room;
                    this.reloadRooms();
                    return;
                }
                box.innerHTML = '<div class="ow-panel-hint">请先选择一个群聊</div>';
                return;
            }
            var meId = me.id || 0;
            var isOwner = !!meId && meId === (r.owner_id || 0);

            // 图标位放**群头像**：这一行代表的就是这个会话，头像比一枚通用齿轮更能说明「我在看哪个群」
            box.innerHTML = entryRow('群聊信息', 'gear', 'OwChat.openRoomEdit()', r.avatar)
                + '<div class="ow-panel-entry-row" id="owREExtras"></div>';

            // 插件扩展钩子（v0.0.102 起）：群公告等入口往 #owREExtras 追加
            var ctx = { roomId: this.room, ownerId: r.owner_id || 0, isAdmin: isAdmin, isOwner: isOwner };
            for (var hi = 0; hi < this._roomEditHooks.length; hi++) {
                try { this._roomEditHooks[hi](ctx); } catch (e) {}
            }
        },

        /**
         * 私聊右侧栏的四个入口（v1.2.28）。样式与群聊的「群聊信息」**完全同款**
         * （同一个 entryRow + .ow-panel-entry-row）。
         *
         * ⚠️ 结构不能拍平：分隔线画在 .ow-panel-entry-row 的 border-top 上，
         * 所以第一个入口必须是 .ow-panel-room-body 的**直接子元素**，
         * 其余三个放进紧随其后的 .ow-panel-entry-row —— 拍平成同级会丢掉顶部分隔线。
         * （v1.5.0：顶栏已上移到 .ow-head，右侧栏不再需要把首行撑到 --ow-topbar-h - 1px
         *   去跟顶栏底线对齐，那条规则一并撤掉了；这两个 DOM 层级仍然要按上面那样摆。）
         */
        dmPanelHtml: function () {
            var d = this.dm || {};
            var peer = d.peer || '';
            var uid = d.id || 0;
            var nick = (this.roomName || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
            var peerKey = peer ? 'dm:' + peer : '';
            var isGuest = this.cfg.actor.kind !== 'user';   // 游客无置顶/好友概念

            var first = isGuest || !peerKey
                ? entryRow('私聊会话', 'gear', '', d.avatar)       // 游客/异常态：静态行，只作占位保住首行结构
                : entryRow(d.pinned ? '取消置顶' : '设为置顶', d.pinned ? 'pinOff' : 'pin',
                    'OwChat.toggleDmPin()', d.avatar);

            var rest = entryRow('删除聊天记录', 'trash', 'OwChat.clearDmHistory()');
            // 「删除好友」只在对方确实是好友时给：否则点了必然报错（服务端会拒）
            if (!isGuest && uid && this.dm && this.dm.is_friend) {
                rest += entryRow('删除好友', 'userX', 'OwChat.removeDmFriend()');
            }
            // 「举报」来自 content-report 插件，插件未启用/未加载时**不渲染这一行**，
            // 不能给一个点了没反应的入口（插件不提供任何核心兜底）。
            if (!isGuest && uid && w.OwCR && typeof w.OwCR.openReport === 'function') {
                rest += entryRow('举报', 'flag', 'OwChat.reportDmPeer()');
            }
            return first + '<div class="ow-panel-entry-row">' + rest + '</div>';
        },

        /** 置顶 / 取消置顶当前私聊（仅影响自己的会话列表顺序） */
        toggleDmPin: function () {
            var self = this;
            var peerKey = (this.dm && this.dm.peer) ? ('dm:' + this.dm.peer) : '';
            if (!peerKey) { toast('私聊对象不合法'); return; }
            OwApi.post('dm_pin', { peer_key: peerKey }, function (r) {
                if (!r.ok) { toast(r.msg); return; }
                if (self.dm) self.dm.pinned = !!r.pinned;
                toast(r.msg);
                self.loadConversations();     // 重排列表：置顶的会话整体提到最前
                self.renderRoomPanel();      // 行文案在「设为置顶 / 取消置顶」之间切
            });
        },

        /**
         * 清空本机聊天记录（v1.2.28）。
         * 语义 = 项目既有的「删除」：**只写 message_hides，对方照常能看到**，可逆。
         * 清完把当前会话消息区也清空，否则界面与实际状态不一致。
         */
        clearDmHistory: function () {
            var self = this;
            var peer = this.dm && this.dm.peer;
            if (!peer) { toast('私聊对象不合法'); return; }
            this.confirm('确定清空与「' + (this.roomName || '对方') + '」的聊天记录？\n'
                + '仅清空你自己这边的视图，对方仍能看到全部消息。', function () {
                OwApi.post('dm_clear', { peer: peer }, function (r) {
                    if (!r.ok) { toast(r.msg); return; }
                    toast(r.msg || '已清空聊天记录');
                    if (self.dm && self.dm.peer === peer) {
                        $('owMessages').innerHTML = '';
                        self.since = 0;
                        self.historyDone = true;    // 已无可加载的历史，避免上滚又拉回来
                    }
                    self.loadConversations();
                });
            });
        },

        /** 删除当前私聊对方这个好友（对方不是好友时按钮本就不渲染，这里再兜一层） */
        removeDmFriend: function () {
            var self = this;
            var uid = this.dm && this.dm.id;
            if (!uid) { toast('私聊对象不合法'); return; }
            this.confirm('确定删除好友「' + (this.roomName || '') + '」？\n'
                + '你这一侧与 TA 的聊天记录会一并清空、不再出现在消息列表；'
                + '对方仍保留他自己的记录。', function () {
                self.removeFriend(uid);
            });
        },

        /** 举报当前私聊对方（入口来自 content-report 插件） */
        reportDmPeer: function () {
            var d = this.dm || {};
            if (!d.id || !w.OwCR) { toast('举报功能不可用'); return; }
            w.OwCR.openReport(d.id, this.roomName || '', 0, 0, 0);
        },

        /**
         * 群聊信息弹窗（v1.1.10 恢复弹窗形态，v1.1.1~v1.1.9 曾内联常驻在侧栏）。
         *
         * 与 v1.1.0 弹窗版的差异：
         *  - 群头像尺寸统一走 avatarHtml 的 'lg' 档（64px），与资料卡/个人设置一致；
         *  - 编辑区保留「群名称 + 群简介」两个字段（v1.1.0 的密码/类型设置不在本弹窗内，
         *    那部分历史上就是独立入口，勿在此扩张）；
         *  - 非群主看到只读信息（简介、群主、类型），可改与否由 room.can_edit 决定。
         */
        openRoomEdit: function () {
            var me = this.cfg.me || {}, isAdmin = this.cfg.actor.role === 'admin';
            var r = null, list = this.cfg.rooms || [], i;
            for (i = 0; i < list.length; i++) { if (list[i].id === this.room) { r = list[i]; break; } }
            if (this.room === 0 || !r) { toast('私聊会话没有群聊信息'); return; }

            var canEdit = !!r.can_edit;   // 服务端下发：群主 + 超管（口径唯一，前端不自行判身份）
            var meId = me.id || 0;
            var isOwner = !!meId && meId === (r.owner_id || 0);
            // v1.1.16：与创建弹窗 / 后端口径统一 —— type='public' 显示「普通」
            // （原先这里显示「群聊」，会与旁边的公开性标签「普通」凑成「群聊 + 普通」两个标签）
            var typeName = r.type === 'password' ? '密码群' : (r.type === 'role' ? '角色限定' : '普通');
            var isPublic = r.is_public !== false;   // 缺省视为公开，兼容旧缓存数据
            var canInvite = !!r.can_invite;
            // v1.1.14：普通用户在总闸关闭时不能把公开群改成不公开（服务端会拒），
            // 这里同步禁用开关。已经是不公开的群则放行——改个名不该被总闸拦住。
            var canTogglePublic = (this.cfg.settings || {}).room_private_create !== '0'
                || !isPublic || isAdmin;
            // 待保存头像按群缓存：切群时必须重置，否则会把上一个群的头像带过来。
            // v1.3.135：这里存的是**要提交给 room_update 的相对路径**，空串=本次不改头像。
            // 原先初始化成 r.avatar —— 那是 rooms() 现算的**渲染用完整 URL**，
            // 于是改个群名点保存，room_update 会收到一个不以 uploads/avatar/ 开头的值，
            // 被服务端判「头像路径不合法」整条拒掉（改名根本存不进去）。
            // 而且对「头像跟随群主」的群，那个 URL 就是群主自己的头像 —— 真存进去等于
            // 把群头像悄悄钉死在保存那一刻的群主图片上。预览要的是地址，这里另取。
            if (this._roomAvatarRoom !== this.room) {
                this._roomAvatarRoom = this.room;
                this._roomAvatar = '';
            }

            this.openModal(
                '<h3>群聊信息</h3>'
                + '<div class="ow-card-head">'
                + '<span class="ow-set-avatar-btn" id="owRoomAvatarPreview"'
                + (canEdit ? ' title="点击更换群头像" onclick="OwChat.roomAvatarPick()"' : '') + '>'
                // v1.1.19：群头像改走 roomAvatarHtml —— 无自定义头像时显示默认剪影图，
                // 不再是「群名首字 + 随机色块」（那个 role 传 'member' 走的是色盘分支）。
                // 取的是**渲染地址**（roomAvatarOf），不是待提交的 _roomAvatar —— 后者空串=不改头像。
                + roomAvatarHtml(this.roomAvatarOf(this.room), 'lg') + '</span>'
                + '<div class="ow-card-id">'
                + '<div class="ow-card-name">' + esc(r.name) + '</div>'
                + '<div class="ow-card-badges"><span class="ow-tag ow-tag-green">' + typeName + '</span>'
                // v1.1.18：公开性徽章改回「公开」。v1.1.16 写成「普通」会与左边
                // type=public 的「普通」并排出现两个同词标签，等于把公开性信息抹掉了。
                + '<span class="ow-tag ow-tag-member">' + (isPublic ? '公开' : '仅邀请') + '</span></div>'
                + '</div></div>'
                + (canEdit
                    ? '<div class="ow-card-meta">'
                      + '<div class="ow-form-item"><label>群名称</label><input class="ow-input" id="owRoomEditName" value="' + esc(r.name) + '" maxlength="30"></div>'
                      + '<div class="ow-form-item" style="margin-top:10px"><label>群简介</label><input class="ow-input" id="owRoomEditDesc" value="' + esc(r.description || '') + '" maxlength="200" placeholder="一句话介绍这个群（可选）"></div>'
                      // v1.1.11 公开性开关：与「类型」正交，只控制谁能发现这个群。
                      // v1.1.14：总闸关闭且本群当前是公开时禁用，避免「保存必报错」。
                      // v1.1.16：删掉「开启：显示在群聊列表，游客可进入并发言。关闭：只有群主
                      // 与成员能进，需邀请加入。」这段说明 —— 一行讲两种状态读着绕，
                      // 且「游客可进入并发言」并非所有群都成立（受类型与角色门槛影响）。
                      // v1.1.18：公开性开关标签改回「公开群聊」（v1.1.16 误写「普通群聊」，
                      // 与上方类型徽章「普通」撞词，看起来像开关消失了）。
                      + '<div class="ow-form-item ow-form-item-switch">'
                      + switchHtml('owRoomPublic', '公开群聊', isPublic,
                          canTogglePublic ? ''
                                          : '站点已关闭「创建仅邀请群聊」，本群只能保持公开。',
                          !canTogglePublic)
                      + '</div>'
                      + '</div>'
                      + '<div class="ow-modal-actions ow-modal-actions-split">'
                      + (canInvite ? '<button class="ow-btn ow-btn-ghost" onclick="OwChat.roomMembers(' + r.id + ')">成员管理</button>' : '')
                      + '<span class="ow-modal-actions-sp"></span>'
                      + '<button class="ow-btn ow-btn-ghost" onclick="OwChat.closeModal()">取消</button>'
                      + '<button class="ow-btn ow-btn-primary" onclick="OwChat.roomEditSave(' + r.id + ')">保存</button></div>'
                    // 只读：非群主会员也能看到群名称 / 简介 / 群主，信息不设限，仅不可改
                    : '<div class="ow-card-meta">'
                      + (r.description
                          ? '<div class="ow-card-meta-row"><span class="ow-card-meta-k">简介</span><span class="ow-card-meta-v">' + esc(r.description) + '</span></div>'
                          : '<div class="ow-card-meta-row"><span class="ow-card-meta-v ow-panel-empty">群主还没有写简介</span></div>')
                      + '<div class="ow-card-meta-row"><span class="ow-card-meta-k">群主</span><span class="ow-card-meta-v">' + esc(fmtUid(r.owner_id)) + '</span></div>'
                      + '<div class="ow-card-meta-row"><span class="ow-card-meta-k">可见性</span><span class="ow-card-meta-v">' + (isPublic ? '公开（所有人可见）' : '仅邀请（仅群主与成员）') + '</span></div>'
                      + '<div class="ow-card-meta-row"><span class="ow-card-meta-k">修改</span><span class="ow-card-meta-v">仅群主与超级管理员可修改</span></div>'
                      + '</div>'
                      + '<div class="ow-modal-actions ow-modal-actions-split">'
                      + (canInvite ? '<button class="ow-btn ow-btn-ghost" onclick="OwChat.roomMembers(' + r.id + ')">成员管理</button>' : '')
                      + '<span class="ow-modal-actions-sp"></span>'
                      + '<button class="ow-btn ow-btn-ghost ow-btn-block" onclick="OwChat.closeModal()">关闭</button></div>')
            );
            bindSwitches($('owModal'));
        },

        /* ---------- 群成员管理（v1.1.11） ---------- */
        /**
         * 成员管理弹窗：列出成员 + 按用户 ID 邀请 + 群主/超管移出成员。
         *
         * 身份口径：**只用数字用户 ID**。昵称可重名、邮箱属个人信息，
         * 二者都不能做身份标识或反查（见开发文档「开发约束」）。
         * 因此这里**不提供**「按昵称搜索用户」的功能——只接受对方主动报给你的 ID。
         */
        roomMembers: function (roomId) {
            var self = this;
            OwApi.post('room_members', { room_id: roomId }, function (r) {
                if (!r.ok) { toast(r.msg); return; }
                var list = r.data || [], canInvite = !!r.can_invite;
                var isOwnerOrAdmin = (self.cfg.actor.role === 'admin')
                    || ((self.cfg.me || {}).id === r.owner_id);
                var meId = (self.cfg.me || {}).id || 0;
                var cards = '';
                if (!list.length) cards = '<div class="ow-mem-empty">还没有其他成员，可按用户 ID 邀请</div>';
                for (var i = 0; i < list.length; i++) {
                    var m = list[i];
                    // v1.2.40：成员管理是**管理场景**，超管要显示「超级管理员」
                    //（判断谁能管这个群靠的就是这个标签，隐藏身份会让人做错决定）
                    var mTag = roleTag(m.role, '', 0, true);
                    cards += '<div class="ow-mem-row">'
                        + avatarHtml(m.avatar, m.nickname, 'sm', m.role)
                        + '<span class="ow-mem-name">' + esc(m.nickname || 'ID' + m.user_id) + '</span>'
                        + (mTag ? '<span class="ow-mem-role">' + mTag + '</span>' : '')
                        + '<span class="ow-mem-id">ID ' + esc(fmtUid(m.user_id)) + '</span>'
                        + (isOwnerOrAdmin
                            ? '<button class="ow-btn ow-btn-ghost ow-btn-mini ow-mem-del" data-id="' + m.user_id + '">移出</button>'
                            : '')
                        + '</div>';
                }
                // 邀请码仅成员可见；不公开群没有它没法被外部找到，所以要提供
                var codeRow = '';
                if (r.invite_code) {
                    var link = self.cfg.site_url + '/?room_invite=' + esc(r.invite_code);
                    codeRow = '<div class="ow-card-meta">'
                        + '<div class="ow-card-meta-row"><span class="ow-card-meta-k">邀请码</span>'
                        + '<span class="ow-card-meta-v">' + esc(r.invite_code) + '</span></div>'
                        + '<div class="ow-card-meta-row"><span class="ow-card-meta-k">邀请链接</span>'
                        + '<span class="ow-card-meta-v ow-mem-link">'
                        + '<a href="' + esc(link) + '" target="_blank" rel="noopener">' + esc(link) + '</a></span></div>'
                        + '</div>';
                }
                self.openModal(
                    '<h3>群成员</h3>'
                    + '<div class="ow-mem-head">共 ' + (list.length + 1) + ' 人（含群主）</div>'
                    + '<div class="ow-mem-list">' + cards + '</div>'
                    + (canInvite
                        ? '<div class="ow-mem-invite">'
                          + '<div class="ow-form-item"><label>邀请用户</label>'
                          + '<input class="ow-input" id="owMemInviteId" placeholder="对方用户 ID（数字）" inputmode="numeric"></div>'
                          + '<button class="ow-btn ow-btn-primary ow-btn-block" id="owMemInviteBtn">邀请加入</button>'
                          + '</div>'
                        : '')
                    + codeRow
                    + '<div class="ow-modal-actions">'
                    + (isOwnerOrAdmin && r.invite_code
                        ? '<button class="ow-btn ow-btn-ghost" onclick="OwChat.roomInviteReset(' + roomId + ')">重置邀请码</button>' : '')
                    + '<button class="ow-btn ow-btn-ghost ow-btn-block" onclick="OwChat.closeModal()">关闭</button></div>'
                );
                // 邀请
                var ib = $('owMemInviteBtn');
                if (ib) ib.onclick = function () {
                    var uid = ($('owMemInviteId').value || '').replace(/[^0-9]/g, '');
                    if (!uid) { toast('请输入对方的用户 ID（数字）'); return; }
                    ib.disabled = true; ib.textContent = '邀请中…';
                    OwApi.secure('room_invite', { room_id: roomId, user_id: uid }, function (res) {
                        toast(res.msg);
                        if (!res.ok) { ib.disabled = false; ib.textContent = '邀请加入'; return; }
                        self.closeModal();
                        self.roomMembers(roomId);   // 成功即刷新成员列表
                    });
                };
                // 移出（敏感操作：票据一次性）
                var delBtns = document.querySelectorAll('#owModal .ow-mem-del');
                for (var k = 0; k < delBtns.length; k++) {
                    (function (el) {
                        el.onclick = function () {
                            var uid = el.getAttribute('data-id');
                            self.confirm('确定把该成员移出本群？他将无法再进入仅邀请群。', function () {
                                OwApi.secure('room_remove_member', { room_id: roomId, user_id: uid }, function (res) {
                                    toast(res.msg);
                                    if (res.ok) { self.closeModal(); self.roomMembers(roomId); }
                                });
                            });
                        };
                    })(delBtns[k]);
                }
                var rb = $('owModal').querySelector('[onclick*="roomInviteReset"]');
                if (rb) rb.onclick = function () { self.roomInviteReset(roomId); };
            });
        },

        /** 重置邀请码（旧链接立即失效）；敏感操作 */
        roomInviteReset: function (roomId) {
            var self = this;
            this.confirm('重置后，旧邀请链接与邀请码立即失效。确定继续？', function () {
                OwApi.secure('room_invite_code_reset', { room_id: roomId }, function (r) {
                    toast(r.msg);
                    if (r.ok) { self.closeModal(); self.roomMembers(roomId); }
                });
            });
        },

        /* ---------- 公告轮播 ---------- */
        renderAnnounce: null,   // v1.0.102 公告已剥离为 announcements 插件（见 plugins/announcements/）

        /* ---------- 表情面板 ---------- */
        buildEmojiPanel: function () {
            var self = this, html = '<div class="ow-emoji-tabs">'
                + '<button class="ow-emoji-tab active" data-tab="emoji">Emoji</button>'
                + '<button class="ow-emoji-tab" data-tab="sticker">我的贴纸</button></div>'
                + '<div class="ow-emoji-grid" id="owEmojiGrid"></div>';
            $('owEmojiPanel').innerHTML = html;
            var tabs = $('owEmojiPanel').querySelectorAll('.ow-emoji-tab'), i;
            for (i = 0; i < tabs.length; i++) {
                tabs[i].onclick = function () {
                    var t = $('owEmojiPanel').querySelectorAll('.ow-emoji-tab'), j;
                    for (j = 0; j < t.length; j++) t[j].className = 'ow-emoji-tab';
                    this.className = 'ow-emoji-tab active';
                    self.renderEmojiGrid(this.getAttribute('data-tab'));
                };
            }
            this.renderEmojiGrid('emoji');
        },

        renderEmojiGrid: function (tab) {
            var grid = $('owEmojiGrid'), self = this, html = '', i;
            if (tab === 'emoji') {
                for (i = 0; i < this.emojis.length; i++) html += '<span class="ow-emoji-item">' + this.emojis[i] + '</span>';
                grid.innerHTML = html;
                var items = grid.getElementsByTagName('span');
                for (i = 0; i < items.length; i++) {
                    items[i].onclick = function () {
                        $('owInput').value += this.innerHTML;
                        $('owInput').focus();
                    };
                }
            } else {
                OwApi.post('stickers', {}, function (r) {
                    if (!r.ok || !r.data.length) { grid.innerHTML = '<p style="padding:20px;color:var(--ow-text-sub);font-size:12px">暂无贴纸：把鼠标悬停在图片消息上点击「收藏贴纸」即可添加</p>'; return; }
                    for (i = 0; i < r.data.length; i++) html += '<img class="ow-sticker-item" src="' + esc(r.data[i].url) + '" loading="lazy" decoding="async">';
                    grid.innerHTML = html;
                    var imgs = grid.getElementsByTagName('img');
                    for (i = 0; i < imgs.length; i++) {
                        imgs[i].onclick = function () {
                            self.send({ type: 'image', content: this.src });
                            $('owEmojiPanel').style.display = 'none';
                        };
                    }
                });
            }
        },

        /* ---------- 我的面板 / 设置 ---------- */
        /**
         * 「头像 + 昵称」胶囊的内层 HTML（v1.3.142 建，v1.5.0 搬进账号菜单首行）。
         * 头像 URL 的三种来源（上传 / 生成式 / 回落）与昵称都在 cfg 里，服务端再拼一遍
         * 就是第二份实现，迟早漂移 —— 所以只有这一处实现。
         */
        meChipHtml: function () {
            var me = this.cfg.me, actor = this.cfg.actor || {};
            var nick = me ? me.nickname : (actor.nickname || '');
            return avatarHtml(me ? me.avatar : actor.avatar, nick, 'xs', me ? me.role : 'guest')
                + '<span class="ow-ctx-me-n">' + esc(nick) + '</span>';
        },

        /**
         * 刷新胶囊（init / 进私聊 / 切群 / 改昵称后调用）。
         * v1.5.0：胶囊现在长在账号菜单里，而菜单每次打开都由 toggleAccountMenu 重拼，
         * 所以「没开着菜单」时这里什么都不用做 —— 保留函数是为了不惊动那 6 个调用点。
         */
        renderMe: function () {
            var el = $('owBrandMe');
            if (!el) return;
            el.innerHTML = this.meChipHtml();
            el.setAttribute('aria-label', owTr('个人主页'));
        },


        /* ---------- 搜索窗（v1.2.31，替代原「品牌区竖三点」菜单） ----------
           v1.2.31 之前这里是 onBrandMenu 扩展点 + toggleBrandMenu（头像+昵称/联系人/插件项）。
           用户要求删除并改成搜索：**默认搜「当前聊天」**，下方可切换搜索范围。
           ⚠️ onBrandMenu 扩展点一并删除（全项目零引用，plugins/ 下无任何调用）；
              打开自己资料卡的入口 —— v1.5.0 起是 rail 底部账号菜单的首行胶囊。 */

        /**
         * 搜索范围（v1.2.32 调整顺序：找人/群 挪到最后）。
         * 顺序即标签条上的呈现顺序；插件追加的排在最后。
         *   current  当前聊天（默认）
         *   messages 全站消息
         *   friends  联系人
         *   people   找人/群
         */
        _srScopeDefs: [
            { id: 'current', label: '当前聊天' },
            { id: 'messages', label: '消息' },
            { id: 'friends', label: '好友' },
            { id: 'people', label: '找人/群' },
        ],
        _searchExt: [],       // 插件追加的自定义搜索范围

        /**
         * 插件扩展点：追加自定义搜索范围。
         * 回调签名 fn(defs, env)：
         *   - 只给 {id, label} → 走核心 doSearch（后端需认得这个 scope，否则结果为空）
         *   - 给 {id, label, run(self, keyword)} → 点击该标签时由插件自己搜、自己渲染，
         *     核心只负责把输入框的值传过去（适合要调自己接口、或结果结构不同的插件）
         * @example
         * OwChat.onSearchScopes(function (defs) {
         *     defs.push({ id: 'files', label: '文件', run: function (self, kw) { /* 自搜 *\/ } });
         * });
         */
        onSearchScopes: function (fn) { if (typeof fn === 'function') this._searchExt.push(fn); },

        /** 取范围定义表（含插件追加项） */
        searchScopes: function () {
            var defs = [], i;
            for (i = 0; i < this._srScopeDefs.length; i++) defs.push(this._srScopeDefs[i]);
            for (i = 0; i < this._searchExt.length; i++) {
                try { this._searchExt[i](defs, { actor: this.cfg.actor, me: this.cfg.me }); } catch (e) {}
            }
            return defs;
        },

        /** 打开搜索窗。scope 缺省为「当前聊天」 */
        openSearch: function (scope) {
            var self = this;
            // v1.2.32：游客不能搜索（没有会话体系，搜什么都是空的）
            if (!this.cfg.actor || this.cfg.actor.kind !== 'user') {
                toast('游客暂不支持搜索');
                return;
            }
            this._srScope = scope || 'current';
            this._srTimer = null;
            this._srSeq = 0;
            this._srData = [];
            var cur = this.searchContext();
            var hint = this._srScope === 'current'
                ? (cur ? ('在「' + cur.name + '」里搜索') : '当前没有打开的会话')
                : '输入关键词';
            var defs = this.searchScopes(), i;
            var scopeHtml = '';
            for (i = 0; i < defs.length; i++) {
                scopeHtml += '<button class="ow-tab' + (defs[i].id === this._srScope ? ' is-active' : '')
                    + '" data-scope="' + esc(defs[i].id) + '" type="button">' + esc(defs[i].label) + '</button>';
            }
            this.openModal(
                '<h3>搜索</h3>'
                + '<div class="ow-tabs ow-sr-scope" id="owSrScope">' + scopeHtml + '</div>'
                // v1.2.32：maxlength=50（与服务端 cleanSearchKey 一致）
                + '<input class="ow-input" id="owSrQ" maxlength="50" placeholder="' + esc(hint) + '" autocomplete="off">'
                + '<div class="ow-sr-list" id="owSrList"></div>'
            , 460);
            var q = $('owSrQ');
            this.renderSearchResults([]);
            if (!q) return;
            // 防抖 300ms（输入过程），回车立即搜一次
            q.oninput = function () {
                if (self._srTimer) clearTimeout(self._srTimer);
                self._srTimer = setTimeout(function () { self.doSearch(q.value); }, 300);
            };
            q.onkeydown = function (e) {
                e = e || w.event;
                if (e.keyCode === 13) { if (self._srTimer) clearTimeout(self._srTimer); self.doSearch(q.value); }
            };
            var bar = $('owSrScope');
            if (bar) bar.onclick = function (e) {
                var t = e.target || e.srcElement;
                while (t && t !== bar && !(t.getAttribute && t.getAttribute('data-scope'))) t = t.parentNode;
                if (!t || t === bar) return;
                var sc = t.getAttribute('data-scope');
                if (!sc || sc === self._srScope) return;
                self._srScope = sc;
                var bs = bar.getElementsByClassName('ow-tab'), i2;
                for (i2 = 0; i2 < bs.length; i2++) {
                    if (bs[i2].getAttribute('data-scope') === sc) bs[i2].className += ' is-active';
                    else bs[i2].className = bs[i2].className.replace(' is-active', '');
                }
                // 插件自定义范围：走它自己的 run（自搜自渲染）
                var defs2 = self.searchScopes(), j;
                for (j = 0; j < defs2.length; j++) {
                    if (defs2[j].id === sc && typeof defs2[j].run === 'function') {
                        self._srData = [];
                        self.renderSearchResults([]);
                        try { defs2[j].run(self, ($('owSrQ') || {}).value || ''); } catch (err) {}
                        return;
                    }
                }
                // v1.2.32：「在「群名」里搜索」这种**带上下文的提示只属于「当前聊天」**，
                // 切到其它范围必须换回普通提示（否则「在『综合闲聊』里搜索」下面
                // 列出全站消息，自相矛盾还误导用户）；切回来也要**恢复**上下文提示。
                var qi = $('owSrQ');
                if (qi) {
                    if (sc === 'current') {
                        var c2 = self.searchContext();
                        qi.placeholder = c2 ? ('在「' + c2.name + '」里搜索') : '当前没有打开的会话';
                    } else {
                        qi.placeholder = '输入关键词';
                    }
                }
                self.doSearch(qi ? qi.value : '');
            };
            setTimeout(function () { try { q.focus(); } catch (e) {} }, 30);
        },

        /** 当前搜索上下文：群聊给 room_id，私聊给 peer；都没有返回 null */
        searchContext: function () {
            if (this.dm) return { roomId: 0, peer: this.dm.peer, name: this.roomName || '私聊' };
            if (this.room) {
                // roomName 只在点过一次会话列表后才填上；用 ?page=chat&room=ID 直达时它是空的，
                // 不兜底就会渲染出「在「」里搜索」这种空壳提示（搜索框现在是侧栏常驻入口，更容易撞到）。
                var nm = this.roomName;
                if (!nm) {
                    var li = document.querySelector('#owRoomList li.active[data-name]');
                    nm = li ? String(li.getAttribute('data-name') || '') : '';
                }
                return { roomId: this.room, peer: '', name: nm };
            }
            return null;
        },

        /**
         * 执行搜索（竞态保护：只认最后一次请求的结果，先回来的旧请求直接丢弃）
         *
         * v1.2.37 节流改成**递增退避**，不再是「一律 10 秒」：
         *   第 1、2 次  ��制（正常打字就该能搜）
         *   第 3 次起   1 秒 → 5 秒 → 25 秒 → 60 秒封顶（×5 递增）
         * 理由：用户连续改关键词是常态，一律 10 秒会把正常输入全挡掉。
         * 输入过程另有 300ms 防抖（见 openSearch 的 oninput），所以「停止输入才搜索」
         * 已经由防抖保证，节流只负责压住「连续回车/连续点」这种高频行为。
         *
         * 冷却期间提示**每秒倒计时**，不是干巴巴一句「请 10 秒后再试」。
         */
        _srCount: 0,          // 已发起过的搜索次数
        _srCoolUntil: 0,      // 冷却截止时间戳
        _srCoolTimer: null,   // 倒计时定时器

        /** 递增退避：第 3 次 1s，第 4 次 5s，第 5 次 25s，之后封顶 60s */
        _srCoolMs: function (n) {
            if (n < 3) return 0;
            return Math.min(1000 * Math.pow(5, n - 3), 60000);
        },

        /** 冷却中的倒计时提示（每秒刷新，到点自动放行） */
        _srShowCooldown: function (list) {
            var self = this;
            if (this._srCoolTimer) { clearInterval(this._srCoolTimer); this._srCoolTimer = null; }
            var tick = function () {
                var wait = Math.ceil((self._srCoolUntil - new Date().getTime()) / 1000);
                if (wait <= 0) {
                    if (self._srCoolTimer) { clearInterval(self._srCoolTimer); self._srCoolTimer = null; }
                    if (list) list.innerHTML = '<div class="ow-sr-empty">可以搜索了，回车或继续输入关键词</div>';
                    return;
                }
                if (list) list.innerHTML = '<div class="ow-sr-empty">搜索太频繁，' + wait + ' 秒后可再次搜索</div>';
            };
            tick();
            this._srCoolTimer = setInterval(tick, 1000);
        },

        doSearch: function (kw) {
            var self = this;
            kw = String(kw == null ? '' : kw)
                .replace(/[\x00-\x1F\x7F<>"'`\\\/%&|;=$()[\]{}*?!#~^,]/g, '')   // 异常字符
                .replace(/\s+/g, ' ')
                .replace(/^\s+|\s+$/g, '');
            if (kw.length > 50) kw = kw.slice(0, 50);
            var list = $('owSrList');
            if (!kw) { this.renderSearchResults([]); return; }
            if (this._srScope === 'current' && !this.searchContext()) {
                // 中间是空白的（切到「联系人」标签后就是这状态）→ 明确告诉用户，别让人干等
                if (list) list.innerHTML = '<div class="ow-sr-empty">当前没有打开的聊天，请先点开一个会话，或切换上面的搜索范围</div>';
                return;
            }
            // ① 递增退避冷却
            if (new Date().getTime() < this._srCoolUntil) { this._srShowCooldown(list); return; }
            this._srCount++;
            var cool = this._srCoolMs(this._srCount);
            if (cool > 0) this._srCoolUntil = new Date().getTime() + cool;
            else this._srCoolUntil = 0;

            var ctx = this.searchContext() || { roomId: 0, peer: '' };
            var mySeq = ++this._srSeq;
            if (list) list.innerHTML = '<div class="ow-sr-empty">搜索中…</div>';
            OwApi.post('search', {
                scope: this._srScope, q: kw,
                room_id: ctx.roomId || 0, peer: ctx.peer || ''
            }, function (r) {
                if (mySeq !== self._srSeq) return;
                if (!r.ok) { if (list) list.innerHTML = '<div class="ow-sr-empty">' + esc(r.msg || '搜索失败') + '</div>'; return; }
                self._srData = r.data || [];
                self._srTruncated = !!r.truncated;
                self._srLimit = r.limit || 0;
                self.renderSearchResults(self._srData);
            });
        },

        /**
         * 渲染搜索结果（用户 / 群 / 消息 三类行）。
         *
         * v1.2.35 起**分批懒渲染**（原先一次性插完，最坏 80 条 × 每条一个头像
         * = 80 个 DOM 子树 + 80 个图片请求，首屏会明显卡）。
         * 现在：首批只渲染 20 条，滚动到底再追加下一批；
         * 头像带 loading="lazy"（屏外不发起请求）；再叠加 CSS content-visibility
         * 让屏外条目跳过布局与绘制。
         * 结论区最后才拼（必须等所有批次都渲染完，否则会夹在中间）。
         */
        _srBatchSize: 20,
        _srRendered: 0,

        /** 搜索结果里的头像：加 loading=lazy / decoding=async，屏外不请求 */
        srAvatar: function (url, name, size, role) {
            var h = avatarHtml(url, name, size, role);
            return h.replace('<img ', '<img loading="lazy" decoding="async" ');
        },

        renderSearchResults: function (list) {
            var self = this, box = $('owSrList');
            if (!box) return;
            if (!list.length) {
                var kw = ($('owSrQ') || {}).value || '';
                box.innerHTML = '<div class="ow-sr-empty">' + (kw ? '没有找到相关内容' : '输入关键词开始搜索') + '</div>';
                return;
            }
            this._srRendered = 0;
            box.innerHTML = '';
            // ⚠️ 两个坑：
            //  ① 用 onscroll 赋值而不是 addEventListener —— 每次搜索都会重跑这个函数，
            //     addEventListener 会不断叠加监听器，回调里重复追加。
            //  ② 判断条件里的 scrollTop/clientHeight/scrollHeight 必须取 **box**（列表元素），
            //     不是 self（OwChat 对象）。写成 self.scrollTop 恒为 undefined，
            //     比较恒为 false → 永远不触发追加，列表就停在首批 20 条。
            box.onscroll = function () {
                if (box.scrollTop + box.clientHeight >= box.scrollHeight - 48) self.appendSrBatch();
            };
            this.appendSrBatch();
        },

        /** 追加一批（默认 20 条）；已全部渲染完则收尾加分隔线说明 */
        appendSrBatch: function () {
            var self = this, box = $('owSrList');
            if (!box || !this._srData) return;
            var total = this._srData.length;
            var from = this._srRendered;
            if (from >= total) return;
            var to = Math.min(from + this._srBatchSize, total);
            var html = '', i, d;
            for (i = from; i < to; i++) {
                d = this._srData[i];
                if (d.type === 'msg') {
                    // v1.2.37：用发送者的真实头像（后端按 user_id 批量带出）；
                    // 游客消息没有用户身份 → 退回字母头像
                    html += '<div class="ow-sr-item is-msg" data-i="' + i + '">'
                        + this.srAvatar(d.avatar || '', d.from, true, d.user_id ? 'user' : 'guest')
                        + '<span class="ow-sr-main">'
                        + '<span class="ow-sr-title">' + esc(d.from) + '<span class="ow-sr-tag">' + (d.room_id ? '群聊' : '私聊') + '</span></span>'
                        + '<span class="ow-sr-sub">' + esc(d.text) + '</span>'
                        + '</span></div>';
                } else if (d.type === 'room') {
                    // v1.2.32：群也显示 ID（可按 ID 直接搜到群）
                    html += '<div class="ow-sr-item" data-i="' + i + '">'
                        + roomAvatarHtml(d.avatar, true, 'ow-cl-icon')
                        + '<span class="ow-sr-main">'
                        + '<span class="ow-sr-title">' + esc(d.name)
                        + '<span class="ow-sr-tag">ID ' + esc(fmtUid(d.room_id)) + '</span>'
                        + (d.need_password ? '<span class="ow-sr-tag">密码房</span>' : '')
                        + '</span>'
                        + '<span class="ow-sr-sub">' + (d.need_password ? '需要密码才能进入' : '点击进入群聊') + '</span>'
                        + '</span></div>';
                } else {
                    // v1.2.32：ID 提到**标题行**做标签
                    html += '<div class="ow-sr-item" data-i="' + i + '">'
                        + this.srAvatar(d.avatar, d.nickname, true, d.role)
                        + '<span class="ow-sr-main">'
                        + '<span class="ow-sr-title">' + esc(d.nickname)
                        + '<span class="ow-sr-tag">ID ' + esc(fmtUid(d.user_id)) + '</span>'
                        + (d.is_friend ? '<span class="ow-sr-tag">好友</span>' : '')
                        + '</span>'
                        + '<span class="ow-sr-sub">' + esc(d.signature || ('用户 ID ' + fmtUid(d.user_id))) + '</span>'
                        + '</span></div>';
                }
            }
            this._srRendered = to;
            box.insertAdjacentHTML('beforeend', html);

            // 最后一批之后才补截断说明
            if (to >= total && this._srTruncated && this._srLimit) {
                box.insertAdjacentHTML('beforeend',
                    '<div class="ow-sr-tip">结果较多，仅显示前 ' + this._srLimit + ' 条，试试更精确的关键词</div>');
            }
            // 内容不足一屏（结果少或窗口很高）时继续补，否则用户永远滚不到底、
            // 后面的批次就永远加载不出来。
            if (to < total && box.scrollHeight <= box.clientHeight + 8) this.appendSrBatch();

            // 点击委托：每次渲染都**赋值**给当前这个 box。
            // ⚠️ 不能用「绑过一次就跳过」的标记 —— openSearch 每次打开都会重建 #owSrList 节点，
            //    标记还留在 OwChat 上，于是第二次起的每一个新节点都没人绑，点结果完全没反应
            //    （实测：第二次打开后 box.onclick === null 而 _srClickBound === true）。
            //    用 onclick = fn 赋值而不是 addEventListener：赋值天然不叠加，正是这里要的语义。
            box.onclick = function (e) {
                var t = e.target;
                while (t && t !== box && !(t.getAttribute && t.getAttribute('data-i'))) t = t.parentNode;
                if (!t || t === box) return;
                var idx = parseInt(t.getAttribute('data-i'), 10);
                if (self._srData && self._srData[idx]) self.pickSearchResult(self._srData[idx]);
            };
        },

        /** 点击搜索结果的分发：人 → 私聊，群 → 进群，消息 → 跳到所在会话 */
        pickSearchResult: function (d) {
            var self = this;
            if (d.type === 'user') {
                this.closeModal();
                this.openDm('user:' + d.user_id, d.nickname);
                return;
            }
            if (d.type === 'room') {
                var rl = this.cfg.rooms || [], i;
                for (i = 0; i < rl.length; i++) {
                    if (rl[i].id === d.room_id) {
                        this.closeModal();
                        this.switchRoom(d.room_id, rl[i].name, null);
                        return;
                    }
                }
                toast('该群聊当前不可进入');
                return;
            }
            // 消息结果：跳到**那条消息**并弹出它的操作菜单（v1.2.32）
            this.closeModal();
            if (this.view !== 'chat') this.switchTab('chat');
            if (d.room_id) {
                var rs = this.cfg.rooms || [];
                for (var k = 0; k < rs.length; k++) {
                    if (rs[k].id === d.room_id) { this.switchRoom(d.room_id, rs[k].name, null); break; }
                }
                if (k >= rs.length) { toast('该消息所在的群聊已不可进入'); return; }
            } else if (d.peer) {
                this.openDm(d.peer, this.dmNameOf(d.peer));
            } else {
                return;
            }
            this.jumpToMsg(d);
        },

        /* ---------- 消息定位轮子（v1.2.36） ----------
           统一「跳到某条消息」的唯一入口。此前有**两套**并行实现：
             · jumpToQuote（引用跳转）：scrollIntoView(smooth) + .ow-msg-jump 动画
                                      + 递归 loadHistory（最多 10 页）
             · 搜索结果跳转：手动算 scrollTop + .ow-msg-hit 高亮 + 弹操作菜单
                                      + 自己的按页回溯（15 页）
           两套的高亮样式、回溯方式、页数上限都不一样，看起来像两个功能。
           现在合并成一个轮子 `locateMsg(msgId, opt)`，样式与行为完全一致。

           opt:
             menu  {boolean} 定位后是否弹出该消息的操作菜单（搜索跳转要，引用不要）
             tip   {string}  定位失败时的提示文案

           为什么不用 scrollIntoView：它会把**最近的祖先滚动容器**一起滚，
           且老浏览器不支持 smooth 参数。本轮子手动算 scrollTop，行为可预期。 */
        _srLocateMax: 15,          // 最多回溯 15 页（≈450 条）
        locateMsg: function (msgId, opt) {
            var self = this;
            opt = opt || {};
            setTimeout(function () { self._locateStep(msgId, 0, opt); }, 60);
        },

        _locateStep: function (msgId, page, opt) {
            var self = this, box = $('owMessages');
            if (!box) return;
            var node = $('owMsg' + msgId);
            if (node) { this._locateFocus(node, opt); return; }
            var fail = function () { toast(opt.tip || '未能定位到该消息'); };
            if (page >= this._srLocateMax || this.historyDone) { fail(); return; }
            var first = box.querySelector('.ow-msg');
            if (!first) { fail(); return; }
            var firstId = parseInt(first.id.replace('owMsg', ''), 10);
            if (firstId <= msgId) { fail(); return; }     // 已到顶，这条不存在
            var isDm = !!this.dm;
            var peer = isDm ? this.dm.peer : '';
            OwApi.post(isDm ? 'dm_history' : 'history',
                isDm ? { peer: peer, before_id: firstId } : { room_id: this.room, before: firstId },
                function (r) {
                    // 已切走 / 已切私聊 → 丢弃，别把别处的消息插进当前视图
                    if ((!!self.dm) !== isDm || (isDm && self.dm.peer !== peer)) return;
                    if (!r.ok || !r.data.length) { self.historyDone = true; self._locateStep(msgId, 99, opt); return; }
                    var oldH = box.scrollHeight, i;
                    for (i = r.data.length - 1; i >= 0; i--) self.addMessageBefore(r.data[i], first);
                    box.scrollTop = box.scrollHeight - oldH;    // 保持视口不跳
                    if (r.data.length < 30) self.historyDone = true;
                    self._locateStep(msgId, page + 1, opt);
                });
        },

        /** 滚动居中 + 高亮 +（可选）弹操作菜单 */
        _locateFocus: function (node, opt) {
            var self = this, box = $('owMessages');
            var r = node.getBoundingClientRect(), br = box.getBoundingClientRect();
            box.scrollTop += (r.top - br.top) - (box.clientHeight / 2) + (r.height / 2);
            setTimeout(function () {
                var rr = node.getBoundingClientRect();
                node.className += ' ow-msg-hit';
                setTimeout(function () {
                    node.className = node.className.replace(' ow-msg-hit', '');
                }, 2400);
                if (!opt.menu) return;
                // ⚠️ 必须在此闭包外抓 self：setTimeout 回调里的 this 是 undefined
                //（本文件是严格模式），写 this.msgCache 会抛
                // 「Cannot read properties of undefined」并中断后面的菜单弹出。
                var m = self.msgCache[parseInt(node.id.replace('owMsg', ''), 10)];
                if (m) self.showContentMenu(Math.round(rr.left + Math.min(rr.width, 360)), Math.round(rr.top + 8), m);
            }, 60);
        },

        /** 跳到指定消息（搜索结果用：定位后顺带弹操作菜单） */
        jumpToMsg: function (d) {
            this.locateMsg(d.msg_id, { menu: true });
        },

        /**
         * 把菜单开在某个触发按钮的旁边。
         * 横向默认开在按钮**右侧**（rail 贴最左，只能往右）；触发点在右半边时自动翻过去
         * —— 不翻的话整张菜单会掉出视口右沿。
         * ⚠️ 翻过去时取「菜单右沿 = 触发点右沿」，不是「菜单摆在触发点左边留 8px 缝」：
         *   后者实测把 136px 宽的菜单推到触发点左沿之外（触发点 left 1275、菜单 right 1267），
         *   整张菜单离它的触发点 120px 远，看着像从聊天区标题里长出来的（v1.3.145 站方截图）。
         *   右沿对齐才是下拉菜单的常规接法，且天然不会掉出视口。
         * 竖向：align='bottom'（rail 底部那颗 ☰，也是目前唯一的调用）菜单往上长、
         * 底边与按钮底边齐平；align='top' 是「从触发点往下长」的接法，留给将来
         * 顶栏/品牌条上的入口用（v1.5.0 起没有调用点，品牌条那颗胶囊已搬进账号菜单）。
         */
        _ctxOpenAt: function (items, from, anchor, align) {
            var menu = $('owCtxMenu');
            if (!menu || !anchor) return;
            this._ctxItems = items;
            menu._from = from;
            menu.innerHTML = ctxItemsHtml(items);
            menu.style.display = 'block';
            var r = anchor.getBoundingClientRect();
            var vw = w.innerWidth || document.documentElement.clientWidth;
            var vh = w.innerHeight || document.documentElement.clientHeight;
            /* v1.3.152：兜底行高回到 34 —— v1.3.146 收到 26px 的那笔已按站方要求撤回，
               菜单行高仍是 32.8px（这个常量只在 offsetHeight 量不到时兜底）。 */
            var mh = menu.offsetHeight || items.length * 34;
            var mw = menu.offsetWidth || 160;
            var top = align === 'top' ? r.bottom + 4 : r.bottom - mh;
            if (top < 8) top = 8;
            if (top + mh > vh - 8) top = Math.max(8, vh - 8 - mh);
            var left = r.right + 8;
            if (left + mw > vw - 8) left = r.right - mw;
            if (left < 8) left = Math.max(8, vw - mw - 8);
            menu.style.left = left + 'px';
            menu.style.top = top + 'px';
        },

        /**
         * rail 底部那颗账号按钮的菜单（v1.3.134 建，v1.5.0 重做首行）。
         *
         * 首行 = 用户胶囊（头像 + 昵称，点开自己的资料卡）—— 站方要求把品牌条右上角
         * 那颗胶囊搬到这里；下一条分隔线把它和操作项隔开，再往下才是
         * 创建群聊 / 设置 / 管理后台 / 退出登录。
         *
         * 游客也走这里（v1.5.0）：他们没有任何账号项，菜单里只有「登录 / 注册」两项
         * ——胶囊从品牌条搬走后，这就是游客唯一的登录入口。
         * ⚠️ 首行不做 hover 变色（见 CSS .ow-ctx-head），但**必须留 data-i**：
         *    点击委托靠下标反查 _ctxItems，sep 也要占一位，所以下面用 push 的顺序建数组。
         */
        toggleAccountMenu: function () {
            var self = this, me = this.cfg.me, menu = $('owCtxMenu');
            if (!menu) return;
            // 再次点击同一个按钮 = 收起
            if (menu.style.display !== 'none' && menu._from === 'account') { this.hideCtxMenu(); return; }
            var items = [];
            if (me && me.id) {
                items.push({
                    head: true,
                    aria: '个人主页',
                    html: this.meChipHtml(),
                    run: function () { self.userCard(me.id, me.nickname); }
                });
                items.push({ sep: true });
                items.push({ t: '创建群聊', run: function () { self.roomCreateModal(); } });
                items.push({ t: '设置', run: function () { self.openSettings(); } });
                if (this.cfg.actor.role === 'admin') items.push({ t: '管理后台', run: function () { location.href = '?page=admin'; } });
                items.push({ t: '退出登录', run: function () {
                    self.confirmModal('确定退出登录吗？', function () {
                        OwApi.secure('logout', {}, function () { location.href = '?page=login'; });
                    });
                } });
            } else {
                items.push({ t: '登录', icon: 'user', run: function () { location.href = '?page=login'; } });
                items.push({ t: '注册', icon: 'plus', run: function () { location.href = '?page=register'; } });
            }
            this._ctxOpenAt(items, 'account', $('owRailMenu'), 'bottom');
        },

        /** @deprecated v1.5.0 起游客的登录 / 注册并入 toggleAccountMenu，保留别名给旧调用 */
        toggleAuthMenu: function () { this.toggleAccountMenu(); },

        /* ---------- 日夜模式（v1.3.25） ---------- */
        /**
         * 主题偏好：'light' | 'dark' | 'auto'（跟随系统）。
         *
         * 关键设计：**auto 由 JS 解析，CSS 只认 light / dark**。
         * 若让 CSS 认识 auto，深色变量就要在 `[data-theme="dark"]` 与
         * `@media (prefers-color-scheme: dark)` 里各写一份 —— 两份必然漂移。
         * 由 JS 用 matchMedia 判定后落成具体值，CSS 只需一份深色变量，
         * 还能顺带监听系统主题变化实时切换（CSS 方案做不到这点）。
         */
        themeKey: 'owl_theme',
        getTheme: function () {
            var v = '';
            try { v = w.localStorage.getItem(this.themeKey) || ''; } catch (e) {}
            // 兼容：旧版本或异常值一律回落 auto
            return (v === 'light' || v === 'dark' || v === 'auto') ? v : 'auto';
        },
        /** 系统是否处于深色（不支持 matchMedia 的老浏览器返回 false） */
        sysPrefersDark: function () {
            return !!(w.matchMedia && w.matchMedia('(prefers-color-scheme: dark)').matches);
        },
        /**
         * 应用主题：把偏好落成 light / dark 写到 <html data-theme>。
         * @param {string} mode 'light'|'dark'|'auto'
         * @param {boolean} persist 是否写入 localStorage（首屏应用时不写）
         */
        applyTheme: function (mode, persist) {
            var real = (mode === 'auto') ? (this.sysPrefersDark() ? 'dark' : 'light') : mode;
            // ⚠️ 用 document 而不是 IIFE 的 d：本文件里有多处 `var d = new Date(...)` /
            // `var d = OW_ICONS[...]`，一旦本函数内出现同名局部变量就会遮蔽外层 d，
            // 且 'use strict' 下会直接抛错 —— 症状是"点了没反应"。
            var el = document.documentElement;
            if (el.getAttribute('data-theme') !== real) el.setAttribute('data-theme', real);
            // color-scheme 让浏览器原生控件（滚动条、表单、::selection）跟着变，
            // 否则深色页面会配一条亮色滚动条 —— 很扎眼。
            el.style.colorScheme = real;
            if (persist) {
                try { w.localStorage.setItem(this.themeKey, mode); } catch (e) {}
            }
        },
        /** 初始化：应用已存偏好 + 监听系统主题变化（仅 auto 时生效） */
        initTheme: function () {
            var self = this;
            this.applyTheme(this.getTheme(), false);
            // v1.3.132：字号档同样是本机偏好，跟着一起落地（不写回 localStorage）
            this.applyFont(this.getFont(), false);
            if (w.matchMedia) {
                var mq = w.matchMedia('(prefers-color-scheme: dark)');
                // ⚠️ 用 addListener 兼容老 Safari(<14)，它没有 addEventListener 版 API
                var onChange = function () {
                    if (self.getTheme() === 'auto') self.applyTheme('auto', false);
                };
                if (mq.addEventListener) mq.addEventListener('change', onChange);
                else if (mq.addListener) mq.addListener(onChange);
            }
        },
        /** 渲染日夜模式三选一（放进设置弹窗） */
        themeSegHtml: function () {
            var cur = this.getTheme();
            var opts = [
                ['light', '浅色', '<circle cx="12" cy="12" r="4.2"/><path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.2 5.2l1.4 1.4M17.4 17.4l1.4 1.4M18.8 5.2l-1.4 1.4M6.6 17.4l-1.4 1.4"/>'],
                ['dark', '深色', '<path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5z"/>'],
                ['auto', '跟随系统', '<rect x="2.5" y="4" width="19" height="12.5" rx="1.6"/><path d="M8.5 20.5h7M12 16.5v4"/>']
            ];
            var h = '<div class="ow-seg" id="owThemeSeg">';
            for (var i = 0; i < opts.length; i++) {
                var o = opts[i];
                h += '<button type="button" class="ow-seg-btn' + (cur === o[0] ? ' is-active' : '') + '"'
                   + ' data-theme-mode="' + o[0] + '">'
                   + '<svg class="ow-ico" width="14" height="14" viewBox="0 0 24 24" fill="none"'
                   + ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
                   + ' aria-hidden="true">' + o[2] + '</svg>'
                   + o[1] + '</button>';
            }
            h += '</div>';
            return h;
        },
        /** 绑定分段控件的点击（设置弹窗打开后调用） */
        bindThemeSeg: function () {
            var self = this, box = $('owThemeSeg');
            if (!box) return;
            var btns = box.getElementsByTagName('button'), i;
            for (i = 0; i < btns.length; i++) {
                (function (btn) {
                    btn.onclick = function () {
                        var mode = btn.getAttribute('data-theme-mode');
                        self.applyTheme(mode, true);
                        var all = box.getElementsByTagName('button'), k;
                        for (k = 0; k < all.length; k++) {
                            var on = all[k].getAttribute('data-theme-mode') === mode;
                            if (all[k].classList) all[k].classList[on ? 'add' : 'remove']('is-active');
                            else all[k].className = on ? 'ow-seg-btn is-active' : 'ow-seg-btn';
                        }
                    };
                })(btns[i]);
            }
        },

        /* ---------- 本机偏好：字号 / 语言（v1.3.132） ----------
           与日夜模式、色系同一类：**不随「保存」提交**（存 localStorage / cookie，点了立即生效）。
           混进保存按钮会让「改个字号还要点保存」显得莫名其妙。 */
        fontKey: 'owl_fontsize',
        FONTS: [
            { v: 'site', b: 'A', t: '站点原始字号' },
            { v: 'compact', b: 'A', t: '紧凑' },
            { v: 'standard', b: 'A', t: '标准' },
            { v: 'comfortable', b: 'A', t: '舒适' }
        ],
        getFont: function () {
            var v = '';
            try { v = w.localStorage.getItem(this.fontKey) || ''; } catch (e) {}
            for (var i = 0; i < this.FONTS.length; i++) if (this.FONTS[i].v === v) return v;
            return 'site';
        },
        /** 应用字号档：site = 不写属性，等于完全跟随站点自己的字号 */
        applyFont: function (v, persist) {
            var el = document.documentElement;
            if (!v || v === 'site') el.removeAttribute('data-fontsize');
            else el.setAttribute('data-fontsize', v);
            if (!persist) return;
            try {
                if (v && v !== 'site') w.localStorage.setItem(this.fontKey, v);
                else w.localStorage.removeItem(this.fontKey);
            } catch (e) {}
        },
        fontPick: function (card) {
            var v = card.getAttribute('data-val');
            this.applyFont(v, true);
            this.paintGrid(card.parentNode, v);
        },
        /** 选项卡片组的选中态：只加减 is-active（借 owTabs.set，整体重写会抹掉插件自加的类） */
        paintGrid: function (grid, v) {
            if (!grid) return;
            for (var i = 0; i < grid.children.length; i++) {
                var c = grid.children[i];
                if (c.getAttribute && c.getAttribute('data-val') !== null) {
                    owTabs.set(c, c.getAttribute('data-val') === v);
                }
            }
        },
        /** 通用选项卡片组 HTML（结构与配色见 index.css 的 .ow-optgrid） */
        optGridHtml: function (opts, cur, handler) {
            var h = '<div class="ow-optgrid">';
            for (var i = 0; i < opts.length; i++) {
                h += '<button type="button" class="ow-optcard' + (opts[i].v === cur ? ' is-active' : '')
                    + '" data-val="' + esc(opts[i].v) + '" onclick="' + handler + '(this)">'
                    + (opts[i].b ? '<b>' + esc(opts[i].b) + '</b>' : '')
                    + '<span>' + esc(opts[i].t) + '</span></button>';
            }
            return h + '</div>';
        },
        /**
         * 语言档：跟随站点默认 / 简体中文 / 各已安装语言包。
         * 「跟随站点默认」= 删掉 ow_lang cookie，让 index.php 的 ow_lang() 回落到后台默认语言。
         * 没装任何语言包时整组不渲染（与第三方授权入口同一套按需渲染思路）。
         */
        langGridHtml: function () {
            var packs = (w.OwI18n && OwI18n.list) ? OwI18n.list() : [];
            if (!packs.length) return '';
            var cur = '';
            try {
                var mm = /(?:^|; )ow_lang=([^;]*)/.exec(document.cookie);
                cur = mm ? decodeURIComponent(mm[1]) : '';
            } catch (e) {}
            var opts = [{ v: 'site', t: '跟随站点默认' }, { v: 'zh', t: '简体中文' }];
            for (var i = 0; i < packs.length; i++) opts.push({ v: packs[i].code, t: packs[i].label });
            return '<div class="ow-form-item"><label>语言</label>'
                + this.optGridHtml(opts, cur, 'OwChat.langPick')
                + '<p class="ow-form-hint">只影响你自己的界面；改完会刷新一次页面，服务端文案才跟着换。</p>'
                + '</div>';
        },
        langPick: function (card) {
            var v = card.getAttribute('data-val');
            // 与登录页右上角切换器共用同一份 cookie（100 天）。必须刷新：
            // 通知、报错这类服务端文案是按 <body data-lang> 输出的，不刷新只有前端会翻。
            try {
                document.cookie = (v === 'site')
                    ? 'ow_lang=;path=/;max-age=0;SameSite=Lax'
                    : 'ow_lang=' + encodeURIComponent(v) + ';path=/;max-age=8640000;SameSite=Lax';
            } catch (e) {}
            this.paintGrid(card.parentNode, v);
            w.location.reload();
        },

        openSettings: function () {
            var me = this.cfg.me;
            if (!me) return;
            // v1.3.115：分组切换改成**左侧导航 + 右侧内容**（站方指定的版式，与后台同一类布局），
            // 用的是核心通用轮子 owTabs 的导航形态 .ow-navset；配色全部走本站 --ow-* 令牌。
            // ⚠️ 保存按钮**留在导航之外常驻**：它提交的是全部字段（插件注入的签名等
            // 也挂在 saveSettings 上），放进某个分组里会让「在账号页点不到保存」变成新问题。
            this.openModal(
                '<h3>个人设置</h3>'
                + '<div class="ow-navset">'
                + '<div class="ow-navset-nav" id="owSetTabs" onclick="owTabs.click(this, event)">'
                + owTabs.navHtml('prof', '资料', 'user')
                + owTabs.navHtml('look', '外观', 'image')
                + owTabs.navHtml('acct', '账号', 'shield')
                + '</div>'
                + '<div class="ow-pagetab-panel" data-pane="prof">'
                // 头像置顶：点击当前头像即触发上传（不另设上传按钮）
                + '<div class="ow-set-avatar">'
                + '<span id="owSetAvatarPreview" class="ow-set-avatar-btn" title="点击设置头像" onclick="OwChat.openAvatarDialog(\'me\')">'
                + avatarHtml(me.avatar, me.nickname, 'lg', me.role) + '</span>'
                + '<input type="file" id="owSetAvatarFile" accept="image/*" style="display:none">'
                + '</div>'
                + '<div class="ow-form-item"><label>昵称</label><input class="ow-input" id="owSetNick" value="' + esc(me.nickname) + '">'
                + '<p style="font-size:12px;color:var(--ow-text-sub);margin-top:4px">2-20 个字符，支持中英文、数字、下划线与短横线，不含空格或 @；允许重名。</p></div>'
                + '</div>'
                + '<div class="ow-pagetab-panel" data-pane="look" style="display:none">'
                // v1.2.37：第三方应用授权已剥离为插件 oauth-core，**按需加载**。
                // 入口按钮按 window.OwOauth 是否存在来渲染 —— 插件停用时它的 chat.js
                // 不加载，这里自然什么都不显示，核心不需要任何开关判断。
                // v1.3.25：日夜模式三选一（浅色 / 深色 / 跟随系统）。
                // 主题是**本地偏好**，不随「保存」提交 —— 点了立即生效更符合直觉，
                // 混进保存会让「改个主题还要点保存」显得莫名其妙。
                // 这一页是「本机外观偏好」的落点：色系插件按 #owThemeSeg 锚点插到日夜模式后面，
                // 聊天背景插件把自己的区块 append 到这一页（两个都是插件侧的接缝）。
                + '<div class="ow-form-item"><label>日夜模式</label>'
                + this.themeSegHtml()
                + '<p class="ow-form-hint">跟随系统会随操作系统的深浅色设置自动切换。</p>'
                + '</div>'
                + '<div class="ow-form-item"><label>字体大小</label>'
                + this.optGridHtml(this.FONTS, this.getFont(), 'OwChat.fontPick')
                + '<p class="ow-form-hint">只调消息正文、输入框和两侧列表这几处；'
                + '「站点原始字号」= 不启用本偏好。只影响这台浏览器，不随「保存」提交。</p>'
                + '</div>'
                + this.langGridHtml()
                + '</div>'
                + '<div class="ow-pagetab-panel" data-pane="acct" style="display:none">'
                + ((w.OwOauth) ? '<div class="ow-form-item"><label>第三方授权</label>'
                    + '<button class="ow-btn ow-btn-ghost ow-btn-block" onclick="OwOauth.open()">管理应用授权</button>'
                    + '<p style="font-size:12px;color:var(--ow-text-sub);margin-top:4px">'
                    + '把资料、聊天记录等权限授予你安装的插件应用；随时可在此取消。</p></div>' : '')
                // v1.3.55：改密码入口。单独一个弹窗而不是塞进这个表单 ——
                // 「保存资料」和「改密码」是两回事，混在一个提交按钮里迟早出现
                // 「改了昵称顺手把密码框当空值提交」这类事故。
                // 提示语必须是**一整句静态文案**：OwTip 把 <p> 的 textContent 整段搬进气泡，
                // 拼进邮箱就变成一个独一无二的字符串，语言包的精确匹配必然落空。
                // 所以邮箱单独一行（.ow-set-mail），键与值各自成节点。
                + '<div class="ow-form-item"><label>账号安全</label>'
                + '<button class="ow-btn ow-btn-ghost ow-btn-block" onclick="OwChat.openPwdDialog()">修改登录密码</button>'
                + '<p class="ow-form-hint">' + (me.email
                        ? '需要当前密码与邮箱验证码；改完当前设备不会掉线。'
                        : '账号还没有可用邮箱，本次只验证当前密码。') + '</p>'
                + (me.email ? '<div class="ow-set-mail"><span>账号邮箱</span><b>' + esc(owMaskEmail(me.email)) + '</b></div>' : '')
                // v1.3.132：改登录邮箱的能力在「邮箱验证」插件里（它才有发码通道与限流）。
                // 插件停用/没装时它的 chat.js 不加载，这里自然什么都不显示 —— 核心不需要开关判断。
                + (w.OwEvEmail ? '<button class="ow-btn ow-btn-ghost ow-btn-block" style="margin-top:8px"'
                    + ' onclick="OwEvEmail.open()">修改登录邮箱</button>'
                    + '<p class="ow-form-hint">改完要用新邮箱收一次验证码；下次登录起用新邮箱或用户 ID 都可以。</p>' : '')
                + '</div>'
                + '</div>'
                + '</div>'
                + '<button class="ow-btn ow-btn-primary ow-btn-block" onclick="OwChat.saveSettings()">保存</button>',
                // 第三个参数锁死整窗高度：三页内容差得多，不锁就会点一下标签胀一截
                null, 'ow-modal-fixed'
            );
            var self = this;
            this.bindThemeSeg();   // v1.3.25：日夜模式分段控件
            $('owSetAvatarFile').onchange = function () {
                if (!this.files || !this.files[0]) return;
                // 选完图不直接上传：先进入裁剪弹窗，由滑块手动缩放后再导出
                self.avatarCrop(this.files[0]);
                this.value = '';
            };
            // 打开时归位到「资料」：选中态只由 owTabs.show 一处负责，不在 HTML 里预写 active，
            // 两处各写一份迟早出现「面板开着、标签没高亮」（v1.3.112 就漏过一次）。
            var bar = $('owSetTabs');
            if (bar) owTabs.show(bar, 'prof');
        },

        /* ---------- 修改登录密码（v1.3.55） ----------
           叠在个人设置之上的第二层浮层复用裁剪那套 overlay（openCropOverlay）：
           它已经处理了遮罩、关闭按钮与 z-index（110 压在普通弹窗 100 之上），
           再造一份只是多一个会漂移的层。
           验证码这一栏是否出现由服务端下发的 cfg.mail_chpwd 决定 —— 判定口径
           （总开关 + 账号有可用邮箱）在 Auth::changePassword 里，前端只是跟着渲染。 */
        openPwdDialog: function () {
            var me = this.cfg.me;
            if (!me) return;
            var need = String(this.cfg.mail_chpwd) === '1';
            var h = '<h3>修改登录密码</h3>'
                + '<div class="ow-form-item"><label>当前密码</label>'
                + '<input class="ow-input" type="password" id="owPwdCur" autocomplete="current-password"></div>'
                + '<div class="ow-form-item"><label>新密码</label>'
                + '<input class="ow-input" type="password" id="owPwdNew" autocomplete="new-password" placeholder="至少 6 位"></div>'
                + '<div class="ow-form-item"><label>确认新密码</label>'
                + '<input class="ow-input" type="password" id="owPwdNew2" autocomplete="new-password"></div>';
            if (need) {
                h += '<div class="ow-form-item"><label>邮箱验证码</label>'
                    + '<div class="ow-captcha-row"><input class="ow-input" id="owPwdCode" maxlength="6" inputmode="numeric" autocomplete="one-time-code">'
                    + '<button type="button" class="ow-btn ow-btn-ghost" id="owPwdSend" onclick="OwChat.sendPwdCode()">发验证码</button></div>'
                    + '<p class="ow-form-hint">验证码发到账号绑定的邮箱，有效期内只能用一次。</p>'
                    + '<div class="ow-set-mail"><span>发送至</span><b>' + esc(owMaskEmail(me.email)) + '</b></div>'
                    + '</div>';
            }
            h += '<div class="ow-modal-actions">'
                + '<button class="ow-btn ow-btn-ghost" onclick="OwChat.closeCropOverlay()">取消</button>'
                + '<button class="ow-btn ow-btn-primary" onclick="OwChat.savePwd()">确认修改</button></div>'
                + '<div class="ow-form-msg" id="owPwdMsg"></div>';
            this.openCropOverlay(h);
        },

        /** 发码：收件地址由服务端按当前会话取，前端不传邮箱（传了也不会被用） */
        sendPwdCode: function () {
            var btn = $('owPwdSend'), msg = $('owPwdMsg'), self = this;
            if (!btn) return;
            btn.disabled = true;
            OwApi.post('send_code', { type: 'chpwd' }, function (r) {
                if (msg) msg.innerHTML = '<span style="color:' + (r.ok ? 'var(--ow-green)' : 'var(--ow-red)') + '">' + esc(r.msg) + '</span>';
                if (!r.ok) { btn.disabled = false; return; }
                // 倒计时秒数由服务端给（后台能把重发间隔改成非 60），写死 60 会出现
                // 「按钮转完了、服务端还说太频繁」的死循环。
                var n = parseInt(r.wait, 10);
                if (r.remain > 0) n = Math.max(1, parseInt(r.remain, 10));
                if (!(n > 0)) n = 60;
                var tm = setInterval(function () {
                    btn.innerHTML = n + 's';
                    if (--n < 0) { clearInterval(tm); btn.disabled = false; btn.innerHTML = '发验证码'; }
                }, 1000);
            });
        },

        savePwd: function () {
            var msg = $('owPwdMsg'), self = this;
            var fail = function (t) { if (msg) msg.innerHTML = '<span style="color:var(--ow-red)">' + esc(t) + '</span>'; };
            var cur = ($('owPwdCur') || {}).value || '';
            var nw = ($('owPwdNew') || {}).value || '';
            var nw2 = ($('owPwdNew2') || {}).value || '';
            if (!cur) { fail('请填写当前密码'); return; }
            if (nw.length < 6) { fail('新密码至少 6 位'); return; }
            if (nw !== nw2) { fail('两次输入的新密码不一致'); return; }
            if (nw === cur) { fail('新密码不能与当前密码相同'); return; }
            if (msg) msg.innerHTML = '提交中…';
            // 改密码是敏感操作：走一次性票据（OwApi.secure），与登出同档
            OwApi.secure('change_password', {
                current: cur, password: nw,
                code: ($('owPwdCode') || {}).value || ''
            }, function (r) {
                if (!r.ok) { fail(r.msg || '修改失败'); return; }
                if (msg) msg.innerHTML = '<span style="color:var(--ow-green)">密码已修改</span>';
                toast('密码已修改');
                // 关掉浮层时密码值随 DOM 一起消失（这些输入框只存在于浮层里）
                setTimeout(function () { self.closeCropOverlay(); }, 700);
            });
        },

        /* ---------- 头像裁剪：滑块手动缩放 + 圆形取景 ---------- */

        /** 裁剪目标边长（与服务端 Upload::AVATAR_SIZE 保持一致） */
        avatarCropSize: 100,

        /**
         * 打开裁剪弹窗：圆形取景框内即最终头像，拖动滑块缩放图片。
         * @param File file 用户选择的原始图片
         */
        /**
         * 独立裁剪浮层（参考论坛 dialog 方案）：不复用 owModal——
         * 裁剪时编辑弹窗 / 后台表单保持完好，裁剪完直接回填预览。
         */
        openCropOverlay: function (html) {
            this.closeCropOverlay();
            var mask = document.createElement('div');
            mask.className = 'ow-modal-mask';
            mask.id = 'owCropMask';
            mask.style.zIndex = '110';   // 盖在普通弹窗（z100）之上
            mask.innerHTML = '<div class="ow-modal" id="owCropModal">'
                + '<button class="ow-modal-close" onclick="OwChat.closeCropOverlay()">✕</button>' + html + '</div>';
            document.body.appendChild(mask);
        },

        closeCropOverlay: function () {
            var m = $('owCropMask');
            if (m && m.parentNode) m.parentNode.removeChild(m);
        },

        avatarCrop: function (file) {
            this._cropTarget = 'me';
            this._cropRoom = 0;
            this.cropForTarget(file);
        },

        /**
         * 群聊头像裁剪：复用同一裁剪弹窗，保存时上传到群聊头像。
         * rid 记到 _cropRoom：裁剪是异步的（等选图、等浮层里点「确定」），
         * 真到上传那一步时已经没有别的地方知道这张图是给哪个群的。
         */
        roomAvatarCrop: function (file, rid) {
            this._cropTarget = 'room';
            this._cropRoom = parseInt(rid || this.room || 0, 10);
            this.cropForTarget(file);
        },

        /** 按 _cropTarget 走裁剪流程（me=个人头像 / room=群聊头像） */
        cropForTarget: function (file) {
            var self = this;
            if (!w.FileReader || !document.createElement('canvas').getContext) {
                // 老浏览器无裁剪能力：退回直接上传，由服务端兜底裁方形
                if (this._cropTarget === 'room') self.roomAvatarUpload(file);
                else self.avatarUpload(file);
                return;
            }
            var reader = new FileReader();
            reader.onload = function (ev) {
                self.openCropOverlay(
                    '<h3>调整头像</h3>'
                    + '<div class="ow-crop-wrap"><canvas id="owCropCanvas" width="200" height="200"></canvas></div>'
                    + '<div class="ow-crop-ctrl">'
                    + '<input type="range" id="owCropZoom" min="1" max="3" step="0.01" value="1">'
                    + '<span class="ow-crop-val" id="owCropVal">100%</span>'
                    + '</div>'
                    + '<div class="ow-modal-actions">'
                    + '<button class="ow-btn ow-btn-ghost" onclick="OwChat.avatarCropCancel()">取消</button>'
                    + '<button class="ow-btn ow-btn-primary" onclick="OwChat.avatarCropSave(this)">确定</button></div>'
                );
                var canvas = $('owCropCanvas'), zoom = $('owCropZoom'), val = $('owCropVal');
                var ctx = canvas.getContext('2d');
                var SIZE = canvas.width;                 // 200：取景框即 canvas 本身
                var img = new Image();
                var draw = function () {
                    if (!img.width || !ctx) return;
                    ctx.clearRect(0, 0, SIZE, SIZE);
                    ctx.fillStyle = '#fff';              // 白底：透明区转 jpg 不返黑
                    ctx.fillRect(0, 0, SIZE, SIZE);
                    // cover 基准：铺满画布所需最小缩放；滑块在此基础上 1~3 倍
                    var base = Math.max(SIZE / img.width, SIZE / img.height);
                    var z = parseFloat(zoom.value);
                    if (!isFinite(z) || z < 1) z = 1;
                    var s2 = base * z;
                    var dw = img.width * s2, dh = img.height * s2;
                    ctx.drawImage(img, (SIZE - dw) / 2, (SIZE - dh) / 2, dw, dh);
                    val.textContent = Math.round(z * 100) + '%';
                };
                img.onload = function () { draw(); };
                img.src = String(ev.target.result);
                zoom.oninput = draw;
                zoom.onchange = draw;                    // 老浏览器无 input 事件时兜底
            };
            reader.readAsDataURL(file);
        },

        /**
         * 当前会话是否拥有「删除他人消息」的权限（v1.1.14）。
         * 口径与服务端 deleteMessage() 完全一致：超级管理员 + 本群群主。
         * 取自 rooms() 下发的 can_edit（其定义就是「群主 + 超管」），私聊无 can_edit → false。
         * ⚠️ 不用 cfg.actor.role 在这里另判一套，避免前后台口径漂移。
         */
        canRemoveOthers: function () {
            if (this.dm || !this.room) return false;
            var list = this.cfg.rooms || [];
            for (var i = 0; i < list.length; i++) {
                if (list[i].id === this.room) return !!list[i].can_edit;
            }
            return false;
        },

        /**
         * 右键「消息内容」的菜单：复制 / 引用 / 撤回 / 删除。
         * 插件可通过 OwChat.onMsgContent 追加项（如翻译、举报、复制原文…）。
         *
         * v1.2.4 语义彻底对调（勿回退）：
         *   - **撤回** = 真正的删除，**全局生效**（所有人都不再看到），需满足撤回条件
         *     （自己发的 5 分钟内，或群主 / 超管处理违规内容）；
         *   - **删除** = 一律只在本机隐藏，**任何身份都是**（含超级管理员），
         *     别人照常看得到、换设备不生效。
         *
         * 因此删除入口**无条件对所有已登录用户开放**（不再判 canRemove）：
         * 它只是「我不想看这条」的私人视图行为，不涉及他人，故不存在越权问题。
         * 需要清除内容时走「撤回」——那条才有权限与时效约束。
         */
        showContentMenu: function (x, y, m) {
            var self = this, admin = this.cfg.actor.role === 'admin', items = [];
            if (!m.recalled && !m.deleted) {
                items.push({ t: '复制', run: function () { self.copyMsg(m); } });
                if (m.type === 'image' && this.cfg.actor.kind === 'user')
                    items.push({ t: '收藏为贴纸', run: function () { self.collect(m.content); } });
                if (this.cfg.actor.kind !== 'none')
                    items.push({ t: '引用', run: function () { self.quoteMsg(m); } });
                // 撤回 = 全局真删除，仅在满足条件时给入口（服务端还会再判一次）
                if (m.mine || admin || this.canRemoveOthers())
                    items.push({ t: '撤回', run: function () { self.recall(m.id); } });
                // 删除 = 本机隐藏，仅对已登录用户有意义：游客身份不落库，删了刷新就没
                if (this.cfg.actor.kind === 'user')
                    items.push({ t: '删除', run: function () { self.deleteMsg(m.id); } });
                for (var i = 0; i < this._ctxExtContent.length; i++) {
                    try { this._ctxExtContent[i](items, m, { roomId: this.room, actor: this.cfg.actor }); } catch (e) {}
                }
            }
            this._ctxItems = items;
            if (!items.length) return;
            var menu = $('owCtxMenu');
            menu._from = 'msg';
            menu.innerHTML = ctxItemsHtml(items);
            menu.style.display = 'block';
            var vw = w.innerWidth || document.documentElement.clientWidth, vh = w.innerHeight || document.documentElement.clientHeight;
            var mw = menu.offsetWidth || 140, mh = menu.offsetHeight || items.length * 32;
            menu.style.left = Math.max(4, x + mw > vw ? x - mw : x) + 'px';
            menu.style.top = Math.max(4, y + mh > vh ? y - mh : y) + 'px';
        },

        /** 插件扩展点：右键消息「内容」时追加菜单项 */
        onMsgContent: function (fn) { if (typeof fn === 'function') this._ctxExtContent.push(fn); },

        /** 复制消息内容（图片/文件/混排消息复制其可读文本） */
        copyMsg: function (m) {
            var text = m.type === 'file' ? (function () {
                try { return JSON.parse(m.content).name || m.content; } catch (e) { return m.content; }
            })() : m.type === 'rich' ? (function () {
                // 混排消息复制的是**文字那一段**；只有图没文字时复制占位说明，
                // 把 JSON 塞进剪贴板对用户没有任何用处
                var p = richParts(m);
                return p.text !== '' ? p.text : '[图片]';
            })() : m.content;
            text = String(text || '');
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.cssText = 'position:fixed;left:-9999px;top:0';
            document.body.appendChild(ta);
            ta.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(ta);
            toast(ok ? '已复制' : '复制失败，请手动选择文本');
        },

        /**
         * 引用消息：在输入框上方生成引用条（可点 × 取消）；发送时随消息提交。
         * 触发 msg.quote 钩子，插件可改写引用内容。
         */
        quoteMsg: function (m) {
            var text = m.type === 'image' ? '[图片]' : (m.type === 'file' ? (function () {
                try { return '[文件] ' + (JSON.parse(m.content).name || ''); } catch (e) { return '[文件]'; }
            })() : m.type === 'rich' ? (function () {
                // 引用条只有一行：混排消息引用时显示「文字 + 一张图的标记」，
                // 把整段 JSON 或全部图片塞进去会把引用条撑破。
                // 不写「×N」—— 与会话列表摘要（Chat::convText）同一口径：
                // 掺进数字的串语言包按整句匹配不上，英文界面只会多出半句中文。
                var p = richParts(m);
                return p.text !== '' ? p.text + (p.media.length ? ' [图片]' : '') : '[图片]';
            })() : String(m.content || ''));
            var q = { nick: m.nickname || '', text: text, id: m.id || 0 };
            for (var i = 0; i < this._quoteExt.length; i++) {
                try { this._quoteExt[i](q, m); } catch (e) {}
            }
            this.quote = { nick: String(q.nick || '').slice(0, 40), text: String(q.text || '').slice(0, 120), id: q.id || 0 };
            this.renderQuote();
            var input = $('owInput');
            if (input) input.focus();
        },

        /** 渲染 / 清除输入框上方的引用条 */
        renderQuote: function () {
            var box = $('owQuoteBar');
            if (!box) return;
            var q = this.quote;
            var input = $('owInput');
            // 有引用时输入框顶部留白，让引用条独占输入框内第一行
            if (input) {
                if (q && (q.nick || q.text)) input.className = 'ow-input ow-has-quote';
                else input.className = 'ow-input';
                this.autoGrow();   // padding 变化后重算高度
            }
            if (!q || (!q.nick && !q.text)) { this.quote = null; box.style.display = 'none'; box.innerHTML = ''; return; }
            box.style.display = 'block';
            box.innerHTML = '<div class="ow-quote-inner"><span class="ow-quote-nick">' + esc(q.nick) + '：</span>'
                + '<span class="ow-quote-text">' + esc(q.text) + '</span>'
                + '<button class="ow-quote-del" type="button" title="取消引用" onclick="OwChat.clearQuote()">✕</button></div>';
        },

        /** 取消引用 */
        clearQuote: function () { this.quote = null; this.renderQuote(); },

        /**
         * 触发群头像文件选择（群聊信息弹窗内的隐藏 input，v1.1.10）
         */
        /** 群头像点击：v1.3.11 起不再直接选文件，改走头像设置弹窗（上传 / 浏览插图 / 恢复默认） */
        roomAvatarPick: function () { this.openAvatarDialog({ room: this.room, name: this.roomName }); },

        /**
         * 某个群**当前渲染出来**的头像地址。
         * cfg.rooms[].avatar 就是服务端 roomAvatarUrl() 的产物（自定义/生成式/回落创建者 三种都已算好），
         * 所有「显示当前头像」的地方都该取它 —— `_roomAvatar` 存的是待提交的相对路径、
         * 空串表示本次不改，拿它去渲染会渲染出一张根本不存在的图（v1.3.135 漏过一次）。
         */
        roomAvatarOf: function (rid) {
            var list = this.cfg.rooms || [], i;
            rid = parseInt(rid || 0, 10);
            for (i = 0; i < list.length; i++) {
                if (parseInt(list[i].id, 10) === rid) return list[i].avatar || '';
            }
            return '';
        },

        /* ================= 头像设置（v1.3.11） =================
           点击头像不再是「直接打开文件选择」，而是先弹这个对话框：
             ① 上传头像 —— 仅当附件上传插件注册了 uploader 时出现（核心零上传入口）
             ② 浏览插图 —— 31 种 DiceBear 风格，点一下即保存
           target：'me'（本人）或 { room: roomId, name: 群名 }（群头像）。
        */
        _avatarUploader: null,   // 由附件上传插件经 registerAvatarUploader 注入

        /**
         * 插件注册上传实现：fn(file, filename, onOk, opts)
         *   opts.target  'me'（默认，服务端顺手写入当前用户头像）| 'room'（只存文件，谁的头像都不动）
         *   onOk(url, path)  url=渲染用的绝对地址，path=入库用的相对路径（room_update 只认这个）
         */
        registerAvatarUploader: function (fn) {
            if (typeof fn === 'function') this._avatarUploader = fn;
        },

        openAvatarDialog: function (target) {
            var self = this;
            var isRoom = (target && typeof target === 'object' && target.room);
            var rid = isRoom ? parseInt(target.room, 10) : 0;
            var cur = isRoom
                ? roomAvatarHtml(this.roomAvatarOf(rid), 'lg')
                : avatarHtml(this.cfg.me ? this.cfg.me.avatar : this.cfg.actor.avatar,
                    this.cfg.me ? this.cfg.me.nickname : this.cfg.actor.nickname, 'lg');

            var canUpload = !!this._avatarUploader;
            var h = '<div class="ow-av-dialog">'
                + '<div class="ow-av-current">' + cur + '<div class="ow-av-current-tip">当前头像</div></div>'
                + '<div class="ow-av-actions">'
                + (canUpload
                    ? '<button class="ow-btn ow-av-btn ow-av-btn-block" type="button" id="owAvUpload">' + owIco('image', 16) + '上传头像</button>'
                    : '')
                + '<button class="ow-btn ow-av-btn ow-av-btn-block" type="button" id="owAvGallery">' + owIco('smile', 16) + '浏览插图</button>'
                + (isRoom ? '<button class="ow-btn ow-av-btn ow-av-btn-block ow-btn-ghost" type="button" id="owAvReset">恢复默认（用创建者头像）</button>' : '')
                + '</div>'
                + '<div class="ow-av-styles" id="owAvStyles" style="display:none"></div>'
                + '</div>';
            // 'ow-modal-av'：这一弹窗的第一块是**居中**的头像，✕ 若还靠 float 就把它压偏，见 CSS
            this.openModal(h, 420, 'ow-modal-av');

            var applyUpload = function () {
                var f = document.createElement('input');
                f.type = 'file'; f.accept = 'image/*';
                f.onchange = function () {
                    if (!f.files || !f.files[0]) return;
                    // ⚠️ 群头像必须走 roomAvatarCrop —— 它把 _cropTarget 设成 'room'。
                    // 这里原先不分流，一律 avatarCrop，而 avatarCrop 内部硬设 'me'，
                    // 于是「给群换头像」把自己账号的头像一起换掉了（站方实测的正是这条）。
                    if (isRoom) self.roomAvatarCrop(f.files[0], rid);
                    else self.avatarCrop(f.files[0]);
                };
                f.click();
            };
            var up = $('owAvUpload'); if (up) up.onclick = applyUpload;
            var rs = $('owAvReset');
            if (rs) rs.onclick = function () {
                self.roomAvatarPost(rid, { avatar: '', avatar_type: 'default' });
            };
            var gal = $('owAvGallery');
            if (gal) gal.onclick = function () { self.openAvatarGallery(isRoom ? rid : 0); };
        },

        /**
         * 「浏览插图」—— 两级（v1.3.14）：
         *   一级：风格网格（31 种）。点一个风格**不再直接随机保存**，
         *         而是进入该风格的固定 45 个变体（同一套 ow-av-grid 样式）。
         *   二级：45 变体网格。点哪张就保存哪张（seed = 1..45，所见即所得）。
         */
        openAvatarGallery: function (rid, curStyle) {
            var self = this;
            var box = $('owAvStyles');
            if (!box) return;
            box.style.display = '';
            box.innerHTML = '<div class="ow-av-loading">加载中…</div>';

            var renderStyles = function () {
                OwApi.post('avatar_styles', {}, function (r) {
                    if (!box) return;
                    if (!r.ok || !r.data || !r.data.length) {
                        box.innerHTML = '<div class="ow-av-loading">风格加载失败，请稍后再试</div>';
                        return;
                    }
                    var h = '<div class="ow-av-grid">';
                    for (var i = 0; i < r.data.length; i++) {
                        var o = r.data[i];
                        h += '<button class="ow-av-cell" type="button" data-slug="' + esc(o.slug) + '" title="' + esc(o.label) + '">'
                           + '<img src="' + esc(o.url) + '" alt="' + esc(o.label) + '" loading="lazy" decoding="async">'
                           + '<span>' + esc(o.label) + '</span></button>';
                    }
                    h += '</div><div class="ow-av-hint">先选一种插图风格，再挑其中一张。</div>';
                    box.innerHTML = h;
                    var cells = box.getElementsByTagName('button');
                    for (var j = 0; j < cells.length; j++) {
                        (function (btn) {
                            btn.onclick = function () {
                                if (!btn.getAttribute('data-slug')) return;
                                renderVariants(btn.getAttribute('data-slug'));
                            };
                        })(cells[j]);
                    }
                });
            };

            var renderVariants = function (slug) {
                box.innerHTML = '<div class="ow-av-loading">变体加载中…</div>';
                OwApi.post('avatar_variants', { avatar_style: slug }, function (r) {
                    if (!box) return;
                    if (!r.ok || !r.data || !r.data.length) {
                        box.innerHTML = '<div class="ow-av-loading">变体加载失败</div>';
                        return;
                    }
                    var label = '';
                    var list = self.cfg.avatarStyles || [];
                    for (var k = 0; k < list.length; k++) if (list[k].slug === slug) label = list[k].label;
                    var h = '<button class="ow-btn ow-btn-ghost ow-av-btn ow-av-back" type="button" id="owAvBack">'
                          + '<svg class="ow-ico" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
                          + ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 6 9 12 15 18"/></svg>'
                          + '全部风格</button>'
                          + '<div class="ow-av-subtitle">' + esc(label || slug) + '</div>'
                          + '<div class="ow-av-grid">';
                    for (var i = 0; i < r.data.length; i++) {
                        var v = r.data[i];
                        h += '<button class="ow-av-cell" type="button" data-seed="' + v.seed + '" title="第 ' + v.seed + ' 号">'
                           + '<img src="' + esc(v.url) + '" alt="" loading="lazy" decoding="async"></button>';
                    }
                    h += '</div><div class="ow-av-hint">共 ' + r.data.length + ' 个头像，点一下即刻生效。</div>';
                    box.innerHTML = h;
                    // ⚠️ 顺序有讲究：先只给「带 data-seed 的变体格」绑事件，
                    //   再单独绑返回按钮 —— 反过来的话，下面的循环会把返回按钮的 onclick
                    //   覆盖成一个「没有 data-seed 就 return」的空函数，点击就没反应了。
                    var cells = box.getElementsByTagName('button');
                    for (var j = 0; j < cells.length; j++) {
                        if (!cells[j].getAttribute('data-seed')) continue;
                        (function (btn) {
                            btn.onclick = function () {
                                self.saveAvatarStyle(slug, rid, btn.getAttribute('data-seed'));
                            };
                        })(cells[j]);
                    }
                    var back = $('owAvBack');
                    if (back) back.onclick = renderStyles;
                });
            };

            renderStyles();
        },

        /** 保存生成式头像。rid>0 表示群头像，0 表示本人 */
        /**
         * 群头像三种改法（上传 / 生成式插图 / 恢复默认）共用的一次提交。
         * 名称与简介必须随房带上 —— room_update 服务端要校验这两项，
         * 只发改头像那几个字段，用户收到的会是莫名其妙的「群名称需 1-30 个字符」。
         * @param int    rid    群 id
         * @param object extra  头像相关字段（avatar / avatar_type / avatar_style / avatar_seed）
         */
        roomAvatarPost: function (rid, extra) {
            var self = this, list = this.cfg.rooms || [], i, k;
            rid = parseInt(rid || 0, 10);
            for (i = 0; i < list.length; i++) {
                if (parseInt(list[i].id, 10) !== rid) continue;
                var data = { id: rid, name: list[i].name, description: list[i].description || '' };
                for (k in extra) if (extra.hasOwnProperty(k)) data[k] = extra[k];
                OwApi.post('room_update', data, function (r) {
                    toast(r.msg);
                    if (!r.ok) return;
                    if (r.url) list[i].avatar = r.url;   // 就地更新，避免重拉整份列表
                    self.closeModal();
                    self.renderConversations();
                    self.renderRoomPanel();
                });
                return true;
            }
            toast('群聊信息已变化，请重新打开');
            return false;
        },

        saveAvatarStyle: function (slug, rid, seed) {
            var self = this;
            // v1.3.14：seed 由二级界面给出（1..45 固定档），不再随机 —— 所见即所得
            if (parseInt(rid || '0', 10) > 0) {
                this.roomAvatarPost(rid, {
                    avatar: '', avatar_type: 'generated', avatar_style: slug, avatar_seed: seed
                });
                return;
            }
            OwApi.post('avatar_save', {
                avatar_type: 'generated', avatar_style: slug, avatar_seed: seed
            }, function (r) {
                toast(r.msg);
                if (!r.ok) return;
                if (self.cfg.me) self.cfg.me.avatar = r.url;
                var pv = $('owSetAvatarPreview');
                if (pv) pv.innerHTML = avatarHtml(r.url, self.cfg.me ? self.cfg.me.nickname : '', 'lg', 'user');
                var cv = $('owCardAvatarPreview');
                if (cv) cv.innerHTML = avatarHtml(r.url, self.cfg.me ? self.cfg.me.nickname : '', 'lg', 'user');
                self.renderMe();
                self.closeModal();
            });
        },


        /**
         * 保存群聊信息（群聊信息弹窗内的表单，v1.1.10）
         *
         * v1.1.1~v1.1.9 表单内联在侧栏，保存后不关任何浮层；v1.1.10 恢复弹窗形态，
         * 因此保存成功必须 closeModal()，否则弹窗会盖在已更新的界面上继续显示旧数据。
         */
        roomEditSave: function (id) {
            var self = this;
            var nameEl = $('owRoomEditName'), descEl = $('owRoomEditDesc');
            if (!nameEl || !descEl) { toast('请先打开群聊信息弹窗'); return; }
            OwApi.post('room_update', {
                id: id,
                name: nameEl.value,
                description: descEl.value,
                avatar: this._roomAvatar || '',
                // v1.1.11 公开性：只在有开关时提交，避免别处复用本函数时误改
                is_public: $('owRoomPublic') ? ($('owRoomPublic').checked ? '1' : '0') : null
            }, function (r) {
                if (!r.ok) { toast(r.msg); return; }
                toast('群聊信息已更新');
                self.closeModal();
                self.reloadRooms();
            });
        },

        /** 重新拉取群聊列表并重渲染 */
        reloadRooms: function () {
            var self = this;
            OwApi.post('rooms', {}, function (r) {
                if (!r.ok) return;
                self.cfg.rooms = r.data;
                // v1.1.0：列表已改为「群聊+私聊」聚合，走 conversations 重新拉取，
                // 直接 renderRooms 会把私聊行冲掉。
                self.loadConversations();
                // 群资料已变（名称/简介/头像）→ 顶栏群名与侧栏入口区同步刷新。
                // _roomAvatar 不用动：它只是「本次要提交的相对路径」（空=不改头像），
                // 与刷新无关；显示用的头像一律取 cfg.rooms[].avatar（见 roomAvatarOf）。
                self.renderRoomPanel();
            });
        },

        /**
         * 点击引用块 → 跳到被引用的原消息。
         *
         * v1.2.36：原实现已**删除**，改为委托统一的消息定位轮子 
         * （与搜索结果跳转共用同一套：同样的居中算法、同样的高亮样式、同样的回溯逻辑）。
         * 差别只有一个：引用跳转**不弹操作菜单**（opt.menu 不传）。
         */
        jumpToQuote: function (msgId) {
            this.locateMsg(msgId, { tip: '原消息在更早的历史里，已加载到底' });
        },

        /**
         * 删除消息（内容右键）
         *
         * v1.2.4：**删除 = 一律只在本机隐藏**，任何身份都是（含超级管理员）。
         * 别人照常看得到、消息仍在库里、换设备登录也不生效。
         * 真的删除走「撤回」（全局生效），见 recall()。
         *
         * 确认框必须写明「仅本机」：否则用户会以为所有人都看不到了，那是欺骗。
         * 移除 hideOnly 参数——已无权限分档，无需前端传期望值。
         */
        deleteMsg: function (id) {
            var self = this;
            var text = '确定删除这条消息吗？删除后仅本机不再看到，其他人不受影响。';
            this.confirmModal(text, function () {
                OwApi.secure('msg_delete', { id: id }, function (r) {
                    if (!r.ok) { toast(r.msg); return; }
                    var el = $('owMsg' + id);
                    if (el && el.parentNode) el.parentNode.removeChild(el);
                    delete self.msgCache[id];
                    // 本机隐藏只影响消息区，不影响会话摘要，无需重拉
                    toast(r.msg || '已删除（仅本机不再看到）');
                });
            });
        },

        /** 取消裁剪：仅关闭独立裁剪浮层（底下的弹窗/表单保持原状） */
        avatarCropCancel: function () {
            this.closeCropOverlay();
        },

        /** 按当前缩放导出正方形头像并上传（上传后仍需点「保存」写入资料） */
        avatarCropSave: function (btn) {
            // v1.5.18：toBlob 是异步的，OwApi 的自动锁拿不到这次点击；按钮在这里同步禁用。
            // 弹窗无论成功失败都会关闭，所以不需要再解禁。
            if (btn && btn.__owBusy) return;
            if (btn) OwBusy.lock(btn, 15000);
            var self = this, canvas = $('owCropCanvas');
            if (!canvas) { toast('裁剪弹窗已关闭'); return; }
            var done = function (blob) {
                self.closeCropOverlay();
                if (!blob) { toast('当前浏览器无法处理图片，请更换浏览器'); return; }
                if (self._cropTarget === 'room') self.roomAvatarUpload(blob, 'room.jpg');
                else self.avatarUpload(blob, 'avatar.jpg');
            };
            if (canvas.toBlob) {
                canvas.toBlob(function (b) { done(b); }, 'image/jpeg', 0.9);
            } else {
                // 老浏览器：toDataURL → 手工转 Blob
                var b64 = canvas.toDataURL('image/jpeg', 0.9).split(',')[1] || '';
                var bin = w.atob ? w.atob(b64) : '';
                var arr = new Uint8Array(bin.length), i;
                for (i = 0; i < bin.length; i++) arr[i] = bin.charCodeAt(i);
                done(new Blob([arr], { type: 'image/jpeg' }));
            }
        },

        /**
         * 通用头像上传（复用用户头像上传 API：kind=avatar，服务端统一裁 100x100）。
         * 上传成功后调用 onOk(url)；UI 行为由调用方决定（个人头像 / 群聊头像）。
         */
        /**
         * 上传个人头像（file 可为 File 或 canvas 导出的 Blob），成功后刷新全部预览。
         *
         * v1.1.8：改为**回填所有可能出现头像预览的容器** —— 个人设置弹窗
         * （#owSetAvatarPreview）与自己的资料卡（#owCardAvatarPreview）。
         * 原先只回填前者，于是从资料卡上传后，卡片里的头像还是旧图
         * （必须关掉再打开才刷新），而资料卡恰恰是最新的入口。
         * 裁剪 → 导出 → 上传 → 回填这一整条链路由 cropForTarget / avatarCropSave
         * 与本方法共享，群聊头像另走 roomAvatarUpload，两者都经插件注入的 uploader。
         */
        avatarUpload: function (file, filename) {
            var self = this;
            // v1.3.11：核心不再有上传实现 —— 由附件上传插件经 registerAvatarUploader 注入。
            // 插件未启用时头像并非不可换：设置弹窗里还有「浏览插图」（生成式头像）。
            if (!this._avatarUploader) {
                toast('上传头像需要启用「附件上传」插件；也可以在头像设置里选「浏览插图」');
                return;
            }
            try {
                this._avatarUploader(file, filename, function (url) {
                    if (self.cfg.me) self.cfg.me.avatar = url;
                    var pv = $('owSetAvatarPreview');
                    if (pv) pv.innerHTML = avatarHtml(url, self.cfg.me ? self.cfg.me.nickname : '', 'lg', 'user');
                    var cv = $('owCardAvatarPreview');
                    if (cv) cv.innerHTML = avatarHtml(url, self.cfg.me ? self.cfg.me.nickname : '', 'lg', 'user');
                    self.renderMe();
                    toast('头像已上传');
                }, { target: 'me' });
            } catch (e) {
                toast('上传失败，请重试');
            }
        },

        /**
         * 上传群聊头像：拿到相对路径后**立即写入**（v1.3.135）。
         * 原先这里只回填预览 + toast「群头像已上传，点击保存生效」，可那颗「保存」按钮
         * 在底下的群聊信息弹窗里 —— 而头像设置弹窗是 openModal 换掉整个 #owModal 的，
         * 弹窗一开，带保存的那张表单就已经不在了，图等于白传。
         * 现在与「浏览插图」「恢复默认」同口径：点一下即生效，走同一个 roomAvatarPost。
         */
        roomAvatarUpload: function (file, filename) {
            var self = this;
            if (!this._avatarUploader) { toast('上传群头像需要启用「附件上传」插件；也可以选「浏览插图」'); return; }
            var rid = parseInt(this._cropRoom || this.room || 0, 10);
            if (!(rid > 0)) { toast('群聊信息已变化，请重新打开'); return; }
            this._avatarUploader(file, filename, function (url, path) {
                // 提交的是**相对路径**：room_update 只认 uploads/avatar/ 开头，
                // 渲染用的绝对 URL 递过去会被服务端判「头像路径不合法」。
                if (!path) { toast('上传接口没有回头像路径，请更新「附件上传」插件'); return; }
                self._roomAvatar = path;
                // 只交相对路径：avatar_type 由服务端在这一步一并落成 upload
                // （roomAvatarUrl 只在 type 为 ''/upload 时才认这个路径，两边各写迟早漂移）
                self.roomAvatarPost(rid, { avatar: path });
            }, { target: 'room' });
        },

        saveSettings: function () {
            var self = this;
            // v1.3.11：头像独立保存（上传/生成式），资料保存只提交昵称 ——
            // 否则这里回传的已算好的 URL 会被服务端当成「头像路径非法」。
            OwApi.post('profile_save', {
                nickname: $('owSetNick').value
            }, function (r) {
                toast(r.msg);
                if (r.ok) { self.cfg.me.nickname = $('owSetNick').value; self.renderMe(); self.closeModal(); }
            });
        },

        /* ---------- 弹层 ---------- */
        /** 打开弹窗；width 可选（px），供内容较宽的弹窗（如群公告页面）覆盖默认 380px */
        openModal: function (html, width, cls) {
            // 后台页（OwAdmin）没有静态浮层：动态补建（closeModal 同样兼容）
            if (!$('owModalMask') || !$('owModal')) {
                var mask = document.createElement('div');
                mask.className = 'ow-modal-mask';
                mask.id = 'owModalMask';
                mask.style.display = 'none';
                mask.innerHTML = '<div class="ow-modal" id="owModal"></div>';
                document.body.appendChild(mask);
            }
            var m = $('owModal');
            // class 每次重设：上一个弹窗可能是固定尺寸（.ow-modal-fixed 带 height + overflow:hidden），
            // 不清掉就会把高度和裁剪带到下一个普通弹窗上。
            m.className = 'ow-modal' + (cls ? ' ' + cls : '');
            m.style.maxWidth = width ? (parseInt(width, 10) + 'px') : '';
            m.innerHTML = '<button class="ow-modal-close" onclick="OwChat.closeModal()">✕</button>' + html;
            $('owModalMask').style.display = '-webkit-flex';
            $('owModalMask').style.display = 'flex';
        },
        closeModal: function () { $('owModalMask').style.display = 'none'; },

        /** 自研确认弹窗（v1.0.112 前台版）：替代原生 confirm——全站禁止浏览器原生弹窗 */
        confirm: function (text, onOk) {
            var mask = document.createElement('div');
            mask.className = 'ow-modal-mask';
            mask.style.display = 'flex';
            mask.style.zIndex = 200;   // 叠在普通弹窗（z-index 100）之上
            mask.innerHTML = '<div class="ow-modal" style="width:340px;max-width:92%">'
                + '<button class="ow-modal-close">✕</button>'
                + '<h3>确认操作</h3>'
                + '<p class="ow-modal-desc">' + esc(text).replace(/\n/g, '<br>') + '</p>'
                + '<div class="ow-modal-actions">'
                + '<button class="ow-btn ow-btn-ghost">取消</button>'
                + '<button class="ow-btn ow-btn-danger">确定</button></div></div>';
            document.body.appendChild(mask);
            var close = function () { if (mask.parentNode) document.body.removeChild(mask); };
            mask.querySelector('.ow-modal-close').onclick = close;
            var bs = mask.querySelectorAll('.ow-modal-actions .ow-btn');
            bs[0].onclick = close;
            bs[1].onclick = function () { close(); if (onOk) onOk(); };
            mask.onclick = function (e) { if (e.target === mask) close(); };
        },
    };

    /* ==========================================================================
       OwAdmin：管理后台
       ========================================================================== */
    var OwAdmin = {
        /** v1.5.4：站点自定义的「积分」名称（init 时由服务端下发）。
         *  后台各页（含插件后台，如用户管理、星光殿堂）统一走它；
         *  默认值兜底「积分」—— init 之前调也不会得到 undefined。 */
        pointsName: function () {
            return this._pn || '积分';
        },
        /**
         * 通用确认弹窗（与前台 confirmModal 同一样式，v1.0.50）。
         * 所有删除 / 卸载 / 禁用等危险操作统一调用，不再使用原生 confirm。
         */
        /* ---------- 通用列表组件（v1.0.84）：分页条 / 多选批量计数，供各管理页复用 ---------- */

        /**
         * 分页条。go 回调接收新页码。
         * @param string|Element el 分页容器
         */
        uiPager: function (el, page, total, size, go) {
            el = typeof el === 'string' ? $(el) : el;
            if (!el) return;
            var pages = Math.max(1, Math.ceil(total / size));
            if (pages <= 1) {
                el.innerHTML = '<span style="font-size:12px;color:var(--ow-text-sub)">共 ' + total + ' 条</span>';
                return;
            }
            var h = '<div class="ow-pager">';
            if (page > 1) h += '<button type="button" class="ow-btn ow-btn-ghost" data-pg="' + (page - 1) + '">上一页</button>';
            // 数字页码：当前页前后各 2 页，首末页与区间之间用省略号
            var start = Math.max(1, page - 2), end = Math.min(pages, page + 2);
            if (start > 1) {
                h += '<button type="button" class="ow-btn ow-btn-ghost" data-pg="1">1</button>';
                if (start > 2) h += '<span class="ow-pager-dots">…</span>';
            }
            for (var i = start; i <= end; i++) {
                h += '<button type="button" class="ow-btn ' + (i === page ? 'ow-btn-primary' : 'ow-btn-ghost') + '"' + (i === page ? ' disabled' : '') + ' data-pg="' + i + '">' + i + '</button>';
            }
            if (end < pages) {
                if (end < pages - 1) h += '<span class="ow-pager-dots">…</span>';
                h += '<button type="button" class="ow-btn ow-btn-ghost" data-pg="' + pages + '">' + pages + '</button>';
            }
            if (page < pages) h += '<button type="button" class="ow-btn ow-btn-ghost" data-pg="' + (page + 1) + '">下一页</button>';
            // 页码跳转：输入页码回车直接跳
            h += '<span class="ow-pager-jump">跳至<input type="number" class="ow-pager-jump-input" min="1" max="' + pages + '" value="' + page + '">页</span>';
            h += '<span class="ow-pager-info">共 ' + total + ' 条</span>';
            h += '</div>';
            el.innerHTML = h;
            var btns = el.getElementsByTagName('button');
            for (var b = 0; b < btns.length; b++) {
                btns[b].onclick = function () {
                    var pg = parseInt(this.getAttribute('data-pg'), 10);
                    if (pg >= 1 && pg <= pages && pg !== page) go(pg);
                };
            }
            var jump = el.querySelector('.ow-pager-jump-input');
            if (jump) {
                var doJump = function () {
                    var v = parseInt(jump.value, 10);
                    if (v >= 1 && v <= pages && v !== page) go(v); else jump.value = page;
                };
                jump.onkeydown = function (e) { e = e || window.event; if (e.key === 'Enter' || e.keyCode === 13) doJump(); };
                jump.onchange = doJump;
            }
        },

        /**
         * 多选批量计数与按钮启停。约定：行复选框 class=chkCls，按钮 id 在 btnIds。
         * @return number 已选数量
         */
        uiBatchSync: function (chkCls, btnIds, statEl, base) {
            var boxes = document.getElementsByClassName(chkCls), n = 0;
            for (var i = 0; i < boxes.length; i++) if (boxes[i].checked) n++;
            for (var j = 0; j < btnIds.length; j++) { var b = $(btnIds[j]); if (b) b.disabled = n === 0; }
            if (statEl) {
                statEl = typeof statEl === 'string' ? $(statEl) : statEl;
                if (statEl) statEl.textContent = n ? base + '，已选 ' + n + ' 条' : base;
            }
            return n;
        },

        /**
         * 批量操作「下拉轮子」（v1.2.60）：把多个批量动作收进一个 select + 一个执行按钮。
         *
         * 为什么改：原先每个动作平铺一个按钮（群聊审核摊了 4 个），动作一多就撑爆一行、
         * 挤掉「已选 N 条」提示位，窄屏还会折成两行。而真正会被用的往往只有一个动作。
         * 下拉首项是占位提示，强迫用户先明确「要做哪个」，比一排等权按钮少误点。
         *
         * @param {Object} opt
         *   - id      容器元素 id
         *   - chkCls  行复选框 class
         *   - actions [{key, label, danger?}]，danger=true 的动作项标红
         *   - statBase 未选中时的统计文案（如「共 120 条」）
         *   - onExec  function(actionKey, ids)，由页面自己弹确认框并调接口
         */
        uiBatchBar: function (opt) {
            var box = $(opt.id);
            if (!box) return;
            var acts = opt.actions || [], h = '', i;
            h = '<div class="ow-admin-batch">'
               + '<select class="ow-input ow-batch-select" id="' + opt.id + '_sel">'
               + '<option value="">请选择批量操作</option>';
            for (i = 0; i < acts.length; i++) {
                h += '<option value="' + esc(acts[i].key) + '"' + (acts[i].danger ? ' class="ow-batch-danger"' : '') + '>'
                   + esc(acts[i].label) + '</option>';
            }
            h += '</select>'
               + '<button type="button" class="ow-btn ow-btn-primary" id="' + opt.id + '_go" disabled>执行</button>'
               + '<span id="' + opt.id + '_stat" style="color:var(--ow-text-sub);font-size:12px"></span>'
               + '</div>';
            box.innerHTML = h;

            var btn = $(opt.id + '_go');
            if (btn) {
                btn.onclick = function () {
                    var sel = $(opt.id + '_sel');
                    var key = sel ? sel.value : '';
                    if (!key) { toast('请先选择批量操作'); return; }
                    var ids = OwAdmin.batchIds(opt.chkCls);
                    if (!ids.length) { toast('请先勾选要操作的项目'); return; }
                    opt.onExec(key, ids);   // 确认框与接口调用交给页面（各动作危险程度不同）
                };
            }
            var sel2 = $(opt.id + '_sel');
            if (sel2) {
                sel2.onchange = function () {
                    // 选中危险动作时执行按钮转红，给一道额外的视觉刹车
                    var a = null;
                    for (var j = 0; j < acts.length; j++) if (acts[j].key === sel2.value) a = acts[j];
                    if (btn) btn.className = 'ow-btn ' + (a && a.danger ? 'ow-btn-danger' : 'ow-btn-primary');
                };
            }
            OwAdmin.batchSync(opt.chkCls, opt.id, opt.statBase);
        },

        /** 取当前勾选的 id 列表（所有批量调用方共用这一份取值逻辑） */
        batchIds: function (chkCls) {
            var boxes = document.getElementsByClassName(chkCls), ids = [];
            for (var i = 0; i < boxes.length; i++) if (boxes[i].checked) ids.push(boxes[i].value);
            return ids;
        },

        /**
         * 批量计数同步（下拉轮子版）：未选中时禁用执行按钮，并把「已选 N 条」写进统计位。
         * @return number 已选数量
         */
        batchSync: function (chkCls, barId, base) {
            var ids = OwAdmin.batchIds(chkCls);
            var go = $(barId + '_go'), st = $(barId + '_stat');
            if (go) go.disabled = ids.length === 0;
            if (st) st.textContent = ids.length
                ? (base ? base + '，已选 ' + ids.length + ' 条' : '已选 ' + ids.length + ' 条')
                : (base || '');
            return ids.length;
        },

        /* ---------- 安全日志：动作/字段中文表 + 详情解析 + 筛选加载（v1.2.51） ----------
           原来一次拉 200 条裸行：action 是英文 slug、data 是原始 JSON，基本看不懂。
           现在服务端分页 + 按动作筛选，动作/字段都翻成中文，data 拼成一句人话。
           ⚠️ 这些方法必须在 OwAdmin **顶层**：筛选下拉的 onchange 直接引用
           OwAdmin.logAct / OwAdmin.logLoad；pages 表里的 logs 只是渲染壳。
           动作表覆盖核心与全部内置插件；新动作没进表也不出错 —— 回退显示英文 slug。 */
        _logActs: {
            login: '登录成功', login_fail: '登录失败', login_locked: '账号锁定',
            session_fingerprint_mismatch: '会话指纹异常（Cookie 在别的浏览器/网络被重放，会话已销毁）',
            logout: '退出登录', register: '注册账号', reset_password: '重置密码',
            admin_settings: '保存系统设置',
            admin_ban: '后台封禁', ban_quick: '快捷封禁',
            admin_user_set: '后台设置用户', admin_user_lock: '后台锁定用户',
            // v1.5.4：admin_user_points（调整积分）不在这张表里 —— 「积分」是站点可改的
            //   名词，标签由 _logActName 用 pointsName() 现拼。
            admin_user_level: '调整等级',
            cron_toggle: '维护任务启停', cron_run: '手动执行任务', cron_token: '重置任务令牌', cron_error: '任务执行异常',
            room_create: '创建群聊', room_update: '更新群资料', room_review: '群聊处置', room_undo: '撤销处置',
            room_invite: '邀请入群', room_member_del: '移出成员', room_invite_code: '生成邀请链接', room_pass_fail: '房间密码错误',
            msg_recall: '撤回消息', msg_hide: '隐藏消息', msg_expire: '保留期清理',
            dm_compliance_read: '私聊合规查阅',
            plugin_install: '安装插件', plugin_uninstall: '卸载插件', plugin_error: '插件加载出错', plugin_page_error: '插件页面出错',
            sensitive_reject: '敏感词拦截',
            sensitive_word_add: '添加敏感词', sensitive_word_del: '删除敏感词', sensitive_word_batch_del: '批量删除敏感词',
            spam_appeal_submit: '用户提交访问申诉', spam_appeal_handle: '处理访问申诉',
            spam_appeal_delete: '删除申诉记录', spam_appeal_batch_del: '批量删除申诉记录',
            spam_ai_hit: 'AI 判定命中', spam_ai_fail: 'AI 判定失败（消息已放行）',
            nickname_reserve_block: '保留昵称拦截', nickname_reserve_save: '保存保留昵称',
            upload_file: '上传附件', admin_attachment_delete: '删除附件',
            group_ann_add: '发布群公告', group_ann_del: '删除群公告', group_ann_batch_del: '批量删除群公告',
            twofa_enable: '开启两步验证', twofa_disable: '关闭两步验证', twofa_login_ok: '两步验证登录',
            twofa_verify_fail: '两步验证失败', twofa_reset_codes: '重置恢复码',
            header_footer_save: '保存页头页脚',
            level_cfg_save: '保存等级参数',
            content_report_config: '保存举报设置', content_report_handle: '处理举报',
            user_block_add: '拉黑用户', user_block_remove: '取消拉黑',
            admin_login_logs_clear: '清理登录日志', plugin_login_logs_err: '登录日志插件异常'
        },
        /* data 字段 → 中文标签（没进表的键直接显示原键名）。
           v1.5.4：points / cost 两项不在这张表里 —— 它们的标签含「积分」二字，
           由 _logDetail 按站点自定义名称现拼（见那里的注释）。 */
        _logKeys: {
            act: '操作', room: '群聊', id: 'ID', name: '名称', uid: '用户', target: '目标', to: '对象',
            count: '数量', fail: '失败', err: '错误', error: '错误', days: '天数', deleted: '删除数',
            level: '等级', role: '角色', type: '类型', hours: '小时', size: '大小',
            ext: '扩展名', method: '方式', msgs: '消息数', files: '文件数', retain_days: '保留期',
            trash: '回收站ID', items: '保存项', scene: '场景', interval: '间隔',
            desc_limit: '简介字数', status: '状态', word: '词条', email: '邮箱', ip: 'IP',
            fails: '失败次数', minutes: '锁定分钟', reason: '原因', expires: '时长',
            domain: '域名', kind: '身份', removed: '移出名单', mode: '模式',
            why: '模型理由', action: '命中后动作'
        },
        /* room_review 的 act 子类型 */
        _logReviewAct: { reset_name: '重置名称', reset_avatar: '重置头像', ban: '封禁', unban: '解封', delete: '删除' },

        _logActName: function (a) {
            // v1.5.4：「积分」是站点可改的名词，动作名不能写死
            if (a === 'admin_user_points') return '调整' + this.pointsName();
            return this._logActs[a] || a;
        },
        /** 时间戳 → 本地 Y-m-d H:i:s（旧实现 toLocaleString 带多余段落，且 ISO 是 UTC 会差 8 小时） */
        _logTs: function (ts) {
            var t = new Date(parseInt(ts, 10) * 1000);
            function p(n) { return (n < 10 ? '0' : '') + n; }
            return t.getFullYear() + '-' + p(t.getMonth() + 1) + '-' + p(t.getDate())
                + ' ' + p(t.getHours()) + ':' + p(t.getMinutes()) + ':' + p(t.getSeconds());
        },
        /** data JSON → 「操作 封禁，群聊 5」式的一句话；解析失败回退原文 */
        _logDetail: function (d) {
            var o = null;
            try { o = JSON.parse(d.data || '{}'); } catch (e) {}
            if (!o || typeof o !== 'object') return esc(d.data || '') || '—';
            var keys = this._logKeys, parts = [];
            for (var k in o) {
                if (!Object.prototype.hasOwnProperty.call(o, k)) continue;
                var v = o[k];
                if (v === '' || v === null || v === undefined) continue;
                if (k === 'act' && d.action === 'room_review') v = this._logReviewAct[v] || v;
                // v1.5.4：points / cost 的中文标签里含「积分」，按站点自定义名称渲染
                var label = keys[k] || k;
                if (k === 'points') label = this.pointsName();
                else if (k === 'cost') label = '扣除' + this.pointsName();
                parts.push('<b>' + esc(label) + '</b> ' + esc(String(v)));
            }
            return parts.length ? parts.join('，') : '—';
        },
        logAct: function (a) {
            OwAdmin._logAct = a || '';
            OwAdmin.logLoad(1);
        },
        logLoad: function (page) {
            OwAdmin._logPage = Math.max(1, page || 1);
            OwApi.post('admin_logs', { page: OwAdmin._logPage, psize: 30, action: OwAdmin._logAct }, function (r) {
                var main = $('owAdminMain');
                if (!r.ok) { main.innerHTML = '<div class="ow-card">' + esc(r.msg || '加载失败') + '</div>'; return; }

                // 筛选下拉：全部 + 各动作（带计数）。每次重绘以保住计数最新，
                // 选中态跟随 _logAct，翻页不丢筛选。
                var sel = $('owLogAct');
                if (sel) {
                    var oh = '<option value="">全部动作（' + r.total + '）</option>';
                    for (var a = 0; a < r.actions.length; a++) {
                        var it = r.actions[a];
                        oh += '<option value="' + esc(it.action) + '"' + (it.action === OwAdmin._logAct ? ' selected' : '') + '>'
                            + esc(OwAdmin._logActName(it.action)) + '（' + it.n + '）</option>';
                    }
                    sel.innerHTML = oh;
                }
                var stat = $('owLogStat');
                if (stat) stat.textContent = '共 ' + r.total + ' 条';
                OwAdmin._logTotal = r.total;

                var h = '<tr><th style="width:32px"><input type="checkbox" id="owLogCheckAll" onchange="OwAdmin.logToggleAll(this)"></th>'
                      + '<th>时间</th><th>动作</th><th>操作者</th><th>IP</th><th>详情</th></tr>';
                if (!r.data.length) {
                    h += '<tr><td colspan="6" style="color:var(--ow-text-sub)">当前筛选下暂无日志。</td></tr>';
                }
                for (var i = 0; i < r.data.length; i++) {
                    var x = r.data[i];
                    h += '<tr><td><input type="checkbox" class="owLogChk" value="' + x.id + '" onchange="OwAdmin.logSyncBatch()"></td>'
                       + '<td style="white-space:nowrap">' + esc(OwAdmin._logTs(x.created_at)) + '</td>'
                       + '<td><b>' + esc(OwAdmin._logActName(x.action)) + '</b>'
                       + '<div style="color:var(--ow-text-sub);font-size:11px">' + esc(x.action) + '</div></td>'
                       + '<td>' + esc(x.actor || '—') + '</td>'
                       + '<td>' + esc(x.ip || '—') + '</td>'
                       + '<td style="max-width:420px">' + OwAdmin._logDetail(x) + '</td></tr>';
                }
                var tb = $('owLogTable');
                if (tb) tb.innerHTML = h;
                var all = $('owLogCheckAll');
                if (all) all.checked = false;
                OwAdmin.batchSync('owLogChk', 'owLogBatch', '共 ' + r.total + ' 条');
                OwAdmin.uiPager('owLogPager', r.page, r.total, r.psize || 30, function (pg) { OwAdmin.logLoad(pg); });
            });
        },

        /** 全选 / 取消全选（安全日志） */
        logToggleAll: function (cb) {
            var boxes = document.getElementsByClassName('owLogChk');
            for (var i = 0; i < boxes.length; i++) boxes[i].checked = cb.checked;
            OwAdmin.logSyncBatch();
        },

        logSyncBatch: function () {
            OwAdmin.batchSync('owLogChk', 'owLogBatch', '共 ' + (OwAdmin._logTotal || 0) + ' 条');
        },

        /** CSV 导出：走 GET 直出（服务端在签名门禁前放行，仅校验管理员会话） */
        logExport: function () {
            window.location.href = '?action=admin_logs_export';
        },

        /** 打开某个日志文件（系统日志页的 tab 切换） */
        syslogOpen: function (file) {
            OwAdmin._sysFile = file;
            var tabs = $('owSysTabs');
            if (tabs) {
                var bs = tabs.getElementsByTagName('button');
                for (var i = 0; i < bs.length; i++) {
                    var on = bs[i].getAttribute('data-file') === file;
                    bs[i].className = bs[i].className.replace(/\s*active/g, '') + (on ? ' active' : '');
                }
            }
            var btn = $('owSysClear');
            if (btn) btn.disabled = false;
            var body = $('owSysBody');
            if (body) body.innerHTML = '加载中…';
            OwApi.post('admin_syslog_read', { file: file }, function (r) {
                if (!body) return;
                if (!r.ok) { body.innerHTML = '<div class="ow-syslog-line">' + esc(r.msg) + '</div>'; return; }
                // 用 textContent 而非 innerHTML：日志内容含任意字符，拼 HTML 会被当成标签解析
                body.textContent = r.content || '（文件为空）';
            });
        },

        syslogClear: function () {
            var file = OwAdmin._sysFile;
            if (!file) return;
            OwAdmin.confirm('确定清空 ' + file + ' 的全部内容？该文件的现有记录将不可恢复。', function () {
                OwApi.secure('admin_syslog_clear', { file: file }, function (r) {
                    toast(r.msg);
                    if (r.ok) OwAdmin.syslogOpen(file);
                });
            });
        },

        /**
         * 手动清理 OPcache（v1.2.60）：刷新已编译脚本缓存，适合代码更新后手动触发。
         * 只清缓存、不改任何数据，故走普通 post 而非票据。
         */
        opcacheReset: function () {
            var btn = $('owOpcacheBtn'), st = $('owOpcacheStat');
            if (btn) btn.disabled = true;
            if (st) st.textContent = '正在清理…';
            OwApi.post('admin_opcache_reset', {}, function (r) {
                if (btn) btn.disabled = false;
                if (st) st.textContent = r.ok ? '（' + (r.msg || '已完成') + '）' : '';
                toast(r.msg);
            });
        },

        /** v1.5.22：刷新数据派生缓存（插件清单 / 合并资源 / 词库编译 / Redis 等插件缓存）。 */
        cacheClear: function () {
            var btn = $('owCacheBtn'), st = $('owCacheStat');
            if (btn) btn.disabled = true;
            if (st) st.textContent = '正在刷新…';
            OwApi.post('admin_cache_clear', {}, function (r) {
                if (btn) btn.disabled = false;
                if (st) st.textContent = r.ok ? '（' + (r.msg || '已完成') + '）' : '';
                toast(r.msg);
            });
        },

        /* ---------- 在线升级（v1.3.39，后端 core/upgrade.php） ----------
           检查 → 差异清单 → 确认升级（下载校验齐了才搬入，自动备份）→ 可恢复。
           apply / rollback 是敏感路由，必须走 OwApi.secure（一次性票据）。 */
        upgrade: {
            info: null,

            /** 红点显隐：raw = settings.update_info（JSON 字符串）。
             *  只有「仓库确有新版」才亮 —— has_update 在 v1.3.102 起就是这个口径（见 Upgrade::saveInfo），
             *  本地改了还没推不再点它。
             *  点亮的是**所有** .ow-upd-dot（分组标题 + 组内「系统升级」那条），
             *  所以用 class 全量取而不是一句 $('owUpdDot')——收起时只见标题那颗。 */
            paintDot: function (raw) {
                var ui = null;
                try { ui = JSON.parse(raw || '{}'); } catch (e) { ui = null; }
                OwAdmin.upgrade.dotOn(ui && ui.has_update);
            },

            dotOn: function (on) {
                var els = document.querySelectorAll('.ow-upd-dot');
                for (var i = 0; i < els.length; i++) els[i].style.display = on ? '' : 'none';
            },

            check: function () {
                var btn = $('owUpdBtn'), panel = $('owUpdPanel');
                if (!btn || !panel) return;
                btn.disabled = true; btn.textContent = owTr('检查中…');
                panel.style.display = 'none';
                OwApi.post('admin_upgrade_check', {}, function (r) {
                    btn.disabled = false; btn.textContent = owTr('检查更新');
                    OwAdmin.upgrade.render(r);
                });
            },

            render: function (r) {
                var panel = $('owUpdPanel');
                if (!panel) return;
                panel.style.display = '';
                if (!r || !r.ok) {
                    panel.innerHTML = '<span style="color:#C41D1F">✗ ' + esc(owTr((r && r.msg) || '检查失败')) + '</span>';
                    return;
                }
                OwAdmin.upgrade.info = r;
                var h = '<b>' + esc(owTr('本地 v{lv} → 远端 v{rv}',
                        { lv: r.local_version || '?', rv: r.remote_version || '?' })) + '</b>'
                    + '<span style="font-size:12px;color:var(--ow-text-sub)">（' + esc(r.repo) + '）</span>';
                // status 三态（判据在 core/upgrade.php 的 statusOf）：
                //   latest=一致 / update=仓库确有新版 / local_changes=本地改了或还没推。
                // 老数据没有 status 字段时按差异兜底成 update，不会白屏。
                var st = r.status || (r.has_update ? 'update' : 'latest');
                if (st === 'latest') {
                    // 句首的 ✓/⚠ 与句子分家（✓ 留在外层文本节点、句子单独包 span）：
                    // 引擎按整个文本节点精确匹配，粘上装饰符号就得连符号一起当字典键，
                    // 换语言时那个键形同虚设。
                    h += '<br><span style="color:#1a7f37">✓ <span>与远端一致，没有需要更新的文件。</span></span>';
                } else {
                    if (st === 'local_changes') {
                        // 这一态点「升级」是拿仓库版覆盖自己的新代码，说人话拦住它。
                        // 句子写成**一整块字面量**：拆成多段拼接，字典键稍有出入就匹配不上，
                        // 而这种错只有在英文界面才看得见，等于埋雷。
                        h += '<br><span style="color:#8a5a00">⚠ <span>本地版本没有落后于远端，却有文件与仓库不同 —— 通常是你本地改了还没推送。这时升级会用仓库里的版本覆盖它们（等于回退），请先把本地提交推上去。</span></span>';
                    } else {
                        h += '<br>' + esc(owTr('有更新：{m} 个文件将覆盖，{a} 个文件新增。',
                                { m: r.modified.length, a: r.added.length }));
                    }
                    // 「（新）」只贴在新增项上，所以两段分开拼（各自先 esc 再进数组）
                    var names = r.modified.slice(0, 50).map(esc).concat(
                        r.added.slice(0, 50).map(function (p) { return esc(p) + ' ' + esc(owTr('（新）')); }));
                    h += '<details style="margin:4px 0"><summary style="cursor:pointer;font-size:12px;color:var(--ow-text-sub)">文件清单</summary>'
                        + '<div style="font-size:12px;max-height:200px;overflow:auto;font-family:Consolas,monospace;margin-top:4px">'
                        + names.join('<br>') + '</div></details>';
                    if (r.downgrade) {
                        h += '<label class="ow-check-row" style="margin:6px 0;color:#8a5a00">'
                            + '<input type="checkbox" id="owUpdDown"> 远端版本低于本地，允许降级（一般应该用「恢复备份」而不是降级）</label>';
                    }
                    if (st === 'local_changes' && !r.downgrade) {
                        h += '<label class="ow-check-row" style="margin:6px 0;color:#8a5a00">'
                            + '<input type="checkbox" id="owUpdOver"> 我确认要用仓库版本覆盖这些本地差异文件</label>';
                    }
                    h += '<button class="ow-btn ow-btn-primary" style="margin-top:6px" onclick="OwAdmin.upgrade.apply()">升级</button>';
                }
                h += '<div id="owUpdResult" style="margin-top:8px;font-size:13px"></div>'
                    + '<div id="owUpdBak" style="margin-top:8px;font-size:12px;color:var(--ow-text-sub)"></div>';
                /* ---------- GitHub token（可选，v1.3.138） ----------
                   未认证的 API 限额是 60 次/小时，而一次升级要花「差异文件数 + 3」次调用 ——
                   站方 2026-10-15 线上就卡在「63 个文件 > 上限 45」。这里让站长可选地配一个
                   token 把限额提到 5000 次/小时；不配也不会再卡死：服务端会改用整包下载
                   （一次调用拿全部差异），只有「服务器没有 phar 扩展」时才回落逐文件拉取。
                   说明文字照全站约定：可见文字只留「好处 + 代价」，细节进 ⓘ 气泡
                   （<p> + 字号 ≤12.5px，OwTip.scan 认的就是这两条）。 */
                h += '<div class="ow-form-item" style="margin-top:12px;border-top:1px solid var(--ow-border);padding-top:10px">'
                    + '<label>' + esc(owTr('GitHub token')) + '（' + esc(owTr('可选')) + '）</label>'
                    + '<input class="ow-input" id="owUpdToken" type="password" autocomplete="new-password" style="max-width:240px" placeholder="'
                    + esc(r.token_masked ? owTr('已配置 {m}，留空则不改', { m: r.token_masked }) : owTr('粘贴 token')) + '">'
                    + '<button type="button" class="ow-btn ow-btn-ghost" onclick="OwAdmin.upgrade.saveToken()">' + esc(owTr('保存')) + '</button>'
                    + (r.token_masked ? '<button type="button" class="ow-btn ow-btn-ghost" onclick="OwAdmin.upgrade.clearToken()">' + esc(owTr('清除')) + '</button>' : '')
                    + '<span id="owUpdTokenMsg" style="font-size:12px;color:var(--ow-text-sub);margin-left:8px"></span>'
                    + '<p style="font-size:12px;color:var(--ow-text-sub);margin-top:4px">'
                    // ⚠️ 整句写成**一整块字面量**：语言包按整个文本节点精确匹配，拆成几段拼接时
                    //    字典键稍有出入就匹配不上，而那种错只有在英文界面才看得见。
                    //    .tools/lang-upgrade-check.js ①④ 两条推的就是这个。
                    + '配了它 GitHub 限额从 60 次/小时提到 5000，一次能搬的文件数上限从 45 放宽到 500；用 fine-grained token、只勾 Contents: Read、只授权本仓库即可，不需要任何写权限。不配也能升级：会自动改用整包下载（一次调用拿全部差异），只有服务器没有 phar 扩展时才会退回逐文件拉取。</p>'
                    + '</div>';
                panel.innerHTML = h;
                OwAdmin.upgrade.loadBackups();
                // v1.2.45 约定：新插入的 DOM 要自己扫一遍，<p> 提示才会变成 label 上的 ⓘ 气泡
                if (w.OwTip && typeof w.OwTip.scan === 'function') w.OwTip.scan(panel);
                // 红点只跟「仓库确有新版」走（与 settings.update_info.has_update 同口径），
                // 手动检查后即时同步；本地差异那一态不点亮——那是我自己的事。
                OwAdmin.upgrade.dotOn(st === 'update');
            },

            /** 保存 GitHub token（敏感路由：POST + 一次性票据）。只回掩码，完整值不回显 */
            saveToken: function () {
                var el = $('owUpdToken'), msg = $('owUpdTokenMsg');
                if (!el) return;
                var v = String(el.value || '').replace(/^\s+|\s+$/g, '');
                if (!v) { if (msg) msg.textContent = owTr('请先粘贴 token'); return; }
                OwApi.secure('admin_upgrade_token', { token: v }, function (r) {
                    toast(r.msg);
                    if (!r.ok) return;
                    el.value = '';
                    if (OwAdmin.upgrade.info) { OwAdmin.upgrade.info.token_masked = r.token_masked || ''; OwAdmin.upgrade.render(OwAdmin.upgrade.info); }
                });
            },

            /** 清除 token：回到未认证限额（整包下载那条路仍然可用） */
            clearToken: function () {
                OwApi.secure('admin_upgrade_token', { token: '' }, function (r) {
                    toast(r.msg);
                    if (!r.ok) return;
                    if (OwAdmin.upgrade.info) { OwAdmin.upgrade.info.token_masked = ''; OwAdmin.upgrade.render(OwAdmin.upgrade.info); }
                });
            },

            apply: function () {
                var r = OwAdmin.upgrade.info;
                if (!r || !r.has_update) return;
                var down = $('owUpdDown'), over = $('owUpdOver');
                var msg = owTr('即将升级：覆盖 {m} 个文件、新增 {a} 个。\n被覆盖的文件会先备份到 data/backup/，可一键恢复；core/config.php、data/、uploads/ 永不受影响。\n确认执行？',
                    { m: r.modified.length, a: r.added.length });
                if (over && over.checked) {
                    msg = owTr('注意：这些差异来自你**本地还没推送**的改动。\n继续执行会用仓库里的版本覆盖它们（等于回退本地代码），覆盖前的原件会备份到 data/backup/。\n确认执行？');
                }
                OwAdmin.confirm(msg, function () {
                    var out = $('owUpdResult');
                    // 这句是**裸文本**塞进已有节点：observer 只遍历新插入的元素子树，
                    // 纯文本节点会被跳过，所以必须在这里就翻好（同理上面两句带数字的）。
                    if (out) out.innerHTML = esc(owTr('下载并校验中…（站点尚未改动，全部就绪才会一次性搬入）'));
                    OwApi.secure('admin_upgrade_apply', {
                        allow_downgrade: down && down.checked ? '1' : '0',
                        allow_overwrite_local: over && over.checked ? '1' : '0'
                    }, function (rr) {
                        if (!rr || !rr.ok) {
                            if (out) out.innerHTML = '<span style="color:#C41D1F">✗ ' + esc(owTr((rr && rr.msg) || '升级失败')) + '</span>';
                            return;
                        }
                        if (out) out.innerHTML = '<span style="color:#1a7f37">✓ ' + esc(owTr(rr.msg || '升级完成')) + '</span>'
                            + ' <button class="ow-btn ow-btn-mini" onclick="OwAdmin.opcacheReset()">清理 OPcache</button>'
                            + ' <button class="ow-btn ow-btn-mini ow-btn-primary" onclick="location.reload()">刷新页面</button>';
                        OwAdmin.upgrade.loadBackups();
                    });
                });
            },

            rollback: function () {
                OwAdmin.confirm(owTr('恢复到最近一次升级之前的代码状态？\n当前代码文件会被备份替换（数据、配置、上传不受影响）。'), function () {
                    OwApi.secure('admin_upgrade_rollback', {}, function (rr) {
                        toast((rr && rr.msg) || '恢复失败');   // toast 内部已过一遍 OwI18n.t
                        if (rr && rr.ok) setTimeout(function () { location.reload(); }, 900);
                    });
                });
            },

            loadBackups: function () {
                OwApi.post('admin_upgrade_backups', {}, function (r) {
                    var el = $('owUpdBak');
                    if (!el || !r || !r.ok || !r.data.length) return;
                    var b = r.data[0];
                    el.innerHTML = esc(owTr('最近备份：{ts}（v{from} → v{to}，覆盖 {m} / 新增 {a}）',
                            { ts: b.ts, from: b.from_version, to: b.to_version, m: b.modified, a: b.added }))
                        + ' <button type="button" class="ow-btn ow-btn-ghost ow-btn-mini" onclick="OwAdmin.upgrade.rollback()">恢复</button>';
                });
            },
        },

        confirm: function (text, onOk) {
            var mask = document.createElement('div');
            mask.className = 'ow-modal-mask';
            mask.style.display = 'flex';
            mask.innerHTML = '<div class="ow-modal" style="width:340px;max-width:92%">'
                + '<button class="ow-modal-close">✕</button>'
                + '<h3>确认操作</h3>'
                + '<p class="ow-modal-desc">' + esc(text).replace(/\n/g, '<br>') + '</p>'
                + '<div class="ow-modal-actions">'
                + '<button class="ow-btn ow-btn-ghost">取消</button>'
                + '<button class="ow-btn ow-btn-danger">确定</button></div></div>';
            document.body.appendChild(mask);
            var close = function () { if (mask.parentNode) document.body.removeChild(mask); };
            mask.querySelector('.ow-modal-close').onclick = close;
            var bs = mask.querySelectorAll('.ow-modal-actions .ow-btn');
            bs[0].onclick = close;
            bs[1].onclick = function () { close(); if (onOk) onOk(); };
            mask.onclick = function (e) { if (e.target === mask) close(); };
        },
        init: function (opt) {
            OwApi.key = opt.key;
            OwApi.setServerTime(opt.ts);
            this.version = opt.version || '';   // v1.3.38：后台「系统与维护」展示版本号用
            this._pn = opt.points_name || '';   // v1.5.4：积分名称（空 = pointsName() 兜底「积分」）
            var menu = $('owAdminMenu'), self = this;
            // 移动端抽屉：顶栏汉堡开合 + 遮罩点击收起 + 回到桌面宽度自动复位
            var side = $('owAdminSide'), mask = $('owAdminMask');
            var setSide = function (open) {
                if (!side) return;
                side.className = 'ow-admin-side' + (open ? ' open' : '');
                if (mask) mask.style.display = open ? 'block' : 'none';
            };
            if ($('owAdminToggle')) $('owAdminToggle').onclick = function () { setSide(side.className.indexOf('open') < 0); };
            if (mask) mask.onclick = function () { setSide(false); };
            window.onresize = function () {
                if ((document.documentElement.clientWidth || window.innerWidth || 1024) > 720) setSide(false);
            };
            var items = menu.getElementsByTagName('li'), i;
            // 记忆当前页面：启停插件等操作刷新后停留在原页面，而不是跳回默认页
            var remember = function (ap) {
                try { sessionStorage.setItem('owAdminPage', ap); } catch (e) {}
            };
            for (i = 0; i < items.length; i++) {
                items[i].onclick = function () {
                    var ap = this.getAttribute('data-apage'), all = menu.getElementsByTagName('li'), j;
                    // ① 只摘掉选中态，保留分组/子项/展开等布局类（否则子菜单会被一起抹掉）
                    for (j = 0; j < all.length; j++) {
                        all[j].className = trimCls(all[j].className.replace(/\bactive\b/g, ''));
                    }
                    // ② 插件分类：点标题自行折叠/展开；点插件子页面时保持展开
                    if (ap === 'plugins') self.togglePluginSub(false);
                    else if (ap.indexOf('plugin:') === 0) self.togglePluginSub(true);
                    // ③ 选中态最后加，避免被上面的类名重置覆盖
                    this.className += ' active';
                    remember(ap);
                    self.page(ap);
                    setSide(false);   // 移动端点完菜单收起抽屉
                };
            }
            /* 恢复上次所在页面（启停插件刷新后不跳回默认页）；无记录时进仪表盘（v1.3.158 起） */
            var lastPage = 'dash';
            try { lastPage = sessionStorage.getItem('owAdminPage') || 'dash'; } catch (e) {}
            try { if (sessionStorage.getItem('owAdminPluginsOpen') === '1') self.togglePluginSub(true); } catch (e) {}
            // 摘掉服务端预置的默认选中态（dash），避免双高亮
            var all0 = menu.getElementsByTagName('li'), k0;
            for (k0 = 0; k0 < all0.length; k0++) {
                all0[k0].className = trimCls(all0[k0].className.replace(/\bactive\b/g, ''));
            }
            if (menu.querySelector('li[data-apage="' + lastPage + '"]')) {
                if (lastPage.indexOf('plugin:') === 0) self.togglePluginSub(true);
                var li0 = menu.querySelector('li[data-apage="' + lastPage + '"]');
                li0.className += ' active';
                this.page(lastPage);
            } else {
                this.page('dash');
            }
        },

        /**
         * 展开/收起「插件管理」下的插件子页面
         * @param {boolean} forceOpen true=强制展开（点击插件子页面时用），false=切换
         */
        togglePluginSub: function (forceOpen) {
            var menu = $('owAdminMenu'), all = menu.getElementsByTagName('li'), i, el, group = null;
            for (i = 0; i < all.length; i++) {
                if (all[i].className.indexOf('ow-admin-group') >= 0) { group = all[i]; break; }
            }
            if (!group) return;   // 没有任何插件声明后台页面 → 插件管理是普通菜单项
            var willOpen = forceOpen ? true : group.className.indexOf('ow-group-open') < 0;
            // ⚠️ 正则里剔掉的类名必须与下面加上的**同一个**：剔的是旧前缀 `ha-group-open`
            //   /`ha-sub-open` 时，`ow-group-open`/`ow-sub-open` 只增不减 —— 表现是
            //   「插件管理」分组一展开就再也收不起来，且不报任何错（v1.3.68 修）。
            group.className = trimCls((group.className.replace(/\bow-group-open\b/g, ''))
                + (willOpen ? ' ow-group-open' : ''));
            for (i = 0; i < all.length; i++) {
                el = all[i];
                if (el.className.indexOf('ow-admin-sub') < 0) continue;
                el.className = trimCls((el.className.replace(/\bow-sub-open\b/g, ''))
                    + (willOpen ? ' ow-sub-open' : ''));
            }
            // 记录展开状态：刷新后恢复
            try {
                if (willOpen) sessionStorage.setItem('owAdminPluginsOpen', '1');
                else sessionStorage.removeItem('owAdminPluginsOpen');
            } catch (e) {}
        },

        page: function (name) {
            // 段码时钟的秒表只属于仪表盘：切到任何页面（含插件页）先停掉，
            // 否则离开首屏之后它还在每秒找 DOM（页面切来切去会攒出好几个定时器）。
            OwAdmin.clockStop();
            var main = $('owAdminMain');
            var M = OwAdmin.pages[name];
            // 移动端适配：主区内任何表格自动套横滚容器（含异步渲染与插件页）
            if (!main._tableObserver) {
                main._tableObserver = new MutationObserver(function () {
                    var tables = main.querySelectorAll('table.ow-table');
                    for (var i = 0; i < tables.length; i++) {
                        var t = tables[i];
                        if (t.parentNode.className !== 'ow-table-wrap') {
                            var w = document.createElement('div');
                            w.className = 'ow-table-wrap';
                            t.parentNode.insertBefore(w, t);
                            w.appendChild(t);
                        }
                    }
                });
                main._tableObserver.observe(main, { childList: true, subtree: true });
            }
            if (name.indexOf('plugin:') === 0) {
                OwApi.post('admin_plugin_page', { slug: name.substr(7) }, function (r) {
                    main.innerHTML = r.ok ? r.html : '<div class="ow-card">' + esc(r.msg) + '</div>';
                });
                return;
            }
            if (M) M(main);
        },

        /* =====================================================================
         * 仪表盘「当前时间」时钟
         * ---------------------------------------------------------------------
         * v1.4.3 用内联 SVG 画七段数码管；站方看了 1 与 4 两版的修法后定「干脆显示数字」，
         * 所以段码那套（_segShapes / _digitSegs / _segSvg）整块删掉，改成直接排数字。
         * 零依赖：不引字体文件 —— 字体栈按「有高对比衬线就用，没有退 Georgia / Times」排
         * （Didot 是 macOS 现成的，Bodoni MT 常见于装了 Office 的 Windows），
         * 退到底也是系统衬线，不会掉成黑体。
         * 每秒只重画「变了的那一位」（比 data-ch），整块 DOM 不重排；
         * 定时器由上面的 page() 路由器统一停表，切页不留尾巴。
         * =================================================================== */
        _clockTimer: null,
        clockHtml: function () {
            var face = '', i;
            for (i = 0; i < 6; i++) {
                face += '<span class="ow-clock-slot" data-i="' + i + '"></span>';
                // 冒号回到字符本身：数字既然用字体排，两枚点反而和字面的对比度接不上
                if (i === 1 || i === 3) face += '<span class="ow-clock-c">:</span>';
            }
            // v1.4.4：星期条删了（站方判它是装饰）—— 日期行 + AM/PM 两枚角标足够
            return '<div class="ow-clock">'
                 + '<div class="ow-clock-face" id="owClockFace">' + face + '</div>'
                 + '<div class="ow-clock-date" id="owClockDate"></div>'
                 + '<div class="ow-clock-ap" id="owClockAp"></div></div>';
        },
        clockTick: function () {
            var face = $('owClockFace');
            if (!face) { OwAdmin.clockStop(); return; }   // 页面已被换掉：顺手停表
            var d = new Date(), h = d.getHours(), h12 = h % 12, i, el;
            if (h12 === 0) h12 = 12;
            var txt = ('0' + h12).slice(-2) + ('0' + d.getMinutes()).slice(-2) + ('0' + d.getSeconds()).slice(-2);
            var slots = face.getElementsByClassName('ow-clock-slot');
            for (i = 0; i < slots.length; i++) {
                el = slots[i];
                if (el.getAttribute('data-ch') !== txt.charAt(i)) {
                    el.setAttribute('data-ch', txt.charAt(i));
                    el.textContent = txt.charAt(i);
                }
            }
            var ap = $('owClockAp'); if (ap) ap.textContent = h < 12 ? 'AM' : 'PM';
            var date = $('owClockDate');
            if (date) date.textContent = d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2)
                + '-' + ('0' + d.getDate()).slice(-2);
        },
        clockStart: function () {
            OwAdmin.clockStop();
            OwAdmin.clockTick();
            OwAdmin._clockTimer = setInterval(OwAdmin.clockTick, 1000);
        },
        clockStop: function () {
            if (OwAdmin._clockTimer) { clearInterval(OwAdmin._clockTimer); OwAdmin._clockTimer = null; }
        },

        pages: {
            /* 用户管理（v1.0.44）、禁言管理（v1.0.52）、系统公告（v1.0.102）、
               敏感词过滤（v1.0.104）已剥离为插件，见 plugins/ 对应目录 */
            /* 仪表盘（v1.3.158）：站方要的后台首屏 —— 计数 + 今日新增 + 近 7 天 + 常用设置快捷入口。
               数字全部现算（服务端 admin_stats），刷新即最新，没有缓存要等。
               风格尽量吃站内现成轮子：卡片 .ow-card、提示 .ow-admin-desc、图标 owIco()、
               开关/设置页的折叠分组也在下面被 goSetting 复用。 */
            dash: function (main) {
                OwApi.post('admin_stats', {}, function (r) {
                    if (!r || !r.ok) {
                        main.innerHTML = '<h2>仪表盘</h2><div class="ow-card">' + esc((r && r.msg) || '统计接口没有响应') + '</div>';
                        return;
                    }
                    var d = r.data || {};
                    /** 千分位：四位数以上一眼读不出来（「12345」vs「12,345」） */
                    var n = function (v) { return Number(v || 0).toLocaleString(); };
                    var stat = function (label, num, sub) {
                        return '<div class="ow-stat"><div class="ow-stat-n">' + n(num) + '</div>'
                             + '<div class="ow-stat-l">' + esc(label) + '</div>'
                             + (sub ? '<div class="ow-stat-s">' + sub + '</div>' : '') + '</div>';
                    };
                    var todayOf = function (v) { return v > 0 ? '今日 <b class="ow-stat-up">+' + n(v) + '</b>' : '今日 +0'; };
                    var days = d.days || [], i;
                    /* 一行 7 根柱子：柱高按**本行**最大值归一 —— 三行各自成比例，
                       否则量级差十倍的那行会被压成一条线（用户 3 个 vs 好友 300 个）。 */
                    var trend = function (label, key) {
                        var vals = [];
                        for (i = 0; i < days.length; i++) vals.push(Number(days[i][key] || 0));
                        var max = Math.max.apply(null, vals.concat([1]));
                        var bars = '';
                        for (i = 0; i < days.length; i++) {
                            var v = vals[i], hp = Math.round(v / max * 100);
                            bars += '<div class="ow-bar' + (days[i].today ? ' is-today' : '') + '" title="'
                                 + esc(days[i].label) + '：' + n(v) + '">'
                                 + '<span class="ow-bar-v">' + (v ? n(v) : '') + '</span>'
                                 + '<i style="height:' + (v ? Math.max(8, hp) : 3) + '%"></i>'
                                 + '<span class="ow-bar-l">' + esc(days[i].label) + '</span></div>';
                        }
                        return '<div class="ow-trend-row"><span class="ow-trend-n">' + esc(label) + '</span>'
                             + '<div class="ow-bars">' + bars + '</div></div>';
                    };
                    /* 快捷访问 = 系统设置里最常改的那几条。第三列是图标名（OW_ICONS 里有的）。 */
                    var quick = [
                        ['allow_register', '开放注册', 'user'],
                        ['reg_email_verify', '注册需邮箱验证', 'lock'],
                        ['guest_chat', '游客可发言', 'users'],
                        ['guest_msg_interval', '游客发言间隔', 'clock'],
                        ['login_fail_lock', '登录失败锁定', 'shield'],
                        ['msg_retain_days', '消息保留天数', 'file'],
                        ['debug_mode', '调试模式', 'gear'],
                        ['site_name', '站点名称', 'mega']
                    ];
                    var chips = '';
                    for (i = 0; i < quick.length; i++) {
                        chips += '<button type="button" class="ow-quick" onclick="OwAdmin.goSetting(\'' + quick[i][0] + '\')">'
                              + owIco(quick[i][2], 14) + '<span>' + esc(quick[i][1]) + '</span>'
                              + '<span class="ow-quick-go">›</span></button>';
                    }
                    var env = d.env || {};
                    main.innerHTML = '<h2>仪表盘</h2><p class="ow-admin-desc">站点概况与常用入口。数字实时统计，刷新即最新。</p>'
                        + OwAdmin.clockHtml()
                        + '<div class="ow-dash-grid">'
                        + stat('用户总数', d.users, todayOf(d.users_today))
                        + stat('群聊总数', d.rooms, todayOf(d.rooms_today))
                        + stat('群聊消息', d.msg_group, '累计')
                        + stat('私聊消息', d.msg_dm, '累计')
                        + stat('好友关系', d.friends, '累计')
                        + stat('当前在线', d.online, '近 45 秒有心跳')
                        + '</div>'
                        + '<div class="ow-card"><h3>今日新增</h3><div class="ow-dash-today">'
                        + '<div class="ow-dash-today-i"><b>' + n(d.users_today) + '</b><span>新注册用户</span></div>'
                        + '<div class="ow-dash-today-i"><b>' + n(d.rooms_today) + '</b><span>新创建群组</span></div>'
                        + '</div></div>'
                        + '<div class="ow-card"><h3>近 7 天新增</h3>'
                        + trend('用户', 'users') + trend('群聊', 'rooms') + trend('好友', 'friends')
                        + '</div>'
                        + '<div class="ow-card"><h3>快捷访问</h3>'
                        + '<p style="font-size:12px;color:var(--ow-text-sub);margin:0 0 10px">系统设置里常改的那几条，点一下直达（自动展开所属分组并高亮）。</p>'
                        + '<div class="ow-quick-wrap">' + chips + '</div></div>'
                        + '<p class="ow-dash-env">Owlsgo ' + esc(env.version || '') + ' · PHP ' + esc(env.php || '')
                        + ' · ' + esc(env.driver || '') + ' · ' + esc(env.now || '') + '</p>';
                    // 时钟在 HTML 落地后起步：先画一帧（不等下一秒），再交给秒表走
                    OwAdmin.clockStart();
                });
            },
            plugins: function (main) {
                OwApi.post('admin_plugins', {}, function (r) {
                    var h = '<h2>插件管理</h2><p class="ow-admin-desc">安装（上传 zip）、启用 / 停用、下载与卸载插件。插件存放于 plugins/ 目录。</p>'
                        + '<div class="ow-card ow-upload-row">'
                        + '<input type="file" id="owPluginZip" accept=".zip" style="display:none">'
                        + '<button type="button" class="ow-btn ow-btn-ghost" onclick="document.getElementById(\'owPluginZip\').click()">选择文件</button>'
                        + '<span class="ow-upload-name" id="owPluginZipName">未选择文件</span>'
                        + '<button type="button" class="ow-btn ow-btn-primary" style="margin-left:auto" onclick="OwAdmin.pluginInstall()">上传安装</button>'
                        + '</div>'
                        // 搜索框：列表已有二十几项，逐个扫眼睛比打字慢得多（v1.3.67）。
                        // 纯前端过滤 —— 数据本来就一次性下发全量，没有再问服务端的理由。
                        + '<div class="ow-card"><div class="ow-form-row" style="align-items:center">'
                        + '<div class="ow-form-item" style="flex:1 1 240px;min-width:240px;max-width:520px"><label>搜索插件</label>'
                        + '<input class="ow-input" id="owPluginSearch" type="text" autocomplete="off" '
                        + 'placeholder="插件名 / ID / 描述 / 来源" oninput="OwAdmin.pluginFilter()"></div>'
                        + '<span id="owPluginHit" style="margin-left:auto;color:var(--ow-text-sub);font-size:12px"></span>'
                        + '</div></div>'
                        + '<div class="ow-plugin-list">';
                    // v1.2.57：点标题直达该插件的后台页。
                    // 条件：已启用 **且** slug 在服务端下发的 admin_pages 清单里
                    // ——没后台页的插件（如 signature）保持普通文字，不给「点了没反应」的假链接。
                    var slugs = r.admin_pages || [];
                    for (var i = 0; i < r.data.length; i++) {
                        var d = r.data[i];
                        var canOpen = d.enabled && slugs.indexOf(d.id) >= 0;
                        // 命中用的关键词串：渲染时拼好并转小写存进属性，避免每次按键重新拼。
                        // 只收列表上看得见的那四项，与 placeholder 一字不差 ——
                        // 搜一个页面里不显示的字段（如 author）只会让人误猜。
                        var hay = (d.name + ' ' + d.id + ' ' + (d.description || '') + ' '
                                 + (d.source || '本地')).toLowerCase();
                        h += '<div class="ow-plugin-card' + (d.enabled ? ' ow-plug-on' : '') + '" data-owplug-k="' + esc(hay) + '">'
                           + '<div class="ow-plugin-head">'
                           + (canOpen
                               ? '<b class="ow-plugin-title-link" title="打开「' + esc(d.name) + '」设置页"'
                                 + ' onclick="OwAdmin.pluginOpen(\'' + esc(d.id) + '\')">' + esc(d.name) + '</b>'
                               : '<b>' + esc(d.name) + '</b>')
                           // v1.5.11：不再画「启用 / 未启用」徽标 —— 状态改由整卡底色承担
                           // （.ow-plugin-card.ow-plug-on → #F6FFED），徽标字太小读不出来。
                           + '</div>'
                           + '<div class="ow-plugin-meta">' + esc(d.id) + ' · v' + esc(d.version) + ' · ' + esc(d.source || '本地')
                           // 维护任务数放在最前：它是「这个插件会自己在后台动什么」的规模指标，
                           // 比「注册了几个函数」更值得管理员先看到（v1.1.13）
                           + ' · 维护任务 ' + (d.crons || 0)
                           + ' · 钩子 ' + (d.hooks || 0) + ' · 路由 ' + (d.routes || 0) + ' · 后台页 ' + (d.pages || 0) + '</div>'
                           + '<div class="ow-plugin-desc">' + esc(d.description || '') + '</div>'
                           + '<div class="ow-plugin-actions">'
                           + '<button class="ow-btn ow-btn-primary" onclick="OwAdmin.pluginToggle(\'' + esc(d.id) + '\',1)"' + (d.enabled ? ' disabled' : '') + '>启用</button>'
                           + '<button class="ow-btn ow-btn-ghost" onclick="OwAdmin.pluginToggle(\'' + esc(d.id) + '\',0)"' + (d.enabled ? '' : ' disabled') + '>停用</button>'
                           + '<a class="ow-btn ow-btn-ghost" href="?action=admin_plugin_download&name=' + esc(d.id) + '">下载</a>'
                           + '<button class="ow-btn ow-btn-ghost" onclick="OwAdmin.pluginUninstall(\'' + esc(d.id) + '\')">卸载</button>'
                           + '</div></div>';
                    }
                    if (!r.data.length) h += '<div class="ow-card" style="color:var(--ow-text-sub)">暂无插件</div>';
                    // 搜空了要给一句话说明，否则满屏空白看着像页面坏了
                    else h += '<div class="ow-card" id="owPluginNone" style="color:var(--ow-text-sub);display:none">没有匹配的插件，换个关键词试试。</div>';
                    main.innerHTML = h + '</div>';
                    /* 自研上传控件：隐藏原生 file input，选择后回显文件名 */
                    var zip = $('owPluginZip');
                    if (zip) zip.onchange = function () {
                        var name = $('owPluginZipName');
                        if (this.files && this.files[0]) {
                            name.textContent = this.files[0].name;
                            name.className = 'ow-upload-name ow-has-file';
                        } else {
                            name.textContent = '未选择文件';
                            name.className = 'ow-upload-name';
                        }
                    };
                    // 上传安装成功后会重渲染本页（OwAdmin.page('plugins')）：把关键词捡回来，
                    // 免得刚搜的东西被一次刷新抹掉
                    var si = $('owPluginSearch');
                    if (si && OwAdmin._plugQ) si.value = OwAdmin._plugQ;
                    OwAdmin.pluginFilter();
                });
            },
            rooms: function (main) {
                // 群聊审核（v1.0.84）：服务端分页 + 搜索 + 多选批量 + 回收站可撤销
                OwAdmin._roomPage = 1;
                OwAdmin._roomTrashPage = 1;
                main.innerHTML = '<h2>群聊审核</h2><p class="ow-admin-desc">对群聊做合规处置：名称 / 头像不合法可重置，违规群聊可封禁或删除。所有处置均可在回收站撤销。</p>'
                    + '<div class="ow-card"><div class="ow-form-row">'
                    + '<div class="ow-form-item" style="min-width:120px"><label>房主用户ID</label>'
                    + '<input class="ow-input" id="owRVRoomOwner" type="number" min="0" placeholder="0=全部" value="0" onkeydown="if(event.key===\'Enter\')OwAdmin.roomLoad(1)"></div>'
                    + '<div class="ow-form-item" style="min-width:120px"><label>群聊ID</label>'
                    + '<input class="ow-input" id="owRVRoomId" type="number" min="0" placeholder="0=全部" value="0" onkeydown="if(event.key===\'Enter\')OwAdmin.roomLoad(1)"></div>'
                    + '<button class="ow-btn ow-btn-primary" onclick="OwAdmin.roomLoad(1)">搜索</button>'
                    + '<button class="ow-btn ow-btn-ghost" onclick="OwAdmin.roomResetFilter()">重置</button>'
                    + '</div></div>'
                    + '<div class="ow-card">'
                    + '<div id="owRVBatch"></div>'
                    + '<div class="ow-table-wrap"><table class="ow-table" id="owRVTable"></table></div>'
                    + '<div id="owRVPager" style="margin-top:10px"></div></div>'
                    + '<div class="ow-card">'
                    // v1.4.4：回收站改吃通用列表顶栏 .ow-list-head —— 标题在左、分页 tab 在右，
                    // 下沿一条分割线接住下面的表格（原先是 h3 + 行内样式，分页器孤零零在表底下）。
                    + '<div class="ow-list-head"><b class="ow-list-head-t">审核回收站</b>'
                    + '<div id="owTrashPager"></div></div>'
                    // v1.4.5：回收站记录只增不减，处置一多就翻不动 —— 加筛选（群名/群聊ID 关键词 +
                    // 处置类型 + 是否已撤销）与批量撤销。筛选行放在顶栏与表格之间，与上方主表同构。
                    + '<div class="ow-form-row">'
                    + '<div class="ow-form-item" style="min-width:160px;flex:1 1 160px"><label>群名 / 群聊ID</label>'
                    + '<input class="ow-input" id="owRVTrashKw" type="text" autocomplete="off" placeholder="输入群名，或直接填数字ID"'
                    + ' onkeydown="if(event.key===\'Enter\')OwAdmin.roomTrashLoad(1)"></div>'
                    + '<div class="ow-form-item" style="min-width:120px"><label>处置类型</label>'
                    + '<select class="ow-input" id="owRVTrashAct" onchange="OwAdmin.roomTrashLoad(1)">'
                    + '<option value="">全部</option><option value="reset_name">重置名称</option>'
                    + '<option value="reset_avatar">重置头像</option><option value="toggle_status">封禁/解封</option>'
                    + '<option value="delete">删除</option></select></div>'
                    + '<div class="ow-form-item" style="min-width:110px"><label>状态</label>'
                    + '<select class="ow-input" id="owRVTrashUndone" onchange="OwAdmin.roomTrashLoad(1)">'
                    + '<option value="">全部</option><option value="0">未撤销</option><option value="1">已撤销</option></select></div>'
                    + '<button class="ow-btn ow-btn-primary" onclick="OwAdmin.roomTrashLoad(1)">搜索</button>'
                    + '<button class="ow-btn ow-btn-ghost" onclick="OwAdmin.roomTrashResetFilter()">重置</button>'
                    + '</div>'
                    + '<div id="owTrashBatch"></div>'
                    + '<div class="ow-table-wrap"><table class="ow-table" id="owRoomTrash"></table></div>'
                    + '<p style="font-size:12px;color:var(--ow-text-sub);margin:8px 0 0">撤销有顺序依赖：群聊被删除后，需先撤销「删除」才能恢复其之前的名称 / 头像 / 封禁状态。批量撤销会自动按这个顺序处理。</p></div>';
                // 批量操作改下拉（v1.2.60）：原先平铺 4 个按钮，窄屏会折成两行并挤掉统计位
                OwAdmin.uiBatchBar({
                    id: 'owRVBatch',
                    chkCls: 'owRVChk',
                    statBase: '共 0 条',
                    actions: [
                        { key: 'reset_name',    label: '批量重置名称' },
                        { key: 'reset_avatar',  label: '批量重置头像' },
                        { key: 'toggle_status', label: '批量封禁 / 解封' },
                        { key: 'delete',        label: '批量删除', danger: true }
                    ],
                    onExec: function (act, ids) { OwAdmin.roomBatch(act, ids); }
                });
                // v1.4.5：回收站只有「撤销」一个动作，但复用同一个批量条轮子 ——
                // 勾选统计、执行按钮禁用态、危险态配色都不用再写一遍。
                OwAdmin.uiBatchBar({
                    id: 'owTrashBatch',
                    chkCls: 'owRVTrashChk',
                    statBase: '共 0 条',
                    actions: [{ key: 'undo', label: '批量撤销', danger: true }],
                    onExec: function (act, ids) { OwAdmin.roomTrashBatch(act, ids); }
                });
                OwAdmin.roomLoad(1);
                OwAdmin.roomTrashLoad(1);
            },
        /* ⚠️ 动作/字段中文表与详情解析挂在 OwAdmin **顶层**（_logActs 等），不在这里 ——
           pages 表成员只能被 page(ap) 分发调用，而筛选下拉的 onchange 直接引用
           OwAdmin.logAct / OwAdmin.logLoad，必须放顶层才可达。 */
        logs: function (main) {
            OwAdmin._logPage = 1;
            OwAdmin._logAct = '';
            OwAdmin._logTotal = 0;
            main.innerHTML = '<div class="ow-admin-head">'
                + '<h2>安全日志</h2>'
                // 系统日志是独立的文件日志页（PHP 报错落盘），与本页的数据库审计流水是两套东西，
                // 故用按钮跳页而不是塞进同一张表
                + '<div class="ow-admin-head-act">'
                + '<button type="button" class="ow-btn ow-btn-ghost" onclick="OwAdmin.page(\'syslog\')">系统日志</button>'
                + '<button type="button" class="ow-btn ow-btn-ghost" onclick="OwAdmin.logExport()">导出 CSV</button>'
                + '</div></div>'
                + '<p class="ow-admin-desc">记录登录、封禁、群聊处置、插件安装等关键安全事件（IP 与数据已脱敏）。'
                + '「详情」列是事件的可读说明；动作多时用顶部下拉筛选定位。</p>'
                + '<div class="ow-card"><div class="ow-form-row" style="align-items:center">'
                + '<div class="ow-form-item" style="min-width:240px"><label>按动作筛选</label>'
                + '<select class="ow-input" id="owLogAct" onchange="OwAdmin.logAct(this.value)"></select></div>'
                + '<span id="owLogStat" style="margin-left:auto;color:var(--ow-text-sub);font-size:12px"></span>'
                + '</div></div>'
                + '<div class="ow-card">'
                + '<div id="owLogBatch"></div>'
                + '<div class="ow-table-wrap"><table class="ow-table" id="owLogTable"></table></div>'
                + '<div id="owLogPager" style="margin-top:10px"></div></div>';
            // 批量轮子：当前仅「批量删除」；导出走顶部独立按钮（导出全量而非所选）
            OwAdmin.uiBatchBar({
                id: 'owLogBatch',
                chkCls: 'owLogChk',
                statBase: '共 0 条',
                actions: [{ key: 'del', label: '批量删除所选日志', danger: true }],
                onExec: function (act, ids) {
                    OwAdmin.confirm('确定删除选中的 ' + ids.length + ' 条安全日志？该操作不可恢复。', function () {
                        OwApi.secure('admin_logs_batch', { ids: ids.join(',') }, function (r) {
                            toast(r.msg);
                            if (r.ok) OwAdmin.logLoad(OwAdmin._logPage || 1);
                        });
                    });
                }
            });
            OwAdmin.logLoad(1);
        },
        /* ---------- 系统日志（PHP 报错文件日志，v1.2.60） ---------- */
        syslog: function (main) {
            OwAdmin._sysFile = '';
            main.innerHTML = '<div class="ow-admin-head">'
                + '<h2>系统日志</h2>'
                + '<div class="ow-admin-head-act">'
                + '<button type="button" class="ow-btn ow-btn-ghost" onclick="OwAdmin.page(\'logs\')">返回操作日志</button>'
                + '<button type="button" class="ow-btn ow-btn-ghost" id="owSysClear" onclick="OwAdmin.syslogClear()" disabled>清空当前文件</button>'
                + '</div></div>'
                + '<div id="owSysTip"></div>'
                + '<div class="ow-card"><div class="ow-syslog-tabs" id="owSysTabs"></div>'
                + '<div class="ow-syslog-body" id="owSysBody">加载中…</div></div>';
            OwApi.post('admin_syslog', {}, function (r) {
                var tip = $('owSysTip'), tabs = $('owSysTabs'), body = $('owSysBody');
                if (!tip || !tabs || !body) return;
                if (!r.ok) { body.innerHTML = '<div class="ow-syslog-line">加载失败：' + esc(r.msg) + '</div>'; return; }
                var files = r.files || [], h = '', i;
                // 调试模式关闭时的说明条：这是「日志页空着」最常见的疑问来源，
                // 直接讲清楚开关在哪、为什么没日志，而不是让人以为功能坏了
                tip.innerHTML = r.debug ? ''
                    : '<div class="ow-notice ow-notice-warn">'
                    + '<b>当前「调试模式」为关闭</b>（后台「系统设置 → 系统与维护 → 调试模式」）。'
                    + '这种状态下不会记录任何 debug 级日志，所以这里看不到内容是正常的。'
                    + '需要排查细节时，把该开关打开再复现一次即可，用完请及时关闭。'
                    + '<br>另外请放心：<b>安全日志</b>（登录、封禁、群聊处置等审计流水）与调试模式无关，'
                    + '无论开关与否都会照常记录。'
                    + '</div>';
                if (!files.length) {
                    tabs.innerHTML = '';
                    body.innerHTML = '<div class="ow-syslog-line">暂无日志文件。开启调试模式后，PHP 报错会自动记录到 '
                        + '<code>data/logs/debug.log</code>。</div>';
                    return;
                }
                for (i = 0; i < files.length; i++) {
                    var f = files[i];
                    // data-file + 事件委托而非内联 onclick：避免把文件名拼进 JS 字符串
                    h += '<button type="button" class="ow-syslog-tab" data-file="' + esc(f.name) + '">'
                       + '<b>' + esc(f.name) + '</b>'
                       + '<span>' + (f.size >= 1024 ? (f.size / 1024).toFixed(1) + ' KB' : f.size + ' B')
                       + ' · ' + esc(new Date(f.mtime * 1000).toLocaleString()) + '</span></button>';
                }
                tabs.innerHTML = h;
                // 事件委托：只在容器上挂一个监听，切换文件时无需重复绑定
                tabs.onclick = function (e) {
                    var b = e.target;
                    while (b && b !== this && b.tagName !== 'BUTTON') b = b.parentNode;
                    if (b && b.tagName === 'BUTTON' && b.getAttribute('data-file')) {
                        OwAdmin.syslogOpen(b.getAttribute('data-file'));
                    }
                };
                OwAdmin.syslogOpen(files[0].name);
            });
        },
            /* 维护任务（v1.1.13 引入时称「计划任务」）：插件通过 Plugin::cron() 声明式注册的任务。
               与旧的 cron.minute 钩子不同——那些任务在这里不可见、不可控。
               任务表只显示「已注册」的：插件停用后其任务仍在表里但每次都会被跳过，
               服务端用 plugin_active 标记，前端据此隐藏启停开关（避免给一个必然被跳过的
               任务提供「启用」按钮）。 */
            cron: function (main) {
                OwAdmin._cronPage = 1;
                OwAdmin.cronLoad(main);
            },
            settings: function (main) {
                OwApi.post('admin_settings_get', {}, function (r) {
                    var d = r.data;
                    /* v1.2.52：布尔类设置全部改用通用开关轮子（原来是一排下拉）。
                       d[k] 是 DB setting 的字符串 '1'/'0'；缺键时用与 core/db.php
                       DB::defaults 一致的默认值，避免「库里没值 → 开关显示关、实际按默认开」。
                       ⚠️ debug_mode 默认必须给 '0'：它不在 defaults 里（后端读
                       DB::setting('debug_mode','0')），而旧的下拉把「开启」写在第一项，
                       缺键时浏览器会选中第一项 → 显示「开启」但实际是关的。 */
                    function sw(k, label, def) {
                        var v = (d[k] === undefined || d[k] === null || d[k] === '') ? def : d[k];
                        // v1.2.53：开关**不要包 .ow-form-item** —— 包了会被 .ow-form-row 的
                        // min-width 卡成窄列，轨道被推到列最右侧，与文字隔一大片空白
                        //（用户报的「样式错乱」）。开关行自带 flex 布局，直接平铺即可。
                        return switchHtml('owS_' + k, label, v === '1');
                    }
                    /* 输入框（文本 / 数值） */
                    function fld(k, label, def, placeholder) {
                        return '<div class="ow-form-item"><label>' + esc(label) + '</label>'
                            + '<input class="ow-input" id="owS_' + k + '" value="'
                            + esc((d[k] === undefined || d[k] === null || d[k] === '') ? def : d[k]) + '"'
                            + (placeholder ? ' placeholder="' + esc(placeholder) + '"' : '') + '></div>';
                    }
                    function row(items) { return '<div class="ow-form-row">' + items + '</div>'; }
                    function note(html) {
                        return '<p style="font-size:12px;color:var(--ow-text-sub);margin:4px 0 12px">' + html + '</p>';
                    }
                    /* v1.2.53 折叠分组（.ow-fold，样式见 index.css）——与插件后台的分组同一思路，
                       点标题展开 / 收起。
                       v1.2.54：**默认全部收起**（用户定版）——先给分组目录，点哪个看哪个，
                       不用在一长条表单里找。收起时输入框仍在 DOM 里，settingsSave 照常提交
                       **全部分组**的值，不存在「收起就没保存」。 */
                    function sec(title, body, dotCls) {
                        return '<div class="ow-fold">'
                            + '<div class="ow-fold-hd" onclick="OwAdmin.foldToggle(this)">' + esc(title)
                            // 分组标题的红点与组内那条设置的红点同源（同一个 class，一起点亮）：
                            // 分组默认全收起，光有组内红点等于要先进去才知道有没有事。
                            // 位置在标题之后、箭头之前——箭头靠 margin-left:auto 自己顶到最右。
                            + (dotCls ? '<span class="ow-dot ' + dotCls + '" title="有新版本可用" aria-hidden="true" style="display:none"></span>' : '')
                            + '<span class="ow-fold-arrow">&#9656;</span></div>'
                            + '<div class="ow-fold-bd">' + body + '</div></div>';
                    }
                    main.innerHTML = '<h2>系统设置</h2><p class="ow-admin-desc">站点、注册控制、游客与发言限制、存储方式。点分组标题展开对应设置。</p>'

                        /* ---------- ① 基本信息 ---------- */
                        + sec('基本信息',
                              fld('site_name', '站点名称', '')
                            + fld('site_url', '固定网站地址', '', '留空自动识别')
                            // v1.5.4：积分名称 —— 全站（含插件）凡写「积分」的地方都改用它。
                            // 空 = 默认「积分」；插件侧取值见 PLUGIN.md（ow_points_name / pointsName）。
                            + fld('points_name', '积分名称', '积分', '留空 = 积分')
                            + note('把「积分」换成站点自己的叫法（如金币、星光），'
                                + '签到、建群扣费、用户管理等所有文案随之一并变更。最多 8 个字，'
                                + '留空恢复默认的「积分」。'))

                        /* ---------- ② 注册登录：注册门槛 + 登录保护合在同一组（v1.2.54） ----------
                           原来「开放注册」在基本信息、「登录失败几次后锁定」在系统与维护，
                           其实都是「谁能进站 / 怎么防暴力破解」，拆在两处来回翻很别扭。 */
                        + sec('注册登录',
                              sw('allow_register', '开放注册', '1')
                            + sw('reg_email_verify', '注册需邮箱验证', '1')
                            + fld('min_register_age', '注册最低年龄(周岁)', '0')
                            + note('填 0 表示不限制；填 18 则注册时必须选择出生日期且年满 18 周岁（按日期精确计算）。')
                            + row(fld('login_fail_captcha', '登录失败几次后要求验证码', '3')
                                + fld('login_fail_lock', '登录失败几次后锁定', '10')
                                + fld('login_lock_minutes', '锁定时长(分钟)', '15'))
                            + note('登录保护：验证码填错也计入失败次数（保证锁定可达），'
                                + '锁定按「账号+IP」记录，成功后清零。全部填 0 表示关闭对应保护。'))

                        /* ---------- ③ 群聊 ---------- */
                        + sec('群聊',
                              sw('room_create_allow', '允许用户创建群聊', '1')
                            // v1.1.14 仅邀请群总闸：与「允许用户创建群聊」正交 ——
                            // 那个管能不能建群，这个管建出来的群能不能藏起来。
                            + sw('room_private_create_allow', '允许用户创建仅邀请群聊', '1')
                            + fld('room_pass_ttl', '密码房通行缓存(秒)', '1800')
                            + note('验证一次密码后，该时间内进入同一房间无需重复输入；填 0 表示每次进入都要输入。')
                            + note('创建群聊：管理员创建始终免费。用户创建的群聊 owner 归属创建者，可在群聊管理中调整。<br>'
                                // v1.1.18：这里原本写「只能创建普通群聊」，指的是**公开性**
                                // （is_public），不是房间类型，已改为「公开群聊」。
                                // 教训：公开性的中文不能叫「普通」，会与 type=public 的「普通」撞词。
                                + '仅邀请群聊只靠邀请链接传播，不出现在任何列表里。关闭后普通用户只能创建公开群聊，'
                                + '已存在的仅邀请群仍可正常改名、改简介（仅禁止把公开群改成仅邀请）；管理员始终不受此限制。'))

                        /* ---------- ④ 游客、存储 ---------- */
                        // v1.2.41：「允许上传文件 / 单文件大小上限(MB) / 允许的文件扩展名」
                        //   三项已移入**附件上传插件**的后台页（插件管理 → 附件上传），
                        //   核心设置页不再出现，也不再读这三个键。
                        + sec('游客、存储',
                              sw('guest_browse', '游客可浏览', '1')
                            + sw('guest_chat', '游客可发言', '1')
                            + fld('guest_msg_interval', '游客发言间隔(秒)', '30')
                            // v1.3.104：发言频率的两项已移到「插件 → 垃圾信息控制 → 刷屏」，
                            // 与新增的「两条消息最小间隔」放在一起才看得懂全局（键名不变，
                            // 仍是核心的 msg_rate_window / msg_rate_max，这里只是撤掉编辑入口）。
                            + note('发言频率窗口与窗口内最大条数已移到「插件管理 → 垃圾信息控制 → 刷屏」，'
                                + '那里还多了一条「两条消息最小间隔」。存储键没变，只是编辑入口收在一处。')
                            // v1.3.55：「邮件发送间隔(秒)」已移入**邮箱验证插件**后台页
                            //   （连同有效期、各类限流、后缀白名单）。键仍是核心的
                            //   mail_rate_limit，只是编辑入口收在一处，避免两个页面改同一个值。
                            // v1.2.2 消息服务器保留期：到期即物理清除（附件同步删），无法恢复
                            + fld('msg_retain_days', '消息服务器保留期(天)', '90')
                            + note('超过本期限的消息会被<b>物理删除</b>，其附件文件（uploads/file/）一并删除，'
                                + '<b>删除后无法恢复</b>。默认 90 天（约三个月）。填 0 表示永久保留。<br>'
                                + '「<b>删除</b>」只在本机生效（仅你看不到，别人照常看得到）；'
                                + '「<b>撤回</b>」才是全局删除，所有人都不再显示且不可恢复。'))

                        /* ---------- ⑤ 系统与维护 ---------- */
                        + sec('系统与维护',
                              sw('sound_default', '新消息提示音默认', '1')
                            // v1.2.51 调试模式：排错开关，默认关（后端 DB::setting 默认也是 0）
                            + sw('debug_mode', '调试模式', '0')
                            + '<p style="font-size:12px;color:var(--ow-yellow);background:var(--ow-warn-bg);border:1px solid var(--ow-warn-border);border-radius:4px;padding:8px 10px;margin:4px 0 12px">'
                            + '开启后记录 debug 级日志、出错页显示详细报错。仅用于排错，用完请及时关闭 —— 报错细节可能暴露路径、SQL 与配置信息。<br>'
                            + '日志文件：data/logs/debug.log（页面报错实时显示；接口请求只落日志、不回显，避免破坏前端数据）。'
                            + '也可在「安全日志 → 系统日志」查看与清空。</p>'
                            // P1（WS 实时层）：实时通道开关与守护进程端口。
                            // 默认关闭 = 与长轮询时代完全一致（容器/虚拟主机跑不了常驻进程就别开）；
                            // 开启前先部署守护进程与 nginx /ws 反代（步骤见 ws-server.php 头部说明）。
                            + sw('ws_enable', 'WS 实时通道', '0')
                            + fld('ws_port', 'WS 守护进程端口', '8790')
                            + note('开启后前端会并行连一条 wss 做心跳与在线状态加速（消息仍走长轮询），'
                                + '连不上自动退回、关闭即一键切回，聊天功能不受影响。启用两步：'
                                + '① Linux 上用 PM2 起守护进程 —— pm2 start "php ws-server.php" --name owlsgo-ws，'
                                + '再执行 pm2 save（配合 pm2 startup 实现开机自启）；'
                                + '② 确认 nginx 已有 /ws 反代（nginx-server.conf 自带，容器部署需手动同步）。'
                                + '改端口需重启守护进程，并同步改 nginx 里的同一端口。')
                            // P3（发送迁移）：WS 发送开关与数值/白名单。默认关 = 消息仍走 HTTP 发送。
                            + sw('ws_send_enable', 'WS 发送（消息经实时通道）', '0')
                            + fld('ws_send_rooms', 'WS 发送房间白名单（逗号分隔房间号，空=全站）', '')
                            + fld('ws_send_timeout', 'WS 发送读超时(秒)', '8')
                            + fld('ws_send_max_inflight', 'WS 发送全站在途上限', '16')
                            + note('开启后消息发送走实时通道（守护进程回环到站点执行同一发送逻辑，插件长任务不阻塞实时通道），'
                                + '断线或守护进程不在时自动回落 HTTP；发送失败的气泡上带「重试」，重试不会重复（幂等键）。'
                                + '读超时按「最慢插件」设置；全站在途建议取 php-cgi 池进程数的 1.5 倍；'
                                + '白名单非空时只有列出的群房间走 WS 发送（灰度用），私聊与密码房不受其约束。')
                            // v1.3.167 执行日志保留期：维护任务页的「清理过期日志」按钮
                            // 与每天一次的 purge_cron_logs 任务共用这一项（一处改、两处生效）。
                            + fld('cron_log_retain_days', '执行日志保留期(天)', '90')
                            + note('维护任务的执行日志超过本期限后会被<b>物理清除</b>，无法恢复。'
                                + '每天由计划任务「清理超过保留期的执行日志」自动清一次，'
                                + '维护任务页的「清理过期日志」按钮立即按同一期限清一次。默认 90 天，填 0 表示永久保留。')
                            // v1.2.60 运行缓存：放在「系统与维护」分组内，说明文字走 ow-tip
                            // （oneItem 会把 .ow-form-item 里的提示元素转成 label 上的 ⓘ 气泡）。
                            // 它是一次性动作而非设置项，所以不随「保存设置」提交，按钮走独立接口。
                            + '<div class="ow-form-item">'
                            + '<label>运行缓存</label>'
                            + '<button type="button" class="ow-btn ow-btn-ghost" id="owOpcacheBtn" onclick="OwAdmin.opcacheReset()">清理 OPcache</button>'
                            + '<button type="button" class="ow-btn ow-btn-ghost" id="owCacheBtn" onclick="OwAdmin.cacheClear()" style="margin-left:8px">刷新数据缓存</button>'
                            + '<span id="owOpcacheStat" style="font-size:12px;color:var(--ow-text-sub);margin-left:10px"></span>'
                            + '<span id="owCacheStat" style="font-size:12px;color:var(--ow-text-sub);margin-left:10px"></span>'
                            // 必须是<p> 且字号 ≤12.5px——OwTip.isHint() 就是按这两条认提示元素的，
                            // 写成 <span> 或 class 写错都不会被转成 ⓘ 气泡
                            + '<p style="font-size:12px;color:var(--ow-text-sub);margin-top:4px">刷新已编译脚本缓存，适合代码更新后手动触发；「刷新数据缓存」会清插件清单、合并资源、词库编译与 Redis 缓存，插件启停或数据不同步时用。'
                            + 'PHP 会把源码编译成字节码缓存在内存里（OPcache），更新文件后若仍跑旧代码'
                            + '（尤其是关闭了时间戳校验时），清一次缓存即可立即生效。开启调试模式时会写 '
                            + 'data/logs/debug.log，本操作不影响它。</p>'
                            + '</div>'
                            // v1.3.42 系统升级：入口按钮 + 内联模块面板（检查/升级/恢复备份）。
                            // 红点 = settings.update_info.has_update（每 12h 计划任务 + 手动检查都会刷）。
                            // v1.3.102 起它只表示「仓库确有新版可拿」= 本地 VERSION 落后；
                            // 「本地改了还没推」不再点亮红点（那是常驻状态，点久了只会把红点关掉）。
                            // v1.3.42：原「版本号」展示行已删（升级面板里本地/远端版本对比更全）。
                            + '<div class="ow-form-item"><label>系统升级'
                            + '<span id="owUpdDot" class="ow-dot ow-upd-dot" title="有新版本可用" aria-hidden="true" style="display:none"></span></label>'
                            + '<button type="button" class="ow-btn ow-btn-ghost" id="owUpdBtn" onclick="OwAdmin.upgrade.check()">检查更新</button>'
                            + '<p style="font-size:12px;color:var(--ow-text-sub);margin-top:4px">按 GitHub 仓库逐文件比对，只更新有差异的代码文件。'
                            + '保护路径永不动：core/config.php、data/、uploads/；覆盖前自动备份到 data/backup/，可一键恢复。'
                            + '需服务器能访问 api.github.com；搬入阶段文件瞬时替换，避开高峰操作。</p></div>'
                            + '<div id="owUpdPanel" class="ow-card" style="display:none;margin:6px 0 12px"></div>',
                            'ow-upd-dot')

                        /* ---------- ⑥ 语言（v1.3.44）----------
                           选项来自 OwI18n.list()（语言包插件 register 注入），
                           没装任何语言包时只有「简体中文」一项，功能自然隐身。 */
                        + sec('语言', (function () {
                            var langs = (w.OwI18n && w.OwI18n.list) ? w.OwI18n.list() : [];
                            var cur = (d.ow_lang_default === undefined || d.ow_lang_default === '') ? 'zh' : d.ow_lang_default;
                            var opts = '<option value="zh"' + (cur === 'zh' ? ' selected' : '') + '>简体中文（默认，不翻译）</option>';
                            for (var li = 0; li < langs.length; li++) {
                                opts += '<option value="' + esc(langs[li].code) + '"' + (cur === langs[li].code ? ' selected' : '') + '>' + esc(langs[li].label) + '</option>';
                            }
                            return '<div class="ow-form-item"><label>站点默认语言</label>'
                                + '<select class="ow-input" id="owS_ow_lang_default">' + opts + '</select>'
                                + '<p style="font-size:12px;color:var(--ow-text-sub)">只翻译系统界面文字（含提示），聊天消息、昵称、群名等用户内容不翻译。'
                                + '访客在登录页右上角切换后以自己的选择为准；保存默认语言会清掉你本浏览器的个人选择，立即按新默认显示。</p></div>';
                        })())

                        + '<button class="ow-btn ow-btn-primary" onclick="OwAdmin.settingsSave()">保存设置</button>';

                    // 开关视觉同步（is-on 类）：新增 DOM 后必须调，轨道才有开/关配色
                    bindSwitches(main);
                    if (w.OwTip && typeof w.OwTip.scan === 'function') w.OwTip.scan(main);
                    OwAdmin.upgrade.paintDot(d.update_info);   // v1.3.42 系统升级红点
                });
            },
            /* 禁言管理自 v1.0.52 起剥离为插件 ban-manager，页面与交互见 plugins/ban-manager/ */
        },

        /* ---------- 用户管理动作已随 v1.0.44 剥离为插件（OwUM，plugins/user-manager/） ---------- */

        /* ---------- 房间动作 ---------- */
        /** 审核回收站：单条撤销（批量见 roomTrashBatch） */
        roomTrashUndo: function (id) {
            OwAdmin.confirm('确定撤销该审核操作？', function () {
                OwApi.secure('admin_room_trash_undo', { id: id }, function (r) {
                    toast(r.msg);
                    if (r.ok) { OwAdmin.roomTrashLoad(OwAdmin._roomTrashPage || 1); OwAdmin.roomLoad(OwAdmin._roomPage || 1); }
                });
            });
        },
        /* ---------- 群聊审核：搜索过滤 / 多选批量（v1.0.83） ---------- */

        /** 按房主ID / 群聊ID 过滤并渲染表格 */
        roomLoad: function (page) {
            this._roomPage = page || this._roomPage || 1;
            var self = this;
            OwApi.post('admin_rooms', {
                page: this._roomPage, size: 20,
                owner: ($('owRVRoomOwner') || {}).value || 0,
                rid: ($('owRVRoomId') || {}).value || 0
            }, function (r) {
                if (!r.ok) { toast(r.msg); return; }
                var d = r.data;
                self._roomAll = d.list;
                var h = '';
                for (var i = 0; i < d.list.length; i++) {
                    var d2 = d.list[i];
                    var av = d2.avatar
                        ? '<img src="' + esc(d2.avatar) + '" loading="lazy" decoding="async" style="width:28px;height:28px;border-radius:50%;object-fit:cover;display:block">'
                        : '<span style="display:block;width:28px;height:28px;border-radius:50%;background:var(--ow-bg-sub);color:var(--ow-text-sub);font-size:12px;line-height:28px;text-align:center">' + esc(d2.name.charAt(0)) + '</span>';
                    h += '<tr><td><input type="checkbox" class="owRVChk" value="' + d2.id + '" onchange="OwAdmin.roomSyncBatch()"></td>'
                       + '<td>' + esc(fmtUid(d2.id)) + '</td><td>' + av + '</td><td>' + esc(d2.name) + '</td>'
                       + '<td>' + (d2.owner_id ? esc(fmtUid(d2.owner_id)) : '-') + '</td>'
                       + '<td>' + (d2.status == 1 ? '正常' : '<span style="color:var(--ow-red)">已封禁</span>') + '</td>'
                       + '<td style="white-space:nowrap">'
                       + '<a href="javascript:;" onclick="OwAdmin.roomReview(' + d2.id + ',\'reset_name\')">名称不合法</a> '
                       + '<a href="javascript:;" onclick="OwAdmin.roomReview(' + d2.id + ',\'reset_avatar\')">头像不合法</a> '
                       + '<a href="javascript:;" onclick="OwAdmin.roomReview(' + d2.id + ',\'toggle_status\')">' + (d2.status == 1 ? '封禁' : '解封') + '</a> '
                       + '<a href="javascript:;" onclick="OwAdmin.roomDel(' + d2.id + ')">删除</a></td></tr>';
                }
                $('owRVTable').innerHTML = '<tr><th style="width:32px"><input type="checkbox" id="owRVCheckAll" onchange="OwAdmin.roomToggleAll(this)"></th>'
                    + '<th>ID</th><th>头像</th><th>名称</th><th>房主ID</th><th>状态</th><th>操作</th></tr>'
                    + (h || '<tr><td colspan="7" style="color:var(--ow-text-sub)">无匹配的群聊</td></tr>');
                self.uiPager('owRVPager', d.page, d.total, d.size, function (pg) { self.roomLoad(pg); });
                self.roomSyncBatch();
            });
        },

        roomResetFilter: function () {
            $('owRVRoomOwner').value = '0';
            $('owRVRoomId').value = '0';
            this.roomLoad(1);
        },

        roomToggleAll: function (cb) {
            var boxes = document.getElementsByClassName('owRVChk');
            for (var i = 0; i < boxes.length; i++) boxes[i].checked = cb.checked;
            this.roomSyncBatch();
        },

        roomSyncBatch: function () {
            this.batchSync('owRVChk', 'owRVBatch', '共 ' + ((this._roomAll || []).length) + ' 条');
        },

        /**
         * 批量审核（v1.0.83 补实现 v1.0.91，v1.2.60 接下拉轮子）：
         * act = reset_name / reset_avatar / toggle_status / delete。
         * ids 由 uiBatchBar 统一收集后传入，这里不再自己遍历复选框。
         */
        roomBatch: function (act, ids) {
            if (!ids || !ids.length) { toast('未选择群聊'); return; }
            var ACT = { reset_name: '批量重置名称', reset_avatar: '批量重置头像', toggle_status: '批量封禁/解封', delete: '批量删除' };
            var self = this;
            this.confirm('确定对已选 ' + ids.length + ' 个群聊执行「' + (ACT[act] || act) + '」？', function () {
                OwApi.secure('admin_room_batch', { act: act, ids: ids.join(',') }, function (r) {
                    toast(r.msg);
                    if (r.ok) {
                        self.roomLoad(self._roomPage || 1);
                        self.roomTrashLoad(self._roomTrashPage || 1);
                    }
                });
            });
        },

        /** 审核回收站：服务端分页 + 筛选（关键词 / 处置类型 / 是否已撤销），可单条或批量撤销 */
        roomTrashLoad: function (page) {
            this._roomTrashPage = page || this._roomTrashPage || 1;
            var self = this;
            OwApi.post('admin_room_trash_list', {
                page: this._roomTrashPage, size: 20,
                kw: (($('owRVTrashKw') || {}).value || '').trim(),
                act: ($('owRVTrashAct') || {}).value || '',
                undone: ($('owRVTrashUndone') || {}).value || ''
            }, function (r) {
                var el = document.getElementById('owRoomTrash');
                if (!el || !r.ok) return;
                var d = r.data;
                var ACT = { reset_name: '重置名称', reset_avatar: '重置头像', toggle_status: '封禁/解封', delete: '删除' };
                // 表头带全选框（v1.4.5 批量撤销需要），这张表原先没有表头，一并补上
                var h = '<tr><th style="width:32px"><input type="checkbox" id="owRVTrashCheckAll"'
                    + ' onchange="OwAdmin.roomTrashToggleAll(this)"></th>'
                    + '<th>时间</th><th>群聊</th><th>操作</th><th>操作前</th><th>状态</th><th></th></tr>';
                if (!d.list.length) { h += '<tr><td colspan="7" style="color:var(--ow-text-sub)">暂无审核记录</td></tr>'; }
                for (var i = 0; i < d.list.length; i++) {
                    var t = d.list[i];
                    var before = t.before_data && t.before_data.row ? '（整条群聊记录）'
                        : (t.before_data && typeof t.before_data === 'object' ? esc(Object.keys(t.before_data).map(function (k) { return k + '=' + t.before_data[k]; }).join('，')) : '-');
                    // 已撤销的行不给勾选框：再撤销必然失败（服务端回「该记录已撤销过」），
                    // 让人勾上一条注定失败的不如根本勾不到
                    h += '<tr><td>' + (t.undone == 1 ? ''
                            : '<input type="checkbox" class="owRVTrashChk" value="' + t.id + '" onchange="OwAdmin.roomTrashSyncBatch()">') + '</td>'
                       + '<td>' + new Date(t.created_at * 1000).toLocaleString() + '</td>'
                       + '<td>' + esc(t.room_name) + '（' + esc(fmtUid(t.room_id)) + '）</td>'
                       + '<td>' + esc(ACT[t.action] || t.action) + '</td>'
                       + '<td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + before + '</td>'
                       + '<td>' + (t.undone == 1 ? '<span style="color:var(--ow-text-sub)">已撤销</span>' : '-') + '</td>'
                       + '<td>' + (t.undone == 1 ? '' : '<a href="javascript:;" onclick="OwAdmin.roomTrashUndo(' + t.id + ')">撤销</a>') + '</td></tr>';
                }
                el.innerHTML = h;
                self._roomTrashRows = d.list.length;
                self.uiPager('owTrashPager', d.page, d.total, d.size, function (pg) { self.roomTrashLoad(pg); });
                self.roomTrashSyncBatch();
            });
        },

        roomTrashResetFilter: function () {
            var k = $('owRVTrashKw'), a = $('owRVTrashAct'), u = $('owRVTrashUndone');
            if (k) k.value = '';
            if (a) a.value = '';
            if (u) u.value = '';
            this.roomTrashLoad(1);
        },

        roomTrashToggleAll: function (cb) {
            var boxes = document.getElementsByClassName('owRVTrashChk');
            for (var i = 0; i < boxes.length; i++) boxes[i].checked = cb.checked;
            this.roomTrashSyncBatch();
        },

        roomTrashSyncBatch: function () {
            this.batchSync('owRVTrashChk', 'owTrashBatch', '共 ' + ((this._roomTrashRows || 0)) + ' 条');
        },

        /**
         * 批量撤销（v1.4.5）：ids 由 uiBatchBar 收集，服务端按「删除优先、各自 id 升序」处理，
         * 所以这里不用管勾选顺序 —— 同一群的「删除 + 改名称」一起勾上也能全部还原。
         */
        roomTrashBatch: function (act, ids) {
            if (act !== 'undo' || !ids || !ids.length) { toast('请先勾选要撤销的记录'); return; }
            var self = this;
            this.confirm('确定撤销选中的 ' + ids.length + ' 条处置？被删除的群聊会先恢复，再还原则之后的名称 / 头像 / 状态。', function () {
                OwApi.secure('admin_room_trash_batch', { act: 'undo', ids: ids.join(',') }, function (r) {
                    toast(r.msg);
                    if (r.ok) {
                        self.roomTrashLoad(self._roomTrashPage || 1);
                        self.roomLoad(self._roomPage || 1);
                    }
                });
            });
        },

        roomReview: function (id, act) {
            var tips = {
                reset_name: '确定该群聊名称不合法？将重置为「未命名群聊」。',
                reset_avatar: '确定该群聊头像不合法？将恢复默认头像。',
                toggle_status: ''
            };
            var run = function () {
                OwApi.post('admin_room_review', { id: id, act: act }, function (r) {
                    toast(r.msg);
                    if (r.ok) { OwAdmin.roomTrashLoad(OwAdmin._roomTrashPage || 1); OwAdmin.roomLoad(OwAdmin._roomPage || 1); }
                });
            };
            if (tips[act]) OwAdmin.confirm(tips[act], run);
            else run();
        },
        roomDel: function (id) {
            OwAdmin.confirm('确定删除该群聊？删除后可在「审核回收站」撤销恢复。', function () {
                OwApi.secure('admin_room_del', { id: id }, function (r) { toast(r.msg); OwAdmin.page('rooms'); });
            });
        },

        /* ---------- 维护任务（v1.1.13 引入时称「计划任务」，v1.2.60 更名） ---------- */

        /* 状态徽标：ok 成功 / error 失败 / skip 跳过（插件未启用）。
           ⚠️ 只用 CSS 里真实存在的 ow-tag-*：green / owner / vip / member / guest / title。
           没有 ow-tag-red、没有 ow-tag-gray —— 别臆造，会静默退化成无背景的裸文字。
           失败借用 owner（橙红，视觉上最接近警示），跳过用 member（灰）。 */
        _cronStatusTag: function (s) {
            var map = { ok: 'green', error: 'owner', skip: 'member' };
            var cn = { ok: '成功', error: '失败', skip: '跳过' };
            return '<span class="ow-tag ow-tag-' + (map[s] || 'guest') + '">' + esc(cn[s] || s || '—') + '</span>';
        },

        /* 把相对时间说人话：刚刚 / N 分钟前 / N 小时前 / 具体日期 */
        _cronAgo: function (ts) {
            ts = parseInt(ts, 10) || 0;
            if (!ts) return '从未执行';
            var d = Math.floor((Date.now() / 1000 - ts) / 60);
            if (d < 1) return '刚刚';
            if (d < 60) return d + ' 分钟前';
            if (d < 1440) return Math.floor(d / 60) + ' 小时前';
            if (d < 10080) return Math.floor(d / 1440) + ' 天前';
            return new Date(ts * 1000).toISOString().slice(0, 10);
        },

        cronLoad: function (main) {
            var page = OwAdmin._cronPage || 1;
            main = main || $('owAdminMain');
            OwApi.post('admin_cron_list', { page: page, psize: 30 }, function (r) {
                if (!r.ok) { main.innerHTML = '<div class="ow-card">' + esc(r.msg) + '</div>'; return; }

                var lastRun = r.last_run ? OwAdmin._cronAgo(r.last_run) : '从未执行';
                var h = '<h2>维护任务</h2>'
                  + '<p class="ow-admin-desc">插件通过 <code>Plugin::cron()</code> 声明式注册的维护任务。'
                  + '由长轮询每分钟驱动一次（多进程下有排他锁，不会重复执行），也可挂系统计划任务访问触发地址。'
                  + '「立即执行」会忽略到期时间强制跑一遍，便于验证任务是否正常。</p>'
                  + '<div class="ow-card" style="margin-bottom:12px">'
                  + '<div class="ow-form-row" style="align-items:center;gap:10px">'
                  + '<button type="button" class="ow-btn ow-btn-primary" onclick="OwAdmin.cronRun()">立即执行全部</button>'
                  + '<button type="button" class="ow-btn ow-btn-ghost" onclick="OwAdmin.cronToken()">重置触发令牌</button>'
                  + '<button type="button" class="ow-btn ow-btn-ghost" onclick="OwAdmin.cronClearLogs()">清理过期日志</button>'
                  + '<span style="margin-left:auto;color:var(--ow-text-sub);font-size:12px">最近一次执行：' + esc(lastRun) + '</span>'
                  + '</div>'
                  + '<div class="ow-form-row" style="margin-top:10px">'
                  + '<div style="flex:1;min-width:0">'
                  + '<label style="display:block;font-size:12px;color:var(--ow-text-sub);margin-bottom:4px">外部触发地址（系统计划任务用，间隔建议 1 分钟）</label>'
                  + '<input class="ow-input" readonly value="' + esc(OwAdmin._cronUrl(r.token)) + '" onclick="this.select()">'
                  + '</div></div></div>';

                /* ---- 任务表 ---- */
                h += '<div class="ow-card"><table class="ow-table"><tr>'
                  + '<th>任务</th><th>说明</th><th>间隔</th><th>下次执行</th><th>最近执行</th><th>状态</th><th>操作</th></tr>';
                if (!r.tasks.length) {
                    h += '<tr><td colspan="7" style="color:var(--ow-text-sub)">暂无维护任务。插件在 main.php 顶层调用 Plugin::cron() 注册后，刷新本页即会出现。</td></tr>';
                }
                for (var i = 0; i < r.tasks.length; i++) {
                    var t = r.tasks[i];
                    h += '<tr>'
                       + '<td><b>' + esc(t.plugin ? t.plugin + '::' + t.name : t.name) + '</b>'
                       +   (t.due ? ' <span class="ow-tag ow-tag-owner">待执行</span>' : '')
                       +   '<div style="color:var(--ow-text-sub);font-size:12px">已运行 ' + (parseInt(t.run_count, 10) || 0) + ' 次</div></td>'
                       + '<td>' + esc(t.description || '—') + '</td>'
                       + '<td>' + esc(t.interval_text) + '</td>'
                       + '<td>' + esc(t.next_run_text) + '</td>'
                       + '<td>' + esc(t.last_run_text) + (t.last_status ? ' ' + OwAdmin._cronStatusTag(t.last_status) : '') + '</td>'
                       + '<td>' + (t.enabled ? '<span class="ow-tag ow-tag-green">启用</span>' : '<span class="ow-tag ow-tag-guest">停用</span>')
                       +   (t.plugin_active ? '' : '<div style="color:#F4995D;font-size:12px">插件未启用</div>') + '</td>'
                       + '<td>';
                    if (t.plugin_active) {
                        h += '<button class="ow-btn ow-btn-mini ' + (t.enabled ? 'ow-btn-ghost' : 'ow-btn-primary') + '"'
                           + ' onclick="OwAdmin.cronToggle(' + (parseInt(t.id, 10) || 0) + ')">'
                           + (t.enabled ? '停用' : '启用') + '</button>';
                    }
                    h += '</td></tr>';
                }
                h += '</table></div>';

                /* ---- 执行日志 ---- */
                h += '<h2 style="margin-top:20px">执行日志</h2><div class="ow-card"><table class="ow-table"><tr>'
                  + '<th>任务</th><th>结果</th><th>耗时</th><th>信息</th><th>时间</th></tr>';
                if (!r.logs.length) {
                    h += '<tr><td colspan="5" style="color:var(--ow-text-sub)">暂无执行记录</td></tr>';
                }
                for (var j = 0; j < r.logs.length; j++) {
                    var g = r.logs[j];
                    h += '<tr><td>' + esc(g.name) + '</td>'
                       + '<td>' + OwAdmin._cronStatusTag(g.status) + '</td>'
                       + '<td>' + (parseInt(g.duration, 10) || 0) + ' ms</td>'
                       + '<td>' + esc(g.message || '—') + '</td>'
                       + '<td>' + esc(new Date(parseInt(g.created_at, 10) * 1000).toISOString().slice(0, 19).replace('T', ' ')) + '</td></tr>';
                }
                h += '</table>';
                // v1.2.51：执行日志换通用分页轮子 uiPager（原来只有「上一页/下一页」，
                // 与群聊审核等页的数字页码+跳页不一致，也没法直接跳页）
                h += '<div id="owCronLogPager" style="margin-top:10px"></div></div>';

                main.innerHTML = h;
                OwAdmin.uiPager('owCronLogPager', page, r.log_total, 30, function (pg) { OwAdmin.cronPage(pg); });
            });
        },

        _cronUrl: function (token) {
            var base = location.origin + location.pathname + '?action=cron';
            return token ? base + '&token=' + encodeURIComponent(token) : base;
        },
        cronPage: function (p) {
            OwAdmin._cronPage = Math.max(1, p);
            OwAdmin.cronLoad();
        },
        /* 启停：敏感操作，走一次性票据（OwApi.secure），不能用普通 post */
        cronToggle: function (id) {
            OwApi.secure('admin_cron_toggle', { id: id }, function (r) {
                toast(r.msg);
                if (r.ok) OwAdmin.cronLoad();
            });
        },
        cronRun: function () {
            OwAdmin.confirm('立即执行全部已启用的维护任务？\n忽略到期时间，任务可能包含清理类操作。', function () {
                OwApi.secure('admin_cron_run', {}, function (r) {
                    toast(r.msg);
                    if (r.ok) {
                        // 失败详情单独提示，否则「执行 3 个，失败 1 个」看不出是哪个挂了
                        var bad = [];
                        for (var i = 0; i < (r.results || []).length; i++) {
                            if (r.results[i].status !== 'ok') bad.push(r.results[i].name + '：' + (r.results[i].message || r.results[i].status));
                        }
                        if (bad.length) toast(bad.join('；'), 'err');
                        OwAdmin.cronLoad();
                    }
                });
            });
        },
        cronToken: function () {
            OwAdmin.confirm('重置外部触发令牌？\n旧地址立即失效，已配置的系统计划任务需要更新为新地址。', function () {
                OwApi.secure('admin_cron_token', {}, function (r) {
                    toast(r.msg);
                    if (r.ok) OwAdmin.cronLoad();
                });
            });
        },
        cronClearLogs: function () {
            OwAdmin.confirm('清理超过保留期的执行日志？保留天数按「系统设置 → 系统与维护 → 执行日志保留期」'
                + '（默认 90 天）。该操作不可恢复。', function () {
                // 不传 days：服务端按系统设置的保留期清（旧版这里发死数 30，与设置项两套口径）
                OwApi.secure('admin_cron_logs_clear', {}, function (r) {
                    toast(r.msg);
                    if (r.ok) OwAdmin.cronLoad();
                });
            });
        },

        /* ---------- 其他动作（banAdd/banDel 已随 v1.0.52 剥离为插件 ban-manager；
           wordAdd/wordToggle/wordDel 已随 v1.0.104 剥离为插件 sensitive-words） ---------- */
        /* 系统公告管理（v1.0.102）已随公告剥离为 announcements 插件 */
        /**
         * 插件列表搜索（v1.3.67）：纯前端过滤，只切卡片 display，不重新请求。
         * 空格分词按「每个词都要命中」处理 —— 后台插件名是中文、ID 是英文，
         * 「email 验证」这类组合查询很常见，整体当子串搜反而一个都搜不到。
         * 命中串在渲染时就拼好存进 data-owplug-k（见 pages.plugins）。
         */
        pluginFilter: function () {
            var inp = $('owPluginSearch');
            if (!inp) return;
            var q = String(inp.value || '').trim();
            OwAdmin._plugQ = q;
            var terms = q ? q.toLowerCase().split(/\s+/) : [];
            var cards = document.querySelectorAll('.ow-plugin-list .ow-plugin-card');
            var hit = 0;
            for (var i = 0; i < cards.length; i++) {
                var hay = cards[i].getAttribute('data-owplug-k') || '';
                var ok = true;
                for (var j = 0; j < terms.length; j++) {
                    if (hay.indexOf(terms[j]) < 0) { ok = false; break; }
                }
                cards[i].style.display = ok ? '' : 'none';
                if (ok) hit++;
            }
            var none = $('owPluginNone');
            if (none) none.style.display = (cards.length && hit === 0) ? '' : 'none';
            var stat = $('owPluginHit');
            // 只在过滤时报数，且不写中文：带数字的句子语言包永远匹配不上，
            // 「3 / 27」这种纯数字反而到哪都不用翻（不搜时列表本来就是全量，报数没意义）
            if (stat) stat.textContent = terms.length ? (hit + ' / ' + cards.length) : '';
        },
        /**
         * 插件列表 → 点标题直达插件后台页（v1.2.57）。
         * 走菜单同一套分发（'plugin:<slug>'），不另写一套渲染逻辑；
         * 只有服务端确认注册过后台页的插件才会出现可点标题（见 plugins 页）。
         */
        pluginOpen: function (id) {
            if (!id) return;
            this.page('plugin:' + id);
        },
        pluginToggle: function (name, en) {
            OwApi.post('admin_plugin_toggle', { name: name, enabled: en }, function (r) {
                toast(r.msg);
                // 启停改变侧栏子菜单与可用页面，整页刷新保证状态一致
                setTimeout(function () { location.reload(); }, 500);
            });
        },
        /* 卸载：删除插件目录，二次确认后执行 */
        pluginUninstall: function (name) {
            OwAdmin.confirm('确定卸载插件「' + name + '」吗？\n将停用并删除 plugins/' + name + ' 目录，不可恢复！', function () {
                OwApi.secure('admin_plugin_uninstall', { name: name }, function (r) {
                    toast(r.msg);
                    setTimeout(function () { location.reload(); }, 500);
                });
            });
        },
        pluginInstall: function () {
            var f = $('owPluginZip');
            if (!f.files || !f.files[0]) { toast('请选择 zip 文件'); return; }
            OwApi.upload('admin_plugin_install', f.files[0], {}, function (r) { toast(r.msg); if (r.ok) OwAdmin.page('plugins'); });
        },
        /**
         * 仪表盘「快捷访问」：跳到系统设置、展开所属分组、滚过去并闪一下（v1.3.158）。
         *
         * ⚠️ 设置页是**异步**渲染的（先 admin_settings_get、回调里才 main.innerHTML），
         *    所以不能调完 page('settings') 就去找控件 —— 那一刻 DOM 还没建出来。
         *    固定 setTimeout 要么白等、要么在接口慢时不够，这里用短轮询（最多 1.5 秒）。
         */
        goSetting: function (key) {
            var menu = $('owAdminMenu');
            if (menu) {
                var all = menu.getElementsByTagName('li'), li = null, j;
                for (j = 0; j < all.length; j++) {
                    if (all[j].getAttribute('data-apage') === 'settings') li = all[j];
                    all[j].className = trimCls(all[j].className.replace(/\bactive\b/g, ''));
                }
                if (li) li.className += ' active';
            }
            try { sessionStorage.setItem('owAdminPage', 'settings'); } catch (e) {}
            this.page('settings');
            var tries = 0;
            (function wait() {
                var el = $('owS_' + key);
                if (!el) { if (++tries < 30) setTimeout(wait, 50); return; }
                /* 开关类设置的 id 挂在**隐藏的** checkbox 上（.ow-switch-input 是
                   position:absolute + 0×0 + opacity:0，视觉控件是旁边那根轨道）——
                   滚动与高亮必须落在整行 .ow-switch-row 上，否则滚的是个看不见的元素、什么都不会发生。 */
                var target = (' ' + el.className + ' ').indexOf(' ow-switch-input ') >= 0 ? el.parentNode : el;
                if (!target || target.nodeType !== 1) target = el;
                // 往上找到所属的折叠分组（.ow-fold），展开并滚到**分组标题** ——
                // 控件本身就在分组第一行时，滚标题比滚控件更符合「我现在在哪」的预期。
                var box = target;
                while (box && box.nodeType === 1 && (' ' + box.className + ' ').indexOf(' ow-fold ') < 0) box = box.parentNode;
                if (box && box.nodeType === 1) {
                    if (box.className.indexOf('is-open') < 0) box.className += ' is-open';
                    box.scrollIntoView(true);
                } else {
                    target.scrollIntoView(true);
                }
                target.className += ' ow-set-hit';
                setTimeout(function () { target.className = trimCls(target.className.replace(/\bow-set-hit\b/g, '')); }, 1700);
            })();
        },

        /**
         * 折叠分组开关（v1.2.53，配合 .ow-fold 样式）。
         * 只切 is-open 类，内容始终留在 DOM 里 —— 收起状态下表单值照样被 settingsSave 读到。
         * @param {Element} hd 被点的 .ow-fold-hd（标题条）
         */
        foldToggle: function (hd) {
            var box = hd && hd.parentNode;
            if (!box || box.className.indexOf('ow-fold') < 0) return;
            box.className = box.className.indexOf('is-open') >= 0
                ? box.className.replace(/\bis-open\b/g, '')
                : box.className + ' is-open';
        },
        settingsSave: function () {
            /** 开关取值：checked → '1'/'0'；元素不存在 → ''。
             *  ⚠️ 空串**不会**被后端跳过（admin_settings_save 只判 isset($_POST[k])，
             *  DB::setSetting 也照写），所以这只在「该开关一定渲染在本页」的前提下成立。
             *  字段被搬去别的页面时，正确做法是整行不提交，见下面 msg_rate_* 那条。 */
            function swv(id) {
                var el = $(id);
                return el ? (el.checked ? '1' : '0') : '';
            }
            OwApi.post('admin_settings_save', {
                site_name: $('owS_site_name').value,
                site_url: $('owS_site_url') ? $('owS_site_url').value : '',
                // v1.5.4 积分名称：空串 = 恢复默认（后端把空串存成空，读取时兜底「积分」）
                points_name: $('owS_points_name') ? $('owS_points_name').value : '',
                // v1.2.52：布尔项已从下拉改成开关（原生 checkbox）→ 读 checked 转成 '1'/'0'。
                allow_register: swv('owS_allow_register'),
                reg_email_verify: swv('owS_reg_email_verify'),
                guest_browse: swv('owS_guest_browse'),
                guest_chat: swv('owS_guest_chat'),
                guest_msg_interval: $('owS_guest_msg_interval') ? $('owS_guest_msg_interval').value : '',
                // v1.2.2 消息服务器保留期（天），0 = 永久保留
                msg_retain_days: $('owS_msg_retain_days') ? $('owS_msg_retain_days').value : '',
                // v1.3.167 执行日志保留期（天），0 = 永久保留
                cron_log_retain_days: $('owS_cron_log_retain_days') ? $('owS_cron_log_retain_days').value : '',
                // v1.3.104 起「发言频率窗口 / 窗口内最大条数」的编辑入口已收到
                // 「插件管理 → 垃圾信息控制 → 刷屏」，本页不再渲染这两个输入框。
                // 这里**整行不再提交**：之前留着 `$('owS_msg_rate_window').value` 的裸读，
                // 元素不存在 → settingsSave 在 OwApi.post 之前就 TypeError，
                // 表现就是「保存设置」点了没反应（站方 2026-10-14 报的正是这个）。
                // 也不能改成提交空串：admin_settings_save 只认 isset($_POST[k])，
                // 空串照写不误（DB::setSetting 不跳空），等于把插件里刚调好的限流清成空值。
                // mail_rate_limit 已移出本页（v1.3.55，见邮箱验证插件），不再提交：
                // 提交空串会跳过、提交旧值会把插件里改过的设置覆盖回去。
                room_pass_ttl: $('owS_room_pass_ttl') ? $('owS_room_pass_ttl').value : '',
                min_register_age: $('owS_min_register_age') ? $('owS_min_register_age').value : '',
                room_create_allow: swv('owS_room_create_allow'),
                room_private_create_allow: swv('owS_room_private_create_allow'),
                login_fail_captcha: $('owS_login_fail_captcha') ? $('owS_login_fail_captcha').value : '',
                login_fail_lock: $('owS_login_fail_lock') ? $('owS_login_fail_lock').value : '',
                login_lock_minutes: $('owS_login_lock_minutes') ? $('owS_login_lock_minutes').value : '',
                sound_default: swv('owS_sound_default'),
                // v1.2.51 调试模式（v1.2.52 起也是开关）
                debug_mode: swv('owS_debug_mode'),
                // P1（WS 实时层）：实时通道开关 + 守护进程端口
                ws_enable: swv('owS_ws_enable'),
                ws_port: $('owS_ws_port') ? $('owS_ws_port').value : '',
                // P3（发送迁移）：WS 发送开关/白名单/数值
                ws_send_enable: swv('owS_ws_send_enable'),
                ws_send_rooms: $('owS_ws_send_rooms') ? $('owS_ws_send_rooms').value : '',
                ws_send_timeout: $('owS_ws_send_timeout') ? $('owS_ws_send_timeout').value : '',
                ws_send_max_inflight: $('owS_ws_send_max_inflight') ? $('owS_ws_send_max_inflight').value : '',
                // v1.3.44 站点默认语言（语言组未渲染时给空串，后端跳过该键）
                ow_lang_default: $('owS_ow_lang_default') ? $('owS_ow_lang_default').value : ''
            }, function (r) {
                toast(r.msg);
                // v1.3.45：本浏览器只要在登录页切换器里选过语言，ow_lang cookie 就盖住后台默认值，
                // 表现是「后台改了没效果」。管理员刚保存的就是语言项时，清掉这个个人偏好并刷新，
                // 让改动立刻可见（其他访客浏览器各自的 cookie 不受影响）。
                var sel = $('owS_ow_lang_default');
                if (r && r.ok && sel && sel.value !== (document.body.getAttribute('data-lang') || 'zh')) {
                    document.cookie = 'ow_lang=; path=/; max-age=0';
                    setTimeout(function () { location.reload(); }, 800);
                }
            });
        }
    };

    w.OwAuth = OwAuth;
    w.OwChat = OwChat;
    w.OwAdmin = OwAdmin;
    w.OwApi = OwApi;   // 暴露给插件脚本（如用户管理插件 OwUM）使用
    // v1.5.18：按钮防重入通用轮子（OwApi 已自动接入；插件里不经过 OwApi 的异步按钮用 run/form）
    w.OwBusy = OwBusy;
    // v1.5.19：图片懒加载通用轮子（自动补 loading/decoding；data-src 走 IntersectionObserver）
    w.OwLazy = OwLazy;
    // 通用助手同样暴露：插件脚本与主程序共用渲染与提示
    w.esc = esc; w.toast = toast; w.fmtUid = fmtUid; w.opts = opts; w.ROLE_CN = ROLE_CN;
    // owTr 也暴露：插件把文案写进**已存在**的节点时，i18n 的 MutationObserver 只看新增元素，
    // 这种文本不会被自动翻译 —— 必须自己过一道 owTr（垃圾信息控制的 AI 判定结果就是这种情况）。
    w.owTr = owTr;
    // 开关（State 按钮）通用轮子：前后台与插件共用同一套 HTML 与绑定逻辑
    w.switchHtml = switchHtml; w.bindSwitches = bindSwitches;
    // 分组标签通用轮子（v1.3.113）：前台个人设置与插件后台页同一套 HTML / 显隐逻辑
    w.owTabs = owTabs;
    w.OwTip = OwTip;   // 表单说明气泡（v1.2.45），核心已自动启用，插件直接用 OwTip.scan()

    /* ==========================================================================
       OwGate：请求单飞闸门（v1.2.38，通用轮子，前后台与插件共用）
       --------------------------------------------------------------------------
       解决什么：多个**互不相干的轻量状态查询**同时触发时（比如心跳、聊天更新、未读数），
       请求会并发打出去、响应乱序回，旧值覆盖新值；请求一多还会堆积成雪崩。

       本项目原有的 5 种防堆积手法**都不等于串行化**：
         · 世代号 pollGen/dmGen → 作废在途回调（丢弃）
         · loadingHistory      → 在途时丢弃新请求
         · _roomPollRunning    → 防循环起两份
         · failCount           → 失败指数退避
         · _srSeq              → 只认最后一次响应
       它们都不提供「第二个请求排队等第一个完成」这个语义，所以这里单列一个轮子。

       两种模式：
         queue（默认）—— 同 key 严格串行，后来者**排队**，前一个完成才发下一个。
                        适合「必须按顺序、结果都要」的状态查询。
         drop          —— 同 key 在途时**直接丢弃**后来者，不发请求。
                        适合「只要最新值」的轮询式查询（旧的已经过时了）。

       用法：
         OwGate.run('heartbeat_status', function (done) {
             OwApi.post('heartbeat_status', {}, function (r) { done(null, r); });
         }, function (err, r) { ... });

         // 只要最新值（丢弃模式）+ 最小间隔 3s
         OwGate.run('notification_count', task, cb, { drop: true, minGap: 3000 });
       ========================================================================== */
    var OwGate = {
        _q: {},        // key -> { busy, chain:[], lastAt }
        _minGap: 0,    // 默认最小间隔（ms），0 = 不限；可被 opts.minGap 覆盖

        /**
         * 过闸：同 key 串行。
         * @param key   闸门键（一般用接口名，如 'heartbeat_status'）
         * @param task  function (done) {} —— 异步任务，**必须调 done(err, res)**
         * @param cb    function (err, res) {} —— 轮到该请求时的回调
         * @param opts  { drop: boolean 在途时丢弃不排队, minGap: number 最小间隔ms }
         * @return boolean false = 被丢弃（仅 drop 模式可能）
         */
        run: function (key, task, cb, opts) {
            opts = opts || {};
            var g = this._q[key];
            if (!g) g = this._q[key] = { busy: false, chain: [], lastAt: 0 };
            if (g.busy && opts.drop) return false;         // 丢弃模式：旧的已过时
            g.chain.push({ task: task, cb: cb, opts: opts });
            if (!g.busy) this._drain(key, g);
            return true;
        },

        /** 取队首执行；无论成功失败都继续排下一个（失败不能卡死整条队列） */
        _drain: function (key, g) {
            var self = this;
            if (g.busy || !g.chain.length) return;
            var it = g.chain.shift();
            g.busy = true;
            var settled = false;
            var done = function (err, res) {
                if (settled) return;                       // 任务重复调 done 只算一次
                settled = true;
                g.busy = false;
                g.lastAt = new Date().getTime();
                if (it.cb) { try { it.cb(err, res); } catch (e) { /* 回调异常不影响队列 */ } }
                setTimeout(function () { self._drain(key, g); }, 0);
            };
            var gap = (it.opts.minGap != null ? it.opts.minGap : this._minGap)
                - (new Date().getTime() - g.lastAt);
            var fire = function () {
                try { it.task(done); } catch (e) { done(e); }   // 任务同步抛错也要放行队列
            };
            if (gap > 0) setTimeout(fire, gap); else fire();
        },

        /** 丢弃某 key 的排队（切页/登出时用；不打断已在途的那个） */
        clear: function (key) {
            if (key === undefined || key === null) { this._q = {}; return; }
            if (this._q[key]) this._q[key].chain.length = 0;
        },

        /** 该 key 是否正在执行（调试与测试用） */
        busy: function (key) { var g = this._q[key]; return !!(g && g.busy); },
        /** 该 key 排队的数量（调试与测试用） */
        pending: function (key) { var g = this._q[key]; return g ? g.chain.length : 0; }
    };
    w.OwGate = OwGate;
})(window, document);

/* ============================================================================
 * 内部链接预加载（v1.5.26，instant.page 思路，零依赖）
 *
 * 为什么做：本站是服务端渲染 + 少量整页跳转（?page=chat / ?page=level / 登录注册等），
 * 跳转时浏览器要重新握手并重新请求整页。悬停 65ms 后预取目标页，等于把这段等待
 * 提前到「手指还没点下去」的空档里（instant.page 的默认阈值）。
 *
 * 规则（缺一不可，照抄 instant.page 的安全边界）：
 *   · 只预取**同源 http(s)** 且 href 不是 #/javascript: 的链接；
 *   · `?action=` 一律跳过 —— 那是接口/下载/工单端点，预取会白跑一次写操作；
 *   · target=_blank、带 download、带 data-no-instant 的不碰；<html data-no-instant> 全局关闭；
 *   · Save-Data / 2g 网络不做预取（省流量优先）；
 *   · 同一 URL 只预取一次；用 <link rel=prefetch as=document>，交给浏览器调度。
 *
 * ⚠️ 动态页响应带 Cache-Control: no-store（页面内嵌会话密钥，不能进缓存），
 *    所以预取**不一定能被导航复用**，收益主要是提前建连 / 提前让服务端渲染一次；
 *    哪天页面改成可缓存（ETag + private），这套预取会立刻变成「点开即到」。
 * ========================================================================== */
(function (w, d) {
    'use strict';
    if (!w || !d || !d.documentElement) return;
    if (d.documentElement.hasAttribute('data-no-instant')) return;

    var conn = w.navigator && (w.navigator.connection || w.navigator.mozConnection);
    if (conn && (conn.saveData || /(^|-)2g$/i.test(String(conn.effectiveType || '')))) return;

    var HOVER_MS = 65;
    var seen = Object.create ? Object.create(null) : {};
    var timer = 0;

    /** 合格则返回去掉 hash 的绝对 URL，否则返回 '' */
    function target(a) {
        if (!a || a.nodeType !== 1) return '';
        if (a.hasAttribute('data-no-instant') || a.hasAttribute('download')) return '';
        if ((a.getAttribute('target') || '') === '_blank') return '';
        var href = a.getAttribute('href') || '';
        if (!href || href.charAt(0) === '#' || /^javascript:/i.test(href)) return '';
        var abs = a.href || '';
        if (!/^https?:/i.test(abs)) return '';
        if (abs.indexOf(location.protocol + '//' + location.host) !== 0) return '';
        var bare = abs.split('#')[0];
        if (bare === (location.href || '').split('#')[0]) return '';
        if (/[?&]action=/.test(bare)) return '';
        return bare;
    }

    function prefetch(a) {
        var url = target(a);
        if (!url || seen[url]) return;
        seen[url] = 1;
        var l = d.createElement('link');
        l.rel = 'prefetch';
        l.as = 'document';
        l.href = url;
        (d.head || d.documentElement).appendChild(l);
    }

    function closestLink(e) {
        var t = e.target;
        if (!t || t.nodeType !== 1) return null;
        return t.closest ? t.closest('a[href]') : null;
    }

    d.addEventListener('mouseover', function (e) {
        var a = closestLink(e);
        if (!a) return;
        clearTimeout(timer);
        timer = setTimeout(function () { prefetch(a); }, HOVER_MS);
    }, { passive: true });

    d.addEventListener('mouseout', function () { clearTimeout(timer); }, { passive: true });

    // 按下/触摸的瞬间不再等 65ms —— 手指已经落在链接上，预取与点击并行赛跑
    d.addEventListener('mousedown', function (e) {
        var a = closestLink(e);
        if (a) prefetch(a);
    }, { passive: true });

    d.addEventListener('touchstart', function (e) {
        var a = closestLink(e);
        if (a) prefetch(a);
    }, { passive: true });
})(window, document);

/* ============================================================================
 * Service Worker 注册（v1.5.28）
 *
 * 做什么：把「合并包 / 上传图片 / 带版本号的核心静态资源」交给 SW 做请求级缓存，
 * 重复打开页面时不再走网络（详见根目录 sw.js 顶部的策略表）。
 *
 * 边界：
 *   · 只在安全上下文注册 —— https，或 localhost / 127.0.0.1（浏览器视为安全上下文）；
 *     纯 http 的内网 IP 部署注册会直接失败，这里提前挡掉，免得控制台刷一条无意义的错。
 *   · 失败一律静默：SW 是纯加速，注册不上页面照常跑。
 *   · updateViaCache:'none' 让浏览器每次导航都去服务端取最新的 SW 脚本
 *     （脚本由 ?action=sw 输出，内容里带版本号；版本一变 activate 就清掉全部旧缓存）。
 * ========================================================================== */
(function (w, n) {
    'use strict';
    if (!w || !n || !('serviceWorker' in n)) return;
    var host = location.hostname;
    if (location.protocol !== 'https:' && !/^(localhost|127\.0\.0\.1|\[::1\])$/.test(host)) return;
    w.addEventListener('load', function () {
        n.serviceWorker.register('?action=sw', { scope: './', updateViaCache: 'none' })
            .catch(function () { /* 注册失败不影响页面 */ });
    });
})(window, navigator);
