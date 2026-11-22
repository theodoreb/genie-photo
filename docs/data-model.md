# Data model

Drupal holds every game. The module `genie` in `drupal/web/modules/custom/genie` stores it as follows.

- Every photo is scaled to a 1600-pixel long side, so blocks look the same on every photo.
- Drupal gives each grid cell to one person, and the person in front wins: `Outlines.php`. Paint reaches a player's own cells and one block of background around them. A place claimed on the page gets its outline from the next trace.
- Each game is a node. A `state_machine` field moves it from start to open, then closed or abandoned. A JSON field holds the play state.
- Each player is an account named `NAME@NID` that references its game, so two games can each have an Alice.
- A revisionable Media item holds the photo, the detector's boxes and the people, as `compound_field` rows. The game points to one revision.
- Six prepared games stay open, three on paintings and three on photos: `Archive.php`. Each `kind` in `archive/manifest.json` has its own turn, over the entries whose `rotation` is true. `drush genie:archive-fill` opens them, and `bin/start` runs it. The first three pictures of each kind in the rotation ship with the module. The others come from Wikimedia Commons when their turn comes, checked against the SHA-1 in the manifest.
