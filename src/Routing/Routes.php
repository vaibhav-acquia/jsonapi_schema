<?php

namespace Drupal\jsonapi_schema\Routing;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\jsonapi\Access\RelationshipFieldAccess;
use Drupal\jsonapi\Routing\Routes as JsonApiRoutes;
use Symfony\Cmf\Component\Routing\RouteObjectInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

class Routes implements ContainerInjectionInterface {

  const CONTROLLER_NAME = 'jsonapi_schema.controller';

  protected $jsonApiRoutes;

  protected $jsonApiBasePath;

  public function __construct(JsonApiRoutes $jsonapi_routes, $jsonapi_base_path) {
    $this->jsonApiRoutes = $jsonapi_routes;
    $this->jsonApiBasePath = $jsonapi_base_path;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      JsonApiRoutes::create($container),
      $container->getParameter('jsonapi.base_path')
    );
  }

  public function routes() {
    $jsonapi_routes = $this->jsonApiRoutes->routes();
    $jsonapi_schema_routes = new RouteCollection();
    foreach ($jsonapi_routes as $jsonapi_route_name => $jsonapi_route) {
      $excluded_route_names = ['jsonapi.resource_list'];
      if (in_array($jsonapi_route_name, $excluded_route_names, TRUE)) {
        continue;
      }
      $document_schema_route = new Route(str_replace("/{entity}", "/resource", $jsonapi_route->getPath()) . '/schema.json');
      $jsonapi_route_parameters = $jsonapi_route->getOption('parameters');
      assert(is_array($jsonapi_route_parameters), $jsonapi_route_name);
      $parameters = array_intersect_key($jsonapi_route_parameters, array_flip([JsonApiRoutes::RESOURCE_TYPE_KEY]));
      $document_schema_route->setOption('parameters', $parameters);
      $defaults = array_intersect_key($jsonapi_route->getDefaults(), array_flip([JsonApiRoutes::RESOURCE_TYPE_KEY, 'related']));
      $jsonapi_route_name_components = explode('.', $jsonapi_route_name);
      $jsonapi_route_type = array_pop($jsonapi_route_name_components);
      while (!empty($jsonapi_route_name_components) && !in_array($jsonapi_route_type, ['individual', 'relationship', 'related', 'collection'], TRUE)) {
        $jsonapi_route_type = array_pop($jsonapi_route_name_components);
      }
      assert(in_array($jsonapi_route_type, ['individual', 'relationship', 'related', 'collection'], TRUE), $jsonapi_route_name);
      if (!in_array($jsonapi_route_type, ['collection', 'individual', 'related'], TRUE)) {
        continue;
      }
      $defaults['jsonapi_route_type'] = $jsonapi_route_type;
      $defaults['jsonapi_route_name'] = $jsonapi_route_name;
      $document_schema_route->addDefaults($defaults);
      $jsonapi_route_requirements = $jsonapi_route->getRequirements();
      unset($jsonapi_route_requirements['_format']);
      unset($jsonapi_route_requirements['_content_type_format']);
      unset($jsonapi_route_requirements['_content_type_format']);
      unset($jsonapi_route_requirements[RelationshipFieldAccess::ROUTE_REQUIREMENT_KEY]);
      $jsonapi_route_requirements['_access'] = 'TRUE';
      $document_schema_route->addRequirements($jsonapi_route_requirements);
      $document_schema_route->setDefault(RouteObjectInterface::CONTROLLER_NAME, static::CONTROLLER_NAME . ':getDocumentSchema');
      $jsonapi_schema_routes->add("$jsonapi_route_name.jsonapi_schema.document", $document_schema_route);
      if ($jsonapi_route_type === 'individual') {
        $resource_schema_route = clone $document_schema_route;
        $resource_schema_route->setPath(str_replace("/{entity}", "/resource", $jsonapi_route->getPath()) . '/data/schema.json');
        $resource_schema_route->setDefault(RouteObjectInterface::CONTROLLER_NAME, static::CONTROLLER_NAME . ':getResourceSchema');
        $jsonapi_schema_routes->add("$jsonapi_route_name.jsonapi_schema.resource", $resource_schema_route);
      }
    }
    return $jsonapi_schema_routes;
  }


}
