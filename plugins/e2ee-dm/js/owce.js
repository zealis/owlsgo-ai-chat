/*!
 * owce.js —— 私聊端到端加密协议核心（OWEE v1）
 *
 * 目标：把「谁能解密」限制到两台设备上，服务端只存密文。
 * 依赖：只依赖浏览器原生 WebCrypto（SubtleCrypto），零第三方库、零构建步骤。
 *
 * 为什么选 P-256 而不是 Signal 的 Curve25519
 *   - X25519 / Ed25519 在 WebCrypto 里要 Chrome 133 / Firefox 129+ 才有，
 *     本产品要兼容的老浏览器（ES5 时代）基本都不满足；
 *   - P-256 的 ECDH / ECDSA 从 Chrome 37 / Firefox 34 / Safari 7 就有，
 *     与「零依赖 + 老浏览器可用」的硬约束一致；
 *   - P-256 在 TLS 里被大规模使用，本身没有已知的实际弱点。
 *     代价是失去与 Signal 生态的互通性，以及压缩点/常量时间实现由浏览器负责（这是好事）。
 *
 * 协议组成
 *   X3DH-lite（P-256）建立共享密钥 → Double Ratchet（HKDF-SHA256 + HMAC）逐条推进消息密钥
 *   → AES-256-GCM 认证加密正文。
 *
 * 与 Signal 的差异（都是为「Web 端 + 零依赖」做的减法，必须知道）
 *   1. 身份密钥拆成两把：IK_dh（ECDH，参与 DH）+ IK_sig（ECDSA，给签名预密钥签名）。
 *      Curve25519 可以一把密钥两用，WebCrypto 不行。
 *   2. 只做单设备：一个账号同时只有一台「加密设备」持有私钥（换设备要重新登记）。
 *      多设备扇出（每台设备各一条会话）留到 P2，见 README。
 *   3. 不做 PQXDH 抗量子扩展。
 *
 * 状态说明
 *   所有会话状态都可 JSON 序列化，由调用方存进 IndexedDB；本文件不碰任何存储。
 *   私钥只在 device 对象里出现，绝不上传。
 */
(function (root, factory) {
    if (typeof module === 'object' && module.exports) module.exports = factory();
    else root.OWEE = factory();
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    var SUB = null;
    if (typeof globalThis !== 'undefined' && globalThis.crypto && globalThis.crypto.subtle) SUB = globalThis.crypto.subtle;

    var INFO_X3DH = 'owee-dm-x3dh';
    var INFO_ROOT = 'owee-dm-root';
    var INFO_MSG = 'owee-dm-msg';
    var PREFIX = 'owee1:';
    var MAX_SKIP = 1000;          // 单条消息最多允许跳过多少条（防 DoS 与乱序上限）
    var MAX_SKIPPED_TOTAL = 2000; // 会话内保留的跳过密钥总数上限

    /* ===================== 字节 / 编码 ===================== */

    function u8(x) { return x instanceof Uint8Array ? x : new Uint8Array(x); }

    function cat() {
        var n = 0, i, out, off = 0;
        for (i = 0; i < arguments.length; i++) n += arguments[i].length;
        out = new Uint8Array(n);
        for (i = 0; i < arguments.length; i++) { out.set(arguments[i], off); off += arguments[i].length; }
        return out;
    }

    /** UTF-8 编码。手写而不是用 TextEncoder：TextEncoder 比 WebCrypto 晚一个版本，
     *  老浏览器上「有 subtle 却没有 TextEncoder」是真会遇到的组合。 */
    function utf8(s) {
        var out = [], i, c, c2;
        s = String(s);
        for (i = 0; i < s.length; i++) {
            c = s.charCodeAt(i);
            if (c < 0x80) out.push(c);
            else if (c < 0x800) out.push(0xC0 | (c >> 6), 0x80 | (c & 63));
            else if (c >= 0xD800 && c <= 0xDBFF && i + 1 < s.length &&
                     (c2 = s.charCodeAt(i + 1)) >= 0xDC00 && c2 <= 0xDFFF) {
                c = 0x10000 + ((c - 0xD800) << 10) + (c2 - 0xDC00);
                i++;
                out.push(0xF0 | (c >> 18), 0x80 | ((c >> 12) & 63), 0x80 | ((c >> 6) & 63), 0x80 | (c & 63));
            } else out.push(0xE0 | (c >> 12), 0x80 | ((c >> 6) & 63), 0x80 | (c & 63));
        }
        return new Uint8Array(out);
    }

    function fromUtf8(b) {
        var s = '', i = 0, c, out = '';
        b = u8(b);
        while (i < b.length) {
            c = b[i++];
            if (c < 0x80) out += String.fromCharCode(c);
            else if (c < 0xE0) out += String.fromCharCode(((c & 31) << 6) | (b[i++] & 63));
            else if (c < 0xF0) out += String.fromCharCode(((c & 15) << 12) | ((b[i++] & 63) << 6) | (b[i++] & 63));
            else {
                c = ((c & 7) << 18) | ((b[i++] & 63) << 12) | ((b[i++] & 63) << 6) | (b[i++] & 63);
                c -= 0x10000;
                out += String.fromCharCode(0xD800 + (c >> 10), 0xDC00 + (c & 1023));
            }
        }
        return out;
    }

    function b64u(bytes) {
        var s = '', i, bin;
        bytes = u8(bytes);
        for (i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
        bin = typeof btoa === 'function' ? btoa(s) : Buffer.from(s, 'binary').toString('base64');
        return bin.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function unb64u(str) {
        var s = String(str).replace(/-/g, '+').replace(/_/g, '/'), bin, i, out;
        while (s.length % 4) s += '=';
        if (typeof atob === 'function') bin = atob(s);
        else bin = Buffer.from(s, 'base64').toString('binary');
        out = new Uint8Array(bin.length);
        for (i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
        return out;
    }

    function toB64(buf) { return b64u(new Uint8Array(buf)); }

    /* ===================== 基础原语 ===================== */

    function need() {
        if (!SUB) throw new Error('当前浏览器不支持 WebCrypto（需要 HTTPS 或 localhost）');
    }

    function genEcdh() {
        return SUB.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveBits']);
    }

    function impPub(pubB64) {
        return SUB.importKey('raw', unb64u(pubB64), { name: 'ECDH', namedCurve: 'P-256' }, true, []);
    }

    function impPriv(jwk) {
        return SUB.importKey('jwk', jwk, { name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveBits']);
    }

    /** ECDH：私钥(jwk) × 对方公钥(b64u raw) → 32 字节 */
    function dh(privJwk, pubB64) {
        return Promise.all([impPriv(privJwk), impPub(pubB64)]).then(function (k) {
            return SUB.deriveBits({ name: 'ECDH', public: k[1] }, k[0], 256);
        }).then(u8);
    }

    /** HKDF-SHA256。salt 为空时按 RFC 5869 用全零，长度取 hash 长度（32）。 */
    function hkdf(ikm, salt, info, len) {
        var s = salt && salt.length ? u8(salt) : new Uint8Array(32);
        return SUB.importKey('raw', u8(ikm), { name: 'HKDF' }, false, ['deriveBits']).then(function (k) {
            return SUB.deriveBits({ name: 'HKDF', hash: 'SHA-256', salt: s, info: utf8(info) }, k, len * 8);
        }).then(u8);
    }

    function hmacSha256(key, data) {
        return SUB.importKey('raw', u8(key), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']).then(function (k) {
            return SUB.sign('HMAC', k, u8(data));
        }).then(u8);
    }

    function sha256(data) {
        return SUB.digest('SHA-256', u8(data)).then(u8);
    }

    function aead(key, iv, ad, pt) {
        return SUB.importKey('raw', u8(key), { name: 'AES-GCM' }, false, ['encrypt']).then(function (k) {
            return SUB.encrypt({ name: 'AES-GCM', iv: u8(iv), additionalData: u8(ad), tagLength: 128 }, k, u8(pt));
        }).then(u8);
    }

    function aeadOpen(key, iv, ad, ct) {
        return SUB.importKey('raw', u8(key), { name: 'AES-GCM' }, false, ['decrypt']).then(function (k) {
            return SUB.decrypt({ name: 'AES-GCM', iv: u8(iv), additionalData: u8(ad), tagLength: 128 }, k, u8(ct));
        }).then(u8);
    }

    function genSig() {
        return SUB.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, true, ['sign', 'verify']);
    }

    function sign(sigPrivJwk, data) {
        return SUB.importKey('jwk', sigPrivJwk, { name: 'ECDSA', namedCurve: 'P-256' }, false, ['sign']).then(function (k) {
            return SUB.sign({ name: 'ECDSA', hash: 'SHA-256' }, k, u8(data));
        }).then(u8);
    }

    function verify(sigPubB64, sigB64, data) {
        return SUB.importKey('raw', unb64u(sigPubB64), { name: 'ECDSA', namedCurve: 'P-256' }, false, ['verify'])
            .then(function (k) {
                return SUB.verify({ name: 'ECDSA', hash: 'SHA-256' }, k, unb64u(sigB64), u8(data));
            });
    }

    /* ===================== 身份与预密钥 ===================== */

    /**
     * 生成一套身份密钥。
     * 返回 { dhPub, dhPriv, sigPub, sigPriv }：两个公钥可上传，两个私钥永远只在本机。
     */
    function genIdentity() {
        need();
        return Promise.all([genEcdh(), genSig()]).then(function (r) {
            return Promise.all([
                SUB.exportKey('raw', r[0].publicKey), SUB.exportKey('jwk', r[0].privateKey),
                SUB.exportKey('raw', r[1].publicKey), SUB.exportKey('jwk', r[1].privateKey)
            ]);
        }).then(function (e) {
            return { dhPub: b64u(e[0]), dhPriv: e[1], sigPub: b64u(e[2]), sigPriv: e[3] };
        });
    }

    /** 签名预密钥的待签字节：公钥 + id，绑在一起防止把同一把预密钥挂到别的 id 下复用 */
    function spkSignedBytes(pubB64, id) {
        return cat(unb64u(pubB64), utf8('|spk|' + (id | 0)));
    }

    /** 生成签名预密钥（SPK），由身份签名密钥签名 */
    function genSignedPrekey(identity, id) {
        return genEcdh().then(function (k) {
            return Promise.all([SUB.exportKey('raw', k.publicKey), SUB.exportKey('jwk', k.privateKey)]);
        }).then(function (e) {
            var pub = b64u(e[0]);
            return sign(identity.sigPriv, spkSignedBytes(pub, id)).then(function (s) {
                return { id: id | 0, pub: pub, priv: e[1], sig: b64u(s) };
            });
        });
    }

    /** 批量生成一次性预密钥（OPK） */
    function genOneTimePrekeys(startId, count) {
        var out = [], i = 0;
        function one() {
            if (i >= count) return Promise.resolve(out);
            var id = (startId | 0) + i;
            i++;
            return genEcdh().then(function (k) {
                return Promise.all([SUB.exportKey('raw', k.publicKey), SUB.exportKey('jwk', k.privateKey)]);
            }).then(function (e) {
                out.push({ id: id, pub: b64u(e[0]), priv: e[1] });
                return one();
            });
        }
        return one();
    }

    /** 单把临时 ECDH 密钥对（X3DH 的 EK 用；算完即弃，不入库） */
    function genDhPair() {
        need();
        return genEcdh().then(function (k) {
            return Promise.all([SUB.exportKey('raw', k.publicKey), SUB.exportKey('jwk', k.privateKey)]);
        }).then(function (e) {
            return { pub: b64u(e[0]), priv: e[1] };
        });
    }

    /** 会话关联数据：双方身份公钥按字典序拼，两边各算各的但结果必然一致 */
    function assocData(ikA, ikB) {
        var a = String(ikA), b = String(ikB);
        return (a < b ? a + '|' + b : b + '|' + a);
    }

    /* ===================== X3DH-lite ===================== */

    /**
     * 发起方（Alice）算共享密钥。
     * @param me     { dhPub, dhPriv }        我的身份 DH 密钥对
     * @param ek     { pub, priv }            本次会话的临时密钥对（一次性，算完即弃）
     * @param bundle 对方上传的密钥包 { ikDh, ikSig, spk, spkId, spkSig, opk:{id,pub}|null }
     * @return { sk, ad, ekPub, opkId }       sk/ad 交给 initAlice 建棘轮
     */
    function x3dhInit(me, ek, bundle) {
        need();
        if (!bundle || !bundle.ikDh || !bundle.spk || !bundle.spkSig) {
            return Promise.reject(new Error('对方密钥包不完整'));
        }
        // 预密钥签名必须验：这是 X3DH 唯一挡住「服务器换掉对方预密钥」的地方
        return verify(bundle.ikSig, bundle.spkSig, spkSignedBytes(bundle.spk, bundle.spkId)).then(function (ok) {
            if (!ok) throw new Error('对方签名预密钥校验失败，可能存在中间人');
            var jobs = [
                dh(me.dhPriv, bundle.spk),      // DH1 = IK_A × SPK_B
                dh(ek.priv, bundle.ikDh),       // DH2 = EK_A × IK_B
                dh(ek.priv, bundle.spk)         // DH3 = EK_A × SPK_B
            ];
            if (bundle.opk && bundle.opk.pub) jobs.push(dh(ek.priv, bundle.opk.pub)); // DH4
            return Promise.all(jobs);
        }).then(function (dhs) {
            // X3DH 规范：P-256 用 32 字节 0xFF 做域分隔前缀
            var F = new Uint8Array(32); for (var i = 0; i < 32; i++) F[i] = 0xFF;
            var ikm = cat.apply(null, [F].concat(dhs));
            return hkdf(ikm, null, INFO_X3DH, 32).then(function (sk) {
                return {
                    sk: b64u(sk),
                    ad: assocData(me.dhPub, bundle.ikDh),
                    ekPub: ek.pub,
                    opkId: bundle.opk ? bundle.opk.id : null
                };
            });
        });
    }

    /**
     * 接收方（Bob）算共享密钥。参数顺序与 DH 方向必须与发起方严格对应，
     * 写错一个就是「两边算出的密钥不一样」这种最难查的 bug。
     */
    function x3dhRespond(me, spkPair, opkPair, peerIkPub, peerEkPub) {
        need();
        var jobs = [
            dh(spkPair.priv, peerIkPub),    // DH1（Bob 视角）= SPK_B × IK_A
            dh(me.dhPriv, peerEkPub),       // DH2 = IK_B × EK_A
            dh(spkPair.priv, peerEkPub)     // DH3 = SPK_B × EK_A
        ];
        if (opkPair) jobs.push(dh(opkPair.priv, peerEkPub)); // DH4
        return Promise.all(jobs).then(function (dhs) {
            var F = new Uint8Array(32); for (var i = 0; i < 32; i++) F[i] = 0xFF;
            var ikm = cat.apply(null, [F].concat(dhs));
            return hkdf(ikm, null, INFO_X3DH, 32).then(function (sk) {
                return { sk: b64u(sk), ad: assocData(me.dhPub, peerIkPub) };
            });
        });
    }

    /* ===================== Double Ratchet ===================== */

    function kdfRoot(rkB64, dhOut) {
        return hkdf(dhOut, unb64u(rkB64), INFO_ROOT, 64).then(function (o) {
            return { rk: b64u(o.slice(0, 32)), ck: b64u(o.slice(32, 64)) };
        });
    }

    /** 链密钥推进：一条消息一个密钥，旧密钥立刻丢弃 → 前向安全 */
    function kdfChain(ckB64) {
        var ck = unb64u(ckB64);
        return Promise.all([hmacSha256(ck, new Uint8Array([1])), hmacSha256(ck, new Uint8Array([2]))])
            .then(function (r) { return { mk: b64u(r[0]), ck: b64u(r[1]) }; });
    }

    function mkKeyIv(mkB64) {
        return hkdf(unb64u(mkB64), null, INFO_MSG, 44).then(function (o) {
            return { key: o.slice(0, 32), iv: o.slice(32, 44) };
        });
    }

    function msgAd(ad, h) {
        return utf8('owee1|' + ad + '|' + h.dh + '|' + (h.pn | 0) + '|' + (h.n | 0));
    }

    function newState(ad) {
        return { RK: null, DHs: null, DHr: null, CKs: null, CKr: null, Ns: 0, Nr: 0, PN: 0,
                 skipped: {}, ad: ad || '' };
    }

    /** 发起方初始化：用共享密钥 + 对方签名预密钥公钥建根链 */
    function initAlice(sk, ad, bobSpkPub) {
        var st = newState(ad);
        return genEcdh().then(function (k) {
            return Promise.all([SUB.exportKey('raw', k.publicKey), SUB.exportKey('jwk', k.privateKey)]);
        }).then(function (e) {
            st.DHs = { pub: b64u(e[0]), priv: e[1] };
            st.DHr = bobSpkPub;
            return dh(st.DHs.priv, bobSpkPub).then(function (o) {
                return kdfRoot(sk, o);
            }).then(function (r) {
                st.RK = r.rk; st.CKs = r.ck;
                return st;
            });
        });
    }

    /** 接收方初始化：只设根密钥，等对方第一条消息带着 DH 公钥来再推棘轮 */
    function initBob(sk, ad, spkPair) {
        var st = newState(ad);
        st.RK = sk;
        st.DHs = { pub: spkPair.pub, priv: spkPair.priv };
        return Promise.resolve(st);
    }

    /** 把接收链推进到 until，中途跨过的密钥全部存起来备用（乱序消息靠它解密） */
    function skipKeys(st, until) {
        if (!st.CKr) return until > 0 ? Promise.reject(new Error('缺少接收链，无法补齐跳过的消息')) : Promise.resolve();
        if ((st.Nr | 0) + MAX_SKIP < until) return Promise.reject(new Error('消息序号跳变过大，可能被篡改'));
        // 链密钥是串行的：下一个 CK 是上一个的输出，不能并行
        function loop() {
            if ((st.Nr | 0) >= until) return Promise.resolve();
            var n = st.Nr | 0;
            return kdfChain(st.CKr).then(function (r) {
                st.Nr = n + 1;
                st.CKr = r.ck;
                st.skipped[st.DHr + '|' + n] = r.mk;
                return loop();
            });
        }
        return loop();
    }

    function trimSkipped(st) {
        var keys = Object.keys(st.skipped);
        if (keys.length <= MAX_SKIPPED_TOTAL) return;
        // 超上限就丢最早的一半（按插入顺序），宁可丢老消息也不让状态无限膨胀
        var drop = keys.slice(0, keys.length - MAX_SKIPPED_TOTAL);
        for (var i = 0; i < drop.length; i++) delete st.skipped[drop[i]];
    }

    function dhRatchet(st, h) {
        st.PN = st.Ns;
        st.Ns = 0;
        st.Nr = 0;
        st.DHr = h.dh;
        return dh(st.DHs.priv, st.DHr).then(function (o) {
            return kdfRoot(st.RK, o);
        }).then(function (r) {
            st.RK = r.rk; st.CKr = r.ck;
            return genEcdh();
        }).then(function (k) {
            return Promise.all([SUB.exportKey('raw', k.publicKey), SUB.exportKey('jwk', k.privateKey)]);
        }).then(function (e) {
            st.DHs = { pub: b64u(e[0]), priv: e[1] };
            return dh(st.DHs.priv, st.DHr);
        }).then(function (o) {
            return kdfRoot(st.RK, o);
        }).then(function (r) {
            st.RK = r.rk; st.CKs = r.ck;
            return st;
        });
    }

    /**
     * 加密一条消息。
     * @return { header:{dh,pn,n}, ct:<b64u> } —— header 必须原样带给对方，它参与认证
     */
    function encrypt(st, plaintext) {
        if (!st || !st.CKs) return Promise.reject(new Error('会话未就绪（缺少发送链）'));
        var h = { dh: st.DHs.pub, pn: st.PN | 0, n: st.Ns | 0 };
        var mk, nextCk;
        return kdfChain(st.CKs).then(function (r) {
            mk = r.mk; nextCk = r.ck;
            st.CKs = r.ck;
            st.Ns = (st.Ns | 0) + 1;
            return mkKeyIv(mk);
        }).then(function (k) {
            return aead(k.key, k.iv, msgAd(st.ad, h), utf8(plaintext));
        }).then(function (ct) {
            return { header: h, ct: b64u(ct) };
        });
    }

    /**
     * 解密一条消息。乱序 / 丢包 / 对方连发多条后再回复都能处理。
     */
    function decrypt(st, h, ctB64) {
        if (!st || typeof st !== 'object') return Promise.reject(new Error('本设备没有该会话的密钥'));
        if (!h || typeof h.dh !== 'string' || typeof ctB64 !== 'string') return Promise.reject(new Error('消息头无效'));

        var key = h.dh + '|' + (h.n | 0);
        var pre = st.skipped && st.skipped[key];
        if (pre) {
            // 之前跳过的消息，现在补到了
            delete st.skipped[key];
            return mkKeyIv(pre).then(function (k) {
                return aeadOpen(k.key, k.iv, msgAd(st.ad, h), unb64u(ctB64));
            }).then(fromUtf8);
        }
        // 序号已消费过又没有留存密钥 → 重复投递或伪造，不能再用当前链密钥解
        if (st.DHr === h.dh && (h.n | 0) < (st.Nr | 0)) {
            return Promise.reject(new Error('消息重复或密钥已失效'));
        }

        var p = Promise.resolve();
        if (st.DHr !== h.dh) {
            // 对方换了棘轮公钥：先把旧链里跨过的密钥补齐，再推 DH 棘轮
            p = (st.DHr && st.CKr ? skipKeys(st, h.pn | 0) : Promise.resolve()).then(function () {
                return dhRatchet(st, h);
            });
        }
        return p.then(function () {
            return skipKeys(st, h.n | 0);
        }).then(function () {
            return kdfChain(st.CKr);
        }).then(function (r) {
            st.CKr = r.ck;
            st.Nr = (st.Nr | 0) + 1;
            trimSkipped(st);
            return mkKeyIv(r.mk);
        }).then(function (k) {
            return aeadOpen(k.key, k.iv, msgAd(st.ad, h), unb64u(ctB64));
        }).then(fromUtf8);
    }

    /* ===================== 信封 ===================== */

    /**
     * 信封格式：owee1: + JSON（内层各字段是 base64url，不再套一层 base64，
     * 否则一条 500 字的私信会因为两次 33% 膨胀直接超过服务端长度上限）。
     */
    function seal(obj) { return PREFIX + JSON.stringify(obj); }

    function isEnvelope(s) {
        return typeof s === 'string' && s.indexOf(PREFIX) === 0 && s.length > PREFIX.length + 8;
    }

    function open(s) {
        if (!isEnvelope(s)) return null;
        try { return JSON.parse(s.slice(PREFIX.length)); } catch (e) { return null; }
    }

    /** 安全码：12 组 5 位数字，两边看到的必须一致 —— 让用户能口头比对身份公钥 */
    function fingerprint(ikA, ikB) {
        return sha256(utf8(assocData(ikA, ikB))).then(function (h) {
            var out = [], i, v;
            for (i = 0; i < 12; i++) {
                v = ((h[i * 2] << 8) | h[i * 2 + 1]) % 100000;
                out.push(('0000' + v).slice(-5));
            }
            return out.join(' ');
        });
    }

    /** 设备号：由身份公钥派生，换设备必然变 —— 服务端用它判断「这台设备是哪一台」 */
    function deviceId(ikDhPub) {
        return sha256(unb64u(ikDhPub)).then(function (h) { return b64u(h.slice(0, 12)); });
    }

    return {
        available: function () { return !!SUB; },
        PREFIX: PREFIX,
        // 编解码
        utf8: utf8, fromUtf8: fromUtf8, b64u: b64u, unb64u: unb64u,
        // 密钥
        genIdentity: genIdentity, genSignedPrekey: genSignedPrekey, genOneTimePrekeys: genOneTimePrekeys,
        genDhPair: genDhPair,
        spkSignedBytes: spkSignedBytes, deviceId: deviceId,
        // 握手
        x3dhInit: x3dhInit, x3dhRespond: x3dhRespond, assocData: assocData,
        // 棘轮
        newState: newState, initAlice: initAlice, initBob: initBob,
        encrypt: encrypt, decrypt: decrypt,
        // 信封
        seal: seal, open: open, isEnvelope: isEnvelope, fingerprint: fingerprint,
        // 常量（测试与前端提示共用）
        MAX_SKIP: MAX_SKIP
    };
});
