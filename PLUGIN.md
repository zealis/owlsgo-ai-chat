# 插件开发规范（PLUGIN.md）

本文件是 AI 新建、修改和审查 Owlsgo-Chat 插件时的规范。开始工作前先读完本文件，再检查核心文件（`core/plugin.php`）和功能最接近的现有插件（`plugins/user-manager/`）；实现时以当前代码为准，不臆造接口。

> 本文档只描述 Owlsgo-Chat 自身的插件机制，全部内容以 `core/plugin.php` 与现有插件的实际代码为准。

## 执行顺序

1. 明确插件 ID、功能边界、页面入口、权限要求与计划任务。
2. 优先复用核心函数与 `Plugin::*` 既有 API；插件机制能完成时，不修改 `index.php` 或 `core/` 下的核心文件。
3. 插件只写在 `plugins/<插件ID>/` 目录内，结构与命名遵守本文「基础约束」。
4. 新建或修改插件后：PHP 语法检查（`php -l`）、JS 语法检查（`node --check`）、在「插件管理」里启用后真实走一遍功能，并按文末清单复核。
5. 修改插件必须递增 `plugin.json` 的 `version`（至少补丁位）；`description` 与当前能力保持一致。

## 基础约束

- 兼容 PHP 8.1+ 与 SQLite / MySQL / PostgreSQL 三驱动，不引入框架、Composer 包或构建依赖；跨库差异处理遵循项目既有写法（见 `core/db.php` 的 `DB::driver()` 分支与 `DB::rebuildTableWithout()` 等助手）。
- 插件目录与插件 ID 相同，只允许小写字母、数字、下划线或短横线。
- 插件自有的 PHP 函数、JS 全局对象、CSS 类、文件名必须以插件 ID 相关的名称开头，避免与核心或其他插件冲突。例：插件 `user-manager` 的 JS 全局对象是 `OwUM`（自有命名），API 路由前缀 `plugin_user_manager_`。
- 插件路由的 action 名**必须**以 `plugin_<插件ID下划线形式>_` 开头（如 `plugin_user_manager_search`）。`index.php` 对未知 action 会调用 `Plugin::dispatch()` 兜底，带前缀可避免与核心 action 或其他插件冲突。
- `main.php` 开头必须包含 `if (!defined('OWLSGO_VERSION')) exit;`，禁止直接 HTTP 访问。
- `plugin.json` 声明 `name`（显示名）、`version`、`description`（面向用户的简短说明）、`author`。
- 插件数据库操作一律使用核心 `DB` 类（`DB::run/one/all/val/insert/upsert`），禁止自行 new PDO；表名建议带 `plugin_<id>_` 前缀。建表/改表如需跨驱动兼容，参考 `core/db.php` 既有实现，不要照搬单驱动 SQL。
- **加列只能用公开的 `DB::ensureColumn($table, $col, $type, $default)`**（`$default` 传**带引号的字面量**，如 `"'1'"` / `"''"`）。`DB::addColumn()` / `hasColumn()` / `dropColumn()` 都是 **private**，插件调用会抛 `Error`。⚠️ 这个坑后果特别隐蔽：`Plugin::loadPlugin()` 用 `try/catch` 吞掉插件异常只记一条 `plugin_error`，于是**该行之后的所有 `Plugin::on()` 都没注册，但缓存清单里仍写着旧的 hooks 列表** —— 表现为「插件静默半个身位」，极像「钩子没触发」，排查方向会被带偏。
- 插件 `main.php` 顶层**只做 `Plugin::*` 注册与建表/迁移**，不要输出、不要再 `require` 其他文件、也不要有 `exit` 以外的副作用。
- **样式不得写死颜色**：CSS、内联 `style`、拼进 `innerHTML` 的 HTML 一律引用核心 `--ow-*` 变量
  （详见下方「样式与主题（禁止硬编码颜色）」）；仅「图像像素 / 数据色板」两类例外，且需就地注释。

## 目录结构与最小插件

```
plugins/<插件ID>/
├── plugin.json     # {"name":"显示名","version":"1.0.0","description":"...","author":"..."}
├── main.php        # 插件逻辑：注册后台页面、API 路由、钩子、资源
└── admin.js        # （可选）后台交互脚本，经 Plugin::asset() 注册
```

最小示例（页脚追加内容）：

```php
<?php
if (!defined('OWLSGO_VERSION')) exit;

Plugin::on('page.footer', function () {
    echo '<p style="text-align:center">Hello</p>';
});
```

## 加载与启停

- 启用状态保存在 `plugins` 表（`enabled` 字段）。未启用插件完全不执行。
- **v1.0.90 起按需加载**：`Plugin::init()` 不再在每个请求里 require 全部 `main.php`，
  而是读取 `data/cache/plugins.json` 元数据缓存（含各插件的注册清单：
  钩子 / 路由 / 后台页 / 资源）。请求周期内只在实际用到时才加载对应插件——
  钩子被 `fire` 到、路由 action 命中、渲染后台页时，才 require 其 `main.php`。
  典型效果：聊天长轮询等与插件无关的请求零插件加载。
- 缓存自动维护：首次部署、新启用插件、插件文件变更（main.php / plugin.json 的
  mtime 签名变化）后的第一个请求会真实加载一次以重建清单，随后恢复按需加载。
  手动删除 `data/cache/` 亦可强制重建。
- 资源合并（`?action=assets`）同样走清单 + 合并缓存（`data/cache/assets-*.json`），
  参与文件未变化时直接复用合并结果。
- **对插件的约束不变**：`main.php` 顶层只做 `Plugin::*` 注册，不做输出与其它副作用
  （清单与探测机制都依赖此契约）。
- 安装方式两种：后台「插件管理」上传 zip（包内须有 `<插件ID>/plugin.json`），或直接把插件目录放进 `plugins/`。
- 安装/上传后默认停用，需在「插件管理」列表中启用。
- 插件启停后整页刷新，侧栏子菜单与可用页面随之更新；**未启用的插件**在侧栏显示灰色「`<ID>（未启用）`」子项，点进去是停用说明 + 「启用插件」一键按钮。
- `plugins/` 目录**纳入 Git 仓库**（v1.2.15 撤销 v1.0.45 的整目录排除：插件与核心同为产品代码，不同步版本无法定位回归）；仅排除目录内的运行时产物（`uploads/`、`cache/`、`*.log`）。插件代码与 `plugin.json` 一律随仓库提交。
- 启停与安装会写安全日志（`Sec::log`）。

## 敏感操作安全校验（v1.0.91 起）

凡是**删除数据、恢复数据、禁用账号 / 禁言、退出登录、关闭安全功能**之类的插件路由，
必须标记为敏感操作，服务端会强制「POST + 一次性操作票据」校验，防止请求重放与跨站劫持。

```php
// 方式一：注册时声明
Plugin::route('plugin_xxx_delete', function (array $ctx) { ... }, ['sensitive' => true]);

// 方式二：多行闭包注册后单独声明（推荐，可读性好）
Plugin::route('plugin_xxx_delete', function (array $ctx) { ... });
Plugin::sensitive('plugin_xxx_delete');
```

前端调用必须用 `OwApi.secure(action, data, cb)`（签名同 post；自动先请求
`?action=ticket` 取一次性票据再随请求提交，服务端校验后立即作废票据）。
不要用 `OwApi.post` 调敏感接口——会被 403 拒绝并记入安全日志（`sensitive_reject`）。

约定要点：
- 票据 5 分钟有效、一次性；签名验证依旧先行，票据是额外一道防线；
- 敏感操作在 UI 层保留二次确认弹窗，与票据校验形成双层防护；
- 核心 action 的敏感清单在 index.php `$SENSITIVE`；插件一律用上面的声明方式，
  清单随插件缓存（plugins.json）持久化，无需手工维护。

## 后台页面（Plugin::adminPage）

```php
Plugin::adminPage('user-manager', '用户管理', function () {
    return '<h2>用户管理</h2> ... ';   // return 或 echo 均可，见下
});
```

- 注册后，后台侧栏「插件管理」分类下出现该插件的子页（一插件一页）；分类标题本身仍指向插件列表页。
- 页面 HTML 通过 `admin_plugin_page` 接口取回并注入 `#owAdminMain`；回调 **echo 输出**与 **return 字符串**两种写法都支持。
- 页面 HTML 是经 `innerHTML` 注入的，**内联 `<script>` 不会执行**——交互函数必须写在插件自己的 JS 文件里（见下）。
- **表单的提示与说明一律用 ow-tip 展示（v1.3.30 全站规范）**：说明文字写成 `.ow-form-item` 内的
  **无 class、字号 ≤12.5px** 的 `<p style="font-size:12px">`，主程序 `OwTip.scan()` 会自动把它收进
  label 右侧的 ⓘ 悬浮气泡（后台页注入后已自动扫描；前台动态渲染的容器需自行调 `OwTip.scan(el)`）。
  页面上**不要**内嵌长段说明——可见文字只留一句短的（好处 + 代价），细节全部进 ⓘ。
  参考实现：`plugins/attachment-manager/` 的「图片压缩」区块。

## API 路由（Plugin::route）

```php
Plugin::route('plugin_user_manager_search', function (array $ctx) {
    $actor = $ctx['actor'];   // 当前访问者摘要（kind/id/nickname/role...）
    $post  = $ctx['post'];    // $_POST
    Api::json(['ok' => true, 'data' => []]);
});
```

- 路由在前台 AJAX 分发的最后兜底触发：核心 action 与 `admin_*` 后台动作之外，未命中的 action 会进入 `Plugin::dispatch()`。
- ctx 固定为 `['actor' => 当前访问者, 'post' => $_POST]`。
- **权限自查是插件路由的硬性要求**：核心不会替插件校验权限。需要管理员的接口，第一步必须做：

```php
if (($ctx['actor']['role'] ?? '') !== 'admin') Api::json(['ok' => false, 'msg' => '需要管理员权限'], 403);
```

- 输出统一用 `Api::json()`；禁止 `echo` 混入 JSON 响应。

## 静态资源（Plugin::asset）

```php
Plugin::asset('js', 'user-manager/admin.js');   // 相对插件目录；css 同理
```

- 合并输出地址：`?action=assets&type=js` / `?action=assets&type=css`（免签名 GET，纯静态无写操作）。
- **后台页面**已自动引入 `<script src="?action=assets&type=js">`：需要后台交互的插件在此声明 JS，交互对象挂为全局（如 `window.OwUM`），页面 HTML 里用 `onclick="OwUM.search()"` 调用。
- 脚本可复用主程序暴露的全局：`OwApi`（AJAX + 自动签名）、`OwBusy`（按钮防重入，见下节）、`esc`、`toast`、`fmtUid`、`opts`、`ROLE_CN`、`OwAdmin`、`OwChat`。不要重复实现这些能力。
- 前端可从 `OwChat.cfg.site_url` 取当前站点根地址（后端 `ow_site_url()`，未配置时为自动识别值）。

## 前端按钮防重入（OwBusy，v1.5.18 起）

用户手抖连点两下，就会发出两个请求。登录页为此付出过代价：后台「失败 10 次锁 15 分钟」按次数算，
一次双击等于两次失败。核心从 v1.5.18 起提供通用轮子 `OwBusy`，插件直接复用，不要自己写 `disabled` 标志位。

### 自动兜底：什么都不用做

只要请求是在**点击的处理函数里同步发起**的，`OwApi.post` / `OwApi.secure` / `OwApi.upload` 会自动：

1. 锁住触发这次请求的按钮（原生按钮置 `disabled`；`a` / `role=button` / `.ow-btn` 等加 `.ow-busy`）；
2. 期间的重复点击被捕获阶段拦掉；
3. 请求回来（`secure` 要等票据 + 业务两次都回来）自动释放；
4. 链式请求（先 `room_create_gate` 再 `room_create`）会继续挂在同一把锁上，不会中途解禁。

所以插件里这种写法**已经有了防重入**，不用改：

```js
OwApi.post('plugin_my_save', data, function (r) { … });
OwApi.secure('plugin_my_delete', { id: id }, function (r) { … });
```

几类接口故意不自动锁，避免把等待时间算到用户头上：

- `poll` / `dm_poll` / `heartbeat_status` / `ws_ticket` / `conv_read` / `conv_unread_at`：轮询与后台请求；
- `send`：发送按设计是非阻塞的（300ms 合并 + 服务端幂等键）；
- `send_code`：「发验证码」自己管禁用与倒计时，自动锁反而会在响应后把倒计时解开。

插件自己的接口名不会撞上这些键；若确实需要「点一下就锁住」，用下面的手动方式。

### 手动：异步动作不在点击处理函数里

文件选择器（上传在 `change` 里才开始）、WebAuthn、第三方 `fetch`、`canvas.toBlob` 等场景，
自动锁拿不到那次点击，需要显式包一层：

```js
// 方式一：run（最常用）——按钮忙时返回 false，任务结束必须调 done()
btn.onclick = function () {
    OwBusy.run(btn, function (done) {
        OwApi.upload('plugin_my_upload', file, {}, function (r) {
            done();                    // 成功、失败都要调，否则只能等 30s 安全超时
            …
        });
    }, { timeout: 120000 });           // 上传类建议放宽（默认 30s；OwApi.upload 的自动锁是 120s）
};

// 方式二：lock —— 多步骤 / 回调不在同一处时，拿释放函数自己管（引用计数，可嵌套）
var release = OwBusy.lock(btn, 60000);
doSomething(function () { release(); });

// 方式三：表单级 —— 提交期间禁用整张表单的按钮，同一表单只放行一次
OwBusy.form(form, function (done) {
    OwApi.post('my_form_submit', data, function (r) { done(); … });
});

// 方式四：裸 XHR / WS 也要挂到「刚点的按钮」上（不经过 OwApi 时）
var release = OwBusy.autoLock('plugin_my_test');
fetch('/…').finally(function () { release(); });
```

参考实现：`plugins/chat-background/chat.js`（选完文件后手动锁「上传」）、
`plugins/gif-stickers/admin.js`（裸 XHR 的连通性自测）、`plugins/twofa/chat.js`（表单级）。

### 行为约定

- 释放只恢复「上锁前本来是启用」的按钮；倒计时、权限置灰等别人设的 `disabled` 不会被解开。
- 同一元素可以嵌套上锁（如 `secure` 的票据请求），引用计数归零才真正解禁。
- 安全超时：`run` / `lock` / `post` / `secure` 默认 30s，`form` 默认 15s，`OwApi.upload` 的自动锁 120s；请求异常丢失时不会把按钮永久焊死。
- **不要**给「本来就该连点」的按钮加锁（例如发送消息、翻页、表情切换）；防重入只针对会重复提交副作用的动作。
- 原生按钮被禁用后不会再触发 `click`，所以服务端仍然必须自己做幂等/频率校验 —— 前端锁只防手抖，不防恶意并发。

### 站点地址（ow_site_url / ow_abs_url）

需要生成绝对 URL（邮件链接、分享、回调地址）时用主程序助手，不要自行拼 `$_SERVER`：

```php
ow_site_url();                 // 站点根地址：后台「固定网站地址」优先，留空则自动识别（兼容反代头与子目录）
ow_site_url(true);             // 只取「手动配置」的值，自动识别不参与
ow_abs_url('uploads/a.jpg');    // 拼接绝对地址；第二个参数默认 true（仅手动配置生效），
                                // 未配置时原样返回相对路径，避免自动识别误判写入不可访问的地址
```

- 自动识别顺序：`X-Forwarded-Proto` / `HTTPS` → `X-Forwarded-Host` / `Host` / `SERVER_NAME`（仅兜底时补非标准端口）→ 子目录部署路径。容器反代下不会误拼服务器内部端口（如 `:80`）。
- 后台设置项 `site_url`：留空自动识别；填写时后端校验必须以 `http://` 或 `https://` 开头。

## 样式与主题（禁止硬编码颜色）

**硬性规则：插件样式里一律不得出现写死的颜色值**（`#RGB` / `#RRGGBB` / `rgb()` / `rgba()` / 颜色名），
无论它出现在 `.css` 文件、PHP/JS 里的内联 `style="…"`，还是动态拼进 `innerHTML` 的 HTML 字符串里。

原因：全站支持「浅色 / 深色 / 跟随系统」三档主题，实现方式是**只重定义 `--ow-*` CSS 变量**
（见 `assets/css/owlsgo.css` 的 `[data-theme="dark"]` 块）。写死颜色等于绕开这套机制——
深色模式下会表现为「深底 + 深字」「整块露白」这类不可读的亮斑，而且核心永远修不到插件里
（v1.3.37 全量清理插件样式时，公告插件因为整套浅色写死，深色下整片坏掉）。

### 用法

```css
/* ✅ 正确：引用核心语义变量，深浅两套主题自动跟随 */
.oa-card { background: var(--ow-bg-sub); color: var(--ow-text); border: 1px solid var(--ow-border); }

/* ❌ 错误：写死浅色，深色模式下必然是亮斑 */
.oa-card { background: #f5f5f5; color: #333; border: 1px solid #f0f0f0; }
```

```js
// ✅ 内联样式同样只能写变量
'<span style="color:var(--ow-text-sub)">暂无记录</span>'
// ❌
'<span style="color:#999">暂无记录</span>'
```

变量可以带一个浅色回退值（`var(--ow-text-sub, #999)`），但回退值只在前端没加载核心样式表时兜底，
**不能当成「包在 var 里就不算硬编码」**：正常页面下它永远不生效，深色依旧会坏。

### 常用变量速查

| 用途 | 变量 |
|---|---|
| 页面底 / 卡片内小块 / 聊天区与侧栏 | `--ow-bg`、`--ow-bg-sub`、`--ow-bg-chat` |
| 浮层 / 通栏条 / 带边框面板 | `--ow-surface`、`--ow-surface-2`、`--ow-panel-bg` |
| 正文 / 副文本 / 标题 | `--ow-text`、`--ow-text-sub`、`--ow-text-title` |
| 分隔线 / 输入控件边框 | `--ow-border`、`--ow-border-control` |
| 品牌色（描边 / 实心按钮 / 链接与 hover / 浅蓝底高亮行） | `--ow-primary`、`--ow-primary-strong`、`--ow-primary-dark`、`--ow-blue-light` |
| 语义色（成功 / 称号 / 危险 / VIP） | `--ow-green`、`--ow-yellow`、`--ow-red`、`--ow-orange` |
| 交互态 | `--ow-hover-bg`、`--ow-active-bg` |
| 警示块（配 `--ow-yellow` 文字） | `--ow-warn-bg`、`--ow-warn-border` |
| 实心徽标 / 按钮上的文字 | `--ow-on-accent` |

⚠️ **插件不要自己写 `[data-theme="dark"]` 覆盖块** —— 出现这种块，基本说明该处本该用变量。
若核心确实缺一个语义变量，请在核心 `:root` 里补（并在 `[data-theme="dark"]` 里配一份深色值），
插件直接引用；不要用「插件里再写一套深色规则」的方式绕过。

### 唯二例外（可以写死，但必须就地注释原因）

1. **图像像素**：二维码 / 验证码 / 装饰性 SVG 里的 `fill="#FFFFFF"`、`stroke="#000"`、蒙版黑白色，
   以及图像缩略图上的半透明黑底白字标签（`rgba(0,0,0,.55)` + `#fff`）。这些是「画出来的内容」，
   不随主题变化。
2. **数据性质的色板**：等级阶段色、默认头像底色等**本身就是数据**的颜色。允许写死，
   但必须保证**在深色底上同样可读**（取中等明度的饱和色），并在注释里点明这是色板而非界面色。

### 列表面板顶栏 `.ow-list-head`（v1.4.4）

面板 / 列表卡片需要一行「标题 + 分栏 tab + 分页 + 动作按钮」时，直接用核心的 `.ow-list-head`
（标题套 `.ow-list-head-t`），不要再自造一套顶栏 —— 时光机原来的 `.ow-tm-head` 已提升成这个通用类：

- 放进 `.ow-card` 会**自动满宽出血**（负边距对齐卡片内边距），下沿一条分割线接住表格；
- **有分页 tab 的列表优先用它**：分页器 `.ow-pager` 落在顶栏里会自动靠右并去掉那档上边距
  （后台「审核回收站」就是这么接的，见 `assets/js/chat.js` 的 `pages.rooms`）；
- 吸顶（sticky）**刻意不在通用类里**：只有「面板自己就是滚动容器」时才有意义，插件自己加一条 ——
  参照 `plugins/time-machine/style.css` 的 `.ow-tm .ow-list-head { position: sticky; top: 0; z-index: 5; }`。

## 文案：别写死「积分」（v1.5.4 起）

「积分」是站点可改的**名词**（后台 系统设置 → 基本信息 → 积分名称，存 `settings.points_name`，
空 = 默认「积分」）。站点可能把它叫「金币 / 星光 / 猫粮」，所以插件的所有用户可见文案
（前台面板、后台表单、接口返回的 `msg`）都不要写死这两个字，走统一的出口取：

```php
$pn = ow_points_name();                       // 服务端（空值已兜底为「积分」）
$msg = '签到成功，获得 ' . $n . ' ' . $pn;      // 拼进入口返回的 msg

// 后台表单标签：$f()/kpi() 之类会自己转义，传原值即可；
// 拼裸 HTML 时记得 Sec::e() / esc()
. '<h3>建群名额与' . Sec::e($pn) . '</h3>'
```

```js
// 前台（聊天页）：核心已下发 boot.settings.points_name
var pn = (w.OwChat && OwChat.pointsName) ? OwChat.pointsName() : '积分';

// 后台（插件后台页）：由 OwAdmin.init 下发
var pn = (w.OwAdmin && OwAdmin.pointsName) ? OwAdmin.pointsName() : '积分';
```

两条注意：

- 返回值是**纯文本**，拼 HTML 仍要自己 `esc()` / `Sec::e()`（核心不替调用方转义）；
- 名称只在「用户看见的名词」处替换。数据库字段（`users.points`）、日志 key（`points` / `cost`）、
  内部注释照旧 —— 那是**数据结构**，跟着改名会把库和旧日志一起弄乱。

## 积分到账必须发系统通知（v1.5.7 起）

**硬性要求：任何让 `users.points` 变大的动作，都必须给本人 `Notice::push` 一条系统通知。**

理由不是「体验更好」，而是**用户端没有别的感知渠道**：积分到账几乎都是异步的 ——
别人领走了你的红包、红包到期退余款、维护任务补退、管理员后台发放 —— 用户不刷新页面、
不盯余额就永远不知道钱到了。少一条通知，就是一笔对不上账的余额。

### 写法（三件套，缺一不可）

```php
// ① main.php 顶层注册模板（v1.3.171 起插件类型不用再改核心，见「通知类型注册」）
Plugin::on('notice.kinds', function (array &$kinds) {
    $kinds['my_plugin_grant'] = '你获得 {amount} {unit}，当前余额 {balance} {unit}。';
});

// ② 加积分的同一处（UPDATE 之后）push 一条
DB::run('UPDATE users SET points=points+? WHERE id=?', [$amount, $uid]);
Notice::push($uid, 'my_plugin_grant', [
    'amount'  => $amount,
    'balance' => (int)DB::val('SELECT points FROM users WHERE id=?', [$uid]),
    'unit'    => ow_points_name(),      // ③ 名词走统一出口，别写死「积分」（见上一节）
]);
```

- **`{unit}` 占位必须传 `ow_points_name()`**：模板是固定字符串，站点把「积分」改成金币 / 星光后，
  文案要跟着变。写死「积分」等于把上一节的约定从后门放回来；
- **模板要能整句翻译**：插件自己的 `lang.js` 里补一条「中文模板 => 英文模板」（占位符原样保留），
  与内置类型同一条路（lang-en 里 `rp_claimed` / `shc_checkin` / `um_points_grant` 就是例子）；
- **通知是旁路留痕**：`Notice::push` 自己吞异常，不要 try/catch 包它，更不要因为通知失败回滚加分。

### 该发 / 不该发

| 场景 | 是否通知 | 说明 |
| --- | --- | --- |
| 领取红包、签到、抽奖、任务奖励到账 | 发 | 用户「获得」了东西，必须留痕 |
| 到期退款、撤销退款、维护任务补退 | 发 | 钱是异步回来的，不发就没人知道 |
| 管理员后台把积分调高 | 发 | 只发**差额**，后台动作用户看不见 |
| 管理员把积分调低 / 设成同一个值 | 不发 | 不是「获得」；同值刷一条纯属噪音 |
| 同一请求内的失败回滚（扣了又立刻退回） | 不发 | 用户在同一响应里已看到结果，再发一条自相矛盾 |
| 单纯查询、展示余额 | 不发 | 不是变动 |

减少积分（扣费）不做强制要求 —— 扣费都有即时可见的操作结果，需要留痕的走插件自己的记录表或 `Sec::log`。

### 自查

新增或修改积分逻辑后，跑一遍这两条：

```bash
grep -n "points=points+" plugins/*/main.php          # 每一处加分后面都必须跟一条 Notice::push
grep -n "notice.kinds" plugins/*/main.php            # 有 push 就必须有对应模板注册
```

当前全站加分点：红包 `red-packet`（领取 / 到期退回 / 撤销退回 / 余款退回）、
签到 `starlight-hall`（普通签到 / 补签 / 管理员代签）、后台发放 `user-manager`。

## 头像相关（v1.3.11 起）

头像是「风格 + 种子」的生成结果（DiceBear 10.x），核心字段 `users.avatar_type` / `avatar_style` /
`avatar_seed`。插件有两个接入点：

**1. 改写头像地址（引用传参，`fire()` 没有返回值）**

```php
Plugin::on('avatar.url', function (&$url, $ctx) {
    // $ctx = ['style' => 'dylan', 'seed' => '...', 'source' => 'api' | 'identicon']
    // 例：改指到自己的 CDN 或本地预生成的 SVG
    $url = str_replace('https://api.dicebear.com/10.x/', 'https://cdn.example.com/avatar/', $url);
});
```

**2. 提供头像上传实现**

核心已移除 `kind=avatar` 上传入口，附件上传插件通过注入前端函数接管：

```js
OwChat.registerAvatarUploader(function (file, filename, onOk) {
    OwApi.upload('my_plugin_upload_avatar', file, {}, function (r) {
        if (r && r.ok) onOk(r.url);
    });
});
```

注册后，核心的头像设置弹窗才会显示「上传头像」选项；未注册时用户仍可选生成式头像。

---

## 钩子（Plugin::on / fire）

当前核心提供的钩子，**以源码 `Plugin::fire()` / `Plugin::collect()` 的调用点为准，不臆造**。
注册一律是 `Plugin::on(name, fn)`，写在 `main.php` **顶层**。

> **`plugin.json` 里不需要声明钩子**（v1.3.153 更正）：注册清单是核心扫描 `main.php` 时自动记下的 ——
> `Plugin::on()` 把钩子名记进 `$pending['hooks']`，随后写进 `data/cache/plugins.json`；
> `fire` / `collect` 决定「要不要 require 哪个插件」读的就是这份清单。
> 手写一个插件不读的 `hooks` 字段只会误导后来人（现存仅 `oauth-core` 留了一个，是早期设计残留）。

### fire —— 通知 / 闸门（无返回值）

| 钩子 | 触发时机 | 参数 |
|---|---|---|
| `request.start` | 每个请求开始（`index.php`，DB 已就绪、`$actor` 已解析、**尚未输出任何字节**） | `[$actor]` —— 想在「任何路由与输出之前拦人」就挂它：处理器可直接 403 退出。插件是按需加载的，所以需要一个稳定入口 |
| `user.active` | 用户活跃时（`index.php`，**每请求一次**，含长轮询；仅登录用户，游客不触发） | `[$actor]` —— 等级信任插件据此把「今日已登录」计入每日任务（内部按自然日去重）。⚠️ 只挂 `login.after_verify` 会漏掉绝大多数活跃日：登录态是**会话**，开着页面第二天继续用不再走登录 |
| `page.head`           | 各页面 `<head>` 输出时（`pageHead()` 内） | 无参，可直接 echo                                                          |
| `page.footer`         | 聊天页 / 后台页 body 输出末尾              | 无参，可直接 echo                                                          |
| `cron.minute`         | 统一计划任务（每分钟至多一次）                  | 无参                                                                   |
| `avatar.url` | 计算某用户的头像地址时（`Auth::avatarUrlFor`；v1.5.17 起上传头像的 `Auth::avatarFileUrl` 同样触发，`$ctx['source']` 为 `upload`） | `[&$url, $ctx]` —— `$ctx` 含 `style` / `seed` / `source`；改写 `$url` 即可换掉头像源（含 DiceBear / 内置 identicon）。云存储插件用它把上传头像的本地 `uploads/` 路径映射到对象存储地址（库里的路径不动，停用插件即整体回落） |
| `nickname.before_save` | 昵称校验（注册 / 改资料 / 安装向导，`Auth::checkNickname` 内） | `[&$nick, &$err, $ctx]` —— 可改写 `$nick`，或把 `$err` 设为非空字符串拦截（即用户看到的文案）；`$ctx['scene']` 为 `register` / `profile` / `install`。参考实现：`plugins/nickname-guard/` |
| `login.after_verify` | 登录验证完成（`Auth::login` 内，密码校验通过且会话已建立） | `[$user, $method, $ctx]` —— 通知型，`$method` 为本通过验证的方式（核心仅 `password`；插件实现两步验证时可自行触发本钩子并传 `totp` / `recovery`）；`$ctx` 含 `ip`。**仅在成功登录时触发，验证失败不触发** |
| `login.failed` | 登录失败（`Auth::login` 内，**每次失败都触发**，v1.1.12 起） | `[$info]` —— 键：`identity`（用户提交的登录标识，原样）、`reason`（`fail` 密码或账号错 / `locked` 临时锁定 / `disabled` 账号被禁用）、`msg`（用户看到的文案）、`left`（剩余尝试次数，`-1` 表示不适用）、`user`（命中的 `users` 行）、`ip`。⚠️ **密码错误时 `user` 为 `null`**（确认「账号存在」本身就是信息，核心不给出以防账号枚举侧信道），回调内**必须判空**。与 `login.after_verify` 严格成对：一个只在成功时触发、一个只在失败时触发，插件不必自己判断方向 |
| `logout.before_destroy` | 用户主动登出（`Auth::logout` 内，**`session_destroy()` 之前**，v1.1.12 起） | `[$info]` —— 键：`uid`、`nickname`、`had_uid`（是否带登录态）、`ip`、`reason`（恒为 `manual`）。⚠️ 核心保证在销毁前 fire，所以 uid / 昵称一定拿得到；**游客会话 `had_uid` 为 false，判空后跳过可免得刷出一堆噪声** |
| `session.destroyed` | 会话被服务端销毁（`Sec::fingerprintGuard` 内，指纹不符时，**`session_destroy()` 之前**，v1.1.12 起） | `[$info]` —— 键：`reason`（当前唯一值 `fingerprint_mismatch`）、`uid`、`had_uid`、`fp_saved`（会话内原指纹）、`fp_now`（本次算出的指纹）、`ip`。⚠️ **这不是用户主动登出**，插件应与 `logout.before_destroy` 区别对待（如记成「疑似会话被盗」而非「主动退出」） |
| `captcha.enforce` | 人机验证闸门（`index.php`，登录 / 注册 / 发信等场景取票之时） | `[&$ok, &$msg, $ctx]` —— `$ctx` 含 `scene` / `post` / `actor`；`$ok` 置 true 即放行，`$msg` 为拒绝文案。实现见 `plugins/captcha-verify/` |
| `embed.sess_user` | 解析「无 cookie 场景下的用户身份」时（`index.php` 身份解析块，请求带 `embed_sess` 且仍是访客时，v1.3.104） | `[&$user, $sess]` —— `$sess` 是 32 位小写 hex 的会话令牌明文；把对应的 `users` 行**整行**回填到 `$user` 即以该用户身份处理本次请求，核心负责 `status=1` 兜底复核。无人响应 / 校验不过就静默回落到访客身份（功能不坏，只是没登录态）。⚠️ 纯备用凭证通道，**权力等同该用户本人**（发消息、私信、收藏…），因此：① 只在「同请求已带合法 `embed_token`、且该令牌绑定了房间」的场景可用；② 必须校验令牌自己没过期/没吊销，且**签发它的那枚嵌入令牌仍有效**（父级吊销要能连坐）；③ 只存 sha256、可吊销、有效期别太长；④ 绝不回填 `password` 之外的敏感字段给前端——`$user` 只在服务端内部使用，但插件里别顺手塞进任何 echo 输出 |
| `upload.guard` | 上传闸门（`index.php` 的 `?action=upload`，**落盘之前**） | `[&$allow, &$reason, $kind, $actor]` —— `$allow` 置 false 拦截，`$reason` 即用户看到的文案；`$kind` 为 `avatar` / `sticker` 等（等级信任插件按等级限制头像与贴纸） |
| `upload.image` | 头像/贴纸/图片消息落盘前（`Upload::handle` 内，`checkImage` 之前） | `[&$f, $kind]` —— `$f` 是 `$_FILES['file']` 的拷贝，按引用改写即可替换落盘内容（附件上传插件在此做 WebP 压缩，按 `$kind` 细分开关）。⚠️ 替换 `tmp_name` 时产物是自建临时文件，核心落盘已按 `is_uploaded_file()` 分流 move/copy（v1.3.29） |
| `upload.stored` | 落盘后（v1.5.17）：`Upload::handle`（头像/贴纸/图片消息）与 `Upload::storeFile`（文件附件、语音消息）各触发一次 | `[&$out, $kind, $rel, $abs]` —— `$out` 是调用方即将返回的第二元素（`handle` 里是返回前端的 **URL**，`storeFile` 里是存进消息 content 的**相对路径**），按引用改写；`$rel`/`$abs` 是本地落点（相对 `uploads/` 与绝对路径）。云存储插件在此把 `$abs` 搬去对象存储并改写 `$out`（URL 或自有云端标识）；本地文件删不删由插件决定，核心不代劳。**只在落盘成功时触发**；无人应答时与此前完全一致 |
| `media.allow` | 媒体地址校验失败时的放行口（v1.5.17）：`Chat::normalizeRich`（图文混排的 `mediaRel` 失败）与 `Chat::send` 文件分支（`fileAbs` 失败） | `[&$allow, &$ref, $kind, $actor, $roomId]` —— 插件担保的云端地址/标识放行（`$allow` 置 `'1'`）；`$ref` 按引用可就地改写成渲染可达的完整地址（normalizeRich 里改写的值直接入库）。核心只认「自己空间里的 key」，外链仍进不来；无人应答时维持「仅限本站 uploads/ 下真实文件」 |
| `file.download` | 下载消息附件时（`index.php` 的 `file_download`，**权限校验之后、读本地盘之前**，v1.5.17） | `[&$handled, $path, $msg, $actor]` —— 插件认出 `$path` 是自家云端标识时自行输出（流式代理或 302 到对象存储）并置 `$handled=true`；核心随即照常发 `file.downloaded` 通知并退出。放在权限校验后：插件不能借它绕过群/私聊可见性。无人应答时维持本地 `readfile` |
| `sticker.allow` | 收藏贴纸的地址放行（`Upload::addSticker` 内） | `[&$allow, $url, $actor]` —— `$allow` 置字符串 `'1'` 才放行**外站**图片地址（核心默认只认本站 `uploads/`）；表情包插件用它在收藏自己图源的外链时作保。放行后核心仍会校验地址形状 |
| `sticker.quota` | 收藏贴纸数量校验前（`Upload::addSticker` 内） | `[&$limit, $actor]` —— `$limit` 初始 100，改小即收紧；`<=0` 表示未解锁直接拒（等级信任插件按 sticker_tiers 分档） |
| `file.downloaded` | 下载消息附件后（`index.php`） | `[$下载者id, $消息id, $发送者id]` —— 通知型，等级插件据此计任务进度。v1.5.17 起插件经 `file.download` 接管的云端下载同样计数（下载真实发生了） |
| `room.create.gate` | 建群闸门（名额 / 权限，`index.php`） | `[&$gate, $actor]` —— `$gate` 置非空字符串即拒绝（即用户看到的文案） |
| `room.created` | 建群成功后（`index.php`） | `[$id, $actor, $charged]` —— `$charged` 为消耗的积分（0 = 免费），等级插件据此统计建群次数 |
| `room.after_update` | 群资料 / 群设置更新后（`Chat::updateRoom`，后台改名、清空群头像） | `[$roomId, $sets, $actor]` —— `$sets` 为本次改动的字段 |
| `room.restored` | 群聊从审核回收站撤销「删除」后 | `[$roomId, $row, $actor]` —— `$row` 为恢复的整行数据 |
| `friend.guard` | 加好友闸门（`Chat::addFriend` 内） | `[&$allow, &$reason, $actor, $toId]` —— `$allow` 置 false 拦截，`$reason` 为展示文案 |
| `dm.read` | 私聊已读上报（`Chat::markDmRead` 内） | `[&$allow, &$reason, $actor, $targetId, $from, $to]` |
| `ban.check` | 消息发送禁言判定（`Chat::isBanned` 内，核心 bans 表无命中时触发，每条消息一次） | `[&$reason, $actor, $roomId]` —— `$reason` 初始为 **null**，设为非空字符串即拦截（即用户看到的文案）；回调签名必须用 **`?string &$reason`**（nullable，初始 null 传非 nullable 引用会 TypeError 且被 fire 静默吞掉）。参考：`plugins/ban-manager/` 的运行时说明 |
| `text.filter` | 任何要入库的文字（消息 / 公告 / 举报理由…）**改写**用 | `[&$text, $scene, $actor]` —— `$text` 按引用改写（敏感词插件在这里做替换），不拦截 |
| `text.opaque` | 同一批文字的**不透明内容**声明（v1.5.8，`Chat::filterText` / `Chat::checkText` 开头） | `[&$skip, $text, $scene, $actor]` —— `$skip` 置 true 即该段文字**整段跳过** `text.filter` 与 `text.check`，由其自行负责内容安全。端到端加密的密文信封必须走这条：base64 里偶发命中短敏感词会被打码，一个字符被改就 AEAD 认证失败，那条消息从此永久解不开 |
| `text.check` | 同一批文字（消息 / 昵称 / 公告…）的**拦截**判定 | `[&$reason, &$text, $scene, $actor]` —— `$reason` 置非空即拦截，即用户看到的文案 |
| `message.quote` | 消息带引用发送时（`Chat::send` 内，引用已规范化为 JSON 后） | `[&$quote, $content, $actor, $roomId]` —— `$quote` 为 `{nick,text}` 的 JSON 字符串；可改写为脱敏后的内容，或置空禁用该条引用 |
| `message.before_send` | 消息入库前（`Chat::send` 内）            | `[&$content, $actor, $roomId, $toUserId]` —— `$content` 按引用传入，可改写（敏感词过滤之后、入库之前）。`$toUserId` 为 v1.5.8 追加：私聊时是收件人 id，群聊为 null —— 加密插件靠它判断「这条私信属于哪个会话」（只有 `roomId=0` 分不出收件人）。老插件少声明该形参不受影响 |
| `message.maxlen` | 单条消息长度校验前（`Chat::send` 内，v1.5.8） | `[&$maxLen, $type, $roomId, $actor, $content]` —— `$maxLen` 初始 2000，改大即放宽（加密信封比明文长约 70%）。刻意带上 `$content`：只该给「确实是密文」的那种放宽，不能把明文私信的上限一起抬掉 |
| `message.conv_text` | 会话列表那一行的摘要（`Chat::convText` 内，v1.5.8） | `[&$text, $m]` —— `$text` 已按 60 字截断，按引用改写。加密会话的正文是信封，直接截断会把 base64 抖到列表上，插件在这里换成「[加密消息]」 |
| `message.after_send` | 消息入库后（含定时群发 / AI 应答等由插件发出的消息） | `[$msgId, $actor, $roomId, $content, $type, $toUserId]` —— 通知型；群聊自动化据此做关键词回复与 @ 应答（回调里要防循环：机器人自己发的消息也会进来） |
| `msg.before_delete` | 删除消息前（`Chat::deleteMessage` 内，权限判定后、执行前） | `[&$allow, &$reason, $msg, $actor]` —— `$allow` 置 false 即拦截，`$reason` 为展示给用户的理由；可用于保护特定消息/房间 |
| `msg.after_recall` | 撤回消息后（自己撤回或管理员撤回他人，`Chat::recall` 内） | `[$msgId, $msg, $actor]` —— 通知型，供审计 / 同步类插件扩展 |
| `user.card` | 打开用户资料卡取数据时（`?action=user_card`） | `[&$u, $actor]` —— `$u` **按引用**传，插件补字段即可随卡片一起下发（等级信任插件挂 `Lv.N`），前端不必再打一次接口 |
| `ip.location` | 管理员查 IP 归属地（`?action=ip_loc`，核心 v1.0.55 起不再内置实现） | `[&$loc, $ip, $actor]` —— `$loc` 初始 `''`，设为非空字符串即作为归属地结果返回；无人响应时接口返回「未安装归属地查询插件」。菜单入口由插件用 `OwChat.onMsgCtx` 自行注册 |
| `mail.render` | `mail.send` **之前**，核心已生成码与默认文案（v1.3.55） | `[&$subject, &$body, &$format, $ctx]` —— 插件按 `$ctx['kind']` 套自己的模板改写主题与正文，并把 `$format` 置 `html`。⚠️ 只对验证码生效：`$ctx['code']` 为空的邮件（别的插件群发通知）不要改写，否则会把别人的正文换成验证码模板 |
| `mail.send` | 发验证码等邮件要投递时（`Mailer::send`） | `[&$sent, $to, $subject, $body, &$error, $ctx]` —— 核心 v1.0.81 起不再内置 SMTP；插件完成投递后把 `$sent` 置 true，失败时把可展示的原因写进 `$error`（会拼进用户提示，**不要塞原始异常/凭据**）。无人响应时验证码仍入库，但接口返回「站点未启用邮件发送」。`$ctx` 含 `kind`（register/reset/chpwd…）、`code`、`ttl`、`name`、`email`、`site_name`、`format`（text/html） |
| `mail.unsent` | 没有任何插件响应 `mail.send`（v1.3.55） | `[$info]` —— 键 `to` / `subject` / `kind`。用于记「待发送」统计：码已入库但没发出去，用户必然收不到 |
| `notice.contacts` | 渲染侧栏通知区时（`index.php` 组装 boot，**仅登录用户**） | `[$add]` —— 唯一一个参数是**登记函数**：`$add(['key' => 'mykey', 'name' => '我的通知', 'avatar' => 'https://…', 'unread' => 3, 'last_text' => '有新内容'])`。侧栏通知区里除了「系统通知」那一行，插件可以加自己的行（与系统通知共用同一套行渲染）。**只有 `key` 必填**（`[A-Za-z0-9_-]{1,32}`）：没给 `name` 就用插件名，没给 `avatar` 就用 open-peeps 生成头像（种子由插件 id 派生，固定不变 —— 通知是长期留痕，头像每次变的话用户认不出是谁发的）。点击交给插件的前端处理器（见「通知联系人」一节）。⚠️ 为什么要传登记函数而不是按引用传数组：登记发生在插件自己的回调里，此刻 `Plugin::current()` 正是它，默认值才拿得到来源，插件不用自报 `['plugin' => '自己']` |

| `contact.rows` | 渲染侧栏联系人区时（`index.php` 组装 boot，**仅登录用户**） | 与 `notice.contacts` **同一个注册函数、同一套校验与默认值**（共用 `Notice::pluginRows()`），只是挂在联系人区（默认排在「新朋友」之后、真人联系人之前）。前端用 `OwChat.onContactOpen(key, fn)` 接点击。两个区整节的说明见「侧栏插件行」 |

| `notice.items` | 取通知流时（`?action=notices`，**仅登录用户**） | 与 `notice.contacts` **同一个注册函数、同一套校验与默认值**（共用 `Notice::pluginRows()`）：把插件的条目并进**通知流**（与系统通知按时间混排），正文 = `last_text`，另可给 `at` 指定时间、`images` 放缩略图（≤3 张，本站 uploads/ 或 https）。点「查看」走 `OwChat.onNoticeContact(key)`。整节说明见「通知流条目」 |

| `notice.kinds` | 取通知**类型表**时（`Notice::push / kinds / texts / render` 任一处，懒触发，v1.3.171） | `[&$kinds]` —— `$kinds['my_kind'] = '你的 {what} 已处理';` 注册自己的通知类型（kind => 中文模板）。kind 名 `[a-z][a-z0-9_]{0,39}`、模板非空且 ≤300 字，不合格静默丢弃；**同名以核心为准**。前端语言包按整句模板匹配，插件模板同样可翻译。整节说明见「通知类型注册」 |

### `Plugin::current()` —— 「这个回调是谁的」

在 `fire` / `collect` 调起**插件注册的回调**期间返回该插件的目录名（核心代码里调用一律空串）。
用途是给插件登记出来的行补默认展示信息 —— 目前正是 `notice.contacts` 拿它来取插件名与派生头像种子。
写自己的钩子契约时可以照用：`$owner = Plugin::current();`

### collect —— 要返回内容（返回值会被**拼接**）

| 钩子 | 用途 | 返回 |
|---|---|---|
| `chat.toolbar` | 输入工具栏入口按钮（插在 `#owToolFirst`） | HTML |
| `sidebar.rail` | 侧栏一级导航（rail）按钮 | HTML |
| `sidebar.tabs` | 旧名，与 `sidebar.rail` 同一条路径（兼容保留） | HTML |
| `captcha.form` | 人机验证组件（登录 / 注册 / 发信 / 加好友等场景） | HTML |
| `captcha.takeover` | 「人机验证由插件接管了吗」——核心据此决定要不要走内置判定 | `'1'` = 已接管 |
| `captcha.needed` | 这个场景此刻要不要出人机组件（必须与服务端闸门同源，否则会出现「界面摆组件、服务端不查」或反过来） | `'1'` = 要 |
| `mail.available` | `Mailer::deliverable()` 判定「这台站点现在真能发信吗」（v1.3.64） | 返回 `'1'` 表示有可用通道。核心用它决定**要不要要求**邮箱验证码：只看后台开关会出现「要求一个根本发不出来的凭证」—— 没配 SMTP 时用户被永久卡在改不了密码上。⚠️ 降级只适用于还有别的凭证的流程（改密码有当前密码、注册是本人建号）；**找回密码绝不能降级**，那里验证码是唯一凭证，一降级就是「知道邮箱就能改别人密码」的接管漏洞 |
| `sign.fallback` | 签名门禁验签失败后（`index.php` 签名门禁，v1.3.96） | `[['token'=>…, 'action'=>…]]` —— 返回字符串 `'1'` 表示放行该请求。供「无 cookie 场景的备用凭证」：room-embed 的跨站嵌入令牌即用本钩子（令牌绑定公开群，权力等价于该群访客）。⚠️ 只在验签失败时咨询；多插件同时注册时返回值会**拼接**，处理器必须只返回 `'1'` 或 `''`，拼接结果非 `'1'` 一律拒绝（fail-closed）。令牌类凭证务必：只存哈希、可吊销、绑定作用域 |

### 插件之间的钩子（核心不 fire，由插件 fire，供其它插件扩展）

| 钩子 | 谁 fire | 参数 / 约定 |
|---|---|---|
| `user.level.get` / `user.level.set` | user-manager、chat-background 等 | `[&$lv, $uid]` / `[$uid, $lv, &$ok, &$msg]` —— 等级信任插件实现：读等级 / 改等级 |
| `file.uploaded` | attachment-manager | `[$uid, $kind, $size]` —— 附件落盘后触发，等级插件据此计任务进度 |
| `attachment.guard` | attachment-manager | `[&$allow, &$reason, $actor]` —— 附件上传闸门（等级限制） |
| `upload.maxsize` | attachment-manager | `[&$tmp, $actor]` —— 压缩后回写临时文件 |
| `oauth.granted` / `oauth.revoked` | oauth-core | `[$userId, $plugin, $appId, …]` —— 授权建立 / 撤销 |
| `ban.after_add` / `ban.after_del` | ban-manager | `[$banId, $type, $target, $actor]` / `[$banId, $actor]` —— 通知型，供审计、通知类插件扩展 |

> **`cron.minute` 钩子 vs `Plugin::cron()` 声明式任务**（v1.1.13 起）
>
> | | `cron.minute` 钩子 | `Plugin::cron()` |
> |---|---|---|
> | 写法 | `Plugin::on('cron.minute', fn)` | `Plugin::cron('name', $间隔, $处理器, '说明')` |
> | 调度 | 每分钟醒一次，回调**自己判断**是否到期 | 到期自动执行，表里有 `next_run_at` |
> | 后台可见 | ❌ 不可见、不可控 | ✅ 「计划任务」页可看、可启停、可手动触发 |
> | 执行留痕 | ❌ 无 | ✅ 每次写 `cron_logs`（结果 / 耗时 / 报错信息） |
>
> **新任务一律用 `Plugin::cron()`**；`cron.minute` 只为兼容既有插件保留。
>
> 约束：
> - 只能在 `main.php` **顶层**调用（与其它 `Plugin::*` 注册同一位置）。
> - 间隔单位秒，**小于 60 按 60 处理**（防止任务被高频调用打爆）。
> - 任务名同插件内唯一（`UNIQUE(plugin, name)`），重复注册不会插出重复行。
> - 插件停用后任务仍显示在后台，但状态为 `skip`、**不提供启停按钮**（`plugin_active=false`）。
> - 处理器抛异常会被捕获，任务记为 `error` 并写日志，**不影响其它任务、不影响主流程**。
> - 调度由长轮询驱动（每分钟至多一次，多进程有 `flock` 排他锁）；
>   也可挂系统计划任务访问 `?action=cron&token=<后台生成的令牌>` 强制触发。
> **涉及会话的钩子必须在 `session_destroy()` 之前 fire**（上表后两个已由核心保证）。销毁之后 `$_SESSION` 已清空，回调拿不到 uid / 指纹，只能记一条匿名日志。
>
> 在 `core/auth.php` 里 fire 插件钩子必须写 `class_exists('Plugin')` 防御 —— `index.php` 的 require 顺序是 `auth.php` **先于** `plugin.php`，而 CLI 诊断脚本可能只 require 部分 core 文件。

计划任务由长轮询驱动（`Plugin::cronTick()`），也可用系统计划任务调 `?action=cron` 强制触发；回调内自行判断是否到达执行周期，保证可重复运行。

前端扩展点（右键菜单按落点分流，v1.0.69 起）：

- `OwChat.onMsgCtx(fn)`：右键**头像**（针对「人」）时追加菜单项——@、私信、收藏、撤回、禁言等都在这里。
- `OwChat.onMsgContent(fn)`：右键**消息内容**（针对「消息」）时追加菜单项——内置复制 / 引用 / 删除，插件可追加翻译、举报等。
- `OwChat.onQuote(fn)`：构造引用内容时改写（`[quote, msg]`，可改 `quote.nick` / `quote.text`）；服务端另有 `message.quote` 做最终校验。
- `OwChat.onUserAction(fn)`：**按人**发起时的操作菜单（v1.3.24）——「所有成员」区搜索出某个用户后点他，弹出的就是它。与 `onMsgCtx` 的区别是没有消息上下文，参数是 `u = {uid, gid, nickname, kind, role}`。禁言 / 举报都挂在这里。
- `OwChat.onNoticeContact(key, fn)`：侧栏**通知联系人**的点击（v1.3.160）—— 服务端用 `notice.contacts` 登记的那一行被点时调它；v1.3.162 起，**通知流里插件条目（`notice.items`）的「查看」也走这条**。见下面「侧栏插件行」「通知流条目」两节。
- `OwChat.onContactOpen(key, fn)`：侧栏**联系人行**的点击（v1.3.161）—— 服务端用 `contact.rows` 登记的那一行被点时调它。同上一节。

## 前台页面输出

- 钩子回调里的 `echo` 直接进入页面输出；输出前所有用户数据必须经 `Sec::e()` 或前端 `esc()` 转义。
- 不修改核心文件即可扩展页面；确需新的注入点时，先在核心 `pageHead()` / 页面渲染处增加 `Plugin::fire()`，再讨论合入，不要在插件里用输出缓冲 hack。
- **前台资源（v1.0.55 起）**：聊天页与后台页**都由主程序统一引入** `<script src="?action=assets&type=js">`（聊天页位于 `OwChat.init` 之后），插件无需自行注入。多个插件各自用 `page.footer` 注入会导致脚本重复加载、菜单项重复注册——不要这么做。
- 脚本头部做场景判断与幂等保护：

```js
if (!w.OwChat || !OwChat.cfg) return;   // 后台 / 登录页也会加载本脚本
if (w.__haXxxLoaded) return;            // 幂等：防止重复引入时二次注册菜单项
w.__haXxxLoaded = true;
```

### 前端扩展点（OwChat.onMsgCtx）

聊天室消息右键菜单支持插件追加菜单项（v1.0.54 新增）：

```js
OwChat.onMsgCtx(function (items, msg, env) {
    // items.push({ t: '菜单文案', run: function () { ... } });
    // msg：消息对象（uid/gid/nickname/role/mine/recalled 等）
    // env：{ roomId, actor }
});
```

- 回调在菜单渲染前同步执行，`try/catch` 包裹（插件异常不影响基础菜单）。
- 显示判定只做用户体验过滤，**权限必须在服务端路由内重新校验**（参考 `plugin_ban_manager_quick`：管理员 / 房主守卫、不能禁言自己与管理员、房间归属校验）。
- 弹窗复用 `OwChat.openModal()` / `OwChat.closeModal()`，与全站确认框同一样式。参考实现：`plugins/ban-manager/chat.js`。

### 成员搜索的用户操作菜单（OwChat.onUserAction）

右侧「所有成员」区标题右侧有个搜索按钮（v1.3.24），点开可按昵称或用户 ID
搜本群成员；点某个搜索结果的人，弹出的操作菜单由本钩子汇总：

```js
OwChat.onUserAction(function (items, u, env) {
    // items.push({ t: '菜单文案', run: function () { ... } });
    // u：  { uid, gid, nickname, kind: 'user'|'guest', role }
    // env：{ roomId, actor }（与 onMsgCtx 同构）
});
```

核心已经给了「查看个人资料」与「发私信」，插件只需追加自己的动作。

**与 `onMsgCtx` 的区别（选哪个）**：

| 场景 | 用哪个 |
|---|---|
| 右键某条消息的头像（有消息上下文，能拿到 msg_id / ts） | `onMsgCtx` |
| 从成员搜索、成员列表等**按人**发起（没有消息上下文） | `onUserAction` |

**⚠️ `u.role` 必须用上**：禁言判定「房主不能禁言管理员」靠的就是它
（`plugins/ban-manager/chat.js` 的 `BM.canBan`）。若你在自己的场景里把它丢了，
前端会显示禁言项而服务端再拒 —— 用户白点一次。

实现可参照 `plugins/ban-manager/chat.js`：它同时挂在两个钩子上，
`canBan` 只依赖 `{uid, gid, nickname, role}`，所以两个钩子共用同一份实现。

### 侧栏插件行：「通知联系人」与「联系人行」（v1.3.160 / v1.3.161）

插件可以在侧栏的两个区里各加**自己的行**，两处的形状、默认值规则、校验完全一样（共用
`Notice::pluginRows()`），只是挂在不同的区、用不同的钩子与前端 API 接：

| 区 | 服务端钩子 | 默认位置 | 前端接点击 |
|---|---|---|---|
| 通知区（`#owNoticeList`） | `notice.contacts` | 「系统通知」之后 | `OwChat.onNoticeContact(key, fn)` |
| 联系人区（`#owRoomList`） | `contact.rows` | 「新朋友」之后、真人联系人之前 | `OwChat.onContactOpen(key, fn)` |

两个区都是用 `ChatList.render` 渲染的同一套行（「头像 + 昵称 + 副标题 + 未读徽标」），
所以插件行看起来就是该区里多出来的一个联系人。典型用途：插件自己有需要用户点开的东西
（群公告、审核结果、每日签到…），不必再往系统通知里塞。

服务端（`main.php` 顶层）—— 唯一参数是**登记函数**：

```php
Plugin::on('notice.contacts', function (callable $add) {
    $add([
        'key'       => 'announcements',              // 必填：前端的注册键，[A-Za-z0-9_-]{1,32}
        'name'      => '群公告',                      // 可选：不填 → 用插件名（plugin.json 的 name）
        'avatar'    => 'https://…/x.png',            // 可选：不填 → open-peeps 生成头像（按插件 id 派生，固定不变）
        'unread'    => 3,                            // 可选：未读徽标数（前端选中该行时不显示）
        'last_text' => '有新公告：本群今晚维护',        // 可选：行内副标题
    ]);
});
```

前端（插件自己的 `chat.js`）—— 按同一个 `key` 接住点击：

```js
OwChat.onNoticeContact('announcements', function (row) {
    // row = { key, name, avatar, unread, last_text }（服务端补好默认值后的那一行）
    // 想怎么展示都行：弹窗、切主区、跳页面…
    OwChat.openModal('<h3>' + esc(row.name) + '</h3>…');
});
```

联系人区同理，只换钩子名与前端方法（**key 相同**，两边的行互不影响）：

```php
Plugin::on('contact.rows', function (callable $add) {
    $add(['key' => 'checkin', 'last_text' => '今天还没签到']);
});
```
```js
OwChat.onContactOpen('checkin', function (row) { OwChat.openModal('<!-- 签到面板 -->'); });
```

约束与取舍：

- **只有 `key` 必填**，其余全可省 —— 省略时用「插件名 + 该插件派生的固定头像」兜底
  （两个区共用 `Notice::pluginRows()`：校验、去重、截断也只有那一份，不会两边行为漂）。
  同一插件在两个区拿到的默认头像**是同一张**（种子由插件 id 派生）。
  头像与种子固定不变是刻意的：这类行是**长期留痕**，头像每次刷新都变的话用户认不出是谁发的。
- **游客不触发这两个钩子**（游客既没有通知区也没有联系人区），所以不必在回调里判身份。
- 前端没注册处理器时点它会弹一句「插件「xxx」没有注册点击处理」—— 给插件作者留的路标，免得对着一个没反应的入口查半天。
- 一个 `key` 只认第一行（重复登记会被忽略），`name` 截到 20 字、`last_text` 截到 60 字、
  未读徽标超过 99 显示 99（与系统通知同一个徽标组件）。

### 通知流条目（`notice.items`，v1.3.162）

上面两个区解决的是「插件在侧栏有个**入口**」；这一节解决的是「插件的**消息**出现在通知**流**里」——
通知流（点 rail「通知」进入的那片消息区）原本只读 `notices` 表，插件消息没有进来的口子。
`notice.items` 与 `notice.contacts` 是**同一套注册函数、同一套校验与默认值**（共用 `Notice::pluginRows()`），
登记的条目经 `?action=notices` 并进同一条流，**与系统通知按时间混排**（新→旧），渲染成一样的消息气泡：

```php
Plugin::on('notice.items', function (callable $add) {
    // 正文写 last_text（侧栏叫「副标题」，进消息流就是正文）；at 写这条消息的时间；
    // images 放缩略图（≤3 张，站内 uploads/ 或 https）
    $add(['key' => 'content-report', 'last_text' => '财产损失举报：甲 举报 乙（广告）', 'at' => time(),
          'images' => ['uploads/image/1a/1a3f….webp', 'https://…/x.png']]);
});
```

- **正文 = `last_text`**（服务端转成 `body` 下发，前端不走语言包、原样展示），`at` 缺省按 0 处理；
- **缩略图（`images`，v1.3.165）**：最多 3 张，渲染在正文下方、点开站内灯箱看原图（与消息图片同一套轮子，不裁切）。
  站内产物写 `uploads/image/…`（或本站相对/绝对地址，服务端统一归一并**校验文件确实存在**）；
  外链只认 https（http / 协议相对 / `data:` / `javascript:` 一律丢弃 —— 这个字段会进 `<img src>`）；
  非法值静默跳过、**不占名额**（后面合法的照常补满）；
- 条目行显示**自己的昵称/头像**（默认值规则与侧栏行一模一样：没给昵称用插件名、没给头像用按插件 id 派生的 open-peeps），
  没给 `at` 的侧栏行时间列仍是空的 —— 两个区的行**行为不变**，`at` / `images` 只是顺带的增量字段；
- **「查看」按钮**：`key` 非空的条目，正文后附一个「查看」，点击等价于点侧栏那一行 ——
  走 `OwChat.onNoticeContact(key)`（与 `notice.contacts` 共用同一个处理器，插件不用写第二份）；
- **一个 `key` 只认第一行**（与两个区共用同一份去重）：一个插件在通知流里是**一条「最新动态」**。
  要展示多条历史，请把列表放在插件自己的界面里（点「查看」打开的那个），流里只放最新一条的摘要；
- **游客不触发**（游客既没有通知区也没有通知流）；系统通知的已读语义（打开即整批已读）不适用于插件条目 ——
  未读计数由插件自己维护（侧栏行的 `unread` 字段，见上一节）。

### 通知类型注册（`notice.kinds`，v1.3.171）

想发一条自己的**系统通知**（`Notice::push($userId, 'my_kind', ['k' => 'v'])`）不用再让核心在类型表里加行 ——
在自己的 `main.php` 顶层注册模板即可，核心会把它并进「已知类型」：
`Notice::kinds()` / `Notice::texts()` / `Notice::push()` 的有效性判断都认它，
前端通知流与侧栏那一行也按同一套模板渲染（`{xx}` 占位参数在 push 时填入）。

```php
Plugin::on('notice.kinds', function (array &$kinds) {
    $kinds['my_kind'] = '你的 {what} 已处理，可到后台查看。';   // kind => 中文模板
});

Notice::push((int)$adminId, 'my_kind', ['what' => '举报']);   // 模板整个走既有通知链路
```

- 校验：kind 名 `[a-z][a-z0-9_]{0,39}`、模板非空 string 且 ≤300 字，不合格**静默丢弃**
  （与 `push` 对未知类型的态度一致：宁可不发，也不要在界面上出现一个没有语义的孤立条目）；
- **同名以核心为准**：内置类型覆盖不了（通知是账号安全留痕，文案不能被插件改写）；
- **可翻译**：前端语言包按「整句模板」精确匹配 —— 在自己的 `lang.js` 里补一条
  「中文模板 => 英文模板」即可（与内置类型走同一条路，如 lang-en 里 `spam_lexicon_update` 那条）；
- 懒触发：只在 `push / kinds / texts / render` 任一处真正用到时才 fire —— 声明了本钩子的插件
  到那一刻才被载入（fire 自带按需加载；回调每次往新数组里登记，不会重复累积）。

### 侧栏一级导航扩展（sidebar.rail / OwChat.onRail）

聊天页最左侧的一级导航条（`.ow-rail`，竖排图标条）向插件开放入口。
**v1.3.4 起它取代了原先侧栏顶部的横向「消息 / 联系人」标签条**，插件注册入口请用新名字。

服务端 —— 注册一个入口按钮（`Plugin::on('sidebar.rail', …)` 写在 `main.php` 顶层即可，
**不用在 `plugin.json` 里声明钩子** —— 注册清单是核心扫描 `main.php` 时自动记下的，见「钩子」一节；
`ow_icon()` 是核心的线性 SVG 图标函数，**不要用 Emoji 或位图**）。
`<span class="ow-rail-lb">` 是入口文字标签：桌面隐藏，窄屏 rail 沉底变标签栏时显示，建议照写：

```php
Plugin::on('sidebar.rail', function () {
    return '<button class="ow-rail-btn" data-tab="myplugin" type="button" title="我的页面">'
         . ow_icon('gear', 20) . '<span class="ow-rail-lb">我的页面</span></button>';
});
```

前端 —— 为该入口注册面板内容：

```js
OwChat.onRail({
    id: 'myplugin',        // 必须与服务端按钮的 data-tab 一致
    label: '我的页面',
    onShow: function (panel) {   // panel = 侧栏面板容器；可能被重复调用，回调内需自行幂等
        panel.innerHTML = '<div>…</div>';
    }
});
```

- 切到插件入口时核心只切换高亮并调用 `onShow`，不碰插件内容，同时隐藏核心会话列表。
- 面板容器复用 `#owSidePanels`，核心面板与插件面板互斥显示。
- 旧名 `Plugin::on('sidebar.tabs', …)` / `OwChat.onSideTabs` 仍可用（内部同一条路径），新代码请用 rail。

### 输入工具栏扩展（chat.toolbar，v1.3.142 起）

输入框上方那条工具栏（`.ow-toolbar`）向插件开放入口，机制与 `sidebar.rail` 相同：
核心把 `Plugin::collect('chat.toolbar')` 的返回值拼进 `<span id="owToolFirst">`，
**按钮 HTML 由服务端产出，事件仍由各插件自己的 chat.js 绑定**。

```php
// main.php：顶层 Plugin::on('chat.toolbar', …) 即可，不用在 plugin.json 里声明钩子
Plugin::on('chat.toolbar', function (): string {
    return '<button class="ow-icon-btn" id="owBtnMy" type="button" title="我的入口" aria-label="我的入口">'
         . ow_icon('smile', 16) . '</button>';
});
```

```js
// chat.js：只绑已存在的按钮，不要再造 HTML
function mount() {
    var btn = d.getElementById('owBtnMy');
    if (!btn) return;                 // 插件未启用（钩子没注册）：安静退出
    btn.onclick = function (e) { e.stopPropagation(); toggle(); };
}
```

规约（**不要绕过**）：

- 工具栏入口一律注册 `chat.toolbar` 钩子返回按钮 HTML，**禁止直接写锚点 `#owToolFirst` 的
  `innerHTML` / `appendChild`**。这是 v1.3.142 之前的写法留下的坑：共享锚点上「谁后加载谁赢」，
  写成 `box.innerHTML = …` 会把同锚点上别的插件的按钮整个冲掉（表情包按钮被冲掉过一次），
  而且注入时机取决于脚本加载顺序，核心完全看不见。收集式把这件事交给核心统一渲染。
- 按钮 id 用 `owBtn*` 前缀、`class="ow-icon-btn"`、图标统一 16px 并走 `ow_icon()`
  （**不要用 Emoji 或位图**；插件自有字形可以内联 `<svg>`，但那份 path 只能存在于 main.php 一处）。
- `title` 与 `aria-label` 都写上中文原词，语言包按这两个属性翻译（缺 `aria-label` 时读屏只念得出图标）。
- 按钮出现在 `#owToolFirst` 里，位置在核心「表情」按钮**之前**，多个插件之间按启用顺序排列。
- 面板本身仍由插件 JS 创建并自行管理开合（参照 `plugins/gif-stickers/chat.js`、
  `plugins/chat-scripts/chat.js`）—— 本钩子只管按钮。
- 需要 `<input type="file">` 的（如附件上传）可以一并返回，隐藏用 `style="display:none"`。

## 安全要点

- 插件路由第一步做权限自查（见上）；涉及写操作的只接受 POST + 核心签名（前端经 `OwApi` 自动携带，无需额外处理）。
- SQL 一律参数化（`DB::run/one/all/val` 的 `?` 占位），禁止拼接用户输入。
- 文件路径白名单校验，防目录穿越；对外请求设置超时。
- 错误信息简短，不暴露凭据与 SQL。
- 用户消息等展示数据的历史快照语义注意：`messages` 表的 `nickname/avatar/role/title/to_nickname` 是发送时快照，涉及用户资料变更的插件需同步刷新对应快照（参考 `Auth::updateProfile` 的做法）。

## 交付检查

- `php -l plugins/<ID>/main.php` 与 `node --check` 全部通过。
- 插件在「插件管理」中启用后，后台页面、API 路由、钩子输出真实走通一遍；停用后功能整体下线且不报错。
- 路由 action 带正确前缀；需要管理员的接口有权限自查；SQL 全部参数化。
- **样式零硬编码**：`grep -nE '#[0-9A-Fa-f]{3,8}|rgba?\(' plugins/<ID>/` 的结果里，
  除「图像像素 / 数据色板」两类（已就地注释）外不应再有颜色值；深色模式下页面与后台均无亮斑/不可读文字。
- `plugin.json` 的 `version` 已按改动递增，`description` 与能力一致。
- 插件文件命名、JS 全局对象、路由前缀均带插件 ID，无与核心或其他插件冲突的通用名。
- 已核对 `plugins/` 在 `.gitignore` 内，插件不进入 Git 仓库。

## 最小提示（给 AI）

```text
请先阅读根目录 PLUGIN.md 与 core/plugin.php，并参考现有插件 plugins/user-manager/。
插件放在 plugins/<插件ID>/，使用 Plugin::adminPage / route / asset / on 注册能力。
样式只引用核心 --ow-* 变量，不写死颜色（深浅两套主题都要能看）。
按钮防重入：经 OwApi 发起的请求已自动锁按钮；上传/WebAuthn 等异步入口用 OwBusy.run/form 包一层。
需求：<清楚描述功能、入口、权限>
完成后执行 php -l 与 node --check，并在后台启用插件做真实验证。
```
