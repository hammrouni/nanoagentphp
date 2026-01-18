<?php

require_once __DIR__ . '/../NanoAgent/autoloader.php';

$configPath = __DIR__ . '/../NanoAgent/config.php';

if (!file_exists($configPath)) {
    // Create a dummy config for testing purposes
    $configContent = "<?php\n\nreturn [\n    'provider' => 'mock',\n    'api_key' => 'test_key',\n    'model' => 'test_model',\n];\n";
    file_put_contents($configPath, $configContent);

    // Register shutdown function to remove the dummy config after tests
    register_shutdown_function(function () use ($configPath) {
        if (file_exists($configPath)) {
            unlink($configPath);
        }
    });
}
