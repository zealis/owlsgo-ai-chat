/**
 * 生成服务端解析器的跨语言测试向量：node .tools/gen-owed-env.js
 * 产出 .tools/owed-env.json（真实 X3DH 会话加密出的一条信封）
 */
const fs = require('fs');
const path = require('path');
const O = require('../plugins/e2ee-dm/js/owce.js');

(async () => {
    const a = await O.genIdentity(), b = await O.genIdentity();
    const spk = await O.genSignedPrekey(b, 1), opk = (await O.genOneTimePrekeys(7, 1))[0];
    const ek = await O.genDhPair();
    const ar = await O.x3dhInit(a, ek, {
        ikDh: b.dhPub, ikSig: b.sigPub, spk: spk.pub, spkId: spk.id, spkSig: spk.sig,
        opk: { id: opk.id, pub: opk.pub }
    });
    const st = await O.initAlice(ar.sk, ar.ad, spk.pub);
    const devA = await O.deviceId(a.dhPub), devB = await O.deviceId(b.dhPub);
    const m = await O.encrypt(st, '跨语言格式约定验证');
    const env = O.seal({ v: 1, s: devA, si: a.dhPub, ek: ek.pub, spk: 1, opk: 7, m: { [devB]: { h: m.header, c: m.ct } } });
    const out = path.join(__dirname, 'owed-env.json');
    fs.writeFileSync(out, JSON.stringify({ env, devA, devB, len: env.length }, null, 2));
    console.log('已写入 ' + out + '（' + env.length + ' 字符）');
})();
