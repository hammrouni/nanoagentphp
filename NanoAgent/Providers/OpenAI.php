<?php

declare(strict_types=1);

namespace NanoAgent\Providers;

/**
 * Provider implementation for the OpenAI API.
 */
class OpenAI extends OpenAICompatibleProvider
{
    /**
     * OpenAI Provider constructor.
     *
     * @param string $apiKey OpenAI API Key.
     * @param string $model Model identifier (default: gpt-4o).
     * @param string $baseUrl API Base URL (default: https://api.openai.com/v1).
     * @param array $options Extra request body parameters merged into every call
     *                       (e.g. 'temperature', 'max_tokens', 'top_p'). Cannot
     *                       override 'model', 'messages', or 'stream'.
     */
    public function __construct(
        string $apiKey,
        string $model = 'gpt-4o',
        string $baseUrl = 'https://api.openai.com/v1',
        array $options = []
    ) {
        parent::__construct($apiKey, $model, $baseUrl, $options);
    }

    protected function providerLabel(): string
    {
        return 'OpenAI';
    }
}
