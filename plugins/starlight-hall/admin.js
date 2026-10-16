/**
 * 星光殿堂 —— 后台交互
 * 全局对象 OwSHAdmin：概览 / 签到 / 等级 / 用户 四张页签。
 *
 * 一个插件一个后台页（PLUGIN.md），所以原来 level-trust 与 daily-checkin 的两张
 * 后台页合并成同一页下的四张页签；「用户」页签把两个子系统的档案合在一张卡里查，
 * 这是合并最直接的收益 —— 不用再对着同一个用户来回切两个后台页。
 *
 * ⚠️ 本文件随合并资源包在**所有**后台页加载，非本页时容器不存在 → 统一经 boot() 判空。
 *    插件页是点菜单后由 Ajax 塞进 #owAdminMain 的，所以要 MutationObserver 等容器出现。
 * 兼容老浏览器：仅用 var / function，不用箭头函数、let/const、fetch。
 *
 * ⚠️ 前台 chat.js 在后台页**不会**运行（它要求 OwChat.cfg，后台没有），
 *    所以这里不能依赖 OwSH.badge，徽章 HTML 在本文件里自带一份。
 */
(function (w, d) {
    'use strict';

    var $ = function (id) { return d.getElementById(id); };
    var A = 'plugin_starlight_hall_';
    var booted = false;
    var curUid = 0;

    /* ---------- 小工具 ---------- */

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /** 小数去尾：0.50 → 0.5、3.00 → 3（与 PHP 侧 owSHLNum 同一口径） */
    function num(v) {
        var n = parseFloat(v);
        if (!isFinite(n)) n = 0;
        var s = n.toFixed(2).replace(/\.?0+$/, '');
        return s === '' || s === '-' ? '0' : s;
    }

    /** 秒 → 中文时长（与 PHP 侧 owSHLFmtDur 同一口径；两处各写一份是刻意的，
     *  不值得为格式化再打一次接口） */
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

    /** 等级徽章（与前台同一套类名，level-cultivation 会接管 .ow-lt-badge） */
    function badge(lv, stageNo, honor) {
        return '<span class="ow-lt-badge ow-lt-s' + (stageNo || 1) + (honor ? ' ow-lt-honor' : '')
            + '">Lv.' + lv + '</span>';
    }

    /**
     * 一行「标签 : 值」。
     * @param {string} k 标签（纯文本，转义）
     * @param {string} v 值。**已是 HTML 片段时原样输出**，纯文本请调用方先 esc()。
     *   口径与项目其它地方一致：默认按 HTML 输出，避免把 badge() 返回的片段转义成源码。
     */
    function row(k, v) {
        return '<div class="ow-card-meta-row"><span class="ow-card-meta-k">' + esc(k)
            + '</span><span class="ow-card-meta-v">' + (v == null ? '' : v) + '</span></div>';
    }

    function kpi(v, label, small) {
        return '<div class="ow-sh-kpi' + (small ? ' small' : '') + '"><b>' + esc(v) + '</b><span>'
            + esc(label) + '</span></div>';
    }

    /* ================= 页签 ================= */

    w.OwSHAdmin = {
        tab: function (p) {
            var tabs = d.getElementById('owShTabs');
            var i, list;
            if (tabs) {
                list = tabs.getElementsByClassName('ow-sh-tab');
                for (i = 0; i < list.length; i++) {
                    var on = list[i].getAttribute('data-p') === p;
                    if (list[i].classList) list[i].classList[on ? 'add' : 'remove']('is-active');
                    else list[i].className = 'ow-sh-tab' + (on ? ' is-active' : '');
                }
            }
            list = d.querySelectorAll('.ow-sh-pane');
            for (i = 0; i < list.length; i++) {
                var on2 = list[i].getAttribute('data-p') === p;
                if (list[i].classList) list[i].classList[on2 ? 'add' : 'remove']('is-active');
                else list[i].className = 'ow-sh-pane' + (on2 ? ' is-active' : '');
            }
        }
    };

    /* ================= 概览（等级体系） ================= */

    w.OwSHAdmin.load = function () {
        OwApi.post(A + 'level_stats', {}, function (r) {
            if (!r || !r.ok) { toast((r && r.msg) || '概览加载失败'); return; }
            renderStats(r);
            renderTrend(r.trend, r.trend_note);
        });
        loadCheckin();
    };

    function renderStats(r) {
        var box = $('owShLvStats');
        if (!box) return;
        var html = '<h3 class="ow-sh-h3">等级概览</h3><div class="ow-sh-kpis">'
            + kpi((r.users || 0), '有等级记录（人）')
            + kpi('Lv.' + (r.median || 1), '等级中位数')
            + kpi('Lv.' + (r.avg || 0), '平均等级')
            + kpi(String(r.gating) === '1' ? '已开启' : '已关闭', '等级限制')
            + '</div>';
        // 阶段分布：圆点色 = 阶段色（数据性质色板，见 style.css 顶部注释）；
        // 名称与区间由服务端按当前配置现算（stage_meta），改阈值后这里自动跟着变。
        var metas = r.stage_meta || [];
        if (metas.length) {
            html += '<div class="ow-sh-kpis" style="margin-top:10px">';
            for (var i = 0; i < metas.length; i++) {
                var m = metas[i];
                html += '<div class="ow-sh-kpi small"><b><i class="ow-sh-dot-s ow-lt-s' + m.no + '"></i>'
                    + ((r.dist && r.dist[m.no]) || 0) + '</b><span>'
                    + esc(m.name) + ' ' + esc(m.range) + '</span></div>';
            }
            html += '</div>';
        }
        box.innerHTML = html;
    }

    var TREND_FIELDS = [
        { k: 'actives', label: '活跃用户', unit: '人' },
        { k: 'upgrades', label: '升级次数', unit: '次' },
        { k: 'msgs', label: '有效发言', unit: '条' },
        { k: 'files', label: '文件操作', unit: '次' },
        { k: 'rooms', label: '建群', unit: '个' }
    ];

    function renderTrend(trend, note) {
        var box = $('owShLvTrend');
        if (!box) return;
        if (!trend || !trend.length) { box.innerHTML = '<p class="ow-panel-empty">暂无数据</p>'; return; }

        var maxes = {};
        TREND_FIELDS.forEach(function (f) {
            var m = 0;
            trend.forEach(function (dd) { if (dd[f.k] > m) m = dd[f.k]; });
            maxes[f.k] = m || 1;
        });

        var html = '<div class="ow-sh-trend">';
        TREND_FIELDS.forEach(function (f) {
            html += '<div class="ow-sh-tchart">'
                + '<div class="ow-sh-tchart-h"><span>' + f.label + '</span><em>峰值 '
                + maxes[f.k] + ' ' + f.unit + '</em></div>'
                + '<div class="ow-sh-tbars">';
            for (var i = 0; i < trend.length; i++) {
                var v = trend[i][f.k] || 0;
                var hh = v > 0 ? Math.max(4, Math.round(v / maxes[f.k] * 100)) : 2;
                html += '<i class="' + (v > 0 ? '' : 'zero') + '" style="height:' + hh + '%" title="'
                    + esc(trend[i].day) + '：' + v + ' ' + f.unit + '"></i>';
            }
            html += '</div></div>';
        });
        // 五张图柱位完全一致（同一组日期、同一套 flex），共用一条日期轴即可
        html += '</div>'
            + '<div class="ow-sh-tx"><span>' + esc(trend[0].day) + '</span>'
            + '<span>' + esc(trend[trend.length - 1].day) + '</span></div>'
            + '<div class="ow-lv-tip">' + esc(note || '') + '</div>';
        box.innerHTML = html;
    }

    /* ================= 签到页签 ================= */

    function loadCheckin() {
        OwApi.post(A + 'checkin_stats', {}, function (r) {
            if (!r || !r.ok) return;
            renderCiStats(r);
            renderCiTrend(r.trend || []);
            renderCiTop(r.top || []);
        });
    }

    function renderCiStats(r) {
        var box = $('owShCiStats');
        if (!box) return;
        box.innerHTML = '<h3 class="ow-sh-h3">签到概览</h3><div class="ow-sh-kpis">'
            + kpi(r.today, '今日签到人次')
            + kpi(r.month, '本月签到人次')
            + kpi(r.users, '参与人数')
            + kpi(r.points, '累计发放积分')
            + kpi(r.all, '历史签到人次')
            + kpi(r.avg_streak, '平均连续天数')
            + '</div>';
    }

    /** 纯 CSS 柱状图：柱高按「当日 / 峰值」百分比内联（内联的是数字，不是颜色） */
    function renderCiTrend(trend) {
        var box = $('owShCiTrend');
        if (!box) return;
        if (!trend.length) { box.innerHTML = '<div class="ow-sh-empty">暂无数据</div>'; return; }
        var max = 1, i;
        for (i = 0; i < trend.length; i++) if (trend[i].n > max) max = trend[i].n;
        var h = '<div class="ow-sh-tbars" style="height:120px">';
        for (i = 0; i < trend.length; i++) {
            var t = trend[i];
            var pct = Math.max(2, Math.round(t.n / max * 100));
            h += '<i class="' + (t.n ? '' : 'zero') + '" style="height:' + pct + '%" title="'
                + esc(t.day + ' · ' + t.n + ' 人次 · ' + t.p + ' 积分') + '"></i>';
        }
        h += '</div><div class="ow-sh-tx"><span>' + esc(trend[0].day.substr(5)) + '</span><span>'
            + esc(trend[trend.length - 1].day.substr(5)) + '</span></div>';
        box.innerHTML = h;
    }

    function renderCiTop(list) {
        var box = $('owShCiTop');
        if (!box) return;
        if (!list.length) { box.innerHTML = '<div class="ow-sh-empty">还没有人签到</div>'; return; }
        var h = '<table class="ow-table"><tr><th>#</th><th>用户</th><th>累计天数</th>'
            + '<th>当前连续</th><th>最长连续</th><th>累计获得</th></tr>';
        for (var i = 0; i < list.length; i++) {
            var r = list[i];
            h += '<tr><td>' + (i + 1) + '</td>'
                + '<td>' + esc(r.nickname) + ' <span style="color:var(--ow-text-sub)">#' + r.uid + '</span></td>'
                + '<td>' + r.days + '</td><td>' + r.streak + '</td><td>' + r.max_streak + '</td>'
                + '<td>' + r.points + ' 分</td></tr>';
        }
        box.innerHTML = h + '</table>';
    }

    /* ================= 参数保存 ================= */

    /** 收集指定前缀的表单值（id 形如 owShCfg_<prefix>_<key>） */
    function collect(prefix) {
        var out = {}, inputs = d.querySelectorAll('[id^="owShCfg_' + prefix + '_"]');
        var head = ('owShCfg_' + prefix + '_').length;
        for (var i = 0; i < inputs.length; i++) out[inputs[i].id.substring(head)] = inputs[i].value;
        return out;
    }

    /**
     * 保存某一组参数（一次提交，避免分多次保存出现「一半新一半旧」的中间态）。
     * 档位表先按服务端同一套规则本地校验一遍：整段都解析不出来就别提交，
     * 免得把「全部名额清零」这种破坏性配置写进去。
     */
    function saveGroup(prefix, action, okLabel) {
        var cfg = collect(prefix);
        var k, n = 0;
        for (k in cfg) if (cfg.hasOwnProperty(k)) n++;
        if (!n) { toast('配置表单不存在'); return; }
        if (cfg.hasOwnProperty('room_tiers')) {
            var lines = String(cfg.room_tiers).split(/[\r\n,;]+/);
            var okay = lines.some(function (l) { return /^\s*\d{1,4}\s*[:：]\s*\d{1,4}\s*$/.test(l); });
            if (!okay) { toast('名额档位格式不对，每行需形如「等级:名额」，例如 10:3'); return; }
        }
        OwApi.secure(action, cfg, function (r) {
            toast((r && r.msg) || (r && r.ok ? okLabel : '保存失败'));
            // 配置一改，前台面板与等级页的公式/解锁表就变了 → 重载让管理员直接看到新文案
            if (r && r.ok) w.location.reload();
        });
    }

    w.OwSHAdmin.saveCheckin = function () {
        saveGroup('ci', A + 'checkin_cfg_save', '已保存');
    };
    w.OwSHAdmin.saveLevel = function () {
        saveGroup('lv', A + 'level_cfg_save', '已保存');
    };

    /* ================= 用户页签（两边档案合卡） ================= */

    w.OwSHAdmin.query = function () {
        var el = $('owShKw');
        var kw = el ? String(el.value || '').replace(/^\s+|\s+$/g, '') : '';
        if (!kw) { toast('请填写用户 ID 或昵称'); return; }
        // 第一步按「ID 或昵称」定位用户（签到侧的查询支持关键词），
        // 第二步拿 uid 取等级档案 —— 等级侧只按 uid 查，两步串行即可，不必再写一个合并路由。
        OwApi.post(A + 'checkin_user', { kw: kw }, function (r1) {
            var box = $('owShUser');
            if (!box) return;
            if (!r1 || !r1.ok) {
                curUid = 0;
                box.innerHTML = '<p class="ow-admin-desc">' + esc((r1 && r1.msg) || '查询失败') + '</p>';
                return;
            }
            curUid = r1.uid;
            OwApi.post(A + 'level_user', { uid: r1.uid }, function (r2) {
                if (!r2 || !r2.ok) {
                    // 等级档案取不到（理论上不该发生）时至少把签到档案展示出来
                    box.innerHTML = '<p class="ow-admin-desc">'
                        + esc((r2 && r2.msg) || '等级档案不可用') + '</p>' + checkinBlock(r1);
                    return;
                }
                box.innerHTML = levelBlock(r1, r2) + checkinBlock(r1) + userActions();
            });
        });
    };

    /** 等级档案块 */
    function levelBlock(ci, r) {
        var p = r.prog || {};
        return '<div class="ow-card-meta" style="margin-top:12px;border-top:0;padding-top:0">'
            + row('用户', esc(r.nickname) + '（ID ' + r.uid + '）' + (r.role === 'admin' ? ' · 超级管理员' : ''))
            + row('等级', badge(r.level, r.stage_no, r.honor) + ' ' + esc(r.stage))
            + row('等级来源', '在线 <b>' + esc(fmtDur(p.online_sec)) + '</b>'
                + ' · 任务 <b>' + num(p.task_days) + '</b> 天'
                + (parseFloat(p.bonus_days) > 0.0001 ? ' · 管理员设定 <b>' + num(p.bonus_days) + '</b> 天' : '')
                + (parseFloat(p.decay_days) > 0.0001 ? ' · 长期未登录消退 <b>−' + num(p.decay_days) + '</b> 天' : '')
                + '　= 合计 <b>' + num(p.total_days) + '</b> 天（1 天 = 1 级）')
            + row('进度', '本级 <b>' + (p.pct || 0) + '%</b> · 距 Lv.' + (p.next_level || (parseInt(r.level, 10) + 1))
                + ' 还需 <b>' + num(p.gap_days) + '</b> 天（在线满 ' + num(p.online_hours) + ' 小时算 1 天）')
            + row('登录', '连续 ' + r.login_streak + ' 天 · 累计 ' + r.login_days + ' 天')
            + row('今日', (r.frozen ? '已冻结' : (r.settled ? '任务已全部完成' : '进行中'))
                + ' · 本周补签 ' + r.patch_used + ' 次 · 本月保护 ' + r.protect_used + ' 次')
            + '</div>'
            + '<h4 class="ow-sh-cap">今日任务</h4><div class="ow-sh-tasks">'
            + taskRows(r.tasks || []) + '</div>';
    }

    function taskRows(list) {
        var h = '';
        for (var i = 0; i < list.length; i++) {
            var t = list[i];
            var done = t.cur >= t.n;
            h += '<div class="ow-sh-task"><span class="ow-sh-task-k">' + esc(t.label || t.k)
                + (parseFloat(t.rw) > 0
                    ? ' <span style="color:var(--ow-text-sub)">+' + num(t.rw) + ' 天'
                      + (t.paid ? '·已发' : '') + '</span>'
                    : '')
                + '</span><span class="ow-sh-task-v' + (done ? ' done' : '') + '">'
                + t.cur + ' / ' + t.n + '</span></div>';
        }
        return h || '<p class="ow-panel-empty">暂无任务</p>';
    }

    /** 签到档案块 */
    function checkinBlock(r) {
        var h = '<h4 class="ow-sh-cap">签到档案</h4>'
            + '<div class="ow-card-meta" style="border-top:0;padding-top:0">'
            + row('积分余额', r.points + ' 分')
            + row('累计签到', r.total_days + ' 天')
            + row('当前连续', r.streak + ' 天')
            + row('最长连续', r.max_streak + ' 天')
            + row('签到累计发放', r.earned + ' 分')
            + row('最近签到', esc(r.last_day || '—'))
            + row('今日状态', r.signed_today ? '<span style="color:var(--ow-green)">已签到</span>' : '未签到')
            + '</div>';

        h += '<div style="margin-top:10px"><b style="font-size:13px">' + esc(r.month) + ' 打卡</b>'
            + '<div class="ow-sh-mini">' + miniCal(r) + '</div></div>';

        if (r.recent && r.recent.length) {
            h += '<table class="ow-table" style="margin-top:12px"><tr><th>日期</th><th>连续</th>'
                + '<th>实发</th><th>方式</th></tr>';
            var kindCn = { normal: '签到', patch: '补签', admin: '管理员代签' };
            for (var i = 0; i < r.recent.length; i++) {
                var x = r.recent[i];
                h += '<tr><td>' + esc(x.day) + '</td><td>连续 ' + x.streak + ' 天</td>'
                    + '<td>' + x.points + ' 分</td><td>' + esc(kindCn[x.kind] || x.kind) + '</td></tr>';
            }
            h += '</table>';
        }
        return h;
    }

    function miniCal(r) {
        var m = r.month || '';
        if (!m) return '';
        var y = parseInt(m.substr(0, 4), 10), n = parseInt(m.substr(5, 2), 10);
        var first = new Date(y, n - 1, 1).getDay();
        var off = (first + 6) % 7;
        var total = new Date(y, n, 0).getDate();
        var days = r.days || {};
        var h = '', b, day;
        for (b = 0; b < off; b++) h += '<span></span>';
        for (day = 1; day <= total; day++) {
            var ds = m + '-' + (day < 10 ? '0' : '') + day;
            h += days[ds] ? '<span class="on"><b>' + day + '</b></span>' : '<span>' + day + '</span>';
        }
        return h;
    }

    function userActions() {
        return '<h4 class="ow-sh-cap">管理员操作</h4>'
            + '<div class="ow-sh-actions" style="flex-wrap:wrap">'
            + '<button class="ow-btn ow-btn-primary" onclick="OwSHAdmin.op(\'ci\',\'sign\')">代签今天</button>'
            + '<button class="ow-btn ow-btn-ghost" onclick="OwSHAdmin.op(\'ci\',\'reset\')">清零连续天数</button>'
            + '<button class="ow-btn ow-btn-ghost" onclick="OwSHAdmin.op(\'lv\',\'patch\')">补签今日任务</button>'
            + '<button class="ow-btn ow-btn-ghost" onclick="OwSHAdmin.op(\'lv\',\'freeze\')">冻结今日任务</button>'
            + '<button class="ow-btn ow-btn-primary" onclick="OwSHAdmin.setLevel()">直接设置等级</button>'
            + '</div>';
    }

    /**
     * 管理员操作统一入口。
     * @param {string} kind 'ci' = 签到侧、'lv' = 等级侧
     * @param {string} op   具体操作名
     */
    w.OwSHAdmin.op = function (kind, op) {
        if (!curUid) { toast('请先查询用户'); return; }
        var tip = {
            sign: '为该用户代签今天？将按当前规则发放积分。',
            reset: '清零该用户的连续天数？历史签到记录不受影响。',
            patch: '为该用户补签今日任务？每周最多 1 次，会发放当天全部任务奖励。',
            freeze: '冻结该用户今日的任务进度？解冻前不再结算。'
        }[op] || '确定执行该操作？';
        OwChat.confirm(tip, function () {
            var action = kind === 'ci' ? A + 'checkin_user_op' : A + 'level_op';
            OwApi.secure(action, { uid: curUid, op: op }, function (r) {
                toast((r && r.msg) || (r && r.ok ? '已执行' : '操作失败'));
                if (r && r.ok) w.OwSHAdmin.query();
            });
        });
    };

    w.OwSHAdmin.setLevel = function () {
        if (!curUid) { toast('请先查询用户'); return; }
        OwChat.openModal(
            '<h3>设置等级</h3>'
            + '<div class="ow-form-item"><label>等级（≥1）</label>'
            + '<input class="ow-input" id="owShSetLv" type="number" min="1" value="1"></div>'
            + '<div class="ow-form-item"><label>任务累计天数（≥0，可含小数）</label>'
            + '<input class="ow-input" id="owShSetDays" type="number" min="0" step="0.1" value="0">'
            + '<p style="font-size:12px">'
            + '等级 = 1 + ⌊ 在线天 + 任务天 + 设定天 ⌋。这里改的是<b>任务天</b>；'
            + '若把等级设得比「在线天 + 任务天」还高，差额会自动记成<b>管理员设定</b>，'
            + '等级页会如实显示这一项 —— 不会出现「Lv.30 配累计 4 天」这种自相矛盾。'
            + '等级只升不降：设得比公式值低时，下次结算会被顶回去。</p></div>'
            + '<div class="ow-modal-actions">'
            + '<button class="ow-btn ow-btn-ghost" onclick="OwChat.closeModal()">取消</button>'
            + '<button class="ow-btn ow-btn-primary" onclick="OwSHAdmin.doSet()">保存</button>'
            + '</div>', 420);
    };

    w.OwSHAdmin.doSet = function () {
        var n = parseInt(($('owShSetLv') || {}).value, 10);
        var dd = parseFloat(($('owShSetDays') || {}).value);
        if (!n || n < 1) { toast('等级需为 ≥1 的整数'); return; }
        if (isNaN(dd) || dd < 0) { toast('任务累计天数需为 ≥0 的数'); return; }
        OwApi.secure(A + 'level_op', { uid: curUid, op: 'set', level: n, task_days: dd }, function (r) {
            toast((r && r.msg) || (r && r.ok ? '已设置' : '设置失败'));
            if (r && r.ok) { OwChat.closeModal(); w.OwSHAdmin.query(); }
        });
    };

    /* ================= 启动 ================= */

    function boot() {
        if (booted || !$('owShLvStats')) return;
        booted = true;
        w.OwSHAdmin.load();
    }

    function watch() {
        var main = d.getElementById('owAdminMain');
        if (!main || !w.MutationObserver) { boot(); return; }
        new w.MutationObserver(function () {
            if (!$('owShLvStats')) { booted = false; return; }
            boot();
        }).observe(main, { childList: true, subtree: true });
        boot();
    }

    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', function () { setTimeout(watch, 0); });
    } else {
        setTimeout(watch, 0);
    }
})(window, document);
