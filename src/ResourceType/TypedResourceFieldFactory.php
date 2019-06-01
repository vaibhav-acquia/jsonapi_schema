<?php

namespace Drupal\jsonapi_schema\ResourceType;

use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\jsonapi\ResourceType\ResourceFieldFactory;
use Drupal\jsonapi\ResourceType\ResourceFieldFactoryInterface;
use Drupal\jsonapi\ResourceType\ResourceTypeFieldInterface;
use Drupal\jsonapi\ResourceType\ResourceTypeRelationshipInterface;
use Drupal\jsonapi_schema\StaticDataDefinitionExtractor;

/**
 * Creates resource fields for configuration and content entities.
 *
 * @internal
 */
class TypedResourceFieldFactory implements ResourceFieldFactoryInterface {

  /**
   * The data definition extractor.
   *
   * @var \Drupal\jsonapi_schema\StaticDataDefinitionExtractor
   */
  protected $dataDefinitionExtractor;

  /**
   * The resource field factory.
   *
   * @var \Drupal\jsonapi\ResourceType\ResourceFieldFactoryInterface
   */
  protected $inner;

  /**
   * TypedResourceFieldFactory constructor.
   *
   * @param \Drupal\jsonapi\ResourceType\ResourceFieldFactoryInterface $inner
   *   The decorated service.
   * @param \Drupal\jsonapi_schema\StaticDataDefinitionExtractor $data_definition_extractor
   *   The data definition extractor.
   */
  public function __construct(ResourceFieldFactoryInterface $inner, StaticDataDefinitionExtractor $data_definition_extractor) {
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
   * @return \Drupal\jsonapi_schema\ResourceType\TypedResourceTypeFieldInterface
   *   The resource field.
   */
  public function createResourceField(EntityTypeInterface $entity_type, $bundle, $field_name, $alias) {
    $resource_field = $this->inner->createResourceField($entity_type, $bundle, $field_name, $alias);
    return $this->decorateResourceField($resource_field, $entity_type, $bundle);
  }

  /**
   * {@inheritdoc}
   */
  public function createResourceFields(EntityTypeInterface $entity_type, $bundle) {
    $resource_fields = $this->inner->createResourceFields($entity_type, $bundle);
    return array_map(function ($resource_field) use ($entity_type, $bundle) {
      if (!$resource_field instanceof ResourceTypeFieldInterface) {
        return $resource_field;
      }
      return $this->decorateResourceField($resource_field, $entity_type, $bundle);
    }, $resource_fields);
  }

  /**
   * {@inheritdoc}
   */
  public static function isReference(DataDefinitionInterface $data_definition) {
    return ResourceFieldFactory::isReference($data_definition);
  }

  /**
   * {@inheritdoc}
   */
  public function getAllFieldNames(EntityTypeInterface $entity_type, $bundle) {
    return $this->inner->getAllFieldNames($entity_type, $bundle);
  }

  /**
   * Decorates a resource field to add the data definitions.
   *
   * @param \Drupal\jsonapi\ResourceType\ResourceTypeFieldInterface $resource_field
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   * @param $bundle
   *
   * @return \Drupal\jsonapi_schema\ResourceType\TypedResourceTypeFieldInterface
   */
  private function decorateResourceField(ResourceTypeFieldInterface $resource_field, EntityTypeInterface $entity_type, $bundle) {
    $typed_resource_field = $resource_field instanceof ResourceTypeRelationshipInterface
      ? new TypedResourceTypeRelationship($resource_field)
      : new TypedResourceTypeAttribute($resource_field);
    $data_definition = $this->dataDefinitionExtractor->extractField(
      $entity_type,
      $bundle,
      $resource_field->getInternalFieldName()
    );
    $typed_resource_field->setDataDefinition($data_definition);
    return $typed_resource_field;
  }

}
