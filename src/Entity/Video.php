<?php

namespace App\Entity;

use App\Enum\VideoStatus;
use App\Repository\VideoRepository;
use App\Transcription\TranscriptionResult;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: VideoRepository::class)]
#[ORM\Index(columns: ['created_at'])]
class Video
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;
    #[ORM\Column(length: 11, unique: true)]
    public string $youtubeId;
    #[ORM\Column(length: 255)]
    public string $url;
    #[ORM\Column(length: 1000, nullable: true)]
    public ?string $title = null;
    #[ORM\Column(length: 500, nullable: true)]
    public ?string $channelName = null;
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $thumbnailUrl = null;
    #[ORM\Column(nullable: true)]
    public ?int $duration = null;
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $publishedAt = null;
    #[ORM\Column(enumType: VideoStatus::class)]
    private VideoStatus $status = VideoStatus::Pending;
    /**
     * Canonical transcription storage. When segments are available this value
     * includes one timestamp per line; plain text is derived by transcriptText().
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $transcript = null;
    #[ORM\Column(length: 100, nullable: true)]
    public ?string $transcriptLanguage = null;
    #[ORM\Column(length: 100, nullable: true)]
    public ?string $transcriptSource = null;
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $summary = null;
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $errorMessage = null;
    #[ORM\Column]
    public \DateTimeImmutable $createdAt;
    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct(string $youtubeId)
    {
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/D', $youtubeId)) {
            throw new \InvalidArgumentException('Identifiant YouTube invalide.');
        }
        $this->youtubeId = $youtubeId;
        $this->url = 'https://www.youtube.com/watch?v='.$youtubeId;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getStatus(): VideoStatus { return $this->status; }

    public function hasTranscript(): bool { return trim((string) $this->transcript) !== ''; }

    public function transcriptText(): ?string
    {
        if (!$this->hasTranscript()) { return null; }
        return preg_replace('/^\d{2}:\d{2}:\d{2}\s+/m', '', (string) $this->transcript) ?? $this->transcript;
    }

    public function restartSummary(): void
    {
        if (!$this->status->terminal() || !$this->hasTranscript()) {
            throw new \LogicException('La relance nécessite une transcription et un traitement terminé ou interrompu.');
        }
        $this->status = VideoStatus::Transcribed;
        $this->errorMessage = null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function transition(VideoStatus $next): void
    {
        $allowed = match ($this->status) {
            VideoStatus::Pending => [VideoStatus::Metadata],
            VideoStatus::Metadata => [VideoStatus::Transcribing],
            VideoStatus::Transcribing => [VideoStatus::Transcribed],
            VideoStatus::Transcribed => [VideoStatus::Summarizing],
            VideoStatus::Summarizing => [VideoStatus::Completed],
            VideoStatus::Failed => [VideoStatus::Pending],
            VideoStatus::Completed => [],
        };
        if (!in_array($next, $allowed, true) && !($next === VideoStatus::Failed && !$this->status->terminal())) {
            throw new \LogicException(sprintf('Transition interdite : %s → %s', $this->status->value, $next->value));
        }
        if ($next === VideoStatus::Transcribed && !$this->hasTranscript()) {
            throw new \LogicException('La transcription doit être enregistrée.');
        }
        if ($next === VideoStatus::Completed && !$this->summary) {
            throw new \LogicException('Le compte rendu doit être enregistré.');
        }
        $this->status = $next;
        $this->updatedAt = new \DateTimeImmutable();
        if ($next !== VideoStatus::Failed) { $this->errorMessage = null; }
    }

    public function setTranscript(TranscriptionResult $result): void
    {
        $this->transcript = $result->timestampedText() ?? $result->text;
        $this->transcriptLanguage = $result->language;
        $this->transcriptSource = $result->source;
    }
}
