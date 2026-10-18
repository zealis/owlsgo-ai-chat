/**
 * 测试专用 HTTPS 轮转代理（隔离副本 .t-pc 专用，绝不用于运行副本）：
 *   ① php -S 是单进程，聊天页 ?action=poll 长轮询会把它堵死 → 轮转 3 个后端分担；
 *   ② 站点强制 HTTPS（ Sec::enforceHttps ），浏览器必须走 https 才会渲染
 *      https:// 的资源 URL（头像 / 横幅图）→ 本代理终结 TLS，转发时补
 *      X-Forwarded-Proto: https（代理自身是本机 peer，服务端认这个头）。
 * 端口：https://127.0.0.1:8845
 */
const https = require('https');
const http = require('http');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const BACKENDS = [8851, 8852, 8853];
const inflight = {}; BACKENDS.forEach(p => { inflight[p] = 0; });
let rr = 0;

// 自签证书自举：缺 pem 时用 openssl 现生成（SAN 带 127.0.0.1/localhost），不入库
const KEY = path.join(__dirname, 'pc-test-key.pem');
const CERT = path.join(__dirname, 'pc-test-cert.pem');
if (!fs.existsSync(KEY) || !fs.existsSync(CERT)) {
  execSync(`openssl req -x509 -newkey rsa:2048 -keyout "${KEY}" -out "${CERT}" -days 30 -nodes`
    + ' -subj "/CN=127.0.0.1" -addext "subjectAltName=IP:127.0.0.1,DNS:localhost"', { stdio: 'ignore' });
  console.log('已生成自签证书（仅测试用）');
}
/** 挑一个后端：在途最少的优先；并列才轮转。长轮询会占住一个后端，此策略直接绕开它 */
function pick() {
  let best = BACKENDS[0];
  for (const p of BACKENDS) if (inflight[p] < inflight[best]) best = p;
  if (inflight[best] > 0) { best = BACKENDS[(rr++) % BACKENDS.length]; }   // 全都忙 → 轮转兜底
  return best;
}
https.createServer({
  key: fs.readFileSync(path.join(__dirname, 'pc-test-key.pem')),
  cert: fs.readFileSync(path.join(__dirname, 'pc-test-cert.pem')),
}, (req, res) => {
  const port = pick();
  const headers = Object.assign({}, req.headers, { 'x-forwarded-proto': 'https' });
  inflight[port]++;
  let done = false;
  const release = () => { if (!done) { done = true; inflight[port]--; } };
  res.on('finish', release); res.on('close', release);
  const p = http.request({
    host: '127.0.0.1', port: port,
    path: req.url, method: req.method,
    headers: headers,                 // Host / Cookie / 签名原样透传
  }, br => {
    res.writeHead(br.statusCode, br.headers);
    br.pipe(res);
  });
  p.on('error', e => { release(); res.writeHead(502, { 'Content-Type': 'text/plain' }); res.end('proxy error: ' + e.message); });
  req.pipe(p);
}).listen(8845, '127.0.0.1', () => console.log('pc-test-proxy https://127.0.0.1:8845 ->', BACKENDS.join(', ')));
