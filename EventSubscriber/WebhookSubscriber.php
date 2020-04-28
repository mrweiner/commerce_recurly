<?php


namespace Drupal\commerce_recurly\EventSubscriber;

use Drupal\recurly\Event\notifications\payment\RecurlySuccessfulPaymentEvent;
use Drupal\recurly\Event\RecurlyWebhookEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Class WebhookSubscriber
 *
 * @package Drupal\commerce_recurly\EventSubscriber
 */
class WebhookSubscriber implements EventSubscriberInterface {

  /**
   * Get subscribed events.
   *
   * @inheritdoc
   */
  public static function getSubscribedEvents() {
    \Drupal::logger('commerce_recurly')
      ->notice(
        'Inside getSubscribedEvents'
      );
    $events[RecurlyWebhookEvents::SUCCESSFUL_PAYMENT][] = ['onSuccessfulPayment'];
    return $events;
  }

  /**
   * Successful payment event handler.
   *
   * @param \Drupal\recurly\Event\notifications\payment\RecurlySuccessfulPaymentEvent $event
   *   The subscribed event.
   */
  public function onSuccessfulPayment(RecurlySuccessfulPaymentEvent $event) {
    \Drupal::logger('commerce_recurly')
      ->notice(
        'Event firing for Successful Payment notification. <pre>@event</pre>',
        [
          '@event' => print_r($event, TRUE),
        ]
      );

    \Drupal::logger('commerce_recurly')
      ->notice(
        'Event notification is <pre>@notification</pre>',
        [
          '@notification' => print_r($event->getNotification(), TRUE),
        ]
      );

  }

}
