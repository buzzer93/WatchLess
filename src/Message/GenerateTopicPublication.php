<?php

namespace App\Message;

final readonly class GenerateTopicPublication
{
    public function __construct(public int $publicationId, public int $topicId, public int $revision, public string $format) {}
}
