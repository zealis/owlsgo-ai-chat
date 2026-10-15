/**
 * 每日签到插件 —— 前台侧栏面板
 *
 * 入口：左侧一级导航条（rail）的「签到」按钮由服务端 Plugin::collect('sidebar.rail') 产出，
 *       面板内容在这里用 OwChat.onRail({ id:'checkin', onShow }) 填充。
 *
 * 复用主程序能力：OwApi（带签名的 AJAX）、esc、toast、OwChat.confirm、OwChat.cfg。
 * 约束：纯 ES5 + XHR（不引入 fetch / 箭头函数），兼容落后内核浏览器；
 *       所有颜色走 style.css 里的类，JS 不内联任何色值。
 */
(function (w, d) {
    'use strict';

    // 后台页 / 登录页 / 安装页也会加载合并脚本，这里直接退出（幂等保护防重复注册入口）
    if (!w.OwChat || !OwChat.cfg) return;
    if (w.__owCiLoaded) return;
    w.__owCiLoaded = true;

    var A = 'plugin_daily_checkin_';
    var RING = 2 * Math.PI * 48;          // 进度环周长（r=48，见 style.css 的 SVG 尺寸）
    var WD = ['一', '二', '三', '四', '五', '六', '日'];

    var CI = {
        panel: null,
        data: null,
        busy: 0,
        month: '',
        level: null,        // 等级信任插件的「我的等级 + 今日任务」（该插件停用时为 null）
        levelOff: false     // mine 接口不可用（插件停用/未安装）→ 不再重试
    };
    w.OwCI = CI;

    /* ---------- 小工具 ---------- */

    /** 月 '2026-10' → 上下月（不越过当前月，未来没有签到记录可看） */
    function shiftMonth(m, delta) {
        var y = parseInt(m.substr(0, 4), 10), n = parseInt(m.substr(5, 2), 10) + delta;
        if (n < 1) { y--; n = 12; }
        if (n > 12) { y++; n = 1; }
        return y + '-' + (n < 10 ? '0' : '') + n;
    }

    function monthLabel(m) {
        return parseInt(m.substr(0, 4), 10) + ' 年 ' + parseInt(m.substr(5, 2), 10) + ' 月';
    }

    /* ---------- 数据 ---------- */

    CI.load = function (cb) {
        var req = {};
        if (CI.month) req.month = CI.month;
        OwApi.post(A + 'info', req, function (r) {
            if (!r || !r.ok) { if (cb) cb(null); return; }
            CI.data = r;
            CI.month = r.month || CI.month;
            paint();
            markDot();
            // 任务卡已有数据时静默刷新（不显示加载态）；首次加载由 tasksHtml 触发
            if (CI.level) loadLevel();
            if (cb) cb(r);
        });
    };

    /**
     * 首屏轻量探测：只为 ① rail 红点 ② 「每天第一次打开自动弹签到窗」。
     * 弹窗用 localStorage 记日期，同一天只弹一次 —— 关掉不签也不会被反复骚扰，
     * 而「已签到」由服务端 signed 判定，两边都不会重复弹。
     */
    CI.peek = function () {
        OwApi.post(A + 'info', {}, function (r) {
            if (!r || !r.ok) return;
            if (r.guest === 1 || r.enabled !== 1) return;      // 游客 / 未开放：不弹也不点亮
            markDot(r.signed !== 1);
            if (r.signed === 1) return;                        // 今天已签：无需打扰
            var today = r.today || '';
            var last = '';
            try { last = w.localStorage.getItem('ow_ci_pop_day') || ''; } catch (e) {}
            if (!today || last === today) return;
            try { w.localStorage.setItem('ow_ci_pop_day', today); } catch (e) {}
            // 记下待签数据，弹窗里直接展示「今日可领」，不必再打一次接口
            CI.data = r;
            CI.month = r.month || CI.month;
            CI.openPopup(r);
        });
    };

    /* ---------- 自动弹窗（模仿移动端「签到成功」弹层） ---------- */

    /** 日历 + 勾（与核心 ow_icon 同款线性风格，不用 Emoji） */
    function svgCalCheck(size, color) {
        return '<svg class="ow-ico" width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"'
            + ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
            + ' style="color:' + color + '" aria-hidden="true">'
            + '<rect x="3.5" y="5" width="17" height="15" rx="2.5"/>'
            + '<path d="M3.5 9.8h17M8 3v3.6M16 3v3.6"/>'
            + '<polyline points="8.8 14.6 11 16.8 15.4 12"/></svg>';
    }
    /** 积分币（同心圆，象征币值） */
    function svgCoin(size, color) {
        return '<svg class="ow-ico" width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"'
            + ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round"'
            + ' style="color:' + color + '" aria-hidden="true">'
            + '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="3.6"/></svg>';
    }
    /** 五角星（周期大奖） */
    function svgStar(size, color) {
        return '<svg class="ow-ico" width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none"'
            + ' stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
            + ' style="color:' + color + '" aria-hidden="true">'
            + '<path d="M12 3.6l2.6 5.3 5.8.8-4.2 4.1 1 5.8-5.2-2.7-5.2 2.7 1-5.8-4.2-4.1 5.8-.8z"/></svg>';
    }

    /**
     * 弹窗内容：待签态与成功态共用一套骨架，只有标题、奖励数值与按钮文案不同。
     * 拆成 popBody / popHtml 两份：成功态只换 box.innerHTML，不用再包一层 div。
     * @param {boolean} done    true = 签到成功态
     * @param {Object}  d       {next, points, streak, bonus}
     */
    function popBody(done, dd) {
        var amount = done ? dd.points : dd.next;
        var h = '<div class="ow-ci-pop-ico">' + svgCalCheck(30, done ? 'var(--ow-green)' : 'var(--ow-primary-strong)') + '</div>'
            + '<h3 class="ow-ci-pop-t">' + (done ? '签到成功！' : '每日签到') + '</h3>'
            + '<div class="ow-ci-pop-rewards">'
            + '<span class="ow-ci-pop-r">' + svgCoin(22, 'var(--ow-primary-strong)')
            + '<b>+' + amount + '</b><i>积分</i></span>';
        if (dd.bonus > 0) {
            h += '<span class="ow-ci-pop-r">' + svgStar(22, 'var(--ow-orange)')
                + '<b>+' + dd.bonus + '</b><i>连签大奖</i></span>';
        }
        h += '</div>'
            + '<p class="ow-ci-pop-sub">' + (done ? '已连续签到 <b>' + dd.streak + '</b> 天'
                : '今日可领 <b>' + dd.next + '</b> 积分，已连续 <b>' + dd.streak + '</b> 天') + '</p>'
            + '<button class="ow-ci-pop-btn" id="owCiPopBtn" type="button"'
            + ' onclick="OwCI.' + (done ? 'popClose' : 'popSign') + '()">' + (done ? '确定' : '立即签到') + '</button>'
            + '<a class="ow-ci-pop-link" href="javascript:;" onclick="OwCI.popGoPanel()">查看签到记录 ›</a>';
        return h;
    }

    function popHtml(done, dd) {
        return '<div class="ow-ci-pop' + (done ? ' is-done' : '') + '" id="owCiPop">' + popBody(done, dd) + '</div>';
    }

    CI.openPopup = function (r) {
        var next = (r.next && r.next.total) || 0;
        // 待签态也预告大奖：本次若正好踩中周期整数倍，把大奖那一行一并展示
        var bonus = (r.next && r.next.bonus) || 0;
        OwChat.openModal(popHtml(false, {
            next: next, streak: r.streak || 0, bonus: bonus
        }), 340, 'ow-ci-modal');
    };

    /** 弹窗内的「立即签到」—— 与侧栏面板的 doSign 分开实现：成功后弹窗切成成功态，不再关闭 */
    CI.popSign = function () {
        if (CI.busy) return;
        var btn = d.getElementById('owCiPopBtn');
        if (btn) { btn.disabled = true; btn.textContent = '签到中…'; }
        CI.busy = 1;
        OwApi.post(A + 'do', {}, function (r) {
            CI.busy = 0;
            if (!r || !r.ok) {
                if (btn) { btn.disabled = false; btn.textContent = '立即签到'; }
                toast((r && r.msg) || '签到失败');
                return;
            }
            fly(r.points);        // 侧栏面板已渲染时同步来一次上浮动画（节点不存在则自动跳过）
            markDot(false);       // 今日已签，撤掉 rail 红点
            var m = d.getElementById('owModal');
            var box = m ? m.querySelector('.ow-ci-pop') : null;
            if (box) {
                box.innerHTML = popBody(true, {
                    points: r.points, streak: r.streak, bonus: (r.parts && r.parts.bonus) || 0
                });
                box.className = 'ow-ci-pop is-done';
            }
            CI.load();            // 刷新侧栏面板数据，用户之后打开即最新
        });
    };

    CI.popClose = function () { OwChat.closeModal(); };

    /** 底部「查看签到记录」：关弹窗并切到签到面板 */
    CI.popGoPanel = function () {
        OwChat.closeModal();
        if (OwChat.switchTab) OwChat.switchTab('checkin');
    };

    /**
     * rail 入口小红点：语义与核心一致 —— 「这里有事要做」。
     * 只增删 .ow-dot 节点，不动按钮的 className（核心靠 classList 切 is-active，整体覆盖会抹掉）。
     */
    function markDot(force) {
        var bar = d.getElementById('owRail');
        if (!bar) return;
        var on = (typeof force === 'boolean') ? force
            : !!(CI.data && CI.data.enabled === 1 && CI.data.guest !== 1 && CI.data.signed !== 1);
        var btns = bar.getElementsByClassName('ow-rail-btn'), i, btn = null;
        for (i = 0; i < btns.length; i++) {
            if (btns[i].getAttribute('data-tab') === 'checkin') { btn = btns[i]; break; }
        }
        if (!btn) return;
        var dots = btn.getElementsByClassName('ow-dot');
        if (on) {
            if (!dots.length) {
                var s = d.createElement('span');
                s.className = 'ow-dot';
                btn.appendChild(s);
            }
        } else {
            for (i = dots.length - 1; i >= 0; i--) {
                if (dots[i].parentNode) dots[i].parentNode.removeChild(dots[i]);
            }
        }
    }

    /* ---------- 渲染 ---------- */

    function paint() {
        var box = CI.box;
        if (!box) return;
        var x = CI.data;
        if (!x) { box.innerHTML = '<div class="ow-ci-empty">正在加载…</div>'; return; }
        if (x.enabled !== 1) { box.innerHTML = empty('站点未开放签到', '管理员关闭了签到功能，欢迎下次再来。'); return; }
        if (x.guest === 1) { box.innerHTML = guestHtml(x); return; }
        box.innerHTML = heroHtml(x) + tasksHtml(x) + calHtml(x) + rankHtml(x);
    }

    function empty(title, desc) {
        return '<div class="ow-ci-card"><div class="ow-ci-empty">'
            + '<svg class="ow-ico" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            + ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            + '<circle cx="12" cy="12" r="9"/><polyline points="8 12.2 11 15.2 16.5 9.5"/></svg>'
            + '<b>' + esc(title) + '</b><br>' + esc(desc) + '</div></div>';
    }

    /** 游客：签到需要账号，但排行榜照常展示（让大家看到这个功能是活的） */
    function guestHtml(x) {
        return '<div class="ow-ci-card"><div class="ow-ci-empty">'
            + '<b>登录后即可签到</b><br>签到记录绑定账号，游客身份换浏览器就没了，因此不开放。'
            + '</div></div>' + calHtml(x) + rankHtml(x, true);
    }

    function heroHtml(x) {
        var cyc = x.cfg.cycle_days;
        var inCycle = 0;
        if (cyc > 0) {
            inCycle = x.streak % cyc;
            if (x.streak > 0 && inCycle === 0) inCycle = cyc;   // 刚满一轮：整环
        }
        var p = cyc > 0 ? (inCycle / cyc) : 0;
        var ring = '';
        if (cyc > 0) {
            ring = '<svg viewBox="0 0 104 104" aria-hidden="true">'
                + '<circle class="ow-ci-ring-bg" cx="52" cy="52" r="48"></circle>'
                + '<circle class="ow-ci-ring-fg' + (x.signed === 1 ? ' is-done' : '') + '" cx="52" cy="52" r="48"'
                + ' stroke-dasharray="' + RING.toFixed(1) + '"'
                + ' stroke-dashoffset="' + (RING * (1 - p)).toFixed(1) + '"></circle></svg>';
        }

        var btnInner, btnCls = 'ow-ci-btn', dis = '';
        if (x.signed === 1) {
            // 已签：只留文字，勾由 CSS 伪元素画（::before）—— <button> 内嵌 SVG 在老内核里渲染脆，
            // 且多一个 DOM 节点多一处错位风险
            btnCls += ' is-done';
            dis = ' disabled';
            btnInner = '<i>已签到</i>';
        } else {
            btnInner = '签到<i>+' + x.next.total + ' 积分</i>';
        }

        var h = '<div class="ow-ci-card ow-ci-hero">'
            + '<div class="ow-ci-hero-top"><span class="ow-ci-hero-t">每日签到</span>'
            + '<span class="ow-ci-pill">积分 ' + x.my_points + '</span></div>'
            + '<div class="ow-ci-hero-sub">'
            + (x.signed === 1 ? '今日已签到' : (x.streak > 0 ? '已连续 ' + x.streak + ' 天，别断了' : '今天还没签到'))
            + '</div>'
            + '<div class="ow-ci-ring" id="owCiRing">' + ring
            + '<button class="' + btnCls + '" id="owCiBtn" type="button"' + dis
            + ' onclick="OwCI.doSign()">' + btnInner + '</button></div>'
            + '<div class="ow-ci-stats">'
            + '<div class="ow-ci-stat"><b>' + x.streak + '</b><span>连续天数</span></div>'
            + '<div class="ow-ci-stat"><b>' + x.total_days + '</b><span>累计签到</span></div>'
            + '<div class="ow-ci-stat"><b>' + x.earned + '</b><span>累计积分</span></div>'
            + '</div>';

        // 周期点阵：一个点 = 周期内的第 N 天，最后一格（大奖）加高亮
        if (cyc > 0) {
            h += '<div class="ow-ci-dots">';
            for (var i = 1; i <= cyc; i++) {
                h += '<span class="ow-ci-dot' + (i <= inCycle ? ' on' : '') + (i === cyc ? ' hit' : '') + '"></span>';
            }
            h += '</div>';
        }

        h += '<div class="ow-ci-tip">' + tipHtml(x) + '</div>';

        // 补签：只在真的有缺口时出现（服务端已判过成本、次数与断签状态）
        if (x.patch && x.patch.can === 1) {
            h += '<button class="ow-btn ow-btn-ghost ow-ci-patch" type="button" onclick="OwCI.doPatch()">'
                + '补签 ' + esc(x.patch.day.substr(5)) + '（消耗 ' + x.patch.cost + ' 积分）</button>';
        }
        h += '</div>';
        return h;
    }

    function tipHtml(x) {
        var t = '';
        if (x.signed === 1) {
            t = '今日已签到，获得 <b>' + x.got + '</b> 积分。明天再来可领 <b>' + x.next.total + '</b> 分。';
        } else {
            t = '今天签到可领 <b>' + x.next.total + '</b> 积分';
            if (x.next.bonus > 0) t += '（含周期大奖 <b>' + x.next.bonus + '</b> 分）';
            else if (x.cfg.cycle_days > 0) t += '，再连签 <b>' + (x.cycle_left || 0) + '</b> 天可拿周期大奖 <b>' + x.cfg.cycle_bonus + '</b> 分';
            t += '。';
        }
        if (x.patch && x.patch.can !== 1 && x.patch.reason) {
            t += '<br>补签：' + esc(x.patch.reason);
        } else if (x.patch && x.patch.can === 1) {
            t += '<br>本月还可补签 <b>' + x.patch.left + '</b> 次。';
        }
        return t;
    }

    function calHtml(x) {
        var m = x.month || '';
        if (!m) return '';
        var y = parseInt(m.substr(0, 4), 10), n = parseInt(m.substr(5, 2), 10);
        var first = new Date(y, n - 1, 1).getDay();      // 0=周日 → 转成周一开头的偏移
        var off = (first + 6) % 7;
        var total = new Date(y, n, 0).getDate();
        var canNext = (m < x.today.substr(0, 7));        // 不允许翻到未来月份

        var h = '<div class="ow-ci-card"><div class="ow-ci-h"><span>' + monthLabel(m) + '</span>'
            + '<span><a href="javascript:;" onclick="OwCI.goMonth(-1)" title="上一月">‹</a> '
            + (canNext ? '<a href="javascript:;" onclick="OwCI.goMonth(1)" title="下一月">›</a>' : '')
            + '</span></div><div class="ow-ci-cal-head">';
        for (var i = 0; i < 7; i++) h += '<span>' + WD[i] + '</span>';
        h += '</div><div class="ow-ci-cal">';

        for (var b = 0; b < off; b++) h += '<span class="ow-ci-cell blank"><i></i></span>';
        for (var day = 1; day <= total; day++) {
            var ds = m + '-' + (day < 10 ? '0' : '') + day;
            var cls = 'ow-ci-cell';
            if (x.days && x.days[ds]) cls += ' on';
            if (ds === x.today) cls += ' today';
            h += '<span class="' + cls + '"><i>' + day + '</i></span>';
        }
        h += '</div>';
        h += '<div class="ow-ci-tip">本月已签到 <b>' + countMonth(x) + '</b> 天。</div>';
        h += '</div>';
        return h;
    }

    function countMonth(x) {
        var n = 0, k;
        if (!x.days) return 0;
        for (k in x.days) if (x.days.hasOwnProperty(k)) n++;
        return n;
    }

    function rankHtml(x, noMe) {
        var list = x.rank || [];
        var h = '<div class="ow-ci-card"><div class="ow-ci-h"><span>本月签到榜</span>'
            + '<em>按当月天数</em></div>';
        if (!list.length) {
            h += '<div class="ow-ci-empty">本月还没有人签到，抢个头名。</div>';
            return h + '</div>';
        }
        var meId = (OwChat.cfg.actor || {}).id || 0;
        for (var i = 0; i < list.length; i++) {
            var r = list[i];
            var cls = 'ow-ci-rank-i' + (!noMe && r.uid === meId ? ' me' : '');
            var no = i + 1;
            h += '<div class="' + cls + '">'
                + '<span class="ow-ci-rank-n' + (no <= 3 ? ' t' + no : '') + '">' + no + '</span>'
                + '<img class="ow-ci-rank-av" src="' + esc(r.avatar) + '" alt="" loading="lazy">'
                + '<span class="ow-ci-rank-t">' + esc(r.nickname) + '</span>'
                + '<span class="ow-ci-rank-d">' + r.days + ' 天</span>'
                + '</div>';
        }
        return h + '</div>';
    }

    /* ---------- 今日任务（数据来自等级信任插件，v1.1.0） ---------- */

    /**
     * 等级信任插件的「今日任务」卡：保底任务逐条展示进度。
     *
     * 三种不展示的情形，都不占位、不影响签到面板本身：
     *   ① 游客（没有等级）；② 等级插件未启用（OwLevel 全局不存在）；
     *   ③ mine 接口不可用（levelOff，插件停用/未安装时 action 未注册）。
     * 任务名取 OwLevel.LABEL（等级插件自带的中文表），避免两处各写一份漂移。
     */
    function tasksHtml(x) {
        if (x.guest === 1) return '';
        if (!w.OwLevel || !OwLevel.LABEL) return '';
        if (CI.levelOff) return '';
        if (!CI.level) {
            loadLevel();
            return '<div class="ow-ci-card" id="owCiTasks"><div class="ow-ci-h"><span>今日任务</span></div>'
                + '<div class="ow-ci-empty">加载中…</div></div>';
        }
        return '<div class="ow-ci-card" id="owCiTasks">' + tasksInner(CI.level) + '</div>';
    }

    function loadLevel() {
        if (!w.OwLevel || !OwLevel.LABEL || CI.levelOff) return;
        OwApi.post('plugin_level_trust_mine', {}, function (r) {
            if (!r || !r.ok) { CI.levelOff = true; paintTasks(); return; }
            CI.level = r;
            paintTasks();
        });
    }

    /** 只重绘任务卡：不碰月历/排行榜，避免整面板重排把用户的滚动位置顶飞 */
    function paintTasks() {
        var box = d.getElementById('owCiTasks');
        if (!box) return;
        if (CI.levelOff || !CI.level) {
            if (box.parentNode) box.parentNode.removeChild(box);
            return;
        }
        box.innerHTML = tasksInner(CI.level);
    }

    function tasksInner(L) {
        var LABEL = OwLevel.LABEL;
        var list = L.tasks || [];
        var done = 0, i, t, okk;
        var h = '<div class="ow-ci-h"><span>今日任务</span><em>Lv.' + L.level
            + ' · ' + esc(L.stage) + '</em></div><div class="ow-ci-tasks">';
        for (i = 0; i < list.length; i++) {
            t = list[i];
            okk = t.cur >= t.n;
            if (okk) done++;
            h += '<div class="ow-ci-task">'
                + '<span class="ow-ci-task-k">' + esc(LABEL[t.k] || t.k)
                + (t.alt ? '<i class="ow-ci-task-alt" title="可替代任务">替</i>' : '') + '</span>'
                + '<span class="ow-ci-task-bar"><i class="' + (okk ? 'done' : '') + '" style="width:'
                + (t.n > 0 ? Math.min(100, Math.round(t.cur / t.n * 100)) : 0) + '%"></i></span>'
                + '<span class="ow-ci-task-v' + (okk ? ' done' : '') + '">' + t.cur + ' / ' + t.n + '</span>'
                + '</div>';
        }
        h += '</div>';
        var all = list.length > 0 && done >= list.length;
        // v1.1.1：等级信任 v2.0 起「完成一天任务 = 记 1 个活跃天数」，按倍率换算等级
        // （默认 1.3，后台可调），不再是固定的「升 1 级」——这里不写死任何数字，
        // 想要准确换算的观众点「等级规则」看公式。
        h += '<div class="ow-ci-tip">保底任务完成 <b>' + done + ' / ' + list.length + '</b>，';
        if (L.frozen) {
            h += '今日已被管理员冻结，进度暂停。';
        } else if (all) {
            h += '今天记 1 个活跃天数' + (L.settled ? '（<b style="color:var(--ow-green)">今日已结算</b>）' : '') + '。';
        } else {
            h += '全部完成今天记 1 个活跃天数。';
        }
        h += ' <a href="?page=level">等级规则 ›</a></div>';
        return h;
    }

    /* ---------- 交互 ---------- */

    /** 上/下一月：不越过当前月 */
    CI.goMonth = function (delta) {
        var cur = CI.month || (CI.data && CI.data.month) || '';
        if (!cur) return;
        var next = shiftMonth(cur, delta);
        if (next > (CI.data ? CI.data.today.substr(0, 7) : '')) return;
        CI.month = next;
        CI.load();
    };

    /** 签到成功后的「+N 分」上浮动画 */
    function fly(n) {
        var ring = d.getElementById('owCiRing');
        if (!ring || !n) return;
        var el = d.createElement('div');
        el.className = 'ow-ci-fly';
        el.textContent = '+' + n;
        ring.appendChild(el);
        setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 1200);
    }

    CI.doSign = function () {
        if (CI.busy) return;
        var btn = d.getElementById('owCiBtn');
        if (!btn || btn.className.indexOf('is-done') >= 0) return;
        CI.busy = 1;
        btn.disabled = true;
        OwApi.post(A + 'do', {}, function (r) {
            CI.busy = 0;
            if (!r || !r.ok) {
                if (btn) btn.disabled = false;
                toast((r && r.msg) || '签到失败');
                return;
            }
            toast(r.msg);
            fly(r.points);
            CI.month = '';                 // 回到当前月，避免停在历史月份看不到今天
            CI.load();
        });
    };

    CI.doPatch = function () {
        if (CI.busy) return;
        var x = CI.data;
        if (!x || !x.patch || x.patch.can !== 1) return;
        var cost = x.patch.cost;
        OwChat.confirm('补签 ' + x.patch.day + ' 将消耗 ' + cost + ' 积分（本月还剩 '
            + x.patch.left + ' 次），连续天数得以延续。确定补签？', function () {
            CI.busy = 1;
            OwApi.secure(A + 'patch', {}, function (r) {
                CI.busy = 0;
                toast((r && r.msg) || '补签失败');
                if (r && r.ok) CI.load();
            });
        });
    };

    /* ---------- 挂载 ---------- */

    OwChat.onRail({
        id: 'checkin',
        label: '每日签到',
        onShow: function (panel) {
            CI.panel = panel;
            // 幂等：面板已建过就只刷数据，避免重复插入骨架
            CI.box = d.getElementById('owCiBox');
            if (!CI.box) {
                panel.innerHTML = '<div class="ow-ci" id="owCiBox"><div class="ow-ci-empty">正在加载…</div></div>';
                CI.box = d.getElementById('owCiBox');
            }
            CI.month = '';
            CI.load();
        }
    });

    // 首屏只为红点探一次状态（延迟一点，别和聊天区的首屏请求抢）
    setTimeout(function () { CI.peek(); }, 1200);
})(window, document);
