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

    /** @var array List of captured requests for inspection. */
    private array $capturedRequests = [];

    /**
     * Return the next mock response from the queue.
     *
     * @param array $messages Ignored in mock.
     * @param array $tools Ignored in mock.
     * @return array The mocked response structure.
     */
    public function send(array $messages, array $tools = []): array
    {
        $this->capturedRequests[] = [
            'type' => 'send',
            'messages' => $messages,
            'tools' => $tools
        ];

        // Retrieve and remove the next response from the front of the queue.
        $response = array_shift($this->responses) ?? ['content' => 'Mock response'];
        
        // Ensure the returned array has the required keys.
        return array_merge([
            'content' => '',
            'tool_calls' => []
        ], $response);
    }

    public function stream(array $messages, callable $onToken, array $tools = []): array
    {
        $this->capturedRequests[] = [
            'type' => 'stream',
            'messages' => $messages,
            'tools' => $tools
        ];

        // Retrieve and remove the next response from the front of the queue.
        $response = array_shift($this->responses) ?? ['content' => 'Mock response'];
        
        $response = array_merge([
            'content' => '',
            'tool_calls' => []
        ], $response);
        
        // Simulate streaming by invoking the callback with the full content at once.
        if (!empty($response['content'])) {
            $onToken($response['content']);
        }
        
        return $response;
    }

    /**
     * Retrieve captured requests for assertion.
     * 
     * @return array
     */
    public function getCapturedRequests(): array
    {
        return $this->capturedRequests;
    }
}
