<?php

declare(strict_types=1);

namespace NanoAgent\Exceptions;

use RuntimeException;

/**
 * Exception thrown when an AI provider fails to process a request.
 */
class ProviderException extends RuntimeException implements NanoAgentException
{
    /**
     * The HTTP status code returned by the API, if the failure originated
     * from an error response (0 for network-level failures).
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->getCode();
    }
}
