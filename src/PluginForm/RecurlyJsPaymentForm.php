<?php

namespace Drupal\commerce_recurly\PluginForm;

use Drupal\commerce\Response\NeedsRedirectException;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm as BasePaymentOffsiteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

class RecurlyJsPaymentForm extends BasePaymentOffsiteForm {

  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;

    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = $payment->getOrder();
    $billing_address = $order->getBillingProfile()->get('address')->getValue()[0];

    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();

    $configuration = $payment_gateway_plugin->getConfiguration();

    $data = [
      'public_key' => $configuration['public_key'],
      'address' => [
        'first_name' => $billing_address['given_name'],
        'last_name' => $billing_address['family_name'],
        'address1' => $billing_address['address_line1'],
        'address2' => $billing_address['address_line2'],
        'city' => $billing_address['locality'],
        'state' => $billing_address['administrative_area'],
        'postal_code' => $billing_address['postal_code'],
        'country' => $billing_address['country_code'],
      ],
      'return_url' => $form['#return_url'],
      'cancel_url' => $form['#cancel_url'],
    ];

    // Name fields
    $form['field_first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First Name'),
      '#default_value' => $billing_address['given_name'],
      '#required' => TRUE,
      '#attributes' => ['data-recurly' => 'first_name'],
    ];

    $form['field_last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last Name'),
      '#default_value' => $billing_address['family_name'],
      '#required' => TRUE,
      '#attributes' => ['data-recurly' => 'last_name'],
    ];

    // Address fields
    $form['field_address1'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Address Line 1'),
      '#default_value' => $billing_address['address_line1'],
      '#required' => TRUE,
      '#attributes' => ['data-recurly' => 'address1'],
    ];

    $form['field_address2'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Address Line 2'),
      '#default_value' => $billing_address['address_line2'],
      '#required' => FALSE,
      '#attributes' => ['data-recurly' => 'address2'],
    ];

    $form['field_city'] = [
      '#type' => 'textfield',
      '#title' => $this->t('City'),
      '#default_value' => $billing_address['locality'],
      '#required' => TRUE,
      '#attributes' => ['data-recurly' => 'city'],
    ];

    $form['field_state'] = [
      '#type' => 'textfield',
      '#title' => $this->t('State'),
      '#default_value' => $billing_address['administrative_area'],
      '#required' => TRUE,
      '#attributes' => ['data-recurly' => 'state'],
    ];

    $form['field_postal_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Postal Code'),
      '#default_value' => $billing_address['postal_code'],
      '#required' => TRUE,
      '#attributes' => ['data-recurly' => 'postal_code'],
    ];

    $form['field_country'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Country'),
      '#default_value' => $billing_address['country_code'],
      '#required' => TRUE,
      '#attributes' => ['data-recurly' => 'country'],
    ];

    // Recurly combined card field
    $form['field_recurly_card'] = [
      '#type' => 'markup',
      '#markup' => '<div data-recurly="card"></div>',
    ];

    // Recurly token
    $form['field_recurly_token'] = [
      '#type' => 'hidden',
      '#attributes' => ['data-recurly' => 'token'],
    ];

    // Form actions
    $form['actions'] = ['#type' => 'actions'];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromUri($form['#cancel_url']),
    ];


    $form['#attached']['library'][] = 'commerce_recurly/recurlyjs';
    $form['#attached']['library'][] = 'commerce_recurly/checkout';
    $form['#attached']['drupalSettings']['commerce_recurly'] = json_encode($data);

    return $this->buildRedirectForm($form, $form_state, $form['#return_url'], $data, self::REDIRECT_POST);
  }

  protected function buildRedirectForm(array $form, FormStateInterface $form_state, $redirect_url, array $data, $redirect_method = self::REDIRECT_GET) {
    if ($redirect_method == self::REDIRECT_POST) {
      $form['#attached']['library'][] = 'commerce_payment/offsite_redirect';
      $form['#process'][] = [get_class($this), 'processRedirectForm'];
      $form['#redirect_url'] = $redirect_url;

      foreach ($data as $key => $value) {
        $form[$key] = [
          '#type' => 'hidden',
          '#value' => $value,
          '#parents' => [$key],
        ];
      }
    }
    else {
      $redirect_url = Url::fromUri($redirect_url, ['absolute' => TRUE, 'query' => $data])->toString();
      throw new NeedsRedirectException($redirect_url);
    }

    return $form;
  }

  public static function processRedirectForm(array $form, FormStateInterface $form_state, array &$complete_form) {
    $complete_form['#action'] = $form['#redirect_url'];
    $complete_form['#attributes']['class'][] = 'payment-redirect-form';

    return $form;
  }

}
