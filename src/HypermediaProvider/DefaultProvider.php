<?php

namespace Drupal\jsonapi_schema\HypermediaProvider;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Url;
use Drupal\jsonapi\JsonApiResource\JsonApiDocumentTopLevel;
use Drupal\jsonapi\JsonApiResource\Link;
use Drupal\jsonapi\JsonApiResource\LinkCollection;
use Drupal\jsonapi\JsonApiResource\ResourceObject;
use Drupal\jsonapi\Routing\Routes;
use Drupal\jsonapi_hypermedia\HypermediaProviderInterface;
use Symfony\Cmf\Component\Routing\RouteObjectInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;

/**
 * Provides schema-specific hyperlinks.
 *
 * @internal
 */
class DefaultProvider implements HypermediaProviderInterface {

  /**
   * @var \Symfony\Component\Routing\Matcher\RequestMatcherInterface
   */
  protected $router;

  /**
   * DefaultProvider constructor.
   *
   */
  public function __construct(RequestMatcherInterface $router) {
    $this->router = $router;
  }

  /**
   * {@inheritdoc}
   */
  public function hyperlink(LinkCollection $link_collection) {
    $context = $link_collection->getContext();
    if ($context instanceof JsonApiDocumentTopLevel) {
      return $this->hyperlinkTopLevelDocument($context, $link_collection);
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
        // We can ignore the cacheability because it's already associated with
        // the `self` link.
        $request_uri = $link->getUri()->toString(TRUE)->getGeneratedUrl();
        $match = $this->router->matchRequest(Request::create($request_uri));
        $route_name = $match[RouteObjectInterface::ROUTE_NAME];
        if (strpos($route_name, 'related') !== FALSE) {
          $schema_url = Url::fromRoute("$route_name.jsonapi_schema.document");
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
    $resource_type = $resource->getResourceType();
    $resource_schema_uri = Url::fromRoute(Routes::getRouteName($resource_type, 'individual') . '.jsonapi_schema.resource');
    $resource_schema_link = new Link(new CacheableMetadata(), $resource_schema_uri, ['describedBy']);
    $link_collection = $link_collection->withLink('describedBy', $resource_schema_link);
    return $link_collection;
  }

  protected function hyperlinkTopLevelDocument(JsonApiDocumentTopLevel $document, LinkCollection $link_collection) {
    foreach ($link_collection as $key => $links) {
      if ($key === 'self') {
        $link = $links[0];
        assert($link instanceof Link);
        // We can ignore the cacheability because it's already associated with
        // the `self` link.
        $request_uri = $link->getUri()->toString(TRUE)->getGeneratedUrl();
        $match = $this->router->matchRequest(Request::create($request_uri));
        $route_name = $match[RouteObjectInterface::ROUTE_NAME];
        if (strpos($route_name, 'relationship') === FALSE && $route_name !== 'jsonapi.resource_list') {
          $schema_link = new Link(new CacheableMetadata(), Url::fromRoute("$route_name.jsonapi_schema.document"), ['describedBy']);
          $link_collection = $link_collection->withLink('describedBy', $schema_link);
        }
        break;
      }
    }
    return $link_collection;
  }

}
