<?php

namespace App\Message;

final readonly class AnalyzeVideo
{
    public function __construct(public int $videoId) {}
}
