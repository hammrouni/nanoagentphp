# PdoMemory: store conversations in a database

`PdoMemory` saves each agent conversation as one row in a SQL table, so a chat keeps going across requests, deploys and server restarts. It works with any PDO connection; SQLite, MySQL/MariaDB and PostgreSQL get native upserts.

- [Quick start (SQLite)](#quick-start-sqlite)
- [Connecting to MySQL, MariaDB or PostgreSQL](#connecting-to-mysql-mariadb-or-postgresql)
- [Constructor options](#constructor-options)
- [The table](#the-table)
- [Choosing a session id](#choosing-a-session-id)
- [Cleaning up old conversations](#cleaning-up-old-conversations)
- [Errors](#errors)
- [Limits](#limits)

## Requirements

- `ext-pdo` plus the driver for your database: `pdo_sqlite`, `pdo_mysql` or `pdo_pgsql`.
- SQLite 3.24+, MySQL 5.7+ / MariaDB 10.3+, or PostgreSQL 9.5+.

Check which drivers your PHP has:

```bash
php -m | grep -i pdo
```

## Quick start (SQLite)

```php
use NanoAgent\Agent;
use NanoAgent\Memory\PdoMemory;

$agent = new Agent(llm: $config);
$agent->setMemory(PdoMemory::sqlite('/var/app/storage/memory.sqlite'), "user-$userId");

echo $agent->chat($_POST['message']);
```

That's all. On first use `PdoMemory` creates a `nanoagent_memory` table. The agent loads the session's history in `setMemory()` and saves it after every completed `chat()` or `stream()` call.

- The **directory** must already exist and be writable by the PHP process. SQLite creates the file, not the folders.
- Keep the file **outside your public web root**, or anyone could download every conversation.

### SQLite in production

`PdoMemory::sqlite()` is a shortcut with default settings. For a site with concurrent requests, open the connection yourself so writers wait for each other instead of failing with "database is locked":

```php
$pdo = new PDO('sqlite:/var/app/storage/memory.sqlite', options: [
    PDO::ATTR_TIMEOUT => 5,              // wait up to 5s for a lock
]);
$pdo->exec('PRAGMA journal_mode = WAL'); // readers don't block writers

$agent->setMemory(new PdoMemory($pdo), $sessionId);
```

## Connecting to MySQL, MariaDB or PostgreSQL

Create a PDO connection and pass it in. `PdoMemory` does not change the connection's attributes.

**MySQL / MariaDB**

```php
$pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=app;charset=utf8mb4',
    getenv('DB_USER'),
    getenv('DB_PASSWORD')
);

$agent->setMemory(new PdoMemory($pdo), $sessionId);
```

Always put `charset=utf8mb4` in the DSN. Without it, emoji and many non-Latin characters can be mangled or rejected.

**PostgreSQL**

```php
$pdo = new PDO(
    'pgsql:host=127.0.0.1;port=5432;dbname=app',
    getenv('DB_USER'),
    getenv('DB_PASSWORD')
);

$agent->setMemory(new PdoMemory($pdo), $sessionId);
```

**Reusing your framework's connection**

```php
// Laravel
$memory = new PdoMemory(DB::connection()->getPdo());

// Doctrine DBAL 3.3+ (Symfony)
$memory = new PdoMemory($connection->getNativeConnection());
```

Create the `PdoMemory` once per request and share it; building it runs a `CREATE TABLE IF NOT EXISTS` unless you turn that off (see below).

## Constructor options

```php
new PdoMemory(PDO $pdo, string $table = 'nanoagent_memory', bool $createTable = true)
PdoMemory::sqlite(string $path, string $table = 'nanoagent_memory')
```

| Option | Default | Description |
| --- | --- | --- |
| `$pdo` | — | An open PDO connection. |
| `$table` | `nanoagent_memory` | Table name. Letters, digits and underscores only, so it can't be used for SQL injection. |
| `$createTable` | `true` | Run `CREATE TABLE IF NOT EXISTS` when constructed. Set to `false` when you create the table yourself. |

Named arguments keep it readable:

```php
$memory = new PdoMemory($pdo, table: 'chat_memory', createTable: false);
```

## The table

Every session is one row:

| Column | Type | Content |
| --- | --- | --- |
| `session_id` | string, primary key | The id you pass to `setMemory()`. Compared exactly: `Alice` and `alice` are different sessions. |
| `history` | long text | The full message list as JSON (user, assistant and tool messages). |
| `updated_at` | big integer | Unix timestamp (seconds) of the last save. Useful for cleanup. |

### Option A: let PdoMemory create it

Nothing to do. The default `$createTable = true` creates the table on first use, with the right column types for your database. It needs the database user to have `CREATE` permission.

### Option B: create it yourself

Use this when your app manages its schema through migrations, the runtime database user can't create tables, or you want an index for cleanup. Run the SQL for your database once, then pass `createTable: false`.

**MySQL / MariaDB**

```sql
CREATE TABLE chat_memory (
    session_id VARBINARY(255) NOT NULL PRIMARY KEY,
    history    LONGTEXT CHARACTER SET utf8mb4 NOT NULL,
    updated_at BIGINT NOT NULL,
    INDEX idx_chat_memory_updated_at (updated_at)
) ENGINE=InnoDB;
```

- `VARBINARY` makes session ids case- and accent-sensitive. A `VARCHAR` with MySQL's default collation would treat `Alice`, `alice` and `alicé` as the **same** session, mixing different users' conversations.
- `LONGTEXT` matters: plain `TEXT` stops at 64KB, which a long conversation with tool results can exceed.

**PostgreSQL**

```sql
CREATE TABLE chat_memory (
    session_id VARCHAR(255) NOT NULL PRIMARY KEY,
    history    TEXT NOT NULL,
    updated_at BIGINT NOT NULL
);
CREATE INDEX idx_chat_memory_updated_at ON chat_memory (updated_at);
```

**SQLite**

```sql
CREATE TABLE chat_memory (
    session_id VARCHAR(255) NOT NULL PRIMARY KEY,
    history    TEXT NOT NULL,
    updated_at BIGINT NOT NULL
);
CREATE INDEX idx_chat_memory_updated_at ON chat_memory (updated_at);
```

Then:

```php
$memory = new PdoMemory($pdo, table: 'chat_memory', createTable: false);
$agent->setMemory($memory, $sessionId);
```

### Adding your own columns

`PdoMemory` only writes `session_id`, `history` and `updated_at`, so you can add columns as long as each one is nullable or has a default. For example, on MySQL:

```sql
CREATE TABLE chat_memory (
    session_id VARBINARY(255) NOT NULL PRIMARY KEY,
    history    LONGTEXT CHARACTER SET utf8mb4 NOT NULL,
    updated_at BIGINT NOT NULL,
    user_id    BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_chat_memory_updated_at (updated_at),
    INDEX idx_chat_memory_user_id (user_id)
) ENGINE=InnoDB;
```

Fill `user_id` yourself after the first save if you need it, e.g. `UPDATE chat_memory SET user_id = ? WHERE session_id = ?`.

## Choosing a session id

The session id decides which conversation is loaded, so it must come from something **your server trusts**, never straight from user input. Otherwise one user could type another user's id and read their chat.

```php
// One conversation per logged-in user
$agent->setMemory($memory, 'user-' . $user->id);

// Several conversations per user: combine the user with a chat id you issued
$agent->setMemory($memory, "user-{$user->id}-chat-{$chatId}");

// Anonymous visitors: the PHP session id
session_start();
$agent->setMemory($memory, 'visitor-' . session_id());
```

To start the conversation over, call `$agent->clearHistory()`. It empties the agent and deletes the row.

## Cleaning up old conversations

Rows are never deleted automatically. Remove conversations that haven't been used for a while, for example from a daily cron job. This works on every database:

```php
$days = 30;
$pdo->prepare('DELETE FROM chat_memory WHERE updated_at < ?')
    ->execute([time() - $days * 86400]);
```

The same in plain SQL:

```sql
-- MySQL / MariaDB
DELETE FROM chat_memory WHERE updated_at < UNIX_TIMESTAMP() - 30 * 86400;

-- PostgreSQL
DELETE FROM chat_memory WHERE updated_at < EXTRACT(EPOCH FROM NOW())::BIGINT - 30 * 86400;

-- SQLite
DELETE FROM chat_memory WHERE updated_at < CAST(strftime('%s', 'now') AS INTEGER) - 30 * 86400;
```

The `updated_at` index from [Option B](#option-b-create-it-yourself) keeps these queries fast on large tables.

## Errors

| Exception | When |
| --- | --- |
| `NanoAgent\Exceptions\MemoryException` | A query fails (connection lost, missing table, no permission), the SQLite file can't be opened, or a stored `history` is not valid JSON. Thrown whatever PDO error mode your connection uses. |
| `InvalidArgumentException` | The table name contains characters other than letters, digits and underscores. |

```php
use NanoAgent\Exceptions\MemoryException;

try {
    $agent->setMemory($memory, $sessionId);
    echo $agent->chat($message);
} catch (MemoryException $e) {
    error_log('Chat memory unavailable: ' . $e->getMessage());
    // e.g. show an error, or continue without memory
}
```

If a `chat()` call fails partway through (API error, tool loop limit), nothing is saved, so the table keeps the last completed exchange.

## Limits

- **Session ids** are up to 255 characters on PostgreSQL and SQLite, and 255 bytes on MySQL, where accented or non-Latin characters take 2 to 4 bytes each. For longer ids, store a hash: `hash('sha256', $longId)`.
- **Simultaneous requests on the same session** (a user double-submitting, two open tabs): the last save wins, and the other request's exchange is lost.
- **History grows with every message.** Each save rewrites the whole conversation, so very long chats get slower and cost more tokens. Call `clearHistory()` when a conversation is finished.
- **Any other PDO driver** (SQL Server, Oracle, ...) uses an UPDATE-then-INSERT fallback. Create the table yourself with equivalent types and pass `createTable: false`.
