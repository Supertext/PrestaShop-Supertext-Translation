# Developer guide — Supertext Translation for PrestaShop

How the module works, how to run it locally, how it's tested, deployed and released.

## Architecture

PrestaShop module `supertext` (folder `supertext/`, PHP 8.1+, PrestaShop 8.1+ / 9.x). It adds a page to the back office and hooks into three lists; translations are written into the items' own language fields (`*_lang` tables).

```
supertext/
├── supertext.php                         Module class: install, settings page (getContent), hooks
├── lib/supertext-sdk/                     bundled Supertext PHP SDK (namespace Supertext\PrestaShop\Sdk\V1; never edit)
├── controllers/front/ping.php            public ping endpoint of the Commando Center (module front controller)
├── config/routes.yml                     supertext_translate (page), supertext_translate_run (one item × language)
├── config/services.yml                   the controller and the console command
├── src/
│   ├── Api/                              no PrestaShop classes (unit-tested on their own)
│   │   ├── SupertextClient.php           wrapper around the Supertext SDK: errors → translatable templates
│   │   ├── CallableTransport.php         lets tests pass a fake HTTP callable
│   │   ├── LanguageCodes.php             locales → Supertext codes (the SDK's Languages + the language setting; no guessing)
│   │   ├── Reporting.php                 Commando Center: the SDK's InstallationReporter with the module's state
│   │   └── SupertextException.php
│   ├── Translation/
│   │   ├── EntityTypes.php               what is translated: product, category, cms + their fields
│   │   ├── FieldPlanner.php              which fields to send, what to write back (no PrestaShop classes)
│   │   └── EntityTranslator.php          loads the ObjectModel, translates, validates, saves
│   ├── Controller/Admin/TranslateController.php   the Translate with Supertext page + JSON endpoint
│   ├── Command/TranslateCommand.php      bin/console supertext:translate
│   └── Settings.php                      configuration values + SUPERTEXT_API_KEY / _API_ENDPOINT / _VERSION_REPORTS,
│                                         language codes per shop language, the Reporting glue (site URL, state)
└── views/templates/admin/
    ├── configure.tpl                     settings page (Smarty, legacy AdminModules controller)
    ├── translate.html.twig               translate page (Twig, Symfony controller) and its script
    └── product-extra.tpl                 the box in the product page's Modules tab
supertext/translations/<locale>/ModulesSupertextAdmin.<locale>.xlf   German, French, Italian UI strings
tools/sync-translations.php               writes the regional copies (de-CH, fr-CH, it-CH, …)
```

### Interface strings

Every user-visible string uses the domain `Modules.Supertext.Admin`: `$this->trans()` in `supertext.php`, `{l s='…' d='Modules.Supertext.Admin'}` in Smarty, `'…'|trans({}, d)` in Twig, `$this->t()` in the controller. English is the source; PrestaShop loads `translations/<locale>/ModulesSupertextAdmin.<locale>.xlf` for the employee's exact locale (no fallback from de-CH to de-DE). So:

- Edit `de-DE`, `fr-FR` and `it-IT` by hand (formal address: Sie, vous, Lei; PrestaShop's own terms, e.g. *Artikel*, *Catalogue → Produits*, *Apparence → Pages*; never translate "Supertext", `%placeholders%` or URLs), then run `php tools/sync-translations.php` to write de-AT, de-CH (ß → ss), fr-BE, fr-CA, fr-CH and it-CH.
- A new or changed string goes into all three files in the same commit. `tests/Unit/TranslationFilesTest.php` scans the module's PHP, Smarty and Twig files and fails if a string is missing, unused, has different placeholders, or a regional copy is out of date.
- `SupertextException` keeps its English template and `%placeholders%` (`template()`, `parameters()`) apart from Supertext's own detail (`detail()`), so the back office translates API errors; `src/Api` still has no PrestaShop classes. The CLI command prints English.

Entry points:

| Where | How |
| --- | --- |
| *Catalog → Products*, *Catalog → Categories*, *Design → Pages* | Hooks `actionProductGridDefinitionModifier`, `actionCategoryGridDefinitionModifier`, `actionCmsPageGridDefinitionModifier` add a `SubmitBulkAction` and a `LinkRowAction`, both to the route `supertext_translate`. The bulk form posts the grid's checkboxes (`product_bulk[]`, `category_id_category[]`, `cms_page_bulk[]`), which also tell the page the type (`EntityTypes::fromBulkSubmission`); the page redirects to its GET form `?type=…&ids=1,2`. |
| Product page, *Modules* tab | Hook `displayAdminProductsExtra` renders `product-extra.tpl` with a link to the page. PrestaShop only renders it for profiles with *view* permission on the module, so `install()` grants that to every profile (`grantViewToAllProfiles()`). |
| Command line | `supertext:translate <product|category|cms> <ids…> [--to=iso]… [--from=iso] [--overwrite]` |

The page (`translateAction`) shows the items and, per target language, a state from `FieldPlanner::state()`. Its script then calls `runAction` once per item and language, one after the other (Supertext rate-limits per second), with `type`, `id`, `source`, `target`, `overwrite`. The CSRF `_token` is in the route URL, as for every Symfony back-office route. Access: `runAction` checks `ROLE_MOD_TAB_<TAB>_UPDATE` for `AdminProducts`, `AdminCategories` or `AdminCmsContent`.

### What is translated

From `EntityTypes::TYPES` (keep `docs/USER_GUIDE.md` → *What is translated* in sync):

| Type | Class | Fields (rich text in bold) | Slug |
| --- | --- | --- | --- |
| `product` | `Product` | `name`, **`description_short`**, **`description`**, `meta_title`, `meta_description`, `available_now`, `available_later`, `delivery_in_stock`, `delivery_out_stock` | `link_rewrite` from `name` |
| `category` | `Category` | `name`, **`description`**, **`additional_description`**, `meta_title`, `meta_description` | `link_rewrite` from `name` |
| `cms` | `CMS` | `meta_title`, `head_seo_title`, `meta_description`, **`content`** | `link_rewrite` from `meta_title` |

Rules (`FieldPlanner`):

- A target field is **translated** when it has text that differs from the source (ignoring whitespace). PrestaShop copies the default language into a new language, so a copy counts as untranslated.
- Without `overwrite`, only untranslated fields are sent; with it, every field that has source text.
- Plain fields are cut to the ObjectModel's `size`; characters PrestaShop's validators refuse (`<>={}` and, in names, `;#`) are removed. The item is validated with `validateFieldsLang()` before `update()`.
- The slug is regenerated with `Tools::str2url()` from the translated title when the target slug is empty or still the source's, or with `overwrite`.

Each item × language is one Supertext document: one `<div data-st-id="N">` per field, a whole rich-text field in one element, so Supertext sees complete sentences with their inline formatting and links.

Multistore: the item is loaded and saved in the current shop context (the back office's shop selector, or the default shop on the command line).

## Supertext API protocol

AI file translation API v1, same as all Supertext plugins. Every call goes through the Supertext SDK (see *Supertext SDK*); this is what it does:

1. `POST {base}/translate/ai/file` — multipart: `file` (`content.html`, part `Content-Type: text/html` exactly, or the API answers 415), `target_lang` (e.g. `de-CH`), `source_lang` (bare language, e.g. `en`; see *Language codes*), optional `politeness` (`more` formal, `less` informal). Answers `{"file_id": "…"}`.
2. `GET {base}/translate/ai/file/{id}/status` every 2 s until `done` (or `error`, `limit_exceeded`, `deleted`), up to the timeout (default 180 s).
3. `GET {base}/translate/ai/file/{id}/translation` — the translated HTML.
4. `DELETE {base}/translate/ai/file/{id}` — always, also after errors.

- Base URLs: live `https://api.supertext.com/v1/`, staging `https://api.staging.supertext.com/v1/`, testing `https://api.testing.supertext.com/v1/`, or a custom one (setting, or `SUPERTEXT_API_ENDPOINT`).
- Header `Authorization: Supertext-Auth-Key <key>` (the name must be `Authorization`). A pasted `Supertext-Auth-Key ` prefix is stripped, so exactly one is sent.
- HTTP 429 (`RATE_LIMIT_EXCEEDED`, per second per key) is retried up to 4 times, after `Retry-After` or 1/2/4/8 s with jitter.
- The API key check (*Save*, *Test connection*, see *Commando Center*) calls `GET {base}/features` (free).
- Documents stay below 900,000 characters (API limit 1,000,000).

### Supertext SDK

The protocol, the retries, the language list, the key check, the version reports and the `data-st-id` document come from the official Supertext PHP SDK (0.2.1) ([Supertext/Supertext-API-SDK-and-Guide](https://github.com/Supertext/Supertext-API-SDK-and-Guide)). The module ships a copy in `supertext/lib/supertext-sdk/` with its namespace changed from `Supertext\Sdk` to `Supertext\PrestaShop\Sdk` (API classes in `…\Sdk\V1`, the HTTP transport in `…\Sdk\Http`; in the module's Composer class map, built by `./build.sh`), so shops install nothing extra and another module that ships the SDK itself can't clash with it. HTTP goes through the SDK's `CurlTransport` (ext-curl, which PrestaShop requires; proxy environment variables apply). `COMMIT` there names the SDK revision.

- Never edit `supertext/lib/supertext-sdk/`. Fix the SDK in its repository, then run `scripts/vendor-sdk.sh [ref]` (default `main`; `SDK_DIR=/path/to/clone` uses a local clone) and the tests.
- `src/Api/SupertextClient.php` keeps the module's interface (`translateDocument`, `validateApiKey`) and turns every SDK error code into a `SupertextException` with an English template (the arms of the `$message = match` block in `fromSdk()`, which `tests/Support/ModuleStrings.php` reads; `LanguageCodes` uses `fromSdk()` too, and `errorCode()` / `isAuthenticationFailure()` give the SDK's code). A new SDK error code needs an arm there and its translation in the three edited XLF files (then `php tools/sync-translations.php`).
- `FieldPlanner` builds and reads the document with the SDK's `HtmlDocument` and `Segment`.
- PHPStan analyses the module's own code; `tests/phpstan/bootstrap.php` autoloads the SDK copy.
- The live API answers an unknown language (`xx-YY`) with HTTP 404 `INVALID_LANGUAGE_PAIR`; the SDK reports it as `invalid_language_pair` with both codes, shown as "Supertext does not support this language pair (en → xx-YY)".

### Language codes

Supertext accepts exactly the codes its `GET features` lists (the SDK's `V1\Languages`, from the live API): bare sources (`en`, `de`); targets in one exact form each, regional for German, English, French, Italian and Portuguese (`de-CH`, `en-US`, `pt-BR`), with a script for Chinese and Serbian (`zh-Hans`, `sr-Latn`), a dialect for Swiss German (`gsw-u-sd-chzh`), bare for the others (`es`, `nl`, `sl`). Anything else is HTTP 404 `INVALID_LANGUAGE_PAIR`; formal/informal for a target without it (`en-US`, `rm`, …) is HTTP 400 `FEATURE_ERROR` (`unsupported_feature`).

`src/Api/LanguageCodes.php` (unit-tested, no PrestaShop classes) turns a shop language into Supertext codes **without guessing**, with one setting per language (`SUPERTEXT_LANGUAGES` → `code`, the *Supertext language* select of the settings page) as the SDK's mapping:

- **Target:** the setting (sent as it is, even a code the SDK's list doesn't know yet); else the locale when it equals a Supertext code apart from case and `_`/`-` (`de-CH`, `fr_ch` → `fr-CH`, `es`); else `SupertextException` with the SDK code `unsupported_language` (*The language %language% has no Supertext language yet*), and nothing is sent. No default regions: `de`, `es-ES`, `zh-CN` need the setting.
- **Source:** the source form of the setting (`de-CH` → `de`), else of the locale (`en-US`, `en_GB` → `en`).
- The locale is `locale`, then `language_code`, then `iso_code` (`Settings::localeOf()`): PrestaShop gives some languages country-like ISO codes (`gb` for en-GB, `br` for pt-BR, `mx` for es-MX).
- The settings page preselects `LanguageCodes::match()` for a language without a setting (saving stores it), keeps a saved code that isn't in the list as an extra option, marks unmapped languages *Not mapped* and warns when a tone is set for a target where `supportsPoliteness()` is false (the value is kept; Supertext would answer `unsupported_feature`). The translate page can't tick an unmapped language (`Settings::targetCodeOrNull()`).
- Translating within one language (source `en`, target `en-GB`) is allowed.

The stand-in (`tests/docs/stand-in.mjs`) accepts only Supertext's target codes and bare sources, like the live API.

### Commando Center: version reports, key check, ping

Protocol: [Commando-Center-Supertext-Translation](https://github.com/Supertext/Commando-Center-Supertext-Translation) → `docs/DEVELOPER.md` → *Report protocol*. The SDK's `InstallationReporter` implements it; `src/Api/Reporting.php` (no PrestaShop classes) loads the state, runs one action and stores the new state, and never throws (storage errors included). `Settings::reporting()` gives it what PrestaShop knows:

- **Setting:** `SUPERTEXT_VERSION_REPORTS` (configuration, `1`/`0`; missing = on; `install()` writes `1`), checkbox on the settings page. The environment variable of the same name wins (`InstallationReporter::reportsEnabled()`); `Reporting::forcedByEnvironment()` tells the page to say so. Off: no reports, ping 404. `SUPERTEXT_REPORT_URL` sends reports elsewhere (CI, screenshots: the stand-in's `POST /report`).
- **Report fields:** plugin `PrestaShop-Supertext-Translation` (the GitHub repository name) with the version from `supertext.php` (`Module::getInstanceByName('supertext')->version`), system `PrestaShop` with `_PS_VERSION_`, runtime PHP; `site_url` = the default shop's `domain_ssl` (with `PS_SSL_ENABLED`) or `domain` plus its base URI; `callback.url` = `<site>index.php?fc=module&module=supertext&controller=ping` (works with and without friendly URLs).
- **When:** `afterSettingsSaved` after every successful save of the settings form (its key check is shown as a notice); `testConnection` for *Test connection*; `onRequest` on the settings page, the *Translate with Supertext* page and every `runAction`, through `Settings::reportAfterResponse()` (a shutdown function that first calls `fastcgi_finish_request()` where available, so under PHP-FPM it runs after the response; under mod_php at the end of the request; `onRequest` only makes a request when the key changed or a report is due); `authenticationFailed` when a translation gets 401/403 (in `runAction` and in the command); `supertext:translate` runs `onRequest` itself before translating. Never on storefront pages.
- **State:** the reporter's JSON (reported versions, last key status, last failure, the latest key check with the key's SHA-256 fingerprint; never the key) in the configuration value `SUPERTEXT_REPORTER_STATE` (global). Both values are in `Settings::ALL`, so uninstalling removes them.
- **Ping:** `controllers/front/ping.php` (`SupertextPingModuleFrontController`) answers in `init()`, before `FrontController::init()` would start the cookie, cart, maintenance page or canonical redirects, with exactly `Reporting::ping()`'s status, headers (`Content-Type: application/json`, `Cache-Control: no-store`) and body; other methods than GET get 405.

## Local development

With Docker (PrestaShop's official image and MariaDB):

```bash
docker network create ps
docker run -d --name ps-db --network ps -e MARIADB_ROOT_PASSWORD=admin -e MARIADB_DATABASE=prestashop mariadb:11
docker run -d --name ps --network ps -p 8080:80 \
  -e DB_SERVER=ps-db -e DB_PASSWD=admin -e PS_INSTALL_AUTO=1 -e PS_DOMAIN=localhost:8080 \
  -e PS_COUNTRY=CH -e PS_FOLDER_ADMIN=admin-dev -e ADMIN_MAIL=admin@example.com -e ADMIN_PASSWD='Admin-Local-2026!' \
  -v "$PWD/supertext:/var/www/html/modules/supertext" \
  prestashop/prestashop:9
# The 9.x image may stop once after installing (it removes an already removed install folder):
docker start ps
composer dump-autoload --working-dir=supertext     # the module's class autoloader (supertext/vendor/)
docker exec ps php bin/console prestashop:module install supertext
```

Back office: http://localhost:8080/admin-dev/. With the module mounted, PHP edits are live; after changing `config/*.yml`, routes or hooks clear the cache (`php bin/console cache:clear`). The demo's setup adds the languages, sample content and accounts:

```bash
docker cp demo ps:/opt/demo
docker exec -e DEMO_ADMIN_EMAIL=… -e DEMO_ADMIN_PASSWORD=… -e DEMO_EDITOR_EMAIL=… -e DEMO_EDITOR_PASSWORD=… \
  -e PS_FOLDER_ADMIN=admin-dev ps runuser -u www-data --preserve-environment -- php /opt/demo/setup.php
```

To translate without a Supertext key, run the stand-in API (`node tests/docs/stand-in.mjs`, any key) and point the module at it with `SUPERTEXT_API_ENDPOINT=http://127.0.0.1:8765/v1/` in the container's environment (with `--network host`, or the host's address). Also set `SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report`, so version reports go to the stand-in (`GET /reports` lists them) instead of the live Commando Center.

## Tests

```bash
composer install
composer test        # PHPUnit: SDK wrapper, language codes, reporting, field planner, entity types, translation files (no PrestaShop needed)
./build.sh           # dist/supertext-<version>.zip
```

CI (`.github/workflows/ci.yml`):

- **unit** (PHP 8.1, 8.3, 8.4): lint, PHPUnit, `sh -n demo/entrypoint.sh`, `./build.sh`.
- **phpstan**: PHPStan on the module's code with the PrestaShop 9 sources (see *Code quality and security checks*).
- **prestashop**: installs PrestaShop 9 in Docker, installs the built zip, runs `demo/setup.php` twice (the second run must change nothing, and no password may appear in the log), translates the sample product, category and page with `supertext:translate` against the stand-in API and checks the German and French rows, slug included; a second run must skip everything. Then it checks the Commando Center: exactly one `installed` report (plugin, system, key check `ok`, callback URL; never the key) reached the stand-in's fake center, and the public ping answers the challenge with `Cache-Control: no-store` (400 for a bad challenge).

Unit tests worth knowing: `LanguageCodesTest` (exact matches, no default regions, the setting wins, sources, `unsupported_language`), `ReportingTest` (the report and the stored state never contain the key, `installed` → `settings_saved`, `upgraded` only after a version change, a 401 reported once without a request to Supertext, nothing sent with reports off, storage errors swallowed, ping answers, `SUPERTEXT_VERSION_REPORTS`), `SupertextClientTest` (codes sent as given, `unsupported_feature`, a rejected key).

## Demo (Railway)

`demo/` is a PrestaShop 9 shop with English sample content, German (Switzerland), French (Switzerland) and Italian (Switzerland), and this module. The Railway service *PrestaShop* (project *supertext-cms-demos-php*, region ams, https://prestashop-production-778d.up.railway.app/, back office `/admin-dev/`) builds `demo/Dockerfile` with the repository root as context, from `main`. Railway no longer reads `railway.json` (config as code is deprecated), so the Dockerfile path, healthcheck (`/health`, a static file, because PrestaShop's pages redirect to the HTTPS domain; 900 s) and restart policy are set on the service itself; `railway.json` documents the same values.

Every push to `main` deploys the demo (Railway's GitHub app watches the repository). If a push doesn't deploy, check Railway's access under https://github.com/organizations/Supertext/settings/installations and then reconnect the service's source once, so Railway re-creates its push trigger.

The container keeps **no files** between deploys (Railway allows only a few volumes per project):

- The shop is in MySQL: `DATABASE_URL` (`mysql://…`, the shared Railway MySQL service) and its own database `PRESTASHOP_DB_NAME` (default `prestashop`), created if missing.
- `app/config/parameters.php` (database access and PrestaShop's secret keys) is stored in that database (`supertext_demo_state`, `demo/state.php`) and restored on every start.
- On the **first start** (empty database) `demo/entrypoint.sh` runs PrestaShop's installer with a throwaway SuperAdmin (random `@supertext-demo.invalid` address and password, never shown). PrestaShop's install screen is never shown: the `DEMO_*` accounts replace it, and `demo/setup.php` deletes the installer account as soon as the `DEMO_ADMIN` account exists.
- On **every start**, the entrypoint copies the module from the image into `modules/`, points the shop at the current domain (`PRESTASHOP_DOMAIN`, else `RAILWAY_PUBLIC_DOMAIN`; HTTPS except for localhost), and runs `demo/setup.php`. It installs the module if needed, adds the languages and sample content (`demo/sample-content.json`) if missing, downloads missing language packs and flags again (they are files), and creates missing accounts. It never changes existing content, translations or accounts.
- Product images and other uploads are lost on redeploy (the sample products have none).

Variables (Railway service variables; template in `demo/.env.example`):

| Variable | Purpose |
| --- | --- |
| `DATABASE_URL` | MySQL server, `mysql://user:password@host:port/any`. On Railway: `mysql://root:${{MySQL-8.MYSQL_ROOT_PASSWORD}}@${{MySQL-8.RAILWAY_PRIVATE_DOMAIN}}:3306/mysql` (the shared MySQL 8.4 service; root is needed to create the database) |
| `PRESTASHOP_DB_NAME` | The demo's database on it (default `prestashop`) |
| `PRESTASHOP_SHOP_NAME` | Shop name at installation (default *Supertext Chocolate Demo*) |
| `PRESTASHOP_ADMIN_FOLDER` | Back-office path (default `admin-dev`) |
| `PRESTASHOP_DOMAIN` | Public host name; default `RAILWAY_PUBLIC_DOMAIN`. (Not `PS_DOMAIN`: the base image sets that to a placeholder.) |
| `SUPERTEXT_API_KEY` | Supertext API key. No Supertext account yet? Create one at https://www.supertext.com/person/en/account/signin. Generate your API key at https://www.supertext.com/en/integrations/api (requires the Admin role). |
| `SUPERTEXT_API_ENDPOINT` | Optional: another API, e.g. the stand-in |
| `SUPERTEXT_VERSION_REPORTS` | Optional: `0` turns *Send version information to Supertext* off (wins over the setting) |
| `SUPERTEXT_REPORT_URL` | Tests only: where version reports go (the stand-in's `http://127.0.0.1:8765/report`) |
| `DEMO_ADMIN_EMAIL`, `DEMO_ADMIN_PASSWORD` | SuperAdmin for Supertext staff. `PRESTASHOP_ADMIN_EMAIL` / `PRESTASHOP_ADMIN_PASSWORD` are read as fallbacks. |
| `DEMO_EDITOR_EMAIL`, `DEMO_EDITOR_PASSWORD` | Editor for tests and screenshots: PrestaShop's **Translator** profile (products and categories). The demo also gives that profile *Design → Pages* (view and edit), so it can translate CMS pages, and view on the module, so it sees the product page button. |

- Accounts are created only if their email doesn't exist yet; existing accounts are never modified (no password resets).
- A password that fails PrestaShop's password rules (8–72 characters and strength score 3, as in PrestaShop's own forms) skips that account with a warning in the log; the demo still starts. The log names variables, never passwords.

Build and run it locally:

```bash
docker build -f demo/Dockerfile -t supertext-prestashop-demo .
docker run --rm -p 8090:80 -e PRESTASHOP_DOMAIN=localhost:8090 \
  -e DATABASE_URL=mysql://root:admin@host.docker.internal:3306/mysql \
  -e DEMO_ADMIN_EMAIL=… -e DEMO_ADMIN_PASSWORD=… -e DEMO_EDITOR_EMAIL=… -e DEMO_EDITOR_PASSWORD=… \
  supertext-prestashop-demo
# shop http://localhost:8090/  back office http://localhost:8090/admin-dev/
```

The first start takes a few minutes (PrestaShop's installer).

## Docs screenshots

`tests/docs/screenshots.mjs` regenerates `docs/images/` with Playwright from a freshly started demo whose module talks to `tests/docs/stand-in.mjs`. The stand-in answers like the Supertext API and returns real German, French and Italian for the sample content (`tests/docs/samples.json`); the settings screenshots show the live API, because the endpoint comes from `SUPERTEXT_API_ENDPOINT` and the script hides the note about it.

```bash
cd tests/docs && npm install && npx playwright install chromium
node stand-in.mjs &
# a fresh demo (empty database) running with SUPERTEXT_API_ENDPOINT=http://127.0.0.1:8765/v1/,
# SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report (version reports go to the stand-in),
# no SUPERTEXT_API_KEY (the script enters a key in the settings) and DEMO_* set, on port 8090:
BASE_URL=http://localhost:8090/admin-dev DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… \
  DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… node screenshots.mjs
```

Run it in the same commit as any UI change the images show.

## Code quality and security checks

Before starting work in this repo, look at its open findings: code scanning alerts, secret scanning alerts, Dependabot PRs and the "Broken links in the docs" issue.

- **Checks** (`.github/workflows/checks.yml`): actionlint and zizmor lint the workflows on every push and pull request; dependency review fails a pull request that adds a package with a known vulnerability (moderate or worse). Third-party actions are pinned to commit SHAs (Dependabot keeps them current).
- **Links** (`.github/workflows/links.yml`): lychee checks the links in all Markdown files weekly and when docs change on `main`. Broken links open (or update) the issue "Broken links in the docs"; links that can't work from CI go in `.lycheeignore` (one regex per line).
- **PHPStan** (job **phpstan** in `ci.yml`, configuration `phpstan.neon`): level 5 on `supertext/supertext.php` and `supertext/src/` (not `demo/` or the tests). PHPStan has to know PrestaShop's classes, so `tests/phpstan/bootstrap.php` loads the autoloaders of a PrestaShop 9 installation given in `PS_ROOT_DIR`; CI copies the sources out of the `prestashop/prestashop:9` image. Locally:

  ```bash
  docker create --name ps prestashop/prestashop:9
  mkdir -p /tmp/prestashop && docker export ps | tar -x -C /tmp/prestashop --strip-components=3 \
    var/www/html/autoload.php var/www/html/app var/www/html/classes var/www/html/config \
    var/www/html/controllers var/www/html/src var/www/html/vendor
  docker rm ps
  composer install
  PS_ROOT_DIR=/tmp/prestashop vendor/bin/phpstan analyse --memory-limit=1G
  ```

  Known findings that aren't fixed yet go in `phpstan-baseline.neon` (`vendor/bin/phpstan analyse --generate-baseline`); it is empty now, so keep it that way and fix new findings instead.
- **GitHub settings** (set by Remy's setup script, not in the repo): secret scanning with push protection (a push containing a known token format is rejected; findings under *Security → Secret scanning*) and CodeQL default setup (findings under *Security → Code scanning* and as pull request comments). CodeQL doesn't cover PHP, which is why this repo runs PHPStan.

## Releasing

Releases are published by `.github/workflows/release.yml` when the version is officially bumped; nobody tags or creates releases by hand.

1. Check that `composer test`, PHPStan and `./build.sh` pass.
2. Move the *Unreleased* entries in `CHANGELOG.md` under a new `## X.Y.Z — YYYY-MM-DD` section, and keep an empty *Unreleased* above it.
3. Set the same version in `supertext/supertext.php` (`$this->version`). PrestaShop shows it in the Module Manager, and the module's settings page links it to the GitHub release.
4. Push to `main`. The workflow checks that the version file matches `CHANGELOG.md`, then tags `vX.Y.Z` and creates the GitHub release with the CHANGELOG section as notes (0.x versions as pre-releases). A push that adds no new version does nothing, and a version that is already released is skipped. After fixing a failed run, start it again with *Run workflow* on the *Release* workflow.

The release attaches `supertext-X.Y.Z.zip`, built by `./build.sh` (module folder, class autoloader, `index.php` in every folder).

## Conventions

- PrestaShop module structure: main class in `supertext.php`, PSR-4 classes in `src/` (`Supertext\PrestaShop\…`), Symfony routes and services in `config/`.
- Keep `src/Api` and `FieldPlanner` free of PrestaShop classes so they stay unit-testable.
- User-visible strings use the domain `Modules.Supertext.Admin` (new translation system), with German, French and Italian in `supertext/translations/` (see *Interface strings*); the API client's messages are English templates that the back office translates.
- Keep the three docs in `docs/` current with every change (see `CLAUDE.md`).

## Known limitations / roadmap

- Not translated yet: product tags, image captions (`image_lang.legend`), feature and attribute values, attachments, customization field labels, brands and suppliers.
- No *Translate with Supertext* button on the category and CMS page edit forms (use the list's row action).
- The *Supertext language* list is the SDK's copy of Supertext's `features` list (October 2026) with English language names; a language Supertext adds later appears with the next SDK update (a code saved before is kept and sent as it is).
- Under mod_php (no `fastcgi_finish_request()`), the automatic key check and a due version report run at the end of the request instead of after the response; they only make a request when the key changed or a report is due (at most 10 s for the check, 3 s for the report).
- Translation runs in the browser, one item and language at a time; very large selections (more than 100 items) are cut to 100. A background queue would allow more.
- Professional (human) translation orders, as in the WordPress plugin, are not offered.
