/* ============================================================================
 * 右侧栏「所有成员」按需加载 —— 真浏览器验收（v1.5.29）
 *
 * 直接打运行副本（https://localhost），游客身份，**屏蔽 Service Worker**
 * （否则 SW 会接管请求，观察不到真实网络行为）。
 *
 * 钉住的行为：
 *   ① 首屏右侧栏默认收起 → 不发完整 room_members（只有 lite 闸门请求），列表不渲染；
 *   ② 点开面板 → 发一次完整 room_members，列表渲染出成员行、计数正确；
 *   ③ 关掉再打开 → 再拉一次（打开即刷新，不依赖轮询）；
 *   ④ 全程无 JS 报错。
 *
 * 用法：node .tools/members-lazy-check.js
 * 前置：运行副本已是本次改动后的代码（core/chat.php + index.php + index.js）。
 * ========================================================================== */
const path = require('path');
const { chromium } = require('C:/Users/zeali/.workbuddy/binaries/node/workspace/node_modules/playwright-core');

const BASE = process.env.OWL_BASE || 'https://localhost';
let pass = 0, fail = 0;
const ok = (m, extra) => { pass++; console.log('  [PASS] ' + m + (extra ? '  ' + extra : '')); };
const bad = (m, extra) => { fail++; console.log('  [FAIL] ' + m + (extra ? '  ' + extra : '')); };
const ck = (m, cond, extra) => (cond ? ok : bad)(m, extra);

(async () => {
  const browser = await chromium.launch({
    channel: 'chrome',
    args: ['--ignore-certificate-errors', '--no-proxy-server'],
  });
  const ctx = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    ignoreHTTPSErrors: true,
    serviceWorkers: 'block',
  });
  const page = await ctx.newPage();

  const errs = [];
  page.on('pageerror', (e) => errs.push(e.message));

  // 记录所有 room_members 请求（区分 lite 与完整）
  const memReqs = [];
  page.on('request', (r) => {
    const u = r.url();
    if (r.method() !== 'POST' || u.indexOf('action=room_members') < 0) return;
    const body = r.postData() || '';
    memReqs.push({ lite: /(^|&)lite=1(&|$)/.test(body), body: body.slice(0, 120) });
  });

  console.log('== ① 首屏（右侧栏默认收起） ==');
  // 不能用 networkidle：长轮询 / WS 一直在挂，页面永远不回 idle
  const resp = await page.goto(BASE + '/?room=1', { waitUntil: 'domcontentloaded', timeout: 30000 });
  ck('聊天页 HTTP 200', resp && resp.status() === 200, 'status=' + (resp && resp.status()));
  await page.waitForTimeout(2500);   // 给 room_join / lite 请求留时间

  const fullReqs = memReqs.filter((r) => !r.lite);
  ck('收起时不发完整 room_members', fullReqs.length === 0, '实际 ' + fullReqs.length + ' 次');
  // 首屏闸门靠 room_join 已经返回的 is_member 校准，连 lite 都不该发
  ck('首屏零 room_members 请求（闸门复用 room_join 结果）', memReqs.length === 0, '实际 ' + memReqs.length + ' 次');

  const s0 = await page.evaluate(() => {
    const box = document.getElementById('owOnlineList');
    const panel = document.getElementById('owOnline');
    return {
      cls: panel ? panel.className : '',
      items: box ? box.getElementsByTagName('li').length : -1,
      count: (document.getElementById('owOnlineCount') || {}).innerHTML,
      room: window.OwChat && window.OwChat.room,
      visible: window.OwChat && window.OwChat.membersVisible ? window.OwChat.membersVisible() : null,
    };
  });
  ck('面板收起（hidden 或非 open）', s0.visible === false, 'cls="' + s0.cls + '"');
  ck('列表未渲染（0 行）', s0.items === 0, 'li=' + s0.items);
  ck('已进入某个群聊', !!s0.room, 'room=' + s0.room);

  console.log('== ② 切群（面板仍收起） → 只发 lite 校准闸门 ==');
  const beforeSwitch = memReqs.length;
  await page.evaluate(() => { window.OwChat.switchRoom(1, window.OwChat.roomName, null, true); });
  await page.waitForTimeout(1200);
  const swReqs = memReqs.slice(beforeSwitch);
  ck('切群发出 lite 请求', swReqs.filter((r) => r.lite).length >= 1, 'lite=' + swReqs.filter((r) => r.lite).length);
  ck('切群不发完整名单', swReqs.filter((r) => !r.lite).length === 0, 'full=' + swReqs.filter((r) => !r.lite).length);
  ck('切群后列表仍未渲染', (await page.evaluate(() => document.getElementById('owOnlineList').getElementsByTagName('li').length)) === 0);

  console.log('== ③ 点开面板 → 加载成员 ==');
  await page.click('#owTogglePanel');
  await page.waitForTimeout(1200);
  const full2 = memReqs.filter((r) => !r.lite);
  ck('打开面板触发完整 room_members', full2.length >= 1, '实际 ' + full2.length + ' 次');
  const s1 = await page.evaluate(() => {
    const box = document.getElementById('owOnlineList');
    return {
      items: box ? box.getElementsByTagName('li').length : -1,
      count: (document.getElementById('owOnlineCount') || {}).innerHTML,
      visible: window.OwChat.membersVisible(),
      firstName: (box && box.querySelector('.ow-online-name')) ? box.querySelector('.ow-online-name').textContent : '',
    };
  });
  ck('面板已展开', s1.visible === true);
  ck('列表渲染出成员行（>0）', s1.items > 0, 'li=' + s1.items + ' 首个=' + s1.firstName);
  ck('计数与行数一致', String(s1.count) === String(s1.items), 'badge=' + s1.count + ' li=' + s1.items);

  console.log('== ④ 关掉再打开 → 再拉一次（打开即刷新） ==');
  const before = memReqs.filter((r) => !r.lite).length;
  // 宽屏没有 .ow-online-close（只有窄屏才渲染），收起走同一个顶栏按钮
  await page.click('#owTogglePanel');
  await page.waitForTimeout(400);
  ck('关闭后面板不可见', await page.evaluate(() => window.OwChat.membersVisible() === false));
  await page.click('#owTogglePanel');
  await page.waitForTimeout(1000);
  const after = memReqs.filter((r) => !r.lite).length;
  ck('重新打开又拉一次', after > before, before + ' → ' + after);
  ck('重新打开后列表仍有内容',
    (await page.evaluate(() => document.getElementById('owOnlineList').getElementsByTagName('li').length)) > 0);

  console.log('== ⑤ 运行时无报错 ==');
  ck('无未捕获 JS 错误', errs.length === 0, errs.slice(0, 2).join(' | '));
  ck('核心对象就绪', await page.evaluate(() => typeof window.OwChat === 'object' && typeof window.OwApi === 'object'));

  await browser.close();
  console.log('');
  console.log(fail === 0 ? `ALL PASS (${pass} 项)` : `${fail} 项失败，${pass} 项通过`);
  process.exit(fail === 0 ? 0 : 1);
})().catch((e) => { console.error('脚本异常:', e.message); process.exit(1); });
