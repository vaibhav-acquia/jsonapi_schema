<?php

declare(strict_types=1);

namespace Drupal\jsonapi_schema;

use Drupal\jsonapi\ResourceType\ResourceType;
use Symfony\Component\Routing\Route;

interface SchemaGeneratorInterface {

  /**
   * Get response JSON Schema for an entity type and optional bundle.
   *
   * @param string $entity_type_id
   *   Entity type ID.
   * @param string|null $bundle
   *   Bundle.
   *
   * @return array
   *   JSON Schema.
   */
  public function getEntityTypeJsonSchema(string $entity_type_id, ?string $bundle = NULL): array;

  /**
   * Get responses for a given resource type at a specific route and method.
   *
   * While this is formatted for use with OpenAPI, the logic is basically the
   * same for generating a schema in other contexts, e.g. a dedicated route for
   * expressing schema. It lives here for ease of re-use.
   *
   * @param ResourceType $resource_type
   *   Resource type.
   * @param string $method
   *   HTTP method used.
   * @param string $route_name
   *   Route name.
   * @param callable $definitionReferenceFn
   *   A function to call for definition references.
   * @param Route $route
   *   The route, if loaded.
   *
   * @return array
   *   An array of response schemas, keyed by response code.
   */
  public function getResponsesForRoute(string $method, string $route_name, callable $definitionReferenceFn, Route $route): array;

  /**
   * Get the response schema for a resource type at a particular route.
   *
   * This differs from ::getResponsesForRoute in that it is designed to provide
   * a flattened, single schema object for a given route. It delegates most of
   * its work to that method but is JSON Schema compliant as opposed to fitting
   * the expected format for an OpenAPI document.
   *
   * @param ResourceType $resource_type
   *   Resource type.
   * @param string $method
   *   HTTP method used.
   * @param string $route_name
   *   Route name.
   * @param Route|NULL $route
   *   The route, if loaded.
   *
   * @return array
   *   JSON Schema.
   */
  public function getSchemaForRoute(ResourceType $resource_type, string $method, string $route_name, Route $route = NULL): array;

}
