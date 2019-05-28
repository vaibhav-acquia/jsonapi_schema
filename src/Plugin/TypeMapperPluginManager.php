<?php

namespace Drupal\jsonapi_schema\Plugin;

use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Component\Plugin\FallbackPluginManagerInterface;
use Drupal\jsonapi_schema\Annotation\TypeMapper;
use Drupal\jsonapi_schema\Plugin\jsonapi_schema\type_mapper\TypeMapperInterface;

/**
 * Manages TypeMapper plugins.
 *
 * TypeMappers are used to adapt Drupal TypedData types to JSON Schema specs.
 *
 * @see \Drupal\jsonapi_schema\Annotation\TypeMapper
 * @see \Drupal\jsonapi_schema\Plugin\jsonapi_schema\type_mapper\TypeMapperBase
 * @see \Drupal\jsonapi_schema\Plugin\jsonapi_schema\type_mapper\TypeMapperInterface
 * @see plugin_api
 */
class TypeMapperPluginManager extends DefaultPluginManager implements FallbackPluginManagerInterface {

  /**
   * The TypeMapper to use if there's a miss.
   *
   * @param string
   */
  const FALLBACK_TYPE_MAPPER = 'fallback';

  /**
   * Constructs a new \Drupal\rest\Plugin\Type\ResourcePluginManager object.
   *
   * @param \Traversable $namespaces
   *   An object that implements \Traversable which contains the root paths
   *   keyed by the corresponding namespace to look for plugin implementations.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler to invoke the alter hook with.
   */
  public function __construct(\Traversable $namespaces, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/jsonapi_schema/type_mapper', $namespaces, $module_handler, TypeMapperInterface::class, TypeMapper::class);
  }

  /**
   * {@inheritdoc}
   */
  public function getFallbackPluginId($plugin_id, array $configuration = []) {
    return static::FALLBACK_TYPE_MAPPER;
  }

}
