/* ============================================================================
 * 整包 CSS 收敛验收（v1.5.31）
 *
 * 背景：此前 19 个插件各自在 page.head 里发自己的 CSS 切片，首屏 16 个独立 CSS
 * 请求（HTTP/1.1 同域 6 并发 → 分三批串行等 RTT），而整包本就包含它们的样式，
 * 等于同一批 CSS 下了两遍。现在核心 pageHead 统一引一次整包（带 `t=` 内容指纹，
 * 一年 immutable），插件不再发切片。
 *
 * 此脚本钉住三件事：
 *   ① 首屏没有 CSS 切片请求（0 个），只有 index.css + 整包两个样式表；
 *   ② 整包里确实包含各插件选择器，且浏览器已解析（不是「下发了但没生效」）；
 *   ③ 布局关键几何没变（侧栏 240、会话行有高度、工具栏在），并顺带看一眼登录页。
 *
 * 用法：node .tools/css-bundle-check.js
 * ========================================================================== */
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
    serviceWorkers: 'block',   // 屏蔽 SW，否则请求被它接管，数不清真实网络行为
  });
  const page = await ctx.newPage();
  const errs = [];
  page.on('pageerror', (e) => errs.push(e.message));

  const cssReqs = [];
  page.on('request', (r) => {
    const u = r.url();
    if (!/action=assets&type=css/.test(u)) return;
    cssReqs.push({ slice: /[?&]plugin=/.test(u), url: u.replace(BASE, '') });
  });

  console.log('== ① 首屏资源收敛 ==');
  await page.goto(BASE + '/', { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForTimeout(2500);

  ck('没有任何插件 CSS 切片请求', cssReqs.filter((r) => r.slice).length === 0,
    '切片 ' + cssReqs.filter((r) => r.slice).length + ' 个');
  ck('整包 CSS 请求恰好 1 次', cssReqs.filter((r) => !r.slice).length === 1,
    '整包 ' + cssReqs.filter((r) => !r.slice).length + ' 次');
  ck('整包 URL 带内容指纹 t=', cssReqs.some((r) => /[?&]t=/.test(r.url)),
    (cssReqs.find((r) => !r.slice) || {}).url || '');

  console.log('== ② 整包内含插件选择器（浏览器已解析） ==');
  const info = await page.evaluate(() => {
    const sels = [];
    let sheets = 0;
    for (let i = 0; i < document.styleSheets.length; i++) {
      const ss = document.styleSheets[i];
      sheets++;
      let rules = null;
      try { rules = ss.cssRules; } catch (e) { rules = null; }
      if (!rules) continue;
      for (let j = 0; j < rules.length; j++) {
        if (rules[j].selectorText) sels.push(rules[j].selectorText);
      }
    }
    return { sheets: sheets, joined: sels.join('\n'), total: sels.length };
  });
  ck('样式表数量 = 2（index.css + 整包）', info.sheets === 2, 'sheets=' + info.sheets);
  ck('解析出的规则数 > 300', info.total > 300, 'rules=' + info.total);

  // 各插件样式表里的独占选择器 —— 只有整包真的包含它们才会出现在这里
  const musts = ['.owvm-rec', '.owum-tabs', '.ow-gif-panel', '.lc-sec'];
  for (const sel of musts) {
    ck('整包含 ' + sel, info.joined.indexOf(sel) >= 0);
  }

  console.log('== ③ 布局关键几何没变 ==');
  const geo = await page.evaluate(() => {
    const r = (sel) => {
      const el = document.querySelector(sel);
      if (!el) return null;
      const b = el.getBoundingClientRect();
      return { w: Math.round(b.width), h: Math.round(b.height) };
    };
    const railEl = document.querySelector('.ow-online');
    return {
      railHidden: !!railEl && getComputedStyle(railEl).display === 'none',
      rail: r('.ow-online'),
      side: r('.ow-sidebar'),
      list: r('#owRoomList'),
      item: r('#owRoomList li'),
      toolbar: r('.ow-toolbar') || r('#owInput'),
      input: r('#owInput'),
    };
  });
  //⚠️ 右侧栏自 v1.5.29 起默认收起（display:none → 宽度 0），不能拿它当「样式生效」的证据
  ck('左侧栏宽度正常（核心样式表生效）', geo.side && geo.side.w > 100 && geo.side.w < 320, JSON.stringify(geo.side));
  ck('右侧栏默认收起（v1.5.29 口径）', geo.railHidden === true);
  ck('会话列表有高度（不是塌成 0）', geo.list && geo.list.h > 100, JSON.stringify(geo.list));
  ck('会话行有高度（插件样式没压坏行高）', geo.item && geo.item.h > 20, JSON.stringify(geo.item));
  ck('输入框可见', !!geo.input && geo.input.h > 0, JSON.stringify(geo.input));

  console.log('== ④ 登录页也走整包（不再各自切片） ==');
  const p2 = await ctx.newPage();
  const css2 = [];
  p2.on('request', (r) => {
    const u = r.url();
    if (/action=assets&type=css/.test(u)) css2.push({ slice: /[?&]plugin=/.test(u) });
  });
  const r2 = await p2.goto(BASE + '/?page=login', { waitUntil: 'domcontentloaded', timeout: 30000 });
  await p2.waitForTimeout(1200);
  ck('登录页 HTTP 200', r2 && r2.status() === 200, 'status=' + (r2 && r2.status()));
  ck('登录页零 CSS 切片', css2.filter((r) => r.slice).length === 0,
    '切片 ' + css2.filter((r) => r.slice).length + ' 个');
  ck('登录页引了整包 CSS', css2.filter((r) => !r.slice).length >= 1);

  console.log('== ⑤ 无 JS 报错 ==');
  ck('无未捕获错误', errs.length === 0, errs.slice(0, 2).join(' | '));

  await browser.close();
  console.log('');
  console.log(fail === 0 ? `ALL PASS (${pass} 项)` : `${fail} 项失败，${pass} 项通过`);
  process.exit(fail === 0 ? 0 : 1);
})().catch((e) => { console.error('脚本异常:', e.message); process.exit(1); });
