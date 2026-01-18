<?php

declare(strict_types=1);

namespace NanoAgent\Providers;

use NanoAgent\Contracts\Provider;

/**
 * Mock provider for testing and development.
 * 
 * Allows for simulating AI responses without making actual API calls.
 */
class MockProvider implements Provider
{
    /**
     * MockProvider constructor.
     *
     * @param array $responses A queue of responses to be returned in sequence.
     */
    public function __construct(
        private array $responses = []
    ) {}

    /**
     * Return the next mock response from the queue.
     *
     * @param array $messages Ignored in mock.
     * @param array $tools Ignored in mock.
     * @return array The mocked response structure.
     */
    public function send(array $messages, array $tools = []): array
    {
        // Retrieve and remove the next response from the front of the queue.
        $response = array_shift($this->responses) ?? ['content' => 'Mock response'];
        
        // Ensure the returned array has the required keys.
        return array_merge([
            'content' => '',
            'tool_calls' => []
        ], $response);
    }

    /**
     * Simulate a streaming response using mocked data.
     *
     * @param array $messages Thread of conversation messages.
     * @param callable $onToken Callback for each "token".
     * @param array $tools List of registered tools.
     * @return array The final standardized response.
     */
    public function stream(array $messages, callable $onToken, array $tools = []): array
    {
        $response = $this->send($messages, $tools);
        
        // Simulate streaming by invoking the callback with the full content at once.
        if (!empty($response['content'])) {
            $onToken($response['content']);
        }
        
        return $response;
    }
}
