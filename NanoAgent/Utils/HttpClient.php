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
     * Perform a standard POST request with a JSON payload.
     *
     * @param string $url The target endpoint URL.
     * @param array $headers List of HTTP headers to include in the request.
     * @param array $body Associative array to be encoded as JSON.
     * @return array The decoded JSON response payload.
     * @throws ProviderException If a network error occurs or the response is invalid JSON.
     */
    public function post(string $url, array $headers, array $body): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new ProviderException('Network error: ' . $error);
        }

        curl_close($ch);

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ProviderException('Invalid JSON response: ' . $response);
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
     * @throws ProviderException If a network error occurs during the stream.
     */
    public function stream(string $url, array $headers, array $body, callable $onChunk): void
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        // We handle the output manually via the WRITEFUNCTION callback.
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        
        // Use a callback to handle each chunk of data as it arrives from the server.
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $chunk) use ($onChunk) {
            $onChunk($chunk);
            return strlen($chunk);
        });

        curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new ProviderException('Network error during stream: ' . $error);
        }

        curl_close($ch);
    }
}
