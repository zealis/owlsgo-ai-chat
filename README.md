# Owlsgo-Chat

纯原生 PHP 8.1+ 写的即时通讯聊天室。零框架、零 Composer 依赖，单入口 `index.php`，
把文件放进站点根目录就能用。数据库支持 SQLite / MySQL / PostgreSQL，
小站可以拿它开一个在线聊天室，也适合二次开发。

当前版本：**v1.4.0**（以根目录 `VERSION` 文件为准；完整更新记录见 `设计文档/CHANGELOG.md`）。

## 快速开始

1. 把代码放进站点根目录（虚拟主机 / 宝塔 / phpStudy 都行）。环境要求：PHP ≥ 8.1，
   启用 pdo、mbstring、curl、openssl、fileinfo 扩展。
2. 浏览器打开 `index.php`，按安装向导创建管理员——默认 SQLite，零配置。
3. 管理员从聊天室左下角进「管理后台」。

要用 MySQL / PostgreSQL：改 `core/config.php` 的 `db` 段，删掉 `data/install.lock`，重新走安装向导。

## 实时通道：长轮询是默认，WebSocket 可选

默认走 AJAX 长轮询：消息挂起在服务端最多 20 秒，一个挂起的轮询会**占住一个 php-cgi 进程**。
人多的时候请把进程数调大——PHP 设置 → 对应版本 → **php-cgi 进程数调到 5 个以上**。
实测 5 个进程时，一个 20 秒挂起的轮询期间，其他请求仍能在 3 秒内返回。

v1.3.168 起内置了 WebSocket 实时层，零依赖的 PHP 常驻进程：心跳与在线状态、消息推送、
消息发送依次迁入（P1 → P2 → P3，v1.3.172 完成发送迁移）。想用的话：

- Linux 用 PM2 起守护进程：`pm2 start "php ws-server.php" --name owlsgo-ws`，再 `pm2 save`；
- nginx 加一段 `/ws` 反代（`nginx-server.conf` 自带现成配置）；
- 后台「系统设置」里打开 WS 实时通道 / WS 发送两个开关。

连上之后消息收发和在线状态都走 wss，长轮询自动退为兜底。守护进程停了、页面断线、
开关关掉，都会自动切回长轮询，聊天不受影响。

要不要开取决于规模：WS 把连接集中到一个进程，绕开 php-cgi 进程墙，代价是多一个常驻进程要维护。
小站保持默认就行。

## 架构

鸟瞰图：`.birdview/architecture.html`

`index.php`（页面 + AJAX API + 安装向导）→ `core/`（配置 / DB / 安全 / 认证 / 聊天 / 实时层 /
后台 / 上传 / 插件）→ `plugins/`（可选功能）→ `index.css` / `index.js`（ow- 前缀自研扁平 UI）。

## 功能

- **聊天**：公开 / 密码 / 限定角色三种聊天室；普通、@提及、私信、系统消息四种消息。
  撤回窗口 5 分钟，群主与超管不受限。上翻到顶自动加载更早的历史。
  图片支持粘贴、上传、点开看大图；另有 Emoji 面板、自定义贴纸收藏、新消息提示音、聊天室背景。
- **实时**：WS 可用时秒级收发，带发送状态（发送中 → 已发送 / 失败可重试，重试不会重复）；
  不可用时长轮询兜底，断线自动切换。
- **用户系统**：邮箱注册（账号是数字 ID，对外显示昵称）、验证码、密码找回。
  游客可进可聊，浏览与发言限额后台可配。资料卡、角色标签、自定义称号。
- **在线状态**：顶栏成员图标展开，心跳同步。
- **管理后台**：通用列表轮子（服务端分页 + 批量操作）——群聊审核与回收站、用户与禁言、
  安全日志、站点设置；装插件后会出现对应的管理页，插件支持 zip 在线安装与启停。
- **安全**：API 签名（按会话密钥）、敏感操作一次性票据（用后即焚）、会话指纹守卫（UA + IP 段绑定）、
  登录验证码与失败锁定、数据库频率限制、上传多重校验（真实 MIME + 扩展名白名单 + 随机名）、
  全部文字入口过敏感词（`text.filter` 钩子）。
- **插件机制**：Hook、API 路由（`plugin_<name>_` 前缀）、后台页面、资源合并与按需加载、
  zip 在线安装、统一计划任务（长轮询、WS 守护进程、`?action=cron` 三种驱动方式任选）。

## 目录结构

```
index.php              统一入口（页面 + AJAX API + 安装向导）
index.css / index.js   核心 UI 样式与前端脚本（对外固定路径，带 ?v=<ASSET_VERSION>）
ASSET_VERSION          静态资源版本（每次改 CSS/JS/插件静态资源后递增）
ws-server.php          WS 实时层守护进程入口（CLI，可选部署）
core/                  配置 / 数据层 / 安全 / 认证 / 聊天 / 实时层 / 后台 / 上传 / 插件
assets/                SVG Logo、i18n 引擎等辅助静态文件
plugins/               插件目录（plugin.json + main.php）
data/                  SQLite 数据库、安装锁、缓存、WS 票据（运行时生成）
uploads/               头像 / 贴纸 / 图片 / 文件附件（运行时生成）
nginx-server.conf      随程序走的 Nginx 站点配置（含 /ws 反代与安全规则）
.tools/                运维脚本（sync-www.sh 同步运行副本、gen_changelog.py 生成更新日志）
设计文档/              开发文档、约定与 CHANGELOG.md
```

## 二次开发

- 所有 API 走 `index.php?action=<动作>`，POST 携带 `ts` + `sign=md5(key|ts|action)`；敏感操作另需先取 `?action=ticket` 的票据并随请求提交（`OwApi.secure` 已封装）。
- 插件示例：在 `plugins/<name>/` 放 `plugin.json` 与 `main.php`，用 `Plugin::on('message.after_send', fn)` 挂载钩子、`Plugin::route('plugin_<name>_xxx', fn, ['sensitive' => true])` 注册路由、`Plugin::adminPage('<slug>', '标题', fn)` 挂后台页、`Plugin::asset('js'|'css', '<name>/file')` 注入资源。
- 前端扩展钩子：`OwChat.onRoomSwitch(fn)`（切群/进群）、`OwChat.onRoomEdit(fn)`（群聊设置弹窗，往 `#owREExtras` 加入口）。
- 发版流程：只改根目录 `VERSION` → 提交推送 → `.tools/sync-www.sh` 同步运行副本 → `python .tools/gen_changelog.py` 更新 `设计文档/CHANGELOG.md`。
