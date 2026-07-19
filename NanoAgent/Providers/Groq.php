<?php

declare(strict_types=1);

namespace NanoAgent\Providers;

/**
 * Provider implementation for the Groq Cloud API.
 */
class Groq extends OpenAICompatibleProvider
{
    /**
     * Groq Provider constructor.
     *
     * @param string $apiKey Groq API Key.
     * @param string $model Model identifier (default: llama-3.3-70b-versatile).
     * @param string $baseUrl API Base URL (default: https://api.groq.com/openai/v1).
     * @param array $options Extra request body parameters merged into every call
     *                       (e.g. 'temperature', 'max_tokens', 'top_p'). Cannot
     *                       override 'model', 'messages', or 'stream'.
     */
    public function __construct(
        string $apiKey,
        string $model = 'llama-3.3-70b-versatile',
        string $baseUrl = 'https://api.groq.com/openai/v1',
        array $options = []
    ) {
        parent::__construct($apiKey, $model, $baseUrl, $options);
    }

    protected function providerLabel(): string
    {
        return 'Groq';
    }
}
