<?php

namespace NanoAgent\Events;

/**
 * Standard event logger for capturing Agent activity.
 * capable of formatting logs for display or debugging.
 */
class ActivityLogger
{
    private array $logs = [];

    public function __invoke(string $event, mixed $data): void
    {
        $this->logs[] = [
            'event' => $event,
            'data' => $data,
            'timestamp' => microtime(true)
        ];
    }

    /**
     * Get raw structured logs.
     */
    public function getLogs(): array
    {
        return $this->logs;
    }

    /**
     * Get simple string messages for display.
     * 
     * @return string[]
     */
    public function getMessages(): array
    {
        $messages = [];
        foreach ($this->logs as $log) {
            $evt = $log['event'];
            $data = $log['data'];

            if ($evt === 'tool.execute') {
                $args = json_encode($data['args']);
                $messages[] = "⚙️ Executing: {$data['name']} with $args";
            } elseif ($evt === 'tool.result') {
                $output = is_string($data['output']) ? $data['output'] : json_encode($data['output']);
                $messages[] = "✅ Result from {$data['name']}: $output";
            } elseif ($evt === 'request.start_stream') {
                $messages[] = "📡 Streaming response started...";
            }
        }
        return $messages;
    }
}
