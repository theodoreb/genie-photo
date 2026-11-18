<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\genie_ledger\Access\WebViewAccess;
use Drupal\genie_ledger\BoxProposer;
use Drupal\genie_ledger\Ledger;
use Drupal\genie_ledger\PhotoIntake;
use Drupal\genie_ledger\PhotoStore;
use Drupal\genie_ledger\WishInterpreter;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Commands for the genie session.
 */
final class GenieCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly Ledger $ledger,
    private readonly PhotoStore $photos,
    private readonly WishInterpreter $interpreter,
    private readonly BoxProposer $proposer,
    private readonly PhotoIntake $intake,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Starts over with no photo and no regions.
   */
  #[CLI\Command(name: 'genie:reset')]
  public function reset(): void {
    $this->ledger->reset();
    $this->photos->delete();
    $this->logger()->success('The genie session is empty.');
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
   * Shows the boxes the local vision model proposes for an image file.
   */
  #[CLI\Command(name: 'genie:propose-boxes')]
  #[CLI\Argument(name: 'file', description: 'Path of the image.')]
  public function proposeBoxes(string $file): void {
    $photo = $this->intake->normalize(file_get_contents($file));
    $boxes = $this->proposer->propose($photo['jpeg'], $photo['width'], $photo['height']);
    $this->output()->writeln($boxes === NULL ? 'unavailable' : json_encode($boxes, JSON_THROW_ON_ERROR));
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
