<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\mcp_server\Tool\Assistant;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\genie_ledger\Photo\BodyPaint;
use Drupal\genie_ledger\Photo\Pictures;
use Drupal\genie_ledger\Plugin\mcp_server\Tool\LedgerToolBase;
use Drupal\genie_ledger\Wish\Wishes;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;
use Mcp\Server\Exception\ToolCallException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Paints the caller's whole person.
 */
#[Tool(
  id: 'paint_me',
  label: new TranslatableMarkup('Paint my whole person'),
  description: new TranslatableMarkup('Repaints your whole person in the shared photo. Call photo_get with view my_body first. Its "editable" rectangles and bright image area are the only cells you can change: your person and a one-block safe zone of background, or the whole photo around other people when you have no outline. "on_person" lists the cells on your person. Set pixelate to true to turn your person into pixel art. Add spans to paint on top: each span is [row, first column, last column, cell] on the numbered fine grid. A cell is a hex digit that picks a palette color, "*" for the photo pixels or "." to leave it. A new paint replaces the old one, and pixelate false with no spans removes it. The result shows what you can paint, so you can check it and correct it.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'pixelate' => ['type' => 'boolean'],
      'spans' => [
        'type' => 'array',
        'description' => 'Runs of fine cells as [row, first column, last column, cell], such as [120, 40, 64, "0"].',
        'items' => ['type' => 'array', 'minItems' => 4, 'maxItems' => 4],
      ],
      'palette' => [
        'type' => 'array',
        'items' => ['type' => 'string', 'description' => 'A color such as #ff8800.'],
        'maxItems' => BodyPaint::MAX_COLORS,
      ],
    ],
  ],
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class PaintMeTool extends LedgerToolBase {

  /**
   * Grants the player's wishes.
   */
  private Wishes $wishes;

  /**
   * Renders the images.
   */
  private Pictures $pictures;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $tool = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $tool->wishes = $container->get(Wishes::class);
    $tool->pictures = $container->get(Pictures::class);
    return $tool;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    try {
      $paint = BodyPaint::fromInput($arguments['pixelate'] ?? NULL, $arguments['palette'] ?? NULL, $arguments['spans'] ?? NULL);
    }
    catch (\InvalidArgumentException $e) {
      throw new ToolCallException($e->getMessage(), previous: $e);
    }
    $uid = (int) $this->currentUser->id();
    $outcome = $this->wishes->paint($uid, $this->currentUser->getDisplayName(), $paint);
    if (!$outcome['granted']) {
      return $outcome;
    }
    return $this->imageResult(['info' => $outcome] + $this->pictures->image($uid, 'my_body'));
  }

}
