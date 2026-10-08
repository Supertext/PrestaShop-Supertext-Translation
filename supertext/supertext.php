<?php

/**
 * Supertext Translation for PrestaShop: translates products, categories and CMS pages
 * into the shop's other languages with Supertext AI.
 *
 * @package     Supertext Translation for PrestaShop
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use PrestaShop\PrestaShop\Adapter\SymfonyContainer;
use PrestaShop\PrestaShop\Core\Grid\Action\Bulk\Type\SubmitBulkAction;
use PrestaShop\PrestaShop\Core\Grid\Action\Row\Type\LinkRowAction;
use PrestaShop\PrestaShop\Core\Grid\Definition\GridDefinitionInterface;
use Supertext\PrestaShop\Api\SupertextException;
use Supertext\PrestaShop\Settings;

class Supertext extends Module
{
    public const REPOSITORY = 'https://github.com/Supertext/PrestaShop-Supertext-Translation';

    private const HOOKS = [
        'actionProductGridDefinitionModifier',
        'actionCategoryGridDefinitionModifier',
        'actionCmsPageGridDefinitionModifier',
        'displayAdminProductsExtra',
    ];

    public function __construct()
    {
        $this->name                   = 'supertext';
        $this->tab                    = 'i18n_localization';
        $this->version                = '0.1.0';
        $this->author                 = 'Supertext';
        $this->need_instance          = 0;
        $this->bootstrap              = true;
        $this->ps_versions_compliancy = ['min' => '8.1.0', 'max' => '9.99.99'];

        parent::__construct();

        $this->displayName = $this->trans('Supertext Translation', [], 'Modules.Supertext.Admin');
        $this->description = $this->trans('Translate products, categories and CMS pages into your other shop languages with Supertext AI.', [], 'Modules.Supertext.Admin');
        $this->confirmUninstall = $this->trans('Remove the Supertext settings? Translations made so far stay in your shop.', [], 'Modules.Supertext.Admin');
    }

    public function isUsingNewTranslationSystem(): bool
    {
        return true;
    }

    public function install(): bool
    {
        return parent::install()
            && $this->registerHook(self::HOOKS)
            && $this->grantViewToAllProfiles()
            && Configuration::updateGlobalValue(Settings::ENVIRONMENT, 'live')
            && Configuration::updateGlobalValue(Settings::TIMEOUT, 180);
    }

    /**
     * PrestaShop shows a module's back-office output (the button in the product page's
     * Modules tab) only to profiles with "view" permission on the module, which a new module
     * gets for SuperAdmin only. Let every profile see it; who can translate is still decided
     * by the edit permission on products, categories and pages. Configuring stays SuperAdmin.
     */
    public function grantViewToAllProfiles(): bool
    {
        $roleId = (int) Db::getInstance()->getValue('SELECT id_authorization_role FROM ' . _DB_PREFIX_
            . "authorization_role WHERE slug = 'ROLE_MOD_MODULE_" . pSQL(strtoupper($this->name)) . "_READ'");

        if (!$roleId) {
            return true;
        }

        foreach (Profile::getProfiles((int) Configuration::get('PS_LANG_DEFAULT')) as $profile) {
            Db::getInstance()->execute('INSERT IGNORE INTO ' . _DB_PREFIX_ . 'module_access (id_profile, id_authorization_role) VALUES ('
                . (int) $profile['id_profile'] . ', ' . $roleId . ')');
        }

        return true;
    }

    public function uninstall(): bool
    {
        foreach (Settings::ALL as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    /* ------------------------------------------------------------------ settings page */

    public function getContent(): string
    {
        $messages = '';

        if (Tools::isSubmit('submitSupertextSettings')) {
            $messages .= $this->saveSettings();
        } elseif (Tools::isSubmit('submitSupertextTest')) {
            $messages .= $this->testConnection();
        }

        $languages = [];
        $saved     = Settings::languages();

        foreach (Language::getLanguages(false) as $language) {
            $id          = (int) $language['id_lang'];
            $languages[] = [
                'id'          => $id,
                'name'        => $language['name'],
                'locale'      => Settings::formatTag((string) ($language['locale'] ?: $language['language_code'])),
                'code'        => $saved[$id]['code'] ?? '',
                'tone'        => $saved[$id]['tone'] ?? 'default',
                'isDefault'   => $id === (int) Configuration::get('PS_LANG_DEFAULT'),
                'active'      => (bool) $language['active'],
            ];
        }

        $key = (string) Configuration::getGlobalValue(Settings::API_KEY);

        $this->context->smarty->assign([
            'st'           => [
                'formUrl'        => $this->context->link->getAdminLink('AdminModules', true, [], ['configure' => $this->name]),
                'hasKey'         => $key !== '',
                'keyHint'        => $key !== '' ? '…' . substr(Settings::apiKey(), -4) : '',
                'keyFromEnv'     => Settings::apiKeyFromEnvironment(),
                'endpointFromEnv' => Settings::endpointFromEnvironment(),
                'environment'    => Settings::environment(),
                'endpoint'       => Settings::customEndpoint(),
                'timeout'        => Settings::timeout(),
                'languages'      => $languages,
                'languagesUrl'   => $this->context->link->getAdminLink('AdminLanguages'),
                'signupUrl'      => Settings::SIGNUP_URL,
                'apiKeyUrl'      => Settings::API_KEY_URL,
                'version'        => $this->version,
                'releaseUrl'     => preg_match('/^\d+\.\d+\.\d+$/', $this->version) ? self::REPOSITORY . '/releases/tag/v' . $this->version : '',
                'docsUrl'        => self::REPOSITORY . '/blob/main/docs/USER_GUIDE.md',
                'productsUrl'    => $this->context->link->getAdminLink('AdminProducts'),
            ],
        ]);

        return $messages . $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }

    private function saveSettings(): string
    {
        $key = trim((string) Tools::getValue('api_key'));

        if (Tools::getValue('remove_api_key')) {
            Configuration::updateGlobalValue(Settings::API_KEY, '');
        } elseif ($key !== '') {
            Configuration::updateGlobalValue(Settings::API_KEY, \Supertext\PrestaShop\Api\SupertextClient::normalizeKey($key));
        }

        $environment = (string) Tools::getValue('environment');
        Configuration::updateGlobalValue(Settings::ENVIRONMENT, in_array($environment, ['live', 'staging', 'testing'], true) ? $environment : 'live');

        $endpoint = trim((string) Tools::getValue('endpoint'));

        if ($endpoint !== '' && !preg_match('#^https?://\S+$#i', $endpoint)) {
            return $this->displayError($this->trans('The custom API base URL must start with https://.', [], 'Modules.Supertext.Admin'));
        }

        Configuration::updateGlobalValue(Settings::ENDPOINT, $endpoint);
        Configuration::updateGlobalValue(Settings::TIMEOUT, max(30, min(1800, (int) Tools::getValue('timeout', 180))));

        $languages = [];

        foreach ((array) Tools::getValue('languages', []) as $id => $row) {
            $code = trim((string) ($row['code'] ?? ''));
            $tone = (string) ($row['tone'] ?? 'default');

            if ($code !== '' && !preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $code)) {
                return $this->displayError($this->trans('"%code%" is not a valid language code. Use a code such as de-CH or fr.', ['%code%' => $code], 'Modules.Supertext.Admin'));
            }

            if ($code !== '' || $tone !== 'default') {
                $languages[(int) $id] = ['code' => $code, 'tone' => in_array($tone, Settings::TONES, true) ? $tone : 'default'];
            }
        }

        Settings::saveLanguages($languages);

        return $this->displayConfirmation($this->trans('Settings saved.', [], 'Modules.Supertext.Admin'));
    }

    private function testConnection(): string
    {
        if (Settings::apiKey() === '') {
            return $this->displayError($this->noKeyMessage());
        }

        try {
            Settings::client()->validateApiKey();
        } catch (SupertextException $e) {
            return $this->displayError(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
        }

        return $this->displayConfirmation($this->trans('Connected. The API key works.', [], 'Modules.Supertext.Admin'));
    }

    private function noKeyMessage(): string
    {
        return $this->trans('No API key is saved yet.', [], 'Modules.Supertext.Admin') . ' '
            . $this->trans('No Supertext account yet? Create one at %signup%. Generate your API key at %apikey% (requires the Admin role).', [
                '%signup%' => '<a href="' . Settings::SIGNUP_URL . '" target="_blank" rel="noopener">supertext.com</a>',
                '%apikey%' => '<a href="' . Settings::API_KEY_URL . '" target="_blank" rel="noopener">supertext.com → Integrations → API</a>',
            ], 'Modules.Supertext.Admin');
    }

    /* ------------------------------------------------------------------ back office hooks */

    public function hookActionProductGridDefinitionModifier(array $params): void
    {
        $this->addGridActions($params['definition'], 'product', 'id_product');
    }

    public function hookActionCategoryGridDefinitionModifier(array $params): void
    {
        $this->addGridActions($params['definition'], 'category', 'id_category');
    }

    public function hookActionCmsPageGridDefinitionModifier(array $params): void
    {
        $this->addGridActions($params['definition'], 'cms', 'id_cms');
    }

    /** Product page (Modules tab): a button that opens the translate page for this product. */
    public function hookDisplayAdminProductsExtra(array $params): string
    {
        $id = (int) ($params['id_product'] ?? Tools::getValue('id_product'));

        if ($id <= 0) {
            return '';
        }

        $this->context->smarty->assign('st', [
            'url'       => $this->route('supertext_translate', ['type' => 'product', 'ids' => (string) $id]),
            'hasApiKey' => Settings::apiKey() !== '',
        ]);

        return $this->display(__FILE__, 'views/templates/admin/product-extra.tpl');
    }

    /** "Translate with Supertext" as a bulk action and as a row action of a list. */
    private function addGridActions(GridDefinitionInterface $definition, string $type, string $idField): void
    {
        $label = $this->trans('Translate with Supertext', [], 'Modules.Supertext.Admin');

        $definition->getBulkActions()->add(
            (new SubmitBulkAction('supertext_translate'))
                ->setName($label)
                ->setOptions(['submit_route' => 'supertext_translate'])
        );

        foreach ($definition->getColumns() as $column) {
            if ($column->getId() !== 'actions') {
                continue;
            }

            $actions = $column->getOptions()['actions'] ?? null;

            if (is_object($actions) && method_exists($actions, 'add')) {
                $actions->add(
                    (new LinkRowAction('supertext_translate'))
                        ->setName($label)
                        ->setIcon('translate')
                        ->setOptions([
                            'route'              => 'supertext_translate',
                            'route_param_name'   => 'ids',
                            'route_param_field'  => $idField,
                            'extra_route_params' => ['type' => $type],
                        ])
                );
            }
        }
    }

    /** @param array<string, string> $parameters */
    private function route(string $name, array $parameters = []): string
    {
        return SymfonyContainer::getInstance()->get('router')->generate($name, $parameters);
    }
}
