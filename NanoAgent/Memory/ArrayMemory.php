<?php

declare(strict_types=1);

namespace NanoAgent\Memory;

use NanoAgent\Contracts\Memory;

/**
 * Process-local memory. Nothing survives the end of the request, so this is
 * meant for tests and long-running workers (queues, ReactPHP, Swoole, CLI loops).
 */
class ArrayMemory implements Memory
{
    /** @var array<string, array> */
    private array $sessions = [];

    public function load(string $sessionId): array
    {
        return $this->sessions[$sessionId] ?? [];
    }

    public function save(string $sessionId, array $history): void
    {
        $this->sessions[$sessionId] = $history;
    }

    public function clear(string $sessionId): void
    {
        unset($this->sessions[$sessionId]);
    }
}
