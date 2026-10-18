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

    var DBNAME = 'owee', STORE = 'kv';
    var keys = null;                 // {devId, identity, spk, opks, opkNext}
    var sessions = {};               // peerUid -> 我发起的会话（发送用）
    var recv = {};                   // peerUid -> 我接收的会话（解密对方消息用）
    var convState = {};              // peerUid -> true/false（会话是否已开启加密）
    var mode = null;                 // off | optin
    var dbp = null;                  // IndexedDB 连接
    var pending = {};                // 并发解密去重

    /* ===================== 存储 ===================== */

    function db() {
        if (dbp) return dbp;
        dbp = new Promise(function (res, rej) {
            if (!W.indexedDB) return rej(new Error('浏览器不支持本地存储'));
            var r = W.indexedDB.open(DBNAME, 1);
            r.onupgradeneeded = function () { r.result.createObjectStore(STORE); };
            r.onsuccess = function () { dbp = Promise.resolve(r.result); res(r.result); };
            r.onerror = function () { rej(r.error); };
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

    function boot() {
        return api('plugin_e2ee_dm_state', {}).then(function (r) {
            mode = r.mode || 'off';
            return r;
        }).catch(function () { mode = 'off'; });
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
                        // 明文只存在自己的设备里）。
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

    /** 开启前必须把「换设备就再也读不到」说清楚 —— 这不是可选项，是这套方案的真实代价 */
    function enableFlow() {
        var oc = W.OwChat;
        if (!oc.dm || !oc.dm.id || oc.dm.kind !== 'user') { W.toast('请先打开一个私聊'); return; }
        var peer = oc.dm.id;

        loadKeys().then(function () {
            ensureConvState(peer).then(function (on) {
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
                modal('开启加密私聊', body, '开启加密', 'OWED._onOpen', false);
            });
        });
    }

    function doEnable() {
        W.OwChat.closeModal();
        var peer = W.OwChat.dm && W.OwChat.dm.id;
        if (!peer) return;
        var p = keys ? Promise.resolve(keys) : createKeys();
        p.then(function () {
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
                convState[String(peer)] = true;
                toastLock(true);
                W.toast('已开启加密。请记住：换设备后此前的加密消息无法恢复', 4000);
            });
        }).catch(function (e) {
            W.toast((e && e.message) || '开启失败');
        });
    }

    function disableFlow() {
        var oc = W.OwChat;
        if (!oc.dm || !oc.dm.id) return;
        var body = ''
            + '<p class="ow-modal-desc">已经加密的消息仍保留在你们各自的设备上，可以继续阅读。</p>'
            + '<p class="ow-modal-desc">关闭后，新消息以<b>明文</b>发送并保存在服务器上：'
            + '管理员可依法查阅，敏感词与内容审核恢复生效。</p>';
        modal('关闭加密', body, '关闭加密', 'OWED._onClose', true);
    }

    function doDisable() {
        W.OwChat.closeModal();
        var peer = W.OwChat.dm && W.OwChat.dm.id;
        if (!peer) return;
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

    function bindBtn() {
        var b = W.document.getElementById('owEDBtn');
        if (!b) { setTimeout(bindBtn, 500); return; }
        b.onclick = function () {
            var oc = W.OwChat;
            if (!oc.dm) { W.toast('请先打开一个私聊'); return; }
            ensureConvState(oc.dm.id).then(function (on) {
                if (on) disableFlow(); else enableFlow();
            });
        };
    }

    /* ===================== 启动 ===================== */

    function init() {
        loadKeys().then(loadSessions).then(boot).then(function (st) {
            if (mode === 'off') return;
            btnVisible(false);      // 先藏起来，等确认当前是私聊再显示
            watch();
            bindBtn();
            // 只在「切换了会话」时查一次状态，不做轮询 —— 本站是长轮询架构，
            // 每 2 秒打一个接口等于给每个用户凭空加 30 次/分钟的请求。
            var lastPeer = '';
            setInterval(function () {
                var oc = W.OwChat;
                var cur = oc && oc.dm && oc.dm.id ? String(oc.dm.id) : '';
                if (cur === lastPeer) return;
                lastPeer = cur;
                if (!cur) { btnVisible(false); return; }
                btnVisible(true);
                syncConv(oc.dm.id).then(toastLock);
            }, 800);
            if (st && st.device) toastLock(false);
        });
    }

    /**
     * 浏览器不支持 WebCrypto（http 访问 / 太老的浏览器）时，不能像以前那样
     * 直接 return —— 那会让盾牌按钮留在工具栏上却毫无反应，用户只会以为功能坏了。
     * 这里把按钮标成不可用并写明原因。
     */
    function markUnsupported() {
        (function poll() {
            var b = W.document.getElementById('owEDBtn');
            if (!b) { setTimeout(poll, 500); return; }
            b.disabled = true;
            b.style.opacity = '.45';
            b.style.cursor = 'not-allowed';
            b.title = '当前环境不支持加密：需要 HTTPS 或 localhost，且浏览器支持 WebCrypto';
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
        enable: enableFlow, disable: disableFlow,
        getKeys: function () { return keys; },
        // 弹窗按钮的回调（onclick 走全局作用域）
        _onOpen: doEnable,
        _onClose: doDisable
    };
})();
