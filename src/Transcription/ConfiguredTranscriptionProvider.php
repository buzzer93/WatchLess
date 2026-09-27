<?php

namespace App\Transcription;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ConfiguredTranscriptionProvider implements TranscriptionProviderInterface
{
    public function __construct(private LocalWhisperProvider $local, private OpenAiTranscriptionProvider $openai,
        #[Autowire('%env(TRANSCRIPTION_PROVIDER)%')] private string $provider) {}

    public function transcribe(string $audioPath): TranscriptionResult
    {
        return match ($this->provider) {
            'local' => $this->local->transcribe($audioPath),
            'openai' => $this->openai->transcribe($audioPath),
            default => throw new \RuntimeException('TRANSCRIPTION_PROVIDER doit valoir local ou openai.'),
        };
    }
}
