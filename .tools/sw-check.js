/**
 * Service Worker 验收（v1.5.28）
 * 用法：node .tools/sw-check.js
 *
 * 钉六件事：
 *   ① SW 能注册并接管页面（reload 后 navigator.serviceWorker.controller 非空）；
 *   ② 上传图片（内容哈希命名）第二次请求由 SW 从缓存返回；
 *   ③ 合并包 ?action=assets 与 index.js 走 SW，第二次零传输；
 *   ④ HTML 页面**不进 SW 缓存**（页面内嵌会话密钥，缓存会导致签名失效）；
 *   ⑤ 接口 ?action=… 不被 SW 接管；
 *   ⑥ 全程无 JS 报错。
 *
 * ⚠️ 本机站点用自签名证书：Chrome 抓取 SW 脚本**不认** context 的 ignoreHTTPSErrors，
 *    必须用 `--ignore-certificate-errors` 启动，否则注册直接失败（生产真证书无此问题）。
 */
const fs = require('fs');
const { chromium } = require('C:/Users/zeali/.workbuddy/binaries/node/workspace/node_modules/playwright-core');

const B = 'https://localhost';
const WWW = 'D:/Program Files/phpstudy_pro/WWW/owlsgo';
const IMG_REL = '/uploads/image/zz/sw-test-' + Date.now() + '.png';
const IMG_ABS = WWW + IMG_REL;
const out = [];
const P = (ok, n, e) => out.push((ok ? 'PASS' : 'FAIL') + ' | ' + n + (e ? ' | ' + e : ''));

(async () => {
  fs.mkdirSync(WWW + '/uploads/image/zz', { recursive: true });
  fs.writeFileSync(IMG_ABS, Buffer.from('89504e470d0a1a0a0000000d49484452', 'hex'));

  const browser = await chromium.launch({ channel: 'chrome', args: ['--ignore-certificate-errors'] });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
  const page = await ctx.newPage();
  const errors = [];
  const events = [];   // 数组：同一 URL 会多次出现，不能用 Map 覆盖
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('response', (r) => {
    try { events.push({ u: r.url().replace(B, ''), sw: r.fromServiceWorker(), s: r.status() }); } catch (_) {}
  });
  const lastOf = (re) => [...events].reverse().find((e) => re.test(e.u)) || null;

  try {
    await page.goto(B + '/', { waitUntil: 'networkidle', timeout: 30000 });
    P(errors.length === 0, '首屏无 JS 报错', errors.join(' / '));

    // ---------- ① 注册并接管 ----------
    const ready = await page.evaluate(() => Promise.race([
      navigator.serviceWorker.ready.then(() => true),
      new Promise((r) => setTimeout(() => r(false), 8000)),
    ]).catch(() => false));
    P(ready === true, 'Service Worker 注册成功（ready）');

    await page.reload({ waitUntil: 'networkidle' });
    const ctrl = await page.evaluate(() => !!(navigator.serviceWorker && navigator.serviceWorker.controller));
    P(ctrl === true, 'reload 后页面已被 SW 接管');

    // ---------- ④ 页面不进缓存 ----------
    const doc = lastOf(/^\/$/);
    P(!!doc && doc.sw === false, 'HTML 页面不被 SW 接管', JSON.stringify(doc));

    // ---------- ③ 合并包 / 核心静态资源走 SW ----------
    const fullBundle = [...events].reverse().find((e) => /action=assets&type=js&v=/.test(e.u));
    P(!!fullBundle && fullBundle.sw === true, '合并包经过 SW', JSON.stringify(fullBundle));
    const idx = [...events].reverse().find((e) => /\/index\.js\?v=/.test(e.u));
    P(!!idx && idx.sw === true, 'index.js 经过 SW', JSON.stringify(idx));

    // 第三次加载：应全部命中缓存（零传输）
    await page.reload({ waitUntil: 'networkidle' });
    const perf = await page.evaluate(() => {
      const pick = (re) => {
        const e = performance.getEntriesByType('resource').find((x) => re.test(x.name.replace(location.origin, '')));
        return e ? { transfer: e.transferSize } : null;
      };
      return {
        bundle: pick(/action=assets&type=js&v=/),
        js: pick(/^\/index\.js\?v=/),
      };
    });
    P(perf.bundle && perf.bundle.transfer === 0, '合并包第二次零传输', JSON.stringify(perf.bundle));
    P(perf.js && perf.js.transfer === 0, 'index.js 第二次零传输', JSON.stringify(perf.js));

    // ---------- ⑤ 接口不缓存 ----------
    await page.evaluate(() => fetch('/?action=plugin_e2ee_dm_state', { credentials: 'same-origin' }).catch(() => null));
    const api = lastOf(/action=plugin_e2ee_dm_state/);
    P(!api || api.sw === false, '接口不被 SW 接管', JSON.stringify(api));

    // ---------- ② 上传图片：两次 fetch，第二次由 SW 返回 ----------
    const status = [];
    for (let i = 0; i < 2; i++) {
      status.push(await page.evaluate((u) => fetch(u, { credentials: 'same-origin' }).then((r) => r.status).catch(() => 0), IMG_REL));
      await page.waitForTimeout(400);   // 等 SW 异步 put 落缓存
    }
    const imgEvents = events.filter((e) => e.u.indexOf(IMG_REL) === 0);
    P(status[0] === 200 && status[1] === 200, '测试图片可访问', status.join('/'));
    P(imgEvents.length >= 2 && imgEvents[imgEvents.length - 1].sw === true, '图片第二次由 SW 返回', JSON.stringify(imgEvents));

    P(errors.length === 0, '全程无未捕获 JS 错误', errors.join(' / '));
  } finally {
    await browser.close();
    try { fs.unlinkSync(IMG_ABS); } catch (_) {}
    try { fs.rmdirSync(WWW + '/uploads/image/zz'); } catch (_) {}
  }

  console.log(out.join('\n'));
  const bad = out.filter((l) => l.startsWith('FAIL')).length;
  console.log(bad === 0 ? 'ALL PASS' : bad + ' FAILED');
  process.exit(bad === 0 ? 0 : 1);
})().catch((e) => { console.error('SCRIPT ERROR:', e && e.message); try { fs.unlinkSync(IMG_ABS); } catch (_) {} process.exit(2); });
