<?php

/**
 * NanoAgent Web Chat Example
 * 
 * Demonstrates how to build a persistent, session-aware chat interface.
 * Conversation history is stored in the PHP SESSION variable to maintain 
 * context across multiple HTTP requests.
 */

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../NanoAgent/autoloader.php';

use NanoAgent\Agent;

// Ensure the history array exists in the session.
if (!isset($_SESSION['history'])) {
    $_SESSION['history'] = [];
}

// Handle request to reset the conversation history.
if (isset($_POST['reset'])) {
    $_SESSION['history'] = [];
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

$error = null;

// Load LLM configuration from the global config.php file.
$configFile = __DIR__ . '/../NanoAgent/config.php';
$config = file_exists($configFile) ? require $configFile : [];

$llmConfig = [
    'provider' => $config['provider'] ?? 'groq',
    'model'    => $config['model']    ?? 'llama-3.3-70b-versatile',
    'api_key'  => $config['api_key']  ?? ''
];

try {
    // Instantiate the Agent.
    $agent = new Agent(
        llm: $llmConfig,
        systemPrompt: "You are a helpful and witty web assistant. Return answers in Markdown."
    );
} catch (Throwable $e) {
    $error = "Agent initialization failed: " . $e->getMessage();
}

// Process user input from the chat form.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['message'])) {
    $userMessage = trim($_POST['message']);
    
    // 1. Persist the user message to history.
    $_SESSION['history'][] = ['role' => 'user', 'content' => $userMessage];

    try {
        if (isset($agent)) {
             // 2. Query the AI Agent.
             $response = $agent->chat($userMessage);
             
             // 3. Persist the agent's response to history.
             $_SESSION['history'][] = ['role' => 'assistant', 'content' => $response];
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $_SESSION['history'][] = ['role' => 'system', 'content' => "Error: " . $e->getMessage()];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat Example - NanoAgent</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="index.php" class="nav-back">Back to Examples</a>
            <h1>NanoAgent Chat</h1>
            <p>Persistent conversation with history.</p>
        </div>

        <?php include __DIR__ . '/components/agent_config.php'; ?>

        <div class="chat-container">
            <div class="chat-header">
                <div style="font-weight:600;">Conversation</div>
                <form method="POST" style="margin:0;">
                    <button type="submit" name="reset" value="1" class="reset">Clear History</button>
                </form>
            </div>
            
            <div class="chat-messages" id="messages">
                <?php if (empty($_SESSION['history'])): ?>
                    <div class="message system">Start a conversation!</div>
                <?php endif; ?>
                
                <?php foreach ($_SESSION['history'] as $msg): ?>
                    <div class="message <?php echo htmlspecialchars($msg['role']); ?>">
                        <strong><?php echo ucfirst(htmlspecialchars($msg['role'])); ?>:</strong>
                        <?php echo nl2br(htmlspecialchars($msg['content'])); ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="chat-input">
                <form method="POST" class="input-group">
                    <input type="text" name="message" placeholder="Type your message..." required autofocus autocomplete="off">
                    <button type="submit">Send</button>
                </form>
            </div>
        </div>
    </div>
    <script>
        // Auto-scroll to bottom
        const messages = document.getElementById('messages');
        messages.scrollTop = messages.scrollHeight;
    </script>
</body>
</html>
