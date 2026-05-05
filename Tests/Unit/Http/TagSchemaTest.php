<?php

declare(strict_types=1);

namespace Skom\Lscache\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Skom\Lscache\Http\RecordTagReference;
use Skom\Lscache\Http\TagSchema;

final class TagSchemaTest extends TestCase
{
    #[Test]
    public function computesDeterministicTagOrderForHappyPath(): void
    {
        $logger = new InMemoryLogger();
        $subject = new TagSchema($logger);

        $tagSet = $subject->computeTagsForPage(
            'main',
            'siteA',
            42,
            [
                new RecordTagReference('tt_content', 7),
                new RecordTagReference('pages', 42),
            ],
        );

        self::assertSame(
            ['siteA_site', 'siteA_page_42', 'siteA_record_tt_content_7', 'siteA_record_pages_42'],
            $tagSet->tags,
        );
        self::assertFalse($tagSet->truncated);
        self::assertSame(4, $tagSet->originalCount);
        self::assertSame([], $logger->records);
    }

    #[Test]
    public function keepsFirstOccurrenceWhenRecordReferencesRepeat(): void
    {
        $subject = new TagSchema(new InMemoryLogger());

        $tagSet = $subject->computeTagsForPage(
            'main',
            'siteA',
            42,
            [
                new RecordTagReference('tt_content', 7),
                new RecordTagReference('tt_content', 7),
                new RecordTagReference('pages', 42),
                new RecordTagReference('tt_content', 7),
            ],
        );

        self::assertSame(
            ['siteA_site', 'siteA_page_42', 'siteA_record_tt_content_7', 'siteA_record_pages_42'],
            $tagSet->tags,
        );
        self::assertFalse($tagSet->truncated);
    }

    #[Test]
    public function returnsOnlySiteAndPageTagForEmptyRecordList(): void
    {
        $subject = new TagSchema(new InMemoryLogger());

        $tagSet = $subject->computeTagsForPage('main', 'siteA', 42, []);

        self::assertSame(['siteA_site', 'siteA_page_42'], $tagSet->tags);
        self::assertSame(2, $tagSet->originalCount);
        self::assertFalse($tagSet->truncated);
    }

    #[Test]
    public function truncatesToSixtyFourTagsAndLogsWarning(): void
    {
        $logger = new InMemoryLogger();
        $subject = new TagSchema($logger);

        $recordReferences = [];
        for ($uid = 1; $uid <= 70; $uid++) {
            $recordReferences[] = new RecordTagReference('tt_content', $uid);
        }

        $tagSet = $subject->computeTagsForPage('main', 'siteA', 42, $recordReferences);

        self::assertCount(64, $tagSet->tags);
        self::assertSame('siteA_site', $tagSet->tags[0]);
        self::assertSame('siteA_page_42', $tagSet->tags[1]);
        self::assertSame('siteA_record_tt_content_62', $tagSet->tags[63]);
        self::assertTrue($tagSet->truncated);
        self::assertSame(72, $tagSet->originalCount);
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('Tag set truncated to 64 entries.', $logger->records[0]['message']);
        self::assertSame(
            ['site' => 'main', 'source' => 'tag-cap', 'tagCount' => 72, 'outcome' => 'truncated'],
            $logger->records[0]['context'],
        );
    }

    #[Test]
    public function isolatesTagsByNamespaceAcrossSites(): void
    {
        $subject = new TagSchema(new InMemoryLogger());
        $recordReferences = [new RecordTagReference('tt_content', 7)];

        $siteA = $subject->computeTagsForPage('siteA', 'siteA', 42, $recordReferences);
        $siteB = $subject->computeTagsForPage('siteB', 'siteB', 42, $recordReferences);

        self::assertSame(['siteA_site', 'siteA_page_42', 'siteA_record_tt_content_7'], $siteA->tags);
        self::assertSame(['siteB_site', 'siteB_page_42', 'siteB_record_tt_content_7'], $siteB->tags);
        self::assertSame([], array_intersect($siteA->tags, $siteB->tags));
    }
}

final class InMemoryLogger extends AbstractLogger
{
    /**
     * @var list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public array $records = [];

    /**
     * @param mixed $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string)$level,
            'message' => (string)$message,
            'context' => $context,
        ];
    }
}
