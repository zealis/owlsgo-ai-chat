/**
 * 诊断：真人操作顺序下点盾牌的完整链路（不预先关任何弹窗）
 *
 *   node .tools/owed-diag-click.js
 *
 * 站方报「点击没有任何提示」时的复现脚本。验证 v1.0.5 的行为：
 *   ① 登录后签到弹窗开着 → 盾牌应当**自己藏起来**（不再摆一个点了没反应的按钮）
 *   ② 弹窗关掉 → 盾牌自己回来
 *   ③ 这时点盾牌 → 出现「开启加密私聊」说明框
 *   ④ 点「开启加密」→ 对方未启用 → 给出「请让对方也点一次」的指引
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

let pass = 0, fail = 0;
function ok(label, cond, extra) {
    if (cond) { pass++; console.log('  [OK]   ' + label); }
    else { fail++; console.log('  [FAIL] ' + label + (extra !== undefined ? '  → ' + JSON.stringify(extra) : '')); }
}

(async () => {
    execFileSync(PHP, [path.join(ROOT, '.tools/owed-e2e-setup.php'), 'reset', WWW], { encoding: 'utf8' });
    const { a: A, b: BB } = JSON.parse(execFileSync(PHP, [path.join(ROOT, '.tools/owed-e2e-setup.php'), 'setup', WWW], { encoding: 'utf8' }).trim());

    const browser = await chromium.launch({ channel: 'chrome' });
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 960 }, ignoreHTTPSErrors: true });
    const page = await ctx.newPage();
    page.on('pageerror', e => console.log('    pageerror: ' + e.message));

    await page.goto(BASE + '/?page=login', { waitUntil: 'domcontentloaded' });
    await page.fill('.ow-auth-form[data-mode="login"] [name=identity]', EMAIL);
    await page.fill('.ow-auth-form[data-mode="login"] [name=password]', PW);
    await page.click('.ow-auth-form[data-mode="login"] button[type=submit]');
    await page.waitForFunction(() => !!(window.OwChat && OwChat.cfg), null, { timeout: 20000 });
    await page.evaluate(([id]) => { OwChat.openDm('user:' + id, '加密测试2'); }, [BB]);
    await page.waitForTimeout(2500);

    const snap = () => page.evaluate(() => {
        const mask = document.getElementById('owModalMask');
        const b = document.getElementById('owEDBtn');
        const m = document.getElementById('owModal');
        return {
            前端版本: (window.OWED && OWED.v) || '没加载',
            弹窗: mask && mask.style.display !== 'none'
                ? '开着：' + ((m && m.textContent) || '').replace(/\s+/g, ' ').trim().slice(0, 18)
                : '无',
            盾牌: b ? getComputedStyle(b).display : '不在DOM'
        };
    });

    const s0 = await snap();
    console.log('  ' + JSON.stringify(s0));
    ok('① 弹窗开着时，盾牌自己藏起来了', s0.弹窗 === '无' || s0.盾牌 === 'none', s0);

    await page.evaluate(() => { const b = document.querySelector('#owModal .ow-modal-close'); if (b) b.click(); });
    await page.waitForTimeout(1500);
    const s1 = await snap();
    console.log('  ' + JSON.stringify(s1));
    ok('② 弹窗关掉后盾牌自己回来了', s1.盾牌 !== 'none' && s1.盾牌 !== '不在DOM', s1);

    await page.click('#owEDBtn');
    await page.waitForTimeout(600);
    const s2 = await page.evaluate(() => {
        const mask = document.getElementById('owModalMask');
        const m = document.getElementById('owModal');
        return {
            弹窗: !!(mask && mask.style.display !== 'none'),
            标题: m ? m.textContent.replace(/\s+/g, ' ').slice(0, 40) : '',
            含换设备: m ? /换设备/.test(m.textContent) : false
        };
    });
    console.log('  ' + JSON.stringify(s2));
    ok('③ 点盾牌出现「开启加密私聊」说明框', s2.弹窗 && /开启加密私聊/.test(s2.标题), s2);
    ok('说明框写明换设备/清缓存会永久丢失', s2.含换设备, s2.标题);

    await page.evaluate(() => { OWED._onOpen(); });
    await page.waitForTimeout(3000);
    const toast = await page.evaluate(() => {
        const t = document.getElementById('owToast');
        return t && t.style.display !== 'none' ? t.textContent : '';
    });
    console.log('  toast: ' + toast);
    ok('④ 对方未启用时给出「请让对方也点一次」的指引', /请让对方也点一次/.test(toast), toast);

    await page.screenshot({ path: path.join(ROOT, '.tools/tmp/owed-diag-click.png') });
    await browser.close();
    console.log('\n结果：' + pass + ' 通过，' + fail + ' 失败');
    process.exit(fail ? 1 : 0);
})().catch(e => { console.error('异常：', e); process.exit(2); });
