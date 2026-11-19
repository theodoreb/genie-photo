<?php

declare(strict_types=1);

namespace Drupal\genie\Plugin\mcp_server\Tool\WebView;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\genie\Photo\Pictures;
use Drupal\genie\Plugin\mcp_server\Tool\WebViewToolBase;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Server\ClientGateway;
use Mcp\Server\Exception\ToolCallException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Gives the web view the images it shows.
 */
#[Tool(
  id: 'web_picture_get',
  label: new TranslatableMarkup('Get an image for the web view'),
  description: new TranslatableMarkup('Returns the original photo, or the shared picture of the given revision.'),
  inputSchema: [
    'type' => 'object',
    'properties' => [
      'view' => ['type' => 'string', 'enum' => ['photo', 'frame']],
      'revision' => ['type' => 'integer'],
    ],
    'required' => ['view'],
  ],
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class PictureGetTool extends WebViewToolBase {

  /**
   * {@inheritdoc}
   */
  protected const STATES = ['open', 'closed', 'abandoned'];

  /**
   * Renders the images.
   */
  private Pictures $pictures;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $tool = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $tool->pictures = $container->get(Pictures::class);
    return $tool;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    try {
      return $this->imageResult($this->pictures->webPicture((string) ($arguments['view'] ?? ''), $arguments['revision'] ?? NULL));
    }
    catch (\InvalidArgumentException $e) {
      throw new ToolCallException($e->getMessage(), previous: $e);
    }
  }

}
