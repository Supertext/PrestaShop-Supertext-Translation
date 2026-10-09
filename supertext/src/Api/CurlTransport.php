<?php

/**
 * @package     Supertext Translation for PrestaShop
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\PrestaShop\Api;

/**
 * HTTP transport for {@see SupertextClient}: cURL when available, PHP streams otherwise.
 * Honours the HTTPS_PROXY environment variable (cURL does by itself; streams are told).
 */
final class CurlTransport
{
    public function __construct(private readonly int $timeout = 60)
    {
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function __invoke(string $method, string $url, array $headers, ?string $body): array
    {
        return \function_exists('curl_init')
            ? $this->curl($method, $url, $headers, $body)
            : $this->stream($method, $url, $headers, $body);
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $responseHeaders = [];
        $handle          = curl_init($url);

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER     => array_map(static fn ($k, $v) => $k . ': ' . $v, array_keys($headers), $headers),
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);

                if (\count($parts) === 2) {
                    $responseHeaders[trim($parts[0])] = trim($parts[1]);
                }

                return \strlen($line);
            },
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($handle);

        if ($result === false) {
            $error = curl_error($handle);

            throw new \RuntimeException($error !== '' ? $error : 'cURL request failed');
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        return ['status' => $status, 'body' => (string) $result, 'headers' => $responseHeaders];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function stream(string $method, string $url, array $headers, ?string $body): array
    {
        $http = [
            'method'        => $method,
            'header'        => implode("\r\n", array_map(static fn ($k, $v) => $k . ': ' . $v, array_keys($headers), $headers)),
            'content'       => $body ?? '',
            'timeout'       => $this->timeout,
            'ignore_errors' => true,
        ];

        $proxy = (string) (getenv('HTTPS_PROXY') ?: getenv('https_proxy') ?: '');

        if ($proxy !== '' && str_starts_with($url, 'https://')) {
            $http['proxy']           = (string) preg_replace('#^https?://#', 'tcp://', $proxy);
            $http['request_fulluri'] = false;
        }

        $result = @file_get_contents($url, false, stream_context_create(['http' => $http]));

        if ($result === false) {
            throw new \RuntimeException(error_get_last()['message'] ?? 'HTTP request failed');
        }

        $status          = 0;
        $responseHeaders = [];

        // $http_response_header is deprecated as of PHP 8.5.
        $lines = \function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : $http_response_header;

        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status          = (int) $m[1];
                $responseHeaders = [];
            } elseif (str_contains($line, ':')) {
                [$k, $v]                   = explode(':', $line, 2);
                $responseHeaders[trim($k)] = trim($v);
            }
        }

        return ['status' => $status, 'body' => $result, 'headers' => $responseHeaders];
    }
}
