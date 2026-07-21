<?php

declare(strict_types=1);

namespace NanoAgent;

/**
 * Represents a high-level objective to be executed by an Agent.
 *
 * The Task class provides a structured way to bundle specific goals and localized
 * context before handing off the execution to a registered Agent instance.
 *
 * Task-added context is scoped to a single execute() call: it is merged into the
 * agent's context only for the duration of that call and the agent's prior context
 * is restored afterward (even if execute() throws). This means the same Agent can
 * be reused across multiple, unrelated Tasks without earlier tasks' context leaking
 * into later ones. Context added directly on the Agent itself (via Agent::addContext())
 * is untouched by this and persists as normal.
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
     * Register a context item for this task.
     *
     * Not synchronized to the agent immediately — it is applied only while
     * execute() is running, then removed. See the class docblock for why.
     *
     * @param string $key A unique identifier/label for the context piece.
     * @param string $value The contextual information or data.
     */
    public function addContext(string $key, string $value): void
    {
        $this->context[$key] = $value;
    }

    /**
     * Initiate the task execution.
     *
     * Constructs a structured prompt template containing the goal and all provided
     * context, then routes it to the agent for processing. The agent's context is
     * restored to its pre-task state once execution finishes, so this task's context
     * does not persist on the agent for subsequent calls.
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

        $previousContext = $this->agent->getContext();

        foreach ($this->context as $key => $value) {
            $this->agent->addContext($key, $value);
        }

        try {
            return $this->agent->chat($prompt);
        } finally {
            $this->agent->setContext($previousContext);
        }
    }

}
