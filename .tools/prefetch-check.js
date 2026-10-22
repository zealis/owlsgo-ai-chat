/**
 * 内部链接预加载 + 资源缓存 浏览器验证（v1.5.26）
 * 用法：node .tools/prefetch-check.js
 *
 * 钉的四件事：
 *   ① index.js 里新增的预加载模块不抛错、不改动可见 DOM（只往 <head> 加 <link>）；
 *   ② 悬停内部链接 65ms 后真的产生 <link rel=prefetch>，同一 URL 只出现一次；
 *   ③ ?action= / data-no-instant / 当前页 三类链接被正确跳过；
 *   ④ ?action=assets 的整包第二次加载走浏览器缓存（transferSize=0）。
 */
const { chromium } = require('C:/Users/zeali/.workbuddy/binaries/node/workspace/node_modules/playwright-core');
const B = 'https://localhost';
const out = [];
const P = (ok, n, e) => out.push((ok ? 'PASS' : 'FAIL') + ' | ' + n + (e ? ' | ' + e : ''));

(async () => {
  const browser = await chromium.launch({ channel: 'chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));

  // ---------- ① 插件前台页：脚本正常、无报错 ----------
  await page.goto(B + '/?page=login', { waitUntil: 'networkidle', timeout: 30000 });
  P(errors.length === 0, '页面无 JS 报错', errors.join(' / '));
  P(await page.locator('a[href="?page=chat"]').count() === 1, '存在「返回聊天」内部链接');

  const bodyBefore = await page.evaluate(() => document.body.children.length);

  // ---------- ② 悬停 → 预取 ----------
  // 先把鼠标挪到空白处再移入链接：Chrome 只在「进入元素」时派发 mouseover，
  // 若鼠标初始位置恰好已落在链接上，hover() 的位移不会产生新事件。
  await page.mouse.move(600, 600);
  await page.waitForTimeout(100);
  await page.hover('a[href="?page=chat"]');
  await page.waitForTimeout(500);
  const pf = await page.evaluate(() =>
    Array.from(document.querySelectorAll('link[rel=prefetch]')).map((l) => l.getAttribute('href')));
  P(pf.some((h) => /[?&]page=chat/.test(h || '')), '悬停后预取了 ?page=chat', JSON.stringify(pf));

  // 再悬停一次：同一 URL 不应重复
  await page.mouse.move(600, 600);
  await page.waitForTimeout(100);
  await page.hover('a[href="?page=chat"]');
  await page.waitForTimeout(300);
  const pf2 = await page.evaluate(() =>
    Array.from(document.querySelectorAll('link[rel=prefetch]')).map((l) => l.getAttribute('href')));
  const dup = pf2.filter((h) => /[?&]page=chat/.test(h || '')).length;
  P(dup === 1, '同一 URL 只预取一次', 'count=' + dup);

  // ---------- ③ 跳过规则 ----------
  await page.evaluate(() => {
    const mk = (href, attrs) => {
      const a = document.createElement('a');
      a.href = href;
      a.textContent = 'x';
      a.id = 'inject-' + Math.random().toString(36).slice(2);
      if (attrs) Object.keys(attrs).forEach((k) => a.setAttribute(k, attrs[k]));
      document.body.appendChild(a);
      return a.id;
    };
    window.__ids = [
      mk('?action=ticket'),                                 // 接口端点
      mk('?page=login', { 'data-no-instant': '' }),         // 主动退出预取
      mk(location.pathname + location.search),              // 当前页
    ];
  });
  for (const id of await page.evaluate(() => window.__ids)) {
    await page.hover('#' + id);
    await page.waitForTimeout(250);
  }
  const pf3 = await page.evaluate(() =>
    Array.from(document.querySelectorAll('link[rel=prefetch]')).map((l) => l.getAttribute('href')));
  P(!pf3.some((h) => /[?&]action=/.test(h || '')), '?action= 链接不预取', JSON.stringify(pf3));
  P(!pf3.some((h) => /[?&]page=login/.test(h || '')), 'data-no-instant 链接不预取', JSON.stringify(pf3));

  // 只往 head 加节点，不往 body 加（保证视觉/结构不变）
  const bodyAfter = await page.evaluate(() => document.body.children.length);
  P(bodyAfter === bodyBefore + 3, '预取只写 head（body 只多出脚本注入的 3 个测试链接）', bodyBefore + '→' + bodyAfter);

  // ---------- ④ 整包第二次加载走缓存（全新上下文，避免前面页面已缓存） ----------
  const ctx2 = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
  const p2 = await ctx2.newPage();
  await p2.goto(B + '/', { waitUntil: 'networkidle', timeout: 30000 });
  const first = await p2.evaluate(() => {
    const e = performance.getEntriesByType('resource').find((r) => /action=assets&type=js/.test(r.name));
    return e ? e.transferSize : -1;
  });
  await p2.goto(B + '/', { waitUntil: 'networkidle', timeout: 30000 });
  const second = await p2.evaluate(() => {
    const e = performance.getEntriesByType('resource').find((r) => /action=assets&type=js/.test(r.name));
    return e ? e.transferSize : -1;
  });
  P(first > 0, '首次加载整包有传输', 'transferSize=' + first);
  P(second === 0, '第二次加载整包走缓存（transferSize=0）', 'transferSize=' + second);
  await ctx2.close();

  await browser.close();
  console.log(out.join('\n'));
  const bad = out.filter((l) => l.startsWith('FAIL')).length;
  console.log(bad === 0 ? 'ALL PASS' : bad + ' FAILED');
  process.exit(bad === 0 ? 0 : 1);
})().catch((e) => { console.error('SCRIPT ERROR:', e && e.message); process.exit(2); });
