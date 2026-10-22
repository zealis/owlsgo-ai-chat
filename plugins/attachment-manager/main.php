<?php
/**
 * 附件上传插件（v1.2.41 自核心剥离；原名「附件管理」）
 *
 * ## 职责边界
 *
 * **本插件负责**：聊天里的「图片消息」与「文件消息」两类附件上传 ——
 *   输入框按钮、上传接口、体积上限、扩展名白名单、文件落盘、后台管理与配置。
 *
 * **核心仍负责（刻意不挪）**：头像上传（个人设置/资料卡）与表情贴纸。
 *   理由：这两个是**账号/表情**功能而非「聊天附件」——
 *   若插件停用后连头像都换不了，用户会直接认为「网站坏了」。
 *
 * 停用本插件后：聊天输入框的图片/文件按钮消失、上传路由消失（调用报「未知操作」），
 * 历史消息里的附件**照常可查看/下载**（读取走核心的 file_abs / 消息渲染，不经本插件）。
 *
 * ## 按钮显隐由插件自己决定
 *
 * 本插件用 chat.toolbar 钩子产出「图片 / 文件」两个按钮，插件停用 → 钩子不注册 →
 * 按钮不出现。核心因此不需要任何 if 判断 —— 与 v1.2.37 第三方授权
 * （`window.OwOauth` 决定入口是否渲染）是同一思路。
 *
 * ## 配置从核心迁来（v1.2.41）
 *
 * 原先是核心后台设置页的三个全局项：file_upload / file_max_size / file_exts。
 * 现改为本插件自有配置（表 plugin_attachment_config），并在**首次读取时**
 * 自动吸收核心旧值（owATInitOnce），吸收后核心不再读这三个键。
 * 为什么要迁移：用户很可能已在核心后台调过上限与扩展名，直接重置成默认等于丢配置。
 */
if (!defined('OWLSGO_VERSION')) exit;   // 禁止直接 HTTP 访问本文件

/* ============================ 配置（原核心设置项迁入） ============================ */
DB::run("CREATE TABLE IF NOT EXISTS plugin_attachment_config (
    k VARCHAR(32) PRIMARY KEY,
    " . DB::textCol('v') . "
)");

/** 默认配置 */
function owATDefaultConfig(): array
{
    return [
        'enabled' => '1',   // 是否允许上传附件（0=关闭）
        'max_mb'  => '10',  // 单文件大小上限（MB）
        // v1.4.0：上传速率上限（MB/分钟，按用户滚动窗口）。默认 60 = 单文件上限 10MB 的 6 倍，
        // 正常连发够用，脚本刷盘会撞限。
        'rate_mb' => '60',
        // v1.3.13：默认白名单补上一般图片格式 —— 头像上传也走这张表
        // v1.3.86：再加 webm —— 「以视频方式发送」要能在聊天里直接播，
        //        mp4 与 webm 是浏览器原生支持的两种容器（webm 本来就在安全表里，只是没进默认串）
        'exts'    => 'jpg,jpeg,png,gif,webp,bmp,zip,rar,7z,pdf,txt,md,doc,docx,xls,xlsx,ppt,pptx,mp3,mp4,webm',
        // v1.2.0：图片压缩（WebP 有损转换）。总开关默认关闭 = 原图直存，与旧行为一致。
        // v1.3.0：细分开关 —— 总开关开启后，各自决定是否压缩（默认全开）。
        'compress'         => '0',
        'compress_q'       => '80',
        'compress_avatar'  => '1',
        'compress_image'   => '1',
        'compress_sticker' => '1',
    ];
}

/**
 * 一次性迁移：把核心旧设置搬进插件配置。
 * ⚠️ 只能读一次 —— 若每次请求都回落核心旧值，用户在插件后台改的值会被核心覆盖回去。
 */
function owATInitOnce(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $have = [];
    foreach (DB::all('SELECT k FROM plugin_attachment_config') as $r) $have[(string)$r['k']] = true;
    // v1.3.86：默认白名单加了 webm（「以视频方式发送」要在浏览器里直接播，mp4/webm 是原生支持的两种容器）。
    // 已经存过配置的站点不会跟着默认值走，所以这里补一次 —— 但**只补没动过这项的站**：
    // 存的串与旧默认串是同一套扩展名时才升级；管理员自己挑过的列表一律不擅自改（多给一种可上传格式
    // 属于放宽上传面，必须由他自己在后台做）。
    if (isset($have['exts'])) {
        $old = 'jpg,jpeg,png,gif,webp,bmp,zip,rar,7z,pdf,txt,md,doc,docx,xls,xlsx,ppt,pptx,mp3,mp4';
        $norm = function (string $s): array {
            $o = [];
            foreach (preg_split('/[\s,，;；]+/u', $s) ?: [] as $p) {
                $e = strtolower(ltrim(trim($p), '.'));
                if ($e !== '') $o[$e] = 1;
            }
            ksort($o);
            return $o;
        };
        $stored = (string)DB::val('SELECT v FROM plugin_attachment_config WHERE k=?', ['exts']);
        if ($stored !== '' && $norm($stored) === $norm($old)) {
            DB::upsert('plugin_attachment_config', ['k' => 'exts', 'v' => owATDefaultConfig()['exts']], ['k']);
        }
    }
    if (isset($have['enabled']) && isset($have['max_mb']) && isset($have['exts'])) return;
    $map = ['enabled' => 'file_upload', 'max_mb' => 'file_max_size', 'exts' => 'file_exts'];
    foreach ($map as $newKey => $oldKey) {
        if (isset($have[$newKey])) continue;
        $v = DB::setting($oldKey, '');
        if ($v === '') continue;   // 核心没这个键（全新安装）→ 保持默认值
        DB::upsert('plugin_attachment_config', ['k' => $newKey, 'v' => (string)$v], ['k']);
    }
}
owATInitOnce();

/** 读配置（缺失项补默认值） */
function owATConfig(): array
{
    $cfg = owATDefaultConfig();
    foreach (DB::all('SELECT k, v FROM plugin_attachment_config') as $r) {
        if (array_key_exists($r['k'], $cfg)) $cfg[$r['k']] = (string)$r['v'];
    }
    $cfg['max_mb'] = max(1, min(1024, (int)$cfg['max_mb']));   // 夹在 1~1024MB
    $cfg['rate_mb'] = max(1, min(10240, (int)$cfg['rate_mb']));
    $cfg['compress'] = $cfg['compress'] === '1' ? '1' : '0';
    foreach (['compress_avatar', 'compress_image', 'compress_sticker'] as $ck) {
        $cfg[$ck] = $cfg[$ck] === '1' ? '1' : '0';
    }
    $cfg['compress_q'] = max(1, min(100, (int)$cfg['compress_q']));
    return $cfg;
}

/** 写配置（只接受白名单键；扩展名还要过安全表） */
function owATSaveConfig(array $in): array
{
    $def = owATDefaultConfig();
    $safe = owATExtMime();
    foreach ($in as $k => $v) {
        if (!array_key_exists($k, $def)) continue;
        $v = trim((string)$v);
        if ($k === 'max_mb') {
            $v = (string)max(1, min(1024, (int)$v));
        } elseif ($k === 'rate_mb') {
            $v = (string)max(1, min(10240, (int)$v));
        } elseif ($k === 'compress' || $k === 'compress_avatar' || $k === 'compress_image' || $k === 'compress_sticker') {
            $v = $v === '1' ? '1' : '0';
        } elseif ($k === 'compress_q') {
            $v = (string)max(1, min(100, (int)$v));
        } elseif ($k === 'exts') {
            $clean = [];
            foreach (preg_split('/[\s,，;；]+/u', $v) ?: [] as $p) {
                $e = strtolower(ltrim(trim((string)$p), '.'));
                if ($e !== '' && isset($safe[$e])) $clean[$e] = true;   // 不在安全表里的直接忽略
            }
            $v = $clean ? implode(',', array_keys($clean)) : $def['exts'];
        }
        // upsert 三驱动通用；INSERT OR REPLACE 是 SQLite 方言，MySQL 会 1064
        DB::upsert('plugin_attachment_config', ['k' => (string)$k, 'v' => $v], ['k']);
    }
    return owATConfig();
}

/* ============================ 安全：扩展名 → MIME 白名单 ============================ */
/**
 * 允许作为「文件附件」的扩展名 => 可接受真实 MIME 列表。
 * 这是**最后一道闸**：后台配置里写了什么扩展名，都必须先在这里登记过，
 * 且 finfo 读出的真实 MIME 必须落在对应列表里 ——
 * 把 .zip 改名为 .pdf 之类的伪装会被挡下。
 *
 * ⚠️ svg / svgz 一律不登记：它是 XML、可内嵌 <script>，浏览器按图片渲染时脚本会执行。
 * ⚠️ php/phtml/html 等可执行 & 网页类同样不登记（本项目不需要交换源码）。
 * ⚠️ office 2007+（docx/xlsx/pptx）本质是 zip 包，finfo 常报 application/zip，
 *   故其 MIME 列表里一并接受 zip 的 MIME，避免误伤正常文件。
 */
function owATExtMime(): array
{
    return [
        // 图片（头像 / 图片附件共用，v1.3.13 起进白名单表）
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'bmp'  => ['image/bmp', 'image/x-ms-bmp'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
        'rar'  => ['application/x-rar-compressed', 'application/vnd.rar', 'application/x-rar'],
        '7z'   => ['application/x-7z-compressed'],
        'gz'   => ['application/gzip', 'application/x-gzip'],
        'pdf'  => ['application/pdf'],
        'txt'  => ['text/plain'],
        'md'   => ['text/markdown', 'text/plain'],
        'csv'  => ['text/plain', 'text/csv'],
        'log'  => ['text/plain', 'text/x-log'],
        'doc'  => ['application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls'  => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ppt'  => ['application/vnd.ms-powerpoint', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'mp3'  => ['audio/mpeg'],
        'wav'  => ['audio/wav', 'audio/x-wav'],
        'mp4'  => ['video/mp4'],
        'webm' => ['video/webm'],
    ];
}

/* ============================ 上传实现 ============================ */

/** 附件总开关（图片与文件共用） */
function owATEnabled(): bool
{
    return owATConfig()['enabled'] === '1';
}

/**
 * 单文件体积上限（字节）。
 * v1.2.42：$actor 非空时先过 `upload.maxsize` 钩子 —— 等级信任插件会按等级
 * **下调**上限（设计文档「五次功能解锁」：3 级起 1/4 全局上限、10 级起 1/2）。
 * 只接受「比全局更小」的值：插件放行不了比站点配置更大的文件，避免越权。
 */
function owATMaxBytes(?array $actor = null): int
{
    $max = (int)owATConfig()['max_mb'] * 1048576;
    if ($actor !== null) {
        $tmp = $max;
        Plugin::fire('upload.maxsize', [&$tmp, $actor]);
        if ($tmp > 0 && $tmp < $max) $max = $tmp;
    }
    return $max;
}

/** 当前允许的扩展名（后台配置 ∩ 安全表） */
function owATExts(): array
{
    $safe = owATExtMime();
    $out = [];
    foreach (preg_split('/[\s,，;；]+/u', owATConfig()['exts']) ?: [] as $piece) {
        $ext = strtolower(ltrim(trim((string)$piece), '.'));
        if ($ext !== '' && isset($safe[$ext])) $out[$ext] = true;
    }
    return $out === [] ? ['zip', 'pdf', 'txt'] : array_keys($out);
}

/** 读真实 MIME（finfo，绝不信任客户端声明的 type） */
function owATRealMime(string $path): string
{
    if (class_exists('finfo')) {
        $f = new finfo(FILEINFO_MIME_TYPE);
        $m = $f->file($path);
        if (is_string($m) && $m !== '') return strtolower($m);
    }
    return 'application/octet-stream';
}

/* ============================ 图片压缩（v1.2.0：WebP 有损转换） ============================ */

/**
 * imagewebp() 是否真的可用 —— 「函数存在」不等于「能写」：
 * 有的 GD 编译了函数但缺 libwebp，调用照样失败。所以实际写一张 2×2 图验证，
 * 结果按请求缓存（static），一次请求最多测一遍。
 */
function owATWebpReady(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    if (!function_exists('imagewebp') || !function_exists('imagecreatefromstring')) { $ok = false; return $ok; }
    $im = @imagecreatetruecolor(2, 2);
    if (!$im) { $ok = false; return $ok; }
    $t = @tempnam(sys_get_temp_dir(), 'atwchk');
    $ok = $t !== false && @imagewebp($im, $t, 80) && (int)@filesize($t) > 0;
    imagedestroy($im);
    if ($t !== false) @unlink($t);
    return $ok;
}

/** GIF 是否带动画（NETSCAPE2.0 = 动画循环扩展的标准标记，一定在前 64KB 内） */
function owATIsAnimatedGif(string $path): bool
{
    $raw = @file_get_contents($path, false, null, 0, 65536);
    return $raw !== false && strpos($raw, 'NETSCAPE2.0') !== false;
}

/**
 * 按 EXIF Orientation 把 GD 图像转正。
 * 为什么必须做：手机照片是「横向像素 + 旋转标记」，浏览器靠 EXIF 显示成正的，
 * 但 GD 读入时不应用标记 —— 不转正就存，WebP（无 EXIF）会整体躺倒。
 * ⚠️ 必须引用传 $im：PHP 8 里 GD 图像是对象，imagerotate 返回**新对象**，
 *    值传递时函数内替换的只是局部变量，调用方仍拿旧图。
 */
function owATApplyExifOrientation(&$im, string $path): void
{
    if (!function_exists('exif_read_data') || !function_exists('imagerotate')) return;
    $ex = @exif_read_data($path);
    $ori = (int)($ex['Orientation'] ?? 0);
    if ($ori <= 0 || $ori > 8) return;
    $bg = (int)imagecolorallocate($im, 0, 0, 0);
    $rot = function ($img, $deg) use ($bg) {
        $r = @imagerotate($img, $deg, $bg);   // imagerotate 正值 = 逆时针
        if ($r !== false) { imagedestroy($img); return $r; }
        return $img;
    };
    switch ($ori) {
        case 3: $tmp = $rot($im, 180); break;
        case 4: $tmp = $rot($im, 180); imageflip($tmp, IMG_FLIP_HORIZONTAL); break;
        case 5: $tmp = $rot($im, -90); imageflip($tmp, IMG_FLIP_HORIZONTAL); break;
        case 6: $tmp = $rot($im, -90); break;
        case 7: $tmp = $rot($im, 90);  imageflip($tmp, IMG_FLIP_HORIZONTAL); break;
        case 8: $tmp = $rot($im, 90);  break;
        default: $tmp = $im;
    }
    // imagerotate 返回的是新资源，调用方拿到的 $im 必须换掉 → 只能引用传回
    if ($tmp !== $im) { $im = $tmp; }
}

/**
 * 上传落地前的 WebP 压缩（总开关或该 kind 的细分开关关闭 / 环境不支持 / 任何一步失败
 * → 原样返回 $f，绝不拦上传）。由 upload.image 钩子统一调用（见下）。
 *
 * 四条刻意保留原图的路径：
 *  ① 动图 GIF —— GD 只读第一帧，转了动画就没了；
 *  ② 超大图（>3000 万像素）—— GD 内存峰值 ≈ 像素×5 字节，防止打爆 PHP 内存；
 *  ③ 转完反而不变小的（小图 / 已高度优化的图）—— 压缩的意义是省，不是越压越大；
 *  ④ 解码失败的 —— 交给核心的 checkImage 去给专业报错。
 */
function owATCompressImage(array $f, string $kind): array
{
    $cfg = owATConfig();
    $key = ['avatar' => 'compress_avatar', 'image' => 'compress_image', 'sticker' => 'compress_sticker'][$kind] ?? '';
    if ($key === '' || $cfg['compress'] !== '1' || $cfg[$key] !== '1' || !owATWebpReady()) return $f;
    $tmp = (string)($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) return $f;
    $info = @getimagesize($tmp);
    if (!$info) return $f;
    if ($info[2] === IMAGETYPE_GIF && owATIsAnimatedGif($tmp)) return $f;
    if ((int)$info[0] * (int)$info[1] > 30000000) return $f;

    $raw = @file_get_contents($tmp);
    $im = $raw === false ? false : @imagecreatefromstring($raw);
    unset($raw);
    if (!$im) return $f;
    imagealphablending($im, false);   // 保 PNG/WebP 透明通道（否则转码变黑底）
    imagesavealpha($im, true);
    if ($info[2] === IMAGETYPE_JPEG) owATApplyExifOrientation($im, $tmp);

    $out = @tempnam(sys_get_temp_dir(), 'atw');
    $q = max(1, min(100, (int)$cfg['compress_q']));
    $ok = $out !== false && @imagewebp($im, $out, $q);
    imagedestroy($im);
    $newSize = $ok ? (int)@filesize($out) : 0;
    $origSize = (int)@filesize($tmp);
    if (!$ok || $newSize <= 0 || $newSize >= $origSize) {
        if ($out !== false) @unlink($out);
        return $f;
    }
    $name = (string)($f['name'] ?? 'image');
    $name = (preg_replace('/\.[^.]*$/', '', $name) ?: 'image') . '.webp';
    return ['name' => $name, 'type' => 'image/webp', 'tmp_name' => $out,
            'error' => UPLOAD_ERR_OK, 'size' => $newSize];
}

/* 核心 Upload::handle 落盘前触发（v1.3.30 核心钩子）。为什么挂这里而不是插件路由里：
   贴纸上传走核心 case 'upload'（不经插件），handle 才是 avatar/image/sticker
   三类共同入口 —— 细分开关必须全覆盖。 */
Plugin::on('upload.image', function (array &$f, string $kind) {
    $f = owATCompressImage($f, $kind);
});

/**
 * 上传速率闸门（v1.4.0）：每个身份每 60 秒滚动窗口累计 ≤ rate_mb。
 * 复用核心 rate_limits 表 —— 与 Sec::rateLimit 同一套行结构，只是 count 存**字节数**
 * 而非次数，所以桶名单独用 upload_bytes，不与次数桶互相污染。
 * 返回 null = 放行（并已预扣本次字节数）；返回字符串 = 拒绝理由。
 * 注意：先扣后传 —— 落盘失败也当作已用额度，宁可少容忍一次重试，不给刷盘者留空子。
 */
function owATRateTry(?array $actor, int $bytes): ?string
{
    $cap = (int)owATConfig()['rate_mb'] * 1048576;
    $ident = ($actor['kind'] ?? 'none') . (int)($actor['id'] ?? 0);
    $now = time();
    $row = DB::one('SELECT * FROM rate_limits WHERE bucket=? AND ident=?', ['upload_bytes', $ident]);
    if (!$row || $now - (int)$row['window_start'] >= 60) {
        DB::upsert('rate_limits', ['bucket' => 'upload_bytes', 'ident' => $ident, 'window_start' => $now, 'count' => $bytes], ['bucket', 'ident']);
        return null;
    }
    if ((int)$row['count'] + $bytes > $cap) {
        $left = 60 - ($now - (int)$row['window_start']);
        return '上传太快：每分钟上限 ' . (int)($cap / 1048576) . ' MB 已用完，请 ' . max(1, $left) . ' 秒后再试';
    }
    DB::run('UPDATE rate_limits SET count=count+? WHERE bucket=? AND ident=?', [$bytes, 'upload_bytes', $ident]);
    return null;
}

/**
 * 存图片消息：复用核心 Upload（头像/贴纸同一条本地存储通道），
 * 这里额外先卡一次体积上限（配置项在插件里，不能指望核心再读）。
 */
function owATStoreImage(array $f, ?array $actor = null): array
{
    if (!owATEnabled()) return [false, '站点已关闭附件上传'];
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) return [false, '上传失败（错误码 ' . $err . '）'];
    $tmp = (string)($f['tmp_name'] ?? '');
    $max = owATMaxBytes($actor);
    if ($tmp !== '' && is_file($tmp) && (int)@filesize($tmp) > $max) {
        return [false, '图片超过 ' . round($max / 1048576, 1) . ' MB 限制'];
    }
    if ($tmp !== '' && is_file($tmp) && ($rl = owATRateTry($actor, (int)filesize($tmp))) !== null) return [false, $rl];
    // 压缩由 upload.image 钩子在 Upload::handle 内统一做（细分开关见后台「图片压缩」）
    return Upload::handle($f, 'image');   // 核心内部还会做「仅 jpg/png/gif/webp」校验
}

/**
 * 存文件附件。
 * 安全要点：① 错误码 + 体积上限 ② finfo 真实 MIME（不信任 $_FILES['type']）
 *          ③ 双重白名单（后台配置 ∩ 内置安全表）④ 随机重命名 + 年月目录
 *          ⑤ 目录写 index.html 防列举
 */
function owATStoreFile(array $f, ?array $actor = null): array
{
    if (!owATEnabled()) return [false, '站点已关闭附件上传'];
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) return [false, '上传失败（错误码 ' . $err . '）'];
    $tmp  = (string)($f['tmp_name'] ?? '');
    $orig = (string)($f['name'] ?? 'file');
    if ($tmp === '' || !is_file($tmp)) return [false, '临时文件不可读'];

    $max  = owATMaxBytes($actor);
    $size = (int)@filesize($tmp);
    if ($size <= 0) return [false, '文件内容为空'];
    if ($size > $max) return [false, '文件超过 ' . round($max / 1048576) . ' MB 限制'];   // ⚠️ $max 是字节，别直接当 MB 显示
    if (($rl = owATRateTry($actor, $size)) !== null) return [false, $rl];

    $mime  = owATRealMime($tmp);
    $extIn = strtolower((string)pathinfo($orig, PATHINFO_EXTENSION));
    $allow = owATExts();
    $safe  = owATExtMime();

    if ($extIn === '' || !in_array($extIn, $allow, true) || !in_array($mime, $safe[$extIn] ?? [], true)) {
        // 声明的扩展名不可用：再看真实内容是否属于站点已开放的类型
        $real = null;
        foreach ($allow as $e) {
            if (in_array($mime, $safe[$e], true)) { $real = $e; break; }
        }
        if ($real === null) return [false, '不支持的文件类型（' . $mime . '），仅允许：' . implode('/', $allow)];
        $extIn = $real;
    }

    // v1.2.50：改走核心的「内容哈希落盘」—— 同一份文件重复上传直接引用已有副本，
    //   不再写第二份。原来的 `date('Ym')` 月份目录 +随机名改为
    //   `file/<哈希前2位>/<哈希前32位>.<ext>`（由 Upload::storeFile 负责）。
    //   月份目录的旧结构仍在磁盘上，存量消息的 path 不变，无需迁移。
    [$ok, $rel] = Upload::storeFile($tmp, $extIn);
    if (!$ok) return [false, '保存失败'];

    $safeName = preg_replace('/[\\\\\/\x00-\x1F\x7F]/u', '', $orig) ?: 'file';
    return [true, [
        'name' => mb_substr($safeName, 0, 120),
        'ext'  => $extIn,
        'size' => $size,
        'path' => $rel,
        'mime' => $mime,
    ]];
}

/* ---------- v1.2.50：删除前查引用（去重的必要配套） ----------
   去重后，同一份物理文件可能被**多条消息**共用。
   旧实现直接 unlink —— 那时每条消息一个独立文件，删了没影响；
   现在如果还那么删，管理员删掉 A 的附件消息，B 的同一个附件就变404 了。

   所以删除前必须数一下「除本条外还有几条消息在用这个文件」，
   为 0 才真正unlink，否则只删消息记录（这是「引用计数」，
   不需要新建表 —— messages 里本来就有 path，直接查即可）。
   v1.3.82：计数范围从「消息」扩到「消息 + 收藏贴纸」—— 贴纸表只存 URL、没有自己的文件，
   漏算它就会出现「消息删干净了、别人贴纸跟着破图」这条不可恢复的误删。 */
function owAM_refCount(string $pathOrUrl, int $excludeMsgId = 0): int
{
    // ⚠️ 这里**必须匹配「哈希主体」而不是完整 path**，有两个坑：
    //
    //  ① JSON 里斜杠被转义成 `\/` —— messages.content 存的是
    //     {"path":"file\/8f\/8ffb...txt"}，所以拿 `file/8f/8ffb...txt`
    //     去 LIKE 匹配 **一条都命中不了**，计数恒为 0 →
    //     删消息时误判成「没人用这个文件」→ 把别人也在用的附件删掉。
    //     （这是实测踩到的：ref 少算，文件被误删。）
    //  ② 只取 basename 也不够 —— 旧存量是 `日期_16位hex`、新的是 32 位 hex，
    //     两者长度不同但都唯一，取「去掉扩展名的文件名主体」最稳：
    //     它不含斜杠，规避 ①；且在 JSON 里原样出现，规避转义问题。
    //
    //  不用 LIKE ... ESCAPE：项目里其它 LIKE 查询（Chat::search）都没用，
    //  SQLite/MySQL/PostgreSQL 对 ESCAPE 的支持与转义写法各不相同，容易踩跨库差异。
    //  哈希主体固定是 [0-9a-f]{32} 或 日期_hex，通配符意义为零。
    $key = trim(str_replace('\\', '/', $pathOrUrl));
    if ($key === '') return 0;
    $base = basename($key);
    $stem = preg_replace('/\.[A-Za-z0-9]{1,8}$/', '', $base) ?: $base;
    if (!preg_match('/^[A-Za-z0-9_\-]{6,80}$/', $stem)) return 0;
    // needle 形态：`%/<stem>.<ext>` —— 前面那个 `/` 很关键：
    //   · 它避开 JSON 转义（content 里是 `file\/8f\/...`，斜杠被写成 `\/`，
    //     所以**绝不能**用带 `/` 的完整 path 去匹配，一条都命中不了）；
    //   · 它又保证前面必须是「目录分隔符」，即 stem 必须出现在路径末尾的文件名段里，
    //     不会因为某个文件的 name 恰好等于 stem 而误计。
    // 实测：`%8ffb25f1...%` 命中 3（对），`%"8ffb25f1...%` 命中 0（错，前导引号假设不成立）。
    $needle = '%/' . $stem . '.%';

    $n = (int)DB::val(
        "SELECT COUNT(*) FROM messages WHERE id<>? AND type='file' AND content LIKE ?",
        [$excludeMsgId, $needle]
    );
    $n += (int)DB::val(
        "SELECT COUNT(*) FROM messages WHERE id<>? AND type='image' AND content LIKE ?",
        [$excludeMsgId, $needle]
    );
    // v1.3.86：图文混排消息把媒体地址埋在 content 的 JSON 里（媒体可能是 uploads/file/ 下的视频）。
    // 漏算它 = 管理员删掉一条**文件**消息时，把某条混排消息正在引用的同一段视频 unlink 掉，
    // 那条消息从此只播得出一半 —— 与 v1.3.82 的贴纸误删是同一个坑，只是换了张表。
    $n += (int)DB::val(
        "SELECT COUNT(*) FROM messages WHERE id<>? AND type='rich' AND content LIKE ?",
        [$excludeMsgId, $needle]
    );
    // v1.3.82：还有一类引用者不在 messages 里 —— **收藏成贴纸的图**。
    // stickers 只存一个 URL，没有自己的文件，所以「这条图片消息没人用了」并不等于
    // 「这个文件没人用了」：只要有人收藏过，unlink 掉就是把他人的贴纸撕了，
    // 而且是永久撕 —— 破图没有任何恢复路径（原文件已经不在磁盘上）。
    // needle 直接复用：stickers.url 可能是绝对地址也可能是相对路径，`%/stem.%` 两种都命中。
    $n += (int)DB::val('SELECT COUNT(*) FROM stickers WHERE url LIKE ?', [$needle]);
    return $n;
}

/* ============================ 上传路由 ============================ */
/** 已登录才能上传（游客没有附件能力，与核心原有口径一致） */
$atGuard = function (array $ctx): bool {
    return ($ctx['actor']['kind'] ?? '') !== 'none';
};

Plugin::route('plugin_attachment_manager_upload', function (array $ctx) use ($atGuard) {
    if (!$atGuard($ctx)) Api::json(['ok' => false, 'msg' => '请先登录'], 403);
    if (empty($ctx['files']['file'])) Api::json(['ok' => false, 'msg' => '未接收到文件'], 400);
    $allowAtt = true; $attReason = '';
    Plugin::fire('attachment.guard', [&$allowAtt, &$attReason, $ctx['actor']]);
    if (!$allowAtt) Api::json(['ok' => false, 'msg' => $attReason !== '' ? $attReason : '当前等级无法上传图片'], 403);
    [$ok, $urlOrMsg] = owATStoreImage($ctx['files']['file'], $ctx['actor']);
    if (!$ok) Api::json(['ok' => false, 'msg' => $urlOrMsg], 400);
    Api::json(['ok' => true, 'url' => $urlOrMsg]);
});

Plugin::route('plugin_attachment_manager_upload_file', function (array $ctx) use ($atGuard) {
    if (!$atGuard($ctx)) Api::json(['ok' => false, 'msg' => '请先登录'], 403);
    if (empty($ctx['files']['file'])) Api::json(['ok' => false, 'msg' => '没有选择文件'], 400);
    if (!Sec::rateLimit('upload_file', $ctx['actor']['kind'] . ($ctx['actor']['id'] ?? '') . '|' . Sec::ip(), 60, 20)) {
        Api::json(['ok' => false, 'msg' => '上传过于频繁，请稍后再试'], 429);
    }
    // v1.2.42：附件闸门（等级信任插件按等级限制文件上传）。
    //   单独给「能否上传」一个钩子，而不是只靠 upload.maxsize 把上限压到 0 ——
    //   后者用户看到的会是「文件超过 0 MB 限制」这种莫名其妙的提示。
    $allowAtt = true; $attReason = '';
    Plugin::fire('attachment.guard', [&$allowAtt, &$attReason, $ctx['actor']]);
    if (!$allowAtt) Api::json(['ok' => false, 'msg' => $attReason !== '' ? $attReason : '当前等级无法上传文件'], 403);
    [$ok, $res] = owATStoreFile($ctx['files']['file'], $ctx['actor']);
    if (!$ok) Api::json(['ok' => false, 'msg' => $res], 400);
    Sec::log('upload_file', $ctx['actor']['nickname'], ['size' => $res['size'], 'ext' => $res['ext']]);
    // v1.2.42：上传成功事件（等级信任插件据此外挂「今日上传文件」任务）
    if (($ctx['actor']['kind'] ?? '') === 'user') {
        Plugin::fire('file.uploaded', [(int)$ctx['actor']['id'], 'file', (int)$res['size']]);
    }
    Api::json(['ok' => true, 'file' => $res]);
});

/* ---------- 头像上传（v1.3.11 从核心迁入） ----------
   为什么迁走：头像是「可换可不换」的可选功能，核心零上传入口更干净；
   插件停用时用户仍可在头像设置里选**生成式头像**，不会出现「换不了头像」的功能空洞。
   存储能力仍复用核心的 Upload::handle(kind=avatar)（内容哈希去重 + 真实 MIME 校验）。 */
function owATStoreAvatar(array $f, array $actor): array
{
    if (($actor['kind'] ?? '') !== 'user') return [false, '游客没有账号头像'];
    if (!owATEnabled()) return [false, '站点已关闭附件上传'];
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) return [false, '上传失败（错误码 ' . $err . '）'];
    $tmp = (string)($f['tmp_name'] ?? '');
    $max = owATMaxBytes($actor);
    if ($tmp !== '' && is_file($tmp) && (int)@filesize($tmp) > $max) {
        return [false, '头像超过 ' . round($max / 1048576, 1) . ' MB 限制'];
    }
    // v1.3.13：**扩展名规则同样作用于头像** —— 与图片/文件附件共用后台配置的
    // 白名单（管理员删掉 bmp，头像也就传不了 bmp）。核心 checkImage 还会再兜一层
    // 「真实图片类型 + 支持格式」，两处都过才落盘。
    $ext = strtolower((string)pathinfo((string)($f['name'] ?? ''), PATHINFO_EXTENSION));
    if ($ext === '') {
        // v1.3.30 修 bug：头像裁剪弹窗导出的是 canvas Blob，multipart 文件名恒为
        // "blob"（无扩展名）—— 不是伪造上传，是前端裁剪流程的正常产物。
        // 文件名取不到扩展时按**真实内容**推断（头像必为图片），推断不出再拒。
        $mimeExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
                    'image/webp' => 'webp', 'image/bmp' => 'bmp'];
        $ext = $mimeExt[owATRealMime($tmp)] ?? '';
    }
    if ($ext === '' || !in_array($ext, owATExts(), true)) {
        return [false, '头像格式不在允许列表内（' . implode(' / ', owATExts()) . '）'];
    }
    if ($tmp !== '' && is_file($tmp) && ($rl = owATRateTry($actor, (int)filesize($tmp))) !== null) return [false, $rl];
    // 压缩由 upload.image 钩子在 Upload::handle 内统一做
    return Upload::handle($f, 'avatar');
}

/**
 * 头像上传路由（v1.4.4 起分两种目标）。
 *
 * target=me（缺省，历史行为）：上传成功即写进 users 表 —— 核心自己不再保留任何头像写库入口。
 * target=room：**只存文件，谁的头像都不动**，把相对路径回给调用方，由它走 room_update 提交。
 *   加这一支的原因是这条路由原先无条件 Auth::setAvatar —— 于是前台「给群换头像」
 *   在上传那一下就把当前用户自己的头像换掉了（站方实测）。群头像最终落在 rooms 表，
 *   权限由 room_update 自己判（仅群主可改本群），这里代写用户头像属于越界副作用。
 */
Plugin::route('plugin_attachment_manager_upload_avatar', function (array $ctx) use ($atGuard) {
    if (!$atGuard($ctx)) Api::json(['ok' => false, 'msg' => '请先登录'], 403);
    if (empty($ctx['files']['file'])) Api::json(['ok' => false, 'msg' => '未接收到文件'], 400);
    if (($ctx['actor']['kind'] ?? '') !== 'user') Api::json(['ok' => false, 'msg' => '游客没有账号头像'], 403);
    $forRoom = (string)($_POST['target'] ?? '') === 'room';

    // 复用上传闸门（等级信任插件等按等级限制），kind 传 'avatar' 与历史口径一致
    $allow = true; $reason = '';
    Plugin::fire('upload.guard', [&$allow, &$reason, 'avatar', $ctx['actor']]);
    if (!$allow) Api::json(['ok' => false, 'msg' => $reason !== '' ? $reason : '当前等级无法上传头像'], 403);

    [$ok, $urlOrMsg] = owATStoreAvatar($ctx['files']['file'], $ctx['actor']);
    if (!$ok) Api::json(['ok' => false, 'msg' => $urlOrMsg], 400);

    if ($forRoom) {
        Sec::log('upload_room_avatar', $ctx['actor']['nickname'], ['path' => $urlOrMsg]);
        Api::json(['ok' => true, 'path' => $urlOrMsg, 'url' => Auth::avatarFileUrl($urlOrMsg),
                   'msg' => '群头像已上传']);
    }
    // 落库：上传成功即写入 users（核心只保留 avatar_save 接口给「生成式」用）
    [$ok2, $msg] = Auth::setAvatar($ctx['actor'], 'upload', $urlOrMsg);
    if (!$ok2) Api::json(['ok' => false, 'msg' => $msg], 400);

    Sec::log('upload_avatar', $ctx['actor']['nickname'], ['url' => $urlOrMsg]);
    Api::json(['ok' => true, 'path' => $urlOrMsg, 'url' => Auth::avatarFileUrl($urlOrMsg), 'msg' => '头像已更新']);
});

/* ============================ 后台配置路由 ============================ */
/* ---------- 后台页开关行（与核心 switchHtml / content-report 同款结构，
   admin.js 读配置后置 checked 再调 bindSwitches 同步视觉） ---------- */
function owATSwitchHtml(string $id, string $label, bool $on, string $hint = ''): string
{
    return '<div class="ow-switch-row">'
        . '<span class="ow-switch-label">' . htmlspecialchars($label, ENT_QUOTES) . '</span>'
        . '<input type="checkbox" class="ow-switch-input" id="' . htmlspecialchars($id, ENT_QUOTES) . '"' . ($on ? ' checked' : '') . '>'
        . '<label class="ow-switch' . ($on ? ' is-on' : '') . '" for="' . htmlspecialchars($id, ENT_QUOTES) . '"></label>'
        . ($hint !== '' ? '<p class="ow-switch-hint">' . htmlspecialchars($hint, ENT_QUOTES) . '</p>' : '')
        . '</div>';
}

/** 管理员鉴权：插件路由的公共守卫。
 *  ⚠️ 必须定义在**所有 use($amGuard) 之前** —— use() 捕获的是变量**值**，
 *     定义在后面会捕获到 null，调用时直接致命错误。 */
$amGuard = function (array $ctx): void {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
};

Plugin::route('plugin_attachment_manager_cfg_get', function (array $ctx) use ($amGuard) {
    $amGuard($ctx);
    Api::json([
        'ok' => true,
        'config' => owATConfig(),
        'active_exts' => owATExts(),
        'safe_exts' => array_keys(owATExtMime()),
        'webp_ready' => owATWebpReady(),   // v1.2.0：imagewebp() 实测可用性（后台展示）
    ]);
});

Plugin::route('plugin_attachment_manager_cfg_save', function (array $ctx) use ($amGuard) {
    $amGuard($ctx);
    $post = $ctx['post'];
    $cfg = owATSaveConfig([
        'enabled' => $post['enabled'] ?? '',
        'max_mb'  => $post['max_mb'] ?? '',
        'rate_mb' => $post['rate_mb'] ?? '',
        'exts'    => $post['exts'] ?? '',
        'compress'   => $post['compress'] ?? '',
        'compress_q' => $post['compress_q'] ?? '',
        // 复选框未勾选时浏览器不提交 → 缺省即 0
        'compress_avatar'  => $post['compress_avatar'] ?? '0',
        'compress_image'   => $post['compress_image'] ?? '0',
        'compress_sticker' => $post['compress_sticker'] ?? '0',
    ]);
    Api::json(['ok' => true, 'config' => $cfg, 'active_exts' => owATExts(), 'webp_ready' => owATWebpReady()]);
});

/**
 * 主程序未在页面中引入 ?action=assets&type=css，插件 CSS 不会自动加载。
 * 通过 page.head 钩子注入 <link> 标签，确保后台样式生效。
 */
Plugin::on('page.head', function () {
    echo '<link rel="stylesheet" href="?action=assets&type=css&am=' . OWLSGO_ASSET_VERSION . '">';
});

/**
 * 图片/文件按钮（v1.4.5 起走 chat.toolbar 钩子）：HTML 由服务端产出，
 * 核心 Plugin::collect('chat.toolbar') 拼进 #owToolFirst；点击仍绑在 chat.js。
 * 原先是核心留专用空锚点 #owAttachTools 让本插件往里 innerHTML。
 */
Plugin::on('chat.toolbar', function (): string {
    return '<input type="file" id="owFileInput" accept="image/*" multiple style="display:none">'
         . '<input type="file" id="owFileAttach" multiple style="display:none">'
         . '<button class="ow-icon-btn" id="owBtnImage" type="button" title="发送图片" aria-label="发送图片">'
         . ow_icon('image', 16) . '</button>'
         . '<button class="ow-icon-btn" id="owBtnFile" type="button" title="发送文件" aria-label="发送文件">'
         . ow_icon('paperclip', 16) . '</button>';
});

Plugin::asset('js', 'attachment-manager/chat.js');
// 前台样式（待发区 / 拖拽高亮 / 二选一弹窗）：跟 chat.js 一起登记，
// 由上面那条 page.head 的 <link> 合并输出 —— 样式写死在 index.css 里
// 会让插件停用时留下一堆没人用的规则（gif-stickers 在 v1.3.78 踩过同样的坑）。
Plugin::asset('css', 'attachment-manager/style.css');


/** 管理员鉴权：插件路由的公共守卫 */

/**
 * 跨驱动提取附件名用于搜索：file 类型取 JSON 的 name 字段，image 类型取 content 本身（URL）。
 * file 的 content 是 JSON，image 的 content 是纯 URL 字符串，直接 LIKE 匹配即可。
 */
function owAM_search_expr(): string
{
    switch (DB::driver()) {
        case 'mysql':
            return "CASE WHEN m.type='file' THEN JSON_UNQUOTE(JSON_EXTRACT(m.content, '$.name')) ELSE m.content END";
        case 'pgsql':
            return "CASE WHEN m.type='file' THEN (m.content::json)->>'name' ELSE m.content END";
        default: // sqlite
            return "CASE WHEN m.type='file' THEN json_extract(m.content, '$.name') ELSE m.content END";
    }
}

/** 把字节数格式化为易读字符串 */
function owAM_size_text(int $n): string
{
    if ($n < 1024) return $n . ' B';
    if ($n < 1048576) return round($n / 1024, 1) . ' KB';
    return round($n / 1048576, 1) . ' MB';
}

/**
 * 将图片消息的 content（URL）解析为物理绝对路径。
 * 兼容两种格式：
 *   - 相对：uploads/image/xxx.png
 *   - 绝对：https://host/uploads/image/xxx.png
 */
function owAM_image_abs(string $url): ?string
{
    $path = parse_url($url, PHP_URL_PATH);
    if ($path === null || $path === '') return null;
    $rel = preg_replace('#^/?uploads/#', '', $path);
    if ($rel === $path || $rel === '' || strpos($rel, '..') !== false) return null;
    $cfg = require dirname(__DIR__, 2) . '/core/config.php';
    $abs = $cfg['upload']['dir'] . '/' . $rel;
    return is_file($abs) ? $abs : null;
}

/* ---------- 后台页面（HTML 注入 #owAdminMain，交互函数见 admin.js） ---------- */
Plugin::adminPage('attachment-manager', '附件上传', function () {
    return '<h2>附件上传</h2>'
        . '<p class="ow-admin-desc">配置聊天附件（图片 / 文件）的上传规则，并管理已上传的文件。'
        . '停用本插件后，聊天输入框的图片与文件按钮会一并消失。</p>'
        // v1.2.41：以下三项由本插件接管（原先在核心「站点设置」里）
        . '<div class="ow-card">'
        . '<div class="ow-form-row">'
        . '<div class="ow-form-item" style="min-width:140px"><label>允许上传附件</label>'
        . '<select class="ow-input" id="owAtEnabled"><option value="1">允许</option><option value="0">禁止</option></select></div>'
        . '<div class="ow-form-item" style="min-width:140px"><label>单文件大小上限(MB)</label>'
        . '<input class="ow-input" id="owAtMaxMb" type="number" min="1" max="1024" value="10"></div>'
        . '<div class="ow-form-item" style="min-width:150px"><label>每分钟上传量上限(MB)</label>'
        . '<input class="ow-input" id="owAtRateMb" type="number" min="1" max="10240" value="60">'
        . '<p style="font-size:12px;color:#5C5C5C;margin-top:4px">按用户滚动 60 秒窗口累计，图片/文件/头像共用。'
        . '默认 60 = 单文件上限的 6 倍：正常连发够用，脚本刷盘会撞限。</p></div>'
        . '</div>'
        . '<div class="ow-form-item"><label>允许的文件扩展名</label>'
        . '<input class="ow-input" id="owAtExts" placeholder="zip,pdf,txt,docx">'
        . '<p style="font-size:12px;color:var(--ow-text-sub);margin-top:4px">逗号分隔。只有安全类型表内登记过的扩展名才会生效；'
        . 'svg / php / html 等可执行或可内嵌脚本的类型不予登记（即使填了也不会放行）。'
        . '当前生效：<span id="owAtActive">-</span></p></div>'
        // v1.2.0：图片压缩（WebP）。v1.3.1：细分开关改通用开关行（与核心 switchHtml 同款，
        // admin.js bindSwitches 同步视觉）；「imagewebp() 支持」状态块与底部一句话移除 ——
        // 环境不支持时保存校验会 toast 并把开关关回，不需要常驻展示。
        // 说明文字放 .ow-form-item / .ow-switch-row 内的小字 <p> → 核心 OwTip 自动收进 ⓘ 气泡。
        . '<div class="ow-form-row">'
        . '<div class="ow-form-item" style="min-width:160px"><label>图片压缩</label>'
        . '<select class="ow-input" id="owAtCompress"><option value="0">关闭（原图直存）</option><option value="1">开启（转 WebP）</option></select>'
        . '<p style="font-size:12px;color:var(--ow-text-sub)">需 PHP GD 编译 WebP 支持；本机不支持时开关会被自动关回。</p></div>'
        . '<div class="ow-form-item" style="min-width:120px"><label>压缩质量(1-100)</label>'
        . '<input class="ow-input" id="owAtCompressQ" type="number" min="1" max="100" value="80"></div>'
        . '</div>'
        . '<div id="owAtcSwitches">'
        . owATSwitchHtml('owAtcAvatar', '压缩头像', owATConfig()['compress_avatar'] === '1')
        . owATSwitchHtml('owAtcImage', '压缩消息图片', owATConfig()['compress_image'] === '1')
        . owATSwitchHtml('owAtcSticker', '压缩贴纸上传', owATConfig()['compress_sticker'] === '1')
        . '</div>'
        . '<button class="ow-btn ow-btn-primary" onclick="OwAT.saveCfg()">保存配置</button>'
        . '</div>'
        . '<div class="ow-card">'
        . '<div class="ow-form-row">'
        . '<div class="ow-form-item" style="flex:1;min-width:160px"><label>文件名包含</label>'
        . '<input class="ow-input" id="owAMQ" placeholder="输入文件名关键词" onkeydown="if(event.key===\'Enter\')OwAM.load(1)"></div>'
        . '<div class="ow-form-item" style="min-width:120px"><label>房间ID</label>'
        . '<input class="ow-input" id="owAMRoom" type="number" min="0" placeholder="0=全部" value="0" onkeydown="if(event.key===\'Enter\')OwAM.load(1)"></div>'
        . '<button class="ow-btn ow-btn-primary" onclick="OwAM.load(1)">搜索</button>'
        . '<button class="ow-btn ow-btn-ghost" onclick="OwAM.resetFilter()">重置</button>'
        . '</div></div>'
        . '<div class="ow-card">'
        . '<div style="margin-bottom:10px">'
        . '<button class="ow-btn ow-btn-danger" id="owAMBatchDel" onclick="OwAM.batchDelete()" disabled>批量删除</button>'
        . '<span id="owAMStat" style="margin-left:12px;color:var(--ow-text-sub,#999);font-size:12px"></span>'
        . '</div>'
        . '<div style="overflow-x:auto;-webkit-overflow-scrolling:touch">'
        . '<table class="ow-table" id="owAMTable">'
        . '<tr><th style="width:32px"><input type="checkbox" id="owAMCheckAll" onchange="OwAM.toggleAll(this)"></th>'
        . '<th>类型</th><th>文件名</th><th>大小</th><th>消息</th><th>上传者</th><th>上传时间</th><th>操作</th></tr>'
        . '</table>'
        . '</div>'
        . '<div id="owAMList" style="display:none"></div>'
        . '<div id="HAMPager" style="margin-top:12px"></div>'
        . '</div>';
});

/* ---------- 群聊列表（过滤下拉） ---------- */
Plugin::route('plugin_attachment_manager_rooms', function (array $ctx) use ($amGuard) {
    $amGuard($ctx);
    $rows = DB::all('SELECT id, name FROM rooms ORDER BY id');
    Api::json(['ok' => true, 'data' => $rows]);
});

/* ---------- 附件列表（分页 + 群聊过滤 + 文件名搜索） ---------- */
Plugin::route('plugin_attachment_manager_list', function (array $ctx) use ($amGuard) {
    $amGuard($ctx);
    $post = $ctx['post'];
    $page = max(1, (int)($post['page'] ?? 1));
    $psize = max(1, min(100, (int)($post['psize'] ?? 20)));
    $roomId = (int)($post['room_id'] ?? 0);
    $keyword = trim((string)($post['q'] ?? ''));

    $where = "WHERE m.type IN ('file','image')";
    $args = [];
    if ($roomId > 0) { $where .= ' AND m.room_id=?'; $args[] = $roomId; }
    if ($keyword !== '') {
        $where .= ' AND ' . owAM_search_expr() . ' LIKE ?';
        $args[] = '%' . $keyword . '%';
    }

    $total = (int)DB::val("SELECT COUNT(*) FROM messages m $where", $args);
    $offset = ($page - 1) * $psize;

    $sql = "SELECT m.id, m.type, m.room_id, m.nickname, m.created_at, m.content,
                   m.user_id, m.guest_id, m.to_user_id, m.to_guest_id,
                   r.name AS room_name
            FROM messages m
            LEFT JOIN rooms r ON r.id = m.room_id
            $where
            ORDER BY m.id DESC
            LIMIT $psize OFFSET $offset";
    $rows = DB::all($sql, $args);

    $list = [];
    foreach ($rows as $r) {
        $type = $r['type'];
        if ($type === 'file') {
            $info = json_decode((string)$r['content'], true);
            $name = is_array($info) ? (string)($info['name'] ?? '文件') : '文件';
            $ext  = is_array($info) ? strtoupper((string)($info['ext'] ?? '')) : '';
            $size = is_array($info) ? (int)($info['size'] ?? 0) : 0;
            $path = is_array($info) ? (string)($info['path'] ?? '') : '';
        } else {
            // image: content 是 URL 字符串，原始文件名未存储，只能显示存储后的文件名
            $url  = (string)$r['content'];
            $name = basename($url);
            $ext  = strtoupper(pathinfo($url, PATHINFO_EXTENSION));
            $size = 0;
            $path = '';
        }
        // 消息归属：群聊显示房间名，私聊显示发送方→接收方
        $isDm = (int)$r['room_id'] === 0
            && ((int)($r['to_user_id'] ?? 0) > 0 || (int)($r['to_guest_id'] ?? 0) > 0);
        if ($isDm) {
            $fromId = (int)$r['user_id'] > 0
                ? '用户' . (int)$r['user_id']
                : '游客' . (int)$r['guest_id'];
            $toId = (int)$r['to_user_id'] > 0
                ? '用户' . (int)$r['to_user_id']
                : '游客' . (int)$r['to_guest_id'];
            $scopeText = $fromId . ' → ' . $toId;
        } else {
            $scopeText = $r['room_name'] !== null ? (string)$r['room_name'] : '（房间已删除）';
        }
        $list[] = [
            'id' => (int)$r['id'],
            'type' => $type,
            'type_cn' => $type === 'file' ? '文件' : '图片',
            'name' => $name,
            'ext'  => $ext,
            'size' => $size,
            'size_text' => $size > 0 ? owAM_size_text($size) : '-',
            'path' => $path,
            'room_id' => (int)$r['room_id'],
            'scope_text' => $scopeText,
            'nickname' => (string)$r['nickname'],
            'created_at' => (int)$r['created_at'],
            'time_text' => date('Y-m-d H:i', (int)$r['created_at']),
        ];
    }

    Api::json([
        'ok' => true,
        'data' => $list,
        'total' => $total,
        'page' => $page,
        'psize' => $psize,
        'pages' => $psize > 0 ? (int)ceil($total / $psize) : 1,
    ]);
});

/* ---------- 批量删除（消息记录 + 物理文件） ---------- */
Plugin::route('plugin_attachment_manager_delete', function (array $ctx) use ($amGuard) {
    $amGuard($ctx);
    $ids = $ctx['post']['ids'] ?? '';
    if (is_array($ids)) {
        $idList = $ids;
    } else {
        $idList = preg_split('/[\s,]+/', (string)$ids) ?: [];
    }
    $idList = array_values(array_filter(array_map('intval', $idList), function ($v) { return $v > 0; }));
    if (!$idList) Api::json(['ok' => false, 'msg' => '未选择任何附件']);

    $deleted = 0;
    $keptFiles = 0;   // 因仍被其它消息引用而保留的物理文件数
    // v1.5.20：一次取回待删消息，别逐条 SELECT。
    // N+1 例外：引用计数仍按文件逐个查（一次后台操作通常只有一页附件，且要同时核对
    // messages 三种类型 + stickers；批量 LIKE 扫描留作后续优化）。
    $msgMap = [];
    foreach (array_chunk($idList, 200) as $chunk) {
        [$msgIn, $msgInArgs] = DB::in('id', $chunk);
        foreach (DB::all("SELECT * FROM messages WHERE $msgIn", $msgInArgs) as $row) {
            $msgMap[(int)$row['id']] = $row;
        }
    }
    foreach ($idList as $mid) {
        $msg = $msgMap[$mid] ?? null;
        if (!$msg) continue;
        $type = $msg['type'];
        // v1.2.50：先算引用（要排除本条自己），再决定要不要删物理文件。
        // 顺序很重要 —— 先 unlink 再查引用就无从查了。
        $refKey = $type === 'file'
            ? (string)((json_decode((string)$msg['content'], true)['path'] ?? ''))
            : (string)$msg['content'];
        $stillUsed = $refKey !== '' && owAM_refCount($refKey, (int)$mid) > 0;

        if (!$stillUsed) {
            if ($type === 'file') {
                $info = json_decode((string)$msg['content'], true);
                if (is_array($info) && !empty($info['path'])) {
                    $abs = Upload::fileAbs((string)$info['path']);
                    if ($abs && is_file($abs)) @unlink($abs);
                }
            } elseif ($type === 'image') {
                $abs = owAM_image_abs((string)$msg['content']);
                if ($abs) @unlink($abs);
            }
        } else {
            $keptFiles++;
        }
        DB::run('DELETE FROM messages WHERE id=?', [$mid]);
        $deleted++;
    }
    Sec::log('admin_attachment_delete', $ctx['actor']['nickname'],
        ['count' => $deleted, 'kept_shared' => $keptFiles]);
    $msg = "已删除 $deleted 个附件";
    // 去重后一条消息删掉、文件可能仍被别的消息用着 —— 必须告诉管理员，别让人以为文件丢了
    if ($keptFiles > 0) $msg .= "（其中 $keptFiles 个文件因仍被其它消息引用已保留）";
    Api::json(['ok' => true, 'msg' => $msg, 'deleted' => $deleted]);
});
Plugin::sensitive('plugin_attachment_manager_delete');   // 删除附件与消息记录：敏感（v1.0.91）

/* ---------- 管理员下载（不依赖房间状态，直接校验文件归属） ---------- */
Plugin::route('plugin_attachment_manager_download', function (array $ctx) use ($amGuard) {
    $amGuard($ctx);
    $mid = (int)($_GET['id'] ?? 0);
    $msg = DB::one('SELECT * FROM messages WHERE id=?', [$mid]);
    if (!$msg) Api::json(['ok' => false, 'msg' => '文件不存在'], 404);
    $type = $msg['type'];
    if ($type === 'file') {
        $info = json_decode((string)$msg['content'], true);
        $abs = Upload::fileAbs((string)($info['path'] ?? ''));
        $name = (string)($info['name'] ?? 'file');
    } else {
        // image: content 是 URL（相对或绝对），用辅助函数解析物理路径
        $url = (string)$msg['content'];
        $abs = owAM_image_abs($url);
        $name = basename(parse_url($url, PHP_URL_PATH) ?: $url);
    }
    if (!$abs || !is_file($abs)) Api::json(['ok' => false, 'msg' => '物理文件不存在'], 404);
    $name = preg_replace('/[\r\n"]/', '', $name);
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    readfile($abs);
    exit;
});

// 注册后台交互脚本与样式（由 ?action=assets 合并输出，仅后台页面引入）
Plugin::asset('css', 'attachment-manager/admin.css');
Plugin::asset('js', 'attachment-manager/admin.js');
