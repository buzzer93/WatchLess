<?php
namespace App\MessageHandler;

use App\Entity\Topic;
use App\Message\SummarizeTopic;
use App\Service\TopicSummaryGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Psr\Log\LoggerInterface;

#[AsMessageHandler]
final class SummarizeTopicHandler
{
    public function __construct(private EntityManagerInterface $em, private TopicSummaryGenerator $generator, private LockFactory $locks, private LoggerInterface $logger) {}

    public function __invoke(SummarizeTopic $message): void
    {
        $lock = $this->locks->createLock('summarize-topic-'.$message->topicId);
        $lock->acquire(true);
        try {
            $topic = $this->em->find(Topic::class, $message->topicId);
            if (!$topic) { return; }
            $this->em->refresh($topic);
            if ($topic->revision !== $message->revision || !in_array($topic->status, ['pending', 'summarizing'], true)) { return; }
            if ($error = $topic->generationError()) { throw new \RuntimeException($error); }
            $sources = [];
            foreach ($topic->videos as $video) {
                $sources[] = [
                    'id' => $video->id,
                    'title' => $video->title ?? $video->youtubeId,
                    'url' => $video->url,
                    // Topic synthesis is grounded in the original speech. The
                    // per-video report remains available as a fallback only for
                    // legacy rows that have no transcript yet.
                    'transcript' => $video->transcriptText(),
                    'summary' => $video->summary,
                ];
            }
            if (!$this->save($message, ['status' => 'summarizing'])) { return; }
            $report = $this->generator->generate($topic->name, $topic->description, $topic->context, $sources, $topic->intent);
            $references = array_map(static function (array $source): array { unset($source['summary'], $source['transcript']); return $source; }, $sources);
            // A changed membership invalidates this job, even while the model is running.
            $this->save($message, ['status' => 'completed', 'summary' => $report, 'summary_revision' => $message->revision, 'summary_sources' => json_encode($references, JSON_THROW_ON_ERROR), 'error_message' => null]);
        } catch (\Throwable $e) {
            $this->save($message, ['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 1500)]);
            $this->logger->error('Topic synthesis failed', ['topic' => $message->topicId, 'reason' => $e->getMessage()]);
            throw $e;
        } finally { $lock->release(); }
    }

    private function save(SummarizeTopic $message, array $values): int
    {
        $values['updated_at'] = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        return $this->em->getConnection()->update('topic', $values, ['id' => $message->topicId, 'revision' => $message->revision]);
    }
}
