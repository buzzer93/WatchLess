<?php
namespace App\Service;

use App\Entity\Video;
use App\Message\AnalyzeVideo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;

final class RestartVideoSummary
{
    public function __construct(private EntityManagerInterface $em, private LockFactory $locks, private MessageBusInterface $bus) {}

    public function restart(int $id): bool
    {
        $lock = $this->locks->createLock('analyze-'.$id);
        if (!$lock->acquire()) { return false; }
        try {
            return $this->em->getConnection()->transactional(function () use ($id): bool {
                $video = $this->em->find(Video::class, $id) ?? throw new \InvalidArgumentException('Vidéo introuvable.');
                $this->em->refresh($video);
                if (!$video->getStatus()->terminal()) { return false; }
                if (!$video->hasTranscript()) { throw new \InvalidArgumentException('La transcription doit être disponible pour régénérer le compte rendu.'); }
                $video->restartSummary();
                $this->em->flush();
                // Invalidate any in-flight aggregate and keep its previous report readable.
                $this->em->getConnection()->executeStatement("UPDATE topic SET revision = revision + 1, status = 'idle', error_message = NULL WHERE id IN (SELECT topic_id FROM topic_video WHERE video_id = ?)", [$id]);
                $this->bus->dispatch(new AnalyzeVideo($id));
                return true;
            });
        } finally { $lock->release(); }
    }
}
