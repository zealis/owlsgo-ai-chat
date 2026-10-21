/* chat-background · 后台交互
 *
 * 全局对象 OwCB：
 *   - init()           初始化（读配置 + 列表）
 *   - loadList()       加载背景库
 *   - saveCfg()        保存插件配置
 *   - openAdd()        打开「添加背景」弹窗（远程链接 / 上传）
 *   - edit(id)         编辑某条
 *   - toggle(id, en)   启用/停用
 *   - del(id)          删除（敏感）
 *   - importPresets()  从 设计文档/background 导入预设
 *
 * 幂等：data-cb-inited 标记避免重复初始化。 */
(function (w, d) {
    'use strict';
    var $ = function (id) { return d.getElementById(id); };
    var toast = w.toast || function (s) { w.alert(s); };
    if (!w.OwApi) return;

    var state = {
        list: [], items: [], cfg: null, attachEnabled: false,
        editing: null
    };

    w.OwCB = {
        init: function () {
            OwCB.loadCfg();
            OwCB.loadList();
        },

        /* ---------- 配置 ---------- */
        loadCfg: function () {
            OwApi.post('plugin_chat_background_admin_config_get', {}, function (r) {
                if (!r || !r.ok) { toast((r && r.msg) || '读取配置失败'); return; }
                state.cfg = r.config || {};
                state.attachEnabled = !!r.attach_enabled;
                var ml = $('owCBMinLevel');
                if (ml) ml.value = state.cfg.min_level_custom || '0';
            });
        },

        saveCfg: function () {
            var ml = $('owCBMinLevel');
            if (!ml) { toast('表单未就绪'); return; }
            var mlv = parseInt(ml.value, 10);
            if (isNaN(mlv) || mlv < 0) { toast('等级阈值需为 ≥0 的整数'); return; }
            OwApi.post('plugin_chat_background_admin_config_save', {
                min_level_custom: String(mlv)
            }, function (r) {
                if (!r || !r.ok) { toast((r && r.msg) || '保存失败'); return; }
                toast('配置已保存');
                state.cfg = r.config;
            });
        },

        /* ---------- 背景库列表 ---------- */
        loadList: function () {
            OwApi.post('plugin_chat_background_admin_list', {}, function (r) {
                if (!r || !r.ok) { toast((r && r.msg) || '加载失败'); return; }
                state.attachEnabled = !!r.attach_enabled;
                state.list = r.data || [];
                OwCB.renderList();
            });
        },

        renderList: function () {
            var box = $('owCBList');
            if (!box) return;
            if (!state.list.length) {
                box.innerHTML = '<p style="color:var(--ow-text-sub);font-size:13px;padding:8px 0">背景库为空。可点击「添加背景」或「从设计文档导入预设」。</p>';
                return;
            }
            var h = '<div class="ow-cb-admin-grid">';
            // 收集每个卡片的 preview URL，innerHTML 后用 JS 设 background-image
            // （避免 url('...') 在 style 属性里被引号冲突破坏）
            var previews = [];
            for (var i = 0; i < state.list.length; i++) {
                var it = state.list[i];
                var tile = (it.kind === 'svg');
                previews.push(it.preview || '');
                h += '<div class="ow-cb-admin-card' + (tile ? ' ow-cb-tile' : '') + '">'
                    + '<div class="ow-cb-admin-thumb" data-bg-idx="' + i + '">'
                    + (parseInt(it.enabled, 10) === 1 ? '' : '<span class="ow-cb-admin-disabled-mark">已停用</span>')
                    + '</div>'
                    + '<div class="ow-cb-admin-meta"><b>' + esc(it.name) + '</b>'
                    + it.kind_cn + ' · ' + esc(it.source_cn)
                    + (it.url ? '<br><span style="color:var(--ow-text-sub);word-break:break-all">' + esc(it.url.slice(0, 40)) + (it.url.length > 40 ? '…' : '') + '</span>' : '')
                    + '</div>'
                    + '<div class="ow-cb-admin-actions">'
                    + '<a onclick="OwCB.toggle(' + it.id + ',' + (parseInt(it.enabled, 10) === 1 ? 0 : 1) + ')">' + (parseInt(it.enabled, 10) === 1 ? '停用' : '启用') + '</a>'
                    + ' <a onclick="OwCB.edit(' + it.id + ')">编辑</a>'
                    + ' <a onclick="OwCB.del(' + it.id + ')">删除</a>'
                    + '</div></div>';
            }
            h += '</div>';
            box.innerHTML = h;
            // 用 JS 设 background-image（CSS 字符串里 ' 与 \ 需转义）
            var thumbs = box.querySelectorAll('.ow-cb-admin-thumb');
            for (var k = 0; k < thumbs.length; k++) {
                var url = previews[k];
                if (!url) continue;
                thumbs[k].style.backgroundImage = "url('" + String(url).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "')";
            }
        },

        toggle: function (id, en) {
            OwApi.post('plugin_chat_background_admin_toggle', { id: id, enabled: en }, function (r) {
                if (!r || !r.ok) { toast((r && r.msg) || '操作失败'); return; }
                toast(r.msg);
                OwCB.loadList();
            });
        },

        del: function (id) {
            OwAdmin.confirm('确认删除该背景？被删除后，已选此背景的用户将回退到默认背景。', function () {
                OwApi.secure('plugin_chat_background_admin_delete', { id: id }, function (r) {
                    if (!r || !r.ok) { toast((r && r.msg) || '删除失败'); return; }
                    toast(r.msg);
                    OwCB.loadList();
                });
            });
        },

        /* ---------- 添加 / 编辑 ---------- */
        openAdd: function () {
            state.editing = null;
            OwCB.openEditModal({
                name: '', kind: 'image', source: 'admin', url: '', file: '',
                thumb: '', enabled: 1, sort_order: 0
            });
        },

        edit: function (id) {
            var it = null;
            for (var i = 0; i < state.list.length; i++) {
                if (parseInt(state.list[i].id, 10) === id) { it = state.list[i]; break; }
            }
            if (!it) { toast('未找到该背景'); return; }
            state.editing = it;
            OwCB.openEditModal(it);
        },

        openEditModal: function (it) {
            var isNew = !state.editing;
            var canUpload = state.attachEnabled;
            // 用 ow-modal 复用
            var mask = d.createElement('div');
            mask.className = 'ow-modal-mask';
            mask.style.display = 'flex';
            mask.innerHTML = '<div class="ow-modal" style="width:460px;max-width:94%">'
                + '<button class="ow-modal-close">✕</button>'
                + '<h3>' + (isNew ? '添加背景' : '编辑背景') + '</h3>'
                + '<div class="ow-form-item"><label>名称</label>'
                + '<input class="ow-input" id="owCBEditName" value="' + esc(it.name || '') + '"></div>'

                + '<div class="ow-form-item"><label>类型</label>'
                + '<select class="ow-input" id="owCBEditKind" onchange="OwCB.editKindChange()">'
                + '<option value="image"' + (it.kind === 'image' ? ' selected' : '') + '>图片（webp/jpg/png，全屏 cover）</option>'
                + '<option value="svg"' + (it.kind === 'svg' ? ' selected' : '') + '>SVG 图案（平铺 repeat）</option>'
                + '<option value="url"' + (it.kind === 'url' ? ' selected' : '') + '>远程链接</option>'
                + '</select></div>'

                + '<div id="owCBEditFileRow" class="ow-form-item"' + (it.kind === 'url' ? ' style="display:none"' : '') + '><label>背景文件</label>'
                + '<div style="display:flex;-webkit-display:flex;gap:6px;align-items:center;-webkit-align-items:center">'
                + '<input class="ow-input" id="owCBEditFile" value="' + esc(it.file || '') + '" placeholder="uploads/background/ 下的相对路径" style="-webkit-flex:1;flex:1;min-width:0">'
                + (canUpload
                    ? '<button class="ow-btn ow-btn-ghost" id="owCBUploadBtn" onclick="OwCB.adminUpload()">上传</button>'
                        + '<input type="file" id="owCBEditFileInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none">'
                    : '')
                + '</div>'
                + '<p style="font-size:12px;color:var(--ow-text-sub);margin-top:4px">'
                + (canUpload ? '可上传新文件，或直接填写已存在的相对路径。' : '附件上传插件未启用，无法上传新文件。')
                + '</p></div>'

                + '<div id="owCBEditUrlRow" class="ow-form-item"' + (it.kind !== 'url' ? ' style="display:none"' : '') + '><label>远程链接</label>'
                + '<input class="ow-input" id="owCBEditUrl" value="' + esc(it.url || '') + '" placeholder="https://..."></div>'

                + '<div class="ow-form-item"><label>排序</label>'
                + '<input class="ow-input" id="owCBEditSort" type="number" value="' + (parseInt(it.sort_order, 10) || 0) + '" style="width:120px"></div>'

                + '<div class="ow-form-item"><label>启用</label>'
                + '<select class="ow-input" id="owCBEditEnabled" style="width:120px">'
                + '<option value="1"' + (parseInt(it.enabled, 10) === 1 ? ' selected' : '') + '>启用</option>'
                + '<option value="0"' + (parseInt(it.enabled, 10) !== 1 ? ' selected' : '') + '>停用</option>'
                + '</select></div>'

                + '<div class="ow-modal-actions">'
                + '<button class="ow-btn ow-btn-ghost" onclick="OwCB.closeEdit()">取消</button>'
                + '<button class="ow-btn ow-btn-primary" onclick="OwCB.saveEdit()">保存</button>'
                + '</div>'
                + '</div>';
            d.body.appendChild(mask);
            mask.querySelector('.ow-modal-close').onclick = function () { d.body.removeChild(mask); };
            mask.onclick = function (e) { if (e.target === mask) d.body.removeChild(mask); };
            state.editMask = mask;

            // 上传文件
            var fi = $('owCBEditFileInput');
            if (fi) {
                fi.onchange = function () {
                    if (!this.files || !this.files[0]) return;
                    // v1.5.18：上传在「选完文件」之后才开始，OwApi 的自动锁拿不到这次点击，
                    // 把按钮一起传进去，上传期间禁用它。
                    OwCB.adminUpload(this.files[0], $('owCBUploadBtn'));
                    this.value = '';
                };
            }
        },

        closeEdit: function () {
            if (state.editMask && state.editMask.parentNode) state.editMask.parentNode.removeChild(state.editMask);
        },

        editKindChange: function () {
            var kind = $('owCBEditKind').value;
            var fileRow = $('owCBEditFileRow'), urlRow = $('owCBEditUrlRow');
            if (fileRow) fileRow.style.display = (kind === 'url') ? 'none' : 'block';
            if (urlRow) urlRow.style.display = (kind === 'url') ? 'block' : 'none';
        },

        adminUpload: function (file, btn) {
            if (!file) {
                var fi = $('owCBEditFileInput');
                if (!fi) return;
                // 同前台上传：没有文件时先主动打开系统选择框，而不是直接 return
                // （那个 input 是 display:none，否则「上传」按钮点了没反应）
                if (!fi.files || !fi.files[0]) { fi.click(); return; }
                file = fi.files[0];
            }
            if (!state.attachEnabled) { toast('附件上传插件未启用'); return; }
            // v1.5.18：单文件上传期间禁用「上传」；成功失败都要放开
            var release = (btn && w.OwBusy) ? OwBusy.lock(btn, 60000) : null;
            var finish = function () { if (release) { release(); release = null; } };
            toast('上传中…');
            OwApi.upload('plugin_chat_background_admin_upload', file, {}, function (r) {
                finish();
                if (!r || !r.ok) { toast((r && r.msg) || '上传失败'); return; }
                var f = $('owCBEditFile');
                if (f) f.value = r.file;
                toast('上传成功');
            });
        },

        saveEdit: function () {
            var kind = $('owCBEditKind').value;
            var name = $('owCBEditName').value.trim();
            var url = $('owCBEditUrl').value.trim();
            var file = $('owCBEditFile').value.trim();
            var sort_order = parseInt($('owCBEditSort').value, 10) || 0;
            var enabled = $('owCBEditEnabled').value === '1' ? 1 : 0;
            if (name === '') name = '未命名背景';
            var payload = {
                id: state.editing ? state.editing.id : 0,
                name: name, kind: kind, source: state.editing ? state.editing.source : 'admin',
                url: url, file: file, thumb: '',
                enabled: enabled, sort_order: sort_order
            };
            if (kind === 'url') {
                if (!url || !/^https?:\/\//i.test(url)) { toast('远程链接需以 http:// 或 https:// 开头'); return; }
                payload.file = '';
            } else {
                if (!file) { toast('缺少背景文件路径'); return; }
                payload.url = '';
            }
            OwApi.post('plugin_chat_background_admin_save', payload, function (r) {
                if (!r || !r.ok) { toast((r && r.msg) || '保存失败'); return; }
                toast(r.msg);
                OwCB.closeEdit();
                OwCB.loadList();
            });
        },

        /* ---------- 从设计文档导入预设 ---------- */
        importPresets: function () {
            OwAdmin.confirm('从 设计文档/background/svg 与 /webp 目录导入所有尚未入库的预设背景？', function () {
                OwApi.post('plugin_chat_background_admin_import_presets', {}, function (r) {
                    if (!r || !r.ok) { toast((r && r.msg) || '导入失败'); return; }
                    toast(r.msg);
                    OwCB.loadList();
                });
            });
        }
    };

    /* 后台页面 HTML 注入 #owAdminMain 后初始化 */
    function _cbWatchInit() {
        var main = $('owAdminMain');
        if (!main) return;
        var tryInit = function () {
            var el = $('owCBMinLevel');
            if (el && el.getAttribute('data-cb-inited') !== '1') {
                el.setAttribute('data-cb-inited', '1');
                OwCB.init();
            }
        };
        if (typeof MutationObserver !== 'undefined') {
            var ob = new MutationObserver(tryInit);
            ob.observe(main, { childList: true, subtree: true });
        } else {
            setInterval(tryInit, 300);
        }
        tryInit();
    }
    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', _cbWatchInit);
    else _cbWatchInit();
})(window, document);
