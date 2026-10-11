/**
 * email-verify · 后台配置页交互（v1.0.0）
 * 全局对象 OwEv；依赖后台主脚本暴露的 OwApi / toast / esc / OwAdmin（通用分页与批量轮子）。
 * 兼容老浏览器：只用 var / function，不用箭头函数、let/const、fetch。
 */
(function (w, d) {
    'use strict';
    var $ = function (id) { return d.getElementById(id); };
    function val(id, def) { var el = $(id); return el ? el.value : (def || ''); }

    /** 只收集当前通道用得上的字段：mail() 模式下 SMTP 那几栏是空壳，提交了也没意义 */
    function currentTrans() {
        var sel = $('owEvTransport');
        return sel && sel.value === 'mail' ? 'mail' : 'smtp';
    }

    w.OwEv = {
        showTrans: function () {
            var t = currentTrans();
            var rows = d.querySelectorAll('.ow-ev-trans'), i;
            for (i = 0; i < rows.length; i++) {
                rows[i].style.display = rows[i].getAttribute('data-owev-trans') === t ? '' : 'none';
            }
        },

        save: function () {
            var data = {
                enabled: val('owEvEnabled', '0'),
                transport: currentTrans()
            };
            var els = d.querySelectorAll('[data-owev]'), i, k;
            for (i = 0; i < els.length; i++) {
                k = els[i].getAttribute('data-owev');
                if (data.transport === 'mail' && k.indexOf('smtp_') === 0) continue;
                data[k] = els[i].value;
            }
            // 保存通道 = 能改登录/注册的验证凭证，走一次性票据
            OwApi.secure('plugin_email_verify_save', data, function (r) {
                toast(r.msg);
                if (r.ok) OwEv.showTrans();
            });
        },

        savePolicy: function () {
            var data = {}, els = d.querySelectorAll('[data-owev-set]'), i;
            for (i = 0; i < els.length; i++) data[els[i].getAttribute('data-owev-set')] = els[i].value;
            OwApi.secure('plugin_email_verify_policy', data, function (r) { toast(r.msg); });
        },

        /** 模板来源显示成中文：管理员必须知道现在生效的是哪一份，否则「改了没效果」无从排查 */
        srcLabel: function (src) {
            if (src === 'file') return '目录文件 data/mail-templates/（优先级最高，此处编辑不生效）';
            if (src === 'db') return '后台保存的模板';
            return '内置默认（尚未自定义）';
        },

        loadTpl: function () {
            var kind = val('owEvKind', 'register');
            OwApi.post('plugin_email_verify_template', { kind: kind }, function (r) {
                if (!r.ok || !r.data) { toast(r.msg || '模板读取失败'); return; }
                var s = $('owEvTplSubject'), b = $('owEvTplBody'), p = $('owEvTplSrc');
                if (s) s.value = r.data.subject || '';
                if (b) b.value = r.data.body || '';
                if (p) p.textContent = OwEv.srcLabel(r.data.source);
            });
        },

        restoreTpl: function () {
            OwApi.post('plugin_email_verify_template', { kind: val('owEvKind', 'register'), default: '1' }, function (r) {
                if (!r.ok || !r.data) { toast(r.msg || '读取失败'); return; }
                if ($('owEvTplSubject')) $('owEvTplSubject').value = r.data.subject;
                if ($('owEvTplBody')) $('owEvTplBody').value = r.data.body;
                toast('已填入内置默认，点「保存模板」即覆盖当前自定义');
            });
        },

        /** 占位符插到光标处（而不是无脑追加到末尾）：编辑长模板时追加等于重写 */
        paste: function (ph) {
            var b = $('owEvTplBody');
            if (!b) return;
            var s = b.selectionStart, e = b.selectionEnd, v = b.value || '';
            if (typeof s !== 'number') { b.value = v + ph; b.focus(); return; }
            b.value = v.slice(0, s) + ph + v.slice(e);
            b.selectionStart = b.selectionEnd = s + ph.length;
            b.focus();
        },

        saveTpl: function () {
            OwApi.secure('plugin_email_verify_template_save', {
                kind: val('owEvKind', 'register'),
                subject: val('owEvTplSubject'),
                body: val('owEvTplBody')
            }, function (r) {
                toast(r.msg);
                if (r.ok) OwEv.loadTpl();
            });
        },

        /** 预览走 sandbox iframe：模板是管理员自己写的 HTML，
         *  但把它塞进后台文档里执行等于给自己留一个 XSS 入口，脚本一律禁掉。 */
        preview: function () {
            var frame = $('owEvPrev');
            if (!frame) return;
            OwApi.post('plugin_email_verify_preview', {
                kind: val('owEvKind', 'register'),
                subject: val('owEvTplSubject'),
                body: val('owEvTplBody')
            }, function (r) {
                if (!r.ok || !r.data) { toast(r.msg || '预览失败'); return; }
                var doc = '<meta charset="utf-8"><style>body{margin:0;padding:14px;background:#fff;'
                    + 'font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;color:#1f2329}'
                    + '.sb{font-size:13px;color:#5b6470;border-bottom:1px solid #eee;padding-bottom:8px;margin-bottom:12px}</style>'
                    + '<div class="sb">主题：' + esc(r.data.subject) + '</div>' + r.data.html;
                frame.setAttribute('srcdoc', doc);
            });
        },

        /** 发信会真的往外投递，服务端把该路由标了 sensitive，必须走一次性票据 */
        test: function () {
            OwApi.secure('plugin_email_verify_test', { kind: val('owEvKind', 'register') }, function (r) {
                toast(r.msg);
            });
        },

        clearLog: function () {
            OwApi.secure('plugin_email_verify_log_clear', {}, function (r) {
                toast(r.msg);
                if (r.ok) OwEv.logs(1);   // 只重取列表那一块，不必把整个后台页重画一遍
            });
        },

        /* ---------- 发送记录：分页 / 筛选 / 批量删（v1.3.81） ----------
           复用后台已有的通用轮子，不另造一套：
             OwAdmin.uiPager     数字页码 + 省略号 + 跳页输入（执行日志、群聊审核都在用）
             OwAdmin.uiBatchBar  批量动作收进一个下拉 + 执行按钮 + 「已选 N 条」
             OwAdmin.batchSync   勾选变化时同步按钮启停与计数
           交互与安全日志一致，管理员在任何一页学的操作到另一页都通用。 */
        logs: function (page) {
            var rows = $('owEvLogRows');
            if (!rows || !w.OwAdmin) return;
            OwEv.logPage = Math.max(1, parseInt(page, 10) || 1);
            rows.innerHTML = '<tr><td colspan="6" class="ow-ev-note">正在读取…</td></tr>';
            OwApi.post('plugin_email_verify_logs', {
                page: OwEv.logPage, size: val('owEvLogSize', '20'),
                q: val('owEvLogQ'), kind: val('owEvLogKind'), status: val('owEvLogStatus')
            }, function (r) {
                if (!r || !r.ok) {
                    rows.innerHTML = '<tr><td colspan="6" class="ow-ev-note">'
                        + esc((r && r.msg) || '记录读取失败') + '</td></tr>';
                    return;
                }
                OwEv.logTotal = r.total || 0;
                OwEv.logPage = r.page || 1;
                OwEv.renderLogs(r.data || []);
                OwAdmin.uiPager('owEvLogPager', OwEv.logPage, OwEv.logTotal, r.size || 20, function (pg) { OwEv.logs(pg); });
                OwAdmin.uiBatchBar({
                    id: 'owEvLogBatch', chkCls: 'owEvLogChk',
                    actions: [{ key: 'del', label: '删除选中记录', danger: true }],
                    statBase: '共 ' + OwEv.logTotal + ' 条',
                    onExec: OwEv.logExec
                });
            });
        },

        renderLogs: function (list) {
            var rows = $('owEvLogRows'), all = $('owEvLogAll'), i, x, h = '';
            if (all) all.checked = false;
            if (!list.length) {
                // 空表分两种：整站没发过信，还是这一筛筛没了 —— 后者的出路是改筛选，不是等邮件
                rows.innerHTML = '<tr><td colspan="6" class="ow-ev-note">'
                    + (OwEv.logTotal ? '没有符合筛选条件的记录。' : '还没有发送记录。') + '</td></tr>';
                return;
            }
            for (i = 0; i < list.length; i++) {
                x = list[i];
                h += '<tr><td><input type="checkbox" class="owEvLogChk" value="' + parseInt(x.id, 10) + '" onchange="OwEv.logSync()"></td>'
                    + '<td style="white-space:nowrap">' + esc(OwEv.logTs(x.created_at)) + '</td>'
                    + '<td>' + esc(x.kind_cn || '—') + '</td>'
                    + '<td>' + esc(x.recipient || '—')
                    + '<div style="color:var(--ow-text-sub);font-size:11px">' + esc(x.subject || '') + '</div></td>'
                    + '<td class="ow-ev-' + esc(x.status) + '">' + esc(x.status_cn || x.status) + '</td>'
                    + '<td style="max-width:360px">' + (esc(x.error || '') || '—') + '</td></tr>';
            }
            rows.innerHTML = h;
            OwEv.logSync();
        },

        /** 时间按**看的人**的时钟显示（与安全日志同一口径）：服务器时区对排查没意义 */
        logTs: function (ts) {
            var t = new Date(parseInt(ts, 10) * 1000);
            function p(n) { return (n < 10 ? '0' : '') + n; }
            return p(t.getMonth() + 1) + '-' + p(t.getDate()) + ' ' + p(t.getHours()) + ':' + p(t.getMinutes());
        },

        logToggleAll: function (cb) {
            var boxes = document.getElementsByClassName('owEvLogChk'), i;
            for (i = 0; i < boxes.length; i++) boxes[i].checked = cb.checked;
            OwEv.logSync();
        },

        logSync: function () {
            if (w.OwAdmin) OwAdmin.batchSync('owEvLogChk', 'owEvLogBatch', '共 ' + (OwEv.logTotal || 0) + ' 条');
        },

        logKeydown: function (e) {
            e = e || w.event;
            if (e.keyCode === 13) {
                e.preventDefault ? e.preventDefault() : (e.returnValue = false);
                OwEv.logs(1);
            }
        },

        /** 批量动作：删除不可恢复，先过确认框，再走一次性票据（服务端已标 sensitive） */
        logExec: function (key, ids) {
            if (key !== 'del') return;
            OwAdmin.confirm('删除选中的 ' + ids.length + ' 条发送记录？删除后无法恢复。', function () {
                OwApi.secure('plugin_email_verify_log_del', { ids: ids.join(',') }, function (r) {
                    toast(r.msg);
                    if (r.ok) OwEv.logs(OwEv.logPage || 1);
                });
            });
        }
    };

    /* 后台页面 HTML 由 admin_plugin_page 异步注入，监听 #owAdminMain 变化后自动初始化 */
    function watchInit() {
        var main = $('owAdminMain');
        if (!main) return;
        var tryInit = function () {
            var en = $('owEvEnabled');
            if (en && en.getAttribute('data-owev-inited') !== '1') {
                en.setAttribute('data-owev-inited', '1');
                OwEv.showTrans();
                OwEv.loadTpl();
                OwEv.logs(1);          // 发送记录是异步取的：页面一注进来就把第一页拉回来
                var t = $('owEvKind');
                if (t) t.onchange = OwEv.loadTpl;
            }
        };
        if (typeof MutationObserver !== 'undefined') {
            new MutationObserver(tryInit).observe(main, { childList: true, subtree: true });
        } else {
            setInterval(tryInit, 300);
        }
        tryInit();
    }

    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', watchInit);
    else watchInit();
})(window, document);
