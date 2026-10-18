'use strict';
/**
 * refreshBotTags 在**真实站点**上的验证（v1.5.9 的核心修复）
 *
 *   node .tools/refresh-bots-live.js
 *
 * 在真实聊天页上注册一个机器人 uid，触发 markBots → refreshBotTags 整屏重画，
 * 检查：① 不抛异常；② 消息条数与 ID 不变；③ 机器人标签补上；④ 消息区非空时也能跑
 *   （真实 bug 的触发条件就是「登记机器人时消息区已有消息」）。
 */
const { chromium } = require('C:/Users/zeali/.workbuddy/skills/birdview/node_modules/playwright-core');
const path = require('path');
const B = 'https://localhost';
const OUT = path.join(__dirname, 'tmp');
const EMAIL = 'voice-test@test.local';
const PW = 'VoiceTest123456';
const ROOM = 730;

let pass = 0, fail = 0;
function ok(label, cond, extra) {
    if (cond) { pass++; console.log('  [OK]   ' + label); }
    else { fail++; console.log('  [FAIL] ' + label + (extra ? '  → ' + extra : '')); }
}

(async () => {
    const browser = await chromium.launch({ channel: 'chrome' });
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 960 }, ignoreHTTPSErrors: true });
    await ctx.addInitScript(() => {
        try { localStorage.setItem('ow_sh_pop_day', new Date().toISOString().slice(0, 10)); } catch (e) {}
    });
    const page = await ctx.newPage();
    const pageErrors = [];
    page.on('pageerror', e => pageErrors.push(e.message));

    await page.goto(B + '/?page=login', { waitUntil: 'domcontentloaded' });
    await page.fill('.ow-auth-form[data-mode="login"] [name=identity]', EMAIL);
    await page.fill('.ow-auth-form[data-mode="login"] [name=password]', PW);
    await page.click('.ow-auth-form[data-mode="login"] button[type=submit]');
    await page.waitForSelector('#owInput', { timeout: 20000 });
    await page.goto(B + '/?room=' + ROOM, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(
        () => !!(window.OwChat && OwChat.cfg && OwChat.cfg.me && OwChat.cfg.me.id), { timeout: 25000 });
    await page.waitForTimeout(2500);

    // 等消息区真的有内容 —— 这正是旧 bug 的触发前提
    await page.waitForSelector('.ow-msg', { timeout: 20000 });
    const before = await page.evaluate(() => ({
        msgs: document.querySelectorAll('.ow-msg').length,
        ids: Array.prototype.map.call(document.querySelectorAll('.ow-msg'), e => e.id).join(','),
        dividers: document.querySelectorAll('.ow-time-divider').length,
        bubbles: document.querySelectorAll('.owvm-voice').length,
    }));
    ok('消息区已有消息（旧 bug 的触发前提）', before.msgs > 0, JSON.stringify(before));

    // 挑一条真实消息的作者 uid 登记成机器人 → 触发 markBots → refreshBotTags
    const res = await page.evaluate(() => {
        const els = document.querySelectorAll('.ow-msg');
        let uid = 0;
        for (const el of els) {
            const id = parseInt(String(el.id).replace('owMsg', ''), 10);
            const m = OwChat.msgCache[id];
            if (m && m.uid) { uid = m.uid; break; }
        }
        if (!uid) return { skipped: 'no uid' };
        const beforeIds = Array.prototype.map.call(document.querySelectorAll('.ow-msg'), e => e.id).join(',');
        let threw = null;
        try { OwChat.markBots(uid); } catch (e) { threw = e.name + ': ' + e.message; }
        return {
            uid: uid, threw: threw,
            beforeIds: beforeIds,
            afterIds: Array.prototype.map.call(document.querySelectorAll('.ow-msg'), e => e.id).join(','),
            msgs: document.querySelectorAll('.ow-msg').length,
            dividers: document.querySelectorAll('.ow-time-divider').length,
            isBot: OwChat.isBot(uid),
            bubbles: document.querySelectorAll('.owvm-voice').length,
        };
    });

    if (res.skipped) { fail++; console.log('  [FAIL] 没能拿到可登记的 uid：' + res.skipped); }
    else {
        ok('markBots 不抛异常（修复前此处必炸 TypeError）', !res.threw, res.threw || '');
        ok('重画后消息条数不变', res.msgs === before.msgs, res.msgs + ' vs ' + before.msgs);
        ok('重画后消息 ID 顺序不变', res.afterIds === res.beforeIds, res.afterIds);
        ok('日期分隔条仍保留', res.dividers >= before.dividers, res.dividers + ' vs ' + before.dividers);
        ok('机器人登记生效', res.isBot === true);
    }

    // 插件增强（语音气泡）在整屏重画后应被 MutationObserver 自动恢复
    await page.waitForTimeout(1500);
    const after = await page.evaluate(() => ({
        bubbles: document.querySelectorAll('.owvm-voice').length,
        files: document.querySelectorAll('.ow-file-card').length,
    }));
    ok('重画后插件增强（语音气泡）自动恢复', after.bubbles >= before.bubbles,
        'bubbles ' + after.bubbles + ' vs ' + before.bubbles);

    ok('全程无未捕获异常', pageErrors.length === 0, pageErrors.slice(0, 2).join(' | '));

    await page.screenshot({ path: path.join(OUT, 'refresh-bots-live.png') });
    await browser.close();
    console.log('\n' + (fail === 0 ? `ALL PASS（${pass} 项）\n` : `${fail} FAILED / ${pass} passed\n`));
    process.exit(fail === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });