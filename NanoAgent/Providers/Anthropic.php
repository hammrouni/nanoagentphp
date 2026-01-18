<?php

declare(strict_types=1);

namespace NanoAgent\Providers;

use NanoAgent\Contracts\Provider;
use NanoAgent\Exceptions\ProviderException;
use NanoAgent\Utils\HttpClient;

/**
 * Provider implementation for the Anthropic Claude API.
 */
class Anthropic implements Provider
{
    /** @var HttpClient Internal HTTP client for API requests. */
    private HttpClient $client;

    /**
     * Anthropic Provider constructor.
     *
     * @param string $apiKey The Anthropic API key.
     * @param string $model The model to use (default: claude-3-5-sonnet-20240620).
     * @param string $apiVersion The Anthropic API version (default: 2023-06-01).
     */
    public function __construct(
        private string $apiKey,
        private string $model = 'claude-3-5-sonnet-20240620', // Default to latest sonnet
        private string $apiVersion = '2023-06-01'
    ) {
        $this->client = new HttpClient();
    }

    /**
     * Send a synchronous chat request to Anthropic.
     * 
     * Handles the specific message format required by Anthropic, including
     * separating the system prompt and mapping tool definitions.
     *
     * @param array $messages Thread of conversation messages.
     * @param array $tools List of registered tools available for the model.
     * @return array Standardized response containing content and tool calls.
     * @throws ProviderException If the API returns an error.
     */
    public function send(array $messages, array $tools = []): array
    {
        $url = 'https://api.anthropic.com/v1/messages';
        
        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: ' . $this->apiVersion,
        ];

        // Anthropic requires the system prompt in a separate field, not in the messages array.
        $systemPrompt = '';
        $cleanMessages = [];

        foreach ($messages as $msg) {
            if ($msg['role'] === 'system') {
                $systemPrompt .= $msg['content'] . "\n";
            } else {
                $cleanMessages[] = $msg;
            }
        }

        $body = [
            'model' => $this->model,
            'messages' => $cleanMessages,
            'max_tokens' => 4096,
        ];

        if (!empty($systemPrompt)) {
            $body['system'] = trim($systemPrompt);
        }

        // Map OpenAI-style tool definitions to the format expected by Anthropic.
        if (!empty($tools)) {
            $anthropicTools = [];
            foreach ($tools as $tool) {
                // Map OpenAI-style tool definition to Anthropic format
                if (isset($tool['function'])) {
                    $anthropicTools[] = [
                        'name' => $tool['function']['name'],
                        'description' => $tool['function']['description'] ?? '',
                        'input_schema' => $tool['function']['parameters']
                    ];
                }
            }
            if (!empty($anthropicTools)) {
                $body['tools'] = $anthropicTools;
            }
        }

        $data = $this->client->post($url, $headers, $body);

        if (isset($data['error'])) {
             throw new ProviderException('Anthropic API Error: ' . json_encode($data['error']));
        }

        $content = '';
        $toolCalls = [];

        // Parse the various content blocks returned by Anthropic.
        if (isset($data['content']) && is_array($data['content'])) {
            foreach ($data['content'] as $block) {
                if ($block['type'] === 'text') {
                    $content .= $block['text'];
                } elseif ($block['type'] === 'tool_use') {
                    $toolCalls[] = [
                        'id' => $block['id'],
                        'type' => 'function',
                        'function' => [
                            'name' => $block['name'],
                            'arguments' => json_encode($block['input'])
                        ]
                    ];
                }
            }
        }
        
        return [
            'content' => $content,
            'tool_calls' => empty($toolCalls) ? null : $toolCalls,
        ];
    }

    /**
     * Stream a chat request from Anthropic.
     * 
     * Currently falls back to standard 'send' behavior until full streaming support is added.
     *
     * @param array $messages Thread of conversation messages.
     * @param callable $onToken Callback for each received token.
     * @param array $tools List of registered tools.
     * @return array The final standardized response.
     */
    public function stream(array $messages, callable $onToken, array $tools = []): array
    {
        // Simple fallback to non-streaming for now.
        $response = $this->send($messages, $tools);
        
        if (!empty($response['content'])) {
            $onToken($response['content']);
        }
        
        return $response;
    }
}
