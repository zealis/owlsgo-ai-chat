/**
 * 星光殿堂 —— 前台（侧栏 rail 面板 + 资料卡等级行）
 *
 * 入口：左侧一级导航条的「星光殿堂」按钮由服务端 Plugin::collect('sidebar.rail')
 *       产出（日历打卡图标），面板内容在这里用 OwChat.onRail({ id:'hall', onShow }) 填充。
 *
 * 数据只有一个接口：plugin_starlight_hall_panel。
 *   合并前这里是三个接口（签到的 info / 等级的 mine / 荣誉榜 rank），前台要连打三次、
 *   还要各自处理「谁先回来」；并成一个插件之后没有理由继续拆 —— 一次请求、一次渲染。
 *
 * 复用主程序能力：OwApi（带签名的 AJAX）、esc、toast、OwChat.confirm/openModal/
 * userCard/switchTab、OwChat.onCardMetaTop。不要重复实现这些。
 *
 * 约束：纯 ES5 + XHR（不引入 fetch / 箭头函数 / let），兼容落后内核浏览器；
 *       所有颜色走 style.css 里的类，JS 不内联任何色值（只内联数字，如进度宽度）。
 */
(function (w, d) {
    'use strict';

    // 后台页 / 登录页 / 安装页也会加载合并脚本，这里直接退出（幂等保护防重复注册入口）
    if (!w.OwChat || !OwChat.cfg) return;
    if (w.__owShLoaded) return;
    w.__owShLoaded = true;

    var A = 'plugin_starlight_hall_';
    var RING = 2 * Math.PI * 48;          // 进度环周长（r=48，见 style.css 的 SVG 尺寸）
    var WD = ['一', '二', '三', '四', '五', '六', '日'];

    var SH = {
        box: null,
        panel: null,
        data: null,
        busy: 0,
        month: ''
    };
    w.OwSH = SH;

    /* ---------- 小工具 ---------- */

    /**
     * v1.5.4：「积分」是站点可改的名词（核心 系统设置 → 基本信息 → 积分名称）。
     * 前台所有文案都走这里，站点把它叫「金币 / 星光」时签到面板跟着换。
     * ⚠️ 返回纯文本，拼 HTML 时自己 esc()。
     */
    function pn() {
        return (w.OwChat && w.OwChat.pointsName) ? w.OwChat.pointsName() : '积分';
    }

    function num(v) {
        var n = parseFloat(v);
        if (!isFinite(n)) n = 0;
        var s = n.toFixed(2).replace(/\.?0+$/, '');
        return s === '' || s === '-' ? '0' : s;
    }

    /** 秒 → 中文时长（与 PHP 侧 owSHLFmtDur 同一口径，避免为一个格式化再打接口） */
    function fmtDur(sec) {
        sec = parseInt(sec, 10) || 0;
        if (sec < 60) return sec + ' 秒';
        var m = Math.floor(sec / 60);
        if (m < 60) return m + ' 分';
        var hh = Math.floor(m / 60), mm = m % 60;
        if (hh < 24) return hh + ' 小时' + (mm > 0 ? ' ' + mm + ' 分' : '');
        var dd = Math.floor(hh / 24), rh = hh % 24;
        return dd + ' 天' + (rh > 0 ? ' ' + rh + ' 小时' : '');
    }

    /**
     * 秒 → 紧凑时长（面板里的「等级来源」用）。
     * 侧栏只有 240px，一行要塞「在线 / 任务 / 设定 / 消退」四项，
     * 所以小时数压成一位小数（22 小时 12 分 → 22.2 时）；完整时长在 ?page=level 有。
     */
    function fmtDurShort(sec) {
        sec = parseInt(sec, 10) || 0;
        if (sec < 3600) return fmtDur(sec);
        return (sec / 3600).toFixed(1).replace(/\.0$/, '') + ' 时';
    }

    /** 等级徽章 HTML（与 PHP 侧 .ow-lt-badge 的类名约定一致，level-cultivation 会接管它） */
    function badge(lv, stageNo, honor) {
        return '<span class="ow-lt-badge ow-lt-s' + (stageNo || 1) + (honor ? ' ow-lt-honor' : '')
            + '">Lv.' + lv + '</span>';
    }
    SH.badge = badge;

    /**
     * 主卡里的大号等级牌 —— **刻意不用 .ow-lt-badge**。
     * 装了 level-cultivation 的站点会把全站 .ow-lt-badge 的文字替换成修仙境界图标
     * （那个插件就是干这个的），主卡里的徽章一旦被换掉，面板上就再没有地方写「当前几级」了。
     * 与本插件榜单里 .ow-sh-rank-lv 的处理同一条理由：纯文本在「装了/没装」两种站点上都成立。
     * 资料卡那一行仍走 badge()/.ow-lt-badge —— 那正是 level-cultivation 该接管的地方。
     */
    function lvChip(lv, stageNo, honor) {
        return '<span class="ow-sh-lvb s' + (stageNo || 1) + (honor ? ' honor' : '') + '">Lv.' + lv + '</span>';
    }

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

    /**
     * 把字符串序列化成可安全嵌进 onclick 属性里当 JS 字符串实参的字面量。
     * 核心 chat.js 的 jsAttr 是 IIFE 内部函数、没有暴露成全局，插件引用不到 ——
     * 但结论照搬适用：esc() 在「属性内嵌 JS」的上下文防不住注入（浏览器执行 onclick
     * 前会把 &#39; 解码回 '，照样闭合字符串）。昵称允许含引号，所以行内 onclick 一律经这里再拼。
     */
    function jsAttr(s) {
        return esc(JSON.stringify(String(s == null ? '' : s)));
    }

    /* ---------- 数据 ---------- */

    /**
     * 拉面板数据。
     * @param {string} [month] 指定月份（翻月历用）；不传则用当前已选月份
     */
    SH.load = function (cb) {
        var req = {};
        if (SH.month) req.month = SH.month;
        OwApi.post(A + 'panel', req, function (r) {
            if (!r || !r.ok) { if (cb) cb(null); return; }
            SH.data = r;
            SH.month = r.month || SH.month;
            paint();
            markDot();
            if (cb) cb(r);
        });
    };

    /**
     * 首屏轻量探测：只为 ① rail 红点 ② 「每天第一次打开自动弹签到窗」。
     * 弹窗用 localStorage 记日期，同一天只弹一次 —— 关掉不签也不会被反复骚扰，
     * 而「已签到」由服务端 signed 判定，两边都不会重复弹。
     */
    SH.peek = function () {
        OwApi.post(A + 'panel', {}, function (r) {
            if (!r || !r.ok) return;
            if (r.guest === 1 || r.enabled !== 1) return;      // 游客 / 未开放：不弹也不点亮
            markDot(r.signed !== 1);
            if (r.signed === 1) return;                        // 今天已签：无需打扰
            var today = r.today || '';
            var last = '';
            try { last = w.localStorage.getItem('ow_sh_pop_day') || ''; } catch (e) {}
            if (!today || last === today) return;
            try { w.localStorage.setItem('ow_sh_pop_day', today); } catch (e) {}
            // 记下待签数据，弹窗里直接展示「今日可领」，不必再打一次接口
            SH.data = r;
            SH.month = r.month || SH.month;
            SH.openPopup(r);
        });
    };

    /* ---------- 自动弹窗（模仿移动端「签到成功」弹层） ---------- */

    /** 日历 + 勾（与核心 ow_icon 同款线性风格，不用 Emoji、不用位图） */
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
     * 等级天数奖励用的小图标 = 克隆 rail 入口那枚（日历打卡）。
     * **不在这里复制一遍 path** —— PLUGIN.md 要求插件自有字形只存在于 main.php 一处，
     * 所以直接克隆服务端已经渲染好的 SVG，换尺寸与颜色即可。
     * rail 不在的页面（嵌入页等）退化成五角星，颜色口径不变。
     */
    function svgReward(size, color) {
        var src = d.querySelector('#owRail button[data-tab="hall"] svg');
        if (src && src.cloneNode) {
            var c = src.cloneNode(true);
            c.setAttribute('width', size);
            c.setAttribute('height', size);
            c.setAttribute('style', 'color:' + color);
            return c.outerHTML;
        }
        return svgStar(size, color);
    }

    /**
     * 弹窗内容：待签态与成功态共用一套骨架，只有标题、奖励数值与按钮文案不同。
     * @param {boolean} done true = 签到成功态
     * @param {Object}  dd   {next, points, streak, bonus, lvDays}
     */
    function popBody(done, dd) {
        var amount = done ? dd.points : dd.next;
        var h = '<div class="ow-sh-pop-ico">' + svgCalCheck(30, done ? 'var(--ow-green)' : 'var(--ow-primary-strong)') + '</div>'
            + '<h3 class="ow-sh-pop-t">' + (done ? '签到成功！' : '每日签到') + '</h3>'
            + '<div class="ow-sh-pop-rewards">'
            + '<span class="ow-sh-pop-r">' + svgCoin(22, 'var(--ow-primary-strong)')
            + '<b>+' + amount + '</b><i>' + esc(pn()) + '</i></span>';
        if (dd.bonus > 0) {
            h += '<span class="ow-sh-pop-r">' + svgStar(22, 'var(--ow-orange)')
                + '<b>+' + dd.bonus + '</b><i>连签大奖</i></span>';
        }
        // v1.1.0：签到的等级天数奖励 —— 与积分/大奖并列，签到与等级不再「两张皮」
        if (dd.lvDays > 0) {
            h += '<span class="ow-sh-pop-r">' + svgReward(22, 'var(--ow-primary-strong)')
                + '<b>+' + dd.lvDays + '</b><i>等级天数</i></span>';
        }
        h += '</div>'
            + '<p class="ow-sh-pop-sub">' + (done ? '已连续签到 <b>' + dd.streak + '</b> 天'
                : '今日可领 <b>' + dd.next + '</b> ' + esc(pn()) + '，已连续 <b>' + dd.streak + '</b> 天') + '</p>'
            + '<button class="ow-sh-pop-btn" id="owShPopBtn" type="button"'
            + ' onclick="OwSH.' + (done ? 'popClose' : 'popSign') + '()">' + (done ? '确定' : '立即签到') + '</button>'
            + '<a class="ow-sh-pop-link" href="javascript:;" onclick="OwSH.popGoPanel()">进入星光殿堂 ›</a>';
        return h;
    }

    function popHtml(done, dd) {
        return '<div class="ow-sh-pop' + (done ? ' is-done' : '') + '" id="owShPop">' + popBody(done, dd) + '</div>';
    }

    SH.openPopup = function (r) {
        var next = (r.next && r.next.total) || 0;
        // 待签态也预告大奖：本次若正好踩中周期整数倍，把大奖那一行一并展示
        var bonus = (r.next && r.next.bonus) || 0;
        OwChat.openModal(popHtml(false, {
            next: next, streak: r.streak || 0, bonus: bonus,
            lvDays: (r.cfg && r.cfg.rw_checkin) || 0
        }), 340, 'ow-sh-modal');
    };

    /** 弹窗内的「立即签到」—— 与面板里的 doSign 分开实现：成功后弹窗切成成功态，不再关闭 */
    SH.popSign = function () {
        if (SH.busy) return;
        var btn = d.getElementById('owShPopBtn');
        if (btn) { btn.disabled = true; btn.textContent = '签到中…'; }
        SH.busy = 1;
        OwApi.post(A + 'checkin_do', {}, function (r) {
            SH.busy = 0;
            if (!r || !r.ok) {
                if (btn) { btn.disabled = false; btn.textContent = '立即签到'; }
                toast((r && r.msg) || '签到失败');
                return;
            }
            fly(r.points);        // 面板已渲染时同步来一次上浮动画（节点不存在则自动跳过）
            markDot(false);       // 今日已签，撤掉 rail 红点
            var m = d.getElementById('owModal');
            var box = m ? m.querySelector('.ow-sh-pop') : null;
            if (box) {
                box.innerHTML = popBody(true, {
                    points: r.points, streak: r.streak, bonus: (r.parts && r.parts.bonus) || 0,
                    lvDays: (r.lv_days || (SH.data && SH.data.cfg && SH.data.cfg.rw_checkin)) || 0
                });
                box.className = 'ow-sh-pop is-done';
            }
            SH.load();            // 刷新面板数据，用户之后打开即最新
        });
    };

    SH.popClose = function () { OwChat.closeModal(); };

    /** 底部「进入星光殿堂」：关弹窗并切到面板 */
    SH.popGoPanel = function () {
        OwChat.closeModal();
        if (OwChat.switchTab) OwChat.switchTab('hall');
    };

    /**
     * rail 入口小红点：语义与核心一致 —— 「这里有事要做」。
     * 只增删 .ow-dot 节点，不动按钮的 className（核心靠 classList 切 is-active，
     * 整体覆盖会把插件的类一起抹掉）。
     */
    function markDot(force) {
        var bar = d.getElementById('owRail');
        if (!bar) return;
        var on = (typeof force === 'boolean') ? force
            : !!(SH.data && SH.data.enabled === 1 && SH.data.guest !== 1 && SH.data.signed !== 1);
        var btns = bar.getElementsByClassName('ow-rail-btn'), i, btn = null;
        for (i = 0; i < btns.length; i++) {
            if (btns[i].getAttribute('data-tab') === 'hall') { btn = btns[i]; break; }
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

    /* ============================ 渲染 ============================ */

    function paint() {
        if (!SH.box) return;
        var x = SH.data;
        if (!x) { SH.box.innerHTML = '<div class="ow-sh-empty">正在加载…</div>'; return; }
        if (x.enabled !== 1) {
            SH.box.innerHTML = empty('站点未开放签到', '管理员关闭了签到功能，欢迎下次再来。');
            return;
        }
        if (x.guest === 1) SH.box.innerHTML = guestHtml(x);
        else SH.box.innerHTML = heroHtml(x) + tasksHtml(x) + calHtml(x) + rankHtml(x) + honorHtml(x);
    }

    function empty(title, desc) {
        return '<div class="ow-sh-card"><div class="ow-sh-empty">'
            + '<svg class="ow-ico" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            + ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            + '<circle cx="12" cy="12" r="9"/><polyline points="8 12.2 11 15.2 16.5 9.5"/></svg>'
            + '<b>' + esc(title) + '</b><br>' + esc(desc) + '</div></div>';
    }

    /** 游客：签到与等级都要账号，但两个榜单照常展示（让大家看到这个功能是活的） */
    function guestHtml(x) {
        return '<div class="ow-sh-card"><div class="ow-sh-empty">'
            + '<b>登录后开启你的殿堂</b><br>签到记录与等级进度都绑定账号，游客身份换个浏览器就没了，因此不开放。'
            + '</div></div>' + rankHtml(x, true) + honorHtml();
    }

    /* ---------- 主卡：等级 + 签到 ---------- */

    function heroHtml(x) {
        var L = x.level || {};
        var p = L.prog || {};
        var lv = parseInt(L.level, 10) || 1;
        var minLv = (x.honor && parseInt(x.honor.min_level, 10)) || 60;
        var isHonor = lv >= minLv ? 1 : (L.honor ? 1 : 0);

        var h = '<div class="ow-sh-card">'
            + '<div class="ow-sh-hero-top">'
            + lvChip(lv, L.stage_no, isHonor)
            + '<span class="ow-sh-hero-stage">' + esc(L.stage || '') + '</span>'
            + '<span class="ow-sh-pill">' + esc(pn()) + ' ' + (x.my_points || 0) + '</span>'
            + '</div>';

        /* ---------- 等级进度 ---------- */
        h += '<div class="ow-sh-bar"><i style="width:' + (parseInt(p.pct, 10) || 0) + '%"></i></div>'
            + '<div class="ow-sh-bar-cap">本级 <b>' + (parseInt(p.pct, 10) || 0) + '%</b>'
            + ' · 距 Lv.' + (parseInt(p.next_level, 10) || (lv + 1)) + ' 还需 <b>'
            + num(p.gap_days) + '</b> 天</div>';

        /* ---------- 签到环 ---------- */
        var cyc = x.cfg.cycle_days;
        var inCycle = 0;
        if (cyc > 0) {
            inCycle = x.streak % cyc;
            if (x.streak > 0 && inCycle === 0) inCycle = cyc;   // 刚满一轮：整环
        }
        var pr = cyc > 0 ? (inCycle / cyc) : 0;
        var ring = '';
        if (cyc > 0) {
            ring = '<svg viewBox="0 0 104 104" aria-hidden="true">'
                + '<circle class="ow-sh-ring-bg" cx="52" cy="52" r="48"></circle>'
                + '<circle class="ow-sh-ring-fg' + (x.signed === 1 ? ' is-done' : '') + '" cx="52" cy="52" r="48"'
                + ' stroke-dasharray="' + RING.toFixed(1) + '"'
                + ' stroke-dashoffset="' + (RING * (1 - pr)).toFixed(1) + '"></circle></svg>';
        }
        var btnInner, btnCls = 'ow-sh-btn', dis = '';
        if (x.signed === 1) {
            // 已签：只留文字，勾由 CSS 伪元素画（::before）
            btnCls += ' is-done';
            dis = ' disabled';
            btnInner = '<i>已签到</i>';
        } else {
            btnInner = '签到<i>+' + x.next.total + ' ' + esc(pn()) + '</i>';
        }
        h += '<div class="ow-sh-ring" id="owShRing">' + ring
            + '<button class="' + btnCls + '" id="owShBtn" type="button"' + dis
            + ' onclick="OwSH.doSign()">' + btnInner + '</button></div>';

        /* ---------- 三格统计 ---------- */
        h += '<div class="ow-sh-stats">'
            + '<div class="ow-sh-stat"><b>' + x.streak + '</b><span>连续天数</span></div>'
            + '<div class="ow-sh-stat"><b>' + x.total_days + '</b><span>累计签到</span></div>'
            + '<div class="ow-sh-stat"><b>' + x.earned + '</b><span>签到' + esc(pn()) + '</span></div>'
            + '</div>';

        // 周期点阵：一个点 = 周期内第 N 天，最后一格（大奖）加高亮
        if (cyc > 0) {
            h += '<div class="ow-sh-dots">';
            for (var i = 1; i <= cyc; i++) {
                h += '<span class="ow-sh-dot' + (i <= inCycle ? ' on' : '') + (i === cyc ? ' hit' : '') + '"></span>';
            }
            h += '</div>';
        }

        h += '<div class="ow-sh-tip">' + tipHtml(x) + levelSrcHtml(p) + '</div>';

        // 补签：只在真的有缺口时出现（服务端已判过成本、次数与断签状态）
        if (x.patch && x.patch.can === 1) {
            h += '<button class="ow-btn ow-btn-ghost ow-sh-patch" type="button" onclick="OwSH.doPatch()">'
                + '补签 ' + esc(x.patch.day.substr(5)) + '（消耗 ' + x.patch.cost + ' ' + esc(pn()) + '）</button>';
        }
        h += '</div>';
        return h;
    }

    /**
     * 签到状态一句话。**刻意只留一句**（站方反馈面板文字太多）：
     *   · 已签 → 今天实际到手多少
     *   · 未签 → 今天能拿多少
     * 「明天可领多少」由按钮上的「签到 +N 积分」和环上的进度承担，不重复写；
     * 「在线满多少小时算 1 天」这类规则说明一律收到 ?page=level，侧栏不解释规则。
     */
    function tipHtml(x) {
        var t;
        if (x.signed === 1) {
            t = '今日已签到 <b>+' + x.got + '</b> ' + esc(pn()) + ' · <b>+' + num(x.cfg.rw_checkin) + '</b> 天';
        } else {
            t = '今天签到 <b>+' + x.next.total + '</b> ' + esc(pn()) + ' · <b>+' + num(x.cfg.rw_checkin) + '</b> 天';
            if (x.next.bonus > 0) t += '（含大奖 <b>' + x.next.bonus + '</b> ' + esc(pn()) + '）';
            else if (x.cfg.cycle_days > 0 && x.cycle_left > 0) t += ' · 再连签 <b>' + x.cycle_left + '</b> 天拿大奖';
        }
        return t;
    }

    /** 等级来源一行：只列非零项，紧凑时长。完整口径见 ?page=level */
    function levelSrcHtml(p) {
        var parts = ['在线 ' + fmtDurShort(p.online_sec), '任务 ' + num(p.task_days) + ' 天'];
        if (parseFloat(p.bonus_days) > 0.0001) parts.push('设定 ' + num(p.bonus_days) + ' 天');
        if (parseFloat(p.decay_days) > 0.0001) parts.push('消退 −' + num(p.decay_days) + ' 天');
        return '<br>' + parts.join(' · ') + '　<a href="?page=level" title="查看等级规则与能力解锁">等级规则 ›</a>';
    }

    /* ---------- 今日任务 ---------- */

    function tasksHtml(x) {
        var L = x.level || {};
        var list = L.tasks || [];
        var LABEL = { login: '登录', gm: '群发言' };
        var h = '<div class="ow-sh-card" id="owShTasks"><div class="ow-sh-h"><span>今日任务</span>'
            + '<em>Lv.' + L.level + ' · ' + esc(L.stage || '') + '</em></div>';
        var done = 0, rwAll = 0, rwGot = 0, i, t, okk;
        for (i = 0; i < list.length; i++) {
            t = list[i];
            okk = t.cur >= t.n;
            if (okk) done++;
            // 每个任务各有一个奖励天数、各自发放（不再是「全部完成才记 1 天」）
            rwAll += parseFloat(t.rw) || 0;
            if (t.paid) rwGot += parseFloat(t.rw) || 0;
            h += '<div class="ow-sh-task">'
                + '<span class="ow-sh-task-k">' + esc(LABEL[t.k] || t.label || t.k) + '</span>'
                + (parseFloat(t.rw) > 0 ? '<i class="ow-sh-task-rw">+' + num(t.rw) + '天</i>' : '')
                + '<span class="ow-sh-task-bar"><i class="' + (okk ? 'done' : '') + '" style="width:'
                + (t.n > 0 ? Math.min(100, Math.round(t.cur / t.n * 100)) : 0) + '%"></i></span>'
                + '<span class="ow-sh-task-v' + (okk ? ' done' : '') + '">' + t.cur + ' / ' + t.n + '</span>'
                + '</div>';
        }
        // 只留「完成几项 / 已得多少天」两个数；「每个任务每天各发一次、次日清零」这类
        // 规则说明统一收到 ?page=level，侧栏不解释规则（站方反馈面板文字太多）。
        h += '<div class="ow-sh-tip">完成 <b>' + done + ' / ' + list.length + '</b>';
        if (rwAll > 0) h += ' · 已得 <b>' + num(rwGot) + '</b> / ' + num(rwAll) + ' 天';
        if (L.frozen) h += ' · 今日已冻结';
        h += '</div></div>';
        return h;
    }

    /* ---------- 月历 ---------- */

    function calHtml(x) {
        var m = x.month || '';
        if (!m) return '';
        var y = parseInt(m.substr(0, 4), 10), n = parseInt(m.substr(5, 2), 10);
        var first = new Date(y, n - 1, 1).getDay();      // 0=周日 → 转成周一开头的偏移
        var off = (first + 6) % 7;
        var total = new Date(y, n, 0).getDate();
        var canNext = (m < x.today.substr(0, 7));        // 不允许翻到未来月份

        var h = '<div class="ow-sh-card" id="owShCal"><div class="ow-sh-h"><span>' + monthLabel(m) + '</span>'
            + '<span class="ow-sh-mnav"><a href="javascript:;" onclick="OwSH.goMonth(-1)" title="上一月">‹</a>'
            + (canNext ? '<a href="javascript:;" onclick="OwSH.goMonth(1)" title="下一月">›</a>' : '')
            + '</span></div><div class="ow-sh-cal-head">';
        for (var i = 0; i < 7; i++) h += '<span>' + WD[i] + '</span>';
        h += '</div><div class="ow-sh-cal">';

        for (var b = 0; b < off; b++) h += '<span class="ow-sh-cell blank"><i></i></span>';
        for (var day = 1; day <= total; day++) {
            var ds = m + '-' + (day < 10 ? '0' : '') + day;
            var cls = 'ow-sh-cell';
            if (x.days && x.days[ds]) cls += ' on';
            if (ds === x.today) cls += ' today';
            h += '<span class="' + cls + '"><i>' + day + '</i></span>';
        }
        h += '</div><div class="ow-sh-tip">本月已签到 <b>' + countMonth(x) + '</b> 天。</div></div>';
        return h;
    }

    function countMonth(x) {
        var n = 0, k;
        if (!x.days) return 0;
        for (k in x.days) if (x.days.hasOwnProperty(k)) n++;
        return n;
    }

    /* ---------- 本月签到榜 ---------- */

    function rankHtml(x, noMe) {
        var list = x.rank || [];
        var h = '<div class="ow-sh-card" id="owShRank"><div class="ow-sh-h"><span>本月签到榜</span>'
            + '<em>按当月天数</em></div>';
        if (!list.length) {
            h += '<div class="ow-sh-empty">本月还没有人签到，抢个头名。</div>';
            return h + '</div>';
        }
        var meId = (OwChat.cfg.actor || {}).id || 0;
        for (var i = 0; i < list.length; i++) {
            var r = list[i];
            var no = i + 1;
            var cls = 'ow-sh-rank-i click' + (!noMe && r.uid === meId ? ' me' : '');
            h += '<div class="' + cls + '" title="查看资料"'
                + ' onclick="OwChat.userCard(' + r.uid + ',' + jsAttr(r.nickname) + ')">'
                + '<span class="ow-sh-rank-n' + (no <= 3 ? ' t' + no : '') + '">' + no + '</span>'
                + '<img class="ow-sh-rank-av" src="' + esc(r.avatar) + '" alt="" loading="lazy">'
                + '<span class="ow-sh-rank-t">' + esc(r.nickname) + '</span>'
                + '<span class="ow-sh-rank-v">' + r.days + ' 天</span>'
                + '</div>';
        }
        return h + '</div>';
    }

    /* ---------- 等级荣誉榜 ---------- */

    /**
     * 「本月签到榜」正下方的等级荣誉榜：达到荣誉等级（默认 Lv.60，由等级配置里的
     * 「荣誉标识」决定）的用户按等级降序取 TOP N。数据随面板接口一起下发。
     * 上榜人数复用「前台榜单人数」配置：两个榜始终 N 条，后台只在一个地方改。
     * 空榜整卡不显示，不留空壳；榜单本身是公开信息，游客也照常展示。
     */
    function honorHtml() {
        var hn = SH.data ? SH.data.honor : null;
        if (!hn) return '';
        var list = hn.list || [];
        if (!list.length) return '';
        var minLv = parseInt(hn.min_level, 10) || 60;
        var h = '<div class="ow-sh-card ow-sh-honor" id="owShHonor">'
            + '<div class="ow-sh-h"><span>等级荣誉榜</span><em>Lv.' + minLv + '+ 才能上榜</em></div>';
        for (var i = 0; i < list.length; i++) {
            var r = list[i];
            var no = i + 1;
            h += '<div class="ow-sh-rank-i click" title="查看资料"'
                + ' onclick="OwChat.userCard(' + r.uid + ',' + jsAttr(r.nickname) + ')">'
                + '<span class="ow-sh-rank-n' + (no <= 3 ? ' t' + no : '') + '">' + no + '</span>'
                + '<img class="ow-sh-rank-av" src="' + esc(r.avatar) + '" alt="" loading="lazy">'
                + '<span class="ow-sh-rank-t">' + esc(r.nickname) + '</span>'
                + '<span class="ow-sh-rank-lv' + (i < 3 ? ' top' : '') + '">Lv.' + r.level + '</span>'
                + '</div>';
        }
        // 门槛被后台调低时给一句解释，免得「榜上并不是 60 级」看着像 bug（默认 60 不出现）
        if (minLv !== 60) {
            h += '<div class="ow-sh-tip">荣誉线当前为 <b>Lv.' + minLv + '</b>（后台「星光殿堂 → 等级」可调）。</div>';
        }
        return h + '</div>';
    }

    /* ============================ 交互 ============================ */

    /** 上/下一月：不越过当前月 */
    SH.goMonth = function (delta) {
        var cur = SH.month || (SH.data && SH.data.month) || '';
        if (!cur) return;
        var next = shiftMonth(cur, delta);
        if (next > (SH.data ? SH.data.today.substr(0, 7) : '')) return;
        SH.month = next;
        SH.load();
    };

    /** 签到成功后的「+N 分」上浮动画 */
    function fly(n) {
        var ring = d.getElementById('owShRing');
        if (!ring || !n) return;
        var el = d.createElement('div');
        el.className = 'ow-sh-fly';
        el.textContent = '+' + n;
        ring.appendChild(el);
        setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 1200);
    }

    SH.doSign = function () {
        if (SH.busy) return;
        var btn = d.getElementById('owShBtn');
        if (!btn || (' ' + btn.className + ' ').indexOf(' is-done ') >= 0) return;
        SH.busy = 1;
        btn.disabled = true;
        OwApi.post(A + 'checkin_do', {}, function (r) {
            SH.busy = 0;
            if (!r || !r.ok) {
                if (btn) btn.disabled = false;
                toast((r && r.msg) || '签到失败');
                return;
            }
            toast(r.msg);
            fly(r.points);
            markDot(false);
            SH.month = '';                 // 回到当前月，避免停在历史月份看不到今天
            SH.load();
        });
    };

    SH.doPatch = function () {
        if (SH.busy) return;
        var x = SH.data;
        if (!x || !x.patch || x.patch.can !== 1) return;
        var cost = x.patch.cost;
        OwChat.confirm('补签 ' + x.patch.day + ' 将消耗 ' + cost + ' ' + pn() + '（本月还剩 '
            + x.patch.left + ' 次），连续天数得以延续。确定补签？', function () {
            SH.busy = 1;
            OwApi.secure(A + 'checkin_patch', {}, function (r) {
                SH.busy = 0;
                toast((r && r.msg) || '补签失败');
                if (r && r.ok) SH.load();
            });
        });
    };

    /* ============================ 挂载 ============================ */

    /** 侧栏 rail 面板 */
    OwChat.onRail({
        id: 'hall',
        label: '星光殿堂',
        onShow: function (panel) {
            SH.panel = panel;
            // 幂等：面板已建过就只刷数据，避免重复插入骨架
            SH.box = d.getElementById('owShBox');
            if (!SH.box) {
                panel.innerHTML = '<div class="ow-sh" id="owShBox"><div class="ow-sh-empty">正在加载…</div></div>';
                SH.box = d.getElementById('owShBox');
            }
            SH.month = '';
            SH.load();
        }
    });

    /**
     * 资料卡：等级行固定在 .ow-card-meta 的最上面一行。
     * 数据随核心 user_card 接口一起下发（服务端 user.card 钩子写入 u.level），
     * 前端不再单独打一次接口 —— 弹窗打开时数据已经在手，不会「先空白再填充」。
     */
    if (typeof OwChat.onCardMetaTop === 'function') {
        OwChat.onCardMetaTop(function (u) {
            if (!u || !u.level) return '';      // 无等级（游客 / 数据缺失）→ 不渲染整行
            var title = u.level_honor ? '荣誉等级' : '等级';
            return '<a class="ow-card-meta-row ow-lt-row ow-lt-row-link" href="?page=level"'
                + ' title="查看等级规则与今日任务">'
                + '<span class="ow-card-meta-k">' + title + '</span>'
                + '<span class="ow-card-meta-v">'
                + badge(u.level, u.level_stage_no, u.level_honor)
                + '</span></a>';
        }, 1);
    }

    // 首屏只为红点探一次状态（延迟一点，别和聊天区的首屏请求抢）
    setTimeout(function () { SH.peek(); }, 1200);
})(window, document);
