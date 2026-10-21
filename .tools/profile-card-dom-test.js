/**
 * 用户名片插件（profile-card v1.0.1）DOM 级验证 + 截图
 * 由 .tools/e2e-profile-card.sh 调起（服务器生命周期归那个脚本管），也可单独跑：
 * 需先自备 .t-pc + 3 个 php -S 后端 + pc-test-proxy.js（见 e2e 脚本头部说明）。
 * v1.0.1 起横幅管理在「个人设置 → 资料」页，本脚本驱动设置弹窗走完整链路。
 * 用法：node .tools/profile-card-dom-test.js
 */
const { chromium } = require('C:/Users/zeali/.workbuddy/skills/birdview/node_modules/playwright-core');
const { spawnSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const B = 'https://127.0.0.1:8845';
const OUT = path.join(__dirname, 'tmp');
if (!fs.existsSync(OUT)) fs.mkdirSync(OUT, { recursive: true });
// 测试横幅图自备（不依赖 .t-pc 残留）：1200x320 品牌蓝 + 半透明圆
const BANNER_PNG = path.join(OUT, 'pc-banner-a.png');
if (!fs.existsSync(BANNER_PNG)) {
  spawnSync('D:/Program Files/phpstudy_pro/Extensions/php/php8.2.9nts/php.exe', ['-r',
    '$im=imagecreatetruecolor(1200,320);imagefill($im,0,0,imagecolorallocate($im,0,144,233));' +
    '$w=imagecolorallocatealpha($im,255,255,255,70);' +
    'imagefilledellipse($im,180,240,420,420,$w);imagefilledellipse($im,1020,40,300,300,$w);' +
    'imagefilledellipse($im,760,300,200,200,$w);imagepng($im,$argv[1]);', BANNER_PNG],
    { encoding: 'utf8' });
}
// 每轮从干净状态起步：900002 无横幅；900003 固定带 banner-b（他人卡片用例靠它）
const reset = spawnSync('D:/Program Files/phpstudy_pro/Extensions/php/php8.2.9nts/php.exe',
  ['.tools/pc-dom-reset.php'], { cwd: path.join(__dirname, '..'), encoding: 'utf8' });
if (reset.status !== 0) { console.error('状态重置失败：', reset.stderr); process.exit(1); }

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
  await page.fill('input[name=identity]', 'zz-av-1791994798@example.test');
  await page.fill('input[name=password]', 'T3st!points');
  await page.click('button[type=submit]');
  await page.waitForSelector('#owRail', { timeout: 15000 });
  await page.waitForTimeout(800);

  /* ---------- 1. 个人设置里的横幅区块 ---------- */
  console.log('=== 1) 个人设置 → 资料：横幅区块 ===');
  ck('rail 不再有名片入口（v1.0.1 移入设置）', await page.locator('[data-tab="profilecard"]').count(), 0);
  await page.evaluate(() => OwChat.openSettings());
  await page.waitForSelector('#owModal.ow-modal-fixed #owpcSetBanner', { timeout: 8000 });
  ck('横幅区块注入「资料」页', await page.locator('#owpcSetBanner').count(), 1);
  ck('区块挂在资料面板里', await page.locator('.ow-pagetab-panel[data-pane="prof"] #owpcSetBanner').count(), 1);
  // 等现值读回（异步 GET），初始无横幅 → 移除按钮应收起
  await page.waitForFunction(() => {
    var rm = document.getElementById('owpcSetRemoveBtn');
    return rm && rm.style.display === 'none';
  }, { timeout: 8000 });
  ck('初始无横幅：移除按钮收起', await page.locator('#owpcSetRemoveBtn').isVisible(), false);
  ck('预览条存在（默认白）', await page.$eval('#owpcSetPreview', el => getComputedStyle(el).backgroundImage), 'none');
  ck('预览条高度 96px', await page.$eval('#owpcSetPreview', el => getComputedStyle(el).height), '96px');
  ck('预览里有半露小头像', await page.$eval('.owpc-set-preview-av', el => getComputedStyle(el).bottom), '-18px');
  ck('上传 / 链接 / 移除三件套齐', await page.locator('#owpcSetUploadBtn,#owpcSetUrlBtn,#owpcSetRemoveBtn').count(), 3);
  await page.screenshot({ path: path.join(OUT, 'pc-1-settings-empty.png') });

  /* ---------- 上传 → 暂存 → 保存 ---------- */
  await page.setInputFiles('#owpcSetFile', BANNER_PNG);
  await page.waitForFunction(() => window.OwPC && OwPC.staged && OwPC.staged.indexOf('uploads/image/') === 0, { timeout: 10000 });
  ck('上传后暂存值为 uploads/image/ 路径', /^uploads\/image\//.test(await page.evaluate(() => OwPC.staged)), true);
  ck('预览显示上传结果', /url\(/.test(await page.$eval('#owpcSetPreview', el => getComputedStyle(el).backgroundImage)), true);
  ck('移除按钮出现', await page.locator('#owpcSetRemoveBtn').isVisible(), true);
  ck('脏标记置位（待保存）', await page.evaluate(() => OwPC.dirty), true);
  await page.screenshot({ path: path.join(OUT, 'pc-2-settings-staged.png') });

  await page.click('#owModal .ow-btn-primary.ow-btn-block');          // 设置弹窗的「保存」
  await page.waitForFunction(() => document.getElementById('owModalMask').style.display === 'none', { timeout: 8000 });
  await page.waitForFunction(() => window.OwPC && OwPC.dirty === false, { timeout: 8000 });
  ck('保存后弹窗关闭', await page.$eval('#owModalMask', el => el.style.display), 'none');
  ck('保存后脏标记清除', await page.evaluate(() => OwPC.dirty), false);
  const savedBanner = await page.evaluate(() => new Promise(resolve => {
    OwApi.post('plugin_profile_card_get', { id: OwChat.cfg.me.id }, r => resolve(r.ok ? r.banner : ''));
  }));
  ck('横幅已落库', /^uploads\/image\//.test(savedBanner), true);

  /* ---------- 2. 自己的资料卡：上传的横幅显示出来 ---------- */
  console.log('=== 2) 自己的资料卡（onCardRendered → 横幅 + 头像叠压） ===');
  await page.evaluate(() => OwChat.userCard(900002));
  await page.waitForSelector('#owModal.owpc-card', { timeout: 8000 });
  await page.waitForTimeout(400);
  ck('弹窗带上 owpc-card 类', (await page.getAttribute('#owModal', 'class')).includes('owpc-card'), true);
  ck('横幅节点注入', await page.locator('#owModal .owpc-banner').count(), 1);
  const heroBox = await page.$eval('#owModal .owpc-hero', el => {
    const cs = getComputedStyle(el);
    const r = el.getBoundingClientRect();
    return { h: Math.round(r.height), mt: cs.marginTop, ml: cs.marginLeft, radius: cs.borderTopLeftRadius };
  });
  const bannerBox = await page.$eval('#owModal .owpc-banner', el => {
    const cs = getComputedStyle(el);
    return { h: cs.height, bg: cs.backgroundImage, bgc: cs.backgroundColor, radius: cs.borderTopLeftRadius };
  });
  ck('封面区铺到 meta 以上（≥180px）', heroBox.h >= 180, true);
  ck('封面区满宽（不被关闭按钮的浮动挤窄）', await page.evaluate(() => {
    const m = document.getElementById('owModal').getBoundingClientRect();
    const h = document.querySelector('#owModal .owpc-hero').getBoundingClientRect();
    return Math.round(h.width) === Math.round(m.width - 2);   // 扣掉左右各 1px 边框
  }), true);
  ck('封面区向上出血 -24px（抵消弹窗内边距）', heroBox.mt, '-24px');
  ck('封面区左右出血 -24px', heroBox.ml, '-24px');
  ck('封面区顶部圆角继承弹窗', heroBox.radius, '13px');
  ck('横幅铺满整个封面区（等于 hero 高）', Math.round(parseFloat(bannerBox.h)), heroBox.h);
  ck('横幅背景图为上传的图', /url\(/.test(bannerBox.bg), true);
  ck('默认底色为白（--ow-surface）', bannerBox.bgc, 'rgb(255, 255, 255)');
  ck('横幅顶部圆角继承弹窗', bannerBox.radius, '13px');
  const headBox = await page.$eval('#owModal .ow-card-head', el => {
    const cs = getComputedStyle(el);
    return { dir: cs.flexDirection, align: cs.alignItems, gap: cs.rowGap, pos: cs.position, z: cs.zIndex };
  });
  ck('头部纵排', headBox.dir, 'column');
  ck('头部左对齐', headBox.align, 'flex-start');
  ck('头像↔昵称间距 14px（站方要求拉大）', headBox.gap, '14px');
  ck('头部浮在图上（定位 + 抬层）', headBox.pos === 'relative' && Number(headBox.z) >= 1, true);
  const avBox = await page.$eval('#owModal .ow-card-head > .ow-set-avatar-btn', el => {
    const cs = getComputedStyle(el);
    return { mt: cs.marginTop, shadow: cs.boxShadow };
  });
  ck('头像在封面内（不再负数压边）', avBox.mt, '0px');
  ck('头像 4px 同色描边环', /4px/.test(avBox.shadow), true);
  ck('原标题被收起', await page.$eval('#owModal > h3', el => getComputedStyle(el).display), 'none');
  ck('关闭按钮有衬底（压横幅可见）', await page.$eval('#owModal .ow-modal-close', el => getComputedStyle(el).borderRadius), '50%');
  ck('昵称行仍在', await page.locator('#owModal .ow-card-name').count(), 1);
  ck('弹窗加宽到 420px（面板体量）', await page.$eval('#owModal', el => getComputedStyle(el).maxWidth), '420px');
  ck('昵称 20px 标题级', await page.$eval('#owModal .ow-card-name', el => getComputedStyle(el).fontSize), '20px');
  ck('头像收小到 64px（站方：再小一点）', await page.$eval('#owModal .ow-card-head > .ow-set-avatar-btn .ow-avatar', el => getComputedStyle(el).width), '64px');
  ck('信息行变数据胶囊', await page.$eval('#owModal .ow-card-meta-row', el => getComputedStyle(el).borderRadius), '8px');
  ck('顶部无彩色边框（站方取消）', await page.$eval('#owModal .owpc-banner', el => getComputedStyle(el).borderTopWidth), '0px');
  ck('横幅底部渐隐遮罩生效', /linear-gradient/.test(await page.$eval('#owModal .owpc-banner', el => getComputedStyle(el).webkitMaskImage)), true);
  await page.screenshot({ path: path.join(OUT, 'pc-3-card-self.png') });

  /* ---------- 3. 别人的资料卡（.ow-avatar 裸路径 + 别人设的横幅） ---------- */
  console.log('=== 3) 他人资料卡 ===');
  await page.evaluate(() => { OwChat.closeModal(); OwChat.userCard(900003); });
  await page.waitForSelector('#owModal.owpc-card', { timeout: 8000 });
  await page.waitForTimeout(400);
  ck('他人卡片也有横幅节点', await page.locator('#owModal .owpc-banner').count(), 1);
  const otherBg = await page.$eval('#owModal .owpc-banner', el => getComputedStyle(el).backgroundImage);
  ck('他人横幅按各自设置显示（banner-b）', /banner-b\.png/.test(otherBg), true);
  ck('他人头像走裸 .ow-avatar 路径', await page.locator('#owModal .ow-card-head > .ow-avatar').count(), 1);
  const avBox2 = await page.$eval('#owModal .ow-card-head > .ow-avatar', el => getComputedStyle(el).marginTop);
  ck('他人头像同样浮在封面内（无负边距）', avBox2, '0px');
  await page.screenshot({ path: path.join(OUT, 'pc-4-card-other.png') });

  /* ---------- 4. 设置里移除 → 默认白 ---------- */
  console.log('=== 4) 设置里移除横幅 → 默认白色 ===');
  await page.evaluate(() => OwChat.closeModal());
  await page.evaluate(() => OwChat.openSettings());
  await page.waitForSelector('#owpcSetBanner', { timeout: 8000 });
  await page.waitForFunction(() => {
    var rm = document.getElementById('owpcSetRemoveBtn');
    return rm && rm.style.display !== 'none';            // 现值读回、有横幅 → 移除按钮可见
  }, { timeout: 8000 });
  await page.click('#owpcSetRemoveBtn');
  ck('移除后预览无背景图', await page.$eval('#owpcSetPreview', el => getComputedStyle(el).backgroundImage), 'none');
  ck('移除后移除按钮收起', await page.locator('#owpcSetRemoveBtn').isVisible(), false);
  await page.click('#owModal .ow-btn-primary.ow-btn-block');
  await page.waitForFunction(() => document.getElementById('owModalMask').style.display === 'none'
    && window.OwPC && OwPC.dirty === false, { timeout: 8000 });
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

  /* ---------- 5. 链接设置（不走上传的路） ---------- */
  console.log('=== 5) 粘贴链接设置横幅 ===');
  await page.evaluate(() => OwChat.openSettings());
  await page.waitForSelector('#owpcSetBanner', { timeout: 8000 });
  // 等现值读回渲染完（上一节末尾横幅已移除 → 移除按钮收起）再操作，避免竞态
  await page.waitForFunction(() => {
    var rm = document.getElementById('owpcSetRemoveBtn');
    return rm && rm.style.display === 'none';
  }, { timeout: 8000 });
  await page.fill('#owpcSetUrl', 'uploads/image/pc/banner-b.png');
  await page.click('#owpcSetUrlBtn');
  ck('链接暂存生效', /banner-b\.png/.test(await page.$eval('#owpcSetPreview', el => getComputedStyle(el).backgroundImage)), true);
  await page.click('#owModal .ow-btn-primary.ow-btn-block');
  await page.waitForFunction(() => document.getElementById('owModalMask').style.display === 'none'
    && window.OwPC && OwPC.dirty === false, { timeout: 8000 });
  await page.evaluate(() => OwChat.userCard(900002));
  await page.waitForSelector('#owModal.owpc-card', { timeout: 8000 });
  ck('链接设置的横幅显示在卡片上', /banner-b\.png/.test(await page.$eval('#owModal .owpc-banner', el => getComputedStyle(el).backgroundImage)), true);
  await page.evaluate(() => OwChat.closeModal());

  /* ---------- 6. 移动端 ---------- */
  console.log('=== 6) 移动端 375px ===');
  await page.setViewportSize({ width: 375, height: 720 });
  await page.evaluate(() => OwChat.userCard(900002));
  await page.waitForSelector('#owModal.owpc-card', { timeout: 8000 });
  await page.waitForTimeout(400);
  ck('窄屏封面区 164px', Math.round(await page.$eval('#owModal .owpc-hero', el => el.getBoundingClientRect().height)), 164);
  ck('窄屏头像 56px', await page.$eval('#owModal .ow-card-head > .ow-set-avatar-btn .ow-avatar', el => getComputedStyle(el).width), '56px');
  await page.screenshot({ path: path.join(OUT, 'pc-6-card-mobile.png') });
  await page.evaluate(() => OwChat.closeModal());
  // 设置弹窗窄屏：区块可见、资料面板无横向溢出
  await page.evaluate(() => OwChat.openSettings());
  await page.waitForSelector('#owpcSetBanner', { timeout: 8000 });
  await page.waitForTimeout(400);
  const mob = await page.evaluate(() => {
    var pane = document.querySelector('.ow-pagetab-panel[data-pane="prof"]');
    var row = document.querySelector('.owpc-set-row');
    return {
      paneOverflow: pane.scrollWidth - pane.clientWidth,
      rowWrap: getComputedStyle(row).flexWrap,
      previewH: getComputedStyle(document.getElementById('owpcSetPreview')).height,
    };
  });
  ck('窄屏资料面板无横向溢出', mob.paneOverflow <= 1, true);
  ck('窄屏操作行允许换行', mob.rowWrap, 'wrap');
  ck('窄屏预览条仍是 96px', mob.previewH, '96px');
  await page.screenshot({ path: path.join(OUT, 'pc-7-settings-mobile.png') });

  /* ---------- 汇总 ---------- */
  const fails = results.filter(r => !r.ok).length;
  console.log(`\n========== DOM 结果：PASS=${results.length - fails} FAIL=${fails} ==========`);
  if (errors.length) { console.log('页面错误：'); errors.forEach(e => console.log('  ! ' + e)); }
  else console.log('页面错误：无');
  await browser.close();
  if (fails > 0 || errors.length > 0) process.exit(1);
})().catch(e => { console.error('脚本异常：', e.message); process.exit(1); });
