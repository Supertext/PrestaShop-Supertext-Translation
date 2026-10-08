<?php

/**
 * @package     Supertext Translation for PrestaShop
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\PrestaShop\Translation;

/**
 * What can be translated, and which fields. Keep "What is translated" in docs/DEVELOPER.md
 * and docs/USER_GUIDE.md in sync with this list.
 */
final class EntityTypes
{
    /**
     * type => [
     *   class:     PrestaShop ObjectModel class,
     *   tab:       admin tab whose "edit" permission is needed,
     *   grid:      grid id and the name of its bulk checkbox field,
     *   label:     field shown as the item's name,
     *   fields:    translatable lang fields (html: rich text),
     *   slug:      [slug field, field it is made from],
     *   editParams: query parameters for Link::getAdminLink(tab) to open the item
     * ]
     */
    public const TYPES = [
        'product' => [
            'class'      => 'Product',
            'tab'        => 'AdminProducts',
            'grid'       => ['product', 'product_bulk'],
            'label'      => 'name',
            'fields'     => [
                'name'               => ['html' => false],
                'description_short'  => ['html' => true],
                'description'        => ['html' => true],
                'meta_title'         => ['html' => false],
                'meta_description'   => ['html' => false],
                'available_now'      => ['html' => false],
                'available_later'    => ['html' => false],
                'delivery_in_stock'  => ['html' => false],
                'delivery_out_stock' => ['html' => false],
            ],
            'slug'       => ['link_rewrite', 'name'],
            'editParams' => ['id' => 'id_product', 'extra' => ['updateproduct' => 1]],
        ],
        'category' => [
            'class'      => 'Category',
            'tab'        => 'AdminCategories',
            'grid'       => ['category', 'category_id_category'],
            'label'      => 'name',
            'fields'     => [
                'name'                   => ['html' => false],
                'description'            => ['html' => true],
                'additional_description' => ['html' => true],
                'meta_title'             => ['html' => false],
                'meta_description'       => ['html' => false],
            ],
            'slug'       => ['link_rewrite', 'name'],
            'editParams' => ['id' => 'id_category', 'extra' => ['updatecategory' => 1]],
        ],
        'cms' => [
            'class'      => 'CMS',
            'tab'        => 'AdminCmsContent',
            'grid'       => ['cms_page', 'cms_page_bulk'],
            'label'      => 'meta_title',
            'fields'     => [
                'meta_title'       => ['html' => false],
                'head_seo_title'   => ['html' => false],
                'meta_description' => ['html' => false],
                'content'          => ['html' => true],
            ],
            'slug'       => ['link_rewrite', 'meta_title'],
            'editParams' => ['id' => 'id_cms', 'extra' => ['updatecms' => 1]],
        ],
    ];

    /** @return array<string, mixed> */
    public static function get(string $type): array
    {
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException(sprintf('Unknown content type "%s".', $type));
        }

        return self::TYPES[$type];
    }

    /**
     * Finds the type and ids in a grid's bulk form submission (e.g. product_bulk[]=3).
     *
     * @param array<string, mixed> $post
     *
     * @return array{0: string, 1: list<int>}|null
     */
    public static function fromBulkSubmission(array $post): ?array
    {
        foreach (self::TYPES as $type => $definition) {
            $ids = $post[$definition['grid'][1]] ?? null;

            if (\is_array($ids) && $ids !== []) {
                return [$type, self::ids($ids)];
            }
        }

        return null;
    }

    /**
     * @param mixed $ids
     *
     * @return list<int>
     */
    public static function ids($ids): array
    {
        $ids = \is_array($ids) ? $ids : explode(',', (string) $ids);

        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    }
}
