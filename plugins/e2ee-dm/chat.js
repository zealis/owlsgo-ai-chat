/*!
 * 加密私聊 —— 前端接线
 *
 * 三件事：
 *   1. 密钥本机保存（IndexedDB），私钥从不出浏览器；
 *   2. 发送前把文字换成密文信封（包裹 OwChat.send，只动私聊文本）；
 *   3. 渲染后把密文换回明文（MutationObserver 扫 #owMessages 里带 .private 的气泡）。
 *
 * 为什么用「包一层 + 观察 DOM」而不是改核心：
 *   核心没有「发送前改写正文」和「渲染前替换正文」的钩子，加钩子要动 chat.js ——
 *   而 chat.js 是全站最大的单文件，任何改动都要全量回归。插件用扩展点 + DOM 观察
 *   把风险关在本文件里，停用插件即完全失效。
 *
 * 已知取舍（v1）：
 *   - 只加密文字。加密会话里发图片/文件会被拦下（它们会以明文落库）。
 *   - 单设备：换设备要重新登记，旧设备此前的密文无法再解。
 *   - 密文在渲染后替换，理论上有一帧「密文闪现」；实际是先渲染密文再替换，
 *     同一帧内完成，肉眼看不到。
 */
(function () {
    'use strict';
    var W = window;
    var started = false;
    var unsupported = false;   // WebCrypto 不可用（http 访问 / 老浏览器）

    var DBNAME = 'owee', STORE = 'kv';
    var keys = null;                 // {devId, identity, spk, opks, opkNext}
    var sessions = {};               // peerUid -> 我发起的会话（发送用）
    var recv = {};                   // peerUid -> 我接收的会话（解密对方消息用）
    var convState = {};              // peerUid -> true/false（会话是否已开启加密）
    var mode = null;                 // off | optin
    var dbp = null;                  // IndexedDB 连接
    var pending = {};                // 并发解密去重
    var plainCache = {};              // envKey -> 本机明文（渲染「发送中」气泡时替换密文）

    /* ===================== 存储 ===================== */

    function db() {
        if (dbp) return dbp;
        dbp = new Promise(function (res, rej) {
            if (!W.indexedDB) return rej(new Error('浏览器不支持本地存储'));
            var r = W.indexedDB.open(DBNAME, 1);
            var settled = false;
            // onblocked：别的标签页正开着更高版本的库，open 既不 success 也不 error ——
            // 不兜底的话下面整条 Promise 永远挂着，用户点了永远没反应（最难查的那种哑巴）。
            // 超时兜底同因：某些隐私模式下 IndexedDB 会既不回调也不报错。
            var bail = setTimeout(function () {
                if (settled) return;
                settled = true;
                dbp = null;                    // 别让一次偶发失败把整个会话的存储判死
                rej(new Error('本机密钥存储无响应（可能是无痕 / 隐私窗口，或另一个标签页正在占用）'));
            }, 4000);
            var done = function (fn, v) {
                if (settled) return;
                settled = true;
                clearTimeout(bail);
                if (fn === rej) dbp = null;    // 同上：失败后允许下次重开
                fn(v);
            };
            r.onupgradeneeded = function () { r.result.createObjectStore(STORE); };
            r.onsuccess = function () { done(res, r.result); };
            r.onerror = function () { done(rej, r.error || new Error('无法打开本机密钥存储')); };
            r.onblocked = function () { done(rej, new Error('本机密钥存储被另一个标签页占用，请关掉本页重试')); };
        });
        return dbp;
    }
    function idbGet(k) {
        return db().then(function (d) {
            return new Promise(function (res) {
                var t = d.transaction(STORE, 'readonly').objectStore(STORE).get(k);
                t.onsuccess = function () { res(t.result || null); };
                t.onerror = function () { res(null); };
            });
        });
    }
    function idbSet(k, v) {
        return db().then(function (d) {
            return new Promise(function (res, rej) {
                var tx = d.transaction(STORE, 'readwrite');
                tx.objectStore(STORE).put(v, k);
                tx.oncomplete = function () { res(v); };
                tx.onerror = function () { rej(tx.error); };
            });
        });
    }

    /* ===================== 网络 ===================== */

    function api(action, data) {
        return new Promise(function (res) {
            W.OwApi.post(action, data || {}, function (r) { res(r || {}); });
        });
    }

    /**
     * 取一次站点状态。
     *
     * ⚠ 两个致命细节，都是站方那次「点了没用」的真凶：
     *   ① **必须判 r.ok**。接口失败时核心返回的是 `{ok:false,msg:'签名验证失败…'}`，
     *      没有 mode 字段 —— 旧代码 `mode = r.mode || 'off'` 会把「请求失败」
     *      直接翻译成「站点关闭了」，于是功能在浏览器里静默自我关闭：
     *      巡检不启动、右键菜单不注册、按钮永远摆在那儿点了没反应。
     *   ② **不能在这里吞掉异常**。首屏这个 XHR 最容易被长轮询挤成 403，
     *      失败必须往上抛，由 init 决定重试，而不是假装一切正常。
     */
    function boot() {
        return api('plugin_e2ee_dm_state', {}).then(function (r) {
            if (!r || r.ok !== true) throw new Error((r && r.msg) || '取站点状态失败');
            mode = r.mode === 'optin' ? 'optin' : 'off';
            return r;
        });
    }

    /* ===================== 密钥 ===================== */

    function loadKeys() {
        if (keys) return Promise.resolve(keys);
        return idbGet('keys').then(function (k) { keys = k || null; return keys; });
    }

    /** 首次启用：生成本设备密钥并登记（会作废旧设备，调用前必须确认） */
    function createKeys() {
        var id, spk, opks, devId, kid;
        id = null;
        return OWEE.genIdentity().then(function (r) {
            id = r;
            kid = Math.floor(Date.now() / 1000);
            return OWEE.genSignedPrekey(id, kid);
        }).then(function (s) {
            spk = s;
            return OWEE.genOneTimePrekeys(1, 20);
        }).then(function (o) {
            opks = o;
            return OWEE.deviceId(id.dhPub);
        }).then(function (d) {
            devId = d;
            return api('plugin_e2ee_dm_register', {
                ik_dh: id.dhPub, ik_sig: id.sigPub,
                spk: spk.pub, spk_id: spk.id, spk_sig: spk.sig,
                opks: JSON.stringify(opks.map(function (x) { return { id: x.id, pub: x.pub }; })),
                dev_name: (W.navigator && W.navigator.platform ? W.navigator.platform : '') + ''
            });
        }).then(function (r) {
            if (!r.ok) throw new Error(r.msg || '登记密钥失败');
            keys = { devId: r.dev_id || devId, identity: id, spk: spk, opks: opks, opkNext: 21 };
            // 私钥只落本机；opks 里带私钥，服务端只收到公钥
            return idbSet('keys', keys);
        }).then(function () { return keys; });
    }

    /* ===================== 会话 ===================== */

    /**
     * 两条会话，不是一个会话：
     *   sessions[peer] —— 我**发起**的会话（我点开启时建立），用来给对方发消息；
     *   recv[peer]     —— 我**接收**的会话（从对方信封的握手字段重算），用来解对方的消息。
     * 为什么必须分开：X3DH 是有方向的，A 拿 B 的密钥包建会话、B 也拿 A 的密钥包建会话，
     * 得到的是两条互不相干的棘轮。若只留一个槽位，后建的那个会把前一个覆盖掉 ——
     * 表现为「我自己发的消息对方收得到，对方发的我全解不开」。
     */
    function saveSessions() {
        var out = { send: {}, recv: {} }, k;
        for (k in sessions) if (sessions.hasOwnProperty(k)) out.send[k] = sessions[k];
        for (k in recv) if (recv.hasOwnProperty(k)) out.recv[k] = recv[k];
        return idbSet('sessions', out);
    }

    function loadSessions() {
        return idbGet('sessions').then(function (s) {
            s = s || {};
            // 兼容旧格式（曾经把两种会话混在一个对象里）：整体当作发送会话，
            // 接收会话由下一条带握手字段的消息重建。
            sessions = s.send || (s.recv ? {} : (s.peer ? {} : s));
            recv = s.recv || {};
            return sessions;
        });
    }

    /** 发起方：取对方密钥包 → X3DH → 建棘轮 */
    function startSession(peerId) {
        var bundle = null, ek = null;
        return api('plugin_e2ee_dm_bundle', { peer_id: peerId }).then(function (r) {
            if (!r.ok) throw new Error(r.msg || '对方还没有启用加密私聊');
            bundle = r.bundle;
            return OWEE.genDhPair();
        }).then(function (e) {
            ek = e;
            return OWEE.x3dhInit(keys.identity, ek, bundle);
        }).then(function (res) {
            return OWEE.initAlice(res.sk, res.ad, bundle.spk).then(function (st) {
                var s = {
                    peer: String(peerId), peerDev: bundle.devId, peerIk: bundle.ikDh,
                    ad: res.ad, state: st, ekPub: ek.pub,
                    setup: { spk: bundle.spkId, opk: res.opkId },
                    confirmed: false
                };
                sessions[String(peerId)] = s;
                return saveSessions().then(function () { return s; });
            });
        });
    }

    /** 收方：从信封里的握手字段本地重算 X3DH（对方发起的第一条消息才带这些字段） */
    function adoptSession(env, peerId) {
        if (!env.si || !env.ek) return Promise.reject(new Error('缺少会话建立信息'));
        var spk = keys.spk;
        var opk = null, i;
        if (env.opk) {
            for (i = 0; i < keys.opks.length; i++) if (keys.opks[i].id === env.opk) { opk = keys.opks[i]; break; }
            if (!opk) return Promise.reject(new Error('一次性预密钥已失效（可能已被使用过）'));
        }
        if (keys.spk.id !== env.spk) return Promise.reject(new Error('预密钥编号不匹配'));
        return OWEE.x3dhRespond(keys.identity, spk, opk, env.si, env.ek).then(function (r) {
            return OWEE.initBob(r.sk, r.ad, spk).then(function (st) {
                var s = {
                    peer: String(peerId), peerDev: env.s || '', peerIk: env.si,
                    ad: r.ad, state: st, ekPub: null, setup: null, confirmed: true
                };
                recv[String(peerId)] = s;
                return saveSessions().then(function () { return s; });
            });
        });
    }

    function buildEnvelope(msg, sess) {
        var env = { v: 1, s: keys.devId, m: {} };
        env.m[sess.peerDev] = { h: msg.header, c: msg.ct };
        if (!sess.confirmed && sess.ekPub && sess.setup) {
            // 握手字段只在「对方还没回过话」时附带：让对端能在本机重算 X3DH
            env.si = keys.identity.dhPub;
            env.ek = sess.ekPub;
            env.spk = sess.setup.spk;
            env.opk = sess.setup.opk;
        }
        return OWEE.seal(env);
    }

    /* ===================== 状态查询 ===================== */

    function ensureConvState(peerId) {
        var k = String(peerId);
        if (convState[k] !== undefined) return Promise.resolve(convState[k]);
        return api('plugin_e2ee_dm_state', { peer_id: peerId }).then(function (r) {
            convState[k] = !!r.conv_enabled;
            return convState[k];
        }).catch(function () { return false; });
    }

    /**
     * 会话切换时做一次对账：开关状态 + 对方设备号。
     * 为什么必须对账：对方一旦换了设备，我们本地还挂着旧会话，
     * 会继续把每条消息加密给一台服务端已经不认的设备 ——
     * 新设备一条也收不到，旧设备反而全读得到（方向正好反了）。
     */
    function syncConv(peerId) {
        var k = String(peerId);
        return api('plugin_e2ee_dm_state', { peer_id: peerId }).then(function (r) {
            convState[k] = !!r.conv_enabled;
            var s = sessions[k];
            if (s && r.peer_dev && s.peerDev && s.peerDev !== r.peer_dev) {
                delete sessions[k];
                delete recv[k];
                saveSessions();
                W.toast('对方已更换加密设备，下次发送时会重新建立会话', 3200);
            }
            return convState[k];
        }).catch(function () { return false; });
    }

    /* ===================== 发送 ===================== */

    /** 包住核心的 send：只拦「私聊 + 纯文字」，其余原样放行 */
    function installSendHook() {
        if (installSendHook.done) return;
        installSendHook.done = true;
        var origSend = W.OwChat.send;
        W.OwChat.send = function (opt) {
        opt = opt || {};
        var oc = W.OwChat;
        var dm = oc.dm;
        // 只管「私聊 + 双方都是注册用户 + 纯文字」，其余原样交给核心
        if (!dm || !dm.id || dm.kind !== 'user') return origSend.apply(oc, arguments);
        var peer = String(dm.id);
        var content = opt.content != null ? opt.content : ((W.document.getElementById('owInput') || {}).value || '');
        var type = opt.type || 'text';
        if (!content || !String(content).replace(/^\s+|\s+$/g, '')) return origSend.apply(oc, arguments);
        if (OWEE.isEnvelope(content)) return origSend.apply(oc, arguments);   // 已经是密文（重发）

        var args = arguments, self = this;
        ensureConvState(peer).then(function (on) {
            if (!on) { origSend.apply(oc, args); return; }
            if (type !== 'text') {
                W.toast('加密会话暂只支持文字，图片与文件请先关闭加密');
                return;
            }
            return loadKeys().then(function () {
                if (!keys) { W.toast('本设备还没有加密密钥，请点输入框上方的盾牌图标启用'); return; }
                var sess = sessions[peer];
                var ready = sess ? Promise.resolve(sess) : startSession(dm.id);
                return ready.then(function (s) {
                    return OWEE.encrypt(s.state, String(content)).then(function (m) {
                        var o = {};
                        for (var k in opt) if (opt.hasOwnProperty(k)) o[k] = opt[k];
                        o.content = buildEnvelope(m, s);
                        o.type = 'text';
                        sessions[peer] = s;
                        saveSessions();
                        // 自己发出去的密文是发给**对方设备**的，本机解不开 ——
                        // 本地存一份明文，回显与翻历史时按信封前缀取回（与所有 E2EE 客户端一样，
                        // 明文只存在自己的设备里）。内存那份给 buildMessage 用：
                        // 「发送中」气泡渲染的就是这个信封，不换回来用户会看到一串 base64。
                        plainCache[envKey(o.content)] = String(content);
                        idbSet('out:' + envKey(o.content), String(content));
                        return origSend.call(oc, o);
                    });
                });
            });
        }).catch(function (e) {
            W.toast((e && e.message) || '加密失败，请稍后重试');
        });
        };
    }

    /* ===================== 解密渲染 ===================== */

    function msgOf(node) {
        var id = parseInt(String(node.id || '').replace('owMsg', ''), 10);
        return id > 0 && W.OwChat.msgCache ? W.OwChat.msgCache[id] : null;
    }

    /** 自己发出的密文本机解不开，明文按信封前 40 字符做键存在本地 */
    function envKey(env) { return String(env).slice(OWEE.PREFIX.length, OWEE.PREFIX.length + 40); }

    function decryptNode(node) {
        if (!node || !node.querySelector) return;
        if (node.getAttribute('data-owed') === '1') return;
        var box = node.querySelector('.ow-msg-content') || node.querySelector('.ow-msg-plain');
        if (!box) return;
        var raw = (box.textContent || '').replace(/^\s+|\s+$/g, '');
        if (!OWEE.isEnvelope(raw)) return;

        node.setAttribute('data-owed', '1');
        var env = OWEE.open(raw);
        var m = msgOf(node);
        var peer = m ? (m.mine ? m.to_uid : m.uid) : 0;

        function fail(why) {
            node.classList.add('owed-bad');
            box.textContent = '（无法解密的消息）';
            box.title = why || '';
        }
        if (!env) return fail('信封格式错误');
        if (!peer) return fail('无法确定会话对象');
        if (pending[raw]) return;                      // 同一条正在解，别重复跑
        pending[raw] = 1;

        // 自己发的：直接取本地明文
        var own = m && m.mine ? idbGet('out:' + envKey(raw)) : Promise.resolve(null);

        own.then(function (plain) {
            if (plain != null) {
                node.classList.add('owed-ok');
                box.textContent = String(plain);
                box.title = '端到端加密（本机保存的明文）';
                return 'done';
            }
            return loadKeys().then(function () {
                if (!keys) throw new Error('本设备没有加密密钥（可能清理过浏览器数据或换了设备）');
                var s = recv[String(peer)];
                if (s && env.si && s.peerIk && env.si !== s.peerIk) {
                    // 对方身份密钥变了：可能是换设备，也可能是中间人。必须显式告警。
                    node.classList.add('owed-warn');
                }
                // 没有接收会话时，用信封里的握手字段现场重建一条（我就是收方）。
                // 解不动且信封带握手字段 → 也重建一次：对方可能重新发起过会话，
                // 旧的接收链已经对不上他的新棘轮。
                var ready = s ? Promise.resolve(s) : adoptSession(env, peer);
                return ready.then(function (ss) {
                    var item = env.m[keys.devId];
                    if (!item) throw new Error('这条消息的密钥不在这台设备上（可能是在更换设备前加密的）');
                    // 解密会推进棘轮状态；失败必须回滚，否则一条坏消息会把整条接收链带偏
                    var snap = JSON.stringify(ss.state);
                    return OWEE.decrypt(ss.state, item.h, item.c).then(function (text) {
                        ss.confirmed = true;
                        saveSessions();
                        node.classList.add('owed-ok');
                        box.textContent = text;
                        box.title = '端到端加密';
                    }, function (e) {
                        try { ss.state = JSON.parse(snap); } catch (x) {}
                        if (env.si && env.ek && s) {
                            // 带上握手字段却解不动：重建接收会话再试一次
                            delete recv[String(peer)];
                            return adoptSession(env, peer).then(function (ss2) {
                                var it2 = env.m[keys.devId];
                                return OWEE.decrypt(ss2.state, it2.h, it2.c).then(function (t2) {
                                    node.classList.add('owed-ok');
                                    box.textContent = t2;
                                    box.title = '端到端加密';
                                });
                            });
                        }
                        throw e;
                    });
                });
            });
        }).catch(function (e) {
            fail((e && e.message) || '解密失败');
        }).then(function () { delete pending[raw]; });
    }

    function scan(root) {
        var list = (root || W.document).querySelectorAll('#owMessages .ow-msg.private');
        for (var i = 0; i < list.length; i++) decryptNode(list[i]);
    }

    var obs = null;
    function watch() {
        var box = W.document.getElementById('owMessages');
        if (!box) { setTimeout(watch, 400); return; }
        if (obs) obs.disconnect();
        obs = new W.MutationObserver(function (muts) {
            for (var i = 0; i < muts.length; i++) {
                var add = muts[i].addedNodes;
                for (var j = 0; j < add.length; j++) {
                    var n = add[j];
                    if (n.nodeType !== 1) continue;
                    if (n.classList && n.classList.contains('ow-msg')) decryptNode(n);
                    else if (n.querySelectorAll) scan(n);
                }
            }
        });
        obs.observe(box, { childList: true, subtree: true });
        scan(box);
    }

    /* ===================== 开关按钮 ===================== */

    /* ===================== 开关（用户自己决定，服务端不强制） ===================== */

    /**
     * 自研弹窗（全站禁用浏览器原生对话框）。
     * 按钮是 innerHTML 注入的字符串，回调只能写全局函数名。
     */
    function modal(title, body, okText, okCall, danger) {
        W.OwChat.openModal(
            '<h3>' + title + '</h3>' + body
            + '<div class="ow-modal-actions">'
            + '<button class="ow-btn" type="button" onclick="OwChat.closeModal()">取消</button>'
            + '<button class="ow-btn ' + (danger ? 'ow-btn-danger' : 'ow-btn-primary')
            + '" type="button" onclick="' + okCall + '">' + okText + '</button>'
            + '</div>', 440);
    }

    /** 给任意异步流程加个硬上限：挂住了也要给出原因，不能让人对着界面干瞪眼 */
    function withTimeout(p, ms, msg) {
        return new Promise(function (res, rej) {
            var done = false;
            var t = setTimeout(function () { if (done) return; done = true; rej(new Error(msg)); }, ms);
            Promise.resolve(p).then(function (v) {
                if (done) return; done = true; clearTimeout(t); res(v);
            }, function (e) {
                if (done) return; done = true; clearTimeout(t); rej(e);
            });
        });
    }

    /** 开启前必须把「换设备就再也读不到」说清楚 —— 这不是可选项，是这套方案的真实代价 */
    function enableFlow() {
        var oc = W.OwChat;
        if (!oc.dm || !oc.dm.id || oc.dm.kind !== 'user') { W.toast('请先打开一个私聊'); return; }
        var peer = oc.dm.id;
        if (mode !== 'optin') {
            W.toast('站点未开启加密私聊，请让管理员在后台「加密私聊」里选择「允许用户自行开启」', 5000);
            return;
        }
        mark('进入 enableFlow');
        // 先给一声回执：后面要读本地存储、问服务端，任何一步慢了用户都不该以为没点上
        W.toast('正在检查本机密钥…', 1800);

        withTimeout(loadKeys().then(function () {
            return ensureConvState(peer);
        }), 8000, '本机密钥存储无响应，请检查是否用了无痕 / 隐私窗口')
            .then(function (on) {
                mark('④ 状态就绪，开关=' + (on ? '已开启' : '未开启'));
                if (on) { W.toast('该会话已开启加密'); return; }
                var first = !keys;
                var body = ''
                    + '<p class="ow-modal-desc">这个会话的消息在<b>这台设备的浏览器里</b>加密，'
                    + '私钥只存在本机，服务器只保存密文，没有能解开它的东西。</p>'
                    + '<p class="ow-modal-desc" style="color:var(--ow-red)">'
                    + '换设备、清理浏览器数据、使用无痕窗口都会丢失密钥：此前的加密消息'
                    + '<b>永久无法读取，也无法恢复</b>。</p>'
                    + '<p class="ow-modal-desc">'
                    + (first ? '本设备还没有加密密钥，点「开启加密」会现在生成一套。' : '')
                    + '对方也必须启用加密才能互发。开启后，这个会话的敏感词过滤、'
                    + '内容审核与管理员查阅都不再生效。</p>';
                mark('⑤ 弹窗已打开');
                modal('开启加密私聊', body, '开启加密', 'OWED._onOpen()', false);
            }).catch(function (e) {
                // 本地存储被禁用（无痕 / 隐私模式）、被别的标签页占用、或整体超时，
                // 以前这些都会表现成「点了没反应」
                W.toast((e && e.message) || '开启失败，请刷新页面后重试', 5000);
            });
    }

    function doEnable() {
        W.OwChat.closeModal();
        var peer = W.OwChat.dm && W.OwChat.dm.id;
        // 不在私聊时也必须出声 —— 早先这里是裸 return，点了完全没反应
        if (!peer) { W.toast('当前不在私聊，请先打开一个私聊', 4000); return; }
        // 密钥生成要走一遍 WebCrypto，慢的时候有一两秒 —— 不给反馈用户就会以为又没点上
        mark('⑥ doEnable 已进入');
        W.toast('正在生成本设备的加密密钥…', 6000);
        var p = keys ? Promise.resolve(keys) : createKeys();
        withTimeout(p, 15000, '生成本机密钥超时，请确认浏览器支持 WebCrypto 且不是无痕窗口')
            .then(function () {
                return startSession(peer).catch(function (e) {
                    // 对方还没登记设备：本设备的密钥**已经登记成功**（createKeys 跑完了），
                    // 所以这里只是「会话还没建成」，要让用户知道下一步该干什么 ——
                    // 不说清楚的话，用户会以为自己点坏了，而其实只差对方也点一次。
                    W.toast((e && e.message) || '对方还没有启用加密私聊', 5000);
                    W.toast('请让对方也点一次输入框上方的盾牌图标，然后你再点一次', 5000);
                    return null;
                });
            }).then(function (s) {
                if (!s) return null;                      // 上面已经提示过，不再往下开会话
                return api('plugin_e2ee_dm_conv', { peer_id: peer, on: 1 }).then(function (r) {
                    if (!r.ok) throw new Error(r.msg || '开启失败');
                    mark('⑦ 会话已开启');
                    convState[String(peer)] = true;
                    toastLock(true);
                    W.toast('已开启加密。请记住：换设备后此前的加密消息无法恢复', 4000);
                });
            }).catch(function (e) {
                W.toast((e && e.message) || '开启失败', 5000);
            });
    }

    function disableFlow() {
        var oc = W.OwChat;
        if (!oc.dm || !oc.dm.id) return;
        var body = ''
            + '<p class="ow-modal-desc">已经加密的消息仍保留在你们各自的设备上，可以继续阅读。</p>'
            + '<p class="ow-modal-desc">关闭后，新消息以<b>明文</b>发送并保存在服务器上：'
            + '管理员可依法查阅，敏感词与内容审核恢复生效。</p>';
        modal('关闭加密', body, '关闭加密', 'OWED._onClose()', true);
    }

    function doDisable() {
        W.OwChat.closeModal();
        var peer = W.OwChat.dm && W.OwChat.dm.id;
        if (!peer) { W.toast('当前不在私聊', 3000); return; }
        api('plugin_e2ee_dm_conv', { peer_id: peer, on: 0 }).then(function (r) {
            if (!r.ok) { W.toast(r.msg || '关闭失败'); return; }
            convState[String(peer)] = false;
            toastLock(false);
            W.toast('已关闭加密，此后的消息以明文发送');
        });
    }

    function toastLock(on) {
        var b = W.document.getElementById('owEDBtn');
        if (!b) return;
        // classList.toggle 的第二个参数在老浏览器不生效，一律用 add/remove
        if (on) b.classList.add('on'); else b.classList.remove('on');
        b.title = on ? '本会话已加密（点击可关闭）' : '按会话开启加密';
        b.setAttribute('aria-label', b.title);
    }

    /** 盾牌按钮只在私聊里有意义：群聊/联系人页直接藏掉，别留个点了就报错的按钮 */
    function btnVisible(v) {
        var b = W.document.getElementById('owEDBtn');
        if (b) b.style.display = v ? '' : 'none';
    }

    /**
     * 登录后「每日签到」等插件会自动弹窗，遮罩盖住整个屏幕 ——
     * 用户点盾牌的第一下其实落在遮罩上（弹窗被关掉），界面毫无变化，
     * 看起来就是「点了开启加密没有任何反应」。已用真实点击复现确认。
     * 这里在捕获阶段听遮罩点击：只有盾牌当前可用（在私聊里）时才提示，
     * 不给自己站点加噪声。
     */
    function installMaskHint() {
        W.document.addEventListener('click', function (e) {
            if (mode === 'off') return;
            var mask = W.document.getElementById('owModalMask');
            if (!mask || mask.style.display === 'none') return;
            if (e.target !== mask) return;                    // 点在弹窗内部，不归我管
            var b = W.document.getElementById('owEDBtn');
            if (!b || b.style.display === 'none') return;     // 不在私聊里，别添乱
            W.toast('弹窗已关闭，请再点一次盾牌图标', 2600);
        }, true);
    }

    /**
     * 点盾牌：挂在 document 上的**事件委托**，不直接绑 onclick。
     * 直接绑有个致命弱点 —— SPA 重渲染会把 #owToolFirst 整段替换，
     * 新节点身上没有 onclick，按钮「看起来正常」但点了毫无反应。
     * 委托绑在 document 上，换多少个节点都不受影响。
     * 用捕获阶段还能抢在别的处理器之前。
     */
    /**
     * 渲染前把密文换成本机明文。
     *
     * 为什么必须包 buildMessage：核心的「发送中」临时气泡直接渲染 payload.content，
     * 而那里装的已经是密文信封 —— WSS 通路上这个窗口可能长达数秒（ws_send_timeout 默认 8 秒），
     * 用户会眼睁睁看着自己刚打的字变成一串 base64。
     * 发送那一刻把明文记进内存缓存，渲染时同key换回来，顺带也让回显没有闪断。
     *
     * 对方发来的消息这里换不了（本机没明文），交给 decryptNode 解密。
     */
    function installRenderHook() {
        if (typeof W.OwChat.buildMessage !== 'function') return;
        installRenderHook.done = true;
        var orig = W.OwChat.buildMessage;
        W.OwChat.buildMessage = function (m) {
            if (m && typeof m.content === 'string' && OWEE.isEnvelope(m.content)) {
                var p = plainCache[envKey(m.content)];
                if (p != null) {
                    var c = {}, k;
                    for (k in m) if (m.hasOwnProperty(k)) c[k] = m[k];
                    c.content = p;
                    return orig.call(this, c);
                }
            }
            return orig.call(this, m);
        };
    }

    /* ===================== 执行轨迹（排查用，出错时一眼看到卡在哪一步） ===================== */

    var trace = [];
    function mark(s) {
        trace.push(s);
        if (trace.length > 14) trace.shift();
    }

    function installBtnClick() {
        W.document.addEventListener('click', function (e) {
            var t = e.target;
            if (!t || !t.closest) { mark('点到了非元素'); return; }
            if (!t.closest('#owEDBtn')) return;
            e.preventDefault();
            e.stopPropagation();
            mark('① 盾牌被点击');
            if (unsupported) {
                mark('× 环境不支持');
                W.toast('当前环境不支持加密：需要用 HTTPS 或 localhost 访问，且浏览器要支持 WebCrypto', 4000);
                return;
            }
            var oc = W.OwChat;
            if (!oc || !oc.dm || !oc.dm.id) {
                mark('× 当前不在私聊');
                W.toast('请先打开一个私聊'); return;
            }
            mark('② 对话对象 id=' + oc.dm.id + ' kind=' + (oc.dm.kind || '(空)'));
            ensureConvState(oc.dm.id).then(function (on) {
                mark('③ 开关状态=' + (on ? '已开启' : '未开启'));
                if (on) disableFlow(); else enableFlow();
            }, function (err) {
                mark('× 开关查询失败：' + ((err && err.message) || err));
                W.toast('无法查询加密状态，请稍后重试');
            });
        }, true);
    }

    /**
     * 第二个入口：消息头像右键菜单里加「加密私聊」。
     * 工具栏那条路只要出任何意外（遮罩、布局挤压、被别的插件重写工具栏），
     * 这条路依然独立可用 —— 同一套功能，不多一份逻辑。
     */
    function installCtxMenu() {
        if (typeof W.OwChat.onMsgCtx !== 'function') return;
        W.OwChat.onMsgCtx(function (items, m) {
            if (mode === 'off' || unsupported) return;
            if (!m || m.recalled || m.mine || !m.uid) return;
            var uid = parseInt(m.uid, 10) || 0;
            var dm = W.OwChat.dm;
            if (!dm || parseInt(dm.id, 10) !== uid) return;   // 只在与他人的私聊里给
            items.push({
                t: '加密私聊设置',
                run: function () {
                    ensureConvState(uid).then(function (on) {
                        if (on) disableFlow(); else enableFlow();
                    });
                }
            });
        });
    }

    /* ===================== 启动 ===================== */

    function init() {
        loadKeys().then(loadSessions).then(function () { return boot(); })
            .then(function (st) { setup(st); })
            .catch(function (e) {
                // 状态没拉到（多半是首屏 XHR 被长轮询挤掉）。此时**不能**当成「站点关闭」：
                // 先把盾牌藏起来——宁可按钮暂时不出现，也不能摆一个点了没反应的东西；
                // 然后重试，拉到了再放出来。
                btnVisible(false);
                retryBoot(5, e);
            });
    }

    /** 状态拉取重试：1s 起、每次翻倍，最多 tries 次 */
    function retryBoot(tries, lastErr) {
        if (tries <= 0) {
            W.toast((lastErr && lastErr.message) || '无法获取加密功能状态，请刷新页面', 4000);
            return;
        }
        setTimeout(function () {
            boot().then(function (st) { setup(st); }, function (e) { retryBoot(tries - 1, e); });
        }, tries === 5 ? 1000 : 1000);
    }

    /** 一切就绪后的装配：解密观察、点击、菜单、会话切换巡检 */
    function setup(st) {
        if (setup.done) return;
        if (mode !== 'optin') {
            // 服务端确实关闭了：按钮是页面渲染时留下的（设置可能刚被改），收起来
            btnVisible(false);
            return;
        }
        setup.done = true;
        btnVisible(false);      // 先藏起来，等确认当前是私聊再显示
        watch();
        installMaskHint();
        installCtxMenu();
        // 只在「切换了会话」时查一次状态，不做轮询 —— 本站是长轮询架构，
        // 每 2 秒打一个接口等于给每个用户凭空加 30 次/分钟的请求。
        var lastPeer = '', shown = false;
        setInterval(function () {
            var oc = W.OwChat;
            var cur = oc && oc.dm && oc.dm.id ? String(oc.dm.id) : '';
            // 有弹窗时把盾牌藏起来：被遮罩盖住的按钮点了不会有任何反应，
            // 摆在那儿只会让人反复点（实测：登录后的每日签到弹窗吃掉了第一下点击）。
            // 弹窗一关，按钮自己回来 —— 它重新出现本身就是「现在可以点了」的信号。
            var usable = !!cur && !masked();
            if (usable !== shown) { shown = usable; btnVisible(usable); }
            if (cur === lastPeer || !cur) return;
            lastPeer = cur;
            syncConv(oc.dm.id).then(toastLock);
        }, 800);
        if (st && st.device) toastLock(false);
    }

    /** 此刻是否有弹窗遮罩盖着页面 */
    function masked() {
        var m = W.document.getElementById('owModalMask');
        return !!m && m.style.display !== 'none';
    }

    /**
     * 浏览器不支持 WebCrypto（http 访问 / 太老的浏览器）时，不能像以前那样
     * 直接 return —— 那会让盾牌按钮留在工具栏上却毫无反应，用户只会以为功能坏了。
     * 这里给它标成不可用，**但不真正 disabled**：disabled 的按钮连 click 事件都不发，
     * 用户点了照样是零反馈。改成保留点击、点了告诉他原因。
     */
    function markUnsupported() {
        unsupported = true;
        // 点击由 installBtnClick 的委托统一处理（它会读 unsupported 标志给提示），
        // 这里只负责置灰 + 写明原因；不再直接绑 onclick（节点被换掉就失效）。
        (function poll() {
            var b = W.document.getElementById('owEDBtn');
            if (!b) { setTimeout(poll, 500); return; }
            b.classList.add('owed-off');
            b.title = '当前环境不支持加密：需要 HTTPS 或 localhost，且浏览器需支持 WebCrypto';
            b.setAttribute('aria-label', b.title);
        })();
    }

    /**
     * 等核心脚本就位。
     * 刷新聊天页时，插件包可能排在核心 chat.js **之前**执行（登录后是 SPA 动态注入，
     * 顺序与首屏不同）—— 以前这里直接 return，表现为「刷新页面后加密功能静默死亡」：
     * 盾牌还在、点击毫无反应、历史消息全是密文。所以改成轮询等待，最多 10 秒。
     */
    function waitCore(tries) {
        if (started) return;
        if (W.OwApi && W.OwChat) { started = true; installSendHook(); init(); return; }
        if (tries <= 0) { started = true; markUnsupported(); return; }
        setTimeout(function () { waitCore(tries - 1); }, 100);
    }

    // 点击委托无条件装上：不支持加密的环境里也要能点出原因，否则又是个哑巴按钮
    installBtnClick();
    installRenderHook();

    if (W.document.readyState === 'loading') {
        W.document.addEventListener('DOMContentLoaded', function () {
            if (!W.OWEE || !W.OWEE.available()) markUnsupported();
            else waitCore(100);
        });
    } else if (!W.OWEE || !W.OWEE.available()) {
        markUnsupported();
    } else {
        waitCore(100);
    }

    W.OWED = {
        // 版本自检：浏览器控制台敲 OWED.v 应显示这个号。
        // 显示 undefined 就说明页面还在用旧的插件包（Ctrl+F5 硬刷新）。
        v: '1.1.3',
        /**
         * 自检：控制台敲 OWED.diagnose() 回车。
         * 结果会**自动打印并复制到剪贴板**（Chrome 控制台的 copy() 辅助）——
         * 返回的是 Promise，直接粘给你看到的是 Promise 对象，得展开才看得到内容。
         */
        diagnose: function () {
            function report(out) {
                var t = JSON.stringify(out);
                try { W.console.log('[加密私聊自检] ' + t); } catch (e) { /* 无控制台环境 */ }
                try { W.copy(t); } catch (e) { /* 非 Chrome / 无剪贴板权限 */ }
                return out;
            }
            return withTimeout(loadKeys(), 3000, 'IndexedDB 无响应').then(function () {
                return report({
                    版本: W.OWED.v,
                    模式: mode,
                    有本机密钥: !!keys,
                    设备号: keys ? keys.devId : null,
                    发送会话: Object.keys(sessions).length,
                    接收会话: Object.keys(recv).length,
                    会话开关: convState,
                    当前私聊: (W.OwChat && W.OwChat.dm) ? W.OwChat.dm.id : null,
                    按钮: (function () {
                        var b = W.document.getElementById('owEDBtn');
                        return b ? getComputedStyle(b).display + ' | ' + b.className : '不在 DOM 里';
                    })(),
                    弹窗: masked() ? '开着' : '无',
                    WebSocket: (W.OwChat && W.OwChat.rt && W.OwChat.rt.on) ? '已连接' : '未连接（发送回落 HTTP）',
                    执行轨迹: trace.slice(),
                    环境: (W.isSecureContext ? 'https 安全上下文' : '非安全上下文（不能加密）')
                        + ' | WebCrypto ' + (W.OWEE.available() ? '可用' : '不可用')
                });
            }).catch(function (e) {
                return report({ 版本: W.OWED.v, 错误: (e && e.message) || String(e) });
            });
        },
        enable: enableFlow, disable: disableFlow,
        getKeys: function () { return keys; },
        getMode: function () { return mode; },
        // 弹窗按钮的回调（onclick 走全局作用域）
        _onOpen: doEnable,
        _onClose: doDisable
    };
})();
