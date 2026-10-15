/**
 * Redis 缓存插件 —— 后台交互
 * 全局对象 OwRdsAdmin：概览 / 体检 / 镜像预览 / 配置保存 / 清空。
 *
 * ⚠️ 本文件随合并资源包在**所有**后台页加载，非本页时容器不存在 → 统一经 boot() 判空。
 *    插件页是点菜单后由 Ajax 塞进 #owAdminMain 的，所以要 MutationObserver 等容器出现
 *    （与 plugins/level-trust/admin.js、plugins/daily-checkin/admin.js 同一套路）。
 * 兼容老浏览器：仅用 var / function，不用箭头函数、let/const、fetch。
 */
(function (w, d) {
    'use strict';

    var $ = function (id) { return d.getElementById(id); };
    var A = 'plugin_redis_';
    var booted = false;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
    /** 键值成对的数字不转义（由调用方保证是数字） */
    function num(n) { n = parseInt(n, 10); return isNaN(n) ? 0 : n; }

    var R = {};

    /* ================= 概览 ================= */

    R.load = function () {
        OwApi.post(A + 'stats', {}, function (r) {
            if (!r || !r.ok) {
                setState('<span class="ow-rds-bad">加载失败：' + esc((r && r.msg) || '未知错误') + '</span>');
                return;
            }
            state(r);
            kpi(r);
            trend(r.trend || []);
            rooms(r.rooms || []);
            backfill(r.cfg || {});
        });
    };

    function setState(html) {
        var box = $('owRdsState');
        if (box) box.innerHTML = html;
    }

    /** 一句话说清 Redis 现在到底在不在干活 */
    function state(r) {
        if (!r.connected) {
            setState('<span class="ow-rds-bad">未连接</span> —— Redis 连不上或总开关已关闭，'
                + '当前不会写入任何数据（不影响聊天）。请检查下面的连接设置，然后点「重新体检」看原因。');
            return;
        }
        var t = r.today || {};
        setState('<span class="ow-rds-ok">已连接</span> —— 正在旁路镜像消息与统计活跃。'
            + '今日（Redis）：消息 ' + num(t.msgs) + ' 条 · 镜像 ' + num(t.mirrors) + ' 条 · 登录 '
            + num(t.logins) + ' 次 · 活跃 ' + num(t.acts) + ' 人。');
    }

    function kpi(r) {
        var box = $('owRdsKpi');
        if (!box) return;
        var t = r.today || {}, tr = r.trend || [], i, sumMsg = 0, sumAct = 0;
        for (i = 0; i < tr.length; i++) { sumMsg += num(tr[i].msgs); }
        for (i = 0; i < tr.length; i++) { if (num(tr[i].acts) > sumAct) sumAct = num(tr[i].acts); }

        box.innerHTML = '<h3 style="margin:0 0 10px">今日实时（Redis）</h3><div class="ow-rds-kpis">'
            + kpiCell(t.msgs, '今日消息')
            + kpiCell(t.mirrors, '今日镜像')
            + kpiCell(t.logins, '今日登录')
            + kpiCell(t.acts, '今日活跃用户')
            + '</div>'
            + '<h3 style="margin:14px 0 10px">近 14 天（已落库）</h3><div class="ow-rds-kpis">'
            + kpiCell(sumMsg, '累计消息')
            + kpiCell(sumAct, '单日最高活跃')
            + kpiCell((r.rooms || []).length, '已镜像群数')
            + '</div>';
    }

    function kpiCell(v, label) {
        return '<div class="ow-rds-kpi"><b>' + num(v) + '</b><span>' + esc(label) + '</span></div>';
    }

    /** 纯 CSS 柱状图：柱高按「当日 / 峰值」百分比内联（内联的是数字，不是颜色） */
    function trend(list) {
        var box = $('owRdsTrend');
        if (!box) return;
        if (!list.length) { box.innerHTML = '<div class="ow-rds-empty">暂无数据</div>'; return; }
        var max = 1, i;
        for (i = 0; i < list.length; i++) if (num(list[i].msgs) > max) max = num(list[i].msgs);
        var h = '<div class="ow-rds-bars">';
        for (i = 0; i < list.length; i++) {
            var t = list[i];
            var pct = Math.max(2, Math.round(num(t.msgs) / max * 100));
            h += '<div class="ow-rds-bar"><span class="ow-rds-bar-num">' + num(t.msgs) + '</span>'
                + '<i class="' + (num(t.msgs) ? '' : 'zero') + '" style="height:' + pct + '%" title="'
                + esc(t.day + ' · 消息 ' + num(t.msgs) + ' · 活跃 ' + num(t.acts) + ' 人')
                + '"></i><em>' + esc(String(t.day).substr(5)) + '</em></div>';
        }
        box.innerHTML = h + '</div>';
    }

    function rooms(list) {
        var box = $('owRdsRooms');
        if (!box) return;
        if (!list.length) {
            box.innerHTML = '<div class="ow-rds-empty">还没有镜像数据。发一条消息后回到这里刷新即可看到。</div>';
            return;
        }
        var h = '<div class="ow-rds-scroll"><table class="ow-table"><tr><th>群聊</th>'
            + '<th>镜像条数</th><th></th></tr>';
        for (var i = 0; i < list.length; i++) {
            var x = list[i];
            h += '<tr><td>' + esc(x.name || ('群 #' + x.room_id))
                + ' <span style="color:var(--ow-text-sub)">#' + num(x.room_id) + '</span></td>'
                + '<td>' + num(x.n) + '</td>'
                + '<td><button class="ow-btn ow-btn-ghost ow-rds-mini" onclick="OwRdsAdmin.preview('
                + num(x.room_id) + ')">查看</button></td></tr>';
        }
        box.innerHTML = h + '</table></div>';
    }

    /* ================= 镜像预览 ================= */

    R.preview = function (roomId) {
        OwApi.post(A + 'preview', { room_id: roomId }, function (r) {
            var box = $('owRdsPreview');
            if (!box) return;
            if (!r || !r.ok) { box.innerHTML = '<p class="ow-admin-desc">' + esc((r && r.msg) || '读取失败') + '</p>'; return; }
            var list = r.rows || [];
            var h = '<h3 style="margin:14px 0 10px">群 #' + num(roomId) + ' 最近镜像（新的在前）</h3>';
            if (!list.length) {
                box.innerHTML = h + '<div class="ow-rds-empty">这个群没有镜像数据（可能已过期，或镜像开关刚打开）</div>';
                return;
            }
            h += '<div class="ow-rds-scroll"><table class="ow-table"><tr><th>ID</th><th>发送者</th>'
                + '<th>类型</th><th>内容</th><th>时间</th></tr>';
            for (var i = 0; i < list.length; i++) {
                var x = list[i];
                var who = esc(x.nick || '—');
                if (num(x.gid)) who += ' <span style="color:var(--ow-text-sub)">游客</span>';
                else if (num(x.uid)) who += ' <span style="color:var(--ow-text-sub)">#' + num(x.uid) + '</span>';
                h += '<tr><td>' + num(x.id) + '</td><td>' + who + '</td><td>' + esc(x.type) + '</td>'
                    + '<td>' + esc(String(x.text).substr(0, 120)) + '</td>'
                    + '<td>' + esc(fmtTime(x.ts)) + '</td></tr>';
            }
            box.innerHTML = h + '</table></div>';
        });
    };

    function fmtTime(ts) {
        ts = num(ts);
        if (!ts) return '—';
        var dt = new Date(ts * 1000);
        var p = function (n) { return (n < 10 ? '0' : '') + n; };
        return p(dt.getMonth() + 1) + '-' + p(dt.getDate()) + ' ' + p(dt.getHours()) + ':' + p(dt.getMinutes());
    }

    /* ================= 体检 ================= */

    R.probe = function () {
        var box = $('owRdsProbe');
        if (box) box.innerHTML = '<p class="ow-admin-desc">正在连接…</p>';
        OwApi.post(A + 'probe', {}, function (r) {
            if (!box) return;
            var p = (r && r.probe) || null;
            if (!p) { box.innerHTML = '<p class="ow-admin-desc">' + esc((r && r.msg) || '体检失败') + '</p>'; return; }
            var cls = p.ok ? 'ow-rds-ok' : 'ow-rds-bad';
            var hit = num(p.hits) + num(p.misses);
            var rate = hit > 0 ? Math.round(num(p.hits) / hit * 100) + '%' : '—';
            var h = '<div class="ow-rds-probe"><div class="ow-rds-probe-h ' + cls + '">'
                + (p.ok ? '体检通过' : '体检未通过') + ' · ' + esc(p.msg) + '</div>'
                + '<div class="ow-rds-kpis">'
                + kpiCell(p.latency, '连接延迟(ms)')
                + kpiCell(p.keys, '当前库键数')
                + kpiCell(p.expires, '其中带过期')
                + kpiCell(rate === '—' ? 0 : String(rate).replace('%', ''), '命中率(%)')
                + '</div>'
                + '<div class="ow-card-meta" style="margin-top:10px">'
                + meta('地址', esc(p.host))
                + meta('目标库', 'db' + num(p.db) + '（' + esc(p.prefix) + '*）')
                + meta('客户端', esc(p.client) + (p.ext ? '（已检测到扩展）' : '（未装扩展，走内置客户端）'))
                + meta('Redis 版本', esc(p.version || '未知'))
                + meta('内存占用', esc(p.memory || '未知'))
                + meta('鉴权', p.auth ? '已配置密码' : '未配置密码')
                + meta('写—读—删探针', p.rw ? '<span class="ow-rds-ok">通过</span>' : '<span class="ow-rds-bad">未通过</span>')
                + '</div></div>';
            box.innerHTML = h;
        });
    };

    function meta(k, v) {
        return '<div class="ow-card-meta-row"><span class="ow-card-meta-k">' + esc(k) + '</span>'
            + '<span class="ow-card-meta-v">' + v + '</span></div>';
    }

    /* ================= 配置 ================= */

    var KEYS = ['enable', 'host', 'port', 'pass', 'db', 'prefix', 'timeout',
                'mirror', 'mirror_keep', 'mirror_days', 'mirror_dm', 'stat', 'stat_days'];

    R.saveCfg = function () {
        var data = {}, i, el;
        for (i = 0; i < KEYS.length; i++) {
            el = $('owRdsCfg_' + KEYS[i]);
            if (el) data[KEYS[i]] = el.value;
        }
        var clr = $('owRdsCfg_pass_clear');
        if (clr && clr.checked) data.pass_clear = '1';

        OwApi.secure(A + 'cfg_save', data, function (r) {
            toast((r && r.msg) || (r && r.ok ? '已保存' : '保存失败'));
            if (r && r.ok) {
                // 密码框永远清空：服务端不回显，留空即「不修改」
                var pe = $('owRdsCfg_pass');
                if (pe) pe.value = '';
                if (clr) clr.checked = false;
                R.load();
            }
        });
    };

    function backfill(cfg) {
        for (var i = 0; i < KEYS.length; i++) {
            var k = KEYS[i];
            if (k === 'pass') continue;                 // 密码不回填
            var el = $('owRdsCfg_' + k);
            if (el && cfg[k] != null) el.value = cfg[k];
        }
    }

    R.flush = function () {
        OwChat.confirm('清空 Redis 中本插件前缀下的全部键？'
            + '镜像数据与当日计数会被删除，数据库里的每日统计不受影响。此操作不可撤销。', function () {
            OwApi.secure(A + 'flush', {}, function (r) {
                toast((r && r.msg) || (r && r.ok ? '已清空' : '清空失败'));
                if (r && r.ok) { R.load(); var p = $('owRdsPreview'); if (p) p.innerHTML = ''; }
            });
        });
    };

    /* ================= 启动 ================= */

    function boot() {
        if (booted || !$('owRdsKpi')) return;
        booted = true;
        R.load();
        R.probe();
    }

    function watch() {
        var main = d.getElementById('owAdminMain');
        if (!main || !w.MutationObserver) { boot(); return; }
        new w.MutationObserver(function () {
            if (!$('owRdsKpi')) { booted = false; return; }
            boot();
        }).observe(main, { childList: true, subtree: true });
        boot();
    }

    if (d.readyState === 'loading') {
        d.addEventListener('DOMContentLoaded', function () { setTimeout(watch, 0); });
    } else {
        setTimeout(watch, 0);
    }

    w.OwRdsAdmin = R;
})(window, document);
