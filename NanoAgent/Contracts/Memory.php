<?php

declare(strict_types=1);

namespace NanoAgent\Contracts;

/**
 * Storage driver for persisting an Agent's conversation history between
 * stateless PHP requests.
 *
 * Implementations only need to round-trip the history array for a given
 * session id; the Agent decides when to load and save.
 */
interface Memory
{
    /**
     * Load the stored history for a session.
     *
     * @param string $sessionId Caller-defined conversation identifier (user id, chat id, ...).
     * @return array The stored messages, or an empty array if the session is unknown.
     */
    public function load(string $sessionId): array;

    /**
     * Persist the full history for a session, replacing whatever was stored before.
     *
     * @param string $sessionId
     * @param array $history
     */
    public function save(string $sessionId, array $history): void;

    /**
     * Delete the stored history for a session. Clearing an unknown session is a no-op.
     *
     * @param string $sessionId
     */
    public function clear(string $sessionId): void;
}
