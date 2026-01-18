<?php

/**
 * NanoAgent API Integration Example
 * 
 * Demonstrates an agent fetching real-time data from an external public API
 * using the library's internal HttpClient utility within a FunctionTool.
 */

declare(strict_types=1);

require_once __DIR__ . '/../NanoAgent/autoloader.php';

use NanoAgent\Agent;
use NanoAgent\Tools\FunctionTool;


// --- 1. Define a Real API Tool ---

/**
 * Weather Tool using wttr.in (a real, public weather service).
 */
$weatherApiTool = new FunctionTool(
    name: 'fetch_live_weather',
    description: 'Retrieves current weather status for any city worldwide using a real meteorological API.',
    parameters: [
        'type' => 'object',
        'properties' => [
            'city' => ['type' => 'string', 'description' => 'The name of the city']
        ],
        'required' => ['city']
    ],
    callable: function(array $args) {
        $city = urlencode($args['city']);
        
        // Using wttr.in JSON format
        $url = "https://wttr.in/{$city}?format=j1";
        
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Increased timeout
            curl_setopt($ch, CURLOPT_USERAGENT, 'NanoAgent/1.0'); // Some APIs require a UA
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Fix for local dev environments
            
            $response = curl_exec($ch);
            
            if (curl_errno($ch)) {
                $error = curl_error($ch);
                curl_close($ch);
                return "Network error while reaching weather API: " . $error;
            }
            curl_close($ch);

            if (!$response) {
                return "Empty response from weather API.";
            }

            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return "Invalid JSON received from weather API: " . substr($response, 0, 100);
            }

            if (!isset($data['current_condition'][0])) {
                return "Could not find weather data for '{$args['city']}'. API might be rate limited or city not found.";
            }

            $current = $data['current_condition'][0];
            $temp = $current['temp_C'];
            $desc = $current['weatherDesc'][0]['value'];
            
            return "Current weather in {$args['city']}: {$desc}, Temperature: {$temp}°C.";

        } catch (Exception $e) {
            return "Error calling external API: " . $e->getMessage();
        }
    }
);

// --- 2. Setup Agent & Request ---

$city = $_GET['city'] ?? 'London';
$question = "What is the real-time weather in $city right now?";

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
        systemPrompt: "You are a meteorological assistant. You can retrieve real-time weather information for any city.",
        tools: [$weatherApiTool]
    );

    $response = $agent->chat($question);

} catch (Throwable $e) {
    $error = $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Integration - NanoAgent</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="index.php" class="nav-back">Back to Examples</a>
            <h1>🌍 Real-World API Integration</h1>
            <p>Fetching live data from <code>wttr.in</code> via Function Tools.</p>
        </div>

        <div class="grid-split">
            <div class="card">
                <h2>☁️ Live Weather lookup</h2>
                <form method="GET">
                    <label>Enter a City:</label>
                    <div style="display:flex; gap:0.5rem; margin-top:0.5rem;">
                        <input type="text" name="city" value="<?php echo htmlspecialchars($city); ?>" placeholder="e.g. New York, Tokyo..." required style="flex:1;">
                        <button type="submit">Fetch</button>
                    </div>
                </form>

                <div style="margin-top:2rem; font-size:0.875rem; color:#666; border-top:1px solid #eee; padding-top:1rem;">
                    <strong>Implementation Note:</strong><br>
                    This example uses standard cURL within a tool to demonstrate how the Agent can bridge the gap between AI reasoning and real-world network data.
                </div>
            </div>

            <div class="card" style="border-left: 4px solid #10b981;">
                <h2>🤖 Agent Response</h2>
                <div class="agent-resp">
                    <?php echo isset($response) ? nl2br(htmlspecialchars($response)) : 'Awaiting city...'; ?>
                </div>
                <?php if (isset($error)): ?>
                    <div class="error-box"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
