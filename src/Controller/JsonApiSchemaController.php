<?php

namespace Drupal\jsonapi_schema\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\jsonapi\Routing\Routes;
use Symfony\Component\HttpFoundation\Request;

class JsonApiSchemaController extends ControllerBase {

  const JSON_SCHEMA_DRAFT = 'http://json-schema.org/draft-07/schema';

  const JSONAPI_BASE_SCHEMA_URI = 'https://jsonapi.org/schema';

  public function getDocumentSchema(Request $request, ResourceType $resource_type, $jsonapi_route_type) {
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
    $get_url = function (ResourceType $resource_type, $type) {
      return Url::fromRoute(Routes::getRouteName($resource_type, $type) . '.jsonapi_schema.resource');
    };
    switch ($jsonapi_route_type) {
      case 'individual':
        $resource_schema_uri = $get_url($resource_type, 'individual')->setAbsolute()->toString(TRUE);
        $schema['definitions']['data'] = ['$ref' => $resource_schema_uri->getGeneratedUrl()];
        $cacheability->addCacheableDependency($resource_schema_uri);
        break;

      case 'related':
        $relationship_field_name = $request->get('related');
        $relatable_resource_types = $resource_type->getRelatableResourceTypesByField($relationship_field_name);
        $related_resource_uris = [];
        foreach ($relatable_resource_types as $target_resource_type) {
          $related_resource_uri = $get_url($target_resource_type, 'individual')->setAbsolute()->toString(TRUE);
          $cacheability->addCacheableDependency($related_resource_uri);
          $related_resource_uris[] = [
            '$ref' => $related_resource_uri->getGeneratedUrl(),
          ];
        }
        assert(count($related_resource_uris) > 0);
        $schema['definitions']['data'] = count($related_resource_uris) > 1
          ? ['anyOf' => $related_resource_uris]
          : array_shift($related_resource_uris);
        break;

      case 'collection':
        $resource_schema_uri = $get_url($resource_type, 'individual')->setAbsolute()->toString(TRUE);
        $cacheability->addCacheableDependency($resource_schema_uri);
        $schema['definitions']['data']['type'] = 'array';
        $schema['definitions']['data']['items'][] = [
          '$ref' => $resource_schema_uri->getGeneratedUrl(),
        ];
    }
    return CacheableJsonResponse::create($schema)->addCacheableDependency($cacheability);
  }

  public function getResourceSchema(Request $request, ResourceType $resource_type, $jsonapi_route_name, $jsonapi_route_type) {
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
      $related_schema_uri = Url::fromRoute(Routes::getRouteName($resource_type, "$field_name.related") . '.jsonapi_schema.document')->setAbsolute()->toString(TRUE);
      $cacheability->addCacheableDependency($related_schema_uri);
      return array_merge($relationships, [
        $field_name => [
          'type' => 'object',
          'properties' => [
            'links' => [
              'type' => 'object',
              'properties' => [
                'related' => [
                  'type' => 'object',
                  'properties' => [
                    'describedBy' => ['const' => $related_schema_uri->getGeneratedUrl()]
                  ],
                ],
              ],
            ],
          ],
        ],
      ]);
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
