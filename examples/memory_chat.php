<?php

/**
 * NanoAgent Memory Chat Example
 * 
 * Demonstrates persistent conversation history. Unlike standard sessions,
 * this example saves the agent's memory (history) into a local JSON file,
 * allowing the conversation to survive server restarts or being shared.
 */

declare(strict_types=1);

require_once __DIR__ . '/../NanoAgent/autoloader.php';

use NanoAgent\Agent;

$historyFile = __DIR__ . '/chat_history.json';

// Handle Reset
if (isset($_POST['reset'])) {
    if (file_exists($historyFile)) unlink($historyFile);
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

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

    // 2. Load existing history from JSON file
    if (file_exists($historyFile)) {
        $savedHistory = json_decode(file_get_contents($historyFile), true);
        if (is_array($savedHistory)) {
            $agent->setHistory($savedHistory);
        }
    }

} catch (Throwable $e) {
    $error = "Init error: " . $e->getMessage();
}

// 3. Handle Message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['message'])) {
    $userMessage = trim($_POST['message']);
    
    try {
        if (isset($agent)) {
            // Processing chat
            $agent->chat($userMessage);
            
            // 4. Save updated history back to file
            file_put_contents($historyFile, json_encode($agent->getHistory(), JSON_PRETTY_PRINT));
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
            <p>Persistent history saved to <code>chat_history.json</code>.</p>
        </div>

        <?php include __DIR__ . '/components/agent_config.php'; ?>

        <div class="chat-container">
            <div class="chat-header">
                <div>History Log</div>
                <form method="POST" style="margin:0;">
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
                <form method="POST" class="input-group">
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
