#!/usr/bin/env bash
# 端到端验证：积分到账的系统通知（红包 v1.0.4 / 星光殿堂 v1.1.5 / 用户管理 v1.0.2）
# 跑在隔离副本 .t-pts 上（php -S 127.0.0.1:8845），绝不触碰运行副本的 data/。
# 用法：bash .tools/e2e-points-notice.sh
set -u
PHP="D:/Program Files/phpstudy_pro/Extensions/php/php8.2.9nts/php.exe"
B="http://127.0.0.1:8845"
SECRET=$("$PHP" -r 'echo (require ".t-pts/core/config.php")["secret"];')
PASS=0; FAIL=0
ck() { if [ "$2" = "$3" ]; then PASS=$((PASS+1)); echo "  [PASS] $1"
       else FAIL=$((FAIL+1)); echo "  [FAIL] $1"; echo "         实际=[$2] 期望=[$3]"; fi; }
has() { case "$3" in *"$2"*) PASS=$((PASS+1)); echo "  [PASS] $1";;
        *) FAIL=$((FAIL+1)); echo "  [FAIL] $1（未找到 [$2]）"; echo "         实际=$3";; esac; }
sign() { "$PHP" -r 'echo md5($argv[1]."|".$argv[2]."|".$argv[3]);' "$1" "$2" "$3"; }
jget() { "$PHP" -r '$j=json_decode(file_get_contents("php://stdin"),true); $p=explode(".",$argv[1]); foreach($p as $k){ if($k==="")continue; $j=(is_array($j)&&array_key_exists($k,$j))?$j[$k]:null; } echo is_scalar($j)?$j:json_encode($j,JSON_UNESCAPED_UNICODE);' "$1"; }
q() { "$PHP" -r '$db=new PDO("sqlite:.t-pts/data/owlsgo.db"); echo (string)$db->query($argv[1])->fetchColumn();' "$1"; }

api() { local C="$1" K="$2" A="$3"; shift 3; local t; t=$(date +%s)
        local args=(--data-urlencode "ts=$t" --data-urlencode "sign=$(sign "$K" "$t" "$A")")
        local kv; for kv in "$@"; do args+=(--data-urlencode "$kv"); done
        curl -s -H "X-Forwarded-Proto: https" -b "$C" -X POST "$B/?action=$A" "${args[@]}"; }

# 登录并把登录后的 API key 写进变量（$3 = 输出变量名）
login() { local C="$1" ID="$2" name="$3"
  curl -s -H "X-Forwarded-Proto: https" -c "$C" -b "$C" "$B/?page=login" -o /dev/null
  local AK TS R
  AK=$(grep -oE 'owl_akey[[:space:]]+[a-f0-9]{32}' "$C" | tail -1 | awk '{print $NF}')
  TS=$(date +%s)
  R=$(curl -s -H "X-Forwarded-Proto: https" -c "$C" -b "$C" -X POST "$B/?action=login" \
        --data-urlencode "ts=$TS" --data-urlencode "sign=$(sign "$AK" "$TS" login)" \
        --data-urlencode "identity=$ID" --data-urlencode "password=T3st!points")
  ck "登录 $ID" "$(printf '%s' "$R" | jget ok)" "1"
  local K
  K=$(curl -s -H "X-Forwarded-Proto: https" -b "$C" "$B/" | grep -oE '"key":"[a-f0-9]{32}"' | head -1 | cut -d'"' -f4)
  [ -n "$K" ] || { echo "  [FAIL] 取不到 $ID 的 API key"; exit 1; }
  eval "$name=$K"
}

pt() { q "SELECT points FROM users WHERE id=265"; }
nc() { q "SELECT COUNT(*) FROM notices WHERE user_id=265"; }

# 每轮都从干净状态起步：改测试账号密码 → 清掉通知 / 签到 / 红包，避免上一轮的数据把断言带偏
"$PHP" -r '$db=new PDO("sqlite:.t-pts/data/owlsgo.db");
$st=$db->prepare("UPDATE users SET password=? WHERE id IN (1,265)");
$st->execute([password_hash("T3st!points", PASSWORD_DEFAULT)]);
$db->exec("DELETE FROM notices WHERE user_id IN (265,961)");
$db->exec("DELETE FROM plugin_checkin_log WHERE user_id IN (265,961)");
$db->exec("DELETE FROM plugin_checkin_user WHERE user_id IN (265,961)");
$db->exec("DELETE FROM plugin_red_packet_claim");
$db->exec("DELETE FROM plugin_red_packet_item");
$db->exec("DELETE FROM plugin_red_packet");'

echo "=== 0) 登录 ==="
login .t-pts/u.txt yes-hrym@outlook.com UK
login .t-pts/a.txt no-hrym@outlook.com AKEY
P0=$(pt); echo "  起始积分 = $P0"

echo
echo "=== 1) 通知类型表：插件经 notice.kinds 注册的模板必须在 texts 里 ==="
T=$(api .t-pts/u.txt "$UK" notices)
for k in rp_claimed rp_refund_expired rp_refund_void rp_refund_leftover shc_checkin shc_checkin_admin um_points_grant; do
  has "texts 含 $k" "\"$k\"" "$(printf '%s' "$T" | jget texts)"
done
ck "起始通知条数" "$(printf '%s' "$T" | jget data | "$PHP" -r 'echo count(json_decode(file_get_contents("php://stdin"),true)??[]);')" "0"

echo
echo "=== 1.5) 管理员代签 → 被代签的人收到 shc_checkin_admin（不是他自己点的） ==="
TK=$(api .t-pts/a.txt "$AKEY" ticket | jget ticket)
R=$(api .t-pts/a.txt "$AKEY" plugin_starlight_hall_checkin_user_op "uid=961" "op=sign" "ticket=$TK")
ck "代签成功" "$(printf '%s' "$R" | jget ok)" "1"
ck "961 的通知 kind" "$(q "SELECT kind FROM notices WHERE user_id=961 ORDER BY id DESC LIMIT 1")" "shc_checkin_admin"
K961=$(q "SELECT body FROM notices WHERE user_id=961 ORDER BY id DESC LIMIT 1")
has "代签文案说明是管理员代签" "管理员为你代签" "$K961"

echo
echo "=== 2) 签到 → 积分增加 + 一条 shc_checkin 通知 ==="
R=$(api .t-pts/u.txt "$UK" plugin_starlight_hall_checkin_do)
ck "签到成功" "$(printf '%s' "$R" | jget ok)" "1"
GAIN=$(printf '%s' "$R" | jget points)
echo "  本次签到发放 = $GAIN"
ck "积分到账" "$(pt)" "$((P0 + GAIN))"
N=$(api .t-pts/u.txt "$UK" notices)
ck "通知 +1" "$(nc)" "1"
ck "最新通知 kind" "$(printf '%s' "$N" | jget data.0.kind)" "shc_checkin"
ck "通知里的积分数" "$(printf '%s' "$N" | jget data.0.params.points)" "$GAIN"
UNIT=$(q "SELECT v FROM settings WHERE k='points_name'")
has "正文用站点的积分名称（$UNIT）" "$UNIT" "$(printf '%s' "$N" | jget data.0.body)"

echo
echo "=== 3) 发红包 → 自己领一份 → rp_claimed 通知 ==="
P1=$(pt)
R=$(api .t-pts/u.txt "$UK" plugin_red_packet_create "room_id=1" "kind=normal" "total=10" "count=2" "blessing=测试红包")
ck "发红包成功" "$(printf '%s' "$R" | jget ok)" "1"
PID=$(q "SELECT MAX(id) FROM plugin_red_packet")
ck "发红包扣费 10" "$(pt)" "$((P1 - 10))"
R=$(api .t-pts/u.txt "$UK" plugin_red_packet_claim "id=$PID")
ck "领取成功" "$(printf '%s' "$R" | jget ok)" "1"
ck "领到 5" "$(printf '%s' "$R" | jget amount)" "5"
ck "领取后余额" "$(pt)" "$((P1 - 5))"
N=$(api .t-pts/u.txt "$UK" notices)
ck "通知 +1（共 2）" "$(nc)" "2"
ck "最新 kind" "$(printf '%s' "$N" | jget data.0.kind)" "rp_claimed"
ck "通知金额" "$(printf '%s' "$N" | jget data.0.params.amount)" "5"
ck "通知余额" "$(printf '%s' "$N" | jget data.0.params.balance)" "$(pt)"
ck "发包人昵称进文案" "$(printf '%s' "$N" | jget data.0.params.sender)" "哈哈哈"
has "领取通知是整句模板" "你领取了" "$(printf '%s' "$N" | jget data.0.body)"

echo
echo "=== 4) 红包到期 → 维护任务退回余款 → rp_refund_expired 通知 ==="
P2=$(pt)
q "UPDATE plugin_red_packet SET expire_at="$(date +%s)"-60 WHERE id=$PID" > /dev/null
TK=$(api .t-pts/a.txt "$AKEY" ticket | jget ticket)
R=$(api .t-pts/a.txt "$AKEY" admin_cron_run "ticket=$TK")
ck "维护任务执行成功" "$(printf '%s' "$R" | jget ok)" "1"
ck "余款 5 已退回" "$(pt)" "$((P2 + 5))"
N=$(api .t-pts/u.txt "$UK" notices)
ck "通知 +1（共 3）" "$(nc)" "3"
ck "最新 kind" "$(printf '%s' "$N" | jget data.0.kind)" "rp_refund_expired"
ck "退回金额" "$(printf '%s' "$N" | jget data.0.params.amount)" "5"
ck "包已过期" "$(q "SELECT status FROM plugin_red_packet WHERE id=$PID")" "expired"

echo
echo "=== 5) 后台把积分调高 → um_points_grant 通知（只发差额） ==="
P3=$(pt)
TK=$(api .t-pts/a.txt "$AKEY" ticket | jget ticket)
R=$(api .t-pts/a.txt "$AKEY" plugin_user_manager_save "id=265" "role=member" "title=" "points=$((P3 + 40))" "ticket=$TK")
ck "后台保存成功" "$(printf '%s' "$R" | jget ok)" "1"
ck "积分已改" "$(pt)" "$((P3 + 40))"
N=$(api .t-pts/u.txt "$UK" notices)
ck "通知 +1（共 4）" "$(nc)" "4"
ck "最新 kind" "$(printf '%s' "$N" | jget data.0.kind)" "um_points_grant"
ck "只发差额 40" "$(printf '%s' "$N" | jget data.0.params.points)" "40"

echo
echo "=== 6) 边界：同值 / 调低 不产生通知 ==="
TK=$(api .t-pts/a.txt "$AKEY" ticket | jget ticket)
api .t-pts/a.txt "$AKEY" plugin_user_manager_save "id=265" "role=member" "title=" "points=$((P3 + 40))" "ticket=$TK" > /dev/null
ck "同值不通知" "$(nc)" "4"
TK=$(api .t-pts/a.txt "$AKEY" ticket | jget ticket)
api .t-pts/a.txt "$AKEY" plugin_user_manager_save "id=265" "role=member" "title=" "points=$((P3 + 10))" "ticket=$TK" > /dev/null
ck "调低不通知" "$(nc)" "4"

echo
echo "=== 6.5) 存量余款兜底（done 但仍有 remain_total）→ rp_refund_leftover ==="
"$PHP" -r '$db=new PDO("sqlite:.t-pts/data/owlsgo.db");
$t=time();
$db->exec("INSERT INTO plugin_red_packet (room_id,sender_id,sender_nick,kind,total,cnt,remain_cnt,remain_total,pass_hash,blessing,msg_id,status,created_at,expire_at) VALUES (1,265,\"哈哈哈\",\"normal\",8,2,0,8,\"\",\"存量余款测试\",0,\"done\",$t,$t)");'
P4=$(pt)
TK=$(api .t-pts/a.txt "$AKEY" ticket | jget ticket)
api .t-pts/a.txt "$AKEY" admin_cron_run "ticket=$TK" > /dev/null
ck "余款 8 已退回" "$(pt)" "$((P4 + 8))"
N=$(api .t-pts/u.txt "$UK" notices)
ck "最新 kind" "$(printf '%s' "$N" | jget data.0.kind)" "rp_refund_leftover"
ck "退回金额 8" "$(printf '%s' "$N" | jget data.0.params.amount)" "8"

echo
echo "=== 7) 英文界面：模板可整句翻译（lang-en）==="
grep -q "You opened {sender}'s red packet" plugins/lang-en/lang.js && { PASS=$((PASS+1)); echo "  [PASS] rp_claimed 英文模板已补"; } || { FAIL=$((FAIL+1)); echo "  [FAIL] rp_claimed 英文模板缺失"; }
grep -q "Checked in {streak} days in a row" plugins/lang-en/lang.js && { PASS=$((PASS+1)); echo "  [PASS] shc_checkin 英文模板已补"; } || { FAIL=$((FAIL+1)); echo "  [FAIL] shc_checkin 英文模板缺失"; }

echo
echo "=== 汇总：PASS=$PASS FAIL=$FAIL ==="
[ "$FAIL" -eq 0 ] || exit 1
