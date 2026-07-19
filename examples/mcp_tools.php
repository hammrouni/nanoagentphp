<?php

/**
 * NanoAgent MCP (Model Context Protocol) Example
 *
 * Demonstrates connecting the Agent to an external MCP server and letting it
 * call the tools that server exposes, exactly as it would call a local
 * FunctionTool. Uses DeepWiki's public MCP server (https://mcp.deepwiki.com/mcp)
 * as a free, no-auth endpoint to test against — it exposes tools for asking
 * questions about the source and docs of any public GitHub repository.
 */

declare(strict_types=1);

require_once __DIR__ . '/../NanoAgent/autoloader.php';

use NanoAgent\Agent;
use NanoAgent\Mcp\McpClient;

$mcpUrl = 'https://mcp.deepwiki.com/mcp';

// DeepWiki indexes public GitHub repos on demand, so an obscure/unvisited repo
// (e.g. this library's own) may not be indexed yet — default to one that's
// guaranteed to already be indexed, but let the user point it at any repo.
$repo = $_POST['repo'] ?? 'facebook/react';
$question = $_POST['question'] ?? 'What does this repository do, in two sentences?';

$discoveredTools = [];
$finalResponse = '';
$error = null;
$agent = null;

try {
    // --- 1. Connect to the MCP server and discover its tools ---
    $mcpClient = new McpClient($mcpUrl);

    $configFile = __DIR__ . '/../NanoAgent/config.php';
    $config = file_exists($configFile) ? require $configFile : [];
    $llmConfig = [
        'provider' => $config['provider'] ?? 'groq',
        'model'    => $config['model']    ?? 'llama-3.3-70b-versatile',
        'api_key'  => $config['api_key']  ?? ''
    ];

    $agent = new Agent(
        llm: $llmConfig,
        systemPrompt: "You are a research assistant with access to MCP tools for exploring GitHub repositories. "
            . "The user will name a repository as 'owner/repo'. Use the available tools to answer their question about it."
    );

    // Every tool the server reports becomes usable by the agent, just like a local FunctionTool.
    $discoveredTools = $agent->registerMcpServer($mcpClient);
    $agent->enableActivityLogging();

    // --- 2. Ask the agent a question that requires calling the MCP server ---
    $finalResponse = $agent->chat("Repository: {$repo}\nQuestion: {$question}");
} catch (Throwable $e) {
    $error = $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MCP Tools - NanoAgent</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="index.php" class="nav-back">Back to Examples</a>
            <h1>🔌 MCP Tools</h1>
            <p>The agent calls tools discovered from a remote MCP server (DeepWiki) instead of local PHP callables.</p>
        </div>

        <?php include __DIR__ . '/components/agent_config.php'; ?>

        <div class="card" style="border-left: 4px solid #14b8a6;">
            <h2>🧰 Tools Discovered from <?php echo htmlspecialchars($mcpUrl); ?></h2>
            <?php if (!empty($discoveredTools)): ?>
                <p><?php echo implode(', ', array_map(fn($t) => "<code>$t</code>", $discoveredTools)); ?></p>
            <?php else: ?>
                <p style="color:#888;">No tools discovered yet — the MCP handshake happens on page load; check the error box below if this stays empty.</p>
            <?php endif; ?>
        </div>

        <form method="POST" class="card" style="border-bottom: 4px solid #3b82f6;">
            <h2>Ask About a Repository</h2>
            <div class="input-group">
                <input type="text" name="repo" value="<?php echo htmlspecialchars($repo); ?>" placeholder="owner/repo">
            </div>
            <div class="input-group" style="margin-top:0.75rem;">
                <input type="text" name="question" value="<?php echo htmlspecialchars($question); ?>" placeholder="Your question">
                <button type="submit">Ask</button>
            </div>
        </form>

        <div class="grid-split-inv">
            <div class="card">
                <h2>⛓️ MCP Tool Calls</h2>
                <div class="log-box" style="font-size:0.875rem;">
                    <?php $logs = $agent ? $agent->getActivityLog() : []; ?>
                    <?php if (empty($logs)): ?>
                        <div style="color:#888;">No tool calls recorded yet. Submit a question above.</div>
                    <?php endif; ?>
                    <?php foreach ($logs as $log): ?>
                        <div class="log-item"><?php echo htmlspecialchars($log); ?></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <h2>🤖 Agent Response</h2>
                <div class="agent-resp">
                    <?php echo $finalResponse !== '' ? nl2br(htmlspecialchars($finalResponse)) : 'Processing...'; ?>
                </div>
                <?php if ($error): ?>
                    <div class="error-box"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
