# Developer guide — Supertext Translation for PrestaShop

How the module works, how to run it locally, how it's tested, deployed and released.

## Architecture

PrestaShop module `supertext` (folder `supertext/`, PHP 8.1+, PrestaShop 8.1+ / 9.x). It adds a page to the back office and hooks into three lists; translations are written into the items' own language fields (`*_lang` tables).

```
supertext/
├── supertext.php                         Module class: install, settings page (getContent), hooks
├── config/routes.yml                     supertext_translate (page), supertext_translate_run (one item × language)
├── config/services.yml                   the controller and the console command
├── src/
│   ├── Api/                              no PrestaShop classes (unit-tested on their own)
│   │   ├── SupertextClient.php           Supertext AI file translation API v1
│   │   ├── HtmlDocument.php              fields ⇄ one HTML document with data-st-id elements
│   │   ├── CurlTransport.php             HTTP via cURL (or streams), honours HTTPS_PROXY
│   │   └── SupertextException.php
│   ├── Translation/
│   │   ├── EntityTypes.php               what is translated: product, category, cms + their fields
│   │   ├── FieldPlanner.php              which fields to send, what to write back (no PrestaShop classes)
│   │   └── EntityTranslator.php          loads the ObjectModel, translates, validates, saves
│   ├── Controller/Admin/TranslateController.php   the Translate with Supertext page + JSON endpoint
│   ├── Command/TranslateCommand.php      bin/console supertext:translate
│   └── Settings.php                      configuration values + SUPERTEXT_API_KEY / SUPERTEXT_API_ENDPOINT
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

AI file translation API v1, same as the WordPress, Joomla and other Supertext plugins (`src/Api/SupertextClient.php`):

1. `POST {base}/translate/ai/file` — multipart: `file` (`content.html`, part `Content-Type: text/html` exactly, or the API answers 415), `target_lang` (e.g. `de-CH`), `source_lang` (primary subtag only, e.g. `en`; a full tag is rejected with `INVALID_LANGUAGE_PAIR`), optional `politeness` (`more` formal, `less` informal). Answers `{"file_id": "…"}`.
2. `GET {base}/translate/ai/file/{id}/status` every 2 s until `done` (or `error`, `limit_exceeded`, `deleted`), up to the timeout (default 180 s).
3. `GET {base}/translate/ai/file/{id}/translation` — the translated HTML.
4. `DELETE {base}/translate/ai/file/{id}` — always, also after errors.

- Base URLs: live `https://api.supertext.com/v1/`, staging `https://api.staging.supertext.com/v1/`, testing `https://api.testing.supertext.com/v1/`, or a custom one (setting, or `SUPERTEXT_API_ENDPOINT`).
- Header `Authorization: Supertext-Auth-Key <key>` (the name must be `Authorization`). A pasted `Supertext-Auth-Key ` prefix is stripped, so exactly one is sent.
- HTTP 429 (`RATE_LIMIT_EXCEEDED`, per second per key) is retried up to 4 times, after `Retry-After` or 1/2/4/8 s with jitter.
- *Test connection* calls `GET {base}/features` (free).
- Documents stay below 900,000 characters (API limit 1,000,000).

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

To translate without a Supertext key, run the stand-in API (`node tests/docs/stand-in.mjs`, any key) and point the module at it with `SUPERTEXT_API_ENDPOINT=http://127.0.0.1:8765/v1/` in the container's environment (with `--network host`, or the host's address).

## Tests

```bash
composer install
composer test        # PHPUnit: API client, HTML document, field planner, entity types, translation files (no PrestaShop needed)
./build.sh           # dist/supertext-<version>.zip
```

CI (`.github/workflows/ci.yml`):

- **unit** (PHP 8.1, 8.3, 8.4): lint, PHPUnit, `sh -n demo/entrypoint.sh`, `./build.sh`.
- **phpstan**: PHPStan on the module's code with the PrestaShop 9 sources (see *Code quality and security checks*).
- **prestashop**: installs PrestaShop 9 in Docker, installs the built zip, runs `demo/setup.php` twice (the second run must change nothing, and no password may appear in the log), translates the sample product, category and page with `supertext:translate` against the stand-in API and checks the German and French rows, slug included; a second run must skip everything.

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
- Back-office texts of the module are English only; German is planned.
- Translation runs in the browser, one item and language at a time; very large selections (more than 100 items) are cut to 100. A background queue would allow more.
- Professional (human) translation orders, as in the WordPress plugin, are not offered.
