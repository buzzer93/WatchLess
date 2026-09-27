<?php

namespace App\Service;

use App\Entity\Video;
use App\Entity\Topic;
use App\Message\AnalyzeVideo;
use App\Repository\VideoRepository;
use App\YouTube\YouTubeUrl;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;

final class SubmitVideo
{
    public function __construct(private YouTubeUrl $urls, private VideoRepository $videos, private EntityManagerInterface $em,
        private MessageBusInterface $bus, private LockFactory $lockFactory, private LoggerInterface $logger) {}

    public function submit(string $url, ?Topic $topic = null): int
    {
        $youtubeId = $this->urls->extractId($url);
        $lock = $this->lockFactory->createLock('submit-'.$youtubeId);
        $lock->acquire(true);
        try {
            // The Doctrine queue uses this same connection: insert and dispatch commit together.
            return $this->em->getConnection()->transactional(function () use ($youtubeId, $topic): int {
                $video = $this->videos->findOneBy(['youtubeId' => $youtubeId]);
                if (!$video) {
                    $video = new Video($youtubeId);
                    $this->em->persist($video);
                    $this->em->flush();
                    $this->bus->dispatch(new AnalyzeVideo($video->id));
                    $this->logger->info('[WatchLess] Video submitted', ['youtubeId' => $youtubeId]);
                }
                if ($topic && !$topic->videos->contains($video)) {
                    $topic->videos->add($video);
                    $topic->changed();
                    $this->em->flush();
                }
                return $video->id;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($topic) { throw $e; }
            $id = $this->em->getConnection()->fetchOne('SELECT id FROM video WHERE youtube_id = ?', [$youtubeId]);
            if ($id !== false) { return (int) $id; }
            throw $e;
        } finally { $lock->release(); }
    }
}
