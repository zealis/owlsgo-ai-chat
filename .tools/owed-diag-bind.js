/**
 * 诊断：盾牌按钮的点击绑定在 SPA 重渲染后还在不在
 *
 *   node .tools/owed-diag-bind.js
 *
 * 怀疑点：chat.js 用 b.onclick 直接绑在按钮节点上，
 * 而 SPA 重渲染工具栏会把 #owToolFirst 整段替换 → 新节点没有 onclick，
 * 按钮「看起来正常」但点了毫无反应。
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
    page.on('pageerror', e => console.log('    pageerror: ' + e.message));

    await page.goto(BASE + '/?page=login', { waitUntil: 'domcontentloaded' });
    await page.fill('.ow-auth-form[data-mode="login"] [name=identity]', EMAIL);
    await page.fill('.ow-auth-form[data-mode="login"] [name=password]', PW);
    await page.click('.ow-auth-form[data-mode="login"] button[type=submit]');
    await page.waitForFunction(() => !!(window.OwChat && OwChat.cfg), null, { timeout: 20000 });
    await page.evaluate(([id]) => { OwChat.openDm('user:' + id, '加密测试2'); }, [BB]);
    await page.waitForTimeout(3000);
    // 关掉签到弹窗
    await page.evaluate(() => { const b = document.querySelector('#owModal .ow-modal-close'); if (b) b.click(); });
    await page.waitForTimeout(1500);

    const probe = async (tag) => {
        const s = await page.evaluate(() => {
            const b = document.getElementById('owEDBtn');
            const box = b ? b.getBoundingClientRect() : null;
            const mid = box && box.width
                ? document.elementFromPoint(box.x + box.width / 2, box.y + box.height / 2) : null;
            return {
                版本: (window.OWED && OWED.v) || '没加载',
                存在: !!b,
                显示: b ? getComputedStyle(b).display : '-',
                // 直接绑的 onclick 还在不在
                onclick还在: b ? !!b.onclick : false,
                // 真实命中点上最顶层是谁（有没有东西盖住按钮）
                命中点: mid ? (mid.id || mid.className || mid.tagName) : 'none',
                是按钮本身: mid ? !!mid.closest && !!mid.closest('#owEDBtn') : false
            };
        });
        console.log('  ' + tag + ' ' + JSON.stringify(s));
        return s;
    };

    await probe('刚打开私聊      ');

    // 模拟真实使用：等几个私聊轮询周期（poll 会重渲染消息区，可能连带重渲染工具栏）
    await page.waitForTimeout(9000);
    await probe('9 秒后（轮询过）');

    // 切走再切回（核心会重渲染工具栏吗？）
    await page.evaluate(() => { OwChat.switchRoom ? OwChat.switchRoom(0) : OwChat.closeRoom && OwChat.closeRoom(); });
    await page.waitForTimeout(2500);
    await page.evaluate(([id]) => { OwChat.openDm('user:' + id, '加密测试2'); }, [BB]);
    await page.waitForTimeout(3000);
    await probe('切走再切回      ');

    // 真实鼠标点一次，看有没有任何反应
    const box = await page.evaluate(() => {
        const b = document.getElementById('owEDBtn');
        if (!b) return null;
        const r = b.getBoundingClientRect();
        return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
    });
    if (box) {
        await page.mouse.click(box.x, box.y);
        await page.waitForTimeout(1200);
        const after = await page.evaluate(() => {
            const m = document.getElementById('owModalMask');
            const t = document.getElementById('owToast');
            return {
                弹窗: m && m.style.display !== 'none',
                toast: t && t.style.display !== 'none' ? t.textContent : ''
            };
        });
        console.log('  真实鼠标点击后 ' + JSON.stringify(after));
    } else {
        console.log('  没有按钮可点');
    }

    // 右键**对方**消息的头像 → 菜单里应出现「加密私聊设置」（第二条独立入口）
    // 自己的消息会被守卫跳过（不能对自己设置），所以让对方发一条：
    // 直接往库里插一条 user_id=对方的私聊，再刷新页面让它渲染出来
    execFileSync(PHP, ['-r',
        '$c=require "' + WWW.replace(/\\/g, '/') + '/core/config.php";' +
        'require "' + WWW.replace(/\\/g, '/') + '/core/db.php";DB::init($c);' +
        'DB::insert("messages",["room_id"=>0,"user_id"=>' + BB + ',"nickname"=>"加密测试2","role"=>"member",' +
        '"type"=>"text","content"=>"来自对方的消息","to_user_id"=>' + A + ',"to_nickname"=>"加密测试1",' +
        '"created_at"=>time()]);'], { encoding: 'utf8' });
    await page.reload({ waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => !!(window.OwChat && OwChat.cfg), null, { timeout: 20000 }).catch(() => {});
    await page.waitForTimeout(3000);
    await page.evaluate(([id]) => { OwChat.openDm('user:' + id, '加密测试2'); }, [BB]);
    await page.waitForTimeout(2500);

    const menu = await page.evaluate(() => {
        // 直接调核心的用户菜单构建（比伪造 DOM 事件更贴近真实调用）
        const nodes = document.querySelectorAll('#owMessages .ow-msg');
        let m = null;
        for (let i = 0; i < nodes.length; i++) {
            const id = parseInt(String(nodes[i].id || '').replace('owMsg', ''), 10);
            const row = id > 0 ? OwChat.msgCache[id] : null;
            if (row && !row.mine && row.uid) { m = row; break; }
        }
        if (!m) return { 找到对方消息: false };
        OwChat.showUserMenu(360, 320, m);
        return { 找到对方消息: true, uid: m.uid, mine: m.mine, 当前私聊: OwChat.dm && OwChat.dm.id };
    });
    await page.waitForTimeout(800);
    const has = await page.evaluate(() =>
        /加密私聊设置/.test(document.body.innerText) || /加密私聊设置/.test(document.body.innerHTML));
    console.log('  右键菜单 ' + JSON.stringify(Object.assign(menu, { 有加密私聊设置: has })));
    console.log(has ? '  [OK]   头像右键菜单里有第二条入口' : '  [FAIL] 右键菜单里没有入口');

    await browser.close();
})().catch(e => { console.error('异常：', e); process.exit(2); });
