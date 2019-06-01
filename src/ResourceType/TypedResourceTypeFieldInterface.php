<?php

namespace Drupal\jsonapi_schema\ResourceType;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\jsonapi\ResourceType\ResourceTypeFieldInterface;

/**
 * TODO: Give this class a brief description.
 */
interface TypedResourceTypeFieldInterface extends ResourceTypeFieldInterface {

  /**
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $data_definition
   *
   * @return mixed
   */
  public function setDataDefinition(DataDefinitionInterface $data_definition);

  /**
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   */
  public function getDataDefinition();

}
