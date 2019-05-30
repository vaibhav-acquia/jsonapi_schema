<?php

namespace Drupal\jsonapi_schema\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\jsonapi\ResourceType\ResourceTypeRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;

class JsonApiSchemaController extends ControllerBase {

  const JSON_SCHEMA_DRAFT = 'http://json-schema.org/draft-07/schema';

  const JSONAPI_BASE_SCHEMA_URI = 'https://jsonapi.org/schema';

  protected $resourceTypeRepository;

  public function __construct(ResourceTypeRepositoryInterface $resource_type_repository) {
    $this->resourceTypeRepository = $resource_type_repository;
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
    $field_names = static::getResourceFieldNames($resource_type);
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
    $schema = static::addAttributesSchema($schema, $field_names['attributes']);
    $schema = static::addRelationshipsSchema($resource_type, $schema, $cacheability);
    return CacheableJsonResponse::create($schema)->addCacheableDependency($cacheability);
  }

  protected static function addAttributesSchema(array $schema, $field_names) {
    if (empty($field_names)) {
      return $schema;
    }
    $schema['properties']['attributes'] = [
      '$ref' => '#/definitions/attributes',
    ];
    $attributes = array_fill_keys($field_names, (object) []);
    $schema['definitions']['attributes'] = [
      'type' => 'object',
      'properties' => $attributes,
      'additionalProperties' => FALSE,
    ];
    return $schema;
  }

  protected static function addRelationshipsSchema(ResourceType $resource_type, array $schema, CacheableMetadata $cacheability) {
    $field_names = static::getResourceFieldNames($resource_type)['relationships'];
    if (empty($field_names)) {
      return $schema;
    }
    $relationships = array_reduce($field_names, function ($relationships, $field_name) use ($resource_type, $cacheability) {
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
    return $schema;
  }

  protected static function getResourceFieldNames(ResourceType $resource_type) {
    $field_names = array_filter(array_map(function ($internal_field_name) use ($resource_type) {
      return $resource_type->isFieldEnabled($internal_field_name) ? $resource_type->getPublicName($internal_field_name) : FALSE;
    }, $resource_type->fields));
    $relationship_field_names = array_intersect($field_names, array_keys($resource_type->getrelatableresourcetypes()));
    $attribute_field_names = array_diff($field_names, $relationship_field_names);
    return [
      'attributes' => $attribute_field_names,
      'relationships' => $relationship_field_names,
    ];
  }

}
