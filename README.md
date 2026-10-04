# Chess Dog

The Symfony web application for [chess-crawl](https://github.com/xormania/chess-crawl).

This first increment supplies a runnable application shell and a small provider
and player preview. The preview exercises Live Components without contacting a
chess provider or chess-crawl. Backend integration is a later increment.
Returning through Turbo or loading a new Foundation document starts a fresh
preview. Native browser Back may preserve the complete preview in the browser's
page cache; its provider selection and Live state remain consistent.

## Stack

| Layer | Implementation |
| --- | --- |
| Application | Symfony 8.1, PHP 8.5, Composer and Symfony Flex |
| Runtime | Symfony Docker with FrankenPHP and Caddy |
| Rendering | Twig and Symfony UX Twig Components |
| LAST | Live Components, AssetMapper, Stimulus and Turbo |
| Styling | Tailwind CSS v4 through SymfonyCasts TailwindBundle |
| UI | Flowbite v4 and Symfony UX Toolkit component recipes |

Composer dependencies and Flex recipes are locked in `composer.lock` and
`symfony.lock`. Front-end packages are versioned in `importmap.php`; the
standalone Tailwind version is set in
`config/packages/symfonycasts_tailwind.yaml`. This setup uses no Node.js or
JavaScript bundler.

## Local tools

Use [Devbox](https://www.jetify.com/docs/devbox/installing-devbox) to install the
native development tools together. The checked-in `devbox.json` declares the
packages and `devbox.lock` pins their resolved versions. This environment targets
Linux/WSL2; run it inside the same WSL distribution as the checkout.

Install Devbox once as your regular Linux/WSL user (CI tests Devbox **0.18.4**):

```bash
curl -fsSL https://get.jetify.com/devbox | bash
```

Devbox installs Nix if needed when you first use the environment. It then supplies:

| Capability | Packages |
| --- | --- |
| Application runtime | PHP 8.5 with required extensions; Composer 2 built for PHP 8.5 |
| Native web server | Symfony CLI |
| Browser tests | Chromium and matching ChromeDriver |
| Optional container commands | Docker client with Compose and Buildx |
| Scripts and diagnostics | Bash, coreutils, curl, Git, Python 3, unzip, grep, sed, awk, findutils and ripgrep |

Composer still installs project packages (Mate, Maker, PHPUnit, Panther and BDI)
into `vendor/`. `scripts/setup` installs locked importmap assets and builds the
project's pinned standalone Tailwind executable. No Node.js installation is needed.
The stock Nix PHP package supplies the extensions; Devbox's PHP/FPM plugin is
disabled so it does not generate a second runtime/configuration.

Docker Desktop (with WSL integration) or another Docker daemon is only needed for
the optional container workflow. Devbox supplies the client commands and preserves
your Docker connection/context; it does not install or start a daemon. Native
Symfony serving, Mate, asset compilation and tests work without a Docker daemon.

If you already manage your tools yourself, install PHP **8.5** and Composer 2 in
the same Linux/WSL environment and use the same `scripts/setup` entrypoint.

The locked packages require PHP's ctype, curl, DOM/XML, iconv, mbstring, tokenizer,
zip and standard bundled extensions. Install intl for parity with Docker and
pdo_sqlite for the current optional Doctrine configuration. `composer
check-platform-reqs` checks the complete dependency requirements on your runtime.

The [Symfony CLI](https://symfony.com/download) is included in Devbox and optional
for manually installed tools. The checked-in
`.php-version` selects PHP 8.5 for `symfony php`, `symfony composer` and Symfony's
local server, **if PHP 8.5 is already installed**. It does not change plain `php`
on your shell's PATH. Verify `php -v` before running native commands.

For WSL, keep the checkout in the Linux filesystem (for example under `~/projects`),
and enable Docker Desktop integration for that distribution if using containers.
Browser tests need a Linux Chrome/Chromium or Firefox installation in that same
environment; the Windows browser is not the Linux Panther browser.

## Start development

```bash
git clone --branch dev https://github.com/xormania/chess-dog.git
cd chess-dog
devbox shell
scripts/check-devbox
scripts/setup
symfony server:start
```

Open the URL printed by Symfony. For live stylesheet updates, run
`devbox run watch` in another terminal. Each new terminal can enter `devbox shell`;
`exit` leaves the environment. For individual commands, use `devbox run -- php
bin/console about`, or the `setup`, `check`, `test`, `serve` and `watch` aliases.
For example, `devbox run setup --env test --skip-assets -- --no-interaction` forwards
options to the existing setup helper; `devbox run test --filter RuntimeStorageTest`
forwards PHPUnit options. Starting a shell does not install project dependencies,
start servers, or change Symfony's `APP_ENV`/cache settings.

`scripts/check-devbox` verifies tool provenance, PHP/Composer versions, required
extensions (including child PHP processes), Symfony's PHP selection, matching
browser/driver versions and Docker client plugins. `--with-docker` additionally
requires a reachable daemon. If PHP extensions fail, inspect inherited `PHPRC` or
`PHP_INI_SCAN_DIR` overrides. Panther uses Devbox's Chromium by default; set
`PANTHER_CHROME_BINARY` explicitly to test another browser installation.

`scripts/setup` installs locked PHP packages, checks platform requirements,
regenerates Mate discovery and builds Tailwind with native tools. Each helper
supports `--help`; setup options include `--env test`, `--skip-assets`, an optional
`--browser google-chrome` driver install, and Composer install arguments after `--`.

For the optional Docker runtime, stop the native server and watcher, then run:

```bash
scripts/compose up --build --wait --wait-timeout 120
```

`scripts/compose` forwards normal Docker Compose arguments and supplies your
current UID/GID as development-image build arguments. Both PHP and Tailwind run
as that user, including later `compose exec` calls. `LOCAL_UID` and `LOCAL_GID`
can override these defaults; rebuild the image after changing either value.
Numeric IDs that already exist in the base image are supported through the
development account alias. Selecting UID 0 runs the development process as root.
Direct `docker compose` remains available; export those variables yourself when
your user IDs differ from the defaults of 1000.

Open [https://localhost](https://localhost) and accept the development certificate.
The **Foundation** page contains the interactive preview and dialog. Source,
`vendor/` and `var/` are shared through the project bind mount. The container can
also install the lock file if `vendor/` is absent. The Tailwind service watches
for stylesheet changes while FrankenPHP watches PHP changes.

If the default ports are occupied:

```bash
HTTP_PORT=8080 HTTPS_PORT=8443 HTTP3_PORT=8443 scripts/compose up --build --wait
```

Then open [https://localhost:8443](https://localhost:8443).
HTTP requests on port 8080 redirect to that HTTPS port, preserving the path and query.
With a custom HTTPS port, recreate PHP after changing Caddy routes or global
options so startup can check the redirect configuration again. Use the same port
variables with `scripts/compose up --force-recreate --wait php`.

After pulling a dependency-lock update, run `scripts/setup` again. Typical native
commands and explicit container diagnostics:

```bash
php bin/console about
php bin/console debug:router
php bin/console make:controller
vendor/bin/mate tools:list
scripts/compose exec php php bin/console about
scripts/compose logs --follow php tailwind
scripts/compose down
```

To serve entirely on the host, use `symfony server:start` and run
`php bin/console tailwind:build --watch` in another terminal. Run only one Tailwind
watcher for a checkout. Container-specific behavior is still validated through Docker.

## Shared development storage

| Path | Purpose |
| --- | --- |
| `var/cache/host/<env>` | Native Symfony compiled cache and build files |
| `var/cache/docker/<env>` | Development Docker compiled cache and build files |
| `var/profiler/<env>` | Shared request profiles, readable from native Mate |
| `var/log/` | Shared application logs |
| `var/tailwind/` | Shared Tailwind executable and generated stylesheet |

Symfony's native `APP_CACHE_DIR` selects the cache root; its build directory follows
that root. Both dev and test default to the host root, and development Compose
overrides it for PHP and Tailwind. Production retains `var/cache/prod`. Clear a
runtime's cache with that runtime's console command; shared profiles live outside
both cache roots. Do not point host and Docker compiled caches at the same path.
If you override `APP_CACHE_DIR` or `APP_BUILD_DIR`, also update the corresponding
cache context in `mate/config.php` and rerun discovery. The shared-storage check
validates the default directory layout listed above.

A short `caddy-storage` initialization service adjusts retained Caddy volume
ownership to the development image's user before PHP starts. It mounts only
Caddy's data/config volumes and preserves their contents, including certificates.
It has no access to the source bind mount. Production keeps its separate Caddy
volumes and `www-data` runtime.

The old `app_var` named volume is no longer mounted or deleted by this change.
Its previous logs/profiles remain in that volume; new records appear in local
`var/`. Compiled caches should be regenerated, not copied between runtimes.
If an older container created root-owned generated files in the checkout, inspect
and repair ownership of the affected paths (`vendor/`, `assets/vendor/`, `var/`,
or Mate-generated files) before running native setup. Do not run setup with sudo.

`down` retains local `var/` and the named Caddy volumes. The current app has no
entities or application database requirements; Doctrine's optional SQLite file
also lives under `var/`.

## Local development with Symfony AI Mate

[Symfony AI Mate](https://symfony.com/doc/current/ai/components/mate.html) and its
Symfony/Monolog extensions are development dependencies. Use native commands by
default:

```bash
vendor/bin/mate discover --no-interaction
vendor/bin/mate tools:list
vendor/bin/mate tools:call server-info --format=json
vendor/bin/mate tools:call symfony-services --context=host --query=ArchivePreview --format=json
vendor/bin/mate tools:call symfony-services --context=docker --query=ArchivePreview --format=json
vendor/bin/mate tools:call symfony-profiler-list --limit=5 --format=json
```

Warm the relevant application's cache before inspecting its services:
`php bin/console cache:warmup` for native commands, or start development Compose
for the container. The `host`/`docker` contexts select compiled XML metadata; Mate
does not execute the other runtime's cached PHP. Profiles from development HTTP
requests are shared in `var/profiler/dev`; logs are shared in `var/log`.

`server-info` describes the process running Mate. To measure the container's PHP
or extensions, explicitly use `scripts/compose exec -T php vendor/bin/mate
tools:call server-info --format=json`. The configured guard checks PHP 8.5 major/
minor compatibility; it does not enforce Docker or guarantee identical extensions.

Run discovery after changing dependencies or Mate configuration; the current
Composer PHAR plugin can miss automatic discovery. `scripts/setup` does this
explicitly. Commit reviewed configuration and generated instructions together.
Codex reads `AGENTS.md` and `.agents/skills/`; Claude imports the orientation through
`CLAUDE.md` and `.claude/skills/`. Project notes belong outside managed markers.
Mate needs no model-provider credentials and is excluded from the production image.

## Validate

```bash
composer validate --strict
php bin/console lint:yaml config --parse-tags
php bin/console lint:twig templates
php bin/console lint:container --resolve-env-vars
vendor/bin/phpunit tests/Functional
```

Inside Devbox, run `devbox run test` or `vendor/bin/phpunit` in its shell; Chromium
and ChromeDriver are already installed and no BDI download is needed. With manually
installed tools, install a browser and matching driver (for example,
`scripts/setup --browser google-chrome`) before running `vendor/bin/phpunit`. Tests cover
Live updates, dialogs, Turbo/native history, responsive layouts, and actual cache
clearing across runtime contexts. The application Docker image does not contain
a browser.

With development Compose running, check native/container interoperability:

```bash
scripts/check-shared-storage
scripts/check-shared-storage --url https://localhost:8443 --ca-file /path/to/caddy-root.crt
```

This helper checks UID/GID, bidirectional writes, generated CSS, both Mate service
contexts and a profile from an actual container request. It warms and clears the
native dev cache. It requires Python 3 and curl in addition to the development tools.
Without `--ca-file`, it copies the running Caddy local CA to its temporary directory.

`scripts/check-dev-user` builds development images for existing numeric IDs and
checks their runtime identity, writable home/Caddy storage, and bind-mount file
ownership. It defaults to `0:0` and `33:33`; pass other `UID:GID` pairs or use
`--builder NAME` / `--tag-prefix PREFIX` to customize the checks.

CI validates both Docker configurations over trusted HTTPS. Development checks
also seed legacy root-owned Caddy storage, verify ownership migration and run the
native/shared-storage helper under the runner's nondefault UID/GID. Production
checks confirm Mate's exclusion. The existing Symfony job installs the locked
Devbox environment, verifies actual tools, runs setup/linting/assets/PHPUnit/Panther,
and fails if installation changes the package locks. Caches retain Devbox/Nix
packages, Composer downloads, the pinned Tailwind executable and shared Docker layers.
Documentation-only
PRs retain the named checks while skipping expensive work; invalid scope decisions
fail. Pushes and manual runs always validate.

To update development tools intentionally, use `devbox update` (or name a package),
then run `devbox run check`, `devbox run setup` and `devbox run test` before committing
`devbox.json` and `devbox.lock`. Update Chromium and ChromeDriver together; the
check rejects mismatched versions. Keep generated `.devbox/` state out of Git.
Devbox configuration and state are excluded from production Docker images.

## Build the production image

Provide runtime secrets through the deployment environment. For a local smoke
check, export `APP_SECRET` and `CADDY_MERCURE_JWT_SECRET`, then run:

```bash
docker compose down
docker compose -f compose.yaml -f compose.prod.yaml up --build --wait
```

Selecting these files omits the development bind mount and watcher. The image
installs production dependencies, warms the application, builds minified
Tailwind CSS, then compiles AssetMapper assets. The final image runs as
`www-data` and includes the application and its built assets.
Production uses separate `caddy_data_prod` and `caddy_config_prod` volumes. This
keeps its certificates readable without reusing storage owned by the local
development UID/GID; both modes retain their own Caddy storage across shutdowns.

The Symfony Docker runtime retains its bundled Mercure capabilities. There is
no application event integration in this increment. Chess-crawl's API and hub
will be integrated through application-owned configuration and credentials.

## Contribute

Create feature branches from `dev` and open PRs into `dev`. Commit source,
configuration and lock files; generated caches, downloaded front-end modules and
Composer dependencies are ignored.

## Credits and license

The LAST approach comes from Ryan Weaver and the SymfonyCasts team. See
[Your LAST Stack](https://symfonycasts.com/blog/last-stack).

The runtime is adapted from
[Symfony Docker](https://github.com/dunglas/symfony-docker), commit
`422756611d61e0108600ed7ec1370ec677d0e8d0`; its attribution and MIT license are
preserved in [frankenphp/LICENSE](frankenphp/LICENSE). Flowbite components were
installed through the [Symfony UX Toolkit](https://ux.symfony.com/toolkit)'s
Flowbite v4 kit; its MIT notice is preserved in
[third-party/SYMFONY-UX-LICENSE](third-party/SYMFONY-UX-LICENSE).
Mate's generated instructions and skills retain their upstream MIT notice in
[third-party/SYMFONY-AI-LICENSE](third-party/SYMFONY-AI-LICENSE).

Chess Dog is licensed under [Apache 2.0](LICENSE).
