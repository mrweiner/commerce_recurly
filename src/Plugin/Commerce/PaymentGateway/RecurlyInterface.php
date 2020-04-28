<?php

namespace Drupal\commerce_recurly\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;

interface RecurlyInterface extends OffsitePaymentGatewayInterface {

  public function getSubdomain();

  public function getPrivateKey();

  public function getPublicKey();

  public function getAccountIdPattern($pattern_key);

  public function getAccountIdPatterns();

  public function getPlanProductVariations();

}

