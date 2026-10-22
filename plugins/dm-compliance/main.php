<?php
/**
 * 私聊合规查阅插件（v1.1.0）
 *
 * 解决的核心矛盾：
 *   核心 v1.1.0 定的硬约束是「私聊仅双方可见，管理员亦不例外」，
 *   但部分司法辖区要求数据控制者在有合法权限时能提供记录。
 *   两者不冲突的正确做法是：**默认不允许，显式例外 + 强制留痕**。
 *
 * 本插件做什么：
 *   1. 注册 `dm.read` 钩子放行 Chat::dmComplianceRead() 的查阅请求。
 *      插件停用 → 钩子不存在 → 核心直接拒绝，物理上无法查阅。
 *   2. 提供后台页面「私聊合规查阅」：按用户ID + 时间范围检索该用户参与的所有私聊。
 *   3. 每次查阅都写安全日志（核心强制，不可关闭）。
 *
 * 权限设计（纵深防御，核心与插件各校验一次）：
 *   - 核心 Chat::dmComplianceRead()：要求 role === 'admin'，否则直接拒绝。
 *   - 插件路由 plugin_dm_compliance_read：额外要求 kind === 'user' 且是敏感操作（CSRF 签名）。
 *   - 页面访问：仅在后台菜单显示，路由本身校验管理员身份。
 *
 * 法规背景备注：GDPR 第 6(1)(f) 合法利益 / 第 23 条权利保障，
 * 中国《个人信息保护法》第 35 条（国家机关依法查询须严格授权审批），
 * 均要求「有合法权限时可调取」且「调取行为本身需可审计」。
 */

if (!defined('OWLSGO_VERSION')) exit('Access denied');

// ---------- 1. 钩子：放行核心的合规查阅 ----------
// 核心在 dmComplianceRead() 里 fire('dm.read', [&$allow, &$reason, ...])，
// 只有这里把 $allow 置 true 才继续。任何插件都能注册同名钩子，
// 所以核心侧仍会独立校验 role==='admin'，不依赖本插件的判断。
Plugin::on('dm.read', function (&$allow, &$reason) {
    $allow = true;
    $reason = '';
});

// ---------- 2. 后台页面 ----------
Plugin::adminPage('dm-compliance', '私聊合规查阅', function () {
    echo '<h2>私聊合规查阅</h2>'
       . '<p class="ow-admin-desc">依据《个人信息保护法》第 35 条、GDPR 合法利益例外等法规，'
       . '在取得法定权限（司法协助 / 依法调取）时，可由超级管理员查阅指定用户参与的私聊记录。'
       . '<b>本操作全程留痕且不可关闭</b>：查询条件与命中条数会写入安全日志，作为调取行为的举证材料。</p>'
       . '<div class="ow-card" style="border-left:3px solid var(--ow-red)">'
       . '<p style="margin:0 0 8px;color:var(--ow-red)"><b>使用前请确认：</b>仅在具有法定权限时使用。'
       . '无权限调取个人信息可能构成违法。</p></div>'
       . '<div class="ow-card">'
       . '<div class="ow-form-row">'
       . '<div class="ow-form-item"><label>目标用户 ID</label>'
       . '<input class="ow-input" id="owDmTarget" type="number" min="1" placeholder="必填，纯数字用户 ID"></div>'
       . '<div class="ow-form-item"><label>起始日期</label>'
       . '<input class="ow-input" id="owDmFrom" type="date"></div>'
       . '<div class="ow-form-item"><label>结束日期</label>'
       . '<input class="ow-input" id="owDmTo" type="date"></div>'
       . '<div class="ow-form-item"><label>最多返回条数</label>'
       . '<input class="ow-input" id="owDmLimit" type="number" min="1" max="1000" value="200"></div>'
       . '</div>'
       // 两个按钮相邻但 inline-block 之间不留空隙会贴在一起，加 margin 隔开
       . '<button class="ow-btn ow-btn-primary" style="margin-right:8px" onclick="OwAdmin.dmComplianceSearch()">查阅</button>'
       . '<button class="ow-btn ow-btn-ghost" onclick="OwAdmin.dmComplianceReset()">重置</button>'
       . '<p style="font-size:12px;color:var(--ow-text-sub);margin:10px 0 0">'
       . '日期留空表示不限。该用户参与的所有私聊都会返回（含其与游客的对话），按时间正序排列。</p>'
       . '</div>'
       . '<div id="owDmResult"></div>';
});

// ---------- 3. 路由：查阅 ----------
Plugin::route('plugin_dm_compliance_read', function (array $ctx) {
    $actor = $ctx['actor'];
    $post  = $ctx['post'];

    // 纵深防御：核心已校验 role==='admin'，这里再卡一次登录态
    if (($actor['kind'] ?? '') !== 'user') {
        Api::json(['ok' => false, 'msg' => '需要登录后操作'], 403);
    }
    if (($actor['role'] ?? '') !== 'admin') {
        Api::json(['ok' => false, 'msg' => '仅超级管理员可查阅私聊记录'], 403);
    }

    $uid   = (int)($post['uid'] ?? 0);
    $from  = trim((string)($post['from'] ?? ''));
    $to    = trim((string)($post['to'] ?? ''));
    $limit = (int)($post['limit'] ?? 200);

    $res = Chat::dmComplianceRead($actor, $uid, $from, $to, $limit);
    Api::json($res);
});
// 查阅私聊是敏感操作：走 CSRF 签名校验（v1.0.91 起）
Plugin::sensitive('plugin_dm_compliance_read');

// ---------- 4. 资源 ----------
Plugin::asset('js', 'dm-compliance/admin.js', 'admin');
