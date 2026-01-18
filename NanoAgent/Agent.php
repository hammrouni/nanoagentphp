<?php

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
    public const VERSION = '0.1.0';

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

    /**
     * Agent constructor.
     *
     * @param array $llm Configuration for the LLM. Should contain 'model' and 'api_key'.
     * @param string $systemPrompt The base instructions that define the agent's behavior.
     * @param array $tools Initial set of tools to register with the agent.
     */
    public function __construct(
        array $llm = [],
        private string $systemPrompt = '',
        array $tools = []
    ) {
        // Automatically attempt to load configuration from the default config.php file if none provided.
        if (empty($llm)) {
            $configPath = __DIR__ . '/config.php';
            if (file_exists($configPath)) {
                $config = require $configPath;
                $llm = $config;
            }
        }

        $this->llmConfig = $llm;

        $this->provider = $this->resolveProvider($llm);
        foreach ($tools as $tool) {
            $this->registerTool($tool);
        }
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
     * Factory method to resolve and instantiate the AI Provider based on configuration.
     *
     * @param array $llmConfig Configuration map containing 'model' (or 'provider') and 'api_key'.
     * @return Provider
     * @throws \RuntimeException If required configuration keys are missing or provider is unsupported.
     */
    private function resolveProvider(array $llmConfig): Provider
    {
        $modelStr = $llmConfig['model'] ?? '';
        $apiKey = $llmConfig['api_key'] ?? '';

        if (empty($apiKey)) {
            throw new \RuntimeException("API Key is required in LLM config.");
        }

        if (empty($llmConfig['provider'])) {
             throw new \RuntimeException("Provider must be specified in 'provider' key.");
        }
        
        $providerName = $llmConfig['provider'];
        $modelName = $modelStr;

        return match(strtolower($providerName)) {
            'groq' => new \NanoAgent\Providers\Groq($apiKey, $modelName),
            'openai' => new \NanoAgent\Providers\OpenAI($apiKey, $modelName),
            'anthropic' => new \NanoAgent\Providers\Anthropic($apiKey, $modelName),
            'deepseek' => new \NanoAgent\Providers\DeepSeek($apiKey, $modelName),
            'openrouter' => new \NanoAgent\Providers\OpenRouter($apiKey, $modelName),
            'mock' => new \NanoAgent\Providers\MockProvider([]),
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
     * Add a new tool capability to the agent.
     *
     * @param \NanoAgent\Contracts\Tool $tool
     */
    public function registerTool(\NanoAgent\Contracts\Tool $tool): void
    {
        $this->tools[$tool->getName()] = $tool;
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
        
        while (true) {
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

            // Process requested tool calls.
            if (!empty($response['tool_calls'])) {
                foreach ($response['tool_calls'] as $toolCall) {
                    $functionName = $toolCall['function']['name'];
                    $functionArgs = json_decode($toolCall['function']['arguments'], true);
                    $callId = $toolCall['id'];

                    $this->log('tool.execute', ['name' => $functionName, 'args' => $functionArgs]);

                    if (isset($this->tools[$functionName])) {
                        try {
                            $result = $this->tools[$functionName]->execute($functionArgs);
                            $output = is_string($result) ? $result : json_encode($result);
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
            } else {
                return $response['content'] ?? '';
            }
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
     * @param string $message User input.
     * @param callable $onToken Callback invoked for each received token.
     * @return string The aggregated response content.
     */
    public function stream(string $message, callable $onToken): string
    {
        $this->history[] = ['role' => 'user', 'content' => $message];
        $this->log('message.user', $message);

        $fullSystemPrompt = ContextBuilder::build($this->systemPrompt, $this->context);
        
        $messages = [['role' => 'system', 'content' => $fullSystemPrompt]];
        $messages = array_merge($messages, $this->history);

        $toolDefinitions = array_values(array_map(fn($t) => $t->toArray(), $this->tools));

        $this->log('request.start_stream', ['model' => get_class($this->provider)]);

        $response = $this->provider->stream($messages, $onToken, $toolDefinitions);

        $this->log('request.end_stream', $response);

        $assistantMessage = ['role' => 'assistant', 'content' => $response['content'] ?? ''];
        $this->history[] = $assistantMessage;

        return $response['content'] ?? '';
    }

}
