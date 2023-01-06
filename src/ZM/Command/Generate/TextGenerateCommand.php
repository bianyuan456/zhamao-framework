<?php

declare(strict_types=1);

namespace ZM\Command\Generate;

use Jelix\Version\VersionComparator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use ZM\Command\Command;
use ZM\Utils\ZMRequest;

#[AsCommand(name: 'generate:text', description: '生成一些文本（内部）')]
class TextGenerateCommand extends Command
{
    protected function configure()
    {
        $this->addArgument('name', InputArgument::REQUIRED, '生成的文本内容');
    }

    /**
     * {@inheritDoc}
     */
    protected function handle(): int
    {
        return match ($this->input->getArgument('name')) {
            'class-alias-md' => $this->generateClassAliasDoc(),
            'update-log-md' => $this->generateUpdateLogs(),
            default => static::FAILURE,
        };
    }

    private function generateClassAliasDoc(): int
    {
        $file = file_get_contents(FRAMEWORK_ROOT_DIR . '/src/Globals/global_class_alias.php');
        // 提取class_alias函数的参数
        preg_match_all('/class_alias\((.+?), \'(.+?)\'\);/', $file, $matches);
        $full_maxlen = 0;
        $short_maxlen = 0;
        $line = [];
        foreach ($matches[1] as $k => $v) {
            $full_class = substr($v, 0, -7);
            $short_class = $matches[2][$k];
            $line[] = [$full_class, $short_class];
            $full_maxlen = max($full_maxlen, strlen('`' . $full_class . '`'));
            $short_maxlen = max($short_maxlen, strlen('`' . $short_class . '`'));
        }
        $this->write('| ' . str_pad('全类名', $full_maxlen) . ' | ' . str_pad('别名', $short_maxlen) . ' |');
        $this->write('| ' . str_pad('', $full_maxlen, '-') . ' | ' . str_pad('', $short_maxlen, '-') . ' |');
        foreach ($line as $v) {
            $this->write('| ' . str_pad('`' . $v[0] . '`', $full_maxlen) . ' | ' . str_pad('`' . $v[1] . '`', $short_maxlen) . ' |');
        }
        return static::SUCCESS;
    }

    private function generateUpdateLogs(): int
    {
        date_default_timezone_set(config('global.runtime.timezone', 'UTC'));
        $api = ZMRequest::get('https://api.github.com/repos/zhamao-robot/zhamao-framework/releases', ['User-Agent' => 'ZMFramework']);
        if ($api === false) {
            $this->error('获取更新日志失败');
            return static::FAILURE;
        }
        $json = json_decode($api, true);
        $line = '# 更新日志' . "\r\n\r\n> 本页面由框架自动生成\r\n\r\n";
        foreach ($json as $v) {
            $version = $v['tag_name'];
            if (str_starts_with($version, '2.')) {
                continue;
            }
            $time = '> 更新时间：' . date('Y-m-d', strtotime($v['published_at']));
            $line .= '## v' . $v['tag_name'] . "\r\n\r\n" . $time . "\r\n\r\n" . trim(str_replace("## What's Changed", '', $v['body'])) . "\r\n\r\n";
        }
        $line = str_replace("\r\n", "\n", $line);
        file_put_contents(FRAMEWORK_ROOT_DIR . '/docs/update/v3.md', $line);
        return static::SUCCESS;
    }
}
