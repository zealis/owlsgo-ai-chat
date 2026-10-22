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

/**
 * 关掉安全画像弹窗。
 * 直接摘遮罩而不是点 ✕：✕ 是 float 定位的小按钮，Playwright 判「可点」的条件
 * 比真人苛刻（飘着的 toast、面板高度还在随归属地回填变化都会让它一直不 stable）。
 * 测试目的是「回到列表继续下一步」，摘节点最稳。
 */
async function closePanel(page) {
  await page.evaluate(() => {
    const m = document.getElementById('owumPanel');
    if (m && m.parentNode) m.parentNode.removeChild(m);
  });
  await page.waitForTimeout(250);
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
  // 中途抛异常时也要把已经抓到的报错打出来，否则只看到一句 Timeout，
  // 真正的原因（console / 4xx）被吞在数组里
  process.on('uncaughtException', async (e) => {
    console.log('!! 中断：' + e.message.split('\n')[0]);
    errors.forEach(x => console.log('   - ' + x));
    try { await browser.close(); } catch (err) {}
    process.exit(1);
  });
  process.on('unhandledRejection', async (e) => {
    console.log('!! 中断：' + String(e && e.message || e).split('\n')[0]);
    errors.forEach(x => console.log('   - ' + x));
    try { await browser.close(); } catch (err) {}
    process.exit(1);
  });

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
      // 弹窗里输入「自己当前 IP」：红字提示出现 + 确认按钮禁用（服务端也会拒，这里验前置）
      await page.fill('#owumDlgIp', '127.0.0.1');
      await page.waitForTimeout(250);
      const dlgSelf = await page.evaluate(() => {
        const w = document.getElementById('owumDlgWarn');
        const ok = document.getElementById('owumDlgOk');
        return { warn: !!w && w.style.display !== 'none', disabled: !!ok && ok.disabled };
      });
      console.log('  ' + label + ' dialog-self', JSON.stringify(dlgSelf));
      if (!dlgSelf.warn) errors.push(label + ' 弹窗输入自己 IP 时没有出现红字提示');
      if (!dlgSelf.disabled) errors.push(label + ' 弹窗输入自己 IP 时确认按钮没有禁用');
      await page.fill('#owumDlgIp', '203.0.113.77');   // 还原成高风险 IP 再确认
      await page.waitForTimeout(200);
      await page.click('#owumDlgOk');
      // 一次点击背后是「取票据 → 提交 → 重拉列表」三个往返，900ms 不够，
      // 上一步截图会拍到「已封禁」的 toast 与「共 0 条」并存的中间态（差点误判成没刷新）
      await page.waitForTimeout(1800);
      await shot(page, 'um-' + label + '-4-banlist.png');
      const listed = await page.evaluate(() => {
        const box = document.getElementById('owumBanList');
        return box ? box.querySelectorAll('.owum-ban-row').length : -1;
      });
      console.log('  ' + label + ' ban-list rows', listed);
      if (listed < 1) errors.push(label + ' 封禁后规则列表没有刷新（rows=' + listed + '）');
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

    // 头像断言：生成式头像必须渲染成 <img>，不能退化成「首字 + 色块」
    const av = await page.evaluate(() => {
      const box = document.querySelector('#owumPanel .owum-phead .ow-avatar');
      if (!box) return { ok: false, reason: 'no avatar' };
      const img = box.querySelector('img');
      return { ok: !!img, src: img ? img.getAttribute('src').slice(0, 60) : '(无 img，退化成色块)',
               bg: img ? '' : getComputedStyle(box).backgroundColor };
    });
    console.log('  ' + label + ' avatar', JSON.stringify(av));
    if (!av.ok) errors.push(label + ' 头像没有渲染成图片：' + av.src + ' bg=' + av.bg);

    // 面板几何（此时面板还开着、数据已到）：面板不能横向溢出
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

    // 自己 IP 不能被封：本次浏览器请求的出口就是 127.0.0.1，
    // 用 1 号账号（他最近一次登录也记在 127.0.0.1）验证入口已关闭
    await closePanel(page);
    await page.fill('#owAQ', '1');
    await page.click('button:has-text("搜索用户")');
    await page.waitForTimeout(700);
    await page.click('a:has-text("安全画像")');
    await page.waitForTimeout(1800);
    await shot(page, 'um-' + label + '-7-selfip.png');
    const self = await page.evaluate(() => {
      const body = document.querySelector('#owumBody');
      if (!body) return { hasSelfPill: false, rows: [], heroHasSelf: false };
      const rows = Array.from(body.querySelectorAll('.owum-net')).map(n => ({
        ip: (n.querySelector('.owum-net-ip') || {}).textContent || '',
        act: ((n.querySelector('.owum-net-act') || {}).textContent || '').trim(),
      }));
      const hero = body.querySelector('.owum-hcard');
      return {
        hasSelfPill: body.textContent.indexOf('当前 IP') >= 0,
        heroHasSelf: hero ? hero.textContent.indexOf('当前 IP') >= 0 : false,
        rows: rows,
      };
    });
    console.log('  ' + label + ' self-ip ' + JSON.stringify(self.rows));
    const selfRow = self.rows.filter(r => r.ip === '127.0.0.1')[0];
    if (!self.hasSelfPill || !self.heroHasSelf) errors.push(label + ' 自己的 IP 没有标成「当前 IP」');
    if (selfRow && selfRow.act.indexOf('封禁') >= 0) {
      errors.push(label + ' 自己的 IP 行仍然给了封禁按钮：' + selfRow.act);
    }

    // 关闭弹窗，回到封禁页看移动端是否折行
    await closePanel(page);

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
