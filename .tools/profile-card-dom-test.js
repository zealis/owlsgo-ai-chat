/**
 * 用户名片插件（profile-card）DOM 级验证 + 截图
 * 跑在隔离副本 .t-pc 上（php -S 127.0.0.1:8845），用系统 Chrome。
 * 本机 peer 会被 Sec::tlsVerified 信任 X-Forwarded-Proto（见 peerIsLocal），
 * 所以整个上下文带 X-Forwarded-Proto: https 即可绕过强制 HTTPS 跳转。
 * 用法：node .tools/profile-card-dom-test.js
 */
const { chromium } = require('C:/Users/zeali/.workbuddy/skills/birdview/node_modules/playwright-core');
const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const B = 'https://127.0.0.1:8845';
const OUT = path.join(__dirname, 'tmp');
if (!fs.existsSync(OUT)) fs.mkdirSync(OUT, { recursive: true });

// 每轮从干净状态起步：900002 无横幅；900003 固定带 banner-b（他人卡片用例靠它）
const reset = spawnSync('D:/Program Files/phpstudy_pro/Extensions/php/php8.2.9nts/php.exe',
  ['.tools/pc-dom-reset.php'], { cwd: path.join(__dirname, '..'), encoding: 'utf8' });
if (reset.status !== 0) { console.error('状态重置失败：', reset.stderr); process.exit(1); }

const ID = 'zz-av-1791994798@example.test';
const PW = 'T3st!points';
const BANNER = 'uploads/image/pc/banner-a.png';

const errors = [];
const results = [];
function ck(name, actual, expect) {
  const ok = String(actual) === String(expect);
  results.push({ name, ok, actual: String(actual), expect: String(expect) });
  console.log(`  [${ok ? 'PASS' : 'FAIL'}] ${name}` + (ok ? '' : `  实际=[${actual}] 期望=[${expect}]`));
  return ok;
}

(async () => {
  const browser = await chromium.launch({ channel: 'chrome' });
  const ctx = await browser.newContext({
    viewport: { width: 1280, height: 860 },
    ignoreHTTPSErrors: true,
  });
  const page = await ctx.newPage();
  page.on('pageerror', e => errors.push(e.message));

  /* ---------- 登录 ---------- */
  await page.goto(B + '/?page=login', { waitUntil: 'domcontentloaded' });
  await page.fill('input[name=identity]', ID);
  await page.fill('input[name=password]', PW);
  await page.click('button[type=submit]');
  await page.waitForSelector('#owRail', { timeout: 15000 });
  await page.waitForTimeout(800);
  console.log('=== 1) 登录 + 名片面板 ===');
  ck('登录后进入聊天页', await page.locator('#owRail').count(), 1);

  /* ---------- 名片面板 ---------- */
  await page.click('[data-tab="profilecard"]');
  await page.waitForSelector('#owpcPanel', { timeout: 8000 });
  // 等状态文案落定（GET 是异步的，别在「读取中…」上断言）
  await page.waitForFunction(() => {
    const s = document.getElementById('owpcState');
    return s && s.textContent.indexOf('读取中') < 0;
  }, { timeout: 8000 });
  ck('「名片」面板打开', await page.locator('#owpcPanel').count(), 1);
  ck('初始状态文案', (await page.textContent('#owpcState')).trim(), '未设置 · 默认白色');
  ck('预览区有横幅位', await page.locator('#owpcPreviewBanner').count(), 1);
  await page.screenshot({ path: path.join(OUT, 'pc-1-panel-empty.png'), clip: { x: 0, y: 0, width: 1280, height: 860 } });

  /* ---------- 通过链接设置横幅 ---------- */
  await page.fill('#owpcUrlInput', BANNER);
  await page.click('#owpcUrlBtn');
  await page.waitForFunction(() => document.getElementById('owpcState') &&
    document.getElementById('owpcState').textContent.indexOf('已设置') >= 0, { timeout: 8000 });
  ck('链接设置后状态', (await page.textContent('#owpcState')).trim(), '已设置横幅');
  const prevBg = await page.$eval('#owpcPreviewBanner', el => getComputedStyle(el).backgroundImage);
  ck('预览横幅背景图生效', /banner-a\.png/.test(prevBg), true);
  ck('移除按钮出现', await page.locator('#owpcRemoveBtn').isVisible(), true);
  await page.screenshot({ path: path.join(OUT, 'pc-2-panel-set.png'), clip: { x: 0, y: 0, width: 1280, height: 860 } });

  /* ---------- 自己的资料卡（.ow-set-avatar-btn 壳路径） ---------- */
  console.log('=== 2) 自己的资料卡（onCardRendered → 横幅 + 头像叠压） ===');
  await page.evaluate(() => OwChat.userCard(900002));
  await page.waitForSelector('#owModal.owpc-card', { timeout: 8000 });
  await page.waitForTimeout(400);
  ck('弹窗带上 owpc-card 类', (await page.getAttribute('#owModal', 'class')).includes('owpc-card'), true);
  ck('横幅节点注入', await page.locator('#owModal .owpc-banner').count(), 1);
  const bannerBox = await page.$eval('#owModal .owpc-banner', el => {
    const cs = getComputedStyle(el);
    return { h: cs.height, mt: cs.marginTop, ml: cs.marginLeft, bg: cs.backgroundImage, bgc: cs.backgroundColor, radius: cs.borderTopLeftRadius };
  });
  ck('横幅高度 96px', bannerBox.h, '96px');
  ck('横幅向上出血 -24px（抵消弹窗内边距）', bannerBox.mt, '-24px');
  ck('横幅左右出血 -24px', bannerBox.ml, '-24px');
  ck('横幅背景图为所选图', /banner-a\.png/.test(bannerBox.bg), true);
  ck('默认底色为白（--ow-surface）', bannerBox.bgc, 'rgb(255, 255, 255)');
  ck('横幅顶部圆角继承弹窗', bannerBox.radius, '10px');
  const headBox = await page.$eval('#owModal .ow-card-head', el => {
    const cs = getComputedStyle(el);
    return { dir: cs.flexDirection, align: cs.alignItems };
  });
  ck('头部改纵排', headBox.dir, 'column');
  ck('头部左对齐', headBox.align, 'flex-start');
  const avBox = await page.$eval('#owModal .ow-card-head > .ow-set-avatar-btn', el => {
    const cs = getComputedStyle(el);
    return { mt: cs.marginTop, shadow: cs.boxShadow };
  });
  ck('头像上移叠压横幅 -34px', avBox.mt, '-34px');
  ck('头像 4px 同色描边环', /4px/.test(avBox.shadow), true);
  ck('原标题被收起', await page.$eval('#owModal > h3', el => getComputedStyle(el).display), 'none');
  ck('关闭按钮有衬底（压横幅可见）', await page.$eval('#owModal .ow-modal-close', el => getComputedStyle(el).borderRadius), '50%');
  ck('昵称行仍在', await page.locator('#owModal .ow-card-name').count(), 1);
  await page.screenshot({ path: path.join(OUT, 'pc-3-card-self.png') });

  /* ---------- 别人的资料卡（.ow-avatar 裸路径 + 别人设的横幅） ---------- */
  console.log('=== 3) 他人资料卡 ===');
  await page.evaluate(() => { OwChat.closeModal(); OwChat.userCard(900003); });
  await page.waitForSelector('#owModal.owpc-card', { timeout: 8000 });
  await page.waitForTimeout(400);
  ck('他人卡片也有横幅节点', await page.locator('#owModal .owpc-banner').count(), 1);
  const otherBg = await page.$eval('#owModal .owpc-banner', el => getComputedStyle(el).backgroundImage);
  ck('他人横幅按各自设置显示（banner-b）', /banner-b\.png/.test(otherBg), true);
  ck('他人头像走裸 .ow-avatar 路径', await page.locator('#owModal .ow-card-head > .ow-avatar').count(), 1);
  const avBox2 = await page.$eval('#owModal .ow-card-head > .ow-avatar', el => getComputedStyle(el).marginTop);
  ck('他人头像同样叠压 -34px', avBox2, '-34px');
  await page.screenshot({ path: path.join(OUT, 'pc-4-card-other.png') });

  /* ---------- 移除横幅 → 默认白 ---------- */
  console.log('=== 4) 移除横幅 → 默认白色 ===');
  await page.evaluate(() => OwChat.closeModal());
  // 先切去「消息」再切回「名片」—— 直接点已激活的 rail 入口会收起侧栏
  // （核心 v1.3.126 行为，所有标签一致），那样就摸不到面板了。
  // 顺带验证：切走再切回，面板内容还在、状态会重拉。
  await page.click('[data-tab="chat"]');
  await page.waitForTimeout(300);
  await page.click('[data-tab="profilecard"]');
  await page.waitForSelector('#owpcPanel', { timeout: 8000 });
  await page.waitForFunction(() => {
    const s = document.getElementById('owpcState');
    return s && s.textContent.indexOf('读取中') < 0;
  }, { timeout: 8000 });
  ck('重入面板状态已重拉', (await page.textContent('#owpcState')).trim(), '已设置横幅');
  await page.click('#owpcRemoveBtn');
  await page.waitForFunction(() => document.getElementById('owpcState') &&
    document.getElementById('owpcState').textContent.indexOf('未设置') >= 0, { timeout: 8000 });
  const prevBg2 = await page.$eval('#owpcPreviewBanner', el => getComputedStyle(el).backgroundImage);
  ck('移除后预览无背景图', prevBg2, 'none');
  await page.evaluate(() => OwChat.userCard(900002));
  await page.waitForSelector('#owModal.owpc-card', { timeout: 8000 });
  const defBg = await page.$eval('#owModal .owpc-banner', el => {
    const cs = getComputedStyle(el);
    return { img: cs.backgroundImage, color: cs.backgroundColor };
  });
  ck('移除后卡片横幅无图', defBg.img, 'none');
  ck('移除后卡片横幅默认白色', defBg.color, 'rgb(255, 255, 255)');
  ck('移除后仍是名片布局（横幅节点在）', await page.locator('#owModal .owpc-banner').count(), 1);
  await page.screenshot({ path: path.join(OUT, 'pc-5-card-default.png') });
  await page.evaluate(() => OwChat.closeModal());

  /* ---------- 移动端 ---------- */
  console.log('=== 5) 移动端 375px ===');
  await page.setViewportSize({ width: 375, height: 720 });
  await page.evaluate(() => OwChat.userCard(900002));
  await page.waitForSelector('#owModal.owpc-card', { timeout: 8000 });
  await page.waitForTimeout(400);
  const mBox = await page.$eval('#owModal .owpc-banner', el => getComputedStyle(el).height);
  ck('窄屏横幅 76px', mBox, '76px');
  const mAv = await page.$eval('#owModal .ow-card-head > .ow-set-avatar-btn', el => getComputedStyle(el).marginTop);
  ck('窄屏头像叠压 -28px', mAv, '-28px');
  await page.screenshot({ path: path.join(OUT, 'pc-6-card-mobile.png'), fullPage: false });

  /* ---------- 汇总 ---------- */
  const fails = results.filter(r => !r.ok).length;
  console.log(`\n========== DOM 结果：PASS=${results.length - fails} FAIL=${fails} ==========`);
  if (errors.length) { console.log('页面错误：'); errors.forEach(e => console.log('  ! ' + e)); }
  else console.log('页面错误：无');
  await browser.close();
  if (fails > 0 || errors.length > 0) process.exit(1);
})().catch(e => { console.error('脚本异常：', e.message); process.exit(1); });
