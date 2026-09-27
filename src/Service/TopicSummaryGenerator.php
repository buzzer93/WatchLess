<?php
namespace App\Service;

use App\Llm\LlmProviderInterface;
use App\Prompt\TopicSummaryPrompt;

class TopicSummaryGenerator
{
    public function __construct(private TranscriptChunker $chunker, private LlmProviderInterface $llm, private TopicSummaryPrompt $prompts) {}

    public function generate(string $name, string $description, string $context, array $sources, string $intent = ''): string
    {
        $parts = [];
        foreach ($sources as $source) {
            // Repeat the identity on every fragment so long reports never lose
            // their source. Transcripts are authoritative; summary is only a
            // compatibility fallback for old records without one.
            $evidence = trim((string) ($source['transcript'] ?? ''));
            if ($evidence === '') { $evidence = trim((string) ($source['summary'] ?? '')); }
            $parts[$source['id']] = '[V'.$source['id'].'] '.mb_substr($source['title'], 0, 200)."\n".$evidence;
        }
        if ($parts === []) { throw new \RuntimeException('Aucun compte rendu exploitable.'); }
        for ($round = 0; strlen(implode("\n\n", $parts)) > $this->chunker->maxBytes(); ++$round) {
            if ($round >= 12) {
                // A provider may ignore the requested compression limit. Keep a
                // bounded dossier per video so the final report still covers
                // every source instead of failing the whole asynchronous job.
                $perSource = max(512, intdiv($this->chunker->maxBytes(), max(1, count($parts))));
                foreach ($parts as $id => $part) { $parts[$id] = mb_strcut($part, 0, $perSource, 'UTF-8'); }
                break;
            }
            $reduced = [];
            foreach ($parts as $id => $part) {
                $notes = [];
                foreach ($this->chunker->split($part) as $fragment) {
                    $notes[] = '[V'.$id.'] '.$this->llm->generate($this->prompts->notes('[V'.$id."]\n".$fragment));
                }
                $reduced[$id] = implode("\n", $notes);
            }
            $parts = $reduced;
        }
        $evidence = implode("\n\n", $parts);
        $draft = $this->llm->generate($this->prompts->report($name, $description, $context, $evidence, $intent));
        $reviewed = [];
        foreach ($this->chunker->split($draft) as $fragment) { $reviewed[] = $this->llm->generate($this->prompts->review($evidence, $fragment)); }
        if ($reviewed === []) { throw new \RuntimeException('La synthèse est vide.'); }
        $report = implode("\n\n", $reviewed);
        preg_match_all('/\[V(\d+)\]/', $report, $matches);
        if (array_diff(array_map('intval', $matches[1]), array_column($sources, 'id'))) {
            throw new \RuntimeException('Le modèle a cité une vidéo qui ne fait pas partie du sujet. Relancez la synthèse.');
        }
        if (!$matches[1]) { throw new \RuntimeException('La synthèse ne référence aucune vidéo source. Relancez la synthèse.'); }
        $missing = array_diff(array_column($sources, 'id'), array_map('intval', $matches[1]));
        if ($missing) {
            $missingSources = array_values(array_filter($sources, static fn (array $source): bool => in_array($source['id'], $missing, true)));
            try {
                $integrated = trim($this->llm->generate($this->prompts->integrateMissing($name, $report, $missingSources)));
                preg_match_all('/\[V(\d+)\]/', $integrated, $repairMatches);
                $repairIds = array_map('intval', $repairMatches[1]);
                if ($integrated === '' || !$repairMatches[1] || array_diff($repairIds, array_column($sources, 'id'))) {
                    $integrated = '';
                }
                if ($integrated !== '') { $report .= "\n\n## Contributions intégrées\n\n".$integrated; }
            } catch (\Throwable) {
                // Keep the already reviewed report if the repair call is unavailable.
            }
            preg_match_all('/\[V(\d+)\]/', $report, $matches);
            $missing = array_diff(array_column($sources, 'id'), array_map('intval', $matches[1]));
            if ($missing) { $report .= "\n\n## Sources complémentaires\n\n"; }
            foreach ($sources as $source) {
                if (!in_array($source['id'], $missing, true)) { continue; }
                $summary = trim(preg_replace('/\[V\d+\]/', '', (string) ($source['transcript'] ?? $source['summary'] ?? '')) ?? '');
                $summary = mb_substr($summary, 0, 2500);
                $report .= '[V'.$source['id'].'] '.mb_substr((string) $source['title'], 0, 200)."\n\n".$summary."\n\n";
            }
        }
        return trim($report);
    }
}
