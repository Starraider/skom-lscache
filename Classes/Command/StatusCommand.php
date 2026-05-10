<?php

declare(strict_types=1);

namespace Skom\Lscache\Command;

use Skom\Lscache\Purge\Audit\PurgeLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

#[AsCommand(
    name: 'lscache:status',
    description: 'Displays LiteSpeed Cache status and purge metrics for configured sites.',
)]
class StatusCommand extends Command
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly PurgeLogRepository $purgeLogRepository,
        string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->addOption('site', null, InputOption::VALUE_REQUIRED, 'Limit output to a specific site identifier');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limitSite = $input->getOption('site');

        $sites = $this->siteFinder->getAllSites();
        if ($limitSite !== null) {
            $sites = array_filter($sites, static fn($site) => $site->getIdentifier() === $limitSite);
            if ($sites === []) {
                $output->writeln('<error>Site not found: ' . htmlspecialchars($limitSite) . '</error>');
                return Command::FAILURE;
            }
        }

        $table = new Table($output);
        $table->setHeaders([
            'Site',
            'Enabled',
            'Default TTL',
            'Tag Namespace',
            'Purges (24h)',
            'Purges (7d)',
            'Last Purge'
        ]);

        foreach ($sites as $site) {
            $settings = $site->getSettings();
            $enabled = (bool)$settings->get('lscache.enabled', true);
            $defaultTtl = (int)$settings->get('lscache.defaultTtl', 86400);
            $tagNamespace = (string)$settings->get('lscache.tagNamespace', $site->getIdentifier() . '_');

            $identifier = $site->getIdentifier();
            
            $now = time();
            $last24hCount = $this->purgeLogRepository->countBySiteSince($identifier, (new \DateTimeImmutable())->setTimestamp($now - 86400));
            $last7dCount = $this->purgeLogRepository->countBySiteSince($identifier, (new \DateTimeImmutable())->setTimestamp($now - (86400 * 7)));
            
            $lastPurgeRows = $this->purgeLogRepository->findRecentBySite($identifier, 1, 0);
            $lastPurgeStr = $lastPurgeRows !== [] ? date('Y-m-d H:i:s', $lastPurgeRows[0]->crdate) : 'Never';

            $table->addRow([
                $identifier,
                $enabled ? '<info>Yes</info>' : '<error>No</error>',
                $defaultTtl . 's',
                $tagNamespace,
                (string)$last24hCount,
                (string)$last7dCount,
                $lastPurgeStr
            ]);
        }

        $table->render();

        return Command::SUCCESS;
    }
}
