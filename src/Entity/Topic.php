<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Topic
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;
    #[ORM\Column(length: 200)]
    public string $name;
    #[ORM\Column(type: Types::TEXT)]
    public string $description = '';
    #[ORM\Column(type: Types::TEXT)]
    public string $context = '';
    #[ORM\Column(length: 30)]
    public string $intent = '';
    #[ORM\ManyToMany(targetEntity: Video::class)]
    #[ORM\JoinTable(name: 'topic_video')]
    #[ORM\JoinColumn(name: 'topic_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'video_id', onDelete: 'CASCADE')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    public Collection $videos;
    #[ORM\Column(length: 20)]
    public string $status = 'idle';
    #[ORM\Column]
    public int $revision = 1;
    #[ORM\Column(nullable: true)]
    public ?int $summaryRevision = null;
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $summary = null;
    #[ORM\Column(type: Types::JSON)]
    public array $summarySources = [];
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $errorMessage = null;
    #[ORM\Column]
    public \DateTimeImmutable $createdAt;
    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;
    #[ORM\OneToMany(mappedBy: 'topic', targetEntity: TopicPublication::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    public Collection $publications;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->videos = new ArrayCollection();
        $this->publications = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public static function intents(): array
    {
        return [
            '' => 'Général',
            'veille' => 'Veille',
            'apprentissage' => 'Apprentissage',
            'projet' => 'Projet / action',
            'decision' => 'Aide à la décision',
            'comparaison' => 'Comparaison',
            'inspiration' => 'Inspiration',
        ];
    }

    public function intentLabel(): string { return self::intents()[$this->intent] ?? self::intents()['']; }

    public function changed(): void
    {
        ++$this->revision;
        $this->status = 'idle';
        $this->errorMessage = null;
        $this->updatedAt = new \DateTimeImmutable();
        foreach ($this->publications as $publication) {
            if ($publication->isBusy()) {
                $publication->status = 'failed';
                $publication->errorMessage = 'Le sujet a changé. Relancez cette publication.';
                $publication->updatedAt = new \DateTimeImmutable();
            }
        }
    }

    public function isBusy(): bool { return in_array($this->status, ['pending', 'summarizing'], true); }
    public function hasWorkInProgress(): bool
    {
        if ($this->isBusy()) { return true; }
        foreach ($this->videos as $video) { if (!$video->getStatus()->terminal()) { return true; } }
        foreach ($this->publications as $publication) { if ($publication->isBusy()) { return true; } }
        return false;
    }
    public function progressKey(): string
    {
        $parts = [$this->status, (string) $this->revision];
        foreach ($this->videos as $video) { $parts[] = $video->id.':'.$video->getStatus()->value; }
        foreach ($this->publications as $publication) { $parts[] = 'p'.$publication->id.':'.$publication->status; }
        return implode('|', $parts);
    }
    public function isStale(): bool { return $this->summary !== null && $this->summaryRevision !== $this->revision; }
    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Synthèse en attente', 'summarizing' => 'Synthèse en cours…',
            'completed' => 'Synthèse terminée', 'failed' => 'Échec de la synthèse',
            default => $this->isStale() ? 'Synthèse à actualiser' : 'Prêt à préparer',
        };
    }
    public function generationError(): ?string
    {
        if ($this->videos->count() < 2) { return 'Ajoutez au moins deux vidéos pour comparer leurs comptes rendus.'; }
        foreach ($this->videos as $video) {
            if ($video->getStatus() !== \App\Enum\VideoStatus::Completed || !$video->summary) {
                return 'Attendez que toutes les vidéos du sujet disposent d’un compte rendu terminé.';
            }
        }
        return null;
    }
}
