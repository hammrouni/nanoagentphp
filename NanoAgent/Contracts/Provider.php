<?php

declare(strict_types=1);

namespace NanoAgent\Contracts;

/**
 * Interface for AI model providers.
 */
interface Provider
{
    /**
     * Sends a set of messages and tools to the AI provider.
     *
     * @param array $messages Conversation history/messages.
     * @param array $tools Available tool definitions.
     * @return array The provider's response, including content and tool calls.
     */
    public function send(array $messages, array $tools = []): array;

    /**
     * Streams the response from the AI provider.
     *
     * @param array $messages Conversation history/messages.
     * @param callable $onToken Callback function to handle each token/chunk.
     * @param array $tools Available tool definitions.
     * @return array The final accumulated response, including content and tool calls.
     */
    public function stream(array $messages, callable $onToken, array $tools = []): array;
}
