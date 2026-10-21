/**
 * 云存储插件 - 后台交互（v1.0.0）
 * 路由：plugin_cloud_storage_*；全局对象 OwCS。
 * 后台页 HTML 由 admin_plugin_page 异步注入，MutationObserver 监听 #owAdminMain 自动初始化；
 * 幂等标记挂 DOM（data-cs-inited），SPA 重注入不会二次 init。
 */
(function (w, d) {
    'use strict';
    var $ = function (id) { return d.getElementById(id); };

    var state = {
        spaces: [], activeId: 0, opts: {}, types: {}, fields: {},
        editing: 0,        // 0 = 新建
        type: 'oss',
        mig: { total: 0, done: 0, fail: 0, running: false }
    };

    /* ---------- 工具 ---------- */
    function esc(s) { return w.esc ? w.esc(s) : String(s == null ? '' : s); }
    function sizeText(n) {
        n = parseInt(n || 0, 10);
        if (n < 1024) return n + ' B';
        if (n < 1048576) return (n / 1048576 >= 10 ? Math.round(n / 1024) : (n / 1024).toFixed(1)) + ' KB';
        return (n / 1048576 >= 10 ? Math.round(n / 1048576) : (n / 1048576).toFixed(1)) + ' MB';
    }
    function bindSwitches(el) {
        if (el && typeof w.bindSwitches === 'function') w.bindSwitches(el);
        if (el && typeof w.OwTip !== 'undefined' && w.OwTip.scan) w.OwTip.scan(el);
    }

    /* ---------- 读取 ---------- */
    function load() {
        OwApi.post('plugin_cloud_storage_list', {}, render);
    }

    function render(r) {
        if (!r.ok) { toast(r.msg || '读取失败'); return; }
        state.spaces = r.spaces || [];
        state.activeId = parseInt(r.active_id || 0, 10);
        state.opts = r.opts || {};
        state.types = r.types || {};
        state.fields = r.fields || {};
        renderActive();
        renderSpaces();
        renderOpts();
    }

    function renderActive() {
        var box = $('owCSActiveBody'), edit = $('owCSActiveEdit'), test = $('owCSActiveTest');
        if (!box) return;
        var sp = null, i;
        for (i = 0; i < state.spaces.length; i++) {
            if (parseInt(state.spaces[i].id, 10) === state.activeId) { sp = state.spaces[i]; break; }
        }
        if (edit) edit.style.display = sp ? '' : 'none';
        if (test) test.style.display = sp ? '' : 'none';
        if (!sp) {
            box.innerHTML = '<p class="owcs-empty" style="padding:14px">尚未启用云存储 —— 新上传全部走本地磁盘。'
                + '在下方「存储空间」里新建一个并点「启用」，或先建好空间再切换。</p>';
            return;
        }
        var meta = domainOf(sp) || '（未配置访问域名）';
        box.innerHTML =
            '<div class="owcs-hero"><div class="owcs-hero-main">'
            + '<div class="owcs-hero-name">' + esc(sp.name) + '<span class="owcs-pill">生效中</span>'
            + '<span class="owcs-space-type">' + esc(sp.type_name) + '</span></div>'
            + '<div class="owcs-hero-meta">' + esc(meta) + '</div>'
            + '<div class="owcs-hero-stats">'
            + '<span><b>' + sp.file_count + '</b>云端文件</span>'
            + '<span><b>' + sizeText(sp.total_size) + '</b>占用</span>'
            + '</div></div></div>';
    }

    function domainOf(sp) {
        var c = sp.cfg || {};
        return c.domain ? String(c.domain).replace(/^https?:\/\//, '') : '';
    }

    function renderSpaces() {
        var box = $('owCSSpaceGrid');
        if (!box) return;
        if (!state.spaces.length) {
            box.innerHTML = '<p class="owcs-empty">还没有存储空间，点右上角「新建空间」添加。</p>';
            return;
        }
        var h = '', i, sp;
        for (i = 0; i < state.spaces.length; i++) {
            sp = state.spaces[i];
            h += '<div class="owcs-space' + (parseInt(sp.id, 10) === state.activeId ? ' is-active' : '') + '">'
                + '<div><div class="owcs-space-head"><span class="owcs-space-name">' + esc(sp.name) + '</span>'
                + (parseInt(sp.id, 10) === state.activeId ? '<span class="owcs-pill">生效中</span>' : '')
                + '</div><div class="owcs-space-type">' + esc(sp.type_name) + '</div></div>'
                + '<div class="owcs-space-stats"><span><b>' + sp.file_count + '</b> 文件</span>'
                + '<span><b>' + sizeText(sp.total_size) + '</b></span></div>'
                + '<div class="owcs-space-ops">'
                + (parseInt(sp.id, 10) === state.activeId ? '' :
                   '<button class="ow-btn ow-btn-primary" type="button" data-cs-act="active" data-id="' + sp.id + '">启用</button>')
                + '<button class="ow-btn ow-btn-ghost" type="button" data-cs-act="edit" data-id="' + sp.id + '">编辑</button>'
                + '<button class="ow-btn ow-btn-ghost" type="button" data-cs-act="test" data-id="' + sp.id + '">测试</button>'
                + '<button class="ow-btn ow-btn-ghost" type="button" data-cs-act="del" data-id="' + sp.id + '">删除</button>'
                + '</div></div>';
        }
        box.innerHTML = h;
        var btns = box.getElementsByTagName('button'), j;
        for (j = 0; j < btns.length; j++) {
            btns[j].onclick = function () {
                var id = parseInt(this.getAttribute('data-id'), 10), act = this.getAttribute('data-cs-act');
                if (act === 'active') setActive(id);
                else if (act === 'edit') editSpace(id);
                else if (act === 'test') testSpace(id);
                else if (act === 'del') delSpace(id);
            };
        }
    }

    function renderOpts() {
        var box = $('owCSOptsBody');
        if (!box) return;
        var o = state.opts, kinds = String(o.kinds || '').split(',');
        function on(k) { return kinds.indexOf(k) >= 0 ? ' checked' : ''; }
        box.innerHTML =
            '<div class="ow-form-row" style="flex-wrap:wrap;gap:10px">'
            + '<div class="ow-switch-row"><span class="ow-switch-label">本地双备份</span>'
            + '<input type="checkbox" class="ow-switch-input" id="owCSKeepLocal"' + (o.keep_local === '1' ? ' checked' : '') + '>'
            + '<label class="ow-switch" for="owCSKeepLocal"></label>'
            + '<p class="ow-switch-hint">云端上传成功后本地也留一份。关掉才真正省磁盘，但停用插件时旧文件已不在本地。</p></div>'
            + '<div class="ow-switch-row"><span class="ow-switch-label">删除同步</span>'
            + '<input type="checkbox" class="ow-switch-input" id="owCSSyncDel"' + (o.sync_delete === '1' ? ' checked' : '') + '>'
            + '<label class="ow-switch" for="owCSSyncDel"></label>'
            + '<p class="ow-switch-hint">消息被撤回且无人再引用时，同步删掉云端对象；同时每小时自动回收未引用文件。</p></div>'
            + '</div>'
            + '<div class="ow-form-row" style="margin-top:10px;flex-wrap:wrap;gap:10px;align-items:center">'
            + '<span style="font-size:13px;color:var(--ow-text-sub)">接入云端的上传类型：</span>'
            + '<label style="font-size:13px"><input type="checkbox" class="owcs-kind" value="avatar"' + on('avatar') + '> 头像</label>'
            + '<label style="font-size:13px"><input type="checkbox" class="owcs-kind" value="sticker"' + on('sticker') + '> 贴纸</label>'
            + '<label style="font-size:13px"><input type="checkbox" class="owcs-kind" value="image"' + on('image') + '> 图片消息</label>'
            + '<label style="font-size:13px"><input type="checkbox" class="owcs-kind" value="file"' + on('file') + '> 文件与语音</label>'
            + '</div>'
            + '<div class="ow-form-row" style="margin-top:10px"><div class="ow-form-item" style="flex:1">'
            + '<input class="ow-input" id="owCSSuffix" placeholder="?x-oss-process=image/resize,w_800" value="' + esc(o.url_suffix || '') + '">'
            + '<p style="font-size:12px">访问链接后缀：拼在每个云端地址后的查询串，用于图片处理参数（不需要就留空）。</p>'
            + '</div></div>';
        bindSwitches(box);
    }

    /* ---------- 空间编辑器 ---------- */
    function newSpace() {
        state.editing = 0;
        state.type = 'oss';
        openEditor('新建空间');
    }

    function editSpace(id) {
        var sp = null, i;
        for (i = 0; i < state.spaces.length; i++) {
            if (parseInt(state.spaces[i].id, 10) === id) { sp = state.spaces[i]; break; }
        }
        if (!sp) return;
        state.editing = id;
        state.type = sp.type;
        openEditor('编辑空间：' + sp.name, sp);
    }

    function openEditor(title, sp) {
        var box = $('owCSEditor'), body = $('owCSEditorBody'), t = $('owCSEditorTitle');
        if (!box || !body) return;
        if (t) t.textContent = title;
        var th = '', k;
        for (k in state.types) {
            if (!state.types.hasOwnProperty(k)) continue;
            th += '<div class="owcs-type' + (k === state.type ? ' is-on' : '') + '" data-cs-type="' + k + '">'
                + '<b>' + esc(state.types[k]) + '</b></div>';
        }
        body.innerHTML =
            '<div class="ow-form-item"><label>空间名称</label>'
            + '<input class="ow-input" id="owCSName" maxlength="60" placeholder="给我的图床" value="' + esc(sp ? sp.name : '') + '">'
            + '<p style="font-size:12px">只用于后台辨认，访客看不到。</p></div>'
            + '<div class="ow-form-item" style="margin-top:10px"><label>存储类型</label></div>'
            + '<div class="owcs-type-grid" id="owCSTypes">' + th + '</div>'
            + '<div class="owcs-form-grid" id="owCSFields"></div>'
            + '<div class="ow-form-row" style="margin-top:14px;gap:8px">'
            + '<button class="ow-btn ow-btn-primary" type="button" id="owCSSave">保存</button>'
            + '<button class="ow-btn ow-btn-ghost" type="button" onclick="OwCS.closeEditor()">取消</button>'
            + '</div>';
        renderFields(sp);
        var cards = $('owCSTypes').getElementsByClassName('owcs-type'), j;
        for (j = 0; j < cards.length; j++) {
            cards[j].onclick = function () {
                state.type = this.getAttribute('data-cs-type');
                var all = $('owCSTypes').getElementsByClassName('owcs-type'), m;
                for (m = 0; m < all.length; m++) all[m].className = 'owcs-type' + (all[m] === this ? ' is-on' : '');
                renderFields(null);
            };
        }
        $('owCSSave').onclick = saveSpace;
        box.style.display = '';
        box.scrollIntoView ? box.scrollIntoView({ behavior: 'smooth', block: 'nearest' }) : 0;
    }

    function renderFields(sp) {
        var box = $('owCSFields');
        if (!box) return;
        var fields = state.fields[state.type] || [], h = '', i, f, cfg = sp ? (sp.cfg || {}) : {};
        for (i = 0; i < fields.length; i++) {
            f = fields[i];
            if (f.k === 'path_style') {
                h += '<div class="ow-form-item"><label>' + esc(f.label) + '</label>'
                    + '<label style="font-size:13px"><input type="checkbox" id="owCSF_path_style"' + (cfg.path_style === '1' ? ' checked' : '') + '> 启用</label>'
                    + '<p style="font-size:12px">MinIO 等自建服务常需要开启；AWS / 华为云 OBS 等保持关闭。</p></div>';
                continue;
            }
            var val = sp && cfg[f.k] ? String(cfg[f.k]) : '';
            var ph = f.secret && val ? '已设置（留空不变）' : (f.ph || '');
            h += '<div class="ow-form-item"><label>' + esc(f.label) + '</label>'
                + '<input class="ow-input" id="owCSF_' + f.k + '" data-cs-secret="' + (f.secret ? '1' : '0') + '"'
                + ' placeholder="' + esc(ph) + '" value="' + (f.secret ? '' : esc(val)) + '">'
                + (f.secret ? '<p class="owcs-secret">密钥只写不读，留空表示不修改。</p>' : '')
                + '</div>';
        }
        box.innerHTML = h;
    }

    function closeEditor() {
        var box = $('owCSEditor');
        if (box) box.style.display = 'none';
    }

    function saveSpace() {
        var name = ($('owCSName') || {}).value || '';
        var data = { id: state.editing, name: name, type: state.type };
        var fields = state.fields[state.type] || [], i, f, el;
        for (i = 0; i < fields.length; i++) {
            f = fields[i];
            el = $('owCSF_' + f.k);
            if (f.k === 'path_style') { data[f.k] = el && el.checked ? '1' : '0'; continue; }
            if (el) data[f.k] = el.value;
        }
        OwApi.post('plugin_cloud_storage_save', data, function (r) {
            toast(r.msg || (r.ok ? '已保存' : '保存失败'));
            if (r.ok) { closeEditor(); load(); }
        });
    }

    function setActive(id) {
        OwApi.post('plugin_cloud_storage_active', { id: id }, function (r) {
            toast(r.msg || (r.ok ? '已切换' : '切换失败'));
            if (r.ok) load();
        });
    }

    function delSpace(id) {
        var sp = null, i;
        for (i = 0; i < state.spaces.length; i++) if (parseInt(state.spaces[i].id, 10) === id) sp = state.spaces[i];
        if (!sp) return;
        OwAdmin.confirm('确认删除空间「' + sp.name + '」？只删配置，不动云端文件；空间里还有文件时不允许删除。', function () {
            OwApi.secure('plugin_cloud_storage_del', { id: id }, function (r) {
                toast(r.msg || (r.ok ? '已删除' : '删除失败'));
                if (r.ok) load();
            });
        });
    }

    function testSpace(id) {
        toast('正在测试连接…');
        OwApi.post('plugin_cloud_storage_test', { id: id }, function (r) {
            toast(r.msg || (r.ok ? '连接正常' : '连接失败'));
        });
    }

    /* ---------- 选项 ---------- */
    function saveOpts() {
        var kinds = [], ks = d.querySelectorAll('.owcs-kind'), i;
        for (i = 0; i < ks.length; i++) if (ks[i].checked) kinds.push(ks[i].value);
        OwApi.post('plugin_cloud_storage_opts', {
            keep_local: $('owCSKeepLocal') && $('owCSKeepLocal').checked ? '1' : '0',
            sync_delete: $('owCSSyncDel') && $('owCSSyncDel').checked ? '1' : '0',
            url_suffix: ($('owCSSuffix') || {}).value || '',
            kinds: kinds.join(',')
        }, function (r) {
            toast(r.msg || (r.ok ? '已保存' : '保存失败'));
            if (r.ok) load();
        });
    }

    /* ---------- 迁移 ---------- */
    function migStart() {
        if (state.mig.running) return;
        if (!state.activeId) { toast('请先设置生效空间'); return; }
        OwAdmin.confirm('把 uploads/ 下尚未入库的文件搬到生效空间？文件多的话要等一会儿，可以随时刷新页面中断。', function () {
            OwApi.secure('plugin_cloud_storage_mig_start', {}, function (r) {
                if (!r.ok) { toast(r.msg || '启动失败'); return; }
                var box = $('owCSMigBox'), bar = $('owCSMigBar'), txt = $('owCSMigText');
                if (box) box.style.display = '';
                state.mig = { total: parseInt(r.total || 0, 10), done: 0, fail: 0, running: true };
                if (bar) bar.style.width = '0%';
                if (txt) txt.textContent = '0 / ' + state.mig.total;
                migStep();
            });
        });
    }

    function migStep() {
        if (!state.mig.running) return;
        OwApi.secure('plugin_cloud_storage_mig_step', {}, function (r) {
            if (!r.ok) {
                state.mig.running = false;
                toast(r.msg || '迁移中断');
                return;
            }
            state.mig.done += parseInt(r.ok || 0, 10);
            state.mig.fail += parseInt(r.fail || 0, 10);
            var pct = state.mig.total ? Math.round(state.mig.done * 100 / state.mig.total) : 100;
            var bar = $('owCSMigBar'), txt = $('owCSMigText');
            if (bar) bar.style.width = pct + '%';
            if (txt) txt.textContent = '已迁移 ' + state.mig.done + ' / ' + state.mig.total
                + (state.mig.fail ? '（失败 ' + state.mig.fail + '）' : '') + (r.done ? ' —— 完成' : '');
            if (r.done) {
                state.mig.running = false;
                toast('迁移完成：成功 ' + state.mig.done + '，失败 ' + state.mig.fail);
                load();
            } else {
                migStep();
            }
        });
    }

    /* ---------- 维护 ---------- */
    function gc() {
        OwAdmin.confirm('清理云端已无任何引用的文件？（被消息、贴纸、头像引用的不会动）', function () {
            OwApi.secure('plugin_cloud_storage_gc', {}, function (r) {
                toast(r.msg || (r.ok ? '已清理' : '清理失败'));
                if (r.ok) load();
            });
        });
    }

    function editActive() { if (state.activeId) editSpace(state.activeId); }
    function testActive() { if (state.activeId) testSpace(state.activeId); }

    /* ---------- 导出 ---------- */
    w.OwCS = {
        init: load, refresh: load,
        newSpace: newSpace, editSpace: editSpace, editActive: editActive, closeEditor: closeEditor,
        testSpace: testSpace, testActive: testActive,
        saveOpts: saveOpts, migStart: migStart, gc: gc
    };

    /* 后台页异步注入：MutationObserver 监听 #owAdminMain，幂等标记挂 DOM */
    function watchInit() {
        var main = $('owAdminMain');
        if (!main) return;
        var tryInit = function () {
            var el = $('owCSApp');
            if (el && el.getAttribute('data-cs-inited') !== '1') {
                el.setAttribute('data-cs-inited', '1');
                load();
            }
        };
        if (typeof w.MutationObserver !== 'undefined') {
            new w.MutationObserver(tryInit).observe(main, { childList: true, subtree: true });
        } else {
            w.setInterval(tryInit, 300);
        }
        tryInit();
    }
    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', watchInit);
    else watchInit();
})(w, d);
