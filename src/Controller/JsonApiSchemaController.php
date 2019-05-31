<?php

namespace Drupal\jsonapi_schema\Controller;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\jsonapi\ResourceType\ResourceFieldInterface;
use Drupal\jsonapi\ResourceType\ResourceRelationship;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class JsonApiSchemaController extends ControllerBase {

  const JSON_SCHEMA_DRAFT = 'http://json-schema.org/draft-07/schema';

  const JSONAPI_BASE_SCHEMA_URI = 'https://jsonapi.org/schema';

  protected $resourceTypeRepository;

  /**
   * The serialization service.
   *
   * @var \Symfony\Component\Serializer\Normalizer\NormalizerInterface
   */
  protected $normalizer;

  /**
   * JsonApiSchemaController constructor.
   *
   * @param \Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface $resource_type_repository
   *   The JSON:API resource type repository.
   * @param \Symfony\Component\Serializer\Normalizer\NormalizerInterface $normalizer
   *   The serializer.
   */
  public function __construct(ResourceTypeRepositoryInterface $resource_type_repository, NormalizerInterface $normalizer) {
    $this->resourceTypeRepository = $resource_type_repository;
    $this->normalizer = $normalizer;
  }

  public function getDocumentSchema(Request $request, $resource_type, $route_type) {
    $schema = [
      '$schema' => static::JSON_SCHEMA_DRAFT,
      '$id' => $request->getUri(),
      'allOf' =>  [
        [
          '$ref' => static::JSONAPI_BASE_SCHEMA_URI,
        ],
        [
          'if' => [
            '$ref' => static::JSONAPI_BASE_SCHEMA_URI . '#/definitions/success',
          ],
          'then' => [
            'type' => 'object',
            'properties' => [
              'data' => [
                '$ref' => '#/definitions/data',
              ],
            ],
            'required' => ['data'],
          ],
        ],
      ],
    ];
    $cacheability = new CacheableMetadata();
    $get_schema_ref = function ($resource_type) use ($cacheability) {
      $schema_url = Url::fromRoute("jsonapi_schema.$resource_type.type")->setAbsolute()->toString(TRUE);
      $cacheability->addCacheableDependency($schema_url);
      return ['$ref' => $schema_url->getGeneratedUrl()];
    };
    $type_schema = is_array($resource_type)
      ? ['anyOf' => array_map($get_schema_ref, $resource_type)]
      : $get_schema_ref($resource_type);
    switch ($route_type) {
      case 'item':
        $schema['definitions']['data'] = $type_schema;
        break;
      case 'collection':
        $schema['definitions']['data'] = [
          'type' => 'array',
          'items' => $type_schema,
        ];
        break;
      case 'relationship':
        assert('not implemented');
        break;
    }
    return CacheableJsonResponse::create($schema)->addCacheableDependency($cacheability);
  }

  public function getResourceObjectSchema(Request $request, $resource_type) {
    $resource_type = $this->resourceTypeRepository->getByTypeName($resource_type);
    $schema = [
      '$schema' => static::JSON_SCHEMA_DRAFT,
      '$id' => $request->getUri(),
      'allOf' => [
        [
          'type' => 'object',
          'properties' => [
            'attributes' => [
              '$ref' => '#/definitions/attributes',
            ],
            'relationships' => [
              '$ref' => '#/definitions/relationships',
            ],
          ],
        ],
        [
          '$ref' => static::JSONAPI_BASE_SCHEMA_URI . '#/definitions/resource',
        ]
      ],
    ];
    $cacheability = new CacheableMetadata();
    $schema = $this->addFieldsSchema($schema, $resource_type);
    $schema = $this->addRelationshipsSchemaLinks($schema, $resource_type, $cacheability);
    return CacheableJsonResponse::create($schema)->addCacheableDependency($cacheability);
  }

  protected function addFieldsSchema(array $schema, ResourceType $resource_type) {
    $resource_attributes = $resource_type->getResourceFields();
    if (empty($resource_attributes)) {
      return $schema;
    }
    $schema['properties']['attributes'] = [
      '$ref' => '#/definitions/attributes',
    ];
    $normalizer = $this->normalizer;
    $fields = array_reduce($resource_attributes, function ($carry, ResourceFieldInterface $attribute) use ($normalizer){
      $json_schema = $normalizer->normalize(
        $attribute->getDataDefinition(),
        'schema_json',
        ['name' => $attribute->getAlias()]
      );
      return NestedArray::mergeDeep($carry, $json_schema);
    }, []);
    $field_definitions = NestedArray::getValue($fields, ['properties']) ?: [];
    if (!empty($field_definitions['attributes'])) {
      $field_definitions['attributes']['additionalProperties'] = FALSE;
    }
    if (!empty($field_definitions['relationships'])) {
      $field_definitions['relationships']['additionalProperties'] = FALSE;
    }
    $schema['definitions'] = $field_definitions;
    return $schema;
  }

  protected static function addRelationshipsSchemaLinks(array $schema, ResourceType $resource_type, CacheableMetadata $cacheability) {
    $resource_relationships = $resource_type->getResourceRelationships();
    if (empty($resource_relationships)) {
      return $schema;
    }
    $relationships = array_reduce($resource_relationships, function ($relationships, ResourceRelationship $relationship) use ($resource_type, $cacheability) {
      $field_name = $relationship->getAlias();
      $resource_type_name = $resource_type->getTypeName();
      $related_route_name = "jsonapi_schema.{$resource_type_name}.$field_name.related";
      $related_schema_uri = Url::fromRoute($related_route_name)->setAbsolute()->toString(TRUE);
      $cacheability->addCacheableDependency($related_schema_uri);
      $drill_prop_into_a_nested_object_schema = function ($path, $prop) {
        return array_reduce(array_reverse(explode('.', $path)), function ($prop, $prop_name) {
          return ['type' => 'object', 'properties' => [$prop_name => $prop]];
        }, $prop);
      };
      $drilled_object = $drill_prop_into_a_nested_object_schema('links.related.meta.linkParams.describedBy', ['const' => $related_schema_uri->getGeneratedUrl()]);
      return array_merge($relationships, [$field_name => $drilled_object]);
    }, []);
    $schema['definitions']['relationships'] = [
      'type' => 'object',
      'properties' => $relationships,
      'additionalProperties' => FALSE,
    ];
    $schema['definitions']['relationships'] = NestedArray::mergeDeep(
      empty($schema['definitions']['relationships']) ? [] : $schema['definitions']['relationships'],
      ['properties' => $relationships]
    );
    return $schema;
  }

}
