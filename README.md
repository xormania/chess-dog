# Chess Dog

The Symfony web application for [chess-crawl](https://github.com/xormania/chess-crawl).

This first increment supplies a runnable application shell and a small provider
and player preview. The preview exercises Live Components without contacting a
chess provider or chess-crawl. Backend integration is a later increment.
Returning to the Foundation page starts a fresh preview, including browser Back.

## Stack

| Layer | Implementation |
| --- | --- |
| Application | Symfony 8.1, PHP 8.5 in Docker, Composer and Symfony Flex |
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

## Start development

Requires Docker with the Compose v2 plugin and Linux container support.

```bash
git clone --branch dev https://github.com/xormania/chess-dog.git
cd chess-dog
docker compose up --build --wait --wait-timeout 120
```

Open [https://localhost](https://localhost) and accept the development certificate.
The **Foundation** page contains the interactive preview and dialog.

The PHP container installs locked dependencies when `vendor/` is absent. A
Tailwind watcher starts after PHP is healthy; both containers share the `app_var`
volume so the server receives the compiled CSS. Source changes are bind-mounted,
and FrankenPHP watches for PHP changes.

If the default ports are occupied:

```bash
HTTP_PORT=8080 HTTPS_PORT=8443 HTTP3_PORT=8443 docker compose up --build --wait
```

Then open [https://localhost:8443](https://localhost:8443).

After pulling a change to the dependency lock file, install it explicitly:

```bash
docker compose exec php composer install --prefer-dist --no-interaction
docker compose exec -T php vendor/bin/mate discover --no-interaction
```

Useful commands:

```bash
docker compose exec php php bin/console about
docker compose exec php php bin/console debug:router
docker compose logs --follow php tailwind
docker compose down
```

`docker compose down` retains the generated `var/` and Caddy volumes. The webapp
pack includes Doctrine, configured for optional SQLite application storage in
`var/`; this increment has no entities or application database requirements.

## Local development with Symfony AI Mate

The development dependencies include [Symfony AI Mate](https://symfony.com/doc/current/ai/components/mate.html)
and its official Symfony and Monolog extensions. Mate exposes CLI tools for
inspecting the compiled container, profiler and logs. This release uses a CLI;
there is no MCP server or client configuration to start.

Start the development stack, then discover the installed tools:

```bash
docker compose exec -T php vendor/bin/mate discover --no-interaction
docker compose exec -T php vendor/bin/mate tools:list
docker compose exec -T php vendor/bin/mate tools:inspect symfony-services --format=json
docker compose exec -T php vendor/bin/mate tools:call server-info --format=json
```

Use the Docker invocation so Mate shares the application's PHP 8.5 runtime and
the `app_var` volume containing its container, profiler and logs. Host PHP is not
required. Visit the application before inspecting request profiles.

`mate/config.php` records this invocation and PHP version. `mate/extensions.php`
records enabled extensions and generated skills. Codex reads the generated
`AGENTS.md` block and `.agents/skills/`; Claude Code imports that orientation
through `CLAUDE.md` and `.claude/skills/`. Generated instructions and skills are
managed by Mate; put project notes outside its managed markers.

Run explicit discovery after dependency or Mate configuration changes. The
current discovery plugin can miss a project when Composer runs from a PHAR, so
do not rely on its automatic hook. Commit the reviewed configuration and
generated instructions together with the lock file.

Mate requires no model provider credentials for these diagnostics. It is a
development dependency and its configuration and agent skills are excluded
from the production image.

## Validate

```bash
docker compose exec php composer validate --strict
docker compose exec php php bin/console lint:yaml config --parse-tags
docker compose exec php php bin/console lint:twig templates
docker compose exec php php bin/console lint:container --resolve-env-vars
docker compose exec php php vendor/bin/phpunit tests/Functional
```

CI also runs the full PHPUnit suite with Panther and a matching Chrome driver.
The browser test exercises the Live preview, dialog keyboard/focus behavior,
Turbo navigation, browser Back and interactions after returning. Running that
suite outside CI requires Chrome and a matching driver; the application Docker
image does not include a browser.

The Docker checks build and start both development and production configurations,
then fetch rendered pages and their compiled stylesheet over HTTPS, trusting
Caddy's generated local CA. They also check separate Caddy storage for the two
runtimes, development Mate tools, and Mate's absence from production.

CI caches Composer downloads, the pinned Tailwind executable, the matching
Chrome driver, and shared Docker build layers. Dependencies are still installed
from the lock and assets rebuilt on each application run. Documentation-only
PRs skip expensive setup while retaining the three named checks; an invalid
scope decision fails those checks. Pushes and manual runs always validate.

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
keeps its certificates readable without reusing development storage owned by
root; both modes retain their own Caddy storage across shutdowns.

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
