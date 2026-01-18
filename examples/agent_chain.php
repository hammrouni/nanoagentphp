<?php

/**
 * NanoAgent Agent Chain Example
 * 
 * Demonstrates a multi-agent workflow where the output of one agent 
 * becomes the input (context) for another agent.
 * 
 * Workflow: Researcher Agent -> Writer Agent
 */

declare(strict_types=1);

require_once __DIR__ . '/../NanoAgent/autoloader.php';

use NanoAgent\Agent;
use NanoAgent\Task;
use NanoAgent\Tools\FunctionTool;

// --- 1. Define a Mock Research Tool ---
$researchTool = new FunctionTool(
    name: 'fetch_topic_facts',
    description: 'Retrieves interesting facts about a given topic.',
    parameters: [
        'type' => 'object',
        'properties' => [
            'topic' => ['type' => 'string', 'description' => 'The subject to research']
        ],
        'required' => ['topic']
    ],
    callable: function(array $args) {
        $topic = strtolower($args['topic']);
        $knowledge = [
            'php' => [
                'Created by Rasmus Lerdorf in 1994.',
                'Originally stood for Personal Home Page.',
                'Powers over 75% of all websites with a known server-side programming language.',
                'The latest version is 8.4.'
            ],
            'ai' => [
                'The term Artificial Intelligence was coined in 1956.',
                'Neural networks are inspired by the human brain.',
                'Generative AI creates new content from existing patterns.',
                'Transformer architecture (2017) revolutionized natural language processing.'
            ]
        ];

        return $knowledge[$topic] ?? ["No specific facts found for '{$topic}', but it is an interesting subject!"];
    }
);

$topic = $_GET['topic'] ?? 'PHP';
$logs = [];

try {
    // Load config
    $configFile = __DIR__ . '/../NanoAgent/config.php';
    $config = file_exists($configFile) ? require $configFile : [];
    $llmConfig = [
        'provider' => $config['provider'] ?? 'groq',
        'model'    => $config['model']    ?? 'llama-3.3-70b-versatile',
        'api_key'  => $config['api_key']  ?? ''
    ];

    // --- 2. Initialize the Researcher Agent ---
    $researcher = new Agent(
        llm: $llmConfig,
        systemPrompt: "You are a research specialist. Use the 'fetch_topic_facts' tool to gather raw data about the request. Return only the collected facts in a list.",
        tools: [$researchTool]
    );

    // --- 3. Initialize the Writer Agent ---
    $writer = new Agent(
        llm: $llmConfig,
        systemPrompt: "You are a creative content writer. Transform raw research facts into an engaging, professional social media post with hashtags. Do not invent facts that were not provided in the research."
    );

    // --- 4. Execute the Chain ---
    $logs[] = "Starting Researcher for topic: $topic...";
    $researchResults = $researcher->chat("Research the topic: $topic");
    $logs[] = "Researcher finished.";

    $logs[] = "Starting Writer with research results...";
    $finalPost = $writer->chat("Write a post based on these facts:\n\n" . $researchResults);
    $logs[] = "Writer finished.";

} catch (Throwable $e) {
    $error = $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Agent Chain - NanoAgent</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="index.php" class="nav-back">Back to Examples</a>
            <h1>🔗 Agent Chaining</h1>
            <p>Multi-agent workflow using sequential execution.</p>
        </div>

        <?php 
        // Set context for the config component
        $agent = $researcher ?? null; 
        include __DIR__ . '/components/agent_config.php'; 
        ?>

        <form method="GET" class="card" style="margin-bottom:2rem;">
            <div class="input-group">
                <input type="text" name="topic" value="<?php echo htmlspecialchars($topic); ?>" placeholder="Topic (e.g. PHP, AI)">
                <button type="submit">Run Chain</button>
            </div>
        </form>

        <div class="grid-split">
            <div class="card">
                <h2>🔍 Phase 1: Researcher</h2>
                <div class="response-box" style="background:#f0fbff; color:#0c4a6e;">
                    <?php echo isset($researchResults) ? nl2br(htmlspecialchars($researchResults)) : '...'; ?>
                </div>
                
                <h2 style="margin-top:2rem;">📜 Logs</h2>
                <div class="log-box">
                    <?php foreach ($logs as $log): ?>
                        <div class="log-item">> <?php echo htmlspecialchars($log); ?></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <h2>✍️ Phase 2: Writer</h2>
                <div class="agent-resp">
                    <?php echo isset($finalPost) ? nl2br(htmlspecialchars($finalPost)) : '...'; ?>
                </div>
                <?php if (isset($error)): ?>
                    <div class="error-box"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
