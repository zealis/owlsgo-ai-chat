/* chat-background · 前端交互
 *
 * 职责：
 *   1. 聊天页加载时，读用户当前背景设置，注入 <style> 应用背景
 *      （实际上 page.head 钩子已在服务端注入，这里只做兜底：服务端没注入时再补一次）
 *   2. 在「个人设置」弹窗里追加「聊天背景」区块：模式切换（SVG/图片/自定义）
 *      + 叠加色色块 + 透明度滑块 + 保存按钮
 *   3. 自定义模式：远程链接输入 + 上传按钮（后者依赖 window.OwAttach 存在）
 *
 * 幂等保护：w.__owCBLoaded 防止重复加载二次注册。
 */
(function (w, d) {
    'use strict';
    if (!w.OwChat || !OwChat.cfg) return;       // 仅聊天页加载
    if (w.__owCBLoaded) return;
    w.__owCBLoaded = true;

    var $ = function (id) { return d.getElementById(id); };
    var toast = w.toast || function (s) { w.alert(s); };

    /* 当前用户状态：从服务端拉取一次，缓存到内存，保存后刷新 */
    var state = {
        setting: null,        // {mode, item_id, custom_url, custom_file, overlay, opacity}
        items: [],            // 可选背景清单
        cfg: null,             // {min_level_custom, can_custom, custom_reason}
        attachEnabled: false,
        overlays: {},          // 叠加色清单
    };

    /* 叠加色中文标签（与后端 owCBOverlays() 一一对应） */
    var OVR_LABELS = {
        none: '无', light: '浅', dark: '深', dim: '暗',
        blue: '蓝', purple: '紫', green: '绿', orange: '橙',
        pink: '粉', sepia: '棕'
    };

    /* ---------- 载入设置 ---------- */
    function loadSetting(cb) {
        OwApi.post('plugin_chat_background_user_load', {}, function (r) {
            if (!r || !r.ok) { if (cb) cb(false); return; }
            state.setting = r.setting;
            state.items = r.items || [];
            state.cfg = r.cfg || null;
            state.attachEnabled = !!r.attach_enabled;
            state.overlays = r.overlays || {};
            if (cb) cb(true);
        });
    }

    /* ---------- 应用背景（前端兜底：服务端 page.head 已注入则跳过） ---------- */
    function applyBg() {
        if (!state.setting || state.setting.mode === 'none') return;
        if (d.getElementById('ow-cb-style')) return;   // 服务端已注入
        var st = state.setting;
        var url = '', tile = false;
        if (st.mode === 'item' && st.item_id) {
            for (var i = 0; i < state.items.length; i++) {
                var it = state.items[i];
                if (it.id === st.item_id) { url = it.preview; tile = (it.kind === 'svg'); break; }
            }
        } else if (st.mode === 'custom') {
            if (st.custom_url) url = st.custom_url;
            else if (st.custom_preview) url = st.custom_preview;
        }
        if (!url) return;
        var bgCss = tile
            ? "background-image:url('" + url + "');background-repeat:repeat;background-size:auto;"
            : "background-image:url('" + url + "');background-repeat:no-repeat;background-size:cover;background-position:center;";
        var ovr = state.overlays[st.overlay] || 'none';
        var op = (Math.max(0, Math.min(100, st.opacity || 50)) / 100).toFixed(2);
        var css = 'body.ow-chat-body .ow-layout{position:relative;}'
            // 背景层挂在 .ow-layout（100vh×100%）上 = 铺满全屏；::before 画图、::after 画叠加色。
            // .ow-layout 不设 z-index，不自建层叠上下文 → 伪元素的 z-index:0 落在根层叠上下文，
            // 高于 body/.ow-layout 底色、低于被抬到 z-index:1 的正文内容。
            + 'body.ow-chat-body .ow-layout::before{content:"";position:absolute;inset:0;z-index:0;pointer-events:none;' + bgCss + '}'
            + (ovr !== 'none' ? 'body.ow-chat-body .ow-layout::after{content:"";position:absolute;inset:0;z-index:0;pointer-events:none;background:' + ovr + ';opacity:' + op + ';}' : '')
            // 只抬 .ow-layout 的直接子级（.ow-topbrand / .ow-body），其内部整体位于背景图之上。
            + 'body.ow-chat-body .ow-layout > *{position:relative;z-index:1;}'
            // 主区与消息区去掉自身不透明底色，露出全屏背景图
            + 'body.ow-chat-body .ow-main{background-color:transparent;background-image:none;}'
            + 'body.ow-chat-body .ow-messages{background-color:transparent;background-image:none;}'
            // 顶栏与输入栏半透明，让背景图在其下若隐若现（与 main.php 的 page.head 注入保持一致）。
            // color-mix 从主题变量 --ow-bg-chat 派生半透明色：不写死颜色、深浅模式各自成立；
            // 不支持时该声明被丢弃、自动回退核心的不透明底色。输入框加 :not(:focus) 以保住聚焦态底色。
            // 不用 backdrop-filter（毛玻璃）：项目样式规范明确「禁毛玻璃」。
            + 'body.ow-chat-body .ow-main .ow-topbar,'
            + 'body.ow-chat-body .ow-main .ow-inputbar{background-color:color-mix(in srgb, var(--ow-bg-chat) 78%, transparent);}'
            + 'body.ow-chat-body .ow-main .ow-inputbar .ow-input:not(:focus){background-color:color-mix(in srgb, var(--ow-bg-chat) 72%, transparent);}';
            // rail 与侧栏不做覆盖 —— 保留核心不透明底色 --ow-bg-chat，背景图被完全遮住、肉眼看不到。
        var st2 = d.createElement('style');
        st2.id = 'ow-cb-style';
        st2.textContent = css;
        d.head.appendChild(st2);
    }

    /* ---------- 在「个人设置」弹窗里追加背景区块 ----------
       做法：猴补丁 OwChat.openSettings —— 调原函数打开弹窗，然后把背景区块并进「外观」那一页
       （v1.3.112 起个人设置成分组标签，背景与日夜模式、色系是同一类偏好）。
       老核心没有 data-setpane 这个结构，回落到「插到保存按钮之前」，两边都不报错。 */
    var origOpenSettings = OwChat.openSettings;
    OwChat.openSettings = function () {
        origOpenSettings.apply(this, arguments);
        var modal = $('owModal');
        if (!modal) return;
        var host = modal.querySelector('[data-setpane="look"]');
        var saveBtn = modal.querySelector('button.ow-btn-primary.ow-btn-block');
        if (!host && !saveBtn) return;
        if (modal.querySelector('.ow-cb-section')) return;   // 已注入
        var section = d.createElement('div');
        section.className = 'ow-cb-section';
        section.innerHTML = '<h4>聊天背景</h4>'
            + '<p class="ow-cb-desc">为聊天区设置背景图，并叠加一层颜色遮罩以保证文字可读。</p>'
            + '<div class="ow-cb-tabs" id="owCBTabs"></div>'
            + '<div id="owCBBody"></div>'
            + '<div class="ow-cb-overlays" id="owCBOverlays"></div>'
            + '<div class="ow-cb-opacity"><span>遮罩透明度</span>'
            + '<input type="range" id="owCBOpacity" min="0" max="100" value="50">'
            + '<span id="owCBOpacityVal">50%</span></div>';
        if (host) host.appendChild(section);
        else modal.insertBefore(section, saveBtn);

        // 先以默认空数据渲染占位，再异步拉取并填充
        renderTabs('item');   // 默认 tab：item（内置背景库）
        loadSetting(function (ok) {
            if (!ok) { toast('读取背景设置失败'); return; }
            applyBg();
            // 同步当前 tab 与 setting
            renderTabs(state.setting.mode);
            renderBody();
            renderOverlays();
            var op = $('owCBOpacity');
            if (op) {
                op.value = state.setting.opacity;
                var ov = $('owCBOpacityVal');
                if (ov) ov.textContent = op.value + '%';
                op.oninput = function () { if (state.setting) state.setting.opacity = parseInt(op.value, 10); if (ov) ov.textContent = op.value + '%'; };
            }
        });
    };

    /* ---------- 渲染：模式 tab ---------- */
    function renderTabs(activeMode) {
        var tabs = $('owCBTabs');
        if (!tabs) return;
        var canCustom = state.cfg ? state.cfg.can_custom : true;
        var customReason = state.cfg ? state.cfg.custom_reason : '';
        var modes = [
            { k: 'none',    t: '默认' },
            { k: 'item',    t: '内置' },
            { k: 'custom',  t: '自定义', disabled: !canCustom, title: customReason }
        ];
        var h = '';
        for (var i = 0; i < modes.length; i++) {
            var m = modes[i];
            h += '<button type="button" class="ow-cb-tab' + (m.k === activeMode ? ' is-active' : '') + '"'
                + (m.disabled ? ' disabled title="' + esc(m.title || '') + '"' : '')
                + ' onclick="OwCBUser.switchMode(\'' + m.k + '\')">' + esc(m.t) + '</button>';
        }
        tabs.innerHTML = h;
    }

    /* ---------- 渲染：模式对应的内容 ---------- */
    function renderBody() {
        var body = $('owCBBody');
        if (!body || !state.setting) return;
        var st = state.setting;
        if (st.mode === 'none') {
            body.innerHTML = '<p class="ow-cb-desc">使用站点默认背景（无背景图）。</p>';
            return;
        }
        if (st.mode === 'item') {
            renderItemsGrid();
            return;
        }
        if (st.mode === 'custom') {
            renderCustom();
            return;
        }
    }

    /* ---------- 渲染：内置背景网格（SVG + 图片混排，按 kind 区分铺法） ---------- */
    function renderItemsGrid() {
        var body = $('owCBBody');
        if (!body) return;
        var items = state.items || [];
        if (!items.length) {
            body.innerHTML = '<p class="ow-cb-desc">管理员尚未提供可选背景。</p>';
            return;
        }
        var st = state.setting;
        // 收集 preview URL，innerHTML 后用 JS 设 background-image（避免引号冲突）
        var previews = [];
        var h = '<div class="ow-cb-grid">';
        for (var i = 0; i < items.length; i++) {
            var it = items[i];
            var sel = (st.mode === 'item' && st.item_id === it.id);
            previews.push(it.preview || '');
            h += '<div class="ow-cb-card' + (it.kind === 'svg' ? ' ow-cb-tile' : '') + (sel ? ' is-selected' : '') + '"'
                + ' onclick="OwCBUser.pickItem(' + it.id + ')">'
                + '<div class="ow-cb-thumb" data-bg-idx="' + i + '"></div>'
                + '<span class="ow-cb-check">✓</span>'
                + '<div class="ow-cb-name">' + esc(it.name) + '</div>'
                + '</div>';
        }
        h += '</div>';
        body.innerHTML = h;
        var thumbs = body.querySelectorAll('.ow-cb-thumb');
        for (var k = 0; k < thumbs.length; k++) {
            var url = previews[k];
            if (!url) continue;
            thumbs[k].style.backgroundImage = "url('" + String(url).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "')";
        }
    }

    /* ---------- 渲染：自定义（远程链接 + 上传） ---------- */
    function renderCustom() {
        var body = $('owCBBody');
        if (!body || !state.setting) return;
        var st = state.setting;
        var canUpload = state.attachEnabled;
        var curUrl = st.custom_url || '';
        var curFile = st.custom_file || '';
        // 用 JS 设背景图（避免 HTML 属性里 url('...') 被引号冲突破坏）
        var prevUrl = curUrl || (st.custom_preview || '');

        var h = '<div class="ow-cb-custom">';
        if (!state.cfg.can_custom) {
            h += '<div class="ow-cb-locked">自定义背景需 <b>' + esc(state.cfg.custom_reason) + '</b></div>';
        } else {
            h += '<div class="ow-cb-custom-url">'
                + '<input class="ow-input" id="owCBCustomUrl" placeholder="https://example.com/bg.jpg" value="' + esc(curUrl) + '">'
                + (canUpload
                    ? '<button class="ow-btn ow-btn-ghost" onclick="OwCBUser.uploadCustom()">上传</button>'
                        + '<input type="file" id="owCBFileInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none">'
                    : '')
                + '</div>';
            if (!canUpload) {
                h += '<p class="ow-cb-desc">未启用「附件上传」插件，仅支持远程链接。</p>';
            }
            h += '<div class="ow-cb-custom-preview" id="owCBCustomPreview">'
                + (prevUrl ? '' : '<div class="ow-cb-custom-preview-empty">暂无预览</div>')
                + '</div>'
                + '<p class="ow-cb-desc" style="margin:0">支持 jpg / png / gif / webp，建议不超过 2 MB。远程链接需可被浏览器直接访问。</p>';
        }
        h += '</div>';
        body.innerHTML = h;

        // 用 JS 设 background-image：CSS 字符串里 ' 和 \ 需要转义
        if (prevUrl) {
            var prev = $('owCBCustomPreview');
            if (prev) prev.style.backgroundImage = "url('" + prevUrl.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "')";
        }

        // URL 输入实时更新预览 + 缓存到 setting
        var urlInput = $('owCBCustomUrl');
        if (urlInput) {
            urlInput.oninput = function () {
                st.custom_url = urlInput.value.trim();
                st.custom_file = '';   // 一旦改了 URL 就清掉文件选择
                st.custom_preview = '';
                var prev = $('owCBCustomPreview');
                if (prev) {
                    if (st.custom_url) {
                        prev.style.backgroundImage = "url('" + st.custom_url.replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "')";
                        prev.innerHTML = '';
                    } else {
                        prev.style.backgroundImage = '';
                        prev.innerHTML = '<div class="ow-cb-custom-preview-empty">暂无预览</div>';
                    }
                }
            };
        }
        var fileInput = $('owCBFileInput');
        if (fileInput) {
            fileInput.onchange = function () {
                if (!this.files || !this.files[0]) return;
                OwCBUser.uploadCustom(this.files[0]);
                this.value = '';
            };
        }
    }

    /* ---------- 渲染：叠加色色块 ---------- */
    function renderOverlays() {
        var box = $('owCBOverlays');
        if (!box || !state.setting) return;
        var st = state.setting;
        var keys = Object.keys(state.overlays);
        var h = '';
        for (var i = 0; i < keys.length; i++) {
            var k = keys[i];
            var css = state.overlays[k] || 'none';
            var sel = (st.overlay === k);
            h += '<div class="ow-cb-ovr' + (sel ? ' is-selected' : '') + '" onclick="OwCBUser.pickOverlay(\'' + k + '\')">'
                + '<div class="ow-cb-ovr-fill"' + (css !== 'none' ? ' style="background:' + css + '"' : ' style="background:var(--ow-bg)"') + '></div>'
                + '<div class="ow-cb-ovr-label">' + esc(OVR_LABELS[k] || k) + '</div>'
                + '</div>';
        }
        box.innerHTML = h;
    }

    /* ---------- 全局对象 OwCBUser ----------
       注意：前台对象**不能**与 admin.js 同名 —— 两个文件都挂在 window 上，
       而它们的资源会被 ?action=assets 合并成同一个总包、按注册顺序在**所有**页面加载，
       admin.js 在后，会把前台这份整个覆盖掉；于是前台设置里注入的按钮
       全部指向 admin 那份对象、找不到对应方法，表现为「怎么点都没反应」。
       两个文件必须用互不相同的全局名。 */
    w.OwCBUser = {
        /* 切模式（tab 点击） */
        switchMode: function (mode) {
            if (!state.setting) return;
            if (mode === 'custom' && state.cfg && !state.cfg.can_custom) {
                toast(state.cfg.custom_reason || '当前等级不可使用自定义背景');
                return;
            }
            state.setting.mode = mode;
            // 切到非 custom 时清掉自定义参数？保留以便切回；不影响展示
            renderTabs(mode);
            renderBody();
        },

        /* 选中某个内置背景 */
        pickItem: function (id) {
            if (!state.setting) return;
            state.setting.mode = 'item';
            state.setting.item_id = id;
            renderTabs('item');
            renderItemsGrid();
        },

        /* 选中叠加色 */
        pickOverlay: function (k) {
            if (!state.setting) return;
            state.setting.overlay = k;
            renderOverlays();
        },

        /* 上传自定义背景 */
        uploadCustom: function (file) {
            if (!file) {
                var fi = $('owCBFileInput');
                if (!fi) return;
                if (!fi.files || !fi.files[0]) return;
                file = fi.files[0];
            }
            if (!state.cfg.can_custom) { toast(state.cfg.custom_reason || '当前等级不可使用自定义背景'); return; }
            if (!state.attachEnabled) { toast('未启用附件上传插件'); return; }
            toast('背景上传中…');
            OwApi.upload('plugin_chat_background_user_upload', file, {}, function (r) {
                if (!r || !r.ok) { toast((r && r.msg) || '上传失败'); return; }
                if (state.setting) {
                    state.setting.custom_file = r.file;
                    state.setting.custom_url = '';   // 上传后清掉 URL
                    state.setting.custom_preview = r.preview || '';  // 直链预览 URL
                }
                toast('上传成功');
                renderCustom();
            });
        },

        /* 保存（挂在「保存」按钮上：拦截原 saveSettings 调用前先保存背景） */
        saveBg: function (cb) {
            if (!state.setting) { cb(true); return; }
            OwApi.post('plugin_chat_background_user_save', state.setting, function (r) {
                if (!r || !r.ok) { toast((r && r.msg) || '背景保存失败'); cb(false); return; }
                cb(true);
            });
        }
    };

    /* 拦截「保存」按钮：原 saveSettings 只存昵称；我们要在它之前先存背景，
       失败则阻止昵称保存（避免误导用户「已保存」但背景没生效）。
       实现方式：包一层 wrapper。 */
    var origSaveSettings = OwChat.saveSettings;
    OwChat.saveSettings = function () {
        OwCBUser.saveBg(function (ok) {
            if (!ok) return;
            origSaveSettings.apply(OwChat, arguments);
            // 昵称保存后背景 CSS 可能被刷新页面，重新注入兜底样式
            setTimeout(applyBg, 50);
        });
    };

    /* ---------- 启动：拉取并应用 ---------- */
    loadSetting(function (ok) {
        if (ok) applyBg();
    });
})(window, document);
