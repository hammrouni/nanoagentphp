# Architecture Documentation

## Overview

NanoAgent PHP is a modular framework designed to build autonomous AI agents capable of executing tasks, using tools, and streaming responses in real-time. It abstracts the underlying Large Language Model (LLM) providers, allowing seamless switching between services like OpenAI, Groq, Anthropic, DeepSeek, and OpenRouter.

The framework is designed to be lightweight and standalone, with a custom autoloader that removes the strict dependency on Composer for basic usage, making it easy to integrate into any PHP project.

## Core Components

### 1. Agent (`NanoAgent\Agent`)
The central orchestrator of the framework.
-   **Responsibilities**:
    -   Manages conversation history and context.
    -   Registers and stores available `Tools`.
    -   Constructs the full system prompt using `ContextBuilder`.
    -   Handles the interacton loop: processing user messages, invoking the `Provider`, executing `Tool` calls, and returning the final response.
    -   **Streaming**: Supports real-time token streaming via the `stream()` method.
    -   **Events**: Triggers events (start, end, tool execution) via a registered callback (`setEventHandler`).
-   **Configuration**: Initialized with an LLM configuration (Provider/Model), a System Prompt, and a list of Tools.

### 2. Task (`NanoAgent\Task`)
A high-level abstraction for a specific unit of work.
-   **Responsibilities**:
    -   Wraps an `Agent` instance.
    -   Accumulates specific context required for the task (e.g., project details, specific data).
    -   Triggers execution via `execute($goal)`, which constructs the final prompt and delegates to the Agent.

### 3. Provider (`NanoAgent\Contracts\Provider`)
The interface layer between the Agent and external LLM APIs.
-   **Contract**: 
    -   `send(array $messages, array $tools): array`
    -   `stream(array $messages, callable $onToken, array $tools): array`
-   **Implementations**:
    -   `Groq`: Connects to Groq API.
    -   `OpenAI`: Connects to OpenAI API.
    -   `Anthropic`: Connects to Anthropic API (Claude).
    -   `DeepSeek`: Connects to DeepSeek API.
    -   `OpenRouter`: Connects to OpenRouter API.
    -   `MockProvider`: For testing purposes.
-   **Role**: Handles authentication, request formatting, and response parsing (standardizing content and tool calls).

### 4. Tool (`NanoAgent\Contracts\Tool`)
Represents an executable function that the AI can invoke.
-   **Contract**: `execute(array $arguments): mixed`, `toArray(): array` (JSON Schema).
-   **Implementation**: `FunctionTool` is a generic implementation that accepts a PHP callable.

### 5. Utilities
-   **`ContextBuilder`**: Merges system prompts with dynamic context variables.
-   **`HttpClient`**: A lightweight wrapper for `curl` requests, handling headers and JSON payloads for API calls.

## Data Flow

### Standard Chat Flow
1.  **Initialization**: User creates an `Agent` with specific LLM config.
2.  **Context**: User adds context (e.g. `addContext('User', '...')`).
3.  **Execution**: User calls `$agent->chat("Message")`.
4.  **Prompt Assembly**: Agent combines System Prompt + Context + History.
5.  **LLM Request**: Agent sends the conversation to the `Provider`.
6.  **Tool Loop** (if applicable):
    -   LLM may return a "tool_call".
    -   Agent executes the corresponding PHP function.
    -   Result is fed back to the LLM.
7.  **Response**: Final text answer is returned to the User.

### Streaming Flow
1.  **Execution**: User calls `$agent->stream("Message", $callback)`.
2.  **Streaming**: The `Provider` opens a stream to the API.
3.  **Token Delivery**: As the LLM generates tokens, the `$callback` is invoked immediately (e.g., for Server-Sent Events).
4.  **Completion**: The full response is aggregated and stored in history.

## Directory Structure

```text
NanoAgent/
├── Agent.php           # Core Agent class (Chat, Stream, Tool logic)
├── Task.php            # Task abstraction
├── autoloader.php      # Custom autoloader for standalone usage
├── config.php          # Default configuration loading
├── Contracts/          # Interfaces (Provider, Tool)
├── Providers/          # API Implementations (Groq, OpenAI, Anthropic, etc.)
├── Tools/              # Tool implementations (FunctionTool)
├── Utils/              # Helpers (ContextBuilder, HttpClient)
└── Exceptions/         # Custom exceptions
```

## Setup & Integration

The framework handles its own loading via `NanoAgent/autoloader.php`. This allows it to be dropped into any project without requiring a Composer build step, although it remains compatible with PSR-4 structure if Composer is preferred.
