<?php

/**
 * @package     Supertext Translation for PrestaShop
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\PrestaShop;

use Supertext\PrestaShop\Api\CurlTransport;
use Supertext\PrestaShop\Api\LanguageCodes;
use Supertext\PrestaShop\Api\SupertextClient;

/**
 * The module's settings (PrestaShop configuration table), with the environment variables
 * SUPERTEXT_API_KEY and SUPERTEXT_API_ENDPOINT taking precedence.
 */
final class Settings
{
    public const API_KEY     = 'SUPERTEXT_API_KEY';
    public const ENVIRONMENT = 'SUPERTEXT_ENVIRONMENT';
    public const ENDPOINT    = 'SUPERTEXT_ENDPOINT';
    public const LANGUAGES   = 'SUPERTEXT_LANGUAGES';
    public const TIMEOUT     = 'SUPERTEXT_TIMEOUT';

    public const ALL = [self::API_KEY, self::ENVIRONMENT, self::ENDPOINT, self::LANGUAGES, self::TIMEOUT];

    public const SIGNUP_URL  = 'https://www.supertext.com/person/en/account/signin';
    public const API_KEY_URL = 'https://www.supertext.com/en/integrations/api';

    public const TONES = ['default', 'more', 'less'];

    public static function apiKey(): string
    {
        $env = (string) getenv('SUPERTEXT_API_KEY');

        return SupertextClient::normalizeKey($env !== '' ? $env : (string) \Configuration::getGlobalValue(self::API_KEY));
    }

    public static function apiKeyFromEnvironment(): bool
    {
        return trim((string) getenv('SUPERTEXT_API_KEY')) !== '';
    }

    public static function endpointFromEnvironment(): bool
    {
        return trim((string) getenv('SUPERTEXT_API_ENDPOINT')) !== '';
    }

    public static function environment(): string
    {
        $value = (string) \Configuration::getGlobalValue(self::ENVIRONMENT);

        return \in_array($value, ['live', 'staging', 'testing'], true) ? $value : 'live';
    }

    public static function customEndpoint(): string
    {
        return trim((string) \Configuration::getGlobalValue(self::ENDPOINT));
    }

    public static function baseUrl(): string
    {
        $env = trim((string) getenv('SUPERTEXT_API_ENDPOINT'));

        return SupertextClient::baseUrlFor(self::environment(), $env !== '' ? $env : self::customEndpoint());
    }

    public static function timeout(): int
    {
        $value = (int) \Configuration::getGlobalValue(self::TIMEOUT);

        return $value >= 30 ? $value : 180;
    }

    /** @return array<int, array{code: string, tone: string}> id_lang => per-language settings */
    public static function languages(): array
    {
        $data = json_decode((string) \Configuration::getGlobalValue(self::LANGUAGES), true);
        $out  = [];

        foreach (\is_array($data) ? $data : [] as $idLang => $row) {
            $out[(int) $idLang] = [
                'code' => trim((string) ($row['code'] ?? '')),
                'tone' => \in_array($row['tone'] ?? '', self::TONES, true) ? $row['tone'] : 'default',
            ];
        }

        return $out;
    }

    /** @param array<int, array{code: string, tone: string}> $languages */
    public static function saveLanguages(array $languages): void
    {
        \Configuration::updateGlobalValue(self::LANGUAGES, json_encode($languages, JSON_UNESCAPED_SLASHES));
    }

    /** The code sent to Supertext as target language: the override, else the language's locale, always with a region (de → de-DE). */
    public static function targetCode(\Language $language): string
    {
        return LanguageCodes::target(self::localeOf($language), self::languages()[(int) $language->id]['code'] ?? '');
    }

    /** The code sent to Supertext as source language: the bare language of the override or locale (en-GB → en). */
    public static function sourceCode(\Language $language): string
    {
        return LanguageCodes::source(self::localeOf($language), self::languages()[(int) $language->id]['code'] ?? '');
    }

    /** The language's locale (en-GB). Not iso_code: PrestaShop uses country-like iso codes for some languages (gb, br, mx). */
    private static function localeOf(\Language $language): string
    {
        return (string) ($language->locale ?: $language->language_code ?: $language->iso_code);
    }

    public static function tone(int $idLang): string
    {
        return self::languages()[$idLang]['tone'] ?? 'default';
    }

    /** "de-ch" or "de_CH" → "de-CH" */
    public static function formatTag(string $tag): string
    {
        return LanguageCodes::format($tag);
    }

    public static function client(): SupertextClient
    {
        return new SupertextClient(self::apiKey(), self::baseUrl(), new CurlTransport(), self::timeout());
    }
}
