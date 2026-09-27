<?php

namespace App\Llm;

use Psr\Log\LoggerInterface;

final class FallbackLlmProvider implements LlmProviderInterface
{
    public function __construct(
        private OpenAiCompatibleLlmProvider $local,
        private CloudLlmProvider $cloud,
        private bool $cloudEnabled,
        private LoggerInterface $logger,
    ) {
    }

    public function generate(string $prompt): string
    {
        if (!$this->cloudEnabled) {
            return $this->local->generate($prompt);
        }

        try {
            return $this->cloud->generate($prompt);
        } catch (LlmQuotaExceededException $exception) {
            $this->logger->warning('[WatchLess] Cloud LLM quota exhausted; using local fallback', [
                'reason' => $exception->getMessage(),
            ]);
            return $this->local->generate($prompt);
        }
    }
}
