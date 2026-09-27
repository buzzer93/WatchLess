<?php

namespace App\Transcription;

use App\Service\CommandRunner;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenAiTranscriptionProvider implements TranscriptionProviderInterface
{
    public function __construct(private HttpClientInterface $http, private CommandRunner $runner,
        #[Autowire('%env(OPENAI_API_KEY)%')] private string $apiKey,
        #[Autowire('%env(FFMPEG_BINARY)%')] private string $ffmpeg) {}

    public function transcribe(string $audioPath): TranscriptionResult
    {
        if ($this->apiKey === '') { throw new \RuntimeException('Renseignez OPENAI_API_KEY dans .env.local pour la transcription API.'); }
        // Ten minutes at 64 kb/s: comfortably below the API's 25 MB upload limit.
        $this->runner->run([$this->ffmpeg, '-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-i', $audioPath,
            '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'libmp3lame', '-b:a', '64k', '-f', 'segment',
            '-segment_time', '600', '-reset_timestamps', '1', dirname($audioPath).'/part-%04d.mp3'], 1800);
        $parts = glob(dirname($audioPath).'/part-*.mp3') ?: [];
        sort($parts, SORT_STRING);
        $texts = $segments = [];
        $language = null;
        foreach ($parts as $index => $part) {
            if (filesize($part) > 24_000_000) { throw new \RuntimeException('Segment audio trop volumineux.'); }
            $form = new FormDataPart(['file' => DataPart::fromPath($part), 'model' => 'whisper-1', 'response_format' => 'verbose_json']);
            $response = $this->http->request('POST', 'https://api.openai.com/v1/audio/transcriptions', [
                'auth_bearer' => $this->apiKey, 'headers' => $form->getPreparedHeaders()->toArray(),
                'body' => $form->bodyToIterable(), 'timeout' => 600, 'max_duration' => 900, 'max_redirects' => 0,
            ]);
            if ($response->getStatusCode() !== 200) { throw new \RuntimeException('Échec API transcription (HTTP '.$response->getStatusCode().').'); }
            $data = $response->toArray();
            $text = trim($data['text'] ?? '');
            if ($text === '') { throw new \RuntimeException('Un segment audio a retourné une transcription vide.'); }
            $texts[] = $text;
            $language ??= $data['language'] ?? null;
            foreach ($data['segments'] ?? [] as $segment) {
                $segments[] = ['start' => $index * 600.0 + (float) $segment['start'], 'text' => trim($segment['text'])];
            }
        }
        return new TranscriptionResult(implode("\n\n", $texts), $segments, $language, 'openai-whisper');
    }
}
