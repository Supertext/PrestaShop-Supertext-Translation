<?php

namespace Supertext\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Supertext\PrestaShop\Translation\FieldPlanner;

final class FieldPlannerTest extends TestCase
{
    private const FIELDS = [
        'name'        => ['html' => false, 'size' => 20],
        'description' => ['html' => true, 'size' => 0],
        'meta_title'  => ['html' => false, 'size' => 255],
        'available'   => ['html' => false, 'size' => 255],
    ];

    private const SOURCE = [
        'name'        => 'Praline box',
        'description' => '<p>Made in <strong>Bern</strong>.</p>',
        'meta_title'  => 'Pralines',
        'available'   => '',
    ];

    public function testFillsEmptyAndCopiedFieldsButKeepsTranslations(): void
    {
        // PrestaShop copies the default language into a new one: "Praline box" is untranslated.
        $planner = new FieldPlanner(self::FIELDS, self::SOURCE, ['name' => 'Praline box', 'description' => '', 'meta_title' => 'Pralinen'], false);

        self::assertSame(['name', 'description'], $planner->toTranslate());
        self::assertSame(['meta_title'], $planner->kept());
        self::assertSame('partial', $planner->state());
    }

    public function testOverwriteSendsEverythingWithText(): void
    {
        $planner = new FieldPlanner(self::FIELDS, self::SOURCE, ['meta_title' => 'Pralinen'], true);

        self::assertSame(['name', 'description', 'meta_title'], $planner->toTranslate());
        self::assertSame([], $planner->kept());
    }

    public function testStates(): void
    {
        self::assertSame('none', (new FieldPlanner(self::FIELDS, self::SOURCE, self::SOURCE, false))->state());
        self::assertSame('translated', (new FieldPlanner(self::FIELDS, self::SOURCE, [
            'name' => 'Pralinenbox', 'description' => '<p>In Bern gemacht.</p>', 'meta_title' => 'Pralinen',
        ], false))->state());
        // Only whitespace differs: still the copied source.
        self::assertFalse((new FieldPlanner(self::FIELDS, self::SOURCE, ['name' => "Praline\n box "], false))->isTranslated('name'));
    }

    public function testDocumentAndApplyRoundTrip(): void
    {
        $planner = new FieldPlanner(self::FIELDS, self::SOURCE, [], false);
        $html    = $planner->document();

        self::assertStringContainsString('<div data-st-id="0">Praline box</div>', $html);
        self::assertStringContainsString('<div data-st-id="1"><p>Made in <strong>Bern</strong>.</p></div>', $html);

        $translated = str_replace(
            ['Praline box', 'Made in <strong>Bern</strong>.', '>Pralines<'],
            ['Pralinenschachtel mit dunkler Schokolade', 'In <strong>Bern</strong> gemacht.', '>Pralinen<'],
            $html,
        );
        $values = $planner->apply($translated);

        // Plain fields are cut to their size, rich text is not.
        self::assertSame('Pralinenschachtel mi', $values['name']);
        self::assertSame('<p>In <strong>Bern</strong> gemacht.</p>', $values['description']);
        self::assertSame('Pralinen', $values['meta_title']);
        self::assertArrayNotHasKey('available', $values);
    }

    public function testSlugFollowsTheTitleOnlyWhileUntranslated(): void
    {
        $planner = new FieldPlanner(self::FIELDS, self::SOURCE, [], false);

        self::assertTrue($planner->shouldRewriteSlug('praline-box', 'praline-box'));
        self::assertTrue($planner->shouldRewriteSlug('praline-box', ''));
        self::assertFalse($planner->shouldRewriteSlug('praline-box', 'pralinen'));
        self::assertTrue((new FieldPlanner(self::FIELDS, self::SOURCE, [], true))->shouldRewriteSlug('praline-box', 'pralinen'));
    }
}
