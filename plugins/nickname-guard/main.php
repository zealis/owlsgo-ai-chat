<?php
/**
 * 昵称保留插件（v1.3.1，匹配语义 v1.3.69 起改为「包含」）
 *
 * 唯一能力：昵称保留。管理员在后台维护保留词列表（逗号 / 空格 / 换行分隔，textarea 可增删），
 * 首次显示内置默认保留（admin/root/administrator/system/support/official/staff/
 * moderator/api/www/login/register/account/help/about/null/undefined/guest/test）。
 * 启动插件（插件管理里启用本插件）后生效：注册 / 修改资料 / 安装向导设置昵称时，
 * 昵称里**含有**任一词即拒绝（不区分大小写）。禁用插件则 main.php 不加载、
 * 钩子不注册，自然不生效。
 *
 * v1.3.69 的两处改动及其代价：
 *   - 精确匹配 → 包含匹配：填 admin 能一并挡住 admin001、超级admin，
 *     但短词会误伤 —— api 命中 rapid、capital，test 命中 latest、contest。列表由管理员维护，
 *     页面提示里把这条写明了（ⓘ 气泡）。
 *   - 分隔符从「仅逗号」扩到「逗号 + 任意空白」：可以一行一个词地贴，
 *     代价是词里不能带空格（带空格会被当成两个词）。
 *
 * 钩子：nickname.before_save（见 core/auth.php Auth::checkNickname）
 *   回调签名 function (&$nick, &$err, $ctx)
 *     - $err 设为非空字符串即拦截，$err 即展示给用户的文案；
 *     - $ctx['scene'] 为 register / profile / install。
 *
 * 列表存储：settings 表 plugin_nickname_guard_list（归一化后用英文逗号 + 空格连接的串）。
 * 首次状态：plugin_nickname_guard_init 为 '0' 时，展示与拦截均回退到内置默认列表；
 *           管理员首次保存后置 '1'，之后以保存值为准（可为空 = 不保留任何昵称）。
 */
if (!defined('OWLSGO_VERSION')) exit;

/* ---------- 设置键 ---------- */
function owNGKeyList(): string { return 'plugin_nickname_guard_list'; }  // 逗号分隔原始字符串
function owNGKeyInit(): string { return 'plugin_nickname_guard_init'; }  // '1' 已首次保存 / '0' 未保存

/** 内置默认保留昵称（小写键 => true），首次展示与首次保存前拦截的回退来源 */
function owNGDefaults(): array
{
    static $d = null;
    if ($d !== null) return $d;
    $d = [];
    foreach ([
        'admin','root','administrator','system','support','official','staff','moderator',
        'api','www','login','register','account','help','about','null','undefined','guest','test',
    ] as $n) {
        $d[mb_strtolower($n, 'UTF-8')] = true;
    }
    return $d;
}

/**
 * 把原始输入归一化为保留词数组（保留原大小写，仅用于回显）：
 *   - 分隔符：英文 , 中文 ， 顿号 、，以及任意空白（空格 / 制表 / 换行 / 全角空格 / nbsp）；
 *     所以词本身不能含空格 —— 一行一个词、或逗号分隔，两种写法都行。
 *   - 按小写去重，保留首次出现的大小写形式。
 */
function owNGNormalize(string $raw): array
{
    $out = [];
    $seen = [];
    foreach (preg_split('/[,，、\s\x{A0}\x{3000}]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) as $n) {
        $k = mb_strtolower($n, 'UTF-8');
        if ($k === '' || isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = $n;
    }
    return $out;
}

/**
 * 当前生效的保留词（全小写、已去重），请求内缓存。
 * 未首次保存（init=0）时回退内置默认；已保存则用保存值归一化解析。
 */
function owNGWords(): array
{
    static $words = null;
    if ($words !== null) return $words;
    $names = DB::setting(owNGKeyInit(), '0') === '1'
        ? owNGNormalize((string)DB::setting(owNGKeyList(), ''))
        : array_keys(owNGDefaults());
    // ⚠️ 去重用的 map 只能待在局部变量里：早先写成直接往 $words 上塞键值对、
    //   最后 return array_keys($words) —— 静态缓存留下的是 map，第二次调用起
    //   foreach 拿到的「词」全是 true，mb_strpos 于是拿 "1" 去匹配昵称。
    $seen = [];
    foreach ($names as $n) {
        $w = mb_strtolower($n, 'UTF-8');
        if ($w !== '') $seen[$w] = true;
    }
    $words = array_keys($seen);
    return $words;
}

/**
 * 昵称是否命中保留词：包含即命中，不区分大小写（v1.3.69）。
 * 单列成函数是为了让匹配语义只有一处 —— 钩子与测试都走它。
 */
function owNGHit(string $nick): bool
{
    $key = mb_strtolower(trim($nick), 'UTF-8');
    if ($key === '') return false;
    foreach (owNGWords() as $w) {
        if (mb_strpos($key, $w) !== false) return true;
    }
    return false;
}

/* ---------- 钩子：命中保留词即拒绝（插件启用时才注册） ---------- */
Plugin::on('nickname.before_save', function (string &$nick, ?string &$err, array $ctx): void {
    if (owNGHit($nick)) {
        $err = '该昵称包含系统保留字样，请更换';
        Sec::log('nickname_reserve_block', $nick, ['scene' => $ctx['scene'] ?? '']);
    }
});

/* ---------- 后台页面：保留昵称列表（可增删） ---------- */
Plugin::adminPage('nickname-guard', '昵称保留', function () {
    // 未首次保存时回退内置默认，便于管理员在此基础上增删
    if (DB::setting(owNGKeyInit(), '0') === '1') {
        $list = (string)DB::setting(owNGKeyList(), '');
    } else {
        $list = implode(', ', array_keys(owNGDefaults()));
    }
    return '<h2>昵称保留</h2>'
        . '<p class="ow-admin-desc">启用本插件后，昵称中含有下列任一词即被拒绝（注册与修改昵称同一口径）。词越短越容易误伤，细则见 ⓘ。</p>'
        . '<div class="ow-card">'
        . '<div class="ow-form-item"><label>保留词列表</label>'
        . '<textarea class="ow-input" id="owNGList" rows="8" style="width:100%;resize:vertical;font-family:var(--ow-mono,Consolas,monospace)" placeholder="例如：管理员, 客服, admin, root">' . Sec::e($list) . '</textarea>'
        . '<p style="font-size:12px;color:var(--ow-text-sub,#999);margin-top:4px">'
        . '昵称中包含其中任意一个词即禁止使用，不区分大小写。多个词用逗号、空格或换行分隔。'
        . '例如填写 admin 时，admin、admin001、超级admin 都会被拒绝。'
        . '代价是短词会误伤：填 test 会连 latest、contest 一起拒掉，填 api 会连 rapid、capital 一起拒掉 —— 尽量用较长的词。'
        . '词本身不能含空格（空格算分隔符）。保存时自动去空与去重、统一为英文逗号；清空并保存 = 不保留任何词。'
        . '</p></div>'
        . '<div class="ow-form-row" style="margin-top:10px"><button class="ow-btn ow-btn-primary" onclick="OwNG.save()">保存设置</button></div>'
        . '<div id="owNGMsg" style="margin-top:10px"></div>'
        . '</div>';
});

/* ---------- 保存接口：仅列表（仅管理员） ---------- */
$ngGuard = function (array $ctx): void {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
};

Plugin::route('plugin_nickname_guard_save', function (array $ctx) use ($ngGuard) {
    $ngGuard($ctx);
    $raw = (string)($ctx['post']['list'] ?? '');
    // 归一化：逗号（中英文）、顿号与任意空白都算分隔符，去重，统一存为英文逗号 + 空格
    $store = implode(', ', owNGNormalize($raw));
    if (strlen($store) > 2000) Api::json(['ok' => false, 'msg' => '保留词列表过长（上限 2000 字符）']);
    DB::setSetting(owNGKeyList(), $store);
    DB::setSetting(owNGKeyInit(), '1');
    Sec::log('nickname_reserve_save', $ctx['actor']['nickname']);
    Api::json(['ok' => true, 'msg' => '已保存']);
});

// 注册后台交互脚本（由 ?action=assets&type=js 合并输出，仅后台页面引入）
Plugin::asset('js', 'nickname-guard/admin.js', 'admin');
