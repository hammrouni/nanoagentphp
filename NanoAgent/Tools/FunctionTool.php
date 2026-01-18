<?php

declare(strict_types=1);

namespace NanoAgent\Tools;

use NanoAgent\Contracts\Tool;

/**
 * A concrete implementation of a Tool that wraps a PHP callable.
 */
class FunctionTool implements Tool
{
    /**
     * @param string $name The name of the function (must be unique).
     * @param string $description A clear description of what the function does.
     * @param array $parameters JSON Schema defining the expected parameters.
     * @param callable $callable The PHP logic to execute.
     */
    public function __construct(
        private string $name,
        private string $description,
        private array $parameters,
        private $callable
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Returns the tool definition in OpenAI-compatible JSON Schema format.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name,
                'description' => $this->description,
                'parameters' => $this->parameters,
            ]
        ];
    }

    /**
     * Executes the wrapped callable with the provided arguments.
     *
     * @param array $arguments
     * @return mixed
     */
    public function execute(array $arguments): mixed
    {
        return call_user_func($this->callable, $arguments);
    }
}
