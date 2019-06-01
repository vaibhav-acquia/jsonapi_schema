<?php

namespace Drupal\jsonapi_schema\ResourceType;

use Drupal\Core\Entity\TypedData\EntityDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\jsonapi\ResourceType\ResourceTypeFieldInterface;
use Drupal\jsonapi\ResourceType\ResourceTypeRelationshipInterface;

/**
 * Adds data definition information to resource type fields.
 */
trait TypedResourceTypeFieldTrait {

  /**
   * @var \Drupal\Core\TypedData\DataDefinitionInterface
   */
  private $dataDefinition;

  /**
   * @var \Drupal\jsonapi\ResourceType\ResourceTypeFieldInterface
   */
  private $inner;

  /**
   * {@inheritdoc}
   */
  public function setDataDefinition(DataDefinitionInterface $data_definition) {
    $this->dataDefinition = $data_definition;
  }

  /**
   * {@inheritdoc}
   */
  public function getDataDefinition() {
    return $this->dataDefinition;
  }

  /**
   * {@inheritdoc}
   */
  public function getInternalFieldName() {
    return $this->inner->getInternalFieldName();
  }

  /**
   * {@inheritdoc}
   */
  public function getPublicFieldName() {
    return $this->inner->getPublicFieldName();
  }

  /**
   * {@inheritdoc}
   */
  public function hasMany() {
    $data_definition = $this->getDataDefinition();
    // If we are dealing with a content entity, we can trust the isMany because
    // its based on the cardinality in the field storage. If not use the data
    // definition instead.
    return $data_definition instanceof EntityDataDefinitionInterface
      ? $this->inner->hasMany()
      : $data_definition instanceof ListDataDefinitionInterface;
  }

}
