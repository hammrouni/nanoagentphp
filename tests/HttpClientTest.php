<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use NanoAgent\Utils\HttpClient;
use NanoAgent\Exceptions\ProviderException;
use ReflectionClass;

class HttpClientTest extends TestCase
{
    private function encodeJsonBody(HttpClient $client, array $body): string
    {
        $reflector = new ReflectionClass($client);
        $method = $reflector->getMethod('encodeJsonBody');
        $method->setAccessible(true);
        return $method->invoke($client, $body);
    }

    public function testEncodeJsonBodyKeepsUnicodeUnescaped()
    {
        $client = new HttpClient();

        $json = $this->encodeJsonBody($client, ['text' => 'café 北京']);

        // The literal UTF-8 text is present, proving it was not turned into
        // \uXXXX escape sequences the way plain json_encode() would.
        $this->assertStringContainsString('café 北京', $json);
    }

    public function testEncodeJsonBodyKeepsSlashesUnescaped()
    {
        $client = new HttpClient();

        $json = $this->encodeJsonBody($client, ['url' => 'https://example.com/path']);

        $this->assertStringContainsString('https://example.com/path', $json);
        $this->assertStringNotContainsString('\/', $json);
    }

    public function testEncodeJsonBodyThrowsProviderExceptionOnUnencodableBody()
    {
        $client = new HttpClient();

        // An invalid UTF-8 byte sequence makes json_encode() fail.
        $body = ['text' => "\xB1\x31"];

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/Failed to encode request body as JSON/');

        $this->encodeJsonBody($client, $body);
    }
}
