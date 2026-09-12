<?php

declare(strict_types=1);

namespace NanoAgent\Exceptions;

use RuntimeException;

/**
 * Exception thrown when a Memory driver fails to read, write, or decode
 * stored conversation history.
 */
class MemoryException extends RuntimeException implements NanoAgentException
{
}
