/**
 * voice-input · 语音输入（v1.0.0）
 *
 * 一句话：把「说出来」变成「输入框里的字」。识别只用浏览器原生 Web Speech API，
 * 零依赖、无 API Key、无后端 —— 本插件没有任何服务端代码。
 *
 * ── 特性检测 → 优雅降级 ──────────────────────────────────────
 *   按钮由 main.php 渲染、默认 display:none。这里探测
 *   window.SpeechRecognition || window.webkitSpeechRecognition：
 *   拿不到就**保持隐藏**（Firefox 长期禁用该接口），不报错、不留半个空按钮。
 *
 * ── 隐私：processLocally ─────────────────────────────────────
 *   Chrome 的默认行为是把音频发到 Google 服务器识别。`recognition.processLocally = true`
 *   要求必须在本机执行（音频与转录都不出设备，前提是浏览器装好了该语言的离线语音包）。
 *   本插件默认走这条路，并对两个坑做了处理：
 *     ① **「设上了」不等于「生效了」**：浏览器没实现这条 IDL 属性时，赋值只是一个
 *        无人理会的 expando —— 不报错、不生效，音频照样上云。所以先 `'processLocally' in rec`
 *        判断能力，再决定要不要设；没有能力就当作「本机不可用」。
 *     ② 能力有但本机没装离线包时，识别会抛 language-not-supported / network。
 *        这时**不静默降级**：浮层里说明原因，由用户点「改用在线识别」显式授权，
 *        此后标签一直显示「在线识别」（可读性优先于美观，不能让人以为还在本机跑）。
 *
 * ── 交互 ────────────────────────────────────────────────────
 *   点麦克风 → 浮层出现，边说边出字（interimResults，未定稿句子用浅色 + 闪烁光标）。
 *   ✓ 把「已定稿 + 未定稿」整体**插入当前光标处**，不动用户已经打的字；✕ / Esc 丢弃。
 *   再点一次麦克风 = 完成。切换会话自动取消（避免把 A 群的话插进 B 群的输入框）。
 *   continuous = true 持续听写，直到用户点 ✓；服务端静默断流时自动续听（有次数上限）。
 *
 * ── 与核心的接缝 ──────────────────────────────────────────────
 *   · 只依赖核心公开的东西：OwChat.cfg / OwChat.autoGrow / OwChat.onViewChange、
 *     全局 toast / esc。不碰核心源码，不改 index.php。
 *   · 写入输入框后派发一次 input 事件，核心绑在 #owInput 上的 oninput（自动增高）会收到。
 */
(function (w, d) {
    'use strict';

    /* 后台页 / 登录页 / 安装页也会加载合并脚本：没有 OwChat.cfg 直接退出 */
    if (!w.OwChat || !OwChat.cfg) return;
    if (w.__owViLoaded) return;                 // 幂等：脚本被引入两次也不重复注册
    w.__owViLoaded = true;

    var SR = w.SpeechRecognition || w.webkitSpeechRecognition || null;
    var btn = d.getElementById('owBtnSpeech');
    if (!btn) return;                           // 插件停用：钩子没注册，按钮根本不存在
    if (!SR) return;                            // 不支持：按钮保持 display:none，安静退出

    /* 本机识别能力：只有浏览器真的实现了 processLocally 这条 IDL 属性，`in` 才为真。
       详见文件头 ① —— 这一句是「不骗用户」的前提，别改成只看赋值是否成功。 */
    var localOK = false;
    try { localOK = ('processLocally' in new SR()); } catch (e) { localOK = false; }

    // 能力齐了才让按钮显形。服务端把按钮渲染成 display:none 就是为了这一步：
    // 没有 SpeechRecognition 的环境里它始终不出现，界面与不支持之前完全一样。
    btn.style.display = '';

    var $ = function (id) { return d.getElementById(id); };
    var esc = w.esc;
    var toast = w.toast;

    var LS_MODE = 'owvi_mode';      // 'cloud' = 用户已显式同意在线识别；缺省/其它 = 本机
    var MAX_SEC = 180;              // 单次听写上限，到时自动收尾（免得忘了关一直占着麦克风）
    var RESTART_MAX = 40;           // 断流自动续听的次数上限

    /* ---------- 文案 ----------
       走插件自带的对照表，**不调用 OwI18n.register()**：那个接口对同一 code 是整体替换
       （`packs[i].dict = dictionary`），插件注册 'en' 会把 lang-en 整本词典冲掉。
       页面语言取自 <body data-lang>，与核心语言包同一来源。 */
    var EN = {
        '语音输入': 'Voice input',
        '正在听，请说话…': 'Listening — start speaking…',
        '本机识别': 'On this device',
        '在线识别': 'Online',
        '音频只在本机识别，不上传': 'Recognised on this device — audio never leaves it',
        '音频会发送到浏览器厂商的服务器': 'Audio is sent to your browser vendor’s servers',
        '没有听到声音，请靠近麦克风再说一次': 'Nothing heard — move closer to the microphone and try again',
        '当前会话不能发言': 'You cannot send messages in this conversation',
        '语音识别失败': 'Speech recognition failed',
        '网络异常，在线识别暂时不可用': 'Network error — online recognition is unavailable',
        '此浏览器不支持在本机离线识别，改用在线识别会把语音发送到浏览器厂商的服务器。':
            'This browser cannot recognise speech on-device. Using online recognition sends your audio to your browser vendor’s servers.',
        '本机没有可用的离线语音包，无法在本机识别。': 'No offline speech pack is available on this device.',
        '可在浏览器设置里下载对应语言的离线语音包后重试。': 'You can download the offline speech pack in your browser settings and try again.',
        '改用在线识别': 'Use online recognition',
        '麦克风权限被拒绝，请在浏览器地址栏的权限设置里允许后重试':
            'Microphone access was denied. Allow it in your browser, then try again',
        '未检测到麦克风设备': 'No microphone was found',
        '输入栏未就绪，请刷新页面': 'The input bar is not ready — refresh the page',
        '已取消语音输入：切换了会话': 'Voice input cancelled — you switched conversations'
    };
    var IS_EN = ((d.body && d.body.getAttribute('data-lang')) || 'zh') === 'en';
    function t(s) { return (IS_EN && EN[s]) || s; }

    function store(k, v) {
        try {
            if (v === undefined) return w.localStorage.getItem(k);
            w.localStorage.setItem(k, v);
        } catch (e) { /* 隐私模式 / 禁用存储：不影响功能，只是每次都回到默认（本机识别） */ }
        return null;
    }

    function siteLang() {
        return IS_EN ? 'en-US' : 'zh-CN';
    }

    /* ============================ 状态 ============================ */

    var VI = {
        panel: null,        // 浮层 DOM（建一次，复用）
        rec: null,          // 当前 SpeechRecognition 实例
        active: false,      // 是否处于一次「听写会话」中（含等待授权的空档）
        waiting: false,     // 等待用户授权在线识别（此时没有在录音）
        stopFlag: false,    // 已请求收尾，onend 不必再续听
        fatal: false,       // 已判定失败，onend 不必再续听
        wantCommit: false,  // 收尾时是否写入输入框
        finalText: '',      // 已定稿
        interim: '',        // 未定稿（尾句）
        restarts: 0,
        silent: 0,
        lang: 'zh-CN',
        mode: 'local',
        t0: 0,
        timer: null,        // 计时器
        finishTimer: null,  // 收尾兜底（abort 后部分浏览器不再触发 onend）
        view: ''            // 开始时的会话标识，用于「切会话即取消」
    };

    /* ============================ 浮层 ============================ */

    function build() {
        if (VI.panel && VI.panel.parentNode) return VI.panel;

        var bar = d.querySelector ? d.querySelector('.ow-inputbar') : null;
        if (!bar) return null;

        var p = d.createElement('div');
        p.id = 'owViPanel';
        p.className = 'ow-vi';
        p.setAttribute('role', 'dialog');
        p.setAttribute('aria-label', t('语音输入'));
        p.innerHTML =
              '<div class="ow-vi-bar">'
            +   '<span class="ow-vi-wave" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></span>'
            +   '<span class="ow-vi-time" id="owViTime">0:00</span>'
            +   '<span class="ow-vi-tag" id="owViTag"></span>'
            +   '<span class="ow-vi-btns">'
            +     '<button class="ow-vi-btn ow-vi-x" id="owViX" type="button"'
            +     ' title="' + esc(t('取消')) + '" aria-label="' + esc(t('取消')) + '">&#10005;</button>'
            +     '<button class="ow-vi-btn ow-vi-ok" id="owViOk" type="button"'
            +     ' title="' + esc(t('完成')) + '" aria-label="' + esc(t('完成')) + '">&#10003;</button>'
            +   '</span>'
            + '</div>'
            + '<div class="ow-vi-body" id="owViBody">'
            +   '<span class="ow-vi-ph" id="owViPh">' + esc(t('正在听，请说话…')) + '</span>'
            +   '<span class="ow-vi-final" id="owViFinal"></span>'
            +   '<span class="ow-vi-live" id="owViLive"></span>'
            +   '<span class="ow-vi-caret" aria-hidden="true"></span>'
            + '</div>'
            + '<div class="ow-vi-note" id="owViNote" style="display:none"></div>';

        // 挂在输入栏内部：.ow-inputbar 是 position:relative，浮层用 bottom:100% 贴住它的上沿，
        // 输入框自动增高 / 用户拖高时浮层自动跟着走，不需要任何 JS 计算高度。
        bar.appendChild(p);

        VI.panel = p;
        $('owViX').onclick = function () { cancel(); };
        $('owViOk').onclick = function () { stop(); };
        return p;
    }

    function showPanel() {
        var p = build();
        if (!p) return false;
        p.className = 'ow-vi is-on';
        btn.className = 'ow-icon-btn ow-vi-entry is-on';
        btn.setAttribute('aria-pressed', 'true');
        return true;
    }

    function hidePanel() {
        stopTimer();
        if (VI.panel) VI.panel.className = 'ow-vi';
        btn.className = 'ow-icon-btn ow-vi-entry';
        btn.setAttribute('aria-pressed', 'false');
    }

    /* 面板内提示条：html 是已经处理好的正文，acts = [{label, primary, run}] */
    function note(html, acts) {
        var box = $('owViNote');
        if (!box) return;
        var h = '<div class="ow-vi-nt">' + html + '</div>', i;
        if (acts && acts.length) {
            h += '<div class="ow-vi-na">';
            for (i = 0; i < acts.length; i++) {
                h += '<button type="button" class="ow-vi-nb' + (acts[i].primary ? ' is-primary' : '')
                   + '" data-i="' + i + '">' + esc(acts[i].label) + '</button>';
            }
            h += '</div>';
        }
        box.innerHTML = h;
        box.style.display = '';
        var bs = box.getElementsByTagName('button');
        for (i = 0; i < bs.length; i++) {
            bs[i].onclick = function () {
                var k = parseInt(this.getAttribute('data-i'), 10) || 0;
                if (acts[k] && acts[k].run) acts[k].run();
            };
        }
    }

    function clearNote() {
        var box = $('owViNote');
        if (box) { box.style.display = 'none'; box.innerHTML = ''; }
    }

    /* ============================ 渲染 ============================ */

    function render() {
        var f = $('owViFinal'), live = $('owViLive'), ph = $('owViPh');
        if (f) f.textContent = VI.finalText;
        if (live) live.textContent = VI.interim;
        if (ph) ph.style.display = (VI.finalText || VI.interim) ? 'none' : '';
        var body = $('owViBody');
        if (body) body.scrollTop = body.scrollHeight;   // 最新的那句留在视野里
    }

    /* 占位文字换成一句临时提示（"没听到声音"这类）；有正文时不动 */
    function hint(s) {
        if (VI.finalText || VI.interim) return;
        var ph = $('owViPh');
        if (ph) { ph.textContent = t(s); ph.style.display = ''; }
    }

    function setTag() {
        var el = $('owViTag');
        if (!el) return;
        var cloud = VI.mode === 'cloud';
        el.className = 'ow-vi-tag' + (cloud ? ' is-cloud' : '');
        el.textContent = cloud ? t('在线识别') : t('本机识别');
        el.title = cloud ? t('音频会发送到浏览器厂商的服务器') : t('音频只在本机识别，不上传');
    }

    function fmt(sec) {
        return Math.floor(sec / 60) + ':' + (sec % 60 < 10 ? '0' : '') + (sec % 60);
    }

    function startTimer() {
        stopTimer();
        VI.t0 = new Date().getTime();
        var el = $('owViTime');
        if (el) el.textContent = '0:00';
        VI.timer = w.setInterval(function () {
            var s = Math.floor((new Date().getTime() - VI.t0) / 1000);
            var e2 = $('owViTime');
            if (e2) e2.textContent = fmt(s);
            if (s >= MAX_SEC) stop();
        }, 250);
    }

    function stopTimer() {
        if (VI.timer) { w.clearInterval(VI.timer); VI.timer = null; }
    }

    /* ============================ 识别 ============================ */

    function begin() {
        VI.stopFlag = false;
        VI.fatal = false;
        VI.waiting = false;

        var rec;
        try { rec = new SR(); } catch (e) { fail(t('语音识别失败')); return; }

        VI.rec = rec;
        rec.lang = VI.lang;
        rec.interimResults = true;     // 边说边出字
        rec.continuous = true;         // 一直听，直到用户点 ✓
        rec.maxAlternatives = 1;
        if (VI.mode === 'local') {
            try { rec.processLocally = true; } catch (e) { /* 上面已判断过能力，这里只兜底 */ }
        }
        rec.onresult = onResult;
        rec.onerror = onError;
        rec.onend = onEnd;

        try {
            rec.start();
        } catch (e) {
            // 重复 start 会抛 InvalidStateError：说明上一轮还没收尾，等它的 onend 续上即可
            if (e && e.name === 'InvalidStateError') return;
            // 续听被拒（部分移动端要求每次 start 都有用户手势）：体面收尾，
            // 已经识别到的字照样写入输入框 —— 不能因为「续不上」把整段话丢掉
            if (VI.restarts > 0) { stop(); return; }
            fail(t('语音识别失败'));
        }
    }

    function onResult(e) {
        var res = e.results, interim = '', i, seg, txt;
        for (i = e.resultIndex; i < res.length; i++) {
            seg = res[i];
            txt = seg[0] ? String(seg[0].transcript || '') : '';
            if (seg.isFinal) VI.finalText += txt;
            else interim += txt;
        }
        VI.interim = interim;   // 未定稿部分每次整体覆盖，不是累加
        if (VI.finalText || interim) VI.silent = 0;
        render();
    }

    function onError(e) {
        var err = (e && e.error) || '';
        if (err === 'aborted') return;          // 我们自己取消的
        if (VI.stopFlag) return;                // 收尾中，错误不必上屏

        if (err === 'no-speech') {              // 静音超时：onend 会自动续听，这里只改提示
            VI.silent++;
            // 连续几次一句都没听到（麦克风被静音 / 选错设备）：别无限续听下去，收尾并说明原因
            if (VI.silent >= 4) { fail(t('没有听到声音，请靠近麦克风再说一次')); return; }
            hint('没有听到声音，请靠近麦克风再说一次');
            return;
        }
        if (err === 'network' || err === 'language-not-supported') {
            if (VI.mode === 'local') { askCloud('pack'); return; }
            fail(t('网络异常，在线识别暂时不可用'));
            return;
        }
        if (err === 'not-allowed' || err === 'service-not-allowed') {
            fail(t('麦克风权限被拒绝，请在浏览器地址栏的权限设置里允许后重试'));
            return;
        }
        if (err === 'audio-capture') { fail(t('未检测到麦克风设备')); return; }
        fail(t('语音识别失败') + (err ? '：' + err : ''));
    }

    function onEnd() {
        VI.rec = null;
        if (!VI.active) return;

        // 手动收尾 / 已判失败 / 正等用户授权：交给 finish / askCloud，不再续听
        if (VI.stopFlag || VI.fatal || VI.waiting) { if (VI.stopFlag) finish(); return; }

        // 服务端静默断流：自动续听（连续会话不会因为一次超时整段丢失）
        if (VI.restarts >= RESTART_MAX) { stop(); return; }
        VI.restarts++;
        w.setTimeout(function () {
            if (VI.active && !VI.stopFlag && !VI.fatal && !VI.waiting && !VI.rec) begin();
        }, 200);
    }

    /* ============================ 开始 / 收尾 ============================ */

    function start() {
        var inp = $('owInput');
        if (!inp) { if (toast) toast(t('输入栏未就绪，请刷新页面')); return; }
        if (inp.disabled) { if (toast) toast(t('当前会话不能发言')); return; }

        VI.active = true;
        VI.waiting = false;
        VI.stopFlag = false;
        VI.fatal = false;
        VI.wantCommit = false;
        VI.finalText = '';
        VI.interim = '';
        VI.restarts = 0;
        VI.silent = 0;
        VI.lang = siteLang();
        VI.mode = store(LS_MODE) === 'cloud' ? 'cloud' : 'local';

        if (!showPanel()) { VI.active = false; if (toast) toast(t('输入栏未就绪，请刷新页面')); return; }
        setTag();
        clearNote();
        render();
        startTimer();

        // 隐私闸门：要本机识别但浏览器没有这条能力 → 不偷偷上云，先问一句
        if (VI.mode === 'local' && !localOK) { askCloud('nocap'); return; }
        begin();
    }

    /**
     * 请用户显式授权在线识别。
     *   nocap 浏览器没实现 processLocally（赋值不会生效，音频会直接上云）
     *   pack  能力有，但本机没有该语言的离线语音包（识别报了 language-not-supported / network）
     */
    function askCloud(reason) {
        VI.waiting = true;
        VI.fatal = false;
        var rec = VI.rec;
        VI.rec = null;
        if (rec) { try { if (rec.abort) rec.abort(); else rec.stop(); } catch (e) {} }
        stopTimer();
        if (VI.panel) VI.panel.className = 'ow-vi is-on is-wait';
        setTag();

        var msg = reason === 'nocap'
            ? t('此浏览器不支持在本机离线识别，改用在线识别会把语音发送到浏览器厂商的服务器。')
            : t('本机没有可用的离线语音包，无法在本机识别。') + t('可在浏览器设置里下载对应语言的离线语音包后重试。');

        note(msg, [
            { label: t('取消'), run: function () { cancel(); } },
            { label: t('改用在线识别'), primary: true, run: function () {
                store(LS_MODE, 'cloud');
                VI.mode = 'cloud';
                VI.waiting = false;
                VI.panel.className = 'ow-vi is-on';
                setTag();
                clearNote();
                startTimer();
                begin();
            } }
        ]);
    }

    /* 完成：把文字写进输入框。
       wantCommit 在 cancel 里被置 false，所以 cancel 也走同一条收尾路径。 */
    function stop() {
        if (!VI.active) return;
        VI.wantCommit = true;
        endSession(true);
    }

    function cancel() {
        if (!VI.active) { hidePanel(); return; }
        VI.wantCommit = false;
        endSession(false);
    }

    function endSession(commit) {
        VI.wantCommit = commit !== false;
        VI.stopFlag = true;
        var rec = VI.rec;
        VI.rec = null;
        if (rec) {
            try { (commit && rec.stop) ? rec.stop() : (rec.abort ? rec.abort() : rec.stop()); } catch (e) {}
        }
        // 不依赖 onend：abort 后部分浏览器不再触发它，收尾会永远悬着。
        // 也不会丢尾句 —— onend 若先到，finish 里 active 已置 false，这里只是兜底。
        if (VI.finishTimer) w.clearTimeout(VI.finishTimer);
        VI.finishTimer = w.setTimeout(finish, 420);
        if (!rec) finish();
    }

    function finish() {
        if (!VI.active) return;
        VI.active = false;
        if (VI.finishTimer) { w.clearTimeout(VI.finishTimer); VI.finishTimer = 0; }

        // 未定稿的尾句也算：用户停下来点 ✓ 时，最后半句往往还没转成 final
        var text = String(VI.finalText + VI.interim).replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
        var commit = VI.wantCommit;

        VI.finalText = '';
        VI.interim = '';
        VI.waiting = false;
        hidePanel();

        if (commit && text) insert(text);
    }

    function fail(msg) {
        VI.fatal = true;
        VI.active = false;
        var rec = VI.rec;
        VI.rec = null;
        if (rec) { try { if (rec.abort) rec.abort(); else rec.stop(); } catch (e) {} }
        hidePanel();
        if (toast) toast(msg);
    }

    /**
     * 插入到光标处 —— **不覆盖**用户已经输入的内容。
     * 与 chat-scripts 的话术插入同一套写法：老浏览器没有 selectionStart 时退化为追加。
     */
    function insert(text) {
        var inp = $('owInput');
        if (!inp) { if (toast) toast(t('输入栏未就绪，请刷新页面')); return; }
        if (inp.disabled) { if (toast) toast(t('当前会话不能发言')); return; }

        var s = inp.selectionStart, e = inp.selectionEnd, v = inp.value, add = text;
        // 英文与已有文字之间补一个空格，中文直接接上（中文没有词间空格）
        if (IS_EN && s != null && s > 0 && !/\s$/.test(v.slice(0, s))) add = ' ' + add;

        if (s == null || e == null) {
            inp.value = v + add;
        } else {
            inp.value = v.slice(0, s) + add + v.slice(e);
            var pos = s + add.length;
            inp.selectionStart = pos;
            inp.selectionEnd = pos;
        }
        inp.focus();

        // 派发一次 input：核心绑在 #owInput 上的 oninput（自动增高）与任何草稿监听都能收到
        try {
            if (d.createEvent) {
                var ev = d.createEvent('HTMLEvents');
                ev.initEvent('input', true, false);
                inp.dispatchEvent(ev);
            }
        } catch (err) {}
        if (OwChat.autoGrow) OwChat.autoGrow();
    }

    /* ============================ 挂载 ============================ */

    // 切换会话就取消：否则 A 群说的话会被插进 B 群的输入框
    function viewKey(ctx) {
        if (!ctx) return '';
        if (ctx.view === 'dm') return 'dm:' + (ctx.peer || '');
        return ctx.view + ':' + (ctx.roomId || 0);
    }

    function handleView(ctx) {
        var k = viewKey(ctx);
        if (VI.active && VI.view && k !== VI.view) {
            cancel();
            if (toast) toast(t('已取消语音输入：切换了会话'));
        }
        VI.view = k;
    }

    function mount() {
        btn.onclick = function (e) {
            if (e && e.stopPropagation) e.stopPropagation();
            else if (w.event) w.event.cancelBubble = true;
            if (VI.active) stop(); else start();
        };

        if (d.addEventListener) {
            d.addEventListener('keydown', function (e) {
                e = e || w.event;
                if (!VI.active) return;
                if ((e.keyCode || e.which) === 27) {   // Esc 丢弃
                    if (e.preventDefault) e.preventDefault();
                    cancel();
                }
            }, false);
        }

        // 注册时核心会立刻补发一次当前视图，VI.view 因此一开始就是准的
        if (OwChat.onViewChange) OwChat.onViewChange(handleView);
    }

    /* 核心在 OwChat.init 之后才引入插件脚本，此时 DOM 已就绪；仍保留 readyState 分支兜底 */
    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', mount, false);
    else mount();

    w.OwVI = {
        start: start,
        stop: stop,
        cancel: cancel,
        supported: true,
        localCapable: localOK,
        // 内部：核心 onViewChange 的同一个入口，供自动化测试直接驱动视图变更
        handleView: handleView
    };
})(window, document);
