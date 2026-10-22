/* ============================================================================
 * 插件 JS 按页面分包验收（v1.5.32）
 *
 * 之前所有页面都吃同一个 1.13MB 合并包（gzip 333KB）：登录页加载了 30 个聊天脚本，
 * 后台加载了 30 个前台脚本。现在按 scope 拆开：
 *   front（聊天页 / 插件前台页）、auth（登录·注册·找回）、admin（后台）
 * 没标 scope 的资源（默认 all）在任何页面都包含。
 *
 * 此脚本从**页面实际生效的全局对象**反推「这个页面到底加载了哪一包」——
 * 比数请求更硬：JS 少加载一个文件，对应的 `window.OwXxx` 就不存在。
 *
 * 用法：node .tools/scope-check.js
 * ========================================================================== */
const { chromium } = require('C:/Users/zeali/.workbuddy/binaries/node/workspace/node_modules/playwright-core');

const BASE = process.env.OWL_BASE || 'https://localhost';
let pass = 0, fail = 0;
const ok = (m, extra) => { pass++; console.log('  [PASS] ' + m + (extra ? '  ' + extra : '')); };
const bad = (m, extra) => { fail++; console.log('  [FAIL] ' + m + (extra ? '  ' + extra : '')); };
const ck = (m, cond, extra) => (cond ? ok : bad)(m, extra);

/** 探针：页面里有哪些插件全局 + 它们是否是可用的对象 */
async function probe(page) {
  return page.evaluate(() => {
    const names = ['OwAttach', 'OwBM', 'OwBatch', 'OwCBUser', 'OwCS', 'OwCR', 'OwGif', 'OwCult',
      'OwLcChat', 'OwOauth', 'OwPK', 'OwPC', 'OwRP', 'OwRoomEmbed', 'OwSpam', 'OwSH', 'OwTM',
      'OwTwoFA', 'OwUB', 'OwVI', 'OwVM', 'OwUM', 'OwAdmin', 'OwChat', 'OwApi'];
    const out = {};
    for (const n of names) out[n] = typeof window[n];
    return out;
  });
}

(async () => {
  const browser = await chromium.launch({
    channel: 'chrome',
    args: ['--ignore-certificate-errors', '--no-proxy-server'],
  });

  // ---------- ① 聊天页：应拿到 front 包 ----------
  console.log('== ① 聊天页（front） ==');
  {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true, serviceWorkers: 'block' });
    const page = await ctx.newPage();
    const errs = [];
    page.on('pageerror', (e) => errs.push(e.message));
    const jsReqs = [];
    page.on('request', (r) => {
      const u = r.url();
      if (/action=assets&type=js/.test(u) && !/[?&]plugin=/.test(u)) jsReqs.push(u);   // 只记整包，排除 boot.js 切片
    });

    await page.goto(BASE + '/', { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForTimeout(3000);
    const g = await probe(page);

    ck('整包 URL 带 scope=front', jsReqs.some((u) => /[?&]scope=front/.test(u)), jsReqs[0] ? jsReqs[0].replace(BASE, '') : '无');
    ck('聊天插件已就绪（OwVM / OwRP / OwGif / OwBatch）',
      g.OwVM === 'object' && g.OwRP === 'object' && g.OwGif === 'object' && g.OwBatch === 'object',
      'OwVM=' + g.OwVM + ' OwRP=' + g.OwRP + ' OwGif=' + g.OwGif + ' OwBatch=' + g.OwBatch);
    ck('聊天插件已就绪（OwAttach / OwTM / OwSP?）',
      g.OwAttach === 'object' && g.OwTM === 'object');
    ck('后台脚本**没有**进来（OwUM 缺失）', g.OwUM === 'undefined', 'OwUM=' + g.OwUM);
    ck('核心对象正常（OwChat / OwApi）', g.OwChat === 'object' && g.OwApi === 'object');
    ck('无未捕获 JS 错误', errs.length === 0, errs.slice(0, 2).join(' | '));

    const sz = await page.evaluate(() => {
      const e = performance.getEntriesByType('resource')
        .filter((r) => /action=assets&type=js/.test(r.name) && !/[?&]plugin=/.test(r.name));
      return e.map((r) => r.decodedBodySize || 0)[0] || 0;
    });
    ok('front 包解压后体积', Math.round(sz / 1024) + 'KB（改前全量 1133KB）');
    await ctx.close();
  }

  // ---------- ② 登录页：应只拿到 auth 包 ----------
  console.log('== ② 登录页（auth） ==');
  {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true, serviceWorkers: 'block' });
    const page = await ctx.newPage();
    const errs = [];
    page.on('pageerror', (e) => errs.push(e.message));
    const jsReqs = [];
    page.on('request', (r) => {
      const u = r.url();
      if (/action=assets&type=js/.test(u) && !/[?&]plugin=/.test(u)) jsReqs.push(u);   // 只记整包，排除 boot.js 切片
    });

    await page.goto(BASE + '/?page=login', { waitUntil: 'domcontentloaded', timeout: 30000 });
    await page.waitForTimeout(2500);
    const g = await probe(page);

    ck('整包 URL 带 scope=auth', jsReqs.some((u) => /[?&]scope=auth/.test(u)), jsReqs[0] ? jsReqs[0].replace(BASE, '') : '无');
    // passkey 是登录页真正会用的（通行密钥登录）；oauth-core 的 chat.js 第 9 行
    // `if (!w.OwChat || !OwChat.cfg) return` 在登录页本来就会自行退出（全量包时代也一样），
    // 所以不能拿 OwOauth 当分包证据 —— 它缺失与 scope 无关。
    ck('登录相关插件已就绪（OwPK）', g.OwPK === 'object', 'OwPK=' + g.OwPK);
    ck('登录页签名机制就绪（表单带 ts/sign 或 OwAuth 在）',
      await page.evaluate(() => !!(document.querySelector('input[name="ts"]') || window.OwAuth)));
    ck('聊天插件**没有**进来（OwVM 缺失）', g.OwVM === 'undefined', 'OwVM=' + g.OwVM);
    ck('聊天插件**没有**进来（OwRP 缺失）', g.OwRP === 'undefined', 'OwRP=' + g.OwRP);
    ck('后台脚本**没有**进来（OwUM 缺失）', g.OwUM === 'undefined', 'OwUM=' + g.OwUM);
    ck('登录页核心对象正常（OwAuth 的表单在）',
      await page.evaluate(() => !!document.querySelector('input[name="identity"], #owAuthForm, .ow-auth-form')));
    ck('无未捕获 JS 错误', errs.length === 0, errs.slice(0, 2).join(' | '));

    const sz = await page.evaluate(() => {
      const e = performance.getEntriesByType('resource')
        .filter((r) => /action=assets&type=js/.test(r.name) && !/[?&]plugin=/.test(r.name));
      return e.map((r) => r.decodedBodySize || 0)[0] || 0;
    });
    ok('auth 包解压后体积', Math.round(sz / 1024) + 'KB（改前全量 1133KB）');
    await ctx.close();
  }

  // ---------- ③ 兜底：不带 scope 时仍是全量（老调用方兼容） ----------
  console.log('== ③ 兼容：不带 scope 仍返回全量 ==');
  {
    const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
    const page = await ctx.newPage();
    const r = await page.goto(BASE + '/?action=assets&type=js&v=v2.7', { timeout: 30000 });
    const body = await r.text();
    ck('无 scope 的整包 HTTP 200', r.status() === 200, 'status=' + r.status());
    ck('无 scope 的整包包含 admin.js（全量口径）', body.indexOf('plugin: user-manager/admin.js') >= 0);
    ck('无 scope 的整包包含 chat.js', body.indexOf('plugin: voice-msg/chat.js') >= 0);
    await ctx.close();
  }

  await browser.close();
  console.log('');
  console.log(fail === 0 ? `ALL PASS (${pass} 项)` : `${fail} 项失败，${pass} 项通过`);
  process.exit(fail === 0 ? 0 : 1);
})().catch((e) => { console.error('脚本异常:', e.message); process.exit(1); });
