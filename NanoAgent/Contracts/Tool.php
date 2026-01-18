<?php

declare(strict_types=1);

namespace NanoAgent\Contracts;

interface Tool
{
    /**
     * Get the tool definition for the LLM (JSON Schema).
     */
    public function toArray(): array;

    /**
     * Execute the tool logic.
     */
    public function execute(array $arguments): mixed;
    
    /**
     * Get the tool name.
     */
    public function getName(): string;
}
