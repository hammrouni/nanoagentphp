<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use NanoAgent\Agent;
use NanoAgent\Providers\MockProvider;
use NanoAgent\Contracts\Tool;
use ReflectionClass;

class AgentTest extends TestCase
{
    /**
     * Test: Automatic Configuration Loading
     */
    public function testAutoConfiguration()
    {
        $agent = new Agent();

        $reflector = new ReflectionClass($agent);
        $property  = $reflector->getProperty('provider');
        $property->setAccessible(true);
        $provider  = $property->getValue($agent);

        $this->assertNotNull($provider, "Agent instantiated, but provider is null.");
    }

    /**
     * Test: Chat with MockProvider
     */
    public function testChatWithMockProvider()
    {
        // Setup mock provider with a single response
        $mockProvider = new MockProvider([
            ['content' => 'Hello from Mock!']
        ]);

        $agent = new Agent(); // Config doesn't matter as we replace provider

        // Inject mock provider via Reflection
        $this->injectProvider($agent, $mockProvider);

        $response = $agent->chat("Hi");

        $this->assertEquals("Hello from Mock!", $response);
        
        // Verify request was captured
        $requests = $mockProvider->getCapturedRequests();
        $this->assertCount(1, $requests);
        $this->assertEquals('user', $requests[0]['messages'][1]['role']);
        $this->assertEquals('Hi', $requests[0]['messages'][1]['content']);
    }

    /**
     * Test: Context Injection
     */
    public function testContextInjection()
    {
        $mockProvider = new MockProvider([
            ['content' => 'Response']
        ]);

        $agent = new Agent();
        $this->injectProvider($agent, $mockProvider);

        $agent->addContext("UserTime", "12:00 PM");
        $agent->chat("Time?");

        $requests = $mockProvider->getCapturedRequests();
        $systemMessage = $requests[0]['messages'][0]['content'];
        
        $this->assertStringContainsString("UserTime", $systemMessage);
        $this->assertStringContainsString("12:00 PM", $systemMessage);
    }

    /**
     * Test: Tool Usage
     */
    public function testToolUsage()
    {
        // Define a simple anonymous tool class
        $tool = new class implements Tool {
            public function getName(): string { return 'calculator'; }
            public function toArray(): array { return ['name' => 'calculator']; }
            public function execute(array $args): mixed { return (string)($args['a'] + $args['b']); }
        };

        // We need 2 responses from mock:
        // 1. request to call tool
        // 2. final response
        $mockProvider = new MockProvider([
            [
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => 'call_1',
                        'function' => [
                            'name' => 'calculator',
                            'arguments' => json_encode(['a' => 5, 'b' => 3])
                        ]
                    ]
                ]
            ],
            [
                'content' => 'The result is 8'
            ]
        ]);

        $agent = new Agent([], '', [$tool]);
        $this->injectProvider($agent, $mockProvider);

        $response = $agent->chat("Add 5 and 3");

        $this->assertEquals("The result is 8", $response);

        // Verify history includes tool result
        $history = $agent->getHistory();
        
        // Expected history:
        // 0: user message
        // 1: assistant tool call
        // 2: tool result
        // 3: assistant final response
        $this->assertCount(4, $history);
        $this->assertEquals('tool', $history[2]['role']);
        $this->assertEquals('8', $history[2]['content']);
    }

    /**
     * Test: Streaming
     */
    public function testStreaming()
    {
        $mockProvider = new MockProvider([
            ['content' => 'Streamed Content']
        ]);

        $agent = new Agent();
        $this->injectProvider($agent, $mockProvider);

        $output = "";
        $result = $agent->stream("Stream me", function($chunk) use (&$output) {
            $output .= $chunk;
        });

        $this->assertEquals("Streamed Content", $output);
        $this->assertEquals("Streamed Content", $result);
    }

    /**
     * Helper to inject provider
     */
    private function injectProvider(Agent $agent, $provider)
    {
        $reflector = new ReflectionClass($agent);
        $property  = $reflector->getProperty('provider');
        $property->setAccessible(true);
        $property->setValue($agent, $provider);
    }
}
