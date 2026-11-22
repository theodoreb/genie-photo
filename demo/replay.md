# Replay the demo

This replay tells a story on `demo/holiday-party.jpg`. Four friends wear Santa hats and surgical masks, so each one asks an assistant to paint a smile. Then Alice asks to be blurred, and everyone approves the new picture.

## Run it with one assistant

Start the site first, with `bin/start` or `docker compose up`. Then run one of these in a terminal:

```sh
bin/replay codex
bin/replay claude
```

The script plays all four players with the assistant you name. It starts a new game, signs the assistant in as `genie-alice`, `genie-bob`, `genie-carol` and `genie-dan`, then sends each step below. To watch the picture change, sign in to the web view with alice's account and password, which the script prints.

- Codex 0.170 and Claude Code 2.1.328 were tested. The run takes about ten minutes and uses your assistant's own account.
- The sign-ins go to a separate folder, `var/replay`. Your usual assistant configuration does not change.
- Claude Code signs in only from a terminal. Codex also signs in when an agent runs the script.
- The assistants cannot approve for their players. The script clicks the approval page for them.
- The final picture goes to `var/replay/final.png`.

## Play it by hand

Sign in one assistant per player, as the README shows, and paste each prompt into that player's assistant. Codex, Claude Code and Mistral Vibe all work. Each player approves on the page that `approve_export` gives them.

## Steps

1. alice uploads `demo/holiday-party.jpg`.
2. alice: Use my genie tools: the group photo is up. Find everyone in it, then claim my place. I'm the one in the yellow sweater.
3. bob: Use my genie tools: I'm the one in the red shirt, claim my place. My mask hides my smile, so paint a huge cheesy grin with teeth on it. Look at my head with the grid first, and stay on the mask. Make no wish.
4. carol: Use my genie tools: I'm the one in the striped shirt, claim my place. Paint a cat nose, a cat mouth and whiskers on my mask. Look at my head with the grid first, and stay on the mask. Make no wish.
5. dan: Use my genie tools: I'm the one in the blue denim shirt, claim my place. Look at my head with the grid, then paint one big solid round red clown nose in the middle of my mask. Make no wish.
6. alice: Use my genie tools: paint a curly black handlebar moustache on my mask. Look at my head with the grid first, and stay on the mask. Make no wish.
7. carol approves on the page.
8. alice: Use my genie tools: blur me.
9. bob approves on the page.
10. alice approves on the page.
11. carol approves on the page.
12. dan approves on the page.

Step 8 changes the picture, so Carol's approval from step 7 is gone and she approves again.
