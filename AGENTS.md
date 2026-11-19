# AGENTS.md

Genie photo: players share one group photo and make wishes through their own AI assistants. `README.md` describes the game and its parts.

## Run it

- `bin/start` installs everything and serves Drupal on 127.0.0.1:7225 and the web view on 127.0.0.1:8000. It needs Linux x86-64 with glibc 2.28 or later, curl and git, and no PHP.
- Elsewhere, `docker compose up` runs the same start in a container.
- `drupal/drupack` runs PHP, Composer and Drush, such as `cd symfony && ../drupal/drupack composer install`.
- `bin/replay codex` or `bin/replay claude` plays the demo story with one assistant for all four players. The steps are in `demo/replay.md`.
- `bin/start` prints a host code. A host starts a game at `/genie/host`, and guests join from its invite link. `drush genie:seats alice bob` seats test players and prints their passwords.

## Where things are

- Symfony 8 web view: `symfony/`. It reaches Drupal only through MCP, in `symfony/src/Drupal/McpDrupalTools.php`.
- Game rules and data: the Drupal module `drupal/web/modules/custom/genie_ledger`.
- Smoke scripts: `symfony/tests/smoke/<name>.php`, sharing `symfony/tests/smoke/lib/`.
- The Drupack build: patches and the build script in `fork/drupack/`. `bin/lib/get-drupack` downloads it.

## Checks

- `bin/qa` runs on the machine's PHP 8.4 or later. It runs the coding standards, PHPStan level 3 on Drupal code, the linters and the unit tests.
- `bin/smoke NAME` runs one smoke script. It needs the site running, and starts its own game.
- `bin/verify-clone` starts a fresh clone and grants a wish. Ports 7225 and 8000 must be free.

## Rules

- Drupal holds all game data. Symfony stores none.
- Only the person's click on the approval page records an approval. An assistant never approves for its player.
- Keep tests light: one simple case on the critical path.
