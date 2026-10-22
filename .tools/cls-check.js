/* ============================================================================
 * 图片消息 CLS 验收（v1.5.36）
 *
 * 问题：消息里的图片没有宽高属性，下载前高度为 0，加载完成时把下方消息推着走
 *       （Cumulative Layout Shift）——聊天这种「边滚边来图」的场景尤其明显。
 * 修法：服务端发送时用 getimagesize 读自然宽高存进 messages.img_w/img_h，
 *       pack() 以 iw/ih 下发，前端只在两者都 >0 时给 <img> 补 width/height。
 *       有属性 + .ow-msg-img 的 max-width/max-height → 浏览器在下载前就按比例预留位置。
 *
 * 本脚本的判据不是「属性在不在」，而是**下方消息的 Y 坐标在图片加载前后是否移动**：
 *   · 带 iw/ih 的消息：Y 不变（预留生效）
 *   · 不带 iw/ih 的对照消息：Y 变大（真的抖了）
 * 用 route 把图片响应掐住 1.5 秒，保证「加载前」那一刻是稳定可测的。
 *
 * 用法：node .tools/cls-check.js
 * ========================================================================== */
const { chromium } = require('C:/Users/zeali/.workbuddy/binaries/node/workspace/node_modules/playwright-core');

const BASE = process.env.OWL_BASE || 'https://localhost';
const IMG = '/uploads/image/cls-browser.jpg';   // 400×300，由 PHP GD 生成
let pass = 0, fail = 0;
const ok = (m, extra) => { pass++; console.log('  [PASS] ' + m + (extra ? '  ' + extra : '')); };
const bad = (m, extra) => { fail++; console.log('  [FAIL] ' + m + (extra ? '  ' + extra : '')); };
const ck = (m, cond, extra) => (cond ? ok : bad)(m, extra);

(async () => {
  const browser = await chromium.launch({
    channel: 'chrome', args: ['--ignore-certificate-errors', '--no-proxy-server'],
  });
  const ctx = await browser.newContext({
    viewport: { width: 1440, height: 900 },
    ignoreHTTPSErrors: true,
    serviceWorkers: 'block',
  });
  const page = await ctx.newPage();
  const errs = [];
  page.on('pageerror', (e) => errs.push(e.message));

  // 图片响应统一掐 1.5 秒：确保「加载前」那一刻能稳定测量
  await page.route('**/uploads/image/cls-browser.jpg*', async (route) => {
    await new Promise((r) => setTimeout(r, 1500));
    await route.continue();
  });

  await page.goto(BASE + '/?room=1', { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForTimeout(2500);

  const r = await page.evaluate(async (imgUrl) => {
    const box = document.getElementById('owMessages');
    const mk = (id, iw, ih, url) => ({
      id: id, room_id: OwChat.room, user_id: 1, nickname: 'CLS测试',
      role: 'member', type: 'image', content: url || imgUrl,
      iw: iw, ih: ih,
      created_at: Math.floor(Date.now() / 1000),
    });
    // 判据 = 标尺的 offsetTop（布局位置，**不受消息区内部滚动影响**）。
    // ⚠️ 不要用「图片底 → 标尺顶的间距」当判据：图片一旦撑高，它和下方标尺是一起
    //    下移的，间距恒定不变 —— 那是假阴性（对照组就是这么骗过第一版的）。
    // ⚠️ 也不要用 getBoundingClientRect().top：消息区滚动会让绝对坐标抖几像素。
    const topOf = (sel) => {
      const el = box.querySelector(sel);
      return el ? el.offsetTop : null;
    };
    // ⚠️ 必须限定 img.ow-msg-img：消息气泡里第一个 <img> 是**头像**
    // （40×40 的 base64 占位图），拿它测会读到 width=null / rectW=40 / natural 5×5。
    const probe = (sel) => {
      const img = box.querySelector(sel + ' img.ow-msg-img');
      if (!img) return null;
      return {
        w: img.getAttribute('width'), h: img.getAttribute('height'),
        rectW: Math.round(img.getBoundingClientRect().width),
        rectH: Math.round(img.getBoundingClientRect().height),
      };
    };

    // ① 带 iw/ih（本次改动的目标形态）
    OwChat.addMessage(mk(910000001, 400, 300));
    const selA = '#owMsg910000001';
    const aBefore = probe(selA);
    // 下面垫一条文本消息，用来观测「它有没有被推着走」
    OwChat.addMessage({ id: 910000002, room_id: OwChat.room, user_id: 1, nickname: 'CLS测试',
      role: 'member', type: 'text', content: '下面这条是标尺', created_at: Math.floor(Date.now() / 1000) });
    const rulerA = '#owMsg910000002';
    const yBeforeA = topOf(rulerA);

    // ② 对照组：同一条图片，但不带 iw/ih（老消息 / 云端文件的形态）。
    //    URL 必须换一个（加 query）：同一条地址第二条会命中浏览器缓存、立刻加载完成，
    //    那就看不出抖动了 —— 对照组要的正是「慢慢加载」的那个时刻。
    OwChat.addMessage(mk(910000003, 0, 0, imgUrl + '?ctrl=1'));
    const selB = '#owMsg910000003';
    const bBefore = probe(selB);
    OwChat.addMessage({ id: 910000004, room_id: OwChat.room, user_id: 1, nickname: 'CLS测试',
      role: 'member', type: 'text', content: '对照标尺', created_at: Math.floor(Date.now() / 1000) });
    const rulerB = '#owMsg910000004';
    const yBeforeB = topOf(rulerB);

    // 等图片真正加载完（route 里掐了 1.5s）
    await new Promise((res) => setTimeout(res, 2600));

    return {
      aBefore, aAfter: probe(selA), yBeforeA, yAfterA: topOf(rulerA),
      bBefore, bAfter: probe(selB), yBeforeB, yAfterB: topOf(rulerB),
      naturalA: (() => { const i = box.querySelector(selA + ' img.ow-msg-img'); return i ? [i.naturalWidth, i.naturalHeight] : null; })(),
    };
  }, IMG);

  console.log('== ① 带 iw/ih 的消息：预留位置 ==');
  ck('img 有 width=400 / height=300 属性', r.aBefore && r.aBefore.w === '400' && r.aBefore.h === '300',
    r.aBefore && ('w=' + r.aBefore.w + ' h=' + r.aBefore.h));
  ck('加载前就已按比例占位（240×180，未超过 max 限制）',
    r.aBefore && r.aBefore.rectH > 100 && r.aBefore.rectH < 200, r.aBefore && JSON.stringify(r.aBefore));
  ck('标尺的 offsetTop 不变（没有 CLS）', r.yBeforeA === r.yAfterA, r.yBeforeA + ' → ' + r.yAfterA);

  console.log('== ② 对照组（无 iw/ih）：仍然会抖 ==');
  ck('对照组 img 无宽高属性', r.bBefore && r.bBefore.w === null && r.bBefore.h === null,
    r.bBefore && ('w=' + r.bBefore.w + ' h=' + r.bBefore.h));
  ck('对照组 offsetTop 被推大（证明抖动真实存在，测试不是空转）',
    r.yAfterB > r.yBeforeB, r.yBeforeB + ' → ' + r.yAfterB);

  console.log('== ③ 图片本身正常 ==');
  ck('自然尺寸 400×300（GD 造图正确）', r.naturalA && r.naturalA[0] === 400 && r.naturalA[1] === 300,
    r.naturalA && r.naturalA.join('×'));
  ck('加载后显示尺寸不变（预留与最终一致）', r.aAfter && r.aAfter.rectH === r.aBefore.rectH,
    r.aBefore && r.aAfter && (r.aBefore.rectH + ' → ' + r.aAfter.rectH));
  ck('无未捕获 JS 错误', errs.length === 0, errs.slice(0, 1).join(''));

  await browser.close();
  console.log('');
  console.log(fail === 0 ? `ALL PASS (${pass} 项)` : `${fail} 项失败，${pass} 项通过`);
  process.exit(fail === 0 ? 0 : 1);
})().catch((e) => { console.error('脚本异常:', e.message); process.exit(1); });
