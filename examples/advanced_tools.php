<?php

/**
 * NanoAgent Advanced Tools Web Example
 * 
 * This example demonstrates complex multi-step tool usage within a web interface.
 * It simulates a real-world scenario (inventory management) where an agent must
 * query state, make decisions, and perform actions (ordering) that mutate state.
 */

declare(strict_types=1);

require_once __DIR__ . '/../NanoAgent/autoloader.php';

use NanoAgent\Agent;
use NanoAgent\Tools\FunctionTool;

// --- Simulated Database & Business Logic ---
/**
 * Simple in-memory product database to simulate real-world data persistence.
 */
class ProductDatabase {
    private array $products = [
        'p1' => ['name' => 'Quantum Laptop', 'price' => 1500, 'stock' => 5],
        'p2' => ['name' => 'Nano Phone', 'price' => 800, 'stock' => 0],
        'p3' => ['name' => 'AI Headset', 'price' => 300, 'stock' => 12],
    ];

    /**
     * Retrieve a product by its ID.
     */
    public function getProduct(string $id): ?array 
    { 
        return $this->products[$id] ?? null; 
    }

    /**
     * Search for products with names containing the query string.
     */
    public function search(string $query): array 
    {
        $results = [];
        foreach ($this->products as $id => $p) {
            if (stripos($p['name'], $query) !== false) {
                $results[$id] = $p;
            }
        }
        return $results;
    }

    /**
     * Mutate state by placing an order for a product.
     */
    public function order(string $id, int $quantity): string 
    {
        if (!isset($this->products[$id])) {
            return "Error: Product not found.";
        }
        if ($this->products[$id]['stock'] < $quantity) {
            return "Error: Insufficient stock.";
        }
        $this->products[$id]['stock'] -= $quantity;
        return "Success: Ordered $quantity of {$this->products[$id]['name']}. New stock: {$this->products[$id]['stock']}";
    }

    /**
     * Get the entire inventory list.
     */
    public function getInventory(): array 
    { 
        return $this->products; 
    }
}

// Initialize the simulated database.
$db = new ProductDatabase();

// Define a tool for searching the product catalog.
$searchTool = new FunctionTool(
    name: 'search_products',
    description: 'Search for products by name in the store inventory.',
    parameters: [
        'type' => 'object', 
        'properties' => ['query' => ['type' => 'string', 'description' => 'The product name or keyword to search for']], 
        'required' => ['query'],
        'additionalProperties' => false
    ],
    callable: fn(array $args) => $db->search($args['query'])
);

// Define a tool for placing orders.
$orderTool = new FunctionTool(
    name: 'place_order',
    description: 'Place an order for a specific product by its ID.',
    parameters: [
        'type' => 'object', 
        'properties' => [
            'product_id' => ['type' => 'string', 'description' => 'The unique ID of the product'], 
            'quantity' => ['type' => 'integer', 'description' => 'How many units to purchase']
        ], 
        'required' => ['product_id', 'quantity'],
        'additionalProperties' => false
    ],
    callable: fn(array $args) => $db->order($args['product_id'], $args['quantity'])
);

// --- Agent Execution & State Recording ---
$userRequest = "I want to buy 2 Quantum Laptops. Check stock and order.";
$agentResponse = "";
$logs = [];

try {
    // 1. Attempt to load global configuration.
    $configFile = __DIR__ . '/../NanoAgent/config.php';
    $config = file_exists($configFile) ? require $configFile : [];

    // 2. Prepare the LLM configuration.
    $llmConfig = [
        'provider' => $config['provider'],
        'model'    => $config['model'],
        'api_key'  => $config['api_key']
    ];

    // 3. Instantiate the Agent with the inventory tools.
    $agent = new Agent(
        llm: $llmConfig,
        systemPrompt: "You are a helpful retail sales assistant. You have access to tools 'search_products' and 'place_order'. You MUST use these tools to check availability and place orders. Do not guess stock levels.",
        tools: [$searchTool, $orderTool]
    );

    // 4. Enable standard activity logging.
    $agent->enableActivityLogging();

    // 5. Process the user's request.
    $agentResponse = $agent->chat($userRequest);

} catch (Throwable $e) {
    $agentResponse = "Fatal Error: " . $e->getMessage();
}

// Capture final inventory state after agent interaction.
$inventory = $db->getInventory();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Advanced Tools Example - NanoAgent</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .user-req { background: #e0e7ff; padding: 1rem; border-left: 4px solid #4338ca; border-radius: 4px; font-style: italic; margin-bottom: 2rem; }
        .agent-resp { background: #f0fdf4; padding: 1rem; border-left: 4px solid #16a34a; border-radius: 4px; margin-top: 2rem; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="index.php" class="nav-back">Back to Examples</a>
            <h1>Advanced Tools Example</h1>
            <p>Simulated tool execution and state management.</p>
        </div>

        <div class="user-req">
            <strong>User Request:</strong> "<?php echo htmlspecialchars($userRequest); ?>"
        </div>

        <?php include __DIR__ . '/components/agent_config.php'; ?>

        <div class="grid-split">
            <div class="card">
                <h2>🔍 Agent Execution Logs</h2>
                <div class="log-box">
                    <?php 
                    $logs = isset($agent) ? $agent->getActivityLog() : $logs;
                    if (empty($logs)) echo "<div>No logs recorded (MockProvider may not trigger tools without specific config).</div>"; ?>
                    <?php foreach ($logs as $log): ?>
                        <div class="log-item"><?php echo htmlspecialchars($log); ?></div>
                    <?php endforeach; ?>
                </div>
                
                <div class="agent-resp">
                    <strong>Agent Final Response:</strong><br>
                    <?php echo nl2br(htmlspecialchars($agentResponse)); ?>
                </div>
            </div>

            <div class="card">
                <h2>📦 Live Inventory</h2>
                <p>State is updated in real-time by the Agent.</p>
                <table>
                    <thead>
                        <tr><th>Product</th><th>Price</th><th>Stock</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inventory as $id => $p): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($p['name']); ?></td>
                            <td>$<?php echo $p['price']; ?></td>
                            <td class="<?php echo $p['stock'] > 0 ? 'stock-good' : 'stock-low'; ?>">
                                <?php echo $p['stock']; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
