<?php
/**
 * 邮件验证码：策略校验 / 频控 / 生成 / 校验。
 *
 * v1.3.55 起本类是**策略的唯一实现处**，参数一律读核心 settings 的 `mail_*` 键
 * （见 DB::defaults()）。为什么不是插件自有表：策略的判定发生在核心
 * （Mailer::sendCode / verifyCode），邮件插件停用后这些判定必须还在，
 * 且不能因为「少一个插件」就读不到值而退化成不限制。
 * 后台的编辑界面由 email-verify 插件提供，它写的也是这批键。
 *
 * 发送通道不在核心（v1.0.81 起核心不再内置 SMTP），三个钩子分工：
 *   - `mail.render` [&$subject, &$body, &$format, $ctx] —— 插件套模板（$format: text|html）
 *   - `mail.send`   [&$sent, $to, $subject, $body, &$error, $ctx] —— 插件投递，置 $sent=true 即成功
 *   - `mail.unsent` [$info] —— 没有任何插件投递时触发，供插件记一条「待发送」统计
 * 无人投递时验证码仍入库、但接口返回明确提示（fail closed：验证码不发出去就等于没有）。
 */
class Mailer
{
    /** 最近一次投递失败的原因（由 mail.send 的 &$error 带回，只给管理员看的话术用） */
    private static string $lastError = '';

    /** 策略参数（已钳位）。前端和插件都读这里，避免各处自己写默认值。 */
    public static function policy(): array
    {
        $int = static function (string $k, int $d, int $lo, int $hi): int {
            try { $v = (int)DB::setting($k, (string)$d); } catch (Throwable $e) { $v = $d; }
            return max($lo, min($hi, $v));
        };
        $sw = static function (string $k, bool $d): bool {
            try { $v = DB::setting($k, $d ? '1' : '0'); } catch (Throwable $e) { $v = $d ? '1' : '0'; }
            return (string)$v === '1';
        };
        $domains = '';
        try { $domains = mb_substr(trim((string)DB::setting('mail_allowed_domains', '')), 0, 2000); } catch (Throwable $e) { $domains = ''; }
        return [
            // 验证码有效期（分钟）。下限 1：设 0 会生成一创建就过期的码，等于关不掉又永远验不过。
            'ttl'          => $int('mail_code_ttl', 10, 1, 120),
            // 同一邮箱重发间隔（秒）。0 = 不限（只在明确要关掉时才设）。
            'resend'       => $int('mail_rate_limit', 60, 0, 86400),
            // 三个每小时上限，0 = 不限制。窗口固定 3600 秒。
            'mail_hourly'  => $int('mail_mail_hourly', 5, 0, 10000),
            'ip_hourly'    => $int('mail_ip_hourly', 15, 0, 10000),
            'browser_hourly' => $int('mail_browser_hourly', 15, 0, 10000),
            // 同一邮箱+同一用途，一个有效期窗口内最多输错几次；超出即作废未使用的码
            'max_attempts' => $int('mail_max_attempts', 5, 0, 100),
            'email_min_len' => $int('mail_email_min_len', 7, 4, 64),
            'email_max_len' => $int('mail_email_max_len', 150, 20, 320),
            'allowed_domains' => $domains,
            // 注册时查重邮箱
            'unique_check' => $sw('mail_unique_check', true),
            // 是否允许用户自己改邮箱（本轮还没有换邮箱功能，留着给插件用）
            'allow_change_email' => $sw('mail_allow_change', true),
            // 邮箱验证码总开关：关掉后不再发码，也不再要求填码
            'code_verify'  => $sw('mail_code_verify', true),
        ];
    }

    /**
     * 邮箱合法性：格式 + 长度 + 后缀白名单 +（可选）唯一检查。
     *
     * 为什么长度单独设：只查 filter_var 的话，`a@b.c` 这种也能过，
     * 而验证码要发到真实地址才有意义；下限挡掉明显的占位垃圾，上限防超长串把日志和模板撑坏。
     *
     * @param array $ctx ['scene' => 'register'|'reset'|'chpwd'|'change', 'exclude_uid' => int]
     *                   scene=register/change 时做唯一检查（change 是「新邮箱」，要排除自己）
     * @return array [bool ok, string 归一化邮箱或错误文案]
     */
    public static function checkEmail(string $email, array $ctx = []): array
    {
        $p = self::policy();
        // 归一化（去空格 + 小写）与 Auth::normEmail 同一口径：这里是所有**写入**路径的必经之地，
        // 归一放在最前面，后面的长度/后缀/唯一检查与落库用的就是同一个值。
        $e = class_exists('Auth') ? Auth::normEmail($email) : mb_strtolower(trim($email));
        $len = strlen($e);
        if ($e === '') return [false, '请填写邮箱'];
        if ($len < $p['email_min_len'] || $len > $p['email_max_len']) {
            return [false, '邮箱长度需在 ' . $p['email_min_len'] . '-' . $p['email_max_len'] . ' 个字符之间'];
        }
        if (!filter_var($e, FILTER_VALIDATE_EMAIL)) return [false, '邮箱格式不正确'];
        $list = self::allowedDomains();
        if ($list && !self::domainAllowed($e, $list)) {
            return [false, '仅接受以下邮箱后缀：' . implode('、', $list)];
        }
        $scene = (string)($ctx['scene'] ?? '');
        $wantUnique = in_array($scene, ['register', 'change'], true) && $p['unique_check'];
        if ($wantUnique) {
            $q = 'SELECT id FROM users WHERE email=?';
            $args = [$e];
            // 换邮箱时本人那行不算冲突（否则永远改不了）
            $uid = (int)($ctx['exclude_uid'] ?? 0);
            if ($uid > 0) { $q .= ' AND id<>?'; $args[] = $uid; }
            if (DB::one($q, $args)) return [false, '该邮箱已注册'];
        }
        return [true, $e];
    }

    /**
     * 后台配置的邮箱后缀白名单（空数组 = 不限制）。
     * 每一项去掉开头的 @ 与点，便于直接做后缀比对。
     * ⚠️ 不接参数：这个列表只有一个来源（settings 的 mail_allowed_domains）。
     *    曾经设计成「传邮箱就解析邮箱」，结果 checkEmail 里把邮箱当列表传进来，
     *    报出「仅接受以下邮箱后缀：no-hrym@outlook.com」这种自指错误。
     */
    public static function allowedDomains(): array
    {
        $out = [];
        foreach (preg_split('/[,\s]+/', strtolower(self::policy()['allowed_domains'])) ?: [] as $d) {
            $d = ltrim(trim($d), '@.');
            if ($d !== '') $out[] = $d;
        }
        return $out;
    }

    /** 邮箱的域名是否命中白名单（按**后缀**匹配：填 qq.com 也接受 mail.qq.com） */
    public static function domainAllowed(string $email, array $list): bool
    {
        $at = strrpos($email, '@');
        if ($at === false) return false;
        $host = strtolower(substr($email, $at + 1));
        foreach ($list as $d) {
            if ($host === $d || str_ends_with($host, '.' . $d)) return true;
        }
        return false;
    }

    /** 最近一次投递失败的原因 */
    public static function lastError(): string { return self::$lastError; }

    /**
     * 触发投递：先让插件套模板（mail.render），再让插件发（mail.send）。
     * @return bool 是否有插件完成投递
     */
    public static function send(string $to, string $subject, string $body, array $ctx = []): bool
    {
        if (!class_exists('Plugin', false)) return false;
        $format = (string)($ctx['format'] ?? 'text');
        Plugin::fire('mail.render', [&$subject, &$body, &$format, $ctx]);
        $ctx['format'] = $format === 'html' ? 'html' : 'text';
        self::$lastError = '';
        $sent = false;
        // 错误原因走局部变量再回写：数组字面量里不能直接 &self::$静态属性
        $err = '';
        Plugin::fire('mail.send', [&$sent, $to, $subject, $body, &$err, $ctx]);
        self::$lastError = trim((string)$err);
        if ($sent !== true) {
            Plugin::fire('mail.unsent', [[
                'to' => $to, 'subject' => $subject, 'kind' => (string)($ctx['kind'] ?? ''),
            ]]);
            return false;
        }
        return true;
    }

    /**
     * 生成并持久化邮箱验证码：策略校验 → 五路限流 → 落库 → 渲染模板 → 投递。
     *
     * 五个限流维度各管一件事，缺一个就有对应打法：
     *   重发间隔（同邮箱）      —— 防一个人反复点「重发」把邮箱刷爆
     *   每小时上限（同邮箱）    —— 防对同一地址的轰炸
     *   每小时上限（同 IP）     —— 防单 IP 换着邮箱刷（发信通道会被服务商封）
     *   每小时上限（同浏览器）  —— 防换 IP / 换邮箱绕过上面两条（票据 cookie 跟着浏览器走）
     *   输错次数（验证码校验时）—— 防拿 6 位码撞库，见 verifyCode()
     *
     * @param array $ctx ['name' => 收件人昵称]
     * @return array [bool ok, string 提示文案, array 附加信息(wait/remain)]
     */
    public static function sendCode(string $email, string $type, array $cfg = [], array $ctx = []): array
    {
        $p = self::policy();
        if (!$p['code_verify']) return [false, '站点未开启邮箱验证码', []];
        [$ok, $msg] = self::checkEmail($email, ['scene' => 'send']);
        if (!$ok) return [false, $msg, []];
        $email = $msg;

        if ($p['resend'] > 0 && !Sec::rateLimit('mail', $email, $p['resend'], 1)) {
            return [false, '发送过于频繁，请 ' . Sec::rateRemaining('mail', $email, $p['resend']) . ' 秒后再试',
                ['wait' => $p['resend'], 'remain' => Sec::rateRemaining('mail', $email, $p['resend'])]];
        }
        if ($p['mail_hourly'] > 0 && !Sec::rateLimit('mail_mail', $email, 3600, $p['mail_hourly'])) {
            return [false, '该邮箱本小时的发送次数已达上限，请稍后再试', ['remain' => Sec::rateRemaining('mail_mail', $email, 3600)]];
        }
        if ($p['ip_hourly'] > 0 && !Sec::rateLimit('mail_ip', Sec::ip(), 3600, $p['ip_hourly'])) {
            return [false, '当前 IP 邮件发送已达上限，请稍后再试', ['remain' => Sec::rateRemaining('mail_ip', Sec::ip(), 3600)]];
        }
        // 浏览器维度用匿名票据里的稳定值：owl_akey 是 7 天期的浏览器标识，
        // 换 IP、换邮箱都绕不开它（正是这一条要防的绕过）。
        if ($p['browser_hourly'] > 0) {
            $browser = class_exists('Sec') ? Sec::anonKey() : '';
            if ($browser !== '' && !Sec::rateLimit('mail_browser', $browser, 3600, $p['browser_hourly'])) {
                return [false, '当前浏览器本小时的发送次数已达上限，请稍后再试',
                    ['remain' => Sec::rateRemaining('mail_browser', $browser, 3600)]];
            }
        }

        $code = (string)random_int(100000, 999999);
        DB::insert('email_codes', [
            'email' => $email, 'code' => $code, 'type' => $type,
            'ip' => Sec::ip(), 'used' => 0,
            'expires_at' => time() + $p['ttl'] * 60, 'created_at' => time(),
        ]);
        $site = DB::setting('site_name', 'Owlsgo-Chat');
        $ctx += [
            'kind' => $type, 'email' => $email, 'code' => $code,
            'site_name' => (string)$site, 'ttl' => $p['ttl'],
            'name' => (string)($ctx['name'] ?? ''),
        ];
        $subject = '[' . $site . '] 验证码 ' . $code;
        $body = '您的验证码是：' . $code . '（' . $p['ttl'] . " 分钟内有效）。\n若非本人操作请忽略本邮件。";
        $ok = self::send($email, $subject, $body, $ctx);
        if (!$ok) {
            $err = self::lastError();
            // 有通道但投递失败 → 给出通道返回的原因；一个通道都没有 → 说明是「没装/没开插件」
            $msg = $err !== '' ? ('邮件发送失败：' . $err)
                               : '站点未启用邮件发送（未安装或未配置邮件插件），请联系管理员';
            return [false, $msg, ['wait' => $p['resend']]];
        }
        return [true, '验证码已发送', ['wait' => $p['resend'], 'ttl' => $p['ttl']]];
    }

    /**
     * 校验验证码。一次性使用（用过即废），并计输错次数。
     *
     * 为什么核心必须自己计数：`Mailer::verifyCode()` 是唯一的校验入口，
     * 输错上限如果只写在插件里，插件一停用就变成「无限次试 6 位码」。
     * 达到上限后把该邮箱该用途下**所有未使用**的码作废 —— 只挡下一次请求的话，
     * 攻击者等窗口一过就能接着撞已经发出去的那个码。
     * 失败与成功都返回同样的布尔值（错误文案由调用方定），不区分「码错」和「已过期」，
     * 免得给撞库的人一个免费反馈。
     */
    public static function verifyCode(string $email, string $type, string $code): bool
    {
        $p = self::policy();
        $code = trim($code);
        if ($code === '') return false;
        $row = DB::one(
            'SELECT id FROM email_codes WHERE email=? AND type=? AND code=? AND used=0 AND expires_at>? ORDER BY id DESC LIMIT 1',
            [$email, $type, $code, time()]
        );
        if ($row) {
            DB::run('UPDATE email_codes SET used=1 WHERE id=?', [$row['id']]);
            try { DB::run('DELETE FROM rate_limits WHERE bucket=? AND ident=?', ['mail_try', $email . '|' . $type]); } catch (Throwable $e) {}
            return true;
        }
        if ($p['max_attempts'] > 0
            && !Sec::rateLimit('mail_try', $email . '|' . $type, max(60, $p['ttl'] * 60), $p['max_attempts'])) {
            DB::run('UPDATE email_codes SET used=1 WHERE email=? AND type=? AND used=0', [$email, $type]);
            Sec::log('mail_try_exceeded', $email, ['type' => $type, 'max' => $p['max_attempts']]);
        }
        return false;
    }

    /** 清理过期或已用掉的验证码（计划任务调用） */
    public static function purge(int $olderThan = 86400): int
    {
        $n = (int)DB::val('SELECT COUNT(*) FROM email_codes WHERE expires_at<?', [time() - $olderThan]);
        DB::run('DELETE FROM email_codes WHERE expires_at<?', [time() - $olderThan]);
        return $n;
    }

    /**
     * 邮箱验证码在这台站点上**到底可不可得**：总开关开着，且真有插件能投递。
     *
     * 为什么不能只看开关：开关是管理员设的意图，通道是运行时的事实。
     * 只看意图就会出现现场这个故障——没装/没配邮件插件时，改密码页照样要求填验证码，
     * 而「发验证码」永远失败，用户被永久卡在改不了密码上。
     * 要求一个**根本发不出来的凭证**不提供任何安全性，只制造死路。
     *
     * ⚠️ 这条降级**只适用于还有别的凭证的流程**（改密码有「当前密码」兜底、
     * 注册是本人自设新账号）。找回密码绝不能降级：那里验证码是唯一凭证，
     * 一降级就变成「知道邮箱就能改别人密码」的接管漏洞。
     */
    public static function deliverable(): bool
    {
        if (!self::policy()['code_verify']) return false;
        if (!class_exists('Plugin', false)) return false;
        return Plugin::collect('mail.available') === '1';
    }

    /** 是否要求邮箱验证码（总开关 + 注册场景的独立开关 + 真有通道） */
    public static function needRegisterCode(): bool
    {
        return DB::setting('reg_email_verify', '1') === '1' && self::deliverable();
    }
}
