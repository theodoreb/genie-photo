<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\ai\PlatformMiddleware;

use Drupal\ai\Attribute\AiPlatformMiddleware;
use Drupal\ai\Plugin\ai\PlatformMiddleware\AiPlatformMiddlewarePluginBase;
use Drupal\Component\Plugin\Factory\FactoryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Bridge\Failover\FailoverPlatform;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * Tries other AI platforms, in order, when the decorated one fails.
 *
 * Configuration: `fallbacks`, a list of ai_platform ids.
 */
#[AiPlatformMiddleware(
  id: 'genie_failover',
  label: new TranslatableMarkup('Failover'),
  description: new TranslatableMarkup('Sends a request to the next platform when one fails.'),
)]
final class Failover extends AiPlatformMiddlewarePluginBase {

  /**
   * The platform factory, which builds the fallbacks.
   */
  protected FactoryInterface $platforms;

  /**
   * Where failures are logged.
   */
  protected LoggerInterface $logger;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->platforms = $container->get('plugin.manager.ai.platform');
    $instance->logger = $container->get('logger.channel.genie_ledger');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return ['fallbacks' => []];
  }

  /**
   * {@inheritdoc}
   */
  public function decorate(PlatformInterface $inner): PlatformInterface {
    $fallbacks = array_map(
      fn (string $id): PlatformInterface => $this->platforms->createInstance("managed:$id"),
      $this->configuration['fallbacks'],
    );
    // Each request is a new PHP request, so the limiter only clears a mark.
    $limiter = new RateLimiterFactory([
      'id' => 'genie_failover',
      'policy' => 'fixed_window',
      'limit' => 1,
      'interval' => '1 minute',
    ], new InMemoryStorage());
    return new FailoverPlatform([$inner, ...$fallbacks], $limiter, logger: $this->logger);
  }

}
