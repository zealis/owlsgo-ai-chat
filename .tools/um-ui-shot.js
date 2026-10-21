/**
 * 用户管理 v1.1.0「安全画像 / IP 封禁」界面验证 + 截图
 * 打开隔离副本 .t-um（php -S 8847），真实 Chrome 跑一遍：
 *   浅色桌面 / 深色桌面 / 移动端 390px，并抓 console 报错。
 * 用法：node .tools/um-ui-shot.js
 */
const { chromium } = require('C:/Users/zeali/.workbuddy/skills/birdview/node_modules/playwright-core');
const fs = require('fs');
const path = require('path');
const B = 'http://127.0.0.1:8847';
const OUT = path.join(__dirname, 'tmp');
if (!fs.existsSync(OUT)) fs.mkdirSync(OUT, { recursive: true });

const EMAIL = 'no-hrym@outlook.com';
const PASS = 'T3st!points';

async function login(page) {
  await page.goto(B + '/?page=login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="identity"]', EMAIL);
  await page.fill('input[name="password"]', PASS);
  // 登录是 AJAX 提交（OwApi.post），成功后前端自己跳 ?page=chat —— 等 URL 变，不要等导航
  const diag = await page.evaluate(() => ({
    hasApi: typeof window.OwApi,
    hasCfg: !!(window.OwChat && window.OwChat.cfg),
    key: (window.OwChat && window.OwChat.cfg) ? String(window.OwChat.cfg.key || '').length : -1,
    cookie: document.cookie.indexOf('owl_akey') >= 0,
    forms: document.querySelectorAll('form.ow-auth-form').length,
  }));
  fs.appendFileSync(path.join(OUT, 'diag.txt'), JSON.stringify(diag) + '\n');
  await page.click('button[type="submit"]');
  try {
    await page.waitForURL(/page=chat/, { timeout: 15000 });
  } catch (e) {
    const msg = await page.$eval('.ow-form-msg', el => el.textContent).catch(() => '(无提示)');
    console.log('  !! 登录失败，表单提示=' + msg + ' URL=' + page.url());
    throw e;
  }
  await page.waitForTimeout(900);
}

async function openPluginPage(page) {
  await page.goto(B + '/?page=admin', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(700);
  const items = await page.$$eval('#owAdminMenu li', els => els.map(e => e.getAttribute('data-apage')));
  if (items.indexOf('plugin:user-manager') < 0) {
    console.log('  !! 菜单里没有插件页，当前 URL=' + page.url());
    console.log('     菜单项=' + items.join(','));
    await page.screenshot({ path: path.join(OUT, 'um-debug-admin.png') });
    throw new Error('插件后台页未注册');
  }
  // 窄屏下侧栏是抽屉、子项在视口外，点不动 —— 兜底用后台自己的
  // sessionStorage 深链（OwAdmin.init 会读它），这条路桌面/手机都稳。
  const toggle = page.locator('#owAdminToggle');
  if (await toggle.isVisible()) {
    await toggle.click({ timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(400);
  }
  const sub = page.locator('li[data-apage="plugin:user-manager"]');
  if (!(await sub.isVisible())) {
    await page.click('li[data-apage="plugins"]', { timeout: 3000 }).catch(() => {});
    await page.waitForTimeout(300);
  }
  const clicked = await sub.click({ timeout: 4000 }).then(() => true).catch(() => false);
  if (!clicked) {
    await page.evaluate(() => {
      try {
        sessionStorage.setItem('owAdminPage', 'plugin:user-manager');
        sessionStorage.setItem('owAdminPluginsOpen', '1');
      } catch (err) {}
    });
    await page.reload({ waitUntil: 'domcontentloaded', timeout: 30000 });
  }
  await page.waitForTimeout(1000);
}

async function shot(page, name) {
  const p = path.join(OUT, name);
  await page.screenshot({ path: p, fullPage: false });
  console.log('  saved', p);
}

(async () => {
  const browser = await chromium.launch({ channel: 'chrome' });
  const errors = [];

  async function run(label, width, height, dark, mobile) {
    const ctx = await browser.newContext({
      viewport: { width: width, height: height },
      deviceScaleFactor: mobile ? 3 : 1,
      isMobile: !!mobile,
      hasTouch: !!mobile,
      extraHTTPHeaders: { 'X-Forwarded-Proto': 'https' },
      colorScheme: dark ? 'dark' : 'light',
    });
    const page = await ctx.newPage();
    page.on('console', m => { if (m.type() === 'error') errors.push(label + ' console: ' + m.text()); });
    page.on('pageerror', e => errors.push(label + ' pageerror: ' + e.message));
    page.on('response', r => {
      if (r.status() >= 400) {
        const u = r.url();
        // e2ee-dm 在后台页也会打一次 plugin_e2ee_dm_state（需要聊天上下文），403 是它既有行为
        if (u.indexOf('plugin_e2ee_dm_state') < 0) errors.push(label + ' HTTP ' + r.status() + ' ' + u);
      }
    });

    await page.addInitScript((d) => {
      try { localStorage.setItem('owl_theme', d ? 'dark' : 'light'); } catch (e) {}
    }, dark);

    await login(page);
    await openPluginPage(page);
    await shot(page, 'um-' + label + '-1-user.png');

    // IP 封禁分栏
    await page.click('#owumTabs button[data-owum-tab="ip"]');
    await page.waitForTimeout(900);
    await shot(page, 'um-' + label + '-2-ban.png');

    // 封禁弹窗：点高风险 IP 第一行的「封禁」，先截图再确认（这样规则列表也有内容）
    const banBtn = page.locator('#owumTopIp button:has-text("封禁")').first();
    if (await banBtn.count()) {
      await banBtn.click();
      await page.waitForTimeout(400);
      await shot(page, 'um-' + label + '-3-dialog.png');
      await page.click('#owumDlgOk');
      await page.waitForTimeout(900);
      await shot(page, 'um-' + label + '-4-banlist.png');
    }

    // 安全画像
    await page.click('#owumTabs button[data-owum-tab="user"]');
    await page.waitForTimeout(200);
    await page.fill('#owAQ', '265');
    await page.click('button:has-text("搜索用户")');
    await page.waitForTimeout(700);
    await page.click('a:has-text("安全画像")');
    await page.waitForTimeout(2200);
    await shot(page, 'um-' + label + '-5-panel.png');

    // 从画像里直接封禁最近 IP → 整卡变红边、胶囊变「已封禁」
    const pban = page.locator('#owumPanel button:has-text("封禁此 IP")').first();
    if (await pban.count()) {
      await pban.click();
      await page.waitForTimeout(300);
      await page.click('#owumDlgOk');
      await page.waitForTimeout(1400);
      await shot(page, 'um-' + label + '-6-panel-banned.png');
    }

    // 几何断言：面板不能横向溢出
    const geo = await page.evaluate(() => {
      const m = document.querySelector('#owumPanel .ow-modal');
      if (!m) return { ok: false, reason: 'no modal' };
      const r = m.getBoundingClientRect();
      const card = document.querySelector('#owumPanel .owum-hcard');
      const tile = document.querySelector('#owumPanel .owum-tile');
      return {
        ok: true,
        w: Math.round(r.width), h: Math.round(r.height),
        overflowX: m.scrollWidth > m.clientWidth + 1,
        cardW: card ? Math.round(card.getBoundingClientRect().width) : 0,
        tileW: tile ? Math.round(tile.getBoundingClientRect().width) : 0,
        docOverflow: document.documentElement.scrollWidth > window.innerWidth + 1,
        ips: (document.querySelectorAll('#owumPanel .owum-net').length),
        tiles: (document.querySelectorAll('#owumPanel .owum-tile').length),
      };
    });
    console.log('  ' + label + ' panel', JSON.stringify(geo));
    if (geo.overflowX) errors.push(label + ' 面板内部横向溢出');
    if (geo.docOverflow) errors.push(label + ' 页面横向溢出');

    // 关闭弹窗，回到封禁页看移动端是否折行
    await page.click('#owumPanel .ow-modal-close');
    await page.waitForTimeout(300);

    // 深色模式亮斑抽查：主卡背景不能等于文字色
    const contrast = await page.evaluate(() => {
      const el = document.querySelector('.owum-ban-row');
      if (!el) return null;
      const cs = getComputedStyle(el);
      return { bg: cs.backgroundColor, fg: cs.color };
    });
    console.log('  ' + label + ' ban-row', JSON.stringify(contrast));
    await ctx.close();
  }

  console.log('light desktop 1440');
  await run('desk', 1440, 900, false, false);
  console.log('dark desktop 1440');
  await run('dark', 1440, 900, true, false);
  console.log('mobile 390');
  await run('mobile', 390, 844, false, true);

  await browser.close();
  // 「Failed to load resource ... 403」这类 console 行不带 URL，无法就地判断归属；
  // 真正要盯的是 response 监听里记下的 4xx（已排除 e2ee-dm 的既有 403），所以这里只留其它报错。
  const real = errors.filter(e => e.indexOf('Failed to load resource') < 0);
  if (real.length) {
    console.log('\n!! 发现问题：');
    real.forEach(e => console.log('   - ' + e));
    process.exit(1);
  }
  console.log('\n全部通过：无 JS 异常、无非预期 4xx、无横向溢出');
})();
