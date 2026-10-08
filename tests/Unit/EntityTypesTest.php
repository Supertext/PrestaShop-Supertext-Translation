<?php

namespace Supertext\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Supertext\PrestaShop\Translation\EntityTypes;

final class EntityTypesTest extends TestCase
{
    public function testReadsTheGridsBulkCheckboxes(): void
    {
        self::assertSame(['product', [3, 7]], EntityTypes::fromBulkSubmission(['product_bulk' => ['3', '7', '3']]));
        self::assertSame(['category', [12]], EntityTypes::fromBulkSubmission(['category_id_category' => ['12']]));
        self::assertSame(['cms', [2]], EntityTypes::fromBulkSubmission(['cms_page_bulk' => ['2'], 'other' => '1']));
        self::assertNull(EntityTypes::fromBulkSubmission(['product_bulk' => []]));
    }

    public function testIds(): void
    {
        self::assertSame([1, 2, 5], EntityTypes::ids('1,2,,x,5,2'));
        self::assertSame([4], EntityTypes::ids(['4', '-1', '0']));
    }

    public function testEveryTypeHasItsSlugSourceAndLabelAmongItsFields(): void
    {
        foreach (EntityTypes::TYPES as $type => $definition) {
            self::assertArrayHasKey($definition['slug'][1], $definition['fields'], $type);
            self::assertArrayHasKey($definition['label'], $definition['fields'], $type);
        }
    }
}
