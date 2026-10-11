<?php

/**
 * @package     Supertext Translation for PrestaShop
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\PrestaShop\Api;

/**
 * Turns language tags into the codes Supertext accepts.
 *
 * Source: the bare language ("en-GB" → "en"). Target: a regional code ("de_ch" → "de-CH");
 * a bare target gets a regional default ("de" → "de-DE"), because Supertext rejects bare
 * targets with INVALID_LANGUAGE_PAIR. Unknown bare codes are sent unchanged.
 */
final class LanguageCodes
{
    /** Regional defaults for bare target languages (same table in every Supertext plugin). */
    public const DEFAULT_REGIONS = [
        'ar' => 'ar-SA', 'bg' => 'bg-BG', 'cs' => 'cs-CZ', 'da' => 'da-DK', 'de' => 'de-DE',
        'el' => 'el-GR', 'en' => 'en-US', 'es' => 'es-ES', 'et' => 'et-EE', 'fi' => 'fi-FI',
        'fr' => 'fr-FR', 'hr' => 'hr-HR', 'hu' => 'hu-HU', 'it' => 'it-IT', 'ja' => 'ja-JP',
        'ko' => 'ko-KR', 'lt' => 'lt-LT', 'lv' => 'lv-LV', 'nb' => 'nb-NO', 'nl' => 'nl-NL',
        'pl' => 'pl-PL', 'pt' => 'pt-PT', 'ro' => 'ro-RO', 'ru' => 'ru-RU', 'sk' => 'sk-SK',
        'sl' => 'sl-SI', 'sr' => 'sr-RS', 'sv' => 'sv-SE', 'th' => 'th-TH', 'tr' => 'tr-TR',
        'uk' => 'uk-UA', 'vi' => 'vi-VN', 'zh' => 'zh-CN',
    ];

    /** "de-ch" or "de_CH" → "de-CH", "zh_hant" → "zh-Hant", "es-419" stays. */
    public static function format(string $tag): string
    {
        $parts = preg_split('/[-_]/', trim($tag)) ?: [];
        $out   = [strtolower((string) array_shift($parts))];

        foreach ($parts as $part) {
            $out[] = \strlen($part) === 2 ? strtoupper($part) : (ctype_digit($part) ? $part : ucfirst(strtolower($part)));
        }

        return implode('-', array_filter($out, static fn (string $part): bool => $part !== ''));
    }

    /** Source language: the bare language of the override, else of the CMS tag ("en-GB" → "en"). */
    public static function source(string $tag, string $override = ''): string
    {
        $tag = trim($override) !== '' ? $override : $tag;

        return strtolower((string) strtok(trim($tag), '-_'));
    }

    /** Target language: the override, else the CMS tag, as a regional code ("de" → "de-DE"). */
    public static function target(string $tag, string $override = ''): string
    {
        $code = self::format(trim($override) !== '' ? $override : $tag);

        if ($code !== '' && !str_contains($code, '-')) {
            return self::DEFAULT_REGIONS[$code] ?? $code;
        }

        return $code;
    }
}
