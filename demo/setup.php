<?php

/**
 * Demo setup, run on every start of the demo container (demo/entrypoint.sh) and in CI:
 *
 *   php demo/setup.php [--remove-employee=<email>]
 *
 * Makes sure the Supertext module is installed, the shop has German (Switzerland), French
 * and Italian next to English, the sample category, product and CMS page exist in English,
 * and the DEMO_ADMIN_* / DEMO_EDITOR_* accounts exist. Never changes what is already there
 * (existing accounts, content and translations stay as they are). Passwords are read from
 * the environment and never printed.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = getenv('PS_ROOT') ?: '/var/www/html';
$admin = getenv('PS_FOLDER_ADMIN') ?: 'admin-demo';
define('_PS_ADMIN_DIR_', $root . '/' . $admin);
require $root . '/config/config.inc.php';

// Language packs and some models need PrestaShop's Symfony container.
global $kernel;
if (is_file($root . '/app/AdminKernel.php')) { // PrestaShop 9
    require_once $root . '/app/AdminKernel.php';
    $kernel = new AdminKernel('prod', false);
} else {
    require_once $root . '/app/AppKernel.php';
    $kernel = new AppKernel('prod', false);
}
$kernel->boot();

$log = static function (string $message): void {
    fwrite(STDOUT, '[supertext-demo] ' . $message . "\n");
};

$context           = Context::getContext();
$context->shop     = new Shop((int) Configuration::get('PS_SHOP_DEFAULT'));
$context->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
Shop::setContext(Shop::CONTEXT_SHOP, (int) $context->shop->id);

// --- Supertext module -----------------------------------------------------------------
$module = Module::getInstanceByName('supertext');

if (!$module) {
    $log('The supertext module is missing from modules/.');
    exit(1);
}

if (!Module::isInstalled('supertext')) {
    $log($module->install() ? 'Supertext module installed.' : 'Installing the Supertext module failed.');
} else {
    // Hooks added in a newer version of the module.
    foreach (['actionProductGridDefinitionModifier', 'actionCategoryGridDefinitionModifier', 'actionCmsPageGridDefinitionModifier', 'displayAdminProductsExtra'] as $hook) {
        if (!$module->isRegisteredInHook($hook)) {
            $module->registerHook($hook);
        }
    }
}

// --- Languages ------------------------------------------------------------------------
$languages = [
    // iso => [locale, name]
    'de' => ['de-CH', 'Deutsch (Schweiz)'],
    'fr' => ['fr-CH', 'Français (Suisse)'],
    'it' => ['it-CH', 'Italiano (Svizzera)'],
];

foreach ($languages as $iso => [$locale, $name]) {
    if (Language::getIdByIso($iso)) {
        continue;
    }

    $result = Language::downloadAndInstallLanguagePack($iso, _PS_VERSION_, null, true);

    if ($result !== true || !Language::getIdByIso($iso, true)) {
        // No network to prestashop.com (CI, sandboxes): add the language without its back-office pack.
        Language::checkAndAddLanguage($iso, ['name' => $name, 'language_code' => strtolower($locale), 'locale' => $locale, 'date_format_lite' => 'd.m.Y', 'date_format_full' => 'd.m.Y H:i:s', 'is_rtl' => 0], false, ['active' => 1]);
    }

    Language::loadLanguages();
    $language = new Language((int) Language::getIdByIso($iso, true));

    if (Validate::isLoadedObject($language)) {
        $language->name          = $name;
        $language->locale        = $locale;
        $language->language_code = strtolower($locale);
        $language->active        = true;
        $language->update();
        $log(sprintf('Language %s (%s) added.', $name, $locale));
    } else {
        $log(sprintf('Adding language %s failed.', $locale));
    }
}

Language::loadLanguages();
$english = (int) Language::getIdByIso('en');

// The demo keeps no files between deploys: bring back language packs and flags that the
// database knows about but the new container doesn't have yet.
foreach (Language::getLanguages(false) as $row) {
    if (!glob(_PS_ROOT_DIR_ . '/translations/' . $row['iso_code'] . '-*', GLOB_ONLYDIR)) {
        Language::downloadAndInstallLanguagePack($row['iso_code'], _PS_VERSION_, null, false);
    }

    if (!is_file(_PS_IMG_DIR_ . 'l/' . (int) $row['id_lang'] . '.jpg') && class_exists(PrestaShop\PrestaShop\Adapter\Language\LanguageImageManager::class)) {
        (new PrestaShop\PrestaShop\Adapter\Language\LanguageImageManager())->setupLanguageFlag((string) $row['locale'], (int) $row['id_lang']);
    }
}

/** Same text in every language: PrestaShop copies the default language into new ones. */
$all = static function (string $text): array {
    $out = [];

    foreach (Language::getIDs(false) as $id) {
        $out[(int) $id] = $text;
    }

    return $out;
};

// --- Sample content (English) -----------------------------------------------------------
$sample = json_decode((string) file_get_contents(__DIR__ . '/sample-content.json'), true);

$category   = null;
$categoryId = (int) Db::getInstance()->getValue('SELECT id_category FROM ' . _DB_PREFIX_ . "category_lang WHERE link_rewrite = '" . pSQL($sample['category']['link_rewrite']) . "' AND id_lang = " . $english);

if ($categoryId) {
    $category = new Category($categoryId);
} else {
    $category                         = new Category();
    $category->id_parent              = (int) Configuration::get('PS_HOME_CATEGORY');
    $category->active                 = true;
    $category->name                   = $all($sample['category']['name']);
    $category->link_rewrite           = $all($sample['category']['link_rewrite']);
    $category->description            = $all($sample['category']['description']);
    $category->additional_description = $all('');
    $category->meta_title             = $all($sample['category']['meta_title']);
    $category->meta_description       = $all($sample['category']['meta_description']);
    $category->add();
    $log('Sample category added.');
}

foreach ($sample['products'] as $data) {
    if (Db::getInstance()->getValue('SELECT id_product FROM ' . _DB_PREFIX_ . "product WHERE reference = '" . pSQL($data['reference']) . "'")) {
        continue;
    }

    $product                      = new Product();
    $product->reference           = $data['reference'];
    $product->price               = $data['price'];
    $product->id_category_default = (int) $category->id;
    $product->active              = true;
    $product->visibility          = 'both';
    $product->product_type        = 'standard';
    $product->id_tax_rules_group  = 0;

    foreach (['name', 'link_rewrite', 'description_short', 'description', 'meta_title', 'meta_description', 'available_now', 'available_later', 'delivery_in_stock', 'delivery_out_stock'] as $field) {
        $product->{$field} = $all($data[$field] ?? '');
    }

    if ($product->add()) {
        $product->addToCategories([(int) $category->id, (int) Configuration::get('PS_HOME_CATEGORY')]);
        StockAvailable::setQuantity((int) $product->id, 0, 25);
        $log(sprintf('Sample product "%s" added.', $data['name']));
    }
}

$pageId = (int) Db::getInstance()->getValue('SELECT id_cms FROM ' . _DB_PREFIX_ . "cms_lang WHERE link_rewrite = '" . pSQL($sample['page']['link_rewrite']) . "' AND id_lang = " . $english);

if (!$pageId) {
    $page                   = new CMS();
    $page->id_cms_category  = 1;
    $page->active           = true;
    $page->indexation       = true;
    $page->meta_title       = $all($sample['page']['meta_title']);
    $page->head_seo_title   = $all($sample['page']['head_seo_title']);
    $page->meta_description = $all($sample['page']['meta_description']);
    $page->content          = $all($sample['page']['content']);
    $page->link_rewrite     = $all($sample['page']['link_rewrite']);
    $page->add();
    $log('Sample CMS page added.');
}

// The editor profile needs "view" on the module to see the button on the product page.
$module->grantViewToAllProfiles();

// --- Demo accounts ----------------------------------------------------------------------
$accounts = [
    // variable prefix, fallback prefix, profile, first name
    ['DEMO_ADMIN', 'PRESTASHOP_ADMIN', (int) _PS_ADMIN_PROFILE_, 'Demo', 'Admin'],
    ['DEMO_EDITOR', null, null, 'Demo', 'Editor'],
];

// The editor uses PrestaShop's "Translator" profile, which can edit products and categories.
// The demo also lets it edit CMS pages (Design → Pages), so it can translate everything.
$translatorProfile = (int) Db::getInstance()->getValue('SELECT id_profile FROM ' . _DB_PREFIX_ . "profile_lang WHERE name = 'Translator'");

if ($translatorProfile) {
    $accounts[1][2] = $translatorProfile;

    foreach (['ADMINPARENTTHEMES', 'ADMINCMSCONTENT'] as $tab) {
        foreach (['READ', 'UPDATE'] as $action) {
            $roleId = (int) Db::getInstance()->getValue('SELECT id_authorization_role FROM ' . _DB_PREFIX_ . "authorization_role WHERE slug = 'ROLE_MOD_TAB_{$tab}_{$action}'");

            if ($roleId && !Db::getInstance()->getValue('SELECT 1 FROM ' . _DB_PREFIX_ . 'access WHERE id_profile = ' . $translatorProfile . ' AND id_authorization_role = ' . $roleId)) {
                Db::getInstance()->insert('access', ['id_profile' => $translatorProfile, 'id_authorization_role' => $roleId]);
            }
        }
    }
} else {
    $accounts[1][2] = (int) _PS_ADMIN_PROFILE_;
    $log('No "Translator" profile found; the editor account gets the SuperAdmin profile.');
}

$hashing = new PrestaShop\PrestaShop\Core\Crypto\Hashing();

foreach ($accounts as [$prefix, $fallback, $profile, $first, $last]) {
    $email    = trim((string) (getenv($prefix . '_EMAIL') ?: ($fallback ? getenv($fallback . '_EMAIL') : '')));
    $password = (string) (getenv($prefix . '_PASSWORD') ?: ($fallback ? getenv($fallback . '_PASSWORD') : ''));

    if ($email === '' || $password === '') {
        $log(sprintf('%s_EMAIL / %s_PASSWORD not set: no account created.', $prefix, $prefix));

        continue;
    }

    if (Employee::employeeExists($email)) {
        continue;
    }

    if (!Validate::isEmail($email)) {
        $log(sprintf('%s_EMAIL is not a valid email address: account skipped.', $prefix));

        continue;
    }

    if (!Validate::isAcceptablePasswordLength($password) || !Validate::isAcceptablePasswordScore($password)) {
        $log(sprintf('%s_PASSWORD does not meet PrestaShop\'s password rules (length and strength): account skipped.', $prefix));

        continue;
    }

    $employee                    = new Employee();
    $employee->firstname         = $first;
    $employee->lastname          = $last;
    $employee->email             = $email;
    $employee->passwd            = $hashing->hash($password);
    $employee->id_profile        = $profile;
    $employee->id_lang           = $english;
    $employee->active            = true;
    $employee->default_tab       = (int) Tab::getIdFromClassName('AdminProducts');
    $employee->bo_theme          = 'default';
    $employee->optin             = false;
    $employee->last_passwd_gen   = date('Y-m-d H:i:s', strtotime('-' . Configuration::get('PS_PASSWD_TIME_BACK') . 'minutes'));

    if ($employee->add()) {
        $log(sprintf('Account from %s_EMAIL created.', $prefix));
    } else {
        $log(sprintf('Creating the account from %s_EMAIL failed.', $prefix));
    }
}
unset($password);

// The installer's throwaway SuperAdmin goes once the demo admin exists: the demo's own
// installer account (@supertext-demo.invalid) and any --remove-employee=<email>.
$remove = ['%@supertext-demo.invalid'];

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--remove-employee=')) {
        $remove[] = substr($arg, \strlen('--remove-employee='));
    }
}

$demoAdmin = trim((string) (getenv('DEMO_ADMIN_EMAIL') ?: getenv('PRESTASHOP_ADMIN_EMAIL')));

if ($demoAdmin !== '' && Employee::employeeExists($demoAdmin)) {
    foreach ($remove as $pattern) {
        if (strcasecmp($pattern, $demoAdmin) === 0) {
            continue;
        }

        foreach (Db::getInstance()->executeS('SELECT id_employee FROM ' . _DB_PREFIX_ . "employee WHERE email LIKE '" . pSQL($pattern) . "'") ?: [] as $row) {
            (new Employee((int) $row['id_employee']))->delete();
            $log('Installer account removed.');
        }
    }
}

$log('Demo setup done.');
