<?php

declare(strict_types=1);

namespace NanoAgent\Utils;

use NanoAgent\Exceptions\ProviderException;
use RuntimeException;

/**
 * Simple HTTP client utility leveraging cURL.
 * 
 * Specifically designed for interacting with AI Provider APIs, supporting 
 * both standard JSON POST requests and streaming Server-Sent Events (SSE).
 */
class HttpClient
{
    /**
     * @param int $connectTimeout Max seconds to wait while establishing the connection.
     * @param int $timeout Max seconds for a standard (non-streaming) request.
     * @param int $streamTimeout Max seconds for a streaming request (0 = unlimited).
     */
    public function __construct(
        private int $connectTimeout = 10,
        private int $timeout = 60,
        private int $streamTimeout = 0
    ) {}

    /**
     * Perform a standard POST request with a JSON payload.
     *
     * @param string $url The target endpoint URL.
     * @param array $headers List of HTTP headers to include in the request.
     * @param array $body Associative array to be encoded as JSON.
     * @return array The decoded JSON response payload.
     * @throws ProviderException If a network error occurs, the HTTP status indicates
     *                           failure (status code available via getStatusCode()),
     *                           or the response body is invalid JSON.
     */
    public function post(string $url, array $headers, array $body): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new ProviderException('Network error: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        $isValidJson = json_last_error() === JSON_ERROR_NONE;

        if ($statusCode >= 400) {
            $detail = $isValidJson ? json_encode($data) : (string) $response;
            throw new ProviderException("HTTP $statusCode error from API: $detail", $statusCode);
        }

        if (!$isValidJson) {
            throw new ProviderException('Invalid JSON response: ' . $response, $statusCode);
        }

        return $data;
    }

    /**
     * Perform a streaming POST request.
     * 
     * Uses a write callback to process incoming data chunks in real-time.
     *
     * @param string $url The target endpoint URL.
     * @param array $headers List of HTTP headers to include in the request.
     * @param array $body Associative array to be encoded as JSON.
     * @param callable $onChunk Callback function: function(string $chunk).
     * @return void
     * @throws ProviderException If a network error occurs during the stream, or the
     *                           HTTP status indicates failure (status code available
     *                           via getStatusCode()).
     */
    public function stream(string $url, array $headers, array $body, callable $onChunk): void
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->streamTimeout);

        // We handle the output manually via the WRITEFUNCTION callback.
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);

        // Buffer raw bytes so a non-200 response body can be surfaced in the exception
        // (error responses aren't SSE and would otherwise be dropped silently).
        $rawBody = '';

        // Use a callback to handle each chunk of data as it arrives from the server.
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use ($onChunk, &$rawBody) {
            $rawBody .= $chunk;
            $onChunk($chunk);
            return strlen($chunk);
        });

        curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new ProviderException('Network error during stream: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($statusCode >= 400) {
            throw new ProviderException("HTTP $statusCode error from API: $rawBody", $statusCode);
        }
    }
}
