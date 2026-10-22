<?php
/**
 * 统一缓存刷新：数据库/文件数据改完后，把派生缓存清掉，让下次请求按新数据重建。
 *
 * 文件缓存（data/cache）：
 *   · plugins.json    —— 插件注册清单（钩子/路由/资源/计划任务）
 *   · assets-*.json   —— 插件 JS/CSS 合并结果
 *   · lexicon.bin     —— 敏感词库编译缓存
 *
 * 外部缓存（Redis 等）通过 `cache.clear` 钩子交给插件自己清；核心不直连任何外部缓存。
 *
 * 用法：
 *   Cache::clear('plugin');   // 插件启停/安装/升级后
 *   Cache::clear('lexicon');  // 词库文件或停用名单变更后
 *   Cache::clear('all');      // 后台保存设置 / 手动清缓存
 *
 * 与事务配合：写在 DB::afterCommit(fn) 里，保证只有事务真提交后才清缓存；
 * 回滚时不会误删缓存（否则并发请求可能用旧数据把缓存又填回去）。
 */
class Cache
{
    /** 可清理区域 => data/cache 下的文件名/通配 */
    private const AREAS = [
        'plugin'   => ['plugins.json', 'assets-*.json'],
        'lexicon'  => ['lexicon.bin'],
        // 设置项可能影响插件注册/资源/词库停用名单，文件缓存全清；
        // 但钩子区传 settings（Redis 等外部缓存不应因核心设置保存被清空）
        'settings' => ['plugins.json', 'assets-*.json', 'lexicon.bin'],
        'all'      => ['plugins.json', 'assets-*.json', 'lexicon.bin'],
    ];

    public static function dir(): string
    {
        return dirname(__DIR__) . '/data/cache';
    }

    /**
     * 清理指定区域的缓存文件，并广播 cache.clear 给插件处理外部缓存。
     * @return array 实际删除的文件名
     */
    public static function clear(string $area = 'all'): array
    {
        $patterns = self::AREAS[$area] ?? [];
        $removed = [];
        $dir = self::dir();
        foreach ($patterns as $pat) {
            foreach (glob($dir . '/' . $pat) ?: [] as $f) {
                if (is_file($f) && @unlink($f)) $removed[] = basename($f);
            }
        }
        // 原子替换留下的临时文件（改名失败或进程被杀）：不是缓存本体，按残留清掉。
        // 只动 60 秒以前的 —— 正常路径从写入到 rename 只有几毫秒，新落的一定是并发请求正在写的。
        $cut = time() - 60;
        foreach (glob($dir . '/*.tmp') ?: [] as $f) {
            if (is_file($f) && (int)@filemtime($f) < $cut && @unlink($f)) $removed[] = basename($f);
        }
        clearstatcache(true);

        // 插件可监听并清自己的外部缓存（如 redis 的键、其它插件私有文件）。
        // 钩子异常不影响文件缓存已经刷新的事实。
        if (class_exists('Plugin')) {
            try {
                Plugin::fire('cache.clear', [$area, $removed]);
            } catch (Throwable $e) {
                // 外部缓存清理失败：下次请求/手动清缓存可重试，不阻断主流程
            }
        }
        return $removed;
    }
}
