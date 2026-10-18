/**
 * 红包插件 · 前台（red-packet v1.0.0）
 *
 * 三块职责：
 *   ① 工具栏「红包」按钮（HTML 由 main.php 的 chat.toolbar 钩子产出，这里只绑事件）；
 *   ② 发包弹窗：四种红包切换、实时校验、提交后把服务端返回的消息行插进聊天区；
 *   ③ 消息里的红包卡：核心没有「消息内容渲染」钩子，这里用 MutationObserver 扫
 *      .ow-msg（与 link-card / level-cultivation 同一做法），拿消息 ID 问服务端
 *      「这条是不是红包」，是就把气泡内容换成信封卡 —— 消息是增量渲染的
 *      （历史 / 长轮询 / 推送 / 引用跳转各走各的路径），事后扫 DOM 是唯一能一次
 *      覆盖全部来源的办法。服务端只认 plugin_red_packet.msg_id，伪造一句「红包」
 *      文字不会变出卡来。
 *
 * 约定：
 *   · 纯 ES5 + var/function，不用箭头函数 / let / fetch / 模板串；
 *   · 用户数据一律 esc() 后再拼 HTML；
 *   · 「积分」文案统一走 OwChat.pointsName()（后台可改名）。
 */
(function (w, d) {
    'use strict';

    // 后台页 / 登录页也会加载合并脚本：没有 OwChat.cfg 说明不是聊天页，安静退出
    if (!w.OwChat || !OwChat.cfg) return;
    if (w.__owRPLoaded) return;          // 幂等：合并资源被重复引入时不重复注册
    w.__owRPLoaded = 1;

    var $ = function (id) { return d.getElementById(id); };
    var esc = w.esc, toast = w.toast;
    var me = OwChat.cfg.me || {};

    /** 类名精确匹配（ES5 空格定界法，老内核没有 classList 也能用） */
    function hasCls(el, cls) {
        return !!(el && el.className && (' ' + String(el.className) + ' ').indexOf(' ' + cls + ' ') >= 0);
    }

    var TIP = {
        normal:   '每人获得相同的{n}，总额需能被个数整除。红包总额将从你的账户中直接扣除。',
        random:   '每人随机分到至少 1 {n}。红包总额将从你的账户中直接扣除。',
        password: '对方输入你设置的口令才能领取，每人金额相同（总额需能被个数整除）。总额将从你的账户中直接扣除。',
        welfare:  '领取者随机抽走一条还没被抽走的内容，抽完即止。福利红包不扣{n}。'
    };

    var RP = {
        kind: 'normal',
        busy: false,
        balance: parseInt(me.points, 10) || 0,
        pn: (OwChat.pointsName ? OwChat.pointsName() : '积分'),
        draft: { count: '5', total: '', pass: '', bless: '', items: '' },

        cards: {},        // msgId -> 卡片数据
        byId: {},         // packetId -> 卡片数据
        elByPacket: {},   // packetId -> 消息 DOM
        asked: {},        // msgId -> 已问过服务端
        timer: 0,
        detail: null,
        rec: null
    };
    w.OwRP = RP;

    /* =====================================================================
       一、工具栏按钮
       ===================================================================== */

    function mount() {
        var btn = $('owBtnRp');
        if (!btn) return;                   // 插件未启用（钩子没注册）：安静退出
        var dpn = btn.getAttribute('data-points-name');
        if (dpn) RP.pn = dpn;               // 服务端已按站点改名下发，优先用它
        btn.onclick = function (e) {
            if (e && e.stopPropagation) e.stopPropagation(); else w.event.cancelBubble = true;
            openComposer();
        };
    }

    /* =====================================================================
       二、发包弹窗
       ===================================================================== */

    function segHtml() {
        var map = [['normal', '普通'], ['random', '随机'], ['password', '口令'], ['welfare', '福利']];
        var h = '';
        for (var i = 0; i < map.length; i++) {
            h += '<button class="ow-seg-btn' + (RP.kind === map[i][0] ? ' is-active' : '') + '"'
               + ' type="button" data-k="' + map[i][0] + '" onclick="OwRP.setKind(\'' + map[i][0] + '\')">'
               + map[i][1] + '</button>';
        }
        return h;
    }

    function fieldsHtml() {
        var pn = esc(RP.pn);
        var h = '';
        if (RP.kind === 'welfare') {
            h += '<div class="ow-form-item"><label>福利内容（每行 1 条）</label>'
               + '<textarea class="ow-input owrp-ta" id="owRpItems" maxlength="15000"'
               + ' placeholder="支付宝口令：xxxxxx&#10;密钥：XXXX-XXXX-XXXX&#10;CDKey：ABCD-EFGH-IJKL"'
               + ' oninput="OwRP.onItems()"></textarea>'
               + '<div class="owrp-hint" id="owRpLines">共 0 条</div></div>';
        } else {
            h += '<div class="owrp-row">'
               + '<div class="ow-form-item"><label>红包个数</label>'
               + '<input class="ow-input" id="owRpCount" type="number" min="1" max="500" inputmode="numeric"'
               + ' value="' + esc(RP.draft.count) + '" oninput="OwRP.onNum()"></div>'
               + '<div class="ow-form-item"><label>总金额（' + pn + '）</label>'
               + '<input class="ow-input" id="owRpTotal" type="number" min="1" inputmode="numeric"'
               + ' value="' + esc(RP.draft.total) + '" placeholder="0" oninput="OwRP.onNum()"></div>'
               + '</div>';
            if (RP.kind === 'password') {
                h += '<div class="ow-form-item"><label>领取口令</label>'
                   + '<input class="ow-input" id="owRpPass" type="text" maxlength="20"'
                   + ' value="' + esc(RP.draft.pass) + '" placeholder="对方要输入这句话才能领"'
                   + ' autocomplete="off"></div>';
            }
            h += '<div class="owrp-hint" id="owRpPer"></div>';
        }
        h += '<div class="ow-form-item"><label>祝福语</label>'
           + '<input class="ow-input" id="owRpBless" type="text" maxlength="40"'
           + ' value="' + esc(RP.draft.bless) + '" placeholder="恭喜发财，大吉大利"></div>';
        return h;
    }

    function tipHtml() {
        return TIP[RP.kind].replace(/\{n\}/g, esc(RP.pn));
    }

    function openComposer() {
        if (!OwChat.cfg.me) { toast('请先登录后再发红包'); return; }
        if (RP.dmCheck()) return;
        RP.busy = false;
        OwChat.openModal(
            '<h3>发红包</h3>'
            + '<div class="ow-seg owrp-seg" id="owRpSeg">' + segHtml() + '</div>'
            + '<p class="owrp-tip" id="owRpTip">' + tipHtml() + '</p>'
            + '<div id="owRpFields">' + fieldsHtml() + '</div>'
            + '<div class="owrp-bal">账户余额 <b id="owRpBal">' + RP.balance + '</b> ' + esc(RP.pn) + '</div>'
            + '<div class="owrp-err" id="owRpErr"></div>'
            + '<div class="ow-modal-actions owrp-actions">'
            + '<button class="ow-btn ow-btn-ghost owrp-rec" type="button" onclick="OwRP.records()">红包记录</button>'
            + '<button class="ow-btn ow-btn-ghost" type="button" onclick="OwChat.closeModal()">取消</button>'
            + '<button class="ow-btn ow-btn-primary" id="owRpSend" type="button" onclick="OwRP.submit()">塞钱进红包</button>'
            + '</div>',
            430, 'owrp-modal'
        );
        onNum();
    }

    /** 私聊 / 未进入群聊时不给发：先提示，避免填完表单才被服务端拒 */
    RP.dmCheck = function () {
        if (OwChat.noticeMode) { toast('请先回到群聊再发红包'); return true; }
        if (!OwChat.dm && (parseInt(OwChat.room, 10) || 0) > 0) return false;
        toast(OwChat.dm ? '私聊中不支持发红包，请在群聊里发' : '请先从左侧进入一个群聊');
        return true;
    };

    RP.setKind = function (k) {
        if (RP.kind === k) return;
        RP.syncDraft();
        RP.kind = k;
        var seg = $('owRpSeg');
        if (seg) {
            seg.innerHTML = segHtml();
        }
        var tip = $('owRpTip');
        if (tip) tip.innerHTML = tipHtml();
        var box = $('owRpFields');
        if (box) box.innerHTML = fieldsHtml();
        var err = $('owRpErr');
        if (err) err.textContent = '';
        onNum();
    };

    /** 切换类型前把已填内容收进草稿，避免来回切一次就清空 */
    RP.syncDraft = function () {
        var c = $('owRpCount'), t = $('owRpTotal'), p = $('owRpPass'),
            b = $('owRpBless'), it = $('owRpItems');
        if (c) RP.draft.count = c.value;
        if (t) RP.draft.total = t.value;
        if (p) RP.draft.pass = p.value;
        if (b) RP.draft.bless = b.value;
        if (it) RP.draft.items = it.value;
    };

    /** 条数提示（福利） */
    RP.onItems = function () {
        var ta = $('owRpItems'), hint = $('owRpLines');
        if (!ta || !hint) return;
        RP.draft.items = ta.value;
        var n = countLines(ta.value);
        hint.className = 'owrp-hint' + (n > 50 ? ' is-bad' : '');
        hint.textContent = n > 50 ? ('共 ' + n + ' 条，最多 50 条（超出部分会被忽略）') : ('共 ' + n + ' 条');
    };

    function countLines(s) {
        var arr = String(s || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n'), n = 0;
        for (var i = 0; i < arr.length; i++) if (arr[i].replace(/^\s+|\s+$/g, '') !== '') n++;
        return n;
    }

    /** 金额 / 个数联动提示：整除、每份至少 1 */
    function onNum() {
        var el = $('owRpPer');
        if (!el) return;
        var cnt = parseInt(($('owRpCount') || {}).value, 10) || 0;
        var tot = parseInt(($('owRpTotal') || {}).value, 10) || 0;
        if (!cnt || !tot) { el.className = 'owrp-hint'; el.textContent = ''; return; }
        if (RP.kind === 'random') {
            if (tot < cnt) {
                el.className = 'owrp-hint is-bad';
                el.textContent = '总额不能少于个数（每份至少 1 ' + RP.pn + '）';
            } else {
                el.className = 'owrp-hint';
                el.textContent = '每份随机，平均 ' + (Math.floor(tot / cnt)) + ' ' + RP.pn;
            }
            return;
        }
        if (tot % cnt !== 0) {
            el.className = 'owrp-hint is-bad';
            el.textContent = '总额需能被个数整除（当前除不尽，建议 ' + (Math.floor(tot / cnt) * cnt) + ' 或 '
                + (Math.ceil(tot / cnt) * cnt) + '）';
        } else {
            el.className = 'owrp-hint';
            el.textContent = '每人 ' + (tot / cnt) + ' ' + RP.pn + '，共 ' + cnt + ' 份';
        }
    }
    RP.onNum = onNum;

    function showErr(msg) {
        var el = $('owRpErr');
        if (el) el.textContent = msg || '';
        if (msg) toast(msg);
    }

    RP.submit = function () {
        if (RP.busy) return;
        RP.syncDraft();
        var cnt = parseInt(RP.draft.count, 10) || 0;
        var tot = parseInt(RP.draft.total, 10) || 0;
        var pass = String(RP.draft.pass || '').replace(/^\s+|\s+$/g, '');
        var items = String(RP.draft.items || '');

        if (RP.kind === 'welfare') {
            if (countLines(items) < 1) { showErr('请至少填写一条福利内容（每行一条）'); return; }
        } else {
            if (cnt < 1) { showErr('请填写红包个数'); return; }
            if (tot < 1) { showErr('请填写红包总额'); return; }
            if (tot < cnt) { showErr('总额不能少于个数（每份至少 1 ' + RP.pn + '）'); return; }
            if (RP.kind !== 'random' && tot % cnt !== 0) { showErr('总额需能被个数整除'); return; }
            if (tot > RP.balance) { showErr('余额不足，当前 ' + RP.balance + ' ' + RP.pn); return; }
            if (RP.kind === 'password' && pass === '') { showErr('请设置领取口令'); return; }
        }

        var btn = $('owRpSend');
        RP.busy = true;
        if (btn) { btn.disabled = true; btn.textContent = '发送中…'; }
        var errEl = $('owRpErr');
        if (errEl) errEl.textContent = '';

        OwApi.post('plugin_red_packet_create', {
            kind: RP.kind,
            room_id: (parseInt(OwChat.room, 10) || 0),
            count: RP.kind === 'welfare' ? '' : cnt,
            total: RP.kind === 'welfare' ? '' : tot,
            password: RP.kind === 'password' ? pass : '',
            blessing: String(RP.draft.bless || '').replace(/^\s+|\s+$/g, ''),
            items: RP.kind === 'welfare' ? items : ''
        }, function (r) {
            RP.busy = false;
            if (btn) { btn.disabled = false; btn.textContent = '塞钱进红包'; }
            if (!r || !r.ok) { showErr((r && r.msg) || '发送失败，请稍后再试'); return; }
            if (typeof r.balance === 'number') {
                RP.balance = r.balance;
                if (OwChat.cfg.me) OwChat.cfg.me.points = r.balance;
            }
            if (r.card && r.card.msg_id) {
                RP.cards[r.card.msg_id] = r.card;
                RP.byId[r.card.id] = r.card;
            }
            OwChat.closeModal();
            RP.draft = { count: RP.draft.count, total: '', pass: '', bless: '', items: '' };
            toast(r.msg || '红包已发出');
            if (r.msg_row) {
                try { OwChat.addMessage(r.msg_row); } catch (e) {}
                try { OwChat.scrollBottom(); } catch (e) {}
            } else {
                try { OwChat.loadConversations(); } catch (e) {}
            }
            schedule();
        });
    };

    /* =====================================================================
       三、消息里的红包卡
       ===================================================================== */

    function cardHtml(p) {
        var done = p.status !== 'active';
        var sub = esc(p.kind_name);
        if (p.kind === 'welfare') {
            sub += done ? ' · ' + esc(p.status_text) : ' · 剩 ' + p.left + ' 份';
        } else {
            sub += done ? ' · ' + esc(p.status_text) : ' · 已领 ' + (p.cnt - p.left) + '/' + p.cnt;
        }
        // 「你已领取」不写进副标题：右侧铜钱位已经是「已领」，再写一遍会把
        // 一行本来就不长的副标题挤成省略号（移动端 208px 宽尤其明显）。
        // 发包人本人也能领，所以铜钱位不再区分「明细」：没领过就是「开」。
        var coin = p.claimed ? '已领' : (done ? '查看' : '开');
        return '<div class="owrp-card' + (done ? ' is-done' : '') + '"'
             + ' onclick="OwRP.open(' + p.id + ')" role="button" tabindex="0"'
             + ' onkeydown="if(event.key===\'Enter\'||event.key===\' \'){event.preventDefault();OwRP.open(' + p.id + ');}"'
             + ' aria-label="' + esc(p.kind_name) + '：' + esc(p.blessing) + '">'
             + '<div class="owrp-card-main">'
             + '<div class="owrp-card-t">' + esc(p.blessing) + '</div>'
             + '<div class="owrp-card-s">' + sub + '</div>'
             + '</div>'
             + '<span class="owrp-coin">' + coin + '</span>'
             + '</div>';
    }

    function paint(el, p) {
        if (!el || !p) return;
        if (hasCls(el, 'recalled') || hasCls(el, 'deleted')) return;
        var bubble = el.querySelector('.ow-msg-content');
        if (!bubble) return;
        bubble.className = 'ow-msg-content owrp-bubble';
        bubble.innerHTML = cardHtml(p);
        el.setAttribute('data-owrp', '1');
        RP.elByPacket[p.id] = el;
    }

    /** 重画某个红包（领取 / 详情里状态变了之后） */
    RP.repaint = function (p) {
        if (!p) return;
        var el = RP.elByPacket[p.id] || (p.msg_id ? d.getElementById('owMsg' + p.msg_id) : null);
        if (el && el.parentNode) {
            el.setAttribute('data-owrp', '');
            paint(el, p);
        }
    };

    function schedule() {
        if (RP.timer) return;
        RP.timer = setTimeout(function () { RP.timer = 0; scan(); }, 140);
    }

    function scan() {
        var box = $('owMessages');
        if (!box || !box.getElementsByClassName) return;
        var els = box.getElementsByClassName('ow-msg'), ids = [], i, el, id;
        for (i = 0; i < els.length; i++) {
            el = els[i];
            if (el.getAttribute('data-owrp')) continue;
            id = parseInt(String(el.id || '').replace('owMsg', ''), 10) || 0;
            if (!id) continue;
            if (RP.cards[id]) { paint(el, RP.cards[id]); continue; }
            if (RP.asked[id]) continue;
            if (ids.length >= 60) break;      // 一批最多问 60 条，剩下的下一轮
            RP.asked[id] = 1;
            ids.push(id);
        }
        if (!ids.length) return;
        OwApi.post('plugin_red_packet_cards', { ids: ids.join(',') }, function (r) {
            var items = (r && r.ok && r.items) || {}, k;
            for (k in items) {
                if (items.hasOwnProperty(k)) {
                    RP.cards[k] = items[k];
                    RP.byId[items[k].id] = items[k];
                }
            }
            // 没命中的也打个标：以后不再重复请求
            for (i = 0; i < ids.length; i++) {
                if (items[ids[i]]) continue;
                var e2 = d.getElementById('owMsg' + ids[i]);
                if (e2 && !e2.getAttribute('data-owrp')) e2.setAttribute('data-owrp', '0');
            }
            schedule();
        });
    }

    /* =====================================================================
       四、领取 / 详情 / 记录
       ===================================================================== */

    /**
     * 点卡片：进行中且没领过 → 打开「开红包」弹窗（**不直接领取**，还需点一下「开」）；
     * 已领 / 已领完 / 已过期 → 详情。
     * 为什么要多一步：卡在聊天流里、又贴着输入框，误触一下就把红包拆了是不可接受的；
     * 弹窗同时把「谁的红包 / 祝福语 / 类型 / 金额或份数」摆清楚，再让用户自己按「开」。
     */
    RP.open = function (id) {
        var p = RP.byId[id];
        if (p && !p.claimed && p.status === 'active') { openEnv(p); return; }
        loadDetail(id);
    };

    /* ---------- 开红包弹窗 ---------- */

    /** 弹窗骨架（未拆状态）：谁的红包、祝福语、类型、口令输入（口令红包）、铜钱「开」 */
    function envHtml(p) {
        var pn = esc(p.points_name || RP.pn);
        var av = p.sender_avatar
            ? '<span class="owrp-env-av"><img src="' + esc(p.sender_avatar) + '" alt=""></span>'
            : '';
        var hint;
        if (p.kind === 'welfare') {
            hint = '点击开启，抽取一条福利';
        } else if (p.kind === 'random') {
            hint = '点击开启，拼手气领 ' + pn;
        } else {
            var per = Math.floor((parseInt(p.total, 10) || 0) / Math.max(1, parseInt(p.cnt, 10) || 1));
            hint = '点击开启，领取 ' + per + ' ' + pn;
        }
        return '<div class="owrp-env" role="dialog" aria-modal="true" aria-label="开红包">'
             + '<button class="owrp-env-x" type="button" aria-label="关闭" onclick="OwRP.closeEnv()">✕</button>'
             + '<div class="owrp-env-fu" aria-hidden="true">福</div>'
             + '<div class="owrp-env-body" id="owRpEnvBody">'
             +   '<div class="owrp-env-top">'
             +     '<div class="owrp-env-head">' + av
             +       '<span class="owrp-env-who">' + esc(p.sender) + ' 的红包</span></div>'
             +     '<div class="owrp-env-bless">' + esc(p.blessing) + '</div>'
             +     '<div class="owrp-env-tag">' + esc(p.kind_name) + '</div>'
             +     (p.kind === 'password'
                      ? '<div class="owrp-env-pass"><input class="owrp-env-input" id="owRpEnvPass" type="text"'
                        + ' maxlength="20" autocomplete="off" placeholder="请输入口令"'
                        + ' onkeydown="if(event.key===\'Enter\')OwRP.envClaim()"></div>'
                      : '')
             +   '</div>'
             +   '<div class="owrp-env-bottom">'
             +     '<div class="owrp-env-coin-wrap"><div class="owrp-env-coin" id="owRpEnvCoin" role="button"'
             +       ' tabindex="0" aria-label="开红包" onclick="OwRP.envClaim()"'
             +       ' onkeydown="if(event.key===\'Enter\'||event.key===\' \'){event.preventDefault();OwRP.envClaim();}">开</div></div>'
             +     '<div class="owrp-env-hint" id="owRpEnvHint">' + hint + '</div>'
             +     '<div class="owrp-env-err" id="owRpEnvErr"></div>'
             +   '</div>'
             + '</div>'
             + '</div>';
    }

    function openEnv(p) {
        RP.closeEnv();
        RP.env = p;
        var el = d.createElement('div');
        // 复用核心的 .ow-modal-mask（遮罩色 / 模糊 / 居中 / 20px 留白都在核心样式里），
        // 只把层级抬到 300，保证压住聊天页的右键菜单与其它浮层。
        el.className = 'ow-modal-mask owrp-env-mask';
        el.id = 'owRpEnv';
        el.style.display = 'flex';
        el.innerHTML = envHtml(p);
        // 点遮罩空白处关闭；点面板内部不关（避免误触铜钱时连带关闭）
        el.onclick = function (e) {
            e = e || w.event;
            if ((e.target || e.srcElement) === el) RP.closeEnv();
        };
        d.body.appendChild(el);
        // 弹窗期间锁住页面滚动（关闭时原样还原，别把别人的 inline 值覆盖掉）
        RP._envOverflow = d.documentElement.style.overflow;
        d.documentElement.style.overflow = 'hidden';
        if (d.addEventListener) d.addEventListener('keydown', envKeydown, false);
        var inp = $('owRpEnvPass');
        if (inp) { try { inp.focus(); } catch (e) {} }
    }

    function envKeydown(e) {
        e = e || w.event;
        if (e && e.key === 'Escape') RP.closeEnv();
    }

    RP.closeEnv = function () {
        var el = $('owRpEnv');
        if (el && el.parentNode) el.parentNode.removeChild(el);
        RP.env = null;
        if (RP._envOverflow !== undefined) {
            d.documentElement.style.overflow = RP._envOverflow;
            RP._envOverflow = undefined;
        }
        if (d.removeEventListener) d.removeEventListener('keydown', envKeydown, false);
    };

    function envErr(msg) {
        var el = $('owRpEnvErr');
        if (el) el.textContent = msg || '';
    }

    /** 点「开」：口令红包先取口令，其余直接请求；成功后在同一个弹窗里翻到结果态 */
    RP.envClaim = function () {
        var p = RP.env;
        if (!p || RP.busy) return;
        var pass = '', inp = $('owRpEnvPass');
        if (p.kind === 'password') {
            pass = inp ? String(inp.value || '').replace(/^\s+|\s+$/g, '') : '';
            if (pass === '') {
                envErr('请输入口令');
                if (inp) { try { inp.focus(); } catch (e) {} }
                return;
            }
        }
        envErr('');
        var coin = $('owRpEnvCoin');
        if (coin) { coin.className = 'owrp-env-coin is-busy'; coin.innerHTML = '···'; }
        doClaim(p.id, pass, function (msg) {
            var c = $('owRpEnvCoin');
            if (c) { c.className = 'owrp-env-coin'; c.innerHTML = '开'; }
            var isPass = p.kind === 'password' && String(msg).indexOf('口令') >= 0;
            // 非口令类失败（被别人抢完 / 已过期）：卡片状态已经变了，
            // 清掉缓存并把 data-owrp 摘掉，让它重新问一次服务端（铜钱位会变成「查看」）。
            if (!isPass && p.msg_id) {
                delete RP.cards[p.msg_id];
                var mEl = d.getElementById('owMsg' + p.msg_id);
                if (mEl) mEl.setAttribute('data-owrp', '');
                schedule();
            }
            envErr(isPass ? '口令不正确' : (msg || '没能打开，请稍后再试'));
        }, function (r) {
            envResult(r);
        });
    };

    /** 拆开后的结果态（仍在弹窗里，点「收下」才回聊天） */
    function envResult(r) {
        var body = $('owRpEnvBody');
        if (!body) return;
        var p = r.card || RP.env || {};
        var pn = esc(p.points_name || RP.pn);
        RP.env = p;
        // 结果态改成整块居中：拆开后视线落在金额上，不再让它贴着页面顶端
        body.className = 'owrp-env-body is-result';
        var h = '<div class="owrp-env-top"><div class="owrp-env-bless">' + esc(p.blessing || '') + '</div>';
        if (r.item) {
            h += '<div class="owrp-env-hint">' + (r.already ? '你之前抽到的是' : '你抽到了这条福利') + '</div>'
               + '<div class="owrp-env-item" id="owRpWbox">' + esc(r.item) + '</div>'
               + '<div class="owrp-env-hint">仅你可见，请自行保存（红包记录里也能回看）</div>'
               + '</div><div class="owrp-env-bottom"><div class="owrp-env-actions">'
               + '<button class="owrp-env-btn" type="button" onclick="OwRP.copyItem()">复制</button>'
               + '<button class="owrp-env-btn is-main" type="button" onclick="OwRP.closeEnv()">收下</button>'
               + '</div></div>';
        } else {
            h += '<div class="owrp-env-amt">' + (parseInt(r.amount, 10) || 0) + '<small>' + pn + '</small></div>'
               + '<div class="owrp-env-hint">' + (r.already ? '这个红包你之前已经领过了，金额已入账' : '已存入你的账户') + '</div>'
               + '</div><div class="owrp-env-bottom"><div class="owrp-env-actions">'
               + '<button class="owrp-env-btn" type="button" onclick="OwRP.envDetail()">看看大家手气</button>'
               + '<button class="owrp-env-btn is-main" type="button" onclick="OwRP.closeEnv()">收下</button>'
               + '</div></div>';
        }
        body.innerHTML = h;
        if (r.card) {
            RP.byId[r.card.id] = r.card;
            if (r.card.msg_id) RP.cards[r.card.msg_id] = r.card;
        }
    }

    /** 从弹窗跳到详情（收起弹窗，避免两层浮层叠着） */
    RP.envDetail = function () {
        var p = RP.env;
        RP.closeEnv();
        if (p) loadDetail(p.id);
    };

    RP.claimWithPass = function (id) {
        var el = $('owRpPassIn');
        // 详情弹窗里只有口令红包才有输入框；普通 / 随机红包直接拆
        var pass = el ? String(el.value || '').replace(/^\s+|\s+$/g, '') : '';
        if (el && pass === '') {
            var err = $('owRpPassErr');
            if (err) err.textContent = '请输入口令';
            return;
        }
        doClaim(id, pass, function () {
            // 详情弹窗里的失败只会是口令不对（其余情况服务端会先判已领/过期）
            if (el) {
                var e2 = $('owRpPassErr');
                if (e2) e2.textContent = '口令不正确';
            }
        });
    };

    /**
     * 领取请求。$fail(msg) 失败回调、$ok(r) 成功回调（不传就走原来的结果弹窗）。
     * 弹窗与详情弹窗共用这一条链路，避免两套「领取 → 记账 → 重画卡片」逻辑漂移。
     */
    function doClaim(id, pass, fail, ok) {
        if (RP.busy) return;
        RP.busy = true;
        OwApi.post('plugin_red_packet_claim', { id: id, pass: pass || '' }, function (r) {
            RP.busy = false;
            if (!r || !r.ok) {
                var msg = (r && r.msg) || '领取失败';
                toast(msg);
                if (fail) fail(msg);
                return;
            }
            if (typeof r.balance === 'number') {
                RP.balance = r.balance;
                if (OwChat.cfg.me) OwChat.cfg.me.points = r.balance;
            }
            if (r.card) {
                RP.byId[r.card.id] = r.card;
                if (r.card.msg_id) RP.cards[r.card.msg_id] = r.card;
                RP.repaint(r.card);
            }
            if (ok) ok(r); else resultModal(r);
        });
    }

    function resultModal(r) {
        var card = r.card || {}, pn = esc(card.points_name || RP.pn);
        var h = '<h3>红包已拆开</h3><div class="owrp-result">'
              + '<div class="owrp-result-coin">开</div>';
        if (r.item) {
            h += '<div class="owrp-result-s">' + (r.already ? '你之前抽到的是' : '你抽到了这条福利') + '</div>'
               + '<div class="owrp-wbox" id="owRpWbox">' + esc(r.item) + '</div>'
               + '<div class="owrp-result-s">仅你可见，请自行保存（关闭后仍可在红包记录里查看）</div>'
               + '<div class="owrp-wcopy"><button class="ow-btn ow-btn-ghost" type="button"'
               + ' onclick="OwRP.copyItem()">复制</button></div>';
        } else {
            h += '<div class="owrp-result-amt">' + (parseInt(r.amount, 10) || 0) + ' <small>' + pn + '</small></div>'
               + '<div class="owrp-result-s">' + (r.already ? '这个红包你之前已经领过了，金额已入账' : '已存入你的账户') + '</div>';
        }
        if (card.blessing) {
            h += '<div class="owrp-result-bless">' + esc(card.blessing) + '</div>';
        }
        h += '</div><div class="ow-modal-actions">'
           + '<button class="ow-btn ow-btn-primary" type="button" onclick="OwChat.closeModal()">收下</button>'
           + '</div>';
        OwChat.openModal(h, 380, 'owrp-modal');
    }

    RP.copyItem = function () {
        var box = $('owRpWbox');
        if (!box) return;
        var text = box.textContent || '';
        var ta = d.createElement('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;left:-9999px;top:0';
        d.body.appendChild(ta);
        ta.select();
        var ok = false;
        try { ok = d.execCommand('copy'); } catch (e) {}
        d.body.removeChild(ta);
        toast(ok ? '已复制' : '复制失败，请长按选择文本');
    };

    function loadDetail(id) {
        OwApi.post('plugin_red_packet_info', { id: id }, function (r) {
            if (!r || !r.ok) { toast((r && r.msg) || '红包不存在'); return; }
            RP.detail = r;
            detailModal(r);
        });
    }

    function detailModal(r) {
        var p = r.card, pn = esc(p.points_name || RP.pn);
        var h = '<h3>' + (p.mine ? '我发出的红包' : '红包详情') + '</h3>'
              + '<div class="owrp-detail-top">';
        if (p.kind === 'welfare') {
            h += '<div class="owrp-detail-amt">' + p.left + ' <small>份剩余</small></div>';
        } else {
            h += '<div class="owrp-detail-amt">' + p.total + ' <small>' + pn + '</small></div>';
        }
        h += '<div class="owrp-detail-bless">' + esc(p.blessing) + '</div>'
           + '<div class="owrp-detail-meta">' + esc(p.sender) + ' · ' + esc(p.kind_name)
           + ' · ' + esc(p.status_text) + ' · 共 ' + p.cnt + ' 份</div>';
        if (r.claimed) {
            // 福利红包没有金额：写「0 金币」会让人以为领了个空包
            h += '<div class="owrp-detail-meta">' + (p.kind === 'welfare'
                ? '你已领取 1 份'
                : '你已领取 ' + (parseInt(p.my_amount, 10) || 0) + ' ' + pn) + '</div>';
            if (r.my_item) {
                h += '<div class="owrp-wbox" id="owRpWbox">' + esc(r.my_item) + '</div>'
                   + '<div class="owrp-wcopy"><button class="ow-btn ow-btn-ghost" type="button"'
                   + ' onclick="OwRP.copyItem()">复制</button></div>';
            }
        }
        h += '</div>';

        // 领取名单（福利红包只显示「已领」，不显示别人抽到的内容）
        h += '<div class="owrp-claims" id="owRpClaims"></div>';

        // p.mine = 是不是我发的（只影响标题文案）；r.claimed = 我领过没有。
        // 发包人本人也可以领一次，所以这里不再排除 p.mine。
        if (!r.claimed && p.status === 'active') {
            if (p.kind === 'password') {
                h += '<div class="ow-form-item" style="margin-top:12px"><label>领取口令</label>'
                   + '<input class="ow-input" id="owRpPassIn" type="text" maxlength="20" autocomplete="off"'
                   + ' onkeydown="if(event.key===\'Enter\')OwRP.claimWithPass(' + p.id + ')"></div>'
                   + '<div class="owrp-err" id="owRpPassErr"></div>';
            }
            h += '<div class="ow-modal-actions">'
               + '<button class="ow-btn ow-btn-ghost" type="button" onclick="OwChat.closeModal()">关闭</button>'
               + '<button class="ow-btn ow-btn-primary" type="button" onclick="OwRP.claimWithPass(' + p.id + ')">开红包</button>'
               + '</div>';
        } else {
            h += '<div class="ow-modal-actions">'
               + '<button class="ow-btn ow-btn-ghost" type="button" onclick="OwChat.closeModal()">关闭</button>'
               + '</div>';
        }

        OwChat.openModal(h, 400, 'owrp-modal');

        var list = r.claims || [];
        var box = $('owRpClaims');
        if (!box) return;
        if (!list.length) {
            box.innerHTML = '<div class="owrp-empty">还没有人领取</div>';
            return;
        }
        var lh = '';
        for (var i = 0; i < list.length; i++) {
            var c = list[i];
            lh += '<div class="owrp-claim">'
                + '<span class="owrp-claim-n">' + esc(c.nickname) + '</span>'
                + '<span class="owrp-claim-a">' + (p.kind === 'welfare'
                    ? '已领取 · ' : (parseInt(c.amount, 10) || 0) + ' ' + pn + ' · ')
                + esc(c.time) + '</span>'
                + '</div>';
        }
        box.innerHTML = lh;
    }

    /* ---------- 红包记录（30 天） ---------- */

    RP.records = function () {
        OwApi.post('plugin_red_packet_records', {}, function (r) {
            if (!r || !r.ok) { toast((r && r.msg) || '加载失败'); return; }
            RP.rec = r;
            recordsModal('recv');
        });
    };

    function recRows(tab) {
        var rec = RP.rec || {}, list = (tab === 'sent' ? rec.sent : rec.recv) || [];
        var pn = esc(rec.points_name || RP.pn), h = '', i, r;
        if (!list.length) {
            return '<div class="owrp-empty">' + (tab === 'sent' ? '最近 30 天还没发过红包' : '最近 30 天还没收到红包') + '</div>';
        }
        for (i = 0; i < list.length; i++) {
            r = list[i];
            var title = esc(r.blessing || r.kind_name);
            var sub, amt, cls;
            if (tab === 'sent') {
                sub = esc(r.kind_name) + ' · 已领 ' + (r.cnt - r.left) + '/' + r.cnt + ' · ' + esc(r.time);
                amt = (r.total > 0 ? '-' + r.total + ' ' + pn : '福利');
                cls = ' is-out';
            } else {
                sub = esc(r.sender) + ' · ' + esc(r.kind_name) + ' · ' + esc(r.time);
                amt = r.is_item ? '福利' : '+' + (parseInt(r.amount, 10) || 0) + ' ' + pn;
                cls = '';
            }
            h += '<div class="owrp-rec' + (tab === 'recv' ? ' is-link' : '') + '"'
               + (tab === 'recv' ? ' onclick="OwRP.open(' + (parseInt(r.id, 10) || 0) + ')"' : '')
               + '>'
               + '<div class="owrp-rec-l"><div class="owrp-rec-t">' + title + '</div>'
               + '<div class="owrp-rec-s">' + sub + '</div></div>'
               + '<div class="owrp-rec-a' + cls + '">' + amt + '</div>'
               + '</div>';
        }
        return h;
    }

    function recordsModal(tab) {
        OwChat.openModal(
            '<h3>红包记录</h3>'
            + '<p class="owrp-tip">只显示最近 30 天的记录。</p>'
            + '<div class="ow-seg owrp-seg">'
            + '<button class="ow-seg-btn' + (tab !== 'sent' ? ' is-active' : '') + '" type="button"'
            + ' onclick="OwRP.recTab(\'recv\')">我收到的</button>'
            + '<button class="ow-seg-btn' + (tab === 'sent' ? ' is-active' : '') + '" type="button"'
            + ' onclick="OwRP.recTab(\'sent\')">我发出的</button>'
            + '</div>'
            + '<div class="owrp-rec-list" id="owRpRecList">' + recRows(tab) + '</div>'
            + '<div class="ow-modal-actions">'
            + '<button class="ow-btn ow-btn-ghost" type="button" onclick="OwChat.closeModal()">关闭</button>'
            + '</div>',
            400, 'owrp-modal'
        );
    }

    RP.recTab = function (tab) {
        var seg = d.querySelector ? d.querySelectorAll('.owrp-modal .ow-seg-btn') : null;
        if (seg && seg.length >= 2) {
            seg[0].className = 'ow-seg-btn' + (tab === 'sent' ? '' : ' is-active');
            seg[1].className = 'ow-seg-btn' + (tab === 'sent' ? ' is-active' : '');
        }
        var list = $('owRpRecList');
        if (list) list.innerHTML = recRows(tab);
    };

    /* =====================================================================
       五、启动
       ===================================================================== */

    function boot() {
        mount();
        var box = $('owMessages');
        if (!box) return;
        if (w.MutationObserver) {
            new w.MutationObserver(function () { schedule(); })
                .observe(box, { childList: true, subtree: true });
        } else {
            setInterval(scan, 1200);      // 老内核兜底：轮询扫一遍（幂等，命中过的会跳过）
        }
        schedule();
    }

    if (d.readyState === 'loading') {
        d.addEventListener ? d.addEventListener('DOMContentLoaded', boot, false) : (d.onreadystatechange = boot);
    } else {
        boot();
    }
})(window, document);
