#!/usr/bin/env bash
# 端到端验证：v1.5.24 插件「标题进清单 + 定向加载」后台侧
# 跑在隔离副本 .t-al 上（php -S 8848 + 运行副本 DB 快照，35 插件真实状态），
# 绝不触碰运行副本的 data/。
# 覆盖：后台壳渲染（子菜单标题来自清单）→ 插件管理列表 → 打开插件设置页
#       → 停用插件占位页 → 未知 slug → 稳态零缓存重写 → 改插件文件标题后菜单跟随。
# 用法：bash .tools/e2e-admin-lazy.sh
set -u
PHP="D:/Program Files/phpstudy_pro/Extensions/php/php8.2.9nts/php.exe"
RT="D:/Program Files/phpstudy_pro/WWW/owlsgo"
REPO="D:/project/owlsgo"
T="$REPO/.t-al"
B="http://127.0.0.1:8848"
PASS=0; FAIL=0
ck()  { if [ "$2" = "$3" ]; then PASS=$((PASS+1)); echo "  [PASS] $1";
        else FAIL=$((FAIL+1)); echo "  [FAIL] $1"; echo "         实际=[$2] 期望=[$3]"; fi; }
has() { case "$3" in *"$2"*) PASS=$((PASS+1)); echo "  [PASS] $1";;
        *) FAIL=$((FAIL+1)); echo "  [FAIL] $1（未找到 [$2]）"; echo "         实际=${3:0:200}";; esac; }
no()  { case "$3" in *"$2"*) FAIL=$((FAIL+1)); echo "  [FAIL] $1（不该出现 [$2]）";;
                 *) PASS=$((PASS+1)); echo "  [PASS] $1";; esac; }
cu()  { curl -s --noproxy '*' "$@"; }
sign(){ "$PHP" -d opcache.enable_cli=0 -r 'echo md5($argv[1]."|".$argv[2]."|".$argv[3]);' "$1" "$2" "$3"; }
jget(){ "$PHP" -d opcache.enable_cli=0 -r '$j=json_decode(file_get_contents("php://stdin"),true); $p=explode(".",$argv[1]); foreach($p as $k){ if($k==="")continue; $j=(is_array($j)&&array_key_exists($k,$j))?$j[$k]:null; } echo is_scalar($j)?$j:json_encode($j,JSON_UNESCAPED_UNICODE);' "$1"; }
q()   { "$PHP" -d opcache.enable_cli=0 -r '$db=new PDO("sqlite:'"$T"'/data/owlsgo.db"); echo (string)$db->query($argv[1])->fetchColumn();' "$1"; }
# 管理员 API 调用（$1=cookie $2=key $3=action，其余 kv）
api() { local C="$1" K="$2" A="$3"; shift 3; local t; t=$(date +%s)
        local args=(--data-urlencode "ts=$t" --data-urlencode "sign=$(sign "$K" "$t" "$A")")
        local kv; for kv in "$@"; do args+=(--data-urlencode "$kv"); done
        cu -H "X-Forwarded-Proto: https" -b "$C" -X POST "$B/?action=$A" "${args[@]}"; }

echo "=== 0) 搭隔离副本 .t-al（快照运行副本库） ==="
pkill -f "127.0.0.1:8848" 2>/dev/null
sleep 0.3
rm -rf "$T"; mkdir -p "$T/data/cache" "$T/data/logs" "$T/uploads/avatar" "$T/uploads/sticker" "$T/uploads/image" "$T/uploads/file"
for p in core assets index.php ws-server.php VERSION ASSET_VERSION; do cp -r "$RT/$p" "$T/" 2>/dev/null; done
cp -r "$RT/plugins" "$T/plugins" 2>/dev/null
cp "$RT/data/install.lock" "$T/data/" 2>/dev/null
"$PHP" -d opcache.enable_cli=0 -r '$d=new PDO("sqlite:'"$RT"'/data/owlsgo.db"); $d->exec("VACUUM INTO '"'"'D:/project/owlsgo/.t-al/data/owlsgo.db'"'"'");'
[ -f "$T/data/owlsgo.db" ] || { echo "DB 快照失败"; exit 1; }
# 已知测试密码 + 一个「已安装但停用」的插件（验证灰色子项与占位页）
"$PHP" -d opcache.enable_cli=0 -r '$db=new PDO("sqlite:'"$T"'/data/owlsgo.db");
$db->prepare("UPDATE users SET password=? WHERE id=1")->execute([password_hash("T3st!points", PASSWORD_DEFAULT)]);
$db->prepare("INSERT OR REPLACE INTO plugins (name, enabled, config) VALUES (?,0,\"\")")->execute(["level-cultivation"]);'
rm -f "$T/data/cache/plugins.json"
# 测试副本打开 trust_proxy：curl 走明文 http，靠 X-Forwarded-Proto 过 enforceHttps
sed -i "s/'sign_window'/'trust_proxy' => true,\n    'sign_window'/" "$T/core/config.php"
grep -q "trust_proxy" "$T/core/config.php" || { echo "trust_proxy 写入失败"; exit 1; }
cat > "$T/router.php" <<'PHPEOF'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && $path !== '/index.php' && is_file(__DIR__ . $path)) return false;
require __DIR__ . '/index.php';
PHPEOF
"$PHP" -d opcache.enable_cli=0 -S 127.0.0.1:8848 -t "$T" "$T/router.php" > "$T/server.log" 2>&1 &
SRV=$!
trap 'kill $SRV 2>/dev/null; rm -rf "$T"' EXIT
sleep 1.2

EMAIL=$(q "SELECT email FROM users WHERE id=1")
echo "  管理员账号：$EMAIL"

echo; echo "=== 1) 登录 + 后台壳渲染（子菜单标题应来自清单，零页面型插件加载） ==="
cu -H "X-Forwarded-Proto: https" -c "$T/c.txt" -b "$T/c.txt" "$B/?page=login" -o /dev/null
# ⚠️ 按字段取，别用 grep -oE 'owl_akey[[:space:]+[a-f0-9]{32}'：那个字符类会吞掉末位
# （实测只取出 31 位 → 签名必然对不上，一度误判成登录逻辑坏了）。
AK=$(awk '$6=="owl_akey"{print $7}' "$T/c.txt" | tail -1)
[ ${#AK} -eq 32 ] || { echo "  [FAIL] owl_akey 长度=${#AK}"; exit 1; }
TS=$(date +%s)
R=$(cu -H "X-Forwarded-Proto: https" -c "$T/c.txt" -b "$T/c.txt" -X POST "$B/?action=login" \
      --data-urlencode "ts=$TS" --data-urlencode "sign=$(sign "$AK" "$TS" login)" \
      --data-urlencode "identity=$EMAIL" --data-urlencode "password=T3st!points")
ck "管理员登录" "$(printf '%s' "$R" | jget ok)" "1"
H=$(cu -H "X-Forwarded-Proto: https" -b "$T/c.txt" "$B/?page=admin")
has "后台壳含插件管理菜单" 'data-apage="plugins"' "$H"
has "子菜单：用户管理（清单标题）" '用户管理' "$H"
has "子菜单：附件上传（清单标题）" '附件上传' "$H"
has "子菜单：通行密钥（清单标题）" '通行密钥' "$H"
has "停用插件灰色子项" '（未启用）' "$H"
case "$H" in *"Fatal error"*|*"Parse error"*) FAIL=$((FAIL+1)); echo "  [FAIL] 后台壳有 PHP 报错";;
                   *) PASS=$((PASS+1)); echo "  [PASS] 后台壳无 PHP 报错";; esac
K=$(printf '%s' "$H" | grep -oE '"key":"[a-f0-9]{32}"' | head -1 | cut -d'"' -f4)
[ -n "$K" ] && echo "  已取到管理员 API key" || { echo "  [FAIL] 取不到 API key"; exit 1; }

echo; echo "=== 2) 插件管理列表（admin_pages 走清单） ==="
R=$(api "$T/c.txt" "$K" admin_plugins)
has "admin_plugins 成功" '"ok":true' "$R"
has "admin_pages 含 user-manager" '"user-manager"' "$R"
has "admin_pages 含 attachment-manager" '"attachment-manager"' "$R"
# 只截 admin_pages 数组判断：整个响应里的 data（listAll）本就包含停用插件
no  "admin_pages 不含停用的 level-cultivation" 'level-cultivation' "$(printf '%s' "$R" | jget admin_pages)"
N=$(printf '%s' "$R" | jget data | "$PHP" -d opcache.enable_cli=0 -r 'echo count(json_decode(file_get_contents("php://stdin"),true)??[]);')
[ "${N:-0}" -gt 30 ] && PASS=$((PASS+1)) && echo "  [PASS] 列表收录 $N 个插件" \
                     || { FAIL=$((FAIL+1)); echo "  [FAIL] 列表只有 $N 个插件"; }

echo; echo "=== 3) 打开插件设置页（只加载拥有者） ==="
R=$(api "$T/c.txt" "$K" admin_plugin_page "slug=user-manager")
has "user-manager 设置页成功" '"ok":true' "$R"
has "设置页含用户管理内容" '用户' "$R"
R=$(api "$T/c.txt" "$K" admin_plugin_page "slug=attachment-manager")
has "attachment-manager 设置页成功" '"ok":true' "$R"
R=$(api "$T/c.txt" "$K" admin_plugin_page "slug=level-cultivation")
has "停用插件给占位页" '停用' "$R"
has "占位页带一键启用" '启用插件' "$R"
R=$(api "$T/c.txt" "$K" admin_plugin_page "slug=nope-404")
has "未知 slug 被拒" '"ok":false' "$R"

echo; echo "=== 4) 稳态：后台壳 + 列表 + 设置页零缓存重写 ==="
# 第一遍可能因「停用插件的计数探测」写一次缓存，取它之后的稳态做断言
cu -H "X-Forwarded-Proto: https" -b "$T/c.txt" "$B/?page=admin" -o /dev/null
api "$T/c.txt" "$K" admin_plugins > /dev/null
CF="$T/data/cache/plugins.json"
M0=$(md5sum "$CF" | cut -d' ' -f1)
ND=$("$PHP" -d opcache.enable_cli=0 -r '$n=0; foreach (glob("'"$T"'/plugins/*", GLOB_ONLYDIR) ?: [] as $d) { if (preg_match("/^[a-zA-Z0-9_-]+$/", basename($d))) $n++; } echo $n;')
V0=$("$PHP" -d opcache.enable_cli=0 -r '$c=json_decode(file_get_contents("'"$CF"'"),true); echo ($c["v"]??"?")."/".count($c["plugins"]);')
H=$(cu -H "X-Forwarded-Proto: https" -b "$T/c.txt" "$B/?page=admin")
api "$T/c.txt" "$K" admin_plugins > /dev/null
api "$T/c.txt" "$K" admin_plugin_page "slug=user-manager" > /dev/null
api "$T/c.txt" "$K" admin_plugin_page "slug=attachment-manager" > /dev/null
M1=$(md5sum "$CF" | cut -d' ' -f1)
ck "稳态请求缓存零重写" "$M1" "$M0"
ck "缓存为 v2 且收录全部插件目录" "$V0" "2/$ND"
has "稳态后台壳仍正常" '用户管理' "$H"

echo; echo "=== 5) 改插件文件标题 → 菜单跟随（证明菜单读清单、变更可重载） ==="
sed -i "s/'用户管理'/'用户管理ZZ'/" "$T/plugins/user-manager/main.php"
grep -q "用户管理ZZ" "$T/plugins/user-manager/main.php" || { echo "  [FAIL] sed 改写失败"; exit 1; }
touch -d "@$(( $(date +%s) - 5000 ))" "$T/plugins/user-manager/main.php"   # 秒级精度：拨到不同秒
# ⚠️ php -S 是单进程长生命服务：require_once 对已 include 的文件直接返回 true、
# 不重执行 —— 改文件后的「重载」在单进程里根本不可测（不是核心问题）。
# 重启服务端 = 模拟生产里「下一个请求是新 php-cgi 进程」的现实，重启后重新拉页面即可。
kill $SRV 2>/dev/null; sleep 0.5
"$PHP" -d opcache.enable_cli=0 -S 127.0.0.1:8848 -t "$T" "$T/router.php" >> "$T/server.log" 2>&1 &
SRV=$!
sleep 1.2
H=$(cu -H "X-Forwarded-Proto: https" -b "$T/c.txt" "$B/?page=admin")
has "菜单显示新标题（清单驱动）" '用户管理ZZ' "$H"
M2=$(md5sum "$CF" | cut -d' ' -f1)
[ "$M2" != "$M1" ] && PASS=$((PASS+1)) && echo "  [PASS] 变更被检测到，缓存已重写" \
                    || { FAIL=$((FAIL+1)); echo "  [FAIL] 变更未被检测到"; }
K=$(printf '%s' "$H" | grep -oE '"key":"[a-f0-9]{32}"' | head -1 | cut -d'"' -f4)
R=$(api "$T/c.txt" "$K" admin_plugin_page "slug=user-manager")
has "改标题后设置页仍可打开" '"ok":true' "$R"
no  "是真设置页而非停用占位" '启用插件' "$R"

echo; echo "=== 6) 前台页路由不回归 ==="
C=$(cu -o /dev/null -w '%{http_code}' -H "X-Forwarded-Proto: https" "$B/?page=level")
ck "?page=level 200（插件前台页）" "$C" "200"
C=$(cu -o /dev/null -w '%{http_code}' -H "X-Forwarded-Proto: https" "$B/")
ck "聊天页 200" "$C" "200"

echo; echo "=== 7) 服务端无致命错误 ==="
if grep -qiE "fatal error|parse error|uncaught" "$T/server.log" 2>/dev/null; then
  FAIL=$((FAIL+1)); echo "  [FAIL] server.log 有致命错误："; grep -iE "fatal error|parse error|uncaught" "$T/server.log" | head -3
else
  PASS=$((PASS+1)); echo "  [PASS] 无 fatal / parse error"
fi

echo; echo "========================================"
echo "通过 $PASS 项，失败 $FAIL 项"
[ "$FAIL" -eq 0 ] || exit 1
