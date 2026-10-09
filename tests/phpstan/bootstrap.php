<?php

/**
 * PHPStan bootstrap: loads a PrestaShop installation's autoloaders so PHPStan knows the
 * PrestaShop classes the module uses (Module, Product, Configuration, the Symfony bundle, …).
 * PS_ROOT_DIR points to the PrestaShop sources; CI copies them out of the prestashop/prestashop:9
 * image. See docs/DEVELOPER.md → Code quality and security checks.
 */

$root = getenv('PS_ROOT_DIR');
if (!is_string($root) || $root === '' || !is_file($root . '/config/defines.inc.php')) {
    fwrite(STDERR, "Set PS_ROOT_DIR to a PrestaShop 9 installation (with vendor/) to run PHPStan.\n");
    exit(1);
}
$root = rtrim($root, '/');

define('_PS_ROOT_DIR_', $root);
// The class index PrestaShop's autoloader writes goes to a temporary cache folder.
define('_PS_CACHE_DIR_', sys_get_temp_dir() . '/supertext-phpstan-ps-cache/');
if (!is_dir(_PS_CACHE_DIR_)) {
    mkdir(_PS_CACHE_DIR_, 0777, true);
}
// Normally defined by the shop's parameters.php.
define('_DB_PREFIX_', 'ps_');

require_once $root . '/config/defines.inc.php';
require_once $root . '/config/autoload.php';
