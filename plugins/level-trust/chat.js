/**
 * 等级信任 —— 前台（资料卡等级行）
 *
 * 显示位置：**用户资料卡 .ow-card-meta 的最上面一行**。
 * 由核心 v1.2.42 的扩展点 `OwChat.onCardMetaTop(fn, prio)` 保证 ——
 * 核心在渲染 meta 时把扩展点的输出**前置**到「积分 / 注册」之前，
 * 而不是给插件一个空容器让插件自己 append（那样谁先注册谁在上面，且不抗漂移）。
 * prio=1 保证等级行在其它插件的顶部行之上。
 *
 * 数据：等级随核心 user_card 接口一起下发（服务端 user.card 钩子写入 u.level），
 * 前端**不再单独打一次接口** —— 弹窗打开时数据已经在手，不会出现「先空白再填充」。
 *
 * 游客：核心的 user_card 只查 users 表，游客 id 直接返回「用户不存在」，
 * 走不到这里；即便走到，u.level 为空也返回空串（不渲染这一行）。
 */
(function (w, d) {
    'use strict';
    // 幂等：合并资源包理论上只应加载一次，但历史上出现过被加载两遍的情况
    //（插件在 page.footer 里重复注入），届时扩展点会注册两次 → 资料卡出现两行等级。
    if (w.__haLevelLoaded) return;
    if (!w.OwChat || typeof OwChat.onCardMetaTop !== 'function') return;
    w.__haLevelLoaded = true;

    /** 任务 key → 中文（v2.0 起任务只剩登录与群发言；旧键已随替代/挑战任务移除） */
    var LABEL = {
        login: '登录', gm: '群发言'
    };

    /** 等级徽章 HTML */
    function badge(lv, stageNo, honor) {
        var cls = 'ow-lt-badge ow-lt-s' + (stageNo || 1) + (honor ? ' ow-lt-honor' : '');
        return '<span class="' + cls + '">Lv.' + lv + '</span>';
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // v1.2.44：整行可点击 → 跳到前台等级页 ?page=level（规则 / 今日任务 / 解锁对比）。
    //   用 <a> 而不是 onclick：右键「新标签打开」和 Ctrl+点击才可用。
    // v1.2.45：去掉了行尾的「详情 ›」文字（用户要求）—— 整行已经可点，
    //   再放指引文字属于冗余，且会把 meta 行撑宽。可点性用 hover 背景 + 手型光标表达。
    OwChat.onCardMetaTop(function (u) {
        if (!u || !u.level) return '';      // 无等级（游客 / 数据缺失）→ 不渲染整行
        var title = u.level_honor ? '荣誉等级' : '等级';
        // v1.2.46：**不显示阶段名**（用户要求）。阶段信息在 ?page=level 页面里有完整说明，
        //   资料卡只留等级数字 —— 一行塞三个信息（数字 + 阶段 + 徽章）反而挤。
        return '<a class="ow-card-meta-row ow-lt-row ow-lt-row-link" href="?page=level"'
            + ' title="查看等级规则与今日任务">'
            + '<span class="ow-card-meta-k">' + title + '</span>'
            + '<span class="ow-card-meta-v">'
            + badge(u.level, u.level_stage_no, u.level_honor)
            + '</span></a>';
    }, 1);

    /* ---------- 侧栏底部资料区（.ow-me-click）：昵称右侧显示等级 ---------- */
    /**
     * 在昵称右边插入等级徽章（**只有等级数字，不显示阶段名**）。
     *
     * 为什么要包一层 renderMe：核心每次渲染资料区都是整块 innerHTML 覆盖，
     * 我们插进去的节点会被抹掉。包一层在渲染后补插，才能保证
     * 「改完昵称/头像回来等级还在」。
     */
    function paintMeLevel() {
        var box = d.getElementById('owMe');
        if (!box || box.className.indexOf('ow-me-click') < 0) return;   // 游客不显示
        if (box.getElementsByClassName('ow-lt-badge').length) return;    // 已有就别重复插
        var line = box.querySelector('.ow-me-line');
        var name = line ? line.querySelector('.ow-me-name') : null;
        if (!name) return;
        if (!w.__haLevelMy || !w.__haLevelMy.level) return;              // 数据还没回来
        var lv = w.__haLevelMy;
        // badge() 返回的是 **HTML 字符串**，要先落成节点才能加类名
        //（直接 b.className = ... 会在字符串上赋值，报「Cannot create property on string」）
        var box2 = d.createElement('span');
        box2.innerHTML = badge(lv.level, lv.stage_no, lv.honor);
        var b = box2.firstChild;
        if (!b) return;
        b.className += ' ow-lt-badge-sm';
        b.setAttribute('title', '等级 Lv.' + lv.level);
        // 插在昵称**后面**、头衔标签**前面**：头衔是用户自己设的，优先级高于等级
        if (name.nextSibling) line.insertBefore(b, name.nextSibling);
        else line.appendChild(b);
    }

    w.OwLevel = {
        LABEL: LABEL,
        badge: badge,
        /** 查「我的」等级与今日任务进度 */
        mine: function (cb) {
            OwApi.post('plugin_level_trust_mine', {}, function (r) {
                if (typeof cb === 'function') cb(r);
            });
        },
        /** 侧栏资料区补等级（供 OwTip 之类重渲染后手动调用） */
        paintMe: paintMeLevel
    };

    /* ---------- 侧栏等级：拉数据 + 包 renderMe ---------- */
    // 游客没有等级，不发这个请求
    if (w.OwChat && OwChat.cfg && OwChat.cfg.me && OwChat.cfg.me.id) {
        OwApi.post('plugin_level_trust_mine', {}, function (r) {
            if (!r || !r.ok || !r.level) return;
            w.__haLevelMy = { level: r.level, stage_no: r.stage_no, honor: r.honor };
            paintMeLevel();
        });
        var origRenderMe = OwChat.renderMe;
        OwChat.renderMe = function () {
            origRenderMe.apply(OwChat, arguments);
            paintMeLevel();
        };
    }
})(window, document);
