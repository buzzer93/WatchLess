<?php

namespace App\Tests\Unit;

use App\Llm\CloudLlmProvider;
use App\Llm\FallbackLlmProvider;
use App\Llm\LlmQuotaExceededException;
use App\Llm\OpenAiCompatibleLlmProvider;
use App\Service\CommandRunner;
use App\Transcription\OpenAiTranscriptionProvider;
use App\Twig\WatchLessExtension;
use App\YouTube\YouTubeUrl;
use App\YouTube\YtDlpService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ProviderTest extends TestCase
{
    public function testLlmRequestAndResponseWithoutNetwork(): void
    {
        $http = new MockHttpClient(function ($method, $url, $options) {
            self::assertSame('POST', $method);
            self::assertSame('http://localhost:11434/v1/chat/completions', $url);
            $body = json_decode($options['body'], true);
            self::assertSame('local-model', $body['model']);
            self::assertSame('La transcription', $body['messages'][1]['content']);
            self::assertSame('none', $body['reasoning_effort']);
            return new MockResponse('{"choices":[{"finish_reason":"stop","message":{"content":"Rapport"}}]}');
        });
        self::assertSame('Rapport', (new OpenAiCompatibleLlmProvider($http, 'http://localhost:11434/v1', '', 'local-model', 6000, 'max_tokens', 'none'))->generate('La transcription'));
    }
    public function testTruncatedLlmOutputIsNotAccepted(): void
    {
        $http = new MockHttpClient(new MockResponse('{"choices":[{"finish_reason":"length","message":{"content":"Incomplet"}}]}'));
        $this->expectException(\RuntimeException::class);
        (new OpenAiCompatibleLlmProvider($http, 'http://localhost/v1', '', 'local', 10))->generate('Texte');
    }
    public function testCloudQuotaFallsBackToLocalProvider(): void
    {
        $local = $this->createMock(OpenAiCompatibleLlmProvider::class);
        $local->expects(self::once())->method('generate')->with('Texte')->willReturn('Rapport local');
        $cloud = $this->createMock(CloudLlmProvider::class);
        $cloud->expects(self::once())->method('generate')->with('Texte')->willThrowException(new LlmQuotaExceededException('quota'));

        $provider = new FallbackLlmProvider($local, $cloud, true, new NullLogger());
        self::assertSame('Rapport local', $provider->generate('Texte'));
    }

    public function testCloudAuthenticationFailureDoesNotHideConfigurationError(): void
    {
        $local = $this->createMock(OpenAiCompatibleLlmProvider::class);
        $local->expects(self::never())->method('generate');
        $cloud = $this->createMock(CloudLlmProvider::class);
        $cloud->method('generate')->willThrowException(new \RuntimeException('clé invalide'));

        $provider = new FallbackLlmProvider($local, $cloud, true, new NullLogger());
        $this->expectExceptionMessage('clé invalide');
        $provider->generate('Texte');
    }
    public function testMiMoUsesBearerAndCompletionBudgetWithoutExposingReasoning(): void
    {
        $http = new MockHttpClient(function ($method, $url, $options) {
            self::assertSame('https://api.xiaomimimo.com/v1/chat/completions', $url);
            self::assertContains('Authorization: Bearer test-key', $options['normalized_headers']['authorization']);
            $body = json_decode($options['body'], true);
            self::assertSame('mimo-v2.6-pro', $body['model']);
            self::assertArrayNotHasKey('reasoning_effort', $body);
            self::assertSame(16000, $body['max_completion_tokens']);
            return new MockResponse('{"choices":[{"finish_reason":"stop","message":{"content":"Rapport final","reasoning_content":"Raisonnement interne"}}]}');
        });
        self::assertSame('Rapport final', (new OpenAiCompatibleLlmProvider($http, 'https://api.xiaomimimo.com/v1', 'test-key', 'mimo-v2.6-pro', 16000))->generate('Texte'));
    }
    public function testAudioChunksHaveGlobalTimestamps(): void
    {
        $directory = sys_get_temp_dir().'/watchless-provider-'.bin2hex(random_bytes(8));
        (new Filesystem())->mkdir($directory);
        file_put_contents($directory.'/part-0000.mp3', 'fake audio');
        file_put_contents($directory.'/part-0001.mp3', 'fake audio');
        $runner = $this->createMock(CommandRunner::class);
        $runner->expects(self::once())->method('run')->willReturn('');
        $http = new MockHttpClient([
            new MockResponse('{"text":"Première partie","language":"french","segments":[{"start":1,"text":"Première partie"}]}'),
            new MockResponse('{"text":"Dernière partie","language":"french","segments":[{"start":2,"text":"Dernière partie"}]}'),
        ]);
        try {
            $result = (new OpenAiTranscriptionProvider($http, $runner, 'test-key', 'ffmpeg'))->transcribe($directory.'/audio.mp3');
            self::assertSame("Première partie\n\nDernière partie", $result->text);
            self::assertSame(602.0, $result->segments[1]['start']);
        } finally { (new Filesystem())->remove($directory); }
    }
    public function testYtDlpUsesCanonicalUrlAndArgumentArray(): void
    {
        $runner = $this->createMock(CommandRunner::class);
        $runner->expects(self::once())->method('run')->willReturnCallback(function ($args, $timeout) {
            self::assertSame('yt-dlp', $args[0]);
            self::assertContains('--ignore-config', $args);
            self::assertSame(['--', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'], array_slice($args, -2));
            self::assertSame(1800.0, $timeout);
            return '{"id":"dQw4w9WgXcQ","title":"Test"}';
        });
        $data = (new YtDlpService($runner, new YouTubeUrl(), 'yt-dlp', 1800))->metadata('https://youtu.be/dQw4w9WgXcQ?t=10');
        self::assertSame('Test', $data['title']);
    }
    public function testMarkdownStripsHtmlAndUnsafeLinks(): void
    {
        $html = (new WatchLessExtension())->markdown('<script>alert(1)</script>'."\n\n".'[x](javascript:alert(1))');
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('href="javascript:', $html);
    }
}
