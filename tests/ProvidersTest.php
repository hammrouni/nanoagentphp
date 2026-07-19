<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use NanoAgent\Providers\OpenAI;
use NanoAgent\Providers\Groq;
use NanoAgent\Providers\DeepSeek;
use NanoAgent\Providers\OpenRouter;
use NanoAgent\Providers\Anthropic;
use NanoAgent\Utils\HttpClient;
use ReflectionClass;

/**
 * A stand-in HttpClient that records the outgoing request body instead of
 * making a real network call, so provider request-building logic can be
 * verified in isolation.
 */
class RequestCapturingHttpClient extends HttpClient
{
    /** @var array|null The body passed to the last post()/stream() call. */
    public ?array $lastBody = null;

    /** @var string|null The URL passed to the last post()/stream() call. */
    public ?string $lastUrl = null;

    /** @var array|null The headers passed to the last post()/stream() call. */
    public ?array $lastHeaders = null;

    /** @var array The canned response returned from post(). */
    private array $response;

    public function __construct(array $response = [])
    {
        $this->response = $response;
    }

    public function post(string $url, array $headers, array $body): array
    {
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;
        $this->lastBody = $body;
        return $this->response;
    }

    public function stream(string $url, array $headers, array $body, callable $onChunk): void
    {
        $this->lastUrl = $url;
        $this->lastHeaders = $headers;
        $this->lastBody = $body;
    }
}

/**
 * A stand-in HttpClient that feeds pre-canned SSE frames to the stream()
 * callback, simulating real network chunks (each array entry is delivered as
 * one call to $onChunk, the same way HttpClient::stream()'s WRITEFUNCTION
 * delivers whatever cURL handed it — which may split or combine SSE events).
 */
class SseEmittingHttpClient extends HttpClient
{
    /** @param string[] $chunks Raw bytes to feed to $onChunk, in order. */
    public function __construct(private array $chunks)
    {
    }

    public function post(string $url, array $headers, array $body): array
    {
        throw new \RuntimeException('post() is not supported by SseEmittingHttpClient');
    }

    public function stream(string $url, array $headers, array $body, callable $onChunk): void
    {
        foreach ($this->chunks as $chunk) {
            $onChunk($chunk);
        }
    }
}

class ProvidersTest extends TestCase
{
    private function injectClient(object $provider, HttpClient $client): void
    {
        $reflector = new ReflectionClass($provider);
        $property = $reflector->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($provider, $client);
    }

    public function testOpenAiOptionsAreMergedIntoRequestBody()
    {
        $provider = new OpenAI('key', 'gpt-4o', options: [
            'temperature' => 0.5,
            'top_p' => 0.9,
            // An attempt to override a core field via options must be ignored.
            'model' => 'should-not-win',
        ]);

        $fakeClient = new RequestCapturingHttpClient([
            'choices' => [['message' => ['content' => 'ok']]]
        ]);
        $this->injectClient($provider, $fakeClient);

        $provider->send([['role' => 'user', 'content' => 'Hi']]);

        $this->assertNotNull($fakeClient->lastBody);
        $this->assertSame(0.5, $fakeClient->lastBody['temperature']);
        $this->assertSame(0.9, $fakeClient->lastBody['top_p']);
        // Core fields set by the provider always win over user-supplied options.
        $this->assertSame('gpt-4o', $fakeClient->lastBody['model']);
    }

    public function testAnthropicMaxTokensDefaultsWhenNotOverridden()
    {
        $provider = new Anthropic('key', 'claude-3-5-sonnet-20240620');

        $fakeClient = new RequestCapturingHttpClient([
            'content' => [['type' => 'text', 'text' => 'ok']]
        ]);
        $this->injectClient($provider, $fakeClient);

        $provider->send([['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame(4096, $fakeClient->lastBody['max_tokens']);
    }

    public function testAnthropicMaxTokensCanBeOverriddenViaOptions()
    {
        $provider = new Anthropic('key', 'claude-3-5-sonnet-20240620', options: [
            'max_tokens' => 8192,
            'temperature' => 0.2,
        ]);

        $fakeClient = new RequestCapturingHttpClient([
            'content' => [['type' => 'text', 'text' => 'ok']]
        ]);
        $this->injectClient($provider, $fakeClient);

        $provider->send([['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame(8192, $fakeClient->lastBody['max_tokens']);
        $this->assertSame(0.2, $fakeClient->lastBody['temperature']);
    }

    public function testAnthropicUsesCustomBaseUrl()
    {
        $provider = new Anthropic('key', 'claude-3-5-sonnet-20240620', baseUrl: 'https://proxy.example.com');

        $fakeClient = new RequestCapturingHttpClient([
            'content' => [['type' => 'text', 'text' => 'ok']]
        ]);
        $this->injectClient($provider, $fakeClient);

        $provider->send([['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame('https://proxy.example.com/v1/messages', $fakeClient->lastUrl);
    }

    public function testOpenAiUsesCustomBaseUrl()
    {
        $provider = new OpenAI('key', 'gpt-4o', baseUrl: 'https://proxy.example.com/v1');

        $fakeClient = new RequestCapturingHttpClient([
            'choices' => [['message' => ['content' => 'ok']]]
        ]);
        $this->injectClient($provider, $fakeClient);

        $provider->send([['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame('https://proxy.example.com/v1/chat/completions', $fakeClient->lastUrl);
    }

    public function testGroqUsesDefaultEndpointAndModel()
    {
        $provider = new Groq('key');

        $fakeClient = new RequestCapturingHttpClient([
            'choices' => [['message' => ['content' => 'ok']]]
        ]);
        $this->injectClient($provider, $fakeClient);

        $provider->send([['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame('https://api.groq.com/openai/v1/chat/completions', $fakeClient->lastUrl);
        $this->assertSame('llama-3.3-70b-versatile', $fakeClient->lastBody['model']);
    }

    public function testDeepSeekUsesDefaultEndpointAndModel()
    {
        $provider = new DeepSeek('key');

        $fakeClient = new RequestCapturingHttpClient([
            'choices' => [['message' => ['content' => 'ok']]]
        ]);
        $this->injectClient($provider, $fakeClient);

        $provider->send([['role' => 'user', 'content' => 'Hi']]);

        $this->assertSame('https://api.deepseek.com/chat/completions', $fakeClient->lastUrl);
        $this->assertSame('deepseek-chat', $fakeClient->lastBody['model']);
    }

    public function testOpenRouterAddsRankingHeadersWhenConfigured()
    {
        $provider = new OpenRouter(
            'key',
            'openai/gpt-3.5-turbo',
            siteUrl: 'https://example.com',
            appName: 'MyApp'
        );

        $fakeClient = new RequestCapturingHttpClient([
            'choices' => [['message' => ['content' => 'ok']]]
        ]);
        $this->injectClient($provider, $fakeClient);

        $provider->send([['role' => 'user', 'content' => 'Hi']]);

        $this->assertContains('HTTP-Referer: https://example.com', $fakeClient->lastHeaders);
        $this->assertContains('X-Title: MyApp', $fakeClient->lastHeaders);
    }

    public function testOpenRouterOmitsRankingHeadersByDefault()
    {
        $provider = new OpenRouter('key');

        $fakeClient = new RequestCapturingHttpClient([
            'choices' => [['message' => ['content' => 'ok']]]
        ]);
        $this->injectClient($provider, $fakeClient);

        $provider->send([['role' => 'user', 'content' => 'Hi']]);

        foreach ($fakeClient->lastHeaders as $header) {
            $this->assertStringNotContainsString('HTTP-Referer', $header);
            $this->assertStringNotContainsString('X-Title', $header);
        }
    }

    public function testStreamAccumulatesTextContent()
    {
        $provider = new OpenAI('key');

        $chunks = [
            "data: " . json_encode(['choices' => [['delta' => ['content' => 'Hel']]]]) . "\n\n",
            "data: " . json_encode(['choices' => [['delta' => ['content' => 'lo!']]]]) . "\n\n",
            "data: [DONE]\n\n",
        ];
        $this->injectClient($provider, new SseEmittingHttpClient($chunks));

        $received = '';
        $result = $provider->stream(
            [['role' => 'user', 'content' => 'Hi']],
            function ($token) use (&$received) { $received .= $token; }
        );

        $this->assertSame('Hello!', $received);
        $this->assertSame('Hello!', $result['content']);
        $this->assertNull($result['tool_calls']);
    }

    public function testStreamAccumulatesToolCallDeltasAcrossChunks()
    {
        $provider = new OpenAI('key');

        // Simulates OpenAI's real streaming shape: id/name arrive once, then
        // 'arguments' streams incrementally as raw JSON string fragments.
        $chunks = [
            "data: " . json_encode(['choices' => [['delta' => ['tool_calls' => [
                ['index' => 0, 'id' => 'call_abc123', 'type' => 'function', 'function' => ['name' => 'get_weather', 'arguments' => '']]
            ]]]]]) . "\n\n",
            "data: " . json_encode(['choices' => [['delta' => ['tool_calls' => [
                ['index' => 0, 'function' => ['arguments' => '{"loc']]
            ]]]]]) . "\n\n",
            "data: " . json_encode(['choices' => [['delta' => ['tool_calls' => [
                ['index' => 0, 'function' => ['arguments' => 'ation":"NYC"}']]
            ]]]]]) . "\n\n",
            "data: [DONE]\n\n",
        ];
        $this->injectClient($provider, new SseEmittingHttpClient($chunks));

        $tokensReceived = [];
        $result = $provider->stream(
            [['role' => 'user', 'content' => 'Weather in NYC?']],
            function ($token) use (&$tokensReceived) { $tokensReceived[] = $token; }
        );

        // Tool-call argument fragments must never be forwarded to onToken.
        $this->assertSame([], $tokensReceived);
        $this->assertSame('', $result['content']);

        $this->assertNotNull($result['tool_calls']);
        $this->assertCount(1, $result['tool_calls']);
        $this->assertSame('call_abc123', $result['tool_calls'][0]['id']);
        $this->assertSame('function', $result['tool_calls'][0]['type']);
        $this->assertSame('get_weather', $result['tool_calls'][0]['function']['name']);
        $this->assertSame('{"location":"NYC"}', $result['tool_calls'][0]['function']['arguments']);
    }

    public function testStreamHandlesMultipleToolCallsByIndex()
    {
        $provider = new OpenAI('key');

        $chunks = [
            "data: " . json_encode(['choices' => [['delta' => ['tool_calls' => [
                ['index' => 0, 'id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'tool_a', 'arguments' => '{}']],
            ]]]]]) . "\n\n",
            "data: " . json_encode(['choices' => [['delta' => ['tool_calls' => [
                ['index' => 1, 'id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'tool_b', 'arguments' => '{}']],
            ]]]]]) . "\n\n",
            "data: [DONE]\n\n",
        ];
        $this->injectClient($provider, new SseEmittingHttpClient($chunks));

        $result = $provider->stream([['role' => 'user', 'content' => 'Do two things']], function () {});

        $this->assertCount(2, $result['tool_calls']);
        $this->assertSame('call_1', $result['tool_calls'][0]['id']);
        $this->assertSame('tool_a', $result['tool_calls'][0]['function']['name']);
        $this->assertSame('call_2', $result['tool_calls'][1]['id']);
        $this->assertSame('tool_b', $result['tool_calls'][1]['function']['name']);
    }

    public function testApiErrorIsPrefixedWithProviderLabel()
    {
        $provider = new Groq('key');

        $fakeClient = new RequestCapturingHttpClient([
            'error' => ['message' => 'boom']
        ]);
        $this->injectClient($provider, $fakeClient);

        $this->expectException(\NanoAgent\Exceptions\ProviderException::class);
        $this->expectExceptionMessageMatches('/^Groq API Error:/');

        $provider->send([['role' => 'user', 'content' => 'Hi']]);
    }
}
