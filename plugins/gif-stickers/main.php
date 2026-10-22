<?php
/**
 * 表情包插件（v1.3.75）
 *
 * 输入框工具栏**首位**一个图标，点开是「上方搜索、下方 GIF 结果」的面板；
 * 点结果即发一条图片消息（内容就是图源那条外链），★ 把同一条外链收进贴纸表。
 * 核心贴纸表本来只认本站 `uploads/` 下的地址（v1.0.100 起），外站地址靠本插件应答
 * `sticker.allow` 钩子放行 —— 只有它自己集成的图源域名过得去，停用插件这条就收回。
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
 * ⚠️ **收藏只存地址，不下载到本站**（v1.3.81 站方定）。这不是偷懒：把图取回来落盘
 * 正是 KLIPY 明文不要做的「存储 / 镜像 / 转存」，存地址才是照它的条款用。
 * 代价必须让用户看见 —— 图源撤图、换地址或链接过期，那张贴纸就打不开了，
 * 所以收藏成功的提示里带着这句（见 chat.js），后台页也摆着一句。
 *
 * KLIPY 署名要求里唯一标 REQUIRED 的一条是「搜索框 placeholder 必须是 Search KLIPY」，
 * 所以该源下这句**不进语言包**（见 chat.js 里的 OW_GIF_KLIPY_PLACEHOLDER）；
 * 水印与 Powered by 两项文档写的是 OPTIONAL，做成后台开关，默认关。
 *
 * 资源：样式表与脚本走 Plugin::asset 登记，由核心的 ?action=assets 合并输出
 * 路由：plugin_gif_stickers_conf     前端首次打开面板时取的公共配置
 *       plugin_gif_stickers_save    管理员保存配置（敏感，需一次性票据）
 *       plugin_gif_stickers_search  apihz 服务端代理搜索（v1.2.0 起带缓存）
 *
 * v1.2.0：两条路各加一份缓存，**层的选择不是随意的**：
 *   - apihz（服务端代理）：访客看到的是本站的响应，缓存也放服务端（新表 + 后台「缓存天数」）。
 *   - klipy（浏览器直连）：他们的《Integration Requirements》写明「请求必须由终端用户客户端发起、
 *     不得经服务器 / 代理转发或缓存」—— 服务端缓存正好是它禁的那一条，所以缓存只能放在
 *     访客自己的 localStorage 里（后台「结果缓存天数」，默认 1 天）。请求仍由终端发起，条款不受影响。
 *   两处都只存「地址与分页信息」，不落任何图片文件（与收藏同一口径）。
 */
declare(strict_types=1);

DB::run("CREATE TABLE IF NOT EXISTS plugin_gif_stickers_config (k VARCHAR(64) PRIMARY KEY, v TEXT)");
/* v1.2.0：apihz 返回的缓存表。
   API 盒子按天限额（免费档 150 次/天），热门与搜索都走同一个代理路由，
   所以按「线路 + 关键词 + 页码 + 条数」把**接口原始返回**整包存下来，期限内不再重复请求。
   ⚠️ 只存地址与分页信息，不落任何图片文件 —— 与收藏同一口径：存的是「别重复请求」，
      不是「把图搬回家」；图仍在他们图床上，撤图/换地址这张贴纸就打开不了（提示文案早已写明）。
   k 是 md5（32 字符），expire_at 用 BIGINT 存 unix 时间戳，两种驱动都能放。 */
DB::run("CREATE TABLE IF NOT EXISTS plugin_gif_stickers_cache (k VARCHAR(64) PRIMARY KEY, v TEXT, expire_at BIGINT NOT NULL)");
// v1.5.22：后台「刷新数据缓存」/ Cache::clear('all') 时一并清空接口结果缓存
Plugin::on('cache.clear', function (string $area = 'all'): void {
    if ($area !== 'all') return;
    try { DB::run('DELETE FROM plugin_gif_stickers_cache'); } catch (Throwable $e) {}
});

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
        // v1.2.0：KLIPY 结果的**浏览器端**缓存天数（服务端缓存撞他们的条款，见 chat.js 里的说明）。
        // 默认只给 1 天：他们的文档对缓存是负面的，我们只取「同一天里同一个查询不重复请求」这一档收益
        'klipy_cache_days' => '1',
        // API 盒子：服务端代理
        'apihz_line' => 'cn', 'apihz_id' => '', 'apihz_key' => '', 'apihz_limit' => '20',
        // v1.2.0：接口限额 150 次/天，所以默认开 7 天缓存；0 = 关闭（每次真调）
        'apihz_cache_days' => '7',
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
            // v1.2.0：只下发「几天」，缓存本体在访客浏览器的 localStorage 里
            'cache_days' => max(0, min(90, (int)$c['klipy_cache_days'])),
        ],
        'apihz' => ['limit' => max(1, min(20, (int)$c['apihz_limit']))],
    ];
}

/** apihz 线路白名单：主机名只能从这张表里取，任何情况下不接受外部传入的地址（SSRF） */
function owGSApihzHost(string $line): string
{
    return $line === 'vip' ? 'https://vip.apihz.cn' : 'https://cn.apihz.cn';
}

/**
 * 这条外链能不能进贴纸表：只放 https、无端口、无 userinfo、host 命中图源白名单、≤500 字符。
 * 单独成函数是因为这几条要**逐条测**（.tools/gif-collect-test.php 就是照着它喂反例的）。
 * https 这一条不只是卫生问题：本站是 https 页面，浏览器会直接拦掉页面里的 http 图片，
 * 存进去就是一只永远的破图。
 */
function owGSImageAllowed(string $url): bool
{
    if ($url === '' || strlen($url) > 500) return false;
    $p = parse_url($url);
    if (!is_array($p)) return false;
    if (($p['scheme'] ?? '') !== 'https') return false;
    if (isset($p['port']) || isset($p['user'])) return false; // 「合法域名 + 别的端口/凭据」也是别的地址
    return owGSHostAllowed((string)($p['host'] ?? ''));
}

/**
 * 服务端 GET（只用于 apihz）。证书必须验：拿明文密钥去敲别人的接口，中间人能直接收走。
 *
 * ⚠️ 两个坑都踩过，两条都要留着：
 *  ① Windows 的 PHP **没有系统 CA 库**（phpStudy 的 php.ini 里 curl.cainfo 是空的），
 *     curl 于是必报「unable to get local issuer certificate」。解法是显式指一个 CA 包，
 *     复用 Upgrade::caBundle() 的发现结果（captcha-verify 早就是这么做的，本函数当时漏了）。
 *  ② 就算 CA 包找到了，也别把 curl 当唯一一条路 —— 换台机器可能一个包都找不到，
 *     而 openssl 流包装器在这台机器上验签是通的（实测：curl FAIL、stream OK）。
 *     所以 curl 失败要**往下走**流包装器，而不是直接返回错误。
 * 两条都不许变成「关掉校验」。
 */
function owGSGet(string $url, int $timeout = 8): array
{
    $curlErr = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch) {
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 2,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT      => 'owlsgo-gif-stickers/1.0',
            ];
            if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
                $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
            }
            if (class_exists('Upgrade')) {
                $ca = Upgrade::caBundle();
                if (is_string($ca) && $ca !== '') {
                    $opts[CURLOPT_CAINFO] = $ca;
                    $opts[CURLOPT_CAPATH] = dirname($ca);
                }
            }
            curl_setopt_array($ch, $opts);
            $body = curl_exec($ch);
            $curlErr = $body === false ? curl_error($ch) : '';
            curl_close($ch);
            if ($body !== false) return ['ok' => true, 'body' => (string)$body, 'error' => ''];
        } else {
            $curlErr = '无法初始化请求';
        }
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'GET', 'timeout' => $timeout, 'follow_location' => 1,
        'max_redirects' => 2, 'ignore_errors' => true, 'user_agent' => 'owlsgo-gif-stickers/1.0',
    ], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false
        ? ['ok' => false, 'body' => '', 'error' => $curlErr !== '' ? $curlErr : '请求失败']
        : ['ok' => true, 'body' => $body, 'error' => ''];
}

/**
 * 允许进贴纸表的外站域名：只有本插件自己集成的这两个图源的主机。
 * 判定必须在服务端 —— 前端递什么地址过来都不可信，`sticker.allow` 是本插件替用户
 * 作保的口子，作保范围就只能是自己图源官方域名下的图片直链。
 */
function owGSHostAllowed(string $host): bool
{
    $host = mb_strtolower(trim($host));
    if ($host === '') return false;
    foreach (['klipy.com', 'apihz.cn'] as $d) {
        if ($host === $d || str_ends_with($host, '.' . $d)) return true;
    }
    return false;
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
    // content_filter 是 KLIPY 的**内容分级**：成人只是其中一类，其它敏感题材走的是同一把尺子。
    // 这里只写「松紧」，不复述题材清单 —— 具体某个内容落在哪档由 KLIPY 那边判定，
    // 我们替它列举只会变成「界面说的和实际拦的两回事」（docs 的分级页是 JS 渲染，抓不到原文，不编）。
    $filters = [
        'off' => 'off（不按分级过滤，原样返回）',
        'low' => 'low（只拦分级最高那一档）',
        'medium' => 'medium（再收紧一级，边缘内容仍可能出现）',
        'high' => 'high（最严：只出分级上适合全年龄的结果）',
    ];
    $h = '<h2>表情包</h2>'
        . '<p class="ow-admin-desc">在输入框工具栏首位加一个表情入口：上方搜索、下方 GIF 结果，点一下即发送；点★收藏成贴纸（存的是图源链接，不落到本站）。两个源二选一，不能混排。</p>'
        . '<div class="ow-card">'
        . '<div class="ow-form-row" style="align-items:center">'
        . '<div class="ow-form-item" style="min-width:200px"><label>表情包源</label>'
        . '<select class="ow-input" id="owGsProvider">'
        . '<option value="klipy"' . ($prov === 'klipy' ? ' selected' : '') . '>KLIPY（客户端直连）</option>'
        . '<option value="apihz"' . ($prov === 'apihz' ? ' selected' : '') . '>API 盒子 表情包（国内可达，本站代理）</option>'
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
        . '内容过滤与国家本地化都是他们文档里的请求参数，本站只是把它们填进去，不改动、不二次筛选返回结果。'
        . '收藏（★）也只存他们返回的图片地址、本站不留文件：他们的条款要求媒体留在自己的地址上，转存才是违约。'
        . '于是这张贴纸能不能一直打开，取决于 KLIPY 那边把图留多久。</p></div>';
    $h .= '<div class="ow-form-item" style="min-width:230px"><label>App Key</label>'
        . '<input class="ow-input" type="text" autocomplete="off" data-owgs-field="klipy_app_key"'
        . ' value="' . htmlspecialchars((string)$c['klipy_app_key'], ENT_QUOTES) . '" placeholder="KLIPY 发放的 app_key"></div>';
    $fh = '<div class="ow-form-item" style="min-width:170px"><label>内容过滤</label><select class="ow-input" data-owgs-field="klipy_content_filter">';
    foreach ($filters as $k => $t) {
        $fh .= '<option value="' . $k . '"' . ((string)$c['klipy_content_filter'] === $k ? ' selected' : '') . '>' . $t . '</option>';
    }
    $h .= $fh . '</select><p style="font-size:12px;color:var(--ow-text-sub)">这是 KLIPY 的 content_filter：一条「内容分级」的尺子，'
        . '成人内容只是其中一类，其它敏感题材走的是同一把尺子，具体某个内容落在哪档由 KLIPY 判定，本站不复述题材清单。'
        . '参数只送到他们那边过滤，本站不额外拦截、也不二次筛选返回结果；'
        . '开源程序会被不同国家的站点使用，所以做成可配置而不是一刀切。</p></div>';
    $h .= '<div class="ow-form-item" style="min-width:150px"><label>国家 / 地区</label>'
        . '<input class="ow-input" type="text" maxlength="2" autocomplete="off" data-owgs-field="klipy_locale"'
        . ' value="' . htmlspecialchars((string)$c['klipy_locale'], ENT_QUOTES) . '" placeholder="us">'
        . '<p style="font-size:12px;color:var(--ow-text-sub)">ISO 3166-1 两位国家码（cn / us / uk / jp…），KLIPY 据此排布本地化结果。</p></div>';
    $h .= '<div class="ow-form-item" style="min-width:150px"><label>KLIPY 移动端广告</label>'
        . '<select class="ow-input" data-owgs-field="klipy_ads">'
        . '<option value="1"' . ((string)$c['klipy_ads'] === '1' ? ' selected' : '') . '>开启（按其返回位置渲染）</option>'
        . '<option value="0"' . ((string)$c['klipy_ads'] !== '1' ? ' selected' : '') . '>关闭（不请求广告位参数）</option></select>'
        . '<p style="font-size:12px;color:var(--ow-text-sub)">他们的广告只对移动端浏览器 UA 投放，桌面端即使参数全对也可能一条都不出——这不是接错了。</p></div>';
    $h .= '<div class="ow-form-item" style="min-width:150px"><label>Powered by 标记</label>'
        . '<select class="ow-input" data-owgs-field="klipy_powered">'
        . '<option value="0"' . ((string)$c['klipy_powered'] !== '1' ? ' selected' : '') . '>不显示（文档标 OPTIONAL）</option>'
        . '<option value="1"' . ((string)$c['klipy_powered'] === '1' ? ' selected' : '') . '>面板底部显示</option></select>'
        . '<p style="font-size:12px;color:var(--ow-text-sub)">无论这项开关如何，搜索框占位文字都固定是 “Search KLIPY”——那是唯一标 REQUIRED 的一条，不进翻译。</p></div>';
    $h .= '<div class="ow-form-item" style="min-width:150px"><label>结果缓存天数</label>'
        . '<input class="ow-input" type="number" min="0" max="90" step="1" data-owgs-field="klipy_cache_days"'
        . ' value="' . max(0, min(90, (int)$c['klipy_cache_days'])) . '">'
        . '<p style="font-size:12px;color:var(--ow-text-sub)">同一关键词 + 同一页在这一天内不再重复请求 KLIPY，热门与搜索都算；'
        . '缓存只落在访客自己的浏览器里、只存解析后的图片地址，不经过本站服务器 —— '
        . '他们的《Integration Requirements》要求请求必须由终端客户端发起、不得经服务器 / 代理转发或缓存，服务端缓存正好是那条禁的。'
        . '代价是图源新上的内容要等缓存过期才看得到。填 0 = 关闭缓存（每次都真调）。</p></div>';
    $h .= '</div>';

    $apihzOpen = $prov === 'apihz';
    $h .= '<div class="ow-form-row ow-gs-src" data-owgs-src="apihz"' . ($apihzOpen ? '' : ' style="display:none"') . '>';
    $h .= '<div class="ow-form-item" style="flex:1 1 100%"><label>API 盒子 · 表情包（APIID 207）</label>'
        . '<p style="font-size:12px;color:var(--ow-text-sub)">id 与 key 在 API 盒子用户中心「个人资料」里查看。'
        . '本站按线路二（普通集群负载-域名接口）调用，密钥只在服务端拼接，前端拿不到。'
        . '和 KLIPY 一样，★ 收藏的是他们图床上的地址，本站不留文件 —— 那边撤图或换地址，贴纸就打开不了。</p></div>';
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
    $h .= '<div class="ow-form-item" style="min-width:140px"><label>缓存天数</label>'
        . '<input class="ow-input" type="number" min="0" max="90" step="1" data-owgs-field="apihz_cache_days"'
        . ' value="' . max(0, min(90, (int)$c['apihz_cache_days'])) . '">'
        . '<p style="font-size:12px;color:var(--ow-text-sub)">API 盒子按天限额（免费档 150 次/天），'
        . '所以同一关键词 + 同一页在期限内只真调一次，热门与搜索结果都算；界面照常点，命中缓存时秒出。'
        . '代价是图源那边新上的内容要等缓存过期才看得到。填 0 = 关闭缓存，每次都真调（会吃满限额）。</p></div>';
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
        // v1.2.0：KLIPY 结果缓存天数（浏览器端）。0 = 关闭
        'klipy_cache_days' => (string)max(0, min(90, (int)($post['klipy_cache_days'] ?? 1))),
        'apihz_line' => ($post['apihz_line'] ?? 'cn') === 'vip' ? 'vip' : 'cn',
        'apihz_id' => mb_substr(preg_replace('/[^0-9]/', '', (string)($post['apihz_id'] ?? '')), 0, 20),
        'apihz_key' => mb_substr(trim((string)($post['apihz_key'] ?? '')), 0, 100),
        'apihz_limit' => (string)max(1, min(20, (int)($post['apihz_limit'] ?? 20))),
        // v1.2.0：缓存天数。0 = 关闭；上限 90 天（再长也没有意义，接口内容自己会轮换）
        'apihz_cache_days' => (string)max(0, min(90, (int)($post['apihz_cache_days'] ?? 7))),
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
    /* ---------- v1.2.0：查缓存 ----------
       ⚠️ 限流仍然排在上面、缓存命中也不放宽 —— 它挡的是「拿本站密钥刷第三方接口」
          （每个新关键词都是一次真调用，缓存挡不住这种刷法）。
       键里带上线路 / 页码 / 条数：管理员改了「每次返回」或换了线路，旧条目自然不会命中，
       不需要额外的失效逻辑。 */
    $cacheDays = max(0, min(90, (int)$c['apihz_cache_days']));
    // 「测试当前源」必须打真接口 —— 否则命中缓存时看着「代理可用」，其实上游早挂了。
    // 只认管理员：不这么限的话，任何登录用户都能用 nocache=1 把日限额刷光。
    $noCache = $cacheDays === 0
        || (!empty($ctx['post']['nocache']) && (string)($actor['role'] ?? '') === 'admin');
    $ck = md5((string)$c['apihz_line'] . '|' . $words . '|' . $page . '|' . $limit);
    if (!$noCache) {
        $row = DB::one('SELECT v, expire_at FROM plugin_gif_stickers_cache WHERE k=?', [$ck]);
        if ($row && (int)$row['expire_at'] > time()) {           // 过期行当未命中（由下面写入时顺手清）
            $hit = json_decode((string)$row['v'], true);
            if (is_array($hit) && isset($hit['data']) && is_array($hit['data'])) {
                Api::json([
                    'ok' => true, 'data' => $hit['data'],
                    'page' => (int)($hit['page'] ?? $page), 'maxpage' => (int)($hit['maxpage'] ?? 1),
                    'count' => (int)($hit['count'] ?? count($hit['data'])), 'cached' => 1,
                ]);
            }
        }
    }
    $url = owGSApihzHost((string)$c['apihz_line']) . '/api/img/apihzbqb.php'
        . '?id=' . rawurlencode((string)$c['apihz_id'])
        . '&key=' . rawurlencode((string)$c['apihz_key'])
        . '&type=' . ($words !== '' ? '2' : '1')
        . '&limit=' . $limit . '&page=' . $page
        . ($words !== '' ? '&words=' . rawurlencode($words) : '');
    // ⚠️ 不传 uip（访客 IP）。接口盒子的文档把它写成可选，但**非会员传了就直接被拒**：
    //    实测免费开发者带 uip 调用回「该开发者非彩钻或炫钻或焱钻会员，禁止传自定义 uip 参数」，
    //    换成会员线路才允许。传 IP 对我们没有任何功能作用（限流是自己按人算的），
    //    所以一律不传；将来买了会员线路要加回来，得连这条注释一起改。
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
    $payload = [
        'data' => $list,
        'page' => (int)($j['page'] ?? $page),
        'maxpage' => (int)($j['maxpage'] ?? 1),
        'count' => (int)($j['count'] ?? count($list)),
    ];
    // v1.2.0：真调成功才写缓存（接口报错/返回异常都不落，免得把失败记成结论）
    if (!$noCache) {
        DB::upsert('plugin_gif_stickers_cache', [
            'k' => $ck,
            'v' => (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'expire_at' => time() + $cacheDays * 86400,
        ], ['k']);
        // 顺手清理：过期行每次写入删一遍；再兜一个 500 行上限 —— 搜索词是无限的，
        // 不封顶的话光靠过期清不干净（7 天里能存下成千上万个词）。
        // LIMIT 只能拼（是算出来的整数，不是用户输入）；子查询再套一层是 MySQL 的硬要求。
        DB::run('DELETE FROM plugin_gif_stickers_cache WHERE expire_at < ?', [time()]);
        $n = (int)DB::val('SELECT COUNT(*) FROM plugin_gif_stickers_cache');
        if ($n > 500) {
            DB::run('DELETE FROM plugin_gif_stickers_cache WHERE k IN (SELECT k FROM (SELECT k FROM plugin_gif_stickers_cache ORDER BY expire_at ASC LIMIT ' . ($n - 500) . ') x)');
        }
    }
    Api::json(['ok' => true] + $payload);
});

/**
 * 放行收藏：核心贴纸表默认只收本站 uploads/ 下的图片（v1.0.100 起），
 * 本插件明确集成了图源，所以由它替自己图源的外链图片地址作保（sticker.allow 钩子）。
 *
 * 为什么是「存地址」而不是「下载到本站」：KLIPY 的《Integration Requirements》要求
 * 媒体留在他们的地址上、不得存储 / 镜像 / 转存 —— 转存才是违约，存地址是照它的条款用。
 * 代价写在用户看得见的地方：图源撤图或换地址，这张贴纸就打不开了（见 chat.js 的收藏提示）。
 *
 * 只对**自己图源的官方主机 + https + 无端口无凭据**放行；其它地址一律不认，
 * 停用了本插件，核心那条限制就恢复成原样（钩子没人应答）。
 */
Plugin::on('sticker.allow', function (string &$allow, string $url, array $actor) {
    if (owGSImageAllowed(trim($url))) $allow = '1';
});

// ⚠️ v1.5.31 起下面这段已作废：核心 pageHead 统一引整包 CSS，插件不再自己发切片（保留作历史）
/* ---------- 前端资源 ---------- */
/**
 * 样式表必须自己发 <link>。核心只在页面里引 index.css 与合并 **JS**，
 * 合并 CSS 端点（?action=assets&type=css）没有任何地方引用它 ——
 * attachment-manager 自己的注释就写着「主程序未引入 …… 插件 CSS 不会自动加载」。
 *
 * ⚠️ 上一版我据「实测有两个样式表命中本插件规则」得出「核心会合并插件 CSS，
 * 只登记就够」，那是**搭了顺风车**：其中一份是 attachment-manager 在 page.head 里
 * 发的整包 link，一停用附件插件，本插件样式就整片消失
 * （面板塌成普通块、搜索框撑满整行、广告 iframe 失去约束）。
 * 现在只发自己那一份，不依赖任何别的插件是否启用。
 */
// v1.5.31：CSS 不再由插件自己发切片 —— 核心 pageHead 统一引整包
// （整包本就包含本插件的样式；此前每个插件各发一条，首屏变成 16 个串行 CSS 请求）

/**
 * 表情包按钮（v1.0.3 起走 chat.toolbar 钩子）：HTML 由服务端产出，
 * 核心 Plugin::collect('chat.toolbar') 拼进 #owToolFirst；点击与面板仍绑在 chat.js。
 * SVG 与本插件旧版 icon() 逐字一致（16px、stroke 1.5）。
 */
Plugin::on('chat.toolbar', function (): string {
    return '<button class="ow-icon-btn" id="owBtnGif" type="button" title="表情包" aria-label="表情包">'
         . '<svg class="ow-ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
         . ' stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . '<path d="M12.75 8.25v7.5m6-7.5h-3V12m0 0v3.75m0-3.75H18M9.75 9.348c-1.03-1.464-2.698-1.464-3.728 0c-1.03 1.465-1.03 3.84 0 5.304s2.699 1.464 3.728 0V12h-1.5M4.5 19.5h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5"/>'
         . '</svg></button>';
});

/** 登记资源：既是合并包的成员，也是 ?action=assets 的**可服务白名单**（不登记则取不到） */
Plugin::asset('js', 'gif-stickers/admin.js', 'admin');
Plugin::asset('js', 'gif-stickers/chat.js', 'front');
Plugin::asset('css', 'gif-stickers/style.css');
