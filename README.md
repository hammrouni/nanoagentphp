# NanoAgent PHP

[![Latest Version on Packagist](https://img.shields.io/packagist/v/yourusername/nanoagent.svg?style=flat-square)](https://packagist.org/packages/yourusername/nanoagent)
[![Total Downloads](https://img.shields.io/packagist/dt/yourusername/nanoagent.svg?style=flat-square)](https://packagist.org/packages/yourusername/nanoagent)
[![License](https://img.shields.io/packagist/l/yourusername/nanoagent.svg?style=flat-square)](https://packagist.org/packages/yourusername/nanoagent)
[![PHP Version](https://img.shields.io/packagist/php-v/yourusername/nanoagent.svg?style=flat-square)](https://packagist.org/packages/yourusername/nanoagent)

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
* **🎯 Goal-Oriented Execution**: Define clear, context-aware tasks. The agent handles the reasoning, you handle the results.
* **💎 Minimalist Design**: A tiny footprint with massive potential. Use only what you need, with no hidden magic.

## Installation

Install via Composer:

```bash
composer require yourusername/nanoagent

```

## Quick Start

Get an agent running in less than 30 seconds.

```php
use NanoAgent\Agent;
use NanoAgent\Task;

// 1. Initialize the Agent
$agent = new Agent(
    llm: [
        'provider' => 'groq',
        'model'    => 'llama-3.3-70b-versatile',
        'api_key'  => getenv('GROQ_API_KEY')
    ],
    systemPrompt: "You are a helpful AI assistant."
);

// 2. Execute a Task
$task = new Task($agent);
$response = $task->execute("Explain the benefits of minimalism in software engineering.");

echo $response;

```

## Configuration

You can configure the agent directly (as above) or use a config file.

### Using config.php

Create a `config.php` file in your project root:

```php
<?php
return [
    'provider' => 'groq',
    'api_key' => 'your-api-key-here',
    'model' => 'llama-3.3-70b-versatile',
];

```

Load it into your application:

```php
$config = require 'config.php';
$agent = new Agent(llm: $config);

```

## Advanced Usage: Tools

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

## Examples

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

## Supported Providers

* Groq
* OpenAI
* OpenRouter
* Anthropic
* DeepSeek

## License

MIT