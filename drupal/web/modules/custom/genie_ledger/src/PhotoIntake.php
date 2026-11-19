<?php

declare(strict_types=1);

namespace Drupal\genie_ledger;

/**
 * Turns an uploaded image into an upright JPEG of the game's size.
 */
final class PhotoIntake {

  private const MIN_LONG_SIDE = 480;
  private const MAX_SHAPE = 3;
  private const MAX_PIXELS = 40_000_000;
  private const TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

  /**
   * Re-encodes an upload, which drops everything but the pixels.
   *
   * @return array{jpeg: string, width: int, height: int}
   *   The new JPEG and its size.
   *
   * @throws \InvalidArgumentException
   *   When the bytes are not a supported image of an accepted size.
   */
  public function normalize(string $bytes): array {
    // The size check runs before decoding, which allocates memory per pixel.
    $info = @getimagesizefromstring($bytes);
    if ($info === FALSE || !in_array($info[2], self::TYPES, TRUE)) {
      throw new \InvalidArgumentException('Upload a JPEG, PNG or WebP image.');
    }
    if ($info[0] * $info[1] > self::MAX_PIXELS) {
      throw new \InvalidArgumentException('This image has too many pixels.');
    }
    $long = max($info[0], $info[1]);
    if ($long < self::MIN_LONG_SIDE) {
      throw new \InvalidArgumentException(sprintf('The long side of the photo must be at least %d pixels.', self::MIN_LONG_SIDE));
    }
    if ($long > min($info[0], $info[1]) * self::MAX_SHAPE) {
      throw new \InvalidArgumentException(sprintf('The photo can be at most %d times wider than it is tall.', self::MAX_SHAPE));
    }
    $image = @imagecreatefromstring($bytes);
    if ($image === FALSE) {
      throw new \InvalidArgumentException('This image cannot be read.');
    }
    if ($info[2] === IMAGETYPE_JPEG) {
      $image = $this->upright($image, $bytes);
    }
    $scale = Outlines::LONG_SIDE / $long;
    $image = imagescale($image, (int) round(imagesx($image) * $scale), (int) round(imagesy($image) * $scale), IMG_BICUBIC);
    ob_start();
    imagejpeg($image, NULL, 88);
    return ['jpeg' => ob_get_clean(), 'width' => imagesx($image), 'height' => imagesy($image)];
  }

  /**
   * Applies the EXIF orientation, which re-encoding would otherwise lose.
   */
  private function upright(\GdImage $image, string $bytes): \GdImage {
    $stream = fopen('php://memory', 'r+b');
    fwrite($stream, $bytes);
    rewind($stream);
    // Phones write EXIF this reader warns about, and a warning is no failure.
    $orientation = (@exif_read_data($stream) ?: [])['Orientation'] ?? 1;
    fclose($stream);

    match ($orientation) {
      2, 7 => imageflip($image, IMG_FLIP_HORIZONTAL),
      4, 5 => imageflip($image, IMG_FLIP_VERTICAL),
      default => NULL,
    };
    // Function imagerotate() turns counterclockwise.
    return match ($orientation) {
      3 => imagerotate($image, 180, 0),
      5, 6, 7 => imagerotate($image, 270, 0),
      8 => imagerotate($image, 90, 0),
      default => $image,
    };
  }

}
