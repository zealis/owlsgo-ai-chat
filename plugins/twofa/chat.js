/* ==========================================================================
 * twofa 插件前端（纯原生 ES5，兼容旧浏览器，无外部依赖）
 *
 * 功能：
 *   1. 登录页：将密码登录表单从 AJAX 改为普通 POST 提交到 plugin_twofa_login，
 *      避免全局 Ajax 拦截器误报"操作失败"。
 *   2. 登录页：?twofa=1 时显示二次验证表单（TOTP 码或恢复码），普通提交。
 *   3. 聊天页个人设置：注入两步验证管理区（启用 / 关闭 / 重置恢复码）。
 *   4. 内嵌极简 QR 码生成器（字节模式，EC=M），用于 TOTP 密钥扫码。
 * ========================================================================== */
(function (w) {
    'use strict';

    /* ---------- 极简 QR 码生成器（字节模式，自动版本，EC=M） ---------- */
    var owQR = (function () {
        var EXP = new Array(512), LOG = new Array(256);
        (function () {
            var x = 1;
            for (var i = 0; i < 255; i++) { EXP[i] = x; LOG[x] = i; x <<= 1; if (x & 0x100) x ^= 0x11d; }
            for (var j = 255; j < 512; j++) EXP[j] = EXP[j - 255];
        })();
        function mul(a, b) { return (a === 0 || b === 0) ? 0 : EXP[LOG[a] + LOG[b]]; }
        function polyMul(a, b) {
            var r = new Array(a.length + b.length - 1);
            for (var i = 0; i < r.length; i++) r[i] = 0;
            for (var i = 0; i < a.length; i++)
                for (var j = 0; j < b.length; j++) r[i + j] ^= mul(a[i], b[j]);
            return r;
        }
        function rsGen(n) {
            var g = [1];
            for (var i = 0; i < n; i++) g = polyMul(g, [1, EXP[i]]);
            return g;
        }
        function rsEncode(data, ecLen) {
            var gen = rsGen(ecLen);
            var zeros = [];
            for (var k = 0; k < ecLen; k++) zeros.push(0);
            var res = data.concat(zeros);
            for (var i = 0; i < data.length; i++) {
                var c = res[i];
                if (c) for (var j = 0; j < gen.length; j++) res[i + j] ^= mul(gen[j], c);
            }
            return res.slice(data.length);
        }
        // 版本 -> [每块数据码数, 每块EC码数, 块数] (EC=M, 版本1-7统一块大小)
        var CAP = {
            1: [16, 10, 1], 2: [28, 16, 1], 3: [44, 26, 1], 4: [32, 18, 2],
            5: [43, 24, 2], 6: [27, 16, 4], 7: [31, 18, 4]
        };
        function bestVersion(len) {
            // 仅支持 V1-6：V7+ 需要额外的版本信息块（本生成器未实现）。
            // otpauth 串已由服务端精简（固定 issuer + 数字账号），~86 字节在 V5/V6 内。
            for (var v = 1; v <= 6; v++) {
                var c = CAP[v], total = c[2] * c[0];
                var bits = 4 + 8 + len * 8;
                if (bits <= total * 8) return v;
            }
            return 0;   // 超容量
        }
        function bitStream(data, version) {
            var bits = [];
            function put(v, n) { for (var i = n - 1; i >= 0; i--) bits.push((v >> i) & 1); }
            put(4, 4);                           // 模式: 字节
            put(data.length, version < 10 ? 8 : 16);
            for (var i = 0; i < data.length; i++) put(data.charCodeAt(i) & 0xff, 8);
            // 终止符
            var cap = CAP[version], totalBits = cap[2] * cap[0] * 8;
            var term = Math.min(4, totalBits - bits.length);
            for (var i = 0; i < term; i++) bits.push(0);
            // 补齐到字节
            while (bits.length % 8 !== 0) bits.push(0);
            // 补齐码字
            var pad = [0xec, 0x11];
            var pi = 0;
            while (bits.length < totalBits) {
                for (var i = 0; i < 8; i++) bits.push((pad[pi] >> (7 - i)) & 1);
                pi = (pi + 1) % 2;
            }
            var bytes = [];
            for (var i = 0; i < bits.length; i += 8) {
                var b = 0;
                for (var j = 0; j < 8; j++) b = (b << 1) | bits[i + j];
                bytes.push(b);
            }
            return bytes;
        }
        function makeMatrix(version) {
            var size = 17 + version * 4;
            var m = [];
            for (var i = 0; i < size; i++) {
                var row = [];
                for (var j = 0; j < size; j++) row.push(-1);
                m.push(row);
            }
            function finder(r, c) {
                for (var i = -1; i <= 7; i++) for (var j = -1; j <= 7; j++) {
                    if (r + i < 0 || r + i >= size || c + j < 0 || c + j >= size) continue;
                    var v = 0;
                    if (i >= 0 && i <= 6 && j >= 0 && j <= 6) {
                        if (i === 0 || i === 6 || j === 0 || j === 6 || (i >= 2 && i <= 4 && j >= 2 && j <= 4)) v = 1;
                    }
                    m[r + i][c + j] = v;
                }
            }
            finder(0, 0); finder(0, size - 7); finder(size - 7, 0);
            // 对齐图案（版本1无）—— 需在时序线之前绘制，且不与查找图案重叠
            var alignPos = [0, [], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34], [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50]];
            if (version > 1) {
                var ap = alignPos[version];
                for (var i = 0; i < ap.length; i++) for (var j = 0; j < ap.length; j++) {
                    var ar = ap[i], ac = ap[j];
                    // 跳过与三个查找图案（含分隔符，8x8）重叠的对齐图案
                    if (ar < 9 && ac < 9) continue;
                    if (ar < 9 && ac >= size - 8) continue;
                    if (ar >= size - 8 && ac < 9) continue;
                    for (var di = -2; di <= 2; di++) for (var dj = -2; dj <= 2; dj++) {
                        var v = (Math.abs(di) === 2 || Math.abs(dj) === 2 || (di === 0 && dj === 0)) ? 1 : 0;
                        m[ar + di][ac + dj] = v;
                    }
                }
            }
            // 时序线（在对齐图案之后绘制，但不覆盖对齐图案）：偶数坐标为暗模块
            for (var i = 8; i < size - 8; i++) {
                if (m[6][i] === -1) m[6][i] = (i % 2 === 0) ? 1 : 0;
                if (m[i][6] === -1) m[i][6] = (i % 2 === 0) ? 1 : 0;
            }
            // 保留格式信息区
            for (var i = 0; i < 9; i++) { if (m[8][i] === -1) m[8][i] = -2; if (m[i][8] === -1) m[i][8] = -2; }
            for (var i = 0; i < 8; i++) { if (m[8][size - 1 - i] === -1) m[8][size - 1 - i] = -2; if (m[size - 1 - i][8] === -1) m[size - 1 - i][8] = -2; }
            m[size - 8][8] = 1; // 暗模块
            return m;
        }
        function placeData(m, data, version) {
            var size = m.length;
            var idx = 0, bitIdx = 0, dir = -1, row = size - 1, col = size - 1;
            while (col > 0) {
                if (col === 6) col--;
                while (true) {
                    for (var c = 0; c < 2; c++) {
                        var cc = col - c;
                        if (m[row][cc] === -1) {
                            var v = 0;
                            if (idx < data.length) v = (data[idx] >> (7 - bitIdx)) & 1;
                            // 用 10/11 标记数据模块，避免与功能模块(0/1)混淆
                            m[row][cc] = v ? 11 : 10;
                            bitIdx++;
                            if (bitIdx === 8) { bitIdx = 0; idx++; }
                        }
                    }
                    row += dir;
                    if (row < 0 || row >= size) { row -= dir; dir = -dir; col -= 2; break; }
                }
            }
        }
        function applyMask(m, pattern) {
            var size = m.length;
            for (var r = 0; r < size; r++) for (var c = 0; c < size; c++) {
                // 只对数据模块(10/11)应用掩码，功能模块(0/1)和保留区(-2)跳过
                if (m[r][c] !== 10 && m[r][c] !== 11) continue;
                var invert = false;
                switch (pattern) {
                    case 0: invert = (r + c) % 2 === 0; break;
                    case 1: invert = r % 2 === 0; break;
                    case 2: invert = c % 3 === 0; break;
                    case 3: invert = (r + c) % 3 === 0; break;
                }
                if (invert) m[r][c] = (m[r][c] === 10) ? 11 : 10;
            }
        }
        function formatBits(mask) {
            // EC=M => 00, mask => 3 bits
            var data = (0 << 3) | mask;
            var g = 0x537;
            var rem = data << 10;
            for (var i = 14; i >= 10; i--) if ((rem >> i) & 1) rem ^= g << (i - 10);
            var fmt = ((data << 10) | rem) ^ 0x5412;
            return fmt;
        }
        function placeFormat(m, mask) {
            var size = m.length, fmt = formatBits(mask), i;
            // 第一份（环绕左上查找图案）：列 8 行 0-5 = bit0-5；行 7 列 8 = bit6；
            // (8,8) = bit7；行 8 列 7 = bit8；行 8 列 5-0 = bit9-14（列 6 为时序线跳过）
            for (i = 0; i <= 5; i++) m[i][8] = (fmt >> i) & 1;
            m[7][8] = (fmt >> 6) & 1;
            m[8][8] = (fmt >> 7) & 1;
            m[8][7] = (fmt >> 8) & 1;
            for (i = 9; i < 15; i++) m[8][14 - i] = (fmt >> i) & 1;
            // 第二份：行 8 右侧（从 size-1 向左）= bit0-7；列 8 底部（从 size-7 向下）= bit8-14
            for (i = 0; i < 8; i++) m[8][size - 1 - i] = (fmt >> i) & 1;
            for (i = 8; i < 15; i++) m[size - 15 + i][8] = (fmt >> i) & 1;
            // 暗模块
            m[size - 8][8] = 1;
        }
        function toSvg(m, cell) {
            var size = m.length;
            var qz = 4; // 静区：4个模块
            var total = (size + qz * 2) * cell;
            var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + total + '" height="' + total + '" viewBox="0 0 ' + total + ' ' + total + '" style="display:block;margin:0 auto">'
                + '<rect width="' + total + '" height="' + total + '" fill="#ffffff"/>';
            for (var r = 0; r < size; r++) for (var c = 0; c < size; c++) {
                if (m[r][c] === 1 || m[r][c] === 11) {
                    svg += '<rect x="' + ((c + qz) * cell) + '" y="' + ((r + qz) * cell) + '" width="' + cell + '" height="' + cell + '" fill="#000000" shape-rendering="crispEdges"/>';
                }
            }
            return svg + '</svg>';
        }
        return {
            svg: function (text, cell) {
                cell = cell || 4;
                var version = bestVersion(text.length);
                if (version === 0) return '<p style="color:var(--ow-red);font-size:13px">二维码生成失败：内容超出容量</p>';
                var cap = CAP[version];
                var bytes = bitStream(text, version);
                // 分块 + RS
                var blocks = cap[2], dataPer = cap[0], ecPer = cap[1];
                var allData = [], allEc = [];
                for (var b = 0; b < blocks; b++) {
                    var chunk = bytes.slice(b * dataPer, (b + 1) * dataPer);
                    allData.push(chunk);
                    allEc.push(rsEncode(chunk, ecPer));
                }
                // 交错
                var finalBytes = [];
                for (var i = 0; i < dataPer; i++) for (var b = 0; b < blocks; b++) finalBytes.push(allData[b][i]);
                for (var i = 0; i < ecPer; i++) for (var b = 0; b < blocks; b++) finalBytes.push(allEc[b][i]);
                // 选掩码（简化：用掩码0）
                var m = makeMatrix(version);
                placeData(m, finalBytes, version);
                applyMask(m, 0);
                placeFormat(m, 0);
                return toSvg(m, cell);
            }
        };
    })();

    /* ---------- 工具 ---------- */
    function $(id) { return document.getElementById(id); }
    function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function getParam(name) {
        var m = new RegExp('[?&]' + name + '=([^&]*)').exec(location.search);
        return m ? decodeURIComponent(m[1].replace(/\+/g, ' ')) : '';
    }
    function showToast(msg) {
        var t = $('owToast');
        if (!t) { toast(msg); return; }   // v1.0.112：兜底改核心 toast，禁原生 alert
        t.textContent = msg; t.style.display = 'block';
        setTimeout(function () { t.style.display = 'none'; }, 2200);
    }

    /* ==========================================================================
     * 登录页：覆盖密码登录表单为普通提交 + 2FA 挑战表单
     * ========================================================================== */
    function initLoginPage() {
        var form = document.querySelector('.ow-auth-form[data-mode="login"]');
        if (!form) return;

        // 接管登录提交：核心 AJAX login 成功后，先查询是否待两步验证
        // （普通表单提交到 plugin_twofa_* 会被核心签名门禁拒绝，故全程走 OwApi）
        var origSubmit = form.onsubmit;
        form.onsubmit = function (e) {
            if (e.preventDefault) e.preventDefault(); else e.returnValue = false;
            var data = {}, i, els = form.elements;
            for (i = 0; i < els.length; i++) {
                if (!els[i].name || els[i].name === 'ts' || els[i].name === 'sign') continue;
                data[els[i].name] = els[i].value;
            }
            var msg = form.querySelector('.ow-form-msg');
            if (msg) msg.innerHTML = '登录中…';
            /* v1.0.5：提交防重入。一次点击发两个请求 = 失败计数翻倍，
               而核心「失败 10 次锁 15 分钟」是按次数的，用户会被自己的双击锁在门外。
               老核心没有 OwAuth.submitOnce 时退回直接提交（宁可少一层保护也不能登不进）。 */
            var send = function (done) {
                OwApi.post('login', data, function (r) {
                    if (!r.ok) {
                        done();
                        if (msg) msg.innerHTML = '<span style="color:var(--ow-red)">' + esc(r.msg) + '</span>';
                        if (r.captcha && $('owCaptchaRow')) {
                            $('owCaptchaRow').style.display = 'block';
                            var img = $('owCaptchaImg');
                            if (img) img.src = '?action=captcha&_=' + new Date().getTime();
                        }
                        return;
                    }
                    // 密码已通过：查询是否需要两步验证（钩子已撤销会话并置 pending）
                    // 成功分支不解禁：要么整页跳走，要么原地换成验证码表单，按钮随之消失
                    OwApi.post('plugin_twofa_check', {}, function (c) {
                        if (c.ok && c.need) { showTwoFAChallenge(form); return; }
                        location.href = '?page=chat';
                    });
                });
            };
            if (w.OwAuth && OwAuth.submitOnce) OwAuth.submitOnce(form, send);
            else send(function () { });
        };
    }

    /** 两步验证挑战表单（AJAX 提交，原位替换登录表单） */
    function showTwoFAChallenge(form) {
        var card = document.querySelector('.ow-auth-card');
        if (!card) return;
        var html = '<div class="ow-auth-logo"><img src="assets/img/logo.svg" alt="Owlsgo-Chat"><h1>两步验证</h1>'
            + '<p>请输入验证器 App 中的 6 位动态验证码，或使用一次性恢复码。</p></div>'
            + '<form class="ow-auth-form">'
            + '<div class="ow-form-item"><label>动态验证码 / 恢复码</label>'
            + '<input class="ow-input" id="owTwoFACode" required autocomplete="one-time-code" placeholder="6 位数字或恢复码" style="letter-spacing:4px;font-size:18px;text-align:center">'
            + '</div>'
            + '<div class="ow-form-msg"></div>'
            + '<button class="ow-btn ow-btn-primary ow-btn-block" type="submit">验 证</button>'
            + '</form>'
            + '<div class="ow-auth-links">'
            + '<a href="javascript:;" id="owTwoFACancel">返回重新登录</a>'
            + '</div>';
        card.innerHTML = html;
        var m = card.querySelector('.ow-form-msg');
        var f = card.querySelector('form');
        f.onsubmit = function (e) {
            if (e.preventDefault) e.preventDefault(); else e.returnValue = false;
            var code = ($('owTwoFACode') || {}).value || '';
            if (!code) { m.innerHTML = '<span style="color:var(--ow-red)">请填写验证码</span>'; return; }
            m.innerHTML = '验证中…';
            OwApi.post('plugin_twofa_verify', { code: code }, function (r) {
                if (r.ok) { location.href = '?page=chat'; return; }
                m.innerHTML = '<span style="color:var(--ow-red)">' + esc(r.msg) + '</span>';
                var input = $('owTwoFACode');
                if (input) { input.value = ''; input.focus(); }
            });
        };
        var cancel = $('owTwoFACancel');
        if (cancel) cancel.onclick = function () {
            OwApi.post('plugin_twofa_cancel', {}, function () { location.href = '?page=login'; });
        };
        var input = $('owTwoFACode');
        if (input) input.focus();
    }

    /* ==========================================================================
     * 聊天页个人设置：注入两步验证管理
     * ========================================================================== */
    function initChatPage() {
        if (!w.OwChat || !OwChat.cfg) return;
        if (w.__haTwoFALoaded) return;
        w.__haTwoFALoaded = true;

        var origOpenSettings = OwChat.openSettings;
        OwChat.openSettings = function () {
            origOpenSettings.call(this);
            loadTwoFASection();
        };
    }

    function loadTwoFASection() {
        OwApi.post('plugin_twofa_status', {}, function (r) {
            if (!r.ok) return;
            injectTwoFASection(r.enabled, r.recovery_remain);
        });
    }

    function injectTwoFASection(enabled, remain) {
        var modal = $('owModal');
        if (!modal) return;
        var saveBtn = modal.querySelector('.ow-btn-primary');
        if (!saveBtn) return;

        // 避免重复注入
        if (modal.querySelector('#owTwoFASection')) return;

        var section = document.createElement('div');
        section.id = 'owTwoFASection';
        section.style.cssText = 'border-top:1px solid var(--ow-border,#e8e8e8);margin-top:16px;padding-top:16px';

        var title = '<h3 style="margin:0 0 10px;font-size:15px">两步验证（2FA）</h3>';
        var body;

        if (enabled) {
            body = '<div class="ow-form-item"><label>状态</label>'
                + '<div class="ow-input" style="background:var(--ow-bg-sub,#f5f5f5);color:var(--ow-green)">已启用 · 剩余恢复码 ' + remain + ' 个</div></div>'
                + '<div class="ow-form-row" style="gap:8px">'
                + '<button class="ow-btn ow-btn-ghost" onclick="OwTwoFA.resetCodes()" style="flex:1">重置恢复码</button>'
                + '<button class="ow-btn ow-btn-danger" onclick="OwTwoFA.disable()" style="flex:1">关闭两步验证</button>'
                + '</div>';
        } else {
            body = '<div class="ow-form-item"><label>状态</label>'
                + '<div class="ow-input" style="background:var(--ow-bg-sub,#f5f5f5);color:var(--ow-text-sub)">未启用</div></div>'
                + '<button class="ow-btn ow-btn-primary ow-btn-block" onclick="OwTwoFA.setup()">启用两步验证</button>';
        }

        section.innerHTML = title + body;
        // v1.3.117：个人设置已经分成「资料 / 外观 / 账号」三页，两步验证属于账号安全，
        // 必须塞进「账号」这一页。插到弹窗根节点会让它在每一页都露出来，
        // 还跟固定 640px 的窗口抢高度，把内容区压成一个滚动小框。
        // 老核心没有 [data-pane] 结构 → owTabs.pane 返回 null，回落到旧写法插在保存按钮后面。
        var host = w.owTabs && owTabs.pane(modal, 'acct');
        if (host) host.appendChild(section);
        else saveBtn.parentNode.insertBefore(section, saveBtn.nextSibling);
    }

    /* ---------- 2FA 管理操作 ---------- */
    var OwTwoFA = {
        setup: function () {
            OwApi.post('plugin_twofa_setup', {}, function (r) {
                if (!r.ok) { showToast(r.msg); return; }
                showSetupModal(r.secret, r.otpauth);
            });
        },

        /**
         * 自研密码确认弹窗（复用核心 OwChat.openModal）。
         * 确认后执行 onSubmit(密码)；取消则回到个人设置弹窗。
         */
        passwordModal: function (title, desc, onSubmit) {
            OwChat.openModal(
                '<h3>' + esc(title) + '</h3>'
                + '<p class="ow-modal-desc">' + esc(desc) + '</p>'
                + '<input type="password" class="ow-input" id="owTwoFAPw" placeholder="当前账号密码" autocomplete="current-password">'
                + '<div class="ow-modal-actions">'
                + '<button class="ow-btn ow-btn-ghost" id="owTwoFAPwNo">取消</button>'
                + '<button class="ow-btn ow-btn-primary" id="owTwoFAPwOk">确认</button></div>'
            );
            var submit = function () {
                var pw = $('owTwoFAPw').value;
                if (!pw) { showToast('请输入账号密码'); return; }
                onSubmit(pw);
            };
            $('owTwoFAPwOk').onclick = submit;
            $('owTwoFAPwNo').onclick = function () {
                OwChat.closeModal();
                if (OwChat.cfg && OwChat.cfg.me) OwChat.openSettings();
            };
            var input = $('owTwoFAPw');
            input.onkeydown = function (e) {
                e = e || window.event;
                if ((e.key === 'Enter' || e.keyCode === 13) && typeof submit === 'function') submit();
            };
            input.focus();
        },

        /** 启用确认：读取启用弹窗内的验证码输入框（自研 UI，不用浏览器原生 prompt） */
        enable: function (secret) {
            var input = $('owTwoFAEnableCode');
            var code = input ? input.value.replace(/\s+/g, '') : '';
            if (!code) { showToast('请输入验证码'); return; }
            OwApi.post('plugin_twofa_enable', { code: code }, function (r) {
                if (!r.ok) { showToast(r.msg); return; }
                showRecoveryCodes(r.recovery_codes, '两步验证已启用！请妥善保存以下恢复码，每个仅可使用一次：');
                // 刷新个人设置弹窗
                if (OwChat && OwChat.cfg && OwChat.cfg.me) OwChat.openSettings();
            });
        },

        disable: function () {
            var self = this;
            self.passwordModal('关闭两步验证', '关闭后登录将不再需要动态验证码。为确认是本人操作，请输入当前账号密码：', function (pw) {
                OwApi.secure('plugin_twofa_disable', { password: pw }, function (r) {
                    showToast(r.msg);
                    if (r.ok && OwChat && OwChat.cfg && OwChat.cfg.me) OwChat.openSettings();
                });
            });
        },

        resetCodes: function () {
            var self = this;
            self.passwordModal('重置恢复码', '重置后旧恢复码将全部作废。为确认是本人操作，请输入当前账号密码：', function (pw) {
                OwApi.secure('plugin_twofa_reset_codes', { password: pw }, function (r) {
                    if (!r.ok) { showToast(r.msg); return; }
                    showRecoveryCodes(r.recovery_codes, '恢复码已重置！旧恢复码全部作废，请妥善保存新恢复码：');
                });
            });
        }
    };
    w.OwTwoFA = OwTwoFA;

    function showSetupModal(secret, otpauth) {
        var qrSvg = owQR.svg(otpauth, 6);
        var modal = $('owModal');
        if (!modal) return;
        var section = $('owTwoFASection');
        var html = '<h3 style="margin:0 0 10px;font-size:15px">启用两步验证</h3>'
            + '<ol style="margin:0 0 12px;padding-left:20px;font-size:13px;line-height:1.8">'
            + '<li>打开验证器 App（如 Google Authenticator、Microsoft Authenticator）</li>'
            + '<li>扫描下方二维码，或手动输入密钥</li>'
            + '<li>输入 App 中显示的 6 位验证码完成启用</li>'
            + '</ol>'
            + '<div style="text-align:center;margin:12px 0">'
            + qrSvg
            + '<div style="margin-top:10px;display:inline-block;background:var(--ow-bg-sub,#f5f5f5);border:1px solid var(--ow-border,#e8e8e8);border-radius:4px;padding:6px 12px">'
            + '<span style="font-family:monospace;font-size:13px;letter-spacing:2px;color:var(--ow-text);user-select:all">' + esc(secret) + '</span>'
            + '</div>'
            + '</div>'
            + '<div class="ow-form-row" style="align-items:stretch">'
            + '<input class="ow-input" id="owTwoFAEnableCode" placeholder="输入 6 位验证码" style="flex:1;letter-spacing:3px;text-align:center;height:38px;box-sizing:border-box;margin:0">'
            + '<button class="ow-btn ow-btn-primary" onclick="OwTwoFA.enable()" style="height:38px;box-sizing:border-box;line-height:1;margin:0">确认启用</button>'
            + '</div>';
        if (section) section.innerHTML = html;
        // 绑定确认按钮（因为 prompt 方式改用输入框）
        var btn = section.querySelector('button[onclick="OwTwoFA.enable()"]');
        if (btn) {
            btn.onclick = function () {
                var code = $('owTwoFAEnableCode').value;
                if (!code) { showToast('请输入验证码'); return; }
                OwApi.post('plugin_twofa_enable', { code: code }, function (r) {
                    if (!r.ok) { showToast(r.msg); return; }
                    showRecoveryCodes(r.recovery_codes, '两步验证已启用！请妥善保存以下恢复码，每个仅可使用一次：');
                    if (OwChat && OwChat.cfg && OwChat.cfg.me) OwChat.openSettings();
                });
            };
        }
    }

    function showRecoveryCodes(codes, title) {
        var modal = $('owModal');
        if (!modal) { w.OwChat.openModal('<h3>' + esc(title) + '</h3><p class="ow-modal-desc">' + codes.map(function (c) { return esc(c); }).join('<br>') + '</p>'); return; }   // v1.0.112：兜底改自研弹窗
        var list = codes.map(function (c) {
            return '<span style="display:inline-block;font-family:monospace;background:var(--ow-bg-sub,#f5f5f5);padding:4px 10px;margin:4px;border-radius:3px;min-width:90px;text-align:center">' + esc(c) + '</span>';
        }).join('');
        var html = '<h3 style="margin:0 0 10px;font-size:15px;color:var(--ow-red)">⚠ 恢复码</h3>'
            + '<p style="font-size:13px;margin:0 0 12px">' + esc(title) + '</p>'
            + '<div style="text-align:center;margin-bottom:12px">' + list + '</div>'
            + '<p style="font-size:12px;color:var(--ow-text-sub);margin:0 0 12px">这些恢复码仅显示一次，请立即复制并保存在安全的地方。丢失后无法找回。</p>'
            + '<button class="ow-btn ow-btn-primary ow-btn-block" onclick="OwChat.closeModal()">我已保存</button>';
        modal.innerHTML = '<button class="ow-modal-close" onclick="OwChat.closeModal()">✕</button>' + html;
        $('owModalMask').style.display = 'flex';
    }

    /* ---------- 启动 ---------- */
    function boot() {
        // 登录页
        if (document.querySelector('.ow-auth-form[data-mode="login"]')) {
            initLoginPage();
            return;
        }
        // 聊天页
        if (w.OwChat && OwChat.cfg) initChatPage();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(window);
