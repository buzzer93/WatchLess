<?php

namespace App\Tests\Integration;

use App\Entity\Video;
use App\Enum\VideoStatus as S;
use App\Llm\LlmProviderInterface;
use App\Message\AnalyzeVideo;
use App\MessageHandler\AnalyzeVideoHandler;
use App\Prompt\VideoSummaryPrompt;
use App\Repository\VideoRepository;
use App\Service\SubmitVideo;
use App\Service\TranscriptChunker;
use App\Service\VideoSummaryGenerator;
use App\Transcription\TranscriptionResult;
use App\YouTube\YouTubeUrl;
use App\YouTube\YouTubeVideoService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Messenger\MessageBusInterface;

final class WatchLessTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertTrue($this->em->getConnection()->getParams()['memory'] ?? false, 'Tests must use an isolated in-memory database.');
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        static::getContainer()->get('messenger.transport.async')->setup();
    }

    public function testHttpSubmissionDuplicateAndStatus(): void
    {
        $crawler = $this->client->request('GET', '/videos/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Regardez moins.');
        $this->client->submit($crawler->selectButton('Analyser la vidéo')->form(['url' => 'https://youtu.be/dQw4w9WgXcQ']));
        self::assertResponseRedirects('/videos/1', 303);
        $this->client->followRedirect();
        self::assertSelectorExists('[data-status-url]');
        $crawler = $this->client->request('GET', '/videos/new');
        $this->client->submit($crawler->selectButton('Analyser la vidéo')->form(['url' => 'https://youtube.com/watch?v=dQw4w9WgXcQ&utm_source=x']));
        self::assertResponseRedirects('/videos/1', 303);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM video'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
        $this->client->request('GET', '/videos/1/status');
        self::assertResponseIsSuccessful();
        self::assertSame('pending', json_decode($this->client->getResponse()->getContent(), true)['status']);
    }

    public function testInvalidUrlAndCsrfDoNotCreateRows(): void
    {
        $crawler = $this->client->request('GET', '/videos/new');
        $this->client->submit($crawler->selectButton('Analyser la vidéo')->form(['url' => 'https://evil.test/watch?v=dQw4w9WgXcQ']));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[role="alert"]');
        $this->client->request('POST', '/videos', ['url' => 'https://youtu.be/dQw4w9WgXcQ', '_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM video'));
    }

    public function testQueueFailureRollsBackVideoCreation(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('Queue unavailable'));
        $service = new SubmitVideo(new YouTubeUrl(), static::getContainer()->get(VideoRepository::class), $this->em, $bus, new LockFactory(new FlockStore()), new NullLogger());
        try { $service->submit('https://youtu.be/dQw4w9WgXcQ'); self::fail('Expected failure'); }
        catch (\RuntimeException $e) { self::assertSame('Queue unavailable', $e->getMessage()); }
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM video'));
    }

    public function testQueueKeepalivePreventsRedeliveryAndExpiredReservationRecovers(): void
    {
        $id = static::getContainer()->get(SubmitVideo::class)->submit('https://youtu.be/dQw4w9WgXcQ');
        $transport = static::getContainer()->get('messenger.transport.async');
        $messages = iterator_to_array($transport->get());
        self::assertCount(1, $messages);
        $envelope = reset($messages);
        self::assertSame($id, $envelope->getMessage()->videoId);
        $connection = $this->em->getConnection();
        $connection->executeStatement("UPDATE messenger_messages SET delivered_at = '2000-01-01 00:00:00'");
        $transport->keepalive($envelope, 60);
        self::assertNotSame('2000-01-01 00:00:00', $connection->fetchOne('SELECT delivered_at FROM messenger_messages'));
        self::assertCount(0, iterator_to_array($transport->get()));
        // A crash stops heartbeats: only the expired reservation can be delivered again.
        $connection->executeStatement("UPDATE messenger_messages SET delivered_at = '2000-01-01 00:00:00'");
        $recovered = iterator_to_array($transport->get());
        self::assertCount(1, $recovered);
        self::assertSame($id, reset($recovered)->getMessage()->videoId);
    }

    public function testDeletionRequiresConfirmationAndCsrfAndAllowsResubmission(): void
    {
        $id = static::getContainer()->get(SubmitVideo::class)->submit('https://youtu.be/dQw4w9WgXcQ');
        $crawler = $this->client->request('GET', '/videos/'.$id.'/delete');
        self::assertResponseIsSuccessful();
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM video'));
        $form = $crawler->selectButton('Supprimer définitivement')->form();
        $this->client->request('POST', '/videos/'.$id.'/delete', ['_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM video'));
        $this->client->submit($form);
        self::assertResponseRedirects('/videos', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="status"]', 'supprimés');
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM video'));
        $this->client->request('GET', '/videos/'.$id);
        self::assertResponseStatusCodeSame(404);
        // A previously queued message is harmless after deletion.
        $youtube = $this->createMock(YouTubeVideoService::class);
        $youtube->expects(self::never())->method('fetchMetadata');
        $llm = $this->createMock(LlmProviderInterface::class);
        $llm->expects(self::never())->method('generate');
        (new AnalyzeVideoHandler($this->em, $youtube, new VideoSummaryGenerator(new TranscriptChunker(), $llm, new VideoSummaryPrompt(), new NullLogger()), new LockFactory(new FlockStore()), new NullLogger()))(new AnalyzeVideo($id));
        $newId = static::getContainer()->get(SubmitVideo::class)->submit('https://youtu.be/dQw4w9WgXcQ');
        self::assertNotSame($id, $newId);
    }

    public function testActiveWorkerPreventsDeletion(): void
    {
        $id = static::getContainer()->get(SubmitVideo::class)->submit('https://youtu.be/dQw4w9WgXcQ');
        $lock = static::getContainer()->get(LockFactory::class)->createLock('analyze-'.$id);
        self::assertTrue($lock->acquire());
        try {
            $crawler = $this->client->request('GET', '/videos/'.$id.'/delete');
            $this->client->submit($crawler->selectButton('Supprimer définitivement')->form());
            self::assertResponseStatusCodeSame(409);
            self::assertSelectorTextContains('[role="alert"]', 'en cours de traitement');
            self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM video'));
        } finally { $lock->release(); }
    }

    public function testPipelinePersistsTranscriptAndRetrySkipsTranscription(): void
    {
        $id = static::getContainer()->get(SubmitVideo::class)->submit('https://youtu.be/dQw4w9WgXcQ');
        $youtube = $this->createMock(YouTubeVideoService::class);
        $youtube->expects(self::once())->method('fetchMetadata')->willReturnCallback(function (Video $video) { $video->title = 'Méthodes Symfony'; return []; });
        $youtube->expects(self::once())->method('fetchTranscript')->willReturn(new TranscriptionResult('Le contenu intégral.', [['start' => 0.0, 'text' => 'Le contenu intégral.']], 'fr', 'youtube-manual'));
        $llm = $this->createMock(LlmProviderInterface::class);
        $attempt = 0;
        $llm->expects(self::exactly(3))->method('generate')->willReturnCallback(function () use (&$attempt) {
            if (++$attempt === 1) { throw new \RuntimeException('LLM indisponible'); }
            return '# Rapport détaillé'."\n\n".'Une méthode expliquée. <script>alert(1)</script>';
        });
        $handler = new AnalyzeVideoHandler($this->em, $youtube, new VideoSummaryGenerator(new TranscriptChunker(), $llm, new VideoSummaryPrompt(), new NullLogger()), new LockFactory(new FlockStore()), new NullLogger());
        try { $handler(new AnalyzeVideo($id)); self::fail('Expected LLM failure'); }
        catch (\RuntimeException $e) { self::assertSame('LLM indisponible', $e->getMessage()); }
        $this->em->clear();
        $video = $this->em->find(Video::class, $id);
        self::assertSame(S::Failed, $video->getStatus());
        self::assertSame('Le contenu intégral.', $video->transcriptText());
        self::assertStringContainsString('[summarizing]', $video->errorMessage);
        $handler(new AnalyzeVideo($id));
        $handler(new AnalyzeVideo($id)); // Completed messages are idempotent.
        $this->em->clear();
        self::assertSame(S::Completed, $this->em->find(Video::class, $id)->getStatus());
        $this->client->request('GET', '/videos/'.$id);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.prose', 'Rapport détaillé');
        self::assertSelectorNotExists('.prose script');
        self::assertSelectorNotExists('[data-status-url]');
        $this->client->request('GET', '/videos?q=Symfony');
        self::assertSelectorCount(1, '.video-card');
        $this->client->request('GET', '/videos?q=introuvable');
        self::assertSelectorTextContains('.empty-state', 'Aucun résultat');
    }
}
