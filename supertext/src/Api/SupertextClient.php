<?php

/**
 * @package     Supertext Translation for PrestaShop
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\PrestaShop\Api;

use Supertext\PrestaShop\Sdk\Client;
use Supertext\PrestaShop\Sdk\Http\HttpTransport;
use Supertext\PrestaShop\Sdk\Supertext;
use Supertext\PrestaShop\Sdk\SupertextException as SdkException;

/**
 * Supertext AI file translation API v1 (https://api.supertext.com/v1/), through the
 * Supertext PHP SDK bundled in lib/supertext-sdk (namespace Supertext\PrestaShop\Sdk).
 *
 * The SDK does the protocol (upload one HTML document, poll its status, download the
 * translation, delete the file), the auth header, HTTP 429 retries and language codes.
 * This class keeps the module's interface and turns SDK errors into this module's
 * SupertextException, whose English template the back office translates
 * (domain Modules.Supertext.Admin).
 *
 * Has no PrestaShop dependencies.
 */
final class SupertextClient
{
    public const LIVE = Supertext::LIVE_BASE_URL;
    public const STAGING = 'https://api.staging.supertext.com/v1/';
    public const TESTING = 'https://api.testing.supertext.com/v1/';

    private readonly Client $sdk;
    private readonly string $apiKey;

    /**
     * @param HttpTransport|callable(string $method, string $url, array<string, string> $headers, ?string $body): array{status: int, body: string, headers?: array<string, string>} $transport
     * @param callable(float $seconds): void|null $sleep tests: the SDK's clock then counts the slept time
     */
    public function __construct(
        string $apiKey,
        string $baseUrl,
        HttpTransport|callable $transport,
        private readonly int $pollTimeout = 180,
        float $pollInterval = 2.0,
        ?callable $sleep = null,
    ) {
        $this->apiKey = self::normalizeKey($apiKey);

        $elapsed = 0.0;
        $sdkSleep = $sleep === null ? null : static function (float $seconds) use ($sleep, &$elapsed): void {
            $elapsed += $seconds;
            $sleep($seconds);
        };
        $clock = $sleep === null ? null : static function () use (&$elapsed): float {
            return $elapsed;
        };

        $this->sdk = new Client(
            $this->apiKey,
            baseUrl: $baseUrl !== '' ? $baseUrl : self::LIVE,
            pollInterval: $pollInterval,
            timeout: (float) $pollTimeout,
            requestTimeout: 60.0,
            userAgent: 'PrestaShop-Supertext-Translation',
            transport: $transport instanceof HttpTransport ? $transport : new CallableTransport($transport),
            sleep: $sdkSleep,
            clock: $clock,
        );
    }

    /** Supertext shows the key as "Supertext-Auth-Key <key>"; accept it with or without that prefix. */
    public static function normalizeKey(string $key): string
    {
        return (string) preg_replace('/^Supertext-Auth-Key\s+/i', '', trim($key));
    }

    public static function baseUrlFor(string $environment, string $customEndpoint = ''): string
    {
        if (trim($customEndpoint) !== '') {
            return trim($customEndpoint);
        }

        return match ($environment) {
            'staging' => self::STAGING,
            'testing' => self::TESTING,
            default   => self::LIVE,
        };
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Translates a complete HTML document and returns the translated HTML.
     *
     * @param string $targetLanguage any form; sent as a regional code ("de" → "de-DE")
     * @param string $sourceLanguage any form ("en-GB"); sent as the bare language. Empty: auto-detect.
     * @param string $politeness     "default", "more" (formal) or "less" (informal)
     */
    public function translateDocument(string $html, string $targetLanguage, string $sourceLanguage = '', string $politeness = 'default'): string
    {
        if (\function_exists('set_time_limit')) {
            @set_time_limit($this->pollTimeout + 60);
        }

        return $this->call(fn (): string => $this->sdk->translateHtml($html, $targetLanguage, $sourceLanguage !== '' ? $sourceLanguage : null, $politeness));
    }

    /** Cost-free check that the key is accepted. */
    public function validateApiKey(): void
    {
        $this->call(fn () => $this->sdk->validateApiKey());
    }

    /** Seconds to wait before retry $attempt (0-based): Retry-After if sent, else 1, 2, 4, 8 plus jitter. */
    public static function retryDelay(int $attempt, string $retryAfter = ''): float
    {
        return Supertext::retryDelayMs($attempt, $retryAfter !== '' ? $retryAfter : null) / 1000;
    }

    /**
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    private function call(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (SdkException $e) {
            throw self::fromSdk($e);
        }
    }

    /**
     * The module's message for an SDK error code. Keep the arms of the "$message = match"
     * block literal: tests/Support/ModuleStrings.php reads them for the translation files.
     */
    private static function fromSdk(SdkException $e): SupertextException
    {
        $code = $e->errorCode;
        $message = match (true) {
            $code === SdkException::MISSING_API_KEY        => 'No Supertext API key is configured. Generate one at https://www.supertext.com/en/integrations/api (requires the Admin role).',
            $code === SdkException::AUTHENTICATION_FAILURE => 'Authentication failed. Please check the Supertext API key. A new key can be generated at https://www.supertext.com/en/integrations/api (requires the Admin role).',
            $code === SdkException::INVALID_LANGUAGE_PAIR  => 'Supertext does not support this language pair (%source% → %target%). Check the Supertext language code in the module settings.',
            $code === SdkException::NOT_FOUND              => 'The requested Supertext resource was not found.',
            $code === SdkException::PAYLOAD_TOO_LARGE      => 'The content is too large for Supertext to translate in one go.',
            $code === SdkException::TOO_MANY_REQUESTS      => 'Too many requests to Supertext. Please try again shortly.',
            $code === SdkException::SERVICE_UNAVAILABLE    => 'The Supertext service is currently unavailable.',
            $code === SdkException::TRANSPORT_ERROR        => 'Could not reach Supertext: %error%',
            $code === SdkException::NO_FILE_ID             => 'Supertext did not return a file id.',
            $code === SdkException::TRANSLATION_ERROR      => 'Supertext could not translate the document.',
            $code === SdkException::QUOTA_EXCEEDED         => 'Your Supertext translation limit is exceeded. Please upgrade your subscription.',
            $code === SdkException::FILE_DELETED           => 'The document was deleted at Supertext before it could be downloaded.',
            $code === SdkException::TIMEOUT                => 'Timed out waiting for the Supertext translation.',
            $code === SdkException::INCOMPLETE_RESPONSE    => 'The translated document was empty.',
            default                                        => 'Supertext answered with HTTP %status%.',
        };

        $detail = (string) $e->detail;
        $parameters = match ($code) {
            SdkException::INVALID_LANGUAGE_PAIR => ['%source%' => (string) $e->sourceLang ?: '?', '%target%' => (string) $e->targetLang ?: '?'],
            SdkException::TRANSPORT_ERROR => ['%error%' => $detail],
            default => ['%status%' => (int) $e->status],
        };
        if ($code === SdkException::TRANSPORT_ERROR) {
            $detail = ''; // already in the message
        }

        return new SupertextException($message, $parameters, (int) $e->status, $e, $detail);
    }
}
