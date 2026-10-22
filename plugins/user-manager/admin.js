/**
 * 用户管理插件 - 后台交互（v1.1.0）
 * 全局对象 OwUM：页面 HTML 由插件后台页注入，函数在点击时执行，
 * 依赖的 OwApi / toast / esc / fmtUid / opts / ROLE_CN / OwAdmin 由主 chat.js 提供。
 *
 * v1.1.0：
 *   - 新增「IP 封禁」分栏（规则列表 / 高风险 IP / 归属地解析）；
 *   - 「详情」升级成安全画像：登录网络 + 归属地 + 同 IP 的其他账号 + 一键封禁。
 *   归属地**不在首屏请求里同步解析**（外部接口会把面板拖到几秒），
 *   面板先出来，缺的地址随后由第二次请求补 —— 用户不会对着转圈等。
 *
 * 兼容老浏览器：只用 var / function，不用箭头函数、let/const、fetch、模板字符串。
 */
(function (w, d) {
    'use strict';
    var $ = function (id) { return d.getElementById(id); };

    /** 当前状态（面板打开一次刷一次，不做跨面板缓存） */
    var st = {
        uid: 0,          // 面板里的用户
        user: null,      // 概览行（detail 接口）
        net: null,       // 网络数据（net 接口）
        geoBusy: false,
        bans: null,
        myIp: ''         // 本次请求的出口 IP（服务端下发，用于关掉「封自己」的入口）
    };

    /**
     * v1.5.4：「积分」是站点可改的名词（核心 系统设置 → 基本信息 → 积分名称），
     * 用户管理的表头 / 概览行 / 提示一律走它，不写死「积分」二字。
     * 兜底值与服务端一致（空 = 积分），OwAdmin 尚未 init 时也不会得到 undefined。
     */
    function pn() {
        return (w.OwAdmin && w.OwAdmin.pointsName) ? w.OwAdmin.pointsName() : '积分';
    }

    /* ---------- 时间格式 ---------- */
    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    function fmtTs(ts) {
        ts = parseInt(ts, 10);
        if (!ts) return '';
        var t = new Date(ts * 1000);
        return t.getFullYear() + '-' + pad2(t.getMonth() + 1) + '-' + pad2(t.getDate())
            + ' ' + pad2(t.getHours()) + ':' + pad2(t.getMinutes());
    }
    function fmtShort(ts) {
        ts = parseInt(ts, 10);
        if (!ts) return '';
        var t = new Date(ts * 1000);
        return pad2(t.getMonth() + 1) + '-' + pad2(t.getDate()) + ' ' + pad2(t.getHours()) + ':' + pad2(t.getMinutes());
    }
    function ago(ts) {
        ts = parseInt(ts, 10);
        if (!ts) return '';
        var s = Math.max(0, Math.floor(Date.now() / 1000) - ts);
        if (s < 60) return '刚刚';
        if (s < 3600) return Math.floor(s / 60) + ' 分钟前';
        if (s < 86400) return Math.floor(s / 3600) + ' 小时前';
        if (s < 86400 * 30) return Math.floor(s / 86400) + ' 天前';
        return Math.floor(s / (86400 * 30)) + ' 个月前';
    }

    /* ---------- 小片段 ---------- */
    function chip(txt) {
        return txt ? '<span class="owum-chip">' + esc(txt) + '</span>' : '';
    }
    function pill(txt, kind) {
        return '<span class="owum-pill' + (kind ? ' owum-pill-' + kind : '') + '">' + esc(txt) + '</span>';
    }

    /**
     * 头像。**优先用服务端算好的 avatar_url** —— 生成式头像（dicebear / 内置 identicon）
     * 在 users.avatar 里是空串，只按 avatar 字段判断会退化成「首字 + 色块」，
     * 站长看到的就是一个紫色的 O。只有连 avatar_url 都拿不到时才走首字兜底。
     */
    function avatar(u, cls) {
        var c = cls || 'ow-avatar-lg';
        var src = u.avatar_url || u.avatar || '';
        if (src) {
            return '<span class="ow-avatar ' + c + '"><img src="' + esc(src) + '" alt="" loading="lazy" decoding="async"></span>';
        }
        var palette = ['#0099FF', '#00558F', '#A05000', '#237804', '#5B21B6'];
        var bg = palette[((u.nickname || '?').length) % palette.length];
        return '<span class="ow-avatar ' + c + '" style="background:' + bg + '">'
            + esc((u.nickname || '?').charAt(0)) + '</span>';
    }

    /* ---------- 「这是我自己在用的地址」判定 ----------
       服务端已经会拒（ban_add 里比对 Sec::ip()），前端把它提前成「入口就没有」：
       与其让管理员点一下再吃一个报错，不如直接把按钮换成说明。
       通配规则（1.2.3.*）也要一起判：它命中的可能是自己。 */
    function ipMatch(pattern, ip) {
        if (!pattern || !ip) return false;
        if (pattern === ip) return true;
        if (pattern.indexOf('*') < 0) return false;
        var re = new RegExp('^' + pattern.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '$');
        return re.test(ip);
    }
    function isMyIp(ip) {
        return !!st.myIp && ipMatch(ip, st.myIp);
    }
    /** 地址行右侧的动作：已封禁 → 解封；是自己 → 只说明；否则 → 封禁 */
    function ipAction(ip, banned) {
        if (banned) {
            return '<button class="ow-btn ow-btn-ghost ow-btn-mini" onclick="OwUM.unban(\''
                + esc(ip) + '\')">解封</button>';
        }
        if (isMyIp(ip)) {
            return '<span class="owum-pill" title="这是你当前登录使用的地址，不能封禁">当前 IP</span>';
        }
        return '<button class="ow-btn ow-btn-ghost ow-btn-mini" onclick="OwUM.banDialog(\''
            + esc(ip) + '\')">封禁</button>';
    }

    /** 封禁表单的自查：输入命中自己当前地址时禁掉按钮并把原因写在旁边 */
    function syncBanForm() {
        var el = $('owumBanIp');
        if (!el) return false;
        var block = isMyIp((el.value || '').replace(/^\s+|\s+$/g, ''));
        var btn = $('owumBanBtn'), warn = $('owumBanSelf'), tip = $('owumBanSelfIp');
        if (btn) btn.disabled = block;
        if (warn) warn.style.display = block ? '' : 'none';
        if (tip) tip.textContent = st.myIp || '';
        return block;
    }

    /* ============================================================
       后台页：分栏切换
       ============================================================ */
    function bindTabs() {
        var bar = $('owumTabs');
        if (!bar) return;
        var btns = bar.getElementsByTagName('button');
        var i;
        for (i = 0; i < btns.length; i++) {
            btns[i].onclick = function () {
                var name = this.getAttribute('data-owum-tab');
                OwUM.tab(name);
            };
        }
    }

    /* ============================================================
       封禁规则弹窗（比 confirm 多两个字段：原因 + 时长）
       ============================================================ */
    function banDialog(pattern, onDone) {
        var mask = d.createElement('div');
        mask.className = 'ow-modal-mask';
        mask.style.display = 'flex';
        mask.style.zIndex = 210;
        mask.innerHTML = '<div class="ow-modal" style="width:400px;max-width:94%">'
            + '<button class="ow-modal-close">✕</button>'
            + '<h3>封禁网络地址</h3>'
            + '<p class="ow-modal-desc">该地址打开任何页面都会被拦下；管理员账号不受影响。</p>'
            + '<div class="ow-form-item"><label>IP / 网段</label>'
            + '<input class="ow-input owum-mono" id="owumDlgIp" value="' + esc(pattern) + '" maxlength="64"></div>'
            // ⚠️ 这行不能放进 .ow-form-item：核心 OwTip 会把 form-item 里的 <p> 整段
            //    搬进 ⓘ 气泡并**摘掉原节点**，$('owumDlgWarn') 直接变 null（踩过）。
            //    放在 form-item 外面它才是常驻可见的红字。
            + '<p class="owum-warn" id="owumDlgWarn" style="display:none">'
            + '这是你当前登录使用的地址，不能封禁。</p>'
            + '<div class="ow-form-item"><label>原因（会显示在拦截页）</label>'
            + '<input class="ow-input" id="owumDlgNote" maxlength="60" placeholder="选填"></div>'
            + '<div class="ow-form-item"><label>时长</label>'
            + '<select class="ow-input" id="owumDlgDays">'
            + '<option value="0">永久</option><option value="1">1 天</option>'
            + '<option value="7">7 天</option><option value="30">30 天</option></select></div>'
            + '<div class="ow-modal-actions">'
            + '<button class="ow-btn ow-btn-ghost" id="owumDlgCancel">取消</button>'
            + '<button class="ow-btn ow-btn-danger" id="owumDlgOk">封禁</button>'
            + '</div></div>';
        d.body.appendChild(mask);
        var close = function () { if (mask.parentNode) d.body.removeChild(mask); };
        mask.querySelector('.ow-modal-close').onclick = close;
        mask.onclick = function (e) { if (e.target === mask) close(); };
        $('owumDlgCancel').onclick = close;

        var sync = function () {
            var ip = ($('owumDlgIp').value || '').replace(/^\s+|\s+$/g, '');
            var block = isMyIp(ip);
            var warn = $('owumDlgWarn'), ok = $('owumDlgOk');
            if (warn) warn.style.display = block ? '' : 'none';
            if (ok) ok.disabled = block;
            return { ip: ip, block: block };
        };
        $('owumDlgIp').oninput = sync;
        sync();

        $('owumDlgOk').onclick = function () {
            var v = sync();
            if (!v.ip) { toast('请输入 IP 地址'); return; }
            if (v.block) return;
            OwApi.secure('plugin_user_manager_ban_add',
                { pattern: v.ip, note: $('owumDlgNote').value, days: $('owumDlgDays').value },
                function (r) {
                    toast(r.msg);
                    if (!r.ok) return;
                    close();
                    if (onDone) onDone();
                });
        };
    }

    /* ============================================================
       安全画像
       ============================================================ */

    /** 头部：头像 + 昵称 + 身份标签 + 状态；右侧主行动 */
    function headHtml(u) {
        var isRoot = parseInt(u.id, 10) === 1;
        var roleTag = (isRoot || u.role === 'admin') ? '<span class="ow-tag ow-tag-admin">超级管理员</span>'
            : (u.role === 'vip' ? '<span class="ow-tag ow-tag-vip">VIP</span>' : '<span class="ow-tag ow-tag-member">会员</span>');
        var statusTag = parseInt(u.status, 10) === 1
            ? '<span class="ow-tag ow-tag-green">正常</span>'
            : '<span class="ow-tag ow-tag-red">已禁用</span>';

        return '<div class="owum-phead">'
            + avatar(u)
            + '<div class="owum-pid">'
            + '<div class="owum-pname">' + esc(u.nickname) + roleTag + statusTag + '</div>'
            + '<div class="owum-psub">ID ' + esc(fmtUid(u.id))
            + (u.title ? ' · ' + esc(u.title) : '')
            + (parseInt(u.level, 10) >= 1 ? ' · 等级 Lv.' + parseInt(u.level, 10) : '')
            + '</div></div>'
            + '<div class="owum-phead-act">'
            + (parseInt(u.status, 10) === 1 && !isRoot
                ? '<button class="ow-btn ow-btn-ghost ow-btn-mini" onclick="OwUM.status(' + u.id + ',0)">禁用账号</button>'
                : (isRoot ? '' : '<button class="ow-btn ow-btn-ghost ow-btn-mini" onclick="OwUM.status(' + u.id + ',1)">恢复账号</button>'))
            + '</div></div>';
    }

    /** 归属地文案：有值显示值；还在解析队列里显示「解析中…」；否则「未解析」 */
    function geoText(r) {
        if (r.geo) return r.geo;
        return r._pending ? '解析中…' : '未解析';
    }

    /** 四张主卡：最近登录 IP / 归属地 / 登录成败 / 关联账号 */
    function heroHtml(n) {
        var ips = n.ips || [];
        var first = ips.length ? ips[0] : null;
        var s = n.summary || {};
        var sharedUids = {};
        var shared = n.shared || [];
        for (var i = 0; i < shared.length; i++) sharedUids[shared[i].uid] = true;
        var sharedN = 0, k;
        for (k in sharedUids) if (sharedUids.hasOwnProperty(k)) sharedN++;

        // 1) 最近登录 IP（命中封禁时整卡红边 + 「已封禁」胶囊 + 解封按钮）
        //    「自己正在用的地址」不给封禁入口 —— 封了等于把自己关在门外（服务端也会拒）
        var c1act = '';
        if (first) {
            if (first.banned) {
                c1act = '<button class="ow-btn ow-btn-ghost ow-btn-mini" onclick="OwUM.unban(\''
                    + esc(first.ip) + '\')">解封</button>';
            } else if (isMyIp(first.ip)) {
                c1act = '<span class="owum-pill" title="这是你当前登录使用的地址，不能封禁">当前 IP</span>';
            } else {
                c1act = '<button class="ow-btn ow-btn-danger ow-btn-mini" onclick="OwUM.banDialog(\''
                    + esc(first.ip) + '\')">封禁此 IP</button>';
            }
        }
        var c1 = '<div class="owum-hcard' + (first && first.banned ? ' is-banned' : '') + '">'
            + '<div class="owum-hk">最近登录 IP</div>'
            + '<div class="owum-hv owum-mono">' + (first ? esc(first.ip) : '—') + '</div>'
            + '<div class="owum-hf">'
            + (first ? chip(geoText(first)) : '')
            + (first && first.banned ? pill('已封禁', 'red') : '')
            + c1act
            + '</div></div>';

        // 2) 归属地（运营商一并显示，能看出是不是机房的地址）
        var c2 = '<div class="owum-hcard">'
            + '<div class="owum-hk">物理位置</div>'
            + '<div class="owum-hv' + (first && first.geo ? '' : ' owum-hv-muted') + '">'
            + esc(first ? geoText(first) : '—') + '</div>'
            + '<div class="owum-hf"><span style="color:var(--ow-text-sub);font-size:12px">'
            + (first && first.last_at ? '最近活跃 ' + esc(ago(first.last_at)) : '暂无登录记录')
            + '</span></div></div>';

        // 3) 登录成败
        var c3 = '<div class="owum-hcard">'
            + '<div class="owum-hk">登录验证</div>'
            + '<div class="owum-hv owum-hv-num">' + (parseInt(s.ok_n, 10) || 0)
            + ' <span class="owum-hv-sub">次成功</span></div>'
            + '<div class="owum-hf">'
            + (parseInt(s.fail_n, 10) > 0
                ? '<span style="font-size:12px;color:var(--ow-orange)">失败 ' + parseInt(s.fail_n, 10) + ' 次</span>'
                : '<span class="owum-hv-sub" style="color:var(--ow-text-sub);font-size:12px">没有失败记录</span>')
            + '</div></div>';

        // 4) 关联账号
        var c4 = '<div class="owum-hcard">'
            + '<div class="owum-hk">同 IP 关联账号</div>'
            + '<div class="owum-hv owum-hv-num">' + sharedN + ' <span class="owum-hv-sub">个</span></div>'
            + '<div class="owum-hf"><span style="font-size:12px;color:var(--ow-text-sub)">'
            + (sharedN > 0 ? '可能存在小号，点下方条目可直接跳转' : '这些地址目前只有该账号在用')
            + '</span></div></div>';

        return '<div class="owum-hero">' + c1 + c2 + c3 + c4 + '</div>';
    }

    /** 六个小指标 */
    function tilesHtml(n) {
        var s = n.summary || {};
        function t(v, l, txt) {
            return '<div class="owum-tile"><b' + (txt ? ' class="owum-tile-txt"' : '') + '>' + v + '</b><span>' + esc(l) + '</span></div>';
        }
        return '<div class="owum-tiles">'
            + t(parseInt(s.ip_n, 10) || 0, '登录 IP 数')
            + t(parseInt(s.ua_n, 10) || 0, '设备数')
            + t(s.first_at ? esc(fmtShort(s.first_at)) : '—', '首次出现', 1)
            + t(s.last_at ? esc(ago(s.last_at)) : '—', '最近活跃', 1)
            + t('<span class="owum-mono">' + esc(s.reg_ip || '—') + '</span>', '注册 IP', 1)
            + t(parseInt(n.user.status, 10) === 1 ? '正常' : '已禁用', '账号状态')
            + '</div>';
    }

    /** 登录网络明细 */
    function netsHtml(n) {
        var ips = n.ips || [];
        if (!ips.length) {
            return '<div class="owum-empty">还没有这个账号的登录网络记录'
                + (n.geo_on ? '' : '（插件启用前的历史登录不会被补记）') + '</div>';
        }
        var h = '<div class="owum-nets">';
        for (var i = 0; i < ips.length; i++) {
            var r = ips[i];
            var meta = [];
            if (r.first_at) meta.push('首次 ' + fmtShort(r.first_at));
            if (r.last_at) meta.push('最近 ' + fmtShort(r.last_at));
            // 次数为 0 的行是「注册 IP / 最后登录 IP」补出来的历史地址，只说明来源，不编造次数
            var cnt = '成功 ' + (parseInt(r.ok_n, 10) || 0) + ' / 失败 ' + (parseInt(r.fail_n, 10) || 0);
            h += '<div class="owum-net' + (r.banned ? ' is-banned' : '') + '">'
                + '<div class="owum-net-main">'
                + '<div class="owum-net-line">'
                + '<span class="owum-net-ip owum-mono">' + esc(r.ip) + '</span>'
                + chip(geoText(r))
                + (r.kind === 'reg' ? pill('注册 IP') : '')
                + (r.banned ? pill('已封禁', 'red') : '')
                + '</div>'
                + '<div class="owum-net-meta">' + esc(meta.join(' · '))
                + ' · ' + esc(cnt)
                + (parseInt(r.fail_n, 10) > 0 ? ' · <em>存在失败尝试</em>' : '')
                + '</div>'
                + (r.ua ? '<div class="owum-net-ua" title="' + esc(r.ua) + '">' + esc(r.ua) + '</div>' : '')
                + '</div>'
                + '<div class="owum-net-act">' + ipAction(r.ip, r.banned) + '</div></div>';
        }
        return h + '</div>';
    }

    /** 同 IP 的其他账号 */
    function sharedHtml(n) {
        var list = n.shared || [];
        if (!list.length) return '<div class="owum-empty">没有发现共用同一 IP 的其他账号</div>';
        var h = '<div class="owum-shared">';
        for (var i = 0; i < list.length; i++) {
            var r = list[i];
            h += '<span class="owum-shared-item" onclick="OwUM.openUser(' + parseInt(r.uid, 10) + ')">'
                + (r.avatar ? '<img src="' + esc(r.avatar) + '" alt="" loading="lazy" decoding="async">' : '')
                + esc(r.nickname)
                + ' <i>' + esc(r.ip) + '</i></span>';
        }
        return h + '</div>';
    }

    /** 账号信息（沿用核心 .ow-card-meta 的键值行） */
    function metaHtml(u) {
        function row(k, v) {
            return '<div class="ow-card-meta-row"><span class="ow-card-meta-k">' + esc(k)
                + '</span><span class="ow-card-meta-v">' + v + '</span></div>';
        }
        return '<div class="ow-card-meta owum-meta">'
            + row('邮箱', esc(u.email || '—'))
            + row(pn(), esc(String(parseInt(u.points, 10) || 0)))
            + row('注册时间', u.created_at ? esc(fmtTs(u.created_at)) + ' <span style="color:var(--ow-text-sub)">（' + esc(ago(u.created_at)) + '）</span>' : '—')
            + row('注册 IP', '<span class="owum-mono">' + esc(u.reg_ip || '—') + '</span>')
            + row('最后登录', u.last_login
                ? '<b>' + esc(ago(u.last_login)) + '</b> ' + esc(fmtTs(u.last_login))
                  + ' <span class="owum-mono" style="color:var(--ow-text-sub)">' + esc(u.last_login_ip || '') + '</span>'
                : '从未登录')
            + '</div>';
    }

    /** 整窗重绘（数据任一变化都走这里，避免两块 DOM 状态不一致） */
    function paint() {
        var box = $('owumBody');
        if (!box || !st.user) return;
        var h = headHtml(st.user);
        if (st.net) {
            h += heroHtml(st.net) + tilesHtml(st.net)
                + '<div class="owum-sec">登录网络<span>' + (st.net.ips || []).length + ' 个地址</span></div>'
                + netsHtml(st.net)
                + '<div class="owum-sec">同 IP 的其他账号<span>' + (st.net.shared || []).length + ' 个</span></div>'
                + sharedHtml(st.net);
        } else {
            h += '<div class="owum-empty">正在读取登录网络…</div>';
        }
        h += '<div class="owum-sec">账号信息</div>' + metaHtml(st.user);
        box.innerHTML = h;
    }

    function openPanel(u) {
        st.user = u;
        st.net = null;
        st.uid = u.id;
        var mask = d.createElement('div');
        mask.className = 'ow-modal-mask';
        mask.style.display = 'flex';
        mask.id = 'owumPanel';
        mask.innerHTML = '<div class="ow-modal owum-modal" style="width:720px;max-width:96%">'
            + '<button class="ow-modal-close">✕</button>'
            + '<div id="owumBody"></div></div>';
        d.body.appendChild(mask);
        var close = function () {
            if (mask.parentNode) d.body.removeChild(mask);
            st.uid = 0; st.net = null; st.user = null;
        };
        mask.querySelector('.ow-modal-close').onclick = close;
        mask.onclick = function (e) { if (e.target === mask) close(); };
        paint();
    }

    /* ============================================================
       对外 API
       ============================================================ */
    w.OwUM = {
        /* 初始化：后台页每次注入都是新 DOM，靠 data-owum-inited 幂等 */
        init: function () {
            bindTabs();
            var el = $('owumBanIp');
            if (el) el.oninput = syncBanForm;
            OwUM.loadBans();
        },

        /* 分栏切换 */
        tab: function (name) {
            var bar = $('owumTabs');
            if (!bar) return;
            var btns = bar.getElementsByTagName('button');
            for (var i = 0; i < btns.length; i++) {
                var on = btns[i].getAttribute('data-owum-tab') === name;
                btns[i].className = 'ow-tab' + (on ? ' is-active' : '');
            }
            var pu = $('owumPaneUser'), pi = $('owumPaneIp');
            if (pu) pu.className = 'owum-pane' + (name === 'user' ? '' : ' owum-hide');
            if (pi) pi.className = 'owum-pane' + (name === 'ip' ? '' : ' owum-hide');
        },

        /* 搜索：仅数字用户 ID 精确查询；非法输入服务端返回 {ok:false} 需拦截提示 */
        search: function () {
            var q = $('owAQ');
            if (!q) return;
            OwApi.post('plugin_user_manager_search', { q: q.value }, function (r) {
                var box = $('owAResult');
                if (!r.ok) { toast(r.msg); if (box) box.innerHTML = ''; return; }
                // 等级是否可用：任一用户带回了 ≥1 的等级就说明装了等级插件
                var hasLevel = false, i;
                for (i = 0; i < r.data.length; i++) {
                    if (parseInt(r.data[i].level, 10) >= 1) { hasLevel = true; break; }
                }
                var th = '<tr><th>ID</th><th>昵称</th><th>邮箱</th><th>角色</th><th>称号</th>'
                       + '<th>' + esc(pn()) + '</th>' + (hasLevel ? '<th>等级</th>' : '') + '<th>状态</th><th>操作</th></tr>';
                var h = '<div class="ow-card"><table class="ow-table">' + th;
                for (i = 0; i < r.data.length; i++) {
                    var u = r.data[i];
                    var isRoot = u.id === 1;   // 超级管理员：用户组锁定、不可禁用（v1.0.88）
                    h += '<tr><td>' + esc(fmtUid(u.id)) + '</td><td>' + esc(u.nickname) + '</td><td>' + esc(u.email) + '</td>'
                       + '<td>' + (isRoot
                           ? '<span class="ow-tag ow-tag-admin">超级管理员 · 锁定</span>'
                           : '<select class="ow-input" id="owUR' + u.id + '">'
                           + opts(ROLE_CN, ['member', 'vip', 'admin'], u.role)
                           + '</select>') + '</td>'
                       + '<td><input class="ow-input" id="owUT' + u.id + '" value="' + esc(u.title || '') + '"></td>'
                       + '<td><input class="ow-input" id="owUP' + u.id + '" value="' + esc(u.points || 0) + '" style="width:88px"></td>'
                       + (hasLevel
                           ? '<td><input class="ow-input" id="owUL' + u.id + '" type="number" min="1"'
                             + ' value="' + esc(parseInt(u.level, 10) >= 1 ? u.level : 1) + '" style="width:72px"></td>'
                           : '')
                       + '<td>' + (u.status == 1 ? '正常' : '禁用') + '</td>'
                       + '<td><a href="javascript:;" onclick="OwUM.detail(' + u.id + ')">安全画像</a>'
                       + ' <a href="javascript:;" onclick="OwUM.save(' + u.id + ')">保存</a>'
                       + (isRoot ? '' : ' <a href="javascript:;" onclick="OwUM.status(' + u.id + ','
                           + (u.status == 1 ? 0 : 1) + ')">' + (u.status == 1 ? '禁用' : '启用') + '</a>')
                       + '</td></tr>';
                }
                if (!r.data.length) {
                    if (r.hint) toast(r.hint);
                    h += '<tr><td colspan="' + (hasLevel ? 9 : 8)
                       + '" style="color:var(--ow-text-sub)">无匹配用户</td></tr>';
                }
                if (box) box.innerHTML = h + '</table></div>';
            });
        },

        /* ---------- 安全画像：概览先出，网络随后补 ---------- */
        detail: function (id) {
            OwApi.post('plugin_user_manager_detail', { id: id }, function (r) {
                if (!r.ok) { toast(r.msg); return; }
                openPanel(r.data);
                OwUM.loadNet(id);
            });
        },

        /* 换个账号看：面板已开着时直接重开，不叠两层 */
        openUser: function (id) {
            var mask = $('owumPanel');
            if (mask && mask.parentNode) d.body.removeChild(mask);
            OwUM.detail(id);
        },

        loadNet: function (id) {
            OwApi.post('plugin_user_manager_net', { id: id }, function (r) {
                if (st.uid !== id) return;          // 面板已换人或已关闭：丢弃过期响应
                if (!r.ok) { toast(r.msg); return; }
                st.net = r.data;
                if (r.data.my_ip) st.myIp = r.data.my_ip;
                // 服务端把「还没有归属地」的地址挑出来了：这里打上待解析标记，
                // 渲染时显示「解析中…」而不是「未解析」，用户才知道有东西正在进行。
                var pend = {};
                var list = r.data.pending || [], i;
                for (i = 0; i < list.length; i++) pend[list[i]] = true;
                var ips = r.data.ips || [];
                for (i = 0; i < ips.length; i++) ips[i]._pending = !!pend[ips[i].ip];
                paint();
                // 有缺归属地的地址就自动补一次（服务端每次最多 6 个、3 秒超时），
                // 失败不再重试 —— 接口本身不通时反复打只会把面板拖慢。
                if (r.data.geo_on && list.length && !st.geoBusy) {
                    OwUM.geo(id);
                }
            });
        },

        /* 补解析归属地：拿到结果原地改字段再重绘（不重新拉网络数据） */
        geo: function (id) {
            if (st.geoBusy) return;
            st.geoBusy = true;
            OwApi.post('plugin_user_manager_geo', { id: id }, function (r) {
                st.geoBusy = false;
                if (st.uid !== id || !st.net) return;
                if (!r || !r.ok) {
                    // 接口不通：把「解析中」全部收成「未解析」，别让界面永远转着
                    var all = st.net.ips || [];
                    for (var k = 0; k < all.length; k++) all[k]._pending = false;
                    paint();
                    return;
                }
                var got = r.geo || {};
                var ips = st.net.ips || [];
                for (var i = 0; i < ips.length; i++) {
                    ips[i]._pending = false;
                    if (got[ips[i].ip]) ips[i].geo = got[ips[i].ip];
                }
                paint();
            });
        },

        /* 保存角色 / 称号 / 积分 / 等级（超管无下拉框，角色固定传 admin） */
        save: function (id) {
            var roleEl = $('owUR' + id);
            var lvEl = $('owUL' + id);
            var payload = {
                id: id, role: roleEl ? roleEl.value : 'admin',
                title: $('owUT' + id).value, points: $('owUP' + id).value
            };
            // 只在等级列存在时提交：否则服务端会当成「要改等级」并报未安装插件
            if (lvEl) payload.level = lvEl.value;
            OwApi.secure('plugin_user_manager_save', payload, function (r) {
                toast(r.msg);
                if (r.ok) w.OwUM.search();
            });
        },

        /* 禁用 / 启用后重查刷新列表 */
        status: function (id, s) {
            var act = parseInt(s, 10) === 1 ? '恢复' : '禁用';
            OwAdmin.confirm(act + '这个账号？' + (parseInt(s, 10) === 1 ? '' : '禁用后该账号无法登录，已登录的会话会在下次请求被拦下。'), function () {
                OwApi.secure('plugin_user_manager_status', { id: id, status: s }, function (r) {
                    toast(r.msg);
                    w.OwUM.search();
                    if (st.uid === id) OwUM.detail(id);   // 面板开着：同步刷新状态标签
                });
            });
        },

        /* ---------- IP 封禁 ---------- */
        banDialog: function (pattern) {
            banDialog(pattern, function () {
                OwUM.loadBans();
                if (st.uid) OwUM.loadNet(st.uid);
            });
        },

        banAdd: function () {
            var ip = ($('owumBanIp').value || '').replace(/^\s+|\s+$/g, '');
            if (!ip) { toast('请输入 IP 地址'); return; }
            if (isMyIp(ip)) { syncBanForm(); toast('这是你当前使用的地址，不能封禁'); return; }
            OwApi.secure('plugin_user_manager_ban_add', {
                pattern: ip, note: $('owumBanNote').value, days: $('owumBanDays').value
            }, function (r) {
                toast(r.msg);
                if (!r.ok) return;
                $('owumBanIp').value = '';
                $('owumBanNote').value = '';
                OwUM.loadBans();
            });
        },

        unban: function (pattern) {
            OwAdmin.confirm('解封 ' + pattern + '？解封后该地址可以立即访问站点。', function () {
                OwApi.secure('plugin_user_manager_ban_del', { pattern: pattern }, function (r) {
                    toast(r.msg);
                    OwUM.loadBans();
                    if (st.uid) OwUM.loadNet(st.uid);
                });
            });
        },

        /* 规则列表 + 高风险 IP + 待解析统计，一次拉齐 */
        loadBans: function () {
            if (!$('owumBanList')) return;
            OwApi.post('plugin_user_manager_ban_list', {}, function (r) {
                if (!r || !r.ok) return;
                var data = r.data || {};
                st.bans = data;
                OwUM.paintBans(data);
            });
        },

        paintBans: function (data) {
            var now = Math.floor(Date.now() / 1000);
            if (data.my_ip) st.myIp = data.my_ip;
            syncBanForm();   // myIp 到位后才知道表单里那条会不会命中自己
            var stat = $('owumBanStat');
            if (stat) stat.textContent = '生效 ' + (data.active || 0) + ' 条 / 共 ' + (data.total || 0) + ' 条';

            /* 规则列表 */
            var box = $('owumBanList');
            if (box) {
                var bans = data.bans || [];
                if (!bans.length) {
                    box.innerHTML = '<div class="owum-empty">还没有封禁规则</div>';
                } else {
                    var h = '';
                    for (var i = 0; i < bans.length; i++) {
                        var b = bans[i];
                        var dead = !b.alive;
                        var exp = parseInt(b.expires_at, 10);
                        h += '<div class="owum-ban-row' + (dead ? ' is-dead' : '') + '">'
                            + '<div class="owum-ban-main">'
                            + '<div class="owum-ban-line"><span class="owum-ban-ip owum-mono">' + esc(b.pattern) + '</span>'
                            + (dead ? pill('已过期') : (exp > 0 ? pill('限时', 'green') : pill('永久', 'red')))
                            + '</div>'
                            + '<div class="owum-ban-meta">'
                            + (b.note ? esc(b.note) + ' · ' : '')
                            + '由 ' + esc(b.by_name || '—') + ' 于 ' + esc(fmtTs(b.created_at)) + ' 添加'
                            + (exp > 0 ? ' · ' + (dead ? '已于 ' + esc(fmtTs(exp)) + ' 到期' : Math.max(1, Math.ceil((exp - now) / 86400)) + ' 天后到期') : '')
                            + '</div></div>'
                            + '<button class="ow-btn ow-btn-ghost ow-btn-mini" onclick="OwUM.unban(\''
                            + esc(b.pattern) + '\')">' + (dead ? '删除' : '解封') + '</button>'
                            + '</div>';
                    }
                    box.innerHTML = h;
                }
            }

            /* 高风险 IP */
            var top = $('owumTopIp');
            if (top) {
                var rows = data.top || [];
                if (!rows.length) {
                    top.innerHTML = '<div class="owum-empty">还没有登录失败记录</div>';
                } else {
                    var t = '';
                    for (var j = 0; j < rows.length; j++) {
                        var r2 = rows[j];
                        t += '<div class="owum-top-row">'
                            + '<span class="owum-top-rank">' + (j + 1) + '</span>'
                            + '<div class="owum-top-main">'
                            + '<div class="owum-net-line"><span class="owum-ban-ip owum-mono">' + esc(r2.ip) + '</span>'
                            + chip(r2.geo || '未解析')
                            + (r2.banned ? pill('已封禁', 'red') : '')
                            + '</div>'
                            + '<div class="owum-ban-meta">失败 <b style="color:var(--ow-orange)">'
                            + (parseInt(r2.f, 10) || 0) + '</b> 次 · 成功 ' + (parseInt(r2.o, 10) || 0)
                            + ' 次 · 最近 ' + esc(fmtTs(r2.t)) + '</div></div>'
                            + ipAction(r2.ip, r2.banned)
                            + '</div>';
                    }
                    top.innerHTML = t;
                }
            }

            /* 归属地配置 */
            var g = data.geo || {};
            var api = $('owumGeoApi'), on = $('owumGeoOn'), pend = $('owumGeoPend');
            if (api && api.value === '') api.value = g.api || '';
            if (on) {
                on.checked = !!g.on;
                var sw = on.parentNode ? on.parentNode.querySelector('.ow-switch') : null;
                if (sw) sw.className = 'ow-switch' + (g.on ? ' is-on' : '');
            }
            if (pend) pend.textContent = '待解析 ' + (parseInt(g.pending, 10) || 0) + ' 个地址';

            // 开关点击：即时生效（一个开关还要再点保存，等于给了两次犹豫）
            if (on && !on.getAttribute('data-owum-bound')) {
                on.setAttribute('data-owum-bound', '1');
                on.onchange = function () {
                    var sw2 = this.parentNode ? this.parentNode.querySelector('.ow-switch') : null;
                    if (sw2) sw2.className = 'ow-switch' + (this.checked ? ' is-on' : '');
                    OwUM.geoSave(this.checked ? '1' : '0');
                };
            }
        },

        /* 保存归属地设置（开关走这里时只传 on，接口地址照旧读输入框） */
        geoSave: function (onFlag) {
            var onEl = $('owumGeoOn'), apiEl = $('owumGeoApi');
            var on = onFlag !== undefined ? onFlag : ((onEl && onEl.checked) ? '1' : '0');
            OwApi.post('plugin_user_manager_geo_save', {
                on: on, api: apiEl ? apiEl.value : ''
            }, function (r) {
                toast(r.msg);
                if (r.ok) OwUM.loadBans();
            });
        },

        /* 立即解析（后台按钮）：服务端一次最多 8 个 */
        geoRun: function () {
            OwApi.post('plugin_user_manager_geo_run', {}, function (r) {
                toast(r.msg);
                OwUM.loadBans();
            });
        }
    };
})(window, document);

/**
 * 后台页注入后的幂等初始化。
 * 为什么挂 data 标记而不是模块变量：SPA 换页时会整体替换 #owAdminMain，
 * 模块变量还是「已初始化」，新 DOM 却什么都没绑 —— 表现就是「第二次进来整页失灵」。
 */
(function (w, d) {
    'use strict';
    var tryInit = function () {
        var el = d.getElementById('owumTabs');
        if (el && el.getAttribute('data-owum-inited') !== '1') {
            el.setAttribute('data-owum-inited', '1');
            if (w.OwUM && w.OwUM.init) w.OwUM.init();
        }
    };
    if (typeof MutationObserver !== 'undefined') {
        var ob = new MutationObserver(tryInit);
        ob.observe(d.body, { childList: true, subtree: true });
    } else {
        setInterval(tryInit, 300);
    }
    tryInit();
})(window, document);
