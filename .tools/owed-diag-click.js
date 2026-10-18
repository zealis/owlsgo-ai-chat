/**
 * 诊断：真实鼠标点击盾牌按钮，看到底有没有反应
 *
 *   node .tools/owed-diag-click.js
 *
 * 与 E2E 的区别：这里**不**预先关弹窗，完全模拟用户的操作顺序，
 * 并且用真实坐标点击（page.click 会滚动+命中测试，和真人一样）。
 */
'use strict';
const { chromium } = require('C:/Users/zeali/.workbuddy/skills/birdview/node_modules/playwright-core');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = 'https://localhost';
const WWW = 'D:/Program Files/phpstudy_pro/WWW/owlsgo';
const PHP = 'D:/Program Files/phpstudy_pro/Extensions/php/php8.2.9nts/php.exe';
const ROOT = 'D:/project/owlsgo';
const EMAIL = 'owed-a@test.local', PW = 'OwedTest123456';

(async () => {
    execFileSync(PHP, [path.join(ROOT, '.tools/owed-e2e-setup.php'), 'reset', WWW], { encoding: 'utf8' });
    const { a: A, b: BB } = JSON.parse(execFileSync(PHP, [path.join(ROOT, '.tools/owed-e2e-setup.php'), 'setup', WWW], { encoding: 'utf8' }).trim());

    const browser = await chromium.launch({ channel: 'chrome' });
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 960 }, ignoreHTTPSErrors: true });
    const page = await ctx.newPage();
    page.on('pageerror', e => console.log('  pageerror: ' + e.message));

    await page.goto(BASE + '/?page=login', { waitUntil: 'domcontentloaded' });
    await page.fill('.ow-auth-form[data-mode="login"] [name=identity]', EMAIL);
    await page.fill('.ow-auth-form[data-mode="login"] [name=password]', PW);
    await page.click('.ow-auth-form[data-mode="login"] button[type=submit]');
    await page.waitForFunction(() => !!(window.OwChat && OwChat.cfg), null, { timeout: 20000 });
    await page.evaluate(([id]) => { OwChat.openDm('user:' + id, '加密测试2'); }, [BB]);
    await page.waitForTimeout(1500);

    const snap = async (tag) => {
        const s = await page.evaluate(() => {
            const mask = document.getElementById('owModalMask');
            const btn = document.getElementById('owEDBtn');
            const toast = document.getElementById('owToast');
            return {
                modalOpen: !!mask && mask.style.display !== 'none',
                modalTitle: (document.getElementById('owModal') || {}).textContent
                    ? document.getElementById('owModal').textContent.replace(/\s+/g, ' ').slice(0, 40) : '',
                btnExists: !!btn,
                btnDisplay: btn ? getComputedStyle(btn).display : '-',
                btnBox: btn ? (function () { const r = btn.getBoundingClientRect(); return [Math.round(r.x), Math.round(r.y), Math.round(r.width), Math.round(r.height)]; })() : null,
                toast: toast && toast.style.display !== 'none' ? toast.textContent.slice(0, 60) : ''
            };
        });
        console.log('  [' + tag + '] ' + JSON.stringify(s));
        return s;
    };

    console.log('\n登录后 1.5s（不关任何弹窗，模拟真人）');
    await snap('t0');

    // 真实点击：先算坐标，再用鼠标点（和用户一模一样）
    const box = await page.evaluate(() => {
        const b = document.getElementById('owEDBtn');
        if (!b) return null;
        const r = b.getBoundingClientRect();
        return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
    });
    if (!box) { console.log('  盾牌按钮不在 DOM 里'); await browser.close(); return; }
    console.log('\n用真实鼠标点盾牌 (' + Math.round(box.x) + ',' + Math.round(box.y) + ')');
    await page.mouse.click(box.x, box.y);
    await page.waitForTimeout(2500);
    await snap('t1 点击后');

    // 再点一次（旧版是「点两次生效」）
    await page.mouse.click(box.x, box.y);
    await page.waitForTimeout(2500);
    await snap('t2 点两次后');

    await page.screenshot({ path: path.join(ROOT, '.tools/tmp/owed-diag-click.png') });
    await browser.close();
})().catch(e => { console.error('异常：', e); process.exit(2); });
