<?php
namespace App\Tests\Unit;
use App\Llm\LlmProviderInterface;
use App\Prompt\TopicSummaryPrompt;
use App\Service\TopicSummaryGenerator;
use App\Service\TranscriptChunker;
use PHPUnit\Framework\TestCase;

final class TopicSummaryTest extends TestCase
{
    public function testOptionalTopicIntentIsIncludedInReportPrompt(): void
    {
        $prompt = (new TopicSummaryPrompt())->report('Sujet', '', '', 'Sources', 'veille');

        self::assertStringContainsString('Intention du sujet : Veille', $prompt);
    }

    public function testOriginalTranscriptionIsUsedBeforePerVideoSummary(): void
    {
        $llm = $this->createMock(LlmProviderInterface::class);
        $prompts = [];
        $llm->method('generate')->willReturnCallback(function (string $prompt) use (&$prompts): string {
            $prompts[] = $prompt;
            return 'Rapport [V1]';
        });
        (new TopicSummaryGenerator(new TranscriptChunker(), $llm, new TopicSummaryPrompt()))->generate('Sujet', '', '', [[
            'id' => 1, 'title' => 'Vidéo', 'transcript' => 'TRANSCRIPTION ORIGINALE', 'summary' => 'ANCIEN RÉSUMÉ',
        ]]);
        self::assertStringContainsString('TRANSCRIPTION ORIGINALE', $prompts[0]);
        self::assertStringNotContainsString('ANCIEN RÉSUMÉ', $prompts[0]);
    }

    public function testLongSourcesAreReducedAndReviewedWithAttribution(): void
    {
        $llm = $this->createMock(LlmProviderInterface::class);
        $notes = 0; $reviews = 0;
        $llm->method('generate')->willReturnCallback(function (string $prompt) use (&$notes, &$reviews): string {
            $data = json_decode(substr($prompt, strrpos($prompt, "\n") + 1), true, flags: JSON_THROW_ON_ERROR);
            if (is_string($data)) {
                ++$notes; self::assertLessThanOrEqual(520, strlen($data));
                preg_match('/\[V\d+\]/', $data, $match);
                return 'Fait sourcé '.$match[0];
            }
            if (isset($data['brouillon'])) { ++$reviews; return 'Rapport relu [V1] [V2]'; }
            return 'Rapport [V1] [V2]';
        });
        $result = (new TopicSummaryGenerator(new TranscriptChunker(512), $llm, new TopicSummaryPrompt()))->generate('Sujet', '', '', [['id' => 1, 'title' => 'A', 'summary' => str_repeat('Sauvegarde hebdomadaire. ', 150)], ['id' => 2, 'title' => 'B', 'summary' => str_repeat('Sauvegarde quotidienne. ', 150)]]);
        self::assertGreaterThan(2, $notes); self::assertSame(1, $reviews);
        self::assertSame('Rapport relu [V1] [V2]', $result);
    }
    public function testForeignCitationRejectsReport(): void
    {
        $llm = $this->createMock(LlmProviderInterface::class); $llm->method('generate')->willReturn('Rapport [V99]');
        $this->expectExceptionMessage('ne fait pas partie');
        (new TopicSummaryGenerator(new TranscriptChunker(), $llm, new TopicSummaryPrompt()))->generate('Sujet', '', '', [['id' => 1, 'title' => 'A', 'summary' => 'Source']]);
    }
    public function testOmittedVideoIsAddedAsSourceAppendix(): void
    {
        $llm = $this->createMock(LlmProviderInterface::class); $llm->method('generate')->willReturn('Rapport [V1]');
        $result = (new TopicSummaryGenerator(new TranscriptChunker(), $llm, new TopicSummaryPrompt()))->generate('Sujet', '', '', [['id' => 1, 'title' => 'A', 'summary' => 'Source'], ['id' => 2, 'title' => 'B', 'summary' => 'Autre source']]);
        self::assertStringContainsString('[V2]', $result);
        self::assertStringContainsString('Autre source', $result);
    }
}
