<?php

declare(strict_types=1);

namespace NanoAgent\Utils;

/**
 * Utility class for managing and injecting context into system instructions.
 * 
 * Provides static methods to format and append contextual information (e.g., user state, 
 * historical data, environment variables) to a base system prompt template.
 */
class ContextBuilder
{
    /**
     * Integrate context data into a system prompt.
     *
     * @param string $systemPrompt The base instructions defining the agent's behavior.
     * @param array $context Associative array of context items (key as label, value as data).
     * @return string The enriched system prompt ready for the LLM.
     */
    public static function build(string $systemPrompt, array $context): string
    {
        $finalPrompt = $systemPrompt;
        
        // If context data is available, format it into a clear, delimited block.
        if (!empty($context)) {
            $finalPrompt .= "\n\n---\nContext Data:\n";
            foreach ($context as $label => $data) {
                // Each context item is wrapped with its label for better AI readability.
                $finalPrompt .= sprintf("\n[%s]:\n%s\n", $label, trim($data));
            }
            $finalPrompt .= "\n---\n";
        }
        
        return $finalPrompt;
    }
}

