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

    /* 遮罩中文标签（与后端 owCBOverlays() 一一对应，键必须齐） */
    var OVR_LABELS = {
        none: '无', light: '浅', dark: '深', dim: '暗',
        blue: '蓝', purple: '紫', green: '绿', orange: '橙',
        pink: '粉', sepia: '棕',
        // 多色渐变遮罩
        'g-sunset': '晚霞', 'g-ocean': '深海', 'g-aurora': '极光', 'g-grape': '葡萄',
        'g-ember': '余烬', 'g-candy': '糖果', 'g-forest': '森林', 'g-night': '夜幕'
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

    /* ---------- 组装要注入的 CSS ----------
       返回空串 = 既没有背景图也没有叠加色 → 不需要注入（保持/回到核心默认）。 */
    function buildCss() {
        var st = state.setting;
        if (!st) return '';
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
        var ovr = state.overlays[st.overlay] || 'none';
        var hasImg = !!url;
        // 「默认」模式（无图）也允许只叠一层色：叠加色是独立于背景图的选择，
        // 只在图上才有意义的话，用户在默认模式里点颜色就等于白点。
        if (!hasImg && ovr === 'none') return '';
        // ⚠️ 不能写 `st.opacity || 50` —— 滑块拖到 0 时 0 是 falsy，会被顶回 50，
        //    于是「0%」这一档永远不生效。这里显式判空。
        var opN = parseInt(st.opacity, 10);
        if (isNaN(opN)) opN = 50;
        var op = (Math.max(0, Math.min(100, opN)) / 100).toFixed(2);
        var bgCss = hasImg
            ? (tile
                ? "background-image:url('" + url + "');background-repeat:repeat;background-size:auto;"
                : "background-image:url('" + url + "');background-repeat:no-repeat;background-size:cover;background-position:center;")
            : '';
        return 'body.ow-chat-body .ow-main{position:relative;' + (hasImg ? 'background-color:transparent;' : '') + '}'
            + (hasImg ? 'body.ow-chat-body .ow-main::before{content:"";position:absolute;inset:0;z-index:0;pointer-events:none;' + bgCss + '}' : '')
            + (ovr !== 'none' ? 'body.ow-chat-body .ow-main::after{content:"";position:absolute;inset:0;z-index:0;pointer-events:none;background:' + ovr + ';opacity:' + op + ';}' : '')
            // ⚠️ 只抬 .ow-topbar / .ow-messages / .ow-inputbar 这三个固定布局子级，
            // 绝不能写成 > * —— .ow-main 同时是 flex 容器，核心的表情面板 #owEmojiPanel
            // 与 gif-stickers 插件的 .ow-gif-panel 都挂在它下面，靠 position:absolute +
            // z-index:30 浮在输入栏上方；被 > * 命中后 position 会被改写成 relative，
            // 面板立刻掉进文档流并错位（表现为「一开启聊天背景，gif 面板样式就乱」）。
            + 'body.ow-chat-body .ow-main > .ow-topbar,'
            + 'body.ow-chat-body .ow-main > .ow-messages,'
            + 'body.ow-chat-body .ow-main > .ow-inputbar{position:relative;z-index:1;}'
            + 'body.ow-chat-body .ow-messages{background-color:transparent;background-image:none;}'
            // 顶栏与输入栏**不做任何覆盖**：按用户要求完全恢复核心的不透明底色（--ow-bg-chat），
            // 连一点点透明也不要。故这里既没有 color-mix 半透明规则，也没有配套的
            // .ow-input:not(:focus) 规则（那条是为了保住聚焦态底色，随半透明一起去掉）。
            // 左侧图标条与侧栏同样半透明。⚠️ 它们不在 .ow-main 内（是兄弟节点），
            // 选择器不能带 .ow-main；透出来的是 .ow-layout 的 --ow-bg，
            // 即「没有背景图在下面」，效果是朝页面底色略提亮。
            + 'body.ow-chat-body .ow-rail,'
            + 'body.ow-chat-body .ow-sidebar{background-color:color-mix(in srgb, var(--ow-bg-chat) 78%, transparent);}'
            // 昵称字色：默认是核心写死的灰 #8E8EA0（核心自己也是固定色），压在任意
            // 背景图 / 渐变遮罩上对比度不稳（浅色遮罩下尤其糊）。按用户要求改成
            // 纯黑（深色模式纯白）—— 这是「用户指定的可读性例外」，
            // 属项目「样式零硬编码」规范里就地注明的例外项。
            // 只作用在 .ow-main 内：侧栏「系统通知」那一行在 .ow-sidebar 里，不受影响。
            // [data-theme="dark"] 挂在 <html> 上，故这条能正确后置覆盖上一条（特异性也更高）。
            + 'body.ow-chat-body .ow-main .ow-msg-meta .ow-msg-nick{color:#000;}'
            + '[data-theme="dark"] body.ow-chat-body .ow-main .ow-msg-meta .ow-msg-nick{color:#FFF;}';
    }

    /* ---------- 写入 <style id="ow-cb-style">（存在则复用，不存在才建） ---------- */
    function injectStyle(css) {
        var el = d.getElementById('ow-cb-style');
        if (!el) {
            el = d.createElement('style');
            el.id = 'ow-cb-style';
            d.head.appendChild(el);
        }
        el.textContent = css;
    }

    /* ---------- 前端兜底：仅当服务端 page.head 没注入时才补一次（首屏） ---------- */
    function applyBg() {
        if (d.getElementById('ow-cb-style')) return;   // 服务端已注入
        var css = buildCss();
        if (!css) return;
        injectStyle(css);
    }

    /* ---------- 保存后强制刷新 ----------
       ⚠️ 这里**不能**走 applyBg：page.head 在页面加载时就写死了那一份样式，
       applyBg 见元素已存在会直接 return，于是「保存后仍是旧背景、要刷新页面才生效」。
       故保存后必须按当前 state 重算并覆盖该元素（无图无叠加色则连元素一起撤掉）。 */
    function refreshBg() {
        var css = buildCss();
        if (!css) {
            var el = d.getElementById('ow-cb-style');
            if (el && el.parentNode) el.parentNode.removeChild(el);
            return;
        }
        injectStyle(css);
    }

    /* ---------- 在「个人设置」弹窗里追加背景区块 ----------
       做法：猴补丁 OwChat.openSettings —— 调原函数打开弹窗，然后把背景区块并进「外观」那一页
       （v1.3.112 起个人设置成分组标签，背景与日夜模式、色系是同一类偏好；
       v1.3.113 标签换成通用轮子 owTabs，面板属性从 data-setpane 改名为 data-pane）。
       老核心没有 data-pane 这个结构，回落到「插到保存按钮之前」，两边都不报错。 */
    var origOpenSettings = OwChat.openSettings;
    OwChat.openSettings = function () {
        origOpenSettings.apply(this, arguments);
        var modal = $('owModal');
        if (!modal) return;
        var host = modal.querySelector('[data-pane="look"]');
        var saveBtn = modal.querySelector('button.ow-btn-primary.ow-btn-block');
        if (!host && !saveBtn) return;
        if (modal.querySelector('.ow-cb-section')) return;   // 已注入
        var section = d.createElement('div');
        section.className = 'ow-cb-section';
        section.innerHTML = '<h4>聊天背景</h4>'
            + '<p class="ow-cb-desc">为聊天区挑一张背景图，或只用一层颜色 / 渐变遮罩；遮罩叠在图上还能压暗背景、保证文字可读。</p>'
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
                // 拖动即时生效：只改 state 与文字而不重算 CSS 的话，用户拖完看不到任何变化，
                // 会认为「遮罩透明度没效果」（原实现要等点保存、由 refreshBg 才刷）。
                op.oninput = function () {
                    if (state.setting) state.setting.opacity = parseInt(op.value, 10);
                    if (ov) ov.textContent = op.value + '%';
                    refreshBg();
                };
            }
        });
    };

    /* ---------- 渲染：模式 tab ---------- */
    function renderTabs(activeMode) {
        var tabs = $('owCBTabs');
        if (!tabs) return;
        var canCustom = state.cfg ? state.cfg.can_custom : true;
        var customReason = state.cfg ? state.cfg.custom_reason : '';
        // 「默认」这一档现在只剩遮罩语义（不加载图片、只叠一层颜色/渐变），故正名为「遮罩」。
        // ⚠️ 键名仍沿用 'none'：存量用户设置存的就是它，改键名要迁移数据，不划算。
        var modes = [
            { k: 'none',    t: '遮罩' },
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
            body.innerHTML = '<p class="ow-cb-desc">只用一层颜色 / 渐变遮罩，不加载图片。在下方挑一个遮罩色即可。</p>';
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
            refreshBg();   // 立即预览（切换前只改 state，页面不会有任何反应）
        },

        /* 选中某个内置背景 */
        pickItem: function (id) {
            if (!state.setting) return;
            state.setting.mode = 'item';
            state.setting.item_id = id;
            renderTabs('item');
            renderItemsGrid();
            refreshBg();
        },

        /* 选中叠加色 */
        pickOverlay: function (k) {
            if (!state.setting) return;
            state.setting.overlay = k;
            renderOverlays();
            refreshBg();
        },

        /* 上传自定义背景 */
        uploadCustom: function (file) {
            if (!file) {
                var fi = $('owCBFileInput');
                if (!fi) return;
                // 首次点击时 input 里还没有文件 —— 这里必须主动打开系统选择框。
                // 原来直接 return，于是「上传」按钮点了毫无反应（那个 input 是
                // display:none，用户没有任何方式去选中文件）。选完文件后由它的
                // onchange 带着 file 再进来，才真正走上传。
                if (!fi.files || !fi.files[0]) { fi.click(); return; }
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
            // 按刚保存的 state 强制刷新背景样式（applyBg 会被「元素已存在」挡住，必须用 refreshBg）
            setTimeout(refreshBg, 50);
        });
    };

    /* ---------- 启动：拉取并应用 ---------- */
    loadSetting(function (ok) {
        if (ok) applyBg();
    });
})(window, document);
