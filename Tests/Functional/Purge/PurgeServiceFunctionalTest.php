<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Purge;

use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use Skom\Lscache\Purge\Enum\PurgeSource;
use Skom\Lscache\Purge\PurgeQueue;
use Skom\Lscache\Purge\PurgeService;
use Skom\Lscache\Purge\PurgeServiceInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PurgeServiceFunctionalTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'skom/lscache',
    ];

    #[Test]
    public function serviceCallUsesContainerWiringAndYieldsLogAndDrainableQueuePayloadInTypo3Runtime(): void
    {
        /** @var PurgeQueue $queue */
        $queue = $this->get('skom.lscache.purge_queue');
        /** @var PurgeServiceInterface $service */
        $service = $this->get('skom.lscache.purge_service');
        /** @var PurgeServiceInterface $serviceByInterface */
        $serviceByInterface = $this->get(PurgeServiceInterface::class);

        self::assertSame($service, $serviceByInterface);
        self::assertInstanceOf(EventDispatcherInterface::class, $this->serviceProperty($service, 'eventDispatcher'));

        $service->purgeTags(['main_page_50', 'main_record_tt_content_500'], PurgeSource::API, 'functional:test');

        $payload = $queue->drain();
        $logContent = $this->readSkomLscacheLogContent();

        self::assertSame(['main_page_50', 'main_record_tt_content_500'], $payload->tags());
        self::assertStringContainsString('purge-issued', $logContent);
        self::assertStringContainsString('enqueued', $logContent);
        self::assertStringContainsString('"tagCount":2', $logContent);
    }

    private function readSkomLscacheLogContent(): string
    {
        $logFiles = glob(Environment::getVarPath() . '/log/typo3_skom_lscache_*.log') ?: [];
        self::assertNotSame([], $logFiles);

        $content = '';
        foreach ($logFiles as $logFile) {
            $content .= (string)file_get_contents($logFile);
        }

        return $content;
    }

    private function serviceProperty(PurgeServiceInterface $service, string $property): mixed
    {
        self::assertInstanceOf(PurgeService::class, $service);
        $reflectionProperty = new \ReflectionProperty($service, $property);

        return $reflectionProperty->getValue($service);
    }
}
