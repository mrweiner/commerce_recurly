<?php

namespace Drupal\commerce_recurly;

use Recurly\Client;

/**
 * Service implementation of the Recurly Client.
 *
 * @package Drupal\commerce_recurly
 */
class RecurlyClient {

  public function init($private_key) {
    return new Client($private_key);
  }

}
