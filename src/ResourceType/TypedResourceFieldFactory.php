<?php

namespace Drupal\jsonapi_schema\ResourceType;

use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\jsonapi\ResourceType\ResourceFieldFactory;
use Drupal\jsonapi_schema\StaticDataDefinitionExtractor;

/**
 * Creates resource fields for configuration and content entities.
 *
 * @internal
 */
class TypedResourceFieldFactory {

  /**
   * The data definition extractor.
   *
   * @var \Drupal\jsonapi_schema\StaticDataDefinitionExtractor
   */
  protected $dataDefinitionExtractor;

  /**
   * The resource field factory.
   *
   * @var \Drupal\jsonapi\ResourceType\ResourceFieldFactory
   */
  protected $inner;

  /**
   * TypedResourceFieldFactory constructor.
   *
   * @param \Drupal\jsonapi\ResourceType\ResourceFieldFactory $inner
   *   The decorated service.
   * @param \Drupal\jsonapi_schema\StaticDataDefinitionExtractor $data_definition_extractor
   *   The data definition extractor.
   */
  public function __construct(ResourceFieldFactory $inner, StaticDataDefinitionExtractor $data_definition_extractor) {
    $this->inner = $inner;
    $this->dataDefinitionExtractor = $data_definition_extractor;
  }

  /**
   * Creates a ResourceFieldInterface object for a given field.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   * @param $bundle
   *   The entity bundle.
   * @param $field_name
   *   The field name this resource field is for.
   * @param $alias
   *   The aliased field name.
   *
   * @return \Drupal\jsonapi\ResourceType\ResourceFieldInterface
   *   The resource field.
   */
  public function createResourceField(EntityTypeInterface $entity_type, $bundle, $field_name, $alias) {
    return $entity_type instanceof ConfigEntityTypeInterface
      ? $this->createResourceFieldForConfigEntityType($entity_type, $bundle, $field_name, $alias)
      : $this->createResourceFieldForContentEntityType($entity_type, $bundle, $field_name, $alias);
  }

  /**
   * Creates a ResourceFieldInterface object for a given field.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityTypeInterface $entity_type
   *   The config entity type.
   * @param string $bundle
   *   The entity bundle.
   * @param string $field_name
   *   The field name this resource field is for.
   * @param string $alias
   *   The aliased field name.
   *
   * @return \Drupal\jsonapi\ResourceType\ResourceFieldInterface
   *   The resource field.
   */
  protected function createResourceFieldForConfigEntityType(ConfigEntityTypeInterface $entity_type, $bundle, $field_name, $alias) {
    $data_definition = $this->dataDefinitionExtractor->extractField($entity_type, $bundle, $field_name);
    $is_multiple = $data_definition instanceof ListDataDefinitionInterface;
    $resource_field = $this->inner->createResourceField($entity_type, $bundle, $field_name, $alias);
    $resource_field->setDataDefinition($data_definition);
    $resource_field->setIsMultiple($is_multiple);
    return $resource_field;
  }

  /**
   * Creates a ResourceFieldInterface object for a given field.
   *
   * @param \Drupal\Core\Entity\ContentEntityTypeInterface $entity_type
   *   The content entity type.
   * @param string $bundle
   *   The entity bundle.
   * @param string $field_name
   *   The field name this resource field is for.
   * @param string $alias
   *   The aliased field name.
   *
   * @return \Drupal\jsonapi\ResourceType\ResourceFieldInterface
   *   The resource field.
   */
  protected function createResourceFieldForContentEntityType(ContentEntityTypeInterface $entity_type, $bundle, $field_name, $alias) {
    $data_definition = $this->dataDefinitionExtractor->extractField($entity_type, $bundle, $field_name);
    $resource_field = $this->inner->createResourceField($entity_type, $bundle, $field_name, $alias);
    $resource_field->setDataDefinition($data_definition);
    return $resource_field;
  }

}
