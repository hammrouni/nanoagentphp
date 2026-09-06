# NanoAgent PHP


[![CI](https://github.com/hammrouni/nanoagentphp/actions/workflows/ci.yml/badge.svg)](https://github.com/hammrouni/nanoagentphp/actions/workflows/ci.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/hammrouni/nanoagent?style=flat-square&logo=composer)](https://packagist.org/packages/hammrouni/nanoagent)
[![Total Downloads](https://img.shields.io/packagist/dt/hammrouni/nanoagent?style=flat-square&logo=packagist)](https://packagist.org/packages/hammrouni/nanoagent)
[![License](https://img.shields.io/github/license/hammrouni/nanoagentphp?style=flat-square&color=yellow)](https://github.com/hammrouni/nanoagentphp/blob/main/LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/hammrouni/nanoagent?style=flat-square&logo=php)](https://packagist.org/packages/hammrouni/nanoagent)
[![GitHub Stars](https://img.shields.io/github/stars/hammrouni/nanoagentphp?style=flat-square&logo=github)](https://github.com/hammrouni/nanoagentphp)
[![GitHub Issues](https://img.shields.io/github/issues/hammrouni/nanoagentphp?style=flat-square&logo=github)](https://github.com/hammrouni/nanoagentphp/issues)

**Bring the power of LLMs to your PHP application—without the bloat.**

NanoAgent is a lightweight library designed to strip away the complexity of modern AI development. While other libraries force you into steep learning curves, massive dependency trees, and rigid architectural patterns, NanoAgent takes a different approach: **simplicity as a feature.**

Whether you are building a complex autonomous agent or just need to integrate smart decision-making into a project, NanoAgent lets you focus on the task, not the configuration.

## Why NanoAgent?

The AI landscape is filled with "everything-included" frameworks that are often too heavy for practical, day-to-day PHP development.

* **Stop wrestling with complex configurations:** No need to learn a new DSL (Domain-Specific Language) or manage a dozen config files.
* **Drop-in ready:** Works with Laravel, Symfony, Slim, or plain PHP scripts.
* **Zero friction:** Go from `composer install` to a working agent in minutes.

## Features

* **⚡ Adaptable**: Seamlessly switch between OpenAI, Groq, Anthropic, DeepSeek, and OpenRouter with a single line of config.
* **🛠️ Tool-First Architecture**: Give your AI "hands." Easily map PHP functions as tools that your agent can intelligently execute to interact with your database, APIs, or filesystem.
* **🔌 MCP Support**: Connect to any Model Context Protocol server (Streamable HTTP) and let the agent call its tools alongside your own.
* **🎯 Goal-Oriented Execution**: Define clear, context-aware tasks. The agent handles the reasoning, you handle the results.
* **💎 Minimalist Design**: A tiny footprint with massive potential. Use only what you need, with no hidden magic.

## 🚀 Quick Start

This guide provides step-by-step instructions for installing, configuring, and running the NanoAgent PHP library in any PHP environment.

### Prerequisites

-   **PHP 8.0** or higher
-   **Composer** installed globally

### 📦 Installation & Setup

1.  Initialize a project:
    ```bash
    mkdir my-agent-project && cd my-agent-project
    composer require hammrouni/nanoagent
    ```

2.  **Configuration** :
    Create `config.php` (copy the example from `NanoAgent/config.php.example`) in the project root to manage credentials.

    ```php
    // config.php
    return [
        // API Key
        'api_key' => 'your_api_key_here',
        
        // AI Provider (groq, openai, anthropic, etc.)
        'provider' => '',
        
        // Model to use
        'model' => '',
    ];
    ```

    Then usage becomes:
    ```php
    // index.php
    $config = require __DIR__ . '/config.php';
    
    $agent = new Agent(
        llm: $config
        // ... other parameters
    );
    ```

3. **Troubleshooting**

-   **Class not found**: Run `composer dump-autoload` to regenerate the autoload files.
-   **API Error**: Check `config.php` or your environment variables to ensure the API key is correct.
-   **PHP Version**: Ensure you are running PHP 8.0+ by checking `php -v`.

## 🛠️ Advanced Usage: Tools

Give your agent "hands" by defining tools.

```php
use NanoAgent\Tools\FunctionTool;

$calculator = new FunctionTool(
    name: 'calculator',
    description: 'Add two numbers',
    parameters: [
        'type' => 'object',
        'properties' => [
            'a' => ['type' => 'integer', 'description' => 'First number'],
            'b' => ['type' => 'integer', 'description' => 'Second number']
        ],
        'required' => ['a', 'b']
    ],
    callable: fn(array $args) => $args['a'] + $args['b']
);

$agent = new Agent(
    llm: [...], 
    tools: [$calculator]
);

```

### Connecting to an MCP Server

Register every tool an [MCP](https://modelcontextprotocol.io) server exposes in one call — they behave exactly like local tools from there on.

```php
use NanoAgent\Mcp\McpClient;

$agent = new Agent(llm: [...]);

$mcpClient = new McpClient('https://mcp.deepwiki.com/mcp');
$agent->registerMcpServer($mcpClient);

echo $agent->chat('Ask the facebook/react repo what it does.');
```

## 📂 Examples

Check the `examples/` directory for advanced use cases:

| Example | Description |
| --- | --- |
| **[Basic Usage](examples/basic.php)** | Fundamental agent initialization and task execution. |
| **[Chat](examples/chat.php)** | Stateful conversation loop using PHP Sessions. |
| **[Memory Chat](examples/memory_chat.php)** | Persistent conversation history saved to JSON. |
| **[Structured Output](examples/structured_output.php)** | Extract JSON data from unstructured text. |
| **[Knowledge Base](examples/knowledge_base_search.php)** | RAG: Ask questions against private documents. |
| **[Streaming](examples/streaming.php)** | Real-time token streaming (SSE). |
| **[Agent Chain](examples/agent_chain.php)** | Multi-agent workflow (Researcher feeds Writer). |
| **[Multi-Tool](examples/multi_tool_workflow.php)** | Orchestrate multiple tools to solve complex requests. |
| **[Advanced Tools](examples/advanced_tools.php)** | Complex multi-step tool usage with simulated database state. |
| **[API Integration](examples/api_integration.php)** | Fetch real-world data from external APIs. |
| **[Multi-Provider](examples/multi_provider.php)** | Switch between different AI providers programmatically. |
| **[MCP Tools](examples/mcp_tools.php)** | Call tools discovered from a remote MCP server. |

## Supported Providers

Every provider below is covered by the automated test suite (see the CI badge above). Groq has also been verified against the real API.

* Groq
* OpenAI
* OpenRouter
* DeepSeek
* Anthropic — experimental. Built separately from the others, not yet verified against the real API, and streaming isn't fully wired up in this library yet.

## Changelog

### 0.4.0 (unreleased)

1. Added MCP (Model Context Protocol) client support: connect to any Streamable HTTP MCP server and register its tools on an `Agent` with `registerMcpServer()`.
2. Clarified in the README that all providers are already covered by the mocked unit test suite.

### 0.3.0

1. Inject a custom provider directly into `Agent`, no config array needed.
2. Configure `temperature`, `max_tokens`, and a custom `base_url` per provider.
3. Streaming now supports tool calls.
4. More reliable request encoding for non-English text.

### 0.2.0

1. HTTP requests now have timeouts and surface real API errors instead of hanging or failing silently.
2. Tool-calling loops are capped to avoid runaway API usage.
3. Task context no longer leaks between calls.
4. Added static analysis (PHPStan) and a wider PHP version test matrix (8.0-8.4).

### 0.1.0

## License

MIT