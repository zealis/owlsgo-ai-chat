'use strict';
/**
 * 首屏「语音先于文件卡出现」的真实可感知性测量
 *
 *   node .tools/voice-msg-flick2.js
 *
 * v1.0.1 加了预取后，气泡与消息几乎同帧（123ms vs 130ms），但严格测下来
 * 核心 addMessage 插入的那一刻节点里仍是文件卡、插件随后同一批回调里替换。
 * 那这个「文件卡瞬间」用户看不看得见？这里用 MutationObserver 逐条记录
 * 每个 .ow-msg 从插入到变成语音气泡的耗时，看有没有超过一帧（16ms）。
 */
const { chromium } = require('C:/Users/zeali/.workbuddy/skills/birdview/node_modules/playwright-core');
const path = require('path');
const B = 'https://localhost';
const OUT = path.join(__dirname, 'tmp');
const EMAIL = 'voice-test@test.local';
const PW = 'VoiceTest123456';
const ROOM = 730;

(async () => {
    const browser = await chromium.launch({ channel: 'chrome' });
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 960 }, ignoreHTTPSErrors: true });
    await ctx.addInitScript(() => {
        try { localStorage.setItem('ow_sh_pop_day', new Date().toISOString().slice(0, 10)); } catch (e) {}
        // 探针装在文档脚本之前，reload 后也在（init script 每个文档都会跑）
        window.__probe = { spans: [] };
        window.__probeMo = null;
        const boot = () => {
            const box = document.getElementById('owMessages');
            if (!box || box.__probed) return;
            box.__probed = true;
            const born = {};
            window.__probeMo = new MutationObserver(() => {
                const now = performance.now();
                for (const el of box.querySelectorAll('.ow-msg')) {
                    if (!born[el.id]) born[el.id] = { t: now, el: el };
                }
                for (const id in born) {
                    const b = born[id];
                    if (b.el.querySelector('.owvm-voice') && !b.done) {
                        b.done = true;
                        window.__probe.spans.push(Math.round(now - b.t));
                    }
                }
            });
            window.__probeMo.observe(box, { childList: true, subtree: true });
        };
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', boot, false);
        } else boot();
    });
    const page = await ctx.newPage();
    await page.goto(B + '/?page=login', { waitUntil: 'domcontentloaded' });
    await page.fill('.ow-auth-form[data-mode="login"] [name=identity]', EMAIL);
    await page.fill('.ow-auth-form[data-mode="login"] [name=password]', PW);
    await page.click('.ow-auth-form[data-mode="login"] button[type=submit]');
    await page.waitForSelector('#owInput', { timeout: 20000 });
    await page.goto(B + '/?room=' + ROOM, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(
        () => !!(window.OwChat && OwChat.cfg && OwChat.cfg.me && OwChat.cfg.me.id), { timeout: 25000 });
    await page.waitForTimeout(2000);

    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForSelector('.owvm-voice', { timeout: 20000 });
    await page.waitForTimeout(2500);

    const r = await page.evaluate(() => {
        if (window.__probeMo) window.__probeMo.disconnect();
        const s = window.__probe.spans.slice().sort((a, b) => a - b);
        return {
            n: s.length,
            median: s.length ? s[Math.floor(s.length / 2)] : -1,
            max: s.length ? s[s.length - 1] : -1,
            overOneFrame: s.filter(x => x > 16).length,
            over100ms: s.filter(x => x > 100).length,
        };
    });

    console.log('首屏语音条数        :', r.n);
    console.log('文件卡→气泡 中位耗时:', r.median + 'ms');
    console.log('最大耗时            :', r.max + 'ms');
    console.log('超过 1 帧(16ms) 的  :', r.overOneFrame, '条');
    console.log('超过 100ms 的       :', r.over100ms, '条');
    console.log('\n判读：');
    if (r.over100ms === 0) {
        console.log('  所有语音都在一帧内完成替换 —— 用户看见的是「直接就是气泡」，无闪烁。');
    } else {
        console.log('  有 ' + r.over100ms + ' 条超过 100ms，肉眼可能看到中间态。');
    }
    await browser.close();
})().catch(e => { console.error(e); process.exit(1); });