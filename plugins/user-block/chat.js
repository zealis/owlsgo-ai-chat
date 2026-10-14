/**
 * 屏蔽用户插件 · 前台能力（user-block）
 *
 * 入口：
 *   1. 群聊消息右键「头像」菜单追加「屏蔽此人 / 取消屏蔽」（OwChat.onMsgCtx）
 *   2. 私聊右侧栏追加「屏蔽此人」iOS 样式开关（包装 OwChat.dmPanelHtml）
 *   3. 个人设置追加「已屏蔽用户」列表，每项带开关可取消屏蔽（包装 OwChat.openSettings）
 *   4. 包装 OwChat.addMessage：前端兜底过滤被屏蔽者的消息（处理轮询竞态）
 *
 * 屏蔽语义：单方面、对方无感知。后端通过 message_hides 让 history/poll 自动过滤，
 * 前端 addMessage 再兜一层，确保即时生效。
 *
 * 兼容老浏览器：仅用 var / function，无箭头函数、无 const/let、无 fetch。
 */
(function (w, d) {
    if (!w.OwChat || !OwChat.cfg) return;
    if (w.__haUBLoaded) return;
    w.__haUBLoaded = true;

    var esc = w.esc || function (s) { return String(s == null ? '' : s); };
    var toast = w.toast || function () {};
    var pad = w.fmtUid || function (n) { return String(n || ''); };
    var $ = function (id) { return d.getElementById(id); };

    var UB = w.OwUB = w.OwUB || {};

    /* ---------- 被屏蔽用户集合（客户端缓存，驱动菜单文案与消息过滤） ---------- */
    var blockedSet = {};   // uid => true
    var listLoaded = false;

    function isLogin() {
        var actor = (OwChat.cfg || {}).actor || {};
        var me = (OwChat.cfg || {}).me || null;
        return actor.kind === 'user' && me && me.id;
    }

    function myId() {
        var me = (OwChat.cfg || {}).me || null;
        return me ? (parseInt(me.id, 10) || 0) : 0;
    }

    function isBlocked(uid) {
        uid = parseInt(uid, 10) || 0;
        return uid > 0 && !!blockedSet[uid];
    }

    /**
     * 判断某用户是否为超级管理员。
     * 私聊侧栏没有直接的 peer.role，从 msgCache 里找该用户任意一条消息的 role。
     * 找不到时返回 false（交由后端写入点硬保护兜底）。
     */
    function isUidAdmin(uid) {
        uid = parseInt(uid, 10) || 0;
        if (uid <= 0) return false;
        var cache = OwChat.msgCache || {};
        for (var mid in cache) {
            if (!cache.hasOwnProperty(mid)) continue;
            var mm = cache[mid];
            if (mm && parseInt(mm.uid, 10) === uid && mm.role === 'admin') return true;
        }
        return false;
    }

    /** 拉取我屏蔽的用户列表，构建 blockedSet */
    function loadBlockedSet(cb) {
        OwApi.post('plugin_user_block_list', {}, function (r) {
            blockedSet = {};
            if (r.ok && r.data) {
                for (var i = 0; i < r.data.length; i++) {
                    var uid = parseInt(r.data[i].uid, 10) || 0;
                    if (uid > 0) blockedSet[uid] = true;
                }
            }
            listLoaded = true;
            if (cb) cb(r.ok ? (r.data || []) : []);
        });
    }

    /** 切换屏蔽状态，成功后同步 blockedSet 并回调 */
    UB.toggleBlock = function (uid, cb) {
        uid = parseInt(uid, 10) || 0;
        if (uid <= 0) { if (cb) cb(false); return; }
        OwApi.post('plugin_user_block_toggle', { target_id: uid }, function (r) {
            if (r.ok) {
                if (r.blocked) blockedSet[uid] = true;
                else delete blockedSet[uid];
            }
            toast(r.msg || (r.ok ? '操作成功' : '操作失败'));
            if (cb) cb(!!r.ok, r.blocked, r.msg);
        });
    };

    /**
     * 刷新当前消息区视图：把已渲染的、来自被屏蔽者的消息气泡移除。
     * 后端 message_hides 已兜底历史/轮询，这里只清当前 DOM 里残留的气泡。
     */
    UB.refreshView = function () {
        var box = $('owMessages');
        if (!box) return;
        var els = box.getElementsByClassName('ow-msg');
        // 倒序删除，避免集合动态变化导致漏删
        for (var i = els.length - 1; i >= 0; i--) {
            var el = els[i];
            var mid = parseInt((el.id || '').replace('owMsg', ''), 10);
            if (!mid) continue;
            var m = OwChat.msgCache ? OwChat.msgCache[mid] : null;
            if (!m) continue;
            var uid = parseInt(m.uid, 10) || 0;
            if (uid > 0 && !m.mine && isBlocked(uid)) {
                // 连带移除紧跟其后的时间戳分隔节点
                var after = el.nextSibling;
                if (el.parentNode) el.parentNode.removeChild(el);
                if (after && after.className
                    && ('' + after.className).indexOf('ow-time-divider') >= 0
                    && after.parentNode) {
                    after.parentNode.removeChild(after);
                }
            }
        }
    };

    /* ---------- 1. 右键头像菜单：屏蔽此人 / 取消屏蔽 ---------- */
    if (typeof OwChat.onMsgCtx === 'function') {
        OwChat.onMsgCtx(function (items, m) {
            if (!isLogin()) return;
            if (!m || m.recalled) return;
            var uid = parseInt(m.uid, 10) || 0;
            if (!uid) return;                       // 仅屏蔽注册用户
            if (uid === myId()) return;             // 不能屏蔽自己
            if (m.role === 'admin') return;         // 不能屏蔽超级管理员

            var blocked = isBlocked(uid);
            items.push({
                t: blocked ? '取消屏蔽' : '屏蔽此人',
                run: function () {
                    UB.toggleBlock(uid, function (ok) {
                        if (!ok) return;
                        UB.refreshView();   // 立即把对方已渲染的消息从视图移除
                        // 若在私聊中，同步刷新右侧栏「屏蔽此人」开关状态
                        if (OwChat.dm && parseInt(OwChat.dm.id, 10) === uid) {
                            OwChat.renderRoomPanel();
                        }
                    });
                }
            });
        });
    }

    /* ---------- 4. addMessage 兜底过滤：被屏蔽者的消息不渲染 ---------- */
    if (typeof OwChat.addMessage === 'function') {
        var origAddMessage = OwChat.addMessage;
        OwChat.addMessage = function (m, batch) {
            if (m && !m.mine) {
                var uid = parseInt(m.uid, 10) || 0;
                if (uid > 0 && isBlocked(uid)) return;   // 静默丢弃
            }
            return origAddMessage.call(this, m, batch);
        };
    }

    /* ---------- 2. 私聊右侧栏：屏蔽此人开关 ---------- */
    if (typeof OwChat.dmPanelHtml === 'function') {
        var origDmPanelHtml = OwChat.dmPanelHtml;
        OwChat.dmPanelHtml = function () {
            var html = origDmPanelHtml.call(this);
            if (!isLogin()) return html;
            var d = this.dm || {};
            var uid = parseInt(d.id, 10) || 0;
            if (!uid) return html;

            // 超级管理员不可屏蔽，不显示开关
            if (isUidAdmin(uid)) return html;

            // 开关行：默认未勾选，真实状态在 renderRoomPanel 后异步回填
            // v1.2.52：与核心 switchHtml 同款结构 —— 行容器是 div，for 只挂轨道，
            // 点标签文字/行空白不会误切换
            var row = '<div class="ow-panel-entry-row" style="border-top:1px solid var(--ow-border,#e8e8e8)">'
                + '<div class="ow-switch-row" style="padding:12px 14px">'
                + '<span class="ow-switch-label">屏蔽此人</span>'
                + '<input type="checkbox" class="ow-switch-input" id="owUbDmSwitch">'
                + '<label class="ow-switch" for="owUbDmSwitch"></label>'
                + '</div></div>';
            return html + row;
        };

        // renderRoomPanel 渲染完 DM 面板后，回填开关状态并绑定切换
        if (typeof OwChat.renderRoomPanel === 'function') {
            var origRenderRoomPanel = OwChat.renderRoomPanel;
            OwChat.renderRoomPanel = function () {
                var ret = origRenderRoomPanel.call(this);
                var sw = $('owUbDmSwitch');
                if (sw && isLogin() && this.dm) {
                    var uid = parseInt(this.dm.id, 10) || 0;
                    // 先用本地缓存快速设置
                    sw.checked = isBlocked(uid);
                    var visual = sw.parentNode.querySelector('.ow-switch');
                    if (visual) visual.className = 'ow-switch' + (sw.checked ? ' is-on' : '');
                    // 再向服务端确认一次（防止多端状态漂移）
                    (function (targetUid, switchEl) {
                        OwApi.post('plugin_user_block_check', { target_id: targetUid }, function (r) {
                            // 防竞态：用户可能已切到别的私聊，别把别的开关状态改了
                            if (!switchEl.parentNode) return;
                            if (OwChat.dm && parseInt(OwChat.dm.id, 10) !== targetUid) return;
                            if (!r.ok) return;
                            var on = !!r.blocked;
                            switchEl.checked = on;
                            if (on) blockedSet[targetUid] = true; else delete blockedSet[targetUid];
                            var v = switchEl.parentNode.querySelector('.ow-switch');
                            if (v) v.className = 'ow-switch' + (on ? ' is-on' : '');
                        });
                    })(uid, sw);

                    // 绑定切换
                    if (!sw._ubBound) {
                        sw._ubBound = true;
                        sw.onchange = function () {
                            var on = sw.checked;
                            var v = sw.parentNode.querySelector('.ow-switch');
                            if (v) v.className = 'ow-switch' + (on ? ' is-on' : '');
                            UB.toggleBlock(uid, function (ok, newState) {
                                if (!ok) {
                                    // 回滚
                                    sw.checked = !on;
                                    if (v) v.className = 'ow-switch' + (!on ? ' is-on' : '');
                                    return;
                                }
                                if (newState) blockedSet[uid] = true; else delete blockedSet[uid];
                            });
                        };
                    }
                }
                return ret;
            };
        }
    }

    /* ---------- 3. 个人设置：已屏蔽用户列表 ---------- */
    if (typeof OwChat.openSettings === 'function') {
        var origOpenSettings = OwChat.openSettings;
        OwChat.openSettings = function () {
            origOpenSettings.call(this);
            injectBlockedSection();
        };
    }

    function injectBlockedSection() {
        if (!isLogin()) return;
        var modal = $('owModal');
        if (!modal) return;
        var saveBtn = modal.querySelector('.ow-btn-primary');
        if (!saveBtn) return;
        if (modal.querySelector('#owUbSection')) return;   // 幂等

        var section = d.createElement('div');
        section.id = 'owUbSection';
        section.style.cssText = 'border-top:1px solid var(--ow-border,#e8e8e8);margin-top:16px;padding-top:16px';
        // v1.3.117：屏蔽列表属于「账号」这一页。插在弹窗根节点会让它在每页都露出来，
        // 还要跟固定 640px 的窗口抢高度。老核心没有 [data-pane] 时回落到插在保存按钮后面。
        var host = w.owTabs && owTabs.pane(modal, 'acct');
        if (host) host.appendChild(section);
        else saveBtn.parentNode.insertBefore(section, saveBtn.nextSibling);

        section.innerHTML = '<h3 style="margin:0 0 10px;font-size:15px">已屏蔽用户</h3>'
            + '<div id="owUbList"><div style="color:var(--ow-text-sub);font-size:13px">加载中…</div></div>';

        OwApi.post('plugin_user_block_list', {}, function (r) {
            var list = (r.ok && r.data) ? r.data : [];
            blockedSet = {};
            var html = '';
            if (!list.length) {
                html = '<div style="color:var(--ow-text-sub);font-size:13px;padding:8px 0">暂无屏蔽的用户</div>';
            } else {
                for (var i = 0; i < list.length; i++) {
                    var u = list[i];
                    var uid = parseInt(u.uid, 10) || 0;
                    if (uid > 0) blockedSet[uid] = true;
                    // v1.2.52：与核心 switchHtml 同款结构 —— 行容器 div，for 只挂轨道
                    html += '<div class="ow-switch-row" style="padding:10px 0">'
                        + '<span class="ow-switch-label">' + esc(u.nickname)
                        + ' <span style="color:var(--ow-text-sub);font-size:12px">ID ' + esc(pad(uid)) + '</span></span>'
                        + '<input type="checkbox" class="ow-switch-input" id="owUbItem' + uid + '" checked data-uid="' + uid + '">'
                        + '<label class="ow-switch is-on" for="owUbItem' + uid + '"></label>'
                        + '</div>';
                }
            }
            var box = $('owUbList');
            if (box) {
                box.innerHTML = html;
                bindListSwitches(box);
            }
        });
    }

    function bindListSwitches(scope) {
        if (!scope) return;
        var inputs = scope.querySelectorAll ? scope.querySelectorAll('.ow-switch-input') : [];
        for (var i = 0; i < inputs.length; i++) {
            (function (cb) {
                var sw = cb.parentNode.querySelector('.ow-switch');
                var sync = function () {
                    if (sw) sw.className = 'ow-switch' + (cb.checked ? ' is-on' : '');
                };
                cb.addEventListener ? cb.addEventListener('change', sync, false) : cb.attachEvent('onchange', sync);
                cb.onchange = function () {
                    var uid = parseInt(cb.getAttribute('data-uid'), 10) || 0;
                    var willBlock = cb.checked;
                    sync();
                    // 列表里的开关默认是「已屏蔽=开」，取消勾选 = 取消屏蔽
                    if (!willBlock) {
                        UB.toggleBlock(uid, function (ok, newState) {
                            if (!ok) { cb.checked = true; sync(); return; }
                            // 取消成功：从列表移除该行
                            var row = cb.parentNode;
                            if (row && row.parentNode) row.parentNode.removeChild(row);
                            delete blockedSet[uid];
                        });
                    } else {
                        // 重新屏蔽（理论上列表里都是已屏蔽的，这里兜底）
                        UB.toggleBlock(uid, function (ok, newState) {
                            if (!ok) { cb.checked = false; sync(); return; }
                            if (newState) blockedSet[uid] = true;
                        });
                    }
                };
            })(inputs[i]);
        }
    }

    /* ---------- 启动：拉取屏蔽列表构建缓存 ---------- */
    if (isLogin()) {
        loadBlockedSet();
    }
})(window, document);
