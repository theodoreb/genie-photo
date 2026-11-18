<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\genie_ledger\Session;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Exception\ToolCallException;
use Mcp\Server\ClientGateway;

/**
 * Marks the caller's own region, or an object when the caller is the uploader.
 */
#[Tool(
  id: 'region_put',
  label: new TranslatableMarkup('Mark a region'),
  description: new TranslatableMarkup('Marks your own place in the photo. The uploader can also mark a named object.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'kind' => ['type' => 'string', 'enum' => ['person', 'object']],
      'label' => ['type' => 'string', 'description' => 'Name of the object.'],
      'x' => ['type' => 'integer'],
      'y' => ['type' => 'integer'],
      'width' => ['type' => 'integer'],
      'height' => ['type' => 'integer'],
    ],
    'required' => ['kind', 'x', 'y', 'width', 'height'],
  ],
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class RegionPutTool extends LedgerToolBase {

  /**
   * Largest share of the photo one person's region may take.
   */
  private const MAX_PERSON_SHARE = 0.25;

  /**
   * Largest share of another region a player's place may overlap.
   */
  private const MAX_OVERLAP_SHARE = 0.5;

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    $uid = (int) $this->currentUser->id();
    $name = $this->currentUser->getAccountName();
    $session = $this->ledger->write(function (Session $session) use ($arguments, $uid, $name): void {
      $box = $this->box($arguments, $session);
      if ($arguments['kind'] === 'person') {
        $this->assertOwnPlace($box, $uid, $session);
        $session->players[$uid] = $name;
        $session->regions["person-$uid"] = ['kind' => 'person', 'owner' => $uid, 'label' => $name] + $box;
        return;
      }
      if ($session->uploader !== $uid) {
        throw new ToolCallException('Only the uploader marks objects.');
      }
      $label = trim((string) ($arguments['label'] ?? ''));
      if ($label === '' || mb_strlen($label) > 40) {
        throw new ToolCallException('An object needs a name of at most 40 characters.');
      }
      $session->regions['object-' . md5($label)] = ['kind' => 'object', 'owner' => $uid, 'label' => $label] + $box;
    });
    return ['revision' => $session->revision];
  }

  /**
   * Validates a box sent by a client against the photo.
   *
   * @return array{x: int, y: int, width: int, height: int}
   *   The box.
   */
  private function box(array $arguments, Session $session): array {
    if ($session->photo === NULL) {
      throw new ToolCallException('The session has no photo yet.');
    }
    if (!in_array($arguments['kind'] ?? NULL, ['person', 'object'], TRUE)) {
      throw new ToolCallException('A region is a person or an object.');
    }
    $box = [];
    foreach (['x', 'y', 'width', 'height'] as $field) {
      if (!is_int($arguments[$field] ?? NULL)) {
        throw new ToolCallException("The region needs an integer $field.");
      }
      $box[$field] = $arguments[$field];
    }
    if ($box['x'] < 0 || $box['y'] < 0 || $box['width'] < 1 || $box['height'] < 1
      || $box['x'] + $box['width'] > $session->photo['width']
      || $box['y'] + $box['height'] > $session->photo['height']) {
      throw new ToolCallException('The region must lie inside the photo.');
    }
    return $box;
  }

  /**
   * Stops a player from claiming the photo or another player's place.
   *
   * Cover and exclude wishes act on this box.
   *
   * @param array{x: int, y: int, width: int, height: int} $box
   *   The box.
   * @param int $uid
   *   The player marking it.
   * @param \Drupal\genie_ledger\Session $session
   *   The session.
   */
  private function assertOwnPlace(array $box, int $uid, Session $session): void {
    if ($box['width'] * $box['height'] > $session->photo['width'] * $session->photo['height'] * self::MAX_PERSON_SHARE) {
      throw new ToolCallException('Your place can take at most a quarter of the photo.');
    }
    foreach ($session->regions as $region) {
      if ($region['kind'] === 'person' && $region['owner'] === $uid) {
        continue;
      }
      $overlapWidth = min($box['x'] + $box['width'], $region['x'] + $region['width']) - max($box['x'], $region['x']);
      $overlapHeight = min($box['y'] + $box['height'], $region['y'] + $region['height']) - max($box['y'], $region['y']);
      if ($overlapWidth > 0 && $overlapHeight > 0
        && $overlapWidth * $overlapHeight > $region['width'] * $region['height'] * self::MAX_OVERLAP_SHARE) {
        throw new ToolCallException(sprintf('This box covers %s.', $region['label']));
      }
    }
  }

}
