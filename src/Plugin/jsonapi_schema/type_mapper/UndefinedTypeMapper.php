<?php

namespace Drupal\jsonapi_schema\Plugin\jsonapi_schema\type_mapper;

use Drupal\Core\TypedData\DataDefinitionInterface;

/**
 * Converts Data Definition properties of the undefined to JSON Schema.
 *
 * @TypeMapper(
 *  id = "undefined"
 * )
 */
class UndefinedTypeMapper extends TypeMapperBase {

  /**
   * {@inheritdoc}
   */
  public function getMappedValue(DataDefinitionInterface $property) {
    return [];
  }

}
