<?php

namespace App\Tests\Unit;

use App\Service\TranscriptChunker;
use PHPUnit\Framework\TestCase;

final class TranscriptChunkerTest extends TestCase
{
    public function testAllWordsSurviveAndChunksStayBounded(): void
    {
        $source = implode("\n", array_map(fn ($i) => "Étape $i : expliquer une méthode concrète et ses limites.", range(1, 300)));
        $chunks = (new TranscriptChunker(512))->split($source);
        self::assertGreaterThan(1, count($chunks));
        foreach ($chunks as $chunk) { self::assertLessThanOrEqual(512, strlen($chunk)); self::assertTrue(mb_check_encoding($chunk)); }
        self::assertSame(preg_replace('/\s+/u', ' ', $source), preg_replace('/\s+/u', ' ', implode(' ', $chunks)));
    }
    public function testLongUnbrokenUnicodeSequenceIsNeverCorrupted(): void
    {
        $source = str_repeat('界🎥', 500);
        $chunks = (new TranscriptChunker(256))->split($source);
        self::assertSame($source, implode('', $chunks));
        foreach ($chunks as $chunk) { self::assertLessThanOrEqual(256, strlen($chunk)); self::assertTrue(mb_check_encoding($chunk)); }
    }
    public function testParagraphsAndEmptyText(): void
    {
        $chunker = new TranscriptChunker(256);
        self::assertSame([], $chunker->split("\n  "));
        self::assertSame(['Un premier paragraphe.'."\n".'Un second.'], $chunker->split("Un premier paragraphe.\n\nUn second."));
    }
}
