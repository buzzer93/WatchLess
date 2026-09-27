<?php

namespace App\Llm;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CloudLlmProvider extends OpenAiCompatibleLlmProvider
{
    public function __construct(
        HttpClientInterface $http,
        #[Autowire('%env(LLM_CLOUD_BASE_URL)%')] string $baseUrl,
        #[Autowire('%env(LLM_CLOUD_API_KEY)%')] string $apiKey,
        #[Autowire('%env(LLM_CLOUD_MODEL)%')] string $model,
        #[Autowire('%env(int:LLM_CLOUD_MAX_OUTPUT_TOKENS)%')] int $maxOutputTokens,
        #[Autowire('%env(LLM_CLOUD_TOKEN_PARAMETER)%')] string $tokenParameter = 'max_completion_tokens',
        #[Autowire('%env(LLM_CLOUD_REASONING_EFFORT)%')] string $reasoningEffort = '',
        #[Autowire('%env(int:LLM_CLOUD_TIMEOUT)%')] int $timeout = 1800,
    ) {
        parent::__construct($http, $baseUrl, $apiKey, $model, $maxOutputTokens, $tokenParameter, $reasoningEffort, $timeout);
    }
}
