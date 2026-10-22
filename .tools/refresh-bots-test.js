'use strict';
/**
 * refreshBotTags 的回归测试（markBots 登记机器人后触发的整屏重画）
 *
 *   node .tools/refresh-bots-test.js
 *
 * 覆盖这个既有 bug 与它的连带面：
 *   ① buildMessage 返回 {cls,html}，直接 appendChild 会抛 NotFoundError
 *      —— 这是 2026-10-18 前一直存在的未修 bug（登记机器人时若消息区非空必炸）
 *   ② 重画后消息条数与消息 ID 不变（不能少条、不能串 ID）
 *   ③ 机器人标签真的补上了（重画的初衷就是纠正它）
 *   ④ 日期分隔条等非消息节点原样保留
 *   ⑤ 滚动位置还回去（重画会回到顶部）
 *   ⑥ 文件消息的文件名省略要在重画后重新量宽
 *   ⑦ 消息区为空时是空转，不抛异常
 */
const fs = require('fs');
const path = require('path');

const CHAT = path.join(__dirname, '..', 'index.js');
const OUT = path.join(__dirname, 'tmp');

let pass = 0, fail = 0;
function ok(label, cond, extra) {
    if (cond) { pass++; console.log('  [OK]   ' + label); }
    else { fail++; console.log('  [FAIL] ' + label + (extra ? '  → ' + extra : '')); }
}

/**
 * 从真实 chat.js 里抠出 buildMessage 与 refreshBotTags 两个函数的源码，
 * 在最小 DOM 上跑 —— 测的是**线上那份代码**，不是复制品。
 */
function extractFunc(src, name) {
    const key = name + ': function';
    const start = src.indexOf(key);
    if (start < 0) throw new Error('chat.js 里找不到 ' + name);
    // 从 function 关键字起做括号配平，取出完整函数体
    let i = src.indexOf('function', start);
    let depth = 0, began = false;
    for (; i < src.length; i++) {
        const c = src[i];
        if (c === '{') { depth++; began = true; }
        else if (c === '}') { depth--; if (began && depth === 0) { i++; break; } }
    }
    return src.slice(src.indexOf('function', start), i);
}

(async () => {
    const { chromium } = require('C:/Users/zeali/.workbuddy/skills/birdview/node_modules/playwright-core');
    const src = fs.readFileSync(CHAT, 'utf8');
    const buildSrc = extractFunc(src, 'buildMessage');
    const refreshSrc = extractFunc(src, 'refreshBotTags');
    console.log('已从 index.js 提取 buildMessage(' + buildSrc.length + ' 字节) 与 refreshBotTags(' + refreshSrc.length + ' 字节)');

    const browser = await chromium.launch({ channel: 'chrome' });
    const page = await browser.newPage({ viewport: { width: 900, height: 600 } });
    const pageErrors = [];
    page.on('pageerror', e => pageErrors.push(e.message));

    await page.setContent(`<!DOCTYPE html><html><body>
      <div id="owMessages" style="height:300px;overflow:auto"></div>
      <style>
        .ow-file-name{display:block;width:120px;overflow:hidden;white-space:nowrap;}
      </style>
    </body></html>`);

    await page.addScriptTag({ content: `
      window.w = window;                      // chat.js 里的 window 别名
      window.$ = function (id) { return document.getElementById(id); };
      window.esc = function (s) { return String(s == null ? '' : s)
          .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); };
      window.linkifyHtml = function (s) { return s; };
      window.richParts = function () { return { text: '', media: [] }; };
      window.richHtml = function () { return ''; };
      window.fileCardHtml = function (m) {
        var info; try { info = JSON.parse(m.content); } catch (e) { info = null; }
        if (!info) return '<span class="ow-file-card">文件内容已失效</span>';
        return '<div class="ow-file-card"><span class="ow-file-name">' + esc(info.name) + '</span></div>';
      };
      window.avatarHtml = function () { return '<span class="a">头像</span>'; };
      window.jsAttr = function (s) { return "'" + String(s == null ? '' : s).replace(/'/g, "\\\\'") + "'"; };
      window.roleTag = function (role) { return window.__botUid && Number(role) === window.__botUid
          ? '<span class="tag-bot">机器人</span>' : ''; };
      window.__fitCount = 0;
      window.scheduleFitFileName = function (box) { window.__fitCount++; };
      window.__buildMessage = ${buildSrc};
      window.__refreshBotTags = ${refreshSrc};
    ` });

    // 造三条消息 + 一条日期分隔条 + 一条文件消息（msgCache）
    await page.evaluate(() => {
        const box = document.getElementById('owMessages');
        const mk = (id, type, content, role) => ({
            id: id, room: 1, uid: 10 + id, nickname: '用户' + id, role: role || 'member',
            title: '', avatar: '', type: type, content: content, quote: null,
            recalled: 0, deleted: 0, dm: 0, mine: (id === 3), ts: 1700000000 + id,
            time: '10:0' + id, date: '01-01',
        });
        window.__cache = {
            1: mk(1, 'text', '第一条'),
            2: mk(2, 'text', '第二条', 99),      // 99 是待登记的机器人 uid
            3: mk(3, 'file', JSON.stringify({ name: '一个非常非常长的文件名用来测省略.txt', size: 1, ext: 'txt', path: 'x' })),
        };
        // 先用同一套 buildMessage 渲染出初始 DOM（模拟 addMessage 已跑过）
        [1, 2, 3].forEach(id => {
            const b = window.__buildMessage(window.__cache[id]);
            const d = document.createElement('div');
            d.className = b.cls; d.id = 'owMsg' + id; d.innerHTML = b.html;
            box.appendChild(d);
            if (id === 3) box.appendChild(window.__makeDivider ? window.__makeDivider() : makeDiv());
        });
        function makeDiv() {
            const d = document.createElement('div');
            d.className = 'ow-time-divider';
            d.innerHTML = '<span>今天</span>';
            return d;
        }
        window.__cache[1].mine = false;
    });

    console.log('\n1) 重画前的初始状态');
    const before = await page.evaluate(() => ({
        msgs: document.querySelectorAll('.ow-msg').length,
        ids: Array.prototype.map.call(document.querySelectorAll('.ow-msg'), e => e.id).join(','),
        dividers: document.querySelectorAll('.ow-time-divider').length,
        texts: document.querySelector('#owMsg1 .ow-msg-content').textContent.trim(),
    }));
    ok('初始渲染出 3 条消息', before.msgs === 3, JSON.stringify(before));
    ok('日期分隔条存在', before.dividers >= 1);

    console.log('\n2) 修复前会炸的那一步（回归点）');
    const throwResult = await page.evaluate(() => {
        try {
            // 旧写法：把 buildMessage 的返回值直接 appendChild
            const f = document.createDocumentFragment();
            f.appendChild(window.__buildMessage(window.__cache[1]));
            return { threw: false };
        } catch (e) {
            return { threw: true, name: e.name, msg: e.message };
        }
    });
    ok('旧写法确实抛 TypeError（证明 bug 真实存在）',
        throwResult.threw && /Node/.test(throwResult.msg || ''),
        JSON.stringify(throwResult));

    console.log('\n3) 用修复后的 refreshBotTags 重画');
    const after = await page.evaluate(() => {
        const box = document.getElementById('owMessages');
        box.scrollTop = 40;
        const keep = box.scrollTop;
        window.__botUid = 99;                 // 登记 uid 99 为机器人
        window.__fitCount = 0;
        // refreshBotTags 依赖 this.msgCache / this.buildMessage —— 用宿主对象当 this
        window.__self = { msgCache: window.__cache, buildMessage: window.__buildMessage };
        window.__refreshBotTags.call(window.__self);
        return {
            msgs: document.querySelectorAll('.ow-msg').length,
            ids: Array.prototype.map.call(document.querySelectorAll('.ow-msg'), e => e.id).join(','),
            dividers: document.querySelectorAll('.ow-time-divider').length,
            scrollTop: box.scrollTop,
            keep: keep,
            botTag: document.querySelectorAll('#owMsg2 .tag-bot').length,
            msg1Text: document.querySelector('#owMsg1 .ow-msg-content').textContent.trim(),
            fileName: (document.querySelector('#owMsg3 .ow-file-name') || {}).textContent || '',
            fitCalled: window.__fitCount,
        };
    });
    ok('重画后仍是 3 条消息（没少条）', after.msgs === 3, JSON.stringify(after));
    ok('消息 ID 顺序不变（没串）', after.ids === before.ids, after.ids + ' vs ' + before.ids);
    ok('日期分隔条被保留', after.dividers >= 1, 'dividers=' + after.dividers);
    ok('机器人标签补上了（重画的目的）', after.botTag === 1, 'botTag=' + after.botTag);
    ok('普通消息内容未变', after.msg1Text === before.texts, after.msg1Text);
    ok('文件消息内容仍在', /非常非常长/.test(after.fileName), after.fileName);
    ok('文件名省略被重新量宽（scheduleFitFileName 有调用）', after.fitCalled > 0, 'fit=' + after.fitCalled);

    console.log('\n4) 边界：消息区为空时是空转');
    const empty = await page.evaluate(() => {
        const box = document.getElementById('owMessages');
        box.innerHTML = '';
        try { window.__refreshBotTags.call(window.__self); return { ok: true }; }
        catch (e) { return { ok: false, err: e.message }; }
    });
    ok('空消息区不抛异常', empty.ok, JSON.stringify(empty));

    console.log('\n5) 控制台无 pageerror');
    ok('全程无未捕获异常', pageErrors.length === 0, pageErrors.slice(0, 2).join(' | '));

    if (!fs.existsSync(OUT)) fs.mkdirSync(OUT, { recursive: true });
    await page.screenshot({ path: path.join(OUT, 'refresh-bots.png') });
    await browser.close();

    console.log('\n' + (fail === 0 ? `ALL PASS（${pass} 项）\n` : `${fail} FAILED / ${pass} passed\n`));
    process.exit(fail === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(1); });