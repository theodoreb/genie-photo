<?php

declare(strict_types=1);

namespace Drupal\genie\Photo;

use Codewithkyrian\Transformers\Transformers;
use Codewithkyrian\Transformers\Utils\ImageDriver;
use Drupal\Core\File\FileSystemInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Runs the image models on this server, through Transformers PHP.
 */
final class LocalModels {

  /**
   * Where the models are downloaded on their first run.
   */
  private const DIRECTORY = 'private://genie-models';

  /**
   * Seconds a drush run may take, the first model download included.
   */
  private const TIMEOUT = 300;

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    #[Autowire(service: 'logger.channel.genie')]
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Tells whether this PHP can run the models.
   */
  public function available(): bool {
    return extension_loaded('FFI');
  }

  /**
   * Points Transformers PHP at the model folder.
   */
  public function setUp(): void {
    $directory = self::DIRECTORY;
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    Transformers::setup()
      ->setCacheDir($this->fileSystem->realpath($directory))
      ->setImageDriver(ImageDriver::GD)
      ->apply();
  }

  /**
   * Runs a drush command that prints JSON, in its own process.
   *
   * The models run through PHP's FFI extension, which PHP allows on the
   * command line alone. A web request runs them this way and waits.
   *
   * @param string $command
   *   The drush command.
   * @param list<string> $arguments
   *   Its arguments.
   *
   * @return mixed
   *   The decoded output, or NULL when the command failed.
   */
  public function runInCommand(string $command, array $arguments): mixed {
    $process = new Process(['./drupack', 'drush', $command, ...$arguments], dirname(DRUPAL_ROOT), ['DRUPACK_NO_UPDATE_CHECK' => '1'], timeout: self::TIMEOUT);
    $process->run();
    if (!$process->isSuccessful()) {
      $this->logger->error('@command failed: @error', ['@command' => $command, '@error' => $process->getErrorOutput()]);
      return NULL;
    }
    return json_decode($process->getOutput(), TRUE, flags: JSON_THROW_ON_ERROR);
  }

}
