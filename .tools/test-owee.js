/**
 * owce.js 协议核心自测（Node 运行，用 Node 自带的 WebCrypto 当浏览器）
 *
 *   node .tools/test-owee.js
 *
 * 覆盖的是「错了就查不出来」的部分：X3DH 双方是否算出同一个密钥、
 * 棘轮在乱序 / 往返 / 篡改 / 状态序列化下是否仍然正确。
 */
const assert = require('assert');
const OWEE = require('../plugins/e2ee-dm/js/owce.js');

let pass = 0;
function ok(name, cond, extra) {
    if (!cond) {
        console.error('  FAIL  ' + name + (extra ? '  ' + JSON.stringify(extra) : ''));
        process.exitCode = 1;
    } else {
        pass++;
        console.log('  ok    ' + name);
    }
}
async function rejects(name, p, re) {
    try { await p; ok(name, false, '应当抛错但没有'); }
    catch (e) { ok(name, !re || re.test(e.message), { got: e.message }); }
}

(async function main() {
    console.log('WebCrypto 可用:', OWEE.available());
    assert(OWEE.available(), 'Node 需要 globalThis.crypto（Node 16+）');

    /* ---------- 1. 身份与预密钥 ---------- */
    const alice = await OWEE.genIdentity();
    const bob = await OWEE.genIdentity();
    ok('身份密钥含两对（DH + 签名）', !!(alice.dhPub && alice.dhPriv && alice.sigPub && alice.sigPriv));
    ok('两方身份公钥不同', alice.dhPub !== bob.dhPub);

    const bobSpk = await OWEE.genSignedPrekey(bob, 1);
    const bobOpkList = await OWEE.genOneTimePrekeys(100, 3);
    ok('一次性预密钥批量生成 3 条', bobOpkList.length === 3);
    ok('一次性预密钥 id 递增', bobOpkList[0].id === 100 && bobOpkList[2].id === 102);

    const bobBundle = {
        ikDh: bob.dhPub, ikSig: bob.sigPub,
        spk: bobSpk.pub, spkId: bobSpk.id, spkSig: bobSpk.sig,
        opk: { id: bobOpkList[0].id, pub: bobOpkList[0].pub }
    };
    const badBundle = Object.assign({}, bobBundle, { spk: (await OWEE.genIdentity()).dhPub });
    await rejects('签名预密钥被换掉 → 必须报错', OWEE.x3dhInit(alice, { pub: '', priv: alice.dhPriv && alice.dhPriv }, badBundle), /签名预密钥/);

    /* ---------- 2. X3DH ---------- */
    const aEk = await (async () => {
        const id = await OWEE.genIdentity();
        return { pub: id.dhPub, priv: id.dhPriv };
    })();
    const aRes = await OWEE.x3dhInit(alice, aEk, bobBundle);
    const bRes = await OWEE.x3dhRespond(bob, bobSpk, bobOpkList[0], alice.dhPub, aEk.pub);
    ok('X3DH 双方共享密钥一致', aRes.sk === bRes.sk, { a: aRes.sk.slice(0, 8), b: bRes.sk.slice(0, 8) });
    ok('X3DH 双方关联数据一致', aRes.ad === bRes.ad);

    /* ---------- 3. 首条消息 A→B ---------- */
    const aState = await OWEE.initAlice(aRes.sk, aRes.ad, bobSpk.pub);
    let bState = await OWEE.initBob(bRes.sk, bRes.ad, bobSpk);

    let m1 = await OWEE.encrypt(aState, '第一条：你好');
    ok('首条消息带 DH 公钥头', typeof m1.header.dh === 'string' && m1.header.n === 0);
    ok('密文可解回原文', (await OWEE.decrypt(bState, m1.header, m1.ct)) === '第一条：你好');

    /* ---------- 4. B 回复触发 DH 棘轮 ---------- */
    let r1 = await OWEE.encrypt(bState, '收到，这是回复');
    ok('回复可解', (await OWEE.decrypt(aState, r1.header, r1.ct)) === '收到，这是回复');

    /* ---------- 5. 连续多条 + 往返 ---------- */
    let seq = [];
    for (let i = 0; i < 3; i++) seq.push(await OWEE.encrypt(aState, 'A连发' + i));
    let got = [];
    for (const m of seq) got.push(await OWEE.decrypt(bState, m.header, m.ct));
    ok('连发三条按序解开', got.join(',') === 'A连发0,A连发1,A连发2', got);

    /* ---------- 6. 乱序 ---------- */
    let ooo = [];
    for (let i = 0; i < 3; i++) ooo.push(await OWEE.encrypt(aState, '乱序' + i));
    const d2 = await OWEE.decrypt(bState, ooo[2].header, ooo[2].ct);
    const d0 = await OWEE.decrypt(bState, ooo[0].header, ooo[0].ct);
    const d1 = await OWEE.decrypt(bState, ooo[1].header, ooo[1].ct);
    ok('乱序 2→0→1 全部解开', d2 === '乱序2' && d0 === '乱序0' && d1 === '乱序1', [d2, d0, d1]);
    await rejects('同一条重复解密 → 拒绝', OWEE.decrypt(bState, ooo[0].header, ooo[0].ct), /重复|失效/);

    /* ---------- 7. 篡改 ---------- */
    let mT = await OWEE.encrypt(aState, '不许改我');
    const ctBytes = OWEE.unb64u(mT.ct);
    ctBytes[0] ^= 1;
    await rejects('密文被改一位 → 认证失败', OWEE.decrypt(bState, mT.header, OWEE.b64u(ctBytes)), /./);
    let mH = await OWEE.encrypt(aState, '头部也要认证');
    await rejects('消息头被改 → 认证失败', OWEE.decrypt(bState, Object.assign({}, mH.header, { n: 99 }), mH.ct), /./);

    /* ---------- 8. 状态可序列化（IndexedDB 存的就是 JSON） ---------- */
    let mS = await OWEE.encrypt(aState, '序列化前');
    const aJson = JSON.parse(JSON.stringify(aState));
    const bJson = JSON.parse(JSON.stringify(bState));
    ok('会话状态可 JSON 往返', !!aJson.DHs.priv.d && !!bJson.skipped);
    let mS2 = await OWEE.encrypt(aJson, '序列化后');
    ok('反序列化后仍能继续加密', (await OWEE.decrypt(bJson, mS2.header, mS2.ct)) === '序列化后');

    /* ---------- 9. 中间文检测 / 安全码 ---------- */
    const fp1 = await OWEE.fingerprint(alice.dhPub, bob.dhPub);
    const fp2 = await OWEE.fingerprint(bob.dhPub, alice.dhPub);
    ok('安全码双方一致（12 组 5 位）', fp1 === fp2 && /^(\d{5} ){11}\d{5}$/.test(fp1), fp1);

    const env = OWEE.seal({ v: 1, s: 'devA', m: { devB: { h: mS2.header, c: mS2.ct } } });
    ok('信封可识别', OWEE.isEnvelope(env) && !OWEE.isEnvelope('普通消息'));
    ok('信封可解析回原结构', OWEE.open(env).m.devB.c === mS2.ct);
    ok('普通文本不会被误当信封', OWEE.open('owee1:') === null);

    /* ---------- 10. 设备号 ---------- */
    const dA = await OWEE.deviceId(alice.dhPub);
    const dB = await OWEE.deviceId(bob.dhPub);
    ok('设备号由身份公钥派生且互不相同', dA !== dB && dA.length > 8);

    /* ---------- 11. 容量：一条 500 字中文私信的信封多大 ---------- */
    let big = '';
    for (let i = 0; i < 500; i++) big += '密';
    const mBig = await OWEE.encrypt(aState, big);
    const bigEnv = OWEE.seal({ v: 1, s: 'devA', m: { devB: { h: mBig.header, c: mBig.ct } } });
    console.log('  500 字中文 → 信封 ' + bigEnv.length + ' 字符（服务端上限需放宽到 ' +
        Math.ceil(bigEnv.length / 1000) + 'k 以上）');
    ok('500 字信封 < 3000 字符', bigEnv.length < 3000);

    console.log('\n通过 ' + pass + ' 项' + (process.exitCode ? '，有失败' : '，全部通过'));
})().catch(e => { console.error('测试崩溃:', e); process.exit(1); });
