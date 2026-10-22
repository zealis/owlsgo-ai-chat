<?php
/**
 * color-schemes · 色系切换（v1.3.51 起从核心剥离）
 *
 * 无数据表、无路由、无后台页 —— 全部状态在浏览器 localStorage（owl_scheme / owl_schemes_custom），
 * 与核心的 owl_theme 同档「本机外观偏好」。
 *
 * 停用本插件即彻底回到核心默认：head 里的引擎不再注入 → 没有任何行内 CSS 变量 →
 * 界面就是 index.css 的样式表值（经典蓝）；设置弹窗里也不再出现「色系」段。
 */
declare(strict_types=1);

// 两个 JS 都要注册：
//  · schemes.js 走 body 末尾的合并总包 —— 它要包装 OwChat.openSettings / applyTheme，
//    必须等 chat.js 定义完那些方法之后才能跑。
//  · boot.js 除了也在总包里，还要在 <head> 里单独先来一份（见下面的 page.head）；
//    它开头有 `if (window.OwTheme) return;` 守卫，第二次执行是空操作。
Plugin::asset('js', 'color-schemes/boot.js');
Plugin::asset('js', 'color-schemes/schemes.js');
Plugin::asset('css', 'color-schemes/style.css');

/**
 * 引擎与样式必须在首帧绘制前就位，否则每次刷新都会先按经典蓝画一帧再跳色。
 * page.head 排在样式表之后，但注入的是**阻塞**资源，仍在首次渲染前完成；
 * 这里用 plugin= + file= 只取本插件那两个文件，不重复拉别人的合并结果。
 */
Plugin::on('page.head', function () {
    echo '<script src="?action=assets&type=js&plugin=color-schemes&file=boot.js&v=' . OWLSGO_ASSET_VERSION . '"></script>'
       . '<link rel="stylesheet" href="?action=assets&type=css&plugin=color-schemes&file=style.css&v=' . OWLSGO_ASSET_VERSION . '">';
});
