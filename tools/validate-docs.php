<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

$projectRoot = dirname(__DIR__);
$autoload = $projectRoot . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

$failures = [];

$requiredFiles = [
    'Documentation/Examples/README.md',
    'Documentation/Examples/quickstart-install.md',
    'Documentation/Examples/site-settings-tuning.md',
    'Documentation/Examples/custom-purge-listener.php',
    'Documentation/Examples/cli-triggered-purge.sh',
    'Documentation/Examples/bypass-rule-recipe.yaml',
];

foreach ($requiredFiles as $file) {
    if (!is_file($projectRoot . '/' . $file)) {
        $failures[] = sprintf('Missing required docs example: %s', $file);
    }
}

runCommand('shellcheck Documentation/Examples/cli-triggered-purge.sh', $projectRoot, $failures);
runCommand('php -l Documentation/Examples/custom-purge-listener.php', $projectRoot, $failures);
runCommand('yamllint -d "{extends: default, rules: {document-start: disable, line-length: disable}}" Documentation/Examples/bypass-rule-recipe.yaml', $projectRoot, $failures);

validateReadmeToc($projectRoot, $failures);
validateReadmeSettingsParity($projectRoot, $failures);

if ($failures !== []) {
    fwrite(STDERR, "Documentation validation failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Documentation validation passed.\n";

function runCommand(string $command, string $cwd, array &$failures): void
{
    $output = [];
    $status = 0;
    exec(sprintf('cd %s && %s 2>&1', escapeshellarg($cwd), $command), $output, $status);

    if ($status !== 0) {
        $failures[] = sprintf("Command failed: %s\n%s", $command, implode("\n", $output));
    }
}

function validateReadmeToc(string $projectRoot, array &$failures): void
{
    $readme = file_get_contents($projectRoot . '/README.md');
    if ($readme === false) {
        $failures[] = 'Cannot read README.md';
        return;
    }

    if (!preg_match('/^## Table of Contents\s*$([\s\S]*?)(?=^##\s|\z)/m', $readme, $tocSectionMatch)) {
        $failures[] = 'README is missing a parseable "Table of Contents" section.';
        return;
    }

    preg_match_all('/^\d+\. \[(.+)]\(#(.+)\)$/m', $tocSectionMatch[1], $matches, PREG_SET_ORDER);
    $actual = array_map(static fn (array $match): string => $match[1], $matches);
    $expected = [
        'Quickstart',
        'Site-settings reference',
        'Event reference',
        'Bypass-rules recipe',
        'CLI purge reference',
        'Coming from other LSCache extensions?',
        'DDEV-Apache local-dev limitation warning',
        'TYPO3 / PHP / license version matrix',
    ];

    if ($actual !== $expected) {
        $failures[] = 'README mandatory TOC does not match the required eight topics in exact order.';
    }
}

function validateReadmeSettingsParity(string $projectRoot, array &$failures): void
{
    $definitionsFile = $projectRoot . '/Configuration/Sets/Lscache/settings.definitions.yaml';
    $readmeFile = $projectRoot . '/README.md';

    if (!class_exists(Yaml::class)) {
        $failures[] = 'Symfony YAML parser is unavailable; run composer install before docs validation.';
        return;
    }

    try {
        $yaml = Yaml::parseFile($definitionsFile);
    } catch (\Throwable $exception) {
        $failures[] = sprintf(
            'Unable to parse settings definitions YAML (%s): %s',
            $definitionsFile,
            $exception->getMessage(),
        );
        return;
    }
    $definitionKeys = array_keys($yaml['settings'] ?? []);
    sort($definitionKeys);

    $readme = file_get_contents($readmeFile);
    if ($readme === false) {
        $failures[] = 'Cannot read README.md';
        return;
    }

    preg_match_all('/^\| `(?<key>lscache\.[^`]+)` \|/m', $readme, $matches);
    $readmeKeys = $matches['key'];
    sort($readmeKeys);

    if ($readmeKeys !== $definitionKeys) {
        $failures[] = sprintf(
            "README site-settings keys differ from settings.definitions.yaml.\nREADME: %s\nDefinitions: %s",
            implode(', ', $readmeKeys),
            implode(', ', $definitionKeys),
        );
    }
}
