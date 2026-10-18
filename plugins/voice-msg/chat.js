/**
 * voice-msg · 聊天页录音 + 语音气泡（v1.0.0）
 *
 * 四件事：
 *   ① 绑定工具栏的麦克风按钮（按钮 HTML 由 main.php 的 chat.toolbar 钩子产出）；
 *   ② 录音：面板取代工具栏与输入行（实时波形 + 计时 + 取消/发送）。
 *      路径 A：MediaRecorder（按 isTypeSupported 依次试探 webm/opus →
 *      mp4 → ogg/opus）；路径 B：老浏览器没有 MediaRecorder 时用
 *      AudioContext + ScriptProcessorNode 采 PCM，手动拼 WAV 头兜底。
 *      两条路共用 AnalyserNode 波形与同一套上传 / 发送链路；
 *   ③ 发送：上传（plugin_voice_msg_upload）→ 发送（plugin_voice_msg_send，
 *      服务端走核心 Chat::send 发 file 消息）→ 本地回显；
 *   ④ 呈现：MutationObserver 扫 .ow-msg 里的文件卡，批量问服务端
 *      「这些消息哪些是语音」（plugin_voice_msg_cards），是就把文件卡
 *      换成语音气泡；点气泡播放，波形柱随进度点亮。
 *
 * 插件停用 → 本文件不加载 → 按钮、面板、扫消息一并消失。
 * 兼容老浏览器：只用 var / function，不用箭头函数、let/const、fetch、模板串。
 * 依赖核心暴露的 OwChat / OwApi / esc / toast。
 */
(function (w, d) {
    'use strict';

    if (!w.OwChat || !OwChat.cfg) return;      // 后台 / 登录页也会加载本脚本
    if (w.OwVM) return;                        // 幂等：防重复引入时二次注册
    var OwApi = w.OwApi;
    if (!OwApi) return;

    var $ = function (id) { return d.getElementById(id); };
    var esc = w.esc || function (s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    };
    var toast = w.toast || function (s) { w.alert(s); };
    var raf = w.requestAnimationFrame || w.webkitRequestAnimationFrame
            || function (fn) { return w.setTimeout(fn, 50); };
    var caf = w.cancelAnimationFrame || w.webkitCancelAnimationFrame || w.clearTimeout;

    /* ============================ 配置 ============================ */

    var cfg = null, cfgLoading = false, cfgWait = [];
    function ensureCfg(cb) {
        if (cfg) { cb(); return; }
        cfgWait.push(cb);
        if (cfgLoading) return;
        cfgLoading = true;
        OwApi.post('plugin_voice_msg_conf', {}, function (r) {
            cfgLoading = false;
            cfg = (r && r.ok)
                ? { maxDur: parseInt(r.max_dur, 10) || 60, maxMb: parseInt(r.max_mb, 10) || 10 }
                : { maxDur: 60, maxMb: 10 };   // 取不到按默认走，服务端还有一道钳制
            var fns = cfgWait; cfgWait = [];
            for (var i = 0; i < fns.length; i++) fns[i]();
        });
    }

    /* ============================ MediaRecorder 容器探测 ============================ */
    /* 顺序即偏好：webm/opus（Chrome 系体积最小）→ mp4（Safari 14.1+）
       → ogg/opus（Firefox）。三种容器现代浏览器都能直接播放，
       接收端浏览器负责解码，本插件不做任何转码。 */
    var MR_CANDIDATES = [
        { mime: 'audio/webm;codecs=opus', ext: 'webm' },
        { mime: 'audio/mp4', ext: 'mp4' },
        { mime: 'audio/ogg;codecs=opus', ext: 'ogg' },
        { mime: 'audio/webm', ext: 'webm' },
        { mime: 'audio/ogg', ext: 'ogg' }
    ];
    function pickMr() {
        if (!w.MediaRecorder) return null;            // 没有 MediaRecorder → WAV 兜底
        var can = w.MediaRecorder.isTypeSupported;
        if (typeof can !== 'function') return { mime: '', ext: 'webm' };
        for (var i = 0; i < MR_CANDIDATES.length; i++) {
            try { if (can.call(w.MediaRecorder, MR_CANDIDATES[i].mime)) return MR_CANDIDATES[i]; } catch (e) {}
        }
        return null;                                  // 有一种都没有 → WAV 兜底
    }

    /* ============================ 录音 ============================ */

    /* 波形柱根数：28 根在 190~320px 的气泡宽度里最易读 —— 再密会糊成一片竖线
       （实测 60 根 × 2px 就是这样），再疏又失去「波形」的意象。
       服务端 owVMNormPeaks 的 60 根上限是这里的天花板，28 在其内。 */
    var PEAK_N = 28;

    var rec = {
        on: false, wantSend: false, busy: false,
        stream: null, ac: null, analyser: null, mute: null, td: null,
        mr: null, proc: null, wavChunks: [], mrChunks: [],
        mime: '', ext: 'webm', mode: '',
        levels: [], startedAt: 0, timer: 0, raf: 0,
        waveColor: '#00A0E9'
    };

    function themeColor() {
        try {
            var cs = w.getComputedStyle(d.documentElement);
            var c = cs.getPropertyValue('--ow-primary');
            if (c) return String(c).replace(/^\s+|\s+$/g, '');
        } catch (e) {}
        return '#00A0E9';   // 核心样式没加载时的兜底（品牌蓝）
    }

    function fmtDur(s) {
        s = Math.max(0, Math.floor(s || 0));
        var m = Math.floor(s / 60);
        return m + ':' + ((s % 60) < 10 ? '0' : '') + (s % 60);
    }

    function panel() {
        var p = $('owvmRec');
        if (p) return p;
        var bar = d.querySelector('.ow-inputbar');
        if (!bar) return null;
        p = d.createElement('div');
        p.className = 'owvm-rec';
        p.id = 'owvmRec';
        p.innerHTML =
              '<span class="owvm-dot"></span>'
            + '<span class="owvm-time" id="owvmRecTime">0:00</span>'
            + '<canvas class="owvm-wave-live" id="owvmRecWave"></canvas>'
            + '<span class="owvm-tip" id="owvmRecTip">发送中…</span>'
            + '<button class="ow-btn ow-btn-ghost owvm-act" id="owvmRecCancel" type="button">取消</button>'
            + '<button class="ow-btn ow-btn-primary owvm-act" id="owvmRecSend" type="button">发送</button>';
        bar.insertBefore(p, bar.firstChild);
        $('owvmRecCancel').onclick = function () { stop(false); };
        $('owvmRecSend').onclick = function () { stop(true); };
        return p;
    }

    function start() {
        if (rec.on) return;
        var me = (OwChat.cfg && OwChat.cfg.me) || {};
        if (!me.id) { toast('请先登录后再录音'); return; }
        if (!OwChat.dm && !(OwChat.room > 0)) { toast('请先选择一个群聊或联系人'); return; }
        if (!w.MediaRecorder && !(w.AudioContext || w.webkitAudioContext)) {
            toast('当前浏览器不支持录音'); return;
        }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            toast('当前浏览器不支持录音（需要 HTTPS 或较新浏览器）'); return;
        }
        ensureCfg(function () {
            var p = panel();
            if (!p) { toast('输入栏未就绪，请刷新页面'); return; }
            dismissModal();     // 录音前先把已开的弹窗收掉，别让遮罩挡住录音面板
            try {
                var pr = navigator.mediaDevices.getUserMedia({ audio: true });
                if (pr && pr.then) { pr.then(onStream, onStreamFail); }
                else onStreamFail({ name: 'Unknown', message: '浏览器返回了异常' });
            } catch (e) { onStreamFail(e); }
        });
    }

    /**
     * 收掉当前弹窗（若开着）。
     *
     * 为什么录音要管弹窗：录音时工具栏与输入行整条被录音面板取代，录音面板
     * 就在遮罩层下面 —— 此刻任何一个自动弹窗（星光堂「每日签到」每天首次进站
     * 必弹）都会把「发送 / 取消」一起盖住，用户只能看着录音干等。
     * 核心没有「弹窗被打开」的钩子，只有 `#owModalMask` 的 display 可观测，
     * 所以这里按状态收：录音前收一次，录音期间在心跳里持续收（最迟 250ms）。
     * 代价是录音期间手动打开的弹窗也会被收 —— 那个时间窗内没有任何可点的
     * 弹窗入口（工具栏与输入行已隐藏），被收的实际上只有自动弹窗。
     */
    function dismissModal() {
        var mask = $('owModalMask');
        if (!mask || mask.style.display === 'none' || !mask.style.display) return;
        if (OwChat.closeModal) OwChat.closeModal();
    }

    function onStreamFail(err) {
        var name = err && err.name;
        if (name === 'NotAllowedError' || name === 'SecurityError' || name === 'PermissionDeniedError') {
            toast('麦克风权限被拒绝，请在浏览器地址栏的权限设置里允许后重试');
        } else if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
            toast('未检测到麦克风设备');
        } else {
            toast('无法启动录音：' + ((err && err.message) || '未知错误'));
        }
    }

    function onStream(stream) {
        rec.on = true;
        rec.wantSend = false;
        rec.stream = stream;
        rec.startedAt = new Date().getTime();
        rec.levels = []; rec.wavChunks = []; rec.mrChunks = [];

        /* 音频图：源 → Analyser（波形用）→ 静音 Gain → destination。
           必须挂一个 gain=0 的目的地，否则图不会被拉起来、波形不动；
           两个录音路径共用这一张图。 */
        var AC = w.AudioContext || w.webkitAudioContext;
        try {
            rec.ac = new AC();
            var src = rec.ac.createMediaStreamSource(stream);
            rec.analyser = rec.ac.createAnalyser();
            rec.analyser.fftSize = 512;
            rec.td = new Uint8Array(rec.analyser.fftSize);
            var mute = rec.ac.createGain();
            mute.gain.value = 0;
            src.connect(rec.analyser);
            rec.analyser.connect(mute);
            mute.connect(rec.ac.destination);
            rec.mute = mute;
        } catch (e) { rec.analyser = null; }

        /* 路径 A：MediaRecorder */
        var mr = pickMr();
        if (mr) {
            rec.mode = 'mr';
            rec.mime = mr.mime; rec.ext = mr.ext;
            try {
                rec.mr = new w.MediaRecorder(stream, mr.mime ? { mimeType: mr.mime } : undefined);
                rec.mr.ondataavailable = function (e) {
                    if (e.data && e.data.size) rec.mrChunks.push(e.data);
                };
                rec.mr.onstop = function () {
                    var blob = null;
                    try { blob = new w.Blob(rec.mrChunks, { type: rec.mime || 'audio/webm' }); } catch (e2) {}
                    finish(blob);
                };
                rec.mr.start(250);   // timeslice：周期性吐数据，停止时更稳
            } catch (e) { rec.mr = null; }
        }

        /* 路径 B：WAV 兜底（没有 MediaRecorder，或构造失败） */
        if (!rec.mr) {
            rec.mode = 'wav';
            rec.ext = 'wav'; rec.mime = 'audio/wav';
            if (!rec.ac) { cleanup(); toast('当前浏览器不支持录音'); return; }
            try {
                rec.proc = rec.ac.createScriptProcessor(4096, 1, 1);
                rec.proc.onaudioprocess = function (e) {
                    var ib = e.inputBuffer, i;
                    var l = ib.getChannelData(0);
                    var r = ib.numberOfChannels > 1 ? ib.getChannelData(1) : null;
                    var out = new Float32Array(l.length);
                    for (i = 0; i < l.length; i++) out[i] = r ? (l[i] + r[i]) * 0.5 : l[i];
                    rec.wavChunks.push(out);
                };
                var src2 = rec.ac.createMediaStreamSource(stream);
                src2.connect(rec.proc);
                // ScriptProcessor 必须接到目的地才会被拉起来；经静音 Gain 出去，自己听不到
                rec.proc.connect(rec.mute);
            } catch (e2) {
                cleanup(); toast('当前浏览器不支持录音'); return;
            }
        }

        showPanel(true);
        setTime(0);
        rec.timer = w.setInterval(tick, 250);
        rec.raf = raf(drawWave);
    }

    function tick() {
        var el = Math.floor((new Date().getTime() - rec.startedAt) / 1000);
        setTime(el);
        dismissModal();     // 录音中冒出来的自动弹窗不留到停止后（见 dismissModal 注释）
        rec.levels.push(sampleLevel());
        if (cfg && el >= cfg.maxDur) {
            stop(true);
            toast('已达最长时长，已自动发送');
        }
    }

    /**
     * 把逐拍的响度序列重采样成固定根数的波形。
     * 为什么不直接截前 N 个：录音时长不定（2 秒与 60 秒的点数差 30 倍），
     * 截断会让长语音只显示开头一小段、波形与时长对不上。
     * 按「时长平均分段、段内取峰值」重采样，任何时长都是同样根数，
     * 波形始终铺满整条（28 根：再密会糊成竖线，见 style.css 的柱宽注释）。
     */
    function waveform() {
        var src = rec.levels, n = src.length, out = [], i, j, k, a, b, mx;
        if (!n) return [];
        if (n <= PEAK_N) return src.slice(0);
        for (i = 0; i < PEAK_N; i++) {
            a = Math.floor(i * n / PEAK_N);
            b = Math.max(a + 1, Math.floor((i + 1) * n / PEAK_N));
            mx = 0;
            for (j = a, k = b; j < k && j < n; j++) { if (src[j] > mx) mx = src[j]; }
            out.push(mx);
        }
        return out;
    }

    function sampleLevel() {
        var a = rec.analyser;
        if (!a || !rec.td) return 0;
        a.getByteTimeDomainData(rec.td);
        var sum = 0, i, v;
        for (i = 0; i < rec.td.length; i++) { v = (rec.td[i] - 128) / 128; sum += v * v; }
        var rms = Math.sqrt(sum / rec.td.length);
        return Math.max(2, Math.min(100, Math.round(rms * 400)));
    }

    function drawWave() {
        if (!rec.on) return;
        var cv = $('owvmRecWave');
        if (!cv) { rec.raf = raf(drawWave); return; }
        var ctx = cv.getContext && cv.getContext('2d');
        if (!ctx) { rec.raf = raf(drawWave); return; }
        var dpr = w.devicePixelRatio || 1;
        var W = cv.clientWidth, H = cv.clientHeight;
        if (W > 0 && H > 0 && (cv.width !== Math.floor(W * dpr) || cv.height !== Math.floor(H * dpr))) {
            cv.width = Math.floor(W * dpr);
            cv.height = Math.floor(H * dpr);
        }
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, W, H);
        ctx.fillStyle = rec.waveColor;
        var a = rec.analyser, i, v, amp, h, x, N = 44, bw;
        if (a) {
            a.getByteTimeDomainData(rec.td);
            bw = Math.max(2, Math.floor(W / N) - 2);
            for (i = 0; i < N; i++) {
                v = rec.td[Math.floor(i * rec.td.length / N)] || 128;
                amp = Math.abs(v - 128) / 128;
                h = Math.max(2, amp * H * 1.7);
                x = Math.floor(i * Math.max(0, W - bw) / (N - 1));
                ctx.fillRect(x, (H - h) / 2, bw, h);
            }
        } else if (W > 0) {
            ctx.fillRect(0, (H - 2) / 2, W, 2);   // 没有 Analyser：静止基线，界面不空
        }
        rec.raf = raf(drawWave);
    }

    function stop(send) {
        if (!rec.on) return;
        rec.on = false;
        rec.wantSend = !!send;
        if (rec.timer) { w.clearInterval(rec.timer); rec.timer = 0; }
        if (rec.raf) { caf(rec.raf); rec.raf = 0; }
        if (rec.mode === 'mr' && rec.mr && rec.mr.state !== 'inactive') {
            try { rec.mr.stop(); } catch (e) { finish(null); }   // onstop 里收尾
        } else if (rec.mode === 'wav') {
            finish(wavBlob());
        } else {
            finish(null);
        }
    }

    /** 手动拼 44 字节 WAV 头 + Int16 单声道 PCM（路径 B 的封装） */
    function wavBlob() {
        var sr = (rec.ac && rec.ac.sampleRate) || 44100;
        var total = 0, i, j;
        for (i = 0; i < rec.wavChunks.length; i++) total += rec.wavChunks[i].length;
        if (total <= 0) return null;
        var buf = new ArrayBuffer(44 + total * 2);
        var view = new DataView(buf);
        var p = 0;
        function wstr(s) { for (var k = 0; k < s.length; k++) view.setUint8(p++, s.charCodeAt(k)); }
        function u32(v) { view.setUint32(p, v, true); p += 4; }
        function u16(v) { view.setUint16(p, v, true); p += 2; }
        wstr('RIFF'); u32(36 + total * 2); wstr('WAVE');
        wstr('fmt '); u32(16); u16(1); u16(1); u32(sr); u32(sr * 2); u16(2); u16(16);
        wstr('data'); u32(total * 2);
        for (i = 0; i < rec.wavChunks.length; i++) {
            var ch = rec.wavChunks[i];
            for (j = 0; j < ch.length; j++) {
                var s = Math.max(-1, Math.min(1, ch[j]));
                view.setInt16(p, s < 0 ? s * 0x8000 : s * 0x7FFF, true);
                p += 2;
            }
        }
        try { return new w.Blob([buf], { type: 'audio/wav' }); } catch (e) { return null; }
    }

    /** 收尾：取消即弃；发送则上传 → 发消息 → 本地回显 */
    function finish(blob) {
        var dur = Math.max(0, Math.round((new Date().getTime() - rec.startedAt) / 1000));
        cleanup();
        if (!rec.wantSend) { resetPanel(); return; }
        if (!blob || blob.size <= 0) { toast('录音为空，请重试'); resetPanel(); return; }
        if (dur < 1) { toast('说话时间太短'); resetPanel(); return; }
        setBusy(true);
        var file = blob;
        try { file = new w.File([blob], 'voice.' + rec.ext, { type: rec.mime || 'audio/wav' }); } catch (e) { file = blob; }
        OwApi.upload('plugin_voice_msg_upload', file, { dur: dur, peaks: JSON.stringify(waveform()) }, function (r) {
            if (!r || !r.ok) { setBusy(false); resetPanel(); toast((r && r.msg) || '上传失败'); return; }
            var payload = { path: r.path };
            if (OwChat.dm) {
                payload.room_id = 0;
                payload.to_user_id = OwChat.dm.id || OwChat.dm.peer || 0;
            } else {
                payload.room_id = OwChat.room || 0;
            }
            OwApi.post('plugin_voice_msg_send', payload, function (r2) {
                setBusy(false); resetPanel();
                if (!r2 || !r2.ok) { toast((r2 && r2.msg) || '发送失败'); return; }
                // 服务端 pack 后的完整消息行：本地回显（与 red-packet 同口径）
                if (r2.msg_row && OwChat.addMessage) {
                    try { OwChat.addMessage(r2.msg_row); } catch (e) {}
                }
            });
        });
    }

    function cleanup() {
        if (rec.timer) { w.clearInterval(rec.timer); rec.timer = 0; }
        if (rec.raf) { caf(rec.raf); rec.raf = 0; }
        if (rec.proc) { try { rec.proc.onaudioprocess = null; rec.proc.disconnect(); } catch (e) {} rec.proc = null; }
        if (rec.analyser) { try { rec.analyser.disconnect(); } catch (e) {} rec.analyser = null; }
        if (rec.mute) { try { rec.mute.disconnect(); } catch (e) {} rec.mute = null; }
        if (rec.ac) { try { if (rec.ac.close) rec.ac.close(); } catch (e) {} rec.ac = null; }
        if (rec.stream) {
            var ts = rec.stream.getTracks ? rec.stream.getTracks() : [];
            for (var i = 0; i < ts.length; i++) { try { ts[i].stop(); } catch (e) {}
            }
            rec.stream = null;
        }
        rec.mr = null; rec.wavChunks = []; rec.mrChunks = [];
    }

    /* ============================ 面板显隐 ============================ */

    function showPanel(on) {
        var p = $('owvmRec');
        if (!p) return;
        var bar = p.parentNode;
        if (on) {
            rec.waveColor = themeColor();
            p.className = 'owvm-rec is-on';
            if (bar && bar.className.indexOf('owvm-rec-ing') < 0) bar.className += ' owvm-rec-ing';
        } else {
            p.className = 'owvm-rec';
            if (bar) bar.className = String(bar.className || '').replace(/\s*owvm-rec-ing/g, '');
        }
    }
    function setBusy(b) {
        var p = $('owvmRec');
        if (p) p.className = 'owvm-rec is-on' + (b ? ' is-busy' : '');
    }
    function resetPanel() {
        rec.on = false; rec.busy = false; rec.wantSend = false;
        showPanel(false);
    }
    function setTime(s) {
        var t = $('owvmRecTime');
        if (t) t.innerHTML = fmtDur(s);
    }

    /* ============================ 消息扫描：文件卡 → 语音气泡 ============================ */

    var queue = [], qTimer = 0, mo = null;

    /* 语音清单缓存：msg_id → 气泡数据。
     * 首屏靠它做到「消息与气泡同帧渲染」—— 见 prefetch() 的注释。
     * 增量拿到的结果也并进来，所以缓存会随使用越来越全，重复问的越来越少。 */
    var cards = {};
    function remember(id, v) { cards[String(id)] = v; }
    function hasCard(id) { return !!cards[String(id)]; }
    function takeCard(id) { return cards[String(id)]; }

    /** 当前会话标识（群 / 私聊），切会话时用它判断要不要重取清单 */
    function convKey() {
        if (OwChat.dm) return 'dm:' + (OwChat.dm.id || OwChat.dm.peer || 0);
        return 'room:' + (OwChat.room || 0);
    }
    var prefetchedKey = '';

    /**
     * 预取当前会话最近一页的语音清单（v1.0.1）。
     *
     * 要解决的问题：核心没有「消息内容渲染」钩子，插件只能在消息**已经被渲染成
     * 文件卡之后**才认得出它是不是语音 —— 实测刷新页面后这段间隔约 1.4 秒，
     * 一屏语音会先整屏显示成附件文件卡再逐个变成语音气泡。
     *
     * 所以把「认语音」提前：进会话时先把清单拿进内存，消息渲染出来时直接查内存
     * 决定渲染成什么，**不需要再发请求**。prefetching 标记保证与核心首屏的
     * history 请求并行发起（谁先回都行 —— 回晚了 consider() 自然回落到增量查询）。
     */
    function prefetch(key) {
        if (!key || key === prefetchedKey) return;
        prefetchedKey = key;
        var payload = { limit: 30 };
        if (OwChat.dm) payload.to_user_id = OwChat.dm.id || OwChat.dm.peer || 0;
        else payload.room_id = OwChat.room || 0;
        OwApi.post('plugin_voice_msg_prefetch', payload, function (r) {
            if (!r || !r.ok || !r.items) return;
            for (var k in r.items) {
                if (r.items.hasOwnProperty(k)) remember(k, r.items[k]);
            }
            // 预取回来时首屏可能已经渲染完：把已在 DOM 里的文件卡补升级
            rescan();
        });
    }

    /** 把消息区里还没升级、但缓存里已有答案的文件卡补升级（预取晚到时用） */
    function rescan() {
        var box = $('owMessages');
        if (!box) return;
        var kids = box.getElementsByClassName('ow-msg'), i, id;
        for (i = 0; i < kids.length; i++) {
            if (kids[i].getAttribute('data-owvm')) continue;
            id = parseInt(String(kids[i].id || '').replace('owMsg', ''), 10) || 0;
            if (id > 0 && hasCard(id)) upgrade(id, takeCard(id));
        }
        // 已被 rescan 升级掉的那批不必再问一次：把队列里命中缓存的清出去。
        // 不清也不影响正确性（flush 只是多问一次、结果一样），但首屏能省一发请求。
        if (queue.length) {
            queue = queue.filter(function (q) { return !hasCard(q); });
        }
    }

    function consider(el) {
        if (!el || el.nodeType !== 1) return;
        if (el.getAttribute && el.getAttribute('data-owvm')) return;
        if (!el.querySelector || !el.querySelector('.ow-file-card')) return;   // 只捞文件消息
        var id = parseInt(String(el.id || '').replace('owMsg', ''), 10) || 0;
        if (id <= 0) return;
        // 命中预取清单 → **同步**升级成气泡，与消息同帧出现（首屏不闪）
        if (hasCard(id)) { upgrade(id, takeCard(id)); return; }
        el.setAttribute('data-owvm-q', '1');   // 先占位：失败也不重试，避免每帧都打接口
        queue.push(id);
    }
    function scanNode(n) {
        if (!n || n.nodeType !== 1) return;
        if (n.className && (' ' + n.className + ' ').indexOf(' ow-msg ') >= 0) consider(n);
        if (!n.getElementsByClassName) return;
        var list = n.getElementsByClassName('ow-msg'), i;
        for (i = 0; i < list.length; i++) consider(list[i]);
    }
    function scheduleFlush() {
        if (qTimer || !queue.length) return;
        qTimer = w.setTimeout(flush, 150);   // 合并同一帧内的多条插入，一批问完
    }
    function flush() {
        qTimer = 0;
        if (!queue.length) return;
        var ids = queue.splice(0, 60);
        OwApi.post('plugin_voice_msg_cards', { ids: ids.join(',') }, function (r) {
            if (!r || !r.ok || !r.items) return;
            for (var k in r.items) {
                if (!r.items.hasOwnProperty(k)) continue;
                remember(k, r.items[k]);      // 增量结果并进内存：往后滚动不再重复问
                upgrade(k, r.items[k]);
            }
        });
    }
    function upgrade(id, v) {
        var el = $('owMsg' + id);
        if (!el) return;
        var plain = el.querySelector('.ow-msg-plain');
        if (!plain) return;
        el.setAttribute('data-owvm', '1');
        plain.innerHTML = voiceHtml(v);
        bindVoice(plain.firstChild, v);
    }
    function voiceHtml(v) {
        var peaks = (v && v.peaks) || [];
        var n = peaks.length ? peaks.length : 24, i, bars = '';
        for (i = 0; i < n; i++) {
            var h = peaks.length ? Math.max(4, Math.min(100, peaks[i])) : 38;
            bars += '<i style="height:' + h + '%"></i>';
        }
        return '<div class="owvm-voice" data-dur="' + (v.dur || 0) + '" title="语音消息">'
             + '<button class="owvm-play" type="button" aria-label="播放语音" title="播放语音">'
             + '<span class="owvm-iplay"></span><span class="owvm-ipause"></span></button>'
             + '<span class="owvm-wave">' + bars + '</span>'
             + '<span class="owvm-dur">' + fmtDur(v.dur || 0) + '</span></div>';
    }

    /* ---------- 播放：同一时刻只播一条 ---------- */
    var curAudio = null;
    function setProgress(root, p) {
        var bars = root.getElementsByTagName('i'), i, on;
        for (i = 0; i < bars.length; i++) {
            on = (i + 0.5) / bars.length <= p;
            if (on) { if (bars[i].className.indexOf(' on') < 0) bars[i].className += ' on'; }
            else bars[i].className = String(bars[i].className || '').replace(/\s*on/g, '');
        }
    }
    function bindVoice(root, v) {
        if (!root) return;
        var btn = root.getElementsByTagName('button')[0];
        if (!btn) return;
        var audio = null;
        function play() {
            if (curAudio && curAudio !== audio) { try { curAudio.pause(); } catch (e) {} }
            curAudio = audio;
            try {
                var pr = audio.play();
                if (pr && pr['catch']) pr['catch'](function () {});
            } catch (e) {}
        }
        btn.onclick = function () {
            if (audio) {
                if (audio.paused) play();
                else { try { audio.pause(); } catch (e) {} }
                return;
            }
            audio = new w.Audio();
            audio.preload = 'auto';
            audio.src = v.url;
            audio.onplay = function () { btn.className = 'owvm-play is-playing'; };
            audio.onpause = function () { btn.className = 'owvm-play'; };
            audio.onended = function () {
                btn.className = 'owvm-play';
                setProgress(root, 0);
                if (curAudio === audio) curAudio = null;
            };
            audio.onerror = function () { toast('语音播放失败'); };
            audio.ontimeupdate = function () {
                var dur = v.dur || audio.duration || 1;
                if (!(dur > 0)) dur = 1;
                setProgress(root, Math.max(0, Math.min(1, audio.currentTime / dur)));
            };
            play();
        };
    }

    /* ============================ 挂载 ============================ */

    function onKey(e) {
        if (!rec.on) return;
        e = e || w.event;
        if ((e.keyCode || e.which) === 27) stop(false);   // Esc 取消
    }
    function onView(ctx) {
        if (rec.on) {
            if (ctx && (ctx.view === 'room' || ctx.view === 'dm')) {
                stop(false);
                toast('已取消录音：切换了会话');
            }
        }
        // 换会话要重取清单（每个会话的语音集合不同）
        if (ctx && (ctx.view === 'room' || ctx.view === 'dm')) {
            cards = {};
            prefetchedKey = '';
            prefetch(convKey());
        }
    }

    function mount() {
        var btn = $('owBtnVoice');
        if (!btn) return;   // 插件未启用（钩子没注册）：安静退出
        btn.onclick = function (e) {
            if (e && e.stopPropagation) e.stopPropagation(); else w.event.cancelBubble = true;
            start();
        };
        if (d.addEventListener) d.addEventListener('keydown', onKey, false);
        if (OwChat.onViewChange) OwChat.onViewChange(onView);

        // 首屏预取：与核心的 history 请求并行发出，两者互不等待（见 prefetch 注释）
        prefetch(convKey());

        var box = $('owMessages');
        if (box) {
            // 已渲染的历史消息先补扫一遍，之后的增量交给 MutationObserver
            var kids = box.getElementsByClassName('ow-msg'), i;
            for (i = 0; i < kids.length; i++) consider(kids[i]);
            scheduleFlush();
            if (w.MutationObserver) {
                mo = new w.MutationObserver(function (muts) {
                    for (var i = 0; i < muts.length; i++) {
                        var an = muts[i].addedNodes;
                        for (var j = 0; j < an.length; j++) scanNode(an[j]);
                    }
                    scheduleFlush();
                });
                mo.observe(box, { childList: true });
            }
        }
    }

    w.OwVM = { mount: mount };

    // 核心在 OwChat.init 之后才引入插件脚本，此时 DOM 已就绪
    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', mount, false);
    else mount();
})(window, document);
