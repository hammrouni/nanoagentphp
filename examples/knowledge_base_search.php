<?php

/**
 * NanoAgent Knowledge Base Search Example
 * 
 * Demonstrates a "Retrieval Augmented Generation" (RAG) style pattern.
 * The agent is equipped with a search tool that queries a project-specific 
 * knowledge repository (simulated) to answer sensitive or niche questions
 * without relying solely on the LLM's pre-trained knowledge.
 */

declare(strict_types=1);

require_once __DIR__ . '/../NanoAgent/autoloader.php';

use NanoAgent\Agent;
use NanoAgent\Tools\FunctionTool;

// --- 1. The Virtual Knowledge Base ---
/**
 * A simulated collection of internal company documents.
 * In a real-world scenario, this might query a SQL database or Vector Store.
 */
$knowledgeBase = [
    'policy_wfh' => [
        'title' => 'Remote Work Policy 2024',
        'content' => 'Employees are allowed to work remotely up to 3 days a week. Full remote work requires approval from a Director. Working from international locations is limited to 30 days per year due to tax implications.'
    ],
    'policy_holiday' => [
        'title' => 'Holiday Schedule 2024',
        'content' => 'The office is closed on New Year\'s Day, Memorial Day, Independence Day, Labor Day, Thanksgiving (and the day after), and Christmas Day.'
    ],
    'it_support' => [
        'title' => 'IT Support Contacts',
        'content' => 'For urgent issues, call ext 5555. For non-urgent requests, email support@company.com. Password resets can be done via portal.company.com.'
    ],
    'snacks' => [
        'title' => 'Office Kitchen Policy',
        'content' => 'Coffee and tea are free. Snacks in the blue bin are free. Unlabeled items in the fridge are cleared every Friday at 5 PM.'
    ]
];

// --- 2. Tool Definition ---
$logs = [];

/**
 * Creates a tool that performs a simple keyword search across the knowledge base.
 */
$searchTool = new FunctionTool(
    name: 'search_knowledge_base',
    description: 'Searches the internal company knowledge base for policy documents. Input search keywords.',
    parameters: [
        'type' => 'object', 
        'properties' => [
            'query' => ['type' => 'string', 'description' => 'The keywords to look for in the documentation']
        ], 
        'required' => ['query']
    ],
    callable: function(array $args) use ($knowledgeBase, &$logs) {
        $query = strtolower($args['query']);
        $hits = [];
        
        // Simple linear search through the documentation array.
        foreach ($knowledgeBase as $key => $doc) {
            if (str_contains(strtolower($doc['title']), $query) || str_contains(strtolower($doc['content']), $query)) {
                $hits[] = "Title: {$doc['title']}\nContent: {$doc['content']}";
            }
        }
        
        $count = count($hits);
        $logs[] = "🔎 Search Query: '{$query}' | Results Found: {$count}";
        
        if (empty($hits)) {
            return "No relevant documents found in the internal knowledge base.";
        }
        return implode("\n\n---\n\n", $hits);
    }
);

// --- 3. Agent Execution Logic ---
$userQuestion = "Can I work from Hawaii for two months?";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['question'])) {
    $userQuestion = trim($_POST['question']);
}

$response = "";

try {
    // 1. Resolve configuration.
    $configFile = __DIR__ . '/../NanoAgent/config.php';
    $config = file_exists($configFile) ? require $configFile : [];

    $llmConfig = [
        'provider' => $config['provider'] ?? 'groq',
        'model'    => $config['model']    ?? 'llama-3.3-70b-versatile',
        'api_key'  => $config['api_key']  ?? ''
    ];

    // 2. Initialize the Agent with HR-specific instructions.
    $agent = new Agent(
        llm: $llmConfig,
        systemPrompt: "You are a professional HR assistant. You must ONLY answer questions based on the provided search results. If the information is not present, politely inform the user. Always cite the document title.",
        tools: [$searchTool]
    );

    // 3. Process the question.
    $response = $agent->chat($userQuestion);

} catch (Throwable $e) {
    $response = "Internal Error: " . $e->getMessage();
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Knowledge Base Search - NanoAgent</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="index.php" class="nav-back">Back to Examples</a>
            <h1>Knowledge Base Search</h1>
            <p>Answer questions using a private document store.</p>
        </div>

        <?php include __DIR__ . '/components/agent_config.php'; ?>

        <div class="grid-split-inv">
            <div class="card">
                <h2>📚 Available Documents</h2>
                <ul class="doc-list">
                    <?php foreach ($knowledgeBase as $doc): ?>
                        <li>
                            <strong><?php echo htmlspecialchars($doc['title']); ?></strong>
                            <div class="doc-preview"><?php echo htmlspecialchars(substr($doc['content'], 0, 80)) . '...'; ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <h2 style="margin-top:2rem;">❓ Ask a Question</h2>
                <form method="POST">
                    <input type="text" name="question" value="<?php echo htmlspecialchars($userQuestion); ?>" style="width:100%; padding:0.5rem; margin-bottom:1rem;" required>
                    <button type="submit">Ask Agent</button>
                </form>
            </div>

            <div class="card">
                <h2>🤖 Answer</h2>
                
                <?php if (!empty($logs)): ?>
                    <div class="log-box" style="margin-bottom:1rem;">
                        <strong>Debug Logs:</strong><br>
                        <?php foreach ($logs as $log): ?>
                            <div><?php echo htmlspecialchars($log); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($response): ?>
                    <div class="agent-resp">
                        <?php echo nl2br(htmlspecialchars($response)); ?>
                    </div>
                <?php else: ?>
                    <p style="color:#888;">Waiting for question...</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
