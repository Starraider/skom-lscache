<?php

declare(strict_types=1);

namespace Skom\Lscache\Configuration;

final class InvalidSiteSettingException extends \RuntimeException
{
    public static function forConstraint(string $siteIdentifier, string $settingKey, string $message): self
    {
        return new self(
            sprintf(
                'Invalid LSCache site setting "%s" for site "%s": %s',
                $settingKey,
                $siteIdentifier,
                $message,
            ),
            1746450001,
        );
    }
}
