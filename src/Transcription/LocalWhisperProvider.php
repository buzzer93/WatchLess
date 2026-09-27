<?php

namespace App\Transcription;

use App\Service\CommandRunner;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class LocalWhisperProvider implements TranscriptionProviderInterface
{
    public function __construct(private CommandRunner $runner,
        #[Autowire('%env(WHISPER_BINARY)%')] private string $binary,
        #[Autowire('%env(WHISPER_MODEL)%')] private string $model,
        #[Autowire('%env(float:WHISPER_TIMEOUT)%')] private float $timeout) {}

    public function transcribe(string $audioPath): TranscriptionResult
    {
        $this->runner->run([$this->binary, $audioPath, '--model', $this->model, '--output_format', 'json',
            '--output_dir', dirname($audioPath), '--fp16', 'False', '--verbose', 'False'], $this->timeout);
        $path = dirname($audioPath).'/'.pathinfo($audioPath, PATHINFO_FILENAME).'.json';
        if (!is_file($path)) { throw new \RuntimeException('Whisper n’a produit aucune transcription JSON.'); }
        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $segments = array_map(static fn ($s) => ['start' => (float) $s['start'], 'text' => trim($s['text'])], $data['segments'] ?? []);
        return new TranscriptionResult(trim($data['text'] ?? ''), $segments, $data['language'] ?? null, 'whisper-local');
    }
}
