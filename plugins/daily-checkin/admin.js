/**
 * 每日签到插件 —— 后台交互
 * 全局对象 OwCIAdmin：概览 / 趋势 / 累计榜 / 参数设置 / 用户查询与代签。
 *
 * ⚠️ 本文件随合并资源包在**所有**后台页加载，非本页时容器不存在 → 统一经 boot() 判空。
 *    插件页是点菜单后由 Ajax 塞进 #owAdminMain 的，所以要 MutationObserver 等容器出现
 *    （与 plugins/level-trust/admin.js 同一套路）。
 * 兼容老浏览器：仅用 var / function，不用箭头函数、let/const、fetch。
 */
(function (w, d) {
    'use strict';

    var $ = function (id) { return d.getElementById(id); };
    var A = 'plugin_daily_checkin_';
    var booted = false;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
    /** 一行「标签 : 值」（v 按 HTML 输出，纯文本请调用方先 esc） */
    function row(k, v) {
        return '<div class="ow-card-meta-row"><span class="ow-card-meta-k">' + esc(k)
            + '</span><span class="ow-card-meta-v">' + (v == null ? '' : v) + '</span></div>';
    }

    var CI = {};

    /* ================= 概览 / 趋势 / 累计榜 ================= */

    CI.load = function () {
        OwApi.post(A + 'stats', {}, function (r) {
            if (!r || !r.ok) { var b = $('owCiStats'); if (b) b.innerHTML = '<p class="ow-admin-desc">' + esc((r && r.msg) || '加载失败') + '</p>'; return; }
            renderStats(r);
            renderTrend(r.trend || []);
            renderTop(r.top || []);
        });
    };

    function renderStats(r) {
        var box = $('owCiStats');
        if (!box) return;
        box.innerHTML = '<h3 style="margin:0 0 10px">概览</h3><div class="ow-ci-kpis">'
            + kpi(r.today, '今日签到人次')
            + kpi(r.month, '本月签到人次')
            + kpi(r.users, '参与人数')
            + kpi(r.points, '累计发放积分')
            + kpi(r.all, '历史签到人次')
            + kpi(r.avg_streak, '平均连续天数')
            + '</div>';
    }
    function kpi(v, label) {
        return '<div class="ow-ci-kpi"><b>' + esc(v) + '</b><span>' + esc(label) + '</span></div>';
    }

    /** 纯 CSS 柱状图：柱高按「当日 / 峰值」百分比内联（内联的是数字，不是颜色） */
    function renderTrend(trend) {
        var box = $('owCiTrend');
        if (!box) return;
        if (!trend.length) { box.innerHTML = '<div class="ow-ci-empty">暂无数据</div>'; return; }
        var max = 1, i;
        for (i = 0; i < trend.length; i++) if (trend[i].n > max) max = trend[i].n;
        var h = '<div class="ow-ci-bars">';
        for (i = 0; i < trend.length; i++) {
            var t = trend[i];
            var pct = Math.max(2, Math.round(t.n / max * 100));
            h += '<div class="ow-ci-bar"><span class="ow-ci-bar-num">' + t.n + '</span>'
                + '<i class="' + (t.n ? '' : 'zero') + '" style="height:' + pct + '%" title="'
                + esc(t.day + ' · ' + t.n + ' 人次 · ' + t.p + ' 积分') + '"></i>'
                + '<em>' + t.day.substr(5) + '</em></div>';
        }
        box.innerHTML = h + '</div>';
    }

    function renderTop(list) {
        var box = $('owCiTop');
        if (!box) return;
        if (!list.length) { box.innerHTML = '<div class="ow-ci-empty">还没有人签到</div>'; return; }
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

    /* ================= 参数设置 ================= */

    CI.saveCfg = function () {
        var keys = ['enable', 'base', 'step', 'step_cap', 'cycle_days', 'cycle_bonus',
                    'patch_cost', 'patch_limit', 'rank_size'];
        var data = {}, i, el;
        for (i = 0; i < keys.length; i++) {
            el = $('owCiCfg_' + keys[i]);
            if (el) data[keys[i]] = el.value;
        }
        OwApi.secure(A + 'cfg_save', data, function (r) {
            toast((r && r.msg) || (r && r.ok ? '已保存' : '保存失败'));
            if (r && r.ok) CI.load();
        });
    };

    /* ================= 用户查询 ================= */

    var curUid = 0;

    CI.query = function () {
        var el = $('owCiKw');
        var kw = el ? (el.value || '').replace(/^\s+|\s+$/g, '') : '';
        if (!kw) { toast('请填写用户 ID 或昵称关键词'); return; }
        OwApi.post(A + 'user', { kw: kw }, function (r) {
            var box = $('owCiUser');
            if (!box) return;
            if (!r || !r.ok) { box.innerHTML = '<p class="ow-admin-desc">' + esc((r && r.msg) || '查询失败') + '</p>'; return; }
            curUid = r.uid;
            renderUser(r);
        });
    };

    function renderUser(r) {
        var box = $('owCiUser');
        if (!box) return;
        var h = '<div class="ow-card-meta" style="margin-top:10px">'
            + row('用户', esc(r.nickname) + ' <span style="color:var(--ow-text-sub)">#' + r.uid + '</span>')
            + row('积分余额', r.points + ' 分')
            + row('累计签到', r.total_days + ' 天')
            + row('当前连续', r.streak + ' 天')
            + row('最长连续', r.max_streak + ' 天')
            + row('本插件累计发放', r.earned + ' 分')
            + row('最近签到', esc(r.last_day || '—'))
            + row('今日状态', r.signed_today ? '<span style="color:var(--ow-green)">已签到</span>' : '未签到')
            + '</div>';

        // 当月打卡轨迹（紧凑版月历，复用 .ow-ci-mini）
        h += '<div style="margin-top:10px"><b style="font-size:13px">' + esc(r.month) + ' 打卡</b>'
            + '<div class="ow-ci-mini">' + miniCal(r) + '</div></div>';

        // 最近 30 条记录
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

        h += '<div class="ow-modal-actions">'
            + '<button class="ow-btn ow-btn-primary" onclick="OwCIAdmin.op(\'sign\')">代签今天</button>'
            + '<button class="ow-btn ow-btn-ghost" onclick="OwCIAdmin.op(\'reset\')">清零连续天数</button>'
            + '</div>';
        box.innerHTML = h;
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

    /** 管理员操作：代签 / 清零连续 */
    CI.op = function (op) {
        if (!curUid) { toast('请先查询用户'); return; }
        var tip = op === 'sign' ? '为该用户代签今天？将按当前规则发放积分。'
            : '清零该用户的连续天数？历史签到记录不受影响。';
        OwChat.confirm(tip, function () {
            OwApi.secure(A + 'user_op', { uid: curUid, op: op }, function (r) {
                toast((r && r.msg) || (r && r.ok ? '已执行' : '操作失败'));
                if (r && r.ok) CI.query();
            });
        });
    };

    /* ================= 启动 ================= */

    function boot() {
        if (booted || !$('owCiStats')) return;
        booted = true;
        CI.load();
    }

    function watch() {
        var main = d.getElementById('owAdminMain');
        if (!main || !w.MutationObserver) { boot(); return; }
        new w.MutationObserver(function () {
            if (!$('owCiStats')) { booted = false; return; }
            boot();
        }).observe(main, { childList: true, subtree: true });
        boot();
    }

    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', function () { setTimeout(watch, 0); });
    } else {
        setTimeout(watch, 0);
    }

    w.OwCIAdmin = CI;
})(window, document);
