<?php
/**
 * profile-card DOM 测试的状态重置（配合 .tools/profile-card-dom-test.js）。
 * 每轮从干净状态起步：900002 无横幅（默认白用例）；900003 固定带 banner-b（他人卡片用例）。
 * 只动隔离副本 .t-pc 的库，绝不碰运行副本。
 */
$db = new PDO('sqlite:' . dirname(__DIR__) . '/.t-pc/data/owlsgo.db');
$db->exec('DELETE FROM plugin_profile_card WHERE user_id=900002');
$db->exec('INSERT OR REPLACE INTO plugin_profile_card (user_id, banner, updated_at)'
    . " VALUES (900003, 'uploads/image/pc/banner-b.png', " . time() . ')');
echo "pc-dom reset ok\n";
