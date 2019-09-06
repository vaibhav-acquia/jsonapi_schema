<?php

namespace Drupal\jsonapi_schema\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class EntryPointLinkProvider.
 *
 * @internal
 */
class EntryPointSchemaLinkProviderDeriver extends DeriverBase implements ContainerDeriverInterface {

  /**
   * The JSON:API resource type repository.
   *
   * @var \Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface
   */
  protected $resourceTypeRepository;

  /**
   * MutableEntityLinkProviderDeriver constructor.
   *
   * @param \Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface $resource_type_repository
   *   The JSON:API resource type repository.
   */
  public function __construct(ResourceTypeRepositoryInterface $resource_type_repository) {
    $this->resourceTypeRepository = $resource_type_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new static(
      $container->get('jsonapi.resource_type.repository')
    );
  }

  public function getDerivativeDefinitions($base_plugin_definition) {
    $resource_types = array_filter($this->resourceTypeRepository->all(), function (ResourceType $resource_type) {
      return !$resource_type->isInternal();
    });
    $derivative_definitions = array_reduce($resource_types, function ($derivative_definitions, ResourceType $resource_type) use ($base_plugin_definition) {
      $resource_type_name = $resource_type->getTypeName();
      $schema_type = $resource_type->isLocatable() ? 'collection' : 'item';
      $schema_route_name = "jsonapi_schema.{$resource_type_name}.{$schema_type}";
      $derivative_definitions[$resource_type_name] = array_merge([
        'link_key' => $resource_type_name,
        'default_configuration' => [
          'schema_route_name' => $schema_route_name,
          'schema_type' => $schema_type,
        ],
      ], $base_plugin_definition);
      return $derivative_definitions;
    }, []);
    return $derivative_definitions;
  }

}
