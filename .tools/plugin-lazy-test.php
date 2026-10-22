<?php
/**
 * 插件「元数据缓存 + 按需加载」回归自检（v1.5.24）
 *
 *   php .tools/plugin-lazy-test.php [站点根]      默认 D:/project/owlsgo
 *
 * 钉的是 v1.5.24 的三件事本身：
 *   ① 暖缓存下 bare init **零插件 main.php** 进入请求，且不重写缓存文件
 *      （元数据缓存真的在生效，不是每次请求都重建）；
 *   ② 标题表 / 路由表 / 敏感标记全部走清单免加载；adminPageFor / frontPageFor /
 *      fire / dispatch 只加载**拥有目标的那一个**插件（不是全部页面型插件）；
 *   ③ 清单权威覆盖：插件改版删掉某个钩子后，签名变化 → 只重载该插件 →
 *      清单里该钩子被覆盖掉（旧实现 array_merge 只增不减，会永留清单、
 *      每次 fire 都白加载一次——正是要消灭的 include 开销）。
 * 另覆盖 inspect() 的两难境地：已加载插件回报真实计数且不洗空清单，
 * 未加载插件正常探测。
 *
 * ⚠️ 每阶段都是独立子进程（模拟独立请求）：同进程内 require_once 不会重跑
 *    main.php，注册回不来（core/plugin.php 防重复声明那道闸的副作用）。
 * ⚠️ 用仓库副本的库（data/ 已 gitignore）：临时启用桩插件 _lazy_probe，
 *    结束删行、删 cron_tasks、删缓存、删桩目录，恢复到跑之前的状态。
 */
define('OWLSGO_VERSION', 'test');

$root = 'D:/project/owlsgo';
$phase = '';
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--phase=') === 0) $phase = substr($a, 8);
    elseif ($a[0] !== '-') $root = rtrim($a, '/');
}
$STUB = '_lazy_probe';
$CF    = $root . '/data/cache/plugins.json';

/* ================= 父进程：搭桩 → 逐阶段子进程 → 收尾还原 ================= */
if ($phase === '') {
    $CFG = require $root . '/core/config.php';
    foreach (['db', 'security', 'mail', 'auth', 'plugin', 'chat', 'notice', 'upload'] as $f) {
        require $root . '/core/' . $f . '.php';
    }
    DB::init($CFG);
    DB::migrate();

    $run = function (string $p) use ($root): int {
        // ⚠️ -d opcache.enable_cli=0：phpStudy 的 php.ini 开了 opcache.enable_cli=On 且
        // revalidate_freq=60 —— 父进程跨阶段存活，共享内存里插件 main.php 的编译结果
        // 60 秒内不重校验，子进程 require_once 会拿到旧编译码（症状：签名检测到变更、
        // 也走了 loadPlugin，执行的却是旧代码 → 断言时通时不通）。测试专用，不影响生产。
        $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=0 -d display_errors=1 -d error_reporting=E_ALL '
            . escapeshellarg(__DIR__ . '/plugin-lazy-test.php') . ' ' . escapeshellarg($root)
            . ' --phase=' . $p . ' 2>&1');
        echo $out;
        return substr_count($out, 'FAIL ');
    };

    $fail = 0;
    try {
        // ① 复制桩插件（.tools/e2e-lazy-stub → plugins/_lazy_probe）并启用
        $dest = $root . '/plugins/' . $STUB;
        rmrf($dest);
        @mkdir($dest, 0775, true);
        foreach (['plugin.json', 'main.php', 'style.css'] as $f) {
            copy(__DIR__ . '/e2e-lazy-stub/' . $f, $dest . '/' . $f);
        }
        DB::upsert('plugins', ['name' => $STUB, 'enabled' => 1, 'config' => ''], ['name']);

        // ② 冷启动（删清单缓存）→ 全量重建
        @unlink($CF);
        foreach (glob($root . '/data/cache/assets-*.json') ?: [] as $f) @unlink($f);
        echo "== ① 冷启动：删缓存后首请求全量重建 ==\n";
        $fail += $run('cold');

        echo "\n== ② 暖缓存：bare init 零加载、零重写 ==\n";
        $fail += $run('warm');

        echo "\n== ③ 标题表免加载 + 定向加载 ==\n";
        $fail += $run('titles');

        // ④ 桩插件「改版」：删掉 lazy.probe 钩子与后台页（内容就地改写 → mtime 变 → 签名失效）
        echo "\n== ④ 权威覆盖：插件删钩子后清单同步失效 ==\n";
        $variant = (string)file_get_contents(__DIR__ . '/e2e-lazy-stub/main.php');
        $variant = str_replace(
            "Plugin::on('lazy.probe', function (): string { return 'stub-hook'; });",
            "Plugin::on('lazy.probe2', function (): string { return 'stub-hook2'; });",
            $variant
        );
        $variant = preg_replace(
            "/Plugin::adminPage\('lazy-probe'.*?\}\);\n/s",
            '',
            $variant
        );
        if ($variant === null || strpos($variant, 'lazy.probe2') === false || strpos($variant, 'Plugin::adminPage(') !== false) {
            echo "FAIL 变体 main.php 生成异常（正则没匹配上？）\n";
            $fail++;
        } else {
            file_put_contents($dest . '/main.php', $variant);
            @touch($dest . '/main.php', time() - 7200);   // 与冷启动的写入时间必然不同（秒级精度）
            $fail += $run('authority');

            // ⑤ 还原桩插件 → 钩子回到清单（反证失效机制双向有效）
            // ⚠️ filemtime 是秒级精度：变体改写与还原拷贝常落在同一秒，签名不变就不重载。
            //    显式把 mtime 拨到一小时前，保证与变体的写入时间必然不同（测试专属，不碰核心）。
            echo "\n== ⑤ 还原：钩子回到清单 ==\n";
            copy(__DIR__ . '/e2e-lazy-stub/main.php', $dest . '/main.php');
            @touch($dest . '/main.php', time() - 3600);
            $fail += $run('restore');
        }

        echo "\n== ⑥ inspect：已加载不洗空 / 未加载正常探测 ==\n";
        $fail += $run('inspect');

        echo "\n== ⑦ 资源合并：清单驱动 + 合并缓存 ==\n";
        $fail += $run('assets');
    } finally {
        // 收尾还原：删桩目录、删 DB 行、删 cron_tasks、删清单与合并缓存
        rmrf($root . '/plugins/' . $STUB);
        DB::run('DELETE FROM plugins WHERE name=?', [$STUB]);
        DB::run('DELETE FROM cron_tasks WHERE plugin=?', [$STUB]);
        @unlink($CF);
        foreach (glob($root . '/data/cache/assets-*.json') ?: [] as $f) @unlink($f);
        echo "\n[收尾] 桩插件 / DB 行 / cron_tasks / 缓存已清理\n";
    }

    echo "\n" . ($fail === 0 ? "ALL PASS\n" : "$fail FAILED\n");
    exit($fail === 0 ? 0 : 1);
}

/* ================= 子进程共用：载入核心（与 index.php 启动路径一致） ================= */
$CFG = require $root . '/core/config.php';
foreach (['db', 'security', 'mail', 'auth', 'plugin', 'chat', 'notice', 'upload'] as $f) {
    require $root . '/core/' . $f . '.php';
}
DB::init($CFG);
DB::migrate();   // 与 index.php 一致：仓库库可能停在旧列名（interval），syncCronTasks 会炸

$fail = 0;
$check = function (string $name, $got, $want) use (&$fail) {
    $okv = $got === $want;
    if (!$okv) $fail++;
    echo ($okv ? 'PASS ' : 'FAIL ') . str_pad($name, 52, ' ')
       . ' got=' . var_export($got, true) . ' want=' . var_export($want, true) . "\n";
};

/** 本请求已 include 的插件 main.php（目录名列表） */
$loadedMains = function (): array {
    $out = [];
    foreach (get_included_files() as $f) {
        $n = str_replace('\\', '/', $f);
        if (preg_match('#/plugins/([^/]+)/main\.php$#', $n, $m)) $out[] = $m[1];
    }
    sort($out);
    return $out;
};

switch ($phase) {

    case 'cold':
        Plugin::init($root . '/plugins', $root . '/data/cache');
        $check('冷启动全量加载 5 个启用插件', $loadedMains(),
            ['_lazy_probe', 'attachment-manager', 'chat-scripts', 'gif-stickers', 'passkey']);
        $c = json_decode((string)file_get_contents($CF), true);
        $check('缓存版本号 v=2', $c['v'] ?? null, 2);
        $sp = $c['plugins']['_lazy_probe'] ?? [];
        $check('清单 pages 是 slug=>标题映射', $sp['pages'] ?? null, ['lazy-probe' => '懒加载探针后台页']);
        $check('清单 fpages 是 slug=>标题映射', $sp['fpages'] ?? null, ['lazy-probe' => '懒加载探针前台页']);
        $check('清单 hooks 含懒钩子', in_array('lazy.probe', $sp['hooks'] ?? [], true), true);
        $check('清单 sensitive 含敏感路由', in_array('lazy_probe_del', $sp['sensitive'] ?? [], true), true);
        $check('cron 任务已入库', (int)DB::val('SELECT COUNT(*) FROM cron_tasks WHERE plugin=? AND name=?', ['_lazy_probe', 'lazy_probe_task']), 1);
        $check('isSensitive 只读清单', Plugin::isSensitive('lazy_probe_del'), true);
        $check('enabledPlugins 含桩', in_array('_lazy_probe', Plugin::enabledPlugins(), true), true);
        break;

    case 'warm':
        // 第一次 init：把「从没加载过的插件」元数据并进缓存（一次性收敛，
        // 旧实现这里每次请求都要重读它们的 plugin.json 并重写缓存文件）。
        Plugin::init($root . '/plugins', $root . '/data/cache');
        $check('收敛请求仍零插件加载', $loadedMains(), []);
        clearstatcache(true, $CF);
        $m0 = (int)@filemtime($CF);
        $nDirs = 0;
        foreach (glob($root . '/plugins/*', GLOB_ONLYDIR) ?: [] as $d) {
            if (preg_match('/^[a-zA-Z0-9_-]+$/', basename($d))) $nDirs++;
        }
        $c = json_decode((string)file_get_contents($CF), true);
        $check('元数据全量入缓存（含未启用插件）', count($c['plugins'] ?? []), $nDirs);

        // 第二次 init（同一进程内重置 = 下一个请求）：应完全命中缓存
        $t0 = microtime(true);
        Plugin::init($root . '/plugins', $root . '/data/cache');
        $ms = (microtime(true) - $t0) * 1000;
        $check('稳态请求零插件加载', $loadedMains(), []);
        clearstatcache(true, $CF);
        $check('稳态请求不重写缓存文件', (int)@filemtime($CF), $m0);
        printf("init 耗时 %.2fms（暖缓存，35+ 插件）\n", $ms);
        break;

    case 'titles':
        Plugin::init($root . '/plugins', $root . '/data/cache');
        // 本阶段会真实加载桩插件（adminPageFor/collect/dispatch）——
        // 注册集与缓存一致时一次缓存都不该重写（v1.5.24 修过「缺 meta 键导致
        // 每个加载插件的请求都重写一遍 46KB 缓存」的回归，这条断言钉住它）。
        $cacheMd5 = md5_file($CF);
        $before = $loadedMains();
        $at = Plugin::adminPageTitles();
        $ft = Plugin::frontPageTitles();
        $check('标题表查询零加载', $loadedMains(), $before);
        $check('后台页标题表含桩', $at['lazy-probe'] ?? null, '懒加载探针后台页');
        $check('后台页标题表含四个真实插件', count($at), 5);
        $check('前台页标题表含桩', $ft['lazy-probe'] ?? null, '懒加载探针前台页');

        $pg = Plugin::adminPageFor('lazy-probe');
        $check('adminPageFor 只加载桩一个', $loadedMains(), ['_lazy_probe']);
        $check('adminPageFor 拿到标题', $pg['title'] ?? null, '懒加载探针后台页');
        $check('adminPageFor 拿到 fn', is_callable($pg['fn'] ?? null), true);

        $n = count($loadedMains());
        $check('adminPageFor 未知 slug 返回 null', Plugin::adminPageFor('nope-404'), null);
        $check('adminPageFor 未知 slug 不加载', count($loadedMains()), $n);

        $fp = Plugin::frontPageFor('lazy-probe');
        $check('frontPageFor 拿到标题', $fp['title'] ?? null, '懒加载探针前台页');
        $check('frontPageFor 拿到 fn', is_callable($fp['fn'] ?? null), true);

        /* v1.5.32：资源 scope 分包 —— 用真实插件（attachment-manager 有 chat.js + admin.js
           两个文件、scope 分别是 front / admin）验证过滤，不依赖桩插件的资源声明。 */
        $frontJs = Plugin::renderAssets('js', '', '', 'front');
        $adminJs = Plugin::renderAssets('js', '', '', 'admin');
        $allJs   = Plugin::renderAssets('js');
        $check('front 包含 chat.js', strpos($frontJs, 'plugin: attachment-manager/chat.js') !== false, true);
        $check('front 不含 admin.js', strpos($frontJs, 'plugin: attachment-manager/admin.js'), false);
        $check('admin 包含 admin.js', strpos($adminJs, 'plugin: attachment-manager/admin.js') !== false, true);
        $check('admin 不含 chat.js', strpos($adminJs, 'plugin: attachment-manager/chat.js'), false);
        $check('不传 scope 仍是全量（老调用方兼容）',
            strpos($allJs, 'plugin: attachment-manager/admin.js') !== false, true);
        $cf = json_decode((string)file_get_contents($CF), true);
        $check('清单记录了 scope 版本 scv=1', (int)($cf['plugins']['_lazy_probe']['scv'] ?? 0), 1);

        $check('collect 懒钩子只按需加载', Plugin::collect('lazy.probe'), 'stub-hook');
        $check('dispatch 懒路由', Plugin::dispatch('lazy_probe', ['actor' => [], 'post' => [], 'files' => []]),
            ['ok' => true, 'data' => 'stub-route']);
        $check('敏感路由走清单免加载', Plugin::isSensitive('lazy_probe_del'), true);
        $check('整阶段零缓存重写', md5_file($CF), $cacheMd5);
        break;

    case 'authority':
        Plugin::init($root . '/plugins', $root . '/data/cache');
        $check('main.php 变更只重载桩一个', $loadedMains(), ['_lazy_probe']);
        $c = json_decode((string)file_get_contents($CF), true);
        $hooks = $c['plugins']['_lazy_probe']['hooks'] ?? [];
        $check('被删的钩子离开清单（权威覆盖）', in_array('lazy.probe', $hooks, true), false);
        $check('新钩子进入清单', in_array('lazy.probe2', $hooks, true), true);
        $check('后台页标题表已移除', isset(Plugin::adminPageTitles()['lazy-probe']), false);
        $check('前台页标题表仍在', isset(Plugin::frontPageTitles()['lazy-probe']), true);
        $check('fire 被删钩子无处理器', Plugin::collect('lazy.probe'), '');
        break;

    case 'restore':
        Plugin::init($root . '/plugins', $root . '/data/cache');
        $check('还原后只重载桩一个', $loadedMains(), ['_lazy_probe']);
        $c = json_decode((string)file_get_contents($CF), true);
        $check('还原后钩子回到清单', in_array('lazy.probe', $c['plugins']['_lazy_probe']['hooks'] ?? [], true), true);
        $check('还原后标题表回来', isset(Plugin::adminPageTitles()['lazy-probe']), true);
        $check('还原后 collect 有值', Plugin::collect('lazy.probe'), 'stub-hook');
        break;

    case 'inspect':
        Plugin::init($root . '/plugins', $root . '/data/cache');
        $check('先按需加载桩', Plugin::collect('lazy.probe'), 'stub-hook');
        $st = Plugin::inspect('_lazy_probe');
        $check('inspect 已加载插件 hooks', $st['hooks'], 1);
        $check('inspect 已加载插件 routes', $st['routes'], 2);
        $check('inspect 已加载插件 pages', $st['pages'], 2);
        $check('inspect 已加载插件 crons', $st['crons'], 1);
        $check('inspect 回滚后钩子仍可触发', Plugin::collect('lazy.probe'), 'stub-hook');
        $check('inspect 不洗空标题表', isset(Plugin::adminPageTitles()['lazy-probe']), true);
        $check('inspect 后定向取页仍在', Plugin::adminPageFor('lazy-probe') !== null, true);
        $st2 = Plugin::inspect('gif-stickers');
        $check('inspect 未加载插件 hooks>0', $st2['hooks'] > 0, true);
        $check('inspect 未加载插件不碰桩', Plugin::collect('lazy.probe'), 'stub-hook');
        break;

    case 'assets':
        Plugin::init($root . '/plugins', $root . '/data/cache');
        $css = Plugin::renderAssets('css');
        $check('合并 CSS 含桩样式', strpos($css, '.ow-lazy-probe-stub') !== false, true);
        $check('二次合并结果一致', Plugin::renderAssets('css'), $css);
        $only = Plugin::renderAssets('css', '_lazy_probe');
        $check('单插件切片含桩样式', strpos($only, '.ow-lazy-probe-stub') !== false, true);
        $check('单插件切片短于总包', strlen($only) < strlen($css), true);
        break;

    default:
        echo "unknown phase: $phase\n";
        exit(1);
}

echo ($fail === 0 ? "PASS $phase\n" : "FAIL $phase ($fail)\n");
exit($fail === 0 ? 0 : 1);

/* ================= 工具 ================= */
function rmrf(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (glob($dir . '/*') ?: [] as $f) {
        is_dir($f) ? rmrf($f) : @unlink($f);
    }
    @rmdir($dir);
}
