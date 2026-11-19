<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\genie_ledger\Access\WebViewAccess;
use Drupal\genie_ledger\Ledger;
use Drupal\genie_ledger\PersonDetector;
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
    private readonly WishInterpreter $interpreter,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly PersonDetector $detector,
    private readonly FileSystemInterface $fileSystem,
  ) {
    parent::__construct();
  }

  /**
   * Finds the people in the session photo. The photo upload starts this.
   */
  #[CLI\Command(name: 'genie:detect')]
  public function detect(): void {
    $survey = $this->ledger->read()->survey;
    if ($survey?->detector !== 'pending') {
      return;
    }
    $status = 'failed';
    $boxes = [];
    try {
      $boxes = $this->detector->detect($this->fileSystem->realpath(PhotoStore::URI));
      $status = 'ready';
    }
    catch (\Throwable $e) {
      $this->logger()->error($e->getMessage());
    }
    $this->ledger->recordDetection($survey->media, $status, $boxes);
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
   * Starts over with no photo and no regions.
   */
  #[CLI\Command(name: 'genie:reset')]
  public function reset(): void {
    $this->ledger->reset();
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
