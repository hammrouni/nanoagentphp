<?php

declare(strict_types=1);

namespace NanoAgent\Providers;

use NanoAgent\Contracts\Provider;
use NanoAgent\Exceptions\ProviderException;
use NanoAgent\Utils\HttpClient;

/**
 * Provider implementation for the DeepSeek API.
 */
class DeepSeek implements Provider
{
    /** @var HttpClient Internal HTTP client for API requests. */
    private HttpClient $client;

    /**
     * DeepSeek Provider constructor.
     *
     * @param string $apiKey DeepSeek API Key.
     * @param string $model Model identifier (default: deepseek-chat).
     * @param string $baseUrl API Base URL (default: https://api.deepseek.com).
     */
    public function __construct(
        private string $apiKey,
        private string $model = 'deepseek-chat',
        private string $baseUrl = 'https://api.deepseek.com'
    ) {
        $this->client = new HttpClient();
    }

    /**
     * Send a synchronous chat request to DeepSeek.
     *
     * @param array $messages Thread of conversation messages.
     * @param array $tools List of registered tools.
     * @return array Standardized response containing content and tool calls.
     * @throws ProviderException If the API returns an error.
     */
    public function send(array $messages, array $tools = []): array
    {
        $url = $this->baseUrl . '/chat/completions';
        
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];

        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => false
        ];

        if (!empty($tools)) {
            $body['tools'] = $tools;
        }

        $data = $this->client->post($url, $headers, $body);

        if (isset($data['error'])) {
             throw new ProviderException('DeepSeek API Error: ' . json_encode($data['error']));
        }

        $choice = $data['choices'][0]['message'] ?? [];
        
        return [
            'content' => $choice['content'] ?? '',
            'tool_calls' => $choice['tool_calls'] ?? null,
        ];
    }

    /**
     * Stream a chat request from DeepSeek using SSE.
     *
     * @param array $messages Thread of conversation messages.
     * @param callable $onToken Callback invoked for each received token.
     * @param array $tools List of registered tools.
     * @return array The final aggregated response.
     */
    public function stream(array $messages, callable $onToken, array $tools = []): array
    {
        $url = $this->baseUrl . '/chat/completions';
        
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ];

        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => true
        ];

        if (!empty($tools)) {
            $body['tools'] = $tools;
        }

        $fullContent = '';
        $buffer = '';

        $this->client->stream($url, $headers, $body, function ($chunk) use ($onToken, &$fullContent, &$buffer) {
            $buffer .= $chunk;
            
            // SSE events are separated by double newlines.
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $event = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);
                
                // Parse the "data: " prefix common in SSE streams.
                if (str_starts_with($event, 'data: ')) {
                    $json = substr($event, 6);
                    
                    if (trim($json) === '[DONE]') {
                        continue;
                    }

                    $data = json_decode($json, true);
                    
                    if (isset($data['choices'][0]['delta']['content'])) {
                        $content = $data['choices'][0]['delta']['content'];
                        $fullContent .= $content;
                        $onToken($content);
                    }
                }
            }
        });

        return [
            'content' => $fullContent,
            'tool_calls' => null // Basic streaming support; tool calls in stream not yet fully supported.
        ];
    }
}
