<?php

declare(strict_types=1);

namespace Drupal\genie\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\genie\Game\Games;
use Drupal\genie\Photo\Archive;
use Drupal\genie\Photo\Moderation;
use Drupal\genie\Photo\NsfwClassifier;
use Drupal\genie\Photo\PersonDetector;
use Drupal\genie\Web\WebViewAccess;
use Drupal\genie\Wish\WishInterpreter;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Commands for the genie games.
 */
final class GenieCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Name of the account that looks at games for the web view.
   */
  private const VISITOR = 'genie visitor';

  public function __construct(
    private readonly Games $games,
    private readonly WishInterpreter $interpreter,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PersonDetector $detector,
    private readonly FileSystemInterface $fileSystem,
    private readonly Archive $archive,
    private readonly NsfwClassifier $classifier,
    private readonly Moderation $moderation,
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
   * Prints the NSFW score of each image as JSON. The moderation runs this.
   */
  #[CLI\Command(name: 'genie:classify-files')]
  #[CLI\Argument(name: 'files', description: 'Paths of images.')]
  public function classifyFiles(array $files): void {
    $this->output()->writeln(json_encode($this->classifier->score($files), JSON_THROW_ON_ERROR));
  }

  /**
   * Checks the final pictures no check has seen yet, as cron does.
   */
  #[CLI\Command(name: 'genie:moderate')]
  public function moderate(): void {
    $this->moderation->run();
    $this->logger()->success(sprintf('%d final pictures withheld.', count($this->moderation->flagged())));
  }

  /**
   * Lists the games whose final picture is withheld.
   */
  #[CLI\Command(name: 'genie:flagged')]
  public function flagged(): void {
    $this->output()->writeln(json_encode($this->moderation->flagged(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
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
   * Opens games on prepared photos until enough of them are open.
   */
  #[CLI\Command(name: 'genie:archive-fill')]
  public function fillArchive(): void {
    $this->logger()->success(sprintf('%d prepared games opened.', $this->archive->fill()));
  }

  /**
   * Gives the web view's OAuth client a new secret and prints it.
   *
   * Players sign in through the client. With its own token, the web view
   * acts as the visitor account, which only looks at games.
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
    ]);
    $secret = bin2hex(random_bytes(32));
    $client
      ->set('grant_types', ['authorization_code', 'refresh_token', 'client_credentials'])
      ->set('scopes', [['scope_id' => 'genie_view']])
      ->set('user_id', $this->visitor())
      // The web view is this site's own page, so its sign-in asks no consent.
      ->set('automatic_authorization', TRUE)
      ->set('secret', $secret)
      ->set('redirect', $callback)
      ->save();
    $this->output()->writeln($secret);
  }

  /**
   * Returns the id of the account the web view's own token acts as.
   */
  private function visitor(): int {
    $users = $this->entityTypeManager->getStorage('user');
    $found = $users->loadByProperties(['name' => self::VISITOR]);
    $visitor = reset($found) ?: $users->create(['name' => self::VISITOR, 'status' => 1]);
    if ($visitor->isNew()) {
      // Nobody signs in as the visitor, so nobody learns this password.
      $visitor->setPassword(bin2hex(random_bytes(24)));
      $visitor->addRole('genie_visitor');
      $visitor->save();
    }
    return (int) $visitor->id();
  }

}
