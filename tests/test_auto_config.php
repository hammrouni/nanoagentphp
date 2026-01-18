<?php

/**
 * Test: Automatic Configuration Loading
 * 
 * Verifies that the Agent class correctly loads the global configuration 
 * from `config.php` when instantiated without explicit LLM parameters.
 */

declare(strict_types=1);

// Use the library's manual autoloader to resolve namespaces.
require_once __DIR__ . '/../NanoAgent/autoloader.php';

use NanoAgent\Agent;

echo "<h3>Testing Agent Auto-Configuration</h3>";

try {
    // 1. Attempt to instantiate the Agent without arguments.
    // This should trigger the internal logic to locate and require the config file.
    $agent = new Agent();
    
    // 2. Verify that a provider was successfully resolved using Reflection.
    // Since the 'provider' property is private, we use reflection for deep inspection.
    $reflector = new ReflectionClass($agent);
    $property  = $reflector->getProperty('provider');
    $property->setAccessible(true);
    $provider  = $property->getValue($agent);
    
    if ($provider) {
        echo "<span style='color:green; font-weight:bold;'>SUCCESS:</span> Agent resolved provider: <code>" . get_class($provider) . "</code><br>";
    } else {
        echo "<span style='color:red; font-weight:bold;'>FAILURE:</span> Agent instantiated, but provider is null.<br>";
    }

} catch (\Throwable $e) {
    echo "<span style='color:red; font-weight:bold;'>FAILURE:</span> An exception occurred during initialization.<br>";
    echo "<blockquote>" . htmlspecialchars($e->getMessage()) . "</blockquote>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
