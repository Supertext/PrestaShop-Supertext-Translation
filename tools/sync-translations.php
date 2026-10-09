<?php

/**
 * Writes the regional translation files (de-CH, fr-CH, it-CH, …) from de-DE, fr-FR and it-IT.
 * Run after editing those: php tools/sync-translations.php
 */
require __DIR__ . '/RegionalTranslations.php';

$dir = dirname(__DIR__) . '/supertext/translations';

foreach (RegionalTranslations::COPIES as $to => $from) {
    $target = RegionalTranslations::path($dir, $to);

    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0777, true);
    }

    file_put_contents($target, RegionalTranslations::derive((string) file_get_contents(RegionalTranslations::path($dir, $from)), $from, $to));
    echo $target, PHP_EOL;
}
