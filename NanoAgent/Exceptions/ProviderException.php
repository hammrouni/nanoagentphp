<?php

declare(strict_types=1);

namespace NanoAgent\Exceptions;

use RuntimeException;

/**
 * Exception thrown when an AI provider fails to process a request.
 */
class ProviderException extends RuntimeException implements NanoAgentException
{
}
