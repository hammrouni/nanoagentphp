<?php

declare(strict_types=1);

namespace NanoAgent;

/**
 * Represents a high-level objective to be executed by an Agent.
 * 
 * The Task class provides a structured way to bundle specific goals and localized 
 * context before handing off the execution to a registered Agent instance.
 */
class Task
{
    /** @var array<string, string> Storage for context items specific to the lifecycle of this task. */
    private array $context = [];

    /**
     * Task constructor.
     * 
     * @param Agent $agent The specific agent instance that will carry out the task.
     */
    public function __construct(
        private Agent $agent
    ) {}

    /**
     * Register a context item for this task and synchronize it with the assigned agent.
     *
     * @param string $key A unique identifier/label for the context piece.
     * @param string $value The contextual information or data.
     */
    public function addContext(string $key, string $value): void
    {
        $this->context[$key] = $value;
        $this->agent->addContext($key, $value);
    }

    /**
     * Initiate the task execution.
     * 
     * Constructs a structured prompt template containing the goal and all provided 
     * context, then routes it to the agent for processing.
     *
     * @param string $goal Clear and concise instructions for what the agent should achieve.
     * @return string The text-based response returned by the agent.
     */
    public function execute(string $goal = ''): string
    {
        // Format the structured prompt template.
        $prompt = "Please fulfill the following task based on the provided context:\n";
        
        if (!empty($goal)) {
            $prompt .= "Goal: $goal\n";
        }

        return $this->agent->chat($prompt);
    }

}
