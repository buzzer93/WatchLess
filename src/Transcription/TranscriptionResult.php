<?php

namespace App\Transcription;

final readonly class TranscriptionResult
{
    /** @param list<array{start: float, text: string}> $segments */
    public function __construct(public string $text, public array $segments = [], public ?string $language = null,
        public string $source = 'speech-to-text')
    {
        if (trim($text) === '') { throw new \RuntimeException('La transcription reçue est vide.'); }
    }

    public function timestampedText(): ?string
    {
        if ($this->segments === []) { return null; }
        return implode("\n", array_map(static function (array $segment): string {
            $s = max(0, (int) $segment['start']);
            return sprintf('%02d:%02d:%02d %s', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60, $segment['text']);
        }, $this->segments));
    }
}
