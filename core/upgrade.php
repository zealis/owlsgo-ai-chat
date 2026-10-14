<?php
/**
 * 在线升级（v1.3.39）：基于 GitHub 仓库 tree 比对的增量更新。
 *
 * 工作方式（入口在后台「系统设置 → 系统与维护 → 在线升级」）：
 *   1. check   —— 拉远端分支的文件树（api.github.com git/trees?recursive=1，
 *      公开仓库免 token），逐文件与本地算同款 git blob sha 比对（本地先按 git 口径
 *      做 CRLF→LF 归一，见 toLf），得出「新增/修改」差异清单 + 远端 VERSION 对比；
 *   2. apply   —— 差异文件**全部**先下载到 data/upgrade/tmp/ 并逐个复核 blob sha，
 *      齐了才统一搬入 —— 中途断网只会留下临时目录，绝不出现半新半旧的站点；
 *      搬入前把将被覆盖的文件备份到 data/backup/upgrade-<时间>/（含 manifest.json）；
 *   3. rollback —— 按 manifest 恢复被覆盖的文件、删除升级新增的文件。
 *
 * 安全边界（四道闸，一条不能省）：
 *   · 保护名单 PROTECT 内的路径永不覆盖、永不删除：
 *     core/config.php（真实密钥）、data/（数据库与运行数据）、uploads/、.git/；
 *   · 仅 admin + 一次性票据（$SENSITIVE）可触发 apply / rollback；
 *   · 远端版本低于本地 = 降级，默认拒绝，需显式勾选「允许降级」；
 *   · 版本不落后却有文件差异（= 本地改了/还没推）默认拒绝，需显式勾选
 *     「用仓库版本覆盖本地差异」——这一条防的是**自己覆盖自己**：
 *     比对的是「部署副本 vs 仓库」，本地领先时清单上列的全是新代码，
 *     点一下就把刚写的插件退回旧版，而同一个版本号让降级闸完全放行（v1.3.102）。
 *   闸门判定集中在 Upgrade::blocked()，apply 只负责执行。
 *   ⚠️ 信任前提：升级源就是仓库写权限 —— GitHub 账号被盗即等于代码被投毒。
 *      因此不做任何自动更新，只在管理员手点时执行；blob sha 校验防的是
 *      传输截断/缓存不一致，防不了恶意仓库本身。
 *
 * 删除策略：远端 tree 里没有的本地文件**只报告、不删除** —— 站点目录里混着
 * 运行副本特有的东西（data、uploads、同步遗留），无法区分「上游删了」还是
 * 「本地自有文件」，宁可留孤儿文件也不误删。
 */
if (!defined('OWLSGO_VERSION')) exit;   // 禁止直接 HTTP 访问本文件

class Upgrade
{
    /** 升级源：公开仓库 + 分支（改 fork 只动这一行） */
    private const REPO   = 'zealis/owlsgo-ai-chat';
    private const BRANCH = 'main';

    /** 保护前缀：命中即跳过比对与搬入（永不覆盖、永不删除） */
    private const PROTECT = ['core/config.php', 'data/', 'uploads/', '.git/'];

    /** 站点根目录（core/ 的上一级） */
    public static function root(): string { return dirname(__DIR__); }

    /**
     * CA 证书包发现：phpStudy 的 Windows PHP 默认没配 curl.cainfo，
     * HTTPS 校验会报 "unable to get local issuer certificate"。
     * 按序找：php.ini 配置 → PHP 自带 extras → Git for Windows 的 Mozilla CA 包 → Linux 系统路径。
     * 全找不到就返回 null 走系统默认 —— 绝不关闭校验（下载的是代码）。
     *
     * v1.3.54 起 public：人机验证插件（captcha-verify）也要发 HTTPS 请求，
     * 复用同一份发现结果 —— 两处各写一套 CA 查找，迟早有一边悄悄不验证书。
     */
    public static function caBundle(): ?string
    {
        static $found = false;
        static $path = null;
        if ($found) return $path;
        $cands = [(string)ini_get('curl.cainfo'), (string)ini_get('openssl.cafile')];
        if (defined('PHP_BINARY')) {
            $cands[] = dirname(PHP_BINARY) . '/extras/cacert.pem';
        }
        $cands[] = 'C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt';
        $cands[] = 'D:/sdk/Git/ucrt64/etc/ssl/certs/ca-bundle.crt';
        $cands[] = '/etc/ssl/certs/ca-certificates.crt';
        foreach ($cands as $c) {
            if ($c !== '' && $c !== null && is_file($c)) { $path = $c; $found = true; return $path; }
        }
        $found = true;
        return null;
    }

    /** 带超时的 HTTPS GET（curl 优先，无 curl 回落 allow_url_fopen）。失败返回 null 并写 $err */
    private static function get(string $url, ?string &$err = null): ?string
    {
        $err = null;
        $auth = self::authHeader();
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HTTPHEADER     => array_merge(['User-Agent: Owlsgo-Updater', 'Accept: application/vnd.github+json'], $auth),
            ]);
            $ca = self::caBundle();
            if ($ca !== null) curl_setopt($ch, CURLOPT_CAINFO, $ca);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($body === false) $err = '网络请求失败：' . curl_error($ch);
            elseif ($code !== 200) $err = 'HTTP ' . $code . '（检查仓库地址/分支/限流）';
            curl_close($ch);
            return $body === false || $err !== null ? null : (string)$body;
        }
        if (ini_get('allow_url_fopen') == false) { $err = '服务器无 curl 扩展且 allow_url_fopen 关闭，无法联网升级'; return null; }
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
        $ca = self::caBundle();
        if ($ca !== null) $ssl['cafile'] = $ca;
        $hdr = "User-Agent: Owlsgo-Updater\r\n" . ($auth ? $auth[0] . "\r\n" : '');
        $ctx = stream_context_create([
            'http' => ['timeout' => 30, 'header' => $hdr],
            'ssl'  => $ssl,
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) { $err = '网络请求失败：' . (error_get_last()['message'] ?? '未知'); return null; }
        return $body;
    }

    /* ---------- GitHub token 与限额（v1.3.138） ----------
       未认证的 API 限额是 60 次/小时，一次升级要花 N+3 次（1 tree + 1 VERSION blob +
       再 1 tree + N 个文件 blob），所以差异一多就必然撞墙 —— 站方 2026-10-15 线上
       就卡在「63 个文件 > 上限 45」。两条路一起上：
         · 配了 token → 5000 次/小时，上限跟着放宽；
         · 没配 token → 走整包（tarball）那条，一次升级只花 2 次，多少文件都一样。
       token 存在 settings，面板只回显掩码，绝不吐完整值。 */
    public static function token(): string
    {
        try { return trim((string)DB::setting('github_token', '')); } catch (Throwable $e) { return ''; }
    }

    /** 单次升级允许的差异文件数：按有无 token 分档，留出 tree/VERSION 的余量 */
    public static function fileLimit(): int
    {
        return self::token() !== '' ? 500 : 45;
    }

    /** 只露头尾各 4 位（面板与回执都只给这个形式） */
    public static function maskToken(string $t): string
    {
        $t = trim($t);
        if ($t === '') return '';
        if (strlen($t) <= 10) return str_repeat('•', strlen($t));
        return substr($t, 0, 4) . '…' . substr($t, -4);
    }

    /** 有 token 才加 Authorization。跨主机重定向（api → codeload）时 curl 会自动摘掉它，正合适 */
    private static function authHeader(): array
    {
        $t = self::token();
        return $t === '' ? [] : ['Authorization: Bearer ' . $t];
    }

    /** 远端文件树：[path => blobSha]（只收 blob；tree 被截断视为不支持） */
    public static function fetchTree(?string &$err = null): ?array
    {
        $j = self::get('https://api.github.com/repos/' . self::REPO . '/git/trees/' . self::BRANCH . '?recursive=1', $err);
        if ($j === null) return null;
        $d = json_decode($j, true);
        if (!is_array($d)) { $err = 'GitHub 响应不是 JSON（可能被网关拦截）'; return null; }
        if (!empty($d['truncated'])) { $err = '仓库文件过多，GitHub tree 响应被截断，在线升级不可用'; return null; }
        $out = [];
        foreach ((array)($d['tree'] ?? []) as $e) {
            if (($e['type'] ?? '') === 'blob' && isset($e['path'], $e['sha']) && is_string($e['path']) && is_string($e['sha'])) {
                $out[$e['path']] = $e['sha'];
            }
        }
        if (!$out) { $err = '仓库树为空（确认仓库与分支存在）'; return null; }
        return $out;
    }

    /**
     * 与 git 的行尾规范化对齐：仓库里存的 blob 是 LF，`core.autocrlf=true` 检出到
     * Windows 工作区会变成 CRLF —— 而 GitHub 树给的 sha 始终是 LF 那份。
     * 不归一的话，这些文件**永远**被判为「有差异」：升级把它们刷成 LF，
     * 下一次 git 检出又变回 CRLF，红点怎么点都灭不掉（v1.3.102 修的就是这个）。
     *
     * 只处理文本：含 NUL 一律按二进制原样返回，与 git 的文本探测同口径。
     * 图片/字体这类文件内部恰好出现 \r\n 时，跟着改会把内容改出第三个 sha，
     * 反而制造假差异。
     */
    public static function toLf(string $data): string
    {
        return strpos($data, "\0") === false ? str_replace("\r\n", "\n", $data) : $data;
    }

    /**
     * 本地文件的 git blob sha（与 GitHub tree 的 sha 同算法）；文件不存在返回 null。
     * ⚠️ 长度也要用**归一后**的字节数 —— blob 头里的长度属于被哈希的内容的一部分。
     */
    public static function localBlobSha(string $absPath): ?string
    {
        if (!is_file($absPath)) return null;
        $data = @file_get_contents($absPath);
        if ($data === false) return null;
        $data = self::toLf($data);
        return sha1('blob ' . strlen($data) . "\0" . $data);
    }

    public static function isProtected(string $path): bool
    {
        foreach (self::PROTECT as $p) {
            if (strpos($path, $p) === 0) return true;
        }
        return false;
    }

    /** 路径合法性：拒绝绝对路径与 .. 穿越（tree 来自远端，防御性校验） */
    private static function safePath(string $path): bool
    {
        return $path !== '' && $path[0] !== '/' && strpos($path, '..') === false && strpos($path, "\0") === false;
    }

    /**
     * 拉单个文件内容：git blob API（api.github.com/git/blobs/{sha}，base64 返回）。
     * 不用 raw.githubusercontent.com —— 实测本机网络环境 api.github.com 通、raw 不通；
     * 且 blob 按 sha 寻址，URL 本身就锁定了要的内容。
     * ⚠️ 未认证 API 限流 60 次/小时：一次升级 = 1 tree + N blob，
     *    差异文件数接近 50 时提前中止并提示（避免限流后卡在半程）。
     */
    public static function fetchBlob(string $sha, ?string &$err = null): ?string
    {
        $j = self::get('https://api.github.com/repos/' . self::REPO . '/git/blobs/' . $sha, $err);
        if ($j === null) return null;
        $d = json_decode($j, true);
        if (!is_array($d) || !isset($d['content'])) { $err = 'blob 响应异常（限流或 sha 不存在）'; return null; }
        $bin = base64_decode((string)$d['content'], true);
        if ($bin === false) { $err = 'blob base64 解码失败'; return null; }
        return $bin;
    }

    /**
     * 整包下载（v1.3.138）：`/tarball/{branch}` 一次 API 调用拿到**整份仓库**，
     * 真正的下载走 codeload（不占 API 限额）—— 于是「一次升级 = 1 tree + 1 tarball」，
     * 差异是 63 个还是 630 个都一样，未认证的 60 次/小时也永远够。
     *
     * 不做整包解压：解压要写几十 MB、要走目录树，而我们要的只是那几十个差异文件。
     * 读法用**纯 PHP**（gzdecode + 顺序扫 tar 头，见 tarGzExtract）而不是 PharData：
     * 线上是否启用 phar 扩展事先不知道，而这条路的价值就是「不挑环境」——
     * zlib 的 gzdecode 是默认扩展（本机与线上都在），phar 反倒不一定。
     * 失败返回 null，调用方回落逐文件拉 blob —— 那条路没 token 时很紧。
     * @return array{file:string}|null
     */
    public static function fetchTarball(string $tmpDir, ?string &$err = null): ?array
    {
        $err = null;
        if (!function_exists('gzdecode')) { $err = '服务器没有 zlib 扩展，无法整包下载'; return null; }
        $bin = self::get('https://api.github.com/repos/' . self::REPO . '/tarball/' . self::BRANCH, $err);
        if ($bin === null) return null;
        return self::prepareTarball($bin, $tmpDir, $err);
    }

    /**
     * 落盘（与网络无关的那一半，单独拆出来是为了能离线自检）。
     * @param string $bin gzip 原始字节
     */
    public static function prepareTarball(string $bin, string $tmpDir, ?string &$err = null): ?array
    {
        $err = null;
        if (!function_exists('gzdecode')) { $err = '服务器没有 zlib 扩展'; return null; }
        // gzip 魔数：codeload 给的是 .tar.gz；网关改写或拿到 JSON 错误页时在这里就拦住
        if (strlen($bin) < 100 || strncmp($bin, "\x1f\x8b", 2) !== 0) { $err = '整包响应不是 gzip（可能被网关改写）'; return null; }
        $file = $tmpDir . '/src.tar.gz';
        if (@file_put_contents($file, $bin) === false) { $err = '整包写入临时文件失败'; return null; }
        return ['file' => $file];
    }

    /**
     * 从 .tar.gz 里取出指定路径（相对仓库根）的内容：[path => 内容]，取不到的路径不在结果里。
     *
     * 纯 PHP 实现，只走两件事：gzdecode 解压、按 512 字节扫 tar 头。
     *   · 仓库路径统一**去掉第一层目录**后匹配（GitHub 的包顶层是 zealis-owlsgo-ai-chat-<sha>，
     *     sha 不固定不能猜；也**不能拿第一个条目当顶层** —— git archive 的包里第一个条目是
     *     pax_global_header，那样推出来的「顶层」是它，全部文件都会对不上，实测 134 个一个都取不到）；
     *   · 认 ustar 的 prefix 字段（长路径会被拆成 prefix + name，不认就取不到文件）；
     *   · 只收普通文件（type '0' 或 NUL）—— 目录与 pax 头不占数据块，但仍要按 size 跳过。
     * 一次扫描取全部需要的文件，不做「每文件一次解压」。
     */
    public static function tarGzExtract(string $gzFile, array $paths): array
    {
        $raw = @gzdecode((string)@file_get_contents($gzFile));
        if ($raw === false || $raw === '') return [];
        $want = array_flip($paths);
        $out = [];
        $off = 0;
        $len = strlen($raw);
        while ($off + 512 <= $len) {
            $hdr = substr($raw, $off, 512);
            if (rtrim($hdr, "\0") === '') break;                      // 结束块
            $name = rtrim(substr($hdr, 0, 100), "\0");
            $pre  = rtrim(substr($hdr, 345, 155), "\0");               // ustar prefix
            if ($pre !== '') $name = $pre . '/' . $name;
            $size = (int)octdec(trim(substr($hdr, 124, 12), "\0 "));
            $type = substr($hdr, 156, 1);
            $off += 512;
            if ($type === '0' || $type === "\0") {
                $slash = strpos($name, '/');
                $rel = $slash === false ? $name : substr($name, $slash + 1);
                if (isset($want[$rel])) $out[$rel] = substr($raw, $off, $size);
                elseif (isset($want[$name])) $out[$name] = substr($raw, $off, $size);
            }
            $off += (int)(ceil($size / 512) * 512);
        }
        return $out;
    }

    /** 差异比对：added（远端有本地无）+ modified（sha 不同）。保护名单与非法路径跳过 */
    public static function diff(array $tree): array
    {
        $root = self::root();
        $added = []; $modified = [];
        foreach ($tree as $path => $sha) {
            if (self::isProtected($path) || !self::safePath($path)) continue;
            $local = self::localBlobSha($root . '/' . $path);
            if ($local === null) $added[] = $path;
            elseif ($local !== $sha) $modified[] = $path;
        }
        return ['added' => $added, 'modified' => $modified];
    }

    /**
     * 把检查结果落到 settings.update_info —— 后台红点的唯一数据源。
     * 三个写入点：12 小时计划任务（Plugin::cron check_update）、手动 check、apply/rollback 后。
     *
     * ⚠️ 这里的 has_update **不等于** check() 里的同名字段：红点要回答的是
     * 「仓库有没有我还没拿到的新版」= status==='update'（VERSION 落后），
     * 而不是「本地与仓库有没有差异」。后者在开发机上是常态（先改后推），
     * 拿它点红点就变成常驻红点。count 仍记差异文件数，供面板显示「本地未推送」用。
     */
    public static function saveInfo(?array $chk, ?string $err = null): void
    {
        $v = $chk === null
            ? json_encode(['error' => mb_substr((string)$err, 0, 140), 'ts' => time()], JSON_UNESCAPED_UNICODE)
            : json_encode([
                'has_update'     => (($chk['status'] ?? '') === 'update') ? 1 : 0,
                'status'         => $chk['status'] ?? 'latest',
                'local_version'  => $chk['local_version'],
                'remote_version' => $chk['remote_version'],
                'count'          => count($chk['added']) + count($chk['modified']),
                'ts'             => time(),
            ], JSON_UNESCAPED_UNICODE);
        DB::upsert('settings', ['k' => 'update_info', 'v' => $v], ['k']);
    }

    /** 检查更新（供 check 路由与 apply 前置复用） */
    public static function check(?string &$err = null): ?array
    {
        $tree = self::fetchTree($err);
        if ($tree === null) return null;
        $d = self::diff($tree);
        $localVer = trim((string)@file_get_contents(self::root() . '/VERSION'));
        $remoteVer = null;
        if (isset($tree['VERSION'])) {
            $rv = self::fetchBlob($tree['VERSION'], $e2);
            $remoteVer = $rv === null ? null : trim($rv);
        }
        $n = count($d['added']) + count($d['modified']);
        return [
            'local_version'  => $localVer,
            'remote_version' => $remoteVer,
            'repo'           => self::REPO . '@' . self::BRANCH,
            'has_update'     => $n > 0,
            // status 是面板与红点真正看的字段；has_update 只表示「有差异文件可搬」，
            // 两件事别再混用同一个布尔（v1.3.102）。
            'status'         => self::statusOf($localVer, $remoteVer, $n),
            'behind'         => $remoteVer !== null && $localVer !== '' && version_compare($localVer, $remoteVer, '<'),
            // downgrade：本地版本明确高于远端（apply 默认拒绝，需前端显式授权）
            'downgrade'      => $remoteVer !== null && $localVer !== '' && version_compare($localVer, $remoteVer, '>'),
            'added'          => $d['added'],
            'modified'       => $d['modified'],
            'protect'        => self::PROTECT,
            // 面板要显示「token 配了没」与「本次能搬多少」——只给掩码，绝不回显完整凭据
            'token_masked'   => self::maskToken(self::token()),
            'file_limit'     => self::fileLimit(),
        ];
    }

    /**
     * 升级面板与红点共用的三态判据（抽成纯函数是为了能**逐条测**：
     * 真跑 check() 要联网，而开发机上远端永远不比本地新，另外两态根本走不到）。
     *
     *   latest        —— 与仓库逐文件一致
     *   update        —— 远端 VERSION 更高：这才是「有新版可升」，红点/通知只认它
     *   local_changes —— VERSION 不落后却有差异：本地改了或提交还没推。
     *                    此时点「升级」= 用仓库版本覆盖自己的新代码，必须另设闸门（见 blocked）
     *
     * 为什么不用「有差异」当红点判据：开发机的工作节奏就是「先改后推」，
     * 拿差异点亮红点等于每小时提醒我一次「你有 40 个文件不一样」，
     * 提醒多了只会把红点关掉。v1.3.99 的通知已经按 behind 收过一遍口径，这里补齐。
     */
    public static function statusOf(string $localVer, ?string $remoteVer, int $diffCount): string
    {
        if ($diffCount === 0) return 'latest';
        // 本地 VERSION 读不到（文件缺失/被改坏）时不判成「本地改动」：那样会把一次正常升级
        // 拦在勾选后面，而这道闸门的意义是「保护我自己没推的代码」——对说不清自己版本的环境不适用。
        if ($localVer === '') return 'update';
        return ($remoteVer !== null && version_compare($localVer, $remoteVer, '<'))
            ? 'update' : 'local_changes';
    }

    /**
     * apply 前的闸门（纯函数，返回 null = 放行，返回字符串 = 拒绝理由）。
     * 四道闸，顺序有意义：先讲「远端更旧」这种最危险的，再讲「本地差异」这种最容易误点的。
     */
    public static function blocked(array $chk, bool $allowDowngrade, bool $allowOverwriteLocal): ?string
    {
        $lv = (string)($chk['local_version'] ?? '');
        $rv = isset($chk['remote_version']) ? (string)$chk['remote_version'] : '';
        $n = count((array)($chk['modified'] ?? []));
        if (($chk['downgrade'] ?? false) && !$allowDowngrade) {
            return '远端版本（' . $rv . '）低于本地（' . $lv . '），已拒绝降级。确需回退请勾选「允许降级」，或用「恢复备份」。';
        }
        // 降级勾选本身已经是「我知道仓库那份更旧」的显式授权，不再重复要第二个勾。
        if (($chk['status'] ?? '') === 'local_changes' && !($chk['downgrade'] ?? false) && !$allowOverwriteLocal) {
            return '本地版本（' . $lv . '）不低于远端（' . $rv . '），却有 ' . $n . ' 个文件与仓库不同 —— '
                . '这些差异通常来自你**本地改了还没推送的提交**。这时升级会用仓库里的旧版覆盖它们（等于回退），已拒绝。'
                . '请先推本地提交；确要用仓库版覆盖，请勾选「用仓库版本覆盖本地差异」。';
        }
        return null;
    }

    /**
     * 执行升级：下载全部差异 → 逐个校验 → 备份 → 原子搬入。
     * 任何一步失败即中止（tmp 已清），此时站点**尚未被改动**。
     * @return array [bool, array|string] 成功时 data 含 applied/backup/version
     */
    public static function apply(bool $allowDowngrade = false, bool $allowOverwriteLocal = false): array
    {
        $chk = self::check($err);
        if ($chk === null) return [false, '获取更新信息失败：' . $err];
        if (!$chk['has_update']) return [true, ['msg' => '已是最新，无需更新', 'applied' => 0]];
        $lv = $chk['local_version']; $rv = $chk['remote_version'];
        $stop = self::blocked($chk, $allowDowngrade, $allowOverwriteLocal);
        if ($stop !== null) return [false, $stop];
        $tree = self::fetchTree($err);
        if ($tree === null) return [false, '重新获取文件树失败：' . $err];
        $files = array_merge($chk['added'], $chk['modified']);
        /* v1.3.139/v1.3.140：上限只该管「只能逐文件拉」那条路。
           整包下载一次调用拿全部，与文件数无关 —— 有能力整包就不设上限，
           否则 63 个文件明明能一次搬完，却被 45 这道闸挡在门外（v1.3.139 就是这个毛病：
           整包逻辑写了，闸门却排在它前面）。有 token（5000 次/小时）时 blob 路也放宽。 */
        $canTar = function_exists('gzdecode');
        $cap = $canTar ? 2000 : self::fileLimit();
        if (count($files) > $cap) {
            return [false, '差异文件 ' . count($files) . ' 个，超过单次升级上限 ' . $cap
                . '（服务器没有 zlib、只能逐文件拉取，未认证 API 限流 60 次/小时）。'
                . '请在「系统与维护 → 在线升级」填一个 GitHub token（5000 次/小时）后重试。'];
        }
        $root = self::root();
        $tmpDir = $root . '/data/upgrade/tmp';
        @mkdir($tmpDir, 0775, true);

        // ---- 第一阶段：全部取内容 + 逐个复核 blob sha（此阶段不碰站点文件） ----
        // 优先整包：一次 API 调用拿全部差异文件，63 个还是 630 个都一样；拿不到
        // （网关拦了 codeload、gzip 被改写…）才回落逐文件拉 blob —— 那条路没 token 时很紧，
        // 所以「整包失败 + 差异超过 blob 路的预算」要在花掉额度之前就停住并说清原因。
        $tar = self::fetchTarball($tmpDir, $tarErr);
        $bag = $tar !== null ? self::tarGzExtract($tar['file'], $files) : [];
        if (!$bag) {
            $blobCap = self::fileLimit();
            if (count($files) > $blobCap) {
                self::rrmdir($tmpDir);
                return [false, '整包下载没成功（' . (string)$tarErr . '），而逐文件拉取的上限是 ' . $blobCap
                    . ' 个、本次差异 ' . count($files) . ' 个（未认证 API 限流 60 次/小时）。'
                    . '多半是服务器连不上 codeload.github.com；可填一个 GitHub token（5000 次/小时）后重试。'];
            }
        }
        $contents = [];
        foreach ($files as $path) {
            if (isset($bag[$path])) {
                $body = self::toLf($bag[$path]);   // 包里就是 LF，这里只是与 sha 算法保持同一口径
            } else {
                $body = self::fetchBlob($tree[$path], $e);
            }
            if ($body === null) { self::rrmdir($tmpDir); return [false, '下载失败：' . $path . '（' . $e . '）']; }
            if (sha1('blob ' . strlen($body) . "\0" . $body) !== $tree[$path]) {
                self::rrmdir($tmpDir);
                return [false, '内容校验失败（blob sha 不匹配）：' . $path . '，已中止，站点未做任何改动'];
            }
            $tmp = $tmpDir . '/' . str_replace('/', '__', $path);
            if (@file_put_contents($tmp, $body) === false) { self::rrmdir($tmpDir); return [false, '写入临时文件失败：' . $path]; }
            $contents[$path] = $tmp;
        }

        // ---- 第二阶段：备份将被覆盖的文件 ----
        $ts = date('Ymd-His');
        $bakDir = $root . '/data/backup/upgrade-' . $ts;
        @mkdir($bakDir . '/files', 0775, true);
        foreach ($chk['modified'] as $i => $path) {
            $src = $root . '/' . $path;
            $dst = $bakDir . '/files/' . str_replace('/', '__', $path);
            if (!@copy($src, $dst)) {
                self::rrmdir($bakDir); self::rrmdir($tmpDir);
                return [false, '备份失败（磁盘/权限？）：' . $path . '，已中止，站点未做任何改动'];
            }
        }
        $manifest = [
            'ts' => $ts, 'from_version' => $lv, 'to_version' => $rv,
            'modified' => $chk['modified'], 'added' => $chk['added'],
            'at' => time(),
        ];
        @file_put_contents($bakDir . '/manifest.json', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // ---- 第三阶段：统一搬入（下载与备份都齐了才会走到这里） ----
        $done = 0; $failed = [];
        foreach ($contents as $path => $tmp) {
            $abs = $root . '/' . $path;
            $dir = dirname($abs);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) { $failed[] = $path; continue; }
            if (@copy($tmp, $abs)) { @chmod($abs, 0644); $done++; }
            else $failed[] = $path;
        }
        self::rrmdir($tmpDir);
        if ($failed) return [false, '已搬入 ' . $done . ' 个文件，但下列文件写入失败（可用「恢复备份」回滚）：' . implode('、', array_slice($failed, 0, 10))];
        return [true, [
            'applied' => $done, 'backup' => basename($bakDir),
            'version' => $rv !== null ? $rv : $lv,
            'source'  => $tar !== null ? 'tarball' : 'blob',   // 走了哪条下载路（整包 / 逐文件）
            'msg' => '升级完成：' . $done . ' 个文件已更新，备份于 data/backup/' . basename($bakDir) . '/',
        ]];
    }

    /** 备份列表（新的在前） */
    public static function backups(): array
    {
        $out = [];
        foreach ((array)@glob(self::root() . '/data/backup/upgrade-*/manifest.json') as $mf) {
            $d = json_decode((string)@file_get_contents($mf), true);
            if (!is_array($d) || empty($d['ts'])) continue;
            $out[] = [
                'ts' => (string)$d['ts'],
                'from_version' => (string)($d['from_version'] ?? ''),
                'to_version' => (string)($d['to_version'] ?? ''),
                'modified' => count((array)($d['modified'] ?? [])),
                'added' => count((array)($d['added'] ?? [])),
                'at' => (int)($d['at'] ?? 0),
            ];
        }
        usort($out, fn($a, $b) => $b['at'] <=> $a['at']);
        return $out;
    }

    /** 回滚到指定（默认最新）备份：恢复被覆盖文件 + 删除升级新增的文件 */
    public static function rollback(?string $ts = null): array
    {
        $root = self::root();
        if ($ts !== null && $ts !== '' && !preg_match('/^\d{8}-\d{6}$/', $ts)) return [false, '备份编号不合法'];
        $dir = $ts !== null && $ts !== '' ? $root . '/data/backup/upgrade-' . $ts : (string)(@glob($root . '/data/backup/upgrade-*', GLOB_ONLYDIR)[0] ?? '');
        if ($dir === '' || !is_dir($dir)) return [false, '没有可恢复的备份'];
        $mf = json_decode((string)@file_get_contents($dir . '/manifest.json'), true);
        if (!is_array($mf)) return [false, '备份缺少 manifest.json，不敢自动恢复（目录：' . basename($dir) . '）'];
        $restored = 0; $removed = 0;
        foreach ((array)($mf['modified'] ?? []) as $path) {
            if (!self::safePath($path) || self::isProtected($path)) continue;
            $src = $dir . '/files/' . str_replace('/', '__', $path);
            if (is_file($src) && @copy($src, $root . '/' . $path)) $restored++;
        }
        foreach ((array)($mf['added'] ?? []) as $path) {
            if (!self::safePath($path) || self::isProtected($path)) continue;
            if (is_file($root . '/' . $path) && @unlink($root . '/' . $path)) $removed++;
        }
        return [true, ['restored' => $restored, 'removed' => $removed, 'backup' => basename($dir),
            'msg' => '已恢复 ' . $restored . ' 个文件、移除升级新增 ' . $removed . ' 个文件（备份：' . basename($dir) . '）']];
    }

    /** 递归删目录（仅限本站 data/ 下自建的 tmp/backup 目录） */
    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach ((array)@scandir($dir) as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            is_dir($p) ? self::rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    /**
     * 拿到比对结果后决定要不要通知、通知谁（由计划任务 check_update 调用，见 Plugin::init）。
     *
     * 判据用 `behind`（本地 VERSION < 远端 VERSION），不用 `has_update`：
     * 后者是「仓库与本地文件有差异」，而开发机上本地总是先于仓库改（改完还没提交），
     * 拿它当条件会变成「每 12 小时通知一次我有 40 个文件不一样」——那不是更新，是自扰。
     *
     * 去重记的是**远端版本号**（settings.update_notice_ver）：仓库一旦有更新就是持续状态，
     * 不记「为哪个版本报过」的话，同一条通知一天两遍，管理员只会把通知入口关掉。
     * 版本号没变就不重复；升过之后再出新版本会再通知一次。
     *
     * 单独成方法（而不是写在任务闭包里）只为**可测**：开发机上远端永远不比自己新，
     * 真跑一次任务只能走到「无新版」，通知那条分支就永远没被验证过。
     *
     * @return string 这次的结果（写进 cron_logs 的 message，后台「维护任务」里看得见）
     */
    public static function notifyAdmins(array $chk): string
    {
        $lv = (string)($chk['local_version'] ?? '');
        $rv = (string)($chk['remote_version'] ?? '');
        if (empty($chk['behind'])) return '无新版（本地 ' . ($lv !== '' ? $lv : '?')
            . '，远端 ' . ($rv !== '' ? $rv : '?') . '）';
        if ((string)DB::setting('update_notice_ver', '') === $rv) return '已通知过 ' . $rv . '，不重复推送';
        $n = count((array)($chk['added'] ?? [])) + count((array)($chk['modified'] ?? []));
        $ids = DB::all("SELECT id FROM users WHERE role='admin'");
        foreach ($ids as $r) Notice::push((int)$r['id'], 'update_available', ['v' => $rv, 'n' => $n]);
        DB::setSetting('update_notice_ver', $rv);
        return '已通知 ' . count($ids) . ' 位超级管理员：新版本 ' . $rv . '（' . $n . ' 个文件）';
    }
}
