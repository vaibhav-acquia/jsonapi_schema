<?php

namespace Drupal\jsonapi_schema\Normalizer;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Field\Plugin\Field\FieldType\BooleanItem;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\OptionsProviderInterface;
use Drupal\serialization\Normalizer\NormalizerBase;

/**
 * Normalizer for DataDefinitionInterface instances.
 *
 * DataDefinitionInterface is the ultimate parent to all data definitions. This
 * service must always be low priority for data definitions, otherwise the
 * simpler normalization process it supports will take precedence over all the
 * complexities most entity properties contain before reaching this level.
 *
 * DataDefinitionNormalizer produces scalar value definitions.
 *
 * All the TypedData normalizers extend from this class.
 */
class DataDefinitionNormalizer extends NormalizerBase {

  const JSON_TYPES = ['null', 'boolean', 'string', 'number', 'integer', 'array', 'object'];

  /**
   * The formats that the Normalizer can handle.
   *
   * @var array
   */
  protected $format = 'schema_json';

  /**
   * The interface or class that this Normalizer supports.
   *
   * @var string
   */
  protected $supportedInterfaceOrClass = DataDefinitionInterface::class;

  /**
   * The supported data type.
   *
   * @var string[]
   */
  protected $supportedDataTypes = [];

  /**
   * {@inheritdoc}
   */
  public function normalize($entity, $format = NULL, array $context = []) {
    assert($entity instanceof DataDefinitionInterface);
    // `text source` and `date source` produce objects not supported in the API.
    // It is not clear how the API excludes them.
    // @todo properly identify and exclude this class of computed objects.
    if (
      $entity->getSetting('text source')
      || $entity->getSetting('date source')
    ) {
      return [];
    }

    $property = $this->extractPropertyData($entity, $context);
    if (!is_object($property) && !empty($context['parent']) && $context['name'] == 'value') {
      /** @var \Drupal\Core\Field\TypedData\FieldItemDataDefinitionInterface $parent */
      $parent = $context['parent'];

      if ($maxLength = $parent->getSetting('max_length')) {
        $property['maxLength'] = $maxLength;
      }

      // Get possible options for anyOf/oneOf.
      if (!empty($context['resource_type'])) {
        /** @var \Drupal\jsonapi\ResourceType\ResourceType $resource_type */
        $resource_type = $context['resource_type'];
        $field_definition = $context['parent']->getFieldDefinition();
        $field_storage_definition = $field_definition->getFieldStorageDefinition();
        if (is_subclass_of($field_storage_definition, ListDataDefinitionInterface::class)) {
          $class = $field_storage_definition->getItemDefinition()->getClass();
          if (is_subclass_of($class, OptionsProviderInterface::class) && $class != BooleanItem::class) {
            $entity_type = $resource_type->getEntityTypeId();
            $bundle = $resource_type->getBundle();
            /** @var \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager */
            $entity_type_manager = \Drupal::entityTypeManager();
            $entity_type_definition = $entity_type_manager->getDefinition($entity_type);
            $bundle_key = $entity_type_definition->getKey('bundle');
            /** @var \Drupal\Core\Entity\FieldableEntityInterface $mock_entity */
            $mock_entity = $entity_type_manager->getStorage($entity_type)->create([$bundle_key => $bundle]);
            $allowed_values = $field_storage_definition->getOptionsProvider($field_definition->getName(), $mock_entity)->getPossibleOptions();
            // Include titles for UI integration.
            // @see https://json-schema.org/understanding-json-schema/reference/generic.html?highlight=enum#annotations
            $composition = $context['cardinality'] === FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED ? 'anyOf' : 'oneOf';
            array_walk($allowed_values, function (&$v, $k) { $v = ['const' => $k, 'title' => $v]; });
            $property[$composition] = array_values($allowed_values);
          }
        }
      }
    }

    if (!is_object($property) && !isset($property['title']) && isset($context['name'])) {
      $property['title'] = $context['name'];
    }

    $normalized = ['properties' => []];
    if (!is_object($property) && !in_array($property['type'], static::JSON_TYPES)) {
      // Unable to find the correct type.
      \Drupal::logger('jsonapi_schema')->error('{type} is not a valid type for a JSON document.', ['type' => $property['type']]);
      $property = (object) [];
    }
    $normalized['properties'][$context['name']] = $property;
    if ($this->requiredProperty($entity)) {
      $normalized['required'][] = $context['name'];
    }

    return $normalized;
  }

  /**
   * Extracts property details from a data definition.
   *
   * This method includes mapping primitive types in Drupal to JSON Schema
   * type and format descriptions. This method is invoked by several of the
   * normalizers.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $property
   *   The data definition from which to extract values.
   * @param array $context
   *   Serializer context.
   *
   * @return array
   *   Discrete values of the property definition
   */
  protected function extractPropertyData(DataDefinitionInterface $property, array $context = []) {
    $value = ['type' => $property->getDataType()];
    if ($item = $property->getLabel()) {
      $value['title'] = $item;
    }
    if ($item = $property->getDescription()) {
      $value['description'] = addslashes(strip_tags($item));
    }

    return $value;
  }

  /**
   * Normalize an array of data definitions.
   *
   * This normalization process gets an array of properties and an array of
   * properties that are required by name. This is needed by the
   * SchemataSchemaNormalizer, otherwise it would have been placed in
   * DataDefinitionNormalizer.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface[] $items
   *   An array of data definition properties to be normalized.
   * @param string $format
   *   Format identifier of the current serialization process.
   * @param array $context
   *   Operating context of the serializer.
   *
   * @return array
   *   Array containing one or two nested arrays.
   *   - properties: The array of all normalized properties.
   *   - required: The array of required properties by name.
   */
  protected function normalizeProperties(array $items, $format, array $context = []) {
    $normalized = [];
    foreach ($items as $name => $property) {
      $context['name'] = $name;
      $item = $this->serializer->normalize($property, $format, $context);
      if (!empty($item)) {
        $normalized = NestedArray::mergeDeep($normalized, $item);
      }
    }

    return $normalized;
  }

  /**
   * Determine if the given property is a required element of the schema.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $property
   *   The data property to be evaluated.
   *
   * @return bool
   *   Whether the property should be treated as required for schema
   *   purposes.
   */
  protected function requiredProperty(DataDefinitionInterface $property) {
    // We must test all three values, because it may be possible a computed
    // field has its read-only value explicitly set to a contradictory value, in
    // which case the computed state is not taken into consideration. This is
    // thus the safest test.
    return $property->isRequired()
      && (!$property->isReadOnly() && !$property->isComputed());
  }

  /**
   * {@inheritdoc}
   */
  public function supportsNormalization($data, $format = NULL, array $context = []): bool {
    return parent::supportsNormalization($data, $format)
      && $data instanceof DataDefinitionInterface
      && (empty($this->supportedDataTypes) || in_array($data->getDataType(), $this->supportedDataTypes));
  }

  /**
   * {@inheritdoc}
   */
  public function supportsDenormalization($data, $type, $format = NULL, array $context = []): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function denormalize($data, $class, $format = NULL, array $context = []) {
    throw new \RuntimeException('Denormalization is not supported.');
  }

}
