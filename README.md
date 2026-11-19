# Genie photo

A group shares one photo. Each person signs in through their own AI assistant and makes wishes: hide my face, crop me out, put me in the middle, paint a smile on my mask. A genie grants each wish to the letter, and the shared picture changes for everyone. The photo is final when every player approves the exact same picture.

Each assistant receives the photo, so it can find who is in it and paint the person it sees. Your assistant's provider therefore receives the photo too. PHP code applies the crops, covers and paint.

## Run it

You need PHP 8.4 or later, Composer and curl. Ports 7225 and 8000 must be free. Tested on Linux x86-64.

```sh
git clone https://github.com/theodoreb/genie-photo.git
cd genie-photo
bin/start
```

Open http://127.0.0.1:8000 and sign in as `alice`, `bob`, `carol` or `dan`. The password is `genie`.

`bin/start` installs the dependencies, downloads Drupack through Composer, installs the Drupal site and serves both applications on loopback. Ctrl-C stops the web view. `cd drupal && ./drupack stop .` stops Drupal.

On Linux x86-64 with glibc 2.28 or later, `bin/start` replaces Drupack with a [build that adds PHP's FFI extension](https://github.com/theodoreb/genie-photo/releases/tag/drupack-1.0.0-rc4-ffi). It checks the file's SHA-256 first. Other systems keep the official Drupack.

### With Docker instead

Without PHP 8.4, Docker runs the same `bin/start` in one container:

```sh
git clone https://github.com/theodoreb/genie-photo.git
cd genie-photo
docker compose up
```

The ports are published on 127.0.0.1 only, so the addresses below stay the same. The container has no local model, so wishes go through the keyword reader. Run checks inside it with `docker compose exec genie drupal/drupack php bin/smoke-wish alice`.

## Replay the demo

`bin/replay codex` or `bin/replay claude` plays the video's story with one assistant for all four players. `demo/replay.md` lists its steps, and you can paste them into your own assistants instead.

## Play

1. The first player uploads a photo. `demo/holiday-party.jpg` is ready to use.
2. A local detector boxes the people in the photo. One player asks their assistant to trace them.
3. Each player tells their assistant which person they are, or clicks their face on the web view.
4. Players make wishes and repaint their whole person, as pixel art if they like.
5. If two wishes cannot both hold, the page names the players in the way.
6. Each player approves the picture on a page that shows it. Any later change cancels the approvals.
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

To play several players from one machine, add the server once per player under another name, such as `genie-bob`, and sign each one in as that player.

Drupal serves HTTPS instead when the machine trusts Drupack's or mkcert's local root. `.env.local` holds the address in use. On HTTPS, start Vibe with `SSL_CERT_FILE="$(mkcert -CAROOT)/rootCA.pem"`.

| Tool | What it does |
|---|---|
| `my_status` | Names the signed-in player |
| `session_get` | Describes the session, without image data |
| `photo_get` | Returns the photo, the shared picture, the tracing grid, or what you can paint under a grid |
| `scan_photo` | Reports the people, their outlines and the objects found in the photo |
| `claim_place` | Takes a found person as yours, or corrects your head or box |
| `make_wish` | Sends a wish to the genie |
| `paint_me` | Repaints your whole person and a one-block safe zone, with optional pixel art, and returns the result |
| `wait_for_update` | Waits up to 25 seconds for a change, approvals included |
| `approve_export` | Sends you to the page that shows the picture, and reports your approval |

Protecting objects and dismissing people who are not playing stay on the web view. The web view also has `picture_get`, which returns its images. Assistants cannot call it.

Only your click on the page records an approval. Your assistant cannot approve for you. The page refuses another player's account and a picture that has changed.

| Client | How you reach the page |
|---|---|
| Claude Code 2.1.328 | It asks to open the page through MCP URL elicitation. Approve there, then choose "I'm done, continue". |
| Codex 0.170 | It gives you the link and waits. Its terminal app declines URL requests. |
| Vibe 3.1 | It has no elicitation, so `approve_export` returns the link. |

Sign-in, the tools and the approval flow were tested with Claude Code and Codex. Vibe was tested for sign-in and the tools.

## Optional local model

The site works without one. Wishes then go through a keyword reader. With [Ollama](https://ollama.com) on its default port, `ollama pull gemma3:4b` (3.4 GB) lets a local model read free-form wishes such as "I deserve the spotlight". The model only picks an operation for the speaker. It refuses requests about other people. Symfony AI turns its answer into a validated PHP object. An invalid answer changes nothing, and the genie asks again.

## How it fits together

- Symfony 8 at the repository root is the web view. It signs players in as an OAuth client of Drupal and calls Drupal's tools with the PHP MCP SDK: `src/Drupal/McpDrupalTools.php`.
- Drupal in `drupal/` holds the rules, in the module `genie_ledger`. Drupal's `mcp_server` module serves assistants directly.
- Drupal AI runs the local wish reader on Symfony AI's platform: `WishInterpreter.php`.
- Symfony AI's TransformersPhp bridge runs DETR in PHP to box people: `PersonDetector.php`. It needs the FFI build of Drupack and a 42 MB model from Hugging Face. Without them, assistants find the people.
- Every photo is scaled to a 1600-pixel long side, so blocks look the same on every photo.
- Drupal gives each grid cell to one person, and the person in front wins: `Outlines.php`. Paint reaches a player's own cells and one block of background around them. A player with no outline can paint everything except other people.
- A revisionable Media item holds the photo, the detector's boxes, the people and the objects, as `compound_field` rows. The game session points to one revision.
- Drupack serves Drupal and its Mercure hub, which tells each browser when the session changes.
- Assistants and the web view get images through MCP tools. Symfony keeps no game data. Only the upload uses an HTTP route, which accepts only the web view's token. The web view is a confidential OAuth client, and `bin/start` gives it a new secret in `.env.local`.

## Checks

| Command | What it checks |
|---|---|
| `bin/qa` | Coding standards, PHPStan level 3 on Drupal code, linters, unit tests |
| `bin/smoke-oauth alice` | Sign-in and one MCP call |
| `bin/smoke-live` | Upload, a live update and the picture over MCP |
| `bin/smoke-wish alice` | A wish changes the picture |
| `bin/smoke-collision` | Two wishes that cannot both hold |
| `bin/smoke-scan` | An assistant finds people, claims one and paints, and ids survive a new scan |
| `bin/smoke-outline` | The detector finds people, and paint reaches the safe zone but no neighbour |
| `bin/smoke-review` | The approval page: return after sign-in, wrong player, changed picture |
| `bin/smoke-approve` | Approval, URL elicitation, output schemas, and what an assistant cannot reach |
| `bin/verify-clone` | A fresh clone starts and grants a wish, with ports 7225 and 8000 free |
| `bin/verify-loopback` | Both servers listen on loopback only |

Smoke scripts reset the session. To reset it by hand, run `cd drupal && ./drupack drush genie:reset`.

## License

GPL-2.0-or-later, the license of Drupal. See `LICENSE`.

`demo/holiday-party.jpg` is "Holiday Coronavirus" by PS Imaging, from [StockSnap](https://stocksnap.io/photo/holiday-coronavirus-OWPQGSY7C6), under CC0. It is enlarged to 1600 pixels wide.
