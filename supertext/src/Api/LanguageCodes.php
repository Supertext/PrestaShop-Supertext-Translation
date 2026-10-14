<?php

/**
 * @package     Supertext Translation for PrestaShop
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\PrestaShop\Api;

use Supertext\PrestaShop\Sdk\V1\Languages;
use Supertext\PrestaShop\Sdk\V1\SupertextException as SdkException;

/**
 * Shop languages → Supertext language codes, without guessing (the Supertext SDK's rules, the
 * same in every Supertext plugin).
 *
 * Supertext accepts exactly the codes of its `GET features` list: bare sources ("en"); targets
 * regional only for German, English, French, Italian and Portuguese ("de-CH", "en-US", "pt-BR"),
 * with a script for Chinese and Serbian ("zh-Hans", "sr-Latn"), bare for the others ("es", "nl").
 *
 * A language's *Supertext language* (module settings) wins; otherwise a locale that equals a
 * Supertext code apart from case and "_"/"-" is used ("de-CH", "fr_ch" → "fr-CH", "es");
 * anything else is the error `unsupported_language` and nothing is sent. There are no default
 * regions: a language with the locale "de" or "es-ES" needs its Supertext language. The same
 * setting serves the source: a mapped "de-CH" is sent as the source "de".
 *
 * Has no PrestaShop dependencies.
 */
final class LanguageCodes
{
    /**
     * Every target Supertext offers: code, English name, whether it has formal/informal.
     *
     * @return list<array{code: string, name: string, politeness: bool}>
     */
    public static function targets(): array
    {
        return Languages::TARGETS;
    }

    /** The Supertext target that equals the locale apart from case and "_"/"-", else null ("de" → null, "de_ch" → "de-CH"). */
    public static function match(string $locale): ?string
    {
        return Languages::matchTarget($locale);
    }

    /**
     * Target code to send: the language's Supertext language, else an exact match.
     *
     * @throws SupertextException unsupported_language when there is neither (nothing is sent)
     */
    public static function target(string $locale, string $override = ''): string
    {
        try {
            return Languages::target($locale, self::mapping($locale, $override));
        } catch (SdkException $e) {
            throw SupertextClient::fromSdk($e);
        }
    }

    /** The target code, or null when the language has no Supertext language (settings page, translate page). */
    public static function targetOrNull(string $locale, string $override = ''): ?string
    {
        try {
            return self::target($locale, $override);
        } catch (SupertextException) {
            return null;
        }
    }

    /**
     * Source code to send: the source form of the language's Supertext language, else of the
     * locale ("en-GB" → "en"). Empty stays empty (auto-detect).
     *
     * @throws SupertextException unsupported_language when there is neither
     */
    public static function source(string $locale, string $override = ''): string
    {
        try {
            return Languages::source($locale, self::mapping($locale, $override));
        } catch (SdkException $e) {
            throw SupertextClient::fromSdk($e);
        }
    }

    /** Whether Supertext offers formal/informal for a target code (null: a code the SDK's list doesn't know). */
    public static function supportsPoliteness(string $target): ?bool
    {
        return Languages::supportsPoliteness($target);
    }

    /**
     * One language's setting as the SDK's mapping (locale → Supertext code).
     *
     * @return array<string, string>
     */
    private static function mapping(string $locale, string $override): array
    {
        return trim($override) !== '' && trim($locale) !== '' ? [trim($locale) => trim($override)] : [];
    }
}
