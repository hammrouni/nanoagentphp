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
     * Test: a Provider instance can be injected directly via the constructor,
     * bypassing string-based config resolution entirely (no api_key/provider
     * keys, no Reflection needed).
     */
    public function testProviderCanBeInjectedViaConstructor()
    {
        $mockProvider = new MockProvider([
            ['content' => 'Hello from injected provider!']
        ]);

        $agent = new Agent($mockProvider);

        $this->assertSame('Hello from injected provider!', $agent->chat('Hi'));
    }

    /**
     * Test: setProvider() swaps the backend provider after construction.
     */
    public function testSetProviderSwapsBackendProvider()
    {
        $firstProvider = new MockProvider([['content' => 'first']]);
        $secondProvider = new MockProvider([['content' => 'second']]);

        $agent = new Agent($firstProvider);
        $this->assertSame('first', $agent->chat('Hi'));

        $agent->setProvider($secondProvider);
        $this->assertSame('second', $agent->chat('Hi again'));
    }

    /**
     * Test: setMaxIterations() works for a directly-injected provider, which has
     * no config array to read 'max_iterations' from.
     */
    public function testSetMaxIterationsWorksWithInjectedProvider()
    {
        $tool = new class implements Tool {
            public function getName(): string { return 'noop'; }
            public function toArray(): array { return ['name' => 'noop']; }
            public function execute(array $args): mixed { return 'ok'; }
        };

        $toolCallResponse = [
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_x',
                    'function' => ['name' => 'noop', 'arguments' => json_encode([])]
                ]
            ]
        ];

        $mockProvider = new MockProvider(array_fill(0, 50, $toolCallResponse));

        $agent = new Agent($mockProvider, '', [$tool]);
        $agent->setMaxIterations(2);

        $this->assertSame(2, $agent->getMaxIterations());
        $this->expectException(\NanoAgent\Exceptions\AgentException::class);
        $agent->chat('Loop forever');
    }

    /**
     * Test: the string-based 'mock' provider no longer requires an api_key.
     */
    public function testMockProviderStringDoesNotRequireApiKey()
    {
        $agent = new Agent(['provider' => 'mock']);

        $reflector = new ReflectionClass($agent);
        $property = $reflector->getProperty('provider');
        $property->setAccessible(true);

        $this->assertInstanceOf(MockProvider::class, $property->getValue($agent));
    }

    /**
     * Test: base_url from config is forwarded to the resolved provider.
     */
    public function testBaseUrlIsForwardedToProvider()
    {
        $agent = new Agent([
            'provider' => 'openai',
            'api_key' => 'test_key',
            'model' => 'gpt-4o',
            'base_url' => 'https://proxy.example.com/v1',
        ]);

        $provider = $this->getPrivateProperty($agent, 'provider');
        $baseUrl = $this->getPrivateProperty($provider, 'baseUrl');

        $this->assertSame('https://proxy.example.com/v1', $baseUrl);
    }

    /**
     * Test: omitting base_url leaves the provider's own default in place.
     */
    public function testProviderKeepsDefaultBaseUrlWhenNotConfigured()
    {
        $agent = new Agent([
            'provider' => 'openai',
            'api_key' => 'test_key',
            'model' => 'gpt-4o',
        ]);

        $provider = $this->getPrivateProperty($agent, 'provider');
        $baseUrl = $this->getPrivateProperty($provider, 'baseUrl');

        $this->assertSame('https://api.openai.com/v1', $baseUrl);
    }

    private function getPrivateProperty(object $object, string $name)
    {
        $reflector = new ReflectionClass($object);
        $property = $reflector->getProperty($name);
        $property->setAccessible(true);
        return $property->getValue($object);
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
     * Test: Malformed tool-call arguments are reported back to the model
     * instead of crashing or silently passing null into the tool.
     */
    public function testToolUsageWithInvalidArgumentsJson()
    {
        $calls = [];
        $tool = new class implements Tool {
            public array $calls = [];
            public function getName(): string { return 'calculator'; }
            public function toArray(): array { return ['name' => 'calculator']; }
            public function execute(array $args): mixed
            {
                $this->calls[] = $args;
                return 'should not be called';
            }
        };

        $mockProvider = new MockProvider([
            [
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => 'call_1',
                        'function' => [
                            'name' => 'calculator',
                            'arguments' => '{not valid json'
                        ]
                    ]
                ]
            ],
            [
                'content' => 'Recovered after invalid arguments'
            ]
        ]);

        $agent = new Agent([], '', [$tool]);
        $this->injectProvider($agent, $mockProvider);

        $response = $agent->chat("Add 5 and 3");

        $this->assertEquals("Recovered after invalid arguments", $response);
        $this->assertCount(0, $tool->calls, "Tool must not execute with malformed arguments.");

        $history = $agent->getHistory();
        $this->assertEquals('tool', $history[2]['role']);
        $this->assertStringContainsString('invalid arguments JSON', $history[2]['content']);
    }

    /**
     * Test: a tool result that can't be JSON-encoded (e.g. invalid UTF-8) is caught
     * and surfaced as a normal error message, not a fatal error or a silently empty
     * tool result being fed to the model.
     */
    public function testToolResultThatCannotBeJsonEncodedIsCaught()
    {
        $tool = new class implements Tool {
            public function getName(): string { return 'broken_tool'; }
            public function toArray(): array { return ['name' => 'broken_tool']; }
            public function execute(array $args): mixed
            {
                // Invalid UTF-8 byte sequence: json_encode() will fail on this.
                return ['text' => "\xB1\x31"];
            }
        };

        $mockProvider = new MockProvider([
            [
                'content' => null,
                'tool_calls' => [
                    [
                        'id' => 'call_1',
                        'function' => ['name' => 'broken_tool', 'arguments' => '{}']
                    ]
                ]
            ],
            ['content' => 'Recovered after tool encoding error']
        ]);

        $agent = new Agent([], '', [$tool]);
        $this->injectProvider($agent, $mockProvider);

        $response = $agent->chat('Use the broken tool');

        $this->assertEquals('Recovered after tool encoding error', $response);

        $history = $agent->getHistory();
        $this->assertEquals('tool', $history[2]['role']);
        $this->assertStringStartsWith('Error executing tool:', $history[2]['content']);
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
     * Test: stream() now executes tool calls returned by the provider (previously
     * dropped silently) and streams the follow-up response after execution.
     */
    public function testStreamExecutesToolCalls()
    {
        $tool = new class implements Tool {
            public function getName(): string { return 'calculator'; }
            public function toArray(): array { return ['name' => 'calculator']; }
            public function execute(array $args): mixed { return (string)($args['a'] + $args['b']); }
        };

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
            ['content' => 'The result is 8']
        ]);

        $agent = new Agent([], '', [$tool]);
        $this->injectProvider($agent, $mockProvider);

        $output = '';
        $result = $agent->stream('Add 5 and 3', function ($chunk) use (&$output) {
            $output .= $chunk;
        });

        $this->assertSame('The result is 8', $result);
        $this->assertSame('The result is 8', $output);

        // Same history shape as chat()'s tool loop: user, assistant tool-call,
        // tool result, assistant final response.
        $history = $agent->getHistory();
        $this->assertCount(4, $history);
        $this->assertSame('tool', $history[2]['role']);
        $this->assertSame('8', $history[2]['content']);
    }

    /**
     * Test: stream()'s tool loop is capped the same way chat()'s is.
     */
    public function testStreamToolLoopIsCapped()
    {
        $tool = new class implements Tool {
            public function getName(): string { return 'noop'; }
            public function toArray(): array { return ['name' => 'noop']; }
            public function execute(array $args): mixed { return 'ok'; }
        };

        $toolCallResponse = [
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_x',
                    'function' => ['name' => 'noop', 'arguments' => json_encode([])]
                ]
            ]
        ];

        $mockProvider = new MockProvider(array_fill(0, 50, $toolCallResponse));

        $agent = new Agent(
            ['provider' => 'mock', 'api_key' => 'test', 'max_iterations' => 3],
            '',
            [$tool]
        );
        $this->injectProvider($agent, $mockProvider);

        $this->expectException(\NanoAgent\Exceptions\AgentException::class);

        $agent->stream('Loop forever', function () {});
    }

    /**
     * Test: Tool loop is capped to avoid infinite tool-calling loops
     */
    public function testToolLoopIsCapped()
    {
        $tool = new class implements Tool {
            public function getName(): string { return 'noop'; }
            public function toArray(): array { return ['name' => 'noop']; }
            public function execute(array $args): mixed { return 'ok'; }
        };

        // Always respond with another tool call, never a final answer.
        $toolCallResponse = [
            'content' => null,
            'tool_calls' => [
                [
                    'id' => 'call_x',
                    'function' => [
                        'name' => 'noop',
                        'arguments' => json_encode([])
                    ]
                ]
            ]
        ];

        $mockProvider = new MockProvider(array_fill(0, 50, $toolCallResponse));

        $agent = new Agent(
            ['provider' => 'mock', 'api_key' => 'test', 'max_iterations' => 3],
            '',
            [$tool]
        );
        $this->injectProvider($agent, $mockProvider);

        $this->assertSame(3, $agent->getMaxIterations());

        $this->expectException(\NanoAgent\Exceptions\AgentException::class);

        $agent->chat("Loop forever");
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
