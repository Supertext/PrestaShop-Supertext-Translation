# Working on this repository

Part of Supertext's translation plugins project: Supertext AI translation for the top open source CMS, PIM and shop systems. Each system has its own repo named `Supertext/<System>-Supertext-Translation`. This one is the **PrestaShop** module (PHP, module `supertext` in `supertext/`).

## Documentation rule (always)

Every plugin repo keeps three guides, and **every change that affects behaviour, settings, installation or the code structure updates them in the same commit**:

| File | Audience | Must cover |
| --- | --- | --- |
| `docs/INSTALLATION.md` | Administrators | Requirements, install/update/uninstall, API key, language setup, all settings, troubleshooting |
| `docs/USER_GUIDE.md` | Editors | How to translate and review in the CMS's own UI, what is and isn't translated, what errors mean |
| `docs/DEVELOPER.md` | Developers | Architecture, Supertext API protocol, local setup, tests, CI/deploy, releasing, known limitations/roadmap |

Also: `README.md` stays a short overview linking the three guides, and `CHANGELOG.md` gets an entry under *Unreleased* for every user-visible change. Before finishing any task, check the docs still match the code.

## Supertext account and API key links (always)

Everywhere an administrator enters or is told about the API key — the settings field's help text, the "no API key" / "authentication failed" messages, `docs/INSTALLATION.md`, `README.md` and the demo's `.env.example` — show both links (same as the WordPress plugin):

- Create a Supertext account (or log in): https://www.supertext.com/person/en/account/signin
- Generate the AI API key: https://www.supertext.com/en/integrations/api (supertext.com → Integrations → API; requires the **Admin** role)

Wording: "No Supertext account yet? Create one at supertext.com. Generate your API key at supertext.com → Integrations → API (requires the Admin role)." In the UI, links open in a new tab (`target="_blank" rel="noopener"`); where the CMS shows plain text only, use the bare URLs. New screens or messages that mention the key get the links too.

## Plugin list (always)

`README.md` ends with the shared list of all Supertext plugins (between the `<!-- supertext-plugins:start -->` and `<!-- supertext-plugins:end -->` markers). It is identical in every Supertext plugin repo: when a plugin is added, renamed or its description changes, update the list in **all** repos, not just this one.

## Releases (always)

Releases are published by `.github/workflows/release.yml`: never tag or create a GitHub release by hand. To release, follow `docs/DEVELOPER.md` → *Releasing* (new version section in `CHANGELOG.md`, same number in `supertext/supertext.php`) and push to `main`. A push without a new version releases nothing. The settings page shows the version from `supertext.php` (never a second copy) and links it to the release.

## Demo accounts rule (always)

Every demo must be usable right after deployment, without anyone registering in a browser. On **every start**, the demo creates these accounts if they don't exist yet:

| Variables | Account |
| --- | --- |
| `DEMO_ADMIN_EMAIL`, `DEMO_ADMIN_PASSWORD` | Full administrator (for Supertext staff) |
| `DEMO_EDITOR_EMAIL`, `DEMO_EDITOR_PASSWORD` | Editor-level account that can translate content in every demo language; used for automated tests and screenshots. Where the CMS has no editor role that works out of the box, use the closest role and document it. |

- Existing accounts are never modified: no password resets from variables, no duplicates on restart.
- A password that doesn't meet the CMS's own password rules skips that account with a clear warning in the log. The demo still starts.
- Values live only in the hosting platform's variables (Railway). Never in the repo, in chat or in logs. Log the variable name, never the password.
- If the CMS has a first-run "create admin" screen, these accounts replace it. Document that once `DEMO_*` is set, the screen no longer appears.
- If a demo already used CMS-specific names (e.g. `TYPO3_ADMIN_*`, `PAYLOAD_ADMIN_*`), keep them as fallbacks for `DEMO_ADMIN_*`.
- The demo also seeds its target languages and at least one sample entry in the source language, and makes sure the editor account can access every target language.
- Document the variables in `docs/DEVELOPER.md` (demo section) and in the demo's `.env.example`.

## Screenshots rule (always)

The user guide and installation guide of every plugin include screenshots of the real UI: at least the translate action before and after translating, a translated result, the overwrite or retranslate warning if there is one, the plugin's settings or configuration screen, and the CMS's language setup. Screenshots are taken from the repo's own demo with the headless browser, by a committed script (e.g. `npm run docs:screenshots`), against a stand-in API that returns real translations for the sample content, so the guides never show placeholder text. Use no real customer data, no secrets, no local URLs (show the live API endpoint). Keep the images small (1× scale, cropped to the relevant part), store them in `docs/images/`, give each one descriptive alt text, and regenerate them in the same commit whenever the UI they show changes.

## Shared Supertext protocol

AI file translation API v1, same as the WordPress plugin: POST HTML file → poll status → GET translation → DELETE. Details in `docs/DEVELOPER.md`. Never commit API keys; use the `SUPERTEXT_API_KEY` environment variable or the module's API key setting.

Lessons from the live API, apply them here: header `Authorization: Supertext-Auth-Key <key>` (strip a pasted prefix), retry HTTP 429 (per-second rate limit), and keep a whole text in one `data-st-id` element (each one is translated on its own).

## This repo

- Before committing: `composer test`, PHP lint, `./build.sh`. CI also installs PrestaShop 9 in Docker and translates the demo content against the stand-in.
- Test UI changes in a local PrestaShop with the module mounted (see `docs/DEVELOPER.md` → Local development) and regenerate the screenshots they affect (`tests/docs/screenshots.mjs`).
- New settings go in `supertext/src/Settings.php`, `views/templates/admin/configure.tpl` **and** the settings table in `docs/INSTALLATION.md`.
- Translated fields live in `supertext/src/Translation/EntityTypes.php`; keep "What is translated" in `docs/DEVELOPER.md` and `docs/USER_GUIDE.md` in sync.
- Keep `supertext/src/Api` and `FieldPlanner` free of PrestaShop classes (unit tests run without PrestaShop).
- User-visible strings use the translation domain `Modules.Supertext.Admin`.
- `demo/` is the Railway demo (service *PrestaShop* in *supertext-cms-demos-php*, building `demo/Dockerfile` with context = repo root; set on the service, Railway ignores `railway.json`). `demo/setup.php` seeds languages, sample content and accounts. Demo secrets live only in Railway variables. Don't export-ignore `demo/` or `supertext/` in `.gitattributes`: Railway builds from a `git archive` snapshot.
- PrestaShop 9 installer quirks the demo depends on: it must run from the web root, it expects the admin folder to be `admin` or `admin-dev`, and `--country` must be upper case (`CH`), or it installs the country's languages and fails.
