<?php

/**
 * @package     Supertext Translation for PrestaShop
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\PrestaShop\Translation;

use Supertext\PrestaShop\Api\SupertextClient;
use Supertext\PrestaShop\Api\SupertextException;
use Supertext\PrestaShop\Settings;

/**
 * Translates one product, category or CMS page from one shop language into another and
 * saves the result in the target language's fields of the same item.
 */
final class EntityTranslator
{
    public function __construct(private readonly SupertextClient $client)
    {
    }

    public static function create(): self
    {
        return new self(Settings::client());
    }

    /**
     * @return array{status: string, title: string, translated: list<string>, kept: list<string>}
     *               status: "translated" or "unchanged" (everything was already translated)
     *
     * @throws SupertextException|\PrestaShopException on failure; the message is shown to the editor
     */
    public function translate(string $type, int $id, \Language $source, \Language $target, bool $overwrite, ?int $idShop = null): array
    {
        $definition = EntityTypes::get($type);
        $object     = self::load($definition['class'], $id, $idShop);

        if (!\Validate::isLoadedObject($object)) {
            throw new SupertextException(sprintf('%s %d does not exist.', $definition['class'], $id));
        }

        $sourceId = (int) $source->id;
        $targetId = (int) $target->id;
        $fields   = [];
        $src      = [];
        $tgt      = [];

        foreach ($definition['fields'] as $field => $options) {
            $fields[$field] = ['html' => $options['html'], 'size' => (int) ($definition['class']::$definition['fields'][$field]['size'] ?? 0)];
            $src[$field]    = (string) ($object->{$field}[$sourceId] ?? '');
            $tgt[$field]    = (string) ($object->{$field}[$targetId] ?? '');
        }

        $planner = new FieldPlanner($fields, $src, $tgt, $overwrite);
        $title   = (string) ($object->{$definition['label']}[$sourceId] ?? '');

        if ($planner->toTranslate() === []) {
            return ['status' => 'unchanged', 'title' => $title, 'translated' => [], 'kept' => $planner->kept()];
        }

        $translatedHtml = $this->client->translateDocument(
            $planner->document(),
            Settings::targetCode($target),
            (string) $source->iso_code,
            Settings::tone($targetId),
        );

        $values = $planner->apply($translatedHtml);

        foreach ($values as $field => $value) {
            if (!$fields[$field]['html']) {
                $value = self::sanitizeLine($value, (string) ($definition['class']::$definition['fields'][$field]['validate'] ?? ''));
            }

            $object->{$field}[$targetId] = $value;
        }

        [$slugField, $slugFrom] = $definition['slug'];

        if (isset($values[$slugFrom])) {
            $sourceSlug = (string) ($object->{$slugField}[$sourceId] ?? '');
            $targetSlug = (string) ($object->{$slugField}[$targetId] ?? '');

            if ($planner->shouldRewriteSlug($sourceSlug, $targetSlug)) {
                $slug = \Tools::str2url((string) $object->{$slugFrom}[$targetId]);

                if ($slug !== '') {
                    $object->{$slugField}[$targetId] = $slug;
                }
            }
        }

        $error = $object->validateFieldsLang(false, true);

        if ($error !== true) {
            throw new SupertextException(sprintf('The translation could not be saved: %s', strip_tags((string) $error)));
        }

        if (!$object->update()) {
            throw new SupertextException('The translation could not be saved.');
        }

        return ['status' => 'translated', 'title' => $title, 'translated' => array_keys($values), 'kept' => $planner->kept()];
    }

    /** The admin URL that opens the item for editing. */
    public static function editUrl(string $type, int $id): string
    {
        $definition = EntityTypes::get($type);
        $params     = [$definition['editParams']['id'] => $id] + $definition['editParams']['extra'];

        // PrestaShop converts these legacy parameters to the Symfony edit page's route.
        return \Context::getContext()->link->getAdminLink($definition['tab'], true, [], $params);
    }

    /** The item's name in a language (for lists), or "" if it doesn't exist. */
    public static function title(string $type, int $id, int $idLang): string
    {
        $definition = EntityTypes::get($type);
        $object     = self::load($definition['class'], $id, null);

        return \Validate::isLoadedObject($object) ? (string) ($object->{$definition['label']}[$idLang] ?? '') : '';
    }

    /**
     * How far an item is already translated into each target language.
     *
     * @param list<int> $targetIds
     *
     * @return array<int, string> id_lang => "none", "partial" or "translated"
     */
    public static function states(string $type, int $id, int $sourceId, array $targetIds): array
    {
        $definition = EntityTypes::get($type);
        $object     = self::load($definition['class'], $id, null);
        $fields     = array_map(static fn (array $o): array => ['html' => $o['html'], 'size' => 0], $definition['fields']);
        $source     = [];
        $states     = [];

        foreach (array_keys($fields) as $field) {
            $source[$field] = (string) ($object->{$field}[$sourceId] ?? '');
        }

        foreach ($targetIds as $targetId) {
            $target = [];

            foreach (array_keys($fields) as $field) {
                $target[$field] = (string) ($object->{$field}[$targetId] ?? '');
            }

            $states[$targetId] = (new FieldPlanner($fields, $source, $target, false))->state();
        }

        return $states;
    }

    public static function exists(string $type, int $id): bool
    {
        return \Validate::isLoadedObject(self::load(EntityTypes::get($type)['class'], $id, null));
    }

    /** Loads an item with all languages (lang fields become arrays keyed by id_lang). */
    private static function load(string $class, int $id, ?int $idShop): \ObjectModel
    {
        return match ($class) {
            'Product' => new \Product($id, false, null, $idShop),
            'Category' => new \Category($id, null, $idShop),
            default => new $class($id, null, $idShop),
        };
    }

    /** PrestaShop's name validators refuse characters such as < > = { }; a translation can't need them. */
    private static function sanitizeLine(string $value, string $validate): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        if (strcasecmp($validate, 'isCatalogName') === 0) {
            return trim((string) preg_replace('/[<>;=#{}]/u', '', $value));
        }

        if (strcasecmp($validate, 'isGenericName') === 0) {
            return trim((string) preg_replace('/[<>={}]/u', '', $value));
        }

        return $value;
    }
}
