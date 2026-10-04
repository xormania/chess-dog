# Changelog

## Unreleased

- Connect the player archive UI to chess-crawl using server-side credentials,
  stored profile/game reads, exact reported move-clock evidence, and explicit
  CSRF-protected background collection with idempotent submission keys.
- Keep archive/provider errors and credentials out of rendered pages, bound
  backend requests, and document the local workspace and SaaS authentication
  boundary.
