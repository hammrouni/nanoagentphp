<?php

/**
 * NanoAgent Memory Chat Example
 *
 * Demonstrates persistent conversation history. A memory driver attached to
 * the agent loads and saves the conversation automatically. Use the selector
 * to compare drivers:
 *   - FileMemory:  history survives page reloads and server restarts.
 *   - ArrayMemory: history lives only for the current request, so the agent
 *                  forgets everything on the next page load.
 *
 * Other drivers plug in the same way, e.g. PdoMemory::sqlite(__DIR__ . '/memory/chat.sqlite')
 */

declare(strict_types=1);

require_once __DIR__ . '/../NanoAgent/autoloader.php';

use NanoAgent\Agent;
use NanoAgent\Contracts\Memory;
use NanoAgent\Memory\ArrayMemory;
use NanoAgent\Memory\FileMemory;

// One shared conversation for the demo; in a real app use a user or chat id.
$sessionId = 'demo';

// Selected driver travels in the query string so every form keeps it.
$drivers = [
    'file'  => 'FileMemory (persistent)',
    'array' => 'ArrayMemory (current request only)',
];
$driver = isset($_GET['driver'], $drivers[$_GET['driver']]) ? $_GET['driver'] : 'file';
$selfUrl = $_SERVER['PHP_SELF'] . '?driver=' . urlencode($driver);

$memory = match ($driver) {
    'array' => new ArrayMemory(),
    default => new FileMemory(__DIR__ . '/memory'),
};

// 1. Initialize the Agent
try {
    $configFile = __DIR__ . '/../NanoAgent/config.php';
    $config = file_exists($configFile) ? require $configFile : [];
    $llmConfig = [
        'provider' => $config['provider'] ?? 'groq',
        'model'    => $config['model']    ?? 'llama-3.3-70b-versatile',
        'api_key'  => $config['api_key']  ?? ''
    ];

    $agent = new Agent(
        llm: $llmConfig,
        systemPrompt: "You are a helpful assistant with a perfect long-term memory."
    );

    // 2. Attach memory: loads any stored history and saves after every chat() call.
    $agent->setMemory($memory, $sessionId);

} catch (Throwable $e) {
    $error = "Init error: " . $e->getMessage();
}

// Handle Reset
if (isset($_POST['reset']) && isset($agent)) {
    $agent->clearHistory();
    header("Location: " . $selfUrl);
    exit;
}

// 3. Handle Message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['message'])) {
    $userMessage = trim($_POST['message']);

    try {
        if (isset($agent)) {
            // History is persisted automatically once the reply is complete.
            $agent->chat($userMessage);
        }
    } catch (Throwable $e) {
        $error = "Chat error: " . $e->getMessage();
    }
}

$history = isset($agent) ? $agent->getHistory() : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Memory Chat - NanoAgent</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="index.php" class="nav-back">Back to Examples</a>
            <h1>🧠 Memory Chat</h1>
            <?php if ($driver === 'array'): ?>
                <p><code>ArrayMemory</code> keeps history only for the current request: the agent forgets it on the next message.</p>
            <?php else: ?>
                <p>Persistent history stored by <code>FileMemory</code> in <code>examples/memory/</code>.</p>
            <?php endif; ?>
        </div>

        <?php include __DIR__ . '/components/agent_config.php'; ?>

        <div class="chat-container">
            <div class="chat-header">
                <form method="GET" style="margin:0; display:flex; align-items:center; gap:0.5rem;">
                    <label for="driver">Memory:</label>
                    <select id="driver" name="driver" onchange="this.form.submit()"
                            style="padding:0.4rem 0.6rem; border-radius:6px; border:none; font-size:0.85rem;">
                        <?php foreach ($drivers as $value => $label): ?>
                            <option value="<?php echo $value; ?>" <?php echo $value === $driver ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <noscript><button type="submit" class="reset">Switch</button></noscript>
                </form>
                <form method="POST" action="<?php echo htmlspecialchars($selfUrl); ?>" style="margin:0;">
                    <button type="submit" name="reset" value="1" class="reset">Wipe Memory</button>
                </form>
            </div>

            <div class="chat-messages" id="messages">
                <?php if (empty($history)): ?>
                    <div class="message system">Memory is empty. Start typing!</div>
                <?php endif; ?>

                <?php foreach ($history as $msg): ?>
                    <?php if ($msg['role'] === 'system') continue; ?>
                    <div class="message <?php echo htmlspecialchars($msg['role']); ?>">
                        <strong><?php echo ucfirst(htmlspecialchars($msg['role'])); ?>:</strong>
                        <div><?php echo nl2br(htmlspecialchars($msg['content'])); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="chat-input">
                <?php if (isset($error)): ?>
                    <div class="error-box"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <form method="POST" action="<?php echo htmlspecialchars($selfUrl); ?>" class="input-group">
                    <input type="text" name="message" placeholder="Ask me anything..." required autofocus autocomplete="off">
                    <button type="submit">Send</button>
                </form>
            </div>
        </div>
    </div>
    <script>
        const m = document.getElementById('messages');
        m.scrollTop = m.scrollHeight;
    </script>
</body>
</html>
