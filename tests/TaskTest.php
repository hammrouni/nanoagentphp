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

    private function injectProvider(Agent $agent, $provider)
    {
        $reflector = new ReflectionClass($agent);
        $property  = $reflector->getProperty('provider');
        $property->setAccessible(true);
        $property->setValue($agent, $provider);
    }
}
