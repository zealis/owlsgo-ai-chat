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
        } else if (name === 'file') {
            body = '<path d="M13 3.5H7a1.5 1.5 0 0 0-1.5 1.5v14A1.5 1.5 0 0 0 7 20.5h10a1.5 1.5 0 0 0 1.5-1.5V9z"/><path d="M13 3.5V9h5.5"/>';
        } else if (name === 'download') {
            body = '<path d="M12 4v11"/><path d="M7.5 11L12 15.5 16.5 11"/><path d="M4.5 19.5h15"/>';
        } else if (name === 'smile') {
            body = '<circle cx="12" cy="12" r="9"/><path d="M8.5 14.5c1 1.5 2.2 2.2 3.5 2.2s2.5-.7 3.5-2.2"/><line x1="9" y1="9.5" x2="9" y2="10.5"/><line x1="15" y1="9.5" x2="15" y2="10.5"/>';
        } else if (name === 'more') {   // 三个点（横向）：卡片右上角的下拉菜单入口
            body = '<circle cx="5" cy="12" r="1.6" fill="currentColor" stroke="none"/>'
                + '<circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/>'
                + '<circle cx="19" cy="12" r="1.6" fill="currentColor" stroke="none"/>';
        } else if (name === 'resize') {   // 拖拽手柄：两条斜线（与核心 ow_icon('resize') 同形）
            body = '<path d="M5 13l7-7"/><path d="M10 15l7-7"/>';
        } else if (name === 'paperclip') {
            body = '<path d="M16.5 7.5l-7 7a3.5 3.5 0 0 0 5 5l7-7a5.5 5.5 0 0 0-8-8L6 12a7.5 7.5 0 0 0 11 11"/>';
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

    /**
     * 可点击的头像（与消息区 .ow-msg-av 同一待遇：手型 + 圆形裁切 + hover 微淡），
     * 点开资料卡。昵称本来就可点，头像没有道理不行。
     */
    function avatarBtn(uid, name, url, cls) {
        return '<span class="ow-tm-ava" title="查看资料" onclick="OwChat.userCard(' + (uid | 0) + ','
            + jsAttr(name) + ')">' + avatarCell(url, name, cls) + '</span>';
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

    /** 体积文案（与核心消息里的文件卡同一口径：B / KB / MB） */
    function sizeText(n) {
        n = parseInt(n, 10) || 0;
        if (n < 1024) return n + ' B';
        if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
        return (n / 1048576).toFixed(1) + ' MB';
    }

    /** 图片扩展名集合（附件插件的图片路由会压缩，走它；其余走文件路由） */
    var IMG_EXT = { jpg: 1, jpeg: 1, png: 1, gif: 1, webp: 1, bmp: 1 };
    function extOf(name) {
        var m = /\.[a-z0-9]+$/i.exec(String(name || ''));
        return m ? m[0].slice(1).toLowerCase() : '';
    }

    /* ===================================================================== */

    var OwTM = {
        active: false,
        tab: 'new',
        page: 1,
        hasMore: false,
        canPost: false,
        isAdmin: false,
        me: 0,
        meProfile: null,
        posts: {},          // pid → 波文数据（点赞/评论后局部更新用，不重画整张卡）
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
            return '<div class="ow-tm"><div class="ow-list-head">'
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
                self.meProfile = r.me_profile || null;
                self.hasMore = !!r.has_more;
                // 附件能力由服务端判定（依赖「附件上传」插件是否安装并启用）
                self.attach = r.attach || { on: false, hint: '', accept: '', max_mb: 0, image_exts: [] };
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
            for (i = 0; i < posts.length; i++) {
                this.posts[posts[i].id] = posts[i];          // 缓存：点赞/评论后局部更新
                list.insertAdjacentHTML('beforeend', this.card(posts[i]));
            }
        },

        card: function (p) {
            var mine = p.uid === this.me;
            var canDel = mine || this.isAdmin;
            var media = p.media || [];
            var imgs = [], files = [], k;
            for (k = 0; k < media.length; k++) {
                if (media[k].k === 'file') files.push(media[k]); else imgs.push(media[k]);
            }
            var ih = '', g = imgs.length >= 5 ? ' g3' : (imgs.length >= 2 ? ' g2' : ' g1');
            for (k = 0; k < imgs.length; k++) {
                ih += '<img class="ow-tm-img" src="' + esc(imgs[k].url) + '" alt="配图"'
                    + ' onclick="OwChat.viewImg(this.src)" loading="lazy">';
            }
            // 文件附件：复用核心消息里文件卡的类名（.ow-file-card / .ow-file-ico / .ow-file-name / .ow-file-size / .ow-file-dl）
            var fh = '';
            for (k = 0; k < files.length; k++) {
                fh += this.fileRow({ post: p.id }, media.indexOf(files[k]), files[k]);
            }
            // 可见性徽标：公开不占地方（默认就是公开），好友/私密各给一枚小标签
            var visTag = '';
            if (p.visibility === 'friends') visTag = '<span class="ow-tm-vis" data-vis="friends">好友可见</span>';
            else if (p.visibility === 'private') visTag = '<span class="ow-tm-vis" data-vis="private">私密</span>';
            /* 右上角「···」：收藏 / 复制 / 删除收进下拉菜单（复用核心 .ow-ctx-menu 外壳）。
               删除只在「本人或管理员」时出现在菜单里。 */
            var h = '<article class="ow-tm-card" data-pid="' + p.id + '">'
                + '<div class="ow-tm-card-av">' + avatarBtn(p.uid, p.nickname, p.avatar, 'ow-avatar-lg') + '</div>'
                + '<div class="ow-tm-card-main">'
                + '<div class="ow-tm-card-top">'
                + '<span class="ow-tm-nick" onclick="OwChat.userCard(' + p.uid + ',' + jsAttr(p.nickname) + ')">' + esc(p.nickname) + '</span>'
                + visTag
                + '<button class="ow-tm-more" type="button" title="更多" aria-label="更多" data-tm-act="menu">'
                + ico('more', 16) + '</button>'
                + '</div>'
                + (p.content ? '<div class="ow-tm-text">' + nl2br(p.content) + '</div>' : '')
                + (ih ? '<div class="ow-tm-imgs' + g + '">' + ih + '</div>' : '')
                + (fh ? '<div class="ow-tm-files">' + fh + '</div>' : '')
                + this.likersRow(p)
                // 动作条：左边时间、右边点赞 / 评论 / 阅读量（时间从顶部挪下来，与互动信息同一行）
                + '<div class="ow-tm-acts">'
                + '<span class="ow-tm-time ow-tm-time-act">' + esc(fmtTime(p.created_at)) + '</span>'
                + '<span class="ow-tm-actbtns">'
                + '<button type="button" class="ow-tm-act' + (p.liked ? ' is-on' : '') + '" data-tm-act="like" title="点赞">'
                + ico(p.liked ? 'heart-fill' : 'heart', 16) + '<i>' + (p.likes | 0) + '</i></button>'
                + '<button type="button" class="ow-tm-act" data-tm-act="cmt" title="评论">'
                + ico('bubble', 16) + '<i>' + (p.comment_cnt | 0) + '</i></button>'
                + '<span class="ow-tm-act ow-tm-plain" title="浏览">' + ico('eye', 16) + '<i>' + (p.views | 0) + '</i></span>'
                + '</span>'
                + '</div>'
                + this.cmtsBox(p)
                + '</div></article>';
            return h;
        },

        /** 内容下方的点赞用户行：心形 + 头像叠放（最多 5 个）+ 昵称（最多 3 个 + 等 N 人） */
        likersRow: function (p) {
            var ls = p.likers || [];
            if (!ls.length) return '';
            var av = '', names = '', k, n;
            for (k = 0; k < ls.length && k < 5; k++) {
                av += avatarBtn(ls[k].uid, ls[k].nickname, ls[k].avatar, 'ow-avatar-xs');
            }
            for (k = 0; k < ls.length && k < 3; k++) {
                names += (k ? '、' : '')
                    + '<span class="ow-tm-like-n" onclick="OwChat.userCard(' + ls[k].uid + ',' + jsAttr(ls[k].nickname) + ')">'
                    + esc(ls[k].nickname) + '</span>';
            }
            n = ls.length;
            if (n > 3) names += ' 等 ' + n + ' 人';
            else if (n > 1) names += ' 觉得很赞';
            return '<div class="ow-tm-likers">'
                // 行首心形：与点赞按钮同一颗（实心 + 主色），一眼看出这一行是「谁点了赞」
                + '<span class="ow-tm-like-heart">' + ico('heart-fill', 14) + '</span>'
                + '<span class="ow-tm-like-avs">' + av + '</span>'
                + '<span class="ow-tm-like-names">' + names + '</span></div>';
        },

        /** 评论区：默认展开（不需要点开）。已登录给输入行（含表情 / 附件），游客给一句提示 */
        cmtsBox: function (p) {
            var cs = p.comments || [], i, h = '';
            for (i = 0; i < cs.length; i++) h += this.cmtRow(cs[i]);
            h = '<div class="ow-tm-cmt-list">' + (h || '<div class="ow-tm-cmt-load">还没有评论，抢个沙发</div>') + '</div>';
            // 评论后追加本地行时也走这里：缓存里补一条，保持「等 N 人」这类汇总口径一致
            if (this.posts[p.id]) this.posts[p.id].comments = p.comments || cs;
            if (this.canPost) {
                // 发送按钮与消息输入区那只**完全一致**：核心 ow-btn-primary + ow-send-round
                // 圆形图标钮（纸飞机 18px / 40×40），不自造文字小按钮
                // 表情与附件按钮同编辑器那两只（.ow-tm-cmp-btn 同档），表情面板 / 待发附件按需展开
                h += '<div class="ow-tm-cmt-row">'
                    + '<input type="text" class="ow-input ow-tm-cmt-in" maxlength="500" placeholder="写评论，Enter 发送">'
                    + '<button type="button" class="ow-tm-cmp-btn" data-tm-act="cmtemo" title="表情" aria-label="表情">'
                    + ico('smile', 18) + '</button>'
                    + '<button type="button" class="ow-tm-cmp-btn' + ((this.attach || {}).on ? '' : ' is-off')
                    + '" data-tm-act="cmtatt" title="图片或文件" aria-label="图片或文件">' + ico('paperclip', 18) + '</button>'
                    + '<button class="ow-btn ow-btn-primary ow-send ow-send-round" type="button" data-tm-act="cmtsend"'
                    + ' aria-label="发送" title="发送">' + ico('send', 18) + '</button>'
                    + '</div>'
                    + '<div class="ow-tm-emoji ow-tm-cmt-emoji" id="owTmCmtEmo-' + p.id + '" style="display:none"></div>'
                    + '<div class="ow-tm-cmt-media" id="owTmCmtMd-' + p.id + '"></div>';
            } else {
                h += '<div class="ow-tm-cmt-load">登录后可评论</div>';
            }
            return '<div class="ow-tm-cmts" data-loaded="1">' + h + '</div>';
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
                else if (act === 'cmt') self.focusComment(card);
                else if (act === 'menu') self.openMenu(card, pid);
                else if (act === 'cmtsend') self.addComment(card, pid);
                else if (act === 'cmtemo') self.toggleCmtEmoji(card);
                else if (act === 'cmtatt') self.pickCmtFile(pid);
                else if (act === 'cmtrm') self.rmCmtMedia(pid, t.getAttribute('data-i'));
            });
            // 点菜单外任意处 / Esc → 收起下拉菜单（菜单是 body 级浮层）
            d.addEventListener('click', function (e) {
                var t = e.target || e.srcElement;
                var menu = $('owTmMenu');
                if (!menu || menu.style.display !== 'block') return;
                while (t && t !== menu && !(t.getAttribute && t.getAttribute('data-tm-act') === 'menu')) t = t.parentNode;
                if (!t || t === menu) menu.style.display = 'none';
            });
            d.addEventListener('keydown', function (e) {
                e = e || w.event;
                if ((e.keyCode || e.which) !== 27) return;
                var m = $('owTmMenu');
                if (m) m.style.display = 'none';
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
                var head = box.getElementsByClassName('ow-list-head')[0];
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

        /* ---------- 修改「谁可以看」（作者 / 管理员） ---------- */
        editVis: function (pid) {
            var p = this.posts[pid] || {};
            var cur = p.visibility || 'public';
            var h = '<h3>谁可以看</h3>'
                + '<p class="ow-modal-desc">改完立即生效：公开 = 所有人可见；好友 = 只有你的好友；私密 = 仅自己。</p>'
                + '<div class="ow-tm-vis-tabs" id="owTmVisEdit">'
                + '<button type="button" class="ow-tm-vtab' + (cur === 'public' ? ' is-on' : '') + '" data-vis="public">公开</button>'
                + '<button type="button" class="ow-tm-vtab' + (cur === 'friends' ? ' is-on' : '') + '" data-vis="friends">好友</button>'
                + '<button type="button" class="ow-tm-vtab' + (cur === 'private' ? ' is-on' : '') + '" data-vis="private">私密</button>'
                + '</div>'
                + '<div class="ow-modal-actions">'
                + '<button class="ow-btn" type="button" onclick="OwChat.closeModal()">取消</button>'
                + '<button class="ow-btn ow-btn-primary" type="button" id="owTmVisGo">保存</button>'
                + '</div>';
            OwChat.openModal(h, 380);
            var chosen = cur, box = $('owTmVisEdit');
            if (box) {
                var bs = box.getElementsByClassName('ow-tm-vtab'), i;
                for (i = 0; i < bs.length; i++) {
                    bs[i].onclick = function () {
                        var all = box.getElementsByClassName('ow-tm-vtab'), k;
                        for (k = 0; k < all.length; k++) all[k].className = 'ow-tm-vtab';
                        this.className = 'ow-tm-vtab is-on';
                        chosen = this.getAttribute('data-vis') || 'public';
                    };
                }
            }
            var go = $('owTmVisGo');
            if (go) go.onclick = function () {
                if (chosen === cur) { OwChat.closeModal(); return; }
                go.disabled = true;
                OwApi.post('plugin_time_machine_set_vis', { id: pid, visibility: chosen }, function (r) {
                    go.disabled = false;
                    if (!r || !r.ok) { toast((r && r.msg) || '修改失败'); return; }
                    OwChat.closeModal();
                    OwTM.applyVis(pid, chosen);
                    toast('可见范围已更新');
                });
            };
        },

        /** 就地更新卡片的可见性徽标 + 缓存（不重画整张卡，评论输入不丢） */
        applyVis: function (pid, vis) {
            var p = this.posts[pid];
            if (p) p.visibility = vis;
            var card = this.findCard(pid);
            if (!card) return;
            var top = card.getElementsByClassName('ow-tm-card-top')[0];
            if (!top) return;
            var old = top.getElementsByClassName('ow-tm-vis')[0];
            if (old && old.parentNode) old.parentNode.removeChild(old);
            var tag = vis === 'friends' ? '<span class="ow-tm-vis" data-vis="friends">好友可见</span>'
                : (vis === 'private' ? '<span class="ow-tm-vis" data-vis="private">私密</span>' : '');
            if (!tag) return;
            var nick = top.getElementsByClassName('ow-tm-nick')[0];
            if (nick) nick.insertAdjacentHTML('afterend', tag);
        },

        /* ---------- 「···」下拉菜单（收藏 / 复制 / 删除） ----------
           复用核心 .ow-ctx-menu 外壳（同款底色/圆角/行高/悬停主色），不另造一套。
           定位跟随按钮，越界时往回挪（与核心 showUserMenu 同一手法）。 */
        openMenu: function (card, pid) {
            var p = this.posts[pid] || {};
            var canDel = (p.uid === this.me) || this.isAdmin;
            var items = [];
            items.push({
                t: p.faved ? '取消收藏' : '收藏', icon: 'bookmark',
                run: function () { OwTM.toggle('fav', pid, card); }
            });
            items.push({ t: '复制', icon: 'copy', run: function () { OwTM.copyCard(card, pid); } });
            // 谁可以看：只有作者（或管理员）能改，点了开一个小弹窗选三档
            if (canDel) {
                var visCn = p.visibility === 'friends' ? '好友' : (p.visibility === 'private' ? '私密' : '公开');
                items.push({ t: '谁可以看（' + visCn + '）', icon: 'eye', run: function () { OwTM.editVis(pid); } });
            }
            if (canDel) items.push({ t: '删除', icon: 'trash', run: function () { OwTM.confirmDel(card, pid); } });
            var btn = card.querySelector('[data-tm-act="menu"]');
            if (!btn) return;
            var r = btn.getBoundingClientRect();
            var menu = $('owTmMenu');
            if (!menu) {
                menu = d.createElement('div');
                menu.className = 'ow-ctx-menu';
                menu.id = 'owTmMenu';
                menu.style.display = 'none';
                d.body.appendChild(menu);
            }
            var h = '', i;
            for (i = 0; i < items.length; i++) {
                h += '<a class="ow-ctx-item" href="javascript:;" data-i="' + i + '">'
                    + ico(items[i].icon, 14, 'ow-ctx-ico') + esc(items[i].t) + '</a>';
            }
            menu.innerHTML = h;
            menu._items = items;
            menu.style.display = 'block';
            var vw = w.innerWidth || 900, vh = w.innerHeight || 700;
            var mw = menu.offsetWidth || 140, mh = menu.offsetHeight || items.length * 34;
            menu.style.left = Math.max(4, r.right - mw) + 'px';
            menu.style.top = Math.max(4, (r.bottom + mh > vh ? r.top - mh : r.bottom + 4)) + 'px';
            var as = menu.getElementsByTagName('a'), k;
            for (k = 0; k < as.length; k++) {
                as[k].onclick = (function (n) {
                    return function () {
                        menu.style.display = 'none';
                        var it = menu._items[n];
                        if (it && typeof it.run === 'function') it.run();
                    };
                })(parseInt(as[k].getAttribute('data-i'), 10));
            }
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
                var p = self.posts[pid];
                if (p) {
                    p[type === 'like' ? 'liked' : 'faved'] = !!r.on;
                    if (type === 'like') {
                        p.likes = r.likes | 0;
                        // 点赞用户行跟着变：把自己加进去 / 摘出来（me_profile 由 feed 下发）
                        var me = self.meProfile || {};
                        if (r.on && me.uid) {
                            var has = false, i;
                            for (i = 0; i < (p.likers || []).length; i++) if (p.likers[i].uid === me.uid) has = true;
                            if (!has) p.likers.push(me);
                        } else {
                            var keep = [], j;
                            for (j = 0; j < (p.likers || []).length; j++) if (p.likers[j].uid !== self.me) keep.push(p.likers[j]);
                            p.likers = keep;
                        }
                    }
                }
                if (type === 'like') {
                    if (btn) {
                        var on = !!r.on;
                        btn.className = 'ow-tm-act' + (on ? ' is-on' : '');
                        btn.innerHTML = ico(on ? 'heart-fill' : 'heart', 16) + '<i>' + (r.likes | 0) + '</i>';
                    }
                    // 点赞用户行整块重画（头像 + 昵称 + 等 N 人）
                    var row = card.getElementsByClassName('ow-tm-likers')[0];
                    if (row) {
                        var fresh = self.likersRow(self.posts[pid] || {});
                        var wrap = row.parentNode;
                        wrap.removeChild(row);
                        if (fresh) wrap.insertAdjacentHTML('afterbegin', fresh);
                    }
                } else {
                    var onf = !!r.on;
                    if (btn) {
                        btn.className = 'ow-tm-act' + (onf ? ' is-on' : '');
                        btn.innerHTML = ico('bookmark', 16) + '<i>' + (onf ? '已收藏' : '收藏') + '</i>';
                    }
                    if (self.tab === 'fav' && !onf) {
                        var el = self.findCard(pid);
                        if (el && el.parentNode) el.parentNode.removeChild(el);
                    }
                }
            });
        },

        /* ---------- 聚焦评论（评论默认展开，点动作条只是把光标送进输入框） ---------- */
        focusComment: function (card) {
            var inp = card.getElementsByClassName('ow-tm-cmt-in')[0];
            if (inp) { inp.focus(); return; }
            var el = card.getElementsByClassName('ow-tm-cmts')[0];
            if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
        },

        /**
         * 文件附件一行：复用核心消息里文件卡的同一套类名
         * （.ow-file-card / .ow-file-ico / .ow-file-name / .ow-file-size / .ow-file-dl），
         * 下载链接带签名（与核心 file_download 同一手法），服务端还会再判一次可见性。
         * scope = {post: 波文id} 或 {cmt: 评论id}
         */
        fileRow: function (scope, idx, f) {
            var s = OwApi.sign('plugin_time_machine_download');
            var href = '?action=plugin_time_machine_download&ts=' + s.ts + '&sign=' + s.sign;
            href += scope && scope.cmt ? '&cid=' + scope.cmt : '&id=' + (scope ? scope.post : 0);
            href += '&i=' + idx;
            var nm = f.name || '文件';
            return '<div class="ow-file-card">'
                + '<span class="ow-file-ico">' + ico('file', 18) + '</span>'
                + '<span class="ow-file-meta"><span class="ow-file-name" title="' + esc(nm) + '">' + esc(nm) + '</span>'
                + '<span class="ow-file-size">' + esc(sizeText(f.size)) + '</span></span>'
                + '<a class="ow-file-dl" href="' + href + '" title="下载">' + ico('download', 18) + '</a></div>';
        },

        /* ---------- 评论（默认展开，无需点开；支持图片 / 文件 / 表情） ---------- */
        cmtRow: function (c) {
            var media = c.media || [], i, ih = '', fh = '', g;
            var imgs = [], files = [];
            for (i = 0; i < media.length; i++) {
                // 记住原始下标：下载链接按评论自己的 media 数组寻址
                if (media[i].k === 'file') files.push({ m: media[i], i: i }); else imgs.push(media[i]);
            }
            g = imgs.length >= 3 ? ' g3' : (imgs.length >= 2 ? ' g2' : ' g1');
            for (i = 0; i < imgs.length; i++) {
                ih += '<img class="ow-tm-img ow-tm-cmt-img" src="' + esc(imgs[i].url) + '" alt="评论图片"'
                    + ' onclick="OwChat.viewImg(this.src)" loading="lazy">';
            }
            for (i = 0; i < files.length; i++) fh += this.fileRow({ cmt: c.id }, files[i].i, files[i].m);
            return '<div class="ow-tm-cmt">'
                + avatarBtn(c.uid, c.nickname, c.avatar, 'ow-avatar-xs')
                + '<div class="ow-tm-cmt-main">'
                + '<div class="ow-tm-cmt-meta"><span class="ow-tm-nick ow-tm-nick-s" onclick="OwChat.userCard(' + c.uid + ',' + jsAttr(c.nickname) + ')">' + esc(c.nickname) + '</span>'
                + '<span class="ow-tm-time">' + esc(fmtTime(c.created_at)) + '</span></div>'
                + (c.content ? '<div class="ow-tm-cmt-text">' + nl2br(c.content) + '</div>' : '')
                + (ih ? '<div class="ow-tm-imgs ow-tm-cmt-imgs' + g + '">' + ih + '</div>' : '')
                + (fh ? '<div class="ow-tm-files ow-tm-cmt-files">' + fh + '</div>' : '')
                + '</div></div>';
        },

        /* ---------- 评论的表情 / 附件（v1.4.0） ----------
           每条评论一个草稿（按 pid 记），表情面板与待发附件都挂在该卡片的评论盒里；
           附件仍然全部走「附件上传」插件，没装就提示安装。 */
        cmtDraft: {},

        /** 表情面板：复用前台 OwChat.emojis + 核心 .ow-emoji-item，落点是该卡片的评论输入框 */
        toggleCmtEmoji: function (card) {
            var box = card.getElementsByClassName('ow-tm-cmt-emoji')[0];
            if (!box) return;
            if (box.style.display !== 'none') { box.style.display = 'none'; return; }
            if (box.getAttribute('data-built') !== '1') {
                var list = (OwChat && OwChat.emojis) || [], h = '', i;
                for (i = 0; i < list.length; i++) h += '<span class="ow-emoji-item">' + esc(list[i]) + '</span>';
                box.innerHTML = h;
                box.setAttribute('data-built', '1');
                var input = card.getElementsByClassName('ow-tm-cmt-in')[0];
                var items = box.getElementsByClassName('ow-emoji-item');
                for (i = 0; i < items.length; i++) {
                    items[i].onclick = function () {
                        if (input) { input.value += this.innerHTML; input.focus(); }
                    };
                }
            }
            box.style.display = '';
        },

        /** 选附件：一个共用的隐藏 file 控件，靠 _cmtPid 记住这次往哪条波文的评论里加 */
        pickCmtFile: function (pid) {
            if (!(this.attach && this.attach.on)) {
                toast('请先在后台安装并启用「附件上传」插件，才能上传图片与文件');
                return;
            }
            var self = this;
            if (!this._cmtFile) {
                var f = d.createElement('input');
                f.type = 'file';
                f.multiple = true;
                f.style.display = 'none';
                d.body.appendChild(f);
                this._cmtFile = f;
            }
            if (this.attach.accept) this._cmtFile.accept = this.attach.accept;
            this._cmtFilePid = pid;
            this._cmtFile.value = '';
            this._cmtFile.onchange = function () {
                var files = self._cmtFile.files || [], queue = [], i;
                for (i = 0; i < files.length; i++) {
                    if (self.cmtMedia(pid).length + queue.length >= 9) break;
                    queue.push(files[i]);
                }
                if (!queue.length) { toast('附件最多 9 个'); return; }
                self.uploadCmtQueue(pid, queue);
            };
            this._cmtFile.click();
        },

        cmtMedia: function (pid) {
            if (!this.cmtDraft[pid]) this.cmtDraft[pid] = [];
            return this.cmtDraft[pid];
        },

        /** 评论附件上传：图片走插件的图片路由（会压缩），其余走文件路由 */
        uploadCmtQueue: function (pid, queue) {
            var self = this;
            (function next() {
                if (!queue.length) { self.paintCmtMedia(pid); return; }
                var f = queue.shift();
                var isImg = !!IMG_EXT[extOf(f.name)];
                var action = isImg ? 'plugin_attachment_manager_upload' : 'plugin_attachment_manager_upload_file';
                OwApi.upload(action, f, {}, function (r) {
                    var arr = self.cmtMedia(pid);
                    if (r && r.ok && arr.length < 9) {
                        if (isImg && r.url) arr.push({ k: 'image', url: r.url });
                        else if (!isImg && r.file) arr.push({
                            k: 'file', path: r.file.path, name: r.file.name, size: r.file.size, ext: r.file.ext
                        });
                    } else if (!r || !r.ok) {
                        toast((r && r.msg) || '附件上传失败');
                    }
                    self.paintCmtMedia(pid);
                    next();
                });
            })();
        },

        paintCmtMedia: function (pid) {
            var box = $('owTmCmtMd-' + pid);
            if (!box) return;
            var arr = this.cmtMedia(pid), h = '', i, m;
            for (i = 0; i < arr.length; i++) {
                m = arr[i];
                if (m.k === 'file') {
                    h += '<span class="ow-tm-chip"><span class="ow-tm-chip-ic">' + ico('file', 16) + '</span>'
                        + '<span class="ow-tm-chip-n">' + esc(m.name) + '</span>'
                        + '<span class="ow-tm-chip-s">' + esc(sizeText(m.size)) + '</span>'
                        + '<button type="button" class="ow-thumb-x" data-tm-act="cmtrm" data-i="' + i + '" title="移除" aria-label="移除">'
                        + ico('close', 12) + '</button></span>';
                } else {
                    h += '<span class="ow-tm-thumb"><img src="' + esc(m.url) + '" alt="">'
                        + '<button type="button" class="ow-thumb-x" data-tm-act="cmtrm" data-i="' + i + '" title="移除" aria-label="移除">'
                        + ico('close', 12) + '</button></span>';
                }
            }
            box.innerHTML = h;
        },

        rmCmtMedia: function (pid, idx) {
            var arr = this.cmtMedia(pid);
            arr.splice(parseInt(idx, 10) || 0, 1);
            this.paintCmtMedia(pid);
        },

        addComment: function (card, pid) {
            var input = card.getElementsByClassName('ow-tm-cmt-in')[0];
            if (!input) return;
            var text = input.value.replace(/^\s+|\s+$/g, '');
            var media = this.cmtMedia(pid).slice();
            if (!text && !media.length) { toast('说点什么吧'); return; }
            var btn = card.querySelector('[data-tm-act="cmtsend"]');
            if (btn) btn.disabled = true;
            var self = this;
            OwApi.post('plugin_time_machine_comment_add', {
                post_id: pid, content: text, media: JSON.stringify(media)
            }, function (r) {
                if (btn) btn.disabled = false;
                if (!r || !r.ok) { toast((r && r.msg) || '评论失败'); return; }
                input.value = '';
                // 清空该条的草稿：待发附件与表情面板一起收起
                self.cmtDraft[pid] = [];
                self.paintCmtMedia(pid);
                var emo = card.getElementsByClassName('ow-tm-cmt-emoji')[0];
                if (emo) emo.style.display = 'none';
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
                // 缓存同步：计数 + 评论数组（下次局部更新用）
                var cp = self.posts[pid];
                if (cp) {
                    cp.comment_cnt = r.comments | 0;
                    if (!cp.comments) cp.comments = [];
                    cp.comments.push(r.comment);
                }
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

        /* ---------- 发波文（弹窗编辑器） ----------
           附件（图 + 文件）全部交给「附件上传」插件：
             · 图片 → plugin_attachment_manager_upload（插件内压缩，返回 url）
             · 文件 → plugin_attachment_manager_upload_file（MIME 白名单 / 体积 / 配额，返回 file{}）
           没装或没启用该插件：不给上传入口，只显示一行提示（服务端同样不认任何附件）。 */
        composerMedia: [],
        composerBusy: false,
        composerVis: 'public',
        taUserH: 0,          // 用户手动拖出的输入框高度（0=走自动增高，与核心 inputUserH 同义）

        /** 输入框自动增高：与核心 OwChat.autoGrow 同一组参数（最小 40 / 自动上限 120 / 手动上限 320） */
        autoGrowTa: function () {
            var ta = $('owTmTa');
            if (!ta) return;
            ta.style.height = 'auto';
            var h = ta.scrollHeight + 2, min = 40;
            if (this.taUserH > 0) {
                ta.style.height = Math.max(min, Math.min(Math.max(h, this.taUserH), 320)) + 'px';
                return;
            }
            ta.style.height = Math.max(min, Math.min(h, 120)) + 'px';
        },

        openComposer: function () {
            if (!this.canPost) { toast('请先登录后再发波文'); return; }
            this.composerMedia = [];
            this.composerBusy = false;
            this.composerVis = 'public';
            var at = this.attach || {};
            var h = '<h3>发波文</h3>'
                + '<div class="ow-tm-cmp">'
                // 输入框与消息输入区同一套：rows=1 自动增高 + 右下角拖拽手柄自定义高度
                + '<div class="ow-tm-ta-wrap">'
                + '<textarea class="ow-input ow-tm-ta" id="owTmTa" maxlength="2000" rows="1" placeholder="这一刻的想法…"></textarea>'
                + '<span class="ow-tm-resize" id="owTmResize" title="拖动调整输入框高度">' + ico('resize', 14) + '</span>'
                + '</div>'
                + '<div class="ow-tm-cmp-bar">'
                + '<button type="button" class="ow-tm-cmp-btn" id="owTmEmo" title="表情" aria-label="表情">' + ico('smile', 18) + '</button>'
                + '<button type="button" class="ow-tm-cmp-btn' + (at.on ? '' : ' is-off') + '" id="owTmAdd" title="添加图片或文件" aria-label="添加图片或文件">'
                + ico('paperclip', 18) + '</button>'
                + '<span class="ow-tm-cmp-cnt" id="owTmCnt">0 / 2000</span>'
                + '</div>'
                + '<div class="ow-tm-emoji" id="owTmEmoji" style="display:none"></div>'
                + '<div class="ow-tm-cmp-media" id="owTmMedia"></div>'
                + '<div class="ow-tm-cmp-tip" id="owTmTip"></div>'
                + '<div class="ow-tm-vis-row"><span class="ow-tm-vis-lb">谁可以看</span>'
                + '<div class="ow-tm-vis-tabs" id="owTmVis">'
                + '<button type="button" class="ow-tm-vtab is-on" data-vis="public">公开</button>'
                + '<button type="button" class="ow-tm-vtab" data-vis="friends">好友</button>'
                + '<button type="button" class="ow-tm-vtab" data-vis="private">私密</button>'
                + '</div></div>'
                + '<div class="ow-modal-actions">'
                + '<button class="ow-btn" type="button" onclick="OwChat.closeModal()">取消</button>'
                + '<button class="ow-btn ow-btn-primary" type="button" id="owTmGo">发布</button>'
                + '</div></div>';
            OwChat.openModal(h, 520);
            // 上一次弹窗（取消/提交）留下的隐藏选择控件：先清掉再建新的，避免 body 上越积越多
            if (this._fileInput) { try { d.body.removeChild(this._fileInput); } catch (e) {} this._fileInput = null; }
            var ta = $('owTmTa'), self = this;
            if (ta) {
                // 恢复上次手动拖出的高度（与核心 owl_input_h 同思路，键名独立）
                try { this.taUserH = parseInt(w.localStorage.getItem('owl_tm_h') || '0', 10) || 0; } catch (e) { this.taUserH = 0; }
                ta.oninput = function () {
                    var c = $('owTmCnt');
                    if (c) c.textContent = ta.value.length + ' / 2000';
                    self.autoGrowTa();
                };
                this.autoGrowTa();
                ta.focus();
            }
            /* 拖拽手柄：与核心 bindInputResize 同一套参数（40~320，写入 localStorage），
               手动设过高度后自动增高只升不降地尊重它。 */
            var rs = $('owTmResize');
            if (rs && ta) {
                rs.onmousedown = function (e) {
                    e = e || w.event;
                    var startY = e.clientY || 0, startH = ta.offsetHeight;
                    if (e.preventDefault) e.preventDefault(); else e.returnValue = false;
                    d.onmousemove = function (ev) {
                        ev = ev || w.event;
                        var hh = startH + (((ev.clientY || 0) - startY));
                        hh = Math.max(40, Math.min(hh, 320));
                        self.taUserH = hh;
                        ta.style.height = hh + 'px';
                        try { w.localStorage.setItem('owl_tm_h', String(hh)); } catch (err) {}
                    };
                    d.onmouseup = function () { d.onmousemove = null; d.onmouseup = null; };
                    return false;
                };
            }
            /* 表情：复用前台同一份表情表与核心 .ow-emoji-* 样式，只是把落点从
               聊天输入框换成这里的 textarea（核心面板写死插 #owInput，够不着）。 */
            var emo = $('owTmEmo'), ebox = $('owTmEmoji');
            if (emo && ebox) {
                var list = (OwChat && OwChat.emojis) || [];
                if (!list.length) {
                    emo.style.display = 'none';       // 没有表情数据就不给这个按钮（不摆一个点了没反应的入口）
                } else {
                    var eh = '', i;
                    for (i = 0; i < list.length; i++) eh += '<span class="ow-emoji-item">' + esc(list[i]) + '</span>';
                    ebox.innerHTML = eh;
                    var items = ebox.getElementsByClassName('ow-emoji-item');
                    for (i = 0; i < items.length; i++) {
                        items[i].onclick = function () {
                            if (ta) { ta.value += this.innerHTML; ta.focus(); }
                            if (ta && ta.oninput) ta.oninput();
                        };
                    }
                    emo.onclick = function () {
                        ebox.style.display = ebox.style.display === 'none' ? '' : 'none';
                    };
                }
            }
            // 可见性三选
            var vtabs = $('owTmVis');
            if (vtabs) {
                var vs = vtabs.getElementsByClassName('ow-tm-vtab'), j;
                for (j = 0; j < vs.length; j++) {
                    vs[j].onclick = function () {
                        var all = vtabs.getElementsByClassName('ow-tm-vtab'), m;
                        for (m = 0; m < all.length; m++) all[m].className = 'ow-tm-vtab';
                        this.className = 'ow-tm-vtab is-on';
                        self.composerVis = this.getAttribute('data-vis') || 'public';
                        self.syncTip();
                    };
                }
            }
            var addBtn = $('owTmAdd');
            var add = d.createElement('input');
            add.type = 'file';
            add.multiple = true;
            add.style.display = 'none';
            add.id = 'owTmFile';
            if (at.accept) add.accept = at.accept;
            d.body.appendChild(add);
            add.onchange = function () {
                var files = add.files || [], queue = [], i;
                for (i = 0; i < files.length; i++) {
                    if (self.composerMedia.length + queue.length >= 9) break;
                    queue.push(files[i]);
                }
                add.value = '';
                if (!queue.length) { toast('附件最多 9 个'); return; }
                self.uploadQueue(queue);
            };
            this._fileInput = add;
            if (addBtn) addBtn.onclick = function () {
                if (!(self.attach && self.attach.on)) {
                    toast('请先在后台安装并启用「附件上传」插件，才能上传图片与文件');
                    return;
                }
                add.click();
            };
            var go = $('owTmGo');
            if (go) go.onclick = function () { self.submit(); };
            this.renderMedia();
        },

        renderMedia: function () {
            var box = $('owTmMedia');
            if (!box) return;
            var h = '', i, self = this, m;
            for (i = 0; i < this.composerMedia.length; i++) {
                m = this.composerMedia[i];
                if (m.k === 'file') {
                    h += '<span class="ow-tm-chip"><span class="ow-tm-chip-ic">' + ico('file', 16) + '</span>'
                        + '<span class="ow-tm-chip-n">' + esc(m.name) + '</span>'
                        + '<span class="ow-tm-chip-s">' + esc(sizeText(m.size)) + '</span>'
                        + '<button type="button" class="ow-thumb-x" data-i="' + i + '" title="移除" aria-label="移除">' + ico('close', 12) + '</button></span>';
                } else {
                    h += '<span class="ow-tm-thumb"><img src="' + esc(m.url) + '" alt="">'
                        + '<button type="button" class="ow-thumb-x" data-i="' + i + '" title="移除" aria-label="移除">' + ico('close', 12) + '</button></span>';
                }
            }
            if (this.composerMedia.length < 9) {
                h += '<button type="button" class="ow-tm-addimg" id="owTmAddImg" title="添加图片或文件" aria-label="添加图片或文件">' + ico('image', 20) + '</button>';
            }
            box.innerHTML = h;
            var addBtn = $('owTmAddImg');
            if (addBtn) addBtn.onclick = function () {
                var a = $('owTmAdd');
                if (a) a.click();
            };
            var xs = box.getElementsByClassName('ow-thumb-x'), k;
            for (k = 0; k < xs.length; k++) {
                xs[k].onclick = (function (n) {
                    return function () { self.composerMedia.splice(n, 1); self.renderMedia(); };
                })(parseInt(xs[k].getAttribute('data-i'), 10));
            }
            this.syncTip();
        },

        /** 逐个上传：图片走插件的图片路由（会压缩），其余走文件路由 */
        uploadQueue: function (queue) {
            var self = this;
            this.composerBusy = true;
            (function next() {
                if (!queue.length) { self.composerBusy = false; self.syncTip(); return; }
                self.syncTip('正在上传…（' + self.composerMedia.length + '/9）');
                var f = queue.shift();
                var isImg = !!IMG_EXT[extOf(f.name)];
                var action = isImg ? 'plugin_attachment_manager_upload' : 'plugin_attachment_manager_upload_file';
                OwApi.upload(action, f, {}, function (r) {
                    if (r && r.ok && self.composerMedia.length < 9) {
                        if (isImg && r.url) self.composerMedia.push({ k: 'image', url: r.url });
                        else if (!isImg && r.file) self.composerMedia.push({
                            k: 'file', path: r.file.path, name: r.file.name, size: r.file.size, ext: r.file.ext
                        });
                        self.renderMedia();
                    } else {
                        toast((r && r.msg) || '附件上传失败');
                    }
                    next();
                });
            })();
        },

        syncTip: function (msg) {
            var tip = $('owTmTip');
            if (!tip) return;
            if (msg) { tip.textContent = msg; return; }
            var at = this.attach || {};
            var visTxt = this.composerVis === 'friends' ? '仅好友可见' : (this.composerVis === 'private' ? '仅自己可见' : '所有人可见');
            tip.textContent = (at.hint || '') + '　' + visTxt + '（已添加 '
                + this.composerMedia.length + '/9）';
        },

        submit: function () {
            var ta = $('owTmTa');
            var text = ta ? ta.value.replace(/^\s+|\s+$/g, '') : '';
            if (!text && !this.composerMedia.length) { toast('写点什么，或者配张图吧'); return; }
            if (this.composerBusy) { toast('附件还在上传，请稍候'); return; }
            var go = $('owTmGo');
            if (go) go.disabled = true;
            var self = this;
            OwApi.post('plugin_time_machine_post', {
                content: text,
                media: JSON.stringify(this.composerMedia),
                visibility: this.composerVis
            }, function (r) {
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
