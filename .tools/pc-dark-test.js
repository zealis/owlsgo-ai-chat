/* 深色主题下的名片卡验证（--ow-surface 随主题变，确认无亮斑 / 无破相） */
const { chromium } = require('C:/Users/zeali/.workbuddy/skills/birdview/node_modules/playwright-core');
const { spawnSync } = require('child_process');
const path = require('path');
const B = 'https://127.0.0.1:8845';
const OUT = path.join(__dirname, 'tmp');

// 900002 先设上横幅（banner-a 蓝色图），深色下看 image 与无图两种形态
spawnSync('D:/Program Files/phpstudy_pro/Extensions/php/php8.2.9nts/php.exe',
  ['-r', '$db=new PDO("sqlite:.t-pc/data/owlsgo.db");' +
   '$db->exec("INSERT OR REPLACE INTO plugin_profile_card (user_id,banner,updated_at) VALUES (900002,\'uploads/image/pc/banner-a.png\'," . time() . ")");'],
  { cwd: path.join(__dirname, '..'), encoding: 'utf8' });

(async () => {
  const browser = await chromium.launch({ channel: 'chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 860 }, ignoreHTTPSErrors: true });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto(B + '/?page=login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[name=identity]', 'zz-av-1791994798@example.test');
  await page.fill('input[name=password]', 'T3st!points');
  await page.click('button[type=submit]');
  await page.waitForSelector('#owRail', { timeout: 15000 });   // 等登录跳转落定
  await page.evaluate(() => localStorage.setItem('owl_theme', 'dark'));
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.waitForSelector('#owRail', { timeout: 15000 });   //  reload 后再等一次
  await page.waitForTimeout(800);
  ck('深色主题生效', await page.evaluate(() => document.documentElement.getAttribute('data-theme')), 'dark');
  await page.evaluate(() => OwChat.userCard(900002));
  await page.waitForSelector('#owModal.owpc-card', { timeout: 8000 });
  await page.waitForTimeout(400);
  const st = await page.evaluate(() => {
    const cs = s => getComputedStyle(document.querySelector(s));
    return {
      bannerBg: cs('#owModal .owpc-banner').backgroundImage.slice(0, 60),
      ring: cs('#owModal .ow-card-head > .ow-set-avatar-btn').boxShadow,
      closeBg: cs('#owModal .ow-modal-close').backgroundColor,
      modalBg: cs('#owModal').backgroundColor,
      h3: cs('#owModal > h3').display,
    };
  });
  console.log(JSON.stringify(st, null, 2));
  await page.screenshot({ path: path.join(OUT, 'pc-7-card-dark.png') });
  console.log('页面错误：', errors.length ? errors.join('; ') : '无');
  await browser.close();
  function ck(n, a, e) { console.log(`  [${String(a) === String(e) ? 'PASS' : 'FAIL'}] ${n} 实际=[${a}]`); }
})().catch(e => { console.error('异常：', e.message); process.exit(1); });
