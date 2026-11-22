# On a public server

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
| `OPENAI_API_KEY` | optional, in Drupal's environment: OpenAI also checks the final pictures of prepared games |
