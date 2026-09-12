<?php

namespace Tests;

use NanoAgent\Agent;
use NanoAgent\Contracts\Memory;
use NanoAgent\Contracts\Tool;
use NanoAgent\Exceptions\AgentException;
use NanoAgent\Exceptions\MemoryException;
use NanoAgent\Memory\ArrayMemory;
use NanoAgent\Memory\FileMemory;
use NanoAgent\Memory\PdoMemory;
use NanoAgent\Providers\MockProvider;
use PHPUnit\Framework\TestCase;

class MemoryTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/nanoagent_memory_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->tmpDir);
        }
    }

    /**
     * Test: a second Agent (i.e. the next request) resumes the conversation
     * stored by the first, and the provider receives the prior turns.
     */
    public function testHistoryPersistsAcrossAgentInstances()
    {
        $memory = new ArrayMemory();

        $first = new Agent(new MockProvider([['content' => 'Nice to meet you, Ada.']]));
        $first->setMemory($memory, 'user-1');
        $first->chat('My name is Ada.');

        $provider = new MockProvider([['content' => 'Your name is Ada.']]);
        $second = new Agent($provider);
        $second->setMemory($memory, 'user-1');

        $this->assertCount(2, $second->getHistory());
        $this->assertSame('Your name is Ada.', $second->chat('What is my name?'));

        $messages = $provider->getCapturedRequests()[0]['messages'];
        $this->assertSame('My name is Ada.', $messages[1]['content']);
        $this->assertSame('Nice to meet you, Ada.', $messages[2]['content']);
        $this->assertSame('What is my name?', $messages[3]['content']);

        $this->assertCount(4, $memory->load('user-1'));
    }

    public function testSessionsAreIsolated()
    {
        $memory = new ArrayMemory();

        $agent = new Agent(new MockProvider([['content' => 'A']]));
        $agent->setMemory($memory, 'alice');
        $agent->chat('Hi from Alice');

        $other = new Agent(new MockProvider([]));
        $other->setMemory($memory, 'bob');

        $this->assertSame([], $other->getHistory());
    }

    public function testStreamPersistsHistory()
    {
        $memory = new ArrayMemory();

        $agent = new Agent(new MockProvider([['content' => 'Streamed']]));
        $agent->setMemory($memory, 's');
        $agent->stream('Hi', function () {});

        $stored = $memory->load('s');
        $this->assertCount(2, $stored);
        $this->assertSame('Streamed', $stored[1]['content']);
    }

    /**
     * Test: the tool-call round trip (assistant tool_calls + tool result) is
     * persisted intact, since providers reject orphaned tool messages.
     */
    public function testToolCallTurnIsPersistedIntact()
    {
        $tool = new class implements Tool {
            public function getName(): string { return 'calculator'; }
            public function toArray(): array { return ['name' => 'calculator']; }
            public function execute(array $args): mixed { return (string)($args['a'] + $args['b']); }
        };

        $memory = new ArrayMemory();
        $agent = new Agent(new MockProvider([
            [
                'content' => null,
                'tool_calls' => [
                    ['id' => 'call_1', 'function' => ['name' => 'calculator', 'arguments' => '{"a":5,"b":3}']]
                ]
            ],
            ['content' => 'The result is 8']
        ]), '', [$tool]);
        $agent->setMemory($memory, 's');
        $agent->chat('Add 5 and 3');

        $stored = $memory->load('s');
        $this->assertCount(4, $stored);
        $this->assertSame('call_1', $stored[1]['tool_calls'][0]['id']);
        $this->assertSame('tool', $stored[2]['role']);
        $this->assertSame('call_1', $stored[2]['tool_call_id']);
    }

    /**
     * Test: a turn that throws mid-loop is not saved, so storage keeps the last
     * completed turn rather than a half-finished one.
     */
    public function testFailedTurnIsNotPersisted()
    {
        $tool = new class implements Tool {
            public function getName(): string { return 'noop'; }
            public function toArray(): array { return ['name' => 'noop']; }
            public function execute(array $args): mixed { return 'ok'; }
        };
        $loop = ['content' => null, 'tool_calls' => [['id' => 'x', 'function' => ['name' => 'noop', 'arguments' => '{}']]]];

        $memory = new ArrayMemory();
        $memory->save('s', [['role' => 'user', 'content' => 'earlier']]);

        $agent = new Agent(new MockProvider(array_fill(0, 5, $loop)), '', [$tool]);
        $agent->setMaxIterations(2);
        $agent->setMemory($memory, 's');

        try {
            $agent->chat('Loop forever');
            $this->fail('Expected AgentException');
        } catch (AgentException $e) {
        }

        $this->assertSame([['role' => 'user', 'content' => 'earlier']], $memory->load('s'));
    }

    public function testSetHistoryAndClearHistoryUpdateStorage()
    {
        $memory = new ArrayMemory();
        $agent = new Agent(new MockProvider([]));
        $agent->setMemory($memory, 's');

        $agent->setHistory([['role' => 'user', 'content' => 'restored']]);
        $this->assertSame([['role' => 'user', 'content' => 'restored']], $memory->load('s'));

        $agent->clearHistory();
        $this->assertSame([], $agent->getHistory());
        $this->assertSame([], $memory->load('s'));
    }

    public function testAgentWithoutMemoryBehavesAsBefore()
    {
        $agent = new Agent(new MockProvider([['content' => 'ok']]));
        $agent->chat('Hi');
        $agent->clearHistory();

        $this->assertNull($agent->getMemory());
        $this->assertNull($agent->getSessionId());
        $this->assertSame([], $agent->getHistory());
    }

    /**
     * @dataProvider driverProvider
     */
    public function testDriverContract(callable $factory)
    {
        /** @var Memory $memory */
        $memory = $factory($this);
        $history = [
            ['role' => 'user', 'content' => 'Héllo / 你好'],
            ['role' => 'assistant', 'content' => '', 'tool_calls' => [['id' => 'c1', 'function' => ['name' => 't', 'arguments' => '{}']]]],
        ];
        // Id with characters that are invalid or unsafe in file names.
        $sessionId = 'user@example.com/../{chat}:1';

        $this->assertSame([], $memory->load($sessionId));

        $memory->save($sessionId, $history);
        $this->assertSame($history, $memory->load($sessionId));

        $memory->save($sessionId, [['role' => 'user', 'content' => 'overwritten']]);
        $this->assertSame([['role' => 'user', 'content' => 'overwritten']], $memory->load($sessionId));
        $this->assertSame([], $memory->load('another-session'));

        $memory->clear($sessionId);
        $this->assertSame([], $memory->load($sessionId));

        // Clearing an unknown session is a no-op.
        $memory->clear('never-saved');
        $this->addToAssertionCount(1);
    }

    public function driverProvider(): array
    {
        return [
            'array' => [fn() => new ArrayMemory()],
            'file' => [fn(self $t) => new FileMemory($t->tmpDir)],
            'sqlite' => [function () {
                if (!extension_loaded('pdo_sqlite')) {
                    $this->markTestSkipped('pdo_sqlite not available');
                }
                return PdoMemory::sqlite(':memory:');
            }],
        ];
    }

    public function testFileMemoryStaysInsideDirectory()
    {
        $memory = new FileMemory($this->tmpDir);
        $memory->save('../../escape', [['role' => 'user', 'content' => 'x']]);

        $files = glob($this->tmpDir . '/*');
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}\.json$/', basename($files[0]));
    }

    public function testCorruptedStoredHistoryThrows()
    {
        $memory = new FileMemory($this->tmpDir);
        $memory->save('s', []);
        file_put_contents(glob($this->tmpDir . '/*.json')[0], '{not json');

        $this->expectException(MemoryException::class);
        $memory->load('s');
    }

    public function testInvalidUtf8InToolOutputDoesNotBreakSaving()
    {
        $memory = new FileMemory($this->tmpDir);
        $memory->save('s', [['role' => 'tool', 'content' => "bad \xB1 byte"]]);

        $this->assertStringStartsWith('bad ', $memory->load('s')[0]['content']);
    }

    public function testPdoMemoryRejectsUnsafeTableName()
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite not available');
        }

        $this->expectException(\InvalidArgumentException::class);
        new PdoMemory(new \PDO('sqlite::memory:'), 'memory; DROP TABLE users');
    }

    public function testPdoMemoryReusesExistingTableAcrossConnections()
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite not available');
        }

        mkdir($this->tmpDir);
        $path = $this->tmpDir . '/memory.sqlite';

        PdoMemory::sqlite($path)->save('s', [['role' => 'user', 'content' => 'persisted']]);

        $this->assertSame([['role' => 'user', 'content' => 'persisted']], PdoMemory::sqlite($path)->load('s'));
    }
}
