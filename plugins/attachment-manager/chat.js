/* 附件上传 · 前端（attachment-manager 插件，v1.4.0 图文混排）
 *
 * 四件事：
 *  ① 把「图片 / 文件」两个按钮注入核心锚点 #owAttachTools；
 *  ② 维护输入框上方的**待发区**（最多 9 个图片/视频）：点图片按钮选图、Ctrl+V 粘贴、
 *     拖拽时选「以图片/视频方式发送」—— 三条路都进这里，最后与输入框里的文字一起
 *     发成**一条** rich 消息（图文混排，不是拆成几条）；
 *  ③ 拖进来的图片/视频先弹窗二选一：拖这个动作本身说不清要的是「内容」还是「附件」，
 *     而两种发法在对方看来完全不同，猜错的代价比多点一次高；
 *  ④ 「以文件方式发送」保持原样：走 file 消息，保留文件名与原始字节（不压缩）。
 *
 * 插件停用 → 本文件不加载 → 按钮、待发区、拖拽一并消失，核心输入框只剩表情。
 * 图标一律走核心 OwChat.icon()：这里不抄 path（v1.3.85 之前抄过一份 paperclip，两处会漂移）。
 */
(function (w, d) {
    if (!w.OwChat || !OwChat.cfg) return;
    if (w.OwAttach) return;          // 防重复加载
    var OwApi = w.OwApi;
    if (!OwApi) return;

    var toast = w.toast || function (s) { w.alert(s); };
    var esc = w.esc || function (s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    };
    var $ = function (id) { return d.getElementById(id); };
    var icon = function (n, s) { return OwChat.icon ? OwChat.icon(n, s || 16) : ''; };

    var MAX_PEND = 9;               // 与 Chat::normalizeRich 的媒体上限同值，两边各拦一次
    var pend = [], seq = 0;
    /* 待发区属于「当前这一个会话」：在 A 群攒了三张图、切到 B 群点发送，图就发错地方了。
       核心的视图切换钩子覆盖 首次进入 / 切群 / 进私聊 三种情况，pendKey 记的是
       「这些媒体是为哪个会话攒的」—— 对不上就清掉，绝不跨会话发。 */
    var pendKey = '';
    function nowKey() {
        return OwChat.dm ? 'dm:' + (OwChat.dm.peer || '') : 'room:' + (OwChat.room || 0);
    }

    /* ---------- 头像上传（v1.3.11 从核心迁入） ----------
       核心不再有 kind=avatar 的上传入口。插件在此注册实现：
       核心的头像设置弹窗检测到注册后才显示「上传头像」选项，
       没启用插件时用户仍可用「浏览插图」（生成式头像），功能不会变成死路。 */
    if (OwChat.registerAvatarUploader && !OwChat._avatarUploader) {
        OwChat.registerAvatarUploader(function (file, filename, onOk) {
            // v1.3.30 修 bug：裁剪弹窗导出的是 canvas **Blob**（没有文件名），
            // FormData 会把 multipart 文件名写成 "blob" → 服务端扩展名校验全拒。
            if (filename && w.File && !(file instanceof w.File)) {
                try { file = new w.File([file], filename, { type: (file && file.type) || 'image/jpeg' }); } catch (e) {}
            }
            OwApi.upload('plugin_attachment_manager_upload_avatar', file, {}, function (r) {
                if (!r || !r.ok) { toast(r && r.msg ? r.msg : '头像上传失败'); return; }
                onOk(r.url);
            });
        });
    }

    /* ---------- 待发区 ---------- */

    function tray() {
        var t = $('owPend');
        if (t) return t;
        var input = $('owInput');
        var row = input && input.parentNode;          // .ow-input-row 是 flex 横排
        var bar = row && row.parentNode;              // .ow-inputbar（纵向：工具栏 / 输入行）
        if (!bar) return null;
        t = d.createElement('div');
        t.className = 'ow-pend';
        t.id = 'owPend';
        t.style.display = 'none';
        // 挂在 .ow-input-row **之前**：待发缩略图紧贴输入框上沿，与气泡里
        // 「媒体在上、文字在下」同序。⚠️ 不能插进 .ow-input-row 内部 ——
        // 那一行是 flex(row)，多塞一个块级子元素会把输入框挤扁。
        bar.insertBefore(t, row);
        return t;
    }

    function renderTray() {
        var t = tray();
        if (!t) return;
        if (!pend.length) { t.style.display = 'none'; t.innerHTML = ''; return; }
        var h = '', i, it;
        for (i = 0; i < pend.length; i++) {
            it = pend[i];
            // 视频缩略图直接用 <video> 载首帧（preload=metadata：只取头，不预拉整段流量）
            h += '<span class="ow-pend-item' + (it.busy ? ' is-busy' : '') + '"'
              + ' title="' + esc(it.name || '') + '">'
              + (it.thumb
                    ? (it.kind === 'video'
                        ? '<video src="' + esc(it.thumb) + '" preload="metadata" muted playsinline></video>'
                        : '<img src="' + esc(it.thumb) + '" alt="">')
                    : '<span class="ow-pend-kind">' + esc(it.kind === 'video' ? '视频' : '图片') + '</span>')
              + '<button type="button" class="ow-pend-x" data-x="' + it.id + '" title="移除">✕</button></span>';
        }
        h += '<span class="ow-pend-count">' + pend.length + '/' + MAX_PEND + '</span>';
        t.style.display = '';
        t.innerHTML = h;
        var xs = t.getElementsByTagName('button'), j;
        for (j = 0; j < xs.length; j++) {
            xs[j].onclick = function () { removePend(this.getAttribute('data-x')); };
        }
    }

    function removePend(id) {
        var i;
        for (i = 0; i < pend.length; i++) {
            if (pend[i].id === id) {
                if (pend[i].thumb && w.URL && w.URL.revokeObjectURL) {
                    try { w.URL.revokeObjectURL(pend[i].thumb); } catch (e) {}
                }
                pend.splice(i, 1);
                break;
            }
        }
        renderTray();
    }

    function clearPend() {
        var i;
        for (i = 0; i < pend.length; i++) {
            if (pend[i].thumb && w.URL && w.URL.revokeObjectURL) {
                try { w.URL.revokeObjectURL(pend[i].thumb); } catch (e) {}
            }
        }
        pend = [];
        renderTray();
    }

    /** 类型判定：MIME 优先，缺失或退化成一堆 octet-stream 时按扩展名兜底 */
    function kindOf(f) {
        var t = String((f && f.type) || '').toLowerCase();
        if (t.indexOf('image/') === 0) return 'image';
        if (t.indexOf('video/') === 0) return 'video';
        var ext = (String((f && f.name) || '').split('.').pop() || '').toLowerCase();
        if (/^(jpg|jpeg|png|gif|webp|bmp)$/.test(ext)) return 'image';
        if (/^(mp4|webm|mov|m4v|ogv)$/.test(ext)) return 'video';
        return 'file';
    }

    /**
     * 加一个图片/视频进待发区：先占位显示，上传回来再填地址。
     * 图片走 kind=image 通道（静态图按后台开关转 WebP）；
     * 视频走 file 通道拿原始字节，但只取它的 path 当媒体地址 —— 「以视频方式发送」
     * 要的是能直接播，不是压缩后的产物。
     */
    function addMedia(file, kind) {
        // 整句写死（不拼 MAX_PEND）：语言包按整句匹配，拼出来的数字串翻不到
        if (pend.length >= MAX_PEND) { toast('一次最多 9 个图片/视频'); return; }
        var item = {
            id: 'p' + (++seq), kind: kind, busy: true, url: '', thumb: '',
            name: (file && file.name) || (kind === 'video' ? 'video.mp4' : 'image.png')
        };
        if (w.URL && w.URL.createObjectURL) {
            try { item.thumb = w.URL.createObjectURL(file); } catch (e) {}
        }
        pend.push(item);
        pendKey = nowKey();
        renderTray();
        var done = function (ok, urlOrMsg) {
            var i;
            for (i = 0; i < pend.length; i++) {
                if (pend[i].id !== item.id) continue;
                if (!ok) {
                    if (pend[i].thumb && w.URL && w.URL.revokeObjectURL) {
                        try { w.URL.revokeObjectURL(pend[i].thumb); } catch (e) {}
                    }
                    pend.splice(i, 1);
                    toast(urlOrMsg || '上传失败');
                } else {
                    pend[i].busy = false;
                    pend[i].url = urlOrMsg;
                }
                break;
            }
            renderTray();
        };
        if (kind === 'image') {
            OwApi.upload('plugin_attachment_manager_upload', file, { kind: 'image' }, function (r) {
                done(!!(r && r.ok), r && r.ok ? r.url : (r && r.msg));
            });
        } else {
            OwApi.upload('plugin_attachment_manager_upload_file', file, {}, function (r) {
                done(!!(r && r.ok && r.file), r && r.ok && r.file ? 'uploads/' + r.file.path : (r && r.msg));
            });
        }
    }

    /**
     * 发送：文字 + 待发媒体合成一条 rich 消息。
     * 返回 true = 已接管本次发送（核心因此不再发那条纯文本）。
     * ⚠️ 还有文件在传时必须**保留输入框里的文字**：静默丢掉用户刚打的字是最恶劣的一种失败。
     */
    function sendRich(text) {
        var media = [], i;
        for (i = 0; i < pend.length; i++) {
            if (pend[i].busy) { toast('还有文件在上传，请稍候'); return true; }
            if (pend[i].url) media.push({ url: pend[i].url, kind: pend[i].kind });
        }
        if (!media.length) return false;
        var input = $('owInput');
        if (input) { input.value = ''; if (OwChat.autoGrow) OwChat.autoGrow(); }
        OwChat.send({ type: 'rich', content: JSON.stringify({ text: String(text || ''), media: media }) });
        clearPend();
        return true;
    }

    /* ---------- 上传（文件方式，保持原样） ---------- */

    /** 上传文件附件 → 发 file 消息（保留文件名与原始字节，不压缩） */
    function uploadFile(file) {
        toast('文件上传中…');
        OwApi.upload('plugin_attachment_manager_upload_file', file, {}, function (r) {
            if (!r.ok) { toast(r.msg); return; }
            OwChat.send({
                type: 'file',
                content: JSON.stringify({ name: r.file.name, size: r.file.size, ext: r.file.ext, path: r.file.path })
            });
        });
    }

    /** 图片进待发区（核心 Ctrl+V 粘贴也调这个，名字保持向后兼容） */
    function uploadImage(file) { addMedia(file, kindOf(file) === 'video' ? 'video' : 'image'); }

    /* ---------- 拖拽 ---------- */

    /**
     * 拖进来的图片/视频先问一句怎么发。
     * 一批里类型不一致时按**第一个**媒体的类型措辞（图片/视频），
     * 因为「以图片方式发送」那张卡片实际发的是全部媒体，混着选会让人以为视频被丢了。
     */
    function askMode(media) {
        var isVideo = media[0].kind === 'video';
        // 标题里不带数量：语言包按**整句**匹配，掺进数字这句就永远翻译不到
        // （数量在待发区里数得出来，不必在这里重复一遍）
        // 标题与说明包在 .ow-pick-txt 里：不包的话它们是两个并列的 flex 子项，
        // 说明会把标题挤成两行（实测「以视频方式 / 发送」这样断行）。
        OwChat.openModal('<h3>怎么发送这些文件？</h3>'
            + '<div class="ow-pick">'
            + '<button type="button" class="ow-pick-card" id="owPickMedia">' + icon(isVideo ? 'video' : 'image', 28)
            + '<span class="ow-pick-txt">'
            + '<span class="ow-pick-name">' + (isVideo ? '以视频方式发送' : '以图片方式发送') + '</span>'
            + '<span class="ow-pick-desc">' + (isVideo ? '直接出现在聊天里可播放，可与文字一起发。' : '直接出现在聊天里，支持图文混排，辅助表达更清晰。') + '</span></span></button>'
            + '<button type="button" class="ow-pick-card" id="owPickFile">' + icon('file', 28)
            + '<span class="ow-pick-txt"><span class="ow-pick-name">以文件方式发送</span>'
            + '<span class="ow-pick-desc">保留格式与文件名，便于识别和管理（不压缩）。</span></span></button>'
            + '</div>'
            + '<div class="ow-modal-actions"><button class="ow-btn ow-btn-ghost" id="owPickCancel">取消</button></div>');
        $('owPickMedia').onclick = function () {
            OwChat.closeModal();
            for (var i = 0; i < media.length; i++) addMedia(media[i].f, media[i].kind);
        };
        $('owPickFile').onclick = function () {
            OwChat.closeModal();
            for (var i = 0; i < media.length; i++) uploadFile(media[i].f);
        };
        $('owPickCancel').onclick = function () { OwChat.closeModal(); };
    }

    /** 拖拽落点：媒体先弹窗，其它类型直接按文件发（没有二选一可言） */
    function handleFiles(list) {
        var media = [], others = [], i, f, k;
        for (i = 0; i < list.length; i++) {
            f = list[i];
            k = kindOf(f);
            if (k === 'file') others.push(f); else media.push({ f: f, kind: k });
        }
        for (i = 0; i < others.length; i++) uploadFile(others[i]);
        if (media.length) askMode(media);
    }

    function bindDrop() {
        var main = d.querySelector('main.ow-main') || $('owMessages');
        if (!main || !main.addEventListener) return;
        var over = 0;
        main.addEventListener('dragenter', function (e) {
            if (e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') < 0) return;
            over++; main.className += ' ow-drop-on';
        }, false);
        main.addEventListener('dragleave', function () {
            over = Math.max(0, over - 1);
            if (!over) main.className = main.className.replace(/\s*ow-drop-on/g, '');
        }, false);
        main.addEventListener('dragover', function (e) {
            if (e.preventDefault) e.preventDefault();
            e.returnValue = false;
        }, false);
        main.addEventListener('drop', function (e) {
            if (e.preventDefault) e.preventDefault();
            e.returnValue = false;
            over = 0;
            main.className = main.className.replace(/\s*ow-drop-on/g, '');
            var fs = e.dataTransfer && e.dataTransfer.files;
            if (fs && fs.length) handleFiles(fs);
        }, false);
    }

    /* ---------- 注入按钮 ---------- */

    function mount() {
        var box = d.getElementById('owAttachTools');
        if (box) {
            box.innerHTML =
                '<input type="file" id="owFileInput" accept="image/*" multiple style="display:none">' +
                '<input type="file" id="owFileAttach" multiple style="display:none">' +
                '<button class="ow-icon-btn" id="owBtnImage" title="发送图片">' + icon('image') + '</button>' +
                '<button class="ow-icon-btn" id="owBtnFile" title="发送文件">' + icon('paperclip') + '</button>';

            var bi = $('owBtnImage'), bf = $('owBtnFile');
            var fi = $('owFileInput'), fa = $('owFileAttach');
            if (bi) bi.onclick = function () { if (fi) fi.click(); };
            if (bf) bf.onclick = function () { if (fa) fa.click(); };
            // 点图片按钮本身就是意图声明 → 直接进待发区，不再弹窗
            if (fi) fi.onchange = function () {
                var i, fs = this.files || [];
                for (i = 0; i < fs.length; i++) addMedia(fs[i], 'image');
                this.value = '';
            };
            if (fa) fa.onchange = function () {
                var i, fs = this.files || [];
                for (i = 0; i < fs.length; i++) uploadFile(fs[i]);
                this.value = '';
            };
        }
        tray();
        bindDrop();
    }

    // 待发区非空时接管「发送」：文字与媒体一起走，而不是先甩一条纯文本
    if (OwChat.onBeforeSend) OwChat.onBeforeSend(function (text) {
        if (!pend.length) return false;
        return sendRich(text);
    });
    // 切会话就丢弃待发区（注册时核心会立刻补发一次当前视图，那时 pend 还是空的，无害）
    if (OwChat.onViewChange) OwChat.onViewChange(function () {
        if (pend.length && pendKey !== nowKey()) clearPend();
    });

    w.OwAttach = {
        mount: mount, uploadFile: uploadFile, uploadImage: uploadImage,
        addMedia: addMedia, pendCount: function () { return pend.length; }
    };

    // 核心在 OwChat.init 之后才引入插件脚本，此时 DOM 已就绪
    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', mount);
    else mount();
})(window, document);
