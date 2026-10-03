# Agent orientation

Chess Dog is a Symfony web application for chess-crawl. Its current foundation
uses Symfony Docker, Twig, Live Components, AssetMapper, Stimulus, Turbo,
Tailwind CSS and Flowbite.

The maintainer's current request sets the task. Read README.md for startup,
validation and integration status. Read composer.json, composer.lock,
symfony.lock and importmap.php for installed dependencies and versions.

## Development

- Work from dev on a feature branch and open a pull request targeting dev.
- Use Symfony Docker for application commands and development.
- Install capabilities with Composer and Symfony Flex. Reuse Symfony UX Toolkit
  components where they fit, and adapt them within the application.
- Follow Symfony conventions: PHP attributes, autowiring, thin controllers,
  services for application behavior, and Twig for rendered HTML.
- Run the validation appropriate to a change. Browser interactions require
  real browser checks; HTTP tests alone do not execute JavaScript.
- Consult installed source, console discovery commands and version-matched
  official documentation when an API is unclear.
