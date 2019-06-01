<?php

namespace Drupal\jsonapi_schema\ResourceType;

use Drupal\jsonapi\ResourceType\ResourceTypeFieldInterface;
use Drupal\jsonapi\ResourceType\ResourceTypeRelationshipInterface;

/**
 * Extends the resource type relationship to add data definition information.
 */
class TypedResourceTypeRelationship implements TypedResourceTypeFieldInterface, ResourceTypeRelationshipInterface {

  use TypedResourceTypeFieldTrait;

  /**
   * TypedResourceTypeRelationship constructor.
   *
   * @param \Drupal\jsonapi\ResourceType\ResourceTypeFieldInterface $inner
   *   The decorated field type.
   */
  public function __construct(ResourceTypeFieldInterface $inner) {
    $this->inner = $inner;
  }

  /**
   * {@inheritdoc}
   */
  public function getRelatableResourceTypes() {
    assert($this->inner instanceof ResourceTypeRelationshipInterface);
    return $this->inner->getRelatableResourceTypes();
  }

  /**
   * {@inheritdoc}
   */
  public function setRelatableResourceTypes(array $relatable_resource_types) {
    assert($this->inner instanceof ResourceTypeRelationshipInterface);
    $this->inner->setRelatableResourceTypes($relatable_resource_types);
  }

}
