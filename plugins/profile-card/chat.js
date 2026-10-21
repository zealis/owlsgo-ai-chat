/* ==========================================================================
 * 用户名片插件前端（profile-card）v1.0.5 —— 纯原生 ES5，无外部依赖
 *
 * 功能：
 *   1. 资料卡头部「名片」化：顶部横幅背景图（默认白色）+ 头像叠压。
 *      挂在核心 v1.5.17 新增的 OwChat.onCardRendered（资料卡渲染完成）钩子上；
 *      横幅 URL 由服务端 user.card 钩子随 user_card 接口下发（u.banner）。
 *   2. 横幅管理在「个人设置 → 资料」页（v1.0.1 从左侧 rail 面板迁入）：
 *      包装 OwChat.openSettings 注入区块、包装 OwChat.saveSettings 随保存提交。
 *      与 signature 插件同一口径 —— 核心保存按钮注释明确「插件注入的字段
 *      挂在 saveSettings 上」。
 *
 * ⚠️ 核心低于 v1.5.17（没有 onCardRendered）时：不猴补 openModal，
 *    资料卡保持核心原样（宁可没有横幅，也不对核心渲染结果做脆弱手术）；
 *    设置里的横幅区块照常工作（等核心升级后即刻生效）。
 * ========================================================================== */
(function (w, d) {
    'use strict';

    if (!w.OwChat || !OwChat.cfg) return;
    if (w.__owPcLoaded) return;
    w.__owPcLoaded = true;

    function $(id) { return d.getElementById(id); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    /** URL 消毒：引号 / 反斜杠 / 空白会破坏 CSS url()（服务端已校验过，这里双保险） */
    function cssUrl(u) {
        return String(u || '').replace(/["'\\\s]/g, '');
    }

    /* ---------- 1. 资料卡：插入横幅节点 ---------- */

    /**
     * 核心渲染完资料卡后调用。做两件事：
     *   ① 给弹窗加 .owpc-card 类（样式作用域；同时把原标题「用户资料」收起来）
     *   ② 把横幅与头部（头像/昵称/ID）包进 .owpc-hero —— 背景图铺满
     *      「.ow-card-meta 以上」的整个封面区，头像与昵称浮在图上
     * 无自定义横幅时**照样建 hero**（默认白色）：所有卡片统一享受
     * 「封面区 + 悬浮身份」的主页布局，有图才显示背景图。
     * 布局全部交给 CSS（style.css），JS 只重组节点、不加一行内联布局样式。
     */
    function decorateCard(modal, u) {
        if (!modal) return;
        modal.className += ' owpc-card';
        var head = modal.querySelector('.ow-card-head');
        if (!head) return;
        var banner = cssUrl(u && u.banner);
        var b = d.createElement('div');
        b.className = 'owpc-banner';
        if (banner) b.style.backgroundImage = 'url("' + banner + '")';
        var hero = d.createElement('div');
        hero.className = 'owpc-hero';
        hero.appendChild(b);
        head.parentNode.insertBefore(hero, head);
        hero.appendChild(head);          // 头部移入封面区，浮在背景图上
    }

    if (typeof OwChat.onCardRendered === 'function') {
        OwChat.onCardRendered(decorateCard);
    }
    // 核心没有 onCardRendered（低于 v1.5.17）：静默降级，资料卡保持核心原样。

    /* ---------- 2. 个人设置：横幅区块 ---------- */

    var OwPC = {
        /** 打开设置时从服务端读回的现值（保存时用来判断改没改） */
        initial: '',
        /** 用户当前暂存的值（上传 / 链接 / 移除都改它，点「保存」才落库） */
        staged: '',
        dirty: false,

        /** 注入区块到「资料」页末尾 + 读现值（openSettings 包装里调用） */
        inject: function () {
            var modal = $('owModal');
            if (!modal || $('owpcSetBanner')) return;                 // 已注入（幂等）
            var pane = modal.querySelector('.ow-pagetab-panel[data-pane="prof"]');
            if (!pane) return;
            var me = OwChat.cfg.me || {};
            var div = d.createElement('div');
            div.className = 'ow-form-item';
            div.id = 'owpcSetBanner';
            div.innerHTML =
                '<label>资料卡横幅</label>'
                + '<div class="owpc-set-preview" id="owpcSetPreview">'
                +   '<span class="owpc-set-preview-av"><img src="' + esc(me.avatar) + '" alt=""></span>'
                + '</div>'
                + '<div class="owpc-set-row">'
                +   '<button class="ow-btn" id="owpcSetUploadBtn" type="button">上传图片</button>'
                +   '<button class="ow-btn ow-btn-ghost" id="owpcSetRemoveBtn" type="button" style="display:none">移除横幅</button>'
                + '</div>'
                + '<div class="owpc-set-row">'
                +   '<input class="ow-input" id="owpcSetUrl" type="text" maxlength="500" placeholder="或粘贴图片链接（http/https）">'
                +   '<button class="ow-btn" id="owpcSetUrlBtn" type="button">使用链接</button>'
                + '</div>'
                + '<p class="ow-form-hint">显示在他人查看你的资料卡时：默认白色，设置图片后头像会叠在图片上沿。过宽过高的图片会自动裁剪展示，建议选用宽幅图片。</p>'
                + '<input type="file" id="owpcSetFile" accept="image/*" style="display:none">';
            pane.appendChild(div);
            this.bind();
            // 有未保存的暂存值（上次开了设置没保存就关了）：先把它呈现出来，
            // 保持「眼里看到的 = 保存会写上去的」；不读服务端，避免旧值覆盖草稿。
            if (this.dirty) { this.render(this.staged); return; }
            this.load();
        },

        bind: function () {
            var self = this;
            var file = $('owpcSetFile');
            $('owpcSetUploadBtn').onclick = function () { if (file) file.click(); };
            if (file) {
                file.onchange = function () {
                    if (!this.files || !this.files[0]) return;
                    var f = this.files[0];
                    this.value = '';                      // 清掉，允许再次选同一个文件
                    if (f.size > 8 * 1024 * 1024) { toast('图片不能超过 8MB'); return; }
                    // v1.5.18：上传在选完文件后才开始，手动锁住「上传图片」按钮
                    var upBtn = $('owpcSetUploadBtn');
                    var release = (upBtn && w.OwBusy) ? OwBusy.lock(upBtn, 60000) : null;
                    var finish = function () { if (release) { release(); release = null; } };
                    OwApi.upload('plugin_profile_card_upload', f, function (r) {
                        finish();
                        if (!r.ok) { toast(r.msg || '上传失败'); return; }
                        self.stage(r.url);
                        var inp = $('owpcSetUrl');       // 上传即采用，链接框清掉避免「两个值」误解
                        if (inp) inp.value = '';
                    });
                };
            }
            $('owpcSetUrlBtn').onclick = function () {
                var v = $('owpcSetUrl').value.replace(/^\s+|\s+$/g, '');
                if (!v) { toast('请先粘贴图片链接'); return; }
                self.stage(v);
            };
            $('owpcSetRemoveBtn').onclick = function () { self.stage(''); };
        },

        /** 读现值并渲染。
         *  ⚠️ 竞态防护：GET 是异步的，回包可能在用户已经暂存了值之后才到 ——
         *     那时直接重置会把用户的编辑清掉（表现为「用了链接、点了保存，
         *     却没存上」）。dirty 一律以回包迟到者输。 */
        load: function () {
            OwApi.post('plugin_profile_card_get', { id: OwChat.cfg.me.id }, function (r) {
                if (OwPC.dirty) return;
                var b = r.ok ? (r.banner || '') : '';
                OwPC.initial = b;
                OwPC.staged = b;
                OwPC.dirty = false;
                OwPC.render(b);
            });
        },

        /** 暂存一个值（上传成功 / 点链接 / 移除都走这），只动预览不落库 */
        stage: function (banner) {
            this.staged = banner;
            this.dirty = true;
            this.render(banner);
        },

        /** 按横幅值刷新预览与移除按钮显隐 */
        render: function (banner) {
            banner = cssUrl(banner);
            var p = $('owpcSetPreview');
            if (p) p.style.backgroundImage = banner ? 'url("' + banner + '")' : 'none';
            var rm = $('owpcSetRemoveBtn');
            if (rm) rm.style.display = banner ? '' : 'none';
        },

        /** 保存成功后：以落库值为初始值，清掉脏标记 */
        synced: function () { this.initial = this.staged; this.dirty = false; }
    };
    w.OwPC = OwPC;

    /* ---------- 3. 包装核心：openSettings 注入 / saveSettings 提交 ---------- */

    var origOpenSettings = OwChat.openSettings;
    OwChat.openSettings = function () {
        origOpenSettings.call(this);
        OwPC.inject();
    };

    var origSaveSettings = OwChat.saveSettings;
    OwChat.saveSettings = function () {
        origSaveSettings.call(this);                 // 先走核心的昵称保存
        // 横幅**改了才提交**（路由是幂等 upsert，但没必要每次保存都打一次接口）。
        // 与签名插件同一口径：两个保存相互独立、各自 toast；昵称保存失败时
        // 核心不会关弹窗，横幅这一笔仍然有效（用户的意图就是改它）。
        if (OwPC.dirty) {
            var v = OwPC.staged;
            OwApi.post('plugin_profile_card_save', { banner: v }, function (r) {
                toast(r.msg || (r.ok ? '横幅已保存' : '横幅保存失败'));
                if (r.ok) OwPC.synced();
            });
        }
    };
})(window, document);
