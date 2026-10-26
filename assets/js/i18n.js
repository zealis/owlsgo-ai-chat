/**
 * Owlsgo i18n 引擎（v1.3.44）——「语言包在插件、引擎在核心」的字典式国际化。
 *
 * 参考 ChatNet 的语言包机制（lang/en_*.php = [原文→译文] 精确映射 + cookie 选语言），
 * 但落地为纯前端引擎，原因：owlsgo 的界面文字散布在 chat.js、PHP 模板与 API msg 三处，
 * 改造成 t('key') 要动几百处且插件无法覆盖核心；以**中文原文为 key** 的精确匹配
 * 替换让语言包插件可以独立增补词条、核心零改造。
 *
 * 只翻译系统文字的保证（用户消息不受影响）：
 *   ① 文本节点**整段精确匹配**词典才替换——消息内容/昵称/群名是自由文本，
 *      不可能与「加入群聊」这类词条全等；
 *   ② 消息流 #owMessages、会话列表 #owRoomList、成员列表 #owOnlineList 整体跳过，
 *      仅放行其中的身份角标 .ow-tag（群主/会员/管理员）——那是系统标签不是用户内容；
 *   ③ script/style/textarea/pre/code 等标签的**文字**跳过（输入框内容、嵌入代码），
 *      但它们的 placeholder/title 属性照常翻译。
 *
 * 语言判定：PHP 在 <body data-lang="zh|en"> 输出（cookie ow_lang 优先，
 * 其次后台「系统设置 → 语言 → 默认语言」）。zh 或无语言包时完全不介入。
 *
 * 插件接口：
 *   OwI18n.register('en', 'English', { '登录': 'Sign in', ... })  —— 注册语言包
 *   OwI18n.t('登录')      —— 查当前词典（核心 toast 等动态文案用）
 *   OwI18n.list()         —— [{code,label},...]（设置页默认语言下拉用）
 */
(function (w, d) {
    'use strict';
    var packs = [];
    var dict = null;
    var current = '';
    var mo = null, moTimer = 0;

    // 文本节点与属性走两套黑名单：
    //   textarea/pre/code 里的**文字**是用户内容（消息输入框、嵌入代码），但它们的
    //   placeholder/title 是系统文案 —— 若共用一套，输入框占位符永远翻不了。
    var SKIP_TAGS_TEXT = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, PRE: 1, CODE: 1, KBD: 1, SAMP: 1, NOSCRIPT: 1, TEMPLATE: 1 };
    var SKIP_TAGS_ATTR = { SCRIPT: 1, STYLE: 1, NOSCRIPT: 1, TEMPLATE: 1 };
    var SKIP_SEL = '#owMessages,#owRoomList,#owOnlineList';   // 用户内容密集区：消息流/会话列表/成员列表
    // 用户内容区里的系统文字白名单：① 消息行身份角标（群主/会员/管理员）；
    // ② 系统通知流的正文与署名（v1.3.52 —— 通知正文由服务端从固定文案表生成，
    //    永远不是用户输入，所以可以和角标同等放行）。
    // 放行按**父元素 class** 判定，消息正文 .ow-msg-content 不在名单内，
    // 所以「会员」作为消息内容发出去也不会被改。
    var RESCUE_SEL = '.ow-tag, .ow-msg-notice .ow-msg-content, .ow-notice-nick';
    var ATTRS = ['placeholder', 'title', 'aria-label'];

    function trim(s) { return String(s == null ? '' : s).replace(/^\s+|\s+$/g, ''); }
    function lookup(text) {
        if (!dict) return null;
        var v = Object.prototype.hasOwnProperty.call(dict, text) ? dict[text] : null;
        return (typeof v === 'string' && v !== '') ? v : null;
    }

    /** 结构标签 / data-no-translate：整棵子树都不该动（脚本、代码块、用户输入框） */
    function inStructSkip(node) {
        for (var p = node; p && p !== d; p = p.parentNode) {
            if (p.nodeType !== 1) continue;
            if (SKIP_TAGS_TEXT[p.tagName]) return true;
            try { if (p.getAttribute && p.getAttribute('data-no-translate') !== null) return true; } catch (e) {}
        }
        return false;
    }
    function inUserZone(el) {
        try { return !!(el && el.closest && el.closest(SKIP_SEL)); } catch (e) { return false; }
    }
    function hasRescue(root) {
        try {
            if (root.matches && root.matches(RESCUE_SEL)) return true;
            return !!(root.querySelector && root.querySelector(RESCUE_SEL));
        } catch (e) { return false; }
    }

    /** 节点是否在跳过范围：祖先含黑名单标签/用户内容区/data-no-translate。mode=1 查属性 */
    function inSkip(node, mode) {
        var tags = mode ? SKIP_TAGS_ATTR : SKIP_TAGS_TEXT;
        for (var p = node; p && p !== d; p = p.parentNode) {
            if (p.nodeType !== 1) continue;
            if (tags[p.tagName]) return true;
            try {
                if (p.getAttribute && p.getAttribute('data-no-translate') !== null) return true;
                if (p.matches && p.matches(SKIP_SEL)) {
                    var par = node.parentNode;
                    if (mode || !par || !par.matches || !par.matches(RESCUE_SEL)) return true;
                }
            } catch (e) {}
        }
        return false;
    }

    /** 替换一棵子树：文本节点整段精确匹配 + 白名单属性精确匹配 */
    function walk(root) {
        if (!dict || root.nodeType !== 1 && root.nodeType !== 9 && root.nodeType !== 11) return;
        if (root.nodeType === 1 && inStructSkip(root)) return;
        // 用户内容区里新增的整棵子树：只有身份角标要翻，其余直接不遍历。
        // ⚠️ 这里不能提前 return「因为在用户区内」——消息行里就带着 .ow-tag 角标，
        //    提前 return 会让 Observer 补翻永远漏掉它们（首屏 apply 从 documentElement 走才侥幸覆盖）。
        if (root.nodeType === 1 && inUserZone(root) && !hasRescue(root)) return;
        var tn = root.nodeType === 1 ? null : root;
        // TreeWalker 收齐再改（边遍历边改 textContent 在旧浏览器会乱序）
        var texts = [], n, walker;
        if (d.createTreeWalker) {
            walker = d.createTreeWalker(root, 4 /* SHOW_TEXT */, null, false);
            while ((n = walker.nextNode())) { if (!inSkip(n)) texts.push(n); }
        }
        var i, t2, v;
        for (i = 0; i < texts.length; i++) {
            t2 = texts[i];
            v = lookup(trim(t2.nodeValue));
            if (v !== null) t2.nodeValue = v;
        }
        if (root.nodeType === 1) {
            var els = root.querySelectorAll('[' + ATTRS.join('],[') + ']'), k, j, el, a, av;
            for (k = 0; k < els.length; k++) {
                el = els[k];
                if (inSkip(el, 1)) continue;
                for (j = 0; j < ATTRS.length; j++) {
                    a = el.getAttribute(ATTRS[j]);
                    if (a === null) continue;
                    av = lookup(trim(a));
                    if (av !== null) el.setAttribute(ATTRS[j], av);
                }
            }
        }
        void tn;
    }

    /** 标题「群聊 - 站点名」：只译站点名前那段，站点名与后缀保持原样 */
    function translateTitle() {
        if (!dict || !d.title) return;
        var parts = String(d.title).split(' - ');
        if (parts.length < 2) return;
        var v = lookup(trim(parts[0]));
        if (v !== null) { parts[0] = v; d.title = parts.join(' - '); }
    }

    function apply() {
        if (!dict) return;
        walk(d.documentElement);
        translateTitle();
        if (current) renderSwitcher();
    }

    /** 动态渲染跟随：聊天页大量 innerHTML，MutationObserver 节流补翻新增节点 */
    function watch() {
        if (mo || !w.MutationObserver || !d.body) return;
        mo = new MutationObserver(function (muts) {
            if (!dict) return;
            var i, j, an;
            for (i = 0; i < muts.length; i++) {
                an = muts[i].addedNodes;
                for (j = 0; j < an.length; j++) { if (an[j].nodeType === 1) walk(an[j]); }
            }
        });
        mo.observe(d.body, { childList: true, subtree: true });
    }

    function setLang(code) {
        current = code;
        dict = null;
        for (var i = 0; i < packs.length; i++) { if (packs[i].code === code) { dict = packs[i].dict; break; } }
        if (dict) { apply(); watch(); }
        else if (mo) { mo.disconnect(); mo = null; }
    }

    /* ---------- 登录页右上角语言切换器 ---------- */
    var GLOBE_SVG = '<svg focusable="false" aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="currentColor"><path d="M3.385 5.997a6.067 6.067 0 0 1 4.576-.467h.001c1.232.362 1.906 1.11 2.237 1.94c.3.754.3 1.536.3 1.99v5.79a.75.75 0 0 1-1.5 0v-.3a9.34 9.34 0 0 1-.396.222c-.952.505-2.327 1.018-3.757.761c-1.584-.285-2.576-1.595-2.8-2.952c-.229-1.38.316-2.937 1.902-3.697c1.44-.69 2.99-.628 4.122-.427c.342.061.653.136.923.211c-.013-.351-.055-.71-.188-1.042c-.166-.417-.491-.83-1.264-1.056a4.57 4.57 0 0 0-3.431.34a.75.75 0 0 1-.725-1.313m4.423 4.337c-.986-.175-2.175-.194-3.212.303c-.891.427-1.208 1.27-1.07 2.1c.141.854.741 1.568 1.586 1.72c.938.169 1.947-.164 2.788-.61a8.296 8.296 0 0 0 1.098-.702v-2.507a8.41 8.41 0 0 0-1.19-.304m8.677-3.296a.75.75 0 0 1 .476.948c-.09.27-.185.61-.271.94c.948-.088 1.87-.226 2.638-.406a.75.75 0 1 1 .343 1.46c-.973.229-2.152.392-3.336.481c-.11.546-.194 1.045-.257 1.5a5.939 5.939 0 0 1 1.672-.238a.75.75 0 0 1 1.5.027l-.003.222a4.035 4.035 0 0 1 2.504 2.423a3.98 3.98 0 0 1-.154 3.128c-.496 1.025-1.44 1.894-2.827 2.427a.75.75 0 0 1-.538-1.4c1.083-.416 1.708-1.046 2.015-1.68a2.48 2.48 0 0 0 .097-1.954a2.548 2.548 0 0 0-1.278-1.399a7.63 7.63 0 0 1-2.285 4.047c.057.132.114.272.17.424a.75.75 0 1 1-1.423.478a4.67 4.67 0 0 1-1.24.474c-.72.155-1.557.099-2.13-.486c-.865-.886-.818-2.277-.204-3.442c.477-.908 1.323-1.77 2.538-2.413c.06-.614.157-1.298.3-2.064c-.654.014-1.28.002-1.844-.037a.75.75 0 0 1 .102-1.496c.622.042 1.331.05 2.067.024c.116-.472.272-1.067.42-1.512a.75.75 0 0 1 .948-.476m-2.058 7.372c-.546.413-.92.87-1.146 1.3c-.444.843-.289 1.45-.05 1.695c.055.057.258.172.742.068c.345-.074.672-.202.977-.373a7.84 7.84 0 0 1-.386-1.373a9.112 9.112 0 0 1-.137-1.317m1.755 1.66a6.647 6.647 0 0 0 1.413-2.848a4.725 4.725 0 0 0-1.328.25a7.56 7.56 0 0 0-.34.12a8.618 8.618 0 0 0 .113 1.867c.041.227.089.428.142.611"></path></svg>';

    function renderSwitcher() {
        // 切换器挂在**登录卡片**右上角（不是页面右上角）：只认 .ow-auth-card，
        // 没有卡片就不是登录/注册/找回页，直接不渲染。
        var card = d.querySelector('.ow-auth-body .ow-auth-card');
        if (!card) return;
        var box = d.getElementById('owLangBox');
        if (!box) {
            box = d.createElement('div');
            box.id = 'owLangBox';
            box.className = 'ow-lang-box';
            card.appendChild(box);
        } else if (box.parentNode !== card) {
            card.appendChild(box);   // 首屏早于卡片渲染时（理论上不会）补挪一次
        }
        var opts = [{ code: 'zh', label: '简体中文' }], i;
        for (i = 0; i < packs.length; i++) opts.push({ code: packs[i].code, label: packs[i].label });
        if (box.getAttribute('data-built') === opts.length + ':' + (current || '')) return;
        box.setAttribute('data-built', opts.length + ':' + (current || ''));
        var btn = d.createElement('button');
        btn.type = 'button';
        btn.className = 'ow-lang-btn';
        btn.setAttribute('aria-label', 'Language');
        btn.innerHTML = GLOBE_SVG + '<span class="ow-lang-caret">\u25be</span>';
        var menu = d.createElement('div');
        menu.className = 'ow-lang-menu';
        for (i = 0; i < opts.length; i++) {
            (function (o) {
                var it = d.createElement('div');
                var on = (o.code === (current || 'zh'));
                it.className = 'ow-lang-item' + (on ? ' ow-lang-on' : '');
                it.textContent = o.label;
                it.onclick = function (e) {
                    e && e.stopPropagation && e.stopPropagation();
                    if (on) { menu.style.display = 'none'; return; }
                    try { d.cookie = 'ow_lang=' + encodeURIComponent(o.code) + ';path=/;max-age=8640000;SameSite=Lax'; } catch (err) {}
                    w.location.reload();   // 刷新让 PHP 按新 cookie 输出 data-lang 与服务端文案
                };
                menu.appendChild(it);
            })(opts[i]);
        }
        btn.onclick = function (e) {
            e && e.stopPropagation && e.stopPropagation();
            // ⚠️ 判据只能是「已经开着」：菜单初始隐藏来自 CSS 类，内联 display 是空串，
            //    写成 === 'none' 会让第一次点击把 display 设成 none —— 表现为点一下没反应。
            menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
        };
        d.addEventListener('click', function () { menu.style.display = 'none'; });
        box.innerHTML = '';
        box.appendChild(btn);
        box.appendChild(menu);
    }

    w.OwI18n = {
        register: function (code, label, dictionary) {
            code = trim(code);
            if (!code || code === 'zh' || !dictionary) return;
            for (var i = 0; i < packs.length; i++) { if (packs[i].code === code) { packs[i].dict = dictionary; packs[i].label = label || packs[i].label; return; } }
            packs.push({ code: code, label: label || code, dict: dictionary });
            if (code === current) setLang(code);   // 语言包晚于 body data-lang 加载时补生效
            renderSwitcher();
        },
        list: function () {
            var out = [];
            for (var i = 0; i < packs.length; i++) out.push({ code: packs[i].code, label: packs[i].label });
            return out;
        },
        t: function (s, vars) {
            var v = lookup(trim(s));
            if (v === null) v = s;
            if (vars) {
                v = String(v).replace(/\{([a-z_]+)\}/gi, function (m, key) {
                    return (vars[key] === undefined || vars[key] === null) ? '' : String(vars[key]);
                });
            }
            return v;
        },
        lang: function () { return current || 'zh'; },
        apply: apply
    };

    current = trim((d.body && d.body.getAttribute('data-lang')) || '');
    if (current && current !== 'zh') setLang(current);
    else renderSwitcher();
    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', function () { if (current && current !== 'zh') { apply(); watch(); } renderSwitcher(); });
})(window, document);
