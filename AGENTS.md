# AGENTS.md

Genie photo: players share one group photo and make wishes through their own AI assistants. `README.md` describes the game and its parts.

## Run it

- `bin/start` installs everything and serves Drupal on 127.0.0.1:7225 and the web view on 127.0.0.1:8000. It needs Linux x86-64 with glibc 2.28 or later, curl and git, and no PHP.
- Elsewhere, `docker compose up` runs the same start in a container.
- `drupal/drupack` runs PHP, Composer and Drush, such as `cd symfony && ../drupal/drupack composer install`.
- `bin/replay codex` or `bin/replay claude` plays the demo story with one assistant for all four players. The steps are in `demo/replay.md`.
- `bin/start` prints a host code and opens six prepared games. A host starts a game at `/genie/host`, and guests join from its invite link. Anyone joins a prepared game from `/games`. `drush genie:seats alice bob` seats test players and prints their passwords.

## Where things are

- Symfony 8 web view: `symfony/`. It calls Drupal through MCP, in `symfony/src/Drupal/McpDrupalTools.php`, except for the OAuth sign-in, the photo upload and the join form.
- Game rules and data: the Drupal module `drupal/web/modules/custom/genie`.
- Prepared games: `archive/manifest.json` and `src/Photo/Archive.php` in that module.
- The guide for AI agents: `symfony/templates/games/llms.txt.twig`, served at `/llms.txt`.
- Colours, fonts and the halftone shade: `symfony/assets/styles/tokens.css`. The Drupal theme `genie_theme` links it and the fonts folder.
- Smoke scripts: `symfony/tests/smoke/<name>.php`, sharing `symfony/tests/smoke/lib/`.
- The Drupack build: patches and the build script in `fork/drupack/`. `bin/lib/get-drupack` downloads it.

## Checks

- `bin/qa` runs on the machine's PHP 8.4 or later. It runs the coding standards, PHPStan (level 8 on `symfony/`, level 5 on Drupal code), the linters and the unit tests. The web view's unit tests are in `symfony/tests/Security/`.
- `bin/smoke NAME` runs one smoke script. It needs the site running, and starts its own game.
- `bin/verify-clone` starts a fresh clone and grants a wish. Ports 7225 and 8000 must be free.

## Rules

- Drupal holds all game data. Symfony stores none.
- Only the person's click on the approval page records an approval. An assistant never approves for its player.
- Keep tests light: one simple case on the critical path.
