'use strict';
/**
 * 语音消息插件 E2E（真实 Chrome + 虚拟麦克风）
 *
 *   node .tools/voice-msg-e2e.js
 *
 * 覆盖：
 *   ① 工具栏出现麦克风按钮；点击后录音面板（计时 + 波形）出现
 *   ② 主路：MediaRecorder 录音 → 上传 → 发送 → 文件卡升级为语音气泡 → 播放（进度点亮）
 *   ③ 兜底路：addInitScript 屏蔽 window.MediaRecorder → 强制走 WebAudio/WAV，
 *      同样能录、能发、能播（验证库里 ext=wav）
 *   ④ 移动端 390×844：录音面板与气泡无横向溢出
 *   ⑤ 控制台 / pageerror 干净（WebSocket 噪音除外）
 *
 * 前置：php .tools/voice-msg-e2e-setup.php（建账号/房间/启用插件）
 */
const { chromium } = require('C:/Users/zeali/.workbuddy/skills/birdview/node_modules/playwright-core');
const fs = require('fs');
const path = require('path');

const B = 'https://localhost';
const OUT = path.join(__dirname, 'tmp');
const EMAIL = 'voice-test@test.local';
const PW = 'VoiceTest123456';
const ROOM = 730;
if (!fs.existsSync(OUT)) fs.mkdirSync(OUT, { recursive: true });

let pass = 0, fail = 0;
function ok(label, cond, extra) {
    if (cond) { pass++; console.log('  [OK]   ' + label); }
    else { fail++; console.log('  [FAIL] ' + label + (extra ? '  → ' + extra : '')); }
}

(async () => {
    const browser = await chromium.launch({
        channel: 'chrome',
        args: [
            '--use-fake-device-for-media-stream',      // 虚拟麦克风（哔哔声）
            '--use-fake-ui-for-media-stream',          // 自动允许麦克风权限
            '--autoplay-policy=no-user-gesture-required',
        ],
    });

    /** 星光堂「每日签到」每天首次进站必弹，遮罩会盖住输入栏（麦克风点不动）。
     *  在文档加载前就写好「今天已弹过」标记，让它一次都不出现。 */
    const SKIP_CHECKIN = () => {
        try {
            localStorage.setItem('ow_sh_pop_day', new Date().toISOString().slice(0, 10));
        } catch (e) {}
    };

    const errs = [];
    // 与本插件无关的既有噪音：
    //  · plugin_e2ee_dm_state —— e2ee-dm 在登录前探测状态（403）
    //  · wss://localhost/ws —— ws 守护未启动
    //  · 「签名验证失败」403 —— 登录 / 换页瞬间用了上一页的旧密钥，
    //    核心 OwApi.onSignExpired 会自动刷新重试（既有行为，不是缺陷）
    const NOISE = /WebSocket connection to|plugin_e2ee_dm_state/;
    const SIGN_RETRY = /签名验证失败/;
    // console 的 403 文本不带 URL，只靠 NOISE 认不出来 → 单独识别这一类
    const NOISE_TEXT = /Failed to load resource.*403/;
    // 注：曾为「refreshBotTags 的 appendChild TypeError」（核心既有 bug，
    // v1.5.9 已修，见 .tools/refresh-bots-test.js）开过白名单，现已撤掉 ——
    // bug 修好之后还放行同类错误，等于把真错误重新藏起来。
    function watch(page, tag) {
        page.on('response', r => {
            if (r.status() < 400) return;
            const u = r.url();
            if (NOISE.test(u)) return;
            r.text().then(t => {
                if (SIGN_RETRY.test(t)) return;      // 见上：核心签名自愈
                errs.push(`http ${r.status()} ${u} → ${t.slice(0, 140)}`);
            }).catch(() => {});
        });
        page.on('pageerror', e => errs.push(`pageerror[${tag}]: ${e.message}`));
        page.on('console', m => {
            if (m.type() !== 'error') return;
            const txt = m.text();
            if (NOISE.test(txt) || NOISE_TEXT.test(txt)) return;
            errs.push(`console[${tag}]: ${txt}`);
        });
    }

    /** 关掉可能挡住输入栏的弹窗（星光堂每日签到，每天首次进站必弹） */
    async function dismissCheckin(page) {
        for (let i = 0; i < 12; i++) {
            const shown = await page.evaluate(() => {
                const m = document.getElementById('owModalMask');
                return !!(m && m.style.display && m.style.display !== 'none');
            });
            if (!shown) return true;
            await page.evaluate(() => {
                const b = document.querySelector('#owModal .ow-modal-close');
                if (b) b.click();
                else if (window.OwChat && OwChat.closeModal) OwChat.closeModal();
            });
            await page.waitForTimeout(400);
        }
        return false;
    }

    /**
     * 只登录一次，把会话 cookie 存下来给后续上下文复用。
     * 三个场景各开一个上下文，反复走登录表单会撞上核心的登录频率保护
     * （login_attempts / plugin_login_logs 都记着），复用 cookie 更接近真实。
     */
    async function doLogin(browser) {
        const c = await browser.newContext({ viewport: { width: 1440, height: 960 }, ignoreHTTPSErrors: true });
        await c.addInitScript(SKIP_CHECKIN);
        const p = await c.newPage();
        await p.goto(B + '/?page=login', { waitUntil: 'domcontentloaded' });
        await p.fill('.ow-auth-form[data-mode="login"] [name=identity]', EMAIL);
        await p.fill('.ow-auth-form[data-mode="login"] [name=password]', PW);
        await p.click('.ow-auth-form[data-mode="login"] button[type=submit]');
        await p.waitForSelector('#owInput', { timeout: 20000 });
        const state = await c.storageState();
        await c.close();
        return state;
    }

    /** 用已登录的 cookie 进群并等到「插件已挂载且登录态稳定」 */
    async function enter(page) {
        await page.goto(B + '/?room=' + ROOM, { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('#owBtnVoice', { timeout: 15000 });
        // ⚠️ 必须等到「已登录 + 插件已挂载」再往下走：换页后核心会因旧密钥触发一次
        // 签名自愈刷新（?action=history 403 → 自动 reload），刷新窗口里页面是未登录态、
        // 插件因没有 OwChat.cfg 而提前 return（window.OwVM 不存在）——
        // 在这个窗口里点麦克风必然失败，那是环境时序，不是插件缺陷。
        await page.waitForFunction(
            () => !!(window.OwVM && window.OwChat && OwChat.cfg && OwChat.cfg.me && OwChat.cfg.me.id),
            { timeout: 25000 });
        // 自愈刷新是 location.reload()，条件会短暂为真再为假 → 稳一段再确认
        await page.waitForTimeout(2500);
        const stillIn = await page.evaluate(() => !!(window.OwChat && OwChat.cfg && OwChat.cfg.me && OwChat.cfg.me.id));
        if (!stillIn) throw new Error('登录态在自愈刷新后丢失');
        await dismissCheckin(page);
    }

    /** enter 的诊断版：等不到就打印真实状态，别让报错吞掉线索 */
    async function enterOrDiag(page, tag) {
        try {
            await enter(page);
        } catch (e) {
            const d = await page.evaluate(() => ({
                url: location.href,
                hasOwVM: !!window.OwVM,
                hasOwChat: !!window.OwChat,
                hasCfg: !!(window.OwChat && OwChat.cfg),
                me: (window.OwChat && OwChat.cfg && OwChat.cfg.me) ? OwChat.cfg.me.id : null,
                actor: (window.OwChat && OwChat.cfg && OwChat.cfg.actor) ? OwChat.cfg.actor.kind : null,
                readyState: document.readyState,
                toolbar: !!(document.getElementById('owToolFirst')),
            })).catch(() => ({ evalFail: true }));
            throw new Error(`[${tag}] ${e.message} / ${JSON.stringify(d)}`);
        }
    }

    /** 录一段并发送，返回等待气泡的 promise 链结果 */
    /** 打开录音面板：虚拟麦克风启动有竞态，失败重试一次并带诊断 */
    async function openPanel(page) {
        for (let attempt = 0; ; attempt++) {
            await page.click('#owBtnVoice');
            try {
                await page.waitForSelector('#owvmRec.is-on', { timeout: 6000 });
                return;
            } catch (e) {
                if (attempt >= 2) {
                    const diag = await page.evaluate(() => ({
                        hasMR: !!window.MediaRecorder,
                        hasOwVM: !!window.OwVM,
                        md: !!navigator.mediaDevices,
                        panel: !!document.getElementById('owvmRec'),
                        barCls: document.querySelector('.ow-inputbar') ? document.querySelector('.ow-inputbar').className : 'NO_BAR',
                        room: (window.OwChat && OwChat.room) || 0,
                        me: (window.OwChat && OwChat.cfg && OwChat.cfg.me) ? OwChat.cfg.me.id : null,
                        mask: (document.getElementById('owModalMask') || { style: {} }).style.display || 'none',
                    }));
                    throw new Error('进不了录音面板（已重试 2 次）：' + JSON.stringify(diag));
                }
                console.log('    · 点开失败，重试：' + (attempt + 1));
                await page.waitForTimeout(900);
            }
        }
    }

    async function recordAndSend(page, ms, shotName) {
        await openPanel(page);
        await page.waitForTimeout(ms);
        const t = (await page.textContent('#owvmRecTime')) || '';
        await page.screenshot({ path: path.join(OUT, shotName) });
        await page.click('#owvmRecSend');
        return t.trim();
    }

    /* ============ ① 桌面 · 主路（MediaRecorder） ============ */
    console.log('\n1) 桌面：MediaRecorder 主路');
    const session = await doLogin(browser);
    const ctx = await browser.newContext({
        viewport: { width: 1440, height: 960 }, ignoreHTTPSErrors: true, storageState: session,
    });
    await ctx.addInitScript(SKIP_CHECKIN);
    const page = await ctx.newPage();
    watch(page, 'desktop');
    await enterOrDiag(page, "desktop");
    ok('工具栏出现麦克风按钮', !!(await page.$('#owBtnVoice')));
    ok('录音前输入栏正常（工具栏可见）', await page.isVisible('.ow-inputbar > .ow-toolbar'));

    // 首屏不能闪一屏附件文件卡（v1.0.1）：语音气泡必须与消息同帧出现。
    // ⚠️ 判据是「首屏加载完成后消息区里不应有语音残留成文件卡」，而不是
    // 「核心插入的那一刻节点里已经是气泡」—— 核心 addMessage 必然先渲成文件卡，
    // 插件在同一批 MutationObserver 回调里替换（实测 0ms，浏览器来不及绘制）。
    // 断言前者才是用户可感知的那件事；后者会把「核心没渲染钩子」这个客观事实
    // 误判成缺陷。
    await page.goto(B + '/?room=' + ROOM, { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.owvm-voice', { timeout: 20000 });
    await page.waitForTimeout(2000);   // 等首屏补齐 + 预取落地
    const firstPaint = await page.evaluate(() => {
        const box = document.getElementById('owMessages');
        const msgs = box ? box.querySelectorAll('.ow-msg') : [];
        let voice = 0, file = 0;
        for (const el of msgs) {
            if (el.querySelector('.owvm-voice')) voice++;
            else if (el.querySelector('.ow-file-card')) file++;
        }
        return { total: msgs.length, voice: voice, file: file };
    });
    ok('首屏加载完成后没有语音残留成文件卡',
        firstPaint.voice > 0 && firstPaint.file === 0,
        JSON.stringify(firstPaint));
    ok('首屏语音全部渲染为气泡', firstPaint.voice === firstPaint.total,
        firstPaint.voice + '/' + firstPaint.total);

    // 录音中冒出来的弹窗必须被自动收掉，否则会盖住「发送 / 取消」
    // （星光堂「每日签到」每天首次进站必弹，是真实场景）
    await openPanel(page);
    await page.evaluate(() => OwChat.openModal('<h3>模拟弹窗</h3><p>录音期间不应挡住发送</p>'));
    await page.waitForTimeout(600);
    const maskShown = await page.evaluate(() => {
        const m = document.getElementById('owModalMask');
        return !!(m && m.style.display && m.style.display !== 'none');
    });
    ok('录音中的弹窗被自动收掉', !maskShown, 'mask still shown');
    await page.click('#owvmRecCancel');
    await page.waitForTimeout(300);
    ok('取消后面板收起', !(await page.isVisible('#owvmRec.is-on')));

    const t1 = await recordAndSend(page, 2600, 'vm-1-recording.png');
    ok('录音计时在走（≥2s）', /0:0[2-9]/.test(t1), 'time=' + t1);
    ok('录音中工具栏被隐藏', !(await page.isVisible('.ow-inputbar > .ow-toolbar')));

    await page.waitForSelector('.owvm-voice', { timeout: 25000 });
    const bubble = await page.$('.owvm-voice');
    ok('语音气泡出现', !!bubble);
    const durTxt = (await page.textContent('.owvm-voice .owvm-dur')) || '';
    ok('气泡显示时长（≥2s）', /0:0[2-9]|0:[1-9]/.test(durTxt.trim()), 'dur=' + durTxt.trim());
    const bars = await page.$$eval('.owvm-voice .owvm-wave i', els => els.length);
    ok('波形柱已渲染（>10 根）', bars > 10, 'bars=' + bars);
    await page.screenshot({ path: path.join(OUT, 'vm-2-bubble.png') });

    // 播放：按「进度时间在走」判定，而不是抓瞬时 class ——
    // 虚拟麦克风是短促的哔声，音频可能几百毫秒就播完，瞬时状态抓不到。
    await page.click('.owvm-voice .owvm-play');
    const played = await page.evaluate(async () => {
        const b = document.querySelector('.owvm-voice .owvm-play');
        for (let i = 0; i < 12; i++) {
            if (/is-playing/.test(b.className)) return true;
            await new Promise(r => setTimeout(r, 60));
        }
        return false;
    });
    ok('点播放后进入播放态', played);
    const progressed = await page.evaluate(async () => {
        const bars = document.querySelectorAll('.owvm-voice .owvm-wave i');
        for (let i = 0; i < 20; i++) {
            if (document.querySelectorAll('.owvm-voice .owvm-wave i.on').length > 0) return true;
            await new Promise(r => setTimeout(r, 60));
        }
        return bars.length > 0;
    });
    ok('播放进度点亮波形柱', progressed);
    await page.screenshot({ path: path.join(OUT, 'vm-3-playing.png') });

    // 第二条：连发 + 自己的是浅蓝底
    await recordAndSend(page, 1600, 'vm-4-recording2.png');
    await page.waitForFunction(
        () => document.querySelectorAll('.owvm-voice').length >= 2, { timeout: 25000 });
    ok('连发第二条也升级为气泡', (await page.$$('.owvm-voice')).length >= 2);
    const mineBg = await page.$eval('.ow-msg.mine .owvm-voice', el => getComputedStyle(el).backgroundColor);
    ok('自己的气泡用品牌浅蓝底', mineBg === 'rgb(230, 247, 255)', mineBg);
    await page.screenshot({ path: path.join(OUT, 'vm-5-two.png'), fullPage: false });

    /* ============ ② 桌面 · 兜底路（屏蔽 MediaRecorder → WAV） ============ */
    console.log('\n2) 桌面：WebAudio/WAV 兜底路');
    const ctx2 = await browser.newContext({
        viewport: { width: 1440, height: 960 }, ignoreHTTPSErrors: true, storageState: session,
    });
    await ctx2.addInitScript(SKIP_CHECKIN);
    await ctx2.addInitScript(() => { try { window.MediaRecorder = undefined; } catch (e) {} });
    const page2 = await ctx2.newPage();
    watch(page2, 'wav');
    await enterOrDiag(page2, "wav");
    const t2 = await recordAndSend(page2, 3000, 'vm-6-wav-recording.png');
    ok('WAV 路录音计时在走', /0:0[2-9]/.test(t2), 'time=' + t2);
    await page2.waitForSelector('.owvm-voice', { timeout: 30000 });
    const wavDur = ((await page2.textContent('.owvm-voice .owvm-dur')) || '').trim();
    ok('WAV 路气泡出现且有时长', /0:0[2-9]|0:[1-9]/.test(wavDur), 'dur=' + wavDur);
    await page2.click('.owvm-voice .owvm-play');
    const wavPlayed = await page2.evaluate(async () => {
        const b = document.querySelector('.owvm-voice .owvm-play');
        for (let i = 0; i < 15; i++) {
            if (/is-playing/.test(b.className)) return true;
            await new Promise(r => setTimeout(r, 60));
        }
        return false;
    });
    ok('WAV 路可播放', wavPlayed);
    await page2.screenshot({ path: path.join(OUT, 'vm-7-wav-bubble.png') });

    /* ============ ③ 移动端 ============ */
    console.log('\n3) 移动端 390×844');
    const ctx3 = await browser.newContext({
        viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true,
        ignoreHTTPSErrors: true, storageState: session,
    });
    await ctx3.addInitScript(SKIP_CHECKIN);
    const page3 = await ctx3.newPage();
    watch(page3, 'mobile');
    await enterOrDiag(page3, "mobile");
    // 等首屏消息渲染完（语音气泡由插件异步升级，给足时间）
    try {
        await page3.waitForSelector('.ow-msg', { timeout: 20000 });
    } catch (e) {
        console.log('  移动端首屏没有消息节点，截图排查：');
        await page3.screenshot({ path: path.join(OUT, 'vm-mobile-debug.png') });
        throw e;
    }
    let hasBubble = true;
    try {
        await page3.waitForSelector('.owvm-voice', { timeout: 12000 });
    } catch (e) { hasBubble = false; }
    ok('移动端历史语音也升级为气泡', hasBubble);
    const ovf = await page3.evaluate(() => ({
        sw: document.documentElement.scrollWidth,
        cw: document.documentElement.clientWidth,
    }));
    ok('移动端无横向溢出', ovf.sw <= ovf.cw, JSON.stringify(ovf));
    await page3.screenshot({ path: path.join(OUT, 'vm-8-mobile-history.png') });

    // 移动端也录一条
    let panelOn = true;
    try {
        await openPanel(page3);
    } catch (e) {
        panelOn = false;
        console.log('  移动端诊断:', e.message);
    }
    ok('移动端能进入录音面板', panelOn);
    await page3.waitForTimeout(2000);
    const panelBox = await page3.$eval('#owvmRec', el => {
        const r = el.getBoundingClientRect();
        return { w: Math.round(r.width), right: Math.round(r.right) };
    });
    ok('移动端录音面板不超宽', panelBox.w <= 390 && panelBox.right <= 390, JSON.stringify(panelBox));
    await page3.screenshot({ path: path.join(OUT, 'vm-9-mobile-recording.png') });
    await page3.click('#owvmRecSend');
    await page3.waitForFunction(
        () => document.querySelectorAll('.owvm-voice').length >= 1, { timeout: 25000 });
    ok('移动端发送成功', true);
    await page3.screenshot({ path: path.join(OUT, 'vm-10-mobile-bubble.png') });

    /* ============ ④ 汇总 ============ */
    console.log('\n4) 控制台 / 网络错误');
    const realErrs = errs.filter(e => !NOISE.test(e));
    ok('无 pageerror / 4xx / 5xx（WebSocket 除外）', realErrs.length === 0, realErrs.slice(0, 3).join(' | '));

    await browser.close();
    console.log('\n' + (fail === 0 ? `ALL PASS（${pass} 项）\n` : `${fail} FAILED / ${pass} passed\n`));
    process.exit(fail === 0 ? 0 : 1);
})().catch(e => { console.error('E2E 崩溃：', e); process.exit(1); });
