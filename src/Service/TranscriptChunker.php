<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class TranscriptChunker
{
    // A UTF-8 byte ceiling is conservative compared with a character/token estimate.
    public function __construct(#[Autowire('%env(int:TRANSCRIPT_CHUNK_BYTES)%')] private int $maxBytes = 12000)
    {
        if ($maxBytes < 256) { throw new \InvalidArgumentException('TRANSCRIPT_CHUNK_BYTES doit être au moins 256.'); }
    }

    /** @return list<string> */
    public function maxBytes(): int { return $this->maxBytes; }

    /** @return list<string> */
    public function split(string $text): array
    {
        $text = trim($text);
        if ($text === '') { return []; }
        $pieces = preg_split('/(?<=\n)|(?<=[.!?])\s+/u', $text, flags: PREG_SPLIT_NO_EMPTY);
        $chunks = [];
        $current = '';
        foreach ($pieces as $piece) {
            $piece = trim($piece);
            if ($piece === '') { continue; }
            while (strlen($piece) > $this->maxBytes) {
                if ($current !== '') { $chunks[] = $current; $current = ''; }
                $head = mb_strcut($piece, 0, $this->maxBytes, 'UTF-8');
                $space = strrpos($head, ' ');
                if ($space !== false && $space > $this->maxBytes / 2) { $head = substr($head, 0, $space); }
                $chunks[] = $head;
                $piece = ltrim(substr($piece, strlen($head)));
            }
            if ($piece === '') { continue; }
            if ($current !== '' && strlen($current) + strlen($piece) + 1 > $this->maxBytes) {
                $chunks[] = $current;
                $current = '';
            }
            $current .= ($current === '' ? '' : "\n").$piece;
        }
        if ($current !== '') { $chunks[] = $current; }
        return $chunks;
    }
}
