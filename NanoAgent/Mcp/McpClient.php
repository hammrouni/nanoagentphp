<?php

declare(strict_types=1);

namespace NanoAgent\Mcp;

use NanoAgent\Exceptions\McpException;

/**
 * Minimal client for the Model Context Protocol (MCP) "Streamable HTTP" transport.
 *
 * Speaks JSON-RPC 2.0 over a single HTTP endpoint: each call is a POST request,
 * and the response is either a plain JSON body or a single Server-Sent Events
 * message carrying one JSON-RPC reply. That's all the request/response pattern
 * needed to discover and call tools requires — server-initiated notifications
 * and multi-message SSE streams are intentionally not consumed here.
 */
class McpClient
{
    private ?string $sessionId = null;
    private int $nextId = 1;
    private bool $initialized = false;

    /**
     * @param string $url MCP server endpoint (Streamable HTTP), e.g. https://example.com/mcp
     * @param array<string, string> $headers Extra headers to send with every request (e.g. Authorization).
     * @param int $timeout Max seconds to wait for a single request.
     */
    public function __construct(
        private string $url,
        private array $headers = [],
        private int $timeout = 30
    ) {}

    /**
     * Perform the MCP initialization handshake. Safe to call more than once —
     * a no-op once the session has already been established.
     *
     * @throws McpException On transport failure or a JSON-RPC error response.
     */
    public function connect(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->request('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'nanoagentphp', 'version' => \NanoAgent\Agent::VERSION],
        ]);

        // 'initialized' is a one-way notification (no 'id', no response expected),
        // required by the spec to complete the handshake before other calls are made.
        $this->notify('notifications/initialized');

        $this->initialized = true;
    }

    /**
     * List the tools exposed by the MCP server.
     *
     * @return array<int, array{name: string, description?: string, inputSchema?: array}>
     * @throws McpException On transport failure or a JSON-RPC error response.
     */
    public function listTools(): array
    {
        $this->connect();
        $result = $this->request('tools/list');
        return $result['tools'] ?? [];
    }

    /**
     * Invoke a tool on the MCP server and return its textual result.
     *
     * @param string $name
     * @param array $arguments
     * @return string
     * @throws McpException On transport failure, a JSON-RPC error response, or
     *                      a tool-level error reported via the result's isError flag.
     */
    public function callTool(string $name, array $arguments): string
    {
        $this->connect();
        $result = $this->request('tools/call', [
            'name' => $name,
            'arguments' => $arguments,
        ]);

        $texts = [];
        foreach ($result['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                $texts[] = $block['text'];
            }
        }
        $output = implode("\n", $texts);

        if (!empty($result['isError'])) {
            throw new McpException("MCP tool '$name' reported an error: " . $output);
        }

        return $output;
    }

    /**
     * Send a JSON-RPC request and return its 'result' payload.
     *
     * @throws McpException On transport failure or a JSON-RPC error response.
     */
    private function request(string $method, array $params = []): array
    {
        $response = $this->send([
            'jsonrpc' => '2.0',
            'id' => $this->nextId++,
            'method' => $method,
            // Empty PHP arrays encode as JSON '[]'; the spec requires an object,
            // so force one when there are no params to send.
            'params' => empty($params) ? new \stdClass() : $params,
        ]);

        if (isset($response['error'])) {
            $message = $response['error']['message'] ?? 'Unknown MCP error';
            throw new McpException("MCP server error calling '$method': $message");
        }

        return $response['result'] ?? [];
    }

    /**
     * Send a one-way JSON-RPC notification (no 'id', no response body expected).
     *
     * @throws McpException On transport failure.
     */
    private function notify(string $method, array $params = []): void
    {
        $this->send([
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => empty($params) ? new \stdClass() : $params,
        ], expectResponse: false);
    }

    /**
     * POST a JSON-RPC payload and decode the reply, transparently handling both
     * a plain JSON response and a single-event SSE response — the Streamable
     * HTTP transport allows a server to reply with either.
     *
     * @throws McpException On network failure, an HTTP error status, or a body
     *                      that isn't valid JSON / a valid single SSE event.
     */
    private function send(array $payload, bool $expectResponse = true): ?array
    {
        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
        ], $this->headers);

        if ($this->sessionId !== null) {
            $headers[] = 'Mcp-Session-Id: ' . $this->sessionId;
        }

        $ch = curl_init($this->url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        $raw = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new McpException('Network error talking to MCP server: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);

        if (preg_match('/^Mcp-Session-Id:\s*(.+)$/mi', $rawHeaders, $m)) {
            $this->sessionId = trim($m[1]);
        }

        // Notifications typically get a 202 Accepted with no body.
        if (!$expectResponse) {
            if ($statusCode >= 400) {
                throw new McpException("HTTP $statusCode error sending MCP notification to {$this->url}");
            }
            return null;
        }

        if ($statusCode >= 400) {
            throw new McpException("HTTP $statusCode error from MCP server: " . trim($body));
        }

        $json = str_contains($rawHeaders, 'text/event-stream')
            ? $this->extractSseData($body)
            : trim($body);

        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new McpException('Invalid JSON-RPC response from MCP server: ' . $body);
        }

        return $decoded;
    }

    /**
     * Pull the JSON payload out of an SSE stream's 'data:' field(s). Only the
     * first event is consumed, which is all the Streamable HTTP transport sends
     * for a single request/response exchange.
     */
    private function extractSseData(string $body): string
    {
        $lines = [];
        foreach (explode("\n", $body) as $line) {
            $line = rtrim($line, "\r");
            if (str_starts_with($line, 'data:')) {
                $lines[] = ltrim(substr($line, 5));
            } elseif ($line === '' && !empty($lines)) {
                break;
            }
        }
        return implode("\n", $lines);
    }
}
