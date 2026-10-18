/* ==========================================================================
 * 用户名片插件前端（profile-card）v1.0.0 —— 纯原生 ES5，无外部依赖
 *
 * 功能：
 *   1. 资料卡头部「名片」化：顶部横幅背景图（默认白色）+ 头像叠压。
 *      挂在核心 v1.5.17 新增的 OwChat.onCardRendered（资料卡渲染完成）钩子上；
 *      横幅 URL 由服务端 user.card 钩子随 user_card 接口下发（u.banner）。
 *   2. 左侧 rail「名片」面板：上传图片 / 粘贴链接 / 移除横幅，带卡片效果预览。
 *
 * ⚠️ 核心低于 v1.5.17（没有 onCardRendered）时：不猴补 openModal，
 *    资料卡保持核心原样（宁可没有横幅，也不对核心渲染结果做脆弱手术）。
 * ========================================================================== */
(function (w, d) {
    'use strict';

    if (!w.OwChat || !OwChat.cfg) return;
    if (w.__owPcLoaded) return;
    w.__owPcLoaded = true;

    var TAB_ID = 'profilecard';

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
     *   ② 在 .ow-card-head 之前插入 .owpc-banner 节点
     * 无自定义横幅时**照样插节点**（默认白色）：所有卡片统一享受
     * 「横幅区 + 头像叠压 + 头部纵排」的名片布局，有图才显示背景图。
     * 布局全部交给 CSS（style.css），JS 只插一个节点、不加一行内联布局样式。
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
        head.parentNode.insertBefore(b, head);
    }

    if (typeof OwChat.onCardRendered === 'function') {
        OwChat.onCardRendered(decorateCard);
    }
    // 核心没有 onCardRendered（低于 v1.5.17）：静默降级，资料卡保持核心原样。

    /* ---------- 2. 「名片」面板 ---------- */

    var OwPC = {

        /** 读取并渲染当前用户的横幅状态 */
        load: function () {
            OwApi.post('plugin_profile_card_get', { id: OwChat.cfg.me.id }, function (r) {
                if (r.ok) OwPC.render(r.banner || '');
            });
        },

        /** 按横幅 URL 刷新预览与按钮态 */
        render: function (banner) {
            banner = cssUrl(banner);
            var b = $('owpcPreviewBanner');
            if (b) b.style.backgroundImage = banner ? 'url("' + banner + '")' : 'none';
            var rm = $('owpcRemoveBtn');
            if (rm) rm.style.display = banner ? '' : 'none';
            var st = $('owpcState');
            if (st) st.textContent = banner ? '已设置横幅' : '未设置 · 默认白色';
        },

        /** 保存横幅（空串 = 移除） */
        save: function (banner) {
            OwApi.post('plugin_profile_card_save', { banner: banner }, function (r) {
                toast(r.msg || (r.ok ? '已保存' : '保存失败'));
                if (r.ok) OwPC.render(banner);
            });
        },

        /** 上传图片 → 拿到 URL 直接采用（上传即保存，少一步确认） */
        upload: function (file) {
            if (file.size > 8 * 1024 * 1024) { toast('图片不能超过 8MB'); return; }
            OwApi.upload('plugin_profile_card_upload', file, function (r) {
                if (!r.ok) { toast(r.msg || '上传失败'); return; }
                OwPC.save(r.url);
            });
        }
    };
    w.OwPC = OwPC;

    /** 面板骨架（只建一次；#owSidePanels 的面板 div 跨标签切换常驻，不会被清掉） */
    function buildSkeleton(panel) {
        var me = OwChat.cfg.me || {};
        panel.innerHTML =
            '<div class="owpc-panel" id="owpcPanel">'
            + '<div class="owpc-preview">'
            +   '<div class="owpc-preview-banner" id="owpcPreviewBanner"></div>'
            +   '<div class="owpc-preview-body">'
            +     '<span class="ow-avatar ow-avatar-lg"><img src="' + esc(me.avatar) + '" alt=""></span>'
            +     '<div class="owpc-preview-meta">'
            +       '<div class="owpc-preview-name">' + esc(me.nickname) + '</div>'
            +       '<div class="owpc-preview-state" id="owpcState">读取中…</div>'
            +     '</div>'
            +   '</div>'
            + '</div>'
            + '<div class="owpc-row">'
            +   '<button class="ow-btn ow-btn-primary" id="owpcUploadBtn" type="button">上传横幅图片</button>'
            +   '<button class="ow-btn ow-btn-ghost" id="owpcRemoveBtn" type="button" style="display:none">移除横幅</button>'
            + '</div>'
            + '<div class="owpc-row">'
            +   '<input class="ow-input" id="owpcUrlInput" type="text" maxlength="500" placeholder="或粘贴图片链接（http/https）">'
            +   '<button class="ow-btn" id="owpcUrlBtn" type="button">使用链接</button>'
            + '</div>'
            + '<p class="owpc-tip">横幅显示在他人查看你的资料卡时：默认白色，设置图片后头像会叠在图片上沿。过宽过高的图片会自动裁剪展示，建议选用宽幅图片。</p>'
            + '<input type="file" id="owpcFile" accept="image/*" style="display:none">'
            + '</div>';
    }

    function bindPanel() {
        var file = $('owpcFile');
        $('owpcUploadBtn').onclick = function () { if (file) file.click(); };
        if (file) {
            file.onchange = function () {
                if (!this.files || !this.files[0]) return;
                var f = this.files[0];
                this.value = '';                      // 清掉，允许再次选同一个文件
                OwPC.upload(f);
            };
        }
        $('owpcUrlBtn').onclick = function () {
            var v = $('owpcUrlInput').value.replace(/^\s+|\s+$/g, '');
            if (!v) { toast('请先粘贴图片链接'); return; }
            OwPC.save(v);
        };
        $('owpcRemoveBtn').onclick = function () { OwPC.save(''); };
    }

    /* ---------- 3. 注册 rail 入口（仅注册用户；游客按钮服务端根本没渲染） ---------- */

    if (OwChat.cfg.me) {
        OwChat.onRail({
            id: TAB_ID,
            label: '我的名片',
            onShow: function (panel) {
                if (!$('owpcPanel')) { buildSkeleton(panel); bindPanel(); }
                OwPC.load();
            }
        });
    }
})(window, document);
