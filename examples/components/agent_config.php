<?php
/**
 * Component: Agent Configuration Display Card
 * 
 * Renders a visual feedback block showing the active AI Provider and Model 
 * being used by the given Agent instance. Helps users track which LLM is processing 
 * their request in multi-provider environments.
 * 
 * @var \NanoAgent\Agent $agent The agent instance whose configuration to display.
 */

// Verify that the component has access to a valid Agent instance.
if (!isset($agent) || !($agent instanceof \NanoAgent\Agent)) {
    echo "<!-- Agent instance not found for configuration component -->";
    return;
}

// Extract configuration metadata from the agent.
$llmConfig     = $agent->getLlmConfig();
$modelStr      = $llmConfig['model'] ?? '';
$providerParam = $llmConfig['provider'] ?? '';

// Determine the display labels for Provider and Model.
// Supports both the "provider/model" combined string and explicit 'provider' key.
if (str_contains($modelStr, '/')) {
    [$providerDisplay, $modelDisplay] = explode('/', $modelStr, 2);
} else {
    $providerDisplay = $providerParam ?: 'Unknown';
    $modelDisplay    = $modelStr;
}

// Capitalize the provider name for a more professional UI appearance.
$providerDisplay = ucfirst($providerDisplay);
?>
<div class="card card-highlight">
    <h2>ℹ️ Configuration</h2>
    <p>
        <span class="status-badge skipped">Provider: <?php echo htmlspecialchars($providerDisplay); ?></span>
        <span class="status-badge skipped">Model: <?php echo htmlspecialchars($modelDisplay); ?></span>
    </p>
</div>
