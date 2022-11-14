<?php

declare(strict_types=1);

namespace ZM\Bootstrap;

class SetInternalTimezone
{
    public function bootstrap(array $config): void
    {
        date_default_timezone_set(config('global.runtime.timezone', 'UTC'));
    }
}
