<?php

declare(strict_types=1);

namespace Drupal\jsonapi_schema;

use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\jsonapi\Routing\Routes;
use Symfony\Component\Routing\Route;

/**
 * Introspection methods for handling JSON:API resource types and routes.
 */
trait ResourceTypeResolutionTrait {

  /**
   * Gets a Resource Type for a given route.
   *
   * The resource type on the route may be a "real" type representing a single
   * entity/bundle pair or a synthetic resource type, e.g. from JSON:API Cross
   * Bundles module.
   *
   * @param string $route_name
   *   The JSON API route name for which the ResourceType is wanted.
   * @param \Symfony\Component\Routing\Route $route
   *   The JSON API route for which the ResourceType is wanted.
   *
   * @return \Drupal\jsonapi\ResourceType\ResourceType|null
   *   Returns the ResourceType for the given JSON API route, NULL if the route
   *   parameter could not be upcasted.
   */
  protected function getResourceTypeFromRoute($route_name, Route $route) {
    $parameters[RouteObjectInterface::ROUTE_NAME] = $route_name;
    $parameters[RouteObjectInterface::ROUTE_OBJECT] = $route;
    $upcasted_parameters = $this->paramConverterManager->convert($parameters + $route->getDefaults());
    return $upcasted_parameters[Routes::RESOURCE_TYPE_KEY] ?? NULL;
  }


  /**
   * Gets the route type from the name, if possible.
   *
   * @see \Drupal\jsonapi\Routing\Routes
   *
   * @param string $route_name
   *   The route name.
   *
   * @return string|null
   *   The route type or NULL if unknown.
   */
  protected static function getRouteTypeByName(string $route_name): ?string {
    // @todo - This is mostly to support custom resources, however they may
    //   not really all be collections. Insufficient information to handle this
    //   case gracefully otherwise, though.
    if (!str_starts_with($route_name, 'jsonapi.')) {
      return 'collection';
    }
    // These are the known, general cases from JSON:API module.
    if (str_contains($route_name, '.collection')) {
      return 'collection';
    }
    if (str_contains($route_name, '.related')) {
      return 'related';
    }
    if (str_contains($route_name, '.relationship')) {
      return 'relationship';
    }
    if (str_contains($route_name, '.individual')) {
      return 'individual';
    }
    throw new \LogicException('JSON:API route appears to be from core but could not resolve route type.');
  }

  /**
   * Gets the related Resource Type.
   *
   * @param string $route_name
   *   The JSON API route name for which the ResourceType is wanted.
   * @param \Symfony\Component\Routing\Route $route
   *   The JSON API route for which the ResourceType is wanted.
   *
   * @return \Drupal\jsonapi\ResourceType\ResourceType[]|null
   *   Returns the ResourceType for the related JSON API resource.
   */
  protected function relatedResourceTypes(string $route_name, Route $route): ?array {
    // @todo Can we have better type safety on this?
    if (!in_array(
      $this->getRouteTypeByName($route_name),
      ['related', 'relationship'])
    ) {
      return NULL;
    }
    $resource_type = $this->getResourceTypeFromRoute($route_name, $route);
    assert($route->hasDefault('related'));
    return $resource_type->getRelatableResourceTypesByField($route->getDefault('related'));
  }

  protected function getResourceTypesForRoute(Route $route): array {
    $resourceTypes = array_filter([$this->resourceTypeRepository->getByTypeName($route->getDefault(Routes::RESOURCE_TYPE_KEY))]);
    // Special handling for jsonapi_cross_bundles.
    if ($resourceTypes && current($resourceTypes)::class === 'Drupal\jsonapi_cross_bundles\ResourceType\CrossBundlesResourceType') {
      $resourceTypes = current($resourceTypes)->getBundleResourceTypes();
    }
    // Special handling for jsonapi_resources.
    else if (is_a($route->getDefault(RouteObjectInterface::CONTROLLER_NAME), '\Drupal\jsonapi_resources\Unstable\Controller\JsonapiResourceController')) {
      $resourceTypes = array_map(
        fn (string $resourceTypeName) => $this->resourceTypeRepository->getByTypeName($resourceTypeName),
        $route->getDefault('_jsonapi_resource_types')
      );
    }
    return $resourceTypes;
  }
}
