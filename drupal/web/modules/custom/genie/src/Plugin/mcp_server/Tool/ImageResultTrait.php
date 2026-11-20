<?php

declare(strict_types=1);

namespace Drupal\genie\Plugin\mcp_server\Tool;

use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;

/**
 * Builds the result of a tool that returns an image.
 */
trait ImageResultTrait {

  /**
   * Builds a result holding a description and an image.
   *
   * It carries no structured content, because some clients show only that and
   * would drop the image.
   *
   * @param array{bytes: string, mime: string, info: array<string, mixed>} $image
   *   The image and what to say about it.
   *
   * @return list<\Mcp\Schema\Content\Content>
   *   The content of the result.
   */
  protected function imageResult(array $image): array {
    return [
      new TextContent(json_encode($image['info'], JSON_THROW_ON_ERROR)),
      new ImageContent(base64_encode($image['bytes']), $image['mime']),
    ];
  }

}
