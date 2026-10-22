/* ============================================================================
 * 微动效验收（v1.5.33）
 *
 * 这一版只做三处入场动画，刻意「最克制」：
 *   新消息气泡（不含历史批量）/ 弹窗 / Toast —— 时长 140~180ms、位移 ≤4px、缩放 ≥.985。
 * 本脚本钉住四件事：
 *   ① 首屏历史批量**不**播（30 条一起动 = 抽搐）；
 *   ② 新来的单条消息、弹窗、Toast 各自挂对类名与动画；
 *   ③ 动画不改变布局（transform 动画期间几何仍与终态一致）；
 *   ④ 系统开「减少动态效果」时动画全关（prefers-reduced-motion）。
 *
 * 用法：node .tools/motion-check.js
 * ========================================================================== */
const { chromium } = require('C:/Users/zeali/.workbuddy/binaries/node/workspace/node_modules/playwright-core');

const BASE = process.env.OWL_BASE || 'https://localhost';
let pass = 0, fail = 0;
const ok = (m, extra) => { pass++; console.log('  [PASS] ' + m + (extra ? '  ' + extra : '')); };
const bad = (m, extra) => { fail++; console.log('  [FAIL] ' + m + (extra ? '  ' + extra : '')); };
const ck = (m, cond, extra) => (cond ? ok : bad)(m, extra);

/** 读某个元素的动画信息 */
const animOf = (sel) => `
  (function () {
    var el = document.querySelector(${JSON.stringify(sel)});
    if (!el) return null;
    var cs = getComputedStyle(el);
    var list = el.getAnimations ? el.getAnimations() : [];
    return {
      cls: el.className,
      name: cs.animationName,
      dur: cs.animationDuration,
      timing: cs.animationTimingFunction,
      hasAnim: list.length > 0,
      keyframes: list.length ? list[0].animationName : ''
    };
  })()`;

async function open(browser, opts) {
  const ctx = await browser.newContext(Object.assign({
    viewport: { width: 1440, height: 900 },
    ignoreHTTPSErrors: true,
    serviceWorkers: 'block',
  }, opts || {}));
  const page = await ctx.newPage();
  const errs = [];
  page.on('pageerror', (e) => errs.push(e.message));
  await page.goto(BASE + '/', { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForTimeout(2200);
  return { ctx, page, errs };
}

(async () => {
  const browser = await chromium.launch({
    channel: 'chrome',
    args: ['--ignore-certificate-errors', '--no-proxy-server'],
  });

  console.log('== ① 首屏历史批量：一个都不该动 ==');
  {
    const { ctx, page, errs } = await open(browser);
    const r = await page.evaluate(() => {
      const mk = (id) => ({
        id: id, room_id: OwChat.room, user_id: 1, nickname: '历史',
        role: 'member', type: 'text', content: '历史消息', created_at: Math.floor(Date.now() / 1000) - 60,
      });
      // batch=true 就是首屏 / 向上翻页的批量渲染路径
      OwChat.addMessage(mk(999999101), true);
      OwChat.addMessage(mk(999999102), true);
      return {
        total: document.querySelectorAll('.ow-msg').length,
        animated: document.querySelectorAll('.ow-msg.is-in').length,
        a2: document.getElementById('owMsg999999101').className,
        a3: document.getElementById('owMsg999999102').className,
      };
    });
    ck('批量渲染出消息', r.total >= 2, '消息数=' + r.total);
    ck('没有一条带 is-in（批量渲染排除动效）', r.animated === 0, 'animated=' + r.animated);
    ck('batch 消息类名不含 is-in', r.a2.indexOf('is-in') < 0 && r.a3.indexOf('is-in') < 0, r.a2 + ' / ' + r.a3);
    ck('无未捕获错误', errs.length === 0, errs.slice(0, 1).join(''));

    console.log('== ② 新到的单条消息：上浮淡入 ==');
    const a = await page.evaluate(async (sel) => {
      // 直接调用渲染管线：id 用一个不会与真实消息冲突的大数
      OwChat.addMessage({
        id: 999999001, room_id: OwChat.room, user_id: 1, nickname: '动效测试',
        role: 'member', type: 'text', content: '微动效验收', created_at: Math.floor(Date.now() / 1000),
      });
      const el = document.querySelector('#owMsg999999001');
      if (!el) return null;
      const cs = getComputedStyle(el);
      const list = el.getAnimations ? el.getAnimations() : [];
      const before = el.getBoundingClientRect();
      return {
        cls: el.className, name: cs.animationName, dur: cs.animationDuration,
        n: list.length, kf: list.length ? list[0].animationName : '',
        w: Math.round(before.width), h: Math.round(before.height),
      };
    });
    ck('新消息挂上 is-in', !!a && a.cls.indexOf('is-in') >= 0, a && a.cls);
    ck('动画名 = owFadeUp', a && a.name === 'owFadeUp', a && a.name);
    ck('时长 0.16s（不做 1s 的长动效）', a && a.dur === '0.16s', a && a.dur);
    ck('确实在播（getAnimations 非空）', a && a.n > 0, a && ('n=' + a.n + ' kf=' + a.kf));

    // 几何：transform 不动布局，动画期间高度应等于终态
    await page.waitForTimeout(260);
    const after = await page.evaluate((sel) => {
      const el = document.querySelector('#owMsg999999001');
      if (!el) return null;
      const cs = getComputedStyle(el);
      const b = el.getBoundingClientRect();
      return { h: Math.round(b.height), transform: cs.transform, n: el.getAnimations().length };
    });
    ck('动画结束后没有残留 transform（不建立新的包含块）',
      !!after && (after.transform === 'none' || after.transform === 'matrix(1, 0, 0, 1, 0, 0)'), after && after.transform);
    ck('高度与动画期间一致（布局没被推动）', !!after && after.h === a.h, a.h + ' → ' + (after && after.h));

    console.log('== ③ 弹窗：淡入 + 极小缩放 ==');
    const m = await page.evaluate((sel) => {
      OwChat.openModal('<h3>动效测试</h3><p>弹窗入场</p>', 340);
      return eval(sel);
    }, animOf('#owModal'));
    ck('弹窗挂上 is-in', !!m && m.cls.indexOf('is-in') >= 0, m && m.cls);
    ck('动画名 = owPopIn', m && m.name === 'owPopIn', m && m.name);
    ck('时长 0.18s', m && m.dur === '0.18s', m && m.dur);
    await page.evaluate(() => OwChat.closeModal());

    console.log('== ④ Toast：只淡入（不动 transform，否则会挪位） ==');
    const t = await page.evaluate((sel) => { toast('动效测试'); return eval(sel); }, animOf('#owToast'));
    ck('Toast 挂上 is-in', !!t && t.cls.indexOf('is-in') >= 0, t && t.cls);
    ck('动画名 = owFadeIn', t && t.name === 'owFadeIn', t && t.name);
    // 真实场景：上一条还在显示（不手动隐藏），且它的动画已经播完（>140ms）后再提示
    await page.waitForTimeout(600);
    const t2 = await page.evaluate((sel) => { toast('第二条'); return eval(sel); }, animOf('#owToast'));
    ck('连续 toast 会重播（前一条已播完、未隐藏）', !!t2 && t2.hasAnim === true,
      t2 && ('hasAnim=' + t2.hasAnim + ' name=' + t2.name));
    ck('文案已换成第二条', await page.evaluate(() => document.getElementById('owToast').textContent === '第二条'));
    ck('无未捕获错误', errs.length === 0, errs.slice(0, 1).join(''));
    await ctx.close();
  }

  console.log('== ⑤ 减少动态效果：动画必须全关 ==');
  {
    const { ctx, page } = await open(browser, { reducedMotion: 'reduce' });
    const r = await page.evaluate(() => {
      OwChat.addMessage({
        id: 999999002, room_id: OwChat.room, user_id: 1, nickname: '动效测试',
        role: 'member', type: 'text', content: '降低动态', created_at: Math.floor(Date.now() / 1000),
      });
      OwChat.openModal('<h3>减少动态</h3>', 340);
      const g = (sel) => {
        const el = document.querySelector(sel);
        if (!el) return null;
        const cs = getComputedStyle(el);
        return { name: cs.animationName, n: el.getAnimations ? el.getAnimations().length : 0 };
      };
      toast('减少动态');
      return { msg: g('#owMsg999999002'), modal: g('#owModal'), toast: g('#owToast') };
    });
    ck('消息动画被关掉', r.msg && (r.msg.name === 'none' || r.msg.n === 0), r.msg && ('name=' + r.msg.name + ' n=' + r.msg.n));
    ck('弹窗动画被关掉', r.modal && (r.modal.name === 'none' || r.modal.n === 0), r.modal && ('name=' + r.modal.name + ' n=' + r.modal.n));
    ck('Toast 动画被关掉', r.toast && (r.toast.name === 'none' || r.toast.n === 0), r.toast && ('name=' + r.toast.name + ' n=' + r.toast.n));
    await ctx.close();
  }

  console.log('== ⑥ 移动视口（375×667）：动效不制造横向溢出 ==');
  {
    const { ctx, page } = await open(browser, { viewport: { width: 375, height: 667 } });
    const r = await page.evaluate(() => {
      OwChat.addMessage({
        id: 999999003, room_id: OwChat.room, user_id: 1, nickname: '动效测试',
        role: 'member', type: 'text', content: '窄屏微动效', created_at: Math.floor(Date.now() / 1000),
      });
      const el = document.getElementById('owMsg999999003');
      const cs = getComputedStyle(el);
      return {
        name: cs.animationName,
        overflowX: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      };
    });
    ck('窄屏同样播 owFadeUp', r.name === 'owFadeUp', r.name);
    ck('无横向溢出（translateY 不改宽度）', r.overflowX <= 0, 'overflowX=' + r.overflowX + 'px');
    await ctx.close();
  }

  await browser.close();
  console.log('');
  console.log(fail === 0 ? `ALL PASS (${pass} 项)` : `${fail} 项失败，${pass} 项通过`);
  process.exit(fail === 0 ? 0 : 1);
})().catch((e) => { console.error('脚本异常:', e.message); process.exit(1); });
