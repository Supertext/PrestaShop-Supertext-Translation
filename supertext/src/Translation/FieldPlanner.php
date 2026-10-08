<?php

/**
 * @package     Supertext Translation for PrestaShop
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\PrestaShop\Translation;

use Supertext\PrestaShop\Api\HtmlDocument;

/**
 * Decides which fields of one item go to Supertext and what is written back.
 * No PrestaShop dependencies, so the rules are unit-tested on their own.
 *
 * A target field counts as *already translated* when it has text that differs from the
 * source. PrestaShop fills a new language with a copy of the default language, so a
 * target identical to the source (or empty) is untranslated and is always filled.
 * Already translated fields are kept unless $overwrite is set.
 */
final class FieldPlanner
{
    /**
     * @param array<string, array{html: bool, size: int}> $fields field => definition
     * @param array<string, string>                         $source field => text in the source language
     * @param array<string, string>                         $target field => text in the target language
     */
    public function __construct(
        private readonly array $fields,
        private readonly array $source,
        private readonly array $target,
        private readonly bool $overwrite,
    ) {
    }

    /** @return list<string> fields to translate, in definition order */
    public function toTranslate(): array
    {
        $send = [];

        foreach (array_keys($this->fields) as $field) {
            $source = (string) ($this->source[$field] ?? '');

            if (trim($source) === '') {
                continue;
            }

            if (!$this->overwrite && $this->isTranslated($field)) {
                continue;
            }

            $send[] = $field;
        }

        return $send;
    }

    /** @return list<string> fields that keep their existing translation */
    public function kept(): array
    {
        if ($this->overwrite) {
            return [];
        }

        return array_values(array_filter(
            array_keys($this->fields),
            fn (string $field): bool => trim((string) ($this->source[$field] ?? '')) !== '' && $this->isTranslated($field),
        ));
    }

    /** "none", "partial" (some fields translated) or "translated" */
    public function state(): string
    {
        $kept  = \count($this->kept());
        $total = $kept + \count((new self($this->fields, $this->source, $this->target, false))->toTranslate());

        return match (true) {
            $kept === 0      => 'none',
            $kept === $total => 'translated',
            default          => 'partial',
        };
    }

    public function isTranslated(string $field): bool
    {
        $target = (string) ($this->target[$field] ?? '');

        return trim($target) !== '' && self::normalize($target) !== self::normalize((string) ($this->source[$field] ?? ''));
    }

    /** The document to send: one data-st-id element per field. */
    public function document(): string
    {
        $segments = [];

        foreach ($this->toTranslate() as $i => $field) {
            $segments[$i] = ['text' => (string) $this->source[$field], 'html' => $this->fields[$field]['html']];
        }

        return HtmlDocument::build($segments);
    }

    /**
     * Maps the translated document back to field values, trimmed to each field's size.
     *
     * @return array<string, string> field => translated text
     */
    public function apply(string $translatedHtml): array
    {
        $send   = $this->toTranslate();
        $isHtml = [];

        foreach ($send as $i => $field) {
            $isHtml[$i] = $this->fields[$field]['html'];
        }

        $parsed = HtmlDocument::parse($translatedHtml, $isHtml);
        $values = [];

        foreach ($send as $i => $field) {
            $text = $parsed[$i] ?? '';

            if (trim($text) === '') {
                continue;
            }

            $size = $this->fields[$field]['size'];

            if (!$this->fields[$field]['html'] && $size > 0 && mb_strlen($text) > $size) {
                $text = rtrim(mb_substr($text, 0, $size));
            }

            $values[$field] = $text;
        }

        return $values;
    }

    /**
     * Whether the URL slug should follow a translated title: it is still the source's slug
     * (or empty), or $overwrite is set.
     */
    public function shouldRewriteSlug(string $sourceSlug, string $targetSlug): bool
    {
        return $this->overwrite || trim($targetSlug) === '' || $targetSlug === $sourceSlug;
    }

    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
