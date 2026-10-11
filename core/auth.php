<?php
/**
 * 用户系统：注册 / 登录 / 找回 / 游客 / 资料卡 / 角色与称号
 */
class Auth
{
    /**
     * 邮箱归一化：去空格 + 转小写。**所有**写入与查询用户的邮箱都要过它。
     *
     * 为什么必须归一：邮箱在实践里不区分大小写（MX 投递不区分，服务商也不区分），
     * 而 SQLite 的排序是 BINARY —— `Admin@qq.com` 与 `admin@qq.com` 在库里是两个账号。
     * 现场故障：安装/注册时填了首字母大写的地址（浏览器自动大写、或手机输入法），
     * 登录时输全小写 → 「邮箱未注册」。MySQL 默认 ci 排序碰巧能匹配，所以这个坑
     * 只在 SQLite 上必现，更容易被当成「数据库选错了」。
     */
    public static function normEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** 当前登录用户（数组）或 null */
    public static function user(): ?array
    {
        if (empty($_SESSION['uid'])) return null;
        $u = DB::one('SELECT * FROM users WHERE id=? AND status=1', [$_SESSION['uid']]);
        // client_key 为空（历史数据/手工建号）会导致该用户所有 API 签名失败，这里自动补全
        if ($u && (string)($u['client_key'] ?? '') === '') $u = self::ensureKey($u, 'users');
        return $u;
    }

    /** 保证访问者持有签名密钥 */
    private static function ensureKey(array $row, string $table): array
    {
        $key = Sec::clientKey();
        DB::run("UPDATE $table SET client_key=? WHERE id=?", [$key, $row['id']]);
        $row['client_key'] = $key;
        return $row;
    }

    /** 当前游客（数组）或 null */
    public static function guest(): ?array
    {
        $token = $_COOKIE['owl_guest'] ?? '';
        if (!$token || !preg_match('/^[a-f0-9]{32}$/', $token)) return null;
        $g = DB::one('SELECT * FROM guests WHERE token=?', [$token]);
        if ($g && (string)($g['client_key'] ?? '') === '') $g = self::ensureKey($g, 'guests');
        return $g;
    }

    /** 确保游客身份存在（允许游客浏览时调用） */
    public static function ensureGuest(): ?array
    {
        $g = self::guest();
        if ($g) return $g;
        $token = md5(random_bytes(16));
        $nickname = '游客' . substr(bin2hex(random_bytes(3)), 0, 5);
        $id = DB::insert('guests', [
            'token' => $token, 'nickname' => $nickname,
            'client_key' => Sec::clientKey(), 'ip' => Sec::ip(),
            // v1.3.14：默认「豆苗」+ 随机一档（1..45），同一游客之后一直长这样
            'avatar_type' => 'generated', 'avatar_style' => self::AVATAR_GUEST_STYLE,
            'avatar_seed' => (string)random_int(1, self::AVATAR_SEED_COUNT),
            'created_at' => time(),
        ]);
        setcookie('owl_guest', $token, [
            'expires' => time() + 86400 * 365, 'path' => '/',
            'httponly' => true, 'secure' => Sec::isHttps(), 'samesite' => 'Lax',
        ]);
        return DB::one('SELECT * FROM guests WHERE id=?', [$id]);
    }

    /**
     * 计算周岁：传入 Y-m-d，返回年龄；日期非法或为未来日期返回 -1
     */
    public static function age(string $birthdate): int
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $birthdate)) return -1;
        $b = DateTimeImmutable::createFromFormat('Y-m-d', $birthdate);
        $today = new DateTimeImmutable('today');
        if (!$b || $b > $today) return -1;
        return (int)$today->diff($b)->y;
    }

    /**
     * 昵称（v1.0.33 起是唯一的账号显示名，不再有独立用户名）合法性校验。
     *
     * 约束：2-20 个字符，仅允许中英文、数字、下划线与短横线。
     * 禁止空格：@提及在服务端按 (^|\s)@[^\s@]+ 切词，含空格会导致无法被提及。
     * 禁止 @：避免与 @提及 前缀冲突。
     * 允许重名：昵称不再承担唯一性职责，区分用户一律使用 id。
     * 例外（v1.2.40）：**超级管理员的昵称是保留名**，别人不能用 ——
     *   因为聊天里 admin 的身份标签刻意显示为「会员」（见前端 roleTag），
     *   两个同名用户里哪个是超管从昵称分辨不出来。改资料时传 $ctx['uid'] 以排除自己。
     *
     * 插件钩子（v1.0.46）：内置规则通过后触发 nickname.before_save，
     * 回调签名 function (&$nick, &$err, $ctx)——插件可改写 $nick，
     * 或把 $err 设为非空字符串表示拒绝（$err 即展示给用户的文案）。
     * $ctx['scene']：register / profile / install。
     *
     * @return array [bool 是否合法, string 归一化值或错误文案]
     */
    public static function checkNickname(string $nick, array $ctx = []): array
    {
        $s = trim($nick);
        if ($s === '') return [false, '请填写昵称'];
        if (!preg_match('/^[\p{L}\p{N}_\-]{2,20}$/u', $s)) {
            return [false, '昵称需 2-20 个字符，支持中英文、数字、下划线与短横线，不含空格或 @'];
        }
        // v1.2.40：**超级管理员的昵称是保留名**，别人不能用。
        // 为什么要这一条：昵称本身允许重名（区分用户一律用 id），但聊天里 admin 的
        // 身份标签刻意显示为「会员」（见前端 roleTag），于是两个同名用户里
        // 到底哪个是超管，从昵称上分辨不出来 —— 容易引发误解与冒名。
        // 所以只保留「超管这一侧」的唯一性：超管自己改名不受影响（改的是自己那条），
        // 但别人不能用这个名字。
        $uid = (int)($ctx['uid'] ?? 0);          // 改名场景传入本人 id，改名时排除自己
        $q = 'SELECT COUNT(*) FROM users WHERE nickname=? AND role=? AND status=1';
        $args = [$s, 'admin'];
        if ($uid > 0) { $q .= ' AND id<>?'; $args[] = $uid; }
        if ((int)DB::val($q, $args) > 0) {
            return [false, '该昵称已被超级管理员使用，请换一个'];
        }
        // 插件校验：可改写昵称（引用）或通过 $err 拦截
        $err = null;
        Plugin::fire('nickname.before_save', [&$s, &$err, $ctx]);
        if (is_string($err) && $err !== '') return [false, $err];
        return [true, $s];
    }

    /**
     * 通用随机位段 ID 分配（v1.0.37 用户 ID 引入，v1.0.51 泛化供群聊复用）：
     * 随机 3 位数字（001-999），该段占满后自动升为随机 4 位（1000-9999），依此类推。
     *
     * 设计要点：
     * - 应用层分配、INSERT 时显式指定 id。SQLite / MySQL / PostgreSQL 的自增
     *   主键都接受显式值，无需改表结构；已有记录的 id 一律保持不变。
     * - 先随机试探（段内空位多时碰撞率极低），试探失败再收集段内空位精确
     *   随机取一个，避免段快满时随机反复撞车。
     * - 并发插入撞主键时，由调用方捕获并重试。
     *
     * @param string $table 目标表（users / rooms 等，主键须为自增整数 id）
     * @return int 可用的 ID
     */
    public static function nextId(string $table): int
    {
        $max    = (int)(DB::val("SELECT MAX(id) FROM $table") ?: 0);
        $digits = max(3, strlen((string)$max));          // 至少 3 位（001-999）
        while (true) {
            $lo = $digits === 3 ? 1 : 10 ** ($digits - 1);   // 3 位段 1-999；4 位段 1000 起
            $hi = 10 ** $digits - 1;
            $occupied = (int)DB::val("SELECT COUNT(*) FROM $table WHERE id BETWEEN ? AND ?", [$lo, $hi]);
            if ($occupied < $hi - $lo + 1) break;        // 当前位段未满，可用
            $digits++;                                   // 段满自动升一位
        }
        // 随机试探 32 次；失败（段接近占满）时收集全部空位精确随机
        for ($i = 0; $i < 32; $i++) {
            $id = random_int($lo, $hi);
            if (!DB::one("SELECT id FROM $table WHERE id=?", [$id])) return $id;
        }
        $used = array_map('intval', array_column(
            DB::all("SELECT id FROM $table WHERE id BETWEEN ? AND ?", [$lo, $hi]), 'id'
        ));
        $free = array_values(array_diff(range($lo, $hi), $used));
        if (!$free) return self::nextId($table);         // 理论不可达（上方已判满），防御性递归
        return (int)$free[array_rand($free)];
    }

    /** 用户 ID：随机 3 位起步（管理员固定 001，安装向导显式指定 id=1） */
    public static function nextUserId(): int
    {
        return self::nextId('users');
    }

    public static function register(string $nickname, string $email, string $password, string $code, string $birthdate = ''): array
    {
        if (DB::setting('allow_register', '1') !== '1') return [false, '站点已关闭注册'];
        [$nickOk, $nick] = self::checkNickname($nickname, ['scene' => 'register']);
        if ($nickOk) Chat::filterText($nick, 'nickname');   // 敏感词过滤（sensitive-words 插件经 text.filter 钩子处理）
        if (!$nickOk) return [false, $nick];
        if (strlen($password) < 6) return [false, '密码至少 6 位'];
        // 邮箱合法性（格式/长度/后缀白名单/唯一检查）与归一化统一走 Mailer::checkEmail：
        // 规则写在核心和写在插件里各一份，迟早变成「注册能过、发码被拒」。
        // 返回的第二项已是**小写归一**后的地址，后面所有查询与落库都用它。
        [$mailOk, $mailMsg] = Mailer::checkEmail($email, ['scene' => 'register']);
        if (!$mailOk) return [false, $mailMsg];
        $email = $mailMsg;
        if (Mailer::needRegisterCode() && !Mailer::verifyCode($email, 'register', $code)) {
            return [false, '邮箱验证码错误或已过期'];
        }
        // 年龄限制（周岁，按出生日期精确计算）
        $minAge = (int)DB::setting('min_register_age', 0);
        if ($minAge > 0) {
            $age = self::age($birthdate);
            if ($age < 0) return [false, '请选择有效的出生日期'];
            if ($age < $minAge) return [false, '注册需年满 ' . $minAge . ' 周岁（当前 ' . $age . ' 周岁）'];
        }
        // 随机 ID 分配（nextUserId）：并发注册同时分到同一 id 会撞主键，
        // PDO 异常模式下捕获后重试（重新随机），最多 5 次
        $data = [
            'nickname' => $nick, 'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            // v1.3.14：默认「小可爱」+ 随机一档（1..45），用户进站后可随时换
            'avatar' => '', 'avatar_type' => 'generated',
            'avatar_style' => self::AVATAR_DEFAULT_STYLE,
            'avatar_seed' => (string)random_int(1, self::AVATAR_SEED_COUNT),
            'role' => 'member',
            'client_key' => Sec::clientKey(), 'status' => 1,
            'email_verified' => Mailer::needRegisterCode() ? 1 : 0,
            'birthdate' => $birthdate,
            'created_at' => time(),
            'reg_ip' => Sec::ip(),   // v1.2.56 用户概览：记录注册来源 IP
        ];
        $attempts = 0;
        while (true) {
            try {
                $data['id'] = self::nextUserId();
                $id = DB::insert('users', $data);
                break;
            } catch (Throwable $e) {
                if (++$attempts >= 5) throw $e;
            }
        }
        Sec::log('register', $nick, ['email' => $email, 'id' => $id]);
        return [true, '注册成功', $id];
    }

    /**
     * 登录：$identity 可为注册邮箱或数字用户 ID（取消用户名后的两种入口）。
     * ID 优先于邮箱匹配，保证纯数字身份不会被同名邮箱干扰；均为一次索引命中。
     *
     * v1.1.12 起每次失败都会 fire('login.failed', ...)，插件可据此做失败提醒、
     * 撞库统计、异地异常登录告警等。**与 login.after_verify 严格成对**：
     * 一个只在成功时触发、一个只在失败时触发，插件不必再自己判断方向。
     */
    public static function login(string $identity, string $password): array
    {
        $key = strtolower($identity) . '|' . Sec::ip();
        // 闭包统一收口：把「失败原因 / 剩余次数 / 命中的用户」补全后交给插件，
        // 保证各失败分支的钩子载荷形状一致，插件侧无需按 reason 做字段兼容。
        $fail = function (string $reason, string $msg, array $user = null, int $left = -1) use ($identity): array {
            if (class_exists('Plugin')) {
                Plugin::fire('login.failed', [[
                    'identity' => $identity,        // 用户提交的登录标识（原样，邮箱或用户 ID）
                    'reason'   => $reason,          // fail / locked / disabled
                    'msg'      => $msg,             // 用户看到的文案
                    'left'     => $left,            // 剩余尝试次数，-1 表示不适用
                    'user'     => $user,            // 命中的 users 行（密码错时为 null，防枚举）
                    'ip'       => Sec::ip(),
                ]]);
            }
            return [false, $msg, $reason];
        };

        if (Sec::loginLocked($key)) return $fail('locked', '失败次数过多，账号已临时锁定 15 分钟');
        $user = null;
        if (preg_match('/^\d{1,19}$/', $identity)) {
            $user = DB::one('SELECT * FROM users WHERE id=?', [(int)$identity]);
        }
        if (!$user) $user = DB::one('SELECT * FROM users WHERE email=?', [self::normEmail($identity)]);
        if (!$user || !password_verify($password, $user['password'])) {
            Sec::loginFail($key);
            Sec::log('login_fail', $identity);
            $left = 10 - Sec::loginFails($key);
            // ⚠️ 密码错时 $user 传 null：确认「账号存在」本身就是信息，
            // 交给插件就能变成账号枚举的侧信道，宁可少给一个字段。
            return $fail('fail', '邮箱或用户 ID 不正确，或密码错误' . ($left <= 3 ? "，剩余 $left 次尝试机会" : ''), null, $left);
        }
        if ((int)$user['status'] !== 1) return $fail('disabled', '账号已被禁用', $user);
        Sec::loginOk($key);
        session_regenerate_id(true);
        $_SESSION['uid'] = $user['id'];
        // v1.2.56：连同最后登录 IP 一起回写（用户概览展示用）
        DB::run('UPDATE users SET last_login=?, last_login_ip=? WHERE id=?', [time(), Sec::ip(), $user['id']]);
        Sec::log('login', $user['nickname']);
        // 登录验证完成钩子：供插件扩展两步验证、登录通知、异地提醒等。
        // $method 为本次通过验证的方式（核心仅有 password；插件实现两步验证时
        // 可自行触发本钩子并传入 totp / recovery 等）。仅成功登录触发，失败不触发。
        Plugin::fire('login.after_verify', [$user, 'password', ['ip' => Sec::ip()]]);
        return [true, '登录成功', $user];
    }

    /**
     * 登出。v1.1.12 起 fire('logout.before_destroy')：
     * **必须在 session_destroy() 之前触发**，否则 $_SESSION 已清空，
     * 插件拿不到 uid / 昵称 / 本次登录方式，只能记一条匿名日志。
     * 载荷为登录快照：$reason=manual（用户主动登出）| fingerprint（守卫踢出，见 session.destroyed）。
     */
    public static function logout(): void
    {
        $uid = (int)($_SESSION['uid'] ?? 0);
        $nick = (string)($_SESSION['uname'] ?? '');
        if (!$nick && $uid > 0) {
            // 项目已无 username（v1.0.33 起），此处只取昵称，**不得**反查邮箱
            $nick = (string)(DB::val('SELECT nickname FROM users WHERE id=?', [$uid]) ?: '');
        }
        if (class_exists('Plugin')) {
            Plugin::fire('logout.before_destroy', [[
                'uid'      => $uid,
                'nickname' => $nick,
                'had_uid'  => isset($_SESSION['uid']),
                'ip'       => Sec::ip(),
                'reason'   => 'manual',
            ]]);
        }
        Sec::log('logout', $nick);
        $_SESSION = [];
        session_destroy();
    }

    public static function resetPassword(string $email, string $code, string $password): array
    {
        $email = self::normEmail($email);
        $user = DB::one('SELECT * FROM users WHERE email=?', [$email]);
        if (!$user) return [false, '该邮箱未注册'];
        if (strlen($password) < 6) return [false, '密码至少 6 位'];
        if (!Mailer::verifyCode($email, 'reset', $code)) return [false, '验证码错误或已过期'];
        DB::run('UPDATE users SET password=? WHERE id=?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        Sec::log('reset_password', $user['nickname']);
        // v1.3.52：账号安全事件留痕。改密码后原会话仍然有效（不强制下线是既有行为），
        // 所以站内通知是用户察觉「密码被人动过」的唯一途径。
        Notice::push((int)$user['id'], 'pwd');
        return [true, '密码已重置，请重新登录'];
    }

    /**
     * 修改密码（v1.3.55，个人设置里的入口）。与「找回密码」的区别是这里人已经登录，
     * 所以凭证是**当前密码**——验证码只是第二道，不是替代品。
     *
     * ⚠️ 账号没有可用邮箱时不要求验证码（只验当前密码）。这不是漏洞而是刻意的兜底：
     * 「必须收到邮件才能改密码」在邮箱为空 / 早已失效的账号上等于永久改不了密码，
     * 而当前密码仍是那道真正的门。要强制全员验证码，请管理员先补齐邮箱再开启总开关。
     *
     * @return array [bool, string 文案]
     */
    public static function changePassword(int $uid, string $current, string $new, string $code = ''): array
    {
        $user = DB::one('SELECT * FROM users WHERE id=? AND status=1', [$uid]);
        if (!$user) return [false, '账号不存在或已被禁用'];
        if (!password_verify($current, (string)$user['password'])) {
            Sec::log('chpwd_fail', (string)$user['nickname']);
            return [false, '当前密码不正确'];
        }
        if (strlen($new) < 6) return [false, '新密码至少 6 位'];
        if ($new === $current) return [false, '新密码不能与当前密码相同'];
        $email = trim((string)($user['email'] ?? ''));
        // 只有「真有通道 + 邮箱可用」才要求验证码。没装邮件插件时硬要求，
        // 等于把改密码这条路彻底堵死（当前密码才是这里的真凭证）。
        $needCode = Mailer::deliverable() && filter_var($email, FILTER_VALIDATE_EMAIL);
        if ($needCode && !Mailer::verifyCode($email, 'chpwd', $code)) {
            Sec::log('chpwd_code_fail', (string)$user['nickname']);
            return [false, '邮箱验证码错误或已过期'];
        }
        DB::run('UPDATE users SET password=? WHERE id=?', [password_hash($new, PASSWORD_DEFAULT), $uid]);
        Sec::log('change_password', (string)$user['nickname'], ['code' => $needCode ? 1 : 0]);
        Notice::push($uid, 'pwd');
        return [true, '密码已修改'];
    }

    /**
     * 修改邮箱 / 注销账号**本轮不做**（这两个功能本身还没有）。
     * 将来做时不必新写一套校验：邮箱合法性用 `Mailer::checkEmail($e, ['scene' => 'change', 'exclude_uid' => $uid])`，
     * 发码用 `Mailer::sendCode($新邮箱, 'change', ...)`，验码用 `Mailer::verifyCode($新邮箱, 'change', $code)` ——
     * 长度、后缀白名单、唯一检查、五路限流都在 Mailer 里，插件停不停都一样生效。
     */

    /**
     * 资料更新：昵称 / 头像。
     * 昵称已是账号显示名，规则与注册保持一致（见 checkNickname），避免注册能填、改资料填不了的割裂。
     *
     * 历史同步：messages 表的 nickname / avatar / to_nickname 是发送时的快照，
     * 改资料后必须一并刷新，否则历史消息仍显示旧昵称旧头像（v1.0.39 修复）。
     * online 在线表无需处理：每次心跳都用实时 users 数据重写。
     */
    public static function updateProfile(array $user, string $nickname, string $avatar): array
    {
        // 传 uid：超管改自己的昵称时要排除自己那行，否则会撞上「昵称已被超管使用」
        [$ok, $nick] = self::checkNickname($nickname, ['scene' => 'profile', 'uid' => (int)$user['id']]);
        if (!$ok) return [false, $nick];
        Chat::filterText($nick, 'nickname');   // 敏感词过滤（sensitive-words 插件经 text.filter 钩子处理）
        $uid = (int)$user['id'];
        $avatar = trim($avatar);
        // v1.3.11：头像已独立成 setAvatar()（上传/生成式两条路），资料保存只在**显式传了路径**时才动它。
        // 以前是无条件覆盖，前端表单里恒传空串 → 每保存一次昵称就把头像清空。
        if ($avatar !== '') {
            if (strpos($avatar, 'uploads/') !== 0) return [false, '头像路径非法'];
            DB::run('UPDATE users SET nickname=?, avatar=?, avatar_type=? WHERE id=?', [$nick, $avatar, 'upload', $uid]);
            DB::run('UPDATE messages SET nickname=?, avatar=? WHERE user_id=?', [$nick, $avatar, $uid]);
        } else {
            DB::run('UPDATE users SET nickname=? WHERE id=?', [$nick, $uid]);
            DB::run('UPDATE messages SET nickname=? WHERE user_id=?', [$nick, $uid]);
        }
        DB::run('UPDATE messages SET to_nickname=? WHERE to_user_id=?', [$nick, $uid]);
        return [true, '资料已更新'];
    }

    /**
     * 设置头像（v1.3.11）。两种来源：
     *   upload    —— $path 为上传后的相对路径（由附件上传插件调用落库）
     *   generated —— $style/$seed 指定生成式头像，path 置空以免与类型冲突
     *
     * 选生成式时**清空 avatar 字段**：否则 avatarUrlFor 会因「有路径优先」继续显示旧图。
     * 换头像不改 nickname，因此不碰 messages 快照 —— 历史消息里的头像保持发送时的样子。
     */
    public static function setAvatar(array $user, string $type, string $path = '', string $style = '', string $seed = ''): array
    {
        $uid = (int)$user['id'];
        // v1.3.20：换头像后**同步本人历史消息的快照**，否则列表里的头像与设置里对不上。
        //
        // 背景：messages.avatar 是「发送当时的快照」，而 messages.nickname 一直
        // 都在 updateProfile 里被同步更新（改昵称会让历史消息一起变），头像却是
        // v1.3.11 拆出去后**漏了这条同步** —— 于是出现「改昵称全站一致、改头像只变自己」
        // 的不一致。快照保留的初衷是「用户注销后仍能显示当时的样子」，
        // 而注销后 avatarMap 查不到人，本来就会回落到快照，两种做法不冲突。
        if ($type === 'upload') {
            $path = ltrim(trim($path), '/');
            if ($path === '') return [false, '缺少头像文件'];
            // 只接受 uploads/ 下的路径，防目录穿越 / 外链
            if (strpos($path, 'uploads/') !== 0) return [false, '头像路径非法'];
            DB::run('UPDATE users SET avatar=?, avatar_type=?, avatar_style=?, avatar_seed=? WHERE id=?',
                [$path, 'upload', '', '', $uid]);
            self::syncMessageAvatar($uid, $path);
            return [true, '头像已更新'];
        }
        if ($type === 'generated') {
            $style = self::avatarStyle($style);
            $seed  = trim($seed);
            if ($seed === '') $seed = (string)$uid;
            $seed = mb_substr($seed, 0, 40);
            DB::run('UPDATE users SET avatar=?, avatar_type=?, avatar_style=?, avatar_seed=? WHERE id=?',
                ['', 'generated', $style, $seed, $uid]);
            // 生成式头像在 messages.avatar 里存的同样是**最终 URL**（send 时
            // 从 actor['avatar'] 取的），所以这里也算出来写进去，保持同一口径。
            self::syncMessageAvatar($uid, self::avatarGeneratedUrl($style, $seed));
            return [true, '头像已更新'];
        }
        return [false, '未知头像类型'];
    }

    /**
     * 同步本人历史消息里的头像快照（v1.3.20）。
     * 只改 avatar 一列，不动 nickname —— 昵称那条在 updateProfile 里已经做了。
     */
    private static function syncMessageAvatar(int $uid, string $avatarUrl): void
    {
        if ($uid <= 0) return;
        DB::run('UPDATE messages SET avatar=? WHERE user_id=?', [$avatarUrl, $uid]);
    }

    /** 角色权重（数值越大权限越高） */
    public static function roleLevel(string $role): int
    {
        return ['guest' => 0, 'member' => 1, 'vip' => 2, 'admin' => 9][$role] ?? 0;
    }

    public static function isAdmin(?array $user): bool
    {
        return $user && $user['role'] === 'admin';
    }

    /** 当前访问者摘要（前端展示/签名用） */
    public static function actor(?array $user, ?array $guest): array
    {
        if ($user) {
            return [
                'kind' => 'user', 'id' => (int)$user['id'],
                'nickname' => $user['nickname'],
                'role' => $user['role'], 'title' => $user['title'] ?? '',
                // v1.3.11：直接下发**最终 URL**（上传图或生成式），前端不再拼路径
                'avatar' => self::avatarUrlFor($user, (string)$user['id']),
                'key' => $user['client_key'],
                'birthdate' => (string)($user['birthdate'] ?? ''),
            ];
        }
        if ($guest) {
            return [
                'kind' => 'guest', 'id' => (int)$guest['id'],
                'nickname' => $guest['nickname'],
                'role' => 'guest', 'title' => '',
                // v1.3.14：游客也有自己的风格与档位（默认豆苗 sprouts）；
                // 存量游客行没有档位 → 仍按 client_key 派生，保证老访客头像不跳变。
                'avatar' => self::avatarUrlFor($guest, (string)($guest['client_key'] ?? 'guest')),
                'key' => $guest['client_key'],
            ];
        }
        // 未建立身份（连游客都没生成）：也给一个匿名头像，别留空白
        return ['kind' => 'none', 'key' => '', 'avatar' => self::avatarGeneratedUrl('', 'anonymous')];
    }

    // ================= 生成式头像（v1.3.11） =================
    //
    // 定位：头像是「风格 + 种子」的生成结果（模型参考 参考文献/bbs1org-main），
    // 而不是一张存路径的图片。好处是换风格只要改两个字段，不用重新上传。
    //
    // 三种来源，按优先级：
    //   1. upload    —— avatar_type='upload' 且 avatar 有路径（上传图，插件写入）
    //   2. generated —— DiceBear 官方 API（设置 avatar_source=api）
    //   3. identicon —— 内置纯 PHP 几何头像（设置 avatar_source=identicon，或远程 onerror 兜底）
    //
    // ⚠️ 存量兼容：老记录 avatar_type 为空但 avatar 有值 → 按「有上传路径就用上传」处理。

    /** 生成式头像的默认风格：注册用户 = 小可爱，游客 = 豆苗 */
    public const AVATAR_DEFAULT_STYLE = 'open-peeps';
    public const AVATAR_GUEST_STYLE  = 'sprouts';

    /**
     * 每种风格固定多少个变体（v1.3.14：按用户要求定为 45，对齐参考项目的做法）。
     * DiceBear 是参数化生成器：同风格下只有 seed 不同，因此「N 个变体」= seed 取 1..N。
     * 固定档位带来两个好处：① 用户选过一次就是稳定的一张（不会每次刷新变脸）；
     * ② 选择界面就是 1..45 的网格，所见即所得，与保存的值一一对应。
     */
    public const AVATAR_SEED_COUNT = 45;

    /**
     * 可选风格表。结构：slug => [中文名, 官方推荐参数（不含 seed）]。
     *
     * 参数来自 DiceBear 10.x 各风格的官方示例：它们决定了该风格的「性格」
     * （如 adventurer 关掉配饰、fun-emoji 指定眼睛与嘴型），
     * **换 seed 时这些参数保持不变** —— 用户看到的差异只来自种子。
     * 全部取自用户指定清单，去掉了 identicon（那是内置几何头像的同源名，不走 API）。
     */
    public static function avatarStyles(): array
    {
        static $s = null;
        if ($s !== null) return $s;
        $s = [
            // v1.3.15：用户指定的开头两个 —— 小可爱（注册默认）与豆苗（游客默认）
            'open-peeps'       => ['小可爱', 'backgroundColor=ff8fab,ffb703,4cc9a7,4d96ff,b57bff'],
            'sprouts'          => ['豆苗', 'patternProbability=0&cheeksProbability=0'],
            'adventurer'       => ['冒险者', 'backgroundColor=&detailsProbability=0&earringsProbability=0&glassesProbability=0'],
            'avataaars'        => ['阿凡达', 'backgroundColor=ffd5a8,ff9db4&backgroundColorFill=linear&backgroundColorAngle=45'],
            'big-ears'         => ['大耳朵', 'detailsProbability=0'],
            'big-smile'        => ['大笑', 'backgroundColor=ffe3ea,e3edff,e2f5e9,fdf1d4,efe6ff'],
            'bottts'           => ['机器人', 'backgroundColor=&textureProbability=0'],
            'clay'             => ['黏土', 'mouthVariant=teeth,smile,o,frown,line,wavy,toothy,pout,grin,smirk,zigzag,dot,cat,smileBig,uu,openSmall&bodyColor=83b0a4,86a5c3,9793bd,8ba06b,6f9fb8&accentColor=5d8b80,63819e,70689c,6b7d4f'],
            'critters'         => ['小动物', 'topProbability=0&patternProbability=0&cheeksProbability=0'],
            'croodles-neutral' => ['中性涂鸦', 'backgroundColor=ffffff'],
            'cutouts'          => ['剪纸', 'browsProbability=0&cheeksProbability=0'],
            'disco'            => ['迪斯科', 'scale=1.4'],
            'dylan'            => ['迪伦', 'facialHairProbability=0'],
            'fun-emoji'        => ['表情', 'eyesVariant=closed,closed2,cute,glasses,plain,sad,shades,sleepClose,wink,wink2&mouthVariant=cute,faceMask,lilSmile,plain,sad,shy,smileTeeth,wideSmile'],
            'gaze'             => ['凝视', 'shapeVariant=circle,square,triangle,pentagon,hexagon,octagon,diamond'],
            'glass'            => ['玻璃', 'backgroundColor=ff2e88,00e5ff,ffe600,7cff00,ff6a00,b400ff'],
            'glyphs'           => ['字形', 'glyphColor=2f6fb5,2f8f8a,4a5fb8,6b4bd6,2f9ec4'],
            'initial-face'     => ['首字母', 'backgroundColor=ffe3ea,e3edff,e2f5e9,fdf1d4,efe6ff'],
            'lorelei'          => ['洛丽', 'backgroundColor=&beardProbability=0&earringsProbability=0&frecklesProbability=0&glassesProbability=0&hairAccessoriesProbability=0'],
            'micah'            => ['米卡', 'backgroundColor=&earringsProbability=0&glassesProbability=0&facialHairProbability=0'],
            'miniavs'          => ['迷你人', 'backgroundColor=ffe3ea,e3edff,e2f5e9,fdf1d4,efe6ff'],
            'notionists'       => ['概念派', 'backgroundColor=&beardProbability=0&gestureProbability=0&glassesProbability=0&clothesGraphicProbability=0'],
            'pixel-art-neutral' => ['像素', ''],
            'shape-grid'       => ['网格', ''],
            'shapes'           => ['几何', ''],
            'thumbs'           => ['大拇指', ''],
            'toon-head'        => ['卡通头', 'beardProbability=0&rearHairProbability=0'],
            'voxel-art'        => ['体素', 'beardProbability=0&glassesProbability=0&cheeksProbability=0'],
            'voxel-bot'        => ['体素机器人', 'topProbability=0&chestProbability=0'],
            'waves'            => ['波浪', ''],
            'weave'            => ['编织', ''],
        ];
        return $s;
    }

    /** 校验风格名，非法回落默认风格 */
    public static function avatarStyle(string $style): string
    {
        $styles = self::avatarStyles();
        return ($style !== '' && isset($styles[$style])) ? $style : self::AVATAR_DEFAULT_STYLE;
    }

    /**
     * 头像 URL（provider 分流）。
     *
     * @param array  $row   users / rooms 的一行（需含 avatar/avatar_type/avatar_style/avatar_seed）
     * @param string $seedFallback 派生种子用的稳定值（uid 或 client_key）
     */
    public static function avatarUrlFor(array $row, string $seedFallback = ''): string
    {
        $type = (string)($row['avatar_type'] ?? '');
        $path = (string)($row['avatar'] ?? '');
        // 上传优先：avatar_type 缺省（存量）但有路径时，也按上传处理
        if ($path !== '' && ($type === '' || $type === 'upload')) {
            return self::avatarFileUrl($path);
        }
        $seed = (string)($row['avatar_seed'] ?? '');
        if ($seed === '') $seed = $seedFallback;
        return self::avatarGeneratedUrl((string)($row['avatar_style'] ?? ''), $seed);
    }

    /**
     * 上传文件的可访问 URL（头像、群头像走这里）。
     * 站点根拿不到时（CLI / host 缺失）退回相对路径 —— 前端按相对当前页解析。
     */
    public static function avatarFileUrl(string $rel): string
    {
        $rel = ltrim($rel, '/');
        if ($rel === '') return '';
        $base = function_exists('ow_site_url') ? ow_site_url() : '';
        return $base !== '' ? $base . '/' . $rel : $rel;
    }

    /**
     * 生成式头像 URL：设置 avatar_source 决定走 API 还是内置 identicon。
     * 插件可用 `avatar.url` 钩子改写最终地址（将来的本地化资源接这个口子）。
     */
    public static function avatarGeneratedUrl(string $style, string $seed): string
    {
        $style = self::avatarStyle($style);
        $seed  = $seed !== '' ? $seed : (string)random_int(1, self::AVATAR_SEED_COUNT);
        // v1.3.15：有定制 seed 表的风格，把「档位号」映射成真实 seed
        $map = self::avatarSeedMap($style);
        if ($map) {
            $i = (int)$seed;
            $seed = (string)($map[$i >= 1 && $i <= count($map) ? $i - 1 : 0]);
        }
        $source = self::avatarSource();
        $url = ($source === 'identicon')
            ? self::avatarIdenticon($seed)
            : 'https://api.dicebear.com/10.x/' . rawurlencode($style) . '/svg?'
                . self::avatarStyles()[$style][1] . (self::avatarStyles()[$style][1] !== '' ? '&' : '') . 'seed=' . rawurlencode($seed);
        if (class_exists('Plugin')) {
            // ⚠️ fire() 没有返回值，插件改写靠**引用传参**：
            //    Plugin::on('avatar.url', function (&$url, $ctx) { $url = '...'; });
            Plugin::fire('avatar.url', [&$url, ['style' => $style, 'seed' => $seed, 'source' => $source]]);
        }
        return $url;
    }

    /** 头像来源：api（官方 API，默认）| identicon（内置几何，零请求） */
    public static function avatarSource(): string
    {
        try { $s = (string)DB::setting('avatar_source', 'api'); } catch (Throwable $e) { $s = 'api'; }
        return $s === 'identicon' ? 'identicon' : 'api';
    }

    /**
     * 内置 identicon：5×5 对称网格 + 色相哈希，纯 PHP 生成 SVG（data URI）。
     *
     * 为什么要有它：官方 API 在国内访问不稳定，`<img>` 加载失败就是一片碎图；
     * 前端 onerror 兜底到这个（见 chat.js 的 owAvatarImg），保证永不破相，
     * 同时也是「内网 / 离线部署」与 avatar_source=identicon 时的唯一依赖。
     * 算法：crc32(seed) → 色相；取 11 位二进制 → 左三列决定图案，右两列镜像。
     */
    public static function avatarIdenticon(string $seed): string
    {
        $hash = (int)(sprintf('%u', crc32($seed !== '' ? $seed : 'owl')));
        $hue  = $hash % 360;
        $fg   = self::hslHex($hue, 62, 48);
        $bg   = self::hslHex(($hue + 200) % 360, 42, 90);
        $bits = ($hash >> 5) & 0x7FF;      // 11 位够画 3×5
        $rects = '';
        for ($y = 0; $y < 5; $y++) {
            for ($x = 0; $x < 3; $x++) {
                if (!($bits & (1 << ($y * 3 + $x)))) continue;
                $rects .= '<rect x="' . $x . '" y="' . $y . '" width="1" height="1"/>'
                        . '<rect x="' . (4 - $x) . '" y="' . $y . '" width="1" height="1"/>';
            }
        }
        // ⚠️ width/height 必须写死：只有 viewBox 的 SVG 没有固有尺寸，
        // 放进 <img> + CSS width:100%/height:100% 时部分浏览器会按 0 宽渲染成一条竖线。
        // 颜色一律用十六进制：SVG 1.1 的 fill 属性不认 hsl()（CSS Color 4 才有），
        // 用 hsl 会让整张图填不上色，只剩默认黑块。
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="5" height="5" viewBox="0 0 5 5"'
             . ' preserveAspectRatio="xMidYMid meet" shape-rendering="crispEdges">'
             . '<rect width="5" height="5" fill="' . $bg . '"/>'
             . '<g fill="' . $fg . '">' . $rects . '</g></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /** HSL → #RRGGBB（供 SVG 属性用；S/L 为 0-100） */
    private static function hslHex(int $h, int $s, int $l): string
    {
        $s /= 100; $l /= 100;
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        $rgb = [0.0, 0.0, 0.0];
        if ($h < 60)       $rgb = [$c, $x, 0];
        elseif ($h < 120)  $rgb = [$x, $c, 0];
        elseif ($h < 180)  $rgb = [0, $c, $x];
        elseif ($h < 240)  $rgb = [0, $x, $c];
        elseif ($h < 300)  $rgb = [$x, 0, $c];
        else               $rgb = [$c, 0, $x];
        $out = '#';
        foreach ($rgb as $v) {
            $n = (int)round(($v + $m) * 255);
            $n = max(0, min(255, $n));
            $out .= str_pad(dechex($n), 2, '0', STR_PAD_LEFT);
        }
        return $out;
    }

    /**
     * open-peeps 专用的 45 个 seed（v1.3.15）。
     *
     * 为什么需要：DiceBear 10.x 的 open-peeps 不支持 skinTone 参数（实测传了也不生效，
     * 返回配色与默认完全一致），肤色只能由 seed 决定。而默认 seed 1..45 的肤色分布是
     * 浅 13 / 中 9 / 深 14 —— 深色偏多，正是用户反馈的「一堆黑人」。
     * 这里从 1..200 实测肤色后按 **白 18 / 黄 13 / 深 14** 挑选并**打散**排列
     * （打散是为了网格里肤色均匀，不出现前 18 格全是白人）。
     * 数组下标 +1 = 用户看到的「第 N 个头像」，与其它风格的口径完全一致。
     */
    private const OPEN_PEEPS_SEEDS = [
        2, 15, 21, 25, 31, 33, 1, 10, 18, 47, 6, 20, 29, 39, 49,
        41, 43, 50, 51, 56, 58, 69, 78, 89, 105, 114, 63, 83, 93, 107,
        60, 65, 67, 68, 75, 85, 126, 142, 156, 172, 124, 134, 147, 155, 165,
    ];

    /**
     * 风格的「档位 → 真实 seed」映射表。没有定制 seed 的风格返回空数组。
     * 保存的是**档位号**（1..45），出 URL 时才映射成真实 seed —— 换风格表不影响已存数据。
     */
    public static function avatarSeedMap(string $style): array
    {
        if (self::avatarStyle($style) === 'open-peeps') return self::OPEN_PEEPS_SEEDS;
        return [];
    }

    /**
     * 某风格的 45 个变体（v1.3.14）：点风格 → 二级选择界面直接复用同一套网格样式。
     * 服务端出 URL 而不是前端拼 —— 参数与保存口径永远一致，不会两边算法漂移。
     * @return array [{seed:int, url:string}, ...] 共 AVATAR_SEED_COUNT 项
     */
    public static function avatarVariants(string $style): array
    {
        $style = self::avatarStyle($style);
        $out = [];
        for ($i = 1; $i <= self::AVATAR_SEED_COUNT; $i++) {
            $out[] = ['seed' => $i, 'url' => self::avatarGeneratedUrl($style, (string)$i)];
        }
        return $out;
    }

    /**
     * 风格选择面板用的清单（给前端渲染网格）：slug + 中文名 + 预览 URL。
     * 预览图用随机种子，只用于选样式，不影响最终头像。
     */
    public static function avatarStyleOptions(int $previewSeed = 0): array
    {
        $out = [];
        $seed = $previewSeed > 0 ? (string)$previewSeed : (string)random_int(1, 100000);
        foreach (self::avatarStyles() as $slug => $meta) {
            $out[] = [
                'slug'  => $slug,
                'label' => $meta[0],
                'url'   => self::avatarGeneratedUrl($slug, $slug . '-' . $seed),
            ];
        }
        return $out;
    }
}
