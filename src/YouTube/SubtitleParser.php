<?php

namespace App\YouTube;

use App\Transcription\TranscriptionResult;

final class SubtitleParser
{
    public function parse(string $json, string $language, string $source): TranscriptionResult
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $segments = [];
        foreach ($data['events'] ?? [] as $event) {
            $text = implode('', array_column($event['segs'] ?? [], 'utf8'));
            $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($text === '' || !isset($event['tStartMs'])) { continue; }
            $start = (float) $event['tStartMs'] / 1000;
            // Only remove exact duplicate events; repeated spoken phrases must survive.
            $previous = $segments[array_key_last($segments)] ?? null;
            if ($previous && $previous['start'] === $start && $previous['text'] === $text) { continue; }
            $segments[] = ['start' => $start, 'text' => $text];
        }
        return new TranscriptionResult(implode("\n", array_column($segments, 'text')), $segments, $language, $source);
    }
}
