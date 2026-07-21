<?php

declare(strict_types=1);

namespace NanoAgent;

use NanoAgent\Contracts\Provider;

use NanoAgent\Utils\ContextBuilder;

/**
 * The main Agent class responsible for managing conversation history,
 * resolving AI providers, and handling the core interaction loop including tool execution.
 */
class Agent
{
    /**
     * Library Version
     */
    public const VERSION = '0.4.0';

    /** @var array<array{role: string, content: string, tool_calls?: array}> Internal storage for the conversation's message history. */
    private array $history = [];

    /** @var array<string, string> Key-value pairs of context strings to be injected into the system prompt. */
    private array $context = [];

    /** @var array<string, \NanoAgent\Contracts\Tool> Map of tool names to their respective Tool instances. */
    private array $tools = [];

    /** @var callable|null An optional callback for monitoring agent events (logging, debugging). */
    private $onEvent = null;

    /** @var Provider The backend AI provider instance (e.g., OpenAI, Groq) resolved for this agent. */
    private Provider $provider;

    /** @var array The configuration parameters used for the LLM. */
    private array $llmConfig = [];

    /** @var int Maximum number of tool-calling round-trips allowed within a single chat() call. */
    private int $maxIterations = 10;

    /**
     * Agent constructor.
     *
     * @param array|Provider $llm Either configuration for the LLM (should contain 'model'
     *                             and 'api_key'), or a ready-made Provider instance to use
     *                             directly — handy for injecting a MockProvider in tests or
     *                             a custom Provider implementation without going through
     *                             string-based config resolution. See also setProvider().
     * @param string $systemPrompt The base instructions that define the agent's behavior.
     * @param array $tools Initial set of tools to register with the agent.
     */
    public function __construct(
        array|Provider $llm = [],
        private string $systemPrompt = '',
        array $tools = []
    ) {
        if ($llm instanceof Provider) {
            $this->provider = $llm;
        } else {
            // Automatically attempt to load configuration from the default config.php file if none provided.
            if (empty($llm)) {
                $configPath = __DIR__ . '/config.php';
                if (file_exists($configPath)) {
                    $config = require $configPath;
                    $llm = $config;
                }
            }

            $this->llmConfig = $llm;

            if (isset($llm['max_iterations'])) {
                $this->maxIterations = (int) $llm['max_iterations'];
            }

            $this->provider = $this->resolveProvider($llm);
        }

        foreach ($tools as $tool) {
            $this->registerTool($tool);
        }
    }

    /**
     * Replace the backend Provider after construction.
     *
     * Primarily useful for tests and for swapping providers at runtime
     * (e.g. failing over from one API to another) without rebuilding the Agent.
     *
     * @param Provider $provider
     */
    public function setProvider(Provider $provider): void
    {
        $this->provider = $provider;
    }

    /**
     * Override the maximum number of tool-calling iterations per chat() call.
     *
     * Useful when a Provider was injected directly (constructor 'llm' config array
     * wasn't used), since 'max_iterations' is normally read from that array.
     *
     * @param int $maxIterations
     */
    public function setMaxIterations(int $maxIterations): void
    {
        $this->maxIterations = $maxIterations;
    }

    /**
     * Retrieve the current LLM configuration.
     *
     * @return array
     */
    public function getLlmConfig(): array
    {
        return $this->llmConfig;
    }

    /**
     * Retrieve the configured maximum number of tool-calling iterations per chat() call.
     *
     * @return int
     */
    public function getMaxIterations(): int
    {
        return $this->maxIterations;
    }

    /**
     * Factory method to resolve and instantiate the AI Provider based on configuration.
     *
     * @param array $llmConfig Configuration map containing 'model' (or 'provider') and 'api_key'.
     *                         An optional 'options' array is forwarded verbatim into every
     *                         request body (e.g. 'temperature', 'max_tokens', 'top_p'). An
     *                         optional 'base_url' overrides the provider's default endpoint
     *                         (e.g. to point at a proxy or self-hosted gateway).
     * @return Provider
     * @throws \RuntimeException If required configuration keys are missing or provider is unsupported.
     */
    private function resolveProvider(array $llmConfig): Provider
    {
        if (empty($llmConfig['provider'])) {
             throw new \RuntimeException("Provider must be specified in 'provider' key.");
        }

        $providerName = strtolower($llmConfig['provider']);

        // The mock provider makes no real request, so it has no use for an API key —
        // prefer Agent's Provider-injection constructor for tests over this string form.
        if ($providerName === 'mock') {
            return new \NanoAgent\Providers\MockProvider([]);
        }

        $modelStr = $llmConfig['model'] ?? '';
        $apiKey = $llmConfig['api_key'] ?? '';
        $options = $llmConfig['options'] ?? [];

        if (empty($apiKey)) {
            throw new \RuntimeException("API Key is required in LLM config.");
        }

        $modelName = $modelStr;

        // Named args can't be mixed with a spread in the same call, so combine
        // 'options' and the optional 'base_url' override into one array first.
        $namedArgs = ['options' => $options];
        if (isset($llmConfig['base_url'])) {
            $namedArgs['baseUrl'] = $llmConfig['base_url'];
        }

        return match($providerName) {
            'groq' => new \NanoAgent\Providers\Groq($apiKey, $modelName, ...$namedArgs),
            'openai' => new \NanoAgent\Providers\OpenAI($apiKey, $modelName, ...$namedArgs),
            'anthropic' => new \NanoAgent\Providers\Anthropic($apiKey, $modelName, ...$namedArgs),
            'deepseek' => new \NanoAgent\Providers\DeepSeek($apiKey, $modelName, ...$namedArgs),
            'openrouter' => new \NanoAgent\Providers\OpenRouter($apiKey, $modelName, ...$namedArgs),
            default => throw new \RuntimeException("Unsupported provider: $providerName")
        };
    }

    /**
     * Attach an event handler to listen for internal agent activities.
     *
     * @param callable $callback Expected signature: function(string $event, mixed $data).
     */
    public function setEventHandler(callable $callback): void
    {
        $this->onEvent = $callback;
    }

    /**
     * Enable standard activity logging using the ActivityLogger.
     */
    public function enableActivityLogging(): void
    {
        $this->onEvent = new \NanoAgent\Events\ActivityLogger();
    }

    /**
     * Retrieve logs if standard activity logging is enabled.
     * 
     * @return string[] Unformatted log messages.
     */
    public function getActivityLog(): array
    {
        if ($this->onEvent instanceof \NanoAgent\Events\ActivityLogger) {
            return $this->onEvent->getMessages();
        }
        return [];
    }

    /**
     * Emit an event through the registered event handler, if any.
     *
     * @param string $event The name/type of the event.
     * @param mixed $data Contextual information for the event.
     */
    private function log(string $event, mixed $data = null): void
    {
        if ($this->onEvent) {
            call_user_func($this->onEvent, $event, $data);
        }
    }

    /**
     * Add a piece of context that will be injected into the system instructions.
     *
     * @param string $label A descriptive name for the context (e.g., 'UserInfo').
     * @param string $content The actual data or text.
     */
    public function addContext(string $label, string $content): void
    {
        $this->context[$label] = $content;
    }

    /**
     * Access the current context map.
     *
     * Chiefly useful for snapshotting/restoring context around a scoped
     * operation (see Task::execute()), so callers don't have to track which
     * keys they added.
     *
     * @return array<string, string>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Overwrite/restore the context map wholesale.
     *
     * @param array<string, string> $context
     */
    public function setContext(array $context): void
    {
        $this->context = $context;
    }

    /**
     * Add a new tool capability to the agent.
     *
     * @param \NanoAgent\Contracts\Tool $tool
     */
    public function registerTool(\NanoAgent\Contracts\Tool $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
    }

    /**
     * Discover every tool exposed by an MCP (Model Context Protocol) server
     * and register each one as a Tool, so the agent can call them exactly
     * like local FunctionTool instances.
     *
     * @param \NanoAgent\Mcp\McpClient $client
     * @return string[] Names of the tools that were registered.
     */
    public function registerMcpServer(\NanoAgent\Mcp\McpClient $client): array
    {
        $names = [];
        foreach ($client->listTools() as $tool) {
            $mcpTool = new \NanoAgent\Tools\McpTool(
                $client,
                $tool['name'],
                $tool['description'] ?? '',
                $tool['inputSchema'] ?? []
            );
            $this->registerTool($mcpTool);
            $names[] = $mcpTool->getName();
        }
        return $names;
    }

    /**
     * Execute a chat round with the agent. 
     * Handles the interaction loop, including automatic tool execution and follow-up requests.
     *
     * @param string $message The user's input.
     * @return string The agent's final text response.
     */
    public function chat(string $message): string
    {
        // Add user input to history.
        $this->history[] = ['role' => 'user', 'content' => $message];
        $this->log('message.user', $message);

        // Build the system instructions by injecting all registered context.
        $fullSystemPrompt = ContextBuilder::build($this->systemPrompt, $this->context);

        $iterations = 0;

        while (true) {
            $iterations++;
            if ($iterations > $this->maxIterations) {
                throw new \NanoAgent\Exceptions\AgentException(
                    "Exceeded maximum tool-call iterations ({$this->maxIterations}) in a single chat() call. "
                    . "This usually means the model is stuck in a tool-calling loop."
                );
            }

            $messages = [['role' => 'system', 'content' => $fullSystemPrompt]];
            $messages = array_merge($messages, $this->history);

            $toolDefinitions = array_values(array_map(fn($t) => $t->toArray(), $this->tools));

            $this->log('request.start', [
                'model' => get_class($this->provider),
                'tools_count' => count($toolDefinitions)
            ]);
            
            $response = $this->provider->send($messages, $toolDefinitions);
            $this->log('request.end', $response);

            // Record assistant's message in the thread.
            $assistantMessage = ['role' => 'assistant', 'content' => $response['content'] ?? ''];
            if (!empty($response['tool_calls'])) {
                $assistantMessage['tool_calls'] = $response['tool_calls'];
            }
            $this->history[] = $assistantMessage;

            // If no tools were called, we have the final answer.
            if (empty($response['tool_calls'])) {
                return $response['content'] ?? '';
            }

            // Process requested tool calls, feeding results back into history for the next round.
            $this->runToolCalls($response['tool_calls']);
        }
    }

    /**
     * Execute a batch of tool calls returned by the provider and append each
     * result to the conversation history as a 'tool' message. Shared by both
     * chat() and stream() so their tool-calling loops behave identically.
     *
     * @param array $toolCalls Tool calls in the standardized provider response format.
     */
    private function runToolCalls(array $toolCalls): void
    {
        foreach ($toolCalls as $toolCall) {
            $functionName = $toolCall['function']['name'];
            $rawArgs = $toolCall['function']['arguments'] ?? '';
            $functionArgs = json_decode($rawArgs, true);
            $argsAreValid = json_last_error() === JSON_ERROR_NONE && is_array($functionArgs);
            $callId = $toolCall['id'];

            $this->log('tool.execute', ['name' => $functionName, 'args' => $argsAreValid ? $functionArgs : $rawArgs]);

            if (!$argsAreValid) {
                // Feed the parse failure back to the model rather than passing malformed
                // input to the tool, so it has a chance to retry with valid JSON.
                $output = "Error: tool '$functionName' received invalid arguments JSON ("
                    . json_last_error_msg() . "): " . $rawArgs;
            } elseif (isset($this->tools[$functionName])) {
                try {
                    $result = $this->tools[$functionName]->execute($functionArgs);
                    // JSON_UNESCAPED_UNICODE/JSON_UNESCAPED_SLASHES keep non-ASCII tool
                    // output compact instead of inflating it into \uXXXX escapes, which
                    // wastes tokens once this is fed back to the model. JSON_THROW_ON_ERROR
                    // turns an unencodable result into a caught error below instead of
                    // silently feeding the model an empty string.
                    $output = is_string($result)
                        ? $result
                        : json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } catch (\Throwable $e) {
                    $output = "Error executing tool: " . $e->getMessage();
                }
            } else {
                $output = "Tool not found: $functionName";
            }

            // Feed the tool result back into the conversation history.
            $this->history[] = [
                'role' => 'tool',
                'tool_call_id' => $callId,
                'name' => $functionName,
                'content' => $output
            ];

            $this->log('tool.result', ['name' => $functionName, 'output' => $output]);
        }
    }

    /**
     * Clear all messages from the conversation history.
     */
    public function clearHistory(): void
    {
        $this->history = [];
    }

    /**
     * Access the current conversation history.
     *
     * @return array
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    /**
     * Overwrite/Restore the conversation history.
     *
     * @param array $history
     */
    public function setHistory(array $history): void
    {
        $this->history = $history;
    }

    /**
     * Stream a chat response from the AI provider.
     *
     * Mirrors chat()'s tool-calling loop: if the provider's streamed response
     * includes tool calls, they are executed and a follow-up request is streamed,
     * up to maxIterations. $onToken only receives text content chunks — tool-call
     * argument deltas are accumulated by the provider and never forwarded to it.
     *
     * @param string $message User input.
     * @param callable $onToken Callback invoked for each received text token.
     * @return string The final aggregated text response.
     */
    public function stream(string $message, callable $onToken): string
    {
        $this->history[] = ['role' => 'user', 'content' => $message];
        $this->log('message.user', $message);

        $fullSystemPrompt = ContextBuilder::build($this->systemPrompt, $this->context);

        $iterations = 0;

        while (true) {
            $iterations++;
            if ($iterations > $this->maxIterations) {
                throw new \NanoAgent\Exceptions\AgentException(
                    "Exceeded maximum tool-call iterations ({$this->maxIterations}) in a single stream() call. "
                    . "This usually means the model is stuck in a tool-calling loop."
                );
            }

            $messages = [['role' => 'system', 'content' => $fullSystemPrompt]];
            $messages = array_merge($messages, $this->history);

            $toolDefinitions = array_values(array_map(fn($t) => $t->toArray(), $this->tools));

            $this->log('request.start_stream', ['model' => get_class($this->provider)]);

            $response = $this->provider->stream($messages, $onToken, $toolDefinitions);

            $this->log('request.end_stream', $response);

            $assistantMessage = ['role' => 'assistant', 'content' => $response['content'] ?? ''];
            if (!empty($response['tool_calls'])) {
                $assistantMessage['tool_calls'] = $response['tool_calls'];
            }
            $this->history[] = $assistantMessage;

            if (empty($response['tool_calls'])) {
                return $response['content'] ?? '';
            }

            $this->runToolCalls($response['tool_calls']);
        }
    }

}
