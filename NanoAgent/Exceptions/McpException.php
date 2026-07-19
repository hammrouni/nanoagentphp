<?php

declare(strict_types=1);

namespace NanoAgent\Exceptions;

use RuntimeException;

/**
 * Exception thrown when communication with an MCP (Model Context Protocol)
 * server fails, either at the transport level or via a JSON-RPC error response.
 */
class McpException extends RuntimeException implements NanoAgentException
{
}
