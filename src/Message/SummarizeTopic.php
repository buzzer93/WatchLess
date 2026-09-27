<?php
namespace App\Message;
final readonly class SummarizeTopic
{
    public function __construct(public int $topicId, public int $revision) {}
}
