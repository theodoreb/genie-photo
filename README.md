# Genie photo

A group shares one photo. Each person signs in through their own AI assistant and makes wishes: hide my face, crop me out, put me in the middle, paint a smile on my mask. A genie grants each wish to the letter, and the shared picture changes for everyone. The photo is final when every player approves the exact same picture.

Each assistant receives the photo, so it can find who is in it and paint the person it sees. Your assistant's provider therefore receives the photo too. PHP code applies the crops, covers and paint.

## Run it

You need Linux x86-64 with glibc 2.28 or later, curl and git. PHP is not needed. Ports 7225 and 8000 must be free.

```sh
git clone https://github.com/theodoreb/genie-photo.git
cd genie-photo
bin/start
```

`bin/start` prints a host code. Open http://127.0.0.1:7225/genie/host, enter the code and your name, and add the photo. The web view then shows an invite link for the others. Several games can run at once.

`bin/start` downloads this project's [Drupack build](https://github.com/theodoreb/genie-photo/releases/tag/drupack-1.0.0-rc6-genie.2) and checks its SHA-256. Drupack runs Composer, installs the Drupal site and serves both applications on loopback. Ctrl-C stops the web view. `cd drupal && ./drupack stop .` stops Drupal.

The build is Drupack 1.0.0-rc6 with four patches in `fork/drupack/`:

- Drupack serves and runs Composer for a PHP project without Drupal, here the Symfony web view.
- Composer's `@php` scripts run on Drupack's PHP.
- PHP has the FFI extension, which the person detector needs.
- FrankenPHP adds threads under load, because MCP listen streams and `wait_for_update` each hold one.

`fork/drupack/build` rebuilds the same file from the drupal.org tag with Docker.

### With Docker instead

On macOS, Windows or another Linux, Docker runs the same `bin/start` in one container:

```sh
git clone https://github.com/theodoreb/genie-photo.git
cd genie-photo
docker compose up
```

The ports are published on 127.0.0.1 only, so the addresses below stay the same. The image is x86-64, which Docker Desktop emulates on Apple silicon. The container has no Ollama, so wishes go through the keyword reader. Run checks inside it with `docker compose exec genie bin/smoke wish alice`.

### On a public server

Put a TLS proxy in front of both ports, then set these in the environment of `bin/start`:

| Variable | Value |
|---|---|
| `GENIE_LISTEN_IP` | `0.0.0.0`, so the proxy reaches both ports |
| `PUBLIC_URL` | the web view's public address |
| `DRUPAL_URL` | Drupal's public address. The web view calls it too, so the server must resolve it. |
| `GENIE_WEB_ORIGIN` | the web view's public address again, for the live-update hub |
| `GENIE_REVERSE_PROXY` | the proxy's IP address |
| `APP_ENV`, `APP_DEBUG`, `APP_SECRET` | `prod`, `0` and a random string |
| `SYMFONY_TRUSTED_PROXIES` | the proxy's IP address |

## Replay the demo

`bin/replay codex` or `bin/replay claude` plays the video's story with one assistant for all four players. `demo/replay.md` lists its steps, and you can paste them into your own assistants instead.

## Play

1. The host starts the game and uploads a photo. `demo/holiday-party.jpg` is ready to use. A photo where the detector finds nobody is refused.
2. The others join from the invite link with a name only. A game takes 12 players.
3. A local detector boxes the people in the photo. One player asks their assistant to trace them.
4. Each player tells their assistant which person they are, or clicks their face on the web view.
5. Players make wishes and repaint their whole person, as pixel art if they like.
6. If two wishes cannot both hold, the page names the players in the way.
7. Each player approves the picture on a page that shows it. Everyone who joined approves, including a guest who has not claimed a place yet. Any later change cancels the approvals.
8. When everyone has approved, the game closes for good and the page offers the download.

A game with no change for a day is abandoned and turns read-only. Nothing is deleted.

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

| Tool | What it does |
|---|---|
| `my_status` | Names the signed-in player |
| `session_get` | Describes the session, without image data |
| `photo_get` | Returns the photo, the shared picture, the tracing grid, or what you can paint under a grid |
| `scan_photo` | Reports the people, their outlines and the objects found in the photo |
| `claim_place` | Takes a found person as yours, or corrects your head or box |
| `make_wish` | Sends a wish to the genie |
| `paint_me` | Repaints your whole person and a one-block safe zone, with optional pixel art, and returns the result |
| `wait_for_update` | Waits up to 10 seconds for a change, approvals included |
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

The site works without one. Wishes then go through a keyword reader. `GENIE_MODEL_URL` in Drupal's environment names another model server to try first, such as a GPU box. Ollama on this machine answers when it fails, then the keyword reader. With [Ollama](https://ollama.com) on its default port, `ollama pull gemma3:4b` (3.4 GB) lets a local model read free-form wishes such as "I deserve the spotlight". The model only picks an operation for the speaker. It refuses requests about other people. Symfony AI turns its answer into a validated PHP object. An invalid answer changes nothing, and the genie asks again.

## How it fits together

- Symfony 8 in `symfony/` is the web view. It signs players in as an OAuth client of Drupal and calls Drupal's tools with the PHP MCP SDK: `symfony/src/Drupal/McpDrupalTools.php`.
- Drupal in `drupal/` holds the rules, in the module `genie_ledger`. Drupal's `mcp_server` module serves assistants directly.
- Drupal AI runs the local wish reader on Symfony AI's platform: `WishInterpreter.php`.
- Symfony AI's TransformersPhp bridge runs DETR in PHP to box people: `PersonDetector.php`. It needs PHP's FFI extension, which the Drupack build adds, and a 42 MB model from Hugging Face. Without them, assistants find the people.
- Every photo is scaled to a 1600-pixel long side, so blocks look the same on every photo.
- Drupal gives each grid cell to one person, and the person in front wins: `Outlines.php`. Paint reaches a player's own cells and one block of background around them. A player with no outline can paint everything except other people.
- Each game is a node. A `state_machine` field moves it from start to open, then closed or abandoned. A JSON field holds the play state.
- Each player is an account named `NAME@NID` that references its game, so two games can each have an Alice.
- A revisionable Media item holds the photo, the detector's boxes, the people and the objects, as `compound_field` rows. The game points to one revision.
- Drupack serves Drupal and its Mercure hub, which tells each browser when the session changes. Its FrankenPHP also serves the web view.
- Assistants and the web view get images through MCP tools. Symfony keeps no game data. Only the upload uses an HTTP route, which accepts only the web view's token. The web view is a confidential OAuth client, and `bin/start` gives it a new secret in `.env.local`.

## Features used

Each line names a library feature and links to the code that uses it.

### Symfony AI

- Platform `invoke()` with a `MessageBag`, on the platform Drupal AI builds from config: [`WishInterpreter.php`](drupal/web/modules/custom/genie_ledger/src/WishInterpreter.php).
- The generic platform bridge reaches Ollama's OpenAI-style API: [`ai.inference_provider.genie_ollama.yml`](drupal/web/modules/custom/genie_ledger/config/install/ai.inference_provider.genie_ollama.yml).
- Structured output: `PlatformSubscriber` turns the model's answer into a `WishReading` object: [`genie_ledger.services.yml`](drupal/web/modules/custom/genie_ledger/genie_ledger.services.yml), [`WishReading.php`](drupal/web/modules/custom/genie_ledger/src/WishReading.php).
- The `#[Schema]` attribute limits the generated JSON schema to the allowed operations: [`WishReading.php`](drupal/web/modules/custom/genie_ledger/src/WishReading.php).
- `ValidatorSubscriber` checks the object with Symfony Validator constraints. A `ValidationException` makes the genie ask again: [`WishInterpreter.php`](drupal/web/modules/custom/genie_ledger/src/WishInterpreter.php).
- The TransformersPhp bridge runs DETR object detection in PHP through FFI: [`PersonDetector.php`](drupal/web/modules/custom/genie_ledger/src/PersonDetector.php).
- `FailoverPlatform` tries the next model server when one fails, wrapped as a Drupal AI platform middleware: [`Failover.php`](drupal/web/modules/custom/genie_ledger/src/Plugin/ai/PlatformMiddleware/Failover.php), [`ai.platform.genie.yml`](drupal/web/modules/custom/genie_ledger/config/install/ai.platform.genie.yml).
- `CachePlatform` answers a repeated wish without the model, under a `prompt_cache_key`: [`Cache.php`](drupal/web/modules/custom/genie_ledger/src/Plugin/ai/PlatformMiddleware/Cache.php), [`WishInterpreter.php`](drupal/web/modules/custom/genie_ledger/src/WishInterpreter.php).
- `MockPlatformFactory` replaces the model in a unit test: [`WishInterpreterTest.php`](drupal/web/modules/custom/genie_ledger/tests/src/Unit/WishInterpreterTest.php).

### PHP MCP SDK

- Client: `Client::builder()`, `HttpTransport` with the player's bearer token, `callTool()`, `structuredContent` and `ImageContent`: [`McpDrupalTools.php`](symfony/src/Drupal/McpDrupalTools.php).
- Server tools with input schemas, output schemas and annotations, through Drupal's `mcp_server` module: [`Plugin/mcp_server/Tool/`](drupal/web/modules/custom/genie_ledger/src/Plugin/mcp_server/Tool/).
- Output schemas the SDK checks on approval results: [`ApproveExportTool.php`](drupal/web/modules/custom/genie_ledger/src/Plugin/mcp_server/Tool/ApproveExportTool.php), [`ExportApproveTool.php`](drupal/web/modules/custom/genie_ledger/src/Plugin/mcp_server/Tool/ExportApproveTool.php), [`Approvals.php`](drupal/web/modules/custom/genie_ledger/src/Approvals.php).
- `ImageContent` results for the photo and the picture: [`LedgerToolBase.php`](drupal/web/modules/custom/genie_ledger/src/Plugin/mcp_server/Tool/LedgerToolBase.php).
- URL elicitation: `ClientGateway::elicitUrl()`, `ElicitResult` and `ElicitationMode::Url` open the approval page: [`ApproveExportTool.php`](drupal/web/modules/custom/genie_ledger/src/Plugin/mcp_server/Tool/ApproveExportTool.php).
- A custom request and notification on the SDK's JSON-RPC classes: [`UrlElicitationRequest.php`](drupal/web/modules/custom/genie_ledger/src/Mcp/UrlElicitationRequest.php), [`ElicitationCompleteNotification.php`](drupal/web/modules/custom/genie_ledger/src/Mcp/ElicitationCompleteNotification.php).
- `ProtocolVersion::isModern()` on the Streamable HTTP version header picks the elicitation path: [`ApproveExportTool.php`](drupal/web/modules/custom/genie_ledger/src/Plugin/mcp_server/Tool/ApproveExportTool.php).
- `ToolCallException` returns a refusal the assistant can read: [`MakeWishTool.php`](drupal/web/modules/custom/genie_ledger/src/Plugin/mcp_server/Tool/MakeWishTool.php) and the other tools.
- Resource subscriptions: each game is a resource, and `Psr16NotificationBus` carries `notifications/resources/updated` to `subscriptions/listen` streams: [`GameResource.php`](drupal/web/modules/custom/genie_ledger/src/Plugin/mcp_server/ResourceProvider/GameResource.php), [`Ledger.php`](drupal/web/modules/custom/genie_ledger/src/Ledger.php), [`mcp_server-notification-bus.patch`](drupal/patches/mcp_server-notification-bus.patch).
- Tools listed by game state, and `notifications/tools/list_changed` when the state moves: [`LedgerToolBase.php`](drupal/web/modules/custom/genie_ledger/src/Plugin/mcp_server/Tool/LedgerToolBase.php).
- The smoke scripts call the server as an MCP client: [`SmokeSite.php`](symfony/tests/smoke/lib/SmokeSite.php).

### Drupal and Drupack

- `mcp_server` serves the tools at `/mcp`. `mcp_server_oauth`, `simple_oauth` and `simple_oauth_21` sign each assistant in as its player: [`McpRouteSubscriber.php`](drupal/web/modules/custom/genie_ledger/src/Routing/McpRouteSubscriber.php).
- A patch lets native clients use `http://localhost` redirect URIs: [`simple_oauth_21-localhost-redirect.patch`](drupal/patches/simple_oauth_21-localhost-redirect.patch).
- Each game is a `genie_game` node with a `state_machine` workflow: [`genie_ledger.workflows.yml`](drupal/web/modules/custom/genie_ledger/genie_ledger.workflows.yml), [`Games.php`](drupal/web/modules/custom/genie_ledger/src/Games.php).
- The host code is a Key entity read from a file. Core's flood service limits wrong host codes and invite links: [`HostForm.php`](drupal/web/modules/custom/genie_ledger/src/Form/HostForm.php), [`JoinForm.php`](drupal/web/modules/custom/genie_ledger/src/Form/JoinForm.php).
- Cron abandons idle games, and `hook_user_format_name_alter` shows players without their game id: [`GenieHooks.php`](drupal/web/modules/custom/genie_ledger/src/Hook/GenieHooks.php).
- The site runs on the `main` branches of Symfony AI and the MCP SDK, with patches for what broke: [`docs/upstream-breaks.md`](docs/upstream-breaks.md).
- Drupal AI holds the platform and the Ollama provider as config: [`ai.platform.genie.yml`](drupal/web/modules/custom/genie_ledger/config/install/ai.platform.genie.yml).
- A revisionable Media item holds the photo, with `compound_field` rows for people and objects: [`PhotoStore.php`](drupal/web/modules/custom/genie_ledger/src/PhotoStore.php), [`Session.php`](drupal/web/modules/custom/genie_ledger/src/Session.php).
- FrankenPHP's `mercure_publish()` announces each new revision: [`RevisionPublisher.php`](drupal/web/modules/custom/genie_ledger/src/RevisionPublisher.php).
- Drupack runs Drupal, its Mercure hub, the web view and Composer. The project's build adds FFI: [`fork/drupack/`](fork/drupack/), [`bin/lib/get-drupack`](bin/lib/get-drupack).

### Symfony

- Security with a custom authenticator, an OAuth client of Drupal with PKCE and token refresh: [`DrupalAuthenticator.php`](symfony/src/Security/DrupalAuthenticator.php), [`DrupalOAuth.php`](symfony/src/Security/DrupalOAuth.php).
- The browser subscribes to the Mercure hub with `EventSource`: [`GameController.php`](symfony/src/Controller/GameController.php), [`app.js`](symfony/assets/app.js), [`review.js`](symfony/assets/review.js).
- AssetMapper and importmap load the JavaScript with no Node build: [`importmap.php`](symfony/importmap.php).

## Checks

| Command | What it checks |
|---|---|
| `bin/qa` | Coding standards, PHPStan level 3 on Drupal code, linters, unit tests |
| `bin/smoke oauth alice` | A new game, sign-in and one MCP call |
| `bin/smoke host` | The host code, the upload on the web view, and a guest joining from the invite link |
| `bin/smoke subscribe` | A resource update reaches a `subscriptions/listen` stream |
| `bin/smoke live` | Upload, a live update and the picture over MCP |
| `bin/smoke wish alice` | A wish changes the picture |
| `bin/smoke collision` | Two wishes that cannot both hold |
| `bin/smoke scan` | An assistant finds people, claims one and paints, and ids survive a new scan |
| `bin/smoke outline` | The detector finds people, and paint reaches the safe zone but no neighbour |
| `bin/smoke review` | The approval page: return after sign-in, wrong player, changed picture |
| `bin/smoke approve` | Approval, URL elicitation, output schemas, what an assistant cannot reach, and the closed game |
| `bin/verify-clone` | A fresh clone starts and grants a wish, with ports 7225 and 8000 free |
| `bin/verify-loopback` | Both servers listen on loopback only |

`bin/smoke NAME` runs one smoke script on Drupack's PHP, such as `bin/smoke wish alice`. `bin/qa` uses the machine's PHP 8.4 or later. Each smoke script starts its own game. `drush genie:seats alice bob` does the same by hand and prints the accounts and passwords.

## License

GPL-2.0-or-later, the license of Drupal. See `LICENSE`.

`demo/holiday-party.jpg` is "Holiday Coronavirus" by PS Imaging, from [StockSnap](https://stocksnap.io/photo/holiday-coronavirus-OWPQGSY7C6), under CC0. It is enlarged to 1600 pixels wide.
