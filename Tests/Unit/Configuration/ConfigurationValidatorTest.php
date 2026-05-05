<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Skom\Lscache\Configuration\ConfigurationValidator;
use Skom\Lscache\Configuration\InvalidSiteSettingException;
use TYPO3\CMS\Core\Configuration\Event\SiteConfigurationLoadedEvent;

final class ConfigurationValidatorTest extends TestCase
{
    private ConfigurationValidator $subject;

    protected function setUp(): void
    {
        $this->subject = new ConfigurationValidator();
    }

    #[Test]
    public function acceptsValidSettings(): void
    {
        $event = new SiteConfigurationLoadedEvent('main', [
            'settings' => [
                'lscache' => [
                    'defaultTtl' => 3600,
                    'tagNamespace' => 'main',
                    'mirror' => ['cacheFlushEvent' => false],
                    'loopback' => ['rateLimit' => 120, 'secret' => ''],
                ],
            ],
        ]);

        $this->subject->__invoke($event);

        self::expectNotToPerformAssertions();
    }

    #[Test]
    public function throwsForNonPositiveDefaultTtl(): void
    {
        $this->expectException(InvalidSiteSettingException::class);
        $this->expectExceptionMessage('lscache.defaultTtl');

        $this->subject->__invoke(new SiteConfigurationLoadedEvent('main', [
            'settings' => [
                'lscache' => [
                    'defaultTtl' => -1,
                    'tagNamespace' => 'main',
                    'mirror' => ['cacheFlushEvent' => false],
                    'loopback' => ['rateLimit' => 120, 'secret' => ''],
                ],
            ],
        ]));
    }

    #[Test]
    public function fillsEmptyTagNamespaceWithSiteIdentifier(): void
    {
        $event = new SiteConfigurationLoadedEvent('main', [
            'settings' => [
                'lscache' => [
                    'defaultTtl' => 3600,
                    'tagNamespace' => '',
                    'mirror' => ['cacheFlushEvent' => false],
                    'loopback' => ['rateLimit' => 120, 'secret' => ''],
                ],
            ],
        ]);

        $this->subject->__invoke($event);

        self::assertSame(
            'main',
            $event->getConfiguration()['settings']['lscache']['tagNamespace'] ?? null,
        );
    }

    #[Test]
    public function throwsIfTagNamespaceIsAlreadyUsedByAnotherSite(): void
    {
        $this->subject->__invoke(new SiteConfigurationLoadedEvent('main', [
            'settings' => [
                'lscache' => [
                    'defaultTtl' => 3600,
                    'tagNamespace' => 'shared',
                    'mirror' => ['cacheFlushEvent' => false],
                    'loopback' => ['rateLimit' => 120, 'secret' => ''],
                ],
            ],
        ]));

        $this->expectException(InvalidSiteSettingException::class);
        $this->expectExceptionMessage('lscache.tagNamespace');
        $this->expectExceptionMessage('shared');
        $this->expectExceptionMessage('main');

        $this->subject->__invoke(new SiteConfigurationLoadedEvent('second', [
            'settings' => [
                'lscache' => [
                    'defaultTtl' => 3600,
                    'tagNamespace' => 'shared',
                    'mirror' => ['cacheFlushEvent' => false],
                    'loopback' => ['rateLimit' => 120, 'secret' => ''],
                ],
            ],
        ]));
    }

    #[Test]
    public function throwsIfDerivedSiteIdentifierNamespaceCollidesWithExistingSite(): void
    {
        $this->subject->__invoke(new SiteConfigurationLoadedEvent('main', [
            'settings' => [
                'lscache' => [
                    'defaultTtl' => 3600,
                    'tagNamespace' => '',
                    'mirror' => ['cacheFlushEvent' => false],
                    'loopback' => ['rateLimit' => 120, 'secret' => ''],
                ],
            ],
        ]));

        $this->expectException(InvalidSiteSettingException::class);
        $this->expectExceptionMessage('lscache.tagNamespace');
        $this->expectExceptionMessage('main');

        $this->subject->__invoke(new SiteConfigurationLoadedEvent('second', [
            'settings' => [
                'lscache' => [
                    'defaultTtl' => 3600,
                    'tagNamespace' => 'main',
                    'mirror' => ['cacheFlushEvent' => false],
                    'loopback' => ['rateLimit' => 120, 'secret' => ''],
                ],
            ],
        ]));
    }

    #[Test]
    public function throwsForNonPositiveLoopbackRateLimit(): void
    {
        $this->expectException(InvalidSiteSettingException::class);
        $this->expectExceptionMessage('lscache.loopback.rateLimit');

        $this->subject->__invoke(new SiteConfigurationLoadedEvent('main', [
            'settings' => [
                'lscache' => [
                    'defaultTtl' => 3600,
                    'tagNamespace' => 'main',
                    'mirror' => ['cacheFlushEvent' => false],
                    'loopback' => ['rateLimit' => 0, 'secret' => ''],
                ],
            ],
        ]));
    }

    #[Test]
    public function throwsIfMirrorEnabledWithoutLoopbackSecret(): void
    {
        $this->expectException(InvalidSiteSettingException::class);
        $this->expectExceptionMessage('lscache.loopback.secret');

        $this->subject->__invoke(new SiteConfigurationLoadedEvent('main', [
            'settings' => [
                'lscache' => [
                    'defaultTtl' => 3600,
                    'tagNamespace' => 'main',
                    'mirror' => ['cacheFlushEvent' => true],
                    'loopback' => ['rateLimit' => 120, 'secret' => ''],
                ],
            ],
        ]));
    }
}
