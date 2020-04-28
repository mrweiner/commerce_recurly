<?php

namespace Drupal\commerce_recurly\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * Recurly configuration settings form.
 */
class CommerceRecurlyCustomFieldsSettingsForm extends CommerceRecurlyConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'commerce_recurly_custom_fields_settings_form';
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
    $form['overview'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Overview'),
    ];

    $form['overview']['desc'] = [
      '#markup' => $this->t('
        <p>Recurly allows the creation of <a href="https://docs.recurly.com/docs/custom-fields">Custom Fields</a> on Customer Accounts, Subscriptions, and Items. After you create a custom field for one of these entity types, you can add it below and data will be set on the custom field when appropriate.</p>

        <p>There doesn\'t seem to be a way to make sure that a a given custom_field_id has been created for a Recurly entity type before saving them onto an individual entity. As such, you can essentially set any values in this form and you\'ll still be able to save it. Commerce Recurly attempts to validate this saving the field data onto the Recurly Object.</p>
        '),
    ];

    $form['#tree'] = TRUE;

    $this->buildSection('Account', $form, $form_state);

    //    $this->buildSection('Subscription', $form, $form_state);
    $form['subscription'] = [
      '#type' => 'details',
      '#title' => $this->t("Subscription Fields"),
      '#description' => $this->t('Handling not yet configured.'),
      '#open' => FALSE,
    ];

    //    $this->buildSection('Item', $form, $form_state);
    $form['item'] = [
      '#type' => 'details',
      '#title' => $this->t("Item Fields"),
      '#description' => $this->t('Handling not yet configured.'),
      '#open' => FALSE,
    ];

    $form = parent::buildForm($form, $form_state);
    return $form;
  }

  public function buildSection($section_name, &$form, &$form_state) {
    $section_id = str_replace(' ', '_', strtolower($section_name));
    $section_id_kebab = str_replace('_', '-', $section_id);

    // Add form elements to collect default account information.
    $form[$section_id] = [
      '#type' => 'details',
      '#title' => $this->t("$section_name Fields"),
      '#open' => TRUE,
      '#attributes' => [
        'id' => $section_id_kebab,
      ],
    ];

    // Set these vals based on config unless they are falsey,
    // in which case set them to empty arrays.
    $custom_fields_config = $this->config('commerce_recurly.settings')
      ->get('custom_fields') ?: [];
    $this_fields_config = $custom_fields_config["{$section_id}_fields"] ?: [];

    // Set data into indexed array to match it
    // when looping over $i below.
    $this_fields_config_indexed = [];
    foreach ($this_fields_config as $field_id => $field_pattern) {
      $this_fields_config_indexed[] = [
        'field_id' => $field_id,
        'field_pattern' => $field_pattern,
      ];
    }

    // Gather the number of names in the form already.
    $section_count = $form_state->get("{$section_id}_count");

    // If this hasn't been set then use set it based on
    // the number of values saved to config.
    if ($section_count === NULL) {
      $section_count = count($this_fields_config_indexed);
      $form_state->set("{$section_id}_count", $section_count);
    }

    // Generate fields based on $section_count and stored config
    for ($i = 0; $i < $section_count; $i++) {
      $i_plus = $i + 1;

      $form[$section_id][$i] = [
        '#type' => 'fieldset',
        '#title' => $this->t("Field $i_plus."),
      ];

      $form[$section_id][$i]['field_id'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Custom Field ID'),
        '#description' => $this->t('The ID of the custom field as it exists in Recurly.'),
        '#default_value' =>
          (isset($this_fields_config_indexed[$i]) && isset($this_fields_config_indexed[$i]['field_id'])) ?
            $this_fields_config_indexed[$i]['field_id'] :
            '',
        '#required' => TRUE,
      ];

      $form[$section_id][$i]['field_pattern'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Replacement Pattern'),
        '#description' => $this->t('The pattern used to populate the custom field.'),
        '#element_validate' => ['token_element_validate'],
        '#after_build' => ['token_element_validate'],
        '#token_types' => ['commerce_order'],
        '#default_value' =>
          (isset($this_fields_config_indexed[$i]) && isset($this_fields_config_indexed[$i]['field_pattern'])) ?
            $this_fields_config_indexed[$i]['field_pattern'] :
            '',
        '#required' => TRUE,
      ];

      // Show the token help relevant to this pattern type.
      $form[$section_id][$i]['field_token_selector'] = [
        '#theme' => 'token_tree_link',
        '#token_types' => ['commerce_order'],
        '#global_types' => FALSE,
      ];
    }

    $form[$section_id]['actions'] = [
      '#type' => 'actions',
    ];

    $form[$section_id]['actions']['add_name'] = [
      '#type' => 'submit',
      '#name' => "add-$section_id_kebab",
      '#value' => $this->t('Add Item'),
      '#submit' => ['::addOne'],
      '#ajax' => [
        'callback' => '::addRemoveCallback',
        'wrapper' => $section_id_kebab,
      ],
    ];

    // If there is more than one name, add the remove button.
    if ($section_count > 0) {
      $form[$section_id]['actions']['remove_name'] = [
        '#type' => 'submit',
        '#name' => "remove-$section_id_kebab",
        '#value' => $this->t('Remove Last Item'),
        '#submit' => ['::removeCallback'],
        '#ajax' => [
          'callback' => '::addRemoveCallback',
          'wrapper' => $section_id_kebab,
        ],
      ];
    }

    return $form;
  }

  /**
   * Gets the key of the trigger's parent section.
   *
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  public function getTriggerSectionKey(FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    $trigger_parents = $trigger['#array_parents'];
    return reset($trigger_parents);
  }

  /**
   * Callback for both ajax-enabled buttons.
   *
   * Selects and returns the fieldset with the names in it.
   */
  public function addRemoveCallback(array &$form, FormStateInterface $form_state) {
    return $form[$this->getTriggerSectionKey($form_state)];
  }


  /**
   * Submit handler for the "add-one-more" button.
   *
   * Increments the max counter and causes a rebuild.
   */
  public function addOne(array &$form, FormStateInterface $form_state) {
    $this->addRemoveHandler('add', $form_state);
  }

  /**
   * Submit handler for the "remove one" button.
   *
   * Decrements the max counter and causes a form rebuild.
   */
  public function removeCallback(array &$form, FormStateInterface $form_state) {
    $this->addRemoveHandler('remove', $form_state);
  }

  /**
   * Add/remove handler.
   *
   * @param $variant
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  public function addRemoveHandler($variant, FormStateInterface $form_state) {
    $trigger_section_name = $this->getTriggerSectionKey($form_state);
    $count_key = "{$trigger_section_name}_count";
    $section_count = $form_state->get($count_key);

    switch ($variant) {
      case 'add':
        $section_count++;
        break;

      case 'remove':
        if ($section_count > 0) {
          $section_count--;
        }
        break;

    }

    // Need to cast this to a string or else a value of 0
    // will be interpreted as false, leading to the relevant
    // count being incorrectly set to null. If this happens
    // then the number of fieldsets rendered will be equal to
    // the number of values stored in the related config set.
    $form_state->set($count_key, (string) $section_count);

    // Since our buildForm() method relies on the value of the section's count
    // to generate form elements, we have to tell the form to rebuild. If we
    // don't do this, the form builder will not call buildForm().
    $form_state->setRebuild();
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
    // Custom field data is stored in a single array
    // keyed by recurly entity type.
    $custom_fields_data = [];

    $custom_field_section_ids = [
      'account',
      'subscription',
      'item',
    ];

    foreach ($custom_field_section_ids as $section_name) {
      // Use the recurly entity name to set an array key that's
      // slightly friendlier when fetching the data.
      $section_id = "{$section_name}_fields";

      $custom_fields_data[$section_id] = [];

      if (!$values = $form_state->getValue($section_name)) {
        continue;
      }

      foreach ($values as $k => $field_info) {
        // Ignore Actions
        if (!is_int($k)) {
          continue;
        }

        // Final check so that we don't store empty data
        if (empty($field_info['field_id']) || empty($field_info['field_pattern'])) {
          continue;
        }

        $field_id = $field_info['field_id'];
        $field_pattern = $field_info['field_pattern'];

        $custom_fields_data[$section_id][$field_id] = $field_pattern;
      }
    }

    $this->config('commerce_recurly.settings')
      ->set('custom_fields', $custom_fields_data)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
