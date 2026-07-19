<?php

declare(strict_types=1);

namespace NanoAgent\Providers;

/**
 * Provider implementation for the DeepSeek API.
 */
class DeepSeek extends OpenAICompatibleProvider
{
    /**
     * DeepSeek Provider constructor.
     *
     * @param string $apiKey DeepSeek API Key.
     * @param string $model Model identifier (default: deepseek-chat).
     * @param string $baseUrl API Base URL (default: https://api.deepseek.com).
     * @param array $options Extra request body parameters merged into every call
     *                       (e.g. 'temperature', 'max_tokens', 'top_p'). Cannot
     *                       override 'model', 'messages', or 'stream'.
     */
    public function __construct(
        string $apiKey,
        string $model = 'deepseek-chat',
        string $baseUrl = 'https://api.deepseek.com',
        array $options = []
    ) {
        parent::__construct($apiKey, $model, $baseUrl, $options);
    }

    protected function providerLabel(): string
    {
        return 'DeepSeek';
    }
}
