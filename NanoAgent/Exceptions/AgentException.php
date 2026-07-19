<?php

declare(strict_types=1);

namespace NanoAgent\Exceptions;

use RuntimeException;

/**
 * Exception thrown when the Agent itself fails, independent of any provider request
 * (e.g. exceeding the maximum number of tool-call iterations in a single chat turn).
 */
class AgentException extends RuntimeException implements NanoAgentException
{
}
