<?php

namespace Supertext\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Supertext\PrestaShop\Api\LanguageCodes;

final class LanguageCodesTest extends TestCase
{
    public function testBareSourceStaysBare(): void
    {
        self::assertSame('en', LanguageCodes::source('en'));
    }

    public function testRegionalSourceBecomesBare(): void
    {
        self::assertSame('en', LanguageCodes::source('en-US'));
        self::assertSame('en', LanguageCodes::source('en_GB'));
        self::assertSame('de', LanguageCodes::source('DE-CH'));
        self::assertSame('', LanguageCodes::source(''));
    }

    public function testBareTargetGetsDefaultRegion(): void
    {
        self::assertSame('de-DE', LanguageCodes::target('de'));
        self::assertSame('en-US', LanguageCodes::target('EN'));
        self::assertSame('sl-SI', LanguageCodes::target('sl'));
    }

    public function testRegionalTargetIsKeptAndNormalized(): void
    {
        self::assertSame('de-CH', LanguageCodes::target('de_ch'));
        self::assertSame('fr-CH', LanguageCodes::target('fr-CH'));
        self::assertSame('zh-Hant', LanguageCodes::target('zh_hant'));
        self::assertSame('es-419', LanguageCodes::target('es-419'));
    }

    public function testOverrideWins(): void
    {
        self::assertSame('de-AT', LanguageCodes::target('de-DE', 'de_at'));
        self::assertSame('de-DE', LanguageCodes::target('de-CH', 'de'));
        self::assertSame('pt', LanguageCodes::source('en-GB', 'pt-BR'));
    }

    public function testUnknownBareCodeIsUnchanged(): void
    {
        self::assertSame('rm', LanguageCodes::target('rm'));
    }
}
