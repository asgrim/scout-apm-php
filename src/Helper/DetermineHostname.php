<?php

declare(strict_types=1);

namespace Scoutapm\Helper;

use Scoutapm\Config;
use Scoutapm\Config\ConfigKey;

use function gethostname;

/** @internal */
final class DetermineHostname
{
    /** @todo refactor to an injectable service */
    public static function withConfig(Config $config): string
    {
        return (string) ($config->get(ConfigKey::HOSTNAME) ?? gethostname());
    }
}
