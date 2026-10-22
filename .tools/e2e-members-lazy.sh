#!/usr/bin/env bash
# 右侧栏「所有成员」按需加载 e2e（v1.5.29）
#
# 跑在隔离副本 .t-ml（php -S 8851 + 仓库 DB 快照 + 自建测试账号），不触碰运行副本。
#
# 钉住的契约：
#   poll  不带 want_members → 响应里**没有** members / guests 键（老前端靠缺键退回 renderOnline）
#   poll  want_members=1    → 有 members（成员数正确）与 guests
#   room_members 默认       → data + members（第三方插件 batch-ops / 成员管理弹窗照旧可用）
#   room_members lite=1     → 无 data / members，只有闸门字段（is_member / is_public / online_status）
#   room_join  默认         → 无 members；want_members=1 才有
#
# 两个环境坑（与 e2e-admin-lazy 同源）：
#   · 取 owl_akey 必须 awk 按字段取（grep 的字符类会吞末位，签名必然对不上）；
#   · 明文请求要带 X-Forwarded-Proto: https（enforceHttps 会 301），且副本 config 必须
#     trust_proxy=true 才认这个头。
#   · curl 一律 --noproxy '*'：本机环境变量里有代理，会把 127.0.0.1 也劫走。
set -u

PHP="/d/Program Files/phpstudy_pro/Extensions/php/php8.2.9nts/php.exe"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
T="$ROOT/.t-ml"
PORT=8851
B="http://127.0.0.1:$PORT"
PASS=0; FAIL=0

ok()   { PASS=$((PASS+1)); echo "  [PASS] $1"; }
bad()  { FAIL=$((FAIL+1)); echo "  [FAIL] $1（实得 [$2] 期望 [$3]）"; }
ck()   { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "$2" "$3"; fi; }
has()  { case "$3" in *"$2"*) ok "$1";; *) FAIL=$((FAIL+1)); echo "  [FAIL] $1（未找到 [$2]）"; echo "         实际=${3:0:220}";; esac; }
nhas() { case "$3" in *"$2"*) FAIL=$((FAIL+1)); echo "  [FAIL] $1（不应出现 [$2]）";; *) ok "$1";; esac; }

# ---------- 1) 隔离副本 ----------
echo "=== 1) 准备隔离副本 .t-ml ==="
rm -rf "$T"; mkdir -p "$T/data"
cp -r "$ROOT/core" "$ROOT/plugins" "$ROOT/assets" "$T/"
cp "$ROOT/index.php" "$ROOT/index.js" "$ROOT/index.css" "$ROOT/sw.js" "$T/"
cp "$ROOT/data/owlsgo.db" "$T/data/"
printf '1' > "$T/data/install.lock"
sed -i "s|'sign_window'  => 1800,|'sign_window'  => 1800,\n    'trust_proxy'  => true,|" "$T/core/config.php"
grep -q trust_proxy "$T/core/config.php" || { echo "config 打补丁失败"; exit 1; }
echo "  OK"

# ---------- 2) 种子数据 ----------
echo "=== 2) 种子：群主 / 成员 / 局外人 + 房间成员行 ==="
"$PHP" -r '
$db = new PDO("sqlite:" . $argv[1]);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$h = password_hash("T3st!lazy", PASSWORD_DEFAULT);
$now = time();
$db->exec("DELETE FROM room_members");
$db->exec("DELETE FROM users");
$ins = $db->prepare("INSERT INTO users (id,nickname,email,password,role,status,created_at) VALUES (?,?,?,?,?,1,?)");
$ins->execute([1, "群主",     "owner@test.local",    $h, "admin",  $now]);
$ins->execute([2, "普通成员", "member@test.local",   $h, "member", $now]);
$ins->execute([3, "局外人",   "outsider@test.local", $h, "member", $now]);
$db->exec("INSERT INTO room_members (room_id,user_id,created_at) VALUES (1,1,$now),(1,2,$now)");
echo "  seeded\n";
' "$T/data/owlsgo.db" || exit 1

# ---------- 3) 起服务 ----------
"$PHP" -d opcache.enable_cli=0 -S 127.0.0.1:$PORT -t "$T" > "$T/srv.log" 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null; sleep 0.3; rm -rf "$T"' EXIT
for _ in $(seq 1 40); do
    curl -s --noproxy '*' -o /dev/null "$B/?page=login" && break
    sleep 0.25
done

# ---------- 4) helper ----------
akey() { awk '$6=="owl_akey"{v=$7} END{print v}' "$1"; }
sign() { "$PHP" -r 'echo md5($argv[1]."|".$argv[2]."|".$argv[3]);' "$1" "$2" "$3"; }

login() {   # login <email> <jar>
    local email="$1" jar="$2" ak ts sg
    rm -f "$jar"
    curl -s --noproxy '*' -H "X-Forwarded-Proto: https" -c "$jar" "$B/?page=login" -o /dev/null
    ak=$(akey "$jar"); ts=$(date +%s); sg=$(sign "$ak" "$ts" login)
    curl -s --noproxy '*' -H "X-Forwarded-Proto: https" -b "$jar" -c "$jar" -X POST "$B/?action=login" \
        --data-urlencode "ts=$ts" --data-urlencode "sign=$sg" \
        --data-urlencode "identity=$email" --data-urlencode "password=T3st!lazy"
}

api() {     # api <jar> <action> [curl --data-urlencode pairs...]
    local jar="$1" act="$2"; shift 2
    local ak ts sg
    ak=$(akey "$jar"); ts=$(date +%s); sg=$(sign "$ak" "$ts" "$act")
    curl -s --noproxy '*' -H "X-Forwarded-Proto: https" -b "$jar" -c "$jar" -X POST "$B/?action=$act" \
        --data-urlencode "ts=$ts" --data-urlencode "sign=$sg" "$@"
}

# jqk <json> <php 表达式（$d 为解出的数组）> → 打印标量或数组长度
jqk() {
    "$PHP" -r '
$d = json_decode($argv[1], true);
if (!is_array($d)) { echo "ERR"; exit; }
eval("\$v = " . $argv[2] . ";");
echo is_bool($v) ? ($v ? "1" : "0") : (is_array($v) ? count($v) : (string)$v);
' "$1" "$2"
}

# 插一条消息并返回 id（poll 靠 since=id-1 立即返回，不必等 20 秒长轮询超时）
say() {
    "$PHP" -r '
$db = new PDO("sqlite:" . $argv[1]);
$db->exec("INSERT INTO messages (room_id,user_id,nickname,role,type,content,created_at)
           VALUES (1,2,\"普通成员\",\"member\",\"text\",\"hi\",\"" . time() . "\")");
echo $db->lastInsertId();
' "$T/data/owlsgo.db"
}

# ---------- 5) 登录 ----------
echo
echo "=== 3) 登录 ==="
R=$(login owner@test.local "$T/owner.jar")
has "群主登录成功" '"ok":true' "$R"
[ "$FAIL" -eq 0 ] || { echo "登录失败，终止"; tail -20 "$T/srv.log"; exit 1; }

# ---------- 6) poll：默认不带成员 ----------
echo
echo "=== 4) poll：默认不带成员名单（右侧栏收起时的真实请求） ==="
N1=$(say)
R=$(api "$T/owner.jar" poll --data-urlencode "room_id=1" --data-urlencode "since=$((N1-1))")
ck "poll 响应里没有 members 键" "$(jqk "$R" 'array_key_exists("members",$d) ? 1 : 0')" "0"
ck "poll 响应里没有 guests 键"  "$(jqk "$R" 'array_key_exists("guests",$d) ? 1 : 0')" "0"
ck "poll 仍返回 messages（消息主通道不受影响）" "$(jqk "$R" 'count($d["messages"] ?? [])')" "1"
ck "poll 仍返回 online_status（在线点显隐口径不变）" "$(jqk "$R" 'array_key_exists("online_status",$d) ? 1 : 0')" "1"
nhas "poll 无 PHP 报错" 'Warning:' "$R"
nhas "poll 无 Fatal" 'Fatal error' "$R"

# ---------- 7) poll：want_members=1 ----------
echo
echo "=== 5) poll：want_members=1（右侧栏打开时） ==="
N2=$(say)
R=$(api "$T/owner.jar" poll --data-urlencode "room_id=1" --data-urlencode "since=$((N2-1))" --data-urlencode "want_members=1")
ck "poll want_members=1 有 members 键" "$(jqk "$R" 'array_key_exists("members",$d) ? 1 : 0')" "1"
ck "成员数 = 2（群主 + 普通成员）" "$(jqk "$R" 'count($d["members"] ?? [])')" "2"
ck "guests 键一并下发" "$(jqk "$R" 'array_key_exists("guests",$d) ? 1 : 0')" "1"
ck "成员行含 uid/nickname/role" "$(jqk "$R" '(isset($d["members"][0]["uid"],$d["members"][0]["nickname"],$d["members"][0]["role"])) ? 1 : 0')" "1"

# ---------- 8) room_members：默认全量（第三方契约不变） ----------
echo
echo "=== 6) room_members：默认仍返回全量（batch-ops / 成员管理弹窗契约） ==="
R=$(api "$T/owner.jar" room_members --data-urlencode "room_id=1")
ck "默认有 data（成员管理用）" "$(jqk "$R" 'array_key_exists("data",$d) ? 1 : 0')" "1"
ck "默认有 members（所有成员列表）" "$(jqk "$R" 'count($d["members"] ?? [])')" "2"
ck "默认有 is_member" "$(jqk "$R" 'isset($d["is_member"]) ? 1 : 0')" "1"

# ---------- 9) room_members：lite=1 ----------
echo
echo "=== 7) room_members lite=1：只要闸门字段（切群时的轻请求） ==="
R=$(api "$T/owner.jar" room_members --data-urlencode "room_id=1" --data-urlencode "lite=1")
ck "lite 不返回 data" "$(jqk "$R" 'array_key_exists("data",$d) ? 1 : 0')" "0"
ck "lite 不返回 members" "$(jqk "$R" 'array_key_exists("members",$d) ? 1 : 0')" "0"
ck "lite 不返回 guests" "$(jqk "$R" 'array_key_exists("guests",$d) ? 1 : 0')" "0"
ck "lite is_member = true（群主在成员表里）" "$(jqk "$R" '$d["is_member"] ?? null')" "1"
ck "lite is_public = true" "$(jqk "$R" '$d["is_public"] ?? null')" "1"
ck "lite 有 online_status" "$(jqk "$R" 'array_key_exists("online_status",$d) ? 1 : 0')" "1"
ck "响应体足够小（< 600B，无名单）" "$( [ "${#R}" -lt 600 ] && echo 1 || echo 0 )" "1"

# ---------- 10) 非成员：lite 能校准闸门 ----------
echo
echo "=== 8) 局外人 lite=1 → is_member=false（闸门据此显示「加入群聊」） ==="
R=$(login outsider@test.local "$T/out.jar")
has "局外人登录成功" '"ok":true' "$R"
R=$(api "$T/out.jar" room_members --data-urlencode "room_id=1" --data-urlencode "lite=1")
ck "非成员 is_member = false" "$(jqk "$R" '$d["is_member"] ? 1 : 0')" "0"
ck "非成员 is_public = true" "$(jqk "$R" '$d["is_public"] ?? null')" "1"

# ---------- 11) room_join ----------
echo
echo "=== 9) room_join：默认不返回名单，want_members=1 才返回 ==="
R=$(api "$T/owner.jar" room_join --data-urlencode "room_id=1")
ck "room_join 默认无 members 键" "$(jqk "$R" 'array_key_exists("members",$d) ? 1 : 0')" "0"
ck "room_join 仍有 is_member" "$(jqk "$R" 'isset($d["is_member"]) ? 1 : 0')" "1"
R=$(api "$T/owner.jar" room_join --data-urlencode "room_id=1" --data-urlencode "want_members=1")
ck "room_join want_members=1 有 members" "$(jqk "$R" 'count($d["members"] ?? [])')" "2"

# ---------- 12) 服务端日志无致命错误 ----------
echo
echo "=== 10) 服务端日志 ==="
case "$(cat "$T/srv.log" 2>/dev/null)" in
    *"Fatal error"*|*"Parse error"*) FAIL=$((FAIL+1)); echo "  [FAIL] 服务端有致命错误"; tail -5 "$T/srv.log";;
    *) ok "服务端无致命错误";;
esac

echo
if [ "$FAIL" -eq 0 ]; then echo "ALL PASS ($PASS 项)"; else echo "$FAIL 项失败，$PASS 项通过"; fi
[ "$FAIL" -eq 0 ]
