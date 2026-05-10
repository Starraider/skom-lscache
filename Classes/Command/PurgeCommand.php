<?php

declare(strict_types=1);

namespace Skom\Lscache\Command;

use Skom\Lscache\Purge\LoopbackDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

#[AsCommand(name: 'lscache:purge')]
class PurgeCommand extends Command
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly LoopbackDispatcher $loopbackDispatcher,
        private readonly \Skom\Lscache\Purge\PurgeServiceInterface $purgeService,
        private readonly \Skom\Lscache\Purge\Audit\PurgeLogRepository $purgeLogRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Purges LiteSpeed Cache tags or entire sites via loopback request.')
            ->addOption('site', null, InputOption::VALUE_REQUIRED, 'Site identifier to purge (required)')
            ->addOption('tags', null, InputOption::VALUE_OPTIONAL, 'Comma-separated list of tags to purge')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Purge all tags for the site')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Display what would be purged without actually executing')
            ->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Output format (json or text)', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errOutput = $output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $siteIdentifier = $input->getOption('site');
        if (!is_string($siteIdentifier) || $siteIdentifier === '') {
            $errOutput->writeln('<error>The "--site" option is required.</error>');
            return Command::FAILURE;
        }

        $tagsInput = $input->getOption('tags');
        $isAll = (bool)$input->getOption('all');
        
        if ($tagsInput === null && !$isAll) {
            $errOutput->writeln('<error>You must provide either "--tags" or "--all".</error>');
            return Command::FAILURE;
        }

        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (\TYPO3\CMS\Core\Exception\SiteNotFoundException $e) {
            $errOutput->writeln(sprintf('<error>Site "%s" not found.</error>', $siteIdentifier));
            return Command::FAILURE;
        }

        $settings = $site->getConfiguration()['settings']['lscache'] ?? [];
        
        if (!($settings['enabled'] ?? false)) {
            $errOutput->writeln(sprintf('<error>Site \'%s\' has LSCache disabled.</error>', $siteIdentifier));
            return Command::FAILURE;
        }

        $secret = (string)($settings['loopback']['secret'] ?? '');
        if ($secret === '') {
            $errOutput->writeln('<error>Site missing lscache.loopback.secret. Cannot securely dispatch CLI purge.</error>');
            return Command::FAILURE;
        }

        $tags = [];
        $fullSiteFlushes = [];
        if ($isAll) {
            $fullSiteFlushes[] = $siteIdentifier;
        } else {
            $tagsArray = array_map('trim', explode(',', (string)$tagsInput));
            $tagsArray = array_filter($tagsArray, fn($tag) => $tag !== '');
            if (empty($tagsArray)) {
                $errOutput->writeln('<error>No valid tags provided.</error>');
                return Command::FAILURE;
            }
            $tags = array_values($tagsArray);
        }

        $payloadArray = [
            'tags' => $tags,
            'urls' => [],
            'fullSiteFlushes' => $fullSiteFlushes,
        ];
        
        $payloadObj = new \Skom\Lscache\Purge\PurgePayload($tags, [], $fullSiteFlushes);

        $isDryRun = (bool)$input->getOption('dry-run');

        if ($isDryRun) {
            if ($input->getOption('format') === 'json') {
                $output->writeln(json_encode(['dry_run' => true, 'payload' => $payloadArray], JSON_THROW_ON_ERROR));
            } else {
                $output->writeln('<info>Dry-run mode. Would send payload:</info>');
                $output->writeln(json_encode($payloadArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
            return Command::SUCCESS;
        }

        $argvHash = md5(serialize($input->getArguments()) . serialize($input->getOptions()));
        $triggerRef = 'cli:lscache:purge:' . $argvHash;

        if ($isAll) {
            $this->purgeService->purgeFullSite($siteIdentifier, \Skom\Lscache\Purge\Enum\PurgeSource::CLI, $triggerRef);
        } else {
            $this->purgeService->purgeTags($tags, \Skom\Lscache\Purge\Enum\PurgeSource::CLI, $triggerRef, $siteIdentifier);
        }

        $requestId = $this->purgeLogRepository->findNewestRequestIdByTrigger($siteIdentifier, \Skom\Lscache\Purge\Enum\PurgeSource::CLI, $triggerRef);
        if ($requestId === null) {
            $errOutput->writeln('<error>Failed to retrieve requestId from audit log.</error>');
            return Command::FAILURE;
        }

        $success = $this->loopbackDispatcher->dispatch($payloadObj, $site, $requestId);

        if (!$success) {
            $errOutput->writeln('<error>Loopback dispatch failed. Check TYPO3 log for details.</error>');
            return 2;
        }

        if ($input->getOption('format') === 'json') {
            $output->writeln(json_encode(['success' => true, 'payload' => $payloadArray], JSON_THROW_ON_ERROR));
        } else {
            $output->writeln('<info>Purge dispatch successful.</info>');
        }

        return Command::SUCCESS;
    }
}
