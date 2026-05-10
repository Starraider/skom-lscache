<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use Skom\Lscache\Purge\Audit\PurgeLogRepository;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Console\CommandApplication;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PurgeCommandTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'skom/lscache',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCsvDataSet(__DIR__ . '/../Configuration/Fixtures/pages.csv');
    }

    private function writeSiteConfiguration(bool $enabled = true, string $secret = 'test-secret', string $siteIdentifier = 'main', string $base = 'https://example.test/'): void
    {
        $sitePath = $this->instancePath . '/typo3conf/sites/' . $siteIdentifier;
        if (!is_dir($sitePath)) {
            mkdir($sitePath, 0777, true);
        }

        $enabledStr = $enabled ? 'true' : 'false';
        $secretLine = $secret !== '' ? "      secret: '{$secret}'" : '';

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
    defaultTtl: 300
    enabled: {$enabledStr}
    loopback:
      rateLimit: 120
{$secretLine}
websiteTitle: ''
YAML,
        );

        $this->get(SiteFinder::class)->siteConfigurationChanged();
    }

    private function executeCommand(array $input): CommandTester
    {
        $registry = $this->get(\TYPO3\CMS\Core\Console\CommandRegistry::class);
        $command = $registry->getCommandByIdentifier('lscache:purge');
        $commandTester = new CommandTester($command);
        $commandTester->execute($input, ['capture_stderr_separately' => true]);

        return $commandTester;
    }

    #[Test]
    public function commandFailsIfSecretIsNotConfigured(): void
    {
        $this->writeSiteConfiguration(true, '');

        $tester = $this->executeCommand(['--site' => 'main', '--all' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('lscache.loopback.secret', $tester->getErrorOutput());
    }

    #[Test]
    public function commandFailsIfSiteLscacheDisabled(): void
    {
        $this->writeSiteConfiguration(false, 'test-secret');

        $tester = $this->executeCommand(['--site' => 'main', '--all' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString("Site 'main' has LSCache disabled", $tester->getErrorOutput());
    }

    #[Test]
    public function commandFailsIfMissingTagsAndAll(): void
    {
        $this->writeSiteConfiguration(true, 'test-secret');

        $tester = $this->executeCommand(['--site' => 'main']);

        self::assertSame(1, $tester->getStatusCode());
    }

    #[Test]
    public function commandOutputsTagAndLeavesAuditLogUnchangedOnDryRun(): void
    {
        $this->writeSiteConfiguration(true, 'test-secret');
        $repo = $this->get(PurgeLogRepository::class);
        $countBefore = $repo->countBySite('main');

        $tester = $this->executeCommand(['--site' => 'main', '--tags' => 'main_page_42', '--dry-run' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('main_page_42', $tester->getDisplay());
        
        $countAfter = $repo->countBySite('main');
        self::assertSame($countBefore, $countAfter);
    }

    #[Test]
    public function commandExits2OnLoopbackFailureAndLogsAuditRow(): void
    {
        $this->writeSiteConfiguration(true, 'test-secret');
        $repo = $this->get(PurgeLogRepository::class);
        $countBefore = $repo->countBySite('main');

        // It should try to contact https://example.test/ and fail
        $tester = $this->executeCommand(['--site' => 'main', '--tags' => 'main_page_42']);

        self::assertSame(2, $tester->getStatusCode());

        $output = $tester->getDisplay() . $tester->getErrorOutput();
        self::assertStringNotContainsString('test-secret', $output, 'NFR-S2: Secret must never be printed');

        $countAfter = $repo->countBySite('main');
        self::assertSame($countBefore + 1, $countAfter, 'An audit log row must be generated before loopback failure.');
    }
}

