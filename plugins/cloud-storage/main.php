<?php
/**
 * 云存储插件（v1.0.0）
 *
 * 把站点的上传文件搬到对象存储，经 v1.5.17 核心的四个接管钩子完成：
 *   · upload.stored —— 落盘后把文件搬去云端，并按需改写返回值（URL / 云端标识）；
 *   · media.allow   —— 消息发送时放行插件担保的云端地址 / 标识；
 *   · file.download —— 附件下载时代理拉取云端对象；
 *   · avatar.url    —— 头像渲染时把本地路径映射到云端地址。
 * 停用插件即整体回落本地存储：库里存的头像路径、本地双备份文件都原样可用。
 *
 * 后端（签名算法均按各厂商官方文档实现，见各类注释）：
 *   oss   阿里云 OSS      —— V1 签名（HMAC-SHA1）
 *   cos   腾讯云 COS      —— XML API 永久密钥（q-sign-* 查询参数）
 *   qiniu 七牛云 Kodo     —— 上传凭证 + 表单上传
 *   upyun 又拍云          —— 表单 API（policy + signature）
 *   s3    AWS S3 / 兼容   —— SigV4（覆盖 MinIO、华为云 OBS 等）
 *
 * 多空间：后台可建多个「存储空间」，同一时刻只有一个生效；新上传走生效空间，
 * 历史文件按各自空间继续寻址（消息里存的是完整 URL 或带空间号的标识）。
 *
 * 零依赖：不引入任何 Composer 包 / SDK，签名与传输全部手写（curl）。
 */
if (!defined('OWLSGO_VERSION')) exit;   // 禁止直接 HTTP 访问本文件

/* ============================ 建表（schema 闸门） ============================ */

function owCSBoot(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    if (DB::setting('owcs_schema', '') === '1') {
        try {
            DB::val('SELECT id FROM plugin_cloud_storage_space LIMIT 1');
            return;
        } catch (Throwable $e) { /* 表没了：往下重新建 */ }
    }

    $id = DB::autoId();

    // 存储空间：一行一个空间，is_active 唯一生效
    DB::run("CREATE TABLE IF NOT EXISTS plugin_cloud_storage_space (
        id {$id},
        name VARCHAR(60) NOT NULL DEFAULT '',
        type VARCHAR(12) NOT NULL DEFAULT 'oss',
        config " . DB::textCol('config') . ",
        is_active INT NOT NULL DEFAULT 0,
        file_count INT NOT NULL DEFAULT 0,
        total_size INT NOT NULL DEFAULT 0,
        created_at INT NOT NULL DEFAULT 0
    )");
    DB::createIndex('owcs_space_active', 'plugin_cloud_storage_space', 'is_active');

    // 文件映射：本地 rel → 云端 key（头像改写、删除同步、迁移去重都靠它）
    DB::run("CREATE TABLE IF NOT EXISTS plugin_cloud_storage_file (
        rel VARCHAR(190) PRIMARY KEY,
        space_id INT NOT NULL DEFAULT 0,
        key VARCHAR(190) NOT NULL DEFAULT '',
        size INT NOT NULL DEFAULT 0,
        created_at INT NOT NULL DEFAULT 0
    )");
    DB::createIndex('owcs_file_space', 'plugin_cloud_storage_file', 'space_id');
    DB::createIndex('owcs_file_key', 'plugin_cloud_storage_file', 'space_id,key');

    DB::setSetting('owcs_schema', '1');
}
owCSBoot();   // 顶层建表是 PLUGIN.md 允许的副作用（与 red-packet 同做法）

/* ============================ 配置读写 ============================ */

/** 插件选项（settings 表，owcs_ 前缀） */
function owCSOpt(string $k, string $def = ''): string
{
    $v = DB::setting('owcs_' . $k, $def);
    return is_scalar($v) ? (string)$v : $def;
}

/** 生效空间 id（0 = 未启用） */
function owCSActiveId(): int
{
    return (int)owCSOpt('active', '0');
}

/** 生效空间行（含解密后的 config）；未启用返回 null */
function owCSActive(): ?array
{
    $id = owCSActiveId();
    return $id > 0 ? owCSSpace($id) : null;
}

/** 取空间行；config 解密为数组；空间不存在返回 null */
function owCSSpace(int $id): ?array
{
    if ($id <= 0) return null;
    $row = DB::one('SELECT * FROM plugin_cloud_storage_space WHERE id=?', [$id]);
    if (!$row) return null;
    $row['cfg'] = json_decode((string)$row['config'], true) ?: [];
    return $row;
}

/** 构造驱动；配置不全返回 null */
function owCSDriverFor(array $space): ?owCSDriver
{
    $type = (string)$space['type'];
    $cfg  = $space['cfg'];
    $cls  = [
        'oss'   => 'owCSDriverOss',
        'cos'   => 'owCSDriverCos',
        'qiniu' => 'owCSDriverQiniu',
        'upyun' => 'owCSDriverUpyun',
        's3'    => 'owCSDriverS3',
    ][$type] ?? '';
    if ($cls === '' || !class_exists($cls)) return null;
    $d = new $cls($cfg);
    return $d->ready() ? $d : null;
}

/** 该类型上传是否接入云端 */
function owCSKindOn(string $kind): bool
{
    $kinds = owCSOpt('kinds', 'avatar,sticker,image,file');
    // ⚠️ array_filter 第二参必须是回调（'strlen' = 剥掉空串），传 true 在 PHP 8 直接 TypeError
    $on = array_filter(array_map('trim', explode(',', $kinds)), 'strlen');
    return in_array($kind, $on, true);
}

/** 是否保留本地副本（双重储存） */
function owCSKeepLocal(): bool
{
    return owCSOpt('keep_local', '0') === '1';
}

/** 访问链接后缀（对象处理参数，如 ?x-oss-process=image/resize,w_800） */
function owCSSuffix(): string
{
    $s = trim(owCSOpt('url_suffix', ''));
    if ($s === '') return '';
    // 只允许以 ? 或 & 开头的一段查询串，挡掉换行 / 引号注入
    if ($s[0] !== '?' && $s[0] !== '&') $s = '?' . $s;
    return (strlen($s) <= 200 && !preg_match('/[\r\n"\'<>]/', $s)) ? $s : '';
}

/** 按扩展名猜 MIME（上传时各后端都要 Content-Type） */
function owCSMime(string $file): string
{
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $map = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'bmp' => 'image/bmp', 'svg' => 'image/svg+xml',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4',
        'amr' => 'audio/amr', 'aac' => 'audio/aac', 'flac' => 'audio/flac', 'webm' => 'audio/webm',
        'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm',
        'pdf' => 'application/pdf', 'zip' => 'application/zip', 'txt' => 'text/plain',
        'md' => 'text/markdown', 'json' => 'application/json',
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

/** 密钥掩码（只写不读：前端永远拿不到真值） */
function owCSMask(string $s): string
{
    $s = (string)$s;
    return $s === '' ? '' : '••••••••';
}

/**
 * 空间配置的字段定义（后台表单与保存校验共用）。
 * secret=true 的字段只写不读：留空 = 不修改。
 */
function owCSFields(string $type): array
{
    $f = static function (string $k, string $label, string $ph = '', bool $secret = false, string $def = ''): array {
        return ['k' => $k, 'label' => $label, 'ph' => $ph, 'secret' => $secret, 'def' => $def];
    };
    $common = [
        $f('prefix', '存储路径前缀', 'owlsgo/', false, 'owlsgo/'),
    ];
    switch ($type) {
        case 'oss':
            return array_merge($common, [
                $f('endpoint', 'Endpoint 域名', 'oss-cn-hangzhou.aliyuncs.com'),
                $f('bucket', 'Bucket 名称', 'my-bucket'),
                $f('ak', 'AccessKeyId', '', true),
                $f('sk', 'AccessKeySecret', '', true),
                $f('domain', '自定义域名（可选）', 'https://img.example.com'),
            ]);
        case 'cos':
            return array_merge($common, [
                $f('region', '存储地域', 'ap-guangzhou'),
                $f('bucket', 'Bucket 名称（含 APPID）', 'my-bucket-1250000000'),
                $f('ak', 'SecretId', '', true),
                $f('sk', 'SecretKey', '', true),
                $f('domain', '自定义域名（可选）', 'https://img.example.com'),
            ]);
        case 'qiniu':
            return array_merge($common, [
                $f('bucket', '空间名称', 'my-bucket'),
                $f('ak', 'AccessKey', '', true),
                $f('sk', 'SecretKey', '', true),
                $f('domain', '访问域名', 'https://img.example.com'),
                $f('up_host', '上传域名', 'up.qiniup.com'),
            ]);
        case 'upyun':
            return array_merge($common, [
                $f('bucket', '服务名称（空间名）', 'my-bucket'),
                $f('operator', '操作员账号', 'operator'),
                $f('password', '表单 API 密钥', '', true),
                $f('domain', '加速域名', 'https://img.example.com'),
            ]);
        case 's3':
            return array_merge($common, [
                $f('endpoint', 'Endpoint 域名', 's3.us-east-1.amazonaws.com'),
                $f('region', 'Region', 'us-east-1'),
                $f('bucket', 'Bucket 名称', 'my-bucket'),
                $f('ak', 'Access Key ID', '', true),
                $f('sk', 'Secret Access Key', '', true),
                $f('path_style', '路径风格（path-style）', '0'),
                $f('domain', '自定义域名（可选）', 'https://img.example.com'),
            ]);
        default:
            return [];
    }
}

/** 空间配置必填字段（不含可选 domain / path_style） */
function owCSRequired(string $type): array
{
    return [
        'oss'   => ['endpoint', 'bucket', 'ak', 'sk'],
        'cos'   => ['region', 'bucket', 'ak', 'sk'],
        'qiniu' => ['bucket', 'ak', 'sk', 'domain'],
        'upyun' => ['bucket', 'operator', 'password', 'domain'],
        's3'    => ['endpoint', 'region', 'bucket', 'ak', 'sk'],
    ][$type] ?? [];
}

/** 类型中文名（后台展示） */
function owCSTypeName(string $type): string
{
    return [
        'oss'   => '阿里云 OSS',
        'cos'   => '腾讯云 COS',
        'qiniu' => '七牛云 Kodo',
        'upyun' => '又拍云',
        's3'    => 'AWS S3 / S3 兼容',
    ][$type] ?? $type;
}

/* ============================ HTTP 传输（curl，零依赖） ============================ */

/**
 * 通用 HTTP 请求。
 * @param array $opt headers[] / file（PUT 上传的本地路径）/ multipart（表单字段）
 * @return array{0:int,1:string,2:array} [状态码, 响应体, 响应头小写映射]
 */
function owCSHttp(string $method, string $url, array $opt = []): array
{
    $ch = curl_init();
    $h = [];
    foreach (($opt['headers'] ?? []) as $k => $v) $h[] = is_int($k) ? $v : "$k: $v";

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);   // ⚠️ 少了这行，签名头全都发不出去
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    // 本机开发环境可能没有 CA 根证书；连不上时退一次不校验（仅本插件请求）
    if (!empty($opt['file'])) {
        $fp = fopen((string)$opt['file'], 'rb');
        if (!$fp) return [0, '', []];
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_INFILE, $fp);
        curl_setopt($ch, CURLOPT_INFILESIZE, (int)@filesize((string)$opt['file']));
    } elseif (!empty($opt['body'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$opt['body']);
    } elseif (!empty($opt['multipart'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, (array)$opt['multipart']);
    }

    $respHeaders = [];
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$respHeaders) {
        $p = strpos($line, ':');
        if ($p !== false && $p > 0) {
            $respHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
        }
        return strlen($line);
    });

    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    if ($body === false && $code === 0 && !empty($opt['file'])) {
        // 上传流要复位：fopen 的句柄被 UPLOAD 消费过，重试前重新打开
        fclose($fp);
        $fp = fopen((string)$opt['file'], 'rb');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_INFILE, $fp);
        curl_setopt($ch, CURLOPT_INFILESIZE, (int)@filesize((string)$opt['file']));
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    }
    if (!empty($fp) && is_resource($fp)) fclose($fp);
    curl_close($ch);

    if ($body === false) $body = '';
    if ($code === 0 && $err !== '') $body = 'curl: ' . $err;
    return [$code, (string)$body, $respHeaders];
}

/* ============================ 驱动基类 ============================ */

/**
 * 后端驱动统一接口。key = 空间前缀 + 本地 rel（如 owlsgo/image/1a/xxx.jpg）。
 * put/del 返回 bool；probe 返回 '' 表示通过，否则是给人看的错误原因。
 */
abstract class owCSDriver
{
    protected array $c;
    protected string $prefix;

    public function __construct(array $cfg)
    {
        $this->c = $cfg;
        $p = trim((string)($cfg['prefix'] ?? 'owlsgo/'));
        $this->prefix = ($p === '' ? 'owlsgo/' : rtrim($p, '/') . '/');
    }

    /** 必填项齐了才干活 */
    public function ready(): bool
    {
        foreach (owCSRequired($this->type()) as $k) {
            if (trim((string)($this->c[$k] ?? '')) === '') return false;
        }
        return true;
    }

    /**
     * 协议：配置里显式写了 http:// 就用 http（自建 MinIO / 内网端点常见），
     * 否则一律 https。密钥不该走明文，但这个选择权交给站长。
     */
    protected function scheme(): string
    {
        foreach (['domain', 'endpoint'] as $k) {
            $v = trim((string)($this->c[$k] ?? ''));
            if ($v !== '' && stripos($v, 'http://') === 0) return 'http';
        }
        return 'https';
    }

    /** 上传专用协议（七牛的上传域名可单独配 http） */
    protected function upScheme(): string
    {
        $v = trim((string)($this->c['up_host'] ?? ''));
        return (stripos($v, 'http://') === 0) ? 'http' : 'https';
    }

    abstract public function type(): string;
    abstract public function put(string $abs, string $key): bool;
    abstract public function del(string $key): bool;
    abstract public function url(string $key): string;

    /** 连接自检：上传一个 2 字节探针，再经访问域名 HEAD 验证域名配置。
     *  返回 '' = 通过；否则是错误原因。探针文件会留在空间里（可手动删）。 */
    public function probe(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'owcsprobe');
        file_put_contents($tmp, 'ok');
        $key = $this->prefix . '.owcs-probe';
        if (!$this->put($tmp, $key)) { @unlink($tmp); return '上传失败：请检查密钥、Bucket 与 Endpoint 配置'; }
        @unlink($tmp);
        // 经访问域名 HEAD：200 = 域名也对了；连不上只警告（上传本身已通过）
        [$code] = owCSHttp('HEAD', $this->url($key));
        if ($code === 200) return '';
        if ($code === 0) return '上传成功，但访问域名拉不通（请检查域名解析 / CDN 配置）';
        return '上传成功，但访问域名返回 ' . $code . '（请检查域名是否已指向该空间）';
    }

    /** key 规范化：只保留安全字符，防对象键穿越 */
    public function keyFor(string $rel): string
    {
        $rel = str_replace('\\', '/', ltrim(trim($rel), '/'));
        $rel = preg_replace('#\.\.+#', '', $rel) ?? '';      // 剥掉 ..（对象键不允许上级跳转）
        $rel = preg_replace('#/{2,}#', '/', $rel) ?? '';     // 折叠重复斜杠
        $rel = ltrim($rel, '/');                             // 剥掉因 ..  removal 露出的前导斜杠
        $rel = preg_replace('#[^A-Za-z0-9_\-./]#', '', $rel) ?? '';
        return $this->prefix . $rel;
    }
}

/* ---------- 阿里云 OSS（V1 签名：HMAC-SHA1） ----------
 * StringToSign = VERB\nContent-MD5\nContent-Type\nDate\nCanonicalizedResource
 * Signature    = base64(HMAC-SHA1(StringToSign, AccessKeySecret))
 * Authorization: OSS <AccessKeyId>:<Signature>                            */
class owCSDriverOss extends owCSDriver
{
    public function type(): string { return 'oss'; }

    protected function host(): string
    {
        $d = trim((string)($this->c['domain'] ?? ''));
        if ($d !== '') return preg_replace('#^https?://#', '', rtrim($d, '/'));
        return trim((string)($this->c['bucket'] ?? '')) . '.' . trim((string)($this->c['endpoint'] ?? ''));
    }

    /** 生成签名头（GET/PUT/HEAD/DELETE 通用） */
    protected function auth(string $verb, string $uri, string $ctype = ''): array
    {
        $date = gmdate('D, d M Y H:i:s') . ' GMT';   // RFC 1123（GMT 前有空格）
        $sign = "{$verb}\n\n{$ctype}\n{$date}\n/" . trim((string)($this->c['bucket'] ?? '')) . $uri;
        $sig  = base64_encode(hash_hmac('sha1', $sign, (string)($this->c['sk'] ?? ''), true));
        $h = [
            'Authorization: OSS ' . trim((string)($this->c['ak'] ?? '')) . ':' . $sig,
            'Date: ' . $date,
        ];
        if ($ctype !== '') $h[] = 'Content-Type: ' . $ctype;
        return $h;
    }

    public function put(string $abs, string $key): bool
    {
        $uri  = '/' . $key;
        $type = owCSMime($abs);
        [$code] = owCSHttp('PUT', $this->scheme() . '://' . $this->host() . $uri, [
            'headers' => $this->auth('PUT', $uri, $type),
            'file'    => $abs,
        ]);
        return $code === 200;
    }

    public function del(string $key): bool
    {
        $uri = '/' . $key;
        [$code] = owCSHttp('DELETE', $this->scheme() . '://' . $this->host() . $uri, ['headers' => $this->auth('DELETE', $uri)]);
        return $code === 204 || $code === 200;
    }

    public function url(string $key): string
    {
        return $this->scheme() . '://' . $this->host() . '/' . $key;
    }
}

/* ---------- 腾讯云 COS（XML API 永久密钥） ----------
 * KeyTime       = <start>;<end>
 * SignKey       = HMAC-SHA1(SecretKey, KeyTime)                  （hex）
 * HttpString    = <method>\n<uri>\n<params>\n<headers>
 * StringToSign  = sha1\n<KeyTime>\nSHA1(HttpString)
 * Signature     = HMAC-SHA1(SignKey, StringToSign)               （hex）
 * URL 带 q-sign-algorithm / q-ak / q-sign-time / q-key-time / q-header-list /
 *      q-url-param-list / q-signature                              */
class owCSDriverCos extends owCSDriver
{
    public function type(): string { return 'cos'; }

    protected function host(): string
    {
        $d = trim((string)($this->c['domain'] ?? ''));
        if ($d !== '') return preg_replace('#^https?://#', '', rtrim($d, '/'));
        return trim((string)($this->c['bucket'] ?? '')) . '.cos.' . trim((string)($this->c['region'] ?? '')) . '.myqcloud.com';
    }

    /** 生成签名查询串 */
    protected function sign(string $method, string $uri): string
    {
        $start   = time();
        $end     = $start + 600;
        $keyTime = $start . ';' . $end;
        $signKey = hash_hmac('sha1', $keyTime, (string)($this->c['sk'] ?? ''));
        $headers = 'host=' . rawurlencode($this->host());
        $httpStr = strtolower($method) . "\n" . $uri . "\n\n" . $headers;
        $toSign  = "sha1\n{$keyTime}\n" . sha1($httpStr);
        $sig     = hash_hmac('sha1', $toSign, $signKey);
        return 'q-sign-algorithm=sha1'
            . '&q-ak=' . rawurlencode(trim((string)($this->c['ak'] ?? '')))
            . '&q-sign-time=' . $keyTime
            . '&q-key-time=' . $keyTime
            . '&q-header-list=host'
            . '&q-url-param-list='
            . '&q-signature=' . $sig;
    }

    public function put(string $abs, string $key): bool
    {
        $uri = '/' . $key;
        [$code] = owCSHttp('PUT', $this->scheme() . '://' . $this->host() . $uri . '?' . $this->sign('PUT', $uri), [
            'headers' => ['Content-Type: ' . owCSMime($abs)],
            'file'    => $abs,
        ]);
        return $code === 200;
    }

    public function del(string $key): bool
    {
        $uri = '/' . $key;
        [$code] = owCSHttp('DELETE', $this->scheme() . '://' . $this->host() . $uri . '?' . $this->sign('DELETE', $uri));
        return $code === 204 || $code === 200;
    }

    public function url(string $key): string
    {
        return $this->scheme() . '://' . $this->host() . '/' . $key;
    }
}

/* ---------- 七牛云 Kodo（上传凭证 + 表单上传） ----------
 * token   = <AK>:<urlsafe_b64(policy)>:<urlsafe_b64(HMAC-SHA1(encodedPolicy, SK))>
 * policy  = {"scope":"<bucket>:<key>","deadline":<unix>}
 * POST <up_host>/  multipart: token / key / file                        */
class owCSDriverQiniu extends owCSDriver
{
    public function type(): string { return 'qiniu'; }

    protected function token(string $key): string
    {
        $policy = ['scope' => trim((string)($this->c['bucket'] ?? '')) . ':' . $key, 'deadline' => time() + 3600];
        $enc    = strtr(base64_encode(json_encode($policy, JSON_UNESCAPED_SLASHES)), '+/', '-_');
        $sign   = strtr(base64_encode(hash_hmac('sha1', $enc, (string)($this->c['sk'] ?? ''), true)), '+/', '-_');
        return trim((string)($this->c['ak'] ?? '')) . ':' . $enc . ':' . $sign;
    }

    public function put(string $abs, string $key): bool
    {
        $host = preg_replace('#^https?://#', '', trim((string)($this->c['up_host'] ?? 'up.qiniup.com')));
        [$code, $body] = owCSHttp('POST', $this->upScheme() . '://' . $host . '/', [
            'multipart' => [
                'token' => $this->token($key),
                'key'   => $key,
                'file'  => new CURLFile($abs, owCSMime($abs), basename($key)),
            ],
        ]);
        if ($code !== 200) return false;
        $j = json_decode($body, true);
        return is_array($j) && !empty($j['key']);
    }

    public function del(string $key): bool
    {
        // 管理凭证：token = AK:urlsafe_b64(/delete/<EncodedEntry>):urlsafe_b64(HMAC-SHA1(path\n, SK))
        $entry  = strtr(base64_encode(trim((string)($this->c['bucket'] ?? '')) . ':' . $key), '+/', '-_');
        $path   = '/delete/' . $entry;
        $encPath = strtr(base64_encode($path), '+/', '-_');
        $encSign = strtr(base64_encode(hash_hmac('sha1', $path . "\n", (string)($this->c['sk'] ?? ''), true)), '+/', '-_');
        [$code] = owCSHttp('POST', 'https://rs.qiniup.com' . $path, [
            'headers' => ['Authorization: Qiniu ' . trim((string)($this->c['ak'] ?? '')) . ':' . $encPath . ':' . $encSign],
        ]);
        return $code === 200;
    }

    public function url(string $key): string
    {
        return $this->scheme() . '://' . preg_replace('#^https?://#', '', rtrim(trim((string)($this->c['domain'] ?? '')), '/')) . '/' . $key;
    }
}

/* ---------- 又拍云（表单 API） ----------
 * policy     = base64({"bucket":…,"save-key":"/<key>","expiration":<unix>})
 * signature  = md5(policy + '&' + 表单API密钥)
 * POST https://v0.api.upyun.com/<bucket>  multipart: policy / signature / file */
class owCSDriverUpyun extends owCSDriver
{
    public function type(): string { return 'upyun'; }

    /** 生成 policy 与 signature（抽出来便于测试） */
    protected function formSign(string $key): array
    {
        $bucket = trim((string)($this->c['bucket'] ?? ''));
        $policy = [
            'bucket'      => $bucket,
            'save-key'    => '/' . $key,
            'expiration'  => time() + 3600,
        ];
        $policyStr = base64_encode(json_encode($policy, JSON_UNESCAPED_SLASHES));
        $signature = md5($policyStr . '&' . trim((string)($this->c['password'] ?? '')));
        return [$policyStr, $signature];
    }

    public function put(string $abs, string $key): bool
    {
        [$policyStr, $signature] = $this->formSign($key);
        [$code, $body] = owCSHttp('POST', 'https://v0.api.upyun.com/' . rawurlencode(trim((string)($this->c['bucket'] ?? ''))), [
            'multipart' => [
                'policy'      => $policyStr,
                'signature'   => $signature,
                'file'        => new CURLFile($abs, owCSMime($abs), basename($key)),
            ],
        ]);
        if ($code !== 200) return false;
        $j = json_decode($body, true);
        return is_array($j) && (int)($j['code'] ?? 0) === 200;
    }

    public function del(string $key): bool
    {
        // REST 删除：Authorization: UPYUN <operator>:<base64(HMAC-SHA1(MD5(password), Method&Uri&Date))>
        $bucket = rawurlencode(trim((string)($this->c['bucket'] ?? '')));
        $uri    = '/' . $bucket . '/' . $key;
        $date   = gmdate('D, d M Y H:i:s') . ' GMT';
        $sign   = base64_encode(hash_hmac('sha1', 'DELETE&' . $uri . '&' . $date, md5(trim((string)($this->c['password'] ?? ''))), true));
        [$code] = owCSHttp('DELETE', 'https://v0.api.upyun.com' . $uri, [
            'headers' => ['Authorization: UPYUN ' . trim((string)($this->c['operator'] ?? '')) . ':' . $sign, 'Date: ' . $date],
        ]);
        return $code === 200;
    }

    public function url(string $key): string
    {
        return $this->scheme() . '://' . preg_replace('#^https?://#', '', rtrim(trim((string)($this->c['domain'] ?? '')), '/')) . '/' . $key;
    }
}

/* ---------- AWS S3 / S3 兼容（SigV4；覆盖 MinIO、华为云 OBS 等） ----------
 * CanonicalRequest = Method\nCanonicalURI\nCanonicalQuery\nCanonicalHeaders\n
 *                    SignedHeaders\nHashedPayload
 * StringToSign     = AWS4-HMAC-SHA256\n<amzDate>\n<scope>\nSHA256(CanonicalRequest)
 * SigningKey       = HMAC(HMAC(HMAC(HMAC("AWS4"+SK, date), region), "s3"), "aws4_request")
 * （AWS 文档测试向量见 .tools/s3-sig-test.php）                        */

/**
 * SigV4 派生签名密钥（纯函数，无副作用，便于测试）。
 * AWS 文档测试向量：SK=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY、
 * date=20130524、region=us-east-1、service=s3 时的中间值见 .tools/s3-sig-test.php。
 */
function owCSHmacChain(string $sk, string $dateStamp, string $region, string $service): string
{
    $k = hash_hmac('sha256', $dateStamp, 'AWS4' . $sk, true);
    $k = hash_hmac('sha256', $region, $k, true);
    $k = hash_hmac('sha256', $service, $k, true);
    return hash_hmac('sha256', 'aws4_request', $k, true);
}

class owCSDriverS3 extends owCSDriver
{
    public function type(): string { return 's3'; }

    protected function host(): string
    {
        $d = trim((string)($this->c['domain'] ?? ''));
        if ($d !== '') return preg_replace('#^https?://#', '', rtrim($d, '/'));
        $ep  = preg_replace('#^https?://#', '', trim((string)($this->c['endpoint'] ?? '')));
        $bkt = trim((string)($this->c['bucket'] ?? ''));
        return ($this->c['path_style'] ?? '0') === '1' ? $ep : $bkt . '.' . $ep;
    }

    /** 对象路径（path-style 时带 bucket 一层） */
    protected function uri(string $key): string
    {
        $k = '/' . $key;
        return ($this->c['path_style'] ?? '0') === '1' ? '/' . trim((string)($this->c['bucket'] ?? '')) . $k : $k;
    }

    /** SigV4 签名头 */
    protected function auth(string $method, string $uri, string $payloadHash): array
    {
        $ak  = trim((string)($this->c['ak'] ?? ''));
        $sk  = trim((string)($this->c['sk'] ?? ''));
        $rg  = trim((string)($this->c['region'] ?? ''));
        $amz = gmdate('Ymd\THis\Z');
        $ds  = gmdate('Ymd');
        $host = $this->host();
        $canonHeaders = "host:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$amz}\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
        $canonRequest = "{$method}\n{$uri}\n\n{$canonHeaders}\n{$signedHeaders}\n{$payloadHash}";
        $scope = "{$ds}/{$rg}/s3/aws4_request";
        $toSign = "AWS4-HMAC-SHA256\n{$amz}\n{$scope}\n" . hash('sha256', $canonRequest);
        $sig = hash_hmac('sha256', $toSign, owCSHmacChain($sk, $ds, $rg, 's3'));
        return [
            'Authorization: AWS4-HMAC-SHA256 Credential=' . $ak . '/' . $scope
                . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $sig,
            'x-amz-content-sha256: ' . $payloadHash,
            'x-amz-date: ' . $amz,
        ];
    }

    public function put(string $abs, string $key): bool
    {
        $uri = $this->uri($key);
        [$code] = owCSHttp('PUT', $this->scheme() . '://' . $this->host() . $uri, [
            'headers' => $this->auth('PUT', $uri, hash_file('sha256', $abs)),
            'file'    => $abs,
        ]);
        return $code === 200;
    }

    public function del(string $key): bool
    {
        $uri = $this->uri($key);
        [$code] = owCSHttp('DELETE', $this->scheme() . '://' . $this->host() . $uri, [
            'headers' => $this->auth('DELETE', $uri, hash('sha256', '')),
        ]);
        return $code === 204 || $code === 200;
    }

    public function url(string $key): string
    {
        return $this->scheme() . '://' . $this->host() . $this->uri($key);
    }
}

/* ============================ 钩子接线 ============================ */

/** 记录映射 + 更新空间统计 */
function owCSMapPut(int $spaceId, string $rel, string $key, int $size): void
{
    $exists = DB::one('SELECT rel FROM plugin_cloud_storage_file WHERE rel=?', [$rel]);
    DB::run('INSERT OR REPLACE INTO plugin_cloud_storage_file (rel, space_id, key, size, created_at) VALUES (?,?,?,?,?)',
        [$rel, $spaceId, $key, $size, time()]);
    if (!$exists) {
        DB::run('UPDATE plugin_cloud_storage_space SET file_count=file_count+1, total_size=total_size+? WHERE id=?', [$size, $spaceId]);
    }
}

/** 删映射 + 更新空间统计 */
function owCSMapDrop(string $rel): void
{
    $row = DB::one('SELECT space_id, size FROM plugin_cloud_storage_file WHERE rel=?', [$rel]);
    if (!$row) return;
    DB::run('DELETE FROM plugin_cloud_storage_file WHERE rel=?', [$rel]);
    DB::run('UPDATE plugin_cloud_storage_space SET file_count=file_count-1, total_size=total_size-? WHERE id=?',
        [(int)$row['size'], (int)$row['space_id']]);
}

/** 按云端 key 反查映射行（头像改写 / 下载核对用） */
function owCSMapByKey(int $spaceId, string $key): ?array
{
    $row = DB::one('SELECT * FROM plugin_cloud_storage_file WHERE space_id=? AND key=?', [$spaceId, $key]);
    return $row ?: null;
}

/**
 * 上传落盘后：搬去云端 + 改写返回值。
 *   · file      → $out 改成 file/cloud-<空间号>/<key>（消息 content 存它）
 *   · 其余媒体  → $out 改成完整云端 URL
 *   · avatar    → 不改写（setAvatar 只认 uploads/ 前缀），渲染走 avatar.url
 * 任何一步失败都静默保持本地 —— 上传永不因云存储故障而失败。
 */
Plugin::on('upload.stored', function (&$out, string $kind, string $rel, string $abs): void {
    $space = owCSActive();
    if (!$space || !owCSKindOn($kind) || $rel === '' || !is_file($abs)) return;
    $driver = owCSDriverFor($space);
    if (!$driver) return;

    $key = $driver->keyFor($rel);
    if (!$driver->put($abs, $key)) return;               // 上传失败 → 保持本地（安全降级）

    $size = (int)@filesize($abs);
    owCSMapPut((int)$space['id'], $rel, $key, $size);

    if ($kind === 'file') {
        $out = 'file/cloud-' . (int)$space['id'] . '/' . $key;
    } elseif ($kind !== 'avatar') {
        $out = $driver->url($key) . owCSSuffix();
    }
    // avatar：不改写 $out

    if (!owCSKeepLocal()) @unlink($abs);                 // 双重储存关 → 删本地副本
});

/**
 * 从完整 URL 反查云端引用（域名前缀匹配 + 映射核对）。
 * 前缀用 $d->url('') 算：path-style 自带 /bucket/，虚拟主机风格不带 ——
 * 两种形态都能正确剥出 key（早先按「域名后整段」当 key，path-style 会把 bucket 拼两次）。
 * @return array{space_id:int,key:string,rel:string}|null
 */
function owCSRefOfUrl(string $url): ?array
{
    if (!preg_match('#^https?://#', $url)) return null;
    foreach (owCSSpaces() as $sp) {
        $d = owCSDriverFor($sp);
        if (!$d) continue;
        $prefix = $d->url('');
        if (stripos($url, $prefix) !== 0) continue;
        $key = (string)preg_replace('~[?#].*$~', '', substr($url, strlen($prefix)));   // 剥掉链接后缀（⚠️ 定界符别用 #，字符类里有 #）
        if ($key === '') continue;
        $row = owCSMapByKey((int)$sp['id'], $key);
        if ($row) return ['space_id' => (int)$sp['id'], 'key' => $key, 'rel' => (string)$row['rel']];
    }
    return null;
}

/**
 * 媒体地址放行：只认自己空间签发出去的东西。
 *   ① https://<空间域名>/<key>      —— 图片 / 贴纸消息的完整 URL
 *   ② file/cloud-<空间号>/<key>    —— 文件消息的标识
 *   ③ uploads/file/cloud-<号>/<key> —— 前端给图文混排的视频拼的前缀形态，
 *      就地改写成渲染可达的完整 URL（$ref 按引用）
 */
Plugin::on('media.allow', function (&$allow, string &$ref, string $kind, array $actor, int $roomId): void {
    // ① 完整 URL：域名前缀须匹配某空间，且 key 须在映射表里
    if (preg_match('#^https?://#', $ref)) {
        if (owCSRefOfUrl($ref) !== null) $allow = '1';
        return;
    }
    // ② / ③ 标识形态
    $norm = preg_replace('#^uploads/#', '', $ref) ?? '';
    if (preg_match('#^file/cloud-(\d+)/(.+)$#', $norm, $m)) {
        $sp = owCSSpace((int)$m[1]);
        $d  = $sp ? owCSDriverFor($sp) : null;
        if ($d && owCSMapByKey((int)$m[1], $m[2])) {
            $allow = '1';
            if ($ref !== $norm) $ref = $d->url($m[2]) . owCSSuffix();   // ③：改写成完整 URL
            return;
        }
    }
});

/**
 * 附件下载：认出 file/cloud-<空间号>/<key> 就从云端代理读出。
 * 幂等核对：key 必须在映射表里（防止伪造标识探测云端文件）。
 */
Plugin::on('file.download', function (&$handled, string $path, array $msg, array $actor): void {
    if (!preg_match('#^file/cloud-(\d+)/(.+)$#', $path, $m)) return;
    $sp = owCSSpace((int)$m[1]);
    $d  = $sp ? owCSDriverFor($sp) : null;
    if (!$d || !owCSMapByKey((int)$m[1], $m[2])) return;

    $info = json_decode((string)($msg['content'] ?? ''), true);
    $name = preg_replace('/[\r\n"]/', '', (string)($info['name'] ?? 'file'));
    $url  = $d->url($m[2]);

    $handled = true;
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    header('X-Cloud-Storage: ' . (int)$m[1]);

    // 先 HEAD 拿大小（拿不到就省了 Content-Length，浏览器按 chunked 收）
    [$hCode, , $hHdr] = owCSHttp('HEAD', $url);
    if ($hCode === 200 && !empty($hHdr['content-length'])) {
        header('Content-Length: ' . (int)$hHdr['content-length']);
    }
    // 流式转发
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_BUFFERSIZE, 65536);
    curl_exec($ch);
    curl_close($ch);
});

/** 头像渲染：本地 uploads/ 路径 → 云端地址（库里路径不动，停用即回落） */
Plugin::on('avatar.url', function (&$url, array $ctx): void {
    if (($ctx['source'] ?? '') !== 'upload') return;
    $path = (string)parse_url($url, PHP_URL_PATH);
    $pos  = strpos($path, '/uploads/');
    if ($pos === false) return;
    $rel = substr($path, $pos + strlen('/uploads/'));
    if ($rel === '') return;
    $row = DB::one('SELECT space_id, key FROM plugin_cloud_storage_file WHERE rel=?', [$rel]);
    if (!$row) return;
    $sp = owCSSpace((int)$row['space_id']);
    $d  = $sp ? owCSDriverFor($sp) : null;
    if ($d) $url = $d->url((string)$row['key']) . owCSSuffix();
});

/**
 * 撤回消息后：同步删除开关打开时，云端对象没人再引用就连对象一起删。
 * （引用计数口径与附件插件一致：消息 content 出现该 key 即算被引用；
 *   另外消息头像快照 / 用户与群头像也可能指向同一个 key —— 同一张图既当过头像
 *   又当过消息图片是常态，漏列会把别人正在用的对象删掉。）
 */
Plugin::on('msg.after_recall', function (int $msgId, array $msg, array $actor): void {
    if (owCSOpt('sync_delete', '0') !== '1') return;
    foreach (owCSRefsOfMessage($msg) as $ref) {
        if (!owCSKeyRefCount((int)$ref['space_id'], (string)$ref['key'], (string)$ref['rel'])
            && owCSMapByKey((int)$ref['space_id'], (string)$ref['key'])) {
            $sp = owCSSpace((int)$ref['space_id']);
            $d  = $sp ? owCSDriverFor($sp) : null;
            if ($d) $d->del((string)$ref['key']);
            owCSMapDrop((string)$ref['rel']);
        }
    }
});

/** 从一条消息里挖出云端引用（file / rich / image 三种形态） */
function owCSRefsOfMessage(array $msg): array
{
    $out = [];
    $type = (string)($msg['type'] ?? '');
    $content = (string)($msg['content'] ?? '');
    if ($type === 'file') {
        $j = json_decode($content, true);
        $p = (string)($j['path'] ?? '');
        if (preg_match('#^file/cloud-(\d+)/(.+)$#', $p, $m)) {
            $out[] = ['space_id' => (int)$m[1], 'key' => $m[2], 'rel' => owCSRelOfKey((int)$m[1], $m[2])];
        }
    } elseif ($type === 'rich') {
        $j = json_decode($content, true);
        foreach ((array)($j['media'] ?? []) as $one) {
            $u = (string)($one['url'] ?? '');
            foreach (owCSRefsOfUrl($u) as $r) $out[] = $r;
        }
    } elseif ($type === 'image') {
        foreach (owCSRefsOfUrl($content) as $r) $out[] = $r;
    }
    return $out;
}

/** 从 URL / 标识里解析云端引用 */
function owCSRefsOfUrl(string $u): array
{
    $r = owCSRefOfUrl($u);
    return $r ? [$r] : [];
}

/** key → 本地 rel（映射表里存着） */
function owCSRelOfKey(int $spaceId, string $key): string
{
    $row = owCSMapByKey($spaceId, $key);
    return $row ? (string)$row['rel'] : '';
}

/** 该 key 还被哪些地方引用（消息正文 / 消息头像快照 / 贴纸 / 用户头像 / 群头像） */
function owCSKeyRefCount(int $spaceId, string $key, string $rel): int
{
    $like = '%' . $key . '%';
    $relFull = 'uploads/' . $rel;   // users.avatar / rooms.avatar 存的是带前缀的相对路径
    return (int)DB::val("SELECT (SELECT COUNT(*) FROM messages WHERE content LIKE ?)
        + (SELECT COUNT(*) FROM messages WHERE avatar LIKE ?)
        + (SELECT COUNT(*) FROM stickers WHERE url LIKE ?)
        + (SELECT COUNT(*) FROM users WHERE avatar=?)
        + (SELECT COUNT(*) FROM rooms WHERE avatar=?)", [$like, $like, $like, $relFull, $relFull]);
}

/** 全部空间（按创建顺序）；config 解密进 cfg，调用方无需各自解码 */
function owCSSpaces(): array
{
    $out = [];
    foreach (DB::all('SELECT * FROM plugin_cloud_storage_space ORDER BY id ASC') as $row) {
        $row['cfg'] = json_decode((string)$row['config'], true) ?: [];
        $out[] = $row;
    }
    return $out;
}

/* ============================ 计划任务：清理未引用文件 ============================ */

Plugin::cron('owcs_gc', 3600, function (): string {
    if (owCSOpt('sync_delete', '0') !== '1') return '跳过（未开启删除同步，可手动清理）';
    $n = owCSGC(200);
    return $n > 0 ? "清理未引用的云端文件 {$n} 个" : '没有需要清理的文件';
}, '云存储：清理云端已无引用的文件（每小时，需开启「删除同步」）');

/** 清理一批（默认 200）没有引用的映射；返回清理数量 */
function owCSGC(int $limit = 200): int
{
    $rows = DB::all('SELECT * FROM plugin_cloud_storage_file ORDER BY created_at ASC LIMIT ' . (int)$limit);
    $n = 0;
    foreach ($rows as $r) {
        $rel = (string)$r['rel'];
        $key = (string)$r['key'];
        if (owCSKeyRefCount((int)$r['space_id'], $key, $rel) > 0) continue;
        $sp = owCSSpace((int)$r['space_id']);
        $d  = $sp ? owCSDriverFor($sp) : null;
        if ($d) $d->del($key);
        owCSMapDrop($rel);
        $n++;
    }
    return $n;
}

/* ============================ 后台路由 ============================ */

$csGuard = function (array $ctx): void {
    if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
};

/** 空间列表 + 选项 + 统计（后台页初始化） */
Plugin::route('plugin_cloud_storage_list', function (array $ctx) use ($csGuard) {
    $csGuard($ctx);
    $spaces = [];
    foreach (owCSSpaces() as $sp) {
        $cfg = json_decode((string)$sp['config'], true) ?: [];
        // 密钥只回掩码；其余配置原样回（后台要可编辑）
        foreach (owCSFields((string)$sp['type']) as $f) {
            if ($f['secret']) $cfg[$f['k']] = isset($cfg[$f['k']]) && (string)$cfg[$f['k']] !== '' ? owCSMask((string)$cfg[$f['k']]) : '';
        }
        $spaces[] = [
            'id' => (int)$sp['id'], 'name' => (string)$sp['name'], 'type' => (string)$sp['type'],
            'type_name' => owCSTypeName((string)$sp['type']),
            'is_active' => (int)$sp['is_active'] === 1,
            'file_count' => (int)$sp['file_count'], 'total_size' => (int)$sp['total_size'],
            'created_at' => (int)$sp['created_at'], 'cfg' => $cfg,
        ];
    }
    Api::json([
        'ok' => true,
        'spaces' => $spaces,
        'active_id' => owCSActiveId(),
        'opts' => [
            'keep_local'  => owCSOpt('keep_local', '0'),
            'sync_delete' => owCSOpt('sync_delete', '0'),
            'url_suffix'  => owCSOpt('url_suffix', ''),
            'kinds'       => owCSOpt('kinds', 'avatar,sticker,image,file'),
        ],
        'types' => [
            'oss'   => owCSTypeName('oss'),
            'cos'   => owCSTypeName('cos'),
            'qiniu' => owCSTypeName('qiniu'),
            'upyun' => owCSTypeName('upyun'),
            's3'    => owCSTypeName('s3'),
        ],
        'fields' => [
            'oss'   => owCSFields('oss'),
            'cos'   => owCSFields('cos'),
            'qiniu' => owCSFields('qiniu'),
            'upyun' => owCSFields('upyun'),
            's3'    => owCSFields('s3'),
        ],
    ]);
});

/** 新建 / 编辑空间。secret 字段留空 = 保持原值 */
Plugin::route('plugin_cloud_storage_save', function (array $ctx) use ($csGuard) {
    $csGuard($ctx);
    $id   = (int)($ctx['post']['id'] ?? 0);
    $name = mb_substr(trim((string)($ctx['post']['name'] ?? '')), 0, 60);
    $type = (string)($ctx['post']['type'] ?? '');
    if ($name === '') Api::json(['ok' => false, 'msg' => '请填写空间名称']);
    if (!array_key_exists($type, ['oss' => 1, 'cos' => 1, 'qiniu' => 1, 'upyun' => 1, 's3' => 1])) {
        Api::json(['ok' => false, 'msg' => '不支持的存储类型']);
    }

    $old = $id > 0 ? owCSSpace($id) : null;
    if ($id > 0 && !$old) Api::json(['ok' => false, 'msg' => '空间不存在'], 404);
    if ($old && (string)$old['type'] !== $type) {
        // 换类型 = 换一套配置，直接按新类型校验（旧密钥不继承）
        $old = null;
    }

    $cfg = [];
    foreach (owCSFields($type) as $f) {
        $v = trim((string)($ctx['post'][$f['k']] ?? ''));
        if ($f['secret']) {
            // 只写不读：留空（或掩码原值）→ 继承旧值；编辑时留空即「不改」
            if ($v === '' || $v === owCSMask($v)) {
                $v = $old ? (string)($old['cfg'][$f['k']] ?? '') : '';
            }
        }
        if ($v === '') $v = $f['def'];
        $cfg[$f['k']] = mb_substr($v, 0, 255);
    }
    foreach (owCSRequired($type) as $k) {
        if ((string)($cfg[$k] ?? '') === '') Api::json(['ok' => false, 'msg' => '请填写「' . $k . '」']);
    }
    $cfg['path_style'] = ($cfg['path_style'] ?? '0') === '1' ? '1' : '0';

    if ($old) {
        DB::run('UPDATE plugin_cloud_storage_space SET name=?, type=?, config=? WHERE id=?',
            [$name, $type, json_encode($cfg, JSON_UNESCAPED_UNICODE), $id]);
        $newId = $id;
    } else {
        $newId = (int)DB::insert('plugin_cloud_storage_space', [
            'name' => $name, 'type' => $type,
            'config' => json_encode($cfg, JSON_UNESCAPED_UNICODE),
            'is_active' => 0, 'created_at' => time(),
        ]);
    }
    Api::json(['ok' => true, 'msg' => $old ? '已保存' : '已创建', 'id' => $newId]);
});

/** 切换生效空间 */
Plugin::route('plugin_cloud_storage_active', function (array $ctx) use ($csGuard) {
    $csGuard($ctx);
    $id = (int)($ctx['post']['id'] ?? 0);
    if ($id === 0) {
        DB::run('UPDATE plugin_cloud_storage_space SET is_active=0');
        DB::setSetting('owcs_active', '0');
        Api::json(['ok' => true, 'msg' => '已停用云存储（回到本地）']);
    }
    if (!owCSSpace($id)) Api::json(['ok' => false, 'msg' => '空间不存在'], 404);
    DB::run('UPDATE plugin_cloud_storage_space SET is_active=0');
    DB::run('UPDATE plugin_cloud_storage_space SET is_active=1 WHERE id=?', [$id]);
    DB::setSetting('owcs_active', (string)$id);
    Api::json(['ok' => true, 'msg' => '已切换生效空间']);
});

/** 删除空间（有文件的挡住，防误删导致消息破图） */
Plugin::route('plugin_cloud_storage_del', function (array $ctx) use ($csGuard) {
    $csGuard($ctx);
    $id = (int)($ctx['post']['id'] ?? 0);
    $sp = owCSSpace($id);
    if (!$sp) Api::json(['ok' => false, 'msg' => '空间不存在'], 404);
    $cnt = (int)DB::val('SELECT COUNT(*) FROM plugin_cloud_storage_file WHERE space_id=?', [$id]);
    if ($cnt > 0) Api::json(['ok' => false, 'msg' => "该空间还有 {$cnt} 个文件，请先迁移到其他空间或清理后再删"]);
    DB::run('DELETE FROM plugin_cloud_storage_space WHERE id=?', [$id]);
    if (owCSActiveId() === $id) DB::setSetting('owcs_active', '0');
    Api::json(['ok' => true, 'msg' => '已删除']);
});
Plugin::sensitive('plugin_cloud_storage_del');

/** 连接测试 */
Plugin::route('plugin_cloud_storage_test', function (array $ctx) use ($csGuard) {
    $csGuard($ctx);
    $id = (int)($ctx['post']['id'] ?? 0);
    $sp = owCSSpace($id);
    if (!$sp) Api::json(['ok' => false, 'msg' => '空间不存在'], 404);
    $d = owCSDriverFor($sp);
    if (!$d) Api::json(['ok' => false, 'msg' => '配置不完整']);
    $err = $d->probe();
    Api::json($err === '' ? ['ok' => true, 'msg' => '连接正常（已上传 2 字节探针文件，可随时删除）'] : ['ok' => false, 'msg' => $err]);
});

/** 保存选项 */
Plugin::route('plugin_cloud_storage_opts', function (array $ctx) use ($csGuard) {
    $csGuard($ctx);
    $p = $ctx['post'];
    DB::setSetting('owcs_keep_local', ($p['keep_local'] ?? '') === '1' ? '1' : '0');
    DB::setSetting('owcs_sync_delete', ($p['sync_delete'] ?? '') === '1' ? '1' : '0');
    $suffix = trim((string)($p['url_suffix'] ?? ''));
    if ($suffix !== '' && preg_match('/[\r\n"\'<>]/', $suffix)) Api::json(['ok' => false, 'msg' => '链接后缀含非法字符']);
    DB::setSetting('owcs_url_suffix', mb_substr($suffix, 0, 200));
    $kinds = array_map('trim', explode(',', (string)($p['kinds'] ?? '')));
    $kinds = array_values(array_intersect(['avatar', 'sticker', 'image', 'file'], $kinds));
    DB::setSetting('owcs_kinds', $kinds ? implode(',', $kinds) : '');
    Api::json(['ok' => true, 'msg' => '已保存']);
});

/** 迁移：开始（扫本地未入库文件，写清单） */
Plugin::route('plugin_cloud_storage_mig_start', function (array $ctx) use ($csGuard) {
    $csGuard($ctx);
    $sp = owCSActive();
    if (!$sp) Api::json(['ok' => false, 'msg' => '请先设置生效空间']);
    $up = owCSUploadDir();
    if ($up === '') Api::json(['ok' => false, 'msg' => '拿不到上传目录']);
    $list = [];
    foreach (['avatar', 'sticker', 'image', 'file'] as $kind) {
        $dir = $up . '/' . $kind;
        if (!is_dir($dir)) continue;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $rel = $kind . '/' . substr($f->getPathname(), strlen($dir) + 1);
            $rel = str_replace('\\', '/', $rel);
            // 隐藏的临时文件（裁剪中间产物）跳过
            if (preg_match('#/\.#', $rel)) continue;
            $list[] = $rel;
        }
    }
    // 过滤掉已在映射表里的
    $have = [];
    foreach (array_chunk($list, 200) as $chunk) {
        $in = implode(',', array_fill(0, count($chunk), '?'));
        foreach (DB::all("SELECT rel FROM plugin_cloud_storage_file WHERE rel IN ($in)", $chunk) as $r) {
            $have[(string)$r['rel']] = 1;
        }
    }
    $list = array_values(array_filter($list, fn($r) => !isset($have[$r])));
    sort($list);
    file_put_contents(owCSMigFile(), json_encode($list, JSON_UNESCAPED_UNICODE));
    $n = count($list);
    Api::json(['ok' => true, 'total' => $n, 'msg' => $n > 0 ? "发现 {$n} 个待迁移文件" : '没有需要迁移的文件']);
});

/** 迁移：推进一步（传 N 个） */
Plugin::route('plugin_cloud_storage_mig_step', function (array $ctx) use ($csGuard) {    $csGuard($ctx);
    $sp = owCSActive();
    $d  = $sp ? owCSDriverFor($sp) : null;
    if (!$sp || !$d) Api::json(['ok' => false, 'msg' => '请先设置生效空间']);
    $file = owCSMigFile();
    if (!is_file($file)) Api::json(['ok' => true, 'done' => true, 'ok' => 0, 'fail' => 0]);
    $list = json_decode((string)file_get_contents($file), true) ?: [];
    $batch = array_splice($list, 0, 20);
    file_put_contents($file, json_encode($list, JSON_UNESCAPED_UNICODE));

    $up = owCSUploadDir();
    $ok = 0; $fail = 0; $err = '';
    foreach ($batch as $rel) {
        $abs = $up . '/' . $rel;
        if (!is_file($abs)) { owCSMapPut((int)$sp['id'], $rel, $d->keyFor($rel), 0); $fail++; continue; }
        $key = $d->keyFor($rel);
        if (!$d->put($abs, $key)) { $fail++; $err = '上传失败'; continue; }
        owCSMapPut((int)$sp['id'], $rel, $key, (int)@filesize($abs));
        if (!owCSKeepLocal()) @unlink($abs);
        $ok++;
    }
    Api::json(['ok' => true, 'done' => count($list) === 0, 'ok' => $ok, 'fail' => $fail, 'err' => $err, 'left' => count($list)]);
});
Plugin::sensitive('plugin_cloud_storage_mig_step');   // 会删本地副本，属删除数据

/** 手动清理未引用文件 */
Plugin::route('plugin_cloud_storage_gc', function (array $ctx) use ($csGuard) {
    $csGuard($ctx);
    $n = owCSGC(500);
    Api::json(['ok' => true, 'msg' => $n > 0 ? "已清理 {$n} 个未引用文件" : '没有需要清理的文件', 'n' => $n]);
});
Plugin::sensitive('plugin_cloud_storage_gc');

/** 上传目录（与核心 Upload::init 同源） */
function owCSUploadDir(): string
{
    // 核心把 upload.dir 注入 Upload；插件侧从配置反推不可靠，直接用站点约定路径
    $base = dirname(__DIR__, 2);           // plugins/ 的上级 = 站点根
    return is_dir($base . '/uploads') ? $base . '/uploads' : '';
}

/** 迁移清单临时文件（放 data/cache，与 plugins.json 同目录） */
function owCSMigFile(): string
{
    $dir = dirname(__DIR__, 2) . '/data/cache';
    @mkdir($dir, 0775, true);
    return $dir . '/cloud-storage-migrate.json';
}

/* ============================ 后台页面 ============================ */

Plugin::asset('css', 'cloud-storage/admin.css');
Plugin::asset('js', 'cloud-storage/admin.js', 'admin');

/** 后台 CSS 不会自动引入，经 page.head 发 <link> */
// v1.5.31：CSS 不再由插件自己发切片 —— 核心 pageHead 统一引整包
// （整包本就包含本插件的样式；此前每个插件各发一条，首屏变成 16 个串行 CSS 请求）

Plugin::adminPage('cloud-storage', '云存储', function () {
    $html = '<h2>云存储</h2>'
        . '<p class="ow-admin-desc">把上传文件存到对象存储。停用插件即整体回落本地，无需迁移数据。</p>'
        . '<div id="owCSApp" data-cs-inited="0">'
        // 当前生效空间（大卡片）
        . '<div class="ow-card" id="owCSActiveCard" style="margin-bottom:14px">'
        . '<div class="ow-list-head"><span class="ow-list-head-t">当前生效空间</span>'
        . '<button class="ow-btn ow-btn-ghost" type="button" onclick="OwCS.editActive()" id="owCSActiveEdit" style="display:none">编辑</button>'
        . '<button class="ow-btn ow-btn-ghost" type="button" onclick="OwCS.testActive()" id="owCSActiveTest" style="display:none">测试连接</button></div>'
        . '<div id="owCSActiveBody"><p class="ow-tip-inline">加载中…</p></div>'
        . '</div>'
        // 空间列表
        . '<div class="ow-card" style="margin-bottom:14px">'
        . '<div class="ow-list-head"><span class="ow-list-head-t">存储空间</span>'
        . '<button class="ow-btn ow-btn-primary" type="button" onclick="OwCS.newSpace()">＋ 新建空间</button></div>'
        . '<div id="owCSSpaceGrid" class="owcs-grid"><p class="ow-tip-inline">加载中…</p></div>'
        . '</div>'
        // 编辑器（新建/编辑滑出面板）
        . '<div class="ow-card" id="owCSEditor" style="display:none;margin-bottom:14px">'
        . '<div class="ow-list-head"><span class="ow-list-head-t" id="owCSEditorTitle">新建空间</span>'
        . '<button class="ow-btn ow-btn-ghost" type="button" onclick="OwCS.closeEditor()">收起</button></div>'
        . '<div id="owCSEditorBody"></div>'
        . '</div>'
        // 上传选项
        . '<div class="ow-card" style="margin-bottom:14px">'
        . '<div class="ow-list-head"><span class="ow-list-head-t">上传选项</span>'
        . '<button class="ow-btn ow-btn-primary" type="button" onclick="OwCS.saveOpts()">保存</button></div>'
        . '<div id="owCSOptsBody"><p class="ow-tip-inline">加载中…</p></div>'
        . '</div>'
        // 维护
        . '<div class="ow-card">'
        . '<div class="ow-list-head"><span class="ow-list-head-t">维护</span></div>'
        . '<div class="ow-form-row" style="flex-wrap:wrap;gap:8px">'
        . '<button class="ow-btn ow-btn-ghost" type="button" onclick="OwCS.migStart()">迁移本地文件到云端</button>'
        . '<button class="ow-btn ow-btn-ghost" type="button" onclick="OwCS.gc()">清理未引用文件</button>'
        . '<button class="ow-btn ow-btn-ghost" type="button" onclick="OwCS.refresh()">刷新统计</button>'
        . '</div>'
        . '<div id="owCSMigBox" style="display:none;margin-top:10px">'
        . '<div class="owcs-progress"><div class="owcs-progress-bar" id="owCSMigBar"></div></div>'
        . '<p class="ow-form-item" style="font-size:12px" id="owCSMigText">准备中…</p>'
        . '</div>'
        . '<p class="ow-form-item" style="font-size:12px">迁移会把 uploads/ 下尚未入库的文件搬到生效空间；已在新上传时入过库的不会重复搬。删除同步关闭时，被删消息对应的云端文件只能靠「清理未引用文件」回收。</p>'
        . '</div>'
        . '</div>';
    return $html;
});
