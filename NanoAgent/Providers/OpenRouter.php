<?php

declare(strict_types=1);

namespace NanoAgent\Providers;

/**
 * Provider implementation for the OpenRouter API.
 */
class OpenRouter extends OpenAICompatibleProvider
{
    /**
     * OpenRouter Provider constructor.
     *
     * @param string $apiKey OpenRouter API Key.
     * @param string $model Model identifier (e.g., openai/gpt-3.5-turbo).
     * @param string $baseUrl API Base URL (default: https://openrouter.ai/api/v1).
     * @param string $siteUrl Optional: Your site URL for OpenRouter rankings.
     * @param string $appName Optional: Your app name for OpenRouter rankings.
     * @param array $options Extra request body parameters merged into every call
     *                       (e.g. 'temperature', 'max_tokens', 'top_p'). Cannot
     *                       override 'model', 'messages', or 'stream'.
     */
    public function __construct(
        string $apiKey,
        string $model = 'openai/gpt-3.5-turbo', // OpenRouter often requires a model, defaulting to a cheap/standard one
        string $baseUrl = 'https://openrouter.ai/api/v1',
        private string $siteUrl = '', // Optional: Your site URL for rankings
        private string $appName = '', // Optional: Your app name for rankings
        array $options = []
    ) {
        parent::__construct($apiKey, $model, $baseUrl, $options);
    }

    protected function providerLabel(): string
    {
        return 'OpenRouter';
    }

    /**
     * Adds OpenRouter's optional identification/ranking headers.
     *
     * @return string[]
     */
    protected function extraHeaders(): array
    {
        $headers = [];

        if (!empty($this->siteUrl)) {
            $headers[] = 'HTTP-Referer: ' . $this->siteUrl;
        }
        if (!empty($this->appName)) {
            $headers[] = 'X-Title: ' . $this->appName;
        }

        return $headers;
    }
}
