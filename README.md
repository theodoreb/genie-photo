# Genie photo

A group shares one photo. Each person signs in through their own AI assistant and makes wishes: hide my face, crop me out, put me in the middle. A genie grants each wish to the letter, and the shared picture changes for everyone. The photo is final when every player approves the exact same picture.

No assistant and no cloud model receives the photo. The genie reads words. PHP code applies the crops, covers and stickers.

## Run it

You need PHP 8.4 or later, Composer and curl. Ports 7225 and 8000 must be free. Tested on Linux x86-64.

```sh
git clone https://github.com/theodoreb/genie-photo.git
cd genie-photo
bin/start
```

Open http://127.0.0.1:8000 and sign in as `alice`, `bob`, `carol` or `dan`. The password is `genie`.

`bin/start` installs the dependencies, downloads Drupack through Composer, installs the Drupal site and serves both applications on loopback. Ctrl-C stops the web view. `cd drupal && ./drupack stop .` stops Drupal.

## Play

1. The first player uploads a photo.
2. Each player drags a box around themselves and clicks "This is me".
3. Each player connects an assistant and makes wishes.
4. If two wishes cannot both hold, the page names the players in the way.
5. Each player approves the picture. Any later change cancels the approvals.
6. When everyone has approved, the page offers the download.

## Connect an assistant

Add this MCP server to your assistant, which then opens the Drupal sign-in page. With Claude Code:

```sh
claude mcp add --transport http genie http://127.0.0.1:7225/mcp
claude mcp login genie
```

Drupal serves HTTPS instead when the machine trusts Drupack's or mkcert's local root. `.env.local` holds the address in use.

| Tool | What it does |
|---|---|
| `my_status` | Names the signed-in player |
| `session_get` | Describes the session, without image data |
| `make_wish` | Sends a wish to the genie |
| `set_sticker` | Places your 32 by 32 pixel sticker |
| `wait_for_update` | Waits up to 25 seconds for a change, approvals included |
| `approve_export` | Asks you to approve, through MCP elicitation |

Marking places, protecting objects and handling proposed boxes stay on the web view.

Sign-in, wishes and the approval prompt were tested with Claude Code 2.1, on HTTP and HTTPS. Mark your place on the web view before making a wish.

## Optional local models

The site works without a model. Wishes then go through a keyword reader, and people mark boxes by hand. With [Ollama](https://ollama.com) on its default port:

```sh
ollama pull llama3.2
ollama pull llava
```

`llama3.2` reads wishes. `llava` proposes boxes, which people confirm on the web view. The photo goes to the vision model only when the provider address is on this machine.

## How it fits together

- Symfony 8 at the repository root is the web view. It signs players in as an OAuth client of Drupal and calls Drupal's tools with the PHP MCP SDK: `src/Drupal/McpDrupalTools.php`.
- Drupal in `drupal/` holds the rules, in the module `genie_ledger`. Drupal's `mcp_server` module serves assistants directly.
- Drupal AI runs the local models on Symfony AI's platform: `WishInterpreter.php` and `BoxProposer.php`.
- Drupack serves Drupal and its Mercure hub, which tells each browser when the session changes.
- Pixels travel over HTTP routes that accept only the web view's token. The web view is a confidential OAuth client, and `bin/start` gives it a new secret in `.env.local`.

## Checks

| Command | What it checks |
|---|---|
| `bin/qa` | Coding standards, PHPStan level 3 on Drupal code, linters, unit tests |
| `bin/smoke-oauth alice` | Sign-in and one MCP call |
| `bin/smoke-live` | Upload and a live update |
| `bin/smoke-wish alice` | A wish changes the picture |
| `bin/smoke-collision` | Two wishes that cannot both hold |
| `bin/smoke-sticker` | A sticker changes the picture |
| `bin/smoke-approve` | Approval, and what an assistant cannot reach |
| `bin/verify-clone` | A fresh clone starts and grants a wish, with ports 7225 and 8000 free |
| `bin/verify-loopback` | Both servers listen on loopback only |

Smoke scripts reset the session. To reset it by hand, run `cd drupal && ./drupack drush genie:reset`.

## License

GPL-2.0-or-later, the license of Drupal. See `LICENSE`.
