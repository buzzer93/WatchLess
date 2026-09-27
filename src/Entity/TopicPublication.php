<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'topic_publication_topic_format', columns: ['topic_id', 'format'])]
class TopicPublication
{
    public const SOCIAL = 'social';
    public const NEWSLETTER = 'newsletter';
    public const BLOG = 'blog';
    public const SHORT = 'short';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;
    #[ORM\ManyToOne(targetEntity: Topic::class, inversedBy: 'publications')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public Topic $topic;
    #[ORM\Column(length: 20)]
    public string $format;
    #[ORM\Column(length: 20)]
    public string $status = 'idle';
    #[ORM\Column(nullable: true)]
    public ?int $sourceRevision = null;
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $content = null;
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $errorMessage = null;
    #[ORM\Column]
    public \DateTimeImmutable $createdAt;
    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    public function __construct(Topic $topic, string $format)
    {
        if (!in_array($format, self::formats(), true)) { throw new \InvalidArgumentException('Format de publication inconnu.'); }
        $this->topic = $topic;
        $this->format = $format;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public static function formats(): array
    {
        return [self::SOCIAL, self::NEWSLETTER, self::BLOG, self::SHORT];
    }

    public static function labels(): array
    {
        return [self::SOCIAL => 'Post réseaux sociaux', self::NEWSLETTER => 'Newsletter', self::BLOG => 'Article de blog', self::SHORT => 'Résumé court'];
    }

    public function label(): string { return self::labels()[$this->format] ?? $this->format; }
    public function isBusy(): bool { return in_array($this->status, ['pending', 'generating'], true); }
    public function isStale(): bool { return $this->content !== null && $this->sourceRevision !== $this->topic->summaryRevision; }
}
