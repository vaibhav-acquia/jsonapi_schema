<?php

namespace Drupal\jsonapi_schema\ResourceType;

use Drupal\jsonapi\ResourceType\ResourceTypeFieldInterface;

/**
 * Extends the resource type attribute to add data definition information.
 */
class TypedResourceTypeAttribute implements TypedResourceTypeFieldInterface {

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

}
