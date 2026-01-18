<?php

/**
 * NanoAgent Streaming Example
 * 
 * Demonstrates real-time token streaming using Server-Sent Events (SSE).
 * This allows the AI to "type" its response in the browser as it's being generated.
 */

declare(strict_types=1);

require_once __DIR__ . '/../NanoAgent/autoloader.php';

use NanoAgent\Agent;

// --- 1. Handle the Streaming Request (SSE) ---
if (isset($_GET['stream']) && !empty($_GET['message'])) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no'); // Disable buffering for Nginx

    $userMessage = trim($_GET['message']);

    try {
        // Load configuration
        $configFile = __DIR__ . '/../NanoAgent/config.php';
        $config = file_exists($configFile) ? require $configFile : [];

        $llmConfig = [
            'provider' => $config['provider'] ?? 'groq',
            'model'    => $config['model']    ?? 'llama-3.3-70b-versatile',
            'api_key'  => $config['api_key']  ?? ''
        ];

        $agent = new Agent(
            llm: $llmConfig,
            systemPrompt: "You are a helpful and concise streaming assistant."
        );

        // Execute streaming
        $agent->stream($userMessage, function($token) {
            // Send token to browser in SSE format
            echo "data: " . json_encode(['token' => $token]) . "\n\n";
            ob_flush();
            flush();
        });

        // Signal completion
        echo "data: [DONE]\n\n";
        ob_flush();
        flush();

    } catch (Throwable $e) {
        echo "data: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
    }
    exit;
}

// --- 2. Render the Web Interface ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Streaming Chat - NanoAgent</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .token-stream { color: #111827; line-height: 1.6; white-space: pre-wrap; }
        .typing-indicator { color: #6b7280; font-style: italic; font-size: 0.875rem; display: none; margin-top: 0.5rem; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="index.php" class="nav-back">Back to Examples</a>
            <h1>⚡ Streaming Chat</h1>
            <p>Real-time token delivery using Server-Sent Events.</p>
        </div>

        <div class="chat-container" style="height: 500px;">
            <div class="chat-header">
                <div>Live Output Stream</div>
                <div id="status" style="font-size: 0.8rem; opacity: 0.8;">Ready</div>
            </div>

            <div class="chat-messages" id="chat">
                <div id="response" class="token-stream">Type a message below to see streaming in action...</div>
                <div id="indicator" class="typing-indicator">Agent is thinking...</div>
            </div>

            <div class="chat-input">
                <div class="input-group">
                    <input type="text" id="userInput" placeholder="Ask something..." autofocus autocomplete="off">
                    <button id="sendBtn" onclick="sendStream()">Send Request</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function sendStream() {
            const input = document.getElementById('userInput');
            const responseDiv = document.getElementById('response');
            const indicator = document.getElementById('indicator');
            const status = document.getElementById('status');
            const sendBtn = document.getElementById('sendBtn');
            const message = input.value.trim();

            if (!message) return;

            // UI Reset
            responseDiv.innerText = '';
            indicator.style.display = 'block';
            status.innerText = 'Streaming...';
            input.value = '';
            input.disabled = true;
            sendBtn.disabled = true;

            // Initialize EventSource for SSE
            const url = `streaming.php?stream=1&message=${encodeURIComponent(message)}`;
            const eventSource = new EventSource(url);

            eventSource.onmessage = function(event) {
                if (event.data === '[DONE]') {
                    eventSource.close();
                    indicator.style.display = 'none';
                    status.innerText = 'Completed';
                    input.disabled = false;
                    sendBtn.disabled = false;
                    input.focus();
                    return;
                }

                try {
                    const data = JSON.parse(event.data);
                    if (data.token) {
                        responseDiv.innerText += data.token;
                        // Auto scroll
                        const chat = document.getElementById('chat');
                        chat.scrollTop = chat.scrollHeight;
                    }
                    if (data.error) {
                        responseDiv.innerHTML = `<span style="color:red">Error: ${data.error}</span>`;
                        eventSource.close();
                        indicator.style.display = 'none';
                        status.innerText = 'Error';
                        input.disabled = false;
                        sendBtn.disabled = false;
                    }
                } catch (e) {
                    console.error("Failed to parse SSE data", e);
                }
            };

            eventSource.onerror = function(err) {
                console.error("EventSource failed:", err);
                eventSource.close();
                indicator.style.display = 'none';
                status.innerText = 'Connection Failed';
                input.disabled = false;
                sendBtn.disabled = false;
            };
        }

        // Allow Enter key
        document.getElementById('userInput').addEventListener('keypress', function (e) {
            if (e.key === 'Enter') sendStream();
        });
    </script>
</body>
</html>
