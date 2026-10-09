<?php

namespace Supertext\PrestaShop\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Supertext\PrestaShop\Tests\Support\ModuleStrings;

require_once __DIR__ . '/../../tools/RegionalTranslations.php';

/**
 * Every string the module shows (domain Modules.Supertext.Admin) has a German, French and
 * Italian translation with the same placeholders, and the regional copies are up to date.
 */
final class TranslationFilesTest extends TestCase
{
    private const DIR = __DIR__ . '/../../supertext/translations';

    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        foreach (['de-DE', 'fr-FR', 'it-IT', ...array_keys(\RegionalTranslations::COPIES)] as $locale) {
            yield $locale => [$locale];
        }
    }

    #[DataProvider('locales')]
    public function testEveryStringIsTranslated(string $locale): void
    {
        $units   = self::units($locale);
        $strings = ModuleStrings::all(__DIR__ . '/../../supertext');

        self::assertGreaterThan(50, \count($strings), 'the string scanner found too little');
        self::assertSame([], array_values(array_diff($strings, array_keys($units))), "missing in $locale");
        self::assertSame([], array_values(array_diff(array_keys($units), $strings)), "no longer used, remove from $locale");

        foreach ($units as $source => $target) {
            self::assertNotSame('', trim($target), "$locale: empty translation of \"$source\"");
            self::assertSame(self::placeholders($source), self::placeholders($target), "$locale: placeholders of \"$source\"");
        }
    }

    public function testRegionalCopiesAreInSync(): void
    {
        foreach (\RegionalTranslations::COPIES as $to => $from) {
            $expected = \RegionalTranslations::derive((string) file_get_contents(\RegionalTranslations::path(self::DIR, $from)), $from, $to);

            self::assertSame($expected, file_get_contents(\RegionalTranslations::path(self::DIR, $to)), "$to is out of date: run php tools/sync-translations.php");
        }
    }

    /** @return array<string, string> source => target */
    private static function units(string $locale): array
    {
        $file = self::DIR . "/$locale/ModulesSupertextAdmin.$locale.xlf";
        self::assertFileExists($file);

        $xml = simplexml_load_file($file);
        self::assertNotFalse($xml, "$file is not valid XML");
        self::assertSame($locale, (string) $xml->file['target-language']);

        $units = [];

        foreach ($xml->file->body->{'trans-unit'} as $unit) {
            $source = (string) $unit->source;
            self::assertArrayNotHasKey($source, $units, "$locale: duplicate \"$source\"");
            $units[$source] = (string) $unit->target;
        }

        return $units;
    }

    /** @return list<string> */
    private static function placeholders(string $text): array
    {
        preg_match_all('/%[a-z]+%|https?:\/\/\S+[^.\s)]|SUPERTEXT_[A-Z_]+|Supertext/', $text, $matches);
        $found = array_values(array_unique($matches[0]));
        sort($found);

        return $found;
    }
}
