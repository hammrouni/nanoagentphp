<?php

declare(strict_types=1);

namespace NanoAgent\Memory;

use NanoAgent\Exceptions\MemoryException;

/**
 * JSON (de)serialization shared by the Memory drivers that store history as text.
 *
 * @internal
 */
final class HistoryCodec
{
    public static function encode(array $history): string
    {
        try {
            // Tool results are passed through from user code and may contain invalid
            // UTF-8; substitute rather than fail so one bad byte can't wipe a session.
            return json_encode(
                $history,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );
        } catch (\JsonException $e) {
            throw new MemoryException('Failed to encode conversation history: ' . $e->getMessage(), 0, $e);
        }
    }

    public static function decode(string $json): array
    {
        try {
            $history = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MemoryException('Stored conversation history is not valid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($history)) {
            throw new MemoryException('Stored conversation history must decode to an array.');
        }

        return $history;
    }
}
