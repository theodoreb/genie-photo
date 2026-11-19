<?php

declare(strict_types=1);

namespace Drupal\genie\Wish;

/**
 * Who a wish is about.
 */
enum Target: string {

  case Me = 'me';
  case SomeoneElse = 'someone_else';
  case Nothing = 'nothing';

}
