<?php

namespace Supertext\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Supertext\PrestaShop\Api\LanguageCodes;
use Supertext\PrestaShop\Api\SupertextException;

/** Shop language → Supertext code: the setting, else an exact match, else unsupported_language. Never guessed. */
final class LanguageCodesTest extends TestCase
{
    public function testLocaleThatIsASupertextCodeIsUsed(): void
    {
        self::assertSame('de-CH', LanguageCodes::target('de-CH'));
        self::assertSame('fr-CH', LanguageCodes::target('fr_ch'));
        self::assertSame('es', LanguageCodes::target('ES'));
        self::assertSame('zh-Hant', LanguageCodes::target('zh_hant'));
    }

    public function testNoDefaultRegions(): void
    {
        foreach (['de', 'es-ES', 'sl-SI', 'zh-CN', 'xx'] as $locale) {
            self::assertNull(LanguageCodes::targetOrNull($locale), $locale);
        }
    }

    public function testUnmappedLanguageIsAnUnsupportedLanguage(): void
    {
        try {
            LanguageCodes::target('es-ES');
            self::fail('Expected an exception');
        } catch (SupertextException $e) {
            self::assertSame('unsupported_language', $e->errorCode());
            self::assertSame('The language %language% has no Supertext language yet. Ask your administrator to map it in the module settings (Languages → Supertext language).', $e->template());
            self::assertSame(['%language%' => 'es-ES'], $e->parameters());
            self::assertSame('', $e->detail());
        }
    }

    public function testTheSettingWins(): void
    {
        self::assertSame('es', LanguageCodes::target('es-ES', 'es'));
        self::assertSame('de-AT', LanguageCodes::target('de-DE', 'de-AT'));
        self::assertSame('gsw-u-sd-chzh', LanguageCodes::target('de-CH', 'gsw-u-sd-chzh'));
        // A code Supertext adds later is sent as it is.
        self::assertSame('uk', LanguageCodes::target('uk-UA', 'uk'));
    }

    public function testSourceIsBareAndUsesTheSameSetting(): void
    {
        self::assertSame('en', LanguageCodes::source('en-US'));
        self::assertSame('en', LanguageCodes::source('en_GB'));
        self::assertSame('de', LanguageCodes::source('de'));
        self::assertSame('es', LanguageCodes::source('es-ES'));
        self::assertSame('de', LanguageCodes::source('xx', 'de-CH'));
        self::assertSame('', LanguageCodes::source(''));
    }

    public function testMatchPrefillsTheSetting(): void
    {
        self::assertSame('de-CH', LanguageCodes::match('de-ch'));
        self::assertNull(LanguageCodes::match('de'));
        self::assertContains('nb', array_column(LanguageCodes::targets(), 'code'));
    }

    public function testPoliteness(): void
    {
        self::assertTrue(LanguageCodes::supportsPoliteness('de-CH'));
        self::assertFalse(LanguageCodes::supportsPoliteness('en-US'));
        self::assertNull(LanguageCodes::supportsPoliteness('xx'));
    }
}
