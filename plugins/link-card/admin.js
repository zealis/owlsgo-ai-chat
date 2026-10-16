/**
 * 链接卡片插件 · 后台交互（link-card v1.0.0）
 * 全局对象 OwLC；依赖 OwApi / esc / toast / OwAdmin.confirm / bindSwitches / OwTip
 * （均由主程序 chat.js 挂到 window，后台页已加载）。
 *
 * 插件后台页由 Ajax 注入 #owAdminMain（内联 <script> 不执行），
 * 因此用 MutationObserver 等 #lcList 出现后再初始化（与公告、敏感词等插件同一模式）。
 * 兼容老浏览器：仅使用 var / function，不用箭头函数与 const/let。
 */
(function (w, d) {
    'use strict';
    var $ = function (id) { return d.getElementById(id); };

    /* 平台定义：k=字段名 n=显示名 t=meta 说明（进 ⓘ 气泡） c=徽标类 */
    var PLATS = [
        { k: 'og', n: 'Open Graph',    t: 'og:title / og:description / og:image / og:url / og:type，Facebook、LinkedIn、Discord、Slack 等', c: 'ow-tag-admin' },
        { k: 'tw', n: 'X（Twitter）',  t: 'twitter:card / twitter:title / twitter:description / twitter:image', c: 'ow-tag-bot' },
        { k: 'wx', n: '微信',          t: 'wx.share.title / wx.share.desc / wx.share.img，微信内置浏览器读取的私有标签', c: 'ow-tag-green' },
        { k: 'qq', n: 'QQ',            t: 'qq:share:title / qq:share:description / qq:share:image，QQ 内置浏览器读取的私有标签', c: 'ow-tag-vip' }
    ];

    var OwLC = {
        cards: [],       // 最近一次列表数据（编辑弹窗回填用）
        mask: null,      // 当前打开的弹窗遮罩

        /* ---------- 启动：等 #lcList 被注入后台主区 ---------- */
        boot: function () {
            // 后台页 HTML 由 innerHTML 注入，开关的视觉同步要自己绑（核心只自动收 OwTip 气泡）
            if (w.bindSwitches && $('lcMsgOn')) w.bindSwitches($('lcMsgOn'));
            OwLC.load();
        },

        /* ---------- 消息卡片设置（总开关 + 域名黑名单） ---------- */
        saveMsgCfg: function () {
            OwApi.post('plugin_link_card_cfg', {
                msg_on: $('lcMsgOn') && $('lcMsgOn').checked ? 1 : 0,
                blocked: ($('lcBlocked') || {}).value || ''
            }, function (r) {
                toast(r.msg);
                if (!r.ok) return;
                OwLC.setCacheInfo(r.cache_count);
                // 黑名单是随聊天页下发的：已打开的聊天页要刷新才生效，这里说清楚
                toast('已保存，已打开的聊天页刷新后生效');
            });
        },

        /* ---------- 清空预览缓存（敏感操作：一次性票据） ---------- */
        clearCache: function () {
            w.OwAdmin.confirm('清空后所有链接会重新抓取一次（不会删除卡片设置）。确定清空预览缓存？', function () {
                OwApi.secure('plugin_link_card_cache_clear', {}, function (r) {
                    toast(r.msg);
                    if (r.ok) OwLC.setCacheInfo(r.cache_count);
                });
            });
        },

        setCacheInfo: function (n) {
            var el = $('lcCacheInfo');
            if (el) el.textContent = '已缓存 ' + (parseInt(n, 10) || 0) + ' 个链接';
        },

        /* ---------- 列表 ---------- */
        load: function () {
            OwApi.post('plugin_link_card_list', {}, function (r) {
                if (!r.ok) { toast(r.msg); return; }
                OwLC.render(r.cards || []);
            });
        },

        render: function (cards) {
            OwLC.cards = cards;
            var list = $('lcList'), notice = $('lcNotice'), count = $('lcCount');
            if (!list) return;

            var on = 0, i;
            for (i = 0; i < cards.length; i++) if (cards[i].enabled == 1) on++;
            if (count) count.textContent = '共 ' + cards.length + ' 张' + (on ? '，' + on + ' 张启用中' : '');

            /* 多张同时启用的说明：抓取器只认第一张 */
            if (notice) {
                notice.innerHTML = on > 1
                    ? '<div class="ow-alert lc-alert">当前有 <b>' + on + '</b> 张卡片处于启用状态，各平台将按列表顺序取<b>第一张</b>生成卡片；如只想使用某一张，请把其余卡片停用。</div>'
                    : '';
            }

            if (!cards.length) {
                list.innerHTML = '<div class="lc-empty">还没有链接卡片。点击「新建卡片」填写标题、描述与封面图，一次填写即可按需启用 Open Graph / X / 微信 / QQ 平台的链接预览。</div>';
                return;
            }

            var effective = -1;   // 第一张启用中的卡片下标
            for (i = 0; i < cards.length; i++) if (cards[i].enabled == 1) { effective = i; break; }

            var h = '';
            for (i = 0; i < cards.length; i++) h += OwLC.cardHtml(cards[i], i === effective);
            list.innerHTML = h;
            if (w.bindSwitches) w.bindSwitches(list);
        },

        cardHtml: function (c, effective) {
            var off = c.enabled != 1;
            var plats = '';
            for (var i = 0; i < PLATS.length; i++) {
                var p = PLATS[i], key = 'p_' + p.k;
                plats += c[key] == 1
                    ? '<span class="ow-tag ' + p.c + '">' + w.esc(p.n) + '</span>'
                    : '<span class="lc-plat-off">' + w.esc(p.n) + '</span>';
            }
            return '<div class="lc-card' + (off ? ' is-off' : '') + '">'
                + '<div class="lc-card-info">'
                + '<div class="lc-card-hd"><span class="lc-card-name">' + w.esc(c.name) + '</span>'
                + (effective ? '<span class="ow-tag ow-tag-admin">生效中</span>' : '')
                + (off ? '<span class="ow-tag ow-tag-guest">已停用</span>' : '')
                + '</div>'
                + '<div class="lc-card-url">' + w.esc(c.url) + '</div>'
                + '<div class="lc-card-desc"><b>' + w.esc(c.title) + '</b>'
                + (c.description ? '　' + w.esc(c.description) : '') + '</div>'
                + '<div class="lc-card-plats">' + plats + '</div>'
                + '</div>'
                + '<div class="lc-card-side">'
                + '<div class="ow-switch-row" title="启用 / 停用">'
                + '<input type="checkbox" class="ow-switch-input" id="lcSw' + c.id + '" aria-label="启用卡片"'
                + (off ? '' : ' checked') + ' onchange="OwLC.toggle(' + c.id + ', this.checked ? 1 : 0)">'
                + '<label class="ow-switch' + (off ? '' : ' is-on') + '" for="lcSw' + c.id + '"></label>'
                + '</div>'
                + '<div class="lc-card-ops">'
                + '<a href="javascript:;" onclick="OwLC.edit(' + c.id + ')">编辑</a>'
                + '<a href="javascript:;" class="lc-del" onclick="OwLC.del(' + c.id + ')">删除</a>'
                + '</div></div></div>';
        },

        /* ---------- 新建 / 编辑弹窗 ---------- */
        edit: function (id) {
            var c = null;
            if (id > 0) {
                for (var i = 0; i < OwLC.cards.length; i++) {
                    if (OwLC.cards[i].id == id) { c = OwLC.cards[i]; break; }
                }
                if (!c) { toast('卡片不存在'); return; }
            }
            var isNew = !c;
            var v = function (k, dft) { return c && c[k] !== undefined && c[k] !== null && c[k] !== '' ? c[k] : dft; };

            var f = '';
            f += '<div class="ow-form-item"><label>卡片名称</label>'
                + '<input class="ow-input" id="lcFName" maxlength="64" placeholder="用于后台识别，如「首页分享」" value="' + w.esc(v('name', '')) + '">'
                + '<p style="font-size:12px">仅后台显示，不会写进 meta 标签。</p></div>';
            f += '<div class="ow-form-item"><label>规范链接</label>'
                + '<input class="ow-input" id="lcFUrl" placeholder="https://example.com/" value="' + w.esc(v('url', '')) + '">'
                + '<p style="font-size:12px">og:url，分享链接的规范地址，需为 http/https 绝对地址。</p></div>';
            f += '<div class="ow-form-item"><label>标题</label>'
                + '<input class="ow-input" id="lcFTitle" maxlength="255" placeholder="分享时显示的标题" value="' + w.esc(v('title', '')) + '">'
                + '<p style="font-size:12px">四平台通用：og:title / twitter:title / wx.share.title / qq:share:title。</p></div>';
            f += '<div class="ow-form-item"><label>描述</label>'
                + '<textarea class="ow-input" id="lcFDesc" rows="3" maxlength="300" placeholder="分享时显示的简介（可留空）" style="width:100%;resize:vertical">' + w.esc(v('description', '')) + '</textarea>'
                + '<p style="font-size:12px">四平台通用描述，建议 100 字以内，超出部分平台会自行截断。</p></div>';
            f += '<div class="ow-form-item"><label>封面图</label>'
                + '<input class="ow-input" id="lcFImage" placeholder="https://example.com/cover.jpg" value="' + w.esc(v('image', '')) + '">'
                + '<p style="font-size:12px">og:image，须为 https:// 开头、可公开访问的绝对地址；建议宽高比 1.91:1。</p></div>';
            f += '<div class="lc-form-2col">'
                + '<div class="ow-form-item"><label>内容类型</label>'
                + '<select class="ow-input" id="lcFOgType">'
                + '<option value="website"' + (v('og_type', 'website') === 'website' ? ' selected' : '') + '>website（网站 / 首页）</option>'
                + '<option value="article"' + (v('og_type', 'website') === 'article' ? ' selected' : '') + '>article（文章 / 作品页）</option>'
                + '</select>'
                + '<p style="font-size:12px">og:type，部分平台按类型决定布局。</p></div>'
                + '<div class="ow-form-item"><label>卡片样式</label>'
                + '<select class="ow-input" id="lcFTwCard">'
                + '<option value="summary_large_image"' + (v('tw_card', 'summary_large_image') === 'summary_large_image' ? ' selected' : '') + '>大图（summary_large_image）</option>'
                + '<option value="summary"' + (v('tw_card', 'summary_large_image') === 'summary' ? ' selected' : '') + '>小图（summary）</option>'
                + '</select>'
                + '<p style="font-size:12px">twitter:card，X 平台的卡片版式。</p></div>'
                + '</div>';

            var platH = '';
            for (var i = 0; i < PLATS.length; i++) {
                var p = PLATS[i], key = 'p_' + p.k;
                platH += OwLC.swRow('lcF' + p.k.charAt(0).toUpperCase() + p.k.slice(1), p.n, c ? c[key] == 1 : true, p.t);
            }
            f += '<div class="ow-form-item"><label>分享平台</label>'
                + '<div class="lc-plat-grid">' + platH + '</div>'
                + '<p style="font-size:12px">一次填写，按需启用；未勾选的平台不会输出对应 meta 标签。</p></div>';

            f += OwLC.swRow('lcFEnabled', '启用该卡片', c ? c.enabled == 1 : true, '停用后不在任何页面输出该卡片的 meta 标签。');
            f += '<div class="ow-form-item" style="margin-top:12px"><label>排序</label>'
                + '<input class="ow-input" id="lcFSort" type="number" style="width:120px" value="' + (c ? parseInt(c.sort, 10) || 0 : 0) + '">'
                + '<p style="font-size:12px">多张卡片同时启用时，排序靠前的先生效。</p></div>';

            var pv = '<div class="lc-pv">'
                + '<div class="lc-pv-cap">分享预览</div>'
                + '<div class="lc-pv-card">'
                + '<div class="lc-pv-img is-empty" id="lcPvImg"></div>'
                + '<div class="lc-pv-body">'
                + '<div class="lc-pv-title" id="lcPvTitle">' + w.esc(v('title', '')) + '</div>'
                + '<div class="lc-pv-desc" id="lcPvDesc">' + w.esc(v('description', '')) + '</div>'
                + '<div class="lc-pv-host" id="lcPvHost"></div>'
                + '</div></div>'
                + '<div class="lc-pv-note">各平台抓取后的实际样式由其自身决定，此处为示意。</div>'
                + '</div>';

            var mask = d.createElement('div');
            mask.className = 'ow-modal-mask';
            mask.id = 'lcMask';
            mask.style.display = 'flex';
            mask.innerHTML = '<div class="ow-modal lc-modal" style="width:760px;max-width:96%">'
                + '<button class="ow-modal-close" title="关闭">✕</button>'
                + '<h3>' + (isNew ? '新建链接卡片' : '编辑链接卡片') + '</h3>'
                + '<div class="lc-modal-grid"><div class="lc-modal-form">' + f + '</div>'
                + '<div class="lc-modal-aside">' + pv + '</div></div>'
                + '<div class="ow-modal-actions">'
                + '<button class="ow-btn ow-btn-ghost" onclick="OwLC.close()">取消</button>'
                + '<button class="ow-btn ow-btn-primary" onclick="OwLC.save(' + (c ? c.id : 0) + ')">保存</button>'
                + '</div></div>';
            d.body.appendChild(mask);
            OwLC.mask = mask;
            mask.querySelector('.ow-modal-close').onclick = OwLC.close;
            mask.onclick = function (e) { if (e.target === mask) OwLC.close(); };
            if (w.bindSwitches) w.bindSwitches(mask);
            if (w.OwTip && w.OwTip.scan) w.OwTip.scan(mask);

            /* 输入即时同步预览 */
            var bind = ['lcFTitle', 'lcFDesc', 'lcFImage', 'lcFUrl'], j;
            for (j = 0; j < bind.length; j++) {
                (function (id) {
                    var el = $(id);
                    if (el) el.oninput = function () { OwLC.pvSync(); };
                })(bind[j]);
            }
            OwLC.pvSync();
            var first = $('lcFName');
            if (first) first.focus();
        },

        /* 开关行（与核心 switchHtml 同构，视觉由 bindSwitches 同步） */
        swRow: function (id, label, on, hint) {
            return '<div class="ow-switch-row">'
                + '<span class="ow-switch-label">' + w.esc(label) + '</span>'
                + '<input type="checkbox" class="ow-switch-input" id="' + id + '"' + (on ? ' checked' : '') + '>'
                + '<label class="ow-switch' + (on ? ' is-on' : '') + '" for="' + id + '"></label>'
                + (hint ? '<p class="ow-switch-hint">' + w.esc(hint) + '</p>' : '')
                + '</div>';
        },

        /* 预览同步：标题 / 描述 / 封面图 / 域名 */
        pvSync: function () {
            var img = $('lcPvImg'), title = $('lcPvTitle'), desc = $('lcPvDesc'), host = $('lcPvHost');
            if (!img) return;
            var url = ($('lcFImage') || {}).value || '';
            url = url.replace(/["']/g, '').replace(/^\s+|\s+$/g, '');
            if (/^https?:\/\//i.test(url)) {
                img.style.backgroundImage = 'url("' + url + '")';
                img.className = 'lc-pv-img';
            } else {
                img.style.backgroundImage = '';
                img.className = 'lc-pv-img is-empty';
            }
            if (title) title.textContent = ($('lcFTitle') || {}).value || '（未填写标题）';
            if (desc) desc.textContent = ($('lcFDesc') || {}).value || '（未填写描述）';
            if (host) {
                var u = (($('lcFUrl') || {}).value || '').replace(/^https?:\/\//i, '');
                host.textContent = u ? u.split('/')[0] : 'example.com';
            }
        },

        /* ---------- 保存 ---------- */
        save: function (id) {
            var name = ($('lcFName') || {}).value || '';
            var url = ($('lcFUrl') || {}).value || '';
            var title = ($('lcFTitle') || {}).value || '';
            if (!name.replace(/\s+/g, '')) { toast('请填写卡片名称'); return; }
            if (!title.replace(/\s+/g, '')) { toast('请填写标题'); return; }
            if (!/^https?:\/\//i.test(url.replace(/\s+/g, ''))) { toast('请填写 http/https 绝对地址的规范链接'); return; }
            var data = {
                id: id, name: name, url: url, title: title,
                desc: ($('lcFDesc') || {}).value || '',
                image: ($('lcFImage') || {}).value || '',
                og_type: ($('lcFOgType') || {}).value || 'website',
                tw_card: ($('lcFTwCard') || {}).value || 'summary_large_image',
                p_og: $('lcFOg') && $('lcFOg').checked ? 1 : 0,
                p_tw: $('lcFTw') && $('lcFTw').checked ? 1 : 0,
                p_wx: $('lcFWx') && $('lcFWx').checked ? 1 : 0,
                p_qq: $('lcFQq') && $('lcFQq').checked ? 1 : 0,
                enabled: $('lcFEnabled') && $('lcFEnabled').checked ? 1 : 0,
                sort: ($('lcFSort') || {}).value || 0
            };
            OwApi.post('plugin_link_card_save', data, function (r) {
                toast(r.msg);
                if (!r.ok) return;
                OwLC.close();
                OwLC.render(r.cards || []);
            });
        },

        /* ---------- 启用 / 停用 ---------- */
        toggle: function (id, on) {
            OwApi.post('plugin_link_card_toggle', { id: id, enabled: on }, function (r) {
                if (!r.ok) { toast(r.msg); OwLC.load(); return; }
                OwLC.render(r.cards || []);
            });
        },

        /* ---------- 删除（敏感操作：一次性票据） ---------- */
        del: function (id) {
            var name = '';
            for (var i = 0; i < OwLC.cards.length; i++) {
                if (OwLC.cards[i].id == id) { name = OwLC.cards[i].name; break; }
            }
            w.OwAdmin.confirm('确定删除卡片「' + name + '」？删除后不可恢复。', function () {
                OwApi.secure('plugin_link_card_del', { id: id }, function (r) {
                    toast(r.msg);
                    if (r.ok) OwLC.render(r.cards || []);
                });
            });
        },

        /* ---------- 关闭弹窗 ---------- */
        close: function () {
            if (OwLC.mask && OwLC.mask.parentNode) OwLC.mask.parentNode.removeChild(OwLC.mask);
            OwLC.mask = null;
        }
    };

    w.OwLC = OwLC;

    /* 后台页 HTML 由 innerHTML 注入（script 不执行）：等 #lcList 出现后初始化一次。
       标记挂在元素上：切走再切回时是新元素，仍能重新加载。 */
    function tryInit() {
        var el = $('lcList');
        if (el && !el._lcInited) { el._lcInited = true; OwLC.boot(); }
    }
    if (w.MutationObserver) {
        new w.MutationObserver(tryInit).observe(d.documentElement, { childList: true, subtree: true });
    }
    tryInit();
})(window, document);
