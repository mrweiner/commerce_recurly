<?php

namespace Drupal\commerce_recurly\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentMethodTypeManager;
use Drupal\commerce_payment\PaymentTypeManager;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_recurly\RecurlyClient;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Entity\EntityTypeBundleInfo;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactory;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Utility\Token;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides a Recurly payment gateway.
 *
 * @todo: pull everything api-related and not gateway-specific into service
 *
 * @CommercePaymentGateway(
 *   id = "recurly_js_checkout",
 *   label = @Translation("Recurly JS"),
 *   display_label = @Translation("Recurly JS"),
 *   forms = {
 *     "offsite-payment" = "Drupal\commerce_recurly\PluginForm\RecurlyJsPaymentForm"
 *   },
 * )
 */
class Recurly extends OffsitePaymentGatewayBase implements RecurlyInterface {

  /**
   * Recurly client service
   *
   * @var \Drupal\commerce_recurly\RecurlyClient
   */
  protected $recurlyClient;

  /**
   * @var \Recurly\Client
   */
  protected $recurlyClientInstance;

  /**
   * Whether or not https://www.drupal.org/project/recurly
   * exists on the site and is enabled.
   *
   * @var bool
   */
  protected $recurlyModuleExists;

  /**
   * Helps to persist checkbox state thru ajax updates.
   *
   * @var bool
   */
  protected $checkboxState;

  /**
   * Recurly module config settings, if module is enabled.
   *
   * @var \Drupal\Core\Config\Config|\Drupal\Core\Config\ImmutableConfig
   */
  protected $recurlyModuleConfig;

  /**
   * Array of Recurly "custom fields" defined in module settings.
   *
   * @var \Drupal\Core\Config\Config|\Drupal\Core\Config\ImmutableConfig
   */
  protected $configCustomFields;

  /**
   * @var EntityTypeBundleInfo
   */
  protected $entityTypeBundleInfo;

  /**
   * The token service
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * @var \Drupal\Core\Logger\LoggerChannelFactory
   */
  protected $loggerChannelFactory;

  /**
   * @var \Drupal\Core\Logger\LoggerChannel
   */
  protected $logger;

  /**
   * Recurly constructor.
   *
   * @param array $configuration
   * @param $plugin_id
   * @param $plugin_definition
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\commerce_payment\PaymentTypeManager $payment_type_manager
   * @param \Drupal\commerce_payment\PaymentMethodTypeManager $payment_method_type_manager
   * @param \Drupal\Component\Datetime\TimeInterface $time
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   * @param \Drupal\commerce_recurly\RecurlyClient $recurly_client
   * @param \Drupal\Core\Extension\ModuleHandler $module_handler
   * @param \Drupal\Core\Config\ConfigFactory $config_factory
   * @param \Drupal\Core\Entity\EntityTypeBundleInfo $entity_type_bundle_info
   * @param \Drupal\Core\Utility\Token $token
   * @param \Drupal\Core\Logger\LoggerChannelFactory $logger_channel_factory
   */
  public function __construct(
    array $configuration, $plugin_id, $plugin_definition,
    EntityTypeManagerInterface $entity_type_manager,
    PaymentTypeManager $payment_type_manager,
    PaymentMethodTypeManager $payment_method_type_manager,
    TimeInterface $time,
    MessengerInterface $messenger,
    RecurlyClient $recurly_client,
    ModuleHandler $module_handler,
    ConfigFactory $config_factory,
    EntityTypeBundleInfo $entity_type_bundle_info,
    Token $token,
    LoggerChannelFactory $logger_channel_factory
  ) {

    parent::__construct($configuration, $plugin_id, $plugin_definition, $entity_type_manager, $payment_type_manager, $payment_method_type_manager, $time);

    $this->messenger = $messenger;
    $this->recurlyClient = $recurly_client;
    $this->recurlyModuleExists = $module_handler->moduleExists('recurly');
    if ($this->recurlyModuleExists) {
      $this->recurlyModuleConfig = $config_factory
        ->get('recurly.settings');
    }

    $this->configCustomFields = $config_factory
      ->get('commerce_recurly.settings')
      ->get('custom_fields');

    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->token = $token;
    $this->loggerChannelFactory = $logger_channel_factory;
    $this->logger = $logger_channel_factory->get('commerce_recurly');
  }

  public
  static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration, $plugin_id, $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.commerce_payment_type'),
      $container->get('plugin.manager.commerce_payment_method_type'),
      $container->get('datetime.time'),
      $container->get('messenger'),
      $container->get('commerce_recurly.recurly_client'),
      $container->get('module_handler'),
      $container->get('config.factory'),
      $container->get('entity_type.bundle.info'),
      $container->get('token'),
      $container->get('logger.factory')
    );
  }

  /**
   * Gets the subdomain that's set for the Payment Gateway.
   *
   * @return string|null
   */
  public
  function getSubdomain() {
    return $this->configuration['subdomain'];
  }

  /**
   * Gets the private key that's set for the Payment Gateway.
   *
   * @return string|null
   */
  public
  function getPrivateKey() {
    return $this->configuration['private_key'];
  }

  /**
   * Gets the public key that's set for the Payment Gateway.
   *
   * @return string|null
   */
  public
  function getPublicKey() {
    return $this->configuration['public_key'];
  }

  /**
   * Gets the account ID pattern that's set for the Payment Gateway.
   *
   * @param $pattern_name
   *
   * @return string|null
   */
  public
  function getAccountIdPattern($pattern_key) {
    return $this->configuration['account_id_patterns'][$pattern_key];
  }

  /**
   * Gets the array of account ID Patterns.
   *
   * @return string|null
   */
  public
  function getAccountIdPatterns() {
    return $this->configuration['account_id_patterns'];
  }

  /**
   * Gets an array of product variations treated as plans.
   *
   * @return array|null
   */
  public
  function getPlanProductVariations() {
    return $this->configuration['plan_product_variations'];
  }

  /**
   * @inheritDoc
   */
  public
  function defaultConfiguration() {
    return [
        'subdomain' => '',
        'private_key' => '',
        'public_key' => '',
        'account_id_pattern' => 'user-[user:id]',
      ] + parent::defaultConfiguration();
  }

  /**
   * @inheritDoc
   */
  public
  function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['mode']['#description'] = $this->t('Note that this has no effect on API calls and exists for developer use only. Recurly accounts can only be configured as one of <a href="https://support.recurly.com/hc/en-us/articles/360027273912-What-is-the-difference-between-Sandbox-Development-and-Production-sites-">Sandbox, Developer, or Production</a> -- never multiple. If you need to handle separate test/live environments then you will need to set up separate accounts for each environment as per <a href="https://support.recurly.com/hc/en-us/articles/360026665332-How-can-I-open-a-new-sandbox-site-for-testing-">Recurly\'s documentation</a>. Modules such as <a href="https://www.drupal.org/project/config_split">Config Split</a> are recommended for this scenario.');

    $form['#attached']['library'][] = 'commerce_recurly/admin';

    // Not applicable right now, but added in anticipation of
    // potential eventual removal of dependency on Recurly module.
    $form['recurly_module_notice'] = [
      '#type' => 'markup',
      '#markup' => $this->t("If the <a href='https://www.drupal.org/project/recurly'>Recurly module</a> is enabled then you may use its configuration to populate these API details."),
    ];

    if ($this->recurlyModuleExists) {
      $recurly_module_config_url =
        \Drupal\Core\Url::fromRoute('recurly.settings_form')
          ->toString();

      $form['use_recurly_module_creds'] = [
        '#type' => 'checkbox',
        '#title' => $this
          ->t("Use API credentials configured in <a href='$recurly_module_config_url'>Recurly Module Settings</a> instead of setting them here."),
        '#default_value' => $this->configuration['use_recurly_module_creds'] ?? 0,
        '#description' => $this->t('If this is checked then any previously entered API details will be overriden by those in the above configuration.'),
      ];

      unset($form['recurly_module_notice']);
    }

    $this->renderDependentElements($form);
    $this->renderPlanProductVariationTypesElement($form);
    $this->renderAccountIdPatternsElements($form);

    return $form;
  }

  /**
   * @inheritDoc
   */
  public
  function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    $values = $form_state->getValue($form['#parents']);

    if ((int) $values['use_recurly_module_creds'] === 1) {
      // Temporarily store all form errors.
      $form_errors = $form_state->getErrors();

      // Clear the form errors.
      $form_state->clearErrors();

      $dependent_elements = [
        'subdomain',
        'private_key',
        'public_key',
      ];

      foreach ($dependent_elements as $element_name) {
        // This is ignored by validation but set for sanity.
        $form['dependent_elements'][$element_name]['#required'] = FALSE;
        $form['dependent_elements'][$element_name]['#required_but_empty'] = FALSE;

        // Loop over existing error messages in search of
        // validation issues for empty dependent elements.
        foreach ($form_errors as $error_key => $error_message) {
          if ($error_key !== "configuration][recurly_js_checkout][dependent_elements][$element_name") {
            continue;
          }

          if (strpos($error_message->getUntranslatedString(), 'is required') === FALSE) {
            continue;
          }

          unset($form_errors[$error_key]);
        }
      }

      // Loop through and re-apply remaining error messages
      foreach ($form_errors as $name => $error_message) {
        $form_state->setErrorByName($name, $error_message);
      }

      if (empty($this->recurlyModuleConfig->get('recurly_private_api_key'))) {
        $recurly_config_error_detected = TRUE;
        $form_state->setErrorByName('configuration][recurly_js_checkout][dependent_elements][private_key', t('Your Recurly module configuration is missing a private key.'));
      }

      if (empty($this->recurlyModuleConfig->get('recurly_public_key'))) {
        $recurly_config_error_detected = TRUE;
        $form_state->setErrorByName('configuration][recurly_js_checkout][dependent_elements][public_key', t('Your Recurly module configuration is missing a public key.'));
      }

      if (empty($this->recurlyModuleConfig->get('recurly_subdomain'))) {
        $recurly_config_error_detected = TRUE;
        $form_state->setErrorByName('configuration][recurly_js_checkout][dependent_elements][subdomain', t('Your Recurly module configuration is missing a subdomain.'));
      }

      if (isset($recurly_config_error_detected)) {
        $form_state->setErrorByName('use_recurly_module_creds', t("Unable to use API Details from your <a href='http://dywm8.lndo.site/admin/config/services/recurly'>Recurly Module Configuration</a>. Please remedy the above errors and then try again."));
      }
    }
  }

  /**
   * @inheritDoc
   */
  public
  function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    $values = $form_state->getValue($form['#parents']);

    $this->configuration['use_recurly_module_creds'] = $values['use_recurly_module_creds'];

    // Set API Details
    $this->configuration['subdomain'] =
      ((int) $values['use_recurly_module_creds'] === 1) ?
        $this->recurlyModuleConfig->get('recurly_subdomain') :
        $values['dependent_elements']['subdomain'];
    $this->configuration['private_key'] =
      ((int) $values['use_recurly_module_creds'] === 1) ?
        $this->recurlyModuleConfig->get('recurly_private_api_key') :
        $values['dependent_elements']['private_key'];
    $this->configuration['public_key'] =
      ((int) $values['use_recurly_module_creds'] === 1) ?
        $this->recurlyModuleConfig->get('recurly_public_key') :
        $values['dependent_elements']['public_key'];
    $this->configuration['subdomain'] =
      ((int) $values['use_recurly_module_creds'] === 1) ?
        $this->recurlyModuleConfig->get('recurly_subdomain') :
        $values['dependent_elements']['subdomain'];

    // Set account ID patterns
    // Initialize as empty array to clear out potentially stale keys.
    $this->configuration['account_id_patterns'] = [];
    $this->configuration['account_id_patterns']['default'] =
      $values['account_id_patterns']['defaults_fallbacks']['default'];

    $this->configuration['account_id_patterns']['plan_plus_nonplan'] =
      $values['account_id_patterns']['defaults_fallbacks']['plan_plus_nonplan'] ?:
        $values['account_id_patterns']['plans_nonplans']['plans'] ?:
          $values['account_id_patterns']['defaults_fallbacks']['default'];

    $this->configuration['account_id_patterns']['plan'] =
      $values['account_id_patterns']['plans_nonplans']['plans'] ?:
        $values['account_id_patterns']['defaults_fallbacks']['default'];

    $this->configuration['account_id_patterns']['nonplan'] =
      $values['account_id_patterns']['plans_nonplans']['nonplans'] ?:
        $values['account_id_patterns']['defaults_fallbacks']['default'];

    // Set product variations to be treated as plans
    $plan_product_variations = [
      'recurly_plan_variation',
    ];

    foreach ($values["plan_product_variation_types_container"]["plan_product_variation_types"] as $variation_type => $checkbox_state) {
      if ($checkbox_state === 0) {
        continue;
      }
      $plan_product_variations[] = $variation_type;
    }

    $this->configuration['plan_product_variations'] = $plan_product_variations;

  }

  /**
   * @inheritDoc
   */
  public
  function onReturn(OrderInterface $order, Request $request) {
    // Determine which Account Pattern to use and fetch it
    $account_id_pattern_type = $this->determineProperAccountIdPatternType($order);

    // @todo: What do we do if the pattern is missing?
    // Like, if somebody imported config with an empty value
    // for the default pattern and that's what was determined
    // to be proper, we should throw an error and make sure
    // it gets dropped in the log. Maybe make the message
    // configurable? In either case, probably need to add...
    //
    // $this->validateAccountIdPatternType()

    $account_id_pattern = $this->getAccountIdPattern($account_id_pattern_type);

    // Determine the de-tokenized account ID
    $account_code = $this->token
      ->replace($account_id_pattern, ['commerce_order' => $order]);

    try {
      $this->initializeRecurlyClient([
        'subdomain' => $this->getSubdomain(),
        'api_key' => $this->getPrivateKey(),
      ]);

      // Create a new account if an existing one isn't found
      $account = $this->getRecurlyAccount($account_code) ?:
        $this->createRecurlyAccount($account_code, $order);

      // Get payment billing info from the token request
      $payment_process = $request->get('payment_process');
      $recurly_token = $payment_process['offsite_payment']['field_recurly_token'];

      // @todo: token expiry caught by this try/catch?
      // Ensure there's billing info attached to the account,
      // but the token is also used to set this info in
      // $this->createRecurlyPurchase.
      // @todo: determine whether or not this is actually needed.
      $this->recurlyClientInstance->updateBillingInfo("code-{$account_code}", ['token_id' => $recurly_token]);

      $line_items = [];

      foreach ($order->getItems() as $i => $item) {
        $unit_price = $item->getUnitPrice();

        $line_items [] = [
          'currency' => $unit_price->getCurrencyCode(),
          'unit_amount' => $unit_price->getNumber(), // recurly seems to want this as a float, not cents like would usually be expcted.
          'quantity' => intval($item->getQuantity()),
          'type' => 'charge',
        ];
      }

      return $this->createRecurlyPurchase($account_code, $line_items, $recurly_token);

    } catch (Exception $e) {
      $this->messenger->addError($this->t('Purchase could not be completed: ' . $e->getMessage()));

      throw new PaymentGatewayException($e->getMessage());
    }
  }

  /**
   * Determine proper account id pattern based on the order's items.
   *
   * @param $order
   *   The order for which we are determining the account id pattern.
   *
   * @return string
   *   The account id pattern type.
   */
  protected
  function determineProperAccountIdPatternType($order) {
    $plan_product_variations = $this->getPlanProductVariations();

    $pattern_to_use = 'default';

    foreach ($order->getItems() as $order_item) {
      if ($pattern_to_use === 'plan_plus_nonplan') {
        return $pattern_to_use;
      }

      $item_product_variation_type = $order_item
        ->getPurchasedEntity()
        ->bundle();


      // If this item should be processed as a plan
      if (in_array($item_product_variation_type, $plan_product_variations)) {
        switch ($pattern_to_use) {
          case 'default':
            $pattern_to_use = 'plan';
            break;

          case 'nonplan': // There's a plan and a nonplan in the order
            return 'plan_plus_nonplan';

          default:
            break;
        }
        continue;

      }

      // If we get here then this item is a nonplan.
      switch ($pattern_to_use) {
        case 'default':
          $pattern_to_use = 'nonplan';
          break;

        case 'plan': // There's a plan and a nonplan in the order
          return 'plan_plus_nonplan';

        default:
          break;
      }
      continue;

    }

    return $pattern_to_use;
  }


  /**
   * Initialize the recurly client.
   *
   * @param array $config
   *   Data passed into the client.
   */
  protected
  function initializeRecurlyClient(array $config) {
    if (isset($config['api_key'])) {
      $this->recurlyClientInstance = $this->recurlyClient->init($config['api_key']);
    }

    if (isset($config['subdomain'])) {
      // There doesn't appear to be handling for this anymore
      // in API v3.
    }
  }

  /**
   * Attempts to load a Recurly customer account.
   *
   * @param $account_code
   *   The identifier for the remote account.
   *
   * @return bool|object|null \Recurly\Resources\Account
   *   The recurly account object found.
   * @throws \Exception
   */
  protected
  function getRecurlyAccount($account_code) {
    try {
      return $this->recurlyClientInstance->getAccount(("code-{$account_code}"));
    } catch (NotFound $e) { // IDE doesn't think this can be thrown, but it can be
      return FALSE;
    } catch (Exception $e) {
      throw new Exception($e->getMessage());
    }
  }

  /**
   * Creates a new Recurly customer account.
   *
   * @param string $account_code
   *   Identifier to be used for the new account.
   * @param OrderInterface $order
   *   Order from which we determine customer/account info.
   *
   * @return \Recurly\Resources\Account
   * @throws \Exception
   */
  protected
  function createRecurlyAccount($account_code, $order) {
    // @todo: reactor into separate create and createFromOrder methods.
    try {

      $billing_address = $order->getBillingProfile()->get('address')->getValue()[0];

      $account_data = ["code" => $account_code];

      if ($email = $order->getCustomer()->getEmail()) {
        $account_data['email'] = $email;
      }

      if ($first_name = $billing_address['given_name']) {
        $account_data['first_name'] = $first_name;
      }

      if ($last_name = $billing_address['family_name']) {
        $account_data['last_name'] = $last_name;
      }

      // Add values for any custom account fields
      //
      // @todo: Set these configs on install?
      // @todo: Add handling for fetching field IDs when config saved
      if ($account_custom_fields = $this->configCustomFields['account_fields']) {
        $custom_field_definitions = $this->recurlyClientInstance->listCustomFieldDefinitions(['related_type' => 'account']);
        $custom_field_definitions_by_name = [];

        foreach ($custom_field_definitions as $k => $custom_field) {
          $custom_field_definitions_by_name[$custom_field->getName()] = $custom_field;
        }

        $custom_fields = [];

        foreach ($account_custom_fields as $field_id => $field_pattern) {
          if (!array_key_exists($field_id, $custom_field_definitions_by_name)) {
            continue;
          }

          // - token decode pattern
          $field_value = $this->token
            ->replace($field_pattern, ['commerce_order' => $order]);

          $custom_fields[] = [
            'name' => $field_id,
            'value' => $field_value,
          ];
        }
        $account_data['custom_fields'] = $custom_fields;
      }

      return $this->recurlyClientInstance->createAccount($account_data);;

    } catch (Validation $e) {
      throw new Exception('Recurly acccount not created. <br />Reason: ' . $e->getMessage());
    }
  }

  // Leaving this for now for reference, but not needed in
  // its current state as long as we are able to use API v3.
  // @todo: Delete
  //
  //  /**
  //   * Set a custom field value on a Recurly object.
  //   *
  //   *
  //   * We cannot reliably add custom field data  to a recurly object
  //   * before it is created. This is because there doesn't appear
  //   * to be a way to generically check if a custom field exists on a
  //   * given recurly entity type. We also cannot check whether a given
  //   * custom field exists on a specific Account, Subscription, or
  //   * item object -- only whether a value for a given $custom_field_id
  //   * is set on the object.
  //   *
  //   * In an ideal world we could wrap this into a single save but
  //   * at the moment that doesn't appear to be possible. This is because
  //   * saving a CustomFieldList array containing an invalid field_id
  //   * causes the whole operation to fail, meaning that even valid
  //   * data alongside the bad field will fail to save.
  //   *
  //   * @param \Recurly_Account|\Recurly_Item|\Recurly_Subscription $recurly_object
  //   *   The recurly object whose custom field we are populating
  //   * @param string $custom_field_id
  //   *   ID of the custom field in recurly
  //   * @param $custom_field_value
  //   *   Value being saved onto the field
  //   *
  //   * @return bool
  //   *   Whether or not the operation succeeded.
  //   */
  //  protected
  //  function setCustomFieldValue(object $recurly_object, $custom_field_id, $custom_field_value) {
  //    try {
  //
  //      // If a value for this field_id is already set on the
  //      // entity then overwrite it. Should be able safely to add an
  //      // $overwrite_existing_vals param if this option is needed.
  //      if (isset($recurly_object->custom_fields[$custom_field_id])) {
  //        $custom_field = $recurly_object->custom_fields[$custom_field_id];
  //        $custom_field->value = $custom_field_value;
  //      }
  //      else {
  //        // The object in question does not have a pre-existing value
  //        // set for the given custom_field_id, so add it.
  //        $recurly_object->custom_fields[] = new \Recurly_CustomField($custom_field_id, $custom_field_value);
  //      }
  //
  //      // Subscriptions have an assortment of update methods
  //      // so we need to use a specific one for those.
  //      if ($recurly_object instanceof \Recurly_Subscription) {
  //        $recurly_object->updateImmediately();
  //      }
  //      else {
  //        $recurly_object->update();
  //      }
  //
  //    } catch (\Recurly_Error $recurly_error) {
  //      // We don't want to throw these errors because that would
  //      // short-circuit whatever parent process is running, like
  //      // allowing an order to successfully process payment.
  //      if ($recurly_error instanceof Validation) {
  //        // If we end up here, it means that we couldn't save
  //        // the $recurly_object because a Custom Field has not
  //        // been attached to the given object type from the
  //        // Recurly administrative area.
  //        $errors = $recurly_error->errors;
  //        $field_error = reset($errors);
  //        $error_field = $field_error->field;
  //        $field_arr = explode('[name=', $error_field);
  //        $error_field_end = $field_arr[1];
  //        $error_field_end_arr = explode(']', $error_field_end);
  //        $error_field_id = $error_field_end_arr[0];
  //        $this->logger
  //          ->error('Tried to save value to invalid custom field_id in <code>setCustomFieldValue()</code>(): <code>@field_id</code>
  //              <br />
  //              Recurly Object Info: <pre>@recurly_object</pre>', [
  //              'field_id' => $error_field_id,
  //              'recurly_object' => print_r($recurly_object, TRUE),
  //            ]
  //          );
  //      }
  //      else {
  //        // Error due to update itself and not due to a bad field_id
  //        $error_str = $recurly_error->__toString();
  //        $this->logger
  //          ->error('Generic error thrown in <code>setCustomFieldValue()</code>():
  //              <br />
  //              Recurly Object Info: <pre>@recurly_object</pre>
  //              <br />
  //              Error Message: <pre>@field_id</pre>', [
  //              'error' => print_r($error_str),
  //              'recurly_object' => print_r($recurly_object, TRUE),
  //            ]
  //          );
  //      }
  //
  //      // Loop over all custom fields on the object to find
  //      // the one that matches the one we just tried to add
  //      // so it can be removed. Could probably get the $key
  //      // by doing count($recurly_object->custom_fields)
  //      // instead but this seem like it has less room for error.
  //      foreach ($recurly_object->custom_fields as $k => $custom_field) {
  //        $custom_field_data = $custom_field->getValues();
  //        if ($custom_field_data['name'] !== $custom_field_id) {
  //          continue;
  //        }
  //        $recurly_object->custom_fields[$k]->__set('value', NULL);
  //      };
  //
  //      // Resave to ensure the bad field data is removed.
  //      if ($recurly_object instanceof \Recurly_Subscription) {
  //        $recurly_object->updateImmediately();
  //      }
  //      else {
  //        $recurly_object->update();
  //      }
  //
  //      return FALSE;
  //    }
  //
  //    return TRUE;
  //  }

  /**
   * Creates and charges a Recurly purchase.
   *
   * @param string $account_code
   *   The account code the purchase is assigned to.
   * @param \Recurly\Resources\LineItem[] $line_items
   *   An array of line items (each as an array, not as objects).
   *
   * @return \Recurly\Resources\InvoiceCollection
   * @throws \Recurly\Errors\Validation
   * @throws \Recurly\RecurlyError
   */
  protected
  function createRecurlyPurchase($account_code, $line_items, $rjs_token_id) {
    // @todo: add error handling if not caught by onRespond
    try {
      $purchase_data = [
        "currency" => $line_items[0]['currency'],
        "account" => [
          "code" => $account_code,
          "billing_info" => [
            "token_id" => $rjs_token_id,
          ],
        ],
        'line_items' => $line_items,
        // @todo: add plan/subscription handling
        //        "subscriptions" => [
        //          [
        //            "plan_code" => $plan_code,
        //          ],
        //        ],
      ];

      return $this->recurlyClientInstance->createPurchase($purchase_data);
    } catch (\Recurly\Errors\Validation $e) {
      // If the request was not valid, you may want to tell your user
      // why. You can find the invalid params and reasons in err.params
      var_dump($e);
    } catch (\Recurly\RecurlyError $e) {
      // If we don't know what to do with the err, we should
      // probably re-raise and let our web framework and logger handle it
      var_dump($e);
      throw new Exception('Recurly Purchase not created.');
    }
  }

  /**
   * Determines whether or not we should be using
   * API data from the Recurly module's config
   * instead of setting it using this config form.
   *
   * @return bool
   */
  private
  function shouldUseRecurlyModuleConfig() {
    if (isset($this->checkboxState)) {
      if ($this->checkboxState === 0) {
        return FALSE;
      }

      return TRUE;
    }

    if (!$this->recurlyModuleExists) {
      return FALSE;
    }

    if (!isset($this->configuration['use_recurly_module_creds']) || $this->configuration['use_recurly_module_creds'] == 0) {
      return FALSE;
    }

    return TRUE;
  }


  /**
   * Adds the dependent API elements to the form.
   *
   * @param $form
   *   The form.
   */
  private
  function renderDependentElements(&$form) {
    // A container for elements that should respond to the
    // AJAX provided by ['use_recurly_module_creds'].
    $form['dependent_elements'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'dependent-elements',
      ],
    ];

    // Dependent elements should always be #required => true
    // to avoid values ever being allowed to be saved as empty
    // regardless of ['use_recurly_module_creds'] state.
    // Handling for shouldUseRecurlyConfig() is defined in
    // $this->validateConfigurationForm()
    $form['dependent_elements']['subdomain'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subdomain'),
      '#description' => $this->t('This is the Recurly subdomain.'),
      '#default_value' => $this->getSubdomain(),
      '#required' => TRUE,
    ];

    $form['dependent_elements']['private_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Private key'),
      '#description' => $this->t('This is the private key from Recurly.'),
      '#default_value' => $this->getPrivateKey(),
      '#required' => TRUE,
    ];

    $form['dependent_elements']['public_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Public key'),
      '#description' => $this->t('This is the public key from Recurly.'),
      '#default_value' => $this->getPublicKey(),
      '#required' => TRUE,
    ];

    foreach ($form['dependent_elements'] as $k => $element) {
      if (strpos($k, '#') !== FALSE) {
        continue;
      }
      $element['#hidden'] = !$this->shouldUseRecurlyModuleConfig();
      $element['#disabled'] = !$this->shouldUseRecurlyModuleConfig();
      $element['#required'] = $this->shouldUseRecurlyModuleConfig();
    }
  }

  /**
   * Adds the Plan Product Variation Types element to the form.
   *
   * @param $form
   */
  private
  function renderPlanProductVariationTypesElement(&$form) {
    // Vertical tabs do not display as they should, unfortunately,
    // so we're dropping them all in a single group to at least
    // give them a styled details container.
    $form['plan_product_variation_types__vertical_tabs'] = [
      '#type' => 'vertical_tabs',
      '#required' => TRUE,
      '#attributes' => [
        'id' => 'plan-product-variation-types',
      ],
    ];

    $form['plan_product_variation_types_container'] = [
      '#type' => 'details',
      '#title' => $this->t('Product Variation Types Treated as Plans'),
      '#description' => $this->t('Variations provided by Commerce Recurly -- Recurly Plan Variation and Recurly Nonplan Variation -- are not included here. Their behaviors follow their names.'),
    ];

    $product_variation_types = $this->entityTypeBundleInfo
      ->getBundleInfo('commerce_product_variation');

    $product_variation_types_as_options = [];

    // Variation types provided by commerce_recurly.
    $default_variations = [
      'recurly_nonplan_variation',
      'recurly_plan_variation',
    ];

    foreach ($product_variation_types as $type_machine_name => $type_info) {
      // We don't want users to be able to override behvior for
      // commerce_recurly's default variations
      if (in_array($type_machine_name, $default_variations)) {
        continue;
      }
      $product_variation_types_as_options[$type_machine_name] = $this->t($type_info['label']);
    }

    // Remove our default variations from our default values
    // as they shouldn't be present in the options anyway.
    $default_values = $this->getPlanProductVariations();
    foreach ($default_variations as $default_variation) {
      if (in_array($default_variation, $default_values)) {
        unset($default_values[$default_variation]);
      }
    }

    $form['plan_product_variation_types_container']['plan_product_variation_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Recurly Plan Product Variation Types'),
      '#description' => $this->t('
        <ul>
            <li>Product variation types selected above will be treated as Recurly Plans and will use the associated Account ID Pattern defined below.</li>
            <li>Product variation types that are <i>not</i> selected above will be considered as nonplans and will use the associated Account ID Pattern defined below.</li>
        </ul>
      '),
      '#options' => $product_variation_types_as_options,
      '#default_value' => $default_values,
      '#group' => 'plan_product_variation_types__vertical_tabs',
    ];
  }

  /**
   * Adds Account ID Patterns elements to the form
   *
   * @param $form
   *   The form
   */
  private
  function renderAccountIdPatternsElements(&$form) {
    // Vertical tabs do display as they should, unfortunately,
    // so we're dropping them all in a single group to at least
    // give them a styled details container.
    $form['account_id_patterns__vertical_tabs'] = [
      '#type' => 'vertical_tabs',
      '#required' => TRUE,
      '#attributes' => [
        'id' => 'account-id-patterns-vertical--tabs',
      ],
    ];

    $this->renderAccountIdPatternsDefaults($form);
    $this->renderAccountIdPatternsPlansNonplans($form);
  }

  /**
   * Adds Default Account ID Patterns elements to the form
   *
   * @param $form
   *   The form
   */
  private
  function renderAccountIdPatternsDefaults(&$form) {
    // "Tabs" container for default/fallback patterns.
    $form['defaults_fallbacks__vertical_tabs'] = [
      '#type' => 'vertical_tabs',
      '#required' => TRUE,
      '#attributes' => [
        'id' => 'defaults-fallbacks--vertical-tabs',
      ],
    ];

    // Details container for all patterns. If tabs worked
    // then this would be a tab.
    $form['account_id_patterns'] = [
      '#type' => 'details',
      '#title' => $this->t('Account ID Patterns'),
      '#description' => $this->t("<p>Recurly only allows a single payment method to be associated with any given customer account. To get around this, you can decide what patterns should be used based on what items the order contains using the below fields. This will help to ensure that the payment method associated with a user's plan is not overwritten when purchasing a non-plan product variation, for instance.</p><p>Doublecheck your tokens. For instance, the User ID lives on <code>[commerce_order:uid:target_id]</code>, not on <code>[commerce_order:uid]</code></p>"),
      '#group' => 'account_id_patterns_tabs',
      '#open' => TRUE,
      '#required' => TRUE,
    ];

    // Details for for default/fallback patterns.
    $form['account_id_patterns']['defaults_fallbacks'] = [
      '#type' => 'details',
      '#title' => $this->t('Default + Fallback Patterns'),
      '#description' => $this->t('Recurly only allows a single payment method to be associated with any given customer. Using the  below fields, you can decide what patterns should be used based on what items the order contains.'),
      '#group' => 'defaults_fallbacks_tabs',
      '#required' => TRUE,
    ];

    // Define our default/fallback sections and their dependent properties
    $sections = [
      'default' => [
        'title' => 'Default',
        'description' => 'This pattern is used when more specific patterns have not been set or cannot be found. If you set patterns for both Plans and Nonplans then this will likely never be used, but should be set just in case.',
        'default_value' => $this->getAccountIdPattern('default') ?: '',
        'required' => TRUE,
      ],
      'plan_plus_nonplan' => [
        'title' => 'Plan + Non-Plan Fallback',
        'description' => '<p>This pattern is used when both a plan and a nonplan exist on a single order. This should generally be the same pattern that you use for Plans since the payment method associated with the account will be used for the recurring billing.</p>
        <ul>
            <li>If this field is left empty then it will be set to the value of the <b>Plans Account ID Pattern</b> upon save.</li>
            <li>If both this field and the <b>Plans Account ID Pattern</b> are left empty then this field will be set to the value of <b>Default Account ID Pattern</b> upon save.</li>
        </ul>',
        'default_value' => $this->getAccountIdPattern('plan_plus_nonplan') ?: '',
        'required' => FALSE,
      ],
      // In case support for multiple nonplans is ever needed.
      // Doesn't seem like a practical accommodation at this time.
      //
      //      'fallback__multiple_nonplans' => [
      //        'title' => 'Multiple Nonplans',
      //        'description' => "This pattern is used when multiple nonplans exist on a single order.",
      //        'default_value' => '',
      //        'required' => FALSE,
      //      ],
    ];

    $this->renderAccountIdPatternElement($form, 'defaults_fallbacks', $sections);
  }

  /**
   * Adds Plan vs Nonplan Account ID Patterns elements to the form
   *
   * @param $form
   *   The form
   */
  private
  function renderAccountIdPatternsPlansNonplans(&$form) {
    // "Tabs" container for product variation type patterns.
    $form['plans_nonplans__vertical_tabs'] = [
      '#type' => 'vertical_tabs',
      '#required' => TRUE,
      '#attributes' => [
        'id' => 'plans-nonplans--vertical-tabs',
      ],
    ];

    // Details for for product variation types patterns.
    $form['account_id_patterns']['plans_nonplans'] = [
      '#type' => 'details',
      '#title' => $this->t('Plan + Nonplan Patterns'),
      '#description' => $this->t('Patterns defined below will be used to determine the Account ID for purchases including <i>only</i> plans or nonplans. If no patterns are defined here then the "default pattern" will be used. If both a plan and a nonplan exist on the same order, the "Plan + Non-Plan Fallback Account ID Pattern" will be used.'),
      '#group' => 'plans_nonplans__vertical_tabs',
    ];

    // Define our default/fallback sections and their dependent properties
    $sections = [
      'plans' => [
        'title' => 'Plans',
        'description' => 'This pattern is used exclusively when purchasing Product Variant Types that are treated as plans.',
        'default_value' => $this->getAccountIdPattern('plan') ?: '',
        'required' => FALSE,
      ],
      'nonplans' => [
        'title' => 'Nonplans',
        'description' => 'This pattern is used exclusively when purchasing Product Variant Types that are not treated as plans.',
        'default_value' => $this->getAccountIdPattern('nonplan') ?: '',
        'required' => FALSE,
      ],
    ];

    $this->renderAccountIdPatternElement($form, 'plans_nonplans', $sections);
  }

  /**
   * Renders a set of elements that share a majority of their information.
   *
   * @param $form
   *   The form
   * @param $key
   *   Key to be used for grouping the elements
   * @param array $sections
   *   The sections to be rendered.
   *   $section = [
   *     'section_1_name' => [
   *       'title' => ...,
   *       'description' => ...,
   *       'default_value' => ...,
   *       'required' => ...
   *     ]
   *   ]
   */
  private
  function renderAccountIdPatternElement(&$form, $key, $sections) {
    // Build our default + fallback form elements
    foreach ($sections as $section_name => $section_details) {
      $form['account_id_patterns'][$key][$section_name] = [
        '#type' => 'textfield',
        '#title' => $this->t($section_details['title'] . '  Account ID Pattern'),
        '#description' => $this->t($section_details['description']),
        '#default_value' => $section_details['default_value'],
        '#size' => 65,
        '#maxlength' => 1280,
        '#element_validate' => ['token_element_validate'],
        '#after_build' => ['token_element_validate'],
        '#token_types' => ['commerce_order'],
        '#min_tokens' => 1,
        '#required' => $section_details['required'],
      ];

      // Show the token help relevant to this pattern type.
      $form['account_id_patterns'][$key] ["account_id_pattern_token_help__$section_name"] = [
        '#theme' => 'token_tree_link',
        '#token_types' => ['commerce_order'],
        //      '#token_types' => ['commerce_order', 'commerce_payment'],
        '#global_types' => FALSE,
      ];
    }
  }

}
