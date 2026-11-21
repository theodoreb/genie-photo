<?php

declare(strict_types=1);

namespace Drupal\genie\Plugin\mcp_server\Tool\Assistant;

use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\genie\Plugin\mcp_server\Tool\LedgerToolBase;
use Drupal\genie\Wish\Wishes;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;
use Mcp\Server\Exception\ToolCallException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Passes a player's wish to the genie.
 */
#[Tool(
  id: 'make_wish',
  label: new TranslatableMarkup('Make a wish'),
  description: new TranslatableMarkup('Tells the genie, in plain words, how you want to appear in the shared photo. A new wish replaces your earlier one.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'wish' => [
        'type' => 'string',
        'minLength' => 1,
        'maxLength' => MakeWishTool::MAX_LENGTH,
        'description' => 'One short sentence, for example: hide my face.',
      ],
    ],
    'required' => ['wish'],
  ],
  readOnly: FALSE,
  destructive: FALSE,
  idempotent: FALSE,
  openWorld: FALSE,
)]
final class MakeWishTool extends LedgerToolBase {

  public const MAX_LENGTH = 120;

  /**
   * Wishes one player may make in each window.
   */
  private const WISHES = 10;

  /**
   * Seconds of the window, ten minutes.
   */
  private const WINDOW = 600;

  private const FLOOD = 'genie.wish';

  /**
   * Grants the player's wishes.
   */
  private Wishes $wishes;

  /**
   * Counts each player's wishes.
   */
  private FloodInterface $flood;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $tool = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $tool->wishes = $container->get(Wishes::class);
    $tool->flood = $container->get('flood');
    return $tool;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    // A wish can reach the local model, so its size and pace are capped.
    $wish = is_string($arguments['wish'] ?? NULL) ? trim($arguments['wish']) : '';
    if ($wish === '') {
      throw new ToolCallException('Say what you wish for, in one short sentence.');
    }
    if (mb_strlen($wish) > self::MAX_LENGTH) {
      throw new ToolCallException(sprintf('Keep a wish to %d characters.', self::MAX_LENGTH));
    }
    $uid = (int) $this->currentUser->id();
    if (!$this->flood->isAllowed(self::FLOOD, self::WISHES, self::WINDOW, "player:$uid")) {
      throw new ToolCallException(sprintf('The genie grants %d wishes every %d minutes. Wait a little.', self::WISHES, self::WINDOW / 60));
    }
    $this->flood->register(self::FLOOD, self::WINDOW, "player:$uid");
    return $this->wishes->grant($uid, $this->currentUser->getDisplayName(), $wish);
  }

}
