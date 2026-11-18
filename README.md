# Genie photo

A group shares one photo. Each person signs in through their own AI assistant and makes wishes: hide my face, crop me out, put me in the middle, paint a smile on my mask. A genie grants each wish to the letter, and the shared picture changes for everyone. The photo is final when every player approves the exact same picture.

Each assistant receives the photo, so it can find who is in it and paint on the head it sees. Your assistant's provider therefore receives the photo too. PHP code applies the crops, covers and stickers.

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

1. The first player uploads a photo. `demo/holiday-party.jpg` is ready to use.
2. One player asks their assistant to find everyone in the photo.
3. Each player tells their assistant which person they are, or clicks their face on the web view.
4. Players make wishes and ask for stickers.
5. If two wishes cannot both hold, the page names the players in the way.
6. Each player approves the picture. Any later change cancels the approvals.
7. When everyone has approved, the page offers the download.

If a box is wrong or someone is missing, tell your assistant. It looks again and sends a corrected box.

## Connect an assistant

Add this MCP server to your assistant, which then opens the Drupal sign-in page.

```sh
# Claude Code
claude mcp add --transport http genie http://127.0.0.1:7225/mcp
claude mcp login genie

# Codex
codex mcp add genie --url http://127.0.0.1:7225/mcp
codex mcp login genie

# Mistral Vibe, which signs in as it adds the server
vibe mcp add genie --url http://127.0.0.1:7225/mcp
```

Drupal serves HTTPS instead when the machine trusts Drupack's or mkcert's local root. `.env.local` holds the address in use. On HTTPS, start Vibe with `SSL_CERT_FILE="$(mkcert -CAROOT)/rootCA.pem"`.

| Tool | What it does |
|---|---|
| `my_status` | Names the signed-in player |
| `session_get` | Describes the session, without image data |
| `photo_get` | Returns the photo, the shared picture or your head under the sticker grid |
| `scan_photo` | Reports the people and objects found in the photo |
| `claim_place` | Takes a found person as yours, or corrects your box |
| `make_wish` | Sends a wish to the genie |
| `set_sticker` | Paints your 32 by 32 pixel sticker and returns the result |
| `wait_for_update` | Waits up to 25 seconds for a change, approvals included |
| `approve_export` | Asks you to approve, through MCP elicitation |

Protecting objects and dismissing people who are not playing stay on the web view.

Sign-in, the tools and the approval prompt were tested with Claude Code 2.1 and Codex 0.170. Vibe 3.1 signs in and uses the tools. It has no approval prompt, so a Vibe player approves on the web view.

## Optional local model

The site works without one. Wishes then go through a keyword reader. With [Ollama](https://ollama.com) on its default port, `ollama pull llama3.2` lets a local model read wishes.

## How it fits together

- Symfony 8 at the repository root is the web view. It signs players in as an OAuth client of Drupal and calls Drupal's tools with the PHP MCP SDK: `src/Drupal/McpDrupalTools.php`.
- Drupal in `drupal/` holds the rules, in the module `genie_ledger`. Drupal's `mcp_server` module serves assistants directly.
- Drupal AI runs the local wish reader on Symfony AI's platform: `WishInterpreter.php`.
- Drupack serves Drupal and its Mercure hub, which tells each browser when the session changes.
- Assistants get images through MCP tools. The web view gets pixels over HTTP routes that accept only its token. The web view is a confidential OAuth client, and `bin/start` gives it a new secret in `.env.local`.

## Checks

| Command | What it checks |
|---|---|
| `bin/qa` | Coding standards, PHPStan level 3 on Drupal code, linters, unit tests |
| `bin/smoke-oauth alice` | Sign-in and one MCP call |
| `bin/smoke-live` | Upload and a live update |
| `bin/smoke-wish alice` | A wish changes the picture |
| `bin/smoke-collision` | Two wishes that cannot both hold |
| `bin/smoke-sticker` | A sticker changes the picture |
| `bin/smoke-scan` | An assistant sees the photo, finds a person, claims it and paints |
| `bin/smoke-approve` | Approval, and what an assistant cannot reach |
| `bin/verify-clone` | A fresh clone starts and grants a wish, with ports 7225 and 8000 free |
| `bin/verify-loopback` | Both servers listen on loopback only |

Smoke scripts reset the session. To reset it by hand, run `cd drupal && ./drupack drush genie:reset`.

## License

GPL-2.0-or-later, the license of Drupal. See `LICENSE`.

`demo/holiday-party.jpg` is "Holiday Coronavirus" by PS Imaging, from [StockSnap](https://stocksnap.io/photo/holiday-coronavirus-OWPQGSY7C6), under CC0. It is enlarged to 1600 pixels wide.
