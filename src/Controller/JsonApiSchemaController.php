<?php

namespace Drupal\jsonapi_schema\Controller;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\Url;
use Drupal\jsonapi\ResourceType\ResourceAttribute;
use Drupal\jsonapi\ResourceType\ResourceFieldInterface;
use Drupal\jsonapi\ResourceType\ResourceRelationship;
use Drupal\jsonapi\ResourceType\ResourceType;
use Drupal\jsonapi\Routing\Routes;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class JsonApiSchemaController extends ControllerBase {

  const JSON_SCHEMA_DRAFT = 'http://json-schema.org/draft-07/schema';

  const JSONAPI_BASE_SCHEMA_URI = 'https://jsonapi.org/schema';

  /**
   * The serialization service.
   *
   * @var \Symfony\Component\Serializer\Normalizer\NormalizerInterface
   */
  protected $normalizer;

  /**
   * JsonApiSchemaController constructor.
   *
   * @param \Symfony\Component\Serializer\Normalizer\NormalizerInterface $normalizer
   *   The serializer.
   */
  public function __construct(NormalizerInterface $normalizer) {
    $this->normalizer = $normalizer;
  }

  public static function create(ContainerInterface $container) {
    return new static($container->get('serializer'));
  }

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
    $schema = $this->addFieldsSchema($resource_type, $schema);
    $schema = $this->addRelationshipsSchemaLinks($resource_type, $schema, $cacheability);
    return CacheableJsonResponse::create($schema)->addCacheableDependency($cacheability);
  }

  protected function addFieldsSchema(ResourceType $resource_type, array $schema) {
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

  protected function addRelationshipsSchemaLinks(ResourceType $resource_type, array $schema, CacheableMetadata $cacheability) {
    $resource_relationships = $resource_type->getResourceRelationships();
    if (empty($resource_relationships)) {
      return $schema;
    }
    $relationship_links = array_reduce($resource_relationships, function ($relationships, ResourceRelationship $relationship) use ($resource_type, $cacheability) {
      $field_name = $relationship->getAlias();
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
    $schema['definitions']['relationships'] = NestedArray::mergeDeep(
      empty($schema['definitions']['relationships']) ? [] : $schema['definitions']['relationships'],
      ['properties' => $relationship_links]
    );
    return $schema;
  }

}
