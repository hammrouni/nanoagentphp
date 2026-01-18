<?php

/**
 * NanoAgent Structured Output Example
 * 
 * Demonstrates how to use Tools to extract strictly structured data (JSON) 
 * from unstructured natural language text. This common pattern is useful 
 * for data processing, form automation, and information extraction.
 */

declare(strict_types=1);

require_once __DIR__ . '/../NanoAgent/autoloader.php';

use NanoAgent\Agent;
use NanoAgent\Tools\FunctionTool;

// --- 1. Define the Data Schema via a Tool ---
$extractedData = null;

/**
 * We define a tool that represents the "schema" of the data we want to extract.
 * By instructing the AI to "call this tool to save results", we effectively
 * force it to produce output that matches our JSON schema.
 */
$extractionTool = new FunctionTool(
    name: 'save_profile',
    description: 'Saves the extracted user profile data into the system.',
    parameters: [
        'type' => 'object', 
        'properties' => [
            'full_name' => ['type' => 'string', 'description' => 'The person\'s full name'],
            'job_title' => ['type' => 'string', 'description' => 'Current or most recent job title'],
            'skills' => [
                'type' => 'array', 
                'items' => ['type' => 'string'],
                'description' => 'List of technical skills mentioned (e.g. PHP, Docker)'
            ],
            'experience_years' => ['type' => 'integer', 'description' => 'Total years of industry experience'],
            'is_open_to_work' => ['type' => 'boolean', 'description' => 'Whether the person is explicitly looking for new opportunities']
        ], 
        'required' => ['full_name', 'skills']
    ],
    // The callable captures the AI-generated arguments into a local variable for display.
    callable: function(array $args) use (&$extractedData) {
        $extractedData = $args;
        return "Internal: Profile for {$args['full_name']} captured for display.";
    }
);

// --- 2. Setup Agent & Input ---
$inputText = "Hi, I'm Sarah Jenkins. I've been a Senior PHP Developer for about 8 years now. I love working with Laravel, Symfony, and Docker. Currently, I'm leading a team of 4. I'm not actively looking, but open to interesting offers.";

// Support custom input via the web form.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['bio'])) {
    $inputText = trim($_POST['bio']);
}

$agentResponse = "";

try {
    // Load configuration.
    $configFile = __DIR__ . '/../NanoAgent/config.php';
    $config     = file_exists($configFile) ? require $configFile : [];

    $llmConfig = [
        'provider' => $config['provider'] ?? 'groq',
        'model'    => $config['model']    ?? 'llama-3.3-70b-versatile',
        'api_key'  => $config['api_key']  ?? ''
    ];

    // 1. Instantiate the Agent with strict extraction instructions.
    $agent = new Agent(
        llm: $llmConfig,
        systemPrompt: "You are a precise data extraction specialist. Your ONLY task is to identify profile details from the text and call the `save_profile` tool once. Answer only via tool call.",
        tools: [$extractionTool]
    );

    // 2. Process the text.
    $agentResponse = $agent->chat($inputText);

} catch (Throwable $e) {
    $agentResponse = "Extraction Failed: " . $e->getMessage();
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Structured Output - NanoAgent</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .json-box { background: #1e1e1e; color: #d4d4d4; padding: 1rem; border-radius: 4px; font-family: monospace; white-space: pre-wrap; overflow-x: auto; }
        .key { color: #9cdcfe; }
        .string { color: #ce9178; }
        .number { color: #b5cea8; }
        .boolean { color: #569cd6; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="index.php" class="nav-back">Back to Examples</a>
            <h1>Structured Data Extraction</h1>
            <p>Turn unstructured text into JSON data.</p>
        </div>

        <?php include __DIR__ . '/components/agent_config.php'; ?>

        <div class="grid-split">
            <div class="card">
                <h2>📝 Input Text</h2>
                <form method="POST">
                    <textarea name="bio" rows="6" style="width:100%; padding:0.5rem; border:1px solid #ddd; border-radius:4px; font-family:inherit;"><?php echo htmlspecialchars($inputText); ?></textarea>
                    <button type="submit" style="margin-top:1rem;">Extract Data</button>
                </form>
            </div>

            <div class="card">
                <h2>📊 Extracted Data</h2>
                <?php if ($extractedData): ?>
                    <div class="json-box"><?php 
                        $json = json_encode($extractedData, JSON_PRETTY_PRINT);
                        // Simple syntax highlighting
                        $json = preg_replace('/"([^"]+)":/', '<span class="key">"$1":</span>', $json);
                        $json = preg_replace('/"([^"]+)"(?=[,\s])/', '<span class="string">"$1"</span>', $json);
                        $json = preg_replace('/\b(\d+)\b/', '<span class="number">$1</span>', $json);
                        $json = preg_replace('/\b(true|false)\b/', '<span class="boolean">$1</span>', $json);
                        echo $json;
                    ?></div>
                    <div class="agent-resp" style="margin-top:1rem; font-size:0.9rem; color:#666;">
                        <strong>Agent Response:</strong> <?php echo htmlspecialchars($agentResponse); ?>
                    </div>
                <?php else: ?>
                    <div style="padding:2rem; text-align:center; color:#888;">
                        <?php if (str_contains($agentResponse, 'Error')): ?>
                            <div class="error-box"><?php echo htmlspecialchars($agentResponse); ?></div>
                        <?php else: ?>
                            <p>No data extracted yet. Try clicking "Extract Data" or check your API key.</p>
                            <?php if ($provider === 'mock') echo "<p><small>Note: MockProvider might not trigger tools dynamically.</small></p>"; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
