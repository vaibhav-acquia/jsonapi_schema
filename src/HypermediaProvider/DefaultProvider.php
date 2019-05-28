<?php

namespace Drupal\jsonapi_schema\HypermediaProvider;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Routing\RequestContext;
use Drupal\Core\Routing\Router;
use Drupal\Core\Url;
use Drupal\jsonapi\JsonApiResource\JsonApiDocumentTopLevel;
use Drupal\jsonapi\JsonApiResource\Link;
use Drupal\jsonapi\JsonApiResource\LinkCollection;
use Drupal\jsonapi\JsonApiResource\ResourceObject;
use Drupal\jsonapi_hypermedia\HypermediaProviderInterface;
use Symfony\Cmf\Component\Routing\RouteObjectInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;

/**
 * Provides schema-specific hyperlinks.
 *
 * @internal
 */
class DefaultProvider implements HypermediaProviderInterface {

  /**
   * @var \Drupal\Core\Routing\Router
   */
  protected $router;

  /**
   * DefaultProvider constructor.
   *
   */
  public function __construct(Router $router) {
    $this->router = $router;
  }

  /**
   * {@inheritdoc}
   */
  public function hyperlink(LinkCollection $link_collection) {
    $context = $link_collection->getContext();
    if ($context instanceof JsonApiDocumentTopLevel) {
      return $this->hyperlinkTopLevelDocument($link_collection);
    }
    elseif ($context instanceof ResourceObject) {
      return $this->hyperlinkResourceObject($context, $link_collection);
    }
    elseif ($context instanceof EntityReferenceFieldItemListInterface) {
      return $this->hyperlinkRelationshipObject($link_collection);
    }
    return $link_collection;
  }

  protected function hyperlinkRelationshipObject(LinkCollection $link_collection) {
    foreach ($link_collection as $key => $links) {
      if ($key === 'related') {
        $link = $links[0];
        assert($link instanceof Link);
        $route_name = $this->getRouteNameFromLink($link);
        if (strpos($route_name, 'related') !== FALSE) {
          $route_name_components = explode('.', $route_name);
          $schema_route_name = "jsonapi_schema.{$route_name_components[1]}.{$route_name_components[2]}.related";
          $schema_url = Url::fromRoute($schema_route_name);
          $schema_href = $schema_url->setAbsolute()->toString(TRUE);
          $target_attributes = NestedArray::mergeDeep($link->getTargetAttributes(), ['linkParams' => ['describedBy' => $schema_href->getGeneratedUrl()]]);
          $link = new Link(CacheableMetadata::createFromObject($link)->addCacheableDependency($schema_href), $link->getUri(), $link->getLinkRelationTypes(), $target_attributes);
          $link_collection = $link_collection->withLink('related', $link);
        }
        break;
      }
    }
    return $link_collection;
  }

  protected function hyperlinkResourceObject(ResourceObject $resource, LinkCollection $link_collection) {
    $resource_type_name = $resource->getResourceType()->getTypeName();
    $resource_schema_uri = Url::fromRoute("jsonapi_schema.$resource_type_name.type");
    $resource_schema_link = new Link(new CacheableMetadata(), $resource_schema_uri, ['describedBy']);
    $link_collection = $link_collection->withLink('describedBy', $resource_schema_link);
    return $link_collection;
  }

  protected function hyperlinkTopLevelDocument(LinkCollection $link_collection) {
    foreach ($link_collection as $key => $links) {
      if ($key === 'self') {
        $link = $links[0];
        assert($link instanceof Link);
        $route_name = $this->getRouteNameFromLink($link);
        if ($route_name === 'jsonapi.resource_list') {
           $link_collection = $this->hyperlinkEntrypoint($link_collection);
        }
        elseif (strpos($route_name, 'relationship') === FALSE) {
          $route_name_components = explode('.', $route_name);
          if (in_array($route_name_components[2], ['individual', 'collection'], TRUE)) {
            $schema_route_name = "jsonapi_schema.{$route_name_components[1]}.{$route_name_components[2]}";
          }
          else {
            $schema_route_name = "jsonapi_schema.{$route_name_components[1]}.{$route_name_components[2]}.{$route_name_components[3]}";
          }
          $schema_link = new Link(new CacheableMetadata(), Url::fromRoute($schema_route_name), ['describedBy']);
          $link_collection = $link_collection->withLink('describedBy', $schema_link);
        }
        break;
      }
    }
    return $link_collection;
  }

  protected function hyperlinkEntryPoint(LinkCollection $link_collection) {
    foreach ($link_collection as $key => $links) {
      if ($key === 'self') {
        continue;
      }
      $link = $links[0];
      assert($link instanceof Link);
      $route_name = $this->getRouteNameFromLink($link);
      if (strpos($route_name, 'collection') !== FALSE) {
        $route_name_components = explode('.', $route_name);
        $schema_url = Url::fromRoute("jsonapi_schema.{$route_name_components[1]}.collection");
        $schema_href = $schema_url->setAbsolute()->toString(TRUE);
        $target_attributes = NestedArray::mergeDeep($link->getTargetAttributes(), ['linkParams' => ['describedBy' => $schema_href->getGeneratedUrl()]]);
        $link = new Link(CacheableMetadata::createFromObject($link)->addCacheableDependency($schema_href), $link->getUri(), $link->getLinkRelationTypes(), $target_attributes);
        $link_collection = $link_collection->withLink($key, $link);
      }
    }
    return $link_collection;
  }

  protected function getRouteNameFromLink(Link $link) {
    $link_uri = $link->getUri()->toString(TRUE)->getGeneratedUrl();
    try {
      $match = $this->getRouteMatchFromUriAndMethod($link_uri, 'GET');
    }
    catch (MethodNotAllowedException $e) {
      $allowed_methods = $e->getAllowedMethods();
      $match = $this->getRouteMatchFromUriAndMethod($link_uri, array_shift($allowed_methods));
    }
    return $match[RouteObjectInterface::ROUTE_NAME];
  }

  protected function getRouteMatchFromUriAndMethod($uri, $method) {
    $request = Request::create($uri, $method);
    $request->headers->add([
      'accept' => 'application/vnd.api+json',
      'content-type' => 'application/vnd.api+json',
    ]);
    $current_context = $this->router->getContext();
    $new_context = new RequestContext();
    $new_context->fromRequest($request);
    $this->router->setContext($new_context);
    $match = $this->router->matchRequest($request);
    $this->router->setContext($current_context);
    return $match;
  }

}
