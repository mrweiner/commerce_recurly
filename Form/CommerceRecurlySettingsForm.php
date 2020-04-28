<?php

namespace Drupal\commerce_recurly\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * Recurly configuration settings form.
 */
class CommerceRecurlySettingsForm extends CommerceRecurlyConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'commerce_recurly_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['commerce_recurly.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Add form elements to collect default account information.
    $form['placeholder'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Settings Placeholder'),
      '#description' => $this->t('No settings are defined right now but they may be added.'),
      '#open' => TRUE,
    ];

    $form = parent::buildForm($form, $form_state);
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('commerce_recurly.settings');
    //    ->set('recurly_private_api_key', $form_state->getValue('recurly_private_api_key'))

    parent::submitForm($form, $form_state);
  }

}
