<?php

namespace Drupal\commerce_recurly\EventSubscriber;

use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Class WebhookSubscriber
 *
 * @package Drupal\commerce_recurly\EventSubscriber
 */
class OrderSubscriber implements EventSubscriberInterface {

  /**
   * Get subscribed events.
   *
   * @inheritdoc
   */
  public static function getSubscribedEvents() {
    $events[OrderEvents::ORDER_PAID][] = ['onOrderPaid'];
    return $events;
  }

  /**
   * @param \Drupal\recurly\Event\RecurlySuccessfulPaymentEvent $event
   */
  public function onOrderPaid(OrderEvent $event) {
    /**
     * Need to:
     * - save the Recurly Account ID onto the order.
     * - loop over order items to get all recurly plan IDs
     * - create a service for hitting the API or leverage recurly module
     * - attach plans to the recurly account based on the order
     */

    /**
     * Alternatively, watch Recurly events for successful payment
     * and get order/account info that way.
     *
     * Maybe pass order data (like relevant plans) through on the
     * recurly order itself so we can grab them from there.
     */
  }

}
