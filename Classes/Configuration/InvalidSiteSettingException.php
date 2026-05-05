<?php

declare(strict_types=1);

namespace Skom\Lscache\Configuration;

/**
 * Exception raised when LSCache site settings violate extension constraints.
 */
final class InvalidSiteSettingException extends \RuntimeException
{
    /**
     * Creates a formatted exception for a specific invalid site setting.
     *
     * @param string $siteIdentifier TYPO3 site identifier whose settings failed validation.
     * @param string $settingKey Dotted LSCache setting key that is invalid.
     * @param string $message Human-readable validation failure detail.
     */
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
