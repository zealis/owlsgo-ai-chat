/**
 * 诊断（回归）：首屏状态接口失败时，功能必须自愈，而不是自我关闭
 *
 *   node .tools/owed-diag-bootfail.js
 *
 * 这是站方那次「点了没用」的真凶：首屏 plugin_e2ee_dm_state 被长轮询挤成 403，
 * 旧代码 .catch 直接 mode='off'，整个功能在浏览器里静默关闭。
 * 这里用 page.route 人为打断前两次状态请求，验证：按钮先藏起来、
 * 重试成功后自动恢复、且点击可用。
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
    const { b: BB } = JSON.parse(execFileSync(PHP, [path.join(ROOT, '.tools/owed-e2e-setup.php'), 'setup', WWW], { encoding: 'utf8' }).trim());

    const browser = await chromium.launch({ channel: 'chrome' });
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 960 }, ignoreHTTPSErrors: true });
    const page = await ctx.newPage();
    page.on('pageerror', e => console.log('    pageerror: ' + e.message));

    // 人为打断前两次状态请求，模拟「首屏 XHR 被长轮询挤掉」
    let blocked = 0;
    await ctx.addInitScript(() => { window.__owedBlocked = 0; });
    await ctx.route('**/index.php*', route => { /* 占位，下面用 URL 匹配 */ });
    await ctx.unroute('**/index.php*');
    await page.route(/action=plugin_e2ee_dm_state/, route => {
        blocked++;
        if (blocked <= 2) return route.abort('failed');   // 前两次：断
        route.continue();                                   // 之后：放行
    });

    await page.goto(BASE + '/?page=login', { waitUntil: 'domcontentloaded' });
    await page.fill('.ow-auth-form[data-mode="login"] [name=identity]', EMAIL);
    await page.fill('.ow-auth-form[data-mode="login"] [name=password]', PW);
    await page.click('.ow-auth-form[data-mode="login"] button[type=submit]');
    await page.waitForFunction(() => !!(window.OwChat && OwChat.cfg), null, { timeout: 20000 });
    await page.evaluate(([id]) => { OwChat.openDm('user:' + id, '加密测试2'); }, [BB]);
    await page.waitForTimeout(2500);   // 等登录后的每日签到弹窗自己冒出来
    await page.evaluate(() => { const b = document.querySelector('#owModal .ow-modal-close'); if (b) b.click(); });
    await page.waitForTimeout(1500);

    const st1 = await page.evaluate(() => ({
        已被拦次数: window.__owedBlocked || 0,
        mode: (window.OWED && OWED.getMode) ? OWED.getMode() : '?',
        btn: (function () { const b = document.getElementById('owEDBtn'); return b ? getComputedStyle(b).display : '不在DOM'; })(),
        dm: (window.OwChat && OwChat.dm && OwChat.dm.id) || null,
        mask: (function(){const m=document.getElementById('owModalMask');return m&&m.style.display!=='none';})()
    }));
    console.log('  已拦请求数（本脚本 Node 侧）：' + blocked);
    console.log('  刚进私聊（状态还没拿到）：' + JSON.stringify(st1));
    // 关键不变量：按钮可见 ⇔ 功能已就绪。旧代码在状态请求失败时 mode 被写成 off，
    // 按钮却还摆在那儿 —— 那就是一个点了没反应的死按钮。
    ok('① 不存在「按钮可见但功能已自我关闭」的死按钮状态', st1.btn === 'none' || st1.mode === 'optin', st1);

    // 等重试生效
    let recovered = false;
    for (let i = 0; i < 12 && !recovered; i++) {
        await page.waitForTimeout(1000);
        recovered = await page.evaluate(() => {
            const b = document.getElementById('owEDBtn');
            return !!b && getComputedStyle(b).display !== 'none';
        });
    }
    const st2 = await page.evaluate(() => ({
        btn: (function () { const b = document.getElementById('owEDBtn'); return b ? getComputedStyle(b).display : '不在DOM'; })(),
        mode: (window.OWED && OWED.getMode) ? OWED.getMode() : '?'
    }));
    console.log('  重试后：' + JSON.stringify(st2));
    ok('② 重试成功后功能自愈，盾牌自己回来', recovered && st2.mode === 'optin', st2);

    // 点一下，确认可用
    await page.click('#owEDBtn');
    await page.waitForTimeout(600);
    const modal = await page.evaluate(() => {
        const mask = document.getElementById('owModalMask');
        const m = document.getElementById('owModal');
        return { open: !!(mask && mask.style.display !== 'none'), text: m ? m.textContent.replace(/\s+/g, ' ').slice(0, 20) : '' };
    });
    ok('③ 点击弹出「开启加密私聊」说明框', modal.open && /开启加密私聊/.test(modal.text), modal);

    await browser.close();
    console.log('\n结果：' + pass + ' 通过，' + fail + ' 失败');
    process.exit(fail ? 1 : 0);
})().catch(e => { console.error('异常：', e); process.exit(2); });
