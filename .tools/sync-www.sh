#!/usr/bin/env bash
# Owlsgo-Chat → phpstudy 运行副本同步 + md5 复核（与 v3 论坛同样的工作方式）
# 用法：bash "D:/project/owlsgo/.tools/sync-www.sh"
#
# 说明：
#   - 排除 data/（SQLite 库与安装锁）与 uploads/（用户上传），避免 /MIR 删掉运行数据
#   - 只同步 git 跟踪的文件，副本里的运行数据不受影响
set -u
export PATH="/c/Users/zeali/.workbuddy/binaries/PortableGit/versions/1.2.0/usr/bin:/c/Windows/System32:/usr/bin:/bin:$PATH"
export MSYS_NO_PATHCONV=1

SRC="D:\\project\\owlsgo"
DST="D:\\Program Files\\phpstudy_pro\\WWW\\owlsgo"
DST_HOST="/d/Program Files/phpstudy_pro/WWW/owlsgo"

# /XF 保护两类运行期文件，避免 /MIR 覆盖或删除它们：
#   .htaccess / nginx.htaccess —— phpStudy 面板生成
#   core/config.php —— 安装向导会往副本里写入随机 secret 与 db 配置，
#                      仓库里是占位值 CHANGE_ME_RANDOM_64_CHARS，同步过去会让全站签名失效
#
# 后面四条排除的是**本地专用料**（未跟踪、不该进运行目录）：
#   参考文献      姊妹实现源码与截图，整目录静态可访问等于把参考料挂到站点根下
#   _test_www     测试插件目录（没有 plugin.json 不会被登记，但没必要挂在运行副本里）
#   .box-agent / .box-agent-scratch   代理任务状态与空壳暂存；ci-preview.html 视觉预览草稿
# ⚠️ robocopy 对被 /XD /XF 命中的项**既不复制也不清除** —— 所以早先那次 /MIR 灌进副本的
#    这四样不会靠本脚本自动消失，要手工删一次（本机已删；再次出现时按路径删即可）。
robocopy "$SRC" "$DST" /MIR /XD data uploads .git .workbuddy .birdview .tools .box-agent .box-agent-scratch .t-pc "参考文献" _test_www /XF .htaccess nginx.htaccess core/config.php ci-preview.html /NFL /NDL /NJH > /dev/null
RC=$?
if [ "$RC" -gt 7 ]; then echo "robocopy 失败 exit=$RC"; exit "$RC"; fi

FAIL=0; N=0
cd "/d/project/owlsgo" || exit 1
# core.quotepath=false：中文路径否则会被转义成八进制，md5sum 取不到文件而误报 DIFF
for f in $(git -c core.quotepath=false ls-files); do
  # 与上方 robocopy 的 /XD 保持一致：运行数据与内部资料不参与同步校验
  case "$f" in
    data/*|uploads/*|.git/*|.workbuddy/*|.birdview/*|.tools/*|.t-pc/*|core/config.php) continue ;;
  esac
  A=$(md5sum "$f" 2>/dev/null | cut -d' ' -f1)
  B=$(md5sum "$DST_HOST/$f" 2>/dev/null | cut -d' ' -f1)
  if [ "$A" != "$B" ]; then echo "DIFF: $f"; FAIL=1; fi
  N=$((N+1))
done
echo "核对 $N 个跟踪文件"
if [ "$FAIL" -eq 0 ]; then echo "SYNC OK：全部一致"; else echo "SYNC FAIL：存在差异，见上"; exit 1; fi

# ⚠️ 不要在这里做 CLI reload：本机 shell 没有给 nginx 发信号的权限，
#   `nginx.exe -s reload` 会把整个脚本挂住（实测被 SIGTERM 打断，但前面已同步完成）。
#   PHP 文件改动不需要重载（PHP-FPM 每次请求读文件）；只有开了 OPcache 且改的是 PHP 逻辑
#   才可能读到旧编译结果，那种情况在 phpStudy 面板点「重启」即可。
echo "提示：若改了 PHP 逻辑且开启 OPcache，请在 phpStudy 面板重启 Nginx/PHP（CLI reload 无权限会卡住）"
