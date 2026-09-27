<?php

namespace App\Tests\Unit;

use App\Entity\Video;
use App\Transcription\TranscriptionProviderInterface;
use App\Transcription\TranscriptionResult;
use App\YouTube\SubtitleParser;
use App\YouTube\YouTubeVideoService;
use App\YouTube\YtDlpService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

final class YouTubeServiceTest extends TestCase
{
    private string $directory;
    protected function setUp(): void { $this->directory = sys_get_temp_dir().'/watchless-test-'.bin2hex(random_bytes(8)); }
    protected function tearDown(): void { (new Filesystem())->remove($this->directory); }

    public function testManualSubtitlesArePreferredWithoutAudio(): void
    {
        $yt = $this->createMock(YtDlpService::class);
        $stt = $this->createMock(TranscriptionProviderInterface::class);
        $yt->expects(self::once())->method('subtitles')->with(self::anything(), 'fr', false, self::anything())->willReturn('{"events":[{"tStartMs":1200,"segs":[{"utf8":"Bonjour &amp; merci"}]}]}');
        $yt->expects(self::never())->method('audio');
        $stt->expects(self::never())->method('transcribe');
        $result = (new YouTubeVideoService($yt, new SubtitleParser(), $stt, new NullLogger(), $this->directory, ['fr', 'en']))
            ->fetchTranscript(new Video('dQw4w9WgXcQ'), ['subtitles' => ['en' => [], 'fr' => []], 'automatic_captions' => ['fr' => []]]);
        self::assertSame('Bonjour & merci', $result->text);
        self::assertSame('youtube-manual', $result->source);
        self::assertSame([], glob($this->directory.'/*'));
    }
    public function testFallbackAndCleanupOnFailure(): void
    {
        $yt = $this->createMock(YtDlpService::class);
        $stt = $this->createMock(TranscriptionProviderInterface::class);
        $yt->method('audio')->willReturnCallback(function ($url, $dir) { file_put_contents($dir.'/audio.mp3', 'fake'); return $dir.'/audio.mp3'; });
        $stt->expects(self::once())->method('transcribe')->willThrowException(new \RuntimeException('STT indisponible'));
        try {
            (new YouTubeVideoService($yt, new SubtitleParser(), $stt, new NullLogger(), $this->directory, ['fr']))->fetchTranscript(new Video('dQw4w9WgXcQ'), []);
            self::fail('Expected transcription failure');
        } catch (\RuntimeException $e) { self::assertSame('STT indisponible', $e->getMessage()); }
        self::assertSame([], glob($this->directory.'/*'));
    }
    public function testBrokenCaptionFallsBackToAudio(): void
    {
        $yt = $this->createMock(YtDlpService::class);
        $stt = $this->createMock(TranscriptionProviderInterface::class);
        $yt->method('subtitles')->willReturn('invalid json');
        $yt->expects(self::once())->method('audio')->willReturn('/fake/audio.mp3');
        $stt->expects(self::once())->method('transcribe')->willReturn(new TranscriptionResult('Audio transcrit'));
        $result = (new YouTubeVideoService($yt, new SubtitleParser(), $stt, new NullLogger(), $this->directory, ['fr']))->fetchTranscript(new Video('dQw4w9WgXcQ'), ['automatic_captions' => ['fr' => []]]);
        self::assertSame('Audio transcrit', $result->text);
    }
    public function testRepeatedSpokenPhrasesArePreserved(): void
    {
        $result = (new SubtitleParser())->parse('{"events":[{"tStartMs":0,"segs":[{"utf8":"Oui."}]},{"tStartMs":1000,"segs":[{"utf8":"Oui."}]}]}', 'fr', 'youtube-auto');
        self::assertSame("Oui.\nOui.", $result->text);
        self::assertCount(2, $result->segments);
    }
}
