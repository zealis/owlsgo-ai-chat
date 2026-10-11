<?php
/**
 * 上传与媒体：头像 / 贴纸 / 图片消息一律本地存储、不走图床。
 * 核心自己一个字节都不压（贴纸与图片消息原图直存；头像例外，会居中裁剪缩放为正方形）。
 * ⚠️ 但 handle() 在落盘前会给插件一次 upload.image 钩子 —— 装了「附件上传」插件且开了
 * 对应开关时，静态图在那里被转成 WebP（动图 GIF 明确跳过，压了就没动画了）。
 * 所以「页面上看到的图是不是原图」取决于站点装没装那个插件，别按本注释想当然。
 */
class Upload
{
    private static array $cfg = [];

    /**
     * 头像统一尺寸（正方形边长px）：居中裁剪 + 缩放后输出。
     * 前端已提供滑块手动缩放裁剪并按此尺寸导出；服务端再裁一次作为兜底
     * （直接调用接口、或前端裁剪失败时仍保证方形）。
     * 目的：① 长方形原图在圆形容器里会被拉成椭圆（老浏览器不支持 object-fit，
     * 靠 CSS 修不住）→ 直接在服务端裁成方形，任何浏览器下都是正圆；
     * ② 避免几 MB 的大图当头像反复传输。
     * 仅作用于头像；贴纸与图片消息仍按 v1.0.35 约定原图直存，不做任何压缩。
     */
    private const AVATAR_SIZE = 100;

    /**
     * v1.2.50：内容寻址存储（秒传 / 去重）。
     *
     * 旧实现用 `date()+random_bytes()` 命名 —— 同��张图、同一个文件被上传N 次
     * 就在磁盘上留N 份完全相同的副本。头像尤其夸张：同一个默认头像、
     * 同一批用户用同一张图做头像，每人存一份。
     *
     * 改为「文件名 = 内容 sha256 的前 N 位」后：
     * · 同一份内容第二次上传 → 文件已存在，**直接返回原URL，一个字节都不再写**；
     * · 文件名不再含随机数，无法猜到（与原来一样安全）；
     * · 不需要额外的数据表 —— 文件存在与否本身就是「这份内容是否已存」的答案。
     *
     * 保留随机成分是为了兼容既有磁盘上的随机名文件（存量消息仍指向它们），
     * 新增内容才走哈希命名。
     *
     * ⚠️ 为什么按 kind 分目录而不混放：
     *   头像会经squareAvatar() 重编码（转 jpg、缩到 100px），
     *   同一原图在不同 kind 下产物不同；贴纸只允许收藏 image/avatar 下的图。
     *   分目录后各自的哈希空间独立，互不干扰。
     */
    private const HASH_PREFIX_LEN = 32;      // sha256 取前 32 位十六进制
    private const HASH_SUBDIR   = true;      // 再按前2 位拆一层子目录，避免单目录文件过多

    public static function init(array $cfg): void
    {
        self::$cfg = $cfg['upload'];
        @mkdir(self::$cfg['dir'], 0775, true);
        foreach (['avatar', 'sticker', 'image', 'file'] as $d) @mkdir(self::$cfg['dir'] . '/' . $d, 0775, true);
    }

    /**
     * v1.2.50：按内容哈希算出**相对**存储路径与目标绝对路径。
     *
     * @return array{0:string,1:string} [relPath, absPath]
     *   relPath 形如 `image/1a/1a3f...9c.jpg`（相对 uploads/，可直接拼 URL）
     */
    private static function hashTarget(string $kind, string $ext, string $sha): array
    {
        $hash = substr($sha, 0, self::HASH_PREFIX_LEN);
        $sub  = self::HASH_SUBDIR ? substr($hash, 0, 2) . '/' : '';
        $name = $hash . '.' . $ext;
        return [$kind . '/' . $sub . $name, self::$cfg['dir'] . '/' . $kind . '/' . $sub . $name];
    }

    /**
     * 把已上传的临时文件落到「哈希路径」；已存在同内容则复用，不重复写盘。
     *
     * @param string $kind  avatar/sticker/image/file
     * @param string $ext  不含点的扩展名
     * @param string $srcTmp 待落盘的源文件（通常是 $_FILES 的 tmp_name）
     * @param bool   $move 是否用 move_uploaded_file（HTTP 上传用 true）
     * @return array{0:bool,1:string} [是否成功, relPath]
     */
    private static function putByHash(string $kind, string $ext, string $srcTmp, bool $move = true): array
    {
        $sha = @hash_file('sha256', $srcTmp);
        if ($sha === false || $sha === '') return [false, ''];

        [$rel, $abs] = self::hashTarget($kind, $ext, $sha);

        // 已存在 → 复用（这就是「重复文件不上传」的全部逻辑，没有额外的表要查）
        if (is_file($abs)) {
            // ⚠️ 命中复用时必须清掉源临时文件：上传落地的临时文件在请求结束后由 PHP 清理，
            //   但 CLI / 长驻进程下会一直留着，长期累积占磁盘。
            //   （HTTP 上传的 tmp_name 由 PHP 自己管，但 plugins 传的是真实路径，必须清。）
            @unlink($srcTmp);
            return [true, $rel];
        }

        $dir = dirname($abs);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return [false, ''];

        if ($move) {
            // ⚠️ move_uploaded_file 对「非 HTTP 上传的临时文件」会失败，
            //   所以调用方要按来源决定传不传 true（见 local()/插件侧）。
            if (!@move_uploaded_file($srcTmp, $abs)) return [false, ''];
        } else {
            if (!@copy($srcTmp, $abs)) return [false, ''];
            @unlink($srcTmp);
        }
        @chmod($abs, 0644);
        return [true, $rel];
    }

    private static function checkImage(array $f): array
    {
        if ($f['error'] !== UPLOAD_ERR_OK) return [false, '上传失败（错误码 ' . $f['error'] . '）'];
        if ($f['size'] > self::$cfg['max_size']) return [false, '文件超过大小限制'];
        $info = @getimagesize($f['tmp_name']);
        if (!$info) return [false, '仅支持图片文件'];
        // v1.3.13：补齐一般图片格式（用户要求）。SVG/ICO/TIFF 不在其中：
        //   SVG 可内嵌脚本（XSS 面），ICO/TIFF 浏览器不能直接当 <img> 显示。
        $ext = [
            IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp', IMAGETYPE_BMP => 'bmp',
        ][$info[2]] ?? null;
        if (!$ext) return [false, '仅支持 jpg/jpeg/png/gif/webp/bmp'];
        return [true, $ext];
    }

    /** 本地存储：头像 / 贴纸 / 图片消息（原图直存，不压缩）
     *  v1.2.50：改为内容哈希命名，重复内容直接复用已有文件。 */
    public static function local(array $f, string $kind): array
    {
        [$ok, $extOrMsg] = self::checkImage($f);
        if (!$ok) return [false, $extOrMsg];
        if (!in_array($kind, ['avatar', 'sticker', 'image'], true)) return [false, '非法类型'];

        // 头像要先裁剪再定哈希 —— 裁剪后的产物才是最终落盘内容，
        // 对**原图**取哈希会导致「两张不同的原图裁出同一头像却算出不同哈希」。
        if ($kind === 'avatar') return self::storeAvatar($f, $extOrMsg);

        // v1.3.29：附件插件的 WebP 压缩产物是**自建临时文件**（tempnam），
        // move_uploaded_file 只认 HTTP POST 上传的文件、对它会恒失败 ——
        // 按 is_uploaded_file 分流：HTTP 上传用 move，其余用 copy+unlink
        // （putByHash 的 $move 参数本来就是这个语义，此前调用方一直硬编码 true）。
        [$ok2, $rel] = self::putByHash($kind, $extOrMsg, (string)$f['tmp_name'], @is_uploaded_file((string)$f['tmp_name']));
        if (!$ok2) return [false, '保存失败'];
        // 手动配置了「固定网站地址」才返回绝对 URL（自动识别不参与，避免误判）
        return [true, ow_abs_url(self::$cfg['url'] . '/' . $rel, true)];
    }

    /**
     * 头像落盘：先裁剪成正方形 jpg，再按**裁剪后内容**的哈希命名去重。
     * 同一张脸/同一张图做头像 → 全站只存一份。
     */
    private static function storeAvatar(array $f, string $ext): array
    {
        // 先原样落一份到临时名（裁剪要读它）。
        // ⚠️ 不在这里对原图取哈希：裁剪后的产物才是最终落盘内容，
        //   对原图取哈希会导致「两张不同原图裁出同一头像却算出不同哈希」，去重就失效了。
        $stg  = self::$cfg['dir'] . '/avatar/.tmp_' . bin2hex(random_bytes(6)) . '.' . $ext;
        // v1.3.29：同 local() —— 压缩产物不是 HTTP 上传的文件，move_uploaded_file 会拒；
        // 按来源分流（自建临时文件用 copy 后删源）。
        $src = (string)$f['tmp_name'];
        if (@is_uploaded_file($src)) {
            if (!@move_uploaded_file($src, $stg)) return [false, '保存失败'];
        } elseif (!@copy($src, $stg)) {
            return [false, '保存失败'];
        } else {
            @unlink($src);
        }

        $sq = self::squareAvatar($stg);
        if ($sq === null) {
            // 无 GD / 裁剪失败 → squareAvatar 没删 $stg，用它按原图哈希落盘（putByHash 内部会清源文件）
            [$ok, $rel] = self::putByHash('avatar', $ext, $stg, false);
            @unlink($stg);   // 兜底：命中复用时 putByHash 已清，这里再清一次无害
            if (!$ok) return [false, '保存失败'];
            return [true, ow_abs_url(self::$cfg['url'] . '/' . $rel, true)];
        }

        // 裁剪产物在 .sq_xxx.jpg（源文件已被 squareAvatar 清掉）：对它取哈希 → 落成最终名字
        $shaSq = @hash_file('sha256', $sq);
        if ($shaSq === false || $shaSq === '') { @unlink($sq); return [false, '保存失败']; }
        [$rel, $abs] = self::hashTarget('avatar', 'jpg', $shaSq);
        $dir = dirname($abs);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) { @unlink($sq); return [false, '保存失败']; }
        if (is_file($abs)) {
            @unlink($sq);      // 已存在同一头像 → 丢弃这份，全站引用同一个文件
        } elseif (!@rename($sq, $abs)) {
            @unlink($sq);
            return [false, '保存失败'];
        } else {
            @chmod($abs, 0644);
        }
        return [true, ow_abs_url(self::$cfg['url'] . '/' . $rel, true)];
    }

    /**
     * 头像裁剪缩放：居中裁成正方形 → 缩放至 AVATAR_SIZE → 统一输出 jpg。
     *
     * @param string $srcPath 待裁剪的源文件绝对路径
     * @return string|null 成功返回**产物绝对路径**，失败（无 GD / 非图片）返回 null
     */
    private static function squareAvatar(string $srcPath): ?string
    {
        // ⚠️ 无 GD 时**直接返回，不能碰 $srcPath** —— 调用方要拿它做「原图按哈希存」的兜底，
        //   这里删掉就等于用户传不上去。
        if (!function_exists('imagecreatefromstring')) return null;
        $raw = @file_get_contents($srcPath);
        $src = $raw === false ? false : @imagecreatefromstring($raw);
        unset($raw);
        if (!$src) return null;

        $w = imagesx($src); $h = imagesy($src);
        $side = min($w, $h);                                  // 以短边为正方形边长
        $sx = (int)(($w - $side) / 2);                         // 居中裁剪起点
        $sy = (int)(($h - $side) / 2);
        $size = self::AVATAR_SIZE;

        $dst = imagecreatetruecolor($size, $size);
        // 白底填充：PNG/WebP 透明区域转 jpg 时不会变黑
        imagefilledrectangle($dst, 0, 0, $size, $size, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $size, $size, $side, $side);
        imagedestroy($src);

        $out = self::$cfg['dir'] . '/avatar/.sq_' . bin2hex(random_bytes(6)) . '.jpg';
        $ok = imagejpeg($dst, $out, 90);
        imagedestroy($dst);
        // 裁剪成功后才删源临时文件（失败时留给调用方做兜底）
        if ($ok) @unlink($srcPath);
        return $ok ? $out : null;
    }

    /**
     * 通用文件落盘（附件文件用）：内容哈希命名 + 自动去重。
     * 与 local() 的区别：不校验图片、扩展名与 MIME 由调用方先判好。
     *
     * @return array{0:bool,1:string} [是否成功, relPath形如 `file/1a/1a3f...9c.zip`]
     */
    public static function storeFile(string $srcTmp, string $ext): array
    {
        $ext = strtolower(preg_replace('/[^a-z0-9]/i', '', $ext) ?: '');
        if ($ext === '') return [false, ''];
        [$ok, $rel] = self::putByHash('file', $ext, $srcTmp, false);
        return [$ok, $rel];
    }

    /**
     * 统一上传入口：全部走本地原样存储（不压缩、不转码、不转发图床）
     * 例外：头像会居中裁剪缩放为正方形（见 squareAvatar）
     *
     * v1.3.31：落盘前给插件一次改写机会（upload.image 钩子）——
     * 附件上传插件在这里做 WebP 压缩。为什么钩子必须在核心而不是插件路由里：
     * 贴纸上传走核心 case 'upload'（不经插件），只有 handle 是
     * avatar/image/sticker 三类共同的入口，细分开关才能全覆盖。
     */
    public static function handle(array $f, string $kind): array
    {
        if (class_exists('Plugin')) Plugin::fire('upload.image', [&$f, $kind]);
        return self::local($f, $kind);
    }


    /**
     * 由消息里的相对路径解析出可下载的绝对路径（阻断路径穿越）
     * @return string|null
     */
    public static function fileAbs(string $rel): ?string
    {
        $rel = str_replace('\\', '/', $rel);
        if ($rel === '' || strpos($rel, '..') !== false) return null;
        // v1.2.50：允许哈希命名的两级子目录（file/1a/1a3f....zip）。
        //   仍只认 file/ 前缀 + 安全的字符集，杜绝穿越与任意文件读取。
        if (!preg_match('#^file/[A-Za-z0-9_\-./]+$#', $rel)) return null;
        $abs = realpath(self::$cfg['dir'] . '/' . $rel);
        $base = realpath(self::$cfg['dir']);
        if ($abs === false || $base === false || strpos($abs, $base) !== 0) return null;
        return is_file($abs) ? $abs : null;
    }
    public static function addSticker(array $actor, string $url): array
    {
        if ($actor['kind'] === 'none') return [false, '请先登录'];
        // v1.0.100：仅允许收藏本站上传的图片（uploads/ 下的随机文件名），
        // 杜绝收藏任意外站 URL 引入追踪图片 / 不可控内容。
        // v1.2.50：文件名改为内容哈希且可能带一层子目录（image/1a/1a3f...png），
        //   正则相应放宽到允许 `<kind>/<2位>/<哈希>.<ext>`，但仍锁定在
        //   avatar|sticker|image 三类目录 + 纯十六进制/扩展名字符集。
        $base = rtrim(ow_site_url(true), '/');
        $rel = ltrim($url);
        if ($base !== '' && stripos($rel, $base . '/') === 0) $rel = ltrim(substr($rel, strlen($base) + 1));
        if (!preg_match('#^uploads/(avatar|sticker|image)/[A-Za-z0-9_\-]+(/[A-Za-z0-9_\-]+)?\.(jpg|jpeg|png|gif|webp|bmp)$#i', $rel)) {
            // v1.0.100 起这条限制只对本站上传的文件开口子一次：明确集成了图源的插件
            // （sticker.allow 钩子）。核心自己不认识任何外站域名，没装表情包插件的站点
            // 行为与之前完全一致。
            // 为什么放行外链而不是「下载到本站」：KLIPY 的《Integration Requirements》
            // 要求媒体留在他们的地址上、不得存储/镜像/转存 —— 转存才是违约，
            // 存地址是照它的条款用。代价由用户承担，插件端必须把「链接随时会失效」说清楚。
            $allow = '';
            if (class_exists('Plugin')) Plugin::fire('sticker.allow', [&$allow, $url, $actor]);
            if ($allow !== '1') return [false, '仅支持收藏本站上传的图片'];
            // 插件放行后，核心仍守住最低限度的形状：这是会在别人浏览器里发起请求的地址
            if (mb_strlen($url) > 500 || !preg_match('#^https://[^\s"\'<>\\\\]+$#i', $url)) {
                return [false, '收藏的图片地址必须是不含空白的 https 链接'];
            }
        }
        $owner = $actor['kind'] . $actor['id'];
        // v1.3.30：收藏上限开放给插件（sticker.quota 钩子）—— 等级信任插件按等级
        // 分档收紧（Lv.3=3 张 … Lv.60=999 张）；无插件响应时保持原全局 100 张。
        // $limit <= 0 表示「未解锁」，直接拒。
        $limit = 100;
        if (class_exists('Plugin')) Plugin::fire('sticker.quota', [&$limit, $actor]);
        $limit = (int)$limit;
        if ($limit <= 0) return [false, '贴纸功能未解锁'];
        if ((int)DB::val('SELECT COUNT(*) FROM stickers WHERE owner_key=?', [$owner]) >= $limit) {
            return [false, '贴纸收藏已满（' . $limit . ' 张）'];
        }
        DB::insert('stickers', ['owner_key' => $owner, 'url' => $url, 'created_at' => time()]);
        return [true, '已收藏'];
    }

    public static function stickers(array $actor): array
    {
        if ($actor['kind'] === 'none') return [];
        return array_map(
            fn($r) => ['id' => (int)$r['id'], 'url' => $r['url']],
            DB::all('SELECT * FROM stickers WHERE owner_key=? ORDER BY id DESC LIMIT 100', [$actor['kind'] . $actor['id']])
        );
    }

    public static function delSticker(array $actor, int $id): array
    {
        DB::run('DELETE FROM stickers WHERE id=? AND owner_key=?', [$id, $actor['kind'] . $actor['id']]);
        return [true, '已删除'];
    }
}
