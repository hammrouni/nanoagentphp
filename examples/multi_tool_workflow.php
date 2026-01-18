<?php

/**
 * NanoAgent Multi-Tool Workflow Example
 * 
 * Demonstrates an agent orchestrating multiple specialized tools 
 * in a single logical chain to solve a complex, multi-step user request.
 */

declare(strict_types=1);

require_once __DIR__ . '/../NanoAgent/autoloader.php';

use NanoAgent\Agent;
use NanoAgent\Tools\FunctionTool;

// --- 1. Define Specialized Tools ---

$locationTool = new FunctionTool(
    name: 'detect_user_location',
    description: 'Determines the users current city and country based on their IP or system context.',
    parameters: ['type' => 'object', 'properties' => new stdClass()],
    callable: fn() => ['city' => 'Paris', 'country' => 'France']
);

$weatherTool = new FunctionTool(
    name: 'get_current_weather',
    description: 'Fetches the live weather forecast for a given city.',
    parameters: [
        'type' => 'object',
        'properties' => [
            'city' => ['type' => 'string', 'description' => 'The city name']
        ],
        'required' => ['city']
    ],
    callable: function(array $args) {
        $observations = [
            'Paris' => 'Cloudy, 14°C',
            'London' => 'Rainy, 11°C',
            'New York' => 'Sunny, 22°C'
        ];
        return $observations[$args['city']] ?? 'Weather data unavailable for this location.';
    }
);

$eventTool = new FunctionTool(
    name: 'search_local_events',
    description: 'Searches for upcoming cultural events or concerts in a specific city.',
    parameters: [
        'type' => 'object',
        'properties' => [
            'city' => ['type' => 'string', 'description' => 'The city name']
        ],
        'required' => ['city']
    ],
    callable: function(array $args) {
        if ($args['city'] === 'Paris') {
            return [
                ['name' => 'Jazz Festival at Le Caveau', 'time' => '20:00'],
                ['name' => 'Art Exhibition: Monet at Grand Palais', 'time' => '10:00-18:00']
            ];
        }
        return "No specific events found for {$args['city']} today.";
    }
);

// --- 2. Initialize Agent with the Tool Suite ---

$userRequest = $_GET['q'] ?? "Check where I am, get the weather there, and see if there are any jazz events tonight.";
$logs = [];

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
        systemPrompt: "You are a highly efficient personal concierge. You have access to location, weather, and event tools. When answering, first describe the location and weather, then list any relevant events.",
        tools: [$locationTool, $weatherTool, $eventTool]
    );

    // Capture tool execution logs
    $agent->enableActivityLogging();

    // 3. Execute the Workflow
    $finalResponse = $agent->chat($userRequest);

} catch (Throwable $e) {
    $error = $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Multi-Tool Workflow - NanoAgent</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="index.php" class="nav-back">Back to Examples</a>
            <h1>🛠️ Multi-Tool Workflow</h1>
            <p>The agent orchestrates three separate tools to solve one request.</p>
        </div>

        <?php 
        // Set context for the config component
        $agent = $agent ?? null; 
        include __DIR__ . '/components/agent_config.php'; 
        ?>

        <form method="GET" class="card" style="border-bottom: 4px solid #3b82f6;">
            <h2>User Request</h2>
            <div class="input-group">
                <input type="text" name="q" value="<?php echo htmlspecialchars($userRequest); ?>" placeholder="Ask something (e.g. check weather in Paris)">
                <button type="submit">Run Workflow</button>
            </div>
        </form>

        <div class="grid-split-inv">
            <div class="card">
                <h2>⛓️ Agent Logic (Tool Execution)</h2>
                <div class="log-box" style="font-size:0.875rem;">
                    <?php 
                    $logs = isset($agent) ? $agent->getActivityLog() : [];
                    if (empty($logs)): ?>
                        <div style="color:#888;">No tool calls recorded yet. Ensure you are using a provider that supports tool-calling (e.g. Groq, OpenAI).</div>
                    <?php endif; ?>
                    <?php foreach ($logs as $log): ?>
                        <div class="log-item"><?php echo htmlspecialchars($log); ?></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <h2>🤖 Concierge Response</h2>
                <div class="agent-resp">
                    <?php echo isset($finalResponse) ? nl2br(htmlspecialchars($finalResponse)) : 'Processing...'; ?>
                </div>
                <?php if (isset($error)): ?>
                    <div class="error-box"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
