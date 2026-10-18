'use strict';
/**
 * 首屏「文件卡 → 语音气泡」两段渲染的时延测量
 *
 *   node .tools/voice-msg-flick.js
 *
 * 问题：刷新页面后，先看到附件形式的音频文件卡，语音气泡才替换上来。
 * 原因：核心 history 一次性返回整批消息并**同步**渲染成文件卡；语音气泡要等
 *  MutationObserver 回调 → 插件再发一轮 plugin_voice_msg_cards → 回包后才替换。
 *  核心没有「消息内容渲染」钩子，插件只能事后补救 → 必然闪一下。
 *
 * 这里量出这个窗口到底有多长（毫秒），给修法提供依据。
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

    // 记录两张请求的时间戳：history（渲染文件卡）与 plugin_voice_msg_cards（升级气泡）
    const net = [];
    page.on('request', r => {
        const u = r.url();
        if (/action=(history|plugin_voice_msg_cards)/.test(u)) {
            net.push({ t: Date.now(), action: (u.match(/action=([a-z_]+)/) || [])[1] });
        }
    });

    await page.reload({ waitUntil: 'domcontentloaded' });
    const t0 = Date.now();

    // 等第一个语音气泡出现
    await page.waitForSelector('.owvm-voice', { timeout: 20000 });
    const tBubble = Date.now();

    // 等历史消息全部渲染完（观察 addMessage 的批插）
    await page.waitForFunction(
        () => document.querySelectorAll('.ow-msg').length > 0, { timeout: 20000 });
    const tFiles = Date.now();

    const counts = await page.evaluate(() => ({
        msgs: document.querySelectorAll('.ow-msg').length,
        files: document.querySelectorAll('.ow-file-card').length,
        bubbles: document.querySelectorAll('.owvm-voice').length,
    }));

    console.log('首屏消息数        :', counts.msgs);
    console.log('残留文件卡        :', counts.files);
    console.log('语音气泡          :', counts.bubbles);
    console.log('从 reload 到气泡  :', (tBubble - t0) + 'ms');
    console.log('从 reload 到消息  :', (tFiles - t0) + 'ms');
    console.log('\n请求时序（相对 reload）:');
    net.forEach(n => console.log('  +' + (n.t - t0) + 'ms  ' + n.action));
    const hist = net.find(n => n.action === 'history');
    const cards = net.find(n => n.action === 'plugin_voice_msg_cards');
    if (hist && cards) {
        console.log('\n两段渲染的间隔（闪烁窗口）:', (cards.t - hist.t) + 'ms');
    }
    console.log('\n结论：核心先同步渲染文件卡，插件再发一轮 cards 请求回包后替换，');
    console.log('      这个间隔就是用户看到「附件形式」的那段时间。');

    await page.screenshot({ path: path.join(OUT, 'vm-flick-measure.png') });
    await browser.close();
})().catch(e => { console.error(e); process.exit(1); });