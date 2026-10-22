/* ============================================================================
 * Owlsgo-Chat · Service Worker（v1.5.28）
 *
 * 由 `?action=sw` 输出（版本号在服务端替换进 __OW_SW_VERSION__），不要直接把这个文件
 * 当静态资源引用 —— 版本号必须随发布变化，否则旧缓存永远不会被清掉。
 *
 * 只做三件事，其余一律放行（**不拦页面、不拦接口**）：
 *   ① 合并包 `?action=assets&type=css|js`：缓存 5 分钟（与 HTTP 头策略一致）。
 *      为什么不永久缓存：插件资源改动不会 bump ASSET_VERSION，永久缓存会让旧 JS 赖在
 *      用户浏览器里直到下一次发版。5 分钟后回源，版内改动最多晚 5 分钟可见。
 *   ② `/uploads/` 下的图片：永久缓存。上传按内容哈希命名（sha256 前 32 位 + 2 级子目录），
 *      内容一变文件名就变，天然不可变 —— 这是「群聊图片不用反复下载」的关键。
 *   ③ 带 `?v=` 的核心静态文件（index.css / index.js / assets/js/i18n.js）：
 *      永久缓存；URL 里的版本变了就是新 URL，旧条目随下次 activate 清掉。
 *
 * 明确不缓存的东西（宁可多一次请求，也不能错）：
 *   · 任何 HTML 页面 —— 页面内嵌会话密钥与签名时间戳，缓存旧页面会导致提交签名对不上；
 *   · 任何 `?action=` 接口（assets 除外）—— 有副作用、要签名、长轮询；
 *   · 非 GET（POST 上传/发言/删除）与带 Range 的请求（音视频拖动会拿到整段而报错）；
 *   · 跨域请求（头像可能走 dicebear 等外站，交给浏览器自己的缓存）。
 *
 * 缓存按版本命名：activate 时删掉所有非当前名的 cache，所以一次发版 = 一次全量更新。
 * 紧急停用：后台「刷新数据缓存」不会清 SW 缓存；需要用户在浏览器「清除网站数据」，
 * 或把服务端 `?action=sw` 暂时 404（浏览器会在下次导航时移除注册）。
 * ========================================================================== */
'use strict';

const VERSION = '__OW_SW_VERSION__';
const CACHE = 'ow-static-' + VERSION;
const ASSET_TTL = 5 * 60 * 1000;   // 合并包缓存时长（毫秒）

self.addEventListener('install', (event) => {
    // 立即接管，不等旧标签页全部关闭（资源都带版本，立刻切换是安全的）
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const names = await caches.keys();
        await Promise.all(names.filter((n) => n !== CACHE).map((n) => caches.delete(n)));
        await self.clients.claim();
    })());
});

const IMG_RE = /\.(png|jpe?g|webp|gif|ico)$/i;
const CORE_RE = /^\/(index\.css|index\.js|assets\/js\/i18n\.js)$/;

/** 决定一个请求怎么处理：'ttl' | 'immutable' | 'bypass' */
function decide(url, req) {
    if (url.origin !== self.location.origin) return 'bypass';
    if (req.headers.has('range')) return 'bypass';          // 音视频拖动
    const act = url.searchParams.get('action') || '';
    if (act === 'assets') return 'ttl';                     // 合并包
    if (act !== '') return 'bypass';                        // 页面端点 / 接口 / sw 自身
    if (url.pathname.startsWith('/uploads/')) {
        return IMG_RE.test(url.pathname) ? 'immutable' : 'bypass';
    }
    if (CORE_RE.test(url.pathname)) {
        return url.searchParams.get('v') ? 'immutable' : 'bypass';   // 无版本号不缓存
    }
    return 'bypass';                                        // 页面、其它静态资源
}

/** 永久缓存：命中即返回，未命中回源并留存 */
async function immutable(req) {
    const cache = await caches.open(CACHE);
    const hit = await cache.match(req, { ignoreSearch: false });
    if (hit) return hit;
    const resp = await fetch(req);
    if (resp && resp.ok) cache.put(req, resp.clone()).catch(() => {});
    return resp;
}

/** 合并包：5 分钟内直接用缓存，过期回源；离线时退回旧缓存 */
async function ttl(req) {
    const cache = await caches.open(CACHE);
    const hit = await cache.match(req);
    if (hit) {
        const at = Number(hit.headers.get('x-sw-ts') || '0');
        if (at > 0 && Date.now() - at < ASSET_TTL) return hit;
    }
    try {
        const resp = await fetch(req);
        if (resp && resp.ok) {
            const headers = new Headers(resp.headers);
            headers.set('x-sw-ts', String(Date.now()));
            // clone 后再读：原响应要原样交给页面
            const body = await resp.clone().blob();
            cache.put(req, new Response(body, { status: resp.status, statusText: resp.statusText, headers }))
                .catch(() => {});
        }
        return resp;
    } catch (e) {
        if (hit) return hit;          // 断网/服务不可用：有旧的先用旧的
        throw e;
    }
}

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;                 // POST 等一律不拦
    const url = new URL(req.url);
    const mode = decide(url, req);
    if (mode === 'bypass') return;                    // 交给浏览器，SW 不介入
    event.respondWith(mode === 'immutable' ? immutable(req) : ttl(req));
});
