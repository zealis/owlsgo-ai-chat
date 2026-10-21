/**
 * 内容举报插件 · 前台能力
 *
 * 入口：
 *   ① 群聊消息右键「头像」菜单（OwChat.onMsgCtx）——有消息上下文
 *   ② 成员搜索结果里的「举报」（OwChat.onUserAction）——按人举报，没有消息上下文
 *   两者走同一个举报弹窗：理由 + 财产损失勾选 + 截图 + 补充说明，
 *   提交到 plugin_content_report_submit。
 *
 * 通知：
 *   后台配置的「通知联系人」（服务端 notice.contacts 登记的那一行）被点时，
 *   打开待处理举报面板 —— AI 判违规 / 勾了财产损失的举报都在这儿，
 *   可直接「已处理 / 忽略」（超管权限，服务端还会再校验一次）。
 *
 * ⚠️ 用户资料卡的「举报」按钮 v1.2.24 已按要求移除（见文件内「资料卡『举报』按钮」一节）。
 *
 * 开关（后台配置）：
 *   - require_desc：必须填写补充说明
 *   - allow_self  ：允许举报自己的内容
 *   - show_guest  ：未登录显示举报入口（点击跳转登录）
 *   - show_loss   ：显示「遭受财产损失」勾选项
 *   - allow_image / image_max：举报截图开关与张数上限
 *
 * 兼容老浏览器：仅用 var / function，无箭头函数、无 const/let、无 fetch。
 */
(function (w, d) {
    if (!w.OwChat || !OwChat.cfg) return;
    if (w.__haCRLoaded) return;
    w.__haCRLoaded = true;

    var esc   = w.esc   || function (s) { return String(s == null ? '' : s); };
    var toast = w.toast || function () {};
    var pad   = w.fmtUid || function (n) { return String(n || ''); };
    var $ = function (id) { return d.getElementById(id); };

    var CR = w.OwCR = w.OwCR || {};

    /** 通知联系人 key（必须与服务端 OWCR_NOTICE_KEY 一致） */
    var NOTICE_KEY = 'content-report';

    /* ---------- 配置缓存（启动时拉取一次，菜单显隐与弹窗规则都依赖它） ---------- */
    var cfgCache = {
        show_guest: 0, require_desc: 0, allow_self: 0, show_loss: 1,
        allow_image: 0, image_max: 3,
        reasons: [], desc_limit: 200, loaded: false
    };

    function loadCfg(cb) {
        OwApi.post('plugin_content_report_get_reasons', {}, function (r) {
            if (r.ok && r.data) {
                cfgCache.show_guest   = parseInt(r.data.show_guest, 10) || 0;
                cfgCache.require_desc = parseInt(r.data.require_desc, 10) || 0;
                cfgCache.allow_self   = parseInt(r.data.allow_self, 10) || 0;
                cfgCache.show_loss    = parseInt(r.data.show_loss, 10) || 0;
                cfgCache.allow_image  = parseInt(r.data.allow_image, 10) || 0;
                cfgCache.image_max    = parseInt(r.data.image_max, 10) || 3;
                cfgCache.reasons      = r.data.reasons || [];
                cfgCache.desc_limit   = parseInt(r.data.desc_limit, 10) || 200;
            }
            cfgCache.loaded = true;
            if (cb) cb();
        });
    }
    loadCfg();

    /** 当前是否已登录 */
    function isLogin() {
        var actor = (OwChat.cfg || {}).actor || {};
        var me = (OwChat.cfg || {}).me || null;
        return actor.kind === 'user' && me && me.id;
    }

    /** 跳转登录页 */
    function goLogin() {
        w.location.href = '?page=login';
    }

    /* ---------- 资料卡「举报」按钮：v1.2.24 移除 ----------
       原实现：包装 OwChat.userCard 记下目标用户 → 再包装 OwChat.openModal，
       往资料卡的 <div class="ow-modal-actions"> 里塞一个 ow-btn-danger 按钮。
       移除理由：资料卡是「看这个人是谁」的地方，底部摆一个红色「举报」把气氛
       搞得很对立；而举报真正的场景是「看到一条具体的违规内容」，那里走
       OwChat.onMsgCtx（右键头像菜单）能精确定位到被举报对象，也更顺手。

       ⚠️ 删除时必须连**三件套**一起删：lastCardTarget 变量、userCard 包装、
       openModal 包装 —— 它们只服务于这一个按钮，留下任何一个都是死代码，
       而且 openModal 包装是全站弹窗的公共路径，留着等于给所有弹窗加一道
       字符串匹配，纯负担。openReport 本身保留（右键菜单还在用）。 */

    /* ---------- 举报弹窗：理由 + 财产损失勾选 + 截图 + 补充说明 ---------- */
    CR.openReport = function (targetUid, targetNick, roomId, msgId, msgTime) {
        targetUid = parseInt(targetUid, 10) || 0;
        msgTime = parseInt(msgTime, 10) || 0;
        if (targetUid <= 0) { toast('举报对象无效'); return; }

        // 未登录：跳转登录（show_guest 开启时游客可见入口）
        if (!isLogin()) { goLogin(); return; }

        var me = (OwChat.cfg || {}).me || null;
        // 允许举报自己关闭时，拦截自己
        if (me && me.id && targetUid === me.id && !cfgCache.allow_self) {
            toast('不能举报自己'); return;
        }
        // v1.2.3：不能举报超级管理员（服务端也会拒，这里先给一句明确的话）
        if (isAdminUid(targetUid)) { toast('不能举报超级管理员'); return; }

        // 配置未加载完时等待
        if (!cfgCache.loaded) {
            loadCfg(function () { CR.openReport(targetUid, targetNick, roomId, msgId, msgTime); });
            return;
        }

        var reasons = cfgCache.reasons || [];
        var descLimit = cfgCache.desc_limit || 200;
        var requireDesc = cfgCache.require_desc ? true : false;
        if (!reasons.length) { toast('管理员尚未配置举报理由'); return; }

        var opts = '';
        for (var i = 0; i < reasons.length; i++) {
            opts += '<option value="' + esc(reasons[i]) + '">' + esc(reasons[i]) + '</option>';
        }

        var descLabel = '补充说明（' + (requireDesc ? '必填' : '可选') + '，最多 ' + descLimit + ' 字）';

        // 「遭受财产损失」：勾上就不走 AI，直接提醒超级管理员（服务端同样按这个开关处理）。
        // ⚠️ 外层**不能**用 .ow-form-item —— 核心 `.ow-form-item label { display:block }`（0,1,1）
        //    会盖掉 .ow-check-row 的 flex，勾选框与文字就上下错位了（站方反馈的正是这个）。
        //    放在 .ow-cr-loss 里，.ow-check-row 自己的 flex 才生效。
        var lossHtml = cfgCache.show_loss
            ? '<div class="ow-cr-loss"><label class="ow-check-row">'
              + '<input type="checkbox" id="owCRLoss"> 遭受财产损失</label>'
              + '<p class="ow-cr-tip">勾选后该举报不经过自动审核，直接提醒超级管理员处理。</p></div>'
            : '';

        // 截图上传：按钮 + 缩略图（其实只是个 <input type=file> 的替身，样式随全站按钮）
        var shotHtml = cfgCache.allow_image
            ? '<div class="ow-form-item"><label>举报截图（最多 ' + esc(cfgCache.image_max) + ' 张）</label>'
              + '<input type="file" id="owCRShotInput" accept="image/*" multiple style="display:none">'
              + '<div class="ow-cr-shots" id="owCRShots"></div>'
              + '<button type="button" class="ow-btn ow-btn-ghost ow-cr-shot-add" id="owCRShotAdd">添加截图</button>'
              + '<p class="ow-cr-tip" id="owCRShotTip">支持 jpg / png / gif / webp，作为证据供管理员查看。</p></div>'
            : '';

        OwChat.openModal(
            '<h3>举报用户</h3>'
            + '<p class="ow-modal-desc">举报对象：' + esc(targetNick) + '（用户 ID：' + esc(pad(targetUid)) + '）</p>'
            + '<div class="ow-form-item"><label>举报理由</label>'
            + '<select class="ow-input" id="owCRReason">' + opts + '</select></div>'
            + shotHtml
            + '<div class="ow-form-item"><label>' + descLabel + '</label>'
            + '<textarea class="ow-input" id="owCRDesc" rows="4" maxlength="' + descLimit + '" style="resize:vertical"></textarea></div>'
            // 勾选框排在**补充说明之后**（站方：应该放在补充说明下面）
            + lossHtml
            + '<div class="ow-modal-actions">'
            + '<button class="ow-btn ow-btn-ghost" onclick="OwChat.closeModal()">取消</button>'
            + '<button class="ow-btn ow-btn-danger" id="owCRSubmit">提交举报</button></div>'
        );

        /* ---- 截图上传 ---- */
        var imgs = [];
        var uploading = 0;

        function renderShots() {
            var box = $('owCRShots');
            if (!box) return;
            var h = '';
            for (var i = 0; i < imgs.length; i++) {
                h += '<span class="ow-cr-shot">'
                   + '<img src="' + esc(imgs[i]) + '" alt="举报截图 ' + (i + 1) + '" loading="lazy" decoding="async">'
                   + '<a href="javascript:;" class="ow-cr-shot-del" data-i="' + i + '"'
                   + ' title="移除这张截图" aria-label="移除这张截图">✕</a></span>';
            }
            box.innerHTML = h;
            var dels = box.getElementsByClassName('ow-cr-shot-del');
            for (var k = 0; k < dels.length; k++) {
                dels[k].onclick = function () {
                    var idx = parseInt(this.getAttribute('data-i'), 10);
                    if (idx >= 0) { imgs.splice(idx, 1); renderShots(); }
                };
            }
            var add = $('owCRShotAdd');
            if (add) add.style.display = (imgs.length >= cfgCache.image_max || uploading > 0) ? 'none' : '';
        }

        function syncTip() {
            var tip = $('owCRShotTip');
            if (!tip) return;
            tip.textContent = uploading > 0
                ? '正在上传…（已上传 ' + imgs.length + '/' + cfgCache.image_max + ' 张）'
                : '支持 jpg / png / gif / webp，作为证据供管理员查看。已上传 '
                  + imgs.length + '/' + cfgCache.image_max + ' 张。';
        }

        var shotAdd = $('owCRShotAdd');
        var shotInput = $('owCRShotInput');
        if (shotAdd && shotInput) {
            shotAdd.onclick = function () { shotInput.click(); };
            shotInput.onchange = function () {
                var files = shotInput.files || [];
                var queue = [];
                for (var i = 0; i < files.length; i++) {
                    if (imgs.length + queue.length >= cfgCache.image_max) break;
                    queue.push(files[i]);
                }
                shotInput.value = '';                    // 同一张图连选两次也要能触发 change
                if (!queue.length) { toast('最多只能上传 ' + cfgCache.image_max + ' 张截图'); return; }
                (function next() {
                    if (!queue.length) { uploading = 0; renderShots(); syncTip(); return; }
                    uploading = 1;
                    renderShots(); syncTip();
                    var f = queue.shift();
                    OwApi.upload('plugin_content_report_upload', f, {}, function (r) {
                        if (r && r.ok && r.url) imgs.push(r.url);
                        else toast((r && r.msg) || '截图上传失败');
                        next();
                    });
                })();
            };
        }
        renderShots();
        syncTip();

        /* ---- 提交 ---- */
        var submit = $('owCRSubmit');
        if (submit) {
            submit.onclick = function () {
                var reason = $('owCRReason') ? $('owCRReason').value : '';
                var desc = $('owCRDesc') ? $('owCRDesc').value : '';
                var loss = ($('owCRLoss') && $('owCRLoss').checked) ? 1 : 0;
                if (!reason) { toast('请选择举报理由'); return; }
                if (requireDesc && !desc.replace(/^\s+|\s+$/g, '')) { toast('请填写补充说明'); return; }
                if (uploading) { toast('截图还在上传，请稍候'); return; }
                submit.disabled = true;
                OwApi.post('plugin_content_report_submit', {
                    target_uid: targetUid,
                    target_nick: targetNick,
                    reason: reason,
                    description: desc,
                    room_id: roomId || 0,
                    msg_id: msgId || 0,
                    msg_time: msgTime || 0,
                    loss: loss,
                    images: JSON.stringify(imgs)
                }, function (r2) {
                    submit.disabled = false;
                    if (r2.ok) OwChat.closeModal();
                    toast(r2.msg || (r2.ok ? '举报已提交' : '操作失败'));
                });
            };
        }
    };

    /* ---------- 待处理举报面板（通知联系人被点时打开） ---------- */

    /** 通知行未读数刷新：改完 cfg 再让核心重画那一行（拿不到就等下次刷新页面） */
    function refreshNoticeBadge(n) {
        try {
            var cs = (OwChat.cfg && OwChat.cfg.notice_contacts) || [];
            for (var i = 0; i < cs.length; i++) {
                if (cs[i].key === NOTICE_KEY) cs[i].unread = n | 0;
            }
            if (typeof OwChat.renderNoticeRow === 'function') OwChat.renderNoticeRow();
        } catch (e) { /* 行渲染是核心私事，失败不影响举报本身 */ }
    }

    function alertRowHtml(r) {
        var isLoss = parseInt(r.loss, 10) === 1;
        var tag = isLoss
            ? '<span class="ow-tag ow-tag-owner">财产损失</span>'
            : (r.ai_verdict === 'violate'
                ? '<span class="ow-tag ow-tag-red">AI 判定违规</span>'
                : '<span class="ow-tag ow-tag-green">AI 审核</span>');

        var h = '<div class="ow-cr-alert" data-id="' + esc(r.id) + '">'
            + '<div class="ow-cr-alert-hd">' + tag
            + '<span class="ow-cr-alert-who">' + esc(r.reporter_nick || ('#' + r.reporter_id))
            + ' 举报 ' + esc(r.target_nick || ('#' + r.target_uid)) + '</span>'
            + '<span class="ow-cr-alert-time">' + esc(new Date((r.created_at || 0) * 1000).toLocaleString()) + '</span></div>'
            + '<div class="ow-cr-alert-bd"><b>理由：</b>' + esc(r.reason || '—');
        if (r.description) h += '　<b>说明：</b>' + esc(r.description);
        if (r.ai_hit) h += '<br><b>AI 命中：</b>' + esc(r.ai_hit);
        if (r.ai_note) h += '　' + esc(r.ai_note);
        h += '</div>';

        var imgs = [];
        try { imgs = JSON.parse(r.images || '[]') || []; } catch (e) { imgs = []; }
        if (imgs.length) {
            h += '<div class="ow-cr-alert-imgs">';
            for (var i = 0; i < imgs.length; i++) {
                // 点开用**站内灯箱**（OwChat.viewImg，与消息里的图片同一套；它的 z-index 200
                // 高于弹窗遮罩 100，所以能盖在弹窗上放大，不必新开标签页）
                h += '<img src="' + esc(imgs[i]) + '" alt="举报截图 ' + (i + 1) + '"'
                   + ' loading="lazy" decoding="async" title="点击查看大图" onclick="OwChat.viewImg(this.src)">';
            }
            h += '</div>';
        }

        h += '<div class="ow-cr-alert-ops">'
            + '<a href="javascript:;" data-act="1">已处理</a>'
            + '<a href="javascript:;" data-act="2">忽略</a></div></div>';
        return h;
    }

    CR.openAlerts = function () {
        OwChat.openModal(
            '<h3>待处理举报</h3>'
            + '<p class="ow-modal-desc">AI 判定违规、以及用户勾选「遭受财产损失」的举报会出现在这里。</p>'
            + '<div class="ow-cr-alerts" id="owCRAlerts"><div class="ow-cr-empty">加载中…</div></div>',
            560
        );

        function load() {
            OwApi.post('plugin_content_report_alerts', { limit: 20 }, function (r) {
                var box = $('owCRAlerts');
                if (!box) return;
                if (!r || !r.ok) { box.innerHTML = '<div class="ow-cr-empty">加载失败</div>'; return; }
                var list = (r.data && r.data.list) || [];
                refreshNoticeBadge((r.data && r.data.total) || 0);
                if (!list.length) {
                    box.innerHTML = '<div class="ow-cr-empty">没有待处理的举报</div>';
                    return;
                }
                var h = '';
                for (var i = 0; i < list.length; i++) h += alertRowHtml(list[i]);
                box.innerHTML = h;

                var rows = box.getElementsByClassName('ow-cr-alert');
                for (var k = 0; k < rows.length; k++) {
                    (function (row) {
                        var ops = row.getElementsByTagName('a');
                        for (var j = 0; j < ops.length; j++) {
                            var act = ops[j].getAttribute('data-act');
                            if (!act) continue;
                            ops[j].onclick = function () {
                                var id = parseInt(row.getAttribute('data-id'), 10);
                                OwApi.secure('plugin_content_report_handle', { id: id, status: parseInt(act, 10) },
                                    function (r2) {
                                        toast((r2 && r2.msg) || '已更新');
                                        if (r2 && r2.ok) { refreshNoticeBadge(r2.alert_count | 0); load(); }
                                    });
                            };
                        }
                    })(rows[k]);
                }
            });
        }
        load();
    };

    /* ---------- 消息箱（独立一个消息框，站方 v1.2.0 口径） ----------
       侧栏「内容举报」那一行点开的就是它：**主区**换成一条条消息（头像 + 昵称 + 时间 +
       正文 + 截图缩略图 + 「查看」按钮），样式与「系统通知」那一页同一套气泡，
       但不是那一页（系统通知页仍只显示系统通知与别的插件条目）。
       按身份给不同内容：超管看待处理举报，举报人看自己的审核结论。 */

    /** 通知行的头像 / 昵称（后台可配，没配由核心兜底成插件名 + 生成头像） */
    function noticeRow() {
        var cs = (OwChat.cfg && OwChat.cfg.notice_contacts) || [];
        for (var i = 0; i < cs.length; i++) if (cs[i].key === NOTICE_KEY) return cs[i];
        return null;
    }

    /** 我的未读条数（服务端按身份算，boot 时随 notice_contacts 下发） */
    CR.myUnread = function () {
        var row = noticeRow();
        return row ? (parseInt(row.unread, 10) || 0) : 0;
    };

    /**
     * rail「通知」按钮的小红点：有未读就亮。
     * 复用核心那颗 `#owNoticeDot`（与系统通知同一个轮子）—— 它本来就表示
     * 「通知区有东西没看」，插件消息也在通知区里。系统通知的未读一起算，
     * 避免我把系统的那颗也灭掉（coreUnread 从 cfg.notice_unread 取）。
     */
    CR.paintDot = function (n) {
        try {
            var dot = d.getElementById('owNoticeDot');
            if (!dot) return;
            var coreUnread = (OwChat.cfg && OwChat.cfg.notice_unread) || 0;
            dot.style.display = ((n | 0) > 0 || coreUnread > 0) ? '' : 'none';
        } catch (e) { /* 红点是装饰，失败不影响举报本身 */ }
    };

    /**
     * 把侧栏选中框指回「内容举报」这一行。
     * ⚠️ 核心 renderNoticeRow() 在 noticeMode 下固定点亮「系统通知」那行（activeKey='dm:notice'），
     * 而 markSeen / 其它触发重画的地方都会调它 —— 消息箱开着时要在每次重画后补一次，
     * 否则用户点了「内容举报」、选中框又跳回「系统通知」。
     */
    CR.repointActive = function () {
        var mb = $('owMessages');
        if (mb && mb.getAttribute('data-cr-box')) {
            ChatList.activate('owNoticeList', 'dm:' + NOTICE_KEY);
        }
    };

    /** 上报已读水位（敏感操作）——「不要一刷新又是未读」就靠服务端这个水位 */
    CR.markSeen = function (id) {
        OwApi.secure('plugin_content_report_seen', { id: id || 0 }, function (r) {
            try {
                var cs = (OwChat.cfg && OwChat.cfg.notice_contacts) || [];
                for (var i = 0; i < cs.length; i++) if (cs[i].key === NOTICE_KEY) cs[i].unread = 0;
                if (typeof OwChat.renderNoticeRow === 'function') OwChat.renderNoticeRow();
            } catch (e) {}
            CR.repointActive();          // 重画完把选中框指回「内容举报」（否则跳回系统通知）
            CR.paintDot(0);
        });
    };

    /**
     * 某用户是不是超级管理员（v1.2.3：不能举报超级管理员的消息）。
     * 消息右键菜单里 `m.role` 直接就有；成员搜索只给 `{uid,gid,nickname,kind}`（没有 role），
     * 所以沿用 user-block 的手法：从 msgCache 里找他任意一条消息的 role。
     * 缓存里没有就判不出来 —— 那种情况由服务端硬校验兜底（接口一样会拒）。
     */
    function isAdminUid(uid) {
        uid = parseInt(uid, 10) || 0;
        if (uid <= 0) return false;
        var cache = OwChat.msgCache || {}, k, mm;
        for (k in cache) {
            if (!Object.prototype.hasOwnProperty.call(cache, k)) continue;
            mm = cache[k];
            if (mm && parseInt(mm.uid, 10) === uid && mm.role === 'admin') return true;
        }
        return false;
    }

    /** 昵称可点击 → 弹用户资料卡（站方 v1.2.0：点昵称就弹窗用户资料） */
    function nickLink(uid, nick) {
        uid = parseInt(uid, 10) || 0;
        if (!uid) return esc(nick || '未知用户');
        return '<a href="javascript:;" class="ow-msg-link" onclick="OwChat.userCard(' + uid + ')">'
            + esc(nick || ('#' + uid)) + '</a>';
    }

    /** 一条消息的正文（客户端拼，才能把昵称变成可点的链接） */
    function boxBody(m, isAdmin) {
        var imgs = m.images || [];
        if (isAdmin) {
            var head = m.kind === 'loss' ? '财产损失举报：' : 'AI 判定违规：';
            return head + nickLink(m.reporter_id, m.reporter_nick) + ' 举报 '
                + nickLink(m.target_uid, m.target_nick)
                + '（' + esc(m.reason || '') + '）'
                + (imgs.length ? '·附 ' + imgs.length + ' 张截图' : '');
        }
        return esc(m.text || '') + '　被举报人：' + nickLink(m.target_uid, m.target_nick);
    }

    /** 一条消息的 HTML（就是核心那套通知气泡 .ow-msg .ow-msg-notice，不另起一套） */
    function boxMsgHtml(m, isAdmin, av, nick) {
        var imgs = m.images || [], ih = '', k;
        for (k = 0; k < imgs.length; k++) {
            ih += '<img class="ow-msg-img" src="' + esc(imgs[k]) + '" alt="举报截图" loading="lazy" decoding="async" onclick="OwChat.viewImg(this.src)">';
        }
        if (ih) ih = '<div class="ow-notice-imgs">' + ih + '</div>';
        // 时间与「系统通知」那条流同一个轮子（ChatList.time），两边看着才像一回事
        var tm = (typeof ChatList !== 'undefined' && ChatList && typeof ChatList.time === 'function')
            ? ChatList.time((m.at || 0) | 0)
            : new Date((m.at || 0) * 1000).toLocaleString();
        return '<div class="ow-msg ow-msg-notice ow-cr-boxmsg' + (parseInt(m.unread, 10) ? ' is-unread' : '') + '">'
            + (av ? '<span class="ow-msg-av"><span class="ow-avatar"><img src="' + esc(av)
                  + '" alt="' + esc(nick) + '" loading="lazy" decoding="async"></span></span>' : '')
            + '<div class="ow-msg-body">'
            + '<div class="ow-msg-meta"><span class="ow-msg-nick">' + esc(nick) + '</span>'
            + '<span class="ow-msg-time">' + esc(tm) + '</span></div>'
            + '<span class="ow-msg-content">' + boxBody(m, isAdmin) + '</span>'
            + ih
            + '<div class="ow-cr-boxmsg-ops">'
            + '<button type="button" class="ow-btn ow-btn-ghost ow-cr-view" data-id="' + esc(m.id) + '">查看</button>'
            + '</div></div></div>';
    }

    /**
     * 「查看」用**一次事件委托**接住：#owMessages 是服务端静态输出的节点，
     * 主区被 innerHTML 反复重建也不丢监听（与核心 renderNoticeStream 绑 .ow-notice-act 同一手法）。
     */
    function bindBoxView() {
        var mb = $('owMessages');
        if (!mb || mb._crViewBound) return;
        mb._crViewBound = true;
        mb.addEventListener('click', function (e) {
            var t = e.target || e.srcElement;
            while (t && t !== mb && !(t.className && (' ' + t.className + ' ').indexOf(' ow-cr-view ') >= 0)) {
                t = t.parentNode;
            }
            if (!t || t === mb) return;
            var id = parseInt(t.getAttribute('data-id'), 10) || 0;
            // 超管：待处理举报面板（可直接处理）；举报人：这一条的结论详情
            if (mb.getAttribute('data-cr-box') === 'admin') CR.openAlerts();
            else CR.openResult(id);
        });
    }

    /**
     * 打开消息箱：把**主区**换成举报的消息流。
     *
     * 形态与「系统通知」完全一致（同一片 #owMessages、同一套 .ow-msg-notice 气泡）：
     * 顶栏换成「内容举报」、收起属于房间的那几样控件、输入区禁用（单向、不需要回复）。
     * 但它**不是系统通知那一页** —— 系统通知那一页仍只显示系统通知与别的插件条目。
     *
     * 进出都走核心那套通知视图流程（而不是自己弹一个窗）：
     * noticeMode=true 后，用户切 rail 标签时核心的 exitNotices() 会自动把顶栏与
     * 群聊按钮还回房间；同时要停掉在飞的轮询、清掉当前会话，否则旧房间的消息会盖掉消息箱。
     */
    CR.openBox = function () {
        if (!isLogin()) return;
        if (!OwChat.noticeMode) {
            OwChat._titleBefore = ($('owRoomName') || {}).textContent || '';
            OwChat.noticeMode = true;
        }
        OwChat.pollGen = (OwChat.pollGen || 0) + 1;
        OwChat.dmGen = (OwChat.dmGen || 0) + 1;
        OwChat._roomPollRunning = false;
        OwChat.dm = null; OwChat.room = 0; OwChat.since = 0;
        OwChat.historyDone = true; OwChat.loadingHistory = false;
        ChatList.activate('owRoomList', '');
        if (typeof OwChat.renderNoticeRow === 'function') OwChat.renderNoticeRow();
        ChatList.activate('owNoticeList', 'dm:' + NOTICE_KEY);   // 点亮的是「内容举报」这一行
        OwChat.paintNoticeChrome(true);                          // 收起「群聊信息」与延迟显示
        var tn = $('owRoomName'); if (tn) tn.textContent = '内容举报';
        OwChat.applyInputGate('notice');                         // 单向：禁用输入与工具栏
        var inp = $('owInput'); if (inp) inp.placeholder = '举报消息不需要回复';
        // v1.3.133：通知别的插件「视图已变」——不通知的话，上一个群的公告条会压在消息箱上
        if (typeof OwChat._fireViewChange === 'function') OwChat._fireViewChange();
        var box = $('owMessages');
        if (box) box.innerHTML = '<div class="ow-notice-pending">加载中…</div>';
        CR.paintDot(0);                                          // 打开即灭（系统通知那颗不受影响）
        CR.loadBox();
    };

    /** 拉消息箱数据 → 主区渲染成消息流（旧→新，与通知流同向） */
    CR.loadBox = function () {
        OwApi.post('plugin_content_report_box', {}, function (r) {
            var box = $('owMessages');
            if (!box) return;
            bindBoxView();
            if (!r || !r.ok) { box.innerHTML = '<div class="ow-notice-pending">加载失败</div>'; return; }
            var d = r.data || {}, list = d.list || [];
            var isAdmin = parseInt(d.admin, 10) === 1;
            box.setAttribute('data-cr-box', isAdmin ? 'admin' : 'mine');
            if (!list.length) {
                box.innerHTML = '<div class="ow-notice-pending">'
                    + (isAdmin ? '暂无待处理的举报' : '暂无举报结果') + '</div>';
                return;
            }
            var row = noticeRow();
            var av = row ? (row.avatar || '') : '';
            var nick = row ? (row.name || '内容举报') : '内容举报';
            var h = '';
            for (var i = 0; i < list.length; i++) h += boxMsgHtml(list[i], isAdmin, av, nick);
            box.innerHTML = h;
            box.scrollTop = box.scrollHeight;
            CR.repointActive();              // 消息箱渲染完把侧栏选中框定在「内容举报」上
            // 打开过就算已读：水位上报后，刷新也不会再变回未读
            if (parseInt(d.max_id, 10) > 0) CR.markSeen(d.max_id);
        });
    };

    /** 举报人：我的某条举报的结论详情 */
    CR.openResult = function (id) {
        OwChat.openModal(
            '<h3>举报结果</h3><div class="ow-cr-box" id="owCRResult"><div class="ow-cr-empty">加载中…</div></div>',
            480
        );
        OwApi.post('plugin_content_report_result', { id: id }, function (r) {
            var box = $('owCRResult');
            if (!box) return;
            if (!r || !r.ok) {
                box.innerHTML = '<div class="ow-cr-empty">' + esc((r && r.msg) || '加载失败') + '</div>';
                return;
            }
            var dd = r.data || {};
            var tag = dd.result === 'violate' ? '<span class="ow-tag ow-tag-red">判定违规</span>'
                : (dd.result === 'clear' ? '<span class="ow-tag ow-tag-green">未违规</span>'
                : (dd.result === 'skipped' ? '<span class="ow-tag ow-tag-owner">转人工核实</span>'
                : '<span class="ow-tag ow-tag-guest">已受理</span>'));
            var h = '<div class="ow-cr-result"><p class="ow-cr-result-hd">' + tag
                + ' ' + esc(dd.text || '') + '</p>'
                + '<p class="ow-cr-sub">举报理由：' + esc(dd.reason || '—')
                + (dd.hit ? '；命中：' + esc(dd.hit) : '') + '</p>';
            if (dd.desc) h += '<p class="ow-cr-sub">你的补充说明：' + esc(dd.desc) + '</p>';
            if (dd.msg_text) h += '<p class="ow-cr-sub">被举报的内容：' + esc(dd.msg_text) + '</p>';
            h += '<p class="ow-cr-sub">被举报人：'
                + ((parseInt(dd.target_uid, 10) > 0)
                    ? nickLink(dd.target_uid, dd.target_nick)
                    : esc(dd.target_nick || '—'))
                + '</p>'
                + '<p class="ow-cr-sub">结论时间：' + esc(new Date((dd.result_at || 0) * 1000).toLocaleString()) + '</p>'
                + '</div>';
            box.innerHTML = h;
        });
    };

    /* ---------- 注册为「通知联系人」的点击处理（v1.3.160 扩展点） ----------
       侧栏那一行 = 入口：点它把主区换成插件自己的消息流（不再进系统通知那一页）。 */
    if (typeof OwChat.onNoticeContact === 'function') {
        OwChat.onNoticeContact(NOTICE_KEY, function () { CR.openBox(); });
    }

    /* 首屏就把 rail 红点对上（服务端已按身份算好未读，随 notice_contacts 下发） */
    CR.paintDot(CR.myUnread());

    /* ---------- 注册到头像右键菜单（针对「人」的操作） ---------- */
    if (typeof OwChat.onMsgCtx === 'function') {
        OwChat.onMsgCtx(function (items, m) {
            if (!m || m.recalled) return;
            var targetUid = m.uid || 0;
            if (!targetUid) return;                       // 仅举报注册用户

            var loggedIn = isLogin();
            var me = (OwChat.cfg || {}).me || null;

            // 未登录：仅当 show_guest 开启时显示入口，点击跳转登录
            if (!loggedIn) {
                if (cfgCache.show_guest) {
                    items.push({ t: '举报', run: function () { goLogin(); } });
                }
                return;
            }

            // v1.2.3：不能举报超级管理员（与服务端同一口径，这里只是把菜单项藏掉）
            if (m.role === 'admin' || isAdminUid(targetUid)) return;

            // 已登录：允许举报自己关闭时，自己的消息不显示举报
            if (me && me.id && targetUid === me.id && !cfgCache.allow_self) return;

            items.push({
                t: '举报',
                run: function () { CR.openReport(targetUid, m.nickname || '', OwChat.room, m.id || 0, m.ts || 0); }
            });
        });
    }

    /* v1.3.24：成员搜索结果里的「举报」。
       与上面同一套判定（仅注册用户、自身开关），区别只在没有消息上下文 ——
       msgId / ts 传 0，表示「未指向具体消息」（用户举报本身而非某条内容）。
       服务端 plugin_content_report_submit 对 msg_id=0 有兼容处理（不入 AI 队列）。 */
    if (typeof OwChat.onUserAction === 'function') {
        OwChat.onUserAction(function (items, u) {
            if (!u || !u.uid) return;                     // 仅举报注册用户
            var me = (OwChat.cfg || {}).me || null;
            if (me && me.id && u.uid === me.id && !cfgCache.allow_self) return;
            if (isAdminUid(u.uid)) return;                // v1.2.3：不能举报超级管理员
            items.push({
                t: '举报',
                run: function () { CR.openReport(u.uid, u.nickname || '', OwChat.room, 0, 0); }
            });
        });
    }
})(window, document);
