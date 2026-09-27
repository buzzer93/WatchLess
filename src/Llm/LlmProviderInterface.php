<?php

namespace App\Llm;

interface LlmProviderInterface
{
    public function generate(string $prompt): string;
}
