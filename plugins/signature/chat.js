/* ==========================================================================
 * 个性签名插件前端（纯原生 ES5，兼容旧浏览器，无外部依赖）
 *
 * 功能：
 *   1. 个人设置弹窗：在昵称下方注入「个性签名」文本框（上限 200 字）
 *   2. 保存设置时一并保存个性签名
 *   3. 用户资料卡：展示该用户的个性签名（注入点：核心的 #owCardExtras，见 findCardAnchor）
 * ========================================================================== */
(function (w) {
    'use strict';

    if (!w.OwChat || !OwChat.cfg) return;
    if (w.__haSigLoaded) return;
    w.__haSigLoaded = true;

    function $(id) { return document.getElementById(id); }

    /* ---------- 1. 个人设置：注入个性签名输入框 ---------- */

    var origOpenSettings = OwChat.openSettings;
    OwChat.openSettings = function () {
        origOpenSettings.call(this);
        injectSignatureField();
    };

    function injectSignatureField() {
        var modal = $('owModal');
        if (!modal) return;
        // 避免重复注入
        if (modal.querySelector('#owSetSignature')) return;

        var nickInput = modal.querySelector('#owSetNick');
        if (!nickInput) return;
        var nickItem = nickInput.parentNode;   // .ow-form-item

        var div = document.createElement('div');
        div.className = 'ow-form-item';
        div.innerHTML = '<label>个性签名</label>'
            + '<textarea class="ow-input" id="owSetSignature" maxlength="200" rows="3"'
            + ' placeholder="介绍一下自己吧（最多 200 个字）"'
            + ' style="resize:vertical;width:100%;box-sizing:border-box"></textarea>';
        nickItem.parentNode.insertBefore(div, nickItem.nextSibling);

        // 加载已有签名
        OwApi.post('plugin_signature_get', { id: OwChat.cfg.me.id }, function (r) {
            if (r.ok) {
                var ta = $('owSetSignature');
                if (ta) ta.value = r.signature || '';
            }
        });
    }

    /* ---------- 2. 保存设置：一并保存个性签名 ---------- */

    var origSaveSettings = OwChat.saveSettings;
    OwChat.saveSettings = function () {
        var ta = $('owSetSignature');
        var sig = ta ? ta.value : '';
        // 先走核心的昵称/头像保存
        origSaveSettings.call(this);
        // 个性签名独立保存（与昵称/头像解耦，互不影响）
        OwApi.post('plugin_signature_save', { signature: sig }, function (r) {
            if (!r.ok) toast(r.msg);
        });
    };

    /* ---------- 3. 用户资料卡：展示个性签名 ---------- */

    var origUserCard = OwChat.userCard;
    OwChat.userCard = function (uid, nick) {
        if (!uid) { OwChat.pmHint(nick); return; }
        var self = this;
        // 临时接管 openModal：核心 userCard 渲染完资料卡后，注入个性签名
        var origOpenModal = OwChat.openModal;
        OwChat.openModal = function (html, width, cls) {
            OwChat.openModal = origOpenModal;   // 立即还原，避免影响其它弹窗
            // 必须透传第三个参数：核心用 cls 给资料卡加移动端全屏类。
            origOpenModal.call(this, html, width, cls);
            injectSignatureIntoCard(uid);
        };
        origUserCard.call(self, uid, nick);
    };

    /**
     * 找资料卡里的注入锚点（按优先级）。
     *
     * ⚠️ 历史坑（本函数曾让签名彻底消失）：原来只写 `modal.querySelector('p')`。
     * 核心 v1.1.9 重做资料卡布局后，信息段从 <p> 换成 <div class="ow-card-meta">，
     * <p> 在资料卡里再也不存在 → 拿到 null → 直接 return。
     * 症状极其隐蔽：不报错、控制台干净、界面只是「没有签名这一行」，
     * 看起来像「用户没填过签名」，实际是插件找不到地方插。
     *
     * 现在核心（v1.2.23）在资料卡末尾预留了稳定的空容器 `<div id="owCardExtras">`，
     * 插件一律优先用它；后两级是给旧版核心的兜底，不留就会又哑一次。
     */
    function findCardAnchor(modal) {
        return modal.querySelector('#owCardExtras')
            || modal.querySelector('.ow-card-meta')
            || modal.querySelector('p');
    }

    function injectSignatureIntoCard(uid) {
        OwApi.post('plugin_signature_get', { id: uid }, function (r) {
            if (!r.ok) return;
            var sig = (r.signature || '').replace(/^\s+|\s+$/g, '');
            if (!sig) return;
            var modal = $('owModal');
            if (!modal) return;
            // 避免重复注入
            if (modal.querySelector('.ow-sig-text')) return;

            // 主路径：插入到 .ow-card-meta 末尾（注册行下方），
            // 复用核心 .ow-card-meta-row 结构，样式与「积分/注册」行完全一致。
            var meta = modal.querySelector('.ow-card-meta');
            if (meta) {
                var row = document.createElement('div');
                row.className = 'ow-card-meta-row ow-sig-text';
                var v = document.createElement('span');
                v.className = 'ow-card-meta-v';
                v.textContent = sig;
                row.innerHTML = '<span class="ow-card-meta-k">签名</span>';
                row.appendChild(v);
                meta.appendChild(row);
                return;
            }

            // 兜底：核心旧版无 .ow-card-meta，用 #owCardExtras 或 <p>
            var anchor = findCardAnchor(modal);
            if (!anchor) return;
            var div = document.createElement('div');
            div.className = 'ow-sig-text';
            div.style.cssText = 'font-size:13px;color:var(--ow-text,#333);'
                + 'word-break:break-word;white-space:pre-wrap;line-height:1.5';
            div.textContent = sig;
            if (anchor.id === 'owCardExtras') anchor.appendChild(div);
            else anchor.parentNode.insertBefore(div, anchor);
        });
    }
})(window);
