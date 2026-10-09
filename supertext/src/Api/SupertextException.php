<?php

/**
 * @package     Supertext Translation for PrestaShop
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\PrestaShop\Api;

/**
 * Any failure talking to Supertext. The message is safe to show to editors.
 *
 * The English message is built from a template with %placeholders% so the back office can
 * translate it (domain Modules.Supertext.Admin): see template(), parameters() and detail().
 */
final class SupertextException extends \RuntimeException
{
    /**
     * @param array<string, string|int> $parameters values for the template's %placeholders%
     * @param string                    $detail     extra text from Supertext, shown in brackets, never translated
     */
    public function __construct(
        private readonly string $template,
        private readonly array $parameters = [],
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly string $detail = '',
    ) {
        $message = strtr($template, array_map('strval', $parameters));

        parent::__construct($detail !== '' ? $message . ' (' . $detail . ')' : $message, $code, $previous);
    }

    /** The English message with its %placeholders%, as listed in the translation files. */
    public function template(): string
    {
        return $this->template;
    }

    /** @return array<string, string|int> */
    public function parameters(): array
    {
        return $this->parameters;
    }

    public function detail(): string
    {
        return $this->detail;
    }
}
