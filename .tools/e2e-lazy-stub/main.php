<?php
/**
 * 懒加载回归桩（.tools/e2e-lazy-stub → 测试时复制到 plugins/_lazy_probe/）
 *
 * 契约样例：顶层只做 Plugin::* 注册，无输出、无 DB、无文件副作用。
 * 每一项注册对应 plugin-lazy-test 的一条断言：
 *   lazy.probe     钩子      —— fire/collect 按需加载
 *   lazy_probe     路由      —— dispatch 按需加载
 *   lazy_probe_del 敏感路由  —— isSensitive 走清单不加载
 *   lazy-probe     后台页    —— adminPageTitles 免加载 / adminPageFor 定向加载
 *   lazy-probe     前台页    —— frontPageTitles 免加载 / frontPageFor 定向加载
 *   lazy_probe_task 计划任务 —— syncCronTasks 入库
 *   style.css      资源      —— renderAssets 合并
 */
Plugin::on('lazy.probe', function (): string { return 'stub-hook'; });

Plugin::route('lazy_probe', function (array $ctx): array {
    return ['ok' => true, 'data' => 'stub-route'];
});
Plugin::route('lazy_probe_del', function (array $ctx): array {
    return ['ok' => true, 'data' => 'stub-sensitive'];
}, ['sensitive' => true]);

Plugin::adminPage('lazy-probe', '懒加载探针后台页', function (): string {
    return '<h2>stub-admin</h2>';
});

Plugin::page('lazy-probe', '懒加载探针前台页', function (array $actor): string {
    return '<p>stub-front</p>';
});

Plugin::cron('lazy_probe_task', 3600, function (): string {
    return 'stub-cron';
}, '探针计划任务（测试桩，跑完即删）');

Plugin::asset('css', '_lazy_probe/style.css');
