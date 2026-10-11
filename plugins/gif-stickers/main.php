<?php
/**
 * 表情包插件（v1.3.75）
 *
 * 输入框工具栏**首位**一个图标，点开是「上方搜索、下方 GIF 结果」的面板；
 * 点结果即发一条图片消息，也可以把它收藏成自己的贴纸（站内贴纸表本来就只存 URL）。
 *
 * 两个内容源，后台单选，**不混排**：
 *   - klipy  KLIPY GIF API。他们的《Integration Requirements》明文规定：请求必须由
 *     终端用户客户端发起、媒体必须直接从返回的 URL 加载、不得经服务器/代理转发或缓存、
 *     结果不得重排删减（过滤只能用他们文档里的参数）。所以这一路是浏览器直连
 *     api.klipy.com —— app_key 因此对访客可见，这是这类客户端 SDK 的常规做法。
 *   - apihz  API 盒子「表情包」接口（线路二：普通集群负载-域名接口）。
 *     id/key 是长期密钥，放进浏览器等于公开给所有访客，所以这一路走**服务端代理**，
 *     前端只拿到结果数组，密钥绝不出现在响应里。
 *
 * KLIPY 署名要求里唯一标 REQUIRED 的一条是「搜索框 placeholder 必须是 Search KLIPY」，
 * 所以该源下这句**不进语言包**（见 chat.js 里的 OW_GIF_KLIPY_PLACEHOLDER）；
 * 水印与 Powered by 两项文档写的是 OPTIONAL，做成后台开关，默认关。
 *
 * 资源：样式表与脚本走 Plugin::asset 登记，由核心的 ?action=assets 合并输出
 * 路由：plugin_gif_stickers_conf     前端首次打开面板时取的公共配置
 *       plugin_gif_stickers_save    管理员保存配置（敏感，需一次性票据）
 *       plugin_gif_stickers_search  apihz 服务端代理搜索
 */
declare(strict_types=1);

DB::run("CREATE TABLE IF NOT EXISTS plugin_gif_stickers_config (k VARCHAR(64) PRIMARY KEY, v TEXT)");

/** 配置默认值。键名即配置表里的 k，也是保存接口的字段名。 */
function owGSConf(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [
        'provider' => 'klipy',
        // KLIPY：客户端直连。内容过滤默认取最严一档：站点是开源分发的，
        // 默认值会被各种环境的访客直接吃到，宽松那档必须由管理员显式选。
        'klipy_app_key' => '', 'klipy_content_filter' => 'high', 'klipy_locale' => 'us',
        'klipy_per_page' => '24', 'klipy_ads' => '1', 'klipy_powered' => '0',
        // API 盒子：服务端代理
        'apihz_line' => 'cn', 'apihz_id' => '', 'apihz_key' => '', 'apihz_limit' => '20',
    ];
    try {
        foreach (DB::all('SELECT k, v FROM plugin_gif_stickers_config') as $r) {
            $k = (string)$r['k'];
            if (array_key_exists($k, $cache)) $cache[$k] = (string)$r['v'];
        }
    } catch (Throwable $e) { /* 表还没建好时用默认值 */ }
    return $cache;
}

/** 某个源是否配置齐了（缺密钥的源不该在后台被选中后还能搜出报错） */
function owGSReady(string $provider, array $c): bool
{
    if ($provider === 'klipy') return trim((string)($c['klipy_app_key'] ?? '')) !== '';
    if ($provider === 'apihz') return trim((string)($c['apihz_id'] ?? '')) !== ''
        && trim((string)($c['apihz_key'] ?? '')) !== '';
    return false;
}

/**
 * customer_id：KLIPY 要求「同一用户在你的系统里保持稳定」，广告与个性化都挂在这个值上。
 * 用站内身份键（用户=ID / 游客=游客ID），不发昵称邮箱 —— 那是可变的、也是隐私。
 */
function owGSCustomerId(array $actor): string
{
    $kind = (string)($actor['kind'] ?? 'none');
    if ($kind !== 'user' && $kind !== 'guest') return '';
    return 'owlsgo-' . $kind . '-' . (int)($actor['id'] ?? 0);
}

/**
 * 前端可以知道的配置。**apihz 的 id/key 不在这里** —— 那一源全部走服务端代理。
 * KLIPY 的 app_key 必须下发：按他们的条款，请求要由浏览器直接发起。
 */
function owGSPublic(array $actor): array
{
    $c = owGSConf();
    $p = (string)$c['provider'];
    if (!owGSReady($p, $c)) return [];
    return [
        'provider' => $p,
        'customer_id' => owGSCustomerId($actor),
        'klipy' => [
            'key' => (string)$c['klipy_app_key'],
            'content_filter' => (string)$c['klipy_content_filter'],
            'locale' => (string)$c['klipy_locale'],
            'per_page' => max(8, min(50, (int)$c['klipy_per_page'])),
            'ads' => (string)$c['klipy_ads'] === '1',
            'powered' => (string)$c['klipy_powered'] === '1',
        ],
        'apihz' => ['limit' => max(1, min(20, (int)$c['apihz_limit']))],
    ];
}

/** apihz 线路白名单：主机名只能从这张表里取，任何情况下不接受外部传入的地址（SSRF） */
function owGSApihzHost(string $line): string
{
    return $line === 'vip' ? 'https://vip.apihz.cn' : 'https://cn.apihz.cn';
}

/** 服务端 GET（只用于 apihz）。证书必须验：拿明文密钥去敲别人的接口，中间人能直接收走。 */
function owGSGet(string $url, int $timeout = 8): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if (!$ch) return ['ok' => false, 'body' => '', 'error' => '无法初始化请求'];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 2,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'owlsgo-gif-stickers/1.0',
        ]);
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
        }
        $body = curl_exec($ch);
        $err = $body === false ? curl_error($ch) : '';
        curl_close($ch);
        return $body === false ? ['ok' => false, 'body' => '', 'error' => $err !== '' ? $err : '请求失败']
            : ['ok' => true, 'body' => (string)$body, 'error' => ''];
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'timeout' => $timeout, 'follow_location' => 1,
        'max_redirects' => 2, 'ignore_errors' => true, 'user_agent' => 'owlsgo-gif-stickers/1.0',
    ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false
        ? ['ok' => false, 'body' => '', 'error' => '请求失败']
        : ['ok' => true, 'body' => $body, 'error' => ''];
}

/* ---------- 前端配置：由前端首次打开面板时取 ---------- */

/**
 * 配置不在服务端渲染进页面，而是由前端**首次打开面板时**取一次：
 * 一来后台页不必挂一份用不到的配置，二来「谁在用」这个事实只有聊天页需要知道。
 * 未配置时这里明确回一句提示，前端把它显示在面板里 —— 不做成「图标在、点开没反应」。
 */
Plugin::route('plugin_gif_stickers_conf', function (array $ctx) {
    $cfg = owGSPublic($ctx['actor'] ?? []);
    if (!$cfg) Api::json(['ok' => false, 'msg' => '表情包源尚未配置，请到后台「插件 → 表情包」填写']);
    Api::json(['ok' => true, 'cfg' => $cfg]);
});

/* ---------- 后台页 ---------- */
Plugin::adminPage('gif-stickers', '表情包', function () {
    $c = owGSConf();
    $prov = (string)$c['provider'];
    // 每一档都写清「拦到什么程度」：只写 medium 没人知道它和 low 差在哪
    $filters = [
        'off' => 'off（不过滤：含成人内容）',
        'low' => 'low（只拦最露骨的）',
        'medium' => 'medium（进一步收紧，仍可能有暗示性内容）',
        'high' => 'high（最严：只出全年龄合适的内容）',
    ];
    $h = '<h2>表情包</h2>'
        . '<p class="ow-admin-desc">在输入框工具栏首位加一个表情入口：上方搜索、下方 GIF 结果，点一下即发送，也能收藏成贴纸。两个源二选一，不能混排。</p>'
        . '<div class="ow-card">'
        . '<div class="ow-form-row" style="align-items:center">'
        . '<div class="ow-form-item" style="min-width:200px"><label>表情包源</label>'
        . '<select class="ow-input" id="owGsProvider">'
        . '<option value="klipy"' . ($prov === 'klipy' ? ' selected' : '') . '>KLIPY（客户端直连）</option>'
        . '<option value="apihz"' . ($prov === 'apihz' ? ' selected' : '') . '>API 盒子 表情包（本站代理）</option>'
        . '</select>'
        . '<p style="font-size:12px;color:var(--ow-text-sub)">KLIPY 的条款要求请求必须由浏览器直接发起，所以它的 app_key 会出现在访客的请求里；'
        . 'API 盒子用的是长期密钥，只存在本站、由本站代查，不会下发到浏览器。两边内容不混排是 KLIPY 明确要求的。</p></div>'
        . '<div class="ow-form-item" style="min-width:140px"><label>每页结果数</label>'
        . '<input class="ow-input" type="number" min="8" max="50" step="1" id="owGsPerPage" value="' . (int)$c['klipy_per_page'] . '">'
        . '<p style="font-size:12px;color:var(--ow-text-sub)">KLIPY 允许 8–50；API 盒子最多 20，超出会被它自己截断。</p></div>'
        . '</div>';

    // 只回显当前所选源的密钥：两源都渲染等于把没用上的那把钥匙也塞进页面源码
    $klipyOpen = $prov === 'klipy';
    $h .= '<div class="ow-form-row ow-gs-src" data-owgs-src="klipy"' . ($klipyOpen ? '' : ' style="display:none"') . '>';
    $h .= '<div class="ow-form-item" style="flex:1 1 100%"><label>KLIPY</label>'
        . '<p style="font-size:12px;color:var(--ow-text-sub)">在 KLIPY 的 Partner Panel（partner.klipy.com/api-keys）创建 API Key。'
        . '内容过滤与国家本地化都是他们文档里的请求参数，本站只是把它们填进去，不改动、不二次筛选返回结果。</p></div>';
    $h .= '<div class="ow-form-item" style="min-width:230px"><label>App Key</label>'
        . '<input class="ow-input" type="text" autocomplete="off" data-owgs-field="klipy_app_key"'
        . ' value="' . htmlspecialchars((string)$c['klipy_app_key'], ENT_QUOTES) . '" placeholder="KLIPY 发放的 app_key"></div>';
    $fh = '<div class="ow-form-item" style="min-width:170px"><label>内容过滤</label><select class="ow-input" data-owgs-field="klipy_content_filter">';
    foreach ($filters as $k => $t) {
        $fh .= '<option value="' . $k . '"' . ((string)$c['klipy_content_filter'] === $k ? ' selected' : '') . '>' . $t . '</option>';
    }
    $h .= $fh . '</select><p style="font-size:12px;color:var(--ow-text-sub)">这是 KLIPY 的 content_filter 参数，不是本站拦截：'
        . '开源程序会被不同国家的站点使用，所以做成可配置而不是一刀切。</p></div>';
    $h .= '<div class="ow-form-item" style="min-width:150px"><label>国家 / 地区</label>'
        . '<input class="ow-input" type="text" maxlength="2" autocomplete="off" data-owgs-field="klipy_locale"'
        . ' value="' . htmlspecialchars((string)$c['klipy_locale'], ENT_QUOTES) . '" placeholder="us">'
        . '<p style="font-size:12px;color:var(--ow-text-sub)">ISO 3166-1 两位国家码（cn / us / uk / jp…），KLIPY 据此排布本地化结果。</p></div>';
    $h .= '<div class="ow-form-item" style="min-width:150px"><label>KLIPY 广告</label>'
        . '<select class="ow-input" data-owgs-field="klipy_ads">'
        . '<option value="1"' . ((string)$c['klipy_ads'] === '1' ? ' selected' : '') . '>开启（按其返回位置渲染）</option>'
        . '<option value="0"' . ((string)$c['klipy_ads'] !== '1' ? ' selected' : '') . '>关闭（不请求广告位参数）</option></select>'
        . '<p style="font-size:12px;color:var(--ow-text-sub)">他们的广告只对移动端浏览器 UA 投放，桌面端即使参数全对也可能一条都不出——这不是接错了。</p></div>';
    $h .= '<div class="ow-form-item" style="min-width:150px"><label>Powered by 标记</label>'
        . '<select class="ow-input" data-owgs-field="klipy_powered">'
        . '<option value="0"' . ((string)$c['klipy_powered'] !== '1' ? ' selected' : '') . '>不显示（文档标 OPTIONAL）</option>'
        . '<option value="1"' . ((string)$c['klipy_powered'] === '1' ? ' selected' : '') . '>面板底部显示</option></select>'
        . '<p style="font-size:12px;color:var(--ow-text-sub)">无论这项开关如何，搜索框占位文字都固定是 “Search KLIPY”——那是唯一标 REQUIRED 的一条，不进翻译。</p></div>';
    $h .= '</div>';

    $apihzOpen = $prov === 'apihz';
    $h .= '<div class="ow-form-row ow-gs-src" data-owgs-src="apihz"' . ($apihzOpen ? '' : ' style="display:none"') . '>';
    $h .= '<div class="ow-form-item" style="flex:1 1 100%"><label>API 盒子 · 表情包（APIID 207）</label>'
        . '<p style="font-size:12px;color:var(--ow-text-sub)">id 与 key 在 API 盒子用户中心「个人资料」里查看。'
        . '本站按线路二（普通集群负载-域名接口）调用，密钥只在服务端拼接，前端拿不到。</p></div>';
    $h .= '<div class="ow-form-item" style="min-width:170px"><label>线路</label>'
        . '<select class="ow-input" data-owgs-field="apihz_line">'
        . '<option value="cn"' . ((string)$c['apihz_line'] !== 'vip' ? ' selected' : '') . '>线路二 普通集群负载（域名接口）</option>'
        . '<option value="vip"' . ((string)$c['apihz_line'] === 'vip' ? ' selected' : '') . '>线路三 VIP 集群负载</option></select></div>';
    $h .= '<div class="ow-form-item" style="min-width:170px"><label>开发者 ID</label>'
        . '<input class="ow-input" type="text" autocomplete="off" data-owgs-field="apihz_id"'
        . ' value="' . htmlspecialchars((string)$c['apihz_id'], ENT_QUOTES) . '" placeholder="例：10000000"></div>';
    $h .= '<div class="ow-form-item" style="min-width:200px"><label>开发者 KEY</label>'
        . '<input class="ow-input" type="password" autocomplete="off" data-owgs-field="apihz_key"'
        . ' value="' . htmlspecialchars((string)$c['apihz_key'], ENT_QUOTES) . '" placeholder="通讯秘钥"></div>';
    $h .= '<div class="ow-form-item" style="min-width:140px"><label>每次返回</label>'
        . '<input class="ow-input" type="number" min="1" max="20" step="1" data-owgs-field="apihz_limit"'
        . ' value="' . max(1, min(20, (int)$c['apihz_limit'])) . '">'
        . '<p style="font-size:12px;color:var(--ow-text-sub)">接口上限 20 条；关键词最长 10 个汉字。</p></div>';
    $h .= '</div>';

    $h .= '<div class="ow-form-row" style="margin-top:10px">'
        . '<button class="ow-btn ow-btn-primary" onclick="OwGS.save()">保存设置</button>'
        . '<button class="ow-btn ow-btn-ghost" onclick="OwGS.test()">测试当前源</button></div>'
        . '<div id="owGsMsg" style="margin-top:10px"></div>'
        . '</div>';
    return $h;
});

/* ---------- 路由 ---------- */
Plugin::route('plugin_gif_stickers_save', function (array $ctx) {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
    $post = $ctx['post'];
    $provider = (string)($post['provider'] ?? 'klipy');
    if (!in_array($provider, ['klipy', 'apihz'], true)) Api::json(['ok' => false, 'msg' => '未知的表情包源']);
    $kv = [
        'provider' => $provider,
        'klipy_app_key' => mb_substr(trim((string)($post['klipy_app_key'] ?? '')), 0, 200),
        'klipy_content_filter' => in_array((string)($post['klipy_content_filter'] ?? ''), ['off', 'low', 'medium', 'high'], true)
            ? (string)$post['klipy_content_filter'] : 'high',
        // 国家码只留两位字母，其余字符一律丢掉：这个值会被拼进第三方请求
        'klipy_locale' => mb_strtolower(preg_replace('/[^A-Za-z]/', '', (string)($post['klipy_locale'] ?? ''))),
        'klipy_per_page' => (string)max(8, min(50, (int)($post['klipy_per_page'] ?? 24))),
        'klipy_ads' => ($post['klipy_ads'] ?? '0') === '1' ? '1' : '0',
        'klipy_powered' => ($post['klipy_powered'] ?? '0') === '1' ? '1' : '0',
        'apihz_line' => ($post['apihz_line'] ?? 'cn') === 'vip' ? 'vip' : 'cn',
        'apihz_id' => mb_substr(preg_replace('/[^0-9]/', '', (string)($post['apihz_id'] ?? '')), 0, 20),
        'apihz_key' => mb_substr(trim((string)($post['apihz_key'] ?? '')), 0, 100),
        'apihz_limit' => (string)max(1, min(20, (int)($post['apihz_limit'] ?? 20))),
    ];
    if ($kv['klipy_locale'] === '') $kv['klipy_locale'] = 'us';
    // 选了源却没填密钥 → 拒绝保存。否则前端会出现「图标在、点开永远搜不到」的静默故障
    if (!owGSReady($provider, $kv)) {
        Api::json(['ok' => false, 'msg' => $provider === 'klipy' ? '请填写 KLIPY 的 App Key' : '请填写 API 盒子的开发者 ID 与 KEY']);
    }
    foreach ($kv as $k => $v) DB::upsert('plugin_gif_stickers_config', ['k' => $k, 'v' => $v], ['k']);
    Sec::log('gif_source_save', $ctx['actor']['nickname'] ?? '', ['provider' => $provider]);
    Api::json(['ok' => true, 'msg' => '已保存']);
});
Plugin::sensitive('plugin_gif_stickers_save');

/**
 * apihz 代理搜索（KLIPY 那一源由浏览器直连，不经过这里）。
 * 返回里只有图片地址与分页信息 —— id/key 永远留在服务端。
 */
Plugin::route('plugin_gif_stickers_search', function (array $ctx) {
    $actor = $ctx['actor'] ?? [];
    $kind = (string)($actor['kind'] ?? 'none');
    if ($kind === 'none') Api::json(['ok' => false, 'msg' => '请先登录'], 403);
    $c = owGSConf();
    if ((string)$c['provider'] !== 'apihz' || !owGSReady('apihz', $c)) {
        Api::json(['ok' => false, 'msg' => '当前表情包源不是 API 盒子'], 400);
    }
    // 这是拿本站身份去敲别人的接口，等于替所有访客买单：按人限流
    $ident = $kind . ':' . (int)($actor['id'] ?? 0);
    if (!Sec::rateLimit('gif_apihz', $ident, 60, 20)) {
        Api::json(['ok' => false, 'msg' => '搜索太频繁，请一分钟后再试'], 429);
    }
    $words = mb_substr(trim((string)($ctx['post']['words'] ?? '')), 0, 10);   // 接口自身限 10 个汉字
    $page = max(1, min(50, (int)($ctx['post']['page'] ?? 1)));
    $limit = max(1, min(20, (int)$c['apihz_limit']));
    $url = owGSApihzHost((string)$c['apihz_line']) . '/api/img/apihzbqb.php'
        . '?id=' . rawurlencode((string)$c['apihz_id'])
        . '&key=' . rawurlencode((string)$c['apihz_key'])
        . '&type=' . ($words !== '' ? '2' : '1')
        . '&limit=' . $limit . '&page=' . $page
        . ($words !== '' ? '&words=' . rawurlencode($words) : '')
        . '&uip=' . rawurlencode(Sec::ip());
    $r = owGSGet($url);
    if (!$r['ok']) { Api::json(['ok' => false, 'msg' => '表情包接口请求失败：' . $r['error']]); }
    $j = json_decode($r['body'], true);
    if (!is_array($j)) Api::json(['ok' => false, 'msg' => '表情包接口返回了无法解析的内容']);
    if ((int)($j['code'] ?? 0) !== 200) {
        Api::json(['ok' => false, 'msg' => '表情包接口：' . (string)($j['msg'] ?? '返回异常')]);
    }
    $list = [];
    foreach ((array)($j['res'] ?? []) as $u) {
        $u = trim((string)$u);
        // 只收图片直链：这一串会被前端当 <img src> 用，不能是 javascript: 之类的东西
        if ($u !== '' && preg_match('#^https?://#i', $u)) $list[] = $u;
    }
    Api::json([
        'ok' => true, 'data' => $list,
        'page' => (int)($j['page'] ?? $page),
        'maxpage' => (int)($j['maxpage'] ?? 1),
        'count' => (int)($j['count'] ?? count($list)),
    ]);
});

/* ---------- 前端资源 ---------- */
/**
 * 样式表必须自己发 <link>。核心只在页面里引 owlsgo.css 与合并 **JS**，
 * 合并 CSS 端点（?action=assets&type=css）没有任何地方引用它 ——
 * attachment-manager 自己的注释就写着「主程序未引入 …… 插件 CSS 不会自动加载」。
 *
 * ⚠️ 上一版我据「实测有两个样式表命中本插件规则」得出「核心会合并插件 CSS，
 * 只登记就够」，那是**搭了顺风车**：其中一份是 attachment-manager 在 page.head 里
 * 发的整包 link，一停用附件插件，本插件样式就整片消失
 * （面板塌成普通块、搜索框撑满整行、广告 iframe 失去约束）。
 * 现在只发自己那一份，不依赖任何别的插件是否启用。
 */
Plugin::on('page.head', function (): void {
    echo '<link rel="stylesheet" href="?action=assets&type=css&plugin=gif-stickers&file=style.css&v=' . OWLSGO_VERSION . '">';
});

/** 登记资源：既是合并包的成员，也是 ?action=assets 的**可服务白名单**（不登记则取不到） */
Plugin::asset('js', 'gif-stickers/admin.js');
Plugin::asset('js', 'gif-stickers/chat.js');
Plugin::asset('css', 'gif-stickers/style.css');
