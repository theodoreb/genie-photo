# Breaks found on the pre-release libraries

The game runs on the `main` branches of Symfony AI and the PHP MCP SDK. This file lists what broke, how to reproduce it, and the fix this repo carries. Each entry is a draft for an upstream report.

Versions in use:

| Package | Version |
|---|---|
| `symfony/ai-platform` | `dev-main` at `99e4396` |
| `symfony/ai-generic-platform` | `dev-main` at `904e270` |
| `symfony/ai-transformers-php-platform` | `dev-main` at `9db5475` |
| `symfony/ai-failover-platform` | `dev-main` at `e9314d8` |
| `symfony/ai-cache-platform` | `dev-main` at `7859826` |
| `mcp/sdk` | `dev-main` at `3d2e831`, which reads 0.11.0 |
| `drupal/ai` | `2.x` at `5d6ae6f` |
| `drupal/mcp_server` | `2.0.0-beta7` |

## 1. mcp_server: classes moved by mcp/sdk 0.10

- Target: `drupal/mcp_server`.
- Symptom: `Mcp\Server`, `Mcp\Capability\*`, `Mcp\Event\*` and `Mcp\Exception\*` no longer exist. The module fatals when it builds the server.
- Cause: mcp/sdk 0.10 split its root namespace into `Mcp\Schema`, `Mcp\Client` and `Mcp\Server`. Its `CHANGELOG.md` lists each move.
- Reproduce: install `mcp_server` 2.0.0-beta7 with `mcp/sdk` 0.10.0, then request `/mcp`.
- Fix: `drupal/patches/mcp_server-mcp-sdk-0.10.patch` renames the imports in 16 files and allows `mcp/sdk ^0.10 || ^0.11`.

## 2. mcp_server: nested tool arguments arrive as objects

- Target: `drupal/mcp_server`.
- Symptom: a tool that reads `$arguments['people'][0]['description']` fails with "Error while executing tool". The client sees no other detail.
- Cause: since mcp/sdk 0.10, a JSON object nested in `tools/call` arguments reaches `ToolHandlerInterface::execute()` as `\stdClass` (`CHANGELOG.md`, 0.10.0). Tool plugins in Drupal read nested values as arrays.
- Reproduce: call any tool whose input schema has an array of objects, such as genie's `scan_photo`.
- Fix: the same patch adds `ArrayArgumentsReferenceHandler`. It turns nested `\stdClass` back into arrays for tool calls, and leaves the SDK's `_session` and `_request` objects alone.
- Upstream question: keep arrays as the plugin contract, or move plugins to `\stdClass`.

## 3. drupal/ai: pinned to Symfony AI 0.14

- Target: `drupal/ai`.
- Symptom: Composer refuses Symfony AI 1.0 next to `drupal/ai` 2.x.
- Cause: `drupal/ai` requires `symfony/ai-platform ^0.14.1` and `symfony/ai-generic-platform ^0.14`.
- Fix here: `drupal/composer.json` defines `drupal/ai` as a `package` repository at the same commit, requiring `^1.0`. No code change was needed for the providers this site uses.
- Also: the module's tests use `Symfony\AI\Platform\Test\InMemoryPlatform`, which Symfony AI removed in favour of `Test\MockPlatformFactory::createPlatform()`.

## 4. symfony/ai: split packages on main declare no branch alias

- Target: `symfony/ai`.
- Symptom: requiring `symfony/ai-platform: dev-main` and `symfony/ai-generic-platform: dev-main` fails. The generic platform requires `symfony/ai-platform ^1.0`, and `dev-main` does not satisfy it.
- Cause: the split packages' `composer.json` has no `extra.branch-alias`, so Composer reads `dev-main` as no version at all.
- Reproduce: a new project requiring both packages at `dev-main`.
- Work-around here: `"symfony/ai-platform": "dev-main as 1.0.0"`, and the same for the generic platform.
- Proposed fix: `"extra": {"branch-alias": {"dev-main": "1.0.x-dev"}}` in each package.

## 5. symfony/ai: FailoverPlatform never fails over for HTTP platforms

- Target: `symfony/ai-failover-platform`.
- Symptom: with a dead first platform, `asText()` throws the first platform's `TransportException`. The second platform is never tried.
- Cause: `invoke()` returns the first platform's `DeferredResult`. Its HTTP request runs only when the result is read, outside the failover's `try`.
- Reproduce: a `FailoverPlatform` over a Generic platform on `http://127.0.0.1:9` and a working one, then `invoke()` and `asText()`.
- Fix: `drupal/patches/symfony-ai-failover-platform-deferred.patch` reads the result inside the `try`.

## 6. symfony/ai: FailoverPlatform retries a failed platform on every call

- Target: `symfony/ai-failover-platform`.
- `do()` records failed platforms but never skips one, so every call tries the dead platform first.
- Rate limiters are keyed by `$platform::class`. Two platforms of one class, such as two Drupal AI platforms, share one limiter.
- No patch here. A connection refused costs no time on this site.

## 7. symfony/ai: CachePlatform cannot store an object that holds an enum

- Target: `symfony/ai-cache-platform`.
- Symptom: a structured output call with `prompt_cache_key` fails with "Cannot normalize attribute "target" because the injected serializer is not a normalizer".
- Cause: the `ObjectNormalizer` inside `ResultNormalizer` never gets a serializer, and the default serializer lists no normalizer for enums, dates or nested objects.
- Fix: `drupal/patches/symfony-ai-cache-platform-nested-objects.patch` hands the serializer to the inner normalizer and adds those three normalizers.

## 8. drupal/ai: config entities cannot be duplicated

- Target: `drupal/ai`.
- Symptom: `createDuplicate()` on an `ai_inference_provider` throws "Cannot assign null to property AiInferenceProviderConfig::$id of type string".
- Cause: core sets the id to NULL on a duplicate, and the entity types `$id` as a non-nullable string.
- No patch here. This site creates the entities from arrays.

## 9. mcp_server: no notification reaches a client

- Target: `drupal/mcp_server`.
- The `NotificationProvider` plugin type ships the contract only. The module never gives the SDK a notification bus, so `subscriptions/listen` acknowledges an empty filter and `resources.subscribe` is never usable.
- Fix: `drupal/patches/mcp_server-notification-bus.patch` builds the SDK's `Psr16NotificationBus` on Drupal's expirable key-value store and passes it to `setNotificationBus()`.
- `bin/smoke-subscribe` checks that an update reaches a listen stream.

## 10. mcp/sdk: a listen stream subscribes to any resource URI

- Target: `mcp/sdk`.
- `NotificationFilter` agrees to every URI in `resourceSubscriptions` without asking whether the client may read that resource.
- A player who learns another game's URI learns when that game changes, though not what changed.
- Proposal: let the server check each URI against resource access before it acknowledges the filter.

## 11. mcp/sdk: handshake-era subscriptions have no delivery path

- Target: `mcp/sdk`.
- `resources/subscribe` stores the URI in the session. `SessionSubscriptionManager::notifyResourceChanged()` needs the `Protocol` and that session, and nothing finds the sessions that subscribed to a URI.
- The notification bus serves `subscriptions/listen` on the 2026-07-28 revision only. This site therefore keeps `wait_for_update`, now 10 seconds long.
