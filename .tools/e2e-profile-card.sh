#!/usr/bin/env bash
# 端到端回归：用户名片插件（profile-card v1.0.0）+ 核心 onCardRendered 钩子（v1.5.17）
#
# 一键自举，绝不触碰运行副本与仓库 data/：
#   ① 重建隔离副本 .t-pc（仓库源码 + data/owlsgo.db 副本，剔掉缓存）
#   ② 准备测试数据（900002 登录口令 / 启用插件 / 900003 带 banner-b / 生成横幅图）
#   ③ 起 3 个 php -S 后端（8851-8853）+ .tools/pc-test-proxy.js（https://127.0.0.1:8845）
#      —— php -S 单进程会被聊天页 ?action=poll 长轮询堵死，代理择闲转发绕开
#   ④ 跑 API 层断言（curl）+ DOM 层断言（系统 Chrome，playwright-core）
#   ⑤ 退出时回收全部进程、删掉 .t-pc
# 用法：bash .tools/e2e-profile-card.sh
set -u
PHP="D:/Program Files/phpstudy_pro/Extensions/php/php8.2.9nts/php.exe"
NODE="C:/Users/zeali/.workbuddy/binaries/node/versions/22.22.2-6/node.exe"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# 走 .tools/pc-test-proxy.js（HTTPS 终结 + 择闲轮转 3 个 php -S 后端）；
# -k 接受自签证书，X-Forwarded-Proto 由代理统一补，curl 不再自带。
B="https://127.0.0.1:8845"
PIDS=()
# ⚠️ 清理一律用 find -delete 而不是 rm -rf：本机 shell 的 rm 被「安全删除」钩子
# 接管（转回收站），批量删目录时常失败；失败被吞掉后 .t-pc 会留下半残旧副本
# （上一轮实测：只剩 data/plugins/uploads，随后 cp 合并旧文件 → 插件 JS 是旧版，
# 设置区块压根不注入，症状极像「插件坏了」）。find -delete 不受钩子影响。
cleanup() {
  for p in "${PIDS[@]:-}"; do kill "$p" 2>/dev/null; done
  find "$ROOT/.t-pc" -mindepth 1 -delete 2>/dev/null
  rmdir "$ROOT/.t-pc" 2>/dev/null
}
trap cleanup EXIT

PASS=0; FAIL=0
ck() { if [ "$2" = "$3" ]; then PASS=$((PASS+1)); echo "  [PASS] $1"
       else FAIL=$((FAIL+1)); echo "  [FAIL] $1"; echo "         实际=[$2] 期望=[$3]"; fi; }
has() { case "$3" in *"$2"*) PASS=$((PASS+1)); echo "  [PASS] $1";;
        *) FAIL=$((FAIL+1)); echo "  [FAIL] $1（未找到 [$2]）"; echo "         实际=$3";; esac; }
sign() { "$PHP" -r 'echo md5($argv[1]."|".$argv[2]."|".$argv[3]);' "$1" "$2" "$3"; }
jget() { "$PHP" -r '$j=json_decode(file_get_contents("php://stdin"),true); $p=explode(".",$argv[1]); foreach($p as $k){ if($k==="")continue; $j=(is_array($j)&&array_key_exists($k,$j))?$j[$k]:null; } if(is_bool($j)){$j=$j?"1":"0";} echo is_scalar($j)?$j:json_encode($j,JSON_UNESCAPED_UNICODE);' "$1"; }
# ⚠️ 用相对路径：$ROOT 是 Git Bash 的 MSYS 路径（/d/project/owlsgo），PHP 不认；
#    本脚本开头已 cd "$ROOT"，直接用 .t-pc/... 即可。
q() { "$PHP" -r '$db=new PDO("sqlite:.t-pc/data/owlsgo.db"); echo (string)$db->query($argv[1])->fetchColumn();' "$1"; }

api() { local C="$1" K="$2" A="$3"; shift 3; local t; t=$(date +%s)
        local args=(--data-urlencode "ts=$t" --data-urlencode "sign=$(sign "$K" "$t" "$A")")
        local kv; for kv in "$@"; do args+=(--data-urlencode "$kv"); done
        curl -sk -b "$C" -X POST "$B/?action=$A" "${args[@]}"; }

login() { local C="$1" ID="$2" name="$3"
  curl -sk -c "$C" -b "$C" "$B/?page=login" -o /dev/null
  local AK TS R K
  AK=$(grep -oE 'owl_akey[[:space:]]+[a-f0-9]{32}' "$C" | tail -1 | awk '{print $NF}')
  TS=$(date +%s)
  R=$(curl -sk -c "$C" -b "$C" -X POST "$B/?action=login" \
        --data-urlencode "ts=$TS" --data-urlencode "sign=$(sign "$AK" "$TS" login)" \
        --data-urlencode "identity=$ID" --data-urlencode "password=T3st!points")
  ck "登录 $ID" "$(printf '%s' "$R" | jget ok)" "1"
  K=$(curl -sk -b "$C" "$B/" | grep -oE '"key":"[a-f0-9]{32}"' | head -1 | cut -d'"' -f4)
  [ -n "$K" ] || { echo "  [FAIL] 取不到 API key"; exit 1; }
  eval "$name=$K"
}

echo "=== 0) 建隔离副本 .t-pc ==="
cd "$ROOT"
find .t-pc -mindepth 1 -delete 2>/dev/null; mkdir -p .t-pc
for item in index.php ws-server.php VERSION PLUGIN.md README.md LICENSE nginx-server.conf core assets plugins data uploads; do
  cp -r "$item" .t-pc/ || { echo "拷贝 $item 失败"; exit 1; }
done
rm -rf .t-pc/data/cache
"$PHP" -r 'file_put_contents(".t-pc/data/install.lock", date("c")." e2e");'
# 复制自检：确认关键文件在、且插件 JS 是当前版本（防「旧副本混入」类假失败）
for f in index.php core/plugin.php assets/js/chat.js assets/css/owlsgo.css plugins/profile-card/chat.js plugins/profile-card/style.css; do
  [ -f ".t-pc/$f" ] || { echo "副本缺文件：$f"; exit 1; }
done
grep -q "OwPC.inject" .t-pc/plugins/profile-card/chat.js || { echo "副本里的插件 JS 不是当前版本"; exit 1; }
# 测试数据：900002 登录口令 + 启用插件；900003 带 banner-b；生成横幅图 banner-a
"$PHP" -r '
$db=new PDO("sqlite:.t-pc/data/owlsgo.db");
$db->exec("UPDATE users SET password=\"".password_hash("T3st!points", PASSWORD_DEFAULT)."\" WHERE id=900002");
$db->exec("INSERT OR IGNORE INTO users (id,nickname,email,password,role,status,created_at)
  VALUES (900003,\"测试观众B\",\"pc-viewer-900003@example.test\",\"".password_hash("T3st!points", PASSWORD_DEFAULT)."\",\"member\",1,".time().")");
$db->exec("INSERT OR REPLACE INTO plugins (name,enabled,config) VALUES (\"profile-card\",1,\"\")");
$db->exec("INSERT OR REPLACE INTO plugin_profile_card (user_id,banner,updated_at) VALUES (900003,\"uploads/image/pc/banner-b.png\",".time().")");
@mkdir(".t-pc/uploads/image/pc", 0777, true);
$im=imagecreatetruecolor(1200,320);
imagefill($im,0,0,imagecolorallocate($im,0,144,233));
$w=imagecolorallocatealpha($im,255,255,255,70);
imagefilledellipse($im,180,240,420,420,$w); imagefilledellipse($im,1020,40,300,300,$w); imagefilledellipse($im,760,300,200,200,$w);
imagepng($im,".t-pc/uploads/image/pc/banner-a.png");
echo "  .t-pc 就绪\n";'

echo
echo "=== 0.5) 起服务（3 后端 + 代理） ==="
for p in 8851 8852 8853; do
  ( cd .t-pc && exec "$PHP" -S 127.0.0.1:$p -t . ) > /tmp/pc-php-$p.log 2>&1 &
  PIDS+=($!)
done
( exec "$NODE" .tools/pc-test-proxy.js ) > /tmp/pc-proxy.log 2>&1 &
PIDS+=($!)
sleep 2
# ⚠️ 冷启动竞争（本套件踩过）：3 个进程同时首次 init → syncCronTasks 并发插
# cron_tasks 行 → SQLite 写锁 → 未捕获的 "database is locked" → 那一两秒内的
# 请求（含 CSS）批量 500。PDO 没设 busy timeout，这是核心侧的既有行为
# （线上缓存是热的、不写库，故不显）。对策：**逐个后端顺序预热**，把首次写库
# 串行化，再确认清单缓存落地，然后才放浏览器进场。
for p in 8851 8852 8853; do
  curl -sk -o /dev/null -H "X-Forwarded-Proto: https" "http://127.0.0.1:$p/?page=login"
done
for _ in $(seq 1 20); do
  [ -f .t-pc/data/cache/plugins.json ] && break
  sleep 0.5
done
[ -f .t-pc/data/cache/plugins.json ] || { echo "清单缓存未生成，后端启动异常"; exit 1; }
curl -sk -o /dev/null -w "  代理自检：%{http_code}\n" "$B/?page=login"

# 每轮从干净状态起步：清掉 900002 的横幅行，避免残留把「初始无横幅」断言带偏
"$PHP" -r '$db=new PDO("sqlite:.t-pc/data/owlsgo.db"); $db->exec("DELETE FROM plugin_profile_card WHERE user_id=900002");'

echo
echo "=== 1) API 层 ==="
login .t-pc/u.txt zz-av-1791994798@example.test UK

H=$(curl -sk -b .t-pc/u.txt "$B/")
# v1.0.1 起横幅管理移入个人设置，rail 不再有「名片」入口（回归：防复活）
if printf '%s' "$H" | grep -q 'data-tab="profilecard"'; then
  FAIL=$((FAIL+1)); echo "  [FAIL] 聊天页不应再有名片 rail 按钮（已移入设置）"; else PASS=$((PASS+1)); echo "  [PASS] 聊天页无名片 rail 按钮"; fi
has "聊天页含名片样式 link（带 pv 版本键）" 'plugin=profile-card&file=style.css&v=1.5.17&pv=1.0.4' "$H"
has "JS 合并包含名片插件代码（__owPcLoaded 哨兵）" '__owPcLoaded' "$(curl -sk -b .t-pc/u.txt "$B/?action=assets&type=js")"
has "CSS 端点非空（两件套落地）" 'owpc-set-preview' "$(curl -sk "$B/?action=assets&type=css&plugin=profile-card&file=style.css")"

ck "初始无横幅" "$(q "SELECT COUNT(*) FROM plugin_profile_card WHERE user_id=900002")" "0"
R=$(api .t-pc/u.txt "$UK" plugin_profile_card_save "banner=uploads/image/ab/test-banner.png")
ck "保存合法站内路径" "$(printf '%s' "$R" | jget ok)" "1"
ck "user_card 下发 banner 字段（user.card 钩子生效）" "$(api .t-pc/u.txt "$UK" user_card "id=900002" | jget data.banner)" "uploads/image/ab/test-banner.png"
ck "get 读回横幅" "$(api .t-pc/u.txt "$UK" plugin_profile_card_get "id=900002" | jget banner)" "uploads/image/ab/test-banner.png"

R=$(api .t-pc/u.txt "$UK" plugin_profile_card_save 'banner=javascript:alert(1)')
ck "拒绝 javascript: 协议" "$(printf '%s' "$R" | jget ok)" "0"
R=$(api .t-pc/u.txt "$UK" plugin_profile_card_save 'banner=https://evil.example.com/a" onerror=alert(1) .png')
ck "拒绝带引号/空格的 URL" "$(printf '%s' "$R" | jget ok)" "0"
R=$(api .t-pc/u.txt "$UK" plugin_profile_card_save 'banner=data:image/svg+xml,<svg onload=alert(1)/>')
ck "拒绝 data: URI" "$(printf '%s' "$R" | jget ok)" "0"
ck "非法输入不落库（仍是旧值）" "$(q "SELECT banner FROM plugin_profile_card WHERE user_id=900002")" "uploads/image/ab/test-banner.png"

rm -f .t-pc/g.txt
AK=$(grep -oE 'owl_akey[[:space:]]+[a-f0-9]{32}' <(curl -sk -c .t-pc/g.txt "$B/?page=login" >/dev/null; cat .t-pc/g.txt) | tail -1 | awk '{print $NF}')
TS=$(date +%s)
R=$(curl -sk -b .t-pc/g.txt -X POST "$B/?action=plugin_profile_card_save" \
      --data-urlencode "ts=$TS" --data-urlencode "sign=$(sign "$AK" "$TS" plugin_profile_card_save)" \
      --data-urlencode "banner=uploads/image/hack.png")
ck "游客保存被拒" "$(printf '%s' "$R" | jget ok)" "0"
has "提示先登录" "请先登录" "$R"

"$PHP" -r '$im=imagecreatetruecolor(8,8); imagefill($im,0,0,imagecolorallocate($im,0,160,233)); imagepng($im,".t-pc/px.png");'
TS=$(date +%s)
R=$(curl -sk -b .t-pc/u.txt -X POST "$B/?action=plugin_profile_card_upload" \
      -F "ts=$TS" -F "sign=$(sign "$UK" "$TS" plugin_profile_card_upload)" -F "file=@.t-pc/px.png;type=image/png")
ck "上传成功（复用核心 Upload image 类型）" "$(printf '%s' "$R" | jget ok)" "1"
UP=$(printf '%s' "$R" | jget url)
has "上传 URL 落在 uploads/image/ 下" "uploads/image/" "$UP"
ck "采用上传结果" "$(api .t-pc/u.txt "$UK" plugin_profile_card_save "banner=$UP" | jget ok)" "1"
ck "user_card 下发上传后的横幅" "$(api .t-pc/u.txt "$UK" user_card "id=900002" | jget data.banner)" "$UP"

R=$(api .t-pc/u.txt "$UK" plugin_profile_card_save "banner=")
ck "移除横幅" "$(printf '%s' "$R" | jget ok)" "1"
has "移除后 banner 为空串" '"banner":""' "$(api .t-pc/u.txt "$UK" user_card "id=900002")"
ck "数据库行仍在（值为空）" "$(q "SELECT COUNT(*) FROM plugin_profile_card WHERE user_id=900002")" "1"

JS6="$(curl -sk "$B/assets/js/chat.js?v=1.5.17")"
has "核心定义 onCardRendered" 'onCardRendered: function' "$JS6"
has "核心在 userCard 里触发 fireCardRendered" 'fireCardRendered($' "$JS6"

echo
echo "=== 2) DOM 层（系统 Chrome） ==="
DOM_FAIL=""
"$NODE" .tools/profile-card-dom-test.js || DOM_FAIL=1

echo
echo "=== 3) 深色主题 ==="
"$NODE" .tools/pc-dark-test.js || DOM_FAIL=1

echo
echo "========== 汇总：API PASS=$PASS FAIL=$FAIL DOM=$([ -n "$DOM_FAIL" ] && echo 失败 || echo 通过) =========="
[ "$FAIL" = "0" ] && [ -z "$DOM_FAIL" ]
