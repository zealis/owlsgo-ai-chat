/**
 * 时光机插件（time-machine v1.0.0）—— 前端
 *
 * 入口：rail「时光机」（data-tab=timemachine）。点开后的形态与核心「系统通知」同一套
 * 主区接管手法：停掉在飞轮询（pollGen/dmGen +1）、清 room/dm/since、顶栏换标题、
 * 输入区落闸，然后把波文时间流渲染进 #owMessages；离开时还原顶栏与标题。
 *
 * 交互全部事件委托在 #owMessages 上（主区会被反复重建，委托一次终身有效）。
 * 纯 ES5 + XHR（经 OwApi），不用 fetch / 箭头函数 / 模板字符串。
 */
(function () {
    if (!window.OwChat || !OwChat.cfg) return;      // 后台 / 登录页也会加载本脚本
    if (window.__owTmLoaded) return;                 // 幂等：防重复引入二次注册
    window.__owTmLoaded = true;

    var w = window, d = document;
    var TAB_ID = 'timemachine';

    function $(id) { return d.getElementById(id); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
        });
    }
    function jsAttr(s) { return esc(JSON.stringify(String(s == null ? '' : s))); }

    /* ---------- 插件自有线性图标（24 viewBox，stroke 跟随 currentColor；
       与核心 ow_icon 同一风格语言，注册表里没有的形状才在这里内联） ---------- */
    function ico(name, size) {
        size = size || 16;
        var body = '';
        if (name === 'heart') {
            body = '<path d="M12 20.2C7.2 16.5 3.8 13.2 3.8 9.6 3.8 7.2 5.7 5.4 8 5.4c1.6 0 3 .9 4 2.3 1-1.4 2.4-2.3 4-2.3 2.3 0 4.2 1.8 4.2 4.2 0 3.6-3.4 6.9-8.2 10.6z"/>';
        } else if (name === 'heart-fill') {
            body = '<path fill="currentColor" stroke="none" d="M12 20.2C7.2 16.5 3.8 13.2 3.8 9.6 3.8 7.2 5.7 5.4 8 5.4c1.6 0 3 .9 4 2.3 1-1.4 2.4-2.3 4-2.3 2.3 0 4.2 1.8 4.2 4.2 0 3.6-3.4 6.9-8.2 10.6z"/>';
        } else if (name === 'bubble') {
            body = '<path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v8a2.5 2.5 0 0 1-2.5 2.5H9l-5 4z"/>';
        } else if (name === 'eye') {
            body = '<path d="M2.5 12S6 5.8 12 5.8 21.5 12 21.5 12 18 18.2 12 18.2 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="2.8"/>';
        } else if (name === 'bookmark') {
            body = '<path d="M7 3.5h10A1.5 1.5 0 0 1 18.5 5v15.5L12 16l-6.5 4.5V5A1.5 1.5 0 0 1 7 3.5z"/>';
        } else if (name === 'copy') {
            body = '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M15 6V4.5A1.5 1.5 0 0 0 13.5 3h-9A1.5 1.5 0 0 0 3 4.5v9A1.5 1.5 0 0 0 4.5 15H6"/>';
        } else if (name === 'trash') {
            body = '<path d="M4 6.5h16"/><path d="M9 6.5v-2a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/><path d="M6.5 6.5l.9 12.6A1.5 1.5 0 0 0 8.9 20.5h6.2a1.5 1.5 0 0 0 1.5-1.4l.9-12.6"/>';
        } else if (name === 'close') {
            body = '<path d="M6 6l12 12M18 6L6 18"/>';
        } else if (name === 'image') {
            body = '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><circle cx="9" cy="10" r="1.8"/><path d="M4 17l4.5-4.5 3.5 3.5 3-3 5 5"/>';
        } else {   // send
            body = '<path d="M3.5 12L21 4l-7.5 17-2.5-7z"/>';
        }
        return '<svg class="ow-ico" width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"'
            + ' stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            + body + '</svg>';
    }

    function avatarCell(url, name, cls) {
        var src = url || OwAvatar.identicon(name || 'owl');
        return '<span class="ow-avatar ' + (cls || '') + '"><img src="' + esc(src) + '" alt="" loading="lazy"'
            + ' onerror="OwAvatar.fallback(this)"></span>';
    }

    function fmtTime(ts) {
        if (!ts) return '';
        var dt = new Date(ts * 1000), now = new Date();
        function p(n) { return (n < 10 ? '0' : '') + n; }
        var hm = p(dt.getHours()) + ':' + p(dt.getMinutes());
        if (dt.getFullYear() === now.getFullYear() && dt.getMonth() === now.getMonth() && dt.getDate() === now.getDate()) return hm;
        if (dt.getFullYear() === now.getFullYear()) return (dt.getMonth() + 1) + '月' + dt.getDate() + '日 ' + hm;
        return dt.getFullYear() + '年' + (dt.getMonth() + 1) + '月' + dt.getDate() + '日';
    }

    function nl2br(s) { return esc(s).replace(/\r?\n/g, '<br>'); }

    /* ===================================================================== */

    var OwTM = {
        active: false,
        tab: 'new',
        page: 1,
        hasMore: false,
        canPost: false,
        isAdmin: false,
        me: 0,
        busy: false,
        _titleBefore: '',

        /* ---------- rail 入口 ---------- */
        onShow: function (panel) {
            // 面板可能被重复调用：内容重建是幂等的
            panel.innerHTML = '<div class="ow-tm-side">'
                + '<div class="ow-tm-side-h">时光机</div>'
                + '<p class="ow-tm-side-d">把此刻的想法与生活写成「波文」，会出现在大家的时间流里。</p>'
                + '<button class="ow-btn ow-btn-primary ow-btn-block" type="button" onclick="OwTM.openComposer()">发波文</button>'
                + '<div class="ow-tm-side-stats" id="owTmStats"></div>'
                + '</div>';
            this.enter();
        },

        /* ---------- 主区接管 / 还原（照核心 openNotices 的做法） ---------- */
        enter: function () {
            var self = this;
            if (!this.active) {
                this._titleBefore = ($('owRoomName') || {}).textContent || '';
                this.active = true;
            }
            // 停掉在飞的群聊 / 私聊长轮询（与 blankChatForFriends 同一手法）
            OwChat.pollGen = (OwChat.pollGen || 0) + 1;
            OwChat.dmGen = (OwChat.dmGen || 0) + 1;
            OwChat._roomPollRunning = false;
            OwChat.dm = null; OwChat.room = 0; OwChat.since = 0;
            OwChat.historyDone = true; OwChat.loadingHistory = false;
            if (w.ChatList) ChatList.activate('owRoomList', '');
            // 顶栏：标题换成时光机，收起群聊信息按钮与延迟显示（它们都属于房间）
            var tp = $('owTogglePanel'); if (tp) tp.style.display = 'none';
            var lt = $('owLatency'); if (lt) lt.style.display = 'none';
            var nm = $('owRoomName'); if (nm) nm.textContent = '时光机';
            OwChat.applyInputGate('notice');
            var input = $('owInput');
            if (input) input.placeholder = '浏览波文时不能聊天，回「消息」继续聊';
            OwChat._fireViewChange();

            var box = $('owMessages');
            this.tab = 'new';
            this.page = 1;
            if (box) {
                box.innerHTML = this.skeleton();
                this.bindOnce(box);
            }
            this.load(true);
        },

        leave: function () {
            if (!this.active) return;
            this.active = false;
            var tp = $('owTogglePanel'); if (tp) tp.style.display = '';
            var lt = $('owLatency'); if (lt) lt.style.display = '';
            var nm = $('owRoomName');
            if (nm && this._titleBefore != null) nm.textContent = this._titleBefore;
            var box = $('owMessages');
            if (box) box.innerHTML = '';   // 与 blankChatForFriends 同口径：不留残影
        },

        skeleton: function () {
            return '<div class="ow-tm"><div class="ow-tm-head">'
                + '<div class="ow-tm-tabs">' + this.tabBtns() + '</div>'
                // 按钮直接复用核心 .ow-btn / .ow-btn-primary / .ow-btn-mini，不自造一套尺寸
                + '<button class="ow-btn ow-btn-primary ow-btn-mini" type="button" data-tm-act="compose">发波文</button>'
                + '</div><div class="ow-tm-list" id="owTmList">'
                + '<div class="ow-tm-empty">加载中…</div></div>'
                + '<div class="ow-tm-foot" id="owTmFoot"></div></div>';
        },

        tabBtns: function () {
            var names = [['new', '最新'], ['hot', '热门'], ['mine', '我的'], ['fav', '收藏']], h = '', i;
            for (i = 0; i < names.length; i++) {
                h += '<button type="button" class="ow-tm-tab' + (names[i][0] === this.tab ? ' is-on' : '')
                    + '" data-tm-act="tab" data-tab="' + names[i][0] + '">' + names[i][1] + '</button>';
            }
            return h;
        },

        /* ---------- 数据 ---------- */
        load: function (reset) {
            var self = this;
            if (this.busy) return;
            this.busy = true;
            var list = $('owTmList'), foot = $('owTmFoot');
            if (reset) this.page = 1;
            if (list && reset) list.innerHTML = '<div class="ow-tm-empty">加载中…</div>';
            if (foot) foot.innerHTML = '';
            OwApi.post('plugin_time_machine_feed', { tab: this.tab, page: this.page }, function (r) {
                self.busy = false;
                if (!r || !r.ok) {
                    if (list) list.innerHTML = '<div class="ow-tm-empty">' + esc((r && r.msg) || '加载失败，稍后再试') + '</div>';
                    return;
                }
                self.canPost = !!r.can_post;
                self.isAdmin = !!r.is_admin;
                self.me = r.me | 0;
                self.hasMore = !!r.has_more;
                if (r.stats) self.paintStats(r.stats);
                self.paint(list, r.posts || [], reset);
                if (foot) {
                    foot.innerHTML = self.hasMore
                        ? '<button class="ow-btn ow-btn-ghost ow-btn-mini" type="button" data-tm-act="more">加载更多</button>' : '';
                }
            });
        },

        paintStats: function (st) {
            var el = $('owTmStats');
            if (el) el.innerHTML = '我的波文 <b>' + (st.posts | 0) + '</b> 条 · 收到点赞 <b>' + (st.likes | 0) + '</b> 个';
        },

        paint: function (list, posts, reset) {
            if (!list) return;
            var h = '', i;
            if (!posts.length) {
                var tip = this.tab === 'fav' ? '还没有收藏的波文'
                    : this.tab === 'mine' ? '你还没发过波文'
                    : '还没有波文，点右上角「发波文」开个头';
                if (!this.canPost && this.tab === 'new') tip = '还没有波文';
                list.innerHTML = '<div class="ow-tm-empty">' + tip + '</div>';
                return;
            }
            if (!reset) {
                var empty = list.getElementsByClassName('ow-tm-empty')[0];
                if (empty) empty.parentNode.removeChild(empty);
            } else {
                list.innerHTML = '';
            }
            for (i = 0; i < posts.length; i++) list.insertAdjacentHTML('beforeend', this.card(posts[i]));
        },

        card: function (p) {
            var mine = p.uid === this.me;
            var canDel = mine || this.isAdmin;
            var imgs = p.images || [], ih = '', g = imgs.length >= 5 ? ' g3' : (imgs.length >= 2 ? ' g2' : ' g1'), k;
            for (k = 0; k < imgs.length; k++) {
                ih += '<img class="ow-tm-img" src="' + esc(imgs[k]) + '" alt="配图"'
                    + ' onclick="OwChat.viewImg(this.src)" loading="lazy">';
            }
            var h = '<article class="ow-tm-card" data-pid="' + p.id + '">'
                + '<div class="ow-tm-card-av">' + avatarCell(p.avatar, p.nickname, 'ow-avatar-lg') + '</div>'
                + '<div class="ow-tm-card-main">'
                + '<div class="ow-tm-card-top">'
                + '<span class="ow-tm-nick" onclick="OwChat.userCard(' + p.uid + ',' + jsAttr(p.nickname) + ')">' + esc(p.nickname) + '</span>'
                + '<span class="ow-tm-time">' + esc(fmtTime(p.created_at)) + '</span>'
                + (canDel ? '<button class="ow-tm-del" type="button" title="删除" aria-label="删除" data-tm-act="del">'
                    + ico('trash', 15) + '</button>' : '')
                + '</div>'
                + (p.content ? '<div class="ow-tm-text">' + nl2br(p.content) + '</div>' : '')
                + (ih ? '<div class="ow-tm-imgs' + g + '">' + ih + '</div>' : '')
                + '<div class="ow-tm-acts">'
                + '<button type="button" class="ow-tm-act' + (p.liked ? ' is-on' : '') + '" data-tm-act="like" title="点赞">'
                + ico(p.liked ? 'heart-fill' : 'heart', 16) + '<i>' + (p.likes | 0) + '</i></button>'
                + '<button type="button" class="ow-tm-act" data-tm-act="cmt" title="评论">'
                + ico('bubble', 16) + '<i>' + (p.comments | 0) + '</i></button>'
                + '<span class="ow-tm-act ow-tm-plain" title="浏览">' + ico('eye', 16) + '<i>' + (p.views | 0) + '</i></span>'
                + '<button type="button" class="ow-tm-act' + (p.faved ? ' is-on' : '') + '" data-tm-act="fav" title="收藏">'
                + ico('bookmark', 16) + '<i>' + (p.faved ? '已收藏' : '收藏') + '</i></button>'
                + '<button type="button" class="ow-tm-act" data-tm-act="copy" title="复制">'
                + ico('copy', 16) + '<i>复制</i></button>'
                + '</div>'
                + '<div class="ow-tm-cmts" style="display:none"></div>'
                + '</div></article>';
            return h;
        },

        /* ---------- 事件委托（绑一次，重建不丢） ---------- */
        bindOnce: function (box) {
            if (box._owTmBound) return;
            box._owTmBound = true;
            var self = this;
            box.addEventListener('click', function (e) {
                var t = e.target || e.srcElement;
                var img = null;
                while (t && t !== box) {
                    if (t.getAttribute && t.getAttribute('data-tm-act')) break;
                    if (!img && t.tagName === 'IMG' && t.className.indexOf('ow-tm-img') >= 0) img = t;
                    t = t.parentNode;
                }
                if (!t || t === box) return;
                var act = t.getAttribute('data-tm-act');
                var card = t; while (card && card !== box && !(' ' + card.className + ' ').match(/ ow-tm-card /)) card = card.parentNode;
                var pid = card && card !== box ? card.getAttribute('data-pid') : 0;
                if (act === 'tab') { self.switchTabBtn(t.getAttribute('data-tab')); return; }
                if (act === 'more') { self.page += 1; self.load(false); return; }
                if (act === 'compose') { self.openComposer(); return; }
                if (!pid) return;
                if (act === 'like') self.toggle('like', pid, card);
                else if (act === 'fav') self.toggle('fav', pid, card);
                else if (act === 'cmt') self.toggleComments(card, pid);
                else if (act === 'copy') self.copyCard(card, pid);
                else if (act === 'del') self.confirmDel(card, pid);
                else if (act === 'cmtsend') self.addComment(card, pid);
            });
            box.addEventListener('keydown', function (e) {
                e = e || w.event;
                var t = e.target || e.srcElement;
                if (!t || t.className.indexOf('ow-tm-cmt-in') < 0) return;
                if ((e.keyCode || e.which) !== 13) return;
                var card = t; while (card && card !== box && !(' ' + card.className + ' ').match(/ ow-tm-card /)) card = card.parentNode;
                if (card && card !== box) {
                    if (e.preventDefault) e.preventDefault();
                    self.addComment(card, card.getAttribute('data-pid'));
                }
            });
        },

        switchTabBtn: function (tab) {
            if (!tab || tab === this.tab) return;
            this.tab = tab;
            var box = $('owMessages');
            if (box) {
                var head = box.getElementsByClassName('ow-tm-head')[0];
                if (head) head.getElementsByClassName('ow-tm-tabs')[0].innerHTML = this.tabBtns();
            }
            this.load(true);
        },

        findCard: function (pid) {
            var box = $('owMessages');
            if (!box) return null;
            var cards = box.getElementsByClassName('ow-tm-card'), i;
            for (i = 0; i < cards.length; i++) {
                if (cards[i].getAttribute('data-pid') == pid) return cards[i];
            }
            return null;
        },

        /* ---------- 点赞 / 收藏 ---------- */
        toggle: function (type, pid, card) {
            if (!this.canPost) { toast('请先登录后再互动'); return; }
            var self = this;
            var btn = card.querySelector('[data-tm-act="' + type + '"]');
            if (btn) btn.disabled = true;
            OwApi.post('plugin_time_machine_' + type, { id: pid }, function (r) {
                if (btn) btn.disabled = false;
                if (!r || !r.ok) { toast((r && r.msg) || '操作失败'); return; }
                if (type === 'like') {
                    if (btn) {
                        var on = !!r.on;
                        btn.className = 'ow-tm-act' + (on ? ' is-on' : '');
                        btn.innerHTML = ico(on ? 'heart-fill' : 'heart', 16) + '<i>' + (r.likes | 0) + '</i>';
                    }
                } else {
                    var onf = !!r.on;
                    if (btn) {
                        btn.className = 'ow-tm-act' + (onf ? ' is-on' : '');
                        btn.innerHTML = ico('bookmark', 16) + '<i>' + (onf ? '已收藏' : '收藏') + '</i>';
                    }
                }
            });
        },

        /* ---------- 评论 ---------- */
        toggleComments: function (card, pid) {
            var box = card.getElementsByClassName('ow-tm-cmts')[0];
            if (!box) return;
            if (box.style.display !== 'none') { box.style.display = 'none'; return; }
            box.style.display = '';
            if (box.getAttribute('data-loaded') === '1') return;
            box.innerHTML = '<div class="ow-tm-cmt-load">加载评论…</div>';
            var self = this;
            OwApi.post('plugin_time_machine_comments', { post_id: pid }, function (r) {
                if (!r || !r.ok) { box.innerHTML = '<div class="ow-tm-cmt-load">' + esc((r && r.msg) || '加载失败') + '</div>'; return; }
                box.setAttribute('data-loaded', '1');
                var h = '<div class="ow-tm-cmt-list">';
                var cs = r.comments || [], i;
                if (!cs.length) h += '<div class="ow-tm-cmt-load">还没有评论，抢个沙发</div>';
                for (i = 0; i < cs.length; i++) h += self.cmtRow(cs[i]);
                h += '</div>';
                if (self.canPost) {
                    h += '<div class="ow-tm-cmt-row">'
                        + '<input type="text" class="ow-input ow-tm-cmt-in" maxlength="500" placeholder="写评论，Enter 发送">'
                        + '<button class="ow-btn ow-btn-primary ow-btn-mini" type="button" data-tm-act="cmtsend">发送</button>'
                        + '</div>';
                }
                box.innerHTML = h;
            });
        },

        cmtRow: function (c) {
            return '<div class="ow-tm-cmt">'
                + avatarCell(c.avatar, c.nickname, 'ow-avatar-xs')
                + '<div class="ow-tm-cmt-main">'
                + '<div class="ow-tm-cmt-meta"><span class="ow-tm-nick ow-tm-nick-s" onclick="OwChat.userCard(' + c.uid + ',' + jsAttr(c.nickname) + ')">' + esc(c.nickname) + '</span>'
                + '<span class="ow-tm-time">' + esc(fmtTime(c.created_at)) + '</span></div>'
                + '<div class="ow-tm-cmt-text">' + nl2br(c.content) + '</div>'
                + '</div></div>';
        },

        addComment: function (card, pid) {
            var input = card.getElementsByClassName('ow-tm-cmt-in')[0];
            if (!input) return;
            var text = input.value.replace(/^\s+|\s+$/g, '');
            if (!text) { toast('说点什么吧'); return; }
            var btn = card.querySelector('[data-tm-act="cmtsend"]');
            if (btn) btn.disabled = true;
            var self = this;
            OwApi.post('plugin_time_machine_comment_add', { post_id: pid, content: text }, function (r) {
                if (btn) btn.disabled = false;
                if (!r || !r.ok) { toast((r && r.msg) || '评论失败'); return; }
                input.value = '';
                var list = card.getElementsByClassName('ow-tm-cmt-list')[0];
                var box = card.getElementsByClassName('ow-tm-cmts')[0];
                if (list) {
                    var load = list.getElementsByClassName('ow-tm-cmt-load')[0];
                    if (load) load.parentNode.removeChild(load);
                    list.insertAdjacentHTML('beforeend', self.cmtRow(r.comment));
                    box.setAttribute('data-loaded', '1');
                }
                var cnt = card.querySelector('[data-tm-act="cmt"] i');
                if (cnt) cnt.textContent = r.comments | 0;
            });
        },

        /* ---------- 复制 ---------- */
        copyCard: function (card, pid) {
            var textEl = card.getElementsByClassName('ow-tm-text')[0];
            var text = textEl ? textEl.innerText || textEl.textContent : '';
            text = String(text || '').replace(/^\s+|\s+$/g, '');
            if (!text) { toast('这条波文没有文字可复制'); return; }
            var done = function () { toast('已复制到剪贴板'); };
            try {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(done, function () { OwTM.copyFallback(text, done); });
                    return;
                }
            } catch (e) {}
            this.copyFallback(text, done);
        },
        copyFallback: function (text, done) {
            var ta = d.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed'; ta.style.opacity = '0';
            d.body.appendChild(ta);
            ta.select();
            try { d.execCommand('copy'); done(); } catch (e) { toast('复制失败，请手动选择文字'); }
            d.body.removeChild(ta);
        },

        /* ---------- 删除（敏感操作：票据 + 二次确认） ---------- */
        confirmDel: function (card, pid) {
            OwChat.openModal('<h3>删除波文</h3><p class="ow-modal-desc">删除后评论、点赞与收藏一并清除，不可恢复。确定删除吗？</p>'
                + '<div class="ow-modal-actions">'
                + '<button class="ow-btn" type="button" onclick="OwChat.closeModal()">取消</button>'
                + '<button class="ow-btn ow-btn-danger" type="button" id="owTmDelGo">删除</button>'
                + '</div>', 360);
            var go = $('owTmDelGo');
            if (go) go.onclick = function () {
                go.disabled = true;
                OwApi.secure('plugin_time_machine_delete', { id: pid }, function (r) {
                    if (r && r.ok) {
                        OwChat.closeModal();
                        var el = OwTM.findCard(pid);
                        if (el && el.parentNode) el.parentNode.removeChild(el);
                        toast('已删除');
                    } else {
                        go.disabled = false;
                        toast((r && r.msg) || '删除失败');
                    }
                });
            };
        },

        /* ---------- 发波文（弹窗编辑器） ---------- */
        composerImgs: [],
        composerBusy: false,

        openComposer: function () {
            if (!this.canPost) { toast('请先登录后再发波文'); return; }
            this.composerImgs = [];
            this.composerBusy = false;
            var h = '<h3>发波文</h3>'
                + '<div class="ow-tm-cmp">'
                + '<textarea class="ow-input ow-tm-ta" id="owTmTa" maxlength="2000" rows="5" placeholder="这一刻的想法…"></textarea>'
                + '<div class="ow-tm-cmp-meta"><span id="owTmCnt">0 / 2000</span></div>'
                + '<div class="ow-tm-cmp-imgs" id="owTmImgs"></div>'
                + '<div class="ow-tm-cmp-tip" id="owTmTip">配图最多 9 张，支持 jpg / png / gif / webp。</div>'
                + '<div class="ow-modal-actions">'
                + '<button class="ow-btn" type="button" onclick="OwChat.closeModal()">取消</button>'
                + '<button class="ow-btn ow-btn-primary" type="button" id="owTmGo">发布</button>'
                + '</div></div>';
            OwChat.openModal(h, 520);
            // 上一次弹窗（取消/提交）留下的隐藏选图控件：先清掉再建新的，避免 body 上越积越多
            if (this._fileInput) { try { d.body.removeChild(this._fileInput); } catch (e) {} this._fileInput = null; }
            var ta = $('owTmTa'), self = this;
            if (ta) {
                ta.oninput = function () {
                    var c = $('owTmCnt');
                    if (c) c.textContent = ta.value.length + ' / 2000';
                };
                ta.focus();
            }
            var add = d.createElement('input');
            add.type = 'file';
            add.accept = 'image/*';
            add.multiple = true;
            add.style.display = 'none';
            add.id = 'owTmFile';
            d.body.appendChild(add);
            add.onchange = function () {
                var files = add.files || [], queue = [], i;
                for (i = 0; i < files.length; i++) {
                    if (self.composerImgs.length + queue.length >= 9) break;
                    queue.push(files[i]);
                }
                add.value = '';
                if (!queue.length) { toast('配图最多 9 张'); return; }
                self.uploadQueue(queue);
            };
            this._fileInput = add;
            var go = $('owTmGo');
            if (go) go.onclick = function () { self.submit(); };
            this.renderImgs();
        },

        renderImgs: function () {
            var box = $('owTmImgs');
            if (!box) return;
            var h = '', i, self = this;
            for (i = 0; i < this.composerImgs.length; i++) {
                h += '<span class="ow-tm-thumb"><img src="' + esc(this.composerImgs[i]) + '" alt="">'
                    + '<button type="button" class="ow-thumb-x" data-i="' + i + '" title="移除" aria-label="移除">' + ico('close', 12) + '</button></span>';
            }
            if (this.composerImgs.length < 9) {
                h += '<button type="button" class="ow-tm-addimg" id="owTmAddImg" title="添加配图" aria-label="添加配图">' + ico('image', 20) + '</button>';
            }
            box.innerHTML = h;
            var addBtn = $('owTmAddImg');
            if (addBtn) addBtn.onclick = function () { if (self._fileInput) self._fileInput.click(); };
            var xs = box.getElementsByClassName('ow-thumb-x'), k;
            for (k = 0; k < xs.length; k++) {
                xs[k].onclick = (function (n) {
                    return function () { self.composerImgs.splice(n, 1); self.renderImgs(); };
                })(parseInt(xs[k].getAttribute('data-i'), 10));
            }
            this.syncTip();
        },

        uploadQueue: function (queue) {
            var self = this;
            this.composerBusy = true;
            (function next() {
                if (!queue.length) { self.composerBusy = false; self.syncTip(); return; }
                self.syncTip('正在上传配图…（' + self.composerImgs.length + '/9）');
                var f = queue.shift();
                OwApi.upload('plugin_time_machine_upload', f, {}, function (r) {
                    if (r && r.ok && r.url) {
                        if (self.composerImgs.length < 9) { self.composerImgs.push(r.url); self.renderImgs(); }
                    } else {
                        toast((r && r.msg) || '配图上传失败');
                    }
                    next();
                });
            })();
        },

        syncTip: function (msg) {
            var tip = $('owTmTip');
            if (tip) tip.textContent = msg || ('配图 ' + this.composerImgs.length + '/9，支持 jpg / png / gif / webp。');
        },

        submit: function () {
            var ta = $('owTmTa');
            var text = ta ? ta.value.replace(/^\s+|\s+$/g, '') : '';
            if (!text && !this.composerImgs.length) { toast('写点什么，或者配张图吧'); return; }
            if (this.composerBusy) { toast('配图还在上传，请稍候'); return; }
            var go = $('owTmGo');
            if (go) go.disabled = true;
            var self = this;
            OwApi.post('plugin_time_machine_post', { content: text, images: JSON.stringify(this.composerImgs) }, function (r) {
                if (go) go.disabled = false;
                if (!r || !r.ok) { toast((r && r.msg) || '发布失败'); return; }
                OwChat.closeModal();
                if (self._fileInput) { try { d.body.removeChild(self._fileInput); } catch (e) {} self._fileInput = null; }
                toast('波文已发布');
                if (self.tab !== 'new') self.switchTabBtn('new');
                else { self.page = 1; self.load(true); }
            });
        }
    };

    w.OwTM = OwTM;

    /* 注册 rail 面板 */
    OwChat.onRail({
        id: TAB_ID,
        label: '时光机',
        onShow: function (panel) { OwTM.onShow(panel); }
    });

    /* ---------- 离开检测 ----------
       核心切标签只走 switchTab（通知红点等旁路走 openNotices），
       包一层把「离开时光机」的还原动作挂上去；原方法照常执行。 */
    (function () {
        var st = OwChat.switchTab;
        OwChat.switchTab = function (tab) {
            if (OwTM.active && tab !== TAB_ID) { try { OwTM.leave(); } catch (e) {} }
            return st.apply(OwChat, arguments);
        };
        var on = OwChat.openNotices;
        OwChat.openNotices = function () {
            if (OwTM.active) { try { OwTM.leave(); } catch (e) {} }
            return on.apply(OwChat, arguments);
        };
    })();
})();
