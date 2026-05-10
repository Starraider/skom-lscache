<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use Skom\Lscache\Purge\Audit\PurgeLogOutcome;
use Skom\Lscache\Purge\Enum\PurgeSource;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Console\CommandApplication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class StatusCommandTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'skom/lscache',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCsvDataSet(__DIR__ . '/../Configuration/Fixtures/pages.csv');
    }

    private function writeSiteConfiguration(bool $enabled = true, string $siteIdentifier = 'main', string $base = 'https://example.test/', int $defaultTtl = 300): void
    {
        $sitePath = $this->instancePath . '/typo3conf/sites/' . $siteIdentifier;
        if (!is_dir($sitePath)) {
            mkdir($sitePath, 0777, true);
        }

        $enabledStr = $enabled ? 'true' : 'false';

        file_put_contents(
            $sitePath . '/config.yaml',
            <<<YAML
base: '{$base}'
dependencies:
  - skom/lscache/default
languages:
  -
    title: Deutsch
    enabled: true
    languageId: 0
    base: /
    locale: de_DE.utf8
    flag: de
    hreflang: de-DE
    websiteTitle: ''
rootPageId: 1
settings:
  lscache:
    enabled: {$enabledStr}
    defaultTtl: {$defaultTtl}
    loopback:
      rateLimit: 120
websiteTitle: ''
YAML,
        );

        $this->get(SiteFinder::class)->siteConfigurationChanged();
    }

    private function executeCommand(array $input): CommandTester
    {
        $registry = $this->get(\TYPO3\CMS\Core\Console\CommandRegistry::class);
        $command = $registry->getCommandByIdentifier('lscache:status');
        $commandTester = new CommandTester($command);
        $commandTester->execute($input, ['capture_stderr_separately' => true]);

        return $commandTester;
    }

    private function seedPurgeLog(string $siteIdentifier, int $daysAgo): void
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable('tx_skomlscache_purge_log');
        $now = time();

        $connection->insert('tx_skomlscache_purge_log', [
            'pid' => 0,
            'crdate' => $now - ($daysAgo * 86400),
            'site_identifier' => $siteIdentifier,
            'source' => PurgeSource::CLI->value,
            'trigger_ref' => 'status-test',
            'tags' => json_encode(['test_tag'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'urls' => json_encode([], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'tag_count' => 1,
            'outcome' => PurgeLogOutcome::ENQUEUED->value,
            'latency_ms' => 0,
            'request_id' => 'status-' . uniqid(),
        ]);
    }

    #[Test]
    public function commandOutputsStatusForAllSites(): void
    {
        $this->writeSiteConfiguration(true, 'main', 'https://example.test/', 300);
        $this->writeSiteConfiguration(false, 'other', 'https://other.test/', 600);
        
        $this->seedPurgeLog('main', 0);
        $this->seedPurgeLog('main', 2);
        
        $tester = $this->executeCommand([]);

        self::assertSame(0, $tester->getStatusCode());
        
        $output = $tester->getDisplay();
        
        // main site
        self::assertStringContainsString('main', $output);
        self::assertStringContainsString('Yes', $output); // Enabled
        self::assertStringContainsString('300s', $output); // defaultTtl
        
        // other site
        self::assertStringContainsString('other', $output);
        self::assertStringContainsString('No', $output); // Enabled
        self::assertStringContainsString('600s', $output); // defaultTtl
    }

    #[Test]
    public function commandOutputsStatusForSingleSite(): void
    {
        $this->writeSiteConfiguration(true, 'main', 'https://example.test/', 300);
        $this->writeSiteConfiguration(false, 'other', 'https://other.test/', 600);
        
        $tester = $this->executeCommand(['--site' => 'main']);

        self::assertSame(0, $tester->getStatusCode());
        
        $output = $tester->getDisplay();
        
        self::assertStringContainsString('main', $output);
        self::assertStringNotContainsString('other', $output);
    }
}
