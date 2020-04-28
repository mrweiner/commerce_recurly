<?php

namespace Drupal\commerce_recurly\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Routing\RouteBuilderInterface;
use Drupal\Core\Utility\Token;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Parent class for Commerce Recurly configuration forms.
 */
abstract class CommerceRecurlyConfigFormBase extends ConfigFormBase {

  /**
   * The formatting service.
   *
   * @var \Drupal\recurly\RecurlyFormatManager
   */
  protected $recurlyFormatter;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The router builder service.
   *
   * @var \Drupal\Core\Routing\RouteBuilderInterface
   */
  protected $routeBuilder;

  /**
   * The token service.
   *
   * @var \Drupal\Core\Routing\RouteBuilderInterface
   */
  protected $token;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('module_handler'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_type.manager'),
      $container->get('router.builder'),
      $container->get('token')
    );
  }

  /**
   * Creates a Recurly settings form.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\recurly\RecurlyClient $client
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_bundle_info
   *   The entity type bundle info service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Routing\RouteBuilderInterface $route_builder
   *   The router builder service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ModuleHandlerInterface $module_handler,
    EntityTypeBundleInfoInterface $entity_bundle_info,
    EntityTypeManagerInterface $entity_type_manager,
    RouteBuilderInterface $route_builder,
    Token $token
  ) {
    parent::__construct($config_factory);
    $this->moduleHandler = $module_handler;
    $this->entityTypeBundleInfo = $entity_bundle_info;
    $this->entityTypeManager = $entity_type_manager;
    $this->routeBuilder = $route_builder;
    $this->token = $token;
  }

}
