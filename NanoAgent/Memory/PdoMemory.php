<?php

declare(strict_types=1);

namespace NanoAgent\Memory;

use NanoAgent\Contracts\Memory;
use NanoAgent\Exceptions\MemoryException;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Stores sessions in a single SQL table through PDO.
 *
 * SQLite, MySQL/MariaDB and PostgreSQL use their native upsert syntax; any other
 * driver falls back to UPDATE-then-INSERT. See docs/pdo-memory.md for connection
 * examples and the table schema for each database.
 */
class PdoMemory implements Memory
{
    private string $table;

    /**
     * @param PDO $pdo An existing connection; its attributes are left untouched.
     * @param string $table Table name (letters, digits, underscores).
     * @param bool $createTable Run CREATE TABLE IF NOT EXISTS on construction. Disable
     *                          when the schema is managed by your own migrations.
     */
    public function __construct(
        private PDO $pdo,
        string $table = 'nanoagent_memory',
        bool $createTable = true
    ) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("Invalid memory table name: $table");
        }
        $this->table = $table;

        if ($createTable) {
            $this->createTable();
        }
    }

    /**
     * Shortcut for a SQLite database file (created if missing). Requires ext-pdo_sqlite.
     *
     * @param string $path Database file path, or ':memory:'.
     * @param string $table
     */
    public static function sqlite(string $path, string $table = 'nanoagent_memory'): self
    {
        try {
            $pdo = new PDO('sqlite:' . $path);
        } catch (PDOException $e) {
            throw new MemoryException("Unable to open SQLite memory database '$path': " . $e->getMessage(), 0, $e);
        }

        return new self($pdo, $table);
    }

    public function load(string $sessionId): array
    {
        $json = $this->query("SELECT history FROM {$this->table} WHERE session_id = ?", [$sessionId])->fetchColumn();

        return ($json === false || $json === null) ? [] : HistoryCodec::decode((string) $json);
    }

    public function save(string $sessionId, array $history): void
    {
        $params = [$sessionId, HistoryCodec::encode($history), time()];
        $insert = "INSERT INTO {$this->table} (session_id, history, updated_at) VALUES (?, ?, ?)";

        switch ($this->driver()) {
            case 'sqlite':
            case 'pgsql':
                $this->query(
                    "$insert ON CONFLICT (session_id) DO UPDATE SET history = excluded.history, updated_at = excluded.updated_at",
                    $params
                );
                return;
            case 'mysql':
                $this->query(
                    "$insert ON DUPLICATE KEY UPDATE history = VALUES(history), updated_at = VALUES(updated_at)",
                    $params
                );
                return;
        }

        $updated = $this->query(
            "UPDATE {$this->table} SET history = ?, updated_at = ? WHERE session_id = ?",
            [$params[1], $params[2], $sessionId]
        );
        if ($updated->rowCount() === 0) {
            $this->query($insert, $params);
        }
    }

    public function clear(string $sessionId): void
    {
        $this->query("DELETE FROM {$this->table} WHERE session_id = ?", [$sessionId]);
    }

    private function createTable(): void
    {
        if ($this->driver() === 'mysql') {
            // VARBINARY keeps session ids exact whatever the database's default collation
            // is (MySQL's default treats 'Alice' and 'alice' as the same key), and LONGTEXT
            // avoids TEXT's 64KB cap, which a tool-heavy conversation can exceed.
            $sessionType = 'VARBINARY(255)';
            $historyType = 'LONGTEXT CHARACTER SET utf8mb4';
        } else {
            $sessionType = 'VARCHAR(255)';
            $historyType = 'TEXT';
        }

        $this->query(
            "CREATE TABLE IF NOT EXISTS {$this->table} ("
            . "session_id $sessionType NOT NULL PRIMARY KEY, "
            . "history $historyType NOT NULL, "
            . 'updated_at BIGINT NOT NULL)'
        );
    }

    private function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Prepare and execute a statement, normalizing both PDO error modes
     * (exceptions and silent false returns) into MemoryException.
     */
    private function query(string $sql, array $params = []): PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            if ($stmt === false || !$stmt->execute($params)) {
                $errorInfo = $stmt === false ? $this->pdo->errorInfo() : $stmt->errorInfo();
                throw new MemoryException('Memory query failed: ' . ($errorInfo[2] ?? 'unknown error'));
            }
            return $stmt;
        } catch (PDOException $e) {
            throw new MemoryException('Memory query failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
