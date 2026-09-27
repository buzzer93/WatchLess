<?php

namespace App\YouTube;

use App\Entity\Video;
use App\Transcription\TranscriptionProviderInterface;
use App\Transcription\TranscriptionResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

class YouTubeVideoService
{
    public function __construct(private YtDlpService $ytDlp, private SubtitleParser $parser,
        private TranscriptionProviderInterface $transcriber, private LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%/var/watchless/tmp')] private string $tempRoot,
        #[Autowire('%env(csv:SUBTITLE_LANGUAGES)%')] private array $preferredLanguages) {}

    public function fetchMetadata(Video $video): array
    {
        $data = $this->ytDlp->metadata($video->url);
        $video->title = mb_substr((string) ($data['title'] ?? $video->youtubeId), 0, 1000);
        $video->channelName = isset($data['channel']) ? mb_substr($data['channel'], 0, 500) : null;
        $video->thumbnailUrl = 'https://i.ytimg.com/vi/'.$video->youtubeId.'/hqdefault.jpg';
        $video->duration = isset($data['duration']) ? max(0, (int) $data['duration']) : null;
        $date = \DateTimeImmutable::createFromFormat('!Ymd', (string) ($data['upload_date'] ?? ''));
        $video->publishedAt = $date ?: null;
        return $data;
    }

    public function fetchTranscript(Video $video, array $metadata): TranscriptionResult
    {
        $fs = new Filesystem();
        $directory = $this->tempRoot.'/'.$video->youtubeId.'-'.bin2hex(random_bytes(8));
        $fs->mkdir($directory, 0700);
        try {
            $this->logger->info('[WatchLess] Looking for subtitles', ['video' => $video->id]);
            foreach (['subtitles' => false, 'automatic_captions' => true] as $key => $automatic) {
                $tracks = $metadata[$key] ?? [];
                $languages = array_filter(array_keys($tracks), static fn ($lang) => $lang !== 'live_chat');
                usort($languages, function ($a, $b) use ($metadata): int {
                    $rank = function ($language) use ($metadata): int {
                        foreach ($this->preferredLanguages as $i => $preferred) {
                            if ($language === $preferred || str_starts_with($language, $preferred.'-')) { return $i; }
                        }
                        return $language === ($metadata['language'] ?? '') ? 100 : 200;
                    };
                    return $rank($a) <=> $rank($b);
                });
                // Avoid downloading hundreds of auto-translated tracks on a blocked request.
                foreach (array_slice($languages, 0, 3) as $language) {
                    try {
                        if ($json = $this->ytDlp->subtitles($video->url, $language, $automatic, $directory)) {
                            return $this->parser->parse($json, $language, $automatic ? 'youtube-auto' : 'youtube-manual');
                        }
                    } catch (\RuntimeException | \JsonException $e) {
                        $this->logger->warning('[WatchLess] Subtitle track unavailable', ['language' => $language, 'reason' => $e->getMessage()]);
                    } finally {
                        $fs->remove(glob($directory.'/*.json3') ?: []);
                    }
                }
            }
            $this->logger->info('[WatchLess] No usable subtitles; downloading audio', ['video' => $video->id]);
            $audio = $this->ytDlp->audio($video->url, $directory);
            $this->logger->info('[WatchLess] Starting speech-to-text', ['video' => $video->id]);
            return $this->transcriber->transcribe($audio);
        } finally { $fs->remove($directory); }
    }
}
