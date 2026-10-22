#!/usr/bin/env bash
# 端到端验证：用户管理 v1.1.0「登录网络 / IP 封禁」
# 跑在隔离副本 .t-um 上（php -S 8847 + 覆盖 REMOTE_ADDR 的路由脚本），绝不触碰运行副本。
# 覆盖：建表 → 登录落库 → 安全画像接口 → 封禁 → 非管理员 403 → 管理员放行 →
#       解封 → 参数校验 → 归属地开关 → 高风险 IP 聚合 → 聊天页不回归。
# 用法：bash .tools/e2e-user-ip.sh
set -u
PHP="D:/Program Files/phpstudy_pro/Extensions/php/php8.2.9nts/php.exe"
RT="D:/Program Files/phpstudy_pro/WWW/owlsgo"
REPO="D:/project/owlsgo"
T="$REPO/.t-um"
B="http://127.0.0.1:8847"
PASS=0; FAIL=0
ck()  { if [ "$2" = "$3" ]; then PASS=$((PASS+1)); echo "  [PASS] $1";
        else FAIL=$((FAIL+1)); echo "  [FAIL] $1"; echo "         实际=[$2] 期望=[$3]"; fi; }
has() { case "$3" in *"$2"*) PASS=$((PASS+1)); echo "  [PASS] $1";;
        *) FAIL=$((FAIL+1)); echo "  [FAIL] $1（未找到 [$2]）"; echo "         实际=${3:0:200}";; esac; }
cu()  { curl -s --noproxy '*' "$@"; }
sign(){ "$PHP" -d opcache.enable_cli=0 -r 'echo md5($argv[1]."|".$argv[2]."|".$argv[3]);' "$1" "$2" "$3"; }
jget(){ "$PHP" -d opcache.enable_cli=0 -r '$j=json_decode(file_get_contents("php://stdin"),true); $p=explode(".",$argv[1]); foreach($p as $k){ if($k==="")continue; $j=(is_array($j)&&array_key_exists($k,$j))?$j[$k]:null; } echo is_scalar($j)?$j:json_encode($j,JSON_UNESCAPED_UNICODE);' "$1"; }
q()   { "$PHP" -d opcache.enable_cli=0 -r '$db=new PDO("sqlite:'"$T"'/data/owlsgo.db"); echo (string)$db->query($argv[1])->fetchColumn();' "$1"; }

# 带「伪装来源 IP」的 API 调用：php -S 下所有连接都是 127.0.0.1，
# 必须靠路由脚本把 X-Test-IP 覆盖进 $_SERVER['REMOTE_ADDR'] 才能验证封禁拦截。
api() { local C="$1" K="$2" A="$3" IP="$4"; shift 4; local t; t=$(date +%s)
        local args=(--data-urlencode "ts=$t" --data-urlencode "sign=$(sign "$K" "$t" "$A")")
        local kv; for kv in "$@"; do args+=(--data-urlencode "$kv"); done
        cu -H "X-Forwarded-Proto: https" -H "X-Test-IP: $IP" -b "$C" -X POST "$B/?action=$A" "${args[@]}"; }
code() { local IP="$1" P="$2"; cu -o /dev/null -w '%{http_code}' -H "X-Forwarded-Proto: https" -H "X-Test-IP: $IP" "$B/$P"; }
body() { local IP="$1" P="$2"; cu -H "X-Forwarded-Proto: https" -H "X-Test-IP: $IP" "$B/$P"; }

ADMIN_IP="198.51.100.10"     # 管理员自己的出口 IP
USER_IP="203.0.113.9"        # 被测用户的登录 IP
BAD_IP="203.0.113.77"        # 撞库来源

echo "=== 0) 搭隔离副本 .t-um ==="
pkill -f "127.0.0.1:8847" 2>/dev/null
sleep 0.3
rm -rf "$T"; mkdir -p "$T/data/cache" "$T/data/logs" "$T/uploads/avatar" "$T/uploads/sticker" "$T/uploads/image" "$T/uploads/file"
for p in core assets index.php ws-server.php VERSION PLUGIN.md README.md LICENSE; do cp -r "$RT/$p" "$T/" 2>/dev/null; done
cp -r "$RT/plugins" "$T/plugins" 2>/dev/null
rm -rf "$T/plugins/user-manager"
cp -r "$REPO/plugins/user-manager" "$T/plugins/"
cp "$RT/data/install.lock" "$T/data/" 2>/dev/null
"$PHP" -d opcache.enable_cli=0 -r '$d=new PDO("sqlite:'"$RT"'/data/owlsgo.db"); $d->exec("VACUUM INTO '"'"'D:/project/owlsgo/.t-um/data/owlsgo.db'"'"'");'
[ -f "$T/data/owlsgo.db" ] || { echo "DB 快照失败"; exit 1; }
"$PHP" -d opcache.enable_cli=0 -r '$db=new PDO("sqlite:'"$T"'/data/owlsgo.db");
$db->prepare("INSERT OR REPLACE INTO plugins (name, enabled, config) VALUES (?,1,\"\")")->execute(["user-manager"]);
$db->prepare("UPDATE users SET password=? WHERE id IN (1,265)")->execute([password_hash("T3st!points", PASSWORD_DEFAULT)]);
$db->exec("DELETE FROM plugin_user_manager_ip");
$db->exec("DELETE FROM plugin_user_manager_geo");
$db->exec("DELETE FROM plugin_user_manager_ipban");
$db->exec("DELETE FROM settings WHERE k IN (\"owum_geo_on\",\"owum_geo_api\")");'
rm -f "$T/data/cache/plugins.json"

# 测试副本打开 trust_proxy：curl 走的是明文 http，靠 X-Forwarded-Proto 冒充 https，
# 否则 core 的 enforceHttps 会把每个 GET 都 301 到 https（生产配置不受影响）。
sed -i "s/'sign_window'/'trust_proxy' => true,\n    'sign_window'/" "$T/core/config.php"
grep -q "trust_proxy" "$T/core/config.php" || { echo "trust_proxy 写入失败"; exit 1; }

# 路由脚本：允许测试用 X-Test-IP 覆盖 REMOTE_ADDR（生产不会存在这个文件）。
# 真实存在的静态文件必须 return false 交回内置服务器 —— 否则 CSS/JS 都会被当成
# index.php 处理，浏览器拿到的是一段 HTML，chat.js 直接语法错误、整页交互全废。
cat > "$T/router.php" <<'PHPEOF'
<?php
if (!empty($_SERVER['HTTP_X_TEST_IP'])) $_SERVER['REMOTE_ADDR'] = $_SERVER['HTTP_X_TEST_IP'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && $path !== '/index.php' && is_file(__DIR__ . $path)) return false;
require __DIR__ . '/index.php';
PHPEOF

"$PHP" -d opcache.enable_cli=0 -S 127.0.0.1:8847 -t "$T" "$T/router.php" > "$T/server.log" 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null' EXIT
sleep 1.2

echo
echo "=== 1) 建表 + 插件加载 ==="
ck "插件表已建" "$(q "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name LIKE 'plugin_user_manager_%'")" "3"
rm -f "$T/data/cache/plugins.json"
cu -H "X-Forwarded-Proto: https" "$B/" -o /dev/null
MAN=$(cat "$T/data/cache/plugins.json")
has "清单记录了 request.start 钩子" '"request.start"' "$MAN"
has "清单记录了 login.after_verify" '"login.after_verify"' "$MAN"
has "清单记录了 ipban_add 路由" 'plugin_user_manager_ban_add' "$MAN"

echo
echo "=== 2) 登录（管理员 + 被测用户），IP 落库 ==="
cu -H "X-Forwarded-Proto: https" -H "X-Test-IP: $ADMIN_IP" -c "$T/a.txt" "$B/?page=login" -o /dev/null
AK=$(grep -oE 'owl_akey[[:space:]]+[a-f0-9]{32}' "$T/a.txt" | tail -1 | awk '{print $NF}')
TS=$(date +%s)
R=$(cu -H "X-Forwarded-Proto: https" -H "X-Test-IP: $ADMIN_IP" -c "$T/a.txt" -b "$T/a.txt" -X POST "$B/?action=login" \
      --data-urlencode "ts=$TS" --data-urlencode "sign=$(sign "$AK" "$TS" login)" \
      --data-urlencode "identity=no-hrym@outlook.com" --data-urlencode "password=T3st!points")
ck "管理员登录" "$(printf '%s' "$R" | jget ok)" "1"
AKEY=$(cu -H "X-Forwarded-Proto: https" -H "X-Test-IP: $ADMIN_IP" -b "$T/a.txt" "$B/" | grep -oE '"key":"[a-f0-9]{32}"' | head -1 | cut -d'"' -f4)
[ -n "$AKEY" ] || { echo "  [FAIL] 取不到管理员 API key"; exit 1; }

# 被测用户：先用一个固定 IP 登录
cu -H "X-Forwarded-Proto: https" -H "X-Test-IP: $USER_IP" -c "$T/u.txt" "$B/?page=login" -o /dev/null
UK0=$(grep -oE 'owl_akey[[:space:]]+[a-f0-9]{32}' "$T/u.txt" | tail -1 | awk '{print $NF}')
TS=$(date +%s)
R=$(cu -H "X-Forwarded-Proto: https" -H "X-Test-IP: $USER_IP" -c "$T/u.txt" -b "$T/u.txt" -X POST "$B/?action=login" \
      --data-urlencode "ts=$TS" --data-urlencode "sign=$(sign "$UK0" "$TS" login)" \
      --data-urlencode "identity=yes-hrym@outlook.com" --data-urlencode "password=T3st!points")
ck "被测用户登录" "$(printf '%s' "$R" | jget ok)" "1"
ck "聚合行记到了用户 IP" "$(q "SELECT ok_n FROM plugin_user_manager_ip WHERE uid=265 AND ip='$USER_IP'")" "1"
ck "管理员自己的 IP 也记了" "$(q "SELECT COUNT(*) FROM plugin_user_manager_ip WHERE uid=1 AND ip='$ADMIN_IP'")" "1"

# 撞库：错误密码（uid=0 聚合）
for i in 1 2 3; do
  cu -H "X-Forwarded-Proto: https" -H "X-Test-IP: $BAD_IP" -c "$T/b.txt" "$B/?page=login" -o /dev/null
  BK=$(grep -oE 'owl_akey[[:space:]]+[a-f0-9]{32}' "$T/b.txt" | tail -1 | awk '{print $NF}')
  TS=$(date +%s)
  cu -H "X-Forwarded-Proto: https" -H "X-Test-IP: $BAD_IP" -c "$T/b.txt" -b "$T/b.txt" -X POST "$B/?action=login" \
     --data-urlencode "ts=$TS" --data-urlencode "sign=$(sign "$BK" "$TS" login)" \
     --data-urlencode "identity=yes-hrym@outlook.com" --data-urlencode "password=wrong-pass" -o /dev/null
done
ck "撞库失败记在 uid=0" "$(q "SELECT fail_n FROM plugin_user_manager_ip WHERE uid=0 AND ip='$BAD_IP'")" "3"
ck "失败不影响真实用户的成功计数" "$(q "SELECT fail_n FROM plugin_user_manager_ip WHERE uid=265 AND ip='$USER_IP'")" "0"

echo
echo "=== 3) 安全画像接口 ==="
N=$(api "$T/a.txt" "$AKEY" plugin_user_manager_net "$ADMIN_IP" "id=265")
ck "net 返回成功" "$(printf '%s' "$N" | jget ok)" "1"
ck "返回用户昵称" "$(printf '%s' "$N" | jget data.user.id)" "265"
has "IP 列表含登录地址" "$USER_IP" "$(printf '%s' "$N" | jget data.ips)"
ck "汇总成功次数 ≥1" "$("$PHP" -r '$j=json_decode(file_get_contents("php://stdin"),true); echo ((int)$j["data"]["summary"]["ok_n"]>=1)?"1":"0";' <<< "$N")" "1"
ck "记录到了设备 UA" "$("$PHP" -r '$j=json_decode(file_get_contents("php://stdin"),true); echo ((int)$j["data"]["summary"]["ua_n"]>=1)?"1":"0";' <<< "$N")" "1"
ck "net 下发当前请求 IP" "$(printf '%s' "$N" | jget data.my_ip)" "$ADMIN_IP"
has "头像走核心分流（非空 avatar_url）" 'avatar_url' "$(printf '%s' "$N" | jget data.user)"
D=$(api "$T/a.txt" "$AKEY" plugin_user_manager_detail "$ADMIN_IP" "id=265")
AU=$(printf '%s' "$D" | jget data.avatar_url)
ck "detail 给出头像地址" "$([ -n "$AU" ] && echo 1 || echo 0)" "1"
has "生成式头像 = 图片地址（不是首字兜底）" "http" "$AU"

echo
echo "=== 4) 封禁 → 非管理员被拦，管理员放行 ==="
TK=$(api "$T/a.txt" "$AKEY" ticket "$ADMIN_IP" | jget ticket)
R=$(api "$T/a.txt" "$AKEY" plugin_user_manager_ban_add "$ADMIN_IP" "pattern=$USER_IP" "note=撞库测试" "days=0" "ticket=$TK")
ck "封禁成功" "$(printf '%s' "$R" | jget ok)" "1"
ck "规则落库" "$(q "SELECT COUNT(*) FROM plugin_user_manager_ipban WHERE pattern='$USER_IP'")" "1"
ck "被封 IP 打开首页 = 403" "$(code "$USER_IP" '')" "403"
has "拦截页有说明" "访问已被限制" "$(body "$USER_IP" '')"
ck "被封 IP 调接口 = 403" "$(code "$USER_IP" '?action=notices')" "403"
ck "被封 IP 的 CSS 也被拦" "$(code "$USER_IP" '?action=assets&type=js')" "403"
ck "管理员同 IP 仍可进后台（防盗锁）" "$(cu -o /dev/null -w '%{http_code}' -H "X-Forwarded-Proto: https" -H "X-Test-IP: $USER_IP" -b "$T/a.txt" "$B/?page=admin")" "200"
ck "其它 IP 不受影响" "$(code "$ADMIN_IP" '')" "200"
ck "net 里该 IP 标了 banned" "$(api "$T/a.txt" "$AKEY" plugin_user_manager_net "$ADMIN_IP" "id=265" | "$PHP" -r '$j=json_decode(file_get_contents("php://stdin"),true); foreach($j["data"]["ips"] as $r){ if($r["ip"]==="203.0.113.9"){ echo $r["banned"]?"1":"0"; return; } } echo "none";')" "1"

echo
echo "=== 5) 高风险 IP 聚合 ==="
L=$(api "$T/a.txt" "$AKEY" plugin_user_manager_ban_list "$ADMIN_IP")
ck "列表接口成功" "$(printf '%s' "$L" | jget ok)" "1"
ck "封禁条数 = 1" "$(printf '%s' "$L" | jget data.total)" "1"
ck "生效条数 = 1" "$(printf '%s' "$L" | jget data.active)" "1"
has "高风险 IP 首位是撞库地址" "\"ip\":\"$BAD_IP\"" "$(printf '%s' "$L" | jget data.top)"
ck "ban_list 下发当前请求 IP" "$(printf '%s' "$L" | jget data.my_ip)" "$ADMIN_IP"

echo
echo "=== 6) 解封 ==="
TK=$(api "$T/a.txt" "$AKEY" ticket "$ADMIN_IP" | jget ticket)
R=$(api "$T/a.txt" "$AKEY" plugin_user_manager_ban_del "$ADMIN_IP" "pattern=$USER_IP" "ticket=$TK")
ck "解封成功" "$(printf '%s' "$R" | jget ok)" "1"
ck "规则已删" "$(q "SELECT COUNT(*) FROM plugin_user_manager_ipban WHERE pattern='$USER_IP'")" "0"
ck "解封后该 IP 恢复访问" "$(code "$USER_IP" '')" "200"

echo
echo "=== 7) 参数校验 ==="
TK=$(api "$T/a.txt" "$AKEY" ticket "$ADMIN_IP" | jget ticket)
R=$(api "$T/a.txt" "$AKEY" plugin_user_manager_ban_add "$ADMIN_IP" "pattern=999.1.1.1" "days=0" "ticket=$TK")
ck "非法 IP 被拒" "$(printf '%s' "$R" | jget ok)" ""
ck "非法 IP 有文案" "$(printf '%s' "$R" | jget msg)" "IP 地址格式不正确"
TK=$(api "$T/a.txt" "$AKEY" ticket "$ADMIN_IP" | jget ticket)
R=$(api "$T/a.txt" "$AKEY" plugin_user_manager_ban_add "$ADMIN_IP" "pattern=not-an-ip" "days=0" "ticket=$TK")
ck "含非法字符也被拒" "$(printf '%s' "$R" | jget ok)" ""
TK=$(api "$T/a.txt" "$AKEY" ticket "$ADMIN_IP" | jget ticket)
R=$(api "$T/a.txt" "$AKEY" plugin_user_manager_ban_add "$ADMIN_IP" "pattern=$ADMIN_IP" "days=0" "ticket=$TK")
ck "不允许封自己的 IP" "$(printf '%s' "$R" | jget ok)" ""
has "提示包含当前地址" "$ADMIN_IP" "$(printf '%s' "$R" | jget msg)"
# 网段规则同样不能覆盖自己
TK=$(api "$T/a.txt" "$AKEY" ticket "$ADMIN_IP" | jget ticket)
R=$(api "$T/a.txt" "$AKEY" plugin_user_manager_ban_add "$ADMIN_IP" "pattern=198.51.100.*" "days=0" "ticket=$TK")
ck "覆盖自己的网段也被拒" "$(printf '%s' "$R" | jget ok)" ""
ck "被拒后没有落库" "$(q "SELECT COUNT(*) FROM plugin_user_manager_ipban WHERE pattern='198.51.100.*'")" "0"
# 网段写法
TK=$(api "$T/a.txt" "$AKEY" ticket "$ADMIN_IP" | jget ticket)
R=$(api "$T/a.txt" "$AKEY" plugin_user_manager_ban_add "$ADMIN_IP" "pattern=203.0.113.*" "note=网段" "days=1" "ticket=$TK")
ck "网段规则可封" "$(printf '%s' "$R" | jget ok)" "1"
ck "网段命中被拦" "$(code "$USER_IP" '')" "403"
ck "限时规则写了到期时间" "$("$PHP" -r 'echo ((int)$argv[1] > time()) ? "1" : "0";' "$(q "SELECT expires_at FROM plugin_user_manager_ipban WHERE pattern='203.0.113.*'")")" "1"
TK=$(api "$T/a.txt" "$AKEY" ticket "$ADMIN_IP" | jget ticket)
api "$T/a.txt" "$AKEY" plugin_user_manager_ban_del "$ADMIN_IP" "pattern=203.0.113.*" "ticket=$TK" -o /dev/null >/dev/null
ck "清理网段规则" "$(q "SELECT COUNT(*) FROM plugin_user_manager_ipban")" "0"

echo
echo "=== 8) 归属地开关 / 接口校验 ==="
R=$(api "$T/a.txt" "$AKEY" plugin_user_manager_geo_save "$ADMIN_IP" "on=1" "api=http://127.0.0.1/x")
ck "内网接口地址被拒" "$(printf '%s' "$R" | jget ok)" ""
R=$(api "$T/a.txt" "$AKEY" plugin_user_manager_geo_save "$ADMIN_IP" "on=1" "api=https://example.com/lookup")
ck "缺 {ip} 的地址被拒" "$(printf '%s' "$R" | jget ok)" ""
R=$(api "$T/a.txt" "$AKEY" plugin_user_manager_geo_save "$ADMIN_IP" "on=0" "api=https://api.ip.sb/geoip/{ip}")
ck "合法地址可保存" "$(printf '%s' "$R" | jget ok)" "1"
ck "开关已落库" "$(q "SELECT v FROM settings WHERE k='owum_geo_on'")" "0"
R=$(api "$T/a.txt" "$AKEY" plugin_user_manager_geo "$ADMIN_IP" "id=265")
ck "关闭后解析接口给出明确原因" "$(printf '%s' "$R" | jget msg)" "归属地解析已关闭"
api "$T/a.txt" "$AKEY" plugin_user_manager_geo_save "$ADMIN_IP" "on=1" "api=https://api.ip.sb/geoip/{ip}" >/dev/null
R=$(api "$T/a.txt" "$AKEY" plugin_user_manager_geo "$ADMIN_IP" "id=265")
ck "开启后解析接口可用（无外网也不报错）" "$(printf '%s' "$R" | jget ok)" "1"
ck "内网地址不查归属地" "$(api "$T/a.txt" "$AKEY" plugin_user_manager_net "$ADMIN_IP" "id=1" | "$PHP" -r '$j=json_decode(file_get_contents("php://stdin"),true); $f=0; foreach($j["data"]["ips"] as $r){ if($r["geo"]==="内网地址") $f=1; } echo $f;')" "1"

echo
echo "=== 9) 原有功能不回归 ==="
R=$(api "$T/a.txt" "$AKEY" plugin_user_manager_search "$ADMIN_IP" "q=265")
ck "搜索用户仍可用" "$(printf '%s' "$R" | jget ok)" "1"
ck "搜索返回 1 条" "$("$PHP" -r '$j=json_decode(file_get_contents("php://stdin"),true); echo count($j["data"]??[]);' <<< "$R")" "1"
R=$(api "$T/a.txt" "$AKEY" plugin_user_manager_detail "$ADMIN_IP" "id=265")
ck "概览仍可用" "$(printf '%s' "$R" | jget ok)" "1"
CK=$(cu -o /dev/null -w '%{http_code}' -H "X-Forwarded-Proto: https" -H "X-Test-IP: $ADMIN_IP" -b "$T/a.txt" "$B/?page=admin")
ck "后台页 200" "$CK" "200"
has "后台页引了插件样式" "plugin=user-manager&file=style.css" "$(cu -H "X-Forwarded-Proto: https" -H "X-Test-IP: $ADMIN_IP" -b "$T/a.txt" "$B/?page=admin")"
has "合并 JS 里有 OwUM" "OwUM" "$(cu -H "X-Forwarded-Proto: https" "$B/?action=assets&type=js")"
ck "CSS 资源可取" "$(cu -o /dev/null -w '%{http_code}' -H "X-Forwarded-Proto: https" "$B/?action=assets&type=css&plugin=user-manager&file=style.css")" "200"

echo
echo "=== 10) PHP 日志无致命错误 ==="
ERRS=$(grep -cE "Fatal error|Uncaught|Parse error" "$T/server.log" 2>/dev/null || true)
ck "server.log 无致命错误" "${ERRS:-0}" "0"
[ "${ERRS:-0}" != "0" ] && tail -20 "$T/server.log"

echo
echo "==== 结果：PASS=$PASS FAIL=$FAIL ===="
[ "$FAIL" = "0" ] || exit 1
