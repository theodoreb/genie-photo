<?php

declare(strict_types=1);

namespace Drupal\genie\Plugin\ai\PlatformMiddleware;

use Drupal\ai\Attribute\AiPlatformMiddleware;
use Drupal\ai\Plugin\ai\PlatformMiddleware\AiPlatformMiddlewarePluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\AI\Platform\Bridge\Cache\CachePlatform;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Answers a repeated request from the private files, without the model.
 *
 * Only a call that passes the `prompt_cache_key` option is cached.
 */
#[AiPlatformMiddleware(
  id: 'genie_cache',
  label: new TranslatableMarkup('Cache'),
  description: new TranslatableMarkup('Keeps answers to requests that name a prompt_cache_key.'),
)]
final class Cache extends AiPlatformMiddlewarePluginBase {

  /**
   * The folder of the cached answers.
   */
  protected string $directory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->directory = $container->get('file_system')->realpath('private://') . '/ai-cache';
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function decorate(PlatformInterface $inner): PlatformInterface {
    return new CachePlatform($inner, cache: new TagAwareAdapter(new FilesystemAdapter('genie', 0, $this->directory)));
  }

}
