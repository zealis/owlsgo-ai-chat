/**
 * 后台设备列表 E2E（v1.4.0）：搜索 + 多选批量 + 分页
 *
 *   node .tools/owed-admin-e2e.js
 *
 * 建一个临时管理员，登录后台打开「端到端加密私聊」页，验证：
 *   ① 列表渲染（列头含多选框、搜索框、批量条）
 *   ② 搜索命中 / 未命中
 *   ③ 勾选 + 批量注销（带自研确认弹窗）后行数减少、数据库同步
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
const EMAIL = 'owed-adm@test.local', PW = 'OwedAdm123456';

let pass = 0, fail = 0;
function ok(label, cond, extra) {
    if (cond) { pass++; console.log('  [OK]   ' + label); }
    else { fail++; console.log('  [FAIL] ' + label + (extra !== undefined ? '  → ' + JSON.stringify(extra) : '')); }
}


(async () => {
    const WWW_ = WWW.replace(/\\/g, '/');
    const dbCmd = (m) => execFileSync(PHP, [path.join(ROOT, '.tools/owed-admin-db.php'), m, WWW], { encoding: 'utf8' }).trim();
    dbCmd('seed');
    const devCount = () => parseInt(dbCmd('count'), 10);
    console.log('测试设备数：' + devCount());
    const browser = await chromium.launch({ channel: 'chrome' });
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
    const page = await ctx.newPage();
    page.on('pageerror', e => console.log('    pageerror: ' + e.message));

    await page.goto(BASE + '/?page=login', { waitUntil: 'domcontentloaded' });
    await page.fill('.ow-auth-form[data-mode="login"] [name=identity]', EMAIL);
    await page.fill('.ow-auth-form[data-mode="login"] [name=password]', PW);
    await page.click('.ow-auth-form[data-mode="login"] button[type=submit]');
    await page.waitForFunction(() => !!(window.OwChat && OwChat.cfg), null, { timeout: 20000 });

    // 2) 进后台 → 插件子菜单
    await page.goto(BASE + '/?page=admin', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('[data-apage="plugin:e2ee-dm"]', { timeout: 25000 }).catch(() => {});
    await page.waitForTimeout(800);
    const clicked = await page.evaluate(() => {
        const li = document.querySelector('[data-apage="plugin:e2ee-dm"]');
        if (!li) return false;
        li.click();
        return true;
    });
    ok('后台侧栏有「端到端加密私聊」入口', clicked);
    await page.waitForSelector('#owEDTable', { timeout: 25000 }).catch(() => {});
    await page.waitForTimeout(1200);

    const has = async () => page.evaluate(() => ({
        搜索框: !!document.getElementById('owEDQ'),
        表体: !!document.getElementById('owEDTable'),
        全选框: !!document.getElementById('owEDChkAll'),
        批量条: !!document.getElementById('owEDBatch'),
        行勾选框: document.getElementsByClassName('owEDChk').length,
        列表头: /登记的设备/.test(document.body.innerText)
    }));
    const h1 = await has();
    ok('① 列表骨架齐：搜索框 / 表体 / 全选框 / 批量条', h1.搜索框 && h1.表体 && h1.全选框 && h1.批量条, h1);
    ok('① 列表标题为「登记的设备」', h1.列表头, h1);
    let rows = 0;
    for (let i = 0; i < 20 && rows < 3; i++) {
        await page.waitForTimeout(500);
        rows = await page.evaluate(() => document.getElementsByClassName('owEDChk').length);
    }
    ok('① 首屏渲染出数据行（每行带勾选框）', rows >= 3, rows);

    // 3) 搜索
    await page.fill('#owEDQ', 'beta');
    await page.press('#owEDQ', 'Enter');
    await page.waitForTimeout(1800);
    const h2 = await page.evaluate(() => ({
        行数: document.getElementsByClassName('owEDChk').length,
        文本: document.getElementById('owEDTable').innerText
    }));
    ok('② 搜索「beta」只命中 1 行', h2.行数 === 1, h2);
    await page.fill('#owEDQ', '不存在的设备名');
    await page.press('#owEDQ', 'Enter');
    await page.waitForTimeout(1500);
    const h3 = await page.evaluate(() => ({
        行数: document.getElementsByClassName('owEDChk').length,
        空态: /没有匹配/.test(document.getElementById('owEDTable').innerText)
    }));
    ok('② 搜索无结果时给空态文案', h3.行数 === 0 && h3.空态, h3);

    // 4) 批量注销
    await page.fill('#owEDQ', '');
    await page.click('#owEDReset');
    await page.waitForTimeout(1800);
    const before = parseInt(devCount(), 10);
    await page.evaluate(() => {
        const list = document.getElementsByClassName('owEDChk');
        for (let i = 0; i < list.length; i++) if (String(list[i].value).length) { list[i].checked = true; break; }
        if (window.OwEDAdmin) OwEDAdmin.sync();
    });
    await page.waitForTimeout(400);
    await page.selectOption('#owEDBatch_sel', 'delete');
    await page.waitForTimeout(200);
    await page.click('#owEDBatch_go');
    await page.waitForTimeout(500);
    // 自研确认弹窗（禁用浏览器原生 dialog）
    const confirmShown = await page.evaluate(() => {
        const m = document.querySelector('.ow-modal-mask');
        return !!(m && m.style.display !== 'none' && /注销/.test(m.textContent));
    });
    ok('③ 点执行弹出自研确认框（含后果说明）', confirmShown);
    await page.evaluate(() => {
        const m = document.querySelector('.ow-modal-mask');
        if (!m) return;
        const bs = m.querySelectorAll('.ow-modal-actions .ow-btn');
        if (bs.length) bs[bs.length - 1].click();   // 「确定」
    });
    await page.waitForTimeout(2500);
    const after = parseInt(devCount(), 10);
    ok('③ 批量注销生效（数据库少一行）', after === before - 1, { before: before, after: after });
    await page.screenshot({ path: path.join(ROOT, '.tools/tmp/owed-admin-list.png') });

    dbCmd('clean');
    await browser.close();
    console.log('\n结果：' + pass + ' 通过，' + fail + ' 失败');
    process.exit(fail ? 1 : 0);
})().catch(e => { console.error('异常：', e.message); process.exit(2); });
