/**
 * 加密私聊 E2E：两个真实账号、两个浏览器上下文同时在线走完整链路
 *
 *   node .tools/owed-e2e.js
 *
 * 覆盖：
 *   ① 登录后盾牌按钮出现在私聊工具栏（群聊里隐藏）
 *   ② 点盾牌 → 弹「开启加密私聊」说明框（换设备不可恢复写在前三行）
 *   ③ 对方还没登记设备时：本设备密钥照常登记，并提示「请让对方也点一次」
 *   ④ 双方都开启后：服务端各登记一台设备 + 会话开关 + 消耗一次性预密钥
 *   ⑤ 发消息 → 库里是 owee1: 信封，不是明文；自己屏幕上看到明文
 *   ⑥ 对端屏幕上密文被解回明文（含历史消息）
 *   ⑦ 反向回复同样解得开
 *   ⑧ 刷新页面后历史仍可读；关闭加密后回到明文
 *
 * 账号由 .tools/owed-e2e-setup.php 建（互为好友），跑完由 clean 清掉。
 */
'use strict';
const { chromium } = require('C:/Users/zeali/.workbuddy/skills/birdview/node_modules/playwright-core');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const BASE = 'https://localhost';
const WWW = 'D:/Program Files/phpstudy_pro/WWW/owlsgo';
const PHP = 'D:/Program Files/phpstudy_pro/Extensions/php/php8.2.9nts/php.exe';
const ROOT = 'D:/project/owlsgo';
const OUT = path.join(ROOT, '.tools/tmp');
const EMAIL_A = 'owed-a@test.local', EMAIL_B = 'owed-b@test.local', PW = 'OwedTest123456';
if (!fs.existsSync(OUT)) fs.mkdirSync(OUT, { recursive: true });

let pass = 0, fail = 0;
function ok(label, cond, extra) {
    if (cond) { pass++; console.log('  [OK]   ' + label); }
    else { fail++; console.log('  [FAIL] ' + label + (extra !== undefined ? '  → ' + JSON.stringify(extra) : '')); }
}
let UIDS = [];
function db() {
    return JSON.parse(execFileSync(PHP, [path.join(ROOT, '.tools/owed-db.php'), WWW].concat(UIDS), { encoding: 'utf8' }));
}
function ids() {
    return JSON.parse(execFileSync(PHP, [path.join(ROOT, '.tools/owed-e2e-setup.php'), 'setup', WWW], { encoding: 'utf8' }).trim());
}
/** 每轮开跑前把测试号的插件状态清空：否则上一轮的会话开关/预密钥消耗会污染断言 */
function reset() {
    execFileSync(PHP, [path.join(ROOT, '.tools/owed-e2e-setup.php'), 'reset', WWW], { encoding: 'utf8' });
}
/** 轮询等某段明文出现在屏幕上（对方要等私聊轮询把消息带过来，不是即时的） */
async function waitPlain(page, text, ms) {
    ms = ms || 20000;
    const t0 = Date.now();
    while (Date.now() - t0 < ms) {
        const hit = await page.evaluate((txt) => {
            const n = document.querySelectorAll('#owMessages .ow-msg.private .ow-msg-content');
            for (let i = 0; i < n.length; i++) if (n[i].textContent === txt) return true;
            return false;
        }, text);
        if (hit) return true;
        await page.waitForTimeout(500);
    }
    return false;
}

(async () => {
    reset();
    const { a: A, b: BB } = ids();
    UIDS = [A, BB];
    console.log('测试账号：A=' + A + '  B=' + BB);

    const browser = await chromium.launch({ channel: 'chrome' });
    const mk = async () => {
        const ctx = await browser.newContext({ viewport: { width: 1440, height: 960 }, ignoreHTTPSErrors: true });
        const page = await ctx.newPage();
        page.on('pageerror', e => console.log('    pageerror: ' + e.message));
        page.on('console', m => { if (m.type() === 'error' && !/WebSocket connection to/.test(m.text())) console.log('    console: ' + m.text()); });
        return page;
    };
    const pA = await mk(), pB = await mk();

    async function login(page, email) {
        await page.goto(BASE + '/?page=login', { waitUntil: 'domcontentloaded' });
        await page.fill('.ow-auth-form[data-mode="login"] [name=identity]', email);
        await page.fill('.ow-auth-form[data-mode="login"] [name=password]', PW);
        await page.click('.ow-auth-form[data-mode="login"] button[type=submit]');
        await page.waitForFunction(() => !!(window.OwChat && OwChat.cfg), null, { timeout: 20000 });
    }
    async function libReady(page) {
        try {
            await page.waitForFunction(() => !!(window.OWEE && window.OWED), null, { timeout: 25000 });
            return true;
        } catch (e) {
            console.log('    库里没加载：OWEE=' + await page.evaluate(() => typeof window.OWEE) +
                ' OWED=' + await page.evaluate(() => typeof window.OWED) +
                ' OwChat=' + await page.evaluate(() => typeof window.OwChat));
            return false;
        }
    }
    async function openDm(page, peerId, name) {
        await page.evaluate(([id, nm]) => { OwChat.openDm('user:' + id, nm); }, [peerId, name]);
        await page.waitForTimeout(1200);
    }
    async function closeAnyModal(page) {
        await page.evaluate(() => {
            const m = document.getElementById('owModalMask');
            if (m && m.style.display !== 'none') OwChat.closeModal();
        });
        await page.waitForTimeout(200);
    }
    async function clickShield(page) {
        await closeAnyModal(page);
        await page.click('#owEDTag');
        await page.waitForTimeout(400);
        return page.evaluate(() => {
            const m = document.getElementById('owModal');
            const mask = document.getElementById('owModalMask');
            if (!m || !mask || mask.style.display === 'none') return null;
            return { text: m.textContent.replace(/\s+/g, ' '), hasOpen: /开启加密/.test(m.textContent) };
        });
    }
    /** 真点弹窗里的确认按钮。
     *  ⚠ 必须真点：v1.1.2 之前 E2E 全程用 evaluate 调 OWED._onOpen()，
     *    于是「onclick=\"OWED._onOpen\" 少了括号、按���点了没反应」这个 bug
     *    在测试里永远看不见。以后一律真点。 */
    async function clickModal(page, danger) {
        await page.click(danger ? '#owModal .ow-btn-danger' : '#owModal .ow-btn-primary');
    }
    /** 页面上任何位置都不该出现密文字面量（尤其「发送中」气泡） */
    async function noCipherVisible(page) {
        return page.evaluate(() => {
            const n = document.querySelectorAll('#owMessages .ow-msg-content, #owMessages .ow-msg-plain');
            for (let i = 0; i < n.length; i++) if (/^owee1:/.test((n[i].textContent || '').trim())) return true;
            return false;
        });
    }
    async function sendText(page, text, label) {
        await page.fill('#owInput', text);
        await page.press('#owInput', 'Enter');
        // 立刻查一次：「发送中」气泡在 WSS 通路上可能挂好几秒，那里绝不能露出密文
        await page.waitForTimeout(350);
        if (label) ok(label, !(await noCipherVisible(page)));
        await page.waitForTimeout(2200);
    }
    const hasPlain = (page, t) => page.evaluate((txt) => {
        const n = document.querySelectorAll('#owMessages .ow-msg.private .ow-msg-content');
        for (let i = 0; i < n.length; i++) if (n[i].textContent === txt) return true;
        return false;
    }, t);

    /* ---------- ① 双方登录 ---------- */
    console.log('\n1) 双方登录 + 盾牌按钮');
    await login(pA, EMAIL_A);
    await login(pB, EMAIL_B);
    ok('A 侧协议核心与前端接线加载', await libReady(pA));
    ok('B 侧协议核心与前端接线加载', await libReady(pB));
    await openDm(pA, BB, '加密测试2');
    await openDm(pB, A, '加密测试1');
    // 弹窗开着时盾牌是故意藏起来的（v1.0.5），所以先关弹窗、等巡检把按钮放回来再断言
    await closeAnyModal(pA);
    await pA.waitForTimeout(1400);
    const gA = await pA.evaluate(() => {
        const b = document.getElementById('owEDTag');
        return b ? getComputedStyle(b).display : 'absent';
    });
    ok('关掉弹窗后私聊里盾牌可见', gA !== 'none' && gA !== 'absent', gA);
    await pA.screenshot({ path: path.join(OUT, 'owed-1-dm.png') });

    /* ---------- ② A 先点：对方还没启用 ---------- */
    console.log('\n2) A 先点盾牌（对方未启用）');
    const mA = await clickShield(pA);
    ok('弹出「开启加密私聊」说明框', !!mA && mA.hasOpen, mA && mA.text.slice(0, 60));
    ok('弹窗写明换设备/清缓存会永久丢失', !!mA && /换设备/.test(mA.text) && /(无法恢复|永久)/.test(mA.text));
    await clickModal(pA);
    await pA.waitForTimeout(2500);
    let d1 = db();
    ok('A 的设备已登记（密钥留在本机）', d1.devices.some(x => x.user_id === A), d1.devices);
    const convOf = (db2) => db2.conv.filter(c => c.conv === 'u' + A + ':u' + BB);
    ok('会话尚未开启（对方没设备）', convOf(d1).length === 0, convOf(d1));
    ok('没有消耗一次性预密钥', d1.opk_used === 0, d1.opk_used);

    /* ---------- ③ B 点：成功 ---------- */
    console.log('\n3) B 点盾牌 → 成功');
    const mB = await clickShield(pB);
    ok('B 侧同样弹出说明框', !!mB && mB.hasOpen);
    await clickModal(pB);
    await pB.waitForTimeout(3000);
    let d2 = db();
    ok('B 的设备已登记', d2.devices.some(x => x.user_id === BB), d2.devices);
    ok('会话开关已置 1', convOf(d2).some(c => c.enabled === 1), convOf(d2));
    ok('消耗了一枚一次性预密钥', d2.opk_used >= 1, { used: d2.opk_used, left: d2.opk_left });
    await pB.screenshot({ path: path.join(OUT, 'owed-2-b-enabled.png') });

    /* ---------- ④ B 发消息 → 密文 ---------- */
    console.log('\n4) B 发消息');
    const MSG_B = '来自 B 的加密私聊 ' + Date.now();
    await sendText(pB, MSG_B, '「发送中」气泡不露出密文');
    let d3 = db();
    const fromB = d3.dms.filter(m => m.user_id === BB);
    ok('库里 B 的消息是密文信封', fromB.length > 0 && fromB[fromB.length - 1].enc === 1, fromB[fromB.length - 1]);
    ok('B 自己屏幕上看到明文', await waitPlain(pB, MSG_B));
    await pB.screenshot({ path: path.join(OUT, 'owed-3-b-sent.png') });

    /* ---------- ⑤ A 再点：成功并解历史 ---------- */
    console.log('\n5) A 再点盾牌 → 成功 + 解开 B 的历史');
    await clickShield(pA);
    await clickModal(pA);
    await pA.waitForTimeout(3000);
    let d4 = db();
    ok('A 也消耗了一枚预密钥（双向各有会话）', d4.opk_used >= 2, { used: d4.opk_used });
    ok('A 屏幕上 B 的密文被解回明文', await waitPlain(pA, MSG_B));
    const badA = await pA.evaluate(() => document.querySelectorAll('#owMessages .ow-msg.owed-bad').length);
    ok('A 侧没有「无法解密」的气泡', badA === 0, { bad: badA });

    /* ---------- ⑥ A 发消息 ---------- */
    console.log('\n6) A 发消息');
    const MSG_A = '来自 A 的加密私聊 ' + Date.now();
    await sendText(pA, MSG_A, '「发送中」气泡不露出密文（A 侧）');
    let d5 = db();
    const fromA = d5.dms.filter(m => m.user_id === A);
    ok('库里 A 的消息是密文信封', fromA.length > 0 && fromA[fromA.length - 1].enc === 1, fromA[fromA.length - 1]);
    ok('A 自己屏幕上看到明文', await waitPlain(pA, MSG_A));
    await pA.screenshot({ path: path.join(OUT, 'owed-4-a-sent.png') });

    /* ---------- ⑦ B 实时解开 A 的消息并回复 ---------- */
    console.log('\n7) B 侧解开 + 回复');
    await pB.waitForTimeout(2500);   // 等私聊轮询把新消息带过来
    ok('B 屏幕上 A 的新消息解开了', await waitPlain(pB, MSG_A));
    const badB = await pB.evaluate(() => document.querySelectorAll('#owMessages .ow-msg.owed-bad').length);
    ok('B 侧没有「无法解密」的气泡', badB === 0, { bad: badB });
    const REPLY = 'B 的回复 ' + Date.now();
    await sendText(pB, REPLY);
    await pA.waitForTimeout(2500);
    ok('A 屏幕上 B 的回复也解开了', await waitPlain(pA, REPLY));
    await pB.screenshot({ path: path.join(OUT, 'owed-5-b-decrypt.png') });
    await pA.screenshot({ path: path.join(OUT, 'owed-6-a-decrypt.png') });

    /* ---------- ⑧ 刷新页面后历史仍可读 ---------- */
    console.log('\n8) 刷新后历史仍可解密');
    // 注意：核心在遇到 403 后会 800ms 自己 location.reload()（签名自愈），
    // 手动 reload 若与它撞车会打断导航，出现「包下载了却没执行」的半加载状态。
    // 所以这里最多重试两次 reload，等插件全局重新就位。
    for (let attempt = 1; attempt <= 3; attempt++) {
        await pA.reload({ waitUntil: 'domcontentloaded' });
        const up = await pA.waitForFunction(() => !!(window.OWEE && window.OWED), null, { timeout: 15000 })
            .then(() => true).catch(() => false);
        if (up) break;
        console.log('     第 ' + attempt + ' 次刷新后插件未就位，重试');
        await pA.waitForTimeout(2000);
    }
    await openDm(pA, BB, '加密测试2');
    await pA.waitForTimeout(3000);
    ok('刷新后 A 仍能解开历史', (await hasPlain(pA, MSG_B)) && (await waitPlain(pA, MSG_A)));
    await pA.screenshot({ path: path.join(OUT, 'owed-7-reload.png') });

    /* ---------- ⑨ 关闭加密 ---------- */
    console.log('\n9) 关闭加密');
    await clickShield(pA);
    let off = false;
    for (let i = 0; i < 4 && !off; i++) {
        await clickModal(pA, true);
        await pA.waitForTimeout(2500);
        off = convOf(db()).every(c => c.enabled === 0);
    }
    ok('会话开关已置 0', off, convOf(db()));
    // 关不掉时，后面两条断言没有意义（消息仍会加密），标成跳过而不是失败：
    // 这是站点在小 FPM 池下被长轮询占满、接口 403 造成的测试环境抖动，不是功能回归
    if (!off) {
        console.log('     [跳过] 关闭失败（接口 403），后续两条断言不成立');
    } else {
        const PLAIN_AFTER = '关闭后的明文 ' + Date.now();
        await sendText(pA, PLAIN_AFTER);
        const d7 = db();
        const last = d7.dms[d7.dms.length - 1];
        ok('关闭后新消息是明文入库', !!last && last.enc === 0, last);
        ok('关闭后对端屏幕直接显示明文', await waitPlain(pB, PLAIN_AFTER));
    }
    await pA.screenshot({ path: path.join(OUT, 'owed-8-off.png') });

    await browser.close();
    console.log('\n结果：' + pass + ' 通过，' + fail + ' 失败');
    console.log('截图目录：' + OUT);
    process.exit(fail > 0 ? 1 : 0);
})().catch(e => { console.error('E2E 异常：', e); process.exit(2); });
