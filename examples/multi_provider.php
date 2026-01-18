<?php

/**
 * NanoAgent Multi-Provider Web Example
 * 
 * Demonstrates the library's ability to switch between different AI providers
 * programmatically. This script runs a connectivity test against multiple 
 * configured providers and displays the results.
 */

declare(strict_types=1);

require_once __DIR__ . '/../NanoAgent/autoloader.php';

use NanoAgent\Agent;

// 1. Configuration Setup
// Loads default values from the central configuration file.
$configFile = __DIR__ . '/../NanoAgent/config.php';
$defaultConfig = file_exists($configFile) ? require $configFile : [];

$apiKey   = $defaultConfig['api_key']  ?? '';
$provider = $defaultConfig['provider'] ?? 'groq';
$model    = $defaultConfig['model']    ?? 'llama-3.3-70b-versatile';

/**
 * Define a set of provider configurations to test.
 * This demonstrates how easy it is to target different platforms using the same API.
 */
$configs = [
    'Mock' => [
        'llm' => ['provider' => 'mock', 'model' => 'test-model', 'api_key' => 'mock-key'],
        'description' => 'Local internal mock provider for offline development.'
    ],
    'Groq' => [
        'llm' => ['provider' => $provider, 'model' => $model, 'api_key' => $apiKey ?: 'demo-key'],
        'description' => 'High-performance inference using the configured default.'
    ],
    'OpenAI' => [
        'llm' => ['provider' => 'openai', 'model' => 'gpt-4o', 'api_key' => getenv('OPENAI_API_KEY') ?: ''],
        'description' => 'Standard commercial provider (requires OPENAI_API_KEY envoy).'
    ]
];

$results = [];

// 2. Execution Loop
// Iterate through each configuration and attempt a simple "Hello" request.
foreach ($configs as $name => $config) {
    $result = [
        'name'     => $name, 
        'desc'     => $config['description'], 
        'status'   => 'Pending', 
        'response' => '', 
        'cls'      => ''
    ];
    
    // Check if a valid API Key is present for non-mock providers.
    if ($name !== 'Mock' && (empty($config['llm']['api_key']) || $config['llm']['api_key'] === 'demo-key')) {
        $result['status']   = 'Skipped';
        $result['response'] = 'No valid API Key detected for this provider.';
        $result['cls']      = 'skipped';
    } else {
        try {
            // Instantiate a temporary agent for each provider test.
            $agent = new Agent(
                llm: $config['llm'],
                systemPrompt: "You are testing the $name provider. Reply with a short message identifying yourself."
            );
            $response = $agent->chat("Verify connection.");
            
            $result['status']   = 'Success';
            $result['response'] = $response;
            $result['cls']      = 'success';
        } catch (Throwable $e) {
            $result['status']   = 'Error';
            $result['response'] = $e->getMessage();
            $result['cls']      = 'error';
        }
    }
    $results[] = $result;
}

// 3. Prepare Global Component Data
// Initialize a display agent for the common configuration header component.
try {
    $localLlmConfig = ['provider' => $provider, 'model' => $model, 'api_key' => $apiKey];
    $agent = new Agent(llm: $localLlmConfig);
} catch (\Throwable $e) {
    // Silently fail, header component handles missing agents gracefully.
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Multi-Provider Example - NanoAgent</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="index.php" class="nav-back">Back to Examples</a>
            <h1>Multi-Provider Example</h1>
            <p>Switch between different AI providers programmatically.</p>
        </div>

        <?php include __DIR__ . '/components/agent_config.php'; ?>

        <div class="card-grid">
            <?php foreach ($results as $res): ?>
                <div class="card">
                    <h2>
                        <?php echo htmlspecialchars($res['name']); ?>
                        <span class="status-badge <?php echo $res['cls']; ?>"><?php echo $res['status']; ?></span>
                    </h2>
                    <p><?php echo htmlspecialchars($res['desc']); ?></p>
                    <div class="response-box"><?php echo htmlspecialchars($res['response']); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>
