/**
 * 批量操作插件 · 聊天页（batch-ops v1.2.1）
 *
 * ── 两块内容 ──────────────────────────────────────────────────
 * ① 消息多选：消息右键「多选」（或「转发」单条直达）进入选择模式，
 *    消息区底部出现操作条（仿参考样式：圆形图标按钮 + 文字）：
 *    逐条转发 / 合并转发 / 保存至电脑 / 收藏 / 删除 / 复制 / 退出。
 *    勾选框跨渲染路径都由 MutationObserver 统一兜住（与 link-card 同思路）。
 * ② 通用选择器 OwBatch.picker()：仿参考样式（标题 + 说明 + 搜索框 + 分栏列表 + 行内动作按钮
 *    + 底部邀请链接区）。当前接了两种业务：转发（逐条 / 合并）与邀请好友进群。
 *    其他插件也可以直接调这个轮子。
 *
 * ── 为什么转发走前端逐条队列而不是新的服务端批量接口 ────────────
 * 逐条调核心 send 才能完整继承全部服务端语义：敏感词过滤、禁言、好友关系、
 * 群成员校验、P2 私聊可见性、message.after_send 钩子（WS 推送加速）……
 * 代价是受「发言频率限制」（默认 10 秒 8 条）约束：数量多时队列撞上限流后
 * 自动等待窗口重置再续发（进度实时显示在按钮上），不静默丢消息。
 * 合并转发则是「N 条合成 1 条」：文字按「昵称：内容」摘要，图片作 rich 附件
 * （≤9 张，只收本站 uploads/ 下的），一次 send 完成。
 *
 * ── 约束（踩过坑，别改） ──────────────────────────────────────
 * · ES5：与核心 chat.js 同一兼容基线（无箭头函数 / let / const / 模板串）。
 * · 样式一律引核心 --ow-* 变量（见 style.css），不写死颜色。
 * · 列表数据全部来自核心既有 action（conversations / friends / rooms / room_members），
 *   搜索是**前端过滤**：核心 search 接口按用户 10 秒 3 次限流，做输入即时过滤必被拒。
 * · 选择状态按消息 id 存（DOM 会因 500 条裁剪/重绘变化），操作时从 msgCache 取数据，
 *   取不到（已滚出/已撤回）的条目在结果里如实报「跳过 N 条」。
 * · 删除 / 收藏 / 保存走核心既有接口：msg_delete（敏感，一次性票据）/ sticker_add /
 *   file_download（带签名 GET，与核心文件卡同一拼法）。
 */
(function (w, d) {
    'use strict';
    if (!w.OwChat || !w.OwChat.cfg) return;   // 后台 / 登录页也会加载合并脚本
    if (w.__owBatchOps) return;               // 幂等：合并资源被重复引入时不重复注册
    w.__owBatchOps = 1;

    var esc = w.esc, toast = w.toast, fmtUid = w.fmtUid, owTr = w.owTr;

    var MAX_SEL = 20;        // 单次最多选择的消息条数（再多请分次转发）
    var BATCH = 20;          // 选择器列表单批渲染条数（与核心搜索结果同一档）
    var SEND_GAP = 380;      // 转发队列两条之间的最小间隔（毫秒）
    var RATE_WAIT = 2500;    // 撞上「发言过于频繁」后的重试等待
    var RATE_TRIES = 6;      // 撞限流后的最大重试次数（覆盖 10 秒窗口）

    /* 放大镜：与核心 ow_icon('search') 逐字同 path（服务端图标表不经过 JS，此处内联一份） */
    var SEARCH_SVG = '<svg class="ow-ico owb-sico" width="15" height="15" viewBox="0 0 24 24" fill="none"'
        + ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        + '<circle cx="11" cy="11" r="6.6"/><path d="M20.2 20.2l-4.5-4.5"/></svg>';
    /* 合并转发（层叠）与保存至电脑（下载托盘）：核心图标表没有对应条目，内联自有字形 */
    var MERGE_SVG = '<svg class="ow-ico owb-acti" width="16" height="16" viewBox="0 0 24 24" fill="none"'
        + ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        + '<path d="M12 3.5l8.5 4.7L12 12.9 3.5 8.2z"/><path d="M4.2 12.3l7.8 4.3 7.8-4.3"/><path d="M4.2 16.2l7.8 4.3 7.8-4.3"/></svg>';
    var SAVE_SVG = '<svg class="ow-ico owb-acti" width="16" height="16" viewBox="0 0 24 24" fill="none"'
        + ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        + '<path d="M12 4v10.5"/><path d="M7.8 10.8l4.2 4.2 4.2-4.2"/><path d="M4.5 19.5h15"/></svg>';

    function byId(id) { return d.getElementById(id); }
    function actor() { return (w.OwChat.cfg && w.OwChat.cfg.actor) || {}; }
    function isUser() { return actor().kind === 'user'; }
    function isAdmin() { return actor().role === 'admin'; }

    function addCls(el, c) {
        if (!el || (' ' + el.className + ' ').indexOf(' ' + c + ' ') >= 0) return;
        el.className = (el.className + ' ' + c).replace(/^\s+|\s+$/g, '');
    }
    function delCls(el, c) {
        if (!el) return;
        el.className = (' ' + el.className + ' ').split(' ' + c + ' ').join(' ').replace(/^\s+|\s+$/g, '');
    }
    function hasCls(el, c) { return !!el && (' ' + el.className + ' ').indexOf(' ' + c + ' ') >= 0; }

    function msgById(id) { return (w.OwChat.msgCache || {})[id] || null; }
    /** 可被选择 / 转发的消息：有正文、没撤回没删除、非系统消息 */
    function msgUsable(m) {
        return !!(m && m.content && !m.recalled && !m.deleted && m.type !== 'system');
    }
    /** 复制到剪贴板（与核心 copyMsg 同一实现：重排都发生在离屏 textarea 上） */
    function copyText(s) {
        var ta = d.createElement('textarea'), ok = false;
        ta.value = String(s == null ? '' : s);
        ta.style.cssText = 'position:fixed;left:-9999px;top:0';
        d.body.appendChild(ta);
        ta.select();
        try { ok = d.execCommand('copy'); } catch (e) {}
        d.body.removeChild(ta);
        return ok;
    }

    /** 操作条里的一个圆形图标按钮（图标 + 文字标签，仿参考样式） */
    function actBtn(id, iconHtml, label) {
        return '<button type="button" class="owb-actbtn" id="' + id + '">'
            + '<span class="owb-acti">' + iconHtml + '</span>'
            + '<span class="owb-actl">' + esc(label) + '</span></button>';
    }
    /** 串行执行器：fn(item, next, idx)；串行是为了不挤爆同一会话的并发请求（一次性票据/会话锁） */
    function seqEach(list, fn, finish) {
        var i = 0;
        function next() {
            if (i >= list.length) { if (finish) finish(); return; }
            fn(list[i], next, i);
            i++;
        }
        next();
    }
    /** 触发一次下载（跨域时 download 属性无效 → 退化成新标签页打开，不劫走当前页） */
    function downloadLink(url, name) {
        var a = d.createElement('a');
        a.href = url;
        if (name) a.download = name;
        a.target = '_blank';
        a.rel = 'noopener';
        a.style.display = 'none';
        d.body.appendChild(a);
        try { a.click(); } catch (e) {}
        d.body.removeChild(a);
    }
    function nameFromUrl(u) {
        var s = String(u || '').split('?')[0].split('/');
        return s[s.length - 1] || 'image';
    }
    function parseRich(m) {
        try {
            var j = JSON.parse(m.content);
            return { text: String(j.text || ''), media: (j.media && j.media.length) ? j.media : [] };
        } catch (e) { return { text: String(m.content || ''), media: [] }; }
    }
    /** 「聊天记录」卡片消息的正文标记（极短：正文进 2000 字过滤与长度限制，快照本体在服务端） */
    var REC_RE = /^\[聊天记录\]#([a-f0-9]{32})#$/;
    function recTokenOf(s) {
        var m = REC_RE.exec(String(s == null ? '' : s));
        return m ? m[1] : '';
    }
    /** 取消息的「可读文本」（与核心 copyMsg / quoteMsg 同一口径） */
    function plainOf(m) {
        if (m.type === 'file') {
            try { return '[文件] ' + (JSON.parse(m.content).name || ''); } catch (e) { return '[文件]'; }
        }
        if (m.type === 'rich') {
            var p = parseRich(m);
            return p.text !== '' ? (p.text + (p.media.length ? ' [图片]' : '')) : '[图片]';
        }
        if (m.type === 'image') return String(m.content || '');   // 复制图片消息 = 复制图片地址
        if (recTokenOf(m.content)) return '[聊天记录]';           // 卡片消息按占位处理，不吐 token
        return String(m.content || '');
    }
    /** 摘要/复制的一行：换行压平、单行封顶 150 字；图片在摘要里是「[图片]」——与核心引用条同一口径 */
    function lineOf(m, forCopy) {
        var s = m.type === 'image' ? '[图片]' : plainOf(m);
        s = s.replace(/[\r\n]+/g, ' ').replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
        if (s.length > 150) s = s.slice(0, 150) + '…';
        return forCopy ? s : ((m.nickname || '') + '：' + s);
    }
    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    function fmtDay(ts) { var t = new Date((ts || 0) * 1000); return pad2(t.getMonth() + 1) + '-' + pad2(t.getDate()); }
    function fmtTime(ts) { var t = new Date((ts || 0) * 1000); return pad2(t.getHours()) + ':' + pad2(t.getMinutes()); }

    /* ==========================================================================
       聊天记录卡片（合并转发的落地形态，仿微信）
       --------------------------------------------------------------------------
       卡片数据（标题 / 条数 / 前 4 行预览）来自 record_get 路由，按 token 缓存到本页会话；
       渲染时机跟着消息 DOM 走（MutationObserver + 首屏补扫，与勾选框同一思路）。
       点击卡片 → 弹窗看完整记录：条目复用核心 OwChat.buildMessage 渲染，
       图片/文件/混排/引用都用全站同一套消息样式，不另造轮子。
       ========================================================================== */
    var recCache = {};    // token -> {title,count,preview} | null（不存在/过期）
    var recPending = {};  // token -> 在途标记（防重复入队）
    var recQueue = [];
    var recTimer = null;

    function recHtml(tok) {
        var info = recCache[tok];
        if (info === undefined) return '<div class="owb-rec-t">聊天记录</div><div class="owb-rec-s">加载中…</div>';
        if (info === null) return '<div class="owb-rec-t">聊天记录</div><div class="owb-rec-s">聊天记录已过期或不存在</div>';
        var h = '<div class="owb-rec-t">' + esc(info.title || '聊天记录') + '</div>';
        for (var i = 0; i < info.preview.length; i++) {
            h += '<div class="owb-rec-l"><span class="owb-rec-n">' + esc(info.preview[i].nick) + '</span> '
                + esc(info.preview[i].text) + '</div>';
        }
        h += '<div class="owb-rec-f">查看 ' + esc(info.count) + ' 条转发消息</div>';
        return h;
    }
    /** 把一条「[聊天记录]#token#」消息的正文节点换成卡片（幂等） */
    function mountCard(msgNode) {
        if (!msgNode || msgNode.getElementsByClassName('owb-rec').length) return;
        var cs = msgNode.querySelector('.ow-msg-content');
        if (!cs) return;
        var tok = recTokenOf(cs.textContent);
        if (!tok) return;
        var body = cs.parentNode;
        var card = d.createElement('div');
        card.className = 'owb-rec';
        card.setAttribute('data-token', tok);
        card.innerHTML = recHtml(tok);
        body.replaceChild(card, cs);
        if (recCache[tok] === undefined) enqueueRec(tok);
    }
    function scanCards(root) {
        var nodes = (root || d).querySelectorAll ? (root || d).querySelectorAll('.ow-msg') : [];
        for (var i = 0; i < nodes.length; i++) mountCard(nodes[i]);
    }
    function enqueueRec(tok) {
        if (recCache[tok] !== undefined || recPending[tok]) return;
        recPending[tok] = true;
        recQueue.push(tok);
        if (recTimer) return;
        recTimer = setTimeout(function () { recTimer = null; flushRec(); }, 250);   // 合并同一批渲染出的多张卡，少发几次请求
    }
    function flushRec() {
        if (!recQueue.length) return;
        var batch = recQueue.splice(0, 20);
        w.OwApi.post('plugin_batch_ops_record_get', { tokens: batch.join(',') }, function (r) {
            var data = (r && r.ok && r.data) || {};
            for (var i = 0; i < batch.length; i++) {
                var t = batch[i];
                delete recPending[t];
                recCache[t] = data[t] || null;
                updateCards(t);
            }
            if (recQueue.length) { recTimer = setTimeout(function () { recTimer = null; flushRec(); }, 200); }
        });
    }
    function updateCards(tok) {
        var cards = d.querySelectorAll('.owb-rec[data-token="' + tok + '"]');
        for (var i = 0; i < cards.length; i++) cards[i].innerHTML = recHtml(tok);
    }
    /** 点开某张卡片：弹窗看完整记录（全宽消息流，条目复用核心消息渲染） */
    function openRecord(tok) {
        w.OwChat.openModal(
            '<h3>聊天记录</h3>'
            + '<div class="owb-rec-view" id="owbRecView"><div class="ow-sr-empty">加载中…</div></div>'
        , 520);
        var view = byId('owbRecView');
        if (view) {
            // 流内再嵌记录（mini 卡）→ 直接在本弹窗里打开它
            view.onclick = function (e) {
                var t = e.target || e.srcElement, node = t;
                while (node && node !== view && !(node.className && String(node.className).indexOf('owb-rec-mini') >= 0)) node = node.parentNode;
                if (node && node !== view) openRecord(node.getAttribute('data-token'));
            };
        }
        w.OwApi.post('plugin_batch_ops_record_get', { tokens: tok, full: '1' }, function (r) {
            var box = byId('owbRecView');
            if (!box) return;   // 用户已经关掉弹窗
            var info = (r && r.ok && r.data) ? r.data[tok] : null;
            if (!info) { box.innerHTML = '<div class="ow-sr-empty">聊天记录已过期或不存在</div>'; return; }
            var h3 = d.querySelector('#owModal h3');
            if (h3) h3.textContent = info.title || '聊天记录';
            recCache[tok] = { title: info.title || '聊天记录', count: info.count, preview: info.preview || [] };
            updateCards(tok);
            var items = info.items || [], html = '';
            if (items.length) {
                html += '<div class="ow-time-divider"><span>' + esc(fmtDay(items[0].ts) + ' ' + fmtTime(items[0].ts)) + '</span></div>';
            }
            for (var i = 0; i < items.length; i++) html += recMsgHtml(items[i]);
            box.innerHTML = html || '<div class="ow-sr-empty">没有内容</div>';
        });
    }
    /** 记录里的一条消息 → 全站同款消息 DOM（核心 buildMessage；引用不带跳转、一律按对方消息呈现） */
    function recMsgHtml(it) {
        var m = {
            id: it.id || 0, type: it.type || 'text', content: it.content || '',
            nickname: it.nickname || '', role: it.role || 'member', title: it.title || '',
            avatar: it.avatar || '', uid: it.uid || 0,
            mine: false, dm: false, recalled: 0, deleted: 0,
            quote: null,
            date: fmtDay(it.ts), time: fmtTime(it.ts), ts: it.ts
        };
        if (it.quote) { try { m.quote = JSON.parse(it.quote); } catch (e) { m.quote = null; } }
        var b = w.OwChat.buildMessage(m);
        var wrap = d.createElement('div');
        wrap.className = b.cls;
        wrap.innerHTML = b.html;
        var tok2 = recTokenOf(it.content);   // 嵌套的记录卡片 → 小卡（弹窗里可再点开）
        if (tok2) {
            var cs = wrap.querySelector('.ow-msg-content');
            if (cs) cs.innerHTML = '<span class="owb-rec-mini" data-token="' + tok2 + '">[聊天记录] 点击查看</span>';
        }
        return wrap.outerHTML;
    }

    /* ==========================================================================
       OwBatch：消息多选模式
       ========================================================================== */
    var OwBatch = {
        mode: false,
        sel: {},        // msgId -> true
        selCount: 0,
        bar: null,
        _busy: false,   // 批量动作进行中（删除 / 收藏的串行队列）
        _mo: null,
        _timer: null,
        _lastView: '',

        /** 进入选择模式；seed 为发起时右键的那条消息（可省，右侧栏入口不传） */
        enter: function (seed) {
            if (this.mode) {                       // 已在模式中：再点「多选」= 切换该条
                if (seed && msgUsable(seed)) this.toggle(seed.id);
                return;
            }
            var box = byId('owMessages');
            if (!box) return;
            this.mode = true;
            this.sel = {};
            this.selCount = 0;
            addCls(box, 'owb-mode');
            this.mountBar();
            var nodes = box.getElementsByClassName('ow-msg'), i;
            for (i = 0; i < nodes.length; i++) this.decorate(nodes[i]);
            this.watch();
            if (seed && msgUsable(seed)) this.toggle(seed.id);
            this.updateBar();
            toast('已进入多选：点击消息勾选，选好后用下方操作条', 3000);
        },

        /** 退出选择模式（静默与否只影响提示；可选地给条 toast 说明） */
        exit: function () {
            if (!this.mode) return;
            this.mode = false;
            this.sel = {};
            this.selCount = 0;
            if (this._mo) { try { this._mo.disconnect(); } catch (e) {} this._mo = null; }
            if (this._timer) { clearInterval(this._timer); this._timer = null; }
            var box = byId('owMessages');
            if (box) {
                delCls(box, 'owb-mode');
                var chks = box.getElementsByClassName('owb-chk'), i;
                for (i = chks.length - 1; i >= 0; i--) if (chks[i].parentNode) chks[i].parentNode.removeChild(chks[i]);
                var ons = box.getElementsByClassName('owb-on');
                for (i = ons.length - 1; i >= 0; i--) delCls(ons[i], 'owb-on');
            }
            if (this.bar && this.bar.parentNode) this.bar.parentNode.removeChild(this.bar);
            this.bar = null;
        },

        /** 挂底部操作条（作为消息区最后一个子节点，sticky 自动贴可视区底部） */
        mountBar: function () {
            var box = byId('owMessages');
            if (!box) return;
            var bar = d.createElement('div');
            bar.className = 'owb-bar';
            bar.id = 'owbBar';
            bar.innerHTML = '<span class="owb-bar-n" id="owbCount"></span>'
                + '<div class="owb-acts">'
                + actBtn('owbFwd', w.OwChat.icon('send', 16, 'owb-acti'), '逐条转发')
                + actBtn('owbMerge', MERGE_SVG, '合并转发')
                + actBtn('owbSave', SAVE_SVG, '保存至电脑')
                + actBtn('owbFav', w.OwChat.icon('star', 16, 'owb-acti'), '收藏')
                + actBtn('owbDel', w.OwChat.icon('trash', 16, 'owb-acti'), '删除')
                + actBtn('owbCopy', w.OwChat.icon('copy', 16, 'owb-acti'), '复制')
                + '</div>'
                + '<button type="button" class="owb-x" id="owbCancel" title="退出多选" aria-label="退出多选">✕</button>';
            box.appendChild(bar);
            this.bar = bar;
            byId('owbFwd').onclick = function () { OwBatch.forwardOpen(); };
            byId('owbMerge').onclick = function () { OwBatch.mergeOpen(); };
            byId('owbSave').onclick = function () { OwBatch.saveToDisk(); };
            byId('owbFav').onclick = function () { OwBatch.collectStickers(); };
            byId('owbDel').onclick = function () { OwBatch.batchDelete(); };
            byId('owbCopy').onclick = function () { OwBatch.copyBatch(); };
            byId('owbCancel').onclick = function () { OwBatch.exit(); };
            this.updateBar();
        },

        /** 当前选中且仍有效的消息（按消息先后序） */
        selectedMsgs: function () {
            var ids = this.ids(), out = [], i, m;
            for (i = 0; i < ids.length; i++) {
                m = msgById(ids[i]);
                if (msgUsable(m)) out.push(m);
            }
            return out;
        },

        /**
         * 刷新操作条：计数 + 各按钮可用性。
         * 「保存至电脑」只在选中有图片/文件时可用、「收藏」只在选中有图片且已登录时可用
         * —— 与参考样式里「无可保存内容时置灰」的取向一致，不让用户点了才发现没东西。
         */
        updateBar: function () {
            var msgs = this.selectedMsgs(), n = this.selCount;
            var cnt = byId('owbCount');
            if (cnt) cnt.innerHTML = owTr('已选 {n} 条', { n: n });
            var has = n > 0, hasImg = false, hasSave = false, i;
            for (i = 0; i < msgs.length; i++) {
                if (msgs[i].type === 'image') { hasImg = true; hasSave = true; }
                else if (msgs[i].type === 'file') hasSave = true;
            }
            var b;
            b = byId('owbFwd'); if (b) b.disabled = !has || this._busy;
            b = byId('owbMerge'); if (b) b.disabled = !has || this._busy;
            b = byId('owbSave'); if (b) b.disabled = (!hasSave) || this._busy;
            b = byId('owbFav'); if (b) b.disabled = (!hasImg || !isUser()) || this._busy;
            b = byId('owbDel'); if (b) b.disabled = !has || this._busy;
            b = byId('owbCopy'); if (b) b.disabled = !has || this._busy;
        },

        /* ============ 操作条 · 删除 / 复制 / 保存 / 收藏 ============ */
        /** 批量删除 = 逐条核心 msg_delete（本机隐藏，敏感操作一次性票据）；串行防会话锁打挤 */
        batchDelete: function () {
            var self = this;
            if (this._busy) return;
            var msgs = this.selectedMsgs();
            if (!msgs.length) { toast('请先选择要删除的消息'); return; }
            w.OwChat.confirm('确定删除选中的 ' + msgs.length + ' 条消息吗？删除后仅本机不再看到，其他人不受影响。', function () {
                self._busy = true; self.updateBar();
                var done = 0, fail = 0, total = msgs.length;
                seqEach(msgs, function (m, next, idx) {
                    toast('正在删除 ' + (idx + 1) + '/' + total + '…', 1200);
                    w.OwApi.secure('msg_delete', { id: m.id }, function (r) {
                        if (r && r.ok) {
                            done++;
                            /* 先摘选择再清缓存：toggle 内部要查 msgCache 判断有效性，
                               反过来写会因「缓存已删 → 判为不可选」而摘不掉，计数挂在旧值上。 */
                            if (self.sel[m.id]) self.toggle(m.id);
                            var el = byId('owMsg' + m.id);
                            if (el && el.parentNode) el.parentNode.removeChild(el);
                            delete w.OwChat.msgCache[m.id];
                        } else fail++;
                        next();
                    });
                }, function () {
                    self._busy = false; self.updateBar();
                    toast('已删除 ' + done + ' 条（仅本机不再看到）' + (fail ? '，' + fail + ' 条失败' : ''), 3000);
                });
            });
        },

        /** 批量复制：文字/文件/混排取可读文本、图片取「[图片]」占位，多行拼接 */
        copyBatch: function () {
            var msgs = this.selectedMsgs();
            if (!msgs.length) { toast('请先选择要复制的消息'); return; }
            var lines = [], i;
            for (i = 0; i < msgs.length; i++) lines.push(lineOf(msgs[i], true));
            toast(copyText(lines.join('\n')) ? ('已复制 ' + msgs.length + ' 条消息') : '复制失败，请手动选择文本');
        },

        /** 保存至电脑：图片直接下（同源 download），文件走核心 file_download 签名链接 */
        saveToDisk: function () {
            var msgs = this.selectedMsgs(), i, m, imgs = [], files = [];
            for (i = 0; i < msgs.length; i++) {
                m = msgs[i];
                if (m.type === 'image' && m.content) imgs.push(m);
                else if (m.type === 'file') files.push(m);
            }
            if (!imgs.length && !files.length) { toast('所选消息里没有可保存的图片或文件'); return; }
            for (i = 0; i < imgs.length; i++) downloadLink(imgs[i].content, nameFromUrl(imgs[i].content));
            for (i = 0; i < files.length; i++) {
                var nm = '文件';
                try { nm = JSON.parse(files[i].content).name || nm; } catch (e) {}
                var s = w.OwApi.sign('file_download');
                downloadLink('?action=file_download&id=' + files[i].id + '&ts=' + s.ts + '&sign=' + s.sign, nm);
            }
            toast('已开始保存 ' + (imgs.length + files.length) + ' 个文件（浏览器可能询问是否允许多个下载）', 3600);
        },

        /** 收藏 = 核心贴纸收藏（sticker_add）；串行执行，完成给聚合结果 */
        collectStickers: function () {
            var self = this;
            if (this._busy) return;
            if (!isUser()) { toast('请先登录后再收藏'); return; }
            var msgs = this.selectedMsgs(), list = [], i;
            for (i = 0; i < msgs.length; i++) {
                if (msgs[i].type === 'image' && msgs[i].content) list.push(msgs[i].content);
            }
            if (!list.length) { toast('所选消息里没有图片'); return; }
            this._busy = true; this.updateBar();
            var done = 0, fail = 0, total = list.length;
            seqEach(list, function (url, next, idx) {
                toast('正在收藏 ' + (idx + 1) + '/' + total + '…', 1200);
                w.OwApi.post('sticker_add', { url: url }, function (r) {
                    if (r && r.ok) done++; else fail++;
                    next();
                });
            }, function () {
                self._busy = false; self.updateBar();
                toast('收藏完成：成功 ' + done + ' 条' + (fail ? '，失败 ' + fail + ' 条' : ''), 3000);
            });
        },

        /** 给一条消息挂勾选框；已挂或不可选（系统/已撤回）则跳过 */
        decorate: function (node) {
            if (!this.mode || !node || !node.id || node.id.indexOf('owMsg') !== 0) return;
            if (node.getElementsByClassName('owb-chk').length) return;
            var id = parseInt(node.id.replace('owMsg', ''), 10);
            if (!msgUsable(msgById(id))) return;
            var chk = d.createElement('span');
            chk.className = 'owb-chk';
            node.insertBefore(chk, node.firstChild);
            if (this.sel[id]) addCls(node, 'owb-on');
        },

        /**
         * 监听消息区子节点变化：勾选框跟着新消息走。
         * 覆盖全部渲染路径（首屏/翻页/轮询/WS 推送/待发气泡转正/机器人标签重绘），
         * 比逐个包装 addMessage/loadMore 稳妥 —— 核心重绘消息的入口不止一处。
         * 顺带把操作条重新挪回队尾（新消息是 appendChild，会插到它后面）。
         */
        watch: function () {
            var self = this, box = byId('owMessages');
            if (!box) return;
            var fixBar = function () {
                if (self.mode && self.bar && box.lastChild !== self.bar) box.appendChild(self.bar);
            };
            if (w.MutationObserver) {
                this._mo = new w.MutationObserver(function (recs) {
                    if (!self.mode) return;
                    for (var i = 0; i < recs.length; i++) {
                        var added = recs[i].addedNodes || [];
                        for (var j = 0; j < added.length; j++) {
                            var n = added[j];
                            if (n && n.nodeType === 1) self.decorate(n);
                        }
                    }
                    fixBar();
                });
                this._mo.observe(box, { childList: true });
            } else {
                /* 老内核（无 MutationObserver）：低频补扫，成本可忽略且只在选择模式期间跑 */
                this._timer = setInterval(function () {
                    if (!self.mode) return;
                    var ns = box.getElementsByClassName('ow-msg'), i;
                    for (i = 0; i < ns.length; i++) self.decorate(ns[i]);
                    fixBar();
                }, 800);
            }
        },

        toggle: function (id) {
            if (!this.mode) return;
            var m = msgById(id);
            if (!msgUsable(m)) return;
            var node = byId('owMsg' + id);
            if (this.sel[id]) {
                delete this.sel[id];
                this.selCount--;
                if (node) delCls(node, 'owb-on');
            } else {
                if (this.selCount >= MAX_SEL) {
                    toast(owTr('单次最多选择 {n} 条消息', { n: MAX_SEL }));
                    return;
                }
                this.sel[id] = true;
                this.selCount++;
                if (node) addCls(node, 'owb-on');
            }
            this.updateBar();
        },

        /** 当前选中消息 id（按消息先后序，转发出去的顺序与阅读顺序一致） */
        ids: function () {
            var out = [], k;
            for (k in this.sel) if (this.sel.hasOwnProperty(k)) out.push(parseInt(k, 10));
            out.sort(function (a, b) { return a - b; });
            return out;
        },

        /* ============ 转发 ============ */
        forwardOpen: function () {
            if (!this.selCount) { toast('请先选择要转发的消息'); return; }
            var n = this.selCount;
            this.picker({
                mode: 'forward',
                title: '转发消息',
                desc: '已选择 ' + n + ' 条消息，点击好友或群聊即可转发',
                verb: '转发',
                searchHint: '搜索好友或群聊',
                defaultTab: 'recent',
                onPick: function (target, ctl) { doForward(target, ctl); }
            });
        },

        /**
         * 单条转发（消息右键「转发」入口）：跳过选择模式，直接把这条消息送进选择器。
         * 与批量转发的区别只在「转发完退出选择模式」——单条是独立快捷操作，
         * 用户可能正处在多选流程里（那时不清掉他的已选集合）。
         */
        forwardMsg: function (m) {
            if (!msgUsable(m)) { toast('这条消息不支持转发'); return; }
            this.picker({
                mode: 'forward',
                title: '转发消息',
                desc: '点击好友或群聊即可转发这条消息',
                verb: '转发',
                searchHint: '搜索好友或群聊',
                defaultTab: 'recent',
                onPick: function (target, ctl) { doForward(target, ctl, [m]); }
            });
        },

        /** 合并转发：N 条合成一条（文字摘要 + 图片附件），走同一个目标选择器 */
        mergeOpen: function () {
            if (this._busy) return;
            if (!this.selCount) { toast('请先选择要转发的消息'); return; }
            var n = this.selCount;
            this.picker({
                mode: 'forward',
                title: '合并转发',
                desc: '已选择 ' + n + ' 条消息，合并为一条发送',
                verb: '发送',
                searchHint: '搜索好友或群聊',
                defaultTab: 'recent',
                onPick: function (target, ctl) { doMerge(target, ctl); }
            });
        },

        /* ============ 邀请好友进群 ============ */
        inviteOpen: function (roomId) {
            if (!isUser()) { toast('请先登录后再邀请成员'); return; }
            var room = roomOf(roomId);
            if (room && !room.can_invite) { toast('只有本群成员可以邀请'); return; }
            var name = (room && room.name) || '';
            this.picker({
                mode: 'invite',
                roomId: roomId,
                title: name ? ('邀请朋友加入 ' + name) : '邀请朋友加入群聊',
                desc: '接收者将直接进入 ' + (name || '本群聊') + '，成为本群成员',
                verb: '邀请',
                searchHint: '搜索好友',
                defaultTab: 'friends',
                onPick: function (target, ctl) { doInvite(roomId, target, ctl); }
            });
        }
    };

    /** 从进页快照里找房间（can_invite / name 用） */
    function roomOf(roomId) {
        var list = (w.OwChat.cfg && w.OwChat.cfg.rooms) || [], i;
        for (i = 0; i < list.length; i++) if (list[i].id === roomId) return list[i];
        return null;
    }

    /* ==========================================================================
       通用选择器（仿参考样式）：标题 + 说明 + 搜索框 + 分栏列表 + 行内动作 + 底部插槽
       --------------------------------------------------------------------------
       opt = {
         mode: 'forward' | 'invite',   // 决定数据源、行状态与底部插槽
         roomId,                        // invite 模式必传
         title, desc,                   // 弹窗标题 / 说明行
         verb,                          // 行内动作按钮文字（转发 / 邀请）
         searchHint, defaultTab,        // 搜索框占位 / 默认分栏
         onPick(target, ctl)            // 选中目标；ctl = { busy(text), row(label), fail(), close() }
                                          //  target = { kind:'user'|'room', id, name, k }
       }
       ========================================================================== */
    function openPicker(opt) {
        var st = {
            loading: opt.mode === 'invite' ? 4 : 3,
            tab: opt.defaultTab || 'recent',
            q: '',
            conv: [], friends: [], rooms: [],
            roomMap: {},
            memberSet: {},
            code: '', isPublic: true,
            done: {},          // data-k -> 完成文案（已邀请）
            busy: false,
            rows: [],          // 当前视图的完整行集合（HTML 字符串数组，分批插入用）
            rendered: 0        // 已插入列表的条数
        };
        var defs = tabsFor(opt);
        var actorKind = actor().kind, admin = isAdmin();
        var closed = false;

        /* ---------- 弹窗外壳 ---------- */
        var tabsHtml = '', i;
        for (i = 0; i < defs.length; i++) {
            tabsHtml += '<button type="button" class="ow-tab' + (defs[i].id === st.tab ? ' is-active' : '')
                + '" data-tab="' + defs[i].id + '">' + esc(defs[i].label) + '</button>';
        }
        w.OwChat.openModal(
            '<h3>' + esc(opt.title) + '</h3>'
            + '<p class="ow-modal-desc">' + esc(opt.desc) + '</p>'
            + '<div class="owb-search">' + SEARCH_SVG
            + '<input class="ow-input" id="owbQ" maxlength="50" autocomplete="off" placeholder="' + esc(opt.searchHint) + '"></div>'
            + '<div class="ow-tabs owb-tabs" id="owbTabs">' + tabsHtml + '</div>'
            + '<div class="ow-sr-list" id="owbList"><div class="ow-sr-empty">加载中…</div></div>'
            + '<div id="owbFoot"></div>'
        , 480);

        /* ---------- 数据拉取（四个核心 action；搜索是前端过滤，这里不碰 search 接口） ---------- */
        w.OwApi.post('conversations', {}, function (r) {
            st.conv = (r && r.ok && r.data) || [];
            fetched();
        });
        w.OwApi.post('friends', {}, function (r) {
            st.friends = (r && r.ok && r.data) || [];
            fetched();
        });
        w.OwApi.post('rooms', {}, function (r) {
            st.rooms = (r && r.ok && r.data) || [];
            for (var j = 0; j < st.rooms.length; j++) st.roomMap[st.rooms[j].id] = st.rooms[j];
            fetched();
        });
        if (opt.mode === 'invite') {
            w.OwApi.post('room_members', { room_id: opt.roomId }, function (r) {
                if (!r || !r.ok) {
                    toast((r && r.msg) || '无法读取群成员');
                    closePicker();
                    return;
                }
                if (!r.can_invite) { toast('只有本群成员可以邀请'); closePicker(); return; }
                var list = r.data || [], k;
                for (k = 0; k < list.length; k++) st.memberSet[list[k].user_id] = true;
                if (r.owner_id) st.memberSet[r.owner_id] = true;   // 群主不写 room_members 行
                st.code = r.invite_code || '';
                st.isPublic = !!r.is_public;
                fetched();
            });
        }
        function fetched() {
            if (closed) return;
            st.loading--;
            if (st.loading <= 0) { render(true); renderFoot(); }
        }
        function closePicker() {
            closed = true;
            w.OwChat.closeModal();
        }

        /* ---------- 交互绑定 ---------- */
        var q = byId('owbQ');
        if (q) {
            var tmr = null;
            q.oninput = function () {
                if (tmr) clearTimeout(tmr);
                tmr = setTimeout(function () { st.q = trimQ(q.value); render(true); }, 200);
            };
            q.onkeydown = function (e) {
                e = e || w.event;
                if (e.keyCode === 13) {
                    if (tmr) clearTimeout(tmr);
                    st.q = trimQ(q.value);
                    render(true);
                }
            };
            setTimeout(function () { try { q.focus(); } catch (e) {} }, 30);
        }
        var bar = byId('owbTabs');
        if (bar) {
            bar.onclick = function (e) {
                var t = e.target || e.srcElement;
                while (t && t !== bar && !(t.getAttribute && t.getAttribute('data-tab'))) t = t.parentNode;
                if (!t || t === bar) return;
                var tab = t.getAttribute('data-tab');
                if (!tab || (tab === st.tab && !st.q)) {
                    if (tab) { st.tab = tab; st.q = ''; if (q) q.value = ''; }
                    return;
                }
                st.tab = tab;
                st.q = '';
                if (q) q.value = '';                       // 切分栏即回到该栏列表（与参考样式一致：分栏是分类，搜索是临时视图）
                var bs = bar.getElementsByClassName('ow-tab'), k;
                for (k = 0; k < bs.length; k++) {
                    if (bs[k].getAttribute('data-tab') === tab) addCls(bs[k], 'is-active');
                    else delCls(bs[k], 'is-active');
                }
                render(true);
            };
        }
        var list = byId('owbList');
        if (list) {
            list.onclick = function (e) {
                if (st.busy) return;
                var t = e.target || e.srcElement;
                while (t && t !== list && !(t.getAttribute && t.getAttribute('data-k'))) t = t.parentNode;
                if (!t || t === list) return;
                if (hasCls(t, 'owb-dis') || hasCls(t, 'owb-done') || st.done[t.getAttribute('data-k')]) return;
                var k = t.getAttribute('data-k') || '';
                var id = parseInt(k.slice(2), 10);
                if (!id) return;
                pick({
                    k: k,
                    kind: k.charAt(0) === 'u' ? 'user' : 'room',
                    id: id,
                    name: t.getAttribute('data-n') || ''
                });
            };
        }

        /* ---------- 渲染 ---------- */
        function tabsFor(o) {
            if (o.mode === 'invite') {
                return [{ id: 'recent', label: '最近' }, { id: 'friends', label: '好友' }];
            }
            return [{ id: 'recent', label: '最近' }, { id: 'friends', label: '好友' }, { id: 'rooms', label: '群聊' }];
        }
        function trimQ(s) {
            return String(s == null ? '' : s).replace(/^\s+|\s+$/g, '').slice(0, 50);
        }
        function matchName(name, id, qq) {
            var lq = qq.toLowerCase();
            name = String(name == null ? '' : name).toLowerCase();
            if (name.indexOf(lq) >= 0) return true;
            if (/^\d+$/.test(qq)) {
                var n = parseInt(qq, 10);
                return id === n || String(fmtUid(id)).indexOf(qq) === 0;   // 输入 001 也能命中 ID 1
            }
            return false;
        }
        function avatarCell(url, name) {
            var src = url || (w.OwAvatar ? w.OwAvatar.identicon(name || 'owl') : '');
            return '<span class="ow-avatar ow-avatar-md"><img src="' + esc(src) + '" alt="" loading="lazy" decoding="async"'
                + ' onerror="OwAvatar.fallback(this)"></span>';
        }
        /** 统一行样式：复用核心搜索结果行（.ow-sr-item 一套），行尾挂动作按钮 */
        function rowHtml(k, name, avatar, sub, tag, dis, disTip, doneLabel) {
            var btnTxt = doneLabel || opt.verb;
            var off = !!(doneLabel || dis);
            return '<div class="ow-sr-item owb-row' + (dis ? ' owb-dis' : '') + (doneLabel ? ' owb-done' : '') + '"'
                + ' data-k="' + esc(k) + '" data-n="' + esc(name) + '"'
                + (dis && disTip ? ' title="' + esc(disTip) + '"' : '') + '>'
                + avatarCell(avatar, name)
                + '<span class="ow-sr-main"><span class="ow-sr-title">' + esc(name)
                + (tag ? '<span class="ow-sr-tag">' + esc(tag) + '</span>' : '')
                + '</span><span class="ow-sr-sub">' + esc(sub == null ? '' : sub) + '</span></span>'
                + '<button type="button" class="ow-btn ow-btn-mini owb-act' + (off ? '' : ' ow-btn-ghost') + '"'
                + (off ? ' disabled' : '') + '>' + esc(btnTxt) + '</button></div>';
        }
        /** 转发目标可用性：私聊须好友；群聊须成员（超管恒可；游客交给服务端把关） */
        function userTarget(u) {
            var dis = false, tip = '';
            if (opt.mode === 'forward' && u.is_friend === false) {
                dis = true; tip = '对方已不是你的好友，无法私信';
            }
            var doneLabel = st.done[u.k];
            return rowHtml(u.k, u.name, u.avatar, u.sub, dis && opt.mode === 'forward' ? '非好友' : '',
                dis && !doneLabel, tip, doneLabel);
        }
        function roomTarget(r) {
            var m = st.roomMap[r.id] || {};
            var member = !!m.is_member || admin;
            var dis = false, tip = '';
            if (opt.mode === 'forward' && actorKind !== 'guest' && !member) {
                dis = true; tip = '你还不是该群成员，加入后才能发言';
            }
            var tag = dis ? '未加入' : '';
            var doneLabel = st.done[r.k];
            return rowHtml(r.k, r.name, r.avatar, r.sub, tag, dis && !doneLabel, tip, doneLabel);
        }
        /** 邀请模式的人行：已在群内的按钮转「已在群内」并禁用（不再重复挂角标） */
        function inviteUser(u) {
            var inRoom = !!st.memberSet[u.id];
            var doneLabel = st.done[u.k] || (inRoom ? '已在群内' : '');
            return rowHtml(u.k, u.name, u.avatar, u.sub, '',
                inRoom && !st.done[u.k], '', doneLabel);
        }
        /** 行数据归一：把人 / 群包成统一结构，再按模式选渲染器 */
        function userRow(u) {
            var norm = { k: 'u:' + u.id, id: u.id, name: u.name, avatar: u.avatar, sub: u.sub, is_friend: u.is_friend };
            return opt.mode === 'invite' ? inviteUser(norm) : userTarget(norm);
        }
        function roomRow(r) {
            return roomTarget({ k: 'r:' + r.id, id: r.id, name: r.name, avatar: r.avatar, sub: r.sub });
        }

        /* ---------- 列表渲染：与核心搜索结果同一套分批手法 ----------
           好友/群聊/会话都是**完整列表**（服务端不分页），一次全量 innerHTML
           在条目多时既吃性能、长列表也容易把弹窗版面撑乱 —— 所以照抄核心搜索：
           首批 20 条、滚动到底续批、内容不足一屏自动补；.ow-sr-item 自带
           content-visibility，屏外条目跳过布局与绘制。 */

        /** 重建当前视图的行集合（HTML 字符串数组；搜索态含分节标题项） */
        function buildRows() {
            if (st.q) return searchRows(st.q);
            if (st.tab === 'recent') return recentRows();
            if (st.tab === 'friends') return friendsRows();
            return roomsRows();
        }
        function emptyText() {
            if (st.q) return '没有找到相关内容';
            if (st.tab === 'recent') return opt.mode === 'invite' ? '暂无最近联系人' : '暂无最近聊天';
            if (st.tab === 'friends') return isUser() ? '暂无好友' : '登录后才能使用好友功能';
            return '暂无可转发的群聊';
        }
        function render(resetScroll) {
            var box = byId('owbList');
            if (!box || closed || st.loading > 0) return;
            var prevRendered = st.rendered, prevTop = box.scrollTop;
            st.rows = buildRows();
            st.rendered = 0;
            box.innerHTML = '';
            if (!st.rows.length) {
                box.innerHTML = '<div class="ow-sr-empty">' + emptyText() + '</div>';
                return;
            }
            /* 滚动到底续批。⚠️ 用 onclick 式的**赋值**而不是 addEventListener ——
               每次渲染都会重绑，赋值天然不叠加（核心搜索踩过叠加监听的坑）。 */
            box.onscroll = function () {
                if (box.scrollTop + box.clientHeight >= box.scrollHeight - 48) appendMore(BATCH);
            };
            if (resetScroll) {
                appendMore(BATCH);
                box.scrollTop = 0;
            } else {
                /* 非重置的重渲染（如邀请成功后刷新行状态）要保住阅读位置：
                   把上次已渲染的条数原样补回，否则会把滚到中段的人拽回顶部。 */
                appendMore(Math.max(BATCH, Math.min(prevRendered, st.rows.length)));
                box.scrollTop = prevTop;
            }
        }
        function appendMore(count) {
            var box = byId('owbList');
            if (!box || closed) return;
            var to = Math.min(st.rendered + count, st.rows.length);
            if (to <= st.rendered) return;
            box.insertAdjacentHTML('beforeend', st.rows.slice(st.rendered, to).join(''));
            st.rendered = to;
            /* 内容不足一屏（结果少或弹窗很高）时继续补，否则用户永远滚不到底、
               后面的批次就永远加载不出来（核心搜索同一处理）。 */
            if (st.rendered < st.rows.length && box.scrollHeight <= box.clientHeight + 8) appendMore(BATCH);
        }

        function recentRows() {
            var out = [], i, c;
            for (i = 0; i < st.conv.length; i++) {
                c = st.conv[i];
                if (opt.mode === 'invite') {
                    if (c.conv !== 'dm') continue;             // 邀请的对象是人，群聊不进这个列表
                    out.push(userRow({ id: c.id, name: c.name, avatar: c.avatar, sub: c.last_text || '暂无消息', is_friend: c.is_friend }));
                } else if (c.conv === 'room') {
                    out.push(roomRow({ id: c.id, name: c.name, avatar: c.avatar, sub: c.last_text || '暂无消息' }));
                } else {
                    out.push(userRow({ id: c.id, name: c.name, avatar: c.avatar, sub: c.last_text || '暂无消息', is_friend: c.is_friend }));
                }
            }
            return out;
        }
        function friendsRows() {
            var out = [], i, f;
            for (i = 0; i < st.friends.length; i++) {
                f = st.friends[i];
                out.push(userRow({ id: f.user_id, name: f.nickname, avatar: f.avatar, sub: '用户 ID ' + fmtUid(f.user_id) }));
            }
            return out;
        }
        function roomsRows() {
            var out = [], i, r;
            for (i = 0; i < st.rooms.length; i++) {
                r = st.rooms[i];
                out.push(roomRow({ id: r.id, name: r.name, avatar: r.avatar, sub: r.description || ('群聊 ID ' + fmtUid(r.id)) }));
            }
            return out;
        }
        /** 搜索：同一个关键词同时过滤好友与群聊（分栏小标题分开列，见需求「搜索同时命中两者」） */
        function searchRows(qq) {
            var fh = [], rh = [], i, f, r, out = [];
            for (i = 0; i < st.friends.length; i++) {
                f = st.friends[i];
                if (!matchName(f.nickname, f.user_id, qq)) continue;
                fh.push(userRow({ id: f.user_id, name: f.nickname, avatar: f.avatar, sub: '用户 ID ' + fmtUid(f.user_id) }));
            }
            if (opt.mode === 'forward') {
                for (i = 0; i < st.rooms.length; i++) {
                    r = st.rooms[i];
                    if (!matchName(r.name, r.id, qq)) continue;
                    rh.push(roomRow({ id: r.id, name: r.name, avatar: r.avatar, sub: r.description || ('群聊 ID ' + fmtUid(r.id)) }));
                }
            }
            if (fh.length) { out.push('<div class="owb-sec">好友</div>'); out = out.concat(fh); }
            if (rh.length) { out.push('<div class="owb-sec">群聊</div>'); out = out.concat(rh); }
            return out;
        }

        /* ---------- 邀请模式底部：群聊邀请链接（对应参考样式的底部区域） ---------- */
        function renderFoot() {
            var foot = byId('owbFoot');
            if (!foot || closed) return;
            if (opt.mode !== 'invite') { foot.innerHTML = ''; return; }
            var base = (w.OwChat.cfg && w.OwChat.cfg.site_url) || '';
            var link, label, hint;
            if (!st.isPublic && st.code) {
                link = base + '/?room_invite=' + st.code;
                label = '或者，向好友发送群聊邀请链接';
                hint = '邀请码可在「群成员」弹窗中重置，重置后旧链接与邀请码立即失效。';
            } else {
                link = base + '/?room=' + opt.roomId;
                label = '或者，向好友发送群聊链接';
                hint = '对方打开链接即可看到并进入本群聊。';
            }
            foot.innerHTML = '<div class="owb-foot">'
                + '<div class="owb-foot-t">' + esc(label) + '</div>'
                + '<div class="owb-foot-row">'
                + '<input class="ow-input owb-link" id="owbLink" readonly value="' + esc(link) + '">'
                + '<button type="button" class="ow-btn ow-btn-primary owb-copy" id="owbCopy">复制</button>'
                + '</div>'
                + '<p class="owb-foot-hint">' + esc(hint) + '</p>'
                + '</div>';
            var cp = byId('owbCopy');
            if (cp) cp.onclick = function () {
                toast(copyText(link) ? '链接已复制' : '复制失败，请手动选择复制');
            };
        }

        /* ---------- 选中目标：交给业务回调（busy / row / fail / close 由它驱动） ---------- */
        function pick(target) {
            if (st.busy || closed) return;
            if (opt.mode === 'invite' && target.kind !== 'user') return;
            st.busy = true;
            opt.onPick(target, {
                busy: function (text) { setActiveRow(target.k, text); },
                row: function (label) { st.busy = false; st.done[target.k] = label || '已完成'; render(false); },
                fail: function () { st.busy = false; render(false); },
                close: function () { closePicker(); }
            });
        }
        /** 行状态操作：把目标行按钮转「进行中」，并锁住其它行（防重复点） */
        function setActiveRow(k, text) {
            var box = byId('owbList');
            if (!box) return;
            var rows = box.getElementsByClassName('owb-row'), i;
            for (i = 0; i < rows.length; i++) {
                var r = rows[i], kk = r.getAttribute('data-k');
                var b = r.getElementsByClassName('owb-act')[0];
                if (kk === k) {
                    if (b) { b.disabled = true; b.textContent = text; }
                } else if (!hasCls(r, 'owb-dis') && !hasCls(r, 'owb-done')) {
                    addCls(r, 'owb-dis');
                }
            }
        }
    }

    /** 挂到 OwBatch 上：forwardOpen / inviteOpen 通过 this.picker 调用，其它插件也可直接复用 */
    OwBatch.picker = openPicker;

    /* ==========================================================================
       业务一：逐条转发
       ========================================================================== */
    /** msgs 缺省 = 走多选集合；显式传入 = 单条转发入口 */
    function doForward(target, ctl, msgs) {
        var queue = [], i, m, skipped = 0, total = 0;
        if (msgs) {
            total = msgs.length;
            for (i = 0; i < msgs.length; i++) if (msgUsable(msgs[i])) queue.push(msgs[i]);
        } else {
            var ids = OwBatch.ids();
            total = ids.length;
            for (i = 0; i < ids.length; i++) {
                m = msgById(ids[i]);
                if (msgUsable(m)) queue.push(m);
            }
        }
        skipped = total - queue.length;
        if (!queue.length) { toast('所选消息已失效，无法转发'); ctl.fail(); return; }
        step(queue, 0, 0, skipped, target, ctl, 0, !!msgs);
    }

    /**
     * 转发队列：逐条串行（保序），每条之间留最小间隔压低撞限流的概率；
     * 撞上发言频率限制后等待窗口重置再重试同一条（服务端限流是固定窗口计数）。
     * keepMode = 单条转发入口调用：完成后不动选择模式（可能正处在多选流程里）。
     */
    function step(queue, idx, sent, skipped, target, ctl, tries, keepMode) {
        if (idx >= queue.length) {
            ctl.close();
            if (!keepMode) OwBatch.exit();
            var done = '已转发 ' + sent + ' 条到「' + target.name + '」';
            if (skipped > 0) done += '（跳过 ' + skipped + ' 条已失效消息）';
            toast(done, 3200);
            return;
        }
        ctl.busy('转发中 ' + (idx + 1) + '/' + queue.length);
        sendOne(queue[idx], target, function (ok, why, retriable) {
            if (ok) {
                setTimeout(function () { step(queue, idx + 1, sent + 1, skipped, target, ctl, 0, keepMode); }, SEND_GAP);
                return;
            }
            if (retriable && tries < RATE_TRIES) {
                var wait = RATE_WAIT, mm = /间隔为\s*(\d+)\s*秒/.exec(why || '');
                if (mm) wait = (parseInt(mm[1], 10) + 1) * 1000;   // 游客发言间隔：按服务端报的秒数等
                if (wait <= 20000) {
                    setTimeout(function () { step(queue, idx, sent, skipped, target, ctl, tries + 1, keepMode); }, wait);
                    return;
                }
            }
            ctl.fail();
            if (!keepMode) {
                /* 已发出去的那几条从选择里摘掉：中止后用户再点「转发」时
                   队列只带剩下的 —— 否则会把发过的重发一遍（游客间隔这类
                   服务端限流最容易触发这条路径）。 */
                for (var si = 0; si < idx; si++) {
                    if (OwBatch.sel[queue[si].id]) OwBatch.toggle(queue[si].id);
                }
            }
            toast('已转发 ' + sent + ' 条，第 ' + (idx + 1) + ' 条失败：' + (why || '网络错误'), 3800);
        });
    }

    /** 发一条：图片/文件/混排按原 type 与 content 原样重发（服务端各自校验）；引用不带（跨会话的引用 id 无意义） */
    function sendOne(m, target, cb) {
        var type = m.type === 'mention' ? 'text' : m.type;
        w.OwApi.post('send', {
            room_id: target.kind === 'room' ? target.id : 0,
            type: type,
            content: m.content,
            to_user_id: target.kind === 'user' ? target.id : '',
            to_nickname: target.kind === 'user' ? (target.name || '') : '',
            client_id: w.OwChat.newCid()
        }, function (r) {
            if (r && r.ok) { cb(true); return; }
            var why = (r && r.msg) || '网络错误';
            cb(false, why, /频繁|稍后再试|间隔/.test(why));
        });
    }

    /* ==========================================================================
       业务一·B：合并转发（N 条 → 一张「聊天记录」卡片）
       ========================================================================== */
    /**
     * 两步走：① 服务端对所选消息做快照（只收「本人此刻有权看到」的，权限判定在
     * plugin_batch_ops_record_save 里），拿回 token 与卡片预览；② 往目标发一条
     * 极短的引用消息 [聊天记录]#token#（正文要过核心的过滤/长度，塞不下 N 条内容）。
     * 卡片渲染、点开查看都由本脚本的卡片体系负责；快照数据同时写进本页缓存，
     * 发件方自己的卡片立刻出（不必等 record_get 回来）。
     */
    function doMerge(target, ctl) {
        var msgs = OwBatch.selectedMsgs();
        if (!msgs.length) { toast('所选消息已失效，无法转发'); ctl.fail(); return; }
        var ids = [], i;
        for (i = 0; i < msgs.length; i++) ids.push(msgs[i].id);
        ctl.busy('合并转发中…');
        w.OwApi.post('plugin_batch_ops_record_save', { ids: ids.join(',') }, function (r) {
            if (!r || !r.ok) {
                ctl.fail();
                toast('合并转发失败：' + ((r && r.msg) || '网络错误'), 3600);
                return;
            }
            recCache[r.token] = { title: r.title || '聊天记录', count: r.count, preview: r.preview || [] };
            w.OwApi.post('send', {
                room_id: target.kind === 'room' ? target.id : 0,
                type: 'text',
                content: '[聊天记录]#' + r.token + '#',
                to_user_id: target.kind === 'user' ? target.id : '',
                to_nickname: target.kind === 'user' ? (target.name || '') : '',
                client_id: w.OwChat.newCid()
            }, function (sr) {
                if (sr && sr.ok) {
                    ctl.close();
                    OwBatch.exit();
                    toast('已合并转发 ' + r.count + ' 条到「' + target.name + '」', 3200);
                    return;
                }
                ctl.fail();
                toast('合并转发失败：' + ((sr && sr.msg) || '网络错误'), 3600);
            });
        });
    }

    /* ==========================================================================
       业务二：邀请好友进群（核心 room_invite，敏感操作走一次性票据）
       ========================================================================== */
    function doInvite(roomId, target, ctl) {
        ctl.busy('邀请中…');
        w.OwApi.secure('room_invite', { room_id: roomId, user_id: target.id }, function (r) {
            var msg = (r && r.msg) || '邀请失败';
            toast(msg);
            if (r && r.ok) { ctl.row('已邀请'); return; }
            if (msg.indexOf('已在群内') >= 0) { ctl.row('已在群内'); return; }   // 幂等：已经在群里，按完成呈现
            ctl.fail();
        });
    }

    /* ==========================================================================
       挂钩与全局事件
       ========================================================================== */
    /* 消息右键（内容菜单）：「转发」（单条直达）插在「引用」上面，「多选」紧跟其后（站方指定位置） */
    w.OwChat.onMsgContent(function (items, m, env) {
        if (!msgUsable(m)) return;
        var fwd = { t: '转发', icon: 'send', run: function () { OwBatch.forwardMsg(m); } };
        var at = -1, i;
        for (i = 0; i < items.length; i++) {
            if (items[i].t === '引用') { at = i; break; }
        }
        /* 插件推送的默认位置是菜单末尾；转发 / 多选是高频入口，splice 到「引用」之前。
           引用不存在（游客不带这条）时退回末尾，不硬造位置。 */
        if (at >= 0) items.splice(at, 0, fwd); else items.push(fwd);
        var sel = { t: '多选', icon: 'check-square', run: function () { OwBatch.enter(m); } };
        var fwAt = -1;
        for (i = 0; i < items.length; i++) {
            if (items[i].t === '转发') { fwAt = i; break; }
        }
        if (fwAt >= 0) items.splice(fwAt + 1, 0, sel); else items.push(sel);
    });

    /* 右侧栏群聊信息面板：只留「邀请好友」（多选入口已按站方要求移到右键菜单，不再出现在侧边栏） */
    w.OwChat.onRoomEdit(function (ctx) {
        var extras = byId('owREExtras');
        if (!extras || !ctx.roomId) return;
        var room = roomOf(ctx.roomId);
        if (!room || room.can_invite) {
            panelEntry(extras,
                w.OwChat.icon('user', 16, 'ow-panel-entry-ico'),
                '邀请好友',
                function () { OwBatch.inviteOpen(ctx.roomId); });
        }
    });

    /** 往面板入口区追加一行（与 room-embed 的 addPanelEntry 同一形态：图标 + 文案 + 右箭头） */
    function panelEntry(extras, iconHtml, text, onclick) {
        var row = d.createElement('div');
        row.className = 'ow-panel-entry';
        row.setAttribute('role', 'button');
        row.setAttribute('tabindex', '0');
        row.innerHTML = iconHtml + '<span class="ow-panel-entry-t">' + esc(text) + '</span>'
            + '<span class="ow-panel-entry-arrow">›</span>';
        row.onclick = onclick;
        row.onkeydown = function (e) {
            e = e || w.event;
            if (e.keyCode === 13 || e.keyCode === 32) {
                if (e.preventDefault) e.preventDefault(); else e.returnValue = false;
                onclick();
            }
        };
        extras.appendChild(row);
    }

    /* 会话切换即退出选择模式：选择状态是「这个会话里选的消息」，跨会话没有意义 */
    w.OwChat.onViewChange(function (ctx) {
        var key = ctx.view + '|' + (ctx.roomId || 0) + '|' + (ctx.peer || '');
        if (OwBatch._lastView && key !== OwBatch._lastView) OwBatch.exit();
        OwBatch._lastView = key;
    });

    /* 选择模式下的整行点击（捕获阶段抢在内联 onclick 之前拦下：
       图片放大 / 资料卡 / 引用跳转等都被统一改写为「切换勾选」） */
    var msgBox = byId('owMessages');
    if (msgBox && msgBox.addEventListener) {
        msgBox.addEventListener('click', function (e) {
            if (!OwBatch.mode) return;
            var t = e.target || e.srcElement, node = t;
            while (node && node !== msgBox && !(node.id && node.id.indexOf('owMsg') === 0)) node = node.parentNode;
            if (!node || node === msgBox) return;
            if (e.preventDefault) e.preventDefault();
            if (e.stopPropagation) e.stopPropagation();
            OwBatch.toggle(parseInt(node.id.replace('owMsg', ''), 10));
        }, true);
    }

    /* Esc：先关选择器弹窗（若它开着），再退选择模式 */
    d.addEventListener('keydown', function (e) {
        e = e || w.event;
        if (e.keyCode !== 27) return;
        var mask = byId('owModalMask');
        var pickerOpen = mask && mask.style.display !== 'none' && byId('owbList');
        if (pickerOpen) { w.OwChat.closeModal(); return; }
        if (OwBatch.mode) OwBatch.exit();
    }, false);

    /* ---------- 聊天记录卡片：渲染与点击 ---------- */
    /* 卡片渲染跟着消息 DOM 走：首屏补扫一次（插件脚本晚于首屏历史渲染），
       此后新消息（历史/轮询/WS/翻页）由 MutationObserver 兜住 —— 与勾选框同一套覆盖策略。 */
    if (msgBox) {
        scanCards(msgBox);
        if (w.MutationObserver) {
            new w.MutationObserver(function (recs) {
                for (var i = 0; i < recs.length; i++) {
                    var added = recs[i].addedNodes || [];
                    for (var j = 0; j < added.length; j++) {
                        var n = added[j];
                        if (n && n.nodeType === 1 && n.id && n.id.indexOf('owMsg') === 0) mountCard(n);
                    }
                }
            }).observe(msgBox, { childList: true });
        }
        /* 点卡片看完整记录（冒泡阶段；多选模式的捕获处理器会先 stopPropagation，
           所以选消息时点卡片 = 切换勾选，不会误开弹窗） */
        msgBox.addEventListener('click', function (e) {
            if (OwBatch.mode) return;
            var t = e.target || e.srcElement, node = t;
            while (node && node !== msgBox && !(node.className && String(node.className).indexOf('owb-rec') === 0)) node = node.parentNode;
            if (!node || node === msgBox) return;
            openRecord(node.getAttribute('data-token'));
        });
    }

    w.OwBatch = OwBatch;   // 暴露给其它插件复用（picker 是通用轮子）

    /* 时序修正：核心在 OwChat.init() 内已渲染过一次右侧栏面板，
       本脚本在 init 之后才加载 —— 主动补一次，让「邀请好友」入口首屏即出现 */
    try { w.OwChat.renderRoomPanel(); } catch (e) {}
})(window, document);
