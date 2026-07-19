<?php

declare(strict_types=1);

namespace NanoAgent\Tools;

use NanoAgent\Contracts\Tool;
use NanoAgent\Mcp\McpClient;

/**
 * Adapts a single tool exposed by an MCP server into a NanoAgent Tool, so it
 * can be registered on an Agent and called just like a local FunctionTool.
 */
class McpTool implements Tool
{
    /**
     * @param McpClient $client MCP client the tool call is dispatched through.
     * @param string $name Tool name as reported by the MCP server.
     * @param string $description Tool description as reported by the MCP server.
     * @param array $inputSchema JSON Schema for the tool's arguments, as reported by the server.
     */
    public function __construct(
        private McpClient $client,
        private string $name,
        private string $description,
        private array $inputSchema
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function toArray(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => $this->inputSchema ?: ['type' => 'object', 'properties' => new \stdClass()],
            ],
        ];
    }

    /**
     * Dispatches execution to the MCP server rather than running local PHP logic.
     */
    public function execute(array $arguments): mixed
    {
        return $this->client->callTool($this->name, $arguments);
    }
}
