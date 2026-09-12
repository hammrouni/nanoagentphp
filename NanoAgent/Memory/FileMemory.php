<?php

declare(strict_types=1);

namespace NanoAgent\Memory;

use NanoAgent\Contracts\Memory;
use NanoAgent\Exceptions\MemoryException;

/**
 * Stores each session as a JSON file in a directory. No extensions required.
 *
 * File names are a hash of the session id, so arbitrary ids (emails, UUIDs,
 * user input) can't escape the directory. Writes go to a temp file and are
 * renamed into place, so a crashed request never leaves a half-written file.
 * Concurrent requests on the same session are last-writer-wins.
 */
class FileMemory implements Memory
{
    public function __construct(private string $directory)
    {
        if ($directory === '') {
            throw new \InvalidArgumentException('FileMemory directory must not be empty.');
        }
    }

    public function load(string $sessionId): array
    {
        $path = $this->path($sessionId);
        if (!is_file($path)) {
            return [];
        }

        $json = @file_get_contents($path);
        if ($json === false) {
            throw new MemoryException("Unable to read memory file: $path");
        }

        return HistoryCodec::decode($json);
    }

    public function save(string $sessionId, array $history): void
    {
        $this->ensureDirectory();

        $path = $this->path($sessionId);
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (@file_put_contents($tmp, HistoryCodec::encode($history), LOCK_EX) === false) {
            throw new MemoryException("Unable to write memory file: $tmp");
        }

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new MemoryException("Unable to move memory file into place: $path");
        }
    }

    public function clear(string $sessionId): void
    {
        $path = $this->path($sessionId);
        if (is_file($path) && !@unlink($path)) {
            throw new MemoryException("Unable to delete memory file: $path");
        }
    }

    private function path(string $sessionId): string
    {
        return rtrim($this->directory, '/\\') . DIRECTORY_SEPARATOR . hash('sha256', $sessionId) . '.json';
    }

    private function ensureDirectory(): void
    {
        // Re-check is_dir() after mkdir() fails: a concurrent request may have created it.
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new MemoryException("Unable to create memory directory: {$this->directory}");
        }
    }
}
