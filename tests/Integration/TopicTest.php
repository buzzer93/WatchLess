<?php
namespace App\Tests\Integration;

use App\Entity\Topic;
use App\Entity\TopicPublication;
use App\Entity\Video;
use App\Enum\VideoStatus;
use App\Llm\LlmProviderInterface;
use App\Message\GenerateTopicPublication;
use App\Message\SummarizeTopic;
use App\MessageHandler\GenerateTopicPublicationHandler;
use App\MessageHandler\SummarizeTopicHandler;
use App\Prompt\TopicPublicationPrompt;
use App\Service\TopicSummaryGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class TopicTest extends WebTestCase
{
    private $client;
    private EntityManagerInterface $em;
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertTrue($this->em->getConnection()->getParams()['memory'] ?? false);
        (new SchemaTool($this->em))->createSchema($this->em->getMetadataFactory()->getAllMetadata());
        self::getContainer()->get('messenger.transport.async')->setup();
    }
    private function topic(): Topic
    {
        $topic = new Topic('Sauvegardes');
        foreach (['aaaaaaaaaaa', 'bbbbbbbbbbb'] as $youtubeId) {
            $video = new Video($youtubeId, 'https://youtu.be/'.$youtubeId);
            $video->transcript = 'Transcription source'; $video->summary = 'Compte rendu source';
            foreach ([VideoStatus::Metadata, VideoStatus::Transcribing, VideoStatus::Transcribed, VideoStatus::Summarizing, VideoStatus::Completed] as $status) { $video->transition($status); }
            $video->title = 'Source '.$youtubeId; $video->summary = 'Compte rendu source';
            $this->em->persist($video); $topic->videos->add($video);
        }
        $this->em->persist($topic); $this->em->flush();
        return $topic;
    }
    private function action(Topic $topic, array $values): void
    {
        $crawler = $this->client->request('GET', '/topics/'.$topic->id);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/topics/'.$topic->id.'/update', ['_token' => $token] + $values);
        self::assertResponseRedirects('/topics/'.$topic->id, 303);
    }
    public function testCreateMembershipAndQueue(): void
    {
        $fixture = $this->topic();
        $crawler = $this->client->request('GET', '/topics');
        $this->client->submit($crawler->selectButton('Créer le sujet')->form(['name' => 'Mon sujet']));
        self::assertResponseRedirects('/topics/2', 303);
        $topic = $this->em->find(Topic::class, 2);
        $this->action($topic, ['action' => 'generate']);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
        foreach ($fixture->videos as $video) { $this->action($topic, ['action' => 'add', 'video_id' => $video->id]); }
        $this->action($topic, ['action' => 'generate']);
        $this->action($topic, ['action' => 'generate']);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
        $this->action($topic, ['action' => 'remove', 'video_id' => $fixture->videos->first()->id]);
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM video'));
        self::assertSame('idle', $this->em->getConnection()->fetchOne('SELECT status FROM topic WHERE id = 2'));
        $this->client->request('POST', '/topics/2/update', ['action' => 'remove', 'video_id' => 2, '_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);
    }
    public function testHomeToTopicToDirectYoutubeImport(): void
    {
        $crawler = $this->client->request('GET', '/');
        self::assertSelectorTextContains('h1', 'Mes sujets');
        self::assertSelectorNotExists('input[name="url"]');
        $this->client->submit($crawler->selectButton('Créer le sujet')->form(['name' => 'Agents IA']));
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Ajouter et analyser la vidéo')->form(['url' => 'https://youtu.be/dQw4w9WgXcQ']));
        self::assertResponseRedirects('/topics/1', 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorCount(1, '.topic-video');
        self::assertSelectorExists('[data-status-url]');
        $progress = $crawler->filter('[data-status-url]')->attr('data-status');
        $this->client->request('GET', '/topics/1/status');
        $status = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame($progress, $status['status']); self::assertFalse($status['terminal']);
        $topic = $this->em->find(Topic::class, 1);
        $this->action($topic, ['action' => 'import', 'url' => 'https://youtube.com/watch?v=dQw4w9WgXcQ']);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM topic_video'));
        $other = new Topic('Autre sujet'); $this->em->persist($other); $this->em->flush();
        $this->action($other, ['action' => 'import', 'url' => 'https://youtu.be/dQw4w9WgXcQ']);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM video'));
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM topic_video'));
        $this->client->request('GET', '/videos/1?topic=1');
        self::assertSelectorTextContains('.back-link', 'Agents IA');
        $this->client->request('GET', '/');
        self::assertSelectorTextContains('main', 'Agents IA');
    }
    public function testInvalidImportAndCsrfCannotCreateVideo(): void
    {
        $topic = new Topic('Sujet'); $this->em->persist($topic); $this->em->flush();
        $this->action($topic, ['action' => 'import', 'url' => 'https://evil.test/video']);
        $this->client->followRedirect(); self::assertSelectorExists('[role="alert"]');
        $this->client->request('POST', '/topics/1/update', ['action' => 'import', 'url' => 'https://youtu.be/dQw4w9WgXcQ', '_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM video'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM topic_video'));
    }
    public function testQueueFailureRollsBackImportAndMembership(): void
    {
        $topic = new Topic('Sujet'); $this->em->persist($topic); $this->em->flush();
        $crawler = $this->client->request('GET', '/topics/1');
        $this->em->getConnection()->executeStatement('DROP TABLE messenger_messages');
        $this->client->submit($crawler->selectButton('Ajouter et analyser la vidéo')->form(['url' => 'https://youtu.be/dQw4w9WgXcQ']));
        self::assertResponseStatusCodeSame(500);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM video'));
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM topic_video'));
    }
    public function testHandlerStoresSourcesAndIgnoresDuplicate(): void
    {
        $topic = $this->topic(); $topic->status = 'pending'; $this->em->flush();
        $generator = $this->createMock(TopicSummaryGenerator::class);
        $generator->expects(self::once())->method('generate')->willReturn('# Synthèse [V1] [V2]');
        $handler = new SummarizeTopicHandler($this->em, $generator, new LockFactory(new FlockStore()), new NullLogger());
        $handler(new SummarizeTopic($topic->id, $topic->revision));
        $handler(new SummarizeTopic($topic->id, $topic->revision));
        $this->em->refresh($topic);
        self::assertSame('completed', $topic->status);
        self::assertCount(2, $topic->summarySources);
        self::assertFalse($topic->isStale());
        $this->client->request('GET', '/topics/'.$topic->id);
        self::assertSelectorTextContains('.prose', 'Synthèse');
    }

    public function testPublicationFormatsAreDisplayedAndQueuedIndependently(): void
    {
        $topic = $this->topic();
        $topic->summary = 'Compte rendu global'; $topic->summaryRevision = $topic->revision; $topic->status = 'completed'; $this->em->flush();
        $crawler = $this->client->request('GET', '/topics/'.$topic->id);
        self::assertSelectorTextContains('#publications', 'Post réseaux sociaux');
        self::assertSelectorTextContains('#publications', 'Newsletter');
        self::assertSelectorTextContains('#publications', 'Article de blog');
        self::assertSelectorTextContains('#publications', 'Résumé court');
        $form = $crawler->filter('#publications form')->first()->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/topics/'.$topic->id, 303);
        $publication = $this->em->getRepository(TopicPublication::class)->findOneBy(['topic' => $topic, 'format' => TopicPublication::SOCIAL]);
        self::assertNotNull($publication);
        self::assertSame('pending', $publication->status);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
        $topic = $this->em->find(Topic::class, $topic->id); $this->em->refresh($topic);
        $topic->context = 'Nouveau contexte'; $topic->changed(); $this->em->flush();
        $this->em->refresh($publication);
        self::assertSame('failed', $publication->status);
    }

    public function testPublicationFailureIsRecordedAndDuplicateMessageDoesNotRegenerate(): void
    {
        $topic = $this->topic();
        $topic->summary = 'Compte rendu global'; $topic->summaryRevision = $topic->revision; $topic->status = 'completed';
        $publication = new TopicPublication($topic, TopicPublication::NEWSLETTER); $publication->status = 'pending'; $publication->sourceRevision = $topic->revision;
        $this->em->persist($publication); $this->em->flush();
        $llm = $this->createMock(LlmProviderInterface::class);
        $llm->expects(self::once())->method('generate')->willReturn('Newsletter prête [V1]');
        $handler = new GenerateTopicPublicationHandler($this->em, new TopicPublicationPrompt(), $llm, new LockFactory(new FlockStore()), new NullLogger());
        $message = new GenerateTopicPublication($publication->id, $topic->id, $topic->revision, TopicPublication::NEWSLETTER);
        $handler($message); $handler($message);
        $publication = $this->em->find(TopicPublication::class, $publication->id);
        self::assertNotNull($publication);
        self::assertSame('completed', $publication->status);
        self::assertSame('Newsletter prête', $publication->content);
    }
    public function testMembershipChangedWhileModelRunsCannotPublishOldReport(): void
    {
        $topic = $this->topic(); $topic->status = 'pending'; $this->em->flush();
        $generator = $this->createMock(TopicSummaryGenerator::class);
        $generator->method('generate')->willReturnCallback(function () use ($topic): string {
            $this->action($topic, ['action' => 'remove', 'video_id' => $topic->videos->first()->id]);
            return 'Résultat périmé';
        });
        (new SummarizeTopicHandler($this->em, $generator, new LockFactory(new FlockStore()), new NullLogger()))(new SummarizeTopic($topic->id, $topic->revision));
        $row = $this->em->getConnection()->fetchAssociative('SELECT status, summary FROM topic WHERE id = ?', [$topic->id]);
        self::assertSame('idle', $row['status']); self::assertNull($row['summary']);
    }
    public function testVideoDeletionMakesRelatedReportStale(): void
    {
        $topic = $this->topic(); $topic->summary = 'Ancien rapport'; $topic->summaryRevision = $topic->revision; $topic->status = 'completed'; $this->em->flush();
        $crawler = $this->client->request('GET', '/videos/'.$topic->videos->first()->id.'/delete');
        $this->client->submit($crawler->selectButton('Supprimer définitivement')->form());
        self::assertResponseRedirects('/videos', 303);
        $this->em->clear(); $topic = $this->em->find(Topic::class, $topic->id);
        self::assertTrue($topic->isStale()); self::assertCount(1, $topic->videos);
        self::assertSame('Ancien rapport', $topic->summary);
    }
    public function testVideoRegenerationPreservesTextAndRequiresManualTopicGeneration(): void
    {
        $topic = $this->topic(); $topic->summary = 'Ancienne synthèse'; $topic->summaryRevision = $topic->revision; $this->em->flush();
        $video = $topic->videos->first();
        $crawler = $this->client->request('GET', '/videos/'.$video->id.'?topic='.$topic->id);
        $form = $crawler->selectButton('Régénérer le compte rendu')->form();
        $this->client->request('POST', '/videos/'.$video->id.'/regenerate', ['_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);
        $this->client->submit($form);
        self::assertResponseRedirects('/videos/'.$video->id.'?topic='.$topic->id.'#summary', 303);
        $this->client->followRedirect();
        self::assertSelectorExists('button[disabled]');
        self::assertSelectorTextContains('.prose', 'Compte rendu source');
        $this->client->submit($form); // A stale form must not enqueue twice.
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
        $topic = $this->em->find(Topic::class, $topic->id);
        $video = $this->em->find(Video::class, $video->id);
        $this->em->refresh($topic); self::assertTrue($topic->isStale());
        $youtube = $this->createMock(\App\YouTube\YouTubeVideoService::class);
        $youtube->expects(self::never())->method('fetchMetadata');
        $youtube->expects(self::never())->method('fetchTranscript');
        $generator = $this->createMock(\App\Service\VideoSummaryGenerator::class);
        $generator->expects(self::once())->method('generate')->with('Transcription source')->willReturn('Nouveau compte rendu');
        (new \App\MessageHandler\AnalyzeVideoHandler($this->em, $youtube, $generator, new LockFactory(new FlockStore()), new NullLogger()))(new \App\Message\AnalyzeVideo($video->id));
        $this->em->refresh($video);
        self::assertSame(VideoStatus::Completed, $video->getStatus());
        self::assertSame('Nouveau compte rendu', $video->summary);
        self::assertSame('Transcription source', $video->transcriptText());
        self::assertSame('idle', $topic->status);
        $this->action($topic, ['action' => 'generate']);
        self::assertSame(2, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
    }
    public function testRegenerationQueueFailureRollsBackStateAndTopicRevision(): void
    {
        $topic = $this->topic(); $video = $topic->videos->first(); $revision = $topic->revision;
        $bus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('File indisponible'));
        $service = new \App\Service\RestartVideoSummary($this->em, new LockFactory(new FlockStore()), $bus);
        try { $service->restart($video->id); self::fail('Expected failure'); }
        catch (\RuntimeException $e) { self::assertSame('File indisponible', $e->getMessage()); }
        $this->em->clear();
        self::assertSame(VideoStatus::Completed, $this->em->find(Video::class, $video->id)->getStatus());
        self::assertSame($revision, $this->em->find(Topic::class, $topic->id)->revision);
    }
    public function testActiveVideoCannotBeRestartedAndMissingTranscriptIsRejected(): void
    {
        $topic = $this->topic(); $video = $topic->videos->first();
        $locks = new LockFactory(new FlockStore()); $lock = $locks->createLock('analyze-'.$video->id); $lock->acquire();
        $bus = $this->createMock(\Symfony\Component\Messenger\MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $service = new \App\Service\RestartVideoSummary($this->em, $locks, $bus);
        try { self::assertFalse($service->restart($video->id)); } finally { $lock->release(); }
        $video->transcript = null; $this->em->flush();
        $this->expectExceptionMessage('La transcription doit être disponible');
        $service->restart($video->id);
    }
}
