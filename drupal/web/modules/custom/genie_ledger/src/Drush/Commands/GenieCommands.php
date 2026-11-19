<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\genie_ledger\Web\WebViewAccess;
use Drupal\genie_ledger\Game\Games;
use Drupal\genie_ledger\Photo\PersonDetector;
use Drupal\genie_ledger\Wish\WishInterpreter;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Commands for the genie games.
 */
final class GenieCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly Games $games,
    private readonly WishInterpreter $interpreter,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PersonDetector $detector,
    private readonly FileSystemInterface $fileSystem,
  ) {
    parent::__construct();
  }

  /**
   * Prints the people in a JPEG as JSON boxes. The photo upload runs this.
   */
  #[CLI\Command(name: 'genie:detect-file')]
  #[CLI\Argument(name: 'file', description: 'The path of a JPEG.')]
  public function detectFile(string $file): void {
    $this->output()->writeln(json_encode($this->detector->detect($file), JSON_THROW_ON_ERROR));
  }

  /**
   * Starts a game with known passwords, for the smoke tests and the replay.
   *
   * Prints the game id and each seat's account name and password as JSON.
   */
  #[CLI\Command(name: 'genie:seats')]
  #[CLI\Argument(name: 'names', description: 'The host first, then the other players.')]
  public function seats(array $names): void {
    $host = array_shift($names);
    $accounts = [$host => $this->games->start($host)];
    $game = (int) $accounts[$host]->get('field_genie_game')->target_id;
    foreach ($names as $name) {
      $accounts[$name] = $this->games->join($game, $name);
    }
    $seats = [];
    foreach ($accounts as $name => $account) {
      $password = bin2hex(random_bytes(12));
      $account->setPassword($password)->save();
      $seats[$name] = ['account' => $account->getAccountName(), 'password' => $password];
    }
    $this->output()->writeln(json_encode(['game' => $game, 'seats' => $seats], JSON_THROW_ON_ERROR));
  }

  /**
   * Downloads the person detector's model, when this PHP can run it.
   */
  #[CLI\Command(name: 'genie:detector-prepare')]
  public function prepareDetector(): void {
    if (!$this->detector->available()) {
      return;
    }
    $file = $this->fileSystem->tempnam('temporary://', 'genie');
    imagejpeg(imagecreatetruecolor(64, 64), $this->fileSystem->realpath($file));
    $this->detector->detect($this->fileSystem->realpath($file));
    $this->fileSystem->delete($file);
    $this->logger()->success('The person detector is ready.');
  }

  /**
   * Shows how the genie reads a wish, and what understood it.
   */
  #[CLI\Command(name: 'genie:interpret')]
  #[CLI\Argument(name: 'wish', description: 'The words of the wish.')]
  public function interpret(string $wish): void {
    $result = $this->interpreter->interpret($wish, []);
    $this->output()->writeln($result === NULL ? 'unclear' : "{$result->operation->value} ({$result->source})");
  }

  /**
   * Gives the web view's OAuth client a new secret and prints it.
   */
  #[CLI\Command(name: 'genie:web-client')]
  #[CLI\Argument(name: 'callback', description: 'The OAuth callback address of the web view.')]
  public function webClient(string $callback): void {
    $storage = $this->entityTypeManager->getStorage('consumer');
    $found = $storage->loadByProperties(['client_id' => WebViewAccess::CLIENT_ID]);
    /** @var \Drupal\consumers\Entity\ConsumerInterface $client */
    $client = reset($found) ?: $storage->create([
      'client_id' => WebViewAccess::CLIENT_ID,
      'label' => 'Genie web view',
      'confidential' => TRUE,
      'pkce' => TRUE,
      'grant_types' => ['authorization_code', 'refresh_token'],
    ]);
    $secret = bin2hex(random_bytes(32));
    $client->set('secret', $secret)->set('redirect', $callback)->save();
    $this->output()->writeln($secret);
  }

}
