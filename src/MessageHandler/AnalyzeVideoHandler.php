<?php

namespace App\MessageHandler;

use App\Entity\Video;
use App\Enum\VideoStatus;
use App\Message\AnalyzeVideo;
use App\Service\VideoSummaryGenerator;
use App\YouTube\YouTubeVideoService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class AnalyzeVideoHandler
{
    public function __construct(private EntityManagerInterface $em, private YouTubeVideoService $youtube,
        private VideoSummaryGenerator $summaries, private LockFactory $lockFactory, private LoggerInterface $logger) {}

    public function __invoke(AnalyzeVideo $message): void
    {
        $lock = $this->lockFactory->createLock('analyze-'.$message->videoId);
        $lock->acquire(true);
        $video = null;
        try {
            $video = $this->em->find(Video::class, $message->videoId);
            if (!$video) { return; }
            $this->em->refresh($video);
            if ($video->getStatus() === VideoStatus::Completed) { return; }
            if ($video->getStatus() === VideoStatus::Failed) { $this->step($video, VideoStatus::Pending); }
            $metadata = null;
            if ($video->getStatus() === VideoStatus::Pending) { $this->step($video, VideoStatus::Metadata); }
            if ($video->getStatus() === VideoStatus::Metadata) {
                if (!$video->hasTranscript()) { $metadata = $this->youtube->fetchMetadata($video); }
                $this->step($video, VideoStatus::Transcribing);
            }
            if ($video->getStatus() === VideoStatus::Transcribing) {
                if (!$video->hasTranscript()) {
                    $metadata ??= $this->youtube->fetchMetadata($video);
                    $video->setTranscript($this->youtube->fetchTranscript($video, $metadata));
                }
                $this->step($video, VideoStatus::Transcribed);
            }
            if ($video->getStatus() === VideoStatus::Transcribed) { $this->step($video, VideoStatus::Summarizing); }
            if ($video->getStatus() === VideoStatus::Summarizing) {
                $video->summary = $this->summaries->generate($video->transcriptText() ?? '');
                $this->step($video, VideoStatus::Completed);
            }
        } catch (\Throwable $e) {
            $stage = $video?->getStatus()->value ?? 'loading';
            $this->logger->error('[WatchLess] Processing failed', ['video' => $message->videoId, 'stage' => $stage, 'reason' => $e->getMessage()]);
            if ($video && $this->em->isOpen() && !$video->getStatus()->terminal()) {
                $video->transition(VideoStatus::Failed);
                $video->errorMessage = '['.$stage.'] '.mb_substr($e->getMessage(), 0, 1500);
                $this->em->flush();
            }
            throw $e;
        } finally { $lock->release(); }
    }

    private function step(Video $video, VideoStatus $status): void
    {
        $video->transition($status);
        $this->em->flush();
        $this->logger->info('[WatchLess] '.$status->value, ['video' => $video->id]);
    }
}
