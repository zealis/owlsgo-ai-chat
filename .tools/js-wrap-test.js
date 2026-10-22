/**
 * 插件 JS 异常隔离验证（v1.5.27：每个插件 JS 用 IIFE + try/catch 包裹）
 * 用法：node .tools/js-wrap-test.js
 *
 * 钉的是「一错全停」这个老坑真的被堵住了：
 *   ① 合并包里每个插件 JS 都有一层 `;(function () { try { … } catch` 包装；
 *   ② 桩插件 A 在**加载阶段直接 throw**，排在它后面的桩插件 B 仍然执行（window 标记为 1）；
 *   ③ 控制台出现带插件文件名的隔离日志 `[plugin js] _jw_throw/throw.js`；
 *   ④ 整页无未捕获错误（pageerror 为空）—— throw 被 catch 接住了，不再冒到 window.onerror。
 *
 * 跑在隔离副本 .t-jw 上（php -S 8850 + 仓库 DB 快照），不碰运行副本。
 */
const { spawn, execFileSync } = require('child_process');
const http = require('http');
const fs = require('fs');
const path = require('path');
const { chromium } = require('C:/Users/zeali/.workbuddy/binaries/node/workspace/node_modules/playwright-core');

const ROOT = 'D:/project/owlsgo';
const T = path.join(ROOT, '.t-jw');
const PHP = 'D:/Program Files/phpstudy_pro/Extensions/php/php8.2.9nts/php.exe';
const PORT = 8850;
const B = 'http://127.0.0.1:' + PORT;
const out = [];
const P = (ok, n, e) => out.push((ok ? 'PASS' : 'FAIL') + ' | ' + n + (e ? ' | ' + e : ''));

function rmrf(p) { if (fs.existsSync(p)) fs.rmSync(p, { recursive: true, force: true }); }
function copyDir(src, dst) {
  fs.mkdirSync(dst, { recursive: true });
  for (const e of fs.readdirSync(src, { withFileTypes: true })) {
    const s = path.join(src, e.name), d = path.join(dst, e.name);
    if (e.isDirectory()) copyDir(s, d); else fs.copyFileSync(s, d);
  }
}
function stub(name, jsFile, jsBody) {
  const dir = path.join(T, 'plugins', name);
  fs.mkdirSync(dir, { recursive: true });
  fs.writeFileSync(path.join(dir, 'plugin.json'), JSON.stringify({ name, version: '1.0.0', description: 'js-wrap 测试桩', author: 'e2e' }));
  fs.writeFileSync(path.join(dir, 'main.php'), `<?php\n// js-wrap 测试桩：只做注册\nPlugin::asset('js', '${name}/${jsFile}');\n`);
  fs.writeFileSync(path.join(dir, jsFile), jsBody);
}

(async () => {
  rmrf(T);
  fs.mkdirSync(path.join(T, 'data', 'cache'), { recursive: true });
  fs.mkdirSync(path.join(T, 'data', 'logs'), { recursive: true });
  for (const p of ['core', 'assets', 'plugins']) copyDir(path.join(ROOT, p), path.join(T, p));
  fs.copyFileSync(path.join(ROOT, 'index.php'), path.join(T, 'index.php'));
  fs.copyFileSync(path.join(ROOT, 'index.js'), path.join(T, 'index.js'));
  fs.copyFileSync(path.join(ROOT, 'index.css'), path.join(T, 'index.css'));
  fs.copyFileSync(path.join(ROOT, 'VERSION'), path.join(T, 'VERSION'));
  fs.copyFileSync(path.join(ROOT, 'ASSET_VERSION'), path.join(T, 'ASSET_VERSION'));
  fs.writeFileSync(path.join(T, 'data', 'install.lock'), '1');

  // 桩：A 在加载阶段抛错，B 设全局标记（顺序 = 插入顺序，A 先 B 后）
  stub('_jw_throw', 'throw.js', "/* 加载阶段直接抛错 */\nthrow new Error('boom-from-jw');\n");
  stub('_jw_ok', 'ok.js', "/* 排在抛错插件之后，应该仍然执行 */\nwindow.__jwOk = 1;\n");

  // DB 快照 + 启用桩（保持插入顺序）
  execFileSync(PHP, ['-d', 'opcache.enable_cli=0', '-r',
    `$d=new PDO("sqlite:${ROOT.replace(/\//g, '/')}/data/owlsgo.db"); $d->exec("VACUUM INTO '${T.replace(/\//g, '/')}/data/owlsgo.db'");`]);
  execFileSync(PHP, ['-d', 'opcache.enable_cli=0', '-r',
    `$db=new PDO("sqlite:${T.replace(/\//g, '/')}/data/owlsgo.db");
     foreach (['_jw_throw','_jw_ok'] as $n) $db->prepare("INSERT OR REPLACE INTO plugins (name, enabled, config) VALUES (?,1,'')")->execute([$n]);`]);

  // 副本 config：trust_proxy（明文本地请求过关）+ 路由脚本（静态文件交回内置服务器）
  const cfgFile = path.join(T, 'core', 'config.php');
  fs.writeFileSync(cfgFile, fs.readFileSync(cfgFile, 'utf8').replace("'sign_window'", "'trust_proxy' => true,\n    'sign_window'"));
  fs.writeFileSync(path.join(T, 'router.php'), `<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && $path !== '/index.php' && is_file(__DIR__ . $path)) return false;
require __DIR__ . '/index.php';`);

  const srv = spawn(PHP, ['-d', 'opcache.enable_cli=0', '-S', '127.0.0.1:' + PORT, '-t', T, path.join(T, 'router.php')],
    { stdio: ['ignore','pipe','pipe'] });
  let srvLog = ''; srv.stdout.on('data', d => srvLog += d); srv.stderr.on('data', d => srvLog += d);
  await new Promise((r) => setTimeout(r, 1500));

  try {
    // ---------- ① 合并包结构 ----------
    const get = (url) => new Promise((resolve, reject) => {
      http.get(url, { headers: { 'X-Forwarded-Proto': 'https' } }, (r) => { let d = ''; r.setEncoding('utf8'); r.on('data', (c) => d += c); r.on('end', () => resolve({ status: r.statusCode, text: d })); })
        .on('error', reject);
    });
    let res;
    for (let i = 0; i < 10; i++) { try { res = await get(`${B}/?action=assets&type=js&v=x`); break; } catch (e) { await new Promise(r => setTimeout(r, 400)); } }
    if (!res) { console.log('SERVER LOG:', srvLog.slice(0, 800)); throw new Error('request failed after retries'); }
    const js = res.text;
    P(res.status === 200, '整包可取', 'HTTP ' + res.status);
    P(/;\(function \(\) \{\ntry \{/.test(js), '整包含 IIFE + try 包装');
    const a = js.indexOf('/* ---- plugin: _jw_throw/throw.js ---- */');
    const b = js.indexOf('/* ---- plugin: _jw_ok/ok.js ---- */');
    P(a > 0 && b > a, '桩 A 排在桩 B 之前', `a=${a} b=${b}`);
    P(/\[plugin js\] _jw_throw\/throw\.js/.test(js), '隔离日志带插件文件名');
    fs.writeFileSync(path.join(T, 'bundle.js'), js);

    // 整包语法自检（包装后仍是合法 JS）
    let syntaxOk = true, syntaxMsg = '';
    try { execFileSync('D:/sdk/Nodejs/node.exe', ['--check', path.join(T, 'bundle.js')], { stdio: 'pipe' }); } catch (e) { syntaxOk = false; syntaxMsg = String(e.stderr || e.message).slice(0, 200); }
    P(syntaxOk, '整包语法合法（node --check）', syntaxMsg);

    // ---------- ② 浏览器：A 抛错后 B 仍执行 ----------
    const browser = await chromium.launch({ channel: 'chrome', args: ['--no-proxy-server'] });
    const ctx = await browser.newContext({ ignoreHTTPSErrors: true, extraHTTPHeaders: { 'X-Forwarded-Proto': 'https' } });
    const page = await ctx.newPage();
    const pageErrors = [];
    const consoleErrs = [];
    page.on('pageerror', (e) => pageErrors.push(e.message));
    page.on('console', (m) => { if (m.type() === 'error') consoleErrs.push(m.text()); });

    await page.goto(B + '/?page=login', { waitUntil: 'networkidle', timeout: 30000 });
    const okFlag = await page.evaluate(() => window.__jwOk);
    P(okFlag === 1, '抛错插件之后的插件仍然执行（window.__jwOk=1）', 'got=' + okFlag);
    P(consoleErrs.some((t) => t.indexOf('[plugin js]') >= 0), '控制台有隔离日志', JSON.stringify(consoleErrs.slice(0, 2)));
    P(pageErrors.length === 0, '无未捕获错误（throw 被 catch 接住）', pageErrors.join(' / '));

    // 真实插件也都在（抽查一个已知全局对象）
    const hasReal = await page.evaluate(() => typeof window.OwChat === 'object' || typeof window.OwApi === 'object');
    P(hasReal, '核心前端对象正常', 'OwChat/OwApi');

    await browser.close();
  } catch (e) {
    console.log('--- 失败时服务端日志 ---');
    console.log(srvLog.slice(-1200));
    throw e;
  } finally {
    srv.kill();
    await new Promise((r) => setTimeout(r, 300));
    if (!process.env.KEEP) rmrf(T);
  }

  console.log(out.join('\n'));
  const bad = out.filter((l) => l.startsWith('FAIL')).length;
  console.log(bad === 0 ? 'ALL PASS' : bad + ' FAILED');
  process.exit(bad === 0 ? 0 : 1);
})().catch((e) => { console.error('SCRIPT ERROR:', e && e.stack || e); try { rmrf(T); } catch (_) {} process.exit(2); });
