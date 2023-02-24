<?php

declare(strict_types=1);

namespace ZM\Bootstrap;

use ZM\Kernel;
use ZM\Plugin\PluginManager;

class LoadPlugins implements Bootstrapper
{
    public function bootstrap(Kernel $kernel): void
    {
        // 先遍历下插件目录下是否有这个插件，没有这个插件则不能打包
        $plugin_dir = config('global.plugin.load_dir', SOURCE_ROOT_DIR . '/plugins');
        // 模拟加载一遍插件
        PluginManager::addPluginsFromDir($plugin_dir);
        PluginManager::addPluginsFromComposer();
    }
}
