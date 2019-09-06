<?php

namespace Drupal\jsonapi_schema\Plugin\jsonapi_hypermedia\LinkProvider;

// @todo: Uncomment these lines when https://www.drupal.org/project/drupal/issues/3036285 lands.
//use Drupal\Core\Access\AccessResult;
//use Drupal\Core\Cache\CacheableMetadata;
//use Drupal\Core\Url;
//use Drupal\jsonapi\JsonApiResource\Link;
//use Drupal\jsonapi\JsonApiResource\Relationship;
//use Drupal\jsonapi\ResourceType\ResourceType;
//use Drupal\jsonapi_hypermedia\AccessRestrictedLink;
//use Drupal\Core\Access\AccessResult;
//use Drupal\Core\Cache\CacheableMetadata;
//use Drupal\jsonapi_hypermedia\AccessRestrictedLink;
use Drupal\jsonapi_hypermedia\Annotation\JsonapiHypermediaLinkProvider;
use Drupal\jsonapi_hypermedia\Plugin\LinkProviderBase;

/**
 * Class ResourceObjectSchemaLinkProvider.
 *
 * @JsonapiHypermediaLinkProvider(
 *   id = "jsonapi_shema.relationship_object",
 *   link_key = "describedby",
 *   link_context = {
 *     "relationship_object" = true,
 *   },
 * )
 *
 * @internal
 */
final class RelationshipObjectSchemaLinkProvider extends LinkProviderBase {

  /**
   * {@inheritdoc}
   */
  public function getLink($context) {
    // @todo: Uncomment this code when https://www.drupal.org/project/drupal/issues/3036285 lands.
    //assert($context instanceof Relationship);
    //$resource_type = $context->getContext()->getResourceType();
    //$relationship_field_name = $context->getFieldName();
    //$has_non_internal_resource_types = array_reduce($resource_type->getRelatableResourceTypesByField($relationship_field_name), function ($carry, ResourceType $target) {
    //  return $carry ?: !$target->isInternal();
    //}, FALSE);
    //if ($has_non_internal_resource_types) {
    //  $links = iterator_to_array($context->getLinks());
    //  assert(!empty($links['related']));
    //  $link = $links['related'][0];
    //  assert($link instanceof Link);
    //  $schema_route_name = "jsonapi_schema.{$resource_type->getTypeName()}.{$relationship_field_name}.related";
    //  $schema_url = Url::fromRoute($schema_route_name);
    //  $schema_href = $schema_url->setAbsolute()->toString(TRUE);
    //  return AccessRestrictedLink::createLink(AccessResult::allowed(), CacheableMetadata::createFromObject($link)->addCacheableDependency($schema_href), $link->getUri(), $link->getLinkRelationTypes(), ['describedby' => $schema_href->getGeneratedUrl()]);
    //}
    //else {
    //  return AccessRestrictedLink::createInaccessibleLink((new CacheableMetadata())->addCacheTags(['jsonapi_resource_types']));
    //}
  }

}
