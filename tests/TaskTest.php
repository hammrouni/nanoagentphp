<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use NanoAgent\Task;
use NanoAgent\Agent;
use NanoAgent\Providers\MockProvider;
use ReflectionClass;

class TaskTest extends TestCase
{
    public function testTaskExecution()
    {
        // Setup Agent with MockProvider
        $mockProvider = new MockProvider([
            ['content' => 'Task Completed']
        ]);
        $agent = new Agent();
        $this->injectProvider($agent, $mockProvider);

        $task = new Task($agent);
        $task->addContext("Project", "Secret Project");
        
        $response = $task->execute("Do work");

        $this->assertEquals("Task Completed", $response);
        
        // Check if context was passed to agent
        $requests = $mockProvider->getCapturedRequests();
        $systemMsg = $requests[0]['messages'][0]['content'];
        $userMsg = $requests[0]['messages'][1]['content'];

        $this->assertStringContainsString("Secret Project", $systemMsg); // Context in system prompt
        $this->assertStringContainsString("Do work", $userMsg); // Goal is in user prompt
        $this->assertStringContainsString("Goal:", $userMsg);   // Task wrapper text
    }

    public function testTaskContextDoesNotLeakToSubsequentCalls()
    {
        $mockProvider = new MockProvider([
            ['content' => 'First task done'],
            ['content' => 'Unrelated chat response'],
        ]);
        $agent = new Agent();
        $this->injectProvider($agent, $mockProvider);

        $task = new Task($agent);
        $task->addContext("Project", "Secret Project");
        $task->execute("Do work");

        // A plain chat() after the task must not see the task's context anymore.
        $agent->chat("Something unrelated");

        $requests = $mockProvider->getCapturedRequests();
        $secondSystemMsg = $requests[1]['messages'][0]['content'];

        $this->assertStringNotContainsString("Secret Project", $secondSystemMsg);
        $this->assertEmpty($agent->getContext());
    }

    public function testTaskContextRestoredEvenOnFailure()
    {
        $agent = new Agent();
        // Provider throws instead of returning an array, so chat() blows up mid-task.
        $throwingProvider = new class implements \NanoAgent\Contracts\Provider {
            public function send(array $messages, array $tools = []): array
            {
                throw new \RuntimeException('boom');
            }
            public function stream(array $messages, callable $onToken, array $tools = []): array
            {
                throw new \RuntimeException('boom');
            }
        };
        $this->injectProvider($agent, $throwingProvider);

        $agent->addContext("Persistent", "Should survive");

        $task = new Task($agent);
        $task->addContext("Scoped", "Should not survive");

        try {
            $task->execute("Do work");
            $this->fail("Expected exception was not thrown.");
        } catch (\RuntimeException $e) {
            // expected
        }

        $context = $agent->getContext();
        $this->assertArrayHasKey("Persistent", $context);
        $this->assertArrayNotHasKey("Scoped", $context);
    }

    private function injectProvider(Agent $agent, $provider)
    {
        $reflector = new ReflectionClass($agent);
        $property  = $reflector->getProperty('provider');
        $property->setAccessible(true);
        $property->setValue($agent, $provider);
    }
}
