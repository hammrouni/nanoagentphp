<?php

declare(strict_types=1);

namespace NanoAgent\Providers;

use NanoAgent\Contracts\Provider;
use NanoAgent\Exceptions\ProviderException;
use NanoAgent\Utils\HttpClient;

/**
 * Base implementation for providers exposing an OpenAI-compatible
 * "/chat/completions" API (OpenAI itself, Groq, DeepSeek, OpenRouter, ...).
 *
 * Concrete providers only need to supply their own constructor defaults
 * (model/base URL) and a label for error messages; anything provider-specific
 * beyond that (e.g. OpenRouter's ranking headers) can be added by overriding
 * extraHeaders().
 */
abstract class OpenAICompatibleProvider implements Provider
{
    /** @var HttpClient Internal HTTP client for API requests. */
    protected HttpClient $client;

    /**
     * @param string $apiKey API key for the provider.
     * @param string $model Model identifier.
     * @param string $baseUrl API base URL (without the trailing "/chat/completions").
     * @param array $options Extra request body parameters merged into every call
     *                       (e.g. 'temperature', 'max_tokens', 'top_p'). Cannot
     *                       override 'model', 'messages', or 'stream'.
     */
    public function __construct(
        protected string $apiKey,
        protected string $model,
        protected string $baseUrl,
        protected array $options = []
    ) {
        $this->client = new HttpClient();
    }

    /**
     * Human-readable provider name used to prefix API error messages (e.g. "OpenAI").
     *
     * @return string
     */
    abstract protected function providerLabel(): string;

    /**
     * Additional headers beyond Content-Type/Authorization. Override to add
     * provider-specific headers (e.g. OpenRouter's ranking headers).
     *
     * @return string[]
     */
    protected function extraHeaders(): array
    {
        return [];
    }

    /**
     * The full chat completions endpoint URL.
     *
     * @return string
     */
    protected function endpoint(): string
    {
        return $this->baseUrl . '/chat/completions';
    }

    /**
     * Assemble the request headers for this provider.
     *
     * @return string[]
     */
    protected function headers(): array
    {
        return array_merge([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ], $this->extraHeaders());
    }

    /**
     * Send a synchronous chat request.
     *
     * @param array $messages Thread of conversation messages.
     * @param array $tools List of registered tools.
     * @return array Standardized response containing content and tool calls.
     * @throws ProviderException If the API returns an error.
     */
    public function send(array $messages, array $tools = []): array
    {
        $body = array_merge($this->options, [
            'model' => $this->model,
            'messages' => $messages,
        ]);

        if (!empty($tools)) {
            $body['tools'] = $tools;
        }

        $data = $this->client->post($this->endpoint(), $this->headers(), $body);

        if (isset($data['error'])) {
            throw new ProviderException(
                $this->providerLabel() . ' API Error: ' . json_encode($data['error'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        $choice = $data['choices'][0]['message'] ?? [];

        return [
            'content' => $choice['content'] ?? '',
            'tool_calls' => $choice['tool_calls'] ?? null,
        ];
    }

    /**
     * Stream a chat request using SSE.
     *
     * Tool-call deltas are accumulated across chunks (OpenAI-style streaming sends
     * the tool call's id/name once, then streams the JSON arguments incrementally
     * per SSE 'index') and returned in the same shape send() would return them in.
     * Only text content deltas are forwarded to $onToken.
     *
     * @param array $messages Thread of conversation messages.
     * @param callable $onToken Callback invoked for each received text token.
     * @param array $tools List of registered tools.
     * @return array The final aggregated response.
     */
    public function stream(array $messages, callable $onToken, array $tools = []): array
    {
        $body = array_merge($this->options, [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => true
        ]);

        if (!empty($tools)) {
            $body['tools'] = $tools;
        }

        $fullContent = '';
        $buffer = '';
        $toolCallsByIndex = [];

        $this->client->stream($this->endpoint(), $this->headers(), $body, function ($chunk) use ($onToken, &$fullContent, &$buffer, &$toolCallsByIndex) {
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
                    $delta = $data['choices'][0]['delta'] ?? [];

                    if (isset($delta['content'])) {
                        $content = $delta['content'];
                        $fullContent .= $content;
                        $onToken($content);
                    }

                    if (!empty($delta['tool_calls'])) {
                        foreach ($delta['tool_calls'] as $toolCallDelta) {
                            $index = $toolCallDelta['index'] ?? 0;

                            if (!isset($toolCallsByIndex[$index])) {
                                $toolCallsByIndex[$index] = [
                                    'id' => '',
                                    'type' => 'function',
                                    'function' => ['name' => '', 'arguments' => ''],
                                ];
                            }

                            if (isset($toolCallDelta['id'])) {
                                $toolCallsByIndex[$index]['id'] = $toolCallDelta['id'];
                            }
                            if (isset($toolCallDelta['type'])) {
                                $toolCallsByIndex[$index]['type'] = $toolCallDelta['type'];
                            }
                            // The tool name arrives whole in a single delta; arguments
                            // stream incrementally and must be concatenated.
                            if (isset($toolCallDelta['function']['name'])) {
                                $toolCallsByIndex[$index]['function']['name'] = $toolCallDelta['function']['name'];
                            }
                            if (isset($toolCallDelta['function']['arguments'])) {
                                $toolCallsByIndex[$index]['function']['arguments'] .= $toolCallDelta['function']['arguments'];
                            }
                        }
                    }
                }
            }
        });

        ksort($toolCallsByIndex);
        $toolCalls = array_values($toolCallsByIndex);

        return [
            'content' => $fullContent,
            'tool_calls' => empty($toolCalls) ? null : $toolCalls,
        ];
    }
}
