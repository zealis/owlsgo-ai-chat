/**
 * 内容举报插件 · 后台交互（v1.1.0）
 * 全局对象 OwCRAdmin；依赖 OwApi / esc / toast / OwAdmin.uiPager / uiBatchBar / batchSync / confirm / OwApi.secure
 *
 * 功能：
 *   - 规则配置 + AI 审核配置 + 通知昵称/头像的读写
 *   - 「填入默认提示词」按当前举报理由生成、「测试接口」直连一次看结果
 *   - 举报记录：通用列表轮子（关键词搜索 + 状态筛选 + 批量操作 + 分页）
 *   - 列表带出 AI 审核结论与举报截图
 */
(function (w, d) {
    'use strict';
    var $ = function (id) { return d.getElementById(id); };

    var st = { page: 1, size: 20, total: 0, status: -1, q: '' };
    var defPrompt = '';

    function val(id, def) {
        var el = $(id);
        return el ? el.value : (def === undefined ? '' : def);
    }
    function checked(id) {
        var el = $(id);
        return (el && el.checked) ? 1 : 0;
    }

    var OwCRAdmin = {
        init: function () {
            this.loadConfig();
            this.load(1);
        },

        /* ---------- 配置 ---------- */
        loadConfig: function () {
            OwApi.post('plugin_content_report_config_get', {}, function (r) {
                if (!r.ok) return;
                var c = r.data || {};
                if ($('owCRReasons')) $('owCRReasons').value = c.reasons || '';
                if ($('owCRInterval')) $('owCRInterval').value = c.interval || 0;
                if ($('owCRDescLimit')) $('owCRDescLimit').value = c.desc_limit || 200;
                if ($('owCRImageMax')) $('owCRImageMax').value = c.image_max || 3;
                if ($('owCRRequireDesc')) $('owCRRequireDesc').checked = !!c.require_desc;
                if ($('owCRAllowSelf')) $('owCRAllowSelf').checked = !!c.allow_self;
                if ($('owCRShowGuest')) $('owCRShowGuest').checked = !!c.show_guest;
                if ($('owCRShowLoss')) $('owCRShowLoss').checked = !!c.show_loss;
                if ($('owCRAllowImage')) $('owCRAllowImage').checked = !!c.allow_image;

                if ($('owCRAIOn')) $('owCRAIOn').checked = !!c.ai_on;
                if ($('owCRAIBase')) $('owCRAIBase').value = c.ai_base || '';
                if ($('owCRAIModel')) $('owCRAIModel').value = c.ai_model || '';
                if ($('owCRAIKey')) $('owCRAIKey').value = '';      // 密钥只写不读：永远留空
                if ($('owCRAITimeout')) $('owCRAITimeout').value = c.ai_timeout || 10;
                if ($('owCRAIContext')) $('owCRAIContext').value = c.ai_context || 21;
                if ($('owCRAIBatch')) $('owCRAIBatch').value = c.ai_batch || 3;
                if ($('owCRAIPrompt')) $('owCRAIPrompt').value = c.ai_prompt || '';
                if ($('owCRNotifyName')) $('owCRNotifyName').value = c.notify_name || '';
                if ($('owCRNotifyAvatar')) $('owCRNotifyAvatar').value = c.notify_avatar || '';
                OwCRAdmin.paintAvatar(c.notify_avatar_preview || c.notify_avatar || '');
                OwCRAdmin.bindAvatar();

                defPrompt = c.default_prompt || '';

                // 开关视觉同步（两个开关组各绑一次）
                if (typeof w.bindSwitches === 'function') {
                    if ($('owCRSwitches')) w.bindSwitches($('owCRSwitches'));
                    if ($('owCRAISwitches')) w.bindSwitches($('owCRAISwitches'));
                }

                // 状态行：一眼看到 AI 到底会不会跑、队列里还压着多少
                var box = $('owCRAITest');
                if (box) {
                    var ready = c.ai_ready ? '已就绪' : '未配置（举报只入库，不自动审核）';
                    box.innerHTML = '<span class="ow-cr-muted">AI 状态：' + esc(ready)
                        + '；待审核 ' + (c.pending_ai | 0) + ' 条；待超管处理 ' + (c.alert_count | 0) + ' 条'
                        + '；密钥：' + (c.key_set ? '已设置' : '未设置') + '</span>';
                }
            });
        },

        saveConfig: function () {
            OwApi.secure('plugin_content_report_config_save', {
                reasons: val('owCRReasons'),
                interval: val('owCRInterval', 0),
                desc_limit: val('owCRDescLimit', 200),
                image_max: val('owCRImageMax', 3),
                require_desc: checked('owCRRequireDesc'),
                allow_self: checked('owCRAllowSelf'),
                show_guest: checked('owCRShowGuest'),
                show_loss: checked('owCRShowLoss'),
                allow_image: checked('owCRAllowImage'),
                ai_on: checked('owCRAIOn'),
                ai_base: val('owCRAIBase'),
                ai_model: val('owCRAIModel'),
                ai_key: val('owCRAIKey'),
                ai_timeout: val('owCRAITimeout', 10),
                ai_context: val('owCRAIContext', 21),
                ai_batch: val('owCRAIBatch', 3),
                ai_prompt: val('owCRAIPrompt'),
                notify_name: val('owCRNotifyName'),
                notify_avatar: val('owCRNotifyAvatar')
            }, function (r) {
                toast(r.msg || (r.ok ? '已保存' : '保存失败'));
                if (r.ok) w.OwCRAdmin.loadConfig();
            });
        },

        /**
         * 按「当前举报理由」生成一份提示词填进输入框。
         * 服务端已算好一份（default_prompt，夹过上下限），优先用它 ——
         * 前端只在理由刚改完还没保存时按输入框内容现算，免得填进一段过期清单。
         */
        fillPrompt: function () {
            var p = defPrompt;
            if (!p) {
                var raw = val('owCRReasons').split(/\r\n|\r|\n/), list = '', n = 0, i, s;
                for (i = 0; i < raw.length; i++) {
                    s = raw[i].replace(/^\s+|\s+$/g, '');
                    if (s === '') continue;
                    n++;
                    list += n + '. ' + s + '\n';
                }
                p = '你是聊天站的内容审核员。下面给你一段聊天记录，其中标着【被举报】的那条是用户举报的消息，'
                    + '其余是它前后的上下文，用来判断语境。\n\n'
                    + '只要这段聊天命中下列任意一条，就判定为违规：\n' + list + '\n'
                    + '判定要求：\n'
                    + '1. 不要求举报人选对理由 —— 命中上面任意一条即算违规，一条都不命中才算正常；\n'
                    + '2. 必须结合上下文：玩笑、引用、转述、反讽、正常的技术讨论与观点争执都不算违规；\n'
                    + '3. 正常的链接分享、正常的联系方式交换不算违规；\n'
                    + '4. 证据不足或含义模糊时判为不违规（宁可放过，也不要误判）。\n\n'
                    + '只输出一个 JSON 对象，不要任何解释、不要代码块标记：\n'
                    + '{"violate": true 或 false, "reason": "命中的违规理由（未违规留空）", "note": "不超过 20 字的判断依据"}\n\n'
                    + '【聊天记录】\n{records}';
            }
            if ($('owCRAIPrompt')) {
                $('owCRAIPrompt').value = p;
                toast('已填入默认提示词，记得点「保存配置」（清空则回到自动生成）');
            }
        },

        testAI: function () {
            var box = $('owCRAITest');
            if (box) box.innerHTML = '<span class="ow-cr-muted">测试中…（一次调用通常 5～10 秒）</span>';
            OwApi.post('plugin_content_report_ai_test', {}, function (r) {
                if (!box) return;
                if (!r || !r.ok) {
                    box.innerHTML = '<span class="ow-cr-bad">测试失败：' + esc((r && r.msg) || '未知错误') + '</span>';
                    return;
                }
                var verdict = r.violate === 1 ? '判定违规' : (r.violate === 0 ? '判定正常' : '未判定');
                box.innerHTML = '<span class="ow-cr-ok">接口正常（' + (r.ms | 0) + ' 毫秒，提示词 '
                    + (r.prompt_len | 0) + ' 字）</span><br>'
                    + '<span class="ow-cr-muted">样例消息 → ' + esc(verdict)
                    + (r.reason ? '，命中：' + esc(r.reason) : '')
                    + (r.note ? '（' + esc(r.note) + '）' : '') + '</span>';
            });
        },

        /* ---------- 通知头像：与前台「换头像」同一套轮子 ----------
           上传走插件自己的路由（核心 Upload::handle(kind=avatar)，裁正方形 + 内容哈希去重）；
           「浏览插图」复用核心的 avatar_styles / avatar_variants 与 .ow-av-grid 那套样式。
           挑完立刻保存（单独一个接口），不必再点「保存配置」—— 与前台换头像的体感一致。 */

        paintAvatar: function (url) {
            var box = $('owCRNotifyAvatarPrev');
            if (!box) return;
            box.innerHTML = url ? '<img src="' + esc(url) + '" alt="通知头像">' : '';
        },

        setAvatar: function (url) {
            OwApi.secure('plugin_content_report_notify_avatar', { avatar: url || '' }, function (r) {
                if (!r || !r.ok) { toast((r && r.msg) || '保存失败'); return; }
                if ($('owCRNotifyAvatar')) $('owCRNotifyAvatar').value = r.avatar || '';
                OwCRAdmin.paintAvatar(r.preview || '');
                OwCRAdmin.gallery(false);
                toast(r.msg || '已更新');
            });
        },

        /** 绑定三个入口（可重复调用：每次都只是重新赋 onclick） */
        bindAvatar: function () {
            var up = $('owCRNotifyAvatarUp'), file = $('owCRNotifyAvatarFile');
            var gal = $('owCRNotifyAvatarGal'), def = $('owCRNotifyAvatarDef');
            if (!up || !file) return;
            up.onclick = function () { file.click(); };
            file.onchange = function () {
                var f = file.files && file.files[0];
                file.value = '';                       // 同一张图连选两次也要能触发 change
                if (!f) return;
                toast('上传中…');
                OwApi.upload('plugin_content_report_upload_avatar', f, {}, function (r) {
                    if (r && r.ok && r.url) OwCRAdmin.setAvatar(r.url);
                    else toast((r && r.msg) || '上传失败');
                });
            };
            if (def) def.onclick = function () { OwCRAdmin.setAvatar(''); };
            if (gal) gal.onclick = function () {
                var box = $('owCRNotifyAvatarBox');
                OwCRAdmin.gallery(!(box && box.style.display !== 'none'));   // 再点一次收起
            };
        },

        /**
         * 「浏览插图」两级：先选风格，再挑该风格里的一个变体（点哪张存哪张）。
         * 一级/二级的取数都走核心接口，与前台换头像完全同源。
         */
        gallery: function (show) {
            var box = $('owCRNotifyAvatarBox');
            if (!box) return;
            if (!show) { box.style.display = 'none'; box.innerHTML = ''; return; }
            box.style.display = '';
            box.innerHTML = '<div class="ow-av-loading">加载中…</div>';

            function renderStyles() {
                OwApi.post('avatar_styles', {}, function (r) {
                    if (!box) return;
                    if (!r || !r.ok || !r.data || !r.data.length) {
                        box.innerHTML = '<div class="ow-av-loading">风格加载失败，请稍后再试</div>';
                        return;
                    }
                    var h = '<div class="ow-av-grid">';
                    for (var i = 0; i < r.data.length; i++) {
                        var o = r.data[i];
                        h += '<button class="ow-av-cell" type="button" data-slug="' + esc(o.slug) + '" title="' + esc(o.label) + '">'
                           + '<img src="' + esc(o.url) + '" alt="" loading="lazy">'
                           + '<span>' + esc(o.label) + '</span></button>';
                    }
                    box.innerHTML = h + '</div><div class="ow-av-hint">先选一种插图风格，再挑其中一张。</div>';
                    var cells = box.getElementsByTagName('button');
                    for (var j = 0; j < cells.length; j++) {
                        (function (btn) {
                            btn.onclick = function () {
                                var slug = btn.getAttribute('data-slug');
                                if (slug) renderVariants(slug);
                            };
                        })(cells[j]);
                    }
                });
            }

            function renderVariants(slug) {
                box.innerHTML = '<div class="ow-av-loading">变体加载中…</div>';
                OwApi.post('avatar_variants', { avatar_style: slug }, function (r) {
                    if (!box) return;
                    if (!r || !r.ok || !r.data || !r.data.length) {
                        box.innerHTML = '<div class="ow-av-loading">变体加载失败</div>';
                        return;
                    }
                    var h = '<button class="ow-btn ow-btn-ghost ow-av-btn ow-av-back" type="button" id="owCRAvBack">全部风格</button>'
                          + '<div class="ow-av-subtitle">' + esc(slug) + '</div><div class="ow-av-grid">';
                    for (var i = 0; i < r.data.length; i++) {
                        var v = r.data[i];
                        h += '<button class="ow-av-cell" type="button" data-url="' + esc(v.url) + '" title="第 ' + esc(v.seed) + ' 号">'
                           + '<img src="' + esc(v.url) + '" alt="" loading="lazy"></button>';
                    }
                    box.innerHTML = h + '</div><div class="ow-av-hint">共 ' + r.data.length + ' 张，点一下即刻保存。</div>';
                    // ⚠️ 顺序有讲究：先只给「带 data-url 的变体」绑事件，再单独绑返回按钮 ——
                    //    反过来的话下面的循环会把返回按钮的 onclick 覆盖成一个空函数（前台同款坑）。
                    var cells = box.getElementsByTagName('button');
                    for (var j = 0; j < cells.length; j++) {
                        if (!cells[j].getAttribute('data-url')) continue;
                        (function (btn) {
                            btn.onclick = function () { OwCRAdmin.setAvatar(btn.getAttribute('data-url')); };
                        })(cells[j]);
                    }
                    var back = $('owCRAvBack');
                    if (back) back.onclick = renderStyles;
                });
            }

            renderStyles();
        },

        /* ---------- 举报列表（通用列表轮子） ---------- */
        load: function (page) {
            st.page = page || 1;
            st.q = val('owCRQ');
            st.status = parseInt(val('owCRState', '-1'), 10);
            if (isNaN(st.status)) st.status = -1;
            OwApi.post('plugin_content_report_list', {
                page: st.page, size: st.size, status: st.status, q: st.q
            }, function (r) {
                var table = $('owCRTable');
                if (!table) return;
                if (!r.ok) { table.innerHTML = '<tr><td class="ow-cr-empty">加载失败</td></tr>'; return; }
                var data = r.data || {};
                st.total = data.total | 0;
                w.OwCRAdmin.render(data.list || []);
                var pager = $('owCRPaging');
                if (pager && w.OwAdmin.uiPager) {
                    w.OwAdmin.uiPager(pager, st.page, st.total, st.size, function (pg) { w.OwCRAdmin.load(pg); });
                }
            });
        },

        reset: function () {
            if ($('owCRQ')) $('owCRQ').value = '';
            if ($('owCRState')) $('owCRState').value = '-1';
            this.load(1);
        },

        /** 勾选变化：同步批量条的计数与按钮状态 */
        sync: function () {
            if (w.OwAdmin.batchSync) w.OwAdmin.batchSync('owCRChk', 'owCRBatch', '共 ' + st.total + ' 条');
        },

        toggleAll: function (box) {
            var items = d.getElementsByClassName('owCRChk');
            for (var i = 0; i < items.length; i++) items[i].checked = !!box.checked;
            this.sync();
        },

        /** AI 审核结论单元格 */
        aiCell: function (row) {
            if (!parseInt(row.msg_id, 10)) {
                return '<span class="ow-cr-muted" title="举报的不是某条具体消息（从成员搜索里按人举报），已直接交超级管理员">已交超管</span>';
            }
            var s = row.ai_status || '';
            if (s === '') return '<span class="ow-cr-muted" title="未进入审核队列（未配置 AI、或提交时 AI 没开）">未送审</span>';
            if (s === 'pending') return '<span class="ow-tag ow-tag-guest">待审核</span>';
            if (s === 'skipped') {
                return '<span class="ow-tag ow-tag-owner" title="' + esc(row.ai_note || '') + '">已转人工</span>';
            }
            if (s === 'failed') return '<span class="ow-cr-bad" title="' + esc(row.ai_note || '') + '">审核失败</span>';
            if (row.ai_verdict === 'violate') {
                return '<span class="ow-tag ow-tag-red">违规</span>'
                    + (row.ai_hit ? '<br><span class="ow-cr-sub">' + esc(row.ai_hit) + '</span>' : '');
            }
            return '<span class="ow-tag ow-tag-green">正常</span>';
        },

        /** 截图单元格：最多展示 3 张，点开原图 */
        imgCell: function (row) {
            var imgs = [];
            try { imgs = JSON.parse(row.images || '[]') || []; } catch (e) { imgs = []; }
            if (!imgs.length) return '<span class="ow-cr-muted">—</span>';
            var h = '<div class="ow-cr-thumbs">';
            for (var i = 0; i < imgs.length && i < 3; i++) {
                h += '<a href="' + esc(imgs[i]) + '" target="_blank" rel="noopener">'
                   + '<img src="' + esc(imgs[i]) + '" alt="截图 ' + (i + 1) + '"></a>';
            }
            if (imgs.length > 3) h += '<span class="ow-cr-more">+' + (imgs.length - 3) + '</span>';
            return h + '</div>';
        },

        render: function (rows) {
            var table = $('owCRTable');
            if (!table) return;
            var h = '<tr><th style="width:32px"><input type="checkbox" id="owCRChkAll" onchange="OwCRAdmin.toggleAll(this)"></th>'
                + '<th>ID</th><th>举报人</th><th>被举报人</th><th>理由</th><th>补充说明</th><th>截图</th>'
                + '<th>来源</th><th>AI 审核</th><th>状态</th><th>时间</th><th>操作</th></tr>';
            for (var i = 0; i < rows.length; i++) {
                var row = rows[i];

                var statusTag;
                if (parseInt(row.status, 10) === 0) {
                    statusTag = parseInt(row.alerted, 10) === 1
                        ? '<span class="ow-tag ow-tag-red">待超管处理</span>'
                        : '<span class="ow-tag ow-tag-guest">待处理</span>';
                } else if (parseInt(row.status, 10) === 1) {
                    statusTag = '<span class="ow-tag ow-tag-green">已处理</span>';
                } else {
                    statusTag = '<span class="ow-tag">已忽略</span>';
                }

                var source = parseInt(row.room_id, 10) > 0
                    ? '群聊 #' + esc(row.room_id)
                    : (parseInt(row.msg_id, 10) > 0 ? '私聊' : '按人举报');
                if (parseInt(row.msg_id, 10) > 0) {
                    source += '<br><span class="ow-cr-sub">消息 #' + esc(row.msg_id) + '</span>';
                }

                var rowClass = parseInt(row.status, 10) === 0 ? ' class="ow-cr-pending"' : '';

                h += '<tr' + rowClass + '>'
                    + '<td><input type="checkbox" class="owCRChk" value="' + esc(row.id) + '" onchange="OwCRAdmin.sync()"></td>'
                    + '<td>' + esc(row.id) + '</td>'
                    + '<td>' + esc(row.reporter_nick) + '<br><span class="ow-cr-sub">#' + esc(row.reporter_id) + '</span></td>'
                    + '<td>' + esc(row.target_nick) + '<br><span class="ow-cr-sub">#' + esc(row.target_uid) + '</span></td>'
                    + '<td>' + esc(row.reason) + '</td>'
                    + '<td class="ow-cr-desc">' + (row.description ? esc(row.description) : '<span class="ow-cr-muted">—</span>') + '</td>'
                    + '<td>' + this.imgCell(row) + '</td>'
                    + '<td>' + source + '</td>'
                    + '<td>' + this.aiCell(row) + '</td>'
                    + '<td>' + statusTag + '</td>'
                    + '<td>' + (row.created_at ? esc(new Date(row.created_at * 1000).toLocaleString()) : '—') + '</td>'
                    + '<td class="ow-cr-ops">'
                    + (parseInt(row.status, 10) === 0
                        ? '<a href="javascript:;" onclick="OwCRAdmin.handle(' + row.id + ',1)">已处理</a>'
                          + '<a href="javascript:;" onclick="OwCRAdmin.handle(' + row.id + ',2)">忽略</a>'
                        : '')
                    + '<a href="javascript:;" onclick="OwCRAdmin.del(' + row.id + ')">删除</a>'
                    + '</td></tr>';
            }
            if (!rows.length) h += '<tr><td colspan="12" class="ow-cr-empty">没有符合条件的举报</td></tr>';
            table.innerHTML = h;

            // 批量条 + 计数（与核心其它后台页同一套：下拉动作 + 执行按钮 + 统计位）
            if (w.OwAdmin.uiBatchBar) {
                w.OwAdmin.uiBatchBar({
                    id: 'owCRBatch',
                    chkCls: 'owCRChk',
                    actions: [
                        { key: 'handled', label: '标记为已处理' },
                        { key: 'ignored', label: '标记为已忽略' },
                        { key: 'delete', label: '删除举报记录', danger: true }
                    ],
                    statBase: '共 ' + st.total + ' 条',
                    onExec: function (op, ids) { w.OwCRAdmin.batch(op, ids); }
                });
            }
        },

        handle: function (id, status) {
            OwApi.secure('plugin_content_report_handle', { id: id, status: status }, function (r) {
                toast(r.msg || (r.ok ? '已更新' : '操作失败'));
                if (r.ok) w.OwCRAdmin.load(st.page);
            });
        },

        del: function (id) {
            w.OwAdmin.confirm('确定删除该举报记录？', function () {
                OwApi.secure('plugin_content_report_delete', { id: id }, function (r) {
                    toast(r.msg || (r.ok ? '已删除' : '操作失败'));
                    if (r.ok) w.OwCRAdmin.load(st.page);
                });
            });
        },

        /** 批量操作：接口只认逗号分隔的 id 串（OwApi.post 会把数组折成 "1,2,3"） */
        batch: function (op, ids) {
            var text = op === 'delete'
                ? '确定删除选中的 ' + ids.length + ' 条举报记录？'
                : (op === 'handled' ? '把选中的 ' + ids.length + ' 条标记为已处理？'
                                    : '把选中的 ' + ids.length + ' 条标记为已忽略？');
            w.OwAdmin.confirm(text, function () {
                OwApi.secure('plugin_content_report_batch', { op: op, ids: ids.join(',') }, function (r) {
                    toast(r.msg || (r.ok ? '操作完成' : '操作失败'));
                    if (r.ok) w.OwCRAdmin.load(st.page);
                });
            });
        }
    };

    w.OwCRAdmin = OwCRAdmin;

    /* 后台页 HTML 由 innerHTML 注入（script 不执行）：检测表格出现后自动初始化 */
    var mo = new MutationObserver(function () {
        var t = $('owCRTable');
        if (t && !t._inited) { t._inited = true; OwCRAdmin.init(); }
    });
    mo.observe(d.documentElement, { childList: true, subtree: true });
})(window, document);
