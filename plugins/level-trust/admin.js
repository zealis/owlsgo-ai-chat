/**
 * 等级信任 —— 后台交互
 * 全局对象 OwLT：概览 / 活跃趋势 / 参数设置 / 用户查询与操作。
 * 兼容老浏览器：仅使用 var / function，不用箭头函数、let/const、fetch。
 *
 * ⚠️ 本文件随合并资源包在**所有**后台页加载，非本页时容器不存在 → 统一经 boot() 判空。
 * 插件页是点菜单后由 Ajax 塞进 #owAdminMain 的，所以要 MutationObserver 等容器出现。
 */
(function (w, d) {
    'use strict';
    var $ = function (id) { return d.getElementById(id); };
    var LABEL = (w.OwLevel && w.OwLevel.LABEL) || {
        login: '登录', gm: '群发言', gfirst: '群聊首条',
        pfirst: '私聊首条', fup: '上传文件', fdl: '下载文件', fact: '群文件操作'
    };
    var booted = false;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
    function taskName(k) { return LABEL[k] || k; }
    /**
     * 一行「标签 : 值」。
     * @param {string} k  标签（纯文本，转义）
     * @param {string} v  值。**若已是 HTML 片段会原样输出**，纯文本请自行先 esc()。
     *   v1.2.49 改签名：以前这里统一 esc(v)，结果把 OwLevel.badge() 返回的
     *   HTML 片段（<span class="ow-lt-badge">Lv.N</span>）转义成纯文本，
     *   后台等级查询里直接打印出标签源码（用户报的 bug）。
     *   现在与项目其它地方的口径一致：**默认按 HTML 输出，调用方自己转义纯文本**。
     *   本文件内所有调用点的纯文本都已显式套 esc()。
     */
    function row(k, v) {
        return '<div class="ow-card-meta-row"><span class="ow-card-meta-k">' + esc(k)
            + '</span><span class="ow-card-meta-v">' + (v == null ? '' : v) + '</span></div>';
    }

    /* ================= 概览（v1.1.5：改 KPI 卡片，与全站后台统计风格统一） ================= */
    function kpi(v, label) {
        return '<div class="ow-lt-kpi"><b>' + v + '</b><span>' + esc(label) + '</span></div>';
    }

    function renderStats(r) {
        var box = $('owLTStats');
        if (!box) return;
        var html = '<h3 style="margin:0 0 10px">概览</h3><div class="ow-lt-kpis">'
            + kpi((r.users || 0), '有等级记录（人）')
            + kpi('Lv.' + (r.median || 1), '等级中位数')
            + kpi('Lv.' + (r.avg || 0), '平均等级')
            + kpi(String(r.gating) === '1' ? '已开启' : '已关闭', '等级限制')
            + '</div>';
        // 阶段分布：圆点色 = 阶段色（数据性质色板，见 style.css 顶部注释）；
        // 名称与区间由服务端按当前配置现算（stage_meta），改阈值后这里自动跟着变。
        var metas = r.stage_meta || [];
        if (metas.length) {
            html += '<div class="ow-lt-kpis ow-lt-dist">';
            for (var i = 0; i < metas.length; i++) {
                var m = metas[i];
                html += '<div class="ow-lt-kpi"><b><i class="ow-lt-dot ow-lt-s' + m.no + '"></i>'
                    + ((r.dist && r.dist[m.no]) || 0) + '</b><span>'
                    + esc(m.name) + ' ' + esc(m.range) + '</span></div>';
            }
            html += '</div>';
        }
        box.innerHTML = html;
    }

    /* ================= 活跃趋势（v1.1.5：改竖条小倍数图，与全站图表风格统一） =================
       每个指标一张小图、各自归一化（放一张图共用一个刻度的话，人数会把「建群」压成一条平线）。
       柱色一律 --ow-primary-strong，零值柱 --ow-border；不再有写死色值。 */
    var TREND_FIELDS = [
        { k: 'actives', label: '活跃用户', unit: '人' },
        { k: 'upgrades', label: '升级次数', unit: '次' },
        { k: 'msgs', label: '有效发言', unit: '条' },
        { k: 'files', label: '文件操作', unit: '次' },
        { k: 'rooms', label: '建群', unit: '个' }
    ];

    function renderTrend(trend, note) {
        var box = $('owLTTrend');
        if (!box) return;
        if (!trend || !trend.length) { box.innerHTML = '<p class="ow-panel-empty">暂无数据</p>'; return; }

        var maxes = {};
        TREND_FIELDS.forEach(function (f) {
            var m = 0;
            trend.forEach(function (d) { if (d[f.k] > m) m = d[f.k]; });
            maxes[f.k] = m || 1;
        });

        var html = '<div class="ow-lt-trend">';
        TREND_FIELDS.forEach(function (f) {
            html += '<div class="ow-lt-tchart">'
                + '<div class="ow-lt-tchart-h"><span>' + f.label + '</span><em>峰值 '
                + maxes[f.k] + ' ' + f.unit + '</em></div>'
                + '<div class="ow-lt-tbars">';
            for (var i = 0; i < trend.length; i++) {
                var v = trend[i][f.k] || 0;
                var h = v > 0 ? Math.max(4, Math.round(v / maxes[f.k] * 100)) : 2;
                html += '<i class="' + (v > 0 ? '' : 'zero') + '" style="height:' + h + '%" title="'
                    + esc(trend[i].day) + '：' + v + ' ' + f.unit + '"></i>';
            }
            html += '</div></div>';
        });
        // 五张图柱位完全一致（同一组日期、同一套 flex），共用一条日期轴即可
        html += '</div>'
            + '<div class="ow-lt-tx"><span>' + esc(trend[0].day) + '</span>'
            + '<span>' + esc(trend[trend.length - 1].day) + '</span></div>'
            + '<div class="ow-lv-tip">' + esc(note || '') + '</div>';
        box.innerHTML = html;
    }

    /* ================= 参数设置 ================= */
    function collectCfg() {
        var cfg = {};
        var inputs = d.querySelectorAll('[id^="owCfg_"]');
        for (var i = 0; i < inputs.length; i++) cfg[inputs[i].id.substring(7)] = inputs[i].value;
        return cfg;
    }

    w.OwLT = {
        init: function () { boot(); },

        load: function () {
            OwApi.post('plugin_level_trust_stats', {}, function (r) {
                if (!r.ok) { toast(r.msg); return; }
                renderStats(r);
                renderTrend(r.trend, r.trend_note);
            });
        },

        /** 保存全部参数（一次提交，避免分多次保存出现「一半新一半旧」的中间态） */
        saveCfg: function () {
            var cfg = collectCfg();
            if (!Object.prototype.hasOwnProperty.call(cfg, 'gating')) { toast('配置表单不存在'); return; }
            if (Object.prototype.hasOwnProperty.call(cfg, 'room_tiers')) {
                // 与服务端同一套校验：整段都解析不出来就别提交，免得把名额全清零
                var lines = String(cfg.room_tiers).split(/[\r\n,;]+/);
                var ok = lines.some(function (l) { return /^\s*\d{1,4}\s*[:：]\s*\d{1,4}\s*$/.test(l); });
                if (!ok) { toast('名额档位格式不对，每行需形如「等级:名额」，例如 10:3'); return; }
            }
            OwApi.secure('plugin_level_trust_cfg_save', cfg, function (r) {
                toast(r.msg || (r.ok ? '已保存' : '保存失败'));
                // 配置一改，前台等级页的公式/解锁表就变了 → 重载让管理员直接看到新文案
                if (r.ok) w.location.reload();
            });
        },

        /* ---------- 用户查询 ---------- */
        query: function () {
            var uid = parseInt(($('owLTUid') || {}).value, 10);
            if (!uid || uid <= 0) { toast('请填写用户 ID'); return; }
            OwApi.post('plugin_level_trust_user', { uid: uid }, function (r) {
                var box = $('owLTUser');
                if (!box) return;
                if (!r.ok) { box.innerHTML = '<p class="ow-panel-empty">' + esc(r.msg) + '</p>'; return; }

                var html = '<div class="ow-card-meta" style="border-top:0;padding-top:0">'
                    // ⚠️ nickname 是**用户自己设的**，必须转义 —— row() 现在按 HTML 输出。
                    // 昵称含 < > & 时不转义就是存储型 XSS（后台管理员打开这页即中招）。
                    + row('用户', esc(r.nickname) + '（ID ' + r.uid + '）' + (r.role === 'admin' ? ' · 超级管理员' : ''))
                    + row('等级', (w.OwLevel ? w.OwLevel.badge(r.level) : esc('Lv.' + r.level)) + ' ' + esc(r.stage))
                    + row('累计完成', r.days + ' 天 · 加权 ' + r.weight + ' 天')
                    + row('登录', '连续 ' + r.login_streak + ' 天 · 累计 ' + r.login_days + ' 天')
                    + row('今日', (r.frozen ? '已冻结' : (r.settled ? '已完成并结算' : '进行中'))
                        + ' · 本周补签 ' + r.patch_used + ' 次 · 本月保护 ' + r.protect_used + ' 次')
                    + '</div>';

                html += '<div class="ow-lt-tasks">';
                for (var i = 0; i < (r.tasks || []).length; i++) {
                    var t = r.tasks[i];
                    var done = t.cur >= t.n;
                    html += '<div class="ow-lt-task"><span class="ow-lt-task-k">' + esc(taskName(t.k))
                        + '</span><span class="ow-lt-task-v' + (done ? ' done' : '') + '">'
                        + t.cur + ' / ' + t.n + '</span></div>';
                }
                html += '</div>';

                html += '<div class="ow-modal-actions" style="margin-top:12px">'
                    + '<button class="ow-btn ow-btn-ghost" onclick="OwLT.op(' + r.uid + ',\'patch\')">补签（每周 1 次）</button>'
                    + '<button class="ow-btn ow-btn-ghost" onclick="OwLT.op(' + r.uid + ',\''
                    + (r.frozen ? 'unfreeze' : 'freeze') + '\')">' + (r.frozen ? '解除冻结' : '冻结今日') + '</button>'
                    + '<button class="ow-btn ow-btn-primary" onclick="OwLT.setLevel(' + r.uid + ',' + r.level + ',' + r.days + ')">直接设置</button>'
                    + '</div>';
                box.innerHTML = html;
            });
        },

        op: function (uid, op) {
            OwApi.secure('plugin_level_trust_op', { uid: uid, op: op }, function (r) {
                toast(r.msg || (r.ok ? '已操作' : '操作失败'));
                if (r.ok) w.OwLT.query();
            });
        },

        setLevel: function (uid, lv, days) {
            OwChat.openModal(
                '<h3>设置等级</h3>'
                + '<div class="ow-form-item"><label>等级（≥1）</label>'
                + '<input class="ow-input" id="owLtSetLv" type="number" min="1" value="' + lv + '"></div>'
                + '<div class="ow-form-item"><label>累计完成天数（≥0）</label>'
                + '<input class="ow-input" id="owLtSetDays" type="number" min="0" value="' + days + '">'
                + '<p style="font-size:12px;color:var(--ow-text-sub);margin-top:4px">'
                + '等级一般由公式自动算出；这里只用于迁移或纠错，直接改数值不会同步改加权。</p></div>'
                + '<div class="ow-modal-actions">'
                + '<button class="ow-btn ow-btn-ghost" onclick="OwChat.closeModal()">取消</button>'
                + '<button class="ow-btn ow-btn-primary" onclick="OwLT.doSet(' + uid + ')">保存</button>'
                + '</div>', 420);
        },

        doSet: function (uid) {
            var n = parseInt(($('owLtSetLv') || {}).value, 10);
            var dd = parseInt(($('owLtSetDays') || {}).value, 10);
            if (!n || n < 1) { toast('等级需为 ≥1 的整数'); return; }
            if (isNaN(dd) || dd < 0) { toast('累计天数需为 ≥0 的整数'); return; }
            OwApi.secure('plugin_level_trust_op', { uid: uid, op: 'set', level: n, days: dd }, function (r) {
                toast(r.msg || (r.ok ? '已设置' : '设置失败'));
                if (r.ok) { OwChat.closeModal(); OwLT.query(); }
            });
        }
    };

    /* ================= 启动 ================= */
    function boot() {
        if (booted || !$('owLTStats')) return;
        booted = true;
        w.OwLT.load();
    }

    function watch() {
        var main = d.getElementById('owAdminMain');
        if (!main || !w.MutationObserver) { boot(); return; }
        new w.MutationObserver(function () {
            if (!$('owLTStats')) { booted = false; return; }
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
