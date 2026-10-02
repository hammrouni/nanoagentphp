<?php

/**
 * NanoAgent MCP (Model Context Protocol) Example
 *
 * Demonstrates connecting the Agent to an external MCP server and letting it
 * call the tools that server exposes, exactly as it would call a local
 * FunctionTool. Uses DeepWiki's public MCP server (https://mcp.deepwiki.com/mcp)
 * as a free, no-auth endpoint to test against — it exposes tools for asking
 * questions about the source and docs of any public GitHub repository.
 *
 * Two MCP features are demonstrated live:
 *   1. Discovery — McpClient::listTools() reports what the server offers and
 *      registers nothing, so the tool names are known *before* choosing.
 *   2. Allowlist — registerMcpServer() takes the names you pick. Every
 *      registered tool ships its JSON Schema on every request, so unchecked
 *      tools cost nothing. Re-calling it reconciles rather than duplicating:
 *      tools dropped from the list are removed from the agent.
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

$isSubmit = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

$availableTools = [];      // name => description, straight from the server
$discoveredTools = [];     // names actually registered on the agent
$finalResponse = '';
$error = null;
$agent = null;
$selected = [];
$skippedTools = [];

try {
    // --- 1. Connect to the MCP server and ask what it offers ---
    // listTools() performs the handshake and returns the raw tool list.
    // Nothing is registered on the agent yet.
    $mcpClient = new McpClient($mcpUrl);

    foreach ($mcpClient->listTools() as $tool) {
        $availableTools[$tool['name']] = $tool['description'] ?? '';
    }

    // On first load every tool is checked; afterwards the user's choice wins.
    // An absent 'tools' key on submit means every box was unticked — which is a
    // deliberate "register nothing", not a reason to fall back to all of them.
    if (!$isSubmit) {
        $selected = array_keys($availableTools);
    } else {
        $selected = isset($_POST['tools']) && is_array($_POST['tools'])
            ? array_values(array_filter($_POST['tools'], 'is_string'))
            : [];
    }

    // Drop anything the server does not expose. registerMcpServer() ignores
    // unknown names too, but filtering here keeps the UI honest.
    $selected = array_values(array_intersect($selected, array_keys($availableTools)));

    // --- 2. Build the agent and register only the selected tools ---
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

    if ($selected !== []) {
        // Each returned tool behaves exactly like a local FunctionTool, and the
        // return value tells you which ones were actually registered.
        $discoveredTools = $agent->registerMcpServer($mcpClient, $selected);
    }
    $skippedTools = array_values(array_diff(array_keys($availableTools), $discoveredTools));

    $agent->enableActivityLogging();

    // --- 3. Ask the agent a question that requires calling the MCP server ---
    if ($isSubmit) {
        if ($discoveredTools === []) {
            $error = 'Select at least one MCP tool before asking a question — '
                . 'with none registered the agent has nothing to call.';
        } else {
            $finalResponse = $agent->chat("Repository: {$repo}\nQuestion: {$question}");
        }
    }
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
            <h2>🧰 Step 1 — Discovery: what does <?php echo htmlspecialchars($mcpUrl); ?> offer?</h2>
            <p style="color:#64748b; font-size:0.9rem;">
                <code>McpClient::listTools()</code> asks the server for its tool list and registers nothing —
                so you can read the names before deciding which ones to allow.
            </p>
            <?php if ($availableTools === []): ?>
                <p style="color:#888;">No tools discovered — the MCP handshake happens on page load; check the error box below if this stays empty.</p>
            <?php else: ?>
                <p>
                    <span class="status-badge skipped"><?php echo count($availableTools); ?> available</span>
                    <?php if ($isSubmit): ?>
                        <span class="status-badge success"><?php echo count($discoveredTools); ?> registered</span>
                        <?php if ($skippedTools !== []): ?>
                            <span class="status-badge error"><?php echo count($skippedTools); ?> skipped</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>

        <form method="POST" class="card" style="border-bottom: 4px solid #3b82f6;">
            <h2>🎛️ Step 2 — Allowlist: pick the tools to register</h2>
            <p style="color:#64748b; font-size:0.9rem;">
                <code>registerMcpServer($client, allowlist: [...])</code> registers only what you tick.
                Every registered tool ships its JSON Schema to the model on <em>every</em> request, so
                unchecking a tool removes that cost. A name the server doesn't expose is ignored, not an error.
            </p>

            <?php if ($availableTools === []): ?>
                <p style="color:#888;">Nothing to select — discovery failed or the server is unreachable.</p>
            <?php else: ?>
                <div class="tool-picker">
                    <?php foreach ($availableTools as $toolName => $toolDescription): ?>
                        <?php $isChecked = in_array($toolName, $selected, true); ?>
                        <label class="tool-option">
                            <input type="checkbox" name="tools[]" value="<?php echo htmlspecialchars($toolName); ?>"
                                <?php echo $isChecked ? 'checked' : ''; ?>>
                            <span class="tool-name"><code><?php echo htmlspecialchars($toolName); ?></code></span>
                            <?php if ($toolDescription !== ''): ?>
                                <span class="tool-desc"><?php echo htmlspecialchars($toolDescription); ?></span>
                            <?php endif; ?>
                            <?php if ($isSubmit): ?>
                                <span class="status-badge <?php echo $isChecked ? 'success' : 'skipped'; ?>">
                                    <?php echo $isChecked ? 'registered' : 'not registered'; ?>
                                </span>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="input-group" style="margin-top:1rem;">
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

        <div class="card">
            <h2>🧾 What ran</h2>
            <?php if ($isSubmit): ?>
<pre style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:1rem; overflow-x:auto; font-size:0.85rem;">// 1. Discover — registers nothing
$available = $mcpClient-&gt;listTools();

// 2. Register only the ticked tools
<?php if ($selected !== []): ?>
$discovered = $agent-&gt;registerMcpServer($mcpClient, [<?php echo implode(', ', array_map(
    fn(string $name) => "'" . $name . "'",
    $selected
)); ?>]);
<?php else: ?>
// (nothing ticked — registerMcpServer() is skipped and the agent gets no tools)
$discovered = [];
<?php endif; ?>

// 3. Chat — the agent may now call only the tools above</pre>
            <?php endif; ?>
            <?php if ($isSubmit && $skippedTools !== []): ?>
                <p style="color:#64748b; font-size:0.9rem;">
                    Not registered this run (their schemas stay out of the payload):
                    <?php foreach ($skippedTools as $skipped): ?>
                        <code><?php echo htmlspecialchars($skipped); ?></code>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
