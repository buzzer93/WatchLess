<?php

namespace App\Tests\Unit;

use App\Entity\Video;
use App\Enum\VideoStatus as S;
use App\Transcription\TranscriptionResult;
use PHPUnit\Framework\TestCase;

final class VideoTest extends TestCase
{
    public function testLifecycleAndRetry(): void
    {
        $video = new Video('dQw4w9WgXcQ');
        self::assertSame(S::Pending, $video->getStatus());
        $video->transition(S::Metadata);
        $video->transition(S::Transcribing);
        $video->setTranscript(new TranscriptionResult('Bonjour', [['start' => 3661.5, 'text' => 'Bonjour']], 'fr'));
        $video->transition(S::Transcribed);
        $video->transition(S::Summarizing);
        $video->transition(S::Failed);
        $video->errorMessage = 'Erreur';
        $video->transition(S::Pending);
        self::assertNull($video->errorMessage);
        self::assertSame('01:01:01 Bonjour', $video->transcript);
        self::assertSame('Bonjour', $video->transcriptText());
    }
    public function testCannotSkipStages(): void
    {
        $this->expectException(\LogicException::class);
        (new Video('dQw4w9WgXcQ'))->transition(S::Completed);
    }
    public function testCannotCompleteWithoutSummary(): void
    {
        $video = new Video('dQw4w9WgXcQ');
        $video->transition(S::Metadata);
        $video->transition(S::Transcribing);
        $video->setTranscript(new TranscriptionResult('Texte'));
        $video->transition(S::Transcribed);
        $video->transition(S::Summarizing);
        $this->expectException(\LogicException::class);
        $video->transition(S::Completed);
    }
}
