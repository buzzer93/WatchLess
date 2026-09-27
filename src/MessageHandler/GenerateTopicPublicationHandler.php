<?php

namespace App\MessageHandler;

use App\Entity\TopicPublication;
use App\Message\GenerateTopicPublication;
use App\Prompt\TopicPublicationPrompt;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class GenerateTopicPublicationHandler
{
    public function __construct(private EntityManagerInterface $em, private TopicPublicationPrompt $prompt, private \App\Llm\LlmProviderInterface $llm, private LockFactory $locks, private LoggerInterface $logger) {}

    public function __invoke(GenerateTopicPublication $message): void
    {
        $lock = $this->locks->createLock('topic-publication-'.$message->publicationId);
        $lock->acquire(true);
        $publication = null;
        try {
            $publication = $this->em->find(TopicPublication::class, $message->publicationId);
            if (!$publication) { return; }
            $this->em->refresh($publication);
            $topic = $publication->topic;
            $this->em->refresh($topic);
            if ($publication->format !== $message->format || $topic->revision !== $message->revision || !$topic->summary || $topic->summaryRevision !== $message->revision) {
                $this->failIfCurrent($publication, $message, 'Le sujet a changé. Relancez cette publication.');
                return;
            }
            if (!in_array($publication->status, ['pending', 'generating'], true)) { return; }
            $publication->status = 'generating';
            $publication->updatedAt = new \DateTimeImmutable();
            $this->em->flush();
            $content = $this->llm->generate($this->prompt->generate($message->format, $topic->name, $topic->context, $topic->summary));
            // Source markers are useful in the internal report, but publication
            // formats must be ready to copy without exposing internal IDs.
            $content = trim((string) (preg_replace('/\[V\d+\]\s*/u', '', $content) ?? $content));
            $this->em->clear();
            $publication = $this->em->find(TopicPublication::class, $message->publicationId);
            $topic = $publication?->topic;
            if (!$publication || !$topic || $topic->revision !== $message->revision || $topic->summaryRevision !== $message->revision) {
                if ($publication && in_array($publication->status, ['pending', 'generating'], true)) {
                    $publication->status = 'failed';
                    $publication->errorMessage = 'Le sujet a changé pendant la génération. Relancez cette publication.';
                    $publication->updatedAt = new \DateTimeImmutable();
                    $this->em->flush();
                }
                return;
            }
            $publication->content = $content;
            $publication->sourceRevision = $message->revision;
            $publication->status = 'completed';
            $publication->errorMessage = null;
            $publication->updatedAt = new \DateTimeImmutable();
            $this->em->flush();
        } catch (\Throwable $exception) {
            if ($publication && $this->em->isOpen()) {
                $this->em->clear();
                $publication = $this->em->find(TopicPublication::class, $message->publicationId);
                if ($publication && in_array($publication->status, ['pending', 'generating'], true)) {
                    $publication->status = 'failed';
                    $publication->errorMessage = mb_substr($exception->getMessage(), 0, 1500);
                    $publication->updatedAt = new \DateTimeImmutable();
                    $this->em->flush();
                }
            }
            $this->logger->error('[WatchLess] Topic publication failed', ['publication' => $message->publicationId, 'reason' => $exception->getMessage()]);
            throw $exception;
        } finally { $lock->release(); }
    }

    private function failIfCurrent(TopicPublication $publication, GenerateTopicPublication $message, string $reason): void
    {
        if (!in_array($publication->status, ['pending', 'generating'], true)) { return; }
        $publication->status = 'failed';
        $publication->errorMessage = $reason;
        $publication->updatedAt = new \DateTimeImmutable();
        $this->em->flush();
    }
}
