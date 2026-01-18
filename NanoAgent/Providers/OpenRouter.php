<?php

declare(strict_types=1);

namespace NanoAgent\Providers;

use NanoAgent\Contracts\Provider;
use NanoAgent\Exceptions\ProviderException;
use NanoAgent\Utils\HttpClient;

/**
 * Provider implementation for the OpenRouter API.
 */
class OpenRouter implements Provider
{
    /** @var HttpClient Internal HTTP client for API requests. */
    private HttpClient $client;

    /**
     * OpenRouter Provider constructor.
     *
     * @param string $apiKey OpenRouter API Key.
     * @param string $model Model identifier (e.g., openai/gpt-3.5-turbo).
     * @param string $baseUrl API Base URL (default: https://openrouter.ai/api/v1).
     * @param string $siteUrl Optional: Your site URL for OpenRouter rankings.
     * @param string $appName Optional: Your app name for OpenRouter rankings.
     */
    public function __construct(
        private string $apiKey,
        private string $model = 'openai/gpt-3.5-turbo', // OpenRouter often requires a model, defaulting to a cheap/standard one
        private string $baseUrl = 'https://openrouter.ai/api/v1',
        private string $siteUrl = '', // Optional: Your site URL for rankings
        private string $appName = '' // Optional: Your app name for rankings
    ) {
        $this->client = new HttpClient();
    }

    /**
     * Send a synchronous chat request to OpenRouter.
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

        // Specific OpenRouter headers for identification and rankings.
        if (!empty($this->siteUrl)) {
            $headers[] = 'HTTP-Referer: ' . $this->siteUrl;
        }
        if (!empty($this->appName)) {
            $headers[] = 'X-Title: ' . $this->appName;
        }

        $body = [
            'model' => $this->model,
            'messages' => $messages,
        ];

        if (!empty($tools)) {
            $body['tools'] = $tools;
        }

        $data = $this->client->post($url, $headers, $body);

        if (isset($data['error'])) {
             throw new ProviderException('OpenRouter API Error: ' . json_encode($data['error']));
        }

        $choice = $data['choices'][0]['message'] ?? [];
        
        return [
            'content' => $choice['content'] ?? '',
            'tool_calls' => $choice['tool_calls'] ?? null,
        ];
    }

    /**
     * Stream a chat request from OpenRouter using SSE.
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

        if (!empty($this->siteUrl)) {
            $headers[] = 'HTTP-Referer: ' . $this->siteUrl;
        }
        if (!empty($this->appName)) {
            $headers[] = 'X-Title: ' . $this->appName;
        }

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
            'tool_calls' => null // Basic streaming support for now.
        ];
    }
}
