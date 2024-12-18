<?php

declare(strict_types=1);

namespace Drupal\jsonapi_schema;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\ContentEntityStorageInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\ParamConverter\ParamConverterManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\jsonapi\JsonApiResource\IncludedData;
use Drupal\jsonapi\JsonApiResource\JsonApiDocumentTopLevel;
use Drupal\jsonapi\JsonApiResource\LinkCollection;
use Drupal\jsonapi\JsonApiResource\Relationship;
use Drupal\jsonapi\JsonApiResource\ResourceObject;
use Drupal\jsonapi\JsonApiResource\ResourceObjectData;
use Drupal\jsonapi\JsonApiResource\TopLevelDataInterface;
use Drupal\jsonapi\JsonApiSpec;
use Drupal\jsonapi\Normalizer\Value\CacheableNormalization;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A service for generating schemas of JSON:API resources.
 */
final class SchemaGenerator implements SchemaGeneratorInterface, ResetInterface {

  use ResourceTypeResolutionTrait;
  use StringTranslationTrait;

  /**
   * Stub data storage.
   */
  protected \SplObjectStorage $stubDataStorage;

  /**
   * Resource types.
   *
   * @var \Drupal\jsonapi\ResourceType\ResourceType[]
   */
  protected array $resourceTypes;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected SerializerInterface $coreSerializer,
    protected ResourceTypeRepositoryInterface $resourceTypeRepository,
    protected ParamConverterManagerInterface $paramConverterManager,
    protected EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    protected EntityFieldManagerInterface $entityFieldManager,

  ) {
    $this->stubDataStorage = new \SplObjectStorage();
  }

  /**
   * {@inheritdoc}
   */
  public function reset() {
    $this->stubDataStorage->removeAllExcept(new \SplObjectStorage());
    $this->resourceTypes = [];
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityTypeJsonSchema(string $entity_type_id, ?string $bundle = NULL): array {
    // JSON:API module specific note - this gets resource type schemas, only;
    // top-level schema is handled in its own method.
    $additional = [];
    $resourceType = $this->loadResourceType($entity_type_id, $bundle);
    $stub_resource = $this->getStubResourceObjectData($resourceType);
    if (($example_normalization = $this->coreSerializer->normalize($stub_resource->getData()->getIterator()->current(), 'api_json', ['account' => NULL])) && $example_normalization instanceof CacheableNormalization) {
      $additional['examples'][] = $example_normalization->getNormalization();
    }
    if ($this->entityTypeManager->getDefinition($entity_type_id)->entityClassImplements(ConfigEntityInterface::class)) {
      $schema = ['$comment' => 'Config entity schemas not yet supported.'];
    }
    else {
      $dataSchema = array_filter(
        $this->coreSerializer->normalize(
          new JsonApiDocumentTopLevel($stub_resource, new IncludedData([]), new LinkCollection([])),
          'json_schema',
          ['account' => NULL]
        )['properties']['data']['oneOf'],
        fn (array $a) => $a !== ['type' => 'null']
      );
      $schema = count($dataSchema) === 1
        ? current($dataSchema)
        : ['oneOf' => $dataSchema];
    }
    return $schema + $additional;
  }

  /**
   * Checks if the relationship is to-many.
   *
   * @param string $route_name
   *   The route name.
   * @param \Drupal\jsonapi\ResourceType\ResourceType $resource_type
   *   The resource type.
   *
   * @return bool
   *   Indicates if the relationship is multiple cardinality.
   */
  protected function isToManyRelationship($route_name, ResourceType $resource_type) {
    $public_field_name = explode('.', $route_name)[2];
    $internal_field_name = $resource_type->getInternalName($public_field_name);
    if ($resource_type->getBundle() !== NULL) {
      $field_definitions = $this->entityFieldManager
        ->getFieldDefinitions($resource_type->getEntityTypeId(), $resource_type->getBundle());
      return $field_definitions[$internal_field_name]->getFieldStorageDefinition()->getCardinality() !== 1;
    }
    // If the bundle isn't specified, then we need to dig deeper.
    $storage_definitions = $this->entityFieldManager->getFieldStorageDefinitions($resource_type->getEntityTypeId());
    if (array_key_exists($internal_field_name, $storage_definitions)) {
      return $storage_definitions[$internal_field_name]->getCardinality() !== 1;
    }
    // At this point, we have a computed or non-stored field but no bundle to
    // query the field manager.
    foreach (array_keys($this->entityTypeBundleInfo->getBundleInfo($resource_type->getEntityTypeId())) as $b) {
      $field_definitions = $this->entityFieldManager
        ->getFieldDefinitions($resource_type->getEntityTypeId(), $b);
      if (array_key_exists($internal_field_name, $field_definitions)) {
        // This might not be particularly ideal but there's no other option but
        // to match the first instance of the field name we find.
        return $field_definitions[$internal_field_name]->getFieldStorageDefinition()->getCardinality() !== 1;
      }
    };
    throw new \LogicException('Could not determine cardinality for field.');
  }

  /**
   * Build a relationship object schema for target resource types & cardinality.
   *
   * @param array $targetResourceTypes
   *   Array of target resource types.
   * @param bool $toMany
   *   If this is a to-many relationship.
   *
   * @return array
   *   Schema for the relationship.
   */
  protected static function buildRelationshipObjectSchema(array $targetResourceTypes, bool $toMany): array {
    $relationshipSchema = [
      'allOf' => [
        ['$ref' => JsonApiSpec::SUPPORTED_SPECIFICATION_JSON_SCHEMA . '#/definitions/relationship'],
      ]
    ];
    $linkageSchema['properties']['type']['enum'] = array_map(
      fn (ResourceType $r) => $r->getTypeName(),
      $targetResourceTypes
    );
    $relationshipSchema['properties']['data'] = $toMany
      ? [
        'type' => 'array',
        // The relationship schema defines the rest of the requirement.
        'items' => $linkageSchema,
      ]
      : $linkageSchema;
    return $relationshipSchema;
  }

  /**
   * @inheritDoc
   */
  public function getSchemaForRoute(ResourceType $resource_type, string $method, string $route_name, Route $route = NULL): array {
    $definitionReferences = [];
    // Collect the references so we can include them in the final schema.
    $definitionReferenceFn = function (string $entityTypeId, string $bundle) use (&$definitionReferences): string {
      $id = sprintf('%s_%s', $entityTypeId, $bundle);
      if (!array_key_exists($id, $definitionReferences)) {
        // @todo Point this to the canonical route.
        $definitionReferences[$id] = $this->getEntityTypeJsonSchema($entityTypeId, $bundle);
      }
      return sprintf('#/$defs/%s', $id);
    };
    $responses = $this->getResponsesForRoute(
      $method,
      $route_name,
      $definitionReferenceFn,
      $route
    );
    $schema = [
      'oneOf' => [],
    ];
    foreach ($responses as $code => $response) {
      if ((int) $code >= 300 || $code < 200) {
        continue;
      }
      $schema['oneOf'][] = $response;
    }
    if (count($schema['oneOf']) === 1) {
      $schema = current($schema['oneOf']);
    }
    return $schema + ['$defs' => $definitionReferences];
  }

  /**
   * {@inheritdoc}
   */
  public function getResponsesForRoute(string $method, string $route_name, callable $definitionReferenceFn, Route $route): array {
    // Standardize.
    $method = strtoupper($method);
    if (!in_array($method, $route->getMethods())) {
      throw new \LogicException('Method requested is not in route\'s supported method list.');
    }
    // The resource types array should now contain a full accounting of resource
    // type definitions for this route.
    /** @var $resourceTypes ResourceType[] */
    if (!$resourceTypes = $this->getResourceTypesForRoute($route)) {
      throw new \InvalidArgumentException('Could not determine resource type(s) from route object.');
    }

    // The logic below is multi-resource/cross-bundle compatible, which is why
    // it is a bit verbose, but will also work on single-resource type routes.
    $routeType = $this->getRouteTypeByName($route_name);
    if (in_array($routeType, ['collection', 'individual'])) {
      // This is a special case, where we may comingle resource types in the
      // "data" property. There's no need to query anything beyond resource
      // type targets.
      $targetSchemas = array_map(
        fn (ResourceType $r) => ['$ref' => $definitionReferenceFn($r->getEntityTypeId(), $r->getBundle())],
        $resourceTypes
      );
      $combinedResponse = [
        'description' => count($resourceTypes) > 1
          ? $this->t('Collection response contains resources from multiple related resource types: @types', ['@types' => implode(', ', array_map(fn (ResourceType $r) => $r->getTypeName(), $resourceTypes))])
          : $this->t('Collection response of @type', ['@type' => current($resourceTypes)->getTypeName()]),
        'content' => [
          'application/vnd.api+json' => [
            'schema' => [
              'allOf' => [
                ['$ref' => JsonApiSpec::SUPPORTED_SPECIFICATION_JSON_SCHEMA . '#'],
              ],
              'properties' => [
                'data' => [
                  'type' => 'array',
                  'items' => count($targetSchemas) > 1
                    ? [$routeType === 'collection' ? 'anyOf' : 'oneOf' => $targetSchemas]
                    : current($targetSchemas),
                ],
              ],
            ],
          ],
        ],
      ];
      if ($routeType === 'collection') {
        return [(string) Response::HTTP_OK => $combinedResponse];
      }
      return match($method) {
        'GET' => [(string) Response::HTTP_OK => ['description' => 'successful operation'] + $combinedResponse],
        'POST' => [(string) Response::HTTP_CREATED => ['description' => 'Entity created'] + $combinedResponse],
        'DELETE' => [(string) Response::HTTP_NO_CONTENT => ['description' => 'Entity deleted']],
        'PATCH' => [(string) Response::HTTP_OK => ['description' => 'Entity patched'] + $combinedResponse],
        default => throw new \LogicException(sprintf('Method %s not supported.', $method)),
      };
    }
    // Relationships and related.
    elseif (in_array($routeType, ['relationship', 'related'])) {
      $route_resource_type = $this->getResourceTypeFromRoute($route_name, $route);
      $target_resource_types = $this->relatedResourceTypes($route_name, $route);
      $is_multiple = $this->isToManyRelationship($route_name, $route_resource_type);
      assert($route->hasDefault('related'));
      $relatedField = $route->getDefault('related');
      // Relationship and related routes for cross-bundles do support mutability,
      // but not all fields may be present on all bundles, so some special
      // handling is in order.

      // A cross-bundle resource type specifies an entity type ID, but not
      // a bundle (it represents them all). In that case, the stub object
      // must be generated with a bundle that includes the field in question.
      if (is_a($route_resource_type, 'Drupal\jsonapi_cross_bundles\ResourceType\CrossBundlesResourceType')) {
        $fields = $this->entityFieldManager->getFieldMap()[$route_resource_type->getEntityTypeId()];
        $fieldInternalName = $route_resource_type->getFieldByPublicName($relatedField)->getInternalName();
        if (!$bundles = $fields[$fieldInternalName]['bundles'] ?? []) {
          // Bundle fields are not (presently) discoverable by ::getFieldMap().
          // @see https://www.drupal.org/project/drupal/issues/3045509
          // @see https://github.com/farmOS/farmOS/pull/854/files
          // A proper resolution to this involves a core patch, but we can't
          // require that for a relative edge case. Go the long way around the
          // horse here, with a note to revisit.
          // @todo Update this after https://www.drupal.org/project/drupal/issues/3129179 lands.
          foreach ($resourceTypes as $resourceType) {
            if (array_key_exists($fieldInternalName, $this->entityFieldManager->getFieldDefinitions($resourceType->getEntityTypeId(), $resourceType->getBundle()))) {
              $bundles = [$resourceType->getBundle()];
              break;
            }
          }
        }
        // Generate a stub from the first bundle containing this field.
        $stubObject = $this->getStubResourceObjectData(
          $this->resourceTypeRepository->get($route_resource_type->getEntityTypeId(), current($bundles))
        );
      }
      else {
        $stubObject = $this->getStubResourceObjectData($route_resource_type);
      }
      $resourceObject = current($stubObject->getData()->toArray());
      assert($resourceObject instanceof ResourceObject);
      $fieldItemList = $resourceObject->getField($relatedField);
      // Relationship route. May be mutable.
      if ($routeType === 'relationship') {
        // Lazy schema generation, if necessary.
        $schema = function () use ($target_resource_types, $is_multiple) {
          return SchemaGenerator::buildRelationshipObjectSchema($target_resource_types, $is_multiple);
        };
        if ($method === 'GET') {
          return [
            (string) Response::HTTP_OK => [
              'description' => 'Successful operation.',
              'content' => ['application/vnd.api+json' => [
                'schema' => $fieldItemList
                  ? $this->getTopLevelSchema(
                    Relationship::createFromEntityReferenceField($resourceObject, $fieldItemList),
                    // We don't use $is_multiple here because this represents an
                    // actual field relationship, so it's known and specific.
                    $fieldItemList->getFieldDefinition()->getFieldStorageDefinition()->getCardinality(),
                    $definitionReferenceFn
                  )
                  // @todo Handle this as a separate response code.
                  : ['$comment' => sprintf('Field does not exist on bundle %s', $resourceObject->getResourceType()->getBundle())]
              ]],
            ],
          ];
        }
        elseif ($method === 'POST') {
          return [(string) Response::HTTP_CREATED => ['description' => 'created', 'content' => ['application/vnd.api+json' => [
            'schema' => [
              'allOf' => [
                ['$ref' => JsonApiSpec::SUPPORTED_SPECIFICATION_JSON_SCHEMA . '#'],
              ],
              'properties' => ['data' => $schema()],
            ]
          ]]]];
        }
        elseif ($method === 'PATCH') {
          return [
            (string) Response::HTTP_OK => [
              'description' => 'successful operation',
              'content' => ['application/vnd.api+json' => [
                'schema' => [
                  'allOf' => [
                    ['$ref' => JsonApiSpec::SUPPORTED_SPECIFICATION_JSON_SCHEMA . '#'],
                  ],
                  'properties' => ['data' => $schema()],
                ]
              ]],
            ],
          ];
        }
        elseif ($method === 'DELETE') {
          return [(string) Response::HTTP_NO_CONTENT => ['description' => 'no content']];
        }
      }
      // Related route, immutable, actually returns the referenced entity.
      else {
        // A single field may target multiple resource types, e.g. DER.
        $mockData = array_reduce(
          array_map(fn (ResourceType $r) => $this->getStubResourceObjectData($r, 1), $target_resource_types),
          fn (ResourceObjectData $carry, ResourceObjectData $r) => ResourceObjectData::merge($carry, $r),
          new ResourceObjectData([], FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
        );
        $schema_response = ['content' => ['application/vnd.api+json' => [
          'schema' => $this->getTopLevelSchema($mockData, $fieldItemList->getFieldDefinition()->getFieldStorageDefinition()->getCardinality(), $definitionReferenceFn),
        ]]];
        $responses[(string) Response::HTTP_OK] = [
            'description' => 'successful operation',
          ] + $schema_response;
        return $responses;
      }
    }
    throw new \LogicException(sprintf('Could not determine appropriate schema generation for route %s', $route_name));
  }

  /**
   * Get schema for a top-level data object.
   *
   * We _could_ simply get the schema for the top-level document here, however
   * that would mean we ship separate schemas for collections and individual
   * resources, which is a lot of boilerplate considering the only difference
   * is whether the data element is an array or a single member. The code here
   * is initially lifted from JsonApiDocumentTopLevelNormalizer, but is
   * refined to allow re-usability of the contained resource type's schema.
   *
   * @param TopLevelDataInterface $object
   *   Top-level data supported for schema generation.
   * @param ?int $overrideCardinality
   *   Override for cardinality.
   *
   * @return array
   *   Schema for the top-level object.
   */
  protected function getTopLevelSchema(TopLevelDataInterface $object, ?int $overrideCardinality = NULL, ?callable $definitionReferenceFn = NULL): array {
    $cardinality = $overrideCardinality ?? $object->getCardinality() ?? $object->getData()->getCardinality();
    $schema = [
      'allOf' => [
        ['$ref' => JsonApiSpec::SUPPORTED_SPECIFICATION_JSON_SCHEMA . '#'],
      ],
    ];

    // Top-level JSON:API documents may contain top-level data or an error
    // collection.
    // Relationship data - "resource identifier object(s)"
    if ($object instanceof Relationship) {
      // @todo Abstract and reference these schemas with a discriminator.
      $relationship_schema = $this->coreSerializer->normalize($object, 'json_schema');
      if ($cardinality === 1) {
        $schema['properties']['data'] = $relationship_schema;
      }
      else {
        $schema['properties']['data'] = [
          'type' => 'array',
          'items' => $relationship_schema,
        ];
        if ($cardinality !== FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED) {
          $schema['properties']['data']['maxContains'] = $cardinality;
        }
      }
    }
    else if ($object instanceof ResourceObjectData) {
      $data = $object->getData();
      // @todo Abstract and reference these schemas with a discriminator.
      $resource_object_schema = [
        'anyOf' => array_map(
          fn (ResourceObject $o) => ['$ref' => $definitionReferenceFn($o->getResourceType()->getEntityTypeId(), $o->getResourceType()->getBundle())],
          $data->getData()->toArray()
        ),
      ];
      if (count($resource_object_schema['anyOf']) === 1) {
        $resource_object_schema = current($resource_object_schema['anyOf']);
      }
      if ($cardinality === 1) {
        $schema['properties']['data'] = $resource_object_schema;
      }
      else {
        $schema['properties']['data'] = [
          'type' => 'array',
          'items' => $resource_object_schema,
        ];
        if ($cardinality !== FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED) {
          $schema['properties']['data']['maxContains'] = $cardinality;
        }
      }
    }
    return $schema;
  }
  /**
   * Gets the entity definition key.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param string|null $bundle_name
   *   The bundle name.
   *
   * @return string
   *   The entity definition key. Either [entity_type] or
   *   [entity_type]:[bundle_name]
   */
  protected function getEntityDefinitionKey($entity_type_id, ?string $bundle_name) {
    $definition_key = $entity_type_id;
    if ($bundle_name) {
      $definition_key .= '-' . $bundle_name;
    }
    return $definition_key;
  }

  protected function normalizeResourceTypeId(string $entity_type_id, ?string $bundle = NULL): string {
    if (!$bundle) {
      $bundle = $entity_type_id;
    }
    return "{$entity_type_id}-{$bundle}";
  }

  /**
   * Load a resource type and also mark it to be included in the schemas object.
   *
   * @param string $entity_type_id
   *   Entity Type ID.
   * @param string|null $bundle
   *   Bundle name.
   *
   * @return ResourceType
   *   The resource type.
   */
  protected function loadResourceType(string $entity_type_id, ?string $bundle = NULL): ResourceType {
    $definition_key = $this->normalizeResourceTypeId($entity_type_id, $bundle);
    if (empty($this->resourceTypes[$definition_key])) {
      if (!$this->resourceTypes[$definition_key] = $this->resourceTypeRepository->get($entity_type_id, $bundle ?: $entity_type_id)) {
        throw new \InvalidArgumentException(sprintf('The resource type for %s/%s could not be loaded.', $entity_type_id, $bundle ?: $entity_type_id));
      }
    }
    return $this->resourceTypes[$definition_key];
  }

  protected function getStubResourceObjectData(ResourceType $resource_type, int $cardinality = 1): ResourceObjectData {
    if (!$this->stubDataStorage->offsetExists($resource_type)) {
      $entity_storage = $this->entityTypeManager->getStorage($resource_type->getEntityTypeId());
      $stub_entity = $entity_storage instanceof ContentEntityStorageInterface
        ? $entity_storage->createWithSampleValues($resource_type->getBundle())
        : $entity_storage->create();
      $this->stubDataStorage->offsetSet(
        $resource_type,
        $stub_entity
      );
    }
    else {
      $stub_entity = $this->stubDataStorage->offsetGet($resource_type);
    }
    return new ResourceObjectData([ResourceObject::createFromEntity(
      $resource_type,
      $stub_entity
    )], $cardinality);
  }

}
