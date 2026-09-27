<?php

namespace App\Service;

use App\Llm\LlmProviderInterface;
use App\Prompt\VideoSummaryPrompt;
use Psr\Log\LoggerInterface;

class VideoSummaryGenerator
{
    public function __construct(private TranscriptChunker $chunker, private LlmProviderInterface $llm,
        private VideoSummaryPrompt $prompts, private LoggerInterface $logger) {}

    public function generate(string $transcript): string
    {
        $chunks = $this->chunker->split($transcript);
        if ($chunks === []) { throw new \RuntimeException('Impossible de résumer une transcription vide.'); }
        $fromNotes = count($chunks) > 1;
        for ($round = 0; count($chunks) > 1; ++$round) {
            if ($round >= 8) { throw new \RuntimeException('La synthèse ne converge pas : réduisez la taille des réponses intermédiaires ou changez de modèle.'); }
            $notes = [];
            foreach ($chunks as $i => $chunk) {
                $this->logger->info('[WatchLess] Summarizing chunk', ['round' => $round, 'part' => $i + 1, 'total' => count($chunks)]);
                $notes[] = $this->llm->generate($this->prompts->notes($chunk, $i + 1, count($chunks)));
            }
            // Re-chunk the merged notes too; the final call must obey the same input budget.
            $chunks = $this->chunker->split(implode("\n\n", $notes));
        }
        $draft = $this->llm->generate($this->prompts->report($chunks[0], $fromNotes));
        // Bound the draft too: the review contains at most two source-sized inputs.
        // Use the same final evidence for every fragment; never review against the draft alone.
        $reviewed = [];
        foreach ($this->chunker->split($draft) as $i => $fragment) {
            $this->logger->info('[WatchLess] Reviewing report fidelity', ['part' => $i + 1]);
            $reviewed[] = $this->llm->generate($this->prompts->review($chunks[0], $fragment));
        }
        if ($reviewed === []) { throw new \RuntimeException('Le brouillon du compte rendu est vide.'); }
        return implode("\n\n", $reviewed);
    }
}
