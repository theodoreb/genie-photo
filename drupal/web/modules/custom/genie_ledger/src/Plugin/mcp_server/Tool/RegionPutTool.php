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
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    $uid = (int) $this->currentUser->id();
    $name = $this->currentUser->getAccountName();
    $session = $this->ledger->write(function (Session $session) use ($arguments, $uid, $name): void {
      $box = $this->box($arguments, $session);
      if ($arguments['kind'] === 'person') {
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

}
