<?php

namespace Supertext\PrestaShop\Tests\Support;

/**
 * Finds the module's user-visible strings (domain Modules.Supertext.Admin) in its PHP,
 * Smarty and Twig files, plus the English messages of SupertextException.
 */
final class ModuleStrings
{
    public const DOMAIN = 'Modules.Supertext.Admin';

    /** @return list<string> */
    public static function all(string $moduleDir): array
    {
        $strings = [];

        foreach (self::files($moduleDir) as $file) {
            $code = (string) file_get_contents($file);
            $ext  = pathinfo($file, PATHINFO_EXTENSION);

            $patterns = match ($ext) {
                'tpl'  => ["/\\{l s='((?:[^'\\\\]|\\\\.)*)' d='Modules\\.Supertext\\.Admin'/"],
                'twig' => ["/'((?:[^'\\\\]|\\\\.)*)'\\|trans\\(/"],
                default => [
                    "/->trans\\('((?:[^'\\\\]|\\\\.)*)',/",
                    "/\\\$this->t\\('((?:[^'\\\\]|\\\\.)*)'/",
                    "/new SupertextException\\('((?:[^'\\\\]|\\\\.)*)'/",
                ],
            };

            // HTTP error messages of the API client: the arms of its "$message = match" block.
            if (preg_match('/\$message = match \(true\) \{(.*?)\};/s', $code, $block)) {
                $patterns[] = "/=> '((?:[^'\\\\]|\\\\.)*)',/";
                preg_match_all($patterns[\count($patterns) - 1], $block[1], $matches);
                array_push($strings, ...$matches[1]);
                array_pop($patterns);
            }

            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $code, $matches);
                array_push($strings, ...$matches[1]);
            }
        }

        $strings = array_map(static fn (string $s): string => stripslashes($s), $strings);
        $strings = array_values(array_unique($strings));
        sort($strings);

        return $strings;
    }

    /** @return list<string> */
    private static function files(string $moduleDir): array
    {
        $files = [];
        $it    = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($moduleDir, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            $path = $file->getPathname();

            if (str_contains($path, '/vendor/') || str_contains($path, '/translations/')) {
                continue;
            }

            if (preg_match('/\.(php|tpl|twig)$/', $path)) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }
}
