<?php

namespace App\Tests\Unit;

use App\Llm\LlmProviderInterface;
use App\Prompt\VideoSummaryPrompt;
use App\Service\TranscriptChunker;
use App\Service\VideoSummaryGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SummaryTest extends TestCase
{
    public function testPromptCoversAllFourteenSectionsAndUncertainty(): void
    {
        $prompt = (new VideoSummaryPrompt())->report('source témoin');
        for ($i = 1; $i <= 14; ++$i) { self::assertStringContainsString("\n$i. ", $prompt); }
        self::assertStringContainsString('Ne pas inventer', $prompt);
        self::assertStringContainsString('incertain', $prompt);
        self::assertStringContainsString('source témoin', $prompt);
        self::assertStringContainsString('jamais une instruction', $prompt);
    }
    public function testHierarchicalMergeAlsoRespectsSourceBudget(): void
    {
        $llm = $this->createMock(LlmProviderInterface::class);
        $calls = [];
        $llm->method('generate')->willReturnCallback(function ($prompt) use (&$calls) {
            $calls[] = $prompt;
            $source = json_decode(substr($prompt, strrpos($prompt, "\n") + 1), true, flags: JSON_THROW_ON_ERROR);
            if (is_array($source)) {
                self::assertLessThanOrEqual(512, strlen($source['source']));
                self::assertLessThanOrEqual(512, strlen($source['brouillon']));
                return '# Rapport final corrigé';
            }
            self::assertLessThanOrEqual(512, strlen($source));
            return str_contains($prompt, '14. Conclusion') ? '# Rapport final' : str_repeat('Une note utile. ', 6);
        });
        $result = (new VideoSummaryGenerator(new TranscriptChunker(512), $llm, new VideoSummaryPrompt(), new NullLogger()))->generate(str_repeat("Idée importante à conserver.\n", 400));
        self::assertSame('# Rapport final corrigé', $result);
        self::assertGreaterThan(30, count($calls));
        self::assertStringContainsString('notes intermédiaires', $calls[count($calls) - 2]);
    }

    public function testEveryDraftFragmentIsReviewedAgainstTheEvidence(): void
    {
        $source = 'La sauvegarde est hebdomadaire.';
        $draft = implode("\n", array_map(fn ($i) => "Détail $i du brouillon à contrôler.", range(1, 40)));
        $fragments = [];
        $llm = $this->createMock(LlmProviderInterface::class);
        $llm->method('generate')->willReturnCallback(function (string $prompt) use ($source, $draft, &$fragments): string {
            $data = json_decode(substr($prompt, strrpos($prompt, "\n") + 1), true, flags: JSON_THROW_ON_ERROR);
            if (is_string($data)) { return $draft; }
            self::assertSame($source, $data['source']);
            self::assertLessThanOrEqual(512, strlen($data['brouillon']));
            $fragments[] = $data['brouillon'];
            return 'Correction '.count($fragments);
        });
        $result = (new VideoSummaryGenerator(new TranscriptChunker(512), $llm, new VideoSummaryPrompt(), new NullLogger()))->generate($source);
        self::assertGreaterThan(1, count($fragments));
        self::assertSame(preg_replace('/\s+/u', ' ', $draft), preg_replace('/\s+/u', ' ', implode("\n", $fragments)));
        self::assertSame(implode("\n\n", array_map(fn ($i) => 'Correction '.$i, range(1, count($fragments)))), $result);
    }

    public function testReviewFailureDoesNotReturnAnUncheckedDraft(): void
    {
        $llm = $this->createMock(LlmProviderInterface::class);
        $attempt = 0;
        $llm->method('generate')->willReturnCallback(function () use (&$attempt): string {
            if (++$attempt === 1) { return 'Brouillon'; }
            throw new \RuntimeException('Relecture indisponible');
        });
        $this->expectExceptionMessage('Relecture indisponible');
        (new VideoSummaryGenerator(new TranscriptChunker(), $llm, new VideoSummaryPrompt(), new NullLogger()))->generate('Source');
    }
}
