<?php

/**
 * PrestaShop loads a module's translations for the employee's exact locale
 * (supertext/translations/<locale>/ModulesSupertextAdmin.<locale>.xlf) and doesn't fall back
 * from de-CH to de-DE. The files for de-DE, fr-FR and it-IT are edited by hand; the regional
 * copies are generated from them by tools/sync-translations.php.
 */
final class RegionalTranslations
{
    /** regional locale => edited locale */
    public const COPIES = [
        'de-AT' => 'de-DE',
        'de-CH' => 'de-DE',
        'fr-BE' => 'fr-FR',
        'fr-CA' => 'fr-FR',
        'fr-CH' => 'fr-FR',
        'it-CH' => 'it-IT',
    ];

    public static function path(string $translationsDir, string $locale): string
    {
        return $translationsDir . '/' . $locale . '/ModulesSupertextAdmin.' . $locale . '.xlf';
    }

    /** The regional file's content, derived from the edited locale's file. */
    public static function derive(string $xliff, string $from, string $to): string
    {
        $xliff = str_replace('target-language="' . $from . '"', 'target-language="' . $to . '"', $xliff);

        if ($to === 'de-CH') {
            // Swiss German spelling has no ß; only the <target> texts change.
            $xliff = preg_replace_callback('#<target>(.*?)</target>#s', static fn (array $m): string => '<target>' . str_replace('ß', 'ss', $m[1]) . '</target>', $xliff);
        }

        return $xliff;
    }
}
