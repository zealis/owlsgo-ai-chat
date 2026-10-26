/**
 * 垃圾信息控制插件 - 后台交互（v1.3.113）
 * 全局对象 OwSW；依赖 OwApi / esc / toast / owTr / owTabs / OwAdmin.uiPager / OwAdmin.uiBatchBar / OwApi.secure
 *
 * 页面是五个标签共用一张卡片；标签的显隐归核心通用轮子 owTabs（v1.3.113）——
 * 标签条上的 onclick="owTabs.click(this, event)" 就是全部接线，插件侧不再有一份。
 * 词库与访问申诉两张表都接核心的列表轮子（批量条 + 全选列 + .ow-table-wrap + uiPager）。
 *
 * ⚠️ 凡是写进**已存在节点**的文案（统计位、判定结果、确认弹窗）必须自己过 owTr：
 * i18n 的 MutationObserver 只看新插入的元素，改文本它看不见。toast 与新渲染的表格单元格
 * 是新增节点，观察器会翻，直接写中文即可。
 */
(function (w, d) {
    'use strict';
    var $ = function (id) { return d.getElementById(id); };

    var state = { page: 1, size: 30, total: 0 };
    var aState = { page: 1, size: 20, total: 0, pending: 0 };
    /* 词库文件清单的本地副本：只用来渲染表格，真正的启用状态在服务端
       （启停是即时路由，不随「保存」提交 —— 见 main.php 里 plugin_spam_lex_toggle 的说明） */
    var lexFiles = [];

    /* 每个标签要提交哪些字段。字段 id 统一是 owSpam_<key>，
       白名单在插件后端（plugin_spam_save）也写死了一份 —— 这里只决定「带不带」，
       能写哪些键最终由后端说了算。 */
    var GROUPS = {
        flood:  ['rate_win', 'rate_max', 'min_gap'],
        domain: ['domain_on', 'domain_list', 'domain_warn', 'domain_rules'],
        appeal: ['appeal_on', 'appeal_interval', 'appeal_note_limit'],
        ai:     ['ai_on', 'ai_action', 'ai_timeout', 'ai_daily', 'ai_base', 'ai_key', 'ai_model', 'ai_prompt'],
        lex:    ['lex_on', 'lex_replacement', 'lexapi_on', 'lexapi_skip_local', 'lexapi_id', 'lexapi_key',
                 'lexapi_action', 'lexapi_timeout', 'lexapi_permin', 'lexapi_daily',
                 'lex_check_on', 'lex_check_hours', 'lex_notify_on']
    };

    w.OwSW = {
        init: function () {
            w.OwAdmin.uiBatchBar({
                id: 'owSWBatch',
                chkCls: 'owSWChk',
                statBase: '',
                actions: [{ key: 'del', label: '批量删除所选敏感词', danger: true }],
                onExec: function (act, ids) { w.OwSW.batchDelete(ids); }
            });
            // 申诉条数显示在筛选行那一句里，这里的统计位只报「已选 N 条」，避免两个「共 N 条」
            w.OwAdmin.uiBatchBar({
                id: 'owSpamAppealBatch',
                chkCls: 'owAppealChk',
                statBase: '',
                actions: [{ key: 'del', label: '批量删除所选申诉', danger: true }],
                onExec: function (act, ids) { w.OwSW.appealBatchDel(ids); }
            });
            // 开关轨道同步用核心导出的 bindSwitches（chat.js 已挂到 window）。
            // 只圈本插件那张卡片：init 可能在其它后台页也跑，绑到 document 会把别人的开关重复绑。
            var card = $('owSpamTabs') && $('owSpamTabs').parentNode;
            if (card && w.bindSwitches) w.bindSwitches(card);
            this.list(1);
            this.appealList(1);
            this.lexList();
        },

        /* ---------- 分组保存 ---------- */
        save: function (group) {
            var keys = GROUPS[group];
            if (!keys) { toast('未知的设置分组'); return; }
            var data = { group: group };
            for (var i = 0; i < keys.length; i++) {
                var k = keys[i], el = $('owSpam_' + k);
                if (!el) continue;
                if (el.type === 'checkbox') data[k] = el.checked ? '1' : '0';
                else data[k] = el.value;
            }
            if (group === 'ai' && data.ai_key === '') delete data.ai_key;   // 空 = 不改动已存的密钥
            if (group === 'lex' && data.lexapi_key === '') delete data.lexapi_key;
            OwApi.secure('plugin_spam_save', data, function (r) {
                toast(r && r.msg ? r.msg : '保存失败');
                // 违禁词设置改动后词库概况要重算（打码串、启停范围都可能变），顺手刷一次
                if (r && r.ok && group === 'lex') w.OwSW.lexList();
                // IP 规则被「防自锁」拦下时不刷新页面：管理员往往要就地改名单再存一次
                if (r && r.ok && group === 'ip') w.OwSW.reloadSoon();
            });
        },

        reloadSoon: function () {
            setTimeout(function () { location.reload(); }, 700);
        },

        /* ---------- AI 测试 ---------- */
        /**
         * 判定结果写进**已存在**的 #owSpamAiStat：i18n 引擎只遍历新增元素里的文本节点，
         * 改现有节点的文本不会被翻译，所以这几句必须自己走 owTr（与升级面板同一做法）。
         * 耗时一律显示：慢是这条链路最常见的故障，光看「失败」两字分不清该调超时还是换地址。
         */
        aiTest: function () {
            var box = $('owSpamAiStat'), t = $('owSpamTest');
            if (!box || !t) return;
            if (!t.value.trim()) { toast(owTr('请先输入一段测试文本')); return; }
            box.textContent = owTr('判定中…（要等接口回话，可能要十几秒）');
            OwApi.post('plugin_spam_ai_test', { text: t.value }, function (r) {
                if (!r || !r.ok) {
                    box.textContent = owTr('测试失败：{msg}', { msg: (r && r.msg) || owTr('接口无响应') });
                    return;
                }
                var cost = r.ms ? '　' + owTr('本次耗时 {sec} 秒', { sec: (r.ms / 1000).toFixed(1) }) : '';
                var why = r.why ? '（' + r.why + '）' : '';
                if (r.verdict === 'spam') {
                    box.textContent = owTr('判定：垃圾信息') + why
                        + '　' + owTr('原始返回：{raw}', { raw: r.raw }) + cost;
                } else if (r.verdict === 'clean') {
                    box.textContent = owTr('判定：正常') + why + cost;
                } else {
                    box.textContent = owTr('判定失败（消息照常放行）：{err}', { err: r.raw }) + cost
                        + (r.quota_left === 'exhausted' ? '　' + owTr('※ 今日调用上限已用完') : '');
                }
            });
        },

        /* ---------- 访问申诉列表（与安全日志同一套轮子：筛选 + 批量 + 分页） ---------- */
        appealList: function (page) {
            var f = $('owSpamAppealFilter');
            OwApi.post('plugin_spam_appeal_list',
                { page: page || 1, size: aState.size, status: f ? f.value : '' }, function (r) {
                var table = $('owSpamAppealTable');
                if (!table) return;
                if (!r || !r.ok) { table.innerHTML = '<tr><td colspan="9" style="color:var(--ow-text-sub)">' + esc(owTr('加载失败')) + '</td></tr>'; return; }
                aState.total = r.data.total;
                aState.page = r.data.page;
                aState.pending = r.data.pending || 0;
                OwSW.appealRender(r.data.list);
                var stat = $('owSpamAppealStat');
                if (stat) stat.textContent = owTr('共 {total} 条申诉，待处理 {pending} 条',
                    { total: aState.total, pending: aState.pending });
                w.OwAdmin.uiPager($('owSpamAppealPager'), aState.page, aState.total, aState.size,
                    function (pg) { OwSW.appealList(pg); });
            });
        },

        appealRender: function (rows) {
            var table = $('owSpamAppealTable');
            var h = '<tr><th style="width:32px"><input type="checkbox" id="owAppealCheckAll" onchange="OwSW.appealToggleAll(this)"></th>'
                + '<th>ID</th><th>域名</th><th>申请人</th><th>说明</th><th>IP</th><th>提交时间</th><th>状态</th><th>操作</th></tr>';
            for (var i = 0; i < rows.length; i++) {
                var r = rows[i];
                var tag = r.status == 0 ? '<span class="ow-tag ow-tag-guest">待处理</span>'
                    : (r.status == 1 ? '<span class="ow-tag ow-tag-green">已通过</span>' : '<span class="ow-tag">已拒绝</span>');
                var who = esc(r.nick) + (r.kind == 'guest' ? '（游客）' : '');
                // 待处理行高亮：一屏几十条里要能一眼挑出没处理的（与内容举报同法）
                h += '<tr' + (r.status == 0 ? ' style="background:var(--ow-primary-soft)"' : '') + '>'
                    + '<td><input type="checkbox" class="owAppealChk" value="' + r.id + '" onchange="OwSW.appealSyncBatch()"></td>'
                    + '<td>' + r.id + '</td><td>' + esc(r.domain) + '</td><td>' + who + '</td>'
                    + '<td>' + esc(r.note) + '</td><td>' + esc(r.ip) + '</td>'
                    + '<td>' + esc(new Date(r.created_at * 1000).toLocaleString()) + '</td><td>' + tag + '</td><td>'
                    + (r.status == 0
                        ? '<a href="javascript:;" onclick="OwSW.appealHandle(' + r.id + ',1)">通过</a> '
                          + '<a href="javascript:;" onclick="OwSW.appealHandle(' + r.id + ',2)">拒绝</a> '
                        : '')
                    + '<a href="javascript:;" onclick="OwSW.appealDel(' + r.id + ')">删除</a></td></tr>';
            }
            if (!rows.length) h += '<tr><td colspan="9" style="color:var(--ow-text-sub)">暂无申诉记录</td></tr>';
            table.innerHTML = h;
            var all = $('owAppealCheckAll');
            if (all) all.checked = false;
            OwSW.appealSyncBatch();
        },

        appealToggleAll: function (cb) {
            var boxes = d.getElementsByClassName('owAppealChk');
            for (var i = 0; i < boxes.length; i++) boxes[i].checked = cb.checked;
            OwSW.appealSyncBatch();
        },

        appealSyncBatch: function () {
            w.OwAdmin.batchSync('owAppealChk', 'owSpamAppealBatch', '');
        },

        /** 批量删除只删记录，不动黑名单 —— 与单条删除同一条语义，确认文案必须说清 */
        appealBatchDel: function (ids) {
            w.OwAdmin.confirm(owTr('删除选中的 {n} 条申诉记录？（只删记录，不影响黑名单）', { n: ids.length }), function () {
                OwApi.secure('plugin_spam_appeal_batch', { ids: ids.join(',') }, function (r) {
                    toast(r && r.msg ? r.msg : '删除失败');
                    if (r && r.ok) OwSW.appealList(aState.page);
                });
            });
        },

        /** 通过 = 把这个域名从黑名单删掉，所以确认文案必须把这件事说在前面 */
        appealHandle: function (id, status) {
            // OwAdmin.confirm 写的是弹窗里**已存在**的节点，观察器翻不到 → 自己走 owTr
            var txt = owTr(status == 1
                ? '通过后该域名会从黑名单移除，所有人点它都能正常跳转。确认通过？'
                : '确认拒绝这条申诉？域名保持在黑名单里。');
            w.OwAdmin.confirm(txt, function () {
                OwApi.secure('plugin_spam_appeal_handle', { id: id, status: status }, function (r) {
                    toast(r && r.msg ? r.msg : '操作失败');
                    if (r && r.ok) OwSW.appealList(aState.page);
                });
            });
        },

        appealDel: function (id) {
            w.OwAdmin.confirm(owTr('删除这条申诉记录？（不影响黑名单）'), function () {
                OwApi.secure('plugin_spam_appeal_delete', { id: id }, function (r) {
                    toast(r && r.msg ? r.msg : '删除失败');
                    if (r && r.ok) OwSW.appealList(aState.page);
                });
            });
        },

        /* ---------- 以下词库部分：路由、表、轮子都没动（v1.3.107 只把统计与确认文案改走 owTr） ---------- */
        list: function (page) {
            OwApi.post('plugin_sensitive_words_admin', { page: page || 1, size: state.size }, function (r) {
                var table = $('owSWTable'), pager = $('owSWPager');
                if (!table || !r.ok) { if (table) table.innerHTML = '<tr><td style="color:var(--ow-text-sub)">加载失败</td></tr>'; return; }
                state.total = r.data.total;
                state.page = r.data.page;
                OwSW.render(r.data.list);
                w.OwAdmin.uiPager(pager, state.page, state.total, state.size, function (pg) { w.OwSW.list(pg); });
                w.OwAdmin.batchSync('owSWChk', 'owSWBatch', owTr('共 {n} 条敏感词', { n: state.total }));
            });
        },

        render: function (rows) {
            var table = $('owSWTable');
            var h = '<tr><th style="width:32px"><input type="checkbox" id="owSWCheckAll" onchange="OwSW.toggleAll(this)"></th>'
                + '<th>ID</th><th>敏感词</th><th>替换为</th><th>状态</th><th>操作</th></tr>';
            for (var i = 0; i < rows.length; i++) {
                var wd = rows[i];
                h += '<tr><td><input type="checkbox" class="owSWChk" value="' + wd.id + '" onchange="OwSW.syncBatch()"></td>'
                    + '<td>' + wd.id + '</td><td>' + esc(wd.word) + '</td><td>' + esc(wd.replacement) + '</td>'
                    + '<td>' + (wd.enabled == 1 ? '<span class="ow-tag ow-tag-green">启用</span>' : '<span class="ow-tag ow-tag-guest">停用</span>') + '</td>'
                    + '<td><a href="javascript:;" onclick="OwSW.wordToggle(' + wd.id + ',' + (wd.enabled == 1 ? 0 : 1) + ')">' + (wd.enabled == 1 ? '停用' : '启用') + '</a> '
                    + '<a href="javascript:;" onclick="OwSW.wordDel(' + wd.id + ')">删除</a></td></tr>';
            }
            if (!rows.length) h += '<tr><td colspan="6" style="color:var(--ow-text-sub)">词库为空</td></tr>';
            table.innerHTML = h;
            var all = $('owSWCheckAll');
            if (all) all.checked = false;
            OwSW.syncBatch();
        },

        toggleAll: function (cb) {
            var boxes = d.getElementsByClassName('owSWChk');
            for (var i = 0; i < boxes.length; i++) boxes[i].checked = cb.checked;
            OwSW.syncBatch();
        },

        syncBatch: function () {
            w.OwAdmin.batchSync('owSWChk', 'owSWBatch', owTr('共 {n} 条敏感词', { n: state.total }));
        },

        batchDelete: function (ids) {
            if (!ids || !ids.length) { toast(owTr('请先选择要删除的敏感词')); return; }
            w.OwAdmin.confirm(owTr('确认删除选中的 {n} 条敏感词？', { n: ids.length }), function () {
                OwApi.secure('plugin_sensitive_words_batch', { ids: ids.join(',') }, function (r) {
                    toast(r.msg);
                    if (r.ok) w.OwSW.list(state.page);
                });
            });
        },

        wordAdd: function () {
            OwApi.post('plugin_sensitive_words_add', { word: $('owWWord').value, replacement: $('owWRep').value }, function (r) {
                toast(r.msg);
                if (r.ok) w.OwSW.list(1);
            });
        },
        wordToggle: function (id, en) {
            OwApi.post('plugin_sensitive_words_toggle', { id: id, enabled: en }, function (r) { toast(r.msg); if (r.ok) w.OwSW.list(state.page); });
        },
        wordDel: function (id) {
            w.OwAdmin.confirm(owTr('确定删除该敏感词？'), function () {
                OwApi.secure('plugin_sensitive_words_del', { id: id }, function (r) {
                    toast(r.msg);
                    if (r.ok) w.OwSW.list(state.page);
                });
            });
        },

        /* ---------- 违禁词库（v1.4.0） ---------- */
        lexList: function () {
            OwApi.post('plugin_spam_lex_list', {}, function (r) {
                if (!r || !r.ok) return;
                lexFiles = r.data.files || [];
                OwSW.lexRender();
                OwSW.lexStat(r.data.status);
            });
        },

        lexRender: function () {
            var t = $('owSpamLexTable');
            if (!t) return;
            var h = '<tr><th>词库文件</th><th>大小</th><th>词条</th><th>状态</th><th>操作</th></tr>';
            if (!lexFiles.length) {
                h += '<tr><td colspan="5" style="color:var(--ow-text-sub)">目录里还没有词库文件：'
                    + '点上面的「在线下载词库」拉取整套，或自己把 txt 放进 data/lexicon 后点「重新扫描」</td></tr>';
            }
            for (var i = 0; i < lexFiles.length; i++) {
                var f = lexFiles[i];
                var size = f.size >= 1048576
                    ? (f.size / 1048576).toFixed(1) + ' MB'
                    : Math.max(1, Math.round(f.size / 1024)) + ' KB';
                h += '<tr><td class="ow-spam-lexname">' + esc(f.name) + '</td>'
                    + '<td>' + size + '</td>'
                    + '<td>' + (f.words < 0 ? '—' : (f.words || 0)) + '</td>'
                    + '<td>' + (f.enabled ? '<span class="ow-tag ow-tag-green">生效中</span>'
                        : '<span class="ow-tag ow-tag-guest">已停用</span>') + '</td>'
                    // 传下标而不是文件名：中文文件名进 onclick 属性要额外处理引号与转义，
                    // 下标一定是数字，且清单本来就有本地副本
                    + '<td><a href="javascript:;" onclick="OwSW.lexToggle(' + i + ',' + (f.enabled ? 0 : 1) + ')">'
                    + (f.enabled ? '停用' : '启用') + '</a></td></tr>';
            }
            t.innerHTML = h;
        },

        /** 状态行写在**已存在**的 #owSpamLexStat 上：i18n 观察器只看新插入的元素，必须自己过 owTr */
        lexStat: function (txt) {
            var el = $('owSpamLexStat');
            if (!el) return;
            el.textContent = owTr(txt || '');
        },

        lexToggle: function (i, en) {
            var f = lexFiles[i];
            if (!f) return;
            OwApi.post('plugin_spam_lex_toggle', { name: f.name, enabled: en ? 1 : 0 }, function (r) {
                toast(r && r.msg ? r.msg : '操作失败');
                if (r && r.ok) OwSW.lexList();
            });
        },

        lexAll: function (en) {
            if (!lexFiles.length) { toast(owTr('目录里还没有词库文件')); return; }
            OwApi.post('plugin_spam_lex_all', { enabled: en ? 1 : 0 }, function (r) {
                toast(r && r.msg ? r.msg : '操作失败');
                if (r && r.ok) OwSW.lexList();
            });
        },

        lexRescan: function () {
            OwApi.post('plugin_spam_lex_rescan', {}, function (r) {
                toast(r && r.msg ? r.msg : '扫描失败');
                if (r && r.ok) OwSW.lexList();
            });
        },

        lexCheck: function () {
            OwApi.post('plugin_spam_lex_check', {}, function (r) {
                toast(r && r.msg ? r.msg : '检测失败');
                if (r && r.ok) OwSW.lexList();
            });
        },

        /** 下载会覆盖 data/lexicon 下的词库文件 → 敏感接口：一次性票据 + 二次确认 */
        lexDownload: function () {
            w.OwAdmin.confirm(owTr('从词库仓库下载整套词库？同名文件会被覆盖。'), function () {
                OwApi.secure('plugin_spam_lex_download', {}, function (r) {
                    toast(r && r.msg ? r.msg : '下载失败');
                    if (r && r.ok) OwSW.lexList();
                });
            });
        },

        lexApiTest: function () {
            var box = $('owSpamLexApiStat'), t = $('owSpamLexApiTest');
            if (!box || !t) return;
            if (!t.value.trim()) { toast(owTr('请先输入一段测试文本')); return; }
            box.textContent = owTr('检测中…（要等接口回话）');
            OwApi.post('plugin_spam_lexapi_test', { text: t.value }, function (r) {
                if (!r || !r.ok) {
                    box.textContent = owTr('测试失败：{msg}', { msg: (r && r.msg) || owTr('接口无响应') });
                    return;
                }
                var cost = r.ms ? '　' + owTr('本次耗时 {sec} 秒', { sec: (r.ms / 1000).toFixed(1) }) : '';
                if (!r.api_ok) {
                    box.textContent = owTr('调用失败（消息照常放行）：{err}', { err: r.raw }) + cost;
                    return;
                }
                var wl = (r.words && r.words.length) ? r.words.join('、') : owTr('（接口未返回具体词）');
                box.textContent = (r.hit
                        ? owTr('检出违禁词 {n} 个：{words}', { n: r.word_count, words: wl })
                        : owTr('未检出违禁词'))
                    + '　' + owTr('打码后：{text}', { text: r.masked }) + cost;
            });
        }
    };

    /* 后台页 HTML 由 innerHTML 注入（script 不执行）：MutationObserver 检测表格出现后自动加载 */
    var mo = new MutationObserver(function () {
        var t = $('owSWTable');
        // ⚠️ 必须用标记防重入，不能只判「表格是空的」：
        // init() 里的 OwAdmin.uiBatchBar 会同步写 #owSWBatch 的 innerHTML，
        // 这次改动立刻再次触发本回调；而此时列表 XHR 还没回来、表格仍是 0 行，
        // 于是 init 被无限递归调用（每轮还发一个请求），表现就是一进页面浏览器直接卡死。
        // 标记挂在表格元素上：切走再切回时表格是新元素，仍能重新初始化。
        if (t && !t._inited) { t._inited = true; w.OwSW.init(); }
    });
    mo.observe(d.documentElement, { childList: true, subtree: true });
})(window, document);
